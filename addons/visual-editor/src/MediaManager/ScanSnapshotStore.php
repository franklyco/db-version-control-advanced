<?php

namespace Dbvc\VisualEditor\MediaManager;

use WP_Error;

final class ScanSnapshotStore
{
    private const TRANSIENT_PREFIX = 'dbvc_visual_editor_media_scan_';
    private const LATEST_PREFIX = 'dbvc_visual_editor_media_scan_latest_';
    private const LOCK_PREFIX = 'dbvc_visual_editor_media_scan_lock_';
    private const GROUP_STORAGE_GZIP_JSON_BASE64 = 'gzip_json_base64_v1';
    private const DEFAULT_TTL = 3600;
    private const MIN_TTL = 300;
    private const MAX_TTL = 86400;
    private const DEFAULT_MAX_PAYLOAD_BYTES = 5242880;
    private const MIN_MAX_PAYLOAD_BYTES = 65536;
    private const MAX_MAX_PAYLOAD_BYTES = 20971520;
    private const LOCK_TTL = 30;

    /**
     * @var int
     */
    private $ttl;

    /**
     * @var int
     */
    private $max_payload_bytes;

    public function __construct($ttl = self::DEFAULT_TTL, $max_payload_bytes = self::DEFAULT_MAX_PAYLOAD_BYTES)
    {
        $this->ttl = max(self::MIN_TTL, min(self::MAX_TTL, absint($ttl)));
        $this->max_payload_bytes = max(
            self::MIN_MAX_PAYLOAD_BYTES,
            min(self::MAX_MAX_PAYLOAD_BYTES, absint($max_payload_bytes))
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>|WP_Error
     */
    public function create(array $snapshot)
    {
        $user_id = get_current_user_id();
        $blog_id = get_current_blog_id();
        if ($user_id <= 0 || $blog_id <= 0) {
            return new WP_Error('media_scan_not_authenticated', __('An authenticated site user is required to create a media scan.', 'dbvc'));
        }

        $now = time();
        $snapshot['scan_ref'] = $this->createReference('vems_', 20);
        $snapshot['generation'] = $this->createReference('vmsg_', 16);
        $snapshot['user_id'] = $user_id;
        $snapshot['blog_id'] = $blog_id;
        $snapshot['revision'] = 1;
        $snapshot['created_at'] = $now;
        $snapshot['updated_at'] = $now;
        $snapshot['expires_at'] = $now + $this->ttl;
        $snapshot['groups'] = isset($snapshot['groups']) && is_array($snapshot['groups']) ? $snapshot['groups'] : [];

        $stored = $this->persistNewSnapshot($snapshot);
        if (is_wp_error($stored)) {
            return $stored;
        }

        set_transient($this->latestKey($user_id, $blog_id), [
            'scan_ref' => $stored['scan_ref'],
            'generation' => $stored['generation'],
        ], $this->ttl);

        return $stored;
    }

    /**
     * @param string $scan_ref
     * @return array<string, mixed>|WP_Error
     */
    public function load($scan_ref)
    {
        $scan_ref = $this->normalizeReference($scan_ref, 'vems_');
        if ($scan_ref === '') {
            return $this->unavailableError();
        }

        $payload = get_transient(self::TRANSIENT_PREFIX . $scan_ref);
        if (! is_array($payload)) {
            return $this->unavailableError();
        }

        if ((string) ($payload['scan_ref'] ?? '') !== $scan_ref
            || absint($payload['user_id'] ?? 0) !== get_current_user_id()
            || absint($payload['blog_id'] ?? 0) !== get_current_blog_id()
            || absint($payload['expires_at'] ?? 0) < time()) {
            return $this->unavailableError();
        }

        return $this->decodeSnapshot($payload);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function loadLatest()
    {
        $user_id = get_current_user_id();
        $blog_id = get_current_blog_id();
        $pointer = get_transient($this->latestKey($user_id, $blog_id));

        if (! is_array($pointer) || empty($pointer['scan_ref'])) {
            return $this->unavailableError();
        }

        $snapshot = $this->load((string) $pointer['scan_ref']);
        if (is_wp_error($snapshot)) {
            delete_transient($this->latestKey($user_id, $blog_id));
        }

        return $snapshot;
    }

    /**
     * @param string $scan_ref
     * @param string $generation
     * @return bool
     */
    public function isLatest($scan_ref, $generation)
    {
        $pointer = get_transient($this->latestKey(get_current_user_id(), get_current_blog_id()));

        return is_array($pointer)
            && hash_equals((string) ($pointer['scan_ref'] ?? ''), (string) $scan_ref)
            && hash_equals((string) ($pointer['generation'] ?? ''), (string) $generation);
    }

    /**
     * Compare the persisted revision while the caller owns the snapshot lock,
     * then advance exactly one revision. This keeps duplicate/stale requests
     * from overwriting a newer chunk.
     *
     * @param array<string, mixed> $snapshot
     * @param int                  $expected_revision
     * @return array<string, mixed>|WP_Error
     */
    public function save(array $snapshot, $expected_revision)
    {
        $scan_ref = $this->normalizeReference($snapshot['scan_ref'] ?? '', 'vems_');
        $generation = $this->normalizeReference($snapshot['generation'] ?? '', 'vmsg_');
        if ($scan_ref === '' || $generation === '') {
            return $this->unavailableError();
        }

        $current = $this->load($scan_ref);
        if (is_wp_error($current)) {
            return $current;
        }

        $expected_revision = absint($expected_revision);
        if (absint($current['revision'] ?? 0) !== $expected_revision
            || ! hash_equals((string) ($current['generation'] ?? ''), $generation)) {
            return new WP_Error('media_scan_stale_revision', __('The media scan has already advanced.', 'dbvc'));
        }

        if (absint($snapshot['user_id'] ?? 0) !== get_current_user_id()
            || absint($snapshot['blog_id'] ?? 0) !== get_current_blog_id()) {
            return $this->unavailableError();
        }

        $snapshot['revision'] = $expected_revision + 1;
        $snapshot['updated_at'] = time();
        $snapshot['expires_at'] = time() + $this->ttl;
        unset($snapshot['request_status']);

        return $this->persistExistingSnapshot($snapshot);
    }

    /**
     * @param string $scan_ref
     * @return string|WP_Error
     */
    public function acquireLock($scan_ref)
    {
        $scan_ref = $this->normalizeReference($scan_ref, 'vems_');
        if ($scan_ref === '') {
            return $this->unavailableError();
        }

        $snapshot = $this->load($scan_ref);
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }

        $option_name = self::LOCK_PREFIX . $scan_ref;
        $token = $this->createReference('vml_', 16);
        $value = [
            'token' => $token,
            'user_id' => get_current_user_id(),
            'blog_id' => get_current_blog_id(),
            'expires_at' => time() + self::LOCK_TTL,
        ];

        if (add_option($option_name, $value, '', 'no')) {
            return $token;
        }

        $existing = get_option($option_name);
        if (is_array($existing) && absint($existing['expires_at'] ?? 0) < time()) {
            delete_option($option_name);
            if (add_option($option_name, $value, '', 'no')) {
                return $token;
            }
        }

        return new WP_Error('media_scan_busy', __('This media scan is already processing another chunk.', 'dbvc'));
    }

    /**
     * @param string $scan_ref
     * @param string $token
     * @return void
     */
    public function releaseLock($scan_ref, $token)
    {
        $scan_ref = $this->normalizeReference($scan_ref, 'vems_');
        $token = $this->normalizeReference($token, 'vml_');
        if ($scan_ref === '' || $token === '') {
            return;
        }

        $option_name = self::LOCK_PREFIX . $scan_ref;
        $existing = get_option($option_name);
        if (is_array($existing) && hash_equals((string) ($existing['token'] ?? ''), $token)) {
            delete_option($option_name);
        }
    }

    /**
     * @param string $scan_ref
     * @return void
     */
    public function delete($scan_ref)
    {
        $scan_ref = $this->normalizeReference($scan_ref, 'vems_');
        if ($scan_ref === '') {
            return;
        }

        delete_transient(self::TRANSIENT_PREFIX . $scan_ref);
        delete_option(self::LOCK_PREFIX . $scan_ref);
    }

    /**
     * @return int
     */
    public function getTtl()
    {
        return $this->ttl;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>|WP_Error
     */
    private function persistNewSnapshot(array $snapshot)
    {
        return $this->persistSnapshot($snapshot, false);
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>|WP_Error
     */
    private function persistExistingSnapshot(array $snapshot)
    {
        return $this->persistSnapshot($snapshot, true);
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param bool                 $existing
     * @return array<string, mixed>|WP_Error
     */
    private function persistSnapshot(array $snapshot, $existing)
    {
        $payload = $this->encodeSnapshot($snapshot);
        $json = wp_json_encode($payload);
        $bytes = is_string($json) ? strlen($json) : 0;
        if ($bytes <= 0 || $bytes > $this->max_payload_bytes) {
            return new WP_Error('media_scan_snapshot_too_large', __('The media scan snapshot exceeded its safe storage limit.', 'dbvc'));
        }

        $snapshot['storage'] = [
            'snapshot_bytes' => $bytes,
            'group_count' => count($snapshot['groups'] ?? []),
            'compressed' => isset($payload['groups_storage']),
        ];
        $payload = $this->encodeSnapshot($snapshot);
        $json = wp_json_encode($payload);
        $bytes = is_string($json) ? strlen($json) : 0;
        if ($bytes <= 0 || $bytes > $this->max_payload_bytes) {
            return new WP_Error('media_scan_snapshot_too_large', __('The media scan snapshot exceeded its safe storage limit.', 'dbvc'));
        }
        $snapshot['storage']['snapshot_bytes'] = $bytes;
        $payload = $this->encodeSnapshot($snapshot);

        $scan_ref = (string) $snapshot['scan_ref'];
        $stored = set_transient(self::TRANSIENT_PREFIX . $scan_ref, $payload, $this->ttl);
        if (! $stored && ! $existing) {
            return new WP_Error('media_scan_storage_failed', __('The media scan snapshot could not be stored.', 'dbvc'));
        }

        $pointer = get_transient($this->latestKey(get_current_user_id(), get_current_blog_id()));
        if (is_array($pointer)
            && hash_equals((string) ($pointer['scan_ref'] ?? ''), $scan_ref)
            && hash_equals((string) ($pointer['generation'] ?? ''), (string) ($snapshot['generation'] ?? ''))) {
            set_transient($this->latestKey(get_current_user_id(), get_current_blog_id()), $pointer, $this->ttl);
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function encodeSnapshot(array $snapshot)
    {
        $groups = isset($snapshot['groups']) && is_array($snapshot['groups']) ? $snapshot['groups'] : [];
        unset($snapshot['groups'], $snapshot['groups_storage'], $snapshot['groups_blob'], $snapshot['group_count']);

        if (! empty($groups) && function_exists('gzencode')) {
            $json = wp_json_encode($groups);
            $compressed = is_string($json) ? gzencode($json, 4) : false;
            if (is_string($compressed) && $compressed !== '') {
                $snapshot['groups_storage'] = self::GROUP_STORAGE_GZIP_JSON_BASE64;
                $snapshot['group_count'] = count($groups);
                $snapshot['groups_blob'] = base64_encode($compressed);

                return $snapshot;
            }
        }

        $snapshot['groups'] = $groups;

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function decodeSnapshot(array $payload)
    {
        if (isset($payload['groups']) && is_array($payload['groups'])) {
            return $payload;
        }

        $payload['groups'] = [];
        if (($payload['groups_storage'] ?? '') !== self::GROUP_STORAGE_GZIP_JSON_BASE64
            || empty($payload['groups_blob'])
            || ! function_exists('gzdecode')) {
            return $payload;
        }

        $compressed = base64_decode((string) $payload['groups_blob'], true);
        $json = is_string($compressed) ? gzdecode($compressed) : false;
        $groups = is_string($json) ? json_decode($json, true) : null;
        $payload['groups'] = is_array($groups) ? $groups : [];
        unset($payload['groups_storage'], $payload['groups_blob'], $payload['group_count']);

        return $payload;
    }

    /**
     * @param int $user_id
     * @param int $blog_id
     * @return string
     */
    private function latestKey($user_id, $blog_id)
    {
        return self::LATEST_PREFIX . absint($blog_id) . '_' . absint($user_id);
    }

    /**
     * @param string $prefix
     * @param int    $length
     * @return string
     */
    private function createReference($prefix, $length)
    {
        return (string) $prefix . strtolower(wp_generate_password(absint($length), false, false));
    }

    /**
     * @param mixed  $value
     * @param string $prefix
     * @return string
     */
    private function normalizeReference($value, $prefix)
    {
        $value = strtolower(trim((string) $value));

        return strpos($value, $prefix) === 0 && preg_match('/^[a-z0-9_]+$/', $value)
            ? $value
            : '';
    }

    /**
     * @return WP_Error
     */
    private function unavailableError()
    {
        return new WP_Error('media_scan_expired_or_invalid', __('The media scan is unavailable or has expired.', 'dbvc'));
    }
}
