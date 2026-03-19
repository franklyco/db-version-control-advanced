<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Section_Typing_Service
{
    /**
     * @param array<string, mixed> $sections_payload
     * @param array<string, mixed> $elements_payload
     * @param array<string, mixed> $options
     * @param string $page_url
     * @return array<string, mixed>
     */
    public static function build_artifact(array $sections_payload, array $elements_payload, array $options, $page_url)
    {
        $sections = isset($sections_payload['sections']) && is_array($sections_payload['sections']) ? $sections_payload['sections'] : [];
        $elements = isset($elements_payload['elements']) && is_array($elements_payload['elements']) ? $elements_payload['elements'] : [];

        $element_text_by_id = [];
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }
            $element_id = isset($element['element_id']) ? (string) $element['element_id'] : '';
            if ($element_id === '') {
                continue;
            }
            $element_text_by_id[$element_id] = isset($element['text']) ? (string) $element['text'] : '';
        }

        $threshold = isset($options['ai_section_typing_confidence_threshold']) ? (float) $options['ai_section_typing_confidence_threshold'] : 0.65;
        if ($threshold < 0) {
            $threshold = 0.0;
        }
        if ($threshold > 1) {
            $threshold = 1.0;
        }

        $section_typings = [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $section_id = isset($section['section_id']) ? (string) $section['section_id'] : '';
            if ($section_id === '') {
                continue;
            }

            $element_ids = isset($section['element_ids']) && is_array($section['element_ids']) ? $section['element_ids'] : [];
            $evidence_ids = [];
            $text_blob_parts = [];
            foreach ($element_ids as $element_id) {
                $element_id = (string) $element_id;
                if ($element_id === '' || ! isset($element_text_by_id[$element_id])) {
                    continue;
                }

                $evidence_ids[] = $element_id;
                $text_blob_parts[] = $element_text_by_id[$element_id];
                if (count($evidence_ids) >= 8) {
                    break;
                }
            }

            $label = isset($section['section_label_candidate']) ? (string) $section['section_label_candidate'] : '';
            $signals = isset($section['signals']) && is_array($section['signals']) ? $section['signals'] : [];
            $text_blob = strtolower(trim($label . ' ' . implode(' ', $text_blob_parts)));

            $result = self::classify_section($text_blob, $signals, $section);
            $auto_accepted = $result['confidence'] >= $threshold;

            $section_typings[] = [
                'section_id' => $section_id,
                'section_type_candidate' => $result['type'],
                'confidence' => $result['confidence'],
                'mode' => 'fallback',
                'auto_accept' => $auto_accepted,
                'rationale' => $result['rationale'],
                'evidence_element_ids' => $evidence_ids,
                'alternate_candidates' => $result['alternates'],
            ];
        }

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'section-typing.v2',
            'source_url' => (string) $page_url,
            'generated_at' => current_time('c'),
            'mode' => 'fallback',
            'confidence_threshold' => $threshold,
            'section_typings' => $section_typings,
        ];
    }

    /**
     * @param string $text_blob
     * @param array<string, mixed> $signals
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    private static function classify_section($text_blob, array $signals, array $section)
    {
        $rules = [
            'faq' => [
                'pattern' => '/\b(faq|frequently asked|questions?)\b/i',
                'confidence' => 0.88,
                'rationale' => 'Detected FAQ-oriented terms in heading or section body.',
                'alternates' => ['content', 'support'],
            ],
            'contact' => [
                'pattern' => '/\b(contact|call|phone|email|address|location|get in touch)\b/i',
                'confidence' => 0.84,
                'rationale' => 'Detected contact-oriented terms in section content.',
                'alternates' => ['cta', 'content'],
            ],
            'cta' => [
                'pattern' => '/\b(get started|learn more|book|schedule|request|quote|start now|contact us)\b/i',
                'confidence' => 0.81,
                'rationale' => 'Detected call-to-action language in section content.',
                'alternates' => ['hero', 'content'],
            ],
            'pricing' => [
                'pattern' => '/\b(price|pricing|cost|plan|plans|package|packages)\b/i',
                'confidence' => 0.80,
                'rationale' => 'Detected pricing/package language in section content.',
                'alternates' => ['content', 'cta'],
            ],
        ];

        foreach ($rules as $type => $rule) {
            if (preg_match($rule['pattern'], $text_blob)) {
                return [
                    'type' => $type,
                    'confidence' => $rule['confidence'],
                    'rationale' => $rule['rationale'],
                    'alternates' => $rule['alternates'],
                ];
            }
        }

        $is_first_section = isset($section['order']) && (int) $section['order'] === 1;
        $heading_level = isset($signals['heading_level']) ? (int) $signals['heading_level'] : 0;
        if ($is_first_section && $heading_level === 1) {
            return [
                'type' => 'hero',
                'confidence' => 0.78,
                'rationale' => 'First section with an H1 heading is likely hero/intro content.',
                'alternates' => ['intro', 'content'],
            ];
        }

        $list_density = isset($signals['list_density']) ? (float) $signals['list_density'] : 0.0;
        if ($list_density >= 0.40) {
            return [
                'type' => 'list',
                'confidence' => 0.72,
                'rationale' => 'High list density indicates structured list content.',
                'alternates' => ['features', 'content'],
            ];
        }

        return [
            'type' => 'content',
            'confidence' => 0.60,
            'rationale' => 'No strong section-type signals detected; defaulted to generic content.',
            'alternates' => ['intro', 'cta'],
        ];
    }
}
