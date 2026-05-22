<?php

namespace Dbvc\ConfigurationPortability\Providers;

use Dbvc\ConfigurationPortability\AbstractOptionDomainProvider;
use Dbvc\ConfigurationPortability\Field;

if (! defined('WPINC')) {
    die;
}

final class ContentCollectorRuntimeProvider extends AbstractOptionDomainProvider
{
    public function get_key(): string
    {
        return 'content_collector_runtime';
    }

    public function get_label(): string
    {
        return __('Content Collector Runtime', 'dbvc');
    }

    public function get_version(): int
    {
        return 1;
    }

    public function get_groups(): array
    {
        if (! class_exists('DBVC_CC_Contracts') || ! class_exists('DBVC_CC_V2_Contracts')) {
            return [];
        }

        return [
            'activation' => [
                'label' => __('Activation', 'dbvc'),
                'fields' => [
                    \DBVC_CC_Contracts::OPTION_ADDON_ENABLED,
                    \DBVC_CC_Contracts::OPTION_GLOBAL_KILL_SWITCH,
                    \DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION,
                ],
            ],
            'feature_flags' => [
                'label' => __('Feature Flags', 'dbvc'),
                'fields' => array_keys(\DBVC_CC_Contracts::get_feature_flag_defaults()),
            ],
            'automation' => [
                'label' => __('V2 Automation', 'dbvc'),
                'fields' => [
                    \DBVC_CC_V2_Contracts::OPTION_AUTO_ACCEPT_MIN_CONFIDENCE,
                    \DBVC_CC_V2_Contracts::OPTION_BLOCK_BELOW_CONFIDENCE,
                    \DBVC_CC_V2_Contracts::OPTION_RESOLUTION_UPDATE_MIN_CONFIDENCE,
                    \DBVC_CC_V2_Contracts::OPTION_PATTERN_REUSE_MIN_CONFIDENCE,
                    \DBVC_CC_V2_Contracts::OPTION_REQUIRE_QA_PASS_FOR_AUTO_ACCEPT,
                    \DBVC_CC_V2_Contracts::OPTION_REQUIRE_UNAMBIGUOUS_RESOLUTION_FOR_AUTO_ACCEPT,
                    \DBVC_CC_V2_Contracts::OPTION_REQUIRE_MANUAL_REVIEW_FOR_OBJECT_FAMILY_CHANGE,
                ],
            ],
            'import_policy' => [
                'label' => __('Import Policy', 'dbvc'),
                'fields' => [
                    \DBVC_CC_Contracts::IMPORT_POLICY_DRY_RUN_REQUIRED,
                    \DBVC_CC_Contracts::IMPORT_POLICY_IDEMPOTENT_UPSERT,
                ],
            ],
            'workflow_state' => [
                'label' => __('Workflow State', 'dbvc'),
                'fields' => [
                    \DBVC_CC_Contracts::OPTION_SCRUB_POLICY_APPROVAL_STATUS,
                ],
            ],
        ];
    }

