<?php

namespace Dbvc\AgencyControl\Admin;

/**
 * Administrator REST routes for the Connected Environments page (hub role):
 * thin wrappers over `DBVC_Agency_CLI_Inspector`, cookie-authenticated (REST
 * nonce) and capability-gated, registered only while the hub gate is ready.
 * Write routes perform exactly the operation the CLI subcommand of the same
 * name performs; the invitation token appears only in the `invite` response.
 */
final class RestController
{
    public const REST_NAMESPACE = 'dbvc/v1';
    public const CAPABILITY = 'manage_options';

    /** @var array<int, string> Inspector methods exposed as GET. */
    private const READS = ['status', 'environments', 'events', 'projections', 'subscriptions', 'deliveries', 'reviews', 'compare', 'baselines', 'links', 'definitions', 'overrides', 'framework_status', 'releases', 'preparations', 'approvals', 'invitations', 'rollouts'];

    /** @var array<int, string> Inspector methods exposed as POST. */
    private const WRITES = ['invite', 'revoke', 'release', 'hold', 'rollback', 'subscribe', 'subscribe_framework', 'route', 'baseline_confirm', 'link_instance', 'unlink_instance', 'definition_publish', 'definition_desire', 'adopt_version', 'override_approve', 'override_detach', 'review_classify', 'review_resolve', 'release_create', 'release_withdraw', 'prepare_request', 'approve', 'revoke_approval', 'rollout_create', 'rollout_advance', 'rollout_pause', 'rollout_resume', 'rollout_retry', 'rollout_withdraw', 'rollout_prune'];

    /**
     * @return void
     */
    public function register_routes()
    {
        foreach (self::READS as $method) {
            register_rest_route(self::REST_NAMESPACE, '/agency/' . str_replace('_', '-', $method), [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => function (\WP_REST_Request $request) use ($method) {
                    return $this->call($method, $request->get_query_params());
                },
                'permission_callback' => [$this, 'permission'],
            ]);
        }
        foreach (self::WRITES as $method) {
            register_rest_route(self::REST_NAMESPACE, '/agency/' . str_replace('_', '-', $method), [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => function (\WP_REST_Request $request) use ($method) {
                    $body = $request->get_json_params();

                    return $this->call($method, is_array($body) ? $body : []);
                },
                'permission_callback' => [$this, 'permission'],
            ]);
        }
        register_rest_route(self::REST_NAMESPACE, '/agency/enable-subscription', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => function (\WP_REST_Request $request) {
                $body = $request->get_json_params();
                $body = is_array($body) ? $body : [];

                return $this->respond(\DBVC_Agency_CLI_Inspector::set_subscription_enabled($body, ! empty($body['enabled'])));
            },
            'permission_callback' => [$this, 'permission'],
        ]);
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
        $args = array_map(static function ($value) {
            return is_scalar($value) ? $value : (is_array($value) ? $value : '');
        }, $args);
        $result = $method === 'status' ? \DBVC_Agency_CLI_Inspector::status() : \DBVC_Agency_CLI_Inspector::$method($args);

        return $this->respond($result);
    }

    /**
     * @param array<string, mixed>|\WP_Error $result
     * @return \WP_REST_Response|\WP_Error
     */
    private function respond($result)
    {
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
