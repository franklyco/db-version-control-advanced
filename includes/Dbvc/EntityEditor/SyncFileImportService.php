<?php

namespace Dbvc\EntityEditor;

if (! defined('WPINC')) {
    die;
}

/**
 * Preview and create live entities from existing Entity Editor sync JSON files.
 */
final class SyncFileImportService
{
    private const MODE_CREATE_ONLY = 'create_only';
    private const MODE_UPDATE_MATCHED = 'update_matched';
    private const BATCH_LIMIT = 25;

    /**
     * Preview existing sync JSON files before import.
     *
     * @param array<int,string>|string $paths
     * @param string                   $mode
     * @return array<string,mixed>|\WP_Error
     */
    public static function preview($paths, $mode = self::MODE_CREATE_ONLY)
    {
        $mode = self::normalize_mode($mode);
        $paths = self::normalize_paths($paths);
        if (\is_wp_error($paths)) {
            return $paths;
        }

        $items = [];
        foreach ($paths as $path) {
            $items[] = self::build_preview_item((string) $path);
        }

        return [
            'mode'    => $mode,
            'summary' => self::build_preview_summary($items),
            'items'   => $items,
        ];
    }

    /**
     * Create live entities from existing sync JSON files.
     *
     * @param array<int,string>|string $paths
     * @param string                   $mode
     * @param int                      $user_id
     * @return array<string,mixed>|\WP_Error
     */
    public static function commit($paths, $mode = self::MODE_CREATE_ONLY, $user_id = 0, array $args = [])
    {
        $mode = self::normalize_mode($mode);
        if ($mode === self::MODE_UPDATE_MATCHED) {
            $confirmations = isset($args['confirmations']) && \is_array($args['confirmations'])
                ? $args['confirmations']
                : [];

            return self::commit_confirmed_matched_updates($paths, $confirmations, (int) $user_id);
        }

        $preview = self::preview($paths, $mode);
        if (\is_wp_error($preview)) {
            return $preview;
        }

        $items = isset($preview['items']) && \is_array($preview['items']) ? $preview['items'] : [];
        $result_items = [];
        foreach ($items as $item) {
            if (! \is_array($item)) {
                continue;
            }

            $result_items[] = self::commit_preview_item($item, (int) $user_id);
        }

        delete_transient('dbvc_entity_editor_index_v1');

        return [
            'mode'    => $mode,
            'summary' => self::build_commit_summary($result_items),
            'items'   => $result_items,
        ];
    }

    /**
     * Update matched live entities from existing sync JSON files after explicit per-file confirmation.
     *
     * This method is intentionally public so future DBVC workflows can reuse the same confirmation,
     * preview-hash, and matched-entity drift checks without duplicating sync-file import logic.
     *
     * @param array<int,string>|string      $paths
     * @param array<string|int,mixed>       $confirmations
     * @param int                           $user_id
     * @return array<string,mixed>|\WP_Error
     */
    public static function commit_confirmed_matched_updates($paths, array $confirmations = [], $user_id = 0)
    {
        $paths = self::normalize_paths($paths);
        if (\is_wp_error($paths)) {
            return $paths;
        }

        $preview = self::preview($paths, self::MODE_UPDATE_MATCHED);
        if (\is_wp_error($preview)) {
            return $preview;
        }

        $items = isset($preview['items']) && \is_array($preview['items']) ? $preview['items'] : [];
        $result_items = [];
        foreach ($items as $item) {
            if (! \is_array($item)) {
                continue;
            }

            $relative_path = isset($item['relative_path']) ? (string) $item['relative_path'] : '';
            $confirmation = self::find_update_confirmation($confirmations, $relative_path);
            $result_items[] = self::commit_confirmed_matched_update_item($item, $confirmation, (int) $user_id);
        }

        delete_transient('dbvc_entity_editor_index_v1');

        return [
            'mode'    => self::MODE_UPDATE_MATCHED,
            'summary' => self::build_commit_summary($result_items),
            'items'   => $result_items,
        ];
    }

    /**
     * Resolve a blocked sync-file import preview with a narrow allowlisted action.
     *
     * @param string              $path
     * @param string              $mode
     * @param string              $remediation
     * @param array<string,mixed> $args
     * @param int                 $user_id
     * @return array<string,mixed>|\WP_Error
     */
    public static function remediate($path, $mode = self::MODE_CREATE_ONLY, $remediation = '', array $args = [], $user_id = 0)
    {
        $mode = self::normalize_mode($mode);
        $paths = self::normalize_paths($path);
        if (\is_wp_error($paths)) {
            return $paths;
        }

        if (count($paths) !== 1) {
            return new \WP_Error(
                'dbvc_entity_editor_sync_import_remediation_single_file_required',
                __('Remediation actions support one sync JSON file at a time.', 'dbvc'),
                ['status' => 400]
            );
        }

        $relative_path = (string) $paths[0];
        $preview = self::preview([$relative_path], $mode);
        if (\is_wp_error($preview)) {
            return $preview;
        }

        $item = isset($preview['items'][0]) && \is_array($preview['items'][0]) ? $preview['items'][0] : [];
        if (empty($item)) {
            return new \WP_Error('dbvc_entity_editor_sync_import_remediation_missing_preview', __('Unable to preview this sync JSON before remediation.', 'dbvc'), ['status' => 400]);
        }

        $provided_hash = isset($args['preview_hash']) ? (string) $args['preview_hash'] : '';
        $current_hash = isset($item['preview_hash']) ? (string) $item['preview_hash'] : '';
        if ($provided_hash !== '' && $current_hash !== '' && ! hash_equals($current_hash, $provided_hash)) {
            return new \WP_Error(
                'dbvc_entity_editor_sync_import_remediation_stale_preview',
                __('The import preview changed. Refresh the preview before applying this fix.', 'dbvc'),
                ['status' => 409, 'preview' => $preview]
            );
        }

        $remediation = sanitize_key((string) $remediation);
        if ($remediation === 'enable_new_post_creation') {
            if (! self::item_has_setting_remediation($item, $remediation)) {
                return new \WP_Error('dbvc_entity_editor_sync_import_remediation_unavailable', __('This setting fix is not available for the current preview.', 'dbvc'), ['status' => 400, 'preview' => $preview]);
            }

            $old_value = get_option('dbvc_allow_new_posts', '0');
            update_option('dbvc_allow_new_posts', '1');
            self::log_remediation($remediation, $relative_path, (int) $user_id, [
                'option'    => 'dbvc_allow_new_posts',
                'old_value' => $old_value,
                'new_value' => '1',
            ]);

            return self::with_remediation_result(self::preview([$relative_path], $mode), [
                'action'    => $remediation,
                'option'    => 'dbvc_allow_new_posts',
                'old_value' => $old_value,
                'new_value' => '1',
            ]);
        }

        if ($remediation === 'allow_post_type_creation') {
            if (! self::item_has_setting_remediation($item, $remediation)) {
                return new \WP_Error('dbvc_entity_editor_sync_import_remediation_unavailable', __('This setting fix is not available for the current preview.', 'dbvc'), ['status' => 400, 'preview' => $preview]);
            }

            $post_type = sanitize_key((string) ($item['subtype'] ?? ''));
            if ($post_type === '' || ! post_type_exists($post_type)) {
                return new \WP_Error('dbvc_entity_editor_sync_import_remediation_missing_post_type', __('The incoming post type is not registered on this site.', 'dbvc'), ['status' => 400, 'preview' => $preview]);
            }

            $old_value = (array) get_option('dbvc_new_post_types_whitelist', []);
            $new_value = array_values(array_unique(array_merge(array_map('sanitize_key', $old_value), [$post_type])));
            update_option('dbvc_new_post_types_whitelist', $new_value);
            self::log_remediation($remediation, $relative_path, (int) $user_id, [
                'option'    => 'dbvc_new_post_types_whitelist',
                'post_type' => $post_type,
                'old_value' => $old_value,
                'new_value' => $new_value,
            ]);

            return self::with_remediation_result(self::preview([$relative_path], $mode), [
                'action'    => $remediation,
                'option'    => 'dbvc_new_post_types_whitelist',
                'post_type' => $post_type,
                'old_value' => $old_value,
                'new_value' => $new_value,
            ]);
        }

        if ($remediation === 'use_canonical_row') {
            $canonical_path = self::extract_item_canonical_path($item);
            if ($canonical_path === '' || ! self::item_has_advanced_override($item, $remediation)) {
                return new \WP_Error('dbvc_entity_editor_sync_import_remediation_missing_canonical', __('This preview does not identify a canonical duplicate row.', 'dbvc'), ['status' => 400, 'preview' => $preview]);
            }

            self::log_remediation($remediation, $relative_path, (int) $user_id, [
                'canonical_relative_path' => $canonical_path,
            ]);

            return self::with_remediation_result(self::preview([$canonical_path], $mode), [
                'action'                  => $remediation,
                'source_relative_path'    => $relative_path,
                'canonical_relative_path' => $canonical_path,
            ]);
        }

        if ($remediation === 'archive_stale_duplicate') {
            $canonical_path = self::extract_item_canonical_path($item);
            if ($canonical_path === '' || ! self::item_has_advanced_override($item, $remediation)) {
                return new \WP_Error('dbvc_entity_editor_sync_import_remediation_missing_canonical', __('This preview does not identify a stale duplicate that can be archived.', 'dbvc'), ['status' => 400, 'preview' => $preview]);
            }

            $archive = self::archive_single_stale_duplicate_file($relative_path, $canonical_path);
            if (\is_wp_error($archive)) {
                return $archive;
            }

            self::log_remediation($remediation, $relative_path, (int) $user_id, [
                'canonical_relative_path' => $canonical_path,
                'archive'                 => $archive,
            ]);

            return self::with_remediation_result(self::preview([$canonical_path], $mode), [
                'action'                  => $remediation,
                'source_relative_path'    => $relative_path,
                'canonical_relative_path' => $canonical_path,
                'archive'                 => $archive,
            ]);
        }

        return new \WP_Error('dbvc_entity_editor_sync_import_remediation_unknown', __('Unknown sync-file import remediation action.', 'dbvc'), ['status' => 400, 'preview' => $preview]);
    }

    /**
     * @param array<string,mixed> $item
     * @param int                 $user_id
     * @return array<string,mixed>
     */
    private static function commit_preview_item(array $item, $user_id)
    {
        $blocking = isset($item['blocking']) && \is_array($item['blocking']) ? $item['blocking'] : [];
        if (! empty($blocking)) {
            return array_merge($item, [
                'action'   => 'blocked',
                'created'  => false,
                'imported' => false,
                'status'   => 'blocked',
            ]);
        }

        $relative_path = isset($item['relative_path']) ? (string) $item['relative_path'] : '';
        $absolute_path = self::resolve_absolute_path($relative_path);
        if (\is_wp_error($absolute_path)) {
            return self::merge_item_error($item, $absolute_path, 'error');
        }

        $entity_kind = isset($item['entity_kind']) ? (string) $item['entity_kind'] : '';
        if ($entity_kind === 'term') {
            return self::commit_term_preview_item($item, $absolute_path, (int) $user_id);
        }

        return self::commit_post_preview_item($item, $absolute_path, (int) $user_id);
    }

    /**
     * @param array<string,mixed>      $item
     * @param array<string,mixed>|null $confirmation
     * @param int                      $user_id
     * @return array<string,mixed>
     */
    private static function commit_confirmed_matched_update_item(array $item, $confirmation, $user_id)
    {
        $relative_path = isset($item['relative_path']) ? (string) $item['relative_path'] : '';
        $matched_update = isset($item['matched_update']) && \is_array($item['matched_update']) ? $item['matched_update'] : [];
        $wp_entity = isset($matched_update['wp_entity']) && \is_array($matched_update['wp_entity']) ? $matched_update['wp_entity'] : [];
        $matched_id = isset($wp_entity['id']) ? (int) $wp_entity['id'] : 0;

        if (empty($matched_update['eligible']) || empty($item['available_actions'][self::MODE_UPDATE_MATCHED])) {
            return self::merge_item_blocked(
                $item,
                'matched_update_unavailable',
                __('This sync JSON does not have a safe matched-entity update action available.', 'dbvc')
            );
        }

        if (! \is_array($confirmation) || empty($confirmation['confirmed'])) {
            return self::merge_item_blocked(
                $item,
                'matched_update_confirmation_required',
                __('Confirm the matched-entity update before DBVC applies this sync JSON.', 'dbvc')
            );
        }

        $provided_hash = isset($confirmation['preview_hash']) ? (string) $confirmation['preview_hash'] : '';
        $current_hash = isset($item['preview_hash']) ? (string) $item['preview_hash'] : '';
        if ($provided_hash === '' || $current_hash === '' || ! hash_equals($current_hash, $provided_hash)) {
            return self::merge_item_blocked(
                $item,
                'matched_update_stale_preview',
                __('The import preview changed. Refresh the preview before updating the matched entity.', 'dbvc')
            );
        }

        $confirmed_match_id = isset($confirmation['matched_entity_id'])
            ? (int) $confirmation['matched_entity_id']
            : (isset($confirmation['match_id']) ? (int) $confirmation['match_id'] : 0);
        if ($matched_id <= 0 || $confirmed_match_id <= 0 || $confirmed_match_id !== $matched_id) {
            return self::merge_item_blocked(
                $item,
                'matched_update_match_drift',
                __('The matched entity changed. Refresh the preview before updating.', 'dbvc')
            );
        }

        $absolute_path = self::resolve_absolute_path($relative_path);
        if (\is_wp_error($absolute_path)) {
            return self::merge_item_error($item, $absolute_path, 'error');
        }

        return self::commit_matched_post_update_item($item, (string) $absolute_path, (int) $user_id);
    }

