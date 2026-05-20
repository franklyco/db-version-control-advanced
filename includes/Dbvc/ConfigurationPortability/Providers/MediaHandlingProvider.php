<?php

namespace Dbvc\ConfigurationPortability\Providers;

use Dbvc\ConfigurationPortability\AbstractOptionDomainProvider;
use Dbvc\ConfigurationPortability\Field;

if (! defined('WPINC')) {
    die;
}

final class MediaHandlingProvider extends AbstractOptionDomainProvider
{
    /**
     * @return string
     */
    public function get_key(): string
    {
        return 'media_handling';
    }

    /**
     * @return string
     */
    public function get_label(): string
    {
        return __('Media Handling', 'dbvc');
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
            'media' => [
                'label' => __('Media Handling', 'dbvc'),
                'fields' => [
                    \DBVC_Media_Sync::OPTION_ENABLED,
                    \DBVC_Media_Sync::OPTION_PRESERVE_NAMES,
                    \DBVC_Media_Sync::OPTION_PREVIEW_ENABLED,
                    \DBVC_Media_Sync::OPTION_ALLOW_EXTERNAL,
                    \DBVC_Media_Sync::OPTION_TRANSPORT_MODE,
                    \DBVC_Media_Sync::OPTION_BUNDLE_ENABLED,
                    \DBVC_Media_Sync::OPTION_BUNDLE_CHUNK,
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
            \DBVC_Media_Sync::OPTION_ENABLED => Field::bool(\DBVC_Media_Sync::OPTION_ENABLED, __('Retrieve missing media during import', 'dbvc'), 'media', '0'),
            \DBVC_Media_Sync::OPTION_PRESERVE_NAMES => Field::bool(\DBVC_Media_Sync::OPTION_PRESERVE_NAMES, __('Preserve source filenames', 'dbvc'), 'media', '1'),
            \DBVC_Media_Sync::OPTION_PREVIEW_ENABLED => Field::bool(\DBVC_Media_Sync::OPTION_PREVIEW_ENABLED, __('Enable media preview', 'dbvc'), 'media', '0'),
            \DBVC_Media_Sync::OPTION_ALLOW_EXTERNAL => Field::bool(
                \DBVC_Media_Sync::OPTION_ALLOW_EXTERNAL,
                __('Allow external media hosts', 'dbvc'),
                'media',
                '0',
                [
                    'requires_confirmation' => true,
                    'description' => __('May allow downloads from hosts outside the source or target site.', 'dbvc'),
                ]
            ),
            \DBVC_Media_Sync::OPTION_TRANSPORT_MODE => Field::select(
                \DBVC_Media_Sync::OPTION_TRANSPORT_MODE,
                __('Media transport mode', 'dbvc'),
                'media',
                'auto',
                ['auto', 'bundled', 'remote']
            ),
            \DBVC_Media_Sync::OPTION_BUNDLE_ENABLED => Field::bool(\DBVC_Media_Sync::OPTION_BUNDLE_ENABLED, __('Bundle media in exports', 'dbvc'), 'media', '0'),
            \DBVC_Media_Sync::OPTION_BUNDLE_CHUNK => Field::integer(\DBVC_Media_Sync::OPTION_BUNDLE_CHUNK, __('Media bundle chunk size', 'dbvc'), 'media', 250, 10, 5000),
        ];
    }
}
