<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Onboarding
{
    public const OPTION_CLIENTS = 'dbvc_bricks_clients';
    public const OPTION_TRANSPORT = 'dbvc_bricks_onboarding_transport';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_clients()
    {
        $clients = get_option(self::OPTION_CLIENTS, []);
        return is_array($clients) ? $clients : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_transport_states()
    {
        $states = get_option(self::OPTION_TRANSPORT, []);
        return is_array($states) ? $states : [];
    }

    /**
     * @param string $site_uid
     * @return array<string, mixed>
     */
    public static function get_transport_state($site_uid)
    {
        $site_uid = sanitize_key((string) $site_uid);
        if ($site_uid === '') {
            return [];
        }
        $states = self::get_transport_states();
        $state = isset($states[$site_uid]) && is_array($states[$site_uid]) ? $states[$site_uid] : [];
        return is_array($state) ? $state : [];
    }

    /**
     * @param string $site_uid
     * @return array<string, mixed>|null
     */
    public static function get_client($site_uid)
    {
        $site_uid = sanitize_key((string) $site_uid);
        if ($site_uid === '') {
            return null;
        }
        $clients = self::get_clients();
        return isset($clients[$site_uid]) && is_array($clients[$site_uid]) ? $clients[$site_uid] : null;
    }

    /**
     * @param string $site_uid
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function upsert_client($site_uid, array $changes)
    {
        $site_uid = sanitize_key((string) $site_uid);
        if ($site_uid === '') {
            return [];
        }

        $existing = self::get_client($site_uid);
        $record = is_array($existing) ? $existing : [];
        $record['site_uid'] = $site_uid;
        $record['site_label'] = sanitize_text_field((string) ($changes['site_label'] ?? ($record['site_label'] ?? $site_uid)));
        $record['base_url'] = esc_url_raw((string) ($changes['base_url'] ?? ($record['base_url'] ?? '')));
        $record['environment'] = sanitize_key((string) ($changes['environment'] ?? ($record['environment'] ?? 'local')));
        $record['auth_profile'] = isset($changes['auth_profile']) && is_array($changes['auth_profile'])
            ? $changes['auth_profile']
            : (isset($record['auth_profile']) && is_array($record['auth_profile']) ? $record['auth_profile'] : []);
        $record['capabilities'] = isset($changes['capabilities']) && is_array($changes['capabilities'])
            ? array_values($changes['capabilities'])
            : (isset($record['capabilities']) && is_array($record['capabilities']) ? array_values($record['capabilities']) : []);

        $state = sanitize_key((string) ($changes['onboarding_state'] ?? ($record['onboarding_state'] ?? 'pending_intro')));
        if (! in_array($state, ['pending_intro', 'verified', 'rejected', 'disabled'], true)) {
            $state = 'pending_intro';
        }
        $record['onboarding_state'] = $state;

        $record['last_intro_at'] = sanitize_text_field((string) ($changes['last_intro_at'] ?? ($record['last_intro_at'] ?? '')));
        $record['last_handshake_at'] = sanitize_text_field((string) ($changes['last_handshake_at'] ?? ($record['last_handshake_at'] ?? '')));
        $record['approved_at'] = sanitize_text_field((string) ($changes['approved_at'] ?? ($record['approved_at'] ?? '')));
        $record['rejected_at'] = sanitize_text_field((string) ($changes['rejected_at'] ?? ($record['rejected_at'] ?? '')));
        $record['last_seen_at'] = sanitize_text_field((string) ($changes['last_seen_at'] ?? gmdate('c')));
        $record['updated_at'] = gmdate('c');
        $record['notes'] = sanitize_text_field((string) ($changes['notes'] ?? ($record['notes'] ?? '')));
        $record['handshake_token_hash'] = sanitize_text_field((string) ($changes['handshake_token_hash'] ?? ($record['handshake_token_hash'] ?? '')));

        if (! isset($record['created_at']) || ! is_string($record['created_at'])) {
            $record['created_at'] = gmdate('c');
        }

        $clients = self::get_clients();
        $clients[$site_uid] = $record;
        update_option(self::OPTION_CLIENTS, $clients);

        return $record;
    }

    /**
     * @param string $site_uid
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function upsert_transport_state($site_uid, array $changes)
    {
        $site_uid = sanitize_key((string) $site_uid);
        if ($site_uid === '') {
            return [];
        }
        $existing = self::get_transport_state($site_uid);
        $record = is_array($existing) ? $existing : [];

        $record['site_uid'] = $site_uid;
        $record['ping_sent'] = ! empty($changes['ping_sent']) ? 1 : (! empty($record['ping_sent']) ? 1 : 0);
        $record['intro_sent'] = ! empty($changes['intro_sent']) ? 1 : (! empty($record['intro_sent']) ? 1 : 0);
        $record['attempts'] = max(0, (int) ($changes['attempts'] ?? ($record['attempts'] ?? 0)));
        $record['handshake_state'] = self::normalize_handshake_state((string) ($changes['handshake_state'] ?? ($record['handshake_state'] ?? 'PENDING_INTRO')));
        $record['approved_at'] = sanitize_text_field((string) ($changes['approved_at'] ?? ($record['approved_at'] ?? '')));
        $record['last_attempt_at'] = sanitize_text_field((string) ($changes['last_attempt_at'] ?? ($record['last_attempt_at'] ?? '')));
        $record['last_intro_at'] = sanitize_text_field((string) ($changes['last_intro_at'] ?? ($record['last_intro_at'] ?? '')));
        $record['last_error'] = sanitize_text_field((string) ($changes['last_error'] ?? ($record['last_error'] ?? '')));
        $record['next_retry_at'] = sanitize_text_field((string) ($changes['next_retry_at'] ?? ($record['next_retry_at'] ?? '')));
        $record['updated_at'] = gmdate('c');

        if (! isset($record['created_at']) || ! is_string($record['created_at'])) {
            $record['created_at'] = gmdate('c');
        }

        $states = self::get_transport_states();
        $states[$site_uid] = $record;
        update_option(self::OPTION_TRANSPORT, $states);
        return $record;
    }

    /**
     * @param string $context
     * @return array<string, mixed>
     */
    public static function persist_local_transport_state($context = 'runtime')
    {
        $site_uid = self::get_local_site_uid();
        if ($site_uid === '') {
            return [];
        }

        $state = self::normalize_handshake_state(DBVC_Bricks_Addon::get_setting('dbvc_bricks_client_registry_state', 'PENDING_INTRO'));
        $token = DBVC_Bricks_Addon::get_setting('dbvc_bricks_intro_handshake_token', '');
        if ($token !== '') {
            $state = 'VERIFIED';
            if (DBVC_Bricks_Addon::get_setting('dbvc_bricks_client_registry_state', '') !== 'VERIFIED') {
                update_option('dbvc_bricks_client_registry_state', 'VERIFIED');
            }
        }

        $existing = self::get_transport_state($site_uid);
        $approved_at = (string) ($existing['approved_at'] ?? '');
        if ($state === 'VERIFIED' && $approved_at === '') {
            $approved_at = gmdate('c');
        }

        $record = self::upsert_transport_state($site_uid, [
            'handshake_state' => $state,
            'approved_at' => $state === 'VERIFIED' ? $approved_at : '',
        ]);

        self::append_diagnostics('intro_transport_state_persisted', [
            'context' => sanitize_key((string) $context),
            'site_uid' => $site_uid,
            'handshake_state' => (string) ($record['handshake_state'] ?? ''),
        ]);
        return $record;
    }

    /**
     * @param string $context
     * @return array<string, mixed>
     */
    public static function run_client_onboarding_tick($context = 'cron')
    {
        if (! class_exists('DBVC_Bricks_Addon')) {
            return ['ok' => false, 'reason' => 'runtime_missing'];
        }
        if (DBVC_Bricks_Addon::get_role_mode() !== 'client') {
            return ['ok' => false, 'reason' => 'role_not_client'];
        }

        $site_uid = self::get_local_site_uid();
        if ($site_uid === '') {
            return ['ok' => false, 'reason' => 'site_uid_missing'];
        }

        $record = self::persist_local_transport_state($context);
        $state = self::normalize_handshake_state((string) ($record['handshake_state'] ?? 'PENDING_INTRO'));
        if (in_array($state, ['VERIFIED', 'REJECTED', 'DISABLED'], true)) {
            return ['ok' => true, 'reason' => 'terminal_state', 'state' => $state];
        }
        if (! DBVC_Bricks_Addon::get_bool_setting('dbvc_bricks_intro_auto_send', true)) {
            return ['ok' => false, 'reason' => 'auto_send_disabled', 'state' => $state];
        }
        if (! self::has_valid_remote_config()) {
            return ['ok' => false, 'reason' => 'invalid_remote_config', 'state' => $state];
        }

        $max_attempts = max(1, DBVC_Bricks_Addon::get_int_setting('dbvc_bricks_intro_retry_max_attempts', 6));
        $interval_minutes = max(5, DBVC_Bricks_Addon::get_int_setting('dbvc_bricks_intro_retry_interval_minutes', 30));
        $attempts = max(0, (int) ($record['attempts'] ?? 0));
        $next_retry_at = (string) ($record['next_retry_at'] ?? '');
        $next_retry_ts = $next_retry_at !== '' ? strtotime($next_retry_at) : false;
        if ($next_retry_ts !== false && $next_retry_ts > time()) {
            return ['ok' => false, 'reason' => 'retry_not_due', 'next_retry_at' => $next_retry_at];
        }
        if ($attempts >= $max_attempts) {
            self::disable_after_retry_exhausted($site_uid, $attempts, $max_attempts);
            return ['ok' => false, 'reason' => 'retry_exhausted', 'attempts' => $attempts];
        }

        $result = self::send_intro_packet_remote($site_uid);
        $attempts++;
        if (! empty($result['ok'])) {
            $handshake_state = self::normalize_handshake_state((string) ($result['onboarding_state'] ?? 'PENDING_INTRO'));
            $payload = [
                'ping_sent' => ! empty($result['ping_ok']) ? 1 : (! empty($record['ping_sent']) ? 1 : 0),
                'intro_sent' => 1,
                'attempts' => $attempts,
                'handshake_state' => $handshake_state,
                'approved_at' => $handshake_state === 'VERIFIED'
                    ? sanitize_text_field((string) ($result['approved_at'] ?? gmdate('c')))
                    : '',
                'last_attempt_at' => gmdate('c'),
                'last_intro_at' => sanitize_text_field((string) ($result['registered_at'] ?? gmdate('c'))),
                'last_error' => '',
                'next_retry_at' => in_array($handshake_state, ['VERIFIED', 'REJECTED', 'DISABLED'], true)
                    ? ''
                    : gmdate('c', time() + ($interval_minutes * MINUTE_IN_SECONDS)),
            ];
            $saved = self::upsert_transport_state($site_uid, $payload);
            update_option('dbvc_bricks_client_registry_state', $handshake_state);
            if ($handshake_state === 'VERIFIED' && ! empty($result['handshake_token'])) {
                update_option('dbvc_bricks_intro_handshake_token', sanitize_text_field((string) $result['handshake_token']));
            }
            self::append_diagnostics('intro_retry_success', [
                'context' => sanitize_key((string) $context),
                'site_uid' => $site_uid,
                'attempts' => $attempts,
                'state' => $handshake_state,
            ]);
            return ['ok' => true, 'state' => (string) ($saved['handshake_state'] ?? $handshake_state), 'attempts' => $attempts];
        }

        $next_retry_at = gmdate('c', time() + ($interval_minutes * MINUTE_IN_SECONDS));
        $error_code = sanitize_key((string) ($result['error_code'] ?? 'intro_send_failed'));
        $payload = [
            'attempts' => $attempts,
            'ping_sent' => ! empty($result['ping_ok']) ? 1 : 0,
            'intro_sent' => ! empty($result['intro_sent']) ? 1 : 0,
            'handshake_state' => 'PENDING_INTRO',
            'approved_at' => '',
            'last_attempt_at' => gmdate('c'),
            'last_error' => $error_code,
            'next_retry_at' => $next_retry_at,
        ];
        if ($attempts >= $max_attempts) {
            $payload['handshake_state'] = 'DISABLED';
            $payload['next_retry_at'] = '';
            update_option('dbvc_bricks_client_registry_state', 'DISABLED');
        }
        $saved = self::upsert_transport_state($site_uid, $payload);
        self::append_diagnostics($attempts >= $max_attempts ? 'intro_retry_exhausted' : 'intro_retry_failed', [
            'context' => sanitize_key((string) $context),
            'site_uid' => $site_uid,
            'attempts' => $attempts,
            'max_attempts' => $max_attempts,
            'error_code' => $error_code,
            'next_retry_at' => (string) ($saved['next_retry_at'] ?? ''),
        ]);
        return ['ok' => false, 'state' => (string) ($saved['handshake_state'] ?? 'PENDING_INTRO'), 'attempts' => $attempts, 'error_code' => $error_code];
    }

    /**
     * @param string $site_uid
     * @param int $attempts
     * @param int $max_attempts
     * @return void
     */
    private static function disable_after_retry_exhausted($site_uid, $attempts, $max_attempts)
    {
        self::upsert_transport_state($site_uid, [
            'attempts' => max(0, (int) $attempts),
            'handshake_state' => 'DISABLED',
            'next_retry_at' => '',
            'last_error' => 'retry_exhausted',
            'last_attempt_at' => gmdate('c'),
        ]);
        update_option('dbvc_bricks_client_registry_state', 'DISABLED');
        self::append_diagnostics('intro_retry_exhausted', [
            'site_uid' => sanitize_key((string) $site_uid),
            'attempts' => (int) $attempts,
            'max_attempts' => (int) $max_attempts,
            'error_code' => 'retry_exhausted',
        ]);
    }

    /**
     * @return string
     */
    private static function get_local_site_uid()
    {
        if (! class_exists('DBVC_Bricks_Addon')) {
            return '';
        }
        $site_uid = sanitize_key((string) DBVC_Bricks_Addon::get_setting('dbvc_bricks_site_uid', ''));
        if ($site_uid === '') {
            $site_uid = 'site_' . get_current_blog_id();
        }
        return sanitize_key($site_uid);
    }

    /**
     * @param string $state
     * @return string
     */
    private static function normalize_handshake_state($state)
    {
        $normalized = strtoupper(sanitize_text_field((string) $state));
        if (! in_array($normalized, ['PENDING_INTRO', 'VERIFIED', 'REJECTED', 'DISABLED'], true)) {
            return 'PENDING_INTRO';
        }
        return $normalized;
    }

    /**
     * @return bool
     */
    private static function has_valid_remote_config()
    {
        if (! class_exists('DBVC_Bricks_Addon')) {
            return false;
        }
        if (DBVC_Bricks_Addon::get_enum_setting('dbvc_bricks_auth_method', ['hmac', 'api_key', 'wp_app_password'], 'hmac') !== 'wp_app_password') {
            return false;
        }
        $mothership_url = untrailingslashit(DBVC_Bricks_Addon::get_setting('dbvc_bricks_mothership_url', ''));
        $username = DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_key_id', '');
        $secret = DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_secret', '');
        return $mothership_url !== '' && $username !== '' && $secret !== '';
    }

    /**
     * @param string $site_uid
     * @return array<string, mixed>
     */
    private static function send_intro_packet_remote($site_uid)
    {
        $mothership_url = untrailingslashit(DBVC_Bricks_Addon::get_setting('dbvc_bricks_mothership_url', ''));
        $username = DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_key_id', '');
        $secret = DBVC_Bricks_Addon::get_setting('dbvc_bricks_api_secret', '');
        $timeout = max(5, DBVC_Bricks_Addon::get_int_setting('dbvc_bricks_http_timeout', 30));
        $sslverify = DBVC_Bricks_Addon::get_bool_setting('dbvc_bricks_tls_verify', true);
        $auth_header = 'Basic ' . base64_encode($username . ':' . $secret);
        $corr = 'dbvc-intro-' . self::hash_fragment(gmdate('c'), 12);

        $ping_url = $mothership_url . '/wp-json/dbvc/v1/bricks/status';
        $ping = wp_remote_get($ping_url, [
            'timeout' => $timeout,
            'sslverify' => $sslverify,
            'headers' => [
                'Authorization' => $auth_header,
                'Accept' => 'application/json',
                'X-DBVC-Correlation-ID' => $corr,
            ],
        ]);
        if (is_wp_error($ping)) {
            return ['ok' => false, 'ping_ok' => false, 'error_code' => 'intro_ping_http_error'];
        }
        $ping_status = (int) wp_remote_retrieve_response_code($ping);
        if ($ping_status < 200 || $ping_status >= 300) {
            return ['ok' => false, 'ping_ok' => false, 'error_code' => 'intro_ping_failed'];
        }
        $ping_body = json_decode((string) wp_remote_retrieve_body($ping), true);
        if (! is_array($ping_body) || sanitize_key((string) ($ping_body['role'] ?? '')) !== 'mothership') {
            return ['ok' => false, 'ping_ok' => false, 'error_code' => 'intro_ping_role_invalid'];
        }

        $intro_url = $mothership_url . '/wp-json/dbvc/v1/bricks/intro/packet';
        $idempotency_key = 'intro-' . $site_uid . '-' . gmdate('YmdHi');
        $intro = wp_remote_post($intro_url, [
            'timeout' => $timeout,
            'sslverify' => $sslverify,
            'headers' => [
                'Authorization' => $auth_header,
                'Content-Type' => 'application/json',
                'Idempotency-Key' => $idempotency_key,
                'X-DBVC-Correlation-ID' => $corr,
            ],
            'body' => wp_json_encode([
                'site_uid' => $site_uid,
                'site_label' => get_bloginfo('name'),
                'base_url' => untrailingslashit(home_url('/')),
                'environment' => wp_get_environment_type(),
                'capabilities' => ['publish_remote', 'pull_latest', 'ack'],
                'auth_profile' => [
                    'method' => 'wp_app_password',
                    'key_id' => sanitize_text_field($username),
                ],
            ]),
        ]);
        if (is_wp_error($intro)) {
            return ['ok' => false, 'ping_ok' => true, 'error_code' => 'intro_send_http_error'];
        }
        $intro_status = (int) wp_remote_retrieve_response_code($intro);
        $intro_body = json_decode((string) wp_remote_retrieve_body($intro), true);
        if (! is_array($intro_body)) {
            $intro_body = [];
        }
        if ($intro_status < 200 || $intro_status >= 300 || empty($intro_body['ok'])) {
            return ['ok' => false, 'ping_ok' => true, 'intro_sent' => false, 'error_code' => 'intro_send_failed'];
        }

        return [
            'ok' => true,
            'ping_ok' => true,
            'intro_sent' => true,
            'onboarding_state' => isset($intro_body['onboarding_state']) ? (string) $intro_body['onboarding_state'] : 'PENDING_INTRO',
            'registered_at' => isset($intro_body['registered_at']) ? sanitize_text_field((string) $intro_body['registered_at']) : gmdate('c'),
            'handshake_token' => isset($intro_body['handshake_token']) ? sanitize_text_field((string) $intro_body['handshake_token']) : '',
            'approved_at' => isset($intro_body['approved_at']) ? sanitize_text_field((string) $intro_body['approved_at']) : '',
        ];
    }

    /**
     * @param string $event_type
     * @param array<string, mixed> $payload
     * @return void
     */
    private static function append_diagnostics($event_type, array $payload)
    {
        if (! class_exists('DBVC_Bricks_Addon')) {
            return;
        }
        $rows = get_option(DBVC_Bricks_Addon::OPTION_UI_DIAGNOSTICS, []);
        if (! is_array($rows)) {
            $rows = [];
        }
        $rows[] = [
            'event_type' => sanitize_key((string) $event_type),
            'payload' => DBVC_Bricks_Addon::sanitize_diagnostics_payload($payload),
            'correlation_id' => 'intro-' . self::hash_fragment((string) microtime(true), 8),
            'actor_id' => 0,
            'at' => gmdate('c'),
        ];
        if (count($rows) > DBVC_Bricks_Addon::UI_DIAGNOSTIC_MAX_ITEMS) {
            $rows = array_slice($rows, -DBVC_Bricks_Addon::UI_DIAGNOSTIC_MAX_ITEMS);
        }
        update_option(DBVC_Bricks_Addon::OPTION_UI_DIAGNOSTICS, array_values($rows));
    }

    /**
     * @param string $value
     * @param int $length
     * @return string
     */
    private static function hash_fragment($value, $length)
    {
        $length = max(4, (int) $length);
        if (function_exists('wp_hash')) {
            return substr((string) wp_hash((string) $value), 0, $length);
        }
        return substr(hash('sha256', (string) $value), 0, $length);
    }

    /**
     * @return \WP_Error|null
     */
    private static function validate_mothership_role()
    {
        if (! class_exists('DBVC_Bricks_Addon')) {
            return new \WP_Error('dbvc_bricks_intro_runtime_missing', 'Bricks addon runtime is unavailable.', ['status' => 500]);
        }
        if (DBVC_Bricks_Addon::get_role_mode() !== 'mothership') {
            return new \WP_Error('dbvc_bricks_intro_role_invalid', 'Introduction routes are mothership-only.', ['status' => 400]);
        }
        return null;
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_intro_packet(\WP_REST_Request $request)
    {
        $role_error = self::validate_mothership_role();
        if (is_wp_error($role_error)) {
            return $role_error;
        }

        $idempotency_key = DBVC_Bricks_Idempotency::extract_key($request);
        if ($idempotency_key === '') {
            return new \WP_Error('dbvc_bricks_idempotency_required', 'Idempotency-Key is required.', ['status' => 400]);
        }
        $existing = DBVC_Bricks_Idempotency::get('intro_packet', $idempotency_key);
        if (is_array($existing) && isset($existing['response']) && is_array($existing['response'])) {
            return rest_ensure_response($existing['response']);
        }

        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }

        $site_uid = sanitize_key((string) ($params['site_uid'] ?? ''));
        $base_url = esc_url_raw((string) ($params['base_url'] ?? ''));
        if ($site_uid === '' || $base_url === '') {
            return new \WP_Error('dbvc_bricks_intro_required_fields', 'site_uid and base_url are required.', ['status' => 400]);
        }

        $raw_capabilities = isset($params['capabilities']) && is_array($params['capabilities']) ? $params['capabilities'] : [];
        $capabilities = [];
        foreach ($raw_capabilities as $capability) {
            $capability = sanitize_key((string) $capability);
            if ($capability === '') {
                continue;
            }
            $capabilities[] = $capability;
        }
        $capabilities = array_values(array_unique($capabilities));

        $auth_profile = isset($params['auth_profile']) && is_array($params['auth_profile']) ? $params['auth_profile'] : [];
        $auth_profile = [
            'method' => sanitize_key((string) ($auth_profile['method'] ?? 'wp_app_password')),
            'key_id' => sanitize_text_field((string) ($auth_profile['key_id'] ?? '')),
        ];

        $record = self::upsert_client($site_uid, [
            'site_label' => sanitize_text_field((string) ($params['site_label'] ?? $site_uid)),
            'base_url' => $base_url,
            'environment' => sanitize_key((string) ($params['environment'] ?? 'local')),
            'capabilities' => $capabilities,
            'auth_profile' => $auth_profile,
            'onboarding_state' => 'pending_intro',
            'last_intro_at' => gmdate('c'),
            'last_seen_at' => gmdate('c'),
        ]);

        if (class_exists('DBVC_Bricks_Connected_Sites')) {
            DBVC_Bricks_Connected_Sites::upsert_site([
                'site_uid' => $site_uid,
                'site_label' => (string) ($record['site_label'] ?? $site_uid),
                'base_url' => $base_url,
                'status' => 'online',
                'auth_mode' => (string) ($auth_profile['method'] ?? 'wp_app_password'),
                'allow_receive_packages' => 0,
                'last_seen_at' => gmdate('c'),
            ]);
        }

        $response = [
            'ok' => true,
            'site_uid' => $site_uid,
            'onboarding_state' => 'PENDING_INTRO',
            'registered_at' => (string) ($record['updated_at'] ?? gmdate('c')),
        ];
        DBVC_Bricks_Idempotency::put('intro_packet', $idempotency_key, $response);
        return rest_ensure_response($response);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_intro_handshake(\WP_REST_Request $request)
    {
        $role_error = self::validate_mothership_role();
        if (is_wp_error($role_error)) {
            return $role_error;
        }

        $idempotency_key = DBVC_Bricks_Idempotency::extract_key($request);
        if ($idempotency_key === '') {
            return new \WP_Error('dbvc_bricks_idempotency_required', 'Idempotency-Key is required.', ['status' => 400]);
        }
        $existing = DBVC_Bricks_Idempotency::get('intro_handshake', $idempotency_key);
        if (is_array($existing) && isset($existing['response']) && is_array($existing['response'])) {
            return rest_ensure_response($existing['response']);
        }

        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }

        $site_uid = sanitize_key((string) ($params['site_uid'] ?? ''));
        $decision = sanitize_key((string) ($params['decision'] ?? ''));
        $notes = sanitize_text_field((string) ($params['notes'] ?? ''));
        if ($site_uid === '' || ! in_array($decision, ['accept', 'reject'], true)) {
            return new \WP_Error('dbvc_bricks_intro_handshake_invalid', 'site_uid and valid decision (accept|reject) are required.', ['status' => 400]);
        }

        $existing_record = self::get_client($site_uid);
        if (! is_array($existing_record)) {
            return new \WP_Error('dbvc_bricks_intro_client_not_found', 'Client introduction record not found.', ['status' => 404]);
        }

        $accepted = $decision === 'accept';
        $registered_at = gmdate('c');
        $token = '';
        $token_hash = '';
        if ($accepted) {
            $token = 'hs_' . substr(wp_hash($site_uid . '|' . $registered_at . '|' . wp_rand()), 0, 24);
            $token_hash = hash('sha256', $token);
        }

        $record = self::upsert_client($site_uid, [
            'onboarding_state' => $accepted ? 'verified' : 'rejected',
            'last_handshake_at' => $registered_at,
            'approved_at' => $accepted ? $registered_at : '',
            'rejected_at' => $accepted ? '' : $registered_at,
            'notes' => $notes,
            'handshake_token_hash' => $token_hash,
            'last_seen_at' => $registered_at,
        ]);

        if (class_exists('DBVC_Bricks_Connected_Sites')) {
            DBVC_Bricks_Connected_Sites::upsert_site([
                'site_uid' => $site_uid,
                'site_label' => (string) ($record['site_label'] ?? $site_uid),
                'base_url' => (string) ($record['base_url'] ?? ''),
                'status' => $accepted ? 'online' : 'disabled',
                'auth_mode' => (string) (($record['auth_profile']['method'] ?? '') ?: 'wp_app_password'),
                'allow_receive_packages' => $accepted ? 1 : 0,
                'last_seen_at' => $registered_at,
            ]);
        }

        $mothership_uid = sanitize_key((string) get_option('dbvc_bricks_site_uid', ''));
        if ($mothership_uid === '') {
            $mothership_uid = 'mship_' . get_current_blog_id();
        }

        $signature_payload = wp_json_encode([
            'site_uid' => $site_uid,
            'accepted' => $accepted,
            'registered_at' => $registered_at,
            'mothership_uid' => $mothership_uid,
        ]);
        $ack_signature = 'sig_' . hash_hmac('sha256', is_string($signature_payload) ? $signature_payload : '', wp_salt('auth'));

        $response = [
            'ok' => true,
            'accepted' => $accepted,
            'mothership_uid' => $mothership_uid,
            'registered_at' => $registered_at,
            'handshake_token' => $token,
            'signature' => $ack_signature,
            'site_uid' => $site_uid,
        ];
        DBVC_Bricks_Idempotency::put('intro_handshake', $idempotency_key, $response);
        return rest_ensure_response($response);
    }
}
