<?php

class BricksAddonPhase16Test extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        update_option('dbvc_bricks_role', 'mothership');
        update_option('dbvc_bricks_diff_path_rules', '{}');
        update_option('dbvc_bricks_artifact_ignore_rules', '{}');
        update_option('dbvc_bricks_artifact_mask_rules', '{}');
        update_option('dbvc_bricks_meta_ignore_rules', '{}');
        update_option('dbvc_bricks_meta_mask_rules', '{}');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        update_option('dbvc_bricks_diff_path_rules', '{}');
        update_option('dbvc_bricks_artifact_ignore_rules', '{}');
        update_option('dbvc_bricks_artifact_mask_rules', '{}');
        update_option('dbvc_bricks_meta_ignore_rules', '{}');
        update_option('dbvc_bricks_meta_mask_rules', '{}');
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        parent::tear_down();
    }

    public function test_drift_scan_applies_ignore_and_mask_path_rules(): void
    {
        update_option('dbvc_bricks_diff_path_rules', wp_json_encode([
            'ignore' => [
                '*' => ['meta.runtime.last_seen'],
            ],
            'mask' => [
                '*' => ['meta.auth.token'],
            ],
        ]));

        $manifest = [
            'package_id' => 'pkg-p16-rules',
            'artifacts' => [
                [
                    'artifact_uid' => 'option:bricks_global_classes',
                    'artifact_type' => 'bricks_global_classes',
                    'payload' => [
                        'meta' => [
                            'runtime' => ['last_seen' => '2026-02-15T00:00:00Z'],
                            'auth' => ['token' => 'golden-secret'],
                            'stable' => 'same',
                        ],
                    ],
                ],
            ],
        ];
        $local = [
            'option:bricks_global_classes' => [
                'payload' => [
                    'meta' => [
                        'runtime' => ['last_seen' => '2026-02-16T00:00:00Z'],
                        'auth' => ['token' => 'local-secret'],
                        'stable' => 'same',
                    ],
                ],
            ],
        ];

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/drift-scan');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'manifest' => $manifest,
            'local_artifacts' => $local,
            'options' => [
                'max_changes' => 50,
                'include_raw_compare' => true,
                'max_raw_compare_bytes' => 65536,
            ],
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $row = $data['artifacts'][0] ?? [];
        $this->assertSame('CLEAN', $row['status'] ?? '');
        $this->assertSame(0, (int) (($row['diff_summary']['total'] ?? -1)));

        $raw_compare = $row['raw_compare'] ?? [];
        $this->assertSame(['meta.runtime.last_seen'], $raw_compare['rules_applied']['ignore_paths'] ?? []);
        $this->assertSame(['meta.auth.token'], $raw_compare['rules_applied']['mask_paths'] ?? []);

        $local_json = (string) ($raw_compare['local']['json'] ?? '');
        $golden_json = (string) ($raw_compare['golden']['json'] ?? '');
        $this->assertStringContainsString('[masked]', $local_json);
        $this->assertStringContainsString('[masked]', $golden_json);
        $this->assertStringNotContainsString('last_seen', $local_json);
        $this->assertStringNotContainsString('last_seen', $golden_json);
    }

    public function test_drift_compare_endpoint_returns_raw_compare_payload(): void
    {
        update_option('dbvc_bricks_diff_path_rules', wp_json_encode([
            'ignore' => [
                'option:bricks_color_palette' => ['colors.0.updated_at'],
            ],
            'mask' => [
                'option:bricks_color_palette' => ['colors.0.value'],
            ],
        ]));

        $manifest = [
            'package_id' => 'pkg-p16-compare',
            'artifacts' => [
                [
                    'artifact_uid' => 'option:bricks_color_palette',
                    'artifact_type' => 'bricks_color_palette',
                    'payload' => [
                        'colors' => [
                            [
                                'slug' => 'brand',
                                'value' => '#ff0000',
                                'updated_at' => '2026-02-15T00:00:00Z',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $local = [
            'option:bricks_color_palette' => [
                'payload' => [
                    'colors' => [
                        [
                            'slug' => 'brand',
                            'value' => '#00ff00',
                            'updated_at' => '2026-02-16T00:00:00Z',
                        ],
                    ],
                ],
            ],
        ];

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/drift-compare');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'manifest' => $manifest,
            'local_artifacts' => $local,
            'artifact_uid' => 'option:bricks_color_palette',
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertSame('option:bricks_color_palette', $data['artifact_uid'] ?? '');
        $this->assertSame(0, (int) (($data['diff_summary']['total'] ?? -1)));
        $this->assertSame(['colors.0.updated_at'], $data['raw_compare']['rules_applied']['ignore_paths'] ?? []);
        $this->assertSame(['colors.0.value'], $data['raw_compare']['rules_applied']['mask_paths'] ?? []);
    }

    public function test_drift_rules_option_accepts_array_storage_shape(): void
    {
        update_option('dbvc_bricks_diff_path_rules', [
            'ignore' => ['*' => ['meta.runtime.last_seen']],
            'mask' => ['*' => ['meta.auth.token']],
        ]);

        $manifest = [
            'package_id' => 'pkg-p16-array-rules',
            'artifacts' => [
                [
                    'artifact_uid' => 'option:bricks_theme_styles',
                    'artifact_type' => 'bricks_theme_styles',
                    'payload' => [
                        'meta' => [
                            'runtime' => ['last_seen' => '2026-02-15T00:00:00Z'],
                            'auth' => ['token' => 'golden-token'],
                        ],
                    ],
                ],
            ],
        ];
        $local = [
            'option:bricks_theme_styles' => [
                'payload' => [
                    'meta' => [
                        'runtime' => ['last_seen' => '2026-02-16T00:00:00Z'],
                        'auth' => ['token' => 'local-token'],
                    ],
                ],
            ],
        ];

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/drift-scan');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'manifest' => $manifest,
            'local_artifacts' => $local,
            'options' => ['include_raw_compare' => true],
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        $row = ($response->get_data()['artifacts'] ?? [])[0] ?? [];
        $this->assertSame('CLEAN', $row['status'] ?? '');
        $this->assertSame(['meta.auth.token'], $row['raw_compare']['rules_applied']['mask_paths'] ?? []);
        $this->assertSame(['meta.runtime.last_seen'], $row['raw_compare']['rules_applied']['ignore_paths'] ?? []);
    }

    public function test_expanded_artifact_and_meta_rules_merge_into_compare_payload(): void
    {
        update_option('dbvc_bricks_diff_path_rules', wp_json_encode([
            'ignore' => ['*' => ['meta.runtime.last_seen']],
            'mask' => ['*' => ['meta.auth.token']],
        ]));
        update_option('dbvc_bricks_artifact_ignore_rules', wp_json_encode([
            'option:bricks_theme_styles' => ['settings.unused'],
        ]));
        update_option('dbvc_bricks_artifact_mask_rules', wp_json_encode([
            'option:bricks_theme_styles' => ['settings.secret'],
        ]));
        update_option('dbvc_bricks_meta_ignore_rules', wp_json_encode([
            '*' => ['runtime.generated_at'],
        ]));
        update_option('dbvc_bricks_meta_mask_rules', wp_json_encode([
            '*' => ['auth.api_key'],
        ]));

        $manifest = [
            'package_id' => 'pkg-p16-expanded-rules',
            'artifacts' => [
                [
                    'artifact_uid' => 'option:bricks_theme_styles',
                    'artifact_type' => 'bricks_theme_styles',
                    'payload' => [
                        'meta' => [
                            'runtime' => ['last_seen' => '2026-02-15T00:00:00Z', 'generated_at' => '2026-02-15T00:00:01Z'],
                            'auth' => ['token' => 'golden-token', 'api_key' => 'golden-api'],
                        ],
                        'settings' => ['unused' => 'x', 'secret' => 'golden-secret'],
                    ],
                ],
            ],
        ];
        $local = [
            'option:bricks_theme_styles' => [
                'payload' => [
                    'meta' => [
                        'runtime' => ['last_seen' => '2026-02-16T00:00:00Z', 'generated_at' => '2026-02-16T00:00:01Z'],
                        'auth' => ['token' => 'local-token', 'api_key' => 'local-api'],
                    ],
                    'settings' => ['unused' => 'y', 'secret' => 'local-secret'],
                ],
            ],
        ];

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/drift-scan');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'manifest' => $manifest,
            'local_artifacts' => $local,
            'options' => ['include_raw_compare' => true],
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        $row = ($response->get_data()['artifacts'] ?? [])[0] ?? [];
        $ignore_paths = $row['raw_compare']['rules_applied']['ignore_paths'] ?? [];
        $mask_paths = $row['raw_compare']['rules_applied']['mask_paths'] ?? [];
        $this->assertContains('meta.runtime.last_seen', $ignore_paths);
        $this->assertContains('settings.unused', $ignore_paths);
        $this->assertContains('meta.runtime.generated_at', $ignore_paths);
        $this->assertContains('meta.auth.token', $mask_paths);
        $this->assertContains('settings.secret', $mask_paths);
        $this->assertContains('meta.auth.api_key', $mask_paths);
        $this->assertSame('CLEAN', $row['status'] ?? '');
    }

    public function test_drift_scan_returns_artifact_label_for_template_and_option(): void
    {
        $manifest = [
            'package_id' => 'pkg-p16-labels',
            'artifacts' => [
                [
                    'artifact_uid' => 'entity:bricks_template:101',
                    'artifact_type' => 'bricks_template',
                    'payload' => ['ID' => 101, 'post_title' => 'Homepage Hero'],
                ],
                [
                    'artifact_uid' => 'option:bricks_global_settings',
                    'artifact_type' => 'bricks_global_settings',
                    'payload' => ['version' => 1],
                ],
            ],
        ];
        $local = [
            'entity:bricks_template:101' => ['payload' => ['ID' => 101, 'post_title' => 'Homepage Hero']],
            'option:bricks_global_settings' => ['payload' => ['version' => 1]],
        ];

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/drift-scan');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'manifest' => $manifest,
            'local_artifacts' => $local,
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $items = $response->get_data()['artifacts'] ?? [];
        $this->assertSame('Homepage Hero', $items[0]['artifact_label'] ?? '');
        $this->assertSame('bricks_global_settings', $items[1]['artifact_label'] ?? '');
    }

    public function test_packages_list_and_get_include_source_site_domain(): void
    {
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, [
            'pkg-p16-domain' => [
                'package_id' => 'pkg-p16-domain',
                'version' => '2026.02.15.1',
                'channel' => 'stable',
                'status' => DBVC_Bricks_Packages::STATUS_PUBLISHED,
                'source_site' => [
                    'site_uid' => 'client_a',
                    'base_url' => 'https://dbvc-codexchanges.local',
                ],
                'artifacts' => [],
                'targeting' => ['mode' => 'all', 'site_uids' => []],
                'updated_at' => gmdate('c'),
            ],
        ]);

        $list = new WP_REST_Request('GET', '/dbvc/v1/bricks/packages');
        $list_response = rest_get_server()->dispatch($list);
        $this->assertSame(200, $list_response->get_status());
        $items = $list_response->get_data()['items'] ?? [];
        $this->assertSame('dbvc-codexchanges.local', $items[0]['source_site_domain'] ?? '');

        $get = new WP_REST_Request('GET', '/dbvc/v1/bricks/packages/pkg-p16-domain');
        $get_response = rest_get_server()->dispatch($get);
        $this->assertSame(200, $get_response->get_status());
        $manifest = $get_response->get_data()['manifest'] ?? [];
        $this->assertSame('dbvc-codexchanges.local', $manifest['source_site_domain'] ?? '');
    }

    public function test_rules_configuration_rest_get_and_partial_post(): void
    {
        update_option('dbvc_bricks_diff_path_rules', '{"ignore":{"*":["meta.runtime.last_seen"]}}');
        update_option('dbvc_bricks_artifact_ignore_rules', '{"*":["meta._edit_lock"]}');
        update_option('dbvc_bricks_meta_mask_rules', '{"*":["auth.token"]}');

        $get = new WP_REST_Request('GET', '/dbvc/v1/bricks/configure/rules');
        $get_response = rest_get_server()->dispatch($get);
        $this->assertSame(200, $get_response->get_status());
        $get_data = $get_response->get_data();
        $this->assertArrayHasKey('fields', $get_data);
        $this->assertArrayHasKey('values', $get_data);
        $this->assertStringContainsString('meta.runtime.last_seen', (string) ($get_data['values']['dbvc_bricks_diff_path_rules'] ?? ''));

        $post = new WP_REST_Request('POST', '/dbvc/v1/bricks/configure/rules');
        $post->set_header('Content-Type', 'application/json');
        $post->set_body(wp_json_encode([
            'dbvc_bricks_meta_ignore_rules' => ['*' => ['runtime.generated_at']],
        ]));
        $post_response = rest_get_server()->dispatch($post);
        $this->assertSame(200, $post_response->get_status());
        $post_data = $post_response->get_data();
        $this->assertTrue((bool) ($post_data['ok'] ?? false));
        $this->assertStringContainsString('meta.runtime.last_seen', (string) get_option('dbvc_bricks_diff_path_rules'));
        $this->assertStringContainsString('runtime.generated_at', (string) get_option('dbvc_bricks_meta_ignore_rules'));
    }

    public function test_rules_configuration_rest_post_rejects_invalid_json_string(): void
    {
        update_option('dbvc_bricks_diff_path_rules', '{"ignore":{"*":["meta.runtime.last_seen"]}}');

        $post = new WP_REST_Request('POST', '/dbvc/v1/bricks/configure/rules');
        $post->set_header('Content-Type', 'application/json');
        $post->set_body(wp_json_encode([
            'dbvc_bricks_diff_path_rules' => '{"ignore":',
        ]));
        $response = rest_get_server()->dispatch($post);
        $this->assertSame(400, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse((bool) ($data['ok'] ?? true));
        $this->assertNotEmpty($data['errors'] ?? []);
        $this->assertStringContainsString('meta.runtime.last_seen', (string) get_option('dbvc_bricks_diff_path_rules'));
    }

    private function reset_rest_server(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }
}
