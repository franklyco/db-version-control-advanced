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
     * @param string $run_id
     * @return array<string, mixed>|WP_Error
     */
    public function get_inventory_for_run($run_id)
    {
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') {
            return new WP_Error(
                'dbvc_cc_v2_run_missing',
                __('The requested V2 run could not be found.', 'dbvc'),
                ['status' => 404]
            );
        }

        $journey = DBVC_CC_V2_Domain_Journey_Service::get_instance();
        $domain = $journey->find_domain_by_journey_id($run_id);
        if ($domain === '') {
            return new WP_Error(
                'dbvc_cc_v2_run_missing',
                __('The requested V2 run could not be found.', 'dbvc'),
                ['status' => 404]
            );
        }

        $inventory = $this->get_inventory($domain);
        if (! is_wp_error($inventory) && (string) ($inventory['journey_id'] ?? '') === $run_id) {
            return $inventory;
        }

        $latest = $journey->get_latest_state_for_run($run_id);
        if (is_wp_error($latest)) {
            return $latest;
        }

        $events = $journey->get_events_for_run($run_id);
        if (is_wp_error($events)) {
            return $events;
        }

        return $this->build_inventory_from_events($domain, $run_id, $latest, $events);
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

    /**
     * @param string                           $domain
     * @param string                           $journey_id
     * @param array<string, mixed>             $latest
     * @param array<int, array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function build_inventory_from_events($domain, $journey_id, array $latest, array $events)
    {
        $rows = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $step_key = isset($event['step_key']) ? sanitize_key((string) $event['step_key']) : '';
            if (
                $step_key !== DBVC_CC_V2_Contracts::STEP_URL_DISCOVERED
                && $step_key !== DBVC_CC_V2_Contracts::STEP_URL_SCOPE_DECIDED
            ) {
                continue;
            }

            $page_id = sanitize_text_field((string) ($event['page_id'] ?? ''));
            if ($page_id === '') {
                continue;
            }

            if (! isset($rows[$page_id])) {
                $rows[$page_id] = $this->build_inventory_row(
                    $page_id,
                    sanitize_text_field((string) ($event['path'] ?? '')),
                    esc_url_raw((string) ($event['source_url'] ?? ''))
                );
            }

            $metadata = isset($event['metadata']) && is_array($event['metadata']) ? $event['metadata'] : [];
            if ($step_key === DBVC_CC_V2_Contracts::STEP_URL_DISCOVERED) {
                $rows[$page_id]['discovery_status'] = sanitize_key((string) ($metadata['discovery_status'] ?? 'discovered'));
                $rows[$page_id]['discovery_reason'] = sanitize_text_field((string) ($metadata['discovery_reason'] ?? ''));
                continue;
            }

            $rows[$page_id]['scope_status'] = sanitize_key((string) ($metadata['scope_status'] ?? 'eligible'));
            $rows[$page_id]['scope_reason'] = sanitize_text_field((string) ($metadata['scope_reason'] ?? ''));
        }

        $latest_stage_by_url = isset($latest['latest_stage_by_url']) && is_array($latest['latest_stage_by_url'])
            ? $latest['latest_stage_by_url']
            : [];
        foreach ($latest_stage_by_url as $page_id => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $normalized_page_id = sanitize_text_field((string) $page_id);
            if ($normalized_page_id === '') {
                continue;
            }

            if (! isset($rows[$normalized_page_id])) {
                $rows[$normalized_page_id] = $this->build_inventory_row(
                    $normalized_page_id,
                    sanitize_text_field((string) ($entry['path'] ?? '')),
                    esc_url_raw((string) ($entry['sourceUrl'] ?? ''))
                );
                continue;
            }

            if ($rows[$normalized_page_id]['path'] === '') {
                $rows[$normalized_page_id]['path'] = sanitize_text_field((string) ($entry['path'] ?? ''));
            }

            if ($rows[$normalized_page_id]['source_url'] === '') {
                $rows[$normalized_page_id]['source_url'] = esc_url_raw((string) ($entry['sourceUrl'] ?? ''));
                $rows[$normalized_page_id]['normalized_url'] = $rows[$normalized_page_id]['source_url'];
            }
        }

        uasort(
            $rows,
            static function ($left, $right) {
                $path_compare = strnatcasecmp((string) ($left['path'] ?? ''), (string) ($right['path'] ?? ''));
                if ($path_compare !== 0) {
                    return $path_compare;
                }

                return strnatcasecmp((string) ($left['page_id'] ?? ''), (string) ($right['page_id'] ?? ''));
            }
        );

        $inventory_rows = array_values($rows);
        $eligible_count = 0;
        $out_of_scope_count = 0;
        foreach ($inventory_rows as $row) {
            if (($row['scope_status'] ?? 'eligible') === 'eligible') {
                ++$eligible_count;
            } else {
                ++$out_of_scope_count;
            }
        }

        $run_profile = DBVC_CC_V2_Run_Profile_Service::get_instance()->get_profile_for_run($journey_id);
        $sitemap_url = '';
        if (! is_wp_error($run_profile)) {
            $request = isset($run_profile['request']) && is_array($run_profile['request']) ? $run_profile['request'] : [];
            $sitemap_url = isset($request['sitemap_url']) ? esc_url_raw((string) $request['sitemap_url']) : '';
        }

        $discovered_count = isset($latest['counts']['urls_discovered']) ? absint($latest['counts']['urls_discovered']) : count($inventory_rows);

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'domain-url-inventory.v1',
            'journey_id' => $journey_id,
            'domain' => sanitize_text_field((string) $domain),
            'generated_at' => current_time('c'),
            'source' => [
                'type' => 'run-materialized-events',
                'sitemap_url' => $sitemap_url,
            ],
            'urls' => $inventory_rows,
            'stats' => [
                'raw_url_count' => $discovered_count,
                'url_count' => count($inventory_rows),
                'eligible_count' => $eligible_count,
                'out_of_scope_count' => $out_of_scope_count,
                'duplicate_count' => 0,
                'invalid_count' => 0,
            ],
        ];
    }

    /**
     * @param string $page_id
     * @param string $path
     * @param string $source_url
     * @return array<string, mixed>
     */
    private function build_inventory_row($page_id, $path, $source_url)
    {
        $normalized_path = sanitize_text_field((string) $path);
        $normalized_source_url = esc_url_raw((string) $source_url);

        return [
            'page_id' => sanitize_text_field((string) $page_id),
            'normalized_url' => $normalized_source_url,
            'source_url' => $normalized_source_url,
            'path' => $normalized_path,
            'slug' => DBVC_CC_Artifact_Manager::get_slug_from_url(
                $normalized_source_url !== '' ? $normalized_source_url : $normalized_path
            ),
            'discovery_status' => 'discovered',
            'discovery_reason' => '',
            'scope_status' => 'eligible',
            'scope_reason' => '',
        ];
    }
}
