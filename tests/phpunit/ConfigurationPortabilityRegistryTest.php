<?php

use Dbvc\ConfigurationPortability\AbstractOptionDomainProvider;
use Dbvc\ConfigurationPortability\Field;
use Dbvc\ConfigurationPortability\Registry;

class ConfigurationPortabilityRegistryTest extends WP_UnitTestCase
{
    /** @var array<string, mixed> */
    private $option_snapshot = [];

    /** @var array<string, bool> */
    private $option_existed = [];

    public function set_up(): void
    {
        parent::set_up();

        $option_keys = [
            DBVC_Visual_Editor_Addon::OPTION_ENABLED,
            DBVC_Sync_Logger::OPTION_ENABLED,
            DBVC_Sync_Logger::OPTION_MAX_SIZE,
            DBVC_Sync_Logger::OPTION_DIRECTORY,
            DBVC_Sync_Logger::OPTION_IMPORT_EVENTS,
            DBVC_Sync_Logger::OPTION_TERM_EVENTS,
            DBVC_Sync_Logger::OPTION_UPLOAD_EVENTS,
            DBVC_Sync_Logger::OPTION_MEDIA_EVENTS,
            DBVC_Media_Sync::OPTION_ENABLED,
            DBVC_Media_Sync::OPTION_PRESERVE_NAMES,
            DBVC_Media_Sync::OPTION_PREVIEW_ENABLED,
            DBVC_Media_Sync::OPTION_ALLOW_EXTERNAL,
            DBVC_Media_Sync::OPTION_TRANSPORT_MODE,
            DBVC_Media_Sync::OPTION_BUNDLE_ENABLED,
            DBVC_Media_Sync::OPTION_BUNDLE_CHUNK,
            'dbvc_post_types',
            'dbvc_taxonomies',
            'dbvc_sync_path',
            'dbvc_mirror_domain',
            'dbvc_mask_defaults_meta_keys',
            'dbvc_mask_defaults_subkeys',
            'dbvc_mask_post_fields',
            'dbvc_auto_export_mask_mode',
            'dbvc_export_last_mask_mode',
            'dbvc_mask_action',
            'dbvc_mask_meta_keys',
            'dbvc_mask_subkeys',
            'dbvc_mask_placeholder',
            DBVC_Third_Party_Portability::OPTION_WSFORM_FORMS,
            DBVC_Third_Party_Portability::OPTION_WSFORM_SETTINGS,
            DBVC_Third_Party_Portability::OPTION_WSFORM_INCLUDE_TRASH,
            DBVC_Bricks_Addon::OPTION_ENABLED,
            'dbvc_bricks_api_key_id',
            'dbvc_bricks_api_secret',
            'dbvc_bricks_mothership_url',
            'dbvc_unknown_portability_option',
            DBVC_Master_Settings::OPTION_SETTINGS,
            \Dbvc\AiPackage\Settings::OPTION_SETTINGS,
        ];

        if (class_exists('DBVC_CC_Contracts')) {
            $option_keys = array_merge(
                $option_keys,
                [
                    DBVC_CC_Contracts::OPTION_SETTINGS,
                    DBVC_CC_Contracts::OPTION_ADDON_ENABLED,
                    DBVC_CC_Contracts::OPTION_GLOBAL_KILL_SWITCH,
                    DBVC_CC_Contracts::IMPORT_POLICY_DRY_RUN_REQUIRED,
                    DBVC_CC_Contracts::IMPORT_POLICY_IDEMPOTENT_UPSERT,
                    DBVC_CC_Contracts::OPTION_SCRUB_POLICY_APPROVAL_STATUS,
                ],
                array_keys(DBVC_CC_Contracts::get_feature_flag_defaults())
            );
        }

        if (class_exists('DBVC_CC_V2_Contracts')) {
            $option_keys = array_merge($option_keys, array_keys(DBVC_CC_V2_Contracts::get_default_values()));
        }

        $this->snapshot_options(array_values(array_unique($option_keys)));

        delete_option('dbvc_unknown_portability_option');
        remove_all_filters('dbvc_configuration_portability_domains');
        Registry::reset();
    }

    public function tear_down(): void
    {
        remove_all_filters('dbvc_configuration_portability_domains');
        Registry::reset();
        $this->restore_options();

        parent::tear_down();
    }

