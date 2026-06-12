<?php

namespace Dbvc\ConfigurationPortability\Providers;

use Dbvc\ConfigurationPortability\AbstractOptionDomainProvider;
use Dbvc\ConfigurationPortability\Field;
use Dbvc\Media\Hydration\Settings as HydrationSettings;

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
            'media_hydration' => [
                'label' => __('Media Hydration', 'dbvc'),
                'fields' => [
                    HydrationSettings::OPTION_ENABLED,
                    HydrationSettings::OPTION_SOURCE,
                    HydrationSettings::OPTION_MATCH_POLICY,
                    HydrationSettings::OPTION_OVERWRITE_POLICY,
                    HydrationSettings::OPTION_METADATA_POLICY,
                    HydrationSettings::OPTION_ALLOWED_MIME_GROUPS,
                    HydrationSettings::OPTION_BATCH_SIZE,
                    HydrationSettings::OPTION_REQUIRE_DRY_RUN,
                    HydrationSettings::OPTION_RECEIPTS_ENABLED,
                    HydrationSettings::OPTION_STRICT_HASHES,
                    HydrationSettings::OPTION_CLONE_CONFIRMATION,
                    HydrationSettings::OPTION_LOCK_TIMEOUT_MINUTES,
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
            HydrationSettings::OPTION_ENABLED => Field::bool(HydrationSettings::OPTION_ENABLED, __('Enable media hydration workflow', 'dbvc'), 'media_hydration', '0'),
            HydrationSettings::OPTION_SOURCE => Field::select(HydrationSettings::OPTION_SOURCE, __('Hydration source', 'dbvc'), 'media_hydration', 'bundle_only', HydrationSettings::allowed_sources()),
            HydrationSettings::OPTION_MATCH_POLICY => Field::select(HydrationSettings::OPTION_MATCH_POLICY, __('Hydration match policy', 'dbvc'), 'media_hydration', 'same_id_then_uid', HydrationSettings::allowed_match_policies()),
            HydrationSettings::OPTION_OVERWRITE_POLICY => Field::select(HydrationSettings::OPTION_OVERWRITE_POLICY, __('Hydration overwrite policy', 'dbvc'), 'media_hydration', 'never', HydrationSettings::allowed_overwrite_policies(), [
                'requires_confirmation' => true,
                'description' => __('Initial implementation never overwrites existing media files.', 'dbvc'),
            ]),
            HydrationSettings::OPTION_METADATA_POLICY => Field::select(HydrationSettings::OPTION_METADATA_POLICY, __('Hydration metadata policy', 'dbvc'), 'media_hydration', 'regenerate_missing', HydrationSettings::allowed_metadata_policies()),
            HydrationSettings::OPTION_ALLOWED_MIME_GROUPS => Field::string_list(HydrationSettings::OPTION_ALLOWED_MIME_GROUPS, __('Hydration MIME groups', 'dbvc'), 'media_hydration', HydrationSettings::allowed_mime_groups()),
            HydrationSettings::OPTION_BATCH_SIZE => Field::integer(HydrationSettings::OPTION_BATCH_SIZE, __('Hydration batch size', 'dbvc'), 'media_hydration', 50, 1, 500),
            HydrationSettings::OPTION_REQUIRE_DRY_RUN => Field::bool(HydrationSettings::OPTION_REQUIRE_DRY_RUN, __('Require dry run before hydration apply', 'dbvc'), 'media_hydration', '1'),
            HydrationSettings::OPTION_RECEIPTS_ENABLED => Field::bool(HydrationSettings::OPTION_RECEIPTS_ENABLED, __('Save media hydration receipts', 'dbvc'), 'media_hydration', '1'),
            HydrationSettings::OPTION_STRICT_HASHES => Field::bool(HydrationSettings::OPTION_STRICT_HASHES, __('Require strict hydration hashes', 'dbvc'), 'media_hydration', '1'),
            HydrationSettings::OPTION_CLONE_CONFIRMATION => Field::bool(HydrationSettings::OPTION_CLONE_CONFIRMATION, __('Require cloned attachment IDs', 'dbvc'), 'media_hydration', '1'),
            HydrationSettings::OPTION_NORMALIZE_MEDIA_URLS_TO_HTTPS => Field::bool(HydrationSettings::OPTION_NORMALIZE_MEDIA_URLS_TO_HTTPS, __('Normalize hydrated media URLs to HTTPS', 'dbvc'), 'media_hydration', '0'),
            HydrationSettings::OPTION_LOCK_TIMEOUT_MINUTES => Field::integer(HydrationSettings::OPTION_LOCK_TIMEOUT_MINUTES, __('Hydration lock timeout minutes', 'dbvc'), 'media_hydration', 30, 1, 1440),
        ];
    }
}
