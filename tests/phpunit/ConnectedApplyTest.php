<?php

use Dbvc\AgencyControl\Comparison\ComparisonService;
use Dbvc\AgencyControl\Enrollment\EnrollmentService as HubEnrollment;
use Dbvc\AgencyControl\Enrollment\SubscriptionService;
use Dbvc\AgencyControl\Release\ApprovalService;
use Dbvc\AgencyControl\Release\PreparationService;
use Dbvc\AgencyControl\Release\ReleaseService;
use Dbvc\AgencyControl\Storage\ApprovalStore;
use Dbvc\AgencyControl\Storage\BaselineStore;
use Dbvc\AgencyControl\Storage\PreparationStore as HubPreparationStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\Connected\Adapters\BricksOptionCollectionObserver;
use Dbvc\Connected\Enrollment\EnrollmentService;
use Dbvc\Connected\Identity\InstanceIdentity;
use Dbvc\Connected\Storage\ObjectStore;
use Dbvc\Connected\Storage\OperationStore;
use Dbvc\Connected\Storage\OutboxStore;
use Dbvc\Connected\Storage\PreparationStore;
use Dbvc\Connected\Storage\Schema;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * M5 step 1: one guarded round trip — approval bound to release and receipt
 * digests, gated apply with a conditional write on the shared Bricks option
 * container, journalled before image, verified after-state, compensation,
 * stale detection, and baseline advancement for verified objects.
 *
 * @group connected-environments
 */
require_once __DIR__ . '/support/ConnectedLoopbackTrait.php';

