<?php

/**
 * OPTION_FLAG_AI_MAPPING has to mean the AI surface does no work, not merely that one module
 * skipped its registration call.
 *
 * Registration alone was never the boundary. The AI refresh entry point is an AJAX action owned by
 * the *collector* module, which is gated on a different flag, and it reached the AI service
 * directly -- so with AI mapping off a finished crawl still wrote 'queued' status files and
 * scheduled CRON_HOOK_AI_PROCESS_JOB events that no listener would ever answer. These tests pin
 * both halves: the flag refuses the work, and the reactivation path does not quietly re-attach the
 * surface the flag turned off.
 */
class ContentMigrationAiMappingFlagBoundaryTest extends WP_UnitTestCase
{
    private const DOMAIN = 'ai-flag-boundary.test';
    private const PAGE_SLUG = 'about';

    public function set_up(): void
    {
        parent::set_up();

        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        update_option(DBVC_CC_Contracts::OPTION_FLAG_AI_MAPPING, '1');

        // Hook registration is in-memory singleton state that WP_UnitTestCase's transaction
        // rollback does not restore, so an earlier test that deactivated the runtime would
        // otherwise decide what this one sees. Start from a registered V1 runtime every time.
        update_option(DBVC_CC_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_Contracts::OPTION_GLOBAL_KILL_SWITCH, '0');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();

        wp_unschedule_hook(DBVC_CC_Contracts::CRON_HOOK_AI_PROCESS_JOB);
        $this->create_page_artifact();
    }

    public function tear_down(): void
    {
        wp_unschedule_hook(DBVC_CC_Contracts::CRON_HOOK_AI_PROCESS_JOB);
        $this->delete_domain_artifacts();

        update_option(DBVC_CC_Contracts::OPTION_FLAG_AI_MAPPING, '1');
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();

        parent::tear_down();
    }

    /**
     * The control case. Without it, a refusal below could just as well be the fixture failing to
     * produce a queueable page.
     */
    public function test_the_domain_refresh_entry_point_schedules_a_job_while_the_flag_is_on(): void
    {
        $result = DBVC_CC_AI_Service::get_instance()->dbvc_cc_queue_domain_refresh(
            self::DOMAIN,
            'crawl_domain_refresh',
            0,
            false
        );

        $this->assertNotWPError($result);
        $this->assertSame(1, (int) $result['total_jobs']);
        $this->assertSame(1, $this->scheduled_ai_event_count());
    }

    public function test_the_domain_refresh_entry_point_schedules_nothing_while_the_flag_is_off(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_AI_MAPPING, '0');

        $result = DBVC_CC_AI_Service::get_instance()->dbvc_cc_queue_domain_refresh(
            self::DOMAIN,
            'crawl_domain_refresh',
            0,
            false
        );

