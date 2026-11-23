<?php

use Dbvc\AgencyControl\Comparison\BaselineService;
use Dbvc\AgencyControl\Comparison\ComparisonService;
use Dbvc\AgencyControl\Enrollment\EnrollmentService;
use Dbvc\AgencyControl\Parity\SyncDriver;
use Dbvc\AgencyControl\Parity\SyncPolicyService;
use Dbvc\AgencyControl\Reports\ChangeReportService;
use Dbvc\AgencyControl\Storage\ChangeReportStore;
use Dbvc\AgencyControl\Storage\PreparationStore as HubPreparationStore;
use Dbvc\AgencyControl\Storage\ReleaseStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * Change report CR-3: the durable report built from report-mode cycles — one
 * run row, one live item per object with plain-language labels and the
 * receipt's nested paths/values, decisions carried across runs and verified
 * on the next comparison, promotion into a real release, retention purge,
 * and the CLI/REST surfaces.
 *
 * @group connected-environments
 */
class ConnectedChangeReportTest extends WP_UnitTestCase
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
        ChangeReportService::register();
        $this->env['src'] = $this->enroll('client-a', 'cr-src');
        $this->env['tgt'] = $this->enroll('client-a', 'cr-tgt');
    }

    public function tear_down(): void
    {
        ChangeReportService::unregister();
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

    public function test_schema_v23_creates_the_report_tables(): void
    {
        $this->assertSame(23, HubSchema::SCHEMA_VERSION);
        $this->assertTrue(HubSchema::tables_exist());
        foreach (['change_reports', 'change_report_items', 'change_report_decisions', 'change_report_run_items'] as $name) {
            $this->assertStringEndsWith('dbvc_ac_' . $name, HubSchema::table($name));
        }
    }

    public function test_a_completed_cycle_builds_the_report_and_the_inbox_with_labels_paths_and_values(): void
    {
        $this->seed_pair();
        $driver = new SyncDriver();
        $release_uid = $this->run_cycle_to_receipt($driver);
        $done = $driver->advance('cr-src', 'cr-tgt');
        $this->assertSame('report_ready', $done['note'], wp_json_encode($done));

        $store = new ChangeReportStore();
        $report = $store->latest_report('cr-src', 'cr-tgt');
        $this->assertIsArray($report);
        $this->assertSame('ready', $report['state']);
        $this->assertSame($release_uid, $report['release_uid']);
        $this->assertTrue($report['values_included']);
        $this->assertSame(['created' => 1, 'updated' => 1, 'removed' => 1, 'conflict' => 1, 'local_only' => 1], array_intersect_key($report['counts'], array_flip(['created', 'updated', 'removed', 'conflict', 'local_only'])));
        $this->assertSame(5, $report['item_count']);

        $service = new ChangeReportService();
        $inbox = $service->inbox('cr-src', 'cr-tgt');
        $this->assertSame('5 changes on staging. 4 need a look. 1 just so you know.', $inbox['summary']['text']);
        $this->assertSame('report', $inbox['status']['mode']);
        $this->assertFalse($inbox['status']['in_flight']);
        $this->assertSame([], $inbox['handled']);
        $by_uid = [];
        foreach ($inbox['items'] as $item) {
            $by_uid[$item['instance_uid']] = $item;
        }
        $this->assertSame(['class-both', 'class-gone', 'class-local', 'class-new', 'class-x'], array_keys(array_intersect_key(array_flip(['class-both', 'class-gone', 'class-local', 'class-new', 'class-x']), $by_uid)) ?: array_keys($by_uid), wp_json_encode(array_keys($by_uid)));

        $x = $by_uid['class-x'];
        $this->assertSame('updated', $x['label']);
        $this->assertSame('Changed on staging', $x['label_text']);
        $this->assertSame('--class-x', $x['display_name'], 'Display name comes from the canonical body.');
        $this->assertSame('replace', $x['operation']);
        $this->assertSame('ready', $x['receipt_outcome']);
        $this->assertTrue($x['values_included']);
        $this->assertSame(1, $x['path_count']);
        $this->assertSame(['path' => 'settings._x', 'operation' => 'replace', 'before_value' => 'x1', 'after_value' => 'x2'], array_intersect_key($x['paths'][0], array_flip(['path', 'operation', 'before_value', 'after_value'])));
        $this->assertSame(Canonicalizer::hash($this->klass_body('x2', '--class-x')), $x['source_hash']);
        $this->assertSame(Canonicalizer::hash($this->klass_body('x1', '--class-x')), $x['target_hash']);
        $this->assertSame($x['target_hash'], $x['baseline_hash']);
        $this->assertTrue($x['needs_look']);

        $this->assertSame('removed', $by_uid['class-gone']['label']);
        $this->assertSame('delete', $by_uid['class-gone']['operation']);
        $this->assertSame('--class-gone', $by_uid['class-gone']['display_name'], 'A removed object is named from the target projection (match_key), not its uid (CR-4c).');
        $this->assertSame('--class-local', $by_uid['class-local']['display_name'], 'A hash-level (LocalWP-only) row is named from its projection too (CR-4c).');
        $this->assertSame('created', $by_uid['class-new']['label']);
        $this->assertSame('(absent)', $by_uid['class-new']['paths'][0]['before_value']);
        $this->assertSame('conflict', $by_uid['class-both']['label']);
        $this->assertSame('Changed on both sites', $by_uid['class-both']['label_text']);
        $this->assertNotSame($by_uid['class-both']['target_hash'], $by_uid['class-both']['baseline_hash'], 'A conflict shows the baseline beside both sides.');
        $this->assertSame('local_only', $by_uid['class-local']['label']);
        $this->assertFalse($by_uid['class-local']['needs_look']);
        $this->assertSame([], $by_uid['class-local']['paths'], 'LocalWP-only edits are listed hash-level only.');

        $markdown = ChangeReportService::render_markdown($inbox);
        $this->assertStringContainsString('## Changed on staging (1)', $markdown);
        $this->assertStringContainsString('- **--class-x** (bricks.global_class) · 1 path', $markdown);
        $this->assertStringContainsString('settings._x (replace): x1 → x2', $markdown);
        $this->assertStringContainsString('## Removed on staging (1)', $markdown);

        // The run list and the per-run view.
        $runs = DBVC_Agency_CLI_Inspector::change_reports(['source' => 'cr-src', 'target' => 'cr-tgt']);
        $this->assertSame(1, $runs['returned']);
        $this->assertSame($report['report_uid'], $runs['reports'][0]['report_uid']);
        $this->assertSame(1, $runs['reports'][0]['conflict']);
        $one = DBVC_Agency_CLI_Inspector::change_report(['source' => 'cr-src', 'target' => 'cr-tgt', 'report' => $report['report_uid'], 'format' => 'markdown']);
        $this->assertSame(5, $one['returned']);
        $this->assertStringContainsString('# Change report · cr-src → cr-tgt', $one['markdown']);

        // An unchanged next tick settles nothing new and creates nothing.
        $this->assertSame('no_change', $driver->advance('cr-src', 'cr-tgt')['note']);
        $this->assertSame(1, count($store->reports('cr-src', 'cr-tgt')));
    }

    public function test_decisions_are_carried_verified_reopened_and_promoted(): void
    {
        $this->seed_pair();
        $driver = new SyncDriver();
        $this->run_cycle_to_receipt($driver);
        $this->assertSame('report_ready', $driver->advance('cr-src', 'cr-tgt')['note']);
        $service = new ChangeReportService();
        $store = new ChangeReportStore();

        // Done on class-x, Sorted (with re-baseline) on the conflict, Leave it on class-new, Remind me tomorrow on class-gone.
        $decided = $service->decide(['source' => 'cr-src', 'target' => 'cr-tgt', 'items' => 'bricks.global_class:class-x', 'decision' => 'reconciled', 'note' => 'ported by hand']);
        $this->assertSame('decided', $decided['items'][0]['result'], wp_json_encode($decided));
        $sorted = $service->decide(['source' => 'cr-src', 'target' => 'cr-tgt', 'items' => ['bricks.global_class:class-both'], 'decision' => 'reconciled', 'rebaseline' => true]);
        $this->assertSame(0, $sorted['items'][0]['rebaselined'], 'The sides still differ when Sorted is pressed: the confirm lands at verification.');
        $this->assertTrue($sorted['items'][0]['decision']['rebaseline_object']);
        $not_conflict = $service->decide(['source' => 'cr-src', 'target' => 'cr-tgt', 'items' => 'bricks.global_class:class-new', 'decision' => 'ignored', 'rebaseline' => true]);
        $this->assertNull($not_conflict['items'][0]['rebaselined'], 'Re-baseline is ignored outside a conflict.');
        $service->decide(['source' => 'cr-src', 'target' => 'cr-tgt', 'items' => 'bricks.global_class:class-gone', 'decision' => 'snoozed']);
        $this->assertSame('dbvc_agency_invalid_decision', $service->decide(['source' => 'cr-src', 'target' => 'cr-tgt', 'items' => 'a:b', 'decision' => 'apply'])->get_error_code());
        $this->assertSame('not_found', $service->decide(['source' => 'cr-src', 'target' => 'cr-tgt', 'items' => 'bricks.global_class:nope', 'decision' => 'ignored'])['items'][0]['result']);

        $inbox = $service->inbox('cr-src', 'cr-tgt');
        $this->assertSame(['class-local'], array_column($inbox['items'], 'instance_uid'), 'Decided items leave the inbox.');
        $this->assertCount(4, $inbox['handled']);
        $this->assertSame('pending', $store->decision('cr-src', 'cr-tgt', 'bricks.global_class', 'class-x')['verify_state']);
        $this->assertSame('muted', $store->decision('cr-src', 'cr-tgt', 'bricks.global_class', 'class-new')['verify_state']);
        $this->assertSame('snoozed', $store->decision('cr-src', 'cr-tgt', 'bricks.global_class', 'class-gone')['verify_state']);

        // The operator ports class-x by hand (target now matches), staging edits class-new again, and the snooze comes due.
        $this->report('tgt', [$this->klass('class-x', 'x2')]);
        $this->report('src', [$this->klass('class-new', 'n2')]);
        $this->age_snooze('class-gone');
        $tick = $driver->advance('cr-src', 'cr-tgt');
        $this->assertSame('release_open', $tick['note'], 'class-new changed again, so a fresh cycle starts: ' . wp_json_encode($tick));

        $this->assertSame('verified', $store->decision('cr-src', 'cr-tgt', 'bricks.global_class', 'class-x')['verify_state'], 'Done is verified on the next comparison.');
        $this->assertSame('still_differs', $store->decision('cr-src', 'cr-tgt', 'bricks.global_class', 'class-both')['verify_state'], 'Sorted on an object that still differs reopens it.');
        $this->assertSame('changed_again', $store->decision('cr-src', 'cr-tgt', 'bricks.global_class', 'class-new')['verify_state']);
        $this->assertSame('due', $store->decision('cr-src', 'cr-tgt', 'bricks.global_class', 'class-gone')['verify_state']);
        $open = array_column($service->inbox('cr-src', 'cr-tgt')['items'], 'instance_uid');
        sort($open);
        $this->assertSame(['class-both', 'class-gone', 'class-local', 'class-new'], $open);
        $this->assertTrue($store->item('cr-src', 'cr-tgt', 'bricks.global_class', 'class-x')['resolved']);

        // The operator sorts the conflict by hand: once LocalWP matches staging, Sorted verifies and re-baselines the object.
        $this->report('tgt', [$this->klass('class-both', 'b-src')]);
        $verified = $service->verify('cr-src', 'cr-tgt', (new ComparisonService())->compare('cr-src', 'cr-tgt'));
        $this->assertSame(1, $verified['verified'], wp_json_encode($verified));
        $this->assertSame('verified', $store->decision('cr-src', 'cr-tgt', 'bricks.global_class', 'class-both')['verify_state']);
        $baselines = (new \Dbvc\AgencyControl\Storage\BaselineStore())->for_pair('cr-src', 'cr-tgt', 'bricks.global_class');
        $this->assertSame(Canonicalizer::hash($this->klass_body('b-src', '--class-both')), $baselines['bricks.global_class|class-both|bricks-global-class-v1']['baseline_hash'], 'The agreed starting point moved to the sorted value.');
        $this->assertTrue($store->item('cr-src', 'cr-tgt', 'bricks.global_class', 'class-both')['resolved']);

        // Clear reopens; promote creates a real release and parks the item.
        $service->decide(['source' => 'cr-src', 'target' => 'cr-tgt', 'items' => 'bricks.global_class:class-x', 'decision' => 'clear']);
        $this->assertNull($store->decision('cr-src', 'cr-tgt', 'bricks.global_class', 'class-x'));
        $this->assertFalse($store->item('cr-src', 'cr-tgt', 'bricks.global_class', 'class-x')['resolved']);
        $promoted = $service->promote(['source' => 'cr-src', 'target' => 'cr-tgt', 'items' => 'bricks.global_class:class-x']);
        $this->assertIsArray($promoted, is_wp_error($promoted) ? $promoted->get_error_message() : '');
        $this->assertSame(ReleaseStore::PURPOSE_RELEASE, (new ReleaseStore())->find($promoted['release']['release_uid'])['purpose']);
        $this->assertSame('promoted', $store->decision('cr-src', 'cr-tgt', 'bricks.global_class', 'class-x')['decision']);
        $this->assertSame($promoted['release']['release_uid'], $store->decision('cr-src', 'cr-tgt', 'bricks.global_class', 'class-x')['release_uid']);
        $this->assertTrue($store->item('cr-src', 'cr-tgt', 'bricks.global_class', 'class-x')['resolved']);
        $this->assertSame('dbvc_agency_invalid_selection', $service->promote(['source' => 'cr-src', 'target' => 'cr-tgt', 'items' => 'bricks.global_class:class-local'])->get_error_code(), 'A listed-only item has nothing to transport.');
    }

    public function test_purge_removes_report_releases_past_retention_and_keeps_the_report(): void
    {
        $this->seed_pair();
        $driver = new SyncDriver();
        $release_uid = $this->run_cycle_to_receipt($driver);
        $this->assertSame('report_ready', $driver->advance('cr-src', 'cr-tgt')['note']);
        $service = new ChangeReportService();

        $fresh = $service->purge(['dry_run' => true]);
        $this->assertSame(0, $fresh['purgeable'], 'Inside the retention window nothing is purgeable.');
        $this->age_release($release_uid, 8);
        $dry = $service->purge(['dry_run' => true]);
        $this->assertSame(1, $dry['purgeable']);
        $this->assertSame(0, $dry['purged']);
        $this->assertSame([$release_uid], $dry['release_uids']);
        $this->assertIsArray((new ReleaseStore())->find($release_uid));

        $purged = $service->purge();
        $this->assertSame(1, $purged['purged']);
        $this->assertGreaterThanOrEqual(1, $purged['preparations_deleted']);
        $this->assertNull((new ReleaseStore())->find($release_uid), 'Release and items are gone.');
        $this->assertSame([], (new HubPreparationStore())->all($release_uid, null, 10));
        $report = (new ChangeReportStore())->latest_report('cr-src', 'cr-tgt');
        $this->assertSame($release_uid, $report['release_uid'], 'The report row (and the live items) survive the purge.');
        $this->assertCount(5, $service->inbox('cr-src', 'cr-tgt')['items']);
    }

    public function test_rest_routes_expose_the_report_with_decide_gated_to_approvers(): void
    {
        $this->seed_pair();
        $driver = new SyncDriver();
        $this->run_cycle_to_receipt($driver);
        $this->assertSame('report_ready', $driver->advance('cr-src', 'cr-tgt')['note']);

        $admin = self::factory()->user->create(['role' => 'administrator']);
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);

        $read = $this->rest($admin, 'GET', '/dbvc/v1/agency/change-report', ['source' => 'cr-src', 'target' => 'cr-tgt']);
        $this->assertSame(200, $read->get_status(), wp_json_encode($read->get_data()));
        $this->assertCount(5, $read->get_data()['items']);
        $runs = $this->rest($admin, 'GET', '/dbvc/v1/agency/change-reports', ['source' => 'cr-src', 'target' => 'cr-tgt']);
        $this->assertSame(1, $runs->get_data()['returned']);

        $forbidden = $this->rest($subscriber, 'POST', '/dbvc/v1/agency/change-report-decide', ['source' => 'cr-src', 'target' => 'cr-tgt', 'items' => ['bricks.global_class:class-x'], 'decision' => 'reconciled']);
        $this->assertContains($forbidden->get_status(), [401, 403]);
        $decided = $this->rest($admin, 'POST', '/dbvc/v1/agency/change-report-decide', ['source' => 'cr-src', 'target' => 'cr-tgt', 'items' => ['bricks.global_class:class-x'], 'decision' => 'reconciled']);
        $this->assertSame(200, $decided->get_status(), wp_json_encode($decided->get_data()));
        $this->assertSame('decided', $decided->get_data()['items'][0]['result']);
        $this->assertCount(4, $this->rest($admin, 'GET', '/dbvc/v1/agency/change-report', ['source' => 'cr-src', 'target' => 'cr-tgt'])->get_data()['items']);
        $purge = $this->rest($admin, 'POST', '/dbvc/v1/agency/change-report-purge', ['dry_run' => true]);
        $this->assertSame(200, $purge->get_status());
        $this->assertTrue($purge->get_data()['dry_run']);
    }

    /**
     * CR-4e: the console's per-run view is a snapshot. The live items table re-stamps
     * an object with the last run that touched it, so an older run used to lose its
     * objects; now each run keeps how it saw every object it touched or settled,
     * decisions beside the rows stay live, pruning a report drops its snapshot, and
     * a run recorded before the snapshot table falls back to the live rows.
     */
    public function test_the_per_run_view_is_a_snapshot_later_runs_do_not_rewrite(): void
    {
        $this->seed_pair();
        $driver = new SyncDriver();
        $service = new ChangeReportService();
        $store = new ChangeReportStore();

        $this->run_cycle_to_receipt($driver);
        $this->assertSame('report_ready', $driver->advance('cr-src', 'cr-tgt')['note']);
        $run1 = $store->latest_report('cr-src', 'cr-tgt');
        $service->decide(['source' => 'cr-src', 'target' => 'cr-tgt', 'items' => 'bricks.global_class:class-new', 'decision' => 'ignored']);

        // Staging edits class-x again → run 2 re-touches the four transported objects; while
        // its receipt is in flight staging also matches LocalWP's class-local, so the completing
        // ingest settles that one as converged.
        $this->report('src', [$this->klass('class-x', 'x3')]);
        $this->run_cycle_to_receipt($driver, ['class-x' => 'x3', 'class-both' => 'b-src', 'class-new' => 'n1']);
        $this->report('src', [$this->klass('class-local', 'l1')]);
        $this->assertSame('report_ready', $driver->advance('cr-src', 'cr-tgt')['note']);
        $run2 = $store->latest_report('cr-src', 'cr-tgt');
        $this->assertNotSame($run1['report_uid'], $run2['report_uid']);
        $this->assertSame(4, $run2['item_count']);
        $this->assertSame(1, $run2['counts']['converged']);

        // Run 1 still reads exactly as it was recorded: 5 objects, class-x at x2, class-local LocalWP-only.
        $view1 = $service->inbox('cr-src', 'cr-tgt', ['report_uid' => $run1['report_uid']]);
        $this->assertTrue($view1['run']['snapshot']);
        $rows1 = array_merge($view1['items'], $view1['handled']);
        $this->assertCount($run1['item_count'], $rows1, 'The run lists as many objects as it counted.');
        $by1 = array_column($rows1, null, 'instance_uid');
        $this->assertSame(Canonicalizer::hash($this->klass_body('x2', '--class-x')), $by1['class-x']['source_hash']);
        $this->assertSame('x2', $by1['class-x']['paths'][0]['after_value']);
        $this->assertSame('local_only', $by1['class-local']['label']);
        $this->assertFalse($by1['class-new']['resolved'], 'Resolved is as the run saw it…');
        $this->assertSame('ignored', $by1['class-new']['decision']['decision'], '…while the decision beside it is live.');
        $this->assertSame($run1['report_uid'], $by1['class-x']['report_uid']);

        // Run 2 reads its own view: class-x at x3, class-new hidden by the carried decision, class-local settled.
        $view2 = $service->inbox('cr-src', 'cr-tgt', ['report_uid' => $run2['report_uid']]);
        $rows2 = array_merge($view2['items'], $view2['handled']);
        $this->assertCount(5, $rows2, '4 touched + 1 settled as converged by this run');
        $by2 = array_column($rows2, null, 'instance_uid');
        $this->assertSame(Canonicalizer::hash($this->klass_body('x3', '--class-x')), $by2['class-x']['source_hash']);
        $this->assertSame('x3', $by2['class-x']['paths'][0]['after_value']);
        $this->assertTrue($by2['class-new']['resolved']);
        $this->assertSame('converged', $by2['class-local']['label']);
        $this->assertTrue($by2['class-local']['resolved']);
        $this->assertSame(Canonicalizer::hash($this->klass_body('x3', '--class-x')), $store->item('cr-src', 'cr-tgt', 'bricks.global_class', 'class-x')['source_hash'], 'The live row moved on.');
        $this->assertSame(['class-local'], array_column($store->items('cr-src', 'cr-tgt', ['report_uid' => $run1['report_uid']]), 'instance_uid'), 'Only the settled row is still stamped with run 1 — the live table alone can no longer list run 1.');

        // CLI and REST per-run views go through the same snapshot.
        $cli = DBVC_Agency_CLI_Inspector::change_report(['source' => 'cr-src', 'target' => 'cr-tgt', 'report' => $run1['report_uid'], 'format' => 'markdown']);
        $this->assertSame(5, $cli['returned'] + count($cli['handled']));
        $this->assertStringContainsString('x1 → x2', $cli['markdown']);
        $admin = self::factory()->user->create(['role' => 'administrator']);
        $rest = $this->rest($admin, 'GET', '/dbvc/v1/agency/change-report', ['source' => 'cr-src', 'target' => 'cr-tgt', 'report' => $run1['report_uid']])->get_data();
        $this->assertTrue($rest['run']['snapshot']);
        $this->assertCount(5, array_merge($rest['items'], $rest['handled']));

        // Pruning a report drops its snapshot; a run without one falls back to the live rows.
        $this->assertSame(1, $store->prune_reports('cr-src', 'cr-tgt', 1));
        $this->assertSame([], $store->run_items('cr-src', 'cr-tgt', $run1['report_uid']));
        $this->assertCount(5, $store->run_items('cr-src', 'cr-tgt', $run2['report_uid']));
        $this->assertSame(5, $store->delete_run_items($run2['report_uid']));
        $legacy = $service->inbox('cr-src', 'cr-tgt', ['report_uid' => $run2['report_uid']]);
        $this->assertFalse($legacy['run']['snapshot']);
        $this->assertCount(4, array_merge($legacy['items'], $legacy['handled']), 'Live rows stamped with run 2 (class-local was settled in place, not re-stamped).');
    }

    // --- Harness ---------------------------------------------------------

    /**
     * CR-4c live finding: the hash-level listing (LocalWP-only / unknown / no agreed
     * starting point) must respect the policy's scope / include / exclude exactly like
     * the transported selection does — a pair scoped to a few objects must not inbox
     * every unbaselined object of the fleet.
     */
    public function test_listed_only_rows_respect_the_policy_include_and_scope(): void
    {
        $this->seed_pair();
        // Target-only, never baselined → baseline_required; LocalWP-only edit → incoming. Both outside the include.
        $this->report('tgt', [$this->klass('class-stray', 's1')]);
        $set = (new SyncPolicyService())->set(['source' => 'cr-src', 'target' => 'cr-tgt', 'mode' => 'report', 'cadence' => 'hourly', 'scope_domains' => ['bricks.global_class'], 'include_uids' => ['class-x', 'class-gone', 'class-both', 'class-new']]);
        $this->assertIsArray($set, is_wp_error($set) ? $set->get_error_message() : 'policy');
        $driver = new SyncDriver();
        $this->run_cycle_to_receipt($driver);
        $done = $driver->advance('cr-src', 'cr-tgt');
        $this->assertSame('report_ready', $done['note'], wp_json_encode($done));

        $report = (new ChangeReportStore())->latest_report('cr-src', 'cr-tgt');
        $this->assertSame(4, $report['item_count']);
        $this->assertSame(0, $report['counts']['local_only']);
        $this->assertSame(0, $report['counts']['baseline_required']);
        $this->assertSame(0, $report['counts']['unknown']);
        $uids = array_column((new ChangeReportService())->inbox('cr-src', 'cr-tgt')['items'], 'instance_uid');
        sort($uids);
        $this->assertSame(['class-both', 'class-gone', 'class-new', 'class-x'], $uids, 'class-local and class-stray are outside the include and never ingested');
    }

    /**
     * CR-4c live finding: an object baselined as absent on the target
     * (baseline-confirm --accept-absent) and present on the source is `outgoing`,
     * but the target has no identity to replace — the report must propose a
     * creation (the guarded creation dry run), not a replace the target refuses.
     */
    public function test_an_outgoing_object_the_target_never_had_is_proposed_as_a_creation(): void
    {
        $this->report('src', [$this->klass('class-abs', 'a1'), $this->order('o1')]);
        $this->report('tgt', [$this->order('o1')]);
        $confirmed = (new BaselineService())->confirm('cr-src', 'cr-tgt', 'bricks.global_class', 'class-abs', 'absent on target', true);
        $this->assertSame(1, $confirmed['confirmed'], wp_json_encode($confirmed));
        (new SyncPolicyService())->set(['source' => 'cr-src', 'target' => 'cr-tgt', 'mode' => 'report', 'cadence' => 'hourly']);

        $t1 = (new SyncDriver())->advance('cr-src', 'cr-tgt');
        $this->assertSame('release_open', $t1['note'], wp_json_encode($t1));
        $this->assertSame([['domain' => 'bricks.global_class', 'instance_uid' => 'class-abs', 'operation' => 'create']], $t1['proposed']);
    }

    /**
     * Shared baseline on class-x / class-gone / class-both / class-local; then staging edits
     * class-x, trashes class-gone, adds class-new, edits class-both; LocalWP edits class-both
     * (conflict) and class-local (LocalWP-only). Policy: report, hourly.
     */
    private function seed_pair(): void
    {
        $this->report('src', [$this->klass('class-x', 'x1'), $this->klass('class-gone', 'g1'), $this->klass('class-both', 'b1'), $this->klass('class-local', 'l0'), $this->order('o1')]);
        $this->report('tgt', [$this->klass('class-x', 'x1'), $this->klass('class-gone', 'g1'), $this->klass('class-both', 'b1'), $this->klass('class-local', 'l0'), $this->order('o1')]);
        $this->assertSame(5, (new BaselineService())->confirm('cr-src', 'cr-tgt', null, null, 'initial')['confirmed']);
        $this->report('src', [$this->klass('class-x', 'x2'), $this->tombstone('class-gone'), $this->klass('class-new', 'n1'), $this->klass('class-both', 'b-src')]);
        $this->report('tgt', [$this->klass('class-both', 'b-tgt'), $this->klass('class-local', 'l1')]);
        (new SyncPolicyService())->set(['source' => 'cr-src', 'target' => 'cr-tgt', 'mode' => 'report', 'cadence' => 'hourly']);
    }

    /**
     * Drive one report cycle up to the delivered receipt (the next advance completes it).
     */
    private const CYCLE_SEEDS = ['class-x' => 'x2', 'class-both' => 'b-src', 'class-new' => 'n1'];

    /**
     * @param array<string, string> $seeds staging body seed per transported class (class-gone is always the removal)
     */
    private function run_cycle_to_receipt(SyncDriver $driver, array $seeds = self::CYCLE_SEEDS): string
    {
        $t1 = $driver->advance('cr-src', 'cr-tgt');
        $this->assertSame('release_open', $t1['note'], wp_json_encode($t1));
        $release_uid = (string) $t1['release']['release_uid'];
        $payloads = [['domain' => 'bricks.global_class', 'instance_uid' => 'class-gone', 'profile' => 'bricks-global-class-v1', 'body' => Canonicalizer::encode(null)]];
        foreach ($seeds as $uid => $seed) {
            $payloads[] = ['domain' => 'bricks.global_class', 'instance_uid' => $uid, 'profile' => 'bricks-global-class-v1', 'body' => $this->klass_body($seed, '--' . $uid)];
        }
        $this->seal($release_uid, $payloads);
        $t2 = $driver->advance('cr-src', 'cr-tgt');
        $this->assertSame('prepare_requested', $t2['note'], wp_json_encode($t2));
        $this->deliver_receipt((string) $t2['operation_id'], $seeds);

        return $release_uid;
    }

    /**
     * A receipt in the CR-1 shape: nested changed paths with both values (the target opted in).
     *
     * @param array<string, string> $seeds the transported classes (see run_cycle_to_receipt)
     */
    private function deliver_receipt(string $operation_id, array $seeds = self::CYCLE_SEEDS): void
    {
        $preparation = (new HubPreparationStore())->find($operation_id);
        $this->assertIsArray($preparation);
        $row = static function (string $path, string $op, ?string $before, ?string $after): array {
            return ['path' => $path, 'operation' => $op, 'before_hash' => $before === null ? null : Canonicalizer::hash(Canonicalizer::encode($before)), 'after_hash' => $after === null ? null : Canonicalizer::hash(Canonicalizer::encode($after)), 'before_value' => $before ?? '(absent)', 'after_value' => $after ?? '(absent)'];
        };
        $item = function (string $uid, string $operation, string $outcome, ?string $before_body, array $paths, string $strategy): array {
            return [
                'domain' => 'bricks.global_class', 'instance_uid' => $uid, 'target_instance_uid' => $uid, 'profile' => 'bricks-global-class-v1', 'operation' => $operation,
                'after_hash' => str_repeat('b', 64), 'before_hash' => $before_body === null ? ComparisonService::absent_hash() : Canonicalizer::hash($before_body),
                'outcome' => $outcome, 'blockers' => [], 'exists' => $before_body !== null, 'complete' => true,
                'patch' => ['strategy' => $strategy, 'changed_paths' => $paths, 'truncated' => false],
            ];
        };
        $receipt = [
            'operation_id' => $operation_id,
            'release_uid' => $preparation['release_uid'],
            'outcome' => 'ready',
            'prepared_at' => gmdate('c'),
            'expires_at' => gmdate('c', time() + 3600),
            'counts' => ['ready' => 4, 'noop' => 0, 'blocked' => 0],
            'values' => ['included' => true, 'omitted' => null],
            'items' => [
                $item('class-gone', 'delete', 'ready', $this->klass_body('g1', '--class-gone'), [], 'remove_object'),
            ],
        ];
        $targets = ['class-x' => 'x1', 'class-both' => 'b-tgt'];
        foreach ($seeds as $uid => $seed) {
            if (isset($targets[$uid])) {
                $receipt['items'][] = $item($uid, 'replace', 'ready', $this->klass_body($targets[$uid], '--' . $uid), [$row('settings._x', 'replace', $targets[$uid], $seed)], 'replace_object');
            } else {
                $receipt['items'][] = $item($uid, 'create', 'ready', null, [$row('id', 'add', null, 'r-' . $seed), $row('name', 'add', null, '--' . $uid), $row('settings._x', 'add', null, $seed)], 'add_object');
            }
        }
        $receipt['counts']['ready'] = count($receipt['items']);
        $notes = ['items_disagreeing_with_hub_projection' => 0, 'items' => []];
        $baselines = (new \Dbvc\AgencyControl\Storage\BaselineStore())->for_pair('cr-src', 'cr-tgt', null);
        foreach ($receipt['items'] as $entry) {
            $key = 'bricks.global_class|' . $entry['instance_uid'] . '|bricks-global-class-v1';
            $notes['items'][] = ['domain' => 'bricks.global_class', 'instance_uid' => $entry['instance_uid'], 'baseline_hash' => isset($baselines[$key]) ? (string) $baselines[$key]['baseline_hash'] : null];
        }
        $this->assertTrue((new HubPreparationStore())->record_receipt($preparation['preparation_id'], $receipt, Canonicalizer::hash(Canonicalizer::encode($receipt)), $notes));
    }

    private function age_release(string $release_uid, int $days): void
    {
        global $wpdb;
        $wpdb->update(HubSchema::table('releases'), ['created_at' => gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)], ['release_uid' => $release_uid]);
    }

    private function age_snooze(string $uid): void
    {
        global $wpdb;
        $wpdb->update(HubSchema::table('change_report_decisions'), ['snoozed_until' => gmdate('Y-m-d H:i:s', time() - 60)], ['instance_uid' => $uid]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function rest(int $user_id, string $method, string $route, array $params): WP_REST_Response
    {
        wp_set_current_user($user_id);
        $request = new WP_REST_Request($method, $route);
        if ($method === 'GET') {
            $request->set_query_params($params);
        } else {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(wp_json_encode($params));
        }
        $response = rest_do_request($request);
        wp_set_current_user(0);

        return $response;
    }

    private function klass_body(string $seed, string $name = '--c'): string
    {
        return Canonicalizer::encode(['id' => 'r-' . $seed, 'name' => $name, 'settings' => ['_x' => $seed]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function klass(string $uid, string $seed): array
    {
        $body = $this->klass_body($seed, '--' . $uid);

        return ['object' => ['domain' => 'bricks.global_class', 'instance_uid' => $uid, 'match_key' => '--' . $uid], 'projection' => ['profile' => 'bricks-global-class-v1', 'hash' => Canonicalizer::hash($body), 'exists' => true, 'complete' => true]];
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
