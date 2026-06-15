<?php

namespace Dbvc\Media\Hydration;

if (! defined('WPINC')) {
    die;
}

/**
 * REST orchestration for media hydration inventory, preflight, and apply.
 */
final class RestController
{
    private const REST_NAMESPACE = 'dbvc/v1';

    /** @var self|null */
    private static $instance = null;

    public static function init(): void
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
    }

    private function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(
            self::REST_NAMESPACE,
            '/media-hydration/inventory',
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'inventory'],
                'permission_callback' => [$this, 'can_manage'],
                'args' => [
                    'limit' => ['required' => false, 'sanitize_callback' => 'absint'],
                    'offset' => ['required' => false, 'sanitize_callback' => 'absint'],
                    'ids' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                    'mime_groups' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                    'compute_hash' => ['required' => false, 'sanitize_callback' => 'rest_sanitize_boolean'],
                    'check_derivatives' => ['required' => false, 'sanitize_callback' => 'rest_sanitize_boolean'],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/media-hydration/preflight',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'preflight'],
                'permission_callback' => [$this, 'can_manage'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/media-hydration/apply',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'apply'],
                'permission_callback' => [$this, 'can_manage'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/media-hydration/jobs',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_job'],
                'permission_callback' => [$this, 'can_manage'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/media-hydration/jobs/(?P<job_id>\d+)',
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'job_status'],
                'permission_callback' => [$this, 'can_manage'],
                'args' => [
                    'job_id' => ['required' => true, 'sanitize_callback' => 'absint'],
                ],
            ]
        );

        foreach (['run', 'pause', 'resume', 'cancel'] as $job_action) {
            register_rest_route(
                self::REST_NAMESPACE,
                '/media-hydration/jobs/(?P<job_id>\d+)/' . $job_action,
                [
                    'methods' => \WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'job_' . $job_action],
                    'permission_callback' => [$this, 'can_manage'],
                    'args' => [
                        'job_id' => ['required' => true, 'sanitize_callback' => 'absint'],
                    ],
                ]
            );
        }

        register_rest_route(
            self::REST_NAMESPACE,
            '/media-hydration/packages',
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'packages'],
                'permission_callback' => [$this, 'can_manage'],
                'args' => [
                    'limit' => ['required' => false, 'sanitize_callback' => 'absint'],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/media-hydration/package/export',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'package_export'],
                'permission_callback' => [$this, 'can_manage'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/media-hydration/receipts',
            [
                [
                    'methods' => \WP_REST_Server::READABLE,
                    'callback' => [$this, 'receipts'],
                    'permission_callback' => [$this, 'can_manage'],
                    'args' => [
                        'limit' => ['required' => false, 'sanitize_callback' => 'absint'],
                    ],
                ],
                [
                    'methods' => \WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'delete_receipts'],
                    'permission_callback' => [$this, 'can_manage'],
                    'args' => [
                        'older_than_days' => ['required' => false, 'sanitize_callback' => 'absint'],
                    ],
                ],
            ]
        );
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function inventory($request)
    {
        $settings = Settings::get_all();
        $limit = absint($request->get_param('limit'));
        if ($limit <= 0) {
            $limit = (int) $settings[Settings::OPTION_BATCH_SIZE];
        }

        $result = LibraryInventoryService::query([
            'limit' => $limit,
            'offset' => absint($request->get_param('offset')),
            'attachment_ids' => (string) $request->get_param('ids'),
            'mime_groups' => (string) $request->get_param('mime_groups'),
            'include_file_state' => true,
            'compute_hash' => rest_sanitize_boolean($request->get_param('compute_hash')),
            'check_derivatives' => rest_sanitize_boolean($request->get_param('check_derivatives')),
        ]);

        return rest_ensure_response($result);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function preflight($request)
    {
        $enabled = $this->ensure_enabled();
        if (is_wp_error($enabled)) {
            return $enabled;
        }

        $params = $this->params($request);
        $manifest = $this->manifest_from_params($params);
        if (is_wp_error($manifest)) {
            return $manifest;
        }

        $plan = HydrationPlanner::plan($manifest, $this->plan_args_from_settings($params));
        if (is_wp_error($plan)) {
            return $plan;
        }

        if (Settings::get_bool(Settings::OPTION_REQUIRE_DRY_RUN) === '1' || ! empty($params['save_plan'])) {
            $saved_plan = HydrationPlanStore::write($plan);
            if (is_wp_error($saved_plan)) {
                $plan['saved_plan_error'] = $saved_plan->get_error_message();
            } else {
                $plan['saved_plan'] = $saved_plan;
            }
        }

        if (! empty($params['save_receipt']) && Settings::get_bool(Settings::OPTION_RECEIPTS_ENABLED) === '1') {
            $receipt = HydrationReceiptStore::write('plan', $plan);
            if (is_wp_error($receipt)) {
                $plan['receipt_error'] = $receipt->get_error_message();
            } else {
                $plan['receipt'] = $receipt;
            }
        }

        return rest_ensure_response($plan);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function package_export($request)
    {
        $enabled = $this->ensure_enabled();
        if (is_wp_error($enabled)) {
            return $enabled;
        }

        $params = $this->params($request);
        $settings = Settings::get_all();
        $batch_size = isset($params['batch_size']) ? absint($params['batch_size']) : (int) $settings[Settings::OPTION_BATCH_SIZE];

        $result = MirrorManifestBuilder::build_package([
            'package_id' => isset($params['package_id']) ? sanitize_file_name((string) $params['package_id']) : '',
            'attachment_ids' => isset($params['ids']) ? (string) $params['ids'] : [],
            'mime_groups' => isset($params['mime_groups']) ? (string) $params['mime_groups'] : implode(',', (array) $settings[Settings::OPTION_ALLOWED_MIME_GROUPS]),
            'batch_size' => $batch_size,
            'include_files' => ! empty($params['include_files']),
            'create_zip' => ! empty($params['create_zip']),
            'check_derivatives' => ! empty($params['check_derivatives']),
            'include_hashes' => true,
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        if (! empty($result['zip_path']) && ! empty($result['package_id']) && class_exists(__NAMESPACE__ . '\PackageDownloadController')) {
            $result['download_url'] = PackageDownloadController::download_url_for_package((string) $result['package_id']);
        }

        return rest_ensure_response($result);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function packages($request)
    {
        $enabled = $this->ensure_enabled();
        if (is_wp_error($enabled)) {
            return $enabled;
        }

        return rest_ensure_response(PackageRegistry::list_packages([
            'limit' => absint($request->get_param('limit')),
        ]));
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function receipts($request)
    {
        $enabled = $this->ensure_enabled();
        if (is_wp_error($enabled)) {
            return $enabled;
        }

        $limit = absint($request->get_param('limit'));
        if ($limit <= 0) {
            $limit = 25;
        }

        return rest_ensure_response([
            'receipts' => HydrationReceiptStore::list_recent($limit),
        ]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function delete_receipts($request)
    {
        $enabled = $this->ensure_enabled();
        if (is_wp_error($enabled)) {
            return $enabled;
        }

        $days = absint($request->get_param('older_than_days'));
        if ($days <= 0) {
            $days = 30;
        }

        $cleanup = HydrationReceiptStore::delete_older_than($days);
        if (is_wp_error($cleanup)) {
            return $cleanup;
        }

        return rest_ensure_response([
            'cleanup' => $cleanup,
            'receipts' => HydrationReceiptStore::list_recent(10),
        ]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function apply($request)
    {
        $enabled = $this->ensure_enabled();
        if (is_wp_error($enabled)) {
            return $enabled;
        }

        $params = $this->params($request);
        if ((string) ($params['confirm'] ?? '') !== 'hydrate-existing-media') {
            return new \WP_Error('dbvc_media_hydration_confirm_required', __('Media hydration apply requires explicit confirmation.', 'dbvc'), ['status' => 400]);
        }

        $plan_id = isset($params['plan_id']) ? sanitize_file_name((string) $params['plan_id']) : '';
        if (Settings::get_bool(Settings::OPTION_REQUIRE_DRY_RUN) === '1' && $plan_id === '') {
            return new \WP_Error('dbvc_media_hydration_dry_run_required', __('Run and acknowledge a saved media hydration dry run before applying.', 'dbvc'), ['status' => 400]);
        }

        $manifest_path = $this->normalize_manifest_path((string) ($params['manifest_path'] ?? ''));
        if (is_wp_error($manifest_path)) {
            return $manifest_path;
        }

        $manifest = $this->manifest_from_params([
            'manifest_path' => $manifest_path,
        ]);
        if (is_wp_error($manifest)) {
            return $manifest;
        }

        if (Settings::get_bool(Settings::OPTION_REQUIRE_DRY_RUN) === '1') {
            $verified_plan = HydrationPlanStore::verify_for_manifest($plan_id, $manifest);
            if (is_wp_error($verified_plan)) {
                return $verified_plan;
            }
        }

        $retry_args = $this->retry_args_from_params($params);
        if (is_wp_error($retry_args)) {
            return $retry_args;
        }

        $package_root = isset($params['package_root']) && (string) $params['package_root'] !== ''
            ? $this->normalize_package_root((string) $params['package_root'])
            : dirname($manifest_path);
        if (is_wp_error($package_root)) {
            return $package_root;
        }

        $lock = HydrationLock::acquire([
            'owner' => 'rest',
            'ttl_seconds' => Settings::get_lock_timeout_minutes() * 60,
        ]);
        if (is_wp_error($lock)) {
            return $lock;
        }

        try {
            $apply_args = $this->apply_args_from_settings($params) + [
                'package_root' => $package_root,
            ] + $retry_args;
            $report = Hydrator::apply_from_manifest_file($manifest_path, $apply_args);
        } finally {
            HydrationLock::release((string) ($lock['token'] ?? ''));
        }

        if (is_wp_error($report)) {
            return $report;
        }

        if (! empty($retry_args['retry_receipt_id'])) {
            $report['retry'] = [
                'receipt_id' => (string) $retry_args['retry_receipt_id'],
                'source_id_count' => count((array) ($retry_args['source_ids'] ?? [])),
            ];
        }

        if (Settings::get_bool(Settings::OPTION_RECEIPTS_ENABLED) === '1') {
            $receipt = HydrationReceiptStore::write('apply', $report);
            if (is_wp_error($receipt)) {
                $report['receipt_error'] = $receipt->get_error_message();
            } else {
                $report['receipt'] = $receipt;
            }
        }

        return rest_ensure_response($report);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function create_job($request)
    {
        $enabled = $this->ensure_enabled();
        if (is_wp_error($enabled)) {
            return $enabled;
        }

        $params = $this->params($request);
        if ((string) ($params['confirm'] ?? '') !== 'hydrate-existing-media') {
            return new \WP_Error('dbvc_media_hydration_confirm_required', __('Media hydration job creation requires explicit confirmation.', 'dbvc'), ['status' => 400]);
        }

        $contract = $this->job_contract_from_params($params);
        if (is_wp_error($contract)) {
            return $contract;
        }

        $job = MediaHydrationJobStore::create($contract, 'queued');
        if (is_wp_error($job)) {
            return $job;
        }

        MediaHydrationJobRunner::schedule((int) ($job['job_id'] ?? 0));

        return new \WP_REST_Response(['job' => $job], 201);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function job_status($request)
    {
        $enabled = $this->ensure_enabled();
        if (is_wp_error($enabled)) {
            return $enabled;
        }

        $job = MediaHydrationJobStore::get(absint($request->get_param('job_id')));
        if (is_wp_error($job)) {
            return $job;
        }

        return rest_ensure_response(['job' => $job]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function job_run($request)
    {
        $enabled = $this->ensure_enabled();
        if (is_wp_error($enabled)) {
            return $enabled;
        }

        $result = MediaHydrationJobRunner::run(absint($request->get_param('job_id')));
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function job_pause($request)
    {
        return $this->transition_job($request, 'paused');
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function job_resume($request)
    {
        $enabled = $this->ensure_enabled();
        if (is_wp_error($enabled)) {
            return $enabled;
        }

        $job = MediaHydrationJobStore::get(absint($request->get_param('job_id')));
        if (is_wp_error($job)) {
            return $job;
        }

        if ((string) ($job['status'] ?? '') !== 'paused') {
            return new \WP_Error('dbvc_media_hydration_job_not_paused', __('Only paused media hydration jobs can be resumed.', 'dbvc'), ['status' => 409]);
        }

        $job = MediaHydrationJobStore::transition((int) ($job['job_id'] ?? 0), 'queued');
        if (is_wp_error($job)) {
            return $job;
        }

        MediaHydrationJobRunner::schedule((int) ($job['job_id'] ?? 0));

        return rest_ensure_response(['job' => $job]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function job_cancel($request)
    {
        return $this->transition_job($request, 'cancelled');
    }

    /**
     * @return bool
     */
    public function can_manage(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * @return true|\WP_Error
     */
    private function ensure_enabled()
    {
        if (Settings::get_bool(Settings::OPTION_ENABLED) === '1') {
            return true;
        }

        return new \WP_Error('dbvc_media_hydration_disabled', __('Media hydration workflow is disabled in DBVC settings.', 'dbvc'), ['status' => 403]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return array<string,mixed>
     */
    private function params($request): array
    {
        $params = $request->get_params();
        $json = $request->get_json_params();
        if (is_array($json) && ! empty($json)) {
            $params = array_merge(is_array($params) ? $params : [], $json);
        }

        return is_array($params) ? $params : [];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|\WP_Error
     */
    private function manifest_from_params(array $params)
    {
        if (isset($params['manifest']) && is_array($params['manifest'])) {
            return $params['manifest'];
        }

        $manifest_path = $this->normalize_manifest_path((string) ($params['manifest_path'] ?? ''));
        if (is_wp_error($manifest_path)) {
            return $manifest_path;
        }

        $raw = file_get_contents($manifest_path);
        if (! is_string($raw) || trim($raw) === '') {
            return new \WP_Error('dbvc_media_hydration_manifest_empty', __('Media mirror manifest is empty.', 'dbvc'), ['status' => 400]);
        }

        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
            return new \WP_Error('dbvc_media_hydration_manifest_invalid_json', __('Media mirror manifest is not valid JSON.', 'dbvc'), ['status' => 400]);
        }

        return $manifest;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function plan_args_from_settings(array $params): array
    {
        return [
            'clone_confirmation' => isset($params['clone_confirmation']) ? rest_sanitize_boolean($params['clone_confirmation']) : Settings::get_bool(Settings::OPTION_CLONE_CONFIRMATION) === '1',
            'strict_hashes' => isset($params['strict_hashes']) ? rest_sanitize_boolean($params['strict_hashes']) : Settings::get_bool(Settings::OPTION_STRICT_HASHES) === '1',
            'match_policy' => isset($params['match_policy']) ? sanitize_key((string) $params['match_policy']) : Settings::get_match_policy(),
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function apply_args_from_settings(array $params): array
    {
        $metadata_policy = Settings::get_metadata_policy();
        $batch_size = Settings::get_batch_size();

        return $this->plan_args_from_settings($params) + [
            'limit' => isset($params['limit']) ? absint($params['limit']) : $batch_size,
            'offset' => isset($params['offset']) ? absint($params['offset']) : 0,
            'repair_metadata' => isset($params['repair_metadata']) ? rest_sanitize_boolean($params['repair_metadata']) : $metadata_policy !== 'skip',
            'normalize_media_urls_to_https' => isset($params['normalize_media_urls_to_https']) ? rest_sanitize_boolean($params['normalize_media_urls_to_https']) : Settings::get_bool(Settings::OPTION_NORMALIZE_MEDIA_URLS_TO_HTTPS) === '1',
            'overwrite_existing' => false,
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|\WP_Error
     */
    private function retry_args_from_params(array $params)
    {
        $receipt_id = isset($params['retry_receipt_id']) ? sanitize_file_name((string) $params['retry_receipt_id']) : '';
        if ($receipt_id === '') {
            return [];
        }

        $source_ids = HydrationReceiptStore::failed_source_ids($receipt_id);
        if (is_wp_error($source_ids)) {
            return $source_ids;
        }

        if (empty($source_ids)) {
            return new \WP_Error('dbvc_media_hydration_retry_no_failed_items', __('The selected media hydration receipt has no failed items to retry.', 'dbvc'), ['status' => 400]);
        }

        return [
            'retry_receipt_id' => $receipt_id,
            'source_ids' => $source_ids,
        ];
    }

    /**
     * @param string $path
     * @return string|\WP_Error
     */
    private function normalize_manifest_path(string $path)
    {
        $path = $this->normalize_allowed_local_path($path, true);
        if (is_wp_error($path)) {
            return $path;
        }

        if (basename($path) !== MirrorManifestBuilder::MANIFEST_FILENAME) {
            return new \WP_Error('dbvc_media_hydration_bad_manifest_name', __('Expected a DBVC media mirror manifest file.', 'dbvc'), ['status' => 400]);
        }

        return $path;
    }

    /**
     * @param string $path
     * @return string|\WP_Error
     */
    private function normalize_package_root(string $path)
    {
        return $this->normalize_allowed_local_path($path, false);
    }

    /**
     * @param string $path
     * @param bool   $must_be_file
     * @return string|\WP_Error
     */
    private function normalize_allowed_local_path(string $path, bool $must_be_file)
    {
        if ($path === '' || strpos($path, chr(0)) !== false) {
            return new \WP_Error('dbvc_media_hydration_bad_path', __('Invalid media hydration path.', 'dbvc'), ['status' => 400]);
        }

        $real = realpath(wp_normalize_path($path));
        if (! is_string($real) || $real === '') {
            return new \WP_Error('dbvc_media_hydration_path_missing', __('Media hydration path does not exist.', 'dbvc'), ['status' => 404]);
        }

        if ($must_be_file && (! is_file($real) || ! is_readable($real))) {
            return new \WP_Error('dbvc_media_hydration_path_unreadable', __('Media hydration file is unreadable.', 'dbvc'), ['status' => 400]);
        }

        if (! $must_be_file && (! is_dir($real) || ! is_readable($real))) {
            return new \WP_Error('dbvc_media_hydration_path_unreadable', __('Media hydration directory is unreadable.', 'dbvc'), ['status' => 400]);
        }

        $real = wp_normalize_path($real);
        foreach ($this->allowed_roots() as $root) {
            if ($this->path_starts_with($real, $root)) {
                return $real;
            }
        }

        return new \WP_Error('dbvc_media_hydration_path_not_allowed', __('Media hydration path is outside allowed DBVC media roots.', 'dbvc'), ['status' => 403]);
    }

    /**
     * @return string[]
     */
    private function allowed_roots(): array
    {
        $roots = [];
        if (function_exists('dbvc_get_sync_path')) {
            $sync_root = realpath(dbvc_get_sync_path());
            if (is_string($sync_root) && $sync_root !== '') {
                $roots[] = wp_normalize_path($sync_root);
            }
        }

        $uploads = wp_get_upload_dir();
        if (! empty($uploads['basedir'])) {
            $upload_root = realpath((string) $uploads['basedir']);
            if (is_string($upload_root) && $upload_root !== '') {
                $roots[] = wp_normalize_path($upload_root);
            }
        }

        return array_values(array_unique($roots));
    }

    private function path_starts_with(string $path, string $base): bool
    {
        $path = rtrim(wp_normalize_path($path), '/') . '/';
        $base = rtrim(wp_normalize_path($base), '/') . '/';
        return strpos($path, $base) === 0;
    }

    /**
     * @param \WP_REST_Request $request
     * @param string           $status
     * @return \WP_REST_Response|\WP_Error
     */
    private function transition_job($request, string $status)
    {
        $enabled = $this->ensure_enabled();
        if (is_wp_error($enabled)) {
            return $enabled;
        }

        $job = MediaHydrationJobStore::get(absint($request->get_param('job_id')));
        if (is_wp_error($job)) {
            return $job;
        }

        $current = (string) ($job['status'] ?? '');
        if (in_array($current, ['completed', 'cancelled'], true)) {
            return new \WP_Error('dbvc_media_hydration_job_terminal', __('Media hydration job is already in a terminal status.', 'dbvc'), ['status' => 409]);
        }

        $job = MediaHydrationJobStore::transition((int) ($job['job_id'] ?? 0), $status);
        if (is_wp_error($job)) {
            return $job;
        }

        return rest_ensure_response(['job' => $job]);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|\WP_Error
     */
    private function job_contract_from_params(array $params)
    {
        $plan_id = isset($params['plan_id']) ? sanitize_file_name((string) $params['plan_id']) : '';
        if (Settings::get_bool(Settings::OPTION_REQUIRE_DRY_RUN) === '1' && $plan_id === '') {
            return new \WP_Error('dbvc_media_hydration_dry_run_required', __('Run and acknowledge a saved media hydration dry run before creating a background job.', 'dbvc'), ['status' => 400]);
        }

        $manifest_path = $this->normalize_manifest_path((string) ($params['manifest_path'] ?? ''));
        if (is_wp_error($manifest_path)) {
            return $manifest_path;
        }

        $manifest = $this->manifest_from_params([
            'manifest_path' => $manifest_path,
        ]);
        if (is_wp_error($manifest)) {
            return $manifest;
        }

        $plan = null;
        if ($plan_id !== '') {
            $plan = HydrationPlanStore::verify_for_manifest($plan_id, $manifest);
            if (is_wp_error($plan)) {
                return $plan;
            }
        } else {
            $plan = HydrationPlanner::plan($manifest, $this->plan_args_from_settings($params));
            if (is_wp_error($plan)) {
                return $plan;
            }
        }

        $retry_args = $this->retry_args_from_params($params);
        if (is_wp_error($retry_args)) {
            return $retry_args;
        }

        $package_root = isset($params['package_root']) && (string) $params['package_root'] !== ''
            ? $this->normalize_package_root((string) $params['package_root'])
            : dirname($manifest_path);
        if (is_wp_error($package_root)) {
            return $package_root;
        }

        $apply_args = $this->apply_args_from_settings($params);
        $match_policy = sanitize_key((string) ($apply_args['match_policy'] ?? Settings::get_match_policy()));
        if (! in_array($match_policy, Settings::allowed_match_policies(), true)) {
            $match_policy = Settings::get_match_policy();
        }
        $source_ids = isset($retry_args['source_ids']) && is_array($retry_args['source_ids']) ? $retry_args['source_ids'] : [];
        $items = isset($plan['items']) && is_array($plan['items']) ? $plan['items'] : [];
        if (! empty($source_ids)) {
            $source_id_map = array_fill_keys(array_map('absint', $source_ids), true);
            $items = array_filter($items, static function ($item) use ($source_id_map): bool {
                if (! is_array($item)) {
                    return false;
                }
                $source_id = isset($item['source_id']) ? absint($item['source_id']) : 0;
                return $source_id > 0 && isset($source_id_map[$source_id]);
            });
        }

        return [
            'manifest_path' => $manifest_path,
            'manifest_checksum' => (string) ($manifest['checksum'] ?? ''),
            'package_root' => $package_root,
            'plan_id' => $plan_id,
            'plan_checksum' => MediaHydrationJobRunner::plan_checksum($plan),
            'retry_receipt_id' => (string) ($retry_args['retry_receipt_id'] ?? ''),
            'source_ids' => $source_ids,
            'offset' => 0,
            'limit' => max(1, min(500, absint($apply_args['limit'] ?? Settings::get_batch_size()))),
            'total_plan_items' => count($items),
            'processed_total' => 0,
            'cumulative_summary' => [
                'items' => 0,
                'hydrated' => 0,
                'metadata_repaired' => 0,
                'skipped' => 0,
                'blocked' => 0,
                'errors' => 0,
                'bytes' => 0,
            ],
            'policy' => [
                'clone_confirmation' => ! empty($apply_args['clone_confirmation']),
                'strict_hashes' => ! empty($apply_args['strict_hashes']),
                'match_policy' => $match_policy,
                'repair_metadata' => ! empty($apply_args['repair_metadata']),
                'normalize_media_urls_to_https' => ! empty($apply_args['normalize_media_urls_to_https']),
                'overwrite_existing' => false,
            ],
            'created_by' => get_current_user_id(),
            'last_error' => '',
            'receipt_ids' => [],
            'progress' => 0,
        ];
    }
}
