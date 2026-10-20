<?php

class ContentCollectorV2Phase20Test extends WP_UnitTestCase
{
    /**
     * @var array<int, string>
     */
    private $domains_to_cleanup = [];

    /**
     * @var array<string, array<string, string>>
     */
    private $http_responses = [];

    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        DBVC_CC_V2_Contracts::ensure_defaults();

        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V2);

        remove_all_filters('pre_http_request');
        add_filter('pre_http_request', [$this, 'mock_http_request'], 10, 3);

        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();
    }

    public function tear_down(): void
    {
        remove_all_filters('pre_http_request');
        remove_all_filters('dbvc_cc_v2_enable_recovery_qa_fixture');

        foreach ($this->domains_to_cleanup as $domain) {
            $this->delete_domain_artifacts($domain);
        }

        $this->http_responses = [];

        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);

        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();

        parent::tear_down();
    }

    public function test_phase_twenty_historical_run_overview_uses_run_scoped_inventory_after_later_same_domain_run(): void
    {
        $domain = 'phase20-history.example';
        $this->domains_to_cleanup[] = $domain;

        $this->set_http_stub_variant(
            $domain,
            [
                '/' => 'Phase 20 Source Home',
                '/about/' => 'Phase 20 Source About',
            ]
        );
        $source_run = $this->create_run(
            $domain,
            [
                'maxUrls' => 2,
            ]
        );

        $this->set_http_stub_variant(
            $domain,
            [
                '/contact/' => 'Phase 20 Replay Contact',
            ]
        );
        $newer_run = $this->create_run(
            $domain,
            [
                'maxUrls' => 1,
            ]
        );

        $domain_latest_inventory = DBVC_CC_V2_URL_Inventory_Service::get_instance()->get_inventory($domain);
        $this->assertIsArray($domain_latest_inventory);
        $this->assertSame((string) $newer_run['runId'], (string) $domain_latest_inventory['journey_id']);
        $this->assertSame(1, (int) $domain_latest_inventory['stats']['url_count']);
        $this->assertSame(['/contact'], $this->extract_inventory_paths($domain_latest_inventory));

        $source_run_inventory = DBVC_CC_V2_URL_Inventory_Service::get_instance()->get_inventory_for_run((string) $source_run['runId']);
        $this->assertIsArray($source_run_inventory);
        $this->assertSame((string) $source_run['runId'], (string) $source_run_inventory['journey_id']);
        $this->assertSame(2, (int) $source_run_inventory['stats']['url_count']);
        $this->assertSame(['/', '/about'], $this->extract_inventory_paths($source_run_inventory));

        $source_overview = $this->get_run_overview((string) $source_run['runId']);
        $this->assertSame((string) $source_run['runId'], (string) $source_overview['runId']);
        $this->assertSame((string) $source_run['runId'], (string) $source_overview['inventory']['journey_id']);
        $this->assertSame(2, (int) $source_overview['inventory']['stats']['url_count']);
        $this->assertSame(['/', '/about'], $this->extract_inventory_paths($source_overview['inventory']));

        $newer_run_inventory = DBVC_CC_V2_URL_Inventory_Service::get_instance()->get_inventory_for_run((string) $newer_run['runId']);
        $this->assertIsArray($newer_run_inventory);
        $this->assertSame((string) $newer_run['runId'], (string) $newer_run_inventory['journey_id']);
        $this->assertSame(1, (int) $newer_run_inventory['stats']['url_count']);
        $this->assertSame(['/contact'], $this->extract_inventory_paths($newer_run_inventory));

        $newer_overview = $this->get_run_overview((string) $newer_run['runId']);
        $this->assertSame((string) $newer_run['runId'], (string) $newer_overview['runId']);
        $this->assertSame((string) $newer_run['runId'], (string) $newer_overview['inventory']['journey_id']);
        $this->assertSame(1, (int) $newer_overview['inventory']['stats']['url_count']);
        $this->assertSame(['/contact'], $this->extract_inventory_paths($newer_overview['inventory']));
    }

    public function test_phase_twenty_historical_run_readiness_and_package_surface_stay_run_scoped_after_later_same_domain_run(): void
    {
        $domain = 'phase20-package.example';
        $this->domains_to_cleanup[] = $domain;

        $this->set_http_stub_variant(
            $domain,
            [
                '/' => 'Phase 20 Package Source Home',
                '/about/' => 'Phase 20 Package Source About',
            ]
        );
        $source_run = $this->create_run(
            $domain,
            [
                'maxUrls' => 2,
            ]
        );
        $source_package = $this->build_package((string) $source_run['runId']);
        $this->assertNotSame('', (string) $source_package['selectedPackageId']);

        $this->set_http_stub_variant(
            $domain,
            [
                '/contact/' => 'Phase 20 Package Replay Contact',
            ]
        );
        $newer_run = $this->create_run(
            $domain,
            [
                'maxUrls' => 1,
            ]
        );
        $newer_package = $this->build_package((string) $newer_run['runId']);
        $this->assertNotSame('', (string) $newer_package['selectedPackageId']);

        $source_readiness = $this->get_run_readiness((string) $source_run['runId']);
        $this->assertSame((string) $source_run['runId'], (string) $source_readiness['runId']);
        $this->assertSame(2, (int) $source_readiness['summary']['eligiblePages']);
        $this->assertSame(['/', '/about'], $this->extract_page_report_paths($source_readiness));

        $newer_readiness = $this->get_run_readiness((string) $newer_run['runId']);
        $this->assertSame((string) $newer_run['runId'], (string) $newer_readiness['runId']);
        $this->assertSame(1, (int) $newer_readiness['summary']['eligiblePages']);
        $this->assertSame(['/contact'], $this->extract_page_report_paths($newer_readiness));

        $source_package_surface = $this->get_package_surface((string) $source_run['runId']);
        $this->assertSame((string) $source_package['selectedPackageId'], (string) $source_package_surface['selectedPackageId']);
        $this->assertCount(1, $source_package_surface['history']);
        $this->assertSame((string) $source_run['runId'], (string) $source_package_surface['history'][0]['journey_id']);
        $this->assertSame((string) $source_run['runId'], (string) $source_package_surface['selectedPackage']['manifest']['journey_id']);

        $newer_package_surface = $this->get_package_surface((string) $newer_run['runId']);
        $this->assertSame((string) $newer_package['selectedPackageId'], (string) $newer_package_surface['selectedPackageId']);
        $this->assertCount(1, $newer_package_surface['history']);
        $this->assertSame((string) $newer_run['runId'], (string) $newer_package_surface['history'][0]['journey_id']);
        $this->assertSame((string) $newer_run['runId'], (string) $newer_package_surface['selectedPackage']['manifest']['journey_id']);
    }

    /**
     * @param mixed  $preempt
     * @param mixed  $args
     * @param string $url
     * @return mixed
     */
    public function mock_http_request($preempt, $args, $url)
    {
        unset($args);

        $response = isset($this->http_responses[$url]) ? $this->http_responses[$url] : null;
        if (! is_string($response)) {
            return $preempt;
        }

        return [
            'headers' => [],
            'body' => $response,
            'response' => [
                'code' => 200,
                'message' => 'OK',
            ],
            'cookies' => [],
            'filename' => null,
        ];
    }

    /**
     * @param array<string, string> $paths_to_titles
     */
    private function set_http_stub_variant(string $domain, array $paths_to_titles): void
    {
        $responses = [
            'https://' . $domain . '/sitemap.xml' => $this->build_sitemap_xml($domain, array_keys($paths_to_titles)),
        ];

        foreach ($paths_to_titles as $path => $title) {
            $normalized_path = '/' . ltrim((string) $path, '/');
            if ($normalized_path !== '/' && substr($normalized_path, -1) !== '/') {
                $normalized_path .= '/';
            }

            $responses['https://' . $domain . $normalized_path] = sprintf(
                '<html><head><title>%1$s</title><meta name="description" content="%1$s description" /></head><body><main><h1>%1$s</h1><p>%1$s body.</p></main></body></html>',
                esc_html($title)
            );
        }

        $this->http_responses = $responses;
    }

    /**
     * @param array<int, string> $paths
     */
    private function build_sitemap_xml(string $domain, array $paths): string
    {
        $urls = '';
        foreach ($paths as $path) {
            $normalized_path = '/' . ltrim((string) $path, '/');
            if ($normalized_path !== '/' && substr($normalized_path, -1) !== '/') {
                $normalized_path .= '/';
            }

            $urls .= '<url><loc>' . esc_url('https://' . $domain . $normalized_path) . '</loc></url>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><urlset>' . $urls . '</urlset>';
    }

    /**
     * @param array<string, mixed> $extra_params
     * @return array<string, mixed>
     */
    private function create_run(string $domain, array $extra_params = []): array
    {
        $this->reset_rest_server();
        do_action('rest_api_init');

        $request = new WP_REST_Request('POST', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs');
        $request->set_body_params(
            array_merge(
                [
                    'domain' => $domain,
                    'sitemapUrl' => 'https://' . $domain . '/sitemap.xml',
                ],
                $extra_params
            )
        );

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        return $response->get_data();
    }

    /**
     * @return array<string, mixed>
     */
    private function get_run_overview(string $run_id): array
    {
        $request = new WP_REST_Request('GET', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/overview');
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        return $response->get_data();
    }

    /**
     * @return array<string, mixed>
     */
    private function get_run_readiness(string $run_id): array
    {
        $request = new WP_REST_Request('GET', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/readiness');
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        return $response->get_data();
    }

    /**
     * @return array<string, mixed>
     */
    private function build_package(string $run_id): array
    {
        $request = new WP_REST_Request('POST', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/package');
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        return $response->get_data();
    }

    /**
     * @return array<string, mixed>
     */
    private function get_package_surface(string $run_id): array
    {
        $request = new WP_REST_Request('GET', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/package');
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        return $response->get_data();
    }

    /**
     * @param array<string, mixed> $inventory
     * @return array<int, string>
     */
    private function extract_inventory_paths(array $inventory): array
    {
        $rows = isset($inventory['urls']) && is_array($inventory['urls']) ? $inventory['urls'] : [];
        $paths = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $path = (string) ($row['path'] ?? '');
            if ($path !== '/' && substr($path, -1) === '/') {
                $path = rtrim($path, '/');
            }

            $paths[] = $path;
        }

        sort($paths);
        return $paths;
    }

    /**
     * @param array<string, mixed> $readiness
     * @return array<int, string>
     */
    private function extract_page_report_paths(array $readiness): array
    {
        $reports = isset($readiness['pageReports']) && is_array($readiness['pageReports']) ? $readiness['pageReports'] : [];
        $paths = [];

        foreach ($reports as $report) {
            if (! is_array($report)) {
                continue;
            }

            $path = (string) ($report['path'] ?? '');
            if ($path !== '/' && substr($path, -1) === '/') {
                $path = rtrim($path, '/');
            }

            $paths[] = $path;
        }

        sort($paths);
        return $paths;
    }

    private function delete_domain_artifacts(string $domain): void
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($context) || empty($context['domain_dir'])) {
            return;
        }

        $this->delete_directory((string) $context['domain_dir']);
    }

    private function delete_directory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->delete_directory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }

    private function reset_rest_server(): void
    {
        global $wp_rest_server;

        $wp_rest_server = new WP_REST_Server();
        do_action('rest_api_init', $wp_rest_server);
    }
}
