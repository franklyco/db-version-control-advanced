<?php

namespace Dbvc\Media\Hydration;

if (! defined('WPINC')) {
    die;
}

/**
 * Builds read-only hydration plans from media mirror manifests.
 */
final class HydrationPlanner
{
    /**
     * Build a dry-run hydration plan.
     *
     * @param array $manifest Media mirror manifest.
     * @param array $args {
     *   @type bool   $clone_confirmation Require same-ID clone matching. Default true.
     *   @type string $match_policy       same_id_then_uid|uid_then_path. Default same_id_then_uid.
     *   @type bool   $strict_hashes      Compare target hashes when source hashes are available. Default true.
     * }
     * @return array<string,mixed>|\WP_Error
     */
    public static function plan(array $manifest, array $args = [])
    {
        $validation = self::validate_manifest($manifest);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $clone_confirmation = array_key_exists('clone_confirmation', $args) ? (bool) $args['clone_confirmation'] : true;
        $match_policy = isset($args['match_policy']) ? sanitize_key((string) $args['match_policy']) : 'same_id_then_uid';
        $strict_hashes = array_key_exists('strict_hashes', $args) ? (bool) $args['strict_hashes'] : true;

        $items = [];
        $summary = self::empty_summary();

        foreach ((array) ($manifest['attachments'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $planned = self::plan_entry($entry, [
                'clone_confirmation' => $clone_confirmation,
                'match_policy' => $match_policy,
                'strict_hashes' => $strict_hashes,
            ]);

            $items[] = $planned;
            self::accumulate_summary($summary, $planned);
        }

        return [
            'kind' => 'dbvc_media_hydration_plan',
            'schema' => 1,
            'generated_at' => gmdate('c'),
            'source_manifest' => [
                'checksum' => (string) ($manifest['checksum'] ?? ''),
                'generated_at' => (string) ($manifest['generated_at'] ?? ''),
                'source_site' => isset($manifest['source_site']) && is_array($manifest['source_site']) ? $manifest['source_site'] : [],
            ],
            'policy' => [
                'clone_confirmation' => $clone_confirmation,
                'match_policy' => $match_policy,
                'strict_hashes' => $strict_hashes,
            ],
            'summary' => $summary,
            'items' => $items,
        ];
    }

    /**
     * Read a media mirror manifest from disk and build a plan.
     *
     * @param string $manifest_path
     * @param array  $args
     * @return array<string,mixed>|\WP_Error
     */
    public static function plan_from_file(string $manifest_path, array $args = [])
    {
        $manifest_path = wp_normalize_path($manifest_path);
        if ($manifest_path === '' || ! is_file($manifest_path) || ! is_readable($manifest_path)) {
            return new \WP_Error('dbvc_media_hydration_manifest_unreadable', __('Media mirror manifest is missing or unreadable.', 'dbvc'));
        }

        $raw = file_get_contents($manifest_path);
        if (! is_string($raw) || trim($raw) === '') {
            return new \WP_Error('dbvc_media_hydration_manifest_empty', __('Media mirror manifest is empty.', 'dbvc'));
        }

        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
            return new \WP_Error('dbvc_media_hydration_manifest_invalid_json', __('Media mirror manifest is not valid JSON.', 'dbvc'));
        }

        return self::plan($manifest, $args);
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function plan_entry(array $entry, array $args): array
    {
        $source_id = isset($entry['source_id']) ? absint($entry['source_id']) : 0;
        $source_hash = self::normalize_hash((string) ($entry['file_hash'] ?? ''));
        $source_relative = FileStateService::normalize_relative_path((string) ($entry['relative_path'] ?? ''));
        $source_file_status = (string) ($entry['file_status'] ?? 'unknown');
        $match = self::match_target($entry, (string) ($args['match_policy'] ?? 'same_id_then_uid'));

        $plan = [
            'source_id' => $source_id,
            'target_id' => (int) ($match['target_id'] ?? 0),
            'matched_via' => (string) ($match['matched_via'] ?? ''),
            'status' => 'blocked',
            'planned_action' => 'block',
            'reason' => '',
            'relative_path' => $source_relative,
            'source_file_status' => $source_file_status,
            'source_hash' => $source_hash,
            'target_hash' => '',
            'file_state' => [],
            'conflicts' => isset($match['conflicts']) ? $match['conflicts'] : [],
        ];

        if (! empty($match['reason'])) {
            $plan['reason'] = (string) $match['reason'];
            $plan['status'] = (string) $match['status'];
            return $plan;
        }

        if (! empty($args['clone_confirmation']) && $source_id > 0 && (int) $plan['target_id'] !== $source_id) {
            $plan['status'] = 'conflict';
            $plan['reason'] = 'clone_same_id_required';
            return $plan;
        }

        $target_id = (int) $plan['target_id'];
        $target_state = FileStateService::inspect($target_id, [
            'compute_hash' => $source_hash !== '' && ! empty($args['strict_hashes']),
            'check_derivatives' => false,
        ]);
        $plan['file_state'] = $target_state;
        $target_hash = self::normalize_hash((string) ($target_state['file_hash'] ?? ''));
        $plan['target_hash'] = $target_hash;

        $target_status = (string) ($target_state['status'] ?? 'unknown');
        $target_relative = FileStateService::normalize_relative_path((string) ($target_state['relative_path'] ?? ''));
        if ($source_relative !== '' && $target_relative !== '' && $source_relative !== $target_relative) {
            $plan['status'] = 'conflict';
            $plan['planned_action'] = 'block';
            $plan['reason'] = 'target_relative_path_differs';
            return $plan;
        }

        if ($target_status === 'unsafe_path') {
            $plan['status'] = 'unsafe_path';
            $plan['reason'] = (string) ($target_state['reason'] ?? 'unsafe_path');
            return $plan;
        }

        if ($target_status === 'missing' || $target_status === 'missing_attached_file_meta') {
            if ($source_file_status !== '' && ! in_array($source_file_status, ['exists', 'unknown'], true)) {
                $plan['status'] = 'source_file_missing';
                $plan['reason'] = 'source_manifest_file_not_available';
                return $plan;
            }

            $plan['status'] = 'needs_hydration';
            $plan['planned_action'] = 'hydrate_existing_attachment';
            $plan['reason'] = 'target_file_missing';
            return $plan;
        }

        if ($target_status === 'unreadable') {
            $plan['status'] = 'blocked';
            $plan['reason'] = 'target_file_unreadable';
            return $plan;
        }

        if (! empty($args['strict_hashes']) && $source_hash !== '' && $target_hash !== '' && ! hash_equals(self::strip_hash_prefix($source_hash), self::strip_hash_prefix($target_hash))) {
            $plan['status'] = 'hash_mismatch';
            $plan['planned_action'] = 'block';
            $plan['reason'] = 'target_hash_differs';
            return $plan;
        }

        $metadata = isset($target_state['metadata']) && is_array($target_state['metadata']) ? $target_state['metadata'] : [];
        $metadata_status = (string) ($metadata['status'] ?? '');
        if (in_array($metadata_status, ['missing', 'stale', 'missing_derivatives'], true) && self::metadata_expected((string) ($entry['mime_group'] ?? ''), (string) ($entry['mime_type'] ?? ''))) {
            $plan['status'] = 'needs_metadata_repair';
            $plan['planned_action'] = 'repair_metadata';
            $plan['reason'] = 'metadata_' . $metadata_status;
            return $plan;
        }

        $plan['status'] = 'ok';
        $plan['planned_action'] = 'none';
        $plan['reason'] = '';
        return $plan;
    }

    /**
     * @param array<string,mixed> $entry
     * @param string              $match_policy
     * @return array<string,mixed>
     */
    private static function match_target(array $entry, string $match_policy): array
    {
        $source_id = isset($entry['source_id']) ? absint($entry['source_id']) : 0;
        $asset_uid = trim((string) ($entry['asset_uid'] ?? ($entry['dbvc']['vf_asset_uid'] ?? '')));
        $file_hash = self::normalize_hash((string) ($entry['file_hash'] ?? ''));
        $relative = FileStateService::normalize_relative_path((string) ($entry['relative_path'] ?? ''));

        $strategies = $match_policy === 'uid_then_path'
            ? ['asset_uid', 'same_id', 'file_hash', 'relative_path']
            : ['same_id', 'asset_uid', 'file_hash', 'relative_path'];

        foreach ($strategies as $strategy) {
            if ($strategy === 'same_id' && $source_id > 0) {
                $post = get_post($source_id);
                if ($post instanceof \WP_Post && $post->post_type === 'attachment') {
                    return [
                        'target_id' => $source_id,
                        'matched_via' => 'same_id',
                    ];
                }
            }

            if ($strategy === 'asset_uid' && $asset_uid !== '') {
                $result = self::query_unique_attachment_by_meta('vf_asset_uid', $asset_uid);
                if ($result['status'] !== 'miss') {
                    return $result;
                }
            }

            if ($strategy === 'file_hash' && $file_hash !== '') {
                $hash_without_prefix = self::strip_hash_prefix($file_hash);
                $result = self::query_unique_attachment_by_meta('vf_file_hash', $hash_without_prefix);
                if ($result['status'] === 'miss') {
                    $result = self::query_unique_attachment_by_meta('vf_file_hash', $file_hash);
                }
                if ($result['status'] !== 'miss') {
                    $result['matched_via'] = $result['matched_via'] ?: 'file_hash';
                    return $result;
                }
            }

            if ($strategy === 'relative_path' && $relative !== '') {
                $result = self::query_unique_attachment_by_meta('_wp_attached_file', $relative);
                if ($result['status'] !== 'miss') {
                    $result['matched_via'] = $result['matched_via'] ?: 'relative_path';
                    return $result;
                }
            }
        }

        return [
            'target_id' => 0,
            'matched_via' => '',
            'status' => 'target_attachment_missing',
            'reason' => 'no_target_match',
        ];
    }

    /**
     * @param string $meta_key
     * @param string $value
     * @return array<string,mixed>
     */
    private static function query_unique_attachment_by_meta(string $meta_key, string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [
                'status' => 'miss',
                'target_id' => 0,
                'matched_via' => '',
            ];
        }

        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 2,
            'fields' => 'ids',
            'meta_key' => $meta_key,
            'meta_value' => $value,
            'no_found_rows' => true,
            'suppress_filters' => true,
        ]);

