<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Target_Transform_Service
{
    /**
     * @var DBVC_CC_V2_Target_Transform_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Target_Transform_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $classification_artifact
     * @param array<string, mixed> $mapping_index_artifact
     * @param array<string, mixed> $media_candidates_artifact
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function build_artifact(
        array $page_context,
        array $classification_artifact,
        array $mapping_index_artifact,
        array $media_candidates_artifact,
        array $args = []
    ) {
        $domain = isset($page_context['domain']) ? sanitize_text_field((string) $page_context['domain']) : '';
        $raw_artifact = isset($page_context['raw_artifact']) && is_array($page_context['raw_artifact']) ? $page_context['raw_artifact'] : [];
        $primary = isset($classification_artifact['primary_classification']) && is_array($classification_artifact['primary_classification'])
            ? $classification_artifact['primary_classification']
            : [];
        $resolution_preview = $this->build_resolution_preview($page_context, $classification_artifact, $mapping_index_artifact);
        $slot_graph = $this->load_slot_graph($domain);
        $slot_lookup = isset($slot_graph['slot_graph']['slots']) && is_array($slot_graph['slot_graph']['slots'])
            ? $slot_graph['slot_graph']['slots']
            : [];
        $assignment = DBVC_CC_V2_Mapping_Assignment_Service::get_instance()->assign_content_items(
            $domain,
            $mapping_index_artifact
        );

        $transform_items = [];
        $warning_count = 0;
        $blocked_count = 0;

        $content_items = isset($assignment['selected_items']) && is_array($assignment['selected_items'])
            ? $assignment['selected_items']
            : [];
        foreach ($content_items as $content_item) {
            if (! is_array($content_item)) {
                continue;
            }

            $candidate = isset($content_item['selected_candidate']) && is_array($content_item['selected_candidate'])
                ? $content_item['selected_candidate']
                : [];
            if (empty($candidate['target_ref'])) {
                continue;
            }

            $preview_value = isset($content_item['value_preview']) ? (string) $content_item['value_preview'] : '';
            $slot = $this->resolve_slot_projection($slot_lookup, (string) $candidate['target_ref']);
            $output_shape = $this->determine_output_shape((string) $candidate['target_ref'], $slot);
            $warnings = [];
            if ($preview_value === '') {
                $warnings[] = 'empty_preview_value';
            }

            $selection = isset($content_item['selection']) && is_array($content_item['selection']) ? $content_item['selection'] : [];
            if (! empty($selection['default_decision']) && (string) $selection['default_decision'] === 'unresolved') {
                $warnings[] = 'ambiguous_target_selection';
            }

            $value_contract_validation = $this->validate_value_contract(
                (string) $candidate['target_ref'],
                $slot,
                'copy_text',
                $preview_value
            );
            $warnings = array_values(
                array_unique(
                    array_merge(
                        $warnings,
                        isset($value_contract_validation['warning_codes']) && is_array($value_contract_validation['warning_codes'])
                            ? $value_contract_validation['warning_codes']
                            : [],
                        isset($value_contract_validation['blocking_issue_codes']) && is_array($value_contract_validation['blocking_issue_codes'])
                            ? $value_contract_validation['blocking_issue_codes']
                            : []
                    )
                )
            );
            $transform_status = $this->resolve_transform_status($warnings, $value_contract_validation);
            if ($transform_status === 'blocked') {
                ++$blocked_count;
            }
            $warning_count += count($warnings);

            $transform_items[] = [
                'source_refs' => isset($content_item['source_refs']) && is_array($content_item['source_refs']) ? array_values($content_item['source_refs']) : [],
                'target_ref' => (string) $candidate['target_ref'],
                'transform_type' => $this->determine_transform_type((string) $candidate['target_ref']),
                'transform_status' => $transform_status,
                'output_shape' => $output_shape,
                'preview_value' => $preview_value,
                'selection' => $selection,
                'warnings' => $warnings,
                'value_contract' => $this->build_value_contract_summary($slot),
                'value_contract_validation' => $value_contract_validation,
            ];
        }

        $media_items = isset($media_candidates_artifact['media_items']) && is_array($media_candidates_artifact['media_items'])
            ? $media_candidates_artifact['media_items']
            : [];
        foreach ($media_items as $media_item) {
            if (! is_array($media_item)) {
                continue;
            }

            $candidate = isset($media_item['target_candidates'][0]) && is_array($media_item['target_candidates'][0])
                ? $media_item['target_candidates'][0]
                : [];
            if (empty($candidate['target_ref'])) {
                continue;
            }

            $slot = $this->resolve_slot_projection($slot_lookup, (string) $candidate['target_ref']);
            $preview_value = isset($media_item['normalized_url']) ? (string) $media_item['normalized_url'] : '';
            $output_shape = $this->determine_output_shape((string) $candidate['target_ref'], $slot);
            $value_contract_validation = $this->validate_value_contract(
                (string) $candidate['target_ref'],
                $slot,
                'media_reference',
                $preview_value
            );
            $warnings = array_values(
                array_unique(
                    array_merge(
                        isset($value_contract_validation['warning_codes']) && is_array($value_contract_validation['warning_codes'])
                            ? $value_contract_validation['warning_codes']
                            : [],
                        isset($value_contract_validation['blocking_issue_codes']) && is_array($value_contract_validation['blocking_issue_codes'])
                            ? $value_contract_validation['blocking_issue_codes']
                            : []
                    )
                )
            );
            $transform_status = $this->resolve_transform_status($warnings, $value_contract_validation);
            if ($transform_status === 'blocked') {
                ++$blocked_count;
            }
            $warning_count += count($warnings);

            $transform_items[] = [
                'source_refs' => isset($media_item['source_refs']) && is_array($media_item['source_refs']) ? array_values($media_item['source_refs']) : [],
                'target_ref' => (string) $candidate['target_ref'],
                'transform_type' => 'media_reference',
                'transform_status' => $transform_status,
                'output_shape' => $output_shape,
                'preview_value' => $preview_value,
                'warnings' => $warnings,
                'value_contract' => $this->build_value_contract_summary($slot),
                'value_contract_validation' => $value_contract_validation,
            ];
        }

        $taxonomy_hints = isset($classification_artifact['taxonomy_hints']) && is_array($classification_artifact['taxonomy_hints'])
            ? $classification_artifact['taxonomy_hints']
            : [];
        foreach ($taxonomy_hints as $taxonomy_hint) {
            if (! is_array($taxonomy_hint) || empty($taxonomy_hint['taxonomy_key'])) {
                continue;
            }

            $terms_preview = $this->build_taxonomy_preview_terms($page_context);
            $warnings = empty($terms_preview) ? ['no_taxonomy_terms_inferred'] : [];
            $warning_count += count($warnings);

            $transform_items[] = [
                'source_refs' => ['initial-classification.v1#taxonomy_hints'],
                'target_ref' => 'taxonomy:' . sanitize_key((string) $taxonomy_hint['taxonomy_key']),
                'transform_type' => 'taxonomy_terms',
                'transform_status' => empty($warnings) ? 'ready' : 'completed_with_warnings',
                'output_shape' => 'term_set',
                'preview_value' => $terms_preview,
                'warnings' => $warnings,
            ];
        }

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'target-transform.v1',
            'journey_id' => isset($raw_artifact['journey_id']) ? (string) $raw_artifact['journey_id'] : '',
            'page_id' => isset($raw_artifact['page_id']) ? (string) $raw_artifact['page_id'] : '',
            'source_url' => isset($raw_artifact['source_url']) ? (string) $raw_artifact['source_url'] : '',
            'generated_at' => current_time('c'),
            'classification_ref' => isset($args['classification_ref']) ? (string) $args['classification_ref'] : '',
            'transform_items' => $transform_items,
            'resolution_preview' => $resolution_preview,
            'trace' => [
                'input_artifacts' => isset($args['input_artifacts']) && is_array($args['input_artifacts']) ? array_values($args['input_artifacts']) : [],
                'primary_object_key' => isset($primary['object_key']) ? (string) $primary['object_key'] : '',
                'assignment_unresolved_count' => isset($assignment['unresolved_items']) && is_array($assignment['unresolved_items'])
                    ? count($assignment['unresolved_items'])
                    : 0,
                'slot_graph_status' => isset($slot_graph['status']) ? (string) $slot_graph['status'] : '',
                'slot_graph_ref' => isset($slot_graph['artifact_relative_path']) ? (string) $slot_graph['artifact_relative_path'] : '',
                'slot_graph_fingerprint' => isset($slot_graph['slot_graph_fingerprint']) ? (string) $slot_graph['slot_graph_fingerprint'] : '',
            ],
            'stats' => [
                'transform_item_count' => count($transform_items),
                'ready_count' => count(
                    array_filter(
                        $transform_items,
                        static function ($item) {
                            return is_array($item) && isset($item['transform_status']) && (string) $item['transform_status'] === 'ready';
                        }
                    )
                ),
                'blocked_count' => $blocked_count,
                'warning_count' => $warning_count,
                'resolution_mode' => isset($resolution_preview['mode']) ? (string) $resolution_preview['mode'] : '',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $classification_artifact
     * @param array<string, mixed> $mapping_index_artifact
     * @return array<string, mixed>
     */
    private function build_resolution_preview(array $page_context, array $classification_artifact, array $mapping_index_artifact)
    {
        $primary = isset($classification_artifact['primary_classification']) && is_array($classification_artifact['primary_classification'])
            ? $classification_artifact['primary_classification']
            : [];
        $object_key = isset($primary['object_key']) ? sanitize_key((string) $primary['object_key']) : '';
        $review = isset($classification_artifact['review']) && is_array($classification_artifact['review']) ? $classification_artifact['review'] : [];
        $review_status = isset($review['status']) ? sanitize_key((string) $review['status']) : '';
        if ($object_key === '' || $review_status === 'blocked') {
            return [
                'mode' => DBVC_CC_V2_Contracts::RESOLUTION_MODE_BLOCKED,
                'reason' => 'classification_blocked',
                'target' => [],
                'ambiguous' => false,
            ];
        }

        $row = isset($page_context['row']) && is_array($page_context['row']) ? $page_context['row'] : [];
        $path = isset($row['path']) ? (string) $row['path'] : '';
        $slug = isset($row['slug']) ? sanitize_title((string) $row['slug']) : sanitize_title((string) $path);

        if ($path === '/' && $object_key === 'page') {
            $front_page_id = (int) get_option('page_on_front');
            if ($front_page_id > 0) {
                return [
                    'mode' => DBVC_CC_V2_Contracts::RESOLUTION_MODE_UPDATE_EXISTING,
                    'reason' => 'front_page_match',
                    'target' => [
                        'object_id' => $front_page_id,
                        'object_key' => $object_key,
                    ],
                    'ambiguous' => false,
                ];
            }
        }

        if ($slug !== '') {
            $existing = get_page_by_path($slug, OBJECT, $object_key);
            if ($existing instanceof WP_Post) {
                return [
                    'mode' => DBVC_CC_V2_Contracts::RESOLUTION_MODE_UPDATE_EXISTING,
                    'reason' => 'slug_match',
                    'target' => [
                        'object_id' => (int) $existing->ID,
                        'object_key' => $object_key,
                    ],
                    'ambiguous' => false,
                ];
            }
        }

        $content_items = isset($mapping_index_artifact['content_items']) && is_array($mapping_index_artifact['content_items'])
            ? $mapping_index_artifact['content_items']
            : [];
        $has_required_content = ! empty(
            array_filter(
                $content_items,
                static function ($item) {
                    return is_array($item) && ! empty($item['value_preview']);
                }
            )
        );

        return [
            'mode' => $has_required_content ? DBVC_CC_V2_Contracts::RESOLUTION_MODE_CREATE_NEW : DBVC_CC_V2_Contracts::RESOLUTION_MODE_BLOCKED,
            'reason' => $has_required_content ? 'create_inputs_present' : 'missing_required_create_inputs',
            'target' => [
                'object_key' => $object_key,
            ],
            'ambiguous' => false,
        ];
    }

    /**
     * @param string $target_ref
     * @return string
     */
    private function determine_transform_type($target_ref)
    {
        if (strpos($target_ref, 'taxonomy:') === 0) {
            return 'taxonomy_terms';
        }

        if ($target_ref === 'core:featured_image') {
            return 'media_reference';
        }

        return 'copy_text';
    }

    /**
     * @param string $target_ref
     * @return string
     */
    private function determine_output_shape($target_ref, array $slot = [])
    {
        if ($target_ref === 'core:post_content') {
            return 'rich_text';
        }

        if ($target_ref === 'core:featured_image') {
            return 'attachment_reference';
        }

        if (strpos($target_ref, 'taxonomy:') === 0) {
            return 'term_set';
        }

        if (strpos($target_ref, 'acf:') === 0 && ! empty($slot)) {
            $field_type = isset($slot['value_contract']['field_type'])
                ? sanitize_key((string) $slot['value_contract']['field_type'])
                : (isset($slot['type']) ? sanitize_key((string) $slot['type']) : '');

            switch ($field_type) {
                case 'wysiwyg':
                    return 'rich_text';
                case 'url':
                    return 'url';
                case 'image':
                case 'file':
                    return 'attachment_reference';
                case 'gallery':
                    return 'attachment_reference_set';
                case 'taxonomy':
                    return 'term_set';
                case 'relationship':
                case 'post_object':
                case 'page_link':
                case 'user':
                    return 'entity_reference';
                case 'link':
                    return 'link_object';
            }
        }

        return 'scalar_string';
    }

    /**
     * @param string $domain
     * @return array<string, mixed>
     */
    private function load_slot_graph($domain)
    {
        if ($domain === '') {
            return [];
        }

        $slot_graph = DBVC_CC_V2_Target_Slot_Graph_Service::get_instance()->get_graph($domain, true);
        if (is_wp_error($slot_graph) || ! is_array($slot_graph)) {
            return [];
        }

        return $slot_graph;
    }

    /**
     * @param array<string, array<string, mixed>> $slot_lookup
     * @param string                              $target_ref
     * @return array<string, mixed>
     */
    private function resolve_slot_projection(array $slot_lookup, $target_ref)
    {
        $target_ref = (string) $target_ref;
        if ($target_ref === '' || strpos($target_ref, 'acf:') !== 0 || ! isset($slot_lookup[$target_ref]) || ! is_array($slot_lookup[$target_ref])) {
            return [];
        }

        return $slot_lookup[$target_ref];
    }

    /**
     * @param array<string, mixed> $slot
     * @return array<string, mixed>
     */
    private function build_value_contract_summary(array $slot)
    {
        if (empty($slot)) {
            return [];
        }

        $contract = isset($slot['value_contract']) && is_array($slot['value_contract']) ? $slot['value_contract'] : [];

        return [
            'field_type' => isset($contract['field_type']) ? sanitize_key((string) $contract['field_type']) : (isset($slot['type']) ? sanitize_key((string) $slot['type']) : ''),
            'writable' => ! empty($slot['writable']),
            'return_format' => isset($contract['return_format']) ? sanitize_key((string) $contract['return_format']) : '',
            'section_family' => isset($slot['section_family']) ? sanitize_key((string) $slot['section_family']) : '',
            'slot_role' => isset($slot['slot_role']) ? sanitize_key((string) $slot['slot_role']) : '',
            'target_ref' => isset($slot['target_ref']) ? sanitize_text_field((string) $slot['target_ref']) : '',
        ];
    }

    /**
     * @param string               $target_ref
     * @param array<string, mixed> $slot
     * @param string               $transform_type
     * @param string               $preview_value
     * @return array<string, mixed>
     */
    private function validate_value_contract($target_ref, array $slot, $transform_type, $preview_value)
    {
        $validation = [
            'status' => 'valid',
            'field_type' => '',
            'warning_codes' => [],
            'blocking_issue_codes' => [],
            'expected_output_shape' => $this->determine_output_shape($target_ref, $slot),
        ];

        if (strpos((string) $target_ref, 'acf:') !== 0) {
            return $validation;
        }

        if (empty($slot)) {
            $validation['status'] = 'warning';
            $validation['warning_codes'][] = 'value_contract_missing';
            return $validation;
        }

        $contract = isset($slot['value_contract']) && is_array($slot['value_contract']) ? $slot['value_contract'] : [];
        $field_type = isset($contract['field_type'])
            ? sanitize_key((string) $contract['field_type'])
            : (isset($slot['type']) ? sanitize_key((string) $slot['type']) : '');
        $validation['field_type'] = $field_type;

        if (empty($slot['writable']) || (array_key_exists('writable', $contract) && empty($contract['writable']))) {
            $validation['blocking_issue_codes'][] = 'value_contract_not_writable';
        }

        $clone_context = isset($slot['clone_context']) && is_array($slot['clone_context']) ? $slot['clone_context'] : [];
        if (! empty($clone_context['is_clone_projected']) && empty($clone_context['is_directly_writable'])) {
            $validation['blocking_issue_codes'][] = 'value_contract_clone_projected';
        }

        if ($field_type === 'url' && ! $this->is_valid_url_preview($preview_value)) {
            $validation['blocking_issue_codes'][] = 'value_contract_invalid_url';
        }

        if ($this->requires_media_reference($field_type) && $transform_type !== 'media_reference') {
            $validation['blocking_issue_codes'][] = 'value_contract_reference_mismatch';
        }

        if ($this->requires_structured_reference($field_type) && $transform_type === 'copy_text') {
            $validation['blocking_issue_codes'][] = 'value_contract_reference_mismatch';
        }

        $validation['warning_codes'] = array_values(array_unique(array_map('sanitize_key', $validation['warning_codes'])));
        $validation['blocking_issue_codes'] = array_values(array_unique(array_map('sanitize_key', $validation['blocking_issue_codes'])));
        if (! empty($validation['blocking_issue_codes'])) {
            $validation['status'] = 'blocked';
        } elseif (! empty($validation['warning_codes'])) {
            $validation['status'] = 'warning';
        }

        return $validation;
    }

    /**
     * @param array<int, string>    $warnings
     * @param array<string, mixed>  $value_contract_validation
     * @return string
     */
    private function resolve_transform_status(array $warnings, array $value_contract_validation)
    {
        $status = isset($value_contract_validation['status']) ? sanitize_key((string) $value_contract_validation['status']) : '';
        if ($status === 'blocked') {
            return 'blocked';
        }

        return empty($warnings) ? 'ready' : 'completed_with_warnings';
    }

    /**
     * @param string $field_type
     * @return bool
     */
    private function requires_media_reference($field_type)
    {
        return in_array(sanitize_key((string) $field_type), ['image', 'file', 'gallery'], true);
    }

    /**
     * @param string $field_type
     * @return bool
     */
    private function requires_structured_reference($field_type)
    {
        return in_array(sanitize_key((string) $field_type), ['relationship', 'post_object', 'page_link', 'user', 'taxonomy', 'link'], true);
    }

    /**
     * @param string $preview_value
     * @return bool
     */
    private function is_valid_url_preview($preview_value)
    {
        $preview_value = trim((string) $preview_value);
        if ($preview_value === '') {
            return false;
        }

        if (filter_var($preview_value, FILTER_VALIDATE_URL)) {
            return true;
        }

        if (preg_match('~^(\/|\#|\?|mailto:|tel:)~i', $preview_value) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $page_context
     * @return array<int, string>
     */
    private function build_taxonomy_preview_terms(array $page_context)
    {
        $raw_artifact = isset($page_context['raw_artifact']) && is_array($page_context['raw_artifact']) ? $page_context['raw_artifact'] : [];
        $title = isset($raw_artifact['metadata']['title']) ? (string) $raw_artifact['metadata']['title'] : '';
        $tokens = preg_split('/[^a-z0-9]+/i', strtolower($title)) ?: [];
        $tokens = array_values(array_filter($tokens, static function ($token) {
            return strlen((string) $token) >= 4;
        }));

        return array_slice($tokens, 0, 3);
    }
}
