<?php

use Dbvc\AgencyControl\Comparison\BaselineService;
use Dbvc\AgencyControl\Enrollment\EnrollmentService;
use Dbvc\AgencyControl\Parity\ParityScheduler;
use Dbvc\AgencyControl\Parity\SyncPolicyService;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\AgencyControl\Storage\SyncPolicyStore;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * M8 slice 4b: the auto-parity WP-Cron scheduler. One base tick advances every
 * enabled `auto` policy that is either mid-cycle or due by its per-policy cadence,
 * and never touches manual/assisted/disabled policies or one that is not yet due.
 *
 * @group connected-environments
 */
class ConnectedParitySchedulerTest extends WP_UnitTestCase
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private $env = [];

    /**
     * @var int
     */
    private $sequence = 0;

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
        foreach (['src', 't-auto', 't-manual'] as $id) {
            $this->env[$id] = $this->enroll('client-a', $id);
        }
        // A shared baseline for each pair, then the source diverges → outgoing on both.
        $this->report('src', [$this->klass('class-x', 'x1'), $this->order('o1')]);
        $this->report('t-auto', [$this->klass('class-x', 'x1'), $this->order('o1')]);
        $this->report('t-manual', [$this->klass('class-x', 'x1'), $this->order('o1')]);
        (new BaselineService())->confirm('src', 't-auto', null, null, 'seed');
        (new BaselineService())->confirm('src', 't-manual', null, null, 'seed');
        $this->report('src', [$this->klass('class-x', 'x2')]);
    }

    public function tear_down(): void
    {
        wp_set_current_user(0);
        $GLOBALS['wp_rest_application_password_uuid'] = null;
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

    public function test_tick_advances_due_auto_policies_and_skips_the_rest(): void
    {
        $service = new SyncPolicyService();
        $store = new SyncPolicyStore();
        // Due auto policy, a manual-cadence auto policy, and an assisted policy.
        $service->set(['source' => 'src', 'target' => 't-auto', 'mode' => 'auto', 'cadence' => 'hourly']);
        $service->set(['source' => 'src', 'target' => 't-manual', 'mode' => 'auto', 'cadence' => 'manual']);

        $result = ParityScheduler::tick();
        $advanced = [];
        foreach ($result['results'] as $row) {
            $advanced[$row['target']] = $row['outcome'];
        }
        $this->assertSame(1, $result['ran'], wp_json_encode($result));
        $this->assertSame('release_open', $advanced['t-auto'] ?? null, 'The due auto policy is advanced.');
        $this->assertArrayNotHasKey('t-manual', $advanced, 'A manual-cadence policy is never cron-driven.');

        // The advanced policy is now mid-cycle; the manual one was untouched.
        $this->assertNotSame('', $store->find('src', 't-auto')['active_release_uid']);
        $this->assertSame('', $store->find('src', 't-manual')['active_release_uid']);
        $this->assertSame('', $store->find('src', 't-manual')['last_run_note']);
    }

    public function test_tick_always_advances_an_in_flight_cycle_but_respects_cadence_when_idle(): void
    {
        $service = new SyncPolicyService();
        $store = new SyncPolicyStore();
        $service->set(['source' => 'src', 'target' => 't-auto', 'mode' => 'auto', 'cadence' => 'daily']);

        // First tick starts a cycle (due because it never ran).
        $this->assertSame(1, ParityScheduler::tick()['ran']);
        $this->assertNotSame('', $store->find('src', 't-auto')['active_release_uid']);

        // Mid-cycle it advances every tick regardless of the daily cadence.
        $second = ParityScheduler::tick();
        $this->assertSame(1, $second['ran']);
        $this->assertSame('awaiting_payloads', $second['results'][0]['outcome']);

        // Once idle again (cycle cleared) with a recent run and a daily cadence, it is not due.
        $store->set_state('src', 't-auto', '', '', 'cleared');
        $idle = ParityScheduler::tick();
        $this->assertSame(0, $idle['ran'], 'An idle policy within its cadence window is skipped.');
    }

    public function test_tick_skips_disabled_and_assisted_policies(): void
    {
        $service = new SyncPolicyService();
        $service->set(['source' => 'src', 'target' => 't-auto', 'mode' => 'assisted', 'cadence' => 'hourly']);
        $service->set(['source' => 'src', 'target' => 't-manual', 'mode' => 'auto', 'cadence' => 'hourly', 'enabled' => false]);

        $result = ParityScheduler::tick();
        $this->assertSame(0, $result['ran'], 'Assisted and disabled policies are never cron-driven.');
    }

    // --- Harness ---------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function klass(string $uid, string $seed): array
    {
        $body = Canonicalizer::encode(['id' => 'r-' . $seed, 'name' => '--c', 'settings' => ['_x' => $seed]]);

        return ['object' => ['domain' => 'bricks.global_class', 'instance_uid' => $uid], 'projection' => ['profile' => 'bricks-global-class-v1', 'hash' => Canonicalizer::hash($body), 'exists' => true, 'complete' => true]];
    }

    /**
     * @return array<string, mixed>
     */
    private function order(string $seed): array
    {
        return ['object' => ['domain' => 'bricks.global_class', 'instance_uid' => \Dbvc\AgencyControl\Comparison\ComparisonService::ORDER_INSTANCE_UID], 'projection' => ['profile' => 'bricks-collection-order-v1', 'hash' => hash('sha256', 'fixture:' . $seed), 'exists' => true, 'complete' => true]];
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    private function report(string $key, array $events): void
    {
        $env = $this->env[$key];
        $bodies = [];
        foreach ($events as $event) {
            $this->sequence++;
            $bodies[] = ObservationEvent::build(array_merge($event, [
                'event_id' => 'obs-' . str_pad((string) $this->sequence, 12, '0', STR_PAD_LEFT),
                'environment_id' => $env['environment_id'],
                'installation_epoch' => $env['epoch'],
                'sequence' => $this->sequence,
                'observed_at' => '2026-09-25T10:00:00Z',
            ]));
        }
        wp_set_current_user($env['user_id']);
        $GLOBALS['wp_rest_application_password_uuid'] = $env['uuid'];
        $request = new WP_REST_Request('POST', '/dbvc-agency/v1/observations');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(['events' => $bodies]));
        $response = rest_do_request($request);
        wp_set_current_user(0);
        $GLOBALS['wp_rest_application_password_uuid'] = null;
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
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
        $data = rest_do_request($request)->get_data();

        return ['environment_id' => $data['environment_id'], 'epoch' => $data['installation_epoch'], 'user_id' => get_user_by('login', $data['principal']['username'])->ID, 'uuid' => $data['principal']['uuid']];
    }
}
