<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Mapping_Index_Service
{
    /**
     * @var DBVC_CC_V2_Mapping_Index_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Mapping_Index_Service
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
     * @param array<string, mixed> $catalog_bundle
     * @param array<string, mixed> $pattern_memory
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function build_artifact(
        array $page_context,
        array $classification_artifact,
        array $catalog_bundle,
        array $pattern_memory = [],
        array $args = []
    ) {
        $raw_artifact = isset($page_context['raw_artifact']) && is_array($page_context['raw_artifact']) ? $page_context['raw_artifact'] : [];
        $ingestion_package = isset($page_context['ingestion_package_artifact']) && is_array($page_context['ingestion_package_artifact']) ? $page_context['ingestion_package_artifact'] : [];
        $context_artifact = isset($page_context['context_artifact']) && is_array($page_context['context_artifact']) ? $page_context['context_artifact'] : [];
        $routing_artifact = isset($page_context['routing_artifact']) && is_array($page_context['routing_artifact'])
            ? $page_context['routing_artifact']
            : DBVC_CC_V2_Routing_Artifact_Service::get_instance()->build_artifact($page_context, $context_artifact, $classification_artifact);
        $sections_artifact = isset($page_context['sections_artifact']) && is_array($page_context['sections_artifact']) ? $page_context['sections_artifact'] : [];
        $elements_artifact = isset($page_context['elements_artifact']) && is_array($page_context['elements_artifact']) ? $page_context['elements_artifact'] : [];
        $catalog = isset($catalog_bundle['catalog']) && is_array($catalog_bundle['catalog']) ? $catalog_bundle['catalog'] : [];
        $primary = isset($classification_artifact['primary_classification']) && is_array($classification_artifact['primary_classification'])
            ? $classification_artifact['primary_classification']
            : [];
        $routing_primary = isset($routing_artifact['primary_route']) && is_array($routing_artifact['primary_route'])
            ? $routing_artifact['primary_route']
            : [];
        $object_key = ! empty($routing_primary['object_key'])
            ? sanitize_key((string) $routing_primary['object_key'])
            : (isset($primary['object_key']) ? sanitize_key((string) $primary['object_key']) : '');
        $page_intent = isset($routing_primary['page_intent']) ? sanitize_key((string) $routing_primary['page_intent']) : '';
        $slot_graph_bundle = DBVC_CC_V2_Target_Slot_Graph_Service::get_instance()->get_graph(
            isset($page_context['domain']) ? (string) $page_context['domain'] : '',
            true
        );
        $slot_graph_result = is_wp_error($slot_graph_bundle) ? [] : $slot_graph_bundle;
        $slot_graph = isset($slot_graph_result['slot_graph']) && is_array($slot_graph_result['slot_graph'])
            ? $slot_graph_result['slot_graph']
            : [];
        $narrowed_catalog = $this->build_narrowed_catalog($catalog, $object_key, $slot_graph_result);
        $field_context_provider = isset($slot_graph['field_context_provider']) && is_array($slot_graph['field_context_provider'])
            ? $slot_graph['field_context_provider']
            : (
                isset($catalog['field_context_provider']) && is_array($catalog['field_context_provider'])
                    ? $catalog['field_context_provider']
                    : []
            );
        $context_map = $this->build_context_map($context_artifact);
        $route_map = $this->build_route_map($routing_artifact);
        $section_outline_map = $this->build_section_outline_map($sections_artifact);
        $element_lookup = $this->build_element_lookup($elements_artifact);

        $content_items = [];
        $unresolved_items = [];
        $item_order = 0;
        $structured_section_item_count = 0;
        $skipped_section_count = 0;

        $title = isset($raw_artifact['metadata']['title']) ? trim((string) $raw_artifact['metadata']['title']) : '';
        if ($title !== '') {
            ++$item_order;
            $page_title = $this->build_page_title_item($item_order, $title, $primary, $narrowed_catalog, $pattern_memory);
            $content_items[] = $page_title;
            if (empty($page_title['target_candidates'])) {
                $unresolved_items[] = $this->build_unresolved_item($page_title, 'no_target_candidates');
            }
        }

        $description = isset($raw_artifact['metadata']['description']) ? trim((string) $raw_artifact['metadata']['description']) : '';
        if ($description !== '') {
            ++$item_order;
            $page_description = $this->build_page_description_item($item_order, $description, $primary, $narrowed_catalog, $pattern_memory);
            $content_items[] = $page_description;
            if (empty($page_description['target_candidates'])) {
                $unresolved_items[] = $this->build_unresolved_item($page_description, 'no_target_candidates');
            }
        }

        $sections = isset($ingestion_package['sections']) && is_array($ingestion_package['sections']) ? $ingestion_package['sections'] : [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $section_id = isset($section['section_id']) ? sanitize_key((string) $section['section_id']) : '';
            $context_item = isset($context_map[$section_id]) && is_array($context_map[$section_id]) ? $context_map[$section_id] : [];
            $section_outline = isset($section_outline_map[$section_id]) && is_array($section_outline_map[$section_id]) ? $section_outline_map[$section_id] : [];
            $section_unit_result = DBVC_CC_V2_Section_Content_Item_Service::get_instance()->build_units(
                $section,
                $section_outline,
                $context_item,
                $element_lookup
            );
            $section_units = isset($section_unit_result['units']) && is_array($section_unit_result['units'])
                ? array_values($section_unit_result['units'])
                : [];

            if (! empty($section_unit_result['skip_reason'])) {
                ++$skipped_section_count;
                continue;
            }

            if (! empty($section_units)) {
                foreach ($section_units as $section_unit) {
                    if (! is_array($section_unit)) {
                        continue;
                    }

                    ++$item_order;
                    ++$structured_section_item_count;
                    $section_item = $this->build_section_item_from_unit(
                        $item_order,
                        $section_unit,
                        $primary,
                        $narrowed_catalog,
                        $pattern_memory,
                        $route_map,
                        $page_intent
                    );
                    $content_items[] = $section_item;
                    if (empty($section_item['target_candidates'])) {
                        $unresolved_items[] = $this->build_unresolved_item($section_item, 'no_target_candidates');
                    }
                }

                continue;
            }

            ++$item_order;
            $section_item = $this->build_section_item($item_order, $section, $primary, $narrowed_catalog, $context_map, $pattern_memory, $route_map, $page_intent);
            $content_items[] = $section_item;
            if (empty($section_item['target_candidates'])) {
                $unresolved_items[] = $this->build_unresolved_item($section_item, 'no_target_candidates');
            }
        }

        $best_confidences = [];
        foreach ($content_items as $content_item) {
            if (! is_array($content_item)) {
                continue;
            }

            $summary = isset($content_item['confidence_summary']) && is_array($content_item['confidence_summary']) ? $content_item['confidence_summary'] : [];
            $best_confidences[] = isset($summary['best']) ? (float) $summary['best'] : 0.0;
        }

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'mapping-index.v1',
            'journey_id' => isset($raw_artifact['journey_id']) ? (string) $raw_artifact['journey_id'] : '',
            'page_id' => isset($raw_artifact['page_id']) ? (string) $raw_artifact['page_id'] : '',
            'source_url' => isset($raw_artifact['source_url']) ? (string) $raw_artifact['source_url'] : '',
            'generated_at' => current_time('c'),
            'catalog_fingerprint' => isset($catalog_bundle['catalog_fingerprint']) ? (string) $catalog_bundle['catalog_fingerprint'] : '',
            'classification_ref' => isset($args['classification_ref']) ? (string) $args['classification_ref'] : '',
            'routing_ref' => isset($args['routing_ref']) ? (string) $args['routing_ref'] : '',
            'field_context_provider' => $this->build_field_context_provider_summary($field_context_provider),
            'routing' => [
                'primary_route' => $routing_primary,
                'review' => isset($routing_artifact['review']) && is_array($routing_artifact['review']) ? $routing_artifact['review'] : [],
                'summary' => isset($routing_artifact['summary']) && is_array($routing_artifact['summary']) ? $routing_artifact['summary'] : [],
            ],
            'content_items' => $content_items,
            'unresolved_items' => $unresolved_items,
            'trace' => [
                'input_artifacts' => isset($args['input_artifacts']) && is_array($args['input_artifacts']) ? array_values($args['input_artifacts']) : [],
                'primary_object_key' => $object_key,
                'page_intent' => $page_intent,
                'pattern_memory_ref' => isset($args['pattern_memory_ref']) ? (string) $args['pattern_memory_ref'] : '',
                'routing_ref' => isset($args['routing_ref']) ? (string) $args['routing_ref'] : '',
                'slot_graph_ref' => isset($slot_graph_result['artifact_relative_path']) ? (string) $slot_graph_result['artifact_relative_path'] : '',
                'slot_graph_fingerprint' => isset($narrowed_catalog['slot_graph_fingerprint']) ? (string) $narrowed_catalog['slot_graph_fingerprint'] : '',
                'slot_graph_status' => isset($narrowed_catalog['slot_graph_status']) ? (string) $narrowed_catalog['slot_graph_status'] : '',
                'structured_section_item_count' => $structured_section_item_count,
                'skipped_section_count' => $skipped_section_count,
                'stage_budget' => DBVC_CC_V2_Contracts::get_ai_stage_budget(DBVC_CC_V2_Contracts::AI_STAGE_MAPPING_INDEX),
            ],
            'stats' => [
                'content_item_count' => count($content_items),
                'unresolved_count' => count($unresolved_items),
                'structured_section_item_count' => $structured_section_item_count,
                'skipped_section_count' => $skipped_section_count,
                'average_best_confidence' => empty($best_confidences) ? 0.0 : round(array_sum($best_confidences) / count($best_confidences), 2),
            ],
        ];
    }

    /**
     * @param int $item_order
     * @param string $title
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $narrowed_catalog
     * @param array<string, mixed> $pattern_memory
     * @return array<string, mixed>
     */
    private function build_page_title_item($item_order, $title, array $primary, array $narrowed_catalog, array $pattern_memory)
    {
        $pattern_keys = ['title', 'headline', 'hero'];
        $candidates = [];
        if (! empty($narrowed_catalog['supports']['title'])) {
            $candidates[] = [
                'target_ref' => 'core:post_title',
                'confidence' => 0.97,
                'reason' => 'page_title_to_post_title',
                'pattern_key' => 'title',
            ];
        }

        $candidates = array_merge(
            $candidates,
            $this->collect_pattern_candidates(
                $pattern_keys,
                0.84,
                $narrowed_catalog,
                $pattern_memory,
                $primary,
                [
                    'item_type' => 'page_title',
                    'context_tag' => 'title',
                    'object_key' => isset($primary['object_key']) ? (string) $primary['object_key'] : '',
                    'source_preview' => $title,
                ]
            )
        );

        return $this->finalize_item(
            [
                'item_id' => 'map_' . str_pad((string) $item_order, 3, '0', STR_PAD_LEFT),
                'item_type' => 'page_title',
                'source_refs' => ['page-artifact.v1#metadata.title'],
                'candidate_group' => 'page_title',
                'context_tag' => 'title',
                'value_preview' => $title,
                'pattern_keys' => $pattern_keys,
                'notes' => ['Deterministic title mapping against the selected target object.'],
            ],
            $candidates
        );
    }

    /**
     * @param int $item_order
     * @param string $description
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $narrowed_catalog
     * @param array<string, mixed> $pattern_memory
     * @return array<string, mixed>
     */
    private function build_page_description_item($item_order, $description, array $primary, array $narrowed_catalog, array $pattern_memory)
    {
        $pattern_keys = ['description', 'summary', 'intro'];
        $candidates = [];
        if (! empty($narrowed_catalog['supports']['excerpt'])) {
            $candidates[] = [
                'target_ref' => 'core:post_excerpt',
                'confidence' => 0.88,
                'reason' => 'meta_description_to_excerpt',
                'pattern_key' => 'description',
            ];
        } elseif (! empty($narrowed_catalog['supports']['editor'])) {
            $candidates[] = [
                'target_ref' => 'core:post_content',
                'confidence' => 0.68,
                'reason' => 'meta_description_to_content',
                'pattern_key' => 'description',
            ];
        }

        $candidates = array_merge(
            $candidates,
            $this->collect_pattern_candidates(
                $pattern_keys,
                0.78,
                $narrowed_catalog,
                $pattern_memory,
                $primary,
                [
                    'item_type' => 'page_description',
                    'context_tag' => 'description',
                    'object_key' => isset($primary['object_key']) ? (string) $primary['object_key'] : '',
                    'source_preview' => $description,
                ]
            )
        );

        return $this->finalize_item(
            [
                'item_id' => 'map_' . str_pad((string) $item_order, 3, '0', STR_PAD_LEFT),
                'item_type' => 'page_description',
                'source_refs' => ['page-artifact.v1#metadata.description'],
                'candidate_group' => 'page_description',
                'context_tag' => 'description',
                'value_preview' => $description,
                'pattern_keys' => $pattern_keys,
                'notes' => ['Description candidate mapping stays inside the selected object family.'],
            ],
            $candidates
        );
    }

    /**
     * @param int $item_order
     * @param array<string, mixed> $section
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $narrowed_catalog
     * @param array<string, array<string, mixed>> $context_map
     * @param array<string, mixed> $pattern_memory
     * @param array<string, array<string, mixed>> $route_map
     * @param string $page_intent
     * @return array<string, mixed>
     */
    private function build_section_item(
        $item_order,
        array $section,
        array $primary,
        array $narrowed_catalog,
        array $context_map,
        array $pattern_memory,
        array $route_map = [],
        $page_intent = ''
    ) {
        $section_id = isset($section['section_id']) ? sanitize_key((string) $section['section_id']) : '';
        $label = isset($section['label']) ? sanitize_text_field((string) $section['label']) : '';
        $sample_text = isset($section['sample_text']) && is_array($section['sample_text']) ? array_values($section['sample_text']) : [];
        $context_item = isset($context_map[$section_id]) && is_array($context_map[$section_id]) ? $context_map[$section_id] : [];
        $route_context = isset($route_map[$section_id]) && is_array($route_map[$section_id]) ? $route_map[$section_id] : [];
        $context_tag = DBVC_CC_V2_Section_Semantics_Service::get_instance()->infer_context_tag(
            max(0, (int) (isset($section['order']) ? $section['order'] : 1) - 1),
            $label,
            $sample_text,
            isset($section['signals']) && is_array($section['signals']) ? $section['signals'] : [],
            isset($context_item['context_tag']) ? (string) $context_item['context_tag'] : 'content'
        );
        $route_scope = isset($route_context['section_scope']) ? sanitize_key((string) $route_context['section_scope']) : '';
        $effective_context_tag = $this->resolve_effective_section_context_tag($context_tag, $route_scope, $page_intent);
        $pattern_keys = $this->append_route_pattern_keys(
            $this->derive_section_pattern_keys($label, $sample_text, $effective_context_tag),
            $route_scope,
            $page_intent
        );

        $candidates = $this->build_section_core_candidates($effective_context_tag, '', $narrowed_catalog);
        $candidates = array_merge(
            $candidates,
            $this->collect_pattern_candidates(
                $pattern_keys,
                0.82,
                $narrowed_catalog,
                $pattern_memory,
                $primary,
                [
                    'item_type' => 'section',
                    'context_tag' => $effective_context_tag,
                    'section_family' => $effective_context_tag,
                    'route_scope' => $route_scope,
                    'page_intent' => sanitize_key((string) $page_intent),
                    'object_key' => isset($primary['object_key']) ? (string) $primary['object_key'] : '',
                    'source_preview' => implode(' ', array_slice($sample_text, 0, 2)),
                ]
            )
        );

        return $this->finalize_item(
            [
                'item_id' => 'map_' . str_pad((string) $item_order, 3, '0', STR_PAD_LEFT),
                'item_type' => 'section',
                'source_refs' => array_values(array_filter([
                    $section_id !== '' ? 'sections.v2#' . $section_id : '',
                    $section_id !== '' ? 'ingestion-package.v2#sections.' . $section_id : '',
                ])),
                'candidate_group' => 'section_field',
                'context_tag' => $effective_context_tag,
                'route_scope' => $route_scope,
                'value_preview' => implode(' ', array_slice($sample_text, 0, 2)),
                'pattern_keys' => $pattern_keys,
                'notes' => array_values(array_filter([
                    $label !== '' ? 'Section label: ' . $label : '',
                    ! empty($context_item['rationale']) ? 'Context signal: ' . sanitize_text_field((string) $context_item['rationale']) : '',
                    $route_scope !== '' && $route_scope !== $context_tag ? sprintf('Routing scope normalized this section to `%s`.', $route_scope) : '',
                ])),
            ],
            $candidates
        );
    }

    /**
     * @param int                  $item_order
     * @param array<string, mixed> $section_unit
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $narrowed_catalog
     * @param array<string, mixed> $pattern_memory
     * @param array<string, array<string, mixed>> $route_map
     * @param string $page_intent
     * @return array<string, mixed>
     */
    private function build_section_item_from_unit(
        $item_order,
        array $section_unit,
        array $primary,
        array $narrowed_catalog,
        array $pattern_memory,
        array $route_map = [],
        $page_intent = ''
    ) {
        $content_role = isset($section_unit['content_role']) ? sanitize_key((string) $section_unit['content_role']) : '';
        $context_tag = isset($section_unit['context_tag']) ? sanitize_key((string) $section_unit['context_tag']) : 'content';
        $section_id = isset($section_unit['section_id']) ? sanitize_key((string) $section_unit['section_id']) : '';
        $route_context = isset($route_map[$section_id]) && is_array($route_map[$section_id]) ? $route_map[$section_id] : [];
        $route_scope = isset($route_context['section_scope']) ? sanitize_key((string) $route_context['section_scope']) : '';
        $effective_context_tag = $this->resolve_effective_section_context_tag($context_tag, $route_scope, $page_intent);
        $pattern_seed = isset($section_unit['pattern_seed']) ? (string) $section_unit['pattern_seed'] : '';
        $pattern_keys = $this->append_route_pattern_keys(
            $this->derive_section_unit_pattern_keys($pattern_seed, $effective_context_tag, $content_role),
            $route_scope,
            $page_intent
        );

        $candidates = $this->build_section_core_candidates($effective_context_tag, $content_role, $narrowed_catalog);
        $candidates = array_merge(
            $candidates,
            $this->collect_pattern_candidates(
                $pattern_keys,
                $this->get_section_unit_base_confidence($content_role),
                $narrowed_catalog,
                $pattern_memory,
                $primary,
                [
                    'item_type' => 'section',
                    'context_tag' => $effective_context_tag,
                    'section_family' => $effective_context_tag,
                    'content_role' => $content_role,
                    'route_scope' => $route_scope,
                    'page_intent' => sanitize_key((string) $page_intent),
                    'object_key' => isset($primary['object_key']) ? (string) $primary['object_key'] : '',
                    'source_preview' => isset($section_unit['value_preview']) ? (string) $section_unit['value_preview'] : '',
                ]
            )
        );

        return $this->finalize_item(
            [
                'item_id' => 'map_' . str_pad((string) $item_order, 3, '0', STR_PAD_LEFT),
                'item_type' => 'section',
                'section_id' => $section_id,
                'content_role' => $content_role,
                'source_refs' => isset($section_unit['source_refs']) && is_array($section_unit['source_refs']) ? array_values($section_unit['source_refs']) : [],
                'candidate_group' => 'section_field',
                'context_tag' => $effective_context_tag,
                'route_scope' => $route_scope,
                'value_preview' => isset($section_unit['value_preview']) ? (string) $section_unit['value_preview'] : '',
                'pattern_keys' => $pattern_keys,
                'notes' => array_values(
                    array_filter(
                        array_merge(
                            isset($section_unit['notes']) && is_array($section_unit['notes']) ? array_values($section_unit['notes']) : [],
                            [
                                $route_scope !== '' && $route_scope !== $context_tag ? sprintf('Routing scope normalized this section to `%s`.', $route_scope) : '',
                            ]
                        )
                    )
                ),
            ],
            $candidates
        );
    }

    /**
     * @param string               $context_tag
     * @param string               $content_role
     * @param array<string, mixed> $narrowed_catalog
     * @return array<int, array<string, mixed>>
     */
    private function build_section_core_candidates($context_tag, $content_role, array $narrowed_catalog)
    {
        $context_tag = sanitize_key((string) $context_tag);
        $content_role = sanitize_key((string) $content_role);
        $candidates = [];
        $slot_indexes = isset($narrowed_catalog['slot_indexes']) && is_array($narrowed_catalog['slot_indexes']) ? $narrowed_catalog['slot_indexes'] : [];
        $has_structured_section_slots = (
            $context_tag !== ''
            && isset($slot_indexes['slots_by_section_family'][$context_tag])
            && is_array($slot_indexes['slots_by_section_family'][$context_tag])
            && ! empty($slot_indexes['slots_by_section_family'][$context_tag])
        );

        if (
            ($content_role === '' || in_array($content_role, ['headline', 'title'], true))
            && $context_tag === 'hero'
            && ! $has_structured_section_slots
            && ! empty($narrowed_catalog['supports']['title'])
        ) {
            $candidates[] = [
                'target_ref' => 'core:post_title',
                'confidence' => 0.91,
                'reason' => 'hero_heading_to_post_title',
                'pattern_key' => 'hero',
            ];
        }

        if (($content_role === '' || $content_role === 'body') && ! empty($narrowed_catalog['supports']['editor'])) {
            $candidates[] = [
                'target_ref' => 'core:post_content',
                'confidence' => $has_structured_section_slots ? 0.66 : ($context_tag === 'hero' ? 0.74 : 0.78),
                'reason' => 'section_copy_to_post_content',
                'pattern_key' => $context_tag !== '' ? $context_tag : 'content',
            ];
        }

        return $candidates;
    }

    /**
     * @param string $pattern_seed
     * @param string $context_tag
     * @param string $content_role
     * @return array<int, string>
     */
    private function derive_section_unit_pattern_keys($pattern_seed, $context_tag, $content_role)
    {
        $patterns = [];
        $context_tag = sanitize_key((string) $context_tag);
        $content_role = sanitize_key((string) $content_role);

        if ($context_tag !== '') {
            $patterns[] = $context_tag;
        }

        switch ($content_role) {
            case 'headline':
                $patterns[] = 'headline';
                $patterns[] = 'title';
                break;
            case 'body':
                $patterns[] = 'content';
                $patterns[] = 'summary';
                break;
            case 'cta_label':
                $patterns[] = 'cta';
                $patterns[] = 'button';
                break;
            case 'cta_url':
                $patterns[] = 'cta';
                $patterns[] = 'link';
                break;
        }

        foreach ($this->extract_patterns_from_field_key((string) $pattern_seed) as $pattern_key) {
            $patterns[] = $pattern_key;
        }

        if (empty($patterns)) {
            $patterns[] = 'content';
        }

        return array_values(array_unique(array_filter(array_map('sanitize_key', $patterns))));
    }

    /**
     * @param string $content_role
     * @return float
     */
    private function get_section_unit_base_confidence($content_role)
    {
        switch (sanitize_key((string) $content_role)) {
            case 'headline':
                return 0.87;
            case 'cta_label':
            case 'cta_url':
                return 0.86;
            case 'body':
            default:
                return 0.82;
        }
    }

    /**
     * @param array<string, mixed> $seed
     * @param array<int, array<string, mixed>> $candidates
     * @return array<string, mixed>
     */
    private function finalize_item(array $seed, array $candidates)
    {
        $deduped = [];
        $pattern_match_count = 0;
        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || empty($candidate['target_ref'])) {
                continue;
            }

            $target_ref = sanitize_text_field((string) $candidate['target_ref']);
            $confidence = isset($candidate['confidence']) ? (float) $candidate['confidence'] : 0.0;
            $sort_score = isset($candidate['sort_score']) ? (float) $candidate['sort_score'] : $confidence;
            if (! isset($deduped[$target_ref]) || $sort_score > (float) $deduped[$target_ref]['sort_score']) {
                $deduped[$target_ref] = [
                    'target_ref' => $target_ref,
                    'confidence' => round(min(0.99, max(0.05, $confidence)), 2),
                    'sort_score' => $sort_score,
                    'reason' => isset($candidate['reason']) ? sanitize_key((string) $candidate['reason']) : '',
                    'pattern_key' => isset($candidate['pattern_key']) ? sanitize_key((string) $candidate['pattern_key']) : '',
                ];
            }

            if (! empty($candidate['reason']) && strpos((string) $candidate['reason'], 'pattern') !== false) {
                ++$pattern_match_count;
            }
        }

        uasort(
            $deduped,
            static function ($left, $right) {
                $left_confidence = isset($left['sort_score']) ? (float) $left['sort_score'] : (isset($left['confidence']) ? (float) $left['confidence'] : 0.0);
                $right_confidence = isset($right['sort_score']) ? (float) $right['sort_score'] : (isset($right['confidence']) ? (float) $right['confidence'] : 0.0);
                if ($left_confidence === $right_confidence) {
                    return strnatcasecmp(
                        isset($left['target_ref']) ? (string) $left['target_ref'] : '',
                        isset($right['target_ref']) ? (string) $right['target_ref'] : ''
                    );
                }

                return $left_confidence < $right_confidence ? 1 : -1;
            }
        );

        $candidate_rows = array_slice(array_values($deduped), 0, 6);
        $best = ! empty($candidate_rows) && isset($candidate_rows[0]['confidence']) ? (float) $candidate_rows[0]['confidence'] : 0.0;
        $raw_best = ! empty($candidate_rows) && isset($candidate_rows[0]['sort_score']) ? (float) $candidate_rows[0]['sort_score'] : $best;
        $raw_next = isset($candidate_rows[1]['sort_score']) ? (float) $candidate_rows[1]['sort_score'] : (isset($candidate_rows[1]['confidence']) ? (float) $candidate_rows[1]['confidence'] : 0.0);
        $seed['target_candidates'] = $candidate_rows;
        $seed['confidence_summary'] = [
            'best' => $best,
            'raw_best' => round($raw_best, 4),
            'margin_to_next' => $raw_best > 0.0 ? round(max(0.0, $raw_best - $raw_next), 2) : 0.0,
            'candidate_count' => count($candidate_rows),
            'pattern_match_count' => $pattern_match_count,
        ];

        return $seed;
    }

    /**
     * @param array<string, mixed> $item
     * @param string $reason
     * @return array<string, mixed>
     */
    private function build_unresolved_item(array $item, $reason)
    {
        $reason = sanitize_key((string) $reason);
        return [
            'item_id' => isset($item['item_id']) ? (string) $item['item_id'] : '',
            'item_type' => isset($item['item_type']) ? (string) $item['item_type'] : '',
            'source_refs' => isset($item['source_refs']) && is_array($item['source_refs']) ? array_values($item['source_refs']) : [],
            'reason' => $reason,
            'unresolved_class' => $this->resolve_unresolved_class($item, $reason),
            'reason_codes' => [$reason],
            'candidate_group' => isset($item['candidate_group']) ? (string) $item['candidate_group'] : '',
            'context_tag' => isset($item['context_tag']) ? sanitize_key((string) $item['context_tag']) : '',
            'route_scope' => isset($item['route_scope']) ? sanitize_key((string) $item['route_scope']) : '',
            'notes' => isset($item['notes']) && is_array($item['notes']) ? array_values($item['notes']) : [],
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param string               $reason
     * @return string
     */
    private function resolve_unresolved_class(array $item, $reason)
    {
        $reason = sanitize_key((string) $reason);
        $item_type = isset($item['item_type']) ? sanitize_key((string) $item['item_type']) : '';
        $context_tag = isset($item['context_tag']) ? sanitize_key((string) $item['context_tag']) : '';

        if ($reason === 'no_target_candidates') {
            if (in_array($item_type, ['page_title', 'page_description'], true)) {
                return 'missing_page_level_slot';
            }

            if (
                in_array($item_type, ['section', 'section_unit'], true)
                && $context_tag !== ''
                && ! in_array($context_tag, ['content', 'unknown', 'title', 'description'], true)
            ) {
                return 'missing_section_family_slot';
            }

            return 'missing_eligible_slot';
        }

        return 'missing_eligible_slot';
    }

    /**
     * @param array<string, mixed> $routing_artifact
     * @return array<string, array<string, mixed>>
     */
    private function build_route_map(array $routing_artifact)
    {
        $map = [];
        $section_routes = isset($routing_artifact['section_routes']) && is_array($routing_artifact['section_routes'])
            ? $routing_artifact['section_routes']
            : [];

        foreach ($section_routes as $route) {
            if (! is_array($route) || empty($route['section_id'])) {
                continue;
            }

            $section_id = sanitize_key((string) $route['section_id']);
            if ($section_id !== '') {
                $map[$section_id] = $route;
            }
        }

        return $map;
    }

    /**
     * @param string $context_tag
     * @param string $route_scope
     * @param string $page_intent
     * @return string
     */
    private function resolve_effective_section_context_tag($context_tag, $route_scope, $page_intent = '')
    {
        $context_tag = sanitize_key((string) $context_tag);
        $route_scope = sanitize_key((string) $route_scope);
        $page_intent = sanitize_key((string) $page_intent);

        $effective = $context_tag !== '' ? $context_tag : 'content';

        if (
            in_array($context_tag, ['', 'content', 'general', 'unknown'], true)
            && $route_scope !== ''
            && ! in_array($route_scope, ['content', 'general', 'unknown'], true)
        ) {
            $effective = $route_scope;
        }

        if (
            in_array($page_intent, ['process', 'pricing', 'conversion', 'service_overview'], true)
            && in_array($effective, ['', 'content', 'general', 'unknown', 'services', 'service', 'product', 'about'], true)
        ) {
            return $page_intent;
        }

        return $effective !== '' ? $effective : 'content';
    }

    /**
     * @param array<int, string> $pattern_keys
     * @param string $route_scope
     * @param string $page_intent
     * @return array<int, string>
     */
    private function append_route_pattern_keys(array $pattern_keys, $route_scope, $page_intent)
    {
        $route_scope = sanitize_key((string) $route_scope);
        $page_intent = sanitize_key((string) $page_intent);

        if ($route_scope !== '') {
            $pattern_keys[] = $route_scope;
        }

        if (in_array($page_intent, ['homepage', 'pricing', 'about', 'process', 'conversion', 'service_overview'], true)) {
            $pattern_keys[] = $page_intent;
        }

        foreach ($this->expand_route_pattern_keys($route_scope, $page_intent) as $route_key) {
            $pattern_keys[] = $route_key;
        }

        return array_values(array_unique(array_filter(array_map('sanitize_key', $pattern_keys))));
    }

    /**
     * @param string $route_scope
     * @param string $page_intent
     * @return array<int, string>
     */
    private function expand_route_pattern_keys($route_scope, $page_intent)
    {
        $route_scope = sanitize_key((string) $route_scope);
        $page_intent = sanitize_key((string) $page_intent);
        $expansions = [];

        $route_scope_map = [
            'hero' => ['banner'],
            'conversion' => ['cta', 'contact', 'form', 'button', 'link'],
            'product' => ['offer', 'plan', 'package'],
            'services' => ['service', 'offering', 'solution'],
            'about' => ['story', 'team', 'mission', 'values'],
        ];
        $page_intent_map = [
            'homepage' => ['hero', 'banner'],
            'pricing' => ['price', 'plans', 'plan', 'package', 'packages'],
            'about' => ['story', 'team', 'mission', 'values'],
            'process' => ['steps', 'step', 'workflow', 'approach', 'timeline'],
            'conversion' => ['cta', 'contact', 'form', 'button', 'link'],
            'service_overview' => ['service', 'services', 'offering', 'solution', 'capability'],
        ];

        if (isset($route_scope_map[$route_scope]) && is_array($route_scope_map[$route_scope])) {
            $expansions = array_merge($expansions, $route_scope_map[$route_scope]);
        }

        if (isset($page_intent_map[$page_intent]) && is_array($page_intent_map[$page_intent])) {
            $expansions = array_merge($expansions, $page_intent_map[$page_intent]);
        }

        return array_values(array_unique(array_filter(array_map('sanitize_key', $expansions))));
    }

    /**
     * @param array<string, mixed> $catalog
     * @param string               $object_key
     * @param array<string, mixed> $slot_graph_bundle
     * @return array<string, mixed>
     */
    private function build_narrowed_catalog(array $catalog, $object_key, array $slot_graph_bundle = [])
    {
        $object_key = sanitize_key((string) $object_key);
        $object_catalog = isset($catalog['object_catalog']) && is_array($catalog['object_catalog']) ? $catalog['object_catalog'] : [];
        $object_entry = isset($object_catalog[$object_key]) && is_array($object_catalog[$object_key]) ? $object_catalog[$object_key] : [];
        $supports = isset($object_entry['supports']) && is_array($object_entry['supports']) ? array_map('sanitize_key', $object_entry['supports']) : [];
        $slot_graph = isset($slot_graph_bundle['slot_graph']) && is_array($slot_graph_bundle['slot_graph']) ? $slot_graph_bundle['slot_graph'] : [];
        $slot_indexes = isset($slot_graph['indexes']) && is_array($slot_graph['indexes']) ? $slot_graph['indexes'] : [];
        $slot_lookup = isset($slot_graph['slots']) && is_array($slot_graph['slots']) ? $slot_graph['slots'] : [];

        return [
            'supports' => [
                'title' => in_array('title', $supports, true),
                'editor' => in_array('editor', $supports, true),
                'excerpt' => in_array('excerpt', $supports, true),
            ],
            'meta_refs' => $this->collect_meta_field_refs($catalog, $object_key),
            'acf_refs' => $this->collect_acf_field_refs($catalog, $object_key, $slot_graph),
            'slot_graph_status' => isset($slot_graph_bundle['status']) ? sanitize_key((string) $slot_graph_bundle['status']) : 'missing',
            'slot_graph_fingerprint' => isset($slot_graph['slot_graph_fingerprint']) ? (string) $slot_graph['slot_graph_fingerprint'] : '',
            'slot_indexes' => $slot_indexes,
            'slot_lookup' => $slot_lookup,
            'slot_pattern_refs' => $this->collect_slot_field_refs($slot_lookup, $object_key),
        ];
    }

    /**
     * @param array<string, mixed> $catalog
     * @param string $object_key
     * @return array<string, array<int, string>>
     */
    private function collect_meta_field_refs(array $catalog, $object_key)
    {
        $refs = [];
        $meta_catalog = isset($catalog['meta_catalog']) && is_array($catalog['meta_catalog']) ? $catalog['meta_catalog'] : [];
        $post_meta = isset($meta_catalog['post']) && is_array($meta_catalog['post']) ? $meta_catalog['post'] : [];

        foreach ([$object_key, 'default'] as $subtype) {
            if (! isset($post_meta[$subtype]) || ! is_array($post_meta[$subtype])) {
                continue;
            }

            foreach ($post_meta[$subtype] as $meta_key => $meta_entry) {
                if (! is_array($meta_entry)) {
                    continue;
                }

                $meta_key = sanitize_key((string) $meta_key);
                if ($meta_key === '') {
                    continue;
                }

                $target_ref = sprintf('meta:post:%s:%s', sanitize_key((string) $subtype), $meta_key);
                foreach ($this->extract_patterns_from_field_key($meta_key) as $pattern_key) {
                    if (! isset($refs[$pattern_key])) {
                        $refs[$pattern_key] = [];
                    }
                    $refs[$pattern_key][] = $target_ref;
                }
            }
        }

        return $refs;
    }

    /**
     * @param array<string, mixed> $catalog
     * @param string               $object_key
     * @param array<string, mixed> $slot_graph
     * @return array<string, array<int, string>>
     */
    private function collect_acf_field_refs(array $catalog, $object_key, array $slot_graph = [])
    {
        $slot_lookup = isset($slot_graph['slots']) && is_array($slot_graph['slots']) ? $slot_graph['slots'] : [];
        if (! empty($slot_lookup)) {
            return $this->collect_slot_field_refs($slot_lookup, $object_key);
        }

        $refs = [];
        $acf_catalog = isset($catalog['acf_catalog']) && is_array($catalog['acf_catalog']) ? $catalog['acf_catalog'] : [];
        $groups = isset($acf_catalog['groups']) && is_array($acf_catalog['groups']) ? $acf_catalog['groups'] : [];

        foreach ($groups as $group_key => $group) {
            if (! is_array($group) || ! $this->acf_group_matches_object($group, $object_key)) {
                continue;
            }

            $fields = isset($group['fields']) && is_array($group['fields']) ? $group['fields'] : [];
            foreach ($fields as $field_key => $field) {
                if (! is_array($field)) {
                    continue;
                }

                $name = isset($field['name']) ? sanitize_key((string) $field['name']) : sanitize_key((string) $field_key);
                if ($name === '') {
                    continue;
                }

                $target_ref = sprintf('acf:%s:%s', sanitize_key((string) $group_key), sanitize_key((string) $field_key));
                foreach ($this->extract_patterns_from_field_key($name) as $pattern_key) {
                    if (! isset($refs[$pattern_key])) {
                        $refs[$pattern_key] = [];
                    }
                    $refs[$pattern_key][] = $target_ref;
                }
            }
        }

        return $refs;
    }

    /**
     * @param array<string, array<string, mixed>> $slot_lookup
     * @param string                              $object_key
     * @return array<string, array<int, string>>
     */
    private function collect_slot_field_refs(array $slot_lookup, $object_key)
    {
        $refs = [];

        foreach ($slot_lookup as $target_ref => $slot) {
            if (! is_array($slot) || ! $this->slot_matches_object($slot, $object_key)) {
                continue;
            }

            foreach ($this->extract_patterns_from_slot($slot) as $pattern_key) {
                if (! isset($refs[$pattern_key])) {
                    $refs[$pattern_key] = [];
                }
                $refs[$pattern_key][] = sanitize_text_field((string) $target_ref);
            }
        }

        foreach ($refs as $pattern_key => $target_refs) {
            $refs[$pattern_key] = array_values(array_unique(array_map('strval', $target_refs)));
            sort($refs[$pattern_key]);
        }

        ksort($refs);

        return $refs;
    }

    /**
     * @param array<string, mixed> $slot
     * @param string               $object_key
     * @return bool
     */
    private function slot_matches_object(array $slot, $object_key)
    {
        $object_key = sanitize_key((string) $object_key);
        if ($object_key === '') {
            return true;
        }

        if (empty($slot['writable'])) {
            return false;
        }

        $clone_context = isset($slot['clone_context']) && is_array($slot['clone_context']) ? $slot['clone_context'] : [];
        if (! empty($clone_context['is_clone_projected']) && empty($clone_context['is_directly_writable'])) {
            return false;
        }

        $object_context = isset($slot['object_context']) && is_array($slot['object_context']) ? $slot['object_context'] : [];
        $post_types = isset($object_context['post_types']) && is_array($object_context['post_types']) ? array_map('sanitize_key', $object_context['post_types']) : [];

        return empty($post_types) || in_array($object_key, $post_types, true);
    }

    /**
     * @param array<string, mixed> $group
     * @param string $object_key
     * @return bool
     */
    private function acf_group_matches_object(array $group, $object_key)
    {
        $location = isset($group['location']) && is_array($group['location']) ? $group['location'] : [];
        if (empty($location)) {
            return true;
        }

        foreach ($location as $rule_group) {
            if (! is_array($rule_group)) {
                continue;
            }

            foreach ($rule_group as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                $parameter = isset($rule['param']) ? sanitize_key((string) $rule['param']) : '';
                $operator = isset($rule['operator']) ? sanitize_text_field((string) $rule['operator']) : '';
                $value = isset($rule['value']) ? sanitize_key((string) $rule['value']) : '';
                if ($parameter === 'post_type' && $operator === '==' && $value === $object_key) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $context_artifact
     * @return array<string, array<string, mixed>>
     */
    private function build_context_map(array $context_artifact)
    {
        $map = [];
        $items = isset($context_artifact['items']) && is_array($context_artifact['items']) ? $context_artifact['items'] : [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $source_refs = isset($item['source_refs']) && is_array($item['source_refs']) ? $item['source_refs'] : [];
            foreach ($source_refs as $source_ref) {
                $source_ref = (string) $source_ref;
                if (strpos($source_ref, 'sections.v2#') !== 0) {
                    continue;
                }

                $section_id = substr($source_ref, strlen('sections.v2#'));
                if ($section_id !== '') {
                    $map[$section_id] = $item;
                }
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $sections_artifact
     * @return array<string, array<string, mixed>>
     */
    private function build_section_outline_map(array $sections_artifact)
    {
        $map = [];
        $sections = isset($sections_artifact['sections']) && is_array($sections_artifact['sections']) ? $sections_artifact['sections'] : [];
        foreach ($sections as $section) {
            if (! is_array($section) || empty($section['section_id'])) {
                continue;
            }

            $section_id = sanitize_key((string) $section['section_id']);
            if ($section_id !== '') {
                $map[$section_id] = $section;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $elements_artifact
     * @return array<string, array<string, mixed>>
     */
    private function build_element_lookup(array $elements_artifact)
    {
        $lookup = [];
        $elements = isset($elements_artifact['elements']) && is_array($elements_artifact['elements']) ? $elements_artifact['elements'] : [];
        foreach ($elements as $element) {
            if (! is_array($element) || empty($element['element_id'])) {
                continue;
            }

            $element_id = sanitize_text_field((string) $element['element_id']);
            if ($element_id !== '') {
                $lookup[$element_id] = $element;
            }
        }

        return $lookup;
    }

    /**
     * @param string $label
     * @param array<int, string> $sample_text
     * @param string $context_tag
     * @return array<int, string>
     */
    private function derive_section_pattern_keys($label, array $sample_text, $context_tag)
    {
        $patterns = [];
        if ($context_tag !== '') {
            $patterns[] = sanitize_key((string) $context_tag);
        }

        foreach ($this->extract_patterns_from_field_key($label . ' ' . implode(' ', $sample_text)) as $pattern_key) {
            $patterns[] = $pattern_key;
        }

        if (empty($patterns)) {
            $patterns[] = 'content';
        }

        return array_values(array_unique(array_filter(array_map('sanitize_key', $patterns))));
    }

    /**
     * @param array<int, string> $pattern_keys
     * @param float              $base_confidence
     * @param array<string, mixed> $narrowed_catalog
     * @param array<string, mixed> $pattern_memory
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $item_context
     * @return array<int, array<string, mixed>>
     */
    private function collect_pattern_candidates(
        array $pattern_keys,
        $base_confidence,
        array $narrowed_catalog,
        array $pattern_memory,
        array $primary,
        array $item_context = []
    ) {
        $item_context['object_key'] = isset($item_context['object_key']) ? (string) $item_context['object_key'] : (isset($primary['object_key']) ? (string) $primary['object_key'] : '');
        $eligibility = DBVC_CC_V2_Target_Eligibility_Service::get_instance();
        $candidates = $eligibility->collect_slot_candidates($pattern_keys, $base_confidence, $narrowed_catalog, $item_context);
        $meta_refs = isset($narrowed_catalog['meta_refs']) && is_array($narrowed_catalog['meta_refs']) ? $narrowed_catalog['meta_refs'] : [];
        $acf_refs = isset($narrowed_catalog['acf_refs']) && is_array($narrowed_catalog['acf_refs']) ? $narrowed_catalog['acf_refs'] : [];

        foreach ($pattern_keys as $pattern_key) {
            $pattern_key = sanitize_key((string) $pattern_key);
            if ($pattern_key === '') {
                continue;
            }

            $pattern_refs = [];
            if (isset($meta_refs[$pattern_key]) && is_array($meta_refs[$pattern_key])) {
                $pattern_refs = array_merge($pattern_refs, $meta_refs[$pattern_key]);
            }
            if (isset($acf_refs[$pattern_key]) && is_array($acf_refs[$pattern_key])) {
                $pattern_refs = array_merge($pattern_refs, $acf_refs[$pattern_key]);
            }

            foreach (array_slice(array_values(array_unique($pattern_refs)), 0, 8) as $target_ref) {
                $candidates[] = [
                    'target_ref' => $target_ref,
                    'confidence' => $base_confidence,
                    'reason' => 'pattern_field_catalog_match',
                    'pattern_key' => $pattern_key,
                ];
            }
        }

        $object_key = isset($primary['object_key']) ? sanitize_key((string) $primary['object_key']) : '';
        $domain = isset($pattern_memory['domain']) ? (string) $pattern_memory['domain'] : '';
        if ($domain !== '' && $object_key !== '') {
            foreach (DBVC_CC_V2_Pattern_Learning_Service::get_instance()->find_matches($domain, $object_key, $pattern_keys) as $match) {
                if (! is_array($match)) {
                    continue;
                }

                $candidates[] = [
                    'target_ref' => isset($match['target_ref']) ? (string) $match['target_ref'] : '',
                    'confidence' => min(0.96, max($base_confidence, isset($match['confidence']) ? (float) $match['confidence'] : 0.0)),
                    'reason' => 'pattern_memory_match',
                    'pattern_key' => isset($match['pattern_key']) ? (string) $match['pattern_key'] : '',
                ];
            }
        }

        return $eligibility->filter_candidates($candidates, $narrowed_catalog, $item_context);
    }

    /**
     * @param array<string, mixed> $slot
     * @return array<int, string>
     */
    private function extract_patterns_from_slot(array $slot)
    {
        $parts = [];
        foreach (['acf_name', 'acf_label', 'group_name', 'group_label', 'section_family', 'slot_role', 'chain_purpose_text'] as $key) {
            if (! empty($slot[$key])) {
                $parts[] = (string) $slot[$key];
            }
        }

        foreach (['branch_name_path', 'branch_label_path'] as $path_key) {
            if (! empty($slot[$path_key]) && is_array($slot[$path_key])) {
                $parts[] = implode(' ', array_map('strval', $slot[$path_key]));
            }
        }

        $patterns = $this->extract_patterns_from_field_key(implode(' ', $parts));
        $section_family = isset($slot['section_family']) ? sanitize_key((string) $slot['section_family']) : '';
        if ($section_family !== '') {
            $patterns[] = $section_family;
        }

        return array_values(array_unique(array_filter(array_map('sanitize_key', $patterns))));
    }

    /**
     * @param string $value
     * @return array<int, string>
     */
    private function extract_patterns_from_field_key($value)
    {
        $patterns = [];
        $value = strtolower((string) $value);
        $map = [
            'title' => ['title', 'headline', 'heading', 'h1'],
            'hero' => ['hero', 'banner'],
            'description' => ['description'],
            'summary' => ['summary', 'overview'],
            'intro' => ['intro'],
            'process' => ['process', 'workflow', 'timeline', 'approach'],
            'step' => ['step', 'steps'],
            'faq' => ['faq'],
            'question' => ['question'],
            'answer' => ['answer'],
            'cta' => ['cta', 'call to action'],
            'button' => ['button'],
            'link' => ['link', 'url'],
            'contact' => ['contact'],
            'phone' => ['phone', 'tel'],
            'email' => ['email'],
            'address' => ['address'],
            'price' => ['price', 'pricing', 'cost'],
            'plan' => ['plan', 'package'],
            'service' => ['service', 'services', 'offering', 'solution', 'capability'],
            'content' => ['content', 'body', 'copy', 'text'],
        ];

        foreach ($map as $pattern_key => $needles) {
            foreach ($needles as $needle) {
                if ($needle !== '' && strpos($value, $needle) !== false) {
                    $patterns[] = $pattern_key;
                    break;
                }
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * @param array<string, mixed> $field_context_provider
     * @return array<string, mixed>
     */
    private function build_field_context_provider_summary(array $field_context_provider)
    {
        if (empty($field_context_provider)) {
            return [];
        }

        $warnings = isset($field_context_provider['warnings']) && is_array($field_context_provider['warnings'])
            ? array_values(
                array_filter(
                    array_map(
                        static function ($warning) {
                            return is_scalar($warning) ? sanitize_text_field((string) $warning) : '';
                        },
                        $field_context_provider['warnings']
                    )
                )
            )
            : [];

        return [
            'status' => isset($field_context_provider['status']) ? sanitize_key((string) $field_context_provider['status']) : 'unavailable',
            'provider' => isset($field_context_provider['provider']) ? sanitize_key((string) $field_context_provider['provider']) : '',
            'transport' => isset($field_context_provider['transport']) ? sanitize_key((string) $field_context_provider['transport']) : '',
            'contract_version' => isset($field_context_provider['contract_version']) ? sanitize_text_field((string) $field_context_provider['contract_version']) : '',
            'source_hash' => isset($field_context_provider['source_hash']) ? sanitize_text_field((string) $field_context_provider['source_hash']) : '',
            'schema_version' => isset($field_context_provider['schema_version']) ? sanitize_text_field((string) $field_context_provider['schema_version']) : '',
            'site_fingerprint' => isset($field_context_provider['site_fingerprint']) ? sanitize_text_field((string) $field_context_provider['site_fingerprint']) : '',
            'warnings' => $warnings,
        ];
    }
}
