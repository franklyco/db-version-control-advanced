<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Command_Auth
{
    public const OPTION_NONCES = 'dbvc_bricks_command_nonces';
    public const MAX_NONCES = 500;
    public const TIMESTAMP_WINDOW_SECONDS = 300;

    /**
     * @param string $secret
     * @param int $timestamp
     * @param string $nonce
     * @param string $site_uid
     * @param string $raw_body
     * @return string
     */
    public static function build_signature($secret, $timestamp, $nonce, $site_uid, $raw_body)
    {
        $body_hash = hash('sha256', (string) $raw_body);
        $payload = (string) $timestamp . "\n" . (string) $nonce . "\n" . (string) $site_uid . "\n" . $body_hash;
        return 'sha256=' . hash_hmac('sha256', $payload, (string) $secret);
    }

    /**
     * @param \WP_REST_Request $request
     * @param string $raw_body
     * @return true|\WP_Error
     */
    public static function verify_signed_request(\WP_REST_Request $request, $raw_body)
    {
        if (! class_exists('DBVC_Bricks_Addon')) {
            return new \WP_Error('dbvc_bricks_command_runtime_missing', 'Bricks addon runtime unavailable.', ['status' => 500]);
        }
        if (DBVC_Bricks_Addon::get_role_mode() !== 'client') {
            return new \WP_Error('dbvc_bricks_command_role_invalid', 'Signed command verification is client-only.', ['status' => 400]);
        }

        $secret = DBVC_Bricks_Addon::get_setting('dbvc_bricks_intro_handshake_token', '');
        if ($secret === '') {
            return new \WP_Error('dbvc_bricks_command_secret_missing', 'Handshake token is not configured on client.', ['status' => 400]);
        }

        $timestamp = (int) $request->get_header('X-DBVC-Timestamp');
        $nonce = sanitize_text_field((string) $request->get_header('X-DBVC-Nonce'));
        $signature = sanitize_text_field((string) $request->get_header('X-DBVC-Signature'));
        $site_uid = sanitize_key((string) $request->get_header('X-DBVC-Site-UID'));
        if ($timestamp <= 0 || $nonce === '' || $signature === '' || $site_uid === '') {
            return new \WP_Error('dbvc_bricks_command_headers_required', 'Missing required signed command headers.', ['status' => 401]);
        }

        $now = time();
        if (abs($now - $timestamp) > self::TIMESTAMP_WINDOW_SECONDS) {
            return new \WP_Error('dbvc_bricks_command_timestamp_invalid', 'Signed command timestamp is outside allowed window.', ['status' => 401]);
        }

        $local_site_uid = sanitize_key((string) DBVC_Bricks_Addon::get_setting('dbvc_bricks_site_uid', ''));
        if ($local_site_uid === '') {
            $local_site_uid = 'site_' . get_current_blog_id();
        }
        if ($site_uid !== $local_site_uid) {
            return new \WP_Error('dbvc_bricks_command_site_uid_mismatch', 'Signed command site UID does not match this client.', ['status' => 403]);
        }

        $nonce_error = self::validate_and_store_nonce($nonce, $timestamp);
        if (is_wp_error($nonce_error)) {
            return $nonce_error;
        }

        $expected = self::build_signature($secret, $timestamp, $nonce, $site_uid, (string) $raw_body);
        if (! hash_equals($expected, $signature)) {
            return new \WP_Error('dbvc_bricks_command_signature_invalid', 'Signed command signature validation failed.', ['status' => 401]);
        }

        return true;
    }

    /**
     * @param string $nonce
     * @param int $timestamp
     * @return true|\WP_Error
     */
    private static function validate_and_store_nonce($nonce, $timestamp)
    {
        $store = get_option(self::OPTION_NONCES, []);
        if (! is_array($store)) {
            $store = [];
        }

        $cutoff = time() - (self::TIMESTAMP_WINDOW_SECONDS * 2);
        foreach ($store as $existing_nonce => $seen_at) {
            if ((int) $seen_at < $cutoff) {
                unset($store[$existing_nonce]);
            }
        }

        if (isset($store[$nonce])) {
            return new \WP_Error('dbvc_bricks_command_nonce_replay', 'Signed command nonce replay detected.', ['status' => 409]);
        }

        $store[$nonce] = $timestamp;
        if (count($store) > self::MAX_NONCES) {
            asort($store, SORT_NUMERIC);
            $remove_count = count($store) - self::MAX_NONCES;
            $to_remove = array_slice(array_keys($store), 0, $remove_count);
            foreach ($to_remove as $old_nonce) {
                unset($store[$old_nonce]);
            }
        }
        update_option(self::OPTION_NONCES, $store);

        return true;
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_signed_ping(\WP_REST_Request $request)
    {
        $verify = self::verify_signed_request($request, (string) $request->get_body());
        if (is_wp_error($verify)) {
            return $verify;
        }
        return rest_ensure_response([
            'ok' => true,
            'verified' => true,
            'site_uid' => sanitize_key((string) $request->get_header('X-DBVC-Site-UID')),
            'at' => gmdate('c'),
        ]);
    }
}
