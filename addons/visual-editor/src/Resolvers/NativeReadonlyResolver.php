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

        return '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return mixed
     */
    public function getDisplayValue(EditableDescriptor $descriptor, $value)
    {
        unset($descriptor);

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
}