    /**
     * @param array<string,mixed> $item
     * @param string              $absolute_path
     * @param int                 $user_id
     * @return array<string,mixed>
     */
    private static function commit_matched_post_update_item(array $item, $absolute_path, $user_id)
    {
        if (! \class_exists('DBVC_Sync_Posts')) {
            return self::merge_item_error(
                $item,
                new \WP_Error('dbvc_entity_editor_sync_import_unavailable', __('DBVC post importer is unavailable.', 'dbvc'), ['status' => 500]),
                'error'
            );
        }

        $relative_path = isset($item['relative_path']) ? (string) $item['relative_path'] : '';
        $matched_update = isset($item['matched_update']) && \is_array($item['matched_update']) ? $item['matched_update'] : [];
        $expected_entity = isset($matched_update['wp_entity']) && \is_array($matched_update['wp_entity']) ? $matched_update['wp_entity'] : [];
        $expected_id = isset($expected_entity['id']) ? (int) $expected_entity['id'] : 0;

        $result = self::import_post_with_export_suppressed($absolute_path);
        if (\is_wp_error($result)) {
            return self::merge_item_error($item, $result, 'error');
        }

        if ($result !== 'applied') {
            return array_merge($item, [
                'action'        => self::MODE_UPDATE_MATCHED,
                'created'       => false,
                'updated'       => false,
                'imported'      => false,
                'status'        => 'skipped',
                'import_result' => [
                    'status' => is_string($result) ? $result : 'skipped',
                ],
                'blocking'      => [
                    self::reason('dbvc_entity_editor_sync_import_update_skipped', __('DBVC wrote no changes for this matched sync JSON file.', 'dbvc')),
                ],
            ]);
        }

        $final_payload = self::read_json_file($absolute_path);
        $match = \is_array($final_payload) ? self::inspect_live_post_match($final_payload) : ['status' => 'none'];
        if (\is_wp_error($match)) {
            return self::merge_item_error($item, $match, 'error');
        }

        if (! isset($match['status']) || $match['status'] !== 'matched' || (int) ($match['id'] ?? 0) !== $expected_id) {
            return array_merge($item, [
                'action'   => 'error',
                'created'  => false,
                'updated'  => false,
                'imported' => false,
                'status'   => 'error',
                'blocking' => [
                    self::reason('dbvc_entity_editor_sync_import_match_changed_after_update', __('DBVC updated the JSON, but the matched WordPress entity could not be reverified.', 'dbvc')),
                ],
            ]);
        }

        if (\is_array($final_payload)) {
            $final_payload['ID'] = $expected_id;
            if (! self::write_json_file($absolute_path, $final_payload)) {
                return self::merge_item_error(
                    $item,
                    new \WP_Error('dbvc_entity_editor_sync_import_update_file_rewrite_failed', __('The matched update succeeded, but DBVC could not rewrite the sync JSON with the local entity ID.', 'dbvc'), ['status' => 500]),
                    'error'
                );
            }
        }

        $final_relative_path = $relative_path;
        $file_normalization = \is_array($final_payload)
            ? self::normalize_imported_file_path($relative_path, $absolute_path, $final_payload)
            : null;
        $normalization_warning = null;
        if (\is_wp_error($file_normalization)) {
            $normalization_warning = self::reason(
                (string) $file_normalization->get_error_code(),
                (string) $file_normalization->get_error_message()
            );
        } elseif (\is_array($file_normalization) && ! empty($file_normalization['relative_path'])) {
            $final_relative_path = (string) $file_normalization['relative_path'];
        }

        $result_item = array_merge($item, [
            'relative_path'        => $final_relative_path,
            'source_relative_path' => $relative_path,
            'action'               => self::MODE_UPDATE_MATCHED,
            'created'              => false,
            'updated'              => true,
            'imported'             => true,
            'status'               => 'updated',
            'import_result'        => [
                'status' => 'applied',
            ],
            'wp_entity'            => $match,
            'file_normalization'   => \is_array($file_normalization) ? $file_normalization : null,
            'blocking'             => [],
        ]);
        if ($normalization_warning) {
            $result_item['warnings'][] = $normalization_warning;
        }
        if (\is_array($file_normalization)) {
            $result_item = self::append_file_normalization_warnings($result_item, $file_normalization);
        }

        if (\class_exists('DBVC_Sync_Logger')) {
            \DBVC_Sync_Logger::log('Entity Editor sync file matched update', [
                'relative_path'        => $final_relative_path,
                'source_relative_path' => $relative_path,
                'user_id'              => (int) $user_id,
                'entity_kind'          => 'post',
                'subtype'              => isset($result_item['subtype']) ? (string) $result_item['subtype'] : '',
                'updated'              => true,
                'wp_entity_id'         => isset($match['id']) ? (int) $match['id'] : 0,
                'match_source'         => isset($match['match_source']) ? (string) $match['match_source'] : '',
            ]);
        }

        return $result_item;
    }

    /**
     * @param array<string,mixed> $item
     * @param string              $absolute_path
     * @param int                 $user_id
     * @return array<string,mixed>
     */
    private static function commit_post_preview_item(array $item, $absolute_path, $user_id)
    {
        if (! \class_exists('DBVC_Sync_Posts')) {
            return self::merge_item_error(
                $item,
                new \WP_Error('dbvc_entity_editor_sync_import_unavailable', __('DBVC post importer is unavailable.', 'dbvc'), ['status' => 500]),
                'error'
            );
        }

        $relative_path = isset($item['relative_path']) ? (string) $item['relative_path'] : '';
        $result = self::import_post_with_export_suppressed($absolute_path);
        if (\is_wp_error($result)) {
            return self::merge_item_error($item, $result, 'error');
        }

        if ($result !== 'applied') {
            return array_merge($item, [
                'action'        => 'skipped',
                'created'       => false,
                'imported'      => false,
                'status'        => 'skipped',
                'import_result' => [
                    'status' => is_string($result) ? $result : 'skipped',
                ],
                'blocking'      => [
                    self::reason('dbvc_entity_editor_sync_import_skipped', __('DBVC wrote no live entity for this sync JSON file.', 'dbvc')),
                ],
            ]);
        }

        $final_payload = self::read_json_file($absolute_path);
        $match = \is_array($final_payload) ? self::inspect_live_post_match($final_payload) : ['status' => 'none'];
        if (\is_wp_error($match)) {
            return self::merge_item_error($item, $match, 'error');
        }

        if (! isset($match['status']) || $match['status'] !== 'matched') {
            return array_merge($item, [
                'action'   => 'error',
                'created'  => false,
                'imported' => false,
                'status'   => 'error',
                'blocking' => [
                    self::reason('dbvc_entity_editor_sync_import_unmatched_after_commit', __('DBVC reported an import, but the created WordPress entity could not be resolved.', 'dbvc')),
                ],
            ]);
        }

        $final_relative_path = $relative_path;
        $file_normalization = \is_array($final_payload)
            ? self::normalize_imported_file_path($relative_path, $absolute_path, $final_payload)
            : null;
        $normalization_warning = null;
        if (\is_wp_error($file_normalization)) {
            $normalization_warning = self::reason(
                (string) $file_normalization->get_error_code(),
                (string) $file_normalization->get_error_message()
            );
        } elseif (\is_array($file_normalization) && ! empty($file_normalization['relative_path'])) {
            $final_relative_path = (string) $file_normalization['relative_path'];
        }

        $result_item = array_merge($item, [
            'relative_path'        => $final_relative_path,
            'source_relative_path' => $relative_path,
            'action'               => 'create',
            'created'              => true,
            'imported'             => true,
            'status'               => 'created',
            'import_result'        => [
                'status' => 'applied',
            ],
            'wp_entity'            => $match,
            'file_normalization'   => \is_array($file_normalization) ? $file_normalization : null,
            'blocking'             => [],
        ]);
        if ($normalization_warning) {
            $result_item['warnings'][] = $normalization_warning;
        }
        if (\is_array($file_normalization)) {
            $result_item = self::append_file_normalization_warnings($result_item, $file_normalization);
        }

        if (\class_exists('DBVC_Sync_Logger')) {
            \DBVC_Sync_Logger::log('Entity Editor sync file import', [
                'relative_path'        => $final_relative_path,
                'source_relative_path' => $relative_path,
                'user_id'              => (int) $user_id,
                'entity_kind'          => 'post',
                'subtype'              => isset($result_item['subtype']) ? (string) $result_item['subtype'] : '',
                'created'              => true,
                'wp_entity_id'         => isset($match['id']) ? (int) $match['id'] : 0,
            ]);
        }

        return $result_item;
    }

    /**
     * @param array<string,mixed> $item
     * @param string              $absolute_path
     * @param int                 $user_id
     * @return array<string,mixed>
     */
    private static function commit_term_preview_item(array $item, $absolute_path, $user_id)
    {
        if (! \class_exists('DBVC_Sync_Taxonomies')) {
            return self::merge_item_error(
                $item,
                new \WP_Error('dbvc_entity_editor_sync_import_unavailable', __('DBVC taxonomy importer is unavailable.', 'dbvc'), ['status' => 500]),
                'error'
            );
        }

        $relative_path = isset($item['relative_path']) ? (string) $item['relative_path'] : '';
        $taxonomy = sanitize_key((string) ($item['subtype'] ?? ''));
        if ($taxonomy === '' || ! taxonomy_exists($taxonomy)) {
            return self::merge_item_error(
                $item,
                new \WP_Error('dbvc_entity_editor_sync_import_missing_taxonomy', __('The taxonomy for this sync JSON file is unavailable.', 'dbvc'), ['status' => 422]),
                'error'
            );
        }

        $result = self::import_term_with_side_effects_suppressed($absolute_path, $taxonomy);
        if (\is_wp_error($result)) {
            return self::merge_item_error($item, $result, 'error');
        }

        $term_id = isset($result['term_id']) ? (int) $result['term_id'] : 0;
        $created_term = $term_id > 0 ? get_term($term_id, $taxonomy) : null;
        if (! $created_term || \is_wp_error($created_term)) {
            return array_merge($item, [
                'action'   => 'error',
                'created'  => false,
                'imported' => false,
                'status'   => 'error',
                'blocking' => [
                    self::reason('dbvc_entity_editor_sync_import_unmatched_after_commit', __('DBVC reported a term import, but the created term could not be resolved.', 'dbvc')),
                ],
            ]);
        }

        if (empty($result['created'])) {
            return array_merge($item, [
                'action'        => 'error',
                'created'       => false,
                'imported'      => true,
                'status'        => 'error',
                'import_result' => $result,
                'wp_entity'     => self::format_term_entity($term_id, $taxonomy, 'import'),
                'blocking'      => [
                    self::reason('dbvc_entity_editor_sync_import_updated_existing_term', __('DBVC updated an existing term instead of creating a new one; the sync-file import was expected to create only.', 'dbvc')),
                ],
            ]);
        }

        $final_payload = self::read_json_file($absolute_path);
        if (\is_array($final_payload)) {
            $final_payload['term_id'] = $term_id;
            $final_payload['taxonomy'] = $taxonomy;
            if (! empty($result['vf_object_uid'])) {
                $final_payload['vf_object_uid'] = (string) $result['vf_object_uid'];
            }
            if (! self::write_json_file($absolute_path, $final_payload)) {
                return self::merge_item_error(
                    $item,
                    new \WP_Error('dbvc_entity_editor_sync_import_term_file_update_failed', __('The created term could not be written back to the source JSON before canonical filename normalization.', 'dbvc'), ['status' => 500]),
                    'error'
                );
            }
        }

        $match = \is_array($final_payload) ? self::inspect_live_term_match($final_payload) : self::format_term_entity($term_id, $taxonomy, 'import');
        if (\is_wp_error($match)) {
            return self::merge_item_error($item, $match, 'error');
        }

        if (! isset($match['status']) || $match['status'] !== 'matched') {
            return array_merge($item, [
                'action'   => 'error',
                'created'  => false,
                'imported' => false,
                'status'   => 'error',
                'blocking' => [
                    self::reason('dbvc_entity_editor_sync_import_unmatched_after_commit', __('DBVC reported a term import, but the created WordPress term could not be resolved.', 'dbvc')),
                ],
            ]);
        }

        $final_relative_path = $relative_path;
        $file_normalization = \is_array($final_payload)
            ? self::normalize_imported_file_path($relative_path, $absolute_path, $final_payload)
            : null;
        $normalization_warning = null;
        if (\is_wp_error($file_normalization)) {
            $normalization_warning = self::reason(
                (string) $file_normalization->get_error_code(),
                (string) $file_normalization->get_error_message()
            );
        } elseif (\is_array($file_normalization) && ! empty($file_normalization['relative_path'])) {
            $final_relative_path = (string) $file_normalization['relative_path'];
        }

        $result_item = array_merge($item, [
            'relative_path'        => $final_relative_path,
            'source_relative_path' => $relative_path,
            'action'               => 'create',
            'created'              => true,
            'imported'             => true,
            'status'               => 'created',
            'import_result'        => $result,
            'wp_entity'            => $match,
            'file_normalization'   => \is_array($file_normalization) ? $file_normalization : null,
            'blocking'             => [],
        ]);
        if ($normalization_warning) {
            $result_item['warnings'][] = $normalization_warning;
        }
        if (\is_array($file_normalization)) {
            $result_item = self::append_file_normalization_warnings($result_item, $file_normalization);
        }

        if (\class_exists('DBVC_Sync_Logger')) {
            \DBVC_Sync_Logger::log('Entity Editor sync file import', [
                'relative_path'        => $final_relative_path,
                'source_relative_path' => $relative_path,
                'user_id'              => (int) $user_id,
                'entity_kind'          => 'term',
                'subtype'              => $taxonomy,
                'created'              => true,
                'wp_entity_id'         => isset($match['id']) ? (int) $match['id'] : $term_id,
            ]);
        }

        return $result_item;
    }

