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
    public static function rest_fleet_visibility(\WP_REST_Request $request)
    {
        $role_error = self::assert_mothership_role();
        if ($role_error instanceof \WP_Error) {
            return $role_error;
        }

        return rest_ensure_response(self::build_mothership_fleet_visibility());
    }

    /**
     * @return array<string, mixed>
     */
    public static function build_mothership_fleet_visibility()
    {
        if (class_exists('DBVC_Bricks_Connected_Sites')) {
            if (method_exists('DBVC_Bricks_Connected_Sites', 'sync_from_onboarding_registry')) {
                DBVC_Bricks_Connected_Sites::sync_from_onboarding_registry();
            }
            if (method_exists('DBVC_Bricks_Connected_Sites', 'sync_from_packages_sources')) {
                DBVC_Bricks_Connected_Sites::sync_from_packages_sources();
            }
        }

        $sites = class_exists('DBVC_Bricks_Connected_Sites') && method_exists('DBVC_Bricks_Connected_Sites', 'get_sites')
            ? DBVC_Bricks_Connected_Sites::get_sites()
            : [];
        $packages = class_exists('DBVC_Bricks_Packages') && method_exists('DBVC_Bricks_Packages', 'get_packages')
            ? DBVC_Bricks_Packages::get_packages()
            : [];
        $latest_by_site = self::latest_packages_by_site(is_array($packages) ? $packages : []);

        $items = [];
        $seen = [];
        foreach ((array) $sites as $site_uid => $site) {
            if (! is_array($site)) {
                continue;
            }
            $site_uid = sanitize_key((string) ($site['site_uid'] ?? $site_uid));
            if ($site_uid === '') {
                continue;
            }
            $items[] = self::build_fleet_site_row($site_uid, $site, $latest_by_site[$site_uid] ?? null);
            $seen[$site_uid] = true;
        }

        foreach ($latest_by_site as $site_uid => $package) {
            if (isset($seen[$site_uid]) || ! is_array($package)) {
                continue;
            }
            $source_site = isset($package['source_site']) && is_array($package['source_site']) ? $package['source_site'] : [];
            $items[] = self::build_fleet_site_row($site_uid, [
                'site_uid' => $site_uid,
                'site_label' => $site_uid,
                'base_url' => (string) ($source_site['base_url'] ?? ''),
                'status' => 'online',
                'last_seen_at' => (string) ($package['updated_at'] ?? $package['created_at'] ?? ''),
            ], $package);
        }

        usort($items, static function ($a, $b) {
            $left = is_array($a) ? (string) ($a['site_uid'] ?? '') : '';
            $right = is_array($b) ? (string) ($b['site_uid'] ?? '') : '';
            return strcmp($left, $right);
        });

        return [
            'ok' => true,
            'generated_at' => gmdate('c'),
            'items' => $items,
            'summary' => self::build_fleet_summary($items),
        ];
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
     * @param array<string, array<string, mixed>> $packages
     * @return array<string, array<string, mixed>>
     */
    private static function latest_packages_by_site(array $packages)
    {
        $latest = [];
        foreach ($packages as $package) {
            if (! is_array($package)) {
                continue;
            }
            $source_site = isset($package['source_site']) && is_array($package['source_site']) ? $package['source_site'] : [];
            $site_uid = sanitize_key((string) ($source_site['site_uid'] ?? ''));
            if ($site_uid === '') {
                continue;
            }
            if (! isset($latest[$site_uid]) || self::package_timestamp($package) >= self::package_timestamp($latest[$site_uid])) {
                $latest[$site_uid] = $package;
            }
        }
        return $latest;
    }

    /**
     * @param array<string, mixed> $site
     * @param array<string, mixed>|null $package
     * @return array<string, mixed>
     */
    private static function build_fleet_site_row($site_uid, array $site, $package)
    {
        $package = is_array($package) ? $package : [];
        $source_site = isset($package['source_site']) && is_array($package['source_site']) ? $package['source_site'] : [];
        $base_url = esc_url_raw((string) ($site['base_url'] ?? $source_site['base_url'] ?? ''));
        $protected = self::extract_package_protected_variants($package);
        $summary = self::normalize_package_protected_summary($package, $protected);
        $last_sync_at = self::sanitize_iso8601((string) ($package['updated_at'] ?? $package['created_at'] ?? ''));
        $last_seen_at = self::sanitize_iso8601((string) ($site['last_seen_at'] ?? ''));
        $protected_url = self::build_client_protected_artifacts_url($base_url);

        return [
            'site_uid' => sanitize_key((string) $site_uid),
            'site_label' => sanitize_text_field((string) ($site['site_label'] ?? $site_uid)),
            'base_url' => $base_url,
            'site_domain' => self::extract_domain($base_url),
            'status' => sanitize_key((string) ($site['status'] ?? '')),
            'onboarding_state' => sanitize_text_field((string) ($site['onboarding_state'] ?? '')),
            'last_seen_at' => $last_seen_at,
            'last_sync_at' => $last_sync_at,
            'freshness' => [
                'last_seen_at' => $last_seen_at,
                'last_synced_at' => $last_sync_at,
                'label' => self::build_freshness_label($last_seen_at, $last_sync_at),
            ],
            'latest_package_id' => sanitize_text_field((string) ($package['package_id'] ?? '')),
            'protected_variant_count' => max(0, (int) ($summary['variant_records'] ?? 0)),
            'unique_artifact_uids' => max(0, (int) ($summary['unique_artifact_uids'] ?? 0)),
            'by_artifact_type' => $summary['by_artifact_type'],
            'by_scope' => $summary['by_scope'],
            'variants' => array_values($protected['variants']),
            'protected_artifacts_url' => $protected_url,
            'copy_link' => $protected_url,
        ];
    }

    /**
     * @param array<string, mixed> $package
     * @return array<string, mixed>
     */
    private static function extract_package_protected_variants(array $package)
    {
        $variants = [];
        $artifact_meta = [];
        $artifacts = isset($package['artifacts']) && is_array($package['artifacts']) ? $package['artifacts'] : [];
        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }
            $artifact_uid = sanitize_text_field((string) ($artifact['artifact_uid'] ?? ''));
            if ($artifact_uid === '') {
                continue;
            }
            $artifact_meta[$artifact_uid] = [
                'artifact_type' => self::sanitize_artifact_type($artifact['artifact_type'] ?? self::infer_artifact_type($artifact_uid)),
                'label' => sanitize_text_field((string) ($artifact['artifact_label'] ?? $artifact['label'] ?? $artifact_uid)),
            ];
            if (isset($artifact['protected_variant']) && is_array($artifact['protected_variant'])) {
                self::merge_fleet_variant($variants, self::normalize_fleet_variant(
                    $artifact_uid,
                    $artifact_meta[$artifact_uid]['artifact_type'],
                    $artifact_meta[$artifact_uid]['label'],
                    $artifact['protected_variant']
                ));
            }
        }

        $lookup = isset($package['protected_variant_lookup']) && is_array($package['protected_variant_lookup']) ? $package['protected_variant_lookup'] : [];
        foreach ($lookup as $artifact_uid => $annotation) {
            if (! is_array($annotation)) {
                continue;
            }
            $artifact_uid = sanitize_text_field((string) $artifact_uid);
            if ($artifact_uid === '') {
                $artifact_uid = sanitize_text_field((string) ($annotation['artifact_uid'] ?? ''));
            }
            if ($artifact_uid === '') {
                continue;
            }
            $meta = isset($artifact_meta[$artifact_uid]) ? $artifact_meta[$artifact_uid] : [
                'artifact_type' => self::infer_artifact_type($artifact_uid),
                'label' => $artifact_uid,
            ];
            self::merge_fleet_variant($variants, self::normalize_fleet_variant(
                $artifact_uid,
                (string) ($meta['artifact_type'] ?? 'unknown'),
                (string) ($meta['label'] ?? $artifact_uid),
                $annotation
            ));
        }

        $by_artifact_type = [];
        $by_scope = [];
        $variant_records = 0;
        foreach ($variants as $variant) {
            $count = max(1, (int) ($variant['variant_count'] ?? 0));
            $artifact_type = self::sanitize_artifact_type($variant['artifact_type'] ?? 'unknown');
            if ($artifact_type === '') {
                $artifact_type = 'unknown';
            }
            if (! isset($by_artifact_type[$artifact_type])) {
                $by_artifact_type[$artifact_type] = 0;
            }
            $by_artifact_type[$artifact_type] += $count;
            foreach ((array) ($variant['scopes'] ?? []) as $scope) {
                $scope = self::sanitize_scope($scope);
                if (! isset($by_scope[$scope])) {
                    $by_scope[$scope] = 0;
                }
                $by_scope[$scope]++;
            }
            $variant_records += $count;
        }
        ksort($by_artifact_type, SORT_STRING);
        ksort($by_scope, SORT_STRING);

        return [
            'variants' => $variants,
            'summary' => [
                'variant_records' => $variant_records,
                'unique_artifact_uids' => count($variants),
                'by_artifact_type' => $by_artifact_type,
                'by_scope' => $by_scope,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $package
     * @param array<string, mixed> $extracted
     * @return array<string, mixed>
     */
    private static function normalize_package_protected_summary(array $package, array $extracted)
    {
        $fallback = isset($extracted['summary']) && is_array($extracted['summary'])
            ? $extracted['summary']
            : ['variant_records' => 0, 'unique_artifact_uids' => 0, 'by_artifact_type' => [], 'by_scope' => []];
        $summary = isset($package['protected_variant_summary']) && is_array($package['protected_variant_summary'])
            ? $package['protected_variant_summary']
            : [];
        if (empty($summary)) {
            return $fallback;
        }

        $by_artifact_type = [];
        foreach ((array) ($summary['by_artifact_type'] ?? []) as $artifact_type => $count) {
            $artifact_type = self::sanitize_artifact_type($artifact_type);
            if ($artifact_type === '') {
                continue;
            }
            $by_artifact_type[$artifact_type] = max(0, (int) $count);
        }
        $by_scope = [];
        foreach ((array) ($summary['by_scope'] ?? []) as $scope => $count) {
            $scope = self::sanitize_scope($scope);
            if ($scope === '') {
                continue;
            }
            $by_scope[$scope] = max(0, (int) $count);
        }
        ksort($by_artifact_type, SORT_STRING);
        ksort($by_scope, SORT_STRING);

        return [
            'variant_records' => max((int) ($fallback['variant_records'] ?? 0), (int) ($summary['variant_records'] ?? 0)),
            'unique_artifact_uids' => max((int) ($fallback['unique_artifact_uids'] ?? 0), (int) ($summary['unique_artifact_uids'] ?? 0)),
            'by_artifact_type' => ! empty($by_artifact_type) ? $by_artifact_type : (array) ($fallback['by_artifact_type'] ?? []),
            'by_scope' => ! empty($by_scope) ? $by_scope : (array) ($fallback['by_scope'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $annotation
     * @return array<string, mixed>
     */
    private static function normalize_fleet_variant($artifact_uid, $artifact_type, $label, array $annotation)
    {
        $variant_ids = [];
        foreach ((array) ($annotation['variant_ids'] ?? []) as $variant_id) {
            $variant_id = sanitize_key((string) $variant_id);
            if ($variant_id !== '' && ! in_array($variant_id, $variant_ids, true)) {
                $variant_ids[] = $variant_id;
            }
        }
        $scopes = [];
        foreach ((array) ($annotation['scopes'] ?? []) as $scope) {
            $scope = self::sanitize_scope($scope);
            if ($scope !== '' && ! in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }
        sort($variant_ids, SORT_STRING);
        sort($scopes, SORT_STRING);
        $variant_count = max(0, (int) ($annotation['variant_count'] ?? count($variant_ids)));
        $is_protected = rest_sanitize_boolean($annotation['is_protected'] ?? false) || $variant_count > 0;
        if (! $is_protected) {
            return [];
        }
        if ($variant_count <= 0) {
            $variant_count = max(1, count($variant_ids));
        }

        $artifact_type = self::sanitize_artifact_type($artifact_type);
        if ($artifact_type === '') {
            $artifact_type = self::infer_artifact_type((string) $artifact_uid);
        }
        if ($artifact_type === '') {
            $artifact_type = 'unknown';
        }

        return [
            'artifact_uid' => sanitize_text_field((string) $artifact_uid),
            'artifact_type' => $artifact_type,
            'label' => sanitize_text_field((string) $label),
            'variant_count' => $variant_count,
            'variant_ids' => $variant_ids,
            'scopes' => $scopes,
            'latest_updated_at' => self::sanitize_iso8601((string) ($annotation['latest_updated_at'] ?? '')),
            'latest_reason' => sanitize_textarea_field((string) ($annotation['latest_reason'] ?? '')),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $variants
     * @param array<string, mixed> $candidate
     * @return void
     */
    private static function merge_fleet_variant(array &$variants, array $candidate)
    {
        $artifact_uid = sanitize_text_field((string) ($candidate['artifact_uid'] ?? ''));
        if ($artifact_uid === '') {
            return;
        }
        if (! isset($variants[$artifact_uid]) || ! is_array($variants[$artifact_uid])) {
            $variants[$artifact_uid] = $candidate;
            return;
        }

        $current = $variants[$artifact_uid];
        $current['variant_count'] = max((int) ($current['variant_count'] ?? 0), (int) ($candidate['variant_count'] ?? 0));
        $current['variant_ids'] = self::merge_string_lists((array) ($current['variant_ids'] ?? []), (array) ($candidate['variant_ids'] ?? []));
        $current['scopes'] = self::merge_string_lists((array) ($current['scopes'] ?? []), (array) ($candidate['scopes'] ?? []));
        $candidate_updated = self::sanitize_iso8601((string) ($candidate['latest_updated_at'] ?? ''));
        $current_updated = self::sanitize_iso8601((string) ($current['latest_updated_at'] ?? ''));
        if ($candidate_updated !== '' && ($current_updated === '' || strtotime($candidate_updated) >= strtotime($current_updated))) {
            $current['latest_updated_at'] = $candidate_updated;
            $current['latest_reason'] = sanitize_textarea_field((string) ($candidate['latest_reason'] ?? ''));
        }
        if ((string) ($current['label'] ?? '') === '') {
            $current['label'] = sanitize_text_field((string) ($candidate['label'] ?? $artifact_uid));
        }
        if ((string) ($current['artifact_type'] ?? '') === '' || (string) ($current['artifact_type'] ?? '') === 'unknown') {
            $current['artifact_type'] = self::sanitize_artifact_type($candidate['artifact_type'] ?? 'unknown');
        }
        $variants[$artifact_uid] = $current;
    }

    /**
     * @param array<int, mixed> $left
     * @param array<int, mixed> $right
     * @return array<int, string>
     */
    private static function merge_string_lists(array $left, array $right)
    {
        $merged = [];
        foreach (array_merge($left, $right) as $value) {
            $value = sanitize_key((string) $value);
            if ($value !== '' && ! in_array($value, $merged, true)) {
                $merged[] = $value;
            }
        }
        sort($merged, SORT_STRING);
        return $merged;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private static function build_fleet_summary(array $items)
    {
        $by_artifact_type = [];
        $by_scope = [];
        $total = 0;
        $protected_sites = 0;
        $artifact_keys = [];
        $fallback_unique_artifacts = 0;
        $latest_sync_at = '';
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $count = max(0, (int) ($item['protected_variant_count'] ?? 0));
            $total += $count;
            if ($count > 0) {
                $protected_sites++;
            }
            $fallback_unique_artifacts += max(0, (int) ($item['unique_artifact_uids'] ?? 0));
            foreach ((array) ($item['by_artifact_type'] ?? []) as $artifact_type => $type_count) {
                $artifact_type = self::sanitize_artifact_type($artifact_type);
                if ($artifact_type === '') {
                    continue;
                }
                if (! isset($by_artifact_type[$artifact_type])) {
                    $by_artifact_type[$artifact_type] = 0;
                }
                $by_artifact_type[$artifact_type] += max(0, (int) $type_count);
            }
            foreach ((array) ($item['by_scope'] ?? []) as $scope => $scope_count) {
                $scope = self::sanitize_scope($scope);
                if ($scope === '') {
                    continue;
                }
                if (! isset($by_scope[$scope])) {
                    $by_scope[$scope] = 0;
                }
                $by_scope[$scope] += max(0, (int) $scope_count);
            }
            foreach ((array) ($item['variants'] ?? []) as $variant) {
                if (! is_array($variant)) {
                    continue;
                }
                $artifact_uid = sanitize_text_field((string) ($variant['artifact_uid'] ?? ''));
                if ($artifact_uid !== '') {
                    $artifact_keys[(string) ($item['site_uid'] ?? '') . '|' . $artifact_uid] = true;
                }
            }
            $last_sync_at = self::sanitize_iso8601((string) ($item['last_sync_at'] ?? ''));
            if ($last_sync_at !== '' && ($latest_sync_at === '' || strtotime($last_sync_at) >= strtotime($latest_sync_at))) {
                $latest_sync_at = $last_sync_at;
            }
        }
        ksort($by_artifact_type, SORT_STRING);
        ksort($by_scope, SORT_STRING);

        return [
            'site_count' => count($items),
            'protected_site_count' => $protected_sites,
            'total_protected_variants' => $total,
            'unique_artifact_uids' => max(count($artifact_keys), $fallback_unique_artifacts),
            'by_artifact_type' => $by_artifact_type,
            'by_scope' => $by_scope,
            'latest_sync_at' => $latest_sync_at,
        ];
    }

    /**
     * @param array<string, mixed> $package
     * @return int
     */
    private static function package_timestamp(array $package)
    {
        $value = (string) ($package['updated_at'] ?? $package['created_at'] ?? '');
        $timestamp = strtotime($value);
        return $timestamp === false ? 0 : (int) $timestamp;
    }

    /**
     * @param string $base_url
     * @return string
     */
    private static function build_client_protected_artifacts_url($base_url)
    {
        $base_url = untrailingslashit(esc_url_raw((string) $base_url));
        if ($base_url === '') {
            return '';
        }
        $menu_slug = class_exists('DBVC_Bricks_Addon') ? DBVC_Bricks_Addon::MENU_SLUG : 'addon-dbvc-bricks-addon';
        return esc_url_raw($base_url . '/wp-admin/admin.php?page=' . rawurlencode($menu_slug) . '&tab=protected_artifacts');
    }

    /**
     * @param string $base_url
     * @return string
     */
    private static function extract_domain($base_url)
    {
        $host = wp_parse_url((string) $base_url, PHP_URL_HOST);
        return is_string($host) ? strtolower(sanitize_text_field($host)) : '';
    }

    /**
     * @param string $last_seen_at
     * @param string $last_sync_at
     * @return string
     */
    private static function build_freshness_label($last_seen_at, $last_sync_at)
    {
        if ($last_sync_at !== '' && $last_seen_at !== '') {
            return 'seen ' . $last_seen_at . ' / synced ' . $last_sync_at;
        }
        if ($last_sync_at !== '') {
            return 'synced ' . $last_sync_at;
        }
        if ($last_seen_at !== '') {
            return 'seen ' . $last_seen_at;
        }
        return 'no freshness data';
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
    private static function assert_mothership_role()
    {
        if (! class_exists('DBVC_Bricks_Addon') || DBVC_Bricks_Addon::get_role_mode() !== 'mothership') {
            return new \WP_Error(
                'dbvc_bricks_protected_variant_fleet_role_invalid',
                'Protected variant fleet visibility is mothership-only.',
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
