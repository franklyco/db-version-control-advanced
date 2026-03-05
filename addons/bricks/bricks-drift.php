<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Drift
{
    private const OPTION_DIFF_PATH_RULES = 'dbvc_bricks_diff_path_rules';
    private const OPTION_ARTIFACT_IGNORE_RULES = 'dbvc_bricks_artifact_ignore_rules';
    private const OPTION_ARTIFACT_MASK_RULES = 'dbvc_bricks_artifact_mask_rules';
    private const OPTION_META_IGNORE_RULES = 'dbvc_bricks_meta_ignore_rules';
    private const OPTION_META_MASK_RULES = 'dbvc_bricks_meta_mask_rules';

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

            $rules = self::resolve_path_rules($artifact_uid, $artifact_type);
            $local_compare_payload = self::apply_payload_path_rules($local_payload, $rules);
            $target_compare_payload = self::apply_payload_path_rules($target_payload, $rules);

            if ($local_hash === '' && $local_compare_payload !== null) {
                $canonical_local = DBVC_Bricks_Artifacts::canonicalize($artifact_type, $local_compare_payload);
                $local_hash = DBVC_Bricks_Artifacts::fingerprint($canonical_local);
            }
            if ($target_hash === '' && $target_compare_payload !== null) {
                $canonical_target = DBVC_Bricks_Artifacts::canonicalize($artifact_type, $target_compare_payload);
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

            $summary = self::build_diff_summary($local_compare_payload, $target_compare_payload, $max_changes);

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
                'artifact_label' => self::derive_artifact_label($artifact_uid, $artifact_type, $local_payload, $target_payload),
                'status' => $status,
                'local_hash' => $local_hash,
                'golden_hash' => $target_hash,
                'diff_summary' => $summary,
            ];
            if (! empty($options['include_raw_compare'])) {
                $row['raw_compare'] = self::build_raw_compare_payload(
                    $local_compare_payload,
                    $target_compare_payload,
                    $rules,
                    isset($options['max_raw_compare_bytes']) ? (int) $options['max_raw_compare_bytes'] : 32768
                );
            }
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
     * Return a single artifact compare payload with rules applied.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_compare(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }
        $manifest = isset($params['manifest']) && is_array($params['manifest']) ? $params['manifest'] : [];
        if (class_exists('DBVC_Bricks_Addon')) {
            $manifest = DBVC_Bricks_Addon::normalize_manifest_payload($manifest);
        }
        $artifact_uid = sanitize_text_field((string) ($params['artifact_uid'] ?? ''));
        if ($artifact_uid === '') {
            return new \WP_Error('dbvc_bricks_compare_artifact_required', 'artifact_uid is required.', ['status' => 400]);
        }
        $local_artifacts = isset($params['local_artifacts']) && is_array($params['local_artifacts']) ? $params['local_artifacts'] : [];
        if (empty($local_artifacts) && class_exists('DBVC_Bricks_Addon')) {
            $local_artifacts = DBVC_Bricks_Addon::resolve_local_artifacts_from_manifest($manifest);
        }

        $entry = null;
        foreach ((array) ($manifest['artifacts'] ?? []) as $item) {
            if (is_array($item) && (string) ($item['artifact_uid'] ?? '') === $artifact_uid) {
                $entry = $item;
                break;
            }
        }
        if (! is_array($entry)) {
            return new \WP_Error('dbvc_bricks_compare_artifact_missing', 'Artifact not found in manifest.', ['status' => 404]);
        }

        $artifact_type = (string) ($entry['artifact_type'] ?? '');
        $local = isset($local_artifacts[$artifact_uid]) && is_array($local_artifacts[$artifact_uid])
            ? $local_artifacts[$artifact_uid]
            : [];
        $local_payload = $local['payload'] ?? null;
        $target_payload = $entry['payload'] ?? null;
        $rules = self::resolve_path_rules($artifact_uid, $artifact_type);
        $local_compare_payload = self::apply_payload_path_rules($local_payload, $rules);
        $target_compare_payload = self::apply_payload_path_rules($target_payload, $rules);
        $summary = self::build_diff_summary($local_compare_payload, $target_compare_payload, 200);

        return rest_ensure_response([
            'artifact_uid' => $artifact_uid,
            'artifact_type' => $artifact_type,
            'diff_summary' => $summary,
            'raw_compare' => self::build_raw_compare_payload($local_compare_payload, $target_compare_payload, $rules, 65536),
        ]);
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
     * @param mixed $local_payload
     * @param mixed $target_payload
     * @param array<string, array<int, string>> $rules
     * @param int $max_bytes
     * @return array<string, mixed>
     */
    private static function build_raw_compare_payload($local_payload, $target_payload, array $rules, $max_bytes)
    {
        $max_bytes = max(1024, $max_bytes);
        return [
            'local' => self::encode_payload_for_view($local_payload, $max_bytes),
            'golden' => self::encode_payload_for_view($target_payload, $max_bytes),
            'rules_applied' => [
                'ignore_paths' => array_values($rules['ignore_paths']),
                'mask_paths' => array_values($rules['mask_paths']),
            ],
        ];
    }

    /**
     * @param mixed $payload
     * @param int $max_bytes
     * @return array<string, mixed>
     */
    private static function encode_payload_for_view($payload, $max_bytes)
    {
        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $raw = is_string($json) ? $json : '';
        $bytes = strlen($raw);
        if ($bytes <= $max_bytes) {
            return [
                'json' => $raw,
                'bytes' => $bytes,
                'truncated' => false,
            ];
        }
        return [
            'json' => substr($raw, 0, $max_bytes),
            'bytes' => $bytes,
            'truncated' => true,
        ];
    }

    /**
     * @param string $artifact_uid
     * @param string $artifact_type
     * @return array<string, array<int, string>>
     */
    private static function resolve_path_rules($artifact_uid, $artifact_type)
    {
        $base = self::decode_rules_option(self::OPTION_DIFF_PATH_RULES);
        $artifact_ignore = self::decode_rules_option(self::OPTION_ARTIFACT_IGNORE_RULES);
        $artifact_mask = self::decode_rules_option(self::OPTION_ARTIFACT_MASK_RULES);
        $meta_ignore = self::decode_rules_option(self::OPTION_META_IGNORE_RULES);
        $meta_mask = self::decode_rules_option(self::OPTION_META_MASK_RULES);

        $ignore = array_values(array_unique(array_merge(
            self::collect_rule_paths($base['ignore'] ?? [], $artifact_uid, $artifact_type),
            self::collect_rule_paths($artifact_ignore, $artifact_uid, $artifact_type),
            self::normalize_meta_rule_paths(self::collect_rule_paths($meta_ignore, $artifact_uid, $artifact_type))
        )));
        $mask = array_values(array_unique(array_merge(
            self::collect_rule_paths($base['mask'] ?? [], $artifact_uid, $artifact_type),
            self::collect_rule_paths($artifact_mask, $artifact_uid, $artifact_type),
            self::normalize_meta_rule_paths(self::collect_rule_paths($meta_mask, $artifact_uid, $artifact_type))
        )));

        return [
            'ignore_paths' => $ignore,
            'mask_paths' => $mask,
        ];
    }

    /**
     * @param string $option_key
     * @return array<string, mixed>
     */
    private static function decode_rules_option($option_key)
    {
        $raw = get_option($option_key, '{}');
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /**
     * @param array<int, string> $paths
     * @return array<int, string>
     */
    private static function normalize_meta_rule_paths(array $paths)
    {
        $out = [];
        foreach ($paths as $path) {
            $clean = trim((string) $path);
            if ($clean === '') {
                continue;
            }
            if (! str_starts_with($clean, 'meta.')) {
                $clean = 'meta.' . ltrim($clean, '.');
            }
            $out[] = $clean;
        }
        return array_values(array_unique($out));
    }

    /**
     * @param mixed $source
     * @param string $artifact_uid
     * @param string $artifact_type
     * @return array<int, string>
     */
    private static function collect_rule_paths($source, $artifact_uid, $artifact_type)
    {
        if (! is_array($source)) {
            return [];
        }
        $candidates = ['*', $artifact_type, $artifact_uid];
        $paths = [];
        foreach ($candidates as $key) {
            if (! isset($source[$key]) || ! is_array($source[$key])) {
                continue;
            }
            foreach ($source[$key] as $path) {
                $normalized = self::normalize_rule_path($path);
                if ($normalized !== '') {
                    $paths[] = $normalized;
                }
            }
        }
        return array_values(array_unique($paths));
    }

    /**
     * @param mixed $payload
     * @param array<string, array<int, string>> $rules
     * @return mixed
     */
    private static function apply_payload_path_rules($payload, array $rules)
    {
        if (! is_array($payload)) {
            return $payload;
        }
        $next = $payload;
        foreach ((array) ($rules['mask_paths'] ?? []) as $path) {
            self::set_path_value($next, $path, '[masked]');
        }
        foreach ((array) ($rules['ignore_paths'] ?? []) as $path) {
            self::unset_path_value($next, $path);
        }
        return $next;
    }

    /**
     * @param mixed $path
     * @return string
     */
    private static function normalize_rule_path($path)
    {
        $path = trim((string) $path);
        return ltrim($path, '.');
    }

    /**
     * @param array<string|int, mixed> $payload
     * @param string $path
     * @param mixed $value
     * @return void
     */
    private static function set_path_value(array &$payload, $path, $value)
    {
        $segments = self::parse_path_segments($path);
        if (empty($segments)) {
            return;
        }
        $cursor = &$payload;
        $last = array_pop($segments);
        foreach ($segments as $segment) {
            if (! is_array($cursor)) {
                return;
            }
            if (! array_key_exists($segment, $cursor) || ! is_array($cursor[$segment])) {
                return;
            }
            $cursor = &$cursor[$segment];
        }
        if (! is_array($cursor)) {
            return;
        }
        if (! array_key_exists($last, $cursor)) {
            return;
        }
        $cursor[$last] = $value;
    }

    /**
     * @param array<string|int, mixed> $payload
     * @param string $path
     * @return void
     */
    private static function unset_path_value(array &$payload, $path)
    {
        $segments = self::parse_path_segments($path);
        if (empty($segments)) {
            return;
        }
        $cursor = &$payload;
        $last = array_pop($segments);
        foreach ($segments as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor) || ! is_array($cursor[$segment])) {
                return;
            }
            $cursor = &$cursor[$segment];
        }
        if (! is_array($cursor) || ! array_key_exists($last, $cursor)) {
            return;
        }
        unset($cursor[$last]);
    }

    /**
     * @param string $path
     * @return array<int, string|int>
     */
    private static function parse_path_segments($path)
    {
        $path = self::normalize_rule_path($path);
        if ($path === '') {
            return [];
        }
        $parts = explode('.', $path);
        $segments = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (ctype_digit($part)) {
                $segments[] = (int) $part;
            } else {
                $segments[] = $part;
            }
        }
        return $segments;
    }

    /**
     * @param string $artifact_uid
     * @param string $artifact_type
     * @param mixed $local_payload
     * @param mixed $target_payload
     * @return string
     */
    private static function derive_artifact_label($artifact_uid, $artifact_type, $local_payload, $target_payload)
    {
        if ($artifact_type === 'bricks_template') {
            $title = '';
            if (is_array($local_payload) && isset($local_payload['post_title'])) {
                $title = sanitize_text_field((string) $local_payload['post_title']);
            }
            if ($title === '' && is_array($target_payload) && isset($target_payload['post_title'])) {
                $title = sanitize_text_field((string) $target_payload['post_title']);
            }
            if ($title !== '') {
                return $title;
            }
        }

        if (str_starts_with($artifact_uid, 'option:')) {
            return substr($artifact_uid, 7);
        }

        return $artifact_uid;
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
