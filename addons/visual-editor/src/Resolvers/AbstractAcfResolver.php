<?php

namespace Dbvc\VisualEditor\Resolvers;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

abstract class AbstractAcfResolver implements ResolverInterface
{
    /**
     * @param EditableDescriptor $descriptor
     * @return mixed
     */
    protected function getRawAcfValue(EditableDescriptor $descriptor)
    {
        if ($this->isRepeaterSubfieldSource($descriptor)) {
            return $this->getRawRepeaterSubfieldValue($descriptor);
        }

        if ($this->isFlexibleSubfieldSource($descriptor)) {
            return $this->getRawFlexibleSubfieldValue($descriptor);
        }

        $object_id = $this->getAcfObjectId($descriptor);
        $field_identifier = $this->getFieldIdentifier($descriptor);

        if ($object_id === '' || $field_identifier === '') {
            return '';
        }

        if (function_exists('get_field')) {
            return get_field($field_identifier, $object_id, false);
        }

        $field_name = $this->getFieldName($descriptor);
        $post_id = $this->getPostId($descriptor);

        return $field_name !== '' && $post_id > 0 ? get_post_meta($post_id, $field_name, true) : '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    protected function writeAcfValue(EditableDescriptor $descriptor, $value)
    {
        if ($this->isRepeaterSubfieldSource($descriptor)) {
            return $this->writeRepeaterSubfieldValue($descriptor, $value);
        }

        if ($this->isFlexibleSubfieldSource($descriptor)) {
            return $this->writeFlexibleSubfieldValue($descriptor, $value);
        }

        $object_id = $this->getAcfObjectId($descriptor);
        $field_name = $this->getFieldName($descriptor);
        $field_key = $this->getFieldKey($descriptor);

        if ($object_id === '' || ($field_name === '' && $field_key === '')) {
            return [
                'ok' => false,
                'message' => __('ACF field context is missing.', 'dbvc'),
            ];
        }

        if (function_exists('update_field')) {
            $result = false;

            if ($field_name !== '') {
                $result = update_field($field_name, $value, $object_id);
            }

            $next_value = $this->getRawAcfValue($descriptor);
            if ($result === false && $field_key !== '' && ! $this->valuesEqual($next_value, $value)) {
                $result = update_field($field_key, $value, $object_id);
                $next_value = $this->getRawAcfValue($descriptor);
            }

            if ($result === false && ! $this->valuesEqual($next_value, $value)) {
                return [
                    'ok' => false,
                    'message' => __('ACF field update did not succeed.', 'dbvc'),
                ];
            }
        } else {
            $post_id = $this->getPostId($descriptor);

            if ($field_name === '' || $post_id <= 0) {
                return [
                    'ok' => false,
                    'message' => __('ACF field name is missing.', 'dbvc'),
                ];
            }

            update_post_meta($post_id, $field_name, $value);
        }

        return [
            'ok' => true,
            'value' => $this->getRawAcfValue($descriptor),
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    protected function getAcfObjectId(EditableDescriptor $descriptor)
    {
        if (isset($descriptor->entity['acf_object_id'])) {
            $object_id = $descriptor->entity['acf_object_id'];

            if (is_numeric($object_id)) {
                return (string) absint($object_id);
            }

            return sanitize_text_field((string) $object_id);
        }

        $post_id = $this->getPostId($descriptor);

        return $post_id > 0 ? (string) $post_id : '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return int
     */
    protected function getPostId(EditableDescriptor $descriptor)
    {
        return isset($descriptor->entity['type'], $descriptor->entity['id'])
            && (string) $descriptor->entity['type'] === 'post'
            ? absint($descriptor->entity['id'])
            : 0;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    protected function getFieldIdentifier(EditableDescriptor $descriptor)
    {
        $field_name = $this->getFieldName($descriptor);

        if ($field_name !== '') {
            return $field_name;
        }

        return $this->getFieldKey($descriptor);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    protected function getFieldName(EditableDescriptor $descriptor)
    {
        return isset($descriptor->source['field_name']) ? sanitize_key((string) $descriptor->source['field_name']) : '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    protected function getFieldKey(EditableDescriptor $descriptor)
    {
        return isset($descriptor->source['field_key']) ? sanitize_key((string) $descriptor->source['field_key']) : '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    protected function getFieldType(EditableDescriptor $descriptor)
    {
        return isset($descriptor->source['field_type']) ? sanitize_key((string) $descriptor->source['field_type']) : '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    protected function supportsAcfSource(EditableDescriptor $descriptor)
    {
        return in_array((string) ($descriptor->source['type'] ?? ''), ['acf_field', 'acf_repeater_subfield', 'acf_flexible_subfield'], true);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    protected function isRepeaterSubfieldSource(EditableDescriptor $descriptor)
    {
        return ($descriptor->source['type'] ?? '') === 'acf_repeater_subfield';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    protected function isFlexibleSubfieldSource(EditableDescriptor $descriptor)
    {
        return ($descriptor->source['type'] ?? '') === 'acf_flexible_subfield';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    protected function getParentFieldName(EditableDescriptor $descriptor)
    {
        return isset($descriptor->source['parent_field_name']) ? sanitize_key((string) $descriptor->source['parent_field_name']) : '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    protected function getParentFieldKey(EditableDescriptor $descriptor)
    {
        return isset($descriptor->source['parent_field_key']) ? sanitize_key((string) $descriptor->source['parent_field_key']) : '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return int|null
     */
    protected function getRepeaterRowIndex(EditableDescriptor $descriptor)
    {
        if (! isset($descriptor->source['row_index']) || ! is_numeric($descriptor->source['row_index'])) {
            return null;
        }

        return absint($descriptor->source['row_index']);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    protected function getFlexibleLayoutKey(EditableDescriptor $descriptor)
    {
        return isset($descriptor->source['layout_key']) ? sanitize_key((string) $descriptor->source['layout_key']) : '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    protected function getFlexibleLayoutName(EditableDescriptor $descriptor)
    {
        return isset($descriptor->source['layout_name']) ? sanitize_key((string) $descriptor->source['layout_name']) : '';
    }

    /**
     * @param mixed $left
     * @param mixed $right
     * @return bool
     */
    protected function valuesEqual($left, $right)
    {
        return wp_json_encode($left) === wp_json_encode($right);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return mixed
     */
    private function getRawRepeaterSubfieldValue(EditableDescriptor $descriptor)
    {
        $rows = $this->getRawRepeaterRows($descriptor);
        $row_index = $this->resolveRepeaterRowIndex($descriptor, $rows);

        if ($row_index < 0 || ! isset($rows[$row_index]) || ! is_array($rows[$row_index])) {
            return '';
        }

        $row = $rows[$row_index];
        $field_key = $this->getFieldKey($descriptor);
        $field_name = $this->getFieldName($descriptor);

        if ($field_key !== '' && array_key_exists($field_key, $row)) {
            return $row[$field_key];
        }

        if ($field_name !== '' && array_key_exists($field_name, $row)) {
            return $row[$field_name];
        }

        return '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    private function writeRepeaterSubfieldValue(EditableDescriptor $descriptor, $value)
    {
        $object_id = $this->getAcfObjectId($descriptor);
        $parent_field_name = $this->getParentFieldName($descriptor);
        $parent_field_key = $this->getParentFieldKey($descriptor);

        if ($object_id === '' || ($parent_field_name === '' && $parent_field_key === '')) {
            return [
                'ok' => false,
                'message' => __('Repeater field context is missing.', 'dbvc'),
            ];
        }

        $rows = $this->getRawRepeaterRows($descriptor);
        $row_index = $this->resolveRepeaterRowIndex($descriptor, $rows);

        if ($row_index < 0 || ! isset($rows[$row_index])) {
            return [
                'ok' => false,
                'message' => __('The repeater row could not be resolved safely.', 'dbvc'),
            ];
        }

        $row = is_array($rows[$row_index]) ? $rows[$row_index] : [];
        $rows[$row_index] = $this->replaceRepeaterRowValue($row, $descriptor, $value);

        if (! function_exists('update_field')) {
            return [
                'ok' => false,
                'message' => __('ACF update_field() is required for repeater row editing.', 'dbvc'),
            ];
        }

        $result = false;

        if ($parent_field_name !== '') {
            $result = update_field($parent_field_name, $rows, $object_id);
        }

        $next_value = $this->getRawRepeaterSubfieldValue($descriptor);
        if ($result === false && $parent_field_key !== '' && ! $this->valuesEqual($next_value, $value)) {
            $result = update_field($parent_field_key, $rows, $object_id);
            $next_value = $this->getRawRepeaterSubfieldValue($descriptor);
        }

        if ($result === false && ! $this->valuesEqual($next_value, $value)) {
            return [
                'ok' => false,
                'message' => __('ACF repeater row update did not succeed.', 'dbvc'),
            ];
        }

        return [
            'ok' => true,
            'value' => $this->getRawAcfValue($descriptor),
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return mixed
     */
    private function getRawFlexibleSubfieldValue(EditableDescriptor $descriptor)
    {
        $rows = $this->getRawFlexibleRows($descriptor);
        $row_index = $this->resolveFlexibleRowIndex($descriptor, $rows);

        if ($row_index < 0 || ! isset($rows[$row_index]) || ! is_array($rows[$row_index])) {
            return '';
        }

        $row = $rows[$row_index];
        $layout_name = $this->getFlexibleLayoutName($descriptor);
        if ($layout_name !== '' && isset($row['acf_fc_layout']) && sanitize_key((string) $row['acf_fc_layout']) !== $layout_name) {
            return '';
        }

        $field_key = $this->getFieldKey($descriptor);
        $field_name = $this->getFieldName($descriptor);

        if ($field_key !== '' && array_key_exists($field_key, $row)) {
            return $row[$field_key];
        }

        if ($field_name !== '' && array_key_exists($field_name, $row)) {
            return $row[$field_name];
        }

        return '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    private function writeFlexibleSubfieldValue(EditableDescriptor $descriptor, $value)
    {
        $object_id = $this->getAcfObjectId($descriptor);
        $parent_field_name = $this->getParentFieldName($descriptor);
        $parent_field_key = $this->getParentFieldKey($descriptor);

        if ($object_id === '' || ($parent_field_name === '' && $parent_field_key === '')) {
            return [
                'ok' => false,
                'message' => __('Flexible field context is missing.', 'dbvc'),
            ];
        }

        $rows = $this->getRawFlexibleRows($descriptor);
        $row_index = $this->resolveFlexibleRowIndex($descriptor, $rows);

        if ($row_index < 0 || ! isset($rows[$row_index])) {
            return [
                'ok' => false,
                'message' => __('The flexible-content row could not be resolved safely.', 'dbvc'),
            ];
        }

        $row = is_array($rows[$row_index]) ? $rows[$row_index] : [];
        $layout_name = $this->getFlexibleLayoutName($descriptor);
        if ($layout_name !== '' && isset($row['acf_fc_layout']) && sanitize_key((string) $row['acf_fc_layout']) !== $layout_name) {
            return [
                'ok' => false,
                'message' => __('The flexible-content layout did not match the expected row.', 'dbvc'),
            ];
        }

        $rows[$row_index] = $this->replaceRepeaterRowValue($row, $descriptor, $value);

        if (! function_exists('update_field')) {
            return [
                'ok' => false,
                'message' => __('ACF update_field() is required for flexible-content editing.', 'dbvc'),
            ];
        }

        $result = false;

        if ($parent_field_name !== '') {
            $result = update_field($parent_field_name, $rows, $object_id);
        }

        $next_value = $this->getRawFlexibleSubfieldValue($descriptor);
        if ($result === false && $parent_field_key !== '' && ! $this->valuesEqual($next_value, $value)) {
            $result = update_field($parent_field_key, $rows, $object_id);
            $next_value = $this->getRawFlexibleSubfieldValue($descriptor);
        }

        if ($result === false && ! $this->valuesEqual($next_value, $value)) {
            return [
                'ok' => false,
                'message' => __('ACF flexible-content row update did not succeed.', 'dbvc'),
            ];
        }

        return [
            'ok' => true,
            'value' => $this->getRawAcfValue($descriptor),
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<int, array<string, mixed>>
     */
    private function getRawRepeaterRows(EditableDescriptor $descriptor)
    {
        $object_id = $this->getAcfObjectId($descriptor);
        $parent_identifier = $this->getParentFieldName($descriptor);

        if ($parent_identifier === '') {
            $parent_identifier = $this->getParentFieldKey($descriptor);
        }

        if ($object_id === '' || $parent_identifier === '') {
            return [];
        }

        if (! function_exists('get_field')) {
            return [];
        }

        $rows = get_field($parent_identifier, $object_id, false);

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<int, array<string, mixed>>
     */
    private function getRawFlexibleRows(EditableDescriptor $descriptor)
    {
        $object_id = $this->getAcfObjectId($descriptor);
        $parent_identifier = $this->getParentFieldName($descriptor);

        if ($parent_identifier === '') {
            $parent_identifier = $this->getParentFieldKey($descriptor);
        }

        if ($object_id === '' || $parent_identifier === '') {
            return [];
        }

        if (! function_exists('get_field')) {
            return [];
        }

        $rows = get_field($parent_identifier, $object_id, false);

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param EditableDescriptor         $descriptor
     * @param array<int, mixed> $rows
     * @return int
     */
    private function resolveRepeaterRowIndex(EditableDescriptor $descriptor, array $rows)
    {
        $requested_index = $this->getRepeaterRowIndex($descriptor);

        if ($requested_index === null) {
            return -1;
        }

        if (array_key_exists($requested_index, $rows)) {
            return $requested_index;
        }

        if ($requested_index > 0 && array_key_exists($requested_index - 1, $rows)) {
            return $requested_index - 1;
        }

        return -1;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param array<int, mixed>  $rows
     * @return int
     */
    private function resolveFlexibleRowIndex(EditableDescriptor $descriptor, array $rows)
    {
        $requested_index = $this->getRepeaterRowIndex($descriptor);

        if ($requested_index === null) {
            return -1;
        }

        if (array_key_exists($requested_index, $rows)) {
            return $requested_index;
        }

        if ($requested_index > 0 && array_key_exists($requested_index - 1, $rows)) {
            return $requested_index - 1;
        }

        return -1;
    }

    /**
     * @param array<string, mixed> $row
     * @param EditableDescriptor   $descriptor
     * @param mixed                $value
     * @return array<string, mixed>
     */
    private function replaceRepeaterRowValue(array $row, EditableDescriptor $descriptor, $value)
    {
        $field_key = $this->getFieldKey($descriptor);
        $field_name = $this->getFieldName($descriptor);
        $updated = false;

        if ($field_key !== '' && array_key_exists($field_key, $row)) {
            $row[$field_key] = $value;
            $updated = true;
        }

        if ($field_name !== '' && array_key_exists($field_name, $row)) {
            $row[$field_name] = $value;
            $updated = true;
        }

        if (! $updated) {
            if ($field_key !== '') {
                $row[$field_key] = $value;
            }

            if ($field_name !== '') {
                $row[$field_name] = $value;
            }
        }

        return $row;
    }
}
