<?php

namespace Dbvc\VisualEditor\Resolvers;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class PostExcerptResolver implements ResolverInterface
{
    /**
     * @return string
     */
    public function name()
    {
        return 'post_excerpt';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    public function supports(EditableDescriptor $descriptor)
    {
        return ($descriptor->source['type'] ?? '') === 'post_field'
            && ($descriptor->source['field_name'] ?? '') === 'post_excerpt';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return mixed
     */
    public function getValue(EditableDescriptor $descriptor)
    {
        $post_id = isset($descriptor->entity['id']) ? absint($descriptor->entity['id']) : 0;

        return $post_id > 0 ? (string) get_post_field('post_excerpt', $post_id) : '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return mixed
     */
    public function getDisplayValue(EditableDescriptor $descriptor, $value)
    {
        $post_id = isset($descriptor->entity['id']) ? absint($descriptor->entity['id']) : 0;
        if ($post_id <= 0) {
            return '';
        }

        if (class_exists('\\Bricks\\Helpers') && method_exists('\\Bricks\\Helpers', 'get_the_excerpt')) {
            return \Bricks\Helpers::get_the_excerpt($post_id, 55, null, false);
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
        unset($descriptor);

        return [
            'ok' => is_scalar($value) || $value === null,
            'message' => __('Post excerpt must be a scalar value.', 'dbvc'),
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

        return sanitize_textarea_field((string) $value);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    public function save(EditableDescriptor $descriptor, $value)
    {
        $post_id = isset($descriptor->entity['id']) ? absint($descriptor->entity['id']) : 0;
        if ($post_id <= 0) {
            return [
                'ok' => false,
                'message' => __('Post context is missing.', 'dbvc'),
            ];
        }

        $result = wp_update_post(
            [
                'ID' => $post_id,
                'post_excerpt' => (string) $value,
            ],
            true
        );

        if (is_wp_error($result)) {
            return [
                'ok' => false,
                'message' => $result->get_error_message(),
            ];
        }

        return [
            'ok' => true,
            'value' => (string) get_post_field('post_excerpt', $post_id),
        ];
    }
}
