<?php

use Dbvc\AiPackage\RulesService;
use Dbvc\AiPackage\Settings;
use Dbvc\AiPackage\SiteFingerprintService;
use Dbvc\AiPackage\Storage;
use Dbvc\AiPackage\SubmissionPackageDetector;
use Dbvc\AiPackage\SubmissionPackageTranslator;
use Dbvc\AiPackage\SubmissionPackageValidator;

if (! function_exists('acf_get_field_groups')) {
    function acf_get_field_groups()
    {
        return isset($GLOBALS['dbvc_test_acf_groups']) && is_array($GLOBALS['dbvc_test_acf_groups'])
            ? $GLOBALS['dbvc_test_acf_groups']
            : [];
    }
}

if (! function_exists('acf_get_fields')) {
    function acf_get_fields($group_key)
    {
        $group_key = sanitize_key((string) $group_key);
        return isset($GLOBALS['dbvc_test_acf_fields'][$group_key]) && is_array($GLOBALS['dbvc_test_acf_fields'][$group_key])
            ? $GLOBALS['dbvc_test_acf_fields'][$group_key]
            : [];
    }
}

if (! function_exists('vf_field_context_get_service_catalog_payload')) {
    function vf_field_context_get_service_catalog_payload($criteria = [], $profile = 'mapping')
    {
        unset($criteria, $profile);

        return isset($GLOBALS['dbvc_test_field_context_payload']) && is_array($GLOBALS['dbvc_test_field_context_payload'])
            ? $GLOBALS['dbvc_test_field_context_payload']
            : [];
    }
}

if (! function_exists('vf_object_type_context_get_catalog_payload')) {
    function vf_object_type_context_get_catalog_payload($criteria = [], $profile = 'mapping')
    {
        unset($criteria, $profile);

        return isset($GLOBALS['dbvc_test_object_type_context_payload']) && is_array($GLOBALS['dbvc_test_object_type_context_payload'])
            ? $GLOBALS['dbvc_test_object_type_context_payload']
            : [];
    }
}

class AiPackageWorkflowTest extends WP_UnitTestCase
{
    /** @var mixed */
    private $settings_before;

    /** @var bool */
    private $settings_option_existed = false;

    /** @var array<int,string> */
    private $paths_to_cleanup = [];

    public function set_up(): void
    {
        parent::set_up();

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $sentinel = new stdClass();
        $stored = get_option(Settings::OPTION_SETTINGS, $sentinel);
        if ($stored !== $sentinel) {
            $this->settings_option_existed = true;
            $this->settings_before = $stored;
        } else {
            $this->settings_option_existed = false;
            $this->settings_before = null;
        }

        update_option(Settings::OPTION_SETTINGS, Settings::get_default_values(), false);
        $this->reset_ai_package_discovery_cache();
        $this->reset_context_provider_singletons();
    }

    public function tear_down(): void
    {
        foreach (array_reverse($this->paths_to_cleanup) as $path) {
            $this->delete_directory($path);
        }

        if ($this->settings_option_existed) {
            update_option(Settings::OPTION_SETTINGS, $this->settings_before, false);
        } else {
            delete_option(Settings::OPTION_SETTINGS);
        }

        unset(
            $GLOBALS['dbvc_test_acf_groups'],
            $GLOBALS['dbvc_test_acf_fields'],
            $GLOBALS['dbvc_test_field_context_payload'],
            $GLOBALS['dbvc_test_object_type_context_payload']
        );
        $this->reset_ai_package_discovery_cache();
        $this->reset_context_provider_singletons();

        parent::tear_down();
    }

    public function test_site_fingerprint_changes_when_rules_change(): void
    {
        $schema_bundle = $this->build_minimal_schema_bundle();

        $first = SiteFingerprintService::build_from_schema_bundle($schema_bundle);
        $settings = Settings::get_default_values();
        $settings['rules']['global']['blocked_meta_keys'] = ['secret_field'];
        update_option(Settings::OPTION_SETTINGS, $settings, false);

        $second = SiteFingerprintService::build_from_schema_bundle($schema_bundle);

        $this->assertNotSame($first['site_fingerprint'], $second['site_fingerprint']);
        $this->assertNotSame($first['components']['rules'], $second['components']['rules']);
    }

