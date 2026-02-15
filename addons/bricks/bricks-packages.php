<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Packages
{
    public const OPTION_PACKAGES = 'dbvc_bricks_packages';
    public const REMOTE_PACKAGE_MAX_BYTES = 1048576;
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PUBLISHED = 'PUBLISHED';
    public const STATUS_SUPERSEDED = 'SUPERSEDED';
    public const STATUS_REVOKED = 'REVOKED';

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
        }
        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : 50;
        return array_slice($items, 0, $limit);
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
        if ($package_bytes > self::REMOTE_PACKAGE_MAX_BYTES) {
            $errors[] = 'package_payload_too_large';
        }

        return [
            'ok' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'limits' => [
                'max_package_bytes' => self::REMOTE_PACKAGE_MAX_BYTES,
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

        $packages[$package_id]['channel'] = $target_channel;
        $packages[$package_id]['updated_at'] = gmdate('c');
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

        $packages[$package_id]['status'] = self::STATUS_REVOKED;
        $packages[$package_id]['updated_at'] = gmdate('c');
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
        ];
        $packages[$package_id]['updated_at'] = gmdate('c');
        update_option(self::OPTION_PACKAGES, $packages);

        return rest_ensure_response([
            'ok' => true,
            'package_id' => $package_id,
            'ack_state' => $state,
        ]);
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

        $preflight = self::build_publish_preflight($package);
        $is_dry_run = ! empty($params['dry_run']) || ! empty($params['preflight_only']);
        if (! empty($preflight['errors'])) {
            return new \WP_Error('dbvc_bricks_publish_remote_preflight_failed', 'Publish preflight failed.', [
                'status' => 400,
                'preflight' => $preflight,
            ]);
        }
        if ($is_dry_run) {
            return rest_ensure_response([
                'ok' => true,
                'dry_run' => true,
                'preflight' => $preflight,
                'targeting' => $targeting,
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
            return new \WP_Error('dbvc_bricks_publish_remote_http_error', $response->get_error_message(), ['status' => 502]);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode((string) $body, true);
        if (! is_array($data)) {
            $data = ['raw' => (string) $body];
        }
        if ($status < 200 || $status >= 300) {
            return new \WP_Error('dbvc_bricks_publish_remote_failed', (string) ($data['message'] ?? 'Remote publish failed.'), ['status' => $status, 'response' => $data]);
        }

        $result = [
            'ok' => true,
            'remote_url' => $remote_url,
            'status' => $status,
            'response' => $data,
            'package_id' => (string) ($package['package_id'] ?? ''),
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
                'targeting' => [
                    'mode' => $target_mode,
                    'site_uids' => $target_mode === 'selected' ? $target_sites : [],
                ],
                'ack_summary' => [
                    'site_count' => count($latest_by_site),
                    'states' => $state_counts,
                    'last_ack_at' => $last_ack_at,
                ],
                'updated_at' => (string) ($package['updated_at'] ?? ''),
            ];
        }

        return $rows;
    }
}
