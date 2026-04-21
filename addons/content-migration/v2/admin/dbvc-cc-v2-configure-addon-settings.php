<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Configure_Addon_Settings
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_settings_groups()
    {
        return [
            'activation' => [
                'label' => __('Activation', 'dbvc'),
                'fields' => [
                    DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED,
                    DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION,
                ],
            ],
            'automation' => [
                'label' => __('Advanced V2 Automation', 'dbvc'),
                'fields' => [
                    DBVC_CC_V2_Contracts::OPTION_AUTO_ACCEPT_MIN_CONFIDENCE,
                    DBVC_CC_V2_Contracts::OPTION_BLOCK_BELOW_CONFIDENCE,
                    DBVC_CC_V2_Contracts::OPTION_RESOLUTION_UPDATE_MIN_CONFIDENCE,
                    DBVC_CC_V2_Contracts::OPTION_PATTERN_REUSE_MIN_CONFIDENCE,
                    DBVC_CC_V2_Contracts::OPTION_REQUIRE_QA_PASS_FOR_AUTO_ACCEPT,
                    DBVC_CC_V2_Contracts::OPTION_REQUIRE_UNAMBIGUOUS_RESOLUTION_FOR_AUTO_ACCEPT,
                    DBVC_CC_V2_Contracts::OPTION_REQUIRE_MANUAL_REVIEW_FOR_OBJECT_FAMILY_CHANGE,
                ],
            ],
            'field_context' => [
                'label' => __('Vertical Field Context', 'dbvc'),
                'fields' => [
                    DBVC_CC_V2_Contracts::OPTION_FIELD_CONTEXT_INTEGRATION_MODE,
                    DBVC_CC_V2_Contracts::OPTION_FIELD_CONTEXT_USE_LEGACY_FALLBACK,
                    DBVC_CC_V2_Contracts::OPTION_FIELD_CONTEXT_WARN_ON_DEGRADED,
                    DBVC_CC_V2_Contracts::OPTION_FIELD_CONTEXT_BLOCK_ON_MISSING,
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_field_meta()
    {
        return [
            DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED => [
                'label' => __('Enable Content Collector', 'dbvc'),
                'input' => 'checkbox',
                'help' => __('Turn on to register Content Collector runtime pages, routes, and assets. Turn off to keep the addon dormant.', 'dbvc'),
            ],
            DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION => [
                'label' => __('Runtime Version', 'dbvc'),
                'input' => 'select',
                'help' => __('Choose which Content Collector runtime powers the existing submenu footprint when the addon is enabled.', 'dbvc'),
                'options' => [
                    DBVC_CC_V2_Contracts::RUNTIME_V1 => __('v1', 'dbvc'),
                    DBVC_CC_V2_Contracts::RUNTIME_V2 => __('v2', 'dbvc'),
                ],
            ],
            DBVC_CC_V2_Contracts::OPTION_AUTO_ACCEPT_MIN_CONFIDENCE => [
                'label' => __('Auto-accept minimum confidence', 'dbvc'),
                'input' => 'number',
                'help' => __('Confidence at or above this threshold may auto-accept when the V2 policy gates also pass.', 'dbvc'),
                'min' => '0',
                'max' => '1',
                'step' => '0.01',
            ],
            DBVC_CC_V2_Contracts::OPTION_BLOCK_BELOW_CONFIDENCE => [
                'label' => __('Block below confidence', 'dbvc'),
                'input' => 'number',
                'help' => __('Confidence below this threshold should stay blocked instead of flowing forward automatically.', 'dbvc'),
                'min' => '0',
                'max' => '1',
                'step' => '0.01',
            ],
            DBVC_CC_V2_Contracts::OPTION_RESOLUTION_UPDATE_MIN_CONFIDENCE => [
                'label' => __('Resolution update minimum confidence', 'dbvc'),
                'input' => 'number',
                'help' => __('Minimum confidence required before V2 can update target resolution automatically.', 'dbvc'),
                'min' => '0',
                'max' => '1',
                'step' => '0.01',
            ],
            DBVC_CC_V2_Contracts::OPTION_PATTERN_REUSE_MIN_CONFIDENCE => [
                'label' => __('Pattern reuse minimum confidence', 'dbvc'),
                'input' => 'number',
                'help' => __('Minimum confidence required before domain-scoped patterns can be reused automatically.', 'dbvc'),
                'min' => '0',
                'max' => '1',
                'step' => '0.01',
            ],
            DBVC_CC_V2_Contracts::OPTION_REQUIRE_QA_PASS_FOR_AUTO_ACCEPT => [
                'label' => __('Require QA pass for auto-accept', 'dbvc'),
                'input' => 'checkbox',
                'help' => __('Auto-accept is blocked unless QA succeeds.', 'dbvc'),
            ],
            DBVC_CC_V2_Contracts::OPTION_REQUIRE_UNAMBIGUOUS_RESOLUTION_FOR_AUTO_ACCEPT => [
                'label' => __('Require unambiguous resolution for auto-accept', 'dbvc'),
                'input' => 'checkbox',
                'help' => __('Auto-accept is blocked when target resolution remains ambiguous.', 'dbvc'),
            ],
            DBVC_CC_V2_Contracts::OPTION_REQUIRE_MANUAL_REVIEW_FOR_OBJECT_FAMILY_CHANGE => [
                'label' => __('Require manual review for object family change', 'dbvc'),
                'input' => 'checkbox',
                'help' => __('Manual review is required when V2 predicts an object family change relative to prior accepted decisions.', 'dbvc'),
            ],
            DBVC_CC_V2_Contracts::OPTION_FIELD_CONTEXT_INTEGRATION_MODE => [
                'label' => __('Field Context integration mode', 'dbvc'),
                'input' => 'select',
                'help' => __('Choose how Content Collector reads VerticalFramework resolved Field Context for target mapping and QA.', 'dbvc'),
                'options' => [
                    DBVC_CC_V2_Contracts::FIELD_CONTEXT_MODE_AUTO => __('Auto', 'dbvc'),
                    DBVC_CC_V2_Contracts::FIELD_CONTEXT_MODE_LOCAL => __('Local only', 'dbvc'),
                    DBVC_CC_V2_Contracts::FIELD_CONTEXT_MODE_REMOTE => __('Remote only', 'dbvc'),
                    DBVC_CC_V2_Contracts::FIELD_CONTEXT_MODE_OFF => __('Off', 'dbvc'),
                ],
            ],
            DBVC_CC_V2_Contracts::OPTION_FIELD_CONTEXT_USE_LEGACY_FALLBACK => [
                'label' => __('Use legacy context fallback', 'dbvc'),
                'input' => 'checkbox',
                'help' => __('Allow legacy GardenAI field hints only as fallback evidence when universal resolved purpose is missing.', 'dbvc'),
            ],
            DBVC_CC_V2_Contracts::OPTION_FIELD_CONTEXT_WARN_ON_DEGRADED => [
                'label' => __('Warn on degraded Field Context', 'dbvc'),
                'input' => 'checkbox',
                'help' => __('Add review warnings when provider data is missing, stale, legacy-only, non-writable, or clone-projected.', 'dbvc'),
            ],
            DBVC_CC_V2_Contracts::OPTION_FIELD_CONTEXT_BLOCK_ON_MISSING => [
                'label' => __('Block when Field Context is missing', 'dbvc'),
                'input' => 'checkbox',
                'help' => __('Treat missing or partial Field Context as a blocking condition for strict migration runs.', 'dbvc'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function get_all_settings()
    {
        $defaults = DBVC_CC_V2_Contracts::get_default_values();
        $values = [
            DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED => get_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1'),
        ];

        foreach ($defaults as $option_key => $default_value) {
            $values[$option_key] = (string) get_option($option_key, $default_value);
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $request_data
     * @return array<string, mixed>
     */
    public static function save_settings(array $request_data)
    {
        $current = self::get_all_settings();
        $values = [];
        $errors = [];

        $values[DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED] = isset($request_data[DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED]) ? '1' : '0';

        $runtime_version = isset($request_data[DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION])
            ? sanitize_key((string) wp_unslash($request_data[DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION]))
            : $current[DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION];
        if (! in_array($runtime_version, DBVC_CC_V2_Contracts::get_allowed_runtimes(), true)) {
            $runtime_version = DBVC_CC_V2_Contracts::RUNTIME_V1;
            $errors[] = __('Content Collector runtime version must be `v1` or `v2`.', 'dbvc');
        }
        $values[DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION] = $runtime_version;

        $field_context_mode = isset($request_data[DBVC_CC_V2_Contracts::OPTION_FIELD_CONTEXT_INTEGRATION_MODE])
            ? sanitize_key((string) wp_unslash($request_data[DBVC_CC_V2_Contracts::OPTION_FIELD_CONTEXT_INTEGRATION_MODE]))
            : $current[DBVC_CC_V2_Contracts::OPTION_FIELD_CONTEXT_INTEGRATION_MODE];
        if (! in_array($field_context_mode, DBVC_CC_V2_Contracts::get_allowed_field_context_modes(), true)) {
            $field_context_mode = DBVC_CC_V2_Contracts::FIELD_CONTEXT_MODE_AUTO;
            $errors[] = __('Field Context integration mode must be auto, local, remote, or off.', 'dbvc');
        }
        $values[DBVC_CC_V2_Contracts::OPTION_FIELD_CONTEXT_INTEGRATION_MODE] = $field_context_mode;

        foreach (self::get_confidence_option_keys() as $option_key) {
            $field_value = isset($request_data[$option_key])
                ? (string) wp_unslash($request_data[$option_key])
                : $current[$option_key];
            $sanitized = self::sanitize_confidence_value($field_value);
            if ($sanitized === null) {
                $values[$option_key] = $current[$option_key];
                $errors[] = sprintf(__('Invalid confidence value for `%s`.', 'dbvc'), $option_key);
                continue;
            }
            $values[$option_key] = $sanitized;
        }

        foreach (self::get_policy_checkbox_option_keys() as $option_key) {
            $values[$option_key] = isset($request_data[$option_key]) ? '1' : '0';
        }

        foreach ($values as $option_key => $option_value) {
            update_option($option_key, $option_value);
        }

        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();

        return [
            'values' => $values,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function get_confidence_option_keys()
    {
        return [
            DBVC_CC_V2_Contracts::OPTION_AUTO_ACCEPT_MIN_CONFIDENCE,
            DBVC_CC_V2_Contracts::OPTION_BLOCK_BELOW_CONFIDENCE,
            DBVC_CC_V2_Contracts::OPTION_RESOLUTION_UPDATE_MIN_CONFIDENCE,
            DBVC_CC_V2_Contracts::OPTION_PATTERN_REUSE_MIN_CONFIDENCE,
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function get_policy_checkbox_option_keys()
    {
        return [
            DBVC_CC_V2_Contracts::OPTION_REQUIRE_QA_PASS_FOR_AUTO_ACCEPT,
            DBVC_CC_V2_Contracts::OPTION_REQUIRE_UNAMBIGUOUS_RESOLUTION_FOR_AUTO_ACCEPT,
            DBVC_CC_V2_Contracts::OPTION_REQUIRE_MANUAL_REVIEW_FOR_OBJECT_FAMILY_CHANGE,
            DBVC_CC_V2_Contracts::OPTION_FIELD_CONTEXT_USE_LEGACY_FALLBACK,
            DBVC_CC_V2_Contracts::OPTION_FIELD_CONTEXT_WARN_ON_DEGRADED,
            DBVC_CC_V2_Contracts::OPTION_FIELD_CONTEXT_BLOCK_ON_MISSING,
        ];
    }

    /**
     * @param string $raw_value
     * @return string|null
     */
    private static function sanitize_confidence_value($raw_value)
    {
        $raw_value = trim((string) $raw_value);
        if ($raw_value === '' || ! is_numeric($raw_value)) {
            return null;
        }

        $float_value = (float) $raw_value;
        if ($float_value < 0.0 || $float_value > 1.0) {
            return null;
        }

        $formatted = number_format($float_value, 2, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
