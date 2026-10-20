<?php

class ContentCollectorV2Phase14Test extends WP_UnitTestCase
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

    public function test_phase_fourteen_queue_prioritizes_conflict_first_review_states(): void
    {
        $domain = 'phase14-queue.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $created = $this->create_run($domain);
        $pages = isset($created['pages']) && is_array($created['pages']) ? $created['pages'] : [];

        $home_page = $this->find_page_by_path($pages, '/');
        $about_page = $this->find_page_by_path($pages, '/about/');
        $contact_page = $this->find_page_by_path($pages, '/contact/');
        $services_page = $this->find_page_by_path($pages, '/services/');
        $faq_page = $this->find_page_by_path($pages, '/faq/');

        $this->assertNotEmpty($home_page);
        $this->assertNotEmpty($about_page);
        $this->assertNotEmpty($contact_page);
        $this->assertNotEmpty($services_page);
        $this->assertNotEmpty($faq_page);

        $this->approve_page($created['runId'], $contact_page['pageId'], 'Approve page for stale-state queue validation.');
        $this->approve_page($created['runId'], $faq_page['pageId'], 'Approve page for ready-after-review queue validation.');
        $this->override_page($created['runId'], $services_page['pageId']);

        $this->inject_mapping_recommendation_state(
            $domain,
            $contact_page['pageId'],
            [
                'review' => [
                    'status' => 'needs_review',
                    'reason_codes' => [],
                ],
                'conflicts' => [],
                'unresolved_items' => [],
            ]
        );
        $this->sync_mapping_decision_fingerprint($domain, $contact_page['pageId']);
        $this->inject_mapping_recommendation_state(
            $domain,
            $services_page['pageId'],
            [
                'review' => [
                    'status' => 'needs_review',
                    'reason_codes' => [],
                ],
                'conflicts' => [],
                'unresolved_items' => [],
            ]
        );
        $this->sync_mapping_decision_fingerprint($domain, $services_page['pageId']);
        $this->inject_mapping_recommendation_state(
            $domain,
            $faq_page['pageId'],
            [
                'review' => [
                    'status' => 'needs_review',
                    'reason_codes' => [],
                ],
                'conflicts' => [],
                'unresolved_items' => [],
            ]
        );
        $this->sync_mapping_decision_fingerprint($domain, $faq_page['pageId']);

        $this->inject_mapping_recommendation_state(
            $domain,
            $home_page['pageId'],
            [
                'review' => [
                    'status' => 'needs_review',
                    'reason_codes' => ['target_conflicts'],
                ],
                'unresolved_items' => [],
                'conflicts' => [
                    [
                        'recommendation_id' => 'phase14-conflict-home',
                        'target_ref' => 'core:post_title',
                    ],
                ],
            ]
        );
        $this->inject_mapping_recommendation_state(
            $domain,
            $about_page['pageId'],
            [
                'review' => [
                    'status' => 'needs_review',
                    'reason_codes' => ['unresolved_items'],
                ],
                'conflicts' => [],
                'unresolved_items' => [
                    [
                        'source_ref' => 'section:body:0',
                        'reason_code' => 'no_target_candidates',
                    ],
                ],
            ]
        );
        $this->mark_mapping_decision_stale($domain, $contact_page['pageId']);

        $queue_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/exceptions'
        );
        $queue_response = rest_get_server()->dispatch($queue_request);

        $this->assertSame(200, $queue_response->get_status());
        $queue_payload = $queue_response->get_data();
        $items = isset($queue_payload['items']) && is_array($queue_payload['items']) ? $queue_payload['items'] : [];
        $counts = isset($queue_payload['counts']) && is_array($queue_payload['counts']) ? $queue_payload['counts'] : [];

        $this->assertSame(5, count($items));
        $this->assertSame(5, (int) ($counts['all'] ?? 0));
        $this->assertSame(1, (int) ($counts['conflicts'] ?? 0));
        $this->assertSame(1, (int) ($counts['unresolved'] ?? 0));
        $this->assertSame(1, (int) ($counts['stale'] ?? 0));
        $this->assertSame(1, (int) ($counts['overridden'] ?? 0));
        $this->assertSame(1, (int) ($counts['readyAfterReview'] ?? 0));

        $this->assertSame(
            [
                $home_page['pageId'],
                $about_page['pageId'],
                $contact_page['pageId'],
                $services_page['pageId'],
                $faq_page['pageId'],
            ],
            array_values(array_map(static function ($item) {
                return is_array($item) ? (string) ($item['pageId'] ?? '') : '';
            }, $items))
        );

        $this->assertSame('conflicts', $items[0]['queueState']);
        $this->assertSame('Resolve conflicts', $items[0]['quickAction']['label']);
        $this->assertSame('conflicts', $items[0]['quickAction']['panelTab']);

        $this->assertSame('unresolved', $items[1]['queueState']);
        $this->assertSame('Review unresolved', $items[1]['quickAction']['label']);

        $this->assertSame('stale', $items[2]['queueState']);
        $this->assertSame('Review stale decision', $items[2]['quickAction']['label']);

        $this->assertSame('overridden', $items[3]['queueState']);
        $this->assertSame('Review overrides', $items[3]['quickAction']['label']);

        $this->assertSame('ready_after_review', $items[4]['queueState']);
        $this->assertSame('Reopen reviewed item', $items[4]['quickAction']['label']);
        $this->assertSame('summary', $items[4]['quickAction']['panelTab']);

        $conflicts_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/exceptions'
        );
        $conflicts_request->set_query_params(['filter' => 'conflicts']);
        $conflicts_response = rest_get_server()->dispatch($conflicts_request);
        $conflicts_payload = $conflicts_response->get_data();

        $this->assertSame(200, $conflicts_response->get_status());
        $this->assertSame($home_page['pageId'], $conflicts_payload['items'][0]['pageId']);

        $ready_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/exceptions'
        );
        $ready_request->set_query_params(['filter' => 'ready-after-review']);
        $ready_response = rest_get_server()->dispatch($ready_request);
        $ready_payload = $ready_response->get_data();

        $this->assertSame(200, $ready_response->get_status());
        $this->assertSame($faq_page['pageId'], $ready_payload['items'][0]['pageId']);
    }

    public function test_saving_resolved_conflict_decisions_clears_active_conflict_state(): void
    {
        $domain = 'phase14-resolved-conflict.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $created = $this->create_run($domain);
        $home_page = $this->find_page_by_path(isset($created['pages']) ? $created['pages'] : [], '/');

        $this->assertNotEmpty($home_page);

        $detail_payload = $this->get_review_detail($created['runId'], $home_page['pageId']);
        $field_recommendations = isset($detail_payload['recommendations']['fieldRecommendations']) && is_array($detail_payload['recommendations']['fieldRecommendations'])
            ? array_values($detail_payload['recommendations']['fieldRecommendations'])
            : [];

        $this->assertGreaterThanOrEqual(2, count($field_recommendations));

        $approved_recommendation = $field_recommendations[0];
        $rejected_recommendation = $field_recommendations[1];

        $this->inject_mapping_recommendation_state(
            $domain,
            $home_page['pageId'],
            [
                'review' => [
                    'status' => 'needs_review',
                    'reason_codes' => ['target_conflicts'],
                ],
                'unresolved_items' => [],
                'conflicts' => [
                    [
                        'reason' => 'duplicate_target_ref',
                        'target_ref' => isset($approved_recommendation['target_ref']) ? (string) $approved_recommendation['target_ref'] : 'core:post_title',
                        'recommendation_ids' => [
                            (string) $approved_recommendation['recommendation_id'],
                            (string) $rejected_recommendation['recommendation_id'],
                        ],
                    ],
                ],
            ]
        );

        $decision_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/urls/' . $home_page['pageId'] . '/decision'
        );
        $decision_request->set_body_params(
            [
                'reviewerNote' => 'Resolve duplicate target ref by keeping one recommendation and rejecting the other.',
                'approvedRecommendationIds' => [
                    (string) $approved_recommendation['recommendation_id'],
                ],
                'rejectedRecommendationIds' => [
                    (string) $rejected_recommendation['recommendation_id'],
                ],
            ]
        );

        $decision_response = rest_get_server()->dispatch($decision_request);
        $this->assertSame(200, $decision_response->get_status());

        $updated_detail = $this->get_review_detail($created['runId'], $home_page['pageId']);
        $this->assertSame(0, (int) ($updated_detail['summary']['counts']['conflicts'] ?? 0));
        $this->assertEmpty($updated_detail['recommendations']['conflicts']);

        $queue_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/exceptions'
        );
        $queue_response = rest_get_server()->dispatch($queue_request);
        $this->assertSame(200, $queue_response->get_status());

        $queue_payload = $queue_response->get_data();
        $items = isset($queue_payload['items']) && is_array($queue_payload['items']) ? $queue_payload['items'] : [];
        $home_queue_item = [];
        foreach ($items as $item) {
            if (is_array($item) && (string) ($item['pageId'] ?? '') === $home_page['pageId']) {
                $home_queue_item = $item;
                break;
            }
        }

        $this->assertNotEmpty($home_queue_item);
        $this->assertSame(0, (int) ($home_queue_item['conflictCount'] ?? 0));
        $this->assertFalse(! empty($home_queue_item['hasConflicts']));
        $this->assertNotSame('conflicts', (string) ($home_queue_item['queueState'] ?? ''));
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

    private function approve_page(string $run_id, string $page_id, string $reviewer_note): void
    {
        $detail_payload = $this->get_review_detail($run_id, $page_id);

        $approved_field_ids = [];
        $field_recommendations = isset($detail_payload['recommendations']['fieldRecommendations']) && is_array($detail_payload['recommendations']['fieldRecommendations'])
            ? $detail_payload['recommendations']['fieldRecommendations']
            : [];
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
        $media_recommendations = isset($detail_payload['recommendations']['mediaRecommendations']) && is_array($detail_payload['recommendations']['mediaRecommendations'])
            ? $detail_payload['recommendations']['mediaRecommendations']
            : [];
        foreach ($media_recommendations as $media_recommendation) {
            if (! is_array($media_recommendation) || empty($media_recommendation['recommendation_id'])) {
                continue;
            }

            if ((string) ($media_recommendation['target_ref'] ?? '') !== 'core:featured_image') {
                continue;
            }

            $approved_media_ids[] = (string) $media_recommendation['recommendation_id'];
        }

        $decision_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/urls/' . $page_id . '/decision'
        );
        $decision_request->set_body_params(
            [
                'reviewerNote' => $reviewer_note,
                'approvedRecommendationIds' => $approved_field_ids,
                'approvedMediaIds' => $approved_media_ids,
            ]
        );

        $decision_response = rest_get_server()->dispatch($decision_request);
        $this->assertSame(200, $decision_response->get_status());
    }

    private function override_page(string $run_id, string $page_id): void
    {
        $detail_payload = $this->get_review_detail($run_id, $page_id);
        $field_recommendations = isset($detail_payload['recommendations']['fieldRecommendations']) && is_array($detail_payload['recommendations']['fieldRecommendations'])
            ? $detail_payload['recommendations']['fieldRecommendations']
            : [];

        $override_recommendation = isset($field_recommendations[0]) && is_array($field_recommendations[0])
            ? $field_recommendations[0]
            : [];
        $this->assertNotEmpty($override_recommendation);

        $decision_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/urls/' . $page_id . '/decision'
        );
        $decision_request->set_body_params(
            [
                'reviewerNote' => 'Create a manual override for Phase 14 queue coverage.',
                'mappingOverrides' => [
                    [
                        'recommendationId' => (string) $override_recommendation['recommendation_id'],
                        'overrideScope' => 'field',
                        'overrideTarget' => 'meta:post:page:hero_subheading',
                    ],
                ],
            ]
        );

        $decision_response = rest_get_server()->dispatch($decision_request);
        $this->assertSame(200, $decision_response->get_status());
    }

    private function get_review_detail(string $run_id, string $page_id): array
    {
        $detail_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/urls/' . $page_id
        );
        $detail_response = rest_get_server()->dispatch($detail_request);
        $this->assertSame(200, $detail_response->get_status());

        return $detail_response->get_data();
    }

    /**
     * @param array<string, mixed> $mutations
     */
    private function inject_mapping_recommendation_state(string $domain, string $page_id, array $mutations): void
    {
        $page_context = DBVC_CC_V2_Page_Artifact_Service::get_instance()->resolve_page_context($domain, $page_id);
        $this->assertNotWPError($page_context);

        $path = isset($page_context['artifact_paths']['mapping_recommendations'])
            ? (string) $page_context['artifact_paths']['mapping_recommendations']
            : '';
        $payload = $this->read_json_file($path);
        $this->assertIsArray($payload);

        foreach ($mutations as $key => $value) {
            $payload[$key] = $value;
        }

        $this->assertTrue(DBVC_CC_Artifact_Manager::write_json_file($path, $payload));
    }

    private function mark_mapping_decision_stale(string $domain, string $page_id): void
    {
        $page_context = DBVC_CC_V2_Page_Artifact_Service::get_instance()->resolve_page_context($domain, $page_id);
        $this->assertNotWPError($page_context);

        $path = isset($page_context['artifact_paths']['mapping_decisions'])
            ? (string) $page_context['artifact_paths']['mapping_decisions']
            : '';
        $payload = $this->read_json_file($path);
        $this->assertIsArray($payload);

        $payload['recommendation_fingerprint'] = 'phase14-stale-fingerprint';
        $this->assertTrue(DBVC_CC_Artifact_Manager::write_json_file($path, $payload));
    }

    private function sync_mapping_decision_fingerprint(string $domain, string $page_id): void
    {
        $page_context = DBVC_CC_V2_Page_Artifact_Service::get_instance()->resolve_page_context($domain, $page_id);
        $this->assertNotWPError($page_context);

        $mapping_recommendations_path = isset($page_context['artifact_paths']['mapping_recommendations'])
            ? (string) $page_context['artifact_paths']['mapping_recommendations']
            : '';
        $mapping_decisions_path = isset($page_context['artifact_paths']['mapping_decisions'])
            ? (string) $page_context['artifact_paths']['mapping_decisions']
            : '';

        $recommendations = $this->read_json_file($mapping_recommendations_path);
        $mapping_decisions = $this->read_json_file($mapping_decisions_path);

        $this->assertIsArray($recommendations);
        $this->assertIsArray($mapping_decisions);

        $mapping_decisions['recommendation_fingerprint'] = DBVC_CC_V2_Page_Artifact_Service::get_instance()->compute_fingerprint($recommendations);
        $this->assertTrue(DBVC_CC_Artifact_Manager::write_json_file($mapping_decisions_path, $mapping_decisions));
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

    private function register_http_stubs(string $domain): void
    {
        add_filter(
            'pre_http_request',
            static function ($preempt, $args, $url) use ($domain) {
                unset($args);

                $responses = [
                    'https://' . $domain . '/sitemap.xml' => [
                        'body' => '<?xml version="1.0" encoding="UTF-8"?><urlset><url><loc>https://' . $domain . '/</loc></url><url><loc>https://' . $domain . '/about/</loc></url><url><loc>https://' . $domain . '/contact/</loc></url><url><loc>https://' . $domain . '/services/</loc></url><url><loc>https://' . $domain . '/faq/</loc></url></urlset>',
                    ],
                    'https://' . $domain . '/' => [
                        'body' => '<html><head><title>Home Title</title><meta name="description" content="Home description" /></head><body><main><h1>Home Heading</h1><p>Hero intro copy.</p><img src="/hero.jpg" alt="Homepage hero" /><a href="/contact/">Request Quote</a><h2>FAQ</h2><p>What do you offer?</p><p>We offer migrations.</p></main></body></html>',
                    ],
                    'https://' . $domain . '/about/' => [
                        'body' => '<html><head><title>About Title</title><meta name="description" content="About description" /></head><body><main><h1>About Heading</h1><p>About copy.</p></main></body></html>',
                    ],
                    'https://' . $domain . '/contact/' => [
                        'body' => '<html><head><title>Contact Title</title><meta name="description" content="Contact description" /></head><body><main><h1>Contact Heading</h1><p>Contact copy.</p><img src="/contact.jpg" alt="Contact hero" /></main></body></html>',
                    ],
                    'https://' . $domain . '/services/' => [
                        'body' => '<html><head><title>Services Title</title><meta name="description" content="Services description" /></head><body><main><h1>Services Heading</h1><p>Services copy.</p><img src="/services.jpg" alt="Services hero" /></main></body></html>',
                    ],
                    'https://' . $domain . '/faq/' => [
                        'body' => '<html><head><title>FAQ Title</title><meta name="description" content="FAQ description" /></head><body><main><h1>FAQ Heading</h1><p>FAQ copy.</p><img src="/faq.jpg" alt="FAQ hero" /></main></body></html>',
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

    /**
     * @return array<string, mixed>|null
     */
    private function read_json_file(string $path)
    {
        if ($path === '' || ! file_exists($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
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
