<?php

namespace Dbvc\VisualEditor\Bricks;

final class NativeAcfQueryResolver
{
    /**
     * @var array<string, array<string, mixed>>|null
     */
    private static $field_path_index = null;

    /**
     * @param string               $query_object_type
     * @param array<string, mixed> $owner_entity
     * @return array<string, mixed>
     */
    public function resolve($query_object_type, array $owner_entity = [])
    {
        $query_object_type = sanitize_key((string) $query_object_type);
        if ($query_object_type === '' || strpos($query_object_type, 'acf_') !== 0) {
            return [];
        }

        $selector = sanitize_key(substr($query_object_type, 4));
        if ($selector === '') {
            return [];
        }

        $field = $this->resolveFieldDefinition($selector, $owner_entity);
        $field_name = isset($field['name']) ? sanitize_key((string) $field['name']) : '';
        $field_key = isset($field['key']) ? sanitize_key((string) $field['key']) : '';
        $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        $path = isset($field['_dbvc_selector_path']) && is_array($field['_dbvc_selector_path'])
            ? array_values(array_filter(array_map('sanitize_key', $field['_dbvc_selector_path'])))
            : array_values(array_filter(explode('_', $selector)));

        return [
            'active' => true,
            'source' => 'acf_native_query',
            'objectType' => $query_object_type,
            'selector' => $selector,
            'path' => $path,
            'fieldName' => $field_name,
            'fieldKey' => $field_key,
            'fieldType' => $field_type,
            'kind' => $this->mapFieldTypeToLoopKind($field_type),
            'supportsConcreteOwner' => in_array($field_type, ['relationship', 'post_object', 'taxonomy'], true),
            'isRepeaterLike' => in_array($field_type, ['repeater', 'flexible_content'], true),
        ];
    }

    /**
     * @param string               $query_object_type
     * @param array<string, mixed> $owner_entity
     * @return array<string, mixed>
     */
    public function resolveFieldDefinitionForQuery($query_object_type, array $owner_entity = [])
    {
        $query_object_type = sanitize_key((string) $query_object_type);
        if ($query_object_type === '' || strpos($query_object_type, 'acf_') !== 0) {
            return [];
        }

        $selector = sanitize_key(substr($query_object_type, 4));
        if ($selector === '') {
            return [];
        }

        return $this->resolveFieldDefinition($selector, $owner_entity);
    }

    /**
     * @param string               $selector
     * @param array<string, mixed> $owner_entity
     * @return array<string, mixed>
     */
    private function resolveFieldDefinition($selector, array $owner_entity = [])
    {
        $field = [];
        $acf_object_id = $this->resolveAcfObjectId($owner_entity);
        $selector_candidates = array_values(array_unique(array_filter([
            $selector,
            $this->buildUnderscoreAlias($selector),
        ])));

        if ($acf_object_id !== '' && function_exists('get_field_object')) {
            foreach ($selector_candidates as $selector_candidate) {
                $resolved = get_field_object($selector_candidate, $acf_object_id, false, false);
                if (is_array($resolved) && ! empty($resolved)) {
                    $field = $resolved;
                    break;
                }
            }
        }

        if (empty($field) && $acf_object_id !== '' && function_exists('get_field_object')) {
            $field = $this->resolveNestedFieldDefinitionFromOwner($selector, $acf_object_id);
        }

        if (empty($field)) {
            $index = $this->getFieldPathIndex();
            foreach ($selector_candidates as $selector_candidate) {
                if (isset($index[$selector_candidate]) && is_array($index[$selector_candidate])) {
                    $field = $index[$selector_candidate];
                    break;
                }
            }
        }

        if (empty($field)) {
            return [];
        }

        $field['_dbvc_selector_path'] = isset($field['_dbvc_selector_path']) && is_array($field['_dbvc_selector_path'])
            ? $field['_dbvc_selector_path']
            : array_values(array_filter(explode('_', $selector)));

        return $field;
    }

