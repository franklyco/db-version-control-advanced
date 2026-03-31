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
        $catalog = isset($catalog_bundle['catalog']) && is_array($catalog_bundle['catalog']) ? $catalog_bundle['catalog'] : [];
        $field_context = $this->extract_field_context_meta($catalog);
        $primary = isset($classification_artifact['primary_classification']) && is_array($classification_artifact['primary_classification'])
            ? $classification_artifact['primary_classification']
            : [];

        $object_key = isset($primary['object_key']) ? sanitize_key((string) $primary['object_key']) : '';
        $narrowed_catalog = $this->build_narrowed_catalog($catalog, $object_key);
        $context_map = $this->build_context_map($context_artifact);

        $content_items = [];
        $unresolved_items = [];
        $item_order = 0;

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

            ++$item_order;
            $section_item = $this->build_section_item($item_order, $section, $primary, $narrowed_catalog, $context_map, $pattern_memory);
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
            'field_context' => $field_context,
            'content_items' => $content_items,
            'unresolved_items' => $unresolved_items,
            'trace' => [
                'input_artifacts' => isset($args['input_artifacts']) && is_array($args['input_artifacts']) ? array_values($args['input_artifacts']) : [],
                'primary_object_key' => $object_key,
                'pattern_memory_ref' => isset($args['pattern_memory_ref']) ? (string) $args['pattern_memory_ref'] : '',
                'field_context_signature' => isset($field_context['signature']) ? (string) $field_context['signature'] : '',
                'field_context_source_hash' => isset($field_context['source_hash']) ? (string) $field_context['source_hash'] : '',
                'stage_budget' => DBVC_CC_V2_Contracts::get_ai_stage_budget(DBVC_CC_V2_Contracts::AI_STAGE_MAPPING_INDEX),
            ],
            'stats' => [
                'content_item_count' => count($content_items),
                'unresolved_count' => count($unresolved_items),
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
                'matched_by' => 'core_post_support',
            ];
        }

        $candidates = array_merge(
            $candidates,
            $this->collect_pattern_candidates($pattern_keys, 0.84, $narrowed_catalog, $pattern_memory, $primary)
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
                'matched_by' => 'core_post_support',
            ];
        } elseif (! empty($narrowed_catalog['supports']['editor'])) {
            $candidates[] = [
                'target_ref' => 'core:post_content',
                'confidence' => 0.68,
                'reason' => 'meta_description_to_content',
                'pattern_key' => 'description',
                'matched_by' => 'core_post_support',
            ];
        }

        $candidates = array_merge(
            $candidates,
            $this->collect_pattern_candidates($pattern_keys, 0.78, $narrowed_catalog, $pattern_memory, $primary)
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
     * @return array<string, mixed>
     */
    private function build_section_item(
        $item_order,
        array $section,
        array $primary,
        array $narrowed_catalog,
        array $context_map,
        array $pattern_memory
    ) {
        $section_id = isset($section['section_id']) ? sanitize_key((string) $section['section_id']) : '';
        $label = isset($section['label']) ? sanitize_text_field((string) $section['label']) : '';
        $sample_text = isset($section['sample_text']) && is_array($section['sample_text']) ? array_values($section['sample_text']) : [];
        $context_item = isset($context_map[$section_id]) && is_array($context_map[$section_id]) ? $context_map[$section_id] : [];
        $context_tag = isset($context_item['context_tag']) ? sanitize_key((string) $context_item['context_tag']) : 'content';
        $pattern_keys = $this->derive_section_pattern_keys($label, $sample_text, $context_tag);

        $candidates = $this->build_section_core_candidates($context_tag, $narrowed_catalog);
        $candidates = array_merge(
            $candidates,
            $this->collect_pattern_candidates($pattern_keys, 0.82, $narrowed_catalog, $pattern_memory, $primary)
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
                'context_tag' => $context_tag,
                'value_preview' => implode(' ', array_slice($sample_text, 0, 2)),
                'pattern_keys' => $pattern_keys,
                'notes' => array_values(array_filter([
                    $label !== '' ? 'Section label: ' . $label : '',
                    ! empty($context_item['rationale']) ? 'Context signal: ' . sanitize_text_field((string) $context_item['rationale']) : '',
                ])),
            ],
            $candidates
        );
    }

    /**
     * @param string $context_tag
     * @param array<string, mixed> $narrowed_catalog
     * @return array<int, array<string, mixed>>
     */
    private function build_section_core_candidates($context_tag, array $narrowed_catalog)
    {
        $context_tag = sanitize_key((string) $context_tag);
        $candidates = [];

        if ($context_tag === 'hero' && ! empty($narrowed_catalog['supports']['title'])) {
            $candidates[] = [
                'target_ref' => 'core:post_title',
                'confidence' => 0.91,
                'reason' => 'hero_heading_to_post_title',
                'pattern_key' => 'hero',
                'matched_by' => 'core_post_support',
            ];
        }

        if (! empty($narrowed_catalog['supports']['editor'])) {
            $candidates[] = [
                'target_ref' => 'core:post_content',
                'confidence' => $context_tag === 'hero' ? 0.74 : 0.78,
                'reason' => 'section_copy_to_post_content',
                'pattern_key' => $context_tag !== '' ? $context_tag : 'content',
                'matched_by' => 'core_post_support',
            ];
        }

        return $candidates;
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
            if (! isset($deduped[$target_ref]) || $confidence > (float) $deduped[$target_ref]['confidence']) {
                $deduped[$target_ref] = [
                    'target_ref' => $target_ref,
                    'confidence' => round(min(0.99, max(0.05, $confidence)), 2),
                    'reason' => isset($candidate['reason']) ? sanitize_key((string) $candidate['reason']) : '',
                    'pattern_key' => isset($candidate['pattern_key']) ? sanitize_key((string) $candidate['pattern_key']) : '',
                    'matched_by' => isset($candidate['matched_by']) ? sanitize_key((string) $candidate['matched_by']) : '',
                    'resolved_from' => isset($candidate['resolved_from']) ? sanitize_key((string) $candidate['resolved_from']) : '',
                    'field_context_status' => isset($candidate['field_context_status']) ? sanitize_key((string) $candidate['field_context_status']) : '',
                    'provider_contract_version' => isset($candidate['provider_contract_version']) ? absint($candidate['provider_contract_version']) : 0,
                    'source_hash' => isset($candidate['source_hash']) ? sanitize_text_field((string) $candidate['source_hash']) : '',
                    'transport' => isset($candidate['transport']) ? sanitize_key((string) $candidate['transport']) : '',
                ];
            }

            if (! empty($candidate['reason']) && strpos((string) $candidate['reason'], 'pattern') !== false) {
                ++$pattern_match_count;
            }
        }

        uasort(
            $deduped,
            static function ($left, $right) {
                $left_confidence = isset($left['confidence']) ? (float) $left['confidence'] : 0.0;
                $right_confidence = isset($right['confidence']) ? (float) $right['confidence'] : 0.0;
                if ($left_confidence === $right_confidence) {
                    return strnatcasecmp(
                        isset($left['target_ref']) ? (string) $left['target_ref'] : '',
                        isset($right['target_ref']) ? (string) $right['target_ref'] : ''
                    );
                }

                return $left_confidence < $right_confidence ? 1 : -1;
            }
        );

        $candidate_rows = array_values($deduped);
        $best = ! empty($candidate_rows) && isset($candidate_rows[0]['confidence']) ? (float) $candidate_rows[0]['confidence'] : 0.0;
        $seed['target_candidates'] = $candidate_rows;
        $seed['confidence_summary'] = [
            'best' => $best,
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
        return [
            'item_id' => isset($item['item_id']) ? (string) $item['item_id'] : '',
            'item_type' => isset($item['item_type']) ? (string) $item['item_type'] : '',
            'source_refs' => isset($item['source_refs']) && is_array($item['source_refs']) ? array_values($item['source_refs']) : [],
            'reason' => sanitize_key((string) $reason),
            'candidate_group' => isset($item['candidate_group']) ? (string) $item['candidate_group'] : '',
            'notes' => isset($item['notes']) && is_array($item['notes']) ? array_values($item['notes']) : [],
        ];
    }

    /**
     * @param array<string, mixed> $catalog
     * @param string $object_key
     * @return array<string, mixed>
     */
    private function build_narrowed_catalog(array $catalog, $object_key)
    {
        $object_key = sanitize_key((string) $object_key);
        $object_catalog = isset($catalog['object_catalog']) && is_array($catalog['object_catalog']) ? $catalog['object_catalog'] : [];
        $object_entry = isset($object_catalog[$object_key]) && is_array($object_catalog[$object_key]) ? $object_catalog[$object_key] : [];
        $supports = isset($object_entry['supports']) && is_array($object_entry['supports']) ? array_map('sanitize_key', $object_entry['supports']) : [];

        return [
            'supports' => [
                'title' => in_array('title', $supports, true),
                'editor' => in_array('editor', $supports, true),
                'excerpt' => in_array('excerpt', $supports, true),
            ],
            'field_context' => $this->extract_field_context_meta($catalog),
            'meta_refs' => $this->collect_meta_field_refs($catalog, $object_key),
            'acf_refs' => $this->collect_acf_field_refs($catalog, $object_key),
        ];
    }

    /**
     * @param array<string, mixed> $catalog
     * @param string $object_key
     * @return array<string, array<int, array<string, mixed>>>
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
                    $refs[$pattern_key][] = $this->build_target_ref_row($target_ref, 'meta_field_key');
                }
            }
        }

        return $refs;
    }

    /**
     * @param array<string, mixed> $catalog
     * @param string $object_key
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function collect_acf_field_refs(array $catalog, $object_key)
    {
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
                $field_context = isset($field['field_context']) && is_array($field['field_context']) ? $field['field_context'] : [];
                $field_context_summary = $this->build_field_context_entry_summary($field_context);
                $pattern_sources = [
                    [
                        'value' => $name,
                        'matched_by' => 'acf_field_name',
                    ],
                ];
                if (! empty($field_context['name_path'])) {
                    $pattern_sources[] = [
                        'value' => (string) $field_context['name_path'],
                        'matched_by' => 'field_context_name_path',
                    ];
                }
                if (! empty($field_context['resolved_purpose'])) {
                    $pattern_sources[] = [
                        'value' => (string) $field_context['resolved_purpose'],
                        'matched_by' => 'field_context_resolved_purpose',
                    ];
                } elseif (! empty($field_context['default_purpose'])) {
                    $pattern_sources[] = [
                        'value' => (string) $field_context['default_purpose'],
                        'matched_by' => 'field_context_default_purpose',
                    ];
                } elseif (! empty($field_context['legacy']['gardenai_field_purpose'])) {
                    $pattern_sources[] = [
                        'value' => (string) $field_context['legacy']['gardenai_field_purpose'],
                        'matched_by' => 'field_context_legacy_purpose',
                    ];
                } elseif (! empty($field_context['effective_purpose'])) {
                    $pattern_sources[] = [
                        'value' => (string) $field_context['effective_purpose'],
                        'matched_by' => 'field_context_effective_purpose',
                    ];
                }

                foreach ($pattern_sources as $pattern_source) {
                    if (! is_array($pattern_source) || empty($pattern_source['value'])) {
                        continue;
                    }

                    foreach ($this->extract_patterns_from_field_key((string) $pattern_source['value']) as $pattern_key) {
                        if (! isset($refs[$pattern_key])) {
                            $refs[$pattern_key] = [];
                        }
                        $refs[$pattern_key][] = $this->build_target_ref_row(
                            $target_ref,
                            isset($pattern_source['matched_by']) ? (string) $pattern_source['matched_by'] : 'acf_field_name',
                            $field_context_summary
                        );
                    }
                }
            }
        }

        return $refs;
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
     * @param float $base_confidence
     * @param array<string, mixed> $narrowed_catalog
     * @param array<string, mixed> $pattern_memory
     * @param array<string, mixed> $primary
     * @return array<int, array<string, mixed>>
     */
    private function collect_pattern_candidates(
        array $pattern_keys,
        $base_confidence,
        array $narrowed_catalog,
        array $pattern_memory,
        array $primary
    ) {
        $candidates = [];
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

            $seen = [];
            foreach ($pattern_refs as $pattern_ref) {
                if (! is_array($pattern_ref) || empty($pattern_ref['target_ref'])) {
                    continue;
                }

                $target_ref = sanitize_text_field((string) $pattern_ref['target_ref']);
                $matched_by = isset($pattern_ref['matched_by']) ? sanitize_key((string) $pattern_ref['matched_by']) : 'pattern_field_catalog_match';
                $seen_key = $target_ref . '|' . $matched_by;
                if (isset($seen[$seen_key])) {
                    continue;
                }

                $seen[$seen_key] = true;
                $candidates[] = [
                    'target_ref' => $target_ref,
                    'confidence' => $base_confidence,
                    'reason' => 'pattern_field_catalog_match',
                    'pattern_key' => $pattern_key,
                    'matched_by' => $matched_by,
                    'resolved_from' => isset($pattern_ref['resolved_from']) ? sanitize_key((string) $pattern_ref['resolved_from']) : '',
                    'field_context_status' => isset($pattern_ref['field_context_status']) ? sanitize_key((string) $pattern_ref['field_context_status']) : '',
                    'provider_contract_version' => isset($pattern_ref['provider_contract_version']) ? absint($pattern_ref['provider_contract_version']) : 0,
                    'source_hash' => isset($pattern_ref['source_hash']) ? sanitize_text_field((string) $pattern_ref['source_hash']) : '',
                    'transport' => isset($pattern_ref['transport']) ? sanitize_key((string) $pattern_ref['transport']) : '',
                ];

                if (count($seen) >= 8) {
                    break;
                }
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
                    'matched_by' => 'pattern_memory',
                ];
            }
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $catalog
     * @return array<string, mixed>
     */
    private function extract_field_context_meta(array $catalog)
    {
        $acf_catalog = isset($catalog['acf_catalog']) && is_array($catalog['acf_catalog']) ? $catalog['acf_catalog'] : [];
        $field_context = isset($acf_catalog['field_context']) && is_array($acf_catalog['field_context']) ? $acf_catalog['field_context'] : [];
        $provider = isset($field_context['provider']) && is_array($field_context['provider']) ? $field_context['provider'] : [];
        $catalog_meta = isset($field_context['catalog_meta']) && is_array($field_context['catalog_meta']) ? $field_context['catalog_meta'] : [];

        return [
            'available' => ! empty($field_context['available']),
            'profile' => isset($field_context['profile']) ? sanitize_key((string) $field_context['profile']) : 'mapping',
            'transport' => isset($field_context['transport']) ? sanitize_key((string) $field_context['transport']) : 'local',
            'provider_contract_version' => isset($provider['contract_version']) ? absint($provider['contract_version']) : 0,
            'source_hash' => isset($catalog_meta['source_hash']) ? sanitize_text_field((string) $catalog_meta['source_hash']) : '',
            'status' => isset($catalog_meta['status']) ? sanitize_key((string) $catalog_meta['status']) : '',
            'signature' => isset($field_context['signature']) ? sanitize_text_field((string) $field_context['signature']) : '',
            'hints_enabled' => ! empty($field_context['hints_enabled']),
            'consumer_policy' => isset($field_context['consumer_policy']) && is_array($field_context['consumer_policy']) ? $field_context['consumer_policy'] : [],
            'diagnostics' => isset($field_context['diagnostics']) && is_array($field_context['diagnostics']) ? $field_context['diagnostics'] : [],
        ];
    }

    /**
     * @param string               $target_ref
     * @param string               $matched_by
     * @param array<string, mixed> $field_context_summary
     * @return array<string, mixed>
     */
    private function build_target_ref_row($target_ref, $matched_by, array $field_context_summary = [])
    {
        return [
            'target_ref' => sanitize_text_field((string) $target_ref),
            'matched_by' => sanitize_key((string) $matched_by),
            'resolved_from' => isset($field_context_summary['resolved_from']) ? sanitize_key((string) $field_context_summary['resolved_from']) : '',
            'field_context_status' => isset($field_context_summary['field_context_status']) ? sanitize_key((string) $field_context_summary['field_context_status']) : '',
            'provider_contract_version' => isset($field_context_summary['provider_contract_version']) ? absint($field_context_summary['provider_contract_version']) : 0,
            'source_hash' => isset($field_context_summary['source_hash']) ? sanitize_text_field((string) $field_context_summary['source_hash']) : '',
            'transport' => isset($field_context_summary['transport']) ? sanitize_key((string) $field_context_summary['transport']) : '',
        ];
    }

    /**
     * @param array<string, mixed> $field_context
     * @return array<string, mixed>
     */
    private function build_field_context_entry_summary(array $field_context)
    {
        $context = isset($field_context['context']) && is_array($field_context['context']) ? $field_context['context'] : [];
        $provider = isset($context['provider']) && is_array($context['provider']) ? $context['provider'] : [];
        $catalog_meta = isset($context['catalog_meta']) && is_array($context['catalog_meta']) ? $context['catalog_meta'] : [];
        $status_meta = isset($field_context['status_meta']) && is_array($field_context['status_meta']) ? $field_context['status_meta'] : [];

        return [
            'resolved_from' => isset($field_context['resolved_from']) ? sanitize_key((string) $field_context['resolved_from']) : '',
            'field_context_status' => isset($status_meta['code']) ? sanitize_key((string) $status_meta['code']) : '',
            'provider_contract_version' => isset($provider['contract_version']) ? absint($provider['contract_version']) : 0,
            'source_hash' => isset($catalog_meta['source_hash']) ? sanitize_text_field((string) $catalog_meta['source_hash']) : '',
            'transport' => isset($context['transport']) ? sanitize_key((string) $context['transport']) : '',
        ];
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
            'title' => ['title', 'headline', 'heading'],
            'hero' => ['hero', 'banner'],
            'description' => ['description'],
            'summary' => ['summary', 'overview'],
            'intro' => ['intro'],
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
}
