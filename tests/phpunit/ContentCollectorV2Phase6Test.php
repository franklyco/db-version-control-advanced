<?php

class ContentCollectorV2Phase6Test extends WP_UnitTestCase
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

    public function test_phase_six_exception_routes_surface_queue_and_inspector_payloads(): void
    {
        $domain = 'phase6-source.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $created = $this->create_run($domain);
        $home_page = $this->find_page_by_path(isset($created['pages']) ? $created['pages'] : [], '/');

        $queue_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/exceptions'
        );
        $queue_response = rest_get_server()->dispatch($queue_request);

        $this->assertSame(200, $queue_response->get_status());
        $queue_payload = $queue_response->get_data();
        $this->assertNotEmpty($queue_payload['items']);
        $this->assertSame($home_page['pageId'], $queue_payload['items'][0]['pageId']);
        $this->assertSame('needs_review', $queue_payload['items'][0]['status']);

        $detail_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/urls/' . $home_page['pageId']
        );
        $detail_response = rest_get_server()->dispatch($detail_request);

        $this->assertSame(200, $detail_response->get_status());
        $detail_payload = $detail_response->get_data();
        $this->assertSame($home_page['pageId'], $detail_payload['pageId']);
        $this->assertSame('needs_review', $detail_payload['summary']['reviewStatus']);
        $this->assertContains(DBVC_CC_V2_Contracts::AI_STAGE_MAPPING_INDEX, $detail_payload['actions']['rerunStages']);
        $this->assertContains(DBVC_CC_V2_Contracts::AI_STAGE_RECOMMENDATION_FINALIZATION, $detail_payload['actions']['rerunStages']);
        $this->assertSame('mapping-decisions.v2', $detail_payload['decisions']['mapping']['artifact_type']);
        $this->assertNotEmpty($detail_payload['evidence']['mapping']['recommendations']);
    }

    public function test_phase_six_decision_route_persists_override_artifacts_and_mapping_rerun(): void
    {
        $domain = 'phase6-decisions.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $created = $this->create_run($domain);
        $home_page = $this->find_page_by_path(isset($created['pages']) ? $created['pages'] : [], '/');

        $detail_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/urls/' . $home_page['pageId']
        );
        $detail_response = rest_get_server()->dispatch($detail_request);
        $detail_payload = $detail_response->get_data();

        $field_recommendation = $detail_payload['recommendations']['fieldRecommendations'][0];
        $media_recommendation = $detail_payload['recommendations']['mediaRecommendations'][0];

        $decision_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/urls/' . $home_page['pageId'] . '/decision'
        );
        $decision_request->set_body_params(
            [
                'reviewerNote' => 'Force hero subtitle and featured image.',
                'targetObject' => [
                    'decisionMode' => 'override',
                    'selectedTargetFamily' => 'post_type',
                    'selectedTargetObjectKey' => 'page',
                    'selectedResolutionMode' => DBVC_CC_V2_Contracts::RESOLUTION_MODE_UPDATE_EXISTING,
                ],
                'approvedRecommendationIds' => [$field_recommendation['recommendation_id']],
                'approvedMediaIds' => [$media_recommendation['recommendation_id']],
                'mappingOverrides' => [
                    [
                        'recommendationId' => $field_recommendation['recommendation_id'],
                        'overrideScope' => 'field',
                        'overrideTarget' => 'meta:post:page:hero_subheading',
                    ],
                ],
                'mediaOverrides' => [
                    [
                        'recommendationId' => $media_recommendation['recommendation_id'],
                        'overrideTarget' => 'core:featured_image',
                    ],
                ],
            ]
        );

        $decision_response = rest_get_server()->dispatch($decision_request);
        $this->assertSame(200, $decision_response->get_status());
        $decision_payload = $decision_response->get_data();
        $this->assertSame('overridden', $decision_payload['summary']['decisionStatus']);
        $this->assertSame('override', $decision_payload['decisions']['mapping']['target_object_decision']['decision_mode']);
        $this->assertNotEmpty($decision_payload['decisions']['mapping']['overrides']);
        $this->assertNotEmpty($decision_payload['decisions']['media']['overrides']);

        $home_dir = DBVC_CC_Artifact_Manager::get_page_dir('https://' . $domain . '/');
        $this->assertFileExists($home_dir . '/home.mapping-decisions.v2.json');
        $this->assertFileExists($home_dir . '/home.media-decisions.v2.json');

        $mapping_decisions = json_decode((string) file_get_contents($home_dir . '/home.mapping-decisions.v2.json'), true);
        $media_decisions = json_decode((string) file_get_contents($home_dir . '/home.media-decisions.v2.json'), true);

        $this->assertSame('mapping-decisions.v2', $mapping_decisions['artifact_type']);
        $this->assertSame('overridden', $mapping_decisions['decision_status']);
        $this->assertSame('meta:post:page:hero_subheading', $mapping_decisions['overrides'][0]['override_target']);
        $this->assertSame('media-decisions.v2', $media_decisions['artifact_type']);
        $this->assertSame('core:featured_image', $media_decisions['overrides'][0]['override_target']);

        $rerun_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/urls/' . $home_page['pageId'] . '/rerun'
        );
        $rerun_request->set_body_params(
            [
                'stage' => DBVC_CC_V2_Contracts::AI_STAGE_MAPPING_INDEX,
            ]
        );

        $rerun_response = rest_get_server()->dispatch($rerun_request);
        $this->assertSame(200, $rerun_response->get_status());
        $rerun_payload = $rerun_response->get_data();
        $this->assertSame(DBVC_CC_V2_Contracts::AI_STAGE_MAPPING_INDEX, $rerun_payload['stage']);
        $this->assertSame([DBVC_CC_V2_Contracts::AI_STAGE_MAPPING_INDEX], $rerun_payload['stagesRun']);
        $this->assertSame('completed', $rerun_payload['page']['finalizationStatus']);
    }

    private function create_run(string $domain): array
    {
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

        return $response->get_data();
    }

    private function register_http_stubs(string $domain): void
    {
        add_filter(
            'pre_http_request',
            static function ($preempt, $args, $url) use ($domain) {
                unset($args);

                $responses = [
                    'https://' . $domain . '/sitemap.xml' => [
                        'body' => '<?xml version="1.0" encoding="UTF-8"?><urlset><url><loc>https://' . $domain . '/</loc></url><url><loc>https://' . $domain . '/about/</loc></url></urlset>',
                    ],
                    'https://' . $domain . '/' => [
                        'body' => '<html><head><title>Home Title</title><meta name="description" content="Home description" /></head><body><main><h1>Home Heading</h1><p>Hero intro copy.</p><img src="/hero.jpg" alt="Homepage hero" /><a href="/contact/">Request Quote</a><h2>FAQ</h2><p>What do you offer?</p><p>We offer migrations.</p></main></body></html>',
                    ],
                    'https://' . $domain . '/about/' => [
                        'body' => '<html><head><title>About Title</title><meta name="description" content="About description" /></head><body><main><h1>About Heading</h1><p>About copy.</p></main></body></html>',
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
     * @param mixed  $pages
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

    private function reset_rest_server(): void
    {
        global $wp_rest_server;

        $wp_rest_server = new WP_REST_Server();
        do_action('rest_api_init');
    }
}
