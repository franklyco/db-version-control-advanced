<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Import_Plan_REST_Controller
{
    /**
     * @var DBVC_CC_Import_Plan_REST_Controller|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_Import_Plan_REST_Controller
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
            '/import-plan/dry-run',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_dry_run_plan'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'domain' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'path' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'build_if_missing' => ['required' => false, 'sanitize_callback' => 'rest_sanitize_boolean'],
                ],
            ]
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_dry_run_plan($request)
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

        $result = DBVC_CC_Import_Plan_Service::get_instance()->get_dry_run_plan(
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