        $ids = array_values(array_map('absint', is_array($ids) ? $ids : []));
        if (count($ids) === 1) {
            return [
                'status' => 'hit',
                'target_id' => (int) $ids[0],
                'matched_via' => $meta_key === '_wp_attached_file' ? 'relative_path' : $meta_key,
            ];
        }

        if (count($ids) > 1) {
            return [
                'status' => 'conflict',
                'target_id' => 0,
                'matched_via' => $meta_key,
                'reason' => 'duplicate_' . sanitize_key($meta_key),
                'conflicts' => $ids,
            ];
        }

        return [
            'status' => 'miss',
            'target_id' => 0,
            'matched_via' => '',
        ];
    }

    /**
     * @param array<string,mixed> $manifest
     * @return true|\WP_Error
     */
    private static function validate_manifest(array $manifest)
    {
        if ((string) ($manifest['kind'] ?? '') !== 'dbvc_media_mirror') {
            return new \WP_Error('dbvc_media_hydration_wrong_manifest_kind', __('Expected a DBVC media mirror manifest.', 'dbvc'));
        }

        if ((int) ($manifest['schema'] ?? 0) !== 1) {
            return new \WP_Error('dbvc_media_hydration_unsupported_schema', __('Unsupported media mirror manifest schema.', 'dbvc'));
        }

        if (! isset($manifest['attachments']) || ! is_array($manifest['attachments'])) {
            return new \WP_Error('dbvc_media_hydration_missing_attachments', __('Media mirror manifest does not contain attachments.', 'dbvc'));
        }

        return true;
    }

    /**
     * @return array<string,mixed>
     */
    private static function empty_summary(): array
    {
        return [
            'items' => 0,
            'ok' => 0,
            'needs_hydration' => 0,
            'needs_metadata_repair' => 0,
            'hash_mismatch' => 0,
            'target_attachment_missing' => 0,
            'source_file_missing' => 0,
            'unsafe_path' => 0,
            'conflict' => 0,
            'blocked' => 0,
            'actions' => [],
        ];
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $item
     * @return void
     */
    private static function accumulate_summary(array &$summary, array $item): void
    {
        $summary['items']++;
        $status = (string) ($item['status'] ?? 'blocked');
        if (! isset($summary[$status])) {
            $summary[$status] = 0;
        }
        $summary[$status]++;

        $action = (string) ($item['planned_action'] ?? 'block');
        if (! isset($summary['actions'][$action])) {
            $summary['actions'][$action] = 0;
        }
        $summary['actions'][$action]++;
        ksort($summary['actions']);
    }

    /**
     * @param string $hash
     * @return string
     */
    private static function normalize_hash(string $hash): string
    {
        $hash = trim($hash);
        if ($hash === '') {
            return '';
        }

        return strpos($hash, ':') === false ? 'sha256:' . $hash : $hash;
    }

    /**
     * @param string $hash
     * @return string
     */
    private static function strip_hash_prefix(string $hash): string
    {
        $hash = trim($hash);
        if (strpos($hash, ':') === false) {
            return $hash;
        }

        [, $value] = explode(':', $hash, 2);
        return trim($value);
    }

    /**
     * @param string $mime_group
     * @param string $mime_type
     * @return bool
     */
    private static function metadata_expected(string $mime_group, string $mime_type): bool
    {
        $mime_group = $mime_group ?: FileStateService::mime_group($mime_type);
        return in_array($mime_group, ['image', 'video', 'audio'], true);
    }
}
