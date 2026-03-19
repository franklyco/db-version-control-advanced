<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_URL_Inventory_Service
{
    /**
     * @var DBVC_CC_V2_URL_Inventory_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_URL_Inventory_Service
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
     * @param string               $journey_id
     * @param string               $sitemap_url
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function build_inventory($domain, $journey_id, $sitemap_url, array $args = [])
    {
        $journey = DBVC_CC_V2_Domain_Journey_Service::get_instance();
        $context = $journey->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        $journey_id = sanitize_text_field((string) $journey_id);
        $sitemap_url = esc_url_raw((string) $sitemap_url);
        if ($journey_id === '' || $sitemap_url === '') {
            return new WP_Error(
                'dbvc_cc_v2_inventory_request_invalid',
                __('Journey ID and sitemap URL are required to build URL inventory.', 'dbvc'),
                ['status' => 400]
            );
        }

        $crawl_overrides = isset($args['crawl_overrides']) && is_array($args['crawl_overrides']) ? $args['crawl_overrides'] : [];
        $all_urls = [];
        $urls = DBVC_CC_Crawler_Service::parse_sitemap($sitemap_url, $all_urls, $crawl_overrides);
        if (is_wp_error($urls)) {
            return $urls;
        }

        $scope_service = DBVC_CC_V2_URL_Scope_Service::get_instance();
        $inventory_rows = [];
        $seen_urls = [];
        $stats = [
            'raw_url_count' => 0,
            'url_count' => 0,
            'eligible_count' => 0,
            'out_of_scope_count' => 0,
            'duplicate_count' => 0,
            'invalid_count' => 0,
        ];

        foreach ($urls as $url) {
            ++$stats['raw_url_count'];
            $entry = $scope_service->evaluate_url($context['domain'], (string) $url);
            if ($entry['normalized_url'] === '') {
                ++$stats['invalid_count'];
                continue;
            }

            if (isset($seen_urls[$entry['normalized_url']])) {
                ++$stats['duplicate_count'];
                continue;
            }
            $seen_urls[$entry['normalized_url']] = true;

            if ($entry['scope_status'] === 'eligible') {
                ++$stats['eligible_count'];
            } else {
                ++$stats['out_of_scope_count'];
            }

            $inventory_rows[] = $entry;
        }

        $stats['url_count'] = count($inventory_rows);

        $inventory = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'domain-url-inventory.v1',
            'journey_id' => $journey_id,
            'domain' => $context['domain'],
            'generated_at' => current_time('c'),
            'source' => [
                'type' => 'sitemap',
                'sitemap_url' => $sitemap_url,
            ],
            'urls' => $inventory_rows,
            'stats' => $stats,
        ];

        if (! DBVC_CC_Artifact_Manager::write_json_file($context['url_inventory_file'], $inventory)) {
            return new WP_Error(
                'dbvc_cc_v2_url_inventory_write_failed',
                __('Could not write the V2 URL inventory artifact.', 'dbvc'),
                ['status' => 500]
            );
        }

        $relative_path = $this->get_domain_relative_path($context['url_inventory_file'], $context['domain_dir']);
        foreach ($inventory_rows as $entry) {
            $journey->append_event(
                $context['domain'],
                [
                    'journey_id' => $journey_id,
                    'step_key' => DBVC_CC_V2_Contracts::STEP_URL_DISCOVERED,
                    'step_name' => 'URL discovered',
                    'status' => 'completed',
                    'page_id' => $entry['page_id'],
                    'path' => $entry['path'],
                    'source_url' => $entry['source_url'],
                    'message' => 'URL discovered during sitemap expansion.',
                    'metadata' => [
                        'discovery_status' => $entry['discovery_status'],
                        'discovery_reason' => $entry['discovery_reason'],
                    ],
                ]
            );

            $journey->append_event(
                $context['domain'],
                [
                    'journey_id' => $journey_id,
                    'step_key' => DBVC_CC_V2_Contracts::STEP_URL_SCOPE_DECIDED,
                    'step_name' => 'URL scope decided',
                    'status' => $entry['scope_status'] === 'eligible' ? 'completed' : 'skipped',
                    'page_id' => $entry['page_id'],
                    'path' => $entry['path'],
                    'source_url' => $entry['source_url'],
                    'message' => 'URL eligibility and migration scope were evaluated.',
                    'metadata' => [
                        'scope_status' => $entry['scope_status'],
                        'scope_reason' => $entry['scope_reason'],
                    ],
                ]
            );
        }

        $journey->append_event(
            $context['domain'],
            [
                'journey_id' => $journey_id,
                'step_key' => DBVC_CC_V2_Contracts::STEP_URL_DISCOVERY_COMPLETED,
                'step_name' => 'URL discovery completed',
                'status' => 'completed',
                'output_artifacts' => [$relative_path],
                'source_fingerprint' => hash('sha256', (string) wp_json_encode($inventory_rows, JSON_UNESCAPED_SLASHES)),
                'message' => 'URL inventory materialized from sitemap input.',
                'metadata' => $stats,
            ]
        );

        return [
            'domain' => $context['domain'],
            'journey_id' => $journey_id,
            'inventory_file' => $context['url_inventory_file'],
            'artifact_relative_path' => $relative_path,
            'inventory' => $inventory,
        ];
    }

    /**
     * @param string $domain
     * @return array<string, mixed>|WP_Error
     */
    public function get_inventory($domain)
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        $raw = @file_get_contents($context['url_inventory_file']);
        if (! is_string($raw) || $raw === '') {
            return new WP_Error(
                'dbvc_cc_v2_url_inventory_missing',
                __('The V2 URL inventory has not been built for this domain.', 'dbvc'),
                ['status' => 404]
            );
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return new WP_Error(
                'dbvc_cc_v2_url_inventory_invalid',
                __('The V2 URL inventory payload is invalid.', 'dbvc'),
                ['status' => 500]
            );
        }

        return $decoded;
    }

    /**
     * @param string $path
     * @param string $domain_dir
     * @return string
     */
    private function get_domain_relative_path($path, $domain_dir)
    {
        return ltrim(str_replace(wp_normalize_path((string) $domain_dir), '', wp_normalize_path((string) $path)), '/');
    }
}
