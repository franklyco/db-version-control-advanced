<?php

use Dbvc\Connected\Capture\DirtyCapture;
use Dbvc\Connected\Lifecycle\TransferExclusions;
use Dbvc\Connected\Storage\Schema;

/**
 * Module gates and role isolation for the connector and hub add-ons.
 *
 * @group connected-environments
 */
class ConnectedEnvironmentsIsolationTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_connected_transaction_strategy', [$this, 'savepoint_strategy']);
        delete_option(DBVC_Connected_Environments_Addon::OPTION_ENABLED);
        delete_option(DBVC_Agency_Control_Addon::OPTION_ENABLED);
        delete_option(Schema::OPTION_SCHEMA_VERSION);
        delete_option(\Dbvc\AgencyControl\Storage\Schema::OPTION_SCHEMA_VERSION);
        Schema::reset_memo();
        \Dbvc\AgencyControl\Storage\Schema::reset_memo();
        DBVC_Connected_Environments_Addon::refresh_runtime_registration();
        DBVC_Agency_Control_Addon::refresh_runtime_registration();
    }

    public function tear_down(): void
    {
        DBVC_Connected_Environments_Addon::save_settings([]);
        DBVC_Agency_Control_Addon::save_settings([]);
        remove_filter('dbvc_connected_transaction_strategy', [$this, 'savepoint_strategy']);
        Schema::reset_memo();
        \Dbvc\AgencyControl\Storage\Schema::reset_memo();
        parent::tear_down();
    }

    public function savepoint_strategy(): string
    {
        return 'savepoint';
    }

    public function test_both_modules_off_by_default_register_nothing(): void
    {
        $this->assertSame('disabled', DBVC_Connected_Environments_Addon::get_gate_state());
        $this->assertSame('disabled', DBVC_Agency_Control_Addon::get_gate_state());
        $this->assertFalse(DBVC_Connected_Environments_Addon::is_runtime_registered());
        $this->assertNull(DBVC_Agency_Control_Addon::get_runtime_state());
        $this->assertFalse(DBVC_Connected_Environments_Addon::is_emergency_disabled());
        $this->assertFalse(DBVC_Agency_Control_Addon::is_emergency_disabled());

        $this->assertFalse($this->has_listener('updated_option', 'Dbvc\\Connected\\Capture\\OptionSignalListener'));
        $this->assertFalse($this->has_listener('added_option', 'Dbvc\\Connected\\Capture\\OptionSignalListener'));
        $this->assertFalse($this->has_listener('deleted_option', 'Dbvc\\Connected\\Capture\\OptionSignalListener'));
        $this->assertFalse(has_action(DirtyCapture::CRON_HOOK));
        $this->assertFalse(wp_next_scheduled(DirtyCapture::CRON_HOOK));
        $this->assertSame([], $this->real_connector_tables(), 'Disabled modules must create no durable tables.');
        $this->assertSame(0, (int) get_option(Schema::OPTION_SCHEMA_VERSION, 0));

        // A Bricks save while disabled leaves no trace and schedules nothing.
        update_option('bricks_global_classes', [['id' => 'abc123', 'name' => 'x', 'settings' => []]]);
        $this->assertFalse(wp_next_scheduled(DirtyCapture::CRON_HOOK));
        $this->assertSame([], $this->real_connector_tables());

        $routes = $this->registered_route_paths();
        $this->assertSame([], preg_grep('#^/dbvc-agency/|/dbvc/v1/connected#', $routes));
        $this->assertSame(0, (int) get_option(\Dbvc\AgencyControl\Storage\Schema::OPTION_SCHEMA_VERSION, 0));
    }

    public function test_connector_only_registers_capture_schema_and_identity_but_no_hub(): void
    {
        $result = DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('1', get_option(DBVC_Connected_Environments_Addon::OPTION_ENABLED));
        $this->assertSame('ready', DBVC_Connected_Environments_Addon::get_gate_state());
        $this->assertTrue(DBVC_Connected_Environments_Addon::is_runtime_registered());
        $this->assertTrue(Schema::is_ready());
        $this->assertTrue(Schema::tables_exist());
        $this->assertSame(Schema::SCHEMA_VERSION, (int) get_option(Schema::OPTION_SCHEMA_VERSION));

        $state = (new \Dbvc\Connected\Storage\StateStore())->get();
        $this->assertIsArray($state);
        $this->assertSame('provisional', $state['enrollment_state']);
        $this->assertStringStartsWith('local-', $state['environment_id']);
        $this->assertStringStartsWith('provisional-', $state['installation_epoch']);
        $this->assertSame(1, $state['next_sequence']);

        $this->assertTrue($this->has_listener('updated_option', 'Dbvc\\Connected\\Capture\\OptionSignalListener'));
        $this->assertTrue($this->has_listener('deleted_option', 'Dbvc\\Connected\\Capture\\OptionSignalListener'));
        $this->assertNotFalse(has_action(DirtyCapture::CRON_HOOK));

        // Hub stays absent: no runtime, no routes, no hub state.
        $this->assertSame('disabled', DBVC_Agency_Control_Addon::get_gate_state());
        $this->assertNull(DBVC_Agency_Control_Addon::get_runtime_state());
        $this->assertSame([], preg_grep('#dbvc-agency#', $this->registered_route_paths()));

        // Re-enabling is idempotent and never rotates identity.
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        $again = (new \Dbvc\Connected\Storage\StateStore())->get();
        $this->assertSame($state['environment_id'], $again['environment_id']);
        $this->assertSame($state['installation_epoch'], $again['installation_epoch']);
    }

    public function test_gate_on_without_installed_schema_is_held_in_migration_required(): void
    {
        // The option gate alone (e.g. copied by a cloning tool) never triggers DDL on ordinary requests.
        update_option(DBVC_Connected_Environments_Addon::OPTION_ENABLED, '1');
        DBVC_Connected_Environments_Addon::refresh_runtime_registration();
        do_action('plugins_loaded');

        $this->assertSame('migration_required', DBVC_Connected_Environments_Addon::get_gate_state());
        $this->assertSame(0, (int) get_option(Schema::OPTION_SCHEMA_VERSION, 0));
        $this->assertFalse($this->has_listener('updated_option', 'Dbvc\\Connected\\Capture\\OptionSignalListener'));
        $this->assertFalse(has_action(DirtyCapture::CRON_HOOK));

        update_option('bricks_global_classes', [['id' => 'abc123', 'name' => 'x', 'settings' => []]]);
        $this->assertFalse(wp_next_scheduled(DirtyCapture::CRON_HOOK));
        $this->assertSame([], $this->real_connector_tables());

        // The explicit lifecycle installs the schema and attaches capture.
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        $this->assertSame('ready', DBVC_Connected_Environments_Addon::get_gate_state());
        $this->assertTrue($this->has_listener('updated_option', 'Dbvc\\Connected\\Capture\\OptionSignalListener'));
    }

    public function test_disabling_connector_unregisters_listeners_and_keeps_data(): void
    {
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        DirtyCapture::schedule_processing(1);
        $this->assertNotFalse(wp_next_scheduled(DirtyCapture::CRON_HOOK));

        DBVC_Connected_Environments_Addon::save_settings([]);
        $this->assertSame('disabled', DBVC_Connected_Environments_Addon::get_gate_state());
        $this->assertFalse(DBVC_Connected_Environments_Addon::is_runtime_registered());
        $this->assertFalse($this->has_listener('updated_option', 'Dbvc\\Connected\\Capture\\OptionSignalListener'));
        $this->assertFalse(wp_next_scheduled(DirtyCapture::CRON_HOOK));
        $this->assertTrue(Schema::tables_exist(), 'Disabling must not delete stored observations.');
        $this->assertIsArray((new \Dbvc\Connected\Storage\StateStore())->get());
    }

    public function test_hub_only_is_inert_and_needs_no_connector_bricks_or_visual_editor(): void
    {
        $this->assertSame('0', get_option('dbvc_addon_visual_editor_enabled', '0'));
        $this->assertFalse(defined('BRICKS_VERSION'));

        $result = DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('ready', DBVC_Agency_Control_Addon::get_gate_state());
        $runtime = DBVC_Agency_Control_Addon::get_runtime_state();
        $this->assertIsArray($runtime);
        $this->assertSame('registered', $runtime['state']);
        $this->assertTrue($runtime['registered']);
        $this->assertSame(['enrollment' => true, 'receipt' => true, 'delivery' => true, 'review' => false], $runtime['capabilities']);
        $this->assertTrue(\Dbvc\AgencyControl\Storage\Schema::is_ready());
        $this->assertTrue(\Dbvc\AgencyControl\Storage\Schema::tables_exist());
        $this->assertNotNull(get_role(\Dbvc\AgencyControl\Storage\Schema::ENVIRONMENT_ROLE));
        $this->assertSame([], get_role(\Dbvc\AgencyControl\Storage\Schema::ENVIRONMENT_ROLE)->capabilities);

        $routes = $this->registered_route_paths();
        $agency_routes = array_values(preg_grep('#^/dbvc-agency/v1/#', $routes));
        sort($agency_routes);
        $this->assertSame(['/dbvc-agency/v1/capabilities', '/dbvc-agency/v1/enrollments/exchange', '/dbvc-agency/v1/inbox', '/dbvc-agency/v1/inbox/ack', '/dbvc-agency/v1/observations', '/dbvc-agency/v1/releases/(?P<release_uid>[A-Za-z0-9._:-]{1,128})/apply-receipts', '/dbvc-agency/v1/releases/(?P<release_uid>[A-Za-z0-9._:-]{1,128})/media/(?P<hash>[a-f0-9]{64})', '/dbvc-agency/v1/releases/(?P<release_uid>[A-Za-z0-9._:-]{1,128})/payloads', '/dbvc-agency/v1/releases/(?P<release_uid>[A-Za-z0-9._:-]{1,128})/prepare-receipts', '/dbvc-agency/v1/releases/apply-requests', '/dbvc-agency/v1/releases/payload-requests', '/dbvc-agency/v1/releases/prepare-requests'], $agency_routes);
        $this->assertSame([], preg_grep('#/dbvc/v1/connected/#', $routes), 'Connector admin routes stay off; the role-neutral connected-admin/* routes belong to the page.');
        $this->assertContains('/dbvc/v1/connected-admin/overview', $routes);

        // The connector stays untouched: no gate, listeners, tables or cron on the hub installation.
        $this->assertSame('disabled', DBVC_Connected_Environments_Addon::get_gate_state());
        $this->assertFalse(DBVC_Connected_Environments_Addon::is_runtime_registered());
        $this->assertFalse($this->has_listener('updated_option', 'Dbvc\\Connected\\Capture\\OptionSignalListener'));
        $this->assertSame([], $this->real_connector_tables());
        $this->assertFalse(wp_next_scheduled(DirtyCapture::CRON_HOOK));

        // Machine routes reject a plain administrator cookie session.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $response = rest_do_request(new WP_REST_Request('GET', '/dbvc-agency/v1/capabilities'));
        $this->assertSame(401, $response->get_status());
        $this->assertSame('dbvc_agency_app_password_required', $response->get_data()['code']);

        DBVC_Agency_Control_Addon::save_settings([]);
        $this->assertSame('disabled', DBVC_Agency_Control_Addon::get_gate_state());
        $this->assertNull(DBVC_Agency_Control_Addon::get_runtime_state());
    }

    public function test_operational_state_is_excluded_from_option_export_and_import(): void
    {
        $excluded = apply_filters('dbvc_excluded_option_keys', ['siteurl']);
        foreach (TransferExclusions::exact_keys() as $key) {
            $this->assertContains($key, $excluded);
        }
        $this->assertContains('siteurl', $excluded);

        $imported = apply_filters('dbvc_import_options_data', [
            'blogname' => 'Keep',
            'dbvc_addon_connected_environments_enabled' => '1',
            'dbvc_addon_agency_control_enabled' => '1',
            'dbvc_connected_schema_version' => '9',
            'dbvc_ce_future_cursor' => 'x',
            'dbvc_ac_future_registry' => 'y',
        ]);
        $this->assertSame(['blogname' => 'Keep'], $imported);

        $this->assertTrue(TransferExclusions::is_operational_option('dbvc_ce_anything'));
        $this->assertTrue(TransferExclusions::is_operational_option('dbvc_connected_schema_version'));
        $this->assertFalse(TransferExclusions::is_operational_option('dbvc_addon_bricks_enabled'));
        $this->assertFalse(TransferExclusions::is_operational_option('bricks_global_classes'));
    }

    public function test_settings_metadata_shapes_match_admin_renderer_contract(): void
    {
        foreach (['DBVC_Connected_Environments_Addon', 'DBVC_Agency_Control_Addon'] as $class) {
            $groups = $class::get_settings_groups();
            $meta = $class::get_field_meta();
            $settings = $class::get_all_settings();
            $this->assertArrayHasKey('activation', $groups);
            foreach ($groups['activation']['fields'] as $field) {
                $this->assertArrayHasKey($field, $meta);
                $this->assertSame('checkbox', $meta[$field]['input']);
                $this->assertArrayHasKey($field, $settings);
                $this->assertSame('0', $settings[$field]);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function real_connector_tables(): array
    {
        global $wpdb;

        return (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'dbvc_ce_') . '%'));
    }

    /**
     * @return array<int, string>
     */
    private function registered_route_paths(): array
    {
        // The REST server is a process-wide singleton; rebuild it so only currently attached hooks register routes.
        $GLOBALS['wp_rest_server'] = null;

        return array_keys(rest_get_server()->get_routes());
    }

    private function has_listener(string $hook, string $class): bool
    {
        global $wp_filter;

        if (! isset($wp_filter[$hook])) {
            return false;
        }
        foreach ($wp_filter[$hook]->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'];
                if (is_array($function) && is_object($function[0]) && get_class($function[0]) === $class) {
                    return true;
                }
            }
        }

        return false;
    }
}
