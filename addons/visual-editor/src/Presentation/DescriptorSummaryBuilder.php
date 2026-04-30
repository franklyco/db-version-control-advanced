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

        return [
            'type' => $entity_type,
            'id' => $entity_id,
            'subtype' => $subtype,
            'typeLabel' => $type_label,
            'title' => $title,
            'frontendLink' => $this->buildFrontendEntityLink($entity_type, $entity_id, $subtype, $type_label),
            'backendLink' => $this->buildBackendEntityLink($entity_type, $entity_id, $subtype, $type_label),
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
        $path = isset($descriptor->path) && is_array($descriptor->path) ? $descriptor->path : [];
        $container_type = isset($path['containerType']) ? sanitize_key((string) $path['containerType']) : (isset($source['container_type']) ? sanitize_key((string) $source['container_type']) : '');
        $parent_field_name = isset($path['rootFieldName']) ? sanitize_key((string) $path['rootFieldName']) : (isset($source['parent_field_name']) ? sanitize_key((string) $source['parent_field_name']) : '');
        $layout_name = isset($path['layoutName']) ? sanitize_key((string) $path['layoutName']) : (isset($source['layout_name']) ? sanitize_key((string) $source['layout_name']) : '');
        $layout_key = isset($path['layoutKey']) ? sanitize_key((string) $path['layoutKey']) : (isset($source['layout_key']) ? sanitize_key((string) $source['layout_key']) : '');
        $expression = isset($source['expression']) ? sanitize_text_field((string) $source['expression']) : '';
        $parts = array_values(array_filter([$type, $field_name]));

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
}
