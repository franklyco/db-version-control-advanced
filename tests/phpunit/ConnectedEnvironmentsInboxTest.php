<?php

use Dbvc\AgencyControl\Enrollment\EnrollmentService as HubEnrollment;
use Dbvc\AgencyControl\Enrollment\SubscriptionService;
use Dbvc\AgencyControl\Storage\DeliveryStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\Connected\Capture\DirtyCapture;
use Dbvc\Connected\Enrollment\EnrollmentService;
use Dbvc\Connected\Storage\InboxStore;
use Dbvc\Connected\Storage\Schema;
use Dbvc\Connected\Storage\StateStore;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

require_once __DIR__ . '/support/ConnectedLoopbackTrait.php';

/**
 * Connector inbox: this install is enrolled as Client A staging; a synthetic
 * Client A production principal reports to the hub; the connector polls,
 * stores before acknowledging, advances its cursor only past stored and
 * acknowledged deliveries, and never applies anything to content.
 *
 * @group connected-environments
 */
class ConnectedEnvironmentsInboxTest extends WP_UnitTestCase
{
    use ConnectedLoopbackTrait;

    private const HUB = 'http://hub.test';

    /**
     * @var array<string, mixed>
     */
    private $prod;

    /**
     * @var string
     */
    private $stage_id;

    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_connected_transaction_strategy', [$this, 'savepoint_strategy']);
        add_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        add_filter('dbvc_connected_bricks_available', '__return_true');
        add_filter('dbvc_connected_processing_delay', '__return_zero');
        add_filter('dbvc_connected_allow_insecure_hub', '__return_true');
        add_filter('wp_is_application_passwords_available', '__return_true');
        add_filter('application_password_is_api_request', '__return_true');
        add_filter('pre_http_request', [$this, 'loopback'], 10, 3);
        $this->http_log = [];
        $this->simulated_transport_error = null;
        $this->transport_failure_predicate = null;

        register_post_type('service', ['public' => true]);
        delete_option(Schema::OPTION_SCHEMA_VERSION);
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        Schema::reset_memo();
        HubSchema::reset_memo();
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        $GLOBALS['wp_rest_server'] = null;
        rest_get_server();
        DBVC_Connected_Environments_Addon::runtime()->worker()->run(['context' => 'test']);

