<?php

namespace Dbvc\Transfer;

if (! defined('WPINC')) {
    die;
}

/**
 * Build proposal-compatible transfer packets from Entity Editor selections.
 */
final class EntityPacketBuilder
{
    private const WORKSPACE_SUBDIR = 'dbvc-transfer-packets';

    /**
     * Build a ZIP packet for a set of Entity Editor relative paths.
     *
     * @param array $relative_paths
     * @return array|\WP_Error
     */
    public static function build_from_entity_paths(array $relative_paths)
    {
        $paths = self::normalize_relative_paths($relative_paths);
        if (empty($paths)) {
            return new \WP_Error('dbvc_transfer_empty_selection', __('No valid entities were selected for transfer.', 'dbvc'), ['status' => 400]);
        }

        if (! class_exists('ZipArchive')) {
            return new \WP_Error('dbvc_transfer_zip_missing', __('Transfer packet creation requires PHP ZipArchive support.', 'dbvc'), ['status' => 500]);
        }

        $workspace_root = self::get_workspace_root();
        if (is_wp_error($workspace_root)) {
            return $workspace_root;
        }

        $packet_id = self::build_packet_id();
        $staging_path = trailingslashit($workspace_root) . $packet_id;
        $zip_path = trailingslashit($workspace_root) . $packet_id . '.zip';
        $state = self::initialize_state($paths);

        try {
            self::ensure_directory($staging_path);
            $prepared = self::populate_selection_workspace($staging_path, $state);
            if (is_wp_error($prepared)) {
                self::cleanup_workspace($packet_id, $staging_path, $zip_path);
                return $prepared;
            }

            $manifest = self::generate_transfer_manifest($staging_path, $packet_id, $state, [
                'build_media_bundle' => true,
            ]);
            if (is_wp_error($manifest)) {
                self::cleanup_workspace($packet_id, $staging_path, $zip_path);
                return $manifest;
            }

            self::build_zip_archive($staging_path, $zip_path);
            self::cleanup_bundle_directory($packet_id);

            return [
                'packet_id'    => $packet_id,
                'zip_path'     => $zip_path,
                'filename'     => self::build_download_filename($packet_id),
                'staging_path' => $staging_path,
            ];
        } catch (\Throwable $e) {
            self::cleanup_workspace($packet_id, $staging_path, $zip_path);

            return new \WP_Error(
                'dbvc_transfer_build_failed',
                sprintf(__('Transfer packet build failed: %s', 'dbvc'), $e->getMessage()),
                ['status' => 500]
            );
        }
    }

    /**
     * Remove staged packet build artifacts.
     *
     * @param array $result
     * @return void
     */
    public static function cleanup_build(array $result): void
    {
        $packet_id = isset($result['packet_id']) ? sanitize_file_name((string) $result['packet_id']) : '';
        $zip_path = isset($result['zip_path']) ? (string) $result['zip_path'] : '';
        $staging_path = isset($result['staging_path']) ? (string) $result['staging_path'] : '';

        self::cleanup_workspace($packet_id, $staging_path, $zip_path);
    }

    /**
     * Analyze a selection and return preview data without building the final ZIP.
     *
     * @param array $relative_paths
     * @return array|\WP_Error
     */
    public static function preview_from_entity_paths(array $relative_paths)
    {
        $paths = self::normalize_relative_paths($relative_paths);
        if (empty($paths)) {
            return new \WP_Error('dbvc_transfer_empty_selection', __('No valid entities were selected for transfer.', 'dbvc'), ['status' => 400]);
        }

        $workspace_root = self::get_workspace_root();
        if (is_wp_error($workspace_root)) {
            return $workspace_root;
        }

        $packet_id = self::build_packet_id() . '-preview';
        $staging_path = trailingslashit($workspace_root) . $packet_id;
        $state = self::initialize_state($paths);

        try {
            self::ensure_directory($staging_path);
            $prepared = self::populate_selection_workspace($staging_path, $state);
            if (is_wp_error($prepared)) {
                self::cleanup_workspace($packet_id, $staging_path);
                return $prepared;
            }

            $manifest = self::generate_transfer_manifest($staging_path, $packet_id, $state, [
                'build_media_bundle' => false,
            ]);
            if (is_wp_error($manifest)) {
                self::cleanup_workspace($packet_id, $staging_path);
                return $manifest;
            }

            $preview = self::build_preview_payload($manifest, $state, $packet_id);
            self::cleanup_workspace($packet_id, $staging_path);

            return $preview;
        } catch (\Throwable $e) {
            self::cleanup_workspace($packet_id, $staging_path);

            return new \WP_Error(
                'dbvc_transfer_preview_failed',
                sprintf(__('Transfer preview failed: %s', 'dbvc'), $e->getMessage()),
                ['status' => 500]
            );
        }
    }

    /**
     * Initialize packet build state.
     *
     * @param array $paths
     * @return array
     */
    private static function initialize_state(array $paths): array
    {
        return [
            'selected_paths'     => $paths,
            'index_by_path'      => [],
            'term_lookup'        => [
                'uid'           => [],
                'taxonomy_slug' => [],
            ],
            'staged_paths'       => [],
            'staged_entity_keys' => [],
            'queued_term_keys'   => [],
            'pending_terms'      => [],
            'payloads_by_path'   => [],
            'notes'              => [],
            'warnings'           => [
                'unsupported_post_references' => [],
            ],
            'stats'              => [
                'requested'            => count($paths),
                'selected_posts'       => 0,
                'selected_terms'       => 0,
                'dependency_terms'     => 0,
                'live_exports'         => 0,
                'fallback_files'       => 0,
                'duplicates_skipped'   => 0,
                'missing_dependencies' => 0,
            ],
        ];
    }

    /**
     * Normalize relative paths from the bulk request payload.
     *
     * @param array $relative_paths
     * @return array
     */
    private static function normalize_relative_paths(array $relative_paths): array
    {
        $normalized = [];
        foreach ($relative_paths as $relative_path) {
            if (! is_string($relative_path)) {
                continue;
            }

            $value = str_replace('\\', '/', ltrim(trim($relative_path), '/'));
            if ($value === '') {
                continue;
            }

            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    /**
     * Build a path => index item lookup.
     *
     * @param array $items
     * @return array
     */
    private static function build_index_by_path(array $items): array
    {
        $lookup = [];
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['relative_path']) || ! is_string($item['relative_path'])) {
                continue;
            }

            $relative_path = str_replace('\\', '/', ltrim($item['relative_path'], '/'));
            if ($relative_path === '') {
                continue;
            }

            $lookup[$relative_path] = $item;
        }

