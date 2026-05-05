<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Packages
{
    public const OPTION_PACKAGES = 'dbvc_bricks_packages';
    public const OPTION_PACKAGE_CATALOG = 'dbvc_bricks_package_catalog';
    public const OPTION_PACKAGE_PREFIX = 'dbvc_bricks_package_';
    public const OPTION_PUBLISH_RUNS = 'dbvc_bricks_publish_remote_runs';
    public const REMOTE_PACKAGE_MAX_BYTES = 5242880;
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PUBLISHED = 'PUBLISHED';
    public const STATUS_SUPERSEDED = 'SUPERSEDED';
    public const STATUS_REVOKED = 'REVOKED';
    public const RETRY_BASE_SECONDS = 300;
    public const RETRY_MAX_ATTEMPTS = 5;
    public const PUBLISH_RUNS_DEFAULT_LIMIT = 20;
    public const PUBLISH_RUNS_MAX_ITEMS = 50;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_packages()
    {
        $stored = self::get_catalog_store();
        if (! is_array($stored)) {
            return [];
        }

        $packages = [];
        foreach ($stored as $package_id => $package) {
            if (! is_array($package)) {
                continue;
            }

            $entry = self::build_package_catalog_entry($package, (string) $package_id);
            $resolved_package_id = sanitize_text_field((string) ($entry['package_id'] ?? $package_id));
            if ($resolved_package_id === '') {
                continue;
            }

            $packages[$resolved_package_id] = $entry;
        }

        return $packages;
    }

    /**
     * @param string $package_id
     * @return array<string, mixed>|null
     */
    public static function get_package($package_id)
    {
        $package_id = sanitize_text_field((string) $package_id);
        if ($package_id === '') {
            return null;
        }

        $stored = get_option(self::get_package_option_name($package_id), null);
        if (is_array($stored)) {
            return self::normalize_stored_package_record($stored, $package_id);
        }

        $packages = get_option(self::OPTION_PACKAGES, []);
        if (! is_array($packages)) {
            return null;
        }

        return isset($packages[$package_id]) && is_array($packages[$package_id])
            ? self::normalize_stored_package_record($packages[$package_id], $package_id)
            : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function get_catalog_store()
    {
        $primary = get_option(self::OPTION_PACKAGES, []);
        if (! is_array($primary)) {
            $primary = [];
        }

        if (! self::primary_store_requires_secondary_catalog($primary)) {
            return $primary;
        }

        $secondary = get_option(self::OPTION_PACKAGE_CATALOG, []);
        return (is_array($secondary) && ! empty($secondary)) ? $secondary : $primary;
    }

    /**
     * @param string $package_id
     * @return string
     */
    private static function get_package_option_name($package_id)
    {
        $package_id = sanitize_key((string) $package_id);
        return self::OPTION_PACKAGE_PREFIX . substr($package_id, 0, 160);
    }

    /**
     * @param array<string, mixed> $packages
     * @return bool
     */
    private static function primary_store_requires_secondary_catalog(array $packages)
    {
        foreach ($packages as $package) {
            if (! is_array($package)) {
                continue;
            }

            if (self::package_entry_contains_full_manifest($package)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $package
     * @return bool
     */
    private static function package_entry_contains_full_manifest(array $package)
    {
        if (! empty($package['payload_index']) && is_array($package['payload_index'])) {
            return true;
        }

        $artifacts = isset($package['artifacts']) && is_array($package['artifacts']) ? $package['artifacts'] : [];
        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }
            if (array_key_exists('payload', $artifact) || array_key_exists('entity_id', $artifact)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public static function get_publish_runs($limit = self::PUBLISH_RUNS_DEFAULT_LIMIT)
    {
        $rows = get_option(self::OPTION_PUBLISH_RUNS, []);
        if (! is_array($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $items[] = self::sanitize_publish_run_entry($row);
        }

        usort($items, static function ($a, $b) {
            if (! is_array($a) && ! is_array($b)) {
                return 0;
            }
            if (! is_array($a)) {
                return 1;
            }
            if (! is_array($b)) {
                return -1;
            }

            $a_at = (string) ($a['created_at'] ?? '');
            $b_at = (string) ($b['created_at'] ?? '');
            if ($a_at !== $b_at) {
                return strcmp($b_at, $a_at);
            }

            return strcmp((string) ($b['run_id'] ?? ''), (string) ($a['run_id'] ?? ''));
        });

        $limit = max(1, (int) $limit);
        return array_slice($items, 0, $limit);
    }

    /**
     * @param array<string, mixed> $entry
     * @return void
     */
    private static function record_publish_run(array $entry)
    {
        $rows = get_option(self::OPTION_PUBLISH_RUNS, []);
        if (! is_array($rows)) {
            $rows = [];
        }

        array_unshift($rows, self::sanitize_publish_run_entry($entry));
        if (count($rows) > self::PUBLISH_RUNS_MAX_ITEMS) {
            $rows = array_slice($rows, 0, self::PUBLISH_RUNS_MAX_ITEMS);
        }

        update_option(self::OPTION_PUBLISH_RUNS, array_values($rows), false);
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private static function sanitize_publish_run_entry(array $entry)
    {
        $targeting = isset($entry['targeting']) && is_array($entry['targeting']) ? $entry['targeting'] : [];
        $target_mode = sanitize_key((string) ($targeting['mode'] ?? 'all'));
        if (! in_array($target_mode, ['all', 'selected'], true)) {
            $target_mode = 'all';
        }
        $site_uids = [];
        foreach ((array) ($targeting['site_uids'] ?? []) as $site_uid) {
            $site_uid = sanitize_key((string) $site_uid);
            if ($site_uid !== '' && ! in_array($site_uid, $site_uids, true)) {
                $site_uids[] = $site_uid;
            }
        }

        $result = sanitize_key((string) ($entry['result'] ?? 'failed'));
        if (! in_array($result, ['success', 'failed'], true)) {
            $result = 'failed';
        }

        return [
            'run_id' => sanitize_key((string) ($entry['run_id'] ?? '')),
            'idempotency_key' => sanitize_text_field((string) ($entry['idempotency_key'] ?? '')),
            'correlation_id' => sanitize_text_field((string) ($entry['correlation_id'] ?? '')),
            'created_at' => self::sanitize_timestamp((string) ($entry['created_at'] ?? gmdate('c'))),
            'actor_id' => max(0, (int) ($entry['actor_id'] ?? 0)),
            'site_uid' => sanitize_key((string) ($entry['site_uid'] ?? '')),
            'package_id' => sanitize_text_field((string) ($entry['package_id'] ?? '')),
            'channel' => sanitize_key((string) ($entry['channel'] ?? '')),
            'artifact_count' => max(0, (int) ($entry['artifact_count'] ?? 0)),
            'package_bytes' => max(0, (int) ($entry['package_bytes'] ?? 0)),
            'targeting' => [
                'mode' => $target_mode,
                'site_uids' => $target_mode === 'selected' ? array_values($site_uids) : [],
            ],
            'remote_url' => esc_url_raw((string) ($entry['remote_url'] ?? '')),
            'result' => $result,
            'http_status' => max(0, (int) ($entry['http_status'] ?? 0)),
            'error_code' => sanitize_key((string) ($entry['error_code'] ?? '')),
            'message' => sanitize_textarea_field((string) ($entry['message'] ?? '')),
            'receipt_id' => sanitize_text_field((string) ($entry['receipt_id'] ?? '')),
            'response' => self::sanitize_publish_run_payload($entry['response'] ?? []),
            'remote_package_visibility' => self::sanitize_publish_run_payload($entry['remote_package_visibility'] ?? []),
            'preflight' => self::sanitize_publish_run_payload($entry['preflight'] ?? []),
            'channel_force_audit' => self::sanitize_publish_run_payload($entry['channel_force_audit'] ?? []),
        ];
    }

    /**
     * @param mixed $payload
     * @return mixed
     */
    private static function sanitize_publish_run_payload($payload)
    {
        if (
            class_exists('DBVC_Bricks_Addon')
            && method_exists('DBVC_Bricks_Addon', 'sanitize_diagnostics_payload')
        ) {
            return DBVC_Bricks_Addon::sanitize_diagnostics_payload($payload);
        }

        if (is_array($payload)) {
            $clean = [];
            foreach ($payload as $key => $value) {
                $clean_key = is_string($key) ? sanitize_key($key) : (int) $key;
                $clean[$clean_key] = self::sanitize_publish_run_payload($value);
            }
            return $clean;
        }

        if (is_bool($payload) || is_int($payload) || is_float($payload) || $payload === null) {
            return $payload;
        }

        return sanitize_text_field((string) $payload);
    }

    /**
     * @param array<string, mixed> $package
     * @param string $fallback_package_id
     * @return array<string, mixed>
     */
    private static function normalize_stored_package_record(array $package, $fallback_package_id = '')
    {
        $package_id = sanitize_text_field((string) ($package['package_id'] ?? $fallback_package_id));
        if ($package_id !== '') {
            $package['package_id'] = $package_id;
        }

        return $package;
    }

    /**
     * @param string $error_code
     * @return string
     */
    public static function get_remediation_hint($error_code)
    {
        $error_code = sanitize_key((string) $error_code);
        $map = [
            'package_payload_too_large' => 'Reduce package size by limiting artifacts, then rerun publish preflight.',
            'dbvc_bricks_publish_remote_http_error' => 'Verify mothership URL/network/TLS settings, then retry.',
            'dbvc_bricks_publish_remote_failed' => 'Inspect remote response and credentials, then retry publish.',
            'dbvc_bricks_connection_test_failed' => 'Re-check API Key ID/Secret and mothership role configuration.',
            'dbvc_bricks_pull_latest_not_found' => 'Confirm channel and targeting allow this site UID to receive packages.',
        ];
        return isset($map[$error_code]) ? $map[$error_code] : 'Review diagnostics payload and retry with corrected configuration.';
    }

    /**
     * @param array<string, mixed> $package
     * @param string $state
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function append_timeline_event(array $package, $state, array $context = [])
    {
        $state = sanitize_key((string) $state);
        if (! in_array($state, ['sent', 'received', 'eligible', 'pulled', 'applied', 'failed'], true)) {
            $state = 'failed';
        }
        if (! isset($package['delivery_timeline']) || ! is_array($package['delivery_timeline'])) {
            $package['delivery_timeline'] = [];
        }
        $package['delivery_timeline'][] = [
            'state' => $state,
            'at' => gmdate('c'),
            'actor_id' => get_current_user_id(),
            'context' => $context,
        ];
        if (count($package['delivery_timeline']) > 100) {
            $package['delivery_timeline'] = array_slice($package['delivery_timeline'], -100);
        }
        return $package;
    }

    /**
     * @param array<string, mixed> $package
     * @param string $operation
     * @param bool $ok
     * @param string $error_code
     * @return array<string, mixed>
     */
    public static function update_transport_state(array $package, $operation, $ok, $error_code = '')
    {
        $operation = sanitize_key((string) $operation);
        if ($operation === '') {
            $operation = 'unknown';
        }
        if (! isset($package['delivery_transport']) || ! is_array($package['delivery_transport'])) {
            $package['delivery_transport'] = [];
        }
        $transport = isset($package['delivery_transport'][$operation]) && is_array($package['delivery_transport'][$operation])
            ? $package['delivery_transport'][$operation]
            : [
                'attempt_count' => 0,
                'dead_letter' => false,
            ];
        $attempt_count = max(0, (int) ($transport['attempt_count'] ?? 0)) + 1;
        $transport['attempt_count'] = $attempt_count;
        $transport['last_attempt_at'] = gmdate('c');
        $transport['last_status'] = $ok ? 'success' : 'failed';
        $transport['last_error'] = $ok ? '' : sanitize_key((string) $error_code);
        $transport['next_retry_at'] = '';
        $transport['retry_backoff_seconds'] = 0;
        $transport['dead_letter'] = false;
        $transport['remediation_hint'] = $ok ? '' : self::get_remediation_hint((string) $error_code);
        if (! $ok) {
            $backoff_seconds = min(self::RETRY_BASE_SECONDS * (int) pow(2, max(0, $attempt_count - 1)), HOUR_IN_SECONDS);
            $transport['retry_backoff_seconds'] = $backoff_seconds;
            $transport['next_retry_at'] = gmdate('c', time() + $backoff_seconds);
            if ($attempt_count >= self::RETRY_MAX_ATTEMPTS) {
                $transport['dead_letter'] = true;
            }
        }
        $package['delivery_transport'][$operation] = $transport;
        return $package;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function list_packages(array $filters = [])
    {
        $items = array_values(self::get_packages());
        $channel = isset($filters['channel']) ? sanitize_key((string) $filters['channel']) : '';
        $site_uid = isset($filters['site_uid']) ? sanitize_key((string) $filters['site_uid']) : '';
        if ($channel !== '') {
            $items = array_values(array_filter($items, static function ($item) use ($channel) {
                return (($item['channel'] ?? '') === $channel);
            }));
        }
        if ($site_uid !== '') {
            $items = array_values(array_filter($items, static function ($item) use ($site_uid) {
                $targeting = isset($item['targeting']) && is_array($item['targeting']) ? $item['targeting'] : ['mode' => 'all', 'site_uids' => []];
                $mode = isset($targeting['mode']) ? (string) $targeting['mode'] : 'all';
                if ($mode === 'all') {
                    return true;
                }
                $uids = isset($targeting['site_uids']) && is_array($targeting['site_uids']) ? $targeting['site_uids'] : [];
                return in_array($site_uid, $uids, true);
            }));
            $items = array_values(array_map(static function ($item) use ($site_uid) {
                if (! is_array($item)) {
                    return $item;
                }
                $item['visibility_reason'] = self::get_visibility_reason($item, $site_uid);
                return $item;
            }, $items));
        }
        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : 50;
        $items = array_values(array_map(static function ($item) {
            if (! is_array($item)) {
                return $item;
            }
            $item = self::augment_package_metadata($item);
            return self::annotate_package_with_protected_variants($item, false);
        }, $items));
        usort($items, static function ($a, $b) {
            if (! is_array($a) && ! is_array($b)) {
                return 0;
            }
            if (! is_array($a)) {
                return 1;
            }
            if (! is_array($b)) {
                return -1;
            }

            $a_updated = self::package_list_timestamp($a, ['updated_at', 'created_at']);
            $b_updated = self::package_list_timestamp($b, ['updated_at', 'created_at']);
            if ($a_updated !== $b_updated) {
                return $b_updated <=> $a_updated;
            }

            $a_created = self::package_list_timestamp($a, ['created_at']);
            $b_created = self::package_list_timestamp($b, ['created_at']);
            if ($a_created !== $b_created) {
                return $b_created <=> $a_created;
            }

            return strcmp((string) ($b['package_id'] ?? ''), (string) ($a['package_id'] ?? ''));
        });
        return array_slice($items, 0, $limit);
    }

    /**
     * @param array<string, mixed> $package
     * @param array<int, string> $keys
     * @return int
     */
    private static function package_list_timestamp(array $package, array $keys)
    {
        foreach ($keys as $key) {
            $value = (string) ($package[$key] ?? '');
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return (int) $timestamp;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $item
     * @param string $site_uid
     * @return bool
     */
    public static function is_package_visible_to_site(array $item, $site_uid)
    {
        $site_uid = sanitize_key((string) $site_uid);
        if ($site_uid === '') {
            return true;
        }
        $targeting = isset($item['targeting']) && is_array($item['targeting']) ? $item['targeting'] : ['mode' => 'all', 'site_uids' => []];
        $mode = isset($targeting['mode']) ? sanitize_key((string) $targeting['mode']) : 'all';
        if ($mode !== 'selected') {
            return true;
        }
        $uids = isset($targeting['site_uids']) && is_array($targeting['site_uids']) ? $targeting['site_uids'] : [];
        return in_array($site_uid, $uids, true);
    }

    /**
     * @param array<string, mixed> $item
     * @param string $site_uid
     * @return string
     */
    public static function get_visibility_reason(array $item, $site_uid)
    {
        $site_uid = sanitize_key((string) $site_uid);
        $targeting = isset($item['targeting']) && is_array($item['targeting']) ? $item['targeting'] : ['mode' => 'all', 'site_uids' => []];
        $mode = isset($targeting['mode']) ? sanitize_key((string) $targeting['mode']) : 'all';
        if ($mode !== 'selected') {
            return 'target_all';
        }
        $uids = isset($targeting['site_uids']) && is_array($targeting['site_uids']) ? $targeting['site_uids'] : [];
        return in_array($site_uid, $uids, true) ? 'target_selected_match' : 'target_selected_miss';
    }

    /**
     * @param string $site_uid
     * @param string $channel
     * @return array<string, mixed>|null
     */
    public static function get_latest_visible_package_for_site($site_uid, $channel = '')
    {
        $site_uid = sanitize_key((string) $site_uid);
        $channel = sanitize_key((string) $channel);
        $items = array_values(self::get_packages());
        return self::select_latest_visible_package($items, $site_uid, $channel);
    }

    /**
     * @param array<int, mixed> $items
     * @param string $site_uid
     * @param string $channel
     * @return array<string, mixed>|null
     */
    private static function select_latest_visible_package(array $items, $site_uid, $channel = '')
    {
        $site_uid = sanitize_key((string) $site_uid);
        $channel = sanitize_key((string) $channel);
        $eligible = array_values(array_filter($items, static function ($item) use ($site_uid, $channel) {
            if (! is_array($item)) {
                return false;
            }
            if (($item['status'] ?? self::STATUS_PUBLISHED) !== self::STATUS_PUBLISHED) {
                return false;
            }
            if ($channel !== '' && (($item['channel'] ?? '') !== $channel)) {
                return false;
            }
            return self::is_package_visible_to_site($item, $site_uid);
        }));
        if (empty($eligible)) {
            return null;
        }

        usort($eligible, static function ($a, $b) {
            $a_ts = strtotime((string) ($a['created_at'] ?? $a['updated_at'] ?? ''));
            $b_ts = strtotime((string) ($b['created_at'] ?? $b['updated_at'] ?? ''));
            if ($a_ts === $b_ts) {
                return strcmp((string) ($b['version'] ?? ''), (string) ($a['version'] ?? ''));
            }
            return $b_ts <=> $a_ts;
        });
        if (! is_array($eligible[0])) {
            return null;
        }

        $package_id = sanitize_text_field((string) ($eligible[0]['package_id'] ?? ''));
        if ($package_id === '') {
            return $eligible[0];
        }

        $full = self::get_package($package_id);
        return is_array($full) ? $full : $eligible[0];
    }

    /**
     * @param string $site_uid
     * @param string $channel
     * @return array<string, mixed>|\WP_Error|null
     */
    private static function fetch_remote_latest_visible_package_for_site($site_uid, $channel = '')
    {
        $site_uid = sanitize_key((string) $site_uid);
        $channel = sanitize_key((string) $channel);
        if ($site_uid === '') {
            return new \WP_Error('dbvc_bricks_pull_latest_site_uid_required', 'Site UID is required for remote pull.', ['status' => 400]);
        }

        $auth_method = DBVC_Bricks_Addon::get_setting('dbvc_bricks_auth_method', 'hmac');
        if ($auth_method !== 'wp_app_password') {
            return new \WP_Error('dbvc_bricks_pull_latest_auth_invalid', 'Remote pull currently requires wp_app_password auth method.', ['status' => 400]);
        }

        $mothership_url = untrailingslashit(DBVC_Bricks_Addon::get_setting('dbvc_bricks_mothership_url', ''));
        $username = DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_key_id', '');
        $app_password = DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_secret', '');
        if ($mothership_url === '' || $username === '' || $app_password === '') {
            return new \WP_Error('dbvc_bricks_pull_latest_remote_config_required', 'Mothership URL, API Key ID, and API Secret are required.', ['status' => 400]);
        }

        $query = [
            'site_uid' => $site_uid,
            'limit' => 200,
        ];
        if ($channel !== '') {
            $query['channel'] = $channel;
        }
        $remote_url = add_query_arg($query, $mothership_url . '/wp-json/dbvc/v1/bricks/packages');
        $basic = base64_encode($username . ':' . $app_password);
        $response = wp_remote_get($remote_url, [
            'timeout' => max(5, DBVC_Bricks_Addon::get_int_setting('dbvc_bricks_http_timeout', 30)),
            'sslverify' => DBVC_Bricks_Addon::get_bool_setting('dbvc_bricks_tls_verify', true),
            'headers' => [
                'Authorization' => 'Basic ' . $basic,
                'Accept' => 'application/json',
                'X-DBVC-Correlation-ID' => 'dbvc-pull-' . substr(wp_hash(gmdate('c')), 0, 10),
            ],
        ]);
        if (is_wp_error($response)) {
            return new \WP_Error('dbvc_bricks_pull_latest_remote_http_error', $response->get_error_message(), ['status' => 502]);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode((string) $body, true);
        if (! is_array($data)) {
            $data = ['raw' => (string) $body];
        }
        if ($status < 200 || $status >= 300) {
            return new \WP_Error(
                'dbvc_bricks_pull_latest_remote_failed',
                (string) ($data['message'] ?? 'Remote pull latest request failed.'),
                ['status' => $status, 'response' => $data]
            );
        }

        $items = isset($data['items']) && is_array($data['items']) ? array_values($data['items']) : [];
        return self::select_latest_visible_package($items, $site_uid, $channel);
    }

    /**
     * @param string $package_id
     * @param string $site_uid
     * @param string $receipt_id
     * @return true|\WP_Error
     */
    private static function post_remote_pull_ack($package_id, $site_uid, $receipt_id)
    {
        $package_id = sanitize_text_field((string) $package_id);
        $site_uid = sanitize_key((string) $site_uid);
        $receipt_id = sanitize_text_field((string) $receipt_id);
        if ($package_id === '' || $site_uid === '') {
            return new \WP_Error('dbvc_bricks_pull_ack_invalid', 'Package ID and site UID are required for remote pull ack.', ['status' => 400]);
        }

        $auth_method = DBVC_Bricks_Addon::get_setting('dbvc_bricks_auth_method', 'hmac');
        if ($auth_method !== 'wp_app_password') {
            return new \WP_Error('dbvc_bricks_pull_ack_auth_invalid', 'Remote ack currently requires wp_app_password auth method.', ['status' => 400]);
        }

        $mothership_url = untrailingslashit(DBVC_Bricks_Addon::get_setting('dbvc_bricks_mothership_url', ''));
        $username = DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_key_id', '');
        $app_password = DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_secret', '');
        if ($mothership_url === '' || $username === '' || $app_password === '') {
            return new \WP_Error('dbvc_bricks_pull_ack_remote_config_required', 'Mothership URL, API Key ID, and API Secret are required.', ['status' => 400]);
        }

        $remote_url = $mothership_url . '/wp-json/dbvc/v1/bricks/packages/' . rawurlencode($package_id) . '/ack';
        $basic = base64_encode($username . ':' . $app_password);
        $response = wp_remote_post($remote_url, [
            'timeout' => max(5, DBVC_Bricks_Addon::get_int_setting('dbvc_bricks_http_timeout', 30)),
            'sslverify' => DBVC_Bricks_Addon::get_bool_setting('dbvc_bricks_tls_verify', true),
            'headers' => [
                'Authorization' => 'Basic ' . $basic,
                'Content-Type' => 'application/json',
                'X-DBVC-Correlation-ID' => 'dbvc-pull-ack-' . substr(wp_hash(gmdate('c')), 0, 10),
            ],
            'body' => wp_json_encode([
                'site_uid' => $site_uid,
                'state' => 'pulled',
                'receipt_id' => $receipt_id,
            ]),
        ]);
        if (is_wp_error($response)) {
            return new \WP_Error('dbvc_bricks_pull_ack_remote_http_error', $response->get_error_message(), ['status' => 502]);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode((string) $body, true);
            if (! is_array($data)) {
                $data = ['raw' => (string) $body];
            }
            return new \WP_Error('dbvc_bricks_pull_ack_remote_failed', (string) ($data['message'] ?? 'Remote pull ack failed.'), [
                'status' => $status,
                'response' => $data,
            ]);
        }

        return true;
    }

    /**
     * Verify that a just-published remote package is readable on the mothership.
     *
     * @param string $mothership_url
     * @param string $username
     * @param string $app_password
     * @param string $package_id
     * @return array<string, mixed>
     */
    private static function verify_remote_package_visibility($mothership_url, $username, $app_password, $package_id)
    {
        $mothership_url = untrailingslashit((string) $mothership_url);
        $package_id = sanitize_text_field((string) $package_id);
        $probe_url = $mothership_url . '/wp-json/dbvc/v1/bricks/packages/' . rawurlencode($package_id);

        if ($mothership_url === '' || $package_id === '') {
            return [
                'ok' => false,
                'status' => 400,
                'package_id' => $package_id,
                'probe_url' => $probe_url,
                'error_code' => 'dbvc_bricks_publish_remote_verify_invalid_input',
                'message' => 'Mothership visibility verification requires mothership URL and package ID.',
            ];
        }

        $basic = base64_encode((string) $username . ':' . (string) $app_password);
        $response = wp_remote_get($probe_url, [
            'timeout' => max(5, DBVC_Bricks_Addon::get_int_setting('dbvc_bricks_http_timeout', 30)),
            'sslverify' => DBVC_Bricks_Addon::get_bool_setting('dbvc_bricks_tls_verify', true),
            'headers' => [
                'Authorization' => 'Basic ' . $basic,
                'Accept' => 'application/json',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
                'X-DBVC-Correlation-ID' => 'dbvc-verify-' . substr(wp_hash($package_id . '|' . gmdate('c')), 0, 10),
            ],
        ]);

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'status' => 502,
                'package_id' => $package_id,
                'probe_url' => $probe_url,
                'error_code' => 'dbvc_bricks_publish_remote_verify_http_error',
                'message' => (string) $response->get_error_message(),
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode((string) $body, true);
        if (! is_array($data)) {
            $data = ['raw' => (string) $body];
        }

        if ($status < 200 || $status >= 300) {
            $message = '';
            if (isset($data['message']) && is_string($data['message'])) {
                $message = sanitize_text_field($data['message']);
            } elseif (isset($data['code']) && is_string($data['code'])) {
                $message = sanitize_text_field($data['code']);
            }
            if ($message === '') {
                $message = 'Mothership package verification failed.';
            }

            return [
                'ok' => false,
                'status' => $status > 0 ? $status : 500,
                'package_id' => $package_id,
                'probe_url' => $probe_url,
                'error_code' => 'dbvc_bricks_publish_remote_verify_failed',
                'message' => $message,
            ];
        }

        $manifest = isset($data['manifest']) && is_array($data['manifest']) ? $data['manifest'] : [];
        if (empty($manifest)) {
            return [
                'ok' => false,
                'status' => $status,
                'package_id' => $package_id,
                'probe_url' => $probe_url,
                'error_code' => 'dbvc_bricks_publish_remote_verify_invalid_response',
                'message' => 'Mothership verification response did not include a package manifest.',
            ];
        }

        return [
            'ok' => true,
            'status' => $status,
            'package_id' => sanitize_text_field((string) ($manifest['package_id'] ?? $package_id)),
            'probe_url' => $probe_url,
            'channel' => sanitize_key((string) ($manifest['channel'] ?? '')),
            'artifact_count' => max(0, (int) ($manifest['artifact_count'] ?? 0)),
            'receipt_id' => sanitize_text_field((string) ($manifest['receipt_id'] ?? '')),
            'updated_at' => self::sanitize_timestamp((string) ($manifest['updated_at'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function normalize_package(array $payload)
    {
        $package_id = sanitize_text_field((string) ($payload['package_id'] ?? ''));
        $version = sanitize_text_field((string) ($payload['version'] ?? ''));
        $channel = sanitize_key((string) ($payload['channel'] ?? 'stable'));
        if ($channel === '') {
            $channel = 'stable';
        }
        $source_site = isset($payload['source_site']) && is_array($payload['source_site']) ? $payload['source_site'] : [];
        $source_site_uid = sanitize_key((string) ($source_site['site_uid'] ?? get_current_blog_id()));
        $source_base_url = esc_url_raw((string) ($source_site['base_url'] ?? home_url('/')));
        $artifacts = isset($payload['artifacts']) && is_array($payload['artifacts']) ? array_values($payload['artifacts']) : [];
        $schema_version = sanitize_text_field((string) ($payload['schema_version'] ?? DBVC_Bricks_Addon::UI_CONTRACT_VERSION));

        if ($package_id === '') {
            $package_id = 'pkg_' . gmdate('Ymd_His') . '_' . substr(wp_hash(wp_json_encode([$version, $channel, $source_site_uid])), 0, 8);
        }
        if ($version === '') {
            $version = gmdate('Y.m.d.His');
        }

        $base = [
            'package_id' => $package_id,
            'schema_version' => $schema_version,
            'parse_mode' => self::normalize_parse_mode((string) ($payload['parse_mode'] ?? 'strict')),
            'version' => $version,
            'channel' => $channel,
            'status' => self::STATUS_PUBLISHED,
            'source_site' => [
                'site_uid' => $source_site_uid,
                'base_url' => $source_base_url,
            ],
            'artifacts' => $artifacts,
            'artifact_count' => count($artifacts),
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        if (isset($payload['protected_variant_summary']) && is_array($payload['protected_variant_summary'])) {
            $base['protected_variant_summary'] = self::sanitize_protected_variant_summary($payload['protected_variant_summary']);
        }
        if (isset($payload['protected_variant_lookup']) && is_array($payload['protected_variant_lookup'])) {
            $base['protected_variant_lookup'] = self::sanitize_protected_variant_lookup($payload['protected_variant_lookup']);
        }
        $base['digest'] = 'sha256:' . hash('sha256', wp_json_encode($base));
        return $base;
    }

    /**
     * @param string $parse_mode
     * @return string
     */
    public static function normalize_parse_mode($parse_mode)
    {
        $parse_mode = sanitize_key((string) $parse_mode);
        if (! in_array($parse_mode, ['strict', 'lenient'], true)) {
            return 'strict';
        }
        return $parse_mode;
    }

    /**
     * @param array<string, mixed> $package
     * @return array<string, mixed>
     */
    public static function build_publish_preflight(array $package)
    {
        $package = self::normalize_package($package);
        $encoded_package = wp_json_encode($package);
        $package_bytes = is_string($encoded_package) ? strlen($encoded_package) : 0;
        $max_package_bytes = self::get_remote_package_max_bytes();
        $schema_version = (string) ($package['schema_version'] ?? '');
        $supported_versions = (array) apply_filters('dbvc_bricks_supported_schema_versions', [DBVC_Bricks_Addon::UI_CONTRACT_VERSION]);
        $supported_versions = array_values(array_filter(array_map('sanitize_text_field', $supported_versions)));
        if (empty($supported_versions)) {
            $supported_versions = [DBVC_Bricks_Addon::UI_CONTRACT_VERSION];
        }

        $warnings = [];
        $errors = [];
        if (! in_array($schema_version, $supported_versions, true)) {
            $warnings[] = 'schema_version_not_explicitly_supported';
            if (($package['parse_mode'] ?? 'strict') === 'strict') {
                $errors[] = 'schema_version_unsupported_in_strict_mode';
            }
        }
        if ($package_bytes > $max_package_bytes) {
            $errors[] = 'package_payload_too_large';
        }

        return [
            'ok' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'limits' => [
                'max_package_bytes' => $max_package_bytes,
                'package_bytes' => $package_bytes,
            ],
            'compatibility' => [
                'schema_version' => $schema_version,
                'supported_versions' => $supported_versions,
                'parse_mode' => (string) ($package['parse_mode'] ?? 'strict'),
            ],
            'package_preview' => [
                'package_id' => (string) ($package['package_id'] ?? ''),
                'version' => (string) ($package['version'] ?? ''),
                'channel' => (string) ($package['channel'] ?? ''),
                'artifact_count' => (int) ($package['artifact_count'] ?? 0),
                'digest' => (string) ($package['digest'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $targeting
     * @return array<string, mixed>|\WP_Error
     */
    public static function normalize_targeting(array $targeting)
    {
        $mode = sanitize_key((string) ($targeting['mode'] ?? 'all'));
        if (! in_array($mode, ['all', 'selected'], true)) {
            $mode = 'all';
        }
        $site_uids = isset($targeting['site_uids']) && is_array($targeting['site_uids']) ? $targeting['site_uids'] : [];
        $normalized_uids = [];
        foreach ($site_uids as $site_uid) {
            $clean = sanitize_key((string) $site_uid);
            if ($clean !== '' && ! in_array($clean, $normalized_uids, true)) {
                $normalized_uids[] = $clean;
            }
        }

        if ($mode === 'selected') {
            if (empty($normalized_uids)) {
                return new \WP_Error('dbvc_bricks_target_sites_required', 'selected mode requires at least one site_uid.', ['status' => 400]);
            }
            foreach ($normalized_uids as $site_uid) {
                if (! DBVC_Bricks_Connected_Sites::is_allowed_site($site_uid)) {
                    return new \WP_Error('dbvc_bricks_target_site_invalid', 'Target site not allowed: ' . $site_uid, ['status' => 400]);
                }
                $site = DBVC_Bricks_Connected_Sites::get_site($site_uid);
                $auth_mode = sanitize_key((string) ($site['auth_mode'] ?? ''));
                if ($auth_mode !== 'wp_app_password') {
                    return new \WP_Error('dbvc_bricks_target_site_auth_mode_invalid', 'Target site auth mode must be wp_app_password: ' . $site_uid, ['status' => 400]);
                }
            }
        }

        return [
            'mode' => $mode,
            'site_uids' => $mode === 'selected' ? array_values($normalized_uids) : [],
        ];
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function rest_list(\WP_REST_Request $request)
    {
        $items = self::list_packages([
            'channel' => $request->get_param('channel'),
            'limit' => $request->get_param('limit'),
            'site_uid' => $request->get_param('site_uid'),
        ]);
        return rest_ensure_response([
            'items' => $items,
            'next_cursor' => null,
        ]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function rest_publish_runs(\WP_REST_Request $request)
    {
        $limit = max(1, (int) $request->get_param('limit'));
        $items = self::get_publish_runs($limit);

        return rest_ensure_response([
            'items' => $items,
            'limit' => $limit,
        ]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_get(\WP_REST_Request $request)
    {
        $package_id = sanitize_text_field((string) $request->get_param('package_id'));
        $package = self::get_package($package_id);
        if (! $package) {
            return new \WP_Error('dbvc_bricks_package_not_found', 'Package not found.', ['status' => 404]);
        }
        $package = self::augment_package_metadata($package);
        $package = self::annotate_package_with_protected_variants($package, true);

        $response = [
            'manifest' => $package,
        ];
        if ($request->get_param('include_payload_index')) {
            $response['payload_index'] = isset($package['payload_index']) && is_array($package['payload_index'])
                ? $package['payload_index']
                : [];
        }

        return rest_ensure_response($response);
    }

    /**
     * @param array<string, mixed> $package
     * @return array<string, mixed>
     */
    private static function augment_package_metadata(array $package)
    {
        $source_site = isset($package['source_site']) && is_array($package['source_site']) ? $package['source_site'] : [];
        $base_url = esc_url_raw((string) ($source_site['base_url'] ?? ''));
        $domain = '';
        if ($base_url !== '') {
            $host = wp_parse_url($base_url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $domain = strtolower($host);
            }
        }
        $package['source_site_domain'] = $domain;
        return $package;
    }

    /**
     * @param array<string, mixed> $package
     * @param string $fallback_package_id
     * @return array<string, mixed>
     */
    private static function build_package_catalog_entry(array $package, $fallback_package_id = '')
    {
        $package = self::normalize_stored_package_record($package, $fallback_package_id);

        $source_site = isset($package['source_site']) && is_array($package['source_site']) ? $package['source_site'] : [];
        $targeting = isset($package['targeting']) && is_array($package['targeting']) ? $package['targeting'] : [];
        $target_mode = sanitize_key((string) ($targeting['mode'] ?? 'all'));
        if (! in_array($target_mode, ['all', 'selected'], true)) {
            $target_mode = 'all';
        }
        $target_site_uids = [];
        foreach ((array) ($targeting['site_uids'] ?? []) as $site_uid) {
            $site_uid = sanitize_key((string) $site_uid);
            if ($site_uid !== '' && ! in_array($site_uid, $target_site_uids, true)) {
                $target_site_uids[] = $site_uid;
            }
        }

        $entry = [
            'package_id' => sanitize_text_field((string) ($package['package_id'] ?? $fallback_package_id)),
            'schema_version' => sanitize_text_field((string) ($package['schema_version'] ?? '')),
            'parse_mode' => sanitize_key((string) ($package['parse_mode'] ?? 'strict')),
            'version' => sanitize_text_field((string) ($package['version'] ?? '')),
            'channel' => sanitize_key((string) ($package['channel'] ?? 'stable')),
            'status' => sanitize_text_field((string) ($package['status'] ?? self::STATUS_PUBLISHED)),
            'source_site' => [
                'site_uid' => sanitize_key((string) ($source_site['site_uid'] ?? '')),
                'base_url' => esc_url_raw((string) ($source_site['base_url'] ?? '')),
            ],
            'artifacts' => self::build_package_catalog_artifacts((array) ($package['artifacts'] ?? [])),
            'artifact_count' => max(0, (int) ($package['artifact_count'] ?? count((array) ($package['artifacts'] ?? [])))),
            'created_at' => self::sanitize_timestamp((string) ($package['created_at'] ?? '')),
            'updated_at' => self::sanitize_timestamp((string) ($package['updated_at'] ?? '')),
            'digest' => sanitize_text_field((string) ($package['digest'] ?? '')),
            'targeting' => [
                'mode' => $target_mode,
                'site_uids' => $target_mode === 'selected' ? $target_site_uids : [],
            ],
            'receipt_id' => sanitize_text_field((string) ($package['receipt_id'] ?? '')),
            'published_by' => max(0, (int) ($package['published_by'] ?? 0)),
        ];

        foreach (['delivery_timeline', 'acks', 'delivery_transport', 'channel_force_audit'] as $key) {
            if (isset($package[$key]) && is_array($package[$key])) {
                $entry[$key] = $package[$key];
            }
        }
        foreach (['visibility_reason', 'source_site_domain'] as $key) {
            if (isset($package[$key]) && is_scalar($package[$key])) {
                $entry[$key] = sanitize_text_field((string) $package[$key]);
            }
        }
        if (isset($package['protected_variant_summary']) && is_array($package['protected_variant_summary'])) {
            $entry['protected_variant_summary'] = self::sanitize_protected_variant_summary($package['protected_variant_summary']);
        }

        return $entry;
    }

    /**
     * @param array<int, mixed> $artifacts
     * @return array<int, array<string, mixed>>
     */
    private static function build_package_catalog_artifacts(array $artifacts)
    {
        $rows = [];
        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }

            $artifact_uid = sanitize_text_field((string) ($artifact['artifact_uid'] ?? ''));
            if ($artifact_uid === '') {
                continue;
            }

            $row = [
                'artifact_uid' => $artifact_uid,
                'artifact_type' => sanitize_key((string) ($artifact['artifact_type'] ?? '')),
                'hash' => sanitize_text_field((string) ($artifact['hash'] ?? '')),
            ];
            if (isset($artifact['protected_variant']) && is_array($artifact['protected_variant'])) {
                $lookup = self::sanitize_protected_variant_lookup([
                    $artifact_uid => $artifact['protected_variant'],
                ]);
                if (isset($lookup[$artifact_uid])) {
                    $row['protected_variant'] = $lookup[$artifact_uid];
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Persist one package into split local storage and verify both the full manifest
     * and lightweight catalog entry are readable afterward.
     *
     * @param array<string, mixed> $package
     * @param array<string, mixed> $context
     * @return true|\WP_Error
     */
    private static function persist_package(array $package, array $context = [])
    {
        global $wpdb;

        $package = self::normalize_stored_package_record($package);
        $package_id = sanitize_text_field((string) ($package['package_id'] ?? ''));
        if ($package_id === '') {
            return new \WP_Error('dbvc_bricks_package_persist_failed', 'Package could not be persisted to local storage.', [
                'status' => 500,
                'package_id' => '',
            ]);
        }

        $primary_store = get_option(self::OPTION_PACKAGES, []);
        if (! is_array($primary_store)) {
            $primary_store = [];
        }
        $secondary_catalog = get_option(self::OPTION_PACKAGE_CATALOG, []);
        if (! is_array($secondary_catalog)) {
            $secondary_catalog = [];
        }
        $catalog_source = self::primary_store_requires_secondary_catalog($primary_store)
            ? (! empty($secondary_catalog) ? $secondary_catalog : $primary_store)
            : $primary_store;
        $catalog = [];
        foreach ($catalog_source as $existing_package_id => $existing_package) {
            if (! is_array($existing_package)) {
                continue;
            }

            $existing_package = self::normalize_stored_package_record($existing_package, (string) $existing_package_id);
            $existing_package_id = sanitize_text_field((string) ($existing_package['package_id'] ?? $existing_package_id));
            if ($existing_package_id === '') {
                continue;
            }

            $catalog[$existing_package_id] = self::build_package_catalog_entry($existing_package, $existing_package_id);
        }
        $catalog[$package_id] = self::build_package_catalog_entry($package, $package_id);

        $package_option_name = self::get_package_option_name($package_id);
        update_option($package_option_name, $package, false);
        update_option(self::OPTION_PACKAGE_CATALOG, $catalog, false);
        if (! self::primary_store_requires_secondary_catalog($primary_store)) {
            update_option(self::OPTION_PACKAGES, $catalog, false);
        }

        $stored_package = get_option($package_option_name, null);
        $stored_catalog = self::primary_store_requires_secondary_catalog($primary_store)
            ? get_option(self::OPTION_PACKAGE_CATALOG, [])
            : get_option(self::OPTION_PACKAGES, []);
        if (
            is_array($stored_package)
            && is_array($stored_catalog)
            && isset($stored_catalog[$package_id])
            && is_array($stored_catalog[$package_id])
        ) {
            return true;
        }

        $error_data = [
            'status' => 500,
            'package_id' => $package_id,
            'package_option_name' => $package_option_name,
            'catalog_entry_present' => is_array($stored_catalog) && isset($stored_catalog[$package_id]),
            'catalog_store' => self::primary_store_requires_secondary_catalog($primary_store)
                ? self::OPTION_PACKAGE_CATALOG
                : self::OPTION_PACKAGES,
        ];
        foreach (['artifact_count', 'package_bytes', 'channel'] as $key) {
            if (array_key_exists($key, $context)) {
                $error_data[$key] = $context[$key];
            }
        }
        if ($wpdb instanceof wpdb && $wpdb->last_error !== '') {
            $error_data['db_error'] = sanitize_text_field((string) $wpdb->last_error);
        }

        return new \WP_Error(
            'dbvc_bricks_package_persist_failed',
            'Package could not be persisted to local storage.',
            $error_data
        );
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_create(\WP_REST_Request $request)
    {
        $idempotency_key = DBVC_Bricks_Idempotency::extract_key($request);
        if ($idempotency_key === '') {
            return new \WP_Error('dbvc_bricks_idempotency_required', 'Idempotency-Key is required.', ['status' => 400]);
        }
        $existing = DBVC_Bricks_Idempotency::get('packages_create', $idempotency_key);
        if (is_array($existing) && isset($existing['response']) && is_array($existing['response'])) {
            return rest_ensure_response($existing['response']);
        }

        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }
        $package_payload = isset($params['package']) && is_array($params['package']) ? $params['package'] : $params;
        $targeting_payload = isset($params['targeting']) && is_array($params['targeting']) ? $params['targeting'] : [];
        $package = self::normalize_package($package_payload);
        $targeting = self::normalize_targeting($targeting_payload);
        if (is_wp_error($targeting)) {
            return $targeting;
        }

        $package['targeting'] = $targeting;
        $package['receipt_id'] = 'pkg_rcpt_' . substr(wp_hash($package['package_id'] . gmdate('c')), 0, 12);
        $package['published_by'] = get_current_user_id();
        $package['updated_at'] = gmdate('c');
        $package = self::append_timeline_event($package, 'received', [
            'target_mode' => (string) ($targeting['mode'] ?? 'all'),
            'receipt_id' => (string) $package['receipt_id'],
        ]);

        $persist = self::persist_package($package, [
            'artifact_count' => (int) ($package['artifact_count'] ?? 0),
            'package_bytes' => strlen((string) wp_json_encode($package)),
            'channel' => (string) ($package['channel'] ?? ''),
        ]);
        if (is_wp_error($persist)) {
            return $persist;
        }

        $response = [
            'ok' => true,
            'package_id' => $package['package_id'],
            'status' => $package['status'],
            'receipt_id' => $package['receipt_id'],
            'targeting' => $package['targeting'],
        ];
        DBVC_Bricks_Idempotency::put('packages_create', $idempotency_key, $response);
        return rest_ensure_response($response);
    }

    /**
     * Create a package from the current site's Bricks Entities/options.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_bootstrap_create(\WP_REST_Request $request)
    {
        $idempotency_key = DBVC_Bricks_Idempotency::extract_key($request);
        if ($idempotency_key === '') {
            return new \WP_Error('dbvc_bricks_idempotency_required', 'Idempotency-Key is required.', ['status' => 400]);
        }
        $existing = DBVC_Bricks_Idempotency::get('packages_bootstrap_create', $idempotency_key);
        if (is_array($existing) && isset($existing['response']) && is_array($existing['response'])) {
            return rest_ensure_response($existing['response']);
        }

        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }

        $targeting_payload = isset($params['targeting']) && is_array($params['targeting']) ? $params['targeting'] : ['mode' => 'all', 'site_uids' => []];
        $targeting = self::normalize_targeting($targeting_payload);
        if (is_wp_error($targeting)) {
            return $targeting;
        }

        $artifacts = self::collect_local_artifacts();
        $site_uid = DBVC_Bricks_Addon::get_setting('dbvc_bricks_site_uid', '');
        if ($site_uid === '') {
            $site_uid = 'site_' . get_current_blog_id();
        }
        $package_payload = [
            'package_id' => sanitize_text_field((string) ($params['package_id'] ?? '')),
            'schema_version' => sanitize_text_field((string) ($params['schema_version'] ?? DBVC_Bricks_Addon::UI_CONTRACT_VERSION)),
            'parse_mode' => self::normalize_parse_mode((string) ($params['parse_mode'] ?? 'strict')),
            'version' => sanitize_text_field((string) ($params['version'] ?? gmdate('Y.m.d.His'))),
            'channel' => sanitize_key((string) ($params['channel'] ?? 'stable')),
            'source_site' => [
                'site_uid' => sanitize_key($site_uid),
                'base_url' => untrailingslashit(home_url('/')),
            ],
            'artifacts' => $artifacts,
        ];

        $package = self::normalize_package($package_payload);
        $package['targeting'] = $targeting;
        $package['receipt_id'] = 'pkg_rcpt_' . substr(wp_hash($package['package_id'] . gmdate('c')), 0, 12);
        $package['published_by'] = get_current_user_id();
        $package['updated_at'] = gmdate('c');
        $package = self::append_timeline_event($package, 'sent', [
            'source' => 'bootstrap_create',
            'receipt_id' => (string) $package['receipt_id'],
        ]);

        $persist = self::persist_package($package, [
            'artifact_count' => (int) ($package['artifact_count'] ?? 0),
            'package_bytes' => strlen((string) wp_json_encode($package)),
            'channel' => (string) ($package['channel'] ?? ''),
        ]);
        if (is_wp_error($persist)) {
            return $persist;
        }

        $response = [
            'ok' => true,
            'package_id' => $package['package_id'],
            'status' => $package['status'],
            'receipt_id' => $package['receipt_id'],
            'targeting' => $package['targeting'],
            'artifact_count' => (int) ($package['artifact_count'] ?? 0),
        ];
        DBVC_Bricks_Idempotency::put('packages_bootstrap_create', $idempotency_key, $response);
        return rest_ensure_response($response);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_promote(\WP_REST_Request $request)
    {
        $idempotency_key = DBVC_Bricks_Idempotency::extract_key($request);
        if ($idempotency_key === '') {
            return new \WP_Error('dbvc_bricks_idempotency_required', 'Idempotency-Key is required.', ['status' => 400]);
        }
        $existing = DBVC_Bricks_Idempotency::get('packages_promote', $idempotency_key);
        if (is_array($existing) && isset($existing['response']) && is_array($existing['response'])) {
            return rest_ensure_response($existing['response']);
        }

        $package_id = sanitize_text_field((string) $request->get_param('package_id'));
        $package = self::get_package($package_id);
        if (! is_array($package)) {
            return new \WP_Error('dbvc_bricks_package_not_found', 'Package not found.', ['status' => 404]);
        }
        $target_channel = sanitize_key((string) $request->get_param('channel'));
        if (! in_array($target_channel, ['canary', 'beta', 'stable'], true)) {
            return new \WP_Error('dbvc_bricks_channel_invalid', 'Invalid channel.', ['status' => 400]);
        }
        $current_channel = sanitize_key((string) ($package['channel'] ?? ''));
        $channel_order = [
            'canary' => 1,
            'beta' => 2,
            'stable' => 3,
        ];
        if (
            isset($channel_order[$current_channel], $channel_order[$target_channel])
            && $channel_order[$target_channel] <= $channel_order[$current_channel]
        ) {
            return new \WP_Error('dbvc_bricks_channel_progression_invalid', 'Channel promotion must move forward (canary -> beta -> stable).', ['status' => 400]);
        }
        if ($target_channel === 'stable' && ! rest_sanitize_boolean($request->get_param('confirm_stable_promotion'))) {
            return new \WP_Error('dbvc_bricks_stable_promotion_confirmation_required', 'Stable promotion requires confirm_stable_promotion=true.', ['status' => 400]);
        }

        $package['channel'] = $target_channel;
        $package['updated_at'] = gmdate('c');
        $package = self::append_timeline_event($package, 'eligible', [
            'promoted_to' => $target_channel,
        ]);
        $persist = self::persist_package($package, [
            'artifact_count' => (int) ($package['artifact_count'] ?? 0),
            'package_bytes' => strlen((string) wp_json_encode($package)),
            'channel' => (string) ($package['channel'] ?? ''),
        ]);
        if (is_wp_error($persist)) {
            return $persist;
        }

        $response = [
            'ok' => true,
            'package_id' => $package_id,
            'channel' => $target_channel,
        ];
        DBVC_Bricks_Idempotency::put('packages_promote', $idempotency_key, $response);
        return rest_ensure_response($response);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_revoke(\WP_REST_Request $request)
    {
        $idempotency_key = DBVC_Bricks_Idempotency::extract_key($request);
        if ($idempotency_key === '') {
            return new \WP_Error('dbvc_bricks_idempotency_required', 'Idempotency-Key is required.', ['status' => 400]);
        }
        $existing = DBVC_Bricks_Idempotency::get('packages_revoke', $idempotency_key);
        if (is_array($existing) && isset($existing['response']) && is_array($existing['response'])) {
            return rest_ensure_response($existing['response']);
        }

        $package_id = sanitize_text_field((string) $request->get_param('package_id'));
        $package = self::get_package($package_id);
        if (! is_array($package)) {
            return new \WP_Error('dbvc_bricks_package_not_found', 'Package not found.', ['status' => 404]);
        }
        if (! rest_sanitize_boolean($request->get_param('confirm_revoke'))) {
            return new \WP_Error('dbvc_bricks_revoke_confirmation_required', 'Revoke requires confirm_revoke=true.', ['status' => 400]);
        }

        $package['status'] = self::STATUS_REVOKED;
        $package['updated_at'] = gmdate('c');
        $package = self::append_timeline_event($package, 'failed', [
            'reason' => 'revoked',
        ]);
        $persist = self::persist_package($package, [
            'artifact_count' => (int) ($package['artifact_count'] ?? 0),
            'package_bytes' => strlen((string) wp_json_encode($package)),
            'channel' => (string) ($package['channel'] ?? ''),
        ]);
        if (is_wp_error($persist)) {
            return $persist;
        }

        $response = [
            'ok' => true,
            'package_id' => $package_id,
            'status' => self::STATUS_REVOKED,
        ];
        DBVC_Bricks_Idempotency::put('packages_revoke', $idempotency_key, $response);
        return rest_ensure_response($response);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_ack(\WP_REST_Request $request)
    {
        $package_id = sanitize_text_field((string) $request->get_param('package_id'));
        $package = self::get_package($package_id);
        if (! is_array($package)) {
            return new \WP_Error('dbvc_bricks_package_not_found', 'Package not found.', ['status' => 404]);
        }
        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }
        $site_uid = sanitize_key((string) ($params['site_uid'] ?? ''));
        $state = sanitize_key((string) ($params['state'] ?? 'received'));
        $receipt_id = sanitize_text_field((string) ($params['receipt_id'] ?? ($package['receipt_id'] ?? '')));
        if (! in_array($state, ['received', 'pulled', 'applied', 'failed', 'skipped'], true)) {
            $state = 'received';
        }

        if (! isset($package['acks']) || ! is_array($package['acks'])) {
            $package['acks'] = [];
        }
        $package['acks'][] = [
            'site_uid' => $site_uid,
            'state' => $state,
            'at' => gmdate('c'),
            'actor_id' => get_current_user_id(),
            'receipt_id' => $receipt_id,
        ];
        $package = self::append_timeline_event($package, $state === 'received' ? 'received' : $state, [
            'site_uid' => $site_uid,
            'receipt_id' => $receipt_id,
        ]);
        if (in_array($state, ['applied', 'failed'], true)) {
            $package = self::update_transport_state(
                $package,
                'apply',
                $state === 'applied',
                $state === 'failed' ? 'apply_failed' : ''
            );
        }
        $package['updated_at'] = gmdate('c');
        $persist = self::persist_package($package, [
            'artifact_count' => (int) ($package['artifact_count'] ?? 0),
            'package_bytes' => strlen((string) wp_json_encode($package)),
            'channel' => (string) ($package['channel'] ?? ''),
        ]);
        if (is_wp_error($persist)) {
            return $persist;
        }

        return rest_ensure_response([
            'ok' => true,
            'package_id' => $package_id,
            'ack_state' => $state,
        ]);
    }

    /**
     * Pull the latest allowed package for current client and run dry-run apply.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_pull_latest(\WP_REST_Request $request)
    {
        $role = DBVC_Bricks_Addon::get_role_mode();
        if ($role !== 'client') {
            return new \WP_Error('dbvc_bricks_pull_latest_role_invalid', 'Pull latest is client-only.', ['status' => 400]);
        }

        $channel = sanitize_key((string) $request->get_param('channel'));
        if ($channel === '') {
            $channel = DBVC_Bricks_Addon::get_setting('dbvc_bricks_channel', 'stable');
            $channel = sanitize_key((string) $channel);
        }
        if (! in_array($channel, ['stable', 'beta', 'canary'], true)) {
            $channel = 'stable';
        }

        $site_uid = sanitize_key((string) DBVC_Bricks_Addon::get_setting('dbvc_bricks_site_uid', ''));
        if ($site_uid === '') {
            $site_uid = 'site_' . get_current_blog_id();
        }

        $source_mode = sanitize_key((string) DBVC_Bricks_Addon::get_setting('dbvc_bricks_source_mode', 'mothership_api'));
        $allow_fallback = DBVC_Bricks_Addon::get_bool_setting('dbvc_bricks_allow_fallback', true);
        $latest = null;
        $pulled_from_remote = false;
        $remote_pull_error = null;
        if ($source_mode === 'mothership_api') {
            $remote_latest = self::fetch_remote_latest_visible_package_for_site($site_uid, $channel);
            if (is_wp_error($remote_latest)) {
                $remote_pull_error = $remote_latest;
            } elseif (is_array($remote_latest)) {
                $latest = $remote_latest;
                $pulled_from_remote = true;
            }
        }
        if (! is_array($latest)) {
            $latest = self::get_latest_visible_package_for_site($site_uid, $channel);
        }
        if (! is_array($latest)) {
            if (is_wp_error($remote_pull_error) && ! $allow_fallback) {
                return $remote_pull_error;
            }
            return new \WP_Error('dbvc_bricks_pull_latest_not_found', 'No eligible package found for this site and channel.', ['status' => 404]);
        }

        $package_id = sanitize_text_field((string) ($latest['package_id'] ?? ''));
        if ($package_id === '') {
            return new \WP_Error('dbvc_bricks_pull_latest_invalid_package', 'Eligible package is missing package_id.', ['status' => 500]);
        }
        $latest = self::annotate_package_with_protected_variants($latest, true);

        $apply = DBVC_Bricks_Apply::apply_package($latest, [], ['dry_run' => true, 'allow_destructive' => false]);
        $stored_package = self::get_package($package_id);
        if (! is_array($stored_package)) {
            $stored_package = $latest;
        } else {
            $stored_package = array_merge($stored_package, $latest);
        }

        if (is_wp_error($apply)) {
            $stored_package = self::append_timeline_event($stored_package, 'failed', [
                'site_uid' => $site_uid,
                'operation' => 'pull_latest',
                'error_code' => (string) $apply->get_error_code(),
            ]);
            $stored_package = self::update_transport_state(
                $stored_package,
                'pull_latest',
                false,
                (string) $apply->get_error_code()
            );
            $stored_package['updated_at'] = gmdate('c');
            $persist = self::persist_package($stored_package, [
                'artifact_count' => (int) ($stored_package['artifact_count'] ?? 0),
                'package_bytes' => strlen((string) wp_json_encode($stored_package)),
                'channel' => (string) ($stored_package['channel'] ?? ''),
            ]);
            if (is_wp_error($persist)) {
                return $persist;
            }
            return $apply;
        }

        $stored_package = self::append_timeline_event($stored_package, 'eligible', [
            'site_uid' => $site_uid,
            'channel' => $channel,
        ]);
        if (! isset($stored_package['acks']) || ! is_array($stored_package['acks'])) {
            $stored_package['acks'] = [];
        }
        $stored_package['acks'][] = [
            'site_uid' => $site_uid,
            'state' => 'pulled',
            'at' => gmdate('c'),
            'actor_id' => get_current_user_id(),
            'receipt_id' => (string) ($stored_package['receipt_id'] ?? ''),
        ];
        $stored_package = self::append_timeline_event($stored_package, 'pulled', [
            'site_uid' => $site_uid,
            'receipt_id' => (string) ($stored_package['receipt_id'] ?? ''),
        ]);
        $stored_package = self::update_transport_state($stored_package, 'pull_latest', true);
        $stored_package['updated_at'] = gmdate('c');
        $persist = self::persist_package($stored_package, [
            'artifact_count' => (int) ($stored_package['artifact_count'] ?? 0),
            'package_bytes' => strlen((string) wp_json_encode($stored_package)),
            'channel' => (string) ($stored_package['channel'] ?? ''),
        ]);
        if (is_wp_error($persist)) {
            return $persist;
        }
        $latest = $stored_package;

        $remote_ack = [
            'ok' => true,
        ];
        if ($pulled_from_remote) {
            $ack_result = self::post_remote_pull_ack($package_id, $site_uid, (string) ($latest['receipt_id'] ?? ''));
            if (is_wp_error($ack_result)) {
                $remote_ack = [
                    'ok' => false,
                    'error_code' => (string) $ack_result->get_error_code(),
                    'message' => (string) $ack_result->get_error_message(),
                ];
            }
        }

        $response = [
            'ok' => true,
            'site_uid' => $site_uid,
            'channel' => $channel,
            'package_id' => $package_id,
            'receipt_id' => (string) ($latest['receipt_id'] ?? ''),
            'manifest' => $latest,
            'dry_run_apply' => $apply,
            'pulled_from_remote' => $pulled_from_remote,
            'remote_ack' => $remote_ack,
        ];
        if (is_wp_error($remote_pull_error)) {
            $response['remote_pull_error'] = [
                'error_code' => (string) $remote_pull_error->get_error_code(),
                'message' => (string) $remote_pull_error->get_error_message(),
            ];
        }

        return rest_ensure_response($response);
    }

    /**
     * Publish package to remote mothership using configured auth.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_publish_remote(\WP_REST_Request $request)
    {
        $idempotency_key = DBVC_Bricks_Idempotency::extract_key($request);
        if ($idempotency_key === '') {
            return new \WP_Error('dbvc_bricks_idempotency_required', 'Idempotency-Key is required.', ['status' => 400]);
        }
        $existing = DBVC_Bricks_Idempotency::get('packages_publish_remote', $idempotency_key);
        if (is_array($existing) && isset($existing['response']) && is_array($existing['response'])) {
            return rest_ensure_response($existing['response']);
        }

        $site_uid = DBVC_Bricks_Addon::get_setting('dbvc_bricks_site_uid', '');
        if ($site_uid === '') {
            $site_uid = 'site_' . get_current_blog_id();
        }
        $correlation_id = sanitize_text_field((string) $request->get_header('X-DBVC-Correlation-ID'));
        $publish_run_base = [
            'run_id' => 'pubrun_' . substr(wp_hash($idempotency_key . '|' . microtime(true)), 0, 12),
            'idempotency_key' => $idempotency_key,
            'correlation_id' => $correlation_id,
            'created_at' => gmdate('c'),
            'actor_id' => get_current_user_id(),
            'site_uid' => sanitize_key($site_uid),
            'package_id' => '',
            'channel' => '',
            'artifact_count' => 0,
            'package_bytes' => 0,
            'targeting' => ['mode' => 'all', 'site_uids' => []],
            'remote_url' => '',
            'result' => 'failed',
            'http_status' => 0,
            'error_code' => '',
            'message' => '',
            'receipt_id' => '',
            'response' => [],
            'remote_package_visibility' => [],
            'preflight' => [],
            'channel_force_audit' => [],
        ];
        $record_publish_failure = static function ($error_code, $message, array $context = []) use (&$publish_run_base) {
            self::record_publish_run(array_merge($publish_run_base, $context, [
                'result' => 'failed',
                'error_code' => sanitize_key((string) $error_code),
                'message' => (string) $message,
            ]));
        };

        $role = DBVC_Bricks_Addon::get_role_mode();
        if ($role !== 'client') {
            $record_publish_failure('dbvc_bricks_publish_remote_role_invalid', 'Remote publish is client-only.');
            return new \WP_Error('dbvc_bricks_publish_remote_role_invalid', 'Remote publish is client-only.', ['status' => 400]);
        }
        $auth_method = DBVC_Bricks_Addon::get_setting('dbvc_bricks_auth_method', 'hmac');
        if ($auth_method !== 'wp_app_password') {
            $record_publish_failure('dbvc_bricks_publish_remote_auth_invalid', 'Remote publish currently requires wp_app_password auth method.');
            return new \WP_Error('dbvc_bricks_publish_remote_auth_invalid', 'Remote publish currently requires wp_app_password auth method.', ['status' => 400]);
        }

        $mothership_url = untrailingslashit(DBVC_Bricks_Addon::get_setting('dbvc_bricks_mothership_url', ''));
        if ($mothership_url === '') {
            $record_publish_failure('dbvc_bricks_publish_remote_url_required', 'Mothership Base URL is required.');
            return new \WP_Error('dbvc_bricks_publish_remote_url_required', 'Mothership Base URL is required.', ['status' => 400]);
        }
        $username = DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_key_id', '');
        $app_password = DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_secret', '');
        if ($username === '' || $app_password === '') {
            $record_publish_failure('dbvc_bricks_publish_remote_credentials_required', 'API Key ID and API Secret are required for wp_app_password.');
            return new \WP_Error('dbvc_bricks_publish_remote_credentials_required', 'API Key ID and API Secret are required for wp_app_password.', ['status' => 400]);
        }

        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }
        $package_payload = isset($params['package']) && is_array($params['package']) ? $params['package'] : [];
        if (empty($package_payload) && isset($params['package_id'])) {
            $from_store = self::get_package((string) $params['package_id']);
            if (is_array($from_store)) {
                $package_payload = $from_store;
            }
        }
        if (empty($package_payload)) {
            $record_publish_failure('dbvc_bricks_publish_remote_package_required', 'package payload or package_id is required.');
            return new \WP_Error('dbvc_bricks_publish_remote_package_required', 'package payload or package_id is required.', ['status' => 400]);
        }
        $targeting_payload = isset($params['targeting']) && is_array($params['targeting']) ? $params['targeting'] : ['mode' => 'all', 'site_uids' => []];
        $targeting = self::normalize_targeting($targeting_payload);
        if (is_wp_error($targeting)) {
            $record_publish_failure((string) $targeting->get_error_code(), (string) $targeting->get_error_message(), [
                'targeting' => $targeting_payload,
            ]);
            return $targeting;
        }

        $package = self::normalize_package($package_payload);
        $package = self::annotate_package_with_protected_variants($package, true);
        $package['source_site']['site_uid'] = sanitize_key($site_uid);
        $package['source_site']['base_url'] = untrailingslashit(home_url('/'));
        $package_bytes = strlen((string) wp_json_encode($package));
        $publish_run_base['package_id'] = (string) ($package['package_id'] ?? '');
        $publish_run_base['channel'] = (string) ($package['channel'] ?? '');
        $publish_run_base['artifact_count'] = (int) ($package['artifact_count'] ?? 0);
        $publish_run_base['package_bytes'] = $package_bytes;
        $publish_run_base['targeting'] = $targeting;
        $forced_channel = DBVC_Bricks_Addon::get_enum_setting(
            'dbvc_bricks_client_force_channel',
            ['none', 'canary', 'beta', 'stable'],
            'none'
        );
        $forced_audit = [
            'channel_forced' => false,
            'forced_from' => '',
            'forced_to' => '',
            'forced_by' => 0,
            'forced_at' => '',
        ];
        if ($forced_channel !== 'none') {
            if (
                $forced_channel === 'stable'
                && DBVC_Bricks_Addon::get_bool_setting('dbvc_bricks_force_stable_confirm', true)
                && ! rest_sanitize_boolean($request->get_param('confirm_force_stable'))
            ) {
                $record_publish_failure(
                    'dbvc_bricks_force_stable_confirmation_required',
                    'Stable force-channel requires confirm_force_stable=true.',
                    [
                        'package_id' => (string) ($package['package_id'] ?? ''),
                        'channel' => (string) ($package['channel'] ?? ''),
                        'artifact_count' => (int) ($package['artifact_count'] ?? 0),
                        'package_bytes' => $package_bytes,
                        'targeting' => $targeting,
                    ]
                );
                return new \WP_Error(
                    'dbvc_bricks_force_stable_confirmation_required',
                    'Stable force-channel requires confirm_force_stable=true.',
                    ['status' => 400]
                );
            }
            $forced_audit = [
                'channel_forced' => true,
                'forced_from' => (string) ($package['channel'] ?? ''),
                'forced_to' => $forced_channel,
                'forced_by' => get_current_user_id(),
                'forced_at' => gmdate('c'),
            ];
            $package['channel'] = $forced_channel;
            $package['channel_force_audit'] = $forced_audit;
        }
        $publish_run_base['channel'] = (string) ($package['channel'] ?? '');
        $publish_run_base['package_bytes'] = strlen((string) wp_json_encode($package));
        $publish_run_base['channel_force_audit'] = $forced_audit;

        $preflight = self::build_publish_preflight($package);
        $publish_run_base['preflight'] = [
            'ok' => empty($preflight['errors']),
            'errors' => isset($preflight['errors']) && is_array($preflight['errors']) ? array_values($preflight['errors']) : [],
            'warnings' => isset($preflight['warnings']) && is_array($preflight['warnings']) ? array_values($preflight['warnings']) : [],
        ];
        $is_dry_run = ! empty($params['dry_run']) || ! empty($params['preflight_only']);
        if (! empty($preflight['errors'])) {
            $message = 'Publish preflight failed.';
            $first_error = isset($preflight['errors'][0]) ? sanitize_text_field((string) $preflight['errors'][0]) : '';
            if ($first_error !== '') {
                $message .= ' ' . $first_error;
            }
            $package_id = (string) ($package['package_id'] ?? '');
            if ($package_id !== '') {
                $package = self::append_timeline_event($package, 'failed', [
                    'operation' => 'publish_remote',
                    'error_code' => $first_error,
                ]);
                $package = self::update_transport_state(
                    $package,
                    'publish_remote',
                    false,
                    $first_error !== '' ? $first_error : 'dbvc_bricks_publish_remote_preflight_failed'
                );
                $package['updated_at'] = gmdate('c');
                $persist = self::persist_package($package, [
                    'artifact_count' => (int) ($package['artifact_count'] ?? 0),
                    'package_bytes' => strlen((string) wp_json_encode($package)),
                    'channel' => (string) ($package['channel'] ?? ''),
                ]);
                if (is_wp_error($persist)) {
                    return $persist;
                }
            }
            $record_publish_failure('dbvc_bricks_publish_remote_preflight_failed', $message, [
                'package_id' => (string) ($package['package_id'] ?? ''),
                'channel' => (string) ($package['channel'] ?? ''),
                'artifact_count' => (int) ($package['artifact_count'] ?? 0),
                'package_bytes' => strlen((string) wp_json_encode($package)),
                'targeting' => $targeting,
                'preflight' => $publish_run_base['preflight'],
                'channel_force_audit' => $forced_audit,
            ]);
            return new \WP_Error('dbvc_bricks_publish_remote_preflight_failed', $message, [
                'status' => 400,
                'preflight' => $preflight,
                'remediation' => self::get_remediation_hint($first_error),
            ]);
        }
        if ($is_dry_run) {
            return rest_ensure_response([
                'ok' => true,
                'dry_run' => true,
                'preflight' => $preflight,
                'targeting' => $targeting,
                'channel_force_audit' => $forced_audit,
            ]);
        }

        $remote_url = $mothership_url . '/wp-json/dbvc/v1/bricks/packages';
        $publish_run_base['remote_url'] = $remote_url;
        $basic = base64_encode($username . ':' . $app_password);
        $response = wp_remote_post($remote_url, [
            'timeout' => max(5, DBVC_Bricks_Addon::get_int_setting('dbvc_bricks_http_timeout', 30)),
            'sslverify' => DBVC_Bricks_Addon::get_bool_setting('dbvc_bricks_tls_verify', true),
            'headers' => [
                'Authorization' => 'Basic ' . $basic,
                'Content-Type' => 'application/json',
                'Idempotency-Key' => $idempotency_key,
                'X-DBVC-Correlation-ID' => 'dbvc-remote-' . substr(wp_hash(gmdate('c')), 0, 10),
            ],
            'body' => wp_json_encode([
                'package' => $package,
                'targeting' => $targeting,
            ]),
        ]);

        if (is_wp_error($response)) {
            $package_id = (string) ($package['package_id'] ?? '');
            if ($package_id !== '') {
                $package = self::append_timeline_event($package, 'failed', [
                    'operation' => 'publish_remote',
                    'error_code' => 'dbvc_bricks_publish_remote_http_error',
                ]);
                $package = self::update_transport_state($package, 'publish_remote', false, 'dbvc_bricks_publish_remote_http_error');
                $package['updated_at'] = gmdate('c');
                $persist = self::persist_package($package, [
                    'artifact_count' => (int) ($package['artifact_count'] ?? 0),
                    'package_bytes' => strlen((string) wp_json_encode($package)),
                    'channel' => (string) ($package['channel'] ?? ''),
                ]);
                if (is_wp_error($persist)) {
                    return $persist;
                }
            }
            $record_publish_failure('dbvc_bricks_publish_remote_http_error', (string) $response->get_error_message(), [
                'package_id' => (string) ($package['package_id'] ?? ''),
                'channel' => (string) ($package['channel'] ?? ''),
                'artifact_count' => (int) ($package['artifact_count'] ?? 0),
                'package_bytes' => strlen((string) wp_json_encode($package)),
                'targeting' => $targeting,
                'remote_url' => $remote_url,
                'preflight' => $publish_run_base['preflight'],
                'channel_force_audit' => $forced_audit,
            ]);
            return new \WP_Error('dbvc_bricks_publish_remote_http_error', $response->get_error_message(), ['status' => 502]);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode((string) $body, true);
        if (! is_array($data)) {
            $data = ['raw' => (string) $body];
        }
        if ($status < 200 || $status >= 300) {
            $package_id = (string) ($package['package_id'] ?? '');
            if ($package_id !== '') {
                $package = self::append_timeline_event($package, 'failed', [
                    'operation' => 'publish_remote',
                    'status' => $status,
                ]);
                $package = self::update_transport_state($package, 'publish_remote', false, 'dbvc_bricks_publish_remote_failed');
                $package['updated_at'] = gmdate('c');
                $persist = self::persist_package($package, [
                    'artifact_count' => (int) ($package['artifact_count'] ?? 0),
                    'package_bytes' => strlen((string) wp_json_encode($package)),
                    'channel' => (string) ($package['channel'] ?? ''),
                ]);
                if (is_wp_error($persist)) {
                    return $persist;
                }
            }
            $record_publish_failure('dbvc_bricks_publish_remote_failed', (string) ($data['message'] ?? 'Remote publish failed.'), [
                'package_id' => (string) ($package['package_id'] ?? ''),
                'channel' => (string) ($package['channel'] ?? ''),
                'artifact_count' => (int) ($package['artifact_count'] ?? 0),
                'package_bytes' => strlen((string) wp_json_encode($package)),
                'targeting' => $targeting,
                'remote_url' => $remote_url,
                'http_status' => $status,
                'response' => $data,
                'preflight' => $publish_run_base['preflight'],
                'channel_force_audit' => $forced_audit,
            ]);
            return new \WP_Error('dbvc_bricks_publish_remote_failed', (string) ($data['message'] ?? 'Remote publish failed.'), [
                'status' => $status,
                'response' => $data,
                'remediation' => self::get_remediation_hint('dbvc_bricks_publish_remote_failed'),
            ]);
        }

        $package_id = (string) ($package['package_id'] ?? '');
        if ($package_id !== '') {
            $package = self::append_timeline_event($package, 'sent', [
                'operation' => 'publish_remote',
                'target_mode' => (string) ($targeting['mode'] ?? 'all'),
                'response_status' => $status,
            ]);
            $package = self::update_transport_state($package, 'publish_remote', true);
            if (! empty($forced_audit['channel_forced'])) {
                $package['channel_force_audit'] = $forced_audit;
                $package['channel'] = (string) $forced_audit['forced_to'];
            }
            $package['updated_at'] = gmdate('c');
            $persist = self::persist_package($package, [
                'artifact_count' => (int) ($package['artifact_count'] ?? 0),
                'package_bytes' => strlen((string) wp_json_encode($package)),
                'channel' => (string) ($package['channel'] ?? ''),
            ]);
            if (is_wp_error($persist)) {
                return $persist;
            }
        }

        $result = [
            'ok' => true,
            'remote_url' => $remote_url,
            'status' => $status,
            'response' => $data,
            'package_id' => (string) ($package['package_id'] ?? ''),
            'channel_force_audit' => $forced_audit,
        ];
        $remote_package_id = sanitize_text_field((string) ($data['package_id'] ?? ($package['package_id'] ?? '')));
        $result['remote_package_visibility'] = self::verify_remote_package_visibility(
            $mothership_url,
            $username,
            $app_password,
            $remote_package_id
        );
        self::record_publish_run(array_merge($publish_run_base, [
            'result' => 'success',
            'http_status' => $status,
            'message' => (string) ($data['message'] ?? 'Package published to mothership.'),
            'receipt_id' => sanitize_text_field((string) ($data['receipt_id'] ?? '')),
            'response' => $data,
            'remote_package_visibility' => $result['remote_package_visibility'],
            'preflight' => $publish_run_base['preflight'],
            'channel_force_audit' => $forced_audit,
        ]));
        DBVC_Bricks_Idempotency::put('packages_publish_remote', $idempotency_key, $result);
        return rest_ensure_response($result);
    }

    /**
     * Test remote mothership API connectivity using configured credentials.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_test_remote_connection()
    {
        $role = DBVC_Bricks_Addon::get_role_mode();
        if ($role !== 'client') {
            return new \WP_Error('dbvc_bricks_connection_test_role_invalid', 'Connection test is client-only.', ['status' => 400]);
        }

        $auth_method = DBVC_Bricks_Addon::get_setting('dbvc_bricks_auth_method', 'hmac');
        if ($auth_method !== 'wp_app_password') {
            return new \WP_Error('dbvc_bricks_connection_test_auth_invalid', 'Connection test currently requires wp_app_password auth method.', ['status' => 400]);
        }

        $mothership_url = untrailingslashit(DBVC_Bricks_Addon::get_setting('dbvc_bricks_mothership_url', ''));
        $username = DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_key_id', '');
        $app_password = DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_secret', '');
        if ($mothership_url === '' || $username === '' || $app_password === '') {
            return new \WP_Error('dbvc_bricks_connection_test_config_required', 'Mothership URL, API Key ID, and API Secret are required.', ['status' => 400]);
        }

        $probe_url = $mothership_url . '/wp-json/dbvc/v1/bricks/status';
        $basic = base64_encode($username . ':' . $app_password);
        $response = wp_remote_get($probe_url, [
            'timeout' => max(5, DBVC_Bricks_Addon::get_int_setting('dbvc_bricks_http_timeout', 30)),
            'sslverify' => DBVC_Bricks_Addon::get_bool_setting('dbvc_bricks_tls_verify', true),
            'headers' => [
                'Authorization' => 'Basic ' . $basic,
                'Accept' => 'application/json',
                'X-DBVC-Correlation-ID' => 'dbvc-conn-' . substr(wp_hash(gmdate('c')), 0, 10),
            ],
        ]);
        if (is_wp_error($response)) {
            return new \WP_Error('dbvc_bricks_connection_test_http_error', $response->get_error_message(), ['status' => 502]);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode((string) $body, true);
        if (! is_array($data)) {
            $data = ['raw' => (string) $body];
        }
        if ($status < 200 || $status >= 300) {
            $remote_message = '';
            if (isset($data['message']) && is_string($data['message'])) {
                $remote_message = sanitize_text_field($data['message']);
            } elseif (isset($data['code']) && is_string($data['code'])) {
                $remote_message = sanitize_text_field($data['code']);
            }
            $message = 'Mothership connection failed (HTTP ' . $status . ').';
            if ($remote_message !== '') {
                $message .= ' ' . $remote_message;
            }
            return new \WP_Error('dbvc_bricks_connection_test_failed', $message, [
                'status' => $status > 0 ? $status : 502,
                'response' => $data,
                'probe_url' => $probe_url,
            ]);
        }

        return rest_ensure_response([
            'ok' => true,
            'probe_url' => $probe_url,
            'status' => $status,
            'response' => $data,
        ]);
    }

    /**
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public static function get_delivery_diagnostics($limit = 20)
    {
        $limit = max(1, (int) $limit);
        $items = array_values(self::get_packages());
        if (empty($items)) {
            return [];
        }
        usort($items, static function ($a, $b) {
            return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        });
        $items = array_slice($items, 0, $limit);

        $rows = [];
        foreach ($items as $package) {
            $acks = isset($package['acks']) && is_array($package['acks']) ? $package['acks'] : [];
            $latest_by_site = [];
            foreach ($acks as $ack) {
                if (! is_array($ack)) {
                    continue;
                }
                $uid = sanitize_key((string) ($ack['site_uid'] ?? ''));
                if ($uid === '') {
                    continue;
                }
                $at = sanitize_text_field((string) ($ack['at'] ?? ''));
                if (! isset($latest_by_site[$uid]) || strcmp($at, (string) ($latest_by_site[$uid]['at'] ?? '')) >= 0) {
                    $latest_by_site[$uid] = [
                        'state' => sanitize_key((string) ($ack['state'] ?? 'received')),
                        'at' => $at,
                    ];
                }
            }

            $state_counts = [];
            $last_ack_at = '';
            foreach ($latest_by_site as $ack) {
                $state = (string) ($ack['state'] ?? 'received');
                $state_counts[$state] = (int) ($state_counts[$state] ?? 0) + 1;
                $ack_at = (string) ($ack['at'] ?? '');
                if ($ack_at !== '' && ($last_ack_at === '' || strcmp($ack_at, $last_ack_at) > 0)) {
                    $last_ack_at = $ack_at;
                }
            }

            $targeting = isset($package['targeting']) && is_array($package['targeting']) ? $package['targeting'] : ['mode' => 'all', 'site_uids' => []];
            $target_mode = (string) ($targeting['mode'] ?? 'all');
            $target_sites = isset($targeting['site_uids']) && is_array($targeting['site_uids']) ? array_values($targeting['site_uids']) : [];

            $rows[] = [
                'package_id' => (string) ($package['package_id'] ?? ''),
                'version' => (string) ($package['version'] ?? ''),
                'channel' => (string) ($package['channel'] ?? ''),
                'status' => (string) ($package['status'] ?? ''),
                'receipt_id' => (string) ($package['receipt_id'] ?? ''),
                'targeting' => [
                    'mode' => $target_mode,
                    'site_uids' => $target_mode === 'selected' ? $target_sites : [],
                ],
                'ack_summary' => [
                    'site_count' => count($latest_by_site),
                    'states' => $state_counts,
                    'last_ack_at' => $last_ack_at,
                ],
                'delivery_transport' => isset($package['delivery_transport']) && is_array($package['delivery_transport'])
                    ? $package['delivery_transport']
                    : [],
                'delivery_timeline' => isset($package['delivery_timeline']) && is_array($package['delivery_timeline'])
                    ? array_slice(array_values($package['delivery_timeline']), -25)
                    : [],
                'updated_at' => (string) ($package['updated_at'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Collect local Bricks artifacts for bootstrap package creation.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function collect_local_artifacts()
    {
        $artifacts = [];

        $entity_ids = get_posts([
            'post_type' => 'bricks_template',
            'post_status' => ['publish', 'draft', 'private', 'pending', 'future'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);
        if (! is_array($entity_ids)) {
            $entity_ids = [];
        }
        foreach ($entity_ids as $entity_id) {
            $entity_id = absint($entity_id);
            if ($entity_id <= 0) {
                continue;
            }
            $payload = DBVC_Bricks_Addon::read_entity_payload_for_diff($entity_id);
            if (! is_array($payload)) {
                continue;
            }
            $canonical = DBVC_Bricks_Artifacts::canonicalize('bricks_template', $payload);
            $artifacts[] = [
                'artifact_uid' => 'entity:bricks_template:' . $entity_id,
                'artifact_type' => 'bricks_template',
                'entity_id' => $entity_id,
                'hash' => DBVC_Bricks_Artifacts::fingerprint($canonical),
                'payload' => $payload,
            ];
        }

        $registry = DBVC_Bricks_Artifacts::get_registry();
        foreach ($registry as $artifact_type => $item) {
            if (($item['storage'] ?? '') !== 'option' || ($item['include_mode'] ?? '') !== 'include') {
                continue;
            }
            $option_key = (string) ($artifact_type);
            $payload = get_option($option_key, null);
            if ($payload === null) {
                continue;
            }
            $canonical = DBVC_Bricks_Artifacts::canonicalize($artifact_type, $payload);
            $artifacts[] = [
                'artifact_uid' => 'option:' . $option_key,
                'artifact_type' => $artifact_type,
                'hash' => DBVC_Bricks_Artifacts::fingerprint($canonical),
                'payload' => $payload,
            ];
        }

        return $artifacts;
    }

    /**
     * @return int
     */
    public static function get_remote_package_max_bytes()
    {
        $configured = (int) apply_filters('dbvc_bricks_remote_package_max_bytes', self::REMOTE_PACKAGE_MAX_BYTES);
        return max(262144, $configured);
    }

    /**
     * @param array<string, mixed> $package
     * @param bool $include_lookup
     * @return array<string, mixed>
     */
    private static function annotate_package_with_protected_variants(array $package, $include_lookup)
    {
        $artifact_uids = [];
        $artifacts = isset($package['artifacts']) && is_array($package['artifacts']) ? $package['artifacts'] : [];
        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }
            $artifact_uid = sanitize_text_field((string) ($artifact['artifact_uid'] ?? ''));
            if ($artifact_uid === '') {
                continue;
            }
            $artifact_uids[] = $artifact_uid;
        }
        $annotations = self::build_protected_variant_annotations($artifact_uids);
        $annotated_artifacts = [];
        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                $annotated_artifacts[] = $artifact;
                continue;
            }
            $artifact_uid = sanitize_text_field((string) ($artifact['artifact_uid'] ?? ''));
            $artifact['protected_variant'] = self::resolve_protected_variant_annotation($artifact_uid, $annotations);
            $annotated_artifacts[] = $artifact;
        }
        $package['artifacts'] = $annotated_artifacts;
        $package['protected_variant_summary'] = self::build_protected_variant_summary($annotations);
        if ($include_lookup) {
            $lookup = isset($annotations['lookup']) && is_array($annotations['lookup']) ? $annotations['lookup'] : [];
            ksort($lookup, SORT_STRING);
            $package['protected_variant_lookup'] = $lookup;
        }
        return $package;
    }

    /**
     * @param array<int, string> $artifact_uids
     * @return array<string, mixed>
     */
    private static function build_protected_variant_annotations(array $artifact_uids)
    {
        if (! class_exists('DBVC_Bricks_Protected_Variants') || ! method_exists('DBVC_Bricks_Protected_Variants', 'build_payload_annotations')) {
            return [];
        }
        $annotations = DBVC_Bricks_Protected_Variants::build_payload_annotations($artifact_uids);
        return is_array($annotations) ? $annotations : [];
    }

    /**
     * @param string $artifact_uid
     * @param array<string, mixed> $annotations
     * @return array<string, mixed>
     */
    private static function resolve_protected_variant_annotation($artifact_uid, array $annotations)
    {
        if (class_exists('DBVC_Bricks_Protected_Variants') && method_exists('DBVC_Bricks_Protected_Variants', 'get_artifact_annotation')) {
            $annotation = DBVC_Bricks_Protected_Variants::get_artifact_annotation($artifact_uid, $annotations);
            if (is_array($annotation)) {
                return $annotation;
            }
        }
        $artifact_uid = sanitize_text_field((string) $artifact_uid);
        return [
            'is_protected' => false,
            'artifact_uid' => $artifact_uid,
            'variant_count' => 0,
            'variant_ids' => [],
            'scopes' => [],
            'latest_updated_at' => '',
            'latest_reason' => '',
        ];
    }

    /**
     * @param array<string, mixed> $annotations
     * @return array<string, mixed>
     */
    private static function build_protected_variant_summary(array $annotations)
    {
        $by_artifact_type = [];
        foreach ((array) ($annotations['by_artifact_type'] ?? []) as $artifact_type => $count) {
            $artifact_type = sanitize_key((string) $artifact_type);
            if ($artifact_type === '') {
                continue;
            }
            $by_artifact_type[$artifact_type] = max(0, (int) $count);
        }
        $by_scope = [];
        foreach ((array) ($annotations['by_scope'] ?? []) as $scope => $count) {
            $scope = sanitize_key((string) $scope);
            if ($scope === '') {
                continue;
            }
            $by_scope[$scope] = max(0, (int) $count);
        }
        ksort($by_artifact_type, SORT_STRING);
        ksort($by_scope, SORT_STRING);

        return [
            'variant_records' => max(0, (int) ($annotations['variant_records'] ?? 0)),
            'unique_artifact_uids' => max(0, (int) ($annotations['unique_artifact_uids'] ?? 0)),
            'by_artifact_type' => $by_artifact_type,
            'by_scope' => $by_scope,
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private static function sanitize_protected_variant_summary(array $summary)
    {
        $by_artifact_type = [];
        foreach ((array) ($summary['by_artifact_type'] ?? []) as $artifact_type => $count) {
            $artifact_type = sanitize_key((string) $artifact_type);
            if ($artifact_type === '') {
                continue;
            }
            $by_artifact_type[$artifact_type] = max(0, (int) $count);
        }
        $by_scope = [];
        foreach ((array) ($summary['by_scope'] ?? []) as $scope => $count) {
            $scope = sanitize_key((string) $scope);
            if ($scope === '') {
                continue;
            }
            $by_scope[$scope] = max(0, (int) $count);
        }
        ksort($by_artifact_type, SORT_STRING);
        ksort($by_scope, SORT_STRING);

        return [
            'variant_records' => max(0, (int) ($summary['variant_records'] ?? 0)),
            'unique_artifact_uids' => max(0, (int) ($summary['unique_artifact_uids'] ?? 0)),
            'by_artifact_type' => $by_artifact_type,
            'by_scope' => $by_scope,
        ];
    }

    /**
     * @param array<string, mixed> $lookup
     * @return array<string, array<string, mixed>>
     */
    private static function sanitize_protected_variant_lookup(array $lookup)
    {
        $clean = [];
        foreach ($lookup as $artifact_uid => $annotation) {
            if (! is_array($annotation)) {
                continue;
            }
            $artifact_uid = sanitize_text_field((string) $artifact_uid);
            if ($artifact_uid === '') {
                $artifact_uid = sanitize_text_field((string) ($annotation['artifact_uid'] ?? ''));
            }
            if ($artifact_uid === '') {
                continue;
            }
            $variant_ids = [];
            foreach ((array) ($annotation['variant_ids'] ?? []) as $variant_id) {
                $variant_id = sanitize_key((string) $variant_id);
                if ($variant_id !== '' && ! in_array($variant_id, $variant_ids, true)) {
                    $variant_ids[] = $variant_id;
                }
            }
            $scopes = [];
            foreach ((array) ($annotation['scopes'] ?? []) as $scope) {
                $scope = sanitize_key((string) $scope);
                if ($scope !== '' && ! in_array($scope, $scopes, true)) {
                    $scopes[] = $scope;
                }
            }
            sort($variant_ids, SORT_STRING);
            sort($scopes, SORT_STRING);
            $clean[$artifact_uid] = [
                'is_protected' => rest_sanitize_boolean($annotation['is_protected'] ?? true),
                'artifact_uid' => $artifact_uid,
                'variant_count' => max(0, (int) ($annotation['variant_count'] ?? count($variant_ids))),
                'variant_ids' => $variant_ids,
                'scopes' => $scopes,
                'latest_updated_at' => self::sanitize_timestamp((string) ($annotation['latest_updated_at'] ?? '')),
                'latest_reason' => sanitize_textarea_field((string) ($annotation['latest_reason'] ?? '')),
            ];
        }
        ksort($clean, SORT_STRING);
        return $clean;
    }

    /**
     * @param string $value
     * @return string
     */
    private static function sanitize_timestamp($value)
    {
        $value = sanitize_text_field((string) $value);
        if ($value === '') {
            return '';
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return '';
        }
        return gmdate('c', $timestamp);
    }
}
