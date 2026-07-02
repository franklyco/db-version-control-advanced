<?php

namespace Dbvc\EntityEditor;

if (! defined('WPINC')) {
    die;
}

/**
 * Preview and apply selected-entity JSON merges inside the Entity Editor.
 */
final class EntityJsonMergeService
{
    private const MODE_SAVE = 'save';
    private const MODE_SAVE_AND_PARTIAL_IMPORT = 'save_and_partial_import';
    private const PROTECTED_POST_META_KEYS = [
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_page_template',
        '_thumbnail_id',
    ];
    private const PROTECTED_TERM_META_KEYS = [
        '_edit_lock',
        '_edit_last',
    ];

    /**
     * Build a proposed merged JSON payload for one selected Entity Editor file.
     *
     * @param string              $relative_path
     * @param string              $incoming_json
     * @param array<string,mixed> $identity
     * @param string              $mode
     * @return array<string,mixed>|\WP_Error
     */
    public static function preview($relative_path, $incoming_json, array $identity = [], $mode = self::MODE_SAVE)
    {
        if (! \class_exists('DBVC_Entity_Editor_Indexer')) {
            return new \WP_Error(
                'dbvc_entity_editor_merge_indexer_unavailable',
                __('Entity Editor indexer unavailable.', 'dbvc'),
                ['status' => 500]
            );
        }

        $relative_path = self::normalize_relative_path($relative_path);
        $mode = self::normalize_mode($mode);
        $identity = self::normalize_identity_choices($identity);

        $current_file = \DBVC_Entity_Editor_Indexer::load_entity_file_for_download($relative_path);
        if (\is_wp_error($current_file)) {
            return $current_file;
        }

        $current = isset($current_file['decoded']) && \is_array($current_file['decoded']) ? $current_file['decoded'] : [];
        $incoming = self::decode_incoming_json($incoming_json);
        if (\is_wp_error($incoming)) {
            return $incoming;
        }

        $current_info = self::inspect_entity($current);
        $incoming_info = self::inspect_entity($incoming);
        $blockers = [];
        $notes = [];

        if ($current_info['kind'] === '') {
            $blockers[] = self::message(
                'unsupported_current_entity',
                __('The selected Entity Editor file is not a supported post/CPT or term JSON payload.', 'dbvc')
            );
        }

        if ($incoming_info['kind'] === '') {
            $blockers[] = self::message(
                'unsupported_incoming_entity',
                __('The incoming JSON is not a supported post/CPT or term entity payload.', 'dbvc')
            );
        }

        if ($current_info['kind'] !== '' && $incoming_info['kind'] !== '' && $current_info['kind'] !== $incoming_info['kind']) {
            $blockers[] = self::message(
                'entity_kind_mismatch',
                __('The selected entity and incoming JSON are different entity kinds.', 'dbvc')
            );
        }

        if ($current_info['subtype'] !== '' && $incoming_info['subtype'] !== '' && $current_info['subtype'] !== $incoming_info['subtype']) {
            $blockers[] = self::message(
                'entity_subtype_mismatch',
                __('The selected entity and incoming JSON use different post types or taxonomies.', 'dbvc')
            );
        }

        $local_match = self::find_local_match($current_info, $current);
        $current_authority_info = self::apply_local_match_authority($current_info, $local_match, $notes);
        if ($mode === self::MODE_SAVE_AND_PARTIAL_IMPORT && empty($local_match['id'])) {
            $blockers[] = self::message(
                'missing_local_entity_match',
                __('Save + Partial Import requires the selected JSON to match an existing local WordPress entity.', 'dbvc')
            );
        }

        $merged = $current;
        $counts = [
            'core_fields_merged' => 0,
            'meta_keys_merged' => 0,
            'taxonomies_merged' => 0,
        ];

        if (empty($blockers)) {
            $merge_result = $current_authority_info['kind'] === 'term'
                ? self::merge_term_payload($current, $incoming, $current_authority_info, $incoming_info, $identity, $notes)
                : self::merge_post_payload($current, $incoming, $current_authority_info, $incoming_info, $identity, $notes);

            $merged = $merge_result['payload'];
            $counts = $merge_result['counts'];

            $slug_blocker = self::find_slug_collision_blocker($merged, $current_authority_info, $incoming_info, $identity);
            if (\is_array($slug_blocker)) {
                $blockers[] = $slug_blocker;
            }
        }

        $proposed_json = self::encode_payload($merged);
        if (\is_wp_error($proposed_json)) {
            return $proposed_json;
        }

        $summary = [
            'kind'         => $current_authority_info['kind'],
            'subtype'      => $current_authority_info['subtype'],
            'local_id'     => (int) $current_authority_info['id'],
            'incoming_id'  => (int) $incoming_info['id'],
            'local_uid'    => $current_authority_info['uid'],
            'incoming_uid' => $incoming_info['uid'],
            'local_slug'   => $current_authority_info['slug'],
            'incoming_slug'=> $incoming_info['slug'],
            'local_title'  => $current_authority_info['title'],
            'incoming_title' => $incoming_info['title'],
            'uid_policy'   => $identity['uid'],
            'slug_policy'  => $identity['slug'],
            'id_policy'    => 'keep_local',
            'title_policy' => $identity['title'],
            'selected_file_identity' => [
                'id'    => (int) $current_info['id'],
                'uid'   => $current_info['uid'],
                'slug'  => $current_info['slug'],
                'title' => $current_info['title'],
            ],
            'local_match'  => $local_match,
            'counts'       => $counts,
        ];

        $preview_hash = self::build_preview_hash(
            $relative_path,
            isset($current_file['content']) ? (string) $current_file['content'] : '',
            (string) $incoming_json,
            $identity,
            (string) $proposed_json
        );

        return [
            'ok'                => empty($blockers),
            'relative_path'     => $relative_path,
            'mode'              => $mode,
            'preview_hash'      => $preview_hash,
            'entity_kind'       => $current_info['kind'],
            'subtype'           => $current_info['subtype'],
            'summary'           => $summary,
            'blockers'          => $blockers,
            'blocking'          => $blockers,
            'notes'             => self::dedupe_messages($notes),
            'proposed_json'     => (string) $proposed_json,
            'available_actions' => [
                self::MODE_SAVE => empty($blockers),
                self::MODE_SAVE_AND_PARTIAL_IMPORT => empty($blockers) && ! empty($local_match['id']),
            ],
        ];
    }