        return $lookup;
    }

    /**
     * Build term lookup indexes for dependency expansion.
     *
     * @param array $items
     * @return array
     */
    private static function build_term_lookup(array $items): array
    {
        $lookup = [
            'uid'           => [],
            'taxonomy_slug' => [],
        ];

        foreach ($items as $item) {
            if (! is_array($item) || ($item['entity_kind'] ?? '') !== 'term') {
                continue;
            }

            $uid = isset($item['uid']) ? sanitize_text_field((string) $item['uid']) : '';
            if ($uid !== '' && ! isset($lookup['uid'][$uid])) {
                $lookup['uid'][$uid] = $item;
            }

            $taxonomy = isset($item['subtype']) ? sanitize_key((string) $item['subtype']) : '';
            $slug     = isset($item['slug']) ? sanitize_title((string) $item['slug']) : '';
            if ($taxonomy !== '' && $slug !== '') {
                $key = $taxonomy . '::' . $slug;
                if (! isset($lookup['taxonomy_slug'][$key])) {
                    $lookup['taxonomy_slug'][$key] = $item;
                }
            }
        }

        return $lookup;
    }

    /**
     * Populate the staging workspace from the selected paths and queued dependencies.
     *
     * @param string $staging_path
     * @param array  $state
     * @return true|\WP_Error
     */
    private static function populate_selection_workspace(string $staging_path, array &$state)
    {
        $index_payload = \DBVC_Entity_Editor_Indexer::get_index(false);
        $index_items   = isset($index_payload['items']) && is_array($index_payload['items']) ? $index_payload['items'] : [];
        $state['index_by_path'] = self::build_index_by_path($index_items);
        $state['term_lookup']   = self::build_term_lookup($index_items);

        foreach ($state['selected_paths'] as $relative_path) {
            $result = self::stage_requested_entity($relative_path, $staging_path, $state);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        while (! empty($state['pending_terms'])) {
            $dependency = array_shift($state['pending_terms']);
            $result = self::stage_term_dependency($dependency, $staging_path, $state);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        if (empty($state['staged_paths'])) {
            return new \WP_Error('dbvc_transfer_no_payloads', __('No valid entity payloads were available for packet creation.', 'dbvc'), ['status' => 404]);
        }

        self::analyze_staged_payload_warnings($state);

        return true;
    }

    /**
     * Generate the transfer manifest for a prepared staging workspace.
     *
     * @param string $staging_path
     * @param string $packet_id
     * @param array  $state
     * @param array  $options
     * @return array|\WP_Error
     */
    private static function generate_transfer_manifest(string $staging_path, string $packet_id, array &$state, array $options = [])
    {
        $build_media_bundle = array_key_exists('build_media_bundle', $options)
            ? (bool) $options['build_media_bundle']
            : true;

        \DBVC_Backup_Manager::generate_manifest($staging_path, [
            'build_media_bundle' => $build_media_bundle,
        ]);

        $manifest = \DBVC_Backup_Manager::read_manifest($staging_path);
        if (! is_array($manifest)) {
            return new \WP_Error('dbvc_transfer_manifest_failed', __('Transfer packet manifest generation failed.', 'dbvc'), ['status' => 500]);
        }

        $manifest = self::extend_manifest($manifest, $packet_id, $state);
        self::write_manifest($staging_path, $manifest);

        return $manifest;
    }

    /**
     * Build the source-side preview payload for the Entity Editor modal.
     *
     * @param array  $manifest
     * @param array  $state
     * @param string $packet_id
     * @return array
     */
    private static function build_preview_payload(array $manifest, array $state, string $packet_id): array
    {
        $media_items = isset($manifest['totals']['media_items']) ? (int) $manifest['totals']['media_items'] : 0;
        $bundle_enabled = class_exists('DBVC_Media_Sync') && \DBVC_Media_Sync::is_bundle_enabled();

        return [
            'packet_id'     => $packet_id,
            'origin'        => isset($manifest['origin']) && is_array($manifest['origin']) ? $manifest['origin'] : null,
            'selection'     => isset($manifest['selection']) && is_array($manifest['selection']) ? $manifest['selection'] : null,
            'requirements'  => isset($manifest['requirements']) && is_array($manifest['requirements']) ? $manifest['requirements'] : null,
            'warnings'      => isset($manifest['warnings']) && is_array($manifest['warnings']) ? $manifest['warnings'] : null,
            'totals'        => isset($manifest['totals']) && is_array($manifest['totals']) ? $manifest['totals'] : [],
            'media'         => [
                'items'               => $media_items,
                'bundle_enabled'      => $bundle_enabled,
                'will_include_bundle' => $bundle_enabled && $media_items > 0,
            ],
            'stats'         => $state['stats'],
        ];
    }

    /**
     * Stage a user-selected entity.
     *
     * @param string $relative_path
     * @param string $staging_path
     * @param array  $state
     * @return array|\WP_Error
     */
    private static function stage_requested_entity(string $relative_path, string $staging_path, array &$state)
    {
        if (isset($state['staged_paths'][$relative_path])) {
            return $state['staged_paths'][$relative_path];
        }

        $item = isset($state['index_by_path'][$relative_path]) && is_array($state['index_by_path'][$relative_path])
            ? $state['index_by_path'][$relative_path]
            : null;

        if ($item && ! empty($item['matched_wp']['id'])) {
            $result = self::stage_live_index_item($item, $staging_path, false, $state);
            if (! is_wp_error($result)) {
                return $result;
            }

            self::append_note(
                $state,
                sprintf(
                    __('Fell back to sync JSON for %s because live export failed: %s', 'dbvc'),
                    $relative_path,
                    $result->get_error_message()
                )
            );
        }

        return self::stage_existing_json($relative_path, $staging_path, false, $state);
    }

    /**
     * Stage a dependency term if it can be resolved.
     *
     * @param array  $dependency
     * @param string $staging_path
     * @param array  $state
     * @return array|true|\WP_Error
     */
    private static function stage_term_dependency(array $dependency, string $staging_path, array &$state)
    {
        $resolved = self::resolve_dependency_term($dependency, $state);
        if (! is_array($resolved)) {
            $state['stats']['missing_dependencies']++;
            $taxonomy = isset($dependency['taxonomy']) ? sanitize_key((string) $dependency['taxonomy']) : '';
            $slug = isset($dependency['slug']) ? sanitize_title((string) $dependency['slug']) : '';
            $uid = isset($dependency['uid']) ? sanitize_text_field((string) $dependency['uid']) : '';
            self::append_note(
                $state,
                sprintf(
                    __('A dependent term could not be resolved on the source site (%s).', 'dbvc'),
                    $uid !== '' ? $uid : ($taxonomy !== '' && $slug !== '' ? $taxonomy . '/' . $slug : __('unknown dependency', 'dbvc'))
                )
            );
            return true;
        }

        if ($resolved['source'] === 'live') {
            return self::stage_live_term($resolved['term_id'], $resolved['taxonomy'], $staging_path, true, $state);
        }

        return self::stage_existing_json($resolved['relative_path'], $staging_path, true, $state);
    }

    /**
     * Resolve a dependency term against live WordPress or sync JSON.
     *
     * @param array $dependency
     * @param array $state
     * @return array|null
     */
    private static function resolve_dependency_term(array $dependency, array $state): ?array
    {
        $taxonomy = isset($dependency['taxonomy']) ? sanitize_key((string) $dependency['taxonomy']) : '';
        $uid      = isset($dependency['uid']) ? sanitize_text_field((string) $dependency['uid']) : '';
        $slug     = isset($dependency['slug']) ? sanitize_title((string) $dependency['slug']) : '';
        $term_id  = isset($dependency['term_id']) ? absint($dependency['term_id']) : 0;

        if ($uid !== '') {
            $live = self::find_live_term_by_uid($uid);
            if ($live) {
                return [
                    'source'   => 'live',
                    'term_id'  => $live['term_id'],
                    'taxonomy' => $live['taxonomy'],
                ];
            }

            if (isset($state['term_lookup']['uid'][$uid])) {
                return [
                    'source'        => 'sync',
                    'relative_path' => (string) $state['term_lookup']['uid'][$uid]['relative_path'],
                ];
            }
        }

        if ($taxonomy !== '' && $slug !== '') {
            $live_term = get_term_by('slug', $slug, $taxonomy);
            if ($live_term && ! is_wp_error($live_term)) {
                return [
                    'source'   => 'live',
                    'term_id'  => (int) $live_term->term_id,
                    'taxonomy' => $taxonomy,
                ];
            }

            $key = $taxonomy . '::' . $slug;
            if (isset($state['term_lookup']['taxonomy_slug'][$key])) {
                return [
                    'source'        => 'sync',
                    'relative_path' => (string) $state['term_lookup']['taxonomy_slug'][$key]['relative_path'],
                ];
            }
        }

        if ($taxonomy !== '' && $term_id > 0) {
            $term = get_term($term_id, $taxonomy);
            if ($term && ! is_wp_error($term)) {
                return [
                    'source'   => 'live',
                    'term_id'  => (int) $term->term_id,
                    'taxonomy' => $taxonomy,
                ];
            }
        }

        return null;
    }

    /**
     * Resolve a live term by DBVC entity UID.
     *
     * @param string $uid
     * @return array|null
     */
    private static function find_live_term_by_uid(string $uid): ?array
    {
        if (! class_exists('DBVC_Database') || ! method_exists('DBVC_Database', 'get_entity_by_uid')) {
            return null;
        }

        $row = \DBVC_Database::get_entity_by_uid($uid);
        if (! is_array($row) || ($row['entity_type'] ?? '') !== 'term') {
            return null;
        }

        $term_id = isset($row['entity_id']) ? absint($row['entity_id']) : 0;
        if (! $term_id) {
            return null;
        }

        $taxonomy = '';
        $object_type = isset($row['object_type']) ? (string) $row['object_type'] : '';
        if (strpos($object_type, 'term:') === 0) {
            $taxonomy = sanitize_key(substr($object_type, 5));
        }

        if ($taxonomy === '') {
            $term = get_term($term_id);
            if ($term && ! is_wp_error($term)) {
                $taxonomy = sanitize_key($term->taxonomy);
            }
        }

        if ($taxonomy === '') {
            return null;
        }

        return [
            'term_id'  => $term_id,
            'taxonomy' => $taxonomy,
        ];
    }

    /**
     * Stage a matched live index item.
     *
     * @param array  $item
     * @param string $staging_path
     * @param bool   $dependency
     * @param array  $state
     * @return array|\WP_Error
     */
    private static function stage_live_index_item(array $item, string $staging_path, bool $dependency, array &$state)
    {
        $entity_kind = isset($item['entity_kind']) ? (string) $item['entity_kind'] : '';
        $matched_wp = isset($item['matched_wp']) && is_array($item['matched_wp']) ? $item['matched_wp'] : [];
        $matched_id = isset($matched_wp['id']) ? absint($matched_wp['id']) : 0;

        if (! $matched_id) {
            return new \WP_Error('dbvc_transfer_missing_live_match', __('Entity Editor item is missing a live match.', 'dbvc'), ['status' => 400]);
        }

        if ($entity_kind === 'post') {
            return self::stage_live_post($matched_id, $staging_path, $dependency, $state);
        }

        if ($entity_kind === 'term') {
            $taxonomy = isset($item['subtype']) ? sanitize_key((string) $item['subtype']) : '';
            return self::stage_live_term($matched_id, $taxonomy, $staging_path, $dependency, $state);
        }

        return new \WP_Error('dbvc_transfer_unsupported_entity', __('Only post and term entities can be transferred.', 'dbvc'), ['status' => 400]);
    }

    /**
     * Stage a live post export.
     *
     * @param int    $post_id
     * @param string $staging_path
     * @param bool   $dependency
     * @param array  $state
     * @return array|\WP_Error
     */
    private static function stage_live_post(int $post_id, string $staging_path, bool $dependency, array &$state)
    {
        $result = \DBVC_Sync_Posts::stage_post_export($post_id, $staging_path);
        if (is_wp_error($result)) {
            return $result;
        }

        return self::register_staged_payload($result, 'live', $dependency, $state);
    }

    /**
     * Stage a live term export.
     *
     * @param int    $term_id
     * @param string $taxonomy
     * @param string $staging_path
     * @param bool   $dependency
     * @param array  $state
     * @return array|\WP_Error
     */
    private static function stage_live_term(int $term_id, string $taxonomy, string $staging_path, bool $dependency, array &$state)
    {
        if ($taxonomy === '') {
            return new \WP_Error('dbvc_transfer_missing_taxonomy', __('The selected term is missing its taxonomy context.', 'dbvc'), ['status' => 400]);
        }

        $result = \DBVC_Sync_Taxonomies::stage_term_export($term_id, $taxonomy, $staging_path);
        if (is_wp_error($result)) {
            return $result;
        }

        return self::register_staged_payload($result, 'live', $dependency, $state);
    }

    /**
     * Stage an existing sync JSON file into the packet workspace.
     *
     * @param string $relative_path
     * @param string $staging_path
     * @param bool   $dependency
     * @param array  $state
     * @return array|\WP_Error
     */
    private static function stage_existing_json(string $relative_path, string $staging_path, bool $dependency, array &$state)
    {
        $file = \DBVC_Entity_Editor_Indexer::load_entity_file_for_download($relative_path);
        if (is_wp_error($file)) {
            return $file;
        }

        $target_path = wp_normalize_path(trailingslashit($staging_path) . ltrim($relative_path, '/'));
        $target_dir  = dirname($target_path);
        self::ensure_directory($target_dir);

        if (false === file_put_contents($target_path, (string) $file['content'])) {
            return new \WP_Error('dbvc_transfer_stage_copy_failed', __('Failed to stage a selected entity JSON file.', 'dbvc'), ['status' => 500]);
        }

        return self::register_staged_payload([
            'relative_path' => (string) $file['relative_path'],
            'file_path'     => $target_path,
            'json_content'  => (string) $file['content'],
            'decoded'       => isset($file['decoded']) && is_array($file['decoded']) ? $file['decoded'] : [],
        ], 'sync', $dependency, $state);
    }

    /**
     * Register a newly staged payload, dedupe it, and queue any dependencies.
     *
     * @param array  $payload
     * @param string $source
     * @param bool   $dependency
     * @param array  $state
     * @return array|\WP_Error
     */
    private static function register_staged_payload(array $payload, string $source, bool $dependency, array &$state)
    {
        $relative_path = isset($payload['relative_path']) ? str_replace('\\', '/', ltrim((string) $payload['relative_path'], '/')) : '';
        $decoded       = isset($payload['decoded']) && is_array($payload['decoded']) ? $payload['decoded'] : [];
        $file_path     = isset($payload['file_path']) ? (string) $payload['file_path'] : '';

        if ($relative_path === '' || empty($decoded)) {
            self::delete_file($file_path);
            return new \WP_Error('dbvc_transfer_invalid_payload', __('Transfer packet staging produced an invalid entity payload.', 'dbvc'), ['status' => 500]);
        }

        if (isset($state['staged_paths'][$relative_path])) {
            if ($file_path !== '' && $file_path !== ($state['staged_paths'][$relative_path]['file_path'] ?? '')) {
                self::delete_file($file_path);
            }
            return $state['staged_paths'][$relative_path];
        }

        $entity_key = self::build_entity_key($decoded, $relative_path);
        if ($entity_key !== '' && isset($state['staged_entity_keys'][$entity_key])) {
            $state['stats']['duplicates_skipped']++;
            self::delete_file($file_path);
            self::append_note(
                $state,
                sprintf(
                    __('Skipped a duplicate packet payload for %s.', 'dbvc'),
                    $relative_path
                )
            );
            return $state['staged_paths'][$state['staged_entity_keys'][$entity_key]];
        }

        $entity_kind = self::detect_entity_kind($decoded);
        if (! in_array($entity_kind, ['post', 'term'], true)) {
            self::delete_file($file_path);
            return new \WP_Error('dbvc_transfer_unsupported_payload', __('Transfer packets currently support only post and term payloads.', 'dbvc'), ['status' => 400]);
        }

        if ($source === 'live') {
            $state['stats']['live_exports']++;
        } else {
            $state['stats']['fallback_files']++;
        }

        if ($entity_kind === 'post') {
            if (! $dependency) {
                $state['stats']['selected_posts']++;
            }
            self::queue_post_term_dependencies($decoded, $state);
        } else {
            if ($dependency) {
                $state['stats']['dependency_terms']++;
            } else {
                $state['stats']['selected_terms']++;
            }
            self::queue_term_parent_dependency($decoded, $state);
        }

        $registered = [
            'relative_path' => $relative_path,
            'file_path'     => $file_path,
            'source'        => $source,
            'entity_kind'   => $entity_kind,
            'entity_key'    => $entity_key,
            'dependency'    => $dependency,
        ];

        $state['staged_paths'][$relative_path] = $registered;
        $state['payloads_by_path'][$relative_path] = $decoded;
        if ($entity_key !== '') {
            $state['staged_entity_keys'][$entity_key] = $relative_path;
        }

        return $registered;
    }

    /**
     * Queue term dependencies referenced by a post payload.
     *
     * @param array $decoded
     * @param array $state
     * @return void
     */
    private static function queue_post_term_dependencies(array $decoded, array &$state): void
    {
        $tax_input = isset($decoded['tax_input']) && is_array($decoded['tax_input']) ? $decoded['tax_input'] : [];
        foreach ($tax_input as $taxonomy => $terms) {
            $taxonomy = sanitize_key((string) $taxonomy);
            if ($taxonomy === '' || ! is_array($terms)) {
                continue;
            }

            foreach ($terms as $term_payload) {
                if (! is_array($term_payload)) {
                    continue;
                }

                $slug = isset($term_payload['slug']) ? sanitize_title((string) $term_payload['slug']) : '';
                if ($slug === '') {
                    continue;
                }

                self::queue_term_dependency([
                    'taxonomy' => $taxonomy,
                    'slug'     => $slug,
                ], $state);
            }
        }
    }

    /**
     * Queue a parent term dependency from a term payload.
     *
     * @param array $decoded
     * @param array $state
     * @return void
     */
    private static function queue_term_parent_dependency(array $decoded, array &$state): void
    {
        $taxonomy = isset($decoded['taxonomy']) ? sanitize_key((string) $decoded['taxonomy']) : '';
        if ($taxonomy === '') {
            return;
        }

        $parent_uid  = isset($decoded['parent_uid']) ? sanitize_text_field((string) $decoded['parent_uid']) : '';
        $parent_slug = isset($decoded['parent_slug']) ? sanitize_title((string) $decoded['parent_slug']) : '';
        $parent_id   = isset($decoded['parent']) ? absint($decoded['parent']) : 0;

        if ($parent_uid === '' && $parent_slug === '' && $parent_id <= 0) {
            return;
        }

        self::queue_term_dependency([
            'taxonomy' => $taxonomy,
            'uid'      => $parent_uid,
            'slug'     => $parent_slug,
            'term_id'  => $parent_id,
        ], $state);
    }

    /**
     * Queue a dependency term once.
     *
     * @param array $dependency
     * @param array $state
     * @return void
     */
    private static function queue_term_dependency(array $dependency, array &$state): void
    {
        $taxonomy = isset($dependency['taxonomy']) ? sanitize_key((string) $dependency['taxonomy']) : '';
        $uid      = isset($dependency['uid']) ? sanitize_text_field((string) $dependency['uid']) : '';
        $slug     = isset($dependency['slug']) ? sanitize_title((string) $dependency['slug']) : '';
        $term_id  = isset($dependency['term_id']) ? absint($dependency['term_id']) : 0;

        $key = '';
        if ($uid !== '') {
            $key = 'uid:' . $uid;
        } elseif ($taxonomy !== '' && $slug !== '') {
            $key = 'taxonomy_slug:' . $taxonomy . ':' . $slug;
        } elseif ($taxonomy !== '' && $term_id > 0) {
            $key = 'taxonomy_id:' . $taxonomy . ':' . $term_id;
        }

        if ($key === '' || isset($state['queued_term_keys'][$key])) {
            return;
        }

        $state['queued_term_keys'][$key] = true;
        $state['pending_terms'][] = [
            'taxonomy' => $taxonomy,
            'uid'      => $uid,
            'slug'     => $slug,
            'term_id'  => $term_id,
        ];
    }

    /**
     * Analyze staged payloads for warnings that should be surfaced before build/import.
     *
     * @param array $state
     * @return void
     */
    private static function analyze_staged_payload_warnings(array &$state): void
    {
        $payloads = isset($state['payloads_by_path']) && is_array($state['payloads_by_path']) ? $state['payloads_by_path'] : [];
        if (empty($payloads)) {
            return;
        }

        $included_post_refs = self::build_included_post_reference_lookup($payloads);
        foreach ($payloads as $relative_path => $decoded) {
            if (! is_array($decoded) || self::detect_entity_kind($decoded) !== 'post') {
                continue;
            }

            $warnings = self::collect_post_reference_warnings_for_payload((string) $relative_path, $decoded, $included_post_refs);
            foreach ($warnings as $warning) {
                $warning_key = sprintf(
                    '%s|%s|%d',
                    $warning['source_path'] ?? '',
                    $warning['meta_key'] ?? '',
                    isset($warning['referenced_post_id']) ? (int) $warning['referenced_post_id'] : 0
                );
                $state['warnings']['unsupported_post_references'][$warning_key] = $warning;
            }
        }

        $unsupported_count = isset($state['warnings']['unsupported_post_references']) && is_array($state['warnings']['unsupported_post_references'])
            ? count($state['warnings']['unsupported_post_references'])
            : 0;

        if ($unsupported_count > 0) {
            self::append_note(
                $state,
                sprintf(
                    _n(
                        'Detected %d likely post-object or relationship reference to a post that is not included in this packet. DBVC will not remap arbitrary post references automatically.',
                        'Detected %d likely post-object or relationship references to posts that are not included in this packet. DBVC will not remap arbitrary post references automatically.',
                        $unsupported_count,
                        'dbvc'
                    ),
                    $unsupported_count
                )
            );
        }
    }

    /**
     * Build a lookup of all post reference keys included in the packet.
     *
     * @param array $payloads
     * @return array<string,bool>
     */
    private static function build_included_post_reference_lookup(array $payloads): array
    {
        $lookup = [];

        foreach ($payloads as $decoded) {
            if (! is_array($decoded) || self::detect_entity_kind($decoded) !== 'post') {
                continue;
            }

            foreach (self::build_post_reference_keys_from_payload($decoded) as $key) {
                $lookup[$key] = true;
            }
        }

        return $lookup;
    }

    /**
     * Collect unsupported post reference warnings for a staged post payload.
     *
     * @param string $relative_path
     * @param array  $decoded
     * @param array  $included_post_refs
     * @return array<int,array<string,mixed>>
     */
    private static function collect_post_reference_warnings_for_payload(string $relative_path, array $decoded, array $included_post_refs): array
    {
        $warnings = [];
        $meta = isset($decoded['meta']) && is_array($decoded['meta']) ? $decoded['meta'] : [];
        if (empty($meta)) {
            return $warnings;
        }

        $source_post_id = isset($decoded['ID']) ? absint($decoded['ID']) : 0;
        $source_post_type = isset($decoded['post_type']) ? sanitize_key((string) $decoded['post_type']) : '';
        $source_post_name = isset($decoded['post_name']) ? sanitize_title((string) $decoded['post_name']) : '';
        $source_uid = isset($decoded['vf_object_uid']) ? sanitize_text_field((string) $decoded['vf_object_uid']) : '';
        $source_title = isset($decoded['post_title']) ? sanitize_text_field((string) $decoded['post_title']) : '';

        foreach ($meta as $meta_key => $meta_values) {
            if (! is_string($meta_key) || $meta_key === '' || strpos($meta_key, '_') === 0) {
                continue;
            }

            $field_context = self::resolve_post_reference_field_context($meta_key, $meta);
            if (! is_array($field_context)) {
                continue;
            }

            $referenced_ids = self::collect_referenced_post_ids($meta_values);
            if (empty($referenced_ids)) {
                continue;
            }

            foreach ($referenced_ids as $referenced_id) {
                if ($referenced_id <= 0 || ($source_post_id > 0 && $referenced_id === $source_post_id)) {
                    continue;
                }

                $reference_context = self::resolve_post_reference_context($referenced_id);
                if (! is_array($reference_context)) {
                    continue;
                }

                if (self::is_included_post_reference($reference_context, $included_post_refs)) {
                    continue;
                }

                $warnings[] = [
                    'source_path'          => $relative_path,
                    'source_post_id'       => $source_post_id,
                    'source_post_type'     => $source_post_type,
                    'source_post_name'     => $source_post_name,
                    'source_post_title'    => $source_title,
                    'source_uid'           => $source_uid,
                    'meta_key'             => $meta_key,
                    'field_label'          => $field_context['field_label'],
                    'field_type'           => $field_context['field_type'],
                    'reference_source'     => $field_context['reference_source'],
                    'referenced_post_id'   => $reference_context['post_id'],
                    'referenced_post_type' => $reference_context['post_type'],
                    'referenced_post_name' => $reference_context['post_name'],
                    'referenced_post_title'=> $reference_context['post_title'],
                    'referenced_uid'       => $reference_context['vf_object_uid'],
                ];
            }
        }

        return $warnings;
    }

    /**
     * Resolve whether a meta key likely stores post-object or relationship references.
     *
     * @param string $meta_key
     * @param array  $meta
     * @return array<string,string>|null
     */
    private static function resolve_post_reference_field_context(string $meta_key, array $meta): ?array
    {
        $hidden_key = '_' . $meta_key;
        $field_key = '';

        if (isset($meta[$hidden_key])) {
            $hidden_values = is_array($meta[$hidden_key]) ? $meta[$hidden_key] : [$meta[$hidden_key]];
            foreach ($hidden_values as $candidate) {
                if (! is_scalar($candidate)) {
                    continue;
                }

                $candidate = trim((string) $candidate);
                if ($candidate !== '' && strpos($candidate, 'field_') === 0) {
                    $field_key = $candidate;
                    break;
                }
            }
        }

        if ($field_key !== '' && function_exists('acf_get_field')) {
            $field = acf_get_field($field_key);
            if (is_array($field)) {
                $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
                if (in_array($field_type, ['relationship', 'post_object', 'page_link'], true)) {
                    $field_label = isset($field['label']) && $field['label'] !== ''
                        ? sanitize_text_field((string) $field['label'])
                        : $meta_key;

                    return [
                        'reference_source' => 'acf',
                        'field_type'       => $field_type,
                        'field_label'      => $field_label,
                    ];
                }
            }
        }

        if (preg_match('/(^|_|-)(related|relationship|post_object|page_link|linked|connected|featured_post|featured_page|selected_posts|selected_pages|parent_post|child_posts)(_|-|$)/i', $meta_key)) {
            return [
                'reference_source' => 'heuristic',
                'field_type'       => 'relationship',
                'field_label'      => $meta_key,
            ];
        }

        return null;
    }

    /**
     * Collect referenced post IDs from a candidate relationship value.
     *
     * @param mixed $value
     * @return array<int,int>
     */
    private static function collect_referenced_post_ids($value): array
    {
        $ids = [];

        if (is_array($value)) {
            $is_list = function_exists('array_is_list')
                ? array_is_list($value)
                : array_keys($value) === range(0, count($value) - 1);

            if ($is_list) {
                foreach ($value as $child) {
                    foreach (self::collect_referenced_post_ids($child) as $id) {
                        $ids[$id] = $id;
                    }
                }
                return array_values($ids);
            }

            foreach (['ID', 'id', 'post_id', 'post_ID'] as $key) {
                if (! array_key_exists($key, $value)) {
                    continue;
                }

                foreach (self::collect_referenced_post_ids($value[$key]) as $id) {
                    $ids[$id] = $id;
                }
            }

            return array_values($ids);
        }

        if (is_object($value)) {
            return self::collect_referenced_post_ids(get_object_vars($value));
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }

            if (function_exists('is_serialized') && is_serialized($trimmed)) {
                return self::collect_referenced_post_ids(maybe_unserialize($trimmed));
            }

            if (($trimmed[0] === '[' || $trimmed[0] === '{')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    return self::collect_referenced_post_ids($decoded);
                }
            }

            if (preg_match('/^\d+(,\d+)+$/', $trimmed)) {
                return self::collect_referenced_post_ids(array_map('trim', explode(',', $trimmed)));
            }

            if (! ctype_digit($trimmed)) {
                return [];
            }

            $value = (int) $trimmed;
        }

        if (! is_int($value) || $value <= 0) {
            return [];
        }

        $post = get_post($value);
        if (! $post || is_wp_error($post) || $post->post_type === 'attachment') {
            return [];
        }

        return [$value];
    }

    /**
     * Resolve a referenced post into matching keys and labels.
     *
     * @param int $post_id
     * @return array<string,mixed>|null
     */
    private static function resolve_post_reference_context(int $post_id): ?array
    {
        $post = get_post($post_id);
        if (! $post || is_wp_error($post) || $post->post_type === 'attachment') {
            return null;
        }

        $post_type = sanitize_key((string) $post->post_type);
        $post_name = sanitize_title((string) $post->post_name);
        $post_title = sanitize_text_field((string) $post->post_title);
        $uid = sanitize_text_field((string) get_post_meta($post_id, 'vf_object_uid', true));

        return [
            'post_id'       => $post_id,
            'post_type'     => $post_type,
            'post_name'     => $post_name,
            'post_title'    => $post_title,
            'vf_object_uid' => $uid,
        ];
    }

    /**
     * Build normalized post reference keys from a payload.
     *
     * @param array $decoded
     * @return array<int,string>
     */
    private static function build_post_reference_keys_from_payload(array $decoded): array
    {
        return self::build_post_reference_keys_from_context([
            'post_id'       => isset($decoded['ID']) ? absint($decoded['ID']) : 0,
            'post_type'     => isset($decoded['post_type']) ? sanitize_key((string) $decoded['post_type']) : '',
            'post_name'     => isset($decoded['post_name']) ? sanitize_title((string) $decoded['post_name']) : '',
            'vf_object_uid' => isset($decoded['vf_object_uid']) ? sanitize_text_field((string) $decoded['vf_object_uid']) : '',
        ]);
    }

    /**
     * Build normalized post reference keys from a context array.
     *
     * @param array $context
     * @return array<int,string>
     */
    private static function build_post_reference_keys_from_context(array $context): array
    {
        $keys = [];
        $uid = isset($context['vf_object_uid']) ? sanitize_text_field((string) $context['vf_object_uid']) : '';
        $post_type = isset($context['post_type']) ? sanitize_key((string) $context['post_type']) : '';
        $post_name = isset($context['post_name']) ? sanitize_title((string) $context['post_name']) : '';
        $post_id = isset($context['post_id']) ? absint($context['post_id']) : 0;

        if ($uid !== '') {
            $keys[] = 'uid:' . $uid;
        }
        if ($post_type !== '' && $post_name !== '') {
            $keys[] = 'post:' . $post_type . ':' . $post_name;
        }
        if ($post_type !== '' && $post_id > 0) {
            $keys[] = 'post:' . $post_type . ':' . $post_id;
        }

        return array_values(array_unique($keys));
    }

    /**
     * Determine whether a referenced post is already included in the packet.
     *
     * @param array $context
     * @param array $included_post_refs
     * @return bool
     */
    private static function is_included_post_reference(array $context, array $included_post_refs): bool
    {
        foreach (self::build_post_reference_keys_from_context($context) as $key) {
            if (isset($included_post_refs[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extend the generated manifest with transfer-specific metadata.
     *
     * @param array  $manifest
     * @param string $packet_id
     * @param array  $state
     * @return array
     */
    private static function extend_manifest(array $manifest, string $packet_id, array $state): array
    {
        $manifest['origin'] = array_merge(
            isset($manifest['origin']) && is_array($manifest['origin']) ? $manifest['origin'] : [],
            [
                'type'                => 'entity_transfer',
                'packet_id'           => $packet_id,
                'source_surface'      => 'entity_editor',
                'generated_from_site' => home_url(),
                'generated_from_name' => get_bloginfo('name'),
            ]
        );

        $manifest['selection'] = [
            'summary' => [
                'requested_paths'    => count($state['selected_paths']),
                'selected_posts'     => (int) $state['stats']['selected_posts'],
                'selected_terms'     => (int) $state['stats']['selected_terms'],
                'dependency_terms'   => (int) $state['stats']['dependency_terms'],
                'live_exports'       => (int) $state['stats']['live_exports'],
                'fallback_files'     => (int) $state['stats']['fallback_files'],
                'duplicates_skipped' => (int) $state['stats']['duplicates_skipped'],
                'missing_dependencies' => (int) $state['stats']['missing_dependencies'],
            ],
            'requested_paths' => array_values($state['selected_paths']),
        ];

        $requirements = self::build_requirements_payload($manifest, $state);
        if (! empty($requirements)) {
            $manifest['requirements'] = $requirements;
        }

        $warnings = self::build_warnings_payload($state);
        if (! empty($warnings)) {
            $manifest['warnings'] = $warnings;
        }

        if (! empty($manifest['media_bundle']['storage']) && is_array($manifest['media_bundle']['storage'])) {
            unset($manifest['media_bundle']['storage']['absolute']);
        }

        $checksum = wp_json_encode($manifest, JSON_UNESCAPED_SLASHES);
        if (is_string($checksum) && $checksum !== '') {
            $manifest['checksum'] = hash('sha256', $checksum);
        }

        return $manifest;
    }

    /**
     * Build the additive structured warnings section.
     *
     * @param array $state
     * @return array
     */
    private static function build_warnings_payload(array $state): array
    {
        $unsupported_post_references = [];
        $warnings = isset($state['warnings']) && is_array($state['warnings']) ? $state['warnings'] : [];
        $items = isset($warnings['unsupported_post_references']) && is_array($warnings['unsupported_post_references'])
            ? $warnings['unsupported_post_references']
            : [];

        foreach ($items as $warning) {
            if (! is_array($warning)) {
                continue;
            }

            $unsupported_post_references[] = [
                'source_path'           => isset($warning['source_path']) ? str_replace('\\', '/', ltrim((string) $warning['source_path'], '/')) : '',
                'source_post_id'        => isset($warning['source_post_id']) ? absint($warning['source_post_id']) : 0,
                'source_post_type'      => isset($warning['source_post_type']) ? sanitize_key((string) $warning['source_post_type']) : '',
                'source_post_name'      => isset($warning['source_post_name']) ? sanitize_title((string) $warning['source_post_name']) : '',
                'source_post_title'     => isset($warning['source_post_title']) ? sanitize_text_field((string) $warning['source_post_title']) : '',
                'source_uid'            => isset($warning['source_uid']) ? sanitize_text_field((string) $warning['source_uid']) : '',
                'meta_key'              => isset($warning['meta_key']) ? sanitize_key((string) $warning['meta_key']) : '',
                'field_label'           => isset($warning['field_label']) ? sanitize_text_field((string) $warning['field_label']) : '',
                'field_type'            => isset($warning['field_type']) ? sanitize_key((string) $warning['field_type']) : '',
                'reference_source'      => isset($warning['reference_source']) ? sanitize_key((string) $warning['reference_source']) : '',
                'referenced_post_id'    => isset($warning['referenced_post_id']) ? absint($warning['referenced_post_id']) : 0,
                'referenced_post_type'  => isset($warning['referenced_post_type']) ? sanitize_key((string) $warning['referenced_post_type']) : '',
                'referenced_post_name'  => isset($warning['referenced_post_name']) ? sanitize_title((string) $warning['referenced_post_name']) : '',
                'referenced_post_title' => isset($warning['referenced_post_title']) ? sanitize_text_field((string) $warning['referenced_post_title']) : '',
                'referenced_uid'        => isset($warning['referenced_uid']) ? sanitize_text_field((string) $warning['referenced_uid']) : '',
            ];
        }

        $unsupported_post_references = array_values(array_filter($unsupported_post_references, static function (array $warning): bool {
            return ! empty($warning['source_path']) && ! empty($warning['meta_key']) && ! empty($warning['referenced_post_id']);
        }));

        if (empty($unsupported_post_references)) {
            return [];
        }

        return [
            'summary' => [
                'unsupported_post_references' => count($unsupported_post_references),
            ],
            'unsupported_post_references' => $unsupported_post_references,
        ];
    }

    /**
     * Build the additive requirements manifest section.
     *
     * @param array $manifest
     * @param array $state
     * @return array
     */
    private static function build_requirements_payload(array $manifest, array $state): array
    {
        $post_types = [];
        $taxonomies = [];

        $items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';
            if ($item_type === 'term') {
                $taxonomy = isset($item['term_taxonomy']) ? sanitize_key((string) $item['term_taxonomy']) : '';
                if ($taxonomy !== '') {
                    $taxonomies[$taxonomy] = $taxonomy;
                }
                continue;
            }

            if ($item_type !== 'post') {
                continue;
            }

            $post_type = isset($item['post_type']) ? sanitize_key((string) $item['post_type']) : '';
            if ($post_type !== '') {
                $post_types[$post_type] = $post_type;
            }

            if (! empty($item['tax_input']) && is_array($item['tax_input'])) {
                foreach (array_keys($item['tax_input']) as $taxonomy_key) {
                    $taxonomy = sanitize_key((string) $taxonomy_key);
                    if ($taxonomy !== '') {
                        $taxonomies[$taxonomy] = $taxonomy;
                    }
                }
            }
        }

        $notes = array_values(array_unique(array_filter($state['notes'])));
        if (
            ! empty($manifest['totals']['media_items'])
            && (! class_exists('DBVC_Media_Sync') || ! \DBVC_Media_Sync::is_bundle_enabled())
        ) {
            $notes[] = __('Media bundle transport is disabled on the source site, so this packet includes media references but no bundled files.', 'dbvc');
        }

        sort($post_types);
        sort($taxonomies);
        sort($notes);

        return [
            'post_types' => array_values($post_types),
            'taxonomies' => array_values($taxonomies),
            'notes'      => $notes,
        ];
    }

    /**
     * Persist the patched manifest.
     *
     * @param string $staging_path
     * @param array  $manifest
     * @return void
     */
    private static function write_manifest(string $staging_path, array $manifest): void
    {
        $manifest_path = trailingslashit($staging_path) . \DBVC_Backup_Manager::MANIFEST_FILENAME;
        $encoded = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || $encoded === '') {
            throw new \RuntimeException(__('Unable to encode the transfer packet manifest.', 'dbvc'));
        }

        if (false === file_put_contents($manifest_path, $encoded)) {
            throw new \RuntimeException(__('Unable to write the transfer packet manifest.', 'dbvc'));
        }
    }

    /**
     * Create the ZIP archive from the staging directory.
     *
     * @param string $staging_path
     * @param string $zip_path
     * @return void
     */
    private static function build_zip_archive(string $staging_path, string $zip_path): void
    {
        self::delete_file($zip_path);

        $zip = new \ZipArchive();
        $open = $zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($open !== true) {
            throw new \RuntimeException(__('Unable to open the transfer packet ZIP archive.', 'dbvc'));
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($staging_path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $basename = $file->getFilename();
            if ($basename === '.htaccess' || $basename === 'index.php') {
                continue;
            }

            $absolute = $file->getPathname();
            $relative = str_replace('\\', '/', ltrim(substr($absolute, strlen(trailingslashit($staging_path))), '/'));
            if ($relative === '') {
                continue;
            }

            $zip->addFile($absolute, $relative);
        }

        $zip->close();

        if (! is_file($zip_path)) {
            throw new \RuntimeException(__('Transfer packet ZIP creation did not produce an archive.', 'dbvc'));
        }
    }

    /**
     * Determine the workspace root for outbound transfer packets.
     *
     * @return string|\WP_Error
     */
    private static function get_workspace_root()
    {
        $uploads = wp_get_upload_dir();
        if (! empty($uploads['error'])) {
            return new \WP_Error('dbvc_transfer_uploads_failed', __('Unable to resolve the uploads directory for transfer packets.', 'dbvc'), ['status' => 500]);
        }

        $sync_root = trailingslashit($uploads['basedir']) . 'sync';
        if (! is_dir($sync_root) && ! wp_mkdir_p($sync_root)) {
            return new \WP_Error('dbvc_transfer_sync_root_failed', __('Unable to create the sync root for transfer packets.', 'dbvc'), ['status' => 500]);
        }

        if (class_exists('DBVC_Sync_Posts')) {
            \DBVC_Sync_Posts::ensure_directory_security($sync_root);
        }

        $workspace_root = trailingslashit($sync_root) . self::WORKSPACE_SUBDIR;
        if (! is_dir($workspace_root) && ! wp_mkdir_p($workspace_root)) {
            return new \WP_Error('dbvc_transfer_workspace_failed', __('Unable to create the transfer packet workspace.', 'dbvc'), ['status' => 500]);
        }

        if (class_exists('DBVC_Sync_Posts')) {
            \DBVC_Sync_Posts::ensure_directory_security($workspace_root);
        }

        return $workspace_root;
    }

    /**
     * Ensure a directory exists and is hardened.
     *
     * @param string $path
     * @return void
     */
    private static function ensure_directory(string $path): void
    {
        if (! is_dir($path) && ! wp_mkdir_p($path)) {
            throw new \RuntimeException(__('Unable to create the transfer packet staging directory.', 'dbvc'));
        }

        if (class_exists('DBVC_Sync_Posts')) {
            \DBVC_Sync_Posts::ensure_directory_security($path);
        }
    }

    /**
     * Create a stable-ish packet identifier.
     *
     * @return string
     */
    private static function build_packet_id(): string
    {
        $host = parse_url(home_url(), PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? sanitize_title(str_replace('.', '-', $host)) : 'site';
        $suffix = strtolower(wp_generate_password(4, false, false));

        return sprintf(
            'entity-transfer-%s-%s-%s',
            $host ?: 'site',
            gmdate('Ymd-His'),
            $suffix
        );
    }

    /**
     * Build the user-facing download filename.
     *
     * @param string $packet_id
     * @return string
     */
    private static function build_download_filename(string $packet_id): string
    {
        return sanitize_file_name($packet_id . '.zip');
    }

    /**
     * Compute a stable entity key for dedupe purposes.
     *
     * @param array  $decoded
     * @param string $fallback_relative
     * @return string
     */
    private static function build_entity_key(array $decoded, string $fallback_relative = ''): string
    {
        $uid = isset($decoded['vf_object_uid']) ? sanitize_text_field((string) $decoded['vf_object_uid']) : '';
        if ($uid !== '') {
            return 'uid:' . $uid;
        }

        if (self::detect_entity_kind($decoded) === 'term') {
            $taxonomy = isset($decoded['taxonomy']) ? sanitize_key((string) $decoded['taxonomy']) : '';
            $slug = isset($decoded['slug']) ? sanitize_title((string) $decoded['slug']) : '';
            $term_id = isset($decoded['term_id']) ? absint($decoded['term_id']) : 0;

            if ($taxonomy !== '' && $slug !== '') {
                return 'term:' . $taxonomy . ':' . $slug;
            }
            if ($taxonomy !== '' && $term_id > 0) {
                return 'term:' . $taxonomy . ':' . $term_id;
            }
        } else {
            $post_type = isset($decoded['post_type']) ? sanitize_key((string) $decoded['post_type']) : '';
            $post_name = isset($decoded['post_name']) ? sanitize_title((string) $decoded['post_name']) : '';
            $post_id = isset($decoded['ID']) ? absint($decoded['ID']) : 0;

            if ($post_type !== '' && $post_name !== '') {
                return 'post:' . $post_type . ':' . $post_name;
            }
            if ($post_type !== '' && $post_id > 0) {
                return 'post:' . $post_type . ':' . $post_id;
            }
        }

        return $fallback_relative !== '' ? 'path:' . $fallback_relative : '';
    }

    /**
     * Detect whether a payload is a post or term entity.
     *
     * @param array $decoded
     * @return string
     */
    private static function detect_entity_kind(array $decoded): string
    {
        if (isset($decoded['taxonomy']) && (isset($decoded['slug']) || isset($decoded['term_id']))) {
            return 'term';
        }

        if (isset($decoded['ID']) && isset($decoded['post_type'])) {
            return 'post';
        }

        return '';
    }

    /**
     * Append a unique operator-facing build note.
     *
     * @param array  $state
     * @param string $note
     * @return void
     */
    private static function append_note(array &$state, string $note): void
    {
        $note = trim($note);
        if ($note === '') {
            return;
        }

        $state['notes'][$note] = $note;
    }

    /**
     * Clean up staging, bundle, and optional archive artifacts for a packet workspace.
     *
     * @param string $packet_id
     * @param string $staging_path
     * @param string $zip_path
     * @return void
     */
    private static function cleanup_workspace(string $packet_id, string $staging_path, string $zip_path = ''): void
    {
        if ($packet_id !== '') {
            self::cleanup_bundle_directory($packet_id);
        }

        self::delete_file($zip_path);
        self::delete_directory_recursive($staging_path);
    }

    /**
     * Remove an existing bundle workspace created during manifest generation.
     *
     * @param string $packet_id
     * @return void
     */
    private static function cleanup_bundle_directory(string $packet_id): void
    {
        if (! class_exists('\Dbvc\Media\BundleManager')) {
            return;
        }

        $bundle_dir = \Dbvc\Media\BundleManager::get_proposal_directory($packet_id);
        if ($bundle_dir) {
            self::delete_directory_recursive($bundle_dir);
        }
    }

    /**
     * Delete a file if it exists.
     *
     * @param string $path
     * @return void
     */
    private static function delete_file(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Recursively delete a directory.
     *
     * @param string $path
     * @return void
     */
    private static function delete_directory_recursive(string $path): void
    {
        if ($path === '' || ! file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($path);
    }
}
