<?php

namespace Dbvc\VisualEditor\Bricks;

final class DynamicDataInspector
{
    /**
     * @param object $element
     * @param string $attribute_key
     * @return array<string, mixed>
     */
    public function inspectForAttribute($element, $attribute_key)
    {
        $attribute_key = sanitize_key((string) $attribute_key);
        $settings = isset($element->settings) && is_array($element->settings) ? $element->settings : [];

        $collection_candidate = $this->inspectCollectionLinkSettings($settings, $attribute_key);
        if (! empty($collection_candidate['supported'])) {
            return $collection_candidate;
        }

        return $this->inspect($element);
    }

    /**
     * @param object $element
     * @return array<string, mixed>
     */
    public function inspect($element)
    {
        $settings = isset($element->settings) && is_array($element->settings) ? $element->settings : [];

        foreach (['text', 'title', 'content'] as $setting_key) {
            $value = isset($settings[$setting_key]) ? $settings[$setting_key] : null;
            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);
            $candidate = $this->inspectExpression($value, $setting_key);
            if (! empty($candidate['supported'])) {
                return $candidate;
            }

            $wrapped_expression = $this->extractWrappedSingleExpression($value);
            if ($wrapped_expression !== '') {
                $candidate = $this->inspectExpression($wrapped_expression, $setting_key);
                if (! empty($candidate['supported'])) {
                    return $candidate;
                }
            }

            $embedded_expression = $this->extractSingleEmbeddedExpression($value);
            if ($embedded_expression !== '') {
                $candidate = $this->inspectExpression($embedded_expression, $setting_key);
                if (! empty($candidate['supported'])) {
                    $candidate['text_projection'] = 'single_embedded';
                    $candidate['text_template'] = $value;
                    $candidate['text_expression'] = $embedded_expression;

                    return $candidate;
                }
            }

            $composite_candidate = $this->inspectCompositeTextTemplate($value, $setting_key);
            if (! empty($composite_candidate['supported'])) {
                return $composite_candidate;
            }
        }

        $link_candidate = $this->inspectLinkSettings($settings);
        if (! empty($link_candidate['supported'])) {
            return $link_candidate;
        }

        $image_candidate = $this->inspectImageSettings($settings);
        if (! empty($image_candidate['supported'])) {
            return $image_candidate;
        }

