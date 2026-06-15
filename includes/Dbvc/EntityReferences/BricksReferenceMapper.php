<?php

namespace Dbvc\EntityReferences;

if (! defined('WPINC')) {
    die;
}

final class BricksReferenceMapper
{
    private const SCHEMA = 'dbvc.entity_reference.v1';
    private const PROVIDER = 'bricks';
    private const META_TEMPLATE_SETTINGS = '_bricks_template_settings';

    /**
     * Collect known Bricks entity references from a post export payload.
     *
     * @param array<string,mixed> $payload
     * @param bool                $hydrate_context Whether to read source objects for UID/slug context.
     * @return array<int,array<string,mixed>>
     */
    public static function collect_post_references(array $payload, bool $hydrate_context = true): array
    {
        $meta = isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : [];
        $settings_values = $meta[self::META_TEMPLATE_SETTINGS] ?? [];
        if (! is_array($settings_values)) {
            return [];
        }

        $references = [];
        foreach ($settings_values as $index => $setting) {
            $setting = maybe_unserialize($setting);
            if (! is_array($setting)) {
                continue;
            }

            if (array_key_exists('templatePreviewPostId', $setting)) {
                $raw_value = $setting['templatePreviewPostId'];
                $source_id = absint($raw_value);
                if ($source_id) {
                    $path = sprintf('meta.%s.%d.templatePreviewPostId', self::META_TEMPLATE_SETTINGS, (int) $index);
                    $context = self::build_post_context($source_id, $setting, $hydrate_context);

                    $references[] = [
                        'schema'            => self::SCHEMA,
                        'provider'          => self::PROVIDER,
                        'kind'              => 'post',
                        'source_id'         => $source_id,
                        'source_value_type' => self::value_type($raw_value),
                        'path'              => $path,
                        'meta_key'          => self::META_TEMPLATE_SETTINGS,
                        'context'           => $context,
                        'policy'            => 'localize_on_import',
                        'confidence'        => 'high',
                    ];
                }
            }

            if (array_key_exists('templatePreviewTerm', $setting)) {
                $raw_value = $setting['templatePreviewTerm'];
                $parsed = self::parse_scoped_term_value($raw_value);
                if ($parsed !== null) {
                    $path = sprintf('meta.%s.%d.templatePreviewTerm', self::META_TEMPLATE_SETTINGS, (int) $index);
                    $context = self::build_term_context($parsed['term_id'], $parsed['taxonomy'], $hydrate_context);

                    $references[] = [
                        'schema'            => self::SCHEMA,
                        'provider'          => self::PROVIDER,
                        'kind'              => 'term',
                        'source_id'         => $parsed['term_id'],
                        'source_value_type' => self::value_type($raw_value),
                        'path'              => $path,
                        'meta_key'          => self::META_TEMPLATE_SETTINGS,
                        'context'           => $context,
                        'policy'            => 'localize_on_import',
                        'confidence'        => 'high',
                    ];
                }
            }
        }

        return $references;
    }

    /**
     * Localize known Bricks ID references inside a decoded post payload.
     *
     * @param array<string,mixed> $payload
     * @param array<int,int>      $source_to_local_post_ids
     * @return array{payload:array<string,mixed>,results:array<int,array<string,mixed>>}
     */
    public static function localize_post_payload(array $payload, array $source_to_local_post_ids = []): array
    {
        return self::localize_post_payload_internal($payload, $source_to_local_post_ids, true);
    }

