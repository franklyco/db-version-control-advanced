<?php

use Dbvc\AgencyControl\Governance\FreezeService;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;

/**
 * M9 slice 7c — per-environment maintenance-freeze windows. An advisory hub-side
 * control: the hub refuses to prepare or approve a release for a target inside
 * its freeze window (the connector apply gate stays authoritative downstream).
 *
 * @group connected-environments
 */
class ConnectedFreezeTest extends WP_UnitTestCase
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

    public function test_is_frozen_window_logic(): void
    {
        $env = ['freeze_from' => '2026-10-06 00:00:00', 'freeze_until' => '2026-10-06 02:00:00'];
        $this->assertFalse(EnvironmentRegistry::is_frozen($env, strtotime('2026-10-05 23:59:59 UTC')), 'before window');
        $this->assertTrue(EnvironmentRegistry::is_frozen($env, strtotime('2026-10-06 00:00:00 UTC')), 'at start (inclusive)');
        $this->assertTrue(EnvironmentRegistry::is_frozen($env, strtotime('2026-10-06 01:00:00 UTC')), 'inside');
        $this->assertFalse(EnvironmentRegistry::is_frozen($env, strtotime('2026-10-06 02:00:00 UTC')), 'at end (exclusive)');
        $this->assertFalse(EnvironmentRegistry::is_frozen(['freeze_from' => '', 'freeze_until' => ''], strtotime('2026-10-06 01:00:00 UTC')), 'no window');
        $this->assertFalse(EnvironmentRegistry::is_frozen(['freeze_from' => 'not-a-date', 'freeze_until' => 'nope']), 'invalid bounds');
    }

    private function seed_env(string $id): void
    {
        (new EnvironmentRegistry())->create([
            'agency_id' => 'ag-1', 'client_id' => 'cl-1', 'environment_id' => $id,
            'current_epoch' => 'epoch-1', 'principal_user_id' => 1, 'principal_uuid' => 'uuid-' . $id,
        ]);
    }

    public function test_freeze_set_stores_a_valid_window_and_reports_frozen(): void
    {
        $this->seed_env('env-frz');
        $from = gmdate('Y-m-d H:i:s', time() - 3600);
        $until = gmdate('Y-m-d H:i:s', time() + 3600);

        $result = (new FreezeService())->set('env-frz', $from, $until, 'nightly maintenance');
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertTrue($result['frozen_now'], 'a window spanning now is active');
        $this->assertSame('nightly maintenance', $result['freeze_reason']);

        $env = (new EnvironmentRegistry())->find('env-frz');
        $this->assertTrue(EnvironmentRegistry::is_frozen($env), 'the stored window reads as frozen');
    }

    public function test_freeze_set_rejects_an_invalid_window(): void
    {
        $this->seed_env('env-bad');
        $now = gmdate('Y-m-d H:i:s');
        $this->assertSame('dbvc_agency_invalid_freeze_window', (new FreezeService())->set('env-bad', $now, $now)->get_error_code(), 'until must be after from');
        $this->assertSame('dbvc_agency_invalid_freeze_window', (new FreezeService())->set('env-bad', 'garbage', 'also-garbage')->get_error_code());
        $this->assertSame('dbvc_agency_environment_not_found', (new FreezeService())->set('nope', $now, gmdate('Y-m-d H:i:s', time() + 60))->get_error_code());
    }

    public function test_freeze_clear_removes_the_window(): void
    {
        $this->seed_env('env-clr');
        (new FreezeService())->set('env-clr', gmdate('Y-m-d H:i:s', time() - 60), gmdate('Y-m-d H:i:s', time() + 60));
        $this->assertTrue(EnvironmentRegistry::is_frozen((new EnvironmentRegistry())->find('env-clr')));

        $cleared = (new FreezeService())->clear('env-clr');
        $this->assertTrue($cleared['cleared']);
        $this->assertFalse(EnvironmentRegistry::is_frozen((new EnvironmentRegistry())->find('env-clr')), 'cleared window is no longer frozen');
    }
}
