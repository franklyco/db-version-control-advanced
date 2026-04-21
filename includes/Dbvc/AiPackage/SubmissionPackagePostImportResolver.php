<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class SubmissionPackagePostImportResolver
{
    /**
     * @param string              $intake_id
     * @param array<string,mixed> $report
     * @return array<string,mixed>|\WP_Error
     */
    public static function resolve(string $intake_id, array $report)
    {
        $entities = isset($report['entities']) && is_array($report['entities']) ? $report['entities'] : [];
        if (empty($entities)) {
            return [
                'processed' => 0,
                'applied' => 0,
                'warnings' => 0,
                'errors' => 0,
                'families' => self::build_empty_family_counts(),
                'details' => [],
            ];
        }

        $resolved_entities = self::build_resolved_entities($intake_id, $entities);
        $indexes = self::build_indexes($resolved_entities);
        $result = [
            'processed' => 0,
            'applied' => 0,
            'warnings' => 0,
            'errors' => 0,
            'families' => self::build_empty_family_counts(),
            'details' => [],
        ];

        self::resolve_term_parents($resolved_entities, $indexes, $result);
        self::resolve_post_parents($resolved_entities, $indexes, $result);
        self::reapply_post_taxonomies($resolved_entities, $indexes, $result);
        self::resolve_deferred_acf_meta($resolved_entities, $indexes, $result);

        return $result;
    }

    /**
     * @param string                    $intake_id
     * @param array<int,array<string,mixed>> $entities
     * @return array<int,array<string,mixed>>
     */
    private static function build_resolved_entities(string $intake_id, array $entities): array
    {
        $resolved = [];

        foreach ($entities as $entity) {
            if (! is_array($entity) || ($entity['status'] ?? '') !== 'translated') {
                continue;
            }

            $translated_path = isset($entity['translated_path']) ? (string) $entity['translated_path'] : '';
            if ($translated_path === '') {
                continue;
            }

            $absolute_path = Storage::resolve_intake_artifact_path($intake_id, $translated_path);
            if (\is_wp_error($absolute_path) || ! is_file($absolute_path) || ! is_readable($absolute_path)) {
                continue;
            }

            $raw = file_get_contents($absolute_path);
            $payload = is_string($raw) ? json_decode($raw, true) : null;
            if (! is_array($payload)) {
                continue;
            }

            $entity_kind = isset($entity['entity_kind']) ? (string) $entity['entity_kind'] : '';
            $object_key = isset($entity['object_key']) ? sanitize_key((string) $entity['object_key']) : '';
            $slug = isset($entity['slug']) ? sanitize_title((string) $entity['slug']) : '';

            $local_id = 0;
            $resolved_uid = '';

            if ($entity_kind === 'term') {
                $local_id = isset($payload['term_id']) ? absint($payload['term_id']) : 0;
                if ($local_id <= 0 && $object_key !== '' && $slug !== '' && taxonomy_exists($object_key)) {
                    $term = get_term_by('slug', $slug, $object_key);
                    if ($term && ! is_wp_error($term)) {
                        $local_id = (int) $term->term_id;
                    }
                }
                if ($local_id > 0) {
                    $resolved_uid = (string) get_term_meta($local_id, 'vf_object_uid', true);
                    $payload['term_id'] = $local_id;
                    if ($resolved_uid !== '') {
                        $payload['vf_object_uid'] = $resolved_uid;
                    }
                }
            } else {
                $local_id = isset($payload['ID']) ? absint($payload['ID']) : 0;
                if ($local_id <= 0 && $object_key !== '' && $slug !== '') {
                    $post = PostLookupService::find_post_by_slug($slug, $object_key);
                    if ($post instanceof \WP_Post) {
                        $local_id = (int) $post->ID;
                    }
                }
                if ($local_id > 0) {
                    $resolved_uid = (string) get_post_meta($local_id, 'vf_object_uid', true);
                    $payload['ID'] = $local_id;
                    if ($resolved_uid !== '') {
                        $payload['vf_object_uid'] = $resolved_uid;
                    }
                }
            }

            $entity['absolute_path'] = $absolute_path;
            $entity['payload'] = $payload;
            $entity['local_id'] = $local_id;
            $entity['resolved_uid'] = $resolved_uid;
            $resolved[] = $entity;
        }

        return $resolved;
    }

    /**
     * @param array<int,array<string,mixed>> $entities
     * @return array<string,mixed>
     */
    private static function build_indexes(array $entities): array
    {
        $indexes = [
            'posts_by_slug' => [],
            'posts_by_uid' => [],
            'terms_by_slug' => [],
            'terms_by_uid' => [],
        ];

        foreach ($entities as $entity) {
            if (! is_array($entity)) {
                continue;
            }

            $kind = isset($entity['entity_kind']) ? (string) $entity['entity_kind'] : '';
            $object_key = isset($entity['object_key']) ? sanitize_key((string) $entity['object_key']) : '';
            $slug = isset($entity['slug']) ? sanitize_title((string) $entity['slug']) : '';
            $local_id = isset($entity['local_id']) ? absint($entity['local_id']) : 0;
            $uid = isset($entity['resolved_uid']) ? trim((string) $entity['resolved_uid']) : '';

            if ($local_id <= 0 || $object_key === '' || $slug === '') {
                continue;
            }

            if ($kind === 'term') {
                $indexes['terms_by_slug'][$object_key . '::' . $slug] = $local_id;
                if ($uid !== '') {
                    $indexes['terms_by_uid'][$uid] = $local_id;
                }
            } else {
                $indexes['posts_by_slug'][$object_key . '::' . $slug] = $local_id;
                if ($uid !== '') {
                    $indexes['posts_by_uid'][$uid] = $local_id;
                }
            }
        }

        return $indexes;
    }

    /**
     * @param array<int,array<string,mixed>> $entities
     * @param array<string,mixed>            $indexes
     * @param array<string,mixed>            $result
     * @return void
     */
    private static function resolve_term_parents(array $entities, array $indexes, array &$result): void
    {
        foreach ($entities as $entity) {
            if (! is_array($entity) || ($entity['entity_kind'] ?? '') !== 'term') {
                continue;
            }

            $payload = isset($entity['payload']) && is_array($entity['payload']) ? $entity['payload'] : [];
            $child_id = isset($entity['local_id']) ? absint($entity['local_id']) : 0;
            $taxonomy = isset($entity['object_key']) ? sanitize_key((string) $entity['object_key']) : '';
            $parent_slug = isset($payload['parent_slug']) ? sanitize_title((string) $payload['parent_slug']) : '';

            if ($child_id <= 0 || $taxonomy === '' || $parent_slug === '') {
                continue;
            }

            self::increment_family($result, 'term_parent', 'processed');
            $result['processed']++;

            $parent_id = self::resolve_term_by_slug($taxonomy, $parent_slug, $indexes);
            if ($parent_id <= 0 || $parent_id === $child_id) {
                self::record_detail(
                    $result,
                    'term_parent',
                    'warning',
                    __('Term parent could not be resolved after import.', 'dbvc'),
                    $entity,
                    [
                        'parent_slug' => $parent_slug,
                        'term_id' => $child_id,
                    ]
                );
                continue;
            }

            $update = wp_update_term($child_id, $taxonomy, ['parent' => $parent_id]);
            if (is_wp_error($update)) {
                self::record_detail(
                    $result,
                    'term_parent',
                    'error',
                    $update->get_error_message(),
                    $entity,
                    [
                        'parent_slug' => $parent_slug,
                        'term_id' => $child_id,
                        'parent_id' => $parent_id,
                    ]
                );
                continue;
            }

            $payload['parent'] = $parent_id;
            self::write_entity_payload($entity, $payload);

            self::record_detail(
                $result,
                'term_parent',
                'applied',
                __('Applied deferred term parent link.', 'dbvc'),
                $entity,
                [
                    'parent_slug' => $parent_slug,
                    'term_id' => $child_id,
                    'parent_id' => $parent_id,
                ]
            );
        }
    }

    /**
     * @param array<int,array<string,mixed>> $entities
     * @param array<string,mixed>            $indexes
     * @param array<string,mixed>            $result
     * @return void
     */
    private static function resolve_post_parents(array $entities, array $indexes, array &$result): void
    {
        foreach ($entities as $entity) {
            if (! is_array($entity) || ($entity['entity_kind'] ?? '') !== 'post') {
                continue;
            }

            $child_id = isset($entity['local_id']) ? absint($entity['local_id']) : 0;
            $post_type = isset($entity['object_key']) ? sanitize_key((string) $entity['object_key']) : '';
            $payload = isset($entity['payload']) && is_array($entity['payload']) ? $entity['payload'] : [];
            $deferred = isset($entity['deferred_relationships']['post_parent']) && is_array($entity['deferred_relationships']['post_parent'])
                ? $entity['deferred_relationships']['post_parent']
                : [];

            if ($child_id <= 0 || $post_type === '' || empty($deferred)) {
                continue;
            }

            self::increment_family($result, 'post_parent', 'processed');
            $result['processed']++;

            if (! is_post_type_hierarchical($post_type)) {
                self::record_detail(
                    $result,
                    'post_parent',
                    'warning',
                    __('Deferred post parent could not be applied because the post type is not hierarchical.', 'dbvc'),
                    $entity,
                    ['post_id' => $child_id]
                );
                continue;
            }

            $parent_id = self::resolve_post_descriptor($deferred, $indexes);
            if ($parent_id <= 0 || $parent_id === $child_id) {
                self::record_detail(
                    $result,
                    'post_parent',
                    'warning',
                    __('Deferred post parent could not be resolved after import.', 'dbvc'),
                    $entity,
                    ['post_id' => $child_id]
                );
                continue;
            }

            $update = wp_update_post([
                'ID' => $child_id,
                'post_parent' => $parent_id,
            ], true);
            if (is_wp_error($update)) {
                self::record_detail(
                    $result,
                    'post_parent',
                    'error',
                    $update->get_error_message(),
                    $entity,
                    [
                        'post_id' => $child_id,
                        'parent_id' => $parent_id,
                    ]
                );
                continue;
            }

            $payload['ID'] = $child_id;
            $payload['post_parent'] = $parent_id;
            self::write_entity_payload($entity, $payload);

            self::record_detail(
                $result,
                'post_parent',
                'applied',
                __('Applied deferred post parent link.', 'dbvc'),
                $entity,
                [
                    'post_id' => $child_id,
                    'parent_id' => $parent_id,
                ]
            );
        }
    }

    /**
     * @param array<int,array<string,mixed>> $entities
     * @param array<string,mixed>            $indexes
     * @param array<string,mixed>            $result
     * @return void
     */
    private static function reapply_post_taxonomies(array $entities, array $indexes, array &$result): void
    {
        if (! class_exists('\DBVC_Sync_Posts')) {
            return;
        }

        $create_terms = (bool) get_option('dbvc_auto_create_terms', true);

        foreach ($entities as $entity) {
            if (! is_array($entity) || ($entity['entity_kind'] ?? '') !== 'post') {
                continue;
            }

            $child_id = isset($entity['local_id']) ? absint($entity['local_id']) : 0;
            $post_type = isset($entity['object_key']) ? sanitize_key((string) $entity['object_key']) : '';
            $payload = isset($entity['payload']) && is_array($entity['payload']) ? $entity['payload'] : [];
            $tax_input = isset($payload['tax_input']) && is_array($payload['tax_input']) ? $payload['tax_input'] : [];

            if ($child_id <= 0 || $post_type === '' || empty($tax_input)) {
                continue;
            }

            self::increment_family($result, 'post_tax_input', 'processed');
            $result['processed']++;

            $missing_refs = self::collect_missing_taxonomy_refs($tax_input, $indexes);
            \DBVC_Sync_Posts::import_tax_input_for_post($child_id, $post_type, $tax_input, $create_terms);

            if (! empty($missing_refs) && ! $create_terms) {
                self::record_detail(
                    $result,
                    'post_tax_input',
                    'warning',
                    __('Post taxonomy assignments were replayed, but some referenced terms still do not exist locally.', 'dbvc'),
                    $entity,
                    [
                        'post_id' => $child_id,
                        'missing_refs' => $missing_refs,
                    ]
                );
                continue;
            }

            self::record_detail(
                $result,
                'post_tax_input',
                'applied',
                __('Reapplied post taxonomy assignments after term import.', 'dbvc'),
                $entity,
                [
                    'post_id' => $child_id,
                    'taxonomies' => array_keys($tax_input),
                ]
            );
        }
    }

    /**
     * @param array<int,array<string,mixed>> $entities
     * @param array<string,mixed>            $indexes
     * @param array<string,mixed>            $result
     * @return void
     */
    private static function resolve_deferred_acf_meta(array $entities, array $indexes, array &$result): void
    {
        foreach ($entities as $entity) {
            if (! is_array($entity)) {
                continue;
            }

            $local_id = isset($entity['local_id']) ? absint($entity['local_id']) : 0;
            $entity_kind = isset($entity['entity_kind']) ? (string) $entity['entity_kind'] : '';
            $payload = isset($entity['payload']) && is_array($entity['payload']) ? $entity['payload'] : [];
            $deferred_fields = isset($entity['deferred_relationships']['acf_meta']) && is_array($entity['deferred_relationships']['acf_meta'])
                ? $entity['deferred_relationships']['acf_meta']
                : [];

            if ($local_id <= 0 || empty($deferred_fields)) {
                continue;
            }

            $meta = isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : [];

            foreach ($deferred_fields as $descriptor) {
                if (! is_array($descriptor)) {
                    continue;
                }

                self::increment_family($result, 'acf_meta', 'processed');
                $result['processed']++;

                $field_name = isset($descriptor['field_name']) ? sanitize_key((string) ($descriptor['field_name'] ?? '')) : '';
                $field_key = isset($descriptor['field_key']) ? (string) ($descriptor['field_key'] ?? '') : '';

                if ($field_name === '') {
                    self::record_detail(
                        $result,
                        'acf_meta',
                        'error',
                        __('Deferred ACF relationship data is missing its field name.', 'dbvc'),
                        $entity
                    );
                    continue;
                }

                $resolved = self::resolve_deferred_acf_descriptor($descriptor, $indexes);
                $values = isset($resolved['values']) && is_array($resolved['values']) ? array_values($resolved['values']) : [];
                $missing = isset($resolved['missing']) && is_array($resolved['missing']) ? array_values($resolved['missing']) : [];

                if (empty($values)) {
                    self::delete_entity_meta($entity_kind, $local_id, $field_name, $field_key);
                    unset($meta[$field_name], $meta['_' . $field_name]);
                    $payload['meta'] = $meta;
                    self::write_entity_payload($entity, $payload);

                    self::record_detail(
                        $result,
                        'acf_meta',
                        'warning',
                        __('Deferred ACF relationship values could not be resolved after import.', 'dbvc'),
                        $entity,
                        [
                            'field_name' => $field_name,
                            'missing_refs' => $missing,
                        ]
                    );
                    continue;
                }

                $stored_value = ! empty($descriptor['multiple']) ? $values : reset($values);
                self::update_entity_meta($entity_kind, $local_id, $field_name, $stored_value, $field_key);
                if (
                    $entity_kind === 'post'
                    && (($descriptor['field_type'] ?? '') === 'taxonomy')
                    && ! empty($descriptor['save_terms'])
                ) {
                    $taxonomy = self::resolve_primary_taxonomy_for_acf_descriptor($descriptor);
                    if ($taxonomy !== '') {
                        wp_set_object_terms($local_id, $values, $taxonomy, false);
                    }
                }
                $meta[$field_name] = $stored_value;
                if ($field_key !== '') {
                    $meta['_' . $field_name] = $field_key;
                }
                $payload['meta'] = $meta;
                self::write_entity_payload($entity, $payload);

                self::record_detail(
                    $result,
                    'acf_meta',
                    empty($missing) ? 'applied' : 'applied_with_warnings',
                    empty($missing)
                        ? __('Applied deferred ACF relationship values after import.', 'dbvc')
                        : __('Applied deferred ACF relationship values after import, but some references could not be resolved.', 'dbvc'),
                    $entity,
                    [
                        'field_name' => $field_name,
                        'resolved_count' => count($values),
                        'missing_refs' => $missing,
                    ]
                );
            }
        }
    }

    /**
     * @param string              $taxonomy
     * @param string              $slug
     * @param array<string,mixed> $indexes
     * @return int
     */
    private static function resolve_term_by_slug(string $taxonomy, string $slug, array $indexes): int
    {
        $key = $taxonomy . '::' . sanitize_title($slug);
        if (isset($indexes['terms_by_slug'][$key])) {
            return (int) $indexes['terms_by_slug'][$key];
        }

        if (! taxonomy_exists($taxonomy)) {
            return 0;
        }

        $term = get_term_by('slug', sanitize_title($slug), $taxonomy);
        if ($term && ! is_wp_error($term)) {
            return (int) $term->term_id;
        }

        return 0;
    }

    /**
     * @param array<string,mixed> $descriptor
     * @param array<string,mixed> $indexes
     * @return int
     */
    private static function resolve_term_descriptor(array $descriptor, array $indexes): int
    {
        $uid = isset($descriptor['vf_object_uid']) ? trim((string) ($descriptor['vf_object_uid'] ?? '')) : '';
        if ($uid !== '' && isset($indexes['terms_by_uid'][$uid])) {
            return (int) $indexes['terms_by_uid'][$uid];
        }

        $taxonomy = isset($descriptor['taxonomy']) ? sanitize_key((string) ($descriptor['taxonomy'] ?? '')) : '';
        $slug = isset($descriptor['slug']) ? sanitize_title((string) ($descriptor['slug'] ?? '')) : '';
        if ($taxonomy !== '' && $slug !== '') {
            return self::resolve_term_by_slug($taxonomy, $slug, $indexes);
        }

        $term_id = isset($descriptor['term_id']) ? absint($descriptor['term_id']) : 0;
        if ($term_id > 0) {
            $term = $taxonomy !== '' ? get_term($term_id, $taxonomy) : get_term($term_id);
            if ($term && ! is_wp_error($term)) {
                return (int) $term->term_id;
            }
        }

        return 0;
    }

    /**
     * @param array<string,mixed> $descriptor
     * @param array<string,mixed> $indexes
     * @return int
     */
    private static function resolve_post_descriptor(array $descriptor, array $indexes): int
    {
        $uid = isset($descriptor['vf_object_uid']) ? trim((string) $descriptor['vf_object_uid']) : '';
        if ($uid !== '' && isset($indexes['posts_by_uid'][$uid])) {
            return (int) $indexes['posts_by_uid'][$uid];
        }

        $slug = isset($descriptor['slug']) ? sanitize_title((string) $descriptor['slug']) : '';
        $post_type = isset($descriptor['post_type']) ? sanitize_key((string) $descriptor['post_type']) : '';
        if ($slug !== '' && $post_type !== '') {
            $key = $post_type . '::' . $slug;
            if (isset($indexes['posts_by_slug'][$key])) {
                return (int) $indexes['posts_by_slug'][$key];
            }

            $post = PostLookupService::find_post_by_slug($slug, $post_type);
            if ($post instanceof \WP_Post) {
                return (int) $post->ID;
            }
        }

        $post_id = isset($descriptor['ID']) ? absint($descriptor['ID']) : 0;
        if ($post_id > 0) {
            $post = get_post($post_id);
            if ($post instanceof \WP_Post && ($post_type === '' || $post->post_type === $post_type)) {
                return (int) $post->ID;
            }
        }

        return 0;
    }

    /**
     * @param array<string,mixed> $descriptor
     * @param array<string,mixed> $indexes
     * @return array<string,mixed>
     */
    private static function resolve_deferred_acf_descriptor(array $descriptor, array $indexes): array
    {
        $field_type = isset($descriptor['field_type']) ? sanitize_key((string) ($descriptor['field_type'] ?? '')) : '';
        $values = isset($descriptor['values']) && is_array($descriptor['values']) ? $descriptor['values'] : [];
        $resolved = [];
        $missing = [];

        foreach ($values as $value) {
            if (! is_array($value)) {
                continue;
            }

            if ($field_type === 'taxonomy') {
                $resolved_id = self::resolve_term_descriptor($value, $indexes);
                if ($resolved_id > 0) {
                    $resolved[] = $resolved_id;
                } else {
                    $missing[] = self::describe_term_reference($value);
                }
                continue;
            }

            $resolved_id = self::resolve_post_descriptor($value, $indexes);
            if ($resolved_id > 0) {
                $resolved[] = $resolved_id;
            } else {
                $missing[] = self::describe_post_reference($value);
            }
        }

        return [
            'values' => array_values(array_unique(array_map('intval', $resolved))),
            'missing' => array_values(array_unique(array_filter($missing))),
        ];
    }

    /**
     * @param array<string,mixed> $tax_input
     * @param array<string,mixed> $indexes
     * @return array<int,string>
     */
    private static function collect_missing_taxonomy_refs(array $tax_input, array $indexes): array
    {
        $missing = [];

        foreach ($tax_input as $taxonomy => $items) {
            $taxonomy = sanitize_key((string) $taxonomy);
            if (! is_array($items)) {
                $items = [$items];
            }

            foreach ($items as $item) {
                $slug = '';
                if (is_string($item)) {
                    $slug = sanitize_title($item);
                } elseif (is_array($item)) {
                    $slug = isset($item['slug']) ? sanitize_title((string) $item['slug']) : '';
                }

                if ($slug === '') {
                    continue;
                }

                if (self::resolve_term_by_slug($taxonomy, $slug, $indexes) <= 0) {
                    $missing[] = $taxonomy . ':' . $slug;
                }
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * @param array<string,mixed> $descriptor
     * @return string
     */
    private static function describe_post_reference(array $descriptor): string
    {
        $post_type = isset($descriptor['post_type']) ? sanitize_key((string) ($descriptor['post_type'] ?? '')) : '';
        $slug = isset($descriptor['slug']) ? sanitize_title((string) ($descriptor['slug'] ?? '')) : '';
        $uid = isset($descriptor['vf_object_uid']) ? trim((string) ($descriptor['vf_object_uid'] ?? '')) : '';
        $post_id = isset($descriptor['ID']) ? absint($descriptor['ID']) : 0;

        if ($post_type !== '' && $slug !== '') {
            return $post_type . ':' . $slug;
        }
        if ($uid !== '') {
            return 'uid:' . $uid;
        }
        if ($post_id > 0) {
            return 'id:' . $post_id;
        }

        return 'post:unknown';
    }

    /**
     * @param array<string,mixed> $descriptor
     * @return string
     */
    private static function describe_term_reference(array $descriptor): string
    {
        $taxonomy = isset($descriptor['taxonomy']) ? sanitize_key((string) ($descriptor['taxonomy'] ?? '')) : '';
        $slug = isset($descriptor['slug']) ? sanitize_title((string) ($descriptor['slug'] ?? '')) : '';
        $uid = isset($descriptor['vf_object_uid']) ? trim((string) ($descriptor['vf_object_uid'] ?? '')) : '';
        $term_id = isset($descriptor['term_id']) ? absint($descriptor['term_id']) : 0;

        if ($taxonomy !== '' && $slug !== '') {
            return $taxonomy . ':' . $slug;
        }
        if ($uid !== '') {
            return 'uid:' . $uid;
        }
        if ($term_id > 0) {
            return 'term_id:' . $term_id;
        }

        return 'term:unknown';
    }

    /**
     * @param array<string,mixed> $descriptor
     * @return string
     */
    private static function resolve_primary_taxonomy_for_acf_descriptor(array $descriptor): string
    {
        if (! empty($descriptor['taxonomy_filters']) && is_array($descriptor['taxonomy_filters'])) {
            $taxonomies = array_values(array_filter(array_map('sanitize_key', $descriptor['taxonomy_filters'])));
            if (! empty($taxonomies)) {
                return (string) $taxonomies[0];
            }
        }

        if (! empty($descriptor['values']) && is_array($descriptor['values'])) {
            foreach ($descriptor['values'] as $value) {
                if (! is_array($value)) {
                    continue;
                }
                $taxonomy = isset($value['taxonomy']) ? sanitize_key((string) ($value['taxonomy'] ?? '')) : '';
                if ($taxonomy !== '') {
                    return $taxonomy;
                }
            }
        }

        return '';
    }

    /**
     * @param string $entity_kind
     * @param int    $local_id
     * @param string $field_name
     * @param mixed  $value
     * @param string $field_key
     * @return void
     */
    private static function update_entity_meta(string $entity_kind, int $local_id, string $field_name, $value, string $field_key): void
    {
        if ($entity_kind === 'term') {
            update_term_meta($local_id, $field_name, $value);
            if ($field_key !== '') {
                update_term_meta($local_id, '_' . $field_name, $field_key);
            }
            return;
        }

        update_post_meta($local_id, $field_name, $value);
        if ($field_key !== '') {
            update_post_meta($local_id, '_' . $field_name, $field_key);
        }
    }

    /**
     * @param string $entity_kind
     * @param int    $local_id
     * @param string $field_name
     * @param string $field_key
     * @return void
     */
    private static function delete_entity_meta(string $entity_kind, int $local_id, string $field_name, string $field_key): void
    {
        if ($entity_kind === 'term') {
            delete_term_meta($local_id, $field_name);
            if ($field_key !== '') {
                delete_term_meta($local_id, '_' . $field_name);
            }
            return;
        }

        delete_post_meta($local_id, $field_name);
        if ($field_key !== '') {
            delete_post_meta($local_id, '_' . $field_name);
        }
    }

    /**
     * @param array<string,mixed> $entity
     * @param array<string,mixed> $payload
     * @return void
     */
    private static function write_entity_payload(array $entity, array $payload): void
    {
        $absolute_path = isset($entity['absolute_path']) ? (string) $entity['absolute_path'] : '';
        if ($absolute_path === '') {
            return;
        }

        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            return;
        }

        file_put_contents($absolute_path, $json . "\n");
    }

    /**
     * @return array<string,array<string,int>>
     */
    private static function build_empty_family_counts(): array
    {
        return [
            'term_parent' => ['processed' => 0, 'applied' => 0, 'warnings' => 0, 'errors' => 0],
            'post_parent' => ['processed' => 0, 'applied' => 0, 'warnings' => 0, 'errors' => 0],
            'post_tax_input' => ['processed' => 0, 'applied' => 0, 'warnings' => 0, 'errors' => 0],
            'acf_meta' => ['processed' => 0, 'applied' => 0, 'warnings' => 0, 'errors' => 0],
        ];
    }

    /**
     * @param array<string,mixed> $result
     * @param string              $family
     * @param string              $key
     * @return void
     */
    private static function increment_family(array &$result, string $family, string $key): void
    {
        if (! isset($result['families'][$family][$key])) {
            $result['families'][$family][$key] = 0;
        }

        $result['families'][$family][$key]++;
    }

    /**
     * @param array<string,mixed> $result
     * @param string              $family
     * @param string              $status
     * @param string              $message
     * @param array<string,mixed> $entity
     * @param array<string,mixed> $extra
     * @return void
     */
    private static function record_detail(array &$result, string $family, string $status, string $message, array $entity, array $extra = []): void
    {
        $detail = array_merge([
            'family' => $family,
            'status' => $status,
            'message' => $message,
            'entity_kind' => (string) ($entity['entity_kind'] ?? ''),
            'object_key' => (string) ($entity['object_key'] ?? ''),
            'slug' => (string) ($entity['slug'] ?? ''),
            'path' => (string) ($entity['path'] ?? ''),
        ], $extra);

        $result['details'][] = $detail;

        if ($status === 'applied') {
            $result['applied']++;
            self::increment_family($result, $family, 'applied');
            return;
        }

        if ($status === 'applied_with_warnings') {
            $result['applied']++;
            $result['warnings']++;
            self::increment_family($result, $family, 'applied');
            self::increment_family($result, $family, 'warnings');
            return;
        }

        if ($status === 'error') {
            $result['errors']++;
            self::increment_family($result, $family, 'errors');
            return;
        }

        $result['warnings']++;
        self::increment_family($result, $family, 'warnings');
    }
}
