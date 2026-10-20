<?php

namespace Dbvc\Connected\Transport;

use Dbvc\ConnectedProtocol\Protocol;

/**
 * Outbound-only HTTP client for the hub. Never called from a save hook.
 * TLS verification is always on; plain `http://` hub URLs are accepted only
 * for a `local` environment type or the `dbvc_connected_allow_insecure_hub`
 * filter (disposable labs), never as a default.
 */
final class HubClient
{
    /**
     * @param string $hub_url
     * @return string|\WP_Error Normalised base URL.
     */
    public static function validate_hub_url($hub_url)
    {
        $hub_url = trim((string) $hub_url);
        $parts = wp_parse_url($hub_url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return new \WP_Error('dbvc_connected_hub_url_invalid', 'Hub URL must be an absolute URL.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme === 'http') {
            $insecure_ok = wp_get_environment_type() === 'local';
            /**
             * Allow a plain-HTTP hub URL (disposable labs only).
             *
             * @param bool   $insecure_ok
             * @param string $hub_url
             */
            if (! apply_filters('dbvc_connected_allow_insecure_hub', $insecure_ok, $hub_url)) {
                return new \WP_Error('dbvc_connected_hub_url_insecure', 'Hub URL must use https (plain http is only accepted for the local environment type).');
            }
        } elseif ($scheme !== 'https') {
            return new \WP_Error('dbvc_connected_hub_url_invalid', 'Hub URL scheme must be https.');
        }

        return untrailingslashit($hub_url);
    }

    /**
     * @param string               $hub_url
     * @param string               $route     Route below the protocol namespace, e.g. `/observations`.
     * @param array<string, mixed> $body
     * @param array<string, mixed> $auth      Optional `username` + `password` for Basic auth.
     * @return array{ok: bool, status: int, body: array<string, mixed>|null, error: string, error_code: string}
     */
    public static function post_json($hub_url, $route, array $body, array $auth = [])
    {
        return self::request('POST', $hub_url, $route, [], $body, $auth);
    }

    /**
     * @param string               $hub_url
     * @param string               $route
     * @param array<string, mixed> $query
     * @param array<string, mixed> $auth
     * @return array{ok: bool, status: int, body: array<string, mixed>|null, error: string, error_code: string}
     */
    public static function get_json($hub_url, $route, array $query = [], array $auth = [])
    {
        return self::request('GET', $hub_url, $route, $query, null, $auth);
    }

    /**
     * GET a media blob from the hub pull channel (A2-2c). Identical to
     * {@see get_json} except the response-size limit is sized for a full-ceiling
     * media file: the blob is base64-wrapped (×4/3) in the JSON body, so the
     * shared {@see get_json} limit (`MAX_BATCH_BYTES` × 4 = 1 MiB) truncates any
     * file over ~768 KiB — i.e. every fetch file. Allow the pull ceiling plus
     * encoding and envelope overhead, and a longer timeout for the larger body.
     *
     * @param string               $hub_url
     * @param string               $route
     * @param array<string, mixed> $auth
     * @return array{ok: bool, status: int, body: array<string, mixed>|null, error: string, error_code: string}
     */
    public static function get_media_blob($hub_url, $route, array $auth = [])
    {
        $base = self::validate_hub_url($hub_url);
        if (is_wp_error($base)) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $base->get_error_message(), 'error_code' => $base->get_error_code()];
        }

