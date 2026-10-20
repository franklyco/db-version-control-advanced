<?php

use Dbvc\AgencyControl\Enrollment\EnrollmentService;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\EventStore;
use Dbvc\AgencyControl\Storage\ProjectionStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * Hub-only: enrollment exchange, application-password principal binding,
 * scoped observation receipt and per-event outcomes. Runs without the
 * connector, Bricks or the Visual Editor.
 *
 * @group connected-environments
 */
class AgencyControlEnrollmentReceiptTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        add_filter('wp_is_application_passwords_available', '__return_true');
        add_filter('application_password_is_api_request', '__return_true'); // REST_REQUEST is not defined under PHPUnit.
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        HubSchema::reset_memo();
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        $this->assertSame('ready', DBVC_Agency_Control_Addon::get_gate_state());
        $GLOBALS['wp_rest_server'] = null;
        rest_get_server();
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

    public function test_invitation_exchange_binds_an_application_password_principal_once(): void
    {
        $invite = (new EnrollmentService())->invite(['agency_id' => 'studio', 'client_id' => 'client-a', 'environment_label' => 'Client A production']);
        $this->assertIsArray($invite);
        $this->assertStringStartsWith('inv-', $invite['token']);

        $response = $this->exchange(['token' => $invite['token'], 'environment_id' => 'a-prod', 'site_url' => 'https://a.example/', 'protocol_versions' => [Protocol::PROTOCOL_VERSION]]);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $data = $response->get_data();
        $this->assertSame('a-prod', $data['environment_id']);
        $this->assertStringStartsWith('enrolled-', $data['installation_epoch']);
        $this->assertSame('client-a', $data['client_id']);
        $this->assertSame('wp_application_password', $data['principal']['scheme']);
        $this->assertStringStartsWith('dbvc-env-', $data['principal']['username']);
        $this->assertNotEmpty($data['principal']['password']);
        $this->assertSame(Protocol::MAX_BATCH_EVENTS, $data['hub']['limits']['max_batch_events']);
        $this->assertSame('no-store, no-cache, must-revalidate, max-age=0', $response->get_headers()['Cache-Control']);

        $user = get_user_by('login', $data['principal']['username']);
        $this->assertInstanceOf(WP_User::class, $user);
        $this->assertSame([HubSchema::ENVIRONMENT_ROLE], $user->roles);
        $this->assertFalse(user_can($user, 'read'), 'Service users carry no capabilities.');

        $environment = (new EnvironmentRegistry())->find('a-prod');
        $this->assertSame('enabled', $environment['status']);
        $this->assertSame($user->ID, $environment['principal_user_id']);
        $this->assertSame($data['principal']['uuid'], $environment['principal_uuid']);
        $this->assertSame('https://a.example/', $environment['enrolled_site_url']);

        // The credential authenticates through WordPress's own application-password path and binds the UUID.
        $authenticated = wp_authenticate_application_password(null, $data['principal']['username'], $data['principal']['password']);
        $this->assertInstanceOf(WP_User::class, $authenticated);
        $this->assertSame($data['principal']['uuid'], rest_get_authenticated_app_password());
        $this->assertSame('a-prod', (new EnvironmentRegistry())->find_by_principal($authenticated->ID, rest_get_authenticated_app_password())['environment_id']);
        $this->clear_principal();

        // Single use.
        $again = $this->exchange(['token' => $invite['token'], 'environment_id' => 'a-prod-2', 'protocol_versions' => [Protocol::PROTOCOL_VERSION]]);
        $this->assertSame(409, $again->get_status());
        $this->assertSame('dbvc_agency_invitation_consumed', $again->get_data()['code']);
    }

    public function test_exchange_rejects_unknown_expired_collision_and_unsupported_protocol(): void
    {
        $this->assertSame(404, $this->exchange(['token' => 'inv-' . str_repeat('0', 48), 'environment_id' => 'x', 'protocol_versions' => [Protocol::PROTOCOL_VERSION]])->get_status());
        $this->assertSame(404, $this->exchange(['token' => 'short', 'environment_id' => 'x', 'protocol_versions' => [Protocol::PROTOCOL_VERSION]])->get_status());

        $service = new EnrollmentService();
        $expired = $service->invite(['client_id' => 'client-a', 'ttl_seconds' => 60]);
        global $wpdb;
        $wpdb->update(HubSchema::table('invitations'), ['expires_at' => '2000-01-01 00:00:00'], ['invitation_id' => $expired['invitation_id']], ['%s'], ['%d']);
        $response = $this->exchange(['token' => $expired['token'], 'environment_id' => 'a-prod', 'protocol_versions' => [Protocol::PROTOCOL_VERSION]]);
        $this->assertSame(410, $response->get_status());

        $unsupported = $service->invite(['client_id' => 'client-a']);
        $response = $this->exchange(['token' => $unsupported['token'], 'environment_id' => 'a-prod', 'protocol_versions' => ['agency-control.observation.v9']]);
        $this->assertSame(409, $response->get_status());
        $this->assertSame('dbvc_agency_protocol_unsupported', $response->get_data()['code']);
        $this->assertNull((new EnvironmentRegistry())->find('a-prod'), 'A rejected exchange enrolls nothing.');

        $first = $service->invite(['client_id' => 'client-a']);
        $this->assertSame(200, $this->exchange(['token' => $first['token'], 'environment_id' => 'a-prod', 'protocol_versions' => [Protocol::PROTOCOL_VERSION]])->get_status());
        $second = $service->invite(['client_id' => 'client-b']);
        $response = $this->exchange(['token' => $second['token'], 'environment_id' => 'a-prod', 'protocol_versions' => [Protocol::PROTOCOL_VERSION]]);
        $this->assertSame(409, $response->get_status());
        $this->assertSame('dbvc_agency_environment_id_taken', $response->get_data()['code']);

        $bad_id = $service->invite(['client_id' => 'client-b']);
        $this->assertSame(400, $this->exchange(['token' => $bad_id['token'], 'environment_id' => '-bad id', 'protocol_versions' => [Protocol::PROTOCOL_VERSION]])->get_status());

        $this->assertInstanceOf(WP_Error::class, $service->invite(['client_id' => 'client-b', 'environment_id' => 'a-prod']));
        $this->assertInstanceOf(WP_Error::class, $service->invite(['client_id' => 'bad client!']));
    }

    public function test_observation_receipt_outcomes_are_scoped_and_durable(): void
    {
        $a = $this->enroll('client-a', 'a-prod');
        $b = $this->enroll('client-b', 'b-prod');

        $this->act_as($a);
        $event1 = $this->event($a, 1, 'class-x', 'a1');
        $event2 = $this->event($a, 2, 'class-y', 'b2');
        $response = $this->post_observations(['batch_id' => 'batch-1', 'events' => [$event1, $event2]]);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $data = $response->get_data();
        $this->assertSame(['accepted' => 2, 'duplicate' => 0, 'conflict' => 0, 'rejected' => 0], $data['counts']);
        $this->assertSame('accepted', $data['outcomes'][0]['outcome']);
        $this->assertSame('projection_inserted', $data['outcomes'][0]['reason']);

        $events = new EventStore();
        $stored = $events->find('a-prod', $a['epoch'], 'obs-000000000001');
        $this->assertSame('routed', $stored['routing_state'], 'Routing runs after durable receipt; without subscriptions it yields no deliveries.');
        $this->assertNotEmpty($stored['routing_policy_revision']);
        $this->assertSame([], (new \Dbvc\AgencyControl\Storage\DeliveryStore())->all('a-prod'));
        $this->assertSame(['accepted' => 2, 'duplicate' => 0, 'conflict' => 0, 'rejected' => 0], $data['counts']);
        $this->assertSame(2, $data['routing']['routed']);
        $this->assertSame(ObservationEvent::digest($event1), $stored['body_digest']);
        $this->assertSame('batch-1', $stored['batch_id']);
        $environment = (new EnvironmentRegistry())->find('a-prod');
        $this->assertSame(2, $environment['received_events']);
        $this->assertSame(2, $environment['max_received_sequence']);
        $this->assertSame('batch-1', $environment['last_batch_id']);
        $this->assertNotEmpty($environment['last_contact_at']);

        // Exact replay is a duplicate; same id with a different body is a conflict; sequence reuse is a conflict.
        $mutated = $event1;
        $mutated['projection']['hash'] = str_repeat('f', 64);
        $reuse = $this->event($a, 2, 'class-z', 'c3');
        $reuse['event_id'] = 'obs-retry-under-reused-sequence';
        $response = $this->post_observations(['events' => [$event1, $mutated, $reuse]]);
        $this->assertSame(200, $response->get_status());
        $outcomes = array_column($response->get_data()['outcomes'], 'outcome');
        $reasons = array_column($response->get_data()['outcomes'], 'reason');
        $this->assertSame(['duplicate', 'conflict', 'conflict'], $outcomes);
        $this->assertSame(['already_received', 'event_digest_mismatch', 'sequence_reuse'], $reasons);
        $this->assertSame(2, (new EnvironmentRegistry())->find('a-prod')['received_events'], 'Replays never count twice.');

        // Wrong epoch, forged environment, sender-supplied authority fields and invalid bodies are rejected per event.
        $wrong_epoch = $this->event($a, 3, 'class-q', 'd4');
        $wrong_epoch['installation_epoch'] = 'epoch-from-a-backup';
        $forged_env = $this->event($a, 4, 'class-q', 'd4');
        $forged_env['environment_id'] = 'b-prod';
        $with_recipients = $this->event($a, 5, 'class-q', 'd4');
        $with_recipients['recipients'] = ['b-stage'];
        $apply_claim = $this->event($a, 6, 'class-q', 'd4');
        $apply_claim['origin'] = 'apply';
        $response = $this->post_observations(['events' => [$wrong_epoch, $forged_env, $with_recipients, 'not-an-event', $apply_claim]]);
        $this->assertSame(200, $response->get_status());
        $reasons = array_column($response->get_data()['outcomes'], 'reason');
        $this->assertSame('epoch_mismatch', $reasons[0]);
        $this->assertSame('environment_mismatch', $reasons[1]);
        $this->assertSame('invalid_event:unknown:recipients', $reasons[2]);
        $this->assertSame('invalid_event:body_not_object', $reasons[3]);
        $this->assertSame('accepted', $response->get_data()['outcomes'][4]['outcome'], 'A sender-labelled apply origin is stored as an ordinary observation (informational only).');
        $this->assertSame(3, (new EventStore())->counts()['total']);

        // Cross-client isolation: B's principal cannot report as A, and A's events are invisible under B's scope.
        $this->act_as($b);
        $response = $this->post_observations(['events' => [$this->event($a, 7, 'class-x', 'e5')]]);
        $this->assertSame('environment_mismatch', $response->get_data()['outcomes'][0]['reason']);
        $this->assertSame([], (new EventStore())->all('b-prod'));
        $this->assertCount(3, (new EventStore())->all('a-prod'));
    }

    public function test_projection_keeps_newest_per_object_and_reports_sequence_gaps(): void
    {
        $a = $this->enroll('client-a', 'a-prod');
        $this->act_as($a);

        // Event 12 for X arrives before 11 for Y and before 10 for X (a retry of older state).
        $x12 = $this->event($a, 12, 'class-x', 'a12');
        $y11 = $this->event($a, 11, 'class-y', 'b11');
        $x10 = $this->event($a, 10, 'class-x', 'a10');
        $response = $this->post_observations(['events' => [$x12, $y11, $x10]]);
        $this->assertSame(['accepted', 'accepted', 'accepted'], array_column($response->get_data()['outcomes'], 'outcome'));
        $this->assertSame('projection_stale', $response->get_data()['outcomes'][2]['reason'], 'Older observation is stored as an event but never regresses the projection.');

        $projections = new ProjectionStore();
        $x = $projections->get('a-prod', $a['epoch'], 'bricks.global_class', 'class-x', 'bricks-global-class-v1');
        $this->assertSame(12, $x['observed_sequence']);
        $this->assertSame(str_repeat('a', 62) . '12', $x['semantic_hash']);
        $y = $projections->get('a-prod', $a['epoch'], 'bricks.global_class', 'class-y', 'bricks-global-class-v1');
        $this->assertSame(11, $y['observed_sequence']);

        $summary = (new EventStore())->sequence_summary();
        $this->assertSame(['environment_id' => 'a-prod', 'installation_epoch' => $a['epoch'], 'received' => 3, 'max_sequence' => 12, 'gaps' => 9, 'pending_routing' => 0], $summary[0]);

        $capabilities = rest_do_request(new WP_REST_Request('GET', '/dbvc-agency/v1/capabilities'));
        $this->assertSame(200, $capabilities->get_status());
        $this->assertSame('a-prod', $capabilities->get_data()['environment_id']);
        $this->assertSame(['receipt' => true, 'inbox' => true, 'release_payloads' => true, 'prepare' => true, 'apply' => true], $capabilities->get_data()['capabilities']);
    }

    public function test_batch_limits_authentication_revocation_and_site_url_hold(): void
    {
        $a = $this->enroll('client-a', 'a-prod');

        // No principal: 401. Administrator cookie: still 401. Unbound app password on another user: 403.
        $this->clear_principal();
        $this->assertSame(401, $this->post_observations(['events' => [$this->event($a, 1, 'class-x', 'a1')]])->get_status());
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertSame(401, $this->post_observations(['events' => [$this->event($a, 1, 'class-x', 'a1')]])->get_status());
        $other = self::factory()->user->create(['role' => 'subscriber']);
        $password = WP_Application_Passwords::create_new_application_password($other, ['name' => 'unbound']);
        wp_set_current_user($other);
        $GLOBALS['wp_rest_application_password_uuid'] = $password[1]['uuid'];
        $response = $this->post_observations(['events' => [$this->event($a, 1, 'class-x', 'a1')]]);
        $this->assertSame(403, $response->get_status());
        $this->assertSame('dbvc_agency_not_enrolled', $response->get_data()['code']);

        $this->act_as($a);
        $this->assertSame(400, $this->post_observations(['events' => []])->get_status());
        $too_many = [];
        for ($i = 1; $i <= Protocol::MAX_BATCH_EVENTS + 1; $i++) {
            $too_many[] = $this->event($a, $i, 'class-' . $i, 'h' . $i);
        }
        $this->assertSame(400, $this->post_observations(['events' => $too_many])->get_status());
        $this->assertSame(400, $this->post_observations(['events' => 'nope'])->get_status());

        // A relocated/cloned sender (different site URL) is held; the batch is refused until an operator releases it.
        $request = $this->observations_request(['events' => [$this->event($a, 1, 'class-x', 'a1')]]);
        $request->set_header(Protocol::HEADER_SITE_URL, 'https://clone.example/');
        $response = rest_do_request($request);
        $this->assertSame(409, $response->get_status());
        $this->assertSame('site_url_mismatch', $response->get_data()['data']['hold_reason']);
        $this->assertSame('held', (new EnvironmentRegistry())->find('a-prod')['status']);
        $this->assertSame(409, $this->post_observations(['events' => [$this->event($a, 1, 'class-x', 'a1')]])->get_status(), 'Held stays held.');
        $this->assertSame(0, (new EventStore())->counts()['total']);

        $released = (new EnrollmentService())->release('a-prod');
        $this->assertSame('enabled', $released['status']);
        $this->assertSame('https://clone.example/', (new EnvironmentRegistry())->find('a-prod')['enrolled_site_url'], 'Release adopts the reviewed URL.');
        $this->assertSame(200, $this->post_observations(['events' => [$this->event($a, 1, 'class-x', 'a1')]])->get_status());

        // Revocation deletes the credential and refuses further receipt; release cannot undo it.
        $revoked = (new EnrollmentService())->revoke('a-prod');
        $this->assertSame('revoked', $revoked['status']);
        $this->assertSame(403, $this->post_observations(['events' => [$this->event($a, 2, 'class-x', 'a2')]])->get_status());
        $this->assertNull(WP_Application_Passwords::get_user_application_password($a['user_id'], $a['uuid']));
        $this->assertInstanceOf(WP_Error::class, (new EnrollmentService())->release('a-prod'));
        $this->assertInstanceOf(WP_Error::class, wp_authenticate_application_password(new WP_Error('x', 'x'), $a['username'], $a['password']));
    }

    /**
     * @return array<string, mixed>
     */
    private function enroll(string $client_id, string $environment_id): array
    {
        $invite = (new EnrollmentService())->invite(['client_id' => $client_id, 'environment_label' => $environment_id]);
        $response = $this->exchange(['token' => $invite['token'], 'environment_id' => $environment_id, 'site_url' => 'https://' . $environment_id . '.example/', 'protocol_versions' => [Protocol::PROTOCOL_VERSION]]);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $data = $response->get_data();
        $user = get_user_by('login', $data['principal']['username']);

        return [
            'environment_id' => $data['environment_id'],
            'epoch' => $data['installation_epoch'],
            'user_id' => $user->ID,
            'uuid' => $data['principal']['uuid'],
            'username' => $data['principal']['username'],
            'password' => $data['principal']['password'],
            'site_url' => 'https://' . $environment_id . '.example/',
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

    private function exchange(array $body): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/dbvc-agency/v1/enrollments/exchange');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));

        return rest_do_request($request);
    }

    private function observations_request(array $body): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/dbvc-agency/v1/observations');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));

        return $request;
    }

    private function post_observations(array $body): WP_REST_Response
    {
        return rest_do_request($this->observations_request($body));
    }

    /**
     * @return array<string, mixed>
     */
    private function event(array $enrollment, int $sequence, string $instance_uid, string $hash_seed): array
    {
        return ObservationEvent::build([
            'event_id' => 'obs-' . str_pad((string) $sequence, 12, '0', STR_PAD_LEFT),
            'environment_id' => $enrollment['environment_id'],
            'installation_epoch' => $enrollment['epoch'],
            'sequence' => $sequence,
            'observed_at' => '2026-09-18T10:00:00Z',
            'object' => ['domain' => 'bricks.global_class', 'instance_uid' => $instance_uid],
            'projection' => ['profile' => 'bricks-global-class-v1', 'hash' => substr(str_repeat($hash_seed[0], 64), 0, 64 - strlen($hash_seed) + 1) . substr($hash_seed, 1), 'exists' => true, 'complete' => true],
        ]);
    }
}