    public function test_sample_package_includes_object_and_field_context_artifacts(): void
    {
        if (! class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for AI sample package tests.');
        }

        $this->seed_sample_context_fixtures();
        $this->reset_ai_package_discovery_cache();
        $this->reset_context_provider_singletons();

        $result = \Dbvc\AiPackage\SamplePackageBuilder::build([
            'post_types' => ['page'],
            'taxonomies' => [],
            'package_profile' => \Dbvc\AiPackage\SamplePackageBuilder::PACKAGE_PROFILE_COMPACT,
            'shape_mode' => 'conservative',
            'value_style' => 'blank',
            'variant_set' => 'single',
            'included_docs' => ['readme'],
        ]);

        if (is_wp_error($result)) {
            $this->fail($result->get_error_message());
        }

        $this->assertIsArray($result);
        $workspace = wp_normalize_path((string) ($result['workspace_path'] ?? ''));
        $zip_path = wp_normalize_path((string) ($result['zip_path'] ?? ''));
        $this->paths_to_cleanup[] = $workspace;
        $this->paths_to_cleanup[] = $zip_path;

        $context_path = trailingslashit($workspace) . 'samples/posts/page.context.json';
        $schema_path = trailingslashit($workspace) . 'SCHEMA_COMPACT.json';
        $this->assertFileExists($context_path);
        $this->assertFileExists($schema_path);
        $this->assertGreaterThanOrEqual(1, (int) ($result['counts']['sample_context_files'] ?? 0));

        $manifest = isset($result['manifest']) && is_array($result['manifest']) ? $result['manifest'] : [];
        $this->assertContains('samples/posts/page.context.json', $manifest['sample_files']['context'] ?? []);

        $context = json_decode((string) file_get_contents($context_path), true);
        $this->assertIsArray($context);
        $this->assertSame('dbvc_ai_sample_context', (string) ($context['artifact_type'] ?? ''));
        $this->assertSame('post', (string) ($context['object']['kind'] ?? ''));
        $this->assertSame('page', (string) ($context['object']['key'] ?? ''));
        $this->assertSame('Primary site page content container for AI-authored marketing content.', (string) ($context['object']['context'] ?? ''));
        $this->assertSame('essentials', (string) ($context['object']['authoring_profile'] ?? ''));
        $this->assertSame('Use pages for broad site content, not industry vertical landing pages.', (string) ($context['object']['routing_hint'] ?? ''));
        $this->assertSame('section_selection', (string) ($context['object']['section_authoring']['control_field'] ?? ''));
        $this->assertArrayNotHasKey('object_type_context_provider', $context);
        $this->assertArrayNotHasKey('field_context_provider', $context);
        $this->assertArrayNotHasKey('authoring_contract', $context);
        $this->assertArrayNotHasKey('object_type_context', $context);

        $hero_summary = $context['fields']['meta.hero_summary'] ?? [];
        $this->assertIsArray($hero_summary);
        $this->assertSame('textarea', (string) ($hero_summary['type'] ?? ''));
        $this->assertSame([], $hero_summary['choices'] ?? null);
        $this->assertSame('Concise hero summary for AI-generated page intros.', (string) ($hero_summary['context'] ?? ''));
        $this->assertSame('portable', (string) ($hero_summary['cross_site_safety'] ?? ''));
        $this->assertSame('content', (string) ($hero_summary['authoring_surface'] ?? ''));
        $this->assertSame('recommended', (string) ($hero_summary['authoring_priority'] ?? ''));
        $this->assertSame('Use source page messaging, rewritten for the target brand.', (string) ($hero_summary['authoring_note'] ?? ''));
        $this->assertArrayNotHasKey('value_contract', $hero_summary);

        $section_selection = $context['fields']['meta.section_selection'] ?? [];
        $this->assertIsArray($section_selection);
        $this->assertSame('operator_control', (string) ($section_selection['authoring_surface'] ?? ''));
        $this->assertSame('design_expected', (string) ($section_selection['authoring_priority'] ?? ''));
        $this->assertSame('faq_group', (string) ($section_selection['section_selection']['section_group_map']['faq_section'] ?? ''));

        $hero_image = $context['fields']['meta.hero_image'] ?? [];
        $this->assertIsArray($hero_image);
        $this->assertSame('image', (string) ($hero_image['type'] ?? ''));
        $this->assertSame('Primary hero media asset for the page.', (string) ($hero_image['context'] ?? ''));
        $this->assertSame('media_deferred', (string) ($hero_image['cross_site_safety'] ?? ''));
        $this->assertSame('media', (string) ($hero_image['authoring_surface'] ?? ''));
        $this->assertSame('do_not_author', (string) ($hero_image['authoring_priority'] ?? ''));

        $schema = json_decode((string) file_get_contents($schema_path), true);
        $this->assertIsArray($schema);
        $this->assertArrayNotHasKey('context_providers', $schema);
        $this->assertSame('Primary site page content container for AI-authored marketing content.', (string) ($schema['objects']['page']['inventory']['object_type_context']['context'] ?? ''));
        $this->assertSame('essentials', (string) ($schema['objects']['page']['inventory']['object_type_context']['authoring_profile'] ?? ''));
        $schema_field_context = $schema['objects']['page']['field_catalog']['acf']['groups']['group_ai_page']['fields'][0]['field_context'] ?? [];
        $this->assertIsArray($schema_field_context);
        $this->assertSame('Controls which frontend page sections should be authored.', (string) ($schema_field_context['context'] ?? ''));
        $this->assertSame('portable', (string) ($schema_field_context['cross_site_safety'] ?? ''));
        $this->assertSame('operator_control', (string) ($schema_field_context['authoring_surface'] ?? ''));
        $this->assertSame('faq_group', (string) ($schema_field_context['section_selection']['section_group_map']['faq_section'] ?? ''));
        $this->assertArrayNotHasKey('value_contract', $schema_field_context);
    }

    public function test_detector_does_not_claim_core_proposal_manifest_alias(): void
    {
        if (! class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for package detector tests.');
        }

        $zip_path = wp_tempnam('dbvc-core-proposal-detector');
        $this->assertIsString($zip_path);
        @unlink($zip_path);
        $zip_path .= '.zip';
        $this->paths_to_cleanup[] = $zip_path;

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('manifest.json', wp_json_encode([
            'schema_version' => 3,
            'backup_name'    => 'core-proposal-detector-fixture',
            'status'         => 'draft',
            'items'          => [],
            'media_index'    => [],
            'totals'         => [
                'files'       => 0,
                'media_items' => 0,
            ],
        ]));
        $zip->close();

        $this->assertFalse(SubmissionPackageDetector::inspect_uploaded_zip($zip_path));
    }

