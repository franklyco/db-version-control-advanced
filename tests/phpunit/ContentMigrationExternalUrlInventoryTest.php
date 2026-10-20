<?php

/**
 * The external URL inventory path.
 *
 * Sitemap-only discovery found 0 of 8 live pages on a real client site, because the site serves no
 * sitemap at any standard path and declares none in robots.txt. Measurement also showed that no
 * single discovery strategy reaches 95% recall on its own, so a caller must be able to supply the
 * union of several. These tests fix that contract:
 *
 * - a sitemap is optional when a URL list is supplied, but one of the two is still required;
 * - supplying a URL does NOT exempt it from the same scope rules a sitemap URL faces;
 * - the inventory records how each URL was found, so provenance survives into the artifact.
 */
class ContentMigrationExternalUrlInventoryTest extends WP_UnitTestCase
{
    /**
     * @var array<int, string>
     */
    private $domains_to_cleanup = [];

    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        DBVC_CC_V2_Contracts::ensure_defaults();

        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V2);

        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();
    }

    public function tear_down(): void
    {
        foreach ($this->domains_to_cleanup as $domain) {
            $this->delete_domain_artifacts($domain);
        }

        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);

        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();

        parent::tear_down();
    }

    public function test_inventory_still_fails_closed_without_a_sitemap_or_a_url_list(): void
    {
        $domain = 'external-inventory-empty.example';
        $this->domains_to_cleanup[] = $domain;

        $result = DBVC_CC_V2_URL_Inventory_Service::get_instance()->build_inventory(
            $domain,
            'journey-external-empty',
            ''
        );

        $this->assertWPError($result);
        $this->assertSame('dbvc_cc_v2_inventory_request_invalid', $result->get_error_code());
    }

    public function test_inventory_builds_from_an_external_url_list_without_a_sitemap(): void
    {
        $domain = 'external-inventory.example';
        $this->domains_to_cleanup[] = $domain;

        $result = DBVC_CC_V2_URL_Inventory_Service::get_instance()->build_inventory(
            $domain,
            'journey-external-list',
            '',
            [
                'urls' => [
                    'https://external-inventory.example/',
                    'https://external-inventory.example/services',
                    'https://external-inventory.example/contact',
                ],
            ]
        );

        $this->assertNotWPError($result);

        $inventory = $result['inventory'];
        $this->assertSame('domain-url-inventory.v1', $inventory['artifact_type']);
        $this->assertSame('external', $inventory['source']['type']);
        $this->assertSame('', $inventory['source']['sitemap_url']);
        $this->assertSame(3, $inventory['source']['external_url_count']);
        $this->assertSame(3, $inventory['stats']['url_count']);
        $this->assertSame(3, $inventory['stats']['eligible_count']);

        foreach ($inventory['urls'] as $row) {
            $this->assertSame('external', $row['discovery_reason']);
            $this->assertSame('eligible', $row['scope_status']);
            $this->assertNotSame('', $row['page_id']);
        }

        $paths = wp_list_pluck($inventory['urls'], 'path');
        sort($paths);
        $this->assertSame(['/', '/contact', '/services'], $paths);
    }

    public function test_supplied_urls_are_not_exempt_from_scope_rules(): void
    {
        $domain = 'external-scope.example';
        $this->domains_to_cleanup[] = $domain;

        $result = DBVC_CC_V2_URL_Inventory_Service::get_instance()->build_inventory(
            $domain,
            'journey-external-scope',
            '',
            [
                'urls' => [
                    'https://external-scope.example/about',
                    'https://not-the-client.example/about',
                    'https://external-scope.example/brochure.pdf',
                    'https://external-scope.example/wp-admin/options.php',
                ],
            ]
        );

        $this->assertNotWPError($result);

        // Keyed on the normalized URL, not the path: the in-scope and off-domain entries both
        // normalise to /about, so a path-keyed map would silently collapse them.
        $reasons = [];
        foreach ($result['inventory']['urls'] as $row) {
            $reasons[(string) $row['normalized_url']] = (string) $row['scope_reason'];
        }

        $this->assertSame(
            'same_domain_html_candidate',
            $reasons['https://external-scope.example/about']
        );
        $this->assertSame(
            'external_domain',
            $reasons['https://not-the-client.example/about'],
            'a supplied URL on another host must still be rejected as out of scope'
        );
        $this->assertSame(
            'non_html_asset',
            $reasons['https://external-scope.example/brochure.pdf']
        );
        $this->assertSame(
            'utility_path',
            $reasons['https://external-scope.example/wp-admin/options.php']
        );

        $this->assertSame(1, $result['inventory']['stats']['eligible_count']);
        $this->assertSame(3, $result['inventory']['stats']['out_of_scope_count']);
    }

    public function test_duplicate_supplied_urls_collapse_to_one_row(): void
    {
        $domain = 'external-dedupe.example';
        $this->domains_to_cleanup[] = $domain;

        $result = DBVC_CC_V2_URL_Inventory_Service::get_instance()->build_inventory(
            $domain,
            'journey-external-dedupe',
            '',
            [
                'urls' => [
                    'https://external-dedupe.example/services',
                    'https://external-dedupe.example/services',
                    // The legacy index.html form of the same page canonicalises separately but must
                    // not be dropped: it is a distinct URL the source really serves.
                    'https://external-dedupe.example/services/',
                    '',
                    null,
                    ['not-a-url'],
                ],
            ]
        );

        $this->assertNotWPError($result);
        $this->assertSame(1, $result['inventory']['stats']['url_count']);
        $this->assertSame('/services', $result['inventory']['urls'][0]['path']);
    }

    public function test_create_run_route_accepts_a_urls_parameter(): void
    {
        $routes = rest_get_server()->get_routes();
        $route = '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs';

        $this->assertArrayHasKey($route, $routes);

        $args = [];
        foreach ($routes[$route] as $handler) {
            if (! empty($handler['methods']['POST'])) {
                $args = $handler['args'];
                break;
            }
        }

        $this->assertArrayHasKey('urls', $args, 'POST /runs must advertise the urls parameter');
        $this->assertArrayHasKey('sitemapUrl', $args);
        $this->assertFalse($args['sitemapUrl']['required'], 'sitemapUrl must remain optional');
    }

    private function reset_rest_server(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
    }

    private function delete_domain_artifacts(string $domain): void
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($context)) {
            return;
        }

        $domain_dir = $context['domain_dir'];
        $base_dir = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        if (! is_dir($domain_dir) || ! dbvc_cc_path_is_within($domain_dir, $base_dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($domain_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($domain_dir);
    }
}
