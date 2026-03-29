<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Historical_Review_QA_Fixture_Service
{
    /**
     * @var DBVC_CC_V2_Historical_Review_QA_Fixture_Service|null
     */
    private static $instance = null;

    /**
     * @var bool
     */
    private $registered = false;

    /**
     * @return DBVC_CC_V2_Historical_Review_QA_Fixture_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @return bool
     */
    public function is_available()
    {
        $enabled = defined('DBVC_PHPUNIT') && DBVC_PHPUNIT;
        if (! $enabled) {
            $enabled = defined('WP_DEBUG') && WP_DEBUG;
        }

        return (bool) apply_filters('dbvc_cc_v2_enable_recovery_qa_fixture', $enabled);
    }

    /**
     * @return void
     */
    public function register_http_stub_filter()
    {
        if ($this->registered || ! $this->is_available()) {
            return;
        }

        add_filter('pre_http_request', [$this, 'maybe_stub_http_request'], 10, 3);
        $this->registered = true;
    }

    /**
     * @param mixed  $preempt
     * @param mixed  $args
     * @param string $url
     * @return mixed
     */
    public function maybe_stub_http_request($preempt, $args, $url)
    {
        unset($args);

        if (! $this->is_available()) {
            return $preempt;
        }

        $parsed_url = wp_parse_url((string) $url);
        $host = isset($parsed_url['host']) ? strtolower(sanitize_text_field((string) $parsed_url['host'])) : '';
        if ($host !== strtolower(DBVC_CC_V2_Contracts::QA_HISTORICAL_REVIEW_FIXTURE_DOMAIN)) {
            return $preempt;
        }

        $path = isset($parsed_url['path']) ? (string) $parsed_url['path'] : '/';
        $responses = $this->get_stubbed_responses();
        if (! isset($responses[$path])) {
            return $preempt;
        }

        return [
            'headers' => [],
            'body' => $responses[$path],
            'response' => [
                'code' => 200,
                'message' => 'OK',
            ],
            'cookies' => [],
            'filename' => null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function get_stubbed_responses()
    {
        $domain = DBVC_CC_V2_Contracts::QA_HISTORICAL_REVIEW_FIXTURE_DOMAIN;

        return [
            '/sitemap.xml' => '<?xml version="1.0" encoding="UTF-8"?><urlset><url><loc>https://' . $domain . '/about/</loc></url></urlset>',
            '/about/' => '<html><head><title>Historical Review Fixture About</title><meta name="description" content="Historical review fixture description" /></head><body><main><h1>Historical Review Fixture</h1><p>Deterministic historical review QA fixture content.</p></main></body></html>',
        ];
    }
}
