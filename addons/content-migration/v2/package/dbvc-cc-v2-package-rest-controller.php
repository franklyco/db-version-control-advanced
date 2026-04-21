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

    /**
     * @return void
     */
    public function download_artifact()
    {
        if (! $this->permissions_check()) {
            status_header(403);
            wp_die(esc_html__('You are not allowed to download V2 package artifacts.', 'dbvc'));
        }

        $run_id = isset($_GET['runId']) ? sanitize_text_field(wp_unslash((string) $_GET['runId'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $package_id = isset($_GET['packageId']) ? sanitize_text_field(wp_unslash((string) $_GET['packageId'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $artifact_key = isset($_GET['artifact']) ? sanitize_key(wp_unslash((string) $_GET['artifact'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $service = DBVC_CC_V2_Package_Artifact_Service::get_instance();

        if (! wp_verify_nonce($nonce, $service->get_download_nonce_action($run_id, $package_id, $artifact_key))) {
            status_header(403);
            wp_die(esc_html__('The V2 package artifact download link is no longer valid.', 'dbvc'));
        }

        $artifact = $service->resolve_artifact_download($run_id, $package_id, $artifact_key);
        if (is_wp_error($artifact)) {
            $data = $artifact->get_error_data();
            if (is_array($data) && ! empty($data['status'])) {
                status_header((int) $data['status']);
            }

            wp_die(esc_html($artifact->get_error_message()));
        }

        $path = isset($artifact['path']) ? (string) $artifact['path'] : '';
        if ($path === '' || ! file_exists($path)) {
            status_header(404);
            wp_die(esc_html__('The requested V2 package artifact file could not be found.', 'dbvc'));
        }

        nocache_headers();
        header('Content-Type: ' . (isset($artifact['contentType']) ? (string) $artifact['contentType'] : 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . sanitize_file_name(isset($artifact['fileName']) ? (string) $artifact['fileName'] : basename($path)) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');

        readfile($path);
        exit;
    }
}
