<?php

class ContentCollectorV2Phase4Test extends WP_UnitTestCase
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

    public function test_create_run_builds_context_and_initial_classification_artifacts(): void
    {
        $domain = 'phase4-source.example';
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
        $this->assertSame(200, $response->get_status());

        $payload = $response->get_data();
        $this->assertSame(2, $payload['latest']['counts']['urls_context_created']);
        $this->assertSame(2, $payload['latest']['counts']['urls_classified']);

        $pages = isset($payload['pages']) && is_array($payload['pages']) ? array_values($payload['pages']) : [];
        $this->assertCount(2, $pages);

        $home_page = $this->find_page_by_path($pages, '/');
        $about_page = $this->find_page_by_path($pages, '/about');
        $this->assertNotEmpty($home_page);
        $this->assertNotEmpty($about_page);
        $this->assertSame('page', $home_page['classification']['objectKey']);
        $this->assertSame('page', $about_page['classification']['objectKey']);
        $this->assertSame('needs_review', $home_page['classification']['reviewStatus']);
        $this->assertSame('auto_accept_candidate', $about_page['classification']['reviewStatus']);
        $this->assertContains($home_page['pageId'], $payload['latest']['urls_needing_review']);

        $home_dir = DBVC_CC_Artifact_Manager::get_page_dir('https://' . $domain . '/');
        $about_dir = DBVC_CC_Artifact_Manager::get_page_dir('https://' . $domain . '/about');
        $this->assertFileExists($home_dir . '/home.context-creation.v1.json');
        $this->assertFileExists($home_dir . '/home.initial-classification.v1.json');
        $this->assertFileExists($home_dir . '/home.routing-artifact.v1.json');
        $this->assertFileExists($about_dir . '/about.context-creation.v1.json');
        $this->assertFileExists($about_dir . '/about.initial-classification.v1.json');
        $this->assertFileExists($about_dir . '/about.routing-artifact.v1.json');

        $context = json_decode((string) file_get_contents($home_dir . '/home.context-creation.v1.json'), true);
        $classification = json_decode((string) file_get_contents($home_dir . '/home.initial-classification.v1.json'), true);
        $routing = json_decode((string) file_get_contents($home_dir . '/home.routing-artifact.v1.json'), true);
        $about_classification = json_decode((string) file_get_contents($about_dir . '/about.initial-classification.v1.json'), true);

        $this->assertSame('context-creation.v1', $context['artifact_type']);
        $this->assertSame($payload['runId'], $context['journey_id']);
        $this->assertSame('hero', $context['summary']['dominant_context_tags'][0]);
        $this->assertNotEmpty($context['trace']['prompt_input']['section_outline']);

        $this->assertSame('initial-classification.v1', $classification['artifact_type']);
        $this->assertSame($payload['runId'], $classification['journey_id']);
        $this->assertSame('page', $classification['primary_classification']['object_key']);
        $this->assertSame('needs_review', $classification['review']['status']);
        $this->assertSame('routing-artifact.v1', $routing['artifact_type']);
        $this->assertSame('page', $routing['primary_route']['object_key']);
        $this->assertSame('homepage', $routing['primary_route']['page_intent']);
        $this->assertSame('page', $about_classification['primary_classification']['object_key']);
        $this->assertSame('auto_accept_candidate', $about_classification['review']['status']);
    }

    public function test_rerun_route_rebuilds_single_page_initial_classification(): void
    {
        $domain = 'phase4-rerun.example';
        $this->domains_to_cleanup[] = $domain;

        $this->register_http_stubs($domain);

        $this->reset_rest_server();
        do_action('rest_api_init');

        $create_request = new WP_REST_Request('POST', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs');
        $create_request->set_body_params(
            [
                'domain' => $domain,
                'sitemapUrl' => 'https://' . $domain . '/sitemap.xml',
            ]
        );

        $create_response = rest_get_server()->dispatch($create_request);
        $this->assertSame(200, $create_response->get_status());

        $created = $create_response->get_data();
        $home_page = $this->find_page_by_path(isset($created['pages']) ? $created['pages'] : [], '/');
        $this->assertNotEmpty($home_page);

        $home_dir = DBVC_CC_Artifact_Manager::get_page_dir('https://' . $domain . '/');
        @unlink($home_dir . '/home.initial-classification.v1.json');
        @unlink($home_dir . '/home.routing-artifact.v1.json');
        $this->assertFileDoesNotExist($home_dir . '/home.initial-classification.v1.json');
        $this->assertFileDoesNotExist($home_dir . '/home.routing-artifact.v1.json');

        $rerun_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/urls/' . $home_page['pageId'] . '/rerun'
        );
        $rerun_request->set_body_params(['stage' => DBVC_CC_V2_Contracts::AI_STAGE_INITIAL_CLASSIFICATION]);

        $rerun_response = rest_get_server()->dispatch($rerun_request);
        $this->assertSame(200, $rerun_response->get_status());

        $rerun = $rerun_response->get_data();
        $this->assertSame($created['runId'], $rerun['runId']);
        $this->assertSame($home_page['pageId'], $rerun['pageId']);
        $this->assertSame([DBVC_CC_V2_Contracts::AI_STAGE_INITIAL_CLASSIFICATION], $rerun['stagesRun']);
        $this->assertFileExists($home_dir . '/home.initial-classification.v1.json');
        $this->assertFileExists($home_dir . '/home.routing-artifact.v1.json');
        $this->assertContains($home_page['pageId'], $rerun['latest']['urls_rerun']);
    }

    public function test_rerun_route_is_only_registered_for_v2_runtime(): void
    {
        $this->reset_rest_server();
        do_action('rest_api_init');
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey('/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/(?P<run_id>[\\w-]+)/urls/(?P<page_id>[\\w-]+)/rerun', $routes);

        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();

        $this->reset_rest_server();
        do_action('rest_api_init');
        $routes = rest_get_server()->get_routes();

        $this->assertArrayNotHasKey('/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/(?P<run_id>[\\w-]+)/urls/(?P<page_id>[\\w-]+)/rerun', $routes);
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

    /**
     * @param mixed $pages
     * @param string $path
     * @return array<string, mixed>
     */
    private function find_page_by_path($pages, string $path): array
    {
        if (! is_array($pages)) {
            return [];
        }

        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            if (isset($page['path']) && (string) $page['path'] === $path) {
                return $page;
            }
        }

        return [];
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
