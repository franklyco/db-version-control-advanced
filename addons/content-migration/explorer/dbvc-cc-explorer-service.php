<?php
/**
 * Builds explorer-ready tree and node payloads from collected crawl artifacts.
 *
 * @package ContentCollector
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class DBVC_CC_Explorer_Service {

    private static $instance = null;
    private $options;

    /**
     * Singleton bootstrap.
     *
     * @return DBVC_CC_Explorer_Service
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->options = DBVC_CC_Settings_Service::get_options();
    }

    /**
     * Returns available crawled domains.
     *
     * @return array
     */
    public function get_domains() {
        $domains = [];
        $domain_keys = DBVC_CC_Artifact_Manager::list_domain_keys();
        foreach ($domain_keys as $entry) {
            $dbvc_cc_domain_key = $this->sanitize_domain((string) $entry);
            if ('' === $dbvc_cc_domain_key) {
                continue;
            }

            $dbvc_cc_ai_health = DBVC_CC_AI_Service::get_instance()->dbvc_cc_get_domain_ai_health($dbvc_cc_domain_key);
            $domains[] = [
                'key'   => $dbvc_cc_domain_key,
                'label' => $dbvc_cc_domain_key,
                'dbvc_cc_ai_health' => $dbvc_cc_ai_health,
            ];
        }

        usort(
            $domains,
            function ($left, $right) {
                return strnatcasecmp($left['key'], $right['key']);
            }
        );

        return $domains;
    }

    /**
     * Returns Cytoscape tree payload with depth-limited recursive scan.
     *
     * @param array $args Route args.
     * @return array|WP_Error
     */
    public function get_tree($args) {
        $domain = isset($args['domain']) ? $this->sanitize_domain($args['domain']) : '';
        if ('' === $domain) {
            return new WP_Error('dbvc_cc_invalid_domain', __('A valid domain is required.', 'dbvc'), ['status' => 400]);
        }

        $domain_dir = $this->resolve_domain_dir($domain);
        if (is_wp_error($domain_dir)) {
            return $domain_dir;
        }

        $depth = $this->normalize_depth(isset($args['depth']) ? $args['depth'] : 0);
        $max_nodes = $this->normalize_max_nodes(isset($args['max_nodes']) ? $args['max_nodes'] : 0);
        $include_files = !empty($args['include_files']);
        $cache_ttl = $this->normalize_cache_ttl();

        $domain_signature = $this->get_domain_signature($domain_dir);
        $cache_key = 'dbvc_cc_x_tree_' . md5($domain . '|' . $depth . '|' . $max_nodes . '|' . (int) $include_files . '|' . $domain_signature);

        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            $cached['scan_mode'] = 'cached';
            $cached['cache'] = [
                'hit'           => true,
                'key'           => $cache_key,
                'ttl_remaining' => null,
            ];
            return $cached;
        }

        $root_id = $this->build_node_id($domain, '', 'domain');
        $root_children_count = count($this->list_directories($domain_dir));

        $nodes = [
            [
                'data' => [
                    'id'                => $root_id,
                    'label'             => $domain,
                    'type'              => 'domain',
                    'path'              => '',
                    'depth'             => 0,
                    'children_count'    => $root_children_count,
                    'has_more_children' => $root_children_count > 0 && $depth <= 0,
                    'json_exists'       => false,
                    'image_count'       => 0,
                    'cpt'               => '',
                    'status'            => 'ready',
                ],
            ],
        ];
        $edges = [];
        $warnings = [];
        $node_count = 1;

        if ($depth > 0 && $max_nodes > 1) {
            $this->append_children_recursive(
                $domain,
                $domain_dir,
                '',
                0,
                $root_id,
                $depth,
                $max_nodes,
                $include_files,
                $nodes,
                $edges,
                $node_count,
                $warnings
            );
        }

        $totals = $this->compute_totals($domain_dir);

        $response = [
            'domain'       => $domain,
            'generated_at' => current_time('c'),
            'scan_mode'    => 'fresh',
            'totals'       => $totals,
            'cytoscape'    => [
                'nodes' => $nodes,
                'edges' => $edges,
            ],
            'cache'        => [
                'hit'           => false,
                'key'           => $cache_key,
                'ttl_remaining' => $cache_ttl,
            ],
            'warnings'     => $warnings,
        ];

        set_transient($cache_key, $response, $cache_ttl);

        return $response;
    }

    /**
     * Returns direct child nodes for lazy expansion.
     *
     * @param array $args Route args.
     * @return array|WP_Error
     */
    public function get_children($args) {
        $domain = isset($args['domain']) ? $this->sanitize_domain($args['domain']) : '';
        if ('' === $domain) {
            return new WP_Error('dbvc_cc_invalid_domain', __('A valid domain is required.', 'dbvc'), ['status' => 400]);
        }

        $domain_dir = $this->resolve_domain_dir($domain);
        if (is_wp_error($domain_dir)) {
            return $domain_dir;
        }

        $relative_path = isset($args['path']) ? $this->normalize_relative_path($args['path']) : '';
        $include_files = !empty($args['include_files']);

        $target_dir = $this->resolve_relative_directory($domain_dir, $relative_path);
        if (is_wp_error($target_dir)) {
            return $target_dir;
        }

        $parent_type = '' === $relative_path ? 'domain' : $this->determine_directory_type($target_dir);
        $parent_id = $this->build_node_id($domain, $relative_path, $parent_type);
        $child_depth = '' === $relative_path ? 1 : $this->path_depth($relative_path) + 1;

        $nodes = [];
        $edges = [];

        foreach ($this->list_directories($target_dir) as $child_name) {
            $child_relative_path = '' === $relative_path ? $child_name : $relative_path . '/' . $child_name;
            $child_abs = trailingslashit($target_dir) . $child_name;
            $node_data = $this->build_directory_node_data($domain, $child_abs, $child_relative_path, $child_depth, $include_files);

            $nodes[] = ['data' => $node_data];
            $edges[] = [
                'data' => [
                    'id'           => 'edge:' . $parent_id . '->' . $node_data['id'],
                    'source'       => $parent_id,
                    'target'       => $node_data['id'],
                    'relationship' => 'contains',
                ],
            ];
        }

        if ($include_files) {
            foreach ($this->list_content_files($target_dir) as $file_name) {
                $file_relative_path = '' === $relative_path ? $file_name : $relative_path . '/' . $file_name;
                $file_node_id = $this->build_node_id($domain, $file_relative_path, 'file');
                $nodes[] = [
                    'data' => [
                        'id'                => $file_node_id,
                        'label'             => $file_name,
                        'type'              => 'file',
                        'path'              => $file_relative_path,
                        'depth'             => $child_depth,
                        'children_count'    => 0,
                        'has_more_children' => false,
                        'json_exists'       => 'json' === pathinfo($file_name, PATHINFO_EXTENSION),
                        'image_count'       => $this->is_image_file($file_name) ? 1 : 0,
                        'status'            => 'ready',
                    ],
                ];
                $edges[] = [
                    'data' => [
                        'id'           => 'edge:' . $parent_id . '->' . $file_node_id,
                        'source'       => $parent_id,
                        'target'       => $file_node_id,
                        'relationship' => 'contains',
                    ],
                ];
            }
        }

        return [
            'domain'       => $domain,
            'path'         => $relative_path,
            'generated_at' => current_time('c'),
            'children'     => [
                'nodes' => $nodes,
                'edges' => $edges,
            ],
        ];
    }

    /**
     * Returns details for a selected node.
     *
     * @param array $args Route args.
     * @return array|WP_Error
     */
    public function get_node($args) {
        $domain = isset($args['domain']) ? $this->sanitize_domain($args['domain']) : '';
        if ('' === $domain) {
            return new WP_Error('dbvc_cc_invalid_domain', __('A valid domain is required.', 'dbvc'), ['status' => 400]);
        }

        $domain_dir = $this->resolve_domain_dir($domain);
        if (is_wp_error($domain_dir)) {
            return $domain_dir;
        }

        $relative_path = isset($args['path']) ? $this->normalize_relative_path($args['path']) : '';
        if ('' === $relative_path) {
            return [
                'domain' => $domain,
                'path'   => '',
                'node'   => [
                    'id'                => $this->build_node_id($domain, '', 'domain'),
                    'label'             => $domain,
                    'type'              => 'domain',
                    'depth'             => 0,
                    'json_file'         => null,
                    'json_exists'       => false,
                    'image_count'       => 0,
                    'cpt'               => '',
                    'artifact'          => null,
                'status'            => [
                    'crawl'        => 'ready',
                    'analysis'     => 'not_started',
                    'sanitization' => 'not_started',
                    'export'       => 'not_started',
                ],
                'actions'           => [
                    'can_rerun_ai'         => false,
                    'can_rerun_ai_branch'  => false,
                    'can_expand_branch'    => true,
                    'can_open_source_url'  => false,
                    'can_open_canonical_url' => false,
                ],
            ],
        ];
    }

        $target_dir = $this->resolve_relative_directory($domain_dir, $relative_path);
        if (is_wp_error($target_dir)) {
            return $target_dir;
        }

        $depth = $this->path_depth($relative_path);
        $node_data = $this->build_directory_node_data($domain, $target_dir, $relative_path, $depth, false);

        $slug = basename($relative_path);
        $artifact_file = trailingslashit($target_dir) . $slug . '.json';
        $artifact_payload = $this->read_json_file($artifact_file);
        $provenance = isset($artifact_payload['provenance']) && is_array($artifact_payload['provenance']) ? $artifact_payload['provenance'] : null;
        $ai_status = $this->resolve_ai_status($target_dir, $slug);
        $phase36_modes = $this->dbvc_cc_resolve_phase36_mode_indicators($target_dir, $slug);
        $can_rerun_ai = ('page' === $node_data['type'] && !empty($node_data['json_exists']));
        $can_rerun_ai_branch = ('file' !== $node_data['type']);
        $can_expand_branch = !empty($node_data['children_count']);
        $can_open_source_url = !empty($provenance['source_url']);
        $can_open_canonical = !empty($provenance['canonical_source_url']);

        return [
            'domain' => $domain,
            'path'   => $relative_path,
            'node'   => [
                'id'         => $node_data['id'],
                'label'      => $node_data['label'],
                'type'       => $node_data['type'],
                'depth'      => $depth,
                'cpt'        => isset($node_data['cpt']) ? $node_data['cpt'] : '',
                'json_file'  => $slug . '.json',
                'json_exists'=> $node_data['json_exists'],
                'image_count'=> $node_data['image_count'],
                'artifact'   => $provenance,
                'status'     => [
                    'crawl'        => $node_data['json_exists'] ? 'success' : 'missing',
                    'analysis'     => $ai_status['analysis'],
                    'sanitization' => $ai_status['sanitization'],
                    'export'       => 'not_started',
                    'ai_mode'      => $ai_status['mode'],
                    'job_id'       => $ai_status['job_id'],
                    'message'      => $ai_status['message'],
                    'updated_at'   => $ai_status['updated_at'],
                    'capture_mode' => $phase36_modes['capture_mode'],
                    'section_typing_mode' => $phase36_modes['section_typing_mode'],
                    'scrub_profile' => $phase36_modes['scrub_profile'],
                ],
                'actions'    => [
                    'can_rerun_ai'           => $can_rerun_ai,
                    'can_rerun_ai_branch'    => $can_rerun_ai_branch,
                    'can_expand_branch'      => $can_expand_branch,
                    'can_open_source_url'    => $can_open_source_url,
                    'can_open_canonical_url' => $can_open_canonical,
                ],
            ],
            'phase36' => [
                'available' => $phase36_modes['available'],
            ],
        ];
    }

    /**
     * Returns content preview payload for explorer inspector.
     *
     * @param array $args Route args.
     * @return array|WP_Error
     */
    public function get_content_preview($args) {
        $domain = isset($args['domain']) ? $this->sanitize_domain($args['domain']) : '';
        if ('' === $domain) {
            return new WP_Error('dbvc_cc_invalid_domain', __('A valid domain is required.', 'dbvc'), ['status' => 400]);
        }

        $domain_dir = $this->resolve_domain_dir($domain);
        if (is_wp_error($domain_dir)) {
            return $domain_dir;
        }

        $relative_path = isset($args['path']) ? $this->normalize_relative_path($args['path']) : '';
        if ('' === $relative_path) {
            return new WP_Error('dbvc_cc_invalid_path', __('A page path is required.', 'dbvc'), ['status' => 400]);
        }

        $mode = isset($args['mode']) ? sanitize_key($args['mode']) : 'raw';
        if (!in_array($mode, ['raw', 'sanitized'], true)) {
            $mode = 'raw';
        }

        $target_dir = $this->resolve_relative_directory($domain_dir, $relative_path);
        if (is_wp_error($target_dir)) {
            return $target_dir;
        }

        $slug = basename($relative_path);
        $raw_json = $this->read_json_file(trailingslashit($target_dir) . $slug . '.json');
        if (!is_array($raw_json)) {
            return new WP_Error('dbvc_cc_missing_artifact', __('No crawl artifact found for this node.', 'dbvc'), ['status' => 404]);
        }

        $sanitized_json = $this->read_json_file(trailingslashit($target_dir) . $slug . '.sanitized.json');
        $analysis_json = $this->read_json_file(trailingslashit($target_dir) . $slug . '.analysis.json');
        $sanitized_html = file_exists(trailingslashit($target_dir) . $slug . '.sanitized.html') ? (string) file_get_contents(trailingslashit($target_dir) . $slug . '.sanitized.html') : '';
        $phase36_files = $this->dbvc_cc_get_phase36_sidecar_files($target_dir, $slug);
        $phase36_artifacts = $this->dbvc_cc_load_phase36_sidecars($phase36_files);

        $text_blocks = isset($raw_json['content']['text_blocks']) && is_array($raw_json['content']['text_blocks']) ? $raw_json['content']['text_blocks'] : [];
        $headings = isset($raw_json['content']['headings']) && is_array($raw_json['content']['headings']) ? $raw_json['content']['headings'] : [];
        $images = isset($raw_json['content']['images']) && is_array($raw_json['content']['images']) ? $raw_json['content']['images'] : [];
        $sections = isset($raw_json['content']['sections']) && is_array($raw_json['content']['sections']) ? $raw_json['content']['sections'] : [];
        $pii_flags = isset($raw_json['compliance']['pii_flags']) && is_array($raw_json['compliance']['pii_flags']) ? $raw_json['compliance']['pii_flags'] : [];

        $sanitized_excerpt = [];
        if ('sanitized' === $mode && is_array($sanitized_json) && isset($sanitized_json['sanitized_html'])) {
            $sanitized_excerpt[] = wp_strip_all_tags((string) $sanitized_json['sanitized_html']);
        } elseif ('sanitized' === $mode && '' !== $sanitized_html) {
            $sanitized_excerpt[] = wp_strip_all_tags($sanitized_html);
        }

        $raw_excerpt = array_slice(array_values($text_blocks), 0, 12);
        $sanitized_for_compare = '';
        if (is_array($sanitized_json) && isset($sanitized_json['sanitized_html'])) {
            $sanitized_for_compare = wp_strip_all_tags((string) $sanitized_json['sanitized_html']);
        } elseif ('' !== $sanitized_html) {
            $sanitized_for_compare = wp_strip_all_tags($sanitized_html);
        }
        $sanitized_for_compare = trim(preg_replace('/\s+/', ' ', $sanitized_for_compare));
        $sanitized_excerpt_lines = [];
        if ('' !== $sanitized_for_compare) {
            $parts = preg_split('/(?<=[.!?])\s+/', $sanitized_for_compare);
            if (is_array($parts)) {
                foreach ($parts as $part) {
                    $part = trim((string) $part);
                    if ('' !== $part) {
                        $sanitized_excerpt_lines[] = $part;
                    }
                }
            }
        }
        $sanitized_excerpt_lines = array_slice($sanitized_excerpt_lines, 0, 12);

        $compare_len = max(count($raw_excerpt), count($sanitized_excerpt_lines));
        $changed_lines = 0;
        for ($index = 0; $index < $compare_len; $index++) {
            $left_line = isset($raw_excerpt[$index]) ? trim((string) $raw_excerpt[$index]) : '';
            $right_line = isset($sanitized_excerpt_lines[$index]) ? trim((string) $sanitized_excerpt_lines[$index]) : '';
            if ($left_line !== $right_line) {
                $changed_lines++;
            }
        }

        $section_groups = [];
        foreach (array_slice($sections, 0, 12) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $section_text_blocks = isset($section['text_blocks']) && is_array($section['text_blocks']) ? array_values($section['text_blocks']) : [];
            $links = isset($section['links']) && is_array($section['links']) ? array_values($section['links']) : [];
            $ctas = isset($section['ctas']) && is_array($section['ctas']) ? array_values($section['ctas']) : [];
            $section_images = isset($section['images']) && is_array($section['images']) ? array_values($section['images']) : [];

            $section_groups[] = [
                'id'         => isset($section['id']) ? (string) $section['id'] : '',
                'order'      => isset($section['order']) ? (int) $section['order'] : 0,
                'parent_id'  => isset($section['parent_id']) ? (string) $section['parent_id'] : null,
                'level'      => isset($section['level']) ? (int) $section['level'] : 0,
                'heading_tag'=> isset($section['heading_tag']) ? (string) $section['heading_tag'] : null,
                'heading'    => isset($section['heading']) ? (string) $section['heading'] : '',
                'is_intro'   => !empty($section['is_intro']),
                'text_blocks'=> $section_text_blocks,
                'links'      => $links,
                'ctas'       => $ctas,
                'images'     => $section_images,
            ];
        }

        $analysis_status = 'not_started';
        if (is_array($analysis_json) && !empty($analysis_json['status'])) {
            if ('fallback' === (string) $analysis_json['status']) {
                $analysis_status = 'fallback_done';
            } else {
                $analysis_status = 'done';
            }
        }

        $categories = [];
        if (is_array($analysis_json) && isset($analysis_json['categories']) && is_array($analysis_json['categories'])) {
            $categories = array_values($analysis_json['categories']);
        }

        $links_count = 0;
        $ctas_count = 0;
        $section_image_count = 0;
        $h1_count = 0;
        $primary_h1 = '';
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            if (isset($section['links']) && is_array($section['links'])) {
                $links_count += count($section['links']);
            }
            if (isset($section['ctas']) && is_array($section['ctas'])) {
                $ctas_count += count($section['ctas']);
            }
            if (isset($section['images']) && is_array($section['images'])) {
                $section_image_count += count($section['images']);
            }

            $heading_tag = isset($section['heading_tag']) ? strtolower((string) $section['heading_tag']) : '';
            if ('h1' !== $heading_tag) {
                continue;
            }

            $h1_count++;
            if ('' === $primary_h1 && !empty($section['heading'])) {
                $primary_h1 = (string) $section['heading'];
            }
        }

        if ('' === $primary_h1 && !empty($headings)) {
            $primary_h1 = (string) $headings[0];
        }

        $word_count = 0;
        foreach ($text_blocks as $line) {
            $normalized_line = trim(wp_strip_all_tags((string) $line));
            if ('' !== $normalized_line) {
                $word_count += str_word_count($normalized_line);
            }
        }

        $requires_legal_review = !empty($raw_json['compliance']['requires_legal_review']);
        $has_structure = !empty($headings) && !empty($text_blocks);
        $has_ai_analysis = in_array($analysis_status, ['done', 'fallback_done'], true);
        $metrics = [
            'headings_count'      => count($headings),
            'h1_count'            => $h1_count,
            'primary_h1'          => $primary_h1,
            'text_blocks_count'   => count($text_blocks),
            'word_count'          => $word_count,
            'section_count'       => count($sections),
            'links_count'         => $links_count,
            'ctas_count'          => $ctas_count,
            'images_count'        => count($images),
            'section_image_count' => $section_image_count,
        ];

        $readiness_checks = [
            [
                'key'    => 'artifact',
                'label'  => __('Crawl artifact captured', 'dbvc'),
                'passed' => true,
            ],
            [
                'key'    => 'structure',
                'label'  => __('Heading hierarchy and text captured', 'dbvc'),
                'passed' => $has_structure,
            ],
            [
                'key'    => 'analysis',
                'label'  => __('AI classification available', 'dbvc'),
                'passed' => $has_ai_analysis,
            ],
            [
                'key'    => 'compliance',
                'label'  => __('No legal review flag', 'dbvc'),
                'passed' => !$requires_legal_review,
            ],
        ];

        $readiness_score = 0;
        foreach ($readiness_checks as $check) {
            if (!empty($check['passed'])) {
                $readiness_score++;
            }
        }
        $readiness_max = count($readiness_checks);
        $readiness_status = 'needs_work';
        if ($readiness_score >= $readiness_max) {
            $readiness_status = 'ready';
        } elseif ($readiness_score >= max(1, $readiness_max - 1)) {
            $readiness_status = 'review';
        }

        $readiness_notes = [];
        if (!$has_structure) {
            $readiness_notes[] = __('Content structure is sparse. Verify heading and text capture quality.', 'dbvc');
        }
        if (!$has_ai_analysis) {
            $readiness_notes[] = __('AI analysis is not complete. Run AI before final export if mapping suggestions are needed.', 'dbvc');
        }
        if ($requires_legal_review) {
            $readiness_notes[] = __('PII/compliance flags require legal review before import.', 'dbvc');
        }

        $phase36_summary = $this->dbvc_cc_build_phase36_summary($phase36_files, $phase36_artifacts);

        return [
            'domain' => $domain,
            'path'   => $relative_path,
            'mode'   => $mode,
            'content'=> [
                'title'        => isset($raw_json['page_name']) ? (string) $raw_json['page_name'] : '',
                'source_url'   => isset($raw_json['source_url']) ? (string) $raw_json['source_url'] : '',
                'canonical_source_url' => isset($raw_json['provenance']['canonical_source_url']) ? (string) $raw_json['provenance']['canonical_source_url'] : '',
                'headings'     => array_values($headings),
                'text_excerpt' => 'sanitized' === $mode ? array_slice($sanitized_excerpt, 0, 1) : array_slice($text_blocks, 0, 8),
                'images'       => array_values($images),
                'section_count'=> count($sections),
                'sections'     => $section_groups,
            ],
            'analysis' => [
                'status'                => $analysis_status,
                'post_type'             => is_array($analysis_json) && isset($analysis_json['post_type']) ? (string) $analysis_json['post_type'] : '',
                'post_type_confidence'  => is_array($analysis_json) && isset($analysis_json['post_type_confidence']) ? (float) $analysis_json['post_type_confidence'] : null,
                'categories'            => $categories,
                'needs_review'          => is_array($analysis_json) && !empty($analysis_json['needs_review']),
                'summary'               => is_array($analysis_json) && isset($analysis_json['summary']) ? (string) $analysis_json['summary'] : '',
                'reasoning'             => is_array($analysis_json) && isset($analysis_json['reasoning']) ? (string) $analysis_json['reasoning'] : '',
            ],
            'metrics' => $metrics,
            'readiness' => [
                'status' => $readiness_status,
                'score'  => $readiness_score,
                'max'    => $readiness_max,
                'checks' => $readiness_checks,
                'notes'  => $readiness_notes,
            ],
            'comparison' => [
                'raw_excerpt'       => $raw_excerpt,
                'sanitized_excerpt' => $sanitized_excerpt_lines,
                'changed_lines'     => $changed_lines,
                'total_lines'       => $compare_len,
            ],
            'pii_flags' => [
                'emails_count'          => isset($pii_flags['emails_count']) ? (int) $pii_flags['emails_count'] : 0,
                'phones_count'          => isset($pii_flags['phones_count']) ? (int) $pii_flags['phones_count'] : 0,
                'forms_count'           => isset($pii_flags['forms_count']) ? (int) $pii_flags['forms_count'] : 0,
                'requires_legal_review' => $requires_legal_review,
            ],
            'phase36' => $phase36_summary,
        ];
    }

    /**
     * Returns raw or trimmed Phase 3.6 sidecar payloads for a selected node.
     *
     * @param array $args Route args.
     * @return array|WP_Error
     */
    public function dbvc_cc_get_content_context_payload($args) {
        $domain = isset($args['domain']) ? $this->sanitize_domain($args['domain']) : '';
        if ('' === $domain) {
            return new WP_Error('dbvc_cc_invalid_domain', __('A valid domain is required.', 'dbvc'), ['status' => 400]);
        }

        $domain_dir = $this->resolve_domain_dir($domain);
        if (is_wp_error($domain_dir)) {
            return $domain_dir;
        }

        $relative_path = isset($args['path']) ? $this->normalize_relative_path($args['path']) : '';
        if ('' === $relative_path) {
            return new WP_Error('dbvc_cc_invalid_path', __('A page path is required.', 'dbvc'), ['status' => 400]);
        }

        $artifact_filter = isset($args['artifact']) ? $this->dbvc_cc_normalize_phase36_artifact_key($args['artifact']) : 'all';
        if ('' === $artifact_filter) {
            return new WP_Error('dbvc_cc_invalid_artifact', __('Invalid artifact type.', 'dbvc'), ['status' => 400]);
        }

        $limit = isset($args['limit']) ? absint($args['limit']) : 40;
        $limit = max(5, min(200, $limit));

        $target_dir = $this->resolve_relative_directory($domain_dir, $relative_path);
        if (is_wp_error($target_dir)) {
            return $target_dir;
        }

        $slug = basename($relative_path);
        $phase36_files = $this->dbvc_cc_get_phase36_sidecar_files($target_dir, $slug);
        $phase36_artifacts = $this->dbvc_cc_load_phase36_sidecars($phase36_files);

        if ('all' !== $artifact_filter && !isset($phase36_artifacts[$artifact_filter])) {
            return new WP_Error('dbvc_cc_missing_phase36_artifact', __('Requested Phase 3.6 artifact is not available for this node.', 'dbvc'), ['status' => 404]);
        }

        $payload = [];
        if ('all' === $artifact_filter) {
            foreach ($phase36_artifacts as $artifact_key => $artifact_data) {
                if (!is_array($artifact_data)) {
                    continue;
                }
                $payload[$artifact_key] = $this->dbvc_cc_trim_phase36_artifact_for_transport($artifact_key, $artifact_data, $limit);
            }
        } else {
            $payload[$artifact_filter] = $this->dbvc_cc_trim_phase36_artifact_for_transport(
                $artifact_filter,
                isset($phase36_artifacts[$artifact_filter]) && is_array($phase36_artifacts[$artifact_filter]) ? $phase36_artifacts[$artifact_filter] : [],
                $limit
            );
        }

        return [
            'domain' => $domain,
            'path' => $relative_path,
            'artifact' => $artifact_filter,
            'limit' => $limit,
            'phase36' => $this->dbvc_cc_build_phase36_summary($phase36_files, $phase36_artifacts),
            'payload' => $payload,
        ];
    }

    /**
     * Returns deterministic scrub-policy preview for selected node.
     *
     * @param array $args Route args.
     * @return array|WP_Error
     */
    public function dbvc_cc_get_scrub_policy_preview_payload($args) {
        $domain = isset($args['domain']) ? $this->sanitize_domain($args['domain']) : '';
        if ('' === $domain) {
            return new WP_Error('dbvc_cc_invalid_domain', __('A valid domain is required.', 'dbvc'), ['status' => 400]);
        }

        $domain_dir = $this->resolve_domain_dir($domain);
        if (is_wp_error($domain_dir)) {
            return $domain_dir;
        }

        $relative_path = isset($args['path']) ? $this->normalize_relative_path($args['path']) : '';
        if ('' === $relative_path) {
            return new WP_Error('dbvc_cc_invalid_path', __('A page path is required.', 'dbvc'), ['status' => 400]);
        }

        $target_dir = $this->resolve_relative_directory($domain_dir, $relative_path);
        if (is_wp_error($target_dir)) {
            return $target_dir;
        }

        $sample_size = isset($args['sample_size']) ? absint($args['sample_size']) : 0;
        if ($sample_size <= 0) {
            $sample_size = isset($this->options['scrub_preview_sample_size']) ? absint($this->options['scrub_preview_sample_size']) : 20;
        }
        $sample_size = max(1, min(200, $sample_size));

        $slug = basename($relative_path);
        $files = $this->dbvc_cc_get_phase36_sidecar_files($target_dir, $slug);
        $elements_payload = $this->read_json_file($files['elements']);
        if (!is_array($elements_payload)) {
            return new WP_Error('dbvc_cc_missing_elements_artifact', __('Elements sidecar is required for scrub policy preview.', 'dbvc'), ['status' => 404]);
        }

        $scrub_report_payload = $this->read_json_file($files['scrub_report']);
        $elements = isset($elements_payload['elements']) && is_array($elements_payload['elements']) ? $elements_payload['elements'] : [];
        if (empty($elements)) {
            return new WP_Error('dbvc_cc_empty_elements_artifact', __('Elements sidecar has no rows to evaluate.', 'dbvc'), ['status' => 404]);
        }

        $sample_rows = array_slice($elements, 0, $sample_size);
        $family_counts = [
            'class' => 0,
            'id' => 0,
            'data' => 0,
            'style' => 0,
            'aria' => 0,
            'event' => 0,
            'other' => 0,
        ];
        $attribute_counts = [];
        $sample_preview = [];
        $elements_with_attributes = 0;

        foreach ($sample_rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $attributes = isset($row['attributes']) && is_array($row['attributes']) ? $row['attributes'] : [];
            if (empty($attributes)) {
                continue;
            }
            $elements_with_attributes++;

            $attribute_names = [];
            foreach ($attributes as $name => $value) {
                unset($value);
                $attribute_name = strtolower(trim((string) $name));
                if ('' === $attribute_name) {
                    continue;
                }

                $attribute_names[] = $attribute_name;
                if (!isset($attribute_counts[$attribute_name])) {
                    $attribute_counts[$attribute_name] = 0;
                }
                $attribute_counts[$attribute_name]++;

                $family = $this->dbvc_cc_get_attribute_family($attribute_name);
                if (!isset($family_counts[$family])) {
                    $family_counts[$family] = 0;
                }
                $family_counts[$family]++;
            }

            if (!empty($attribute_names) && count($sample_preview) < 12) {
                sort($attribute_names);
                $sample_preview[] = [
                    'element_id' => isset($row['element_id']) ? (string) $row['element_id'] : '',
                    'tag' => isset($row['tag']) ? (string) $row['tag'] : '',
                    'attributes' => $attribute_names,
                ];
            }
        }

        $current_actions = $this->dbvc_cc_get_current_scrub_actions();
        $suggested_actions = $this->dbvc_cc_get_suggested_scrub_actions($family_counts);
        $rationale = $this->dbvc_cc_build_scrub_suggestions_rationale($family_counts, $current_actions, $suggested_actions);
        $top_attributes = $this->dbvc_cc_sort_counts_desc($attribute_counts, 20);

        return [
            'domain' => $domain,
            'path' => $relative_path,
            'source_url' => isset($elements_payload['source_url']) ? (string) $elements_payload['source_url'] : '',
            'sample_size' => $sample_size,
            'evaluated_elements' => count($sample_rows),
            'elements_with_attributes' => $elements_with_attributes,
            'elements_with_attributes_ratio' => count($sample_rows) > 0 ? round($elements_with_attributes / count($sample_rows), 4) : 0.0,
            'mode' => 'deterministic-preview',
            'approval_required' => true,
            'ai_suggestion_enabled' => !empty($this->options['scrub_ai_suggestion_enabled']),
            'profile_mode_current' => isset($this->options['scrub_profile_mode']) ? sanitize_key((string) $this->options['scrub_profile_mode']) : DBVC_CC_Contracts::SCRUB_PROFILE_DETERMINISTIC_DEFAULT,
            'current_actions' => $current_actions,
            'suggested_actions' => $suggested_actions,
            'attribute_family_counts' => $family_counts,
            'top_attributes' => $top_attributes,
            'sample_preview' => $sample_preview,
            'rationale' => $rationale,
            'scrub_report' => [
                'profile' => is_array($scrub_report_payload) && isset($scrub_report_payload['profile']) ? sanitize_key((string) $scrub_report_payload['profile']) : '',
                'policy_hash' => is_array($scrub_report_payload) && isset($scrub_report_payload['policy_hash']) ? (string) $scrub_report_payload['policy_hash'] : '',
                'totals' => is_array($scrub_report_payload) && isset($scrub_report_payload['totals']) && is_array($scrub_report_payload['totals']) ? $scrub_report_payload['totals'] : [],
            ],
            'notes' => [
                'Suggestions are preview-only and must be manually approved before applying to Configure defaults.',
                'No runtime writes are performed by this endpoint.',
            ],
        ];
    }

    /**
     * Returns latest scrub suggestion approval status data.
     *
     * @param array $args Route args.
     * @return array|WP_Error
     */
    public function dbvc_cc_get_scrub_policy_approval_status_payload($args) {
        $domain = isset($args['domain']) ? $this->sanitize_domain($args['domain']) : '';
        $relative_path = isset($args['path']) ? $this->normalize_relative_path($args['path']) : '';

        $stored = get_option(DBVC_CC_Contracts::OPTION_SCRUB_POLICY_APPROVAL_STATUS, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return [
            'domain' => $domain,
            'path' => $relative_path,
            'current_profile_mode' => isset($this->options['scrub_profile_mode']) ? sanitize_key((string) $this->options['scrub_profile_mode']) : DBVC_CC_Contracts::SCRUB_PROFILE_DETERMINISTIC_DEFAULT,
            'current_actions' => $this->dbvc_cc_get_current_scrub_actions(),
            'last_approval' => $stored,
            'approval_required' => true,
            'notes' => [
                'Preview suggestions are never auto-applied.',
                'Use scrub-policy-approve to apply approved actions to Configure defaults.',
            ],
        ];
    }

    /**
     * Applies approved scrub suggestions to settings defaults.
     *
     * @param array $args Route args.
     * @return array|WP_Error
     */
    public function dbvc_cc_post_scrub_policy_approve_payload($args) {
        $preview = $this->dbvc_cc_get_scrub_policy_preview_payload($args);
        if (is_wp_error($preview)) {
            return $preview;
        }

        $suggested_actions = isset($preview['suggested_actions']) && is_array($preview['suggested_actions']) ? $preview['suggested_actions'] : [];
        $requested_actions = isset($args['actions']) && is_array($args['actions']) ? $args['actions'] : [];
        $requested_actions = $this->dbvc_cc_validate_scrub_actions($requested_actions);
        $applied_actions = !empty($requested_actions) ? $requested_actions : $suggested_actions;

        if (empty($applied_actions)) {
            return new WP_Error('dbvc_cc_scrub_approve_missing_actions', __('No scrub actions available to approve.', 'dbvc'), ['status' => 400]);
        }

        $allowed_profiles = [
            DBVC_CC_Contracts::SCRUB_PROFILE_DETERMINISTIC_DEFAULT,
            DBVC_CC_Contracts::SCRUB_PROFILE_CUSTOM,
            DBVC_CC_Contracts::SCRUB_PROFILE_AI_SUGGESTED_APPROVED,
        ];
        $profile_mode = isset($args['profile_mode']) ? sanitize_key((string) $args['profile_mode']) : DBVC_CC_Contracts::SCRUB_PROFILE_AI_SUGGESTED_APPROVED;
        if (!in_array($profile_mode, $allowed_profiles, true)) {
            $profile_mode = DBVC_CC_Contracts::SCRUB_PROFILE_AI_SUGGESTED_APPROVED;
        }

        $settings = DBVC_CC_Settings_Service::get_options();
        $settings['scrub_attr_action_class'] = isset($applied_actions['class']) ? (string) $applied_actions['class'] : (string) $settings['scrub_attr_action_class'];
        $settings['scrub_attr_action_id'] = isset($applied_actions['id']) ? (string) $applied_actions['id'] : (string) $settings['scrub_attr_action_id'];
        $settings['scrub_attr_action_data'] = isset($applied_actions['data']) ? (string) $applied_actions['data'] : (string) $settings['scrub_attr_action_data'];
        $settings['scrub_attr_action_style'] = isset($applied_actions['style']) ? (string) $applied_actions['style'] : (string) $settings['scrub_attr_action_style'];
        $settings['scrub_attr_action_aria'] = isset($applied_actions['aria']) ? (string) $applied_actions['aria'] : (string) $settings['scrub_attr_action_aria'];
        $settings['scrub_profile_mode'] = $profile_mode;
        update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $settings);
        $this->options = DBVC_CC_Settings_Service::get_options();

        $approval_record = [
            'updated_at' => current_time('c'),
            'updated_by_user_id' => get_current_user_id(),
            'domain' => isset($preview['domain']) ? (string) $preview['domain'] : '',
            'path' => isset($preview['path']) ? (string) $preview['path'] : '',
            'sample_size' => isset($preview['sample_size']) ? (int) $preview['sample_size'] : 0,
            'profile_mode' => $profile_mode,
            'applied_actions' => $applied_actions,
            'suggested_actions' => $suggested_actions,
            'approval_mode' => !empty($requested_actions) ? 'manual-override' : 'apply-suggested',
        ];
        update_option(DBVC_CC_Contracts::OPTION_SCRUB_POLICY_APPROVAL_STATUS, $approval_record);

        return [
            'status' => 'approved',
            'message' => __('Scrub suggestions were applied to Configure defaults.', 'dbvc'),
            'domain' => $approval_record['domain'],
            'path' => $approval_record['path'],
            'sample_size' => $approval_record['sample_size'],
            'profile_mode' => $approval_record['profile_mode'],
            'applied_actions' => $approval_record['applied_actions'],
            'current_actions' => $this->dbvc_cc_get_current_scrub_actions(),
            'approval_record' => $approval_record,
        ];
    }

    /**
     * Returns audit trail events scoped to a selected node path.
     *
     * @param array $args Route args.
     * @return array|WP_Error
     */
    public function get_node_audit($args) {
        $domain = isset($args['domain']) ? $this->sanitize_domain($args['domain']) : '';
        if ('' === $domain) {
            return new WP_Error('dbvc_cc_invalid_domain', __('A valid domain is required.', 'dbvc'), ['status' => 400]);
        }

        $domain_dir = $this->resolve_domain_dir($domain);
        if (is_wp_error($domain_dir)) {
            return $domain_dir;
        }

        $relative_path = isset($args['path']) ? $this->normalize_relative_path($args['path']) : '';
        $limit = isset($args['limit']) ? absint($args['limit']) : 25;
        $limit = max(5, min(100, $limit));
        $pipeline_filter = isset($args['pipeline_id']) ? sanitize_key((string) $args['pipeline_id']) : '';

        $log_file = trailingslashit($domain_dir) . '_logs/' . DBVC_CC_Artifact_Manager::LOG_FILE;
        if (!file_exists($log_file)) {
            return [
                'domain'       => $domain,
                'path'         => $relative_path,
                'pipeline_id'  => $pipeline_filter,
                'generated_at' => current_time('c'),
                'limit'        => $limit,
                'summary'      => [
                    'total'         => 0,
                    'stage_counts'  => new stdClass(),
                    'status_counts' => new stdClass(),
                    'pipeline_counts' => new stdClass(),
                ],
                'events'       => [],
            ];
        }

        $lines = @file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return new WP_Error('dbvc_cc_audit_log_read', __('Could not read audit log file.', 'dbvc'), ['status' => 500]);
        }

        $events = [];
        $stage_counts = [];
        $status_counts = [];
        $pipeline_counts = [];

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim((string) $lines[$i]);
            if ('' === $line) {
                continue;
            }

            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }

            if (!$this->audit_event_matches_path($decoded, $relative_path)) {
                continue;
            }

            $pipeline_id = isset($decoded['pipeline_id']) ? sanitize_key((string) $decoded['pipeline_id']) : '';
            if ($pipeline_filter !== '' && $pipeline_id !== $pipeline_filter) {
                continue;
            }

            $stage = isset($decoded['stage']) ? sanitize_key((string) $decoded['stage']) : 'unknown';
            $status = isset($decoded['status']) ? sanitize_key((string) $decoded['status']) : 'unknown';
            $event_path = $this->extract_event_path($decoded);

            if (!isset($stage_counts[$stage])) {
                $stage_counts[$stage] = 0;
            }
            $stage_counts[$stage]++;

            if (!isset($status_counts[$status])) {
                $status_counts[$status] = 0;
            }
            $status_counts[$status]++;
            if ($pipeline_id !== '') {
                if (!isset($pipeline_counts[$pipeline_id])) {
                    $pipeline_counts[$pipeline_id] = 0;
                }
                $pipeline_counts[$pipeline_id]++;
            }

            $events[] = [
                'timestamp'    => isset($decoded['timestamp']) ? (string) $decoded['timestamp'] : null,
                'stage'        => $stage,
                'status'       => $status,
                'path'         => $event_path,
                'page_url'     => isset($decoded['page_url']) ? esc_url_raw((string) $decoded['page_url']) : '',
                'job_id'       => isset($decoded['job_id']) ? sanitize_key((string) $decoded['job_id']) : '',
                'pipeline_id'  => $pipeline_id,
                'message'      => isset($decoded['message']) ? sanitize_text_field((string) $decoded['message']) : '',
                'failure_code' => isset($decoded['failure_code']) ? sanitize_key((string) $decoded['failure_code']) : '',
            ];

            if (count($events) >= $limit) {
                break;
            }
        }

        ksort($stage_counts);
        ksort($status_counts);
        arsort($pipeline_counts);

        return [
            'domain'       => $domain,
            'path'         => $relative_path,
            'pipeline_id'  => $pipeline_filter,
            'generated_at' => current_time('c'),
            'limit'        => $limit,
            'summary'      => [
                'total'         => count($events),
                'stage_counts'  => $stage_counts,
                'status_counts' => $status_counts,
                'pipeline_counts' => $pipeline_counts,
            ],
            'events'       => $events,
        ];
    }

    /**
     * Determines whether an event should be included for the selected node scope.
     *
     * @param array  $event         Event payload.
     * @param string $relative_path Selected relative path.
     * @return bool
     */
    private function audit_event_matches_path($event, $relative_path) {
        if ('' === $relative_path) {
            return true;
        }

        $event_path = $this->extract_event_path($event);
        if ('' === $event_path) {
            return false;
        }

        if ($event_path === $relative_path) {
            return true;
        }

        return 0 === strpos($event_path, $relative_path . '/');
    }

    /**
     * Extracts normalized relative path from an event payload.
     *
     * @param array $event Event payload.
     * @return string
     */
    private function extract_event_path($event) {
        if (isset($event['path']) && is_string($event['path'])) {
            return $this->normalize_relative_path($event['path']);
        }

        if (isset($event['relative_path']) && is_string($event['relative_path'])) {
            return $this->normalize_relative_path($event['relative_path']);
        }

        if (isset($event['page_url']) && is_string($event['page_url']) && '' !== trim($event['page_url'])) {
            return $this->normalize_relative_path(DBVC_CC_Artifact_Manager::get_relative_page_path($event['page_url']));
        }

        return '';
    }

    /**
     * Appends child nodes recursively for initial tree payload.
     *
     * @param string $domain        Domain key.
     * @param string $absolute_dir  Parent absolute directory path.
     * @param string $relative_path Parent relative path.
     * @param int    $depth         Parent depth.
     * @param string $parent_id     Parent node ID.
     * @param int    $depth_limit   Max recursive depth.
     * @param int    $max_nodes     Max nodes allowed.
     * @param bool   $include_files Include direct file nodes.
     * @param array  $nodes         Node collection.
     * @param array  $edges         Edge collection.
     * @param int    $node_count    Current node count.
     * @param array  $warnings      Warning collection.
     * @return void
     */
    private function append_children_recursive($domain, $absolute_dir, $relative_path, $depth, $parent_id, $depth_limit, $max_nodes, $include_files, &$nodes, &$edges, &$node_count, &$warnings) {
        $children = $this->list_directories($absolute_dir);
        $child_depth = $depth + 1;

        foreach ($children as $child_name) {
            if ($node_count >= $max_nodes) {
                $warnings[] = __('Node limit reached. Expand nodes lazily to load more.', 'dbvc');
                return;
            }

            $child_relative_path = '' === $relative_path ? $child_name : $relative_path . '/' . $child_name;
            $child_abs = trailingslashit($absolute_dir) . $child_name;
            $child_node_data = $this->build_directory_node_data($domain, $child_abs, $child_relative_path, $child_depth, $include_files);
            if ($child_depth >= $depth_limit && $child_node_data['children_count'] > 0) {
                $child_node_data['has_more_children'] = true;
            }

            $nodes[] = ['data' => $child_node_data];
            $edges[] = [
                'data' => [
                    'id'           => 'edge:' . $parent_id . '->' . $child_node_data['id'],
                    'source'       => $parent_id,
                    'target'       => $child_node_data['id'],
                    'relationship' => 'contains',
                ],
            ];
            $node_count++;

            if ($include_files && $child_depth <= $depth_limit && $node_count < $max_nodes) {
                foreach ($this->list_content_files($child_abs) as $file_name) {
                    if ($node_count >= $max_nodes) {
                        $warnings[] = __('Node limit reached while loading files.', 'dbvc');
                        return;
                    }
                    $file_relative_path = $child_relative_path . '/' . $file_name;
                    $file_node_id = $this->build_node_id($domain, $file_relative_path, 'file');
                    $nodes[] = [
                        'data' => [
                            'id'                => $file_node_id,
                            'label'             => $file_name,
                            'type'              => 'file',
                            'path'              => $file_relative_path,
                            'depth'             => $child_depth + 1,
                            'children_count'    => 0,
                            'has_more_children' => false,
                            'json_exists'       => 'json' === pathinfo($file_name, PATHINFO_EXTENSION),
                            'image_count'       => $this->is_image_file($file_name) ? 1 : 0,
                            'status'            => 'ready',
                        ],
                    ];
                    $edges[] = [
                        'data' => [
                            'id'           => 'edge:' . $child_node_data['id'] . '->' . $file_node_id,
                            'source'       => $child_node_data['id'],
                            'target'       => $file_node_id,
                            'relationship' => 'contains',
                        ],
                    ];
                    $node_count++;
                }
            }

            if ($child_depth < $depth_limit) {
                $this->append_children_recursive(
                    $domain,
                    $child_abs,
                    $child_relative_path,
                    $child_depth,
                    $child_node_data['id'],
                    $depth_limit,
                    $max_nodes,
                    $include_files,
                    $nodes,
                    $edges,
                    $node_count,
                    $warnings
                );
            }
        }
    }

    /**
     * Builds a directory node payload.
     *
     * @param string $domain         Domain key.
     * @param string $absolute_path  Directory absolute path.
     * @param string $relative_path  Relative path.
     * @param int    $depth          Node depth.
     * @param bool   $include_files  Include files in child count.
     * @return array
     */
    private function build_directory_node_data($domain, $absolute_path, $relative_path, $depth, $include_files) {
        $type = $this->determine_directory_type($absolute_path);
        $subdir_count = count($this->list_directories($absolute_path));
        $file_count = $include_files ? count($this->list_content_files($absolute_path)) : 0;
        $children_count = $subdir_count + $file_count;
        $slug = basename($relative_path);
        $expected_json = trailingslashit($absolute_path) . $slug . '.json';
        $image_count = $this->count_direct_images($absolute_path);
        $ai_status = $this->resolve_ai_status($absolute_path, $slug);
        $analysis_status = $ai_status['analysis'];
        $cpt = $this->resolve_page_cpt($absolute_path, $slug, $type);
        $page_urls = $this->resolve_page_urls($absolute_path, $slug, $type);

        $node_status = 'ready';
        if (in_array($analysis_status, ['queued', 'processing'], true)) {
            $node_status = 'processing';
        } elseif ('failed' === $analysis_status) {
            $node_status = 'error';
        }

        return [
            'id'                => $this->build_node_id($domain, $relative_path, $type),
            'label'             => $slug,
            'type'              => $type,
            'path'              => $relative_path,
            'depth'             => $depth,
            'children_count'    => $children_count,
            'has_more_children' => $children_count > 0,
            'json_exists'       => file_exists($expected_json),
            'image_count'       => $image_count,
            'analysis_status'   => $analysis_status,
            'sanitize_status'   => $ai_status['sanitization'],
            'ai_mode'           => $ai_status['mode'],
            'job_id'            => $ai_status['job_id'],
            'cpt'               => $cpt,
            'status'            => $node_status,
            'last_modified'     => $this->safe_filemtime($absolute_path),
            'source_url'        => $page_urls['source_url'],
            'canonical_source_url' => $page_urls['canonical_source_url'],
        ];
    }

    /**
     * Resolves suggested CPT from analysis artifact for page nodes.
     *
     * @param string $absolute_dir Directory path.
     * @param string $slug         Node slug.
     * @param string $type         Node type.
     * @return string
     */
    private function resolve_page_cpt($absolute_dir, $slug, $type) {
        if ('page' !== $type) {
            return '';
        }

        $analysis_file = trailingslashit($absolute_dir) . $slug . '.analysis.json';
        $analysis = $this->read_json_file($analysis_file);
        if (!is_array($analysis) || empty($analysis['post_type'])) {
            return '';
        }

        return sanitize_key((string) $analysis['post_type']);
    }

    /**
     * Resolves source and canonical URLs from page artifact for search/filter metadata.
     *
     * @param string $absolute_dir Directory path.
     * @param string $slug         Node slug.
     * @param string $type         Node type.
     * @return array
     */
    private function resolve_page_urls($absolute_dir, $slug, $type) {
        if ('page' !== $type) {
            return [
                'source_url'           => '',
                'canonical_source_url' => '',
            ];
        }

        $artifact_file = trailingslashit($absolute_dir) . $slug . '.json';
        $artifact = $this->read_json_file($artifact_file);
        if (!is_array($artifact)) {
            return [
                'source_url'           => '',
                'canonical_source_url' => '',
            ];
        }

        $source_url = isset($artifact['source_url']) ? esc_url_raw((string) $artifact['source_url']) : '';
        $canonical = isset($artifact['provenance']['canonical_source_url']) ? esc_url_raw((string) $artifact['provenance']['canonical_source_url']) : '';

        return [
            'source_url'           => $source_url,
            'canonical_source_url' => $canonical,
        ];
    }

    /**
     * Returns AI analysis/sanitization status derived from status file and artifacts.
     *
     * @param string $absolute_dir Directory path.
     * @param string $slug         Node slug.
     * @return array
     */
    private function resolve_ai_status($absolute_dir, $slug) {
        $status_file = trailingslashit($absolute_dir) . $slug . '.analysis.status.json';
        $analysis_file = trailingslashit($absolute_dir) . $slug . '.analysis.json';
        $sanitized_json_file = trailingslashit($absolute_dir) . $slug . '.sanitized.json';
        $sanitized_html_file = trailingslashit($absolute_dir) . $slug . '.sanitized.html';

        $analysis_exists = file_exists($analysis_file);
        $sanitized_exists = file_exists($sanitized_json_file) || file_exists($sanitized_html_file);

        $result = [
            'analysis'     => 'not_started',
            'sanitization' => 'not_started',
            'mode'         => 'pending',
            'job_id'       => null,
            'message'      => null,
            'updated_at'   => null,
        ];

        $status_payload = $this->read_json_file($status_file);
        if (is_array($status_payload)) {
            $raw_status = isset($status_payload['status']) ? sanitize_key((string) $status_payload['status']) : '';
            $raw_mode = isset($status_payload['mode']) ? sanitize_key((string) $status_payload['mode']) : '';

            if ('' !== $raw_mode) {
                $result['mode'] = $raw_mode;
            }
            if (isset($status_payload['job_id'])) {
                $result['job_id'] = sanitize_key((string) $status_payload['job_id']);
            }
            if (isset($status_payload['message'])) {
                $result['message'] = sanitize_text_field((string) $status_payload['message']);
            }
            if (!empty($status_payload['finished_at'])) {
                $result['updated_at'] = (string) $status_payload['finished_at'];
            } elseif (!empty($status_payload['started_at'])) {
                $result['updated_at'] = (string) $status_payload['started_at'];
            } elseif (!empty($status_payload['requested_at'])) {
                $result['updated_at'] = (string) $status_payload['requested_at'];
            }

            if (in_array($raw_status, ['queued', 'processing'], true)) {
                $result['analysis'] = $raw_status;
                $result['sanitization'] = $raw_status;
            } elseif ('failed' === $raw_status) {
                $result['analysis'] = 'failed';
                $result['sanitization'] = 'failed';
                $result['mode'] = 'failed';
            } elseif ('completed' === $raw_status) {
                if ('fallback' === $result['mode']) {
                    $result['analysis'] = $analysis_exists ? 'fallback_done' : 'not_started';
                    $result['sanitization'] = $sanitized_exists ? 'fallback_done' : 'not_started';
                } else {
                    $result['analysis'] = $analysis_exists ? 'done' : 'not_started';
                    $result['sanitization'] = $sanitized_exists ? 'done' : 'not_started';
                    if ('' === $result['mode'] || 'pending' === $result['mode']) {
                        $result['mode'] = 'ai';
                    }
                }
            }
        }

        if ('not_started' === $result['analysis'] && $analysis_exists) {
            $result['analysis'] = 'done';
        }
        if ('not_started' === $result['sanitization'] && $sanitized_exists) {
            $result['sanitization'] = 'done';
        }
        if (in_array($result['analysis'], ['done', 'fallback_done'], true) && in_array($result['mode'], ['', 'pending'], true)) {
            $result['mode'] = 'ai';
        }

        return $result;
    }

    /**
     * Returns phase3.6 mode indicators for quick node metadata display.
     *
     * @param string $absolute_dir Directory path.
     * @param string $slug Node slug.
     * @return array<string, mixed>
     */
    private function dbvc_cc_resolve_phase36_mode_indicators($absolute_dir, $slug) {
        $files = $this->dbvc_cc_get_phase36_sidecar_files($absolute_dir, $slug);
        $artifacts = $this->dbvc_cc_load_phase36_sidecars($files);

        $capture_mode = isset($artifacts['elements']['capture_mode']) ? sanitize_key((string) $artifacts['elements']['capture_mode']) : '';
        if ('' === $capture_mode) {
            $capture_mode = isset($this->options['capture_mode']) ? sanitize_key((string) $this->options['capture_mode']) : DBVC_CC_Contracts::CAPTURE_MODE_DEEP;
        }

        $section_typing_mode = isset($artifacts['section_typing']['mode']) ? sanitize_key((string) $artifacts['section_typing']['mode']) : '';
        if ('' === $section_typing_mode) {
            $section_typing_mode = 'pending';
        }

        $scrub_profile = isset($artifacts['scrub_report']['profile']) ? sanitize_key((string) $artifacts['scrub_report']['profile']) : '';
        if ('' === $scrub_profile) {
            $scrub_profile = isset($this->options['scrub_profile_mode']) ? sanitize_key((string) $this->options['scrub_profile_mode']) : DBVC_CC_Contracts::SCRUB_PROFILE_DETERMINISTIC_DEFAULT;
        }

        return [
            'capture_mode' => $capture_mode,
            'section_typing_mode' => $section_typing_mode,
            'scrub_profile' => $scrub_profile,
            'available' => $this->dbvc_cc_build_phase36_availability_map($files),
        ];
    }

    /**
     * Returns known phase3.6 sidecar file paths for a node.
     *
     * @param string $absolute_dir Directory path.
     * @param string $slug Node slug.
     * @return array<string, string>
     */
    private function dbvc_cc_get_phase36_sidecar_files($absolute_dir, $slug) {
        $prefix = trailingslashit($absolute_dir) . $slug;

        return [
            'elements' => $prefix . DBVC_CC_Contracts::STORAGE_ELEMENTS_V2_SUFFIX,
            'scrub_report' => $prefix . DBVC_CC_Contracts::STORAGE_ATTRIBUTE_SCRUB_REPORT_V2_SUFFIX,
            'sections' => $prefix . DBVC_CC_Contracts::STORAGE_SECTIONS_V2_SUFFIX,
            'section_typing' => $prefix . DBVC_CC_Contracts::STORAGE_SECTION_TYPING_V2_SUFFIX,
            'context_bundle' => $prefix . DBVC_CC_Contracts::STORAGE_CONTEXT_BUNDLE_V2_SUFFIX,
            'ingestion_package' => $prefix . DBVC_CC_Contracts::STORAGE_INGESTION_PACKAGE_V2_SUFFIX,
        ];
    }

    /**
     * Reads available sidecar payloads.
     *
     * @param array<string, string> $files Sidecar file map.
     * @return array<string, array<string, mixed>>
     */
    private function dbvc_cc_load_phase36_sidecars(array $files) {
        $artifacts = [];
        foreach ($files as $key => $file) {
            $payload = $this->read_json_file($file);
            if (is_array($payload)) {
                $artifacts[$key] = $payload;
            }
        }

        return $artifacts;
    }

    /**
     * Builds availability booleans by sidecar.
     *
     * @param array<string, string> $files Sidecar file map.
     * @return array<string, bool>
     */
    private function dbvc_cc_build_phase36_availability_map(array $files) {
        $availability = [];
        foreach ($files as $key => $file) {
            $availability[$key] = file_exists($file);
        }

        return $availability;
    }

    /**
     * Builds summary payload for phase3.6 artifacts.
     *
     * @param array<string, string> $files Sidecar file map.
     * @param array<string, array<string, mixed>> $artifacts Loaded sidecars.
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_phase36_summary(array $files, array $artifacts) {
        $availability = $this->dbvc_cc_build_phase36_availability_map($files);

        $elements = isset($artifacts['elements']) && is_array($artifacts['elements']) ? $artifacts['elements'] : [];
        $sections = isset($artifacts['sections']) && is_array($artifacts['sections']) ? $artifacts['sections'] : [];
        $section_typing = isset($artifacts['section_typing']) && is_array($artifacts['section_typing']) ? $artifacts['section_typing'] : [];
        $context_bundle = isset($artifacts['context_bundle']) && is_array($artifacts['context_bundle']) ? $artifacts['context_bundle'] : [];
        $ingestion_package = isset($artifacts['ingestion_package']) && is_array($artifacts['ingestion_package']) ? $artifacts['ingestion_package'] : [];
        $scrub_report = isset($artifacts['scrub_report']) && is_array($artifacts['scrub_report']) ? $artifacts['scrub_report'] : [];

        $capture_mode = isset($elements['capture_mode']) ? sanitize_key((string) $elements['capture_mode']) : '';
        if ('' === $capture_mode) {
            $capture_mode = isset($this->options['capture_mode']) ? sanitize_key((string) $this->options['capture_mode']) : DBVC_CC_Contracts::CAPTURE_MODE_DEEP;
        }

        $section_typing_mode = isset($section_typing['mode']) ? sanitize_key((string) $section_typing['mode']) : 'pending';
        $scrub_profile = isset($scrub_report['profile']) ? sanitize_key((string) $scrub_report['profile']) : '';
        if ('' === $scrub_profile) {
            $scrub_profile = isset($this->options['scrub_profile_mode']) ? sanitize_key((string) $this->options['scrub_profile_mode']) : DBVC_CC_Contracts::SCRUB_PROFILE_DETERMINISTIC_DEFAULT;
        }

        $elements_preview = [];
        $raw_elements = isset($elements['elements']) && is_array($elements['elements']) ? $elements['elements'] : [];
        foreach (array_slice($raw_elements, 0, 6) as $element) {
            if (!is_array($element)) {
                continue;
            }
            $elements_preview[] = [
                'element_id' => isset($element['element_id']) ? (string) $element['element_id'] : '',
                'tag' => isset($element['tag']) ? (string) $element['tag'] : '',
                'text' => $this->dbvc_cc_truncate_preview_text(isset($element['text']) ? (string) $element['text'] : '', 180),
            ];
        }

        $sections_preview = [];
        $raw_sections = isset($sections['sections']) && is_array($sections['sections']) ? $sections['sections'] : [];
        foreach (array_slice($raw_sections, 0, 6) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $sections_preview[] = [
                'section_id' => isset($section['section_id']) ? (string) $section['section_id'] : '',
                'label' => $this->dbvc_cc_truncate_preview_text(isset($section['section_label_candidate']) ? (string) $section['section_label_candidate'] : '', 140),
                'element_count' => isset($section['element_ids']) && is_array($section['element_ids']) ? count($section['element_ids']) : 0,
            ];
        }

        $typing_preview = [];
        $raw_typings = isset($section_typing['section_typings']) && is_array($section_typing['section_typings']) ? $section_typing['section_typings'] : [];
        foreach (array_slice($raw_typings, 0, 8) as $typing) {
            if (!is_array($typing)) {
                continue;
            }
            $typing_preview[] = [
                'section_id' => isset($typing['section_id']) ? (string) $typing['section_id'] : '',
                'type' => isset($typing['section_type_candidate']) ? sanitize_key((string) $typing['section_type_candidate']) : '',
                'confidence' => isset($typing['confidence']) ? (float) $typing['confidence'] : 0.0,
                'mode' => isset($typing['mode']) ? sanitize_key((string) $typing['mode']) : '',
            ];
        }

        $context_outline_preview = [];
        $raw_outline = isset($context_bundle['outline']) && is_array($context_bundle['outline']) ? $context_bundle['outline'] : [];
        foreach (array_slice($raw_outline, 0, 6) as $outline_item) {
            if (!is_array($outline_item)) {
                continue;
            }
            $context_outline_preview[] = [
                'level' => isset($outline_item['level']) ? (int) $outline_item['level'] : 0,
                'label' => $this->dbvc_cc_truncate_preview_text(isset($outline_item['label']) ? (string) $outline_item['label'] : '', 140),
                'section_id' => isset($outline_item['section_id']) ? (string) $outline_item['section_id'] : '',
            ];
        }

        $ingestion_preview = [];
        $raw_ingestion_sections = isset($ingestion_package['sections']) && is_array($ingestion_package['sections']) ? $ingestion_package['sections'] : [];
        foreach (array_slice($raw_ingestion_sections, 0, 6) as $section_item) {
            if (!is_array($section_item)) {
                continue;
            }
            $ingestion_preview[] = [
                'section_id' => isset($section_item['section_id']) ? (string) $section_item['section_id'] : '',
                'label' => $this->dbvc_cc_truncate_preview_text(isset($section_item['label']) ? (string) $section_item['label'] : '', 140),
                'section_type' => isset($section_item['section_type']) ? sanitize_key((string) $section_item['section_type']) : '',
                'section_type_mode' => isset($section_item['section_type_mode']) ? sanitize_key((string) $section_item['section_type_mode']) : '',
            ];
        }

        $scrub_totals = isset($scrub_report['totals']) && is_array($scrub_report['totals']) ? $scrub_report['totals'] : [];
        $scrub_by_attribute = isset($scrub_report['by_attribute']) && is_array($scrub_report['by_attribute']) ? $scrub_report['by_attribute'] : [];
        $elements_processing = isset($elements['processing']) && is_array($elements['processing']) ? $elements['processing'] : [];
        $sections_processing = isset($sections['processing']) && is_array($sections['processing']) ? $sections['processing'] : [];

        return [
            'available' => $availability,
            'modes' => [
                'capture_mode' => $capture_mode,
                'section_typing_mode' => $section_typing_mode,
                'scrub_profile' => $scrub_profile,
            ],
            'badges' => [
                ['key' => 'capture_mode', 'label' => $capture_mode],
                ['key' => 'section_typing_mode', 'label' => $section_typing_mode],
                ['key' => 'scrub_profile', 'label' => $scrub_profile],
            ],
            'elements' => [
                'element_count' => isset($elements['element_count']) ? (int) $elements['element_count'] : 0,
                'truncated' => !empty($elements['truncated']),
                'processing' => [
                    'is_partial' => !empty($elements_processing['is_partial']),
                    'partial_reason' => isset($elements_processing['partial_reason']) ? sanitize_key((string) $elements_processing['partial_reason']) : '',
                ],
                'preview' => $elements_preview,
            ],
            'sections' => [
                'section_count' => isset($sections['section_count']) ? (int) $sections['section_count'] : 0,
                'processing' => [
                    'is_partial' => !empty($sections_processing['is_partial']),
                    'partial_reason' => isset($sections_processing['partial_reason']) ? sanitize_key((string) $sections_processing['partial_reason']) : '',
                ],
                'preview' => $sections_preview,
            ],
            'section_typing' => [
                'mode' => $section_typing_mode,
                'confidence_threshold' => isset($section_typing['confidence_threshold']) ? (float) $section_typing['confidence_threshold'] : null,
                'count' => count($raw_typings),
                'type_counts' => $this->dbvc_cc_build_phase36_section_type_counts($raw_typings),
                'preview' => $typing_preview,
            ],
            'context_bundle' => [
                'outline_count' => count($raw_outline),
                'section_count' => isset($context_bundle['sections']) && is_array($context_bundle['sections']) ? count($context_bundle['sections']) : 0,
                'internal_link_count' => isset($context_bundle['link_graph_hints']['internal_link_count']) ? (int) $context_bundle['link_graph_hints']['internal_link_count'] : 0,
                'external_link_count' => isset($context_bundle['link_graph_hints']['external_link_count']) ? (int) $context_bundle['link_graph_hints']['external_link_count'] : 0,
                'outline_preview' => $context_outline_preview,
            ],
            'ingestion_package' => [
                'section_count' => isset($ingestion_package['stats']['section_count']) ? (int) $ingestion_package['stats']['section_count'] : count($raw_ingestion_sections),
                'element_count' => isset($ingestion_package['stats']['element_count']) ? (int) $ingestion_package['stats']['element_count'] : 0,
                'preview' => $ingestion_preview,
            ],
            'scrub_report' => [
                'profile' => $scrub_profile,
                'totals' => $scrub_totals,
                'by_attribute' => $scrub_by_attribute,
                'warnings' => isset($scrub_report['warnings']) && is_array($scrub_report['warnings']) ? array_values($scrub_report['warnings']) : [],
            ],
        ];
    }

    /**
     * Normalizes artifact key for phase3.6 endpoint.
     *
     * @param string $artifact Artifact input.
     * @return string
     */
    private function dbvc_cc_normalize_phase36_artifact_key($artifact) {
        $value = sanitize_key((string) $artifact);
        if ('' === $value) {
            return 'all';
        }

        $aliases = [
            'all' => 'all',
            'elements' => 'elements',
            'elements_v2' => 'elements',
            'elements-v2' => 'elements',
            'scrub_report' => 'scrub_report',
            'attribute_scrub_report' => 'scrub_report',
            'scrub-report' => 'scrub_report',
            'attribute-scrub-report' => 'scrub_report',
            'sections' => 'sections',
            'sections_v2' => 'sections',
            'sections-v2' => 'sections',
            'section_typing' => 'section_typing',
            'section_typing_v2' => 'section_typing',
            'section-typing' => 'section_typing',
            'section-typing-v2' => 'section_typing',
            'context_bundle' => 'context_bundle',
            'context_bundle_v2' => 'context_bundle',
            'context-bundle' => 'context_bundle',
            'context-bundle-v2' => 'context_bundle',
            'ingestion_package' => 'ingestion_package',
            'ingestion_package_v2' => 'ingestion_package',
            'ingestion-package' => 'ingestion_package',
            'ingestion-package-v2' => 'ingestion_package',
        ];

        return isset($aliases[$value]) ? $aliases[$value] : '';
    }

    /**
     * Trims large sidecar arrays before REST transport.
     *
     * @param string $artifact_key Artifact key.
     * @param array<string, mixed> $artifact Artifact payload.
     * @param int $limit Max preview rows.
     * @return array<string, mixed>
     */
    private function dbvc_cc_trim_phase36_artifact_for_transport($artifact_key, array $artifact, $limit) {
        $trimmed = $artifact;
        $limit = max(5, min(200, absint($limit)));

        if ('elements' === $artifact_key) {
            $rows = isset($artifact['elements']) && is_array($artifact['elements']) ? $artifact['elements'] : [];
            $trimmed['elements_total'] = count($rows);
            $trimmed['elements'] = [];
            foreach (array_slice($rows, 0, $limit) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $trimmed['elements'][] = [
                    'element_id' => isset($row['element_id']) ? (string) $row['element_id'] : '',
                    'tag' => isset($row['tag']) ? (string) $row['tag'] : '',
                    'sequence_index' => isset($row['sequence_index']) ? (int) $row['sequence_index'] : 0,
                    'text' => $this->dbvc_cc_truncate_preview_text(isset($row['text']) ? (string) $row['text'] : '', 400),
                    'heading_context' => isset($row['heading_context']) && is_array($row['heading_context']) ? $row['heading_context'] : [],
                ];
            }
            $trimmed['preview_truncated'] = count($rows) > $limit;
            return $trimmed;
        }

        if ('sections' === $artifact_key) {
            $rows = isset($artifact['sections']) && is_array($artifact['sections']) ? $artifact['sections'] : [];
            $trimmed['sections_total'] = count($rows);
            $trimmed['sections'] = array_slice($rows, 0, $limit);
            $trimmed['preview_truncated'] = count($rows) > $limit;
            return $trimmed;
        }

        if ('section_typing' === $artifact_key) {
            $rows = isset($artifact['section_typings']) && is_array($artifact['section_typings']) ? $artifact['section_typings'] : [];
            $trimmed['section_typings_total'] = count($rows);
            $trimmed['section_typings'] = array_slice($rows, 0, $limit);
            $trimmed['preview_truncated'] = count($rows) > $limit;
            return $trimmed;
        }

        if ('context_bundle' === $artifact_key) {
            $sections = isset($artifact['sections']) && is_array($artifact['sections']) ? $artifact['sections'] : [];
            $outline = isset($artifact['outline']) && is_array($artifact['outline']) ? $artifact['outline'] : [];
            $trace_map = isset($artifact['trace_map']) && is_array($artifact['trace_map']) ? $artifact['trace_map'] : [];
            $trimmed['sections_total'] = count($sections);
            $trimmed['outline_total'] = count($outline);
            $trimmed['sections'] = array_slice($sections, 0, $limit);
            $trimmed['outline'] = array_slice($outline, 0, $limit);
            $trimmed['trace_map'] = array_slice($trace_map, 0, $limit, true);
            $trimmed['preview_truncated'] = count($sections) > $limit || count($outline) > $limit || count($trace_map) > $limit;
            return $trimmed;
        }

        if ('ingestion_package' === $artifact_key) {
            $sections = isset($artifact['sections']) && is_array($artifact['sections']) ? $artifact['sections'] : [];
            $traceability = isset($artifact['traceability']) && is_array($artifact['traceability']) ? $artifact['traceability'] : [];
            $trimmed['sections_total'] = count($sections);
            $trimmed['sections'] = array_slice($sections, 0, $limit);
            $trimmed['traceability'] = array_slice($traceability, 0, $limit, true);
            $trimmed['preview_truncated'] = count($sections) > $limit || count($traceability) > $limit;
            return $trimmed;
        }

        if ('scrub_report' === $artifact_key) {
            $by_attribute = isset($artifact['by_attribute']) && is_array($artifact['by_attribute']) ? $artifact['by_attribute'] : [];
            $trimmed['by_attribute_total'] = count($by_attribute);
            $trimmed['by_attribute'] = array_slice($by_attribute, 0, $limit, true);
            $warnings = isset($artifact['warnings']) && is_array($artifact['warnings']) ? $artifact['warnings'] : [];
            $trimmed['warnings_total'] = count($warnings);
            $trimmed['warnings'] = array_slice($warnings, 0, $limit);
            $trimmed['preview_truncated'] = count($by_attribute) > $limit || count($warnings) > $limit;
            return $trimmed;
        }

        return $trimmed;
    }

    /**
     * Returns section-type counts by candidate type.
     *
     * @param array<int, mixed> $typings Typing rows.
     * @return array<string, int>
     */
    private function dbvc_cc_build_phase36_section_type_counts(array $typings) {
        $counts = [];
        foreach ($typings as $typing) {
            if (!is_array($typing)) {
                continue;
            }
            $type = isset($typing['section_type_candidate']) ? sanitize_key((string) $typing['section_type_candidate']) : '';
            if ('' === $type) {
                $type = 'unknown';
            }
            if (!isset($counts[$type])) {
                $counts[$type] = 0;
            }
            $counts[$type]++;
        }

        ksort($counts);
        return $counts;
    }

    /**
     * Truncates preview text.
     *
     * @param string $value Text value.
     * @param int $max_chars Max characters.
     * @return string
     */
    private function dbvc_cc_truncate_preview_text($value, $max_chars) {
        $text = trim((string) $value);
        if ('' === $text) {
            return '';
        }

        $max_chars = max(20, absint($max_chars));
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') > $max_chars) {
                return trim(mb_substr($text, 0, $max_chars - 1, 'UTF-8')) . '...';
            }
            return $text;
        }

        if (strlen($text) > $max_chars) {
            return trim(substr($text, 0, $max_chars - 1)) . '...';
        }

        return $text;
    }

    /**
     * Classifies a scrub attribute name into a logical family.
     *
     * @param string $attribute_name Attribute name.
     * @return string
     */
    private function dbvc_cc_get_attribute_family($attribute_name) {
        $value = strtolower(trim((string) $attribute_name));
        if ('' === $value) {
            return 'other';
        }

        if ('class' === $value) {
            return 'class';
        }
        if ('id' === $value) {
            return 'id';
        }
        if ('style' === $value) {
            return 'style';
        }
        if (0 === strpos($value, 'data-')) {
            return 'data';
        }
        if (0 === strpos($value, 'aria-')) {
            return 'aria';
        }
        if (0 === strpos($value, 'on')) {
            return 'event';
        }

        return 'other';
    }

    /**
     * Returns current scrub action defaults from settings.
     *
     * @return array<string, string>
     */
    private function dbvc_cc_get_current_scrub_actions() {
        return [
            'class' => isset($this->options['scrub_attr_action_class']) ? sanitize_key((string) $this->options['scrub_attr_action_class']) : DBVC_CC_Contracts::SCRUB_ACTION_TOKENIZE,
            'id' => isset($this->options['scrub_attr_action_id']) ? sanitize_key((string) $this->options['scrub_attr_action_id']) : DBVC_CC_Contracts::SCRUB_ACTION_HASH,
            'data' => isset($this->options['scrub_attr_action_data']) ? sanitize_key((string) $this->options['scrub_attr_action_data']) : DBVC_CC_Contracts::SCRUB_ACTION_TOKENIZE,
            'style' => isset($this->options['scrub_attr_action_style']) ? sanitize_key((string) $this->options['scrub_attr_action_style']) : DBVC_CC_Contracts::SCRUB_ACTION_DROP,
            'aria' => isset($this->options['scrub_attr_action_aria']) ? sanitize_key((string) $this->options['scrub_attr_action_aria']) : DBVC_CC_Contracts::SCRUB_ACTION_KEEP,
        ];
    }

    /**
     * Validates manual scrub action payload.
     *
     * @param array<string, mixed> $actions Action map.
     * @return array<string, string>
     */
    private function dbvc_cc_validate_scrub_actions(array $actions) {
        $allowed_actions = [
            DBVC_CC_Contracts::SCRUB_ACTION_KEEP,
            DBVC_CC_Contracts::SCRUB_ACTION_DROP,
            DBVC_CC_Contracts::SCRUB_ACTION_HASH,
            DBVC_CC_Contracts::SCRUB_ACTION_TOKENIZE,
        ];
        $validated = [];
        $allowed_keys = ['class', 'id', 'data', 'style', 'aria'];

        foreach ($allowed_keys as $key) {
            if (!array_key_exists($key, $actions)) {
                continue;
            }
            $value = sanitize_key((string) $actions[$key]);
            if (in_array($value, $allowed_actions, true)) {
                $validated[$key] = $value;
            }
        }

        return $validated;
    }

    /**
     * Returns deterministic suggested scrub actions based on sampled attribute families.
     *
     * @param array<string, int> $family_counts Family counts.
     * @return array<string, string>
     */
    private function dbvc_cc_get_suggested_scrub_actions(array $family_counts) {
        $suggested = [
            'class' => DBVC_CC_Contracts::SCRUB_ACTION_TOKENIZE,
            'id' => DBVC_CC_Contracts::SCRUB_ACTION_HASH,
            'data' => DBVC_CC_Contracts::SCRUB_ACTION_TOKENIZE,
            'style' => DBVC_CC_Contracts::SCRUB_ACTION_DROP,
            'aria' => DBVC_CC_Contracts::SCRUB_ACTION_KEEP,
        ];

        if (isset($family_counts['class']) && (int) $family_counts['class'] <= 5) {
            $suggested['class'] = DBVC_CC_Contracts::SCRUB_ACTION_KEEP;
        }
        if (isset($family_counts['data']) && (int) $family_counts['data'] <= 3) {
            $suggested['data'] = DBVC_CC_Contracts::SCRUB_ACTION_KEEP;
        }
        if (isset($family_counts['aria']) && (int) $family_counts['aria'] >= 30) {
            $suggested['aria'] = DBVC_CC_Contracts::SCRUB_ACTION_TOKENIZE;
        }

        return $suggested;
    }

    /**
     * Builds deterministic rationale entries for scrub suggestions.
     *
     * @param array<string, int> $family_counts Family counts.
     * @param array<string, string> $current_actions Current actions.
     * @param array<string, string> $suggested_actions Suggested actions.
     * @return array<int, string>
     */
    private function dbvc_cc_build_scrub_suggestions_rationale(array $family_counts, array $current_actions, array $suggested_actions) {
        $rationale = [];
        foreach ($suggested_actions as $family => $action) {
            $count = isset($family_counts[$family]) ? (int) $family_counts[$family] : 0;
            $current = isset($current_actions[$family]) ? (string) $current_actions[$family] : 'unknown';
            $rationale[] = sprintf(
                '%s attributes sampled: %d. Suggested action: %s (current: %s).',
                $family,
                $count,
                $action,
                $current
            );
        }

        return $rationale;
    }

    /**
     * Sorts a count map by descending count and returns top entries.
     *
     * @param array<string, int> $counts Count map.
     * @param int $limit Max records.
     * @return array<int, array<string, mixed>>
     */
    private function dbvc_cc_sort_counts_desc(array $counts, $limit = 20) {
        arsort($counts);
        $limit = max(1, min(100, absint($limit)));

        $rows = [];
        $index = 0;
        foreach ($counts as $name => $count) {
            $rows[] = [
                'name' => (string) $name,
                'count' => (int) $count,
            ];
            $index++;
            if ($index >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Returns aggregate totals for a domain directory.
     *
     * @param string $domain_dir Domain directory.
     * @return array
     */
    private function compute_totals($domain_dir) {
        $totals = [
            'directories' => 0,
            'pages'       => 0,
            'json_files'  => 0,
            'media_files' => 0,
            'max_depth'   => 0,
        ];

        if (!is_dir($domain_dir)) {
            return $totals;
        }

        $base_len = strlen(trailingslashit($domain_dir));
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($domain_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $relative = str_replace('\\', '/', substr($path, $base_len));
            $relative = ltrim($relative, '/');
            $depth = '' === $relative ? 0 : substr_count($relative, '/') + 1;

            if ($depth > $totals['max_depth']) {
                $totals['max_depth'] = $depth;
            }

            if ($item->isDir()) {
                $totals['directories']++;
                continue;
            }

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ('json' === $ext) {
                $totals['json_files']++;
                $parent_base = basename(dirname($path));
                if (basename($path) === $parent_base . '.json') {
                    $totals['pages']++;
                }
            } elseif ($this->is_image_file($path)) {
                $totals['media_files']++;
            }
        }

        return $totals;
    }

    /**
     * Determines whether directory represents a page or a section.
     *
     * @param string $absolute_dir Directory path.
     * @return string
     */
    private function determine_directory_type($absolute_dir) {
        $slug = basename($absolute_dir);
        $expected_json = trailingslashit($absolute_dir) . $slug . '.json';
        return file_exists($expected_json) ? 'page' : 'section';
    }

    /**
     * Builds a stable node id for Cytoscape payload.
     *
     * @param string $domain        Domain key.
     * @param string $relative_path Relative path.
     * @param string $type          Node type.
     * @return string
     */
    private function build_node_id($domain, $relative_path, $type) {
        if ('domain' === $type) {
            return 'domain:' . $domain;
        }

        return $type . ':' . $relative_path;
    }

    /**
     * Returns direct subdirectories.
     *
     * @param string $directory Directory path.
     * @return array
     */
    private function list_directories($directory) {
        if (!is_dir($directory)) {
            return [];
        }

        $entries = @scandir($directory);
        if (!is_array($entries)) {
            return [];
        }

        $directories = [];
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            if (0 === strpos($entry, '.')) {
                continue;
            }

            $path = trailingslashit($directory) . $entry;
            if (is_dir($path)) {
                $directories[] = $entry;
            }
        }

        natcasesort($directories);
        return array_values($directories);
    }

    /**
     * Returns direct content files eligible for explorer display.
     *
     * @param string $directory Directory path.
     * @return array
     */
    private function list_content_files($directory) {
        if (!is_dir($directory)) {
            return [];
        }

        $entries = @scandir($directory);
        if (!is_array($entries)) {
            return [];
        }

        $files = [];
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            if (0 === strpos($entry, '.')) {
                continue;
            }

            $path = trailingslashit($directory) . $entry;
            if (is_dir($path)) {
                continue;
            }

            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (in_array($extension, ['json', 'yml', 'yaml', 'md'], true) || $this->is_image_file($entry)) {
                $files[] = $entry;
            }
        }

        natcasesort($files);
        return array_values($files);
    }

    /**
     * Counts direct image files in a directory.
     *
     * @param string $directory Directory path.
     * @return int
     */
    private function count_direct_images($directory) {
        $count = 0;
        foreach ($this->list_content_files($directory) as $file) {
            if ($this->is_image_file($file)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Checks whether a filename/path is an image.
     *
     * @param string $file File path or file name.
     * @return bool
     */
    private function is_image_file($file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'], true);
    }

    /**
     * Safely returns file modified time in ISO format.
     *
     * @param string $path File path.
     * @return string|null
     */
    private function safe_filemtime($path) {
        $mtime = @filemtime($path);
        if (false === $mtime) {
            return null;
        }

        return gmdate('c', $mtime);
    }

    /**
     * Returns a change signature for cache invalidation.
     *
     * @param string $domain_dir Domain directory.
     * @return string
     */
    private function get_domain_signature($domain_dir) {
        $parts = [];
        $parts[] = (string) @filemtime($domain_dir);
        $parts[] = (string) @filemtime(trailingslashit($domain_dir) . DBVC_CC_Artifact_Manager::INDEX_FILE);
        $parts[] = (string) @filemtime(trailingslashit($domain_dir) . DBVC_CC_Artifact_Manager::REDIRECT_FILE);

        return md5(implode('|', $parts));
    }

    /**
     * Resolves and validates a domain directory.
     *
     * @param string $domain Domain key.
     * @return string|WP_Error
     */
    private function resolve_domain_dir($domain) {
        if (!preg_match('/^[a-z0-9.-]+$/', $domain)) {
            return new WP_Error('dbvc_cc_invalid_domain', __('Invalid domain format.', 'dbvc'), ['status' => 400]);
        }

        $base_dir = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        if (!is_dir($base_dir)) {
            return new WP_Error('dbvc_cc_storage_missing', __('Content collector storage path does not exist yet.', 'dbvc'), ['status' => 404]);
        }

        $base_real = realpath($base_dir);
        if (!is_string($base_real)) {
            return new WP_Error('dbvc_cc_storage_missing', __('Unable to resolve storage path.', 'dbvc'), ['status' => 500]);
        }

        $domain_path = trailingslashit($base_real) . $domain;
        if (!is_dir($domain_path)) {
            return new WP_Error('dbvc_cc_domain_missing', __('No crawl data found for this domain.', 'dbvc'), ['status' => 404]);
        }

        $domain_real = realpath($domain_path);
        if (!is_string($domain_real) || 0 !== strpos($domain_real, $base_real)) {
            return new WP_Error('dbvc_cc_invalid_path', __('Invalid domain path.', 'dbvc'), ['status' => 400]);
        }

        return $domain_real;
    }

    /**
     * Resolves and validates a relative directory within a domain directory.
     *
     * @param string $domain_dir    Domain directory.
     * @param string $relative_path Relative path.
     * @return string|WP_Error
     */
    private function resolve_relative_directory($domain_dir, $relative_path) {
        if ('' === $relative_path) {
            return $domain_dir;
        }

        $target = trailingslashit($domain_dir) . $relative_path;
        if (!is_dir($target)) {
            return new WP_Error('dbvc_cc_missing_path', __('The requested explorer path does not exist.', 'dbvc'), ['status' => 404]);
        }

        $target_real = realpath($target);
        if (!is_string($target_real) || 0 !== strpos($target_real, $domain_dir)) {
            return new WP_Error('dbvc_cc_invalid_path', __('Invalid explorer path.', 'dbvc'), ['status' => 400]);
        }

        return $target_real;
    }

    /**
     * Normalizes domain input.
     *
     * @param string $domain Domain input.
     * @return string
     */
    private function sanitize_domain($domain) {
        $value = strtolower((string) $domain);
        return preg_replace('/[^a-z0-9.-]/', '', $value);
    }

    /**
     * Normalizes relative path input.
     *
     * @param string $path Relative path.
     * @return string
     */
    private function normalize_relative_path($path) {
        $value = (string) $path;
        $value = urldecode($value);
        $value = str_replace('\\', '/', $value);
        $value = trim($value, '/');

        if ('' === $value) {
            return '';
        }

        $parts = [];
        foreach (explode('/', $value) as $part) {
            $part = sanitize_title($part);
            if ('' !== $part && '..' !== $part) {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    /**
     * Returns normalized depth value.
     *
     * @param int $depth Depth input.
     * @return int
     */
    private function normalize_depth($depth) {
        $value = absint($depth);
        if ($value <= 0) {
            $value = isset($this->options['explorer_default_depth']) ? absint($this->options['explorer_default_depth']) : 2;
        }

        return max(1, min(5, $value));
    }

    /**
     * Returns normalized max node value.
     *
     * @param int $max_nodes Max nodes input.
     * @return int
     */
    private function normalize_max_nodes($max_nodes) {
        $value = absint($max_nodes);
        if ($value <= 0) {
            $value = isset($this->options['explorer_max_nodes']) ? absint($this->options['explorer_max_nodes']) : 600;
        }

        return max(100, min(2000, $value));
    }

    /**
     * Returns normalized explorer cache TTL.
     *
     * @return int
     */
    private function normalize_cache_ttl() {
        $value = isset($this->options['explorer_cache_ttl']) ? absint($this->options['explorer_cache_ttl']) : 300;
        return max(30, min(3600, $value));
    }

    /**
     * Returns depth from a relative path.
     *
     * @param string $path Relative path.
     * @return int
     */
    private function path_depth($path) {
        if ('' === $path) {
            return 0;
        }

        return substr_count($path, '/') + 1;
    }

    /**
     * Reads JSON from file.
     *
     * @param string $file File path.
     * @return array|null
     */
    private function read_json_file($file) {
        if (!file_exists($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        if (!is_string($raw) || '' === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
