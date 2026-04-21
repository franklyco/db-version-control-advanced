<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Run_Profile_Service
{
    /**
     * @var DBVC_CC_V2_Run_Profile_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Run_Profile_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param string               $domain
     * @param array<string, mixed> $profile
     * @return array<string, mixed>|WP_Error
     */
    public function persist_latest_profile($domain, array $profile)
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        $normalized = $this->normalize_profile($context['domain'], $profile);
        if (! DBVC_CC_Artifact_Manager::write_json_file($context['run_profile_file'], $normalized)) {
            return new WP_Error(
                'dbvc_cc_v2_run_profile_write_failed',
                __('Could not write the V2 run request profile artifact.', 'dbvc'),
                ['status' => 500]
            );
        }

        return $normalized;
    }

    /**
     * @param string $domain
     * @return array<string, mixed>|WP_Error
     */
    public function get_latest_profile($domain)
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        if (! file_exists($context['run_profile_file'])) {
            return [];
        }

        $raw = file_get_contents($context['run_profile_file']);
        if (! is_string($raw) || $raw === '') {
            return new WP_Error(
                'dbvc_cc_v2_run_profile_unreadable',
                __('The stored V2 run request profile could not be read.', 'dbvc'),
                ['status' => 500]
            );
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return new WP_Error(
                'dbvc_cc_v2_run_profile_invalid',
                __('The stored V2 run request profile is invalid.', 'dbvc'),
                ['status' => 500]
            );
        }

        return $decoded;
    }

    /**
     * @param string $run_id
     * @return array<string, mixed>|WP_Error
     */
    public function get_profile_for_run($run_id)
    {
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') {
            return [];
        }

        $domain = DBVC_CC_V2_Domain_Journey_Service::get_instance()->find_domain_by_journey_id($run_id);
        if ($domain === '') {
            return [];
        }

        $profile = $this->get_latest_profile($domain);
        if (is_wp_error($profile)) {
            return $profile;
        }

        if (! empty($profile['run_id']) && (string) $profile['run_id'] !== $run_id) {
            return [];
        }

        return $profile;
    }

    /**
     * @param string               $domain
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function normalize_profile($domain, array $profile)
    {
        $run_id = isset($profile['run_id']) ? sanitize_text_field((string) $profile['run_id']) : '';
        $sitemap_url = isset($profile['sitemap_url']) ? esc_url_raw((string) $profile['sitemap_url']) : '';
        $max_urls = isset($profile['max_urls']) ? absint($profile['max_urls']) : 0;
        $force_rebuild = ! empty($profile['force_rebuild']);
        $crawl_overrides = DBVC_CC_Crawler_Service::sanitize_crawl_overrides(
            isset($profile['crawl_overrides']) && is_array($profile['crawl_overrides'])
                ? $profile['crawl_overrides']
                : []
        );

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'run-request-profile.latest.v1',
            'run_id' => $run_id,
            'domain' => sanitize_text_field((string) $domain),
            'stored_at' => current_time('c'),
            'request' => [
                'domain' => sanitize_text_field((string) $domain),
                'sitemap_url' => $sitemap_url,
                'max_urls' => $max_urls,
                'force_rebuild' => $force_rebuild,
                'crawl_overrides' => is_array($crawl_overrides) ? $crawl_overrides : [],
            ],
        ];
    }
}
