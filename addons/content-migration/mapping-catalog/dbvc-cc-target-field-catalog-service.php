<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Target_Field_Catalog_Service
{
    /**
     * @var DBVC_CC_Target_Field_Catalog_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_Target_Field_Catalog_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param string $domain
     * @param bool   $force_rebuild
     * @return array<string, mixed>|WP_Error
     */
    public function build_catalog($domain, $force_rebuild = false)
    {
        $context = $this->resolve_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        $catalog_file = $context['catalog_file'];
        $snapshot_source = $this->resolve_snapshot_source();

        $cpt_catalog = $this->collect_post_type_catalog();
        $taxonomy_catalog = $this->collect_taxonomy_catalog();
        $term_catalog = $this->collect_term_catalog($taxonomy_catalog);
        $meta_catalog = $this->collect_meta_catalog();
        $acf_catalog = $this->collect_acf_catalog();
        $media_field_catalog = $this->collect_media_field_catalog($meta_catalog, $acf_catalog);

        $fingerprint_components = [
            'schema_snapshot_hash' => isset($snapshot_source['snapshot_hash']) ? (string) $snapshot_source['snapshot_hash'] : '',
            'post_type_signature' => hash('sha256', (string) wp_json_encode($this->normalize_for_hash($cpt_catalog))),
            'taxonomy_signature' => hash('sha256', (string) wp_json_encode($this->normalize_for_hash($taxonomy_catalog))),
            'term_signature' => hash('sha256', (string) wp_json_encode($this->normalize_for_hash($term_catalog))),
            'meta_signature' => hash('sha256', (string) wp_json_encode($this->normalize_for_hash($meta_catalog))),
            'acf_signature' => hash('sha256', (string) wp_json_encode($this->normalize_for_hash($acf_catalog))),
            'media_field_signature' => hash('sha256', (string) wp_json_encode($this->normalize_for_hash($media_field_catalog))),
        ];
        $catalog_fingerprint = hash('sha256', (string) wp_json_encode($fingerprint_components));

        $existing = $this->read_json_file($catalog_file);
        if (
            ! $force_rebuild
            && is_array($existing)
            && isset($existing['catalog_fingerprint'])
            && (string) $existing['catalog_fingerprint'] === $catalog_fingerprint
        ) {
            DBVC_CC_Artifact_Manager::log_event(
                [
                    'stage' => 'mapping_catalog',
                    'status' => 'reused',
                    'path' => '',
                    'page_url' => 'https://' . $context['domain'] . '/',
                    'message' => 'Target field catalog reused without rebuild.',
                ]
            );

            return [
                'status' => 'reused',
                'domain' => $context['domain'],
                'generated_at' => isset($existing['generated_at']) ? (string) $existing['generated_at'] : '',
                'catalog_fingerprint' => $catalog_fingerprint,
                'catalog_file' => $catalog_file,
                'catalog' => $existing,
            ];
        }

        $catalog = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'target-field-catalog.v1',
            'domain' => $context['domain'],
            'generated_at' => current_time('c'),
            'catalog_fingerprint' => $catalog_fingerprint,
            'source_artifacts' => [
                'schema_snapshot_file' => isset($snapshot_source['snapshot_file']) ? (string) $snapshot_source['snapshot_file'] : '',
                'schema_snapshot_hash' => isset($snapshot_source['snapshot_hash']) ? (string) $snapshot_source['snapshot_hash'] : '',
                'runtime_signatures' => $fingerprint_components,
            ],
            'cpt_catalog' => $cpt_catalog,
            'taxonomy_catalog' => $taxonomy_catalog,
            'term_catalog' => $term_catalog,
            'meta_catalog' => $meta_catalog,
            'acf_catalog' => $acf_catalog,
            'media_field_catalog' => $media_field_catalog,
            'stats' => [
                'post_type_count' => count($cpt_catalog),
                'taxonomy_count' => count($taxonomy_catalog),
                'term_schema_count' => count($term_catalog),
                'meta_object_type_count' => count($meta_catalog),
                'acf_group_count' => isset($acf_catalog['groups']) && is_array($acf_catalog['groups']) ? count($acf_catalog['groups']) : 0,
                'media_field_count' => count($media_field_catalog),
            ],
        ];

        if (! DBVC_CC_Artifact_Manager::write_json_file($catalog_file, $catalog)) {
            return new WP_Error(
                'dbvc_cc_mapping_catalog_write_failed',
                __('Could not write target field catalog artifact.', 'dbvc'),
                ['status' => 500]
            );
        }

        DBVC_CC_Artifact_Manager::log_event(
            [
                'stage' => 'mapping_catalog',
                'status' => 'built',
                'path' => '',
                'page_url' => 'https://' . $context['domain'] . '/',
                'message' => 'Target field catalog generated.',
            ]
        );

        return [
            'status' => 'built',
            'domain' => $context['domain'],
            'generated_at' => $catalog['generated_at'],
            'catalog_fingerprint' => $catalog['catalog_fingerprint'],
            'catalog_file' => $catalog_file,
            'catalog' => $catalog,
        ];
    }

    /**
     * @param string $domain
     * @param bool   $build_if_missing
     * @return array<string, mixed>|WP_Error
     */
    public function get_catalog($domain, $build_if_missing = true)
    {
        $context = $this->resolve_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        $catalog = $this->read_json_file($context['catalog_file']);
        if (is_array($catalog)) {
            return [
                'status' => 'loaded',
                'domain' => $context['domain'],
                'generated_at' => isset($catalog['generated_at']) ? (string) $catalog['generated_at'] : '',
                'catalog_fingerprint' => isset($catalog['catalog_fingerprint']) ? (string) $catalog['catalog_fingerprint'] : '',
                'catalog_file' => $context['catalog_file'],
                'catalog' => $catalog,
            ];
        }

        if (! $build_if_missing) {
            return new WP_Error(
                'dbvc_cc_mapping_catalog_missing',
                __('Target field catalog has not been built for this domain.', 'dbvc'),
                ['status' => 404]
            );
        }

        return $this->build_catalog($context['domain'], false);
    }

    /**
     * @param string $domain
     * @return string
     */
    public function get_catalog_file_path($domain)
    {
        $context = $this->resolve_domain_context($domain);
        if (is_wp_error($context)) {
            return '';
        }

        return $context['catalog_file'];
    }

    /**
     * @param string $domain
     * @return array<string, string>|WP_Error
     */
    private function resolve_domain_context($domain)
    {
        $domain_key = $this->sanitize_domain_key($domain);
        if ($domain_key === '') {
            return new WP_Error(
                'dbvc_cc_mapping_catalog_domain_invalid',
                __('A valid domain key is required.', 'dbvc'),
                ['status' => 400]
            );
        }

        $base_dir = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        if (! is_string($base_dir) || $base_dir === '' || ! is_dir($base_dir)) {
            return new WP_Error(
                'dbvc_cc_mapping_catalog_storage_missing',
                __('Content migration storage path is not available.', 'dbvc'),
                ['status' => 500]
            );
        }

        $base_real = realpath($base_dir);
        if (! is_string($base_real)) {
            return new WP_Error(
                'dbvc_cc_mapping_catalog_storage_invalid',
                __('Could not resolve content migration storage path.', 'dbvc'),
                ['status' => 500]
            );
        }

        $domain_dir = trailingslashit($base_real) . $domain_key;
        if (! is_dir($domain_dir)) {
            return new WP_Error(
                'dbvc_cc_mapping_catalog_domain_missing',
                __('No crawl storage was found for the requested domain.', 'dbvc'),
                ['status' => 404]
            );
        }

        $schema_dir = trailingslashit($domain_dir) . DBVC_CC_Contracts::STORAGE_SCHEMA_SNAPSHOT_SUBDIR;
        if (! dbvc_cc_create_security_files($schema_dir)) {
            return new WP_Error(
                'dbvc_cc_mapping_catalog_schema_dir',
                __('Could not create domain schema directory.', 'dbvc'),
                ['status' => 500]
            );
        }

        $catalog_file = trailingslashit($schema_dir) . DBVC_CC_Contracts::STORAGE_TARGET_FIELD_CATALOG_V1_FILE;
        if (! dbvc_cc_path_is_within($catalog_file, $base_real)) {
            return new WP_Error(
                'dbvc_cc_mapping_catalog_path_invalid',
                __('Catalog path is outside the storage root.', 'dbvc'),
                ['status' => 500]
            );
        }

        return [
            'domain' => $domain_key,
            'catalog_file' => $catalog_file,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve_snapshot_source()
    {
        $snapshot_file = DBVC_CC_Schema_Snapshot_Service::get_snapshot_file_path();
        $snapshot_payload = $this->read_json_file($snapshot_file);

        if (! is_array($snapshot_payload)) {
            $generated = DBVC_CC_Schema_Snapshot_Service::generate_snapshot();
            if (! is_wp_error($generated)) {
                $snapshot_payload = $this->read_json_file($snapshot_file);
            }
        }

        if (! is_array($snapshot_payload)) {
            return [
                'snapshot_file' => $snapshot_file,
                'snapshot_hash' => '',
                'snapshot_payload' => [],
            ];
        }

        return [
            'snapshot_file' => $snapshot_file,
            'snapshot_hash' => hash('sha256', (string) wp_json_encode($this->normalize_for_hash($snapshot_payload))),
            'snapshot_payload' => $snapshot_payload,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function collect_post_type_catalog()
    {
        $objects = get_post_types([], 'objects');
        $catalog = [];
        if (! is_array($objects)) {
            return $catalog;
        }

        foreach ($objects as $post_type => $object) {
            if (! ($object instanceof WP_Post_Type)) {
                continue;
            }

            $supports = get_all_post_type_supports((string) $post_type);
            $catalog[(string) $post_type] = [
                'slug' => (string) $post_type,
                'label' => (string) $object->label,
                'description' => (string) $object->description,
                'public' => (bool) $object->public,
                'hierarchical' => (bool) $object->hierarchical,
                'supports' => array_keys(is_array($supports) ? $supports : []),
                'taxonomies' => array_values(get_object_taxonomies((string) $post_type)),
                'rest_base' => (string) $object->rest_base,
                'has_archive' => (bool) $object->has_archive,
            ];
        }

        ksort($catalog);
        return $catalog;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function collect_taxonomy_catalog()
    {
        $objects = get_taxonomies([], 'objects');
        $catalog = [];
        if (! is_array($objects)) {
            return $catalog;
        }

        foreach ($objects as $taxonomy => $object) {
            if (! ($object instanceof WP_Taxonomy)) {
                continue;
            }

            $catalog[(string) $taxonomy] = [
                'slug' => (string) $taxonomy,
                'label' => (string) $object->label,
                'description' => (string) $object->description,
                'public' => (bool) $object->public,
                'hierarchical' => (bool) $object->hierarchical,
                'object_types' => array_values(is_array($object->object_type) ? $object->object_type : []),
                'rest_base' => (string) $object->rest_base,
            ];
        }

        ksort($catalog);
        return $catalog;
    }

    /**
     * @param array<string, array<string, mixed>> $taxonomy_catalog
     * @return array<string, array<string, mixed>>
     */
    private function collect_term_catalog(array $taxonomy_catalog)
    {
        $catalog = [];
        foreach ($taxonomy_catalog as $taxonomy => $taxonomy_entry) {
            $term_count = wp_count_terms(
                [
                    'taxonomy' => (string) $taxonomy,
                    'hide_empty' => false,
                ]
            );
            if (is_wp_error($term_count)) {
                $term_count = 0;
            }

            $catalog[(string) $taxonomy] = [
                'taxonomy' => (string) $taxonomy,
                'hierarchical' => ! empty($taxonomy_entry['hierarchical']),
                'known_term_count' => absint($term_count),
                'field_schema' => [
                    ['field' => 'term_id', 'type' => 'integer'],
                    ['field' => 'name', 'type' => 'string'],
                    ['field' => 'slug', 'type' => 'string'],
                    ['field' => 'description', 'type' => 'string'],
                    ['field' => 'parent', 'type' => 'integer'],
                    ['field' => 'count', 'type' => 'integer'],
                ],
            ];
        }

        ksort($catalog);
        return $catalog;
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function collect_meta_catalog()
    {
        global $wp_meta_keys;
        $catalog = [];

        if (! is_array($wp_meta_keys)) {
            return $catalog;
        }

        foreach ($wp_meta_keys as $object_type => $subtypes) {
            if (! is_array($subtypes)) {
                continue;
            }

            $object_type_key = sanitize_key((string) $object_type);
            if ($object_type_key === '') {
                continue;
            }

            foreach ($subtypes as $subtype => $meta_keys) {
                if (! is_array($meta_keys)) {
                    continue;
                }

                $subtype_key = sanitize_key((string) $subtype);
                if ($subtype_key === '') {
                    $subtype_key = 'default';
                }

                foreach ($meta_keys as $meta_key => $meta_args) {
                    $meta_key_string = sanitize_key((string) $meta_key);
                    if ($meta_key_string === '') {
                        continue;
                    }

                    $meta_args_array = is_array($meta_args) ? $meta_args : [];
                    $catalog[$object_type_key][$subtype_key][$meta_key_string] = [
                        'meta_key' => $meta_key_string,
                        'type' => isset($meta_args_array['type']) ? sanitize_key((string) $meta_args_array['type']) : '',
                        'single' => ! empty($meta_args_array['single']),
                        'show_in_rest' => ! empty($meta_args_array['show_in_rest']),
                        'description' => isset($meta_args_array['description']) ? sanitize_text_field((string) $meta_args_array['description']) : '',
                    ];
                }

                if (isset($catalog[$object_type_key][$subtype_key]) && is_array($catalog[$object_type_key][$subtype_key])) {
                    ksort($catalog[$object_type_key][$subtype_key]);
                }
            }

            if (isset($catalog[$object_type_key]) && is_array($catalog[$object_type_key])) {
                ksort($catalog[$object_type_key]);
            }
        }

        ksort($catalog);
        return $catalog;
    }

    /**
     * @return array<string, mixed>
     */
    private function collect_acf_catalog()
    {
        if (! function_exists('acf_get_field_groups') || ! function_exists('acf_get_fields')) {
            return [
                'available' => false,
                'groups' => [],
            ];
        }

        $groups = acf_get_field_groups();
        if (! is_array($groups)) {
            return [
                'available' => true,
                'groups' => [],
            ];
        }

        $catalog_groups = [];
        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $group_key = isset($group['key']) ? sanitize_key((string) $group['key']) : '';
            if ($group_key === '') {
                continue;
            }

            $group_fields = acf_get_fields($group_key);
            $field_catalog = [];
            if (is_array($group_fields)) {
                foreach ($group_fields as $field) {
                    if (is_array($field)) {
                        $this->collect_acf_field_entries($field_catalog, $field, $group_key);
                    }
                }
                ksort($field_catalog);
                foreach ($field_catalog as $field_key => $field_entry) {
                    if (! is_array($field_entry)) {
                        continue;
                    }

                    $parent_key = isset($field_entry['parent']) ? sanitize_key((string) $field_entry['parent']) : '';
                    $field_catalog[$field_key]['parent_type'] = ($parent_key !== '' && isset($field_catalog[$parent_key]) && is_array($field_catalog[$parent_key]))
                        ? (isset($field_catalog[$parent_key]['type']) ? sanitize_key((string) $field_catalog[$parent_key]['type']) : '')
                        : '';
                }
            }

            $catalog_groups[$group_key] = [
                'key' => $group_key,
                'group_name' => isset($group['name']) ? sanitize_key((string) $group['name']) : sanitize_title((string) ($group['title'] ?? $group_key)),
                'title' => isset($group['title']) ? sanitize_text_field((string) $group['title']) : '',
                'position' => isset($group['position']) ? sanitize_key((string) $group['position']) : '',
                'location' => isset($group['location']) && is_array($group['location']) ? $group['location'] : [],
                'vf_field_context' => $this->sanitize_context_meta(isset($group['vf_field_context']) ? $group['vf_field_context'] : []),
                'gardenai_field_purpose' => isset($group['gardenai_field_purpose']) ? sanitize_text_field((string) $group['gardenai_field_purpose']) : '',
                'fields' => $field_catalog,
            ];
        }

        ksort($catalog_groups);
        return [
            'available' => true,
            'groups' => $catalog_groups,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $field_catalog
     * @param array<string, mixed>                $field
     * @param string                              $group_key
     * @param array<int, string>                  $ancestor_field_keys
     * @param array<int, string>                  $ancestor_name_path
     * @param array<int, string>                  $ancestor_label_path
     * @return void
     */
    private function collect_acf_field_entries(
        array &$field_catalog,
        array $field,
        $group_key,
        array $ancestor_field_keys = [],
        array $ancestor_name_path = [],
        array $ancestor_label_path = []
    ) {
        $field_key = isset($field['key']) ? sanitize_key((string) $field['key']) : '';
        if ($field_key === '') {
            return;
        }

        $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        $field_name = isset($field['name']) ? sanitize_key((string) $field['name']) : '';
        $field_label = isset($field['label']) ? sanitize_text_field((string) $field['label']) : '';

        $field_catalog[$field_key] = [
            'key' => $field_key,
            'group_key' => sanitize_key((string) $group_key),
            'name' => $field_name,
            'label' => $field_label,
            'type' => $field_type,
            'container_type' => $this->infer_acf_container_type($field),
            'required' => ! empty($field['required']),
            'parent' => isset($field['parent']) ? sanitize_key((string) $field['parent']) : '',
            'return_format' => isset($field['return_format']) ? sanitize_key((string) $field['return_format']) : '',
            'ancestor_field_keys' => array_values($ancestor_field_keys),
            'ancestor_name_path' => array_values($ancestor_name_path),
            'ancestor_label_path' => array_values($ancestor_label_path),
            'depth' => count($ancestor_field_keys),
            'has_sub_fields' => ! empty($field['sub_fields']) && is_array($field['sub_fields']),
            'vf_field_context' => $this->sanitize_context_meta(isset($field['vf_field_context']) ? $field['vf_field_context'] : []),
            'gardenai_field_purpose' => isset($field['gardenai_field_purpose']) ? sanitize_text_field((string) $field['gardenai_field_purpose']) : '',
        ];

        $next_ancestor_field_keys = $ancestor_field_keys;
        $next_ancestor_name_path = $ancestor_name_path;
        $next_ancestor_label_path = $ancestor_label_path;

        if ($field_name !== '' || $field_label !== '') {
            $next_ancestor_field_keys[] = $field_key;
            if ($field_name !== '') {
                $next_ancestor_name_path[] = $field_name;
            }
            if ($field_label !== '') {
                $next_ancestor_label_path[] = $field_label;
            }
        }

        if (! empty($field['sub_fields']) && is_array($field['sub_fields'])) {
            foreach ($field['sub_fields'] as $sub_field) {
                if (is_array($sub_field)) {
                    $this->collect_acf_field_entries(
                        $field_catalog,
                        $sub_field,
                        $group_key,
                        $next_ancestor_field_keys,
                        $next_ancestor_name_path,
                        $next_ancestor_label_path
                    );
                }
            }
        }

        if (! empty($field['layouts']) && is_array($field['layouts'])) {
            foreach ($field['layouts'] as $layout) {
                if (! is_array($layout) || empty($layout['sub_fields']) || ! is_array($layout['sub_fields'])) {
                    continue;
                }

                $layout_name = isset($layout['name']) ? sanitize_key((string) $layout['name']) : '';
                $layout_label = isset($layout['label']) ? sanitize_text_field((string) $layout['label']) : '';
                $layout_ancestor_name_path = $next_ancestor_name_path;
                $layout_ancestor_label_path = $next_ancestor_label_path;
                if ($layout_name !== '') {
                    $layout_ancestor_name_path[] = $layout_name;
                }
                if ($layout_label !== '') {
                    $layout_ancestor_label_path[] = $layout_label;
                }

                foreach ($layout['sub_fields'] as $sub_field) {
                    if (is_array($sub_field)) {
                        $this->collect_acf_field_entries(
                            $field_catalog,
                            $sub_field,
                            $group_key,
                            $next_ancestor_field_keys,
                            $layout_ancestor_name_path,
                            $layout_ancestor_label_path
                        );
                    }
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $field
     * @return string
     */
    private function infer_acf_container_type(array $field)
    {
        $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';

        return in_array($field_type, ['group', 'repeater', 'flexible_content', 'clone'], true)
            ? $field_type
            : '';
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function sanitize_context_meta($value)
    {
        if (! is_array($value)) {
            return [];
        }

        $sanitized = [];
        foreach ($value as $key => $item) {
            $clean_key = is_string($key) ? sanitize_key($key) : (is_int($key) ? $key : null);
            if ($clean_key === null) {
                continue;
            }

            if (is_array($item)) {
                $sanitized[$clean_key] = $this->sanitize_context_meta($item);
            } elseif (is_bool($item)) {
                $sanitized[$clean_key] = $item;
            } elseif (is_numeric($item)) {
                $sanitized[$clean_key] = $item + 0;
            } elseif (is_scalar($item)) {
                $sanitized[$clean_key] = sanitize_text_field((string) $item);
            }
        }

        return $sanitized;
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $meta_catalog
     * @param array<string, mixed>                               $acf_catalog
     * @return array<string, array<string, mixed>>
     */
    private function collect_media_field_catalog(array $meta_catalog, array $acf_catalog)
    {
        $catalog = [];

        foreach ($meta_catalog as $object_type => $subtypes) {
            if (! is_array($subtypes)) {
                continue;
            }

            foreach ($subtypes as $subtype => $meta_entries) {
                if (! is_array($meta_entries)) {
                    continue;
                }

                foreach ($meta_entries as $meta_key => $meta_entry) {
                    if (! is_array($meta_entry)) {
                        continue;
                    }

                    $media_kinds = $this->infer_media_kinds_from_key_and_type($meta_key, isset($meta_entry['type']) ? (string) $meta_entry['type'] : '');
                    if (empty($media_kinds)) {
                        continue;
                    }

                    $field_ref = sprintf('meta:%s:%s:%s', sanitize_key((string) $object_type), sanitize_key((string) $subtype), sanitize_key((string) $meta_key));
                    $shape_meta = $this->build_meta_media_shape_meta($meta_key, $meta_entry, $media_kinds);
                    $catalog[$field_ref] = [
                        'field_ref' => $field_ref,
                        'source' => 'meta',
                        'object_type' => sanitize_key((string) $object_type),
                        'subtype' => sanitize_key((string) $subtype),
                        'field_key' => sanitize_key((string) $meta_key),
                        'media_kind_candidates' => $media_kinds,
                        'storage_shape' => isset($shape_meta['storage_shape']) ? (string) $shape_meta['storage_shape'] : 'attachment_id',
                        'multi_value' => ! empty($shape_meta['multi_value']),
                        'return_format' => isset($shape_meta['return_format']) ? (string) $shape_meta['return_format'] : 'id',
                        'accepted_media_kinds' => isset($shape_meta['accepted_media_kinds']) && is_array($shape_meta['accepted_media_kinds'])
                            ? array_values($shape_meta['accepted_media_kinds'])
                            : $media_kinds,
                        'normalized_value_strategy' => isset($shape_meta['normalized_value_strategy']) ? (string) $shape_meta['normalized_value_strategy'] : 'replace_single_attachment',
                        'confidence' => 0.7,
                    ];
                }
            }
        }

        $acf_groups = isset($acf_catalog['groups']) && is_array($acf_catalog['groups']) ? $acf_catalog['groups'] : [];
        foreach ($acf_groups as $group_key => $group_entry) {
            if (! is_array($group_entry) || empty($group_entry['fields']) || ! is_array($group_entry['fields'])) {
                continue;
            }

            foreach ($group_entry['fields'] as $field_key => $field_entry) {
                if (! is_array($field_entry)) {
                    continue;
                }

                $field_type = isset($field_entry['type']) ? sanitize_key((string) $field_entry['type']) : '';
                $name = isset($field_entry['name']) ? sanitize_key((string) $field_entry['name']) : '';
                $media_kinds = $this->infer_media_kinds_from_acf_type($field_type, $name);
                if (empty($media_kinds)) {
                    continue;
                }

                $field_ref = sprintf('acf:%s:%s', sanitize_key((string) $group_key), sanitize_key((string) $field_key));
                $shape_meta = $this->build_acf_media_shape_meta($field_entry, $media_kinds);
                $catalog[$field_ref] = [
                    'field_ref' => $field_ref,
                    'source' => 'acf',
                    'group_key' => sanitize_key((string) $group_key),
                    'field_key' => sanitize_key((string) $field_key),
                    'field_name' => $name,
                    'field_type' => $field_type,
                    'parent_type' => isset($field_entry['parent_type']) ? sanitize_key((string) $field_entry['parent_type']) : '',
                    'media_kind_candidates' => $media_kinds,
                    'storage_shape' => isset($shape_meta['storage_shape']) ? (string) $shape_meta['storage_shape'] : 'attachment_id',
                    'multi_value' => ! empty($shape_meta['multi_value']),
                    'return_format' => isset($shape_meta['return_format']) ? (string) $shape_meta['return_format'] : 'id',
                    'accepted_media_kinds' => isset($shape_meta['accepted_media_kinds']) && is_array($shape_meta['accepted_media_kinds'])
                        ? array_values($shape_meta['accepted_media_kinds'])
                        : $media_kinds,
                    'normalized_value_strategy' => isset($shape_meta['normalized_value_strategy']) ? (string) $shape_meta['normalized_value_strategy'] : 'replace_single_attachment',
                    'confidence' => 0.9,
                ];
            }
        }

        ksort($catalog);
        return $catalog;
    }

    /**
     * @param string                     $meta_key
     * @param array<string, mixed>       $meta_entry
     * @param array<int, string>         $media_kinds
     * @return array<string, mixed>
     */
    private function build_meta_media_shape_meta($meta_key, array $meta_entry, array $media_kinds)
    {
        $meta_key = sanitize_key((string) $meta_key);
        $meta_type = isset($meta_entry['type']) ? sanitize_key((string) $meta_entry['type']) : '';
        $is_single = array_key_exists('single', $meta_entry) ? ! empty($meta_entry['single']) : true;
        $is_gallery_like = (bool) preg_match('/gallery|images|logos|slides/', $meta_key);
        $is_remote_url = (bool) preg_match('/embed|oembed|video_url|video_embed|youtube|vimeo/', $meta_key);

        if ($is_remote_url) {
            return [
                'storage_shape' => 'remote_url',
                'multi_value' => false,
                'return_format' => 'url',
                'accepted_media_kinds' => array_values(array_unique(array_merge($media_kinds, ['embed', 'video']))),
                'normalized_value_strategy' => 'replace_remote_url',
            ];
        }

        if ($is_gallery_like || $meta_type === 'array' || ! $is_single) {
            return [
                'storage_shape' => 'attachment_id_list',
                'multi_value' => true,
                'return_format' => 'ids',
                'accepted_media_kinds' => $media_kinds,
                'normalized_value_strategy' => 'replace_attachment_list',
            ];
        }

        return [
            'storage_shape' => 'attachment_id',
            'multi_value' => false,
            'return_format' => 'id',
            'accepted_media_kinds' => $media_kinds,
            'normalized_value_strategy' => 'replace_single_attachment',
        ];
    }

    /**
     * @param array<string, mixed> $field_entry
     * @param array<int, string>   $media_kinds
     * @return array<string, mixed>
     */
    private function build_acf_media_shape_meta(array $field_entry, array $media_kinds)
    {
        $field_type = isset($field_entry['type']) ? sanitize_key((string) $field_entry['type']) : '';
        $return_format = isset($field_entry['return_format']) ? sanitize_key((string) $field_entry['return_format']) : '';
        $parent_type = isset($field_entry['parent_type']) ? sanitize_key((string) $field_entry['parent_type']) : '';

        if (in_array($parent_type, ['repeater', 'flexible_content'], true)) {
            return [
                'storage_shape' => 'unsupported_nested_media',
                'multi_value' => $field_type === 'gallery',
                'return_format' => $return_format !== '' ? $return_format : 'id',
                'accepted_media_kinds' => $media_kinds,
                'normalized_value_strategy' => 'defer_unsupported_nested',
            ];
        }

        if ($field_type === 'gallery') {
            return [
                'storage_shape' => 'attachment_id_list',
                'multi_value' => true,
                'return_format' => $return_format !== '' ? $return_format : 'ids',
                'accepted_media_kinds' => $media_kinds,
                'normalized_value_strategy' => 'replace_attachment_list',
            ];
        }

        if ($field_type === 'oembed' || ($field_type === 'url' && ! empty($media_kinds) && in_array('embed', $media_kinds, true))) {
            return [
                'storage_shape' => 'remote_url',
                'multi_value' => false,
                'return_format' => 'url',
                'accepted_media_kinds' => array_values(array_unique(array_merge($media_kinds, ['embed', 'video']))),
                'normalized_value_strategy' => 'replace_remote_url',
            ];
        }

        return [
            'storage_shape' => 'attachment_id',
            'multi_value' => false,
            'return_format' => $return_format !== '' ? $return_format : 'id',
            'accepted_media_kinds' => $media_kinds,
            'normalized_value_strategy' => 'replace_single_attachment',
        ];
    }

    /**
     * @param string $key
     * @param string $type
     * @return array<int, string>
     */
    private function infer_media_kinds_from_key_and_type($key, $type)
    {
        $needle = strtolower((string) $key);
        $type_key = strtolower((string) $type);
        $kinds = [];

        if ($needle === '_thumbnail_id' || preg_match('/image|thumbnail|hero|logo|icon|photo|gallery/', $needle)) {
            $kinds[] = 'image';
        }
        if (preg_match('/video|embed|youtube|vimeo|wistia/', $needle)) {
            $kinds[] = 'video';
            $kinds[] = 'embed';
        }
        if (preg_match('/file|pdf|document|download|brochure/', $needle)) {
            $kinds[] = 'file';
        }
        if ($type_key === 'integer' && $needle === '_thumbnail_id') {
            $kinds[] = 'image';
        }
        if ($type_key === 'array' && preg_match('/gallery|images/', $needle)) {
            $kinds[] = 'image';
        }

        return array_values(array_unique($kinds));
    }

    /**
     * @param string $field_type
     * @param string $field_name
     * @return array<int, string>
     */
    private function infer_media_kinds_from_acf_type($field_type, $field_name)
    {
        $kinds = [];
        if (in_array($field_type, ['image', 'gallery'], true)) {
            $kinds[] = 'image';
        }
        if ($field_type === 'file') {
            $kinds[] = 'file';
        }
        if ($field_type === 'oembed') {
            $kinds[] = 'embed';
            $kinds[] = 'video';
        }
        if ($field_type === 'url' && preg_match('/video|youtube|vimeo|embed/', $field_name)) {
            $kinds[] = 'embed';
            $kinds[] = 'video';
        }

        return array_values(array_unique($kinds));
    }

    /**
     * @param mixed $payload
     * @return mixed
     */
    private function normalize_for_hash($payload)
    {
        if (! is_array($payload)) {
            return $payload;
        }

        if ($this->is_assoc_array($payload)) {
            ksort($payload);
        }

        foreach ($payload as $key => $value) {
            $payload[$key] = $this->normalize_for_hash($value);
        }

        return $payload;
    }

    /**
     * @param array<mixed> $value
     * @return bool
     */
    private function is_assoc_array(array $value)
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * @param string $path
     * @return array<string, mixed>|null
     */
    private function read_json_file($path)
    {
        if (! is_string($path) || $path === '' || ! file_exists($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param string $domain
     * @return string
     */
    private function sanitize_domain_key($domain)
    {
        $value = strtolower(trim((string) $domain));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-z0-9.\-]/', '', $value);
        return is_string($value) ? $value : '';
    }
}
