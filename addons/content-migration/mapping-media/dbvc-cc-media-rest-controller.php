<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Media_REST_Controller
{
    /**
     * @var DBVC_CC_Media_REST_Controller|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_Media_REST_Controller
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
            '/mapping/media/candidates',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_candidates'],
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
            '/mapping/media/candidates/build',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'build_candidates'],
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
            '/mapping/media/decision',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_decision'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'domain' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'path' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/mapping/media/decision',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'save_decision'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'domain' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'path' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_candidates($request)
    {
        $params = $this->extract_request_params($request);
        $domain = isset($params['domain']) ? (string) $params['domain'] : '';
        $path = isset($params['path']) ? (string) $params['path'] : '';
        $force_rebuild = isset($params['force_rebuild']) ? rest_sanitize_boolean($params['force_rebuild']) : false;
        $build_if_missing = isset($params['build_if_missing']) ? rest_sanitize_boolean($params['build_if_missing']) : true;

        if ($force_rebuild) {
            $result = DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates($domain, $path, true);
        } else {
            $result = DBVC_CC_Media_Candidate_Service::get_instance()->get_candidates($domain, $path, $build_if_missing);
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
    public function build_candidates($request)
    {
        $params = $this->extract_request_params($request);
        $domain = isset($params['domain']) ? (string) $params['domain'] : '';
        $path = isset($params['path']) ? (string) $params['path'] : '';
        $force_rebuild = isset($params['force_rebuild']) ? rest_sanitize_boolean($params['force_rebuild']) : true;

        $result = DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates($domain, $path, $force_rebuild);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_decision($request)
    {
        $params = $this->extract_request_params($request);
        $domain = isset($params['domain']) ? (string) $params['domain'] : '';
        $path = isset($params['path']) ? (string) $params['path'] : '';

        $result = DBVC_CC_Media_Decision_Service::get_instance()->get_decision($domain, $path);
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
        $domain = isset($params['domain']) ? (string) $params['domain'] : '';
        $path = isset($params['path']) ? (string) $params['path'] : '';

        $result = DBVC_CC_Media_Decision_Service::get_instance()->save_decision(
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
}
