<?php

class ContentCollectorV2Phase3Test extends WP_UnitTestCase
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

        remove_all_filters('pre_http_request');
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();
    }

    public function tear_down(): void
    {
        remove_all_filters('pre_http_request');

        foreach ($this->domains_to_cleanup as $domain) {
            $this->delete_domain_artifacts($domain);
        }

        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);

        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();

        parent::tear_down();
    }

    public function test_create_run_builds_url_inventory_and_capture_artifacts_from_sitemap(): void
    {
        $domain = 'phase3-source.example';
        $this->domains_to_cleanup[] = $domain;

        $this->register_http_stubs($domain);

        $this->reset_rest_server();
        do_action('rest_api_init');

        $request = new WP_REST_Request('POST', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs');
        $request->set_body_params(
            [
                'domain' => $domain,
                'sitemapUrl' => 'https://' . $domain . '/sitemap.xml',
            ]
        );

        $response = rest_get_server()->dispatch($request);
        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());

        $payload = $response->get_data();
        $this->assertMatchesRegularExpression('/^ccv2_phase3-source-example_\d{8}T\d{6}Z_[a-z0-9]{6}$/', $payload['runId']);
        $this->assertSame($domain, $payload['domain']);
        $this->assertSame(2, $payload['stats']['processed_count']);
        $this->assertSame(2, $payload['stats']['skipped_count']);
        $this->assertSame(0, $payload['stats']['failed_count']);

        $url_inventory = $payload['urlInventory'];
        $this->assertSame('domain-url-inventory.v1', $url_inventory['artifact_type']);
        $this->assertSame(5, $url_inventory['stats']['raw_url_count']);
        $this->assertSame(4, $url_inventory['stats']['url_count']);
        $this->assertSame(2, $url_inventory['stats']['eligible_count']);
        $this->assertSame(2, $url_inventory['stats']['out_of_scope_count']);
        $this->assertSame(1, $url_inventory['stats']['duplicate_count']);

        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        $this->assertNotWPError($context);
        $this->assertFileExists($context['url_inventory_file']);

        $home_dir = DBVC_CC_Artifact_Manager::get_page_dir('https://' . $domain . '/');
        $about_dir = DBVC_CC_Artifact_Manager::get_page_dir('https://' . $domain . '/about');
        $this->assertFileExists($home_dir . '/home.json');
        $this->assertFileExists($home_dir . '/home.source-normalization.v1.json');
        $this->assertFileExists($home_dir . '/home.elements.v2.json');
        $this->assertFileExists($home_dir . '/home.sections.v2.json');
        $this->assertFileExists($home_dir . '/home.ingestion-package.v2.json');
        $this->assertFileExists($about_dir . '/about.json');
        $this->assertFileExists($about_dir . '/about.source-normalization.v1.json');
        $this->assertFileDoesNotExist(DBVC_CC_Artifact_Manager::get_page_dir('https://' . $domain . '/wp-json') . '/wp-json.json');

        $raw = json_decode((string) file_get_contents($home_dir . '/home.json'), true);
        $normalized = json_decode((string) file_get_contents($home_dir . '/home.source-normalization.v1.json'), true);
        $elements = json_decode((string) file_get_contents($home_dir . '/home.elements.v2.json'), true);
        $sections = json_decode((string) file_get_contents($home_dir . '/home.sections.v2.json'), true);
        $ingestion = json_decode((string) file_get_contents($home_dir . '/home.ingestion-package.v2.json'), true);

        $this->assertSame('page-artifact.v1', $raw['artifact_type']);
        $this->assertSame($payload['runId'], $raw['journey_id']);
        $this->assertSame('Home Title', $raw['metadata']['title']);
        $this->assertNotEmpty($raw['sections_raw']);

        $this->assertSame('source-normalization.v1', $normalized['artifact_type']);
        $this->assertSame($payload['runId'], $normalized['journey_id']);

        $this->assertSame('elements.v2', $elements['artifact_type']);
        $this->assertSame($payload['runId'], $elements['journey_id']);
        $this->assertSame($raw['page_id'], $elements['page_id']);

        $this->assertSame('sections.v2', $sections['artifact_type']);
        $this->assertSame('ingestion-package.v2', $ingestion['artifact_type']);
        $this->assertSame($raw['page_id'], $ingestion['page_id']);

        $latest = $payload['latest'];
        $this->assertSame(4, $latest['counts']['urls_discovered']);
        $this->assertSame(2, $latest['counts']['urls_captured']);
        $this->assertSame(2, $latest['counts']['urls_extracted']);

        $overview_request = new WP_REST_Request('GET', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $payload['runId'] . '/overview');
        $overview_response = rest_get_server()->dispatch($overview_request);
        $this->assertSame(200, $overview_response->get_status());
        $overview = $overview_response->get_data();
        $this->assertSame($payload['runId'], $overview['runId']);
        $this->assertSame(4, $overview['inventory']['stats']['url_count']);
    }

    public function test_overview_route_is_only_registered_for_v2_runtime(): void
    {
        $this->reset_rest_server();
        do_action('rest_api_init');
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey('/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/(?P<run_id>[\\w-]+)/overview', $routes);

        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();

        $this->reset_rest_server();
        do_action('rest_api_init');
        $routes = rest_get_server()->get_routes();

        $this->assertArrayNotHasKey('/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/(?P<run_id>[\\w-]+)/overview', $routes);
    }

    private function register_http_stubs(string $domain): void
    {
        add_filter(
            'pre_http_request',
            static function ($preempt, $args, $url) use ($domain) {
                unset($args);

                $responses = [
                    'https://' . $domain . '/sitemap.xml' => [
                        'body' => '<?xml version="1.0" encoding="UTF-8"?><urlset><url><loc>https://' . $domain . '/</loc></url><url><loc>https://' . $domain . '/about/</loc></url><url><loc>https://' . $domain . '/about/?utm=campaign</loc></url><url><loc>https://external.example/offsite/</loc></url><url><loc>https://' . $domain . '/wp-json/</loc></url></urlset>',
                    ],
                    'https://' . $domain . '/' => [
                        'body' => '<html><head><title>Home Title</title><meta name="description" content="Home description" /></head><body><main><h1>Home Heading</h1><p>Intro paragraph.</p><a href="/about/">About Us</a><img src="/hero.jpg" alt="Hero" /><h2>Section Two</h2><p>More content.</p></main></body></html>',
                    ],
                    'https://' . $domain . '/about/' => [
                        'body' => '<html><head><title>About Title</title><meta name="description" content="About description" /></head><body><main><h1>About Heading</h1><p>About copy.</p><button>Contact us</button></main></body></html>',
                    ],
                ];

                if (! isset($responses[$url])) {
                    return $preempt;
                }

                return [
                    'headers' => [],
                    'body' => $responses[$url]['body'],
                    'response' => [
                        'code' => 200,
                        'message' => 'OK',
                    ],
                    'cookies' => [],
                    'filename' => null,
                ];
            },
            10,
            3
        );
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
