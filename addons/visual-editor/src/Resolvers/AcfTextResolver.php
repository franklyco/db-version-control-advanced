<?php

namespace Dbvc\VisualEditor\Resolvers;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class AcfTextResolver extends AbstractAcfResolver
{
    /**
     * @return string
     */
    public function name()
    {
        return 'acf_text';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    public function supports(EditableDescriptor $descriptor)
    {
        return $this->supportsAcfSource($descriptor)
            && in_array(($descriptor->source['field_type'] ?? ''), ['text', 'textarea', 'url', 'email', 'number', 'range'], true);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return mixed
     */
    public function getValue(EditableDescriptor $descriptor)
    {
        return $this->getRawAcfValue($descriptor);
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
        unset($descriptor);

        return [
            'ok' => is_scalar($value) || $value === null,
            'message' => __('ACF text-like fields require a scalar value.', 'dbvc'),
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return mixed
     */
    public function sanitize(EditableDescriptor $descriptor, $value)
    {
        $field_type = isset($descriptor->source['field_type']) ? (string) $descriptor->source['field_type'] : 'text';
        $string_value = is_scalar($value) || $value === null ? (string) $value : '';

        if ($field_type === 'textarea') {
            return sanitize_textarea_field($string_value);
        }

        if ($field_type === 'email') {
            return sanitize_email($string_value);
        }

        if ($field_type === 'url') {
            return esc_url_raw($string_value);
        }

        if (in_array($field_type, ['number', 'range'], true)) {
            $string_value = trim($string_value);

            if ($string_value === '') {
                return '';
            }

            return is_numeric($string_value) ? $string_value : '';
        }

        return sanitize_text_field($string_value);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    public function save(EditableDescriptor $descriptor, $value)
    {
        return $this->writeAcfValue($descriptor, $value);
    }
}
