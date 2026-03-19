<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_URL_Scope_Service
{
    /**
     * @var DBVC_CC_V2_URL_Scope_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_URL_Scope_Service
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
     * @param string $source_url
     * @return array<string, string>
     */
    public function evaluate_url($domain, $source_url)
    {
        $source_url = esc_url_raw((string) $source_url);
        $domain_key = $this->normalize_domain($domain);
        $normalized_url = $this->canonicalize_url($source_url);

        $record = [
            'page_id' => '',
            'source_url' => $source_url,
            'normalized_url' => $normalized_url,
            'path' => '',
            'slug' => '',
            'discovery_status' => 'discovered',
            'discovery_reason' => 'sitemap',
            'scope_status' => 'eligible',
            'scope_reason' => 'same_domain_html_candidate',
        ];

        if ($normalized_url === '') {
            $record['discovery_status'] = 'invalid';
            $record['discovery_reason'] = 'invalid_url';
            $record['scope_status'] = 'out_of_scope';
            $record['scope_reason'] = 'invalid_url';

            return $record;
        }

        $host = $this->normalize_domain((string) wp_parse_url($normalized_url, PHP_URL_HOST));
        if ($domain_key === '' || $host !== $domain_key) {
            $record['scope_status'] = 'out_of_scope';
            $record['scope_reason'] = 'external_domain';
        }

        $path = $this->normalize_path($normalized_url);
        $slug = DBVC_CC_Artifact_Manager::get_slug_from_url($normalized_url);

        if ($this->is_non_html_asset($path)) {
            $record['scope_status'] = 'out_of_scope';
            $record['scope_reason'] = 'non_html_asset';
        } elseif ($this->is_utility_path($path)) {
            $record['scope_status'] = 'out_of_scope';
            $record['scope_reason'] = 'utility_path';
        }

        $record['page_id'] = $this->build_page_id($normalized_url);
        $record['path'] = $path;
        $record['slug'] = $slug;

        return $record;
    }

    /**
     * @param string $url
     * @return string
     */
    public function build_page_id($url)
    {
        $normalized_url = $this->canonicalize_url($url);
        if ($normalized_url === '') {
            return '';
        }

        return 'pg_' . substr(hash('sha256', $normalized_url), 0, 12);
    }

    /**
     * @param string $value
     * @return string
     */
    public function normalize_domain($value)
    {
        $value = strtolower((string) $value);
        $value = preg_replace('/^https?:\/\//', '', $value);
        $value = preg_replace('/^www\./', '', $value);
        $value = preg_replace('/[^a-z0-9.-]/', '', $value);

        return is_string($value) ? $value : '';
    }

    /**
     * @param string $url
     * @return string
     */
    public function canonicalize_url($url)
    {
        $url = esc_url_raw((string) $url);
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'https';
        if (! in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        $host = $this->normalize_domain((string) $parts['host']);
        if ($host === '') {
            return '';
        }

        $path = isset($parts['path']) ? (string) $parts['path'] : '/';
        $path = preg_replace('#/+#', '/', $path);
        $path = is_string($path) ? $path : '/';
        if ($path === '') {
            $path = '/';
        }
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $scheme . '://' . $host . $path;
    }

    /**
     * @param string $url
     * @return string
     */
    public function normalize_path($url)
    {
        $path = wp_parse_url((string) $url, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $path = preg_replace('#/+#', '/', $path);
        $path = is_string($path) ? $path : '/';

        if ($path === '') {
            return '/';
        }

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * @param string $path
     * @return bool
     */
    private function is_non_html_asset($path)
    {
        return (bool) preg_match('/\.(?:xml|json|rss|atom|jpg|jpeg|png|webp|gif|svg|pdf|zip|css|js|txt|ico|mp4|mov|mp3)$/i', (string) $path);
    }

    /**
     * @param string $path
     * @return bool
     */
    private function is_utility_path($path)
    {
        return (bool) preg_match('#^/(?:wp-admin|wp-json|wp-content|wp-includes|xmlrpc\.php|feed|search)(?:/|$)#i', (string) $path);
    }
}