        $this->assertWPError($result);
        $this->assertSame('dbvc_cc_ai_disabled', $result->get_error_code());
        $this->assertSame(
            0,
            $this->scheduled_ai_event_count(),
            'A disabled AI surface must not leave an event on a hook it refuses to listen on.'
        );
        $this->assertFileDoesNotExist(
            $this->page_dir() . '/' . self::PAGE_SLUG . '.analysis.status.json',
            'No status file should be written, so nothing can sit at "queued" forever.'
        );
    }

    /**
     * The AJAX action is what the crawler JS calls when a crawl finishes, so the message the
     * operator actually sees comes from the WP_Error the service returns.
     */
    public function test_the_refusal_carries_a_message_that_names_the_flag(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_AI_MAPPING, '0');

        $result = DBVC_CC_AI_Service::get_instance()->dbvc_cc_queue_domain_refresh(self::DOMAIN);

        $this->assertWPError($result);
        $this->assertStringContainsString('dbvc_cc_flag_ai_mapping', $result->get_error_message());
    }

    public function test_single_job_queueing_and_processing_both_refuse_while_the_flag_is_off(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_AI_MAPPING, '0');

        $queued = DBVC_CC_AI_Service::get_instance()->queue_job(self::DOMAIN, self::PAGE_SLUG, 'manual_rerun', 0, false, false);
        $this->assertWPError($queued);
        $this->assertSame('dbvc_cc_ai_disabled', $queued->get_error_code());

        // process_job is the belt behind the registration gate: an event already in the cron table
        // when the flag went off must not do AI work, whoever attached the handler.
        $processed = DBVC_CC_AI_Service::get_instance()->process_job(self::DOMAIN, self::PAGE_SLUG, 'dbvc_cc_ai_test');
        $this->assertWPError($processed);
        $this->assertSame('dbvc_cc_ai_disabled', $processed->get_error_code());
    }

    /**
     * Turning the flag off is the on->off edge nothing in the add-on used to react to.
     */
    public function test_turning_the_flag_off_retracts_jobs_that_were_already_scheduled(): void
    {
        DBVC_CC_AI_Service::get_instance()->register_hooks();

        $queued = DBVC_CC_AI_Service::get_instance()->dbvc_cc_queue_domain_refresh(self::DOMAIN);
        $this->assertNotWPError($queued);
        $this->assertSame(1, $this->scheduled_ai_event_count());

        update_option(DBVC_CC_Contracts::OPTION_FLAG_AI_MAPPING, '0');

        $this->assertSame(
            0,
            $this->scheduled_ai_event_count(),
            'Pending AI events should be retracted when the feature that answers them is switched off.'
        );
    }

    /**
     * Written as add_option rather than update_option: on a site whose flag row was never stored,
     * the first write to it fires add_option_*, not update_option_*.
     */
    public function test_the_retraction_also_covers_a_flag_row_that_did_not_exist_yet(): void
    {
        DBVC_CC_AI_Service::get_instance()->register_hooks();

        $queued = DBVC_CC_AI_Service::get_instance()->dbvc_cc_queue_domain_refresh(self::DOMAIN);
        $this->assertNotWPError($queued);
        $this->assertSame(1, $this->scheduled_ai_event_count());

        delete_option(DBVC_CC_Contracts::OPTION_FLAG_AI_MAPPING);
        add_option(DBVC_CC_Contracts::OPTION_FLAG_AI_MAPPING, '0');

        $this->assertSame(0, $this->scheduled_ai_event_count());
    }

    public function test_the_health_rollup_reports_disabled_instead_of_a_pass_in_progress(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_AI_MAPPING, '0');

        $health = DBVC_CC_AI_Service::get_instance()->dbvc_cc_get_domain_ai_health(self::DOMAIN);

        $this->assertSame('disabled', $health['status']);
        // The Explorer renders warning_badge as an "AI Warning: Refresh Domain AI" button, and a
        // refresh is what the flag refuses, so a disabled feature raises no badge.
        $this->assertFalse($health['warning_badge']);
        $this->assertSame('', $health['warning_message']);
    }

    /**
     * Reads stay allowed with the flag off. The Explorer and Workbench domain lists call the health
     * rollup unconditionally, and that has to keep working rather than fatal or write anything.
     */
    public function test_the_ungated_health_readers_still_work_with_the_flag_off(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_AI_MAPPING, '0');

        $domains = DBVC_CC_Explorer_Service::get_instance()->get_domains();
        $this->assertIsArray($domains);

        $workbench_domains = DBVC_CC_Workbench_Service::get_instance()->dbvc_cc_get_domains();
        $this->assertIsArray($workbench_domains);

        foreach (array_merge($domains, $workbench_domains) as $row) {
            if (isset($row['key']) && $row['key'] === self::DOMAIN) {
                $this->assertSame('disabled', $row['dbvc_cc_ai_health']['status']);
            }
        }

        $this->assertSame(0, $this->scheduled_ai_event_count());
    }

    /**
     * restore_v1_runtime_hooks() used to re-attach the AI surface with no flag check, and the one
     * path that reaches it -- a runtime refresh after the flags are written -- is also the only
     * path in the add-on that can turn a flag off at runtime.
     */
    public function test_reactivating_the_runtime_in_one_request_does_not_re_attach_a_disabled_ai_surface(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_AI_MAPPING, '0');

        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        // Second pass: the first one marks the runtime as bootstrapped, which is what sends the
        // next activation down restore_v1_runtime_hooks() instead of the module registrations.
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();

        $this->assertFalse(
            has_action(DBVC_CC_Contracts::CRON_HOOK_AI_PROCESS_JOB, [DBVC_CC_AI_Service::get_instance(), 'process_job']),
            'The AI job handler must not be re-attached while OPTION_FLAG_AI_MAPPING is off.'
        );
    }

    public function test_reactivating_the_runtime_re_attaches_the_ai_surface_while_the_flag_is_on(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_AI_MAPPING, '1');

        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();

        $this->assertNotFalse(
            has_action(DBVC_CC_Contracts::CRON_HOOK_AI_PROCESS_JOB, [DBVC_CC_AI_Service::get_instance(), 'process_job'])
        );
    }

    /**
     * Counts queued AI events directly, because wp_next_scheduled()/wp_clear_scheduled_hook()
     * match on an exact argument list and every AI event carries [domain, path, job_id].
     *
     * @return int
     */
    private function scheduled_ai_event_count(): int
    {
        $crons = _get_cron_array();
        if (! is_array($crons)) {
            return 0;
        }

        $count = 0;
        foreach ($crons as $events) {
            if (isset($events[DBVC_CC_Contracts::CRON_HOOK_AI_PROCESS_JOB])) {
                $count += count($events[DBVC_CC_Contracts::CRON_HOOK_AI_PROCESS_JOB]);
            }
        }

        return $count;
    }

    /**
     * @return void
     */
    private function create_page_artifact(): void
    {
        $page_dir = $this->page_dir();
        wp_mkdir_p($page_dir);

        file_put_contents(
            $page_dir . '/' . self::PAGE_SLUG . '.json',
            (string) wp_json_encode([
                'schema_version' => '1.0',
                'source_url' => 'https://' . self::DOMAIN . '/' . self::PAGE_SLUG . '/',
                'title' => 'About',
                'content_html' => '<h1>About</h1><p>Flag boundary fixture.</p>',
            ])
        );
    }

    /**
     * @return string
     */
    private function page_dir(): string
    {
        return trailingslashit(DBVC_CC_Artifact_Manager::get_storage_base_dir()) . self::DOMAIN . '/' . self::PAGE_SLUG;
    }

    /**
     * @return void
     */
    private function delete_domain_artifacts(): void
    {
        $base = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        if ($base === '') {
            return;
        }

        $this->delete_directory(trailingslashit($base) . self::DOMAIN);
    }

    /**
     * @param string $directory
     * @return void
     */
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

            $path = trailingslashit($directory) . $item;
            if (is_dir($path)) {
                $this->delete_directory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
