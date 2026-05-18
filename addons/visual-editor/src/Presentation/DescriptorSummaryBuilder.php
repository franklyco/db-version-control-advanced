<?php

namespace Dbvc\VisualEditor\Presentation;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class DescriptorSummaryBuilder
{
    /**
     * @param EditableDescriptor $descriptor
     * @return array<string, mixed>
     */
    public function buildEntitySummary(EditableDescriptor $descriptor)
    {
        $entity = isset($descriptor->entity) && is_array($descriptor->entity) ? $descriptor->entity : [];
        $entity_type = isset($entity['type']) ? sanitize_key((string) $entity['type']) : '';
        $entity_id = isset($entity['id']) ? absint($entity['id']) : 0;
        $subtype = isset($entity['subtype']) ? sanitize_key((string) $entity['subtype']) : '';
        $type_label = $this->resolveEntityTypeLabel($entity_type, $subtype);
        $title = $this->resolveEntityTitle($entity_type, $entity_id, $subtype);
        $backend_link = $this->buildBackendEntityLink($entity_type, $entity_id, $subtype, $type_label);
        if ($backend_link === null && $entity_type === 'option') {
            $backend_link = $this->buildOptionsPageBackendLink($descriptor, $type_label);
        }

        return [
            'type' => $entity_type,
            'id' => $entity_id,
            'subtype' => $subtype,
            'typeLabel' => $type_label,
            'title' => $title,
            'frontendLink' => $this->buildFrontendEntityLink($entity_type, $entity_id, $subtype, $type_label),
            'backendLink' => $backend_link,
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<string, string>
     */
    public function buildSourceSummary(EditableDescriptor $descriptor)
    {
        $source = isset($descriptor->source) && is_array($descriptor->source) ? $descriptor->source : [];
        $entity = isset($descriptor->entity) && is_array($descriptor->entity) ? $descriptor->entity : [];
        $label = isset($descriptor->ui['label']) ? sanitize_text_field((string) $descriptor->ui['label']) : __('Field', 'dbvc');
        $type = isset($source['type']) ? sanitize_key((string) $source['type']) : '';
        $field_name = isset($source['field_name']) ? sanitize_key((string) $source['field_name']) : '';
        $source_context = isset($source['source_context']) ? sanitize_key((string) $source['source_context']) : '';
        $field_group_title = isset($source['field_group_title']) ? sanitize_text_field((string) $source['field_group_title']) : '';
        $field_group_option_pages = isset($source['field_group_option_pages']) && is_array($source['field_group_option_pages'])
            ? array_values(array_filter(array_map('sanitize_key', $source['field_group_option_pages'])))
            : [];
        $path = isset($descriptor->path) && is_array($descriptor->path) ? $descriptor->path : [];
        $container_type = isset($path['containerType']) ? sanitize_key((string) $path['containerType']) : (isset($source['container_type']) ? sanitize_key((string) $source['container_type']) : '');
        $parent_field_name = isset($path['rootFieldName']) ? sanitize_key((string) $path['rootFieldName']) : (isset($source['parent_field_name']) ? sanitize_key((string) $source['parent_field_name']) : '');
        $layout_name = isset($path['layoutName']) ? sanitize_key((string) $path['layoutName']) : (isset($source['layout_name']) ? sanitize_key((string) $source['layout_name']) : '');
        $layout_key = isset($path['layoutKey']) ? sanitize_key((string) $path['layoutKey']) : (isset($source['layout_key']) ? sanitize_key((string) $source['layout_key']) : '');
        $native_query_kind = isset($path['nativeQueryKind']) ? sanitize_key((string) $path['nativeQueryKind']) : (isset($source['native_query_kind']) ? sanitize_key((string) $source['native_query_kind']) : '');
        $native_query_selector = isset($path['nativeQuerySelector']) ? sanitize_key((string) $path['nativeQuerySelector']) : (isset($source['native_query_selector']) ? sanitize_key((string) $source['native_query_selector']) : '');
        $parent_native_query_kind = isset($path['parentNativeQueryKind']) ? sanitize_key((string) $path['parentNativeQueryKind']) : (isset($source['parent_native_query_kind']) ? sanitize_key((string) $source['parent_native_query_kind']) : '');
        $parent_native_query_selector = isset($path['parentNativeQuerySelector']) ? sanitize_key((string) $path['parentNativeQuerySelector']) : (isset($source['parent_native_query_selector']) ? sanitize_key((string) $source['parent_native_query_selector']) : '');
        $native_query_ancestry = $this->normalizeNativeQueryAncestry(
            isset($path['nativeQueryAncestry']) && is_array($path['nativeQueryAncestry'])
                ? $path['nativeQueryAncestry']
                : (isset($source['native_query_ancestry']) && is_array($source['native_query_ancestry']) ? $source['native_query_ancestry'] : [])
        );
        $group_path = isset($path['groupPath']) && is_array($path['groupPath'])
            ? array_values(
                array_filter(
                    array_map(
                        static function ($value) {
                            return sanitize_key((string) $value);
                        },
                        $path['groupPath']
                    )
                )
            )
            : (isset($source['group_path']) && is_array($source['group_path']) ? array_values(array_filter(array_map('sanitize_key', $source['group_path']))) : []);
        $group_key_path = isset($path['groupKeyPath']) && is_array($path['groupKeyPath'])
            ? array_values(
                array_filter(
                    array_map(
                        static function ($value) {
                            return sanitize_key((string) $value);
                        },
                        $path['groupKeyPath']
                    )
                )
            )
            : (isset($source['group_key_path']) && is_array($source['group_key_path']) ? array_values(array_filter(array_map('sanitize_key', $source['group_key_path']))) : []);
        $nested_repeater_path = isset($path['nestedRepeaterPath']) && is_array($path['nestedRepeaterPath'])
            ? array_values(
                array_filter(
                    array_map(
                        static function ($segment) {
                            if (! is_array($segment)) {
                                return null;
                            }

                            $field_name = isset($segment['fieldName']) ? sanitize_key((string) $segment['fieldName']) : (isset($segment['field_name']) ? sanitize_key((string) $segment['field_name']) : '');
                            $field_key = isset($segment['fieldKey']) ? sanitize_key((string) $segment['fieldKey']) : (isset($segment['field_key']) ? sanitize_key((string) $segment['field_key']) : '');
                            $row_index = isset($segment['rowIndex']) && $segment['rowIndex'] !== null && is_numeric($segment['rowIndex'])
                                ? absint($segment['rowIndex'])
                                : (isset($segment['row_index']) && $segment['row_index'] !== null && is_numeric($segment['row_index']) ? absint($segment['row_index']) : null);

                            if ($field_name === '' && $field_key === '') {
                                return null;
                            }

                            return [
                                'fieldName' => $field_name,
                                'fieldKey' => $field_key,
                                'rowIndex' => $row_index,
                            ];
                        },
                        $path['nestedRepeaterPath']
                    )
                )
            )
            : (isset($source['nested_repeater_path']) && is_array($source['nested_repeater_path']) ? array_values(array_filter(array_map(static function ($segment) {
                if (! is_array($segment)) {
                    return null;
                }

                $field_name = isset($segment['field_name']) ? sanitize_key((string) $segment['field_name']) : '';
                $field_key = isset($segment['field_key']) ? sanitize_key((string) $segment['field_key']) : '';
                $row_index = isset($segment['row_index']) && $segment['row_index'] !== null && is_numeric($segment['row_index']) ? absint($segment['row_index']) : null;

                if ($field_name === '' && $field_key === '') {
                    return null;
                }

                return [
                    'fieldName' => $field_name,
                    'fieldKey' => $field_key,
                    'rowIndex' => $row_index,
                ];
            }, $source['nested_repeater_path']))) : []);
        $expression = isset($source['expression']) ? sanitize_text_field((string) $source['expression']) : '';
        $parts = array_values(array_filter([$type, $field_name]));

        if ($source_context !== '') {
            $parts[] = 'context:' . $source_context;
        }

        if ($field_group_title !== '') {
            $parts[] = 'field-group:' . $field_group_title;
        }

        if (! empty($field_group_option_pages)) {
            $parts[] = 'options-page:' . implode(',', $field_group_option_pages);
        }

        if ($parent_field_name !== '') {
            $parts[] = ($container_type !== '' ? $container_type : 'repeater') . ':' . $parent_field_name;
        }

        $row_index = isset($path['rowIndex']) ? $path['rowIndex'] : (isset($source['row_index']) ? $source['row_index'] : null);
        if ($row_index !== null && $row_index !== '') {
            $parts[] = 'row:' . (absint($row_index) + 1);
        }

        if ($layout_name !== '' || $layout_key !== '') {
            $parts[] = 'layout:' . ($layout_name !== '' ? $layout_name : $layout_key);
        }

        foreach ($nested_repeater_path as $nested_segment) {
            $nested_name = isset($nested_segment['fieldName']) ? sanitize_key((string) $nested_segment['fieldName']) : '';
            $nested_key = isset($nested_segment['fieldKey']) ? sanitize_key((string) $nested_segment['fieldKey']) : '';
            $nested_row_index = array_key_exists('rowIndex', $nested_segment) ? $nested_segment['rowIndex'] : null;

            if ($nested_name !== '' || $nested_key !== '') {
                $parts[] = 'repeater:' . ($nested_name !== '' ? $nested_name : $nested_key);
            }

            if ($nested_row_index !== null) {
                $parts[] = 'row:' . ($nested_row_index + 1);
            }
        }

        if (! empty($native_query_ancestry)) {
            $parts[] = 'native-chain:' . implode('>', array_map(
                static function ($item) {
                    $kind = isset($item['kind']) ? sanitize_key((string) $item['kind']) : '';
                    $selector = isset($item['selector']) ? sanitize_key((string) $item['selector']) : '';
                    $loop_index = isset($item['loopIndex']) ? sanitize_text_field((string) $item['loopIndex']) : '';

                    $label = $kind !== '' ? $kind : 'query';
                    if ($selector !== '') {
                        $label .= ':' . $selector;
                    }
                    if ($loop_index !== '' && is_numeric($loop_index)) {
                        $label .= '@' . (absint($loop_index) + 1);
                    }

                    return $label;
                },
                $native_query_ancestry
            ));
        } elseif ($parent_native_query_kind !== '' || $parent_native_query_selector !== '') {
            $parts[] = 'parent-native:' . ($parent_native_query_kind !== '' ? $parent_native_query_kind : 'query') . ($parent_native_query_selector !== '' ? ':' . $parent_native_query_selector : '');
        }

        if ($native_query_kind !== '' || $native_query_selector !== '') {
            $parts[] = 'native:' . ($native_query_kind !== '' ? $native_query_kind : 'query') . ($native_query_selector !== '' ? ':' . $native_query_selector : '');
        }

        $group_depth = max(count($group_path), count($group_key_path));
        $group_summary_path = [];

        for ($index = 0; $index < $group_depth; $index++) {
            $group_name = isset($group_path[$index]) ? $group_path[$index] : '';
            $group_key = isset($group_key_path[$index]) ? $group_key_path[$index] : '';

            if ($group_name !== '' || $group_key !== '') {
                $group_summary_path[] = $group_name !== '' ? $group_name : $group_key;
            }
        }

        if (! empty($group_summary_path)) {
            $parts[] = 'group:' . implode('>', $group_summary_path);
        }

        $entity_type = isset($entity['type']) ? sanitize_key((string) $entity['type']) : '';
        $entity_id = isset($entity['id']) ? absint($entity['id']) : 0;
        if ($entity_type !== '' && $entity_id > 0) {
            $parts[] = $entity_type . ':' . $entity_id;
        }

        return [
            'label' => $label,
            'type' => $type,
            'fieldName' => $field_name,
            'parentFieldName' => $parent_field_name,
            'expression' => $expression,
            'summary' => implode(' / ', $parts),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $ancestry
     * @return array<int, array<string, string>>
     */
    private function normalizeNativeQueryAncestry(array $ancestry)
    {
        return array_values(
            array_filter(
                array_map(
                    static function ($item) {
                        if (! is_array($item)) {
                            return null;
                        }

                        $kind = isset($item['kind']) ? sanitize_key((string) $item['kind']) : '';
                        $selector = isset($item['selector']) ? sanitize_key((string) $item['selector']) : '';
                        $object_type = isset($item['objectType']) ? sanitize_key((string) $item['objectType']) : '';
                        $loop_index = isset($item['loopIndex']) ? sanitize_text_field((string) $item['loopIndex']) : '';

                        if ($kind === '' && $selector === '' && $object_type === '') {
                            return null;
                        }

                        return [
                            'kind' => $kind,
                            'selector' => $selector,
                            'objectType' => $object_type,
                            'loopIndex' => $loop_index,
                        ];
                    },
                    $ancestry
                )
            )
        );
    }

    /**
     * @param EditableDescriptor    $descriptor
     * @param bool                  $can_edit
     * @param array<string, mixed>  $entity_summary
     * @param array<string, string> $source_summary
     * @return array<string, string>
     */
    public function buildNoticeSummary(EditableDescriptor $descriptor, $can_edit, array $entity_summary, array $source_summary)
    {
        $status = isset($descriptor->status) ? (string) $descriptor->status : 'unsupported';
        $scope = isset($descriptor->scope) ? (string) $descriptor->scope : 'current_entity';
        $entity_title = isset($entity_summary['title']) && (string) $entity_summary['title'] !== ''
            ? sanitize_text_field((string) $entity_summary['title'])
            : __('Item', 'dbvc');
        $type_label = isset($entity_summary['typeLabel']) ? sanitize_text_field((string) $entity_summary['typeLabel']) : __('Item', 'dbvc');
        $source_label = isset($source_summary['label']) && (string) $source_summary['label'] !== ''
            ? sanitize_text_field((string) $source_summary['label'])
            : (isset($source_summary['fieldName']) ? sanitize_text_field((string) $source_summary['fieldName']) : __('Field', 'dbvc'));
        $scope_label = $this->resolveScopeSummaryLabel($descriptor, $entity_summary);

        if ($status === 'readonly') {
            return [
                'title' => sprintf(
                    /* translators: %s: entity title */
                    __('Inspecting %s', 'dbvc'),
                    $entity_title
                ),
                'detail' => sprintf(
                    /* translators: 1: entity type label, 2: source label, 3: scope label */
                    __('%1$s / Source: %2$s / %3$s', 'dbvc'),
                    $type_label,
                    $source_label,
                    $scope_label
                ),
            ];
        }

        if (! $can_edit) {
            return [
                'title' => sprintf(
                    /* translators: %s: entity title */
                    __('Editing unavailable for %s', 'dbvc'),
                    $entity_title
                ),
                'detail' => sprintf(
                    /* translators: 1: entity type label, 2: source label, 3: scope label */
                    __('%1$s / Source: %2$s / %3$s', 'dbvc'),
                    $type_label,
                    $source_label,
                    $scope_label
                ),
            ];
        }

        if ($scope !== 'current_entity') {
            return [
                'title' => sprintf(
                    /* translators: %s: entity title */
                    __('Editing %s', 'dbvc'),
                    $entity_title
                ),
                'detail' => sprintf(
                    /* translators: 1: entity type label, 2: source label, 3: scope label */
                    __('%1$s / Source: %2$s / %3$s', 'dbvc'),
                    $type_label,
                    $source_label,
                    $scope_label
                ),
            ];
        }

        return [];
    }

    /**
     * @param EditableDescriptor    $descriptor
     * @param array<string, mixed>  $entity_summary
     * @param array<string, string> $source_summary
     * @return array<string, string>
     */
    public function buildSaveSummary(EditableDescriptor $descriptor, array $entity_summary, array $source_summary)
    {
        $entity_title = isset($entity_summary['title']) && (string) $entity_summary['title'] !== ''
            ? sanitize_text_field((string) $entity_summary['title'])
            : __('Item', 'dbvc');
        $type_label = isset($entity_summary['typeLabel']) ? sanitize_text_field((string) $entity_summary['typeLabel']) : __('Item', 'dbvc');
        $source_label = isset($source_summary['label']) && (string) $source_summary['label'] !== ''
            ? sanitize_text_field((string) $source_summary['label'])
            : (isset($source_summary['fieldName']) ? sanitize_text_field((string) $source_summary['fieldName']) : __('Field', 'dbvc'));
        $scope_label = $this->resolveScopeSummaryLabel($descriptor, $entity_summary);

        return [
            'title' => sprintf(
                /* translators: %s: entity title */
                __('Saved %s', 'dbvc'),
                $entity_title
            ),
            'detail' => sprintf(
                /* translators: 1: entity type label, 2: source label, 3: scope label */
                __('%1$s / Source: %2$s / %3$s', 'dbvc'),
                $type_label,
                $source_label,
                $scope_label
            ),
        ];
    }

    /**
     * @param EditableDescriptor   $descriptor
     * @param array<string, mixed> $entity_summary
     * @return string
     */
    private function resolveScopeSummaryLabel(EditableDescriptor $descriptor, array $entity_summary)
    {
        $scope = isset($descriptor->scope) ? (string) $descriptor->scope : 'current_entity';
        $entity_type = isset($entity_summary['type']) ? sanitize_key((string) $entity_summary['type']) : '';

        if ($scope === 'related_entity') {
            if ($entity_type === 'term') {
                return __('related term target', 'dbvc');
            }

            if ($entity_type === 'user') {
                return __('related user target', 'dbvc');
            }

            if ($entity_type === 'option') {
                return __('related options target', 'dbvc');
            }

            return __('related post target', 'dbvc');
        }

        if ($scope === 'shared_entity') {
            if ($entity_type === 'term') {
                return __('shared term target', 'dbvc');
            }

            if ($entity_type === 'user') {
                return __('shared user target', 'dbvc');
            }

            if ($entity_type === 'option') {
                return __('shared Site Settings target', 'dbvc');
            }

            if ($entity_type === 'post') {
                return __('shared post target', 'dbvc');
            }

            return __('shared target', 'dbvc');
        }

        return __('current target', 'dbvc');
    }

    /**
     * @param string $entity_type
     * @param int    $entity_id
     * @param string $subtype
     * @return string
     */
    private function resolveEntityTitle($entity_type, $entity_id, $subtype)
    {
        if ($entity_type === 'post' && $entity_id > 0) {
            $title = get_the_title($entity_id);

            if (is_string($title) && trim($title) !== '') {
                return $title;
            }

            return sprintf(
                /* translators: 1: post type label, 2: post ID */
                __('%1$s #%2$d', 'dbvc'),
                $this->resolveEntityTypeLabel($entity_type, $subtype),
                $entity_id
            );
        }

        if ($entity_type === 'term' && $entity_id > 0) {
            $term = $subtype !== '' ? get_term($entity_id, $subtype) : get_term($entity_id);

            if ($term && ! is_wp_error($term) && isset($term->name)) {
                return sanitize_text_field((string) $term->name);
            }
        }

        if ($entity_type === 'user' && $entity_id > 0) {
            $user = get_userdata($entity_id);

            if ($user && isset($user->display_name) && (string) $user->display_name !== '') {
                return sanitize_text_field((string) $user->display_name);
            }
        }

        if ($entity_type === 'option') {
            return __('Site Settings', 'dbvc');
        }

        return $this->resolveEntityTypeLabel($entity_type, $subtype);
    }

    /**
     * @param string $entity_type
     * @param string $subtype
     * @return string
     */
    private function resolveEntityTypeLabel($entity_type, $subtype = '')
    {
        if ($entity_type === 'post') {
            $object = $subtype !== '' ? get_post_type_object($subtype) : null;

            if ($object && ! empty($object->labels->singular_name)) {
                return sanitize_text_field((string) $object->labels->singular_name);
            }

            return __('Post', 'dbvc');
        }

        if ($entity_type === 'term') {
            $taxonomy = $subtype !== '' ? get_taxonomy($subtype) : null;

            if ($taxonomy && ! empty($taxonomy->labels->singular_name)) {
                return sanitize_text_field((string) $taxonomy->labels->singular_name);
            }

            return __('Term', 'dbvc');
        }

        if ($entity_type === 'user') {
            return __('User', 'dbvc');
        }

        if ($entity_type === 'option') {
            return __('Site Settings', 'dbvc');
        }

        return __('Item', 'dbvc');
    }

    /**
     * @param string $entity_type
     * @param int    $entity_id
     * @param string $subtype
     * @param string $type_label
     * @return array<string, string>|null
     */
    private function buildFrontendEntityLink($entity_type, $entity_id, $subtype, $type_label)
    {
        $url = '';

        if ($entity_type === 'post' && $entity_id > 0) {
            $url = get_permalink($entity_id);
        } elseif ($entity_type === 'term' && $entity_id > 0) {
            $term_link = $subtype !== '' ? get_term_link($entity_id, $subtype) : get_term_link($entity_id);
            $url = ! is_wp_error($term_link) ? (string) $term_link : '';
        } elseif ($entity_type === 'user' && $entity_id > 0) {
            $url = get_author_posts_url($entity_id);
        }

        if (! is_string($url) || $url === '') {
            return null;
        }

        return [
            'label' => sprintf(
                /* translators: %s: entity type label */
                __('Frontend - %s Content Editor', 'dbvc'),
                $type_label
            ),
            'url' => esc_url_raw($url),
        ];
    }

    /**
     * @param string $entity_type
     * @param int    $entity_id
     * @param string $subtype
     * @param string $type_label
     * @return array<string, string>|null
     */
    private function buildBackendEntityLink($entity_type, $entity_id, $subtype, $type_label)
    {
        $url = '';

        if ($entity_type === 'post' && $entity_id > 0) {
            $url = get_edit_post_link($entity_id, '');
        } elseif ($entity_type === 'term' && $entity_id > 0 && $subtype !== '') {
            $url = get_edit_term_link($entity_id, $subtype, '');
        } elseif ($entity_type === 'user' && $entity_id > 0) {
            $url = get_edit_user_link($entity_id);
        }

        if (! is_string($url) || $url === '') {
            return null;
        }

        return [
            'label' => sprintf(
                /* translators: %s: entity type label */
                __('Backend - %s Full Editor', 'dbvc'),
                $type_label
            ),
            'url' => esc_url_raw($url),
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param string             $type_label
     * @return array<string, string>|null
     */
    private function buildOptionsPageBackendLink(EditableDescriptor $descriptor, $type_label)
    {
        $source = isset($descriptor->source) && is_array($descriptor->source) ? $descriptor->source : [];
        $entity = isset($descriptor->entity) && is_array($descriptor->entity) ? $descriptor->entity : [];
        $slug = '';

        if (! empty($source['field_group_option_pages']) && is_array($source['field_group_option_pages'])) {
            $first = reset($source['field_group_option_pages']);
            $slug = is_scalar($first) ? sanitize_key((string) $first) : '';
        }

        if ($slug === '' && ! empty($entity['option_page_slug'])) {
            $slug = sanitize_key((string) $entity['option_page_slug']);
        }

        if ($slug === '') {
            return null;
        }

        return [
            'label' => sprintf(
                /* translators: %s: entity type label */
                __('Backend - %s Full Editor', 'dbvc'),
                $type_label
            ),
            'url' => esc_url_raw(admin_url('admin.php?page=' . $this->resolveOptionsPageAdminSlug($slug))),
        ];
    }

    /**
     * @param string $slug
     * @return string
     */
    private function resolveOptionsPageAdminSlug($slug)
    {
        $slug = sanitize_key((string) $slug);
        if ($slug === '') {
            return '';
        }

        if (function_exists('acf_get_options_pages')) {
            $pages = acf_get_options_pages();
            if (is_array($pages)) {
                foreach ($pages as $page) {
                    if (! is_array($page)) {
                        continue;
                    }

                    $menu_slug = ! empty($page['menu_slug']) ? sanitize_key((string) $page['menu_slug']) : '';
                    $normalized_menu_slug = preg_replace('/^acf-options-/', '', $menu_slug);
                    $normalized_menu_slug = is_string($normalized_menu_slug) ? sanitize_key($normalized_menu_slug) : '';

                    if ($menu_slug !== '' && ($menu_slug === $slug || $normalized_menu_slug === $slug)) {
                        return $menu_slug;
                    }
                }
            }
        }

        return 'acf-options-' . $slug;
    }
}
