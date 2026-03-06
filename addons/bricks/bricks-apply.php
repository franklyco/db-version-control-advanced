<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Apply
{
    public const OPTION_RESTORE_POINTS = 'dbvc_bricks_restore_points';

    /**
     * Build apply plan and optionally execute.
     *
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $selection
     * @param array<string, mixed> $options
     * @return array<string, mixed>|\WP_Error
     */
    public static function apply_package(array $manifest, array $selection = [], array $options = [])
    {
        $receipt_id = sanitize_text_field((string) ($options['receipt_id'] ?? ($manifest['receipt_id'] ?? '')));
        if (DBVC_Bricks_Addon::get_bool_setting('dbvc_bricks_read_only')) {
            return new \WP_Error('dbvc_bricks_read_only_mode', 'Bricks apply is disabled while read-only mode is active.', ['status' => 400]);
        }

        $plan = self::build_apply_plan($manifest, $selection, $options);
        if (is_wp_error($plan)) {
            return $plan;
        }

        if (! empty($options['dry_run'])) {
            return [
                'dry_run' => true,
                'receipt_id' => $receipt_id,
                'plan' => $plan,
            ];
        }

        $restore_id = null;
        if (DBVC_Bricks_Addon::get_bool_setting('dbvc_bricks_restore_before_apply', true)) {
            $restore = self::create_restore_point($plan);
            if (is_wp_error($restore)) {
                return $restore;
            }
            $restore_id = $restore['restore_id'];
        }

        $applied = [
            'options' => [],
            'entities' => [],
            'skipped' => $plan['skipped'],
        ];
        $verification = [];

        foreach ($plan['option_artifacts'] as $item) {
            update_option($item['option_key'], $item['payload']);
            $applied['options'][] = $item['artifact_uid'];
            $canonical = DBVC_Bricks_Artifacts::canonicalize($item['artifact_type'], get_option($item['option_key']));
            $verification[$item['artifact_uid']] = DBVC_Bricks_Artifacts::hash_diagnostics(
                (string) $item['target_hash'],
                DBVC_Bricks_Artifacts::fingerprint($canonical)
            );
        }

        foreach ($plan['entity_artifacts'] as $item) {
            $entity_result = self::apply_entity_artifact($item);
            if (is_wp_error($entity_result)) {
                if ($restore_id) {
                    self::rollback_restore_point($restore_id);
                }
                return $entity_result;
            }
            $applied['entities'][] = $item['artifact_uid'];
            $current = self::read_entity_payload($item);
            $canonical = DBVC_Bricks_Artifacts::canonicalize($item['artifact_type'], $current);
            $verification[$item['artifact_uid']] = DBVC_Bricks_Artifacts::hash_diagnostics(
                (string) $item['target_hash'],
                DBVC_Bricks_Artifacts::fingerprint($canonical)
            );
        }

        foreach ($verification as $artifact_uid => $diag) {
            if (empty($diag['match'])) {
                if ($restore_id) {
                    self::rollback_restore_point($restore_id);
                }
                self::log_audit('rollback', [
                    'artifact_uid' => $artifact_uid,
                    'reason' => 'verification_failed',
                    'restore_id' => $restore_id,
                ]);
                return new \WP_Error('dbvc_bricks_verification_failed', 'Post-apply verification failed; rollback applied.', ['status' => 500]);
            }
        }

        return [
            'dry_run' => false,
            'receipt_id' => $receipt_id,
            'restore_id' => $restore_id,
            'applied' => $applied,
            'verification' => $verification,
        ];
    }

    /**
     * Build deterministic apply plan.
     *
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $selection
     * @param array<string, mixed> $options
     * @return array<string, mixed>|\WP_Error
     */
    public static function build_apply_plan(array $manifest, array $selection = [], array $options = [])
    {
        $artifacts = isset($manifest['artifacts']) && is_array($manifest['artifacts']) ? $manifest['artifacts'] : [];
        if (empty($artifacts)) {
            return new \WP_Error('dbvc_bricks_empty_manifest', 'Manifest must include artifacts for apply planning.', ['status' => 400]);
        }

        $selected_uids = isset($selection['artifact_uids']) && is_array($selection['artifact_uids'])
            ? array_map('strval', $selection['artifact_uids'])
            : [];
        $use_selection = ! empty($selected_uids);

        $plan = [
            'manifest_id' => isset($manifest['package_id']) ? (string) $manifest['package_id'] : '',
            'option_artifacts' => [],
            'entity_artifacts' => [],
            'skipped' => [],
        ];
        $allow_destructive = ! empty($options['allow_destructive']);
        $block_delete = DBVC_Bricks_Addon::get_bool_setting('dbvc_bricks_policy_block_delete', true);

        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }

            $artifact_uid = isset($artifact['artifact_uid']) ? (string) $artifact['artifact_uid'] : '';
            $artifact_type = isset($artifact['artifact_type']) ? (string) $artifact['artifact_type'] : '';
            if ($artifact_uid === '' || $artifact_type === '') {
                continue;
            }
            if ($use_selection && ! in_array($artifact_uid, $selected_uids, true)) {
                $plan['skipped'][] = ['artifact_uid' => $artifact_uid, 'reason' => 'not_selected'];
                continue;
            }

            $payload = $artifact['payload'] ?? null;
            $is_destructive = $payload === null || (is_array($payload) && ! empty($payload['__delete']));
            if ($is_destructive && $block_delete && ! $allow_destructive) {
                return new \WP_Error('dbvc_bricks_destructive_blocked', 'Destructive artifact apply requires explicit approval.', ['status' => 400]);
            }

            $policy = self::resolve_policy($artifact_uid, $artifact_type);
            if ($policy === 'IGNORE') {
                $plan['skipped'][] = ['artifact_uid' => $artifact_uid, 'reason' => 'policy_ignore'];
                continue;
            }

            $target_hash = isset($artifact['hash']) ? (string) $artifact['hash'] : '';
            if ($target_hash === '') {
                $canonical = DBVC_Bricks_Artifacts::canonicalize($artifact_type, $payload);
                $target_hash = DBVC_Bricks_Artifacts::fingerprint($canonical);
            }

            $plan_item = [
                'artifact_uid' => $artifact_uid,
                'artifact_type' => $artifact_type,
                'payload' => $payload,
                'target_hash' => $target_hash,
                'policy' => $policy,
            ];

            if (str_starts_with($artifact_uid, 'option:')) {
                $plan_item['option_key'] = substr($artifact_uid, 7);
                $plan['option_artifacts'][] = $plan_item;
            } else {
                $plan['entity_artifacts'][] = $plan_item;
            }
        }

        return $plan;
    }

    /**
     * Create restore point.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>|\WP_Error
     */
    public static function create_restore_point(array $plan)
    {
        $restore_points = get_option(self::OPTION_RESTORE_POINTS, []);
        if (! is_array($restore_points)) {
            $restore_points = [];
        }

        $option_state = [];
        foreach ((array) $plan['option_artifacts'] as $item) {
            $option_key = (string) ($item['option_key'] ?? '');
            if ($option_key === '') {
                continue;
            }
            $option_state[$option_key] = get_option($option_key, null);
        }

        $entity_state = [];
        foreach ((array) $plan['entity_artifacts'] as $item) {
            $entity_state[] = self::read_entity_payload($item);
        }

        $restore_id = 'bricks-restore-' . wp_generate_password(12, false, false);
        $restore_points[$restore_id] = [
            'restore_id' => $restore_id,
            'created_at' => gmdate('c'),
            'options' => $option_state,
            'entities' => $entity_state,
        ];

        $retention = DBVC_Bricks_Addon::get_int_setting('dbvc_bricks_restore_retention', 20);
        if ($retention < 1) {
            $retention = 1;
        }
        if (count($restore_points) > $retention) {
            $keys = array_keys($restore_points);
            $remove = array_slice($keys, 0, count($restore_points) - $retention);
            foreach ($remove as $old) {
                unset($restore_points[$old]);
            }
        }

        update_option(self::OPTION_RESTORE_POINTS, $restore_points);
        return ['restore_id' => $restore_id];
    }

    /**
     * Rollback restore point.
     *
     * @param string $restore_id
     * @return array<string, mixed>|\WP_Error
     */
    public static function rollback_restore_point($restore_id)
    {
        $restore_points = get_option(self::OPTION_RESTORE_POINTS, []);
        if (! is_array($restore_points) || ! isset($restore_points[$restore_id])) {
            return new \WP_Error('dbvc_bricks_restore_missing', 'Restore point not found.', ['status' => 404]);
        }

        $snapshot = $restore_points[$restore_id];
        foreach ((array) ($snapshot['options'] ?? []) as $option_key => $value) {
            if ($value === null) {
                delete_option($option_key);
            } else {
                update_option($option_key, $value);
            }
        }

        foreach ((array) ($snapshot['entities'] ?? []) as $entity_payload) {
            if (! is_array($entity_payload) || empty($entity_payload['ID'])) {
                continue;
            }
            wp_update_post([
                'ID' => (int) $entity_payload['ID'],
                'post_title' => $entity_payload['post_title'] ?? '',
                'post_status' => $entity_payload['post_status'] ?? 'draft',
                'post_content' => $entity_payload['post_content'] ?? '',
            ]);
        }

        self::log_audit('rollback', ['restore_id' => $restore_id]);
        return ['restore_id' => $restore_id, 'rolled_back' => true];
    }

    /**
     * REST: apply package endpoint.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_apply(\WP_REST_Request $request)
    {
        $idempotency_key = class_exists('DBVC_Bricks_Idempotency')
            ? DBVC_Bricks_Idempotency::extract_key($request)
            : '';
        if ($idempotency_key !== '' && class_exists('DBVC_Bricks_Idempotency')) {
            $cached = DBVC_Bricks_Idempotency::get('apply', $idempotency_key);
            if (is_array($cached) && isset($cached['response']) && is_array($cached['response'])) {
                $cached_response = $cached['response'];
                $cached_response['idempotent_replay'] = true;
                return rest_ensure_response($cached_response);
            }
        }

        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }
        $manifest = isset($params['manifest']) && is_array($params['manifest']) ? $params['manifest'] : [];
        $selection = isset($params['selection']) && is_array($params['selection']) ? $params['selection'] : [];
        $options = isset($params['options']) && is_array($params['options']) ? $params['options'] : [];
        $options['receipt_id'] = sanitize_text_field((string) ($params['receipt_id'] ?? ($manifest['receipt_id'] ?? '')));

        $result = self::apply_package($manifest, $selection, $options);
        if (is_wp_error($result)) {
            return $result;
        }
        self::log_audit('apply', [
            'receipt_id' => (string) ($result['receipt_id'] ?? ''),
            'dry_run' => ! empty($result['dry_run']) ? 1 : 0,
            'restore_id' => (string) ($result['restore_id'] ?? ''),
        ]);
        if ($idempotency_key !== '' && class_exists('DBVC_Bricks_Idempotency')) {
            DBVC_Bricks_Idempotency::put('apply', $idempotency_key, $result);
        }
        return rest_ensure_response($result);
    }

    /**
     * REST: create restore point endpoint.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_create_restore_point(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }
        $plan = isset($params['plan']) && is_array($params['plan']) ? $params['plan'] : ['option_artifacts' => [], 'entity_artifacts' => []];
        $receipt_id = sanitize_text_field((string) ($params['receipt_id'] ?? ''));
        $result = self::create_restore_point($plan);
        if (is_wp_error($result)) {
            return $result;
        }
        $result['receipt_id'] = $receipt_id;
        self::log_audit('restore_create', [
            'receipt_id' => $receipt_id,
            'restore_id' => (string) ($result['restore_id'] ?? ''),
        ]);
        return rest_ensure_response($result);
    }

    /**
     * REST: rollback endpoint.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_rollback(\WP_REST_Request $request)
    {
        $restore_id = (string) $request->get_param('restore_id');
        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = [];
        }
        $receipt_id = sanitize_text_field((string) ($params['receipt_id'] ?? ''));
        $result = self::rollback_restore_point($restore_id);
        if (is_wp_error($result)) {
            return $result;
        }
        $result['receipt_id'] = $receipt_id;
        return rest_ensure_response($result);
    }

    /**
     * @param string $artifact_uid
     * @param string $artifact_type
     * @return string
     */
    private static function resolve_policy($artifact_uid, $artifact_type)
    {
        $registry = DBVC_Bricks_Artifacts::get_registry();
        $default_policy = 'REQUEST_REVIEW';
        if (isset($registry[$artifact_type]['default_policy'])) {
            $default_policy = (string) $registry[$artifact_type]['default_policy'];
        }

        if (str_starts_with($artifact_uid, 'option:')) {
            $default_policy = DBVC_Bricks_Addon::get_enum_setting(
                'dbvc_bricks_policy_option_default',
                ['AUTO_ACCEPT', 'REQUIRE_MANUAL_ACCEPT', 'ALWAYS_OVERRIDE', 'REQUEST_REVIEW', 'IGNORE'],
                $default_policy
            );
        } else {
            $default_policy = DBVC_Bricks_Addon::get_enum_setting(
                'dbvc_bricks_policy_entity_default',
                ['AUTO_ACCEPT', 'REQUIRE_MANUAL_ACCEPT', 'ALWAYS_OVERRIDE', 'REQUEST_REVIEW', 'IGNORE'],
                $default_policy
            );
        }

        $raw_overrides = DBVC_Bricks_Addon::get_setting('dbvc_bricks_policy_overrides', '{}');
        $decoded = json_decode($raw_overrides, true);
        if (is_array($decoded) && isset($decoded[$artifact_uid])) {
            $override = strtoupper((string) $decoded[$artifact_uid]);
            if (in_array($override, ['AUTO_ACCEPT', 'REQUIRE_MANUAL_ACCEPT', 'ALWAYS_OVERRIDE', 'REQUEST_REVIEW', 'IGNORE'], true)) {
                return $override;
            }
        }

        return $default_policy;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|\WP_Error
     */
    private static function apply_entity_artifact(array $item)
    {
        $payload = isset($item['payload']) && is_array($item['payload']) ? $item['payload'] : [];
        $post_id = isset($payload['ID']) ? (int) $payload['ID'] : 0;
        if ($post_id <= 0 || ! get_post($post_id)) {
            return new \WP_Error('dbvc_bricks_entity_missing', 'Entity apply requires valid existing post ID.', ['status' => 400]);
        }

        $update = [
            'ID' => $post_id,
        ];
        foreach (['post_title', 'post_status', 'post_content'] as $field) {
            if (array_key_exists($field, $payload)) {
                $update[$field] = $payload[$field];
            }
        }
        wp_update_post($update);

        if (! empty($payload['meta']) && is_array($payload['meta'])) {
            foreach ($payload['meta'] as $meta_key => $meta_value) {
                update_post_meta($post_id, (string) $meta_key, $meta_value);
            }
        }

        return ['applied' => true];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function read_entity_payload(array $item)
    {
        $payload = isset($item['payload']) && is_array($item['payload']) ? $item['payload'] : [];
        $post_id = isset($payload['ID']) ? (int) $payload['ID'] : 0;
        if ($post_id <= 0) {
            return [];
        }
        $post = get_post($post_id);
        if (! $post) {
            return [];
        }
        return [
            'ID' => $post->ID,
            'post_title' => $post->post_title,
            'post_status' => $post->post_status,
            'post_content' => $post->post_content,
        ];
    }

    /**
     * @param string $event
     * @param array<string, mixed> $context
     * @return void
     */
    private static function log_audit($event, array $context)
    {
        do_action('dbvc_bricks_audit_event', $event, $context);
        if (class_exists('DBVC_Database') && method_exists('DBVC_Database', 'log_activity')) {
            $encoded = wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            DBVC_Database::log_activity('dbvc_bricks_' . $event, is_string($encoded) ? $encoded : '{}');
        }
    }
}
