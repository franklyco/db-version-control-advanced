<?php

/**
 * OPTION_FLAG_MAPPING_WORKBENCH scopes the Workbench's operator surface, not the mapping services
 * the import pipeline shares. That distinction was previously unstated, which is the whole of D-24:
 * with the flag off the module skips three services, yet the import-plan handoff calls two of them
 * anyway under OPTION_FLAG_MAPPING_CATALOG_BRIDGE -- and one of those calls writes.
 *
 * Both halves are pinned here, because a decision recorded only in a comment is one the next
 * change can quietly reverse in either direction: by gating the shared services and breaking
 * import planning, or by registering the operator surface when the flag is off.
 *
 * Contrast ContentMigrationAiMappingFlagBoundaryTest. OPTION_FLAG_AI_MAPPING genuinely means "this
 * surface does no work", because every caller of DBVC_CC_AI_Service was a way of starting AI jobs
 * rather than a consumer of artifacts already on disk.
 */
class ContentMigrationWorkbenchFlagScopeTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        update_option(DBVC_CC_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_Contracts::OPTION_GLOBAL_KILL_SWITCH, '0');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_WORKBENCH, '1');
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_CATALOG_BRIDGE, '1');
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
    }

    public function tear_down(): void
    {
        wp_unschedule_hook(DBVC_CC_Contracts::CRON_HOOK_MAPPING_REBUILD_BATCH);

        update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_WORKBENCH, '1');
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_CATALOG_BRIDGE, '1');
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();

        parent::tear_down();
    }

    /**
     * @return bool
     */
    private function rebuild_handler_is_registered(): bool
    {
        return false !== has_action(
            DBVC_CC_Contracts::CRON_HOOK_MAPPING_REBUILD_BATCH,
            [DBVC_CC_Mapping_Rebuild_Service::get_instance(), 'dbvc_cc_process_rebuild_batch_event']
        );
    }

    /**
     * The shared half. The handoff must not fail merely because the review UI is hidden, so it
     * reports the catalog-bridge flag's state, never the workbench flag's.
     */
    public function test_the_import_plan_handoff_still_runs_with_the_workbench_flag_off(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_WORKBENCH, '0');

        $result = DBVC_CC_Import_Plan_Handoff_Service::get_instance()->get_handoff_payload(
            'workbench-flag-scope.test',
            'about',
            false
        );

        // It will fail on missing crawl data in this fixture; what matters is which failure.
        if (is_wp_error($result)) {
            $this->assertNotSame(
                'dbvc_cc_import_plan_handoff_disabled',
                $result->get_error_code(),
                'The handoff refused because the workbench flag is off. That flag scopes the '
                    . 'operator surface; refusing here would break import planning as a side effect '
                    . 'of hiding a page.'
            );
        } else {
            $this->assertIsArray($result);
        }
    }

    /**
     * The same call with the flag that *does* own it refuses, which is what makes the first
     * assertion meaningful rather than merely true.
     */
    public function test_the_import_plan_handoff_refuses_with_the_catalog_bridge_flag_off(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_CATALOG_BRIDGE, '0');

        $result = DBVC_CC_Import_Plan_Handoff_Service::get_instance()->get_handoff_payload(
            'workbench-flag-scope.test',
            'about',
            false
        );

        $this->assertWPError($result);
        $this->assertSame('dbvc_cc_import_plan_handoff_disabled', $result->get_error_code());
    }

    /**
     * The operator-surface half: the queue reachable only from the workbench routes.
     */
    public function test_the_rebuild_queue_handler_follows_the_workbench_flag(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_WORKBENCH, '0');
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->assertFalse(
            $this->rebuild_handler_is_registered(),
            'The mapping-rebuild handler must not be registered while the workbench flag is off.'
        );

        update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_WORKBENCH, '1');
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->assertTrue(
            $this->rebuild_handler_is_registered(),
            'The mapping-rebuild handler must be registered while the workbench flag is on.'
        );
    }

    /**
     * D-25. Constructing the service must not register its flag-gated handler; only the module,
     * which checks the flag, may. This is the pattern #165 moved out of DBVC_CC_AI_Service after
     * two readers in other modules reached it through get_instance().
     */
    public function test_constructing_the_rebuild_service_does_not_register_its_cron_handler(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_WORKBENCH, '0');
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();

        // The singleton already exists by now, so prove the property at its source instead: the
        // constructor body must not register hooks, and register_hooks() must be what does.
        $source = (string) file_get_contents(
            DBVC_PLUGIN_PATH . 'addons/content-migration/mapping-workbench/dbvc-cc-mapping-rebuild-service.php'
        );
        $constructor = (string) preg_replace('/^.*private function __construct\(\)\s*\{(.*?)\}.*$/s', '$1', $source);

        $this->assertStringNotContainsString(
            'add_action',
            $constructor,
            'The constructor registers a hook again. A flag-gated hook must be registered only '
                . 'where the flag is checked, or any get_instance() re-opens the leak.'
        );
        $this->assertTrue(
            method_exists(DBVC_CC_Mapping_Rebuild_Service::class, 'register_hooks'),
            'register_hooks() is the seam the module calls; it must exist.'
        );

        // And constructing it in this flag-off request still leaves nothing attached.
        DBVC_CC_Mapping_Rebuild_Service::get_instance();
        $this->assertFalse($this->rebuild_handler_is_registered());
    }

    /**
     * The one entry point that can queue a rebuild lives behind the flag, so there is no AI-shaped
     * dead queue here: nothing outside the gated surface can schedule work for a handler the flag
     * removed. If a second caller ever appears, this fails and the queueing path needs its own
     * guard, exactly as DBVC_CC_AI_Service got one.
     */
    public function test_queueing_a_rebuild_is_reachable_only_from_the_gated_workbench_surface(): void
    {
        $root = DBVC_PLUGIN_PATH . 'addons/content-migration';
        $command = sprintf(
            'grep -rl %s %s --include=*.php',
            escapeshellarg('dbvc_cc_queue_domain_mapping_rebuild'),
            escapeshellarg($root)
        );
        $files = array_filter(explode("\n", (string) shell_exec($command)));

        $callers = [];
        foreach ($files as $file) {
            $relative = str_replace($root . '/', '', trim($file));
            if ($relative === 'mapping-workbench/dbvc-cc-mapping-rebuild-service.php') {
                continue; // The declaration itself.
            }
            $callers[] = $relative;
        }

        $this->assertSame(
            ['mapping-workbench/dbvc-cc-workbench-rest-controller.php'],
            $callers,
            'A new caller of dbvc_cc_queue_domain_mapping_rebuild() appeared. If it is outside the '
                . 'flag-gated workbench surface, the queueing path now needs its own flag guard, as '
                . 'DBVC_CC_AI_Service::queue_job() has.'
        );
    }
}
