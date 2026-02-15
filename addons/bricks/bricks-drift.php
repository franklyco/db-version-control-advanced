<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Drift
{
    /**
     * Scan drift between target manifest and local artifacts.
     *
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $local_artifacts
     * @param array<string, mixed> $options
     * @return array<string, mixed>|\WP_Error
     */
    public static function scan(array $manifest, array $local_artifacts = [], array $options = [])
    {
        if (! empty($options['write']) || ! empty($options['mutate']) || ! empty($options['apply'])) {
            return new \WP_Error('dbvc_bricks_read_only', 'Drift scan is read-only and does not allow write actions.', ['status' => 400]);
        }

        $entries = [];
        if (! empty($manifest['artifacts']) && is_array($manifest['artifacts'])) {
            $entries = $manifest['artifacts'];
        }

        $max_changes = isset($options['max_changes']) ? max(1, (int) $options['max_changes']) : 25;
        $status_overrides = isset($options['status_overrides']) && is_array($options['status_overrides'])
            ? $options['status_overrides']
            : [];

        $counts = [
            'clean' => 0,
            'diverged' => 0,
            'overridden' => 0,
            'pending_review' => 0,
        ];
        $artifacts = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $artifact_uid = isset($entry['artifact_uid']) ? (string) $entry['artifact_uid'] : '';
            $artifact_type = isset($entry['artifact_type']) ? (string) $entry['artifact_type'] : '';
            $target_hash = isset($entry['hash']) ? (string) $entry['hash'] : '';
            $target_payload = $entry['payload'] ?? null;

            $local = isset($local_artifacts[$artifact_uid]) && is_array($local_artifacts[$artifact_uid])
                ? $local_artifacts[$artifact_uid]
                : [];
            $local_hash = isset($local['hash']) ? (string) $local['hash'] : '';
            $local_payload = $local['payload'] ?? null;

            if ($local_hash === '' && $local_payload !== null) {
                $canonical_local = DBVC_Bricks_Artifacts::canonicalize($artifact_type, $local_payload);
                $local_hash = DBVC_Bricks_Artifacts::fingerprint($canonical_local);
            }
            if ($target_hash === '' && $target_payload !== null) {
                $canonical_target = DBVC_Bricks_Artifacts::canonicalize($artifact_type, $target_payload);
                $target_hash = DBVC_Bricks_Artifacts::fingerprint($canonical_target);
            }

            $status = ($local_hash !== '' && $target_hash !== '' && hash_equals($local_hash, $target_hash))
                ? 'CLEAN'
                : 'DIVERGED';

            if (isset($status_overrides[$artifact_uid])) {
                $override = strtoupper((string) $status_overrides[$artifact_uid]);
                if (in_array($override, ['OVERRIDDEN', 'PENDING_REVIEW'], true)) {
                    $status = $override;
                }
            }

            $summary = self::build_diff_summary($local_payload, $target_payload, $max_changes);

            if ($status === 'CLEAN') {
                $counts['clean']++;
            } elseif ($status === 'OVERRIDDEN') {
                $counts['overridden']++;
            } elseif ($status === 'PENDING_REVIEW') {
                $counts['pending_review']++;
            } else {
                $counts['diverged']++;
            }

            $row = [
                'artifact_uid' => $artifact_uid,
                'artifact_type' => $artifact_type,
                'status' => $status,
                'local_hash' => $local_hash,
                'golden_hash' => $target_hash,
                'diff_summary' => $summary,
            ];
            $row = apply_filters('dbvc_bricks_diff_row_data', $row, $entry, $local, $options);
            if (! is_array($row)) {
                continue;
            }
            $artifacts[] = $row;
        }

        return [
            'package_id' => isset($manifest['package_id']) ? (string) $manifest['package_id'] : '',
            'counts' => $counts,
            'artifacts' => $artifacts,
        ];
    }

    /**
     * REST callback for drift scan.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_scan(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }
        $manifest = isset($params['manifest']) && is_array($params['manifest']) ? $params['manifest'] : [];
        if (class_exists('DBVC_Bricks_Addon')) {
            $manifest = DBVC_Bricks_Addon::normalize_manifest_payload($manifest);
        }
        $local_artifacts = isset($params['local_artifacts']) && is_array($params['local_artifacts']) ? $params['local_artifacts'] : [];
        if (empty($local_artifacts) && class_exists('DBVC_Bricks_Addon')) {
            $local_artifacts = DBVC_Bricks_Addon::resolve_local_artifacts_from_manifest($manifest);
        }
        $options = isset($params['options']) && is_array($params['options']) ? $params['options'] : [];

        $result = self::scan($manifest, $local_artifacts, $options);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response($result);
    }

    /**
     * @param mixed $local_payload
     * @param mixed $target_payload
     * @param int $max_changes
     * @return array<string, mixed>
     */
    private static function build_diff_summary($local_payload, $target_payload, $max_changes)
    {
        $local_flat = self::flatten_payload($local_payload);
        $target_flat = self::flatten_payload($target_payload);

        $all_paths = array_values(array_unique(array_merge(array_keys($local_flat), array_keys($target_flat))));
        sort($all_paths, SORT_STRING);

        $changes = [];
        $total = 0;
        foreach ($all_paths as $path) {
            $local_exists = array_key_exists($path, $local_flat);
            $target_exists = array_key_exists($path, $target_flat);
            $local_value = $local_exists ? $local_flat[$path] : null;
            $target_value = $target_exists ? $target_flat[$path] : null;

            if ($local_exists && $target_exists && $local_value === $target_value) {
                continue;
            }

            $total++;
            if (count($changes) < $max_changes) {
                $change_type = 'modified';
                if (! $local_exists) {
                    $change_type = 'added';
                } elseif (! $target_exists) {
                    $change_type = 'removed';
                }
                $changes[] = [
                    'path' => $path,
                    'type' => $change_type,
                ];
            }
        }

        return [
            'total' => $total,
            'changes' => $changes,
            'truncated' => $total > count($changes),
            'raw_available' => $total > count($changes),
        ];
    }

    /**
     * @param mixed $payload
     * @param string $path
     * @param array<string, string> $flat
     * @return array<string, string>
     */
    private static function flatten_payload($payload, $path = '', array $flat = [])
    {
        if (is_array($payload)) {
            foreach ($payload as $key => $value) {
                $segment = is_int($key) ? (string) $key : (string) $key;
                $next_path = $path === '' ? $segment : $path . '.' . $segment;
                $flat = self::flatten_payload($value, $next_path, $flat);
            }
            if ($path !== '' && empty($payload)) {
                $flat[$path] = '[]';
            }
            return $flat;
        }

        $encoded = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $flat[$path] = is_string($encoded) ? $encoded : '';
        return $flat;
    }
}
