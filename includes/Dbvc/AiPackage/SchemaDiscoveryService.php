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
        $context_providers = self::build_context_provider_bundle();
        $observed_shape = [];
        if ($shape_mode === self::SHAPE_MODE_OBSERVED && class_exists(__NAMESPACE__ . '\\ObservedShapeService')) {
            $observed_shape = ObservedShapeService::collect($selection, self::resolve_observed_scan_cap($args));
        }

        $object_inventory = self::build_object_inventory($selection, $acf_discovery, $context_providers);
        $field_catalog = self::build_field_catalog($selection, $acf_discovery, $object_inventory, $observed_shape, $context_providers);

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
     * @param array<string,mixed> $context_providers
     * @return array<string,mixed>
     */
    private static function build_object_inventory(array $selection, array $acf_discovery, array $context_providers = []): array
    {
        $runtime_post_types = self::get_runtime_post_types();
        $runtime_taxonomies = self::get_runtime_taxonomies();
        $acf_post_types = isset($acf_discovery['post_types']) && is_array($acf_discovery['post_types']) ? $acf_discovery['post_types'] : [];
        $acf_taxonomies = isset($acf_discovery['taxonomies']) && is_array($acf_discovery['taxonomies']) ? $acf_discovery['taxonomies'] : [];
        $object_map = isset($acf_discovery['object_map']) && is_array($acf_discovery['object_map']) ? $acf_discovery['object_map'] : [];
        $object_type_context_catalog = isset($context_providers['object_type_context_catalog']) && is_array($context_providers['object_type_context_catalog'])
            ? $context_providers['object_type_context_catalog']
            : [];

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
                'object_type_context' => self::resolve_object_type_context('post_type', $post_type, $object_type_context_catalog),
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
                'object_type_context' => self::resolve_object_type_context('taxonomy', $taxonomy, $object_type_context_catalog),
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
     * @param array<string,mixed> $context_providers
     * @return array<string,mixed>
     */
    private static function build_field_catalog(array $selection, array $acf_discovery, array $object_inventory, array $observed_shape = [], array $context_providers = []): array
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
                    $all_groups,
                    $context_providers
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
                    $all_groups,
                    $context_providers
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
     * @param array<string,mixed>                   $context_providers
     * @return array<string,mixed>
     */
    private static function build_object_acf_catalog(array $group_keys, array $all_groups, array $context_providers = []): array
    {
        $groups = [];
        $logical_field_names = [];
        $field_type_counts = [];

        foreach ($group_keys as $group_key) {
            if (! isset($all_groups[$group_key]) || ! is_array($all_groups[$group_key])) {
                continue;
            }

            $group = $all_groups[$group_key];
            $group = self::enrich_acf_group_with_context($group, $context_providers);
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
     * @return array<string,mixed>
     */
    private static function build_context_provider_bundle(): array
    {
        self::load_content_migration_context_services();

        $field_context_catalog = [];
        if (class_exists('\DBVC_CC_Field_Context_Provider_Service')) {
            try {
                $field_context_catalog = \DBVC_CC_Field_Context_Provider_Service::get_instance()->get_catalog([], 'mapping');
            } catch (\Throwable $e) {
                $field_context_catalog = [];
            }
        }

        $object_type_context_catalog = [];
        if (class_exists('\DBVC_CC_Object_Type_Context_Provider_Service')) {
            try {
                $object_type_context_catalog = \DBVC_CC_Object_Type_Context_Provider_Service::get_instance()->get_catalog([], 'mapping');
            } catch (\Throwable $e) {
                $object_type_context_catalog = [];
            }
        }

        return [
            'field_context_catalog' => $field_context_catalog,
            'object_type_context_catalog' => $object_type_context_catalog,
        ];
    }

    /**
     * @return void
     */
    private static function load_content_migration_context_services(): void
    {
        if (! defined('DBVC_PLUGIN_PATH')) {
            return;
        }

        $files = [
            'addons/content-migration/shared/dbvc-cc-field-context-provider-service.php',
            'addons/content-migration/shared/dbvc-cc-object-type-context-provider-service.php',
        ];

        foreach ($files as $relative_path) {
            $absolute_path = wp_normalize_path(trailingslashit(DBVC_PLUGIN_PATH) . $relative_path);
            if (is_file($absolute_path)) {
                require_once $absolute_path;
            }
        }
    }

    /**
     * @param string              $kind post_type|taxonomy
     * @param string              $object_key
     * @param array<string,mixed> $object_type_context_catalog
     * @return array<string,mixed>
     */
    private static function resolve_object_type_context(string $kind, string $object_key, array $object_type_context_catalog): array
    {
        $object_key = sanitize_key($object_key);
        if ($object_key === '') {
            return [];
        }

        if (class_exists('\DBVC_CC_Object_Type_Context_Provider_Service')) {
            if ($kind === 'taxonomy') {
                return self::build_authoring_context(
                    \DBVC_CC_Object_Type_Context_Provider_Service::get_instance()->get_taxonomy_context($object_key, $object_type_context_catalog)
                );
            }

            return self::build_authoring_context(
                \DBVC_CC_Object_Type_Context_Provider_Service::get_instance()->get_post_type_context($object_key, $object_type_context_catalog)
            );
        }

        $index_key = $kind === 'taxonomy' ? 'taxonomies_by_key' : 'post_types_by_key';
        $entries = isset($object_type_context_catalog[$index_key]) && is_array($object_type_context_catalog[$index_key])
            ? $object_type_context_catalog[$index_key]
            : [];

        return isset($entries[$object_key]) && is_array($entries[$object_key]) ? self::build_authoring_context($entries[$object_key]) : [];
    }

    /**
     * @param array<string,mixed> $object_context
     * @param array<string,mixed> $object_type_context_catalog
     * @return array<string,mixed>
     */
    private static function resolve_object_type_context_for_object_context(array $object_context, array $object_type_context_catalog): array
    {
        if (class_exists('\DBVC_CC_Object_Type_Context_Provider_Service')) {
            return self::build_authoring_context(
                \DBVC_CC_Object_Type_Context_Provider_Service::get_instance()->resolve_context_for_object_context($object_context, $object_type_context_catalog)
            );
        }

        $post_types = isset($object_context['post_types']) && is_array($object_context['post_types']) ? $object_context['post_types'] : [];
        foreach ($post_types as $post_type) {
            $context = self::resolve_object_type_context('post_type', (string) $post_type, $object_type_context_catalog);
            if (! empty($context)) {
                return $context;
            }
        }

        $taxonomies = isset($object_context['taxonomies']) && is_array($object_context['taxonomies']) ? $object_context['taxonomies'] : [];
        foreach ($taxonomies as $taxonomy) {
            $context = self::resolve_object_type_context('taxonomy', (string) $taxonomy, $object_type_context_catalog);
            if (! empty($context)) {
                return $context;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $group
     * @param array<string,mixed> $context_providers
     * @return array<string,mixed>
     */
    private static function enrich_acf_group_with_context(array $group, array $context_providers): array
    {
        $field_context_catalog = isset($context_providers['field_context_catalog']) && is_array($context_providers['field_context_catalog'])
            ? $context_providers['field_context_catalog']
            : [];
        $object_type_context_catalog = isset($context_providers['object_type_context_catalog']) && is_array($context_providers['object_type_context_catalog'])
            ? $context_providers['object_type_context_catalog']
            : [];
        $group_key = isset($group['key']) ? sanitize_key((string) $group['key']) : '';
        $provider_groups = isset($field_context_catalog['groups_by_key']) && is_array($field_context_catalog['groups_by_key'])
            ? $field_context_catalog['groups_by_key']
            : [];
        $provider_group = ($group_key !== '' && isset($provider_groups[$group_key]) && is_array($provider_groups[$group_key]))
            ? $provider_groups[$group_key]
            : [];

        $object_context = ! empty($provider_group['object_context']) && is_array($provider_group['object_context'])
            ? $provider_group['object_context']
            : self::normalize_object_context_from_location(isset($group['location']) && is_array($group['location']) ? $group['location'] : []);
        $object_type_context = self::resolve_object_type_context_for_object_context($object_context, $object_type_context_catalog);

        $group['group_name'] = ! empty($provider_group['group_name'])
            ? sanitize_key((string) $provider_group['group_name'])
            : sanitize_title((string) ($group['title'] ?? $group_key));
        $group['object_type_context'] = $object_type_context;
        $group['field_context'] = self::build_group_field_context($group, $provider_group);

        $fields = isset($group['fields']) && is_array($group['fields']) ? $group['fields'] : [];
        $group['fields'] = self::enrich_acf_fields_with_context($fields, $group, $field_context_catalog);

        return $group;
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @param array<string,mixed>            $group
     * @param array<string,mixed>            $field_context_catalog
     * @return array<int,array<string,mixed>>
     */
    private static function enrich_acf_fields_with_context(array $fields, array $group, array $field_context_catalog): array
    {
        foreach ($fields as $index => $field) {
            if (! is_array($field)) {
                continue;
            }

            $field = self::enrich_acf_field_with_context($field, $group, $field_context_catalog);

            if (! empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                $field['sub_fields'] = self::enrich_acf_fields_with_context($field['sub_fields'], $group, $field_context_catalog);
            }

            if (! empty($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layout_index => $layout) {
                    if (! is_array($layout) || empty($layout['sub_fields']) || ! is_array($layout['sub_fields'])) {
                        continue;
                    }

                    $layout['sub_fields'] = self::enrich_acf_fields_with_context($layout['sub_fields'], $group, $field_context_catalog);
                    $field['layouts'][$layout_index] = $layout;
                }
            }

            $fields[$index] = $field;
        }

        return $fields;
    }

    /**
     * @param array<string,mixed> $field
     * @param array<string,mixed> $group
     * @param array<string,mixed> $field_context_catalog
     * @return array<string,mixed>
     */
    private static function enrich_acf_field_with_context(array $field, array $group, array $field_context_catalog): array
    {
        $group_key = isset($group['key']) ? sanitize_key((string) $group['key']) : '';
        $field_key = isset($field['key']) ? sanitize_key((string) $field['key']) : '';
        $provider_entries = isset($field_context_catalog['entries_by_group_and_acf_key']) && is_array($field_context_catalog['entries_by_group_and_acf_key'])
            ? $field_context_catalog['entries_by_group_and_acf_key']
            : [];
        $provider_entry = ($group_key !== '' && $field_key !== '' && isset($provider_entries[$group_key][$field_key]) && is_array($provider_entries[$group_key][$field_key]))
            ? $provider_entries[$group_key][$field_key]
            : [];

        $field['field_context'] = self::build_field_entry_context($field, $provider_entry);

        return $field;
    }

    /**
     * @param array<string,mixed> $group
     * @param array<string,mixed> $provider_group
     * @return array<string,mixed>
     */
    private static function build_group_field_context(array $group, array $provider_group): array
    {
        $context = self::select_authoring_context_text($provider_group);
        if ($context === '') {
            $context = self::extract_runtime_purpose($group);
        }

        return self::build_compact_authoring_context($provider_group, $context, isset($group['type']) ? (string) $group['type'] : '');
    }

    /**
     * @param array<string,mixed> $field
     * @param array<string,mixed> $provider_entry
     * @return array<string,mixed>
     */
    private static function build_field_entry_context(array $field, array $provider_entry): array
    {
        $context = self::select_authoring_context_text($provider_entry);
        if ($context === '') {
            $context = self::extract_runtime_purpose($field);
        }

        return self::build_compact_authoring_context($provider_entry, $context, isset($field['type']) ? (string) $field['type'] : '');
    }

    /**
     * @param array<string,mixed> $source
     * @param string              $context
     * @param string              $field_type
     * @return array<string,mixed>
     */
    private static function build_compact_authoring_context(array $source, string $context, string $field_type = ''): array
    {
        $authoring_surface = self::normalize_authoring_surface($source['authoring_surface'] ?? '');
        $cross_site_safety = self::normalize_cross_site_safety($source['cross_site_safety'] ?? '');
        if ($cross_site_safety === '') {
            $cross_site_safety = self::infer_cross_site_safety($field_type, $authoring_surface);
        }

        $authoring_priority = self::normalize_authoring_priority($source['authoring_priority'] ?? '');
        if ($authoring_priority === '' && in_array($cross_site_safety, ['media_deferred', 'admin_or_editor'], true)) {
            $authoring_priority = 'do_not_author';
        }

        $context_payload = [];
        if ($context !== '') {
            $context_payload['context'] = $context;
        }
        if ($cross_site_safety !== '') {
            $context_payload['cross_site_safety'] = $cross_site_safety;
        }
        if ($authoring_surface !== '') {
            $context_payload['authoring_surface'] = $authoring_surface;
        }
        if ($authoring_priority !== '') {
            $context_payload['authoring_priority'] = $authoring_priority;
        }
        if (! empty($source['authoring_note']) && is_scalar($source['authoring_note'])) {
            $context_payload['authoring_note'] = sanitize_textarea_field((string) $source['authoring_note']);
        }

        $choice_meaning = self::normalize_context_string_map($source['choice_meaning'] ?? []);
        if (! empty($choice_meaning)) {
            $context_payload['choice_meaning'] = $choice_meaning;
        }

        if (! empty($source['shared_group_id']) && is_scalar($source['shared_group_id'])) {
            $context_payload['shared_group_id'] = sanitize_text_field((string) $source['shared_group_id']);
        }
        if (! empty($source['shared_group_label']) && is_scalar($source['shared_group_label'])) {
            $context_payload['shared_group_label'] = sanitize_text_field((string) $source['shared_group_label']);
        }

        $section_selection = self::normalize_section_selection_context($source['section_selection'] ?? []);
        if (! empty($section_selection)) {
            $context_payload['section_selection'] = $section_selection;
        }

        return $context_payload;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function normalize_cross_site_safety($value): string
    {
        $value = sanitize_key((string) $value);
        return in_array($value, ['portable', 'site_specific', 'media_deferred', 'admin_or_editor'], true) ? $value : '';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function normalize_authoring_surface($value): string
    {
        $value = sanitize_key((string) $value);
        return in_array($value, ['content', 'seo', 'cta', 'media', 'relationship', 'style_token', 'operator_control', 'site_config'], true) ? $value : '';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function normalize_authoring_priority($value): string
    {
        $value = sanitize_key((string) $value);
        return in_array($value, ['design_expected', 'recommended', 'optional', 'do_not_author'], true) ? $value : '';
    }

    private static function infer_cross_site_safety(string $field_type, string $authoring_surface): string
    {
        $field_type = sanitize_key($field_type);
        if (in_array($field_type, ['image', 'file', 'gallery'], true)) {
            return 'media_deferred';
        }
        if ($authoring_surface === 'media') {
            return 'media_deferred';
        }
        if ($authoring_surface === 'style_token') {
            return 'site_specific';
        }
        if (in_array($authoring_surface, ['operator_control', 'site_config'], true)) {
            return 'admin_or_editor';
        }

        return '';
    }

    /**
     * @param mixed $map
     * @return array<string,string>
     */
    private static function normalize_context_string_map($map): array
    {
        $normalized = [];
        if (! is_array($map)) {
            return $normalized;
        }

        foreach ($map as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $key = sanitize_text_field((string) $key);
            if ($key === '') {
                continue;
            }

            $normalized[$key] = sanitize_text_field((string) $value);
        }

        ksort($normalized);
        return $normalized;
    }

    /**
     * @param mixed $selection
     * @return array<string,mixed>
     */
    private static function normalize_section_selection_context($selection): array
    {
        if (! is_array($selection)) {
            return [];
        }

        $normalized = [
            'available' => ! empty($selection['available']),
            'field_key' => isset($selection['field_key']) && is_scalar($selection['field_key']) ? sanitize_text_field((string) $selection['field_key']) : '',
            'field_name' => isset($selection['field_name']) && is_scalar($selection['field_name']) ? sanitize_key((string) $selection['field_name']) : '',
            'controls_frontend_sections' => ! empty($selection['controls_frontend_sections']),
            'source_of_truth' => ! empty($selection['source_of_truth']),
            'default_values' => isset($selection['default_values']) && is_array($selection['default_values'])
                ? array_values(array_filter(array_map('sanitize_key', $selection['default_values'])))
                : [],
            'choices' => self::normalize_context_string_map($selection['choices'] ?? []),
            'section_group_map' => self::normalize_context_string_map($selection['section_group_map'] ?? []),
        ];

        if (! empty($selection['note']) && is_scalar($selection['note'])) {
            $normalized['note'] = sanitize_textarea_field((string) $selection['note']);
        }

        return array_filter($normalized, static function ($value) {
            return $value !== '' && $value !== [] && $value !== false;
        });
    }

    /**
     * @param array<int,mixed> $location
     * @return array<string,mixed>
     */
    private static function normalize_object_context_from_location(array $location): array
    {
        if (class_exists('\DBVC_CC_Field_Context_Provider_Service')) {
            return \DBVC_CC_Field_Context_Provider_Service::get_instance()->normalize_object_context($location);
        }

        $object_context = [
            'post_types' => [],
            'taxonomies' => [],
            'options_pages' => [],
            'unknown_rules' => [],
        ];

        foreach ($location as $rule_group) {
            if (! is_array($rule_group)) {
                continue;
            }

            foreach ($rule_group as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                $param = sanitize_key((string) ($rule['param'] ?? ''));
                $operator = sanitize_text_field((string) ($rule['operator'] ?? ''));
                $value = sanitize_key((string) ($rule['value'] ?? ''));
                if ($param === '' || $operator !== '==' || $value === '') {
                    continue;
                }

                if ($param === 'post_type') {
                    $object_context['post_types'][] = $value;
                } elseif ($param === 'taxonomy') {
                    $object_context['taxonomies'][] = $value;
                } elseif (in_array($param, ['options_page', 'options_page_key'], true)) {
                    $object_context['options_pages'][] = $value;
                } else {
                    $object_context['unknown_rules'][] = [
                        'param' => $param,
                        'operator' => $operator,
                        'value' => $value,
                    ];
                }
            }
        }

        $object_context['post_types'] = array_values(array_unique($object_context['post_types']));
        $object_context['taxonomies'] = array_values(array_unique($object_context['taxonomies']));
        $object_context['options_pages'] = array_values(array_unique($object_context['options_pages']));

        return $object_context;
    }

    /**
     * @param array<string,mixed> $source
     * @return string
     */
    private static function extract_runtime_purpose(array $source): string
    {
        $purpose = self::select_authoring_context_text($source);
        if ($purpose !== '') {
            return $purpose;
        }

        foreach (['purpose', 'gardenai_field_purpose'] as $key) {
            if (! empty($source[$key])) {
                return sanitize_textarea_field((string) $source[$key]);
            }
        }

        foreach (['field_context', 'vf_field_context', 'context', 'default_context'] as $nested_key) {
            if (isset($source[$nested_key]) && is_array($source[$nested_key])) {
                $purpose = self::extract_runtime_purpose($source[$nested_key]);
                if ($purpose !== '') {
                    return $purpose;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,string>
     */
    private static function build_authoring_context(array $source): array
    {
        $context = self::select_authoring_context_text($source);
        $payload = [];

        if ($context !== '') {
            $payload['context'] = $context;
        }

        $authoring_profile = self::normalize_authoring_profile($source['authoring_profile'] ?? '');
        if ($authoring_profile !== '') {
            $payload['authoring_profile'] = $authoring_profile;
        }

        if (! empty($source['routing_hint']) && is_scalar($source['routing_hint'])) {
            $payload['routing_hint'] = sanitize_textarea_field((string) $source['routing_hint']);
        }

        $section_authoring = self::normalize_section_authoring_context($source['section_authoring'] ?? []);
        if (! empty($section_authoring)) {
            $payload['section_authoring'] = $section_authoring;
        }

        return $payload;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function normalize_authoring_profile($value): string
    {
        $value = sanitize_key((string) $value);
        return in_array($value, ['essentials', 'extended', 'all'], true) ? $value : '';
    }

    /**
     * @param mixed $section_authoring
     * @return array<string,mixed>
     */
    private static function normalize_section_authoring_context($section_authoring): array
    {
        if (! is_array($section_authoring)) {
            return [];
        }

        $normalized = [
            'available' => ! empty($section_authoring['available']),
            'control_field' => isset($section_authoring['control_field']) && is_scalar($section_authoring['control_field']) ? sanitize_key((string) $section_authoring['control_field']) : '',
            'field_context_required' => ! empty($section_authoring['field_context_required']),
        ];

        if (! empty($section_authoring['note']) && is_scalar($section_authoring['note'])) {
            $normalized['note'] = sanitize_textarea_field((string) $section_authoring['note']);
        }

        return array_filter($normalized, static function ($value) {
            return $value !== '' && $value !== [] && $value !== false;
        });
    }

    /**
     * @param array<string,mixed> $source
     * @return string
     */
    private static function select_authoring_context_text(array $source): string
    {
        foreach (['resolved_purpose', 'effective_purpose', 'default_purpose', 'context'] as $key) {
            if (! empty($source[$key]) && is_scalar($source[$key])) {
                return sanitize_textarea_field((string) $source[$key]);
            }
        }

        return '';
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
