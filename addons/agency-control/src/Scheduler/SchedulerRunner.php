<?php

namespace Dbvc\AgencyControl\Scheduler;

use Dbvc\AgencyControl\Framework\FrameworkRolloutScheduler;
use Dbvc\AgencyControl\Parity\ParityScheduler;
use Dbvc\AgencyControl\Storage\Schema;

/**
 * One entrypoint that runs every hub tick in a single call, for an external
 * scheduler.
 *
 * The reliable-hub recipe is `define('DISABLE_WP_CRON', true)` plus a system
 * crontab line `* * * * * cd <wp> && wp dbvc agency scheduler-run`, so the hourly
 * parity and framework-rollout ticks no longer depend on a page being visited.
 * Each underlying tick stays idempotent and records its heartbeat, so this is
 * safe to call at any cadence; the framework tick also scans drift and flushes
 * notifications.
 */
final class SchedulerRunner
{
    /**
     * Run both hub ticks once. A tick already contains its own per-item errors;
     * this additionally guards against a hard failure in one tick so it can never
     * prevent the other from running.
     *
     * @return array<string, mixed>
     */
    public static function run_all()
    {
        if (! Schema::is_ready()) {
            return ['ready' => false, 'parity' => null, 'framework' => null];
        }

        $parity = null;
        $framework = null;
        try {
            $parity = ParityScheduler::tick();
        } catch (\Throwable $e) {
            $parity = ['error' => $e->getMessage()];
        }
        try {
            $framework = FrameworkRolloutScheduler::tick();
        } catch (\Throwable $e) {
            $framework = ['error' => $e->getMessage()];
        }

        return ['ready' => true, 'parity' => $parity, 'framework' => $framework];
    }

    /**
     * Health of both hub schedulers (backing `scheduler-status`).
     *
     * @return array<string, mixed>
     */
    public static function health()
    {
        return [
            'parity' => SchedulerHeartbeat::health('parity', ParityScheduler::HOOK),
            'framework' => SchedulerHeartbeat::health('framework', FrameworkRolloutScheduler::HOOK),
        ];
    }
}