    /**
     * Dry-run known Bricks ID reference localization for proposal/preflight display.
     *
     * @param array<string,mixed> $payload
     * @param array<int,int>      $source_to_local_post_ids
     * @return array{payload:array<string,mixed>,results:array<int,array<string,mixed>>}
     */
    public static function preflight_post_payload(array $payload, array $source_to_local_post_ids = []): array
    {
        return self::localize_post_payload_internal($payload, $source_to_local_post_ids, false);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int,int>      $source_to_local_post_ids
     * @return array{payload:array<string,mixed>,results:array<int,array<string,mixed>>}
     */
    private static function localize_post_payload_internal(array $payload, array $source_to_local_post_ids = [], bool $respect_enabled = true): array
    {
        if ($respect_enabled && ! self::is_enabled()) {
            return [
                'payload' => $payload,
                'results' => [],
            ];
        }

        $references = self::normalize_references($payload['dbvc_entity_references'] ?? []);
        foreach (self::collect_post_references($payload, false) as $detected) {
            $key = (string) ($detected['path'] ?? '');
            if ($key !== '' && ! isset($references[$key])) {
                $references[$key] = $detected;
            }
        }

        $results = [];
        foreach ($references as $reference) {
            if (! self::is_supported_reference($reference)) {
                continue;
            }

            $source_id = absint($reference['source_id'] ?? 0);
            $path = (string) ($reference['path'] ?? '');
            $kind = isset($reference['kind']) ? sanitize_key((string) $reference['kind']) : '';
            $current_value = self::get_reference_value($payload, $reference);
            if ($current_value === null) {
                $results[] = self::build_result($reference, 'missing_path');
                continue;
            }

            $current_source_id = self::current_source_id($reference, $current_value);
            if ($source_id && $current_source_id && $current_source_id !== $source_id) {
                $results[] = self::build_result($reference, 'current_value_mismatch', [
                    'current_source_id' => $current_source_id,
                ]);
                continue;
            }

            if (! $source_id) {
                $source_id = $current_source_id;
            }

            $local_id = $kind === 'term'
                ? self::resolve_term_reference($reference)
                : self::resolve_post_reference($reference, $source_to_local_post_ids);
            if (! $local_id) {
                $results[] = self::build_result($reference, 'unresolved');
                continue;
            }

            $value_type = isset($reference['source_value_type']) ? (string) $reference['source_value_type'] : self::value_type($current_value);
            $localized_value = self::localized_value($reference, $current_value, $local_id, $value_type);
            $patched = $localized_value !== null && self::set_reference_value($payload, $reference, $localized_value);
            $results[] = self::build_result($reference, $patched ? 'resolved' : 'patch_failed', [
                'source_id' => $source_id,
                'local_id'  => $local_id,
            ]);
        }

        return [
            'payload'  => $payload,
            'results' => $results,
        ];
    }

    /**
     * @param mixed $references
     * @return array<string,array<string,mixed>>
     */
    public static function normalize_references($references): array
    {
        if (! is_array($references)) {
            return [];
        }

        $normalized = [];
        foreach ($references as $reference) {
            if (! is_array($reference)) {
                continue;
            }

            $provider = isset($reference['provider']) ? sanitize_key((string) $reference['provider']) : '';
            $kind = isset($reference['kind']) ? sanitize_key((string) $reference['kind']) : '';
            $path = isset($reference['path']) ? (string) $reference['path'] : '';
            $source_id = absint($reference['source_id'] ?? 0);
            if ($provider === '' || $kind === '' || $path === '' || ! $source_id) {
                continue;
            }

            $context = isset($reference['context']) && is_array($reference['context'])
                ? self::sanitize_context($reference['context'])
                : [];

            $normalized[$path] = [
                'schema'            => isset($reference['schema']) ? sanitize_text_field((string) $reference['schema']) : self::SCHEMA,
                'provider'          => $provider,
                'kind'              => $kind,
                'source_id'         => $source_id,
                'source_value_type' => isset($reference['source_value_type']) ? sanitize_key((string) $reference['source_value_type']) : 'string',
                'path'              => $path,
                'meta_key'          => isset($reference['meta_key']) ? sanitize_key((string) $reference['meta_key']) : '',
                'context'           => $context,
                'policy'            => isset($reference['policy']) ? sanitize_key((string) $reference['policy']) : 'localize_on_import',
                'confidence'        => isset($reference['confidence']) ? sanitize_key((string) $reference['confidence']) : 'medium',
            ];
        }

        return $normalized;
    }

    private static function is_enabled(): bool
    {
        $enabled = get_option('dbvc_localize_bricks_entity_references', '1') !== '0';
        return (bool) apply_filters('dbvc_localize_bricks_entity_references', $enabled);
    }

    /**
     * @param array<string,mixed> $reference
     */
    private static function is_supported_reference(array $reference): bool
    {
        if (($reference['provider'] ?? '') !== self::PROVIDER) {
            return false;
        }

        $kind = isset($reference['kind']) ? sanitize_key((string) $reference['kind']) : '';
        $path = (string) ($reference['path'] ?? '');

        if ($kind === 'post') {
            return self::parse_template_preview_post_path($path) !== null;
        }

        if ($kind === 'term') {
            return self::parse_template_preview_term_path($path) !== null;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $setting
     * @return array<string,mixed>
     */
    private static function build_post_context(int $source_id, array $setting, bool $hydrate_context): array
    {
        $context = [];

        if ($hydrate_context) {
            $post = get_post($source_id);
            if ($post instanceof \WP_Post) {
                $context['post_type'] = sanitize_key($post->post_type);
                $context['slug'] = sanitize_title($post->post_name);
                $context['title'] = sanitize_text_field($post->post_title);

                $uid = get_post_meta($source_id, 'vf_object_uid', true);
                $uid = is_string($uid) ? trim($uid) : '';
                if ($uid === '' && class_exists('\DBVC_Sync_Posts')) {
                    $uid = (string) \DBVC_Sync_Posts::ensure_post_uid($source_id, $post);
                }
                if ($uid !== '') {
                    $context['vf_object_uid'] = sanitize_text_field($uid);
                }
            }
        }

        if (empty($context['post_type'])) {
            $post_type = self::infer_post_type_from_setting($setting);
            if ($post_type !== '') {
                $context['post_type'] = $post_type;
            }
        }

        return $context;
    }

    /**
     * @return array<string,mixed>
     */
    private static function build_term_context(int $source_id, string $taxonomy, bool $hydrate_context): array
    {
        $taxonomy = sanitize_key($taxonomy);
        $context = [];
        if ($taxonomy !== '') {
            $context['taxonomy'] = $taxonomy;
        }

        if (! $hydrate_context || ! $source_id || $taxonomy === '') {
            return $context;
        }

        $term = get_term($source_id, $taxonomy);
        if (! $term || is_wp_error($term) || ! $term instanceof \WP_Term) {
            return $context;
        }

        $context['slug'] = sanitize_title($term->slug);
        $context['name'] = sanitize_text_field($term->name);

        $uid = get_term_meta($source_id, 'vf_object_uid', true);
        $uid = is_string($uid) ? trim($uid) : '';
        if ($uid === '' && class_exists('\DBVC_Sync_Taxonomies')) {
            $uid = (string) \DBVC_Sync_Taxonomies::ensure_term_uid($source_id, $taxonomy);
        }
        if ($uid !== '') {
            $context['vf_object_uid'] = sanitize_text_field($uid);
        }

        return $context;
    }

    /**
     * @param array<string,mixed> $setting
     */
    private static function infer_post_type_from_setting(array $setting): string
    {
        if (isset($setting['postType'])) {
            $post_type = self::first_string($setting['postType']);
            if ($post_type !== '') {
                return sanitize_key($post_type);
            }
        }

        $conditions = isset($setting['templateConditions']) && is_array($setting['templateConditions'])
            ? $setting['templateConditions']
            : [];
        foreach ($conditions as $condition) {
            if (! is_array($condition) || ! isset($condition['postType'])) {
                continue;
            }

            $post_type = self::first_string($condition['postType']);
            if ($post_type !== '') {
                return sanitize_key($post_type);
            }
        }

        return '';
    }

    /**
     * @param mixed $value
     */
    private static function first_string($value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item) && trim($item) !== '') {
                    return trim($item);
                }
            }
        }

