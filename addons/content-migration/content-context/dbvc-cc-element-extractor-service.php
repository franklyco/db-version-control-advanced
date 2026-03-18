<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Element_Extractor_Service
{
    /**
     * @param DOMXPath $xpath
     * @param array<int, DOMNode> $context_nodes
     * @param string $page_url
     * @param array<string, mixed> $options
     * @return array<string, array<string, mixed>>
     */
    public static function extract_artifacts(DOMXPath $xpath, array $context_nodes, $page_url, array $options)
    {
        $policy = DBVC_CC_Attribute_Scrub_Policy_Service::get_policy($options);
        $max_elements = isset($options['capture_max_elements_per_page']) ? max(100, (int) $options['capture_max_elements_per_page']) : 2000;
        $max_chars_per_element = isset($options['capture_max_chars_per_element']) ? max(100, (int) $options['capture_max_chars_per_element']) : 1000;
        $dbvc_cc_chunk_size = self::dbvc_cc_resolve_chunk_size($options);
        $dbvc_cc_stage_timeout_seconds = self::dbvc_cc_resolve_stage_timeout_seconds($options);
        $dbvc_cc_stage_started_at = microtime(true);

        $include_attributes = ! empty($options['capture_include_attribute_context']);
        $include_dom_path = ! empty($options['capture_include_dom_path']);

        $elements = [];
        $seen = [];
        $truncated = false;
        $dbvc_cc_is_partial = false;
        $dbvc_cc_partial_reason = '';
        $dbvc_cc_resume_marker = [];
        $warnings = [];

        $scrub_totals = [
            'kept' => 0,
            'dropped' => 0,
            'hashed' => 0,
            'tokenized' => 0,
        ];
        $scrub_by_attribute = [];

        foreach ($context_nodes as $dbvc_cc_context_index => $context_node) {
            $nodes = $xpath->query('.//h1 | .//h2 | .//h3 | .//h4 | .//h5 | .//h6 | .//p | .//li | .//blockquote | .//a | .//button | .//figcaption | .//label', $context_node);
            if (! $nodes) {
                continue;
            }

            $dbvc_cc_node_count = $nodes->length;
            for ($dbvc_cc_node_offset = 0; $dbvc_cc_node_offset < $dbvc_cc_node_count; $dbvc_cc_node_offset += $dbvc_cc_chunk_size) {
                if (self::dbvc_cc_has_stage_timed_out($dbvc_cc_stage_started_at, $dbvc_cc_stage_timeout_seconds)) {
                    $truncated = true;
                    $dbvc_cc_is_partial = true;
                    $dbvc_cc_partial_reason = 'timeout';
                    $dbvc_cc_resume_marker = [
                        'context_node_index' => (int) $dbvc_cc_context_index,
                        'node_offset' => (int) $dbvc_cc_node_offset,
                    ];
                    break 2;
                }

                $dbvc_cc_chunk_end = min($dbvc_cc_node_offset + $dbvc_cc_chunk_size, $dbvc_cc_node_count);
                for ($dbvc_cc_node_index = $dbvc_cc_node_offset; $dbvc_cc_node_index < $dbvc_cc_chunk_end; $dbvc_cc_node_index++) {
                    $node = $nodes->item($dbvc_cc_node_index);
                    if (! ($node instanceof DOMElement)) {
                        continue;
                    }

                    if (count($elements) >= $max_elements) {
                        $truncated = true;
                        $dbvc_cc_is_partial = true;
                        $dbvc_cc_partial_reason = 'max_elements';
                        $dbvc_cc_resume_marker = [
                            'context_node_index' => (int) $dbvc_cc_context_index,
                            'node_offset' => (int) $dbvc_cc_node_index,
                        ];
                        break 3;
                    }

                    $text = self::normalize_text($node->textContent, $max_chars_per_element);
                    if ($text === '') {
                        continue;
                    }

                    $tag = strtolower($node->tagName);
                    $dom_path = self::build_dom_path($node);
                    $text_hash = hash('sha256', $text);
                    $dedupe_key = $dom_path . '|' . $text_hash;
                    if (isset($seen[$dedupe_key])) {
                        continue;
                    }
                    $seen[$dedupe_key] = true;

                    $raw_attributes = $include_attributes ? self::collect_candidate_attributes($node) : [];
                    $scrubbed = DBVC_CC_Attribute_Scrubber_Service::scrub_attributes($raw_attributes, $policy);

                    self::merge_totals($scrub_totals, isset($scrubbed['totals']) && is_array($scrubbed['totals']) ? $scrubbed['totals'] : []);
                    self::merge_by_attribute($scrub_by_attribute, isset($scrubbed['by_attribute']) && is_array($scrubbed['by_attribute']) ? $scrubbed['by_attribute'] : []);

                    $parent_tag = '';
                    if ($node->parentNode instanceof DOMElement) {
                        $parent_tag = strtolower($node->parentNode->tagName);
                    }

                    $sequence_index = count($elements) + 1;
                    $stored_path = $include_dom_path ? $dom_path : '';
                    $element_id = 'dbvc_cc_el_' . substr(hash('sha256', $dom_path . '|' . $text_hash . '|' . $tag), 0, 16);

                    $elements[] = [
                        'element_id' => $element_id,
                        'tag' => $tag,
                        'text' => $text,
                        'text_hash' => $text_hash,
                        'sequence_index' => $sequence_index,
                        'dom_path' => $stored_path,
                        'parent_tag' => $parent_tag,
                        'heading_context' => self::extract_heading_context($xpath, $node, $max_chars_per_element),
                        'attributes' => isset($scrubbed['attributes']) && is_array($scrubbed['attributes']) ? $scrubbed['attributes'] : [],
                        'attribute_scrub' => [
                            'profile' => isset($policy['profile']) ? (string) $policy['profile'] : DBVC_CC_Contracts::SCRUB_PROFILE_DETERMINISTIC_DEFAULT,
                            'actions' => isset($scrubbed['actions']) && is_array($scrubbed['actions']) ? $scrubbed['actions'] : [],
                        ],
                        'link_target' => self::extract_link_target($node, $page_url),
                        'media_refs' => [],
                    ];
                }
            }
        }

        if ($truncated && $dbvc_cc_partial_reason === 'max_elements') {
            $warnings[] = sprintf('Element extraction capped at %d elements for this page.', $max_elements);
        }
        if ($dbvc_cc_is_partial && $dbvc_cc_partial_reason === 'timeout') {
            $warnings[] = sprintf('Element extraction timed out after %.2f seconds.', $dbvc_cc_stage_timeout_seconds);
        }

        if (empty($policy['enabled'])) {
            $warnings[] = 'Attribute scrub policy is disabled; raw candidate attributes were retained.';
        }

        $generated_at = current_time('c');
        $elements_payload = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'elements.v2',
            'source_url' => (string) $page_url,
            'generated_at' => $generated_at,
            'capture_mode' => isset($options['capture_mode']) ? (string) $options['capture_mode'] : DBVC_CC_Contracts::CAPTURE_MODE_DEEP,
            'element_count' => count($elements),
            'truncated' => $truncated,
            'processing' => [
                'is_partial' => $dbvc_cc_is_partial,
                'partial_reason' => $dbvc_cc_partial_reason,
                'resume_marker' => ! empty($dbvc_cc_resume_marker) ? $dbvc_cc_resume_marker : (object) [],
                'chunk_size' => $dbvc_cc_chunk_size,
                'stage_timeout_seconds' => $dbvc_cc_stage_timeout_seconds,
                'max_elements' => $max_elements,
            ],
            'elements' => $elements,
        ];

        $scrub_report_payload = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'attribute-scrub-report.v2',
            'source_url' => (string) $page_url,
            'generated_at' => $generated_at,
            'policy_version' => '1.0',
            'policy_hash' => isset($policy['policy_hash']) ? (string) $policy['policy_hash'] : hash('sha256', 'dbvc_cc_default_policy'),
            'profile' => isset($policy['profile']) ? (string) $policy['profile'] : DBVC_CC_Contracts::SCRUB_PROFILE_DETERMINISTIC_DEFAULT,
            'totals' => $scrub_totals,
            'by_attribute' => $scrub_by_attribute,
            'warnings' => $warnings,
        ];

        return [
            'elements' => $elements_payload,
            'scrub_report' => $scrub_report_payload,
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

    /**
     * @param string $text
     * @param int $max_chars
     * @return string
     */
    private static function normalize_text($text, $max_chars)
    {
        $value = trim((string) $text);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', $value);
        if (! is_string($value)) {
            return '';
        }

        if ($max_chars <= 0) {
            return trim($value);
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value, 'UTF-8') > $max_chars) {
                return trim(mb_substr($value, 0, $max_chars, 'UTF-8'));
            }
            return trim($value);
        }

        if (strlen($value) > $max_chars) {
            return trim(substr($value, 0, $max_chars));
        }

        return trim($value);
    }

    /**
     * @param DOMElement $node
     * @return string
     */
    private static function build_dom_path(DOMElement $node)
    {
        $segments = [];
        $current = $node;

        while ($current instanceof DOMElement) {
            $tag = strtolower($current->tagName);
            $position = 1;
            $sibling = $current->previousSibling;
            while ($sibling) {
                if ($sibling instanceof DOMElement && strtolower($sibling->tagName) === $tag) {
                    $position++;
                }
                $sibling = $sibling->previousSibling;
            }

            $segments[] = $tag . '[' . $position . ']';

            if ($current->parentNode instanceof DOMDocument) {
                break;
            }

            if (! ($current->parentNode instanceof DOMElement)) {
                break;
            }
            $current = $current->parentNode;
        }

        $segments = array_reverse($segments);
        return '/' . implode('/', $segments);
    }

    /**
     * @param DOMElement $node
     * @return array<string, string>
     */
    private static function collect_candidate_attributes(DOMElement $node)
    {
        $attributes = [];
        if (! $node->hasAttributes()) {
            return $attributes;
        }

        foreach ($node->attributes as $attribute) {
            if (! ($attribute instanceof DOMAttr)) {
                continue;
            }

            $name = strtolower(trim((string) $attribute->name));
            if ($name === '') {
                continue;
            }

            if ($name === 'class' || $name === 'id' || $name === 'style' || strpos($name, 'data-') === 0 || strpos($name, 'aria-') === 0 || strpos($name, 'on') === 0) {
                $attributes[$name] = (string) $attribute->value;
            }
        }

        return $attributes;
    }

    /**
     * @param DOMXPath $xpath
     * @param DOMElement $node
     * @param int $max_chars
     * @return array<string, string>
     */
    private static function extract_heading_context(DOMXPath $xpath, DOMElement $node, $max_chars)
    {
        $context = [];

        for ($level = 1; $level <= 6; $level++) {
            $heading_query = sprintf('(preceding::h%d)[last()]', $level);
            $heading = $xpath->query($heading_query, $node)->item(0);
            if (! ($heading instanceof DOMElement)) {
                continue;
            }

            $text = self::normalize_text($heading->textContent, $max_chars);
            if ($text === '') {
                continue;
            }

            $context['h' . $level] = $text;
        }

        return $context;
    }

    /**
     * @param DOMElement $node
     * @param string $page_url
     * @return string
     */
    private static function extract_link_target(DOMElement $node, $page_url)
    {
        if (strtolower($node->tagName) !== 'a') {
            return '';
        }

        $href = trim((string) $node->getAttribute('href'));
        if ($href === '' || preg_match('/^\s*javascript:/i', $href)) {
            return '';
        }

        return dbvc_cc_convert_to_absolute_url($href, $page_url);
    }

    /**
     * @param array<string, int> $totals
     * @param array<string, mixed> $incoming
     * @return void
     */
    private static function merge_totals(array &$totals, array $incoming)
    {
        foreach (['kept', 'dropped', 'hashed', 'tokenized'] as $key) {
            $totals[$key] += isset($incoming[$key]) ? (int) $incoming[$key] : 0;
        }
    }

    /**
     * @param array<string, array<string, int>> $target
     * @param array<string, mixed> $incoming
     * @return void
     */
    private static function merge_by_attribute(array &$target, array $incoming)
    {
        foreach ($incoming as $category => $counts) {
            if (! is_array($counts)) {
                continue;
            }
            if (! isset($target[$category])) {
                $target[$category] = [
                    'kept' => 0,
                    'dropped' => 0,
                    'hashed' => 0,
                    'tokenized' => 0,
                ];
            }
            foreach (['kept', 'dropped', 'hashed', 'tokenized'] as $key) {
                $target[$category][$key] += isset($counts[$key]) ? (int) $counts[$key] : 0;
            }
        }
    }
}
