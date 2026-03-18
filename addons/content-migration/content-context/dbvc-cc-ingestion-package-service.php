<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Ingestion_Package_Service
{
    /**
     * @param array<string, mixed> $scraped_data
     * @param array<string, mixed> $elements_payload
     * @param array<string, mixed> $sections_payload
     * @param array<string, mixed> $section_typing_payload
     * @param array<string, mixed> $context_bundle_payload
     * @param string $page_url
     * @return array<string, mixed>
     */
    public static function build_artifact(
        array $scraped_data,
        array $elements_payload,
        array $sections_payload,
        array $section_typing_payload,
        array $context_bundle_payload,
        $page_url
    ) {
        $elements = isset($elements_payload['elements']) && is_array($elements_payload['elements']) ? $elements_payload['elements'] : [];
        $sections = isset($sections_payload['sections']) && is_array($sections_payload['sections']) ? $sections_payload['sections'] : [];
        $section_typings = isset($section_typing_payload['section_typings']) && is_array($section_typing_payload['section_typings']) ? $section_typing_payload['section_typings'] : [];

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

        $typing_by_section_id = [];
        foreach ($section_typings as $typing) {
            if (! is_array($typing)) {
                continue;
            }
            $section_id = isset($typing['section_id']) ? (string) $typing['section_id'] : '';
            if ($section_id === '') {
                continue;
            }
            $typing_by_section_id[$section_id] = $typing;
        }

        $normalized_sections = [];
        $traceability = [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $section_id = isset($section['section_id']) ? (string) $section['section_id'] : '';
            if ($section_id === '') {
                continue;
            }

            $element_ids = isset($section['element_ids']) && is_array($section['element_ids']) ? $section['element_ids'] : [];
            $typing = isset($typing_by_section_id[$section_id]) && is_array($typing_by_section_id[$section_id]) ? $typing_by_section_id[$section_id] : [];

            $sample_text = [];
            foreach ($element_ids as $element_id) {
                $element_id = (string) $element_id;
                if ($element_id === '' || ! isset($element_text_by_id[$element_id])) {
                    continue;
                }

                $value = trim((string) $element_text_by_id[$element_id]);
                if ($value === '') {
                    continue;
                }

                $sample_text[] = $value;
                if (count($sample_text) >= 3) {
                    break;
                }
            }

            $normalized_sections[] = [
                'section_id' => $section_id,
                'order' => isset($section['order']) ? (int) $section['order'] : 0,
                'label' => isset($section['section_label_candidate']) ? (string) $section['section_label_candidate'] : '',
                'section_type' => isset($typing['section_type_candidate']) ? (string) $typing['section_type_candidate'] : 'content',
                'section_type_confidence' => isset($typing['confidence']) ? (float) $typing['confidence'] : 0.0,
                'section_type_mode' => isset($typing['mode']) ? (string) $typing['mode'] : 'fallback',
                'signals' => isset($section['signals']) && is_array($section['signals']) ? $section['signals'] : [],
                'sample_text' => $sample_text,
                'element_count' => count($element_ids),
            ];

            $traceability[$section_id] = [
                'element_ids' => array_values(array_map('strval', $element_ids)),
                'typing_evidence_element_ids' => isset($typing['evidence_element_ids']) && is_array($typing['evidence_element_ids'])
                    ? array_values(array_map('strval', $typing['evidence_element_ids']))
                    : [],
            ];
        }

        $page_name = isset($scraped_data['page_name']) ? (string) $scraped_data['page_name'] : '';
        $slug = isset($scraped_data['slug']) ? (string) $scraped_data['slug'] : DBVC_CC_Artifact_Manager::get_slug_from_url((string) $page_url);
        $meta_description = isset($scraped_data['meta']['description']) ? (string) $scraped_data['meta']['description'] : '';

        $entity_hints = isset($context_bundle_payload['entity_hints']) && is_array($context_bundle_payload['entity_hints'])
            ? $context_bundle_payload['entity_hints']
            : [];

        $scrub_summary = isset($context_bundle_payload['attribute_scrub_summary']) && is_array($context_bundle_payload['attribute_scrub_summary'])
            ? $context_bundle_payload['attribute_scrub_summary']
            : [];

        $content_hash = isset($scraped_data['provenance']['content_hash']) ? (string) $scraped_data['provenance']['content_hash'] : '';
        $source_url = (string) $page_url;

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'ingestion-package.v2',
            'source_url' => $source_url,
            'generated_at' => current_time('c'),
            'page' => [
                'title' => $page_name,
                'slug' => $slug,
                'meta_description' => $meta_description,
                'content_hash' => $content_hash,
            ],
            'sections' => $normalized_sections,
            'entity_hints' => $entity_hints,
            'attribute_scrub_summary' => $scrub_summary,
            'traceability' => $traceability,
            'stats' => [
                'section_count' => count($normalized_sections),
                'element_count' => count($elements),
            ],
        ];
    }
}
