<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Contracts
{
    public const OPTION_ADDON_ENABLED = DBVC_CC_Contracts::OPTION_ADDON_ENABLED;
    public const OPTION_RUNTIME_VERSION = 'dbvc_cc_runtime_version';
    public const OPTION_AUTO_ACCEPT_MIN_CONFIDENCE = 'dbvc_cc_v2_auto_accept_min_confidence';
    public const OPTION_BLOCK_BELOW_CONFIDENCE = 'dbvc_cc_v2_block_below_confidence';
    public const OPTION_RESOLUTION_UPDATE_MIN_CONFIDENCE = 'dbvc_cc_v2_resolution_update_min_confidence';
    public const OPTION_PATTERN_REUSE_MIN_CONFIDENCE = 'dbvc_cc_v2_pattern_reuse_min_confidence';
    public const OPTION_REQUIRE_QA_PASS_FOR_AUTO_ACCEPT = 'dbvc_cc_v2_require_qa_pass_for_auto_accept';
    public const OPTION_REQUIRE_UNAMBIGUOUS_RESOLUTION_FOR_AUTO_ACCEPT = 'dbvc_cc_v2_require_unambiguous_resolution_for_auto_accept';
    public const OPTION_REQUIRE_MANUAL_REVIEW_FOR_OBJECT_FAMILY_CHANGE = 'dbvc_cc_v2_require_manual_review_for_object_family_change';

    public const RUNTIME_V1 = 'v1';
    public const RUNTIME_V2 = 'v2';

    public const ADMIN_MENU_SLUG = DBVC_CC_Contracts::ADMIN_MENU_SLUG;
    public const REST_NAMESPACE = 'dbvc_cc/v2';
    public const SCRIPT_HANDLE = 'dbvc-content-collector-v2-app';
    public const SCRIPT_OBJECT = 'DBVC_CC_V2_APP';
    public const DEFAULT_RUN_ID = 'journey-demo';
    public const PIPELINE_VERSION = 'v2';
    public const AI_FALLBACK_MODE = 'deterministic';
    public const AI_STAGE_CONTEXT_CREATION = 'context_creation';
    public const AI_STAGE_INITIAL_CLASSIFICATION = 'initial_classification';
    public const AI_STAGE_MAPPING_INDEX = 'mapping_index';
    public const AI_STAGE_RECOMMENDATION_FINALIZATION = 'recommendation_finalization';
    public const QA_HISTORICAL_REVIEW_FIXTURE_DOMAIN = 'qa-historical-review-fixture.example';

    public const STORAGE_JOURNEY_SUBDIR = '_journey';
    public const STORAGE_INVENTORY_SUBDIR = '_inventory';
    public const STORAGE_LEARNING_SUBDIR = '_learning';
    public const STORAGE_PACKAGES_SUBDIR = '_packages';
    public const STORAGE_PAGE_RUNS_SUBDIR = '_runs';
    public const STORAGE_JOURNEY_LOG_FILE = 'domain-journey.ndjson';
    public const STORAGE_JOURNEY_LATEST_FILE = 'domain-journey.latest.v1.json';
    public const STORAGE_STAGE_SUMMARY_FILE = 'domain-stage-summary.v1.json';
    public const STORAGE_RUN_PROFILE_FILE = 'run-request-profile.latest.v1.json';
    public const STORAGE_URL_INVENTORY_FILE = 'domain-url-inventory.v1.json';
    public const STORAGE_DOMAIN_PATTERN_MEMORY_FILE = 'domain-pattern-memory.v1.json';
    public const STORAGE_PACKAGE_BUILDS_FILE = 'package-builds.v1.json';
    public const STORAGE_TARGET_OBJECT_INVENTORY_FILE = 'dbvc_cc_target_object_inventory.v1.json';
    public const STORAGE_TARGET_FIELD_CATALOG_FILE = 'dbvc_cc_target_field_catalog.v2.json';
    public const STORAGE_SOURCE_NORMALIZATION_SUFFIX = '.source-normalization.v1.json';
    public const STORAGE_CONTEXT_CREATION_SUFFIX = '.context-creation.v1.json';
    public const STORAGE_INITIAL_CLASSIFICATION_SUFFIX = '.initial-classification.v1.json';
    public const STORAGE_MAPPING_INDEX_SUFFIX = '.mapping-index.v1.json';
    public const STORAGE_TARGET_TRANSFORM_SUFFIX = '.target-transform.v1.json';
    public const STORAGE_MAPPING_RECOMMENDATIONS_SUFFIX = '.mapping-recommendations.v2.json';
    public const STORAGE_MAPPING_DECISIONS_SUFFIX = '.mapping-decisions.v2.json';
    public const STORAGE_MEDIA_CANDIDATES_SUFFIX = '.media-candidates.v2.json';
    public const STORAGE_MEDIA_DECISIONS_SUFFIX = '.media-decisions.v2.json';
    public const STORAGE_QA_REPORT_SUFFIX = '.qa-report.v1.json';
    public const STORAGE_PACKAGE_MANIFEST_FILE = 'package-manifest.v1.json';
    public const STORAGE_PACKAGE_RECORDS_FILE = 'package-records.v1.json';
    public const STORAGE_PACKAGE_MEDIA_MANIFEST_FILE = 'package-media-manifest.v1.json';
    public const STORAGE_PACKAGE_QA_REPORT_FILE = 'package-qa-report.v1.json';
    public const STORAGE_PACKAGE_SUMMARY_FILE = 'package-summary.v1.json';
    public const STORAGE_PACKAGE_ZIP_FILE = 'import-package.v1.zip';

    public const STEP_DOMAIN_JOURNEY_STARTED = 'domain_journey_started';
    public const STEP_URL_DISCOVERED = 'url_discovered';
    public const STEP_URL_SCOPE_DECIDED = 'url_scope_decided';
    public const STEP_URL_DISCOVERY_COMPLETED = 'url_discovery_completed';
    public const STEP_PAGE_CAPTURE_COMPLETED = 'page_capture_completed';
    public const STEP_SOURCE_NORMALIZATION_COMPLETED = 'source_normalization_completed';
    public const STEP_STRUCTURED_EXTRACTION_COMPLETED = 'structured_extraction_completed';
    public const STEP_CONTEXT_CREATION_COMPLETED = 'context_creation_completed';
    public const STEP_INITIAL_CLASSIFICATION_COMPLETED = 'initial_classification_completed';
    public const STEP_MAPPING_INDEX_COMPLETED = 'mapping_index_completed';
    public const STEP_TARGET_TRANSFORM_COMPLETED = 'target_transform_completed';
    public const STEP_RECOMMENDED_MAPPINGS_FINALIZED = 'recommended_mappings_finalized';
    public const STEP_TARGET_OBJECT_INVENTORY_BUILT = 'target_object_inventory_built';
    public const STEP_TARGET_SCHEMA_CATALOG_BUILT = 'target_schema_catalog_built';
    public const STEP_TARGET_SCHEMA_SYNC_COMPLETED = 'target_schema_sync_completed';
    public const STEP_PATTERN_MEMORY_UPDATED = 'pattern_memory_updated';
    public const STEP_REVIEW_PRESENTED = 'review_presented';
    public const STEP_REVIEW_DECISION_SAVED = 'review_decision_saved';
    public const STEP_MANUAL_OVERRIDE_SAVED = 'manual_override_saved';
    public const STEP_QA_VALIDATION_COMPLETED = 'qa_validation_completed';
    public const STEP_PACKAGE_VALIDATION_COMPLETED = 'package_validation_completed';
    public const STEP_PACKAGE_READY = 'package_ready';
    public const STEP_PACKAGE_BUILT = 'package_built';
    public const STEP_STAGE_RERUN_REQUESTED = 'stage_rerun_requested';
    public const STEP_STAGE_RERUN_COMPLETED = 'stage_rerun_completed';

    public const RESOLUTION_MODE_UPDATE_EXISTING = 'update_existing';
    public const RESOLUTION_MODE_CREATE_NEW = 'create_new';
    public const RESOLUTION_MODE_BLOCKED = 'blocked_needs_review';
    public const RESOLUTION_MODE_SKIP = 'skip_out_of_scope';
    public const READINESS_STATUS_READY = 'ready_for_import';
    public const READINESS_STATUS_NEEDS_REVIEW = 'needs_review';
    public const READINESS_STATUS_BLOCKED = 'blocked';

    /**
     * @param string $domain
     * @return string
     */
    public static function generate_run_id($domain)
    {
        $domain_slug = sanitize_title_with_dashes(str_replace('.', '-', strtolower((string) $domain)));
        if ($domain_slug === '') {
            $domain_slug = 'unknown-domain';
        }

        $timestamp = gmdate('Ymd\THis\Z');
        $token = strtolower(substr(str_replace('-', '', wp_generate_uuid4()), 0, 6));

        return sprintf('ccv2_%s_%s_%s', $domain_slug, $timestamp, $token);
    }

    /**
     * @param string $run_id
     * @param int    $build_seq
     * @return string
     */
    public static function generate_package_id($run_id, $build_seq)
    {
        $run_id = sanitize_text_field((string) $run_id);
        $build_seq = max(1, absint($build_seq));

        return sprintf(
            'pkg_%s_%03d',
            preg_replace('/[^A-Za-z0-9_-]/', '_', $run_id),
            $build_seq
        );
    }

    /**
     * @return array<string, string>
     */
    public static function get_default_values()
    {
        return [
            self::OPTION_RUNTIME_VERSION => self::RUNTIME_V1,
            self::OPTION_AUTO_ACCEPT_MIN_CONFIDENCE => '0.92',
            self::OPTION_BLOCK_BELOW_CONFIDENCE => '0.55',
            self::OPTION_RESOLUTION_UPDATE_MIN_CONFIDENCE => '0.94',
            self::OPTION_PATTERN_REUSE_MIN_CONFIDENCE => '0.90',
            self::OPTION_REQUIRE_QA_PASS_FOR_AUTO_ACCEPT => '1',
            self::OPTION_REQUIRE_UNAMBIGUOUS_RESOLUTION_FOR_AUTO_ACCEPT => '1',
            self::OPTION_REQUIRE_MANUAL_REVIEW_FOR_OBJECT_FAMILY_CHANGE => '1',
        ];
    }

    /**
     * @return void
     */
    public static function ensure_defaults()
    {
        foreach (self::get_default_values() as $option_key => $default_value) {
            add_option($option_key, $default_value);
        }
    }

    /**
     * @return array<int, string>
     */
    public static function get_allowed_runtimes()
    {
        return [
            self::RUNTIME_V1,
            self::RUNTIME_V2,
        ];
    }

    /**
     * @return bool
     */
    public static function is_addon_enabled()
    {
        return get_option(self::OPTION_ADDON_ENABLED, '1') === '1';
    }

    /**
     * @return string
     */
    public static function get_runtime_version()
    {
        $runtime_version = (string) get_option(self::OPTION_RUNTIME_VERSION, self::RUNTIME_V1);

        return in_array($runtime_version, self::get_allowed_runtimes(), true)
            ? $runtime_version
            : self::RUNTIME_V1;
    }

    /**
     * @return bool
     */
    public static function is_v2_runtime_selected()
    {
        return self::is_addon_enabled() && self::get_runtime_version() === self::RUNTIME_V2;
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_automation_settings()
    {
        $defaults = self::get_default_values();

        return [
            'autoAcceptMinConfidence' => (float) get_option(self::OPTION_AUTO_ACCEPT_MIN_CONFIDENCE, $defaults[self::OPTION_AUTO_ACCEPT_MIN_CONFIDENCE]),
            'blockBelowConfidence' => (float) get_option(self::OPTION_BLOCK_BELOW_CONFIDENCE, $defaults[self::OPTION_BLOCK_BELOW_CONFIDENCE]),
            'resolutionUpdateMinConfidence' => (float) get_option(self::OPTION_RESOLUTION_UPDATE_MIN_CONFIDENCE, $defaults[self::OPTION_RESOLUTION_UPDATE_MIN_CONFIDENCE]),
            'patternReuseMinConfidence' => (float) get_option(self::OPTION_PATTERN_REUSE_MIN_CONFIDENCE, $defaults[self::OPTION_PATTERN_REUSE_MIN_CONFIDENCE]),
            'requireQaPassForAutoAccept' => get_option(self::OPTION_REQUIRE_QA_PASS_FOR_AUTO_ACCEPT, $defaults[self::OPTION_REQUIRE_QA_PASS_FOR_AUTO_ACCEPT]) === '1',
            'requireUnambiguousResolutionForAutoAccept' => get_option(self::OPTION_REQUIRE_UNAMBIGUOUS_RESOLUTION_FOR_AUTO_ACCEPT, $defaults[self::OPTION_REQUIRE_UNAMBIGUOUS_RESOLUTION_FOR_AUTO_ACCEPT]) === '1',
            'requireManualReviewForObjectFamilyChange' => get_option(self::OPTION_REQUIRE_MANUAL_REVIEW_FOR_OBJECT_FAMILY_CHANGE, $defaults[self::OPTION_REQUIRE_MANUAL_REVIEW_FOR_OBJECT_FAMILY_CHANGE]) === '1',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function get_supported_ai_stages()
    {
        return [
            self::AI_STAGE_CONTEXT_CREATION,
            self::AI_STAGE_INITIAL_CLASSIFICATION,
            self::AI_STAGE_MAPPING_INDEX,
            self::AI_STAGE_RECOMMENDATION_FINALIZATION,
        ];
    }

    /**
     * @param string $stage
     * @return bool
     */
    public static function is_supported_ai_stage($stage)
    {
        return in_array(sanitize_key((string) $stage), self::get_supported_ai_stages(), true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_ai_runtime_settings()
    {
        $settings = DBVC_CC_Settings_Service::get_options();

        return [
            'prompt_version' => isset($settings['prompt_version']) ? sanitize_text_field((string) $settings['prompt_version']) : 'v1',
            'model' => isset($settings['openai_model']) ? sanitize_text_field((string) $settings['openai_model']) : 'gpt-4o-mini',
            'fallback_mode' => self::AI_FALLBACK_MODE,
            'stage_budgets' => [
                self::AI_STAGE_CONTEXT_CREATION => [
                    'timeout_seconds' => 45,
                    'retry_count' => 1,
                    'backoff_seconds' => 5,
                ],
                self::AI_STAGE_INITIAL_CLASSIFICATION => [
                    'timeout_seconds' => 30,
                    'retry_count' => 1,
                    'backoff_seconds' => 5,
                ],
                self::AI_STAGE_MAPPING_INDEX => [
                    'timeout_seconds' => 45,
                    'retry_count' => 1,
                    'backoff_seconds' => 5,
                ],
                self::AI_STAGE_RECOMMENDATION_FINALIZATION => [
                    'timeout_seconds' => 45,
                    'retry_count' => 1,
                    'backoff_seconds' => 5,
                ],
            ],
        ];
    }

    /**
     * @param string $stage
     * @return array<string, mixed>
     */
    public static function get_ai_stage_budget($stage)
    {
        $runtime = self::get_ai_runtime_settings();
        $stage = sanitize_key((string) $stage);

        return isset($runtime['stage_budgets'][$stage]) && is_array($runtime['stage_budgets'][$stage])
            ? $runtime['stage_budgets'][$stage]
            : [
                'timeout_seconds' => 45,
                'retry_count' => 1,
                'backoff_seconds' => 5,
            ];
    }
}