    /**
     * @param string $selector
     * @param string $acf_object_id
     * @return array<string, mixed>
     */
    private function resolveNestedFieldDefinitionFromOwner($selector, $acf_object_id)
    {
        $selector = sanitize_key((string) $selector);
        if ($selector === '') {
            return [];
        }

        $segments = array_values(array_filter(explode('_', ltrim($selector, '_'))));
        if (count($segments) < 2) {
            return [];
        }

        for ($prefix_length = count($segments) - 1; $prefix_length >= 1; $prefix_length--) {
            $root_selector = implode('_', array_slice($segments, 0, $prefix_length));
            $root_candidates = array_values(array_unique(array_filter([
                $root_selector,
                $this->buildUnderscoreAlias($root_selector),
            ])));

            foreach ($root_candidates as $root_candidate) {
                $root_field = get_field_object($root_candidate, $acf_object_id, false, false);
                if (! is_array($root_field) || empty($root_field)) {
                    continue;
                }

                $remaining_segments = array_slice($segments, $prefix_length);
                $resolved_field = $this->resolveNestedFieldSegments($root_field, $remaining_segments);
                if (empty($resolved_field)) {
                    continue;
                }

                $resolved_field['_dbvc_selector_path'] = $segments;

                return $resolved_field;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $owner_entity
     * @return string
     */
    private function resolveAcfObjectId(array $owner_entity)
    {
        if (! empty($owner_entity['acf_object_id'])) {
            $object_id = $owner_entity['acf_object_id'];

            if (is_numeric($object_id)) {
                return (string) absint($object_id);
            }

            return sanitize_text_field((string) $object_id);
        }

        $page_id = absint(get_queried_object_id());

        return $page_id > 0 ? (string) $page_id : '';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getFieldPathIndex()
    {
        if (is_array(self::$field_path_index)) {
            return self::$field_path_index;
        }

        $index = [];

        if (! function_exists('acf_get_field_groups') || ! function_exists('acf_get_fields')) {
            self::$field_path_index = $index;

            return $index;
        }

        $groups = acf_get_field_groups();
        if (! is_array($groups)) {
            self::$field_path_index = $index;

            return $index;
        }

        foreach ($groups as $group) {
            if (! is_array($group) || empty($group['key'])) {
                continue;
            }

            $fields = acf_get_fields($group['key']);
            if (! is_array($fields) || empty($fields)) {
                continue;
            }

            $this->indexFieldTree($fields, [], $index);
        }

        self::$field_path_index = $index;

        return $index;
    }

    /**
     * @param array<int, array<string, mixed>>    $fields
     * @param array<int, string>                  $prefix
     * @param array<string, array<string, mixed>> $index
     * @return void
     */
    private function indexFieldTree(array $fields, array $prefix, array &$index)
    {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = isset($field['name']) ? sanitize_key((string) $field['name']) : '';
            if ($name === '') {
                continue;
            }

            $path = array_merge($prefix, [$name]);
            $selector = implode('_', $path);
            if ($selector !== '' && ! isset($index[$selector])) {
                $field['_dbvc_selector_path'] = $path;
                $index[$selector] = $field;
            }

            $underscore_alias = $this->buildTrimmedUnderscoreAlias($selector);
            if ($underscore_alias !== '' && ! isset($index[$underscore_alias])) {
                $field['_dbvc_selector_path'] = $path;
                $index[$underscore_alias] = $field;
            }

            $type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';

            if (in_array($type, ['group', 'repeater'], true) && ! empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                $this->indexFieldTree($field['sub_fields'], $path, $index);
                continue;
            }

            if ($type === 'flexible_content' && ! empty($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layout) {
                    if (! is_array($layout) || empty($layout['sub_fields']) || ! is_array($layout['sub_fields'])) {
                        continue;
                    }

                    $layout_name = isset($layout['name']) ? sanitize_key((string) $layout['name']) : '';
                    $layout_prefix = $layout_name !== '' ? array_merge($path, [$layout_name]) : $path;
                    $this->indexFieldTree($layout['sub_fields'], $layout_prefix, $index);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $field
     * @param array<int, string>   $segments
     * @return array<string, mixed>
     */
    private function resolveNestedFieldSegments(array $field, array $segments)
    {
        $current = $field;

        foreach ($segments as $segment) {
            $segment = sanitize_key((string) $segment);
            if ($segment === '') {
                return [];
            }

            $current = $this->findNestedChildField($current, $segment);
            if (empty($current)) {
                return [];
            }
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $field
     * @param string               $segment
     * @return array<string, mixed>
     */
    private function findNestedChildField(array $field, $segment)
    {
        $sub_fields = isset($field['sub_fields']) && is_array($field['sub_fields']) ? $field['sub_fields'] : [];
        foreach ($sub_fields as $sub_field) {
            if (! is_array($sub_field)) {
                continue;
            }

            $name = isset($sub_field['name']) ? sanitize_key((string) $sub_field['name']) : '';
            if ($name === $segment) {
                return $sub_field;
            }
        }

        if (
            isset($field['type']) &&
            sanitize_key((string) $field['type']) === 'flexible_content' &&
            ! empty($field['layouts']) &&
            is_array($field['layouts'])
        ) {
            foreach ($field['layouts'] as $layout) {
                if (! is_array($layout)) {
                    continue;
                }

                $layout_name = isset($layout['name']) ? sanitize_key((string) $layout['name']) : '';
                if ($layout_name === $segment) {
                    return [
                        'name' => $layout_name,
                        'type' => 'layout',
                        'sub_fields' => isset($layout['sub_fields']) && is_array($layout['sub_fields']) ? $layout['sub_fields'] : [],
                    ];
                }

                $layout_fields = isset($layout['sub_fields']) && is_array($layout['sub_fields']) ? $layout['sub_fields'] : [];
                foreach ($layout_fields as $layout_field) {
                    if (! is_array($layout_field)) {
                        continue;
                    }

                    $name = isset($layout_field['name']) ? sanitize_key((string) $layout_field['name']) : '';
                    if ($name === $segment) {
                        return $layout_field;
                    }
                }
            }
        }

        return [];
    }

    /**
     * @param string $field_type
     * @return string
     */
    private function mapFieldTypeToLoopKind($field_type)
    {
        switch ($field_type) {
            case 'repeater':
                return 'repeater';
            case 'relationship':
                return 'relationship';
            case 'post_object':
                return 'post_object';
            case 'taxonomy':
                return 'taxonomy';
            case 'flexible_content':
                return 'flexible_content';
            default:
                return $field_type !== '' ? $field_type : 'unknown';
        }
    }

    /**
     * @param string $selector
     * @return string
     */
    private function buildUnderscoreAlias($selector)
    {
        $selector = sanitize_key((string) $selector);
        if ($selector === '' || strpos($selector, '_') === 0) {
            return '';
        }

        return '_' . $selector;
    }

    /**
     * @param string $selector
     * @return string
     */
    private function buildTrimmedUnderscoreAlias($selector)
    {
        $selector = sanitize_key((string) $selector);
        if ($selector === '' || strpos($selector, '_') !== 0) {
            return '';
        }

        return ltrim($selector, '_');
    }
}
