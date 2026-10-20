<?php

class BricksAddonPhase2Test extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->reset_bricks_options();
        DBVC_Bricks_Addon::ensure_defaults();
    }

    public function tear_down(): void
    {
        $this->reset_bricks_options();
        parent::tear_down();
    }

    public function test_defaults_are_seeded_for_all_bricks_settings(): void
    {
        $defaults = DBVC_Bricks_Addon::get_default_values();
        foreach ($defaults as $key => $expected) {
            $this->assertSame((string) $expected, (string) get_option($key));
        }
    }

    public function test_field_validation_covers_enums_ranges_url_and_json_rules(): void
    {
        update_option('dbvc_addon_bricks_visibility', 'submenu_only');
        update_option('dbvc_bricks_channel', 'stable');
        update_option('dbvc_bricks_http_timeout', '30');
        update_option('dbvc_bricks_policy_max_diff_kb', '1024');
        update_option('dbvc_bricks_scan_interval_minutes', '60');
        update_option('dbvc_bricks_api_key_id', 'abc_123');
        update_option('dbvc_bricks_policy_overrides', '{"option:bricks_components":"REQUEST_REVIEW"}');

        $result = DBVC_Bricks_Addon::save_settings([
            'dbvc_addon_bricks_visibility' => 'INVALID',
            'dbvc_bricks_channel' => 'beta',
            'dbvc_bricks_http_timeout' => '999',
            'dbvc_bricks_policy_max_diff_kb' => '10',
            'dbvc_bricks_scan_interval_minutes' => '9999',
            'dbvc_bricks_api_key_id' => '***',
            'dbvc_bricks_policy_overrides' => '{broken-json',
            'dbvc_bricks_role' => 'mothership',
            'dbvc_bricks_auth_method' => 'wp_app_password',
            'dbvc_bricks_api_secret' => 'new-secret',
        ]);

        $this->assertNotEmpty($result['errors']);
        $this->assertSame('submenu_only', get_option('dbvc_addon_bricks_visibility'));
        $this->assertSame('beta', get_option('dbvc_bricks_channel'));
        $this->assertSame('30', get_option('dbvc_bricks_http_timeout'));
        $this->assertSame('1024', get_option('dbvc_bricks_policy_max_diff_kb'));
        $this->assertSame('60', get_option('dbvc_bricks_scan_interval_minutes'));
        $this->assertSame('abc_123', get_option('dbvc_bricks_api_key_id'));
        $this->assertSame('{"option:bricks_components":"REQUEST_REVIEW"}', get_option('dbvc_bricks_policy_overrides'));
    }

    public function test_invalid_conditional_input_is_rejected_and_keeps_previous_values(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_mothership_url', 'https://mothership.example');
        update_option('dbvc_bricks_auth_method', 'hmac');
        update_option('dbvc_bricks_api_key_id', 'valid_id');
        update_option('dbvc_bricks_api_secret', 'valid_secret');
        update_option('dbvc_bricks_source_mode', 'pinned_version');
        update_option('dbvc_bricks_pinned_version', '1.2.3');
        update_option('dbvc_bricks_scan_mode', 'scheduled');
        update_option('dbvc_bricks_scan_interval_minutes', '30');

        $result = DBVC_Bricks_Addon::save_settings([
            'dbvc_bricks_role' => 'client',
            'dbvc_bricks_mothership_url' => '',
            'dbvc_bricks_auth_method' => 'hmac',
            'dbvc_bricks_api_key_id' => '',
            'dbvc_bricks_api_secret' => '',
            'dbvc_bricks_source_mode' => 'pinned_version',
            'dbvc_bricks_pinned_version' => '',
            'dbvc_bricks_scan_mode' => 'scheduled',
            'dbvc_bricks_scan_interval_minutes' => '',
        ]);

        $this->assertNotEmpty($result['errors']);
        $this->assertSame('https://mothership.example', get_option('dbvc_bricks_mothership_url'));
        $this->assertSame('valid_id', get_option('dbvc_bricks_api_key_id'));
        $this->assertSame('valid_secret', get_option('dbvc_bricks_api_secret'));
        $this->assertSame('1.2.3', get_option('dbvc_bricks_pinned_version'));
        $this->assertSame('30', get_option('dbvc_bricks_scan_interval_minutes'));
    }

    public function test_typed_settings_getters_return_expected_types(): void
    {
        update_option('dbvc_bricks_read_only', '1');
        update_option('dbvc_bricks_http_timeout', '45');
        update_option('dbvc_bricks_channel', 'canary');

        $this->assertTrue(DBVC_Bricks_Addon::get_bool_setting('dbvc_bricks_read_only'));
        $this->assertSame(45, DBVC_Bricks_Addon::get_int_setting('dbvc_bricks_http_timeout'));
        $this->assertSame(
            'canary',
            DBVC_Bricks_Addon::get_enum_setting('dbvc_bricks_channel', ['stable', 'beta', 'canary'], 'stable')
        );
        update_option('dbvc_bricks_channel', 'invalid-value');
        $this->assertSame(
            'stable',
            DBVC_Bricks_Addon::get_enum_setting('dbvc_bricks_channel', ['stable', 'beta', 'canary'], 'stable')
        );
    }

    public function test_policy_overrides_map_rejects_invalid_policy_values(): void
    {
        update_option('dbvc_bricks_policy_overrides', '{"option:bricks_components":"REQUEST_REVIEW"}');

        $result = DBVC_Bricks_Addon::save_settings([
            'dbvc_bricks_role' => 'mothership',
            'dbvc_bricks_auth_method' => 'wp_app_password',
            'dbvc_bricks_api_secret' => 'secret',
            'dbvc_bricks_policy_overrides' => '{"option:bricks_components":"INVALID_POLICY"}',
        ]);

        $this->assertNotEmpty($result['errors']);
        $this->assertSame(
            '{"option:bricks_components":"REQUEST_REVIEW"}',
            get_option('dbvc_bricks_policy_overrides')
        );

        $ok = DBVC_Bricks_Addon::save_settings([
            'dbvc_bricks_role' => 'mothership',
            'dbvc_bricks_auth_method' => 'wp_app_password',
            'dbvc_bricks_api_secret' => 'secret',
            'dbvc_bricks_policy_overrides' => '{"option:bricks_components":"AUTO_ACCEPT"}',
        ]);
        $this->assertEmpty($ok['errors']);
        $this->assertSame(
            '{"option:bricks_components":"AUTO_ACCEPT"}',
            get_option('dbvc_bricks_policy_overrides')
        );
    }

    private function reset_bricks_options(): void
    {
        $keys = array_keys(DBVC_Bricks_Addon::get_default_values());
        foreach ($keys as $key) {
            delete_option($key);
        }
    }
}
