<?php

class BricksAddonPhase4Test extends WP_UnitTestCase
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
        $this->reset_rest_server();
        parent::tear_down();
    }

    public function test_status_classification_and_compare_path(): void
    {
        $manifest = [
            'package_id' => 'pkg-1',
            'artifacts' => [
                ['artifact_uid' => 'option:bricks_global_classes', 'artifact_type' => 'bricks_global_classes', 'payload' => ['a' => 1]],
                ['artifact_uid' => 'option:bricks_components', 'artifact_type' => 'bricks_components', 'payload' => ['a' => 2]],
                ['artifact_uid' => 'option:bricks_color_palette', 'artifact_type' => 'bricks_color_palette', 'payload' => ['a' => 3]],
            ],
        ];
        $local = [
            'option:bricks_global_classes' => ['payload' => ['a' => 1]],
            'option:bricks_components' => ['payload' => ['a' => 99]],
            'option:bricks_color_palette' => ['payload' => ['a' => 0]],
        ];
        $result = DBVC_Bricks_Drift::scan($manifest, $local, [
            'status_overrides' => ['option:bricks_color_palette' => 'pending_review'],
        ]);

        $this->assertIsArray($result);
        $this->assertSame('pkg-1', $result['package_id']);
        $this->assertSame(1, $result['counts']['clean']);
        $this->assertSame(1, $result['counts']['diverged']);
        $this->assertSame(1, $result['counts']['pending_review']);
    }

    public function test_diff_summary_truncation_and_raw_flag(): void
    {
        $many_local = [];
        $many_target = [];
        for ($i = 0; $i < 20; $i++) {
            $many_local['k' . $i] = 'left-' . $i;
            $many_target['k' . $i] = 'right-' . $i;
        }

        $manifest = [
            'package_id' => 'pkg-2',
            'artifacts' => [
                ['artifact_uid' => 'option:bricks_global_classes', 'artifact_type' => 'bricks_global_classes', 'payload' => $many_target],
            ],
        ];
        $local = [
            'option:bricks_global_classes' => ['payload' => $many_local],
        ];
        $result = DBVC_Bricks_Drift::scan($manifest, $local, ['max_changes' => 5]);
        $summary = $result['artifacts'][0]['diff_summary'];

        $this->assertGreaterThan(5, $summary['total']);
        $this->assertCount(5, $summary['changes']);
        $this->assertTrue($summary['truncated']);
        $this->assertTrue($summary['raw_available']);
    }

    public function test_read_only_guard_rejects_write_attempts(): void
    {
        update_option('dbvc_bricks_guard_probe', 'before');
        $server = rest_get_server();
        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/drift-scan');
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode([
            'manifest' => ['package_id' => 'pkg-3', 'artifacts' => []],
            'options' => ['write' => true],
        ]));
        $response = $server->dispatch($request);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('before', get_option('dbvc_bricks_guard_probe'));
    }

    public function test_large_payload_scan_completes_and_returns_counts(): void
    {
        $local_payload = [];
        $target_payload = [];
        for ($i = 0; $i < 1000; $i++) {
            $local_payload[] = ['id' => 'l-' . $i, 'name' => 'left-' . $i];
            $target_payload[] = ['id' => 't-' . $i, 'name' => 'right-' . $i];
        }
        $manifest = [
            'package_id' => 'pkg-large',
            'artifacts' => [
                [
                    'artifact_uid' => 'option:bricks_components',
                    'artifact_type' => 'bricks_components',
                    'payload' => $target_payload,
                ],
            ],
        ];
        $local = [
            'option:bricks_components' => ['payload' => $local_payload],
        ];

        $result = DBVC_Bricks_Drift::scan($manifest, $local, ['max_changes' => 20]);
        $this->assertIsArray($result);
        $this->assertSame(1, count($result['artifacts']));
        $this->assertSame('DIVERGED', $result['artifacts'][0]['status']);
        $this->assertGreaterThan(0, $result['artifacts'][0]['diff_summary']['total']);
    }

    private function reset_rest_server(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
    }
}
