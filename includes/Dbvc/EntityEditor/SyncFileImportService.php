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
    public static function commit($paths, $mode = self::MODE_CREATE_ONLY, $user_id = 0)
    {
        $mode = self::normalize_mode($mode);
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
            'imported' => false,
            'status'   => $status,
            'blocking' => [
                self::reason((string) $error->get_error_code(), (string) $error->get_error_message()),
            ],
        ]);
    }

    /**
     * @param string $mode
     * @return string
     */
    private static function normalize_mode($mode)
    {
        return sanitize_key((string) $mode) === self::MODE_CREATE_ONLY ? self::MODE_CREATE_ONLY : self::MODE_CREATE_ONLY;
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
        $base = [
            'relative_path'    => $relative_path,
            'entity_kind'      => '',
            'subtype'          => '',
            'title'            => '',
            'slug'             => '',
            'uid'              => '',
            'detected_action'  => 'blocked',
            'match'            => ['status' => 'none'],
            'warnings'         => [],
            'blocking'         => [],
            'available_actions'=> [
                self::MODE_CREATE_ONLY => false,
            ],
        ];

        if (! \class_exists('DBVC_Entity_Editor_Indexer')) {
            $base['blocking'][] = self::reason('indexer_unavailable', __('Entity Editor indexer is unavailable.', 'dbvc'));
            return $base;
        }

        $loaded = \DBVC_Entity_Editor_Indexer::load_entity_file_for_download($relative_path);
        if (\is_wp_error($loaded)) {
            $base['blocking'][] = self::reason((string) $loaded->get_error_code(), (string) $loaded->get_error_message());
            return $base;
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
            return $base;
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
                }
            }
        }

        if ($kind === 'post' && empty($base['blocking']) && ! self::can_create_post($post_type)) {
            $base['blocking'][] = self::reason('post_creation_disabled', self::build_post_creation_message($post_type));
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

        return $base;
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
            'blocked'   => 0,
            'skipped'   => 0,
        ];

        foreach ($items as $item) {
            $blocking = isset($item['blocking']) && \is_array($item['blocking']) ? $item['blocking'] : [];
            if (empty($blocking) && isset($item['detected_action']) && $item['detected_action'] === 'create') {
                $summary['creatable']++;
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
        ];

        if ($canonical_relative_path === '' || $canonical_relative_path === $source_relative_path) {
            return $state;
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
                return $state;
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

        return $state;
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
        if (get_option('dbvc_allow_new_posts') !== '1') {
            return false;
        }

        $whitelist = (array) get_option('dbvc_new_post_types_whitelist', []);
        if (! empty($whitelist) && ! in_array($post_type, $whitelist, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $post_type
     * @return string
     */
    private static function build_post_creation_message($post_type)
    {
        $whitelist = (array) get_option('dbvc_new_post_types_whitelist', []);
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
