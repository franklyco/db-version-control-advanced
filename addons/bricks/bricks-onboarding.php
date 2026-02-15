<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Onboarding
{
    public const OPTION_CLIENTS = 'dbvc_bricks_clients';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_clients()
    {
        $clients = get_option(self::OPTION_CLIENTS, []);
        return is_array($clients) ? $clients : [];
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
