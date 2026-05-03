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
     * @param string               $selector
     * @param array<string, mixed> $owner_entity
     * @return array<string, mixed>
     */
    private function resolveFieldDefinition($selector, array $owner_entity = [])
    {
        $field = [];
        $acf_object_id = $this->resolveAcfObjectId($owner_entity);

        if ($acf_object_id !== '' && function_exists('get_field_object')) {
            $resolved = get_field_object($selector, $acf_object_id, false, false);
            if (is_array($resolved) && ! empty($resolved)) {
                $field = $resolved;
            }
        }

        if (empty($field)) {
            $index = $this->getFieldPathIndex();
            if (isset($index[$selector]) && is_array($index[$selector])) {
                $field = $index[$selector];
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
}
