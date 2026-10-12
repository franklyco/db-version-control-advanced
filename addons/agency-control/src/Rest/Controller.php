<?php

namespace Dbvc\AgencyControl\Rest;

use Dbvc\AgencyControl\Enrollment\EnrollmentService;
use Dbvc\AgencyControl\Receipt\ObservationReceipt;
use Dbvc\AgencyControl\Release\ApprovalService;
use Dbvc\AgencyControl\Release\PreparationService;
use Dbvc\AgencyControl\Release\ReleaseService;
use Dbvc\AgencyControl\Routing\RoutingWorker;
use Dbvc\AgencyControl\Storage\DeliveryStore;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\ReleaseStore;
use Dbvc\AgencyControl\Storage\SubscriptionStore;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * Hub REST surface for M2 step 1: enrollment exchange, observation receipt
 * and capabilities. Machine routes require the enrolled application-password
 * principal; an administrator cookie is deliberately not accepted there.
 */
final class Controller
{
    /**
     * @return void
     */
    public function register_routes()
    {
        register_rest_route(Protocol::REST_NAMESPACE, '/enrollments/exchange', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'exchange'],
            'permission_callback' => '__return_true', // The single-use invitation token is the authority.
        ]);
        register_rest_route(Protocol::REST_NAMESPACE, '/observations', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'observations'],
            'permission_callback' => [$this, 'require_enrolled_principal'],
        ]);
        register_rest_route(Protocol::REST_NAMESPACE, '/capabilities', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'capabilities'],
            'permission_callback' => [$this, 'require_enrolled_principal'],
        ]);
        register_rest_route(Protocol::REST_NAMESPACE, '/inbox', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'inbox'],
            'permission_callback' => [$this, 'require_enrolled_principal'],
        ]);
        register_rest_route(Protocol::REST_NAMESPACE, '/inbox/ack', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'inbox_ack'],
            'permission_callback' => [$this, 'require_enrolled_principal'],
        ]);
        // M4: release payload requests (source side) and prepare requests/receipts (target side); all connector-initiated.
        register_rest_route(Protocol::REST_NAMESPACE, '/releases/payload-requests', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'payload_requests'],
            'permission_callback' => [$this, 'require_enrolled_principal'],
        ]);
        register_rest_route(Protocol::REST_NAMESPACE, '/releases/(?P<release_uid>[A-Za-z0-9._:-]{1,128})/payloads', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'payloads'],
            'permission_callback' => [$this, 'require_enrolled_principal'],
        ]);
        register_rest_route(Protocol::REST_NAMESPACE, '/releases/prepare-requests', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'prepare_requests'],
            'permission_callback' => [$this, 'require_enrolled_principal'],
        ]);
        register_rest_route(Protocol::REST_NAMESPACE, '/releases/(?P<release_uid>[A-Za-z0-9._:-]{1,128})/prepare-receipts', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'prepare_receipts'],
            'permission_callback' => [$this, 'require_enrolled_principal'],
        ]);
        // M5: approved executions (target side).
        register_rest_route(Protocol::REST_NAMESPACE, '/releases/apply-requests', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'apply_requests'],
            'permission_callback' => [$this, 'require_enrolled_principal'],
        ]);
        register_rest_route(Protocol::REST_NAMESPACE, '/releases/(?P<release_uid>[A-Za-z0-9._:-]{1,128})/apply-receipts', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'apply_receipts'],
            'permission_callback' => [$this, 'require_enrolled_principal'],
        ]);
    }

    /**
     * Enrollment authority derives from the authenticated application
     * password, resolved through the trusted registry.
     *
     * @param \WP_REST_Request $request
     * @return true|\WP_Error
     */
    public function require_enrolled_principal(\WP_REST_Request $request)
    {
        unset($request);
        $environment = $this->resolve_environment();
        if (is_wp_error($environment)) {
            return $environment;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    private function resolve_environment()
    {
        $uuid = function_exists('rest_get_authenticated_app_password') ? rest_get_authenticated_app_password() : null;
        $user_id = get_current_user_id();
        if (! is_string($uuid) || $uuid === '' || $user_id <= 0) {
            return new \WP_Error('dbvc_agency_app_password_required', 'Machine routes require the enrolled application-password principal.', ['status' => 401]);
        }
        $environment = (new EnvironmentRegistry())->find_by_principal($user_id, $uuid);
        if ($environment === null) {
            return new \WP_Error('dbvc_agency_not_enrolled', 'This principal is not bound to an enrolled environment.', ['status' => 403]);
        }
        if ($environment['status'] === EnvironmentRegistry::STATUS_REVOKED) {
            return new \WP_Error('dbvc_agency_environment_revoked', 'Enrollment revoked.', ['status' => 403]);
        }

        return $environment;
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function exchange(\WP_REST_Request $request)
    {
        $body = $request->get_json_params();
        if (! is_array($body)) {
            return new \WP_Error('dbvc_agency_invalid_body', 'JSON body required.', ['status' => 400]);
        }
        $result = (new EnrollmentService())->exchange($body);
        if (is_wp_error($result)) {
            return $result;
        }

        return $this->no_store(rest_ensure_response($result));
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function observations(\WP_REST_Request $request)
    {
        $environment = $this->resolve_environment();
        if (is_wp_error($environment)) {
            return $environment;
        }
        if ($environment['status'] === EnvironmentRegistry::STATUS_HELD) {
            return new \WP_Error('dbvc_agency_environment_held', 'Enrollment is held pending review: ' . $environment['hold_reason'], ['status' => 409, 'hold_reason' => $environment['hold_reason']]);
        }

        if (strlen((string) $request->get_body()) > Protocol::MAX_BATCH_BYTES) {
            return new \WP_Error('dbvc_agency_batch_too_large', 'Batch exceeds ' . Protocol::MAX_BATCH_BYTES . ' bytes.', ['status' => 413]);
        }
        $body = $request->get_json_params();
        if (! is_array($body) || ! isset($body['events']) || ! is_array($body['events']) || ! array_is_list($body['events'])) {
            return new \WP_Error('dbvc_agency_invalid_body', 'Body must contain an events list.', ['status' => 400]);
        }
        if (count($body['events']) === 0 || count($body['events']) > Protocol::MAX_BATCH_EVENTS) {
            return new \WP_Error('dbvc_agency_batch_size', 'Batches carry 1 to ' . Protocol::MAX_BATCH_EVENTS . ' events.', ['status' => 400]);
        }
        $batch_id = isset($body['batch_id']) && ObservationEvent::isIdentifier($body['batch_id']) ? (string) $body['batch_id'] : '';

        // Site URL drift is a clone/relocation signal: hold the enrollment rather than accept silently.
        $site_url = esc_url_raw((string) $request->get_header(Protocol::HEADER_SITE_URL));
        $enrolled_url = (string) $environment['enrolled_site_url'];
        if ($site_url !== '' && $enrolled_url !== '' && untrailingslashit($site_url) !== untrailingslashit($enrolled_url)) {
            (new EnvironmentRegistry())->update($environment['environment_id'], [
                'status' => EnvironmentRegistry::STATUS_HELD,
                'hold_reason' => 'site_url_mismatch',
                'last_site_url' => $site_url,
            ]);
            return new \WP_Error('dbvc_agency_environment_held', 'Site URL differs from the enrolled URL; enrollment held pending review.', ['status' => 409, 'hold_reason' => 'site_url_mismatch']);
        }

        $result = (new ObservationReceipt())->accept_batch($environment, $body['events'], $batch_id, $site_url);

        // Routing intent is already durable (routing_state=pending); route now so deliveries exist before the ACK returns.
        if ($result['counts'][Protocol::OUTCOME_ACCEPTED] > 0) {
            $result['routing'] = (new RoutingWorker())->run(RoutingWorker::DEFAULT_LIMIT);
            unset($result['routing']['jobs']);
        }

        return $this->no_store(rest_ensure_response($result));
    }

    /**
     * Bounded, cursor-paged deliveries for the authenticated target only.
     * Authorization is rechecked here: a disabled subscription or a
     * non-enabled target cancels rather than serves.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function inbox(\WP_REST_Request $request)
    {
        $environment = $this->resolve_environment();
        if (is_wp_error($environment)) {
            return $environment;
        }
        if ($environment['status'] !== EnvironmentRegistry::STATUS_ENABLED) {
            return new \WP_Error('dbvc_agency_environment_held', 'Enrollment is not enabled: ' . $environment['hold_reason'], ['status' => 409, 'hold_reason' => $environment['hold_reason']]);
        }

        $cursor = max(0, (int) $request->get_param('cursor'));
        $limit = max(1, min(Protocol::MAX_INBOX_ITEMS, (int) ($request->get_param('limit') ?: Protocol::MAX_INBOX_ITEMS)));
        $deliveries = new DeliveryStore();
        $subscriptions = new SubscriptionStore();
        $registry = new EnvironmentRegistry();

        $items = [];
        $next_cursor = $cursor;
        $scanned = 0;
        $has_more = false;
        while (count($items) < $limit && $scanned < $limit * 3) {
            $page = $deliveries->pending_for_target($environment['environment_id'], $next_cursor, $limit);
            if ($page === []) {
                break;
            }
            foreach ($page as $delivery) {
                $scanned++;
                $next_cursor = $delivery['delivery_id'];
                if (! $subscriptions->client_subscription_enabled($delivery['source_environment_id'], $environment['environment_id'], $delivery['domain'])) {
                    $deliveries->cancel($delivery['delivery_id'], 'subscription_disabled');
                    continue;
                }
                $source = $registry->find($delivery['source_environment_id']);
                if ($source === null || $source['status'] === EnvironmentRegistry::STATUS_REVOKED) {
                    $deliveries->cancel($delivery['delivery_id'], 'source_revoked');
                    continue;
                }
                $items[] = [
                    'delivery_id' => $delivery['delivery_id'],
                    'source_environment_id' => $delivery['source_environment_id'],
                    'installation_epoch' => $delivery['installation_epoch'],
                    'event_id' => $delivery['event_id'],
                    'sequence' => $delivery['source_sequence'],
                    'domain' => $delivery['domain'],
                    'instance_uid' => $delivery['instance_uid'],
                    'profile' => $delivery['profile'],
                    'body_digest' => $delivery['body_digest'],
                    'policy_revision' => $delivery['policy_revision'],
                    'event' => $delivery['body'],
                    'created_at' => $delivery['created_at'],
                ];
                if (count($items) >= $limit) {
                    break;
                }
            }
            if (count($page) < $limit) {
                break;
            }
            $has_more = true;
        }
        if (count($items) >= $limit) {
            $has_more = $deliveries->pending_for_target($environment['environment_id'], $next_cursor, 1) !== [];
        }

        $registry->update($environment['environment_id'], ['last_inbox_poll_at' => current_time('mysql', true), 'last_inbox_cursor' => (int) $cursor]);

        return $this->no_store(rest_ensure_response([
            'environment_id' => $environment['environment_id'],
            'cursor' => $cursor,
            'next_cursor' => $next_cursor,
            'has_more' => $has_more,
            'items' => $items,
            'retrieved_at' => gmdate('c'),
        ]));
    }

    /**
     * Acknowledge deliveries the connector has durably stored. Only ids
     * belonging to this principal's environment are affected.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function inbox_ack(\WP_REST_Request $request)
    {
        $environment = $this->resolve_environment();
        if (is_wp_error($environment)) {
            return $environment;
        }
        $body = $request->get_json_params();
        $ids = is_array($body) && isset($body['delivery_ids']) && is_array($body['delivery_ids']) ? $body['delivery_ids'] : null;
        if ($ids === null || $ids === [] || count($ids) > Protocol::MAX_INBOX_ITEMS) {
            return new \WP_Error('dbvc_agency_invalid_body', 'delivery_ids must list 1 to ' . Protocol::MAX_INBOX_ITEMS . ' ids.', ['status' => 400]);
        }
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));

        return $this->no_store(rest_ensure_response([
            'environment_id' => $environment['environment_id'],
            'outcomes' => (new DeliveryStore())->ack($environment['environment_id'], $ids),
            'acked_at' => gmdate('c'),
        ]));
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function capabilities(\WP_REST_Request $request)
    {
        unset($request);
        $environment = $this->resolve_environment();
        if (is_wp_error($environment)) {
            return $environment;
        }

        return $this->no_store(rest_ensure_response(array_merge(Protocol::describe(), [
            'environment_id' => $environment['environment_id'],
            'installation_epoch' => $environment['current_epoch'],
            'status' => $environment['status'],
            'hold_reason' => $environment['hold_reason'],
            'capabilities' => ['receipt' => true, 'inbox' => true, 'release_payloads' => true, 'prepare' => true, 'apply' => true],
        ])));
    }

    /**
     * Items of this environment's open releases still awaiting a canonical body.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function payload_requests(\WP_REST_Request $request)
    {
        unset($request);
        $environment = $this->enabled_environment();
        if (is_wp_error($environment)) {
            return $environment;
        }
        $items = (new ReleaseStore())->requested_payloads($environment['environment_id'], Protocol::MAX_PAYLOAD_ITEMS * 4);

        return $this->no_store(rest_ensure_response([
            'environment_id' => $environment['environment_id'],
            'installation_epoch' => $environment['current_epoch'],
            'items' => array_map(static function ($item) {
                return ['release_uid' => $item['release_uid'], 'domain' => $item['domain'], 'instance_uid' => $item['instance_uid'], 'profile' => $item['profile'], 'operation' => $item['operation'], 'after_hash' => $item['after_hash'], 'source_sequence' => $item['source_sequence']];
            }, $items),
            'limits' => ['max_payload_items' => Protocol::MAX_PAYLOAD_ITEMS, 'max_payload_bytes' => Protocol::MAX_PAYLOAD_BYTES],
            'retrieved_at' => gmdate('c'),
        ]));
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function payloads(\WP_REST_Request $request)
    {
        $environment = $this->enabled_environment();
        if (is_wp_error($environment)) {
            return $environment;
        }
        $body = $request->get_json_params();
        $items = is_array($body) && isset($body['items']) && is_array($body['items']) ? $body['items'] : null;
        if ($items === null) {
            return new \WP_Error('dbvc_agency_invalid_body', 'items required.', ['status' => 400]);
        }
        $result = (new ReleaseService())->accept_payloads($environment, (string) $request->get_param('release_uid'), $items);
        if (is_wp_error($result)) {
            return $result;
        }

        return $this->no_store(rest_ensure_response($result));
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function prepare_requests(\WP_REST_Request $request)
    {
        unset($request);
        $environment = $this->enabled_environment();
        if (is_wp_error($environment)) {
            return $environment;
        }

        return $this->no_store(rest_ensure_response([
            'environment_id' => $environment['environment_id'],
            'installation_epoch' => $environment['current_epoch'],
            'requests' => (new PreparationService())->pending_for_target($environment),
            'retrieved_at' => gmdate('c'),
        ]));
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function prepare_receipts(\WP_REST_Request $request)
    {
        $environment = $this->enabled_environment();
        if (is_wp_error($environment)) {
            return $environment;
        }
        $body = $request->get_json_params();
        $receipt = is_array($body) && isset($body['receipt']) && is_array($body['receipt']) ? $body['receipt'] : null;
        if ($receipt === null) {
            return new \WP_Error('dbvc_agency_invalid_body', 'receipt required.', ['status' => 400]);
        }
        $result = (new PreparationService())->accept_receipt($environment, (string) $request->get_param('release_uid'), $receipt);
        if (is_wp_error($result)) {
            return $result;
        }

        return $this->no_store(rest_ensure_response($result));
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function apply_requests(\WP_REST_Request $request)
    {
        unset($request);
        $environment = $this->enabled_environment();
        if (is_wp_error($environment)) {
            return $environment;
        }

        return $this->no_store(rest_ensure_response([
            'environment_id' => $environment['environment_id'],
            'installation_epoch' => $environment['current_epoch'],
            'approvals' => (new ApprovalService())->pending_for_target($environment),
            'retrieved_at' => gmdate('c'),
        ]));
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function apply_receipts(\WP_REST_Request $request)
    {
        $environment = $this->enabled_environment();
        if (is_wp_error($environment)) {
            return $environment;
        }
        $body = $request->get_json_params();
        $receipt = is_array($body) && isset($body['receipt']) && is_array($body['receipt']) ? $body['receipt'] : null;
        if ($receipt === null) {
            return new \WP_Error('dbvc_agency_invalid_body', 'receipt required.', ['status' => 400]);
        }
        $result = (new ApprovalService())->accept_execution($environment, (string) $request->get_param('release_uid'), $receipt);
        if (is_wp_error($result)) {
            return $result;
        }

        return $this->no_store(rest_ensure_response($result));
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    private function enabled_environment()
    {
        $environment = $this->resolve_environment();
        if (is_wp_error($environment)) {
            return $environment;
        }
        if ($environment['status'] !== EnvironmentRegistry::STATUS_ENABLED) {
            return new \WP_Error('dbvc_agency_environment_held', 'Enrollment is not enabled: ' . $environment['hold_reason'], ['status' => 409, 'hold_reason' => $environment['hold_reason']]);
        }

        return $environment;
    }

    /**
     * @param \WP_REST_Response $response
     * @return \WP_REST_Response
     */
    private function no_store(\WP_REST_Response $response)
    {
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->header('Pragma', 'no-cache');

        return $response;
    }
}
