<?php

class ContentCollectorV2Phase5Test extends WP_UnitTestCase
{
    /**
     * @var array<int, string>
     */
    private $domains_to_cleanup = [];

    /**
     * @var int
     */
    private $original_page_on_front = 0;

    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        DBVC_CC_V2_Contracts::ensure_defaults();

        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V2);

        $this->original_page_on_front = (int) get_option('page_on_front');

        register_post_meta('page', 'hero_subheading', [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
        ]);
        register_post_meta('page', 'faq_question', [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
        ]);
        register_post_meta('page', 'faq_answer', [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
        ]);
        register_post_meta('page', 'cta_button_url', [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
        ]);
        register_post_meta('page', 'download_asset', [
            'type' => 'integer',
            'single' => true,
            'show_in_rest' => true,
        ]);

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

        update_option('page_on_front', $this->original_page_on_front);
        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);

        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();

        parent::tear_down();
    }

    public function test_create_run_builds_phase_five_artifacts_and_resolution_preview(): void
    {
        $domain = 'phase5-source.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $existing_about_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_name' => 'about',
            'post_title' => 'Existing About',
        ]);
        $front_page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_name' => 'home',
            'post_title' => 'Front Page',
        ]);
        update_option('page_on_front', $front_page_id);

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
        $this->assertSame(2, $payload['latest']['counts']['urls_mapped']);
        $this->assertSame(2, $payload['latest']['counts']['urls_finalized']);

        $pages = isset($payload['pages']) && is_array($payload['pages']) ? array_values($payload['pages']) : [];
        $home_page = $this->find_page_by_path($pages, '/');
        $about_page = $this->find_page_by_path($pages, '/about');
        $this->assertNotEmpty($home_page);
        $this->assertNotEmpty($about_page);
        $this->assertSame('completed', $home_page['mappingStatus']);
        $this->assertSame('completed', $about_page['finalizationStatus']);
        $this->assertSame(DBVC_CC_V2_Contracts::RESOLUTION_MODE_UPDATE_EXISTING, $about_page['resolutionMode']);

        $home_dir = DBVC_CC_Artifact_Manager::get_page_dir('https://' . $domain . '/');
        $about_dir = DBVC_CC_Artifact_Manager::get_page_dir('https://' . $domain . '/about');

        $this->assertFileExists($home_dir . '/home.mapping-index.v1.json');
        $this->assertFileExists($home_dir . '/home.media-candidates.v2.json');
        $this->assertFileExists($home_dir . '/home.target-transform.v1.json');
        $this->assertFileExists($home_dir . '/home.mapping-recommendations.v2.json');
        $this->assertFileExists($about_dir . '/about.target-transform.v1.json');

        $home_mapping_index = json_decode((string) file_get_contents($home_dir . '/home.mapping-index.v1.json'), true);
        $home_media_candidates = json_decode((string) file_get_contents($home_dir . '/home.media-candidates.v2.json'), true);
        $home_recommendations = json_decode((string) file_get_contents($home_dir . '/home.mapping-recommendations.v2.json'), true);
        $about_transform = json_decode((string) file_get_contents($about_dir . '/about.target-transform.v1.json'), true);

        $this->assertSame('mapping-index.v1', $home_mapping_index['artifact_type']);
        $this->assertNotEmpty($home_mapping_index['catalog_fingerprint']);
        $this->assertContains('core:post_title', $this->collect_target_refs($home_mapping_index));
        $this->assertContains('meta:post:page:hero_subheading', $this->collect_target_refs($home_mapping_index));

        $this->assertSame('media-candidates.v2', $home_media_candidates['artifact_type']);
        $this->assertCount(1, $home_media_candidates['media_items']);
        $this->assertSame('core:featured_image', $home_media_candidates['media_items'][0]['target_candidates'][0]['target_ref']);

        $this->assertSame('target-transform.v1', $about_transform['artifact_type']);
        $this->assertSame(DBVC_CC_V2_Contracts::RESOLUTION_MODE_UPDATE_EXISTING, $about_transform['resolution_preview']['mode']);
        $this->assertSame($existing_about_id, $about_transform['resolution_preview']['target']['object_id']);

        $this->assertSame('mapping-recommendations.v2', $home_recommendations['artifact_type']);
        $this->assertSame('page', $home_recommendations['recommended_target_object']['target_object_key']);
        $this->assertNotEmpty($home_recommendations['recommendations']);
        $this->assertCount(1, $home_recommendations['media_recommendations']);

        $domain_context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        $this->assertIsArray($domain_context);
        $this->assertFileExists($domain_context['pattern_memory_file']);
        $pattern_memory = json_decode((string) file_get_contents($domain_context['pattern_memory_file']), true);
        $this->assertSame('domain-pattern-memory.v1', $pattern_memory['artifact_type']);
        $this->assertContains($payload['runId'], $pattern_memory['source_journey_ids']);
        $this->assertNotEmpty($pattern_memory['pattern_groups']);
    }

    public function test_pattern_memory_remains_domain_scoped(): void
    {
        $domain_one = 'phase5-domain-one.example';
        $domain_two = 'phase5-domain-two.example';
        $this->domains_to_cleanup[] = $domain_one;
        $this->domains_to_cleanup[] = $domain_two;

        $this->register_http_stubs($domain_one);

        $this->reset_rest_server();
        do_action('rest_api_init');

        $request = new WP_REST_Request('POST', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs');
        $request->set_body_params(
            [
                'domain' => $domain_one,
                'sitemapUrl' => 'https://' . $domain_one . '/sitemap.xml',
            ]
        );

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        $matches_one = DBVC_CC_V2_Pattern_Learning_Service::get_instance()->find_matches($domain_one, 'page', ['hero', 'title']);
        $matches_two = DBVC_CC_V2_Pattern_Learning_Service::get_instance()->find_matches($domain_two, 'page', ['hero', 'title']);

        $this->assertNotEmpty($matches_one);
        $this->assertSame([], $matches_two);
    }

    private function register_http_stubs(string $domain): void
    {
        add_filter(
            'pre_http_request',
            static function ($preempt, $args, $url) use ($domain) {
                unset($args);

                $responses = [
                    'https://' . $domain . '/sitemap.xml' => [
                        'body' => '<?xml version="1.0" encoding="UTF-8"?><urlset><url><loc>https://' . $domain . '/</loc></url><url><loc>https://' . $domain . '/about/</loc></url><url><loc>https://' . $domain . '/about/?utm=campaign</loc></url></urlset>',
                    ],
                    'https://' . $domain . '/' => [
                        'body' => '<html><head><title>Home Title</title><meta name="description" content="Home description" /></head><body><main><h1>Home Heading</h1><p>Hero intro copy.</p><img src="/hero.jpg" alt="Homepage hero" /><a href="/contact/">Request Quote</a><h2>FAQ</h2><p>What do you offer?</p><p>We offer migrations.</p></main></body></html>',
                    ],
                    'https://' . $domain . '/about/' => [
                        'body' => '<html><head><title>About Title</title><meta name="description" content="About description" /></head><body><main><h1>About Heading</h1><p>About copy.</p><img src="/team.jpg" alt="Team photo" /></main></body></html>',
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

    /**
     * @param array<string, mixed> $mapping_index
     * @return array<int, string>
     */
    private function collect_target_refs(array $mapping_index): array
    {
        $refs = [];
        $content_items = isset($mapping_index['content_items']) && is_array($mapping_index['content_items']) ? $mapping_index['content_items'] : [];
        foreach ($content_items as $content_item) {
            if (! is_array($content_item)) {
                continue;
            }

            $target_candidates = isset($content_item['target_candidates']) && is_array($content_item['target_candidates'])
                ? $content_item['target_candidates']
                : [];
            foreach ($target_candidates as $candidate) {
                if (! is_array($candidate) || empty($candidate['target_ref'])) {
                    continue;
                }

                $refs[] = (string) $candidate['target_ref'];
            }
        }

        $refs = array_values(array_unique($refs));
        sort($refs);

        return $refs;
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
