<?php

class ContentCollectorV2Phase18Test extends WP_UnitTestCase
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

    public function test_phase_eighteen_replay_source_run_remains_addressable_after_same_domain_replay(): void
    {
        $domain = 'phase18-replay.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $source_run = $this->create_run($domain);
        $replayed_run = $this->create_run($domain);

        $this->assertNotSame((string) $source_run['runId'], (string) $replayed_run['runId']);

        $list_request = new WP_REST_Request('GET', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs');
        $list_response = rest_get_server()->dispatch($list_request);
        $this->assertSame(200, $list_response->get_status());

        $listed_run_ids = array_map(
            static function ($item) {
                return is_array($item) ? (string) ($item['runId'] ?? '') : '';
            },
            isset($list_response->get_data()['items']) && is_array($list_response->get_data()['items'])
                ? $list_response->get_data()['items']
                : []
        );

        $this->assertContains((string) $replayed_run['runId'], $listed_run_ids);
        $this->assertNotContains((string) $source_run['runId'], $listed_run_ids);

        $source_run_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $source_run['runId']
        );
        $source_run_response = rest_get_server()->dispatch($source_run_request);

        $this->assertSame(200, $source_run_response->get_status());
        $this->assertSame((string) $source_run['runId'], (string) $source_run_response->get_data()['runId']);
        $this->assertSame($domain, (string) $source_run_response->get_data()['domain']);

        $source_overview_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $source_run['runId'] . '/overview'
        );
        $source_overview_response = rest_get_server()->dispatch($source_overview_request);

        $this->assertSame(200, $source_overview_response->get_status());
        $this->assertSame((string) $source_run['runId'], (string) $source_overview_response->get_data()['runId']);
        $this->assertSame(
            (string) $source_run['runId'],
            (string) ($source_overview_response->get_data()['latest']['journey_id'] ?? '')
        );

        $replayed_overview_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $replayed_run['runId'] . '/overview'
        );
        $replayed_overview_response = rest_get_server()->dispatch($replayed_overview_request);

        $this->assertSame(200, $replayed_overview_response->get_status());
        $this->assertSame((string) $replayed_run['runId'], (string) $replayed_overview_response->get_data()['runId']);
        $this->assertSame(
            (string) $replayed_run['runId'],
            (string) ($replayed_overview_response->get_data()['latest']['journey_id'] ?? '')
        );

        $source_readiness_request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $source_run['runId'] . '/readiness'
        );
        $source_readiness_response = rest_get_server()->dispatch($source_readiness_request);

        $this->assertSame(200, $source_readiness_response->get_status());
        $this->assertSame((string) $source_run['runId'], (string) $source_readiness_response->get_data()['runId']);
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
                        'body' => '<html><head><title>Phase 18 Home</title><meta name="description" content="Phase 18 home description" /></head><body><main><h1>Phase 18 Home</h1><p>Replay source run coverage body copy.</p></main></body></html>',
                    ],
                    'https://' . $domain . '/about/' => [
                        'body' => '<html><head><title>Phase 18 About</title><meta name="description" content="Phase 18 about description" /></head><body><main><h1>Phase 18 About</h1><p>Secondary replay coverage page.</p></main></body></html>',
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
