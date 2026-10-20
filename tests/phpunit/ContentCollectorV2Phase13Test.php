<?php

class ContentCollectorV2Phase13Test extends WP_UnitTestCase
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

    public function test_schema_presentation_service_resolves_meta_acf_and_taxonomy_labels_from_catalog(): void
    {
        $domain = 'phase13-schema-labels.example';
        $this->domains_to_cleanup[] = $domain;

        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        $this->assertNotWPError($context);

        $catalog_payload = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'target-field-catalog.v2',
            'domain' => $domain,
            'generated_at' => current_time('c'),
            'catalog_fingerprint' => 'phase13-test',
            'object_catalog' => [
                'page' => [
                    'slug' => 'page',
                    'label' => 'Page',
                ],
            ],
            'taxonomy_catalog' => [
                'service_category' => [
                    'slug' => 'service_category',
                    'label' => 'Service Categories',
                ],
            ],
            'meta_catalog' => [
                'post' => [
                    'page' => [
                        'hero_subheading' => [
                            'meta_key' => 'hero_subheading',
                            'type' => 'string',
                            'description' => 'Hero subheading copy.',
                        ],
                    ],
                ],
            ],
            'acf_catalog' => [
                'available' => true,
                'groups' => [
                    'group_content_fields' => [
                        'key' => 'group_content_fields',
                        'title' => 'Content Fields',
                        'location' => [
                            [
                                [
                                    'param' => 'post_type',
                                    'operator' => '==',
                                    'value' => 'page',
                                ],
                            ],
                        ],
                        'fields' => [
                            'field_hero_cta' => [
                                'key' => 'field_hero_cta',
                                'name' => 'hero_cta',
                                'label' => 'Hero CTA',
                                'type' => 'text',
                                'parent' => '',
                                'parent_type' => '',
                            ],
                        ],
                    ],
                ],
            ],
            'media_field_catalog' => [],
        ];

        $this->assertTrue(
            DBVC_CC_Artifact_Manager::write_json_file($context['target_field_catalog_file'], $catalog_payload)
        );

        $service = DBVC_CC_V2_Schema_Presentation_Service::get_instance();

        $meta_presentation = $service->resolve_target_ref($domain, 'meta:post:page:hero_subheading');
        $this->assertSame('Hero Subheading', $meta_presentation['label']);
        $this->assertSame('meta:post:page:hero_subheading', $meta_presentation['machineRef']);
        $this->assertSame('Page', $meta_presentation['objectLabel']);
        $this->assertSame('Text', $meta_presentation['fieldTypeLabel']);

        $acf_presentation = $service->resolve_target_ref($domain, 'acf:group_content_fields:field_hero_cta');
        $this->assertSame('Hero CTA', $acf_presentation['label']);
        $this->assertSame('Content Fields', $acf_presentation['groupLabel']);
        $this->assertSame('Page', $acf_presentation['objectLabel']);

        $taxonomy_presentation = $service->resolve_target_ref($domain, 'taxonomy:service_category');
        $this->assertSame('Service Categories', $taxonomy_presentation['label']);
        $this->assertSame('taxonomy:service_category', $taxonomy_presentation['machineRef']);
    }

    public function test_review_payload_exposes_target_presentation_metadata(): void
    {
        $domain = 'phase13-review.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $created = $this->create_run($domain);
        $home_page = $this->find_page_by_path(isset($created['pages']) ? $created['pages'] : [], '/');

        $detail_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/urls/' . $home_page['pageId']
        );
        $detail_response = rest_get_server()->dispatch($detail_request);

        $this->assertSame(200, $detail_response->get_status());
        $detail_payload = $detail_response->get_data();
        $this->assertNotEmpty($detail_payload['recommendations']['fieldRecommendations']);
        $this->assertNotEmpty($detail_payload['recommendations']['mediaRecommendations']);

        $title_recommendation = $this->find_recommendation_by_target_ref(
            $detail_payload['recommendations']['fieldRecommendations'],
            'core:post_title'
        );
        $this->assertNotEmpty($title_recommendation);
        $this->assertSame('Post Title', $title_recommendation['targetPresentation']['label']);
        $this->assertSame('core:post_title', $title_recommendation['targetPresentation']['machineRef']);

        $this->assertSame('Pages', $detail_payload['summary']['currentTargetObject']['presentation']['label']);
        $this->assertSame('Pages', $detail_payload['recommendations']['recommendedTargetObject']['presentation']['label']);
        $this->assertSame(
            'Pages',
            $detail_payload['decisions']['mapping']['target_object_decision']['selected_target_object_presentation']['label']
        );

        $media_recommendation = $detail_payload['recommendations']['mediaRecommendations'][0];
        $this->assertNotEmpty($media_recommendation['targetPresentation']['label']);
        $this->assertSame(
            $media_recommendation['target_ref'],
            $media_recommendation['targetPresentation']['machineRef']
        );
    }

    public function test_readiness_package_preview_carries_target_presentation_metadata(): void
    {
        $domain = 'phase13-readiness.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $created = $this->create_run($domain);
        $home_page = $this->find_page_by_path(isset($created['pages']) ? $created['pages'] : [], '/');
        $this->approve_page($created['runId'], $home_page['pageId']);

        $readiness_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/readiness'
        );
        $readiness_response = rest_get_server()->dispatch($readiness_request);

        $this->assertSame(200, $readiness_response->get_status());
        $payload = $readiness_response->get_data();
        $page_report = $this->find_page_report($payload, $home_page['pageId']);

        $this->assertNotEmpty($page_report['packagePreview']['field_values']);
        $this->assertNotEmpty($page_report['packagePreview']['media_refs']);
        $this->assertSame('Pages', $page_report['selectedTargetObject']['presentation']['label']);
        $this->assertNotEmpty($page_report['packagePreview']['field_values'][0]['targetPresentation']['label']);
        $this->assertSame(
            $page_report['packagePreview']['field_values'][0]['target_ref'],
            $page_report['packagePreview']['field_values'][0]['targetPresentation']['machineRef']
        );
        $this->assertNotEmpty($page_report['packagePreview']['media_refs'][0]['targetPresentation']['label']);
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

    private function approve_page(string $run_id, string $page_id): void
    {
        $detail_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/urls/' . $page_id
        );
        $detail_response = rest_get_server()->dispatch($detail_request);
        $detail_payload = $detail_response->get_data();

        $field_recommendations = isset($detail_payload['recommendations']['fieldRecommendations']) && is_array($detail_payload['recommendations']['fieldRecommendations'])
            ? $detail_payload['recommendations']['fieldRecommendations']
            : [];
        $media_recommendations = isset($detail_payload['recommendations']['mediaRecommendations']) && is_array($detail_payload['recommendations']['mediaRecommendations'])
            ? $detail_payload['recommendations']['mediaRecommendations']
            : [];

        $approved_field_ids = [];
        foreach ($field_recommendations as $field_recommendation) {
            if (! is_array($field_recommendation) || empty($field_recommendation['recommendation_id'])) {
                continue;
            }

            $target_ref = isset($field_recommendation['target_ref']) ? (string) $field_recommendation['target_ref'] : '';
            if (! in_array($target_ref, ['core:post_title', 'core:post_excerpt', 'core:post_content'], true)) {
                continue;
            }

            $approved_field_ids[] = (string) $field_recommendation['recommendation_id'];
        }

        $approved_media_ids = [];
        foreach ($media_recommendations as $media_recommendation) {
            if (! is_array($media_recommendation) || empty($media_recommendation['recommendation_id'])) {
                continue;
            }

            $target_ref = isset($media_recommendation['target_ref']) ? (string) $media_recommendation['target_ref'] : '';
            if ($target_ref === 'core:featured_image') {
                $approved_media_ids[] = (string) $media_recommendation['recommendation_id'];
            }
        }

        $decision_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/urls/' . $page_id . '/decision'
        );
        $decision_request->set_body_params(
            [
                'reviewerNote' => 'Approve page for Phase 13 schema label validation.',
                'approvedRecommendationIds' => $approved_field_ids,
                'approvedMediaIds' => $approved_media_ids,
            ]
        );

        $decision_response = rest_get_server()->dispatch($decision_request);
        $this->assertSame(200, $decision_response->get_status());
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

            if (($page['path'] ?? '') === $path) {
                return $page;
            }
        }

        return [];
    }

    /**
     * @param mixed  $recommendations
     * @param string $target_ref
     * @return array<string, mixed>
     */
    private function find_recommendation_by_target_ref($recommendations, string $target_ref): array
    {
        if (! is_array($recommendations)) {
            return [];
        }

        foreach ($recommendations as $recommendation) {
            if (! is_array($recommendation)) {
                continue;
            }

            if (($recommendation['target_ref'] ?? '') === $target_ref) {
                return $recommendation;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     * @param string               $page_id
     * @return array<string, mixed>
     */
    private function find_page_report(array $payload, string $page_id): array
    {
        $reports = isset($payload['pageReports']) && is_array($payload['pageReports']) ? $payload['pageReports'] : [];
        foreach ($reports as $report) {
            if (! is_array($report)) {
                continue;
            }

            if (($report['pageId'] ?? '') === $page_id) {
                return $report;
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
