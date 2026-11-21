<?php

use Dbvc\AgencyControl\Notifications\ChangeReportNotifier;
use Dbvc\AgencyControl\Notifications\NotificationService;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;

/**
 * Change report CR-4a — the three report-mode events become deduped
 * notifications on the 7d-1 backbone: one per report, one per withdrawn
 * release, and one per stale EPISODE (keyed on the silent side's last contact).
 *
 * @group connected-environments
 */
class ConnectedChangeReportNotifyTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        HubSchema::reset_memo();
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        ChangeReportNotifier::unregister();
    }

    public function tear_down(): void
    {
        ChangeReportNotifier::unregister();
        DBVC_Agency_Control_Addon::save_settings([]);
        remove_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        HubSchema::reset_memo();
        parent::tear_down();
    }

    public function savepoint_strategy(): string
    {
        return 'savepoint';
    }

    /**
     * @return array<string, mixed>
     */
    private function policy(): array
    {
        return ['agency_id' => 'ag-1', 'client_id' => 'cl-1', 'source_environment_id' => 'stg', 'target_environment_id' => 'loc', 'mode' => 'report'];
    }

    /**
     * @param string[] $events
     * @return NotificationService
     */
    private function service_with_channel(array $events): NotificationService
    {
        $svc = new NotificationService();
        $ch = $svc->save_channel(['type' => 'webhook', 'target' => 'https://93.184.216.34/hook', 'agency_id' => 'ag-1', 'events' => $events]);
        $this->assertIsArray($ch, is_wp_error($ch) ? $ch->get_error_message() : 'channel');

        return $svc;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pending(NotificationService $svc, string $kind): array
    {
        return array_values(array_filter($svc->log_entries(100, 'pending'), static function ($row) use ($kind) {
            return $row['event_kind'] === $kind;
        }));
    }

    public function test_generated_report_is_announced_once_with_safe_metadata(): void
    {
        $svc = $this->service_with_channel([ChangeReportNotifier::KIND_GENERATED]);
        $notifier = new ChangeReportNotifier($svc);
        $report = [
            'report_uid' => 'rep-1', 'agency_id' => 'ag-1', 'client_id' => 'cl-1',
            'source_environment_id' => 'stg', 'target_environment_id' => 'loc', 'release_uid' => 'rel-1',
            'state' => 'ready', 'item_count' => 5, 'values_included' => true, 'generated_at' => '2026-11-15 10:00:00',
            'counts' => ['created' => 1, 'updated' => 1, 'removed' => 1, 'conflict' => 1, 'local_only' => 1, 'converged' => 0, 'truncated_items' => 0],
        ];

        $this->assertSame(1, $notifier->on_generated($report, $this->policy()));
        $this->assertSame(0, $notifier->on_generated($report, $this->policy()), 'the same report is not announced twice');
        $this->assertSame(1, $notifier->on_generated(['report_uid' => 'rep-2', 'agency_id' => 'ag-1'] + $report, $this->policy()), 'a new report is');
        $this->assertSame(0, $notifier->on_generated(['no' => 'uid'], $this->policy()), 'no report_uid, no notification');

        $rows = $this->pending($svc, ChangeReportNotifier::KIND_GENERATED);
        $this->assertCount(2, $rows);
        $this->assertSame(['rep-2', 'rep-1'], array_column($rows, 'dedupe_key'));

        global $wpdb;
        $payload = json_decode((string) $wpdb->get_var($wpdb->prepare('SELECT payload FROM ' . HubSchema::table('notification_log') . ' WHERE dedupe_key = %s', 'rep-1')), true); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->assertSame([
            'report_uid' => 'rep-1', 'source_environment_id' => 'stg', 'target_environment_id' => 'loc', 'client_id' => 'cl-1',
            'release_uid' => 'rel-1', 'state' => 'ready', 'item_count' => 5,
            'counts' => ['created' => 1, 'updated' => 1, 'removed' => 1, 'conflict' => 1, 'local_only' => 1, 'converged' => 0],
            'values_included' => true, 'generated_at' => '2026-11-15 10:00:00',
        ], $payload, 'safe metadata only — no bodies, paths or values');
    }

    public function test_unstable_source_is_announced_once_per_withdrawn_release(): void
    {
        $svc = $this->service_with_channel([ChangeReportNotifier::KIND_UNSTABLE]);
        $notifier = new ChangeReportNotifier($svc);

        $this->assertSame(1, $notifier->on_unstable_source($this->policy(), ['release_uid' => 'rel-a'], 3));
        $this->assertSame(0, $notifier->on_unstable_source($this->policy(), ['release_uid' => 'rel-a'], 3));
        $this->assertSame(1, $notifier->on_unstable_source($this->policy(), ['release_uid' => 'rel-b'], 3));

        $rows = $this->pending($svc, ChangeReportNotifier::KIND_UNSTABLE);
        $this->assertSame(['stg|loc|rel-b', 'stg|loc|rel-a'], array_column($rows, 'dedupe_key'));
    }

    public function test_stale_side_is_announced_once_per_episode_keyed_on_last_contact(): void
    {
        $svc = $this->service_with_channel([ChangeReportNotifier::KIND_STALE]);
        $notifier = new ChangeReportNotifier($svc);
        $outage_one = ['source' => ['fresh' => true, 'last_contact_at' => '2026-11-15 09:00:00'], 'target' => ['fresh' => false, 'last_contact_at' => '2026-11-13 22:10:00']];

        // Every idle tick during the outage fires the action; only the first queues.
        $this->assertSame(1, $notifier->on_stale($this->policy(), 'target', $outage_one));
        $this->assertSame(0, $notifier->on_stale($this->policy(), 'target', $outage_one));
        $this->assertSame(0, $notifier->on_stale($this->policy(), 'target', $outage_one));

        // The side reports again, then goes quiet later: a new last_contact_at = a new episode.
        $outage_two = $outage_one;
        $outage_two['target']['last_contact_at'] = '2026-11-16 08:00:00';
        $this->assertSame(1, $notifier->on_stale($this->policy(), 'target', $outage_two));

        // The other side going stale is its own key.
        $this->assertSame(1, $notifier->on_stale($this->policy(), 'source', $outage_one));

        // CR-4d: when the comparison carries last_seen_at (poll or observation), that is the episode key.
        $polled = $outage_one;
        $polled['target']['last_seen_at'] = '2026-11-14 06:30:00';
        $this->assertSame(1, $notifier->on_stale($this->policy(), 'target', $polled));
        $this->assertSame(0, $notifier->on_stale($this->policy(), 'target', $polled));

        $rows = $this->pending($svc, ChangeReportNotifier::KIND_STALE);
        $this->assertSame(
            ['stg|loc|target|2026-11-14 06:30:00', 'stg|loc|source|2026-11-15 09:00:00', 'stg|loc|target|2026-11-16 08:00:00', 'stg|loc|target|2026-11-13 22:10:00'],
            array_column($rows, 'dedupe_key')
        );
    }

    public function test_channels_subscribe_per_kind_and_an_empty_list_takes_all(): void
    {
        $svc = new NotificationService();
        $svc->save_channel(['type' => 'webhook', 'target' => 'https://93.184.216.34/only-generated', 'agency_id' => 'ag-1', 'events' => [ChangeReportNotifier::KIND_GENERATED]]);
        $svc->save_channel(['type' => 'email', 'target' => 'ops@example.com', 'agency_id' => '', 'events' => []]);
        $notifier = new ChangeReportNotifier($svc);

        $this->assertSame(2, $notifier->on_generated(['report_uid' => 'rep-9', 'agency_id' => 'ag-1'], $this->policy()), 'both channels');
        $this->assertSame(1, $notifier->on_stale($this->policy(), 'target', []), 'only the catch-all channel');
        $this->assertSame(1, $notifier->on_unstable_source($this->policy(), ['release_uid' => 'r'], 3), 'only the catch-all channel');
    }

    public function test_register_binds_the_three_actions_and_unregister_releases_them(): void
    {
        $this->service_with_channel([]);
        ChangeReportNotifier::register();
        ChangeReportNotifier::register(); // idempotent

        do_action('dbvc_agency_change_report_generated', ['report_uid' => 'rep-h', 'agency_id' => 'ag-1'], $this->policy());
        do_action('dbvc_agency_report_unstable_source', $this->policy(), ['release_uid' => 'rel-h'], 3);
        do_action('dbvc_agency_report_stale', $this->policy(), 'source', ['source' => ['last_contact_at' => '2026-11-10 00:00:00']]);

        $svc = new NotificationService();
        $kinds = array_column($svc->log_entries(100, 'pending'), 'event_kind');
        sort($kinds);
        $this->assertSame([ChangeReportNotifier::KIND_GENERATED, ChangeReportNotifier::KIND_STALE, ChangeReportNotifier::KIND_UNSTABLE], $kinds);

        ChangeReportNotifier::unregister();
        do_action('dbvc_agency_change_report_generated', ['report_uid' => 'rep-after', 'agency_id' => 'ag-1'], $this->policy());
        $this->assertCount(3, $svc->log_entries(100, 'pending'), 'nothing queued once unregistered');
    }
}
