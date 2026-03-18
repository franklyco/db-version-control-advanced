<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Workbench_REST_Controller
{
    /**
     * @var DBVC_CC_Workbench_REST_Controller|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_Workbench_REST_Controller
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
            '/workbench/domains',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'dbvc_cc_get_domains'],
                'permission_callback' => [$this, 'permissions_check'],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/workbench/review-queue',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_review_queue'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'domain' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                    'limit' => ['required' => false, 'sanitize_callback' => 'absint'],
                    'include_decided' => ['required' => false, 'sanitize_callback' => 'rest_sanitize_boolean'],
                    'min_confidence' => ['required' => false],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/workbench/suggestions',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_suggestions'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'domain' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'path' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/workbench/decision',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'save_decision'],
                'permission_callback' => [$this, 'permissions_check'],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/mapping/candidates',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_mapping_candidates'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'domain' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'path' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'build_if_missing' => ['required' => false, 'sanitize_callback' => 'rest_sanitize_boolean'],
                    'force_rebuild' => ['required' => false, 'sanitize_callback' => 'rest_sanitize_boolean'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/mapping/candidates/build',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'build_mapping_candidates'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'domain' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'path' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'force_rebuild' => ['required' => false, 'sanitize_callback' => 'rest_sanitize_boolean'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/mapping/decision',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_mapping_decision'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'domain' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'path' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/mapping/decision',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'save_mapping_decision'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'domain' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'path' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/mapping/handoff',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_mapping_handoff'],
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
            '/mapping/domain/rebuild',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'dbvc_cc_rebuild_mapping_domain'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'domain' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'refresh_catalog' => ['required' => false, 'sanitize_callback' => 'rest_sanitize_boolean'],
                    'force_rebuild' => ['required' => false, 'sanitize_callback' => 'rest_sanitize_boolean'],
                    'run_now' => ['required' => false, 'sanitize_callback' => 'rest_sanitize_boolean'],
                    'batch_size' => ['required' => false, 'sanitize_callback' => 'absint'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/mapping/domain/rebuild/status',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'dbvc_cc_get_mapping_rebuild_batch_status'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'batch_id' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_review_queue($request)
    {
        $result = DBVC_CC_Workbench_Service::get_instance()->get_review_queue($request->get_params());
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * @return WP_REST_Response
     */
    public function dbvc_cc_get_domains()
    {
        return rest_ensure_response(
            [
                'domains' => DBVC_CC_Workbench_Service::get_instance()->dbvc_cc_get_domains(),
            ]
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_suggestions($request)
    {
        $params = $this->extract_request_params($request);
        $dbvc_cc_validated_path = $this->dbvc_cc_get_validated_path_param($params);
        if (is_wp_error($dbvc_cc_validated_path)) {
            return $dbvc_cc_validated_path;
        }

        $result = DBVC_CC_Workbench_Service::get_instance()->get_suggestions(
            isset($params['domain']) ? (string) $params['domain'] : '',
            (string) $dbvc_cc_validated_path
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
    public function save_decision($request)
    {
        $params = $this->extract_request_params($request);
        $dbvc_cc_validated_path = $this->dbvc_cc_get_validated_path_param($params);
        if (is_wp_error($dbvc_cc_validated_path)) {
            return $dbvc_cc_validated_path;
        }

        $domain = isset($params['domain']) ? (string) $params['domain'] : '';
        $path = (string) $dbvc_cc_validated_path;

        $result = DBVC_CC_Workbench_Service::get_instance()->save_decision(
            $domain,
            $path,
            $params,
            get_current_user_id()
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
    public function get_mapping_candidates($request)
    {
        $params = $this->extract_request_params($request);
        $dbvc_cc_validated_path = $this->dbvc_cc_get_validated_path_param($params);
        if (is_wp_error($dbvc_cc_validated_path)) {
            return $dbvc_cc_validated_path;
        }
        $domain = isset($params['domain']) ? (string) $params['domain'] : '';
        $path = (string) $dbvc_cc_validated_path;
        $force_rebuild = isset($params['force_rebuild']) ? rest_sanitize_boolean($params['force_rebuild']) : false;
        $build_if_missing = isset($params['build_if_missing']) ? rest_sanitize_boolean($params['build_if_missing']) : true;

        if ($force_rebuild) {
            $result = DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates($domain, $path, true);
        } else {
            $result = DBVC_CC_Section_Field_Candidate_Service::get_instance()->get_candidates($domain, $path, $build_if_missing);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function build_mapping_candidates($request)
    {
        $params = $this->extract_request_params($request);
        $dbvc_cc_validated_path = $this->dbvc_cc_get_validated_path_param($params);
        if (is_wp_error($dbvc_cc_validated_path)) {
            return $dbvc_cc_validated_path;
        }
        $domain = isset($params['domain']) ? (string) $params['domain'] : '';
        $path = (string) $dbvc_cc_validated_path;
        $force_rebuild = isset($params['force_rebuild']) ? rest_sanitize_boolean($params['force_rebuild']) : true;

        $result = DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates($domain, $path, $force_rebuild);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_mapping_decision($request)
    {
        $params = $this->extract_request_params($request);
        $dbvc_cc_validated_path = $this->dbvc_cc_get_validated_path_param($params);
        if (is_wp_error($dbvc_cc_validated_path)) {
            return $dbvc_cc_validated_path;
        }
        $result = DBVC_CC_Mapping_Decision_Service::get_instance()->get_decision(
            isset($params['domain']) ? (string) $params['domain'] : '',
            (string) $dbvc_cc_validated_path
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
    public function save_mapping_decision($request)
    {
        $params = $this->extract_request_params($request);
        $dbvc_cc_validated_path = $this->dbvc_cc_get_validated_path_param($params);
        if (is_wp_error($dbvc_cc_validated_path)) {
            return $dbvc_cc_validated_path;
        }
        $result = DBVC_CC_Mapping_Decision_Service::get_instance()->save_decision(
            isset($params['domain']) ? (string) $params['domain'] : '',
            (string) $dbvc_cc_validated_path,
            $params,
            get_current_user_id()
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
    public function get_mapping_handoff($request)
    {
        $params = $this->extract_request_params($request);
        $dbvc_cc_validated_path = $this->dbvc_cc_get_validated_path_param($params);
        if (is_wp_error($dbvc_cc_validated_path)) {
            return $dbvc_cc_validated_path;
        }
        $build_if_missing = isset($params['build_if_missing']) ? rest_sanitize_boolean($params['build_if_missing']) : true;
        $result = DBVC_CC_Import_Plan_Handoff_Service::get_instance()->get_handoff_payload(
            isset($params['domain']) ? (string) $params['domain'] : '',
            (string) $dbvc_cc_validated_path,
            $build_if_missing
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
    public function dbvc_cc_rebuild_mapping_domain($request)
    {
        $params = $this->extract_request_params($request);
        $result = DBVC_CC_Mapping_Rebuild_Service::get_instance()->dbvc_cc_queue_domain_mapping_rebuild(
            isset($params['domain']) ? (string) $params['domain'] : '',
            [
                'refresh_catalog' => isset($params['refresh_catalog']) ? $params['refresh_catalog'] : false,
                'force_rebuild' => isset($params['force_rebuild']) ? $params['force_rebuild'] : true,
                'run_now' => isset($params['run_now']) ? $params['run_now'] : false,
                'batch_size' => isset($params['batch_size']) ? $params['batch_size'] : 20,
                'requested_by' => get_current_user_id(),
            ]
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
    public function dbvc_cc_get_mapping_rebuild_batch_status($request)
    {
        $params = $this->extract_request_params($request);
        $result = DBVC_CC_Mapping_Rebuild_Service::get_instance()->dbvc_cc_get_batch_status(
            isset($params['batch_id']) ? (string) $params['batch_id'] : ''
        );

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
     * @return string|WP_Error
     */
    private function dbvc_cc_get_validated_path_param(array $params)
    {
        return dbvc_cc_validate_required_relative_path(
            isset($params['path']) ? (string) $params['path'] : '',
            __('A valid page path is required.', 'dbvc'),
            __('Invalid page path.', 'dbvc')
        );
    }
}
