<?php

class ContentCollectorV2Phase7Test extends WP_UnitTestCase
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

    public function test_phase_seven_routes_and_readiness_reports_are_available(): void
    {
        $domain = 'phase7-readiness.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $this->reset_rest_server();
        do_action('rest_api_init');
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey('/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/(?P<run_id>[\\w-]+)/package', $routes);

        $created = $this->create_run($domain);

        $readiness_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/readiness'
        );
        $readiness_response = rest_get_server()->dispatch($readiness_request);

        $this->assertSame(200, $readiness_response->get_status());
        $payload = $readiness_response->get_data();

        $this->assertSame($created['runId'], $payload['runId']);
        $this->assertNotEmpty($payload['pageReports']);
        $this->assertArrayHasKey('readinessStatus', $payload);
        $this->assertArrayHasKey('blockingIssues', $payload);

        $home_dir = DBVC_CC_Artifact_Manager::get_page_dir('https://' . $domain . '/');
        $about_dir = DBVC_CC_Artifact_Manager::get_page_dir('https://' . $domain . '/about/');
        $this->assertFileExists($home_dir . '/home.qa-report.v1.json');
        $this->assertFileExists($about_dir . '/about.qa-report.v1.json');

        $home_qa = json_decode((string) file_get_contents($home_dir . '/home.qa-report.v1.json'), true);
        $this->assertSame('qa-report.v1', $home_qa['artifact_type']);
        $this->assertArrayHasKey('readiness_status', $home_qa);
        $this->assertArrayHasKey('quality_score', $home_qa);
    }

    public function test_phase_seven_package_build_generates_artifacts_history_and_zip(): void
    {
        $domain = 'phase7-package.example';
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
                'reviewerNote' => 'Approve homepage content for package assembly.',
                'approvedRecommendationIds' => [$field_recommendation['recommendation_id']],
                'approvedMediaIds' => [$media_recommendation['recommendation_id']],
            ]
        );
        $decision_response = rest_get_server()->dispatch($decision_request);
        $this->assertSame(200, $decision_response->get_status());

        $package_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/package'
        );
        $package_response = rest_get_server()->dispatch($package_request);

        $this->assertSame(200, $package_response->get_status());
        $payload = $package_response->get_data();
        $package_id = (string) ($payload['selectedPackageId'] ?? '');

        $this->assertStringStartsWith('pkg_' . $created['runId'] . '_001', $package_id);
        $this->assertCount(1, $payload['history']);
        $this->assertSame($package_id, $payload['selectedPackage']['manifest']['package_id']);
        $this->assertSame('package-records.v1', $payload['selectedPackage']['records']['artifact_type']);
        $this->assertArrayHasKey('record_count', $payload['selectedPackage']['summary']);

        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        $this->assertNotWPError($context);
        $package_dir = trailingslashit($context['packages_dir']) . $package_id;

        $this->assertFileExists($context['package_builds_file']);
        $this->assertFileExists($package_dir . '/' . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_MANIFEST_FILE);
        $this->assertFileExists($package_dir . '/' . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_RECORDS_FILE);
        $this->assertFileExists($package_dir . '/' . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_MEDIA_MANIFEST_FILE);
        $this->assertFileExists($package_dir . '/' . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_QA_REPORT_FILE);
        $this->assertFileExists($package_dir . '/' . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_SUMMARY_FILE);
        $this->assertFileExists($package_dir . '/' . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_ZIP_FILE);

        $history_payload = json_decode((string) file_get_contents($context['package_builds_file']), true);
        $this->assertSame('package-builds.v1', $history_payload['artifact_type']);
        $this->assertSame($package_id, $history_payload['builds'][0]['package_id']);

        $latest = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_latest_state($domain);
        $this->assertNotWPError($latest);
        $this->assertContains($package_id, $latest['packages_built']);
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
