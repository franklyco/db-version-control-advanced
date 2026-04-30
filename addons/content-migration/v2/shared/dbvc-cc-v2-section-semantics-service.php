<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Section_Semantics_Service
{
    /**
     * @var DBVC_CC_V2_Section_Semantics_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Section_Semantics_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param int                  $index
     * @param string               $label
     * @param array<int, string>   $sample_text
     * @param array<string, mixed> $signals
     * @param string               $fallback
     * @return string
     */
    public function infer_context_tag($index, $label, array $sample_text, array $signals, $fallback = '')
    {
        $haystack = $this->build_haystack($label, $sample_text);
        $label_haystack = strtolower(trim((string) $label));
        if ($this->looks_like_utility_navigation($label, $sample_text, $signals)) {
            return 'utility';
        }

        $fallback = sanitize_key((string) $fallback);
        $heading_level = isset($signals['heading_level']) ? (int) $signals['heading_level'] : 0;
        $has_heading = ! empty($signals['has_heading']) || $heading_level > 0;
        $label_is_contact_like = preg_match(
            '/\b(contact(?: us)?|get in touch|call us|schedule|request (?:a )?(?:quote|consultation|appointment))\b/i',
            $label_haystack
        ) === 1;
        $is_contact_like = preg_match(
            '/\b(contact(?: us)?|get in touch|call us|schedule|book(?: a| your)? call|request (?:a )?(?:quote|consultation|appointment))\b/i',
            $haystack
        ) === 1;

        if ($haystack !== '' && $heading_level === 1 && ! $label_is_contact_like) {
            return 'hero';
        }

        if ((int) $index === 0 && $haystack !== '' && $has_heading && ! $label_is_contact_like && ! $is_contact_like) {
            return 'hero';
        }

        if (preg_match('/\b(faq|frequently asked|questions?)\b/i', $haystack)) {
            return 'faq';
        }

        if (preg_match('/\b(contact|call|phone|email|address|location|get in touch|schedule|quote|request)\b/i', $haystack)) {
            return 'contact';
        }

        if (preg_match('/\b(about|team|story|mission|values|history)\b/i', $haystack)) {
            return 'about';
        }

        if (preg_match('/\b(price|pricing|cost|plan|plans|package|packages)\b/i', $haystack)) {
            return 'product';
        }

        if (preg_match('/\b(service|services|solution|offering|capability|feature|framework)\b/i', $haystack)) {
            return 'services';
        }

        if (in_array($fallback, ['hero', 'contact', 'about', 'services', 'product', 'faq', 'conversion'], true)) {
            return $fallback;
        }

        if (! empty($signals['cta_keyword_hits'])) {
            return 'conversion';
        }

        return $fallback !== '' ? $fallback : 'general';
    }

    /**
     * @param string               $label
     * @param array<int, string>   $sample_text
     * @param array<string, mixed> $signals
     * @return bool
     */
    public function looks_like_utility_navigation($label, array $sample_text, array $signals)
    {
        $haystack = $this->build_haystack($label, $sample_text);
        if ($haystack === '') {
            return false;
        }

        if (
            preg_match(
                '/\b(skip to main content|skip to footer|main menu|site navigation|navigation|breadcrumb)\b/i',
                $haystack
            )
        ) {
            return true;
        }

        $has_heading = ! empty($signals['has_heading']);
        $link_count = isset($signals['link_element_count']) ? (int) $signals['link_element_count'] : 0;
        $text_count = isset($signals['text_element_count']) ? (int) $signals['text_element_count'] : 0;
        $list_density = isset($signals['list_density']) ? (float) $signals['list_density'] : 0.0;

        if (! $has_heading && $link_count >= 12) {
            return true;
        }

        if (! $has_heading && $link_count >= 8 && ($list_density >= 0.12 || $text_count >= 12)) {
            return true;
        }

        return false;
    }

    /**
     * @param string             $label
     * @param array<int, string> $sample_text
     * @return string
     */
    private function build_haystack($label, array $sample_text)
    {
        return strtolower(trim((string) $label . ' ' . implode(' ', array_map('strval', $sample_text))));
    }
}