    /**
     * Save the server-generated merge result, optionally followed by partial import.
     *
     * @param string              $relative_path
     * @param string              $incoming_json
     * @param array<string,mixed> $identity
     * @param int                 $user_id
     * @param string              $lock_token
     * @param bool                $force_takeover
     * @param string              $preview_hash
     * @param bool                $confirmed
     * @param bool                $partial_import
     * @return array<string,mixed>|\WP_Error
     */
    public static function save($relative_path, $incoming_json, array $identity, $user_id, $lock_token, $force_takeover, $preview_hash, $confirmed, $partial_import = false)
    {
        if (! $confirmed) {
            return new \WP_Error(
                'dbvc_entity_editor_merge_confirmation_required',
                __('Confirm the merge before saving incoming JSON into the selected entity.', 'dbvc'),
                ['status' => 400]
            );
        }

        $mode = $partial_import ? self::MODE_SAVE_AND_PARTIAL_IMPORT : self::MODE_SAVE;
        $preview = self::preview($relative_path, $incoming_json, $identity, $mode);
        if (\is_wp_error($preview)) {
            return $preview;
        }

        $provided_hash = trim((string) $preview_hash);
        $current_hash = isset($preview['preview_hash']) ? (string) $preview['preview_hash'] : '';
        if ($provided_hash === '' || $current_hash === '' || ! \hash_equals($current_hash, $provided_hash)) {
            return new \WP_Error(
                'dbvc_entity_editor_merge_stale_preview',
                __('The merge preview changed. Refresh the preview before saving.', 'dbvc'),
                [
                    'status'  => 409,
                    'preview' => $preview,
                ]
            );
        }

        $blockers = isset($preview['blockers']) && \is_array($preview['blockers']) ? $preview['blockers'] : [];
        if (! empty($blockers)) {
            return new \WP_Error(
                'dbvc_entity_editor_merge_blocked',
                isset($blockers[0]['message']) ? (string) $blockers[0]['message'] : __('The merge has blockers that must be resolved before saving.', 'dbvc'),
                [
                    'status'  => 409,
                    'preview' => $preview,
                ]
            );
        }

        $content = isset($preview['proposed_json']) ? (string) $preview['proposed_json'] : '';
        $forced_match = [];
        if ($partial_import) {
            $summary = isset($preview['summary']) && \is_array($preview['summary']) ? $preview['summary'] : [];
            $local_match = isset($summary['local_match']) && \is_array($summary['local_match']) ? $summary['local_match'] : [];
            if (! empty($local_match['id'])) {
                $forced_match = [
                    'id'      => (int) $local_match['id'],
                    'kind'    => isset($local_match['kind']) ? (string) $local_match['kind'] : '',
                    'subtype' => isset($local_match['subtype']) ? (string) $local_match['subtype'] : '',
                    'source'  => 'selected_entity',
                ];
            }
        }
        $result = $partial_import
            ? \DBVC_Entity_Editor_Indexer::save_and_partial_import($relative_path, $content, (int) $user_id, (string) $lock_token, (bool) $force_takeover, $forced_match)
            : \DBVC_Entity_Editor_Indexer::save_entity_file($relative_path, $content, (int) $user_id, (string) $lock_token, (bool) $force_takeover);

        if (\is_wp_error($result)) {
            return $result;
        }

        if (\class_exists('DBVC_Sync_Logger')) {
            \DBVC_Sync_Logger::log('Entity Editor merge incoming JSON', [
                'relative_path'  => (string) $relative_path,
                'user_id'        => (int) $user_id,
                'mode'           => $mode,
                'preview_hash'   => $current_hash,
                'entity_kind'    => $preview['entity_kind'] ?? '',
                'subtype'        => $preview['subtype'] ?? '',
            ]);
        }

        return \array_merge(
            $result,
            [
                'merge' => [
                    'preview_hash' => $current_hash,
                    'summary'      => $preview['summary'] ?? [],
                    'notes'        => $preview['notes'] ?? [],
                    'blockers'     => [],
                ],
            ]
        );
    }

    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $incoming
     * @param array<string,mixed> $current_info
     * @param array<string,mixed> $incoming_info
     * @param array<string,string> $identity
     * @param array<int,array<string,string>> $notes
     * @return array{payload:array<string,mixed>,counts:array<string,int>}
     */
    private static function merge_post_payload(array $current, array $incoming, array $current_info, array $incoming_info, array $identity, array &$notes)
    {
        $merged = $current;
        $counts = [
            'core_fields_merged' => 0,
            'meta_keys_merged' => 0,
            'taxonomies_merged' => 0,
        ];

        $skip = [
            'ID' => true,
            'post_type' => true,
            'post_name' => true,
            'post_title' => true,
            'vf_object_uid' => true,
            'dbvc_object_uid' => true,
            'meta' => true,
            'tax_input' => true,
        ];

        foreach ($incoming as $key => $value) {
            if (isset($skip[(string) $key])) {
                continue;
            }
            $merged[$key] = $value;
            $counts['core_fields_merged']++;
        }

        if ((int) $current_info['id'] > 0) {
            $merged['ID'] = (int) $current_info['id'];
            if ((int) $incoming_info['id'] > 0 && (int) $incoming_info['id'] !== (int) $current_info['id']) {
                $notes[] = self::message('incoming_id_ignored', __('Incoming ID differs from the local entity and will be ignored.', 'dbvc'), 'note');
            }
        }

        $merged['post_type'] = (string) $current_info['subtype'];
        $merged['post_name'] = self::choose_identity_value($current_info['slug'], $incoming_info['slug'], $identity['slug']);
        $merged['post_title'] = self::choose_identity_value($current_info['title'], $incoming_info['title'], $identity['title'] === 'keep_local' ? 'keep_local' : 'use_incoming');
        self::apply_uid_choice($merged, $current_info['uid'], $incoming_info['uid'], $identity['uid'], $notes);

        if ($identity['slug'] === 'keep_local' && $incoming_info['slug'] !== '' && $incoming_info['slug'] !== $current_info['slug']) {
            $notes[] = self::message('incoming_slug_ignored', __('Incoming slug differs from the local slug and will be ignored.', 'dbvc'), 'note');
        }

        if ($identity['uid'] === 'keep_local' && $incoming_info['uid'] !== '' && $incoming_info['uid'] !== $current_info['uid']) {
            $notes[] = self::message('incoming_uid_ignored', __('Incoming UID differs from the local UID and will be ignored.', 'dbvc'), 'note');
        }

        $protected_keys = self::get_protected_post_meta_keys();
        $merged['meta'] = self::merge_meta(
            isset($current['meta']) && \is_array($current['meta']) ? $current['meta'] : [],
            isset($incoming['meta']) && \is_array($incoming['meta']) ? $incoming['meta'] : [],
            $protected_keys,
            $notes,
            $counts
        );
        self::apply_uid_choice($merged, $current_info['uid'], $incoming_info['uid'], $identity['uid'], $notes);

        if (isset($incoming['tax_input']) && \is_array($incoming['tax_input'])) {
            $merged['tax_input'] = isset($merged['tax_input']) && \is_array($merged['tax_input']) ? $merged['tax_input'] : [];
            foreach ($incoming['tax_input'] as $taxonomy => $terms) {
                $merged['tax_input'][(string) $taxonomy] = $terms;
                $counts['taxonomies_merged']++;
            }
        }

        if ((string) $current_info['subtype'] === 'bricks_template') {
            $merged = self::preserve_bricks_local_scope($merged, $current, $incoming, $notes);
        }

        if (self::contains_acf_field_reference($incoming)) {
            $notes[] = self::message(
                'acf_references_not_validated',
                __('Incoming ACF field keys or references were detected but not validated against local field groups.', 'dbvc'),
                'note'
            );
        }

        return [
            'payload' => $merged,
            'counts'  => $counts,
        ];
    }

    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $incoming
     * @param array<string,mixed> $current_info
     * @param array<string,mixed> $incoming_info
     * @param array<string,string> $identity
     * @param array<int,array<string,string>> $notes
     * @return array{payload:array<string,mixed>,counts:array<string,int>}
     */
    private static function merge_term_payload(array $current, array $incoming, array $current_info, array $incoming_info, array $identity, array &$notes)
    {
        $merged = $current;
        $counts = [
            'core_fields_merged' => 0,
            'meta_keys_merged' => 0,
            'taxonomies_merged' => 0,
        ];

        $skip = [
            'term_id' => true,
            'taxonomy' => true,
            'slug' => true,
            'name' => true,
            'term_name' => true,
            'vf_object_uid' => true,
            'dbvc_object_uid' => true,
            'meta' => true,
            'parent' => true,
            'parent_slug' => true,
        ];

        foreach ($incoming as $key => $value) {
            if (isset($skip[(string) $key])) {
                continue;
            }
            $merged[$key] = $value;
            $counts['core_fields_merged']++;
        }

        if ((int) $current_info['id'] > 0) {
            $merged['term_id'] = (int) $current_info['id'];
            if ((int) $incoming_info['id'] > 0 && (int) $incoming_info['id'] !== (int) $current_info['id']) {
                $notes[] = self::message('incoming_id_ignored', __('Incoming term ID differs from the local term and will be ignored.', 'dbvc'), 'note');
            }
        }

        $merged['taxonomy'] = (string) $current_info['subtype'];
        $merged['slug'] = self::choose_identity_value($current_info['slug'], $incoming_info['slug'], $identity['slug']);
        $title = self::choose_identity_value($current_info['title'], $incoming_info['title'], $identity['title'] === 'keep_local' ? 'keep_local' : 'use_incoming');
        if ($title !== '') {
            $merged['name'] = $title;
        }
        self::apply_uid_choice($merged, $current_info['uid'], $incoming_info['uid'], $identity['uid'], $notes);

        if ($identity['slug'] === 'keep_local' && $incoming_info['slug'] !== '' && $incoming_info['slug'] !== $current_info['slug']) {
            $notes[] = self::message('incoming_slug_ignored', __('Incoming slug differs from the local slug and will be ignored.', 'dbvc'), 'note');
        }

        if ($identity['uid'] === 'keep_local' && $incoming_info['uid'] !== '' && $incoming_info['uid'] !== $current_info['uid']) {
            $notes[] = self::message('incoming_uid_ignored', __('Incoming UID differs from the local UID and will be ignored.', 'dbvc'), 'note');
        }

        if (array_key_exists('parent_slug', $incoming) || array_key_exists('parent', $incoming)) {
            $parent = self::resolve_incoming_term_parent($incoming, (string) $current_info['subtype']);
            if ($parent['resolved']) {
                $merged['parent'] = $parent['parent'];
                if ($parent['parent_slug'] !== '') {
                    $merged['parent_slug'] = $parent['parent_slug'];
                }
            } else {
                $notes[] = self::message(
                    'incoming_term_parent_not_mapped',
                    __('Incoming term parent could not be mapped locally, so the current parent reference was preserved.', 'dbvc'),
                    'note'
                );
            }
        }

        $merged['meta'] = self::merge_meta(
            isset($current['meta']) && \is_array($current['meta']) ? $current['meta'] : [],
            isset($incoming['meta']) && \is_array($incoming['meta']) ? $incoming['meta'] : [],
            self::get_protected_term_meta_keys(),
            $notes,
            $counts
        );
        self::apply_uid_choice($merged, $current_info['uid'], $incoming_info['uid'], $identity['uid'], $notes);

        if (self::contains_acf_field_reference($incoming)) {
            $notes[] = self::message(
                'acf_references_not_validated',
                __('Incoming ACF field keys or references were detected but not validated against local field groups.', 'dbvc'),
                'note'
            );
        }

        return [
            'payload' => $merged,
            'counts'  => $counts,
        ];
    }

