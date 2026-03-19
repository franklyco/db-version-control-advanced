<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Import_REST_Controller
{
    /**
     * @var DBVC_CC_V2_Import_REST_Controller|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Import_REST_Controller
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
            '/runs/(?P<run_id>[\w-]+)/dry-run',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_dry_run_surface'],
                'permission_callback' => [$this, 'permissions_check'],
            ]
        );

        register_rest_route(
            DBVC_CC_V2_Contracts::REST_NAMESPACE,
            '/runs/(?P<run_id>[\w-]+)/preflight-approve',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'approve_preflight'],
                'permission_callback' => [$this, 'permissions_check'],
            ]
        );

        register_rest_route(
            DBVC_CC_V2_Contracts::REST_NAMESPACE,
            '/runs/(?P<run_id>[\w-]+)/execute',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'execute_package_import'],
                'permission_callback' => [$this, 'permissions_check'],
            ]
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_dry_run_surface($request)
    {
        $run_id = sanitize_text_field((string) $request['run_id']);
        $package_id = isset($request['packageId']) ? sanitize_text_field((string) $request['packageId']) : '';

        $payload = DBVC_CC_V2_Import_Plan_Bridge_Service::get_instance()->get_dry_run_surface($run_id, $package_id);
        if (is_wp_error($payload)) {
            return $payload;
        }

        return rest_ensure_response($payload);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function approve_preflight($request)
    {
        $run_id = sanitize_text_field((string) $request['run_id']);
        $package_id = isset($request['packageId']) ? sanitize_text_field((string) $request['packageId']) : '';
        $confirm_approval = isset($request['confirmApproval'])
            ? rest_sanitize_boolean($request['confirmApproval'])
            : true;

        $payload = DBVC_CC_V2_Import_Execution_Bridge_Service::get_instance()->approve_package_preflight(
            $run_id,
            $package_id,
            $confirm_approval
        );
        if (is_wp_error($payload)) {
            return $payload;
        }

        return rest_ensure_response($payload);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function execute_package_import($request)
    {
        $run_id = sanitize_text_field((string) $request['run_id']);
        $package_id = isset($request['packageId']) ? sanitize_text_field((string) $request['packageId']) : '';
        $confirm_execute = isset($request['confirmExecute'])
            ? rest_sanitize_boolean($request['confirmExecute'])
            : true;
        $approval_tokens = isset($request['approvalTokens']) && is_array($request['approvalTokens'])
            ? $request['approvalTokens']
            : [];

        $payload = DBVC_CC_V2_Import_Execution_Bridge_Service::get_instance()->execute_package_import(
            $run_id,
            $package_id,
            $confirm_execute,
            $this->sanitize_approval_tokens($approval_tokens)
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
     * @param array<string, mixed> $approval_tokens
     * @return array<string, string>
     */
    private function sanitize_approval_tokens(array $approval_tokens)
    {
        $sanitized = [];
        foreach ($approval_tokens as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $token = sanitize_text_field((string) $value);
            if ($token === '') {
                continue;
            }

            $sanitized[sanitize_text_field((string) $key)] = $token;
        }

        return $sanitized;
    }
}