        return [
            'supported' => false,
        ];
    }

    /**
     * @param string $value
     * @return string
     */
    private function extractWrappedSingleExpression($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (! preg_match('/^<([A-Za-z0-9:_-]+)(?:\s[^>]*)?>\s*(\{[^{}]+\})\s*<\/\1>$/s', $value, $matches)) {
            return '';
        }

        return isset($matches[2]) ? trim((string) $matches[2]) : '';
    }

    /**
     * @param string $value
     * @return string
     */
    private function extractSingleEmbeddedExpression($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (! preg_match_all('/\{[^{}]+\}/', $value, $matches)) {
            return '';
        }

        $expressions = isset($matches[0]) && is_array($matches[0]) ? array_values($matches[0]) : [];
        if (count($expressions) !== 1) {
            return '';
        }

        $expression = trim((string) $expressions[0]);
        if ($expression === '' || $expression === $value) {
            return '';
        }

        return $expression;
    }

    /**
     * @param string $value
     * @param string $setting_key
     * @return array<string, mixed>
     */
    private function inspectCompositeTextTemplate($value, $setting_key)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return [
                'supported' => false,
            ];
        }

        if (! preg_match_all('/\{[^{}]+\}/', $value, $matches, PREG_OFFSET_CAPTURE)) {
            return [
                'supported' => false,
            ];
        }

        $expressions = isset($matches[0]) && is_array($matches[0]) ? array_values($matches[0]) : [];
        if (count($expressions) < 2) {
            return [
                'supported' => false,
            ];
        }

        $segments = [];
        $children = [];
        $supported_children = 0;
        $cursor = 0;
        $dynamic_index = 0;

        foreach ($expressions as $match) {
            if (! is_array($match) || count($match) < 2) {
                continue;
            }

            $expression = trim((string) $match[0]);
            $offset = max(0, (int) $match[1]);
            $length = strlen((string) $match[0]);

            if ($offset > $cursor) {
                $literal = substr($value, $cursor, $offset - $cursor);
                if ($literal !== '') {
                    $segments[] = [
                        'type' => 'literal',
                        'text' => $literal,
                    ];
                }
            }

            $candidate = $this->inspectExpression($expression, $setting_key);
            $is_supported = ! empty($candidate['supported']);
            if ($is_supported) {
                $supported_children++;
            }

            $child = [
                'index' => $dynamic_index,
                'expression' => $expression,
                'supported' => $is_supported,
            ];

            if ($is_supported) {
                $child['candidate'] = $candidate;
            }

            $children[] = $child;
            $segments[] = [
                'type' => 'dynamic',
                'index' => $dynamic_index,
                'expression' => $expression,
                'supported' => $is_supported,
            ];

            $cursor = $offset + $length;
            $dynamic_index++;
        }

        if ($cursor < strlen($value)) {
            $literal = substr($value, $cursor);
            if ($literal !== '') {
                $segments[] = [
                    'type' => 'literal',
                    'text' => $literal,
                ];
            }
        }

        if ($supported_children < 1) {
            return [
                'supported' => false,
            ];
        }

        return [
            'supported' => true,
            'setting_key' => $setting_key,
            'source_type' => 'composite_text',
            'expression' => 'composite_text:' . md5($value),
            'template' => $value,
            'segments' => $segments,
            'children' => $children,
            'dynamic_count' => count($children),
            'supported_child_count' => $supported_children,
            'unsupported_child_count' => max(0, count($children) - $supported_children),
            'render_context' => 'composite_text',
            'render_attribute' => '',
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param string               $attribute_key
     * @return array<string, mixed>
     */
    private function inspectCollectionLinkSettings(array $settings, $attribute_key)
    {
        if (! preg_match('/^a-(\d+)$/', $attribute_key, $matches)) {
            return [
                'supported' => false,
            ];
        }

        $index = absint($matches[1]);

        $list_items = isset($settings['items']) && is_array($settings['items']) ? $settings['items'] : [];
        if (isset($list_items[$index]) && is_array($list_items[$index])) {
            $link_settings = isset($list_items[$index]['link']) && is_array($list_items[$index]['link']) ? $list_items[$index]['link'] : [];
            $candidate = $this->inspectLinkControlArray($link_settings, 'items.' . $index . '.link');
            if (! empty($candidate['supported'])) {
                return $candidate;
            }
        }

        $social_icons = isset($settings['icons']) && is_array($settings['icons']) ? $settings['icons'] : [];
        if (isset($social_icons[$index]) && is_array($social_icons[$index])) {
            $link_settings = isset($social_icons[$index]['link']) && is_array($social_icons[$index]['link']) ? $social_icons[$index]['link'] : [];
            $candidate = $this->inspectLinkControlArray($link_settings, 'icons.' . $index . '.link');
            if (! empty($candidate['supported'])) {
                return $candidate;
            }
        }

        $gallery_mode = isset($settings['linkTo']) ? sanitize_key((string) $settings['linkTo']) : '';
        $gallery_links = isset($settings['linkCustom']) && is_array($settings['linkCustom']) ? $settings['linkCustom'] : [];
        if ($gallery_mode === 'custom' && isset($gallery_links[$index]) && is_array($gallery_links[$index])) {
            $link_settings = isset($gallery_links[$index]['link']) && is_array($gallery_links[$index]['link']) ? $gallery_links[$index]['link'] : [];
            $candidate = $this->inspectLinkControlArray($link_settings, 'linkCustom.' . $index . '.link');
            if (! empty($candidate['supported'])) {
                return $candidate;
            }
        }

        return [
            'supported' => false,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function inspectLinkSettings(array $settings)
    {
        $top_level_link = isset($settings['link']) && is_array($settings['link']) ? $settings['link'] : [];
        $candidate = $this->inspectLinkControlArray($top_level_link, 'link');
        if (! empty($candidate['supported'])) {
            return $candidate;
        }

        $image_link_mode = isset($settings['link']) && is_scalar($settings['link'])
            ? sanitize_key((string) $settings['link'])
            : '';
        $image_url_link = isset($settings['url']) && is_array($settings['url']) ? $settings['url'] : [];
        if ($image_link_mode === 'url') {
            $candidate = $this->inspectLinkControlArray($image_url_link, 'url');
            if (! empty($candidate['supported'])) {
                return $candidate;
            }
        }

        return [
            'supported' => false,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function inspectImageSettings(array $settings)
    {
        $image_settings = isset($settings['image']) && is_array($settings['image']) ? $settings['image'] : [];
        $dynamic_image = isset($image_settings['useDynamicData']) && is_scalar($image_settings['useDynamicData'])
            ? trim((string) $image_settings['useDynamicData'])
            : '';

        if ($dynamic_image !== '') {
            $candidate = $this->inspectExpression($dynamic_image, 'image');
            if ($this->supportsDirectImageSource($candidate)) {
                $candidate['render_context'] = 'image_src';
                $candidate['render_attribute'] = 'src';
                $candidate['media_size'] = isset($image_settings['size']) ? sanitize_key((string) $image_settings['size']) : '';

                return $candidate;
            }
        }

        $background_settings = isset($settings['_background']) && is_array($settings['_background'])
            ? $settings['_background']
            : (isset($settings['background']) && is_array($settings['background']) ? $settings['background'] : []);
        $background_image_settings = isset($background_settings['image']) && is_array($background_settings['image']) ? $background_settings['image'] : [];
        $dynamic_background_image = isset($background_image_settings['useDynamicData']) && is_scalar($background_image_settings['useDynamicData'])
            ? trim((string) $background_image_settings['useDynamicData'])
            : '';

        if ($dynamic_background_image !== '') {
            $candidate = $this->inspectExpression($dynamic_background_image, '_background.image');
            if ($this->supportsDirectImageSource($candidate)) {
                $candidate['render_context'] = 'background_image';
                $candidate['render_attribute'] = 'style';
                $candidate['media_size'] = isset($background_image_settings['size']) ? sanitize_key((string) $background_image_settings['size']) : '';

                return $candidate;
            }
        }

        $gallery_items = isset($settings['items']) && is_array($settings['items']) ? $settings['items'] : [];
        $dynamic_gallery = isset($gallery_items['useDynamicData']) && is_scalar($gallery_items['useDynamicData'])
            ? trim((string) $gallery_items['useDynamicData'])
            : '';

        if ($dynamic_gallery !== '') {
            $candidate = $this->inspectExpression($dynamic_gallery, 'items');
            if (! empty($candidate['supported']) && ($candidate['source_type'] ?? '') === 'acf_field') {
                $candidate['render_context'] = 'gallery_collection';
                $candidate['render_attribute'] = 'src';
                $candidate['media_size'] = isset($gallery_items['size']) ? sanitize_key((string) $gallery_items['size']) : '';

                return $candidate;
            }
        }

        return [
            'supported' => false,
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @return bool
     */
    private function supportsDirectImageSource(array $candidate)
    {
        if (empty($candidate['supported'])) {
            return false;
        }

        $source_type = isset($candidate['source_type']) ? (string) $candidate['source_type'] : '';
        if ($source_type === 'acf_field') {
            return true;
        }

        return $source_type === 'post_field'
            && isset($candidate['field_name'])
            && sanitize_key((string) $candidate['field_name']) === 'featured_image';
    }

    /**
     * @param array<string, mixed> $link_settings
     * @param string               $setting_key
     * @return array<string, mixed>
     */
    private function inspectLinkControlArray(array $link_settings, $setting_key)
    {
        $link_type = isset($link_settings['type']) ? sanitize_key((string) $link_settings['type']) : '';
        $url = isset($link_settings['url']) && is_string($link_settings['url']) ? trim($link_settings['url']) : '';

        if ($link_type !== 'external' || $url === '') {
            return [
                'supported' => false,
            ];
        }

        $candidate = $this->inspectExpression($url, $setting_key);
        if (! $this->supportsDirectLinkSource($candidate)) {
            return [
                'supported' => false,
            ];
        }

        $candidate['render_context'] = 'link_href';
        $candidate['render_attribute'] = 'href';

        return $candidate;
    }

    /**
     * @param string $expression
     * @param string $setting_key
     * @return array<string, mixed>
     */
    private function inspectExpression($expression, $setting_key)
    {
        $expression = trim((string) $expression);
        if (! preg_match('/^\{([^{}]+)\}$/', $expression, $matches)) {
            return [
                'supported' => false,
            ];
        }

        $inner = trim((string) $matches[1]);
        if ($inner === '') {
            return [
                'supported' => false,
            ];
        }

        $parts = explode(':', $inner);
        $tag = isset($parts[0]) ? sanitize_key((string) $parts[0]) : '';
        $args = array_values(
            array_filter(
                array_map(
                    static function ($part) {
                        return sanitize_text_field((string) $part);
                    },
                    array_slice($parts, 1)
                ),
                static function ($part) {
                    return $part !== '';
                }
            )
        );

        if (strpos($tag, 'acf_') === 0) {
            $field_name = sanitize_key(substr($tag, 4));
            if ($field_name === '') {
                return [
                    'supported' => false,
                ];
            }

            return [
                'supported' => true,
                'setting_key' => $setting_key,
                'source_type' => 'acf_field',
                'expression' => $expression,
                'field_name' => $field_name,
                'tag' => $tag,
                'args' => $args,
            ];
        }

        if (in_array($tag, ['post_title', 'post_excerpt', 'featured_image', 'post_url'], true)) {
            return [
                'supported' => true,
                'setting_key' => $setting_key,
                'source_type' => 'post_field',
                'expression' => $expression,
                'field_name' => $tag,
                'tag' => $tag,
                'args' => $args,
            ];
        }

        if (in_array($tag, ['term_name', 'term_description', 'term_url', 'term_id'], true)) {
            return [
                'supported' => true,
                'setting_key' => $setting_key,
                'source_type' => 'term_field',
                'expression' => $expression,
                'field_name' => $tag,
                'tag' => $tag,
                'args' => $args,
            ];
        }

        if ($tag === 'archive_title') {
            return [
                'supported' => true,
                'setting_key' => $setting_key,
                'source_type' => 'archive_field',
                'expression' => $expression,
                'field_name' => $tag,
                'tag' => $tag,
                'args' => $args,
            ];
        }

        return [
            'supported' => false,
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @return bool
     */
    private function supportsDirectLinkSource(array $candidate)
    {
        if (empty($candidate['supported'])) {
            return false;
        }

        $source_type = isset($candidate['source_type']) ? (string) $candidate['source_type'] : '';
        if ($source_type === 'acf_field') {
            return true;
        }

        $field_name = isset($candidate['field_name']) ? sanitize_key((string) $candidate['field_name']) : '';

        return ($source_type === 'post_field' && $field_name === 'post_url')
            || ($source_type === 'term_field' && $field_name === 'term_url');
    }
}
