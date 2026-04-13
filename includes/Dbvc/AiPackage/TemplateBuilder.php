<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class TemplateBuilder
{
    private const VALUE_STYLE_BLANK = 'blank';
    private const VALUE_STYLE_DUMMY = 'dummy';
    private const VARIANT_SINGLE = 'single';
    private const VARIANT_MINIMAL = 'minimal';
    private const VARIANT_TYPICAL = 'typical';
    private const VARIANT_MAXIMAL = 'maximal';
    private const VARIANT_FULL_SET = 'full_set';

    /**
     * Build sample templates and field context for the current schema bundle.
     *
     * @param array<string,mixed> $schema_bundle
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function build_templates(array $schema_bundle, array $args = []): array
    {
        $value_style = self::resolve_value_style($args);
        $variant_set = self::resolve_variant_set($args);
        $shape_mode = isset($schema_bundle['shape_mode']) ? sanitize_key((string) $schema_bundle['shape_mode']) : 'conservative';
        $object_inventory = isset($schema_bundle['object_inventory']) && is_array($schema_bundle['object_inventory']) ? $schema_bundle['object_inventory'] : [];
        $field_catalog = isset($schema_bundle['field_catalog']) && is_array($schema_bundle['field_catalog']) ? $schema_bundle['field_catalog'] : [];
        $variants = self::resolve_variants($variant_set);
        $acf_resolution = self::build_acf_resolution_context($field_catalog);

        $post_type_inventory = isset($object_inventory['post_types']) && is_array($object_inventory['post_types']) ? $object_inventory['post_types'] : [];
        $taxonomy_inventory = isset($object_inventory['taxonomies']) && is_array($object_inventory['taxonomies']) ? $object_inventory['taxonomies'] : [];
        $post_type_catalog = isset($field_catalog['post_types']) && is_array($field_catalog['post_types']) ? $field_catalog['post_types'] : [];
        $taxonomy_catalog = isset($field_catalog['taxonomies']) && is_array($field_catalog['taxonomies']) ? $field_catalog['taxonomies'] : [];

        $post_types = [];
        foreach ($post_type_inventory as $post_type => $inventory_entry) {
            if (! isset($post_type_catalog[$post_type]) || ! is_array($post_type_catalog[$post_type])) {
                continue;
            }

            $post_types[$post_type] = self::build_post_type_template_set(
                $post_type,
                is_array($inventory_entry) ? $inventory_entry : [],
                $post_type_catalog[$post_type],
                $variants,
                $value_style,
                $shape_mode,
                $acf_resolution
            );
        }

        $taxonomies = [];
        foreach ($taxonomy_inventory as $taxonomy => $inventory_entry) {
            if (! isset($taxonomy_catalog[$taxonomy]) || ! is_array($taxonomy_catalog[$taxonomy])) {
                continue;
            }

            $taxonomies[$taxonomy] = self::build_taxonomy_template_set(
                $taxonomy,
                is_array($inventory_entry) ? $inventory_entry : [],
                $taxonomy_catalog[$taxonomy],
                $variants,
                $value_style,
                $shape_mode,
                $acf_resolution
            );
        }

        ksort($post_types);
        ksort($taxonomies);

        return [
            'generated_at' => current_time('c'),
            'shape_mode' => $shape_mode,
            'value_style' => $value_style,
            'variant_set' => $variant_set,
            'variants' => $variants,
            'post_types' => $post_types,
            'taxonomies' => $taxonomies,
        ];
    }

    /**
     * @param string              $post_type
     * @param array<string,mixed> $inventory_entry
     * @param array<string,mixed> $catalog_entry
     * @param array<int,string>   $variants
     * @param string              $value_style
     * @param string              $shape_mode
     * @param array<string,mixed> $acf_resolution
     * @return array<string,mixed>
     */
    private static function build_post_type_template_set(
        string $post_type,
        array $inventory_entry,
        array $catalog_entry,
        array $variants,
        string $value_style,
        string $shape_mode,
        array $acf_resolution
    ): array {
        $variant_payloads = [];
        foreach ($variants as $variant) {
            $template = self::build_post_template($post_type, $inventory_entry, $catalog_entry, $variant, $value_style, $shape_mode, $acf_resolution);
            $variant_payloads[$variant] = $template;
        }

        return [
            'label' => isset($inventory_entry['label']) ? (string) $inventory_entry['label'] : $post_type,
            'singular_label' => isset($inventory_entry['singular_label']) ? (string) $inventory_entry['singular_label'] : $post_type,
            'variants' => $variant_payloads,
        ];
    }

    /**
     * @param string              $taxonomy
     * @param array<string,mixed> $inventory_entry
     * @param array<string,mixed> $catalog_entry
     * @param array<int,string>   $variants
     * @param string              $value_style
     * @param string              $shape_mode
     * @param array<string,mixed> $acf_resolution
     * @return array<string,mixed>
     */
    private static function build_taxonomy_template_set(
        string $taxonomy,
        array $inventory_entry,
        array $catalog_entry,
        array $variants,
        string $value_style,
        string $shape_mode,
        array $acf_resolution
    ): array {
        $variant_payloads = [];
        foreach ($variants as $variant) {
            $template = self::build_term_template($taxonomy, $inventory_entry, $catalog_entry, $variant, $value_style, $shape_mode, $acf_resolution);
            $variant_payloads[$variant] = $template;
        }

        return [
            'label' => isset($inventory_entry['label']) ? (string) $inventory_entry['label'] : $taxonomy,
            'singular_label' => isset($inventory_entry['singular_label']) ? (string) $inventory_entry['singular_label'] : $taxonomy,
            'variants' => $variant_payloads,
        ];
    }

    /**
     * @param string              $post_type
     * @param array<string,mixed> $inventory_entry
     * @param array<string,mixed> $catalog_entry
     * @param string              $variant
     * @param string              $value_style
     * @param string              $shape_mode
     * @param array<string,mixed> $acf_resolution
     * @return array<string,mixed>
     */
    private static function build_post_template(
        string $post_type,
        array $inventory_entry,
        array $catalog_entry,
        string $variant,
        string $value_style,
        string $shape_mode,
        array $acf_resolution
    ): array {
        $singular_label = isset($inventory_entry['singular_label']) ? (string) $inventory_entry['singular_label'] : $post_type;
        $supports = isset($inventory_entry['supports']) && is_array($inventory_entry['supports']) ? $inventory_entry['supports'] : [];
        $is_hierarchical = ! empty($inventory_entry['hierarchical']);

        $template = [
            'ID' => 0,
            'post_type' => $post_type,
            'post_title' => self::build_core_string_placeholder('post_title', $singular_label, $post_type, $value_style),
            'post_name' => self::build_core_slug_placeholder($post_type, $value_style),
        ];

        if (self::should_include_post_field('post_status', $variant, $supports, $is_hierarchical)) {
            $template['post_status'] = 'draft';
        }

        if (self::should_include_post_field('post_content', $variant, $supports, $is_hierarchical)) {
            $template['post_content'] = self::build_core_string_placeholder('post_content', $singular_label, $post_type, $value_style);
        }

        if (self::should_include_post_field('post_excerpt', $variant, $supports, $is_hierarchical)) {
            $template['post_excerpt'] = self::build_core_string_placeholder('post_excerpt', $singular_label, $post_type, $value_style);
        }

        if (self::should_include_post_field('post_date', $variant, $supports, $is_hierarchical)) {
            $template['post_date'] = '';
        }

        if (self::should_include_post_field('post_date_gmt', $variant, $supports, $is_hierarchical)) {
            $template['post_date_gmt'] = '';
        }

        if (self::should_include_post_field('post_parent', $variant, $supports, $is_hierarchical)) {
            $template['post_parent'] = 0;
        }

        if (self::should_include_post_field('menu_order', $variant, $supports, $is_hierarchical)) {
            $template['menu_order'] = 0;
        }

        if (self::should_include_post_field('post_author', $variant, $supports, $is_hierarchical)) {
            $template['post_author'] = 0;
        }

        if (self::should_include_post_field('post_password', $variant, $supports, $is_hierarchical)) {
            $template['post_password'] = '';
        }

        if (self::should_include_post_field('comment_status', $variant, $supports, $is_hierarchical)) {
            $template['comment_status'] = 'closed';
        }

        if (self::should_include_post_field('ping_status', $variant, $supports, $is_hierarchical)) {
            $template['ping_status'] = 'closed';
        }

        $meta = self::build_meta_template($catalog_entry, $variant, $value_style, $shape_mode, $acf_resolution);
        if (! empty($meta) && self::should_include_meta_block($variant)) {
            $template['meta'] = $meta['values'];
        }

        $tax_input = self::build_tax_input_template($catalog_entry, $variant);
        if (! empty($tax_input) && self::should_include_tax_input_block($variant)) {
            $template['tax_input'] = $tax_input;
        }

        return [
            'template' => $template,
            'context' => [
                'core_fields' => $catalog_entry['core_fields'] ?? [],
                'meta' => $meta['context'] ?? [],
                'tax_input' => $tax_input,
                'shape_mode' => $shape_mode,
                'variant' => $variant,
                'value_style' => $value_style,
                'post_type' => $post_type,
                'label' => isset($inventory_entry['label']) ? (string) $inventory_entry['label'] : $post_type,
                'singular_label' => $singular_label,
            ],
        ];
    }

    /**
     * @param string              $taxonomy
     * @param array<string,mixed> $inventory_entry
     * @param array<string,mixed> $catalog_entry
     * @param string              $variant
     * @param string              $value_style
     * @param string              $shape_mode
     * @param array<string,mixed> $acf_resolution
     * @return array<string,mixed>
     */
    private static function build_term_template(
        string $taxonomy,
        array $inventory_entry,
        array $catalog_entry,
        string $variant,
        string $value_style,
        string $shape_mode,
        array $acf_resolution
    ): array {
        $singular_label = isset($inventory_entry['singular_label']) ? (string) $inventory_entry['singular_label'] : $taxonomy;
        $is_hierarchical = ! empty($inventory_entry['hierarchical']);

        $template = [
            'term_id' => 0,
            'taxonomy' => $taxonomy,
            'name' => self::build_term_name_placeholder($singular_label, $taxonomy, $value_style),
            'slug' => self::build_core_slug_placeholder($taxonomy, $value_style),
        ];

        if (self::should_include_term_field('description', $variant)) {
            $template['description'] = self::build_core_string_placeholder('term_description', $singular_label, $taxonomy, $value_style);
        }

        if ($is_hierarchical && self::should_include_term_field('parent_slug', $variant)) {
            $template['parent_slug'] = '';
        }

        if ($is_hierarchical && self::should_include_term_field('parent', $variant)) {
            $template['parent'] = 0;
        }

        $meta = self::build_meta_template($catalog_entry, $variant, $value_style, $shape_mode, $acf_resolution);
        if (! empty($meta) && self::should_include_meta_block($variant)) {
            $template['meta'] = $meta['values'];
        }

        return [
            'template' => $template,
            'context' => [
                'core_fields' => $catalog_entry['core_fields'] ?? [],
                'meta' => $meta['context'] ?? [],
                'shape_mode' => $shape_mode,
                'variant' => $variant,
                'value_style' => $value_style,
                'taxonomy' => $taxonomy,
                'label' => isset($inventory_entry['label']) ? (string) $inventory_entry['label'] : $taxonomy,
                'singular_label' => $singular_label,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $catalog_entry
     * @param string              $variant
     * @param string              $value_style
     * @param string              $shape_mode
     * @param array<string,mixed> $acf_resolution
     * @return array<string,mixed>
     */
    private static function build_meta_template(array $catalog_entry, string $variant, string $value_style, string $shape_mode, array $acf_resolution): array
    {
        $values = [];
        $context = [];

        $registered_meta = isset($catalog_entry['registered_meta']) && is_array($catalog_entry['registered_meta']) ? $catalog_entry['registered_meta'] : [];
        if ($variant !== self::VARIANT_MINIMAL) {
            foreach ($registered_meta as $meta_key => $meta_definition) {
                if (! is_array($meta_definition)) {
                    continue;
                }

                $values[$meta_key] = self::build_registered_meta_placeholder($meta_definition, $value_style);
                $context[$meta_key] = [
                    'source' => 'registered_meta',
                    'type' => isset($meta_definition['type']) ? (string) $meta_definition['type'] : '',
                    'description' => isset($meta_definition['description']) ? (string) $meta_definition['description'] : '',
                    'required' => false,
                ];
            }
        }

        $acf_catalog = isset($catalog_entry['acf']) && is_array($catalog_entry['acf']) ? $catalog_entry['acf'] : [];
        $acf_groups = isset($acf_catalog['groups']) && is_array($acf_catalog['groups']) ? $acf_catalog['groups'] : [];
        foreach ($acf_groups as $group_key => $group) {
            if (! is_array($group)) {
                continue;
            }

            $fields = isset($group['fields']) && is_array($group['fields']) ? $group['fields'] : [];
            $field_values = self::build_acf_field_map($fields, $variant, $value_style, $acf_resolution, $context, (string) $group_key, isset($group['title']) ? (string) $group['title'] : '');
            foreach ($field_values as $field_name => $field_value) {
                $values[$field_name] = $field_value;
            }
        }

        $observed_meta = isset($catalog_entry['observed_meta']) && is_array($catalog_entry['observed_meta']) ? $catalog_entry['observed_meta'] : [];
        if ($shape_mode === 'observed_shape' && $variant !== self::VARIANT_MINIMAL) {
            foreach ($observed_meta as $meta_key => $observed_definition) {
                if (! is_array($observed_definition) || isset($values[$meta_key])) {
                    continue;
                }

                $values[$meta_key] = self::build_observed_meta_placeholder($observed_definition, $value_style);
                $context[$meta_key] = [
                    'source' => 'observed',
                    'type' => isset($observed_definition['value_type']) ? (string) $observed_definition['value_type'] : '',
                    'description' => '',
                    'required' => false,
                    'frequency' => isset($observed_definition['frequency']) ? (float) $observed_definition['frequency'] : 0,
                ];
            }
        }

        return [
            'values' => $values,
            'context' => $context,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @param string                         $variant
     * @param string                         $value_style
     * @param array<string,mixed>            $acf_resolution
     * @param array<string,mixed>            $context
     * @param string                         $group_key
     * @param string                         $group_title
     * @param string                         $path_prefix
     * @return array<string,mixed>
     */
    private static function build_acf_field_map(
        array $fields,
        string $variant,
        string $value_style,
        array $acf_resolution,
        array &$context,
        string $group_key,
        string $group_title,
        string $path_prefix = ''
    ): array {
        $values = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
            if (in_array($type, ['tab', 'accordion', 'message'], true)) {
                continue;
            }

            $name = isset($field['name']) ? (string) $field['name'] : '';
            if ($variant === self::VARIANT_MINIMAL && empty($field['required'])) {
                if ($type !== 'clone' || $name !== '') {
                    continue;
                }
            }

            if ($type === 'clone') {
                $resolved_fields = self::resolve_clone_targets($field, $acf_resolution, []);
                if ($name === '') {
                    $inline_values = self::build_acf_field_map($resolved_fields, $variant, $value_style, $acf_resolution, $context, $group_key, $group_title, $path_prefix);
                    foreach ($inline_values as $inline_name => $inline_value) {
                        $values[$inline_name] = $inline_value;
                    }
                    continue;
                }
            }

            if ($name === '') {
                continue;
            }

            $value = self::build_acf_field_value($field, $variant, $value_style, $acf_resolution, $context, $group_key, $group_title, $path_prefix);
            $values[$name] = $value;
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $field
     * @param string              $variant
     * @param string              $value_style
     * @param array<string,mixed> $acf_resolution
     * @param array<string,mixed> $context
     * @param string              $group_key
     * @param string              $group_title
     * @param string              $path_prefix
     * @return mixed
     */
    private static function build_acf_field_value(
        array $field,
        string $variant,
        string $value_style,
        array $acf_resolution,
        array &$context,
        string $group_key,
        string $group_title,
        string $path_prefix = ''
    ) {
        $name = isset($field['name']) ? (string) $field['name'] : '';
        $type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        $path = $path_prefix === '' ? $name : $path_prefix . '.' . $name;

        $context[$path] = [
            'source' => 'acf',
            'group_key' => $group_key,
            'group_title' => $group_title,
            'label' => isset($field['label']) ? (string) $field['label'] : $name,
            'type' => $type,
            'instructions' => isset($field['instructions']) ? (string) $field['instructions'] : '',
            'required' => ! empty($field['required']),
            'choices' => isset($field['choices']) && is_array($field['choices']) ? $field['choices'] : [],
            'post_type' => isset($field['post_type']) && is_array($field['post_type']) ? $field['post_type'] : [],
            'taxonomy_filters' => isset($field['taxonomy_filters']) && is_array($field['taxonomy_filters']) ? $field['taxonomy_filters'] : [],
        ];

        switch ($type) {
            case 'group':
                return self::build_acf_field_map(
                    isset($field['sub_fields']) && is_array($field['sub_fields']) ? $field['sub_fields'] : [],
                    $variant,
                    $value_style,
                    $acf_resolution,
                    $context,
                    $group_key,
                    $group_title,
                    $path
                );

            case 'repeater':
                if ($variant === self::VARIANT_MINIMAL) {
                    return [];
                }

                return [
                    self::build_acf_field_map(
                        isset($field['sub_fields']) && is_array($field['sub_fields']) ? $field['sub_fields'] : [],
                        $variant,
                        $value_style,
                        $acf_resolution,
                        $context,
                        $group_key,
                        $group_title,
                        $path . '[]'
                    ),
                ];

            case 'flexible_content':
                return self::build_flexible_content_placeholder($field, $variant, $value_style, $acf_resolution, $context, $group_key, $group_title, $path);

            case 'clone':
                $resolved_fields = self::resolve_clone_targets($field, $acf_resolution, []);
                return self::build_acf_field_map($resolved_fields, $variant, $value_style, $acf_resolution, $context, $group_key, $group_title, $path);

            case 'relationship':
                return self::build_post_reference_placeholder($field, true, $value_style);

            case 'post_object':
                return self::build_post_reference_placeholder($field, ! empty($field['multiple']), $value_style);

            case 'taxonomy':
                return self::build_taxonomy_reference_placeholder($field, ! empty($field['multiple']), $value_style);

            case 'checkbox':
                if ($value_style === self::VALUE_STYLE_DUMMY && ! empty($field['choices']) && is_array($field['choices'])) {
                    $first_choice = array_key_first($field['choices']);
                    return $first_choice === null ? [] : [$first_choice];
                }
                return [];

            case 'select':
            case 'radio':
                if ($value_style === self::VALUE_STYLE_DUMMY && ! empty($field['choices']) && is_array($field['choices'])) {
                    $first_choice = array_key_first($field['choices']);
                    return $first_choice === null ? '' : $first_choice;
                }
                return '';

            case 'true_false':
                return false;

            case 'image':
            case 'file':
                return null;

            case 'gallery':
                return [];

            case 'number':
            case 'range':
                return 0;

            default:
                return self::build_default_acf_scalar_placeholder($field, $value_style);
        }
    }

    /**
     * @param array<string,mixed> $field
     * @param bool                $multiple
     * @param string              $value_style
     * @return mixed
     */
    private static function build_post_reference_placeholder(array $field, bool $multiple, string $value_style)
    {
        $post_types = isset($field['post_type']) && is_array($field['post_type']) ? $field['post_type'] : [];
        $post_type = ! empty($post_types) ? (string) reset($post_types) : '';
        $reference = [
            'post_type' => $post_type,
            'slug' => $value_style === self::VALUE_STYLE_DUMMY && $post_type !== '' ? 'sample-' . $post_type : '',
        ];

        return $multiple ? [$reference] : $reference;
    }

    /**
     * @param array<string,mixed> $field
     * @param bool                $multiple
     * @param string              $value_style
     * @return mixed
     */
    private static function build_taxonomy_reference_placeholder(array $field, bool $multiple, string $value_style)
    {
        $taxonomy_filters = isset($field['taxonomy_filters']) && is_array($field['taxonomy_filters']) ? $field['taxonomy_filters'] : [];
        $taxonomy = '';
        if (! empty($taxonomy_filters)) {
            $raw = (string) reset($taxonomy_filters);
            $parts = explode(':', $raw, 2);
            $taxonomy = sanitize_key((string) $parts[0]);
        }

        $reference = [
            'taxonomy' => $taxonomy,
            'slug' => $value_style === self::VALUE_STYLE_DUMMY && $taxonomy !== '' ? 'sample-' . $taxonomy : '',
        ];

        return $multiple ? [$reference] : $reference;
    }

    /**
     * @param array<string,mixed> $field
     * @param string              $variant
     * @param string              $value_style
     * @param array<string,mixed> $acf_resolution
     * @param array<string,mixed> $context
     * @param string              $group_key
     * @param string              $group_title
     * @param string              $path
     * @return array<int,array<string,mixed>>
     */
    private static function build_flexible_content_placeholder(
        array $field,
        string $variant,
        string $value_style,
        array $acf_resolution,
        array &$context,
        string $group_key,
        string $group_title,
        string $path
    ): array {
        $layouts = isset($field['layouts']) && is_array($field['layouts']) ? $field['layouts'] : [];
        if (empty($layouts) || $variant === self::VARIANT_MINIMAL) {
            return [];
        }

        $selected_layouts = $variant === self::VARIANT_MAXIMAL
            ? $layouts
            : [reset($layouts)];

        $values = [];
        foreach ($selected_layouts as $layout) {
            if (! is_array($layout)) {
                continue;
            }

            $layout_name = isset($layout['name']) ? (string) $layout['name'] : '';
            $layout_fields = isset($layout['sub_fields']) && is_array($layout['sub_fields']) ? $layout['sub_fields'] : [];
            $layout_value = self::build_acf_field_map($layout_fields, $variant, $value_style, $acf_resolution, $context, $group_key, $group_title, $path . '[].' . $layout_name);
            $layout_value['acf_fc_layout'] = $layout_name;
            $values[] = $layout_value;
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $field
     * @param string              $value_style
     * @return mixed
     */
    private static function build_default_acf_scalar_placeholder(array $field, string $value_style)
    {
        $type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        $label = isset($field['label']) ? (string) $field['label'] : '';

        if ($value_style !== self::VALUE_STYLE_DUMMY) {
            return '';
        }

        switch ($type) {
            case 'url':
                return 'https://example.com';
            case 'email':
                return 'sample@example.com';
            case 'wysiwyg':
            case 'textarea':
                return 'Replace with final content.';
            default:
                return $label !== '' ? sprintf('Sample %s', $label) : 'Sample value';
        }
    }

    /**
     * @param array<string,mixed> $field
     * @param array<string,mixed> $acf_resolution
     * @param array<int,string>   $stack
     * @return array<int,array<string,mixed>>
     */
    private static function resolve_clone_targets(array $field, array $acf_resolution, array $stack): array
    {
        $targets = isset($field['clone']['targets']) && is_array($field['clone']['targets']) ? $field['clone']['targets'] : [];
        if (empty($targets)) {
            return [];
        }

        $fields_by_key = isset($acf_resolution['fields_by_key']) && is_array($acf_resolution['fields_by_key']) ? $acf_resolution['fields_by_key'] : [];
        $groups_by_key = isset($acf_resolution['groups_by_key']) && is_array($acf_resolution['groups_by_key']) ? $acf_resolution['groups_by_key'] : [];
        $resolved = [];

        foreach ($targets as $target) {
            $target = (string) $target;
            if ($target === '' || in_array($target, $stack, true)) {
                continue;
            }

            if (isset($fields_by_key[$target]) && is_array($fields_by_key[$target])) {
                $resolved[] = $fields_by_key[$target];
                continue;
            }

            if (isset($groups_by_key[$target]) && is_array($groups_by_key[$target])) {
                foreach ($groups_by_key[$target] as $group_field) {
                    if (is_array($group_field)) {
                        $resolved[] = $group_field;
                    }
                }
            }
        }

        return $resolved;
    }

    /**
     * @param array<string,mixed> $catalog_entry
     * @param string              $variant
     * @return array<string,array<int,mixed>>
     */
    private static function build_tax_input_template(array $catalog_entry, string $variant): array
    {
        if (! self::should_include_tax_input_block($variant)) {
            return [];
        }

        $taxonomies = isset($catalog_entry['tax_input']) && is_array($catalog_entry['tax_input']) ? $catalog_entry['tax_input'] : [];
        $template = [];
        foreach ($taxonomies as $taxonomy => $taxonomy_definition) {
            $template[(string) $taxonomy] = [];
            unset($taxonomy_definition);
        }

        ksort($template);
        return $template;
    }

    /**
     * @param array<string,mixed> $meta_definition
     * @param string              $value_style
     * @return mixed
     */
    private static function build_registered_meta_placeholder(array $meta_definition, string $value_style)
    {
        $type = isset($meta_definition['type']) ? sanitize_key((string) $meta_definition['type']) : '';

        switch ($type) {
            case 'boolean':
                return false;
            case 'integer':
                return 0;
            case 'number':
                return 0;
            case 'array':
            case 'object':
                return [];
            case 'string':
            default:
                return $value_style === self::VALUE_STYLE_DUMMY ? 'Sample value' : '';
        }
    }

    /**
     * @param array<string,mixed> $observed_definition
     * @param string              $value_style
     * @return mixed
     */
    private static function build_observed_meta_placeholder(array $observed_definition, string $value_style)
    {
        $type = isset($observed_definition['value_type']) ? sanitize_key((string) $observed_definition['value_type']) : '';

        switch ($type) {
            case 'boolean':
            case 'boolean_like_string':
                return false;
            case 'integer':
                return 0;
            case 'number':
                return 0;
            case 'array':
            case 'object':
                return [];
            case 'null':
                return null;
            case 'string':
            default:
                return $value_style === self::VALUE_STYLE_DUMMY ? 'Observed sample value' : '';
        }
    }

    /**
     * @param string $field
     * @param string $variant
     * @param array  $supports
     * @param bool   $is_hierarchical
     * @return bool
     */
    private static function should_include_post_field(string $field, string $variant, array $supports, bool $is_hierarchical): bool
    {
        if (in_array($field, ['ID', 'post_type', 'post_title', 'post_name'], true)) {
            return true;
        }

        if ($variant === self::VARIANT_MINIMAL) {
            return false;
        }

        if (in_array($variant, [self::VARIANT_SINGLE, self::VARIANT_TYPICAL], true)) {
            if ($field === 'post_content') {
                return in_array('editor', $supports, true);
            }

            if ($field === 'post_excerpt') {
                return in_array('excerpt', $supports, true);
            }

            return in_array($field, ['post_status', 'post_content', 'post_excerpt'], true);
        }

        if ($field === 'post_content') {
            return in_array('editor', $supports, true);
        }

        if ($field === 'post_excerpt') {
            return in_array('excerpt', $supports, true);
        }

        if ($field === 'post_parent') {
            return $is_hierarchical;
        }

        return true;
    }

    /**
     * @param string $field
     * @param string $variant
     * @return bool
     */
    private static function should_include_term_field(string $field, string $variant): bool
    {
        if (in_array($field, ['term_id', 'taxonomy', 'name', 'slug'], true)) {
            return true;
        }

        if ($variant === self::VARIANT_MINIMAL) {
            return false;
        }

        if (in_array($variant, [self::VARIANT_SINGLE, self::VARIANT_TYPICAL], true)) {
            return in_array($field, ['description', 'parent_slug'], true);
        }

        return true;
    }

    /**
     * @param string $variant
     * @return bool
     */
    private static function should_include_meta_block(string $variant): bool
    {
        return $variant !== self::VARIANT_MINIMAL;
    }

    /**
     * @param string $variant
     * @return bool
     */
    private static function should_include_tax_input_block(string $variant): bool
    {
        return $variant !== self::VARIANT_MINIMAL;
    }

    /**
     * @param string $field
     * @param string $singular_label
     * @param string $slug_seed
     * @param string $value_style
     * @return string
     */
    private static function build_core_string_placeholder(string $field, string $singular_label, string $slug_seed, string $value_style): string
    {
        if ($value_style !== self::VALUE_STYLE_DUMMY) {
            return '';
        }

        switch ($field) {
            case 'post_title':
                return sprintf('Sample %s Title', $singular_label);
            case 'post_content':
                return sprintf('Replace with final %s content.', strtolower($singular_label));
            case 'post_excerpt':
                return sprintf('Short summary for the %s.', strtolower($singular_label));
            case 'term_description':
                return sprintf('Description for the %s.', strtolower($singular_label));
            default:
                return 'Sample ' . $slug_seed;
        }
    }

    /**
     * @param string $slug_seed
     * @param string $value_style
     * @return string
     */
    private static function build_core_slug_placeholder(string $slug_seed, string $value_style): string
    {
        if ($value_style !== self::VALUE_STYLE_DUMMY) {
            return '';
        }

        return sanitize_title('sample-' . $slug_seed);
    }

    /**
     * @param string $singular_label
     * @param string $slug_seed
     * @param string $value_style
     * @return string
     */
    private static function build_term_name_placeholder(string $singular_label, string $slug_seed, string $value_style): string
    {
        if ($value_style !== self::VALUE_STYLE_DUMMY) {
            return '';
        }

        return sprintf('Sample %s', $singular_label !== '' ? $singular_label : $slug_seed);
    }

    /**
     * @param array<string,mixed> $field_catalog
     * @return array<string,mixed>
     */
    private static function build_acf_resolution_context(array $field_catalog): array
    {
        $groups_by_key = [];
        $fields_by_key = [];

        foreach (['post_types', 'taxonomies'] as $branch) {
            $entries = isset($field_catalog[$branch]) && is_array($field_catalog[$branch]) ? $field_catalog[$branch] : [];
            foreach ($entries as $entry) {
                if (! is_array($entry) || empty($entry['acf']['groups']) || ! is_array($entry['acf']['groups'])) {
                    continue;
                }

                foreach ($entry['acf']['groups'] as $group_key => $group) {
                    if (! is_array($group) || isset($groups_by_key[$group_key])) {
                        continue;
                    }

                    $fields = isset($group['fields']) && is_array($group['fields']) ? $group['fields'] : [];
                    $groups_by_key[$group_key] = $fields;
                    self::index_fields_by_key($fields, $fields_by_key);
                }
            }
        }

        return [
            'groups_by_key' => $groups_by_key,
            'fields_by_key' => $fields_by_key,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @param array<string,array<string,mixed>> $fields_by_key
     * @return void
     */
    private static function index_fields_by_key(array $fields, array &$fields_by_key): void
    {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $field_key = isset($field['key']) ? (string) $field['key'] : '';
            if ($field_key !== '' && ! isset($fields_by_key[$field_key])) {
                $fields_by_key[$field_key] = $field;
            }

            if (! empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                self::index_fields_by_key($field['sub_fields'], $fields_by_key);
            }

            if (! empty($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layout) {
                    if (! is_array($layout) || empty($layout['sub_fields']) || ! is_array($layout['sub_fields'])) {
                        continue;
                    }

                    self::index_fields_by_key($layout['sub_fields'], $fields_by_key);
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $args
     * @return string
     */
    private static function resolve_value_style(array $args): string
    {
        $settings = Settings::get_all_settings();
        $value = isset($args['value_style']) ? sanitize_key((string) $args['value_style']) : '';
        if ($value === '' && isset($settings['generation']['value_style'])) {
            $value = sanitize_key((string) $settings['generation']['value_style']);
        }

        return $value === self::VALUE_STYLE_DUMMY ? self::VALUE_STYLE_DUMMY : self::VALUE_STYLE_BLANK;
    }

    /**
     * @param array<string,mixed> $args
     * @return string
     */
    private static function resolve_variant_set(array $args): string
    {
        $settings = Settings::get_all_settings();
        $value = isset($args['variant_set']) ? sanitize_key((string) $args['variant_set']) : '';
        if ($value === '' && isset($settings['generation']['variant_set'])) {
            $value = sanitize_key((string) $settings['generation']['variant_set']);
        }

        if (in_array($value, [self::VARIANT_MINIMAL, self::VARIANT_TYPICAL, self::VARIANT_MAXIMAL, self::VARIANT_FULL_SET], true)) {
            return $value;
        }

        return self::VARIANT_SINGLE;
    }

    /**
     * @param string $variant_set
     * @return array<int,string>
     */
    private static function resolve_variants(string $variant_set): array
    {
        switch ($variant_set) {
            case self::VARIANT_MINIMAL:
                return [self::VARIANT_MINIMAL];
            case self::VARIANT_TYPICAL:
                return [self::VARIANT_TYPICAL];
            case self::VARIANT_MAXIMAL:
                return [self::VARIANT_MAXIMAL];
            case self::VARIANT_FULL_SET:
                return [self::VARIANT_MINIMAL, self::VARIANT_TYPICAL, self::VARIANT_MAXIMAL];
            case self::VARIANT_SINGLE:
            default:
                return [self::VARIANT_SINGLE];
        }
    }
}
