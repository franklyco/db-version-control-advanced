<?php
/**
 * The core crawling and scraping logic.
 *
 * @package ContentCollector
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class DBVC_CC_Crawler_Service {

    private $page_url;
    private $options;

    public function __construct($page_url, $crawl_overrides = []) {
        $this->page_url = $page_url;
        $this->options = self::get_effective_crawl_options($crawl_overrides);
    }

    /**
     * @param array<string, mixed> $raw_overrides
     * @return array<string, mixed>
     */
    public static function sanitize_crawl_overrides($raw_overrides) {
        if (!is_array($raw_overrides)) {
            return [];
        }

        $overrides = [];
        $allowed_capture_modes = [DBVC_CC_Contracts::CAPTURE_MODE_STANDARD, DBVC_CC_Contracts::CAPTURE_MODE_DEEP];
        $allowed_scrub_profiles = [
            DBVC_CC_Contracts::SCRUB_PROFILE_DETERMINISTIC_DEFAULT,
            DBVC_CC_Contracts::SCRUB_PROFILE_CUSTOM,
            DBVC_CC_Contracts::SCRUB_PROFILE_AI_SUGGESTED_APPROVED,
        ];
        $allowed_scrub_actions = [
            DBVC_CC_Contracts::SCRUB_ACTION_KEEP,
            DBVC_CC_Contracts::SCRUB_ACTION_DROP,
            DBVC_CC_Contracts::SCRUB_ACTION_HASH,
            DBVC_CC_Contracts::SCRUB_ACTION_TOKENIZE,
        ];

        if (isset($raw_overrides['request_delay'])) {
            $overrides['request_delay'] = max(0, min(10000, absint($raw_overrides['request_delay'])));
        }

        if (isset($raw_overrides['request_timeout'])) {
            $overrides['request_timeout'] = max(1, min(300, absint($raw_overrides['request_timeout'])));
        }

        if (isset($raw_overrides['user_agent'])) {
            $user_agent = sanitize_text_field((string) $raw_overrides['user_agent']);
            if ($user_agent !== '') {
                $overrides['user_agent'] = $user_agent;
            }
        }

        if (isset($raw_overrides['exclude_selectors'])) {
            $overrides['exclude_selectors'] = sanitize_textarea_field((string) $raw_overrides['exclude_selectors']);
        }

        if (isset($raw_overrides['focus_selectors'])) {
            $overrides['focus_selectors'] = sanitize_textarea_field((string) $raw_overrides['focus_selectors']);
        }

        if (isset($raw_overrides['capture_mode'])) {
            $capture_mode = sanitize_key((string) $raw_overrides['capture_mode']);
            if (in_array($capture_mode, $allowed_capture_modes, true)) {
                $overrides['capture_mode'] = $capture_mode;
            }
        }

        if (isset($raw_overrides['capture_include_attribute_context'])) {
            $overrides['capture_include_attribute_context'] = ! empty($raw_overrides['capture_include_attribute_context']) ? 1 : 0;
        }

        if (isset($raw_overrides['capture_include_dom_path'])) {
            $overrides['capture_include_dom_path'] = ! empty($raw_overrides['capture_include_dom_path']) ? 1 : 0;
        }

        if (isset($raw_overrides['capture_max_elements_per_page'])) {
            $overrides['capture_max_elements_per_page'] = max(100, min(10000, absint($raw_overrides['capture_max_elements_per_page'])));
        }

        if (isset($raw_overrides['capture_max_chars_per_element'])) {
            $overrides['capture_max_chars_per_element'] = max(100, min(4000, absint($raw_overrides['capture_max_chars_per_element'])));
        }

        if (isset($raw_overrides['context_enable_boilerplate_detection'])) {
            $overrides['context_enable_boilerplate_detection'] = ! empty($raw_overrides['context_enable_boilerplate_detection']) ? 1 : 0;
        }

        if (isset($raw_overrides['context_enable_entity_hints'])) {
            $overrides['context_enable_entity_hints'] = ! empty($raw_overrides['context_enable_entity_hints']) ? 1 : 0;
        }

        if (isset($raw_overrides['ai_enable_section_typing'])) {
            $overrides['ai_enable_section_typing'] = ! empty($raw_overrides['ai_enable_section_typing']) ? 1 : 0;
        }

        if (isset($raw_overrides['ai_section_typing_confidence_threshold']) && is_numeric((string) $raw_overrides['ai_section_typing_confidence_threshold'])) {
            $overrides['ai_section_typing_confidence_threshold'] = max(0.0, min(1.0, (float) $raw_overrides['ai_section_typing_confidence_threshold']));
        }

        if (isset($raw_overrides['scrub_policy_enabled'])) {
            $overrides['scrub_policy_enabled'] = ! empty($raw_overrides['scrub_policy_enabled']) ? 1 : 0;
        }

        if (isset($raw_overrides['scrub_profile_mode'])) {
            $scrub_profile_mode = sanitize_key((string) $raw_overrides['scrub_profile_mode']);
            if (in_array($scrub_profile_mode, $allowed_scrub_profiles, true)) {
                $overrides['scrub_profile_mode'] = $scrub_profile_mode;
            }
        }

        if (isset($raw_overrides['scrub_attr_action_class'])) {
            $scrub_attr_action_class = sanitize_key((string) $raw_overrides['scrub_attr_action_class']);
            if (in_array($scrub_attr_action_class, $allowed_scrub_actions, true)) {
                $overrides['scrub_attr_action_class'] = $scrub_attr_action_class;
            }
        }

        if (isset($raw_overrides['scrub_attr_action_id'])) {
            $scrub_attr_action_id = sanitize_key((string) $raw_overrides['scrub_attr_action_id']);
            if (in_array($scrub_attr_action_id, $allowed_scrub_actions, true)) {
                $overrides['scrub_attr_action_id'] = $scrub_attr_action_id;
            }
        }

        if (isset($raw_overrides['scrub_attr_action_data'])) {
            $scrub_attr_action_data = sanitize_key((string) $raw_overrides['scrub_attr_action_data']);
            if (in_array($scrub_attr_action_data, $allowed_scrub_actions, true)) {
                $overrides['scrub_attr_action_data'] = $scrub_attr_action_data;
            }
        }

        if (isset($raw_overrides['scrub_attr_action_style'])) {
            $scrub_attr_action_style = sanitize_key((string) $raw_overrides['scrub_attr_action_style']);
            if (in_array($scrub_attr_action_style, $allowed_scrub_actions, true)) {
                $overrides['scrub_attr_action_style'] = $scrub_attr_action_style;
            }
        }

        if (isset($raw_overrides['scrub_attr_action_aria'])) {
            $scrub_attr_action_aria = sanitize_key((string) $raw_overrides['scrub_attr_action_aria']);
            if (in_array($scrub_attr_action_aria, $allowed_scrub_actions, true)) {
                $overrides['scrub_attr_action_aria'] = $scrub_attr_action_aria;
            }
        }

        if (isset($raw_overrides['scrub_custom_allowlist'])) {
            $overrides['scrub_custom_allowlist'] = sanitize_textarea_field((string) $raw_overrides['scrub_custom_allowlist']);
        }

        if (isset($raw_overrides['scrub_custom_denylist'])) {
            $overrides['scrub_custom_denylist'] = sanitize_textarea_field((string) $raw_overrides['scrub_custom_denylist']);
        }

        if (isset($raw_overrides['scrub_ai_suggestion_enabled'])) {
            $overrides['scrub_ai_suggestion_enabled'] = ! empty($raw_overrides['scrub_ai_suggestion_enabled']) ? 1 : 0;
        }

        if (isset($raw_overrides['scrub_preview_sample_size'])) {
            $overrides['scrub_preview_sample_size'] = max(1, min(100, absint($raw_overrides['scrub_preview_sample_size'])));
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $crawl_overrides
     * @return array<string, mixed>
     */
    public static function get_effective_crawl_options($crawl_overrides = []) {
        $options = DBVC_CC_Settings_Service::get_options();
        $overrides = self::sanitize_crawl_overrides($crawl_overrides);

        foreach ($overrides as $key => $value) {
            $options[$key] = $value;
        }

        return $options;
    }

    /**
     * Process the entire page crawl.
     *
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function process_page() {
        $page_dir = $this->create_page_directory();
        if (is_wp_error($page_dir)) {
            DBVC_CC_Artifact_Manager::log_event(
                [
                    'stage'        => 'crawl',
                    'status'       => 'error',
                    'page_url'     => $this->page_url,
                    'failure_code' => $page_dir->get_error_code(),
                    'message'      => $page_dir->get_error_message(),
                ]
            );
            return $page_dir;
        }

        $html = $this->fetch_page_content();
        if (is_wp_error($html)) {
            DBVC_CC_Artifact_Manager::log_event(
                [
                    'stage'        => 'crawl',
                    'status'       => 'error',
                    'page_url'     => $this->page_url,
                    'failure_code' => $html->get_error_code(),
                    'message'      => $html->get_error_message(),
                ]
            );
            return $html;
        }

        $scraped_data = $this->scrape_data($html);
        $phase36_artifacts = [];
        if (isset($scraped_data['_dbvc_cc_phase36']) && is_array($scraped_data['_dbvc_cc_phase36'])) {
            $phase36_artifacts = $scraped_data['_dbvc_cc_phase36'];
            unset($scraped_data['_dbvc_cc_phase36']);
        }

        $slug = $this->get_slug();
        $json_file = trailingslashit($page_dir) . $slug . '.json';

        if (DBVC_CC_Artifact_Manager::write_json_file($json_file, $scraped_data)) {
            $relative_path = DBVC_CC_Artifact_Manager::get_relative_page_path($this->page_url);
            $content_hash = isset($scraped_data['provenance']['content_hash']) ? (string) $scraped_data['provenance']['content_hash'] : hash('sha256', $html);

            DBVC_CC_Artifact_Manager::update_domain_index($this->page_url, $relative_path, $content_hash);
            DBVC_CC_Artifact_Manager::update_redirect_map($this->page_url, $relative_path);
            DBVC_CC_Artifact_Manager::sync_page_to_dev($this->page_url, $page_dir);
            $this->write_phase36_artifacts($page_dir, $slug, $phase36_artifacts);

            DBVC_CC_Artifact_Manager::log_event(
                [
                    'stage'         => 'crawl',
                    'status'        => 'success',
                    'page_url'      => $this->page_url,
                    'relative_path' => $relative_path,
                    'artifact_file' => $json_file,
                    'image_count'   => count($scraped_data['content']['images']),
                ]
            );

            return true;
        }

        DBVC_CC_Artifact_Manager::log_event(
            [
                'stage'        => 'crawl',
                'status'       => 'error',
                'page_url'     => $this->page_url,
                'failure_code' => 'dbvc_cc_json_write_error',
                'message'      => sprintf(__('Could not write JSON for %s.', 'dbvc'), $this->page_url),
            ]
        );

        return new WP_Error('dbvc_cc_json_write_error', sprintf(__('Could not write JSON for %s.', 'dbvc'), $this->page_url));
    }

    /**
     * Creates the deterministic storage folder for the page.
     *
     * @return string|WP_Error
     */
    private function create_page_directory() {
        return DBVC_CC_Artifact_Manager::prepare_page_directory($this->page_url);
    }

    /**
     * Fetches page HTML.
     *
     * @return string|WP_Error
     */
    private function fetch_page_content() {
        $args = [
            'timeout'    => $this->options['request_timeout'],
            'user-agent' => $this->options['user_agent'],
        ];

        $response = wp_remote_get($this->page_url, $args);
        if (is_wp_error($response)) {
            return new WP_Error('dbvc_cc_fetch_failed', sprintf(__('Fetch failed for %s: %s', 'dbvc'), $this->page_url, $response->get_error_message()));
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return new WP_Error('dbvc_cc_page_empty', sprintf(__('Page %s is empty.', 'dbvc'), $this->page_url));
        }

        return $html;
    }

    /**
     * Scrapes page metadata/content/images and appends provenance/compliance data.
     *
     * @param string $html Source HTML.
     * @return array
     */
    private function scrape_data($html) {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        @$doc->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        $title_node = $xpath->query('//title')->item(0);
        $meta_title = $title_node ? trim($title_node->nodeValue) : '';

        $desc_node = $xpath->query("//meta[@name='description']/@content")->item(0);
        $meta_description = $desc_node ? trim($desc_node->nodeValue) : '';

        $og_tags = [];
        $og_nodes = $xpath->query("//meta[starts-with(@property, 'og:')]");
        foreach ($og_nodes as $node) {
            $property = $node->getAttribute('property');
            $content = $node->getAttribute('content');
            if ($property && $content) {
                $og_tags[str_replace('og:', '', $property)] = $content;
            }
        }

        $schema_data = [];
        $schema_nodes = $xpath->query("//script[@type='application/ld+json']");
        foreach ($schema_nodes as $node) {
            $json = json_decode($node->nodeValue, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $schema_data[] = $json;
            }
        }

        if (!empty($this->options['exclude_selectors'])) {
            $exclude_xpath = dbvc_cc_css_to_xpath($this->options['exclude_selectors']);
            $nodes_to_remove = $xpath->query($exclude_xpath);
            foreach ($nodes_to_remove as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $context_nodes = [$doc];
        if (!empty($this->options['focus_selectors'])) {
            $focus_xpath = dbvc_cc_css_to_xpath($this->options['focus_selectors']);
            $found_nodes = $xpath->query($focus_xpath);
            if ($found_nodes && $found_nodes->length > 0) {
                $context_nodes = iterator_to_array($found_nodes);
            }
        }

        $content_hash = hash('sha256', $html);
        $crawl_timestamp = current_time('c');

        $scraped_data = [
            'page_name'       => $meta_title,
            'slug'            => $this->get_slug(),
            'source_url'      => $this->page_url,
            'collection_date' => current_time('mysql'),
            'provenance'      => [
                'source_url'           => $this->page_url,
                'canonical_source_url' => DBVC_CC_Artifact_Manager::canonicalize_url($this->page_url),
                'crawl_timestamp'      => $crawl_timestamp,
                'content_hash'         => $content_hash,
                'prompt_version'       => $this->options['prompt_version'],
            ],
            'processing'      => [
                'ai_fallback_mode'         => !empty($this->options['ai_fallback_mode']),
                'slug_collision_policy'    => $this->options['slug_collision_policy'],
                'taxonomy_collision_policy'=> $this->options['taxonomy_collision_policy'],
            ],
            'meta'            => [
                'title'       => $meta_title,
                'description' => $meta_description,
                'opengraph'   => $og_tags,
                'schema'      => $schema_data,
            ],
            'content'         => [
                'headings'               => [],
                'text_blocks'            => [],
                'images'                 => [],
                'section_schema_version' => '1.0',
                'section_count'          => 0,
                'sections'               => [],
            ],
        ];

        foreach ($context_nodes as $context_node) {
            $headings = $xpath->query('.//h1 | .//h2 | .//h3 | .//h4 | .//h5 | .//h6', $context_node);
            $first_h1 = $xpath->query('.//h1', $context_node)->item(0);

            if ($first_h1) {
                $scraped_data['page_name'] = trim($first_h1->nodeValue);
            } elseif (empty($scraped_data['page_name']) && $headings->length > 0) {
                $scraped_data['page_name'] = trim($headings->item(0)->nodeValue);
            }

            foreach ($headings as $heading) {
                $scraped_data['content']['headings'][] = trim($heading->nodeValue);
            }

            $text_nodes = $xpath->query('.//p | .//li', $context_node);
            foreach ($text_nodes as $node) {
                $scraped_data['content']['text_blocks'][] = trim($node->nodeValue);
            }

            $images = $xpath->query('.//img', $context_node);
            foreach ($images as $image) {
                $src = $image->getAttribute('src');
                if (empty($src)) {
                    continue;
                }

                $image_url = dbvc_cc_convert_to_absolute_url($src, $this->page_url);
                $filename = sanitize_file_name(basename(wp_parse_url($image_url, PHP_URL_PATH)));

                if (!pathinfo($filename, PATHINFO_EXTENSION)) {
                    $temp_img_data = wp_remote_get($image_url);
                    $content_type = wp_remote_retrieve_header($temp_img_data, 'content-type');
                    $extension = dbvc_cc_get_extension_from_mime($content_type);
                    if ($extension) {
                        $filename .= '.' . $extension;
                    }
                }

                $page_dir = trailingslashit($this->get_page_directory_path());
                if ($this->download_image($image_url, $page_dir . $filename)) {
                    $scraped_data['content']['images'][] = [
                        'source_url'     => $image_url,
                        'local_filename' => $filename,
                    ];
                }
            }
        }

        $scraped_data['content']['sections'] = $this->build_content_sections($context_nodes, $xpath, $scraped_data['content']['images']);
        $scraped_data['content']['section_count'] = count($scraped_data['content']['sections']);

        $this->annotate_privacy_and_redactions($scraped_data, $html);
        $phase36_artifacts = $this->build_phase36_artifacts($xpath, $context_nodes, $scraped_data);
        if (!empty($phase36_artifacts)) {
            $scraped_data['_dbvc_cc_phase36'] = $phase36_artifacts;
        }

        return $scraped_data;
    }

    /**
     * Builds grouped content sections using heading hierarchy and page flow.
     *
     * @param array    $context_nodes Context node list.
     * @param DOMXPath $xpath         XPath instance.
     * @param array    $images        Downloaded image list.
     * @return array
     */
    private function build_content_sections($context_nodes, $xpath, $images) {
        $sections = [];
        $section_counter = 0;
        $current_section_index = -1;
        $heading_stack = [];
        $image_lookup = $this->build_image_lookup($images);

        foreach ($context_nodes as $context_node) {
            $nodes = $xpath->query('.//*', $context_node);
            if (!$nodes) {
                continue;
            }

            foreach ($nodes as $node) {
                if (!($node instanceof DOMElement)) {
                    continue;
                }

                $tag = strtolower($node->tagName);
                if (in_array($tag, ['script', 'style', 'noscript', 'template', 'svg', 'path'], true)) {
                    continue;
                }

                if (preg_match('/^h([1-6])$/', $tag, $match)) {
                    $heading_text = $this->normalize_block_text($node->textContent);
                    if ('' === $heading_text) {
                        continue;
                    }

                    $level = (int) $match[1];
                    $parent_id = null;
                    for ($i = $level - 1; $i >= 1; $i--) {
                        if (isset($heading_stack[$i])) {
                            $parent_id = $heading_stack[$i];
                            break;
                        }
                    }

                    foreach (array_keys($heading_stack) as $stack_level) {
                        if ($stack_level >= $level) {
                            unset($heading_stack[$stack_level]);
                        }
                    }

                    $section_counter++;
                    $section_id = 'section-' . $section_counter;
                    $sections[] = [
                        'id'         => $section_id,
                        'order'      => $section_counter,
                        'parent_id'  => $parent_id,
                        'level'      => $level,
                        'heading_tag'=> $tag,
                        'heading'    => $heading_text,
                        'is_intro'   => false,
                        'text_blocks'=> [],
                        'links'      => [],
                        'ctas'       => [],
                        'images'     => [],
                    ];
                    $current_section_index = count($sections) - 1;
                    $heading_stack[$level] = $section_id;
                    continue;
                }

                if (in_array($tag, ['p', 'li'], true)) {
                    $this->ensure_section($sections, $current_section_index, $section_counter);
                    $text = $this->normalize_block_text($node->textContent);
                    if ('' !== $text) {
                        $this->append_unique_text($sections[$current_section_index]['text_blocks'], $text);
                    }
                    continue;
                }

                if (in_array($tag, ['a', 'button'], true)) {
                    $this->ensure_section($sections, $current_section_index, $section_counter);
                    $action = $this->extract_action($node, $tag);
                    if (null !== $action) {
                        if ('a' === $tag) {
                            $this->append_unique_action($sections[$current_section_index]['links'], $action);
                        }
                        if ($action['is_cta']) {
                            $cta = $action;
                            unset($cta['is_cta']);
                            $this->append_unique_action($sections[$current_section_index]['ctas'], $cta);
                        }
                    }
                    continue;
                }

                if ('img' === $tag) {
                    $this->ensure_section($sections, $current_section_index, $section_counter);
                    $image = $this->extract_section_image($node, $image_lookup);
                    if (null !== $image) {
                        $this->append_unique_image($sections[$current_section_index]['images'], $image);
                    }
                }
            }
        }

        return $sections;
    }

    /**
     * Ensures there is a section available for non-heading content.
     *
     * @param array $sections              Section list.
     * @param int   $current_section_index Current section index.
     * @param int   $section_counter       Section counter.
     * @return void
     */
    private function ensure_section(&$sections, &$current_section_index, &$section_counter) {
        if ($current_section_index >= 0 && isset($sections[$current_section_index])) {
            return;
        }

        $section_counter++;
        $sections[] = [
            'id'         => 'section-' . $section_counter,
            'order'      => $section_counter,
            'parent_id'  => null,
            'level'      => 0,
            'heading_tag'=> null,
            'heading'    => null,
            'is_intro'   => true,
            'text_blocks'=> [],
            'links'      => [],
            'ctas'       => [],
            'images'     => [],
        ];
        $current_section_index = count($sections) - 1;
    }

    /**
     * Normalizes extracted block text for section payloads.
     *
     * @param string $text Raw text.
     * @return string
     */
    private function normalize_block_text($text) {
        $value = trim((string) $text);
        if ('' === $value) {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', $value);
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Adds unique text to a section array.
     *
     * @param array  $collection Collection list.
     * @param string $text       Text value.
     * @return void
     */
    private function append_unique_text(&$collection, $text) {
        if ('' === $text) {
            return;
        }
        if (!in_array($text, $collection, true)) {
            $collection[] = $text;
        }
    }

    /**
     * Adds a unique action item (link/cta) to a section list.
     *
     * @param array $collection Action collection.
     * @param array $action     Action payload.
     * @return void
     */
    private function append_unique_action(&$collection, $action) {
        if (!is_array($action)) {
            return;
        }
        $needle = (isset($action['type']) ? $action['type'] : '') . '|' .
            (isset($action['text']) ? $action['text'] : '') . '|' .
            (isset($action['url']) ? $action['url'] : '');

        foreach ($collection as $existing) {
            if (!is_array($existing)) {
                continue;
            }
            $existing_key = (isset($existing['type']) ? $existing['type'] : '') . '|' .
                (isset($existing['text']) ? $existing['text'] : '') . '|' .
                (isset($existing['url']) ? $existing['url'] : '');
            if ($existing_key === $needle) {
                return;
            }
        }

        $collection[] = $action;
    }

    /**
     * Adds a unique image payload to a section list.
     *
     * @param array $collection Image collection.
     * @param array $image      Image payload.
     * @return void
     */
    private function append_unique_image(&$collection, $image) {
        if (!is_array($image)) {
            return;
        }
        $needle = (isset($image['source_url']) ? $image['source_url'] : '') . '|' .
            (isset($image['local_filename']) ? $image['local_filename'] : '');

        foreach ($collection as $existing) {
            if (!is_array($existing)) {
                continue;
            }
            $existing_key = (isset($existing['source_url']) ? $existing['source_url'] : '') . '|' .
                (isset($existing['local_filename']) ? $existing['local_filename'] : '');
            if ($existing_key === $needle) {
                return;
            }
        }

        $collection[] = $image;
    }

    /**
     * Extracts action metadata from anchor/button nodes.
     *
     * @param DOMElement $node Element node.
     * @param string     $tag  Element tag.
     * @return array|null
     */
    private function extract_action($node, $tag) {
        $text = $this->normalize_block_text($node->textContent);
        $url = '';

        if ('a' === $tag) {
            $href = trim((string) $node->getAttribute('href'));
            if ('' === $href || preg_match('/^\s*javascript:/i', $href)) {
                return null;
            }
            $url = dbvc_cc_convert_to_absolute_url($href, $this->page_url);
        }

        $is_cta = $this->is_cta_node($node, $tag, $text);
        if ('' === $text && '' === $url) {
            return null;
        }

        return [
            'type'   => 'button' === $tag ? 'button' : 'link',
            'text'   => $text,
            'url'    => $url,
            'is_cta' => $is_cta,
        ];
    }

    /**
     * Determines whether an action node should be classified as CTA.
     *
     * @param DOMElement $node Element node.
     * @param string     $tag  Tag name.
     * @param string     $text Action text.
     * @return bool
     */
    private function is_cta_node($node, $tag, $text) {
        if ('button' === $tag) {
            return true;
        }

        $class_attr = strtolower((string) $node->getAttribute('class'));
        $role_attr = strtolower((string) $node->getAttribute('role'));
        if (false !== strpos($class_attr, 'btn') || false !== strpos($class_attr, 'button') || false !== strpos($class_attr, 'cta')) {
            return true;
        }
        if ('button' === $role_attr) {
            return true;
        }

        return (bool) preg_match('/\b(get started|learn more|contact|book|schedule|call|request|quote|start)\b/i', (string) $text);
    }

    /**
     * Builds source-url to local filename lookup for downloaded images.
     *
     * @param array $images Downloaded image list.
     * @return array
     */
    private function build_image_lookup($images) {
        $lookup = [];
        if (!is_array($images)) {
            return $lookup;
        }

        foreach ($images as $image) {
            if (!is_array($image) || empty($image['source_url'])) {
                continue;
            }
            $lookup[(string) $image['source_url']] = isset($image['local_filename']) ? (string) $image['local_filename'] : '';
        }

        return $lookup;
    }

    /**
     * Extracts section image metadata.
     *
     * @param DOMElement $node         Image element.
     * @param array      $image_lookup Image source lookup map.
     * @return array|null
     */
    private function extract_section_image($node, $image_lookup) {
        $src = trim((string) $node->getAttribute('src'));
        if ('' === $src) {
            return null;
        }

        $source_url = dbvc_cc_convert_to_absolute_url($src, $this->page_url);
        $local_filename = isset($image_lookup[$source_url]) ? (string) $image_lookup[$source_url] : '';
        if ('' === $local_filename) {
            $local_filename = sanitize_file_name(basename((string) wp_parse_url($source_url, PHP_URL_PATH)));
        }

        return [
            'source_url'     => $source_url,
            'local_filename' => $local_filename,
            'alt'            => $this->normalize_block_text((string) $node->getAttribute('alt')),
        ];
    }

    /**
     * Appends PII signals and applies optional text redaction.
     *
     * @param array  $scraped_data Scraped data array.
     * @param string $html         Source HTML.
     * @return void
     */
    private function annotate_privacy_and_redactions(&$scraped_data, $html) {
        $text_blob = implode("\n", $scraped_data['content']['text_blocks']);

        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text_blob, $email_matches);
        preg_match_all('/(?:\+?1[\s.-]?)?(?:\(?\d{3}\)?[\s.-]?)\d{3}[\s.-]?\d{4}/', $text_blob, $phone_matches);
        preg_match_all('/<form\b/i', $html, $form_matches);

        $emails = array_values(array_unique($email_matches[0]));
        $phones = array_values(array_unique($phone_matches[0]));
        $form_count = is_array($form_matches[0]) ? count($form_matches[0]) : 0;

        $redaction_applied = [
            'emails' => !empty($this->options['redact_emails']),
            'phones' => !empty($this->options['redact_phones']),
            'forms'  => !empty($this->options['redact_forms']),
        ];

        if ($redaction_applied['emails'] || $redaction_applied['phones']) {
            foreach ($scraped_data['content']['text_blocks'] as &$text_block) {
                $text_block = $this->redact_text($text_block, $redaction_applied);
            }
            unset($text_block);

            if (isset($scraped_data['content']['sections']) && is_array($scraped_data['content']['sections'])) {
                foreach ($scraped_data['content']['sections'] as &$section) {
                    if (isset($section['heading']) && is_string($section['heading'])) {
                        $section['heading'] = $this->redact_text($section['heading'], $redaction_applied);
                    }

                    if (isset($section['text_blocks']) && is_array($section['text_blocks'])) {
                        foreach ($section['text_blocks'] as &$section_text) {
                            $section_text = $this->redact_text((string) $section_text, $redaction_applied);
                        }
                        unset($section_text);
                    }

                    if (isset($section['links']) && is_array($section['links'])) {
                        foreach ($section['links'] as &$section_link) {
                            if (isset($section_link['text']) && is_string($section_link['text'])) {
                                $section_link['text'] = $this->redact_text($section_link['text'], $redaction_applied);
                            }
                        }
                        unset($section_link);
                    }

                    if (isset($section['ctas']) && is_array($section['ctas'])) {
                        foreach ($section['ctas'] as &$section_cta) {
                            if (isset($section_cta['text']) && is_string($section_cta['text'])) {
                                $section_cta['text'] = $this->redact_text($section_cta['text'], $redaction_applied);
                            }
                        }
                        unset($section_cta);
                    }
                }
                unset($section);
            }

            $scraped_data['meta']['description'] = $this->redact_text($scraped_data['meta']['description'], $redaction_applied);
        }

        $scraped_data['compliance'] = [
            'pii_flags' => [
                'emails_count'   => count($emails),
                'phones_count'   => count($phones),
                'forms_count'    => $form_count,
                'email_examples' => array_map([$this, 'mask_email'], array_slice($emails, 0, 5)),
                'phone_examples' => array_map([$this, 'mask_phone'], array_slice($phones, 0, 5)),
            ],
            'redaction_rules' => [
                'emails' => $redaction_applied['emails'],
                'phones' => $redaction_applied['phones'],
                'forms'  => $redaction_applied['forms'],
            ],
            'requires_legal_review' => $redaction_applied['forms'] && $form_count > 0,
        ];
    }

    /**
     * Redacts configured PII patterns in a text string.
     *
     * @param string $text               Input text.
     * @param array  $redaction_applied  Toggle map.
     * @return string
     */
    private function redact_text($text, $redaction_applied) {
        if (!is_string($text) || '' === $text) {
            return $text;
        }

        $output = $text;
        if (!empty($redaction_applied['emails'])) {
            $output = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $output);
        }
        if (!empty($redaction_applied['phones'])) {
            $output = preg_replace('/(?:\+?1[\s.-]?)?(?:\(?\d{3}\)?[\s.-]?)\d{3}[\s.-]?\d{4}/', '[redacted-phone]', $output);
        }

        return is_string($output) ? $output : $text;
    }

    /**
     * Masks email for safe logging/output.
     *
     * @param string $email Email string.
     * @return string
     */
    private function mask_email($email) {
        if (!is_string($email) || false === strpos($email, '@')) {
            return '[redacted-email]';
        }

        list($local, $domain) = explode('@', $email, 2);
        if ('' === $local) {
            return '[redacted-email]';
        }

        return substr($local, 0, 1) . '***@' . $domain;
    }

    /**
     * Masks phone for safe logging/output.
     *
     * @param string $phone Phone string.
     * @return string
     */
    private function mask_phone($phone) {
        if (!is_string($phone) || '' === $phone) {
            return '[redacted-phone]';
        }

        $digits = preg_replace('/\D+/', '', $phone);
        if (!is_string($digits) || '' === $digits) {
            return '[redacted-phone]';
        }

        $tail = substr($digits, -2);
        return '***-***-' . $tail;
    }

    /**
     * Builds deep-capture artifacts for Phase 3.6 when enabled.
     *
     * @param DOMXPath $xpath XPath instance.
     * @param array<int, DOMNode> $context_nodes Context nodes.
     * @param array<string, mixed> $scraped_data Scraped payload.
     * @return array<string, array<string, mixed>>
     */
    private function build_phase36_artifacts(DOMXPath $xpath, array $context_nodes, array $scraped_data) {
        $pipeline_id = 'dbvc_cc_p36_' . substr(hash('sha256', $this->page_url . '|' . microtime(true)), 0, 16);

        if (! DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_DEEP_CAPTURE)) {
            $this->dbvc_cc_log_phase36_stage_event(
                'extract',
                'skipped',
                [
                    'pipeline_id' => $pipeline_id,
                    'message' => 'Deep capture feature flag disabled.',
                ]
            );
            return [];
        }

        if (($this->options['capture_mode'] ?? DBVC_CC_Contracts::CAPTURE_MODE_DEEP) !== DBVC_CC_Contracts::CAPTURE_MODE_DEEP) {
            $this->dbvc_cc_log_phase36_stage_event(
                'extract',
                'skipped',
                [
                    'pipeline_id' => $pipeline_id,
                    'message' => 'Capture mode is not deep.',
                ]
            );
            return [];
        }

        if (!class_exists('DBVC_CC_Element_Extractor_Service')) {
            $this->dbvc_cc_log_phase36_stage_event(
                'extract',
                'error',
                [
                    'pipeline_id' => $pipeline_id,
                    'message' => 'Element extractor service is unavailable.',
                ]
            );
            return [];
        }

        try {
            $artifacts = DBVC_CC_Element_Extractor_Service::extract_artifacts($xpath, $context_nodes, $this->page_url, $this->options);
        } catch (Throwable $throwable) {
            $this->dbvc_cc_log_phase36_stage_event(
                'extract',
                'error',
                [
                    'pipeline_id' => $pipeline_id,
                    'failure_code' => 'dbvc_cc_extract_exception',
                    'message' => (string) $throwable->getMessage(),
                ]
            );
            return [];
        }
        $elements_payload = isset($artifacts['elements']) && is_array($artifacts['elements']) ? $artifacts['elements'] : [];
        $scrub_report_payload = isset($artifacts['scrub_report']) && is_array($artifacts['scrub_report']) ? $artifacts['scrub_report'] : [];
        $section_typing_payload = [];
        $context_bundle_payload = [];

        $elements_count = isset($elements_payload['element_count']) ? (int) $elements_payload['element_count'] : 0;
        $extract_processing = isset($elements_payload['processing']) && is_array($elements_payload['processing']) ? $elements_payload['processing'] : [];
        $extract_is_partial = !empty($extract_processing['is_partial']);
        $this->dbvc_cc_log_phase36_stage_event(
            'extract',
            !empty($elements_payload) ? ($extract_is_partial ? 'partial' : 'success') : 'skipped',
            [
                'pipeline_id' => $pipeline_id,
                'element_count' => $elements_count,
                'capture_mode' => isset($elements_payload['capture_mode']) ? (string) $elements_payload['capture_mode'] : DBVC_CC_Contracts::CAPTURE_MODE_DEEP,
                'partial_reason' => isset($extract_processing['partial_reason']) ? (string) $extract_processing['partial_reason'] : '',
                'resume_marker' => isset($extract_processing['resume_marker']) && is_array($extract_processing['resume_marker']) ? $extract_processing['resume_marker'] : [],
            ]
        );
        if (!empty($scrub_report_payload)) {
            $this->dbvc_cc_log_phase36_stage_event(
                'attribute_scrub',
                'success',
                [
                    'pipeline_id' => $pipeline_id,
                    'profile' => isset($scrub_report_payload['profile']) ? (string) $scrub_report_payload['profile'] : DBVC_CC_Contracts::SCRUB_PROFILE_DETERMINISTIC_DEFAULT,
                    'policy_hash' => isset($scrub_report_payload['policy_hash']) ? (string) $scrub_report_payload['policy_hash'] : '',
                    'totals' => isset($scrub_report_payload['totals']) && is_array($scrub_report_payload['totals']) ? $scrub_report_payload['totals'] : [],
                ]
            );
        } else {
            $this->dbvc_cc_log_phase36_stage_event(
                'attribute_scrub',
                'skipped',
                [
                    'pipeline_id' => $pipeline_id,
                    'message' => 'No scrub report was generated.',
                ]
            );
        }

        if (!empty($elements_payload) && class_exists('DBVC_CC_Section_Segmenter_Service')) {
            try {
                $sections_payload = DBVC_CC_Section_Segmenter_Service::build_artifact($elements_payload, $this->page_url, $this->options);
            } catch (Throwable $throwable) {
                $sections_payload = [];
                $this->dbvc_cc_log_phase36_stage_event(
                    'segment',
                    'error',
                    [
                        'pipeline_id' => $pipeline_id,
                        'failure_code' => 'dbvc_cc_segment_exception',
                        'message' => (string) $throwable->getMessage(),
                    ]
                );
            }
            if (!empty($sections_payload)) {
                $artifacts['sections'] = $sections_payload;
            }
            $segment_processing = isset($sections_payload['processing']) && is_array($sections_payload['processing']) ? $sections_payload['processing'] : [];
            $segment_is_partial = !empty($segment_processing['is_partial']);
            $this->dbvc_cc_log_phase36_stage_event(
                'segment',
                !empty($sections_payload) ? ($segment_is_partial ? 'partial' : 'success') : 'skipped',
                [
                    'pipeline_id' => $pipeline_id,
                    'section_count' => isset($sections_payload['section_count']) ? (int) $sections_payload['section_count'] : 0,
                    'partial_reason' => isset($segment_processing['partial_reason']) ? (string) $segment_processing['partial_reason'] : '',
                    'resume_marker' => isset($segment_processing['resume_marker']) && is_array($segment_processing['resume_marker']) ? $segment_processing['resume_marker'] : [],
                ]
            );

            if (!empty($sections_payload) && class_exists('DBVC_CC_Section_Typing_Service')) {
                try {
                    $section_typing_payload = DBVC_CC_Section_Typing_Service::build_artifact(
                        $sections_payload,
                        $elements_payload,
                        $this->options,
                        $this->page_url
                    );
                } catch (Throwable $throwable) {
                    $section_typing_payload = [];
                    $this->dbvc_cc_log_phase36_stage_event(
                        'section_typing',
                        'error',
                        [
                            'pipeline_id' => $pipeline_id,
                            'failure_code' => 'dbvc_cc_section_typing_exception',
                            'message' => (string) $throwable->getMessage(),
                        ]
                    );
                }
                if (!empty($section_typing_payload)) {
                    $artifacts['section_typing'] = $section_typing_payload;
                }
                $this->dbvc_cc_log_phase36_stage_event(
                    'section_typing',
                    !empty($section_typing_payload) ? 'success' : 'skipped',
                    [
                        'pipeline_id' => $pipeline_id,
                        'mode' => isset($section_typing_payload['mode']) ? (string) $section_typing_payload['mode'] : 'fallback',
                        'typing_count' => isset($section_typing_payload['section_typings']) && is_array($section_typing_payload['section_typings']) ? count($section_typing_payload['section_typings']) : 0,
                    ]
                );
            } else {
                $this->dbvc_cc_log_phase36_stage_event(
                    'section_typing',
                    'skipped',
                    [
                        'pipeline_id' => $pipeline_id,
                        'message' => 'Sections artifact missing or section typing service unavailable.',
                    ]
                );
            }

            if (
                !empty($sections_payload)
                && class_exists('DBVC_CC_Context_Bundle_Service')
                && DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_CONTEXT_BUNDLE)
            ) {
                try {
                    $context_bundle_payload = DBVC_CC_Context_Bundle_Service::build_artifact(
                        $scraped_data,
                        $elements_payload,
                        $sections_payload,
                        $scrub_report_payload,
                        $this->page_url
                    );
                } catch (Throwable $throwable) {
                    $context_bundle_payload = [];
                    $this->dbvc_cc_log_phase36_stage_event(
                        'context_bundle',
                        'error',
                        [
                            'pipeline_id' => $pipeline_id,
                            'failure_code' => 'dbvc_cc_context_bundle_exception',
                            'message' => (string) $throwable->getMessage(),
                        ]
                    );
                }
                if (!empty($context_bundle_payload)) {
                    $artifacts['context_bundle'] = $context_bundle_payload;
                }
                $this->dbvc_cc_log_phase36_stage_event(
                    'context_bundle',
                    !empty($context_bundle_payload) ? 'success' : 'skipped',
                    [
                        'pipeline_id' => $pipeline_id,
                        'outline_count' => isset($context_bundle_payload['outline']) && is_array($context_bundle_payload['outline']) ? count($context_bundle_payload['outline']) : 0,
                    ]
                );
            } else {
                $this->dbvc_cc_log_phase36_stage_event(
                    'context_bundle',
                    'skipped',
                    [
                        'pipeline_id' => $pipeline_id,
                        'message' => DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_CONTEXT_BUNDLE)
                            ? 'Sections artifact missing or context bundle service unavailable.'
                            : 'Context bundle feature flag disabled.',
                    ]
                );
            }

            if (!empty($sections_payload) && class_exists('DBVC_CC_Ingestion_Package_Service')) {
                try {
                    $ingestion_package_payload = DBVC_CC_Ingestion_Package_Service::build_artifact(
                        $scraped_data,
                        $elements_payload,
                        $sections_payload,
                        $section_typing_payload,
                        $context_bundle_payload,
                        $this->page_url
                    );
                } catch (Throwable $throwable) {
                    $ingestion_package_payload = [];
                    $this->dbvc_cc_log_phase36_stage_event(
                        'ingestion_package',
                        'error',
                        [
                            'pipeline_id' => $pipeline_id,
                            'failure_code' => 'dbvc_cc_ingestion_package_exception',
                            'message' => (string) $throwable->getMessage(),
                        ]
                    );
                }
                if (!empty($ingestion_package_payload)) {
                    $artifacts['ingestion_package'] = $ingestion_package_payload;
                }
                $this->dbvc_cc_log_phase36_stage_event(
                    'ingestion_package',
                    !empty($ingestion_package_payload) ? 'success' : 'skipped',
                    [
                        'pipeline_id' => $pipeline_id,
                        'section_count' => isset($ingestion_package_payload['stats']['section_count']) ? (int) $ingestion_package_payload['stats']['section_count'] : 0,
                    ]
                );
            } else {
                $this->dbvc_cc_log_phase36_stage_event(
                    'ingestion_package',
                    'skipped',
                    [
                        'pipeline_id' => $pipeline_id,
                        'message' => 'Sections artifact missing or ingestion package service unavailable.',
                    ]
                );
            }
        } else {
            $this->dbvc_cc_log_phase36_stage_event(
                'segment',
                'skipped',
                [
                    'pipeline_id' => $pipeline_id,
                    'message' => 'Elements artifact missing or section segmenter service unavailable.',
                ]
            );
            $this->dbvc_cc_log_phase36_stage_event(
                'section_typing',
                'skipped',
                [
                    'pipeline_id' => $pipeline_id,
                    'message' => 'No sections available for section typing.',
                ]
            );
            $this->dbvc_cc_log_phase36_stage_event(
                'context_bundle',
                'skipped',
                [
                    'pipeline_id' => $pipeline_id,
                    'message' => 'No sections available for context bundling.',
                ]
            );
            $this->dbvc_cc_log_phase36_stage_event(
                'ingestion_package',
                'skipped',
                [
                    'pipeline_id' => $pipeline_id,
                    'message' => 'No sections available for ingestion package.',
                ]
            );
        }

        return $artifacts;
    }

    /**
     * Logs phase3.6 stage events with consistent payload shape.
     *
     * @param string $stage Stage identifier.
     * @param string $status Status value.
     * @param array<string, mixed> $extra Extra event fields.
     * @return void
     */
    private function dbvc_cc_log_phase36_stage_event($stage, $status, array $extra = []) {
        $sanitized_extra = $this->dbvc_cc_sanitize_observability_value($extra);
        if (!is_array($sanitized_extra)) {
            $sanitized_extra = [];
        }
        $payload = array_merge(
            [
                'stage' => sanitize_key((string) $stage),
                'status' => sanitize_key((string) $status),
                'page_url' => $this->page_url,
            ],
            $sanitized_extra
        );

        DBVC_CC_Artifact_Manager::log_event($payload);
    }

    /**
     * Sanitizes event values to avoid leaking secrets into logs.
     *
     * @param mixed $value Event value.
     * @return mixed
     */
    private function dbvc_cc_sanitize_observability_value($value) {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $child_value) {
                $sanitized[$key] = $this->dbvc_cc_sanitize_observability_value($child_value);
            }
            return $sanitized;
        }

        if (!is_string($value)) {
            return $value;
        }

        $message = sanitize_text_field($value);
        if ('' === $message) {
            return '';
        }

        $message = preg_replace('/\bsk-[A-Za-z0-9_-]{16,}\b/', 'sk-[redacted]', $message);
        $message = preg_replace('/\b(Bearer)\s+[A-Za-z0-9._-]{12,}\b/i', '$1 [redacted]', $message);
        $message = preg_replace('/\b(api[_-]?key)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $message);

        return is_string($message) ? $message : '[redacted]';
    }

    /**
     * Writes sidecar Phase 3.6 artifacts for deep capture outputs.
     *
     * @param string $page_dir Storage page directory.
     * @param string $slug Page slug.
     * @param array<string, array<string, mixed>> $phase36_artifacts Sidecar artifacts.
     * @return void
     */
    private function write_phase36_artifacts($page_dir, $slug, array $phase36_artifacts) {
        if (empty($phase36_artifacts)) {
            return;
        }

        $elements_payload = isset($phase36_artifacts['elements']) && is_array($phase36_artifacts['elements']) ? $phase36_artifacts['elements'] : [];
        if (!empty($elements_payload)) {
            $elements_path = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_ELEMENTS_V2_SUFFIX;
            DBVC_CC_Artifact_Manager::write_json_file($elements_path, $elements_payload);
        }

        $scrub_report_payload = isset($phase36_artifacts['scrub_report']) && is_array($phase36_artifacts['scrub_report']) ? $phase36_artifacts['scrub_report'] : [];
        if (!empty($scrub_report_payload)) {
            $scrub_report_path = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_ATTRIBUTE_SCRUB_REPORT_V2_SUFFIX;
            DBVC_CC_Artifact_Manager::write_json_file($scrub_report_path, $scrub_report_payload);
        }

        $sections_payload = isset($phase36_artifacts['sections']) && is_array($phase36_artifacts['sections']) ? $phase36_artifacts['sections'] : [];
        if (!empty($sections_payload)) {
            $sections_path = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_SECTIONS_V2_SUFFIX;
            DBVC_CC_Artifact_Manager::write_json_file($sections_path, $sections_payload);
        }

        $section_typing_payload = isset($phase36_artifacts['section_typing']) && is_array($phase36_artifacts['section_typing']) ? $phase36_artifacts['section_typing'] : [];
        if (!empty($section_typing_payload)) {
            $section_typing_path = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_SECTION_TYPING_V2_SUFFIX;
            DBVC_CC_Artifact_Manager::write_json_file($section_typing_path, $section_typing_payload);
        }

        $context_bundle_payload = isset($phase36_artifacts['context_bundle']) && is_array($phase36_artifacts['context_bundle']) ? $phase36_artifacts['context_bundle'] : [];
        if (!empty($context_bundle_payload)) {
            $context_bundle_path = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_CONTEXT_BUNDLE_V2_SUFFIX;
            DBVC_CC_Artifact_Manager::write_json_file($context_bundle_path, $context_bundle_payload);
        }

        $ingestion_package_payload = isset($phase36_artifacts['ingestion_package']) && is_array($phase36_artifacts['ingestion_package']) ? $phase36_artifacts['ingestion_package'] : [];
        if (!empty($ingestion_package_payload)) {
            $ingestion_package_path = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_INGESTION_PACKAGE_V2_SUFFIX;
            DBVC_CC_Artifact_Manager::write_json_file($ingestion_package_path, $ingestion_package_payload);
        }
    }

    /**
     * Downloads an image to disk.
     *
     * @param string $url         Image URL.
     * @param string $destination Target file path.
     * @return bool
     */
    private function download_image($url, $destination) {
        $args = [
            'timeout'    => $this->options['request_timeout'],
            'user-agent' => $this->options['user_agent'],
            'stream'     => true,
            'filename'   => $destination,
        ];
        $response = wp_remote_get($url, $args);
        return !is_wp_error($response) && 200 == wp_remote_retrieve_response_code($response);
    }

    /**
     * Recursively parses a sitemap.
     *
     * @param string $sitemap_url Sitemap URL.
     * @param array  $all_urls    Aggregated URLs.
     * @return array|WP_Error
     */
    public static function parse_sitemap($sitemap_url, &$all_urls = [], $crawl_overrides = []) {
        $options = self::get_effective_crawl_options($crawl_overrides);
        $args = [
            'timeout'    => $options['request_timeout'],
            'user-agent' => $options['user_agent'],
        ];
        $response = wp_remote_get($sitemap_url, $args);
        if (is_wp_error($response)) {
            return new WP_Error('dbvc_cc_sitemap_fetch_failed', sprintf(__('Failed to fetch sitemap: %s', 'dbvc'), $response->get_error_message()));
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return new WP_Error('dbvc_cc_sitemap_empty', __('Sitemap is empty.', 'dbvc'));
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        if (false === $xml) {
            return new WP_Error('dbvc_cc_sitemap_parsing_failed', __('Failed to parse XML sitemap.', 'dbvc'));
        }

        if (isset($xml->sitemap)) {
            foreach ($xml->sitemap as $sitemap) {
                self::parse_sitemap((string) $sitemap->loc, $all_urls, $crawl_overrides);
            }
        } elseif (isset($xml->url)) {
            foreach ($xml->url as $url) {
                $loc = (string) $url->loc;
                $all_urls[$loc] = $loc;
            }
        }

        return $all_urls;
    }

    /**
     * Returns deterministic slug for current page URL.
     *
     * @return string
     */
    private function get_slug() {
        return DBVC_CC_Artifact_Manager::get_slug_from_url($this->page_url);
    }

    /**
     * Returns deterministic page directory path for current page URL.
     *
     * @return string
     */
    private function get_page_directory_path() {
        return DBVC_CC_Artifact_Manager::get_page_dir($this->page_url);
    }
}
