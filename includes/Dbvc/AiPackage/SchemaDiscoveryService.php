<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class SchemaDiscoveryService
{
    private const SHAPE_MODE_CONSERVATIVE = 'conservative';
    private const SHAPE_MODE_OBSERVED = 'observed_shape';

    /**
     * Build the current schema bundle used by sample package generation and validation.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function build_schema_bundle(array $args = []): array
    {
        $shape_mode = self::resolve_shape_mode($args);
        $selection = self::resolve_selection($args);
        $acf_discovery = AcfDiscoveryService::discover();
        $observed_shape = [];
        if ($shape_mode === self::SHAPE_MODE_OBSERVED && class_exists(__NAMESPACE__ . '\\ObservedShapeService')) {
            $observed_shape = ObservedShapeService::collect($selection, self::resolve_observed_scan_cap($args));
        }

        $object_inventory = self::build_object_inventory($selection, $acf_discovery);
        $field_catalog = self::build_field_catalog($selection, $acf_discovery, $object_inventory, $observed_shape);

        return [
            'generated_at' => current_time('c'),
            'shape_mode' => $shape_mode,
            'selection' => $selection,
            'sources' => [
                'acf' => isset($acf_discovery['sources']) && is_array($acf_discovery['sources']) ? $acf_discovery['sources'] : [],
            ],
            'object_inventory' => $object_inventory,
            'field_catalog' => $field_catalog,
            'validation_rules' => class_exists(__NAMESPACE__ . '\\RulesService') ? RulesService::build_validation_rules(
                [
                    'shape_mode' => $shape_mode,
                    'selection' => $selection,
                ]
            ) : [],
            'observed_shape' => $observed_shape,
            'stats' => [
                'post_type_count' => count($object_inventory['post_types']),
                'taxonomy_count' => count($object_inventory['taxonomies']),
                'registered_post_meta_count' => self::count_registered_meta($field_catalog['post_types']),
                'registered_term_meta_count' => self::count_registered_meta($field_catalog['taxonomies']),
                'acf_group_count' => self::count_acf_groups($field_catalog['post_types']) + self::count_acf_groups($field_catalog['taxonomies']),
                'observed_post_meta_count' => self::count_observed_meta($field_catalog['post_types']),
                'observed_term_meta_count' => self::count_observed_meta($field_catalog['taxonomies']),
                'validation_rule_group_count' => class_exists(__NAMESPACE__ . '\\RulesService') ? count(RulesService::build_validation_rules()) : 0,
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function get_post_core_field_catalog(): array
    {
        return [
            'ID' => [
                'type' => 'integer',
                'required' => true,
                'description' => __('Neutral create marker (`0`) or matched local post ID for update payloads.', 'dbvc'),
            ],
            'post_type' => [
                'type' => 'string',
                'required' => true,
                'description' => __('Registered post type slug.', 'dbvc'),
            ],
            'post_title' => [
                'type' => 'string',
                'required' => true,
                'description' => __('Human-readable entity title.', 'dbvc'),
            ],
            'post_name' => [
                'type' => 'string',
                'required' => true,
                'description' => __('Slug used for deterministic matching and filename generation.', 'dbvc'),
            ],
            'post_status' => [
                'type' => 'string',
                'required' => false,
            ],
            'post_content' => [
                'type' => 'string',
                'required' => false,
            ],
            'post_excerpt' => [
                'type' => 'string',
                'required' => false,
            ],
            'post_date' => [
                'type' => 'string',
                'required' => false,
            ],
            'post_date_gmt' => [
                'type' => 'string',
                'required' => false,
            ],
            'post_parent' => [
                'type' => 'integer',
                'required' => false,
            ],
            'menu_order' => [
                'type' => 'integer',
                'required' => false,
            ],
            'post_author' => [
                'type' => 'integer',
                'required' => false,
            ],
            'post_password' => [
                'type' => 'string',
                'required' => false,
            ],
            'comment_status' => [
                'type' => 'string',
                'required' => false,
            ],
            'ping_status' => [
                'type' => 'string',
                'required' => false,
            ],
            'meta' => [
                'type' => 'object',
                'required' => false,
                'description' => __('Arbitrary meta payload keyed by meta key or logical ACF field name.', 'dbvc'),
            ],
            'tax_input' => [
                'type' => 'object',
                'required' => false,
                'description' => __('Taxonomy assignments keyed by taxonomy slug.', 'dbvc'),
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function get_term_core_field_catalog(): array
    {
        return [
            'term_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => __('Neutral create marker (`0`) or matched local term ID for update payloads.', 'dbvc'),
            ],
            'taxonomy' => [
                'type' => 'string',
                'required' => true,
                'description' => __('Registered taxonomy slug.', 'dbvc'),
            ],
            'name' => [
                'type' => 'string',
                'required' => true,
            ],
            'slug' => [
                'type' => 'string',
                'required' => true,
            ],
            'description' => [
                'type' => 'string',
                'required' => false,
            ],
            'parent' => [
                'type' => 'integer',
                'required' => false,
            ],
            'parent_slug' => [
                'type' => 'string',
                'required' => false,
            ],
            'meta' => [
                'type' => 'object',
                'required' => false,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $selection
     * @param array<string,mixed> $acf_discovery
     * @return array<string,mixed>
     */
    private static function build_object_inventory(array $selection, array $acf_discovery): array
    {
        $runtime_post_types = self::get_runtime_post_types();
        $runtime_taxonomies = self::get_runtime_taxonomies();
        $acf_post_types = isset($acf_discovery['post_types']) && is_array($acf_discovery['post_types']) ? $acf_discovery['post_types'] : [];
        $acf_taxonomies = isset($acf_discovery['taxonomies']) && is_array($acf_discovery['taxonomies']) ? $acf_discovery['taxonomies'] : [];
        $object_map = isset($acf_discovery['object_map']) && is_array($acf_discovery['object_map']) ? $acf_discovery['object_map'] : [];

        $post_types = [];
        foreach ($selection['post_types'] as $post_type) {
            if (! isset($runtime_post_types[$post_type])) {
                continue;
            }

            $post_type_object = $runtime_post_types[$post_type];
            $acf_entry = isset($acf_post_types[$post_type]) && is_array($acf_post_types[$post_type]) ? $acf_post_types[$post_type] : [];
            $supports = get_all_post_type_supports($post_type);
            $supports = is_array($supports) ? array_keys($supports) : [];
            $taxonomies = get_object_taxonomies($post_type);
            $taxonomies = is_array($taxonomies) ? $taxonomies : [];

            $post_types[$post_type] = [
                'slug' => $post_type,
                'label' => isset($post_type_object->label) ? (string) $post_type_object->label : (string) ($acf_entry['label'] ?? $post_type),
                'singular_label' => isset($post_type_object->labels->singular_name) ? (string) $post_type_object->labels->singular_name : (string) ($acf_entry['singular_label'] ?? $post_type),
                'description' => isset($post_type_object->description) ? (string) $post_type_object->description : (string) ($acf_entry['description'] ?? ''),
                'public' => ! empty($post_type_object->public),
                'hierarchical' => ! empty($post_type_object->hierarchical),
                'show_in_rest' => ! empty($post_type_object->show_in_rest),
                'rest_base' => isset($post_type_object->rest_base) ? (string) $post_type_object->rest_base : (string) ($acf_entry['rest_base'] ?? ''),
                'menu_icon' => isset($post_type_object->menu_icon) ? (string) $post_type_object->menu_icon : (string) ($acf_entry['menu_icon'] ?? ''),
                'supports' => self::normalize_string_array($supports),
                'taxonomies' => self::normalize_string_array($taxonomies),
                'acf_group_keys' => isset($object_map['post_types'][$post_type]) && is_array($object_map['post_types'][$post_type]) ? array_values($object_map['post_types'][$post_type]) : [],
                'sources' => [
                    'runtime' => true,
                    'acf_local_json' => ! empty($acf_entry),
                ],
            ];
        }

        $taxonomies = [];
        foreach ($selection['taxonomies'] as $taxonomy) {
            if (! isset($runtime_taxonomies[$taxonomy])) {
                continue;
            }

            $taxonomy_object = $runtime_taxonomies[$taxonomy];
            $acf_entry = isset($acf_taxonomies[$taxonomy]) && is_array($acf_taxonomies[$taxonomy]) ? $acf_taxonomies[$taxonomy] : [];

            $taxonomies[$taxonomy] = [
                'slug' => $taxonomy,
                'label' => isset($taxonomy_object->label) ? (string) $taxonomy_object->label : (string) ($acf_entry['label'] ?? $taxonomy),
                'singular_label' => isset($taxonomy_object->labels->singular_name) ? (string) $taxonomy_object->labels->singular_name : (string) ($acf_entry['singular_label'] ?? $taxonomy),
                'description' => isset($taxonomy_object->description) ? (string) $taxonomy_object->description : (string) ($acf_entry['description'] ?? ''),
                'public' => ! empty($taxonomy_object->public),
                'hierarchical' => ! empty($taxonomy_object->hierarchical),
                'show_in_rest' => ! empty($taxonomy_object->show_in_rest),
                'rest_base' => isset($taxonomy_object->rest_base) ? (string) $taxonomy_object->rest_base : (string) ($acf_entry['rest_base'] ?? ''),
                'object_type' => self::normalize_string_array(isset($taxonomy_object->object_type) && is_array($taxonomy_object->object_type) ? $taxonomy_object->object_type : (array) ($acf_entry['object_type'] ?? [])),
                'acf_group_keys' => isset($object_map['taxonomies'][$taxonomy]) && is_array($object_map['taxonomies'][$taxonomy]) ? array_values($object_map['taxonomies'][$taxonomy]) : [],
                'sources' => [
                    'runtime' => true,
                    'acf_local_json' => ! empty($acf_entry),
                ],
            ];
        }

        ksort($post_types);
        ksort($taxonomies);

        return [
            'generated_at' => current_time('c'),
            'selection' => $selection,
            'post_types' => $post_types,
            'taxonomies' => $taxonomies,
            'stats' => [
                'post_type_count' => count($post_types),
                'taxonomy_count' => count($taxonomies),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $selection
     * @param array<string,mixed> $acf_discovery
     * @param array<string,mixed> $object_inventory
     * @param array<string,mixed> $observed_shape
     * @return array<string,mixed>
     */
    private static function build_field_catalog(array $selection, array $acf_discovery, array $object_inventory, array $observed_shape = []): array
    {
        $all_groups = isset($acf_discovery['field_groups']) && is_array($acf_discovery['field_groups']) ? $acf_discovery['field_groups'] : [];
        $post_type_inventory = isset($object_inventory['post_types']) && is_array($object_inventory['post_types']) ? $object_inventory['post_types'] : [];
        $taxonomy_inventory = isset($object_inventory['taxonomies']) && is_array($object_inventory['taxonomies']) ? $object_inventory['taxonomies'] : [];
        $observed_posts = isset($observed_shape['post_types']) && is_array($observed_shape['post_types']) ? $observed_shape['post_types'] : [];
        $observed_taxonomies = isset($observed_shape['taxonomies']) && is_array($observed_shape['taxonomies']) ? $observed_shape['taxonomies'] : [];

        $post_types = [];
        foreach ($selection['post_types'] as $post_type) {
            if (! isset($post_type_inventory[$post_type])) {
                continue;
            }

            $post_types[$post_type] = [
                'core_fields' => self::get_post_core_field_catalog(),
                'tax_input' => self::build_tax_input_catalog($post_type),
                'registered_meta' => self::collect_registered_meta_catalog('post', $post_type),
                'observed_meta' => isset($observed_posts[$post_type]['meta_keys']) && is_array($observed_posts[$post_type]['meta_keys']) ? $observed_posts[$post_type]['meta_keys'] : [],
                'acf' => self::build_object_acf_catalog(
                    isset($post_type_inventory[$post_type]['acf_group_keys']) && is_array($post_type_inventory[$post_type]['acf_group_keys'])
                        ? $post_type_inventory[$post_type]['acf_group_keys']
                        : [],
                    $all_groups
                ),
            ];
        }

        $taxonomies = [];
        foreach ($selection['taxonomies'] as $taxonomy) {
            if (! isset($taxonomy_inventory[$taxonomy])) {
                continue;
            }

            $taxonomies[$taxonomy] = [
                'core_fields' => self::get_term_core_field_catalog(),
                'registered_meta' => self::collect_registered_meta_catalog('term', $taxonomy),
                'observed_meta' => isset($observed_taxonomies[$taxonomy]['meta_keys']) && is_array($observed_taxonomies[$taxonomy]['meta_keys']) ? $observed_taxonomies[$taxonomy]['meta_keys'] : [],
                'acf' => self::build_object_acf_catalog(
                    isset($taxonomy_inventory[$taxonomy]['acf_group_keys']) && is_array($taxonomy_inventory[$taxonomy]['acf_group_keys'])
                        ? $taxonomy_inventory[$taxonomy]['acf_group_keys']
                        : [],
                    $all_groups
                ),
            ];
        }

        ksort($post_types);
        ksort($taxonomies);

        return [
            'generated_at' => current_time('c'),
            'selection' => $selection,
            'post_types' => $post_types,
            'taxonomies' => $taxonomies,
        ];
    }

    /**
     * @param array<int,string>                     $group_keys
     * @param array<string,array<string,mixed>>     $all_groups
     * @return array<string,mixed>
     */
    private static function build_object_acf_catalog(array $group_keys, array $all_groups): array
    {
        $groups = [];
        $logical_field_names = [];
        $field_type_counts = [];

        foreach ($group_keys as $group_key) {
            if (! isset($all_groups[$group_key]) || ! is_array($all_groups[$group_key])) {
                continue;
            }

            $group = $all_groups[$group_key];
            $groups[$group_key] = $group;

            $group_names = isset($group['field_names']) && is_array($group['field_names']) ? $group['field_names'] : [];
            foreach ($group_names as $group_name) {
                $group_name = (string) $group_name;
                if ($group_name !== '') {
                    $logical_field_names[] = $group_name;
                }
            }

            self::accumulate_field_type_counts(isset($group['fields']) && is_array($group['fields']) ? $group['fields'] : [], $field_type_counts);
        }

        ksort($groups);
        $logical_field_names = array_values(array_unique(array_filter(array_map('strval', $logical_field_names))));
        sort($logical_field_names);
        ksort($field_type_counts);

        return [
            'group_keys' => array_keys($groups),
            'group_count' => count($groups),
            'groups' => $groups,
            'logical_field_names' => $logical_field_names,
            'field_type_counts' => $field_type_counts,
        ];
    }

    /**
     * @param string $post_type
     * @return array<string,array<string,mixed>>
     */
    private static function build_tax_input_catalog(string $post_type): array
    {
        $objects = get_object_taxonomies($post_type, 'objects');
        if (! is_array($objects)) {
            return [];
        }

        $catalog = [];
        foreach ($objects as $taxonomy => $object) {
            if (! ($object instanceof \WP_Taxonomy)) {
                continue;
            }

            $catalog[(string) $taxonomy] = [
                'taxonomy' => (string) $taxonomy,
                'label' => isset($object->labels->name) ? (string) $object->labels->name : (string) $taxonomy,
                'hierarchical' => ! empty($object->hierarchical),
                'accepted_reference_formats' => ['slug', 'object'],
            ];
        }

        ksort($catalog);
        return $catalog;
    }

    /**
     * @param string $object_type
     * @param string $object_subtype
     * @return array<string,array<string,mixed>>
     */
    private static function collect_registered_meta_catalog(string $object_type, string $object_subtype): array
    {
        if (! function_exists('get_registered_meta_keys')) {
            return [];
        }

        $registered = get_registered_meta_keys($object_type, $object_subtype);
        if (! is_array($registered)) {
            return [];
        }

        $catalog = [];
        foreach ($registered as $meta_key => $config) {
            if (! is_array($config)) {
                continue;
            }

            $catalog[(string) $meta_key] = [
                'meta_key' => (string) $meta_key,
                'type' => isset($config['type']) ? sanitize_key((string) $config['type']) : '',
                'single' => array_key_exists('single', $config) ? (bool) $config['single'] : false,
                'default' => self::normalize_value($config['default'] ?? null),
                'description' => isset($config['description']) ? (string) $config['description'] : '',
                'show_in_rest' => self::normalize_show_in_rest($config['show_in_rest'] ?? false),
                'has_auth_callback' => ! empty($config['auth_callback']),
                'has_sanitize_callback' => ! empty($config['sanitize_callback']),
                'revisions_enabled' => ! empty($config['revisions_enabled']),
                'protected' => strpos((string) $meta_key, '_') === 0,
            ];
        }

        ksort($catalog);
        return $catalog;
    }

    /**
     * @param mixed $show_in_rest
     * @return array<string,mixed>
     */
    private static function normalize_show_in_rest($show_in_rest): array
    {
        if (is_array($show_in_rest)) {
            return [
                'enabled' => true,
                'schema_type' => isset($show_in_rest['schema']['type']) ? sanitize_key((string) $show_in_rest['schema']['type']) : '',
            ];
        }

        return [
            'enabled' => (bool) $show_in_rest,
            'schema_type' => '',
        ];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function resolve_selection(array $args): array
    {
        $runtime_post_types = self::get_runtime_post_types();
        $runtime_taxonomies = self::get_runtime_taxonomies();
        $available_post_types = array_keys($runtime_post_types);
        $available_taxonomies = array_keys($runtime_taxonomies);

        $requested_post_types = self::normalize_string_array($args['post_types'] ?? []);
        $requested_taxonomies = self::normalize_string_array($args['taxonomies'] ?? []);

        $selected_post_types = ! empty($requested_post_types)
            ? array_values(array_intersect($requested_post_types, $available_post_types))
            : self::get_default_post_types($available_post_types);
        $selected_taxonomies = ! empty($requested_taxonomies)
            ? array_values(array_intersect($requested_taxonomies, $available_taxonomies))
            : self::get_default_taxonomies($available_taxonomies);

        sort($selected_post_types);
        sort($selected_taxonomies);

        return [
            'post_types' => $selected_post_types,
            'taxonomies' => $selected_taxonomies,
            'missing_post_types' => array_values(array_diff($requested_post_types, $available_post_types)),
            'missing_taxonomies' => array_values(array_diff($requested_taxonomies, $available_taxonomies)),
        ];
    }

    /**
     * @param array<string,mixed> $args
     * @return string
     */
    private static function resolve_shape_mode(array $args): string
    {
        $settings = Settings::get_all_settings();
        $value = isset($args['shape_mode']) ? sanitize_key((string) $args['shape_mode']) : '';
        if ($value === '' && isset($settings['generation']['shape_mode'])) {
            $value = sanitize_key((string) $settings['generation']['shape_mode']);
        }

        if ($value === self::SHAPE_MODE_OBSERVED) {
            return self::SHAPE_MODE_OBSERVED;
        }

        return self::SHAPE_MODE_CONSERVATIVE;
    }

    /**
     * @param array<string,mixed> $args
     * @return int
     */
    private static function resolve_observed_scan_cap(array $args): int
    {
        $settings = Settings::get_all_settings();
        $value = isset($args['observed_scan_cap']) ? absint($args['observed_scan_cap']) : 0;
        if ($value <= 0 && isset($settings['generation']['observed_scan_cap'])) {
            $value = absint($settings['generation']['observed_scan_cap']);
        }

        if ($value < Settings::MIN_OBSERVED_SCAN_CAP) {
            $value = Settings::MIN_OBSERVED_SCAN_CAP;
        } elseif ($value > Settings::MAX_OBSERVED_SCAN_CAP) {
            $value = Settings::MAX_OBSERVED_SCAN_CAP;
        }

        return $value;
    }

    /**
     * @return array<string,\WP_Post_Type|object>
     */
    private static function get_runtime_post_types(): array
    {
        if (function_exists('dbvc_get_available_post_types')) {
            $post_types = dbvc_get_available_post_types();
            if (is_array($post_types)) {
                return $post_types;
            }
        }

        $post_types = get_post_types(['public' => true], 'objects');
        if (! is_array($post_types)) {
            $post_types = [];
        }

        if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
            $post_types = array_merge(
                $post_types,
                [
                    'wp_template' => (object) [
                        'label' => __('Templates (FSE)', 'dbvc'),
                        'name' => 'wp_template',
                    ],
                    'wp_template_part' => (object) [
                        'label' => __('Template Parts (FSE)', 'dbvc'),
                        'name' => 'wp_template_part',
                    ],
                    'wp_global_styles' => (object) [
                        'label' => __('Global Styles (FSE)', 'dbvc'),
                        'name' => 'wp_global_styles',
                    ],
                    'wp_navigation' => (object) [
                        'label' => __('Navigation (FSE)', 'dbvc'),
                        'name' => 'wp_navigation',
                    ],
                ]
            );
        }

        return $post_types;
    }

    /**
     * @return array<string,\WP_Taxonomy>
     */
    private static function get_runtime_taxonomies(): array
    {
        if (function_exists('dbvc_get_available_taxonomies')) {
            $taxonomies = dbvc_get_available_taxonomies();
            if (is_array($taxonomies)) {
                return $taxonomies;
            }
        }

        $taxonomies = get_taxonomies(
            [
                'public' => true,
                'show_ui' => true,
            ],
            'objects'
        );

        return is_array($taxonomies) ? $taxonomies : [];
    }

    /**
     * @param array<int,string> $available_post_types
     * @return array<int,string>
     */
    private static function get_default_post_types(array $available_post_types): array
    {
        $selected = get_option('dbvc_post_types', []);
        if (! is_array($selected) || empty($selected)) {
            $selected = $available_post_types;
        }

        return array_values(array_intersect(self::normalize_string_array($selected), $available_post_types));
    }

    /**
     * @param array<int,string> $available_taxonomies
     * @return array<int,string>
     */
    private static function get_default_taxonomies(array $available_taxonomies): array
    {
        $selected = get_option('dbvc_taxonomies', []);
        if (! is_array($selected) || empty($selected)) {
            $selected = $available_taxonomies;
        }

        return array_values(array_intersect(self::normalize_string_array($selected), $available_taxonomies));
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,string>
     */
    private static function normalize_string_array($values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $string = sanitize_key((string) $value);
            if ($string === '') {
                continue;
            }

            $normalized[] = $string;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

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

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return null;
    }

    /**
     * @param array<string,array<string,mixed>> $entries
     * @return int
     */
    private static function count_registered_meta(array $entries): int
    {
        $count = 0;
        foreach ($entries as $entry) {
            if (! is_array($entry) || empty($entry['registered_meta']) || ! is_array($entry['registered_meta'])) {
                continue;
            }

            $count += count($entry['registered_meta']);
        }

        return $count;
    }

    /**
     * @param array<string,array<string,mixed>> $entries
     * @return int
     */
    private static function count_acf_groups(array $entries): int
    {
        $count = 0;
        foreach ($entries as $entry) {
            if (! is_array($entry) || empty($entry['acf']['group_count'])) {
                continue;
            }

            $count += (int) $entry['acf']['group_count'];
        }

        return $count;
    }

    /**
     * @param array<string,array<string,mixed>> $entries
     * @return int
     */
    private static function count_observed_meta(array $entries): int
    {
        $count = 0;
        foreach ($entries as $entry) {
            if (! is_array($entry) || empty($entry['observed_meta']) || ! is_array($entry['observed_meta'])) {
                continue;
            }

            $count += count($entry['observed_meta']);
        }

        return $count;
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @param array<string,int>              $counts
     * @return void
     */
    private static function accumulate_field_type_counts(array $fields, array &$counts): void
    {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
            if ($type !== '') {
                if (! isset($counts[$type])) {
                    $counts[$type] = 0;
                }

                $counts[$type]++;
            }

            if (! empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                self::accumulate_field_type_counts($field['sub_fields'], $counts);
            }

            if (! empty($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layout) {
                    if (! is_array($layout) || empty($layout['sub_fields']) || ! is_array($layout['sub_fields'])) {
                        continue;
                    }

                    self::accumulate_field_type_counts($layout['sub_fields'], $counts);
                }
            }
        }
    }
}
