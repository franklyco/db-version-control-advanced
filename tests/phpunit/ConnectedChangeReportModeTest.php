<?php

use Dbvc\AgencyControl\Comparison\BaselineService;
use Dbvc\AgencyControl\Comparison\ComparisonService;
use Dbvc\AgencyControl\Enrollment\EnrollmentService;
use Dbvc\AgencyControl\Governance\FreezeService;
use Dbvc\AgencyControl\Parity\ParityScheduler;
use Dbvc\AgencyControl\Parity\SyncDriver;
use Dbvc\AgencyControl\Parity\SyncPolicyService;
use Dbvc\AgencyControl\Storage\ApprovalStore;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\PreparationStore as HubPreparationStore;
use Dbvc\AgencyControl\Storage\ReleaseStore;
use Dbvc\AgencyControl\Storage\RolloutStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\AgencyControl\Storage\SyncPolicyStore;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * Change report CR-2: the `report` sync-policy mode. Tracked like auto on the
 * parity tick, but the cycle ends at the target's dry run — a report-purpose
 * release is proposed, sealed by the source, prepared on the target, and the
 * receipt is handed to the report sink; nothing is approved or applied.
 * Selection follows D4, a payload mismatch withdraws and re-proposes (D5), an
 * unchanged comparison or a stale side creates nothing (D12).
 *
 * @group connected-environments
 */
