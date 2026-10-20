<?php

class BricksAddonPhase9Test extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        update_option('dbvc_bricks_role', 'client');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        $_GET = [];
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        parent::tear_down();
    }

    public function test_drift_response_to_ui_mapping_contains_required_containers(): void
    {
        $output = $this->render_admin_page('differences');
        $this->assertStringContainsString('id="dbvc-bricks-package-select"', $output);
        $this->assertStringContainsString('id="dbvc-bricks-run-drift-scan"', $output);
        $this->assertStringContainsString('id="dbvc-bricks-diff-table-body"', $output);
        $this->assertStringContainsString('id="dbvc-bricks-diff-detail"', $output);
    }

    public function test_filters_search_and_summary_controls_render(): void
    {
        $output = $this->render_admin_page('differences');
        $this->assertStringContainsString('id="dbvc-bricks-filter-class"', $output);
        $this->assertStringContainsString('id="dbvc-bricks-filter-status"', $output);
        $this->assertStringContainsString('id="dbvc-bricks-filter-search"', $output);
        $this->assertStringContainsString('id="dbvc-bricks-count-clean"', $output);
        $this->assertStringContainsString('id="dbvc-bricks-count-diverged"', $output);
        $this->assertStringContainsString('id="dbvc-bricks-count-overridden"', $output);
        $this->assertStringContainsString('id="dbvc-bricks-count-pending"', $output);
    }

    public function test_diff_detail_truncation_metadata_present_in_scan_response(): void
    {
        update_option('bricks_global_classes', ['k1' => 'left', 'k2' => 'left', 'k3' => 'left']);
        $manifest = [
            'package_id' => 'pkg-test',
            'artifacts' => [
                [
                    'artifact_uid' => 'option:bricks_global_classes',
                    'artifact_type' => 'bricks_global_classes',
                    'payload' => ['k1' => 'right', 'k2' => 'right', 'k3' => 'right'],
                ],
            ],
        ];
        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/drift-scan');
        $request->set_body(wp_json_encode([
            'manifest' => $manifest,
            'options' => ['max_changes' => 1],
        ]));
        $request->set_header('content-type', 'application/json');
        $response = rest_get_server()->dispatch($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $summary = $data['artifacts'][0]['diff_summary'] ?? [];
        $this->assertTrue((bool) ($summary['truncated'] ?? false));
        $this->assertTrue((bool) ($summary['raw_available'] ?? false));
        $this->assertSame(1, count($summary['changes'] ?? []));
    }

    public function test_entity_and_option_artifact_labels_are_distinguishable(): void
    {
        $this->assertSame('Template Entity', DBVC_Bricks_Addon::get_artifact_class_label('bricks_template'));
        $this->assertSame('Bricks Setting', DBVC_Bricks_Addon::get_artifact_class_label('bricks_components'));
    }

    private function render_admin_page($tab): string
    {
        $_GET['page'] = DBVC_Bricks_Addon::MENU_SLUG;
        $_GET['tab'] = $tab;
        ob_start();
        DBVC_Bricks_Addon::render_admin_page();
        return (string) ob_get_clean();
    }

    private function reset_rest_server(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
    }
}
