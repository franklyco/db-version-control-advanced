<?php

use Dbvc\AgencyControl\Framework\FrameworkRolloutScheduler;
use Dbvc\AgencyControl\Parity\ParityScheduler;
use Dbvc\AgencyControl\Scheduler\SchedulerHeartbeat;
use Dbvc\AgencyControl\Scheduler\SchedulerRunner;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;

/**
 * Phase A slice: a reliable hub scheduler. The `scheduler-run` entrypoint drives
 * every hub tick in one call for an external (non-WP-Cron) scheduler, and each
 * tick records a heartbeat so `scheduler-status` can show whether the ticks are
 * actually firing — WP-Cron is request-gated and can silently stall on a quiet
 * hub.
 *
 * @group connected-environments
 */
class ConnectedSchedulerRunnerTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        HubSchema::reset_memo();
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        HubSchema::install();
        delete_option(SchedulerHeartbeat::OPTION);
    }

    public function tear_down(): void
    {
        delete_option(SchedulerHeartbeat::OPTION);
        DBVC_Agency_Control_Addon::save_settings([]);
        HubSchema::reset_memo();
        parent::tear_down();
    }

    public function test_each_tick_records_a_heartbeat(): void
    {
        $this->assertTrue(HubSchema::is_ready(), 'Hub schema should be installed for this test.');
        $this->assertSame([], SchedulerHeartbeat::all(), 'No heartbeat before any tick.');

        ParityScheduler::tick();
        FrameworkRolloutScheduler::tick();

        $all = SchedulerHeartbeat::all();
        $this->assertArrayHasKey('parity', $all);
        $this->assertArrayHasKey('framework', $all);
        $this->assertNotSame('', (string) $all['parity']['at']);
        $this->assertNotSame('', (string) $all['framework']['at']);
        $this->assertArrayHasKey('ran', $all['parity']['summary']);
        $this->assertArrayHasKey('skipped', $all['parity']['summary']);
        $this->assertArrayHasKey('ran', $all['framework']['summary']);
    }

    public function test_run_all_runs_both_ticks_and_reports_healthy_when_ready(): void
    {
        $result = SchedulerRunner::run_all();

        $this->assertTrue($result['ready'], wp_json_encode($result));
        $this->assertIsArray($result['parity']);
        $this->assertIsArray($result['framework']);
        // Empty fleet: nothing to advance, but the ticks still ran and recorded.
        $this->assertSame(0, $result['parity']['ran']);
        $this->assertSame(0, $result['framework']['ran']);

        $health = SchedulerRunner::health();
        foreach (['parity', 'framework'] as $name) {
            $this->assertFalse($health[$name]['overdue'], "$name should be healthy right after a run.");
            $this->assertNotSame('', $health[$name]['last_run_at']);
            $this->assertNotNull($health[$name]['age_seconds']);
            $this->assertLessThan(SchedulerHeartbeat::OVERDUE_AFTER, $health[$name]['age_seconds']);
        }
    }

    public function test_run_all_returns_ready_false_when_schema_absent(): void
    {
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        HubSchema::reset_memo();
        $this->assertFalse(HubSchema::is_ready());

        $result = SchedulerRunner::run_all();

        $this->assertFalse($result['ready']);
        $this->assertNull($result['parity']);
        $this->assertNull($result['framework']);
        $this->assertSame([], SchedulerHeartbeat::all(), 'A not-ready run records nothing.');
    }

    public function test_health_reports_overdue_when_absent_or_stale(): void
    {
        // Never run: overdue, no timestamp.
        $health = SchedulerRunner::health();
        $this->assertTrue($health['parity']['overdue']);
        $this->assertTrue($health['framework']['overdue']);
        $this->assertSame('', $health['parity']['last_run_at']);
        $this->assertNull($health['parity']['age_seconds']);

        // A heartbeat older than the threshold reads as overdue.
        update_option(SchedulerHeartbeat::OPTION, [
            'parity' => ['at' => gmdate('c', time() - (SchedulerHeartbeat::OVERDUE_AFTER + 800)), 'summary' => ['ran' => 0]],
        ], false);
        $this->assertTrue(SchedulerHeartbeat::health('parity', ParityScheduler::HOOK)['overdue']);

        // A fresh record clears it.
        SchedulerHeartbeat::record('parity', ['ran' => 0]);
        $this->assertFalse(SchedulerHeartbeat::health('parity', ParityScheduler::HOOK)['overdue']);
    }
}
