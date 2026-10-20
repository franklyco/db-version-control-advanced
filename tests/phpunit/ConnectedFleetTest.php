<?php

use Dbvc\AgencyControl\Enrollment\EnrollmentService;
use Dbvc\AgencyControl\Fleet\FleetService;
use Dbvc\AgencyControl\Storage\OverrideStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * M9 slice 3: the fleet rollup. One row per enrolled client environment with a
 * composed, worst-wins site status (not_reporting > needs_review >
 * update_available > protected > in_sync), aggregated from freshness + approved
 * overrides + framework status. A stale/never-reporting site is never in sync.
 *
 * @group connected-environments
 */
class ConnectedFleetTest extends WP_UnitTestCase
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private $env = [];

    /**
     * @var array<string, int>
     */
    private $sequence = [];

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
        foreach (['f-sync', 'f-prot', 'f-review', 'f-stale'] as $environment_id) {
            $this->env[$environment_id] = $this->enroll('fleetc', $environment_id);
            $this->sequence[$environment_id] = 0;
        }
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

    public function test_overview_rolls_up_each_site_to_a_worst_wins_status(): void
    {
        // f-sync, f-prot and f-review report (fresh); f-stale never reports.
        $this->report('f-sync');
        $this->report('f-prot');
        $this->report('f-review');

        // f-prot: an approved override -> protected. f-review: a rebase-flagged override -> needs review.
        $this->assertTrue((new OverrideStore())->approve($this->override('f-prot', 'fw-a')));
        $this->assertTrue((new OverrideStore())->approve($this->override('f-review', 'fw-b')));
        $flagged = (new OverrideStore())->find('f-review', 'bricks.global_class', 'fw-b', 'fw-def');
        $this->assertTrue((new OverrideStore())->set_state($flagged['override_id'], OverrideStore::STATE_NEEDS_REBASE_REVIEW));

        $overview = (new FleetService())->overview(null, 'fleetc');
        $this->assertSame(
            ['sites' => 4, 'in_sync' => 1, 'update_available' => 0, 'protected' => 1, 'needs_review' => 1, 'not_reporting' => 1],
            $overview['summary']
        );

        $states = [];
        foreach ($overview['sites'] as $site) {
            $states[$site['environment_id']] = $site;
        }
        $this->assertSame('in_sync', $states['f-sync']['state']);
        $this->assertSame(0, $states['f-sync']['locked']);
        $this->assertNull($states['f-sync']['framework'], 'No framework subscription = no version distance.');

        $this->assertSame('protected', $states['f-prot']['state']);
        $this->assertSame(1, $states['f-prot']['locked']);
        $this->assertTrue($states['f-prot']['fresh']);

        $this->assertSame('needs_review', $states['f-review']['state']);
        $this->assertSame(1, $states['f-review']['needs_rebase_review']);

        $this->assertSame('not_reporting', $states['f-stale']['state'], 'A site that never reported is not in sync.');
        $this->assertFalse($states['f-stale']['fresh']);
    }

    public function test_revoked_sites_leave_the_fleet_and_client_scope_filters(): void
    {
        $this->report('f-sync');
        (new \Dbvc\AgencyControl\Storage\EnvironmentRegistry())->update('f-stale', ['status' => 'revoked']);

        $overview = (new FleetService())->overview(null, 'fleetc');
        $ids = array_column($overview['sites'], 'environment_id');
        $this->assertNotContains('f-stale', $ids, 'A revoked environment is not rolled up.');
        $this->assertContains('f-sync', $ids);
        $this->assertSame(3, $overview['summary']['sites']);

        // A different client's sites are excluded by the client filter.
        $this->assertSame([], (new FleetService())->overview(null, 'nobody')['sites']);
    }

    private function report(string $environment_id): void
    {
        $env = $this->env[$environment_id];
        $this->sequence[$environment_id]++;
        $body = ObservationEvent::build([
            'object' => ['domain' => 'bricks.global_class', 'instance_uid' => 'seed-' . $environment_id],
            'projection' => ['profile' => 'bricks-global-class-v1', 'hash' => hash('sha256', 'fleet:' . $environment_id), 'exists' => true, 'complete' => true],
            'event_id' => 'obs-' . $environment_id . '-' . $this->sequence[$environment_id],
            'environment_id' => $env['environment_id'],
            'installation_epoch' => $env['epoch'],
            'sequence' => $this->sequence[$environment_id],
            'observed_at' => '2026-09-28T10:00:00Z',
        ]);
        wp_set_current_user($env['user_id']);
        $GLOBALS['wp_rest_application_password_uuid'] = $env['uuid'];
        $request = new WP_REST_Request('POST', '/dbvc-agency/v1/observations');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(['events' => [$body]]));
        $response = rest_do_request($request);
        wp_set_current_user(0);
        $GLOBALS['wp_rest_application_password_uuid'] = null;
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
    }

    /**
     * @return array<string, mixed>
     */
    private function override(string $environment_id, string $instance_uid): array
    {
        return [
            'agency_id' => 'studio',
            'client_id' => 'fleetc',
            'environment_id' => $environment_id,
            'domain' => 'bricks.global_class',
            'instance_uid' => $instance_uid,
            'definition_uid' => 'fw-def',
            'approved_hash' => hash('sha256', 'lock:' . $environment_id),
            'rationale' => 'client work',
        ];
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

        return ['environment_id' => $data['environment_id'], 'epoch' => $data['installation_epoch'], 'user_id' => get_user_by('login', $data['principal']['username'])->ID, 'uuid' => $data['principal']['uuid']];
    }
}
