<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Schema_Presentation_Service
{
    /**
     * @var DBVC_CC_V2_Schema_Presentation_Service|null
     */
    private static $instance = null;

    /**
     * @var array<string, array<string, mixed>>
     */
    private $catalog_cache = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private $target_ref_cache = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private $target_object_cache = [];

    /**
     * @return DBVC_CC_V2_Schema_Presentation_Service
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
     * @param string $target_ref
     * @return array<string, mixed>
     */
    public function resolve_target_ref($domain, $target_ref)
    {
        $domain = sanitize_text_field((string) $domain);
        $target_ref = sanitize_text_field((string) $target_ref);
        if ($target_ref === '') {
            return $this->build_empty_target_presentation();
        }

        $cache_key = $domain . '|' . $target_ref;
        if (isset($this->target_ref_cache[$cache_key])) {
            return $this->target_ref_cache[$cache_key];
        }

        $catalog = $this->load_catalog($domain);
        $parts = explode(':', $target_ref);
        $namespace = isset($parts[0]) ? sanitize_key((string) $parts[0]) : '';
        $presentation = [];

        switch ($namespace) {
            case 'core':
                $presentation = $this->resolve_core_target_presentation($target_ref, $parts);
                break;
            case 'taxonomy':
                $presentation = $this->resolve_taxonomy_target_presentation($catalog, $target_ref, $parts);
                break;
            case 'meta':
                $presentation = $this->resolve_meta_target_presentation($catalog, $target_ref, $parts);
                break;
            case 'acf':
                $presentation = $this->resolve_acf_target_presentation($catalog, $target_ref, $parts);
                break;
            default:
                $presentation = $this->build_target_presentation(
                    [
                        'family' => $namespace !== '' ? $namespace : 'unknown',
                        'label' => $this->humanize_identifier($target_ref),
                        'machineRef' => $target_ref,
                        'contextLabel' => 'Target reference',
                    ]
                );
                break;
        }

        $this->target_ref_cache[$cache_key] = $presentation;
        return $presentation;
    }

    /**
     * @param string $domain
     * @param string $target_object_key
     * @param string $target_family
     * @return array<string, mixed>
     */
    public function resolve_target_object($domain, $target_object_key, $target_family = '')
    {
        $domain = sanitize_text_field((string) $domain);
        $target_object_key = sanitize_key((string) $target_object_key);
        $target_family = sanitize_key((string) $target_family);
        if ($target_object_key === '') {
            return [
                'family' => $target_family,
                'targetObjectKey' => '',
                'label' => '',
                'machineKey' => '',
                'contextLabel' => '',
            ];
        }

        $cache_key = $domain . '|' . $target_family . '|' . $target_object_key;
        if (isset($this->target_object_cache[$cache_key])) {
            return $this->target_object_cache[$cache_key];
        }

        $catalog = $this->load_catalog($domain);
        $label = $this->resolve_object_label($catalog, $target_object_key, $target_family);
        $context_label = $target_family === 'taxonomy'
            ? 'Taxonomy target'
            : 'Target object';

        $presentation = [
            'family' => $target_family,
            'targetObjectKey' => $target_object_key,
            'label' => $label,
            'machineKey' => $target_object_key,
            'contextLabel' => $context_label,
        ];

        $this->target_object_cache[$cache_key] = $presentation;
        return $presentation;
    }

    /**
     * @param string $domain
     * @return array<string, mixed>
     */
    private function load_catalog($domain)
    {
        $domain = sanitize_text_field((string) $domain);
        if ($domain === '') {
            return [];
        }

        if (isset($this->catalog_cache[$domain])) {
            return $this->catalog_cache[$domain];
        }

        $result = DBVC_CC_V2_Target_Field_Catalog_Service::get_instance()->get_catalog($domain, false);
        if (is_wp_error($result)) {
            $this->catalog_cache[$domain] = [];
            return [];
        }

        $catalog = isset($result['catalog']) && is_array($result['catalog']) ? $result['catalog'] : [];
        $this->catalog_cache[$domain] = $catalog;
        return $catalog;
    }

    /**
     * @param string               $target_ref
     * @param array<int, string>   $parts
     * @return array<string, mixed>
     */
    private function resolve_core_target_presentation($target_ref, array $parts)
    {
        $field_key = isset($parts[1]) ? sanitize_key((string) $parts[1]) : '';
        $map = [
            'post_title' => [
                'label' => 'Post Title',
                'contextLabel' => 'Core field',
                'fieldType' => 'string',
                'fieldTypeLabel' => 'Text',
            ],
            'post_excerpt' => [
                'label' => 'Post Excerpt',
                'contextLabel' => 'Core field',
                'fieldType' => 'string',
                'fieldTypeLabel' => 'Text',
            ],
            'post_content' => [
                'label' => 'Post Content',
                'contextLabel' => 'Core field',
                'fieldType' => 'string',
                'fieldTypeLabel' => 'Rich Text',
            ],
            'featured_image' => [
                'label' => 'Featured Image',
                'contextLabel' => 'Core media field',
                'fieldType' => 'image',
                'fieldTypeLabel' => 'Image',
                'acceptedMediaKinds' => ['image'],
            ],
        ];
        $definition = isset($map[$field_key]) ? $map[$field_key] : [];

        return $this->build_target_presentation(
            [
                'family' => 'core',
                'label' => isset($definition['label']) ? $definition['label'] : $this->humanize_identifier($field_key),
                'machineRef' => $target_ref,
                'contextLabel' => isset($definition['contextLabel']) ? $definition['contextLabel'] : 'Core field',
                'fieldType' => isset($definition['fieldType']) ? $definition['fieldType'] : '',
                'fieldTypeLabel' => isset($definition['fieldTypeLabel']) ? $definition['fieldTypeLabel'] : '',
                'fieldKey' => $field_key,
                'acceptedMediaKinds' => isset($definition['acceptedMediaKinds']) ? $definition['acceptedMediaKinds'] : [],
            ]
        );
    }

    /**
     * @param array<string, mixed> $catalog
     * @param string               $target_ref
     * @param array<int, string>   $parts
     * @return array<string, mixed>
     */
    private function resolve_taxonomy_target_presentation(array $catalog, $target_ref, array $parts)
    {
        $taxonomy_key = isset($parts[1]) ? sanitize_key((string) $parts[1]) : '';
        $taxonomy_catalog = isset($catalog['taxonomy_catalog']) && is_array($catalog['taxonomy_catalog']) ? $catalog['taxonomy_catalog'] : [];
        $taxonomy_entry = ($taxonomy_key !== '' && isset($taxonomy_catalog[$taxonomy_key]) && is_array($taxonomy_catalog[$taxonomy_key]))
            ? $taxonomy_catalog[$taxonomy_key]
            : [];
        $taxonomy_label = isset($taxonomy_entry['label']) ? (string) $taxonomy_entry['label'] : $this->humanize_identifier($taxonomy_key);

        return $this->build_target_presentation(
            [
                'family' => 'taxonomy',
                'label' => $taxonomy_label,
                'machineRef' => $target_ref,
                'contextLabel' => 'Taxonomy',
                'taxonomyKey' => $taxonomy_key,
                'taxonomyLabel' => $taxonomy_label,
            ]
        );
    }

    /**
     * @param array<string, mixed> $catalog
     * @param string               $target_ref
     * @param array<int, string>   $parts
     * @return array<string, mixed>
     */
    private function resolve_meta_target_presentation(array $catalog, $target_ref, array $parts)
    {
        $object_type = isset($parts[1]) ? sanitize_key((string) $parts[1]) : '';
        $subtype = isset($parts[2]) ? sanitize_key((string) $parts[2]) : '';
        $meta_key = isset($parts[3]) ? sanitize_key((string) $parts[3]) : '';
        $meta_catalog = isset($catalog['meta_catalog']) && is_array($catalog['meta_catalog']) ? $catalog['meta_catalog'] : [];
        $meta_entry = ($object_type !== '' && $subtype !== '' && $meta_key !== '' && isset($meta_catalog[$object_type][$subtype][$meta_key]) && is_array($meta_catalog[$object_type][$subtype][$meta_key]))
            ? $meta_catalog[$object_type][$subtype][$meta_key]
            : [];
        $object_key = $object_type === 'post' && $subtype !== '' && $subtype !== 'default'
            ? $subtype
            : $object_type;
        $object_label = $this->resolve_object_label($catalog, $object_key, 'post_type');
        $media_entry = $this->resolve_media_field_entry($catalog, $target_ref);
        $context_label = $media_entry !== []
            ? trim($object_label . ' media field')
            : trim($object_label . ' meta field');

        return $this->build_target_presentation(
            [
                'family' => 'meta',
                'label' => $this->humanize_identifier($meta_key),
                'machineRef' => $target_ref,
                'contextLabel' => $context_label !== '' ? $context_label : 'Meta field',
                'fieldType' => isset($meta_entry['type']) ? (string) $meta_entry['type'] : '',
                'fieldTypeLabel' => $this->resolve_field_type_label(isset($meta_entry['type']) ? (string) $meta_entry['type'] : ''),
                'objectType' => $object_type,
                'objectKey' => $object_key,
                'objectLabel' => $object_label,
                'subtype' => $subtype,
                'fieldKey' => $meta_key,
                'description' => isset($meta_entry['description']) ? (string) $meta_entry['description'] : '',
                'acceptedMediaKinds' => isset($media_entry['accepted_media_kinds']) && is_array($media_entry['accepted_media_kinds'])
                    ? array_values($media_entry['accepted_media_kinds'])
                    : [],
            ]
        );
    }

    /**
     * @param array<string, mixed> $catalog
     * @param string               $target_ref
     * @param array<int, string>   $parts
     * @return array<string, mixed>
     */
    private function resolve_acf_target_presentation(array $catalog, $target_ref, array $parts)
    {
        $group_key = isset($parts[1]) ? sanitize_key((string) $parts[1]) : '';
        $field_key = isset($parts[2]) ? sanitize_key((string) $parts[2]) : '';
        $acf_catalog = isset($catalog['acf_catalog']) && is_array($catalog['acf_catalog']) ? $catalog['acf_catalog'] : [];
        $groups = isset($acf_catalog['groups']) && is_array($acf_catalog['groups']) ? $acf_catalog['groups'] : [];
        $group_entry = ($group_key !== '' && isset($groups[$group_key]) && is_array($groups[$group_key])) ? $groups[$group_key] : [];
        $fields = isset($group_entry['fields']) && is_array($group_entry['fields']) ? $group_entry['fields'] : [];
        $field_entry = ($field_key !== '' && isset($fields[$field_key]) && is_array($fields[$field_key])) ? $fields[$field_key] : [];
        $field_name = isset($field_entry['name']) ? sanitize_key((string) $field_entry['name']) : '';
        $field_label = isset($field_entry['label']) ? (string) $field_entry['label'] : '';
        if ($field_label === '') {
            $field_label = $this->humanize_identifier($field_name !== '' ? $field_name : $field_key);
        }

        $group_label = isset($group_entry['title']) ? (string) $group_entry['title'] : $this->humanize_identifier($group_key);
        $object_label = $this->resolve_acf_group_object_label($catalog, $group_entry);
        $media_entry = $this->resolve_media_field_entry($catalog, $target_ref);
        $context_parts = [];
        if ($object_label !== '') {
            $context_parts[] = $object_label;
        }
        if ($group_label !== '') {
            $context_parts[] = $group_label;
        }
        $context_parts[] = $media_entry !== [] ? 'ACF media field' : 'ACF field';

        return $this->build_target_presentation(
            [
                'family' => 'acf',
                'label' => $field_label,
                'machineRef' => $target_ref,
                'contextLabel' => implode(' · ', array_values(array_filter($context_parts))),
                'fieldType' => isset($field_entry['type']) ? (string) $field_entry['type'] : '',
                'fieldTypeLabel' => $this->resolve_field_type_label(isset($field_entry['type']) ? (string) $field_entry['type'] : ''),
                'objectKey' => $this->resolve_acf_group_object_key($group_entry),
                'objectLabel' => $object_label,
                'groupKey' => $group_key,
                'groupLabel' => $group_label,
                'fieldKey' => $field_key,
                'fieldName' => $field_name,
                'acceptedMediaKinds' => isset($media_entry['accepted_media_kinds']) && is_array($media_entry['accepted_media_kinds'])
                    ? array_values($media_entry['accepted_media_kinds'])
                    : [],
            ]
        );
    }

    /**
     * @param array<string, mixed> $catalog
     * @param string               $target_ref
     * @return array<string, mixed>
     */
    private function resolve_media_field_entry(array $catalog, $target_ref)
    {
        $media_catalog = isset($catalog['media_field_catalog']) && is_array($catalog['media_field_catalog']) ? $catalog['media_field_catalog'] : [];
        return isset($media_catalog[$target_ref]) && is_array($media_catalog[$target_ref]) ? $media_catalog[$target_ref] : [];
    }

    /**
     * @param array<string, mixed> $catalog
     * @param string               $object_key
     * @param string               $target_family
     * @return string
     */
    private function resolve_object_label(array $catalog, $object_key, $target_family = '')
    {
        $object_key = sanitize_key((string) $object_key);
        $target_family = sanitize_key((string) $target_family);
        if ($object_key === '') {
            return '';
        }

        if ($target_family === 'taxonomy') {
            $taxonomy_catalog = isset($catalog['taxonomy_catalog']) && is_array($catalog['taxonomy_catalog']) ? $catalog['taxonomy_catalog'] : [];
            if (isset($taxonomy_catalog[$object_key]) && is_array($taxonomy_catalog[$object_key]) && ! empty($taxonomy_catalog[$object_key]['label'])) {
                return (string) $taxonomy_catalog[$object_key]['label'];
            }
        }

        $object_catalog = isset($catalog['object_catalog']) && is_array($catalog['object_catalog']) ? $catalog['object_catalog'] : [];
        if (isset($object_catalog[$object_key]) && is_array($object_catalog[$object_key]) && ! empty($object_catalog[$object_key]['label'])) {
            return (string) $object_catalog[$object_key]['label'];
        }

        return $this->humanize_identifier($object_key);
    }

    /**
     * @param array<string, mixed> $catalog
     * @param array<string, mixed> $group_entry
     * @return string
     */
    private function resolve_acf_group_object_label(array $catalog, array $group_entry)
    {
        $object_key = $this->resolve_acf_group_object_key($group_entry);
        if ($object_key === '') {
            return '';
        }

        return $this->resolve_object_label($catalog, $object_key, 'post_type');
    }

    /**
     * @param array<string, mixed> $group_entry
     * @return string
     */
    private function resolve_acf_group_object_key(array $group_entry)
    {
        $location = isset($group_entry['location']) && is_array($group_entry['location']) ? $group_entry['location'] : [];
        foreach ($location as $rule_group) {
            if (! is_array($rule_group)) {
                continue;
            }

            foreach ($rule_group as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                $parameter = isset($rule['param']) ? sanitize_key((string) $rule['param']) : '';
                $operator = isset($rule['operator']) ? sanitize_text_field((string) $rule['operator']) : '';
                $value = isset($rule['value']) ? sanitize_key((string) $rule['value']) : '';
                if ($parameter === 'post_type' && $operator === '==' && $value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function build_target_presentation(array $args)
    {
        return [
            'family' => isset($args['family']) ? sanitize_key((string) $args['family']) : '',
            'label' => isset($args['label']) ? (string) $args['label'] : '',
            'machineRef' => isset($args['machineRef']) ? (string) $args['machineRef'] : '',
            'contextLabel' => isset($args['contextLabel']) ? (string) $args['contextLabel'] : '',
            'fieldType' => isset($args['fieldType']) ? sanitize_key((string) $args['fieldType']) : '',
            'fieldTypeLabel' => isset($args['fieldTypeLabel']) ? (string) $args['fieldTypeLabel'] : '',
            'objectType' => isset($args['objectType']) ? sanitize_key((string) $args['objectType']) : '',
            'objectKey' => isset($args['objectKey']) ? sanitize_key((string) $args['objectKey']) : '',
            'objectLabel' => isset($args['objectLabel']) ? (string) $args['objectLabel'] : '',
            'subtype' => isset($args['subtype']) ? sanitize_key((string) $args['subtype']) : '',
            'groupKey' => isset($args['groupKey']) ? sanitize_key((string) $args['groupKey']) : '',
            'groupLabel' => isset($args['groupLabel']) ? (string) $args['groupLabel'] : '',
            'fieldKey' => isset($args['fieldKey']) ? sanitize_key((string) $args['fieldKey']) : '',
            'fieldName' => isset($args['fieldName']) ? sanitize_key((string) $args['fieldName']) : '',
            'taxonomyKey' => isset($args['taxonomyKey']) ? sanitize_key((string) $args['taxonomyKey']) : '',
            'taxonomyLabel' => isset($args['taxonomyLabel']) ? (string) $args['taxonomyLabel'] : '',
            'description' => isset($args['description']) ? (string) $args['description'] : '',
            'acceptedMediaKinds' => isset($args['acceptedMediaKinds']) && is_array($args['acceptedMediaKinds'])
                ? array_values(array_map('sanitize_key', $args['acceptedMediaKinds']))
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function build_empty_target_presentation()
    {
        return $this->build_target_presentation(
            [
                'family' => '',
                'label' => '',
                'machineRef' => '',
                'contextLabel' => '',
            ]
        );
    }

    /**
     * @param string $field_type
     * @return string
     */
    private function resolve_field_type_label($field_type)
    {
        $field_type = sanitize_key((string) $field_type);
        if ($field_type === '') {
            return '';
        }

        $labels = [
            'string' => 'Text',
            'textarea' => 'Textarea',
            'wysiwyg' => 'WYSIWYG',
            'boolean' => 'Boolean',
            'integer' => 'Integer',
            'number' => 'Number',
            'array' => 'Array',
            'object' => 'Object',
            'image' => 'Image',
            'file' => 'File',
            'gallery' => 'Gallery',
            'url' => 'URL',
            'email' => 'Email',
            'select' => 'Select',
            'group' => 'Group',
            'repeater' => 'Repeater',
            'taxonomy' => 'Taxonomy',
            'relationship' => 'Relationship',
            'post_object' => 'Post Object',
        ];

        if (isset($labels[$field_type])) {
            return $labels[$field_type];
        }

        return $this->humanize_identifier($field_type);
    }

    /**
     * @param string $value
     * @return string
     */
    private function humanize_identifier($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $humanized = preg_replace('/[_-]+/', ' ', $value);
        $humanized = is_string($humanized) ? trim($humanized) : $value;
        $humanized = ucwords($humanized);

        $replacements = [
            'Faq' => 'FAQ',
            'Seo' => 'SEO',
            'Cta' => 'CTA',
            'Url' => 'URL',
            'Id' => 'ID',
            'Api' => 'API',
            'Ui' => 'UI',
            'Acf' => 'ACF',
        ];

        return strtr($humanized, $replacements);
    }
}
