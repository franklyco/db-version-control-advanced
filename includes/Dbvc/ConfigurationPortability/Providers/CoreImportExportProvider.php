<?php

namespace Dbvc\ConfigurationPortability\Providers;

use Dbvc\ConfigurationPortability\AbstractOptionDomainProvider;
use Dbvc\ConfigurationPortability\Field;

if (! defined('WPINC')) {
    die;
}

final class CoreImportExportProvider extends AbstractOptionDomainProvider
{
    public function get_key(): string
    {
        return 'core_import_export';
    }

    public function get_label(): string
    {
        return __('Core Import/Export', 'dbvc');
    }

    public function get_version(): int
    {
        return 1;
    }

    public function get_groups(): array
    {
        return [
            'post_types' => [
                'label' => __('Post Types', 'dbvc'),
                'fields' => ['dbvc_post_types'],
            ],
            'taxonomies' => [
                'label' => __('Taxonomies', 'dbvc'),
                'fields' => ['dbvc_taxonomies', 'dbvc_tax_export_meta', 'dbvc_tax_export_parent_slugs', 'dbvc_taxonomy_filename_format'],
            ],
            'filenames' => [
                'label' => __('Filename Policy', 'dbvc'),
                'fields' => ['dbvc_export_filename_format', 'dbvc_import_filename_format', 'dbvc_use_slug_in_filenames'],
            ],
            'sync' => [
                'label' => __('Sync Folder', 'dbvc'),
                'fields' => ['dbvc_sync_path'],
            ],
            'import_defaults' => [
                'label' => __('Import Defaults', 'dbvc'),
                'fields' => [
                    'dbvc_allow_new_posts',
                    'dbvc_auto_clear_decisions',
                    'dbvc_new_post_status',
                    'dbvc_new_post_types_whitelist',
                    'dbvc_import_require_review',
                    'dbvc_force_reapply_new_posts',
                    'dbvc_prefer_entity_uids',
                    'dbvc_allow_uid_fallback_matching',
                    'dbvc_localize_bricks_entity_references',
                    'dbvc_diff_ignore_paths',
                ],
            ],
            'environment_urls' => [
                'label' => __('Environment URLs', 'dbvc'),
                'fields' => ['dbvc_mirror_domain', 'dbvc_export_use_mirror_domain', 'dbvc_strip_domain_urls'],
            ],
            'options_groups' => [
                'label' => __('Options Groups', 'dbvc'),
                'fields' => ['dbvc_options_groups', 'dbvc_export_options_groups'],
            ],
        ];
    }

