<?php

namespace Dbvc\VisualEditor\Resolvers;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class NativeReadonlyResolver implements ResolverInterface
{
    /**
     * @return string
     */
    public function name()
    {
        return 'native_readonly';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    public function supports(EditableDescriptor $descriptor)
    {
        return ($descriptor->resolver['name'] ?? '') === 'native_readonly';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return mixed
     */
    public function getValue(EditableDescriptor $descriptor)
    {
        $source_type = isset($descriptor->source['type']) ? sanitize_key((string) $descriptor->source['type']) : '';
        $field_name = isset($descriptor->source['field_name']) ? sanitize_key((string) $descriptor->source['field_name']) : '';

        if ($source_type === 'post_field' && $field_name === 'post_url') {
            $post_id = isset($descriptor->entity['id']) ? absint($descriptor->entity['id']) : 0;
            $url = $post_id > 0 ? get_permalink($post_id) : '';

            return is_string($url) ? $url : '';
        }

        if ($source_type === 'term_field') {
            return $this->getTermFieldValue($descriptor, $field_name);
        }

        if ($source_type === 'archive_field' && $field_name === 'archive_title') {
            return $this->getArchiveTitle($descriptor);
        }

        if (($descriptor->render['context'] ?? '') === 'query_collection'
            && in_array($source_type, ['acf_collection_field', 'derived_query_collection'], true)) {
            return $this->getQueryCollectionValue($descriptor);
        }

        return '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return mixed
     */
    public function getDisplayValue(EditableDescriptor $descriptor, $value)
    {
        if (($descriptor->render['context'] ?? '') === 'query_collection' && is_array($value)) {
            $count = count($value);

            if ($count === 1 && isset($value[0]['title']) && is_scalar($value[0]['title'])) {
                return sanitize_text_field((string) $value[0]['title']);
            }

            if ($count > 0) {
                return sprintf(
                    /* translators: %d: queried item count */
                    _n('%d queried item', '%d queried items', $count, 'dbvc'),
                    $count
                );
            }

            return '';
        }

        return is_scalar($value) || $value === null ? (string) $value : '';
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
     * @return array<string, mixed>
     */
    public function validate(EditableDescriptor $descriptor, $value)
    {
        unset($descriptor, $value);

        return [
            'ok' => false,
            'message' => __('This derived native value is inspectable only.', 'dbvc'),
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return mixed
     */
    public function sanitize(EditableDescriptor $descriptor, $value)
    {
        unset($descriptor);

        return $value;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    public function save(EditableDescriptor $descriptor, $value)
    {
        unset($descriptor, $value);

        return [
            'ok' => false,
            'message' => __('This derived native value is inspectable only.', 'dbvc'),
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param string             $field_name
     * @return string
     */
    private function getTermFieldValue(EditableDescriptor $descriptor, $field_name)
    {
        $term_id = isset($descriptor->entity['id']) ? absint($descriptor->entity['id']) : 0;
        $taxonomy = isset($descriptor->entity['taxonomy'])
            ? sanitize_key((string) $descriptor->entity['taxonomy'])
            : (isset($descriptor->entity['subtype']) ? sanitize_key((string) $descriptor->entity['subtype']) : '');

        if ($term_id <= 0 || $taxonomy === '') {
            return '';
        }

        if ($field_name === 'term_id') {
            return (string) $term_id;
        }

        if ($field_name !== 'term_url') {
            return '';
        }

        $term = get_term($term_id, $taxonomy);
        if (! $term instanceof \WP_Term || is_wp_error($term)) {
            return '';
        }

        $url = get_term_link($term);

        return is_string($url) && ! is_wp_error($url) ? $url : '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    private function getArchiveTitle(EditableDescriptor $descriptor)
    {
        $page = isset($descriptor->page) && is_array($descriptor->page) ? $descriptor->page : [];

        if (! empty($page['isTaxonomyArchive'])) {
            $term_id = isset($page['entityId']) ? absint($page['entityId']) : 0;
            $taxonomy = isset($page['taxonomy']) ? sanitize_key((string) $page['taxonomy']) : '';
            $term = $term_id > 0 && $taxonomy !== '' ? get_term($term_id, $taxonomy) : null;

            return $term instanceof \WP_Term && ! is_wp_error($term) ? (string) $term->name : '';
        }

        if (! empty($page['isPostTypeArchive'])) {
            $post_type = isset($page['postType']) ? sanitize_key((string) $page['postType']) : '';
            $post_type_object = $post_type !== '' ? get_post_type_object($post_type) : null;
            if ($post_type_object && isset($post_type_object->labels->name)) {
                return (string) $post_type_object->labels->name;
            }
        }

        return '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<int, array<string, mixed>>
     */
    private function getQueryCollectionValue(EditableDescriptor $descriptor)
    {
        $ids = isset($descriptor->source['query_result_ids']) && is_array($descriptor->source['query_result_ids'])
            ? array_values(array_filter(array_map('absint', $descriptor->source['query_result_ids'])))
            : [];
        $items = [];

        foreach ($ids as $post_id) {
            $post = get_post($post_id);
            if (! $post instanceof \WP_Post) {
                continue;
            }

            $post_type = get_post_type($post_id);
            $post_type = is_string($post_type) ? sanitize_key($post_type) : '';
            $type_object = $post_type !== '' ? get_post_type_object($post_type) : null;
            $type_label = $type_object && ! empty($type_object->labels->singular_name)
                ? sanitize_text_field((string) $type_object->labels->singular_name)
                : ($post_type !== '' ? sanitize_text_field(ucwords(str_replace(['_', '-'], ' ', $post_type))) : __('Post', 'dbvc'));

            $items[] = [
                'id' => $post_id,
                'title' => get_the_title($post_id),
                'objectType' => 'post',
                'postType' => $post_type,
                'taxonomy' => '',
                'typeLabel' => $type_label,
                'status' => get_post_status($post_id),
                'frontendUrl' => get_permalink($post_id),
                'backendUrl' => get_edit_post_link($post_id, 'raw'),
            ];
        }

        return $items;
    }
}
