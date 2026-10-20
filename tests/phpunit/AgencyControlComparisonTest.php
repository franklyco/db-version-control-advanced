<?php

use Dbvc\AgencyControl\Comparison\BaselineService;
use Dbvc\AgencyControl\Comparison\ComparisonService;
use Dbvc\AgencyControl\Enrollment\EnrollmentService;
use Dbvc\AgencyControl\Enrollment\SubscriptionService;
use Dbvc\AgencyControl\Storage\BaselineStore;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * M3 step 1: hash-level three-way comparison of a source/target pair over
 * each environment's own projections and explicitly confirmed baselines.
 *
 * @group connected-environments
 */
class AgencyControlComparisonTest extends WP_UnitTestCase
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
        foreach ([['client-a', 'a-prod'], ['client-a', 'a-stage'], ['client-b', 'b-prod']] as $pair) {
            $this->env[$pair[1]] = $this->enroll($pair[0], $pair[1]);
            $this->sequence[$pair[1]] = 0;
        }
        (new SubscriptionService())->subscribe_client('a-prod', 'a-stage', []);
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

    public function test_states_follow_the_three_way_matrix_and_baselines_advance_only_on_confirmation(): void
    {
        // Both environments report complete Bricks class collections with two shared-lineage classes.
        $this->report('a-prod', [$this->class('class-x', 'x1'), $this->class('class-y', 'y1'), $this->order('o1')]);
        $this->report('a-stage', [$this->class('class-x', 'x1'), $this->class('class-y', 'y1'), $this->order('o1')]);

        $service = new ComparisonService();
        $result = $service->compare('a-prod', 'a-stage');
        $this->assertIsArray($result);
        $this->assertSame(['synchronized' => 0, 'outgoing' => 0, 'incoming' => 0, 'converged' => 0, 'conflict' => 0, 'baseline_required' => 3, 'unknown' => 0], $result['counts'], 'Equal hashes without a baseline are not clean yet.');
        $this->assertTrue($result['source']['fresh']);
        $this->assertSame(['bricks.global_class' => true], $result['target']['coverage']);

        // Receiving, routing and acknowledging events never created a baseline.
        $this->assertSame(0, (new BaselineStore())->count());

        $confirmed = (new BaselineService())->confirm('a-prod', 'a-stage', null, null, 'initial agreement');
        $this->assertSame(3, $confirmed['confirmed']);
        $this->assertSame(0, $confirmed['skipped']);
        $this->assertSame(3, (new BaselineStore())->count());
        $result = $service->compare('a-prod', 'a-stage');
        $this->assertSame(3, $result['counts']['synchronized']);

        // Source edits class-x → outgoing; target edits class-y → incoming.
        $this->report('a-prod', [$this->class('class-x', 'x2')]);
        $this->report('a-stage', [$this->class('class-y', 'y2')]);
        $rows = $this->rows_by_uid($service->compare('a-prod', 'a-stage'));
        $this->assertSame('outgoing', $rows['class-x']['state']);
        $this->assertSame('incoming', $rows['class-y']['state']);
        $this->assertSame('synchronized', $rows[ComparisonService::ORDER_INSTANCE_UID]['state']);

        // Both make the same change → converged; confirmation advances the baseline; different changes → conflict.
        $this->report('a-stage', [$this->class('class-x', 'x2')]);
        $rows = $this->rows_by_uid($service->compare('a-prod', 'a-stage'));
        $this->assertSame('converged', $rows['class-x']['state']);
        $confirmed = (new BaselineService())->confirm('a-prod', 'a-stage', 'bricks.global_class', 'class-x');
        $this->assertSame(1, $confirmed['confirmed']);
        $this->assertSame('synchronized', $this->rows_by_uid($service->compare('a-prod', 'a-stage'))['class-x']['state']);
        $this->report('a-prod', [$this->class('class-y', 'y3')]);
        $rows = $this->rows_by_uid($service->compare('a-prod', 'a-stage'));
        $this->assertSame('conflict', $rows['class-y']['state']);
        $skipped = (new BaselineService())->confirm('a-prod', 'a-stage', 'bricks.global_class', 'class-y');
        $this->assertSame(0, $skipped['confirmed']);
        $this->assertSame('no_agreement', $skipped['skipped_rows'][0]['reason']);

        // A new class on the source inside a complete collection is verified absent on the target but, without a baseline,
        // "new on source" and "deleted on target" are indistinguishable: baseline_required until an operator accepts the absence.
        $this->report('a-prod', [$this->class('class-z', 'z1'), $this->order('o2')]);
        $rows = $this->rows_by_uid($service->compare('a-prod', 'a-stage'));
        $this->assertSame('baseline_required', $rows['class-z']['state']);
        $this->assertFalse($rows['class-z']['target_exists']);
        $this->assertFalse($rows['class-z']['target_observed'], 'Never observed on the target, as opposed to tombstoned.');
        $this->assertSame('outgoing', $rows[ComparisonService::ORDER_INSTANCE_UID]['state']);
        $this->assertSame(0, (new BaselineService())->confirm('a-prod', 'a-stage', 'bricks.global_class', 'class-z')['confirmed']);
        $accepted = (new BaselineService())->confirm('a-prod', 'a-stage', 'bricks.global_class', 'class-z', 'target never had it', true);
        $this->assertSame(1, $accepted['confirmed']);
        $this->assertSame(ComparisonService::absent_hash(), $accepted['confirmed_rows'][0]['baseline_hash']);
        $rows = $this->rows_by_uid($service->compare('a-prod', 'a-stage'));
        $this->assertSame('outgoing', $rows['class-z']['state']);
        // A tombstone on both sides is synchronized regardless of baselines.
        $this->report('a-prod', [$this->tombstone('class-z')]);
        $rows = $this->rows_by_uid($service->compare('a-prod', 'a-stage'));
        $this->assertSame('synchronized', $rows['class-z']['state']);
        $this->assertContains('both_absent', $rows['class-z']['reasons']);
    }

    public function test_unknown_when_stale_incomplete_or_outside_verified_coverage(): void
    {
        $this->report('a-prod', [$this->class('class-x', 'x1'), $this->order('o1'), $this->service('svc-1', 's1')]);
        $this->report('a-stage', [$this->class('class-x', 'x1', false), $this->order('o1')]);
        $service = new ComparisonService();
        $rows = $this->rows_by_uid($service->compare('a-prod', 'a-stage'));
        $this->assertSame('unknown', $rows['class-x']['state']);
        $this->assertContains('incomplete_target', $rows['class-x']['reasons']);
        $this->assertSame('unknown', $rows['svc-1']['state'], 'wp.service has no collection projection, so a never-observed target object cannot be proven absent.');
        $this->assertContains('target_coverage_unknown', $rows['svc-1']['reasons']);
        $confirmed = (new BaselineService())->confirm('a-prod', 'a-stage');
        $this->assertSame(1, $confirmed['confirmed'], 'Only the complete, agreeing order projection is confirmable.');
        $this->assertSame(ComparisonService::ORDER_INSTANCE_UID, $confirmed['confirmed_rows'][0]['source_instance_uid']);
        $this->assertSame(['unknown:incomplete_target', 'unknown:target_coverage_unknown'], [$confirmed['skipped_rows'][0]['reason'], $confirmed['skipped_rows'][1]['reason']]);

        // Stale contact makes every row unknown, even with equal hashes.
        $this->report('a-stage', [$this->class('class-x', 'x1')]);
        $this->assertSame('baseline_required', $this->rows_by_uid($service->compare('a-prod', 'a-stage'))['class-x']['state']);
        (new EnvironmentRegistry())->update('a-stage', ['last_contact_at' => '2000-01-01 00:00:00']);
        $result = $service->compare('a-prod', 'a-stage');
        $this->assertFalse($result['target']['fresh']);
        $this->assertSame('unknown', $this->rows_by_uid($result)['class-x']['state']);
        $this->assertContains('stale_target', $this->rows_by_uid($result)['class-x']['reasons']);
        $this->assertSame(0, (new BaselineService())->confirm('a-prod', 'a-stage')['confirmed']);

        // Scope: cross-client and self comparisons are refused.
        $this->assertSame('dbvc_agency_scope_mismatch', $service->compare('a-prod', 'b-prod')->get_error_code());
        $this->assertSame('dbvc_agency_self_comparison', $service->compare('a-prod', 'a-prod')->get_error_code());
        $this->assertSame('dbvc_agency_invalid_domain', $service->compare('a-prod', 'a-stage', 'bricks.template')->get_error_code());
    }

    public function test_independently_enrolled_lineages_pair_only_through_explicit_links(): void
    {
        // Same class, different sidecar UIDs on each environment.
        $this->report('a-prod', [$this->class('bricks-global-class-aaaa', 'x1'), $this->order('o1')]);
        $this->report('a-stage', [$this->class('bricks-global-class-bbbb', 'x1'), $this->order('o1')]);
        $service = new ComparisonService();
        $rows = $this->rows_by_uid($service->compare('a-prod', 'a-stage', 'bricks.global_class'));
        $this->assertSame('baseline_required', $rows['bricks-global-class-aaaa']['state'], 'Without a link the source class looks unrelated to the target.');
        $this->assertFalse($rows['bricks-global-class-aaaa']['target_observed']);
        $this->assertSame('baseline_required', $rows['bricks-global-class-bbbb']['state']);
        $this->assertFalse($rows['bricks-global-class-bbbb']['source_observed']);

        $link = (new BaselineService())->link(['domain' => 'bricks.global_class', 'source_environment_id' => 'a-prod', 'source_instance_uid' => 'bricks-global-class-aaaa', 'target_environment_id' => 'a-stage', 'target_instance_uid' => 'bricks-global-class-bbbb']);
        $this->assertSame('created', $link['result']);
        $rows = $this->rows_by_uid($service->compare('a-prod', 'a-stage', 'bricks.global_class'));
        $this->assertArrayNotHasKey('bricks-global-class-bbbb', $rows, 'The linked target instance is consumed by its source pair.');
        $this->assertSame('link', $rows['bricks-global-class-aaaa']['pairing']);
        $this->assertSame('bricks-global-class-bbbb', $rows['bricks-global-class-aaaa']['target_instance_uid']);
        $this->assertSame('baseline_required', $rows['bricks-global-class-aaaa']['state']);
        $this->assertSame(1, (new BaselineService())->confirm('a-prod', 'a-stage', 'bricks.global_class', 'bricks-global-class-aaaa')['confirmed']);
        $this->assertSame('synchronized', $this->rows_by_uid($service->compare('a-prod', 'a-stage', 'bricks.global_class'))['bricks-global-class-aaaa']['state']);
        $this->assertSame('exists', (new BaselineService())->link(['domain' => 'bricks.global_class', 'source_environment_id' => 'a-prod', 'source_instance_uid' => 'bricks-global-class-aaaa', 'target_environment_id' => 'a-stage', 'target_instance_uid' => 'bricks-global-class-bbbb'])['result']);
        $this->assertInstanceOf(WP_Error::class, (new BaselineService())->link(['domain' => 'bricks.global_class', 'source_environment_id' => 'a-prod', 'source_instance_uid' => 'x', 'target_environment_id' => 'b-prod', 'target_instance_uid' => 'y']));
    }

    public function test_service_inventory_projection_makes_never_observed_services_verifiably_absent(): void
    {
        $service = new ComparisonService();
        $this->report('a-prod', [$this->service('svc-1', 's1'), $this->service_inventory('i1')]);
        $this->report('a-stage', [$this->service_inventory('i0')]);
        $result = $service->compare('a-prod', 'a-stage', 'wp.service');
        $this->assertSame(['wp.service' => true], $result['target']['coverage']);
        $rows = $this->rows_by_uid($result);
        $this->assertSame('baseline_required', $rows['svc-1']['state'], 'Verified absent on the target, but "new on source" and "deleted on target" are indistinguishable without a baseline.');
        $this->assertFalse($rows['svc-1']['target_exists']);
        $this->assertFalse($rows['svc-1']['target_observed']);
        $this->assertSame('baseline_required', $rows[ComparisonService::ORDER_INSTANCE_UID]['state'], 'The inventories differ and have no baseline.');
        $this->assertSame(1, (new BaselineService())->confirm('a-prod', 'a-stage', 'wp.service', 'svc-1', 'stage never had it', true)['confirmed']);
        $this->assertSame('outgoing', $this->rows_by_uid($service->compare('a-prod', 'a-stage', 'wp.service'))['svc-1']['state']);

        // An incomplete inventory (a member without identity) withdraws coverage: unknown again.
        $this->report('a-stage', [$this->service_inventory('i0', false)]);
        $result = $service->compare('a-prod', 'a-stage', 'wp.service');
        $this->assertSame(['wp.service' => false], $result['target']['coverage']);
        $this->assertSame('unknown', $this->rows_by_uid($result)['svc-1']['state']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows_by_uid(array $result): array
    {
        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[$row['source_instance_uid']] = $row;
        }

        return $rows;
    }

    private function report(string $environment_id, array $events): void
    {
        $env = $this->env[$environment_id];
        $bodies = [];
        foreach ($events as $event) {
            $this->sequence[$environment_id]++;
            $bodies[] = ObservationEvent::build(array_merge($event, [
                'event_id' => 'obs-' . str_pad((string) $this->sequence[$environment_id], 12, '0', STR_PAD_LEFT),
                'environment_id' => $env['environment_id'],
                'installation_epoch' => $env['epoch'],
                'sequence' => $this->sequence[$environment_id],
                'observed_at' => '2026-09-19T10:00:00Z',
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
        $this->assertSame(count($bodies), $response->get_data()['counts']['accepted'], wp_json_encode($response->get_data()['outcomes']));
    }

    private function class(string $uid, string $seed, bool $complete = true): array
    {
        return ['object' => ['domain' => 'bricks.global_class', 'instance_uid' => $uid], 'projection' => ['profile' => 'bricks-global-class-v1', 'hash' => $this->hash($seed), 'exists' => true, 'complete' => $complete]];
    }

    private function tombstone(string $uid): array
    {
        return ['object' => ['domain' => 'bricks.global_class', 'instance_uid' => $uid], 'projection' => ['profile' => 'bricks-global-class-v1', 'hash' => ComparisonService::absent_hash(), 'exists' => false, 'complete' => true]];
    }

    private function order(string $seed, bool $complete = true): array
    {
        return ['object' => ['domain' => 'bricks.global_class', 'instance_uid' => ComparisonService::ORDER_INSTANCE_UID], 'projection' => ['profile' => 'bricks-collection-order-v1', 'hash' => $this->hash($seed), 'exists' => true, 'complete' => $complete]];
    }

    private function service_inventory(string $seed, bool $complete = true): array
    {
        return ['object' => ['domain' => 'wp.service', 'instance_uid' => ComparisonService::ORDER_INSTANCE_UID], 'projection' => ['profile' => 'wp-service-inventory-v1', 'hash' => $this->hash($seed), 'exists' => true, 'complete' => $complete]];
    }

    private function service(string $uid, string $seed): array
    {
        return ['object' => ['domain' => 'wp.service', 'instance_uid' => $uid], 'projection' => ['profile' => 'wp-service-v1', 'hash' => $this->hash($seed), 'exists' => true, 'complete' => true]];
    }

    private function hash(string $seed): string
    {
        return hash('sha256', 'fixture:' . $seed);
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
