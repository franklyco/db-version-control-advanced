<?php

namespace Dbvc\AgencyControl\Framework;

use Dbvc\AgencyControl\Storage\FrameworkRolloutStore;
use Dbvc\AgencyControl\Storage\Schema;

/**
 * Hub WP-Cron driver for framework rollouts (M9 slice 4e). One base tick
 * (hourly, the event {@see HOOK}) advances every RUNNING campaign one step —
 * building each active-cohort target's release, creating its rollout once the
 * source seals, and reading outcomes — so a fleet push completes hands-off. A
 * PAUSED campaign (a cohort failed) is never advanced by cron; the operator
 * reviews it and resumes/retries/withdraws. Manual `framework-rollout-advance`
 * stays available regardless of this schedule (and is the way to accelerate a
 * push, since the connectors' own polls pace delivery/apply).
 *
 * As with parity, WP-Cron reliability on an always-on hub (a non-laptop
 * scheduler) is the standing operational note; this class wires the event.
 */
final class FrameworkRolloutScheduler
{
    public const HOOK = 'dbvc_agency_framework_rollout_tick';

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
     * Advance every running campaign one step. Errors on one campaign never stop
     * the others. Returns a summary (also useful for tests/CLI).
     *
     * @return array<string, mixed>
     */
    public static function tick()
    {
        if (! Schema::is_ready()) {
            return ['ran' => 0, 'results' => []];
        }
        $driver = new FrameworkRolloutService();
        $ran = 0;
        $results = [];
        foreach ((new FrameworkRolloutStore())->active(500) as $campaign) {
            $result = $driver->advance((string) $campaign['campaign_uid']);
            $ran++;
            $results[] = [
                'campaign_uid' => (string) $campaign['campaign_uid'],
                'outcome' => is_wp_error($result) ? ('error:' . $result->get_error_code()) : (string) $result['state'],
            ];
        }

        // M9 slice 7d: scan for client drift (no event of its own) and enqueue,
        // then flush everything the advances + the scan enqueued. Best-effort on
        // the hub's existing hourly tick.
        $drift = ['drifted' => 0, 'queued' => 0];
        $flushed = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        try {
            $drift = (new \Dbvc\AgencyControl\Notifications\DriftNotifier())->scan(200);
            $flushed = (new \Dbvc\AgencyControl\Notifications\NotificationService())->flush(50);
            // Post-M9 A-2a: reclaim media blobs no release item references any more
            // (orphaned by withdrawn/pruned releases). Never removes a live blob.
            \Dbvc\AgencyControl\Storage\MediaBlobStore::gc();
        } catch (\Throwable $e) {
            unset($e);
        }

        // Heartbeat so an operator can confirm this tick actually fired (WP-Cron is
        // request-gated and can silently stall on a quiet hub).
        \Dbvc\AgencyControl\Scheduler\SchedulerHeartbeat::record('framework', [
            'ran' => $ran,
            'drift_queued' => isset($drift['queued']) ? (int) $drift['queued'] : 0,
            'flushed_sent' => isset($flushed['sent']) ? (int) $flushed['sent'] : 0,
        ]);

        return ['ran' => $ran, 'results' => $results, 'drift' => $drift, 'notifications_flushed' => $flushed];
    }
}
