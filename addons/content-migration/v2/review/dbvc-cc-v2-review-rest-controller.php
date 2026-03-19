<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Review_REST_Controller
{
    /**
     * @var DBVC_CC_V2_Review_REST_Controller|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Review_REST_Controller
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
            '/runs/(?P<run_id>[\w-]+)/exceptions',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_exceptions'],
                'permission_callback' => [$this, 'permissions_check'],
            ]
        );

        register_rest_route(
            DBVC_CC_V2_Contracts::REST_NAMESPACE,
            '/runs/(?P<run_id>[\w-]+)/urls/(?P<page_id>[\w-]+)',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_url_review'],
                'permission_callback' => [$this, 'permissions_check'],
            ]
        );

        register_rest_route(
            DBVC_CC_V2_Contracts::REST_NAMESPACE,
            '/runs/(?P<run_id>[\w-]+)/urls/(?P<page_id>[\w-]+)/decision',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'save_url_decision'],
                'permission_callback' => [$this, 'permissions_check'],
            ]
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_exceptions($request)
    {
        $run_id = sanitize_text_field((string) $request['run_id']);
        $payload = DBVC_CC_V2_Exception_Queue_Service::get_instance()->get_queue(
            $run_id,
            $this->extract_request_params($request)
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
    public function get_url_review($request)
    {
        $run_id = sanitize_text_field((string) $request['run_id']);
        $page_id = sanitize_text_field((string) $request['page_id']);
        $domain = DBVC_CC_V2_Domain_Journey_Service::get_instance()->find_domain_by_journey_id($run_id);
        if ($domain === '') {
            return new WP_Error(
                'dbvc_cc_v2_run_missing',
                __('The requested V2 run could not be found.', 'dbvc'),
                ['status' => 404]
            );
        }

        $payload = DBVC_CC_V2_Recommendation_Review_Service::get_instance()->get_review_payload(
            $domain,
            $run_id,
            $page_id,
            [
                'record_presentation' => true,
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
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function save_url_decision($request)
    {
        $run_id = sanitize_text_field((string) $request['run_id']);
        $page_id = sanitize_text_field((string) $request['page_id']);
        $domain = DBVC_CC_V2_Domain_Journey_Service::get_instance()->find_domain_by_journey_id($run_id);
        if ($domain === '') {
            return new WP_Error(
                'dbvc_cc_v2_run_missing',
                __('The requested V2 run could not be found.', 'dbvc'),
                ['status' => 404]
            );
        }

        $payload = DBVC_CC_V2_Recommendation_Review_Service::get_instance()->save_decisions(
            $domain,
            $run_id,
            $page_id,
            $this->extract_request_params($request),
            get_current_user_id()
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
