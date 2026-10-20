<?php

class ContentCollectorV2Phase27Test extends WP_UnitTestCase
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

        DBVC_CC_V2_Package_Execution_QA_Fixture_Service::get_instance()->clear_fixture();

        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);

        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();

        parent::tear_down();
    }

    public function test_phase_twenty_seven_package_execution_fixture_route_seeds_and_clears_selected_package_observability(): void
    {
        $domain = 'phase27-package-execution.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $created = $this->create_run(
            $domain,
            [
                'maxUrls' => 1,
            ]
        );
        $built = $this->build_package((string) $created['runId']);
        $package_id = isset($built['selectedPackageId']) ? (string) $built['selectedPackageId'] : '';

        $this->assertNotSame('', $package_id);
        $this->assertSame([], isset($built['selectedPackage']['importHistory']) ? $built['selectedPackage']['importHistory'] : []);
        $this->assertSame('not_started', (string) ($built['selectedPackage']['workflowState']['latestExecute']['status'] ?? 'not_started'));

        $seed_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/package/qa/execution-observability-fixture'
        );
        $seed_request->set_body_params(
            [
                'enabled' => true,
                'packageId' => $package_id,
            ]
        );

        $seed_response = rest_get_server()->dispatch($seed_request);
        $this->assertSame(200, $seed_response->get_status());

        $seed_payload = $seed_response->get_data();
        $this->assertTrue((bool) $seed_payload['enabled']);
        $this->assertSame((string) $created['runId'], (string) $seed_payload['runId']);
        $this->assertSame($package_id, (string) $seed_payload['packageId']);
        $this->assertSame('completed', (string) $seed_payload['latestExecute']['status']);

        $surface = $this->get_package_surface((string) $created['runId'], $package_id);
        $this->assertSame((string) $created['runId'], (string) $surface['runId']);
        $this->assertSame($package_id, (string) $surface['selectedPackageId']);
        $this->assertSame('completed', (string) $surface['selectedPackage']['workflowState']['latestExecute']['status']);
        $this->assertCount(1, isset($surface['selectedPackage']['importHistory']) ? $surface['selectedPackage']['importHistory'] : []);
        $this->assertSame(1, (int) $surface['selectedPackage']['importHistory'][0]['summary']['importRuns']);
        $this->assertSame('available', (string) $surface['selectedPackage']['importHistory'][0]['importRuns'][0]['rollbackStatus']);

        $history_item = $this->find_history_item(isset($surface['history']) ? $surface['history'] : [], $package_id);
        $this->assertNotEmpty($history_item);
        $this->assertSame('completed', (string) $history_item['workflowSummary']['executeStatus']);
        $this->assertSame(1, (int) $history_item['workflowSummary']['importRunCount']);

        $clear_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/package/qa/execution-observability-fixture'
        );
        $clear_request->set_body_params(
            [
                'enabled' => false,
                'packageId' => $package_id,
            ]
        );

        $clear_response = rest_get_server()->dispatch($clear_request);
        $this->assertSame(200, $clear_response->get_status());
        $this->assertFalse((bool) $clear_response->get_data()['enabled']);

        $cleared_surface = $this->get_package_surface((string) $created['runId'], $package_id);
        $this->assertSame([], isset($cleared_surface['selectedPackage']['importHistory']) ? $cleared_surface['selectedPackage']['importHistory'] : []);
        $this->assertSame('not_started', (string) ($cleared_surface['selectedPackage']['workflowState']['latestExecute']['status'] ?? 'not_started'));
    }

    public function test_phase_twenty_seven_package_execution_fixture_route_is_rejected_when_helper_disabled(): void
    {
        $domain = 'phase27-package-execution-disabled.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $created = $this->create_run($domain);
        $built = $this->build_package((string) $created['runId']);
        $package_id = isset($built['selectedPackageId']) ? (string) $built['selectedPackageId'] : '';

        add_filter('dbvc_cc_v2_enable_recovery_qa_fixture', '__return_false');

        $seed_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $created['runId'] . '/package/qa/execution-observability-fixture'
        );
        $seed_request->set_body_params(
            [
                'enabled' => true,
                'packageId' => $package_id,
            ]
        );

        $seed_response = rest_get_server()->dispatch($seed_request);
        $this->assertSame(403, $seed_response->get_status());
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

    private function build_package(string $run_id): array
    {
        $this->reset_rest_server();
        do_action('rest_api_init');

        $request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/package'
        );
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        return $response->get_data();
    }

    private function get_package_surface(string $run_id, string $package_id): array
    {
        $this->reset_rest_server();
        do_action('rest_api_init');

        $request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/package'
        );
        $request->set_query_params(
            [
                'packageId' => $package_id,
            ]
        );

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        return $response->get_data();
    }

    /**
     * @param mixed $items
     * @return array<string, mixed>
     */
    private function find_history_item($items, string $package_id): array
    {
        if (! is_array($items)) {
            return [];
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if ((string) ($item['package_id'] ?? '') === $package_id) {
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
                        'body' => '<?xml version="1.0" encoding="UTF-8"?><urlset><url><loc>https://' . $domain . '/</loc></url></urlset>',
                    ],
                    'https://' . $domain . '/' => [
                        'body' => '<html><head><title>Phase 27 Home</title><meta name="description" content="Phase 27 home description" /></head><body><main><h1>Phase 27 Home</h1><p>Deterministic package execution fixture home page.</p></main></body></html>',
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

        $wp_rest_server = null;
        rest_get_server();
    }
}
