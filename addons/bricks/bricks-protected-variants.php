<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Protected_Variants
{
    public const OPTION_VARIANTS = 'dbvc_bricks_protected_variants';
    public const DEFAULT_SCOPE = 'site_local';
    public const MAX_VARIANTS = 2000;

    /**
     * @return void
     */
    public static function ensure_defaults()
    {
        add_option(self::OPTION_VARIANTS, []);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_variants()
    {
        $stored = get_option(self::OPTION_VARIANTS, []);
        if (is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }
        if (! is_array($stored)) {
            $stored = [];
        }

        $normalized = [];
        foreach ($stored as $row) {
            if (! is_array($row)) {
                continue;
            }
            $record = self::normalize_record($row);
            if (empty($record)) {
                continue;
            }
            $normalized[(string) $record['variant_id']] = $record;
        }

        if (count($normalized) > self::MAX_VARIANTS) {
            uasort($normalized, static function ($a, $b) {
                $left = is_array($a) ? strtotime((string) ($a['updated_at'] ?? '')) : false;
                $right = is_array($b) ? strtotime((string) ($b['updated_at'] ?? '')) : false;
                return (int) $left <=> (int) $right;
            });
            $normalized = array_slice($normalized, -self::MAX_VARIANTS, null, true);
        }

        if ($normalized !== $stored) {
            update_option(self::OPTION_VARIANTS, $normalized);
        }

        return $normalized;
    }

    /**
     * Build read-only protected-variant annotations for payload consumers.
     *
     * @param array<int, string> $artifact_uids
     * @return array<string, mixed>
     */
    public static function build_payload_annotations(array $artifact_uids = [])
    {
        $filter = [];
        foreach ($artifact_uids as $artifact_uid) {
            $clean = sanitize_text_field((string) $artifact_uid);
            if ($clean === '') {
                continue;
            }
            $filter[$clean] = true;
        }
        $use_filter = ! empty($filter);

        $lookup = [];
        $by_artifact_type = [];
        $by_scope = [];
        $variant_records = 0;

        foreach (self::get_variants() as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $artifact_uid = sanitize_text_field((string) ($variant['artifact_uid'] ?? ''));
            if ($artifact_uid === '') {
                continue;
            }
            if ($use_filter && ! isset($filter[$artifact_uid])) {
                continue;
            }

            $variant_id = sanitize_key((string) ($variant['variant_id'] ?? ''));
            $artifact_type = sanitize_key((string) ($variant['artifact_type'] ?? 'unknown'));
            if ($artifact_type === '') {
                $artifact_type = 'unknown';
            }
            $scope = self::sanitize_scope($variant['scope'] ?? self::DEFAULT_SCOPE);
            $updated_at = self::sanitize_iso8601((string) ($variant['updated_at'] ?? ''));
            $reason = sanitize_textarea_field((string) ($variant['reason'] ?? ''));

            if (! isset($lookup[$artifact_uid]) || ! is_array($lookup[$artifact_uid])) {
                $lookup[$artifact_uid] = [
                    'is_protected' => true,
                    'artifact_uid' => $artifact_uid,
                    'variant_count' => 0,
                    'variant_ids' => [],
                    'scopes' => [],
                    'latest_updated_at' => '',
                    'latest_reason' => '',
                ];
            }

            $entry = $lookup[$artifact_uid];
            $entry['variant_count'] = max(0, (int) ($entry['variant_count'] ?? 0)) + 1;

            $ids = isset($entry['variant_ids']) && is_array($entry['variant_ids']) ? $entry['variant_ids'] : [];
            if ($variant_id !== '' && ! in_array($variant_id, $ids, true)) {
                $ids[] = $variant_id;
            }
            $entry['variant_ids'] = $ids;

            $scopes = isset($entry['scopes']) && is_array($entry['scopes']) ? $entry['scopes'] : [];
            if ($scope !== '' && ! in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
            sort($scopes, SORT_STRING);
            $entry['scopes'] = $scopes;

            $current_latest = self::sanitize_iso8601((string) ($entry['latest_updated_at'] ?? ''));
            $replace_latest = ($current_latest === '' && $updated_at !== '')
                || ($updated_at !== '' && strtotime($updated_at) >= strtotime($current_latest));
            if ($replace_latest) {
                $entry['latest_updated_at'] = $updated_at;
                $entry['latest_reason'] = $reason;
            }

            $lookup[$artifact_uid] = $entry;

            if (! isset($by_artifact_type[$artifact_type])) {
                $by_artifact_type[$artifact_type] = 0;
            }
            if (! isset($by_scope[$scope])) {
                $by_scope[$scope] = 0;
            }
            $by_artifact_type[$artifact_type]++;
            $by_scope[$scope]++;
            $variant_records++;
        }

        ksort($lookup, SORT_STRING);
        ksort($by_artifact_type, SORT_STRING);
        ksort($by_scope, SORT_STRING);

        return [
            'variant_records' => $variant_records,
            'unique_artifact_uids' => count($lookup),
            'by_artifact_type' => $by_artifact_type,
            'by_scope' => $by_scope,
            'lookup' => $lookup,
        ];
    }

    /**
     * Return read-only annotation block for an artifact UID.
     *
     * @param string $artifact_uid
     * @param array<string, mixed> $annotations
     * @return array<string, mixed>
     */
    public static function get_artifact_annotation($artifact_uid, array $annotations = [])
    {
        $artifact_uid = sanitize_text_field((string) $artifact_uid);
        $lookup = isset($annotations['lookup']) && is_array($annotations['lookup']) ? $annotations['lookup'] : [];
        if ($artifact_uid !== '' && isset($lookup[$artifact_uid]) && is_array($lookup[$artifact_uid])) {
            return $lookup[$artifact_uid];
        }
        return [
            'is_protected' => false,
            'artifact_uid' => $artifact_uid,
            'variant_count' => 0,
            'variant_ids' => [],
            'scopes' => [],
            'latest_updated_at' => '',
            'latest_reason' => '',
        ];
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_list(\WP_REST_Request $request)
    {
        $role_error = self::assert_client_role();
        if ($role_error instanceof \WP_Error) {
            return $role_error;
        }

        $scope_filter = sanitize_key((string) $request->get_param('scope'));
        $type_filter = sanitize_key((string) $request->get_param('artifact_type'));

        $items = array_values(self::get_variants());
        if ($scope_filter !== '') {
            $items = array_values(array_filter($items, static function ($item) use ($scope_filter) {
                return is_array($item) && (($item['scope'] ?? '') === $scope_filter);
            }));
        }
        if ($type_filter !== '') {
            $items = array_values(array_filter($items, static function ($item) use ($type_filter) {
                return is_array($item) && (($item['artifact_type'] ?? '') === $type_filter);
            }));
        }

        usort($items, static function ($a, $b) {
            $left = is_array($a) ? strtotime((string) ($a['updated_at'] ?? '')) : false;
            $right = is_array($b) ? strtotime((string) ($b['updated_at'] ?? '')) : false;
            return (int) $right <=> (int) $left;
        });

        return rest_ensure_response([
            'ok' => true,
            'items' => $items,
            'summary' => self::build_summary($items),
        ]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_create(\WP_REST_Request $request)
    {
        $role_error = self::assert_client_role();
        if ($role_error instanceof \WP_Error) {
            return $role_error;
        }
        $read_only_error = self::assert_not_read_only();
        if ($read_only_error instanceof \WP_Error) {
            return $read_only_error;
        }

        $idempotency_key = self::require_idempotency_key($request);
        if ($idempotency_key instanceof \WP_Error) {
            return $idempotency_key;
        }
        $cached = self::get_idempotent_response('protected_variant_create', $idempotency_key);
        if ($cached instanceof \WP_REST_Response) {
            return $cached;
        }

        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }

        $artifact_uid = sanitize_text_field((string) ($params['artifact_uid'] ?? ''));
        if ($artifact_uid === '') {
            return new \WP_Error('dbvc_bricks_protected_variant_artifact_uid_required', 'artifact_uid is required.', ['status' => 400]);
        }

        $reason = sanitize_textarea_field((string) ($params['reason'] ?? ''));
        if ($reason === '') {
            return new \WP_Error('dbvc_bricks_protected_variant_reason_required', 'reason is required.', ['status' => 400]);
        }

        $scope = self::sanitize_scope($params['scope'] ?? self::DEFAULT_SCOPE);
        $variant_id = self::build_variant_id($artifact_uid, $scope);
        $variants = self::get_variants();
        $existing = isset($variants[$variant_id]) && is_array($variants[$variant_id]) ? $variants[$variant_id] : null;
        $now = gmdate('c');
        $actor_id = (int) get_current_user_id();

        $action = 'created';
        if ($existing !== null) {
            $variant = $existing;
            if ((string) ($existing['reason'] ?? '') !== $reason) {
                $variant['reason'] = $reason;
                $variant['updated_at'] = $now;
                $variant['updated_by'] = $actor_id;
                $variants[$variant_id] = $variant;
                self::persist_variants($variants);
                self::emit_audit('updated_reason', $variant, ['previous_reason' => (string) ($existing['reason'] ?? '')]);
                $action = 'updated_reason';
            } else {
                $action = 'unchanged';
            }
        } else {
            if (count($variants) >= self::MAX_VARIANTS) {
                return new \WP_Error(
                    'dbvc_bricks_protected_variant_limit_reached',
                    'Protected variant limit reached. Remove stale records before adding new entries.',
                    ['status' => 409]
                );
            }

            $artifact_type = self::sanitize_artifact_type($params['artifact_type'] ?? self::infer_artifact_type($artifact_uid));
            if ($artifact_type === '') {
                $artifact_type = 'unknown';
            }
            $label = sanitize_text_field((string) ($params['label'] ?? $artifact_uid));
            if ($label === '') {
                $label = $artifact_uid;
            }

            $variant = [
                'variant_id' => $variant_id,
                'artifact_uid' => $artifact_uid,
                'artifact_type' => $artifact_type,
                'label' => $label,
                'reason' => $reason,
                'scope' => $scope,
                'created_at' => $now,
                'created_by' => $actor_id,
                'updated_at' => $now,
                'updated_by' => $actor_id,
            ];
            $variants[$variant_id] = $variant;
            self::persist_variants($variants);
            self::emit_audit('created', $variant);
        }

        $response = [
            'ok' => true,
            'action' => $action,
            'variant' => $variant,
            'summary' => self::build_summary(array_values($variants)),
        ];
        self::store_idempotent_response('protected_variant_create', $idempotency_key, $response);

        return rest_ensure_response($response);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_update(\WP_REST_Request $request)
    {
        $role_error = self::assert_client_role();
        if ($role_error instanceof \WP_Error) {
            return $role_error;
        }
        $read_only_error = self::assert_not_read_only();
        if ($read_only_error instanceof \WP_Error) {
            return $read_only_error;
        }

        $idempotency_key = self::require_idempotency_key($request);
        if ($idempotency_key instanceof \WP_Error) {
            return $idempotency_key;
        }
        $cached = self::get_idempotent_response('protected_variant_update', $idempotency_key);
        if ($cached instanceof \WP_REST_Response) {
            return $cached;
        }

        $variant_id = sanitize_key((string) $request->get_param('variant_id'));
        if ($variant_id === '') {
            return new \WP_Error('dbvc_bricks_protected_variant_id_required', 'variant_id is required.', ['status' => 400]);
        }

        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }
        $reason = sanitize_textarea_field((string) ($params['reason'] ?? ''));
        if ($reason === '') {
            return new \WP_Error('dbvc_bricks_protected_variant_reason_required', 'reason is required.', ['status' => 400]);
        }

        $variants = self::get_variants();
        if (! isset($variants[$variant_id]) || ! is_array($variants[$variant_id])) {
            return new \WP_Error('dbvc_bricks_protected_variant_not_found', 'Protected variant not found.', ['status' => 404]);
        }

        $variant = $variants[$variant_id];
        $action = 'unchanged';
        if ((string) ($variant['reason'] ?? '') !== $reason) {
            $previous = (string) ($variant['reason'] ?? '');
            $variant['reason'] = $reason;
            $variant['updated_at'] = gmdate('c');
            $variant['updated_by'] = (int) get_current_user_id();
            $variants[$variant_id] = $variant;
            self::persist_variants($variants);
            self::emit_audit('updated_reason', $variant, ['previous_reason' => $previous]);
            $action = 'updated_reason';
        }

        $response = [
            'ok' => true,
            'action' => $action,
            'variant' => $variant,
            'summary' => self::build_summary(array_values($variants)),
        ];
        self::store_idempotent_response('protected_variant_update', $idempotency_key, $response);

        return rest_ensure_response($response);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_delete(\WP_REST_Request $request)
    {
        $role_error = self::assert_client_role();
        if ($role_error instanceof \WP_Error) {
            return $role_error;
        }
        $read_only_error = self::assert_not_read_only();
        if ($read_only_error instanceof \WP_Error) {
            return $read_only_error;
        }

        $idempotency_key = self::require_idempotency_key($request);
        if ($idempotency_key instanceof \WP_Error) {
            return $idempotency_key;
        }
        $cached = self::get_idempotent_response('protected_variant_delete', $idempotency_key);
        if ($cached instanceof \WP_REST_Response) {
            return $cached;
        }

        $variant_id = sanitize_key((string) $request->get_param('variant_id'));
        $confirm = ! empty($request->get_param('confirm_remove'));
        if ($variant_id === '' || ! $confirm) {
            return new \WP_Error(
                'dbvc_bricks_protected_variant_delete_invalid',
                'variant_id and confirm_remove=true are required.',
                ['status' => 400]
            );
        }

        $variants = self::get_variants();
        if (! isset($variants[$variant_id]) || ! is_array($variants[$variant_id])) {
            return new \WP_Error('dbvc_bricks_protected_variant_not_found', 'Protected variant not found.', ['status' => 404]);
        }

        $removed = $variants[$variant_id];
        unset($variants[$variant_id]);
        self::persist_variants($variants);
        self::emit_audit('removed', $removed);

        $response = [
            'ok' => true,
            'action' => 'removed',
            'variant_id' => $variant_id,
            'removed_variant' => $removed,
            'summary' => self::build_summary(array_values($variants)),
        ];
        self::store_idempotent_response('protected_variant_delete', $idempotency_key, $response);

        return rest_ensure_response($response);
    }

    /**
     * @param array<string, mixed> $variants
     * @return void
     */
    private static function persist_variants(array $variants)
    {
        update_option(self::OPTION_VARIANTS, $variants);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize_record(array $row)
    {
        $artifact_uid = sanitize_text_field((string) ($row['artifact_uid'] ?? ''));
        if ($artifact_uid === '') {
            return [];
        }

        $scope = self::sanitize_scope($row['scope'] ?? self::DEFAULT_SCOPE);
        $variant_id = sanitize_key((string) ($row['variant_id'] ?? ''));
        if ($variant_id === '') {
            $variant_id = self::build_variant_id($artifact_uid, $scope);
        }

        $artifact_type = self::sanitize_artifact_type($row['artifact_type'] ?? self::infer_artifact_type($artifact_uid));
        if ($artifact_type === '') {
            $artifact_type = 'unknown';
        }

        $label = sanitize_text_field((string) ($row['label'] ?? $artifact_uid));
        if ($label === '') {
            $label = $artifact_uid;
        }

        $reason = sanitize_textarea_field((string) ($row['reason'] ?? ''));
        $created_at = self::sanitize_iso8601((string) ($row['created_at'] ?? ''));
        $updated_at = self::sanitize_iso8601((string) ($row['updated_at'] ?? ''));
        if ($created_at === '') {
            $created_at = $updated_at !== '' ? $updated_at : gmdate('c');
        }
        if ($updated_at === '') {
            $updated_at = $created_at;
        }

        $created_by = absint($row['created_by'] ?? 0);
        $updated_by = absint($row['updated_by'] ?? 0);
        if ($created_by <= 0) {
            $created_by = $updated_by;
        }
        if ($updated_by <= 0) {
            $updated_by = $created_by;
        }

        return [
            'variant_id' => $variant_id,
            'artifact_uid' => $artifact_uid,
            'artifact_type' => $artifact_type,
            'label' => $label,
            'reason' => $reason,
            'scope' => $scope,
            'created_at' => $created_at,
            'created_by' => $created_by,
            'updated_at' => $updated_at,
            'updated_by' => $updated_by,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private static function build_summary(array $items)
    {
        $by_type = [];
        $by_scope = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $artifact_type = sanitize_key((string) ($item['artifact_type'] ?? 'unknown'));
            if ($artifact_type === '') {
                $artifact_type = 'unknown';
            }
            $scope = self::sanitize_scope($item['scope'] ?? self::DEFAULT_SCOPE);
            if (! isset($by_type[$artifact_type])) {
                $by_type[$artifact_type] = 0;
            }
            if (! isset($by_scope[$scope])) {
                $by_scope[$scope] = 0;
            }
            $by_type[$artifact_type]++;
            $by_scope[$scope]++;
        }

        ksort($by_type);
        ksort($by_scope);

        return [
            'total' => count($items),
            'by_artifact_type' => $by_type,
            'by_scope' => $by_scope,
        ];
    }

    /**
     * @param string $event
     * @param array<string, mixed> $variant
     * @param array<string, mixed> $context
     * @return void
     */
    private static function emit_audit($event, array $variant, array $context = [])
    {
        $event = sanitize_key((string) $event);
        if (! in_array($event, ['created', 'updated_reason', 'removed'], true)) {
            return;
        }

        $payload = [
            'event' => $event,
            'variant_id' => sanitize_key((string) ($variant['variant_id'] ?? '')),
            'artifact_uid' => sanitize_text_field((string) ($variant['artifact_uid'] ?? '')),
            'artifact_type' => sanitize_key((string) ($variant['artifact_type'] ?? '')),
            'scope' => self::sanitize_scope($variant['scope'] ?? self::DEFAULT_SCOPE),
            'actor_id' => (int) get_current_user_id(),
            'at' => gmdate('c'),
            'context' => DBVC_Bricks_Addon::sanitize_diagnostics_payload($context),
        ];

        do_action('dbvc_bricks_audit_event', 'protected_variant_' . $event, $payload);
        do_action('dbvc_bricks_protected_variant_' . $event, $payload);

        if (class_exists('DBVC_Database') && method_exists('DBVC_Database', 'log_activity')) {
            $encoded = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            DBVC_Database::log_activity('dbvc_bricks_protected_variant_' . $event, is_string($encoded) ? $encoded : '{}');
        }
    }

    /**
     * @param \WP_REST_Request $request
     * @return string|\WP_Error
     */
    private static function require_idempotency_key(\WP_REST_Request $request)
    {
        if (! class_exists('DBVC_Bricks_Idempotency')) {
            return '';
        }
        $idempotency_key = DBVC_Bricks_Idempotency::extract_key($request);
        if ($idempotency_key === '') {
            return new \WP_Error('dbvc_bricks_idempotency_required', 'Idempotency-Key is required.', ['status' => 400]);
        }
        return $idempotency_key;
    }

    /**
     * @param string $scope
     * @param string $idempotency_key
     * @return \WP_REST_Response|null
     */
    private static function get_idempotent_response($scope, $idempotency_key)
    {
        if (! class_exists('DBVC_Bricks_Idempotency') || $idempotency_key === '') {
            return null;
        }
        $existing = DBVC_Bricks_Idempotency::get($scope, $idempotency_key);
        if (is_array($existing) && isset($existing['response']) && is_array($existing['response'])) {
            return rest_ensure_response($existing['response']);
        }
        return null;
    }

    /**
     * @param string $scope
     * @param string $idempotency_key
     * @param array<string, mixed> $response
     * @return void
     */
    private static function store_idempotent_response($scope, $idempotency_key, array $response)
    {
        if (! class_exists('DBVC_Bricks_Idempotency') || $idempotency_key === '') {
            return;
        }
        DBVC_Bricks_Idempotency::put($scope, $idempotency_key, $response);
    }

    /**
     * @return \WP_Error|null
     */
    private static function assert_client_role()
    {
        if (! class_exists('DBVC_Bricks_Addon') || DBVC_Bricks_Addon::get_role_mode() !== 'client') {
            return new \WP_Error(
                'dbvc_bricks_protected_variant_role_invalid',
                'Protected variant endpoints are client-only.',
                ['status' => 400]
            );
        }
        return null;
    }

    /**
     * @return \WP_Error|null
     */
    private static function assert_not_read_only()
    {
        if (class_exists('DBVC_Bricks_Addon') && DBVC_Bricks_Addon::get_bool_setting('dbvc_bricks_read_only', false)) {
            return new \WP_Error(
                'dbvc_bricks_protected_variant_read_only',
                'Protected variant changes are blocked in read-only mode.',
                ['status' => 403]
            );
        }
        return null;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function sanitize_scope($value)
    {
        $scope = sanitize_key((string) $value);
        return $scope !== '' ? $scope : self::DEFAULT_SCOPE;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function sanitize_artifact_type($value)
    {
        return sanitize_key((string) $value);
    }

    /**
     * @param string $artifact_uid
     * @return string
     */
    private static function infer_artifact_type($artifact_uid)
    {
        $artifact_uid = strtolower((string) $artifact_uid);
        if (strpos($artifact_uid, 'option:') === 0) {
            return 'option';
        }
        if (strpos($artifact_uid, 'template:') === 0 || strpos($artifact_uid, 'entity:') === 0 || strpos($artifact_uid, 'post:') === 0) {
            return 'bricks_template';
        }
        return 'unknown';
    }

    /**
     * @param string $artifact_uid
     * @param string $scope
     * @return string
     */
    private static function build_variant_id($artifact_uid, $scope)
    {
        $artifact_uid = strtolower(trim((string) $artifact_uid));
        $scope = strtolower(trim((string) $scope));
        return 'pv_' . substr(sha1($artifact_uid . '|' . $scope), 0, 20);
    }

    /**
     * @param string $value
     * @return string
     */
    private static function sanitize_iso8601($value)
    {
        $value = sanitize_text_field((string) $value);
        if ($value === '') {
            return '';
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return '';
        }
        return gmdate('c', $timestamp);
    }
}
