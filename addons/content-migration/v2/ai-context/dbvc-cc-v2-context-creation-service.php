<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Context_Creation_Service
{
    /**
     * @var DBVC_CC_V2_Context_Creation_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Context_Creation_Service
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
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function build_artifact(array $page_context, array $args = [])
    {
        $runtime = DBVC_CC_V2_Contracts::get_ai_runtime_settings();
        $raw_artifact = isset($page_context['raw_artifact']) && is_array($page_context['raw_artifact']) ? $page_context['raw_artifact'] : [];
        $source_normalization = isset($page_context['source_normalization_artifact']) && is_array($page_context['source_normalization_artifact']) ? $page_context['source_normalization_artifact'] : [];
        $sections_artifact = isset($page_context['sections_artifact']) && is_array($page_context['sections_artifact']) ? $page_context['sections_artifact'] : [];
        $elements_artifact = isset($page_context['elements_artifact']) && is_array($page_context['elements_artifact']) ? $page_context['elements_artifact'] : [];

        $items = $this->build_items($raw_artifact, $source_normalization, $sections_artifact, $elements_artifact);
        $summary = $this->build_summary($raw_artifact, $items, $sections_artifact);

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'context-creation.v1',
            'journey_id' => isset($raw_artifact['journey_id']) ? (string) $raw_artifact['journey_id'] : '',
            'page_id' => isset($raw_artifact['page_id']) ? (string) $raw_artifact['page_id'] : '',
            'source_url' => isset($raw_artifact['source_url']) ? (string) $raw_artifact['source_url'] : '',
            'generated_at' => current_time('c'),
            'prompt_version' => isset($runtime['prompt_version']) ? (string) $runtime['prompt_version'] : 'v1',
            'model' => isset($runtime['model']) ? (string) $runtime['model'] : 'gpt-4o-mini',
            'status' => empty($items) ? 'completed_with_warnings' : 'completed',
            'items' => $items,
            'summary' => $summary,
            'trace' => [
                'input_artifacts' => isset($args['input_artifacts']) && is_array($args['input_artifacts']) ? array_values($args['input_artifacts']) : [],
                'source_fingerprint' => isset($raw_artifact['content_hash']) ? (string) $raw_artifact['content_hash'] : '',
                'fallback_mode' => isset($runtime['fallback_mode']) ? (string) $runtime['fallback_mode'] : DBVC_CC_V2_Contracts::AI_FALLBACK_MODE,
                'stage_budget' => DBVC_CC_V2_Contracts::get_ai_stage_budget(DBVC_CC_V2_Contracts::AI_STAGE_CONTEXT_CREATION),
                'prompt_input' => $this->build_prompt_input($raw_artifact, $source_normalization, $sections_artifact),
            ],
            'stats' => [
                'item_count' => count($items),
                'section_count' => isset($sections_artifact['section_count']) ? (int) $sections_artifact['section_count'] : count(isset($sections_artifact['sections']) && is_array($sections_artifact['sections']) ? $sections_artifact['sections'] : []),
                'average_confidence' => $this->calculate_average_confidence($items),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $raw_artifact
     * @param array<string, mixed> $source_normalization
     * @param array<string, mixed> $sections_artifact
     * @param array<string, mixed> $elements_artifact
     * @return array<int, array<string, mixed>>
     */
    private function build_items(array $raw_artifact, array $source_normalization, array $sections_artifact, array $elements_artifact)
    {
        $sections = isset($sections_artifact['sections']) && is_array($sections_artifact['sections']) ? $sections_artifact['sections'] : [];
        $element_map = $this->build_element_map($elements_artifact);
        $items = [];

        foreach ($sections as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            $section_id = isset($section['section_id']) ? (string) $section['section_id'] : 'section-' . ($index + 1);
            $label = isset($section['section_label_candidate']) ? trim((string) $section['section_label_candidate']) : '';
            $signals = isset($section['signals']) && is_array($section['signals']) ? $section['signals'] : [];
            $sample_text = $this->get_section_sample_text($section, $element_map);
            $context_tag = $this->infer_context_tag($index, $label, $sample_text, $signals);
            $audience_purpose = $this->infer_audience_purpose($context_tag, $sample_text, $signals);
            $authoring_intent = $this->infer_authoring_intent($context_tag, $label, $sample_text);
            $technical_intent = $this->infer_technical_intent($context_tag, $signals);
            $seo_intent = $this->infer_seo_intent($raw_artifact, $label, $sample_text, $source_normalization);
            $confidence = $this->compute_confidence($index, $signals, $context_tag, $sample_text);
            $source_refs = $this->build_source_refs($section, $sample_text);

            $items[] = [
                'item_id' => 'ctx_' . str_pad((string) (count($items) + 1), 3, '0', STR_PAD_LEFT),
                'item_type' => 'section',
                'source_refs' => $source_refs,
                'context_tag' => $context_tag,
                'audience_purpose' => $audience_purpose,
                'authoring_intent' => $authoring_intent,
                'technical_intent' => $technical_intent,
                'seo_intent' => $seo_intent,
                'downstream_instructions' => $this->build_downstream_instructions($context_tag, $signals, $sample_text),
                'confidence' => $confidence,
                'rationale' => $this->build_rationale($label, $sample_text, $signals, $context_tag, $audience_purpose),
            ];
        }

        if (! empty($items)) {
            return $items;
        }

        return [
            [
                'item_id' => 'ctx_001',
                'item_type' => 'page',
                'source_refs' => ['page-artifact.v1#metadata'],
                'context_tag' => 'general',
                'audience_purpose' => 'inform',
                'authoring_intent' => 'summarize_page',
                'technical_intent' => 'retain_metadata_context',
                'seo_intent' => isset($raw_artifact['metadata']['title']) ? (string) $raw_artifact['metadata']['title'] : '',
                'downstream_instructions' => [
                    'Use page metadata and normalized headings as the primary fallback evidence.',
                ],
                'confidence' => 0.58,
                'rationale' => 'No section-level evidence was available, so the page metadata was preserved as the fallback context layer.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $raw_artifact
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $sections_artifact
     * @return array<string, mixed>
     */
    private function build_summary(array $raw_artifact, array $items, array $sections_artifact)
    {
        $tag_counts = [];
        $purpose_counts = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $tag = isset($item['context_tag']) ? (string) $item['context_tag'] : '';
            $purpose = isset($item['audience_purpose']) ? (string) $item['audience_purpose'] : '';
            if ($tag !== '') {
                $tag_counts[$tag] = isset($tag_counts[$tag]) ? $tag_counts[$tag] + 1 : 1;
            }
            if ($purpose !== '') {
                $purpose_counts[$purpose] = isset($purpose_counts[$purpose]) ? $purpose_counts[$purpose] + 1 : 1;
            }
        }

        arsort($tag_counts);
        arsort($purpose_counts);

        return [
            'page_title' => isset($raw_artifact['metadata']['title']) ? (string) $raw_artifact['metadata']['title'] : '',
            'page_path' => isset($raw_artifact['path']) ? (string) $raw_artifact['path'] : '',
            'item_count' => count($items),
            'section_count' => isset($sections_artifact['section_count']) ? (int) $sections_artifact['section_count'] : 0,
            'dominant_context_tags' => array_values(array_slice(array_keys($tag_counts), 0, 3)),
            'primary_audience_purpose' => empty($purpose_counts) ? 'inform' : (string) array_key_first($purpose_counts),
            'average_confidence' => $this->calculate_average_confidence($items),
        ];
    }

    /**
     * @param array<string, mixed> $raw_artifact
     * @param array<string, mixed> $source_normalization
     * @param array<string, mixed> $sections_artifact
     * @return array<string, mixed>
     */
    private function build_prompt_input(array $raw_artifact, array $source_normalization, array $sections_artifact)
    {
        $sections = isset($sections_artifact['sections']) && is_array($sections_artifact['sections']) ? $sections_artifact['sections'] : [];
        $headings = isset($source_normalization['normalized']['headings']) && is_array($source_normalization['normalized']['headings'])
            ? array_values(array_slice($source_normalization['normalized']['headings'], 0, 8))
            : [];

        $section_outline = [];
        foreach (array_slice($sections, 0, 6) as $section) {
            if (! is_array($section)) {
                continue;
            }

            $section_outline[] = [
                'section_id' => isset($section['section_id']) ? (string) $section['section_id'] : '',
                'label' => isset($section['section_label_candidate']) ? (string) $section['section_label_candidate'] : '',
                'signals' => isset($section['signals']) && is_array($section['signals']) ? $section['signals'] : [],
            ];
        }

        return [
            'page' => [
                'title' => isset($raw_artifact['metadata']['title']) ? (string) $raw_artifact['metadata']['title'] : '',
                'description' => isset($raw_artifact['metadata']['description']) ? (string) $raw_artifact['metadata']['description'] : '',
                'path' => isset($raw_artifact['path']) ? (string) $raw_artifact['path'] : '',
            ],
            'normalized_headings' => $headings,
            'section_outline' => $section_outline,
        ];
    }

    /**
     * @param array<string, mixed> $elements_artifact
     * @return array<string, string>
     */
    private function build_element_map(array $elements_artifact)
    {
        $elements = isset($elements_artifact['elements']) && is_array($elements_artifact['elements']) ? $elements_artifact['elements'] : [];
        $map = [];

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            $element_id = isset($element['element_id']) ? (string) $element['element_id'] : '';
            $text = isset($element['text']) ? trim((string) $element['text']) : '';
            if ($element_id !== '' && $text !== '') {
                $map[$element_id] = $text;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $section
     * @param array<string, string> $element_map
     * @return array<int, string>
     */
    private function get_section_sample_text(array $section, array $element_map)
    {
        $sample = [];
        $element_ids = isset($section['element_ids']) && is_array($section['element_ids']) ? $section['element_ids'] : [];

        foreach ($element_ids as $element_id) {
            $element_id = (string) $element_id;
            if ($element_id === '' || ! isset($element_map[$element_id])) {
                continue;
            }

            $sample[] = $element_map[$element_id];
            if (count($sample) >= 3) {
                break;
            }
        }

        return $sample;
    }

    /**
     * @param array<string, mixed> $section
     * @param array<int, string> $sample_text
     * @return array<int, string>
     */
    private function build_source_refs(array $section, array $sample_text)
    {
        $refs = [];
        $section_id = isset($section['section_id']) ? (string) $section['section_id'] : '';
        if ($section_id !== '') {
            $refs[] = 'sections.v2#' . $section_id;
        }

        $element_ids = isset($section['element_ids']) && is_array($section['element_ids']) ? array_values($section['element_ids']) : [];
        foreach (array_slice($element_ids, 0, 2) as $element_id) {
            $element_id = sanitize_text_field((string) $element_id);
            if ($element_id !== '') {
                $refs[] = 'elements.v2#' . $element_id;
            }
        }

        if (empty($refs) && ! empty($sample_text)) {
            $refs[] = 'source-normalization.v1#text_blocks';
        }

        return $refs;
    }

    /**
     * @param int $index
     * @param string $label
     * @param array<int, string> $sample_text
     * @param array<string, mixed> $signals
     * @return string
     */
    private function infer_context_tag($index, $label, array $sample_text, array $signals)
    {
        $haystack = strtolower(trim($label . ' ' . implode(' ', $sample_text)));
        if ($index === 0 && $haystack !== '') {
            return 'hero';
        }

        if (preg_match('/\b(contact|call|book|schedule|quote|request)\b/', $haystack)) {
            return 'contact';
        }

        if (preg_match('/\b(about|team|story|mission|values|history)\b/', $haystack)) {
            return 'about';
        }

        if (preg_match('/\b(service|services|solution|offering|capability)\b/', $haystack)) {
            return 'services';
        }

        if (preg_match('/\b(product|platform|feature|pricing|plan)\b/', $haystack)) {
            return 'product';
        }

        if (! empty($signals['cta_keyword_hits'])) {
            return 'conversion';
        }

        return 'general';
    }

    /**
     * @param string $context_tag
     * @param array<int, string> $sample_text
     * @param array<string, mixed> $signals
     * @return string
     */
    private function infer_audience_purpose($context_tag, array $sample_text, array $signals)
    {
        $haystack = strtolower(implode(' ', $sample_text));
        if ($context_tag === 'contact' || $context_tag === 'conversion' || ! empty($signals['cta_keyword_hits'])) {
            return 'convert';
        }

        if ($context_tag === 'about') {
            return 'build_trust';
        }

        if ($context_tag === 'services' || $context_tag === 'product') {
            return 'evaluate_solution';
        }

        if (preg_match('/\b(guide|learn|how to|overview|introduction)\b/', $haystack)) {
            return 'educate';
        }

        return 'inform';
    }

    /**
     * @param string $context_tag
     * @param string $label
     * @param array<int, string> $sample_text
     * @return string
     */
    private function infer_authoring_intent($context_tag, $label, array $sample_text)
    {
        $haystack = strtolower(trim($label . ' ' . implode(' ', $sample_text)));
        if ($context_tag === 'hero') {
            return 'frame_primary_message';
        }

        if ($context_tag === 'contact') {
            return 'capture_inquiry';
        }

        if ($context_tag === 'about') {
            return 'establish_credibility';
        }

        if ($context_tag === 'services' || $context_tag === 'product') {
            return 'describe_offering';
        }

        if (preg_match('/\b(testimonial|case study|results|success)\b/', $haystack)) {
            return 'prove_outcome';
        }

        return 'explain_content';
    }

    /**
     * @param string $context_tag
     * @param array<string, mixed> $signals
     * @return string
     */
    private function infer_technical_intent($context_tag, array $signals)
    {
        if ($context_tag === 'contact') {
            return 'route_to_contact_flow';
        }

        if ($context_tag === 'conversion' || ! empty($signals['cta_keyword_hits'])) {
            return 'promote_next_action';
        }

        if (! empty($signals['list_density']) && (float) $signals['list_density'] >= 0.4) {
            return 'preserve_structured_list';
        }

        return 'preserve_section_context';
    }

    /**
     * @param array<string, mixed> $raw_artifact
     * @param string $label
     * @param array<int, string> $sample_text
     * @param array<string, mixed> $source_normalization
     * @return string
     */
    private function infer_seo_intent(array $raw_artifact, $label, array $sample_text, array $source_normalization)
    {
        $title = isset($raw_artifact['metadata']['title']) ? trim((string) $raw_artifact['metadata']['title']) : '';
        if ($title !== '') {
            return $title;
        }

        if (trim((string) $label) !== '') {
            return trim((string) $label);
        }

        $headings = isset($source_normalization['normalized']['headings']) && is_array($source_normalization['normalized']['headings'])
            ? array_values($source_normalization['normalized']['headings'])
            : [];

        if (! empty($headings)) {
            return (string) $headings[0];
        }

        return empty($sample_text) ? '' : (string) $sample_text[0];
    }

    /**
     * @param string $context_tag
     * @param array<string, mixed> $signals
     * @param array<int, string> $sample_text
     * @return array<int, string>
     */
    private function build_downstream_instructions($context_tag, array $signals, array $sample_text)
    {
        $instructions = [];

        if ($context_tag === 'hero') {
            $instructions[] = 'Prioritize the leading heading and supporting copy for title and summary candidates.';
        }
        if ($context_tag === 'contact') {
            $instructions[] = 'Preserve contact cues and CTA wording for downstream field and entity resolution.';
        }
        if ($context_tag === 'services' || $context_tag === 'product') {
            $instructions[] = 'Retain offer framing and feature language for object classification and mapping.';
        }
        if (! empty($signals['list_density']) && (float) $signals['list_density'] >= 0.4) {
            $instructions[] = 'Treat list-heavy content as structured detail rather than a freeform paragraph.';
        }
        if (! empty($signals['cta_keyword_hits'])) {
            $instructions[] = 'Carry CTA text forward as a high-signal conversion hint.';
        }
        if (empty($instructions) && ! empty($sample_text)) {
            $instructions[] = 'Use the section summary as supporting evidence during classification.';
        }

        return $instructions;
    }

    /**
     * @param int $index
     * @param array<string, mixed> $signals
     * @param string $context_tag
     * @param array<int, string> $sample_text
     * @return float
     */
    private function compute_confidence($index, array $signals, $context_tag, array $sample_text)
    {
        $confidence = 0.63;
        if ((int) $index === 0) {
            $confidence += 0.1;
        }
        if (! empty($signals['has_heading'])) {
            $confidence += 0.06;
        }
        if (! empty($signals['text_element_count'])) {
            $confidence += min(0.08, ((int) $signals['text_element_count']) * 0.01);
        }
        if (! empty($signals['cta_keyword_hits'])) {
            $confidence += 0.05;
        }
        if ($context_tag !== 'general') {
            $confidence += 0.04;
        }
        if (count($sample_text) >= 2) {
            $confidence += 0.03;
        }

        return round(max(0.55, min(0.96, $confidence)), 2);
    }

    /**
     * @param string $label
     * @param array<int, string> $sample_text
     * @param array<string, mixed> $signals
     * @param string $context_tag
     * @param string $audience_purpose
     * @return string
     */
    private function build_rationale($label, array $sample_text, array $signals, $context_tag, $audience_purpose)
    {
        $parts = [];

        if (trim((string) $label) !== '') {
            $parts[] = sprintf('Section label "%s" anchors the interpretation.', trim((string) $label));
        }
        if (! empty($signals['cta_keyword_hits'])) {
            $parts[] = 'CTA language indicates a conversion-oriented segment.';
        }
        if (! empty($signals['list_density']) && (float) $signals['list_density'] >= 0.4) {
            $parts[] = 'List density suggests structured supporting detail.';
        }
        if (! empty($sample_text)) {
            $parts[] = sprintf('Sample text supports a %s context with a %s audience purpose.', $context_tag, $audience_purpose);
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return float
     */
    private function calculate_average_confidence(array $items)
    {
        if (empty($items)) {
            return 0.0;
        }

        $sum = 0.0;
        $count = 0;
        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['confidence'])) {
                continue;
            }

            $sum += (float) $item['confidence'];
            ++$count;
        }

        if ($count === 0) {
            return 0.0;
        }

        return round($sum / $count, 2);
    }
}