class ConnectedChangeReportModeTest extends WP_UnitTestCase
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private $env = [];

    /**
     * @var int
     */
    private $sequence = 0;

    /**
     * @var array<int, array<string, mixed>>
     */
    private $sink = [];

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
        $this->env['src'] = $this->enroll('client-a', 'rep-src');
        $this->env['tgt'] = $this->enroll('client-a', 'rep-tgt');
        $this->sink = [];
        add_action('dbvc_agency_report_cycle_complete', [$this, 'capture_cycle'], 10, 4);
    }

    public function tear_down(): void
    {
        remove_action('dbvc_agency_report_cycle_complete', [$this, 'capture_cycle'], 10);
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

    /**
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $release
     * @param array<string, mixed> $preparation
     */
    public function capture_cycle(array $policy, array $release, array $preparation, string $note): void
    {
        $this->sink[] = ['policy' => $policy, 'release' => $release, 'preparation' => $preparation, 'note' => $note];
    }

    public function test_schema_v21_carries_the_report_columns_and_the_mode_is_accepted(): void
    {
        $this->assertGreaterThanOrEqual(21, HubSchema::SCHEMA_VERSION, 'v21 introduced the report columns; later slices may bump further.');
        $this->assertSame(HubSchema::SCHEMA_VERSION, (int) get_option(HubSchema::OPTION_SCHEMA_VERSION));
        $this->assertContains('report', SyncPolicyStore::MODES);

        $service = new SyncPolicyService();
        $this->assertSame('dbvc_agency_invalid_mode', $service->set(['source' => 'rep-src', 'target' => 'rep-tgt', 'mode' => 'watch'])->get_error_code());
        $policy = $service->set(['source' => 'rep-src', 'target' => 'rep-tgt', 'mode' => 'report', 'cadence' => 'hourly']);
        $this->assertSame('report', $policy['mode']);
        $this->assertSame(50, $policy['max_objects'], 'Report mode transports more per cycle by default (D7).');
        $this->assertSame('', $policy['active_operation_id']);
        $this->assertSame('', $policy['last_comparison_digest']);
        $this->assertSame(0, $policy['mismatch_cycles']);
        $this->assertSame(25, $service->set(['source' => 'rep-src', 'target' => 'rep-tgt', 'mode' => 'auto'])['max_objects'], 'Other modes keep the 25 default.');
    }

    public function test_report_cycle_proposes_seals_prepares_and_hands_the_receipt_to_the_sink_without_applying(): void
    {
        // Shared baseline on class-x; then the source edits it (outgoing), trashes class-gone
        // (removal), and adds class-new the target never had (creation). The target edits
        // class-both after the baseline and so does the source (conflict).
        $this->report('src', [$this->klass('class-x', 'x1'), $this->klass('class-gone', 'g1'), $this->klass('class-both', 'b1'), $this->klass('class-local', 'l0'), $this->order('o1')]);
        $this->report('tgt', [$this->klass('class-x', 'x1'), $this->klass('class-gone', 'g1'), $this->klass('class-both', 'b1'), $this->klass('class-local', 'l0'), $this->order('o1')]);
        $this->assertSame(5, (new BaselineService())->confirm('rep-src', 'rep-tgt', null, null, 'initial')['confirmed']);
        $this->report('src', [$this->klass('class-x', 'x2'), $this->tombstone('class-gone'), $this->klass('class-new', 'n1'), $this->klass('class-both', 'b-src')]);
        // class-local: edited on the target only (incoming) → listed, never transported. class-only-here: the target has it, the source never did.
        $this->report('tgt', [$this->klass('class-both', 'b-tgt'), $this->klass('class-local', 'l1'), $this->klass('class-only-here', 'h1')]);

        // Apply-side flags are all off: report mode ignores them (D4).
        (new SyncPolicyService())->set(['source' => 'rep-src', 'target' => 'rep-tgt', 'mode' => 'report', 'cadence' => 'hourly']);
        $driver = new SyncDriver();

        // Dry run lists the plan without creating anything.
        $dry = $driver->sync_now('rep-src', 'rep-tgt', true);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame('report', $dry['mode']);
        $this->assertSame(['class-both' => 'replace', 'class-gone' => 'delete', 'class-new' => 'create', 'class-x' => 'replace'], array_column($dry['proposed'], 'operation', 'instance_uid'));
        $this->assertSame(1, $dry['skipped']['local_only'], 'class-local changed on the target only: listed, not transported.');
        $listed = array_column($dry['listed'], 'listed_as', 'target_instance_uid');
        $this->assertSame(['class-local' => 'local_only', 'class-only-here' => 'baseline_required'], $listed);
        $this->assertSame(1, $dry['skipped']['baseline_required']);
        $this->assertSame(0, (new ReleaseStore())->counts()['total'] ?? 0);

        // Tick 1: a report-purpose release, tracked on the policy, with the digest memo set.
        $t1 = $driver->advance('rep-src', 'rep-tgt');
        $this->assertSame('release_open', $t1['note'], wp_json_encode($t1));
        $this->assertSame(4, $t1['proposed_count']);
        $release_uid = $t1['release']['release_uid'];
        $release = (new ReleaseStore())->find($release_uid);
        $this->assertSame(ReleaseStore::PURPOSE_REPORT, $release['purpose']);
        $this->assertSame('report:rep-src->rep-tgt', $release['note']);
        $policy = $this->policy();
        $this->assertSame($release_uid, $policy['active_release_uid']);
        $this->assertSame('', $policy['active_rollout_uid'], 'Report mode never creates a rollout.');
        $this->assertNotSame('', $policy['last_comparison_digest']);
        $this->assertSame($t1['comparison_digest'], $policy['last_comparison_digest']);

        // Still open: waiting for the source to deliver payloads.
        $this->assertSame('awaiting_payloads', $driver->advance('rep-src', 'rep-tgt')['note']);
        $this->seal($release_uid, [
            ['domain' => 'bricks.global_class', 'instance_uid' => 'class-x', 'profile' => 'bricks-global-class-v1', 'body' => $this->klass_body('x2')],
            ['domain' => 'bricks.global_class', 'instance_uid' => 'class-both', 'profile' => 'bricks-global-class-v1', 'body' => $this->klass_body('b-src')],
            ['domain' => 'bricks.global_class', 'instance_uid' => 'class-new', 'profile' => 'bricks-global-class-v1', 'body' => $this->klass_body('n1')],
            // A deletion's payload is the canonical absence (`null`), as the connector sends it.
            ['domain' => 'bricks.global_class', 'instance_uid' => 'class-gone', 'profile' => 'bricks-global-class-v1', 'body' => Canonicalizer::encode(null)],
        ]);

        // Tick 2: sealed → a prepare request for the target (no rollout, no approval).
        $t2 = $driver->advance('rep-src', 'rep-tgt');
        $this->assertSame('prepare_requested', $t2['note'], wp_json_encode($t2));
        $operation_id = $t2['operation_id'];
        $this->assertSame($operation_id, $this->policy()['active_operation_id']);
        $this->assertSame(0, (new RolloutStore())->counts()['total'] ?? 0, 'No rollout was created.');
        $this->assertSame([], (new ApprovalStore())->all(null, 10), 'Nothing is approved.');
        $preparation = (new HubPreparationStore())->find($operation_id);
        $this->assertSame(HubPreparationStore::STATE_REQUESTED, $preparation['state']);
        $this->assertSame($release_uid, $preparation['release_uid']);
        $this->assertSame('rep-tgt', $preparation['target_environment_id']);

        // The target has not answered yet: the policy stays in flight and the scheduler keeps driving it.
        $this->assertSame('awaiting_receipt', $driver->advance('rep-src', 'rep-tgt')['note']);
        $tick = ParityScheduler::tick();
        $this->assertSame(1, $tick['ran'], 'An in-flight report policy is advanced by the parity tick.');
        $this->assertSame('awaiting_receipt', $tick['results'][0]['outcome']);

        // The target's receipt lands → the cycle completes into the sink and clears.
        $this->deliver_receipt($operation_id);
        $this->assertSame([], $this->sink);
        $t3 = $driver->advance('rep-src', 'rep-tgt');
        $this->assertSame('report_ready', $t3['note'], wp_json_encode($t3));
        $this->assertSame('ready', $t3['receipt_outcome']);
        $this->assertCount(1, $this->sink);
        $this->assertSame('report_ready', $this->sink[0]['note']);
        $this->assertSame($release_uid, $this->sink[0]['release']['release_uid']);
        $this->assertSame($operation_id, $this->sink[0]['preparation']['operation_id']);
        $this->assertSame('ready', $this->sink[0]['preparation']['receipt']['outcome']);
        $policy = $this->policy();
        $this->assertSame('', $policy['active_release_uid']);
        $this->assertSame('', $policy['active_operation_id']);
        $this->assertSame('report_ready:' . $operation_id, $policy['last_run_note']);
        $this->assertSame(ReleaseStore::STATE_SEALED, (new ReleaseStore())->find($release_uid)['state'], 'The report release is left sealed for CR-3 to read and purge; it is never approved.');

        // Nothing changed since: the next tick creates nothing (digest memo).
        $t4 = $driver->advance('rep-src', 'rep-tgt');
        $this->assertSame('no_change', $t4['note'], wp_json_encode($t4));
        $this->assertSame(1, (new ReleaseStore())->counts()['total'] ?? 1);

        // A new staging edit changes the comparison → a fresh cycle starts.
        $this->report('src', [$this->klass('class-x', 'x3')]);
        $t5 = $driver->advance('rep-src', 'rep-tgt');
        $this->assertSame('release_open', $t5['note'], wp_json_encode($t5));
        $this->assertNotSame($release_uid, $t5['release']['release_uid']);
    }

    public function test_payload_mismatch_withdraws_and_reproposes_until_unstable_source(): void
    {
        $this->report('src', [$this->klass('class-x', 'x1'), $this->order('o1')]);
        $this->report('tgt', [$this->klass('class-x', 'x1'), $this->order('o1')]);
        (new BaselineService())->confirm('rep-src', 'rep-tgt', null, null, 'initial');
        (new SyncPolicyService())->set(['source' => 'rep-src', 'target' => 'rep-tgt', 'mode' => 'report']);
        $driver = new SyncDriver();
        $unstable = [];
        add_action('dbvc_agency_report_unstable_source', static function ($policy, $release, $cycles) use (&$unstable) {
            $unstable[] = $cycles;
        }, 10, 3);

        for ($cycle = 1; $cycle <= SyncDriver::REPORT_MISMATCH_LIMIT; $cycle++) {
            // The source keeps editing between propose and upload: every upload mismatches the manifest hash.
            $this->report('src', [$this->klass('class-x', 'edit-' . $cycle)]);
            $t = $driver->advance('rep-src', 'rep-tgt');
            $this->assertSame('release_open', $t['note'], 'cycle ' . $cycle . ': ' . wp_json_encode($t));
            $release_uid = $t['release']['release_uid'];
            $response = $this->as_environment($this->env['src'], 'POST', '/dbvc-agency/v1/releases/' . $release_uid . '/payloads', ['items' => [
                ['domain' => 'bricks.global_class', 'instance_uid' => 'class-x', 'profile' => 'bricks-global-class-v1', 'body' => $this->klass_body('something-else')],
            ]]);
            $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
            $this->assertFalse($response->get_data()['sealed']);

            $withdrawn = $driver->advance('rep-src', 'rep-tgt');
            $expected = $cycle < SyncDriver::REPORT_MISMATCH_LIMIT ? 'mismatch_withdrawn' : 'unstable_source';
            $this->assertSame($expected, $withdrawn['note'], 'cycle ' . $cycle . ': ' . wp_json_encode($withdrawn));
            $this->assertSame($cycle, $withdrawn['mismatch_cycles']);
            $this->assertSame(ReleaseStore::STATE_WITHDRAWN, (new ReleaseStore())->find($release_uid)['state'], 'The stuck release is withdrawn, never left open.');
            $this->assertSame('', $this->policy()['active_release_uid']);
            $this->assertSame($cycle, $this->policy()['mismatch_cycles']);
        }
        $this->assertSame([SyncDriver::REPORT_MISMATCH_LIMIT], $unstable, 'unstable_source fires once the limit is reached.');

        // A clean cycle resets the counter.
        $t = $driver->advance('rep-src', 'rep-tgt');
        $this->assertSame('release_open', $t['note']);
        $this->seal($t['release']['release_uid'], [['domain' => 'bricks.global_class', 'instance_uid' => 'class-x', 'profile' => 'bricks-global-class-v1', 'body' => $this->klass_body('edit-3')]]);
        $prepared = $driver->advance('rep-src', 'rep-tgt');
        $this->assertSame('prepare_requested', $prepared['note']);
        $this->deliver_receipt($prepared['operation_id']);
        $this->assertSame('report_ready', $driver->advance('rep-src', 'rep-tgt')['note']);
        $this->assertSame(0, $this->policy()['mismatch_cycles']);
    }

    public function test_stale_side_freeze_and_expiry_are_honest_states(): void
    {
        $this->report('src', [$this->klass('class-x', 'x1'), $this->order('o1')]);
        $this->report('tgt', [$this->klass('class-x', 'x1'), $this->order('o1')]);
        (new BaselineService())->confirm('rep-src', 'rep-tgt', null, null, 'initial');
        $this->report('src', [$this->klass('class-x', 'x2')]);
        (new SyncPolicyService())->set(['source' => 'rep-src', 'target' => 'rep-tgt', 'mode' => 'report']);
        $driver = new SyncDriver();

        // A stale target: nothing is created, the state names the stale side (D12),
        // and the CR-4 action names the side with the comparison (so the notifier can
        // key the episode on the silent side's last_contact_at).
        $stale_fired = [];
        add_action('dbvc_agency_report_stale', static function ($policy, $side, $comparison) use (&$stale_fired) {
            $stale_fired[] = [$policy['target_environment_id'], $side, (string) ($comparison[$side]['last_contact_at'] ?? '')];
        }, 10, 3);
        $this->age_contact('rep-tgt');
        $stale = $driver->advance('rep-src', 'rep-tgt');
        $this->assertSame('stale_target', $stale['note'], wp_json_encode($stale));
        $this->assertNotSame('', $stale['target_last_contact_at']);
        $this->assertSame([['rep-tgt', 'target', $stale['target_last_contact_at']]], $stale_fired, 'dbvc_agency_report_stale fires with the stale side and its last contact.');
        remove_all_actions('dbvc_agency_report_stale');
        $this->assertSame(0, (new ReleaseStore())->counts()['total'] ?? 0);
        $this->assertSame('', $this->policy()['active_release_uid']);
        $this->touch_contact('rep-tgt');

        // A frozen target: the sealed release waits; the prepare is retried after the window.
        $t = $driver->advance('rep-src', 'rep-tgt');
        $this->assertSame('release_open', $t['note'], wp_json_encode($t));
        $release_uid = $t['release']['release_uid'];
        $this->seal($release_uid, [['domain' => 'bricks.global_class', 'instance_uid' => 'class-x', 'profile' => 'bricks-global-class-v1', 'body' => $this->klass_body('x2')]]);
        $set = (new FreezeService())->set('rep-tgt', gmdate('Y-m-d H:i:s', time() - 60), gmdate('Y-m-d H:i:s', time() + 3600), 'maintenance');
        $this->assertIsArray($set, is_wp_error($set) ? $set->get_error_message() : '');
        $frozen = $driver->advance('rep-src', 'rep-tgt');
        $this->assertSame('frozen_target', $frozen['note'], wp_json_encode($frozen));
        $this->assertSame($release_uid, $this->policy()['active_release_uid'], 'The sealed release is kept for after the freeze.');
        $this->assertSame('', $this->policy()['active_operation_id']);
        (new FreezeService())->clear('rep-tgt');
        $prepared = $driver->advance('rep-src', 'rep-tgt');
        $this->assertSame('prepare_requested', $prepared['note'], wp_json_encode($prepared));

        // The target never answers inside the receipt window: the cycle closes as expired.
        $this->age_preparation($prepared['operation_id']);
        $expired = $driver->advance('rep-src', 'rep-tgt');
        $this->assertSame('report_expired', $expired['note'], wp_json_encode($expired));
        $this->assertCount(1, $this->sink);
        $this->assertSame('report_expired', $this->sink[0]['note']);
        $this->assertSame(HubPreparationStore::STATE_CANCELLED, $this->sink[0]['preparation']['state']);
        $this->assertSame('', $this->policy()['active_operation_id']);
        $this->assertSame('', $this->policy()['active_release_uid']);
    }

    public function test_scheduler_and_sync_now_treat_report_like_auto_and_assisted_stays_untouched(): void
    {
        $this->report('src', [$this->klass('class-x', 'x1')]);
        $this->report('tgt', [$this->klass('class-x', 'x1')]);
        (new BaselineService())->confirm('rep-src', 'rep-tgt', null, null, 'initial');
        $service = new SyncPolicyService();

        $service->set(['source' => 'rep-src', 'target' => 'rep-tgt', 'mode' => 'report', 'cadence' => 'hourly']);
        $tick = ParityScheduler::tick();
        $this->assertSame(1, $tick['ran'], wp_json_encode($tick));
        $this->assertSame('in_sync', $tick['results'][0]['outcome']);
        $this->assertSame('no_change', (new SyncDriver())->sync_now('rep-src', 'rep-tgt')['note'], 'sync-now drives a report policy through advance; the comparison is unchanged since the tick.');

        $service->set(['source' => 'rep-src', 'target' => 'rep-tgt', 'mode' => 'assisted', 'cadence' => 'hourly']);
        $this->assertSame(0, ParityScheduler::tick()['ran'], 'Assisted is still never cron-driven.');
        $this->assertSame('dbvc_agency_sync_mode_not_auto', (new SyncDriver())->advance('rep-src', 'rep-tgt')->get_error_code());
    }

    // --- Harness ---------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function policy(): array
    {
        return (new SyncPolicyStore())->find('rep-src', 'rep-tgt');
    }

    private function klass_body(string $seed): string
    {
        return Canonicalizer::encode(['id' => 'r-' . $seed, 'name' => '--c', 'settings' => ['_x' => $seed]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function klass(string $uid, string $seed): array
    {
        $body = $this->klass_body($seed);

        return ['object' => ['domain' => 'bricks.global_class', 'instance_uid' => $uid], 'projection' => ['profile' => 'bricks-global-class-v1', 'hash' => Canonicalizer::hash($body), 'exists' => true, 'complete' => true]];
    }

    /**
     * @return array<string, mixed>
     */
    private function tombstone(string $uid): array
    {
        return ['object' => ['domain' => 'bricks.global_class', 'instance_uid' => $uid], 'projection' => ['profile' => 'bricks-global-class-v1', 'hash' => ComparisonService::absent_hash(), 'exists' => false, 'complete' => true]];
    }

    /**
     * @return array<string, mixed>
     */
    private function order(string $seed): array
    {
        return ['object' => ['domain' => 'bricks.global_class', 'instance_uid' => ComparisonService::ORDER_INSTANCE_UID], 'projection' => ['profile' => 'bricks-collection-order-v1', 'hash' => hash('sha256', 'fixture:' . $seed), 'exists' => true, 'complete' => true]];
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
                'observed_at' => '2026-11-11T10:00:00Z',
            ]));
        }
        $response = $this->as_environment($env, 'POST', '/dbvc-agency/v1/observations', ['events' => $bodies]);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame(count($bodies), $response->get_data()['counts']['accepted'], wp_json_encode($response->get_data()['outcomes']));
    }

    /**
     * @param array<int, array<string, string>> $items
     */
    private function seal(string $release_uid, array $items): void
    {
        $response = $this->as_environment($this->env['src'], 'POST', '/dbvc-agency/v1/releases/' . $release_uid . '/payloads', ['items' => $items]);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertTrue($response->get_data()['sealed'], wp_json_encode($response->get_data()));
    }

    private function deliver_receipt(string $operation_id): void
    {
        $preparation = (new HubPreparationStore())->find($operation_id);
        $this->assertIsArray($preparation);
        $receipt = ['operation_id' => $operation_id, 'outcome' => 'ready', 'expires_at' => gmdate('c', time() + 3600), 'counts' => ['ready' => 1, 'noop' => 0, 'blocked' => 0], 'items' => []];
        $this->assertTrue((new HubPreparationStore())->record_receipt($preparation['preparation_id'], $receipt, str_repeat('a', 64), ['items_disagreeing_with_hub_projection' => 0]));
    }

    private function age_preparation(string $operation_id): void
    {
        global $wpdb;
        $wpdb->update(HubSchema::table('preparations'), ['requested_at' => gmdate('Y-m-d H:i:s', time() - Protocol::PREPARE_RECEIPT_TTL_SECONDS - 120)], ['operation_id' => $operation_id]);
    }

    private function age_contact(string $environment_id): void
    {
        global $wpdb;
        $wpdb->update(HubSchema::table('environments'), ['last_contact_at' => gmdate('Y-m-d H:i:s', time() - ComparisonService::freshness_seconds() - 120), 'last_inbox_poll_at' => null], ['environment_id' => $environment_id]);
    }

    private function touch_contact(string $environment_id): void
    {
        global $wpdb;
        $wpdb->update(HubSchema::table('environments'), ['last_contact_at' => gmdate('Y-m-d H:i:s')], ['environment_id' => $environment_id]);
    }

    /**
     * @param array<string, mixed> $env
     * @param array<string, mixed> $body
     */
    private function as_environment(array $env, string $method, string $route, array $body): WP_REST_Response
    {
        wp_set_current_user($env['user_id']);
        $GLOBALS['wp_rest_application_password_uuid'] = $env['uuid'];
        $request = new WP_REST_Request($method, $route);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
        $response = rest_do_request($request);
        wp_set_current_user(0);
        $GLOBALS['wp_rest_application_password_uuid'] = null;

        return $response;
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
