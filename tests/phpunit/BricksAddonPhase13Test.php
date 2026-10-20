<?php

class BricksAddonPhase13Test extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, []);
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGE_CATALOG, []);
        update_option(DBVC_Bricks_Packages::OPTION_PUBLISH_RUNS, []);
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, []);
        update_option(DBVC_Bricks_Idempotency::OPTION_STORE, []);
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        $_GET = [];
        remove_all_filters('pre_http_request');
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, []);
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGE_CATALOG, []);
        update_option(DBVC_Bricks_Packages::OPTION_PUBLISH_RUNS, []);
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, []);
        update_option(DBVC_Bricks_Idempotency::OPTION_STORE, []);
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        parent::tear_down();
    }

    public function test_connected_site_upsert_and_list_routes(): void
    {
        $upsert = new WP_REST_Request('POST', '/dbvc/v1/bricks/connected-sites');
        $upsert->set_header('Content-Type', 'application/json');
        $upsert->set_body(wp_json_encode([
            'site_uid' => 'client_a',
            'site_label' => 'Client A',
            'base_url' => 'https://client-a.local',
            'status' => 'online',
            'allow_receive_packages' => true,
        ]));
        $response = rest_get_server()->dispatch($upsert);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue((bool) ($data['ok'] ?? false));

        $list = new WP_REST_Request('GET', '/dbvc/v1/bricks/connected-sites');
        $list_response = rest_get_server()->dispatch($list);
        $this->assertSame(200, $list_response->get_status());
        $items = $list_response->get_data()['items'] ?? [];
        $this->assertCount(1, $items);
        $this->assertSame('client_a', $items[0]['site_uid']);
    }

    public function test_package_publish_requires_idempotency_key(): void
    {
        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'package' => [
                'package_id' => 'pkg-no-key',
                'version' => '1.0.0',
                'channel' => 'canary',
                'artifacts' => [],
            ],
            'targeting' => ['mode' => 'all'],
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(400, $response->get_status());
        $this->assertSame('dbvc_bricks_idempotency_required', $response->get_data()['code']);
    }

    public function test_selected_targeting_requires_allowed_connected_sites(): void
    {
        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Idempotency-Key', 'idemp-selected-invalid');
        $request->set_body(wp_json_encode([
            'package' => [
                'package_id' => 'pkg-target-invalid',
                'version' => '1.0.0',
                'channel' => 'canary',
                'artifacts' => [],
            ],
            'targeting' => [
                'mode' => 'selected',
                'site_uids' => ['unknown_site'],
            ],
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(400, $response->get_status());
        $this->assertSame('dbvc_bricks_target_site_invalid', $response->get_data()['code']);
    }

    public function test_package_publish_idempotency_and_site_filtered_visibility(): void
    {
        DBVC_Bricks_Connected_Sites::upsert_site([
            'site_uid' => 'client_a',
            'site_label' => 'Client A',
            'base_url' => 'https://client-a.local',
            'status' => 'online',
            'allow_receive_packages' => true,
        ]);

        $publish = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages');
        $publish->set_header('Content-Type', 'application/json');
        $publish->set_header('Idempotency-Key', 'idemp-pkg-1');
        $publish->set_body(wp_json_encode([
            'package' => [
                'package_id' => 'pkg-targeted-a',
                'version' => '1.0.1',
                'channel' => 'canary',
                'artifacts' => [
                    ['artifact_uid' => 'option:bricks_global_classes', 'artifact_type' => 'bricks_global_classes', 'hash' => 'sha256:a'],
                ],
            ],
            'targeting' => [
                'mode' => 'selected',
                'site_uids' => ['client_a'],
            ],
        ]));
        $first = rest_get_server()->dispatch($publish);
        $this->assertSame(200, $first->get_status());
        $first_data = $first->get_data();
        $this->assertSame('pkg-targeted-a', $first_data['package_id']);
        $this->assertSame('selected', $first_data['targeting']['mode']);

        $publish_replay = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages');
        $publish_replay->set_header('Content-Type', 'application/json');
        $publish_replay->set_header('Idempotency-Key', 'idemp-pkg-1');
        $publish_replay->set_body($publish->get_body());
        $second = rest_get_server()->dispatch($publish_replay);
        $this->assertSame(200, $second->get_status());
        $this->assertSame($first_data, $second->get_data());

        $list_a = new WP_REST_Request('GET', '/dbvc/v1/bricks/packages');
        $list_a->set_param('site_uid', 'client_a');
        $list_a_response = rest_get_server()->dispatch($list_a);
        $this->assertSame(200, $list_a_response->get_status());
        $items_a = $list_a_response->get_data()['items'] ?? [];
        $this->assertCount(1, $items_a);

        $list_b = new WP_REST_Request('GET', '/dbvc/v1/bricks/packages');
        $list_b->set_param('site_uid', 'client_b');
        $list_b_response = rest_get_server()->dispatch($list_b);
        $this->assertSame(200, $list_b_response->get_status());
        $items_b = $list_b_response->get_data()['items'] ?? [];
        $this->assertCount(0, $items_b);
    }

    public function test_remote_publish_requires_client_role_and_wp_app_password(): void
    {
        update_option('dbvc_bricks_role', 'mothership');
        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/publish-remote');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Idempotency-Key', 'remote-role-check');
        $request->set_body(wp_json_encode([
            'package' => ['package_id' => 'pkg-role', 'version' => '1.0.0', 'channel' => 'canary', 'artifacts' => []],
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(400, $response->get_status());
        $this->assertSame('dbvc_bricks_publish_remote_role_invalid', $response->get_data()['code']);
    }

    public function test_remote_publish_uses_basic_auth_and_returns_remote_response(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_site_uid', 'client_a');
        update_option('dbvc_bricks_auth_method', 'wp_app_password');
        update_option('dbvc_bricks_api_key_id', 'integration_user');
        update_option('dbvc_bricks_api_secret', 'app password');
        update_option('dbvc_bricks_mothership_url', 'https://mothership.local');
        update_option('dbvc_bricks_http_timeout', '30');
        update_option('dbvc_bricks_tls_verify', '1');

        $captured = [];
        add_filter('pre_http_request', static function ($preempt, $args, $url) use (&$captured) {
            $captured[] = [
                'url' => $url,
                'args' => $args,
            ];
            if (strpos((string) $url, '/wp-json/dbvc/v1/bricks/packages/pkg-remote-ok') !== false) {
                return [
                    'headers' => [],
                    'body' => wp_json_encode([
                        'manifest' => [
                            'package_id' => 'pkg-remote-ok',
                            'channel' => 'canary',
                            'artifact_count' => 0,
                            'receipt_id' => 'pkg_rcpt_verify',
                            'updated_at' => '2026-05-05T05:50:00+00:00',
                        ],
                    ]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }
            return [
                'headers' => [],
                'body' => wp_json_encode(['ok' => true, 'package_id' => 'pkg-remote-ok']),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies' => [],
                'filename' => null,
            ];
        }, 10, 3);

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/publish-remote');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Idempotency-Key', 'remote-success-check');
        $request->set_body(wp_json_encode([
            'package' => ['package_id' => 'pkg-remote-1', 'version' => '1.0.0', 'channel' => 'canary', 'artifacts' => []],
            'targeting' => ['mode' => 'all', 'site_uids' => []],
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue((bool) ($data['ok'] ?? false));
        $this->assertCount(2, $captured);
        $this->assertSame('https://mothership.local/wp-json/dbvc/v1/bricks/packages', $captured[0]['url']);
        $this->assertSame('https://mothership.local/wp-json/dbvc/v1/bricks/packages/pkg-remote-ok', $captured[1]['url']);
        $this->assertStringStartsWith('Basic ', (string) ($captured[0]['args']['headers']['Authorization'] ?? ''));
        $this->assertTrue((bool) ($data['remote_package_visibility']['ok'] ?? false));

        $runs_request = new WP_REST_Request('GET', '/dbvc/v1/bricks/packages/publish-runs');
        $runs_response = rest_get_server()->dispatch($runs_request);
        $this->assertSame(200, $runs_response->get_status());
        $runs = $runs_response->get_data()['items'] ?? [];
        $this->assertNotEmpty($runs);
        $this->assertSame('pkg-remote-1', (string) ($runs[0]['package_id'] ?? ''));
        $this->assertSame('success', (string) ($runs[0]['result'] ?? ''));
        $this->assertTrue((bool) ($runs[0]['remote_package_visibility']['ok'] ?? false));
    }

    public function test_remote_publish_preflight_dry_run_returns_without_http_call(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_site_uid', 'client_a');
        update_option('dbvc_bricks_auth_method', 'wp_app_password');
        update_option('dbvc_bricks_api_key_id', 'integration_user');
        update_option('dbvc_bricks_api_secret', 'app password');
        update_option('dbvc_bricks_mothership_url', 'https://mothership.local');

        $http_called = false;
        add_filter('pre_http_request', static function ($preempt) use (&$http_called) {
            $http_called = true;
            return $preempt;
        }, 10, 1);

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/publish-remote');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Idempotency-Key', 'remote-preflight-check');
        $request->set_body(wp_json_encode([
            'package' => ['package_id' => 'pkg-remote-preflight', 'version' => '1.0.0', 'channel' => 'canary', 'artifacts' => []],
            'targeting' => ['mode' => 'all', 'site_uids' => []],
            'dry_run' => true,
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue((bool) ($data['ok'] ?? false));
        $this->assertTrue((bool) ($data['dry_run'] ?? false));
        $this->assertFalse($http_called);
        $this->assertSame('strict', $data['preflight']['compatibility']['parse_mode'] ?? '');
    }

    public function test_diagnostics_surface_package_delivery_ack_summary(): void
    {
        DBVC_Bricks_Connected_Sites::upsert_site([
            'site_uid' => 'client_a',
            'allow_receive_packages' => true,
            'status' => 'online',
        ]);

        $publish = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages');
        $publish->set_header('Content-Type', 'application/json');
        $publish->set_header('Idempotency-Key', 'idemp-diagnostics-package');
        $publish->set_body(wp_json_encode([
            'package' => [
                'package_id' => 'pkg-diag',
                'version' => '1.2.3',
                'channel' => 'stable',
                'artifacts' => [],
            ],
            'targeting' => ['mode' => 'selected', 'site_uids' => ['client_a']],
        ]));
        $publish_response = rest_get_server()->dispatch($publish);
        $this->assertSame(200, $publish_response->get_status());

        $ack = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/pkg-diag/ack');
        $ack->set_header('Content-Type', 'application/json');
        $ack->set_body(wp_json_encode([
            'site_uid' => 'client_a',
            'state' => 'applied',
        ]));
        $ack_response = rest_get_server()->dispatch($ack);
        $this->assertSame(200, $ack_response->get_status());

        $diagnostics = new WP_REST_Request('GET', '/dbvc/v1/bricks/diagnostics');
        $diagnostics_response = rest_get_server()->dispatch($diagnostics);
        $this->assertSame(200, $diagnostics_response->get_status());
        $data = $diagnostics_response->get_data();
        $delivery = $data['package_delivery'] ?? [];
        $this->assertNotEmpty($delivery);
        $this->assertSame('pkg-diag', $delivery[0]['package_id'] ?? '');
        $this->assertSame(1, $delivery[0]['ack_summary']['site_count'] ?? 0);
        $this->assertSame(1, $delivery[0]['ack_summary']['states']['applied'] ?? 0);
    }

    public function test_mothership_packages_panel_renders_connected_sites_controls(): void
    {
        update_option('dbvc_bricks_role', 'mothership');
        $render = $this->render_admin_page('packages');
        $this->assertStringContainsString('id="dbvc-bricks-connected-sites-search"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-connected-sites-sort"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-select-visible-sites"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-publish-remote-preflight"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-test-remote-connection"', $render);
    }

    public function test_client_packages_panel_renders_publish_remote_button(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_read_only', '0');
        $render = $this->render_admin_page('packages');
        $this->assertStringContainsString('id="dbvc-bricks-publish-remote-packages"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-refresh-publish-runs"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-publish-runs-body"', $render);
        $this->assertStringContainsString('Select</th><th>Update Package', $render);
        $this->assertStringContainsString('dbvc-bricks-package-select-row', $render);
    }

    public function test_remote_connection_test_endpoint_uses_basic_auth_probe(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_auth_method', 'wp_app_password');
        update_option('dbvc_bricks_api_key_id', 'FranklyCo');
        update_option('dbvc_bricks_api_secret', 'app password');
        update_option('dbvc_bricks_mothership_url', 'https://mothership.local');

        $captured = [];
        add_filter('pre_http_request', static function ($preempt, $args, $url) use (&$captured) {
            $captured['url'] = $url;
            $captured['args'] = $args;
            return [
                'headers' => [],
                'body' => wp_json_encode(['enabled' => true, 'addon' => 'bricks']),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies' => [],
                'filename' => null,
            ];
        }, 10, 3);

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/test-remote-connection');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue((bool) ($data['ok'] ?? false));
        $this->assertSame('https://mothership.local/wp-json/dbvc/v1/bricks/status', $captured['url']);
        $this->assertStringStartsWith('Basic ', (string) ($captured['args']['headers']['Authorization'] ?? ''));
    }

    public function test_remote_publish_surfaces_mothership_visibility_verification_failure(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_auth_method', 'wp_app_password');
        update_option('dbvc_bricks_api_key_id', 'FranklyCo');
        update_option('dbvc_bricks_api_secret', 'app password');
        update_option('dbvc_bricks_mothership_url', 'https://mothership.local');
        update_option('dbvc_bricks_site_uid', 'client_visibility_probe');

        add_filter('pre_http_request', static function ($preempt, $args, $url) {
            if (strpos((string) $url, '/wp-json/dbvc/v1/bricks/packages/pkg_remote_visibility_missing') !== false) {
                return [
                    'headers' => [],
                    'body' => wp_json_encode([
                        'code' => 'dbvc_bricks_package_not_found',
                        'message' => 'Package not found.',
                    ]),
                    'response' => ['code' => 404, 'message' => 'Not Found'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }
            if (strpos((string) $url, '/wp-json/dbvc/v1/bricks/packages') !== false) {
                return [
                    'headers' => [],
                    'body' => wp_json_encode([
                        'ok' => true,
                        'package_id' => 'pkg_remote_visibility_missing',
                        'receipt_id' => 'pkg_rcpt_remote_visibility',
                    ]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }
            return $preempt;
        }, 10, 3);

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/publish-remote');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Idempotency-Key', 'pkg-remote-visibility-failure');
        $request->set_body(wp_json_encode([
            'package' => [
                'package_id' => 'pkg_local_visibility_probe',
                'version' => '1.0.0',
                'channel' => 'stable',
                'artifacts' => [],
            ],
            'targeting' => ['mode' => 'all', 'site_uids' => []],
        ]));
        $response = rest_get_server()->dispatch($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue((bool) ($data['ok'] ?? false));
        $this->assertFalse((bool) ($data['remote_package_visibility']['ok'] ?? true));
        $this->assertSame(404, (int) ($data['remote_package_visibility']['status'] ?? 0));
        $this->assertSame('pkg_remote_visibility_missing', (string) ($data['remote_package_visibility']['package_id'] ?? ''));

        $runs_request = new WP_REST_Request('GET', '/dbvc/v1/bricks/packages/publish-runs');
        $runs_response = rest_get_server()->dispatch($runs_request);
        $this->assertSame(200, $runs_response->get_status());
        $runs = $runs_response->get_data()['items'] ?? [];
        $this->assertNotEmpty($runs);
        $this->assertSame('success', (string) ($runs[0]['result'] ?? ''));
        $this->assertFalse((bool) ($runs[0]['remote_package_visibility']['ok'] ?? true));
    }

    public function test_bootstrap_create_generates_first_package_from_local_artifacts(): void
    {
        update_option('bricks_global_classes', ['class-a' => ['label' => 'Class A']]);

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/bootstrap-create');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Idempotency-Key', 'bootstrap-first-package');
        $request->set_body(wp_json_encode([
            'channel' => 'stable',
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue((bool) ($data['ok'] ?? false));
        $this->assertNotEmpty($data['package_id'] ?? '');
        $this->assertGreaterThan(0, (int) ($data['artifact_count'] ?? 0));

        $stored = DBVC_Bricks_Packages::get_package((string) $data['package_id']);
        $this->assertIsArray($stored);
        $this->assertSame('stable', $stored['channel'] ?? '');
    }

    public function test_connected_sites_backfill_from_package_sources(): void
    {
        $create = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages');
        $create->set_header('Content-Type', 'application/json');
        $create->set_header('Idempotency-Key', 'pkg-source-backfill');
        $create->set_body(wp_json_encode([
            'package' => [
                'package_id' => 'pkg-source-backfill',
                'version' => '1.0.0',
                'channel' => 'stable',
                'source_site' => [
                    'site_uid' => 'client_source_a',
                    'base_url' => 'https://client-source-a.local',
                ],
                'artifacts' => [],
            ],
            'targeting' => ['mode' => 'all', 'site_uids' => []],
        ]));
        $create_response = rest_get_server()->dispatch($create);
        $this->assertSame(200, $create_response->get_status());

        $list = new WP_REST_Request('GET', '/dbvc/v1/bricks/connected-sites');
        $list_response = rest_get_server()->dispatch($list);
        $this->assertSame(200, $list_response->get_status());
        $items = $list_response->get_data()['items'] ?? [];
        $uids = array_map(static function ($row) {
            return isset($row['site_uid']) ? (string) $row['site_uid'] : '';
        }, is_array($items) ? $items : []);
        $this->assertContains('client_source_a', $uids);
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