    /**
     * @param array<string,mixed> $current_meta
     * @param array<string,mixed> $incoming_meta
     * @param array<int,string>   $protected_keys
     * @param array<int,array<string,string>> $notes
     * @param array<string,int>   $counts
     * @return array<string,mixed>
     */
    private static function merge_meta(array $current_meta, array $incoming_meta, array $protected_keys, array &$notes, array &$counts)
    {
        $merged = $current_meta;
        $protected = \array_fill_keys($protected_keys, true);

        foreach ($incoming_meta as $key => $incoming_value) {
            $meta_key = (string) $key;
            if ($meta_key === '') {
                continue;
            }

            if (isset($protected[$meta_key]) && \array_key_exists($meta_key, $current_meta)) {
                if (! self::values_equal($current_meta[$meta_key], $incoming_value)) {
                    $notes[] = self::message(
                        'protected_meta_preserved',
                        sprintf(__('Protected local meta "%s" was preserved.', 'dbvc'), $meta_key),
                        'note'
                    );
                }
                $merged[$meta_key] = $current_meta[$meta_key];
                continue;
            }

            $merged[$meta_key] = $incoming_value;
            $counts['meta_keys_merged']++;
        }

        return $merged;
    }

    /**
     * Preserve local Bricks scope references in the first merge slice.
     *
     * @param array<string,mixed> $merged
     * @param array<string,mixed> $current
     * @param array<string,mixed> $incoming
     * @param array<int,array<string,string>> $notes
     * @return array<string,mixed>
     */
    private static function preserve_bricks_local_scope(array $merged, array $current, array $incoming, array &$notes)
    {
        $current_meta = isset($current['meta']) && \is_array($current['meta']) ? $current['meta'] : [];
        $incoming_meta = isset($incoming['meta']) && \is_array($incoming['meta']) ? $incoming['meta'] : [];
        $merged_meta = isset($merged['meta']) && \is_array($merged['meta']) ? $merged['meta'] : [];

        if (\array_key_exists('_bricks_template_type', $current_meta) && \array_key_exists('_bricks_template_type', $incoming_meta)) {
            $current_type = self::unwrap_single_meta_value($current_meta['_bricks_template_type']);
            $incoming_type = self::unwrap_single_meta_value($incoming_meta['_bricks_template_type']);
            if (! self::values_equal($current_type, $incoming_type)) {
                $merged_meta['_bricks_template_type'] = $current_meta['_bricks_template_type'];
                $notes[] = self::message(
                    'bricks_template_type_preserved',
                    __('Incoming Bricks template type differs, so the local template type was preserved.', 'dbvc'),
                    'note'
                );
            }
        }

        if (\array_key_exists('_bricks_template_settings', $current_meta) && \array_key_exists('_bricks_template_settings', $incoming_meta)) {
            $current_settings = self::unwrap_single_meta_value($current_meta['_bricks_template_settings']);
            $incoming_settings = self::unwrap_single_meta_value($incoming_meta['_bricks_template_settings']);
            if (\is_array($current_settings) && \is_array($incoming_settings)) {
                $settings = $incoming_settings;
                $reference_keys = [
                    'templateConditions',
                    'templatePreviewPostId',
                    'templatePreviewTerm',
                ];

                foreach ($reference_keys as $key) {
                    if (! \array_key_exists($key, $current_settings) || ! \array_key_exists($key, $incoming_settings)) {
                        continue;
                    }

                    if (self::values_equal($current_settings[$key], $incoming_settings[$key])) {
                        continue;
                    }

                    $settings[$key] = $current_settings[$key];
                    $notes[] = self::message(
                        'bricks_template_scope_preserved',
                        sprintf(__('Bricks template setting "%s" differs, so the local value was preserved.', 'dbvc'), $key),
                        'note'
                    );
                }

                $merged_meta['_bricks_template_settings'] = self::wrap_meta_like($incoming_meta['_bricks_template_settings'], $settings);
            }
        }

        $merged['meta'] = $merged_meta;

        return $merged;
    }

