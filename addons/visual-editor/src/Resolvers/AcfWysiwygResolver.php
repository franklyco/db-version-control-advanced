<?php

namespace Dbvc\VisualEditor\Resolvers;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class AcfWysiwygResolver extends AbstractAcfResolver
{
    /**
     * @return string
     */
    public function name()
    {
        return 'acf_wysiwyg';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    public function supports(EditableDescriptor $descriptor)
    {
        return $this->supportsAcfSource($descriptor)
            && ($descriptor->source['field_type'] ?? '') === 'wysiwyg';
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
     * @return array<string, mixed>
     */
    public function validate(EditableDescriptor $descriptor, $value)
    {
        unset($descriptor);

        return [
            'ok' => is_scalar($value) || $value === null,
            'message' => __('ACF WYSIWYG fields require a scalar value.', 'dbvc'),
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

        return wp_kses_post((string) $value);
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

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return mixed
     */
    public function getDisplayValue(EditableDescriptor $descriptor, $value)
    {
        unset($descriptor);

        $value = is_scalar($value) || $value === null ? (string) $value : '';

        if ($value === '') {
            return '';
        }

        return apply_filters('acf_the_content', $value);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    public function getDisplayMode(EditableDescriptor $descriptor)
    {
        unset($descriptor);

        return 'html';
    }
}
