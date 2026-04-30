<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Target_Eligibility_Service
{
    /**
     * @var DBVC_CC_V2_Target_Eligibility_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Target_Eligibility_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param array<int, string>         $pattern_keys
     * @param float                      $base_confidence
     * @param array<string, mixed>       $narrowed_catalog
     * @param array<string, mixed>       $item_context
     * @return array<int, array<string, mixed>>
     */
    public function collect_slot_candidates(array $pattern_keys, $base_confidence, array $narrowed_catalog, array $item_context = [])
    {
        $slot_lookup = isset($narrowed_catalog['slot_lookup']) && is_array($narrowed_catalog['slot_lookup'])
            ? $narrowed_catalog['slot_lookup']
            : [];
        $slot_pattern_refs = isset($narrowed_catalog['slot_pattern_refs']) && is_array($narrowed_catalog['slot_pattern_refs'])
            ? $narrowed_catalog['slot_pattern_refs']
            : [];
        $slot_indexes = isset($narrowed_catalog['slot_indexes']) && is_array($narrowed_catalog['slot_indexes'])
            ? $narrowed_catalog['slot_indexes']
            : [];

        if (empty($slot_lookup) || empty($slot_pattern_refs)) {
            return [];
        }

        $preferred_section_family = $this->resolve_preferred_section_family($item_context);
        $preferred_roles = $this->resolve_preferred_roles($item_context);
        $is_section_item = isset($item_context['item_type']) && sanitize_key((string) $item_context['item_type']) === 'section';
        $strict_section_family = false;

        if (
            $is_section_item
            && $preferred_section_family !== ''
            && $preferred_section_family !== 'content'
            && isset($slot_indexes['slots_by_section_family'][$preferred_section_family])
            && is_array($slot_indexes['slots_by_section_family'][$preferred_section_family])
            && ! empty($slot_indexes['slots_by_section_family'][$preferred_section_family])
        ) {
            $strict_section_family = true;
        }

        $candidate_matches = [];

        foreach ($pattern_keys as $pattern_key) {
            $pattern_key = sanitize_key((string) $pattern_key);
            if ($pattern_key === '' || ! isset($slot_pattern_refs[$pattern_key]) || ! is_array($slot_pattern_refs[$pattern_key])) {
                continue;
            }

            foreach ($slot_pattern_refs[$pattern_key] as $target_ref) {
                $target_ref = sanitize_text_field((string) $target_ref);
                if ($target_ref === '' || ! isset($slot_lookup[$target_ref]) || ! is_array($slot_lookup[$target_ref])) {
                    continue;
                }

                $slot = $slot_lookup[$target_ref];
                if (! $this->is_slot_eligible($slot, $item_context, $strict_section_family)) {
                    continue;
                }

                if (! isset($candidate_matches[$target_ref])) {
                    $candidate_matches[$target_ref] = [
                        'slot' => $slot,
                        'pattern_keys' => [],
                    ];
                }

                $candidate_matches[$target_ref]['pattern_keys'][$pattern_key] = true;
            }
        }

        $candidates = [];
        foreach ($candidate_matches as $target_ref => $match) {
            $slot = isset($match['slot']) && is_array($match['slot']) ? $match['slot'] : [];
            if (empty($slot)) {
                continue;
            }

            $matched_pattern_keys = array_keys(isset($match['pattern_keys']) && is_array($match['pattern_keys']) ? $match['pattern_keys'] : []);
            $confidence = (float) $base_confidence;
            $confidence += min(0.08, 0.03 * count($matched_pattern_keys));

            $section_family = isset($slot['section_family']) ? sanitize_key((string) $slot['section_family']) : '';
            if ($preferred_section_family !== '' && $section_family === $preferred_section_family) {
                $confidence += 0.08;
            }

            $slot_role = isset($slot['slot_role']) ? sanitize_key((string) $slot['slot_role']) : '';
            if (! empty($preferred_roles) && in_array($slot_role, $preferred_roles, true)) {
                $confidence += 0.06;
            }
            $confidence += $this->calculate_content_role_signal($slot, $item_context);
            $confidence += $this->calculate_purpose_bias_signal($slot, $item_context);
            $confidence += $this->calculate_structure_signal($slot, $item_context);
            $confidence += $this->calculate_item_type_signal($slot, $item_context);

            $provider_trace = isset($slot['provider_trace']) && is_array($slot['provider_trace']) ? $slot['provider_trace'] : [];
            $provider_status = isset($provider_trace['status']) ? sanitize_key((string) $provider_trace['status']) : '';
            if (in_array($provider_status, ['degraded', 'stale'], true)) {
                $confidence -= 0.04;
            } elseif ($provider_status === 'unavailable') {
                $confidence -= 0.08;
            }

            $clone_context = isset($slot['clone_context']) && is_array($slot['clone_context']) ? $slot['clone_context'] : [];
            if (! empty($clone_context['is_clone_projected'])) {
                $confidence -= 0.03;
            }

            $raw_confidence = max(0.05, $confidence);
            $candidates[] = [
                'target_ref' => $target_ref,
                'confidence' => round(min(0.99, $raw_confidence), 2),
                'sort_score' => $raw_confidence,
                'reason' => 'slot_graph_context_match',
                'pattern_key' => ! empty($matched_pattern_keys) ? (string) reset($matched_pattern_keys) : '',
                'matched_pattern_keys' => $matched_pattern_keys,
                'section_family' => $section_family,
                'slot_role' => $slot_role,
            ];
        }

        return $candidates;
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @param array<string, mixed>             $narrowed_catalog
     * @param array<string, mixed>             $item_context
     * @return array<int, array<string, mixed>>
     */
    public function filter_candidates(array $candidates, array $narrowed_catalog, array $item_context = [])
    {
        $slot_lookup = isset($narrowed_catalog['slot_lookup']) && is_array($narrowed_catalog['slot_lookup'])
            ? $narrowed_catalog['slot_lookup']
            : [];
        $filtered = [];
        $strict_section_family = false;
        $preferred_section_family = $this->resolve_preferred_section_family($item_context);

        if (
            isset($item_context['item_type'])
            && sanitize_key((string) $item_context['item_type']) === 'section'
            && $preferred_section_family !== ''
            && $preferred_section_family !== 'content'
        ) {
            $strict_section_family = true;
        }

        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || empty($candidate['target_ref'])) {
                continue;
            }

            $target_ref = sanitize_text_field((string) $candidate['target_ref']);
            if (strpos($target_ref, 'acf:') !== 0) {
                $filtered[] = $candidate;
                continue;
            }

            if (! isset($slot_lookup[$target_ref]) || ! is_array($slot_lookup[$target_ref])) {
                continue;
            }

            if (! $this->is_slot_eligible($slot_lookup[$target_ref], $item_context, $strict_section_family)) {
                continue;
            }

            $filtered[] = $candidate;
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $slot
     * @param array<string, mixed> $item_context
     * @param bool $strict_section_family
     * @return bool
     */
    private function is_slot_eligible(array $slot, array $item_context, $strict_section_family)
    {
        if (empty($slot['writable'])) {
            return false;
        }

        $clone_context = isset($slot['clone_context']) && is_array($slot['clone_context']) ? $slot['clone_context'] : [];
        if (! empty($clone_context['is_clone_projected']) && empty($clone_context['is_directly_writable'])) {
            return false;
        }

        $object_key = isset($item_context['object_key']) ? sanitize_key((string) $item_context['object_key']) : '';
        $object_context = isset($slot['object_context']) && is_array($slot['object_context']) ? $slot['object_context'] : [];
        $post_types = isset($object_context['post_types']) && is_array($object_context['post_types']) ? array_values($object_context['post_types']) : [];
        $taxonomies = isset($object_context['taxonomies']) && is_array($object_context['taxonomies']) ? array_values($object_context['taxonomies']) : [];
        $options_pages = isset($object_context['options_pages']) && is_array($object_context['options_pages']) ? array_values($object_context['options_pages']) : [];
        if ($object_key !== '' && ! empty($post_types) && ! in_array($object_key, array_map('sanitize_key', $post_types), true)) {
            return false;
        }
        if ($object_key !== '' && (! empty($taxonomies) || ! empty($options_pages))) {
            return false;
        }

        if ($strict_section_family) {
            $preferred_section_family = $this->resolve_preferred_section_family($item_context);
            $slot_section_family = isset($slot['section_family']) ? sanitize_key((string) $slot['section_family']) : '';
            if ($preferred_section_family !== '' && $slot_section_family !== $preferred_section_family) {
                return false;
            }
        }

        $content_role = isset($item_context['content_role']) ? sanitize_key((string) $item_context['content_role']) : '';
        if ($content_role !== '') {
            $preferred_roles = $this->resolve_preferred_roles($item_context);
            $slot_role = isset($slot['slot_role']) ? sanitize_key((string) $slot['slot_role']) : '';
            if (! empty($preferred_roles) && ! in_array($slot_role, $preferred_roles, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $slot
     * @param array<string, mixed> $item_context
     * @return float
     */
    private function calculate_content_role_signal(array $slot, array $item_context)
    {
        $content_role = isset($item_context['content_role']) ? sanitize_key((string) $item_context['content_role']) : '';
        if ($content_role === '') {
            return 0.0;
        }

        $haystack_parts = [
            isset($slot['acf_name']) ? (string) $slot['acf_name'] : '',
            isset($slot['acf_label']) ? (string) $slot['acf_label'] : '',
            isset($slot['slot_role']) ? (string) $slot['slot_role'] : '',
            isset($slot['section_family']) ? (string) $slot['section_family'] : '',
            isset($slot['chain_purpose_text']) ? (string) $slot['chain_purpose_text'] : '',
        ];
        if (isset($slot['branch_name_path']) && is_array($slot['branch_name_path'])) {
            $haystack_parts = array_merge($haystack_parts, array_values($slot['branch_name_path']));
        }
        if (isset($slot['branch_label_path']) && is_array($slot['branch_label_path'])) {
            $haystack_parts = array_merge($haystack_parts, array_values($slot['branch_label_path']));
        }

        $haystack = strtolower(implode(' ', array_map('strval', $haystack_parts)));
        if ($haystack === '') {
            return 0.0;
        }

        $keyword_sets = [
            'headline' => [
                'positive' => ['headline', 'heading', 'h1', 'title'],
                'negative' => ['label', 'eyebrow', 'description', 'body', 'summary', 'button', 'cta', 'link', 'url', 'card', 'announcement', 'message', 'popup'],
            ],
            'body' => [
                'positive' => ['description', 'body', 'summary', 'supporting', 'support', 'intro', 'overview', 'copy', 'text', 'blurb'],
                'negative' => ['label', 'eyebrow', 'headline', 'heading', 'h1', 'button', 'cta', 'url', 'link', 'card', 'popup', 'announcement', 'message'],
            ],
            'cta_label' => [
                'positive' => ['button', 'cta', 'call-to-action', 'call to action', 'label'],
                'negative' => ['url', 'destination', 'headline', 'description', 'body', 'popup', 'message'],
            ],
            'cta_url' => [
                'positive' => ['url', 'link', 'destination', 'href'],
                'negative' => ['button text', 'label', 'headline', 'description', 'body', 'message'],
            ],
        ];

        if (! isset($keyword_sets[$content_role])) {
            return 0.0;
        }

        $signal = 0.0;
        foreach ($keyword_sets[$content_role]['positive'] as $needle) {
            if ($needle !== '' && strpos($haystack, $needle) !== false) {
                $signal += 0.03;
            }
        }
        foreach ($keyword_sets[$content_role]['negative'] as $needle) {
            if ($needle !== '' && strpos($haystack, $needle) !== false) {
                $signal -= 0.03;
            }
        }

        return max(-0.09, min(0.09, $signal));
    }

    /**
     * @param array<string, mixed> $slot
     * @param array<string, mixed> $item_context
     * @return float
     */
    private function calculate_purpose_bias_signal(array $slot, array $item_context)
    {
        $content_role = isset($item_context['content_role']) ? sanitize_key((string) $item_context['content_role']) : '';
        if ($content_role === '') {
            return 0.0;
        }

        $purpose_haystack = strtolower(
            trim(
                implode(
                    ' ',
                    array_filter(
                        [
                            isset($slot['chain_purpose_text']) ? (string) $slot['chain_purpose_text'] : '',
                            isset($slot['acf_label']) ? (string) $slot['acf_label'] : '',
                            isset($slot['acf_name']) ? (string) $slot['acf_name'] : '',
                        ]
                    )
                )
            )
        );
        if ($purpose_haystack === '') {
            return 0.0;
        }

        $keyword_sets = [
            'headline' => [
                'positive' => ['headline', 'heading', 'h1', 'core promise', 'primary hero'],
                'negative' => ['description', 'announcement', 'card', 'popup', 'button', 'link', 'label'],
            ],
            'body' => [
                'positive' => ['description', 'supporting', 'clarifies', 'explains', 'value proposition', 'relevance', 'expectation', 'overview', 'summary', 'body copy', 'blurb'],
                'negative' => ['announcement', 'message', 'card', 'popup', 'button', 'link', 'destination', 'anchor', 'eyebrow', 'label', 'topic', 'category'],
            ],
            'cta_label' => [
                'positive' => ['button', 'call-to-action', 'call to action', 'label', 'cta'],
                'negative' => ['description', 'message', 'announcement', 'destination url'],
            ],
            'cta_url' => [
                'positive' => ['destination url', 'destination link', 'link target', 'href', 'url'],
                'negative' => ['description', 'message', 'announcement', 'button label'],
            ],
        ];

        if (! isset($keyword_sets[$content_role])) {
            return 0.0;
        }

        $signal = 0.0;
        foreach ($keyword_sets[$content_role]['positive'] as $needle) {
            if ($needle !== '' && strpos($purpose_haystack, $needle) !== false) {
                $signal += 0.04;
            }
        }
        foreach ($keyword_sets[$content_role]['negative'] as $needle) {
            if ($needle !== '' && strpos($purpose_haystack, $needle) !== false) {
                $signal -= 0.04;
            }
        }

        return max(-0.16, min(0.16, $signal));
    }

    /**
     * @param array<string, mixed> $slot
     * @param array<string, mixed> $item_context
     * @return float
     */
    private function calculate_structure_signal(array $slot, array $item_context)
    {
        $content_role = isset($item_context['content_role']) ? sanitize_key((string) $item_context['content_role']) : '';
        if ($content_role === '') {
            return 0.0;
        }

        $branch_depth = isset($slot['branch_name_path']) && is_array($slot['branch_name_path'])
            ? count($slot['branch_name_path'])
            : 0;
        $field_type = isset($slot['type']) ? sanitize_key((string) $slot['type']) : '';
        $source_preview = isset($item_context['source_preview']) ? trim((string) $item_context['source_preview']) : '';
        $source_length = strlen($source_preview);
        $semantic_haystack = strtolower(
            trim(
                implode(
                    ' ',
                    array_filter(
                        [
                            isset($slot['acf_name']) ? (string) $slot['acf_name'] : '',
                            isset($slot['acf_label']) ? (string) $slot['acf_label'] : '',
                            isset($slot['chain_purpose_text']) ? (string) $slot['chain_purpose_text'] : '',
                        ]
                    )
                )
            )
        );

        $signal = 0.0;
        if (in_array($content_role, ['headline', 'body'], true) && $branch_depth > 1) {
            $signal -= min(0.12, 0.04 * (float) ($branch_depth - 1));
        }

        if ($content_role === 'body') {
            if (in_array($field_type, ['link', 'url'], true)) {
                $signal -= 0.22;
            } elseif ($field_type === 'text' && $source_length >= 70) {
                $signal -= 0.06;
            } elseif (in_array($field_type, ['textarea', 'wysiwyg'], true) && $source_length >= 40) {
                $signal += 0.04;
            }

            if ($branch_depth <= 1 && strpos($semantic_haystack, 'hero description') !== false) {
                $signal += 0.06;
            }

            if ($branch_depth > 1 && preg_match('/\b(card|anchor|popup|modal)\b/', $semantic_haystack) === 1) {
                $signal -= 0.08;
            }
        }

        if ($content_role === 'headline') {
            if (in_array($field_type, ['text', 'textarea', 'wysiwyg'], true)) {
                $signal += 0.02;
            }
            if ($branch_depth <= 1) {
                $signal += 0.02;
            }
            if ($branch_depth > 1 && preg_match('/\b(card|anchor|popup|modal)\b/', $semantic_haystack) === 1) {
                $signal -= 0.06;
            }
        }

        if ($content_role === 'cta_url' && in_array($field_type, ['link', 'url'], true)) {
            $signal += 0.08;
        }

        return max(-0.22, min(0.12, $signal));
    }

    /**
     * @param array<string, mixed> $slot
     * @param array<string, mixed> $item_context
     * @return float
     */
    private function calculate_item_type_signal(array $slot, array $item_context)
    {
        $item_type = isset($item_context['item_type']) ? sanitize_key((string) $item_context['item_type']) : '';
        $section_family = isset($slot['section_family']) ? sanitize_key((string) $slot['section_family']) : '';

        if ($item_type === 'page_description') {
            if ($section_family === 'hero') {
                return -0.2;
            }

            if (in_array($section_family, ['content', 'intro'], true)) {
                return 0.02;
            }
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $item_context
     * @return string
     */
    private function resolve_preferred_section_family(array $item_context)
    {
        foreach (['section_family', 'context_tag'] as $key) {
            if (! empty($item_context[$key])) {
                return sanitize_key((string) $item_context[$key]);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $item_context
     * @return array<int, string>
     */
    private function resolve_preferred_roles(array $item_context)
    {
        $content_role = isset($item_context['content_role']) ? sanitize_key((string) $item_context['content_role']) : '';
        switch ($content_role) {
            case 'headline':
                return ['headline', 'subheadline'];
            case 'body':
                return ['body', 'rich_text', 'subheadline'];
            case 'cta_label':
                return ['cta_label'];
            case 'cta_url':
                return ['cta_url', 'link'];
        }

        $item_type = isset($item_context['item_type']) ? sanitize_key((string) $item_context['item_type']) : '';
        $context_tag = isset($item_context['context_tag']) ? sanitize_key((string) $item_context['context_tag']) : '';

        if ($item_type === 'page_title') {
            return ['headline', 'subheadline'];
        }

        if ($item_type === 'page_description') {
            return ['body', 'rich_text', 'subheadline'];
        }

        switch ($context_tag) {
            case 'hero':
                return ['headline', 'subheadline', 'body', 'rich_text', 'cta_label', 'image'];
            case 'faq':
                return ['headline', 'body', 'rich_text'];
            case 'contact':
                return ['body', 'rich_text', 'link'];
            case 'cta':
                return ['cta_label', 'cta_url', 'headline', 'body'];
            case 'media':
                return ['image', 'video'];
            default:
                return ['headline', 'body', 'rich_text', 'image', 'cta_label'];
        }
    }
}
