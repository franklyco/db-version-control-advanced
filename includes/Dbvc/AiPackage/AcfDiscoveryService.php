<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class AcfDiscoveryService
{
    private const LOCAL_JSON_DIRNAME = 'acf-json';
    private const FIELD_GROUPS_SUBDIR = 'field-groups';
    private const POST_TYPES_SUBDIR = 'post-types';
    private const TAXONOMIES_SUBDIR = 'taxonomies';

    /**
     * @var array<string,mixed>|null
     */
    private static $cached_discovery = null;

    /**
     * Discover ACF JSON/runtime schema signals used by AI package generation.
     *
     * @return array<string,mixed>
     */
    public static function discover(): array
    {
        if (is_array(self::$cached_discovery)) {
            return self::$cached_discovery;
        }

        $local_json_dirs = self::get_local_json_directories();
        $local_post_types = self::collect_local_post_types($local_json_dirs);
        $local_taxonomies = self::collect_local_taxonomies($local_json_dirs);
        $local_field_groups = self::collect_local_field_groups($local_json_dirs);

        $field_groups = $local_field_groups;
        $field_group_source = 'local_json';
        if (empty($field_groups)) {
            $field_groups = self::collect_runtime_field_groups();
            $field_group_source = empty($field_groups) ? 'none' : 'acf_runtime';
        }

        $object_map = self::build_object_map($field_groups);

        self::$cached_discovery = [
            'sources' => [
                'local_json_dirs'  => $local_json_dirs,
                'field_groups'     => $field_group_source,
                'post_types'       => empty($local_post_types) ? 'runtime' : 'local_json',
                'taxonomies'       => empty($local_taxonomies) ? 'runtime' : 'local_json',
            ],
            'local_json' => [
                'post_types'   => $local_post_types,
                'taxonomies'   => $local_taxonomies,
                'field_groups' => $local_field_groups,
            ],
            'post_types' => $local_post_types,
            'taxonomies' => $local_taxonomies,
            'field_groups' => $field_groups,
            'object_map' => $object_map,
            'stats' => [
                'local_json_dir_count' => count($local_json_dirs),
                'local_post_type_count' => count($local_post_types),
                'local_taxonomy_count' => count($local_taxonomies),
                'field_group_count' => count($field_groups),
                'field_group_source' => $field_group_source,
            ],
        ];

        return self::$cached_discovery;
    }

    /**
     * @return array<int,string>
     */
    public static function get_local_json_directories(): array
    {
        $candidates = [];
        if (function_exists('get_stylesheet_directory')) {
            $candidates[] = wp_normalize_path(trailingslashit(get_stylesheet_directory()) . self::LOCAL_JSON_DIRNAME);
        }

        if (function_exists('get_template_directory')) {
            $candidates[] = wp_normalize_path(trailingslashit(get_template_directory()) . self::LOCAL_JSON_DIRNAME);
        }

        $filtered = apply_filters('dbvc_ai_acf_json_directories', $candidates);
        if (! is_array($filtered)) {
            $filtered = $candidates;
        }

        $directories = [];
        foreach ($filtered as $directory) {
            if (! is_string($directory) || $directory === '') {
                continue;
            }

            $normalized = wp_normalize_path(rtrim($directory, '/\\'));
            if ($normalized === '' || ! is_dir($normalized)) {
                continue;
            }

            if (! in_array($normalized, $directories, true)) {
                $directories[] = $normalized;
            }
        }

        return array_values($directories);
    }

    /**
     * @param array<int,string> $directories
     * @return array<string,array<string,mixed>>
     */
    private static function collect_local_post_types(array $directories): array
    {
        $post_types = [];
        foreach ($directories as $directory) {
            $json_files = self::collect_json_files($directory, self::POST_TYPES_SUBDIR);
            foreach ($json_files as $json_file) {
                $payload = self::read_json_file($json_file);
                if (! is_array($payload) || empty($payload['active'])) {
                    continue;
                }

                $post_type = sanitize_key((string) ($payload['post_type'] ?? ''));
                if ($post_type === '' || isset($post_types[$post_type])) {
                    continue;
                }

                $post_types[$post_type] = [
                    'key' => isset($payload['key']) ? (string) $payload['key'] : '',
                    'title' => isset($payload['title']) ? (string) $payload['title'] : $post_type,
                    'post_type' => $post_type,
                    'label' => isset($payload['labels']['name']) ? (string) $payload['labels']['name'] : $post_type,
                    'singular_label' => isset($payload['labels']['singular_name']) ? (string) $payload['labels']['singular_name'] : $post_type,
                    'description' => isset($payload['description']) ? (string) $payload['description'] : '',
                    'public' => ! empty($payload['public']),
                    'hierarchical' => ! empty($payload['hierarchical']),
                    'show_in_rest' => ! empty($payload['show_in_rest']),
                    'rest_base' => isset($payload['rest_base']) ? (string) $payload['rest_base'] : '',
                    'menu_icon' => isset($payload['menu_icon']) ? (string) $payload['menu_icon'] : '',
                    'supports' => self::sanitize_string_array($payload['supports'] ?? []),
                    'taxonomies' => self::sanitize_string_array($payload['taxonomies'] ?? []),
                    'source' => 'local_json',
                    'local_json_file' => wp_normalize_path($json_file),
                ];
            }
        }

        ksort($post_types);
        return $post_types;
    }

    /**
     * @param array<int,string> $directories
     * @return array<string,array<string,mixed>>
     */
    private static function collect_local_taxonomies(array $directories): array
    {
        $taxonomies = [];
        foreach ($directories as $directory) {
            $json_files = self::collect_json_files($directory, self::TAXONOMIES_SUBDIR);
            foreach ($json_files as $json_file) {
                $payload = self::read_json_file($json_file);
                if (! is_array($payload) || empty($payload['active'])) {
                    continue;
                }

                $taxonomy = sanitize_key((string) ($payload['taxonomy'] ?? ''));
                if ($taxonomy === '' || isset($taxonomies[$taxonomy])) {
                    continue;
                }

                $taxonomies[$taxonomy] = [
                    'key' => isset($payload['key']) ? (string) $payload['key'] : '',
                    'title' => isset($payload['title']) ? (string) $payload['title'] : $taxonomy,
                    'taxonomy' => $taxonomy,
                    'label' => isset($payload['labels']['name']) ? (string) $payload['labels']['name'] : $taxonomy,
                    'singular_label' => isset($payload['labels']['singular_name']) ? (string) $payload['labels']['singular_name'] : $taxonomy,
                    'description' => isset($payload['description']) ? (string) $payload['description'] : '',
                    'public' => ! empty($payload['public']),
                    'hierarchical' => ! empty($payload['hierarchical']),
                    'show_in_rest' => ! empty($payload['show_in_rest']),
                    'rest_base' => isset($payload['rest_base']) ? (string) $payload['rest_base'] : '',
                    'object_type' => self::sanitize_string_array($payload['object_type'] ?? []),
                    'source' => 'local_json',
                    'local_json_file' => wp_normalize_path($json_file),
                ];
            }
        }

        ksort($taxonomies);
        return $taxonomies;
    }

    /**
     * @param array<int,string> $directories
     * @return array<string,array<string,mixed>>
     */
    private static function collect_local_field_groups(array $directories): array
    {
        $groups = [];
        foreach ($directories as $directory) {
            $json_files = self::collect_json_files($directory, self::FIELD_GROUPS_SUBDIR);
            foreach ($json_files as $json_file) {
                $payload = self::read_json_file($json_file);
                if (! is_array($payload) || empty($payload['active'])) {
                    continue;
                }

                $group_key = isset($payload['key']) ? (string) $payload['key'] : '';
                if ($group_key === '' || isset($groups[$group_key])) {
                    continue;
                }

                $groups[$group_key] = self::normalize_field_group($payload, $payload['fields'] ?? [], 'local_json', $json_file);
            }
        }

        ksort($groups);
        return $groups;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function collect_runtime_field_groups(): array
    {
        if (! function_exists('acf_get_field_groups')) {
            return [];
        }

        $runtime_groups = acf_get_field_groups();
        if (! is_array($runtime_groups)) {
            return [];
        }

        $local_files = function_exists('acf_get_local_json_files') ? acf_get_local_json_files() : [];
        if (! is_array($local_files)) {
            $local_files = [];
        }

        $groups = [];
        foreach ($runtime_groups as $runtime_group) {
            if (! is_array($runtime_group)) {
                continue;
            }

            $group_key = isset($runtime_group['key']) ? (string) $runtime_group['key'] : '';
            if ($group_key === '') {
                continue;
            }

            $fields = function_exists('acf_get_fields') ? acf_get_fields($group_key) : [];
            $source = isset($local_files[$group_key]) ? 'acf_runtime_local' : 'acf_runtime';
            $local_json_file = isset($local_files[$group_key]) && is_string($local_files[$group_key]) ? $local_files[$group_key] : '';
            $groups[$group_key] = self::normalize_field_group($runtime_group, $fields, $source, $local_json_file);
        }

        ksort($groups);
        return $groups;
    }

    /**
     * @param array<string,mixed> $group
     * @param mixed               $fields
     * @param string              $source
     * @param string              $local_json_file
     * @return array<string,mixed>
     */
    private static function normalize_field_group(array $group, $fields, string $source, string $local_json_file): array
    {
        $normalized_fields = self::normalize_field_list(is_array($fields) ? $fields : []);
        $location = isset($group['location']) && is_array($group['location']) ? $group['location'] : [];

        return [
            'key' => isset($group['key']) ? (string) $group['key'] : '',
            'title' => isset($group['title']) ? (string) $group['title'] : '',
            'active' => ! array_key_exists('active', $group) || ! empty($group['active']),
            'source' => $source,
            'local_json_file' => $local_json_file !== '' ? wp_normalize_path($local_json_file) : '',
            'location' => self::normalize_location($location),
            'targets' => self::extract_group_targets($location),
            'fields' => $normalized_fields,
            'field_names' => self::collect_field_names($normalized_fields),
            'field_count' => count(self::collect_field_names($normalized_fields)),
        ];
    }

    /**
     * @param array<int,mixed> $fields
     * @return array<int,array<string,mixed>>
     */
    private static function normalize_field_list(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $normalized[] = self::normalize_field_definition($field);
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    public static function normalize_field_definition(array $field): array
    {
        $type = sanitize_key((string) ($field['type'] ?? ''));
        $name = isset($field['name']) ? (string) $field['name'] : '';

        $normalized = [
            'key' => isset($field['key']) ? (string) $field['key'] : '',
            'name' => $name,
            'label' => isset($field['label']) ? (string) $field['label'] : $name,
            'type' => $type,
            'instructions' => isset($field['instructions']) ? (string) $field['instructions'] : '',
            'required' => ! empty($field['required']),
            'conditional_logic' => ! empty($field['conditional_logic']),
            'default_value' => self::normalize_value($field['default_value'] ?? null),
            'choices' => self::normalize_choices($field['choices'] ?? []),
            'allow_null' => ! empty($field['allow_null']),
            'multiple' => ! empty($field['multiple']),
            'return_format' => isset($field['return_format']) ? sanitize_key((string) $field['return_format']) : '',
            'field_type' => isset($field['field_type']) ? sanitize_key((string) $field['field_type']) : '',
            'post_type' => self::sanitize_string_array($field['post_type'] ?? []),
            'taxonomy_filters' => self::sanitize_string_array($field['taxonomy'] ?? []),
            'save_terms' => ! empty($field['save_terms']),
            'load_terms' => ! empty($field['load_terms']),
            'mime_types' => isset($field['mime_types']) ? (string) $field['mime_types'] : '',
            'min' => self::normalize_value($field['min'] ?? null),
            'max' => self::normalize_value($field['max'] ?? null),
            'availability' => self::sanitize_string_array($field['fco_object_availability'] ?? []),
        ];

        if (! empty($field['sub_fields']) && is_array($field['sub_fields'])) {
            $normalized['sub_fields'] = self::normalize_field_list($field['sub_fields']);
        }

        if ($type === 'flexible_content' && ! empty($field['layouts']) && is_array($field['layouts'])) {
            $normalized['layouts'] = self::normalize_layouts($field['layouts']);
        }

        if ($type === 'clone') {
            $normalized['clone'] = [
                'targets' => self::sanitize_string_array($field['clone'] ?? []),
                'display' => isset($field['display']) ? sanitize_key((string) $field['display']) : '',
                'layout' => isset($field['layout']) ? sanitize_key((string) $field['layout']) : '',
                'prefix_label' => ! empty($field['prefix_label']),
                'prefix_name' => ! empty($field['prefix_name']),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int,mixed> $layouts
     * @return array<int,array<string,mixed>>
     */
    private static function normalize_layouts(array $layouts): array
    {
        $normalized = [];
        foreach ($layouts as $layout) {
            if (! is_array($layout)) {
                continue;
            }

            $normalized[] = [
                'key' => isset($layout['key']) ? (string) $layout['key'] : '',
                'name' => isset($layout['name']) ? sanitize_key((string) $layout['name']) : '',
                'label' => isset($layout['label']) ? (string) $layout['label'] : '',
                'display' => isset($layout['display']) ? sanitize_key((string) $layout['display']) : '',
                'sub_fields' => self::normalize_field_list(isset($layout['sub_fields']) && is_array($layout['sub_fields']) ? $layout['sub_fields'] : []),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string,array<string,mixed>> $field_groups
     * @return array<string,mixed>
     */
    private static function build_object_map(array $field_groups): array
    {
        $map = [
            'post_types' => [],
            'taxonomies' => [],
            'options_pages' => [],
            'unscoped_groups' => [],
        ];

        foreach ($field_groups as $group_key => $group) {
            $targets = isset($group['targets']) && is_array($group['targets']) ? $group['targets'] : [];

            $post_types = isset($targets['post_types']) && is_array($targets['post_types']) ? $targets['post_types'] : [];
            foreach ($post_types as $post_type) {
                $post_type = sanitize_key((string) $post_type);
                if ($post_type === '') {
                    continue;
                }

                if (! isset($map['post_types'][$post_type])) {
                    $map['post_types'][$post_type] = [];
                }

                $map['post_types'][$post_type][] = $group_key;
            }

            $taxonomies = isset($targets['taxonomies']) && is_array($targets['taxonomies']) ? $targets['taxonomies'] : [];
            foreach ($taxonomies as $taxonomy) {
                $taxonomy = sanitize_key((string) $taxonomy);
                if ($taxonomy === '') {
                    continue;
                }

                if (! isset($map['taxonomies'][$taxonomy])) {
                    $map['taxonomies'][$taxonomy] = [];
                }

                $map['taxonomies'][$taxonomy][] = $group_key;
            }

            $options_pages = isset($targets['options_pages']) && is_array($targets['options_pages']) ? $targets['options_pages'] : [];
            foreach ($options_pages as $options_page) {
                $options_page = sanitize_key((string) $options_page);
                if ($options_page === '') {
                    continue;
                }

                if (! isset($map['options_pages'][$options_page])) {
                    $map['options_pages'][$options_page] = [];
                }

                $map['options_pages'][$options_page][] = $group_key;
            }

            if (empty($post_types) && empty($taxonomies) && empty($options_pages)) {
                $map['unscoped_groups'][] = $group_key;
            }
        }

        foreach (['post_types', 'taxonomies', 'options_pages'] as $family) {
            ksort($map[$family]);
            foreach ($map[$family] as $object_key => $group_keys) {
                $group_keys = array_values(array_unique(array_map('strval', $group_keys)));
                sort($group_keys);
                $map[$family][$object_key] = $group_keys;
            }
        }

        $map['unscoped_groups'] = array_values(array_unique(array_map('strval', $map['unscoped_groups'])));
        sort($map['unscoped_groups']);

        return $map;
    }

    /**
     * @param array<int,mixed> $location
     * @return array<string,mixed>
     */
    private static function extract_group_targets(array $location): array
    {
        $targets = [
            'post_types' => [],
            'taxonomies' => [],
            'options_pages' => [],
            'generic_rules' => [],
        ];

        foreach ($location as $rule_group) {
            if (! is_array($rule_group)) {
                continue;
            }

            foreach ($rule_group as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                $param = isset($rule['param']) ? sanitize_key((string) $rule['param']) : '';
                $operator = isset($rule['operator']) ? (string) $rule['operator'] : '';
                $value = isset($rule['value']) ? (string) $rule['value'] : '';
                if ($param === '' || $value === '') {
                    continue;
                }

                if ($operator !== '==' && $operator !== '===') {
                    $targets['generic_rules'][] = [
                        'param' => $param,
                        'operator' => $operator,
                        'value' => $value,
                    ];
                    continue;
                }

                if ($param === 'post_type') {
                    $targets['post_types'][] = sanitize_key($value);
                    continue;
                }

                if ($param === 'taxonomy') {
                    $targets['taxonomies'][] = sanitize_key($value);
                    continue;
                }

                if ($param === 'post_taxonomy') {
                    $parts = explode(':', $value, 2);
                    $taxonomy = sanitize_key((string) $parts[0]);
                    if ($taxonomy !== '') {
                        $targets['taxonomies'][] = $taxonomy;
                    }
                    continue;
                }

                if ($param === 'options_page') {
                    $targets['options_pages'][] = sanitize_key($value);
                    continue;
                }

                $targets['generic_rules'][] = [
                    'param' => $param,
                    'operator' => $operator,
                    'value' => $value,
                ];
            }
        }

        $targets['post_types'] = self::sanitize_string_array($targets['post_types']);
        $targets['taxonomies'] = self::sanitize_string_array($targets['taxonomies']);
        $targets['options_pages'] = self::sanitize_string_array($targets['options_pages']);
        usort($targets['generic_rules'], static function (array $left, array $right): int {
            return strcmp(wp_json_encode($left), wp_json_encode($right));
        });

        return $targets;
    }

    /**
     * @param array<int,mixed> $location
     * @return array<int,array<int,array<string,string>>>
     */
    private static function normalize_location(array $location): array
    {
        $normalized = [];
        foreach ($location as $rule_group) {
            if (! is_array($rule_group)) {
                continue;
            }

            $normalized_group = [];
            foreach ($rule_group as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                $param = isset($rule['param']) ? sanitize_key((string) $rule['param']) : '';
                $operator = isset($rule['operator']) ? (string) $rule['operator'] : '';
                $value = isset($rule['value']) ? (string) $rule['value'] : '';
                if ($param === '') {
                    continue;
                }

                $normalized_group[] = [
                    'param' => $param,
                    'operator' => $operator,
                    'value' => $value,
                ];
            }

            if (! empty($normalized_group)) {
                $normalized[] = $normalized_group;
            }
        }

        return $normalized;
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @return array<int,string>
     */
    private static function collect_field_names(array $fields): array
    {
        $names = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = isset($field['name']) ? (string) $field['name'] : '';
            if ($name !== '') {
                $names[] = $name;
            }

            if (! empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                $names = array_merge($names, self::collect_field_names($field['sub_fields']));
            }

            if (! empty($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layout) {
                    if (! is_array($layout) || empty($layout['sub_fields']) || ! is_array($layout['sub_fields'])) {
                        continue;
                    }

                    $names = array_merge($names, self::collect_field_names($layout['sub_fields']));
                }
            }
        }

        $names = array_values(array_unique(array_filter(array_map('strval', $names))));
        sort($names);

        return $names;
    }

    /**
     * @param mixed $choices
     * @return array<string,string>
     */
    private static function normalize_choices($choices): array
    {
        if (! is_array($choices)) {
            return [];
        }

        $normalized = [];
        foreach ($choices as $choice_key => $choice_label) {
            $key = is_scalar($choice_key) ? (string) $choice_key : '';
            $label = is_scalar($choice_label) ? (string) $choice_label : '';
            if ($key === '' && $label === '') {
                continue;
            }

            if ($key === '') {
                $key = $label;
            }

            $normalized[$key] = $label;
        }

        ksort($normalized);
        return $normalized;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalize_value($value)
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalize_value($item);
            }

            return $normalized;
        }

        if (is_object($value)) {
            return self::normalize_value((array) $value);
        }

        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
            return $value;
        }

        return null;
    }

    /**
     * @param mixed $values
     * @return array<int,string>
     */
    private static function sanitize_string_array($values): array
    {
        if (is_scalar($values)) {
            $values = [$values];
        }

        if (! is_array($values)) {
            return [];
        }

        $sanitized = [];
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $string = sanitize_key((string) $value);
            if ($string === '') {
                continue;
            }

            $sanitized[] = $string;
        }

        $sanitized = array_values(array_unique($sanitized));
        sort($sanitized);

        return $sanitized;
    }

    /**
     * @param string $directory
     * @param string $subdirectory
     * @return array<int,string>
     */
    private static function collect_json_files(string $directory, string $subdirectory): array
    {
        $target_directory = wp_normalize_path(trailingslashit($directory) . trim($subdirectory, '/\\'));
        if (! is_dir($target_directory)) {
            return [];
        }

        $files = glob(trailingslashit($target_directory) . '*.json');
        if (! is_array($files)) {
            return [];
        }

        $normalized = array_map('wp_normalize_path', $files);
        sort($normalized);

        return array_values($normalized);
    }

    /**
     * @param string $file_path
     * @return array<string,mixed>|null
     */
    private static function read_json_file(string $file_path): ?array
    {
        if (! is_file($file_path) || ! is_readable($file_path)) {
            return null;
        }

        $contents = file_get_contents($file_path);
        if ($contents === false || $contents === '') {
            return null;
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : null;
    }
}