class ConnectedApplyTest extends WP_UnitTestCase
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
        $this->transport_failure_predicate = null;

        delete_option(self::CLASSES);
        delete_option(Schema::OPTION_SCHEMA_VERSION);
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        Schema::reset_memo();
        HubSchema::reset_memo();
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        $GLOBALS['wp_rest_server'] = null;
        rest_get_server();
        update_option(self::CLASSES, $this->classes_fixture());
        $this->run_observation();
    }

    public function tear_down(): void
    {
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
        delete_option('bricks_global_classes_categories');
        delete_option('bricks_theme_styles');
        delete_option('bricks_components');
        delete_option(\Dbvc\Connected\Adapters\DomainRegistry::OPTION_BRICKS_DOMAINS);
        delete_option(DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED);
        \Dbvc\Connected\Adapters\DomainRegistry::reset_observers();
        Schema::reset_memo();
        HubSchema::reset_memo();
        parent::tear_down();
    }

    public function savepoint_strategy(): string
    {
        return 'savepoint';
    }

    public function test_apply_gate_is_off_by_default_and_never_implied_by_observation(): void
    {
        $this->assertSame('0', get_option(DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED, '0'));
        $this->assertFalse(DBVC_Connected_Environments_Addon::is_apply_enabled());
        $this->assertFalse(DBVC_Connected_Environments_Addon::runtime()->describe()['capabilities']['apply']);
        $this->assertContains(DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED, apply_filters('dbvc_excluded_option_keys', []), 'The apply gate never travels with an options export.');

        // Enabling apply without the connector is refused; disabling the connector clears it.
        $result = DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED => '1']);
        $this->assertSame('0', $result['values'][DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED]);
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1', DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED => '1']);
        $this->assertTrue(DBVC_Connected_Environments_Addon::is_apply_enabled());
        $this->assertTrue(DBVC_Connected_Environments_Addon::runtime()->describe()['capabilities']['apply']);
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        $this->assertFalse(DBVC_Connected_Environments_Addon::is_apply_enabled());
    }

    public function test_one_guarded_round_trip_applies_verifies_reports_and_advances_the_baseline(): void
    {
        $local = $this->enroll_local();
        $uids = $this->local_uids();
        $remote = $this->enroll_remote('client-a', 'studio-src');
        (new SubscriptionService())->subscribe_client('studio-src', $local['environment_id'], []);
        $local_aaa = (new ObjectStore())->get('bricks.global_class', $uids['aaa111'], 'bricks-global-class-v1');
        $after_aaa = json_decode($local_aaa['snapshot_body'], true);
        $after_aaa['settings']['_direction'] = 'row-reverse';
        $after_aaa_body = Canonicalizer::encode($after_aaa);
        $bodies = [
            ['domain' => 'bricks.global_class', 'instance_uid' => $uids['aaa111'], 'profile' => 'bricks-global-class-v1', 'body' => $after_aaa_body],
            ['domain' => 'bricks.global_class', 'instance_uid' => $uids['bbb222'], 'profile' => 'bricks-global-class-v1', 'body' => (new ObjectStore())->get('bricks.global_class', $uids['bbb222'], 'bricks-global-class-v1')['snapshot_body']],
        ];
        $this->report_and_seal($remote, $bodies, $release_uid);
        $preparations = new PreparationService();
        $approvals = new ApprovalService();

        // Approval needs a received, unexpired, ready/noop receipt; nothing else.
        $requested = $preparations->request($release_uid, $local['environment_id']);
        $this->assertSame('dbvc_agency_receipt_missing', $approvals->approve($requested['operation_id'])->get_error_code());
        $this->assertSame(1, $this->run_release()['receipts_stored']);
        $this->assertSame('ready', (new HubPreparationStore())->find($requested['operation_id'])['outcome']);

        // With the apply gate off, an approval is never retrieved or executed.
        $approval = $approvals->approve($requested['operation_id'], 'go');
        $this->assertIsArray($approval, is_wp_error($approval) ? $approval->get_error_message() : '');
        $this->assertSame(ApprovalStore::STATE_APPROVED, $approval['state']);
        $this->assertSame((new HubPreparationStore())->find($requested['operation_id'])['receipt_digest'], $approval['receipt_digest']);
        $this->assertSame('dbvc_agency_already_approved', $approvals->approve($requested['operation_id'])->get_error_code());
        $before_raw = $this->raw_option();
        $summary = $this->run_release();
        $this->assertFalse($summary['apply_enabled']);
        $this->assertSame(0, $summary['apply_requests']);
        $this->assertSame($before_raw, $this->raw_option(), 'Nothing applied while the gate is off.');
        $this->assertSame(ApprovalStore::STATE_APPROVED, (new ApprovalStore())->find($approval['approval_uid'])['state']);

        // Gate on: the approval is retrieved, executed with a conditional write, verified, journalled and reported once.
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1', DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED => '1']);
        $this->simulated_transport_error = new WP_Error('http_request_failed', 'simulated outage');
        $this->transport_failure_predicate = static function ($url) {
            return strpos((string) $url, 'apply-receipts') !== false;
        };
        $summary = $this->run_release();
        $this->assertTrue($summary['apply_enabled']);
        $this->assertSame([1, 1, 0], [$summary['apply_requests'], $summary['executions'], $summary['executions_reported']]);
        $this->assertSame('applied', $summary['operations'][0]['outcome']);
        $classes = get_option(self::CLASSES);
        $this->assertSame('row-reverse', $classes[0]['settings']['_direction'], 'The member was replaced in place.');
        $this->assertSame('bbb222', $classes[1]['id']);
        $this->assertSame(3, count($classes), 'Unrelated members survive.');
        $this->assertNotSame($before_raw, $this->raw_option());
        $journal = (new OperationStore())->find($requested['operation_id']);
        $this->assertSame(OperationStore::STATE_FINISHED, $journal['state']);
        $this->assertSame('applied', $journal['outcome']);
        $this->assertSame($before_raw, $journal['before_image']['bricks_global_classes']['raw'], 'The verified before image is journalled.');
        $this->assertSame(['precheck' => null, 'write' => 'written', 'verify' => 'verified'], ['precheck' => null, 'write' => $journal['journal'][0]['result'], 'verify' => $journal['journal'][1]['result']]);
        $this->assertNull($journal['reported_at']);
        $receipt = $journal['execution_receipt'];
        $this->assertSame(['applied' => 1, 'noop' => 1, 'stale' => 0, 'failed' => 0, 'compensated' => 0, 'unsupported' => 0], $receipt['counts']);
        $applied_item = null;
        foreach ($receipt['items'] as $item) {
            if ($item['instance_uid'] === $uids['aaa111']) {
                $applied_item = $item;
            }
        }
        $this->assertSame('applied', $applied_item['outcome']);
        $this->assertTrue($applied_item['verified']);
        $this->assertSame(hash('sha256', $before_raw), $applied_item['storage_fingerprint_before']);
        $this->assertSame(hash('sha256', $this->raw_option()), $applied_item['storage_fingerprint_after']);
        $this->assertSame([], $receipt['warnings'], 'Without Bricks in external-file mode there is nothing to rebuild, so no CSS warning.');

        // The apply is observed and reported with origin=apply; a second run never re-executes, only reports.
        $this->run_observation();
        $events = array_values(array_filter((new OutboxStore())->all(null, 500), static function ($event) use ($uids, $local) {
            return $event['instance_uid'] === $uids['aaa111'] && $event['installation_epoch'] === $local['epoch'];
        }));
        $latest = end($events);
        $this->assertSame('apply', $latest['body']['origin']);
        $this->assertSame($requested['operation_id'], $latest['body']['causation_id']);
        $this->assertSame(Canonicalizer::hash($after_aaa_body), $latest['body']['projection']['hash']);
        $this->simulated_transport_error = null;
        $this->transport_failure_predicate = null;
        $raw_after_apply = $this->raw_option();
        $summary = $this->run_release();
        $this->assertSame([0, 1, 0], [$summary['executions'], $summary['executions_recovered'], $summary['apply_requests']], 'Reported on recovery; the consumed approval is no longer offered.');
        $this->assertSame($raw_after_apply, $this->raw_option());
        $hub_approval = (new ApprovalStore())->find($approval['approval_uid']);
        $this->assertSame(ApprovalStore::STATE_CONSUMED, $hub_approval['state']);
        $this->assertSame('applied', $hub_approval['execution_outcome']);
        $this->assertSame($receipt, $hub_approval['execution_receipt']);
        $this->assertNotEmpty((new OperationStore())->find($requested['operation_id'])['reported_at']);

        // Verified applied objects advance the pair baseline; after the target's own report the pair reads synchronized.
        $baseline = (new BaselineStore())->for_pair('studio-src', $local['environment_id'], 'bricks.global_class');
        $this->assertSame(Canonicalizer::hash($after_aaa_body), $baseline['bricks.global_class|' . $uids['aaa111'] . '|bricks-global-class-v1']['baseline_hash']);
        $this->assertArrayNotHasKey('bricks.global_class|' . $uids['bbb222'] . '|bricks-global-class-v1', $baseline, 'A no-op advances nothing.');
        DBVC_Connected_Environments_Addon::runtime()->outbound()->run(['context' => 'test']);
        $rows = [];
        foreach ((new ComparisonService())->compare('studio-src', $local['environment_id'], 'bricks.global_class')['rows'] as $row) {
            $rows[$row['source_instance_uid']] = $row['state'];
        }
        $this->assertSame('synchronized', $rows[$uids['aaa111']]);

        // Stale: a container edit between receipt and execution is refused by the conditional write; nothing is overwritten.
        update_option('bricks_global_classes_categories', [['id' => 'rgxlvq', 'name' => 'Backgrounds']]); // ccc333's category, so its ledger resolves
        $after_ccc = json_decode((new ObjectStore())->get('bricks.global_class', $uids['ccc333'], 'bricks-global-class-v1')['snapshot_body'], true);
        $after_ccc['name'] = '--bg-gradient-dark';
        $this->report_and_seal($remote, [['domain' => 'bricks.global_class', 'instance_uid' => $uids['ccc333'], 'profile' => 'bricks-global-class-v1', 'body' => Canonicalizer::encode($after_ccc)]], $second_uid, 10);
        $second = $preparations->request($second_uid, $local['environment_id']);
        $this->assertSame(1, $this->run_release()['receipts_stored']);
        $second_approval = $approvals->approve($second['operation_id']);
        $this->assertIsArray($second_approval);
        $edited = get_option(self::CLASSES);
        $edited[2]['settings']['_margin'] = '8px'; // an editor save to the very object between receipt and execution
        update_option(self::CLASSES, $edited);
        $edited_raw = $this->raw_option();
        $summary = $this->run_release();
        $this->assertSame('stale', $summary['operations'][0]['outcome']);
        $this->assertSame($edited_raw, $this->raw_option(), 'The editor\'s change survives; nothing was written.');
        $this->assertSame(ApprovalStore::STATE_CONSUMED, (new ApprovalStore())->find($second_approval['approval_uid'])['state']);
        $this->assertSame('stale', (new ApprovalStore())->find($second_approval['approval_uid'])['execution_outcome']);
        $this->assertSame('container_changed_since_receipt', (new OperationStore())->find($second['operation_id'])['execution_receipt']['items'][0]['error']);
        $this->assertArrayNotHasKey('bricks.global_class|' . $uids['ccc333'] . '|bricks-global-class-v1', (new BaselineStore())->for_pair('studio-src', $local['environment_id'], 'bricks.global_class'));

        // Prepare again against the new state: the receipt agrees with the hub's projection only after the target reported it.
        $this->run_observation();
        $third = $preparations->request($second_uid, $local['environment_id']);
        $this->assertSame(1, $this->run_release()['receipts_stored']);
        $error = $approvals->approve($third['operation_id']);
        $this->assertSame('dbvc_agency_receipt_disagrees', $error->get_error_code(), 'The hub has not yet received the target\'s report of the edit.');
        DBVC_Connected_Environments_Addon::runtime()->outbound()->run(['context' => 'test']);
        $fourth = $preparations->request($second_uid, $local['environment_id']);
        $this->assertSame(1, $this->run_release()['receipts_stored']);
        $fourth_approval = $approvals->approve($fourth['operation_id']);
        $this->assertIsArray($fourth_approval, is_wp_error($fourth_approval) ? $fourth_approval->get_error_message() : '');
        $summary = $this->run_release();
        $this->assertSame('applied', $summary['operations'][0]['outcome']);
        $this->assertSame('--bg-gradient-dark', get_option(self::CLASSES)[2]['name']);
        $this->assertArrayNotHasKey('_margin', get_option(self::CLASSES)[2]['settings'], 'Whole-object replacement: the interim edit to this object is superseded by the approved after-state the receipt described.');
        $this->assertSame('flex', get_option(self::CLASSES)[1]['settings']['_display'], 'Unrelated members are untouched.');

        // A revoked approval is never executed; an expired one is refused by the target.
        $this->report_and_seal($remote, [['domain' => 'bricks.global_class', 'instance_uid' => $uids['bbb222'], 'profile' => 'bricks-global-class-v1', 'body' => Canonicalizer::encode(array_merge(json_decode($bodies[1]['body'], true), ['name' => '--flex-row-2']))]], $third_uid, 20);
        $fifth = $preparations->request($third_uid, $local['environment_id']);
        $this->run_release();
        $fifth_approval = $approvals->approve($fifth['operation_id']);
        $this->assertTrue($approvals->revoke($fifth_approval['approval_uid'], 'changed my mind')['changed']);
        $raw = $this->raw_option();
        $summary = $this->run_release();
        $this->assertSame(0, $summary['apply_requests']);
        $this->assertSame($raw, $this->raw_option());
        $this->assertNull((new OperationStore())->find($fifth['operation_id']));
        $this->assertSame(3, (new OperationStore())->counts()['total']);
        $this->assertSame(0, (new OperationStore())->counts()['unreported']);
        $this->assertNull((new InstanceIdentity())->find_by_instance_uid('bricks.global_class', 'never'), 'Apply assigns no identity.');
    }

    public function test_reviewed_rollback_restores_the_before_image_or_reports_a_conflict(): void
    {
        $local = $this->enroll_local();
        $uids = $this->local_uids();
        $remote = $this->enroll_remote('client-a', 'studio-src');
        (new SubscriptionService())->subscribe_client('studio-src', $local['environment_id'], []);
        $after = json_decode((new ObjectStore())->get('bricks.global_class', $uids['aaa111'], 'bricks-global-class-v1')['snapshot_body'], true);
        $after['settings']['_direction'] = 'row-reverse';
        $after_body = Canonicalizer::encode($after);
        $this->report_and_seal($remote, [['domain' => 'bricks.global_class', 'instance_uid' => $uids['aaa111'], 'profile' => 'bricks-global-class-v1', 'body' => $after_body]], $release_uid);
        $approvals = new ApprovalService();
        $requested = (new PreparationService())->request($release_uid, $local['environment_id']);
        $this->run_release();
        $approval = $approvals->approve($requested['operation_id'], 'go');
        $before_raw = $this->raw_option();
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1', DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED => '1']);
        $this->assertSame('applied', $this->run_release()['operations'][0]['outcome']);
        $applied_raw = $this->raw_option();
        $this->assertNotSame($before_raw, $applied_raw);
        $this->run_observation();
        $this->run_release(); // report the apply receipt so the approval is consumed.
        $env = (new \Dbvc\AgencyControl\Storage\EnvironmentRegistry())->find($local['environment_id']);

        // A rollback cannot be requested for an operation that never executed, and only once for one that did.
        $this->assertSame('dbvc_agency_operation_not_found', $approvals->rollback('prep-000000000000')->get_error_code());
        $rollback = $approvals->rollback($requested['operation_id'], 'undo');
        $this->assertIsArray($rollback, is_wp_error($rollback) ? $rollback->get_error_message() : '');
        $this->assertSame('rollback', $rollback['kind']);
        $this->assertSame($requested['operation_id'], $rollback['rolls_back_operation_id']);
        $this->assertSame('dbvc_agency_already_rolled_back', $approvals->rollback($requested['operation_id'])->get_error_code());

        // The target restores the before image on its next poll: conditional write on the apply\'s after fingerprint.
        $summary = $this->run_release();
        $this->assertSame([1, 1], [$summary['apply_requests'], $summary['executions']]);
        $this->assertSame('restored', $summary['operations'][0]['outcome']);
        $this->assertSame($before_raw, $this->raw_option(), 'The pre-apply value is restored byte for byte.');
        $journal = (new OperationStore())->find($rollback['operation_id']);
        $this->assertSame('restored', $journal['outcome']);
        $this->assertSame($applied_raw, $journal['before_image']['bricks_global_classes']['raw'], 'The restore journals the state it reversed.');
        $this->assertContains('restored', array_column($journal['journal'], 'result'), 'A restore write step is journalled.');
        $item = $journal['execution_receipt']['items'][0];
        $this->assertSame('rollback', $item['operation']);
        $this->assertSame($item['before_hash'], $item['restored_hash'], 'The restored hash is the object\'s pre-apply hash.');
        $this->assertNotSame('', (string) $item['restored_hash']);

        // The hub consumes the rollback receipt and regresses the pair baseline to the restored hash.
        $this->run_release(); // recovery report
        $hub = (new ApprovalStore())->find($rollback['approval_uid']);
        $this->assertSame(ApprovalStore::STATE_CONSUMED, $hub['state']);
        $this->assertSame('restored', $hub['execution_outcome']);
        $baseline = (new BaselineStore())->for_pair('studio-src', $local['environment_id'], 'bricks.global_class');
        $restored_hash = $journal['execution_receipt']['items'][0]['restored_hash'];
        $this->assertSame($restored_hash, $baseline['bricks.global_class|' . $uids['aaa111'] . '|bricks-global-class-v1']['baseline_hash']);

        // Rolling back again re-applies the release, then an editor edit between apply and rollback yields a restore conflict.
        $this->report_and_seal($remote, [['domain' => 'bricks.global_class', 'instance_uid' => $uids['aaa111'], 'profile' => 'bricks-global-class-v1', 'body' => $after_body]], $second_uid, 30);
        $second = (new PreparationService())->request($second_uid, $local['environment_id']);
        $this->run_release();
        DBVC_Connected_Environments_Addon::runtime()->outbound()->run(['context' => 'test']);
        $second_approval = $approvals->approve($second['operation_id']);
        $this->assertIsArray($second_approval, is_wp_error($second_approval) ? $second_approval->get_error_message() : '');
        $this->assertSame('applied', $this->run_release()['operations'][0]['outcome']);
        $this->run_observation();
        $this->run_release();
        $second_rollback = $approvals->rollback($second['operation_id'], 'undo 2');
        $this->assertIsArray($second_rollback, is_wp_error($second_rollback) ? $second_rollback->get_error_message() : '');
        // An editor changes the container after apply, before the rollback poll.
        $edited = get_option(self::CLASSES);
        $edited[0]['settings']['_margin'] = '4px';
        update_option(self::CLASSES, $edited);
        $edited_raw = $this->raw_option();
        $summary = $this->run_release();
        $this->assertSame('restore_conflict', $summary['operations'][0]['outcome']);
        $this->assertSame($edited_raw, $this->raw_option(), 'A container that moved on is never overwritten.');
        $this->assertSame('restore_conflict', (new ApprovalStore())->find($second_rollback['approval_uid'])['execution_outcome']);
        $this->assertSame('container_changed_since_operation', (new OperationStore())->find($second_rollback['operation_id'])['execution_receipt']['items'][0]['error']);
    }

    public function test_wp_service_apply_writes_the_post_row_verifies_and_advances_the_baseline(): void
    {
        register_post_type('service', ['public' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
        update_option('dbvc_post_types', ['service']);
        try {
            $post_id = self::factory()->post->create(['post_type' => 'service', 'post_title' => 'Roof inspection', 'post_status' => 'publish']);
            \DBVC_Sync_Posts::ensure_post_uid($post_id);
            $observer = \Dbvc\Connected\Adapters\DomainRegistry::observer_for('wp.service');
            $uid = (string) get_post_meta($post_id, 'vf_object_uid', true);
            $this->assertNotSame('', $uid);
            $this->run_observation();

            $local = $this->enroll_local();
            $remote = $this->enroll_remote('client-a', 'studio-src');
            (new SubscriptionService())->subscribe_client('studio-src', $local['environment_id'], []);

            // The source reports a renamed service; the after body is the target snapshot with the new title.
            $snapshot = $observer->snapshot(['storage_key' => (string) $post_id]);
            $after = json_decode((string) $snapshot['canonical'], true);
            $after['post_title'] = 'Roof inspection and repair';
            $after_body = Canonicalizer::encode($after);
            $this->report_and_seal($remote, [['domain' => 'wp.service', 'instance_uid' => $uid, 'profile' => 'wp-service-v1', 'body' => $after_body]], $release_uid);
            $approvals = new ApprovalService();
            $requested = (new PreparationService())->request($release_uid, $local['environment_id']);
            $this->assertSame(1, $this->run_release()['receipts_stored']);
            $this->assertSame('ready', (new HubPreparationStore())->find($requested['operation_id'])['outcome'], 'A service post prepares to a ready receipt.');
            $approval = $approvals->approve($requested['operation_id'], 'go');
            $this->assertIsArray($approval, is_wp_error($approval) ? $approval->get_error_message() : '');

            DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1', DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED => '1']);
            $summary = $this->run_release();
            $this->assertSame([1, 1], [$summary['apply_requests'], $summary['executions']]);
            $this->assertSame('applied', $summary['operations'][0]['outcome']);
            $this->assertSame('Roof inspection and repair', get_post($post_id)->post_title, 'The post row was written in place.');
            $journal = (new OperationStore())->find($requested['operation_id']);
            $this->assertSame('applied', $journal['outcome']);
            $item = $journal['execution_receipt']['items'][0];
            $this->assertTrue($item['verified']);
            $this->assertStringStartsWith('post_type:service#', (string) ($journal['journal'][0]['container'] ?? ''));
            $this->assertSame(Canonicalizer::hash($after_body), $observer->snapshot(['storage_key' => (string) $post_id])['semantic_hash'], 'The target now matches the released hash.');

            // Reported once; the pair baseline advances to the applied hash.
            $this->run_observation();
            $this->run_release();
            DBVC_Connected_Environments_Addon::runtime()->outbound()->run(['context' => 'test']);
            $hub = (new ApprovalStore())->find($approval['approval_uid']);
            $this->assertSame(ApprovalStore::STATE_CONSUMED, $hub['state']);
            $this->assertSame('applied', $hub['execution_outcome']);
            $baseline = (new BaselineStore())->for_pair('studio-src', $local['environment_id'], 'wp.service');
            $this->assertSame(Canonicalizer::hash($after_body), $baseline['wp.service|' . $uid . '|wp-service-v1']['baseline_hash']);

            // Reviewed rollback restores the service post to its pre-apply title.
            $rollback = $approvals->rollback($requested['operation_id'], 'undo service');
            $this->assertIsArray($rollback, is_wp_error($rollback) ? $rollback->get_error_message() : '');
            $summary = $this->run_release();
            $this->assertSame('restored', $summary['operations'][0]['outcome']);
            $this->assertSame('Roof inspection', get_post($post_id)->post_title, 'The pre-apply title is restored.');
            $rjournal = (new OperationStore())->find($rollback['operation_id']);
            $this->assertStringStartsWith('post_type:service#', (string) $rjournal['journal'][0]['container']);
            $this->run_release(); // report the rollback receipt
            $this->assertSame('restored', (new ApprovalStore())->find($rollback['approval_uid'])['execution_outcome']);

            // Re-apply so the stale-guard case below has a fresh applied state.
            $this->run_observation();
            DBVC_Connected_Environments_Addon::runtime()->outbound()->run(['context' => 'test']);

            // A post edit between receipt and execution is refused by the fingerprint guard.
            $after2 = json_decode((string) $observer->snapshot(['storage_key' => (string) $post_id])['canonical'], true);
            $after2['post_content'] = 'New body from source';
            $after2_body = Canonicalizer::encode($after2);
            $this->report_and_seal($remote, [['domain' => 'wp.service', 'instance_uid' => $uid, 'profile' => 'wp-service-v1', 'body' => $after2_body]], $second_uid, 20);
            $second = (new PreparationService())->request($second_uid, $local['environment_id']);
            $this->run_release();
            $second_approval = $approvals->approve($second['operation_id']);
            $this->assertIsArray($second_approval, is_wp_error($second_approval) ? $second_approval->get_error_message() : '');
            update_post_meta($post_id, 'service_note', 'an interim editor change'); // a custom-field edit moves the post fingerprint
            $summary = $this->run_release();
            $this->assertSame('stale', $summary['operations'][0]['outcome']);
            $this->assertSame('an interim editor change', get_post_meta($post_id, 'service_note', true), 'The editor change survives; nothing was overwritten.');
            $this->assertNotSame('New body from source', get_post($post_id)->post_content, 'The source body was not written.');
            $this->assertSame('post_changed_since_receipt', (new OperationStore())->find($second['operation_id'])['execution_receipt']['items'][0]['error']);
        } finally {
            unregister_post_type('service');
            delete_option('dbvc_post_types');
        }
    }

    public function test_wp_service_deletion_trashes_the_post_and_rollback_restores_it(): void
    {
        register_post_type('service', ['public' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
        update_option('dbvc_post_types', ['service']);
        try {
            $post_id = self::factory()->post->create(['post_type' => 'service', 'post_title' => 'Retired service', 'post_status' => 'publish']);
            \DBVC_Sync_Posts::ensure_post_uid($post_id);
            $observer = \Dbvc\Connected\Adapters\DomainRegistry::observer_for('wp.service');
            $uid = (string) get_post_meta($post_id, 'vf_object_uid', true);
            $this->assertNotSame('', $uid);
            $this->run_observation();

            $local = $this->enroll_local();
            $remote = $this->enroll_remote('client-a', 'studio-src');
            (new SubscriptionService())->subscribe_client('studio-src', $local['environment_id'], []);

            // The source reports the object as verified-absent (a tombstone) and releases a deletion.
            $tombstone = Canonicalizer::encode(null);
            $event = ObservationEvent::build([
                'event_id' => 'obs-del-000001', 'environment_id' => 'studio-src', 'installation_epoch' => $remote['epoch'], 'sequence' => 1, 'observed_at' => '2026-09-22T10:00:00Z',
                'object' => ['domain' => 'wp.service', 'instance_uid' => $uid],
                'projection' => ['profile' => 'wp-service-v1', 'hash' => Canonicalizer::hash($tombstone), 'exists' => false, 'complete' => true],
            ]);
            $this->assertSame(200, $this->as_environment($remote, 'POST', '/dbvc-agency/v1/observations', ['events' => [$event]])->get_status());
            $created = (new ReleaseService())->create('studio-src', [['domain' => 'wp.service', 'instance_uid' => $uid, 'operation' => 'delete']]);
            $this->assertIsArray($created, is_wp_error($created) ? $created->get_error_message() : '');
            $release_uid = $created['release']['release_uid'];
            $sealed = $this->as_environment($remote, 'POST', '/dbvc-agency/v1/releases/' . $release_uid . '/payloads', ['items' => [['domain' => 'wp.service', 'instance_uid' => $uid, 'profile' => 'wp-service-v1', 'body' => $tombstone]]]);
            $this->assertTrue($sealed->get_data()['sealed'], wp_json_encode($sealed->get_data()));

            $requested = (new PreparationService())->request($release_uid, $local['environment_id']);
            $this->assertSame(1, $this->run_release()['receipts_stored']);
            $this->assertSame('ready', (new HubPreparationStore())->find($requested['operation_id'])['outcome'], 'A present service post prepares to a ready remove_object.');

            $approval = (new ApprovalService())->approve($requested['operation_id'], 'remove it');
            $this->assertIsArray($approval, is_wp_error($approval) ? $approval->get_error_message() : '');
            DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1', DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED => '1']);
            $summary = $this->run_release();
            $this->assertSame('applied', $summary['operations'][0]['outcome']);
            $this->assertSame('trash', get_post_status($post_id), 'The service post was trashed, never hard-deleted.');
            $this->assertSame('missing', $observer->snapshot(['storage_key' => (string) $post_id])['status'], 'The trashed post left observed coverage.');

            // Reviewed rollback of the deletion restores the post (untrashed, same id + identity).
            $rollback = (new ApprovalService())->rollback($requested['operation_id'], 'undo delete');
            $this->assertIsArray($rollback, is_wp_error($rollback) ? $rollback->get_error_message() : '');
            $summary = $this->run_release();
            $this->assertSame('restored', $summary['operations'][0]['outcome']);
            $this->assertSame('publish', get_post_status($post_id), 'The rollback untrashed the post.');
            $this->assertSame('Retired service', get_post($post_id)->post_title);
            $this->assertSame($uid, (string) get_post_meta($post_id, 'vf_object_uid', true), 'Portable identity survived the delete/restore.');
        } finally {
            unregister_post_type('service');
            delete_option('dbvc_post_types');
        }
    }

    public function test_wp_service_apply_syncs_unmasked_fields_when_a_field_is_masked(): void
    {
        register_post_type('service', ['public' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
        update_option('dbvc_post_types', ['service']);
        update_option('dbvc_mask_post_fields', ['post_date_gmt']);
        update_option('dbvc_mask_action', 'remove');
        try {
            $post_id = self::factory()->post->create([
                'post_type' => 'service',
                'post_title' => 'Gutter cleaning',
                'post_status' => 'publish',
                'post_date' => '2019-05-15 08:00:00',
                'post_date_gmt' => '2019-05-15 08:00:00',
            ]);
            \DBVC_Sync_Posts::ensure_post_uid($post_id);
            $observer = \Dbvc\Connected\Adapters\DomainRegistry::observer_for('wp.service');
            $uid = (string) get_post_meta($post_id, 'vf_object_uid', true);
            $this->assertNotSame('', $uid);
            $this->run_observation();

            // Masking a post field makes the projection incomplete, but the managed
            // (unmasked) hash is real and the masked field is excluded from the body.
            $snapshot = $observer->snapshot(['storage_key' => (string) $post_id]);
            $this->assertFalse($snapshot['complete'], 'A masked field marks the projection incomplete.');
            $this->assertContains('masked:post_date_gmt', (array) $snapshot['problems']);
            $after = json_decode((string) $snapshot['canonical'], true);
            $this->assertArrayNotHasKey('post_date_gmt', $after, 'The masked field is excluded from the managed body.');

            $local = $this->enroll_local();
            $remote = $this->enroll_remote('client-a', 'studio-src');
            (new SubscriptionService())->subscribe_client('studio-src', $local['environment_id'], []);

            $after['post_title'] = 'Gutter cleaning and repair';
            $after_body = Canonicalizer::encode($after);
            $this->report_and_seal($remote, [['domain' => 'wp.service', 'instance_uid' => $uid, 'profile' => 'wp-service-v1', 'body' => $after_body]], $release_uid);

            $requested = (new PreparationService())->request($release_uid, $local['environment_id']);
            $this->assertSame(1, $this->run_release()['receipts_stored']);
            $this->assertSame('ready', (new HubPreparationStore())->find($requested['operation_id'])['outcome'], 'A masked-field service post prepares READY instead of blocking.');

            $approval = (new ApprovalService())->approve($requested['operation_id'], 'go');
            $this->assertIsArray($approval, is_wp_error($approval) ? $approval->get_error_message() : '');
            DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1', DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED => '1']);
            $summary = $this->run_release();
            $this->assertSame([1, 1], [$summary['apply_requests'], $summary['executions']]);
            $this->assertSame('applied', $summary['operations'][0]['outcome']);

            $post = get_post($post_id);
            $this->assertSame('Gutter cleaning and repair', $post->post_title, 'The unmasked title synced.');
            $this->assertSame('2019-05-15 08:00:00', $post->post_date_gmt, 'The masked post_date_gmt was preserved, never overwritten.');
        } finally {
            delete_option('dbvc_mask_post_fields');
            delete_option('dbvc_mask_action');
            unregister_post_type('service');
            delete_option('dbvc_post_types');
        }
    }

    public function test_wp_service_apply_sideloads_carried_media_onto_a_target_that_lacks_it(): void
    {
        register_post_type('service', ['public' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
        update_option('dbvc_post_types', ['service']);
        try {
            $bytes = 'SIDELOAD-IMAGE-BYTES';
            $hash = hash('sha256', $bytes);
            $observer = \Dbvc\Connected\Adapters\DomainRegistry::observer_for('wp.service');

            // Source: an attachment plus a service post that references it (featured + inline).
            $upload = wp_upload_bits('sideload.png', null, $bytes);
            $this->assertEmpty($upload['error'] ?? '', (string) ($upload['error'] ?? ''));
            $att = wp_insert_attachment(['post_mime_type' => (string) $upload['type'], 'post_title' => 'Hero', 'post_excerpt' => 'Cap', 'post_status' => 'inherit'], (string) $upload['file']);
            update_post_meta($att, '_wp_attachment_image_alt', 'Alt');
            $src = self::factory()->post->create(['post_type' => 'service', 'post_status' => 'publish', 'post_title' => 'Roof repair', 'post_content' => '<img class="wp-image-' . $att . '" src="' . esc_url((string) wp_get_attachment_url($att)) . '" />']);
            update_post_meta($src, '_thumbnail_id', $att);
            \DBVC_Sync_Posts::ensure_post_uid($src);
            $snap = $observer->snapshot(['storage_key' => (string) $src]);
            $this->assertSame('available', $snap['status'], wp_json_encode($snap));
            $after_hash = (string) $snap['semantic_hash'];
            $after_body = (string) $snap['canonical'];
            $bundle = \Dbvc\Connected\Preparation\MediaReferences::collect_for_transport(json_decode($after_body, true));
            $this->assertCount(1, $bundle['media'], 'The source carries the referenced attachment bytes.');
            $this->assertSame('sha256:' . $hash, $bundle['media'][0]['hash']);
            $this->assertStringContainsString(\Dbvc\Connected\Preparation\MediaReferences::TOKEN_PREFIX . $hash, $after_body, 'The projected body references the attachment by content-hash token.');

            // The target lacks the media: drop the source post and its attachment, then
            // stand up a fresh media-free target post with its own identity.
            wp_delete_post($src, true);
            wp_delete_attachment($att, true);
            $this->assertSame(0, \Dbvc\Connected\Preparation\MediaReferences::local_by_hash($hash), 'The target no longer holds the media.');
            $target = self::factory()->post->create(['post_type' => 'service', 'post_status' => 'publish', 'post_title' => 'Placeholder', 'post_content' => '<p>old</p>']);
            \DBVC_Sync_Posts::ensure_post_uid($target);
            $uid = (string) get_post_meta($target, 'vf_object_uid', true);
            $this->assertNotSame('', $uid);

            $local = $this->enroll_local();
            $remote = $this->enroll_remote('client-a', 'studio-src');
            (new SubscriptionService())->subscribe_client('studio-src', $local['environment_id'], []);
            $this->report_and_seal($remote, [['domain' => 'wp.service', 'instance_uid' => $uid, 'profile' => 'wp-service-v1', 'body' => $after_body, 'media' => $bundle['media']]], $release_uid);

            $requested = (new PreparationService())->request($release_uid, $local['environment_id']);
            $this->assertSame(1, $this->run_release()['receipts_stored']);
            $this->assertSame('ready', (new HubPreparationStore())->find($requested['operation_id'])['outcome'], 'Carried media makes the post READY even though the target lacks the bytes.');

            (new ApprovalService())->approve($requested['operation_id'], 'go');
            DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1', DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED => '1']);
            $summary = $this->run_release();
            $this->assertSame([1, 1], [$summary['apply_requests'], $summary['executions']]);
            $this->assertSame('applied', $summary['operations'][0]['outcome'], 'A media-bearing post applies by sideloading its attachment.');

            // The bytes were sideloaded into a new local attachment, the post references
            // it locally, and the applied post re-projects to the source after_hash.
            $new_att = \Dbvc\Connected\Preparation\MediaReferences::local_by_hash($hash);
            $this->assertGreaterThan(0, $new_att, 'The carried bytes were sideloaded.');
            $this->assertNotSame($att, $new_att, 'A new local attachment, not the deleted source id.');
            $this->assertSame((string) $new_att, (string) get_post_meta($target, '_thumbnail_id', true));
            $this->assertStringContainsString('wp-image-' . $new_att, (string) get_post($target)->post_content);
            $this->assertSame($after_hash, (string) $observer->snapshot(['storage_key' => (string) $target])['semantic_hash'], 'The media-bearing post converged to the source hash.');
        } finally {
            unregister_post_type('service');
            delete_option('dbvc_post_types');
        }
    }

    public function test_reviewed_rollback_deletes_only_the_attachments_the_apply_created(): void
    {
        register_post_type('service', ['public' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
        update_option('dbvc_post_types', ['service']);
        try {
            $observer = \Dbvc\Connected\Adapters\DomainRegistry::observer_for('wp.service');
            $kept_bytes = 'ALREADY-ON-TARGET';
            $kept_hash = hash('sha256', $kept_bytes);
            $new_bytes = 'ARRIVES-WITH-RELEASE';
            $new_hash = hash('sha256', $new_bytes);

            // X exists on the target; Y (below) does not. The release's after-state
            // references both: X is reused, Y is sideloaded.
            $kept_upload = wp_upload_bits('kept.png', null, $kept_bytes);
            $kept = wp_insert_attachment(['post_mime_type' => (string) $kept_upload['type'], 'post_status' => 'inherit'], (string) $kept_upload['file']);
            $y_upload = wp_upload_bits('arrives.png', null, $new_bytes);
            $y = wp_insert_attachment(['post_mime_type' => (string) $y_upload['type'], 'post_status' => 'inherit'], (string) $y_upload['file']);

            // Source projection referencing both attachments -> after body (tokens) + media bytes.
            $src = self::factory()->post->create(['post_type' => 'service', 'post_status' => 'publish', 'post_title' => 'Both', 'post_content' => '<img class="wp-image-' . $y . '" />']);
            update_post_meta($src, '_thumbnail_id', $kept);
            \DBVC_Sync_Posts::ensure_post_uid($src);
            $snap = $observer->snapshot(['storage_key' => (string) $src]);
            $after_hash = (string) $snap['semantic_hash'];
            $after_body = (string) $snap['canonical'];
            $bundle = \Dbvc\Connected\Preparation\MediaReferences::collect_for_transport(json_decode($after_body, true));
            $this->assertCount(2, $bundle['media'], 'Both attachments are carried.');

            // Y is removed from the target (it must be sideloaded); X stays.
            wp_delete_post($src, true);
            wp_delete_attachment($y, true);
            $this->assertSame(0, \Dbvc\Connected\Preparation\MediaReferences::local_by_hash($new_hash));

            // The target already has a post referencing X (its pre-apply before image).
            $target = self::factory()->post->create(['post_type' => 'service', 'post_status' => 'publish', 'post_title' => 'Target', 'post_content' => '<p>before</p>']);
            update_post_meta($target, '_thumbnail_id', $kept);
            \DBVC_Sync_Posts::ensure_post_uid($target);
            $uid = (string) get_post_meta($target, 'vf_object_uid', true);

            $local = $this->enroll_local();
            $remote = $this->enroll_remote('client-a', 'studio-src');
            (new SubscriptionService())->subscribe_client('studio-src', $local['environment_id'], []);
            $this->assertGreaterThan(0, \Dbvc\Connected\Preparation\MediaReferences::local_by_hash($kept_hash), 'X is hash-indexed on the target after observation.');
            $before_hash = (string) $observer->snapshot(['storage_key' => (string) $target])['semantic_hash'];

            $this->report_and_seal($remote, [['domain' => 'wp.service', 'instance_uid' => $uid, 'profile' => 'wp-service-v1', 'body' => $after_body, 'media' => $bundle['media']]], $release_uid);
            $requested = (new PreparationService())->request($release_uid, $local['environment_id']);
            $this->run_release();
            (new ApprovalService())->approve($requested['operation_id'], 'go');
            DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1', DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED => '1']);
            $this->assertSame('applied', $this->run_release()['operations'][0]['outcome']);

            $y_new = \Dbvc\Connected\Preparation\MediaReferences::local_by_hash($new_hash);
            $this->assertGreaterThan(0, $y_new, 'Y was sideloaded.');
            $this->assertSame($kept, \Dbvc\Connected\Preparation\MediaReferences::local_by_hash($kept_hash), 'X was reused, not duplicated.');
            $this->assertStringContainsString('wp-image-' . $y_new, (string) get_post($target)->post_content);

            // Reviewed rollback: restore the before image and delete only what the apply created.
            $rollback = (new ApprovalService())->rollback($requested['operation_id'], 'undo');
            $this->assertIsArray($rollback, is_wp_error($rollback) ? $rollback->get_error_message() : '');
            $this->assertSame('restored', $this->run_release()['operations'][0]['outcome']);

            $this->assertSame('attachment', get_post_type($kept), 'The reused attachment X is untouched by rollback.');
            $this->assertNull(get_post($y_new), 'The sideloaded attachment Y was deleted by rollback.');
            $this->assertSame((string) $kept, (string) get_post_meta($target, '_thumbnail_id', true));
            $this->assertStringNotContainsString('wp-image-' . $y_new, (string) get_post($target)->post_content, 'The restored post no longer references the deleted attachment.');
            $this->assertSame($before_hash, (string) $observer->snapshot(['storage_key' => (string) $target])['semantic_hash'], 'The post is back at its before image.');
        } finally {
            unregister_post_type('service');
            delete_option('dbvc_post_types');
        }
    }

    public function test_bricks_css_files_are_regenerated_after_apply_in_external_file_mode(): void
    {
        // Stub the supported Bricks calls: external-file CSS mode with a regeneration method.
        if (! class_exists('Bricks\\Database')) {
            eval('namespace Bricks; class Database { public static $mode = \'file\'; public static function get_setting($k) { return $k === \'cssLoading\' ? self::$mode : null; } }');
        }
        if (! class_exists('Bricks\\Assets_Files')) {
            eval('namespace Bricks; class Assets_Files { public static $calls = 0; public static function regenerate_css_files() { self::$calls++; return [\'global-classes.min.css\', \'global-variables.min.css\']; } }');
        }
        \Bricks\Database::$mode = 'file';
        \Bricks\Assets_Files::$calls = 0;

        $local = $this->enroll_local();
        $uids = $this->local_uids();
        $remote = $this->enroll_remote('client-a', 'studio-src');
        (new SubscriptionService())->subscribe_client('studio-src', $local['environment_id'], []);
        $after = json_decode((new ObjectStore())->get('bricks.global_class', $uids['aaa111'], 'bricks-global-class-v1')['snapshot_body'], true);
        $after['settings']['_direction'] = 'row-reverse';
        $this->report_and_seal($remote, [['domain' => 'bricks.global_class', 'instance_uid' => $uids['aaa111'], 'profile' => 'bricks-global-class-v1', 'body' => Canonicalizer::encode($after)]], $release_uid);
        $requested = (new PreparationService())->request($release_uid, $local['environment_id']);
        $this->run_release();
        (new ApprovalService())->approve($requested['operation_id'], 'go');
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1', DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED => '1']);
        $summary = $this->run_release();

        $this->assertSame('applied', $summary['operations'][0]['outcome']);
        $this->assertSame(1, \Bricks\Assets_Files::$calls, 'The supported Bricks regeneration call ran once after the write.');
        $receipt = (new OperationStore())->find($requested['operation_id'])['execution_receipt'];
        $this->assertStringContainsString('generated_css_rebuilt', $receipt['warnings'][0]);
        $this->assertStringContainsString('2 Bricks CSS file(s)', $receipt['warnings'][0]);

        // Inline mode needs no rebuild: no CSS call, no warning.
        \Bricks\Assets_Files::$calls = 0;
        \Bricks\Database::$mode = 'inline';
        $after2 = json_decode((new ObjectStore())->get('bricks.global_class', $uids['aaa111'], 'bricks-global-class-v1')['snapshot_body'], true);
        $after2['settings']['_direction'] = 'column';
        $this->report_and_seal($remote, [['domain' => 'bricks.global_class', 'instance_uid' => $uids['aaa111'], 'profile' => 'bricks-global-class-v1', 'body' => Canonicalizer::encode($after2)]], $second_uid, 20);
        $this->run_observation();
        DBVC_Connected_Environments_Addon::runtime()->outbound()->run(['context' => 'test']);
        $second = (new PreparationService())->request($second_uid, $local['environment_id']);
        $this->run_release();
        (new ApprovalService())->approve($second['operation_id']);
        $summary = $this->run_release();
        $this->assertSame('applied', $summary['operations'][0]['outcome']);
        $this->assertSame(0, \Bricks\Assets_Files::$calls, 'Inline CSS mode regenerates per request; nothing to rebuild.');
        $this->assertSame([], (new OperationStore())->find($second['operation_id'])['execution_receipt']['warnings']);
    }

    public function test_execution_receipts_are_bound_and_stored_once(): void
    {
        $local = $this->enroll_local();
        $uids = $this->local_uids();
        $remote = $this->enroll_remote('client-a', 'studio-src');
        $body = json_decode((new ObjectStore())->get('bricks.global_class', $uids['aaa111'], 'bricks-global-class-v1')['snapshot_body'], true);
        $body['name'] = '--flex-col-2';
        $this->report_and_seal($remote, [['domain' => 'bricks.global_class', 'instance_uid' => $uids['aaa111'], 'profile' => 'bricks-global-class-v1', 'body' => Canonicalizer::encode($body)]], $release_uid);
        $requested = (new PreparationService())->request($release_uid, $local['environment_id']);
        $this->run_release();
        $approval = (new ApprovalService())->approve($requested['operation_id']);
        $this->assertIsArray($approval);

        // A forged execution receipt from the source, or with the wrong digests, is refused; the approval stays open.
        $forged = ['approval_uid' => $approval['approval_uid'], 'operation_id' => $requested['operation_id'], 'release_digest' => $approval['release_digest'], 'receipt_digest' => $approval['receipt_digest'], 'target' => ['environment_id' => $local['environment_id'], 'epoch' => $local['epoch']], 'outcome' => 'applied', 'items' => []];
        $response = $this->as_environment($remote, 'POST', '/dbvc-agency/v1/releases/' . $release_uid . '/apply-receipts', ['receipt' => $forged]);
        $this->assertSame(404, $response->get_status());
        $wrong = $forged;
        $wrong['receipt_digest'] = str_repeat('1', 64);
        $error = (new ApprovalService())->accept_execution((new \Dbvc\AgencyControl\Storage\EnvironmentRegistry())->find($local['environment_id']), $release_uid, $wrong);
        $this->assertSame('dbvc_agency_invalid_receipt', $error->get_error_code());
        $this->assertSame(ApprovalStore::STATE_APPROVED, (new ApprovalStore())->find($approval['approval_uid'])['state']);

        // The real execution consumes it; a second receipt for the same approval is refused.
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1', DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED => '1']);
        $summary = $this->run_release();
        $this->assertSame('applied', $summary['operations'][0]['outcome']);
        $this->assertSame('--flex-col-2', get_option(self::CLASSES)[0]['name']);
        $stored = (new ApprovalStore())->find($approval['approval_uid']);
        $this->assertSame(ApprovalStore::STATE_CONSUMED, $stored['state']);
        $again = (new ApprovalService())->accept_execution((new \Dbvc\AgencyControl\Storage\EnvironmentRegistry())->find($local['environment_id']), $release_uid, $stored['execution_receipt']);
        $this->assertSame('dbvc_agency_approval_closed', $again->get_error_code());
        $this->assertSame(1, (new ApprovalStore())->counts()[ApprovalStore::STATE_CONSUMED]);
    }

    /**
     * @param array<string, mixed>              $remote
     * @param array<int, array<string, mixed>>  $bodies
     * @param string|null                       $release_uid
     * @param int                               $sequence_base
     */
    public function test_guarded_creation_lands_a_new_object_adopts_identity_is_idempotent_and_rolls_back(): void
    {
        $local = $this->enroll_local();
        $remote = $this->enroll_remote('client-a', 'studio-src');
        (new SubscriptionService())->subscribe_client('studio-src', $local['environment_id'], []);
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1', DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED => '1']);

        // A framework object the target has never had (no variable refs, no category → clean ledger).
        $new_uid = 'bricks-global-class-fwnew';
        $new_body = Canonicalizer::encode(['id' => 'ddd444', 'name' => '--fw-new', 'settings' => ['_display' => 'block']]);
        $before_raw = $this->raw_option();
        $before_count = count(get_option(self::CLASSES));

        $release_uid = $this->report_and_seal_create($remote, $new_uid, $new_body, 1);
        $requested = (new PreparationService())->request($release_uid, $local['environment_id']);
        $this->run_release(); // deliver the prepare receipt

        $prep = (new HubPreparationStore())->find($requested['operation_id']);
        $this->assertSame('ready', $prep['outcome'], 'A create against an absent object is ready, not blocked.');
        $item = $prep['receipt']['items'][0];
        $this->assertSame('create', $item['operation']);
        $this->assertSame(BricksOptionCollectionObserver::absent_hash(), $item['before_hash']);
        $this->assertSame('add_object', $item['patch']['strategy']);

        $approval = (new ApprovalService())->approve($requested['operation_id'], 'ship fw-new');
        $this->assertIsArray($approval, is_wp_error($approval) ? $approval->get_error_message() : '');
        $summary = $this->run_release();
        $this->assertSame('applied', $summary['operations'][0]['outcome'], wp_json_encode($summary['operations'][0] ?? []));

        // The object landed verbatim (its framework id) and unrelated members survive.
        $classes = get_option(self::CLASSES);
        $this->assertSame($before_count + 1, count($classes), 'The new object was appended; existing members survive.');
        $created_member = null;
        foreach ($classes as $class) {
            if (($class['name'] ?? '') === '--fw-new') {
                $created_member = $class;
            }
        }
        $this->assertNotNull($created_member, 'The framework object was created.');
        $this->assertSame('ddd444', $created_member['id'], 'It keeps the framework object id, so it verifies by hash and is fleet-identical.');

        // It adopted the framework object's portable identity (aligns by shared UID; no fresh uid minted).
        $mapping = (new InstanceIdentity())->find_by_instance_uid('bricks.global_class', $new_uid);
        $this->assertNotNull($mapping, 'The created object is bound to the framework identity.');
        $this->assertSame('ddd444', $mapping['storage_key']);

        // Idempotent: re-creating the same object does not duplicate it (now resolved by the bound uid → noop).
        $this->run_observation();
        $again_uid = $this->report_and_seal_create($remote, $new_uid, $new_body, 2);
        $req2 = (new PreparationService())->request($again_uid, $local['environment_id']);
        $this->run_release();
        $this->assertSame('noop', (new HubPreparationStore())->find($req2['operation_id'])['outcome'], 'The object already exists → noop, never a duplicate.');
        $this->assertSame($before_count + 1, count(get_option(self::CLASSES)));

        // Reviewed rollback of the create removes the object (restores the absent-before container).
        $this->run_release(); // report the first apply so its approval is consumed
        $rollback = (new ApprovalService())->rollback($requested['operation_id'], 'undo create');
        $this->assertIsArray($rollback, is_wp_error($rollback) ? $rollback->get_error_message() : '');
        $summary = $this->run_release();
        $this->assertSame('restored', $summary['operations'][0]['outcome'], wp_json_encode($summary['operations'][0] ?? []));
        $this->assertSame($before_count, count(get_option(self::CLASSES)), 'Rollback removes the created object.');
        $this->assertSame($before_raw, $this->raw_option(), 'The collection is restored byte for byte.');
    }

    public function test_guarded_creation_into_an_absent_option_adds_the_option_and_rollback_removes_it(): void
    {
        $local = $this->enroll_local();
        $remote = $this->enroll_remote('client-a', 'studio-src');
        (new SubscriptionService())->subscribe_client('studio-src', $local['environment_id'], []);
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1', DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED => '1']);

        // The target has NO bricks_global_classes option at all — not an empty option, a
        // missing one. A create must add the option row itself, then roll back to absence.
        delete_option(self::CLASSES);
        $this->assertFalse(get_option(self::CLASSES), 'Precondition: the collection option is absent.');

        $new_uid = 'bricks-global-class-fwabsent';
        $new_body = Canonicalizer::encode(['id' => 'eee555', 'name' => '--fw-into-absent', 'settings' => ['_display' => 'flex']]);

        $release_uid = $this->report_and_seal_create($remote, $new_uid, $new_body, 1);
        $requested = (new PreparationService())->request($release_uid, $local['environment_id']);
        $this->run_release(); // deliver the prepare receipt

        $prep = (new HubPreparationStore())->find($requested['operation_id']);
        $this->assertSame('ready', $prep['outcome'], 'A create into an absent option is ready, not stale.');
        $this->assertSame('create', $prep['receipt']['items'][0]['operation']);
        $this->assertNull($prep['receipt']['items'][0]['storage_fingerprint'], 'The receipt carries a null container fingerprint when the option is absent.');

        $approval = (new ApprovalService())->approve($requested['operation_id'], 'ship into absent');
        $this->assertIsArray($approval, is_wp_error($approval) ? $approval->get_error_message() : '');
        $summary = $this->run_release();
        $this->assertSame('applied', $summary['operations'][0]['outcome'], wp_json_encode($summary['operations'][0] ?? []));

        // The option was created, holding exactly the one new member at its verbatim id.
        $classes = get_option(self::CLASSES);
        $this->assertIsArray($classes, 'The apply added the option.');
        $this->assertCount(1, $classes);
        $this->assertSame('--fw-into-absent', $classes[0]['name']);
        $this->assertSame('eee555', $classes[0]['id'], 'The framework object id is kept verbatim.');
        $mapping = (new InstanceIdentity())->find_by_instance_uid('bricks.global_class', $new_uid);
        $this->assertNotNull($mapping, 'The created object adopts the framework portable identity.');

        // Reviewed rollback removes the option entirely — net-zero, since it never existed.
        $this->run_release(); // report the apply so its approval is consumed
        $rollback = (new ApprovalService())->rollback($requested['operation_id'], 'undo into absent');
        $this->assertIsArray($rollback, is_wp_error($rollback) ? $rollback->get_error_message() : '');
        $summary = $this->run_release();
        $this->assertSame('restored', $summary['operations'][0]['outcome'], wp_json_encode($summary['operations'][0] ?? []));
        $this->assertFalse(get_option(self::CLASSES), 'Rollback deletes the option it created: the target is back to absent.');
        $this->assertSame('', $this->raw_option(), 'No option row remains — byte-for-byte as before the create.');
    }

    public function test_singleton_theme_styles_replace_applies_whole_blob_verifies_and_rolls_back(): void
    {
        $local = $this->enroll_local();
        $remote = $this->enroll_remote('client-a', 'studio-src');
        // Opt this connector into the theme_styles singleton domain and subscribe the pair to it.
        update_option(\Dbvc\Connected\Adapters\DomainRegistry::OPTION_BRICKS_DOMAINS, ['theme_styles']);
        \Dbvc\Connected\Adapters\DomainRegistry::reset_observers();
        $this->assertIsArray((new SubscriptionService())->subscribe_client('studio-src', $local['environment_id'], ['wp.bricks:theme_styles']));
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1', DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED => '1']);

        // The target holds an older whole-blob theme style; the framework publishes a new one.
        $singleton = \Dbvc\Connected\Adapters\BricksOptionSingletonObserver::SINGLETON_KEY;
        $old = ['global' => ['settings' => ['typography' => ['typographyBody' => ['color' => ['raw' => '#111111']]]]], 'global_old' => ['settings' => []]];
        $new = ['global' => ['settings' => ['typography' => ['typographyBody' => ['color' => ['raw' => '#222222']]]]], 'global_old' => ['settings' => []]];
        update_option('bricks_theme_styles', $old);
        $before_raw = (string) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", 'bricks_theme_styles'));
        $new_body = Canonicalizer::encode($new);

        $release_uid = '';
        $this->report_and_seal($remote, [['domain' => 'wp.bricks:theme_styles', 'instance_uid' => $singleton, 'profile' => 'bricks-theme-styles-v1', 'body' => $new_body]], $release_uid, 10);

        $requested = (new PreparationService())->request($release_uid, $local['environment_id']);
        $this->run_release(); // deliver the prepare receipt

        $prep = (new HubPreparationStore())->find($requested['operation_id']);
        $this->assertSame('ready', $prep['outcome'], wp_json_encode($prep));
        $item = $prep['receipt']['items'][0];
        $this->assertSame('replace', $item['operation']);
        $this->assertSame($singleton, $item['identity']['storage_key'], 'A singleton resolves to its deterministic storage key, no sidecar.');

        $approval = (new ApprovalService())->approve($requested['operation_id'], 'ship theme styles');
        $this->assertIsArray($approval, is_wp_error($approval) ? $approval->get_error_message() : '');
        $summary = $this->run_release();
        $this->assertSame('applied', $summary['operations'][0]['outcome'], wp_json_encode($summary['operations'][0] ?? []));

        // The whole option converged to the framework blob.
        $applied = get_option('bricks_theme_styles');
        $this->assertSame('#222222', $applied['global']['settings']['typography']['typographyBody']['color']['raw'], 'The theme style blob was replaced whole.');

        // Reviewed rollback restores the prior blob byte for byte.
        $this->run_release(); // report the apply so its approval is consumed
        $rollback = (new ApprovalService())->rollback($requested['operation_id'], 'undo theme styles');
        $this->assertIsArray($rollback, is_wp_error($rollback) ? $rollback->get_error_message() : '');
        $summary = $this->run_release();
        $this->assertSame('restored', $summary['operations'][0]['outcome'], wp_json_encode($summary['operations'][0] ?? []));
        $restored = get_option('bricks_theme_styles');
        $this->assertSame('#111111', $restored['global']['settings']['typography']['typographyBody']['color']['raw'], 'Rollback restored the prior theme style.');
        $this->assertSame($before_raw, (string) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", 'bricks_theme_styles')), 'Byte-for-byte restore.');
    }

    public function test_component_create_remaps_class_references_verifies_and_is_id_identical(): void
    {
        $local = $this->enroll_local();
        $remote = $this->enroll_remote('client-a', 'studio-src');
        update_option(\Dbvc\Connected\Adapters\DomainRegistry::OPTION_BRICKS_DOMAINS, ['components']);
        \Dbvc\Connected\Adapters\DomainRegistry::reset_observers();
        $this->assertIsArray((new SubscriptionService())->subscribe_client('studio-src', $local['environment_id'], ['wp.bricks:components']));
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1', DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED => '1']);

        // The target already holds the referenced class, at ITS OWN local id, and has the
        // (empty) components container — the guarded write appends into a present container.
        update_option('bricks_global_classes', [['id' => 'local9', 'name' => 'team-card']]);
        update_option('bricks_components', []);

        // The framework component references that class by PORTABLE TOKEN (the projection form).
        $token = \Dbvc\Connected\Preparation\ReferenceTokens::token('class', 'team-card');
        $component = ['id' => 'cmp777', 'category' => 'Cards', 'elements' => [
            ['id' => 'cmp777', 'name' => 'block', 'label' => 'promo-card', 'settings' => ['_cssGlobalClasses' => [$token]]],
        ]];
        $body = Canonicalizer::encode($component);
        $uid = 'bricks-component-cmp777';

        $event = ObservationEvent::build([
            'event_id' => 'obs-comp-000001', 'environment_id' => $remote['environment_id'], 'installation_epoch' => $remote['epoch'], 'sequence' => 1, 'observed_at' => '2026-10-01T10:00:00Z',
            'object' => ['domain' => 'wp.bricks:components', 'instance_uid' => $uid],
            'projection' => ['profile' => 'bricks-components-v1', 'hash' => Canonicalizer::hash($body), 'exists' => true, 'complete' => true],
        ]);
        $this->assertSame(200, $this->as_environment($remote, 'POST', '/dbvc-agency/v1/observations', ['events' => [$event]])->get_status());
        $created = (new ReleaseService())->create($remote['environment_id'], [['domain' => 'wp.bricks:components', 'instance_uid' => $uid, 'operation' => 'create']]);
        $this->assertIsArray($created, is_wp_error($created) ? $created->get_error_message() : '');
        $release_uid = $created['release']['release_uid'];
        $sealed = $this->as_environment($remote, 'POST', '/dbvc-agency/v1/releases/' . $release_uid . '/payloads', ['items' => [['domain' => 'wp.bricks:components', 'instance_uid' => $uid, 'profile' => 'bricks-components-v1', 'body' => $body]]]);
        $this->assertTrue($sealed->get_data()['sealed'], wp_json_encode($sealed->get_data()));

        $requested = (new PreparationService())->request($release_uid, $local['environment_id']);
        $this->run_release();
        $prep = (new HubPreparationStore())->find($requested['operation_id']);
        $this->assertSame('ready', $prep['outcome'], wp_json_encode($prep));
        $this->assertSame('create', $prep['receipt']['items'][0]['operation']);

        $approval = (new ApprovalService())->approve($requested['operation_id'], 'ship component');
        $this->assertIsArray($approval, is_wp_error($approval) ? $approval->get_error_message() : '');
        $summary = $this->run_release();
        $this->assertSame('applied', $summary['operations'][0]['outcome'], wp_json_encode($summary['operations'][0] ?? []));

        // The component landed with its framework id (fleet-identical) and its class reference
        // was REMAPPED from the portable token to the target's local class id.
        $components = get_option('bricks_components');
        $this->assertCount(1, $components);
        $this->assertSame('cmp777', $components[0]['id'], 'Create-verbatim keeps the framework component id.');
        $this->assertSame(['local9'], $components[0]['elements'][0]['settings']['_cssGlobalClasses'], 'The class reference was detokenized to the target local id.');

        // It adopted the framework identity, so a re-push is a noop (idempotent, never a duplicate).
        $mapping = (new InstanceIdentity())->find_by_instance_uid('wp.bricks:components', $uid);
        $this->assertNotNull($mapping);
        $this->assertSame('cmp777', $mapping['storage_key']);
    }

    public function test_component_create_into_an_absent_components_option_adds_it_remaps_refs_and_rolls_back(): void
    {
        $local = $this->enroll_local();
        $remote = $this->enroll_remote('client-a', 'studio-src');
        update_option(\Dbvc\Connected\Adapters\DomainRegistry::OPTION_BRICKS_DOMAINS, ['components']);
        \Dbvc\Connected\Adapters\DomainRegistry::reset_observers();
        $this->assertIsArray((new SubscriptionService())->subscribe_client('studio-src', $local['environment_id'], ['wp.bricks:components']));
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1', DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED => '1']);

        // The referenced class exists at the target's own local id, but the components
        // option has NEVER been created — the create must add the option row itself and
        // still remap the component's class reference to the local id.
        update_option('bricks_global_classes', [['id' => 'local9', 'name' => 'team-card']]);
        delete_option('bricks_components');
        $this->assertFalse(get_option('bricks_components'), 'Precondition: the components option is absent.');

        $token = \Dbvc\Connected\Preparation\ReferenceTokens::token('class', 'team-card');
        $component = ['id' => 'cmp888', 'category' => 'Cards', 'elements' => [
            ['id' => 'cmp888', 'name' => 'block', 'label' => 'promo-card', 'settings' => ['_cssGlobalClasses' => [$token]]],
        ]];
        $body = Canonicalizer::encode($component);
        $uid = 'bricks-component-cmp888';

        $event = ObservationEvent::build([
            'event_id' => 'obs-comp-000009', 'environment_id' => $remote['environment_id'], 'installation_epoch' => $remote['epoch'], 'sequence' => 1, 'observed_at' => '2026-10-01T10:00:00Z',
            'object' => ['domain' => 'wp.bricks:components', 'instance_uid' => $uid],
            'projection' => ['profile' => 'bricks-components-v1', 'hash' => Canonicalizer::hash($body), 'exists' => true, 'complete' => true],
        ]);
        $this->assertSame(200, $this->as_environment($remote, 'POST', '/dbvc-agency/v1/observations', ['events' => [$event]])->get_status());
        $created = (new ReleaseService())->create($remote['environment_id'], [['domain' => 'wp.bricks:components', 'instance_uid' => $uid, 'operation' => 'create']]);
        $this->assertIsArray($created, is_wp_error($created) ? $created->get_error_message() : '');
        $release_uid = $created['release']['release_uid'];
        $sealed = $this->as_environment($remote, 'POST', '/dbvc-agency/v1/releases/' . $release_uid . '/payloads', ['items' => [['domain' => 'wp.bricks:components', 'instance_uid' => $uid, 'profile' => 'bricks-components-v1', 'body' => $body]]]);
        $this->assertTrue($sealed->get_data()['sealed'], wp_json_encode($sealed->get_data()));

        $requested = (new PreparationService())->request($release_uid, $local['environment_id']);
        $this->run_release();
        $prep = (new HubPreparationStore())->find($requested['operation_id']);
        $this->assertSame('ready', $prep['outcome'], wp_json_encode($prep));
        $this->assertNull($prep['receipt']['items'][0]['storage_fingerprint'], 'An absent components option → null container fingerprint.');

        $approval = (new ApprovalService())->approve($requested['operation_id'], 'ship component into absent');
        $this->assertIsArray($approval, is_wp_error($approval) ? $approval->get_error_message() : '');
        $summary = $this->run_release();
        $this->assertSame('applied', $summary['operations'][0]['outcome'], wp_json_encode($summary['operations'][0] ?? []));

        // The components option was created; the component landed verbatim and its class
        // reference was remapped from the portable token to the target's local id.
        $components = get_option('bricks_components');
        $this->assertIsArray($components, 'The apply added the components option.');
        $this->assertCount(1, $components);
        $this->assertSame('cmp888', $components[0]['id']);
        $this->assertSame(['local9'], $components[0]['elements'][0]['settings']['_cssGlobalClasses'], 'The class reference was detokenized to the local id.');

        // Reviewed rollback removes the created components option entirely (net-zero).
        $this->run_release(); // report the apply so its approval is consumed
        $rollback = (new ApprovalService())->rollback($requested['operation_id'], 'undo component into absent');
        $this->assertIsArray($rollback, is_wp_error($rollback) ? $rollback->get_error_message() : '');
        $summary = $this->run_release();
        $this->assertSame('restored', $summary['operations'][0]['outcome'], wp_json_encode($summary['operations'][0] ?? []));
        $this->assertFalse(get_option('bricks_components'), 'Rollback deletes the components option it created.');
    }

    public function test_global_class_background_media_sideloads_onto_a_target_that_lacks_it_and_rolls_back(): void
    {
        $uids = $this->local_uids();

        // Capture the SOURCE projection of class aaa111 carrying a background image, plus
        // its transport media bundle (bytes), while the attachment still exists.
        $bytes = 'CLASS-BACKGROUND-IMAGE-BYTES';
        $hash = hash('sha256', $bytes);
        $upload = wp_upload_bits('class-bg.png', null, $bytes);
        $this->assertEmpty($upload['error'] ?? '', (string) ($upload['error'] ?? ''));
        $att = wp_insert_attachment(['post_mime_type' => (string) $upload['type'], 'post_status' => 'inherit'], (string) $upload['file']);

        $target_before = get_option(self::CLASSES);
        $with_image = $target_before;
        foreach ($with_image as $i => $c) {
            if (($c['id'] ?? '') === 'aaa111') {
                $with_image[$i]['settings']['_background']['image'] = ['id' => $att, 'url' => (string) wp_get_attachment_url($att), 'size' => 'full', 'filename' => 'class-bg.png'];
            }
        }
        update_option(self::CLASSES, $with_image);
        \Dbvc\Connected\Adapters\DomainRegistry::reset_observers();
        $observer = \Dbvc\Connected\Adapters\DomainRegistry::observer_for('bricks.global_class');
        $snap = $observer->snapshot(['storage_key' => 'aaa111']);
        $this->assertSame('available', $snap['status'], wp_json_encode($snap));
        $after_body = (string) $snap['canonical'];
        $this->assertStringContainsString(\Dbvc\Connected\Preparation\BricksMediaReferences::TOKEN_PREFIX . $hash, $after_body, 'The class background image id is tokenized by content hash.');
        $bundle = $observer->collect_transport_media(json_decode($after_body, true));
        $this->assertCount(1, $bundle['media'], 'The class background image bytes ride the media channel.');

        // The target: restore aaa111 WITHOUT the image and drop the attachment bytes.
        update_option(self::CLASSES, $target_before);
        wp_delete_attachment($att, true);
        \Dbvc\Connected\Adapters\DomainRegistry::reset_observers();
        $this->run_observation();
        $this->assertSame(0, \Dbvc\Connected\Preparation\MediaReferences::local_by_hash($hash), 'The target lacks the image bytes.');

        $local = $this->enroll_local();
        $remote = $this->enroll_remote('client-a', 'studio-src');
        (new SubscriptionService())->subscribe_client('studio-src', $local['environment_id'], []);
        $this->report_and_seal($remote, [['domain' => 'bricks.global_class', 'instance_uid' => $uids['aaa111'], 'profile' => 'bricks-global-class-v1', 'body' => $after_body, 'media' => $bundle['media']]], $release_uid);

        $requested = (new PreparationService())->request($release_uid, $local['environment_id']);
        $this->assertSame(1, $this->run_release()['receipts_stored']);
        $this->assertSame('ready', (new HubPreparationStore())->find($requested['operation_id'])['outcome'], 'A class carrying media the target lacks prepares READY via the carried bytes.');

        (new ApprovalService())->approve($requested['operation_id'], 'go');
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1', DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED => '1']);
        $summary = $this->run_release();
        $this->assertSame('applied', $summary['operations'][0]['outcome'], wp_json_encode($summary['operations'][0] ?? []));

        // The class now references a REAL local attachment (sideloaded), not a literal token.
        $applied = null;
        foreach (get_option(self::CLASSES) as $c) {
            if (($c['id'] ?? '') === 'aaa111') {
                $applied = $c;
            }
        }
        $this->assertNotNull($applied);
        $new_att = (int) ($applied['settings']['_background']['image']['id'] ?? 0);
        $this->assertGreaterThan(0, $new_att, 'The image id resolved to a local attachment.');
        $this->assertSame('attachment', get_post_type($new_att));
        $this->assertSame($new_att, \Dbvc\Connected\Preparation\MediaReferences::local_by_hash($hash), 'The sideloaded attachment is indexed by the content hash.');

        // Reviewed rollback: the class returns to no background image and the sideloaded attachment is deleted (net-zero).
        $rollback = (new ApprovalService())->rollback($requested['operation_id'], 'undo');
        $this->assertIsArray($rollback, is_wp_error($rollback) ? $rollback->get_error_message() : '');
        $this->assertSame('restored', $this->run_release()['operations'][0]['outcome']);
        $restored = null;
        foreach (get_option(self::CLASSES) as $c) {
            if (($c['id'] ?? '') === 'aaa111') {
                $restored = $c;
            }
        }
        $this->assertArrayNotHasKey('image', $restored['settings']['_background'] ?? [], 'The background image reference is gone after rollback.');
        $this->assertNull(get_post($new_att), 'The sideloaded attachment was deleted by rollback (net-zero).');
    }

    private function report_and_seal_create(array $remote, string $instance_uid, string $body, int $sequence): string
    {
        $event = ObservationEvent::build([
            'event_id' => 'obs-' . str_pad((string) $sequence, 12, '0', STR_PAD_LEFT), 'environment_id' => $remote['environment_id'], 'installation_epoch' => $remote['epoch'], 'sequence' => $sequence, 'observed_at' => '2026-09-28T10:00:00Z',
            'object' => ['domain' => 'bricks.global_class', 'instance_uid' => $instance_uid],
            'projection' => ['profile' => 'bricks-global-class-v1', 'hash' => Canonicalizer::hash($body), 'exists' => true, 'complete' => true],
        ]);
        $this->assertSame(200, $this->as_environment($remote, 'POST', '/dbvc-agency/v1/observations', ['events' => [$event]])->get_status());
        $created = (new ReleaseService())->create($remote['environment_id'], [['domain' => 'bricks.global_class', 'instance_uid' => $instance_uid, 'operation' => 'create']]);
        $this->assertIsArray($created, is_wp_error($created) ? $created->get_error_message() : '');
        $release_uid = $created['release']['release_uid'];
        $sealed = $this->as_environment($remote, 'POST', '/dbvc-agency/v1/releases/' . $release_uid . '/payloads', ['items' => [['domain' => 'bricks.global_class', 'instance_uid' => $instance_uid, 'profile' => 'bricks-global-class-v1', 'body' => $body]]]);
        $this->assertTrue($sealed->get_data()['sealed'], wp_json_encode($sealed->get_data()));

        return $release_uid;
    }

    private function report_and_seal(array $remote, array $bodies, &$release_uid, int $sequence_base = 0): void
    {
        $events = [];
        foreach ($bodies as $index => $body) {
            $events[] = ObservationEvent::build([
                'event_id' => 'obs-' . str_pad((string) ($sequence_base + $index + 1), 12, '0', STR_PAD_LEFT), 'environment_id' => $remote['environment_id'], 'installation_epoch' => $remote['epoch'], 'sequence' => $sequence_base + $index + 1, 'observed_at' => '2026-09-19T10:00:00Z',
                'object' => ['domain' => $body['domain'], 'instance_uid' => $body['instance_uid']],
                'projection' => ['profile' => $body['profile'], 'hash' => Canonicalizer::hash($body['body']), 'exists' => true, 'complete' => true],
            ]);
        }
        $response = $this->as_environment($remote, 'POST', '/dbvc-agency/v1/observations', ['events' => $events]);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $created = (new ReleaseService())->create($remote['environment_id'], array_map(static function ($body) {
            return ['domain' => $body['domain'], 'instance_uid' => $body['instance_uid']];
        }, $bodies));
        $this->assertIsArray($created, is_wp_error($created) ? $created->get_error_message() : '');
        $release_uid = $created['release']['release_uid'];
        $response = $this->as_environment($remote, 'POST', '/dbvc-agency/v1/releases/' . $release_uid . '/payloads', ['items' => array_map(static function ($body) {
            $item = ['domain' => $body['domain'], 'instance_uid' => $body['instance_uid'], 'profile' => $body['profile'], 'body' => $body['body']];
            if (isset($body['media'])) {
                $item['media'] = $body['media'];
            }
            return $item;
        }, $bodies)]);
        $this->assertTrue($response->get_data()['sealed'], wp_json_encode($response->get_data()));
    }

    private function raw_option(): string
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::CLASSES));
    }

    /**
     * @return array{environment_id: string, epoch: string}
     */
    private function enroll_local(): array
    {
        $invite = (new HubEnrollment())->invite(['client_id' => 'client-a', 'environment_id' => 'a-stage']);
        $result = (new EnrollmentService())->exchange(self::HUB, $invite['token']);
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->run_observation();
        DBVC_Connected_Environments_Addon::runtime()->outbound()->run(['context' => 'test']);

        return ['environment_id' => $result['environment_id'], 'epoch' => $result['installation_epoch']];
    }

    /**
     * @return array<string, mixed>
     */
    private function enroll_remote(string $client_id, string $environment_id): array
    {
        $invite = (new HubEnrollment())->invite(['client_id' => $client_id, 'environment_id' => $environment_id]);
        $request = new WP_REST_Request('POST', '/dbvc-agency/v1/enrollments/exchange');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(['token' => $invite['token'], 'environment_id' => $environment_id, 'site_url' => 'https://' . $environment_id . '.example/', 'protocol_versions' => [Protocol::PROTOCOL_VERSION]]));
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $data = $response->get_data();

        return ['environment_id' => $data['environment_id'], 'epoch' => $data['installation_epoch'], 'user_id' => get_user_by('login', $data['principal']['username'])->ID, 'uuid' => $data['principal']['uuid']];
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
     * @return array<string, string>
     */
    private function local_uids(): array
    {
        $map = [];
        foreach ((new InstanceIdentity())->all_for_domain('bricks.global_class') as $storage_key => $row) {
            $map[$storage_key] = $row['instance_uid'];
        }

        return $map;
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
    private function run_release(): array
    {
        return DBVC_Connected_Environments_Addon::runtime()->release()->run(['context' => 'test']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function classes_fixture(): array
    {
        return [
            ['id' => 'aaa111', 'name' => '--flex-col', 'settings' => ['_direction' => 'column'], 'cat' => false],
            ['id' => 'bbb222', 'name' => '--flex-row', 'settings' => ['_direction' => 'row', '_display' => 'flex']],
            ['id' => 'ccc333', 'name' => '--bg-gradient-light', 'settings' => ['_gradient' => ['colors' => [['id' => 'vypvsh', 'stop' => '0'], ['id' => 'wjtzex', 'stop' => '100']]]], 'cat' => 'rgxlvq'],
        ];
    }
}
