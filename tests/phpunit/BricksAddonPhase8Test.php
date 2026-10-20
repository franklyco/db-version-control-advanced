<?php

class BricksAddonPhase8Test extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        DBVC_Bricks_Addon::refresh_runtime_registration();
    }

    public function tear_down(): void
    {
        $_GET = [];
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        update_option('dbvc_bricks_read_only', '0');
        update_option('dbvc_bricks_role', 'client');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        parent::tear_down();
    }

    public function test_submenu_page_render_and_capability_guard(): void
    {
        $output = $this->render_admin_page();
        $this->assertStringContainsString('DBVC Bricks Add-on', $output);
        $this->assertStringContainsString('dbvc-bricks-admin-tabs', $output);
        $this->assertStringContainsString('id="dbvc-bricks-onboarding"', $output);
        $this->assertStringContainsString('id="dbvc-bricks-onboarding-list"', $output);

        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);
        $this->expect_wp_die_message('permissions');
    }

    public function test_role_based_tab_visibility_for_client_and_mothership(): void
    {
        update_option('dbvc_bricks_role', 'client');
        $client = $this->render_admin_page();
        $this->assertStringContainsString('id="dbvc-bricks-panel-configure"', $client);
        $this->assertStringContainsString('id="dbvc-bricks-configure-tabs"', $client);
        $this->assertStringContainsString('id="dbvc-bricks-config-tab-basic"', $client);
        $this->assertStringContainsString('id="dbvc-bricks-config-tab-advanced"', $client);
        $this->assertStringContainsString('id="dbvc-bricks-config-tab-artifacts_rules"', $client);
        $this->assertStringNotContainsString('config_tab=', $client);
        $this->assertStringContainsString('Apply &amp; Backups', $client);
        $this->assertStringContainsString('id="dbvc-bricks-panel-documentation"', $client);
        $this->assertStringContainsString('id="dbvc-bricks-user-doc-library"', $client);
        $this->assertStringContainsString('id="dbvc-bricks-panel-packages"', $client);

        update_option('dbvc_bricks_role', 'mothership');
        $mothership = $this->render_admin_page();
        $this->assertStringContainsString('id="dbvc-bricks-panel-configure"', $mothership);
        $this->assertStringContainsString('id="dbvc-bricks-panel-packages"', $mothership);
        $this->assertStringContainsString('id="dbvc-bricks-panel-documentation"', $mothership);
        $this->assertStringNotContainsString('id="dbvc-bricks-panel-apply-restore"', $mothership);
    }

    public function test_user_documentation_library_payload_parses_seed_sections(): void
    {
        $sample = "### Sample Section\n\n- `CLEAN`\n- `DIVERGED`\n\nText with `code` token.";
        $html = DBVC_Bricks_Addon::render_user_documentation_markdown_fragment($sample);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<code>CLEAN</code>', $html);
        $this->assertStringContainsString('<p>Text with <code>code</code> token.</p>', $html);

        $payload = DBVC_Bricks_Addon::get_user_documentation_library_payload();
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('available', $payload);
        $this->assertArrayHasKey('title', $payload);
        $this->assertArrayHasKey('sections', $payload);
    }

    public function test_save_rules_configuration_updates_only_rule_fields(): void
    {
        update_option('dbvc_bricks_read_only', '1');
        update_option('dbvc_bricks_diff_path_rules', '{}');
        update_option('dbvc_bricks_artifact_ignore_rules', '{}');
        update_option('dbvc_bricks_meta_mask_rules', '{}');

        $result = DBVC_Bricks_Addon::save_rules_configuration([
            'dbvc_bricks_diff_path_rules' => '{"ignore":{"*":["meta.runtime.last_seen"]}}',
            'dbvc_bricks_artifact_ignore_rules' => '{"bricks_template":["meta._edit_lock"]}',
            'dbvc_bricks_meta_mask_rules' => '{"*":["auth.token"]}',
        ]);

        $this->assertEmpty($result['errors']);
        $this->assertSame('1', get_option('dbvc_bricks_read_only'));
        $this->assertStringContainsString('meta.runtime.last_seen', (string) get_option('dbvc_bricks_diff_path_rules'));
        $this->assertStringContainsString('meta._edit_lock', (string) get_option('dbvc_bricks_artifact_ignore_rules'));
        $this->assertStringContainsString('auth.token', (string) get_option('dbvc_bricks_meta_mask_rules'));
    }

    public function test_read_only_state_disables_mutating_controls(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_read_only', '1');

        $output = $this->render_admin_page();
        $this->assertStringContainsString('dbvc-bricks-read-only-notice', $output);
        $this->assertStringContainsString('id="dbvc-bricks-apply-selected" disabled="disabled"', $output);
        $this->assertStringContainsString('id="dbvc-bricks-create-restore-point" disabled="disabled"', $output);
        $this->assertStringContainsString('id="dbvc-bricks-submit-proposal" disabled="disabled"', $output);
    }

    public function test_disabled_mode_blocks_submenu_runtime_actions(): void
    {
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        DBVC_Bricks_Addon::refresh_runtime_registration();

        $output = $this->render_admin_page();
        $this->assertStringContainsString('Bricks Add-on is turned off', $output);
        $this->assertStringNotContainsString('dbvc-bricks-admin-tabs', $output);
        $this->assertStringNotContainsString('dbvc-bricks-apply-selected', $output);
    }

    private function render_admin_page(): string
    {
        ob_start();
        DBVC_Bricks_Addon::render_admin_page();
        return (string) ob_get_clean();
    }

    private function expect_wp_die_message(string $contains): void
    {
        $die_handler = static function ($message) {
            throw new RuntimeException(wp_strip_all_tags((string) $message));
        };
        $handler_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        add_filter('wp_die_handler', $handler_filter);

        try {
            DBVC_Bricks_Addon::render_admin_page();
            $this->fail('Expected wp_die to be called.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($contains, strtolower($exception->getMessage()));
        } finally {
            remove_filter('wp_die_handler', $handler_filter);
        }
    }
}
