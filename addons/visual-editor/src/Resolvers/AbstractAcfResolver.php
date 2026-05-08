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
        if ($this->isRepeaterCollectionSource($descriptor)) {
            return $this->getRawRepeaterSubfieldValue($descriptor);
        }

        if ($this->isFlexibleCollectionSource($descriptor)) {
            return $this->getRawFlexibleSubfieldValue($descriptor);
        }

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
        if ($this->isRepeaterCollectionSource($descriptor)) {
            return $this->writeRepeaterSubfieldValue($descriptor, $value);
        }

        if ($this->isFlexibleCollectionSource($descriptor)) {
            return $this->writeFlexibleSubfieldValue($descriptor, $value);
        }

        if ($this->isRepeaterSubfieldSource($descriptor)) {
            return $this->writeRepeaterSubfieldValue($descriptor, $value);
        }

        if ($this->isFlexibleSubfieldSource($descriptor)) {
            return $this->writeFlexibleSubfieldValue($descriptor, $value);
        }

        $object_id = $this->getAcfObjectId($descriptor);
        $field_identifier = $this->getFieldIdentifier($descriptor);
        $field_name = $this->getFieldName($descriptor);
        $field_key = $this->getFieldKey($descriptor);

        if ($object_id === '' || ($field_identifier === '' && $field_name === '' && $field_key === '')) {
            return [
                'ok' => false,
                'message' => __('ACF field context is missing.', 'dbvc'),
            ];
        }

        if (function_exists('update_field')) {
            $result = false;
            $prefer_identifier = $this->shouldPreferFieldIdentifierWrite($descriptor, $field_identifier, $field_name);

            if ($prefer_identifier && $field_identifier !== '') {
                $result = update_field($field_identifier, $value, $object_id);
            }

            if ($result === false && $field_name !== '') {
                $result = update_field($field_name, $value, $object_id);
            }

            if ($result === false && ! $prefer_identifier && $field_identifier !== '' && $field_identifier !== $field_name) {
                $result = update_field($field_identifier, $value, $object_id);
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
        $field_selector = isset($descriptor->source['field_selector']) ? sanitize_key((string) $descriptor->source['field_selector']) : '';
        if ($field_selector !== '') {
            return $field_selector;
        }

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
     * @return string
     */
    protected function getLeafFieldName(EditableDescriptor $descriptor)
    {
        if (isset($descriptor->source['leaf_field_name']) && (string) $descriptor->source['leaf_field_name'] !== '') {
            return sanitize_key((string) $descriptor->source['leaf_field_name']);
        }

        return $this->getFieldName($descriptor);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    protected function getLeafFieldKey(EditableDescriptor $descriptor)
    {
        if (isset($descriptor->source['leaf_field_key']) && (string) $descriptor->source['leaf_field_key'] !== '') {
            return sanitize_key((string) $descriptor->source['leaf_field_key']);
        }

        return $this->getFieldKey($descriptor);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<int, string>
     */
    protected function getGroupPath(EditableDescriptor $descriptor)
    {
        if (empty($descriptor->source['group_path']) || ! is_array($descriptor->source['group_path'])) {
            return [];
        }

        return array_values(
            array_filter(
                array_map(
                    static function ($value) {
                        return sanitize_key((string) $value);
                    },
                    $descriptor->source['group_path']
                )
            )
        );
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<int, string>
     */
    protected function getGroupKeyPath(EditableDescriptor $descriptor)
    {
        if (empty($descriptor->source['group_key_path']) || ! is_array($descriptor->source['group_key_path'])) {
            return [];
        }

        return array_values(
            array_filter(
                array_map(
                    static function ($value) {
                        return sanitize_key((string) $value);
                    },
                    $descriptor->source['group_key_path']
                )
            )
        );
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<int, array<string, mixed>>
     */
    protected function getNestedRepeaterPath(EditableDescriptor $descriptor)
    {
        if (empty($descriptor->source['nested_repeater_path']) || ! is_array($descriptor->source['nested_repeater_path'])) {
            return [];
        }

        $segments = [];

        foreach ($descriptor->source['nested_repeater_path'] as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $field_name = isset($segment['field_name']) ? sanitize_key((string) $segment['field_name']) : '';
            $field_key = isset($segment['field_key']) ? sanitize_key((string) $segment['field_key']) : '';
            $field_selector = isset($segment['field_selector']) ? sanitize_key((string) $segment['field_selector']) : '';
            $row_index = isset($segment['row_index']) && $segment['row_index'] !== null && is_numeric($segment['row_index'])
                ? absint($segment['row_index'])
                : null;

            if ($field_name === '' && $field_key === '' && $field_selector === '') {
                continue;
            }

            $segments[] = [
                'field_name' => $field_name,
                'field_key' => $field_key,
                'field_selector' => $field_selector,
                'row_index' => $row_index,
            ];
        }

        return $segments;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    protected function isGroupedFieldSource(EditableDescriptor $descriptor)
    {
        if (! empty($this->getGroupPath($descriptor))) {
            return true;
        }

        return ! empty($descriptor->source['is_grouped_field']);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    protected function supportsAcfSource(EditableDescriptor $descriptor)
    {
        return in_array((string) ($descriptor->source['type'] ?? ''), ['acf_field', 'acf_repeater_subfield', 'acf_flexible_subfield', 'acf_collection_field'], true);
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
     * @return bool
     */
    protected function isRepeaterCollectionSource(EditableDescriptor $descriptor)
    {
        return ($descriptor->source['type'] ?? '') === 'acf_collection_field'
            && ($descriptor->source['container_type'] ?? '') === 'repeater';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    protected function isFlexibleCollectionSource(EditableDescriptor $descriptor)
    {
        return ($descriptor->source['type'] ?? '') === 'acf_collection_field'
            && ($descriptor->source['container_type'] ?? '') === 'flexible_content';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param string             $field_identifier
     * @param string             $field_name
     * @return bool
     */
    private function shouldPreferFieldIdentifierWrite(EditableDescriptor $descriptor, $field_identifier, $field_name)
    {
        return $field_identifier !== ''
            && $field_identifier !== $field_name
            && $this->isGroupedFieldSource($descriptor)
            && ! $this->isRepeaterSubfieldSource($descriptor)
            && ! $this->isFlexibleSubfieldSource($descriptor);
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
    protected function getParentFieldSelector(EditableDescriptor $descriptor)
    {
        return isset($descriptor->source['parent_field_selector']) ? sanitize_key((string) $descriptor->source['parent_field_selector']) : '';
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
     * @return array<int, array<string, mixed>>
     */
    public function getRowCandidateValues(EditableDescriptor $descriptor)
    {
        $candidates = [];

        if ($this->isRepeaterSubfieldSource($descriptor)) {
            $rows = $this->getRawRepeaterRows($descriptor);

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $candidates[] = [
                    'index' => (int) $index,
                    'value' => $this->extractRowFieldValue($row, $descriptor),
                ];
            }

            return $candidates;
        }

        if ($this->isFlexibleSubfieldSource($descriptor)) {
            $rows = $this->getRawFlexibleRows($descriptor);
            $layout_name = $this->getFlexibleLayoutName($descriptor);

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                if ($layout_name !== '' && isset($row['acf_fc_layout']) && sanitize_key((string) $row['acf_fc_layout']) !== $layout_name) {
                    continue;
                }

                $candidates[] = [
                    'index' => (int) $index,
                    'value' => $this->extractRowFieldValue($row, $descriptor),
                ];
            }
        }

        return $candidates;
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
        return $this->extractRowFieldValue($row, $descriptor);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    private function writeRepeaterSubfieldValue(EditableDescriptor $descriptor, $value)
    {
        $object_id = $this->getAcfObjectId($descriptor);
        $parent_field_selector = $this->getParentFieldSelector($descriptor);
        $parent_field_name = $this->getParentFieldName($descriptor);
        $parent_field_key = $this->getParentFieldKey($descriptor);

        if ($object_id === '' || ($parent_field_selector === '' && $parent_field_name === '' && $parent_field_key === '')) {
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
        $rows[$row_index] = $this->replaceRowFieldValue($row, $descriptor, $value);

        if (! function_exists('update_field')) {
            return [
                'ok' => false,
                'message' => __('ACF update_field() is required for repeater row editing.', 'dbvc'),
            ];
        }

        $result = false;

        if ($parent_field_selector !== '') {
            $result = update_field($parent_field_selector, $rows, $object_id);
        }

        if ($result === false && $parent_field_name !== '') {
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

        return $this->extractRowFieldValue($row, $descriptor);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    private function writeFlexibleSubfieldValue(EditableDescriptor $descriptor, $value)
    {
        $object_id = $this->getAcfObjectId($descriptor);
        $parent_field_selector = $this->getParentFieldSelector($descriptor);
        $parent_field_name = $this->getParentFieldName($descriptor);
        $parent_field_key = $this->getParentFieldKey($descriptor);

        if ($object_id === '' || ($parent_field_selector === '' && $parent_field_name === '' && $parent_field_key === '')) {
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

        $rows[$row_index] = $this->replaceRowFieldValue($row, $descriptor, $value);

        if (! function_exists('update_field')) {
            return [
                'ok' => false,
                'message' => __('ACF update_field() is required for flexible-content editing.', 'dbvc'),
            ];
        }

        $result = false;

        if ($parent_field_selector !== '') {
            $result = update_field($parent_field_selector, $rows, $object_id);
        }

        if ($result === false && $parent_field_name !== '') {
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
        $parent_identifier = $this->resolveParentFieldReadIdentifier($descriptor);

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
        $parent_identifier = $this->resolveParentFieldReadIdentifier($descriptor);

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
    private function extractRowFieldValue(array $row, EditableDescriptor $descriptor)
    {
        $container = $this->resolveLeafRowContainer($row, $descriptor);
        $field_keys = $this->resolveRowFieldKeys($descriptor);

        foreach ($field_keys as $field_key) {
            if (array_key_exists($field_key, $container)) {
                return $container[$field_key];
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     * @param EditableDescriptor   $descriptor
     * @param mixed                $value
     * @return array<string, mixed>
     */
    private function replaceRowFieldValue(array $row, EditableDescriptor $descriptor, $value)
    {
        $container =& $this->resolveLeafRowContainerReference($row, $descriptor);
        $field_keys = $this->resolveRowFieldKeys($descriptor);
        $updated = false;

        foreach ($field_keys as $field_key) {
            if (array_key_exists($field_key, $container)) {
                $container[$field_key] = $value;
                $updated = true;
            }
        }

        if (! $updated) {
            foreach ($field_keys as $field_key) {
                if ($field_key !== '') {
                    $container[$field_key] = $value;
                }
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param EditableDescriptor   $descriptor
     * @return array<string, mixed>
     */
    private function resolveLeafRowContainer(array $row, EditableDescriptor $descriptor)
    {
        $leaf_row = $this->resolveNestedRepeaterRow($row, $descriptor);

        return $this->resolveGroupedRowContainer($leaf_row, $descriptor);
    }

    /**
     * @param array<string, mixed> $row
     * @param EditableDescriptor   $descriptor
     * @return array<string, mixed>
     */
    private function &resolveLeafRowContainerReference(array &$row, EditableDescriptor $descriptor)
    {
        $leaf_row =& $this->resolveNestedRepeaterRowReference($row, $descriptor);
        $container =& $this->resolveGroupedRowContainerReference($leaf_row, $descriptor);

        return $container;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<int, string>
     */
    private function resolveRowFieldKeys(EditableDescriptor $descriptor)
    {
        return array_values(
            array_filter(
                array_unique(
                    [
                        $this->getLeafFieldKey($descriptor),
                        $this->getLeafFieldName($descriptor),
                        $this->getFieldKey($descriptor),
                        $this->getFieldName($descriptor),
                    ]
                )
            )
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param EditableDescriptor   $descriptor
     * @return array<string, mixed>
     */
    private function resolveNestedRepeaterRow(array $row, EditableDescriptor $descriptor)
    {
        $container = $row;
        $segments = $this->getNestedRepeaterPath($descriptor);

        foreach ($segments as $segment) {
            $segment_key = $this->resolveNestedRepeaterSegmentKey($container, $segment);
            if ($segment_key === '' || ! isset($container[$segment_key]) || ! is_array($container[$segment_key])) {
                return [];
            }

            $rows = array_values($container[$segment_key]);
            $row_index = $this->resolveNestedRepeaterSegmentRowIndex($segment, $rows);
            if ($row_index < 0 || ! isset($rows[$row_index]) || ! is_array($rows[$row_index])) {
                return [];
            }

            $container = $rows[$row_index];
        }

        return is_array($container) ? $container : [];
    }

    /**
     * @param array<string, mixed> $row
     * @param EditableDescriptor   $descriptor
     * @return array<string, mixed>
     */
    private function &resolveNestedRepeaterRowReference(array &$row, EditableDescriptor $descriptor)
    {
        $container =& $row;
        $segments = $this->getNestedRepeaterPath($descriptor);

        foreach ($segments as $segment) {
            $segment_key = $this->resolveNestedRepeaterSegmentKey($container, $segment);
            if ($segment_key === '') {
                $segment_key = $this->fallbackNestedRepeaterSegmentKey($segment);
            }

            if ($segment_key === '') {
                continue;
            }

            if (! isset($container[$segment_key]) || ! is_array($container[$segment_key])) {
                $container[$segment_key] = [];
            }

            $container[$segment_key] = array_values($container[$segment_key]);
            $rows =& $container[$segment_key];
            $row_index = $this->resolveNestedRepeaterSegmentRowIndex($segment, $rows);

            if ($row_index < 0) {
                $row_index = isset($segment['row_index']) && $segment['row_index'] !== null && is_numeric($segment['row_index'])
                    ? absint($segment['row_index'])
                    : 0;
            }

            if (! isset($rows[$row_index]) || ! is_array($rows[$row_index])) {
                $rows[$row_index] = [];
            }

            $container =& $rows[$row_index];
        }

        return $container;
    }

    /**
     * @param array<string, mixed> $container
     * @param array<string, mixed> $segment
     * @return string
     */
    private function resolveNestedRepeaterSegmentKey(array $container, array $segment)
    {
        $field_key = isset($segment['field_key']) ? sanitize_key((string) $segment['field_key']) : '';
        $field_name = isset($segment['field_name']) ? sanitize_key((string) $segment['field_name']) : '';
        $field_selector = isset($segment['field_selector']) ? sanitize_key((string) $segment['field_selector']) : '';

        if ($field_key !== '' && isset($container[$field_key]) && is_array($container[$field_key])) {
            return $field_key;
        }

        if ($field_name !== '' && isset($container[$field_name]) && is_array($container[$field_name])) {
            return $field_name;
        }

        if ($field_selector !== '' && isset($container[$field_selector]) && is_array($container[$field_selector])) {
            return $field_selector;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $segment
     * @return string
     */
    private function fallbackNestedRepeaterSegmentKey(array $segment)
    {
        $field_key = isset($segment['field_key']) ? sanitize_key((string) $segment['field_key']) : '';
        if ($field_key !== '') {
            return $field_key;
        }

        $field_name = isset($segment['field_name']) ? sanitize_key((string) $segment['field_name']) : '';
        if ($field_name !== '') {
            return $field_name;
        }

        return isset($segment['field_selector']) ? sanitize_key((string) $segment['field_selector']) : '';
    }

    /**
     * @param array<string, mixed> $segment
     * @param array<int, mixed>    $rows
     * @return int
     */
    private function resolveNestedRepeaterSegmentRowIndex(array $segment, array $rows)
    {
        if (! isset($segment['row_index']) || $segment['row_index'] === null || ! is_numeric($segment['row_index'])) {
            return -1;
        }

        $requested_index = absint($segment['row_index']);

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
     * @return array<string, mixed>
     */
    private function resolveGroupedRowContainer(array $row, EditableDescriptor $descriptor)
    {
        $container = $row;

        $group_names = $this->getGroupPath($descriptor);
        $group_keys = $this->getGroupKeyPath($descriptor);
        $depth = max(count($group_names), count($group_keys));

        for ($index = 0; $index < $depth; $index++) {
            $group_name = isset($group_names[$index]) ? $group_names[$index] : '';
            $group_key = isset($group_keys[$index]) ? $group_keys[$index] : '';
            $segment = $this->resolveGroupedRowSegmentKey($container, $group_name, $group_key);

            if ($segment === '' || ! isset($container[$segment]) || ! is_array($container[$segment])) {
                return [];
            }

            $container = $container[$segment];
        }

        return is_array($container) ? $container : [];
    }

    /**
     * @param array<string, mixed> $row
     * @param EditableDescriptor   $descriptor
     * @return array<string, mixed>
     */
    private function &resolveGroupedRowContainerReference(array &$row, EditableDescriptor $descriptor)
    {
        $container =& $row;

        $group_names = $this->getGroupPath($descriptor);
        $group_keys = $this->getGroupKeyPath($descriptor);
        $depth = max(count($group_names), count($group_keys));

        for ($index = 0; $index < $depth; $index++) {
            $group_name = isset($group_names[$index]) ? $group_names[$index] : '';
            $group_key = isset($group_keys[$index]) ? $group_keys[$index] : '';
            $segment = $this->resolveGroupedRowSegmentKey($container, $group_name, $group_key);

            if ($segment === '') {
                $segment = $group_key !== '' ? $group_key : $group_name;
            }

            if ($segment === '') {
                continue;
            }

            if (! isset($container[$segment]) || ! is_array($container[$segment])) {
                $container[$segment] = [];
            }

            $container =& $container[$segment];
        }

        return $container;
    }

    /**
     * @param array<string, mixed> $container
     * @param string               $group_name
     * @param string               $group_key
     * @return string
     */
    private function resolveGroupedRowSegmentKey(array $container, $group_name, $group_key)
    {
        $group_name = sanitize_key((string) $group_name);
        $group_key = sanitize_key((string) $group_key);

        if ($group_key !== '' && isset($container[$group_key]) && is_array($container[$group_key])) {
            return $group_key;
        }

        if ($group_name !== '' && isset($container[$group_name]) && is_array($container[$group_name])) {
            return $group_name;
        }

        return '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    private function resolveParentFieldReadIdentifier(EditableDescriptor $descriptor)
    {
        $selector = $this->getParentFieldSelector($descriptor);
        if ($selector !== '') {
            return $selector;
        }

        $field_name = $this->getParentFieldName($descriptor);
        if ($field_name !== '') {
            return $field_name;
        }

        return $this->getParentFieldKey($descriptor);
    }
}