    public function test_detector_keeps_legacy_ai_manifest_alias_compatibility(): void
    {
        $zip_path = $this->create_zip_from_fixture('submission-legacy-compat', [
            '__SITE_FINGERPRINT__' => $this->get_current_site_fingerprint(),
        ]);

        $inspection = SubmissionPackageDetector::inspect_uploaded_zip($zip_path);

        $this->assertIsArray($inspection);
        $this->assertTrue((bool) ($inspection['detected'] ?? false));
        $this->assertSame('dbvc_ai_submission_package', (string) ($inspection['package_type'] ?? ''));
        $this->assertFalse((bool) ($inspection['manifest_is_canonical'] ?? true));
    }

    public function test_validator_blocks_sample_package_uploads(): void
    {
        $zip_path = $this->create_zip_from_fixture('sample-basic');
        $report = SubmissionPackageValidator::intake_uploaded_zip([
            'tmp_name' => $zip_path,
            'name' => 'sample-basic.zip',
        ]);

        $this->assertIsArray($report);
        $this->assertSame('blocked', $report['status']);
        $this->assertContains('wrong_package_type', $this->collect_issue_codes($report));

        $this->cleanup_intake_workspace($report);
    }

    public function test_validator_warns_when_manifest_counts_do_not_match_contents(): void
    {
        $zip_path = $this->create_zip_from_fixture('submission-warning-count-mismatch', [
            '__SITE_FINGERPRINT__' => $this->get_current_site_fingerprint(),
        ]);
        $report = SubmissionPackageValidator::intake_uploaded_zip([
            'tmp_name' => $zip_path,
            'name' => 'submission-warning-count-mismatch.zip',
        ]);

        $this->assertIsArray($report);
        $this->assertSame('valid_with_warnings', $report['status']);
        $this->assertContains('entity_count_mismatch', $this->collect_issue_codes($report));
        $this->assertSame(1, (int) ($report['counts']['translated_entities'] ?? 0));

        $this->cleanup_intake_workspace($report);
    }

    public function test_validator_accepts_valid_submission_fixture_and_writes_translation_artifacts(): void
    {
        $zip_path = $this->create_zip_from_fixture('submission-valid-basic', [
            '__SITE_FINGERPRINT__' => $this->get_current_site_fingerprint(),
        ]);
        $report = SubmissionPackageValidator::intake_uploaded_zip([
            'tmp_name' => $zip_path,
            'name' => 'submission-valid-basic.zip',
        ]);

        $this->assertIsArray($report);
        $this->assertContains((string) ($report['status'] ?? ''), ['valid', 'valid_with_warnings']);
        $this->assertSame(0, (int) ($report['counts']['blocked'] ?? 0));
        $this->assertSame(1, (int) ($report['counts']['translated_entities'] ?? 0));

        $intake_id = (string) ($report['intake_id'] ?? '');
        $translation_manifest = Storage::resolve_intake_artifact_path($intake_id, (string) ($report['artifacts']['translation_manifest'] ?? ''));
        $this->assertIsString($translation_manifest);
        $this->assertFileExists($translation_manifest);

        $this->cleanup_intake_workspace($report);
    }

    public function test_validator_accepts_legacy_manifest_filename_and_operation_alias_with_warnings(): void
    {
        $zip_path = $this->create_zip_from_fixture('submission-legacy-compat', [
            '__SITE_FINGERPRINT__' => $this->get_current_site_fingerprint(),
        ]);
        $report = SubmissionPackageValidator::intake_uploaded_zip([
            'tmp_name' => $zip_path,
            'name' => 'submission-legacy-compat.zip',
        ]);

        $this->assertIsArray($report);
        $this->assertSame('valid_with_warnings', $report['status']);
        $this->assertSame('create_only', (string) ($report['intended_operation'] ?? ''));
        $issue_codes = $this->collect_issue_codes($report);
        $this->assertContains('manifest_filename_legacy', $issue_codes);
        $this->assertContains('operation_legacy_alias', $issue_codes);
        $this->assertNotContains('root_path_unexpected', $issue_codes);
        $this->assertNotContains('entity_extension_invalid', $issue_codes);
        $this->assertSame(1, (int) ($report['counts']['translated_entities'] ?? 0));

        $this->cleanup_intake_workspace($report);
    }

    public function test_validator_accepts_legacy_generated_manifest_shape_with_warnings(): void
    {
        $zip_path = $this->create_zip_from_fixture('submission-legacy-generated-manifest', [
            '__SITE_FINGERPRINT__' => $this->get_current_site_fingerprint(),
        ]);
        $report = SubmissionPackageValidator::intake_uploaded_zip([
            'tmp_name' => $zip_path,
            'name' => 'submission-legacy-generated-manifest.zip',
        ]);

        $this->assertIsArray($report);
        $this->assertSame('valid_with_warnings', $report['status']);
        $this->assertSame('create_or_update', (string) ($report['intended_operation'] ?? ''));
        $this->assertSame($this->get_current_site_fingerprint(), (string) ($report['manifest']['source_sample_package']['site_fingerprint'] ?? ''));
        $issue_codes = $this->collect_issue_codes($report);
        $this->assertContains('site_fingerprint_legacy_top_level', $issue_codes);
        $this->assertContains('operation_inferred_from_validation_defaults', $issue_codes);
        $this->assertContains('operation_legacy_alias', $issue_codes);
        $this->assertNotContains('site_fingerprint_missing', $issue_codes);
        $this->assertNotContains('operation_invalid', $issue_codes);
        $this->assertSame(1, (int) ($report['counts']['translated_entities'] ?? 0));

        $this->cleanup_intake_workspace($report);
    }

