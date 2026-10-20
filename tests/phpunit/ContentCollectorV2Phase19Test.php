<?php

class ContentCollectorV2Phase19Test extends WP_UnitTestCase
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
        remove_all_filters('dbvc_cc_v2_enable_recovery_qa_fixture');

        foreach ($this->domains_to_cleanup as $domain) {
            $this->delete_domain_artifacts($domain);
        }

        DBVC_CC_V2_Run_Recovery_QA_Fixture_Service::get_instance()->clear_fixture();

        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);

        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();

        parent::tear_down();
    }

    public function test_phase_nineteen_recovery_fixture_route_seeds_and_clears_deterministic_rerun_candidates(): void
    {
        $domain = 'phase19-recovery.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $created = $this->create_run(
            $domain,
            [
                'maxUrls' => 2,
            ]
        );

        $seed_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/qa/recovery-fixture'
        );
        $seed_request->set_body_params(
            [
                'enabled' => true,
                'stage' => DBVC_CC_V2_Contracts::AI_STAGE_RECOMMENDATION_FINALIZATION,
            ]
        );

        $seed_response = rest_get_server()->dispatch($seed_request);
        $this->assertSame(200, $seed_response->get_status());

        $seed_payload = $seed_response->get_data();
        $this->assertTrue((bool) $seed_payload['enabled']);
        $this->assertSame((string) $created['runId'], (string) $seed_payload['runId']);
        $this->assertSame(DBVC_CC_V2_Contracts::AI_STAGE_RECOMMENDATION_FINALIZATION, (string) $seed_payload['stage']);
        $this->assertNotSame('', (string) $seed_payload['pageId']);

        $list_request = new WP_REST_Request('GET', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs');
        $list_response = rest_get_server()->dispatch($list_request);
        $this->assertSame(200, $list_response->get_status());

        $run_item = $this->find_run_by_id(
            isset($list_response->get_data()['items']) ? $list_response->get_data()['items'] : [],
            (string) $created['runId']
        );
        $this->assertNotEmpty($run_item);

        $candidates = isset($run_item['actionSummary']['rerunCandidates']) && is_array($run_item['actionSummary']['rerunCandidates'])
            ? $run_item['actionSummary']['rerunCandidates']
            : [];

        $this->assertCount(1, $candidates);
        $this->assertSame(DBVC_CC_V2_Contracts::AI_STAGE_RECOMMENDATION_FINALIZATION, (string) $candidates[0]['stage']);
        $this->assertSame(1, (int) $candidates[0]['count']);
        $this->assertSame([(string) $seed_payload['pageId']], $candidates[0]['pageIds']);
        $this->assertSame([DBVC_CC_V2_Contracts::STEP_RECOMMENDED_MAPPINGS_FINALIZED], $candidates[0]['stepKeys']);

        $run_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId']
        );
        $run_response = rest_get_server()->dispatch($run_request);
        $this->assertSame(200, $run_response->get_status());

        $run_candidates = isset($run_response->get_data()['actionSummary']['rerunCandidates']) && is_array($run_response->get_data()['actionSummary']['rerunCandidates'])
            ? $run_response->get_data()['actionSummary']['rerunCandidates']
            : [];
        $this->assertCount(1, $run_candidates);
        $this->assertSame((string) $seed_payload['pageId'], (string) $run_candidates[0]['pageIds'][0]);

        $clear_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/qa/recovery-fixture'
        );
        $clear_request->set_body_params(['enabled' => false]);
        $clear_response = rest_get_server()->dispatch($clear_request);

        $this->assertSame(200, $clear_response->get_status());
        $this->assertFalse((bool) $clear_response->get_data()['enabled']);

        $cleared_list_response = rest_get_server()->dispatch($list_request);
        $this->assertSame(200, $cleared_list_response->get_status());

        $cleared_run_item = $this->find_run_by_id(
            isset($cleared_list_response->get_data()['items']) ? $cleared_list_response->get_data()['items'] : [],
            (string) $created['runId']
        );
        $this->assertNotEmpty($cleared_run_item);

        $cleared_candidates = isset($cleared_run_item['actionSummary']['rerunCandidates']) && is_array($cleared_run_item['actionSummary']['rerunCandidates'])
            ? $cleared_run_item['actionSummary']['rerunCandidates']
            : [];
        $this->assertSame([], $cleared_candidates);
    }

    public function test_phase_nineteen_recovery_fixture_route_is_rejected_when_helper_disabled(): void
    {
        $domain = 'phase19-disabled.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $created = $this->create_run($domain);

        add_filter('dbvc_cc_v2_enable_recovery_qa_fixture', '__return_false');

        $seed_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/qa/recovery-fixture'
        );
        $seed_request->set_body_params(['enabled' => true]);

        $seed_response = rest_get_server()->dispatch($seed_request);
        $this->assertSame(403, $seed_response->get_status());
    }

    public function test_phase_nineteen_create_run_can_use_deterministic_replay_fixture(): void
    {
        $domain = 'phase19-replay.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $source = $this->create_run(
            $domain,
            [
                'maxUrls' => 2,
            ]
        );

        $replay = $this->create_run(
            $domain,
            [
                'qaReplaySourceRunId' => $source['runId'],
            ]
        );

        $this->assertNotSame((string) $source['runId'], (string) $replay['runId']);
        $this->assertSame((string) $replay['runId'], (string) $replay['latest']['journey_id']);
        $this->assertSame((string) $domain, (string) $replay['domain']);
        $this->assertSame((string) $replay['runId'], (string) $replay['runProfile']['runId']);

        $source_overview_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $source['runId'] . '/overview'
        );
        $source_overview_response = rest_get_server()->dispatch($source_overview_request);
        $this->assertSame(200, $source_overview_response->get_status());

        $replay_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $replay['runId']
        );
        $replay_response = rest_get_server()->dispatch($replay_request);
        $this->assertSame(200, $replay_response->get_status());

        $replay_payload = $replay_response->get_data();
        $this->assertSame((string) $replay['runId'], (string) $replay_payload['runId']);
        $this->assertSame((string) $replay['runId'], (string) $replay_payload['runProfile']['runId']);
        $this->assertSame((string) $domain, (string) $replay_payload['domain']);
    }

    public function test_phase_nineteen_deterministic_replay_fixture_clones_page_artifacts_into_an_overwrite_chain(): void
    {
        $domain = 'phase19-replay-overwrite.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $source = $this->create_run(
            $domain,
            [
                'maxUrls' => 1,
            ]
        );
        $source_page_id = $this->get_single_run_page_id((string) $source['runId']);

        $replay = $this->create_run(
            $domain,
            [
                'qaReplaySourceRunId' => $source['runId'],
            ]
        );

        $source_context = DBVC_CC_V2_Page_Artifact_Service::get_instance()->resolve_page_context_for_run(
            (string) $source['runId'],
            $source_page_id
        );
        $this->assertIsArray($source_context);

        $current_raw_artifact = $this->read_json_file((string) $source_context['current_artifact_paths']['raw']);
        $this->assertIsArray($current_raw_artifact);
        $this->assertSame((string) $replay['runId'], (string) $current_raw_artifact['journey_id']);

        $historical_raw_artifact = $this->read_json_file((string) $source_context['artifact_paths']['raw']);
        $this->assertIsArray($historical_raw_artifact);
        $this->assertSame((string) $source['runId'], (string) $historical_raw_artifact['journey_id']);
        $this->assertStringContainsString(
            '/' . DBVC_CC_V2_Contracts::STORAGE_PAGE_RUNS_SUBDIR . '/' . (string) $source['runId'] . '/',
            wp_normalize_path((string) $source_context['artifact_paths']['raw'])
        );

        $source_detail = $this->get_review_detail((string) $source['runId'], $source_page_id);
        $this->assertSame((string) $source['runId'], (string) $source_detail['runId']);
        $this->assertSame('Phase 19 Home', (string) $source_detail['evidence']['source']['title']);

        $replay_detail = $this->get_review_detail((string) $replay['runId'], $source_page_id);
        $this->assertSame((string) $replay['runId'], (string) $replay_detail['runId']);
        $this->assertSame('Phase 19 Home', (string) $replay_detail['evidence']['source']['title']);
    }

    public function test_phase_nineteen_deterministic_replay_fixture_is_rejected_when_helper_disabled(): void
    {
        $domain = 'phase19-replay-disabled.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $source = $this->create_run($domain);

        add_filter('dbvc_cc_v2_enable_recovery_qa_fixture', '__return_false');

        $request = new WP_REST_Request('POST', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs');
        $request->set_body_params(
            [
                'domain' => $domain,
                'sitemapUrl' => 'https://' . $domain . '/sitemap.xml',
                'qaReplaySourceRunId' => $source['runId'],
            ]
        );

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(403, $response->get_status());
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

    private function get_single_run_page_id(string $run_id): string
    {
        $inventory = DBVC_CC_V2_URL_Inventory_Service::get_instance()->get_inventory_for_run($run_id);
        $this->assertIsArray($inventory);

        $rows = isset($inventory['urls']) && is_array($inventory['urls']) ? $inventory['urls'] : [];
        $this->assertNotEmpty($rows);

        return (string) $rows[0]['page_id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function get_review_detail(string $run_id, string $page_id): array
    {
        $request = new WP_REST_Request('GET', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/urls/' . $page_id);
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        return $response->get_data();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read_json_file(string $path): ?array
    {
        if ($path === '' || ! file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
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
                        'body' => '<html><head><title>Phase 19 Home</title><meta name="description" content="Phase 19 home description" /></head><body><main><h1>Phase 19 Home</h1><p>Deterministic recovery fixture home page.</p></main></body></html>',
                    ],
                    'https://' . $domain . '/about/' => [
                        'body' => '<html><head><title>Phase 19 About</title><meta name="description" content="Phase 19 about description" /></head><body><main><h1>Phase 19 About</h1><p>Deterministic recovery fixture secondary page.</p></main></body></html>',
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
