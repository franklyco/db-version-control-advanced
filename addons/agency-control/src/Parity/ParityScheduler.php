<?php

namespace Dbvc\AgencyControl\Parity;

use Dbvc\AgencyControl\Storage\Schema;
use Dbvc\AgencyControl\Storage\SyncPolicyStore;

/**
 * Hub WP-Cron driver for auto parity (M8 slice 4). One base tick (hourly, the
 * event {@see HOOK}) advances every enabled `auto` policy that is either mid-cycle
 * (a release/rollout in flight) or due by its per-policy cadence. `manual` cadence
 * is never cron-driven — the operator uses "Sync now" / "Advance". "Sync now" is
 * always available regardless of this schedule.
 *
 * Production reliability of WP-Cron on an always-on hub (a non-laptop scheduler:
 * system cron or an external ping to wp-cron.php) is the standing operational
 * concern noted in the design; this class wires the event and the manual action.
 */
final class ParityScheduler
{
    public const HOOK = 'dbvc_agency_parity_tick';

    /**
     * @var array<string, int> cadence slug => seconds between new cycles.
     */
    private const CADENCE_SECONDS = [
        'every15' => 900,
        'hourly' => 3600,
        'daily' => 86400,
    ];

    /**
     * Schedule the base tick once (idempotent). Called from the hub Runtime while
     * enabled; the recurring timer is not reset on subsequent requests.
     *
     * @return void
     */
    public static function schedule()
    {
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::HOOK);
        }
    }

    /**
     * Remove the scheduled tick (called on explicit disable).
     *
     * @return void
     */
    public static function unschedule()
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Cron callback.
     *
     * @return void
     */
    public static function run()
    {
        self::tick();
    }

    /**
     * Advance every enabled auto policy that is in flight or due. Errors on one
     * policy never stop the others. Returns a summary (also useful for tests/CLI).
     *
     * @return array<string, mixed>
     */
    public static function tick()
    {
        if (! Schema::is_ready()) {
            return ['ran' => 0, 'skipped' => 0, 'results' => []];
        }
        $store = new SyncPolicyStore();
        $driver = new SyncDriver();
        $ran = 0;
        $skipped = 0;
        $results = [];
        foreach ($store->all(null, null, 500) as $policy) {
            if (! $policy['enabled'] || $policy['mode'] !== 'auto' || $policy['cadence'] === 'manual') {
                $skipped++;
                continue;
            }
            $in_flight = $policy['active_release_uid'] !== '' || $policy['active_rollout_uid'] !== '';
            if (! $in_flight && ! self::is_due($policy)) {
                $skipped++;
                continue;
            }
            $result = $driver->advance($policy['source_environment_id'], $policy['target_environment_id']);
            $ran++;
            $results[] = [
                'source' => $policy['source_environment_id'],
                'target' => $policy['target_environment_id'],
                'outcome' => is_wp_error($result) ? ('error:' . $result->get_error_code()) : (string) $result['note'],
            ];
        }

        // Heartbeat so an operator can confirm this tick actually fired (WP-Cron is
        // request-gated and can silently stall on a quiet hub).
        \Dbvc\AgencyControl\Scheduler\SchedulerHeartbeat::record('parity', [
            'ran' => $ran,
            'skipped' => $skipped,
        ]);

        return ['ran' => $ran, 'skipped' => $skipped, 'results' => $results];
    }

    /**
     * @param array<string, mixed> $policy
     * @return bool
     */
    private static function is_due(array $policy)
    {
        if ($policy['last_run_at'] === '') {
            return true;
        }
        $interval = self::CADENCE_SECONDS[$policy['cadence']] ?? 3600;
        $last = strtotime((string) $policy['last_run_at'] . ' GMT');

        return $last === false || ($last + $interval) <= time();
    }
}