    public function test_validator_blocks_mismatched_site_fingerprint(): void
    {
        $zip_path = $this->create_zip_from_fixture('submission-blocked-fingerprint');
        $report = SubmissionPackageValidator::intake_uploaded_zip([
            'tmp_name' => $zip_path,
            'name' => 'submission-blocked-fingerprint.zip',
        ]);

        $this->assertIsArray($report);
        $this->assertSame('blocked', $report['status']);
        $this->assertContains('site_fingerprint_mismatch', $this->collect_issue_codes($report));
        $this->assertTrue(! empty($report['override_context']['can_override_import_block']));
        $this->assertSame(1, (int) ($report['counts']['translated_entities'] ?? 0));

        $this->cleanup_intake_workspace($report);
    }

    public function test_validator_can_allow_mismatched_site_fingerprint_with_warning(): void
    {
        $settings = Settings::get_all_settings();
        $settings['validation']['site_fingerprint_mismatch_policy'] = 'warn';
        update_option(Settings::OPTION_SETTINGS, $settings, false);

        $zip_path = $this->create_zip_from_fixture('submission-blocked-fingerprint');
        $report = SubmissionPackageValidator::intake_uploaded_zip([
            'tmp_name' => $zip_path,
            'name' => 'submission-blocked-fingerprint.zip',
        ]);

        $this->assertIsArray($report);
        $this->assertSame('valid_with_warnings', $report['status']);
        $this->assertContains('site_fingerprint_mismatch', $this->collect_issue_codes($report));
        $this->assertSame(1, (int) ($report['counts']['warnings'] ?? 0));
        $this->assertSame(0, (int) ($report['counts']['blocked'] ?? 0));
        $this->assertEmpty($report['override_context']['can_override_import_block'] ?? false);
        $this->assertSame(1, (int) ($report['counts']['translated_entities'] ?? 0));

        $this->cleanup_intake_workspace($report);
    }

    public function test_importer_allows_explicit_fingerprint_override_on_blocked_report(): void
    {
        $zip_path = $this->create_zip_from_fixture('submission-blocked-fingerprint');
        $report = SubmissionPackageValidator::intake_uploaded_zip([
            'tmp_name' => $zip_path,
            'name' => 'submission-blocked-fingerprint.zip',
        ]);

        $this->assertIsArray($report);
        $this->assertSame('blocked', $report['status']);
        $this->assertSame(1, (int) ($report['counts']['translated_entities'] ?? 0));

        $report['override_applied'] = [
            'site_fingerprint_mismatch' => true,
            'approved_at' => current_time('mysql'),
            'approved_by' => get_current_user_id(),
        ];

        $result = \Dbvc\AiPackage\SubmissionPackageImporter::import_intake(
            (string) ($report['intake_id'] ?? ''),
            $report,
            ['allow_override_blocked' => true]
        );

        $this->assertIsArray($result);
        $this->assertSame(1, (int) ($result['posts']['applied'] ?? 0));
        $this->assertSame('blocked', (string) ($result['source_report']['validation_status'] ?? ''));
        $this->assertTrue(! empty($result['source_report']['override_applied']['site_fingerprint_mismatch']));

        $this->cleanup_intake_workspace($report);
    }

