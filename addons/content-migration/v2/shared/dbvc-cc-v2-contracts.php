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
}
