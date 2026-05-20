<?php

namespace Dbvc\ConfigurationPortability\Providers;

use Dbvc\ConfigurationPortability\AbstractOptionDomainProvider;
use Dbvc\ConfigurationPortability\Field;

if (! defined('WPINC')) {
    die;
}

final class VisualEditorProvider extends AbstractOptionDomainProvider
{
    /**
     * @return string
     */
    public function get_key(): string
    {
        return 'visual_editor';
    }

    /**
     * @return string
     */
    public function get_label(): string
    {
        return __('Visual Editor', 'dbvc');
    }

    /**
     * @return int
     */
    public function get_version(): int
    {
        return 1;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get_groups(): array
    {
        return [
            'activation' => [
                'label' => __('Activation', 'dbvc'),
                'fields' => [
                    \DBVC_Visual_Editor_Addon::OPTION_ENABLED,
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get_fields(): array
    {
        return [
            \DBVC_Visual_Editor_Addon::OPTION_ENABLED => Field::bool(
                \DBVC_Visual_Editor_Addon::OPTION_ENABLED,
                __('Enable Visual Editor', 'dbvc'),
                'activation',
                '0',
                [
                    'requires_confirmation' => true,
                    'description' => __('Controls whether the authenticated frontend Visual Editor runtime is registered.', 'dbvc'),
                ]
            ),
        ];
    }

    /**
     * @param array<int|string, mixed> $applied
     * @return void
     */
    protected function after_apply(array $applied): void
    {
        unset($applied);

        if (class_exists('DBVC_Visual_Editor_Addon')) {
            \DBVC_Visual_Editor_Addon::refresh_runtime_registration();
        }
    }
}
