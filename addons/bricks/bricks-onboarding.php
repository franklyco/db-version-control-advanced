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
     * @return array<string, mixed>|null
     */
    public static function reset_client_linkage($site_uid)
    {
        $site_uid = sanitize_key((string) $site_uid);
        if ($site_uid === '') {
            return null;
        }
        $existing = self::get_client($site_uid);
        if (! is_array($existing)) {
            return null;
        }
        return self::upsert_client($site_uid, [
            'onboarding_state' => 'pending_intro',
            'approved_at' => '',
            'rejected_at' => '',
            'last_handshake_at' => '',
            'handshake_token_hash' => '',
            'command_secret' => '',
            'notes' => 'linkage_reset_' . gmdate('c'),
            'last_seen_at' => gmdate('c'),
        ]);
    }

    /**
     * @param string $alias_site_uid
     * @param string $canonical_site_uid
     * @return array<string, mixed>|null
     */
    public static function disable_alias_client_record($alias_site_uid, $canonical_site_uid = '')
    {
        $alias_site_uid = sanitize_key((string) $alias_site_uid);
        if ($alias_site_uid === '') {
            return null;
        }
        $existing = self::get_client($alias_site_uid);
        if (! is_array($existing)) {
            return null;
        }
        return self::upsert_client($alias_site_uid, [
            'onboarding_state' => 'disabled',
            'approved_at' => '',
            'handshake_token_hash' => '',
            'command_secret' => '',
            'notes' => 'alias_deactivated:' . sanitize_key((string) $canonical_site_uid),
            'last_seen_at' => gmdate('c'),
        ]);
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
        $record['command_secret'] = sanitize_text_field((string) ($changes['command_secret'] ?? ($record['command_secret'] ?? '')));
        $record['local_instance_uuid'] = sanitize_text_field((string) ($changes['local_instance_uuid'] ?? ($record['local_instance_uuid'] ?? '')));
        $record['first_seen_at'] = sanitize_text_field((string) ($changes['first_seen_at'] ?? ($record['first_seen_at'] ?? '')));
        $record['site_sequence_id'] = max(0, (int) ($changes['site_sequence_id'] ?? ($record['site_sequence_id'] ?? 0)));
        $record['site_title_host_snapshot'] = sanitize_text_field((string) ($changes['site_title_host_snapshot'] ?? ($record['site_title_host_snapshot'] ?? '')));
        $record['last_incoming_site_uid'] = sanitize_key((string) ($changes['last_incoming_site_uid'] ?? ($record['last_incoming_site_uid'] ?? '')));

        if (! isset($record['created_at']) || ! is_string($record['created_at'])) {
            $record['created_at'] = gmdate('c');
        }
        if ($record['first_seen_at'] === '') {
            $record['first_seen_at'] = (string) $record['created_at'];
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
     * @return string
     */
    private static function get_local_instance_uuid()
    {
        $existing = sanitize_text_field((string) get_option('dbvc_bricks_local_instance_uuid', ''));
        if ($existing !== '') {
            return $existing;
        }
        $uuid = 'inst_' . substr(hash('sha256', home_url('/') . '|' . microtime(true) . '|' . self::random_seed()), 0, 24);
        update_option('dbvc_bricks_local_instance_uuid', $uuid);
        return $uuid;
    }

    /**
     * @return string
     */
    private static function get_local_title_host_snapshot()
    {
        $title = sanitize_text_field((string) get_bloginfo('name'));
        $host = sanitize_text_field((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($title === '' && $host === '') {
            return '';
        }
        if ($host === '') {
            return $title;
        }
        if ($title === '') {
            return $host;
        }
        return $title . ' | ' . $host;
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
                'local_instance_uuid' => self::get_local_instance_uuid(),
                'site_title_host_snapshot' => self::get_local_title_host_snapshot(),
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
     * @param string $site_uid
     * @param string $registered_at
     * @return string
     */
    private static function issue_handshake_token($site_uid, $registered_at)
    {
        $seed = (string) $site_uid . '|' . (string) $registered_at . '|' . self::random_seed();
        if (function_exists('wp_hash')) {
            return 'hs_' . substr((string) wp_hash($seed), 0, 24);
        }
        return 'hs_' . substr(hash('sha256', $seed), 0, 24);
    }

    /**
     * @return string
     */
    private static function random_seed()
    {
        if (function_exists('wp_rand')) {
            return (string) wp_rand();
        }
        return (string) mt_rand();
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

        $site_uid_incoming = sanitize_key((string) ($params['site_uid'] ?? ''));
        $base_url = esc_url_raw((string) ($params['base_url'] ?? ''));
        if ($site_uid_incoming === '' || $base_url === '') {
            return new \WP_Error('dbvc_bricks_intro_required_fields', 'site_uid and base_url are required.', ['status' => 400]);
        }
        $site_uid = $site_uid_incoming;
        if (class_exists('DBVC_Bricks_Connected_Sites') && method_exists('DBVC_Bricks_Connected_Sites', 'resolve_site_identity')) {
            $resolution = DBVC_Bricks_Connected_Sites::resolve_site_identity($site_uid_incoming);
            $resolved = sanitize_key((string) ($resolution['resolved_site_uid'] ?? ''));
            if ($resolved !== '') {
                $site_uid = $resolved;
            }
        }

        $existing_record = self::get_client($site_uid);
        $existing_state = is_array($existing_record)
            ? sanitize_key((string) ($existing_record['onboarding_state'] ?? 'pending_intro'))
            : 'pending_intro';
        if (! in_array($existing_state, ['pending_intro', 'verified', 'rejected', 'disabled'], true)) {
            $existing_state = 'pending_intro';
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

        $command_secret = is_array($existing_record)
            ? sanitize_text_field((string) ($existing_record['command_secret'] ?? ''))
            : '';
        $approved_at = is_array($existing_record)
            ? sanitize_text_field((string) ($existing_record['approved_at'] ?? ''))
            : '';
        $handshake_token_hash = is_array($existing_record)
            ? sanitize_text_field((string) ($existing_record['handshake_token_hash'] ?? ''))
            : '';
        if ($existing_state === 'verified' && $command_secret === '') {
            $approved_at = $approved_at !== '' ? $approved_at : gmdate('c');
            $command_secret = self::issue_handshake_token($site_uid, $approved_at);
            $handshake_token_hash = hash('sha256', $command_secret);
        }

        $record = self::upsert_client($site_uid, [
            'site_label' => sanitize_text_field((string) ($params['site_label'] ?? $site_uid)),
            'base_url' => $base_url,
            'environment' => sanitize_key((string) ($params['environment'] ?? 'local')),
            'capabilities' => $capabilities,
            'auth_profile' => $auth_profile,
            'onboarding_state' => $existing_state,
            'last_intro_at' => gmdate('c'),
            'last_seen_at' => gmdate('c'),
            'approved_at' => $existing_state === 'verified' ? $approved_at : '',
            'handshake_token_hash' => $handshake_token_hash,
            'command_secret' => $existing_state === 'verified' ? $command_secret : '',
            'local_instance_uuid' => sanitize_text_field((string) ($params['local_instance_uuid'] ?? ($existing_record['local_instance_uuid'] ?? ''))),
            'first_seen_at' => sanitize_text_field((string) ($existing_record['first_seen_at'] ?? gmdate('c'))),
            'site_title_host_snapshot' => sanitize_text_field((string) ($params['site_title_host_snapshot'] ?? ($existing_record['site_title_host_snapshot'] ?? ''))),
            'last_incoming_site_uid' => $site_uid_incoming,
        ]);

        if (class_exists('DBVC_Bricks_Connected_Sites')) {
            $allow_receive_packages = 0;
            if ($existing_state === 'verified') {
                $allow_receive_packages = 1;
            } elseif (is_array($existing_record) && ! empty($existing_record['allow_receive_packages'])) {
                $allow_receive_packages = 1;
            }
            $connected_payload = [
                'site_uid' => $site_uid,
                'site_label' => (string) ($record['site_label'] ?? $site_uid),
                'base_url' => $base_url,
                'status' => in_array($existing_state, ['rejected', 'disabled'], true) ? 'disabled' : 'online',
                'auth_mode' => (string) ($auth_profile['method'] ?? 'wp_app_password'),
                'allow_receive_packages' => $allow_receive_packages,
                'onboarding_state' => strtoupper((string) ($record['onboarding_state'] ?? 'PENDING_INTRO')),
                'onboarding_updated_at' => (string) ($record['last_handshake_at'] ?? $record['updated_at'] ?? gmdate('c')),
                'last_seen_at' => gmdate('c'),
                'local_instance_uuid' => (string) ($record['local_instance_uuid'] ?? ''),
                'first_seen_at' => (string) ($record['first_seen_at'] ?? ''),
                'site_title_host_snapshot' => (string) ($record['site_title_host_snapshot'] ?? ''),
            ];

            $recover_hidden_visibility = false;
            if (
                method_exists('DBVC_Bricks_Connected_Sites', 'get_site')
                && method_exists('DBVC_Bricks_Connected_Sites', 'normalize_base_url')
            ) {
                $existing_site = DBVC_Bricks_Connected_Sites::get_site($site_uid);
                if (is_array($existing_site) && ! empty($existing_site['is_hidden'])) {
                    $hidden_reason = sanitize_key((string) ($existing_site['hidden_reason'] ?? ''));
                    $incoming_base = DBVC_Bricks_Connected_Sites::normalize_base_url($base_url);
                    $existing_base = DBVC_Bricks_Connected_Sites::normalize_base_url((string) ($existing_site['base_url'] ?? ''));
                    if (
                        $hidden_reason === DBVC_Bricks_Connected_Sites::HIDDEN_REASON_MANUAL_FORGET
                        && $incoming_base !== ''
                        && ($existing_base === '' || $existing_base === $incoming_base)
                    ) {
                        $recover_hidden_visibility = true;
                    }
                }
            }
            if ($recover_hidden_visibility) {
                $connected_payload['is_hidden'] = 0;
                $connected_payload['hidden_reason'] = '';
                $connected_payload['linkage_recovered_at'] = gmdate('c');
                self::append_diagnostics('intro_packet_hidden_visibility_restored', [
                    'site_uid' => $site_uid,
                    'incoming_site_uid' => $site_uid_incoming,
                ]);
            }

            DBVC_Bricks_Connected_Sites::upsert_site($connected_payload);
            if ($site_uid !== $site_uid_incoming && method_exists('DBVC_Bricks_Connected_Sites', 'set_known_alias')) {
                DBVC_Bricks_Connected_Sites::set_known_alias($site_uid_incoming, $site_uid, 'intro_packet_resolved_alias', true);
            }
        }

        $response_state = strtoupper((string) ($record['onboarding_state'] ?? 'pending_intro'));
        if (! in_array($response_state, ['PENDING_INTRO', 'VERIFIED', 'REJECTED', 'DISABLED'], true)) {
            $response_state = 'PENDING_INTRO';
        }
        $response = [
            'ok' => true,
            'site_uid' => $site_uid,
            'onboarding_state' => $response_state,
            'registered_at' => (string) ($record['updated_at'] ?? gmdate('c')),
            'approved_at' => $response_state === 'VERIFIED' ? (string) ($record['approved_at'] ?? '') : '',
            'handshake_token' => $response_state === 'VERIFIED' ? (string) ($record['command_secret'] ?? '') : '',
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

        $site_uid_incoming = sanitize_key((string) ($params['site_uid'] ?? ''));
        $decision = sanitize_key((string) ($params['decision'] ?? ''));
        $notes = sanitize_text_field((string) ($params['notes'] ?? ''));
        if ($site_uid_incoming === '' || ! in_array($decision, ['accept', 'reject'], true)) {
            return new \WP_Error('dbvc_bricks_intro_handshake_invalid', 'site_uid and valid decision (accept|reject) are required.', ['status' => 400]);
        }
        $site_uid = $site_uid_incoming;
        if (class_exists('DBVC_Bricks_Connected_Sites') && method_exists('DBVC_Bricks_Connected_Sites', 'resolve_site_identity')) {
            $resolution = DBVC_Bricks_Connected_Sites::resolve_site_identity($site_uid_incoming);
            $resolved = sanitize_key((string) ($resolution['resolved_site_uid'] ?? ''));
            if ($resolved !== '') {
                $site_uid = $resolved;
            }
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
            $token = self::issue_handshake_token($site_uid, $registered_at);
            $token_hash = hash('sha256', $token);
        }

        $record = self::upsert_client($site_uid, [
            'onboarding_state' => $accepted ? 'verified' : 'rejected',
            'last_handshake_at' => $registered_at,
            'approved_at' => $accepted ? $registered_at : '',
            'rejected_at' => $accepted ? '' : $registered_at,
            'notes' => $notes,
            'handshake_token_hash' => $token_hash,
            'command_secret' => $accepted ? $token : '',
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

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_client_reset_rerun(\WP_REST_Request $request)
    {
        if (! class_exists('DBVC_Bricks_Addon')) {
            return new \WP_Error('dbvc_bricks_intro_runtime_missing', 'Bricks addon runtime is unavailable.', ['status' => 500]);
        }
        if (DBVC_Bricks_Addon::get_role_mode() !== 'client') {
            return new \WP_Error('dbvc_bricks_intro_retry_role_invalid', 'Reset + Re-run Intro Handshake is client-only.', ['status' => 400]);
        }

        $confirm = ! empty($request->get_param('confirm_reset'));
        if (! $confirm) {
            return new \WP_Error('dbvc_bricks_intro_retry_confirm_required', 'confirm_reset=true is required.', ['status' => 400]);
        }

        $site_uid = self::get_local_site_uid();
        if ($site_uid === '') {
            return new \WP_Error('dbvc_bricks_intro_retry_site_uid_missing', 'Local site UID is not configured.', ['status' => 400]);
        }

        update_option('dbvc_bricks_intro_handshake_token', '');
        update_option('dbvc_bricks_client_registry_state', 'PENDING_INTRO');
        self::upsert_transport_state($site_uid, [
            'ping_sent' => 0,
            'intro_sent' => 0,
            'attempts' => 0,
            'handshake_state' => 'PENDING_INTRO',
            'approved_at' => '',
            'last_attempt_at' => '',
            'last_intro_at' => '',
            'last_error' => '',
            'next_retry_at' => '',
        ]);

        self::append_diagnostics('intro_manual_reset_requested', [
            'site_uid' => $site_uid,
            'context' => 'client_ui',
        ]);

        $run_now = ! isset($request['run_now']) || ! empty($request->get_param('run_now'));
        $tick = ['ok' => true, 'reason' => 'reset_only'];
        if ($run_now) {
            $tick = self::run_client_onboarding_tick('manual_reset_rerun');
        }

        return rest_ensure_response([
            'ok' => ! is_wp_error($tick),
            'site_uid' => $site_uid,
            'run_now' => $run_now,
            'result' => $tick,
        ]);
    }

}
