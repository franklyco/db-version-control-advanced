<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Contracts
{
    public const ADDON_CONTRACT_VERSION = '1.1.0';
    public const ARTIFACT_SCHEMA_VERSION = '2.0.0';

    public const OPTION_ADDON_ENABLED = 'dbvc_cc_addon_enabled';
    public const OPTION_GLOBAL_KILL_SWITCH = 'dbvc_cc_global_kill_switch';
    public const OPTION_SETTINGS = 'dbvc_cc_settings';
    public const OPTION_SETTINGS_VERSION = 'dbvc_cc_settings_version';
    public const OPTION_FEATURE_FLAG_VERSION = 'dbvc_cc_feature_flag_version';
    public const OPTION_SCRUB_POLICY_APPROVAL_STATUS = 'dbvc_cc_scrub_policy_approval_status';
    public const SETTINGS_VERSION = 1;
    public const FEATURE_FLAG_VERSION = 6;

    public const OPTION_FLAG_COLLECTOR = 'dbvc_cc_flag_collector';
    public const OPTION_FLAG_EXPLORER = 'dbvc_cc_flag_explorer';
    public const OPTION_FLAG_AI_MAPPING = 'dbvc_cc_flag_ai_mapping';
    public const OPTION_FLAG_MAPPING_WORKBENCH = 'dbvc_cc_flag_mapping_workbench';
    public const OPTION_FLAG_IMPORT_PLAN = 'dbvc_cc_flag_import_plan';
    public const OPTION_FLAG_IMPORT_EXECUTE = 'dbvc_cc_flag_import_execute';
    public const OPTION_FLAG_EXPORT = 'dbvc_cc_flag_export';
    public const OPTION_FLAG_DEEP_CAPTURE = 'dbvc_cc_flag_deep_capture';
    public const OPTION_FLAG_CONTEXT_BUNDLE = 'dbvc_cc_flag_context_bundle';
    public const OPTION_FLAG_AI_SECTION_TYPING = 'dbvc_cc_flag_ai_section_typing';
    public const OPTION_FLAG_ATTRIBUTE_SCRUB_CONTROLS = 'dbvc_cc_flag_attribute_scrub_controls';
    public const OPTION_FLAG_MAPPING_CATALOG_BRIDGE = 'dbvc_cc_flag_mapping_catalog_bridge';
    public const OPTION_FLAG_MEDIA_MAPPING_BRIDGE = 'dbvc_cc_flag_media_mapping_bridge';

    public const SETTINGS_GROUP = 'dbvc_cc_options';
    public const ADMIN_CAPABILITY = 'manage_options';
    public const ADMIN_MENU_SLUG = 'dbvc_cc';
    public const EXPLORER_MENU_SLUG = 'dbvc_cc_explorer';
    public const WORKBENCH_MENU_SLUG = 'dbvc_cc_workbench';

    public const AJAX_NONCE_ACTION = 'dbvc_cc_ajax_nonce';
    public const AJAX_ACTION_GET_URLS_FROM_SITEMAP = 'dbvc_cc_get_urls_from_sitemap';
    public const AJAX_ACTION_PROCESS_SINGLE_URL = 'dbvc_cc_process_single_url';
    public const AJAX_ACTION_DBVC_CC_TRIGGER_DOMAIN_AI_REFRESH = 'dbvc_cc_trigger_domain_ai_refresh';

    public const REST_NAMESPACE = 'dbvc_cc/v1';
    public const CRON_HOOK_AI_PROCESS_JOB = 'dbvc_cc_ai_process_job';
    public const CRON_HOOK_MAPPING_REBUILD_BATCH = 'dbvc_cc_mapping_rebuild_batch';
    public const ACTION_RUN_SCHEMA_SNAPSHOT = 'dbvc_cc_run_schema_snapshot';

    public const TRANSIENT_PREFIX_AI_JOB = 'dbvc_cc_ai_job_';
    public const TRANSIENT_PREFIX_AI_BATCH = 'dbvc_cc_ai_batch_';
    public const TRANSIENT_PREFIX_MAPPING_REBUILD_BATCH = 'dbvc_cc_mapping_rebuild_batch_';
    public const TRANSIENT_PREFIX_EXPORT_JOB = 'dbvc_cc_export_job_';
    public const TRANSIENT_PREFIX_TREE = 'dbvc_cc_tree_';

    public const STORAGE_DEFAULT_PATH = 'contentcollector';
    public const STORAGE_EXPORTS_PATH = 'contentcollector-exports';
    public const STORAGE_INDEX_FILE = '.cc-index.json';
    public const STORAGE_REDIRECT_MAP_FILE = 'redirect-map.json';
    public const STORAGE_EVENTS_LOG_PATH = '_logs/events.ndjson';
    public const STORAGE_SCHEMA_SNAPSHOT_SUBDIR = '_schema';
    public const STORAGE_SCHEMA_SNAPSHOT_FILE = 'dbvc_cc_schema_snapshot.json';
    public const STORAGE_ELEMENTS_V2_SUFFIX = '.elements.v2.json';
    public const STORAGE_SECTIONS_V2_SUFFIX = '.sections.v2.json';
    public const STORAGE_SECTION_TYPING_V2_SUFFIX = '.section-typing.v2.json';
    public const STORAGE_CONTEXT_BUNDLE_V2_SUFFIX = '.context-bundle.v2.json';
    public const STORAGE_INGESTION_PACKAGE_V2_SUFFIX = '.ingestion-package.v2.json';
    public const STORAGE_ATTRIBUTE_SCRUB_REPORT_V2_SUFFIX = '.attribute-scrub-report.v2.json';
    public const STORAGE_TARGET_FIELD_CATALOG_V1_FILE = 'dbvc_cc_target_field_catalog.v1.json';
    public const STORAGE_SECTION_FIELD_CANDIDATES_V1_SUFFIX = '.section-field-candidates.v1.json';
    public const STORAGE_MAPPING_DECISIONS_V1_SUFFIX = '.mapping-decisions.v1.json';
    public const STORAGE_MEDIA_CANDIDATES_V1_SUFFIX = '.media-candidates.v1.json';
    public const STORAGE_MEDIA_DECISIONS_V1_SUFFIX = '.media-decisions.v1.json';

    public const CAPTURE_MODE_STANDARD = 'standard';
    public const CAPTURE_MODE_DEEP = 'deep';

    public const SCRUB_PROFILE_DETERMINISTIC_DEFAULT = 'deterministic-default';
    public const SCRUB_PROFILE_CUSTOM = 'custom';
    public const SCRUB_PROFILE_AI_SUGGESTED_APPROVED = 'ai-suggested-approved';

    public const SCRUB_ACTION_KEEP = 'keep';
    public const SCRUB_ACTION_DROP = 'drop';
    public const SCRUB_ACTION_HASH = 'hash';
    public const SCRUB_ACTION_TOKENIZE = 'tokenize';

    public const MAPPING_CATALOG_REFRESH_REUSE = 'reuse-until-fingerprint-change';
    public const MAPPING_CATALOG_REFRESH_ALWAYS = 'always-rebuild';

    public const MEDIA_DISCOVERY_MODE_METADATA_FIRST = 'metadata-first';
    public const MEDIA_DISCOVERY_MODE_SELECTIVE_DOWNLOAD = 'selective-download';

    public const MEDIA_DOWNLOAD_POLICY_REMOTE_ONLY = 'remote_only';
    public const MEDIA_DOWNLOAD_POLICY_DOWNLOAD_SELECTED = 'download_selected';
    public const MEDIA_DOWNLOAD_POLICY_SKIP = 'skip';

    public const IMPORT_POLICY_DRY_RUN_REQUIRED = 'dbvc_cc_import_policy_dry_run_required';
    public const IMPORT_POLICY_IDEMPOTENT_UPSERT = 'dbvc_cc_import_policy_idempotent_upsert';
    public const IMPORT_META_SOURCE_URL = '_dbvc_cc_source_url';
    public const IMPORT_META_SOURCE_PATH = '_dbvc_cc_source_path';
    public const IMPORT_META_SOURCE_HASH = '_dbvc_cc_source_hash';

    /**
     * @return array<string, string>
     */
    public static function get_feature_flag_defaults()
    {
        return [
            self::OPTION_FLAG_COLLECTOR => '1',
            self::OPTION_FLAG_EXPLORER => '1',
            self::OPTION_FLAG_AI_MAPPING => '0',
            self::OPTION_FLAG_MAPPING_WORKBENCH => '0',
            self::OPTION_FLAG_IMPORT_PLAN => '0',
            self::OPTION_FLAG_IMPORT_EXECUTE => '0',
            self::OPTION_FLAG_EXPORT => '0',
            self::OPTION_FLAG_DEEP_CAPTURE => '1',
            self::OPTION_FLAG_CONTEXT_BUNDLE => '0',
            self::OPTION_FLAG_AI_SECTION_TYPING => '0',
            self::OPTION_FLAG_ATTRIBUTE_SCRUB_CONTROLS => '1',
            self::OPTION_FLAG_MAPPING_CATALOG_BRIDGE => '1',
            self::OPTION_FLAG_MEDIA_MAPPING_BRIDGE => '1',
        ];
    }

    /**
     * @param string $option_key
     * @return bool
     */
    public static function is_feature_enabled($option_key)
    {
        $defaults = self::get_feature_flag_defaults();
        $default = isset($defaults[$option_key]) ? (string) $defaults[$option_key] : '0';

        return get_option((string) $option_key, $default) === '1';
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_settings_defaults()
    {
        return [
            'storage_path' => self::STORAGE_DEFAULT_PATH,
            'request_delay' => 500,
            'request_timeout' => 30,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.4896.127 Safari/537.36',
            'exclude_selectors' => '',
            'focus_selectors' => '',
            'dev_mode' => 0,
            'dev_storage_subdir' => 'dev-data',
            'prompt_version' => 'v1',
            'ai_fallback_mode' => 1,
            'openai_api_key' => '',
            'openai_model' => 'gpt-4o-mini',
            'ai_request_timeout' => 90,
            'slug_collision_policy' => 'append-path-hash',
            'taxonomy_collision_policy' => 'match-existing-review',
            'redact_emails' => 1,
            'redact_phones' => 1,
            'redact_forms' => 1,
            'explorer_default_depth' => 2,
            'explorer_max_nodes' => 600,
            'explorer_cache_ttl' => 300,
            'capture_mode' => self::CAPTURE_MODE_DEEP,
            'capture_include_attribute_context' => 1,
            'capture_include_dom_path' => 1,
            'capture_max_elements_per_page' => 2000,
            'capture_max_chars_per_element' => 1000,
            'context_enable_boilerplate_detection' => 1,
            'context_enable_entity_hints' => 1,
            'ai_enable_section_typing' => 0,
            'ai_section_typing_confidence_threshold' => 0.65,
            'scrub_policy_enabled' => 1,
            'scrub_profile_mode' => self::SCRUB_PROFILE_DETERMINISTIC_DEFAULT,
            'scrub_attr_action_class' => self::SCRUB_ACTION_TOKENIZE,
            'scrub_attr_action_id' => self::SCRUB_ACTION_HASH,
            'scrub_attr_action_data' => self::SCRUB_ACTION_TOKENIZE,
            'scrub_attr_action_style' => self::SCRUB_ACTION_DROP,
            'scrub_attr_action_aria' => self::SCRUB_ACTION_KEEP,
            'scrub_custom_allowlist' => '',
            'scrub_custom_denylist' => '',
            'scrub_ai_suggestion_enabled' => 0,
            'scrub_preview_sample_size' => 20,
            'dbvc_cc_mapping_catalog_refresh_strategy' => self::MAPPING_CATALOG_REFRESH_REUSE,
            'dbvc_cc_mapping_candidate_confidence_threshold' => 0.65,
            'dbvc_cc_media_mapping_confidence_threshold' => 0.70,
            'dbvc_cc_media_discovery_mode' => self::MEDIA_DISCOVERY_MODE_METADATA_FIRST,
            'dbvc_cc_media_download_policy' => self::MEDIA_DOWNLOAD_POLICY_REMOTE_ONLY,
            'dbvc_cc_media_mime_allowlist' => 'image/jpeg,image/png,image/webp,image/gif,image/svg+xml,video/mp4,application/pdf',
            'dbvc_cc_media_max_bytes_per_asset' => 8388608,
            'dbvc_cc_media_preview_thumbnail_enabled' => 1,
            'dbvc_cc_media_block_private_hosts' => 1,
            'dbvc_cc_media_source_domain_allowlist' => '',
            'dbvc_cc_media_source_domain_denylist' => '',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function get_settings_keys()
    {
        return array_keys(self::get_settings_defaults());
    }

    /**
     * @return void
     */
    public static function ensure_phase_zero_defaults()
    {
        add_option(self::OPTION_ADDON_ENABLED, '1');
        add_option(self::OPTION_GLOBAL_KILL_SWITCH, '0');
        add_option(self::OPTION_SETTINGS_VERSION, (string) self::SETTINGS_VERSION);
        add_option(self::OPTION_FEATURE_FLAG_VERSION, '0');
        add_option(self::OPTION_SETTINGS, self::get_settings_defaults());
        add_option(self::OPTION_SCRUB_POLICY_APPROVAL_STATUS, []);
        add_option(self::IMPORT_POLICY_DRY_RUN_REQUIRED, '1');
        add_option(self::IMPORT_POLICY_IDEMPOTENT_UPSERT, '1');

        foreach (self::get_feature_flag_defaults() as $option_key => $default_value) {
            add_option($option_key, $default_value);
        }

        $feature_flag_version = (int) get_option(self::OPTION_FEATURE_FLAG_VERSION, 0);
        if ($feature_flag_version < self::FEATURE_FLAG_VERSION) {
            update_option(self::OPTION_FLAG_COLLECTOR, '1');
            update_option(self::OPTION_FLAG_EXPLORER, '1');
            update_option(self::OPTION_FLAG_AI_MAPPING, '1');
            update_option(self::OPTION_FLAG_MAPPING_WORKBENCH, '1');
            update_option(self::OPTION_FLAG_DEEP_CAPTURE, '1');
            update_option(self::OPTION_FLAG_CONTEXT_BUNDLE, '0');
            update_option(self::OPTION_FLAG_AI_SECTION_TYPING, '0');
            update_option(self::OPTION_FLAG_ATTRIBUTE_SCRUB_CONTROLS, '1');
            update_option(self::OPTION_FLAG_MAPPING_CATALOG_BRIDGE, '1');
            update_option(self::OPTION_FLAG_MEDIA_MAPPING_BRIDGE, '1');
            update_option(self::OPTION_FEATURE_FLAG_VERSION, (string) self::FEATURE_FLAG_VERSION);
        }
    }
}
