<?php

namespace Dbvc\VisualEditor\Resolvers;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class AcfChoiceResolver extends AbstractAcfResolver
{
    /**
     * @return string
     */
    public function name()
    {
        return 'acf_choice';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    public function supports(EditableDescriptor $descriptor)
    {
        return $this->supportsAcfSource($descriptor)
            && in_array(($descriptor->source['field_type'] ?? ''), ['checkbox', 'select', 'radio', 'button_group'], true);
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
        $allow_multiple = ! empty($descriptor->ui['allowMultiple']);

        if ($allow_multiple && ! is_array($value) && ! is_scalar($value) && $value !== null) {
            return [
                'ok' => false,
                'message' => __('This choice field expects one or more selected values.', 'dbvc'),
            ];
        }

        if (! $allow_multiple && ! is_scalar($value) && $value !== null) {
            return [
                'ok' => false,
                'message' => __('This choice field expects a single scalar value.', 'dbvc'),
            ];
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
        $allow_multiple = ! empty($descriptor->ui['allowMultiple']);
        $allowed_values = $this->getAllowedValues($descriptor);

        if ($allow_multiple) {
            $values = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);
            $sanitized = [];

            foreach ($values as $item) {
                $item = sanitize_text_field((string) $item);

                if ($item !== '' && in_array($item, $allowed_values, true)) {
                    $sanitized[] = $item;
                }
            }

            return array_values(array_unique($sanitized));
        }

        $item = sanitize_text_field((string) $value);

        return in_array($item, $allowed_values, true) ? $item : '';
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
        $display_key = $this->resolveDisplayKey($descriptor);

        if ($display_key === 'labels') {
            return $this->buildLabelsDisplay($descriptor, $value);
        }

        if ($display_key === 'values') {
            return $this->buildValuesDisplay($value);
        }

        $return_format = isset($descriptor->source['return_format']) ? (string) $descriptor->source['return_format'] : 'value';

        return in_array($return_format, ['label', 'array'], true)
            ? $this->buildLabelsDisplay($descriptor, $value)
            : $this->buildValuesDisplay($value);
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
        $candidates = [];
        $values_display = $this->buildValuesDisplay($value);
        $labels_display = $this->buildLabelsDisplay($descriptor, $value);

        if ($values_display !== '') {
            $candidates[] = [
                'key' => 'values',
                'value' => $values_display,
                'mode' => 'text',
            ];
        }

        if ($labels_display !== '' && $labels_display !== $values_display) {
            $candidates[] = [
                'key' => 'labels',
                'value' => $labels_display,
                'mode' => 'text',
            ];
        }

        if (empty($candidates)) {
            $candidates[] = [
                'key' => 'values',
                'value' => '',
                'mode' => 'text',
            ];
        }

        return $candidates;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<int, string>
     */
    private function getAllowedValues(EditableDescriptor $descriptor)
    {
        $options = isset($descriptor->ui['options']) && is_array($descriptor->ui['options']) ? $descriptor->ui['options'] : [];
        $values = [];

        foreach ($options as $option) {
            if (! is_array($option) || ! isset($option['value'])) {
                continue;
            }

            $values[] = sanitize_text_field((string) $option['value']);
        }

        return array_values(array_unique(array_filter($values, static function ($value) {
            return $value !== '';
        })));
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    private function resolveDisplayKey(EditableDescriptor $descriptor)
    {
        $display_key = isset($descriptor->render['display_key']) ? (string) $descriptor->render['display_key'] : '';

        return in_array($display_key, ['labels', 'values'], true) ? $display_key : '';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function buildValuesDisplay($value)
    {
        $values = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);
        $display = [];

        foreach ($values as $selected) {
            $selected = sanitize_text_field((string) $selected);
            if ($selected !== '') {
                $display[] = $selected;
            }
        }

        return implode(', ', $display);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return string
     */
    private function buildLabelsDisplay(EditableDescriptor $descriptor, $value)
    {
        $options = isset($descriptor->ui['options']) && is_array($descriptor->ui['options']) ? $descriptor->ui['options'] : [];
        $values = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);
        $labels = [];

        foreach ($values as $selected) {
            $selected = sanitize_text_field((string) $selected);
            if ($selected === '') {
                continue;
            }

            $label = $selected;

            foreach ($options as $option) {
                if (! is_array($option)) {
                    continue;
                }

                $option_value = isset($option['value']) ? (string) $option['value'] : '';
                if ($option_value === $selected) {
                    $label = isset($option['label']) ? (string) $option['label'] : $selected;
                    break;
                }
            }

            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return implode(', ', $labels);
    }
}
