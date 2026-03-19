<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Source_Normalization_Service
{
    /**
     * @var DBVC_CC_V2_Source_Normalization_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Source_Normalization_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param array<string, mixed> $raw_artifact
     * @return array<string, mixed>
     */
    public function build_artifact(array $raw_artifact)
    {
        $normalized_headings = $this->normalize_string_list(isset($raw_artifact['headings']) ? $raw_artifact['headings'] : []);
        $normalized_text_blocks = $this->normalize_string_list(isset($raw_artifact['text_blocks']) ? $raw_artifact['text_blocks'] : []);
        $normalized_links = $this->normalize_links(isset($raw_artifact['links']) ? $raw_artifact['links'] : []);
        $normalized_sections = $this->normalize_sections(isset($raw_artifact['sections_raw']) ? $raw_artifact['sections_raw'] : []);

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'source-normalization.v1',
            'journey_id' => isset($raw_artifact['journey_id']) ? (string) $raw_artifact['journey_id'] : '',
            'page_id' => isset($raw_artifact['page_id']) ? (string) $raw_artifact['page_id'] : '',
            'source_url' => isset($raw_artifact['source_url']) ? (string) $raw_artifact['source_url'] : '',
            'generated_at' => current_time('c'),
            'normalizations' => [
                [
                    'field' => 'headings',
                    'action' => 'trim_collapse_whitespace_dedupe',
                    'count' => count($normalized_headings),
                ],
                [
                    'field' => 'text_blocks',
                    'action' => 'trim_collapse_whitespace_dedupe',
                    'count' => count($normalized_text_blocks),
                ],
                [
                    'field' => 'links',
                    'action' => 'canonicalize_urls_and_trim_text',
                    'count' => count($normalized_links),
                ],
                [
                    'field' => 'sections_raw',
                    'action' => 'normalize_labels_and_text_blocks',
                    'count' => count($normalized_sections),
                ],
            ],
            'normalized' => [
                'headings' => $normalized_headings,
                'text_blocks' => $normalized_text_blocks,
                'links' => $normalized_links,
                'sections_raw' => $normalized_sections,
            ],
            'stats' => [
                'heading_count' => count($normalized_headings),
                'text_block_count' => count($normalized_text_blocks),
                'link_count' => count($normalized_links),
                'section_count' => count($normalized_sections),
            ],
        ];
    }

    /**
     * @param mixed $values
     * @return array<int, string>
     */
    private function normalize_string_list($values)
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            $clean = preg_replace('/\s+/u', ' ', trim((string) $value));
            if (! is_string($clean) || $clean === '') {
                continue;
            }

            $normalized[$clean] = $clean;
        }

        return array_values($normalized);
    }

    /**
     * @param mixed $links
     * @return array<int, array<string, string>>
     */
    private function normalize_links($links)
    {
        if (! is_array($links)) {
            return [];
        }

        $normalized = [];
        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }

            $url = isset($link['url']) ? DBVC_CC_V2_URL_Scope_Service::get_instance()->canonicalize_url((string) $link['url']) : '';
            $text = preg_replace('/\s+/u', ' ', trim(isset($link['text']) ? (string) $link['text'] : ''));
            $text = is_string($text) ? $text : '';

            if ($url === '' && $text === '') {
                continue;
            }

            $key = $url . '|' . $text;
            $normalized[$key] = [
                'url' => $url,
                'text' => $text,
                'type' => isset($link['type']) ? sanitize_key((string) $link['type']) : 'link',
            ];
        }

        return array_values($normalized);
    }

    /**
     * @param mixed $sections
     * @return array<int, array<string, mixed>>
     */
    private function normalize_sections($sections)
    {
        if (! is_array($sections)) {
            return [];
        }

        $normalized = [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $normalized[] = [
                'id' => isset($section['id']) ? sanitize_text_field((string) $section['id']) : '',
                'heading' => isset($section['heading']) ? preg_replace('/\s+/u', ' ', trim((string) $section['heading'])) : '',
                'is_intro' => ! empty($section['is_intro']),
                'text_blocks' => $this->normalize_string_list(isset($section['text_blocks']) ? $section['text_blocks'] : []),
                'links' => $this->normalize_links(isset($section['links']) ? $section['links'] : []),
                'ctas' => $this->normalize_links(isset($section['ctas']) ? $section['ctas'] : []),
            ];
        }

        return $normalized;
    }
}
