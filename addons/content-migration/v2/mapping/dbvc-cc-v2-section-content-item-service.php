<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Section_Content_Item_Service
{
    /**
     * @var DBVC_CC_V2_Section_Content_Item_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Section_Content_Item_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param array<string, mixed>                $ingestion_section
     * @param array<string, mixed>                $section_outline
     * @param array<string, mixed>                $context_item
     * @param array<string, array<string, mixed>> $element_lookup
     * @return array<string, mixed>
     */
    public function build_units(array $ingestion_section, array $section_outline = [], array $context_item = [], array $element_lookup = [])
    {
        $section_id = isset($ingestion_section['section_id']) ? sanitize_key((string) $ingestion_section['section_id']) : '';
        $label = isset($ingestion_section['label']) ? trim((string) $ingestion_section['label']) : '';
        if ($label === '' && ! empty($section_outline['section_label_candidate'])) {
            $label = trim((string) $section_outline['section_label_candidate']);
        }

        $signals = [];
        if (isset($ingestion_section['signals']) && is_array($ingestion_section['signals'])) {
            $signals = $ingestion_section['signals'];
        }
        if (isset($section_outline['signals']) && is_array($section_outline['signals'])) {
            $signals = array_merge($signals, $section_outline['signals']);
        }

        $sample_text = isset($ingestion_section['sample_text']) && is_array($ingestion_section['sample_text'])
            ? array_values(array_filter(array_map('strval', $ingestion_section['sample_text'])))
            : [];
        $element_entries = $this->collect_section_elements($section_outline, $element_lookup);
        if (empty($sample_text)) {
            $sample_text = $this->collect_sample_text($element_entries);
        }

        $order = isset($ingestion_section['order']) ? (int) $ingestion_section['order'] : (isset($section_outline['order']) ? (int) $section_outline['order'] : 0);
        $fallback_context = isset($context_item['context_tag']) ? (string) $context_item['context_tag'] : '';
        $context_tag = DBVC_CC_V2_Section_Semantics_Service::get_instance()->infer_context_tag(
            max(0, $order - 1),
            $label,
            $sample_text,
            $signals,
            $fallback_context
        );

        if ($context_tag === 'utility') {
            return [
                'section_context_tag' => $context_tag,
                'skip_reason' => 'utility_navigation_section',
                'units' => [],
            ];
        }

        $units = [];
        $used_hashes = [];

        $heading = $this->find_primary_heading($element_entries);
        if (! empty($heading['text'])) {
            $units[] = $this->build_unit(
                $section_id,
                $context_tag,
                'headline',
                (string) $heading['text'],
                (string) $heading['text'],
                $this->build_source_refs($section_id, isset($heading['element_id']) ? (string) $heading['element_id'] : ''),
                array_values(array_filter([
                    $label !== '' ? 'Section label: ' . sanitize_text_field($label) : '',
                    'Section unit: headline',
                ]))
            );
            $used_hashes[$this->hash_text((string) $heading['text'])] = true;
        }

        $body = $this->find_primary_body($element_entries, $used_hashes);
        if (! empty($body['text'])) {
            $units[] = $this->build_unit(
                $section_id,
                $context_tag,
                'body',
                (string) $body['text'],
                (string) $body['text'],
                $this->build_source_refs($section_id, isset($body['element_id']) ? (string) $body['element_id'] : ''),
                ['Section unit: body']
            );
            $used_hashes[$this->hash_text((string) $body['text'])] = true;
        }

        $cta = $this->find_primary_cta($element_entries, $signals);
        if (! empty($cta['text'])) {
            $cta_text = (string) $cta['text'];
            if (! isset($used_hashes[$this->hash_text($cta_text)])) {
                $units[] = $this->build_unit(
                    $section_id,
                    $context_tag,
                    'cta_label',
                    $cta_text,
                    $cta_text,
                    $this->build_source_refs($section_id, isset($cta['element_id']) ? (string) $cta['element_id'] : ''),
                    ['Section unit: CTA label']
                );
            }

            if (! empty($cta['link_target'])) {
                $units[] = $this->build_unit(
                    $section_id,
                    $context_tag,
                    'cta_url',
                    (string) $cta['link_target'],
                    $cta_text,
                    $this->build_source_refs($section_id, isset($cta['element_id']) ? (string) $cta['element_id'] : ''),
                    ['Section unit: CTA URL']
                );
            }
        }

        if (empty($units) && ! empty($sample_text) && ! empty($element_entries)) {
            $fallback_text = (string) $sample_text[0];
            $fallback_role = ! empty($signals['has_heading']) ? 'headline' : 'body';
            $units[] = $this->build_unit(
                $section_id,
                $context_tag,
                $fallback_role,
                $fallback_text,
                $fallback_text,
                $this->build_source_refs($section_id),
                ['Section unit: fallback']
            );
        }

        return [
            'section_context_tag' => $context_tag,
            'skip_reason' => '',
            'units' => $this->dedupe_units($units),
        ];
    }

    /**
     * @param array<string, mixed>                $section_outline
     * @param array<string, array<string, mixed>> $element_lookup
     * @return array<int, array<string, mixed>>
     */
    private function collect_section_elements(array $section_outline, array $element_lookup)
    {
        $entries = [];
        $element_ids = isset($section_outline['element_ids']) && is_array($section_outline['element_ids'])
            ? array_values($section_outline['element_ids'])
            : [];

        foreach ($element_ids as $element_id) {
            $element_id = sanitize_text_field((string) $element_id);
            if ($element_id === '' || ! isset($element_lookup[$element_id]) || ! is_array($element_lookup[$element_id])) {
                continue;
            }

            $entry = $element_lookup[$element_id];
            $text = isset($entry['text']) ? trim((string) $entry['text']) : '';
            if ($text === '') {
                continue;
            }

            $entries[] = [
                'element_id' => $element_id,
                'tag' => isset($entry['tag']) ? strtolower((string) $entry['tag']) : '',
                'text' => $text,
                'link_target' => isset($entry['link_target']) ? trim((string) $entry['link_target']) : '',
                'sequence_index' => isset($entry['sequence_index']) ? (int) $entry['sequence_index'] : 0,
            ];
        }

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $element_entries
     * @return array<int, string>
     */
    private function collect_sample_text(array $element_entries)
    {
        $sample = [];
        foreach ($element_entries as $entry) {
            if (! is_array($entry) || empty($entry['text'])) {
                continue;
            }

            $sample[] = (string) $entry['text'];
            if (count($sample) >= 3) {
                break;
            }
        }

        return $sample;
    }

    /**
     * @param array<int, array<string, mixed>> $element_entries
     * @return array<string, mixed>
     */
    private function find_primary_heading(array $element_entries)
    {
        foreach ($element_entries as $entry) {
            if (! is_array($entry) || empty($entry['tag']) || empty($entry['text'])) {
                continue;
            }

            $tag = strtolower((string) $entry['tag']);
            if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                return $entry;
            }
        }

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $element_entries
     * @param array<string, bool>              $used_hashes
     * @return array<string, mixed>
     */
    private function find_primary_body(array $element_entries, array $used_hashes = [])
    {
        $fallback = [];

        foreach ($element_entries as $entry) {
            if (! is_array($entry) || empty($entry['text'])) {
                continue;
            }

            $tag = isset($entry['tag']) ? strtolower((string) $entry['tag']) : '';
            $text = trim((string) $entry['text']);
            if ($text === '' || isset($used_hashes[$this->hash_text($text)])) {
                continue;
            }

            if (in_array($tag, ['a', 'button', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                continue;
            }

            if (strlen($text) >= 30 && $tag === 'p') {
                return $entry;
            }

            if (strlen($text) >= 40 && empty($fallback)) {
                $fallback = $entry;
            }
        }

        return $fallback;
    }

    /**
     * @param array<int, array<string, mixed>> $element_entries
     * @param array<string, mixed>             $signals
     * @return array<string, mixed>
     */
    private function find_primary_cta(array $element_entries, array $signals)
    {
        $link_count = isset($signals['link_element_count']) ? (int) $signals['link_element_count'] : 0;
        $cta_hits = isset($signals['cta_keyword_hits']) ? (int) $signals['cta_keyword_hits'] : 0;

        foreach ($element_entries as $entry) {
            if (! is_array($entry) || empty($entry['text'])) {
                continue;
            }

            $tag = isset($entry['tag']) ? strtolower((string) $entry['tag']) : '';
            if (! in_array($tag, ['a', 'button'], true)) {
                continue;
            }

            $text = trim((string) $entry['text']);
            if ($text === '' || strlen($text) > 90) {
                continue;
            }

            if (preg_match('/\b(skip to main content|skip to footer)\b/i', $text)) {
                continue;
            }

            $is_actionable = preg_match('/\b(book|call|start|get started|learn more|schedule|request|shop|quiz|contact)\b/i', $text) === 1;
            if (! $is_actionable && $cta_hits < 1 && $link_count > 3) {
                continue;
            }

            return $entry;
        }

        return [];
    }

    /**
     * @param string             $section_id
     * @param string             $context_tag
     * @param string             $content_role
     * @param string             $value_preview
     * @param string             $pattern_seed
     * @param array<int, string> $source_refs
     * @param array<int, string> $notes
     * @return array<string, mixed>
     */
    private function build_unit($section_id, $context_tag, $content_role, $value_preview, $pattern_seed, array $source_refs, array $notes)
    {
        return [
            'section_id' => sanitize_key((string) $section_id),
            'context_tag' => sanitize_key((string) $context_tag),
            'content_role' => sanitize_key((string) $content_role),
            'value_preview' => trim((string) $value_preview),
            'pattern_seed' => trim((string) $pattern_seed),
            'source_refs' => array_values(array_filter(array_map('strval', $source_refs))),
            'notes' => array_values(array_filter(array_map('sanitize_text_field', $notes))),
        ];
    }

    /**
     * @param string $section_id
     * @param string $element_id
     * @return array<int, string>
     */
    private function build_source_refs($section_id, $element_id = '')
    {
        $refs = [];
        $section_id = sanitize_key((string) $section_id);
        $element_id = sanitize_text_field((string) $element_id);

        if ($section_id !== '') {
            $refs[] = 'sections.v2#' . $section_id;
            $refs[] = 'ingestion-package.v2#sections.' . $section_id;
        }

        if ($element_id !== '') {
            $refs[] = 'elements.v2#' . $element_id;
        }

        return $refs;
    }

    /**
     * @param array<int, array<string, mixed>> $units
     * @return array<int, array<string, mixed>>
     */
    private function dedupe_units(array $units)
    {
        $deduped = [];

        foreach ($units as $unit) {
            if (! is_array($unit) || empty($unit['value_preview']) || empty($unit['content_role'])) {
                continue;
            }

            $key = sanitize_key((string) $unit['content_role']) . '|' . $this->hash_text((string) $unit['value_preview']);
            if (! isset($deduped[$key])) {
                $deduped[$key] = $unit;
            }
        }

        return array_values($deduped);
    }

    /**
     * @param string $value
     * @return string
     */
    private function hash_text($value)
    {
        return hash('sha256', strtolower(trim((string) $value)));
    }
}
