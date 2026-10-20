<?php

class ContentCollectorV2Phase1Test extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        if (! function_exists('dbvc_render_export_page')) {
            require_once DBVC_PLUGIN_PATH . 'admin/admin-page.php';
        }

        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        DBVC_CC_V2_Contracts::ensure_defaults();

        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);

        foreach (DBVC_CC_V2_Contracts::get_default_values() as $option_key => $default_value) {
            update_option($option_key, $default_value);
        }

        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();
    }

    public function tear_down(): void
    {
        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);

        foreach (DBVC_CC_V2_Contracts::get_default_values() as $option_key => $default_value) {
            update_option($option_key, $default_value);
        }

        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();

        parent::tear_down();
    }

    public function test_content_collector_addons_settings_persist_via_configure_save(): void
    {
        $_POST = [
            'dbvc_config_save' => ['addons' => '1'],
            'dbvc_config_nonce' => wp_create_nonce('dbvc_config_save_action'),
            DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED => '1',
            DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION => DBVC_CC_V2_Contracts::RUNTIME_V2,
            DBVC_CC_V2_Contracts::OPTION_AUTO_ACCEPT_MIN_CONFIDENCE => '0.93',
            DBVC_CC_V2_Contracts::OPTION_BLOCK_BELOW_CONFIDENCE => '0.56',
            DBVC_CC_V2_Contracts::OPTION_RESOLUTION_UPDATE_MIN_CONFIDENCE => '0.95',
            DBVC_CC_V2_Contracts::OPTION_PATTERN_REUSE_MIN_CONFIDENCE => '0.91',
            DBVC_CC_V2_Contracts::OPTION_REQUIRE_QA_PASS_FOR_AUTO_ACCEPT => '1',
            DBVC_CC_V2_Contracts::OPTION_REQUIRE_UNAMBIGUOUS_RESOLUTION_FOR_AUTO_ACCEPT => '1',
        ];

        ob_start();
        dbvc_render_export_page();
        ob_end_clean();

        $this->assertSame('1', get_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED));
        $this->assertSame(DBVC_CC_V2_Contracts::RUNTIME_V2, get_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION));
        $this->assertSame('0.93', get_option(DBVC_CC_V2_Contracts::OPTION_AUTO_ACCEPT_MIN_CONFIDENCE));
        $this->assertSame('0.56', get_option(DBVC_CC_V2_Contracts::OPTION_BLOCK_BELOW_CONFIDENCE));
        $this->assertSame('0.95', get_option(DBVC_CC_V2_Contracts::OPTION_RESOLUTION_UPDATE_MIN_CONFIDENCE));
        $this->assertSame('0.91', get_option(DBVC_CC_V2_Contracts::OPTION_PATTERN_REUSE_MIN_CONFIDENCE));
        $this->assertSame('1', get_option(DBVC_CC_V2_Contracts::OPTION_REQUIRE_QA_PASS_FOR_AUTO_ACCEPT));
        $this->assertSame('1', get_option(DBVC_CC_V2_Contracts::OPTION_REQUIRE_UNAMBIGUOUS_RESOLUTION_FOR_AUTO_ACCEPT));
        $this->assertSame('0', get_option(DBVC_CC_V2_Contracts::OPTION_REQUIRE_MANUAL_REVIEW_FOR_OBJECT_FAMILY_CHANGE));

        $_POST = [];
    }

    public function test_disabled_state_registers_no_content_collector_surfaces(): void
    {
        global $submenu;

        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '0');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V2);
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();

        $submenu = [];
        do_action('admin_menu');

        $this->assertFalse($this->has_content_collector_submenu($submenu));
        $this->assertFalse(wp_next_scheduled(DBVC_CC_Contracts::CRON_HOOK_AI_PROCESS_JOB));
        $this->assertFalse(wp_next_scheduled(DBVC_CC_Contracts::CRON_HOOK_MAPPING_REBUILD_BATCH));

        $this->reset_rest_server();
        do_action('rest_api_init');
        $routes = rest_get_server()->get_routes();

        $this->assertArrayNotHasKey('/' . DBVC_CC_Contracts::REST_NAMESPACE . '/explorer/domains', $routes);
    }

    public function test_v1_runtime_keeps_legacy_routes_and_menu_active(): void
    {
        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->assertSame(90, has_action('admin_menu', [DBVC_CC_Admin_Controller::get_instance(), 'add_admin_menu']));

        $this->reset_rest_server();
        do_action('rest_api_init');
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey('/' . DBVC_CC_Contracts::REST_NAMESPACE . '/explorer/domains', $routes);
    }

    public function test_v2_runtime_mounts_new_shell_without_legacy_v1_routes(): void
    {
        global $submenu;

        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V2);
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();

        $submenu = [];
        do_action('admin_menu');

        $this->assertTrue($this->has_content_collector_submenu($submenu));
        $this->assertSame(90, has_action('admin_menu', [DBVC_CC_V2_Admin_Menu_Service::class, 'register_admin_menu']));
        $this->assertFalse(has_action('admin_menu', [DBVC_CC_Admin_Controller::get_instance(), 'add_admin_menu']));

        $this->reset_rest_server();
        do_action('rest_api_init');
        $routes = rest_get_server()->get_routes();

        $this->assertArrayNotHasKey('/' . DBVC_CC_Contracts::REST_NAMESPACE . '/explorer/domains', $routes);

        ob_start();
        DBVC_CC_V2_Admin_Menu_Service::render_admin_page();
        $markup = ob_get_clean();

        $this->assertStringContainsString('dbvc-cc-v2-root', $markup);
        $this->assertStringNotContainsString('dbvc-cc-configure-subtab-nav', $markup);
    }

    /**
     * @param array<string, array<int, array<int, string>>> $submenu
     */
    private function has_content_collector_submenu(array $submenu): bool
    {
        if (empty($submenu['dbvc-export'])) {
            return false;
        }

        foreach ($submenu['dbvc-export'] as $item) {
            if (($item[2] ?? '') === DBVC_CC_V2_Contracts::ADMIN_MENU_SLUG) {
                return true;
            }
        }

        return false;
    }

    private function reset_rest_server(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
    }
}