    public function test_registry_exposes_initial_safe_domains(): void
    {
        $status = Registry::get_status();
        $this->assertSame('dbvc_configuration_portability', $status['feature']);
        $this->assertIsArray($status['domains']);

        $domain_keys = array_map(
            static function ($domain) {
                return is_array($domain) ? (string) ($domain['key'] ?? '') : '';
            },
            $status['domains']
        );

        $this->assertContains('visual_editor', $domain_keys);
        $this->assertContains('logging', $domain_keys);
        $this->assertContains('media_handling', $domain_keys);
        $this->assertContains('core_import_export', $domain_keys);
        $this->assertContains('masking', $domain_keys);
        $this->assertContains('third_party_portability_settings', $domain_keys);
        $this->assertContains('bricks_addon', $domain_keys);
        $this->assertContains('master_tools', $domain_keys);
        $this->assertContains('ai_package', $domain_keys);
        $this->assertContains('content_collector', $domain_keys);
        $this->assertContains('content_collector_runtime', $domain_keys);

        $logging_provider = Registry::get_provider('logging');
        $this->assertNotNull($logging_provider);
        $this->assertArrayHasKey(DBVC_Sync_Logger::OPTION_DIRECTORY, $logging_provider->get_fields());
    }

    public function test_registry_filter_accepts_only_provider_instances(): void
    {
        add_filter(
            'dbvc_configuration_portability_domains',
            static function (array $providers): array {
                $providers[] = 'not-a-provider';
                $providers[] = new class extends AbstractOptionDomainProvider {
                    public function get_key(): string
                    {
                        return 'qa_custom';
                    }

                    public function get_label(): string
                    {
                        return 'QA Custom';
                    }

                    public function get_version(): int
                    {
                        return 1;
                    }

                    public function get_groups(): array
                    {
                        return [
                            'general' => [
                                'label' => 'General',
                                'fields' => ['dbvc_unknown_portability_option'],
                            ],
                        ];
                    }

                    public function get_fields(): array
                    {
                        return [
                            'dbvc_unknown_portability_option' => Field::bool('dbvc_unknown_portability_option', 'Unknown test option', 'general', '0'),
                        ];
                    }
                };

                return $providers;
            }
        );

        Registry::reset();
        $providers = Registry::get_providers();

        $this->assertArrayHasKey('qa_custom', $providers);
        $this->assertNotContains('not-a-provider', $providers);
    }

    public function test_logging_export_redacts_environment_specific_directory(): void
    {
        update_option(DBVC_Sync_Logger::OPTION_DIRECTORY, 'wp-content/dbvc-logs');

        $provider = Registry::get_provider('logging');
        $this->assertNotNull($provider);

        $payload = $provider->export([], ['profile' => 'agency_baseline']);
        $directory_field = $payload['groups']['logging']['fields'][DBVC_Sync_Logger::OPTION_DIRECTORY] ?? null;

        $this->assertIsArray($directory_field);
        $this->assertSame('${DBVC_LOGGING_DIRECTORY}', $directory_field['value']);
        $this->assertSame('prompt', $directory_field['policy']);
        $this->assertTrue((bool) $directory_field['source_value_redacted']);
        $this->assertArrayHasKey(DBVC_Sync_Logger::OPTION_DIRECTORY, $payload['redactions']);
    }

    public function test_visual_editor_provider_applies_known_fields_and_ignores_unknown_fields(): void
    {
        update_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED, '0');

        $provider = Registry::get_provider('visual_editor');
        $this->assertNotNull($provider);

        $payload = $provider->export([], ['profile' => 'agency_baseline']);
        $payload['groups']['activation']['fields'][DBVC_Visual_Editor_Addon::OPTION_ENABLED]['value'] = '1';
        $payload['groups']['activation']['fields']['dbvc_unknown_portability_option'] = [
            'value' => '1',
            'policy' => 'portable',
            'source_value_redacted' => false,
        ];

        $backup = $provider->capture_backup([]);
        $sanitized = $provider->sanitize_for_apply($payload, [], $provider->get_current_values());

        $this->assertArrayHasKey(DBVC_Visual_Editor_Addon::OPTION_ENABLED, $sanitized['values']);
        $this->assertArrayNotHasKey('dbvc_unknown_portability_option', $sanitized['values']);

