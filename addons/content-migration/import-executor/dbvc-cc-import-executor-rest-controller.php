<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Import_Executor_REST_Controller
{
    /**
     * @var DBVC_CC_Import_Executor_REST_Controller|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_Import_Executor_REST_Controller
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * @return void
     */
    public function register_routes()
    {
        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/import-executor/dry-run',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_dry_run_execution'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'domain' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'path' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'build_if_missing' => ['required' => false, 'sanitize_callback' => 'rest_sanitize_boolean'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/import-executor/execute',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'execute_write_skeleton'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'domain' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'path' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'build_if_missing' => ['required' => false, 'sanitize_callback' => 'rest_sanitize_boolean'],
                    'confirm_execute' => ['required' => false, 'sanitize_callback' => 'rest_sanitize_boolean'],
                    'approval_token' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/import-executor/preflight-approve',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'approve_preflight'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'domain' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'path' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'build_if_missing' => ['required' => false, 'sanitize_callback' => 'rest_sanitize_boolean'],
                    'confirm_approval' => ['required' => false, 'sanitize_callback' => 'rest_sanitize_boolean'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/import-executor/preflight-status',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_preflight_status'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'domain' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'path' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'build_if_missing' => ['required' => false, 'sanitize_callback' => 'rest_sanitize_boolean'],
                    'approval_token' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/import-executor/run',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_run_details'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'run_id' => ['required' => false, 'sanitize_callback' => 'absint'],
                    'run_uuid' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/import-executor/runs',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'list_runs'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'domain' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                    'path' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                    'limit' => ['required' => false, 'sanitize_callback' => 'absint'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/import-executor/rollback',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'rollback_run'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'run_id' => ['required' => false, 'sanitize_callback' => 'absint'],
                    'run_uuid' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_dry_run_execution($request)
    {
        $params = $this->extract_request_params($request);
        $dbvc_cc_validated_path = dbvc_cc_validate_required_relative_path(
            isset($params['path']) ? (string) $params['path'] : '',
            __('A valid page path is required.', 'dbvc'),
            __('Invalid page path.', 'dbvc')
        );
        if (is_wp_error($dbvc_cc_validated_path)) {
            return $dbvc_cc_validated_path;
        }

        $result = DBVC_CC_Import_Executor_Service::get_instance()->execute_dry_run(
            isset($params['domain']) ? (string) $params['domain'] : '',
            (string) $dbvc_cc_validated_path,
            isset($params['build_if_missing']) ? rest_sanitize_boolean($params['build_if_missing']) : true
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function execute_write_skeleton($request)
    {
        $params = $this->extract_request_params($request);
        $dbvc_cc_validated_path = dbvc_cc_validate_required_relative_path(
            isset($params['path']) ? (string) $params['path'] : '',
            __('A valid page path is required.', 'dbvc'),
            __('Invalid page path.', 'dbvc')
        );
        if (is_wp_error($dbvc_cc_validated_path)) {
            return $dbvc_cc_validated_path;
        }

        $result = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            isset($params['domain']) ? (string) $params['domain'] : '',
            (string) $dbvc_cc_validated_path,
            isset($params['build_if_missing']) ? rest_sanitize_boolean($params['build_if_missing']) : true,
            isset($params['confirm_execute']) ? rest_sanitize_boolean($params['confirm_execute']) : false,
            isset($params['approval_token']) ? (string) $params['approval_token'] : ''
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function approve_preflight($request)
    {
        $params = $this->extract_request_params($request);
        $dbvc_cc_validated_path = dbvc_cc_validate_required_relative_path(
            isset($params['path']) ? (string) $params['path'] : '',
            __('A valid page path is required.', 'dbvc'),
            __('Invalid page path.', 'dbvc')
        );
        if (is_wp_error($dbvc_cc_validated_path)) {
            return $dbvc_cc_validated_path;
        }

        $result = DBVC_CC_Import_Executor_Service::get_instance()->approve_preflight(
            isset($params['domain']) ? (string) $params['domain'] : '',
            (string) $dbvc_cc_validated_path,
            isset($params['build_if_missing']) ? rest_sanitize_boolean($params['build_if_missing']) : true,
            isset($params['confirm_approval']) ? rest_sanitize_boolean($params['confirm_approval']) : false
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_preflight_status($request)
    {
        $params = $this->extract_request_params($request);
        $dbvc_cc_validated_path = dbvc_cc_validate_required_relative_path(
            isset($params['path']) ? (string) $params['path'] : '',
            __('A valid page path is required.', 'dbvc'),
            __('Invalid page path.', 'dbvc')
        );
        if (is_wp_error($dbvc_cc_validated_path)) {
            return $dbvc_cc_validated_path;
        }

        $result = DBVC_CC_Import_Executor_Service::get_instance()->get_preflight_status(
            isset($params['domain']) ? (string) $params['domain'] : '',
            (string) $dbvc_cc_validated_path,
            isset($params['approval_token']) ? (string) $params['approval_token'] : '',
            isset($params['build_if_missing']) ? rest_sanitize_boolean($params['build_if_missing']) : true
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_run_details($request)
    {
        $params = $this->extract_request_params($request);
        $run_lookup = $this->resolve_run_lookup($params);
        if (is_wp_error($run_lookup)) {
            return $run_lookup;
        }

        $result = DBVC_CC_Import_Executor_Service::get_instance()->get_run_details($run_lookup);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function list_runs($request)
    {
        $params = $this->extract_request_params($request);
        $result = DBVC_CC_Import_Executor_Service::get_instance()->list_runs(
            isset($params['domain']) ? (string) $params['domain'] : '',
            isset($params['path']) ? (string) $params['path'] : '',
            isset($params['limit']) ? absint($params['limit']) : 20
        );

        return rest_ensure_response($result);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rollback_run($request)
    {
        $params = $this->extract_request_params($request);
        $run_lookup = $this->resolve_run_lookup($params);
        if (is_wp_error($run_lookup)) {
            return $run_lookup;
        }

        $result = DBVC_CC_Import_Executor_Service::get_instance()->rollback_run($run_lookup);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * @return bool
     */
    public function permissions_check()
    {
        return current_user_can(DBVC_CC_Contracts::ADMIN_CAPABILITY);
    }

    /**
     * @param WP_REST_Request $request
     * @return array<string, mixed>
     */
    private function extract_request_params($request)
    {
        $params = $request->get_params();
        $json_params = $request->get_json_params();
        if (is_array($json_params) && ! empty($json_params)) {
            $params = array_merge($params, $json_params);
        }

        return is_array($params) ? $params : [];
    }

    /**
     * @param array<string, mixed> $params
     * @return int|string|WP_Error
     */
    private function resolve_run_lookup(array $params = [])
    {
        $run_id = isset($params['run_id']) ? absint($params['run_id']) : 0;
        if ($run_id > 0) {
            return $run_id;
        }

        $run_uuid = isset($params['run_uuid']) ? sanitize_text_field((string) $params['run_uuid']) : '';
        if ($run_uuid !== '') {
            return $run_uuid;
        }

        return new WP_Error(
            'dbvc_cc_import_run_lookup_required',
            __('A valid import run ID or UUID is required.', 'dbvc'),
            ['status' => 400]
        );
    }
}
