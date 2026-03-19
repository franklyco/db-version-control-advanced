<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Page_Capture_Service
{
    /**
     * @var DBVC_CC_V2_Page_Capture_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Page_Capture_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param string                $journey_id
     * @param array<string, string> $row
     * @param array<string, mixed>  $options
     * @return array<string, mixed>|WP_Error
     */
    public function capture_page($journey_id, array $row, array $options = [])
    {
        $source_url = isset($row['source_url']) ? (string) $row['source_url'] : '';
        $html = $this->fetch_html($source_url, $options);
        if (is_wp_error($html)) {
            return $html;
        }

        return $this->build_page_capture($journey_id, $row, $html, $options);
    }

    /**
     * @param string               $source_url
     * @param array<string, mixed> $options
     * @return string|WP_Error
     */
    private function fetch_html($source_url, array $options)
    {
        $response = wp_remote_get(
            $source_url,
            [
                'timeout' => isset($options['request_timeout']) ? (int) $options['request_timeout'] : 30,
                'user-agent' => isset($options['user_agent']) ? (string) $options['user_agent'] : 'Mozilla/5.0',
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error(
                'dbvc_cc_v2_capture_fetch_failed',
                sprintf(__('Fetch failed for %s: %s', 'dbvc'), $source_url, $response->get_error_message())
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code >= 400) {
            return new WP_Error(
                'dbvc_cc_v2_capture_http_error',
                sprintf(__('Fetch failed for %s with HTTP %d.', 'dbvc'), $source_url, $status_code)
            );
        }

        $body = wp_remote_retrieve_body($response);
        if (! is_string($body) || trim($body) === '') {
            return new WP_Error(
                'dbvc_cc_v2_capture_empty',
                sprintf(__('Page %s returned no crawlable HTML.', 'dbvc'), $source_url)
            );
        }

        return $body;
    }

    /**
     * @param string               $journey_id
     * @param array<string, string> $row
     * @param string               $html
     * @param array<string, mixed> $options
     * @return array<string, mixed>|WP_Error
     */
    private function build_page_capture($journey_id, array $row, $html, array $options)
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        @$doc->loadHTML((string) $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        if (! empty($options['exclude_selectors'])) {
            $exclude_xpath = dbvc_cc_css_to_xpath((string) $options['exclude_selectors']);
            $nodes_to_remove = $xpath->query($exclude_xpath);
            if ($nodes_to_remove instanceof DOMNodeList) {
                foreach ($nodes_to_remove as $node) {
                    if ($node instanceof DOMNode && $node->parentNode instanceof DOMNode) {
                        $node->parentNode->removeChild($node);
                    }
                }
            }
        }

        $context_nodes = [$doc];
        if (! empty($options['focus_selectors'])) {
            $focus_xpath = dbvc_cc_css_to_xpath((string) $options['focus_selectors']);
            $found_nodes = $xpath->query($focus_xpath);
            if ($found_nodes instanceof DOMNodeList && $found_nodes->length > 0) {
                $context_nodes = iterator_to_array($found_nodes);
            }
        }

        $raw_artifact = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'page-artifact.v1',
            'journey_id' => $journey_id,
            'page_id' => isset($row['page_id']) ? (string) $row['page_id'] : '',
            'slug' => isset($row['slug']) ? (string) $row['slug'] : '',
            'source_url' => isset($row['source_url']) ? (string) $row['source_url'] : '',
            'normalized_url' => isset($row['normalized_url']) ? (string) $row['normalized_url'] : '',
            'path' => isset($row['path']) ? (string) $row['path'] : '',
            'captured_at' => current_time('c'),
            'content_hash' => hash('sha256', (string) $html),
            'metadata' => $this->extract_metadata($xpath),
            'headings' => $this->collect_headings($xpath, $context_nodes),
            'text_blocks' => $this->collect_text_blocks($xpath, $context_nodes),
            'links' => $this->collect_links($xpath, $context_nodes, isset($row['source_url']) ? (string) $row['source_url'] : ''),
            'images' => $this->collect_images($xpath, $context_nodes, isset($row['source_url']) ? (string) $row['source_url'] : ''),
            'sections_raw' => [],
        ];

        $raw_artifact['sections_raw'] = $this->build_sections_raw(
            $xpath,
            $context_nodes,
            isset($row['source_url']) ? (string) $row['source_url'] : ''
        );

        return [
            'xpath' => $xpath,
            'context_nodes' => $context_nodes,
            'raw_artifact' => $raw_artifact,
        ];
    }

    /**
     * @param DOMXPath $xpath
     * @return array<string, mixed>
     */
    private function extract_metadata(DOMXPath $xpath)
    {
        $title = '';
        $title_nodes = $xpath->query('//title');
        if ($title_nodes instanceof DOMNodeList && $title_nodes->length > 0) {
            $title = $this->normalize_text($title_nodes->item(0)->textContent);
        }

        $description = '';
        $desc_nodes = $xpath->query("//meta[@name='description']/@content");
        if ($desc_nodes instanceof DOMNodeList && $desc_nodes->length > 0) {
            $description = $this->normalize_text($desc_nodes->item(0)->nodeValue);
        }

        $opengraph = [];
        $og_nodes = $xpath->query("//meta[starts-with(@property, 'og:')]");
        if ($og_nodes instanceof DOMNodeList) {
            foreach ($og_nodes as $node) {
                if (! ($node instanceof DOMElement)) {
                    continue;
                }

                $property = str_replace('og:', '', (string) $node->getAttribute('property'));
                $content = $this->normalize_text($node->getAttribute('content'));
                if ($property !== '' && $content !== '') {
                    $opengraph[$property] = $content;
                }
            }
        }
        ksort($opengraph);

        $schema = [];
        $schema_nodes = $xpath->query("//script[@type='application/ld+json']");
        if ($schema_nodes instanceof DOMNodeList) {
            foreach ($schema_nodes as $node) {
                if (! ($node instanceof DOMElement)) {
                    continue;
                }

                $decoded = json_decode((string) $node->textContent, true);
                if (is_array($decoded)) {
                    $schema[] = $decoded;
                }
            }
        }

        return [
            'title' => $title,
            'description' => $description,
            'opengraph' => $opengraph,
            'schema' => $schema,
        ];
    }

    /**
     * @param DOMXPath $xpath
     * @param array<int, DOMNode> $context_nodes
     * @return array<int, string>
     */
    private function collect_headings(DOMXPath $xpath, array $context_nodes)
    {
        $values = [];
        foreach ($context_nodes as $context_node) {
            $nodes = $xpath->query('.//h1 | .//h2 | .//h3 | .//h4 | .//h5 | .//h6', $context_node);
            if (! ($nodes instanceof DOMNodeList)) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! ($node instanceof DOMNode)) {
                    continue;
                }

                $text = $this->normalize_text($node->textContent);
                if ($text !== '') {
                    $values[$text] = $text;
                }
            }
        }

        return array_values($values);
    }

    /**
     * @param DOMXPath $xpath
     * @param array<int, DOMNode> $context_nodes
     * @return array<int, string>
     */
    private function collect_text_blocks(DOMXPath $xpath, array $context_nodes)
    {
        $values = [];
        foreach ($context_nodes as $context_node) {
            $nodes = $xpath->query('.//p | .//li', $context_node);
            if (! ($nodes instanceof DOMNodeList)) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! ($node instanceof DOMNode)) {
                    continue;
                }

                $text = $this->normalize_text($node->textContent);
                if ($text !== '') {
                    $values[$text] = $text;
                }
            }
        }

        return array_values($values);
    }

    /**
     * @param DOMXPath $xpath
     * @param array<int, DOMNode> $context_nodes
     * @param string $page_url
     * @return array<int, array<string, mixed>>
     */
    private function collect_links(DOMXPath $xpath, array $context_nodes, $page_url)
    {
        $links = [];
        foreach ($context_nodes as $context_node) {
            $nodes = $xpath->query('.//a | .//button', $context_node);
            if (! ($nodes instanceof DOMNodeList)) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! ($node instanceof DOMElement)) {
                    continue;
                }

                $tag = strtolower($node->tagName);
                $text = $this->normalize_text($node->textContent);
                $url = '';
                if ($tag === 'a') {
                    $href = trim((string) $node->getAttribute('href'));
                    if ($href === '' || preg_match('/^\s*javascript:/i', $href)) {
                        continue;
                    }

                    $url = DBVC_CC_V2_URL_Scope_Service::get_instance()->canonicalize_url(
                        dbvc_cc_convert_to_absolute_url($href, $page_url)
                    );
                }

                $is_cta = $tag === 'button'
                    || preg_match('/\b(get started|learn more|contact|book|schedule|call|request|quote|start)\b/i', $text)
                    || preg_match('/\b(btn|button|cta)\b/i', (string) $node->getAttribute('class'));

                $key = $tag . '|' . $text . '|' . $url;
                $links[$key] = [
                    'type' => $tag === 'button' ? 'button' : 'link',
                    'text' => $text,
                    'url' => $url,
                    'is_cta' => (bool) $is_cta,
                ];
            }
        }

        return array_values($links);
    }

    /**
     * @param DOMXPath $xpath
     * @param array<int, DOMNode> $context_nodes
     * @param string $page_url
     * @return array<int, array<string, string>>
     */
    private function collect_images(DOMXPath $xpath, array $context_nodes, $page_url)
    {
        $images = [];
        foreach ($context_nodes as $context_node) {
            $nodes = $xpath->query('.//img', $context_node);
            if (! ($nodes instanceof DOMNodeList)) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! ($node instanceof DOMElement)) {
                    continue;
                }

                $src = trim((string) $node->getAttribute('src'));
                if ($src === '') {
                    continue;
                }

                $url = DBVC_CC_V2_URL_Scope_Service::get_instance()->canonicalize_url(
                    dbvc_cc_convert_to_absolute_url($src, $page_url)
                );
                if ($url === '') {
                    continue;
                }

                $images[$url] = [
                    'source_url' => $url,
                    'alt' => $this->normalize_text($node->getAttribute('alt')),
                ];
            }
        }

        return array_values($images);
    }

    /**
     * @param DOMXPath $xpath
     * @param array<int, DOMNode> $context_nodes
     * @param string $page_url
     * @return array<int, array<string, mixed>>
     */
    private function build_sections_raw(DOMXPath $xpath, array $context_nodes, $page_url)
    {
        $sections = [];
        $current_index = -1;
        $order = 0;

        foreach ($context_nodes as $context_node) {
            $nodes = $xpath->query('.//*', $context_node);
            if (! ($nodes instanceof DOMNodeList)) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! ($node instanceof DOMElement)) {
                    continue;
                }

                $tag = strtolower($node->tagName);
                if (preg_match('/^h([1-6])$/', $tag)) {
                    $text = $this->normalize_text($node->textContent);
                    if ($text === '') {
                        continue;
                    }

                    ++$order;
                    $sections[] = [
                        'id' => 'section-' . $order,
                        'order' => $order,
                        'heading' => $text,
                        'is_intro' => false,
                        'text_blocks' => [],
                        'links' => [],
                        'ctas' => [],
                        'images' => [],
                    ];
                    $current_index = count($sections) - 1;
                    continue;
                }

                if ($current_index < 0) {
                    ++$order;
                    $sections[] = [
                        'id' => 'section-' . $order,
                        'order' => $order,
                        'heading' => '',
                        'is_intro' => true,
                        'text_blocks' => [],
                        'links' => [],
                        'ctas' => [],
                        'images' => [],
                    ];
                    $current_index = count($sections) - 1;
                }

                if (in_array($tag, ['p', 'li'], true)) {
                    $text = $this->normalize_text($node->textContent);
                    if ($text !== '') {
                        $sections[$current_index]['text_blocks'][$text] = $text;
                    }
                } elseif (in_array($tag, ['a', 'button'], true)) {
                    $text = $this->normalize_text($node->textContent);
                    $href = $tag === 'a' ? trim((string) $node->getAttribute('href')) : '';
                    $url = $href !== '' ? dbvc_cc_convert_to_absolute_url($href, $page_url) : '';
                    $is_cta = $tag === 'button'
                        || preg_match('/\b(get started|learn more|contact|book|schedule|call|request|quote|start)\b/i', $text);
                    $payload = [
                        'type' => $tag === 'button' ? 'button' : 'link',
                        'text' => $text,
                        'url' => $tag === 'a' ? DBVC_CC_V2_URL_Scope_Service::get_instance()->canonicalize_url($url) : '',
                    ];
                    $key = $payload['type'] . '|' . $payload['text'] . '|' . $payload['url'];
                    if ($is_cta) {
                        $sections[$current_index]['ctas'][$key] = $payload;
                    } else {
                        $sections[$current_index]['links'][$key] = $payload;
                    }
                } elseif ($tag === 'img') {
                    $src = trim((string) $node->getAttribute('src'));
                    $image_url = $src !== ''
                        ? DBVC_CC_V2_URL_Scope_Service::get_instance()->canonicalize_url(dbvc_cc_convert_to_absolute_url($src, $page_url))
                        : '';
                    if ($image_url !== '') {
                        $sections[$current_index]['images'][$image_url] = [
                            'source_url' => $image_url,
                            'alt' => $this->normalize_text($node->getAttribute('alt')),
                        ];
                    }
                }
            }
        }

        foreach ($sections as &$section) {
            $section['text_blocks'] = array_values($section['text_blocks']);
            $section['links'] = array_values($section['links']);
            $section['ctas'] = array_values($section['ctas']);
            $section['images'] = array_values($section['images']);
        }
        unset($section);

        return $sections;
    }

    /**
     * @param string $value
     * @return string
     */
    private function normalize_text($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', $value);
        return is_string($value) ? $value : '';
    }
}
