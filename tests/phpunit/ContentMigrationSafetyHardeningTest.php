<?php

/**
 * Safety and correctness debt in the Content Migration add-on.
 *
 * Each case names a defect the V3 review recorded:
 *
 * - D-4  scope accepted injected `.shtml` spam as crawlable client content
 * - D-11 storage was denied with Apache 2.2 syntax only, which Apache 2.4 may ignore
 * - D-12 the API key lived only in wp_options and was echoed back into the Configure page
 * - D-13 a development-only REST route was published on production
 * - D-14 crawled third-party content had no retention and the plugin had no uninstall handler
 */
class ContentMigrationSafetyHardeningTest extends WP_UnitTestCase
{
    /**
     * @var array<int, string>
     */
    private $directories_to_clean = [];

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
        foreach ($this->directories_to_clean as $directory) {
            $this->remove_directory($directory);
        }
        putenv('DBVC_CC_OPENAI_API_KEY');
        putenv('OPENAI_API_KEY');
        // Targeted, not remove_all_filters(): that strips callbacks the plugin registered too,
        // which leaked into later test classes and failed two unrelated package-import tests.
        foreach (['dbvc_cc_v2_enable_recovery_qa_fixture', 'dbvc_cc_v2_enable_package_execution_qa_fixture'] as $hook) {
            remove_filter($hook, '__return_false');
            remove_filter($hook, '__return_true');
        }
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();

