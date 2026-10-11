<?php

namespace Dbvc\AgencyControl\Admin;

use Dbvc\AgencyControl\Storage\Schema;

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
    private const READS = ['status', 'environments', 'events', 'projections', 'subscriptions', 'deliveries', 'reviews', 'compare', 'baselines', 'links', 'link_proposals', 'definitions', 'overrides', 'framework_status', 'fleet', 'framework_plan', 'framework_rollouts', 'framework_rollout', 'segments', 'releases', 'preparations', 'approvals', 'invitations', 'rollouts', 'sync_policies', 'parity', 'audit_export', 'notifications', 'notification_log'];

    /** @var array<int, string> Inspector methods exposed as POST. */
    private const WRITES = ['invite', 'revoke', 'release', 'hold', 'environment_reassign', 'rollback', 'subscribe', 'subscribe_framework', 'route', 'baseline_confirm', 'link_instance', 'unlink_instance', 'link_accept', 'definition_publish', 'definition_desire', 'framework_publish', 'framework_rollout_start', 'framework_rollout_advance', 'framework_rollout_resume', 'framework_rollout_retry', 'framework_rollout_withdraw', 'environment_tag', 'adopt_version', 'override_approve', 'override_detach', 'review_classify', 'review_resolve', 'release_create', 'release_withdraw', 'prepare_request', 'approve', 'revoke_approval', 'rollout_create', 'rollout_advance', 'rollout_pause', 'rollout_resume', 'rollout_retry', 'rollout_withdraw', 'rollout_prune', 'sync_policy_set', 'sync_policy_delete', 'sync_now', 'sync_advance', 'freeze_set', 'freeze_clear', 'notification_channel_set', 'notification_channel_delete', 'notification_flush'];

    /**
     * Write routes reserved to the client *approver* (reviewing, approving and
     * rolling back client-side changes, plus governance holds). Every other
     * write is a framework *publisher* action; reads are open to either cap.
     *
     * @var array<int, string>
     */
    private const APPROVER_WRITES = ['approve', 'revoke_approval', 'override_approve', 'override_detach', 'review_classify', 'review_resolve', 'rollback', 'hold', 'freeze_set', 'freeze_clear'];

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
                'permission_callback' => function () use ($method) {
                    return $this->permit($method);
                },
            ]);
        }
        foreach (self::WRITES as $method) {
            register_rest_route(self::REST_NAMESPACE, '/agency/' . str_replace('_', '-', $method), [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => function (\WP_REST_Request $request) use ($method) {
                    $body = $request->get_json_params();

                    return $this->call($method, is_array($body) ? $body : []);
                },
                'permission_callback' => function () use ($method) {
                    return $this->permit($method);
                },
            ]);
        }
        register_rest_route(self::REST_NAMESPACE, '/agency/enable-subscription', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => function (\WP_REST_Request $request) {
                $body = $request->get_json_params();
                $body = is_array($body) ? $body : [];

                return $this->respond(\DBVC_Agency_CLI_Inspector::set_subscription_enabled($body, ! empty($body['enabled'])));
            },
            'permission_callback' => function () {
                // Enabling/disabling a subscription is a framework-distribution (publisher) action.
                return $this->permit('subscribe');
            },
        ]);
    }

    /**
     * Capability check for one agency route. `manage_options` is always allowed
     * (a superset, so an administrator is never locked out and the check is
     * fail-safe before any cap is granted); otherwise the route's required
     * capability must be held. An unmapped route requires `manage_options`
     * (strictest), never a default-allow. The connector machine REST path
     * ({@see \Dbvc\AgencyControl\Rest\Controller}) is separate and unaffected.
     *
     * @param string $method Inspector method / route name.
     * @return bool|\WP_Error
     */
    public function permit($method)
    {
        if (current_user_can(self::CAPABILITY)) {
            return true;
        }
        $cap = self::route_capability((string) $method);
        $allowed = $cap === self::CAP_EITHER
            ? (current_user_can(Schema::CAP_PUBLISH) || current_user_can(Schema::CAP_APPROVE))
            : ($cap !== '' && current_user_can($cap));
        if ($allowed) {
            return true;
        }

        return new \WP_Error('dbvc_connected_admin_forbidden', 'This action requires the agency publisher or approver capability.', ['status' => rest_authorization_required_code()]);
    }

    /** Sentinel: a read reachable by either human hub capability. */
    private const CAP_EITHER = 'either';

    /**
     * Required capability for an agency route: approver for the reviewing/
     * approving/rollback writes, publisher for every other known write, either
     * for reads, and '' (→ manage_options only) for anything unmapped.
     *
     * @param string $method
     * @return string
     */
    private static function route_capability($method)
    {
        if (in_array($method, self::APPROVER_WRITES, true)) {
            return Schema::CAP_APPROVE;
        }
        if (in_array($method, self::READS, true)) {
            return self::CAP_EITHER;
        }
        if (in_array($method, self::WRITES, true)) {
            return Schema::CAP_PUBLISH;
        }

        return '';
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
