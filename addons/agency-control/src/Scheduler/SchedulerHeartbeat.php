<?php

namespace Dbvc\AgencyControl\Scheduler;

/**
 * Records and reads a per-scheduler heartbeat so an operator can verify the hub
 * ticks actually ran.
 *
 * WP-Cron fires only when a request reaches the site, so on a quiet or asleep hub
 * the hourly parity and framework-rollout ticks can silently stop advancing a
 * fleet push — the standing operational concern noted on both schedulers. A
 * stale heartbeat makes that failure visible instead of silent.
 *
 * Stored as one autoload-off option (no schema), keyed by scheduler name, each
 * entry carrying the last-run UTC time and a compact summary. Both WP-Cron-driven
 * ticks and the `scheduler-run` CLI entrypoint record here.
 */
final class SchedulerHeartbeat
{
    public const OPTION = 'dbvc_ac_scheduler_heartbeat';

    /**
     * Age (seconds) beyond which a scheduler counts as overdue. The hub ticks run
     * hourly; two missed hours is the threshold so a single slipped tick does not
     * flap the status.
     */
    public const OVERDUE_AFTER = 7200;

    /**
     * Record a scheduler's completion.
     *
     * @param string $name Scheduler key ('parity'|'framework').
     * @param array<string, mixed> $summary Compact counts (kept small).
     * @return void
     */
    public static function record($name, array $summary)
    {
        $name = (string) $name;
        if ($name === '') {
            return;
        }
        $all = self::all();
        $all[$name] = [
            'at' => gmdate('c'),
            'summary' => $summary,
        ];
        update_option(self::OPTION, $all, false);
    }

    /**
     * All recorded heartbeats, keyed by scheduler name.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all()
    {
        $value = get_option(self::OPTION, []);

        return is_array($value) ? $value : [];
    }

    /**
     * Health view for one scheduler: last-run time, age, overdue flag, whether the
     * WP-Cron event is currently scheduled, its next run, and the last summary.
     *
     * A scheduler that has never recorded (no heartbeat) reads as overdue, which is
     * correct — nothing has run.
     *
     * @param string $name
     * @param string $hook WP-Cron hook whose next scheduled run to report.
     * @return array<string, mixed>
     */
    public static function health($name, $hook)
    {
        $all = self::all();
        $entry = isset($all[$name]) && is_array($all[$name]) ? $all[$name] : [];
        $at = isset($entry['at']) ? (string) $entry['at'] : '';
        $last = $at !== '' ? strtotime($at) : false;
        $age = is_int($last) ? max(0, time() - $last) : null;
        $next = function_exists('wp_next_scheduled') ? wp_next_scheduled((string) $hook) : false;

        return [
            'last_run_at' => $at,
            'age_seconds' => $age,
            'overdue' => $age === null ? true : ($age > self::OVERDUE_AFTER),
            'cron_scheduled' => $next !== false,
            'next_cron_run' => $next !== false ? gmdate('c', (int) $next) : null,
            'last_summary' => isset($entry['summary']) && is_array($entry['summary']) ? $entry['summary'] : [],
        ];
    }
}
