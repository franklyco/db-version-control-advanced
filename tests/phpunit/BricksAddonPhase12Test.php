<?php

class BricksAddonPhase12Test extends WP_UnitTestCase
{
    private $panel_output = '';

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
        remove_all_filters('dbvc_bricks_diff_row_data');
        remove_all_filters('dbvc_bricks_admin_tabs');
        remove_all_filters('dbvc_bricks_governance_overlay');
        remove_all_actions('dbvc_bricks_render_extra_panels');
        $_GET = [];
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        parent::tear_down();
    }

    public function test_extension_hook_contracts_for_diff_tabs_and_panels(): void
    {
        add_filter('dbvc_bricks_diff_row_data', static function ($row) {
            $row['hooked'] = true;
            return $row;
        });
        add_filter('dbvc_bricks_admin_tabs', static function ($tabs) {
            $tabs['custom_panel'] = 'Custom Panel';
            return $tabs;
        });
        add_action('dbvc_bricks_render_extra_panels', [$this, 'render_extra_panel']);

        $scan = DBVC_Bricks_Drift::scan([
            'package_id' => 'hook',
            'artifacts' => [
                ['artifact_uid' => 'option:bricks_global_classes', 'artifact_type' => 'bricks_global_classes', 'payload' => ['a' => 1]],
            ],
        ], [
            'option:bricks_global_classes' => ['payload' => ['a' => 2]],
        ]);
        $this->assertTrue((bool) ($scan['artifacts'][0]['hooked'] ?? false));

        $tabs = DBVC_Bricks_Addon::get_admin_tabs_for_role('client');
        $this->assertArrayHasKey('custom_panel', $tabs);

        $output = $this->render_admin_page();
        $this->assertStringContainsString('dbvc-phase12-extra-panel', $output);
    }

    public function test_manifest_compatibility_normalization_for_legacy_payloads(): void
    {
        $legacy = [
            'manifest' => [
                'id' => 'legacy-1',
                'items' => [
                    [
                        'uid' => 'option:bricks_components',
                        'type' => 'bricks_components',
                        'payload' => ['x' => 'y'],
                    ],
                ],
            ],
        ];
        $normalized = DBVC_Bricks_Addon::normalize_manifest_payload($legacy);
        $this->assertSame('legacy-1', $normalized['package_id']);
        $this->assertSame('legacy_items', $normalized['compatibility']['source_shape']);
        $this->assertSame('option:bricks_components', $normalized['artifacts'][0]['artifact_uid']);
    }

    public function test_bulk_chunk_builder_is_deterministic_and_deduplicated(): void
    {
        $input = ['z', 'b', 'b', 'a', 'c'];
        $first = DBVC_Bricks_Addon::build_bulk_chunks($input, 2);
        $second = DBVC_Bricks_Addon::build_bulk_chunks($input, 2);

        $this->assertSame($first, $second);
        $this->assertSame([['a', 'b'], ['c', 'z']], $first);
    }

    public function test_deprecation_warning_and_contract_endpoint_available(): void
    {
        $output = $this->render_admin_page();
        $this->assertStringContainsString('dbvc-bricks-deprecation-notice', $output);
        $this->assertStringContainsString('Legacy direct admin path', $output);

        $request = new WP_REST_Request('GET', '/dbvc/v1/bricks/ui-contract');
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame(DBVC_Bricks_Addon::UI_CONTRACT_VERSION, $data['ui_contract_version']);
        $this->assertNotEmpty($data['deprecations']);
    }

    public function test_diagnostics_limit_is_bounded_and_returns_latest_items(): void
    {
        $stored = [];
        for ($i = 1; $i <= 130; $i++) {
            $stored[] = ['event_type' => 'status_loaded', 'payload' => ['idx' => $i]];
        }
        update_option(DBVC_Bricks_Addon::OPTION_UI_DIAGNOSTICS, $stored);

        $_GET['limit'] = '9999';
        $request = new WP_REST_Request('GET', '/dbvc/v1/bricks/diagnostics');
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame(DBVC_Bricks_Addon::UI_DIAGNOSTIC_MAX_ITEMS, $data['limit']);
        $this->assertCount(DBVC_Bricks_Addon::UI_DIAGNOSTIC_MAX_ITEMS, $data['items']);
        $this->assertSame(31, $data['items'][0]['payload']['idx']);
        $this->assertSame(130, $data['items'][99]['payload']['idx']);
    }

    public function test_ui_event_sanitizes_payload_and_normalizes_unknown_event_type(): void
    {
        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/ui-event');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('X-DBVC-Correlation-ID', 'cid-123');
        $request->set_body(wp_json_encode([
            'event_type' => 'not-allowed-event',
            'payload' => [
                'Unsafe Key!' => '<script>alert(1)</script>',
                'nested' => ['level2' => ['level3' => ['level4' => 'too-deep']]],
            ],
        ]));

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $entry = $data['entry'];

        $this->assertSame('unknown', $entry['event_type']);
        $this->assertSame('cid-123', $entry['correlation_id']);
        $this->assertArrayHasKey('unsafekey', $entry['payload']);
        $this->assertSame('', $entry['payload']['unsafekey']);
        $this->assertSame('[truncated-depth]', $entry['payload']['nested']['level2']['level3']);

        $stored = get_option(DBVC_Bricks_Addon::OPTION_UI_DIAGNOSTICS, []);
        $this->assertNotEmpty($stored);
    }

    public function render_extra_panel(): void
    {
        echo '<div id="dbvc-phase12-extra-panel">extra</div>';
    }

    private function render_admin_page(): string
    {
        $_GET['page'] = DBVC_Bricks_Addon::MENU_SLUG;
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
