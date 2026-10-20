<?php

class ContentCollectorV2Phase15Test extends WP_UnitTestCase
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

    public function test_phase_fifteen_package_surface_exposes_operator_artifact_actions(): void
    {
        $domain = 'phase15-package-actions.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $created = $this->create_run($domain);
        $payload = $this->build_package($created['runId']);
        $selected_package = isset($payload['selectedPackage']) && is_array($payload['selectedPackage'])
            ? $payload['selectedPackage']
            : [];
        $artifact_actions = isset($selected_package['artifactActions']) && is_array($selected_package['artifactActions'])
            ? $selected_package['artifactActions']
            : [];

        $this->assertSame((string) $payload['selectedPackageId'], (string) $selected_package['packageId']);
        $this->assertArrayHasKey('manifest', $artifact_actions);
        $this->assertArrayHasKey('summary', $artifact_actions);
        $this->assertArrayHasKey('qa', $artifact_actions);
        $this->assertArrayHasKey('records', $artifact_actions);
        $this->assertArrayHasKey('media', $artifact_actions);
        $this->assertArrayHasKey('zip', $artifact_actions);

        $this->assertTrue((bool) $artifact_actions['manifest']['canInspect']);
        $this->assertTrue((bool) $artifact_actions['manifest']['canDownload']);
        $this->assertFalse((bool) $artifact_actions['zip']['canInspect']);
        $this->assertTrue((bool) $artifact_actions['zip']['canDownload']);
        $this->assertStringContainsString(
            'admin-post.php?action=dbvc_cc_v2_package_artifact_download',
            (string) $artifact_actions['zip']['downloadUrl']
        );
        $this->assertStringContainsString(
            'artifact=manifest',
            (string) $artifact_actions['manifest']['downloadUrl']
        );
    }

    public function test_phase_fifteen_package_artifact_service_resolves_download_targets(): void
    {
        $domain = 'phase15-downloads.example';
        $this->domains_to_cleanup[] = $domain;
        $this->register_http_stubs($domain);

        $created = $this->create_run($domain);
        $payload = $this->build_package($created['runId']);
        $package_id = (string) ($payload['selectedPackageId'] ?? '');

        $service = DBVC_CC_V2_Package_Artifact_Service::get_instance();
        $manifest = $service->resolve_artifact_download($created['runId'], $package_id, 'manifest');
        $zip = $service->resolve_artifact_download($created['runId'], $package_id, 'zip');

        $this->assertNotWPError($manifest);
        $this->assertNotWPError($zip);
        $this->assertSame('package-manifest.v1.json', $manifest['fileName']);
        $this->assertSame('application/json', $manifest['contentType']);
        $this->assertFileExists($manifest['path']);
        $this->assertSame('import-package.v1.zip', $zip['fileName']);
        $this->assertSame('application/zip', $zip['contentType']);
        $this->assertFileExists($zip['path']);
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

    private function build_package(string $run_id): array
    {
        $package_request = new WP_REST_Request(
            'POST',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/package'
        );
        $package_response = rest_get_server()->dispatch($package_request);
        $this->assertSame(200, $package_response->get_status());

        return $package_response->get_data();
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
                        'body' => '<html><head><title>Phase 15 Home</title><meta name="description" content="Phase 15 home description" /></head><body><main><h1>Phase 15 Home</h1><p>Package artifact coverage body copy.</p></main></body></html>',
                    ],
                    'https://' . $domain . '/about/' => [
                        'body' => '<html><head><title>Phase 15 About</title><meta name="description" content="Phase 15 about description" /></head><body><main><h1>Phase 15 About</h1><p>Secondary coverage page.</p></main></body></html>',
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
