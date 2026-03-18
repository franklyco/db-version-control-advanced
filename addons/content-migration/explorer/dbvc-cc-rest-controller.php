<?php
/**
 * Registers REST API routes for Explorer and content preview flows.
 *
 * @package ContentCollector
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class DBVC_CC_Explorer_REST_Controller {

    private static $instance = null;

    /**
     * Singleton bootstrap.
     *
     * @return DBVC_CC_Explorer_REST_Controller
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Registers Explorer REST routes.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/explorer/domains',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_domains'],
                'permission_callback' => [$this, 'permissions_check'],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/explorer/tree',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_tree'],
                'permission_callback' => [$this, 'permissions_check'],
                'args'                => [
                    'domain'        => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'depth'         => ['required' => false, 'sanitize_callback' => 'absint'],
                    'max_nodes'     => ['required' => false, 'sanitize_callback' => 'absint'],
                    'include_files' => ['required' => false, 'sanitize_callback' => 'rest_sanitize_boolean'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/explorer/node/children',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_children'],
                'permission_callback' => [$this, 'permissions_check'],
                'args'                => [
                    'domain'        => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'path'          => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                    'include_files' => ['required' => false, 'sanitize_callback' => 'rest_sanitize_boolean'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/explorer/node',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_node'],
                'permission_callback' => [$this, 'permissions_check'],
                'args'                => [
                    'domain' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'path'   => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/explorer/content',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_content'],
                'permission_callback' => [$this, 'permissions_check'],
                'args'                => [
                    'domain' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'path'   => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'mode'   => ['required' => false, 'sanitize_callback' => 'sanitize_key'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/explorer/content-context',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'dbvc_cc_get_content_context'],
                'permission_callback' => [$this, 'permissions_check'],
                'args'                => [
                    'domain'   => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'path'     => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'artifact' => ['required' => false, 'sanitize_callback' => 'sanitize_key'],
                    'limit'    => ['required' => false, 'sanitize_callback' => 'absint'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/explorer/scrub-policy-preview',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'dbvc_cc_get_scrub_policy_preview'],
                'permission_callback' => [$this, 'permissions_check'],
                'args'                => [
                    'domain'      => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'path'        => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'sample_size' => ['required' => false, 'sanitize_callback' => 'absint'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/explorer/scrub-policy-approval-status',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'dbvc_cc_get_scrub_policy_approval_status'],
                'permission_callback' => [$this, 'permissions_check'],
                'args'                => [
                    'domain' => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                    'path'   => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/explorer/scrub-policy-approve',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'dbvc_cc_post_scrub_policy_approve'],
                'permission_callback' => [$this, 'permissions_check'],
                'args'                => [
                    'domain'      => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'path'        => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'sample_size' => ['required' => false, 'sanitize_callback' => 'absint'],
                    'profile_mode'=> ['required' => false, 'sanitize_callback' => 'sanitize_key'],
                ],
            ]
        );

        register_rest_route(
            DBVC_CC_Contracts::REST_NAMESPACE,
            '/explorer/node/audit',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_node_audit'],
                'permission_callback' => [$this, 'permissions_check'],
                'args'                => [
                    'domain' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                    'path'   => ['required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                    'limit'  => ['required' => false, 'sanitize_callback' => 'absint'],
                    'pipeline_id' => ['required' => false, 'sanitize_callback' => 'sanitize_key'],
                ],
            ]
        );
    }

    /**
     * Returns available domain crawls.
     *
     * @return WP_REST_Response
     */
    public function get_domains() {
        $domains = DBVC_CC_Explorer_Service::get_instance()->get_domains();
        return rest_ensure_response(
            [
                'domains' => $domains,
            ]
        );
    }

    /**
     * Returns Explorer tree payload.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_tree($request) {
        $result = DBVC_CC_Explorer_Service::get_instance()->get_tree($request->get_params());
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * Returns lazy-loaded child payload.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_children($request) {
        $result = DBVC_CC_Explorer_Service::get_instance()->get_children($request->get_params());
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * Returns node details.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_node($request) {
        $result = DBVC_CC_Explorer_Service::get_instance()->get_node($request->get_params());
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * Returns content preview for selected node.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_content($request) {
        $result = DBVC_CC_Explorer_Service::get_instance()->get_content_preview($request->get_params());
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * Returns content-context sidecar payload for selected node.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function dbvc_cc_get_content_context($request) {
        $result = DBVC_CC_Explorer_Service::get_instance()->dbvc_cc_get_content_context_payload($request->get_params());
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * Returns deterministic scrub policy preview for selected node.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function dbvc_cc_get_scrub_policy_preview($request) {
        $result = DBVC_CC_Explorer_Service::get_instance()->dbvc_cc_get_scrub_policy_preview_payload($request->get_params());
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * Returns scrub suggestion approval status payload.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function dbvc_cc_get_scrub_policy_approval_status($request) {
        $result = DBVC_CC_Explorer_Service::get_instance()->dbvc_cc_get_scrub_policy_approval_status_payload($request->get_params());
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * Applies approved scrub suggestions to current configure defaults.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function dbvc_cc_post_scrub_policy_approve($request) {
        $params = $request->get_params();
        $json_params = $request->get_json_params();
        if (is_array($json_params) && !empty($json_params)) {
            $params = array_merge($params, $json_params);
        }

        $result = DBVC_CC_Explorer_Service::get_instance()->dbvc_cc_post_scrub_policy_approve_payload($params);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * Returns audit trail events for the selected node path.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_node_audit($request) {
        $result = DBVC_CC_Explorer_Service::get_instance()->get_node_audit($request->get_params());
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * Permission callback for Explorer endpoints.
     *
     * @return bool
     */
    public function permissions_check() {
        return current_user_can('manage_options');
    }
}