        $headers = [
            'Accept' => 'application/json',
            Protocol::HEADER_SITE_URL => home_url('/'),
            Protocol::HEADER_PROTOCOL => Protocol::PROTOCOL_VERSION,
        ];
        if (isset($auth['username'], $auth['password'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode((string) $auth['username'] . ':' . (string) $auth['password']);
        }

        $url = $base . '/index.php?rest_route=' . rawurlencode('/' . Protocol::REST_NAMESPACE . $route);
        $response = wp_remote_request($url, [
            'method' => 'GET',
            'timeout' => Protocol::REQUEST_TIMEOUT_SECONDS * 6,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => $headers,
            'limit_response_size' => (int) (Protocol::MAX_PULL_FILE_BYTES * 2),
        ]);
        if (is_wp_error($response)) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $response->get_error_message(), 'error_code' => 'transport:' . $response->get_error_code()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $decoded = is_array($decoded) ? $decoded : null;

        return ['ok' => $status >= 200 && $status < 300 && $decoded !== null, 'status' => $status, 'body' => $decoded, 'error' => '', 'error_code' => ''];
    }

    /**
     * PUT raw bytes (octet-stream) to a hub route — the media pull-channel upload.
     * The body is the raw file, not a JSON envelope, so a large file is streamed
     * rather than base64-inflated past post_max_size. Returns the same result
     * shape as {@see post_json}.
     *
     * @param string               $hub_url
     * @param string               $route
     * @param string               $raw
     * @param array<string, mixed> $auth
     * @return array{ok: bool, status: int, body: array<string, mixed>|null, error: string, error_code: string}
     */
    public static function put_bytes($hub_url, $route, $raw, array $auth = [])
    {
        $base = self::validate_hub_url($hub_url);
        if (is_wp_error($base)) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $base->get_error_message(), 'error_code' => $base->get_error_code()];
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/octet-stream',
            Protocol::HEADER_SITE_URL => home_url('/'),
            Protocol::HEADER_PROTOCOL => Protocol::PROTOCOL_VERSION,
        ];
        if (isset($auth['username'], $auth['password'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode((string) $auth['username'] . ':' . (string) $auth['password']);
        }

        $url = $base . '/index.php?rest_route=' . rawurlencode('/' . Protocol::REST_NAMESPACE . $route);
        $response = wp_remote_request($url, [
            'method' => 'PUT',
            'timeout' => Protocol::REQUEST_TIMEOUT_SECONDS * 3,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => $headers,
            'body' => (string) $raw,
            'limit_response_size' => 65536,
        ]);
        if (is_wp_error($response)) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $response->get_error_message(), 'error_code' => 'transport:' . $response->get_error_code()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $decoded = is_array($decoded) ? $decoded : null;

        return ['ok' => $status >= 200 && $status < 300 && $decoded !== null, 'status' => $status, 'body' => $decoded, 'error' => '', 'error_code' => ''];
    }

    /**
     * @param string                    $method
     * @param string                    $hub_url
     * @param string                    $route
     * @param array<string, mixed>      $query
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $auth
     * @return array{ok: bool, status: int, body: array<string, mixed>|null, error: string, error_code: string}
     */
    private static function request($method, $hub_url, $route, array $query, $body, array $auth)
    {
        $base = self::validate_hub_url($hub_url);
        if (is_wp_error($base)) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $base->get_error_message(), 'error_code' => $base->get_error_code()];
        }

        $headers = [
            'Accept' => 'application/json',
            Protocol::HEADER_SITE_URL => home_url('/'),
            Protocol::HEADER_PROTOCOL => Protocol::PROTOCOL_VERSION,
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json; charset=utf-8';
        }
        if (isset($auth['username'], $auth['password'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode((string) $auth['username'] . ':' . (string) $auth['password']);
        }

        // The rest_route query form works whether or not the hub has pretty permalinks and never triggers a redirect.
        $url = $base . '/index.php?rest_route=' . rawurlencode('/' . Protocol::REST_NAMESPACE . $route);
        foreach ($query as $key => $value) {
            $url .= '&' . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }
        $args = [
            'method' => $method,
            'timeout' => Protocol::REQUEST_TIMEOUT_SECONDS,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => $headers,
            'limit_response_size' => Protocol::MAX_BATCH_BYTES * 4,
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $response->get_error_message(), 'error_code' => 'transport:' . $response->get_error_code()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $decoded = is_array($decoded) ? $decoded : null;
        $ok = $status >= 200 && $status < 300 && $decoded !== null;

        return [
            'ok' => $ok,
            'status' => $status,
            'body' => $decoded,
            'error' => $ok ? '' : (string) ($decoded['message'] ?? ('HTTP ' . $status)),
            'error_code' => $ok ? '' : (string) ($decoded['code'] ?? ('http_' . $status)),
        ];
    }
}
