<?php

use Dbvc\ConfigurationPortability\ExportPackageBuilder;
use Dbvc\ConfigurationPortability\ImportSessionService;
use Dbvc\ConfigurationPortability\AdminPage;
use Dbvc\ConfigurationPortability\Storage;

class ConfigurationPortabilityExportPackageTest extends WP_UnitTestCase
{
    /** @var array<string, mixed> */
    private $option_snapshot = [];

    /** @var array<string, bool> */
    private $option_existed = [];

    /** @var array<int, string> */
    private $created_export_dirs = [];

    /** @var array<int, string> */
    private $created_session_dirs = [];

    /** @var array<int, string> */
    private $created_backup_dirs = [];

    public function set_up(): void
    {
        parent::set_up();

        $this->snapshot_options([
            DBVC_Sync_Logger::OPTION_ENABLED,
            DBVC_Sync_Logger::OPTION_DIRECTORY,
            'dbvc_bricks_api_key_id',
            'dbvc_bricks_api_secret',
            'dbvc_bricks_mothership_url',
            'dbvc_post_types',
            \Dbvc\AiPackage\Settings::OPTION_SETTINGS,
        ]);
    }

    public function tear_down(): void
    {
        foreach ($this->created_export_dirs as $directory) {
            $this->delete_export_dir($directory);
        }
        foreach ($this->created_session_dirs as $directory) {
            $this->delete_storage_dir($directory, '/dbvc-config-portability/sessions/');
        }
        foreach ($this->created_backup_dirs as $directory) {
            $this->delete_storage_dir($directory, '/dbvc-config-portability/backups/');
        }

        $this->restore_options();

        parent::tear_down();
    }

    public function test_export_package_builder_creates_zip_with_manifest_redactions_and_checksums(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for configuration portability package tests.');
        }

        update_option(DBVC_Sync_Logger::OPTION_DIRECTORY, 'wp-content/source-logs');
        update_option('dbvc_bricks_api_key_id', 'source-key-id');
        update_option('dbvc_bricks_api_secret', 'bricks-source-secret');
        update_option('dbvc_bricks_mothership_url', 'https://mothership.example');

        $settings = \Dbvc\AiPackage\Settings::get_default_values();
        $settings['providers']['api_key'] = 'ai-provider-secret';
        $settings['providers']['model_default'] = 'gpt-5.4';
        update_option(\Dbvc\AiPackage\Settings::OPTION_SETTINGS, $settings);

        $result = ExportPackageBuilder::create_export(
            [
                'domains' => ['logging', 'bricks_addon', 'ai_package'],
            ],
            [
                'profile' => 'agency_baseline',
                'notes' => 'Package QA',
                'environment' => 'staging',
            ]
        );

        $this->assertNotWPError($result);
        $this->assertIsArray($result);
        $this->assertFileExists($result['zip_path']);
        $this->assertFileExists(trailingslashit($result['export_dir']) . 'record.json');
        $this->assertDirectoryDoesNotExist(trailingslashit($result['export_dir']) . 'workspace');
        $this->created_export_dirs[] = $result['export_dir'];

        $manifest = $this->read_zip_json($result['zip_path'], 'manifest.json');
        $site = $this->read_zip_json($result['zip_path'], 'site.json');
        $redactions = $this->read_zip_json($result['zip_path'], 'redactions.json');
        $checksums = $this->read_zip_json($result['zip_path'], 'checksums.json');
        $logging = $this->read_zip_json($result['zip_path'], 'domains/logging.json');
        $ai_package = $this->read_zip_json($result['zip_path'], 'domains/ai_package.json');

        $this->assertSame(ExportPackageBuilder::PACKAGE_TYPE, $manifest['package_type']);
        $this->assertSame('agency_baseline', $manifest['profile']);
        $this->assertContains('logging', $manifest['selected_domains']);
        $this->assertContains('bricks_addon', $manifest['selected_domains']);
        $this->assertContains('ai_package', $manifest['selected_domains']);
        $this->assertSame('staging', $site['environment']);
        $this->assertArrayHasKey('logging', $redactions['by_domain']);
        $this->assertArrayHasKey(DBVC_Sync_Logger::OPTION_DIRECTORY, $redactions['by_domain']['logging']);
        $this->assertArrayHasKey('bricks_addon', $redactions['by_domain']);
        $this->assertArrayHasKey('dbvc_bricks_api_key_id', $redactions['by_domain']['bricks_addon']);
        $this->assertSame('${DBVC_LOGGING_DIRECTORY}', $logging['groups']['logging']['fields'][DBVC_Sync_Logger::OPTION_DIRECTORY]['value']);
        $this->assertArrayNotHasKey('ai_provider_api_key', $ai_package['groups']['providers']['fields']);

