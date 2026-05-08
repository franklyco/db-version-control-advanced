<?php

namespace Dbvc\VisualEditor\Resolvers;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class AcfReferenceCollectionResolver extends AbstractAcfResolver
{
    /**
     * @return string
     */
    public function name()
    {
        return 'acf_reference_collection';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    public function supports(EditableDescriptor $descriptor)
    {
        $field_type = (string) ($descriptor->source['field_type'] ?? '');
        $context = (string) ($descriptor->render['context'] ?? '');
        $source_type = (string) ($descriptor->source['type'] ?? '');

        return $source_type === 'acf_collection_field'
            && $context === 'query_collection'
            && in_array($field_type, ['relationship', 'post_object'], true);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<int, array<string, mixed>>
     */
    public function getValue(EditableDescriptor $descriptor)
    {
        $ids = $this->getSelectedReferenceIds($descriptor);
        $items = [];

        foreach ($ids as $post_id) {
            $item = $this->buildReferenceItem($post_id);
            if (! empty($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return string
     */
    public function getDisplayValue(EditableDescriptor $descriptor, $value)
    {
        unset($descriptor);

        $items = is_array($value) ? $value : [];
        $count = count($items);

        if ($count <= 0) {
            return '';
        }

        if ($count === 1 && isset($items[0]['title']) && is_scalar($items[0]['title'])) {
            return sanitize_text_field((string) $items[0]['title']);
        }

        return sprintf(
            /* translators: %d: connected item count */
            _n('%d connected item', '%d connected items', $count, 'dbvc'),
            $count
        );
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    public function getDisplayMode(EditableDescriptor $descriptor)
    {
        unset($descriptor);

        return 'text';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<int, array<string, string>>
     */
    public function getDisplayCandidates(EditableDescriptor $descriptor, $value)
    {
        return [
            [
                'key' => 'default',
                'value' => $this->getDisplayValue($descriptor, $value),
                'mode' => 'text',
            ],
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    public function validate(EditableDescriptor $descriptor, $value)
    {
        if (! is_array($value)) {
            return [
                'ok' => false,
                'message' => __('Connected-items editing expects an ordered list of related post IDs.', 'dbvc'),
            ];
        }

        $normalized = $this->normalizeSubmittedIds($value);
        $max = $this->getReferenceMaxSelections($descriptor);
        $min = $this->getReferenceMinSelections($descriptor);

        if ($max > 0 && count($normalized) > $max) {
            return [
                'ok' => false,
                'message' => sprintf(
                    /* translators: %d: maximum selections */
                    __('You can select at most %d connected items for this field.', 'dbvc'),
                    $max
                ),
            ];
        }

        if ($min > 0 && count($normalized) < $min) {
            return [
                'ok' => false,
                'message' => sprintf(
                    /* translators: %d: minimum selections */
                    __('You must keep at least %d connected items for this field.', 'dbvc'),
                    $min
                ),
            ];
        }

        foreach ($normalized as $post_id) {
            if (! $this->postMatchesDescriptor($descriptor, $post_id)) {
                return [
                    'ok' => false,
                    'message' => __('One or more selected connected items are not valid for this field.', 'dbvc'),
                ];
            }
        }

        return [
            'ok' => true,
            'message' => '',
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return mixed
     */
    public function sanitize(EditableDescriptor $descriptor, $value)
    {
        $ids = is_array($value) ? $this->normalizeSubmittedIds($value) : [];
        $field_type = $this->getFieldType($descriptor);
        $is_multiple = ! empty($descriptor->source['reference_multiple']);

        if ($field_type === 'relationship' || $is_multiple) {
            return $ids;
        }

        return isset($ids[0]) ? $ids[0] : '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    public function save(EditableDescriptor $descriptor, $value)
    {
        $result = $this->writeAcfValue($descriptor, $value);
        if (empty($result['ok'])) {
            return $result;
        }

        return [
            'ok' => true,
            'value' => $this->getValue($descriptor),
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param string             $search
     * @param int                $limit
     * @return array<int, array<string, mixed>>
     */
    public function searchItems(EditableDescriptor $descriptor, $search = '', $limit = 20)
    {
        $search = sanitize_text_field((string) $search);
        $limit = max(1, min(50, absint($limit)));
        $post_types = $this->getAllowedPostTypes($descriptor);

        $query_args = [
            'post_type' => ! empty($post_types) ? $post_types : 'any',
            'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
            'posts_per_page' => $limit,
            'orderby' => $search !== '' ? 'title' : 'modified',
            'order' => $search !== '' ? 'ASC' : 'DESC',
            's' => $search,
            'fields' => 'ids',
            'suppress_filters' => false,
        ];

        $post_ids = get_posts($query_args);
        if (! is_array($post_ids) || empty($post_ids)) {
            return [];
        }

        $items = [];

        foreach ($post_ids as $post_id) {
            $post_id = absint($post_id);
            if ($post_id <= 0 || ! $this->postMatchesDescriptor($descriptor, $post_id)) {
                continue;
            }

            $item = $this->buildReferenceItem($post_id);
            if (! empty($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<int, int>
     */
    private function getSelectedReferenceIds(EditableDescriptor $descriptor)
    {
        $raw_value = $this->getRawAcfValue($descriptor);
        $ids = [];

        if (is_array($raw_value)) {
            foreach ($raw_value as $item) {
                $ids[] = $this->extractReferenceId($item);
            }
        } else {
            $ids[] = $this->extractReferenceId($raw_value);
        }

        return array_values(
            array_filter(
                array_map('absint', $ids)
            )
        );
    }

    /**
     * @param mixed $value
     * @return int
     */
    private function extractReferenceId($value)
    {
        if (is_object($value) && isset($value->ID)) {
            return absint($value->ID);
        }

        if (is_array($value) && isset($value['ID'])) {
            return absint($value['ID']);
        }

        return is_numeric($value) ? absint($value) : 0;
    }

    /**
     * @param array<int, mixed> $value
     * @return array<int, int>
     */
    private function normalizeSubmittedIds(array $value)
    {
        $ids = [];

        foreach ($value as $item) {
            $post_id = is_array($item) && isset($item['id'])
                ? absint($item['id'])
                : absint($item);

            if ($post_id > 0 && ! in_array($post_id, $ids, true)) {
                $ids[] = $post_id;
            }
        }

        return $ids;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<int, string>
     */
    private function getAllowedPostTypes(EditableDescriptor $descriptor)
    {
        $post_types = isset($descriptor->source['reference_post_types']) && is_array($descriptor->source['reference_post_types'])
            ? array_values(array_filter(array_map('sanitize_key', $descriptor->source['reference_post_types'])))
            : [];

        if (! empty($post_types)) {
            return $post_types;
        }

        return array_values(
            array_filter(
                get_post_types(
                    [
                        'public' => true,
                    ],
                    'names'
                )
            )
        );
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param int                $post_id
     * @return bool
     */
    private function postMatchesDescriptor(EditableDescriptor $descriptor, $post_id)
    {
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post) {
            return false;
        }

        $allowed_post_types = $this->getAllowedPostTypes($descriptor);
        if (! empty($allowed_post_types) && ! in_array((string) $post->post_type, $allowed_post_types, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param int $post_id
     * @return array<string, mixed>
     */
    private function buildReferenceItem($post_id)
    {
        $post_id = absint($post_id);
        if ($post_id <= 0) {
            return [];
        }

        $post = get_post($post_id);
        if (! $post instanceof \WP_Post) {
            return [];
        }

        $post_type = sanitize_key((string) $post->post_type);
        $post_type_object = get_post_type_object($post_type);
        $type_label = $post_type_object && ! empty($post_type_object->labels->singular_name)
            ? sanitize_text_field((string) $post_type_object->labels->singular_name)
            : __('Post', 'dbvc');
        $title = get_the_title($post_id);

        return [
            'id' => $post_id,
            'title' => is_string($title) && trim($title) !== ''
                ? sanitize_text_field($title)
                : sprintf(
                    /* translators: 1: post type label, 2: post ID */
                    __('%1$s #%2$d', 'dbvc'),
                    $type_label,
                    $post_id
                ),
            'postType' => $post_type,
            'typeLabel' => $type_label,
            'status' => sanitize_key((string) $post->post_status),
            'frontendUrl' => ($url = get_permalink($post_id)) && is_string($url) ? esc_url_raw($url) : '',
            'backendUrl' => ($edit_url = get_edit_post_link($post_id, '')) && is_string($edit_url) ? esc_url_raw($edit_url) : '',
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return int
     */
    private function getReferenceMaxSelections(EditableDescriptor $descriptor)
    {
        if (isset($descriptor->source['reference_max']) && is_numeric($descriptor->source['reference_max'])) {
            return max(0, absint($descriptor->source['reference_max']));
        }

        if (! empty($descriptor->source['reference_multiple'])) {
            return 0;
        }

        return 1;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return int
     */
    private function getReferenceMinSelections(EditableDescriptor $descriptor)
    {
        return isset($descriptor->source['reference_min']) && is_numeric($descriptor->source['reference_min'])
            ? max(0, absint($descriptor->source['reference_min']))
            : 0;
    }
}
