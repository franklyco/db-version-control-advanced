<?php

namespace Dbvc\VisualEditor\Resolvers;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class TermFieldResolver implements ResolverInterface
{
    /**
     * @return string
     */
    public function name()
    {
        return 'term_field';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    public function supports(EditableDescriptor $descriptor)
    {
        $field_name = isset($descriptor->source['field_name']) ? sanitize_key((string) $descriptor->source['field_name']) : '';

        return ($descriptor->source['type'] ?? '') === 'term_field'
            && in_array($field_name, ['term_name', 'term_description'], true);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return mixed
     */
    public function getValue(EditableDescriptor $descriptor)
    {
        $term = $this->getTerm($descriptor);
        if (! $term) {
            return '';
        }

        $field_name = $this->getFieldName($descriptor);

        if ($field_name === 'term_description') {
            return (string) get_term_field('description', $term->term_id, $term->taxonomy, 'raw');
        }

        return (string) get_term_field('name', $term->term_id, $term->taxonomy, 'raw');
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return mixed
     */
    public function getDisplayValue(EditableDescriptor $descriptor, $value)
    {
        if ($this->getFieldName($descriptor) === 'term_description') {
            $term = $this->getTerm($descriptor);
            if ($term) {
                $description = term_description($term->term_id, $term->taxonomy);

                return is_string($description) ? $description : (string) $value;
            }
        }

        return is_scalar($value) || $value === null ? (string) $value : '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    public function getDisplayMode(EditableDescriptor $descriptor)
    {
        return $this->getFieldName($descriptor) === 'term_description' ? 'html' : 'text';
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
            'message' => __('Term field value must be scalar.', 'dbvc'),
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return mixed
     */
    public function sanitize(EditableDescriptor $descriptor, $value)
    {
        if ($this->getFieldName($descriptor) === 'term_description') {
            return wp_kses_post((string) $value);
        }

        return sanitize_text_field((string) $value);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    public function save(EditableDescriptor $descriptor, $value)
    {
        $term = $this->getTerm($descriptor);
        if (! $term) {
            return [
                'ok' => false,
                'message' => __('Term context is missing.', 'dbvc'),
            ];
        }

        $field_name = $this->getFieldName($descriptor);
        $args = [];

        if ($field_name === 'term_description') {
            $args['description'] = (string) $value;
        } elseif ($field_name === 'term_name') {
            $args['name'] = (string) $value;
        } else {
            return [
                'ok' => false,
                'message' => __('Unsupported term field.', 'dbvc'),
            ];
        }

        $result = wp_update_term($term->term_id, $term->taxonomy, $args);
        if (is_wp_error($result)) {
            return [
                'ok' => false,
                'message' => $result->get_error_message(),
            ];
        }

        return [
            'ok' => true,
            'value' => $this->getValue($descriptor),
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    private function getFieldName(EditableDescriptor $descriptor)
    {
        return isset($descriptor->source['field_name']) ? sanitize_key((string) $descriptor->source['field_name']) : '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return \WP_Term|null
     */
    private function getTerm(EditableDescriptor $descriptor)
    {
        $term_id = isset($descriptor->entity['id']) ? absint($descriptor->entity['id']) : 0;
        $taxonomy = isset($descriptor->entity['taxonomy'])
            ? sanitize_key((string) $descriptor->entity['taxonomy'])
            : (isset($descriptor->entity['subtype']) ? sanitize_key((string) $descriptor->entity['subtype']) : '');

        if ($term_id <= 0 || $taxonomy === '') {
            return null;
        }

        $term = get_term($term_id, $taxonomy);

        return $term instanceof \WP_Term && ! is_wp_error($term) ? $term : null;
    }
}
