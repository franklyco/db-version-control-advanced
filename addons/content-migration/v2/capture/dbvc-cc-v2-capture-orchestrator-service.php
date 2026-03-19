<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Capture_Orchestrator_Service
{
    /**
     * @var DBVC_CC_V2_Capture_Orchestrator_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Capture_Orchestrator_Service
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
    public function run_domain($domain, $journey_id, $sitemap_url, array $args = [])
    {
        $journey = DBVC_CC_V2_Domain_Journey_Service::get_instance();
        $context = $journey->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        $journey_id = sanitize_text_field((string) $journey_id);
        $actor = isset($args['actor']) ? sanitize_key((string) $args['actor']) : 'system';
        $trigger = isset($args['trigger']) ? sanitize_key((string) $args['trigger']) : 'manual';
        $schema_fingerprint = isset($args['schema_fingerprint']) ? sanitize_text_field((string) $args['schema_fingerprint']) : '';
        $crawl_overrides = isset($args['crawl_overrides']) && is_array($args['crawl_overrides']) ? $args['crawl_overrides'] : [];
        $max_urls = isset($args['max_urls']) ? absint($args['max_urls']) : 0;

        $inventory_result = DBVC_CC_V2_URL_Inventory_Service::get_instance()->build_inventory(
            $context['domain'],
            $journey_id,
            $sitemap_url,
            [
                'crawl_overrides' => $crawl_overrides,
            ]
        );
        if (is_wp_error($inventory_result)) {
            return $inventory_result;
        }

        $inventory = isset($inventory_result['inventory']) && is_array($inventory_result['inventory']) ? $inventory_result['inventory'] : [];
        $rows = isset($inventory['urls']) && is_array($inventory['urls']) ? $inventory['urls'] : [];
        $options = DBVC_CC_Crawler_Service::get_effective_crawl_options($crawl_overrides);

        $processed = [];
        $processed_count = 0;
        $failed_count = 0;
        $skipped_count = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (($row['scope_status'] ?? '') !== 'eligible') {
                ++$skipped_count;
                continue;
            }

            if ($max_urls > 0 && $processed_count >= $max_urls) {
                break;
            }

            $page_result = $this->capture_page($context, $row, $journey_id, $schema_fingerprint, $options, $actor, $trigger);
            if (is_wp_error($page_result)) {
                ++$failed_count;
                $processed[] = [
                    'pageId' => isset($row['page_id']) ? (string) $row['page_id'] : '',
                    'status' => 'failed',
                    'error' => $page_result->get_error_message(),
                ];
                continue;
            }

            ++$processed_count;
            $processed[] = $page_result;
        }

        $latest = $journey->get_latest_state($context['domain']);
        $stage_summary = $journey->get_stage_summary($context['domain']);
        if (is_wp_error($latest)) {
            return $latest;
        }
        if (is_wp_error($stage_summary)) {
            return $stage_summary;
        }

        return [
            'runId' => $journey_id,
            'journey_id' => $journey_id,
            'domain' => $context['domain'],
            'url_inventory' => $inventory,
            'pages' => $processed,
            'stats' => [
                'processed_count' => $processed_count,
                'failed_count' => $failed_count,
                'skipped_count' => $skipped_count,
            ],
            'latest' => $latest,
            'stage_summary' => $stage_summary,
        ];
    }

    /**
     * @param array<string, string> $context
     * @param array<string, string> $row
     * @param string                $journey_id
     * @param string                $schema_fingerprint
     * @param array<string, mixed>  $options
     * @param string                $actor
     * @param string                $trigger
     * @return array<string, mixed>|WP_Error
     */
    private function capture_page(array $context, array $row, $journey_id, $schema_fingerprint, array $options, $actor, $trigger)
    {
        $normalized_url = isset($row['normalized_url']) ? (string) $row['normalized_url'] : '';
        $source_url = isset($row['source_url']) ? (string) $row['source_url'] : $normalized_url;
        $page_id = isset($row['page_id']) ? (string) $row['page_id'] : '';
        $path = isset($row['path']) ? (string) $row['path'] : '';
        $slug = isset($row['slug']) ? (string) $row['slug'] : DBVC_CC_Artifact_Manager::get_slug_from_url($normalized_url);

        $journey = DBVC_CC_V2_Domain_Journey_Service::get_instance();
        $journey->append_event(
            $context['domain'],
            [
                'journey_id' => $journey_id,
                'step_key' => DBVC_CC_V2_Contracts::STEP_PAGE_CAPTURE_COMPLETED,
                'step_name' => 'Page capture completed',
                'status' => 'started',
                'page_id' => $page_id,
                'path' => $path,
                'source_url' => $source_url,
                'schema_fingerprint' => $schema_fingerprint,
                'actor' => $actor,
                'trigger' => $trigger,
                'message' => 'Page capture started.',
            ]
        );

        $parsed = DBVC_CC_V2_Page_Capture_Service::get_instance()->capture_page($journey_id, $row, $options);
        if (is_wp_error($parsed)) {
            $journey->append_event(
                $context['domain'],
                [
                    'journey_id' => $journey_id,
                    'step_key' => DBVC_CC_V2_Contracts::STEP_PAGE_CAPTURE_COMPLETED,
                    'step_name' => 'Page capture completed',
                    'status' => 'failed',
                    'page_id' => $page_id,
                    'path' => $path,
                    'source_url' => $source_url,
                    'schema_fingerprint' => $schema_fingerprint,
                    'actor' => $actor,
                    'trigger' => $trigger,
                    'error_code' => $parsed->get_error_code(),
                    'message' => $parsed->get_error_message(),
                ]
            );

            return $parsed;
        }

        $page_dir = DBVC_CC_Artifact_Manager::prepare_page_directory($normalized_url);
        if (is_wp_error($page_dir)) {
            return $page_dir;
        }

        $raw_path = trailingslashit($page_dir) . $slug . '.json';
        if (! DBVC_CC_Artifact_Manager::write_json_file($raw_path, $parsed['raw_artifact'])) {
            return new WP_Error(
                'dbvc_cc_v2_raw_write_failed',
                __('Could not write the V2 raw page artifact.', 'dbvc'),
                ['status' => 500]
            );
        }

        $raw_relative = $this->get_domain_relative_path($raw_path, $context['domain_dir']);
        $source_fingerprint = isset($parsed['raw_artifact']['content_hash']) ? (string) $parsed['raw_artifact']['content_hash'] : '';

        $journey->append_event(
            $context['domain'],
            [
                'journey_id' => $journey_id,
                'step_key' => DBVC_CC_V2_Contracts::STEP_PAGE_CAPTURE_COMPLETED,
                'step_name' => 'Page capture completed',
                'status' => 'completed',
                'page_id' => $page_id,
                'path' => $path,
                'source_url' => $source_url,
                'output_artifacts' => [$raw_relative],
                'source_fingerprint' => $source_fingerprint,
                'schema_fingerprint' => $schema_fingerprint,
                'actor' => $actor,
                'trigger' => $trigger,
                'message' => 'Raw page artifact captured.',
            ]
        );

        $source_normalization = DBVC_CC_V2_Source_Normalization_Service::get_instance()->build_artifact($parsed['raw_artifact']);
        $source_normalization_path = trailingslashit($page_dir) . $slug . DBVC_CC_V2_Contracts::STORAGE_SOURCE_NORMALIZATION_SUFFIX;
        if (! DBVC_CC_Artifact_Manager::write_json_file($source_normalization_path, $source_normalization)) {
            return new WP_Error(
                'dbvc_cc_v2_source_normalization_write_failed',
                __('Could not write the V2 source normalization artifact.', 'dbvc'),
                ['status' => 500]
            );
        }

        $source_normalization_relative = $this->get_domain_relative_path($source_normalization_path, $context['domain_dir']);
        $journey->append_event(
            $context['domain'],
            [
                'journey_id' => $journey_id,
                'step_key' => DBVC_CC_V2_Contracts::STEP_SOURCE_NORMALIZATION_COMPLETED,
                'step_name' => 'Source normalization completed',
                'status' => 'completed',
                'page_id' => $page_id,
                'path' => $path,
                'source_url' => $source_url,
                'input_artifacts' => [$raw_relative],
                'output_artifacts' => [$source_normalization_relative],
                'source_fingerprint' => $source_fingerprint,
                'schema_fingerprint' => $schema_fingerprint,
                'actor' => $actor,
                'trigger' => $trigger,
                'message' => 'Deterministic source normalization artifact written.',
            ]
        );

        $extracted = DBVC_CC_V2_Structured_Extraction_Service::get_instance()->build_artifacts(
            $parsed['raw_artifact'],
            $parsed['xpath'],
            $parsed['context_nodes'],
            $options
        );
        if (is_wp_error($extracted)) {
            return $extracted;
        }

        $artifact_paths = [];

        if (! empty($extracted['elements'])) {
            $elements_path = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_ELEMENTS_V2_SUFFIX;
            if (! DBVC_CC_Artifact_Manager::write_json_file($elements_path, $extracted['elements'])) {
                return new WP_Error(
                    'dbvc_cc_v2_elements_write_failed',
                    __('Could not write the V2 elements artifact.', 'dbvc'),
                    ['status' => 500]
                );
            }

            $artifact_paths[] = $this->get_domain_relative_path($elements_path, $context['domain_dir']);
        }

        if (! empty($extracted['sections'])) {
            $sections_path = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_SECTIONS_V2_SUFFIX;
            if (! DBVC_CC_Artifact_Manager::write_json_file($sections_path, $extracted['sections'])) {
                return new WP_Error(
                    'dbvc_cc_v2_sections_write_failed',
                    __('Could not write the V2 sections artifact.', 'dbvc'),
                    ['status' => 500]
                );
            }

            $artifact_paths[] = $this->get_domain_relative_path($sections_path, $context['domain_dir']);
        }

        if (! empty($extracted['ingestion_package'])) {
            $ingestion_path = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_INGESTION_PACKAGE_V2_SUFFIX;
            if (! DBVC_CC_Artifact_Manager::write_json_file($ingestion_path, $extracted['ingestion_package'])) {
                return new WP_Error(
                    'dbvc_cc_v2_ingestion_package_write_failed',
                    __('Could not write the V2 ingestion package artifact.', 'dbvc'),
                    ['status' => 500]
                );
            }

            $artifact_paths[] = $this->get_domain_relative_path($ingestion_path, $context['domain_dir']);
        }

        $journey->append_event(
            $context['domain'],
            [
                'journey_id' => $journey_id,
                'step_key' => DBVC_CC_V2_Contracts::STEP_STRUCTURED_EXTRACTION_COMPLETED,
                'step_name' => 'Structured extraction completed',
                'status' => 'completed',
                'page_id' => $page_id,
                'path' => $path,
                'source_url' => $source_url,
                'input_artifacts' => [$raw_relative, $source_normalization_relative],
                'output_artifacts' => $artifact_paths,
                'source_fingerprint' => $source_fingerprint,
                'schema_fingerprint' => $schema_fingerprint,
                'actor' => $actor,
                'trigger' => $trigger,
                'message' => 'Structured extraction artifacts written.',
            ]
        );

        return [
            'pageId' => $page_id,
            'status' => 'completed',
            'path' => $path,
            'sourceUrl' => $source_url,
            'artifacts' => array_merge([$raw_relative, $source_normalization_relative], $artifact_paths),
        ];
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
