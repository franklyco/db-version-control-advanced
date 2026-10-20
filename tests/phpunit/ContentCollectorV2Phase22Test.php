<?php

class ContentCollectorV2Phase22Test extends WP_UnitTestCase
{
    /**
     * @var array<int, string>
     */
    private $domains_to_cleanup = [];

    /**
     * @var array<string, string>
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

    public function test_phase_twenty_two_historical_exceptions_and_review_payload_use_requested_run_artifacts(): void
    {
        $domain = 'phase22-review-history.example';
        $this->domains_to_cleanup[] = $domain;

        $source_run = $this->create_same_url_run($domain, 'Phase 22 Source About');
        $source_page_id = $this->get_single_run_page_id((string) $source_run['runId']);

        $this->create_same_url_run($domain, 'Phase 22 Replay About');

        $queue_payload = $this->get_exceptions((string) $source_run['runId']);
        $queue_item = $this->find_queue_item($queue_payload, $source_page_id);
        $this->assertNotEmpty($queue_item);
        $this->assertSame((string) $source_run['runId'], (string) $queue_item['runId']);
        $this->assertSame('/about', $this->normalize_path((string) $queue_item['path']));

        $detail_payload = $this->get_review_detail((string) $source_run['runId'], $source_page_id);
        $this->assertSame((string) $source_run['runId'], (string) $detail_payload['runId']);
        $this->assertSame('Phase 22 Source About', (string) $detail_payload['evidence']['source']['title']);
        $this->assertNotEmpty($detail_payload['recommendations']['fieldRecommendations']);
    }

    public function test_phase_twenty_two_historical_decision_save_and_rerun_do_not_mutate_newer_current_page_files(): void
    {
        $domain = 'phase22-review-write.example';
        $this->domains_to_cleanup[] = $domain;

        $source_run = $this->create_same_url_run($domain, 'Phase 22 Decision Source');
        $source_page_id = $this->get_single_run_page_id((string) $source_run['runId']);

        $newer_run = $this->create_same_url_run($domain, 'Phase 22 Decision Replay');

        $source_context = DBVC_CC_V2_Page_Artifact_Service::get_instance()->resolve_page_context_for_run(
            (string) $source_run['runId'],
            $source_page_id
        );
        $this->assertIsArray($source_context);

        $current_transform_before = $this->read_json_file((string) $source_context['current_artifact_paths']['target_transform']);
        $this->assertIsArray($current_transform_before);
        $this->assertSame((string) $newer_run['runId'], (string) $current_transform_before['journey_id']);
        $current_transform_before_fingerprint = $this->compute_fingerprint($current_transform_before);

        $detail_payload = $this->get_review_detail((string) $source_run['runId'], $source_page_id);
        $decision_response = $this->save_decision(
            (string) $source_run['runId'],
            $source_page_id,
            $detail_payload,
            'Preserve historical decision state for Phase 22.'
        );
        $this->assertSame((string) $source_run['runId'], (string) $decision_response['runId']);

        $source_context_after_decision = DBVC_CC_V2_Page_Artifact_Service::get_instance()->resolve_page_context_for_run(
            (string) $source_run['runId'],
            $source_page_id
        );
        $this->assertIsArray($source_context_after_decision);

        $saved_mapping_decisions = $this->read_json_file((string) $source_context_after_decision['artifact_paths']['mapping_decisions']);
        $this->assertIsArray($saved_mapping_decisions);
        $this->assertSame((string) $source_run['runId'], (string) $saved_mapping_decisions['journey_id']);
        $this->assertStringContainsString(
            '/' . DBVC_CC_V2_Contracts::STORAGE_PAGE_RUNS_SUBDIR . '/' . (string) $source_run['runId'] . '/',
            wp_normalize_path((string) $source_context_after_decision['artifact_paths']['mapping_decisions'])
        );
        $this->assertFileDoesNotExist((string) $source_context_after_decision['current_artifact_paths']['mapping_decisions']);

        $rerun_response = $this->rerun_stage((string) $source_run['runId'], $source_page_id, DBVC_CC_V2_Contracts::AI_STAGE_MAPPING_INDEX);
        $this->assertSame((string) $source_run['runId'], (string) $rerun_response['runId']);
        $rerun_artifacts = isset($rerun_response['page']['artifacts']) && is_array($rerun_response['page']['artifacts'])
            ? $rerun_response['page']['artifacts']
            : [];
        $this->assertNotEmpty($rerun_artifacts);
        $this->assertStringContainsString(
            '/' . DBVC_CC_V2_Contracts::STORAGE_PAGE_RUNS_SUBDIR . '/' . (string) $source_run['runId'] . '/',
            wp_normalize_path((string) $rerun_artifacts[0])
        );

        $current_transform_after = $this->read_json_file((string) $source_context_after_decision['current_artifact_paths']['target_transform']);
        $this->assertIsArray($current_transform_after);
        $this->assertSame((string) $newer_run['runId'], (string) $current_transform_after['journey_id']);
        $this->assertSame($current_transform_before_fingerprint, $this->compute_fingerprint($current_transform_after));
    }

    public function test_phase_twenty_two_historical_decision_save_recreates_missing_run_scoped_directories(): void
    {
        $domain = 'phase22-review-write-recreate.example';
        $this->domains_to_cleanup[] = $domain;

        $source_run = $this->create_same_url_run($domain, 'Phase 22 Decision Preserve Source');
        $source_page_id = $this->get_single_run_page_id((string) $source_run['runId']);

        $this->create_same_url_run($domain, 'Phase 22 Decision Preserve Replay');

        $source_context = DBVC_CC_V2_Page_Artifact_Service::get_instance()->resolve_page_context_for_run(
            (string) $source_run['runId'],
            $source_page_id
        );
        $this->assertIsArray($source_context);

        $run_scoped_directory = dirname((string) $source_context['run_artifact_paths']['mapping_decisions']);
        $this->delete_directory($run_scoped_directory);
        $this->assertDirectoryDoesNotExist($run_scoped_directory);

        $detail_payload = $this->get_review_detail((string) $source_run['runId'], $source_page_id);
        $decision_response = $this->save_decision(
            (string) $source_run['runId'],
            $source_page_id,
            $detail_payload,
            'Recreate missing preserved decision directory for Phase 22.'
        );
        $this->assertSame((string) $source_run['runId'], (string) $decision_response['runId']);

        $source_context_after_decision = DBVC_CC_V2_Page_Artifact_Service::get_instance()->resolve_page_context_for_run(
            (string) $source_run['runId'],
            $source_page_id
        );
        $this->assertIsArray($source_context_after_decision);
        $this->assertDirectoryExists($run_scoped_directory);
        $this->assertSame(
            wp_normalize_path((string) $source_context_after_decision['run_artifact_paths']['mapping_decisions']),
            wp_normalize_path((string) $source_context_after_decision['artifact_paths']['mapping_decisions'])
        );

        $saved_mapping_decisions = $this->read_json_file((string) $source_context_after_decision['artifact_paths']['mapping_decisions']);
        $this->assertIsArray($saved_mapping_decisions);
        $this->assertSame((string) $source_run['runId'], (string) $saved_mapping_decisions['journey_id']);
        $this->assertSame(
            'Recreate missing preserved decision directory for Phase 22.',
            (string) ($saved_mapping_decisions['target_object_decision']['reviewer_note'] ?? '')
        );
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

    private function create_same_url_run(string $domain, string $title): array
    {
        $this->set_http_stub_variant(
            $domain,
            [
                '/about/' => $title,
            ]
        );

        return $this->create_run(
            $domain,
            [
                'maxUrls' => 1,
            ]
        );
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

    private function get_single_run_page_id(string $run_id): string
    {
        $inventory = DBVC_CC_V2_URL_Inventory_Service::get_instance()->get_inventory_for_run($run_id);
        $this->assertIsArray($inventory);

        $rows = isset($inventory['urls']) && is_array($inventory['urls']) ? $inventory['urls'] : [];
        $this->assertCount(1, $rows);

        return (string) $rows[0]['page_id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function get_exceptions(string $run_id): array
    {
        $request = new WP_REST_Request('GET', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/exceptions');
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        return $response->get_data();
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
     * @param array<string, mixed> $detail_payload
     * @return array<string, mixed>
     */
    private function save_decision(string $run_id, string $page_id, array $detail_payload, string $reviewer_note): array
    {
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
        $this->assertNotEmpty($approved_field_ids);

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

        $request = new WP_REST_Request('POST', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/urls/' . $page_id . '/decision');
        $request->set_body_params(
            [
                'reviewerNote' => $reviewer_note,
                'approvedRecommendationIds' => $approved_field_ids,
                'approvedMediaIds' => $approved_media_ids,
            ]
        );

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        return $response->get_data();
    }

    /**
     * @return array<string, mixed>
     */
    private function rerun_stage(string $run_id, string $page_id, string $stage): array
    {
        $request = new WP_REST_Request('POST', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/urls/' . $page_id . '/rerun');
        $request->set_body_params(
            [
                'stage' => $stage,
            ]
        );

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        return $response->get_data();
    }

    /**
     * @param array<string, mixed> $queue_payload
     * @return array<string, mixed>
     */
    private function find_queue_item(array $queue_payload, string $page_id): array
    {
        $items = isset($queue_payload['items']) && is_array($queue_payload['items']) ? $queue_payload['items'] : [];
        foreach ($items as $item) {
            if (is_array($item) && (string) ($item['pageId'] ?? '') === $page_id) {
                return $item;
            }
        }

        return [];
    }

    private function normalize_path(string $path): string
    {
        return $path !== '/' && substr($path, -1) === '/'
            ? rtrim($path, '/')
            : $path;
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

    /**
     * @param mixed $value
     */
    private function compute_fingerprint($value): string
    {
        return hash('sha256', (string) wp_json_encode($value, JSON_UNESCAPED_SLASHES));
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