    public function test_translator_writes_nested_acf_meta_and_deferred_relationships(): void
    {
        $workspace = $this->create_upload_workspace('translator-nested');
        $content_root = trailingslashit($workspace) . 'content';
        $translated_root = trailingslashit($workspace) . 'translated';
        wp_mkdir_p(trailingslashit($content_root) . 'entities/posts/page');

        $entity_relative_path = 'entities/posts/page/nested-page.json';
        $entity_path = trailingslashit($content_root) . $entity_relative_path;
        file_put_contents(
            $entity_path,
            wp_json_encode([
                'ID' => 0,
                'post_type' => 'page',
                'post_title' => 'Nested Fixture Page',
                'post_name' => 'nested-page',
                'post_status' => 'draft',
                'meta' => [
                    'article_options' => [
                        'content_type' => 'simple',
                        'enable_features' => ['related_section', 'cta_section'],
                        'related_page' => [
                            'slug' => 'linked-page',
                            'post_type' => 'page',
                        ],
                    ],
                    'faq_rows' => [
                        ['question' => 'What is DBVC?'],
                        ['question' => 'How does AI intake work?'],
                    ],
                    'page_modules' => [
                        [
                            'acf_fc_layout' => 'hero',
                            'heading' => 'Welcome',
                        ],
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $schema_bundle = $this->build_nested_acf_schema_bundle();
        $validation_rules = RulesService::build_validation_rules($schema_bundle);
        $result = SubmissionPackageTranslator::translate([
            'workspace_path' => $workspace,
            'content_root_path' => $content_root,
            'translated_root' => $translated_root,
            'manifest' => [
                'package_type' => 'dbvc_ai_submission_package',
                'package_schema_version' => 1,
                'intended_operation' => 'create_or_update',
            ],
            'schema_bundle' => $schema_bundle,
            'validation_rules' => $validation_rules,
            'entities' => [
                [
                    'path' => $entity_relative_path,
                    'entity_kind' => 'post',
                    'object_key' => 'page',
                    'slug' => 'nested-page',
                    'intent' => 'create',
                    'status' => 'collected',
                ],
            ],
        ]);

        $this->assertIsArray($result);
        $this->assertSame(1, (int) ($result['counts']['translated_entities'] ?? 0));

        $translated_entity = $result['entities'][0] ?? [];
        $expected_filename = class_exists('DBVC_Import_Router')
            ? DBVC_Import_Router::determine_post_filename([
                'ID' => 0,
                'post_type' => 'page',
                'post_name' => 'nested-page',
            ])
            : 'page-nested-page.json';
        $this->assertSame('translated', $translated_entity['status'] ?? '');
        $this->assertSame('translated/sync-root/page/' . $expected_filename, $translated_entity['translated_path'] ?? '');
        $this->assertArrayHasKey('acf_meta', $translated_entity['deferred_relationships'] ?? []);

        $translated_path = trailingslashit($workspace) . (string) $translated_entity['translated_path'];
        $this->assertFileExists($translated_path);

        $translated_payload = json_decode((string) file_get_contents($translated_path), true);
        $this->assertIsArray($translated_payload);
        $meta = isset($translated_payload['meta']) && is_array($translated_payload['meta']) ? $translated_payload['meta'] : [];

        $this->assertSame('', $meta['article_options'] ?? null);
        $this->assertSame('field_article_options', $meta['_article_options'] ?? null);
        $this->assertSame('simple', $meta['article_options_content_type'] ?? null);
        $this->assertSame('field_content_type', $meta['_article_options_content_type'] ?? null);
        $this->assertSame([['related_section', 'cta_section']], $meta['article_options_enable_features'] ?? null);
        $this->assertSame('field_enable_features', $meta['_article_options_enable_features'] ?? null);
        $this->assertSame(2, $meta['faq_rows'] ?? null);
        $this->assertSame('What is DBVC?', $meta['faq_rows_0_question'] ?? null);
        $this->assertSame('How does AI intake work?', $meta['faq_rows_1_question'] ?? null);
        $this->assertSame([['hero']], $meta['page_modules'] ?? null);
        $this->assertSame('field_page_modules', $meta['_page_modules'] ?? null);
        $this->assertSame('Welcome', $meta['page_modules_0_heading'] ?? null);

        $deferred = $translated_entity['deferred_relationships']['acf_meta'][0] ?? [];
        $this->assertSame('article_options_related_page', $deferred['field_name'] ?? '');
        $this->assertSame('post_object', $deferred['field_type'] ?? '');
        $this->assertSame('linked-page', $deferred['values'][0]['slug'] ?? '');
        $this->assertSame('page', $deferred['values'][0]['post_type'] ?? '');

        $translation_manifest_path = trailingslashit($translated_root) . 'translation-manifest.json';
        $this->assertFileExists($translation_manifest_path);
    }

    public function test_translator_blocks_nonempty_unsupported_media_acf_values(): void
    {
        $workspace = $this->create_upload_workspace('translator-media');
        $content_root = trailingslashit($workspace) . 'content';
        $translated_root = trailingslashit($workspace) . 'translated';
        wp_mkdir_p(trailingslashit($content_root) . 'entities/posts/page');

        $entity_relative_path = 'entities/posts/page/media-page.json';
        file_put_contents(
            trailingslashit($content_root) . $entity_relative_path,
            wp_json_encode([
                'ID' => 0,
                'post_type' => 'page',
                'post_title' => 'Media Fixture Page',
                'post_name' => 'media-page',
                'post_status' => 'draft',
                'meta' => [
                    'hero_image' => 'https://example.com/uploads/hero.png',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $schema_bundle = [
            'field_catalog' => [
                'post_types' => [
                    'page' => [
                        'acf' => [
                            'groups' => [
                                'group_media' => [
                                    'key' => 'group_media',
                                    'fields' => [
                                        [
                                            'key' => 'field_hero_image',
                                            'name' => 'hero_image',
                                            'type' => 'image',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'taxonomies' => [],
            ],
        ];

        $result = SubmissionPackageTranslator::translate([
            'workspace_path' => $workspace,
            'content_root_path' => $content_root,
            'translated_root' => $translated_root,
            'manifest' => [
                'package_type' => 'dbvc_ai_submission_package',
                'package_schema_version' => 1,
                'intended_operation' => 'create_or_update',
            ],
            'schema_bundle' => $schema_bundle,
            'validation_rules' => RulesService::build_validation_rules($schema_bundle),
            'entities' => [
                [
                    'path' => $entity_relative_path,
                    'entity_kind' => 'post',
                    'object_key' => 'page',
                    'slug' => 'media-page',
                    'intent' => 'create',
                    'status' => 'collected',
                ],
            ],
        ]);

        $this->assertIsArray($result);
        $translated_entity = $result['entities'][0] ?? [];
        $this->assertSame('blocked', $translated_entity['status'] ?? '');
        $this->assertSame('', $translated_entity['translated_path'] ?? '');
        $this->assertContains('acf_field_unsupported_nonempty', $this->collect_issue_codes($result));
    }

    public function test_translator_uses_v2_field_context_for_import_safety(): void
    {
        $workspace = $this->create_upload_workspace('translator-v2-context-safety');
        $content_root = trailingslashit($workspace) . 'content';
        $translated_root = trailingslashit($workspace) . 'translated';
        wp_mkdir_p(trailingslashit($content_root) . 'entities/posts/page');

        $entity_relative_path = 'entities/posts/page/context-safety-page.json';
        file_put_contents(
            trailingslashit($content_root) . $entity_relative_path,
            wp_json_encode([
                'ID' => 0,
                'post_type' => 'page',
                'post_title' => 'Context Safety Page',
                'post_name' => 'context-safety-page',
                'post_status' => 'draft',
                'meta' => [
                    'hero_summary' => 'Portable intro text.',
                    'hero_image' => 'https://example.com/uploads/hero.png',
                    'editor_lock' => 1,
                    'brand_color' => '#123456',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $schema_bundle = [
            'field_catalog' => [
                'post_types' => [
                    'page' => [
                        'acf' => [
                            'groups' => [
                                'group_context_safety' => [
                                    'key' => 'group_context_safety',
                                    'fields' => [
                                        [
                                            'key' => 'field_hero_summary',
                                            'name' => 'hero_summary',
                                            'type' => 'textarea',
                                            'field_context' => [
                                                'cross_site_safety' => 'portable',
                                                'authoring_surface' => 'content',
                                                'authoring_priority' => 'recommended',
                                            ],
                                        ],
                                        [
                                            'key' => 'field_hero_image',
                                            'name' => 'hero_image',
                                            'type' => 'image',
                                            'return_format' => 'id',
                                            'field_context' => [
                                                'cross_site_safety' => 'media_deferred',
                                                'authoring_surface' => 'media',
                                                'authoring_priority' => 'do_not_author',
                                            ],
                                        ],
                                        [
                                            'key' => 'field_editor_lock',
                                            'name' => 'editor_lock',
                                            'type' => 'true_false',
                                            'field_context' => [
                                                'cross_site_safety' => 'admin_or_editor',
                                                'authoring_surface' => 'operator_control',
                                                'authoring_priority' => 'do_not_author',
                                            ],
                                        ],
                                        [
                                            'key' => 'field_brand_color',
                                            'name' => 'brand_color',
                                            'type' => 'color_picker',
                                            'field_context' => [
                                                'cross_site_safety' => 'site_specific',
                                                'authoring_surface' => 'style_token',
                                                'authoring_priority' => 'optional',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'taxonomies' => [],
            ],
        ];

        $result = SubmissionPackageTranslator::translate([
            'workspace_path' => $workspace,
            'content_root_path' => $content_root,
            'translated_root' => $translated_root,
            'manifest' => [
                'package_type' => 'dbvc_ai_submission_package',
                'package_schema_version' => 1,
                'intended_operation' => 'create_or_update',
            ],
            'schema_bundle' => $schema_bundle,
            'validation_rules' => RulesService::build_validation_rules($schema_bundle),
            'entities' => [
                [
                    'path' => $entity_relative_path,
                    'entity_kind' => 'post',
                    'object_key' => 'page',
                    'slug' => 'context-safety-page',
                    'intent' => 'create',
                    'status' => 'collected',
                ],
            ],
        ]);

        $this->assertIsArray($result);
        $translated_entity = $result['entities'][0] ?? [];
        $this->assertSame('translated', $translated_entity['status'] ?? '');
        $this->assertArrayHasKey('acf_media', $translated_entity['deferred_relationships'] ?? []);
        $this->assertContains('acf_field_media_deferred', $this->collect_issue_codes($result));
        $this->assertContains('acf_field_admin_or_editor_removed', $this->collect_issue_codes($result));
        $this->assertContains('acf_field_site_specific_review', $this->collect_issue_codes($result));

        $translated_path = trailingslashit($workspace) . (string) ($translated_entity['translated_path'] ?? '');
        $this->assertFileExists($translated_path);
        $translated_payload = json_decode((string) file_get_contents($translated_path), true);
        $this->assertIsArray($translated_payload);
        $meta = isset($translated_payload['meta']) && is_array($translated_payload['meta']) ? $translated_payload['meta'] : [];

        $this->assertSame('Portable intro text.', $meta['hero_summary'] ?? null);
        $this->assertSame('field_hero_summary', $meta['_hero_summary'] ?? null);
        $this->assertArrayNotHasKey('hero_image', $meta);
        $this->assertArrayNotHasKey('_hero_image', $meta);
        $this->assertArrayNotHasKey('editor_lock', $meta);
        $this->assertArrayNotHasKey('_editor_lock', $meta);
        $this->assertSame('#123456', $meta['brand_color'] ?? null);
        $this->assertSame('field_brand_color', $meta['_brand_color'] ?? null);
    }

    private function seed_sample_context_fixtures(): void
    {
        $location = [
            [
                [
                    'param' => 'post_type',
                    'operator' => '==',
                    'value' => 'page',
                ],
            ],
        ];

        $GLOBALS['dbvc_test_acf_groups'] = [
            [
                'key' => 'group_ai_page',
                'title' => 'AI Page Fields',
                'active' => true,
                'location' => $location,
            ],
        ];
        $GLOBALS['dbvc_test_acf_fields'] = [
            'group_ai_page' => [
                [
                    'key' => 'field_section_selection',
                    'name' => 'section_selection',
                    'label' => 'Section Selection',
                    'type' => 'checkbox',
                    'choices' => [
                        'hero_section' => 'Hero',
                        'intro_section' => 'Intro',
                        'faq_section' => 'FAQs',
                    ],
                ],
                [
                    'key' => 'field_hero_summary',
                    'name' => 'hero_summary',
                    'label' => 'Hero Summary',
                    'type' => 'textarea',
                    'instructions' => 'Short summary used near the top of the page.',
                ],
                [
                    'key' => 'field_hero_image',
                    'name' => 'hero_image',
                    'label' => 'Hero Image',
                    'type' => 'image',
                    'return_format' => 'id',
                ],
            ],
        ];
        $GLOBALS['dbvc_test_field_context_payload'] = [
            'provider' => [
                'name' => 'vertical-field-context',
                'contract_version' => 2,
                'schema_version' => 1,
                'site_fingerprint' => 'vf-site-ai',
            ],
            'catalog_meta' => [
                'status' => 'fresh',
                'source_hash' => 'vf-field-context-hash',
            ],
            'data' => [
                'groups' => [
                    [
                        'group_key' => 'group_ai_page',
                        'group_name' => 'ai_page',
                        'label' => 'AI Page Fields',
                        'key_path' => 'group_ai_page',
                        'name_path' => 'ai_page',
                        'resolved_purpose' => 'Fields used to author the page content packet.',
                        'location' => $location,
                    ],
                ],
                'entries' => [
                    [
                        'group_key' => 'group_ai_page',
                        'acf_key' => 'field_section_selection',
                        'acf_name' => 'section_selection',
                        'label' => 'Section Selection',
                        'type' => 'checkbox',
                        'key_path' => 'group_ai_page.field_section_selection',
                        'name_path' => 'ai_page.section_selection',
                        'resolved_purpose' => 'Controls which frontend page sections should be authored.',
                        'cross_site_safety' => 'portable',
                        'authoring_surface' => 'operator_control',
                        'authoring_priority' => 'design_expected',
                        'section_selection' => [
                            'available' => true,
                            'field_key' => 'field_section_selection',
                            'field_name' => 'section_selection',
                            'controls_frontend_sections' => true,
                            'source_of_truth' => true,
                            'default_values' => ['hero_section', 'intro_section'],
                            'choices' => [
                                'hero_section' => 'Hero',
                                'intro_section' => 'Intro',
                                'faq_section' => 'FAQs',
                            ],
                            'section_group_map' => [
                                'hero_section' => 'hero_section',
                                'intro_section' => 'intro_section',
                                'faq_section' => 'faq_group',
                            ],
                        ],
                        'value_contract' => [
                            'field_type' => 'checkbox',
                            'value_shape' => 'string_array',
                            'writable' => true,
                        ],
                    ],
                    [
                        'group_key' => 'group_ai_page',
                        'acf_key' => 'field_hero_summary',
                        'acf_name' => 'hero_summary',
                        'label' => 'Hero Summary',
                        'type' => 'textarea',
                        'key_path' => 'group_ai_page.field_hero_summary',
                        'name_path' => 'ai_page.hero_summary',
                        'resolved_purpose' => 'Concise hero summary for AI-generated page intros.',
                        'cross_site_safety' => 'portable',
                        'authoring_surface' => 'content',
                        'authoring_priority' => 'recommended',
                        'authoring_note' => 'Use source page messaging, rewritten for the target brand.',
                        'value_contract' => [
                            'field_type' => 'textarea',
                            'value_shape' => 'plain_text',
                            'writable' => true,
                        ],
                    ],
                    [
                        'group_key' => 'group_ai_page',
                        'acf_key' => 'field_hero_image',
                        'acf_name' => 'hero_image',
                        'label' => 'Hero Image',
                        'type' => 'image',
                        'key_path' => 'group_ai_page.field_hero_image',
                        'name_path' => 'ai_page.hero_image',
                        'resolved_purpose' => 'Primary hero media asset for the page.',
                        'cross_site_safety' => 'media_deferred',
                        'authoring_surface' => 'media',
                        'authoring_priority' => 'do_not_author',
                        'value_contract' => [
                            'field_type' => 'image',
                            'value_shape' => 'attachment_id',
                            'writable' => false,
                        ],
                    ],
                ],
            ],
        ];
        $GLOBALS['dbvc_test_object_type_context_payload'] = [
            'provider' => [
                'name' => 'vertical-object-type-context',
                'provider_version' => '1.0.0',
                'contract_version' => 2,
                'schema_version' => 1,
                'site_fingerprint' => 'vf-site-ai',
            ],
            'catalog_meta' => [
                'status' => 'fresh',
                'source_hash' => 'vf-object-context-hash',
                'entry_count' => 1,
                'complete_count' => 1,
            ],
            'data' => [
                'entries' => [
                    [
                        'object_id' => 'post_type:page',
                        'object_kind' => 'post_type',
                        'object_key' => 'page',
                        'label' => 'Pages',
                        'singular_label' => 'Page',
                        'resolved_purpose' => 'Primary site page content container for AI-authored marketing content.',
                        'entity_role' => 'primary_content',
                        'content_model' => 'page',
                        'supports_generation' => true,
                        'supports_migration_target' => true,
                        'requires_manual_review' => false,
                        'authoring_profile' => 'essentials',
                        'routing_hint' => 'Use pages for broad site content, not industry vertical landing pages.',
                        'section_authoring' => [
                            'available' => true,
                            'control_field' => 'section_selection',
                            'field_context_required' => true,
                            'note' => 'Use Field Context section_selection metadata as the source of truth for selected frontend sections.',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function reset_ai_package_discovery_cache(): void
    {
        $this->set_static_property(\Dbvc\AiPackage\AcfDiscoveryService::class, 'cached_discovery', null);
    }

    private function reset_context_provider_singletons(): void
    {
        $this->set_static_property('DBVC_CC_Field_Context_Provider_Service', 'instance', null);
        $this->set_static_property('DBVC_CC_Object_Type_Context_Provider_Service', 'instance', null);
    }

    /**
     * @param mixed $value
     */
    private function set_static_property(string $class_name, string $property_name, $value): void
    {
        if (! class_exists($class_name)) {
            return;
        }

        $reflection = new ReflectionClass($class_name);
        if (! $reflection->hasProperty($property_name)) {
            return;
        }

        $property = $reflection->getProperty($property_name);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }

    /**
     * @return array<string,mixed>
     */
    private function build_minimal_schema_bundle(): array
    {
        return [
            'object_inventory' => [
                'post_types' => [
                    'page' => [
                        'slug' => 'page',
                        'label' => 'Pages',
                    ],
                ],
                'taxonomies' => [
                    'category' => [
                        'slug' => 'category',
                        'label' => 'Categories',
                    ],
                ],
            ],
            'field_catalog' => [
                'post_types' => [
                    'page' => [
                        'registered_meta' => [],
                        'acf' => [
                            'groups' => [],
                        ],
                    ],
                ],
                'taxonomies' => [
                    'category' => [
                        'registered_meta' => [],
                        'acf' => [
                            'groups' => [],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function build_nested_acf_schema_bundle(): array
    {
        return [
            'field_catalog' => [
                'post_types' => [
                    'page' => [
                        'acf' => [
                            'groups' => [
                                'group_page_builder' => [
                                    'key' => 'group_page_builder',
                                    'fields' => [
                                        [
                                            'key' => 'field_article_options',
                                            'name' => 'article_options',
                                            'type' => 'group',
                                            'sub_fields' => [
                                                [
                                                    'key' => 'field_content_type',
                                                    'name' => 'content_type',
                                                    'type' => 'select',
                                                    'choices' => [
                                                        'simple' => 'Simple',
                                                        'flexible' => 'Flexible',
                                                    ],
                                                ],
                                                [
                                                    'key' => 'field_enable_features',
                                                    'name' => 'enable_features',
                                                    'type' => 'checkbox',
                                                    'choices' => [
                                                        'related_section' => 'Related section',
                                                        'cta_section' => 'CTA section',
                                                    ],
                                                ],
                                                [
                                                    'key' => 'field_related_page',
                                                    'name' => 'related_page',
                                                    'type' => 'post_object',
                                                    'post_type' => ['page'],
                                                    'return_format' => 'id',
                                                ],
                                            ],
                                        ],
                                        [
                                            'key' => 'field_faq_rows',
                                            'name' => 'faq_rows',
                                            'type' => 'repeater',
                                            'sub_fields' => [
                                                [
                                                    'key' => 'field_question',
                                                    'name' => 'question',
                                                    'type' => 'text',
                                                ],
                                            ],
                                        ],
                                        [
                                            'key' => 'field_page_modules',
                                            'name' => 'page_modules',
                                            'type' => 'flexible_content',
                                            'layouts' => [
                                                [
                                                    'name' => 'hero',
                                                    'sub_fields' => [
                                                        [
                                                            'key' => 'field_heading',
                                                            'name' => 'heading',
                                                            'type' => 'text',
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'taxonomies' => [],
            ],
        ];
    }

    private function get_current_site_fingerprint(): string
    {
        $context = SiteFingerprintService::build_current_context();

        return isset($context['site_fingerprint']) ? (string) $context['site_fingerprint'] : '';
    }

    private function create_upload_workspace(string $suffix): string
    {
        $uploads = wp_upload_dir(null, false);
        $base_dir = wp_normalize_path((string) ($uploads['basedir'] ?? ''));
        $workspace = trailingslashit($base_dir) . 'dbvc-ai-phpunit-' . sanitize_key($suffix) . '-' . wp_generate_password(8, false, false);

        wp_mkdir_p($workspace);
        $this->paths_to_cleanup[] = $workspace;

        return wp_normalize_path($workspace);
    }

    /**
     * @param string               $fixture_name
     * @param array<string,string> $replacements
     * @return string
     */
    private function create_zip_from_fixture(string $fixture_name, array $replacements = []): string
    {
        if (! class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for AI package fixture tests.');
        }

        $fixture_root = dirname(__DIR__) . '/fixtures/ai-packages/' . $fixture_name;
        $this->assertDirectoryExists($fixture_root);

        $zip_path = wp_tempnam('dbvc-ai-package-fixture');
        $this->assertIsString($zip_path);
        @unlink($zip_path);
        $zip_path .= '.zip';

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fixture_root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file_info) {
            if (! $file_info instanceof SplFileInfo || ! $file_info->isFile()) {
                continue;
            }

            $absolute_path = wp_normalize_path($file_info->getPathname());
            $relative_path = ltrim(str_replace(wp_normalize_path($fixture_root), '', $absolute_path), '/');
            $contents = (string) file_get_contents($absolute_path);

            if (! empty($replacements)) {
                $contents = str_replace(array_keys($replacements), array_values($replacements), $contents);
            }

            $zip->addFromString($relative_path, $contents);
        }

        $zip->close();
        $this->paths_to_cleanup[] = $zip_path;

        return $zip_path;
    }

    /**
     * @param array<string,mixed> $report
     * @return array<int,string>
     */
    private function collect_issue_codes(array $report): array
    {
        $codes = [];
        $issues = isset($report['issues']) && is_array($report['issues']) ? $report['issues'] : [];

        foreach ($issues as $issue) {
            if (! is_array($issue) || empty($issue['code'])) {
                continue;
            }

            $codes[] = (string) $issue['code'];
        }

        return $codes;
    }

    /**
     * @param array<string,mixed> $report
     * @return void
     */
    private function cleanup_intake_workspace(array $report): void
    {
        $intake_id = isset($report['intake_id']) ? (string) $report['intake_id'] : '';
        if ($intake_id === '') {
            return;
        }

        $workspace = Storage::resolve_intake_workspace($intake_id);
        if (is_string($workspace)) {
            $this->delete_directory($workspace);
        }
    }

    private function delete_directory(string $path): void
    {
        $path = wp_normalize_path($path);
        if ($path === '' || ! file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $items = scandir($path);
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->delete_directory($path . '/' . $item);
        }

        @rmdir($path);
    }
}
