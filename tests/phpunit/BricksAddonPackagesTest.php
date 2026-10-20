<?php

class BricksAddonPackagesTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGE_CATALOG, []);
        update_option(DBVC_Bricks_Packages::OPTION_PUBLISH_RUNS, []);
        DBVC_Bricks_Addon::refresh_runtime_registration();
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, [
            'pkg_100' => [
                'package_id' => 'pkg_100',
                'version' => '1.0.0',
                'channel' => 'stable',
                'artifacts' => [],
                'payload_index' => [
                    ['path' => 'artifacts/a.json', 'hash' => 'sha256:' . str_repeat('a', 64)],
                ],
            ],
            'pkg_200' => [
                'package_id' => 'pkg_200',
                'version' => '2.0.0-beta',
                'channel' => 'beta',
                'artifacts' => [],
                'payload_index' => [],
            ],
        ]);
        $this->reset_rest_server();
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        delete_option(DBVC_Bricks_Packages::OPTION_PACKAGES);
        delete_option(DBVC_Bricks_Packages::OPTION_PACKAGE_CATALOG);
        delete_option(DBVC_Bricks_Packages::OPTION_PUBLISH_RUNS);
        delete_option(DBVC_Bricks_Packages::OPTION_PACKAGE_PREFIX . 'pkg_split_store_test');
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        parent::tear_down();
    }

    public function test_list_packages_endpoint_returns_filtered_items(): void
    {
        $server = rest_get_server();
        $request = new WP_REST_Request('GET', '/dbvc/v1/bricks/packages');
        $request->set_param('channel', 'beta');
        $response = $server->dispatch($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertCount(1, $data['items']);
        $this->assertSame('pkg_200', $data['items'][0]['package_id']);
    }

    public function test_list_packages_endpoint_sorts_newest_before_default_limit_slice(): void
    {
        $packages = [];
        for ($i = 1; $i <= 55; $i++) {
            $package_id = sprintf('pkg_%03d', $i);
            $timestamp = gmdate('c', strtotime(sprintf('2026-01-%02d 00:00:00 UTC', min($i, 28))));
            $packages[$package_id] = [
                'package_id' => $package_id,
                'version' => sprintf('1.0.%d', $i),
                'channel' => 'stable',
                'status' => DBVC_Bricks_Packages::STATUS_PUBLISHED,
                'targeting' => ['mode' => 'all', 'site_uids' => []],
                'artifacts' => [],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }
        $packages['pkg_latest'] = [
            'package_id' => 'pkg_latest',
            'version' => '9.9.9',
            'channel' => 'stable',
            'status' => DBVC_Bricks_Packages::STATUS_PUBLISHED,
            'targeting' => ['mode' => 'all', 'site_uids' => []],
            'artifacts' => [],
            'created_at' => '2026-05-05T04:54:41+00:00',
            'updated_at' => '2026-05-05T04:54:41+00:00',
        ];
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, $packages);

        $server = rest_get_server();
        $request = new WP_REST_Request('GET', '/dbvc/v1/bricks/packages');
        $response = $server->dispatch($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertCount(50, $data['items']);
        $this->assertSame('pkg_latest', $data['items'][0]['package_id']);

        $visible_ids = array_map(static function ($item) {
            return is_array($item) ? (string) ($item['package_id'] ?? '') : '';
        }, $data['items']);
        $this->assertContains('pkg_latest', $visible_ids);
        $this->assertNotContains('pkg_001', $visible_ids);
    }

    public function test_create_package_persists_full_manifest_in_split_store_and_keeps_catalog_lightweight(): void
    {
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, []);
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGE_CATALOG, []);

        $server = rest_get_server();
        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages');
        $request->set_header('Idempotency-Key', 'pkg-create-split-store-test');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'package_id' => 'pkg_split_store_test',
            'version' => '3.0.0',
            'channel' => 'stable',
            'source_site' => [
                'site_uid' => 'client_split_store',
                'base_url' => 'https://client-split-store.local',
            ],
            'artifacts' => [
                [
                    'artifact_uid' => 'option:bricks_global_settings',
                    'artifact_type' => 'option',
                    'hash' => 'sha256:' . str_repeat('b', 64),
                    'payload' => [
                        'probe' => str_repeat('payload-', 32),
                    ],
                ],
            ],
        ]));
        $response = $server->dispatch($request);

        $this->assertSame(200, $response->get_status());
        $catalog = get_option(DBVC_Bricks_Packages::OPTION_PACKAGES, []);
        $this->assertIsArray($catalog);
        $this->assertArrayHasKey('pkg_split_store_test', $catalog);
        $this->assertArrayHasKey('artifacts', $catalog['pkg_split_store_test']);
        $this->assertArrayNotHasKey('payload', $catalog['pkg_split_store_test']['artifacts'][0] ?? []);

        $full_option_name = DBVC_Bricks_Packages::OPTION_PACKAGE_PREFIX . 'pkg_split_store_test';
        $full_manifest = get_option($full_option_name, []);
        $this->assertIsArray($full_manifest);
        $this->assertSame('payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-', (string) ($full_manifest['artifacts'][0]['payload']['probe'] ?? ''));

        $get_request = new WP_REST_Request('GET', '/dbvc/v1/bricks/packages/pkg_split_store_test');
        $get_response = $server->dispatch($get_request);
        $this->assertSame(200, $get_response->get_status());
        $manifest = $get_response->get_data()['manifest'] ?? [];
        $this->assertSame('payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-payload-', (string) ($manifest['artifacts'][0]['payload']['probe'] ?? ''));
    }

    public function test_persist_package_uses_secondary_catalog_when_primary_store_is_legacy_full_archive(): void
    {
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, [
            'pkg_legacy_full' => [
                'package_id' => 'pkg_legacy_full',
                'version' => '0.9.0',
                'channel' => 'stable',
                'artifacts' => [
                    [
                        'artifact_uid' => 'option:bricks_global_settings',
                        'artifact_type' => 'option',
                        'hash' => 'sha256:' . str_repeat('c', 64),
                        'payload' => ['legacy' => 'full-manifest-kept'],
                    ],
                ],
            ],
        ]);
        delete_option(DBVC_Bricks_Packages::OPTION_PACKAGE_CATALOG);

        $server = rest_get_server();
        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages');
        $request->set_header('Idempotency-Key', 'pkg-create-secondary-catalog-test');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'package_id' => 'pkg_new_remote',
            'version' => '3.1.0',
            'channel' => 'stable',
            'artifacts' => [],
        ]));
        $response = $server->dispatch($request);

        $this->assertSame(200, $response->get_status());
        $legacy_primary = get_option(DBVC_Bricks_Packages::OPTION_PACKAGES, []);
        $this->assertSame('full-manifest-kept', (string) ($legacy_primary['pkg_legacy_full']['artifacts'][0]['payload']['legacy'] ?? ''));

        $secondary_catalog = get_option(DBVC_Bricks_Packages::OPTION_PACKAGE_CATALOG, []);
        $this->assertIsArray($secondary_catalog);
        $this->assertArrayHasKey('pkg_legacy_full', $secondary_catalog);
        $this->assertArrayHasKey('pkg_new_remote', $secondary_catalog);
        $this->assertArrayNotHasKey('payload', $secondary_catalog['pkg_legacy_full']['artifacts'][0] ?? []);

        $list_request = new WP_REST_Request('GET', '/dbvc/v1/bricks/packages');
        $list_response = $server->dispatch($list_request);
        $this->assertSame(200, $list_response->get_status());
        $ids = array_map(static function ($item) {
            return is_array($item) ? (string) ($item['package_id'] ?? '') : '';
        }, $list_response->get_data()['items'] ?? []);
        $this->assertContains('pkg_legacy_full', $ids);
        $this->assertContains('pkg_new_remote', $ids);

        $legacy_get = new WP_REST_Request('GET', '/dbvc/v1/bricks/packages/pkg_legacy_full');
        $legacy_get_response = $server->dispatch($legacy_get);
        $this->assertSame(200, $legacy_get_response->get_status());
        $this->assertSame('full-manifest-kept', (string) ($legacy_get_response->get_data()['manifest']['artifacts'][0]['payload']['legacy'] ?? ''));
    }

    public function test_get_package_endpoint_returns_manifest_and_payload_index(): void
    {
        $server = rest_get_server();
        $request = new WP_REST_Request('GET', '/dbvc/v1/bricks/packages/pkg_100');
        $request->set_param('include_payload_index', true);
        $response = $server->dispatch($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('pkg_100', $data['manifest']['package_id']);
        $this->assertNotEmpty($data['payload_index']);
    }

    public function test_get_package_returns_404_for_unknown_package(): void
    {
        $server = rest_get_server();
        $request = new WP_REST_Request('GET', '/dbvc/v1/bricks/packages/pkg_missing');
        $response = $server->dispatch($request);
        $this->assertSame(404, $response->get_status());
    }

    public function test_packages_routes_are_unavailable_when_addon_disabled(): void
    {
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        do_action('rest_api_init');

        $server = rest_get_server();
        $request = new WP_REST_Request('GET', '/dbvc/v1/bricks/packages');
        $response = $server->dispatch($request);
        $this->assertSame(404, $response->get_status());
    }

    private function reset_rest_server(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
    }
}
