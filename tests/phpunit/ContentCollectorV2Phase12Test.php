<?php

class ContentCollectorV2Phase12Test extends WP_UnitTestCase
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

        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();
    }

    public function tear_down(): void
    {
        foreach ($this->domains_to_cleanup as $domain) {
            $this->delete_domain_artifacts($domain);
        }

        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);

        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();

        parent::tear_down();
    }

    public function test_overview_route_returns_recent_activity_for_selected_run(): void
    {
        $domain = 'phase12-activity.example';
        $run_id = 'journey-phase12-activity';
        $this->domains_to_cleanup[] = $domain;
        $this->seed_run_activity($domain, $run_id);

        $this->reset_rest_server();
        do_action('rest_api_init');

        $request = new WP_REST_Request(
            'GET',
            '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/' . $run_id . '/overview'
        );
        $response = rest_get_server()->dispatch($request);

        $this->assertSame(200, $response->get_status());

        $payload = $response->get_data();
        $this->assertSame($run_id, $payload['runId']);
        $this->assertArrayHasKey('recentActivity', $payload);
        $this->assertCount(4, $payload['recentActivity']);
        $this->assertSame(DBVC_CC_V2_Contracts::STEP_PACKAGE_BUILT, $payload['recentActivity'][0]['stepKey']);
        $this->assertSame($run_id, $payload['recentActivity'][0]['runId']);
        $this->assertNotEmpty($payload['recentActivity'][0]['activityId']);
        $this->assertSame('completed', $payload['recentActivity'][0]['status']);
        $this->assertSame('page-home', $payload['recentActivity'][1]['pageId']);
    }

    public function test_recent_activity_service_filters_events_to_selected_run(): void
    {
        $domain = 'phase12-filter.example';
        $run_id = 'journey-phase12-filter';
        $this->domains_to_cleanup[] = $domain;
        $this->seed_run_activity($domain, $run_id);

        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        $this->assertNotWPError($context);

        $foreign_event = [
            'journey_id' => 'journey-phase12-foreign',
            'pipeline_version' => DBVC_CC_V2_Contracts::PIPELINE_VERSION,
            'domain' => $domain,
            'step_key' => DBVC_CC_V2_Contracts::STEP_PACKAGE_READY,
            'step_name' => 'Package Ready',
            'status' => 'completed',
            'started_at' => '2026-03-19T15:00:00Z',
            'finished_at' => '2026-03-19T15:00:02Z',
            'duration_ms' => 2000,
            'actor' => 'test',
            'trigger' => 'phpunit',
            'input_artifacts' => [],
            'output_artifacts' => ['_packages/foreign-ready.json'],
            'source_fingerprint' => '',
            'schema_fingerprint' => '',
            'message' => 'Foreign run activity should be ignored.',
            'warning_codes' => [],
            'error_code' => '',
            'metadata' => [],
            'exception_state' => '',
            'rerun_parent_event_id' => '',
            'package_id' => 'pkg-foreign-001',
            'page_id' => '',
            'path' => '',
            'source_url' => '',
            'override_scope' => '',
            'override_target' => '',
        ];

        $line = wp_json_encode($foreign_event, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($line);
        $this->assertNotFalse(file_put_contents($context['journey_log_file'], $line . PHP_EOL, FILE_APPEND | LOCK_EX));

        $activity = DBVC_CC_V2_Run_Activity_Service::get_instance()->get_recent_activity($domain, $run_id, 10);
        $this->assertNotWPError($activity);
        $this->assertCount(4, $activity);
        $this->assertSame(DBVC_CC_V2_Contracts::STEP_PACKAGE_BUILT, $activity[0]['stepKey']);

        foreach ($activity as $item) {
            $this->assertSame($run_id, $item['runId']);
            $this->assertNotSame('Foreign run activity should be ignored.', $item['message']);
        }
    }

    private function seed_run_activity(string $domain, string $run_id): void
    {
        $events = [
            [
                'journey_id' => $run_id,
                'step_key' => DBVC_CC_V2_Contracts::STEP_DOMAIN_JOURNEY_STARTED,
                'step_name' => 'Domain Journey Started',
                'status' => 'completed',
                'started_at' => '2026-03-19T14:00:00Z',
                'finished_at' => '2026-03-19T14:00:01Z',
                'duration_ms' => 1000,
                'actor' => 'test',
                'trigger' => 'phpunit',
                'message' => 'Journey bootstrapped for the selected run.',
            ],
            [
                'journey_id' => $run_id,
                'step_key' => DBVC_CC_V2_Contracts::STEP_URL_DISCOVERY_COMPLETED,
                'step_name' => 'URL Discovery Completed',
                'status' => 'completed',
                'started_at' => '2026-03-19T14:00:05Z',
                'finished_at' => '2026-03-19T14:00:07Z',
                'duration_ms' => 2000,
                'actor' => 'test',
                'trigger' => 'phpunit',
                'message' => 'Discovered one in-scope URL.',
                'output_artifacts' => ['_inventory/url-inventory.v1.json'],
            ],
            [
                'journey_id' => $run_id,
                'step_key' => DBVC_CC_V2_Contracts::STEP_PAGE_CAPTURE_COMPLETED,
                'step_name' => 'Page Capture Completed',
                'status' => 'completed',
                'started_at' => '2026-03-19T14:00:10Z',
                'finished_at' => '2026-03-19T14:00:14Z',
                'duration_ms' => 4000,
                'actor' => 'test',
                'trigger' => 'phpunit',
                'message' => 'Captured the home page.',
                'page_id' => 'page-home',
                'path' => '/',
                'source_url' => 'https://' . $domain . '/',
                'input_artifacts' => ['_inventory/url-inventory.v1.json'],
                'output_artifacts' => ['pages/page-home/home.json'],
            ],
            [
                'journey_id' => $run_id,
                'step_key' => DBVC_CC_V2_Contracts::STEP_PACKAGE_BUILT,
                'step_name' => 'Package Built',
                'status' => 'completed',
                'started_at' => '2026-03-19T14:00:20Z',
                'finished_at' => '2026-03-19T14:00:23Z',
                'duration_ms' => 3000,
                'actor' => 'test',
                'trigger' => 'phpunit',
                'message' => 'Built the first package for review.',
                'package_id' => 'pkg_' . $run_id . '_001',
                'input_artifacts' => ['pages/page-home/home.json'],
                'output_artifacts' => ['_packages/pkg_' . $run_id . '_001.json'],
            ],
        ];

        foreach ($events as $event) {
            $appended = DBVC_CC_V2_Domain_Journey_Service::get_instance()->append_event($domain, $event);
            $this->assertNotWPError($appended);
        }
    }

    private function reset_rest_server(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
    }

    private function delete_domain_artifacts(string $domain): void
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($context)) {
            return;
        }

        $domain_dir = $context['domain_dir'];
        $base_dir = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        if (! is_dir($domain_dir) || ! dbvc_cc_path_is_within($domain_dir, $base_dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($domain_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($domain_dir);
    }
}
