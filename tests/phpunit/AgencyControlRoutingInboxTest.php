<?php

use Dbvc\AgencyControl\Enrollment\EnrollmentService;
use Dbvc\AgencyControl\Enrollment\SubscriptionService;
use Dbvc\AgencyControl\Routing\RoutingPolicy;
use Dbvc\AgencyControl\Storage\DeliveryStore;
use Dbvc\AgencyControl\Storage\EventStore;
use Dbvc\AgencyControl\Storage\ReviewStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\AgencyControl\Storage\SubscriptionStore;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * Hub-only acceptance scenario: a subscribed framework class edited on Client
 * A production enters studio review and Client A staging; a service edit
 * reaches only Client A staging; Client B receives neither; an offline
 * staging site catches up. Membership and recipients come only from the
 * hub's own registry.
 *
 * @group connected-environments
 */
class AgencyControlRoutingInboxTest extends WP_UnitTestCase
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private $env = [];

    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        add_filter('wp_is_application_passwords_available', '__return_true');
        add_filter('application_password_is_api_request', '__return_true');
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        HubSchema::reset_memo();
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        $GLOBALS['wp_rest_server'] = null;
        rest_get_server();

        foreach ([['client-a', 'a-prod'], ['client-a', 'a-stage'], ['client-a', 'a-local'], ['client-b', 'b-prod'], ['client-b', 'b-stage']] as $pair) {
            $this->env[$pair[1]] = $this->enroll($pair[0], $pair[1]);
        }
        $subscriptions = new SubscriptionService();
        $this->assertIsArray($subscriptions->subscribe_client('a-prod', 'a-stage', []));
        $this->assertIsArray($subscriptions->subscribe_client('a-prod', 'a-local', []));
        $this->assertIsArray($subscriptions->subscribe_client('b-prod', 'b-stage', []));
        $this->assertIsArray($subscriptions->subscribe_framework('a-prod', 'bricks.global_class', 'class-button-a', 'framework-button'));
    }

    public function tear_down(): void
    {
        $this->clear_principal();
        DBVC_Agency_Control_Addon::save_settings([]);
        remove_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        remove_filter('wp_is_application_passwords_available', '__return_true');
        remove_filter('application_password_is_api_request', '__return_true');
        HubSchema::reset_memo();
        parent::tear_down();
    }

    public function savepoint_strategy(): string
    {
        return 'savepoint';
    }

    public function test_routing_policy_matches_the_package_examples_and_refuses_sender_authority(): void
    {
        $dir = DBVC_PLUGIN_PATH . 'docs/dropins/dbvc-connected-agency/contracts/examples/';
        $registry = json_decode((string) file_get_contents($dir . 'registry.json'), true);
        $expected = json_decode((string) file_get_contents($dir . 'expected-routing.json'), true);
        foreach ($expected as $file => $decision) {
            $event = json_decode((string) file_get_contents($dir . $file), true);
            $this->assertSame($decision, RoutingPolicy::decide($event, $event['environment_id'], $registry), $file);
        }

        $event = json_decode((string) file_get_contents($dir . 'class-observation.json'), true);
        $forged = $event;
        $forged['recipients'] = ['b-stage'];
        try {
            RoutingPolicy::decide($forged, 'a-prod', $registry);
            $this->fail('recipients must be refused');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('sender_authority_forbidden', $exception->getMessage());
        }
        $wrong_epoch = $event;
        $wrong_epoch['installation_epoch'] = 'epoch-a-prod-0';
        try {
            RoutingPolicy::decide($wrong_epoch, 'a-prod', $registry);
            $this->fail('epoch mismatch must be refused');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('enrollment_mismatch', $exception->getMessage());
        }
        $this->assertSame(['client_targets' => ['a-local', 'a-stage'], 'framework_review' => []], RoutingPolicy::decide($event, 'a-prod', $registry, true), 'A verified operation result skips framework review.');
        $apply_claim = $event;
        $apply_claim['origin'] = 'apply';
        $this->assertSame(['framework-button'], RoutingPolicy::decide($apply_claim, 'a-prod', $registry)['framework_review'], 'A sender-labelled apply origin does not suppress review.');
    }

    public function test_subscriptions_are_scoped_to_one_agency_and_client(): void
    {
        $service = new SubscriptionService();
        $cross = $service->subscribe_client('a-prod', 'b-stage', ['bricks.global_class']);
        $this->assertInstanceOf(WP_Error::class, $cross);
        $this->assertSame('dbvc_agency_scope_mismatch', $cross->get_error_code());
        $this->assertSame('dbvc_agency_self_subscription', $service->subscribe_client('a-prod', 'a-prod', [])->get_error_code());
        $this->assertSame('dbvc_agency_environment_not_found', $service->subscribe_client('a-prod', 'nope', [])->get_error_code());
        $this->assertSame('dbvc_agency_invalid_domain', $service->subscribe_client('a-prod', 'a-stage', ['bricks.template'])->get_error_code());
        $again = $service->subscribe_client('a-prod', 'a-stage', ['wp.service']);
        $this->assertSame('exists', $again['domains']['wp.service']);
        $this->assertCount(10, (new SubscriptionStore())->all());
    }

    public function test_acceptance_scenario_routes_to_same_client_targets_and_studio_review_only(): void
    {
        $a = $this->env['a-prod'];
        $this->act_as($a);
        $class_x = $this->event($a, 1, 'bricks.global_class', 'class-button-a', 'a1');
        $class_z = $this->event($a, 2, 'bricks.global_class', 'class-unsubscribed', 'b2');
        $service = $this->event($a, 3, 'wp.service', 'svc-1', 'c3', 'wp-service-v1');
        $response = $this->post_observations(['events' => [$class_x, $class_z, $service]]);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame(3, $response->get_data()['routing']['routed']);
        $this->assertSame(6, $response->get_data()['routing']['deliveries']);
        $this->assertSame(1, $response->get_data()['routing']['review_items']);

        $b = $this->env['b-prod'];
        $this->act_as($b);
        $this->assertSame(200, $this->post_observations(['events' => [$this->event($b, 1, 'bricks.global_class', 'class-button-b', 'd4')]])->get_status());

        $deliveries = new DeliveryStore();
        $summary = $deliveries->summary();
        $this->assertSame(3, $summary['a-stage']['pending']);
        $this->assertSame(3, $summary['a-local']['pending']);
        $this->assertSame(1, $summary['b-stage']['pending']);
        $this->assertArrayNotHasKey('a-prod', $summary);
        $this->assertArrayNotHasKey('b-prod', $summary);

        $reviews = (new ReviewStore())->all();
        $this->assertCount(1, $reviews);
        $this->assertSame(['a-prod', 'bricks.global_class', 'class-button-a', 'framework-button', 'observed'], [$reviews[0]['environment_id'], $reviews[0]['domain'], $reviews[0]['instance_uid'], $reviews[0]['definition_uid'], $reviews[0]['state']]);
        $routed = (new EventStore())->find('a-prod', $a['epoch'], 'obs-000000000001');
        $this->assertSame('routed', $routed['routing_state']);
        $this->assertSame($deliveries->all('a-stage')[0]['policy_revision'], $routed['routing_policy_revision'], 'The policy revision is frozen on the delivery.');

        // A staging retrieves only its own client's deliveries in cursor order and acknowledges them.
        $this->act_as($this->env['a-stage']);
        $inbox = $this->get_inbox(0, 2);
        $this->assertSame(200, $inbox->get_status());
        $page = $inbox->get_data();
        $this->assertCount(2, $page['items']);
        $this->assertTrue($page['has_more']);
        $this->assertSame(['obs-000000000001', 'obs-000000000002'], array_column($page['items'], 'event_id'));
        $this->assertSame('a-prod', $page['items'][0]['source_environment_id']);
        $this->assertSame([], ObservationEvent::validate($page['items'][0]['event']));
        $this->assertSame(ObservationEvent::digest($page['items'][0]['event']), $page['items'][0]['body_digest']);
        $this->assertArrayNotHasKey('recipients', $page['items'][0]['event']);

        $rest = $this->get_inbox($page['next_cursor'], 100)->get_data();
        $this->assertCount(1, $rest['items']);
        $this->assertFalse($rest['has_more']);
        $this->assertSame('wp.service', $rest['items'][0]['domain']);

        $ack = $this->ack([$page['items'][0]['delivery_id'], $page['items'][1]['delivery_id'], $rest['items'][0]['delivery_id'], 999999]);
        $this->assertSame(['acked', 'acked', 'acked', 'unknown'], array_column($ack->get_data()['outcomes'], 'outcome'));
        $this->assertSame(['acked', 'already_acked'], [$ack->get_data()['outcomes'][0]['outcome'], $this->ack([$page['items'][0]['delivery_id']])->get_data()['outcomes'][0]['outcome']]);
        $this->assertSame([], $this->get_inbox(0, 100)->get_data()['items'], 'Acknowledged deliveries are not served again.');
        $this->assertSame(3, $deliveries->summary()['a-stage']['acked']);

        // B staging sees only B's event and cannot acknowledge A's deliveries.
        $this->act_as($this->env['b-stage']);
        $b_inbox = $this->get_inbox(0, 100)->get_data();
        $this->assertSame(['obs-000000000001'], array_column($b_inbox['items'], 'event_id'));
        $this->assertSame('b-prod', $b_inbox['items'][0]['source_environment_id']);
        $foreign = $this->ack([$page['items'][0]['delivery_id']])->get_data()['outcomes'][0];
        $this->assertSame('unknown', $foreign['outcome']);
        $this->assertSame(3, $deliveries->summary()['a-stage']['acked']);

        // A local was offline all along: its deliveries are still pending and it catches up later.
        $this->assertSame(3, $deliveries->summary()['a-local']['pending']);
        $this->act_as($this->env['a-local']);
        $catch_up = $this->get_inbox(0, 100)->get_data();
        $this->assertSame(['obs-000000000001', 'obs-000000000002', 'obs-000000000003'], array_column($catch_up['items'], 'event_id'));

        // The source environments themselves never receive deliveries.
        $this->act_as($a);
        $this->assertSame([], $this->get_inbox(0, 100)->get_data()['items']);
    }

    public function test_disabled_subscriptions_and_revoked_sources_cancel_at_retrieval_and_holds_block_the_inbox(): void
    {
        $a = $this->env['a-prod'];
        $this->act_as($a);
        $this->post_observations(['events' => [
            $this->event($a, 1, 'wp.service', 'svc-1', 'a1', 'wp-service-v1'),
            $this->event($a, 2, 'bricks.variable', 'var-1', 'b2', 'bricks-variable-v1'),
        ]]);
        $store = new SubscriptionStore();
        $service_to_local = array_values(array_filter($store->all('a-local'), static function ($row) {
            return $row['target_environment_id'] === 'a-local' && $row['domain'] === 'wp.service';
        }))[0];
        (new SubscriptionService())->set_enabled($service_to_local['subscription_id'], false);

        $this->act_as($this->env['a-local']);
        $inbox = $this->get_inbox(0, 100)->get_data();
        $this->assertSame(['obs-000000000002'], array_column($inbox['items'], 'event_id'), 'The disabled domain is cancelled at retrieval; the other domain is served.');
        $deliveries = (new DeliveryStore())->all('a-local');
        $this->assertSame('cancelled', $deliveries[0]['state']);
        $this->assertSame('subscription_disabled', $deliveries[0]['cancelled_reason']);

        // Revoking the source cancels its remaining deliveries at retrieval and stops new receipt.
        (new EnrollmentService())->revoke('a-prod');
        $this->act_as($this->env['a-stage']);
        $this->assertSame([], $this->get_inbox(0, 100)->get_data()['items']);
        $this->assertSame(['cancelled', 'cancelled'], array_column((new DeliveryStore())->all('a-stage'), 'state'));

        // A held target cannot read its inbox until released.
        (new \Dbvc\AgencyControl\Storage\EnvironmentRegistry())->update('b-stage', ['status' => 'held', 'hold_reason' => 'site_url_mismatch']);
        $this->act_as($this->env['b-stage']);
        $held = $this->get_inbox(0, 100);
        $this->assertSame(409, $held->get_status());
        (new EnrollmentService())->release('b-stage');
        $this->assertSame(200, $this->get_inbox(0, 100)->get_status());

        // Deliveries for a held target still accumulate while it is held.
        (new \Dbvc\AgencyControl\Storage\EnvironmentRegistry())->update('b-stage', ['status' => 'held', 'hold_reason' => 'site_url_mismatch']);
        $b = $this->env['b-prod'];
        $this->act_as($b);
        $this->post_observations(['events' => [$this->event($b, 1, 'wp.service', 'svc-b', 'c3', 'wp-service-v1')]]);
        $this->assertSame(1, (new DeliveryStore())->summary()['b-stage']['pending']);

        $this->assertSame(400, $this->ack([])->get_status());
    }

    /**
     * @return array<string, mixed>
     */
    private function enroll(string $client_id, string $environment_id): array
    {
        $invite = (new EnrollmentService())->invite(['client_id' => $client_id, 'environment_label' => $environment_id]);
        $request = new WP_REST_Request('POST', '/dbvc-agency/v1/enrollments/exchange');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(['token' => $invite['token'], 'environment_id' => $environment_id, 'site_url' => 'https://' . $environment_id . '.example/', 'protocol_versions' => [Protocol::PROTOCOL_VERSION]]));
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $data = $response->get_data();

        return [
            'environment_id' => $data['environment_id'],
            'epoch' => $data['installation_epoch'],
            'user_id' => get_user_by('login', $data['principal']['username'])->ID,
            'uuid' => $data['principal']['uuid'],
        ];
    }

    private function act_as(array $enrollment): void
    {
        wp_set_current_user($enrollment['user_id']);
        $GLOBALS['wp_rest_application_password_uuid'] = $enrollment['uuid'];
    }

    private function clear_principal(): void
    {
        wp_set_current_user(0);
        $GLOBALS['wp_rest_application_password_uuid'] = null;
    }

    private function post_observations(array $body): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/dbvc-agency/v1/observations');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));

        return rest_do_request($request);
    }

    private function get_inbox(int $cursor, int $limit): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', '/dbvc-agency/v1/inbox');
        $request->set_query_params(['cursor' => $cursor, 'limit' => $limit]);

        return rest_do_request($request);
    }

    private function ack(array $ids): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/dbvc-agency/v1/inbox/ack');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(['delivery_ids' => $ids]));

        return rest_do_request($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function event(array $enrollment, int $sequence, string $domain, string $instance_uid, string $seed, string $profile = 'bricks-global-class-v1'): array
    {
        return ObservationEvent::build([
            'event_id' => 'obs-' . str_pad((string) $sequence, 12, '0', STR_PAD_LEFT),
            'environment_id' => $enrollment['environment_id'],
            'installation_epoch' => $enrollment['epoch'],
            'sequence' => $sequence,
            'observed_at' => '2026-09-19T10:00:00Z',
            'object' => ['domain' => $domain, 'instance_uid' => $instance_uid],
            'projection' => ['profile' => $profile, 'hash' => substr(str_repeat($seed[0], 64), 0, 64 - strlen($seed) + 1) . substr($seed, 1), 'exists' => true, 'complete' => true],
        ]);
    }
}
