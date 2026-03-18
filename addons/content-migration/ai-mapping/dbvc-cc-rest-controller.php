<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_AI_REST_Controller
{
    /**
     * @var DBVC_CC_AI_REST_Controller|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_AI_REST_Controller
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
            '/ai/rerun',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'queue_rerun'],
                'permission_callback' => [$this, 'permissions_check'],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/ai/rerun-branch',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'queue_branch_rerun'],
                'permission_callback' => [$this, 'permissions_check'],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/ai/status',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_status'],
                'permission_callback' => [$this, 'permissions_check'],
                'args' => [
                    'domain' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                    'path' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                    'job_id' => ['required' => false, 'sanitize_callback' => 'sanitize_key'],
                    'batch_id' => ['required' => false, 'sanitize_callback' => 'sanitize_key'],
                ],
            ]
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function queue_rerun($request)
    {
        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = $request->get_params();
        }

        $domain = isset($params['domain']) ? (string) $params['domain'] : '';
        $path = isset($params['path']) ? (string) $params['path'] : '';
        $run_now = isset($params['run_now']) ? rest_sanitize_boolean($params['run_now']) : true;

        $result = DBVC_CC_AI_Service::get_instance()->queue_job(
            $domain,
            $path,
            'manual_rerun',
            get_current_user_id(),
            $run_now
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
    public function queue_branch_rerun($request)
    {
        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = $request->get_params();
        }

        $domain = isset($params['domain']) ? (string) $params['domain'] : '';
        $path = isset($params['path']) ? (string) $params['path'] : '';
        $run_now = isset($params['run_now']) ? rest_sanitize_boolean($params['run_now']) : false;
        $max_jobs = isset($params['max_jobs']) ? absint($params['max_jobs']) : 150;
        $offset = isset($params['offset']) ? absint($params['offset']) : 0;

        $result = DBVC_CC_AI_Service::get_instance()->queue_branch_jobs(
            $domain,
            $path,
            'manual_branch_rerun',
            get_current_user_id(),
            $run_now,
            $max_jobs,
            $offset
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
    public function get_status($request)
    {
        $batch_id = isset($request['batch_id']) ? (string) $request['batch_id'] : '';
        if ($batch_id !== '') {
            $result = DBVC_CC_AI_Service::get_instance()->get_status_by_batch_id($batch_id);
            if (is_wp_error($result)) {
                return $result;
            }

            return rest_ensure_response($result);
        }

        $job_id = isset($request['job_id']) ? (string) $request['job_id'] : '';
        if ($job_id !== '') {
            $result = DBVC_CC_AI_Service::get_instance()->get_status_by_job_id($job_id);
            if (is_wp_error($result)) {
                return $result;
            }

            return rest_ensure_response($result);
        }

        $domain = isset($request['domain']) ? (string) $request['domain'] : '';
        $path = isset($request['path']) ? (string) $request['path'] : '';
        if ($domain === '' || $path === '') {
            return new WP_Error(
                'dbvc_cc_ai_status_params',
                __('Provide batch_id, job_id, or both domain and path.', 'dbvc'),
                ['status' => 400]
            );
        }

        $result = DBVC_CC_AI_Service::get_instance()->get_status($domain, $path);
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
}
