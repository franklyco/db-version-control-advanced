<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Context_Bundle_Service
{
    /**
     * @param array<string, mixed> $scraped_data
     * @param array<string, mixed> $elements_payload
     * @param array<string, mixed> $sections_payload
     * @param array<string, mixed> $scrub_report_payload
     * @param string $page_url
     * @return array<string, mixed>
     */
    public static function build_artifact(array $scraped_data, array $elements_payload, array $sections_payload, array $scrub_report_payload, $page_url)
    {
        $elements = isset($elements_payload['elements']) && is_array($elements_payload['elements']) ? $elements_payload['elements'] : [];
        $sections = isset($sections_payload['sections']) && is_array($sections_payload['sections']) ? $sections_payload['sections'] : [];

        $element_text_by_id = [];
        $internal_link_count = 0;
        $external_link_count = 0;

        $source_host = wp_parse_url((string) $page_url, PHP_URL_HOST);
        $source_host = is_string($source_host) ? strtolower($source_host) : '';

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            $element_id = isset($element['element_id']) ? (string) $element['element_id'] : '';
            $element_text = isset($element['text']) ? (string) $element['text'] : '';
            if ($element_id !== '') {
                $element_text_by_id[$element_id] = $element_text;
            }

            $link_target = isset($element['link_target']) ? (string) $element['link_target'] : '';
            if ($link_target === '') {
                continue;
            }

            $target_host = wp_parse_url($link_target, PHP_URL_HOST);
            $target_host = is_string($target_host) ? strtolower($target_host) : '';
            if ($target_host !== '' && $source_host !== '' && $target_host === $source_host) {
                $internal_link_count++;
            } else {
                $external_link_count++;
            }
        }

        $outline = [];
        $sections_enriched = [];
        $trace_map = [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $section_id = isset($section['section_id']) ? (string) $section['section_id'] : '';
            $label = isset($section['section_label_candidate']) ? (string) $section['section_label_candidate'] : '';
            $heading_level = isset($section['signals']['heading_level']) ? (int) $section['signals']['heading_level'] : 0;
            $element_ids = isset($section['element_ids']) && is_array($section['element_ids']) ? $section['element_ids'] : [];

            if ($heading_level > 0 && $label !== '') {
                $outline[] = [
                    'level' => $heading_level,
                    'label' => $label,
                    'section_id' => $section_id,
                ];
            }

            $sample_text = [];
            foreach ($element_ids as $element_id) {
                $element_id = (string) $element_id;
                if ($element_id === '' || ! isset($element_text_by_id[$element_id])) {
                    continue;
                }

                $text = trim((string) $element_text_by_id[$element_id]);
                if ($text === '') {
                    continue;
                }
                $sample_text[] = $text;
                if (count($sample_text) >= 3) {
                    break;
                }
            }

            $sections_enriched[] = [
                'section_id' => $section_id,
                'label_candidate' => $label,
                'signals' => isset($section['signals']) && is_array($section['signals']) ? $section['signals'] : [],
                'element_count' => count($element_ids),
                'sample_text' => $sample_text,
            ];
            $trace_map[$section_id] = $element_ids;
        }

        $scrub_summary = [
            'profile' => isset($scrub_report_payload['profile']) ? (string) $scrub_report_payload['profile'] : DBVC_CC_Contracts::SCRUB_PROFILE_DETERMINISTIC_DEFAULT,
            'totals' => isset($scrub_report_payload['totals']) && is_array($scrub_report_payload['totals']) ? $scrub_report_payload['totals'] : [],
            'by_attribute' => isset($scrub_report_payload['by_attribute']) && is_array($scrub_report_payload['by_attribute']) ? $scrub_report_payload['by_attribute'] : [],
        ];

        $pii_flags = isset($scraped_data['compliance']['pii_flags']) && is_array($scraped_data['compliance']['pii_flags'])
            ? $scraped_data['compliance']['pii_flags']
            : [];
        $dbvc_cc_redaction_rules = isset($scraped_data['compliance']['redaction_rules']) && is_array($scraped_data['compliance']['redaction_rules'])
            ? $scraped_data['compliance']['redaction_rules']
            : [];
        $dbvc_cc_requires_legal_review = !empty($scraped_data['compliance']['requires_legal_review']);
        $dbvc_cc_pii_hint_tags = [];
        if (!empty($pii_flags['emails_count'])) {
            $dbvc_cc_pii_hint_tags[] = 'email';
        }
        if (!empty($pii_flags['phones_count'])) {
            $dbvc_cc_pii_hint_tags[] = 'phone';
        }
        if (!empty($pii_flags['forms_count'])) {
            $dbvc_cc_pii_hint_tags[] = 'form';
        }

        $repetition_hints = [];
        if (! empty($scraped_data['content']['headings']) && is_array($scraped_data['content']['headings'])) {
            $seen = [];
            foreach ($scraped_data['content']['headings'] as $heading) {
                $heading_key = strtolower(trim((string) $heading));
                if ($heading_key === '') {
                    continue;
                }
                if (isset($seen[$heading_key])) {
                    $repetition_hints[] = $heading_key;
                }
                $seen[$heading_key] = true;
            }
            $repetition_hints = array_values(array_unique($repetition_hints));
        }

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'context-bundle.v2',
            'source_url' => (string) $page_url,
            'generated_at' => current_time('c'),
            'page_context' => [
                'url' => (string) $page_url,
                'slug' => isset($scraped_data['slug']) ? (string) $scraped_data['slug'] : DBVC_CC_Artifact_Manager::get_slug_from_url((string) $page_url),
                'title' => isset($scraped_data['page_name']) ? (string) $scraped_data['page_name'] : '',
                'meta_description' => isset($scraped_data['meta']['description']) ? (string) $scraped_data['meta']['description'] : '',
            ],
            'outline' => $outline,
            'sections' => $sections_enriched,
            'repetition_hints' => $repetition_hints,
            'entity_hints' => [
                'pii_flags' => $pii_flags,
                'pii_hint_tags' => $dbvc_cc_pii_hint_tags,
            ],
            'privacy' => [
                'redaction_rules' => $dbvc_cc_redaction_rules,
                'requires_legal_review' => $dbvc_cc_requires_legal_review,
            ],
            'link_graph_hints' => [
                'internal_link_count' => $internal_link_count,
                'external_link_count' => $external_link_count,
            ],
            'trace_map' => $trace_map,
            'attribute_scrub_summary' => $scrub_summary,
        ];
    }
}
