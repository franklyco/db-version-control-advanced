<?php

class ContentCollectorV2Phase2Test extends WP_UnitTestCase
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

    public function test_schema_sync_builds_domain_journey_and_v2_schema_artifacts(): void
    {
        $domain = 'phase2-source.example';
        $this->domains_to_cleanup[] = $domain;

        $result = DBVC_CC_V2_Schema_Sync_Service::get_instance()->sync_domain(
            $domain,
            [
                'journey_id' => 'journey-phase2-sync',
                'actor' => 'test',
                'trigger' => 'phpunit',
                'force_rebuild' => true,
            ]
        );

        $this->assertNotWPError($result);
        $this->assertSame('journey-phase2-sync', $result['runId']);
        $this->assertSame($domain, $result['domain']);

        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        $this->assertNotWPError($context);
        $this->assertFileExists($context['journey_log_file']);
        $this->assertFileExists($context['journey_latest_file']);
        $this->assertFileExists($context['journey_stage_summary_file']);
        $this->assertFileExists($context['target_object_inventory_file']);
        $this->assertFileExists($context['target_field_catalog_file']);

        $latest = json_decode((string) file_get_contents($context['journey_latest_file']), true);
        $stage_summary = json_decode((string) file_get_contents($context['journey_stage_summary_file']), true);
        $inventory = json_decode((string) file_get_contents($context['target_object_inventory_file']), true);
        $catalog = json_decode((string) file_get_contents($context['target_field_catalog_file']), true);

        $this->assertSame('domain-journey.latest.v1', $latest['artifact_type']);
        $this->assertSame('journey-phase2-sync', $latest['journey_id']);
        $this->assertSame($result['schema_fingerprint'], $latest['latest_schema_fingerprint']);
        $this->assertContains('_schema/dbvc_cc_target_object_inventory.v1.json', $latest['artifact_inventory']);
        $this->assertContains('_schema/dbvc_cc_target_field_catalog.v2.json', $latest['artifact_inventory']);

        $this->assertSame('domain-stage-summary.v1', $stage_summary['artifact_type']);
        $this->assertSame('journey-phase2-sync', $stage_summary['journey_id']);
        $this->assertContains(
            DBVC_CC_V2_Contracts::STEP_TARGET_SCHEMA_SYNC_COMPLETED,
            wp_list_pluck($stage_summary['stages'], 'step_key')
        );

        $this->assertSame('target-object-inventory.v1', $inventory['artifact_type']);
        $this->assertSame('target-field-catalog.v2', $catalog['artifact_type']);
        $this->assertSame($inventory['inventory_fingerprint'], $catalog['inventory_fingerprint']);
        $this->assertNotEmpty($catalog['catalog_fingerprint']);
    }

    public function test_run_routes_are_registered_only_for_v2_runtime(): void
    {
        $this->reset_rest_server();
        do_action('rest_api_init');
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey('/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs', $routes);
        $this->assertArrayHasKey('/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/(?P<run_id>[\\w-]+)/readiness', $routes);

        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();

        $this->reset_rest_server();
        do_action('rest_api_init');
        $routes = rest_get_server()->get_routes();

        $this->assertArrayNotHasKey('/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs', $routes);
        $this->assertArrayNotHasKey('/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs/(?P<run_id>[\\w-]+)/readiness', $routes);
    }

    public function test_domain_journey_state_stays_isolated_per_domain(): void
    {
        $alpha_domain = 'alpha-phase2.example';
        $beta_domain = 'beta-phase2.example';
        $this->domains_to_cleanup[] = $alpha_domain;
        $this->domains_to_cleanup[] = $beta_domain;

        $alpha = DBVC_CC_V2_Schema_Sync_Service::get_instance()->sync_domain(
            $alpha_domain,
            [
                'journey_id' => 'journey-alpha-phase2',
                'actor' => 'test',
                'trigger' => 'phpunit',
            ]
        );
        $beta = DBVC_CC_V2_Schema_Sync_Service::get_instance()->sync_domain(
            $beta_domain,
            [
                'journey_id' => 'journey-beta-phase2',
                'actor' => 'test',
                'trigger' => 'phpunit',
            ]
        );

        $this->assertNotWPError($alpha);
        $this->assertNotWPError($beta);

        $alpha_latest = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_latest_state($alpha_domain);
        $beta_latest = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_latest_state($beta_domain);

        $this->assertNotWPError($alpha_latest);
        $this->assertNotWPError($beta_latest);

        $this->assertSame($alpha_domain, $alpha_latest['domain']);
        $this->assertSame($beta_domain, $beta_latest['domain']);
        $this->assertSame('journey-alpha-phase2', $alpha_latest['journey_id']);
        $this->assertSame('journey-beta-phase2', $beta_latest['journey_id']);
        $this->assertSame($alpha_domain, DBVC_CC_V2_Domain_Journey_Service::get_instance()->find_domain_by_journey_id('journey-alpha-phase2'));
        $this->assertSame($beta_domain, DBVC_CC_V2_Domain_Journey_Service::get_instance()->find_domain_by_journey_id('journey-beta-phase2'));
        $this->assertNotSame($alpha_latest['journey_id'], $beta_latest['journey_id']);
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
