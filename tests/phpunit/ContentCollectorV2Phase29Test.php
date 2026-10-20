<?php

class ContentCollectorV2Phase29Test extends WP_UnitTestCase
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

    public function test_phase_twenty_nine_resolved_conflicts_no_longer_block_package_preflight(): void
    {
        $domain = 'phase29-execute-conflict.example';
        $this->domains_to_cleanup[] = $domain;
        $this->set_http_stub_variant($domain);

        $created = $this->create_run(
            $domain,
            [
                'maxUrls' => 1,
            ]
        );
        $run_id = (string) $created['runId'];
        $page_id = $this->get_single_run_page_id($run_id);

        $detail = $this->get_review_detail($run_id, $page_id);
        $decision_payload = $this->save_fixture_conflict_resolution($run_id, $page_id, $detail);
        $this->assertSame('reviewed', (string) ($decision_payload['summary']['decisionStatus'] ?? ''));

        $built = $this->build_package($run_id);
        $this->assertContains((string) ($built['readinessStatus'] ?? ''), ['ready', 'ready_for_import']);
        $this->assertContains((string) ($built['readiness']['readinessStatus'] ?? ''), ['ready', 'ready_for_import']);

        $page_reports = isset($built['readiness']['pageReports']) && is_array($built['readiness']['pageReports'])
            ? $built['readiness']['pageReports']
            : [];
        $this->assertCount(1, $page_reports);
        $this->assertContains((string) ($page_reports[0]['readinessStatus'] ?? ''), ['ready', 'ready_for_import']);
        $this->assertSame(0, (int) ($page_reports[0]['stats']['conflictCount'] ?? -1));

        $package_id = (string) ($built['selectedPackageId'] ?? '');
        $this->assertNotSame('', $package_id);
        $this->assertContains((string) ($built['selectedPackage']['qaReport']['readiness_status'] ?? ''), ['ready', 'ready_for_import']);

        $preflight = $this->approve_preflight($run_id, $package_id);
        $this->assertSame('approved', (string) ($preflight['status'] ?? ''));
        $this->assertTrue((bool) ($preflight['approvalValid'] ?? false));
        $this->assertTrue((bool) ($preflight['executeReady'] ?? false));
        $this->assertNotEmpty($preflight['approvalTokens'] ?? []);
        $this->assertSame([], $preflight['issues'] ?? []);
    }

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

    private function set_http_stub_variant(string $domain): void
    {
        $this->http_responses = [
            'https://' . $domain . '/sitemap.xml' => $this->build_sitemap_xml($domain, ['/about/']),
            'https://' . $domain . '/about/' => '<html><head><title>Historical Review Fixture About</title><meta name="description" content="Historical review fixture description" /></head><body><main><h1>Historical Review Fixture</h1><p>Deterministic historical review QA fixture content.</p></main></body></html>',
        ];
    }

    /**
     * @param array<int, string> $paths
     */
    private function build_sitemap_xml(string $domain, array $paths): string
    {
        $urls = '';
        foreach ($paths as $path) {
            $urls .= sprintf(
                '<url><loc>https://%1$s%2$s</loc></url>',
                $domain,
                $path
            );
        }

        return '<?xml version="1.0" encoding="UTF-8"?><urlset>' . $urls . '</urlset>';
    }

    private function get_single_run_page_id(string $run_id): string
    {
        $request = new WP_REST_Request('GET', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/exceptions');
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        $items = $response->get_data()['items'] ?? [];
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);

        return (string) ($items[0]['pageId'] ?? '');
    }

    private function get_review_detail(string $run_id, string $page_id): array
    {
        $request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/urls/' . $page_id
        );
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        return $response->get_data();
    }

    private function save_fixture_conflict_resolution(string $run_id, string $page_id, array $detail): array
    {
        $field_recommendations = isset($detail['recommendations']['fieldRecommendations']) && is_array($detail['recommendations']['fieldRecommendations'])
            ? $detail['recommendations']['fieldRecommendations']
            : [];

        $approve_ids = [];
        $reject_ids = [];
        foreach ($field_recommendations as $recommendation) {
            if (! is_array($recommendation) || empty($recommendation['recommendation_id'])) {
                continue;
            }

            $recommendation_id = (string) $recommendation['recommendation_id'];

            if ($recommendation_id === 'rec_003') {
                $reject_ids[] = $recommendation_id;
                continue;
            }

            $approve_ids[] = $recommendation_id;
        }

        $this->assertContains('rec_001', $approve_ids);
        $this->assertSame(['rec_003'], $reject_ids);

        $request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/urls/' . $page_id . '/decision'
        );
        $request->set_body_params(
            [
                'reviewerNote' => 'Resolve deterministic historical conflict for Phase 29.',
                'approvedRecommendationIds' => $approve_ids,
                'rejectedRecommendationIds' => $reject_ids,
            ]
        );

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        return $response->get_data();
    }

    private function build_package(string $run_id): array
    {
        $request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/package'
        );
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        return $response->get_data();
    }

    private function approve_preflight(string $run_id, string $package_id): array
    {
        $request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/preflight-approve'
        );
        $request->set_body_params(
            [
                'packageId' => $package_id,
                'confirmApproval' => true,
            ]
        );

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        return $response->get_data();
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

        $wp_rest_server = null;
        rest_get_server();
    }
}
