<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Recommendation_Finalizer_Service
{
    /**
     * @var DBVC_CC_V2_Recommendation_Finalizer_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Recommendation_Finalizer_Service
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
     * @param array<string, mixed> $target_transform_artifact
     * @param array<string, mixed> $media_candidates_artifact
     * @param array<string, mixed> $pattern_memory
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function build_artifact(
        array $page_context,
        array $classification_artifact,
        array $mapping_index_artifact,
        array $target_transform_artifact,
        array $media_candidates_artifact,
        array $pattern_memory = [],
        array $args = []
    ) {
        $raw_artifact = isset($page_context['raw_artifact']) && is_array($page_context['raw_artifact']) ? $page_context['raw_artifact'] : [];
        $primary = isset($classification_artifact['primary_classification']) && is_array($classification_artifact['primary_classification'])
            ? $classification_artifact['primary_classification']
            : [];
        $alternates = isset($classification_artifact['alternate_classifications']) && is_array($classification_artifact['alternate_classifications'])
            ? $classification_artifact['alternate_classifications']
            : [];
        $resolution_preview = isset($target_transform_artifact['resolution_preview']) && is_array($target_transform_artifact['resolution_preview'])
            ? $target_transform_artifact['resolution_preview']
            : [];
        $transform_lookup = $this->build_transform_lookup($target_transform_artifact);
        $assignment = DBVC_CC_V2_Mapping_Assignment_Service::get_instance()->assign_content_items(
            isset($page_context['domain']) ? (string) $page_context['domain'] : '',
            $mapping_index_artifact
        );

        $recommendations = $this->build_recommendations($assignment, $transform_lookup);
        $target_entities = $this->build_target_entities($page_context, $mapping_index_artifact, $recommendations);
        $media_recommendations = $this->build_media_recommendations($media_candidates_artifact, $transform_lookup);
        $unresolved_items = $this->build_unresolved_items($assignment, $media_candidates_artifact);
        $conflicts = $this->build_conflicts($recommendations, $media_recommendations);
        $recommendations = $this->escalate_contested_record_fields($recommendations, $conflicts);
        $review = $this->build_review($primary, $resolution_preview, $recommendations, $media_recommendations, $unresolved_items, $conflicts);

        $artifact = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'mapping-recommendations.v2',
            'journey_id' => isset($raw_artifact['journey_id']) ? (string) $raw_artifact['journey_id'] : '',
            'page_id' => isset($raw_artifact['page_id']) ? (string) $raw_artifact['page_id'] : '',
            'source_url' => isset($raw_artifact['source_url']) ? (string) $raw_artifact['source_url'] : '',
            'generated_at' => current_time('c'),
            'field_context_provider' => isset($mapping_index_artifact['field_context_provider']) && is_array($mapping_index_artifact['field_context_provider'])
                ? $mapping_index_artifact['field_context_provider']
                : [],
            'object_type_context_provider' => isset($mapping_index_artifact['object_type_context_provider']) && is_array($mapping_index_artifact['object_type_context_provider'])
                ? $mapping_index_artifact['object_type_context_provider']
                : [],
            'classification' => [
                'primary' => $primary,
                'alternates' => array_values($alternates),
                'taxonomy_hints' => isset($classification_artifact['taxonomy_hints']) && is_array($classification_artifact['taxonomy_hints'])
                    ? array_values($classification_artifact['taxonomy_hints'])
                    : [],
            ],
            'recommended_target_object' => [
                'target_family' => isset($primary['type_family']) ? (string) $primary['type_family'] : '',
                'target_object_key' => isset($primary['object_key']) ? (string) $primary['object_key'] : '',
                'label' => isset($primary['label']) ? (string) $primary['label'] : '',
                'confidence' => isset($primary['confidence']) ? (float) $primary['confidence'] : 0.0,
                'resolution_mode' => isset($resolution_preview['mode']) ? (string) $resolution_preview['mode'] : '',
                'resolution_reason' => isset($resolution_preview['reason']) ? (string) $resolution_preview['reason'] : '',
                'target' => isset($resolution_preview['target']) && is_array($resolution_preview['target']) ? $resolution_preview['target'] : [],
            ],
            'candidate_target_objects' => $this->build_candidate_target_objects($primary, $alternates),
        ];

        // D-19: the records this one URL becomes, when it is more than the page itself. Absent
        // for a single-record page, so that artifact is byte-for-byte the one V2 always wrote and
        // every reader falls back to `recommended_target_object` as a list of one
        // (`DBVC_CC_V2_Contracts::get_target_entities()`).
        if ($target_entities !== []) {
            $artifact['target_entities'] = $target_entities;
        }

        return $artifact + [
            'recommendations' => $recommendations,
            'media_recommendations' => $media_recommendations,
            'unresolved_items' => $unresolved_items,
            'conflicts' => $conflicts,
            'review' => $review,
            'trace' => [
                'input_artifacts' => isset($args['input_artifacts']) && is_array($args['input_artifacts']) ? array_values($args['input_artifacts']) : [],
                'pattern_memory_ref' => isset($args['pattern_memory_ref']) ? (string) $args['pattern_memory_ref'] : '',
                'pattern_group_count' => isset($pattern_memory['pattern_groups']) && is_array($pattern_memory['pattern_groups']) ? count($pattern_memory['pattern_groups']) : 0,
                'source_fingerprint' => isset($raw_artifact['content_hash']) ? (string) $raw_artifact['content_hash'] : '',
                'assignment_unresolved_count' => isset($assignment['unresolved_items']) && is_array($assignment['unresolved_items'])
                    ? count($assignment['unresolved_items'])
                    : 0,
                'stage_budget' => DBVC_CC_V2_Contracts::get_ai_stage_budget(DBVC_CC_V2_Contracts::AI_STAGE_RECOMMENDATION_FINALIZATION),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $target_transform_artifact
     * @return array<string, array<string, mixed>>
     */
    private function build_transform_lookup(array $target_transform_artifact)
    {
        $lookup = [];
        $transform_items = isset($target_transform_artifact['transform_items']) && is_array($target_transform_artifact['transform_items'])
            ? $target_transform_artifact['transform_items']
            : [];

        foreach ($transform_items as $transform_item) {
            if (! is_array($transform_item)) {
                continue;
            }

            $source_refs = isset($transform_item['source_refs']) && is_array($transform_item['source_refs']) ? $transform_item['source_refs'] : [];
            $target_ref = isset($transform_item['target_ref']) ? (string) $transform_item['target_ref'] : '';
            if ($target_ref === '') {
                continue;
            }

            $lookup[$this->build_lookup_key($source_refs, $target_ref)] = $transform_item;
        }

        return $lookup;
    }

    /**
     * @param array<string, mixed> $assignment
     * @param array<string, array<string, mixed>> $transform_lookup
     * @return array<int, array<string, mixed>>
     */
    private function build_recommendations(array $assignment, array $transform_lookup)
    {
        $recommendations = [];
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

            $source_refs = isset($content_item['source_refs']) && is_array($content_item['source_refs']) ? array_values($content_item['source_refs']) : [];
            $target_ref = (string) $candidate['target_ref'];
            $transform = isset($transform_lookup[$this->build_lookup_key($source_refs, $target_ref)]) && is_array($transform_lookup[$this->build_lookup_key($source_refs, $target_ref)])
                ? $transform_lookup[$this->build_lookup_key($source_refs, $target_ref)]
                : [];
            $confidence = isset($candidate['confidence']) ? (float) $candidate['confidence'] : 0.0;
            $selection = isset($content_item['selection']) && is_array($content_item['selection']) ? $content_item['selection'] : [];
            $requires_review = $confidence < (float) DBVC_CC_V2_Contracts::get_automation_settings()['autoAcceptMinConfidence']
                || (! empty($selection['default_decision']) && (string) $selection['default_decision'] === 'unresolved')
                || ! empty($selection['reason_codes'])
                || ! empty($transform['warnings'])
                || (
                    isset($transform['value_contract_validation']['status'])
                    && sanitize_key((string) $transform['value_contract_validation']['status']) === 'blocked'
                );

            $recommendations[] = [
                'recommendation_id' => 'rec_' . str_pad((string) (count($recommendations) + 1), 3, '0', STR_PAD_LEFT),
                'item_id' => isset($content_item['item_id']) ? (string) $content_item['item_id'] : '',
                'source_refs' => $source_refs,
                'target_ref' => $target_ref,
                'target_family' => $this->infer_target_family($target_ref),
                'target_field_key' => $this->infer_target_field_key($target_ref),
                'recommended_value_type' => isset($transform['output_shape']) ? (string) $transform['output_shape'] : 'scalar_string',
                'confidence' => round($confidence, 2),
                'rationale' => isset($candidate['reason']) ? str_replace('_', ' ', (string) $candidate['reason']) : '',
                'source_evidence' => isset($content_item['value_preview']) ? (string) $content_item['value_preview'] : '',
                'target_evidence' => ! empty($transform['preview_value']) ? $transform['preview_value'] : $target_ref,
                'requires_review' => $requires_review,
                'default_decision' => ! empty($selection['default_decision']) ? (string) $selection['default_decision'] : 'approve',
                'candidate_group' => isset($content_item['candidate_group']) ? (string) $content_item['candidate_group'] : '',
                'context_tag' => isset($content_item['context_tag']) ? (string) $content_item['context_tag'] : '',
                'pattern_key' => isset($candidate['pattern_key']) ? (string) $candidate['pattern_key'] : '',
                'selection' => $selection,
                'transform_status' => isset($transform['transform_status']) ? sanitize_key((string) $transform['transform_status']) : '',
                'transform_warnings' => isset($transform['warnings']) && is_array($transform['warnings']) ? array_values($transform['warnings']) : [],
                'value_contract' => isset($transform['value_contract']) && is_array($transform['value_contract']) ? $transform['value_contract'] : [],
                'value_contract_validation' => isset($transform['value_contract_validation']) && is_array($transform['value_contract_validation'])
                    ? $transform['value_contract_validation']
                    : [],
            ] + (array_key_exists('entity_ref', $content_item) ? ['entity_ref' => (string) $content_item['entity_ref']] : []);
        }

        return $recommendations;
    }

    /**
     * Two values competing for one field of one record are a contest, and a contest is never
     * settled unreviewed.
     *
     * Measured on corpus slot 1's team page: each photo's alt text, which the capture lifts into
     * a paragraph (D-18), was filed as the person's role beside the real role heading, both at
     * auto-accept confidence. The duplicate is already a conflict that holds the package, but a
     * recommendation that still reads `requires_review: false` is one G-5 counts as decided
     * without a reviewer — and it was wrong. Same rule as D-22: escalate, don't re-score. The
     * confidence is untouched; the value just stops auto-accepting, with the reason named.
     *
     * Scoped to values filed under a record, which only a model-declared set produces, so a
     * single-record page is unchanged.
     *
     * @param array<int, array<string, mixed>> $recommendations
     * @param array<int, array<string, mixed>> $conflicts
     * @return array<int, array<string, mixed>>
     */
    private function escalate_contested_record_fields(array $recommendations, array $conflicts)
    {
        $contested = [];
        foreach ($conflicts as $conflict) {
            foreach ((array) ($conflict['recommendation_ids'] ?? []) as $recommendation_id) {
                $contested[(string) $recommendation_id] = true;
            }
        }
        if ($contested === []) {
            return $recommendations;
        }

        foreach ($recommendations as $index => $recommendation) {
            if (! isset($recommendation['entity_ref']) || ! isset($contested[(string) $recommendation['recommendation_id']])) {
                continue;
            }
            $recommendations[$index]['requires_review'] = true;
            $reason_codes = isset($recommendation['selection']['reason_codes']) && is_array($recommendation['selection']['reason_codes'])
                ? $recommendation['selection']['reason_codes']
                : [];
            $reason_codes[] = 'contested_field_in_record';
            $recommendations[$index]['selection']['reason_codes'] = array_values(array_unique($reason_codes));
        }

        return $recommendations;
    }

    /**
     * The records one source URL becomes, read from the interpreter's set (D-19).
     *
     * Empty unless the mapping index recorded a model-declared set. Each entry is shaped like
     * `recommended_target_object` plus the record's own `entity_ref`, `label` and `slug`, so a
     * reader that handles one target handles each of these the same way. Resolution is
     * previewed per record against the record's own slug; the importer's match order still runs
     * per record at dry run and is what actually decides create-or-update.
     *
     * @param array<string, mixed>             $page_context
     * @param array<string, mixed>             $mapping_index_artifact
     * @param array<int, array<string, mixed>> $recommendations
     * @return array<int, array<string, mixed>>
     */
    private function build_target_entities(array $page_context, array $mapping_index_artifact, array $recommendations)
    {
        $entities = isset($mapping_index_artifact['interpretation']['entities']) && is_array($mapping_index_artifact['interpretation']['entities'])
            ? $mapping_index_artifact['interpretation']['entities']
            : [];
        if ($entities === []) {
            return [];
        }

        $target_entities = [];
        $used_slugs = [];
        foreach ($entities as $entity) {
            if (! is_array($entity) || empty($entity['entity_id']) || empty($entity['object_key'])) {
                continue;
            }

            $entity_ref = (string) $entity['entity_id'];
            $confidences = [];
            foreach ($recommendations as $recommendation) {
                if (isset($recommendation['entity_ref']) && $recommendation['entity_ref'] === $entity_ref) {
                    $confidences[] = (float) $recommendation['confidence'];
                }
            }
            if ($confidences === []) {
                continue;
            }

            $label = isset($entity['label']) ? (string) $entity['label'] : '';
            $slug = DBVC_CC_V2_Contracts::build_entity_slug($label, $entity_ref, $used_slugs);
            $used_slugs[$slug] = true;
            $object_key = sanitize_key((string) $entity['object_key']);
            $preview = DBVC_CC_V2_Target_Transform_Service::get_instance()->build_entity_resolution_preview($object_key, $slug);

            $target_entities[] = [
                'entity_ref' => $entity_ref,
                'target_family' => 'post_type',
                'target_object_key' => $object_key,
                'label' => $label,
                'slug' => $slug,
                // The weakest value decides how far the record can be trusted unreviewed.
                'confidence' => round(min($confidences), 2),
                'resolution_mode' => (string) $preview['mode'],
                'resolution_reason' => (string) $preview['reason'],
                'target' => $preview['target'],
                'recommendation_count' => count($confidences),
            ];
        }

        return $target_entities;
    }

    /**
     * @param array<string, mixed> $media_candidates_artifact
     * @param array<string, array<string, mixed>> $transform_lookup
     * @return array<int, array<string, mixed>>
     */
    private function build_media_recommendations(array $media_candidates_artifact, array $transform_lookup)
    {
        $recommendations = [];
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

            $source_refs = isset($media_item['source_refs']) && is_array($media_item['source_refs']) ? array_values($media_item['source_refs']) : [];
            $target_ref = (string) $candidate['target_ref'];
            $transform = isset($transform_lookup[$this->build_lookup_key($source_refs, $target_ref)]) && is_array($transform_lookup[$this->build_lookup_key($source_refs, $target_ref)])
                ? $transform_lookup[$this->build_lookup_key($source_refs, $target_ref)]
                : [];
            $confidence = isset($candidate['confidence']) ? (float) $candidate['confidence'] : 0.0;

            $recommendations[] = [
                'recommendation_id' => 'media_' . str_pad((string) (count($recommendations) + 1), 3, '0', STR_PAD_LEFT),
                'media_id' => isset($media_item['media_id']) ? (string) $media_item['media_id'] : '',
                'source_refs' => $source_refs,
                'target_ref' => $target_ref,
                'target_family' => 'media',
                'target_field_key' => $this->infer_target_field_key($target_ref),
                'recommended_value_type' => 'attachment_reference',
                'confidence' => round($confidence, 2),
                'rationale' => isset($candidate['reason']) ? str_replace('_', ' ', (string) $candidate['reason']) : '',
                'source_evidence' => isset($media_item['normalized_url']) ? (string) $media_item['normalized_url'] : '',
                'target_evidence' => ! empty($transform['preview_value']) ? $transform['preview_value'] : $target_ref,
                'requires_review' => ! empty($transform['warnings'])
                    || (
                        isset($transform['value_contract_validation']['status'])
                        && sanitize_key((string) $transform['value_contract_validation']['status']) === 'blocked'
                    ),
                'media_kind' => isset($media_item['media_kind']) ? (string) $media_item['media_kind'] : '',
                'source_section_id' => isset($media_item['source_section_id']) ? (string) $media_item['source_section_id'] : '',
                'role_candidates' => isset($media_item['role_candidates']) && is_array($media_item['role_candidates']) ? array_values($media_item['role_candidates']) : [],
                'transform_status' => isset($transform['transform_status']) ? sanitize_key((string) $transform['transform_status']) : '',
                'transform_warnings' => isset($transform['warnings']) && is_array($transform['warnings']) ? array_values($transform['warnings']) : [],
                'value_contract' => isset($transform['value_contract']) && is_array($transform['value_contract']) ? $transform['value_contract'] : [],
                'value_contract_validation' => isset($transform['value_contract_validation']) && is_array($transform['value_contract_validation'])
                    ? $transform['value_contract_validation']
                    : [],
            ];
        }

        return $recommendations;
    }

    /**
     * @param array<string, mixed> $assignment
     * @param array<string, mixed> $media_candidates_artifact
     * @return array<int, array<string, mixed>>
     */
    private function build_unresolved_items(array $assignment, array $media_candidates_artifact)
    {
        $unresolved = isset($assignment['unresolved_items']) && is_array($assignment['unresolved_items'])
            ? array_values($assignment['unresolved_items'])
            : [];

        $media_items = isset($media_candidates_artifact['media_items']) && is_array($media_candidates_artifact['media_items'])
            ? $media_candidates_artifact['media_items']
            : [];
        foreach ($media_items as $media_item) {
            if (! is_array($media_item) || ! empty($media_item['target_candidates'])) {
                continue;
            }

            $unresolved[] = [
                'item_id' => isset($media_item['media_id']) ? (string) $media_item['media_id'] : '',
                'item_type' => 'media',
                'source_refs' => isset($media_item['source_refs']) && is_array($media_item['source_refs']) ? array_values($media_item['source_refs']) : [],
                'reason' => 'no_media_target_candidates',
                'unresolved_class' => 'missing_media_slot',
                'reason_codes' => ['no_media_target_candidates'],
            ];
        }

        return $unresolved;
    }

    /**
     * @param array<int, array<string, mixed>> $recommendations
     * @param array<int, array<string, mixed>> $media_recommendations
     * @return array<int, array<string, mixed>>
     */
    private function build_conflicts(array $recommendations, array $media_recommendations)
    {
        $conflicts = [];
        $target_index = [];

        foreach (array_merge($recommendations, $media_recommendations) as $recommendation) {
            if (! is_array($recommendation) || empty($recommendation['target_ref'])) {
                continue;
            }

            $target_ref = (string) $recommendation['target_ref'];
            if ($this->target_ref_is_repeatable($target_ref)) {
                continue;
            }
            // Within one record of a set, several paragraphs bound for one rich-text field are
            // that field's value, merged in source order at packaging — not a contest.
            if (isset($recommendation['entity_ref']) && ($recommendation['recommended_value_type'] ?? '') === 'rich_text') {
                continue;
            }

            // Five records each have a title; that is five fields, not one field claimed five
            // times. Only values bound for the same record compete.
            $index_key = isset($recommendation['entity_ref'])
                ? (string) $recommendation['entity_ref'] . "\x1f" . $target_ref
                : $target_ref;
            if (! isset($target_index[$index_key])) {
                $target_index[$index_key] = ['target_ref' => $target_ref, 'ids' => []];
            }
            $target_index[$index_key]['ids'][] = isset($recommendation['recommendation_id']) ? (string) $recommendation['recommendation_id'] : '';
        }

        foreach ($target_index as $entry) {
            $target_ref = $entry['target_ref'];
            $recommendation_ids = $entry['ids'];
            $recommendation_ids = array_values(array_filter($recommendation_ids));
            if (count($recommendation_ids) <= 1) {
                continue;
            }

            $conflicts[] = [
                'target_ref' => $target_ref,
                'recommendation_ids' => $recommendation_ids,
                'reason' => 'duplicate_target_ref',
            ];
        }

        return $conflicts;
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $resolution_preview
     * @param array<int, array<string, mixed>> $recommendations
     * @param array<int, array<string, mixed>> $media_recommendations
     * @param array<int, array<string, mixed>> $unresolved_items
     * @param array<int, array<string, mixed>> $conflicts
     * @return array<string, mixed>
     */
    private function build_review(
        array $primary,
        array $resolution_preview,
        array $recommendations,
        array $media_recommendations,
        array $unresolved_items,
        array $conflicts
    ) {
        $automation = DBVC_CC_V2_Contracts::get_automation_settings();
        $reason_codes = [];
        $status = 'needs_review';

        if (isset($resolution_preview['mode']) && (string) $resolution_preview['mode'] === DBVC_CC_V2_Contracts::RESOLUTION_MODE_BLOCKED) {
            $status = 'blocked';
            $reason_codes[] = 'blocked_resolution';
        } elseif (! empty($unresolved_items)) {
            $reason_codes[] = 'unresolved_items';
        } elseif (! empty($conflicts)) {
            $reason_codes[] = 'target_conflicts';
        }

        $needs_manual_review = ! empty(
            array_filter(
                array_merge($recommendations, $media_recommendations),
                static function ($recommendation) {
                    return is_array($recommendation) && ! empty($recommendation['requires_review']);
                }
            )
        );
        if ($needs_manual_review) {
            $reason_codes[] = 'low_confidence_recommendations';
        }

        $has_ambiguous_recommendations = ! empty(
            array_filter(
                $recommendations,
                static function ($recommendation) {
                    return is_array($recommendation)
                        && isset($recommendation['default_decision'])
                        && (string) $recommendation['default_decision'] === 'unresolved';
                }
            )
        );
        if ($has_ambiguous_recommendations) {
            $reason_codes[] = 'ambiguous_recommendations';
        }

        $primary_confidence = isset($primary['confidence']) ? (float) $primary['confidence'] : 0.0;
        if (
            $status !== 'blocked'
            && empty($reason_codes)
            && $primary_confidence >= (float) $automation['autoAcceptMinConfidence']
            && (
                ! empty($recommendations)
                || ! empty($media_recommendations)
            )
        ) {
            $status = 'auto_accept_candidate';
        }

        return [
            'status' => $status,
            'reason_codes' => array_values(array_unique($reason_codes)),
            'resolution_mode' => isset($resolution_preview['mode']) ? (string) $resolution_preview['mode'] : '',
            'primary_confidence' => round($primary_confidence, 2),
            'unresolved_count' => count($unresolved_items),
            'conflict_count' => count($conflicts),
        ];
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<int, array<string, mixed>> $alternates
     * @return array<int, array<string, mixed>>
     */
    private function build_candidate_target_objects(array $primary, array $alternates)
    {
        $objects = [];
        foreach (array_merge([$primary], $alternates) as $candidate) {
            if (! is_array($candidate) || empty($candidate['object_key'])) {
                continue;
            }

            $objects[] = [
                'target_family' => isset($candidate['type_family']) ? (string) $candidate['type_family'] : '',
                'target_object_key' => isset($candidate['object_key']) ? (string) $candidate['object_key'] : '',
                'label' => isset($candidate['label']) ? (string) $candidate['label'] : '',
                'confidence' => isset($candidate['confidence']) ? (float) $candidate['confidence'] : 0.0,
            ];
        }

        return $objects;
    }

    /**
     * @param array<int, string> $source_refs
     * @param string $target_ref
     * @return string
     */
    private function build_lookup_key(array $source_refs, $target_ref)
    {
        return implode('|', $source_refs) . '>' . (string) $target_ref;
    }

    /**
     * @param string $target_ref
     * @return string
     */
    private function infer_target_family($target_ref)
    {
        if (strpos($target_ref, 'core:') === 0) {
            return 'core';
        }

        if (strpos($target_ref, 'meta:') === 0) {
            return 'meta';
        }

        if (strpos($target_ref, 'acf:') === 0) {
            return 'acf';
        }

        if (strpos($target_ref, 'taxonomy:') === 0) {
            return 'taxonomy';
        }

        return 'unknown';
    }

    /**
     * @param string $target_ref
     * @return string
     */
    private function infer_target_field_key($target_ref)
    {
        $parts = explode(':', (string) $target_ref);
        return isset($parts[count($parts) - 1]) ? (string) $parts[count($parts) - 1] : '';
    }

    /**
     * @param string $target_ref
     * @return bool
     */
    private function target_ref_is_repeatable($target_ref)
    {
        return $target_ref === 'core:post_content' || strpos((string) $target_ref, 'taxonomy:') === 0;
    }
}