        return '';
    }

    /**
     * @param mixed $value
     */
    private static function value_type($value): string
    {
        if (is_int($value)) {
            return 'integer';
        }

        if (is_array($value)) {
            return 'array';
        }

        return 'string';
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function sanitize_context(array $context): array
    {
        $sanitized = [];
        foreach ($context as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key === '') {
                continue;
            }

            if (is_scalar($value)) {
                $sanitized[$key] = sanitize_text_field((string) $value);
            }
        }

        return $sanitized;
    }

    /**
     * @param array<string,mixed> $reference
     * @param array<int,int>      $source_to_local_post_ids
     */
    private static function resolve_post_reference(array $reference, array $source_to_local_post_ids): int
    {
        $source_id = absint($reference['source_id'] ?? 0);
        $context = isset($reference['context']) && is_array($reference['context']) ? $reference['context'] : [];
        $post_type = isset($context['post_type']) ? sanitize_key((string) $context['post_type']) : '';

        if ($source_id && isset($source_to_local_post_ids[$source_id])) {
            $candidate = get_post((int) $source_to_local_post_ids[$source_id]);
            if ($candidate instanceof \WP_Post) {
                return (int) $candidate->ID;
            }
        }

        $uid = isset($context['vf_object_uid']) ? trim((string) $context['vf_object_uid']) : '';
        if ($uid !== '') {
            $by_uid = self::find_post_by_uid($uid, $post_type);
            if ($by_uid) {
                return $by_uid;
            }
        }

        $slug = isset($context['slug']) ? sanitize_title((string) $context['slug']) : '';
        if ($slug !== '' && $post_type !== '') {
            $by_slug = self::find_post_by_slug($slug, $post_type);
            if ($by_slug) {
                return $by_slug;
            }
        }

        if ($source_id) {
            $candidate = get_post($source_id);
            if ($candidate instanceof \WP_Post && ($post_type === '' || $candidate->post_type === $post_type)) {
                return (int) $candidate->ID;
            }
        }

        return 0;
    }

    /**
     * @param array<string,mixed> $reference
     */
    private static function resolve_term_reference(array $reference): int
    {
        $source_id = absint($reference['source_id'] ?? 0);
        $context = isset($reference['context']) && is_array($reference['context']) ? $reference['context'] : [];
        $taxonomy = isset($context['taxonomy']) ? sanitize_key((string) $context['taxonomy']) : '';

        $uid = isset($context['vf_object_uid']) ? trim((string) $context['vf_object_uid']) : '';
        if ($uid !== '' && class_exists('\DBVC_Sync_Posts') && method_exists('\DBVC_Sync_Posts', 'find_term_id_by_uid')) {
            $by_uid = \DBVC_Sync_Posts::find_term_id_by_uid($uid, $taxonomy);
            if ($by_uid) {
                return (int) $by_uid;
            }
        }

        $slug = isset($context['slug']) ? sanitize_title((string) $context['slug']) : '';
        if ($slug !== '' && $taxonomy !== '' && taxonomy_exists($taxonomy)) {
            $by_slug = get_term_by('slug', $slug, $taxonomy);
            if ($by_slug instanceof \WP_Term) {
                return (int) $by_slug->term_id;
            }
        }

        if ($source_id && $taxonomy !== '' && taxonomy_exists($taxonomy)) {
            $candidate = get_term($source_id, $taxonomy);
            if ($candidate instanceof \WP_Term && ! is_wp_error($candidate)) {
                return (int) $candidate->term_id;
            }
        }

        return 0;
    }

    private static function find_post_by_uid(string $uid, string $post_type = ''): int
    {
        if (class_exists('\DBVC_Database')) {
            $record = \DBVC_Database::get_entity_by_uid($uid);
            if ($record && ! empty($record->object_id)) {
                $candidate = get_post((int) $record->object_id);
                if ($candidate instanceof \WP_Post && ($post_type === '' || $candidate->post_type === $post_type)) {
                    return (int) $candidate->ID;
                }
            }
        }

        $query_args = [
            'post_type'        => $post_type !== '' ? [$post_type] : 'any',
            'post_status'      => 'any',
            'meta_key'         => 'vf_object_uid',
            'meta_value'       => $uid,
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ];

        $found = get_posts($query_args);
        return ! empty($found) ? (int) $found[0] : 0;
    }

    private static function find_post_by_slug(string $slug, string $post_type): int
    {
        $candidate = get_page_by_path($slug, OBJECT, $post_type);
        if ($candidate instanceof \WP_Post) {
            return (int) $candidate->ID;
        }

        $found = get_posts([
            'post_type'        => [$post_type],
            'name'             => $slug,
            'post_status'      => 'any',
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]);

        return ! empty($found) ? (int) $found[0] : 0;
    }

    /**
     * @return int|null
     */
    private static function parse_template_preview_post_path(string $path): ?int
    {
        if (preg_match('/^meta\._bricks_template_settings\.(\d+)\.templatePreviewPostId$/', $path, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @return int|null
     */
    private static function parse_template_preview_term_path(string $path): ?int
    {
        if (preg_match('/^meta\._bricks_template_settings\.(\d+)\.templatePreviewTerm$/', $path, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return array{taxonomy:string,term_id:int}|null
     */
    private static function parse_scoped_term_value($value): ?array
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if (! preg_match('/^([A-Za-z0-9_-]+)::([0-9]+)$/', $value, $matches)) {
            return null;
        }

        $taxonomy = sanitize_key($matches[1]);
        $term_id = absint($matches[2]);
        if ($taxonomy === '' || ! $term_id) {
            return null;
        }

        return [
            'taxonomy' => $taxonomy,
            'term_id'  => $term_id,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $reference
     * @return mixed|null
     */
    private static function get_reference_value(array $payload, array $reference)
    {
        $path = (string) ($reference['path'] ?? '');
        $post_index = self::parse_template_preview_post_path($path);
        if ($post_index !== null) {
            if (! isset($payload['meta'][self::META_TEMPLATE_SETTINGS][$post_index]) || ! is_array($payload['meta'][self::META_TEMPLATE_SETTINGS][$post_index])) {
                return null;
            }

            return $payload['meta'][self::META_TEMPLATE_SETTINGS][$post_index]['templatePreviewPostId'] ?? null;
        }

        $term_index = self::parse_template_preview_term_path($path);
        if ($term_index !== null) {
            if (! isset($payload['meta'][self::META_TEMPLATE_SETTINGS][$term_index]) || ! is_array($payload['meta'][self::META_TEMPLATE_SETTINGS][$term_index])) {
                return null;
            }

            return $payload['meta'][self::META_TEMPLATE_SETTINGS][$term_index]['templatePreviewTerm'] ?? null;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $reference
     * @param mixed               $value
     */
    private static function set_reference_value(array &$payload, array $reference, $value): bool
    {
        $path = (string) ($reference['path'] ?? '');
        $post_index = self::parse_template_preview_post_path($path);
        if ($post_index !== null) {
            if (! isset($payload['meta'][self::META_TEMPLATE_SETTINGS][$post_index]) || ! is_array($payload['meta'][self::META_TEMPLATE_SETTINGS][$post_index])) {
                return false;
            }

            $payload['meta'][self::META_TEMPLATE_SETTINGS][$post_index]['templatePreviewPostId'] = $value;
            return true;
        }

        $term_index = self::parse_template_preview_term_path($path);
        if ($term_index !== null) {
            if (! isset($payload['meta'][self::META_TEMPLATE_SETTINGS][$term_index]) || ! is_array($payload['meta'][self::META_TEMPLATE_SETTINGS][$term_index])) {
                return false;
            }

            $payload['meta'][self::META_TEMPLATE_SETTINGS][$term_index]['templatePreviewTerm'] = $value;
            return true;
        }

        return false;
    }

    /**
     * @param mixed $value
     * @return int|string
     */
    private static function cast_value(int $value, string $type)
    {
        return $type === 'integer' ? $value : (string) $value;
    }

    /**
     * @param array<string,mixed> $reference
     * @param mixed               $current_value
     */
    private static function current_source_id(array $reference, $current_value): int
    {
        $kind = isset($reference['kind']) ? sanitize_key((string) $reference['kind']) : '';
        if ($kind === 'term') {
            $parsed = self::parse_scoped_term_value($current_value);
            return $parsed !== null ? $parsed['term_id'] : 0;
        }

        return absint($current_value);
    }

    /**
     * @param array<string,mixed> $reference
     * @param mixed               $current_value
     * @return int|string|null
     */
    private static function localized_value(array $reference, $current_value, int $local_id, string $value_type)
    {
        $kind = isset($reference['kind']) ? sanitize_key((string) $reference['kind']) : '';
        if ($kind !== 'term') {
            return self::cast_value($local_id, $value_type);
        }

        $parsed = self::parse_scoped_term_value($current_value);
        $taxonomy = $parsed !== null ? $parsed['taxonomy'] : '';
        if ($taxonomy === '' && isset($reference['context']) && is_array($reference['context'])) {
            $taxonomy = isset($reference['context']['taxonomy']) ? sanitize_key((string) $reference['context']['taxonomy']) : '';
        }

        return $taxonomy !== '' ? $taxonomy . '::' . $local_id : null;
    }

    /**
     * @param array<string,mixed> $reference
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function build_result(array $reference, string $status, array $extra = []): array
    {
        return array_merge([
            'provider'  => self::PROVIDER,
            'kind'      => isset($reference['kind']) ? sanitize_key((string) $reference['kind']) : '',
            'path'      => (string) ($reference['path'] ?? ''),
            'source_id' => absint($reference['source_id'] ?? 0),
            'status'    => $status,
        ], $extra);
    }
}
