<?php

class ContentCollectorV2Phase9Test extends WP_UnitTestCase
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
        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '0');

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
        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '0');

        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();

        parent::tear_down();
    }

    public function test_phase_nine_package_surface_persists_workflow_state_and_import_history(): void
    {
        $domain = 'phase9-observability.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $created = $this->create_run($domain);
        $home_page = $this->find_page_by_path(isset($created['pages']) ? $created['pages'] : [], '/');
        $this->approve_page($created['runId'], $home_page['pageId']);
        $page_report = $this->get_page_report($created['runId'], $home_page['pageId']);
        $package_id = $this->seed_ready_package($created['runId'], $domain, $page_report);

        $dry_run_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/dry-run'
        );
        $dry_run_request->set_query_params(['packageId' => $package_id]);
        $dry_run_response = rest_get_server()->dispatch($dry_run_request);
        $this->assertSame(200, $dry_run_response->get_status());
        $dry_run_payload = $dry_run_response->get_data();

        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');

        $approval_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/preflight-approve'
        );
        $approval_request->set_body_params(
            [
                'packageId' => $package_id,
                'confirmApproval' => true,
            ]
        );
        $approval_response = rest_get_server()->dispatch($approval_request);
        $this->assertSame(200, $approval_response->get_status());
        $approval_payload = $approval_response->get_data();
        $this->assertTrue((bool) $approval_payload['approvalValid']);

        $execute_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/execute'
        );
        $execute_request->set_body_params(
            [
                'packageId' => $package_id,
                'confirmExecute' => true,
                'approvalTokens' => $approval_payload['approvalTokens'],
            ]
        );
        $execute_response = rest_get_server()->dispatch($execute_request);
        $this->assertSame(200, $execute_response->get_status());
        $execute_payload = $execute_response->get_data();
        $this->assertNotEmpty($execute_payload['pageImports']);
        $this->assertGreaterThan(0, (int) $execute_payload['pageImports'][0]['runId']);

        $package_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/package'
        );
        $package_request->set_query_params(['packageId' => $package_id]);
        $package_response = rest_get_server()->dispatch($package_request);
        $this->assertSame(200, $package_response->get_status());
        $package_payload = $package_response->get_data();

        $this->assertSame($package_id, $package_payload['selectedPackageId']);
        $this->assertSame('completed', $package_payload['selectedPackage']['workflowState']['build']['status']);
        $this->assertSame($dry_run_payload['status'], $package_payload['selectedPackage']['workflowState']['latestDryRun']['status']);
        $this->assertSame($approval_payload['status'], $package_payload['selectedPackage']['workflowState']['latestPreflight']['status']);
        $this->assertSame($execute_payload['status'], $package_payload['selectedPackage']['workflowState']['latestExecute']['status']);
        $this->assertNotEmpty($package_payload['selectedPackage']['importHistory']);
        $this->assertGreaterThan(0, (int) $package_payload['selectedPackage']['importHistory'][0]['summary']['importRuns']);
        $this->assertSame(
            $execute_payload['pageImports'][0]['runId'],
            $package_payload['selectedPackage']['importHistory'][0]['importRuns'][0]['runId']
        );
        $this->assertSame(
            $package_payload['selectedPackage']['workflowState']['latestExecute']['status'],
            $package_payload['history'][0]['workflowSummary']['executeStatus']
        );

        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        $this->assertNotWPError($context);
        $history_payload = json_decode((string) file_get_contents($context['package_builds_file']), true);

        $this->assertArrayHasKey('workflow_state', $history_payload['builds'][0]);
        $this->assertSame(
            $dry_run_payload['status'],
            $history_payload['builds'][0]['workflow_state']['latest_dry_run']['status']
        );
        $this->assertSame(
            $approval_payload['status'],
            $history_payload['builds'][0]['workflow_state']['latest_preflight']['status']
        );
        $this->assertSame(
            $execute_payload['status'],
            $history_payload['builds'][0]['workflow_state']['latest_execute']['status']
        );
        $this->assertNotEmpty($history_payload['builds'][0]['import_history']);
    }

    public function test_create_run_accepts_crawl_overrides_for_reused_crawl_primitives(): void
    {
        $domain = 'phase9-crawl-overrides.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_crawl_override_http_stubs($domain);

        $this->reset_rest_server();

        $request = new WP_REST_Request('POST', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs');
        $request->set_body_params(
            [
                'domain' => $domain,
                'sitemapUrl' => 'https://' . $domain . '/sitemap.xml',
                'crawlOverrides' => [
                    'focus_selectors' => 'main',
                    'exclude_selectors' => '.skip',
                ],
            ]
        );

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        $payload = $response->get_data();
        $this->assertSame(1, $payload['stats']['processed_count']);

        $home_dir = DBVC_CC_Artifact_Manager::get_page_dir('https://' . $domain . '/');
        $raw = json_decode((string) file_get_contents($home_dir . '/home.json'), true);

        $this->assertSame(['Primary Heading'], $raw['headings']);
        $this->assertSame(['Primary paragraph.'], $raw['text_blocks']);
        $this->assertCount(1, $raw['links']);
        $this->assertSame('https://' . $domain . '/next', $raw['links'][0]['url']);
        $this->assertStringNotContainsString('Sidebar Heading', wp_json_encode($raw));
        $this->assertStringNotContainsString('Sidebar paragraph.', wp_json_encode($raw));
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

    private function get_page_report(string $run_id, string $page_id): array
    {
        $request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/readiness'
        );
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        $payload = $response->get_data();
        $reports = isset($payload['pageReports']) && is_array($payload['pageReports']) ? $payload['pageReports'] : [];
        foreach ($reports as $report) {
            if (is_array($report) && (($report['pageId'] ?? '') === $page_id)) {
                return $report;
            }
        }

        return [];
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

        if (empty($approved_field_ids) && ! empty($field_recommendations[0]['recommendation_id'])) {
            $approved_field_ids[] = (string) $field_recommendations[0]['recommendation_id'];
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

        if (empty($approved_media_ids) && ! empty($media_recommendations[0]['recommendation_id'])) {
            $approved_media_ids[] = (string) $media_recommendations[0]['recommendation_id'];
        }

        $decision_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/urls/' . $page_id . '/decision'
        );
        $decision_request->set_body_params(
            [
                'reviewerNote' => 'Approve page for Phase 9 package observability validation.',
                'approvedRecommendationIds' => $approved_field_ids,
                'approvedMediaIds' => $approved_media_ids,
            ]
        );
        $decision_response = rest_get_server()->dispatch($decision_request);
        $this->assertSame(200, $decision_response->get_status());
    }

    private function seed_ready_package(string $run_id, string $domain, array $page_report): string
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        $this->assertNotWPError($context);

        $latest = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_latest_state($domain);
        $this->assertNotWPError($latest);

        $storage = DBVC_CC_V2_Package_Storage_Service::get_instance();
        $package_id = DBVC_CC_V2_Contracts::generate_package_id($run_id, 1);
        $package_dir = trailingslashit($context['packages_dir']) . $package_id;
        $this->assertTrue(dbvc_cc_create_security_files($package_dir));

        $preview = isset($page_report['packagePreview']) && is_array($page_report['packagePreview']) ? $page_report['packagePreview'] : [];
        $page_id = isset($page_report['pageId']) ? (string) $page_report['pageId'] : '';
        $path = isset($page_report['path']) ? (string) $page_report['path'] : '/';
        $source_url = isset($page_report['source_url']) ? (string) $page_report['source_url'] : '';
        $media_refs = isset($preview['media_refs']) && is_array($preview['media_refs']) ? array_values($preview['media_refs']) : [];

        $records = [
            [
                'page_id' => $page_id,
                'path' => $path,
                'source_url' => $source_url,
                'target_entity_key' => 'post:page',
                'target_action' => isset($preview['target_action']) ? (string) $preview['target_action'] : 'create_new',
                'field_values' => isset($preview['field_values']) && is_array($preview['field_values']) ? array_values($preview['field_values']) : [],
                'media_refs' => $media_refs,
                'trace' => isset($preview['trace']) && is_array($preview['trace']) ? $preview['trace'] : [],
            ],
        ];

        $media_items = array_values(
            array_map(
                static function ($media_ref) use ($page_id, $path) {
                    if (! is_array($media_ref)) {
                        return [];
                    }

                    return [
                        'page_id' => $page_id,
                        'path' => $path,
                        'media_id' => isset($media_ref['media_id']) ? (string) $media_ref['media_id'] : '',
                        'source_url' => isset($media_ref['source_url']) ? (string) $media_ref['source_url'] : '',
                        'target_ref' => isset($media_ref['target_ref']) ? (string) $media_ref['target_ref'] : '',
                        'media_kind' => isset($media_ref['media_kind']) ? (string) $media_ref['media_kind'] : 'image',
                        'recommendation_id' => isset($media_ref['recommendation_id']) ? (string) $media_ref['recommendation_id'] : '',
                        'trace' => [
                            'decision_source' => isset($media_ref['decision_source']) ? (string) $media_ref['decision_source'] : 'reviewed',
                            'source_refs' => isset($media_ref['source_refs']) && is_array($media_ref['source_refs']) ? array_values($media_ref['source_refs']) : [],
                        ],
                    ];
                },
                $media_refs
            )
        );

        $artifact_bundle = [
            DBVC_CC_V2_Contracts::STORAGE_PACKAGE_MANIFEST_FILE => [
                'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
                'artifact_type' => 'package-manifest.v1',
                'package_id' => $package_id,
                'journey_id' => $run_id,
                'domain' => $domain,
                'generated_at' => current_time('c'),
                'target_schema_fingerprint' => isset($latest['latest_schema_fingerprint']) ? (string) $latest['latest_schema_fingerprint'] : '',
                'included_pages' => [$page_id],
                'included_object_types' => ['post:page'],
                'stats' => [
                    'build_seq' => 1,
                    'eligible_page_count' => 1,
                    'included_page_count' => 1,
                    'record_count' => count($records),
                    'media_item_count' => count($media_items),
                    'readiness_status' => DBVC_CC_V2_Contracts::READINESS_STATUS_READY,
                ],
            ],
            DBVC_CC_V2_Contracts::STORAGE_PACKAGE_RECORDS_FILE => [
                'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
                'artifact_type' => 'package-records.v1',
                'package_id' => $package_id,
                'records' => $records,
            ],
            DBVC_CC_V2_Contracts::STORAGE_PACKAGE_MEDIA_MANIFEST_FILE => [
                'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
                'artifact_type' => 'package-media-manifest.v1',
                'package_id' => $package_id,
                'media_items' => array_values(array_filter($media_items, 'is_array')),
            ],
            DBVC_CC_V2_Contracts::STORAGE_PACKAGE_QA_REPORT_FILE => [
                'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
                'artifact_type' => 'package-qa-report.v1',
                'package_id' => $package_id,
                'journey_id' => $run_id,
                'domain' => $domain,
                'generated_at' => current_time('c'),
                'readiness_status' => DBVC_CC_V2_Contracts::READINESS_STATUS_READY,
                'blocking_issues' => [],
                'warnings' => [],
                'quality_score' => 92,
                'included_pages' => [$page_id],
            ],
            DBVC_CC_V2_Contracts::STORAGE_PACKAGE_SUMMARY_FILE => [
                'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
                'artifact_type' => 'package-summary.v1',
                'package_id' => $package_id,
                'generated_at' => current_time('c'),
                'build_seq' => 1,
                'readiness_status' => DBVC_CC_V2_Contracts::READINESS_STATUS_READY,
                'record_count' => count($records),
                'exception_count' => 0,
                'auto_accepted_count' => 0,
                'manual_override_count' => 0,
                'included_page_count' => 1,
                'media_item_count' => count($media_items),
                'blocking_issue_count' => 0,
                'warning_count' => 0,
            ],
        ];

        $this->assertTrue($storage->write_artifact_bundle($package_dir, $artifact_bundle));
        $this->assertTrue(
            $storage->write_history(
                $context,
                [],
                [
                    'package_id' => $package_id,
                    'journey_id' => $run_id,
                    'build_seq' => 1,
                    'built_at' => current_time('c'),
                    'readiness_status' => DBVC_CC_V2_Contracts::READINESS_STATUS_READY,
                    'record_count' => count($records),
                    'media_item_count' => count($media_items),
                    'included_page_count' => 1,
                    'blocking_issue_count' => 0,
                    'warning_count' => 0,
                    'quality_score' => 92,
                    'artifact_refs' => $storage->build_package_artifact_refs($context, $package_id),
                ]
            )
        );

        return $package_id;
    }

    private function register_http_stubs(string $domain): void
    {
        add_filter(
            'pre_http_request',
            static function ($preempt, $args, $url) use ($domain) {
                unset($args);

                $hero_png = base64_decode(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wn7Z3sAAAAASUVORK5CYII='
                );

                $responses = [
                    'https://' . $domain . '/sitemap.xml' => [
                        'body' => '<?xml version="1.0" encoding="UTF-8"?><urlset><url><loc>https://' . $domain . '/</loc></url><url><loc>https://' . $domain . '/about/</loc></url></urlset>',
                    ],
                    'https://' . $domain . '/' => [
                        'body' => '<html><head><title>Home Title</title><meta name="description" content="Home description" /></head><body><main><p>Hero intro copy.</p><img src="/hero.png" alt="Homepage hero" /></main></body></html>',
                    ],
                    'https://' . $domain . '/about/' => [
                        'body' => '<html><head><title>About Title</title><meta name="description" content="About description" /></head><body><main><h1>About Heading</h1><p>About copy.</p></main></body></html>',
                    ],
                    'https://' . $domain . '/hero.png' => [
                        'body' => $hero_png !== false ? $hero_png : '',
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

    private function register_crawl_override_http_stubs(string $domain): void
    {
        add_filter(
            'pre_http_request',
            static function ($preempt, $args, $url) use ($domain) {
                unset($args);

                $responses = [
                    'https://' . $domain . '/sitemap.xml' => [
                        'body' => '<?xml version="1.0" encoding="UTF-8"?><urlset><url><loc>https://' . $domain . '/</loc></url></urlset>',
                    ],
                    'https://' . $domain . '/' => [
                        'body' => '<html><head><title>Override Title</title></head><body><main><h1>Primary Heading</h1><p>Primary paragraph.</p><a href="/next/">Next</a></main><aside class="skip"><h2>Sidebar Heading</h2><p>Sidebar paragraph.</p><a href="/skip/">Skip</a></aside></body></html>',
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

            wp_delete_file($path);
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
