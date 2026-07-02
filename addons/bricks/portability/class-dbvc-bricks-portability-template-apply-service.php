<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Portability_Template_Apply_Service
{
    /**
     * @param array<string, mixed> $session
     * @param array<string, array<int, array<string, mixed>>> $affected_domains
     * @param array<string, string> $effective_decisions
     * @param array<string, string> $font_value_map
     * @param array<string, mixed>|null $media_state
     * @return array<string, mixed>|\WP_Error
     */
    public static function apply_affected_domains(array $session, array $affected_domains, array $effective_decisions, array $font_value_map = [], &$media_state = null)
    {
        $rows = isset($affected_domains['bricks_templates']) && is_array($affected_domains['bricks_templates'])
            ? $affected_domains['bricks_templates']
            : [];
        $state = self::empty_entity_state();
        if (empty($rows)) {
            return ['entity_state' => $state];
        }
        if (! is_array($media_state)) {
            $media_state = DBVC_Bricks_Portability_Media_Apply_Service::empty_media_state();
        }

        $extract_dir = wp_normalize_path((string) ($session['extract_dir'] ?? ''));
        $template_id_map = self::build_initial_template_id_map($rows);
        $reference_state = self::empty_reference_state();
        $pending = self::collect_selected_template_rows($rows, $effective_decisions);

        while (! empty($pending)) {
            $progress = false;
            $pending_source_ids = self::collect_pending_source_template_ids($pending);

            foreach ($pending as $pending_index => $row) {
                if (! is_array($row)) {
                    unset($pending[$pending_index]);
                    $progress = true;
                    continue;
                }

                $waiting = self::find_waiting_nested_template_dependencies($row, $template_id_map, $pending_source_ids);
                if (! empty($waiting)) {
                    continue;
                }

                $result = self::apply_template_row($row, $effective_decisions, $font_value_map, $extract_dir, $template_id_map, $state, $media_state, $reference_state);
                if (is_wp_error($result)) {
                    DBVC_Bricks_Portability_Backup_Service::restore_entity_state($state);
                    return $result;
                }

                unset($pending[$pending_index]);
                $progress = true;
            }

            if (! $progress) {
                DBVC_Bricks_Portability_Backup_Service::restore_entity_state($state);
                return new \WP_Error(
                    'dbvc_bricks_portability_template_dependency_unresolved',
                    __('One or more selected Bricks templates depend on nested templates that cannot be resolved without creating a cycle or selecting the missing dependency.', 'dbvc'),
                    ['status' => 409]
                );
            }
        }

        return [
            'entity_state' => $state,
            'reference_state' => $reference_state,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $effective_decisions
     * @param array<string, string> $font_value_map
     * @param string $extract_dir
     * @param array<string, int> $template_id_map
     * @param array<string, array<int, array<string, mixed>>> $state
     * @param array<string, mixed> $media_state
     * @param array<string, array<int, array<string, mixed>>> $reference_state
     * @return int|true|\WP_Error
     */
    private static function apply_template_row(array $row, array $effective_decisions, array $font_value_map, $extract_dir, array &$template_id_map, array &$state, array &$media_state, array &$reference_state)
    {
        if (($row['row_type'] ?? '') !== 'object') {
            return true;
        }

        $row_id = self::normalize_row_id($row['row_id'] ?? '');
        $decision = sanitize_key((string) ($effective_decisions[$row_id] ?? ''));
        if (! in_array($decision, ['add_incoming', 'replace_with_incoming'], true)) {
            return true;
        }
        if (empty($row['source']) || ! is_array($row['source']) || empty($row['source']['raw']) || ! is_array($row['source']['raw'])) {
            return true;
        }

        $source = (array) $row['source'];
        $source_raw = self::remap_custom_font_references((array) $source['raw'], $font_value_map);
        $source_raw = self::hydrate_template_media_references($source_raw, $source, $extract_dir, $media_state, $reference_state);
        if (is_wp_error($source_raw)) {
            return $source_raw;
        }
        $source_raw = self::remap_nested_template_references($source_raw, $source, $template_id_map, $reference_state);
        if (is_wp_error($source_raw)) {
            return $source_raw;
        }
        $source_raw = self::remap_post_or_term_references($source_raw, $source, $reference_state);

        $target_post_id = 0;
        if ($decision === 'replace_with_incoming' && ! empty($row['target']) && is_array($row['target']) && ! empty($row['target']['raw']) && is_array($row['target']['raw'])) {
            $target_post_id = (int) ($row['target']['raw']['post_id'] ?? 0);
        }

        $post_id = self::apply_template_object($source_raw, $decision, $target_post_id, $state);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $source_post_id = (int) ($source_raw['post_id'] ?? 0);
        if ($source_post_id > 0) {
            $template_id_map[(string) $source_post_id] = (int) $post_id;
        }

        return (int) $post_id;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $effective_decisions
     * @return array<int, array<string, mixed>>
     */
    private static function collect_selected_template_rows(array $rows, array $effective_decisions)
    {
        $selected = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['row_type'] ?? '') !== 'object') {
                continue;
            }
            $row_id = self::normalize_row_id($row['row_id'] ?? '');
            $decision = sanitize_key((string) ($effective_decisions[$row_id] ?? ''));
            if (! in_array($decision, ['add_incoming', 'replace_with_incoming'], true)) {
                continue;
            }
            if (empty($row['source']) || ! is_array($row['source']) || empty($row['source']['raw']) || ! is_array($row['source']['raw'])) {
                continue;
            }
            $selected[] = $row;
        }

        return $selected;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    private static function build_initial_template_id_map(array $rows)
    {
        $map = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['source']) || ! is_array($row['source']) || empty($row['source']['raw']) || ! is_array($row['source']['raw'])) {
                continue;
            }
            $source_post_id = (int) ($row['source']['raw']['post_id'] ?? 0);
            $target_post_id = ! empty($row['target']) && is_array($row['target']) && ! empty($row['target']['raw']) && is_array($row['target']['raw'])
                ? (int) ($row['target']['raw']['post_id'] ?? 0)
                : 0;
            if ($source_post_id > 0 && $target_post_id > 0) {
                $map[(string) $source_post_id] = $target_post_id;
            }
        }

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $pending
     * @return array<string, bool>
     */
    private static function collect_pending_source_template_ids(array $pending)
    {
        $ids = [];
        foreach ($pending as $row) {
            if (! is_array($row) || empty($row['source']) || ! is_array($row['source']) || empty($row['source']['raw']) || ! is_array($row['source']['raw'])) {
                continue;
            }
            $source_post_id = (int) ($row['source']['raw']['post_id'] ?? 0);
            if ($source_post_id > 0) {
                $ids[(string) $source_post_id] = true;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $template_id_map
     * @param array<string, bool> $pending_source_ids
     * @return array<int, int>
     */
    private static function find_waiting_nested_template_dependencies(array $row, array $template_id_map, array $pending_source_ids)
    {
        $waiting = [];
        foreach (self::get_dependency_refs((array) ($row['source'] ?? []), 'nested_template') as $ref) {
            $source_id = (int) ($ref['source_id'] ?? 0);
            if ($source_id <= 0 || isset($template_id_map[(string) $source_id])) {
                continue;
            }
            if (! empty($pending_source_ids[(string) $source_id])) {
                $waiting[] = $source_id;
            }
        }

        return array_values(array_unique($waiting));
    }

    /**
     * @param int $post_id
     * @return array<string, mixed>
     */
    public static function snapshot_template($post_id)
    {
        $post = get_post((int) $post_id);
        if (! $post instanceof \WP_Post || $post->post_type !== 'bricks_template') {
            return [];
        }

        return [
            'post_id' => (int) $post->ID,
            'post_title' => (string) $post->post_title,
            'post_name' => (string) $post->post_name,
            'post_status' => (string) $post->post_status,
            'post_excerpt' => (string) $post->post_excerpt,
            'menu_order' => (int) $post->menu_order,
            'template_type' => get_post_meta((int) $post->ID, '_bricks_template_type', true),
            'template_settings' => get_post_meta((int) $post->ID, '_bricks_template_settings', true),
            'areas' => self::get_template_area_meta((int) $post->ID),
            'taxonomies' => self::get_template_taxonomy_terms((int) $post->ID),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return true|\WP_Error
     */
    public static function restore_template_snapshot(array $snapshot)
    {
        $post_id = (int) ($snapshot['post_id'] ?? 0);
        if ($post_id <= 0) {
            return true;
        }

        $post_data = self::build_post_data($snapshot);
        $post_data['ID'] = $post_id;
        $result = wp_update_post(wp_slash($post_data), true);
        if (is_wp_error($result)) {
            return $result;
        }

        return self::write_template_payload($post_id, $snapshot);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function empty_entity_state()
    {
        return [
            'created_posts' => [],
            'updated_posts' => [],
            'created_terms' => [],
        ];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function empty_reference_state()
    {
        return [
            'media_refs' => [],
            'nested_template_refs' => [],
            'post_refs' => [],
            'term_refs' => [],
            'query_refs' => [],
            'link_refs' => [],
            'dynamic_data_refs' => [],
            'preserved_refs' => [],
            'blocked_refs' => [],
        ];
    }

    /**
     * @param array<string, mixed> $source_raw
     * @param string $decision
     * @param int $target_post_id
     * @param array<string, array<int, array<string, mixed>>> $state
     * @return int|\WP_Error
     */
    private static function apply_template_object(array $source_raw, $decision, $target_post_id, array &$state)
    {
        $decision = sanitize_key((string) $decision);
        if (! post_type_exists('bricks_template')) {
            return new \WP_Error('dbvc_bricks_portability_template_post_type_missing', __('The Bricks template post type is not registered on this site.', 'dbvc'), ['status' => 409]);
        }

        if ($decision === 'replace_with_incoming') {
            $target_post = get_post((int) $target_post_id);
            if (! $target_post instanceof \WP_Post || $target_post->post_type !== 'bricks_template') {
                return new \WP_Error('dbvc_bricks_portability_template_target_missing', __('The target Bricks template no longer exists.', 'dbvc'), ['status' => 409]);
            }
            $snapshot = self::snapshot_template((int) $target_post_id);
            if (! empty($snapshot)) {
                $state['updated_posts'][] = [
                    'post_id' => (int) $target_post_id,
                    'post_type' => 'bricks_template',
                    'source_post_id' => (int) ($source_raw['post_id'] ?? 0),
                    'before' => $snapshot,
                ];
            }
            $post_data = self::build_post_data($source_raw);
            $post_data['ID'] = (int) $target_post_id;
            $result = wp_update_post(wp_slash($post_data), true);
            if (is_wp_error($result)) {
                return $result;
            }
            $post_id = (int) $target_post_id;
        } else {
            $post_data = self::build_post_data($source_raw);
            $post_data['post_type'] = 'bricks_template';
            $requested_slug = sanitize_title((string) ($post_data['post_name'] ?? ''));
            if ($requested_slug !== '' && self::find_template_by_slug($requested_slug) > 0) {
                return new \WP_Error(
                    'dbvc_bricks_portability_template_slug_collision',
                    sprintf(__('A Bricks template with slug `%s` already exists. Refresh the review or replace the matched template instead of adding a duplicate.', 'dbvc'), $requested_slug),
                    ['status' => 409]
                );
            }
            $result = wp_insert_post(wp_slash($post_data), true);
            if (is_wp_error($result)) {
                return $result;
            }
            $post_id = (int) $result;
            $state['created_posts'][] = [
                'post_id' => $post_id,
                'post_type' => 'bricks_template',
                'source_post_id' => (int) ($source_raw['post_id'] ?? 0),
                'post_title' => sanitize_text_field((string) ($source_raw['post_title'] ?? '')),
            ];
            $created_post = get_post($post_id);
            if ($requested_slug !== '' && $created_post instanceof \WP_Post && (string) $created_post->post_name !== $requested_slug) {
                return new \WP_Error(
                    'dbvc_bricks_portability_template_slug_changed',
                    sprintf(__('WordPress changed imported Bricks template slug `%1$s` to `%2$s`; apply was stopped to avoid silent template reference drift.', 'dbvc'), $requested_slug, (string) $created_post->post_name),
                    ['status' => 409]
                );
            }
        }

        $write = self::write_template_payload($post_id, $source_raw, $state);
        if (is_wp_error($write)) {
            return $write;
        }

        clean_post_cache($post_id);
        return $post_id;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function build_post_data(array $raw)
    {
        $status = sanitize_key((string) ($raw['post_status'] ?? 'draft'));
        if ($status === '' || ! get_post_status_object($status)) {
            $status = 'draft';
        }

        return [
            'post_title' => sanitize_text_field((string) ($raw['post_title'] ?? '')),
            'post_name' => sanitize_title((string) ($raw['post_name'] ?? '')),
            'post_status' => $status,
            'post_excerpt' => sanitize_textarea_field((string) ($raw['post_excerpt'] ?? '')),
            'menu_order' => (int) ($raw['menu_order'] ?? 0),
            'post_type' => 'bricks_template',
        ];
    }

    /**
     * @param int $post_id
     * @param array<string, mixed> $raw
     * @param array<string, array<int, array<string, mixed>>>|null $state
     * @return true|\WP_Error
     */
    private static function write_template_payload($post_id, array $raw, &$state = null)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return new \WP_Error('dbvc_bricks_portability_template_post_invalid', __('The Bricks template post ID is invalid.', 'dbvc'), ['status' => 500]);
        }

        self::update_or_delete_post_meta($post_id, '_bricks_template_type', sanitize_key((string) ($raw['template_type'] ?? 'content')));
        self::update_or_delete_post_meta($post_id, '_bricks_template_settings', $raw['template_settings'] ?? null);

        $areas = isset($raw['areas']) && is_array($raw['areas']) ? $raw['areas'] : [];
        foreach (self::get_template_area_meta_keys() as $meta_key) {
            if (array_key_exists($meta_key, $areas)) {
                self::update_or_delete_post_meta($post_id, $meta_key, $areas[$meta_key]);
                continue;
            }
            delete_post_meta($post_id, $meta_key);
        }

        $terms = isset($raw['taxonomies']) && is_array($raw['taxonomies']) ? $raw['taxonomies'] : [];
        foreach (self::get_template_taxonomies() as $taxonomy) {
            $term_rows = isset($terms[$taxonomy]) && is_array($terms[$taxonomy]) ? $terms[$taxonomy] : [];
            $assigned = self::ensure_terms($taxonomy, $term_rows, $state);
            if (is_wp_error($assigned)) {
                return $assigned;
            }
            if (taxonomy_exists($taxonomy)) {
                $set = wp_set_object_terms($post_id, $assigned, $taxonomy, false);
                if (is_wp_error($set)) {
                    return $set;
                }
            }
        }

        return true;
    }

    /**
     * @param int $post_id
     * @param string $meta_key
     * @param mixed $value
     * @return void
     */
    private static function update_or_delete_post_meta($post_id, $meta_key, $value)
    {
        $meta_key = sanitize_key((string) $meta_key);
        if ($meta_key === '') {
            return;
        }

        if ($value === null || $value === '') {
            delete_post_meta((int) $post_id, $meta_key);
            return;
        }

        update_post_meta((int) $post_id, $meta_key, $value);
    }

    /**
     * @param string $taxonomy
     * @param array<int, mixed> $term_rows
     * @param array<string, array<int, array<string, mixed>>>|null $state
     * @return array<int, int>|\WP_Error
     */
    private static function ensure_terms($taxonomy, array $term_rows, &$state = null)
    {
        $taxonomy = sanitize_key((string) $taxonomy);
        if ($taxonomy === '') {
            return [];
        }
        if (! taxonomy_exists($taxonomy)) {
            if (empty($term_rows)) {
                return [];
            }
            return new \WP_Error('dbvc_bricks_portability_template_taxonomy_missing', sprintf(__('The Bricks template taxonomy `%s` is not registered on this site.', 'dbvc'), $taxonomy), ['status' => 409]);
        }

        $term_ids = [];
        foreach ($term_rows as $term_row) {
            if (! is_array($term_row)) {
                continue;
            }
            $slug = sanitize_title((string) ($term_row['slug'] ?? ''));
            $name = sanitize_text_field((string) ($term_row['name'] ?? $slug));
            if ($slug === '') {
                continue;
            }

            $exists = term_exists($slug, $taxonomy);
            if (is_array($exists) && ! empty($exists['term_id'])) {
                $term_ids[] = (int) $exists['term_id'];
                continue;
            }
            if (is_int($exists) && $exists > 0) {
                $term_ids[] = $exists;
                continue;
            }

            $created = wp_insert_term($name !== '' ? $name : $slug, $taxonomy, ['slug' => $slug]);
            if (is_wp_error($created)) {
                return $created;
            }
            $term_id = (int) ($created['term_id'] ?? 0);
            if ($term_id <= 0) {
                continue;
            }
            $term_ids[] = $term_id;
            if (is_array($state)) {
                $state['created_terms'][] = [
                    'term_id' => $term_id,
                    'taxonomy' => $taxonomy,
                    'slug' => $slug,
                ];
            }
        }

        return array_values(array_unique(array_filter(array_map('intval', $term_ids))));
    }

    /**
     * @param int $post_id
     * @return array<string, mixed>
     */
    private static function get_template_area_meta($post_id)
    {
        $areas = [];
        foreach (self::get_template_area_meta_keys() as $meta_key) {
            $value = get_post_meta((int) $post_id, $meta_key, true);
            if ($value === '' || $value === null) {
                continue;
            }
            if (is_array($value) && empty($value)) {
                continue;
            }
            $areas[$meta_key] = $value;
        }

        return $areas;
    }

    /**
     * @param int $post_id
     * @return array<string, array<int, array<string, string>>>
     */
    private static function get_template_taxonomy_terms($post_id)
    {
        $result = [];
        foreach (self::get_template_taxonomies() as $taxonomy) {
            if (! taxonomy_exists($taxonomy)) {
                $result[$taxonomy] = [];
                continue;
            }

            $terms = wp_get_object_terms((int) $post_id, $taxonomy, ['fields' => 'all']);
            if (is_wp_error($terms)) {
                $result[$taxonomy] = [];
                continue;
            }

            $items = [];
            foreach ($terms as $term) {
                if (! $term instanceof \WP_Term) {
                    continue;
                }
                $items[] = [
                    'slug' => sanitize_title((string) $term->slug),
                    'name' => sanitize_text_field((string) $term->name),
                ];
            }
            $result[$taxonomy] = $items;
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private static function get_template_area_meta_keys()
    {
        return ['_bricks_page_header_2', '_bricks_page_content_2', '_bricks_page_footer_2'];
    }

    /**
     * @return array<int, string>
     */
    private static function get_template_taxonomies()
    {
        return ['template_tag', 'template_bundle'];
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $source
     * @param string $extract_dir
     * @param array<string, mixed> $media_state
     * @param array<string, array<int, array<string, mixed>>> $reference_state
     * @return array<string, mixed>|\WP_Error
     */
    private static function hydrate_template_media_references(array $raw, array $source, $extract_dir, array &$media_state, array &$reference_state)
    {
        $media_refs = self::index_media_refs((array) ($source['media_refs'] ?? []));
        foreach (self::get_dependency_refs($source, 'media') as $ref) {
            $media_key = sanitize_text_field((string) ($ref['media_key'] ?? ''));
            if ($media_key === '' || empty($media_refs[$media_key]) || ! is_array($media_refs[$media_key])) {
                return new \WP_Error(
                    'dbvc_bricks_portability_template_media_missing',
                    __('A Bricks template media reference is missing from the imported package.', 'dbvc'),
                    ['status' => 400]
                );
            }

            $attachment_id = DBVC_Bricks_Portability_Media_Apply_Service::import_packaged_media_ref((array) $media_refs[$media_key], $extract_dir, $media_state);
            if (is_wp_error($attachment_id)) {
                return $attachment_id;
            }

            $source_attachment_id = (int) ($ref['source_id'] ?? $media_refs[$media_key]['source_attachment_id'] ?? 0);
            if ($source_attachment_id > 0) {
                $media_state['template_attachment_id_map'][(string) $source_attachment_id] = (int) $attachment_id;
            }
            self::record_reference_state_item($reference_state, 'media_refs', $ref, $source_attachment_id, (int) $attachment_id);

            $path = self::normalize_dependency_path((array) ($ref['path'] ?? []));
            if (! empty($path)) {
                self::set_payload_path_value($raw, $path, (int) $attachment_id);
            }

            $url_path = self::normalize_dependency_path((array) ($ref['url_path'] ?? []));
            if (! empty($url_path)) {
                $url = wp_get_attachment_url((int) $attachment_id);
                self::set_payload_path_value($raw, $url_path, is_string($url) ? $url : '');
            }
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $source
     * @param array<string, int> $template_id_map
     * @param array<string, array<int, array<string, mixed>>> $reference_state
     * @return array<string, mixed>|\WP_Error
     */
    private static function remap_nested_template_references(array $raw, array $source, array $template_id_map, array &$reference_state)
    {
        foreach (self::get_dependency_refs($source, 'nested_template') as $ref) {
            $source_id = (int) ($ref['source_id'] ?? 0);
            if ($source_id <= 0) {
                continue;
            }
            if (empty($template_id_map[(string) $source_id])) {
                self::record_reference_state_item($reference_state, 'blocked_refs', $ref, $source_id, 0);
                return new \WP_Error(
                    'dbvc_bricks_portability_template_nested_unresolved',
                    sprintf(__('A Bricks template references nested template ID `%d`, but no selected or matched target template could resolve it.', 'dbvc'), $source_id),
                    ['status' => 409]
                );
            }
            self::record_reference_state_item($reference_state, 'nested_template_refs', $ref, $source_id, (int) $template_id_map[(string) $source_id]);
            $path = self::normalize_dependency_path((array) ($ref['path'] ?? []));
            if (! empty($path)) {
                self::set_payload_path_value($raw, $path, (int) $template_id_map[(string) $source_id]);
            }
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $source
     * @param array<string, array<int, array<string, mixed>>> $reference_state
     * @return array<string, mixed>
     */
    private static function remap_post_or_term_references(array $raw, array $source, array &$reference_state)
    {
        foreach (self::get_dependency_refs($source, 'post_or_term') as $ref) {
            $entity_kind = sanitize_key((string) ($ref['entity_kind'] ?? ''));
            $target_id = $entity_kind === 'term'
                ? self::resolve_term_dependency_ref($ref)
                : self::resolve_post_dependency_ref($ref);
            if ($target_id <= 0) {
                self::record_reference_state_item($reference_state, 'preserved_refs', $ref, (int) ($ref['source_id'] ?? 0), 0);
                continue;
            }

            $path = self::normalize_dependency_path((array) ($ref['path'] ?? []));
            if (empty($path)) {
                continue;
            }

            if ($entity_kind === 'post' && self::dependency_ref_is_dynamic_token($ref)) {
                $current_value = self::get_payload_path_value($raw, $path);
                $value = self::localized_dynamic_post_token_value($ref, $current_value, $target_id);
                if ($value !== null) {
                    self::set_payload_path_value($raw, $path, $value);
                    self::record_post_or_term_reference_state($reference_state, $ref, $target_id);
                }
                continue;
            }

            $value = $entity_kind === 'term'
                ? self::localized_term_dependency_value($ref, $target_id)
                : self::localized_post_dependency_value($ref, $target_id);
            if ($value !== null) {
                self::set_payload_path_value($raw, $path, $value);
                self::record_post_or_term_reference_state($reference_state, $ref, $target_id);
            }
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $ref
     * @return int
     */
    private static function resolve_post_dependency_ref(array $ref)
    {
        $post_type = sanitize_key((string) ($ref['object_subtype'] ?? ''));
        $uid = trim((string) ($ref['entity_uid'] ?? ''));
        if ($uid !== '') {
            $by_uid = self::find_post_by_uid($uid, $post_type);
            if ($by_uid > 0) {
                return $by_uid;
            }
        }

        $slug = sanitize_title((string) ($ref['object_slug'] ?? ''));
        if ($slug !== '' && $post_type !== '') {
            $by_slug = self::find_post_by_slug($slug, $post_type);
            if ($by_slug > 0) {
                return $by_slug;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $ref
     * @return int
     */
    private static function resolve_term_dependency_ref(array $ref)
    {
        $taxonomy = sanitize_key((string) ($ref['object_subtype'] ?? ''));
        $uid = trim((string) ($ref['entity_uid'] ?? ''));
        if ($uid !== '' && class_exists('DBVC_Sync_Posts') && method_exists('DBVC_Sync_Posts', 'find_term_id_by_uid')) {
            $by_uid = DBVC_Sync_Posts::find_term_id_by_uid($uid, $taxonomy);
            if ($by_uid) {
                return (int) $by_uid;
            }
        }

        $slug = sanitize_title((string) ($ref['object_slug'] ?? ''));
        if ($slug !== '' && $taxonomy !== '' && taxonomy_exists($taxonomy)) {
            $term = get_term_by('slug', $slug, $taxonomy);
            if ($term instanceof \WP_Term) {
                return (int) $term->term_id;
            }
        }

        return 0;
    }

    private static function localized_post_dependency_value(array $ref, $target_id)
    {
        $value_type = sanitize_key((string) ($ref['source_value_type'] ?? 'integer'));
        return $value_type === 'string' ? (string) (int) $target_id : (int) $target_id;
    }

    private static function dependency_ref_is_dynamic_token(array $ref)
    {
        return sanitize_key((string) ($ref['dynamic_ref_kind'] ?? '')) === 'dynamic_data_token'
            && (string) ($ref['dynamic_token_original'] ?? '') !== '';
    }

    private static function localized_dynamic_post_token_value(array $ref, $current_value, $target_id)
    {
        if (! is_string($current_value)) {
            return null;
        }

        $original_token = (string) ($ref['dynamic_token_original'] ?? '');
        if ($original_token === '' || strpos($current_value, $original_token) === false) {
            return null;
        }

        $token_name = sanitize_key((string) ($ref['dynamic_token_name'] ?? ''));
        $prefix = (string) ($ref['dynamic_token_prefix'] ?? '');
        $suffix = (string) ($ref['dynamic_token_suffix'] ?? '');
        if ($token_name === '') {
            return null;
        }
        if ($prefix === '') {
            $prefix = '{' . $token_name . ':';
        }
        if ($suffix === '') {
            $suffix = '}';
        }

        $target_token = $prefix . (int) $target_id . $suffix;
        return str_replace($original_token, $target_token, $current_value);
    }

    private static function record_post_or_term_reference_state(array &$reference_state, array $ref, $target_id)
    {
        $entity_kind = sanitize_key((string) ($ref['entity_kind'] ?? ''));
        $bucket = $entity_kind === 'term' ? 'term_refs' : 'post_refs';
        self::record_reference_state_item($reference_state, $bucket, $ref, (int) ($ref['source_id'] ?? 0), (int) $target_id);

        if (sanitize_key((string) ($ref['query_ref_kind'] ?? '')) !== '') {
            self::record_reference_state_item($reference_state, 'query_refs', $ref, (int) ($ref['source_id'] ?? 0), (int) $target_id);
        }
        if (sanitize_key((string) ($ref['link_ref_kind'] ?? '')) !== '') {
            self::record_reference_state_item($reference_state, 'link_refs', $ref, (int) ($ref['source_id'] ?? 0), (int) $target_id);
        }
        if (sanitize_key((string) ($ref['dynamic_ref_kind'] ?? '')) !== '') {
            self::record_reference_state_item($reference_state, 'dynamic_data_refs', $ref, (int) ($ref['source_id'] ?? 0), (int) $target_id);
        }
    }

    private static function record_reference_state_item(array &$reference_state, $bucket, array $ref, $source_id, $target_id)
    {
        $bucket = sanitize_key((string) $bucket);
        if ($bucket === '') {
            return;
        }
        if (! isset($reference_state[$bucket]) || ! is_array($reference_state[$bucket])) {
            $reference_state[$bucket] = [];
        }

        $item = [
            'source_id' => (int) $source_id,
            'target_id' => (int) $target_id,
            'payload_path' => sanitize_text_field((string) ($ref['payload_path'] ?? '')),
            'control_name' => sanitize_key((string) ($ref['control_name'] ?? '')),
            'ref_type' => sanitize_key((string) ($ref['ref_type'] ?? '')),
            'entity_kind' => sanitize_key((string) ($ref['entity_kind'] ?? '')),
            'object_subtype' => sanitize_key((string) ($ref['object_subtype'] ?? '')),
            'query_ref_kind' => sanitize_key((string) ($ref['query_ref_kind'] ?? '')),
            'link_ref_kind' => sanitize_key((string) ($ref['link_ref_kind'] ?? '')),
            'dynamic_ref_kind' => sanitize_key((string) ($ref['dynamic_ref_kind'] ?? '')),
            'dynamic_token_name' => sanitize_key((string) ($ref['dynamic_token_name'] ?? '')),
        ];

        $fingerprint = DBVC_Bricks_Portability_Utils::fingerprint($item);
        foreach ($reference_state[$bucket] as $existing) {
            if (is_array($existing) && DBVC_Bricks_Portability_Utils::fingerprint($existing) === $fingerprint) {
                return;
            }
        }

        $reference_state[$bucket][] = $item;
    }

    private static function localized_term_dependency_value(array $ref, $target_id)
    {
        $taxonomy = sanitize_key((string) ($ref['object_subtype'] ?? ''));
        if ($taxonomy === '') {
            return null;
        }

        return $taxonomy . '::' . (int) $target_id;
    }

    private static function find_post_by_uid($uid, $post_type = '')
    {
        $uid = trim((string) $uid);
        $post_type = sanitize_key((string) $post_type);
        if ($uid === '') {
            return 0;
        }

        if (class_exists('DBVC_Database')) {
            $record = DBVC_Database::get_entity_by_uid($uid);
            if ($record && ! empty($record->object_id)) {
                $candidate = get_post((int) $record->object_id);
                if ($candidate instanceof \WP_Post && ($post_type === '' || $candidate->post_type === $post_type)) {
                    return (int) $candidate->ID;
                }
            }
        }

        $found = get_posts([
            'post_type' => $post_type !== '' ? [$post_type] : 'any',
            'post_status' => 'any',
            'meta_key' => 'vf_object_uid',
            'meta_value' => $uid,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => true,
        ]);

        return ! empty($found[0]) ? (int) $found[0] : 0;
    }

    private static function find_post_by_slug($slug, $post_type)
    {
        $slug = sanitize_title((string) $slug);
        $post_type = sanitize_key((string) $post_type);
        if ($slug === '' || $post_type === '') {
            return 0;
        }

        $candidate = get_page_by_path($slug, OBJECT, $post_type);
        if ($candidate instanceof \WP_Post) {
            return (int) $candidate->ID;
        }

        $found = get_posts([
            'post_type' => [$post_type],
            'name' => $slug,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => true,
        ]);

        return ! empty($found[0]) ? (int) $found[0] : 0;
    }

    /**
     * @param array<string, mixed> $source
     * @param string $ref_type
     * @return array<int, array<string, mixed>>
     */
    private static function get_dependency_refs(array $source, $ref_type)
    {
        $ref_type = sanitize_key((string) $ref_type);
        $refs = [];
        foreach ((array) ($source['dependency_refs'] ?? []) as $ref) {
            if (! is_array($ref) || sanitize_key((string) ($ref['ref_type'] ?? '')) !== $ref_type) {
                continue;
            }
            $refs[] = $ref;
        }

        return $refs;
    }

    /**
     * @param array<int, mixed> $refs
     * @return array<string, array<string, mixed>>
     */
    private static function index_media_refs(array $refs)
    {
        $indexed = [];
        foreach ($refs as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $media_key = sanitize_text_field((string) ($ref['media_key'] ?? ''));
            if ($media_key !== '') {
                $indexed[$media_key] = $ref;
            }
        }

        return $indexed;
    }

    /**
     * @param array<int, mixed> $path
     * @return array<int, string|int>
     */
    private static function normalize_dependency_path(array $path)
    {
        $normalized = [];
        foreach ($path as $segment) {
            if (is_int($segment) || (is_string($segment) && preg_match('/^\d+$/', $segment))) {
                $normalized[] = (int) $segment;
                continue;
            }
            $segment = (string) $segment;
            if ($segment === '') {
                return [];
            }
            $normalized[] = $segment;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string|int> $path
     * @param mixed $value
     * @return void
     */
    private static function set_payload_path_value(array &$payload, array $path, $value)
    {
        $cursor =& $payload;
        $last_index = count($path) - 1;
        foreach ($path as $index => $segment) {
            if ($index === $last_index) {
                $cursor[$segment] = $value;
                return;
            }
            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                return;
            }
            $cursor =& $cursor[$segment];
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string|int> $path
     * @return mixed|null
     */
    private static function get_payload_path_value(array $payload, array $path)
    {
        $cursor = $payload;
        foreach ($path as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @param string $slug
     * @return int
     */
    private static function find_template_by_slug($slug)
    {
        $slug = sanitize_title((string) $slug);
        if ($slug === '') {
            return 0;
        }

        $posts = get_posts([
            'post_type' => 'bricks_template',
            'post_status' => 'any',
            'name' => $slug,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        return ! empty($posts[0]) ? (int) $posts[0] : 0;
    }

    /**
     * @param mixed $value
     * @param array<string, string> $font_value_map
     * @return mixed
     */
    private static function remap_custom_font_references($value, array $font_value_map)
    {
        if (empty($font_value_map)) {
            return $value;
        }

        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = self::remap_custom_font_references($child, $font_value_map);
            }
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return $value;
        }

        return preg_replace_callback('/custom_font_\d+/', static function ($matches) use ($font_value_map) {
            $token = (string) ($matches[0] ?? '');
            return isset($font_value_map[$token]) ? (string) $font_value_map[$token] : $token;
        }, $value);
    }

    /**
     * @param mixed $row_id
     * @return string
     */
    private static function normalize_row_id($row_id)
    {
        return trim(sanitize_text_field((string) $row_id));
    }
}
