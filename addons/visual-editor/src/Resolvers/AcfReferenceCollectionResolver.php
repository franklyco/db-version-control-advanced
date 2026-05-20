<?php

namespace Dbvc\VisualEditor\Resolvers;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class AcfReferenceCollectionResolver extends AbstractAcfResolver
{
    /**
     * @return string
     */
    public function name()
    {
        return 'acf_reference_collection';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    public function supports(EditableDescriptor $descriptor)
    {
        $field_type = (string) ($descriptor->source['field_type'] ?? '');
        $context = (string) ($descriptor->render['context'] ?? '');
        $source_type = (string) ($descriptor->source['type'] ?? '');

        return $source_type === 'acf_collection_field'
            && $context === 'query_collection'
            && in_array($field_type, ['relationship', 'post_object'], true);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<int, array<string, mixed>>
     */
    public function getValue(EditableDescriptor $descriptor)
    {
        $ids = $this->getSelectedReferenceIds($descriptor);
        $items = [];

        foreach ($ids as $post_id) {
            $item = $this->buildReferenceItem($post_id);
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
            /* translators: %d: connected item count */
            _n('%d connected item', '%d connected items', $count, 'dbvc'),
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
                'message' => __('Connected-items editing expects an ordered list of related post IDs.', 'dbvc'),
            ];
        }

        $normalized = $this->normalizeSubmittedIds($value);
        $max = $this->getReferenceMaxSelections($descriptor);
        $min = $this->getReferenceMinSelections($descriptor);

        if ($max > 0 && count($normalized) > $max) {
            return [
                'ok' => false,
                'message' => sprintf(
                    /* translators: %d: maximum selections */
                    __('You can select at most %d connected items for this field.', 'dbvc'),
                    $max
                ),
            ];
        }

        if ($min > 0 && count($normalized) < $min) {
            return [
                'ok' => false,
                'message' => sprintf(
                    /* translators: %d: minimum selections */
                    __('You must keep at least %d connected items for this field.', 'dbvc'),
                    $min
                ),
            ];
        }

        foreach ($normalized as $post_id) {
            if (! $this->postMatchesDescriptor($descriptor, $post_id)) {
                return [
                    'ok' => false,
                    'message' => __('One or more selected connected items are not valid for this field.', 'dbvc'),
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
     * @return mixed
     */
    public function sanitize(EditableDescriptor $descriptor, $value)
    {
        $ids = is_array($value) ? $this->normalizeSubmittedIds($value) : [];
        $field_type = $this->getFieldType($descriptor);
        $is_multiple = ! empty($descriptor->source['reference_multiple']);

        if ($this->isFilteredSubsetDescriptor($descriptor)) {
            return $ids;
        }

        if ($field_type === 'relationship' || $is_multiple) {
            return $ids;
        }

        return isset($ids[0]) ? $ids[0] : '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    public function save(EditableDescriptor $descriptor, $value)
    {
        $result = $this->writeCollectionValue($descriptor, $value);
        if (empty($result['ok'])) {
            return $result;
        }

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
        $search = sanitize_text_field((string) $search);
        $limit = max(1, min(50, absint($limit)));
        $post_types = $this->getAllowedPostTypes($descriptor);

        $query_args = [
            'post_type' => ! empty($post_types) ? $post_types : 'any',
            'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
            'posts_per_page' => $limit,
            'orderby' => $search !== '' ? 'title' : 'modified',
            'order' => $search !== '' ? 'ASC' : 'DESC',
            's' => $search,
            'fields' => 'ids',
            'suppress_filters' => false,
        ];

        $post_ids = get_posts($query_args);
        if (! is_array($post_ids) || empty($post_ids)) {
            return [];
        }

        $items = [];

        foreach ($post_ids as $post_id) {
            $post_id = absint($post_id);
            if ($post_id <= 0 || ! $this->postMatchesDescriptor($descriptor, $post_id)) {
                continue;
            }

            $item = $this->buildReferenceItem($post_id);
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
    private function getSelectedReferenceIds(EditableDescriptor $descriptor)
    {
        $raw_value = $this->getCollectionValue($descriptor);
        $ids = [];

        if (is_array($raw_value)) {
            foreach ($raw_value as $item) {
                $ids[] = $this->extractReferenceId($item);
            }
        } else {
            $ids[] = $this->extractReferenceId($raw_value);
        }

        $ids = array_values(
            array_filter(
                array_map('absint', $ids)
            )
        );

        if ($this->isFilteredSubsetDescriptor($descriptor)) {
            return $this->filterIdsByTargetPostType($descriptor, $ids);
        }

        return $ids;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return mixed
     */
    private function getCollectionValue(EditableDescriptor $descriptor)
    {
        $ancestry = $this->getContainerAncestry($descriptor);
        if (empty($ancestry)) {
            return $this->getRawAcfValue($descriptor);
        }

        $resolved = $this->loadTargetContainerRows($descriptor, $ancestry);
        if (empty($resolved['ok'])) {
            return '';
        }

        $rows = isset($resolved['rows']) && is_array($resolved['rows']) ? $resolved['rows'] : [];
        $container_type = $this->getContainerType($descriptor);

        if ($container_type === 'repeater') {
            return $this->readValueFromRepeaterRows($rows, $descriptor);
        }

        if ($container_type === 'flexible_content') {
            return $this->readValueFromFlexibleRows($rows, $descriptor);
        }

        return $this->getRawAcfValue($descriptor);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    private function writeCollectionValue(EditableDescriptor $descriptor, $value)
    {
        $ancestry = $this->getContainerAncestry($descriptor);
        if (empty($ancestry)) {
            if ($this->isFilteredSubsetDescriptor($descriptor)) {
                return $this->writeFilteredSubsetValue($descriptor, $value);
            }

            return $this->writeAcfValue($descriptor, $value);
        }

        $resolved = $this->loadTargetContainerRows($descriptor, $ancestry);
        if (empty($resolved['ok'])) {
            return [
                'ok' => false,
                'message' => isset($resolved['message']) ? (string) $resolved['message'] : __('The nested collection container could not be resolved safely.', 'dbvc'),
            ];
        }

        $rows = isset($resolved['rows']) && is_array($resolved['rows']) ? $resolved['rows'] : [];
        $container_type = $this->getContainerType($descriptor);
        $mutation = $container_type === 'flexible_content'
            ? $this->writeValueToFlexibleRows($rows, $descriptor, $value)
            : $this->writeValueToRepeaterRows($rows, $descriptor, $value);

        if (empty($mutation['ok'])) {
            return $mutation;
        }

        $updated_rows = isset($mutation['rows']) && is_array($mutation['rows']) ? $mutation['rows'] : [];
        $write_result = $this->persistTargetContainerRows($descriptor, $ancestry, $updated_rows);
        if (empty($write_result['ok'])) {
            return $write_result;
        }

        return [
            'ok' => true,
            'value' => $this->getValue($descriptor),
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $value
     * @return array<string, mixed>
     */
    private function writeFilteredSubsetValue(EditableDescriptor $descriptor, $value)
    {
        $target_post_type = $this->getQueryTargetPostType($descriptor);
        if ($target_post_type === '') {
            return [
                'ok' => false,
                'message' => __('The linked-post subset target post type is missing.', 'dbvc'),
            ];
        }

        $submitted_ids = is_array($value)
            ? $this->normalizeSubmittedIds($value)
            : $this->normalizeSubmittedIds([$value]);
        $latest_ids = $this->normalizeStoredReferenceIds($this->getRawAcfValue($descriptor));
        $latest_target_ids = $this->filterIdsByPostType($latest_ids, $target_post_type);
        $original_target_ids = $this->normalizeSourceIdList($descriptor, 'query_result_ids');

        if ($latest_target_ids !== $original_target_ids) {
            return [
                'ok' => false,
                'message' => __('The linked-post subset changed after this page loaded. Refresh and reopen the Visual Editor before saving.', 'dbvc'),
                'journal' => $this->buildFilteredSubsetJournalContext(
                    $descriptor,
                    $target_post_type,
                    $latest_ids,
                    $latest_ids,
                    $latest_target_ids,
                    $submitted_ids,
                    true
                ),
            ];
        }

        foreach ($submitted_ids as $post_id) {
            if (get_post_type($post_id) !== $target_post_type) {
                return [
                    'ok' => false,
                    'message' => __('The linked-post subset can only contain posts from the query loop target post type.', 'dbvc'),
                ];
            }
        }

        $merged_ids = $this->mergeFilteredSubsetIds($latest_ids, $submitted_ids, $target_post_type);
        $write_result = $this->writeAcfValue($descriptor, $merged_ids);
        if (empty($write_result['ok'])) {
            $write_result['journal'] = $this->buildFilteredSubsetJournalContext(
                $descriptor,
                $target_post_type,
                $latest_ids,
                $latest_ids,
                $latest_target_ids,
                $submitted_ids,
                false
            );

            return $write_result;
        }

        return [
            'ok' => true,
            'value' => $this->getValue($descriptor),
            'journal' => $this->buildFilteredSubsetJournalContext(
                $descriptor,
                $target_post_type,
                $latest_ids,
                $merged_ids,
                $latest_target_ids,
                $submitted_ids,
                false
            ),
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    private function getContainerType(EditableDescriptor $descriptor)
    {
        return isset($descriptor->source['container_type'])
            ? sanitize_key((string) $descriptor->source['container_type'])
            : '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<int, array<string, mixed>>
     */
    private function getContainerAncestry(EditableDescriptor $descriptor)
    {
        if (empty($descriptor->source['container_ancestry']) || ! is_array($descriptor->source['container_ancestry'])) {
            return [];
        }

        $segments = [];

        foreach ($descriptor->source['container_ancestry'] as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $type = isset($segment['type']) ? sanitize_key((string) $segment['type']) : '';
            $field_name = isset($segment['field_name']) ? sanitize_key((string) $segment['field_name']) : '';
            $field_key = isset($segment['field_key']) ? sanitize_key((string) $segment['field_key']) : '';
            $field_selector = isset($segment['field_selector']) ? sanitize_key((string) $segment['field_selector']) : '';
            $row_index = isset($segment['row_index']) && $segment['row_index'] !== null && is_numeric($segment['row_index'])
                ? absint($segment['row_index'])
                : null;

            if ($type === '' || $field_name === '' && $field_key === '' && $field_selector === '') {
                continue;
            }

            $segments[] = [
                'type' => $type,
                'field_name' => $field_name,
                'field_key' => $field_key,
                'field_selector' => $field_selector,
                'row_index' => $row_index,
                'layout_name' => isset($segment['layout_name']) ? sanitize_key((string) $segment['layout_name']) : '',
                'layout_key' => isset($segment['layout_key']) ? sanitize_key((string) $segment['layout_key']) : '',
            ];
        }

        return $segments;
    }

    /**
     * @param EditableDescriptor               $descriptor
     * @param array<int, array<string, mixed>> $ancestry
     * @return array<string, mixed>
     */
    private function loadTargetContainerRows(EditableDescriptor $descriptor, array $ancestry)
    {
        $root = $this->loadAncestorRootRows($descriptor, $ancestry);
        if (empty($root['ok'])) {
            return $root;
        }

        $root_rows = isset($root['rows']) && is_array($root['rows']) ? $root['rows'] : [];
        $container =& $root_rows;
        $segment_count = count($ancestry);

        for ($index = 0; $index < $segment_count; $index++) {
            $segment = $ancestry[$index];
            $selection = $this->selectSegmentRowReference($container, $segment);
            if (empty($selection['ok'])) {
                return $selection;
            }

            if ($index === $segment_count - 1) {
                $target_container = $this->resolveNestedFieldContainer($selection['row'], $descriptor);
                $target_key = $this->resolveNestedFieldKeyFromRow($target_container, $descriptor);
                if ($target_key === '') {
                    return [
                        'ok' => false,
                        'message' => __('The nested collection field could not be resolved from the parent row.', 'dbvc'),
                    ];
                }

                $target_rows = isset($target_container[$target_key]) && is_array($target_container[$target_key])
                    ? array_values($target_container[$target_key])
                    : [];

                return [
                    'ok' => true,
                    'rows' => $target_rows,
                ];
            }

            $next_segment = $ancestry[$index + 1];
            $next_key = $this->resolveContainerFieldKeyFromRow($selection['row'], $next_segment);
            if ($next_key === '') {
                return [
                    'ok' => false,
                    'message' => __('The nested collection ancestry could not be traversed safely.', 'dbvc'),
                ];
            }

            $container = isset($selection['row'][$next_key]) && is_array($selection['row'][$next_key])
                ? array_values($selection['row'][$next_key])
                : [];
        }

        return [
            'ok' => false,
            'message' => __('The nested collection ancestry did not expose a writable target container.', 'dbvc'),
        ];
    }

    /**
     * @param EditableDescriptor               $descriptor
     * @param array<int, array<string, mixed>> $ancestry
     * @param array<int, array<string, mixed>> $updated_rows
     * @return array<string, mixed>
     */
    private function persistTargetContainerRows(EditableDescriptor $descriptor, array $ancestry, array $updated_rows)
    {
        $root = $this->loadAncestorRootRows($descriptor, $ancestry);
        if (empty($root['ok'])) {
            return $root;
        }

        $root_rows = isset($root['rows']) && is_array($root['rows']) ? array_values($root['rows']) : [];
        $container =& $root_rows;
        $segment_count = count($ancestry);

        for ($index = 0; $index < $segment_count; $index++) {
            $segment = $ancestry[$index];
            $selection = $this->selectSegmentRowReference($container, $segment, true);
            if (empty($selection['ok'])) {
                return $selection;
            }

            if ($index === $segment_count - 1) {
                $target_container =& $this->resolveNestedFieldContainerReference($selection['row'], $descriptor);
                $target_key = $this->resolveNestedFieldKeyFromRow($target_container, $descriptor, true);
                if ($target_key === '') {
                    return [
                        'ok' => false,
                        'message' => __('The nested collection field could not be resolved for save.', 'dbvc'),
                    ];
                }

                $target_container[$target_key] = array_values($updated_rows);
                break;
            }

            $next_segment = $ancestry[$index + 1];
            $next_key = $this->resolveContainerFieldKeyFromRow($selection['row'], $next_segment, true);
            if ($next_key === '') {
                return [
                    'ok' => false,
                    'message' => __('The nested collection ancestry could not be traversed safely for save.', 'dbvc'),
                ];
            }

            if (! isset($selection['row'][$next_key]) || ! is_array($selection['row'][$next_key])) {
                $selection['row'][$next_key] = [];
            }

            $selection['row'][$next_key] = array_values($selection['row'][$next_key]);
            $container =& $selection['row'][$next_key];
        }

        return $this->persistAncestorRootRows($descriptor, $ancestry[0], $root_rows);
    }

    /**
     * @param EditableDescriptor               $descriptor
     * @param array<int, array<string, mixed>> $ancestry
     * @return array<string, mixed>
     */
    private function loadAncestorRootRows(EditableDescriptor $descriptor, array $ancestry)
    {
        if (empty($ancestry)) {
            return [
                'ok' => false,
                'message' => __('The nested collection ancestry is missing.', 'dbvc'),
            ];
        }

        $root_segment = $ancestry[0];
        $object_id = $this->getAcfObjectId($descriptor);
        $identifier = $this->resolveSegmentReadIdentifier($root_segment);

        if ($object_id === '' || $identifier === '' || ! function_exists('get_field')) {
            return [
                'ok' => false,
                'message' => __('The nested collection root field could not be loaded.', 'dbvc'),
            ];
        }

        $rows = get_field($identifier, $object_id, false);

        return [
            'ok' => true,
            'rows' => is_array($rows) ? array_values($rows) : [],
        ];
    }

    /**
     * @param EditableDescriptor         $descriptor
     * @param array<string, mixed>       $root_segment
     * @param array<int, array<string,mixed>> $rows
     * @return array<string, mixed>
     */
    private function persistAncestorRootRows(EditableDescriptor $descriptor, array $root_segment, array $rows)
    {
        $object_id = $this->getAcfObjectId($descriptor);
        $field_selector = isset($root_segment['field_selector']) ? sanitize_key((string) $root_segment['field_selector']) : '';
        $field_name = isset($root_segment['field_name']) ? sanitize_key((string) $root_segment['field_name']) : '';
        $field_key = isset($root_segment['field_key']) ? sanitize_key((string) $root_segment['field_key']) : '';

        if ($object_id === '' || ($field_selector === '' && $field_name === '' && $field_key === '') || ! function_exists('update_field')) {
            return [
                'ok' => false,
                'message' => __('The nested collection root field could not be saved.', 'dbvc'),
            ];
        }

        $result = false;

        if ($field_selector !== '') {
            $result = update_field($field_selector, $rows, $object_id);
        }

        if ($result === false && $field_name !== '') {
            $result = update_field($field_name, $rows, $object_id);
        }

        $next_value = $this->loadAncestorRootRows($descriptor, [$root_segment]);
        $next_rows = ! empty($next_value['ok']) && isset($next_value['rows']) && is_array($next_value['rows']) ? $next_value['rows'] : [];

        if ($result === false && $field_key !== '' && ! $this->valuesEqual($next_rows, array_values($rows))) {
            $result = update_field($field_key, $rows, $object_id);
            $next_value = $this->loadAncestorRootRows($descriptor, [$root_segment]);
            $next_rows = ! empty($next_value['ok']) && isset($next_value['rows']) && is_array($next_value['rows']) ? $next_value['rows'] : [];
        }

        if ($result === false && ! $this->valuesEqual($next_rows, array_values($rows))) {
            return [
                'ok' => false,
                'message' => __('The nested collection root field update did not succeed.', 'dbvc'),
            ];
        }

        return [
            'ok' => true,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $segment
     * @param bool                             $create
     * @return array<string, mixed>
     */
    private function selectSegmentRowReference(array &$rows, array $segment, $create = false)
    {
        $row_index = isset($segment['row_index']) && $segment['row_index'] !== null && is_numeric($segment['row_index'])
            ? absint($segment['row_index'])
            : null;

        if ($row_index === null) {
            return [
                'ok' => false,
                'message' => __('A nested collection row index is missing.', 'dbvc'),
            ];
        }

        if ($create && ! isset($rows[$row_index])) {
            $rows[$row_index] = [];
        }

        if (! isset($rows[$row_index]) || ! is_array($rows[$row_index])) {
            return [
                'ok' => false,
                'message' => __('A nested collection row could not be resolved safely.', 'dbvc'),
            ];
        }

        $row =& $rows[$row_index];
        $type = isset($segment['type']) ? sanitize_key((string) $segment['type']) : '';
        $layout_name = isset($segment['layout_name']) ? sanitize_key((string) $segment['layout_name']) : '';

        if ($type === 'flexible_content' && $layout_name !== '') {
            $current_layout = isset($row['acf_fc_layout']) ? sanitize_key((string) $row['acf_fc_layout']) : '';

            if ($current_layout === '' && $create) {
                $row['acf_fc_layout'] = $layout_name;
            } elseif ($current_layout !== '' && $current_layout !== $layout_name) {
                return [
                    'ok' => false,
                    'message' => __('A nested flexible layout did not match the expected row.', 'dbvc'),
                ];
            }
        }

        return [
            'ok' => true,
            'row' => &$row,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param EditableDescriptor   $descriptor
     * @return array<string, mixed>
     */
    private function resolveNestedFieldContainer(array $row, EditableDescriptor $descriptor)
    {
        $container = $row;
        $group_names = $this->getGroupPath($descriptor);
        $group_keys = $this->getGroupKeyPath($descriptor);
        $depth = max(count($group_names), count($group_keys));

        for ($index = 0; $index < $depth; $index++) {
            $group_name = isset($group_names[$index]) ? $group_names[$index] : '';
            $group_key = isset($group_keys[$index]) ? $group_keys[$index] : '';
            $segment = $this->resolveGroupSegmentKey($container, $group_name, $group_key);

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
    private function &resolveNestedFieldContainerReference(array &$row, EditableDescriptor $descriptor)
    {
        $container =& $row;
        $group_names = $this->getGroupPath($descriptor);
        $group_keys = $this->getGroupKeyPath($descriptor);
        $depth = max(count($group_names), count($group_keys));

        for ($index = 0; $index < $depth; $index++) {
            $group_name = isset($group_names[$index]) ? $group_names[$index] : '';
            $group_key = isset($group_keys[$index]) ? $group_keys[$index] : '';
            $segment = $this->resolveGroupSegmentKey($container, $group_name, $group_key);

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
    private function resolveGroupSegmentKey(array $container, $group_name, $group_key)
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
     * @param array<string, mixed> $row
     * @param array<string, mixed> $segment
     * @param bool                 $create
     * @return string
     */
    private function resolveContainerFieldKeyFromRow(array $row, array $segment, $create = false)
    {
        $candidates = array_values(
            array_filter(
                array_unique(
                    [
                        isset($segment['field_key']) ? sanitize_key((string) $segment['field_key']) : '',
                        isset($segment['field_name']) ? sanitize_key((string) $segment['field_name']) : '',
                        isset($segment['field_selector']) ? sanitize_key((string) $segment['field_selector']) : '',
                    ]
                )
            )
        );

        foreach ($candidates as $candidate) {
            if (isset($row[$candidate]) && is_array($row[$candidate])) {
                return $candidate;
            }
        }

        return $create && ! empty($candidates) ? $candidates[0] : '';
    }

    /**
     * @param array<string, mixed> $row
     * @param EditableDescriptor   $descriptor
     * @param bool                 $create
     * @return string
     */
    private function resolveNestedFieldKeyFromRow(array $row, EditableDescriptor $descriptor, $create = false)
    {
        $candidates = array_values(
            array_filter(
                array_unique(
                    [
                        isset($descriptor->source['field_key']) ? sanitize_key((string) $descriptor->source['field_key']) : '',
                        isset($descriptor->source['field_name']) ? sanitize_key((string) $descriptor->source['field_name']) : '',
                        isset($descriptor->source['field_selector']) ? sanitize_key((string) $descriptor->source['field_selector']) : '',
                        isset($descriptor->source['parent_field_key']) ? sanitize_key((string) $descriptor->source['parent_field_key']) : '',
                        isset($descriptor->source['parent_field_name']) ? sanitize_key((string) $descriptor->source['parent_field_name']) : '',
                        isset($descriptor->source['parent_field_selector']) ? sanitize_key((string) $descriptor->source['parent_field_selector']) : '',
                    ]
                )
            )
        );

        foreach ($candidates as $candidate) {
            if (isset($row[$candidate]) && is_array($row[$candidate])) {
                return $candidate;
            }
        }

        return $create && ! empty($candidates) ? $candidates[0] : '';
    }

    /**
     * @param array<string, mixed> $segment
     * @return string
     */
    private function resolveSegmentReadIdentifier(array $segment)
    {
        $field_selector = isset($segment['field_selector']) ? sanitize_key((string) $segment['field_selector']) : '';
        if ($field_selector !== '') {
            return $field_selector;
        }

        $field_name = isset($segment['field_name']) ? sanitize_key((string) $segment['field_name']) : '';
        if ($field_name !== '') {
            return $field_name;
        }

        return isset($segment['field_key']) ? sanitize_key((string) $segment['field_key']) : '';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    private function isFilteredSubsetDescriptor(EditableDescriptor $descriptor)
    {
        return isset($descriptor->source['query_source'], $descriptor->source['query_subset_write_mode'])
            && sanitize_key((string) $descriptor->source['query_source']) === 'derived_bricks_query'
            && sanitize_key((string) $descriptor->source['query_subset_write_mode']) === 'replace_target_post_type_subset';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    private function getQueryTargetPostType(EditableDescriptor $descriptor)
    {
        return isset($descriptor->source['query_target_post_type'])
            ? sanitize_key((string) $descriptor->source['query_target_post_type'])
            : '';
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function normalizeStoredReferenceIds($value)
    {
        $values = is_array($value) ? $value : [$value];
        $ids = [];

        foreach ($values as $item) {
            $id = $this->extractReferenceId($item);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param string             $key
     * @return array<int, int>
     */
    private function normalizeSourceIdList(EditableDescriptor $descriptor, $key)
    {
        $values = isset($descriptor->source[$key]) && is_array($descriptor->source[$key])
            ? $descriptor->source[$key]
            : [];

        return array_values(array_filter(array_map('absint', $values)));
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param array<int, int>    $ids
     * @return array<int, int>
     */
    private function filterIdsByTargetPostType(EditableDescriptor $descriptor, array $ids)
    {
        return $this->filterIdsByPostType($ids, $this->getQueryTargetPostType($descriptor));
    }

    /**
     * @param array<int, int> $ids
     * @param string          $post_type
     * @return array<int, int>
     */
    private function filterIdsByPostType(array $ids, $post_type)
    {
        $post_type = sanitize_key((string) $post_type);
        if ($post_type === '') {
            return [];
        }

        $filtered = [];
        foreach ($ids as $id) {
            $id = absint($id);
            if ($id > 0 && get_post_type($id) === $post_type) {
                $filtered[] = $id;
            }
        }

        return array_values($filtered);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param string             $target_post_type
     * @param array<int, int>    $full_before_ids
     * @param array<int, int>    $full_after_ids
     * @param array<int, int>    $target_before_ids
     * @param array<int, int>    $target_after_ids
     * @param bool               $stale_conflict
     * @return array<string, mixed>
     */
    private function buildFilteredSubsetJournalContext(EditableDescriptor $descriptor, $target_post_type, array $full_before_ids, array $full_after_ids, array $target_before_ids, array $target_after_ids, $stale_conflict = false)
    {
        return [
            'contract' => 'filtered_subset',
            'targetPostType' => sanitize_key((string) $target_post_type),
            'fieldName' => isset($descriptor->source['field_name']) ? sanitize_key((string) $descriptor->source['field_name']) : '',
            'fieldKey' => isset($descriptor->source['field_key']) ? sanitize_key((string) $descriptor->source['field_key']) : '',
            'queryElementId' => isset($descriptor->source['query_element_id']) ? sanitize_text_field((string) $descriptor->source['query_element_id']) : '',
            'descriptorTargetIds' => $this->normalizeSourceIdList($descriptor, 'query_result_ids'),
            'fullBeforeIds' => array_values(array_filter(array_map('absint', $full_before_ids))),
            'fullAfterIds' => array_values(array_filter(array_map('absint', $full_after_ids))),
            'targetBeforeIds' => array_values(array_filter(array_map('absint', $target_before_ids))),
            'targetAfterIds' => array_values(array_filter(array_map('absint', $target_after_ids))),
            'preservedBeforeIds' => $this->filterIdsNotByPostType($full_before_ids, $target_post_type),
            'preservedAfterIds' => $this->filterIdsNotByPostType($full_after_ids, $target_post_type),
            'staleConflict' => (bool) $stale_conflict,
        ];
    }

    /**
     * @param array<int, int> $ids
     * @param string          $post_type
     * @return array<int, int>
     */
    private function filterIdsNotByPostType(array $ids, $post_type)
    {
        $post_type = sanitize_key((string) $post_type);
        $filtered = [];

        foreach ($ids as $id) {
            $id = absint($id);
            if ($id > 0 && ($post_type === '' || get_post_type($id) !== $post_type)) {
                $filtered[] = $id;
            }
        }

        return array_values($filtered);
    }

    /**
     * @param array<int, int> $current_ids
     * @param array<int, int> $submitted_ids
     * @param string          $target_post_type
     * @return array<int, int>
     */
    private function mergeFilteredSubsetIds(array $current_ids, array $submitted_ids, $target_post_type)
    {
        $merged = [];
        $inserted = false;

        foreach ($current_ids as $id) {
            $id = absint($id);
            if ($id <= 0) {
                continue;
            }

            if (get_post_type($id) === $target_post_type) {
                if (! $inserted) {
                    $merged = array_merge($merged, $submitted_ids);
                    $inserted = true;
                }

                continue;
            }

            $merged[] = $id;
        }

        if (! $inserted) {
            $merged = array_merge($merged, $submitted_ids);
        }

        return array_values($merged);
    }

    /**
     * @param mixed $value
     * @return int
     */
    private function extractReferenceId($value)
    {
        if (is_object($value) && isset($value->ID)) {
            return absint($value->ID);
        }

        if (is_array($value) && isset($value['ID'])) {
            return absint($value['ID']);
        }

        return is_numeric($value) ? absint($value) : 0;
    }

    /**
     * @param array<int, mixed> $value
     * @return array<int, int>
     */
    private function normalizeSubmittedIds(array $value)
    {
        $ids = [];

        foreach ($value as $item) {
            $post_id = is_array($item) && isset($item['id'])
                ? absint($item['id'])
                : absint($item);

            if ($post_id > 0 && ! in_array($post_id, $ids, true)) {
                $ids[] = $post_id;
            }
        }

        return $ids;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<int, string>
     */
    private function getAllowedPostTypes(EditableDescriptor $descriptor)
    {
        if ($this->isFilteredSubsetDescriptor($descriptor)) {
            $target_post_type = $this->getQueryTargetPostType($descriptor);

            return $target_post_type !== '' ? [$target_post_type] : [];
        }

        $post_types = isset($descriptor->source['reference_post_types']) && is_array($descriptor->source['reference_post_types'])
            ? array_values(array_filter(array_map('sanitize_key', $descriptor->source['reference_post_types'])))
            : [];

        if (! empty($post_types)) {
            return $post_types;
        }

        return array_values(
            array_filter(
                get_post_types(
                    [
                        'public' => true,
                    ],
                    'names'
                )
            )
        );
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param int                $post_id
     * @return bool
     */
    private function postMatchesDescriptor(EditableDescriptor $descriptor, $post_id)
    {
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post) {
            return false;
        }

        $allowed_post_types = $this->getAllowedPostTypes($descriptor);
        if (! empty($allowed_post_types) && ! in_array((string) $post->post_type, $allowed_post_types, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param int $post_id
     * @return array<string, mixed>
     */
    private function buildReferenceItem($post_id)
    {
        $post_id = absint($post_id);
        if ($post_id <= 0) {
            return [];
        }

        $post = get_post($post_id);
        if (! $post instanceof \WP_Post) {
            return [];
        }

        $post_type = sanitize_key((string) $post->post_type);
        $post_type_object = get_post_type_object($post_type);
        $type_label = $post_type_object && ! empty($post_type_object->labels->singular_name)
            ? sanitize_text_field((string) $post_type_object->labels->singular_name)
            : __('Post', 'dbvc');
        $title = get_the_title($post_id);

        return [
            'id' => $post_id,
            'title' => is_string($title) && trim($title) !== ''
                ? sanitize_text_field($title)
                : sprintf(
                    /* translators: 1: post type label, 2: post ID */
                    __('%1$s #%2$d', 'dbvc'),
                    $type_label,
                    $post_id
                ),
            'postType' => $post_type,
            'typeLabel' => $type_label,
            'status' => sanitize_key((string) $post->post_status),
            'frontendUrl' => ($url = get_permalink($post_id)) && is_string($url) ? esc_url_raw($url) : '',
            'backendUrl' => ($edit_url = get_edit_post_link($post_id, '')) && is_string($edit_url) ? esc_url_raw($edit_url) : '',
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return int
     */
    private function getReferenceMaxSelections(EditableDescriptor $descriptor)
    {
        if (isset($descriptor->source['reference_max']) && is_numeric($descriptor->source['reference_max'])) {
            return max(0, absint($descriptor->source['reference_max']));
        }

        if (! empty($descriptor->source['reference_multiple'])) {
            return 0;
        }

        return 1;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return int
     */
    private function getReferenceMinSelections(EditableDescriptor $descriptor)
    {
        return isset($descriptor->source['reference_min']) && is_numeric($descriptor->source['reference_min'])
            ? max(0, absint($descriptor->source['reference_min']))
            : 0;
    }
}