    /**
     * @param array<string,mixed> $merged
     * @param string              $local_uid
     * @param string              $incoming_uid
     * @param string              $policy
     * @param array<int,array<string,string>> $notes
     * @return void
     */
    private static function apply_uid_choice(array &$merged, $local_uid, $incoming_uid, $policy, array &$notes)
    {
        $chosen = $policy === 'use_incoming' ? trim((string) $incoming_uid) : trim((string) $local_uid);

        if ($chosen === '' && $incoming_uid !== '') {
            $chosen = trim((string) $incoming_uid);
            $notes[] = self::message(
                'incoming_uid_used_when_local_missing',
                __('The selected entity has no local UID, so DBVC used the incoming UID.', 'dbvc'),
                'note'
            );
        }

        if ($chosen === '') {
            return;
        }

        $merged['vf_object_uid'] = $chosen;
        if (isset($merged['dbvc_object_uid'])) {
            $merged['dbvc_object_uid'] = $chosen;
        }

        if (isset($merged['meta']) && \is_array($merged['meta']) && \array_key_exists('vf_object_uid', $merged['meta'])) {
            $merged['meta']['vf_object_uid'] = self::wrap_meta_like($merged['meta']['vf_object_uid'], $chosen);
        }
    }

    /**
     * @param array<string,mixed> $merged
     * @param array<string,mixed> $current_info
     * @param array<string,mixed> $incoming_info
     * @param array<string,string> $identity
     * @return array<string,string>|null
     */
    private static function find_slug_collision_blocker(array $merged, array $current_info, array $incoming_info, array $identity)
    {
        if ($identity['slug'] !== 'use_incoming' || $incoming_info['slug'] === '') {
            return null;
        }

        if ($current_info['kind'] === 'post') {
            if (! \function_exists('get_page_by_path')) {
                return null;
            }

            $post = \get_page_by_path((string) $incoming_info['slug'], OBJECT, (string) $current_info['subtype']);
            if ($post instanceof \WP_Post && (int) $post->ID !== (int) $current_info['id']) {
                return self::message(
                    'incoming_slug_collision',
                    __('Incoming slug already belongs to another local post of the same post type.', 'dbvc')
                );
            }
            return null;
        }

        if ($current_info['kind'] === 'term' && \function_exists('get_term_by')) {
            $term = \get_term_by('slug', (string) $incoming_info['slug'], (string) $current_info['subtype']);
            if ($term && ! \is_wp_error($term) && (int) $term->term_id !== (int) $current_info['id']) {
                return self::message(
                    'incoming_slug_collision',
                    __('Incoming slug already belongs to another local term in the same taxonomy.', 'dbvc')
                );
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $entity
     * @return array<string,mixed>
     */
    private static function inspect_entity(array $entity)
    {
        $kind = self::detect_kind($entity);
        $subtype = $kind === 'term'
            ? sanitize_key((string) ($entity['taxonomy'] ?? ''))
            : sanitize_key((string) ($entity['post_type'] ?? ''));

        $id = $kind === 'term'
            ? (int) ($entity['term_id'] ?? 0)
            : (int) ($entity['ID'] ?? 0);

        $slug = $kind === 'term'
            ? sanitize_title((string) ($entity['slug'] ?? ''))
            : sanitize_title((string) ($entity['post_name'] ?? ''));

        $title = $kind === 'term'
            ? (string) ($entity['name'] ?? $entity['term_name'] ?? '')
            : (string) ($entity['post_title'] ?? '');

        return [
            'kind'    => $kind,
            'subtype' => $subtype,
            'id'      => $id,
            'slug'    => $slug,
            'title'   => $title,
            'uid'     => self::extract_entity_uid($entity),
        ];
    }

    /**
     * @param array<string,mixed> $entity
     * @return string
     */
    private static function detect_kind(array $entity)
    {
        if (isset($entity['taxonomy']) && (isset($entity['slug']) || isset($entity['term_id']) || isset($entity['name']))) {
            return 'term';
        }

        if (isset($entity['post_type']) && (isset($entity['ID']) || isset($entity['post_name']) || isset($entity['post_title']))) {
            return 'post';
        }

        return '';
    }

    /**
     * @param array<string,mixed> $entity_info
     * @param array<string,mixed> $entity
     * @return array<string,mixed>
     */
    private static function find_local_match(array $entity_info, array $entity)
    {
        if ($entity_info['kind'] === 'post') {
            $post_id = (int) $entity_info['id'];
            if ($post_id > 0) {
                $post = \get_post($post_id);
                if ($post instanceof \WP_Post && (string) $post->post_type === (string) $entity_info['subtype']) {
                    return self::format_match('post', (string) $entity_info['subtype'], $post_id, 'id', (string) $post->post_title);
                }
            }

            if ($entity_info['uid'] !== '') {
                $post_id = self::lookup_post_id_by_uid((string) $entity_info['uid'], (string) $entity_info['subtype']);
                if ($post_id > 0) {
                    $post = \get_post($post_id);
                    return self::format_match('post', (string) $entity_info['subtype'], $post_id, 'uid', $post instanceof \WP_Post ? (string) $post->post_title : '');
                }
            }

            if ($entity_info['slug'] !== '' && \function_exists('get_page_by_path')) {
                $post = \get_page_by_path((string) $entity_info['slug'], OBJECT, (string) $entity_info['subtype']);
                if ($post instanceof \WP_Post) {
                    return self::format_match('post', (string) $entity_info['subtype'], (int) $post->ID, 'slug', (string) $post->post_title);
                }
            }
        }

        if ($entity_info['kind'] === 'term') {
            $term_id = (int) $entity_info['id'];
            if ($term_id > 0) {
                $term = \get_term($term_id, (string) $entity_info['subtype']);
                if ($term && ! \is_wp_error($term)) {
                    return self::format_match('term', (string) $entity_info['subtype'], $term_id, 'id', (string) $term->name);
                }
            }

            if ($entity_info['slug'] !== '' && \function_exists('get_term_by')) {
                $term = \get_term_by('slug', (string) $entity_info['slug'], (string) $entity_info['subtype']);
                if ($term && ! \is_wp_error($term)) {
                    return self::format_match('term', (string) $entity_info['subtype'], (int) $term->term_id, 'slug', (string) $term->name);
                }
            }
        }

        return [
            'status' => 'none',
            'id'     => 0,
        ];
    }

    /**
     * Use the matched WordPress entity as the selected local identity authority.
     *
     * The selected JSON file can drift after a direct save of source-site JSON. A
     * selected-entity merge should still treat the matched local post/term as
     * authoritative for local ID, UID, slug, and title defaults.
     *
     * @param array<string,mixed> $entity_info
     * @param array<string,mixed> $local_match
     * @param array<int,array<string,string>> $notes
     * @return array<string,mixed>
     */
    private static function apply_local_match_authority(array $entity_info, array $local_match, array &$notes)
    {
        $id = isset($local_match['id']) ? (int) $local_match['id'] : 0;
        if ($id <= 0) {
            return $entity_info;
        }

        if ((string) ($entity_info['kind'] ?? '') === 'post') {
            $post = \get_post($id);
            if (! $post instanceof \WP_Post) {
                return $entity_info;
            }

            $post_type = sanitize_key((string) ($entity_info['subtype'] ?? ''));
            if ($post_type !== '' && (string) $post->post_type !== $post_type) {
                return $entity_info;
            }

            $authority = $entity_info;
            $authority['id'] = (int) $post->ID;
            $authority['slug'] = sanitize_title((string) $post->post_name);
            $authority['title'] = (string) $post->post_title;

            $live_uid = trim((string) \get_post_meta((int) $post->ID, 'vf_object_uid', true));
            if ($live_uid !== '') {
                $authority['uid'] = $live_uid;
            }

            self::add_local_authority_notes($entity_info, $authority, $notes);
            return $authority;
        }

        if ((string) ($entity_info['kind'] ?? '') === 'term') {
            $taxonomy = sanitize_key((string) ($entity_info['subtype'] ?? ''));
            if ($taxonomy === '') {
                return $entity_info;
            }

            $term = \get_term($id, $taxonomy);
            if (! $term || \is_wp_error($term)) {
                return $entity_info;
            }

            $authority = $entity_info;
            $authority['id'] = (int) $term->term_id;
            $authority['slug'] = sanitize_title((string) $term->slug);
            $authority['title'] = (string) $term->name;

            $live_uid = trim((string) \get_term_meta((int) $term->term_id, 'vf_object_uid', true));
            if ($live_uid !== '') {
                $authority['uid'] = $live_uid;
            }

            self::add_local_authority_notes($entity_info, $authority, $notes);
            return $authority;
        }

        return $entity_info;
    }

    /**
     * @param array<string,mixed> $file_info
     * @param array<string,mixed> $authority_info
     * @param array<int,array<string,string>> $notes
     * @return void
     */
    private static function add_local_authority_notes(array $file_info, array $authority_info, array &$notes)
    {
        if ((int) ($file_info['id'] ?? 0) > 0 && (int) ($file_info['id'] ?? 0) !== (int) ($authority_info['id'] ?? 0)) {
            $notes[] = self::message(
                'selected_file_id_replaced_by_local_match',
                __('The selected JSON ID differs from the matched local entity ID, so the local entity ID will be used.', 'dbvc'),
                'note'
            );
        }

        $file_uid = trim((string) ($file_info['uid'] ?? ''));
        $authority_uid = trim((string) ($authority_info['uid'] ?? ''));
        if ($file_uid !== '' && $authority_uid !== '' && $file_uid !== $authority_uid) {
            $notes[] = self::message(
                'selected_file_uid_replaced_by_local_match',
                __('The selected JSON UID differs from the matched local entity UID, so the local UID will be used when UID policy is keep local.', 'dbvc'),
                'note'
            );
        }

        $file_slug = trim((string) ($file_info['slug'] ?? ''));
        $authority_slug = trim((string) ($authority_info['slug'] ?? ''));
        if ($file_slug !== '' && $authority_slug !== '' && $file_slug !== $authority_slug) {
            $notes[] = self::message(
                'selected_file_slug_replaced_by_local_match',
                __('The selected JSON slug differs from the matched local entity slug, so the local slug will be used when slug policy is keep local.', 'dbvc'),
                'note'
            );
        }
    }

    /**
     * @param string $kind
     * @param string $subtype
     * @param int    $id
     * @param string $source
     * @param string $label
     * @return array<string,mixed>
     */
    private static function format_match($kind, $subtype, $id, $source, $label = '')
    {
        $edit_url = '';
        if ($kind === 'post' && \function_exists('get_edit_post_link')) {
            $edit_url = (string) \get_edit_post_link((int) $id, '');
        } elseif ($kind === 'term' && \function_exists('get_edit_term_link')) {
            $edit_url = (string) \get_edit_term_link((int) $id, (string) $subtype);
        }

        return [
            'status'       => 'matched',
            'kind'         => $kind,
            'subtype'      => $subtype,
            'id'           => (int) $id,
            'match_source' => $source,
            'label'        => $label !== '' ? $label : sprintf(__('%s #%d', 'dbvc'), ucfirst($kind), (int) $id),
            'edit_url'     => $edit_url,
        ];
    }

    /**
     * @param string $uid
     * @param string $post_type
     * @return int
     */
    private static function lookup_post_id_by_uid($uid, $post_type = '')
    {
        global $wpdb;
        $uid = trim((string) $uid);
        if ($uid === '' || ! $wpdb || ! isset($wpdb->postmeta)) {
            return 0;
        }

        $sql = "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s";
        $params = ['vf_object_uid', $uid];
        if ($post_type !== '' && isset($wpdb->posts)) {
            $sql .= " AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = %s)";
            $params[] = sanitize_key((string) $post_type);
        }
        $sql .= ' LIMIT 1';

        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    /**
     * @param array<string,mixed> $entity
     * @return string
     */
    private static function extract_entity_uid(array $entity)
    {
        $uid = isset($entity['vf_object_uid']) ? (string) $entity['vf_object_uid'] : '';
        if ($uid === '' && isset($entity['dbvc_object_uid'])) {
            $uid = (string) $entity['dbvc_object_uid'];
        }
        if ($uid === '' && isset($entity['meta']) && \is_array($entity['meta']) && isset($entity['meta']['vf_object_uid'])) {
            $meta_uid = self::unwrap_single_meta_value($entity['meta']['vf_object_uid']);
            $uid = \is_string($meta_uid) ? $meta_uid : '';
        }

        foreach (['dbvc_post_history', 'dbvc_term_history'] as $history_key) {
            if ($uid !== '' || ! isset($entity['meta']) || ! \is_array($entity['meta']) || ! isset($entity['meta'][$history_key])) {
                continue;
            }

            $history = self::unwrap_single_meta_value($entity['meta'][$history_key]);
            if (\is_array($history) && isset($history[0]) && \is_array($history[0]) && isset($history[0]['vf_object_uid'])) {
                $uid = (string) $history[0]['vf_object_uid'];
            } elseif (\is_array($history) && isset($history['vf_object_uid'])) {
                $uid = (string) $history['vf_object_uid'];
            }
        }

        return trim($uid);
    }

    /**
     * @param array<string,mixed> $incoming
     * @param string              $taxonomy
     * @return array{resolved:bool,parent:int,parent_slug:string}
     */
    private static function resolve_incoming_term_parent(array $incoming, $taxonomy)
    {
        $taxonomy = sanitize_key((string) $taxonomy);
        $parent_slug = isset($incoming['parent_slug']) ? sanitize_title((string) $incoming['parent_slug']) : '';
        if ($parent_slug !== '' && \function_exists('get_term_by')) {
            $term = \get_term_by('slug', $parent_slug, $taxonomy);
            if ($term && ! \is_wp_error($term)) {
                return [
                    'resolved' => true,
                    'parent' => (int) $term->term_id,
                    'parent_slug' => $parent_slug,
                ];
            }
        }

        if (isset($incoming['parent']) && (int) $incoming['parent'] === 0) {
            return [
                'resolved' => true,
                'parent' => 0,
                'parent_slug' => '',
            ];
        }

        return [
            'resolved' => false,
            'parent' => 0,
            'parent_slug' => '',
        ];
    }

    /**
     * @param mixed  $local
     * @param mixed  $incoming
     * @param string $policy
     * @return string
     */
    private static function choose_identity_value($local, $incoming, $policy)
    {
        $incoming = is_scalar($incoming) ? (string) $incoming : '';
        $local = is_scalar($local) ? (string) $local : '';
        if ($policy === 'use_incoming' && $incoming !== '') {
            return $incoming;
        }

        return $local !== '' ? $local : $incoming;
    }

    /**
     * @param string $content
     * @return array<string,mixed>|\WP_Error
     */
    private static function decode_incoming_json($content)
    {
        $content = (string) $content;
        if (trim($content) === '') {
            return new \WP_Error(
                'dbvc_entity_editor_merge_empty_incoming_json',
                __('Paste incoming JSON before previewing a merge.', 'dbvc'),
                ['status' => 400]
            );
        }

        $decoded = json_decode($content, true);
        if (! \is_array($decoded)) {
            return new \WP_Error(
                'dbvc_entity_editor_merge_invalid_json',
                __('Incoming JSON payload is invalid.', 'dbvc'),
                ['status' => 422]
            );
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $payload
     * @return string|\WP_Error
     */
    private static function encode_payload(array $payload)
    {
        $normalized = \function_exists('dbvc_normalize_for_json') ? \dbvc_normalize_for_json($payload) : $payload;
        $encoded = \wp_json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! \is_string($encoded) || $encoded === '') {
            return new \WP_Error(
                'dbvc_entity_editor_merge_encode_failed',
                __('Unable to encode proposed merged JSON.', 'dbvc'),
                ['status' => 500]
            );
        }

        return $encoded . "\n";
    }

    /**
     * @param array<string,mixed> $identity
     * @return array<string,string>
     */
    private static function normalize_identity_choices(array $identity)
    {
        $uid = isset($identity['uid']) ? (string) $identity['uid'] : 'keep_local';
        $slug = isset($identity['slug']) ? (string) $identity['slug'] : 'keep_local';
        $title = isset($identity['title']) ? (string) $identity['title'] : 'use_incoming';

        return [
            'uid' => $uid === 'use_incoming' ? 'use_incoming' : 'keep_local',
            'slug' => $slug === 'use_incoming' ? 'use_incoming' : 'keep_local',
            'title' => $title === 'keep_local' ? 'keep_local' : 'use_incoming',
        ];
    }

    /**
     * @param string $mode
     * @return string
     */
    private static function normalize_mode($mode)
    {
        return (string) $mode === self::MODE_SAVE_AND_PARTIAL_IMPORT
            ? self::MODE_SAVE_AND_PARTIAL_IMPORT
            : self::MODE_SAVE;
    }

    /**
     * @param string $relative_path
     * @return string
     */
    private static function normalize_relative_path($relative_path)
    {
        return \str_replace('\\', '/', \ltrim((string) $relative_path, '/'));
    }

    /**
     * @return array<int,string>
     */
    private static function get_protected_post_meta_keys()
    {
        $keys = \apply_filters('dbvc_entity_editor_protected_post_meta_keys', self::PROTECTED_POST_META_KEYS);
        return \is_array($keys) ? \array_values(\array_unique(\array_map('strval', $keys))) : self::PROTECTED_POST_META_KEYS;
    }

    /**
     * @return array<int,string>
     */
    private static function get_protected_term_meta_keys()
    {
        $keys = \apply_filters('dbvc_entity_editor_protected_term_meta_keys', self::PROTECTED_TERM_META_KEYS);
        return \is_array($keys) ? \array_values(\array_unique(\array_map('strval', $keys))) : self::PROTECTED_TERM_META_KEYS;
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
     * @param mixed $original
     * @param mixed $value
     * @return mixed
     */
    private static function wrap_meta_like($original, $value)
    {
        if (\is_array($original) && self::is_list_array($original)) {
            return [$value];
        }

        return $value;
    }

    /**
     * @param mixed $left
     * @param mixed $right
     * @return bool
     */
    private static function values_equal($left, $right)
    {
        return \wp_json_encode(self::normalize_compare_value($left)) === \wp_json_encode(self::normalize_compare_value($right));
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalize_compare_value($value)
    {
        if (\is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $child) {
                $normalized[$key] = self::normalize_compare_value($child);
            }
            if (! self::is_list_array($normalized)) {
                \ksort($normalized);
            }
            return $normalized;
        }

        return $value;
    }

    /**
     * @param array<int|string,mixed> $value
     * @return bool
     */
    private static function is_list_array(array $value)
    {
        if (\function_exists('array_is_list')) {
            return \array_is_list($value);
        }

        return \array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @param array<string,mixed> $payload
     * @return bool
     */
    private static function contains_acf_field_reference(array $payload)
    {
        $stack = [$payload];
        while (! empty($stack)) {
            $value = \array_pop($stack);
            if (\is_string($value) && strpos($value, 'field_') === 0) {
                return true;
            }
            if (\is_array($value)) {
                foreach ($value as $child) {
                    $stack[] = $child;
                }
            }
        }

        return false;
    }

    /**
     * @param string              $relative_path
     * @param string              $current_content
     * @param string              $incoming_json
     * @param array<string,string> $identity
     * @param string              $proposed_json
     * @return string
     */
    private static function build_preview_hash($relative_path, $current_content, $incoming_json, array $identity, $proposed_json)
    {
        return \hash('sha256', \wp_json_encode([
            'path' => self::normalize_relative_path($relative_path),
            'current' => \hash('sha256', (string) $current_content),
            'incoming' => \hash('sha256', (string) $incoming_json),
            'identity' => $identity,
            'proposed' => \hash('sha256', (string) $proposed_json),
        ]));
    }

    /**
     * @param string $code
     * @param string $message
     * @param string $severity
     * @return array<string,string>
     */
    private static function message($code, $message, $severity = 'error')
    {
        return [
            'code'     => (string) $code,
            'message'  => (string) $message,
            'severity' => (string) $severity,
        ];
    }

    /**
     * @param array<int,array<string,string>> $messages
     * @return array<int,array<string,string>>
     */
    private static function dedupe_messages(array $messages)
    {
        $seen = [];
        $deduped = [];
        foreach ($messages as $message) {
            $key = (string) ($message['code'] ?? '') . '|' . (string) ($message['message'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $message;
        }

        return $deduped;
    }
}