        $this->assertArrayHasKey('manifest.json', $checksums);
        $this->assertArrayHasKey('site.json', $checksums);
        $this->assertArrayHasKey('redactions.json', $checksums);
        $this->assertArrayHasKey('domains/logging.json', $checksums);
        $this->assertArrayNotHasKey('checksums.json', $checksums);
        $this->assertZipChecksumMatches($result['zip_path'], 'manifest.json', $checksums['manifest.json']);
        $this->assertZipChecksumMatches($result['zip_path'], 'domains/logging.json', $checksums['domains/logging.json']);
        $this->assertNotContains('.htaccess', $this->list_zip_entries($result['zip_path']));
        $this->assertNotContains('index.php', $this->list_zip_entries($result['zip_path']));
        $this->assertNotContains('domains/.htaccess', $this->list_zip_entries($result['zip_path']));
        $this->assertNotContains('domains/index.php', $this->list_zip_entries($result['zip_path']));

        $zip_text = $this->read_zip_text_bundle($result['zip_path']);
        $this->assertStringNotContainsString('bricks-source-secret', $zip_text);
        $this->assertStringNotContainsString('ai-provider-secret', $zip_text);
        $this->assertStringNotContainsString('wp-content/source-logs', $zip_text);
    }

    public function test_export_package_builder_rejects_unknown_only_domain_selection(): void
    {
        $result = ExportPackageBuilder::create_export(
            [
                'domains' => ['dbvc_missing_domain'],
            ],
            [
                'profile' => 'agency_baseline',
            ]
        );

        $this->assertWPError($result);
        $this->assertSame('dbvc_config_portability_domains_missing', $result->get_error_code());
    }

    public function test_import_session_service_stages_package_and_builds_diff_without_applying(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for configuration portability package tests.');
        }

        update_option(DBVC_Sync_Logger::OPTION_ENABLED, '1');
        update_option(DBVC_Sync_Logger::OPTION_DIRECTORY, 'wp-content/source-logs');

        $export = ExportPackageBuilder::create_export(
            [
                'domains' => ['logging'],
            ],
            [
                'profile' => 'agency_baseline',
            ]
        );
        $this->assertNotWPError($export);
        $this->created_export_dirs[] = $export['export_dir'];

        update_option(DBVC_Sync_Logger::OPTION_ENABLED, '0');
        update_option(DBVC_Sync_Logger::OPTION_DIRECTORY, 'wp-content/target-logs');

        $session = ImportSessionService::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertNotWPError($session);
        $this->assertSame('import_session', $session['record_type']);
        $this->assertSame('0', get_option(DBVC_Sync_Logger::OPTION_ENABLED));
        $this->assertSame('wp-content/target-logs', get_option(DBVC_Sync_Logger::OPTION_DIRECTORY));
        $this->assertArrayHasKey('logging', $session['diffs']);
        $this->assertGreaterThan(0, (int) ($session['summary']['row_count'] ?? 0));
        $this->assertArrayHasKey('changed', $session['summary']['statuses']);
        $this->assertArrayHasKey('needs_environment_value', $session['summary']['statuses']);
        $this->created_session_dirs[] = dirname((string) $session['archive_path']);

        $loaded = ImportSessionService::get_session((string) $session['session_id']);
        $this->assertNotWPError($loaded);
        $this->assertSame($session['session_id'], $loaded['session_id']);
    }

    public function test_import_session_service_rejects_invalid_package_type(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for configuration portability package tests.');
        }

        $zip_path = trailingslashit(sys_get_temp_dir()) . 'dbvc-invalid-config-package-' . uniqid('', true) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('manifest.json', wp_json_encode([
            'package_type' => 'not_dbvc_configuration_portability',
            'package_schema_version' => 1,
            'selected_domains' => ['logging'],
        ]));
        $zip->close();

        $result = ImportSessionService::import_uploaded_package([
            'tmp_name' => $zip_path,
            'name' => 'invalid.zip',
            'error' => UPLOAD_ERR_OK,
        ]);

        @unlink($zip_path);

        $this->assertWPError($result);
        $this->assertSame('dbvc_config_portability_manifest_type_invalid', $result->get_error_code());
    }

    public function test_import_session_surfaces_manifest_compatibility_warnings(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for configuration portability package tests.');
        }

        $export = ExportPackageBuilder::create_export(
            [
                'domains' => ['logging'],
            ],
            [
                'profile' => 'agency_baseline',
            ]
        );
        $this->assertNotWPError($export);
        $this->created_export_dirs[] = $export['export_dir'];

        $manifest = $this->read_zip_json($export['zip_path'], 'manifest.json');
        $manifest['compatibility']['min_dbvc_version'] = '99.0.0';
        $manifest['domains']['logging']['domain_version'] = 99;
        $this->rewrite_zip_json_entries($export['zip_path'], [
            'manifest.json' => $manifest,
        ]);

        $session = ImportSessionService::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertNotWPError($session);
        $this->created_session_dirs[] = dirname((string) $session['archive_path']);

        $codes = array_map(static function ($warning): string {
            return is_array($warning) ? (string) ($warning['code'] ?? '') : '';
        }, (array) ($session['compatibility_warnings'] ?? []));

        $this->assertContains('package_requires_newer_dbvc', $codes);
        $this->assertContains('domain_version_newer', $codes);
        $this->assertSame(2, (int) ($session['summary']['warning_count'] ?? 0));
    }

    public function test_import_session_rejects_checksum_mismatch(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for configuration portability package tests.');
        }

        $export = ExportPackageBuilder::create_export(
            ['domains' => ['logging']],
            ['profile' => 'agency_baseline']
        );
        $this->assertNotWPError($export);
        $this->created_export_dirs[] = $export['export_dir'];

        $entry = 'domains/logging.json';
        $contents = $this->read_zip_entry($export['zip_path'], $entry) . " ";
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($export['zip_path']) === true);
        $zip->deleteName($entry);
        $this->assertTrue($zip->addFromString($entry, $contents));
        $zip->close();

        $result = ImportSessionService::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertWPError($result);
        $this->assertSame('dbvc_config_portability_checksum_mismatch', $result->get_error_code());
    }

    public function test_import_session_surfaces_missing_domain_and_unsupported_field_without_writing(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for configuration portability package tests.');
        }

        update_option(DBVC_Sync_Logger::OPTION_ENABLED, '1');
        $export = ExportPackageBuilder::create_export(
            ['domains' => ['logging']],
            ['profile' => 'agency_baseline']
        );
        $this->assertNotWPError($export);
        $this->created_export_dirs[] = $export['export_dir'];

        $manifest = $this->read_zip_json($export['zip_path'], 'manifest.json');
        $manifest['selected_domains'][] = 'dbvc_missing_domain';
        $logging = $this->read_zip_json($export['zip_path'], 'domains/logging.json');
        $logging['groups']['logging']['fields']['dbvc_future_logging_field'] = [
            'value' => 'future',
            'policy' => 'portable',
            'type' => 'text',
            'label' => 'Future logging field',
        ];
        $this->rewrite_zip_json_entries($export['zip_path'], [
            'manifest.json' => $manifest,
            'domains/logging.json' => $logging,
        ]);

        update_option(DBVC_Sync_Logger::OPTION_ENABLED, '0');
        $session = ImportSessionService::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertNotWPError($session);
        $this->created_session_dirs[] = dirname((string) $session['archive_path']);
        $codes = array_column((array) $session['compatibility_warnings'], 'code');
        $this->assertContains('domain_skipped', $codes);
        $this->assertContains('unsupported_field', $codes);
        $this->assertSame('provider_unavailable', $session['skipped_domains']['dbvc_missing_domain']);
        $this->assertSame('0', get_option(DBVC_Sync_Logger::OPTION_ENABLED));
    }

    public function test_import_session_blocks_missing_selected_dependency_and_leaves_settings_unchanged(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for configuration portability package tests.');
        }

        update_option('dbvc_post_types', ['post']);
        $export = ExportPackageBuilder::create_export(
            [
                'domains' => ['core_import_export'],
                'fields_by_domain' => [
                    'core_import_export' => ['dbvc_post_types'],
                ],
            ],
            ['profile' => 'agency_baseline']
        );
        $this->assertNotWPError($export);
        $this->created_export_dirs[] = $export['export_dir'];

        $core = $this->read_zip_json($export['zip_path'], 'domains/core_import_export.json');
        $core['groups']['post_types']['fields']['dbvc_post_types']['value'] = ['dbvc_missing_post_type'];
        $this->rewrite_zip_json_entries($export['zip_path'], [
            'domains/core_import_export.json' => $core,
        ]);

        update_option('dbvc_post_types', ['page']);
        $session = ImportSessionService::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertNotWPError($session);
        $this->created_session_dirs[] = dirname((string) $session['archive_path']);
        $dependency_warnings = array_values(array_filter((array) $session['compatibility_warnings'], static function ($warning): bool {
            return is_array($warning) && ($warning['code'] ?? '') === 'missing_dependency';
        }));
        $this->assertCount(1, $dependency_warnings);
        $this->assertSame('blocked', $dependency_warnings[0]['severity']);
        $this->assertSame('post_type', $dependency_warnings[0]['dependency_type']);
        $this->assertSame('dbvc_missing_post_type', $dependency_warnings[0]['dependency']);
        $this->assertSame(1, (int) $session['summary']['blocking_dependency_count']);

        $result = ImportSessionService::apply_session((string) $session['session_id'], [], [
            'confirm_apply' => true,
        ]);
        $this->assertWPError($result);
        $this->assertSame('dbvc_config_portability_apply_preflight_failed', $result->get_error_code());
        $this->assertSame(['page'], get_option('dbvc_post_types'));
    }

    public function test_review_draft_recovers_choices_expires_safely_and_never_stores_credential_like_values(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for configuration portability package tests.');
        }

        update_option(DBVC_Sync_Logger::OPTION_DIRECTORY, 'wp-content/source-logs');
        update_option('dbvc_bricks_api_key_id', 'source-key-id');
        $export = ExportPackageBuilder::create_export(
            [
                'domains' => ['logging', 'bricks_addon'],
                'fields_by_domain' => [
                    'logging' => [DBVC_Sync_Logger::OPTION_DIRECTORY],
                    'bricks_addon' => ['dbvc_bricks_api_key_id'],
                ],
            ],
            ['profile' => 'agency_baseline']
        );
        $this->assertNotWPError($export);
        $this->created_export_dirs[] = $export['export_dir'];

        update_option(DBVC_Sync_Logger::OPTION_DIRECTORY, 'wp-content/target-logs');
        update_option('dbvc_bricks_api_key_id', 'target-key-id');
        $session = ImportSessionService::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
            'error' => UPLOAD_ERR_OK,
        ]);
        $this->assertNotWPError($session);
        $this->created_session_dirs[] = dirname((string) $session['archive_path']);

        $fingerprint = ImportSessionService::get_review_fingerprint($session);
        $saved = ImportSessionService::save_review_draft(
            (string) $session['session_id'],
            [
                'logging' => [
                    DBVC_Sync_Logger::OPTION_DIRECTORY => [
                        'action' => 'replace',
                        'value' => 'wp-content/draft-logs',
                    ],
                ],
                'bricks_addon' => [
                    'dbvc_bricks_api_key_id' => [
                        'action' => 'replace',
                        'value' => 'must-not-be-stored',
                    ],
                ],
                'unknown_domain' => [
                    'unknown_field' => [
                        'action' => 'replace',
                        'value' => 'ignored',
                    ],
                ],
            ],
            $fingerprint
        );

        $this->assertNotWPError($saved);
        $draft = ImportSessionService::get_review_draft($saved);
        $this->assertSame('active', $draft['status']);
        $this->assertSame('replace', $draft['decisions']['logging'][DBVC_Sync_Logger::OPTION_DIRECTORY]['action']);
        $this->assertSame('wp-content/draft-logs', $draft['decisions']['logging'][DBVC_Sync_Logger::OPTION_DIRECTORY]['value']);
        $this->assertSame('replace', $draft['decisions']['bricks_addon']['dbvc_bricks_api_key_id']['action']);
        $this->assertSame('', $draft['decisions']['bricks_addon']['dbvc_bricks_api_key_id']['value']);
        $this->assertArrayNotHasKey('unknown_domain', $draft['decisions']);
        $this->assertSame('wp-content/target-logs', get_option(DBVC_Sync_Logger::OPTION_DIRECTORY));
        $this->assertSame('target-key-id', get_option('dbvc_bricks_api_key_id'));

        $loaded = ImportSessionService::get_session((string) $session['session_id']);
        $this->assertNotWPError($loaded);
        $this->assertSame('active', ImportSessionService::get_review_draft($loaded)['status']);

        $previous_get = $_GET;
        $_GET = [
            'page' => AdminPage::PAGE_SLUG,
            'session_id' => (string) $session['session_id'],
            'review_unresolved' => '1',
        ];
        $administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($administrator_id);
        ob_start();
        AdminPage::render_page();
        $html = (string) ob_get_clean();
        $_GET = $previous_get;

        $this->assertStringContainsString('Save Review Draft', $html);
        $this->assertStringContainsString('name="review_domain"', $html);
        $this->assertStringContainsString('name="review_group"', $html);
        $this->assertStringContainsString('name="review_field"', $html);
        $this->assertStringContainsString('name="review_status"', $html);
        $this->assertStringContainsString('name="review_unresolved"', $html);
        $this->assertStringContainsString('value="wp-content/draft-logs"', $html);
        $this->assertStringNotContainsString('must-not-be-stored', $html);
        $this->assertStringContainsString('Credential-like replacement text is never stored', $html);
        $this->assertSame('wp-content/target-logs', get_option(DBVC_Sync_Logger::OPTION_DIRECTORY));
        $this->assertSame('target-key-id', get_option('dbvc_bricks_api_key_id'));

        $stale = ImportSessionService::save_review_draft(
            (string) $session['session_id'],
            [],
            str_repeat('0', 64)
        );
        $this->assertWPError($stale);
        $this->assertSame('dbvc_config_portability_review_draft_stale', $stale->get_error_code());

        $loaded['review_draft']['expires_at_gmt'] = gmdate('c', time() - 60);
        $this->assertSame('expired', ImportSessionService::get_review_draft($loaded)['status']);

        $missing_id = 'dbvc-config-session-phase38-missing';
        $missing = ImportSessionService::save_review_draft($missing_id, [], str_repeat('0', 64));
        $this->assertWPError($missing);
        $missing_dir = Storage::resolve_session_directory($missing_id);
        if (is_string($missing_dir)) {
            $this->created_session_dirs[] = $missing_dir;
        }
    }

    public function test_review_filters_are_server_rendered_and_do_not_mutate_settings(): void
    {
        $diffs = [
            'logging' => [
                'domain' => 'logging',
                'rows' => [
                    [
                        'field' => 'dbvc_logging_enabled',
                        'label' => 'Logging enabled',
                        'group' => 'logging',
                        'status' => 'same',
                    ],
                    [
                        'field' => DBVC_Sync_Logger::OPTION_DIRECTORY,
                        'label' => 'Logging directory',
                        'group' => 'logging',
                        'status' => 'needs_environment_value',
                    ],
                ],
            ],
            'media_handling' => [
                'domain' => 'media_handling',
                'rows' => [
                    [
                        'field' => 'dbvc_media_sync_enabled',
                        'label' => 'Media sync enabled',
                        'group' => 'media',
                        'status' => 'changed',
                    ],
                ],
            ],
        ];

        $unresolved = AdminPage::filter_review_diffs($diffs, ['unresolved' => '1']);
        $this->assertCount(1, $unresolved['logging']['rows']);
        $this->assertSame(DBVC_Sync_Logger::OPTION_DIRECTORY, $unresolved['logging']['rows'][0]['field']);
        $this->assertArrayHasKey('media_handling', $unresolved);

        $field = AdminPage::filter_review_diffs($diffs, [
            'domain' => 'logging',
            'group' => 'logging',
            'field' => DBVC_Sync_Logger::OPTION_DIRECTORY,
            'status' => 'needs_environment_value',
        ]);
        $this->assertSame([DBVC_Sync_Logger::OPTION_DIRECTORY], array_column($field['logging']['rows'], 'field'));
        $this->assertArrayNotHasKey('media_handling', $field);
    }

    public function test_review_draft_handler_requires_manage_options_and_valid_nonce(): void
    {
        $previous_post = $_POST;
        $_POST = ['session_id' => 'dbvc-config-session-phase38-auth'];

        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);
        $this->assertWpDieContains(static function (): void {
            AdminPage::handle_save_review_draft();
        }, 'sufficient permissions');

        $administrator_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($administrator_id);
        $this->assertWpDieContains(static function (): void {
            AdminPage::handle_save_review_draft();
        }, 'link you followed has expired');

        $_POST = $previous_post;
    }

    public function test_import_session_apply_requires_environment_decisions_before_writing(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for configuration portability package tests.');
        }

        update_option(DBVC_Sync_Logger::OPTION_ENABLED, '1');
        update_option(DBVC_Sync_Logger::OPTION_DIRECTORY, 'wp-content/source-logs');

        $export = ExportPackageBuilder::create_export(
            [
                'domains' => ['logging'],
            ],
            [
                'profile' => 'agency_baseline',
            ]
        );
        $this->assertNotWPError($export);
        $this->created_export_dirs[] = $export['export_dir'];

        update_option(DBVC_Sync_Logger::OPTION_ENABLED, '0');
        update_option(DBVC_Sync_Logger::OPTION_DIRECTORY, 'wp-content/target-logs');

        $session = ImportSessionService::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
            'error' => UPLOAD_ERR_OK,
        ]);
        $this->assertNotWPError($session);
        $this->created_session_dirs[] = dirname((string) $session['archive_path']);

        $result = ImportSessionService::apply_session((string) $session['session_id'], [], [
            'confirm_apply' => true,
        ]);

        $this->assertWPError($result);
        $this->assertSame('dbvc_config_portability_apply_preflight_failed', $result->get_error_code());
        $this->assertSame('0', get_option(DBVC_Sync_Logger::OPTION_ENABLED));
        $this->assertSame('wp-content/target-logs', get_option(DBVC_Sync_Logger::OPTION_DIRECTORY));
    }

    public function test_import_session_apply_captures_backup_and_rollback_restores_previous_values(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for configuration portability package tests.');
        }

        update_option(DBVC_Sync_Logger::OPTION_ENABLED, '1');
        update_option(DBVC_Sync_Logger::OPTION_DIRECTORY, 'wp-content/source-logs');

        $export = ExportPackageBuilder::create_export(
            [
                'domains' => ['logging'],
            ],
            [
                'profile' => 'agency_baseline',
            ]
        );
        $this->assertNotWPError($export);
        $this->created_export_dirs[] = $export['export_dir'];

        update_option(DBVC_Sync_Logger::OPTION_ENABLED, '0');
        update_option(DBVC_Sync_Logger::OPTION_DIRECTORY, 'wp-content/target-logs');

        $session = ImportSessionService::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
            'error' => UPLOAD_ERR_OK,
        ]);
        $this->assertNotWPError($session);
        $this->created_session_dirs[] = dirname((string) $session['archive_path']);

        $applied = ImportSessionService::apply_session(
            (string) $session['session_id'],
            [
                'logging' => [
                    DBVC_Sync_Logger::OPTION_DIRECTORY => [
                        'action' => 'replace',
                        'value' => 'wp-content/replaced-logs',
                    ],
                ],
            ],
            [
                'confirm_apply' => true,
            ]
        );

        $this->assertNotWPError($applied);
        $this->assertSame('applied', $applied['status']);
        $this->assertSame('1', get_option(DBVC_Sync_Logger::OPTION_ENABLED));
        $this->assertSame('wp-content/replaced-logs', get_option(DBVC_Sync_Logger::OPTION_DIRECTORY));
        $this->assertNotEmpty($applied['backup_id']);
        $this->created_backup_dirs[] = dirname((string) $applied['backup_path']);

        $rolled_back = ImportSessionService::rollback_session((string) $session['session_id'], [
            'confirm_rollback' => true,
        ]);

        $this->assertNotWPError($rolled_back);
        $this->assertSame('rolled_back', $rolled_back['status']);
        $this->assertSame('0', get_option(DBVC_Sync_Logger::OPTION_ENABLED));
        $this->assertSame('wp-content/target-logs', get_option(DBVC_Sync_Logger::OPTION_DIRECTORY));
    }

    /**
     * @param string $zip_path
     * @param string $entry
     * @return array<string, mixed>
     */
    private function read_zip_json($zip_path, $entry): array
    {
        $raw = $this->read_zip_entry($zip_path, $entry);
        $decoded = json_decode($raw, true);

        $this->assertIsArray($decoded, sprintf('Expected %s to decode as JSON object.', $entry));

        return $decoded;
    }

    /**
     * @param string $zip_path
     * @param string $entry
     * @return string
     */
    private function read_zip_entry($zip_path, $entry): string
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path) === true);
        $raw = $zip->getFromName($entry);
        $zip->close();

        $this->assertIsString($raw, sprintf('Expected %s in ZIP.', $entry));

        return $raw;
    }

    /**
     * @param string $zip_path
     * @return string
     */
    private function read_zip_text_bundle($zip_path): string
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path) === true);

        $bundle = '';
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (! is_string($name) || substr($name, -5) !== '.json') {
                continue;
            }
            $contents = $zip->getFromIndex($index);
            if (is_string($contents)) {
                $bundle .= $contents . "\n";
            }
        }
        $zip->close();

        return $bundle;
    }

    /**
     * @param string $zip_path
     * @return array<int, string>
     */
    private function list_zip_entries($zip_path): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path) === true);

        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (is_string($name)) {
                $entries[] = $name;
            }
        }
        $zip->close();

        return $entries;
    }

    /**
     * @param string $zip_path
     * @param string $entry
     * @param string $expected
     * @return void
     */
    private function assertZipChecksumMatches($zip_path, $entry, $expected): void
    {
        $this->assertSame($expected, hash('sha256', $this->read_zip_entry($zip_path, $entry)));
    }

    /**
     * @param string               $zip_path
     * @param array<string, mixed> $entries
     * @return void
     */
    private function rewrite_zip_json_entries($zip_path, array $entries): void
    {
        $checksums = $this->read_zip_json($zip_path, 'checksums.json');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path) === true);

        foreach ($entries as $entry => $payload) {
            $entry = (string) $entry;
            $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->assertIsString($json);
            $json .= "\n";

            $zip->deleteName($entry);
            $this->assertTrue($zip->addFromString($entry, $json));
            $checksums[$entry] = hash('sha256', $json);
        }

        $checksums_json = wp_json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($checksums_json);
        $zip->deleteName('checksums.json');
        $this->assertTrue($zip->addFromString('checksums.json', $checksums_json . "\n"));
        $zip->close();
    }

    /**
     * @param callable $callback
     * @param string   $expected
     * @return void
     */
    private function assertWpDieContains(callable $callback, $expected): void
    {
        $die_handler = static function ($message) {
            if (is_wp_error($message)) {
                $message = $message->get_error_message();
            }
            throw new RuntimeException(wp_strip_all_tags((string) $message));
        };
        $handler_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        add_filter('wp_die_handler', $handler_filter);

        try {
            $callback();
            $this->fail('Expected wp_die to be called.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(strtolower((string) $expected), strtolower($exception->getMessage()));
        } finally {
            remove_filter('wp_die_handler', $handler_filter);
        }
    }

    /**
     * @param array<int, string> $option_keys
     * @return void
     */
    private function snapshot_options(array $option_keys): void
    {
        $sentinel = new stdClass();
        foreach ($option_keys as $option_key) {
            $value = get_option($option_key, $sentinel);
            $this->option_existed[$option_key] = $value !== $sentinel;
            $this->option_snapshot[$option_key] = $value === $sentinel ? null : $value;
        }
    }

    /**
     * @return void
     */
    private function restore_options(): void
    {
        foreach ($this->option_snapshot as $option_key => $value) {
            if (empty($this->option_existed[$option_key])) {
                delete_option($option_key);
                continue;
            }

            update_option($option_key, $value);
        }
    }

    /**
     * @param string $directory
     * @return void
     */
    private function delete_export_dir($directory): void
    {
        $this->delete_storage_dir($directory, '/dbvc-config-portability/exports/');
    }

    /**
     * @param string $directory
     * @param string $required_segment
     * @return void
     */
    private function delete_storage_dir($directory, $required_segment): void
    {
        $directory = wp_normalize_path((string) $directory);
        if ($directory === '' || strpos($directory, $required_segment) === false || ! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
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

        @rmdir($directory);
    }
}
