<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Idempotency
{
    public const OPTION_STORE = 'dbvc_bricks_idempotency_store';

    /**
     * @param string $scope
     * @param string $idempotency_key
     * @return array<string, mixed>|null
     */
    public static function get($scope, $idempotency_key)
    {
        $store = get_option(self::OPTION_STORE, []);
        if (! is_array($store)) {
            return null;
        }
        $bucket = $store[(string) $scope] ?? [];
        if (! is_array($bucket)) {
            return null;
        }
        $record = $bucket[(string) $idempotency_key] ?? null;
        return is_array($record) ? $record : null;
    }

    /**
     * @param string $scope
     * @param string $idempotency_key
     * @param array<string, mixed> $response
     * @return void
     */
    public static function put($scope, $idempotency_key, array $response)
    {
        $store = get_option(self::OPTION_STORE, []);
        if (! is_array($store)) {
            $store = [];
        }
        if (! isset($store[$scope]) || ! is_array($store[$scope])) {
            $store[$scope] = [];
        }

        $store[$scope][(string) $idempotency_key] = [
            'stored_at' => gmdate('c'),
            'response' => $response,
        ];

        // Keep bounded size per scope.
        if (count($store[$scope]) > 200) {
            $keys = array_keys($store[$scope]);
            $remove = array_slice($keys, 0, count($store[$scope]) - 200);
            foreach ($remove as $key) {
                unset($store[$scope][$key]);
            }
        }

        update_option(self::OPTION_STORE, $store);
    }

    /**
     * @param \WP_REST_Request $request
     * @return string
     */
    public static function extract_key(\WP_REST_Request $request)
    {
        $header = $request->get_header('Idempotency-Key');
        if (is_string($header) && $header !== '') {
            return sanitize_text_field($header);
        }
        $param = $request->get_param('idempotency_key');
        return is_string($param) ? sanitize_text_field($param) : '';
    }
}
