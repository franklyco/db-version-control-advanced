<?php

class ContentCollectorV2Phase16Test extends WP_UnitTestCase
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

    public function test_phase_sixteen_run_list_exposes_profile_and_rerun_candidates(): void
    {
        $domain = 'phase16-actions.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $created = $this->create_run(
            $domain,
            [
                'maxUrls' => 2,
                'forceRebuild' => true,
                'crawlOverrides' => [
                    'request_delay' => 750,
                    'capture_mode' => DBVC_CC_Contracts::CAPTURE_MODE_STANDARD,
                ],
            ]
        );

        $home_page = $this->find_page_by_path(isset($created['pages']) ? $created['pages'] : [], '/');
        $this->assertNotEmpty($home_page);

        $append_result = DBVC_CC_V2_Domain_Journey_Service::get_instance()->append_event(
            $domain,
            [
                'journey_id' => $created['runId'],
                'step_key' => DBVC_CC_V2_Contracts::STEP_MAPPING_INDEX_COMPLETED,
                'step_name' => 'Mapping index completed',
                'status' => 'failed',
                'page_id' => $home_page['pageId'],
                'path' => $home_page['path'],
                'source_url' => $home_page['sourceUrl'],
                'actor' => 'admin',
                'trigger' => 'test',
                'message' => 'Phase 16 rerun helper coverage.',
            ]
        );
        $this->assertNotWPError($append_result);

        $list_request = new WP_REST_Request('GET', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs');
        $list_response = rest_get_server()->dispatch($list_request);

        $this->assertSame(200, $list_response->get_status());
        $list_payload = $list_response->get_data();
        $run_item = $this->find_run_by_id(isset($list_payload['items']) ? $list_payload['items'] : [], $created['runId']);

        $this->assertNotEmpty($run_item);
        $this->assertSame('https://' . $domain . '/sitemap.xml', $run_item['runProfile']['sitemapUrl']);
        $this->assertSame(2, (int) $run_item['runProfile']['maxUrls']);
        $this->assertTrue((bool) $run_item['runProfile']['forceRebuild']);
        $this->assertSame(750, (int) $run_item['runProfile']['crawlOverrides']['request_delay']);
        $this->assertSame(DBVC_CC_Contracts::CAPTURE_MODE_STANDARD, $run_item['runProfile']['crawlOverrides']['capture_mode']);

        $candidates = isset($run_item['actionSummary']['rerunCandidates']) && is_array($run_item['actionSummary']['rerunCandidates'])
            ? $run_item['actionSummary']['rerunCandidates']
            : [];

        $this->assertCount(1, $candidates);
        $this->assertSame(DBVC_CC_V2_Contracts::AI_STAGE_MAPPING_INDEX, $candidates[0]['stage']);
        $this->assertSame(1, (int) $candidates[0]['count']);
        $this->assertSame([$home_page['pageId']], $candidates[0]['pageIds']);
    }

    public function test_phase_sixteen_visibility_route_hides_and_restores_runs(): void
    {
        $domain = 'phase16-hidden.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $created = $this->create_run($domain);

        $hide_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/visibility'
        );
        $hide_request->set_body_params(['hidden' => true]);
        $hide_response = rest_get_server()->dispatch($hide_request);

        $this->assertSame(200, $hide_response->get_status());
        $this->assertTrue((bool) $hide_response->get_data()['hidden']);

        $default_list_request = new WP_REST_Request('GET', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs');
        $default_list_response = rest_get_server()->dispatch($default_list_request);
        $this->assertSame(200, $default_list_response->get_status());
        $this->assertSame([], $this->find_run_by_id(isset($default_list_response->get_data()['items']) ? $default_list_response->get_data()['items'] : [], $created['runId']));

        $hidden_list_request = new WP_REST_Request('GET', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs');
        $hidden_list_request->set_query_params(['includeHidden' => 1]);
        $hidden_list_response = rest_get_server()->dispatch($hidden_list_request);

        $this->assertSame(200, $hidden_list_response->get_status());
        $hidden_item = $this->find_run_by_id(isset($hidden_list_response->get_data()['items']) ? $hidden_list_response->get_data()['items'] : [], $created['runId']);
        $this->assertNotEmpty($hidden_item);
        $this->assertTrue((bool) $hidden_item['hidden']);

        $restore_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/visibility'
        );
        $restore_request->set_body_params(['hidden' => false]);
        $restore_response = rest_get_server()->dispatch($restore_request);

        $this->assertSame(200, $restore_response->get_status());
        $this->assertFalse((bool) $restore_response->get_data()['hidden']);

        $restored_list_response = rest_get_server()->dispatch($default_list_request);
        $this->assertSame(200, $restored_list_response->get_status());
        $restored_item = $this->find_run_by_id(isset($restored_list_response->get_data()['items']) ? $restored_list_response->get_data()['items'] : [], $created['runId']);
        $this->assertNotEmpty($restored_item);
        $this->assertFalse((bool) $restored_item['hidden']);
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
     * @param mixed  $pages
     * @param string $path
     * @return array<string, mixed>
     */
    private function find_page_by_path($pages, string $path): array
    {
        if (! is_array($pages)) {
            return [];
        }

        $normalized_path = $this->normalize_path($path);

        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            if ($this->normalize_path((string) ($page['path'] ?? '')) === $normalized_path) {
                return $page;
            }
        }

        return [];
    }

    /**
     * @param mixed  $items
     * @param string $run_id
     * @return array<string, mixed>
     */
    private function find_run_by_id($items, string $run_id): array
    {
        if (! is_array($items)) {
            return [];
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if ((string) ($item['runId'] ?? '') === $run_id) {
                return $item;
            }
        }

        return [];
    }

    private function register_http_stubs(string $domain): void
    {
        add_filter(
            'pre_http_request',
            static function ($preempt, $args, $url) use ($domain) {
                unset($args);

                $responses = [
                    'https://' . $domain . '/sitemap.xml' => [
                        'body' => '<?xml version="1.0" encoding="UTF-8"?><urlset><url><loc>https://' . $domain . '/</loc></url><url><loc>https://' . $domain . '/about/</loc></url><url><loc>https://' . $domain . '/contact/</loc></url></urlset>',
                    ],
                    'https://' . $domain . '/' => [
                        'body' => '<html><head><title>Home Title</title><meta name="description" content="Home description" /></head><body><main><h1>Home Heading</h1><p>Hero intro copy.</p><img src="/hero.jpg" alt="Homepage hero" /></main></body></html>',
                    ],
                    'https://' . $domain . '/about/' => [
                        'body' => '<html><head><title>About Title</title><meta name="description" content="About description" /></head><body><main><h1>About Heading</h1><p>About copy.</p></main></body></html>',
                    ],
                    'https://' . $domain . '/contact/' => [
                        'body' => '<html><head><title>Contact Title</title><meta name="description" content="Contact description" /></head><body><main><h1>Contact Heading</h1><p>Contact copy.</p></main></body></html>',
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

            @unlink($path);
        }

        @rmdir($directory);
    }

    private function normalize_path(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return '/';
        }

        return '/' . trim($path, '/');
    }

    private function reset_rest_server(): void
    {
        global $wp_rest_server;

        $wp_rest_server = new WP_REST_Server();
        do_action('rest_api_init');
    }
}
