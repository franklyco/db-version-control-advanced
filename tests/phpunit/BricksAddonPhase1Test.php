<?php

class BricksAddonPhase1Test extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        if (! function_exists('dbvc_render_export_page')) {
            require_once DBVC_PLUGIN_PATH . 'admin/admin-page.php';
        }
        delete_option(DBVC_Bricks_Addon::OPTION_ENABLED);
        delete_option(DBVC_Bricks_Addon::OPTION_VISIBILITY);
        DBVC_Bricks_Addon::bootstrap();
        $this->reset_rest_server();
    }

    public function tear_down(): void
    {
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(DBVC_Bricks_Addon::CRON_HOOK);
        }
        $this->reset_rest_server();
        parent::tear_down();
    }

    public function test_toggle_persistence_via_configure_addons_save(): void
    {
        $this->submit_addons_form(true, 'submenu_only');
        $this->assertSame('1', get_option(DBVC_Bricks_Addon::OPTION_ENABLED));
        $this->assertSame('submenu_only', get_option(DBVC_Bricks_Addon::OPTION_VISIBILITY));

        $this->submit_addons_form(false, 'configure_and_submenu');
        $this->assertSame('0', get_option(DBVC_Bricks_Addon::OPTION_ENABLED));
        $this->assertSame('configure_and_submenu', get_option(DBVC_Bricks_Addon::OPTION_VISIBILITY));
    }

    public function test_configure_addons_panel_uses_nested_addon_subtabs_and_preserves_active_subtab(): void
    {
        $_POST = [
            'dbvc_config_save' => ['addons' => '1'],
            'dbvc_config_nonce' => wp_create_nonce('dbvc_config_save_action'),
            'dbvc_addons_subtab' => 'dbvc-addon-bricks',
            DBVC_Bricks_Addon::OPTION_ENABLED => '1',
            DBVC_Bricks_Addon::OPTION_VISIBILITY => 'submenu_only',
        ];

        if (class_exists('DBVC_CC_V2_Contracts') && get_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1') === '1') {
            $_POST[DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED] = '1';
        }

        if (class_exists('DBVC_Visual_Editor_Addon') && get_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '0') === '1') {
            $_POST[DBVC_Visual_Editor_Addon::OPTION_ENABLED] = '1';
        }

        ob_start();
        dbvc_render_export_page();
        $markup = (string) ob_get_clean();

        $this->assertStringContainsString('name="dbvc_addons_subtab"', $markup);
        $this->assertStringContainsString('id="dbvc-addons-subtab" value="dbvc-addon-bricks"', $markup);
        $this->assertStringContainsString('data-dbvc-subtabs-value-target="dbvc_addons_subtab"', $markup);
        $this->assertStringContainsString('id="dbvc-nav-dbvc-addon-content-collector"', $markup);
        $this->assertStringContainsString('id="dbvc-nav-dbvc-addon-bricks"', $markup);
        $this->assertStringContainsString('id="dbvc-nav-dbvc-addon-visual-editor"', $markup);
        $this->assertStringContainsString('id="dbvc-addon-content-collector"', $markup);
        $this->assertStringContainsString('id="dbvc-addon-bricks" class="dbvc-subtab-panel is-active"', $markup);
        $this->assertStringContainsString('id="dbvc-addon-visual-editor"', $markup);

        $_POST = [];
    }

    public function test_bricks_submenu_visibility_is_gated_by_enable_flag(): void
    {
        global $submenu;

        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $submenu = [];
        do_action('admin_menu');
        $this->assertFalse($this->has_bricks_submenu($submenu));

        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $submenu = [];
        do_action('admin_menu');
        $this->assertTrue($this->has_bricks_submenu($submenu));
    }

    public function test_bricks_endpoint_registration_is_gated_by_enable_flag(): void
    {
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        do_action('rest_api_init');
        $server = rest_get_server();
        $disabled_request = new WP_REST_Request('GET', '/dbvc/v1/bricks/status');
        $disabled_response = $server->dispatch($disabled_request);
        $this->assertSame(404, $disabled_response->get_status());

        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        do_action('rest_api_init');
        $server = rest_get_server();
        $enabled_request = new WP_REST_Request('GET', '/dbvc/v1/bricks/status');
        $enabled_response = $server->dispatch($enabled_request);
        $this->assertSame(200, $enabled_response->get_status());
    }

    public function test_bricks_status_reports_external_db_dropin_runtime_warning(): void
    {
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        do_action('rest_api_init');

        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlink support is required for external drop-in warning test.');
        }

        $dropin_path = wp_normalize_path((string) WP_CONTENT_DIR . '/db.php');
        $external_target = wp_normalize_path(sys_get_temp_dir() . '/dbvc-external-dropin-' . wp_rand(1000, 999999) . '.php');
        $backup_path = '';
        $had_existing_dropin = file_exists($dropin_path) || is_link($dropin_path);

        if ($had_existing_dropin) {
            $backup_path = $dropin_path . '.dbvc-backup-' . wp_rand(1000, 999999);
            if (! @rename($dropin_path, $backup_path)) {
                $this->markTestSkipped('Unable to move existing db.php drop-in for test setup.');
            }
        }

        file_put_contents($external_target, "<?php\n// dbvc test drop-in target\n");
        if (! @symlink($external_target, $dropin_path)) {
            @unlink($external_target);
            if ($backup_path !== '' && (file_exists($backup_path) || is_link($backup_path))) {
                @rename($backup_path, $dropin_path);
            }
            $this->markTestSkipped('Unable to create db.php symlink for external drop-in warning test.');
        }

        try {
            $request = new WP_REST_Request('GET', '/dbvc/v1/bricks/status');
            $response = rest_get_server()->dispatch($request);
            $this->assertSame(200, $response->get_status());
            $data = $response->get_data();
            $this->assertIsArray($data['health_warnings'] ?? null);

            $has_external_dropin_warning = false;
            foreach ((array) ($data['health_warnings'] ?? []) as $warning) {
                if (! is_array($warning)) {
                    continue;
                }
                if (($warning['code'] ?? '') !== 'dbvc_bricks_runtime_external_dropin_db_php') {
                    continue;
                }
                $has_external_dropin_warning = true;
                $this->assertSame('warning', (string) ($warning['severity'] ?? ''));
                $this->assertStringContainsString('db.php', (string) ($warning['dropin'] ?? ''));
                break;
            }

            $this->assertTrue($has_external_dropin_warning, 'Expected external db.php runtime health warning in status payload.');
        } finally {
            if (is_link($dropin_path) || file_exists($dropin_path)) {
                @unlink($dropin_path);
            }
            if ($backup_path !== '' && (is_link($backup_path) || file_exists($backup_path))) {
                @rename($backup_path, $dropin_path);
            }
            @unlink($external_target);
        }
    }

    public function test_bricks_status_reports_runtime_site_identity(): void
    {
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        update_option('dbvc_bricks_site_uid', 'client_status_identity');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        do_action('rest_api_init');

        $request = new WP_REST_Request('GET', '/dbvc/v1/bricks/status');
        $response = rest_get_server()->dispatch($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('client_status_identity', (string) ($data['site_uid'] ?? ''));
        $this->assertSame(get_bloginfo('name'), (string) ($data['site_name'] ?? ''));
        $this->assertSame(home_url('/'), (string) ($data['home_url'] ?? ''));
        $this->assertSame(site_url('/'), (string) ($data['site_url'] ?? ''));
    }

    public function test_bricks_status_response_sets_no_cache_headers(): void
    {
        $request = new WP_REST_Request('GET', '/dbvc/v1/bricks/status');
        $response = new WP_REST_Response(['ok' => true], 200);
        $server = rest_get_server();
        $filtered = DBVC_Bricks_Addon::prevent_rest_caching($response, $server, $request);
        $this->assertInstanceOf(WP_REST_Response::class, $filtered);

        $headers = $filtered->get_headers();
        $cache_control = strtolower((string) ($headers['Cache-Control'] ?? ''));
        $pragma = strtolower((string) ($headers['Pragma'] ?? ''));
        $vary = strtolower((string) ($headers['Vary'] ?? ''));

        $this->assertStringContainsString('no-store', $cache_control);
        $this->assertStringContainsString('no-cache', $cache_control);
        $this->assertStringContainsString('no-cache', $pragma);
        $this->assertStringContainsString('authorization', $vary);
        $this->assertStringContainsString('cookie', $vary);
    }

    public function test_bricks_hook_and_job_registration_is_gated_by_enable_flag(): void
    {
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(DBVC_Bricks_Addon::CRON_HOOK);
        }
        do_action('init');
        $this->assertFalse(has_action(DBVC_Bricks_Addon::CRON_HOOK, [DBVC_Bricks_Addon::class, 'run_scheduled_job']));
        $this->assertFalse(has_action('admin_init', [DBVC_Bricks_Addon::class, 'maybe_redirect_admin_path']));
        $this->assertFalse(wp_next_scheduled(DBVC_Bricks_Addon::CRON_HOOK));

        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        do_action('init');
        $this->assertSame(10, has_action(DBVC_Bricks_Addon::CRON_HOOK, [DBVC_Bricks_Addon::class, 'run_scheduled_job']));
        $this->assertNotFalse(wp_next_scheduled(DBVC_Bricks_Addon::CRON_HOOK));
        $this->assertSame(10, has_action('admin_init', [DBVC_Bricks_Addon::class, 'maybe_redirect_admin_path']));
    }

    public function test_bricks_bootstrap_does_not_run_client_transport_ticks(): void
    {
        $transport_keys = [
            DBVC_Bricks_Addon::OPTION_ENABLED,
            'dbvc_bricks_role',
            'dbvc_bricks_site_uid',
            'dbvc_bricks_mothership_url',
            'dbvc_bricks_auth_method',
            'dbvc_bricks_api_key_id',
            'dbvc_bricks_api_secret',
            'dbvc_bricks_command_transport_mode',
            'dbvc_bricks_client_registry_state',
            'dbvc_bricks_intro_handshake_token',
        ];

        $requested_urls = [];
        $http_filter = static function ($preempt, $args, $url) use (&$requested_urls) {
            if (strpos((string) $url, 'https://mothership.example.test/wp-json/dbvc/v1/bricks/') === 0) {
                $requested_urls[] = (string) $url;
                return [
                    'headers' => [],
                    'body' => wp_json_encode(['ok' => true, 'items' => []]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }

            return $preempt;
        };

        foreach ($transport_keys as $key) {
            delete_option($key);
        }

        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_site_uid', 'test_site_a');
        update_option('dbvc_bricks_mothership_url', 'https://mothership.example.test');
        update_option('dbvc_bricks_auth_method', 'wp_app_password');
        update_option('dbvc_bricks_api_key_id', 'operator');
        update_option('dbvc_bricks_api_secret', 'secret');
        update_option('dbvc_bricks_command_transport_mode', 'client_pull_envelope');
        update_option('dbvc_bricks_client_registry_state', 'VERIFIED');
        update_option('dbvc_bricks_intro_handshake_token', 'hs_verified');

        add_filter('pre_http_request', $http_filter, 10, 3);
        try {
            DBVC_Bricks_Addon::bootstrap();
        } finally {
            remove_filter('pre_http_request', $http_filter, 10);
            foreach ($transport_keys as $key) {
                delete_option($key);
            }
        }

        $this->assertSame([], $requested_urls, 'Bricks bootstrap must not perform client transport HTTP calls.');
    }

    public function test_bricks_admin_page_url_is_canonical_under_admin_php_page_param(): void
    {
        $url = DBVC_Bricks_Addon::get_admin_page_url();
        $this->assertStringContainsString('wp-admin/admin.php?page=' . DBVC_Bricks_Addon::MENU_SLUG, $url);
    }

    private function submit_addons_form(bool $enabled, string $visibility): void
    {
        $_POST = [
            'dbvc_config_save'            => ['addons' => '1'],
            'dbvc_config_nonce'           => wp_create_nonce('dbvc_config_save_action'),
            'dbvc_addon_bricks_visibility' => $visibility,
        ];

        if ($enabled) {
            $_POST['dbvc_addon_bricks_enabled'] = '1';
        }

        ob_start();
        dbvc_render_export_page();
        ob_end_clean();
        $_POST = [];
    }

    /**
     * @param array<string, array<int, array<int, string>>> $submenu
     */
    private function has_bricks_submenu(array $submenu): bool
    {
        if (empty($submenu['dbvc-export'])) {
            return false;
        }

        foreach ($submenu['dbvc-export'] as $item) {
            if (($item[2] ?? '') === DBVC_Bricks_Addon::MENU_SLUG) {
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