        $result = $provider->apply($sanitized, []);
        $this->assertArrayHasKey(DBVC_Visual_Editor_Addon::OPTION_ENABLED, $result['applied']);
        $this->assertSame('1', get_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED));
        $this->assertSame('', get_option('dbvc_unknown_portability_option', ''));

        $provider->rollback($backup, []);
        $this->assertSame('0', get_option(DBVC_Visual_Editor_Addon::OPTION_ENABLED));
    }

    public function test_media_provider_blocks_invalid_import_values(): void
    {
        update_option(DBVC_Media_Sync::OPTION_TRANSPORT_MODE, 'auto');
        update_option(DBVC_Media_Sync::OPTION_BUNDLE_CHUNK, '250');

        $provider = Registry::get_provider('media_handling');
        $this->assertNotNull($provider);

        $payload = $provider->export([], ['profile' => 'agency_baseline']);
        $payload['groups']['media']['fields'][DBVC_Media_Sync::OPTION_TRANSPORT_MODE]['value'] = 'invalid';
        $payload['groups']['media']['fields'][DBVC_Media_Sync::OPTION_BUNDLE_CHUNK]['value'] = '5';

        $sanitized = $provider->sanitize_for_apply($payload, [], $provider->get_current_values());

        $this->assertArrayHasKey(DBVC_Media_Sync::OPTION_TRANSPORT_MODE, $sanitized['errors']);
        $this->assertArrayHasKey(DBVC_Media_Sync::OPTION_BUNDLE_CHUNK, $sanitized['errors']);
        $this->assertArrayNotHasKey(DBVC_Media_Sync::OPTION_TRANSPORT_MODE, $sanitized['values']);
        $this->assertArrayNotHasKey(DBVC_Media_Sync::OPTION_BUNDLE_CHUNK, $sanitized['values']);
    }

    public function test_core_provider_redacts_environment_paths_and_sanitizes_lists(): void
    {
        update_option('dbvc_post_types', ['post', 'page']);
        update_option('dbvc_sync_path', 'wp-content/dbvc-sync');
        update_option('dbvc_mirror_domain', 'https://source.example');

        $provider = Registry::get_provider('core_import_export');
        $this->assertNotNull($provider);

        $payload = $provider->export([], ['profile' => 'agency_baseline']);
        $sync_field = $payload['groups']['sync']['fields']['dbvc_sync_path'] ?? null;
        $mirror_field = $payload['groups']['environment_urls']['fields']['dbvc_mirror_domain'] ?? null;

        $this->assertIsArray($sync_field);
        $this->assertSame('${DBVC_SYNC_PATH}', $sync_field['value']);
        $this->assertTrue((bool) $sync_field['source_value_redacted']);
        $this->assertIsArray($mirror_field);
        $this->assertSame('${DBVC_MIRROR_DOMAIN}', $mirror_field['value']);

        $payload['groups']['post_types']['fields']['dbvc_post_types']['value'] = ['post', 'Page Value', 'post', 'bad<script>'];
        $sanitized = $provider->sanitize_for_apply(
            $payload,
            [
                'dbvc_sync_path' => 'wp-content/safe-sync',
                'dbvc_mirror_domain' => 'https://target.example',
            ],
            $provider->get_current_values()
        );

        $this->assertSame(['post', 'pagevalue', 'badscript'], $sanitized['values']['dbvc_post_types']);
        $this->assertSame('wp-content/safe-sync', $sanitized['values']['dbvc_sync_path']);
        $this->assertSame('https://target.example', $sanitized['values']['dbvc_mirror_domain']);
    }

    public function test_masking_provider_excludes_runtime_fields_from_agency_baseline(): void
    {
        update_option('dbvc_mask_defaults_meta_keys', '_edit_lock');
        update_option('dbvc_mask_action', 'redact');
        update_option('dbvc_mask_meta_keys', '_secret');

        $provider = Registry::get_provider('masking');
        $this->assertNotNull($provider);

        $payload = $provider->export([], ['profile' => 'agency_baseline']);
        $this->assertArrayHasKey('defaults', $payload['groups']);
        $this->assertArrayNotHasKey('runtime', $payload['groups']);
        $this->assertArrayHasKey('dbvc_mask_defaults_meta_keys', $payload['groups']['defaults']['fields']);
    }

    public function test_third_party_settings_provider_applies_only_declared_fields(): void
    {
        update_option(DBVC_Third_Party_Portability::OPTION_WSFORM_FORMS, '0');
        update_option(DBVC_Third_Party_Portability::OPTION_WSFORM_SETTINGS, '0');
        update_option(DBVC_Third_Party_Portability::OPTION_WSFORM_INCLUDE_TRASH, '0');

        $provider = Registry::get_provider('third_party_portability_settings');
        $this->assertNotNull($provider);

        $payload = $provider->export([], ['profile' => 'agency_baseline']);
        $payload['groups']['ws_form']['fields'][DBVC_Third_Party_Portability::OPTION_WSFORM_FORMS]['value'] = '1';
        $payload['groups']['ws_form']['fields']['dbvc_unknown_portability_option'] = [
            'value' => '1',
            'policy' => 'portable',
        ];

        $sanitized = $provider->sanitize_for_apply($payload, [], $provider->get_current_values());
        $provider->apply($sanitized, []);

        $this->assertSame('1', get_option(DBVC_Third_Party_Portability::OPTION_WSFORM_FORMS));
        $this->assertSame('0', get_option(DBVC_Third_Party_Portability::OPTION_WSFORM_SETTINGS));
        $this->assertSame('', get_option('dbvc_unknown_portability_option', ''));
    }

    public function test_bricks_provider_redacts_environment_fields_and_excludes_secrets(): void
    {
        update_option('dbvc_bricks_api_key_id', 'agency_key');
        update_option('dbvc_bricks_api_secret', 'agency_secret');
        update_option('dbvc_bricks_mothership_url', 'https://mothership.example');

        $provider = Registry::get_provider('bricks_addon');
        $this->assertNotNull($provider);

        $payload = $provider->export([], ['profile' => 'agency_baseline']);
        $connection_fields = $payload['groups']['connection']['fields'] ?? [];

        $this->assertArrayHasKey('dbvc_bricks_api_key_id', $connection_fields);
        $this->assertArrayHasKey('dbvc_bricks_mothership_url', $connection_fields);
        $this->assertArrayNotHasKey('dbvc_bricks_api_secret', $connection_fields);
        $this->assertSame('${DBVC_BRICKS_API_KEY_ID}', $connection_fields['dbvc_bricks_api_key_id']['value']);
        $this->assertSame('${DBVC_BRICKS_MOTHERSHIP_URL}', $connection_fields['dbvc_bricks_mothership_url']['value']);
        $this->assertArrayHasKey('dbvc_bricks_api_key_id', $payload['redactions']);
    }

    public function test_master_tools_provider_applies_nested_option_array_fields(): void
    {
        update_option(DBVC_Master_Settings::OPTION_SETTINGS, DBVC_Master_Settings::get_default_values());

        $provider = Registry::get_provider('master_tools');
        $this->assertNotNull($provider);

        $payload = $provider->export([], ['profile' => 'agency_baseline']);
        $payload['groups']['download_sample_entities']['fields']['sample_observed_scan_cap']['value'] = '15';
        $payload['groups']['download_sample_entities']['fields']['sample_included_docs']['value'] = ['readme', 'agents', 'readme', 'bad<script>'];

        $backup = $provider->capture_backup([]);
        $sanitized = $provider->sanitize_for_apply($payload, [], $provider->get_current_values());
        $result = $provider->apply($sanitized, []);
        $settings = get_option(DBVC_Master_Settings::OPTION_SETTINGS, []);
        $tool_settings = $settings['tools']['download_sample_entities'] ?? [];

        $this->assertArrayHasKey('sample_observed_scan_cap', $result['applied']);
        $this->assertSame('15', (string) ($tool_settings['observed_scan_cap'] ?? ''));
        $this->assertSame(['readme', 'agents'], $tool_settings['included_docs'] ?? []);

        $provider->rollback($backup, []);
        $rolled_back = get_option(DBVC_Master_Settings::OPTION_SETTINGS, []);
        $rolled_back_tool = $rolled_back['tools']['download_sample_entities'] ?? [];
        $this->assertSame('0', (string) ($rolled_back_tool['observed_scan_cap'] ?? ''));
    }

    public function test_ai_package_provider_excludes_api_key_and_preserves_current_secret_on_apply(): void
    {
        $settings = \Dbvc\AiPackage\Settings::get_default_values();
        $settings['providers']['api_key'] = 'source-secret';
        $settings['providers']['model_default'] = 'gpt-5.4';
        update_option(\Dbvc\AiPackage\Settings::OPTION_SETTINGS, $settings);

        $provider = Registry::get_provider('ai_package');
        $this->assertNotNull($provider);

        $payload = $provider->export([], ['profile' => 'agency_baseline']);
        $this->assertArrayNotHasKey('ai_provider_api_key', $payload['groups']['providers']['fields']);

        $payload['groups']['providers']['fields']['ai_provider_model_default']['value'] = 'gpt-5.5';
        $payload['groups']['rules']['fields']['ai_rules_post_types']['value'] = [
            'page' => [
                'required_post_fields' => ['post_title'],
                'allowed_statuses' => ['draft'],
            ],
        ];

        $sanitized = $provider->sanitize_for_apply($payload, [], $provider->get_current_values());
        $provider->apply($sanitized, []);
        $applied = \Dbvc\AiPackage\Settings::get_all_settings();

        $this->assertSame('gpt-5.5', $applied['providers']['model_default']);
        $this->assertSame('source-secret', $applied['providers']['api_key']);
        $this->assertArrayHasKey('page', $applied['rules']['post_types']);
    }

    public function test_content_collector_provider_redacts_environment_storage_and_preserves_api_key(): void
    {
        $settings = DBVC_CC_Contracts::get_settings_defaults();
        $settings['storage_path'] = 'source-collector';
        $settings['openai_api_key'] = 'cc-secret';
        $settings['openai_model'] = 'gpt-4o-mini';
        update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $settings);

        $provider = Registry::get_provider('content_collector');
        $this->assertNotNull($provider);

        $payload = $provider->export([], ['profile' => 'agency_baseline']);
        $storage_field = $payload['groups']['storage']['fields']['cc_storage_path'] ?? null;

        $this->assertIsArray($storage_field);
        $this->assertSame('${DBVC_CC_STORAGE_PATH}', $storage_field['value']);
        $this->assertTrue((bool) $storage_field['source_value_redacted']);
        $this->assertArrayNotHasKey('cc_openai_api_key', $payload['groups']['ai']['fields']);

        $payload['groups']['ai']['fields']['cc_openai_model']['value'] = 'gpt-5.4';
        $sanitized = $provider->sanitize_for_apply(
            $payload,
            ['cc_storage_path' => 'target-collector'],
            $provider->get_current_values()
        );
        $provider->apply($sanitized, []);
        $applied = DBVC_CC_Settings_Service::get_options();

        $this->assertSame('target-collector', $applied['storage_path']);
        $this->assertSame('gpt-5.4', $applied['openai_model']);
        $this->assertSame('cc-secret', $applied['openai_api_key']);
    }

    public function test_content_collector_runtime_provider_validates_confidence_and_keeps_workflow_state_advanced(): void
    {
        update_option(DBVC_CC_V2_Contracts::OPTION_AUTO_ACCEPT_MIN_CONFIDENCE, '0.92');
        update_option(DBVC_CC_Contracts::OPTION_SCRUB_POLICY_APPROVAL_STATUS, ['approved' => true]);

        $provider = Registry::get_provider('content_collector_runtime');
        $this->assertNotNull($provider);

        $payload = $provider->export([], ['profile' => 'agency_baseline']);
        $this->assertArrayNotHasKey('workflow_state', $payload['groups']);

        $payload['groups']['automation']['fields'][DBVC_CC_V2_Contracts::OPTION_AUTO_ACCEPT_MIN_CONFIDENCE]['value'] = '2';
        $sanitized = $provider->sanitize_for_apply($payload, [], $provider->get_current_values());

        $this->assertArrayHasKey(DBVC_CC_V2_Contracts::OPTION_AUTO_ACCEPT_MIN_CONFIDENCE, $sanitized['errors']);
        $this->assertArrayNotHasKey(DBVC_CC_V2_Contracts::OPTION_AUTO_ACCEPT_MIN_CONFIDENCE, $sanitized['values']);
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
}
