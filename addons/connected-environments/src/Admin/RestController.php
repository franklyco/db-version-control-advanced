<?php

namespace Dbvc\Connected\Admin;

/**
 * Administrator REST routes for the Connected Environments page (connector
 * role): thin wrappers over `DBVC_Connected_CLI_Inspector`, cookie-authenticated
 * (REST nonce) and capability-gated. Registered only while the connector gate
 * is ready. Machine routes for the hub stay on `dbvc-agency/v1` with
 * application-password principals; nothing here accepts one.
 */
final class RestController
{
    public const REST_NAMESPACE = 'dbvc/v1';
    public const CAPABILITY = 'manage_options';

    /**
     * @return void
     */
    public function register_routes()
    {
        $reads = ['status', 'jobs', 'objects', 'outbox', 'inbox', 'inventory', 'preparations', 'operations'];
        foreach ($reads as $method) {
            register_rest_route(self::REST_NAMESPACE, '/connected/' . $method, [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => function (\WP_REST_Request $request) use ($method) {
                    return $this->call($method, $request->get_query_params());
                },
                'permission_callback' => [$this, 'permission'],
            ]);
        }
        register_rest_route(self::REST_NAMESPACE, '/connected/run/(?P<runner>process|deliver|poll|release)', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => function (\WP_REST_Request $request) {
                $body = $request->get_json_params();

                return $this->call((string) $request->get_param('runner'), is_array($body) ? $body : []);
            },
            'permission_callback' => [$this, 'permission'],
        ]);
        foreach (['reconcile', 'enroll', 'resume'] as $method) {
            register_rest_route(self::REST_NAMESPACE, '/connected/' . $method, [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => function (\WP_REST_Request $request) use ($method) {
                    $body = $request->get_json_params();

                    return $this->call($method, is_array($body) ? $body : []);
                },
                'permission_callback' => [$this, 'permission'],
            ]);
        }
    }

    /**
     * @return bool|\WP_Error
     */
    public function permission()
    {
        if (! current_user_can(self::CAPABILITY)) {
            return new \WP_Error('dbvc_connected_admin_forbidden', 'Administrator capability required.', ['status' => rest_authorization_required_code()]);
        }

        return true;
    }

    /**
     * @param string               $method
     * @param array<string, mixed> $args
     * @return \WP_REST_Response|\WP_Error
     */
    private function call($method, array $args)
    {
        $args = array_intersect_key($args, array_flip(['limit', 'domain', 'hub', 'token', 'budget', 'fields', 'operation']));
        $result = $method === 'status' ? \DBVC_Connected_CLI_Inspector::status() : \DBVC_Connected_CLI_Inspector::$method($args);
        if (is_wp_error($result)) {
            $data = (array) $result->get_error_data();
            if (! isset($data['status'])) {
                $result->add_data(['status' => 400]);
            }

            return $result;
        }
        $response = rest_ensure_response($result);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');

        return $response;
    }
}