        // Client A production is a synthetic principal on the hub; this install enrolls as Client A staging.
        $this->prod = $this->enroll_synthetic('client-a', 'a-prod');
        $invite = (new HubEnrollment())->invite(['client_id' => 'client-a', 'environment_id' => 'a-stage']);
        $result = (new EnrollmentService())->exchange(self::HUB, $invite['token']);
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->stage_id = $result['environment_id'];
        DBVC_Connected_Environments_Addon::runtime()->worker()->run(['context' => 'test']);
        $this->assertIsArray((new SubscriptionService())->subscribe_client('a-prod', 'a-stage', []));
        $this->http_log = [];
    }

    public function tear_down(): void
    {
        // Registrations are process-global (not rolled back with the DB); leave no extra sources for later suites.
        unregister_post_type('service');
        wp_set_current_user(0);
        $GLOBALS['wp_rest_application_password_uuid'] = null;
        DBVC_Connected_Environments_Addon::save_settings([]);
        DBVC_Agency_Control_Addon::save_settings([]);
        remove_filter('dbvc_connected_transaction_strategy', [$this, 'savepoint_strategy']);
        remove_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        remove_filter('dbvc_connected_bricks_available', '__return_true');
        remove_filter('dbvc_connected_processing_delay', '__return_zero');
        remove_filter('dbvc_connected_allow_insecure_hub', '__return_true');
        remove_filter('wp_is_application_passwords_available', '__return_true');
        remove_filter('application_password_is_api_request', '__return_true');
        remove_filter('pre_http_request', [$this, 'loopback'], 10);
        Schema::reset_memo();
        HubSchema::reset_memo();
        parent::tear_down();
    }

    public function savepoint_strategy(): string
    {
        return 'savepoint';
    }

    public function test_poll_stores_before_acknowledging_and_advances_the_cursor(): void
    {
        $this->report_from_prod([
            $this->event(1, 'bricks.global_class', 'class-button-a', 'a1'),
            $this->event(2, 'wp.service', 'svc-1', 'b2', 'wp-service-v1'),
            $this->event(3, 'bricks.variable', 'var-1', 'c3', 'bricks-variable-v1'),
        ]);
        $this->assertSame(3, (new DeliveryStore())->summary()['a-stage']['pending']);
        $posts_before = wp_count_posts('service');
        $options_before = md5(serialize(wp_load_alloptions()));

        $summary = $this->poll();
        $this->assertNull($summary['blocked'], wp_json_encode($summary));
        $this->assertSame(3, $summary['retrieved']);
        $this->assertSame(3, $summary['stored']);
        $this->assertSame(3, $summary['acked']);
        $this->assertSame(0, $summary['cursor_before']);
        $this->assertGreaterThan(0, $summary['cursor_after']);
        $this->assertFalse($summary['has_more']);
        $this->assertNotFalse(wp_next_scheduled(DirtyCapture::INBOX_CRON_HOOK), 'The poll reschedules itself.');

        $inbox = new InboxStore();
        $rows = $inbox->all();
        $this->assertCount(3, $rows);
        $this->assertSame(['obs-000000000001', 'obs-000000000002', 'obs-000000000003'], array_column($rows, 'event_id'));
        $this->assertSame('a-prod', $rows[0]['source_environment_id']);
        $this->assertNotEmpty($rows[0]['acked_at']);
        $this->assertSame([], ObservationEvent::validate($rows[1]['body']));
        $this->assertSame('wp-service-v1', $rows[1]['profile']);
        $this->assertSame(['total' => 3, 'unacked' => 0, 'sources' => 1], $inbox->counts());
        $this->assertSame($summary['cursor_after'], (new StateStore())->get()['inbox_cursor']);
        $this->assertSame(3, (new DeliveryStore())->summary()['a-stage']['acked']);

        // Nothing is applied: no posts or options changed on the receiving site.
        $this->assertEquals($posts_before, wp_count_posts('service'));
        $this->assertSame($options_before, md5(serialize(wp_load_alloptions())));

        $idle = $this->poll();
        $this->assertSame(0, $idle['retrieved']);
        $this->assertSame($summary['cursor_after'], $idle['cursor_after']);
        $this->assertCount(3, $this->http_log, 'First poll: one GET plus one ACK; idle poll: one GET.');
    }

    public function test_crash_between_store_and_ack_is_recovered_without_duplicates(): void
    {
        $this->report_from_prod([$this->event(1, 'bricks.global_class', 'class-button-a', 'a1'), $this->event(2, 'bricks.global_class', 'class-2', 'b2')]);

        // The ACK request fails after the items were stored.
        $this->simulated_transport_error = new WP_Error('http_request_failed', 'ack lost');
        $this->transport_failure_predicate = static function ($url) {
            return strpos($url, 'inbox%2Fack') !== false;
        };
        $first = $this->poll();
        $this->assertSame(2, $first['stored']);
        $this->assertSame(0, $first['acked']);
        $this->assertSame(0, $first['cursor_after'], 'The cursor never advances past unacknowledged deliveries.');
        $this->assertSame(2, (new InboxStore())->counts()['unacked']);
        $this->assertSame(2, (new DeliveryStore())->summary()['a-stage']['pending']);

        $this->simulated_transport_error = null;
        $second = $this->poll();
        $this->assertNull($second['blocked']);
        $this->assertSame(2, $second['acked'], 'Unacknowledged rows are acknowledged first, before any new fetch.');
        $this->assertSame(0, $second['retrieved'], 'Once acknowledged, the hub no longer serves them.');
        $this->assertSame(0, $second['stored']);
        $this->assertGreaterThan(0, $second['cursor_after']);
        $this->assertSame(2, (new StateStore())->get()['inbox_status']['recovered'] ?? 0);
        $this->assertSame(['total' => 2, 'unacked' => 0, 'sources' => 1], (new InboxStore())->counts());
        $this->assertSame(2, (new DeliveryStore())->summary()['a-stage']['acked']);
    }

    public function test_revoked_or_held_connector_stops_polling_and_keeps_deliveries_pending(): void
    {
        $this->report_from_prod([$this->event(1, 'bricks.global_class', 'class-button-a', 'a1')]);
        (new HubEnrollment())->revoke($this->stage_id);
        $held = $this->poll();
        $this->assertSame('held:dbvc_agency_app_password_required', $held['blocked']);
        $this->assertSame(0, $held['retrieved']);
        $this->assertSame('held', (new StateStore())->get()['connection_state']);
        $this->assertSame(1, (new DeliveryStore())->summary()['a-stage']['pending'], 'Pending deliveries remain for a later re-enrollment review.');
        $this->assertFalse(wp_next_scheduled(DirtyCapture::INBOX_CRON_HOOK) !== false && (new StateStore())->get()['connection_state'] === 'enrolled');
    }

    private function poll(): array
    {
        return DBVC_Connected_Environments_Addon::runtime()->inbox()->run(['context' => 'test']);
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return void
     */
    private function report_from_prod(array $events): void
    {
        wp_set_current_user($this->prod['user_id']);
        $GLOBALS['wp_rest_application_password_uuid'] = $this->prod['uuid'];
        $request = new WP_REST_Request('POST', '/dbvc-agency/v1/observations');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(['events' => $events]));
        $response = rest_do_request($request);
        wp_set_current_user(0);
        $GLOBALS['wp_rest_application_password_uuid'] = null;
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame(count($events), $response->get_data()['counts']['accepted']);
    }

    /**
     * @return array<string, mixed>
     */
    private function enroll_synthetic(string $client_id, string $environment_id): array
    {
        $invite = (new HubEnrollment())->invite(['client_id' => $client_id, 'environment_label' => $environment_id]);
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

    /**
     * @return array<string, mixed>
     */
    private function event(int $sequence, string $domain, string $instance_uid, string $seed, string $profile = 'bricks-global-class-v1'): array
    {
        return ObservationEvent::build([
            'event_id' => 'obs-' . str_pad((string) $sequence, 12, '0', STR_PAD_LEFT),
            'environment_id' => $this->prod['environment_id'],
            'installation_epoch' => $this->prod['epoch'],
            'sequence' => $sequence,
            'observed_at' => '2026-09-19T10:00:00Z',
            'object' => ['domain' => $domain, 'instance_uid' => $instance_uid],
            'projection' => ['profile' => $profile, 'hash' => substr(str_repeat($seed[0], 64), 0, 64 - strlen($seed) + 1) . substr($seed, 1), 'exists' => true, 'complete' => true],
        ]);
    }
}
