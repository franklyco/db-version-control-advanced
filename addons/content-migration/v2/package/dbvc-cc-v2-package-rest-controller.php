<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Package_REST_Controller
{
    /**
     * @var DBVC_CC_V2_Package_REST_Controller|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Package_REST_Controller
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @return void
     */
    public function register_routes()
    {
        register_rest_route(
            DBVC_CC_V2_Contracts::REST_NAMESPACE,
            '/runs/(?P<run_id>[\w-]+)/package',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_package_surface'],
                    'permission_callback' => [$this, 'permissions_check'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'build_package'],
                    'permission_callback' => [$this, 'permissions_check'],
                ],
            ]
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_package_surface($request)
    {
        $run_id = sanitize_text_field((string) $request['run_id']);
        $package_id = isset($request['packageId']) ? sanitize_text_field((string) $request['packageId']) : '';
        $payload = DBVC_CC_V2_Package_Build_Service::get_instance()->get_package_surface($run_id, $package_id);
        if (is_wp_error($payload)) {
            return $payload;
        }

        return rest_ensure_response($payload);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function build_package($request)
    {
        $run_id = sanitize_text_field((string) $request['run_id']);
        $payload = DBVC_CC_V2_Package_Build_Service::get_instance()->build_package(
            $run_id,
            [
                'actor' => 'admin',
                'trigger' => 'rest',
            ]
        );
        if (is_wp_error($payload)) {
            return $payload;
        }

        return rest_ensure_response($payload);
    }

    /**
     * @return bool
     */
    public function permissions_check()
    {
        return current_user_can(DBVC_CC_Contracts::ADMIN_CAPABILITY);
    }
}
