<?php

use Dbvc\AgencyControl\Comparison\BaselineService;
use Dbvc\AgencyControl\Enrollment\EnrollmentService;
use Dbvc\AgencyControl\Parity\SyncDriver;
use Dbvc\AgencyControl\Parity\SyncPolicyService;
use Dbvc\AgencyControl\Storage\OverrideStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * M8 slice 3: the parity engine (assisted). A per-pair sync policy plus a
 * Compare-driven driver that auto-proposes a release for `outgoing` objects
 * through the existing release pipeline — conflicts hold unless `source_wins`,
 * caps and include/exclude scope the proposal, and nothing is applied here.
 *
 * @group connected-environments
 */
class ConnectedParityTest extends WP_UnitTestCase
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

    public function test_sync_policy_crud_and_validation(): void
    {
        $service = new SyncPolicyService();

        $policy = $service->set([
            'source' => 'a-prod',
            'target' => 'a-stage',
            'mode' => 'assisted',
            'conflict_policy' => 'hold',
            'scope_domains' => ['bricks.global_class', 'wp.post:portfolio'],
            'max_objects' => 10,
        ]);
        $this->assertIsArray($policy, is_wp_error($policy) ? $policy->get_error_message() : '');
        $this->assertSame('assisted', $policy['mode']);
        $this->assertSame('hold', $policy['conflict_policy']);
        $this->assertTrue($policy['enabled']);
        $this->assertSame(['bricks.global_class', 'wp.post:portfolio'], $policy['scope_domains']);
        $this->assertSame('client-a', $policy['client_id']);
        $this->assertSame(10, $policy['max_objects']);

        // get + list.
        $this->assertSame('assisted', $service->get('a-prod', 'a-stage')['mode']);
        $this->assertCount(1, $service->all());

        // upsert updates the same row.
        $updated = $service->set(['source' => 'a-prod', 'target' => 'a-stage', 'mode' => 'auto']);
        $this->assertSame('auto', $updated['mode']);
        $this->assertCount(1, $service->all(), 'One row per pair.');

        // Validation.
        $this->assertSame('dbvc_agency_self_subscription', $service->set(['source' => 'a-prod', 'target' => 'a-prod'])->get_error_code());
        $this->assertSame('dbvc_agency_scope_mismatch', $service->set(['source' => 'a-prod', 'target' => 'b-prod'])->get_error_code());
        $this->assertSame('dbvc_agency_invalid_mode', $service->set(['source' => 'a-prod', 'target' => 'a-stage', 'mode' => 'bogus'])->get_error_code());
        $this->assertSame('dbvc_agency_invalid_conflict_policy', $service->set(['source' => 'a-prod', 'target' => 'a-stage', 'conflict_policy' => 'nope'])->get_error_code());
        $this->assertSame('dbvc_agency_invalid_domain', $service->set(['source' => 'a-prod', 'target' => 'a-stage', 'scope_domains' => ['not a domain']])->get_error_code());
        $this->assertSame('dbvc_agency_environment_not_found', $service->set(['source' => 'a-prod', 'target' => 'ghost'])->get_error_code());

        // delete.
        $this->assertTrue($service->delete('a-prod', 'a-stage')['deleted']);
        $this->assertNull($service->get('a-prod', 'a-stage'));
        $this->assertSame('dbvc_agency_sync_policy_not_found', $service->delete('a-prod', 'a-stage')->get_error_code());
    }

    public function test_sync_now_requires_an_enabled_non_manual_policy(): void
    {
        $driver = new SyncDriver();
        $service = new SyncPolicyService();

        $this->assertSame('dbvc_agency_sync_policy_missing', $driver->sync_now('a-prod', 'a-stage')->get_error_code());

        $service->set(['source' => 'a-prod', 'target' => 'a-stage', 'mode' => 'manual']);
        $this->assertSame('dbvc_agency_sync_mode_manual', $driver->sync_now('a-prod', 'a-stage')->get_error_code());

        $service->set(['source' => 'a-prod', 'target' => 'a-stage', 'mode' => 'assisted', 'enabled' => false]);
        $this->assertSame('dbvc_agency_sync_policy_disabled', $driver->sync_now('a-prod', 'a-stage')->get_error_code());
    }

    public function test_assisted_sync_now_proposes_and_creates_a_release_for_outgoing_only(): void
    {
        // Shared baseline, then the source edits class-x (outgoing) and the target edits class-y (incoming).
        $this->report('a-prod', [$this->class('class-x', 'x1'), $this->class('class-y', 'y1'), $this->order('o1')]);
        $this->report('a-stage', [$this->class('class-x', 'x1'), $this->class('class-y', 'y1'), $this->order('o1')]);
        $this->assertSame(3, (new BaselineService())->confirm('a-prod', 'a-stage', null, null, 'initial')['confirmed']);
        $this->report('a-prod', [$this->class('class-x', 'x2')]);
        $this->report('a-stage', [$this->class('class-y', 'y2')]);

        (new SyncPolicyService())->set(['source' => 'a-prod', 'target' => 'a-stage', 'mode' => 'assisted']);
        $driver = new SyncDriver();

        // Dry run previews the proposal without creating anything.
        $preview = $driver->sync_now('a-prod', 'a-stage', true);
        $this->assertIsArray($preview, is_wp_error($preview) ? $preview->get_error_message() : '');
        $this->assertSame(1, $preview['counts']['outgoing']);
        $this->assertSame(1, $preview['counts']['incoming']);
        $this->assertSame(1, $preview['proposed_count'], 'Only the outgoing class-x is proposed.');
        $this->assertSame('class-x', $preview['proposed'][0]['instance_uid']);
        $this->assertSame('replace', $preview['proposed'][0]['operation']);
        $this->assertNull($preview['release'], 'A dry run creates no release.');
        $this->assertSame('dry_run', $preview['note']);

        // Real run creates an OPEN release for class-x (it seals once the source connector delivers payloads).
        $result = $driver->sync_now('a-prod', 'a-stage', false);
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertIsArray($result['release']);
        $this->assertSame('open', $result['release']['state']);
        $this->assertSame(1, (int) $result['release']['item_count']);
        $this->assertSame('release_open', $result['note']);

        // parity() is the read-only dashboard view.
        $parity = $driver->parity('a-prod', 'a-stage');
        $this->assertSame(1, $parity['counts']['outgoing']);
        $this->assertTrue($parity['dry_run']);
    }

    public function test_conflict_policy_caps_and_exclude_shape_the_proposal(): void
    {
        // Baseline class-x and class-y, then make class-x a conflict (both diverge) and class-y outgoing.
        $this->report('a-prod', [$this->class('class-x', 'x1'), $this->class('class-y', 'y1'), $this->order('o1')]);
        $this->report('a-stage', [$this->class('class-x', 'x1'), $this->class('class-y', 'y1'), $this->order('o1')]);
        (new BaselineService())->confirm('a-prod', 'a-stage', null, null, 'initial');
        $this->report('a-prod', [$this->class('class-x', 'x2'), $this->class('class-y', 'y2')]);
        $this->report('a-stage', [$this->class('class-x', 'x3')]);

        $service = new SyncPolicyService();
        $driver = new SyncDriver();

        // hold: class-x conflict is held, only class-y proposed.
        $service->set(['source' => 'a-prod', 'target' => 'a-stage', 'mode' => 'assisted', 'conflict_policy' => 'hold']);
        $hold = $driver->sync_now('a-prod', 'a-stage', true);
        $this->assertSame(['class-y'], array_column($hold['proposed'], 'instance_uid'));
        $this->assertGreaterThanOrEqual(1, $hold['skipped']['held_conflict']);

        // source_wins: the conflict is proposed too (still fingerprint-guarded on apply).
        $service->set(['source' => 'a-prod', 'target' => 'a-stage', 'mode' => 'assisted', 'conflict_policy' => 'source_wins']);
        $wins = $driver->sync_now('a-prod', 'a-stage', true);
        $this->assertSame(['class-x', 'class-y'], array_column($wins['proposed'], 'instance_uid'));

        // caps: max_objects trims the proposal and reports the remainder.
        $service->set(['source' => 'a-prod', 'target' => 'a-stage', 'mode' => 'assisted', 'conflict_policy' => 'source_wins', 'max_objects' => 1]);
        $capped = $driver->sync_now('a-prod', 'a-stage', true);
        $this->assertSame(1, $capped['proposed_count']);
        $this->assertSame(1, $capped['skipped']['capped']);

        // exclude drops a specific object.
        $service->set(['source' => 'a-prod', 'target' => 'a-stage', 'mode' => 'assisted', 'conflict_policy' => 'source_wins', 'max_objects' => 25, 'exclude_uids' => ['class-x']]);
        $excluded = $driver->sync_now('a-prod', 'a-stage', true);
        $this->assertSame(['class-y'], array_column($excluded['proposed'], 'instance_uid'));

        // scope limits the domains considered (only wp.service in scope → nothing bricks proposed).
        $service->set(['source' => 'a-prod', 'target' => 'a-stage', 'mode' => 'assisted', 'conflict_policy' => 'source_wins', 'scope_domains' => ['wp.service']]);
        $scoped = $driver->sync_now('a-prod', 'a-stage', true);
        $this->assertSame([], $scoped['proposed'], 'Out-of-scope domains are never proposed.');
    }

    public function test_approved_override_on_the_target_is_never_pushed_even_under_source_wins(): void
    {
        // Baseline class-x and class-y; then the source diverges both and the target
        // diverges class-x -> class-x is a CONFLICT, class-y is OUTGOING.
        $this->report('a-prod', [$this->class('class-x', 'x1'), $this->class('class-y', 'y1'), $this->order('o1')]);
        $this->report('a-stage', [$this->class('class-x', 'x1'), $this->class('class-y', 'y1'), $this->order('o1')]);
        (new BaselineService())->confirm('a-prod', 'a-stage', null, null, 'initial');
        $this->report('a-prod', [$this->class('class-x', 'x2'), $this->class('class-y', 'y2')]);
        $this->report('a-stage', [$this->class('class-x', 'x3')]);

        $service = new SyncPolicyService();
        $driver = new SyncDriver();
        // source_wins would otherwise overwrite the conflicted class-x on the target.
        $service->set(['source' => 'a-prod', 'target' => 'a-stage', 'mode' => 'assisted', 'conflict_policy' => 'source_wins']);

        // Control: source_wins proposes the conflicted class-x and the outgoing class-y.
        $before = $driver->sync_now('a-prod', 'a-stage', true);
        $this->assertSame(['class-x', 'class-y'], array_column($before['proposed'], 'instance_uid'));

        // Lock class-x on the TARGET (a-stage): an approved client override.
        $this->assertTrue((new OverrideStore())->approve([
            'agency_id' => 'studio',
            'client_id' => 'client-a',
            'environment_id' => 'a-stage',
            'domain' => 'bricks.global_class',
            'instance_uid' => 'class-x',
            'definition_uid' => 'fw-buttons',
            'approved_hash' => $this->hash('locked'),
            'rationale' => 'client brand colours',
        ]));

        // The locked object is no longer proposed, even though conflict_policy=source_wins.
        $after = $driver->sync_now('a-prod', 'a-stage', true);
        $this->assertSame(['class-y'], array_column($after['proposed'], 'instance_uid'), 'A locked object is never pushed, even under source_wins.');
        $this->assertSame(1, $after['skipped']['approved_override']);

        // The Compare view annotates each row with the target's lock state (drives the badge + inline lock/unlock).
        $compare = \DBVC_Agency_CLI_Inspector::compare(['source' => 'a-prod', 'target' => 'a-stage', 'domain' => 'bricks.global_class']);
        $compare_rows = [];
        foreach ($compare['rows'] as $compare_row) {
            $compare_rows[$compare_row['source_instance_uid']] = $compare_row;
        }
        $this->assertSame('approved', $compare_rows['class-x']['target_override'], 'Compare marks the locked object.');
        $this->assertSame('fw-buttons', $compare_rows['class-x']['target_override_definition']);
        $this->assertSame('', $compare_rows['class-y']['target_override'], 'An unlocked object carries no override.');

        $override = (new OverrideStore())->find('a-stage', 'bricks.global_class', 'class-x', 'fw-buttons');
        $this->assertNotNull($override);

        // needs_rebase_review still takes precedence (precedence, but flagged — never a silent overwrite).
        (new OverrideStore())->set_state($override['override_id'], OverrideStore::STATE_NEEDS_REBASE_REVIEW);
        $rebase = $driver->sync_now('a-prod', 'a-stage', true);
        $this->assertSame(['class-y'], array_column($rebase['proposed'], 'instance_uid'), 'needs_rebase_review still blocks the push.');
        $this->assertSame(1, $rebase['skipped']['approved_override']);

        // Unlock (detach) -> the object rejoins parity and is proposed again on the next push.
        (new OverrideStore())->set_state($override['override_id'], OverrideStore::STATE_DETACHED);
        $unlocked = $driver->sync_now('a-prod', 'a-stage', true);
        $this->assertSame(['class-x', 'class-y'], array_column($unlocked['proposed'], 'instance_uid'), 'A detached override no longer blocks.');
        $this->assertSame(0, $unlocked['skipped']['approved_override']);
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
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
                'observed_at' => '2026-09-24T10:00:00Z',
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

    /**
     * @return array<string, mixed>
     */
    private function class(string $uid, string $seed): array
    {
        return ['object' => ['domain' => 'bricks.global_class', 'instance_uid' => $uid], 'projection' => ['profile' => 'bricks-global-class-v1', 'hash' => $this->hash($seed), 'exists' => true, 'complete' => true]];
    }

    /**
     * @return array<string, mixed>
     */
    private function order(string $seed): array
    {
        return ['object' => ['domain' => 'bricks.global_class', 'instance_uid' => \Dbvc\AgencyControl\Comparison\ComparisonService::ORDER_INSTANCE_UID], 'projection' => ['profile' => 'bricks-collection-order-v1', 'hash' => $this->hash($seed), 'exists' => true, 'complete' => true]];
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
