<?php

use Dbvc\AgencyControl\Notifications\DriftNotifier;
use Dbvc\AgencyControl\Notifications\NotificationService;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;

/**
 * M9 slice 7d-2 — the scheduled drift notifier. Client drift has no event of its
 * own; this scanner turns each `local_drift` framework-status row into a deduped
 * `site.drifted` notification.
 *
 * @group connected-environments
 */
class ConnectedDriftNotifyTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        HubSchema::reset_memo();
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
    }

    public function tear_down(): void
    {
        DBVC_Agency_Control_Addon::save_settings([]);
        remove_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        HubSchema::reset_memo();
        parent::tear_down();
    }

    public function savepoint_strategy(): string
    {
        return 'savepoint';
    }

    public function test_scan_enqueues_site_drifted_for_local_drift_rows_and_dedupes(): void
    {
        (new EnvironmentRegistry())->create([
            'agency_id' => 'ag-1', 'client_id' => 'cl-1', 'environment_id' => 'env-d',
            'current_epoch' => 'ep-1', 'principal_user_id' => 1, 'principal_uuid' => 'uuid-d',
        ]);
        $svc = new NotificationService();
        $svc->save_channel(['type' => 'webhook', 'target' => 'https://93.184.216.34/hook', 'agency_id' => 'ag-1', 'events' => ['site.drifted']]);

        $rows = [
            ['environment_id' => 'env-d', 'client_id' => 'cl-1', 'definition_uid' => 'def-1', 'domain' => 'bricks.global_class', 'instance_uid' => 'u1', 'drift' => 'local_drift'],
            ['environment_id' => 'env-d', 'client_id' => 'cl-1', 'definition_uid' => 'def-1', 'domain' => 'bricks.global_class', 'instance_uid' => 'u2', 'drift' => 'clean'],
        ];
        $notifier = new DriftNotifier($svc, static function () use ($rows) {
            return $rows;
        });

        $first = $notifier->scan();
        $this->assertSame(1, $first['drifted'], 'one local_drift row');
        $this->assertSame(1, $first['queued'], 'one channel queued');

        $second = $notifier->scan();
        $this->assertSame(1, $second['drifted']);
        $this->assertSame(0, $second['queued'], 'same drift signature deduped');

        $log = $svc->log_entries(50, 'pending');
        $drifted = array_values(array_filter($log, static function ($r) {
            return $r['event_kind'] === 'site.drifted';
        }));
        $this->assertCount(1, $drifted);
        $this->assertSame('env-d|def-1|bricks.global_class|u1|local_drift', $drifted[0]['dedupe_key']);
    }

    public function test_no_matching_channel_queues_nothing(): void
    {
        (new EnvironmentRegistry())->create([
            'agency_id' => 'ag-9', 'client_id' => 'cl-9', 'environment_id' => 'env-x',
            'current_epoch' => 'ep-1', 'principal_user_id' => 1, 'principal_uuid' => 'uuid-x',
        ]);
        // A channel for a DIFFERENT agency.
        (new NotificationService())->save_channel(['type' => 'email', 'target' => 'ops@example.com', 'agency_id' => 'ag-other', 'events' => []]);

        $notifier = new DriftNotifier(new NotificationService(), static function () {
            return [['environment_id' => 'env-x', 'definition_uid' => 'def-9', 'domain' => 'd', 'instance_uid' => 'i', 'drift' => 'local_drift']];
        });
        $result = $notifier->scan();
        $this->assertSame(1, $result['drifted']);
        $this->assertSame(0, $result['queued'], 'no ag-9 or fleet-wide channel to receive it');
    }
}
