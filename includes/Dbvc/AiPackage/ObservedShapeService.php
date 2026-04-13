<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class ObservedShapeService
{
    /**
     * Collect bounded observed-shape signals from live site content.
     *
     * @param array<string,mixed> $selection
     * @param int                 $scan_cap
     * @return array<string,mixed>
     */
    public static function collect(array $selection, int $scan_cap): array
    {
        $scan_cap = self::normalize_scan_cap($scan_cap);

        $post_types = isset($selection['post_types']) && is_array($selection['post_types']) ? $selection['post_types'] : [];
        $taxonomies = isset($selection['taxonomies']) && is_array($selection['taxonomies']) ? $selection['taxonomies'] : [];

        return [
            'scan_cap' => $scan_cap,
            'post_types' => self::collect_post_type_observations($post_types, $scan_cap),
            'taxonomies' => self::collect_taxonomy_observations($taxonomies, $scan_cap),
        ];
    }

    /**
     * @param array<int,mixed> $post_types
     * @param int              $scan_cap
     * @return array<string,mixed>
     */
    private static function collect_post_type_observations(array $post_types, int $scan_cap): array
    {
        $observations = [];

        foreach ($post_types as $post_type) {
            $post_type = sanitize_key((string) $post_type);
            if ($post_type === '' || ! post_type_exists($post_type)) {
                continue;
            }

            $query = new \WP_Query(
                [
                    'post_type' => $post_type,
                    'post_status' => 'any',
                    'posts_per_page' => $scan_cap,
                    'orderby' => 'ID',
                    'order' => 'ASC',
                    'fields' => 'ids',
                    'no_found_rows' => true,
                    'ignore_sticky_posts' => true,
                    'cache_results' => false,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                ]
            );

            $post_ids = isset($query->posts) && is_array($query->posts) ? array_map('absint', $query->posts) : [];
            $meta_keys = [];
            $taxonomy_presence = [];

            foreach ($post_ids as $post_id) {
                $meta = get_post_meta($post_id);
                if (! is_array($meta)) {
                    $meta = [];
                }

                foreach ($meta as $meta_key => $values) {
                    $meta_key = (string) $meta_key;
                    if (self::should_skip_post_meta_key($meta_key)) {
                        continue;
                    }

                    if (! isset($meta_keys[$meta_key])) {
                        $meta_keys[$meta_key] = [
                            'count' => 0,
                            'frequency' => 0,
                            'value_type' => '',
                            'source' => 'observed',
                        ];
                    }

                    $meta_keys[$meta_key]['count']++;
                    if ($meta_keys[$meta_key]['value_type'] === '') {
                        $meta_keys[$meta_key]['value_type'] = self::detect_meta_value_type($values);
                    }
                }

                $attached_taxonomies = get_object_taxonomies($post_type);
                if (! is_array($attached_taxonomies)) {
                    $attached_taxonomies = [];
                }

                foreach ($attached_taxonomies as $taxonomy) {
                    $terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
                    if (is_wp_error($terms) || ! is_array($terms) || empty($terms)) {
                        continue;
                    }

                    if (! isset($taxonomy_presence[$taxonomy])) {
                        $taxonomy_presence[$taxonomy] = 0;
                    }

                    $taxonomy_presence[$taxonomy]++;
                }
            }

            foreach ($meta_keys as $meta_key => $details) {
                $count = isset($details['count']) ? (int) $details['count'] : 0;
                $meta_keys[$meta_key]['frequency'] = $scan_cap > 0 && ! empty($post_ids)
                    ? round($count / count($post_ids), 4)
                    : 0;
            }

            ksort($meta_keys);
            ksort($taxonomy_presence);

            $observations[$post_type] = [
                'scanned' => count($post_ids),
                'meta_keys' => $meta_keys,
                'taxonomy_presence' => $taxonomy_presence,
            ];
        }

        ksort($observations);
        return $observations;
    }

    /**
     * @param array<int,mixed> $taxonomies
     * @param int              $scan_cap
     * @return array<string,mixed>
     */
    private static function collect_taxonomy_observations(array $taxonomies, int $scan_cap): array
    {
        $observations = [];

        foreach ($taxonomies as $taxonomy) {
            $taxonomy = sanitize_key((string) $taxonomy);
            if ($taxonomy === '' || ! taxonomy_exists($taxonomy)) {
                continue;
            }

            $terms = get_terms(
                [
                    'taxonomy' => $taxonomy,
                    'hide_empty' => false,
                    'number' => $scan_cap,
                    'orderby' => 'id',
                    'order' => 'ASC',
                ]
            );

            if (is_wp_error($terms) || ! is_array($terms)) {
                $terms = [];
            }

            $meta_keys = [];
            $parent_count = 0;

            foreach ($terms as $term) {
                if (! ($term instanceof \WP_Term)) {
                    continue;
                }

                if (! empty($term->parent)) {
                    $parent_count++;
                }

                $meta = get_term_meta($term->term_id);
                if (! is_array($meta)) {
                    $meta = [];
                }

                foreach ($meta as $meta_key => $values) {
                    $meta_key = (string) $meta_key;
                    if (self::should_skip_term_meta_key($meta_key)) {
                        continue;
                    }

                    if (! isset($meta_keys[$meta_key])) {
                        $meta_keys[$meta_key] = [
                            'count' => 0,
                            'frequency' => 0,
                            'value_type' => '',
                            'source' => 'observed',
                        ];
                    }

                    $meta_keys[$meta_key]['count']++;
                    if ($meta_keys[$meta_key]['value_type'] === '') {
                        $meta_keys[$meta_key]['value_type'] = self::detect_meta_value_type($values);
                    }
                }
            }

            foreach ($meta_keys as $meta_key => $details) {
                $count = isset($details['count']) ? (int) $details['count'] : 0;
                $meta_keys[$meta_key]['frequency'] = ! empty($terms)
                    ? round($count / count($terms), 4)
                    : 0;
            }

            ksort($meta_keys);

            $observations[$taxonomy] = [
                'scanned' => count($terms),
                'meta_keys' => $meta_keys,
                'parent_frequency' => ! empty($terms) ? round($parent_count / count($terms), 4) : 0,
            ];
        }

        ksort($observations);
        return $observations;
    }

    /**
     * @param mixed $values
     * @return string
     */
    private static function detect_meta_value_type($values): string
    {
        if (! is_array($values)) {
            return self::detect_scalar_value_type($values);
        }

        foreach ($values as $value) {
            $maybe = maybe_unserialize($value);
            $type = self::detect_scalar_value_type($maybe);
            if ($type !== '') {
                return $type;
            }
        }

        return 'unknown';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function detect_scalar_value_type($value): string
    {
        if (is_array($value)) {
            return 'array';
        }

        if (is_object($value)) {
            return 'object';
        }

        if (is_bool($value)) {
            return 'boolean';
        }

        if (is_int($value)) {
            return 'integer';
        }

        if (is_float($value)) {
            return 'number';
        }

        if ($value === null) {
            return 'null';
        }

        if (! is_string($value)) {
            return '';
        }

        $value = trim($value);
        if ($value === '') {
            return 'string';
        }

        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? 'number' : 'integer';
        }

        if (in_array(strtolower($value), ['0', '1', 'true', 'false', 'yes', 'no'], true)) {
            return 'boolean_like_string';
        }

        return 'string';
    }

    /**
     * @param string $meta_key
     * @return bool
     */
    private static function should_skip_post_meta_key(string $meta_key): bool
    {
        if ($meta_key === '' || strpos($meta_key, '_') === 0) {
            return true;
        }

        return in_array(
            $meta_key,
            [
                'vf_object_uid',
                'dbvc_post_history',
                '_dbvc_import_hash',
                '_edit_lock',
                '_edit_last',
            ],
            true
        );
    }

    /**
     * @param string $meta_key
     * @return bool
     */
    private static function should_skip_term_meta_key(string $meta_key): bool
    {
        if ($meta_key === '' || strpos($meta_key, '_') === 0) {
            return true;
        }

        return in_array(
            $meta_key,
            [
                'vf_object_uid',
                'dbvc_term_history',
                'parent_uid',
            ],
            true
        );
    }

    /**
     * @param int $scan_cap
     * @return int
     */
    private static function normalize_scan_cap(int $scan_cap): int
    {
        if ($scan_cap < Settings::MIN_OBSERVED_SCAN_CAP) {
            return Settings::MIN_OBSERVED_SCAN_CAP;
        }

        if ($scan_cap > Settings::MAX_OBSERVED_SCAN_CAP) {
            return Settings::MAX_OBSERVED_SCAN_CAP;
        }

        return $scan_cap;
    }
}
