<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Section_Segmenter_Service
{
    /**
     * @param array<string, mixed> $elements_payload
     * @param string $page_url
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function build_artifact(array $elements_payload, $page_url, array $options = [])
    {
        $elements = isset($elements_payload['elements']) && is_array($elements_payload['elements']) ? $elements_payload['elements'] : [];
        $dbvc_cc_chunk_size = self::dbvc_cc_resolve_chunk_size($options);
        $dbvc_cc_stage_timeout_seconds = self::dbvc_cc_resolve_stage_timeout_seconds($options);
        $dbvc_cc_stage_started_at = microtime(true);
        $dbvc_cc_is_partial = false;
        $dbvc_cc_partial_reason = '';
        $dbvc_cc_resume_marker = [];
        $sections = [];
        $section_order = 0;
        $current_section_index = -1;

        $dbvc_cc_element_count = count($elements);
        for ($dbvc_cc_element_offset = 0; $dbvc_cc_element_offset < $dbvc_cc_element_count; $dbvc_cc_element_offset += $dbvc_cc_chunk_size) {
            if (self::dbvc_cc_has_stage_timed_out($dbvc_cc_stage_started_at, $dbvc_cc_stage_timeout_seconds)) {
                $dbvc_cc_is_partial = true;
                $dbvc_cc_partial_reason = 'timeout';
                $dbvc_cc_resume_marker = [
                    'element_offset' => (int) $dbvc_cc_element_offset,
                ];
                break;
            }

            $dbvc_cc_chunk_end = min($dbvc_cc_element_offset + $dbvc_cc_chunk_size, $dbvc_cc_element_count);
            for ($dbvc_cc_element_index = $dbvc_cc_element_offset; $dbvc_cc_element_index < $dbvc_cc_chunk_end; $dbvc_cc_element_index++) {
                $element = $elements[$dbvc_cc_element_index];
                if (! is_array($element)) {
                    continue;
                }

                $tag = isset($element['tag']) ? strtolower((string) $element['tag']) : '';
                $is_heading = (bool) preg_match('/^h([1-6])$/', $tag, $heading_match);
                $sequence_index = isset($element['sequence_index']) ? (int) $element['sequence_index'] : 0;
                $element_id = isset($element['element_id']) ? (string) $element['element_id'] : '';
                $text = isset($element['text']) ? trim((string) $element['text']) : '';

                if ($is_heading) {
                    $section_order++;
                    $section_id = 'dbvc_cc_sec_' . str_pad((string) $section_order, 4, '0', STR_PAD_LEFT);
                    $sections[] = [
                        'section_id' => $section_id,
                        'order' => $section_order,
                        'section_label_candidate' => $text,
                        'start_sequence_index' => $sequence_index,
                        'end_sequence_index' => $sequence_index,
                        'heading_anchor_element_id' => $element_id,
                        'element_ids' => $element_id !== '' ? [$element_id] : [],
                        'signals' => [
                            'heading_level' => isset($heading_match[1]) ? (int) $heading_match[1] : 0,
                            'has_heading' => true,
                            'text_element_count' => 0,
                            'link_element_count' => 0,
                            'list_element_count' => 0,
                            'cta_keyword_hits' => 0,
                        ],
                    ];
                    $current_section_index = count($sections) - 1;
                    continue;
                }

                if ($current_section_index < 0 || ! isset($sections[$current_section_index])) {
                    $section_order++;
                    $section_id = 'dbvc_cc_sec_' . str_pad((string) $section_order, 4, '0', STR_PAD_LEFT);
                    $sections[] = [
                        'section_id' => $section_id,
                        'order' => $section_order,
                        'section_label_candidate' => 'Intro',
                        'start_sequence_index' => $sequence_index,
                        'end_sequence_index' => $sequence_index,
                        'heading_anchor_element_id' => '',
                        'element_ids' => [],
                        'signals' => [
                            'heading_level' => 0,
                            'has_heading' => false,
                            'text_element_count' => 0,
                            'link_element_count' => 0,
                            'list_element_count' => 0,
                            'cta_keyword_hits' => 0,
                        ],
                    ];
                    $current_section_index = count($sections) - 1;
                }

                if ($element_id !== '') {
                    $sections[$current_section_index]['element_ids'][] = $element_id;
                }
                $sections[$current_section_index]['end_sequence_index'] = $sequence_index;

                if (in_array($tag, ['p', 'blockquote', 'figcaption', 'label'], true)) {
                    $sections[$current_section_index]['signals']['text_element_count']++;
                }
                if ($tag === 'a') {
                    $sections[$current_section_index]['signals']['link_element_count']++;
                }
                if ($tag === 'li') {
                    $sections[$current_section_index]['signals']['list_element_count']++;
                }
                if ($text !== '' && preg_match('/\b(get started|learn more|contact|book|schedule|call|request|quote|start)\b/i', $text)) {
                    $sections[$current_section_index]['signals']['cta_keyword_hits']++;
                }
            }
        }

        foreach ($sections as &$section) {
            $element_total = count(isset($section['element_ids']) && is_array($section['element_ids']) ? $section['element_ids'] : []);
            if ($element_total > 0) {
                $list_count = isset($section['signals']['list_element_count']) ? (int) $section['signals']['list_element_count'] : 0;
                $section['signals']['list_density'] = round($list_count / $element_total, 4);
            } else {
                $section['signals']['list_density'] = 0;
            }
        }
        unset($section);

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'sections.v2',
            'source_url' => (string) $page_url,
            'generated_at' => current_time('c'),
            'section_count' => count($sections),
            'processing' => [
                'is_partial' => $dbvc_cc_is_partial,
                'partial_reason' => $dbvc_cc_partial_reason,
                'resume_marker' => ! empty($dbvc_cc_resume_marker) ? $dbvc_cc_resume_marker : (object) [],
                'chunk_size' => $dbvc_cc_chunk_size,
                'stage_timeout_seconds' => $dbvc_cc_stage_timeout_seconds,
            ],
            'sections' => $sections,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return int
     */
    private static function dbvc_cc_resolve_chunk_size(array $options)
    {
        if (isset($options['capture_processing_chunk_size'])) {
            return max(25, min(1000, (int) $options['capture_processing_chunk_size']));
        }

        return 250;
    }

    /**
     * @param array<string, mixed> $options
     * @return float
     */
    private static function dbvc_cc_resolve_stage_timeout_seconds(array $options)
    {
        if (isset($options['capture_stage_timeout_seconds']) && is_numeric((string) $options['capture_stage_timeout_seconds'])) {
            $timeout = (float) $options['capture_stage_timeout_seconds'];
            return max(0.0, min(120.0, $timeout));
        }

        $request_timeout = isset($options['request_timeout']) ? (int) $options['request_timeout'] : 30;
        return (float) max(5, min(60, $request_timeout));
    }

    /**
     * @param float $started_at
     * @param float $timeout_seconds
     * @return bool
     */
    private static function dbvc_cc_has_stage_timed_out($started_at, $timeout_seconds)
    {
        if ($timeout_seconds <= 0.0) {
            return true;
        }

        return (microtime(true) - (float) $started_at) >= $timeout_seconds;
    }
}
