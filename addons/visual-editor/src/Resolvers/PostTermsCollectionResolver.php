<?php

namespace Dbvc\VisualEditor\Resolvers;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class PostTermsCollectionResolver implements ResolverInterface
{
    /**
     * @return string
     */
    public function name()
    {
        return 'post_terms_collection';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    public function supports(EditableDescriptor $descriptor)
    {
        return (string) ($descriptor->source['type'] ?? '') === 'post_terms_collection'
            && (string) ($descriptor->render['context'] ?? '') === 'query_collection'
            && (string) ($descriptor->source['field_type'] ?? '') === 'taxonomy';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<int, array<string, mixed>>
     */
    public function getValue(EditableDescriptor $descriptor)
    {
        $items = [];

        foreach ($this->getSelectedTermIds($descriptor) as $term_id) {
            $item = $this->buildTermItem($descriptor, $term_id);
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
            /* translators: %d: connected term count */
            _n('%d linked term', '%d linked terms', $count, 'dbvc'),
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
                'message' => __('Linked-term editing expects a list of term IDs.', 'dbvc'),
            ];
        }

        $taxonomy = $this->getTaxonomy($descriptor);
        if ($taxonomy === '') {
            return [
                'ok' => false,
                'message' => __('Linked-term editing requires one taxonomy.', 'dbvc'),
            ];
        }

        foreach ($this->normalizeSubmittedIds($value) as $term_id) {
            $term = get_term($term_id, $taxonomy);
            if (! $term instanceof \WP_Term || is_wp_error($term)) {
                return [
                    'ok' => false,
                    'message' => __('One or more selected terms are not valid for this taxonomy.', 'dbvc'),
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
     * @return array<int, int>
     */
    public function sanitize(EditableDescriptor $descriptor, $value)
    {
        unset($descriptor);

        return is_array($value) ? $this->normalizeSubmittedIds($value) : [];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    public function save(EditableDescriptor $descriptor, $value)
    {
        $post_id = $this->getPostId($descriptor);
        $taxonomy = $this->getTaxonomy($descriptor);
        $ids = is_array($value) ? $this->normalizeSubmittedIds($value) : [];

        if ($post_id <= 0 || $taxonomy === '') {
            return [
                'ok' => false,
                'message' => __('Linked-term editing could not resolve the owner post and taxonomy.', 'dbvc'),
            ];
        }

        if (! current_user_can('edit_post', $post_id)) {
            return [
                'ok' => false,
                'message' => __('You do not have permission to edit terms for this post.', 'dbvc'),
            ];
        }

        $taxonomy_object = get_taxonomy($taxonomy);
        $assign_capability = $taxonomy_object && ! empty($taxonomy_object->cap->assign_terms)
            ? (string) $taxonomy_object->cap->assign_terms
            : '';
        if ($assign_capability !== '' && ! current_user_can($assign_capability)) {
            return [
                'ok' => false,
                'message' => __('You do not have permission to assign terms in this taxonomy.', 'dbvc'),
            ];
        }

        $result = wp_set_object_terms($post_id, $ids, $taxonomy, false);
        if (is_wp_error($result)) {
            return [
                'ok' => false,
                'message' => $result->get_error_message(),
            ];
        }

        clean_object_term_cache($post_id, get_post_type($post_id));

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
        $taxonomy = $this->getTaxonomy($descriptor);
        if ($taxonomy === '') {
            return [];
        }

        $terms = get_terms(
            [
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'number' => max(1, min(50, absint($limit))),
                'search' => sanitize_text_field((string) $search),
                'orderby' => (string) $search !== '' ? 'name' : 'count',
                'order' => 'ASC',
            ]
        );

        if (is_wp_error($terms) || ! is_array($terms)) {
            return [];
        }

        $items = [];
        foreach ($terms as $term) {
            if (! $term instanceof \WP_Term) {
                continue;
            }

            $item = $this->buildTermItem($descriptor, (int) $term->term_id, $term);
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
    private function getSelectedTermIds(EditableDescriptor $descriptor)
    {
        $post_id = $this->getPostId($descriptor);
        $taxonomy = $this->getTaxonomy($descriptor);

        if ($post_id <= 0 || $taxonomy === '') {
            return [];
        }

        $terms = wp_get_object_terms(
            $post_id,
            $taxonomy,
            [
                'fields' => 'ids',
                'orderby' => 'term_order',
                'order' => 'ASC',
            ]
        );

        if (is_wp_error($terms) || ! is_array($terms)) {
            return [];
        }

        return array_values(array_filter(array_map('absint', $terms)));
    }

    /**
     * @param array<int, mixed> $value
     * @return array<int, int>
     */
    private function normalizeSubmittedIds(array $value)
    {
        $ids = [];

        foreach ($value as $item) {
            $term_id = is_array($item) && isset($item['id'])
                ? absint($item['id'])
                : absint($item);

            if ($term_id > 0 && ! in_array($term_id, $ids, true)) {
                $ids[] = $term_id;
            }
        }

        return $ids;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return int
     */
    private function getPostId(EditableDescriptor $descriptor)
    {
        return isset($descriptor->entity['id']) ? absint($descriptor->entity['id']) : 0;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    private function getTaxonomy(EditableDescriptor $descriptor)
    {
        $taxonomies = isset($descriptor->source['reference_taxonomies']) && is_array($descriptor->source['reference_taxonomies'])
            ? array_values(array_filter(array_map('sanitize_key', $descriptor->source['reference_taxonomies'])))
            : [];

        if (count($taxonomies) === 1 && taxonomy_exists($taxonomies[0])) {
            return $taxonomies[0];
        }

        $taxonomy = isset($descriptor->source['taxonomy']) ? sanitize_key((string) $descriptor->source['taxonomy']) : '';
        return $taxonomy !== '' && taxonomy_exists($taxonomy) ? $taxonomy : '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param int                $term_id
     * @param \WP_Term|null      $term
     * @return array<string, mixed>
     */
    private function buildTermItem(EditableDescriptor $descriptor, $term_id, $term = null)
    {
        $taxonomy = $this->getTaxonomy($descriptor);
        $term_id = absint($term_id);
        if ($taxonomy === '' || $term_id <= 0) {
            return [];
        }

        if (! $term instanceof \WP_Term) {
            $term = get_term($term_id, $taxonomy);
        }

        if (! $term instanceof \WP_Term || is_wp_error($term)) {
            return [];
        }

        $taxonomy_object = get_taxonomy($taxonomy);
        $type_label = $taxonomy_object && ! empty($taxonomy_object->labels->singular_name)
            ? sanitize_text_field((string) $taxonomy_object->labels->singular_name)
            : __('Term', 'dbvc');
        $frontend_url = get_term_link($term);
        $backend_url = get_edit_term_link($term_id, $taxonomy, '');

        return [
            'id' => $term_id,
            'title' => sanitize_text_field((string) $term->name),
            'postType' => $taxonomy,
            'typeLabel' => $type_label,
            'status' => sanitize_key((string) $term->slug),
            'frontendUrl' => is_string($frontend_url) ? esc_url_raw($frontend_url) : '',
            'backendUrl' => is_string($backend_url) ? esc_url_raw($backend_url) : '',
        ];
    }
}
