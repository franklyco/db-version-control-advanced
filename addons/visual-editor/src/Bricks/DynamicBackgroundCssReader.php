<?php

namespace Dbvc\VisualEditor\Bricks;

/**
 * Reads the background images Bricks actually emitted for dynamic-data backgrounds.
 *
 * Bricks never renders a dynamic background as an inline `style` attribute. It resolves the
 * tag and appends a rule to `\Bricks\Assets::$inline_css_dynamic_data`, e.g.
 * `#brxe-abc123 {background-image: url("...")}` for an element setting or
 * `.hero.brxe-section {background-image: none}` for a global class. This reader is the
 * render-truth source for verifying `background_image` descriptors. Only base (non-@media)
 * rules are considered; the last matching rule wins, mirroring the CSS cascade for
 * equal-specificity selectors.
 */
final class DynamicBackgroundCssReader
{
    /**
     * @var callable|null
     */
    private $css_provider;

    /**
     * @var string
     */
    private $parsed_css = '';

    /**
     * @var array<int, array{selectors: array<int, string>, url: string, offset: int}>
     */
    private $rules = [];

    /**
     * @param callable|null $css_provider Returns the emitted dynamic-data CSS. Defaults to Bricks' buffer.
     */
    public function __construct(?callable $css_provider = null)
    {
        $this->css_provider = $css_provider;
    }

    /**
     * Last base-breakpoint background-image rule matching any of the selectors.
     *
     * @param array<int, string> $selectors Exact selectors, e.g. `.hero.brxe-section`.
     * @return array{url: string, offset: int}|null `url` is '' when Bricks emitted `none`; null when no rule exists.
     */
    public function findBackgroundImage(array $selectors)
    {
        $selectors = array_values(array_filter(array_map('trim', $selectors), 'strlen'));
        if (empty($selectors)) {
            return null;
        }

        $match = null;
        foreach ($this->rules() as $rule) {
            if (! array_intersect($selectors, $rule['selectors'])) {
                continue;
            }

            $match = [
                'url' => $rule['url'],
                'offset' => $rule['offset'],
            ];
        }

        return $match;
    }

    /**
     * @return array<int, array{selectors: array<int, string>, url: string, offset: int}>
     */
    private function rules()
    {
        $css = $this->readCss();
        if ($css === $this->parsed_css) {
            return $this->rules;
        }

        $this->parsed_css = $css;
        $this->rules = [];

        if ($css === '' || stripos($css, 'background-image') === false) {
            return $this->rules;
        }

        $base_css = $this->stripAtRules($css);
        if (! preg_match_all('/([^{}]+)\{([^{}]*)\}/', $base_css, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $this->rules;
        }

        foreach ($matches as $match) {
            $declarations = (string) $match[2][0];
            if (! preg_match('/background-image\s*:\s*(none|url\(\s*(["\']?)(.*?)\2\s*\))/i', $declarations, $value)) {
                continue;
            }

            $selector_text = preg_replace('#/\*.*?\*/#s', '', (string) $match[1][0]);
            $selectors = array_values(array_filter(array_map('trim', explode(',', (string) $selector_text)), 'strlen'));
            if (empty($selectors)) {
                continue;
            }

            $this->rules[] = [
                'selectors' => $selectors,
                'url' => strtolower($value[1]) === 'none' ? '' : html_entity_decode((string) $value[3], ENT_QUOTES, 'UTF-8'),
                'offset' => (int) $match[0][1],
            ];
        }

        return $this->rules;
    }

    /**
     * Remove `@media`/`@supports`/`@container` blocks so only base rules remain.
     *
     * @param string $css
     * @return string
     */
    private function stripAtRules($css)
    {
        $result = '';
        $length = strlen($css);
        $index = 0;

        while ($index < $length) {
            $at = strpos($css, '@', $index);
            if ($at === false) {
                $result .= substr($css, $index);
                break;
            }

            $result .= substr($css, $index, $at - $index);
            $open = strpos($css, '{', $at);
            $semicolon = strpos($css, ';', $at);
            if ($open === false || ($semicolon !== false && $semicolon < $open)) {
                // Statement at-rule (e.g. @import) or malformed tail: skip the statement.
                $index = $semicolon === false ? $length : $semicolon + 1;
                continue;
            }

            $depth = 0;
            for ($cursor = $open; $cursor < $length; $cursor++) {
                if ($css[$cursor] === '{') {
                    $depth++;
                } elseif ($css[$cursor] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }

            // Keep a placeholder so later rule offsets stay ordered after this block.
            $result .= str_repeat(' ', min($cursor + 1, $length) - $at);
            $index = $cursor + 1;
        }

        return $result;
    }

    /**
     * @return string
     */
    private function readCss()
    {
        if (is_callable($this->css_provider)) {
            $css = call_user_func($this->css_provider);

            return is_string($css) ? $css : '';
        }

        if (class_exists('\\Bricks\\Assets') && isset(\Bricks\Assets::$inline_css_dynamic_data) && is_string(\Bricks\Assets::$inline_css_dynamic_data)) {
            return \Bricks\Assets::$inline_css_dynamic_data;
        }

        return '';
    }
}
