<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Portability_Normalizer
{
    public const NORMALIZATION_VERSION = 1;

    /**
     * @var array<int, string>
     */
    private const VOLATILE_KEYS = [
        'created_at',
        'updated_at',
        'modified',
        'modified_at',
        'modified_gmt',
        'updated_by',
        'user_id',
        'userId',
        'timestamp',
        'time',
        'last_generated',
        'generated_at',
        'lastModified',
        '_lastEdited',
    ];

    /**
     * @param array<string, mixed> $domain_definition
     * @return array<string, mixed>
     */
    public static function normalize_live_domain(array $domain_definition)
    {
        $domain_key = sanitize_key((string) ($domain_definition['domain_key'] ?? ''));
        $option_values = [];
        foreach ((array) ($domain_definition['option_names'] ?? []) as $option_name) {
            $option_name = sanitize_key((string) $option_name);
            if ($option_name === '') {
                continue;
            }
            $option_values[$option_name] = get_option($option_name, null);
        }

        if ($domain_key === 'custom_fonts') {
            return self::normalize_custom_fonts_domain($domain_definition, $option_values, 'live');
        }

        if ($domain_key === 'icon_collections') {
            return self::normalize_icon_collections_domain($domain_definition, $option_values, 'live');
        }

        if ($domain_key === 'bricks_templates') {
            return self::normalize_bricks_templates_domain($domain_definition, 'live');
        }

        return self::normalize_domain($domain_definition, $option_values, 'live');
    }

    /**
     * @param array<string, mixed> $domain_definition
     * @param array<string, mixed> $option_values
     * @return array<string, mixed>
     */
    public static function normalize_package_domain(array $domain_definition, array $option_values)
    {
        $domain_key = sanitize_key((string) ($domain_definition['domain_key'] ?? ''));
        if ($domain_key === 'icon_collections') {
            return self::normalize_icon_collections_domain($domain_definition, $option_values, 'package');
        }

        return self::normalize_domain($domain_definition, $option_values, 'package');
    }

    /**
     * @param array<string, mixed> $domain_definition
     * @param array<string, mixed> $domain_payload
     * @return array<string, mixed>
     */
    public static function normalize_package_domain_payload(array $domain_definition, array $domain_payload)
    {
        $domain_key = sanitize_key((string) ($domain_definition['domain_key'] ?? ($domain_payload['domain'] ?? '')));
        $label = sanitize_text_field((string) ($domain_definition['label'] ?? ($domain_payload['label'] ?? $domain_key)));
        $mode = sanitize_key((string) ($domain_definition['mode'] ?? 'collection'));
        $objects = isset($domain_payload['objects']) && is_array($domain_payload['objects']) ? array_values($domain_payload['objects']) : [];
        $metadata_rows = isset($domain_payload['metadata_rows']) && is_array($domain_payload['metadata_rows']) ? array_values($domain_payload['metadata_rows']) : [];
        $meta = isset($domain_payload['meta']) && is_array($domain_payload['meta']) ? $domain_payload['meta'] : [];

        return [
            'domain_key' => $domain_key,
            'label' => $label,
            'mode' => $mode,
            'source' => 'package',
            'normalization_version' => self::NORMALIZATION_VERSION,
            'source_option_names' => (array) ($domain_definition['option_names'] ?? []),
            'option_values' => [],
            'primary_option' => sanitize_key((string) ($domain_definition['primary_option'] ?? '')),
            'objects' => $objects,
            'metadata_rows' => $metadata_rows,
            'warnings' => array_values((array) ($meta['warnings'] ?? [])),
            'verification' => DBVC_Bricks_Portability_Domain_Verifier::verify_domain_payload($domain_definition, [], 'package'),
            'transport' => isset($meta['transport']) && is_array($meta['transport']) ? $meta['transport'] : [
                'shape' => 'list',
                'path' => [],
                'wrapper_shape' => 'root',
            ],
            'media_refs' => isset($domain_payload['media_refs']) && is_array($domain_payload['media_refs']) ? array_values($domain_payload['media_refs']) : [],
            'domain_fingerprint' => sanitize_text_field((string) ($meta['domain_fingerprint'] ?? self::build_domain_fingerprint($objects, $metadata_rows))),
        ];
    }

    /**
     * @param array<string, mixed> $domain_definition
     * @param array<string, mixed> $option_values
     * @param string $source
     * @return array<string, mixed>
     */
    public static function normalize_domain(array $domain_definition, array $option_values, $source = 'live')
    {
        $domain_key = sanitize_key((string) ($domain_definition['domain_key'] ?? ''));
        $primary_option = sanitize_key((string) ($domain_definition['primary_option'] ?? ''));
        $mode = sanitize_key((string) ($domain_definition['mode'] ?? 'singleton'));
        $label = sanitize_text_field((string) ($domain_definition['label'] ?? $domain_key));

        $normalized = [
            'domain_key' => $domain_key,
            'label' => $label,
            'mode' => $mode,
            'source' => sanitize_key((string) $source),
            'normalization_version' => self::NORMALIZATION_VERSION,
            'source_option_names' => (array) ($domain_definition['option_names'] ?? []),
            'option_values' => $option_values,
            'primary_option' => $primary_option,
            'objects' => [],
            'metadata_rows' => [],
            'warnings' => [],
            'verification' => [],
            'transport' => [
                'shape' => 'singleton',
                'path' => [],
                'wrapper_shape' => 'root',
            ],
        ];

        $primary_raw = $primary_option !== '' && array_key_exists($primary_option, $option_values)
            ? $option_values[$primary_option]
            : null;
        $normalized['verification'] = DBVC_Bricks_Portability_Domain_Verifier::verify_domain_payload($domain_definition, $option_values, $source);
        $normalized['warnings'] = self::merge_warning_lists(
            $normalized['warnings'],
            (array) ($normalized['verification']['warnings'] ?? [])
        );

        if ($mode === 'collection') {
            $collection = self::extract_collection($primary_raw, $domain_key);
            $normalized['objects'] = $collection['objects'];
            $normalized['transport'] = $collection['transport'];
            $normalized['warnings'] = self::merge_warning_lists($normalized['warnings'], $collection['warnings']);
        } else {
            $root_normalized = self::normalize_value($primary_raw, $domain_key, $domain_key === 'breakpoints');
            $normalized['objects'][] = [
                'source_key' => $domain_key . '::root',
                'display_name' => $label,
                'object_id' => $primary_option !== '' ? $primary_option : '__root__',
                'match_keys' => [
                    'singleton' => '__root__',
                ],
                'fingerprint' => DBVC_Bricks_Portability_Utils::fingerprint($root_normalized),
                'references' => [
                    'css_variables' => DBVC_Bricks_Portability_Utils::extract_css_variable_tokens($root_normalized),
                    'class_names' => [],
                    'category_values' => [],
                    'category_option_name' => '',
                ],
                'warnings' => self::merge_warning_lists([], (array) ($normalized['verification']['warnings'] ?? [])),
                'raw' => $primary_raw,
                'normalized' => $root_normalized,
                'source_index' => 0,
                'transport' => [
                    'shape' => 'singleton',
                    'path' => [],
                ],
            ];
        }

        foreach ((array) ($domain_definition['related_options'] ?? []) as $option_name) {
            $option_name = sanitize_key((string) $option_name);
            $exists = array_key_exists($option_name, $option_values);
            $raw = $exists ? $option_values[$option_name] : null;
            if (! $exists && $raw === null) {
                continue;
            }

            $normalized_payload = self::normalize_value($raw, $domain_key, false);
            $normalized['metadata_rows'][] = [
                'row_id' => $domain_key . '::meta::' . $option_name,
                'row_type' => 'meta',
                'display_name' => 'Related Metadata: ' . $option_name,
                'object_id' => $option_name,
                'option_name' => $option_name,
                'fingerprint' => DBVC_Bricks_Portability_Utils::fingerprint($normalized_payload),
                'raw' => $raw,
                'normalized' => $normalized_payload,
            ];
        }

        $normalized['domain_fingerprint'] = self::build_domain_fingerprint($normalized['objects'], $normalized['metadata_rows']);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $domain_definition
     * @param array<string, mixed> $option_values
     * @param string $source
     * @return array<string, mixed>
     */
    private static function normalize_custom_fonts_domain(array $domain_definition, array $option_values, $source)
    {
        $domain_key = 'custom_fonts';
        $label = sanitize_text_field((string) ($domain_definition['label'] ?? 'Bricks Custom Fonts'));
        $post_type = sanitize_key((string) ($domain_definition['post_type'] ?? 'bricks_fonts'));
        if ($post_type === '') {
            $post_type = 'bricks_fonts';
        }

        $objects = [];
        $media_refs = [];
        $warnings = [];
        $posts = get_posts([
            'post_type' => $post_type,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);

        foreach ($posts as $index => $post) {
            if (! $post instanceof \WP_Post) {
                continue;
            }

            $font_faces = get_post_meta((int) $post->ID, 'bricks_font_faces', true);
            if (! is_array($font_faces)) {
                $font_faces = [];
            }

            $object_media_refs = [];
            foreach (self::extract_font_face_attachment_ids($font_faces) as $attachment_id) {
                $ref = self::build_attachment_media_ref($attachment_id, $domain_key, 'font_file');
                if (! empty($ref)) {
                    $object_media_refs[(string) ($ref['media_key'] ?? $attachment_id)] = $ref;
                    $media_refs[(string) ($ref['media_key'] ?? $attachment_id)] = $ref;
                    if (empty($ref['file_available'])) {
                        $warnings[] = 'Font attachment file is missing for attachment ID ' . (int) $attachment_id . '.';
                    }
                }
            }

            $normalized_faces = self::normalize_font_faces_for_compare($font_faces);
            $normalized_payload = self::normalize_value([
                'post_title' => (string) $post->post_title,
                'post_name' => (string) $post->post_name,
                'post_status' => (string) $post->post_status,
                'font_faces' => $normalized_faces,
            ], $domain_key, false);
            $object_id = 'custom_font_' . (int) $post->ID;
            $display_name = sanitize_text_field((string) $post->post_title);
            if ($display_name === '') {
                $display_name = $object_id;
            }

            $objects[] = [
                'source_key' => $domain_key . ':font:' . (int) $post->ID,
                'display_name' => $display_name,
                'object_id' => $object_id,
                'match_keys' => array_filter([
                    'id' => $object_id,
                    'name' => $display_name,
                    'slug' => sanitize_title((string) $post->post_name),
                ]),
                'fingerprint' => DBVC_Bricks_Portability_Utils::fingerprint($normalized_payload),
                'references' => [
                    'css_variables' => [],
                    'class_names' => [],
                    'category_values' => [],
                    'category_option_name' => '',
                    'media_refs' => array_values($object_media_refs),
                ],
                'warnings' => self::build_media_reference_warnings($object_media_refs, 'font'),
                'raw' => [
                    'post_id' => (int) $post->ID,
                    'post_title' => (string) $post->post_title,
                    'post_name' => (string) $post->post_name,
                    'post_status' => (string) $post->post_status,
                    'font_faces' => $font_faces,
                ],
                'normalized' => $normalized_payload,
                'source_index' => (int) $index,
                'map_key' => $object_id,
                'transport' => [
                    'shape' => 'entity',
                    'path' => ['bricks_fonts'],
                    'wrapper_shape' => 'posts',
                ],
                'media_refs' => array_values($object_media_refs),
            ];
        }

        $metadata_rows = self::build_related_metadata_rows($domain_definition, $option_values, $domain_key);

        return [
            'domain_key' => $domain_key,
            'label' => $label,
            'mode' => 'collection',
            'source' => sanitize_key((string) $source),
            'normalization_version' => self::NORMALIZATION_VERSION,
            'source_option_names' => (array) ($domain_definition['option_names'] ?? []),
            'option_values' => $option_values,
            'primary_option' => '',
            'objects' => $objects,
            'metadata_rows' => $metadata_rows,
            'warnings' => array_values(array_unique(array_map('sanitize_text_field', $warnings))),
            'verification' => DBVC_Bricks_Portability_Domain_Verifier::verify_domain_payload($domain_definition, $option_values, $source),
            'transport' => [
                'shape' => 'entity_collection',
                'path' => ['bricks_fonts'],
                'wrapper_shape' => 'posts',
            ],
            'media_refs' => array_values($media_refs),
            'domain_fingerprint' => self::build_domain_fingerprint($objects, $metadata_rows),
        ];
    }

    /**
     * @param array<string, mixed> $domain_definition
     * @param string $source
     * @return array<string, mixed>
     */
    private static function normalize_bricks_templates_domain(array $domain_definition, $source)
    {
        $domain_key = 'bricks_templates';
        $label = sanitize_text_field((string) ($domain_definition['label'] ?? 'Bricks Templates'));
        $post_type = sanitize_key((string) ($domain_definition['post_type'] ?? 'bricks_template'));
        if ($post_type === '') {
            $post_type = 'bricks_template';
        }

        $objects = [];
        $media_refs = [];
        $warnings = [];
        $posts = get_posts([
            'post_type' => $post_type,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'orderby' => ['title' => 'ASC', 'ID' => 'ASC'],
            'no_found_rows' => true,
        ]);

        foreach ($posts as $index => $post) {
            if (! $post instanceof \WP_Post) {
                continue;
            }

            $object = self::build_bricks_template_object($post, $domain_key, (int) $index);
            $warnings = self::merge_warning_lists($warnings, (array) ($object['warnings'] ?? []));
            foreach ((array) ($object['media_refs'] ?? []) as $ref) {
                if (! is_array($ref)) {
                    continue;
                }
                $media_key = sanitize_text_field((string) ($ref['media_key'] ?? ''));
                if ($media_key !== '') {
                    $media_refs[$media_key] = $ref;
                }
            }
            $objects[] = $object;
        }

        return [
            'domain_key' => $domain_key,
            'label' => $label,
            'mode' => 'collection',
            'source' => sanitize_key((string) $source),
            'normalization_version' => self::NORMALIZATION_VERSION,
            'source_option_names' => [],
            'option_values' => [],
            'primary_option' => '',
            'objects' => $objects,
            'metadata_rows' => [],
            'warnings' => array_values(array_unique(array_map('sanitize_text_field', $warnings))),
            'verification' => DBVC_Bricks_Portability_Domain_Verifier::verify_domain_payload($domain_definition, [], $source),
            'transport' => [
                'shape' => 'entity_collection',
                'path' => ['bricks_template'],
                'wrapper_shape' => 'posts',
            ],
            'media_refs' => array_values($media_refs),
            'domain_fingerprint' => self::build_domain_fingerprint($objects, []),
        ];
    }

    /**
     * @param \WP_Post $post
     * @param string $domain_key
     * @param int $index
     * @return array<string, mixed>
     */
    private static function build_bricks_template_object(\WP_Post $post, $domain_key, $index)
    {
        $template_type = self::get_template_type_for_post((int) $post->ID);
        $template_slug = sanitize_title((string) $post->post_name);
        $display_name = sanitize_text_field((string) $post->post_title);
        if ($display_name === '') {
            $display_name = $template_slug !== '' ? $template_slug : 'Template ' . (int) $post->ID;
        }

        $raw = [
            'post_id' => (int) $post->ID,
            'post_title' => (string) $post->post_title,
            'post_name' => (string) $post->post_name,
            'post_status' => (string) $post->post_status,
            'post_excerpt' => (string) $post->post_excerpt,
            'menu_order' => (int) $post->menu_order,
            'template_type' => $template_type,
            'template_settings' => get_post_meta((int) $post->ID, '_bricks_template_settings', true),
            'areas' => self::get_template_area_meta((int) $post->ID),
            'taxonomies' => self::get_template_taxonomy_terms((int) $post->ID),
        ];
        $normalized = self::normalize_value(self::template_compare_payload($raw), $domain_key, true);
        $type_prefix = $template_type !== '' ? $template_type : 'content';
        $object_id = $type_prefix . ':' . ($template_slug !== '' ? $template_slug : (string) $post->ID);
        $source_key = $domain_key . ':template:' . (int) $post->ID;
        $reference_payload = [
            'post' => [
                'title' => $raw['post_title'],
                'name' => $raw['post_name'],
                'type' => $raw['template_type'],
            ],
            'settings' => $raw['template_settings'],
            'areas' => $raw['areas'],
        ];
        $dependencies = self::extract_template_dependency_refs($raw, $domain_key);
        $dependency_refs = (array) ($dependencies['dependency_refs'] ?? []);
        $media_refs = (array) ($dependencies['media_refs'] ?? []);
        $warnings = self::build_template_reference_warnings($reference_payload, $dependency_refs);
        $warnings = self::merge_warning_lists($warnings, self::build_media_reference_warnings($media_refs, 'template'));

        return [
            'source_key' => $source_key,
            'display_name' => $display_name,
            'object_id' => $object_id,
            'match_keys' => array_filter([
                'slug' => $type_prefix . '::' . $template_slug,
                'name' => $type_prefix . '::' . $display_name,
            ]),
            'fingerprint' => DBVC_Bricks_Portability_Utils::fingerprint($normalized),
            'references' => [
                'css_variables' => DBVC_Bricks_Portability_Utils::extract_css_variable_tokens($reference_payload),
                'class_names' => self::extract_class_references($reference_payload),
                'category_values' => [],
                'category_option_name' => '',
                'media_refs' => array_values($media_refs),
                'template_refs' => array_values(array_filter($dependency_refs, static function ($ref) {
                    return is_array($ref) && sanitize_key((string) ($ref['ref_type'] ?? '')) === 'nested_template';
                })),
            ],
            'warnings' => $warnings,
            'raw' => $raw,
            'normalized' => $normalized,
            'source_index' => (int) $index,
            'map_key' => $object_id,
            'dependency_refs' => array_values($dependency_refs),
            'media_refs' => array_values($media_refs),
            'transport' => [
                'shape' => 'entity',
                'path' => ['bricks_template', (string) $post->ID],
                'wrapper_shape' => 'posts',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function template_compare_payload(array $raw)
    {
        unset($raw['post_id']);
        return $raw;
    }

    /**
     * @param int $post_id
     * @return string
     */
    private static function get_template_type_for_post($post_id)
    {
        $template_type = get_post_meta((int) $post_id, '_bricks_template_type', true);
        if (is_scalar($template_type) && trim((string) $template_type) !== '') {
            return sanitize_key((string) $template_type);
        }

        foreach ([
            'header' => '_bricks_page_header_2',
            'footer' => '_bricks_page_footer_2',
            'content' => '_bricks_page_content_2',
        ] as $type => $meta_key) {
            $data = get_post_meta((int) $post_id, $meta_key, true);
            if (is_array($data) && ! empty($data)) {
                return $type;
            }
        }

        return 'content';
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

        ksort($areas, SORT_STRING);
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
            usort($items, static function ($left, $right) {
                return strcmp((string) ($left['slug'] ?? ''), (string) ($right['slug'] ?? ''));
            });
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
     * @param mixed $payload
     * @return array<int, string>
     */
    private static function build_template_reference_warnings($payload, array $dependency_refs = [])
    {
        $warnings = [];
        $text = is_scalar($payload) ? (string) $payload : DBVC_Bricks_Portability_Utils::json_encode($payload);
        if ($text === '') {
            return [];
        }

        $has_nested_descriptor = false;
        $has_media_descriptor = false;
        foreach ($dependency_refs as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $ref_type = sanitize_key((string) ($ref['ref_type'] ?? ''));
            if ($ref_type === 'nested_template') {
                $has_nested_descriptor = true;
            }
            if ($ref_type === 'media') {
                $has_media_descriptor = true;
            }
        }

        if (! $has_nested_descriptor && preg_match('/"templateId"\s*:\s*\d+|"template"\s*:\s*\d+|"template_id"\s*:\s*\d+/', $text) === 1) {
            $warnings[] = 'Template contains possible nested Bricks template ID references that could not be typed safely.';
        }
        if (! $has_media_descriptor && preg_match('/"(?:image|media|attachment|attachment_id|backgroundImage)"\s*:\s*\d+/', $text) === 1) {
            $warnings[] = 'Template may contain media or post ID references that could not be typed safely.';
        }

        $warnings = self::merge_warning_lists($warnings, self::build_template_query_reference_warnings($payload, $dependency_refs));

        return array_values(array_unique($warnings));
    }

    /**
     * @param mixed $payload
     * @param array<int, array<string, mixed>> $dependency_refs
     * @return array<int, string>
     */
    private static function build_template_query_reference_warnings($payload, array $dependency_refs)
    {
        if (! is_array($payload)) {
            return [];
        }

        $mapped_paths = [];
        foreach ($dependency_refs as $ref) {
            if (! is_array($ref) || sanitize_key((string) ($ref['ref_type'] ?? '')) !== 'post_or_term') {
                continue;
            }
            if (sanitize_key((string) ($ref['query_ref_kind'] ?? '')) === '') {
                continue;
            }
            $payload_path = (string) ($ref['payload_path'] ?? '');
            if ($payload_path !== '') {
                $mapped_paths[$payload_path] = true;
            }
        }

        $warnings = [];
        self::collect_template_query_reference_warnings($payload, [], $mapped_paths, $warnings);
        return array_values(array_unique($warnings));
    }

    /**
     * @param mixed $value
     * @param array<int, string> $path
     * @param array<string, bool> $mapped_paths
     * @param array<int, string> $warnings
     * @return void
     */
    private static function collect_template_query_reference_warnings($value, array $path, array $mapped_paths, array &$warnings)
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $child_path = array_merge($path, [(string) $key]);
            if (self::template_path_parent_is_query($child_path)) {
                $control_name = sanitize_key((string) $key);
                if (in_array($control_name, ['post__in', 'post__not_in'], true)) {
                    self::collect_template_query_post_reference_warnings($control_name, $child, $child_path, $mapped_paths, $warnings);
                } elseif (in_array($control_name, ['tax_query', 'tax_query_not'], true)) {
                    self::collect_template_query_term_reference_warnings($control_name, $child, $child_path, $mapped_paths, $warnings);
                }
            }

            if (is_array($child)) {
                self::collect_template_query_reference_warnings($child, $child_path, $mapped_paths, $warnings);
            }
        }
    }

    /**
     * @param mixed $value
     * @param array<int, string> $path
     * @param array<string, bool> $mapped_paths
     * @param array<int, string> $warnings
     * @return void
     */
    private static function collect_template_query_post_reference_warnings($control_name, $value, array $path, array $mapped_paths, array &$warnings)
    {
        foreach (self::collect_template_scalar_reference_values($value, $path) as $candidate) {
            $candidate_path = (array) ($candidate['path'] ?? $path);
            $payload_path = implode('.', $candidate_path);
            if ($payload_path !== '' && ! empty($mapped_paths[$payload_path])) {
                continue;
            }
            $source_value = $candidate['value'] ?? null;
            if (! self::template_query_post_value_needs_warning($source_value)) {
                continue;
            }

            $warnings[] = sprintf(
                'Template contains Bricks query post reference at %s that could not be mapped safely; source value will be preserved.',
                sanitize_text_field($payload_path !== '' ? $payload_path : sanitize_key((string) $control_name))
            );
        }
    }

    /**
     * @param mixed $value
     * @param array<int, string> $path
     * @param array<string, bool> $mapped_paths
     * @param array<int, string> $warnings
     * @return void
     */
    private static function collect_template_query_term_reference_warnings($control_name, $value, array $path, array $mapped_paths, array &$warnings)
    {
        foreach (self::collect_template_scalar_reference_values($value, $path) as $candidate) {
            $candidate_path = (array) ($candidate['path'] ?? $path);
            $payload_path = implode('.', $candidate_path);
            if ($payload_path !== '' && ! empty($mapped_paths[$payload_path])) {
                continue;
            }
            $source_value = $candidate['value'] ?? null;
            if (! self::template_query_term_value_needs_warning($source_value)) {
                continue;
            }

            $warnings[] = sprintf(
                'Template contains Bricks query term reference at %s that could not be mapped safely; source value will be preserved.',
                sanitize_text_field($payload_path !== '' ? $payload_path : sanitize_key((string) $control_name))
            );
        }
    }

    /**
     * @param mixed $value
     */
    private static function template_query_post_value_needs_warning($value)
    {
        if (is_int($value)) {
            return $value > 0;
        }

        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (preg_match('/^[0-9]+$/', $value) === 1) {
            return true;
        }

        if (preg_match('/^[0-9,\s]+$/', $value) === 1 && strpos($value, ',') !== false) {
            return true;
        }

        return strpos($value, '{') !== false && strpos($value, '}') !== false;
    }

    /**
     * @param mixed $value
     */
    private static function template_query_term_value_needs_warning($value)
    {
        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (! empty(self::parse_template_scoped_term_reference($value))) {
            return true;
        }

        return strpos($value, '{') !== false && strpos($value, '}') !== false;
    }

    /**
     * @param array<string, mixed> $raw
     * @param string $domain_key
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function extract_template_dependency_refs(array $raw, $domain_key)
    {
        $payload = [
            'template_settings' => $raw['template_settings'] ?? [],
            'areas' => isset($raw['areas']) && is_array($raw['areas']) ? $raw['areas'] : [],
        ];
        $dependency_refs = [];
        $media_refs = [];
        self::walk_template_dependency_refs($payload, [], $domain_key, $dependency_refs, $media_refs);

        return [
            'dependency_refs' => array_values($dependency_refs),
            'media_refs' => array_values($media_refs),
        ];
    }

    /**
     * @param mixed $value
     * @param array<int, string> $path
     * @param string $domain_key
     * @param array<int, array<string, mixed>> $dependency_refs
     * @param array<string, array<string, mixed>> $media_refs
     * @return void
     */
    private static function walk_template_dependency_refs($value, array $path, $domain_key, array &$dependency_refs, array &$media_refs)
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $child_path = array_merge($path, [(string) $key]);
            if (is_numeric($child) && self::template_key_is_media_attachment_id((string) $key, $value)) {
                $attachment_id = (int) $child;
                $attachment = get_post($attachment_id);
                if ($attachment instanceof \WP_Post && $attachment->post_type === 'attachment') {
                    $ref = self::build_attachment_media_ref($attachment_id, $domain_key, 'template_media');
                    if (! empty($ref)) {
                        $media_key = sanitize_text_field((string) ($ref['media_key'] ?? ''));
                        if ($media_key !== '') {
                            $media_refs[$media_key] = $ref;
                        }
                        $dependency_refs[] = [
                            'ref_type' => 'media',
                            'source_id' => $attachment_id,
                            'source_url' => esc_url_raw((string) ($ref['source_url'] ?? '')),
                            'media_key' => $media_key,
                            'payload_path' => implode('.', $child_path),
                            'path' => $child_path,
                            'url_path' => self::find_template_reference_url_path($value, $path),
                            'control_name' => sanitize_key((string) $key),
                            'required' => true,
                            'confidence' => 'high',
                            'resolution_strategy' => 'package_media_checksum',
                            'target_id' => 0,
                            'status' => 'pending',
                        ];
                    }
                }
            }

            if (is_numeric($child) && self::template_key_is_nested_template_id((string) $key)) {
                $dependency_refs[] = [
                    'ref_type' => 'nested_template',
                    'source_id' => (int) $child,
                    'source_url' => '',
                    'media_key' => '',
                    'payload_path' => implode('.', $child_path),
                    'path' => $child_path,
                    'control_name' => sanitize_key((string) $key),
                    'required' => true,
                    'confidence' => 'high',
                    'resolution_strategy' => 'template_graph',
                    'target_id' => 0,
                    'status' => 'pending',
                ];
            }

            self::maybe_add_template_entity_dependency_ref((string) $key, $child, $child_path, $dependency_refs);
            self::maybe_add_template_query_dependency_refs((string) $key, $child, $child_path, $dependency_refs);

            if (is_array($child)) {
                self::walk_template_dependency_refs($child, $child_path, $domain_key, $dependency_refs, $media_refs);
            }
        }
    }

    /**
     * @param mixed $value
     * @param array<int, string> $path
     * @param array<int, array<string, mixed>> $dependency_refs
     * @return void
     */
    private static function maybe_add_template_entity_dependency_ref($key, $value, array $path, array &$dependency_refs)
    {
        $control_name = sanitize_key((string) $key);
        if ($control_name === 'templatepreviewpostid' && is_numeric($value)) {
            $post_id = (int) $value;
            if ($post_id <= 0) {
                return;
            }

            $post = get_post($post_id);
            if (! $post instanceof \WP_Post || $post->post_type === 'attachment') {
                return;
            }

            self::add_template_post_dependency_ref($post_id, $post, $value, $path, $control_name, $dependency_refs);
            return;
        }

        if ($control_name !== 'templatepreviewterm') {
            return;
        }

        $parsed = self::parse_template_scoped_term_reference($value);
        if (empty($parsed)) {
            return;
        }

        $term_id = (int) ($parsed['term_id'] ?? 0);
        $taxonomy = sanitize_key((string) ($parsed['taxonomy'] ?? ''));
        $term = $term_id > 0 && $taxonomy !== '' ? get_term($term_id, $taxonomy) : null;
        if (! $term instanceof \WP_Term || is_wp_error($term)) {
            return;
        }

        self::add_template_term_dependency_ref($term_id, $taxonomy, $term, $value, $path, $control_name, $dependency_refs);
    }

    /**
     * @param mixed $value
     * @param array<int, string> $path
     * @param array<int, array<string, mixed>> $dependency_refs
     * @return void
     */
    private static function maybe_add_template_query_dependency_refs($key, $value, array $path, array &$dependency_refs)
    {
        if (! self::template_path_parent_is_query($path)) {
            return;
        }

        $control_name = sanitize_key((string) $key);
        if (in_array($control_name, ['post__in', 'post__not_in'], true)) {
            self::add_template_query_post_dependency_refs($control_name, $value, $path, $dependency_refs);
            return;
        }

        if (in_array($control_name, ['tax_query', 'tax_query_not'], true)) {
            self::add_template_query_term_dependency_refs($control_name, $value, $path, $dependency_refs);
        }
    }

    /**
     * @param mixed $value
     * @param array<int, string> $path
     * @param array<int, array<string, mixed>> $dependency_refs
     * @return void
     */
    private static function add_template_query_post_dependency_refs($control_name, $value, array $path, array &$dependency_refs)
    {
        foreach (self::collect_template_scalar_reference_values($value, $path) as $candidate) {
            $source_value = $candidate['value'] ?? null;
            $post_id = self::template_positive_integer_reference_value($source_value);
            if ($post_id <= 0) {
                continue;
            }

            $post = get_post($post_id);
            if (! $post instanceof \WP_Post || $post->post_type === 'attachment') {
                continue;
            }

            self::add_template_post_dependency_ref(
                $post_id,
                $post,
                $source_value,
                (array) ($candidate['path'] ?? $path),
                $control_name,
                $dependency_refs,
                $control_name
            );
        }
    }

    /**
     * @param mixed $value
     * @param array<int, string> $path
     * @param array<int, array<string, mixed>> $dependency_refs
     * @return void
     */
    private static function add_template_query_term_dependency_refs($control_name, $value, array $path, array &$dependency_refs)
    {
        foreach (self::collect_template_scalar_reference_values($value, $path) as $candidate) {
            $source_value = $candidate['value'] ?? null;
            $parsed = self::parse_template_scoped_term_reference($source_value);
            if (empty($parsed)) {
                continue;
            }

            $term_id = (int) ($parsed['term_id'] ?? 0);
            $taxonomy = sanitize_key((string) ($parsed['taxonomy'] ?? ''));
            $term = $term_id > 0 && $taxonomy !== '' ? get_term($term_id, $taxonomy) : null;
            if (! $term instanceof \WP_Term || is_wp_error($term)) {
                continue;
            }

            self::add_template_term_dependency_ref(
                $term_id,
                $taxonomy,
                $term,
                $source_value,
                (array) ($candidate['path'] ?? $path),
                $control_name,
                $dependency_refs,
                $control_name
            );
        }
    }

    /**
     * @param array<int, string> $path
     */
    private static function template_path_parent_is_query(array $path)
    {
        $count = count($path);
        return $count >= 2 && (string) $path[$count - 2] === 'query';
    }

    /**
     * @param mixed $value
     * @param array<int, string> $path
     * @return array<int, array{value:mixed,path:array<int, string>}>
     */
    private static function collect_template_scalar_reference_values($value, array $path)
    {
        if (is_scalar($value)) {
            return [
                [
                    'value' => $value,
                    'path' => $path,
                ],
            ];
        }

        if (! is_array($value)) {
            return [];
        }

        $references = [];
        foreach ($value as $index => $item) {
            if (! is_scalar($item)) {
                continue;
            }
            $references[] = [
                'value' => $item,
                'path' => array_merge($path, [(string) $index]),
            ];
        }

        return $references;
    }

    /**
     * @param mixed $value
     */
    private static function template_positive_integer_reference_value($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }

        if (! is_string($value)) {
            return 0;
        }

        $value = trim($value);
        if ($value === '' || preg_match('/^[0-9]+$/', $value) !== 1) {
            return 0;
        }

        return absint($value);
    }

    /**
     * @param mixed $value
     * @param array<int, string> $path
     * @param array<int, array<string, mixed>> $dependency_refs
     * @return void
     */
    private static function add_template_post_dependency_ref($post_id, \WP_Post $post, $value, array $path, $control_name, array &$dependency_refs, $query_ref_kind = '')
    {
        $control_name = sanitize_key((string) $control_name);
        $context = self::build_template_post_reference_context($post_id, $post);
        $descriptor = [
            'ref_type' => 'post_or_term',
            'entity_kind' => 'post',
            'source_id' => (int) $post_id,
            'source_url' => self::get_post_reference_url($post_id),
            'entity_uid' => (string) ($context['vf_object_uid'] ?? ''),
            'object_subtype' => (string) ($context['post_type'] ?? ''),
            'object_slug' => (string) ($context['slug'] ?? ''),
            'source_value_type' => self::template_reference_value_type($value),
            'payload_path' => implode('.', $path),
            'path' => $path,
            'control_name' => $control_name,
            'required' => false,
            'confidence' => 'high',
            'resolution_strategy' => 'entity_uid_then_slug',
            'target_id' => 0,
            'status' => 'pending',
        ];
        $query_ref_kind = sanitize_key((string) $query_ref_kind);
        if ($query_ref_kind !== '') {
            $descriptor['query_ref_kind'] = $query_ref_kind;
        }

        $dependency_refs[] = $descriptor;
    }

    /**
     * @param mixed $value
     * @param array<int, string> $path
     * @param array<int, array<string, mixed>> $dependency_refs
     * @return void
     */
    private static function add_template_term_dependency_ref($term_id, $taxonomy, \WP_Term $term, $value, array $path, $control_name, array &$dependency_refs, $query_ref_kind = '')
    {
        $taxonomy = sanitize_key((string) $taxonomy);
        $control_name = sanitize_key((string) $control_name);
        $context = self::build_template_term_reference_context($term_id, $taxonomy, $term);
        $descriptor = [
            'ref_type' => 'post_or_term',
            'entity_kind' => 'term',
            'source_id' => (int) $term_id,
            'source_url' => self::get_term_reference_url($term_id, $taxonomy),
            'entity_uid' => (string) ($context['vf_object_uid'] ?? ''),
            'object_subtype' => $taxonomy,
            'object_slug' => (string) ($context['slug'] ?? ''),
            'source_value_type' => 'scoped_term',
            'payload_path' => implode('.', $path),
            'path' => $path,
            'control_name' => $control_name,
            'required' => false,
            'confidence' => 'high',
            'resolution_strategy' => 'entity_uid_then_slug',
            'target_id' => 0,
            'status' => 'pending',
        ];
        $query_ref_kind = sanitize_key((string) $query_ref_kind);
        if ($query_ref_kind !== '') {
            $descriptor['query_ref_kind'] = $query_ref_kind;
        }

        $dependency_refs[] = $descriptor;
    }

    /**
     * @return array<string, string>
     */
    private static function build_template_post_reference_context($post_id, \WP_Post $post)
    {
        $context = [
            'post_type' => sanitize_key((string) $post->post_type),
            'slug' => sanitize_title((string) $post->post_name),
            'title' => sanitize_text_field((string) $post->post_title),
        ];

        $uid = get_post_meta((int) $post_id, 'vf_object_uid', true);
        $uid = is_string($uid) ? trim($uid) : '';
        if ($uid === '' && class_exists('DBVC_Sync_Posts') && method_exists('DBVC_Sync_Posts', 'ensure_post_uid')) {
            $uid = (string) DBVC_Sync_Posts::ensure_post_uid((int) $post_id, $post);
        }
        if ($uid !== '') {
            $context['vf_object_uid'] = sanitize_text_field($uid);
        }

        return $context;
    }

    /**
     * @return array<string, string>
     */
    private static function build_template_term_reference_context($term_id, $taxonomy, \WP_Term $term)
    {
        $taxonomy = sanitize_key((string) $taxonomy);
        $context = [
            'taxonomy' => $taxonomy,
            'slug' => sanitize_title((string) $term->slug),
            'name' => sanitize_text_field((string) $term->name),
        ];

        $uid = get_term_meta((int) $term_id, 'vf_object_uid', true);
        $uid = is_string($uid) ? trim($uid) : '';
        if ($uid === '' && class_exists('DBVC_Sync_Taxonomies') && method_exists('DBVC_Sync_Taxonomies', 'ensure_term_uid')) {
            $uid = (string) DBVC_Sync_Taxonomies::ensure_term_uid((int) $term_id, $taxonomy);
        }
        if ($uid !== '') {
            $context['vf_object_uid'] = sanitize_text_field($uid);
        }

        return $context;
    }

    /**
     * @param mixed $value
     * @return array{taxonomy:string,term_id:int}|array<string, mixed>
     */
    private static function parse_template_scoped_term_reference($value)
    {
        if (! is_scalar($value)) {
            return [];
        }

        $value = trim((string) $value);
        if (! preg_match('/^([A-Za-z0-9_-]+)::([0-9]+)$/', $value, $matches)) {
            return [];
        }

        $taxonomy = sanitize_key((string) ($matches[1] ?? ''));
        $term_id = absint((int) ($matches[2] ?? 0));
        if ($taxonomy === '' || $term_id <= 0) {
            return [];
        }

        return [
            'taxonomy' => $taxonomy,
            'term_id' => $term_id,
        ];
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function template_reference_value_type($value)
    {
        return is_int($value) ? 'integer' : 'string';
    }

    private static function get_post_reference_url($post_id)
    {
        $url = get_permalink((int) $post_id);
        return is_string($url) ? esc_url_raw($url) : '';
    }

    private static function get_term_reference_url($term_id, $taxonomy)
    {
        $url = get_term_link((int) $term_id, sanitize_key((string) $taxonomy));
        return is_string($url) && ! is_wp_error($url) ? esc_url_raw($url) : '';
    }

    /**
     * @param string $key
     * @param array<int|string, mixed> $parent
     * @return bool
     */
    private static function template_key_is_media_attachment_id($key, array $parent)
    {
        $key = sanitize_key((string) $key);
        if ($key === 'id') {
            return isset($parent['url']) || isset($parent['src']);
        }

        return in_array($key, [
            'attachment',
            'attachmentid',
            'attachment_id',
            'background',
            'backgroundimage',
            'background_image',
            'gallery',
            'image',
            'imageid',
            'image_id',
            'logo',
            'media',
            'mediaid',
            'media_id',
            'poster',
            'thumbnail',
            'video',
        ], true);
    }

    /**
     * @param string $key
     * @return bool
     */
    private static function template_key_is_nested_template_id($key)
    {
        return in_array(sanitize_key((string) $key), [
            'template',
            'templateid',
            'template_id',
            'templatepostid',
            'template_post_id',
        ], true);
    }

    /**
     * @param array<int|string, mixed> $parent
     * @param array<int, string> $parent_path
     * @return array<int, string>
     */
    private static function find_template_reference_url_path(array $parent, array $parent_path)
    {
        foreach (['url', 'src'] as $url_key) {
            if (isset($parent[$url_key]) && is_string($parent[$url_key]) && $parent[$url_key] !== '') {
                return array_merge($parent_path, [$url_key]);
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $domain_definition
     * @param array<string, mixed> $option_values
     * @param string $source
     * @return array<string, mixed>
     */
    private static function normalize_icon_collections_domain(array $domain_definition, array $option_values, $source)
    {
        $domain_key = 'icon_collections';
        $label = sanitize_text_field((string) ($domain_definition['label'] ?? 'Bricks Icon Collections'));
        $sets = isset($option_values['bricks_icon_sets']) && is_array($option_values['bricks_icon_sets']) ? $option_values['bricks_icon_sets'] : [];
        $icons = isset($option_values['bricks_custom_icons']) && is_array($option_values['bricks_custom_icons']) ? $option_values['bricks_custom_icons'] : [];
        $disabled = isset($option_values['bricks_disabled_icon_sets']) && is_array($option_values['bricks_disabled_icon_sets']) ? $option_values['bricks_disabled_icon_sets'] : [];
        $disabled_lookup = self::build_disabled_icon_set_lookup($disabled);
        $icons_by_set = [];
        $media_refs = [];
        $warnings = [];

        foreach ($icons as $icon) {
            if (! is_array($icon)) {
                continue;
            }
            $set_id = sanitize_text_field((string) ($icon['setId'] ?? $icon['set_id'] ?? ''));
            if ($set_id === '') {
                $set_id = '__unassigned';
            }
            if (! isset($icons_by_set[$set_id])) {
                $icons_by_set[$set_id] = [];
            }
            $icons_by_set[$set_id][] = $icon;
        }

        $objects = [];
        $seen_set_ids = [];
        foreach ($sets as $index => $set) {
            if (! is_array($set)) {
                continue;
            }
            $set_id = sanitize_text_field((string) ($set['id'] ?? $set['setId'] ?? 'set-' . $index));
            if ($set_id === '') {
                $set_id = 'set-' . $index;
            }
            $seen_set_ids[$set_id] = true;
            $object = self::build_icon_collection_object($set, (array) ($icons_by_set[$set_id] ?? []), isset($disabled_lookup[$set_id]), $domain_key, (int) $index);
            foreach ((array) ($object['media_refs'] ?? []) as $ref) {
                if (is_array($ref)) {
                    $media_refs[(string) ($ref['media_key'] ?? '')] = $ref;
                    if (empty($ref['file_available'])) {
                        $warnings[] = 'Icon attachment file is missing for attachment ID ' . (int) ($ref['source_attachment_id'] ?? 0) . '.';
                    }
                }
            }
            $objects[] = $object;
        }

        foreach ($icons_by_set as $set_id => $set_icons) {
            if ($set_id !== '__unassigned' && isset($seen_set_ids[$set_id])) {
                continue;
            }
            $set = [
                'id' => $set_id,
                'name' => $set_id === '__unassigned' ? 'Unassigned Icons' : 'Missing Icon Set: ' . $set_id,
            ];
            $object = self::build_icon_collection_object($set, (array) $set_icons, isset($disabled_lookup[$set_id]), $domain_key, count($objects));
            $object['warnings'][] = $set_id === '__unassigned'
                ? 'Custom icons exist without an icon set ID.'
                : 'Custom icons reference an icon set that is not present in bricks_icon_sets.';
            foreach ((array) ($object['media_refs'] ?? []) as $ref) {
                if (is_array($ref)) {
                    $media_refs[(string) ($ref['media_key'] ?? '')] = $ref;
                }
            }
            $objects[] = $object;
        }

        $metadata_rows = self::build_related_metadata_rows($domain_definition, $option_values, $domain_key);

        return [
            'domain_key' => $domain_key,
            'label' => $label,
            'mode' => 'collection',
            'source' => sanitize_key((string) $source),
            'normalization_version' => self::NORMALIZATION_VERSION,
            'source_option_names' => (array) ($domain_definition['option_names'] ?? []),
            'option_values' => $option_values,
            'primary_option' => sanitize_key((string) ($domain_definition['primary_option'] ?? 'bricks_icon_sets')),
            'objects' => $objects,
            'metadata_rows' => $metadata_rows,
            'warnings' => array_values(array_unique(array_map('sanitize_text_field', $warnings))),
            'verification' => DBVC_Bricks_Portability_Domain_Verifier::verify_domain_payload($domain_definition, $option_values, $source),
            'transport' => [
                'shape' => 'entity_collection',
                'path' => ['bricks_icon_sets', 'bricks_custom_icons'],
                'wrapper_shape' => 'options',
            ],
            'media_refs' => array_values(array_filter($media_refs)),
            'domain_fingerprint' => self::build_domain_fingerprint($objects, $metadata_rows),
        ];
    }

    /**
     * @param array<string, mixed> $set
     * @param array<int, array<string, mixed>> $icons
     * @param bool $disabled
     * @param string $domain_key
     * @param int $index
     * @return array<string, mixed>
     */
    private static function build_icon_collection_object(array $set, array $icons, $disabled, $domain_key, $index)
    {
        $set_id = sanitize_text_field((string) ($set['id'] ?? $set['setId'] ?? 'set-' . $index));
        if ($set_id === '') {
            $set_id = 'set-' . $index;
        }
        $display_name = sanitize_text_field((string) ($set['name'] ?? $set['label'] ?? $set_id));
        if ($display_name === '') {
            $display_name = $set_id;
        }

        $media_refs = [];
        $normalized_icons = [];
        foreach ($icons as $icon) {
            if (! is_array($icon)) {
                continue;
            }
            $icon_ref = self::build_icon_media_ref($icon, $domain_key);
            if (! empty($icon_ref)) {
                $media_refs[(string) ($icon_ref['media_key'] ?? '')] = $icon_ref;
            }
            $normalized_icons[] = self::normalize_icon_record_for_compare($icon, $icon_ref);
        }

        $normalized_payload = self::normalize_value([
            'set' => $set,
            'icons' => $normalized_icons,
            'disabled' => (bool) $disabled,
        ], $domain_key, false);

        return [
            'source_key' => $domain_key . ':set:' . $set_id,
            'display_name' => $display_name,
            'object_id' => $set_id,
            'match_keys' => array_filter([
                'id' => $set_id,
                'name' => $display_name,
                'slug' => sanitize_title($display_name),
            ]),
            'fingerprint' => DBVC_Bricks_Portability_Utils::fingerprint($normalized_payload),
            'references' => [
                'css_variables' => [],
                'class_names' => [],
                'category_values' => [],
                'category_option_name' => '',
                'media_refs' => array_values($media_refs),
            ],
            'warnings' => self::build_media_reference_warnings($media_refs, 'icon'),
            'raw' => [
                'set' => $set,
                'icons' => array_values($icons),
                'disabled' => (bool) $disabled,
            ],
            'normalized' => $normalized_payload,
            'source_index' => (int) $index,
            'map_key' => $set_id,
            'transport' => [
                'shape' => 'entity',
                'path' => ['bricks_icon_sets', $set_id],
                'wrapper_shape' => 'options',
            ],
            'media_refs' => array_values($media_refs),
        ];
    }

    /**
     * @param array<string, mixed> $domain_definition
     * @param array<string, mixed> $option_values
     * @param string $domain_key
     * @return array<int, array<string, mixed>>
     */
    private static function build_related_metadata_rows(array $domain_definition, array $option_values, $domain_key)
    {
        $rows = [];
        foreach ((array) ($domain_definition['related_options'] ?? []) as $option_name) {
            $option_name = sanitize_key((string) $option_name);
            if ($option_name === '' || ! array_key_exists($option_name, $option_values)) {
                continue;
            }

            $raw = $option_values[$option_name];
            $normalized_payload = self::normalize_value($raw, $domain_key, false);
            $rows[] = [
                'row_id' => $domain_key . '::meta::' . $option_name,
                'row_type' => 'meta',
                'display_name' => 'Related Metadata: ' . $option_name,
                'object_id' => $option_name,
                'option_name' => $option_name,
                'fingerprint' => DBVC_Bricks_Portability_Utils::fingerprint($normalized_payload),
                'raw' => $raw,
                'normalized' => $normalized_payload,
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $objects
     * @param array<int, array<string, mixed>> $metadata_rows
     * @return string
     */
    private static function build_domain_fingerprint(array $objects, array $metadata_rows)
    {
        return DBVC_Bricks_Portability_Utils::fingerprint([
            'objects' => array_map(static function ($object) {
                return [
                    'source_key' => (string) ($object['source_key'] ?? ''),
                    'fingerprint' => (string) ($object['fingerprint'] ?? ''),
                ];
            }, $objects),
            'metadata_rows' => array_map(static function ($row) {
                return [
                    'row_id' => (string) ($row['row_id'] ?? ''),
                    'fingerprint' => (string) ($row['fingerprint'] ?? ''),
                ];
            }, $metadata_rows),
        ]);
    }

    /**
     * @param mixed $font_faces
     * @return array<int, int>
     */
    private static function extract_font_face_attachment_ids($font_faces)
    {
        $ids = [];
        self::walk_font_faces_for_attachment_ids($font_faces, $ids);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * @param mixed $value
     * @param array<int, int> $ids
     * @param string $key
     * @return void
     */
    private static function walk_font_faces_for_attachment_ids($value, array &$ids, $key = '')
    {
        $key = sanitize_key((string) $key);
        if (is_array($value)) {
            foreach ($value as $child_key => $child_value) {
                self::walk_font_faces_for_attachment_ids($child_value, $ids, (string) $child_key);
            }
            return;
        }

        if (! in_array($key, self::get_media_attachment_candidate_keys(), true)) {
            return;
        }

        if (is_numeric($value) && (int) $value > 0) {
            $ids[] = (int) $value;
        }
    }

    /**
     * @param mixed $font_faces
     * @return mixed
     */
    private static function normalize_font_faces_for_compare($font_faces)
    {
        if (! is_array($font_faces)) {
            return $font_faces;
        }

        $normalized = [];
        foreach ($font_faces as $key => $value) {
            $clean_key = is_int($key) ? $key : (string) $key;
            if (in_array(sanitize_key((string) $key), self::get_media_attachment_candidate_keys(), true) && is_numeric($value)) {
                $normalized[$clean_key] = self::build_media_compare_payload((int) $value, 'custom_fonts', 'font_file');
                continue;
            }
            $normalized[$clean_key] = self::normalize_font_faces_for_compare($value);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $icon
     * @param array<string, mixed> $media_ref
     * @return array<string, mixed>
     */
    private static function normalize_icon_record_for_compare(array $icon, array $media_ref = [])
    {
        $normalized = $icon;
        unset($normalized['attachment_id'], $normalized['attachmentId'], $normalized['url']);
        if (! empty($media_ref)) {
            $normalized['media'] = self::media_ref_compare_payload($media_ref);
        }

        return self::normalize_value($normalized, 'icon_collections', false);
    }

    /**
     * @param array<string, mixed> $icon
     * @param string $domain_key
     * @return array<string, mixed>
     */
    private static function build_icon_media_ref(array $icon, $domain_key)
    {
        foreach (['attachment_id', 'attachmentId'] as $key) {
            if (! empty($icon[$key]) && is_numeric($icon[$key])) {
                return self::build_attachment_media_ref((int) $icon[$key], $domain_key, 'icon_svg');
            }
        }

        return [];
    }

    /**
     * @param int $attachment_id
     * @param string $domain_key
     * @param string $usage
     * @return array<string, mixed>
     */
    private static function build_attachment_media_ref($attachment_id, $domain_key, $usage)
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return [];
        }

        $file = get_attached_file($attachment_id);
        $file = is_string($file) ? wp_normalize_path($file) : '';
        $url = wp_get_attachment_url($attachment_id);
        $mime_type = get_post_mime_type($attachment_id);
        $filename = $file !== '' ? basename($file) : basename((string) parse_url((string) $url, PHP_URL_PATH));
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $checksum = '';
        $file_available = $file !== '' && is_file($file) && is_readable($file);
        $size = 0;
        if ($file_available) {
            $hash = hash_file('sha256', $file);
            $checksum = is_string($hash) && $hash !== '' ? 'sha256:' . $hash : '';
            $size_result = filesize($file);
            $size = is_int($size_result) ? $size_result : 0;
        }

        return [
            'media_key' => $checksum !== '' ? 'media:' . substr($checksum, 7) : 'attachment:' . $attachment_id,
            'domain_key' => sanitize_key((string) $domain_key),
            'usage' => sanitize_key((string) $usage),
            'source_attachment_id' => $attachment_id,
            'source_url' => is_string($url) ? esc_url_raw($url) : '',
            'source_file' => $file,
            'filename' => sanitize_file_name($filename !== '' ? $filename : 'attachment-' . $attachment_id),
            'extension' => sanitize_key($extension),
            'mime_type' => sanitize_text_field((string) $mime_type),
            'checksum' => $checksum,
            'file_size' => $size,
            'file_available' => $file_available,
        ];
    }

    /**
     * @param int $attachment_id
     * @param string $domain_key
     * @param string $usage
     * @return array<string, mixed>
     */
    private static function build_media_compare_payload($attachment_id, $domain_key, $usage)
    {
        return self::media_ref_compare_payload(self::build_attachment_media_ref($attachment_id, $domain_key, $usage));
    }

    /**
     * @param array<string, mixed> $ref
     * @return array<string, mixed>
     */
    private static function media_ref_compare_payload(array $ref)
    {
        if (empty($ref)) {
            return [];
        }

        return [
            'checksum' => sanitize_text_field((string) ($ref['checksum'] ?? '')),
            'filename' => sanitize_file_name((string) ($ref['filename'] ?? '')),
            'extension' => sanitize_key((string) ($ref['extension'] ?? '')),
            'mime_type' => sanitize_text_field((string) ($ref['mime_type'] ?? '')),
            'file_available' => ! empty($ref['file_available']),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $media_refs
     * @param string $label
     * @return array<int, string>
     */
    private static function build_media_reference_warnings(array $media_refs, $label)
    {
        $warnings = [];
        foreach ($media_refs as $ref) {
            if (! is_array($ref) || ! empty($ref['file_available'])) {
                continue;
            }
            $warnings[] = sprintf(
                'The %1$s media file `%2$s` is missing or unreadable.',
                sanitize_text_field((string) $label),
                sanitize_file_name((string) ($ref['filename'] ?? ''))
            );
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @return array<int, string>
     */
    private static function get_media_attachment_candidate_keys()
    {
        return ['woff2', 'woff', 'ttf', 'otf', 'eot', 'svg'];
    }

    /**
     * @param array<int|string, mixed> $disabled
     * @return array<string, bool>
     */
    private static function build_disabled_icon_set_lookup(array $disabled)
    {
        $lookup = [];
        foreach ($disabled as $key => $value) {
            if (is_string($key) && ! is_int($key)) {
                if (! empty($value)) {
                    $lookup[sanitize_text_field($key)] = true;
                }
                continue;
            }

            if (is_scalar($value)) {
                $clean = sanitize_text_field((string) $value);
                if ($clean !== '') {
                    $lookup[$clean] = true;
                }
            }
        }

        return $lookup;
    }

    /**
     * @param mixed $raw
     * @param string $domain_key
     * @return array<int, string>
     */
    public static function extract_category_reference_values($raw, $domain_key)
    {
        $domain_key = sanitize_key((string) $domain_key);
        if (! in_array($domain_key, ['global_classes', 'global_variables'], true)) {
            return [];
        }

        $references = [];
        self::walk_for_category_references($raw, $references);
        $references = array_values(array_unique(array_filter($references)));
        sort($references, SORT_STRING);
        return $references;
    }

    /**
     * @param mixed $raw
     * @return array<int, string>
     */
    public static function extract_category_lookup_values($raw)
    {
        $values = [];
        self::walk_for_category_lookup_values($raw, $values);
        $values = array_values(array_unique(array_filter($values)));
        sort($values, SORT_STRING);
        return $values;
    }

    /**
     * @param mixed $raw
     * @param string $domain_key
     * @return array<string, mixed>
     */
    private static function extract_collection($raw, $domain_key)
    {
        $transport = [
            'shape' => 'singleton',
            'path' => [],
            'wrapper_shape' => 'root',
        ];
        $warnings = [];
        $objects = [];

        if (! is_array($raw)) {
            if ($raw !== null && $raw !== '') {
                $warnings[] = 'Primary domain option is not an array; using singleton fallback.';
                $objects[] = self::build_collection_object($raw, $domain_key, 0, '', $transport);
            }

            return [
                'objects' => $objects,
                'transport' => $transport,
                'warnings' => $warnings,
            ];
        }

        $candidate_keys = self::get_collection_keys($domain_key);
        foreach ($candidate_keys as $candidate_key) {
            if (! array_key_exists($candidate_key, $raw) || ! is_array($raw[$candidate_key])) {
                continue;
            }

            $container = $raw[$candidate_key];
            $transport = [
                'shape' => DBVC_Bricks_Portability_Utils::is_assoc($container) ? 'map' : 'list',
                'path' => [$candidate_key],
                'wrapper_shape' => 'object',
            ];
            $objects = self::hydrate_collection_objects($container, $domain_key, $transport);
            return [
                'objects' => $objects,
                'transport' => $transport,
                'warnings' => $warnings,
            ];
        }

        if (! DBVC_Bricks_Portability_Utils::is_assoc($raw)) {
            $transport = [
                'shape' => 'list',
                'path' => [],
                'wrapper_shape' => 'root',
            ];

            return [
                'objects' => self::hydrate_collection_objects($raw, $domain_key, $transport),
                'transport' => $transport,
                'warnings' => $warnings,
            ];
        }

        if (self::looks_like_map_of_objects($raw)) {
            $transport = [
                'shape' => 'map',
                'path' => [],
                'wrapper_shape' => 'root',
            ];

            return [
                'objects' => self::hydrate_collection_objects($raw, $domain_key, $transport),
                'transport' => $transport,
                'warnings' => $warnings,
            ];
        }

        $warnings[] = 'Collection shape could not be confidently derived; using singleton fallback.';
        $transport = [
            'shape' => 'singleton',
            'path' => [],
            'wrapper_shape' => 'root',
        ];
        $objects[] = self::build_collection_object($raw, $domain_key, 0, '', $transport);

        return [
            'objects' => $objects,
            'transport' => $transport,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int|string, mixed> $container
     * @param string $domain_key
     * @param array<string, mixed> $transport
     * @return array<int, array<string, mixed>>
     */
    private static function hydrate_collection_objects(array $container, $domain_key, array $transport)
    {
        $objects = [];
        $index = 0;
        foreach ($container as $key => $item) {
            $map_key = is_int($key) ? '' : sanitize_text_field((string) $key);
            $objects[] = self::build_collection_object($item, $domain_key, $index, $map_key, $transport);
            $index++;
        }

        return $objects;
    }

    /**
     * @param mixed $raw
     * @param string $domain_key
     * @param int $index
     * @param string $map_key
     * @param array<string, mixed> $transport
     * @return array<string, mixed>
     */
    private static function build_collection_object($raw, $domain_key, $index, $map_key, array $transport)
    {
        $normalized = self::normalize_value($raw, $domain_key, false);
        $object_id = self::pick_object_id($raw, $domain_key, $map_key, $index);
        $display_name = self::pick_display_name($raw, $domain_key, $object_id, $map_key, $index);
        $class_refs = self::extract_class_references($raw);
        $category_refs = self::extract_category_reference_values($raw, $domain_key);

        $match_keys = [
            'id' => self::pick_first_value($raw, self::get_id_candidates($domain_key)),
            'name' => self::pick_first_value($raw, self::get_name_candidates($domain_key)),
            'slug' => self::pick_first_value($raw, self::get_slug_candidates($domain_key)),
            'token' => self::pick_first_value($raw, self::get_token_candidates($domain_key)),
            'selector' => self::pick_first_value($raw, self::get_selector_candidates($domain_key)),
            'map_key' => $map_key,
        ];

        if ($display_name === '') {
            $display_name = $object_id !== '' ? $object_id : ($map_key !== '' ? $map_key : 'Object ' . ((int) $index + 1));
        }

        $source_key = $domain_key . ':' . ($map_key !== '' ? $map_key : ($object_id !== '' ? $object_id : sanitize_title($display_name)));
        $warnings = [];
        if ($object_id === '' && trim((string) $match_keys['name']) === '' && $map_key === '') {
            $warnings[] = 'Object has no stable id or name and may require manual review.';
        }

        return [
            'source_key' => $source_key,
            'display_name' => $display_name,
            'object_id' => $object_id,
            'match_keys' => array_filter($match_keys, static function ($value) {
                return $value !== null && $value !== '';
            }),
            'fingerprint' => DBVC_Bricks_Portability_Utils::fingerprint($normalized),
            'references' => [
                'css_variables' => DBVC_Bricks_Portability_Utils::extract_css_variable_tokens($raw),
                'class_names' => $class_refs,
                'category_values' => $category_refs,
                'category_option_name' => self::get_category_option_name($domain_key),
            ],
            'warnings' => $warnings,
            'raw' => $raw,
            'normalized' => $normalized,
            'source_index' => (int) $index,
            'map_key' => $map_key,
            'transport' => $transport,
        ];
    }

    /**
     * @param mixed $value
     * @param string $domain_key
     * @param bool $preserve_numeric_order
     * @return mixed
     */
    public static function normalize_value($value, $domain_key, $preserve_numeric_order = false)
    {
        unset($domain_key);

        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $string_key = is_int($key) ? (string) $key : (string) $key;
                if (in_array($string_key, self::VOLATILE_KEYS, true)) {
                    continue;
                }
                $clean[$key] = self::normalize_value($item, '', $preserve_numeric_order);
            }

            return DBVC_Bricks_Portability_Utils::deep_sort($clean, $preserve_numeric_order);
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    /**
     * @param string $domain_key
     * @return array<int, string>
     */
    private static function get_collection_keys($domain_key)
    {
        $map = [
            'color_palette' => ['palettes', 'colors', 'items', 'palette'],
            'global_classes' => ['classes', 'items', 'globalClasses'],
            'global_variables' => ['variables', 'items', 'globalVariables'],
            'pseudo_classes' => ['pseudoClasses', 'items', 'classes'],
            'theme_styles' => ['styles', 'items', 'themeStyles'],
            'components' => ['components', 'items', 'globalElements'],
        ];

        return $map[$domain_key] ?? ['items'];
    }

    /**
     * @param array<int|string, mixed> $value
     * @return bool
     */
    private static function looks_like_map_of_objects(array $value)
    {
        if (! DBVC_Bricks_Portability_Utils::is_assoc($value)) {
            return false;
        }

        $total = count($value);
        if ($total === 0) {
            return false;
        }

        $array_values = 0;
        foreach ($value as $item) {
            if (is_array($item)) {
                $array_values++;
            }
        }

        return $array_values >= max(1, (int) floor($total / 2));
    }

    /**
     * @param mixed $raw
     * @param string $domain_key
     * @param string $map_key
     * @param int $index
     * @return string
     */
    private static function pick_object_id($raw, $domain_key, $map_key, $index)
    {
        $value = self::pick_first_value($raw, self::get_id_candidates($domain_key));
        if ($value !== '') {
            return $value;
        }
        if ($map_key !== '') {
            return $map_key;
        }
        return 'index-' . (int) $index;
    }

    /**
     * @param mixed $raw
     * @param string $domain_key
     * @param string $object_id
     * @param string $map_key
     * @param int $index
     * @return string
     */
    private static function pick_display_name($raw, $domain_key, $object_id, $map_key, $index)
    {
        $value = self::pick_first_value($raw, self::get_name_candidates($domain_key));
        if ($value !== '') {
            return $value;
        }
        if ($map_key !== '') {
            return $map_key;
        }
        if ($object_id !== '') {
            return $object_id;
        }
        return 'Object ' . ((int) $index + 1);
    }

    /**
     * @param mixed $raw
     * @param array<int, string> $candidates
     * @return string
     */
    private static function pick_first_value($raw, array $candidates)
    {
        if (! is_array($raw)) {
            return is_scalar($raw) ? DBVC_Bricks_Portability_Utils::normalize_string($raw) : '';
        }

        foreach ($candidates as $candidate) {
            $value = self::read_candidate_value($raw, $candidate);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return DBVC_Bricks_Portability_Utils::normalize_string($value);
            }
        }

        return '';
    }

    /**
     * @param string $domain_key
     * @return array<int, string>
     */
    private static function get_id_candidates($domain_key)
    {
        $domain_key = sanitize_key((string) $domain_key);
        if ($domain_key === 'pseudo_classes') {
            return ['selector', 'id', '_id', 'uuid', 'uid', 'key', 'slug', 'token'];
        }
        if ($domain_key === 'components') {
            return ['id', '_id', 'uuid', 'uid', 'key', 'slug', 'token', 'elements.0.id', 'elements.0.name'];
        }
        return ['id', '_id', 'uuid', 'uid', 'key', 'slug', 'token'];
    }

    /**
     * @param string $domain_key
     * @return array<int, string>
     */
    private static function get_name_candidates($domain_key)
    {
        $domain_key = sanitize_key((string) $domain_key);
        if ($domain_key === 'pseudo_classes') {
            return ['selector', 'name', 'label', 'title', 'slug', 'token'];
        }
        if ($domain_key === 'components') {
            return ['label', 'name', 'title', 'elements.0.label', 'elements.0.name', 'settings.label', 'slug', 'token'];
        }
        return ['name', 'label', 'title', 'slug', 'token'];
    }

    /**
     * @param string $domain_key
     * @return array<int, string>
     */
    private static function get_slug_candidates($domain_key)
    {
        $domain_key = sanitize_key((string) $domain_key);
        if ($domain_key === 'components') {
            return ['slug', 'handle', 'token', 'id', 'uid', 'uuid', 'elements.0.name'];
        }
        return ['slug', 'handle', 'token'];
    }

    /**
     * @param string $domain_key
     * @return array<int, string>
     */
    private static function get_token_candidates($domain_key)
    {
        $domain_key = sanitize_key((string) $domain_key);
        if ($domain_key === 'global_variables') {
            return ['token', 'name', 'slug'];
        }
        if ($domain_key === 'components') {
            return ['token', 'slug', 'name', 'elements.0.name', 'elements.0.label'];
        }
        return ['token', 'name', 'slug'];
    }

    /**
     * @param string $domain_key
     * @return array<int, string>
     */
    private static function get_selector_candidates($domain_key)
    {
        $domain_key = sanitize_key((string) $domain_key);
        if ($domain_key === 'pseudo_classes') {
            return ['selector', 'name', 'label'];
        }
        return ['selector', 'name'];
    }

    /**
     * @param array<string, mixed> $raw
     * @param string $candidate
     * @return mixed
     */
    private static function read_candidate_value(array $raw, $candidate)
    {
        $candidate = (string) $candidate;
        if ($candidate === '') {
            return null;
        }

        if (strpos($candidate, '.') === false) {
            return array_key_exists($candidate, $raw) ? $raw[$candidate] : null;
        }

        return self::read_path($raw, $candidate);
    }

    /**
     * @param array<string, mixed> $payload
     * @param string $path
     * @return mixed
     */
    private static function read_path(array $payload, $path)
    {
        $value = $payload;
        foreach (explode('.', (string) $path) as $part) {
            if (! is_array($value)) {
                return null;
            }
            if (ctype_digit($part)) {
                $index = (int) $part;
                if (! array_key_exists($index, $value)) {
                    return null;
                }
                $value = $value[$index];
                continue;
            }
            if (! array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /**
     * @param mixed $raw
     * @return array<int, string>
     */
    private static function extract_class_references($raw)
    {
        $references = [];
        self::walk_for_class_references($raw, $references);
        $references = array_values(array_unique(array_filter($references)));
        sort($references, SORT_STRING);
        return $references;
    }

    /**
     * @param mixed $value
     * @param array<int, string> $references
     * @param string $key
     * @return void
     */
    private static function walk_for_class_references($value, array &$references, $key = '')
    {
        $key = sanitize_key((string) $key);
        if (is_array($value)) {
            foreach ($value as $child_key => $child_value) {
                self::walk_for_class_references($child_value, $references, (string) $child_key);
            }
            return;
        }

        if (! is_scalar($value)) {
            return;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return;
        }

        if (in_array($key, ['class', 'classes', 'classname', 'class_name', 'globalclasses'], true)) {
            foreach (preg_split('/\s+|,/', $string) as $token) {
                $token = sanitize_text_field((string) $token);
                if ($token !== '') {
                    $references[] = $token;
                }
            }
        }
    }

    /**
     * @param array<int, string> $warnings
     * @param array<int, string> $next
     * @return array<int, string>
     */
    private static function merge_warning_lists(array $warnings, array $next)
    {
        return array_values(array_unique(array_map('sanitize_text_field', array_merge($warnings, $next))));
    }

    /**
     * @param string $domain_key
     * @return string
     */
    private static function get_category_option_name($domain_key)
    {
        $domain_key = sanitize_key((string) $domain_key);
        if ($domain_key === 'global_classes') {
            return 'bricks_global_classes_categories';
        }
        if ($domain_key === 'global_variables') {
            return 'bricks_global_variables_categories';
        }
        return '';
    }

    /**
     * @param mixed $value
     * @param array<int, string> $references
     * @param string $key
     * @return void
     */
    private static function walk_for_category_references($value, array &$references, $key = '')
    {
        $key = sanitize_key((string) $key);
        if (is_array($value)) {
            foreach ($value as $child_key => $child_value) {
                if (is_string($child_key) && strpos(strtolower($child_key), 'categor') !== false) {
                    self::collect_category_reference_values($child_value, $references);
                }
                self::walk_for_category_references($child_value, $references, (string) $child_key);
            }
            return;
        }

        if ($key !== '' && strpos($key, 'categor') !== false && is_scalar($value)) {
            $clean = DBVC_Bricks_Portability_Utils::normalize_string($value);
            if ($clean !== '') {
                $references[] = $clean;
            }
        }
    }

    /**
     * @param mixed $value
     * @param array<int, string> $references
     * @return void
     */
    private static function collect_category_reference_values($value, array &$references)
    {
        if (is_array($value)) {
            if (DBVC_Bricks_Portability_Utils::is_assoc($value)) {
                foreach (['id', 'name', 'slug', 'label', 'title', 'value', 'category', 'key'] as $candidate) {
                    if (! array_key_exists($candidate, $value)) {
                        continue;
                    }
                    $clean = DBVC_Bricks_Portability_Utils::normalize_string($value[$candidate]);
                    if ($clean !== '') {
                        $references[] = $clean;
                    }
                }
            } else {
                foreach ($value as $item) {
                    self::collect_category_reference_values($item, $references);
                }
            }
            return;
        }

        if (! is_scalar($value)) {
            return;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return;
        }

        foreach (preg_split('/\s*,\s*/', $string) as $token) {
            $token = DBVC_Bricks_Portability_Utils::normalize_string($token);
            if ($token !== '') {
                $references[] = $token;
            }
        }
    }

    /**
     * @param mixed $value
     * @param array<int, string> $values
     * @return void
     */
    private static function walk_for_category_lookup_values($value, array &$values)
    {
        if (is_scalar($value)) {
            $clean = DBVC_Bricks_Portability_Utils::normalize_string($value);
            if ($clean !== '') {
                $values[] = $clean;
            }
            return;
        }

        if (is_array($value)) {
            if (DBVC_Bricks_Portability_Utils::is_assoc($value)) {
                $matched = false;
                foreach (['id', 'name', 'slug', 'label', 'title', 'key'] as $candidate) {
                    if (! array_key_exists($candidate, $value)) {
                        continue;
                    }
                    $clean = DBVC_Bricks_Portability_Utils::normalize_string($value[$candidate]);
                    if ($clean === '') {
                        continue;
                    }
                    $values[] = $clean;
                    $matched = true;
                }

                if ($matched) {
                    return;
                }
            }

            foreach ($value as $child) {
                self::walk_for_category_lookup_values($child, $values);
            }
        }
    }
}
