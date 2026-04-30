<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Portability_Rest_Controller
{
    /**
     * @return void
     */
    public static function register_routes()
    {
        if (! class_exists('DBVC_Bricks_Addon') || ! DBVC_Bricks_Addon::is_enabled()) {
            return;
        }

        register_rest_route(
            'dbvc/v1/bricks',
            '/portability/status',
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [self::class, 'get_status'],
                'permission_callback' => [DBVC_Bricks_Addon::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/portability/export',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'post_export'],
                'permission_callback' => [DBVC_Bricks_Addon::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/portability/import',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'post_import'],
                'permission_callback' => [DBVC_Bricks_Addon::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/portability/sessions/(?P<session_id>[^/]+)',
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [self::class, 'get_session'],
                'permission_callback' => [DBVC_Bricks_Addon::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/portability/sessions/(?P<session_id>[^/]+)/draft',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'post_session_draft'],
                'permission_callback' => [DBVC_Bricks_Addon::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/portability/sessions/(?P<session_id>[^/]+)/refresh',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'post_session_refresh'],
                'permission_callback' => [DBVC_Bricks_Addon::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/portability/apply',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'post_apply'],
                'permission_callback' => [DBVC_Bricks_Addon::class, 'can_manage'],
            ]
        );

        register_rest_route(
            'dbvc/v1/bricks',
            '/portability/backups/(?P<backup_id>[^/]+)/rollback',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'post_rollback'],
                'permission_callback' => [DBVC_Bricks_Addon::class, 'can_manage'],
            ]
        );
    }

    /**
     * @return \WP_REST_Response
     */
    public static function get_status()
    {
        $domains = array_map([self::class, 'prepare_domain_for_response'], DBVC_Bricks_Portability_Registry::get_supported_domains());
        $exports = array_map([self::class, 'prepare_export_record'], DBVC_Bricks_Portability_Package_Service::list_recent_exports(10));
        $sessions = array_map([self::class, 'prepare_session_record'], DBVC_Bricks_Portability_Package_Service::list_recent_sessions(10));
        $backups = array_map([self::class, 'prepare_backup_record'], DBVC_Bricks_Portability_Backup_Service::list_recent_backups(10));

        return rest_ensure_response([
            'ok' => true,
            'domains' => array_values($domains),
            'option_registry' => DBVC_Bricks_Portability_Registry::get_option_registry(),
            'recent_exports' => array_values($exports),
            'recent_sessions' => array_values($sessions),
            'recent_backups' => array_values($backups),
            'recent_jobs' => self::get_recent_jobs(),
        ]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function post_export(\WP_REST_Request $request)
    {
        $replay = self::require_idempotency($request, 'portability_export');
        if ($replay instanceof \WP_REST_Response || $replay instanceof \WP_Error) {
            return $replay;
        }

        $payload = $request->get_json_params();
        $domains = isset($payload['domains']) && is_array($payload['domains']) ? array_values($payload['domains']) : [];
        $args = [
            'notes' => sanitize_textarea_field((string) ($payload['notes'] ?? '')),
            'environment' => sanitize_key((string) ($payload['environment'] ?? '')),
        ];

        $result = DBVC_Bricks_Portability_Package_Service::create_export($domains, $args);
        if (is_wp_error($result)) {
            return $result;
        }

        $response = self::prepare_export_record($result);
        self::store_idempotency($replay, $response);
        return rest_ensure_response($response);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function post_import(\WP_REST_Request $request)
    {
        $replay = self::require_idempotency($request, 'portability_import');
        if ($replay instanceof \WP_REST_Response || $replay instanceof \WP_Error) {
            return $replay;
        }

        $files = $request->get_file_params();
        if (empty($files['file']) || ! is_array($files['file'])) {
            return new \WP_Error('dbvc_bricks_portability_upload_missing', __('Upload a Bricks portability ZIP package first.', 'dbvc'), ['status' => 400]);
        }

        $result = DBVC_Bricks_Portability_Package_Service::import_uploaded_package($files['file']);
        if (is_wp_error($result)) {
            return $result;
        }

        self::store_idempotency($replay, $result);
        return rest_ensure_response($result);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function get_session(\WP_REST_Request $request)
    {
        $session = DBVC_Bricks_Portability_Package_Service::load_session($request->get_param('session_id'));
        if (is_wp_error($session)) {
            return $session;
        }

        return rest_ensure_response(DBVC_Bricks_Portability_Package_Service::build_session_view($session));
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function post_session_draft(\WP_REST_Request $request)
    {
        $replay = self::require_idempotency($request, 'portability_draft');
        if ($replay instanceof \WP_REST_Response || $replay instanceof \WP_Error) {
            return $replay;
        }

        $session_id = sanitize_key((string) $request->get_param('session_id'));
        if ($session_id === '') {
            return new \WP_Error('dbvc_bricks_portability_session_missing', __('Bricks portability draft save requires a review session.', 'dbvc'), ['status' => 400]);
        }

        $payload = $request->get_json_params();
        $decisions = isset($payload['decisions']) && is_array($payload['decisions']) ? $payload['decisions'] : [];
        $manual_row_ids = isset($payload['manual_decisions']) && is_array($payload['manual_decisions']) ? $payload['manual_decisions'] : [];
        $result = DBVC_Bricks_Portability_Package_Service::save_session_draft($session_id, $decisions, $manual_row_ids);
        if (is_wp_error($result)) {
            return $result;
        }

        self::store_idempotency($replay, $result);
        return rest_ensure_response($result);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function post_session_refresh(\WP_REST_Request $request)
    {
        $replay = self::require_idempotency($request, 'portability_refresh');
        if ($replay instanceof \WP_REST_Response || $replay instanceof \WP_Error) {
            return $replay;
        }

        $session_id = sanitize_key((string) $request->get_param('session_id'));
        if ($session_id === '') {
            return new \WP_Error('dbvc_bricks_portability_session_missing', __('Bricks portability refresh requires a review session.', 'dbvc'), ['status' => 400]);
        }

        $result = DBVC_Bricks_Portability_Package_Service::refresh_session($session_id);
        if (is_wp_error($result)) {
            return $result;
        }

        self::store_idempotency($replay, $result);
        return rest_ensure_response($result);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function post_apply(\WP_REST_Request $request)
    {
        $replay = self::require_idempotency($request, 'portability_apply');
        if ($replay instanceof \WP_REST_Response || $replay instanceof \WP_Error) {
            return $replay;
        }

        $payload = $request->get_json_params();
        $session_id = sanitize_key((string) ($payload['session_id'] ?? ''));
        if ($session_id === '') {
            return new \WP_Error('dbvc_bricks_portability_session_missing', __('Bricks portability apply requires a review session.', 'dbvc'), ['status' => 400]);
        }

        $confirm = rest_sanitize_boolean($payload['confirm_apply'] ?? false);
        if (! $confirm) {
            return new \WP_Error('dbvc_bricks_portability_confirm_required', __('Confirm the Bricks portability apply action before proceeding.', 'dbvc'), ['status' => 400]);
        }

        $decisions = isset($payload['decisions']) && is_array($payload['decisions']) ? $payload['decisions'] : [];
        $manual_row_ids = isset($payload['manual_decisions']) && is_array($payload['manual_decisions']) ? $payload['manual_decisions'] : [];
        $result = DBVC_Bricks_Portability_Apply_Service::apply_session($session_id, $decisions, $manual_row_ids);
        if (is_wp_error($result)) {
            return $result;
        }

        self::store_idempotency($replay, $result);
        return rest_ensure_response($result);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function post_rollback(\WP_REST_Request $request)
    {
        $replay = self::require_idempotency($request, 'portability_rollback');
        if ($replay instanceof \WP_REST_Response || $replay instanceof \WP_Error) {
            return $replay;
        }

        $confirm = rest_sanitize_boolean($request->get_param('confirm_rollback'));
        if (! $confirm) {
            return new \WP_Error('dbvc_bricks_portability_confirm_required', __('Confirm the Bricks portability rollback action before proceeding.', 'dbvc'), ['status' => 400]);
        }

        $result = DBVC_Bricks_Portability_Apply_Service::rollback_backup($request->get_param('backup_id'));
        if (is_wp_error($result)) {
            return $result;
        }

        self::store_idempotency($replay, $result);
        return rest_ensure_response($result);
    }

    /**
     * @param mixed $domain
     * @return array<string, mixed>
     */
    private static function prepare_domain_for_response($domain)
    {
        $domain = is_array($domain) ? $domain : [];
        return [
            'domain_key' => sanitize_key((string) ($domain['domain_key'] ?? '')),
            'label' => sanitize_text_field((string) ($domain['label'] ?? '')),
            'option_names' => array_values((array) ($domain['option_names'] ?? [])),
            'mode' => sanitize_key((string) ($domain['mode'] ?? '')),
            'available' => ! empty($domain['available']),
            'high_risk' => ! empty($domain['high_risk']),
            'verification' => sanitize_key((string) ($domain['verification'] ?? '')),
        ];
    }

    /**
     * @param mixed $record
     * @return array<string, mixed>
     */
    private static function prepare_export_record($record)
    {
        $record = is_array($record) ? $record : [];
        $export_id = sanitize_key((string) ($record['export_id'] ?? $record['package_id'] ?? ''));
        $record['download_url'] = $export_id !== '' ? DBVC_Bricks_Portability::get_export_download_url($export_id) : '';
        return $record;
    }

    /**
     * @param mixed $record
     * @return array<string, mixed>
     */
    private static function prepare_session_record($record)
    {
        return is_array($record) ? $record : [];
    }

    /**
     * @param mixed $record
     * @return array<string, mixed>
     */
    private static function prepare_backup_record($record)
    {
        return is_array($record) ? $record : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function get_recent_jobs()
    {
        if (! class_exists('DBVC_Database') || ! method_exists('DBVC_Database', 'get_jobs')) {
            return [];
        }

        $rows = DBVC_Database::get_jobs(['limit' => 25]);
        if (! is_array($rows)) {
            return [];
        }

        $jobs = [];
        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }
            $type = sanitize_key((string) ($row->job_type ?? ''));
            if (strpos($type, 'bricks_portability_') !== 0) {
                continue;
            }
            $jobs[] = [
                'id' => (int) ($row->id ?? 0),
                'job_type' => $type,
                'status' => sanitize_key((string) ($row->status ?? '')),
                'updated_at' => sanitize_text_field((string) ($row->updated_at ?? '')),
                'context' => DBVC_Bricks_Portability_Utils::normalize_job_context($row->context ?? null),
            ];
        }

        return $jobs;
    }

    /**
     * @param \WP_REST_Request $request
     * @param string $scope
     * @return array<string, string>|\WP_REST_Response|\WP_Error
     */
    private static function require_idempotency(\WP_REST_Request $request, $scope)
    {
        if (! class_exists('DBVC_Bricks_Idempotency')) {
            return ['scope' => sanitize_key((string) $scope), 'key' => ''];
        }

        $key = DBVC_Bricks_Idempotency::extract_key($request);
        if ($key === '') {
            return new \WP_Error('dbvc_bricks_idempotency_required', 'Idempotency-Key is required.', ['status' => 400]);
        }

        $scope = sanitize_key((string) $scope);
        $cached = DBVC_Bricks_Idempotency::get($scope, $key);
        if (is_array($cached) && isset($cached['response']) && is_array($cached['response'])) {
            $response = $cached['response'];
            $response['idempotent_replay'] = true;
            return rest_ensure_response($response);
        }

        return [
            'scope' => $scope,
            'key' => $key,
        ];
    }

    /**
     * @param array<string, string> $replay
     * @param array<string, mixed> $response
     * @return void
     */
    private static function store_idempotency(array $replay, array $response)
    {
        if (! class_exists('DBVC_Bricks_Idempotency')) {
            return;
        }
        $scope = sanitize_key((string) ($replay['scope'] ?? ''));
        $key = sanitize_text_field((string) ($replay['key'] ?? ''));
        if ($scope === '' || $key === '') {
            return;
        }
        DBVC_Bricks_Idempotency::put($scope, $key, $response);
    }
}