        parent::tear_down();
    }

    // ---------------------------------------------------------------- D-4

    public function test_injected_shtml_spam_is_rejected_as_a_static_template_artifact(): void
    {
        $scope = DBVC_CC_V2_URL_Scope_Service::get_instance();

        foreach ([
            'https://spam-scope.example/caste/return/fe4b15sbhgwivud.shtml',
            'https://spam-scope.example/denomination/abhelp/d07548v1ksv5c5c.shtml',
            'https://spam-scope.example/legacy/page.phtml',
            'https://spam-scope.example/legacy/page.aspx',
        ] as $url) {
            $record = $scope->evaluate_url('spam-scope.example', $url);
            $this->assertSame('out_of_scope', $record['scope_status'], $url);
            $this->assertSame('static_template_artifact', $record['scope_reason'], $url);
        }
    }

    public function test_a_legacy_index_html_page_is_still_eligible(): void
    {
        // The legacy index.html form of a real page is live content on exactly the kind of older
        // site this tool targets. Rejecting .html wholesale would discard pages the client serves.
        $record = DBVC_CC_V2_URL_Scope_Service::get_instance()->evaluate_url(
            'legacy-scope.example',
            'https://legacy-scope.example/dental-team/index.html'
        );

        $this->assertSame('eligible', $record['scope_status']);
        $this->assertSame('same_domain_html_candidate', $record['scope_reason']);
    }

    public function test_the_static_template_pattern_is_filterable(): void
    {
        add_filter('dbvc_cc_v2_static_template_artifact_pattern', static function () {
            return '';
        });

        $record = DBVC_CC_V2_URL_Scope_Service::get_instance()->evaluate_url(
            'filtered-scope.example',
            'https://filtered-scope.example/a/b/c.shtml'
        );

        $this->assertSame('eligible', $record['scope_status'], 'an empty pattern disables the check');
        remove_all_filters('dbvc_cc_v2_static_template_artifact_pattern');
    }

    // ---------------------------------------------------------------- D-11

    public function test_storage_denial_covers_both_apache_generations(): void
    {
        $contents = dbvc_cc_storage_htaccess_contents();

        $this->assertStringContainsString('Require all denied', $contents, 'Apache 2.4');
        $this->assertStringContainsString('Deny from all', $contents, 'Apache 2.2');
        $this->assertStringContainsString('mod_authz_core.c', $contents, 'guarded per module');
    }

    public function test_an_existing_apache_22_only_file_is_upgraded(): void
    {
        $directory = DBVC_CC_Artifact_Manager::get_storage_base_dir() . '/htaccess-upgrade-probe';
        $this->directories_to_clean[] = $directory;
        wp_mkdir_p($directory);

        $htaccess = $directory . '/.htaccess';
        file_put_contents($htaccess, "Deny from all\n");

        $this->assertTrue(dbvc_cc_htaccess_needs_upgrade($htaccess), 'the old one-liner is detected');

        dbvc_cc_create_security_files($directory);

        $this->assertStringContainsString('Require all denied', (string) file_get_contents($htaccess));
        $this->assertFalse(dbvc_cc_htaccess_needs_upgrade($htaccess));
    }

    public function test_an_already_upgraded_file_is_left_alone(): void
    {
        $directory = DBVC_CC_Artifact_Manager::get_storage_base_dir() . '/htaccess-stable-probe';
        $this->directories_to_clean[] = $directory;
        wp_mkdir_p($directory);
        dbvc_cc_create_security_files($directory);

        $htaccess = $directory . '/.htaccess';
        $before = (string) file_get_contents($htaccess);
        dbvc_cc_create_security_files($directory);

        $this->assertSame($before, (string) file_get_contents($htaccess));
    }

    public function test_the_exposure_probe_reports_rather_than_assumes(): void
    {
        // The .htaccess does nothing on nginx, so "we wrote a deny file" is not the same claim as
        // "the directory is unreachable". The probe exists so the difference can be established.
        $this->assertTrue(function_exists('dbvc_cc_storage_http_exposure_probe'));

        $result = dbvc_cc_storage_http_exposure_probe('index.php');
        $this->assertArrayHasKey('exposed', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('reason', $result);
        $this->assertContains($result['exposed'], [true, false, null], 'null means it could not be determined');
    }

    // ---------------------------------------------------------------- D-12

    public function test_an_environment_variable_outranks_the_stored_option(): void
    {
        $options = DBVC_CC_Settings_Service::get_options();
        $options['openai_api_key'] = 'from-the-database';
        update_option('dbvc_cc_settings', $options);

        putenv('DBVC_CC_OPENAI_API_KEY=from-the-environment');

        $this->assertSame('from-the-environment', DBVC_CC_Settings_Service::get_openai_api_key());
        $this->assertSame('environment', DBVC_CC_Settings_Service::get_openai_api_key_source());
    }

    public function test_the_stored_option_is_the_last_resort(): void
    {
        $options = DBVC_CC_Settings_Service::get_options();
        $options['openai_api_key'] = 'from-the-database';
        update_option('dbvc_cc_settings', $options);

        putenv('DBVC_CC_OPENAI_API_KEY');
        putenv('OPENAI_API_KEY');

        $this->assertSame('from-the-database', DBVC_CC_Settings_Service::get_openai_api_key());
        $this->assertSame('option', DBVC_CC_Settings_Service::get_openai_api_key_source());
    }

    public function test_a_constant_is_checked_before_the_environment(): void
    {
        // Asserted against source rather than by defining the constant: define() cannot be undone,
        // and doing it here would change get_openai_api_key_source() for every later test in the
        // process. The precedence itself is exercised by the environment and option cases above.
        $source = (string) file_get_contents(
            DBVC_PLUGIN_PATH . 'addons/content-migration/settings/dbvc-cc-settings-service.php'
        );
        $constant_at = strpos($source, 'defined(\'DBVC_CC_OPENAI_API_KEY\')');
        $getenv_at = strpos($source, 'getenv($variable)');
        $option_at = strpos($source, 'isset($options[\'openai_api_key\'])');

        $this->assertNotFalse($constant_at, 'the constant must be consulted');
        $this->assertNotFalse($getenv_at, 'the environment must be consulted');
        $this->assertLessThan($getenv_at, $constant_at, 'constant before environment');
        if ($option_at !== false) {
            $this->assertLessThan($option_at, $getenv_at, 'environment before the stored option');
        }
    }

    public function test_the_mask_never_overwrites_a_stored_key(): void
    {
        $options = DBVC_CC_Settings_Service::get_options();
        $options['openai_api_key'] = 'sk-the-real-key';
        update_option('dbvc_cc_settings', $options);

        $submitted = DBVC_CC_Settings_Service::get_options();
        $submitted['openai_api_key'] = DBVC_CC_Settings_Service::API_KEY_MASK;
        $sanitized = DBVC_CC_Settings_Service::sanitize_settings($submitted);

        $this->assertSame('sk-the-real-key', $sanitized['openai_api_key']);
    }

    public function test_a_newly_typed_key_does_replace_the_stored_one(): void
    {
        $options = DBVC_CC_Settings_Service::get_options();
        $options['openai_api_key'] = 'sk-the-old-key';
        update_option('dbvc_cc_settings', $options);

        $submitted = DBVC_CC_Settings_Service::get_options();
        $submitted['openai_api_key'] = 'sk-the-new-key';
        $sanitized = DBVC_CC_Settings_Service::sanitize_settings($submitted);

        $this->assertSame('sk-the-new-key', $sanitized['openai_api_key']);
    }

    public function test_the_configure_page_does_not_echo_the_stored_key(): void
    {
        // A source contract rather than a render: the regression to prevent is literally printing
        // the secret into a value attribute, which put it in the page source on every load.
        $view = DBVC_PLUGIN_PATH . 'addons/content-migration/collector/views/tabs/configure/dbvc-cc-configure-general.php';
        $source = (string) file_get_contents($view);

        $this->assertStringNotContainsString(
            "esc_attr(\$options['openai_api_key'])",
            $source,
            'the Configure page must never echo the stored API key'
        );
        $this->assertStringContainsString('API_KEY_MASK', $source);
    }

    // ---------------------------------------------------------------- D-13

    public function test_development_only_routes_are_absent_when_disabled(): void
    {
        add_filter('dbvc_cc_v2_enable_recovery_qa_fixture', '__return_false');
        add_filter('dbvc_cc_v2_enable_package_execution_qa_fixture', '__return_false');
        $this->reset_rest_server();

        $routes = array_keys(rest_get_server()->get_routes());
        $namespace = '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE;

        foreach ($routes as $route) {
            $this->assertStringNotContainsString('/qa/recovery-fixture', $route);
            $this->assertStringNotContainsString('/qa/execution-observability-fixture', $route);
        }

        // The production routes must still be there; this gates the dev surface, nothing else.
        $this->assertContains($namespace . '/runs', $routes);
    }

    public function test_development_only_routes_are_present_when_enabled(): void
    {
        add_filter('dbvc_cc_v2_enable_recovery_qa_fixture', '__return_true');
        $this->reset_rest_server();

        $matched = false;
        foreach (array_keys(rest_get_server()->get_routes()) as $route) {
            if (strpos($route, '/qa/recovery-fixture') !== false) {
                $matched = true;
                break;
            }
        }

        $this->assertTrue($matched, 'the fixture route must still be reachable in development');
    }

    // ---------------------------------------------------------------- D-14

    public function test_retention_disabled_prunes_nothing(): void
    {
        $result = DBVC_CC_Artifact_Manager::prune_domains(0, true);

        $this->assertSame('retention_disabled', $result['reason']);
        $this->assertSame([], $result['removed']);
        $this->assertFalse((bool) $result['executed']);
    }

    public function test_a_dry_run_reports_an_old_collection_without_removing_it(): void
    {
        $domain_dir = $this->seed_domain('retention-old.example', 120);

        $result = DBVC_CC_Artifact_Manager::prune_domains(90, false);

        $eligible = wp_list_pluck($result['eligible'], 'domain');
        $this->assertContains('retention-old.example', $eligible);
        $this->assertSame([], $result['removed'], 'a dry run must not delete');
        $this->assertDirectoryExists($domain_dir);
    }

    public function test_execute_removes_only_the_collections_past_the_window(): void
    {
        $old = $this->seed_domain('retention-stale.example', 120);
        $fresh = $this->seed_domain('retention-fresh.example', 2);

        $result = DBVC_CC_Artifact_Manager::prune_domains(90, true);

        $this->assertContains('retention-stale.example', wp_list_pluck($result['removed'], 'domain'));
        $this->assertContains('retention-fresh.example', wp_list_pluck($result['retained'], 'domain'));
        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryExists($fresh);
    }

    public function test_the_uninstall_handler_removes_nothing_unless_opted_in(): void
    {
        $uninstall = DBVC_PLUGIN_PATH . 'uninstall.php';
        $this->assertFileExists($uninstall, 'the plugin must have an uninstall handler');

        $source = (string) file_get_contents($uninstall);
        $this->assertStringContainsString("defined('WP_UNINSTALL_PLUGIN')", $source);
        $this->assertStringContainsString('delete_data_on_uninstall', $source);
        // The opt-in must be an early return, so the default path deletes nothing at all.
        $this->assertMatchesRegularExpression(
            '/if \(! \$dbvc_cc_opted_in\) \{\s*return;/',
            $source,
            'uninstall must return before deleting when the operator has not opted in'
        );
    }

    public function test_retention_and_uninstall_settings_default_to_safe_values(): void
    {
        $defaults = DBVC_CC_Contracts::get_settings_defaults();

        $this->assertSame(0, (int) $defaults['artifact_retention_days'], 'keep content by default');
        $this->assertSame('0', (string) $defaults['delete_data_on_uninstall'], 'never delete by default');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param string $domain_key
     * @param int    $age_days
     * @return string
     */
    private function seed_domain(string $domain_key, int $age_days): string
    {
        $base = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        $domain_dir = trailingslashit($base) . $domain_key;
        $this->directories_to_clean[] = $domain_dir;
        wp_mkdir_p($domain_dir);

        // A directory only counts as a crawl collection once it carries the index file, so the
        // fixture has to look like one or list_domain_keys() will not see it.
        $index = $domain_dir . '/' . DBVC_CC_Artifact_Manager::INDEX_FILE;
        file_put_contents($index, '{"pages":[]}');
        $file = $domain_dir . '/page.json';
        file_put_contents($file, '{"fixture":true}');
        $stamp = time() - ($age_days * DAY_IN_SECONDS);
        touch($index, $stamp);
        touch($file, $stamp);
        touch($domain_dir, $stamp);

        return $domain_dir;
    }

    private function reset_rest_server(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
    }

    private function remove_directory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if (! ($item instanceof SplFileInfo)) {
                continue;
            }
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($directory);
    }
}
