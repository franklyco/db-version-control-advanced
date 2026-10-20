<?php

class ContentMigrationCliTest extends WP_UnitTestCase
{
    /** @var array<int, string> */
    private $domains_to_cleanup = [];

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        DBVC_CC_V2_Contracts::ensure_defaults();
        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V2);
    }

    public function tear_down(): void
    {
        foreach ($this->domains_to_cleanup as $domain) {
            $this->delete_domain_artifacts($domain);
        }
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);
        parent::tear_down();
    }

    public function test_list_returns_bounded_latest_run_rows_without_writing_artifacts(): void
    {
        $domain = 'cli-list.example';
        $run_id = 'journey-cli-list';
        $this->seed_run($domain, $run_id, false, true);
        $before = $this->tree_fingerprint($domain);

        $result = DBVC_CC_V2_CLI_Run_Inspector::list_runs([
            'domain' => $domain,
            'limit' => 1,
        ]);
        $after = $this->tree_fingerprint($domain);

        $this->assertIsArray($result);
        $this->assertTrue($result['runtime']['addon_enabled']);
        $this->assertTrue($result['runtime']['v2_selected']);
        $this->assertSame(1, $result['total_matching']);
        $this->assertSame(1, $result['returned']);
        $this->assertSame($run_id, $result['items'][0]['run_id']);
        $this->assertSame($domain, $result['items'][0]['domain']);
        $this->assertSame(1, $result['items'][0]['urls_discovered']);
        $this->assertArrayNotHasKey('sitemap_url', $result['items'][0]);
        $this->assertArrayNotHasKey('crawl_overrides', $result['items'][0]);
        $this->assertSame($before, $after);
    }

    public function test_show_returns_structural_overview_without_raw_event_or_url_values(): void
    {
        $domain = 'cli-show.example';
        $run_id = 'journey-cli-show';
        $this->seed_run($domain, $run_id, true, true);
        $before = $this->tree_fingerprint($domain);

        $result = DBVC_CC_V2_CLI_Run_Inspector::show_run($run_id, [
            'domain' => $domain,
            'activity-limit' => 2,
        ]);
        $list = DBVC_CC_V2_CLI_Run_Inspector::list_runs(['domain' => $domain]);
        $after = $this->tree_fingerprint($domain);

        $this->assertIsArray($result);
        $this->assertSame($run_id, $result['run']['run_id']);
        $this->assertSame($domain, $result['run']['domain']);
        $this->assertTrue($result['profile']['sitemap_configured']);
        $this->assertSame(['request_timeout'], $result['profile']['crawl_override_keys']);
        $this->assertSame('stored_run_artifact', $result['inventory']['source']);
        $this->assertSame(1, $result['inventory']['url_count']);
        $this->assertIsArray($list);
        $this->assertSame($result['counts']['urls_discovered'], $list['items'][0]['urls_discovered']);
        $this->assertTrue($result['has_issues']);
        $this->assertGreaterThan(0, $result['issue_count']);
        $this->assertCount(2, $result['recent_activity']);
        $encoded = wp_json_encode($result);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('https://' . $domain . '/sitemap.xml', $encoded);
        $this->assertStringNotContainsString('Sensitive failure detail', $encoded);
        $this->assertStringNotContainsString('_inventory/domain-url-inventory.v1.json', $encoded);
        $this->assertStringNotContainsString('source_url', $encoded);
        $this->assertStringNotContainsString('output_artifacts', $encoded);
        $this->assertSame($before, $after);
    }

    public function test_show_materializes_historical_run_summary_in_memory_only(): void
    {
        $domain = 'cli-history.example';
        $old_run_id = 'journey-cli-history-old';
        $new_run_id = 'journey-cli-history-new';
        $this->seed_run($domain, $old_run_id, false, true);
        $this->seed_run($domain, $new_run_id, false, true);
        $before = $this->tree_fingerprint($domain);

        $list = DBVC_CC_V2_CLI_Run_Inspector::list_runs(['domain' => $domain]);
        $old = DBVC_CC_V2_CLI_Run_Inspector::show_run($old_run_id, ['domain' => $domain]);
        $after = $this->tree_fingerprint($domain);

        $this->assertIsArray($list);
        $this->assertSame($new_run_id, $list['items'][0]['run_id']);
        $this->assertIsArray($old);
        $this->assertSame($old_run_id, $old['run']['run_id']);
        $this->assertSame('materialized_events', $old['inventory']['source']);
        $this->assertSame([], $old['profile']);
        $this->assertGreaterThan(0, $old['stage_summary']['stats']['total_events']);
        $this->assertSame($before, $after);
    }

    public function test_read_only_guards_reject_writer_and_sensitive_expansion_flags(): void
    {
        foreach (['readiness', 'create', 'rerun', 'visibility', 'fixture', 'package', 'execute', 'recover', 'raw', 'download', 'apply'] as $flag) {
            $list = DBVC_CC_V2_CLI_Run_Inspector::list_runs([$flag => true]);
            $show = DBVC_CC_V2_CLI_Run_Inspector::show_run('journey-safe', [$flag => true]);
            $this->assertWPError($list, $flag);
            $this->assertSame('dbvc_cc_cli_read_only', $list->get_error_code(), $flag);
            $this->assertWPError($show, $flag);
            $this->assertSame('dbvc_cc_cli_read_only', $show->get_error_code(), $flag);
        }

        $invalid_format = DBVC_CC_V2_CLI_Run_Inspector::list_runs(['format' => 'xml']);
        $this->assertWPError($invalid_format);
        $this->assertSame('dbvc_cc_cli_format_invalid', $invalid_format->get_error_code());
    }

    public function test_registration_and_synopsis_expose_only_read_only_run_inspection(): void
    {
        $source = file_get_contents(DBVC_PLUGIN_PATH . 'commands/class-content-migration-cli.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("WP_CLI::add_command('dbvc content-migration runs', 'DBVC_WP_CLI_Content_Migration_Runs')", $source);
        $this->assertStringContainsString('@subcommand list', $source);
        $this->assertStringContainsString('public function show($args, $assoc_args)', $source);
        $this->assertStringContainsString('[--fail-on-issues]', $source);
        $this->assertStringNotContainsString('[--readiness]', $source);
        $this->assertStringNotContainsString('[--create]', $source);
        $this->assertStringNotContainsString('[--rerun]', $source);
        $this->assertStringNotContainsString('[--raw]', $source);
        $this->assertStringNotContainsString('get_run_readiness(', $source);
        $this->assertStringNotContainsString('write_reports', $source);
        $this->assertStringNotContainsString('get_domain_context(', $source);
    }

    private function seed_run(string $domain, string $run_id, bool $failed, bool $write_inventory): void
    {
        if (! in_array($domain, $this->domains_to_cleanup, true)) {
            $this->domains_to_cleanup[] = $domain;
        }
        $journey = DBVC_CC_V2_Domain_Journey_Service::get_instance();
        $events = [
            [
                'journey_id' => $run_id,
                'step_key' => DBVC_CC_V2_Contracts::STEP_DOMAIN_JOURNEY_STARTED,
                'step_name' => 'Domain Journey Started',
                'status' => 'completed',
                'started_at' => '2026-08-11T10:00:00Z',
                'finished_at' => '2026-08-11T10:00:01Z',
            ],
            [
                'journey_id' => $run_id,
                'step_key' => DBVC_CC_V2_Contracts::STEP_URL_DISCOVERED,
                'step_name' => 'URL Discovered',
                'status' => 'completed',
                'started_at' => '2026-08-11T10:00:02Z',
                'finished_at' => '2026-08-11T10:00:03Z',
                'page_id' => 'page-home',
                'path' => '/',
                'source_url' => 'https://' . $domain . '/',
                'output_artifacts' => ['_inventory/domain-url-inventory.v1.json'],
            ],
            [
                'journey_id' => $run_id,
                'step_key' => DBVC_CC_V2_Contracts::STEP_URL_SCOPE_DECIDED,
                'step_name' => 'URL Scope Decided',
                'status' => $failed ? 'failed' : 'completed',
                'started_at' => '2026-08-11T10:00:04Z',
                'finished_at' => '2026-08-11T10:00:05Z',
                'page_id' => 'page-home',
                'path' => '/',
                'source_url' => 'https://' . $domain . '/',
                'message' => $failed ? 'Sensitive failure detail' : 'Scope accepted.',
                'error_code' => $failed ? 'test_failure' : '',
                'metadata' => ['scope_status' => 'eligible'],
            ],
        ];
        foreach ($events as $event) {
            $result = $journey->append_event($domain, $event);
            $this->assertNotWPError($result);
        }

        $profile = DBVC_CC_V2_Run_Profile_Service::get_instance()->persist_latest_profile($domain, [
            'run_id' => $run_id,
            'sitemap_url' => 'https://' . $domain . '/sitemap.xml',
            'max_urls' => 10,
            'force_rebuild' => false,
            'crawl_overrides' => ['request_timeout' => 20],
        ]);
        $this->assertNotWPError($profile);

        if ($write_inventory) {
            $context = $journey->get_domain_context($domain);
            $this->assertNotWPError($context);
            $written = DBVC_CC_Artifact_Manager::write_json_file($context['url_inventory_file'], [
                'journey_id' => $run_id,
                'domain' => $domain,
                'stats' => [
                    'raw_url_count' => 1,
                    'url_count' => 1,
                    'eligible_count' => 1,
                    'out_of_scope_count' => 0,
                    'duplicate_count' => 0,
                    'invalid_count' => 0,
                ],
            ]);
            $this->assertTrue($written);
        }
    }

    private function tree_fingerprint(string $domain): string
    {
        $path = trailingslashit(DBVC_CC_Artifact_Manager::get_storage_base_dir()) . $domain;
        if (! is_dir($path)) {
            return '';
        }
        $rows = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $rows[] = wp_normalize_path($file->getPathname()) . '|' . $file->getSize() . '|' . $file->getMTime() . '|' . hash_file('sha256', $file->getPathname());
        }
        sort($rows);
        return hash('sha256', implode("\n", $rows));
    }

    private function delete_domain_artifacts(string $domain): void
    {
        $path = trailingslashit(DBVC_CC_Artifact_Manager::get_storage_base_dir()) . $domain;
        if (! is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