    public function get_fields(): array
    {
        if (! class_exists('DBVC_CC_Contracts') || ! class_exists('DBVC_CC_V2_Contracts')) {
            return [];
        }

        $defaults = \DBVC_CC_V2_Contracts::get_default_values();
        $fields = [
            \DBVC_CC_Contracts::OPTION_ADDON_ENABLED => Field::bool(\DBVC_CC_Contracts::OPTION_ADDON_ENABLED, __('Enable Content Collector', 'dbvc'), 'activation', '1', ['requires_confirmation' => true]),
            \DBVC_CC_Contracts::OPTION_GLOBAL_KILL_SWITCH => Field::bool(\DBVC_CC_Contracts::OPTION_GLOBAL_KILL_SWITCH, __('Global kill switch', 'dbvc'), 'activation', '0', ['requires_confirmation' => true]),
            \DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION => Field::select(\DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, __('Runtime version', 'dbvc'), 'activation', \DBVC_CC_V2_Contracts::RUNTIME_V1, \DBVC_CC_V2_Contracts::get_allowed_runtimes(), ['requires_confirmation' => true]),
            \DBVC_CC_V2_Contracts::OPTION_AUTO_ACCEPT_MIN_CONFIDENCE => Field::decimal(\DBVC_CC_V2_Contracts::OPTION_AUTO_ACCEPT_MIN_CONFIDENCE, __('Auto-accept minimum confidence', 'dbvc'), 'automation', (string) ($defaults[\DBVC_CC_V2_Contracts::OPTION_AUTO_ACCEPT_MIN_CONFIDENCE] ?? '0.92'), 0.0, 1.0),
            \DBVC_CC_V2_Contracts::OPTION_BLOCK_BELOW_CONFIDENCE => Field::decimal(\DBVC_CC_V2_Contracts::OPTION_BLOCK_BELOW_CONFIDENCE, __('Block below confidence', 'dbvc'), 'automation', (string) ($defaults[\DBVC_CC_V2_Contracts::OPTION_BLOCK_BELOW_CONFIDENCE] ?? '0.55'), 0.0, 1.0),
            \DBVC_CC_V2_Contracts::OPTION_RESOLUTION_UPDATE_MIN_CONFIDENCE => Field::decimal(\DBVC_CC_V2_Contracts::OPTION_RESOLUTION_UPDATE_MIN_CONFIDENCE, __('Resolution update minimum confidence', 'dbvc'), 'automation', (string) ($defaults[\DBVC_CC_V2_Contracts::OPTION_RESOLUTION_UPDATE_MIN_CONFIDENCE] ?? '0.94'), 0.0, 1.0),
            \DBVC_CC_V2_Contracts::OPTION_PATTERN_REUSE_MIN_CONFIDENCE => Field::decimal(\DBVC_CC_V2_Contracts::OPTION_PATTERN_REUSE_MIN_CONFIDENCE, __('Pattern reuse minimum confidence', 'dbvc'), 'automation', (string) ($defaults[\DBVC_CC_V2_Contracts::OPTION_PATTERN_REUSE_MIN_CONFIDENCE] ?? '0.90'), 0.0, 1.0),
            \DBVC_CC_V2_Contracts::OPTION_REQUIRE_QA_PASS_FOR_AUTO_ACCEPT => Field::bool(\DBVC_CC_V2_Contracts::OPTION_REQUIRE_QA_PASS_FOR_AUTO_ACCEPT, __('Require QA pass for auto-accept', 'dbvc'), 'automation', (string) ($defaults[\DBVC_CC_V2_Contracts::OPTION_REQUIRE_QA_PASS_FOR_AUTO_ACCEPT] ?? '1')),
            \DBVC_CC_V2_Contracts::OPTION_REQUIRE_UNAMBIGUOUS_RESOLUTION_FOR_AUTO_ACCEPT => Field::bool(\DBVC_CC_V2_Contracts::OPTION_REQUIRE_UNAMBIGUOUS_RESOLUTION_FOR_AUTO_ACCEPT, __('Require unambiguous resolution for auto-accept', 'dbvc'), 'automation', (string) ($defaults[\DBVC_CC_V2_Contracts::OPTION_REQUIRE_UNAMBIGUOUS_RESOLUTION_FOR_AUTO_ACCEPT] ?? '1')),
            \DBVC_CC_V2_Contracts::OPTION_REQUIRE_MANUAL_REVIEW_FOR_OBJECT_FAMILY_CHANGE => Field::bool(\DBVC_CC_V2_Contracts::OPTION_REQUIRE_MANUAL_REVIEW_FOR_OBJECT_FAMILY_CHANGE, __('Require manual review for object family change', 'dbvc'), 'automation', (string) ($defaults[\DBVC_CC_V2_Contracts::OPTION_REQUIRE_MANUAL_REVIEW_FOR_OBJECT_FAMILY_CHANGE] ?? '1')),
            \DBVC_CC_Contracts::IMPORT_POLICY_DRY_RUN_REQUIRED => Field::bool(\DBVC_CC_Contracts::IMPORT_POLICY_DRY_RUN_REQUIRED, __('Dry run required before import', 'dbvc'), 'import_policy', '1'),
            \DBVC_CC_Contracts::IMPORT_POLICY_IDEMPOTENT_UPSERT => Field::bool(\DBVC_CC_Contracts::IMPORT_POLICY_IDEMPOTENT_UPSERT, __('Use idempotent upsert import policy', 'dbvc'), 'import_policy', '1'),
            \DBVC_CC_Contracts::OPTION_SCRUB_POLICY_APPROVAL_STATUS => Field::map(\DBVC_CC_Contracts::OPTION_SCRUB_POLICY_APPROVAL_STATUS, __('Scrub policy approval status', 'dbvc'), 'workflow_state', [], [
                'default_export' => Field::POLICY_ADVANCED,
                'environment_policy' => Field::POLICY_ADVANCED,
                'requires_confirmation' => true,
            ]),
        ];

        foreach (\DBVC_CC_Contracts::get_feature_flag_defaults() as $option_key => $default_value) {
            $fields[$option_key] = Field::bool($option_key, $this->feature_flag_label($option_key), 'feature_flags', (string) $default_value, ['requires_confirmation' => true]);
        }

        return $fields;
    }

    protected function after_apply(array $applied): void
    {
        unset($applied);

        if (class_exists('DBVC_CC_V2_Runtime_Registrar')) {
            \DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        }
    }

    /**
     * @param string $option_key
     * @return string
     */
    private function feature_flag_label($option_key): string
    {
        $label = preg_replace('/^dbvc_cc_flag_/', '', (string) $option_key);
        $label = str_replace('_', ' ', (string) $label);

        return ucwords($label);
    }
}
