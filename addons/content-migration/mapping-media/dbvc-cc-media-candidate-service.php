<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Media_Candidate_Service
{
    /**
     * @var DBVC_CC_Media_Candidate_Service|null
     */
    private static $instance = null;

    /**
     * @var array<string, mixed>
     */
    private $options = [];

    /**
     * @return DBVC_CC_Media_Candidate_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->options = DBVC_CC_Settings_Service::get_options();
    }

    /**
     * @param string $domain
     * @param string $path
     * @param bool   $force_rebuild
     * @return array<string, mixed>|WP_Error
     */
    public function build_candidates($domain, $path, $force_rebuild = false)
    {
        $this->options = DBVC_CC_Settings_Service::get_options();
        $context = $this->resolve_page_context($domain, $path);
        if (is_wp_error($context)) {
            return $context;
        }

        $existing = $this->read_json_file($context['candidates_file']);
        $current_catalog_fingerprint = $this->resolve_catalog_fingerprint($context['domain']);
        $stale_reason = is_array($existing)
            ? $this->detect_stale_candidates_reason($context, $existing, $current_catalog_fingerprint)
            : '';

        if (is_array($existing) && ! $force_rebuild && $stale_reason === '') {
            return [
                'status' => 'reused',
                'domain' => $context['domain'],
                'path' => $context['path'],
                'source_url' => $context['source_url'],
                'candidate_file' => $context['candidates_file'],
                'media_candidates' => $existing,
                'stale' => false,
                'stale_reason' => '',
            ];
        }

        if (is_array($existing) && $stale_reason !== '') {
            DBVC_CC_Artifact_Manager::log_event(
                [
                    'stage' => 'mapping_media',
                    'status' => 'stale_rebuild',
                    'page_url' => $context['source_url'],
                    'path' => $context['path'],
                    'message' => sprintf('Rebuilding media candidates because existing artifact is stale (%s).', $stale_reason),
                ]
            );
        }

        $main_artifact = $this->read_json_file($context['artifact_file']);
        if (! is_array($main_artifact)) {
            return new WP_Error(
                'dbvc_cc_media_candidates_source_missing',
                __('Could not load page artifact for media candidate generation.', 'dbvc'),
                ['status' => 404]
            );
        }

        $elements_artifact = $this->read_json_file($context['elements_file']);
        $sections_artifact = $this->read_json_file($context['sections_file']);
        $element_to_section = $this->build_element_section_map($sections_artifact);
        $section_order = $this->build_section_order_map($sections_artifact);
        $dbvc_cc_media_policy_context = $this->dbvc_cc_build_media_policy_context($context['source_url']);

        $candidate_index = [];
        $stats = [
            'blocked_url_count' => 0,
            'blocked_url_examples' => [],
            'suppressed_preview_count' => 0,
            'mime_blocked_count' => 0,
            'source_hits' => 0,
        ];

        $this->collect_from_main_artifact($candidate_index, $stats, $context, $main_artifact, $dbvc_cc_media_policy_context);
        $this->collect_from_elements_artifact($candidate_index, $stats, $context, $elements_artifact, $element_to_section, $dbvc_cc_media_policy_context);

        $section_image_counts = [];
        foreach ($candidate_index as $candidate) {
            if (! is_array($candidate) || (string) ($candidate['media_kind'] ?? '') !== 'image') {
                continue;
            }

            $section_id = isset($candidate['source_section_id']) ? (string) $candidate['source_section_id'] : '';
            if ($section_id === '') {
                continue;
            }
            if (! isset($section_image_counts[$section_id])) {
                $section_image_counts[$section_id] = 0;
            }
            $section_image_counts[$section_id]++;
        }

        foreach ($candidate_index as $normalized_url => $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $candidate_index[$normalized_url]['role_candidates'] = $this->determine_role_candidates(
                $candidate,
                $section_order,
                $section_image_counts
            );
        }

        ksort($candidate_index);
        $media_items = array_values($candidate_index);

        $kind_counts = [];
        $preview_count = 0;
        foreach ($media_items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $kind = isset($item['media_kind']) ? sanitize_key((string) $item['media_kind']) : 'file';
            if (! isset($kind_counts[$kind])) {
                $kind_counts[$kind] = 0;
            }
            $kind_counts[$kind]++;

            if (! empty($item['preview_ref'])) {
                $preview_count++;
            }
        }
        ksort($kind_counts);

        $catalog_fingerprint = $current_catalog_fingerprint;

        $payload = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'media-candidates.v1',
            'source_url' => $context['source_url'],
            'generated_at' => current_time('c'),
            'catalog_fingerprint' => $catalog_fingerprint,
            'policy' => [
                'dbvc_cc_media_download_policy' => $dbvc_cc_media_policy_context['dbvc_cc_media_download_policy'],
                'dbvc_cc_media_discovery_mode' => $dbvc_cc_media_policy_context['dbvc_cc_media_discovery_mode'],
                'dbvc_cc_media_preview_thumbnail_enabled' => $dbvc_cc_media_policy_context['dbvc_cc_media_preview_thumbnail_enabled'] ? 1 : 0,
                'dbvc_cc_media_block_private_hosts' => $dbvc_cc_media_policy_context['dbvc_cc_media_block_private_hosts'] ? 1 : 0,
                'dbvc_cc_media_source_domain_allowlist' => array_values($dbvc_cc_media_policy_context['dbvc_cc_media_source_domain_allowlist']),
                'dbvc_cc_media_source_domain_denylist' => array_values($dbvc_cc_media_policy_context['dbvc_cc_media_source_domain_denylist']),
                'dbvc_cc_media_mime_allowlist' => array_values($dbvc_cc_media_policy_context['dbvc_cc_media_mime_allowlist']),
                'dbvc_cc_media_max_bytes_per_asset' => $dbvc_cc_media_policy_context['dbvc_cc_media_max_bytes_per_asset'],
            ],
            'provenance' => [
                'source_url' => $context['source_url'],
                'service' => 'dbvc_cc_media_candidate_service',
                'host_policy_mode' => $dbvc_cc_media_policy_context['dbvc_cc_media_block_private_hosts'] ? 'block_private_hosts' : 'allow_private_hosts',
            ],
            'media_items' => $media_items,
            'stats' => [
                'total_candidates' => count($media_items),
                'by_kind' => $kind_counts,
                'with_preview_refs' => $preview_count,
                'blocked_url_count' => $stats['blocked_url_count'],
                'blocked_url_examples' => array_values($stats['blocked_url_examples']),
                'suppressed_preview_count' => $stats['suppressed_preview_count'],
                'mime_blocked_count' => $stats['mime_blocked_count'],
                'source_hits' => $stats['source_hits'],
            ],
        ];

        if (! DBVC_CC_Artifact_Manager::write_json_file($context['candidates_file'], $payload)) {
            return new WP_Error(
                'dbvc_cc_media_candidates_write_failed',
                __('Could not write media candidates artifact.', 'dbvc'),
                ['status' => 500]
            );
        }

        DBVC_CC_Artifact_Manager::log_event(
            [
                'stage' => 'mapping_media',
                'status' => 'built',
                'page_url' => $context['source_url'],
                'path' => $context['path'],
                'message' => sprintf('Generated %d media candidates.', count($media_items)),
            ]
        );

        return [
            'status' => 'built',
            'domain' => $context['domain'],
            'path' => $context['path'],
            'source_url' => $context['source_url'],
            'candidate_file' => $context['candidates_file'],
            'media_candidates' => $payload,
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
            $current_catalog_fingerprint = $this->resolve_catalog_fingerprint($context['domain']);
            $stale_reason = $this->detect_stale_candidates_reason($context, $payload, $current_catalog_fingerprint);
            if ($stale_reason !== '' && $build_if_missing) {
                return $this->build_candidates($context['domain'], $context['path'], true);
            }

            return [
                'status' => 'loaded',
                'domain' => $context['domain'],
                'path' => $context['path'],
                'source_url' => $context['source_url'],
                'candidate_file' => $context['candidates_file'],
                'media_candidates' => $payload,
                'stale' => $stale_reason !== '',
                'stale_reason' => $stale_reason,
            ];
        }

        if (! $build_if_missing) {
            return new WP_Error(
                'dbvc_cc_media_candidates_missing',
                __('Media candidates artifact has not been generated for this node.', 'dbvc'),
                ['status' => 404]
            );
        }

        return $this->build_candidates($context['domain'], $context['path'], false);
    }

    /**
     * @param array<string, string> $context
     * @param array<string, mixed>  $existing
     * @param string                $current_catalog_fingerprint
     * @return string
     */
    private function detect_stale_candidates_reason(array $context, array $existing, $current_catalog_fingerprint)
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
        if (
            $existing_catalog_fingerprint !== ''
            && $current_catalog_fingerprint !== ''
            && $existing_catalog_fingerprint !== $current_catalog_fingerprint
        ) {
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
     * @param array<string, array<string, mixed>> $candidate_index
     * @param array<string, int>                  $stats
     * @param array<string, string>               $context
     * @param array<string, mixed>                $artifact
     * @param array<string, mixed>                $dbvc_cc_media_policy_context
     * @return void
     */
    private function collect_from_main_artifact(array &$candidate_index, array &$stats, array $context, array $artifact, array $dbvc_cc_media_policy_context)
    {
        $content = isset($artifact['content']) && is_array($artifact['content']) ? $artifact['content'] : [];
        $root_images = isset($content['images']) && is_array($content['images']) ? $content['images'] : [];
        foreach ($root_images as $image_item) {
            $url = $this->extract_media_url($image_item);
            $alt = is_array($image_item) && isset($image_item['alt']) ? sanitize_text_field((string) $image_item['alt']) : '';
            $caption = is_array($image_item) && isset($image_item['caption']) ? sanitize_text_field((string) $image_item['caption']) : '';

            $this->add_or_merge_candidate(
                $candidate_index,
                $stats,
                $context,
                [
                    'url' => $url,
                    'kind_hint' => 'image',
                    'source_tag' => 'artifact-content-images',
                    'source_element_id' => '',
                    'source_section_id' => '',
                    'alt_text' => $alt,
                    'caption_text' => $caption,
                    'surrounding_text' => '',
                ],
                $dbvc_cc_media_policy_context
            );
        }

        $sections = isset($content['sections']) && is_array($content['sections']) ? $content['sections'] : [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $section_id = isset($section['id']) ? sanitize_key((string) $section['id']) : '';
            $heading = isset($section['heading']) ? sanitize_text_field((string) $section['heading']) : '';
            $section_images = isset($section['images']) && is_array($section['images']) ? $section['images'] : [];
            foreach ($section_images as $image_item) {
                $url = $this->extract_media_url($image_item);
                $alt = is_array($image_item) && isset($image_item['alt']) ? sanitize_text_field((string) $image_item['alt']) : '';
                $caption = is_array($image_item) && isset($image_item['caption']) ? sanitize_text_field((string) $image_item['caption']) : '';

                $this->add_or_merge_candidate(
                    $candidate_index,
                    $stats,
                    $context,
                [
                    'url' => $url,
                    'kind_hint' => 'image',
                    'source_tag' => 'artifact-section-images',
                    'source_element_id' => '',
                    'source_section_id' => $section_id,
                    'alt_text' => $alt,
                    'caption_text' => $caption,
                    'surrounding_text' => $heading,
                ],
                $dbvc_cc_media_policy_context
            );
        }
    }
    }

    /**
     * @param array<string, array<string, mixed>> $candidate_index
     * @param array<string, int>                  $stats
     * @param array<string, string>               $context
     * @param array<string, mixed>|null           $elements_artifact
     * @param array<string, string>               $element_to_section
     * @param array<string, mixed>                $dbvc_cc_media_policy_context
     * @return void
     */
    private function collect_from_elements_artifact(array &$candidate_index, array &$stats, array $context, $elements_artifact, array $element_to_section, array $dbvc_cc_media_policy_context)
    {
        if (! is_array($elements_artifact)) {
            return;
        }

        $elements = isset($elements_artifact['elements']) && is_array($elements_artifact['elements']) ? $elements_artifact['elements'] : [];
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            $element_id = isset($element['element_id']) ? sanitize_key((string) $element['element_id']) : '';
            $section_id = $element_id !== '' && isset($element_to_section[$element_id]) ? $element_to_section[$element_id] : '';
            $text = isset($element['text']) ? sanitize_text_field((string) $element['text']) : '';
            $tag = isset($element['tag']) ? sanitize_key((string) $element['tag']) : '';

            $media_refs = isset($element['media_refs']) && is_array($element['media_refs']) ? $element['media_refs'] : [];
            foreach ($media_refs as $media_ref) {
                $url = $this->extract_media_url($media_ref);
                $this->add_or_merge_candidate(
                    $candidate_index,
                    $stats,
                    $context,
                    [
                        'url' => $url,
                        'kind_hint' => '',
                        'source_tag' => $tag !== '' ? $tag : 'media_ref',
                        'source_element_id' => $element_id,
                        'source_section_id' => $section_id,
                        'alt_text' => '',
                        'caption_text' => '',
                        'surrounding_text' => $text,
                    ],
                    $dbvc_cc_media_policy_context
                );
            }

            $attributes = isset($element['attributes']) && is_array($element['attributes']) ? $element['attributes'] : [];
            $attribute_keys = ['src', 'data-src', 'poster', 'href'];
            foreach ($attribute_keys as $attribute_key) {
                if (! isset($attributes[$attribute_key])) {
                    continue;
                }

                $url = $this->extract_media_url($attributes[$attribute_key]);
                $this->add_or_merge_candidate(
                    $candidate_index,
                    $stats,
                    $context,
                    [
                        'url' => $url,
                        'kind_hint' => '',
                        'source_tag' => $tag !== '' ? $tag : 'attribute',
                        'source_element_id' => $element_id,
                        'source_section_id' => $section_id,
                        'alt_text' => isset($attributes['alt']) ? sanitize_text_field((string) $attributes['alt']) : '',
                        'caption_text' => '',
                        'surrounding_text' => $text,
                    ],
                    $dbvc_cc_media_policy_context
                );
            }

            if (! empty($element['link_target'])) {
                $url = $this->extract_media_url($element['link_target']);
                if ($this->looks_like_media_reference($url)) {
                    $this->add_or_merge_candidate(
                        $candidate_index,
                        $stats,
                        $context,
                        [
                            'url' => $url,
                            'kind_hint' => '',
                            'source_tag' => $tag !== '' ? $tag : 'link_target',
                            'source_element_id' => $element_id,
                            'source_section_id' => $section_id,
                            'alt_text' => '',
                            'caption_text' => '',
                            'surrounding_text' => $text,
                        ],
                        $dbvc_cc_media_policy_context
                    );
                }
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $candidate_index
     * @param array<string, int>                  $stats
     * @param array<string, string>               $context
     * @param array<string, mixed>                $candidate_input
     * @param array<string, mixed>                $dbvc_cc_media_policy_context
     * @return void
     */
    private function add_or_merge_candidate(array &$candidate_index, array &$stats, array $context, array $candidate_input, array $dbvc_cc_media_policy_context)
    {
        $raw_url = isset($candidate_input['url']) ? (string) $candidate_input['url'] : '';
        $normalized_url = $this->normalize_media_url($raw_url, $context['source_url']);
        if ($normalized_url === '') {
            return;
        }

        $blocked_reason = $this->dbvc_cc_get_blocked_media_host_reason(
            $normalized_url,
            isset($context['source_url']) ? (string) $context['source_url'] : '',
            $dbvc_cc_media_policy_context
        );
        if ($blocked_reason !== '') {
            $stats['blocked_url_count']++;
            if (count($stats['blocked_url_examples']) < 15) {
                $stats['blocked_url_examples'][] = [
                    'url' => $normalized_url,
                    'reason' => $blocked_reason,
                ];
            }
            return;
        }

        $stats['source_hits']++;

        $kind_hint = isset($candidate_input['kind_hint']) ? sanitize_key((string) $candidate_input['kind_hint']) : '';
        $source_tag = isset($candidate_input['source_tag']) ? sanitize_key((string) $candidate_input['source_tag']) : '';
        $media_kind = $this->determine_media_kind($normalized_url, $kind_hint, $source_tag);
        $mime_guess = $this->determine_mime_guess($normalized_url, $media_kind);
        $is_new_candidate = ! isset($candidate_index[$normalized_url]);
        $dbvc_cc_mime_allowed = $this->dbvc_cc_is_mime_allowed($mime_guess, $dbvc_cc_media_policy_context['dbvc_cc_media_mime_allowlist']);
        $ingest_policy = $dbvc_cc_media_policy_context['dbvc_cc_media_download_policy'];
        if (! $dbvc_cc_mime_allowed) {
            $ingest_policy = DBVC_CC_Contracts::MEDIA_DOWNLOAD_POLICY_SKIP;
            if ($is_new_candidate) {
                $stats['mime_blocked_count']++;
            }
        }
        $preview_status = $this->dbvc_cc_get_preview_status($media_kind, $dbvc_cc_mime_allowed, $dbvc_cc_media_policy_context);
        $preview_ref = $preview_status === 'remote_allowed' ? $normalized_url : '';
        if ($preview_status !== 'remote_allowed' && $is_new_candidate) {
            $stats['suppressed_preview_count']++;
        }

        $media_id = 'dbvc_cc_med_' . substr(hash('sha256', $context['domain'] . '|' . $context['path'] . '|' . $normalized_url), 0, 16);
        $surrounding = isset($candidate_input['surrounding_text']) ? sanitize_text_field((string) $candidate_input['surrounding_text']) : '';
        $alt_text = isset($candidate_input['alt_text']) ? sanitize_text_field((string) $candidate_input['alt_text']) : '';
        $caption_text = isset($candidate_input['caption_text']) ? sanitize_text_field((string) $candidate_input['caption_text']) : '';
        $source_element_id = isset($candidate_input['source_element_id']) ? sanitize_key((string) $candidate_input['source_element_id']) : '';
        $source_section_id = isset($candidate_input['source_section_id']) ? sanitize_key((string) $candidate_input['source_section_id']) : '';

        if (! isset($candidate_index[$normalized_url])) {
            $candidate_index[$normalized_url] = [
                'media_id' => $media_id,
                'media_kind' => $media_kind,
                'source_url' => $normalized_url,
                'normalized_url' => $normalized_url,
                'source_element_id' => $source_element_id,
                'source_section_id' => $source_section_id,
                'mime_guess' => $mime_guess,
                'dimensions' => [
                    'width' => null,
                    'height' => null,
                ],
                'alt_text' => $alt_text,
                'caption_text' => $caption_text,
                'surrounding_text_snippet' => $this->truncate_text($surrounding, 240),
                'role_candidates' => [],
                'quality_signals' => [
                    'extension' => $this->get_url_extension($normalized_url),
                    'is_https' => strpos($normalized_url, 'https://') === 0,
                    'has_query' => wp_parse_url($normalized_url, PHP_URL_QUERY) !== null,
                    'evidence_count' => 1,
                    'duplicate_score' => 0,
                ],
                'ingest_policy' => $ingest_policy,
                'local_asset_candidate' => '',
                'preview_ref' => $preview_ref,
                'preview_status' => $preview_status,
                'policy_trace' => [
                    'dbvc_cc_domain_policy' => $this->dbvc_cc_get_domain_policy_mode(
                        $normalized_url,
                        isset($context['source_url']) ? (string) $context['source_url'] : '',
                        $dbvc_cc_media_policy_context
                    ),
                    'dbvc_cc_mime_allowed' => $dbvc_cc_mime_allowed,
                    'dbvc_cc_blocked_reason' => '',
                    'dbvc_cc_media_download_policy' => $dbvc_cc_media_policy_context['dbvc_cc_media_download_policy'],
                    'dbvc_cc_media_discovery_mode' => $dbvc_cc_media_policy_context['dbvc_cc_media_discovery_mode'],
                ],
                'provenance' => [
                    'source_url' => isset($context['source_url']) ? (string) $context['source_url'] : '',
                    'source_tag' => $source_tag,
                    'license_hint' => '',
                ],
                'ai_enrichment' => new stdClass(),
                'evidence_sources' => [
                    [
                        'source_tag' => $source_tag,
                        'source_element_id' => $source_element_id,
                        'source_section_id' => $source_section_id,
                    ],
                ],
            ];
            return;
        }

        $existing = $candidate_index[$normalized_url];
        if (! is_array($existing)) {
            return;
        }

        $evidence_count = isset($existing['quality_signals']['evidence_count']) ? (int) $existing['quality_signals']['evidence_count'] : 1;
        $evidence_count++;
        $existing['quality_signals']['evidence_count'] = $evidence_count;
        $existing['quality_signals']['duplicate_score'] = max(0, $evidence_count - 1);

        if ((string) ($existing['source_element_id'] ?? '') === '' && $source_element_id !== '') {
            $existing['source_element_id'] = $source_element_id;
        }
        if ((string) ($existing['source_section_id'] ?? '') === '' && $source_section_id !== '') {
            $existing['source_section_id'] = $source_section_id;
        }
        if ((string) ($existing['alt_text'] ?? '') === '' && $alt_text !== '') {
            $existing['alt_text'] = $alt_text;
        }
        if ((string) ($existing['caption_text'] ?? '') === '' && $caption_text !== '') {
            $existing['caption_text'] = $caption_text;
        }
        if ((string) ($existing['surrounding_text_snippet'] ?? '') === '' && $surrounding !== '') {
            $existing['surrounding_text_snippet'] = $this->truncate_text($surrounding, 240);
        }

        $existing_evidence = isset($existing['evidence_sources']) && is_array($existing['evidence_sources']) ? $existing['evidence_sources'] : [];
        $existing_evidence[] = [
            'source_tag' => $source_tag,
            'source_element_id' => $source_element_id,
            'source_section_id' => $source_section_id,
        ];
        $existing['evidence_sources'] = $existing_evidence;

        $candidate_index[$normalized_url] = $existing;
    }

    /**
     * @param array<string, mixed>|null $sections_artifact
     * @return array<string, string>
     */
    private function build_element_section_map($sections_artifact)
    {
        $map = [];
        if (! is_array($sections_artifact)) {
            return $map;
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

            $element_ids = isset($section['element_ids']) && is_array($section['element_ids']) ? $section['element_ids'] : [];
            foreach ($element_ids as $element_id) {
                $safe_element_id = sanitize_key((string) $element_id);
                if ($safe_element_id === '') {
                    continue;
                }
                $map[$safe_element_id] = $section_id;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed>|null $sections_artifact
     * @return array<string, int>
     */
    private function build_section_order_map($sections_artifact)
    {
        $order_map = [];
        if (! is_array($sections_artifact)) {
            return $order_map;
        }

        $sections = isset($sections_artifact['sections']) && is_array($sections_artifact['sections']) ? $sections_artifact['sections'] : [];
        $position = 0;
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $position++;

            $section_id = isset($section['section_id']) ? sanitize_key((string) $section['section_id']) : '';
            if ($section_id === '') {
                continue;
            }

            $order = isset($section['order']) ? absint($section['order']) : $position;
            $order_map[$section_id] = $order > 0 ? $order : $position;
        }

        return $order_map;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, int>   $section_order
     * @param array<string, int>   $section_image_counts
     * @return array<int, string>
     */
    private function determine_role_candidates(array $candidate, array $section_order, array $section_image_counts)
    {
        $roles = [];
        $kind = isset($candidate['media_kind']) ? sanitize_key((string) $candidate['media_kind']) : 'file';
        $url = strtolower(isset($candidate['normalized_url']) ? (string) $candidate['normalized_url'] : '');
        $section_id = isset($candidate['source_section_id']) ? sanitize_key((string) $candidate['source_section_id']) : '';
        $section_position = isset($section_order[$section_id]) ? absint($section_order[$section_id]) : 0;
        $section_image_count = isset($section_image_counts[$section_id]) ? absint($section_image_counts[$section_id]) : 0;

        if ($kind === 'image') {
            if (strpos($url, 'logo') !== false || strpos($url, 'icon') !== false) {
                $roles[] = 'logo';
                $roles[] = 'icon';
            }

            if ($section_position === 1) {
                $roles[] = 'featured_image';
                $roles[] = 'hero_background';
            }

            if ($section_image_count > 1) {
                $roles[] = 'gallery_item';
            }

            $roles[] = 'inline_illustration';
        } elseif (in_array($kind, ['video', 'embed'], true)) {
            $roles[] = 'video_embed';
        } else {
            $roles[] = 'download_asset';
        }

        return array_values(array_unique($roles));
    }

    /**
     * @param string $normalized_url
     * @param string $kind_hint
     * @param string $source_tag
     * @return string
     */
    private function determine_media_kind($normalized_url, $kind_hint, $source_tag)
    {
        $kind_hint = sanitize_key((string) $kind_hint);
        if (in_array($kind_hint, ['image', 'video', 'audio', 'file', 'embed'], true)) {
            return $kind_hint;
        }

        $url = strtolower((string) $normalized_url);
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        $extension = $this->get_url_extension($url);

        if (preg_match('/youtube\.com|youtu\.be|vimeo\.com|wistia\.com/', $host)) {
            return 'embed';
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'], true)) {
            return 'image';
        }
        if (in_array($extension, ['mp4', 'webm', 'mov', 'm4v'], true)) {
            return 'video';
        }
        if (in_array($extension, ['mp3', 'wav', 'ogg', 'm4a'], true)) {
            return 'audio';
        }
        if (in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip'], true)) {
            return 'file';
        }

        if (in_array($source_tag, ['img', 'picture', 'source'], true)) {
            return 'image';
        }
        if (in_array($source_tag, ['video'], true)) {
            return 'video';
        }
        if (in_array($source_tag, ['iframe', 'oembed'], true)) {
            return 'embed';
        }

        return 'file';
    }

    /**
     * @param string $normalized_url
     * @param string $media_kind
     * @return string
     */
    private function determine_mime_guess($normalized_url, $media_kind)
    {
        $type_info = wp_check_filetype((string) $normalized_url);
        if (is_array($type_info) && ! empty($type_info['type'])) {
            return (string) $type_info['type'];
        }

        if ($media_kind === 'image') {
            return 'image/*';
        }
        if ($media_kind === 'video') {
            return 'video/*';
        }
        if ($media_kind === 'audio') {
            return 'audio/*';
        }
        if ($media_kind === 'embed') {
            return 'text/html';
        }

        return 'application/octet-stream';
    }

    /**
     * @param string $value
     * @return string
     */
    private function get_url_extension($value)
    {
        $path = (string) wp_parse_url((string) $value, PHP_URL_PATH);
        if ($path === '') {
            return '';
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if (! is_string($extension)) {
            return '';
        }

        return strtolower($extension);
    }

    /**
     * @param string $url
     * @return bool
     */
    private function looks_like_media_reference($url)
    {
        if ($url === '') {
            return false;
        }

        $extension = $this->get_url_extension($url);
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'mp4', 'webm', 'mov', 'm4v', 'mp3', 'wav', 'ogg', 'm4a', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip'], true)) {
            return true;
        }

        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        return (bool) preg_match('/youtube\.com|youtu\.be|vimeo\.com|wistia\.com/', $host);
    }

    /**
     * @param string               $url
     * @param string               $source_url
     * @param array<string, mixed> $dbvc_cc_media_policy_context
     * @return string
     */
    private function dbvc_cc_get_blocked_media_host_reason($url, $source_url, array $dbvc_cc_media_policy_context)
    {
        $host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
        if ($host === '') {
            return 'missing_host';
        }

        $source_host = strtolower((string) wp_parse_url((string) $source_url, PHP_URL_HOST));
        $allowlist = isset($dbvc_cc_media_policy_context['dbvc_cc_media_source_domain_allowlist']) && is_array($dbvc_cc_media_policy_context['dbvc_cc_media_source_domain_allowlist'])
            ? $dbvc_cc_media_policy_context['dbvc_cc_media_source_domain_allowlist']
            : [];
        $denylist = isset($dbvc_cc_media_policy_context['dbvc_cc_media_source_domain_denylist']) && is_array($dbvc_cc_media_policy_context['dbvc_cc_media_source_domain_denylist'])
            ? $dbvc_cc_media_policy_context['dbvc_cc_media_source_domain_denylist']
            : [];
        $block_private_hosts = ! empty($dbvc_cc_media_policy_context['dbvc_cc_media_block_private_hosts']);

        if (in_array($host, $denylist, true)) {
            return 'domain_denylist';
        }

        if ($source_host !== '' && $host === $source_host) {
            return '';
        }

        if (in_array($host, $allowlist, true)) {
            return '';
        }

        if (! empty($allowlist) && ! in_array($host, $allowlist, true)) {
            return 'domain_not_allowlisted';
        }

        if (! $block_private_hosts) {
            return '';
        }

        if ($host === 'localhost' || substr($host, -6) === '.local') {
            return 'private_host';
        }

        if (filter_var($host, FILTER_VALIDATE_IP) && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return 'private_ip';
        }

        return '';
    }

    /**
     * @param string               $normalized_url
     * @param string               $source_url
     * @param array<string, mixed> $dbvc_cc_media_policy_context
     * @return string
     */
    private function dbvc_cc_get_domain_policy_mode($normalized_url, $source_url, array $dbvc_cc_media_policy_context)
    {
        $source_host = strtolower((string) wp_parse_url((string) $source_url, PHP_URL_HOST));
        $host = strtolower((string) wp_parse_url((string) $normalized_url, PHP_URL_HOST));

        if ($source_host !== '' && $source_host === $host) {
            return 'same_origin';
        }

        $allowlist = isset($dbvc_cc_media_policy_context['dbvc_cc_media_source_domain_allowlist']) && is_array($dbvc_cc_media_policy_context['dbvc_cc_media_source_domain_allowlist'])
            ? $dbvc_cc_media_policy_context['dbvc_cc_media_source_domain_allowlist']
            : [];
        if (! empty($allowlist) && in_array($host, $allowlist, true)) {
            return 'allowlisted';
        }
        if (! empty($allowlist)) {
            return 'restricted';
        }

        return 'open';
    }

    /**
     * @param string               $mime_guess
     * @param array<int, string>   $dbvc_cc_media_mime_allowlist
     * @return bool
     */
    private function dbvc_cc_is_mime_allowed($mime_guess, array $dbvc_cc_media_mime_allowlist)
    {
        $mime = strtolower(trim((string) $mime_guess));
        if ($mime === '') {
            return true;
        }

        if (empty($dbvc_cc_media_mime_allowlist)) {
            return true;
        }

        foreach ($dbvc_cc_media_mime_allowlist as $allowed_mime) {
            $allowed = strtolower(trim((string) $allowed_mime));
            if ($allowed === '') {
                continue;
            }

            if ($allowed === $mime) {
                return true;
            }

            if (substr($allowed, -2) === '/*') {
                $prefix = substr($allowed, 0, -1);
                if ($prefix !== '' && strpos($mime, $prefix) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param string               $media_kind
     * @param bool                 $dbvc_cc_mime_allowed
     * @param array<string, mixed> $dbvc_cc_media_policy_context
     * @return string
     */
    private function dbvc_cc_get_preview_status($media_kind, $dbvc_cc_mime_allowed, array $dbvc_cc_media_policy_context)
    {
        if ($media_kind !== 'image') {
            return 'not_image';
        }

        if (! $dbvc_cc_mime_allowed) {
            return 'blocked_mime';
        }

        if (empty($dbvc_cc_media_policy_context['dbvc_cc_media_preview_thumbnail_enabled'])) {
            return 'disabled_by_policy';
        }

        return 'remote_allowed';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function extract_media_url($value)
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }

        if (! is_array($value)) {
            return '';
        }

        $candidate_keys = ['url', 'src', 'href', 'source_url', 'media_url'];
        foreach ($candidate_keys as $candidate_key) {
            if (! isset($value[$candidate_key])) {
                continue;
            }
            $candidate = $value[$candidate_key];
            if (is_string($candidate) || is_numeric($candidate)) {
                $candidate = trim((string) $candidate);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return '';
    }

    /**
     * @param string $url
     * @param string $source_url
     * @return string
     */
    private function normalize_media_url($url, $source_url)
    {
        $value = trim((string) $url);
        if ($value === '') {
            return '';
        }

        if (! preg_match('#^https?://#i', $value)) {
            $value = dbvc_cc_convert_to_absolute_url($value, $source_url);
        }

        $value = esc_url_raw($value);
        if ($value === '') {
            return '';
        }

        $parts = wp_parse_url($value);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        $host = strtolower((string) $parts['host']);
        $path = isset($parts['path']) ? (string) $parts['path'] : '/';
        if ($path === '') {
            $path = '/';
        }

        $normalized = $scheme . '://' . $host . $path;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $normalized .= '?' . (string) $parts['query'];
        }

        return $normalized;
    }

    /**
     * @param string $domain
     * @return string
     */
    private function resolve_catalog_fingerprint($domain)
    {
        if (class_exists('DBVC_CC_Target_Field_Catalog_Service')) {
            $catalog = DBVC_CC_Target_Field_Catalog_Service::get_instance()->get_catalog($domain, false);
            if (! is_wp_error($catalog) && is_array($catalog) && isset($catalog['catalog_fingerprint'])) {
                return (string) $catalog['catalog_fingerprint'];
            }
        }

        return '';
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
                'dbvc_cc_media_candidates_domain_invalid',
                __('A valid domain key is required.', 'dbvc'),
                ['status' => 400]
            );
        }

        $relative_path = $this->normalize_relative_path($path);
        if ($relative_path === '') {
            return new WP_Error(
                'dbvc_cc_media_candidates_path_invalid',
                __('A valid node path is required.', 'dbvc'),
                ['status' => 400]
            );
        }

        $base_dir = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        if (! is_string($base_dir) || $base_dir === '' || ! is_dir($base_dir)) {
            return new WP_Error(
                'dbvc_cc_media_candidates_storage_missing',
                __('Content migration storage path is not available.', 'dbvc'),
                ['status' => 500]
            );
        }

        $base_real = realpath($base_dir);
        if (! is_string($base_real)) {
            return new WP_Error(
                'dbvc_cc_media_candidates_storage_invalid',
                __('Could not resolve content migration storage path.', 'dbvc'),
                ['status' => 500]
            );
        }

        $domain_dir = trailingslashit($base_real) . $domain_key;
        if (! is_dir($domain_dir)) {
            return new WP_Error(
                'dbvc_cc_media_candidates_domain_missing',
                __('No crawl storage was found for the requested domain.', 'dbvc'),
                ['status' => 404]
            );
        }

        $page_dir = trailingslashit($domain_dir) . $relative_path;
        if (! is_dir($page_dir)) {
            return new WP_Error(
                'dbvc_cc_media_candidates_path_missing',
                __('No crawl storage was found for the requested page path.', 'dbvc'),
                ['status' => 404]
            );
        }

        if (! dbvc_cc_path_is_within($page_dir, $base_real)) {
            return new WP_Error(
                'dbvc_cc_media_candidates_path_escape',
                __('Requested path escapes storage root.', 'dbvc'),
                ['status' => 400]
            );
        }

        $slug = basename($relative_path);
        $artifact_file = trailingslashit($page_dir) . $slug . '.json';
        $elements_file = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_ELEMENTS_V2_SUFFIX;
        $sections_file = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_SECTIONS_V2_SUFFIX;
        $candidates_file = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_MEDIA_CANDIDATES_V1_SUFFIX;
        if (! dbvc_cc_path_is_within($candidates_file, $base_real)) {
            return new WP_Error(
                'dbvc_cc_media_candidates_file_invalid',
                __('Media candidates file path is invalid.', 'dbvc'),
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
            'artifact_file' => $artifact_file,
            'elements_file' => $elements_file,
            'sections_file' => $sections_file,
            'candidates_file' => $candidates_file,
            'source_url' => $source_url,
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

    /**
     * @param string $value
     * @param int    $max_chars
     * @return string
     */
    private function truncate_text($value, $max_chars)
    {
        $text = trim((string) $value);
        $max_chars = max(20, absint($max_chars));
        if ($text === '' || strlen($text) <= $max_chars) {
            return $text;
        }

        return substr($text, 0, $max_chars - 3) . '...';
    }

    /**
     * @param string $source_url
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_media_policy_context($source_url)
    {
        $download_policy = isset($this->options['dbvc_cc_media_download_policy']) ? sanitize_key((string) $this->options['dbvc_cc_media_download_policy']) : DBVC_CC_Contracts::MEDIA_DOWNLOAD_POLICY_REMOTE_ONLY;
        if (! in_array($download_policy, [DBVC_CC_Contracts::MEDIA_DOWNLOAD_POLICY_REMOTE_ONLY, DBVC_CC_Contracts::MEDIA_DOWNLOAD_POLICY_DOWNLOAD_SELECTED, DBVC_CC_Contracts::MEDIA_DOWNLOAD_POLICY_SKIP], true)) {
            $download_policy = DBVC_CC_Contracts::MEDIA_DOWNLOAD_POLICY_REMOTE_ONLY;
        }

        $discovery_mode = isset($this->options['dbvc_cc_media_discovery_mode']) ? sanitize_key((string) $this->options['dbvc_cc_media_discovery_mode']) : DBVC_CC_Contracts::MEDIA_DISCOVERY_MODE_METADATA_FIRST;
        if (! in_array($discovery_mode, [DBVC_CC_Contracts::MEDIA_DISCOVERY_MODE_METADATA_FIRST, DBVC_CC_Contracts::MEDIA_DISCOVERY_MODE_SELECTIVE_DOWNLOAD], true)) {
            $discovery_mode = DBVC_CC_Contracts::MEDIA_DISCOVERY_MODE_METADATA_FIRST;
        }

        return [
            'dbvc_cc_source_host' => strtolower((string) wp_parse_url((string) $source_url, PHP_URL_HOST)),
            'dbvc_cc_media_download_policy' => $download_policy,
            'dbvc_cc_media_discovery_mode' => $discovery_mode,
            'dbvc_cc_media_preview_thumbnail_enabled' => ! empty($this->options['dbvc_cc_media_preview_thumbnail_enabled']),
            'dbvc_cc_media_block_private_hosts' => ! empty($this->options['dbvc_cc_media_block_private_hosts']),
            'dbvc_cc_media_source_domain_allowlist' => $this->parse_domain_list(isset($this->options['dbvc_cc_media_source_domain_allowlist']) ? (string) $this->options['dbvc_cc_media_source_domain_allowlist'] : ''),
            'dbvc_cc_media_source_domain_denylist' => $this->parse_domain_list(isset($this->options['dbvc_cc_media_source_domain_denylist']) ? (string) $this->options['dbvc_cc_media_source_domain_denylist'] : ''),
            'dbvc_cc_media_mime_allowlist' => $this->dbvc_cc_parse_mime_allowlist(isset($this->options['dbvc_cc_media_mime_allowlist']) ? (string) $this->options['dbvc_cc_media_mime_allowlist'] : ''),
            'dbvc_cc_media_max_bytes_per_asset' => max(0, absint(isset($this->options['dbvc_cc_media_max_bytes_per_asset']) ? $this->options['dbvc_cc_media_max_bytes_per_asset'] : 0)),
        ];
    }

    /**
     * @param string $value
     * @return array<int, string>
     */
    private function dbvc_cc_parse_mime_allowlist($value)
    {
        $tokens = preg_split('/[\s,]+/', strtolower((string) $value));
        if (! is_array($tokens)) {
            return [];
        }

        $allowlist = [];
        foreach ($tokens as $token) {
            $mime = trim((string) $token);
            if ($mime === '') {
                continue;
            }
            if (! preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+*-]+$/', $mime)) {
                continue;
            }
            $allowlist[$mime] = true;
        }

        return array_keys($allowlist);
    }

    /**
     * @param string $value
     * @return array<int, string>
     */
    private function parse_domain_list($value)
    {
        $items = preg_split('/[\s,]+/', strtolower((string) $value));
        if (! is_array($items)) {
            return [];
        }

        $domains = [];
        foreach ($items as $item) {
            $domain = trim((string) $item);
            if ($domain === '') {
                continue;
            }
            $domain = preg_replace('/[^a-z0-9.\-]/', '', $domain);
            if (! is_string($domain) || $domain === '') {
                continue;
            }
            $domains[$domain] = true;
        }

        return array_keys($domains);
    }
}
