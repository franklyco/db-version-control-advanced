<?php

use Dbvc\AgencyControl\Enrollment\EnrollmentService as HubEnrollment;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\EventStore;
use Dbvc\AgencyControl\Storage\ProjectionStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\Connected\Adapters\DomainRegistry;
use Dbvc\Connected\Capture\DirtyCapture;
use Dbvc\Connected\Enrollment\EnrollmentService;
use Dbvc\Connected\Lifecycle\SecretBox;
use Dbvc\Connected\Storage\OutboxStore;
use Dbvc\Connected\Storage\Schema;
use Dbvc\Connected\Storage\StateStore;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * Connector ⇄ hub round trip inside one WordPress install (deliberate dual
 * role, independent stores) through the ConnectedLoopbackTrait shim.
 *
 * @group connected-environments
 */
require_once __DIR__ . '/support/ConnectedLoopbackTrait.php';

class ConnectedEnvironmentsDeliveryTest extends WP_UnitTestCase
{
    use ConnectedLoopbackTrait;

    private const CLASSES = 'bricks_global_classes';
    private const HUB = 'http://hub.test';

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

        register_post_type('service', ['public' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
        delete_option(self::CLASSES);
        delete_option(Schema::OPTION_SCHEMA_VERSION);
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        Schema::reset_memo();
        HubSchema::reset_memo();
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        $GLOBALS['wp_rest_server'] = null;
        rest_get_server();
        $this->run_observation();
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
        delete_option(self::CLASSES);
        Schema::reset_memo();
        HubSchema::reset_memo();
        parent::tear_down();
    }

    public function savepoint_strategy(): string
    {
        return 'savepoint';
    }

    public function test_enrollment_binds_identity_supersedes_provisional_history_and_delivers_under_the_new_epoch(): void
    {
        update_option(self::CLASSES, $this->classes_fixture());
        $this->run_observation();
        $provisional = (new StateStore())->get();
        $this->assertSame('provisional', $provisional['enrollment_state']);
        $this->assertSame('disconnected', $provisional['connection_state']);
        $this->assertSame(7, (new OutboxStore())->counts()['pending'], 'Provisional history: two empty-order reconciliations, the empty service inventory, three members and one order.');
        $this->assertNull((new StateStore())->hub_secret());

        $invite = (new HubEnrollment())->invite(['client_id' => 'client-a', 'environment_label' => 'A production']);
        $result = (new EnrollmentService())->exchange(self::HUB, $invite['token']);
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame($provisional['environment_id'], $result['environment_id'], 'The hub accepts the proposed id when the invitation does not fix one.');
        $this->assertStringStartsWith('enrolled-', $result['installation_epoch']);
        $this->assertSame('client-a', $result['client_id']);
        $this->assertSame(7, $result['superseded_events']);
        $this->assertArrayNotHasKey('password', $result);
        $this->assertArrayNotHasKey('secret', $result);

        $state = (new StateStore())->get();
        $this->assertSame('enrolled', $state['enrollment_state']);
        $this->assertSame('enrolled', $state['connection_state']);
        $this->assertSame(self::HUB, $state['hub_url']);
        $this->assertTrue($state['has_hub_secret']);
        $this->assertArrayNotHasKey('hub_secret', $state, 'The store never exposes the sealed secret through get().');
        $secret = (new StateStore())->hub_secret();
        $this->assertNotEmpty($secret);
        $this->assertStringStartsWith('v1:', $this->raw_hub_secret());
        $this->assertNotSame($secret, $this->raw_hub_secret(), 'Credential is stored sealed.');
        $this->assertSame(7, (new OutboxStore())->counts()['superseded']);
        $this->assertSame(0, (new OutboxStore())->counts()['pending'] ?? 0);
        $this->assertSame(\Dbvc\Connected\Storage\Schema::SCHEMA_VERSION, (int) get_option(\Dbvc\Connected\Storage\Schema::OPTION_SCHEMA_VERSION), 'The installed connector schema is current.');

        // Reconciliation re-emits the current state under the enrolled epoch.
        $summary = $this->run_observation();
        $this->assertSame(3, $summary['acknowledged']);
        $this->assertSame(6, $summary['events'], 'Three members + one order under the new epoch, plus the empty variables order and the empty service inventory.');
        $this->assertNotFalse(wp_next_scheduled(DirtyCapture::DELIVERY_CRON_HOOK), 'Emitting events while enrolled schedules delivery.');
        $sequence = 1;
        foreach ((new OutboxStore())->all('pending') as $event) {
            $this->assertSame($result['installation_epoch'], $event['installation_epoch']);
            $this->assertSame($sequence++, $event['source_sequence'], 'A new epoch restarts the sequence at 1.');
            $this->assertSame('reconciliation', $event['body']['origin']);
            $this->assertSame('enrollment', $event['body']['causation_id']);
        }

        $before = maybe_serialize(get_option(self::CLASSES));
        $delivery = $this->run_delivery();
        $this->assertNull($delivery['blocked'], wp_json_encode($delivery));
        $this->assertSame(6, $delivery['sent']);
        $this->assertSame(6, $delivery['delivered']);
        $this->assertSame(200, $delivery['http_status']);
        $this->assertCount(2, $this->http_log, 'One exchange request and one batch.');
        $this->assertSame(home_url('/'), $this->http_log[1]['args']['headers'][Protocol::HEADER_SITE_URL]);
        $this->assertSame($before, maybe_serialize(get_option(self::CLASSES)), 'Reporting never edits source content.');

        $outbox = (new OutboxStore())->counts();
        $this->assertSame(6, $outbox['delivered']);
        $this->assertSame(7, $outbox['superseded']);
        $this->assertArrayNotHasKey('pending', $outbox);
        $delivered = (new OutboxStore())->all('delivered');
        $this->assertSame('accepted', $delivered[0]['receipt']['outcome']);
        $this->assertSame($delivery['batch_id'], $delivered[0]['receipt']['batch_id']);

        $hub_events = (new EventStore())->all($result['environment_id']);
        $this->assertCount(6, $hub_events);
        $this->assertSame($delivered[0]['body_digest'], $hub_events[0]['body_digest']);
        $this->assertSame('routed', $hub_events[0]['routing_state'], 'Routed after receipt; no subscriptions yet, so no deliveries.');
        $this->assertCount(6, (new ProjectionStore())->all($result['environment_id']));
        $environment = (new EnvironmentRegistry())->find($result['environment_id']);
        $this->assertSame(6, $environment['received_events']);
        $this->assertSame(6, $environment['max_received_sequence']);
        $this->assertSame(0, (new EventStore())->sequence_summary()[0]['gaps']);
        $this->assertSame(home_url('/'), $environment['enrolled_site_url']);

        // Nothing pending: a further run sends nothing; a duplicate resend is acknowledged as duplicate, not stored twice.
        $idle = $this->run_delivery();
        $this->assertSame(0, $idle['sent']);
        global $wpdb;
        $wpdb->update(Schema::table('outbox'), ['delivery_state' => 'pending'], ['outbox_id' => $delivered[0]['outbox_id']], ['%s'], ['%d']);
        $resend = $this->run_delivery();
        $this->assertSame(1, $resend['duplicate']);
        $this->assertCount(6, (new EventStore())->all($result['environment_id']));
        $this->assertSame(6, (new EnvironmentRegistry())->find($result['environment_id'])['received_events']);
    }

    public function test_transport_failure_retries_with_backoff_and_hub_holds_stop_delivery(): void
    {
        $enrollment = $this->enroll();
        update_option(self::CLASSES, $this->classes_fixture());
        $this->run_observation();
        $pending_before = (new OutboxStore())->counts()['pending'];
        $this->assertGreaterThan(0, $pending_before);

        $this->simulated_transport_error = new WP_Error('http_request_failed', 'connection refused');
        $failed = $this->run_delivery();
        $this->assertNull($failed['blocked']);
        $this->assertSame(0, $failed['delivered']);
        $this->assertSame($pending_before, $failed['released'], 'Every leased event is released for retry.');
        $this->assertStringContainsString('transport:http_request_failed', $failed['error']);
        $rows = (new OutboxStore())->all('pending');
        $this->assertCount($pending_before, $rows);
        $this->assertSame(1, $rows[0]['attempts']);
        $this->assertNull($rows[0]['lease_token']);
        $this->assertGreaterThan(gmdate('Y-m-d H:i:s'), $rows[0]['available_at'], 'Retry is deferred with backoff.');
        $this->assertSame(0, (new EventStore())->counts()['total']);
        $this->simulated_transport_error = null;

        // Hub-side hold (revocation) turns into a local hold; delivery stops without losing events.
        global $wpdb;
        $wpdb->update(Schema::table('outbox'), ['available_at' => '2000-01-01 00:00:00'], ['delivery_state' => 'pending'], ['%s'], ['%s']);
        (new HubEnrollment())->revoke($enrollment['environment_id']);
        $held = $this->run_delivery();
        // Revocation deletes the application password, so the hub answers 401 before any enrollment lookup.
        $this->assertSame('held:dbvc_agency_app_password_required', $held['blocked']);
        $this->assertSame(401, $held['http_status']);
        $this->assertSame('held', (new StateStore())->get()['connection_state']);
        $this->assertSame($pending_before, (new OutboxStore())->counts()['pending']);
        $requests = count($this->http_log);
        $this->assertSame('held:dbvc_agency_app_password_required', $this->run_delivery()['blocked'], 'A held connection does not retry the hub.');
        $this->assertCount($requests, $this->http_log);
        $this->assertInstanceOf(WP_Error::class, DBVC_Connected_CLI_Inspector::resume([]), 'An invalid credential requires re-enrollment.');

        // Re-enrollment under a fresh invitation rotates the epoch and re-emits every member.
        $invite = (new HubEnrollment())->invite(['client_id' => 'client-a', 'environment_id' => 'a-prod-2']);
        $result = (new EnrollmentService())->exchange(self::HUB, $invite['token']);
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame('a-prod-2', $result['environment_id'], 'A fixed invitation id overrides the proposed id.');
        $this->assertNotSame($enrollment['epoch'], $result['installation_epoch']);
        $this->assertSame($pending_before, $result['superseded_events']);
        $summary = $this->run_observation();
        $this->assertSame(6, $summary['events'], 'Three members, their order, the empty variables order and the empty service inventory under the new epoch.');
        $delivery = $this->run_delivery();
        $this->assertSame(6, $delivery['delivered']);
        $this->assertCount(6, (new EventStore())->all('a-prod-2'));
        $this->assertSame([], (new EventStore())->all($enrollment['environment_id']), 'Nothing was ever delivered under the revoked enrollment.');
    }

    public function test_epoch_mismatch_rejection_holds_the_connection_and_credentials_are_unreadable_with_other_salts(): void
    {
        $enrollment = $this->enroll();
        update_option(self::CLASSES, $this->classes_fixture(1));
        $this->run_observation();

        // The hub rotated the epoch (e.g. after a restore review); our events are rejected, not silently accepted.
        (new EnvironmentRegistry())->update($enrollment['environment_id'], ['current_epoch' => 'enrolled-rotated']);
        $delivery = $this->run_delivery();
        $this->assertSame('held:epoch_mismatch', $delivery['blocked']);
        $this->assertGreaterThan(0, $delivery['rejected']);
        $this->assertSame('epoch_mismatch', (new OutboxStore())->all('rejected')[0]['receipt']['reason']);
        $this->assertSame(0, (new EventStore())->counts()['total']);
        $this->assertInstanceOf(WP_Error::class, DBVC_Connected_CLI_Inspector::resume([]));

        // A copied database on an installation with different salts cannot open the credential: explicit hold, no send.
        (new StateStore())->set_connection_state(StateStore::CONNECTION_ENROLLED, '');
        $sealed = $this->raw_hub_secret();
        $this->assertNotNull(SecretBox::open($sealed));
        add_filter('salt', [$this, 'foreign_salt'], 10, 2);
        $this->assertNull(SecretBox::open($sealed));
        global $wpdb;
        $wpdb->update(Schema::table('outbox'), ['delivery_state' => 'pending', 'available_at' => '2000-01-01 00:00:00'], ['delivery_state' => 'rejected'], ['%s', '%s'], ['%s']);
        $requests = count($this->http_log);
        $clone = $this->run_delivery();
        remove_filter('salt', [$this, 'foreign_salt'], 10);
        $this->assertSame('held:credentials_unreadable', $clone['blocked']);
        $this->assertSame(0, $clone['sent']);
        $this->assertCount($requests, $this->http_log, 'An unreadable credential never reaches the hub.');
    }

    public function foreign_salt($salt, $scheme)
    {
        unset($salt);

        return 'foreign-' . $scheme;
    }

    public function test_enrollment_refuses_insecure_or_invalid_hubs_unless_local(): void
    {
        remove_filter('dbvc_connected_allow_insecure_hub', '__return_true');
        $result = (new EnrollmentService())->exchange('http://hub.test', 'inv-' . str_repeat('1', 48));
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('dbvc_connected_hub_url_insecure', $result->get_error_code());
        $this->assertInstanceOf(WP_Error::class, (new EnrollmentService())->exchange('ftp://hub.test', 'x'));
        $this->assertSame('provisional', (new StateStore())->get()['enrollment_state']);
        $this->assertSame([], $this->http_log);
        add_filter('dbvc_connected_allow_insecure_hub', '__return_true');

        $bad_token = (new EnrollmentService())->exchange(self::HUB, 'inv-' . str_repeat('1', 48));
        $this->assertInstanceOf(WP_Error::class, $bad_token);
        $this->assertSame('dbvc_agency_invitation_not_found', $bad_token->get_error_code());
        $this->assertSame('disconnected', (new StateStore())->get()['connection_state']);
    }

    /**
     * @return array{environment_id: string, epoch: string}
     */
    private function enroll(): array
    {
        $invite = (new HubEnrollment())->invite(['client_id' => 'client-a']);
        $result = (new EnrollmentService())->exchange(self::HUB, $invite['token']);
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->run_observation(); // settle the enrollment reconciliation

        return ['environment_id' => $result['environment_id'], 'epoch' => $result['installation_epoch']];
    }

    private function raw_hub_secret(): string
    {
        global $wpdb;

        return (string) $wpdb->get_var('SELECT hub_secret FROM ' . Schema::table('state') . ' WHERE singleton_id = 1');
    }

    /**
     * @return array<string, mixed>
     */
    private function run_observation(): array
    {
        return DBVC_Connected_Environments_Addon::runtime()->worker()->run(['context' => 'test', 'budget_seconds' => 30]);
    }

    /**
     * @return array<string, mixed>
     */
    private function run_delivery(): array
    {
        return DBVC_Connected_Environments_Addon::runtime()->outbound()->run(['context' => 'test']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function classes_fixture(int $count = 3): array
    {
        $fixture = [
            ['id' => 'aaa111', 'name' => '--flex-col', 'settings' => ['_direction' => 'column'], 'cat' => false],
            ['id' => 'bbb222', 'name' => '--flex-row', 'settings' => ['_direction' => 'row', '_display' => 'flex']],
            ['id' => 'ccc333', 'name' => '--bg-gradient-light', 'settings' => ['_gradient' => ['colors' => [['id' => 'vypvsh', 'stop' => '0'], ['id' => 'wjtzex', 'stop' => '100']]]], 'cat' => 'rgxlvq'],
        ];

        return array_slice($fixture, 0, $count);
    }
}
