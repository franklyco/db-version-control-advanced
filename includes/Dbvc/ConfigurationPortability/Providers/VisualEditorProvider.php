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
        return 2;
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
            'toolbar' => [
                'label' => __('Toolbar 2.0', 'dbvc'),
                'fields' => [
                    \DBVC_Visual_Editor_Addon::OPTION_SHARED_GLOBAL_FIELD_NAMES,
                ],
            ],
            'visibility' => [
                'label' => __('Frontend Content Visibility', 'dbvc'),
                'fields' => [
                    \DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES,
                    \DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES,
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
            \DBVC_Visual_Editor_Addon::OPTION_SHARED_GLOBAL_FIELD_NAMES => Field::textarea(
                \DBVC_Visual_Editor_Addon::OPTION_SHARED_GLOBAL_FIELD_NAMES,
                __('Shared global option field names', 'dbvc'),
                'toolbar',
                \DBVC_Visual_Editor_Addon::DEFAULT_SHARED_GLOBAL_FIELD_NAMES,
                [
                    'description' => __('ACF options field names available in the Toolbar Shared Globals panel.', 'dbvc'),
                ]
            ),
            \DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES => Field::textarea(
                \DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_POST_TYPES,
                __('Excluded post types', 'dbvc'),
                'visibility',
                \DBVC_Visual_Editor_Addon::DEFAULT_EXCLUDED_POST_TYPES,
                [
                    'description' => __('Post type slugs omitted from Visual Editor frontend object navigation, descriptors, and connected-item searches.', 'dbvc'),
                ]
            ),
            \DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES => Field::textarea(
                \DBVC_Visual_Editor_Addon::OPTION_EXCLUDED_TAXONOMIES,
                __('Excluded taxonomies', 'dbvc'),
                'visibility',
                \DBVC_Visual_Editor_Addon::DEFAULT_EXCLUDED_TAXONOMIES,
                [
                    'description' => __('Taxonomy slugs omitted from Visual Editor frontend object navigation, descriptors, and linked-term searches.', 'dbvc'),
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
