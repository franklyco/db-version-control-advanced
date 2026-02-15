<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Packages
{
    public const OPTION_PACKAGES = 'dbvc_bricks_packages';
    public const REMOTE_PACKAGE_MAX_BYTES = 5242880;
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PUBLISHED = 'PUBLISHED';
    public const STATUS_SUPERSEDED = 'SUPERSEDED';
    public const STATUS_REVOKED = 'REVOKED';
    public const RETRY_BASE_SECONDS = 300;
    public const RETRY_MAX_ATTEMPTS = 5;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_packages()
    {
        $packages = get_option(self::OPTION_PACKAGES, []);
        return is_array($packages) ? $packages : [];
    }

    /**
     * @param string $package_id
     * @return array<string, mixed>|null
     */
    public static function get_package($package_id)
    {
        $packages = self::get_packages();
        return isset($packages[$package_id]) && is_array($packages[$package_id])
            ? $packages[$package_id]
            : null;
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
        return array_slice($items, 0, $limit);
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
        return is_array($eligible[0]) ? $eligible[0] : null;
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
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_get(\WP_REST_Request $request)
    {
        $package_id = sanitize_text_field((string) $request->get_param('package_id'));
        $package = self::get_package($package_id);
        if (! $package) {
            return new \WP_Error('dbvc_bricks_package_not_found', 'Package not found.', ['status' => 404]);
        }

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

        $packages = self::get_packages();
        $packages[(string) $package['package_id']] = $package;
        update_option(self::OPTION_PACKAGES, $packages);

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

        $packages = self::get_packages();
        $packages[(string) $package['package_id']] = $package;
        update_option(self::OPTION_PACKAGES, $packages);

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
        $packages = self::get_packages();
        if (! isset($packages[$package_id]) || ! is_array($packages[$package_id])) {
            return new \WP_Error('dbvc_bricks_package_not_found', 'Package not found.', ['status' => 404]);
        }
        $target_channel = sanitize_key((string) $request->get_param('channel'));
        if (! in_array($target_channel, ['canary', 'beta', 'stable'], true)) {
            return new \WP_Error('dbvc_bricks_channel_invalid', 'Invalid channel.', ['status' => 400]);
        }
        $current_channel = sanitize_key((string) ($packages[$package_id]['channel'] ?? ''));
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

        $packages[$package_id]['channel'] = $target_channel;
        $packages[$package_id]['updated_at'] = gmdate('c');
        $packages[$package_id] = self::append_timeline_event($packages[$package_id], 'eligible', [
            'promoted_to' => $target_channel,
        ]);
        update_option(self::OPTION_PACKAGES, $packages);

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
        $packages = self::get_packages();
        if (! isset($packages[$package_id]) || ! is_array($packages[$package_id])) {
            return new \WP_Error('dbvc_bricks_package_not_found', 'Package not found.', ['status' => 404]);
        }
        if (! rest_sanitize_boolean($request->get_param('confirm_revoke'))) {
            return new \WP_Error('dbvc_bricks_revoke_confirmation_required', 'Revoke requires confirm_revoke=true.', ['status' => 400]);
        }

        $packages[$package_id]['status'] = self::STATUS_REVOKED;
        $packages[$package_id]['updated_at'] = gmdate('c');
        $packages[$package_id] = self::append_timeline_event($packages[$package_id], 'failed', [
            'reason' => 'revoked',
        ]);
        update_option(self::OPTION_PACKAGES, $packages);

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
        $packages = self::get_packages();
        if (! isset($packages[$package_id]) || ! is_array($packages[$package_id])) {
            return new \WP_Error('dbvc_bricks_package_not_found', 'Package not found.', ['status' => 404]);
        }
        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }
        $site_uid = sanitize_key((string) ($params['site_uid'] ?? ''));
        $state = sanitize_key((string) ($params['state'] ?? 'received'));
        $receipt_id = sanitize_text_field((string) ($params['receipt_id'] ?? ($packages[$package_id]['receipt_id'] ?? '')));
        if (! in_array($state, ['received', 'pulled', 'applied', 'failed', 'skipped'], true)) {
            $state = 'received';
        }

        if (! isset($packages[$package_id]['acks']) || ! is_array($packages[$package_id]['acks'])) {
            $packages[$package_id]['acks'] = [];
        }
        $packages[$package_id]['acks'][] = [
            'site_uid' => $site_uid,
            'state' => $state,
            'at' => gmdate('c'),
            'actor_id' => get_current_user_id(),
            'receipt_id' => $receipt_id,
        ];
        $packages[$package_id] = self::append_timeline_event($packages[$package_id], $state === 'received' ? 'received' : $state, [
            'site_uid' => $site_uid,
            'receipt_id' => $receipt_id,
        ]);
        if (in_array($state, ['applied', 'failed'], true)) {
            $packages[$package_id] = self::update_transport_state(
                $packages[$package_id],
                'apply',
                $state === 'applied',
                $state === 'failed' ? 'apply_failed' : ''
            );
        }
        $packages[$package_id]['updated_at'] = gmdate('c');
        update_option(self::OPTION_PACKAGES, $packages);

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

        $apply = DBVC_Bricks_Apply::apply_package($latest, [], ['dry_run' => true, 'allow_destructive' => false]);
        $packages = self::get_packages();
        if (! isset($packages[$package_id]) || ! is_array($packages[$package_id])) {
            $packages[$package_id] = $latest;
        } else {
            $packages[$package_id] = array_merge($packages[$package_id], $latest);
        }

        if (is_wp_error($apply)) {
            if (isset($packages[$package_id]) && is_array($packages[$package_id])) {
                $packages[$package_id] = self::append_timeline_event($packages[$package_id], 'failed', [
                    'site_uid' => $site_uid,
                    'operation' => 'pull_latest',
                    'error_code' => (string) $apply->get_error_code(),
                ]);
                $packages[$package_id] = self::update_transport_state(
                    $packages[$package_id],
                    'pull_latest',
                    false,
                    (string) $apply->get_error_code()
                );
                $packages[$package_id]['updated_at'] = gmdate('c');
                update_option(self::OPTION_PACKAGES, $packages);
            }
            return $apply;
        }

        if (isset($packages[$package_id]) && is_array($packages[$package_id])) {
            $packages[$package_id] = self::append_timeline_event($packages[$package_id], 'eligible', [
                'site_uid' => $site_uid,
                'channel' => $channel,
            ]);
            if (! isset($packages[$package_id]['acks']) || ! is_array($packages[$package_id]['acks'])) {
                $packages[$package_id]['acks'] = [];
            }
            $packages[$package_id]['acks'][] = [
                'site_uid' => $site_uid,
                'state' => 'pulled',
                'at' => gmdate('c'),
                'actor_id' => get_current_user_id(),
                'receipt_id' => (string) ($packages[$package_id]['receipt_id'] ?? ''),
            ];
            $packages[$package_id] = self::append_timeline_event($packages[$package_id], 'pulled', [
                'site_uid' => $site_uid,
                'receipt_id' => (string) ($packages[$package_id]['receipt_id'] ?? ''),
            ]);
            $packages[$package_id] = self::update_transport_state($packages[$package_id], 'pull_latest', true);
            $packages[$package_id]['updated_at'] = gmdate('c');
            update_option(self::OPTION_PACKAGES, $packages);
            $latest = $packages[$package_id];
        }

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

        $role = DBVC_Bricks_Addon::get_role_mode();
        if ($role !== 'client') {
            return new \WP_Error('dbvc_bricks_publish_remote_role_invalid', 'Remote publish is client-only.', ['status' => 400]);
        }
        $auth_method = DBVC_Bricks_Addon::get_setting('dbvc_bricks_auth_method', 'hmac');
        if ($auth_method !== 'wp_app_password') {
            return new \WP_Error('dbvc_bricks_publish_remote_auth_invalid', 'Remote publish currently requires wp_app_password auth method.', ['status' => 400]);
        }

        $mothership_url = untrailingslashit(DBVC_Bricks_Addon::get_setting('dbvc_bricks_mothership_url', ''));
        if ($mothership_url === '') {
            return new \WP_Error('dbvc_bricks_publish_remote_url_required', 'Mothership Base URL is required.', ['status' => 400]);
        }
        $username = DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_key_id', '');
        $app_password = DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_secret', '');
        if ($username === '' || $app_password === '') {
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
            return new \WP_Error('dbvc_bricks_publish_remote_package_required', 'package payload or package_id is required.', ['status' => 400]);
        }
        $targeting_payload = isset($params['targeting']) && is_array($params['targeting']) ? $params['targeting'] : ['mode' => 'all', 'site_uids' => []];
        $targeting = self::normalize_targeting($targeting_payload);
        if (is_wp_error($targeting)) {
            return $targeting;
        }

        $package = self::normalize_package($package_payload);
        $site_uid = DBVC_Bricks_Addon::get_setting('dbvc_bricks_site_uid', '');
        if ($site_uid === '') {
            $site_uid = 'site_' . get_current_blog_id();
        }
        $package['source_site']['site_uid'] = sanitize_key($site_uid);
        $package['source_site']['base_url'] = untrailingslashit(home_url('/'));
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

        $preflight = self::build_publish_preflight($package);
        $is_dry_run = ! empty($params['dry_run']) || ! empty($params['preflight_only']);
        if (! empty($preflight['errors'])) {
            $message = 'Publish preflight failed.';
            $first_error = isset($preflight['errors'][0]) ? sanitize_text_field((string) $preflight['errors'][0]) : '';
            if ($first_error !== '') {
                $message .= ' ' . $first_error;
            }
            $packages = self::get_packages();
            $package_id = (string) ($package['package_id'] ?? '');
            if ($package_id !== '' && isset($packages[$package_id]) && is_array($packages[$package_id])) {
                $packages[$package_id] = self::append_timeline_event($packages[$package_id], 'failed', [
                    'operation' => 'publish_remote',
                    'error_code' => $first_error,
                ]);
                $packages[$package_id] = self::update_transport_state(
                    $packages[$package_id],
                    'publish_remote',
                    false,
                    $first_error !== '' ? $first_error : 'dbvc_bricks_publish_remote_preflight_failed'
                );
                $packages[$package_id]['updated_at'] = gmdate('c');
                update_option(self::OPTION_PACKAGES, $packages);
            }
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
            $packages = self::get_packages();
            $package_id = (string) ($package['package_id'] ?? '');
            if ($package_id !== '' && isset($packages[$package_id]) && is_array($packages[$package_id])) {
                $packages[$package_id] = self::append_timeline_event($packages[$package_id], 'failed', [
                    'operation' => 'publish_remote',
                    'error_code' => 'dbvc_bricks_publish_remote_http_error',
                ]);
                $packages[$package_id] = self::update_transport_state($packages[$package_id], 'publish_remote', false, 'dbvc_bricks_publish_remote_http_error');
                $packages[$package_id]['updated_at'] = gmdate('c');
                update_option(self::OPTION_PACKAGES, $packages);
            }
            return new \WP_Error('dbvc_bricks_publish_remote_http_error', $response->get_error_message(), ['status' => 502]);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode((string) $body, true);
        if (! is_array($data)) {
            $data = ['raw' => (string) $body];
        }
        if ($status < 200 || $status >= 300) {
            $packages = self::get_packages();
            $package_id = (string) ($package['package_id'] ?? '');
            if ($package_id !== '' && isset($packages[$package_id]) && is_array($packages[$package_id])) {
                $packages[$package_id] = self::append_timeline_event($packages[$package_id], 'failed', [
                    'operation' => 'publish_remote',
                    'status' => $status,
                ]);
                $packages[$package_id] = self::update_transport_state($packages[$package_id], 'publish_remote', false, 'dbvc_bricks_publish_remote_failed');
                $packages[$package_id]['updated_at'] = gmdate('c');
                update_option(self::OPTION_PACKAGES, $packages);
            }
            return new \WP_Error('dbvc_bricks_publish_remote_failed', (string) ($data['message'] ?? 'Remote publish failed.'), [
                'status' => $status,
                'response' => $data,
                'remediation' => self::get_remediation_hint('dbvc_bricks_publish_remote_failed'),
            ]);
        }

        $packages = self::get_packages();
        $package_id = (string) ($package['package_id'] ?? '');
        if ($package_id !== '' && isset($packages[$package_id]) && is_array($packages[$package_id])) {
            $packages[$package_id] = self::append_timeline_event($packages[$package_id], 'sent', [
                'operation' => 'publish_remote',
                'target_mode' => (string) ($targeting['mode'] ?? 'all'),
                'response_status' => $status,
            ]);
            $packages[$package_id] = self::update_transport_state($packages[$package_id], 'publish_remote', true);
            if (! empty($forced_audit['channel_forced'])) {
                $packages[$package_id]['channel_force_audit'] = $forced_audit;
                $packages[$package_id]['channel'] = (string) $forced_audit['forced_to'];
            }
            $packages[$package_id]['updated_at'] = gmdate('c');
            update_option(self::OPTION_PACKAGES, $packages);
        }

        $result = [
            'ok' => true,
            'remote_url' => $remote_url,
            'status' => $status,
            'response' => $data,
            'package_id' => (string) ($package['package_id'] ?? ''),
            'channel_force_audit' => $forced_audit,
        ];
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
}
