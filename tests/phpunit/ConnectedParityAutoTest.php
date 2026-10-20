<?php

use Dbvc\AgencyControl\Comparison\BaselineService;
use Dbvc\AgencyControl\Comparison\ComparisonService;
use Dbvc\AgencyControl\Enrollment\EnrollmentService;
use Dbvc\AgencyControl\Parity\SyncDriver;
use Dbvc\AgencyControl\Parity\SyncPolicyService;
use Dbvc\AgencyControl\Release\ApprovalService;
use Dbvc\AgencyControl\Storage\ApprovalStore;
use Dbvc\AgencyControl\Storage\BaselineStore;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\PreparationStore as HubPreparationStore;
use Dbvc\AgencyControl\Storage\RolloutStore;
use Dbvc\AgencyControl\Storage\SyncPolicyStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * M8 slice 4: auto-mode parity. The driver reconciles a pair one tick at a time
 * — propose an open release, wait for the source to seal it, roll it out through
 * the existing prepare → approve → guarded apply, and clear the cycle — and is
 * apply-gate-authoritative (it never completes a target that has not executed).
 * The connector side is simulated in-process (payload delivery, prepare +
 * execution receipts) exactly as the M6 rollout suite does.
 *
 * @group connected-environments
 */
class ConnectedParityAutoTest extends WP_UnitTestCase
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
        $this->env['src'] = $this->enroll('client-a', 'auto-src');
        $this->env['tgt'] = $this->enroll('client-a', 'auto-tgt');
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

    public function test_auto_advance_drives_a_full_cycle_and_clears_when_the_rollout_finishes(): void
    {
        // Shared baseline on class-x, then the source edits it → outgoing.
        $this->report('src', [$this->klass('class-x', 'x1'), $this->order('o1')]);
        $this->report('tgt', [$this->klass('class-x', 'x1'), $this->order('o1')]);
        $this->assertSame(2, (new BaselineService())->confirm('auto-src', 'auto-tgt', null, null, 'initial')['confirmed']);
        $this->report('src', [$this->klass('class-x', 'x2')]);

        (new SyncPolicyService())->set(['source' => 'auto-src', 'target' => 'auto-tgt', 'mode' => 'auto']);
        $driver = new SyncDriver();

        // Tick 1: propose → an open release for class-x, tracked on the policy.
        $t1 = $driver->advance('auto-src', 'auto-tgt');
        $this->assertSame('release_open', $t1['note'], wp_json_encode($t1));
        $this->assertSame(1, $t1['proposed_count']);
        $this->assertSame('class-x', $t1['proposed'][0]['instance_uid']);
        $release_uid = $t1['release']['release_uid'];
        $this->assertSame($release_uid, $this->policy()['active_release_uid']);

        // The source has not sealed it yet: the next tick waits.
        $this->assertSame('awaiting_payloads', $driver->advance('auto-src', 'auto-tgt')['note']);

        // Source delivers the payload → the release seals.
        $this->seal($release_uid, [['domain' => 'bricks.global_class', 'instance_uid' => 'class-x', 'profile' => 'bricks-global-class-v1', 'body' => $this->klass_body('x2')]]);

        // Tick 2: sealed → a rollout is created and driven (target now preparing).
        $t2 = $driver->advance('auto-src', 'auto-tgt');
        $this->assertSame('rollout_started', $t2['note'], wp_json_encode($t2));
        $rollout_uid = $this->policy()['active_rollout_uid'];
        $this->assertNotSame('', $rollout_uid);
        $this->assertSame(RolloutStore::TARGET_PREPARING, $this->target_state($rollout_uid, 'auto-tgt'));

        // The target reports its prepare receipt → next tick approves it.
        $this->deliver_prepare($rollout_uid, 'auto-tgt');
        $this->assertSame('rollout_running', $driver->advance('auto-src', 'auto-tgt')['note']);
        $this->assertSame(RolloutStore::TARGET_APPROVED, $this->target_state($rollout_uid, 'auto-tgt'));

        // The target executes (its own guarded apply) → the cycle completes and clears.
        $this->deliver_execution($rollout_uid, $this->env['tgt'], $release_uid, 'applied', [[
            'domain' => 'bricks.global_class',
            'instance_uid' => 'class-x',
            'target_instance_uid' => 'class-x',
            'profile' => 'bricks-global-class-v1',
            'operation' => 'replace',
            'outcome' => 'applied',
            'verified' => true,
            'before_hash' => Canonicalizer::hash($this->klass_body('x1')),
            'after_hash' => Canonicalizer::hash($this->klass_body('x2')),
        ]]);
        $done = $driver->advance('auto-src', 'auto-tgt');
        $this->assertSame('rollout_completed', $done['note'], wp_json_encode($done));
        $this->assertSame('', $this->policy()['active_release_uid'], 'The finished cycle is cleared.');
        $this->assertSame('', $this->policy()['active_rollout_uid']);

        // The hub baseline advanced to the applied hash, and there is nothing left to propose.
        $baselines = (new BaselineStore())->for_pair('auto-src', 'auto-tgt', 'bricks.global_class');
        $key = 'bricks.global_class|class-x|bricks-global-class-v1';
        $this->assertArrayHasKey($key, $baselines);
        $this->assertSame(Canonicalizer::hash($this->klass_body('x2')), $baselines[$key]['baseline_hash']);
        $this->assertSame('in_sync', $driver->advance('auto-src', 'auto-tgt')['note']);
    }

    public function test_auto_is_apply_gate_authoritative_and_waits_when_the_target_never_executes(): void
    {
        $this->report('src', [$this->klass('class-x', 'x1'), $this->order('o1')]);
        $this->report('tgt', [$this->klass('class-x', 'x1'), $this->order('o1')]);
        (new BaselineService())->confirm('auto-src', 'auto-tgt', null, null, 'initial');
        $this->report('src', [$this->klass('class-x', 'x2')]);

        (new SyncPolicyService())->set(['source' => 'auto-src', 'target' => 'auto-tgt', 'mode' => 'auto']);
        $driver = new SyncDriver();

        $release_uid = $driver->advance('auto-src', 'auto-tgt')['release']['release_uid'];
        $this->seal($release_uid, [['domain' => 'bricks.global_class', 'instance_uid' => 'class-x', 'profile' => 'bricks-global-class-v1', 'body' => $this->klass_body('x2')]]);
        $driver->advance('auto-src', 'auto-tgt'); // sealed → rollout created + driven
        $rollout_uid = $this->policy()['active_rollout_uid'];
        $this->deliver_prepare($rollout_uid, 'auto-tgt');
        $driver->advance('auto-src', 'auto-tgt'); // approve

        // The target never executes (e.g. its apply gate is off). Auto must not complete or clear.
        $waiting = $driver->advance('auto-src', 'auto-tgt');
        $this->assertSame('rollout_running', $waiting['note']);
        $this->assertSame($rollout_uid, $this->policy()['active_rollout_uid'], 'Auto keeps waiting; it never forces the apply.');
        $this->assertSame(RolloutStore::TARGET_APPROVED, $this->target_state($rollout_uid, 'auto-tgt'));

        // The baseline never advanced (still the confirmed x1 hash); the object is still outgoing.
        $baselines = (new BaselineStore())->for_pair('auto-src', 'auto-tgt', 'bricks.global_class');
        $this->assertSame(Canonicalizer::hash($this->klass_body('x1')), $baselines['bricks.global_class|class-x|bricks-global-class-v1']['baseline_hash']);
        $rows = [];
        foreach ((new ComparisonService())->compare('auto-src', 'auto-tgt')['rows'] as $row) {
            $rows[$row['source_instance_uid']] = $row['state'];
        }
        $this->assertSame('outgoing', $rows['class-x']);
    }

    public function test_deletion_and_new_object_operations_follow_the_policy(): void
    {
        // Baseline class-x and class-y, then the source deletes class-y and adds class-z.
        $this->report('src', [$this->klass('class-x', 'x1'), $this->klass('class-y', 'y1'), $this->order('o1')]);
        $this->report('tgt', [$this->klass('class-x', 'x1'), $this->klass('class-y', 'y1'), $this->order('o1')]);
        (new BaselineService())->confirm('auto-src', 'auto-tgt', null, null, 'initial');
        $this->report('src', [$this->tombstone('class-y'), $this->klass('class-z', 'z1'), $this->order('o2')]);

        $service = new SyncPolicyService();
        $driver = new SyncDriver();

        // Defaults: deletions and new objects are only reported as skipped, never proposed.
        $service->set(['source' => 'auto-src', 'target' => 'auto-tgt', 'mode' => 'assisted']);
        $off = $driver->sync_now('auto-src', 'auto-tgt', true);
        $this->assertSame([], array_column($off['proposed'], 'instance_uid'), 'No deletions/new objects by default.');
        $this->assertGreaterThanOrEqual(1, $off['skipped']['deletion']);
        $this->assertGreaterThanOrEqual(1, $off['skipped']['new_object']);

        // Enable both: class-y is proposed as a delete, class-z as a create.
        $service->set(['source' => 'auto-src', 'target' => 'auto-tgt', 'mode' => 'assisted', 'propagate_deletions' => true, 'create_new' => true]);
        $on = $driver->sync_now('auto-src', 'auto-tgt', true);
        $ops = [];
        foreach ($on['proposed'] as $row) {
            $ops[$row['instance_uid']] = $row['operation'];
        }
        $this->assertSame('delete', $ops['class-y'] ?? null, wp_json_encode($on['proposed']));
        $this->assertSame('create', $ops['class-z'] ?? null, 'A target-absent object is proposed as a guarded create.');
    }

    // --- Harness ---------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function policy(): array
    {
        return (new SyncPolicyStore())->find('auto-src', 'auto-tgt');
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
                'observed_at' => '2026-09-25T10:00:00Z',
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

    private function deliver_prepare(string $rollout_uid, string $environment_id): void
    {
        $target = $this->target_row($rollout_uid, $environment_id);
        $preparation = (new HubPreparationStore())->find($target['operation_id']);
        $this->assertIsArray($preparation);
        $receipt = ['operation_id' => $target['operation_id'], 'outcome' => 'ready', 'expires_at' => gmdate('c', time() + 3600), 'counts' => ['ready' => 1, 'noop' => 0, 'blocked' => 0], 'items' => []];
        $this->assertTrue((new HubPreparationStore())->record_receipt($preparation['preparation_id'], $receipt, str_repeat('a', 64), ['items_disagreeing_with_hub_projection' => 0]));
    }

    /**
     * @param array<string, mixed>            $env
     * @param array<int, array<string, mixed>> $items Per-item execution outcomes (empty = none, so no baseline advances).
     */
    private function deliver_execution(string $rollout_uid, array $env, string $release_uid, string $outcome, array $items = []): void
    {
        $target = $this->target_row($rollout_uid, $env['environment_id']);
        $approval = (new ApprovalStore())->find($target['approval_uid']);
        $this->assertIsArray($approval);
        $receipt = ['approval_uid' => $target['approval_uid'], 'operation_id' => $approval['operation_id'], 'release_digest' => $approval['release_digest'], 'receipt_digest' => $approval['receipt_digest'], 'target' => ['environment_id' => $env['environment_id'], 'epoch' => $env['epoch']], 'outcome' => $outcome, 'items' => $items];
        $result = (new ApprovalService())->accept_execution((new EnvironmentRegistry())->find($env['environment_id']), $release_uid, $receipt);
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function target_row(string $rollout_uid, string $environment_id): array
    {
        $store = new RolloutStore();
        $rollout = $store->find($rollout_uid);
        $this->assertIsArray($rollout);
        foreach ($store->targets($rollout['rollout_id']) as $target) {
            if ($target['target_environment_id'] === $environment_id) {
                return $target;
            }
        }
        $this->fail('No rollout target for ' . $environment_id . '.');
    }

    private function target_state(string $rollout_uid, string $environment_id): string
    {
        return (string) $this->target_row($rollout_uid, $environment_id)['state'];
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