    /**
     * @param array<string,mixed> $item
     * @param \WP_Error           $error
     * @param string              $status
     * @return array<string,mixed>
     */
    private static function merge_item_error(array $item, \WP_Error $error, $status = 'error')
    {
        return array_merge($item, [
            'action'   => $status,
            'created'  => false,
            'updated'  => false,
            'imported' => false,
            'status'   => $status,
            'blocking' => [
                self::reason((string) $error->get_error_code(), (string) $error->get_error_message()),
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $item
     * @param string              $code
     * @param string              $message
     * @return array<string,mixed>
     */
    private static function merge_item_blocked(array $item, $code, $message)
    {
        return array_merge($item, [
            'action'   => 'blocked',
            'created'  => false,
            'updated'  => false,
            'imported' => false,
            'status'   => 'blocked',
            'blocking' => [
                self::reason((string) $code, (string) $message),
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $file_normalization
     * @return array<string,mixed>
     */
    private static function append_file_normalization_warnings(array $item, array $file_normalization)
    {
        $errors = isset($file_normalization['archive_errors']) && \is_array($file_normalization['archive_errors'])
            ? $file_normalization['archive_errors']
            : [];
        foreach ($errors as $error) {
            $path = isset($error['relative_path']) ? (string) $error['relative_path'] : '';
            $message = isset($error['message']) ? (string) $error['message'] : '';
            if ($message === '') {
                $message = __('A stale duplicate sync file could not be archived.', 'dbvc');
            }
            if ($path !== '') {
                $message = sprintf('%s (%s)', $message, $path);
            }
            $item['warnings'][] = self::reason('stale_duplicate_archive_failed', $message);
        }

        return $item;
    }

    /**
     * @param string $mode
     * @return string
     */
    private static function normalize_mode($mode)
    {
        $mode = sanitize_key((string) $mode);
        $allowed = [
            self::MODE_CREATE_ONLY,
            self::MODE_UPDATE_MATCHED,
        ];

        return \in_array($mode, $allowed, true) ? $mode : self::MODE_CREATE_ONLY;
    }

    /**
     * Call the existing low-level importer without letting save_post create a second export file.
     *
     * @param string $absolute_path
     * @return mixed
     */
    private static function import_post_with_export_suppressed($absolute_path)
    {
        $removed_hooks = self::remove_auto_export_hooks();

        try {
            return \DBVC_Sync_Posts::import_post_from_json(
                $absolute_path,
                false,
                null,
                null,
                null,
                null,
                false,
                []
            );
        } finally {
            self::restore_auto_export_hooks($removed_hooks);
        }
    }

    /**
     * Call the existing term importer without broad term-change side effects.
     *
     * @param string $absolute_path
     * @param string $taxonomy
     * @return mixed
     */
    private static function import_term_with_side_effects_suppressed($absolute_path, $taxonomy)
    {
        $removed_hooks = self::remove_term_import_side_effect_hooks();

        try {
            return \DBVC_Sync_Taxonomies::import_term_json_file($absolute_path, $taxonomy);
        } finally {
            self::restore_auto_export_hooks($removed_hooks);
        }
    }

    /**
     * @return array<int,array{hook:string,callback:mixed,priority:int,args:int}>
     */
    private static function remove_auto_export_hooks()
    {
        $hooks = [
            [
                'hook'     => 'save_post',
                'callback' => ['DBVC_Sync_Posts', 'export_post_to_json'],
                'priority' => 10,
                'args'     => 2,
            ],
            [
                'hook'     => 'transition_post_status',
                'callback' => 'dbvc_handle_post_status_transition',
                'priority' => 10,
                'args'     => 3,
            ],
            [
                'hook'     => 'updated_post_meta',
                'callback' => 'dbvc_handle_post_meta_update',
                'priority' => 10,
                'args'     => 4,
            ],
            [
                'hook'     => 'added_post_meta',
                'callback' => 'dbvc_handle_post_meta_update',
                'priority' => 10,
                'args'     => 4,
            ],
            [
                'hook'     => 'deleted_post_meta',
                'callback' => 'dbvc_handle_post_meta_update',
                'priority' => 10,
                'args'     => 4,
            ],
        ];

        $removed = [];
        foreach ($hooks as $spec) {
            if (has_action($spec['hook'], $spec['callback']) === false) {
                continue;
            }

            if (remove_action($spec['hook'], $spec['callback'], $spec['priority'])) {
                $removed[] = $spec;
            }
        }

        return $removed;
    }

    /**
     * @return array<int,array{hook:string,callback:mixed,priority:int,args:int}>
     */
    private static function remove_term_import_side_effect_hooks()
    {
        $hooks = [
            [
                'hook'     => 'created_term',
                'callback' => 'dbvc_handle_term_changes',
                'priority' => 10,
                'args'     => 0,
            ],
            [
                'hook'     => 'edited_term',
                'callback' => 'dbvc_handle_term_changes',
                'priority' => 10,
                'args'     => 0,
            ],
            [
                'hook'     => 'created_term',
                'callback' => ['DBVC_Sync_Taxonomies', 'ensure_term_uid_on_change'],
                'priority' => 10,
                'args'     => 3,
            ],
            [
                'hook'     => 'edited_term',
                'callback' => ['DBVC_Sync_Taxonomies', 'ensure_term_uid_on_change'],
                'priority' => 10,
                'args'     => 3,
            ],
        ];

        $removed = [];
        foreach ($hooks as $spec) {
            if (has_action($spec['hook'], $spec['callback']) === false) {
                continue;
            }

            if (remove_action($spec['hook'], $spec['callback'], $spec['priority'])) {
                $removed[] = $spec;
            }
        }

        return $removed;
    }

    /**
     * @param array<int,array{hook:string,callback:mixed,priority:int,args:int}> $hooks
     * @return void
     */
    private static function restore_auto_export_hooks(array $hooks)
    {
        foreach ($hooks as $spec) {
            add_action($spec['hook'], $spec['callback'], $spec['priority'], $spec['args']);
        }
    }

    /**
     * @param array<int,string>|string $paths
     * @return array<int,string>|\WP_Error
     */
    private static function normalize_paths($paths)
    {
        if (\is_string($paths)) {
            $paths = [$paths];
        }

        if (! \is_array($paths)) {
            return new \WP_Error('dbvc_entity_editor_sync_import_empty', __('Select one sync JSON file to import.', 'dbvc'), ['status' => 400]);
        }

        $normalized = [];
        foreach ($paths as $path) {
            if (! \is_string($path)) {
                continue;
            }

            $path = str_replace('\\', '/', ltrim(trim($path), '/'));
            if ($path === '') {
                continue;
            }

            $normalized[$path] = $path;
        }

        $normalized = array_values($normalized);
        if (empty($normalized)) {
            return new \WP_Error('dbvc_entity_editor_sync_import_empty', __('Select one sync JSON file to import.', 'dbvc'), ['status' => 400]);
        }

        if (count($normalized) > self::BATCH_LIMIT) {
            return new \WP_Error(
                'dbvc_entity_editor_sync_import_batch_limit',
                sprintf(__('Sync file import supports up to %d files per request.', 'dbvc'), self::BATCH_LIMIT),
                [
                    'status' => 400,
                    'limit'  => self::BATCH_LIMIT,
                ]
            );
        }

        return $normalized;
    }

    /**
     * @param string $relative_path
     * @return array<string,mixed>
     */
    private static function build_preview_item($relative_path)
    {
        $relative_path = str_replace('\\', '/', ltrim((string) $relative_path, '/'));
        $payload = [];
        $index_item = null;
        $base = [
            'relative_path'        => $relative_path,
            'entity_kind'          => '',
            'subtype'              => '',
            'title'                => '',
            'slug'                 => '',
            'uid'                  => '',
            'detected_action'      => 'blocked',
            'match'                => ['status' => 'none'],
            'warnings'             => [],
            'blocking'             => [],
            'blocker_details'      => [],
            'settings_links'       => [],
            'setting_remediations' => [],
            'advanced_overrides'   => [],
            'preview_hash'         => '',
            'available_actions'    => [
                self::MODE_CREATE_ONLY => false,
                self::MODE_UPDATE_MATCHED => false,
            ],
        ];

        if (! \class_exists('DBVC_Entity_Editor_Indexer')) {
            $base['blocking'][] = self::reason('indexer_unavailable', __('Entity Editor indexer is unavailable.', 'dbvc'));
            return self::finalize_preview_item($base, $payload, $index_item);
        }

        $loaded = \DBVC_Entity_Editor_Indexer::load_entity_file_for_download($relative_path);
        if (\is_wp_error($loaded)) {
            $base['blocking'][] = self::reason((string) $loaded->get_error_code(), (string) $loaded->get_error_message());
            return self::finalize_preview_item($base, $payload, $index_item);
        }

        $payload = isset($loaded['decoded']) && \is_array($loaded['decoded']) ? $loaded['decoded'] : [];
        $kind = self::detect_kind($payload);
        $base['entity_kind'] = $kind;

        $index_item = self::find_index_item($relative_path);
        if (\is_array($index_item) && ! empty($index_item['is_duplicate']) && empty($index_item['is_canonical_duplicate'])) {
            $canonical_path = isset($index_item['duplicate_group']['canonical_relative_path'])
                ? (string) $index_item['duplicate_group']['canonical_relative_path']
                : '';
            $base['blocking'][] = self::reason(
                'stale_duplicate_file',
                $canonical_path !== ''
                    ? sprintf(__('This is an older duplicate sync file. Import the canonical row instead: %s', 'dbvc'), $canonical_path)
                    : __('This is an older duplicate sync file. Import the canonical row instead.', 'dbvc')
            );
        }

        if ($kind !== 'post' && $kind !== 'term') {
            $base['blocking'][] = self::reason('unsupported_entity_kind', __('Sync file import supports DBVC post/CPT and taxonomy term JSON only.', 'dbvc'));
            return self::finalize_preview_item($base, $payload, $index_item);
        }

        $post_type = $kind === 'post' ? sanitize_key((string) ($payload['post_type'] ?? '')) : '';
        $taxonomy = $kind === 'term' ? sanitize_key((string) ($payload['taxonomy'] ?? '')) : '';
        $slug = $kind === 'term'
            ? sanitize_title((string) ($payload['slug'] ?? ''))
            : sanitize_title((string) ($payload['post_name'] ?? ''));
        $title = $kind === 'term'
            ? (string) ($payload['name'] ?? $payload['term_name'] ?? $slug)
            : (string) ($payload['post_title'] ?? '');
        $uid = self::extract_entity_uid($payload);

        $base['subtype'] = $kind === 'term' ? $taxonomy : $post_type;
        $base['title'] = $title;
        $base['slug'] = $slug;
        $base['uid'] = $uid;

        if ($kind === 'post') {
            if ($post_type === '') {
                $base['blocking'][] = self::reason('missing_post_type', __('Post JSON is missing a valid post_type.', 'dbvc'));
            } elseif (! post_type_exists($post_type)) {
                $base['blocking'][] = self::reason('missing_post_type', sprintf(__('The post type "%s" is not registered on this site.', 'dbvc'), $post_type));
            }

            if ($title === '') {
                $base['blocking'][] = self::reason('missing_post_title', __('Post JSON must include post_title.', 'dbvc'));
            }

            if ($slug === '' && empty($payload['ID'])) {
                $base['blocking'][] = self::reason('missing_post_identifier', __('Post JSON must include post_name or ID so DBVC can identify the import source.', 'dbvc'));
            }
        } else {
            if ($taxonomy === '') {
                $base['blocking'][] = self::reason('missing_taxonomy', __('Term JSON is missing a valid taxonomy.', 'dbvc'));
            } elseif (! taxonomy_exists($taxonomy)) {
                $base['blocking'][] = self::reason('missing_taxonomy', sprintf(__('The taxonomy "%s" is not registered on this site.', 'dbvc'), $taxonomy));
            }

            if ($slug === '') {
                $base['blocking'][] = self::reason('missing_term_slug', __('Term JSON must include slug so DBVC can identify the import source.', 'dbvc'));
            }

            if ($title === '') {
                $base['warnings'][] = self::reason('missing_term_name', __('This JSON has no term name. DBVC will use the slug as the name during import.', 'dbvc'));
            }
        }

        if ($uid === '') {
            $base['warnings'][] = self::reason('missing_uid', __('This JSON has no UID. DBVC will create and write one during import.', 'dbvc'));
        }

        if (empty($base['blocking'])) {
            $match = $kind === 'term' ? self::inspect_live_term_match($payload) : self::inspect_live_post_match($payload);
            if (\is_wp_error($match)) {
                $base['match'] = ['status' => 'ambiguous'];
                $base['blocking'][] = self::reason((string) $match->get_error_code(), (string) $match->get_error_message());
            } else {
                $base['match'] = $match;
                if (isset($match['status']) && $match['status'] === 'matched') {
                    $base['blocking'][] = self::reason('matched_entity', __('This sync file already matches a live WordPress entity.', 'dbvc'));
                    $matched_update = self::build_matched_update_descriptor($base, $match);
                    if (\is_array($matched_update)) {
                        $base['matched_update'] = $matched_update;
                        $base['available_actions'][self::MODE_UPDATE_MATCHED] = true;
                    }
                }
            }
        }

        if ($kind === 'post' && empty($base['blocking'])) {
            $creation_blocker = self::build_post_creation_blocker($post_type);
            if (\is_array($creation_blocker)) {
                $base['blocking'][] = $creation_blocker;
            }
        }

        if (empty($base['blocking'])) {
            $canonical = $kind === 'term'
                ? self::build_canonical_term_relative_path($payload, $taxonomy)
                : self::build_canonical_post_relative_path($payload, $post_type);
            if ($canonical !== '' && $canonical !== $relative_path) {
                $base['warnings'][] = self::reason('noncanonical_filename', sprintf(__('This file is not at the current canonical path (%s), but DBVC can still import it.', 'dbvc'), $canonical));
            }

            $base['detected_action'] = 'create';
            $base['available_actions'][self::MODE_CREATE_ONLY] = true;
        }

        $bricks_advisory = self::build_bricks_template_advisory($payload, $base['match']);
        if (\is_array($bricks_advisory)) {
            $base['bricks_template_advisory'] = $bricks_advisory;
        }

        return self::finalize_preview_item($base, $payload, $index_item);
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $match
     * @return array<string,mixed>|null
     */
    private static function build_matched_update_descriptor(array $item, array $match)
    {
        $entity_kind = isset($item['entity_kind']) ? (string) $item['entity_kind'] : '';
        $match_source = isset($match['match_source']) ? sanitize_key((string) $match['match_source']) : '';
        $match_id = isset($match['id']) ? (int) $match['id'] : 0;
        $subtype = isset($item['subtype']) ? sanitize_key((string) $item['subtype']) : '';

        if ($entity_kind !== 'post' || $match_id <= 0 || $match_source !== 'uid') {
            return null;
        }

        $post = get_post($match_id);
        if (! ($post instanceof \WP_Post) || ($subtype !== '' && $post->post_type !== $subtype)) {
            return null;
        }

        return [
            'eligible'              => true,
            'requires_confirmation' => true,
            'match_source'          => $match_source,
            'wp_entity'             => self::format_post_entity($match_id, $subtype, $match_source),
            'scope_summary'         => [
                'core_fields' => true,
                'meta'        => true,
                'taxonomies'  => true,
            ],
            'confirmation_label'    => __('I confirm updating this matched WordPress entity from the selected JSON.', 'dbvc'),
        ];
    }

    /**
     * @param array<string,mixed>      $item
     * @param array<string,mixed>      $payload
     * @param array<string,mixed>|null $index_item
     * @return array<string,mixed>
     */
    private static function finalize_preview_item(array $item, array $payload = [], $index_item = null)
    {
        $canonical_path = self::get_index_canonical_path($index_item);
        if ($canonical_path !== '') {
            $item['canonical_relative_path'] = $canonical_path;
        }

        $item['settings_links'] = self::build_import_settings_links($item);
        $item['blocker_details'] = self::build_blocker_details($item, $payload, $index_item);
        $item['setting_remediations'] = self::build_setting_remediations($item);
        $item['advanced_overrides'] = self::build_advanced_overrides($item, $index_item);
        $item['preview_hash'] = self::build_preview_hash($item, $payload, $index_item);

        return $item;
    }

    /**
     * @param array<string,mixed> $item
     * @return array<int,array<string,string>>
     */
    private static function build_import_settings_links(array $item)
    {
        $blocking_codes = array_column((array) ($item['blocking'] ?? []), 'code');
        if (
            ! in_array('post_creation_disabled', $blocking_codes, true)
            && ! in_array('post_type_whitelist_blocked', $blocking_codes, true)
        ) {
            return [];
        }

        $links = [
            [
                'id'    => 'import_settings',
                'label' => __('Open Import Settings', 'dbvc'),
                'url'   => admin_url('admin.php?page=dbvc-export&tab=tab-config&subtab=dbvc-config-import&import_subtab=dbvc-config-import-settings#dbvc-config-import-settings'),
            ],
        ];

        if (in_array('post_type_whitelist_blocked', $blocking_codes, true)) {
            $links[] = [
                'id'    => 'post_type_settings',
                'label' => __('Open Post Type Settings', 'dbvc'),
                'url'   => admin_url('admin.php?page=dbvc-export&tab=tab-config&subtab=dbvc-config-post-types#dbvc-config-post-types'),
            ];
        }

        return $links;
    }

    /**
     * @param array<string,mixed>      $item
     * @param array<string,mixed>      $payload
     * @param array<string,mixed>|null $index_item
     * @return array<int,array<string,mixed>>
     */
    private static function build_blocker_details(array $item, array $payload = [], $index_item = null)
    {
        $details = [];
        $blocking = isset($item['blocking']) && \is_array($item['blocking']) ? $item['blocking'] : [];
        foreach ($blocking as $blocker) {
            if (! \is_array($blocker)) {
                continue;
            }

            $code = isset($blocker['code']) ? (string) $blocker['code'] : '';
            $detail = [
                'code'     => $code,
                'message'  => isset($blocker['message']) ? (string) $blocker['message'] : '',
                'severity' => 'error',
                'category' => __('Import blocker', 'dbvc'),
            ];

            if ($code === 'post_creation_disabled') {
                $detail['category'] = __('Configuration', 'dbvc');
                $detail['option'] = 'dbvc_allow_new_posts';
                $detail['current_value'] = (string) get_option('dbvc_allow_new_posts', '0');
                $detail['expected_value'] = '1';
            } elseif ($code === 'post_type_whitelist_blocked') {
                $detail['category'] = __('Configuration', 'dbvc');
                $detail['option'] = 'dbvc_new_post_types_whitelist';
                $detail['post_type'] = isset($item['subtype']) ? sanitize_key((string) $item['subtype']) : '';
                $detail['current_value'] = self::normalize_option_list(get_option('dbvc_new_post_types_whitelist', []));
            } elseif ($code === 'stale_duplicate_file') {
                $detail['category'] = __('Duplicate sync file', 'dbvc');
                $canonical_path = self::get_index_canonical_path($index_item);
                if ($canonical_path !== '') {
                    $detail['canonical_relative_path'] = $canonical_path;
                }
                if (\is_array($index_item) && ! empty($index_item['duplicate_group']) && \is_array($index_item['duplicate_group'])) {
                    $detail['duplicate_group_key'] = isset($index_item['duplicate_group']['key']) ? (string) $index_item['duplicate_group']['key'] : '';
                }
            } elseif ($code === 'matched_entity') {
                $detail['category'] = __('Existing entity', 'dbvc');
                if (isset($item['match']) && \is_array($item['match'])) {
                    $detail['match'] = $item['match'];
                }
            } elseif (in_array($code, ['missing_post_type', 'missing_taxonomy', 'unsupported_entity_kind'], true)) {
                $detail['category'] = __('Unsupported entity type', 'dbvc');
                if (isset($payload['post_type'])) {
                    $detail['post_type'] = sanitize_key((string) $payload['post_type']);
                }
                if (isset($payload['taxonomy'])) {
                    $detail['taxonomy'] = sanitize_key((string) $payload['taxonomy']);
                }
            }

            $details[] = $detail;
        }

        return $details;
    }

    /**
     * @param array<string,mixed> $item
     * @return array<int,array<string,mixed>>
     */
    private static function build_setting_remediations(array $item)
    {
        $remediations = [];
        $blocking_codes = array_column((array) ($item['blocking'] ?? []), 'code');
        if (in_array('post_creation_disabled', $blocking_codes, true)) {
            $remediations[] = [
                'id'                    => 'enable_new_post_creation',
                'label'                 => __('Enable new post creation', 'dbvc'),
                'description'           => __('Set DBVC to allow new post/CPT entities during import.', 'dbvc'),
                'option'                => 'dbvc_allow_new_posts',
                'new_value'             => '1',
                'requires_confirmation' => true,
            ];
        }

        if (in_array('post_type_whitelist_blocked', $blocking_codes, true)) {
            $post_type = isset($item['subtype']) ? sanitize_key((string) $item['subtype']) : '';
            if ($post_type !== '') {
                $remediations[] = [
                    'id'                    => 'allow_post_type_creation',
                    'label'                 => sprintf(__('Allow %s imports', 'dbvc'), $post_type),
                    'description'           => sprintf(__('Add "%s" to the DBVC new-post import whitelist.', 'dbvc'), $post_type),
                    'option'                => 'dbvc_new_post_types_whitelist',
                    'post_type'             => $post_type,
                    'requires_confirmation' => true,
                ];
            }
        }

        return $remediations;
    }

    /**
     * @param array<string,mixed>      $item
     * @param array<string,mixed>|null $index_item
     * @return array<int,array<string,mixed>>
     */
    private static function build_advanced_overrides(array $item, $index_item = null)
    {
        $blocking_codes = array_column((array) ($item['blocking'] ?? []), 'code');
        if (! in_array('stale_duplicate_file', $blocking_codes, true)) {
            return [];
        }

        $canonical_path = self::get_index_canonical_path($index_item);
        if ($canonical_path === '') {
            return [];
        }

        return [
            [
                'id'                      => 'use_canonical_row',
                'label'                   => __('Use canonical row', 'dbvc'),
                'description'             => __('Refresh this preview against the canonical sync JSON for the same entity.', 'dbvc'),
                'canonical_relative_path' => $canonical_path,
                'requires_confirmation'   => false,
            ],
            [
                'id'                      => 'archive_stale_duplicate',
                'label'                   => __('Archive stale duplicate', 'dbvc'),
                'description'             => __('Move only this stale duplicate JSON into the Entity Editor backup folder, then preview the canonical file.', 'dbvc'),
                'canonical_relative_path' => $canonical_path,
                'requires_confirmation'   => true,
            ],
        ];
    }

    /**
     * @param array<string,mixed>      $item
     * @param array<string,mixed>      $payload
     * @param array<string,mixed>|null $index_item
     * @return string
     */
    private static function build_preview_hash(array $item, array $payload = [], $index_item = null)
    {
        $blocking_codes = array_column((array) ($item['blocking'] ?? []), 'code');
        $payload_hash = '';
        if (! empty($payload)) {
            $encoded_payload = wp_json_encode($payload);
            $payload_hash = \is_string($encoded_payload) ? hash('sha256', $encoded_payload) : '';
        }
        $hash_payload = [
            'relative_path'                   => isset($item['relative_path']) ? (string) $item['relative_path'] : '',
            'payload_id'                      => isset($payload['ID']) ? (string) $payload['ID'] : (isset($payload['term_id']) ? (string) $payload['term_id'] : ''),
            'payload_hash'                    => $payload_hash,
            'entity_kind'                     => isset($item['entity_kind']) ? (string) $item['entity_kind'] : '',
            'subtype'                         => isset($item['subtype']) ? (string) $item['subtype'] : '',
            'slug'                            => isset($item['slug']) ? (string) $item['slug'] : '',
            'uid'                             => isset($item['uid']) ? (string) $item['uid'] : '',
            'blocking_codes'                  => array_values(array_map('strval', $blocking_codes)),
            'canonical_relative_path'         => self::get_index_canonical_path($index_item),
            'match_status'                    => isset($item['match']['status']) ? (string) $item['match']['status'] : '',
            'match_id'                        => isset($item['match']['id']) ? (int) $item['match']['id'] : 0,
            'dbvc_allow_new_posts'            => (string) get_option('dbvc_allow_new_posts', '0'),
            'dbvc_new_post_types_whitelist'   => self::normalize_option_list(get_option('dbvc_new_post_types_whitelist', [])),
        ];

        return hash('sha256', (string) wp_json_encode($hash_payload));
    }

    /**
     * @param array<string|int,mixed> $confirmations
     * @param string                  $relative_path
     * @return array<string,mixed>|null
     */
    private static function find_update_confirmation(array $confirmations, $relative_path)
    {
        $relative_path = str_replace('\\', '/', ltrim((string) $relative_path, '/'));
        foreach ($confirmations as $key => $confirmation) {
            if (! \is_array($confirmation)) {
                continue;
            }

            $candidate_path = \is_string($key) ? $key : (isset($confirmation['path']) ? (string) $confirmation['path'] : '');
            $candidate_path = str_replace('\\', '/', ltrim($candidate_path, '/'));
            if ($candidate_path !== '' && $candidate_path === $relative_path) {
                return $confirmation;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $index_item
     * @return string
     */
    private static function get_index_canonical_path($index_item = null)
    {
        if (! \is_array($index_item) || empty($index_item['duplicate_group']) || ! \is_array($index_item['duplicate_group'])) {
            return '';
        }

        return isset($index_item['duplicate_group']['canonical_relative_path'])
            ? str_replace('\\', '/', ltrim((string) $index_item['duplicate_group']['canonical_relative_path'], '/'))
            : '';
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private static function normalize_option_list($value)
    {
        $values = \is_array($value) ? $value : [];
        return array_values(array_unique(array_filter(array_map('sanitize_key', $values))));
    }

    /**
     * Build read-only Bricks template import guidance for the preview modal.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $match
     * @return array<string,mixed>|null
     */
    private static function build_bricks_template_advisory(array $payload, array $match = [])
    {
        if (sanitize_key((string) ($payload['post_type'] ?? '')) !== 'bricks_template') {
            return null;
        }

        if (
            ! \class_exists('\DBVC_Bricks_Addon')
            || ! \method_exists('\DBVC_Bricks_Addon', 'is_enabled')
            || ! \DBVC_Bricks_Addon::is_enabled()
        ) {
            return null;
        }

        $settings = self::extract_bricks_template_settings($payload);
        $template_type = self::extract_bricks_template_type($payload);
        $conditions = self::extract_bricks_template_conditions($settings);
        $condition_summaries = self::summarize_bricks_template_conditions($conditions);
        $exclude_ids = [];

        if (isset($payload['ID'])) {
            $exclude_ids[] = absint($payload['ID']);
        }
        if (($match['status'] ?? '') === 'matched' && ! empty($match['id'])) {
            $exclude_ids[] = absint($match['id']);
        }

        $conflicts = self::find_bricks_template_condition_conflicts($template_type, $conditions, $exclude_ids);
        $preview_post = self::build_bricks_preview_post_advisory($settings);
        $preview_term = self::build_bricks_preview_term_advisory($settings);

        $messages = [];
        foreach ($conflicts as $conflict) {
            $title = isset($conflict['title']) && $conflict['title'] !== '' ? (string) $conflict['title'] : __('Untitled Bricks template', 'dbvc');
            $id = isset($conflict['id']) ? (int) $conflict['id'] : 0;
            $messages[] = sprintf(
                __('Existing published Bricks template "%1$s" (#%2$d) has overlapping conditions and may render instead.', 'dbvc'),
                $title,
                $id
            );
        }

        if (\is_array($preview_post) && empty($preview_post['exists_locally']) && ! empty($preview_post['incoming_id'])) {
            $messages[] = sprintf(
                __('Preview post ID %d does not exist locally.', 'dbvc'),
                (int) $preview_post['incoming_id']
            );
        }

        if (\is_array($preview_term) && empty($preview_term['exists_locally']) && ! empty($preview_term['incoming_term_id'])) {
            $messages[] = sprintf(
                __('Preview term ID %d does not exist locally.', 'dbvc'),
                (int) $preview_term['incoming_term_id']
            );
        }

        $severity = (! empty($conflicts) || ! empty($messages)) ? 'warning' : 'info';

        return [
            'enabled'             => true,
            'severity'            => $severity,
            'template_type'       => $template_type,
            'conditions'          => $condition_summaries,
            'condition_conflicts' => $conflicts,
            'preview_post'        => $preview_post,
            'preview_term'        => $preview_term,
            'messages'            => array_values(array_unique($messages)),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function extract_bricks_template_settings(array $payload)
    {
        $settings = self::extract_bricks_template_meta_value($payload, '_bricks_template_settings');
        return \is_array($settings) ? $settings : [];
    }

    /**
     * @param array<string,mixed> $payload
     * @return string
     */
    private static function extract_bricks_template_type(array $payload)
    {
        return self::normalize_bricks_scalar(self::extract_bricks_template_meta_value($payload, '_bricks_template_type'));
    }

    /**
     * @param array<string,mixed> $payload
     * @param string              $meta_key
     * @return mixed|null
     */
    private static function extract_bricks_template_meta_value(array $payload, $meta_key)
    {
        $meta = isset($payload['meta']) && \is_array($payload['meta']) ? $payload['meta'] : [];
        if (! array_key_exists($meta_key, $meta)) {
            return null;
        }

        return self::unwrap_single_meta_value($meta[$meta_key]);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function unwrap_single_meta_value($value)
    {
        if (\is_array($value) && self::is_list_array($value) && count($value) === 1) {
            return reset($value);
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function normalize_bricks_scalar($value)
    {
        $value = self::unwrap_single_meta_value($value);
        if (\is_array($value)) {
            foreach ($value as $candidate) {
                if (\is_scalar($candidate)) {
                    return sanitize_key((string) $candidate);
                }
            }
            return '';
        }

        return \is_scalar($value) ? sanitize_key((string) $value) : '';
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<int,array<string,mixed>>
     */
    private static function extract_bricks_template_conditions(array $settings)
    {
        $conditions = isset($settings['templateConditions']) && \is_array($settings['templateConditions'])
            ? $settings['templateConditions']
            : [];

        $normalized = [];
        foreach ($conditions as $condition) {
            if (\is_array($condition)) {
                $normalized[] = $condition;
            }
        }

        return $normalized;
    }

    /**
     * @param array<int,array<string,mixed>> $conditions
     * @return array<int,string>
     */
    private static function summarize_bricks_template_conditions(array $conditions)
    {
        $summaries = [];
        foreach ($conditions as $condition) {
            $summaries[] = self::summarize_bricks_template_condition($condition);
        }

        return array_values(array_filter(array_unique($summaries)));
    }

    /**
     * @param array<string,mixed> $condition
     * @return string
     */
    private static function summarize_bricks_template_condition(array $condition)
    {
        $main = isset($condition['main']) ? sanitize_key((string) $condition['main']) : '';
        if ($main === 'posttype') {
            $post_types = self::bricks_condition_values($condition, 'postType');
            return $post_types ? 'postType: ' . implode(', ', $post_types) : 'postType';
        }

        if ($main === 'archivetype') {
            $parts = ['archiveType'];
            $archive_types = self::bricks_condition_values($condition, 'archiveType');
            $archive_terms = self::bricks_condition_values($condition, 'archiveTerms');
            if ($archive_types) {
                $parts[] = implode(', ', $archive_types);
            }
            if ($archive_terms) {
                $parts[] = 'terms: ' . implode(', ', $archive_terms);
            }
            return implode(' - ', $parts);
        }

        return $main !== '' ? $main : __('Bricks template condition', 'dbvc');
    }

    /**
     * @param string                     $template_type
     * @param array<int,array<string,mixed>> $incoming_conditions
     * @param array<int,int>             $exclude_ids
     * @return array<int,array<string,mixed>>
     */
    private static function find_bricks_template_condition_conflicts($template_type, array $incoming_conditions, array $exclude_ids = [])
    {
        if (empty($incoming_conditions) || ! post_type_exists('bricks_template')) {
            return [];
        }

        $exclude_ids = array_values(array_unique(array_filter(array_map('absint', $exclude_ids))));
        $template_ids = get_posts([
            'post_type'        => 'bricks_template',
            'post_status'      => 'publish',
            'fields'           => 'ids',
            'posts_per_page'   => 50,
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ]);

        $conflicts = [];
        foreach ($template_ids as $template_id) {
            $template_id = absint($template_id);
            if ($template_id <= 0 || in_array($template_id, $exclude_ids, true)) {
                continue;
            }

            $existing_type = self::normalize_bricks_scalar(get_post_meta($template_id, '_bricks_template_type', true));
            if ($template_type !== '' && $existing_type !== '' && $template_type !== $existing_type) {
                continue;
            }

            $existing_settings = self::unwrap_single_meta_value(get_post_meta($template_id, '_bricks_template_settings', true));
            $existing_conditions = \is_array($existing_settings) ? self::extract_bricks_template_conditions($existing_settings) : [];
            if (empty($existing_conditions)) {
                continue;
            }

            foreach ($incoming_conditions as $incoming_condition) {
                foreach ($existing_conditions as $existing_condition) {
                    $overlap = self::describe_bricks_condition_overlap($incoming_condition, $existing_condition);
                    if (! $overlap) {
                        continue;
                    }

                    $title = (string) get_the_title($template_id);
                    $conflicts[] = [
                        'id'                 => $template_id,
                        'title'              => $title,
                        'status'             => 'publish',
                        'template_type'      => $existing_type,
                        'reason'             => $overlap,
                        'condition'          => self::summarize_bricks_template_condition($existing_condition),
                        'incoming_condition' => self::summarize_bricks_template_condition($incoming_condition),
                        'edit_url'           => get_edit_post_link($template_id, 'raw') ?: '',
                    ];
                    break 2;
                }
            }

            if (count($conflicts) >= 10) {
                break;
            }
        }

        return $conflicts;
    }

    /**
     * @param array<string,mixed> $incoming
     * @param array<string,mixed> $existing
     * @return string
     */
    private static function describe_bricks_condition_overlap(array $incoming, array $existing)
    {
        if (self::normalize_bricks_condition_for_compare($incoming) === self::normalize_bricks_condition_for_compare($existing)) {
            return __('Exact template condition match.', 'dbvc');
        }

        $incoming_main = isset($incoming['main']) ? sanitize_key((string) $incoming['main']) : '';
        $existing_main = isset($existing['main']) ? sanitize_key((string) $existing['main']) : '';
        if ($incoming_main === '' || $incoming_main !== $existing_main) {
            return '';
        }

        if ($incoming_main === 'posttype') {
            $overlap = array_intersect(
                self::bricks_condition_values($incoming, 'postType'),
                self::bricks_condition_values($existing, 'postType')
            );
            return $overlap ? sprintf(__('Overlapping post type condition: %s.', 'dbvc'), implode(', ', $overlap)) : '';
        }

        if ($incoming_main === 'archivetype') {
            $archive_overlap = array_intersect(
                self::bricks_condition_values($incoming, 'archiveType'),
                self::bricks_condition_values($existing, 'archiveType')
            );
            if (empty($archive_overlap)) {
                return '';
            }

            $incoming_terms = self::bricks_condition_values($incoming, 'archiveTerms');
            $existing_terms = self::bricks_condition_values($existing, 'archiveTerms');
            if (empty($incoming_terms) || empty($existing_terms) || array_intersect($incoming_terms, $existing_terms)) {
                return sprintf(__('Overlapping archive condition: %s.', 'dbvc'), implode(', ', $archive_overlap));
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $condition
     * @return array<string,mixed>
     */
    private static function normalize_bricks_condition_for_compare(array $condition)
    {
        unset($condition['id']);
        return self::normalize_bricks_compare_value($condition);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalize_bricks_compare_value($value)
    {
        if (! \is_array($value)) {
            return \is_scalar($value) || $value === null ? (string) $value : '';
        }

        $normalized = [];
        foreach ($value as $key => $child) {
            $normalized[$key] = self::normalize_bricks_compare_value($child);
        }

        if (self::is_list_array($normalized)) {
            sort($normalized);
        } else {
            ksort($normalized);
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $condition
     * @param string              $key
     * @return array<int,string>
     */
    private static function bricks_condition_values(array $condition, $key)
    {
        if (! array_key_exists($key, $condition)) {
            return [];
        }

        $value = $condition[$key];
        $values = \is_array($value) ? $value : [$value];
        $normalized = [];
        foreach ($values as $candidate) {
            if (\is_scalar($candidate)) {
                $candidate = sanitize_text_field((string) $candidate);
                if ($candidate !== '') {
                    $normalized[] = $candidate;
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>|null
     */
    private static function build_bricks_preview_post_advisory(array $settings)
    {
        if (! array_key_exists('templatePreviewPostId', $settings)) {
            return null;
        }

        $incoming_id = absint($settings['templatePreviewPostId']);
        if ($incoming_id <= 0) {
            return null;
        }

        $post = get_post($incoming_id);
        $exists = $post instanceof \WP_Post;

        return [
            'incoming_id'    => $incoming_id,
            'exists_locally' => $exists,
            'post_type'      => $exists ? (string) $post->post_type : '',
            'title'          => $exists ? (string) get_the_title($incoming_id) : '',
            'edit_url'       => $exists ? (get_edit_post_link($incoming_id, 'raw') ?: '') : '',
        ];
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>|null
     */
    private static function build_bricks_preview_term_advisory(array $settings)
    {
        if (! array_key_exists('templatePreviewTerm', $settings)) {
            return null;
        }

        $raw = (string) $settings['templatePreviewTerm'];
        if ($raw === '') {
            return null;
        }

        $taxonomy = '';
        $term_id = 0;
        if (strpos($raw, '::') !== false) {
            [$taxonomy, $term_id_raw] = array_pad(explode('::', $raw, 2), 2, '');
            $taxonomy = sanitize_key((string) $taxonomy);
            $term_id = absint($term_id_raw);
        } else {
            $term_id = absint($raw);
        }

        if ($term_id <= 0) {
            return null;
        }

        $term = $taxonomy !== '' ? get_term($term_id, $taxonomy) : get_term($term_id);
        $exists = $term && ! \is_wp_error($term);

        return [
            'incoming_value'   => $raw,
            'incoming_term_id' => $term_id,
            'taxonomy'         => $taxonomy,
            'exists_locally'   => $exists,
            'name'             => $exists ? (string) $term->name : '',
            'edit_url'         => $exists ? get_edit_term_link($term_id, $taxonomy ?: $term->taxonomy) : '',
        ];
    }

    /**
     * @param array<mixed> $value
     * @return bool
     */
    private static function is_list_array(array $value)
    {
        $index = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $index) {
                return false;
            }
            $index++;
        }

        return true;
    }

    /**
     * @param string $relative_path
     * @return array<string,mixed>|null
     */
    private static function find_index_item($relative_path)
    {
        if (! \class_exists('DBVC_Entity_Editor_Indexer')) {
            return null;
        }

        $normalized = str_replace('\\', '/', ltrim((string) $relative_path, '/'));
        $index = \DBVC_Entity_Editor_Indexer::get_index(false);
        $items = isset($index['items']) && \is_array($index['items']) ? $index['items'] : [];
        foreach ($items as $item) {
            if (! \is_array($item)) {
                continue;
            }

            $item_path = isset($item['relative_path']) ? str_replace('\\', '/', ltrim((string) $item['relative_path'], '/')) : '';
            if ($item_path === $normalized) {
                return $item;
            }
        }

        $index = \DBVC_Entity_Editor_Indexer::get_index(true);
        $items = isset($index['items']) && \is_array($index['items']) ? $index['items'] : [];
        foreach ($items as $item) {
            if (! \is_array($item)) {
                continue;
            }

            $item_path = isset($item['relative_path']) ? str_replace('\\', '/', ltrim((string) $item['relative_path'], '/')) : '';
            if ($item_path === $normalized) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<string,int>
     */
    private static function build_preview_summary(array $items)
    {
        $summary = [
            'requested' => count($items),
            'creatable' => 0,
            'updatable' => 0,
            'blocked'   => 0,
            'skipped'   => 0,
        ];

        foreach ($items as $item) {
            $blocking = isset($item['blocking']) && \is_array($item['blocking']) ? $item['blocking'] : [];
            $matched_update = isset($item['matched_update']) && \is_array($item['matched_update']) ? $item['matched_update'] : [];
            if (empty($blocking) && isset($item['detected_action']) && $item['detected_action'] === 'create') {
                $summary['creatable']++;
            } elseif (! empty($matched_update['eligible']) && ! empty($item['available_actions'][self::MODE_UPDATE_MATCHED])) {
                $summary['updatable']++;
            } elseif (! empty($blocking)) {
                $summary['blocked']++;
            } else {
                $summary['skipped']++;
            }
        }

        return $summary;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<string,int>
     */
    private static function build_commit_summary(array $items)
    {
        $summary = [
            'requested' => count($items),
            'created'   => 0,
            'updated'   => 0,
            'blocked'   => 0,
            'skipped'   => 0,
            'errors'    => 0,
        ];

        foreach ($items as $item) {
            $status = isset($item['status']) ? (string) $item['status'] : '';
            if (! empty($item['created'])) {
                $summary['created']++;
            } elseif (! empty($item['updated']) || $status === 'updated') {
                $summary['updated']++;
            } elseif ($status === 'blocked') {
                $summary['blocked']++;
            } elseif ($status === 'skipped') {
                $summary['skipped']++;
            } elseif ($status === 'error') {
                $summary['errors']++;
            } else {
                $blocking = isset($item['blocking']) && \is_array($item['blocking']) ? $item['blocking'] : [];
                if (! empty($blocking)) {
                    $summary['blocked']++;
                } else {
                    $summary['skipped']++;
                }
            }
        }

        return $summary;
    }

    /**
     * Move the importer-rewritten source JSON to the final canonical filename.
     *
     * @param string              $source_relative_path
     * @param string              $source_absolute_path
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|\WP_Error
     */
    private static function normalize_imported_file_path($source_relative_path, $source_absolute_path, array $payload)
    {
        $source_relative_path = str_replace('\\', '/', ltrim((string) $source_relative_path, '/'));
        $kind = self::detect_kind($payload);
        $canonical_relative_path = '';
        if ($kind === 'term') {
            $taxonomy = sanitize_key((string) ($payload['taxonomy'] ?? ''));
            $canonical_relative_path = self::build_canonical_term_relative_path($payload, $taxonomy);
        } else {
            $post_type = sanitize_key((string) ($payload['post_type'] ?? ''));
            $canonical_relative_path = self::build_canonical_post_relative_path($payload, $post_type);
        }

        $state = [
            'source_relative_path'    => $source_relative_path,
            'relative_path'           => $source_relative_path,
            'canonical_relative_path' => $canonical_relative_path,
            'moved'                   => false,
            'replaced_existing'       => false,
            'removed_duplicate'       => false,
            'backup_path'             => '',
            'archived_duplicates'     => [],
            'archive_errors'          => [],
        ];

        if ($canonical_relative_path === '') {
            return $state;
        }

        if ($canonical_relative_path === $source_relative_path) {
            return self::with_stale_duplicate_archival($state, $canonical_relative_path);
        }

        if (! is_file($source_absolute_path)) {
            return new \WP_Error(
                'dbvc_entity_editor_sync_import_source_missing_after_import',
                __('The imported source JSON could not be found for canonical filename normalization.', 'dbvc'),
                ['status' => 500]
            );
        }

        $target_absolute_path = self::resolve_writable_sync_path($canonical_relative_path);
        if (\is_wp_error($target_absolute_path)) {
            return $target_absolute_path;
        }

        if (is_file($target_absolute_path)) {
            $source_hash = hash_file('sha256', $source_absolute_path);
            $target_hash = hash_file('sha256', $target_absolute_path);
            if ($source_hash && $target_hash && hash_equals((string) $source_hash, (string) $target_hash)) {
                if (! @unlink($source_absolute_path)) {
                    return new \WP_Error(
                        'dbvc_entity_editor_sync_import_duplicate_cleanup_failed',
                        __('The imported JSON matched the canonical file, but the old source filename could not be removed.', 'dbvc'),
                        ['status' => 500]
                    );
                }

                $state['relative_path'] = $canonical_relative_path;
                $state['removed_duplicate'] = true;
                return self::with_stale_duplicate_archival($state, $canonical_relative_path);
            }

            $backup_path = self::backup_existing_sync_file($target_absolute_path, $canonical_relative_path);
            if (\is_wp_error($backup_path)) {
                return $backup_path;
            }

            if (! @unlink($target_absolute_path)) {
                return new \WP_Error(
                    'dbvc_entity_editor_sync_import_target_replace_failed',
                    __('The existing canonical JSON file could not be replaced after backup.', 'dbvc'),
                    ['status' => 500]
                );
            }

            $state['backup_path'] = $backup_path;
            $state['replaced_existing'] = true;
        }

        if (! @rename($source_absolute_path, $target_absolute_path)) {
            return new \WP_Error(
                'dbvc_entity_editor_sync_import_canonical_move_failed',
                __('The imported JSON could not be moved to its canonical filename.', 'dbvc'),
                ['status' => 500]
            );
        }

        $state['relative_path'] = $canonical_relative_path;
        $state['moved'] = true;

        return self::with_stale_duplicate_archival($state, $canonical_relative_path);
    }

    /**
     * @param array<string,mixed> $state
     * @param string              $canonical_relative_path
     * @return array<string,mixed>
     */
    private static function with_stale_duplicate_archival(array $state, $canonical_relative_path)
    {
        $cleanup = self::archive_stale_duplicate_files((string) $canonical_relative_path);
        $state['archived_duplicates'] = isset($cleanup['archived']) && \is_array($cleanup['archived'])
            ? $cleanup['archived']
            : [];
        $state['archive_errors'] = isset($cleanup['errors']) && \is_array($cleanup['errors'])
            ? $cleanup['errors']
            : [];

        return $state;
    }

    /**
     * Archive older duplicate sync files that point to the same live entity as the canonical file.
     *
     * @param string $canonical_relative_path
     * @return array{archived:array<int,array<string,string>>,errors:array<int,array<string,string>>}
     */
    private static function archive_stale_duplicate_files($canonical_relative_path)
    {
        $result = [
            'archived' => [],
            'errors'  => [],
        ];

        $canonical_relative_path = str_replace('\\', '/', ltrim((string) $canonical_relative_path, '/'));
        if ($canonical_relative_path === '' || ! \class_exists('DBVC_Entity_Editor_Indexer')) {
            return $result;
        }

        delete_transient('dbvc_entity_editor_index_v1');
        $index = \DBVC_Entity_Editor_Indexer::get_index(true);
        $items = isset($index['items']) && \is_array($index['items']) ? $index['items'] : [];
        $canonical_item = null;
        foreach ($items as $item) {
            if (! \is_array($item)) {
                continue;
            }
            $path = isset($item['relative_path']) ? str_replace('\\', '/', ltrim((string) $item['relative_path'], '/')) : '';
            if ($path === $canonical_relative_path) {
                $canonical_item = $item;
                break;
            }
        }

        if (! \is_array($canonical_item) || empty($canonical_item['duplicate_group']) || ! \is_array($canonical_item['duplicate_group'])) {
            return $result;
        }

        $group_key = isset($canonical_item['duplicate_group']['key']) ? (string) $canonical_item['duplicate_group']['key'] : '';
        if ($group_key === '') {
            return $result;
        }

        foreach ($items as $item) {
            if (! \is_array($item)) {
                continue;
            }

            $path = isset($item['relative_path']) ? str_replace('\\', '/', ltrim((string) $item['relative_path'], '/')) : '';
            if ($path === '' || $path === $canonical_relative_path) {
                continue;
            }

            $duplicate_group = isset($item['duplicate_group']) && \is_array($item['duplicate_group']) ? $item['duplicate_group'] : [];
            $item_group_key = isset($duplicate_group['key']) ? (string) $duplicate_group['key'] : '';
            if ($item_group_key === '' || $item_group_key !== $group_key || ! empty($item['is_canonical_duplicate'])) {
                continue;
            }

            $absolute_path = \DBVC_Entity_Editor_Indexer::resolve_entity_file_path_for_import($path);
            if (\is_wp_error($absolute_path)) {
                $result['errors'][] = [
                    'relative_path' => $path,
                    'message'       => (string) $absolute_path->get_error_message(),
                ];
                continue;
            }

            $backup_path = self::backup_existing_sync_file((string) $absolute_path, $path);
            if (\is_wp_error($backup_path)) {
                $result['errors'][] = [
                    'relative_path' => $path,
                    'message'       => (string) $backup_path->get_error_message(),
                ];
                continue;
            }

            if (! @unlink((string) $absolute_path)) {
                $result['errors'][] = [
                    'relative_path' => $path,
                    'message'       => __('The stale duplicate sync file could not be removed after backup.', 'dbvc'),
                ];
                continue;
            }

            $result['archived'][] = [
                'relative_path' => $path,
                'backup_path'   => (string) $backup_path,
            ];
        }

        if (! empty($result['archived'])) {
            delete_transient('dbvc_entity_editor_index_v1');
        }

        return $result;
    }

    /**
     * Archive one explicitly selected stale duplicate sync file.
     *
     * @param string $relative_path
     * @param string $canonical_relative_path
     * @return array<string,string>|\WP_Error
     */
    private static function archive_single_stale_duplicate_file($relative_path, $canonical_relative_path)
    {
        $relative_path = str_replace('\\', '/', ltrim((string) $relative_path, '/'));
        $canonical_relative_path = str_replace('\\', '/', ltrim((string) $canonical_relative_path, '/'));
        if ($relative_path === '' || $canonical_relative_path === '' || $relative_path === $canonical_relative_path) {
            return new \WP_Error('dbvc_entity_editor_sync_import_archive_invalid_duplicate', __('Select a stale duplicate file that is different from the canonical file.', 'dbvc'), ['status' => 400]);
        }

        if (! \class_exists('DBVC_Entity_Editor_Indexer')) {
            return new \WP_Error('dbvc_entity_editor_sync_import_unavailable', __('Entity Editor indexer is unavailable.', 'dbvc'), ['status' => 500]);
        }

        delete_transient('dbvc_entity_editor_index_v1');
        $index = \DBVC_Entity_Editor_Indexer::get_index(true);
        $items = isset($index['items']) && \is_array($index['items']) ? $index['items'] : [];
        $selected_item = null;
        foreach ($items as $item) {
            if (! \is_array($item)) {
                continue;
            }

            $path = isset($item['relative_path']) ? str_replace('\\', '/', ltrim((string) $item['relative_path'], '/')) : '';
            if ($path === $relative_path) {
                $selected_item = $item;
                break;
            }
        }

        if (
            ! \is_array($selected_item)
            || empty($selected_item['is_duplicate'])
            || ! empty($selected_item['is_canonical_duplicate'])
            || self::get_index_canonical_path($selected_item) !== $canonical_relative_path
        ) {
            return new \WP_Error('dbvc_entity_editor_sync_import_archive_not_stale_duplicate', __('This file is no longer a stale duplicate of the selected canonical file. Refresh the preview.', 'dbvc'), ['status' => 409]);
        }

        $absolute_path = \DBVC_Entity_Editor_Indexer::resolve_entity_file_path_for_import($relative_path);
        if (\is_wp_error($absolute_path)) {
            return $absolute_path;
        }

        $backup_path = self::backup_existing_sync_file((string) $absolute_path, $relative_path);
        if (\is_wp_error($backup_path)) {
            return $backup_path;
        }

        if (! @unlink((string) $absolute_path)) {
            return new \WP_Error('dbvc_entity_editor_sync_import_archive_unlink_failed', __('The stale duplicate sync file could not be removed after backup.', 'dbvc'), ['status' => 500]);
        }

        delete_transient('dbvc_entity_editor_index_v1');

        return [
            'relative_path'           => $relative_path,
            'canonical_relative_path' => $canonical_relative_path,
            'backup_path'             => (string) $backup_path,
        ];
    }

    /**
     * @param array<string,mixed>|\WP_Error $preview
     * @param array<string,mixed>           $result
     * @return array<string,mixed>|\WP_Error
     */
    private static function with_remediation_result($preview, array $result)
    {
        if (\is_wp_error($preview)) {
            return $preview;
        }

        $preview['remediation_result'] = $result;
        return $preview;
    }

    /**
     * @param array<string,mixed> $item
     * @param string              $id
     * @return bool
     */
    private static function item_has_setting_remediation(array $item, $id)
    {
        $remediations = isset($item['setting_remediations']) && \is_array($item['setting_remediations']) ? $item['setting_remediations'] : [];
        foreach ($remediations as $remediation) {
            if (\is_array($remediation) && isset($remediation['id']) && (string) $remediation['id'] === (string) $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $item
     * @param string              $id
     * @return bool
     */
    private static function item_has_advanced_override(array $item, $id)
    {
        $overrides = isset($item['advanced_overrides']) && \is_array($item['advanced_overrides']) ? $item['advanced_overrides'] : [];
        foreach ($overrides as $override) {
            if (\is_array($override) && isset($override['id']) && (string) $override['id'] === (string) $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    private static function extract_item_canonical_path(array $item)
    {
        if (! empty($item['canonical_relative_path'])) {
            return str_replace('\\', '/', ltrim((string) $item['canonical_relative_path'], '/'));
        }

        $details = isset($item['blocker_details']) && \is_array($item['blocker_details']) ? $item['blocker_details'] : [];
        foreach ($details as $detail) {
            if (\is_array($detail) && ! empty($detail['canonical_relative_path'])) {
                return str_replace('\\', '/', ltrim((string) $detail['canonical_relative_path'], '/'));
            }
        }

        $overrides = isset($item['advanced_overrides']) && \is_array($item['advanced_overrides']) ? $item['advanced_overrides'] : [];
        foreach ($overrides as $override) {
            if (\is_array($override) && ! empty($override['canonical_relative_path'])) {
                return str_replace('\\', '/', ltrim((string) $override['canonical_relative_path'], '/'));
            }
        }

        return '';
    }

    /**
     * @param string              $action
     * @param string              $relative_path
     * @param int                 $user_id
     * @param array<string,mixed> $context
     * @return void
     */
    private static function log_remediation($action, $relative_path, $user_id, array $context = [])
    {
        if (! \class_exists('DBVC_Sync_Logger')) {
            return;
        }

        \DBVC_Sync_Logger::log('Entity Editor sync file import remediation', array_merge([
            'action'        => (string) $action,
            'relative_path' => (string) $relative_path,
            'user_id'       => (int) $user_id,
        ], $context));
    }

    /**
     * @param string $relative_path
     * @return string|\WP_Error
     */
    private static function resolve_writable_sync_path($relative_path)
    {
        $relative_path = str_replace('\\', '/', ltrim((string) $relative_path, '/'));
        if ($relative_path === '' || strpos($relative_path, '..') !== false || substr($relative_path, -5) !== '.json') {
            return new \WP_Error('dbvc_entity_editor_sync_import_invalid_target_path', __('Invalid canonical entity file path.', 'dbvc'), ['status' => 400]);
        }

        $sync_real = realpath(\dbvc_get_sync_path());
        if (! $sync_real || ! is_dir($sync_real)) {
            return new \WP_Error('dbvc_entity_editor_sync_missing', __('Sync folder is unavailable.', 'dbvc'), ['status' => 500]);
        }

        $target = trailingslashit($sync_real) . $relative_path;
        $target_dir = dirname($target);
        if (! is_dir($target_dir) && ! wp_mkdir_p($target_dir)) {
            return new \WP_Error('dbvc_entity_editor_sync_import_target_dir_failed', __('Unable to create the canonical sync directory.', 'dbvc'), ['status' => 500]);
        }

        $dir_real = realpath($target_dir);
        $sync_norm = rtrim(str_replace('\\', '/', $sync_real), '/');
        $dir_norm = $dir_real ? rtrim(str_replace('\\', '/', $dir_real), '/') : '';
        if ($dir_norm === '' || ($dir_norm !== $sync_norm && strpos($dir_norm, $sync_norm . '/') !== 0)) {
            return new \WP_Error('dbvc_entity_editor_sync_import_target_escapes_root', __('Canonical entity file path escapes sync folder.', 'dbvc'), ['status' => 400]);
        }

        if (\class_exists('DBVC_Sync_Posts') && method_exists('DBVC_Sync_Posts', 'ensure_directory_security')) {
            \DBVC_Sync_Posts::ensure_directory_security($target_dir);
        }

        return $target;
    }

    /**
     * @param string $absolute_path
     * @param string $relative_path
     * @return string|\WP_Error
     */
    private static function backup_existing_sync_file($absolute_path, $relative_path)
    {
        $sync_real = realpath(\dbvc_get_sync_path());
        if (! $sync_real || ! is_dir($sync_real)) {
            return new \WP_Error('dbvc_entity_editor_sync_missing', __('Sync folder is unavailable.', 'dbvc'), ['status' => 500]);
        }

        $backup_dir = trailingslashit($sync_real) . '.dbvc_entity_editor_backups';
        if (! is_dir($backup_dir) && ! wp_mkdir_p($backup_dir)) {
            return new \WP_Error('dbvc_entity_editor_backup_dir_failed', __('Unable to create the Entity Editor backup directory.', 'dbvc'), ['status' => 500]);
        }

        if (\class_exists('DBVC_Sync_Posts') && method_exists('DBVC_Sync_Posts', 'ensure_directory_security')) {
            \DBVC_Sync_Posts::ensure_directory_security($backup_dir);
        }

        $safe_name = str_replace(['/', '\\'], '__', ltrim((string) $relative_path, '/'));
        $backup_name = $safe_name . '.' . gmdate('Ymd-His') . '.bak.json';
        $backup_path = trailingslashit($backup_dir) . $backup_name;

        if (! @copy($absolute_path, $backup_path)) {
            return new \WP_Error('dbvc_entity_editor_backup_failed', __('Unable to back up the existing canonical file before replacement.', 'dbvc'), ['status' => 500]);
        }

        return '.dbvc_entity_editor_backups/' . $backup_name;
    }

    /**
     * @param string $code
     * @param string $message
     * @return array{code:string,message:string}
     */
    private static function reason($code, $message)
    {
        return [
            'code'    => (string) $code,
            'message' => (string) $message,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return string
     */
    private static function detect_kind(array $payload)
    {
        if (isset($payload['taxonomy']) && (isset($payload['slug']) || isset($payload['term_id']))) {
            return 'term';
        }

        if (isset($payload['post_type']) && (isset($payload['ID']) || isset($payload['post_name']) || isset($payload['post_title']))) {
            return 'post';
        }

        return '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return string
     */
    private static function extract_entity_uid(array $payload)
    {
        $uid = isset($payload['vf_object_uid']) ? (string) $payload['vf_object_uid'] : '';
        if ($uid === '' && isset($payload['dbvc_object_uid'])) {
            $uid = (string) $payload['dbvc_object_uid'];
        }
        if ($uid === '' && isset($payload['meta']) && \is_array($payload['meta'])) {
            if (isset($payload['meta']['vf_object_uid'])) {
                $meta_uid = $payload['meta']['vf_object_uid'];
                if (\is_array($meta_uid)) {
                    $uid = isset($meta_uid[0]) ? (string) $meta_uid[0] : '';
                } else {
                    $uid = (string) $meta_uid;
                }
            }

            if ($uid === '' && isset($payload['meta']['dbvc_post_history'])) {
                $history = $payload['meta']['dbvc_post_history'];
                if (isset($history[0]) && \is_array($history[0]) && isset($history[0]['vf_object_uid'])) {
                    $uid = (string) $history[0]['vf_object_uid'];
                } elseif (\is_array($history) && isset($history['vf_object_uid'])) {
                    $uid = (string) $history['vf_object_uid'];
                }
            }

            if ($uid === '' && isset($payload['meta']['dbvc_term_history'])) {
                $history = $payload['meta']['dbvc_term_history'];
                if (isset($history[0]) && \is_array($history[0]) && isset($history[0]['vf_object_uid'])) {
                    $uid = (string) $history[0]['vf_object_uid'];
                } elseif (\is_array($history) && isset($history['vf_object_uid'])) {
                    $uid = (string) $history['vf_object_uid'];
                }
            }
        }

        return trim($uid);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|\WP_Error
     */
    private static function inspect_live_post_match(array $payload)
    {
        $post_type = sanitize_key((string) ($payload['post_type'] ?? ''));
        $slug = sanitize_title((string) ($payload['post_name'] ?? ''));
        $uid = self::extract_entity_uid($payload);

        $sources = [];
        if ($uid !== '') {
            $sources[] = ['source' => 'uid', 'ids' => self::find_post_ids_by_uid($uid, $post_type)];
        }
        if (($uid === '' || self::is_uid_fallback_matching_allowed()) && ! empty($payload['ID'])) {
            $sources[] = ['source' => 'payload_id', 'ids' => self::find_post_ids_by_payload_id((int) $payload['ID'], $post_type)];
        }
        if ($slug !== '' && $post_type !== '') {
            $sources[] = ['source' => 'slug', 'ids' => self::find_post_ids_by_slug($slug, $post_type)];
        }

        $all_ids = [];
        $best_source = '';
        foreach ($sources as $source) {
            $ids = isset($source['ids']) && \is_array($source['ids']) ? array_values(array_unique(array_map('intval', $source['ids']))) : [];
            if (empty($ids)) {
                continue;
            }

            if (count($ids) > 1) {
                return new \WP_Error(
                    'dbvc_entity_editor_ambiguous_match',
                    __('Multiple matching entities were found; refine identifiers before importing.', 'dbvc'),
                    ['status' => 409, 'candidates' => $ids]
                );
            }

            if ($best_source === '') {
                $best_source = (string) ($source['source'] ?? '');
            }

            $all_ids = array_values(array_unique(array_merge($all_ids, $ids)));
        }

        if (empty($all_ids)) {
            return ['status' => 'none'];
        }

        if (count($all_ids) > 1) {
            return new \WP_Error(
                'dbvc_entity_editor_ambiguous_match',
                __('Matched more than one local entity; sync-file import was blocked.', 'dbvc'),
                ['status' => 409, 'candidates' => $all_ids]
            );
        }

        return self::format_post_entity((int) $all_ids[0], $post_type, $best_source ?: 'unknown');
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|\WP_Error
     */
    private static function inspect_live_term_match(array $payload)
    {
        $taxonomy = sanitize_key((string) ($payload['taxonomy'] ?? ''));
        $slug = sanitize_title((string) ($payload['slug'] ?? ''));
        $uid = self::extract_entity_uid($payload);

        $sources = [];
        if ($uid !== '') {
            $sources[] = ['source' => 'uid', 'ids' => self::find_term_ids_by_uid($uid, $taxonomy)];
        }
        if ($slug !== '' && $taxonomy !== '') {
            $sources[] = ['source' => 'slug', 'ids' => self::find_term_ids_by_slug($slug, $taxonomy)];
        }

        $all_ids = [];
        $best_source = '';
        foreach ($sources as $source) {
            $ids = isset($source['ids']) && \is_array($source['ids']) ? array_values(array_unique(array_map('intval', $source['ids']))) : [];
            if (empty($ids)) {
                continue;
            }

            if (count($ids) > 1) {
                return new \WP_Error(
                    'dbvc_entity_editor_ambiguous_match',
                    __('Multiple matching terms were found; refine identifiers before importing.', 'dbvc'),
                    ['status' => 409, 'candidates' => $ids]
                );
            }

            if ($best_source === '') {
                $best_source = (string) ($source['source'] ?? '');
            }

            $all_ids = array_values(array_unique(array_merge($all_ids, $ids)));
        }

        if (empty($all_ids)) {
            return ['status' => 'none'];
        }

        if (count($all_ids) > 1) {
            return new \WP_Error(
                'dbvc_entity_editor_ambiguous_match',
                __('Matched more than one local term; sync-file import was blocked.', 'dbvc'),
                ['status' => 409, 'candidates' => $all_ids]
            );
        }

        return self::format_term_entity((int) $all_ids[0], $taxonomy, $best_source ?: 'unknown');
    }

    /**
     * @param int    $entity_id
     * @param string $post_type
     * @param string $match_source
     * @return array<string,mixed>
     */
    private static function format_post_entity($entity_id, $post_type, $match_source)
    {
        $entity_id = (int) $entity_id;
        $post = $entity_id > 0 ? get_post($entity_id) : null;
        if (! ($post instanceof \WP_Post)) {
            return [
                'status'       => 'none',
                'id'           => 0,
                'kind'         => 'post',
                'subtype'      => (string) $post_type,
                'label'        => '',
                'edit_url'     => '',
                'match_source' => (string) $match_source,
            ];
        }

        return [
            'status'       => 'matched',
            'id'           => (int) $post->ID,
            'kind'         => 'post',
            'subtype'      => (string) $post_type,
            'label'        => (string) $post->post_title,
            'edit_url'     => (string) get_edit_post_link($post->ID, ''),
            'match_source' => (string) $match_source,
        ];
    }

    /**
     * @param int    $entity_id
     * @param string $taxonomy
     * @param string $match_source
     * @return array<string,mixed>
     */
    private static function format_term_entity($entity_id, $taxonomy, $match_source)
    {
        $entity_id = (int) $entity_id;
        $term = $entity_id > 0 ? get_term($entity_id, $taxonomy) : null;
        if (! $term || \is_wp_error($term)) {
            return [
                'status'       => 'none',
                'id'           => 0,
                'kind'         => 'term',
                'subtype'      => (string) $taxonomy,
                'label'        => '',
                'edit_url'     => '',
                'match_source' => (string) $match_source,
            ];
        }

        return [
            'status'       => 'matched',
            'id'           => (int) $term->term_id,
            'kind'         => 'term',
            'subtype'      => (string) $taxonomy,
            'label'        => (string) $term->name,
            'edit_url'     => \function_exists('get_edit_term_link') ? (string) get_edit_term_link($term->term_id, $taxonomy) : '',
            'match_source' => (string) $match_source,
        ];
    }

    /**
     * @param string $uid
     * @param string $post_type
     * @return array<int,int>
     */
    private static function find_post_ids_by_uid($uid, $post_type)
    {
        $ids = [];
        $uid = trim((string) $uid);
        $post_type = sanitize_key((string) $post_type);
        if ($uid === '') {
            return $ids;
        }

        if (\class_exists('DBVC_Database')) {
            $record = \DBVC_Database::get_entity_by_uid($uid);
            if (\is_object($record) && ! empty($record->object_id)) {
                $candidate = get_post((int) $record->object_id);
                if (
                    $candidate instanceof \WP_Post &&
                    ($post_type === '' || $candidate->post_type === $post_type)
                ) {
                    $ids[] = (int) $candidate->ID;
                }
            }
        }

        $query = get_posts([
            'post_type'        => $post_type ? [$post_type] : 'any',
            'post_status'      => 'any',
            'meta_key'         => 'vf_object_uid',
            'meta_value'       => $uid,
            'posts_per_page'   => 20,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]);

        if (\is_array($query)) {
            foreach ($query as $post_id) {
                $ids[] = (int) $post_id;
            }
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * @param string $slug
     * @param string $post_type
     * @return array<int,int>
     */
    private static function find_post_ids_by_slug($slug, $post_type)
    {
        $slug = sanitize_title((string) $slug);
        $post_type = sanitize_key((string) $post_type);
        if ($slug === '' || $post_type === '') {
            return [];
        }

        $ids = get_posts([
            'name'             => $slug,
            'post_type'        => [$post_type],
            'post_status'      => 'any',
            'posts_per_page'   => 20,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]);

        return \is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
    }

    /**
     * @param int    $payload_id
     * @param string $post_type
     * @return array<int,int>
     */
    private static function find_post_ids_by_payload_id($payload_id, $post_type)
    {
        $payload_id = absint($payload_id);
        $post_type = sanitize_key((string) $post_type);
        if ($payload_id <= 0 || $post_type === '') {
            return [];
        }

        $post = get_post($payload_id);
        if (! ($post instanceof \WP_Post) || $post->post_type !== $post_type) {
            return [];
        }

        return [(int) $post->ID];
    }

    /**
     * @return bool
     */
    private static function is_uid_fallback_matching_allowed()
    {
        if (\class_exists('DBVC_Sync_Posts') && \method_exists('DBVC_Sync_Posts', 'is_uid_fallback_matching_allowed')) {
            return (bool) \DBVC_Sync_Posts::is_uid_fallback_matching_allowed();
        }

        return get_option('dbvc_allow_uid_fallback_matching', '0') === '1';
    }

    /**
     * @param string $uid
     * @param string $taxonomy
     * @return array<int,int>
     */
    private static function find_term_ids_by_uid($uid, $taxonomy)
    {
        $ids = [];
        $uid = trim((string) $uid);
        $taxonomy = sanitize_key((string) $taxonomy);
        if ($uid === '' || $taxonomy === '') {
            return $ids;
        }

        if (\class_exists('DBVC_Database')) {
            $record = \DBVC_Database::get_entity_by_uid($uid);
            if (
                \is_object($record) &&
                ! empty($record->object_id) &&
                isset($record->object_type) &&
                (string) $record->object_type === 'term:' . $taxonomy
            ) {
                $term = get_term((int) $record->object_id, $taxonomy);
                if ($term && ! \is_wp_error($term)) {
                    $ids[] = (int) $term->term_id;
                }
            }
        }

        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => 20,
            'meta_query' => [
                [
                    'key'   => 'vf_object_uid',
                    'value' => $uid,
                ],
            ],
            'fields'     => 'ids',
        ]);

        if (\is_array($terms)) {
            foreach ($terms as $term_id) {
                $ids[] = (int) $term_id;
            }
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * @param string $slug
     * @param string $taxonomy
     * @return array<int,int>
     */
    private static function find_term_ids_by_slug($slug, $taxonomy)
    {
        $slug = sanitize_title((string) $slug);
        $taxonomy = sanitize_key((string) $taxonomy);
        if ($slug === '' || $taxonomy === '') {
            return [];
        }

        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'slug'       => $slug,
            'number'     => 20,
            'fields'     => 'ids',
        ]);

        return \is_array($terms) ? array_values(array_unique(array_map('intval', $terms))) : [];
    }

    /**
     * @param string $post_type
     * @return bool
     */
    private static function can_create_post($post_type)
    {
        return self::build_post_creation_blocker($post_type) === null;
    }

    /**
     * @param string $post_type
     * @return array{code:string,message:string}|null
     */
    private static function build_post_creation_blocker($post_type)
    {
        $post_type = sanitize_key((string) $post_type);
        if (get_option('dbvc_allow_new_posts', '0') !== '1') {
            return self::reason('post_creation_disabled', __('DBVC is currently configured to block creation of new posts from imports.', 'dbvc'));
        }

        $whitelist = self::normalize_option_list(get_option('dbvc_new_post_types_whitelist', []));
        if (! empty($whitelist) && ! in_array($post_type, $whitelist, true)) {
            return self::reason('post_type_whitelist_blocked', sprintf(__('DBVC post creation is restricted and "%s" is not in the new-post whitelist.', 'dbvc'), $post_type));
        }

        return null;
    }

    /**
     * @param string $post_type
     * @return string
     */
    private static function build_post_creation_message($post_type)
    {
        $post_type = sanitize_key((string) $post_type);
        $whitelist = self::normalize_option_list(get_option('dbvc_new_post_types_whitelist', []));
        if (! empty($whitelist) && ! in_array($post_type, $whitelist, true)) {
            return sprintf(__('DBVC post creation is restricted and "%s" is not in the new-post whitelist.', 'dbvc'), $post_type);
        }

        return __('DBVC is currently configured to block creation of new posts from imports.', 'dbvc');
    }

    /**
     * @param array<string,mixed> $payload
     * @param string              $post_type
     * @return string
     */
    private static function build_canonical_post_relative_path(array $payload, $post_type)
    {
        if (! \class_exists('DBVC_Import_Router') || $post_type === '') {
            return '';
        }

        return $post_type . '/' . \DBVC_Import_Router::determine_post_filename($payload);
    }

    /**
     * @param array<string,mixed> $payload
     * @param string              $taxonomy
     * @return string
     */
    private static function build_canonical_term_relative_path(array $payload, $taxonomy)
    {
        if (! \class_exists('DBVC_Import_Router') || $taxonomy === '') {
            return '';
        }

        return 'taxonomy/' . $taxonomy . '/' . \DBVC_Import_Router::determine_term_filename($payload, $taxonomy);
    }

    /**
     * @param string $relative_path
     * @return string|\WP_Error
     */
    private static function resolve_absolute_path($relative_path)
    {
        if (! \class_exists('DBVC_Entity_Editor_Indexer')) {
            return new \WP_Error('dbvc_entity_editor_sync_import_unavailable', __('Entity Editor indexer is unavailable.', 'dbvc'), ['status' => 500]);
        }

        return \DBVC_Entity_Editor_Indexer::resolve_entity_file_path_for_import($relative_path);
    }

    /**
     * @param string $absolute_path
     * @return array<string,mixed>|null
     */
    private static function read_json_file($absolute_path)
    {
        $raw = is_string($absolute_path) && $absolute_path !== '' && is_file($absolute_path)
            ? file_get_contents($absolute_path)
            : false;
        if (! \is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @param string              $absolute_path
     * @param array<string,mixed> $payload
     * @return bool
     */
    private static function write_json_file($absolute_path, array $payload)
    {
        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return false !== file_put_contents($absolute_path, $json . "\n");
    }
}
