<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Section_Field_Candidate_Service
{
    /**
     * @var DBVC_CC_Section_Field_Candidate_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_Section_Field_Candidate_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param string $domain
     * @param string $path
     * @param bool   $force_rebuild
     * @return array<string, mixed>|WP_Error
     */
    public function build_candidates($domain, $path, $force_rebuild = false)
    {
        $context = $this->resolve_page_context($domain, $path);
        if (is_wp_error($context)) {
            return $context;
        }

        $existing = $this->read_json_file($context['candidates_file']);
        $catalog_result = DBVC_CC_Target_Field_Catalog_Service::get_instance()->get_catalog($context['domain'], true);
        if (is_wp_error($catalog_result)) {
            return $catalog_result;
        }

        $catalog = isset($catalog_result['catalog']) && is_array($catalog_result['catalog']) ? $catalog_result['catalog'] : [];
        $catalog_fingerprint = isset($catalog_result['catalog_fingerprint']) ? (string) $catalog_result['catalog_fingerprint'] : '';

        $stale_reason = is_array($existing)
            ? $this->detect_stale_candidates_reason($context, $existing, $catalog_fingerprint)
            : '';

        if (is_array($existing) && ! $force_rebuild && $stale_reason === '') {
            $existing = $this->hydrate_section_source_preview_fields($context, $existing);
            return [
                'status' => 'reused',
                'domain' => $context['domain'],
                'path' => $context['path'],
                'candidate_file' => $context['candidates_file'],
                'section_candidates' => $existing,
                'stale' => false,
                'stale_reason' => '',
            ];
        }

        if (is_array($existing) && $stale_reason !== '') {
            DBVC_CC_Artifact_Manager::log_event(
                [
                    'stage' => 'mapping_candidates',
                    'status' => 'stale_rebuild',
                    'page_url' => $context['source_url'],
                    'path' => $context['path'],
                    'message' => sprintf('Rebuilding section candidates because existing artifact is stale (%s).', $stale_reason),
                ]
            );
        }

        $sections_artifact = $this->read_json_file($context['sections_file']);
        if (! is_array($sections_artifact)) {
            return new WP_Error(
                'dbvc_cc_section_candidates_missing_sections',
                __('Section artifact is required before mapping candidates can be generated.', 'dbvc'),
                ['status' => 404]
            );
        }

        $elements_artifact = $this->read_json_file($context['elements_file']);
        $section_preview_map = $this->build_section_source_preview_map($sections_artifact, $elements_artifact);
        $typing_artifact = $this->read_json_file($context['section_typing_file']);
        $typing_map = $this->build_section_typing_map($typing_artifact);
        $meta_refs = $this->collect_meta_field_refs($catalog);
        $acf_refs = $this->collect_acf_field_refs($catalog);

        $sections = isset($sections_artifact['sections']) && is_array($sections_artifact['sections']) ? $sections_artifact['sections'] : [];
        $section_rows = [];
        $unresolved_total = 0;
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $section_id = isset($section['section_id']) ? sanitize_key((string) $section['section_id']) : '';
            if ($section_id === '') {
                continue;
            }

            $label = isset($section['section_label_candidate']) ? sanitize_text_field((string) $section['section_label_candidate']) : '';
            $element_ids = isset($section['element_ids']) && is_array($section['element_ids']) ? array_values($section['element_ids']) : [];
            $section_preview = isset($section_preview_map[$section_id]) && is_array($section_preview_map[$section_id])
                ? $section_preview_map[$section_id]
                : [];
            $section_archetype = isset($typing_map[$section_id]['section_type_candidate']) && $typing_map[$section_id]['section_type_candidate'] !== ''
                ? $typing_map[$section_id]['section_type_candidate']
                : $this->infer_section_archetype_from_label($label);

            $deterministic_candidates = $this->build_deterministic_candidates_for_archetype($section_archetype, $meta_refs, $acf_refs);
            $confidence_values = [];
            foreach ($deterministic_candidates as $candidate) {
                if (is_array($candidate) && isset($candidate['confidence'])) {
                    $confidence_values[] = (float) $candidate['confidence'];
                }
            }

            $unresolved_fields = [];
            if (empty($deterministic_candidates)) {
                $unresolved_fields[] = [
                    'reason' => 'no_deterministic_candidates',
                    'section_id' => $section_id,
                    'section_archetype' => $section_archetype,
                ];
                $unresolved_total++;
            }

            $section_rows[] = [
                'section_id' => $section_id,
                'section_label' => isset($section_preview['section_label']) && $section_preview['section_label'] !== ''
                    ? (string) $section_preview['section_label']
                    : $label,
                'section_archetype' => $section_archetype,
                'deterministic_candidates' => $deterministic_candidates,
                'ai_candidates' => [],
                'unresolved_fields' => $unresolved_fields,
                'collected_value_preview' => isset($section_preview['collected_value_preview']) ? (string) $section_preview['collected_value_preview'] : '',
                'collected_value_origin' => isset($section_preview['collected_value_origin']) ? (string) $section_preview['collected_value_origin'] : '',
                'collected_value_lines' => isset($section_preview['collected_value_lines']) && is_array($section_preview['collected_value_lines'])
                    ? array_values($section_preview['collected_value_lines'])
                    : [],
                'confidence_summary' => [
                    'deterministic_count' => count($deterministic_candidates),
                    'deterministic_max' => empty($confidence_values) ? 0.0 : max($confidence_values),
                    'deterministic_min' => empty($confidence_values) ? 0.0 : min($confidence_values),
                    'ai_count' => 0,
                ],
                'evidence_element_ids' => $element_ids,
                'evidence_media_ids' => [],
            ];
        }

        usort($section_rows, static function ($left, $right) {
            $left_id = isset($left['section_id']) ? (string) $left['section_id'] : '';
            $right_id = isset($right['section_id']) ? (string) $right['section_id'] : '';
            return strnatcasecmp($left_id, $right_id);
        });

        $payload = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'section-field-candidates.v1',
            'source_url' => $context['source_url'],
            'generated_at' => current_time('c'),
            'catalog_fingerprint' => $catalog_fingerprint,
            'sections' => $section_rows,
            'stats' => [
                'section_count' => count($section_rows),
                'unresolved_section_count' => $unresolved_total,
            ],
        ];
        $payload = $this->hydrate_section_source_preview_fields($context, $payload, $sections_artifact, $elements_artifact);

        if (! DBVC_CC_Artifact_Manager::write_json_file($context['candidates_file'], $payload)) {
            return new WP_Error(
                'dbvc_cc_section_candidates_write_failed',
                __('Could not write section-field candidates artifact.', 'dbvc'),
                ['status' => 500]
            );
        }

        DBVC_CC_Artifact_Manager::log_event(
            [
                'stage' => 'mapping_candidates',
                'status' => 'built',
                'page_url' => $context['source_url'],
                'path' => $context['path'],
                'message' => sprintf('Generated %d section mapping rows.', count($section_rows)),
            ]
        );

        return [
            'status' => 'built',
            'domain' => $context['domain'],
            'path' => $context['path'],
            'candidate_file' => $context['candidates_file'],
            'section_candidates' => $payload,
            'stale' => false,
            'stale_reason' => '',
        ];
    }

    /**
     * @param string $domain
     * @param string $path
     * @param bool   $build_if_missing
     * @return array<string, mixed>|WP_Error
     */
    public function get_candidates($domain, $path, $build_if_missing = true)
    {
        $context = $this->resolve_page_context($domain, $path);
        if (is_wp_error($context)) {
            return $context;
        }

        $payload = $this->read_json_file($context['candidates_file']);
        if (is_array($payload)) {
            $catalog_result = DBVC_CC_Target_Field_Catalog_Service::get_instance()->get_catalog($context['domain'], true);
            if (is_wp_error($catalog_result)) {
                return $catalog_result;
            }

            $catalog_fingerprint = isset($catalog_result['catalog_fingerprint']) ? (string) $catalog_result['catalog_fingerprint'] : '';
            $stale_reason = $this->detect_stale_candidates_reason($context, $payload, $catalog_fingerprint);
            if ($stale_reason !== '' && $build_if_missing) {
                return $this->build_candidates($context['domain'], $context['path'], true);
            }
            $payload = $this->hydrate_section_source_preview_fields($context, $payload);

            return [
                'status' => 'loaded',
                'domain' => $context['domain'],
                'path' => $context['path'],
                'candidate_file' => $context['candidates_file'],
                'section_candidates' => $payload,
                'stale' => $stale_reason !== '',
                'stale_reason' => $stale_reason,
            ];
        }

        if (! $build_if_missing) {
            return new WP_Error(
                'dbvc_cc_section_candidates_missing',
                __('Section-field candidates artifact has not been generated for this node.', 'dbvc'),
                ['status' => 404]
            );
        }

        return $this->build_candidates($context['domain'], $context['path'], false);
    }

    /**
     * @param array<string, string>     $context
     * @param array<string, mixed>      $payload
     * @param array<string, mixed>|null $sections_artifact
     * @param array<string, mixed>|null $elements_artifact
     * @return array<string, mixed>
     */
    private function hydrate_section_source_preview_fields(array $context, array $payload, $sections_artifact = null, $elements_artifact = null)
    {
        if (! isset($payload['sections']) || ! is_array($payload['sections'])) {
            return $payload;
        }

        $sections_artifact = is_array($sections_artifact) ? $sections_artifact : $this->read_json_file($context['sections_file']);
        $elements_artifact = is_array($elements_artifact) ? $elements_artifact : $this->read_json_file($context['elements_file']);
        $section_preview_map = $this->build_section_source_preview_map($sections_artifact, $elements_artifact);
        if (empty($section_preview_map)) {
            return $payload;
        }

        foreach ($payload['sections'] as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $section_id = isset($row['section_id']) ? sanitize_key((string) $row['section_id']) : '';
            if ($section_id === '' || ! isset($section_preview_map[$section_id])) {
                continue;
            }

            $section_preview = $section_preview_map[$section_id];
            $row['section_label'] = isset($section_preview['section_label']) ? (string) $section_preview['section_label'] : '';
            $row['collected_value_preview'] = isset($section_preview['collected_value_preview']) ? (string) $section_preview['collected_value_preview'] : '';
            $row['collected_value_origin'] = isset($section_preview['collected_value_origin']) ? (string) $section_preview['collected_value_origin'] : '';
            $row['collected_value_lines'] = isset($section_preview['collected_value_lines']) && is_array($section_preview['collected_value_lines'])
                ? array_values($section_preview['collected_value_lines'])
                : [];

            $payload['sections'][$index] = $row;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed>|null $sections_artifact
     * @param array<string, mixed>|null $elements_artifact
     * @return array<string, array<string, mixed>>
     */
    private function build_section_source_preview_map($sections_artifact, $elements_artifact)
    {
        $map = [];
        if (! is_array($sections_artifact)) {
            return $map;
        }

        $elements = is_array($elements_artifact) && isset($elements_artifact['elements']) && is_array($elements_artifact['elements'])
            ? $elements_artifact['elements']
            : [];
        $element_text_by_id = [];
        $element_tag_by_id = [];
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            $element_id = isset($element['element_id']) ? sanitize_key((string) $element['element_id']) : '';
            if ($element_id === '') {
                continue;
            }

            $element_text_by_id[$element_id] = isset($element['text']) ? sanitize_text_field((string) $element['text']) : '';
            $element_tag_by_id[$element_id] = isset($element['tag']) ? strtolower((string) $element['tag']) : '';
        }

        $sections = isset($sections_artifact['sections']) && is_array($sections_artifact['sections']) ? $sections_artifact['sections'] : [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $section_id = isset($section['section_id']) ? sanitize_key((string) $section['section_id']) : '';
            if ($section_id === '') {
                continue;
            }

            $label = isset($section['section_label_candidate']) ? sanitize_text_field((string) $section['section_label_candidate']) : '';
            $element_ids = isset($section['element_ids']) && is_array($section['element_ids']) ? array_values($section['element_ids']) : [];
            $sample_text = [];
            $sample_seen = [];
            $heading_text = '';

            foreach ($element_ids as $element_id) {
                $element_id = sanitize_key((string) $element_id);
                if ($element_id === '' || ! isset($element_text_by_id[$element_id])) {
                    continue;
                }

                $text = trim((string) $element_text_by_id[$element_id]);
                if ($text === '') {
                    continue;
                }

                $tag = isset($element_tag_by_id[$element_id]) ? (string) $element_tag_by_id[$element_id] : '';
                if ($heading_text === '' && preg_match('/^h[1-6]$/', $tag) === 1) {
                    $heading_text = $text;
                }

                $normalized_text = $this->normalize_preview_key($text);
                if (isset($sample_seen[$normalized_text])) {
                    continue;
                }

                $sample_seen[$normalized_text] = true;
                $sample_text[] = $text;
                if (count($sample_text) >= 3) {
                    break;
                }
            }

            $preview_parts = [];
            $preview_seen = [];
            if ($heading_text !== '') {
                $this->add_preview_part($preview_parts, $preview_seen, $heading_text);
            } elseif ($label !== '' && strtolower($label) !== 'intro') {
                $this->add_preview_part($preview_parts, $preview_seen, $label);
            }

            foreach ($sample_text as $text) {
                $this->add_preview_part($preview_parts, $preview_seen, $text);
                if (count($preview_parts) >= 3) {
                    break;
                }
            }

            $preview = $this->truncate_preview(implode(' | ', $preview_parts), 220);
            $map[$section_id] = [
                'section_label' => $label,
                'collected_value_preview' => $preview,
                'collected_value_origin' => $preview !== ''
                    ? (! empty($sample_text) ? 'section_sample_text' : ($label !== '' ? 'section_label_candidate' : ''))
                    : '',
                'collected_value_lines' => $sample_text,
            ];
        }

        return $map;
    }

    /**
     * @param array<int, string> $parts
     * @param array<string, bool> $seen
     * @param string $value
     * @return void
     */
    private function add_preview_part(array &$parts, array &$seen, $value)
    {
        $value = sanitize_text_field((string) $value);
        if ($value === '') {
            return;
        }

        $normalized_value = $this->normalize_preview_key($value);
        if (isset($seen[$normalized_value])) {
            return;
        }

        $seen[$normalized_value] = true;
        $parts[] = $value;
    }

    /**
     * @param string $value
     * @return string
     */
    private function normalize_preview_key($value)
    {
        $value = sanitize_text_field((string) $value);
        if ($value === '') {
            return '';
        }

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    /**
     * @param string $value
     * @param int    $max_chars
     * @return string
     */
    private function truncate_preview($value, $max_chars)
    {
        $value = sanitize_text_field((string) $value);
        if ($value === '' || $max_chars <= 0) {
            return $value;
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value, 'UTF-8') <= $max_chars) {
                return $value;
            }

            return rtrim(mb_substr($value, 0, $max_chars - 1, 'UTF-8')) . '...';
        }

        if (strlen($value) <= $max_chars) {
            return $value;
        }

        return rtrim(substr($value, 0, $max_chars - 1)) . '...';
    }

    /**
     * @param array<string, string> $context
     * @param array<string, mixed>  $existing
     * @param string                $catalog_fingerprint
     * @return string
     */
    private function detect_stale_candidates_reason(array $context, array $existing, $catalog_fingerprint)
    {
        $artifact_schema_version = isset($existing['artifact_schema_version']) ? (string) $existing['artifact_schema_version'] : '';
        if ($artifact_schema_version !== DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION) {
            return 'artifact_schema_mismatch';
        }

        $existing_source_url = isset($existing['source_url']) ? esc_url_raw((string) $existing['source_url']) : '';
        $current_source_url = isset($context['source_url']) ? esc_url_raw((string) $context['source_url']) : '';
        if ($existing_source_url !== '' && $current_source_url !== '' && $existing_source_url !== $current_source_url) {
            return 'source_url_mismatch';
        }

        $existing_catalog_fingerprint = isset($existing['catalog_fingerprint']) ? (string) $existing['catalog_fingerprint'] : '';
        if ($existing_catalog_fingerprint !== '' && $catalog_fingerprint !== '' && $existing_catalog_fingerprint !== $catalog_fingerprint) {
            return 'catalog_fingerprint_mismatch';
        }

        $candidate_mtime = is_file($context['candidates_file']) ? (int) @filemtime($context['candidates_file']) : 0;
        if ($candidate_mtime <= 0) {
            return 'candidate_file_missing';
        }

        $dependency_mtime = $this->max_mtime(
            [
                isset($context['artifact_file']) ? (string) $context['artifact_file'] : '',
                isset($context['elements_file']) ? (string) $context['elements_file'] : '',
                isset($context['sections_file']) ? (string) $context['sections_file'] : '',
                isset($context['section_typing_file']) ? (string) $context['section_typing_file'] : '',
            ]
        );
        if ($dependency_mtime > $candidate_mtime) {
            return 'source_artifact_newer';
        }

        return '';
    }

    /**
     * @param array<int, string> $paths
     * @return int
     */
    private function max_mtime(array $paths)
    {
        $latest = 0;
        foreach ($paths as $path) {
            if (! is_string($path) || $path === '' || ! is_file($path)) {
                continue;
            }
            $mtime = (int) @filemtime($path);
            if ($mtime > $latest) {
                $latest = $mtime;
            }
        }

        return $latest;
    }

    /**
     * @param string $archetype
     * @param array<string, array<int, string>> $meta_refs
     * @param array<string, array<int, string>> $acf_refs
     * @return array<int, array<string, mixed>>
     */
    private function build_deterministic_candidates_for_archetype($archetype, array $meta_refs, array $acf_refs)
    {
        $archetype_key = sanitize_key((string) $archetype);
        $candidate_refs = [];

        if ($archetype_key === 'hero') {
            $candidate_refs[] = ['target_ref' => 'core:post_title', 'confidence' => 0.9, 'reason' => 'hero_heading_to_title'];
            $candidate_refs[] = ['target_ref' => 'core:post_content', 'confidence' => 0.7, 'reason' => 'hero_copy_to_content'];
            $candidate_refs = array_merge($candidate_refs, $this->build_pattern_candidates('hero', 0.82, $meta_refs, $acf_refs));
            $candidate_refs = array_merge($candidate_refs, $this->build_pattern_candidates('cta', 0.78, $meta_refs, $acf_refs));
        } elseif ($archetype_key === 'faq') {
            $candidate_refs = array_merge($candidate_refs, $this->build_pattern_candidates('faq', 0.88, $meta_refs, $acf_refs));
            $candidate_refs = array_merge($candidate_refs, $this->build_pattern_candidates('question', 0.84, $meta_refs, $acf_refs));
            $candidate_refs = array_merge($candidate_refs, $this->build_pattern_candidates('answer', 0.84, $meta_refs, $acf_refs));
        } elseif ($archetype_key === 'contact') {
            $candidate_refs = array_merge($candidate_refs, $this->build_pattern_candidates('contact', 0.84, $meta_refs, $acf_refs));
            $candidate_refs = array_merge($candidate_refs, $this->build_pattern_candidates('phone', 0.82, $meta_refs, $acf_refs));
            $candidate_refs = array_merge($candidate_refs, $this->build_pattern_candidates('email', 0.82, $meta_refs, $acf_refs));
            $candidate_refs = array_merge($candidate_refs, $this->build_pattern_candidates('address', 0.82, $meta_refs, $acf_refs));
        } elseif ($archetype_key === 'pricing') {
            $candidate_refs = array_merge($candidate_refs, $this->build_pattern_candidates('price', 0.86, $meta_refs, $acf_refs));
            $candidate_refs = array_merge($candidate_refs, $this->build_pattern_candidates('plan', 0.8, $meta_refs, $acf_refs));
        } elseif ($archetype_key === 'cta') {
            $candidate_refs = array_merge($candidate_refs, $this->build_pattern_candidates('cta', 0.85, $meta_refs, $acf_refs));
            $candidate_refs = array_merge($candidate_refs, $this->build_pattern_candidates('button', 0.82, $meta_refs, $acf_refs));
            $candidate_refs = array_merge($candidate_refs, $this->build_pattern_candidates('link', 0.8, $meta_refs, $acf_refs));
        } else {
            $candidate_refs[] = ['target_ref' => 'core:post_content', 'confidence' => 0.72, 'reason' => 'default_content_mapping'];
            $candidate_refs = array_merge($candidate_refs, $this->build_pattern_candidates('content', 0.74, $meta_refs, $acf_refs));
            $candidate_refs = array_merge($candidate_refs, $this->build_pattern_candidates('description', 0.74, $meta_refs, $acf_refs));
        }

        $by_target = [];
        foreach ($candidate_refs as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $target_ref = isset($candidate['target_ref']) ? (string) $candidate['target_ref'] : '';
            if ($target_ref === '') {
                continue;
            }

            $confidence = isset($candidate['confidence']) ? (float) $candidate['confidence'] : 0.5;
            $reason = isset($candidate['reason']) ? sanitize_key((string) $candidate['reason']) : '';
            if (! isset($by_target[$target_ref]) || $confidence > (float) $by_target[$target_ref]['confidence']) {
                $by_target[$target_ref] = [
                    'candidate_id' => 'dbvc_cc_cand_' . substr(hash('sha256', $archetype_key . '|' . $target_ref), 0, 16),
                    'target_ref' => $target_ref,
                    'confidence' => max(0.0, min(1.0, $confidence)),
                    'reason' => $reason,
                ];
            }
        }

        uasort($by_target, static function ($left, $right) {
            $left_conf = isset($left['confidence']) ? (float) $left['confidence'] : 0.0;
            $right_conf = isset($right['confidence']) ? (float) $right['confidence'] : 0.0;
            if ($left_conf === $right_conf) {
                $left_ref = isset($left['target_ref']) ? (string) $left['target_ref'] : '';
                $right_ref = isset($right['target_ref']) ? (string) $right['target_ref'] : '';
                return strnatcasecmp($left_ref, $right_ref);
            }
            return $left_conf > $right_conf ? -1 : 1;
        });

        return array_values($by_target);
    }

    /**
     * @param string                              $pattern
     * @param float                               $confidence
     * @param array<string, array<int, string>>   $meta_refs
     * @param array<string, array<int, string>>   $acf_refs
     * @return array<int, array<string, mixed>>
     */
    private function build_pattern_candidates($pattern, $confidence, array $meta_refs, array $acf_refs)
    {
        $pattern_key = sanitize_key((string) $pattern);
        $candidates = [];
        $refs = [];
        if (isset($meta_refs[$pattern_key]) && is_array($meta_refs[$pattern_key])) {
            $refs = array_merge($refs, $meta_refs[$pattern_key]);
        }
        if (isset($acf_refs[$pattern_key]) && is_array($acf_refs[$pattern_key])) {
            $refs = array_merge($refs, $acf_refs[$pattern_key]);
        }

        foreach (array_slice(array_values(array_unique($refs)), 0, 12) as $target_ref) {
            $candidates[] = [
                'target_ref' => (string) $target_ref,
                'confidence' => (float) $confidence,
                'reason' => 'pattern_' . $pattern_key,
            ];
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $catalog
     * @return array<string, array<int, string>>
     */
    private function collect_meta_field_refs(array $catalog)
    {
        $meta_catalog = isset($catalog['meta_catalog']) && is_array($catalog['meta_catalog']) ? $catalog['meta_catalog'] : [];
        $refs = [];
        foreach ($meta_catalog as $object_type => $subtypes) {
            if (! is_array($subtypes)) {
                continue;
            }
            foreach ($subtypes as $subtype => $meta_entries) {
                if (! is_array($meta_entries)) {
                    continue;
                }
                foreach ($meta_entries as $meta_key => $meta_entry) {
                    if (! is_array($meta_entry)) {
                        continue;
                    }
                    $field_key = sanitize_key((string) $meta_key);
                    if ($field_key === '') {
                        continue;
                    }
                    $target_ref = sprintf('meta:%s:%s:%s', sanitize_key((string) $object_type), sanitize_key((string) $subtype), $field_key);
                    foreach ($this->extract_patterns_from_field_key($field_key) as $pattern) {
                        if (! isset($refs[$pattern])) {
                            $refs[$pattern] = [];
                        }
                        $refs[$pattern][] = $target_ref;
                    }
                }
            }
        }

        ksort($refs);
        return $refs;
    }

    /**
     * @param array<string, mixed> $catalog
     * @return array<string, array<int, string>>
     */
    private function collect_acf_field_refs(array $catalog)
    {
        $acf_catalog = isset($catalog['acf_catalog']) && is_array($catalog['acf_catalog']) ? $catalog['acf_catalog'] : [];
        $groups = isset($acf_catalog['groups']) && is_array($acf_catalog['groups']) ? $acf_catalog['groups'] : [];

        $refs = [];
        foreach ($groups as $group_key => $group) {
            if (! is_array($group) || ! isset($group['fields']) || ! is_array($group['fields'])) {
                continue;
            }

            foreach ($group['fields'] as $field_key => $field) {
                if (! is_array($field)) {
                    continue;
                }
                $name = isset($field['name']) ? sanitize_key((string) $field['name']) : sanitize_key((string) $field_key);
                if ($name === '') {
                    continue;
                }

                $target_ref = sprintf('acf:%s:%s', sanitize_key((string) $group_key), sanitize_key((string) $field_key));
                $pattern_sources = [$name];
                $field_context = isset($field['field_context']) && is_array($field['field_context']) ? $field['field_context'] : [];
                if (! empty($field_context['name_path'])) {
                    $pattern_sources[] = (string) $field_context['name_path'];
                }
                if (! empty($field_context['resolved_purpose'])) {
                    $pattern_sources[] = (string) $field_context['resolved_purpose'];
                } elseif (! empty($field_context['default_purpose'])) {
                    $pattern_sources[] = (string) $field_context['default_purpose'];
                }

                $patterns = [];
                foreach ($pattern_sources as $pattern_source) {
                    $patterns = array_merge($patterns, $this->extract_patterns_from_field_key($pattern_source));
                }

                foreach (array_values(array_unique($patterns)) as $pattern) {
                    if (! isset($refs[$pattern])) {
                        $refs[$pattern] = [];
                    }
                    $refs[$pattern][] = $target_ref;
                }
            }
        }

        ksort($refs);
        return $refs;
    }

    /**
     * @param string $field_key
     * @return array<int, string>
     */
    private function extract_patterns_from_field_key($field_key)
    {
        $patterns = [];
        $key = strtolower((string) $field_key);
        $map = [
            'hero' => ['hero', 'banner'],
            'faq' => ['faq'],
            'question' => ['question'],
            'answer' => ['answer'],
            'cta' => ['cta', 'call_to_action'],
            'button' => ['button', 'btn'],
            'link' => ['link', 'url'],
            'contact' => ['contact'],
            'phone' => ['phone', 'tel'],
            'email' => ['email'],
            'address' => ['address'],
            'pricing' => ['pricing'],
            'price' => ['price', 'cost'],
            'plan' => ['plan', 'package'],
            'content' => ['content', 'body', 'text'],
            'description' => ['description', 'summary', 'intro'],
        ];

        foreach ($map as $pattern => $needles) {
            foreach ($needles as $needle) {
                if (strpos($key, $needle) !== false) {
                    $patterns[] = $pattern;
                    break;
                }
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * @param array<string, mixed>|null $typing_artifact
     * @return array<string, array<string, string>>
     */
    private function build_section_typing_map($typing_artifact)
    {
        $map = [];
        if (! is_array($typing_artifact)) {
            return $map;
        }

        $rows = isset($typing_artifact['section_typings']) && is_array($typing_artifact['section_typings']) ? $typing_artifact['section_typings'] : [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $section_id = isset($row['section_id']) ? sanitize_key((string) $row['section_id']) : '';
            if ($section_id === '') {
                continue;
            }
            $map[$section_id] = [
                'section_type_candidate' => isset($row['section_type_candidate']) ? sanitize_key((string) $row['section_type_candidate']) : '',
                'mode' => isset($row['mode']) ? sanitize_key((string) $row['mode']) : '',
            ];
        }

        return $map;
    }

    /**
     * @param string $label
     * @return string
     */
    private function infer_section_archetype_from_label($label)
    {
        $value = strtolower((string) $label);
        if ($value === '') {
            return 'content';
        }

        if (strpos($value, 'faq') !== false || strpos($value, 'question') !== false) {
            return 'faq';
        }
        if (strpos($value, 'contact') !== false) {
            return 'contact';
        }
        if (strpos($value, 'price') !== false || strpos($value, 'plan') !== false) {
            return 'pricing';
        }
        if (strpos($value, 'hero') !== false || strpos($value, 'banner') !== false) {
            return 'hero';
        }
        if (strpos($value, 'cta') !== false || strpos($value, 'call to action') !== false) {
            return 'cta';
        }

        return 'content';
    }

    /**
     * @param string $domain
     * @param string $path
     * @return array<string, string>|WP_Error
     */
    private function resolve_page_context($domain, $path)
    {
        $domain_key = $this->sanitize_domain_key($domain);
        if ($domain_key === '') {
            return new WP_Error(
                'dbvc_cc_section_candidates_domain_invalid',
                __('A valid domain key is required.', 'dbvc'),
                ['status' => 400]
            );
        }

        $relative_path = $this->normalize_relative_path($path);
        if ($relative_path === '') {
            return new WP_Error(
                'dbvc_cc_section_candidates_path_invalid',
                __('A valid page path is required.', 'dbvc'),
                ['status' => 400]
            );
        }

        $base_dir = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        if (! is_string($base_dir) || $base_dir === '' || ! is_dir($base_dir)) {
            return new WP_Error(
                'dbvc_cc_section_candidates_storage_missing',
                __('Content migration storage path is not available.', 'dbvc'),
                ['status' => 500]
            );
        }

        $base_real = realpath($base_dir);
        if (! is_string($base_real)) {
            return new WP_Error(
                'dbvc_cc_section_candidates_storage_invalid',
                __('Could not resolve content migration storage path.', 'dbvc'),
                ['status' => 500]
            );
        }

        $domain_dir = trailingslashit($base_real) . $domain_key;
        if (! is_dir($domain_dir)) {
            return new WP_Error(
                'dbvc_cc_section_candidates_domain_missing',
                __('No crawl storage was found for the requested domain.', 'dbvc'),
                ['status' => 404]
            );
        }

        $page_dir = trailingslashit($domain_dir) . $relative_path;
        if (! is_dir($page_dir)) {
            return new WP_Error(
                'dbvc_cc_section_candidates_path_missing',
                __('No crawl storage was found for the requested path.', 'dbvc'),
                ['status' => 404]
            );
        }

        $slug = basename($relative_path);
        $artifact_file = trailingslashit($page_dir) . $slug . '.json';
        $elements_file = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_ELEMENTS_V2_SUFFIX;
        $sections_file = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_SECTIONS_V2_SUFFIX;
        $section_typing_file = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_SECTION_TYPING_V2_SUFFIX;
        $candidates_file = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_SECTION_FIELD_CANDIDATES_V1_SUFFIX;

        if (! dbvc_cc_path_is_within($candidates_file, $base_real)) {
            return new WP_Error(
                'dbvc_cc_section_candidates_file_invalid',
                __('Section candidates file path is invalid.', 'dbvc'),
                ['status' => 500]
            );
        }

        $artifact = $this->read_json_file($artifact_file);
        $source_url = '';
        if (is_array($artifact)) {
            if (isset($artifact['source_url'])) {
                $source_url = esc_url_raw((string) $artifact['source_url']);
            }
            if ($source_url === '' && isset($artifact['provenance']['source_url'])) {
                $source_url = esc_url_raw((string) $artifact['provenance']['source_url']);
            }
        }
        if ($source_url === '') {
            $source_url = 'https://' . $domain_key . '/' . ltrim($relative_path, '/');
        }

        return [
            'domain' => $domain_key,
            'path' => $relative_path,
            'slug' => $slug,
            'source_url' => $source_url,
            'artifact_file' => $artifact_file,
            'elements_file' => $elements_file,
            'sections_file' => $sections_file,
            'section_typing_file' => $section_typing_file,
            'candidates_file' => $candidates_file,
        ];
    }

    /**
     * @param string $path
     * @return array<string, mixed>|null
     */
    private function read_json_file($path)
    {
        if (! is_string($path) || $path === '' || ! file_exists($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param string $domain
     * @return string
     */
    private function sanitize_domain_key($domain)
    {
        $value = strtolower(trim((string) $domain));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-z0-9.\-]/', '', $value);
        return is_string($value) ? $value : '';
    }

    /**
     * @param string $path
     * @return string
     */
    private function normalize_relative_path($path)
    {
        $value = wp_normalize_path((string) $path);
        $value = trim($value, '/');
        if ($value === '') {
            return 'home';
        }

        if (strpos($value, '..') !== false) {
            return '';
        }

        $segments = array_filter(explode('/', $value), static function ($segment) {
            return $segment !== '';
        });

        $normalized_segments = [];
        foreach ($segments as $segment) {
            $safe_segment = sanitize_title((string) $segment);
            if ($safe_segment === '') {
                continue;
            }
            $normalized_segments[] = $safe_segment;
        }

        return empty($normalized_segments) ? '' : implode('/', $normalized_segments);
    }
}