    public function get_fields(): array
    {
        return [
            'dbvc_post_types' => Field::key_list('dbvc_post_types', __('Selected post types', 'dbvc'), 'post_types'),
            'dbvc_taxonomies' => Field::key_list('dbvc_taxonomies', __('Selected taxonomies', 'dbvc'), 'taxonomies'),
            'dbvc_tax_export_meta' => Field::bool('dbvc_tax_export_meta', __('Include term meta', 'dbvc'), 'taxonomies', '1'),
            'dbvc_tax_export_parent_slugs' => Field::bool('dbvc_tax_export_parent_slugs', __('Store parent terms by slug', 'dbvc'), 'taxonomies', '1'),
            'dbvc_taxonomy_filename_format' => Field::select('dbvc_taxonomy_filename_format', __('Taxonomy filename format', 'dbvc'), 'taxonomies', 'slug', ['id', 'slug', 'slug_id']),
            'dbvc_export_filename_format' => Field::select('dbvc_export_filename_format', __('Export filename format', 'dbvc'), 'filenames', 'id', ['id', 'slug', 'slug_id']),
            'dbvc_import_filename_format' => Field::select('dbvc_import_filename_format', __('Import filename format', 'dbvc'), 'filenames', 'id', ['id', 'slug', 'slug_id']),
            'dbvc_use_slug_in_filenames' => Field::bool('dbvc_use_slug_in_filenames', __('Legacy slug filename flag', 'dbvc'), 'filenames', '0'),
            'dbvc_sync_path' => Field::path(
                'dbvc_sync_path',
                __('Sync folder path', 'dbvc'),
                'sync',
                '',
                [
                    'default_export' => Field::POLICY_PROMPT,
                    'environment_policy' => Field::POLICY_PROMPT,
                    'apply_strategy' => Field::STRATEGY_KEEP_EXISTING_UNLESS_SUPPLIED,
                    'placeholder' => '${DBVC_SYNC_PATH}',
                    'requires_confirmation' => true,
                ]
            ),
            'dbvc_allow_new_posts' => Field::bool('dbvc_allow_new_posts', __('Allow new posts during import', 'dbvc'), 'import_defaults', '0'),
            'dbvc_auto_clear_decisions' => Field::bool('dbvc_auto_clear_decisions', __('Auto-clear successful proposal decisions', 'dbvc'), 'import_defaults', '1'),
            'dbvc_new_post_status' => Field::select('dbvc_new_post_status', __('New post status', 'dbvc'), 'import_defaults', 'draft', ['draft', 'publish', 'pending']),
            'dbvc_new_post_types_whitelist' => Field::key_list('dbvc_new_post_types_whitelist', __('New post type whitelist', 'dbvc'), 'import_defaults'),
            'dbvc_import_require_review' => Field::bool('dbvc_import_require_review', __('Require proposal review before import', 'dbvc'), 'import_defaults', '0'),
            'dbvc_force_reapply_new_posts' => Field::bool('dbvc_force_reapply_new_posts', __('Force reapply accepted new posts', 'dbvc'), 'import_defaults', '0'),
            'dbvc_prefer_entity_uids' => Field::bool('dbvc_prefer_entity_uids', __('Prefer entity UIDs', 'dbvc'), 'import_defaults', '0'),
            'dbvc_allow_uid_fallback_matching' => Field::bool('dbvc_allow_uid_fallback_matching', __('Allow UID-unmatched ID/slug fallback', 'dbvc'), 'import_defaults', '0'),
            'dbvc_localize_bricks_entity_references' => Field::bool('dbvc_localize_bricks_entity_references', __('Localize Bricks entity references', 'dbvc'), 'import_defaults', '1'),
            'dbvc_diff_ignore_paths' => Field::textarea('dbvc_diff_ignore_paths', __('Diff ignore paths', 'dbvc'), 'import_defaults'),
            'dbvc_mirror_domain' => Field::url(
                'dbvc_mirror_domain',
                __('Mirror domain', 'dbvc'),
                'environment_urls',
                '',
                [
                    'default_export' => Field::POLICY_PROMPT,
                    'environment_policy' => Field::POLICY_PROMPT,
                    'apply_strategy' => Field::STRATEGY_KEEP_EXISTING_UNLESS_SUPPLIED,
                    'placeholder' => '${DBVC_MIRROR_DOMAIN}',
                ]
            ),
            'dbvc_export_use_mirror_domain' => Field::bool('dbvc_export_use_mirror_domain', __('Use mirror domain during export', 'dbvc'), 'environment_urls', '0'),
            'dbvc_strip_domain_urls' => Field::bool('dbvc_strip_domain_urls', __('Strip current domain URLs during export', 'dbvc'), 'environment_urls', '0'),
            'dbvc_options_groups' => Field::string_list('dbvc_options_groups', __('Selected ACF options groups', 'dbvc'), 'options_groups'),
            'dbvc_export_options_groups' => Field::bool('dbvc_export_options_groups', __('Export selected options groups', 'dbvc'), 'options_groups', '1'),
        ];
    }

    protected function after_apply(array $applied): void
    {
        if (! isset($applied['dbvc_export_filename_format'])) {
            return;
        }

        $format = (string) get_option('dbvc_export_filename_format', 'id');
        update_option('dbvc_use_slug_in_filenames', $format === 'id' ? '0' : '1');
    }
}
