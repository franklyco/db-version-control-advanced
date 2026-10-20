<?php

class BricksAddonPhase19CTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        update_option('dbvc_bricks_role', 'mothership');
        update_option('dbvc_bricks_read_only', '0');
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, []);
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGE_CATALOG, []);
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, []);
        update_option(DBVC_Bricks_Idempotency::OPTION_STORE, []);
        update_option(DBVC_Bricks_Protected_Variants::OPTION_VARIANTS, []);
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        $_GET = [];
        remove_all_filters('pre_http_request');
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_read_only', '0');
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, []);
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGE_CATALOG, []);
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, []);
        update_option(DBVC_Bricks_Idempotency::OPTION_STORE, []);
        update_option(DBVC_Bricks_Protected_Variants::OPTION_VARIANTS, []);
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        parent::tear_down();
    }

    public function test_mothership_protected_variant_fleet_endpoint_aggregates_latest_package_by_site(): void
    {
        DBVC_Bricks_Connected_Sites::upsert_site([
            'site_uid' => 'client_a',
            'site_label' => 'Client A',
            'base_url' => 'https://client-a.local',
            'status' => 'online',
            'allow_receive_packages' => true,
            'last_seen_at' => '2026-03-08T01:00:00+00:00',
        ]);
        DBVC_Bricks_Connected_Sites::upsert_site([
            'site_uid' => 'client_b',
            'site_label' => 'Client B',
            'base_url' => 'https://client-b.local',
            'status' => 'online',
            'allow_receive_packages' => true,
            'last_seen_at' => '2026-03-08T01:05:00+00:00',
        ]);

        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, [
            'pkg-client-a-old' => [
                'package_id' => 'pkg-client-a-old',
                'updated_at' => '2026-03-01T00:00:00+00:00',
                'source_site' => [
                    'site_uid' => 'client_a',
                    'base_url' => 'https://client-a.local',
                ],
                'artifacts' => [],
            ],
            'pkg-client-a-new' => [
                'package_id' => 'pkg-client-a-new',
                'updated_at' => '2026-03-08T02:00:00+00:00',
                'source_site' => [
                    'site_uid' => 'client_a',
                    'base_url' => 'https://client-a.local',
                ],
                'artifacts' => [
                    [
                        'artifact_uid' => 'template:hero',
                        'artifact_type' => 'bricks_template',
                        'artifact_label' => 'Hero Template',
                        'protected_variant' => [
                            'is_protected' => true,
                            'artifact_uid' => 'template:hero',
                            'variant_count' => 1,
                            'variant_ids' => ['pv_template_hero'],
                            'scopes' => ['site_local'],
                            'latest_updated_at' => '2026-03-08T01:30:00+00:00',
                            'latest_reason' => 'Client A template override.',
                        ],
                    ],
                    [
                        'artifact_uid' => 'option:bricks_global_classes',
                        'artifact_type' => 'global_classes',
                        'artifact_label' => 'Global Classes',
                        'protected_variant' => [
                            'is_protected' => true,
                            'artifact_uid' => 'option:bricks_global_classes',
                            'variant_count' => 1,
                            'variant_ids' => ['pv_global_classes'],
                            'scopes' => ['site_local'],
                            'latest_updated_at' => '2026-03-08T01:35:00+00:00',
                            'latest_reason' => 'Client A brand palette.',
                        ],
                    ],
                ],
                'protected_variant_summary' => [
                    'variant_records' => 2,
                    'unique_artifact_uids' => 2,
                    'by_artifact_type' => [
                        'bricks_template' => 1,
                        'global_classes' => 1,
                    ],
                    'by_scope' => [
                        'site_local' => 2,
                    ],
                ],
            ],
            'pkg-client-b' => [
                'package_id' => 'pkg-client-b',
                'updated_at' => '2026-03-08T02:05:00+00:00',
                'source_site' => [
                    'site_uid' => 'client_b',
                    'base_url' => 'https://client-b.local',
                ],
                'artifacts' => [],
            ],
        ]);

        $request = new WP_REST_Request('GET', '/dbvc/v1/bricks/protected-variant-fleet');
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue((bool) ($data['ok'] ?? false));
        $this->assertSame(2, (int) ($data['summary']['site_count'] ?? 0));
        $this->assertSame(1, (int) ($data['summary']['protected_site_count'] ?? 0));
        $this->assertSame(2, (int) ($data['summary']['total_protected_variants'] ?? 0));
        $this->assertSame(1, (int) ($data['summary']['by_artifact_type']['bricks_template'] ?? 0));
        $this->assertSame(1, (int) ($data['summary']['by_artifact_type']['global_classes'] ?? 0));

        $items = $this->index_by_site_uid($data['items'] ?? []);
        $this->assertArrayHasKey('client_a', $items);
        $this->assertArrayHasKey('client_b', $items);
        $this->assertSame('pkg-client-a-new', (string) ($items['client_a']['latest_package_id'] ?? ''));
        $this->assertSame(2, (int) ($items['client_a']['protected_variant_count'] ?? 0));
        $this->assertCount(2, $items['client_a']['variants'] ?? []);
        $this->assertSame(0, (int) ($items['client_b']['protected_variant_count'] ?? -1));
        $this->assertStringContainsString('/wp-admin/admin.php?page=addon-dbvc-bricks-addon&tab=protected_artifacts', (string) ($items['client_a']['protected_artifacts_url'] ?? ''));
        $this->assertSame($items['client_a']['protected_artifacts_url'], $items['client_a']['copy_link']);
    }

    public function test_protected_variant_fleet_endpoint_requires_mothership_role(): void
    {
        update_option('dbvc_bricks_role', 'client');

        $request = new WP_REST_Request('GET', '/dbvc/v1/bricks/protected-variant-fleet');
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(400, $response->get_status());
        $this->assertSame('dbvc_bricks_protected_variant_fleet_role_invalid', (string) ($response->get_data()['code'] ?? ''));
    }

    public function test_mothership_packages_panel_renders_protected_variant_visibility_controls(): void
    {
        update_option('dbvc_bricks_role', 'mothership');
        $render = $this->render_admin_page('packages');
        $this->assertStringContainsString('id="dbvc-bricks-refresh-protected-fleet"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-protected-fleet-body"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-protected-fleet-detail"', $render);
        $this->assertStringContainsString('protectedVariantFleetEndpoint', $render);
        $this->assertStringContainsString('function loadProtectedFleet()', $render);
        $this->assertStringContainsString('Open Protected Local Changes', $render);
        $this->assertStringContainsString('Copy Link', $render);
    }

    public function test_remote_publish_sends_client_protected_variant_summary_to_mothership(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_site_uid', 'client_a');
        update_option('dbvc_bricks_auth_method', 'wp_app_password');
        update_option('dbvc_bricks_api_key_id', 'integration_user');
        update_option('dbvc_bricks_api_secret', 'app password');
        update_option('dbvc_bricks_mothership_url', 'https://mothership.local');
        update_option('dbvc_bricks_http_timeout', '30');
        update_option('dbvc_bricks_tls_verify', '1');
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, [
            'pkg-client-protected' => [
                'package_id' => 'pkg-client-protected',
                'version' => '1.0.0',
                'channel' => 'canary',
                'source_site' => [
                    'site_uid' => 'client_a',
                    'base_url' => 'https://client-a.local',
                ],
                'artifacts' => [
                    [
                        'artifact_uid' => 'option:bricks_theme_styles',
                        'artifact_type' => 'bricks_theme_styles',
                        'artifact_label' => 'Theme Styles',
                    ],
                ],
            ],
        ]);

        $create = new WP_REST_Request('POST', '/dbvc/v1/bricks/protected-variants');
        $create->set_header('Content-Type', 'application/json');
        $create->set_header('Idempotency-Key', 'p19c-protected-create');
        $create->set_body(wp_json_encode([
            'artifact_uid' => 'option:bricks_theme_styles',
            'artifact_type' => 'bricks_theme_styles',
            'label' => 'Theme Styles',
            'reason' => 'Intentional client theme override.',
            'scope' => 'site_local',
        ]));
        $create_response = rest_get_server()->dispatch($create);
        $this->assertSame(200, $create_response->get_status());

        $captured = [];
        add_filter('pre_http_request', static function ($preempt, $args, $url) use (&$captured) {
            $captured[] = [
                'url' => $url,
                'args' => $args,
            ];
            if (strpos((string) $url, '/wp-json/dbvc/v1/bricks/packages/pkg-client-protected') !== false) {
                return [
                    'headers' => [],
                    'body' => wp_json_encode([
                        'manifest' => [
                            'package_id' => 'pkg-client-protected',
                            'channel' => 'stable',
                            'artifact_count' => 1,
                            'receipt_id' => 'pkg_rcpt_p19c_verify',
                            'updated_at' => '2026-05-05T07:30:00+00:00',
                        ],
                    ]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }
            return [
                'headers' => [],
                'body' => wp_json_encode(['ok' => true, 'package_id' => 'pkg-client-protected']),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies' => [],
                'filename' => null,
            ];
        }, 10, 3);

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/publish-remote');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Idempotency-Key', 'p19c-remote-protected');
        $request->set_body(wp_json_encode([
            'package_id' => 'pkg-client-protected',
            'targeting' => ['mode' => 'all', 'site_uids' => []],
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $this->assertCount(2, $captured);
        $this->assertSame('https://mothership.local/wp-json/dbvc/v1/bricks/packages', (string) ($captured[0]['url'] ?? ''));
        $this->assertSame('https://mothership.local/wp-json/dbvc/v1/bricks/packages/pkg-client-protected', (string) ($captured[1]['url'] ?? ''));

        $body = json_decode((string) ($captured[0]['args']['body'] ?? ''), true);
        $this->assertIsArray($body);
        $package = $body['package'] ?? [];
        $this->assertSame(1, (int) ($package['protected_variant_summary']['variant_records'] ?? 0));
        $this->assertArrayHasKey('option:bricks_theme_styles', $package['protected_variant_lookup'] ?? []);
        $this->assertTrue((bool) ($package['artifacts'][0]['protected_variant']['is_protected'] ?? false));
        $this->assertSame('client_a', (string) ($package['source_site']['site_uid'] ?? ''));
    }

    /**
     * @param mixed $items
     * @return array<string, array<string, mixed>>
     */
    private function index_by_site_uid($items): array
    {
        $indexed = [];
        foreach ((array) $items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $site_uid = (string) ($item['site_uid'] ?? '');
            if ($site_uid !== '') {
                $indexed[$site_uid] = $item;
            }
        }
        return $indexed;
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
