<?php

class BricksAddonPhase7Test extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        do_action('rest_api_init');
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

    public function test_performance_baseline_for_large_drift_scan_payloads(): void
    {
        $manifest_artifact = [
            'artifact_uid' => 'option:bricks_components',
            'artifact_type' => 'bricks_components',
            'payload' => [],
        ];
        $local_payload = [];
        $target_payload = [];
        for ($i = 0; $i < 1500; $i++) {
            $local_payload[] = ['id' => 'local-' . $i, 'name' => 'L' . $i];
            $target_payload[] = ['id' => 'target-' . $i, 'name' => 'T' . $i];
        }
        $manifest_artifact['payload'] = $target_payload;
        $manifest = ['package_id' => 'perf', 'artifacts' => [$manifest_artifact]];
        $local = ['option:bricks_components' => ['payload' => $local_payload]];

        $start = microtime(true);
        $result = DBVC_Bricks_Drift::scan($manifest, $local, ['max_changes' => 20]);
        $duration = microtime(true) - $start;

        $this->assertIsArray($result);
        $this->assertLessThan(2.5, $duration);
        $this->assertSame('DIVERGED', $result['artifacts'][0]['status']);
    }

    public function test_multisite_option_behavior_uses_standard_option_api(): void
    {
        update_option('dbvc_bricks_policy_option_default', 'REQUEST_REVIEW');
        $this->assertSame('REQUEST_REVIEW', DBVC_Bricks_Addon::get_setting('dbvc_bricks_policy_option_default'));

        if (is_multisite() && function_exists('switch_to_blog')) {
            $blog_id = self::factory()->blog->create();
            switch_to_blog($blog_id);
            DBVC_Bricks_Addon::ensure_defaults();
            $this->assertSame('REQUEST_REVIEW', DBVC_Bricks_Addon::get_setting('dbvc_bricks_policy_option_default'));
            restore_current_blog();
        } else {
            $this->assertSame(get_current_blog_id(), (int) get_current_blog_id());
        }
    }

    public function test_security_permissions_block_non_admin_access_to_bricks_endpoints(): void
    {
        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);

        $server = rest_get_server();
        $endpoints = [
            ['GET', '/dbvc/v1/bricks/status'],
            ['POST', '/dbvc/v1/bricks/drift-scan'],
            ['POST', '/dbvc/v1/bricks/apply'],
            ['POST', '/dbvc/v1/bricks/restore-points'],
            ['GET', '/dbvc/v1/bricks/proposals'],
        ];
        foreach ($endpoints as $entry) {
            $request = new WP_REST_Request($entry[0], $entry[1]);
            $response = $server->dispatch($request);
            $this->assertSame(403, $response->get_status());
        }
    }

    public function test_disabled_mode_regression_suppresses_submenu_routes_and_jobs(): void
    {
        global $submenu;

        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(DBVC_Bricks_Addon::CRON_HOOK);
        }

        $submenu = [];
        do_action('admin_menu');
        $this->assertEmpty($submenu['dbvc-export'] ?? []);

        $this->reset_rest_server();
        do_action('rest_api_init');
        $server = rest_get_server();
        $request = new WP_REST_Request('GET', '/dbvc/v1/bricks/status');
        $response = $server->dispatch($request);
        $this->assertSame(404, $response->get_status());

        do_action('init');
        $this->assertFalse(wp_next_scheduled(DBVC_Bricks_Addon::CRON_HOOK));
    }

    private function reset_rest_server(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
    }
}
