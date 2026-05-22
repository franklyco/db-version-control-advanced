<?php

namespace Dbvc\ConfigurationPortability\Providers;

use Dbvc\ConfigurationPortability\AbstractOptionDomainProvider;
use Dbvc\ConfigurationPortability\Field;

if (! defined('WPINC')) {
    die;
}

final class MaskingProvider extends AbstractOptionDomainProvider
{
    public function get_key(): string
    {
        return 'masking';
    }

    public function get_label(): string
    {
        return __('Masking Defaults', 'dbvc');
    }

    public function get_version(): int
    {
        return 1;
    }

    public function get_groups(): array
    {
        return [
            'defaults' => [
                'label' => __('Masking Defaults', 'dbvc'),
                'fields' => [
                    'dbvc_mask_defaults_meta_keys',
                    'dbvc_mask_defaults_subkeys',
                    'dbvc_mask_post_fields',
                    'dbvc_auto_export_mask_mode',
                    'dbvc_auto_export_mask_placeholder',
                ],
            ],
            'runtime' => [
                'label' => __('Runtime Masking State', 'dbvc'),
                'fields' => [
                    'dbvc_export_last_mask_mode',
                    'dbvc_mask_action',
                    'dbvc_mask_meta_keys',
                    'dbvc_mask_subkeys',
                    'dbvc_mask_placeholder',
                ],
            ],
        ];
    }

    public function get_fields(): array
    {
        $advanced = [
            'default_export' => Field::POLICY_ADVANCED,
            'environment_policy' => Field::POLICY_ADVANCED,
        ];

        return [
            'dbvc_mask_defaults_meta_keys' => Field::textarea('dbvc_mask_defaults_meta_keys', __('Default meta keys to mask', 'dbvc'), 'defaults'),
            'dbvc_mask_defaults_subkeys' => Field::textarea('dbvc_mask_defaults_subkeys', __('Default nested paths to mask', 'dbvc'), 'defaults'),
            'dbvc_mask_post_fields' => Field::key_list('dbvc_mask_post_fields', __('Post fields to mask', 'dbvc'), 'defaults'),
            'dbvc_auto_export_mask_mode' => Field::select('dbvc_auto_export_mask_mode', __('Automatic export masking mode', 'dbvc'), 'defaults', 'none', ['none', 'remove_defaults', 'redact_defaults']),
            'dbvc_auto_export_mask_placeholder' => Field::text('dbvc_auto_export_mask_placeholder', __('Automatic export redaction placeholder', 'dbvc'), 'defaults', '***'),
            'dbvc_export_last_mask_mode' => Field::select('dbvc_export_last_mask_mode', __('Last manual export mask mode', 'dbvc'), 'runtime', 'none', ['none', 'remove_defaults', 'remove_defaults_with_post_fields', 'remove_customize', 'redact_custom'], $advanced),
            'dbvc_mask_action' => Field::select('dbvc_mask_action', __('Runtime mask action', 'dbvc'), 'runtime', 'remove', ['remove', 'redact'], $advanced),
            'dbvc_mask_meta_keys' => Field::textarea('dbvc_mask_meta_keys', __('Runtime meta keys to mask', 'dbvc'), 'runtime', '', $advanced),
            'dbvc_mask_subkeys' => Field::textarea('dbvc_mask_subkeys', __('Runtime nested paths to mask', 'dbvc'), 'runtime', '', $advanced),
            'dbvc_mask_placeholder' => Field::text('dbvc_mask_placeholder', __('Runtime redaction placeholder', 'dbvc'), 'runtime', '***', $advanced),
        ];
    }
}
