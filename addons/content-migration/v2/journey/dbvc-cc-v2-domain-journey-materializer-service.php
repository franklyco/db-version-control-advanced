<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Domain_Journey_Materializer_Service
{
    /**
     * @var DBVC_CC_V2_Domain_Journey_Materializer_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Domain_Journey_Materializer_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param array<string, string> $context
     * @return array<string, mixed>|WP_Error
     */
    public function materialize(array $context)
    {
        $events = $this->read_ndjson_file($context['journey_log_file']);
        if (empty($events)) {
            return new WP_Error(
                'dbvc_cc_v2_journey_empty',
                __('No journey events were found for the requested domain.', 'dbvc'),
                ['status' => 404]
            );
        }

        $latest = $this->build_latest_state($context['domain'], $events);
        $stage_summary = $this->build_stage_summary($context['domain'], $events);

        if (! DBVC_CC_Artifact_Manager::write_json_file($context['journey_latest_file'], $latest)) {
            return new WP_Error(
                'dbvc_cc_v2_journey_latest_write_failed',
                __('Could not write the journey latest-state artifact.', 'dbvc'),
                ['status' => 500]
            );
        }

        if (! DBVC_CC_Artifact_Manager::write_json_file($context['journey_stage_summary_file'], $stage_summary)) {
            return new WP_Error(
                'dbvc_cc_v2_journey_stage_summary_write_failed',
                __('Could not write the journey stage summary artifact.', 'dbvc'),
                ['status' => 500]
            );
        }

        return [
            'latest' => $latest,
            'stage_summary' => $stage_summary,
        ];
    }

    /**
     * @param string                      $domain
     * @param array<int, array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function build_latest_state($domain, array $events)
    {
        $counts = [
            'urls_discovered' => 0,
            'urls_captured' => 0,
            'urls_extracted' => 0,
            'urls_context_created' => 0,
            'urls_classified' => 0,
            'urls_mapped' => 0,
            'urls_finalized' => 0,
            'urls_reviewed' => 0,
            'urls_failed' => 0,
            'urls_blocked' => 0,
        ];

        $latest = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'domain-journey.latest.v1',
            'journey_id' => '',
            'domain' => $domain,
            'pipeline_version' => DBVC_CC_V2_Contracts::PIPELINE_VERSION,
            'started_at' => '',
            'updated_at' => '',
            'status' => 'queued',
            'counts' => $counts,
            'latest_stage_by_url' => [],
            'urls_needing_review' => [],
            'urls_auto_accepted' => [],
            'urls_manually_overridden' => [],
            'urls_rerun' => [],
            'urls_blocked' => [],
            'urls_failed' => [],
            'urls_package_ready' => [],
            'packages_built' => [],
            'artifact_inventory' => [],
            'latest_schema_fingerprint' => '',
        ];

        $artifact_inventory = [];
        $status_buckets = [
            'urls_needing_review' => [],
            'urls_auto_accepted' => [],
            'urls_manually_overridden' => [],
            'urls_rerun' => [],
            'urls_blocked' => [],
            'urls_failed' => [],
            'urls_package_ready' => [],
        ];

        foreach ($events as $index => $event) {
            $journey_id = isset($event['journey_id']) ? (string) $event['journey_id'] : '';
            if ($journey_id !== '') {
                $latest['journey_id'] = $journey_id;
            }

            $pipeline_version = isset($event['pipeline_version']) ? (string) $event['pipeline_version'] : '';
            if ($pipeline_version !== '') {
                $latest['pipeline_version'] = $pipeline_version;
            }

            $started_at = isset($event['started_at']) ? (string) $event['started_at'] : '';
            if ($started_at !== '' && $latest['started_at'] === '') {
                $latest['started_at'] = $started_at;
            }

            $updated_at = isset($event['finished_at']) && (string) $event['finished_at'] !== ''
                ? (string) $event['finished_at']
                : $started_at;
            if ($updated_at !== '') {
                $latest['updated_at'] = $updated_at;
            }

            $latest['status'] = isset($event['status']) ? (string) $event['status'] : 'queued';

            $schema_fingerprint = isset($event['schema_fingerprint']) ? (string) $event['schema_fingerprint'] : '';
            if ($schema_fingerprint !== '') {
                $latest['latest_schema_fingerprint'] = $schema_fingerprint;
            }

            $output_artifacts = isset($event['output_artifacts']) && is_array($event['output_artifacts'])
                ? $event['output_artifacts']
                : [];
            foreach ($output_artifacts as $artifact) {
                $artifact_key = sanitize_text_field((string) $artifact);
                if ($artifact_key !== '') {
                    $artifact_inventory[$artifact_key] = $artifact_key;
                }
            }

            $step_key = isset($event['step_key']) ? (string) $event['step_key'] : '';
            $status = isset($event['status']) ? (string) $event['status'] : '';
            $exception_state = isset($event['exception_state']) ? sanitize_key((string) $event['exception_state']) : '';
            $page_id = isset($event['page_id']) ? (string) $event['page_id'] : '';
            if ($page_id !== '') {
                $latest['latest_stage_by_url'][$page_id] = [
                    'pageId' => $page_id,
                    'stepKey' => $step_key,
                    'status' => isset($event['status']) ? (string) $event['status'] : 'queued',
                    'path' => isset($event['path']) ? (string) $event['path'] : '',
                    'sourceUrl' => isset($event['source_url']) ? (string) $event['source_url'] : '',
                    'index' => $index,
                ];
            }

            $is_completion = in_array($status, ['completed', 'completed_with_warnings'], true);

            if ($is_completion && $step_key === 'url_discovered' && isset($counts['urls_discovered'])) {
                ++$counts['urls_discovered'];
            } elseif ($is_completion && $step_key === 'page_capture_completed') {
                ++$counts['urls_captured'];
            } elseif ($is_completion && $step_key === 'structured_extraction_completed') {
                ++$counts['urls_extracted'];
            } elseif ($is_completion && $step_key === 'context_creation_completed') {
                ++$counts['urls_context_created'];
            } elseif ($is_completion && $step_key === 'initial_classification_completed') {
                ++$counts['urls_classified'];
            } elseif ($is_completion && $step_key === 'target_transform_completed') {
                ++$counts['urls_mapped'];
            } elseif ($is_completion && $step_key === 'recommended_mappings_finalized') {
                ++$counts['urls_finalized'];
            } elseif ($is_completion && $step_key === 'review_decision_saved') {
                ++$counts['urls_reviewed'];
            }

            $is_failed = $status === 'failed' || $exception_state === 'failed';
            $is_blocked = $status === 'blocked' || $exception_state === 'blocked';
            $is_needs_review = $status === 'needs_review' || $exception_state === 'needs_review';

            if ($is_needs_review && $page_id !== '') {
                $status_buckets['urls_needing_review'][$page_id] = $page_id;
            } elseif ($is_failed && $page_id !== '') {
                $status_buckets['urls_failed'][$page_id] = $page_id;
                ++$counts['urls_failed'];
            } elseif ($is_blocked && $page_id !== '') {
                $status_buckets['urls_blocked'][$page_id] = $page_id;
                ++$counts['urls_blocked'];
            }

            if ($step_key === 'review_decision_saved' && $page_id !== '') {
                $metadata = isset($event['metadata']) && is_array($event['metadata']) ? $event['metadata'] : [];
                $decision_type = isset($metadata['decision_type']) ? sanitize_key((string) $metadata['decision_type']) : '';
                if ($decision_type === 'auto_accept') {
                    $status_buckets['urls_auto_accepted'][$page_id] = $page_id;
                } elseif ($decision_type === 'manual_override') {
                    $status_buckets['urls_manually_overridden'][$page_id] = $page_id;
                }
            }

            if ($step_key === 'stage_rerun_completed' && $page_id !== '') {
                $status_buckets['urls_rerun'][$page_id] = $page_id;
            }

            if ($step_key === 'package_ready' && $page_id !== '') {
                $status_buckets['urls_package_ready'][$page_id] = $page_id;
            }

            if ($step_key === 'package_built') {
                $package_id = isset($event['package_id']) ? (string) $event['package_id'] : '';
                if ($package_id !== '') {
                    $latest['packages_built'][$package_id] = $package_id;
                }
            }
        }

        $latest['counts'] = $counts;
        $latest['artifact_inventory'] = array_values($artifact_inventory);

        foreach ($status_buckets as $bucket => $values) {
            $latest[$bucket] = array_values($values);
        }

        $latest['packages_built'] = array_values($latest['packages_built']);

        return $latest;
    }

    /**
     * @param string                           $domain
     * @param array<int, array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function build_stage_summary($domain, array $events)
    {
        $stages = [];
        $journey_id = '';
        $updated_at = '';
        $pipeline_version = DBVC_CC_V2_Contracts::PIPELINE_VERSION;
        $stats = [
            'total_events' => 0,
            'completed_steps' => 0,
            'failed_steps' => 0,
            'blocked_steps' => 0,
            'needs_review_steps' => 0,
        ];

        foreach ($events as $event) {
            ++$stats['total_events'];

            $step_key = isset($event['step_key']) ? (string) $event['step_key'] : '';
            if ($step_key === '') {
                continue;
            }

            $journey_candidate = isset($event['journey_id']) ? (string) $event['journey_id'] : '';
            if ($journey_candidate !== '') {
                $journey_id = $journey_candidate;
            }

            $pipeline_candidate = isset($event['pipeline_version']) ? (string) $event['pipeline_version'] : '';
            if ($pipeline_candidate !== '') {
                $pipeline_version = $pipeline_candidate;
            }

            $event_status = isset($event['status']) ? (string) $event['status'] : 'queued';
            if ($event_status === 'completed' || $event_status === 'completed_with_warnings') {
                ++$stats['completed_steps'];
            } elseif ($event_status === 'failed') {
                ++$stats['failed_steps'];
            } elseif ($event_status === 'blocked') {
                ++$stats['blocked_steps'];
            } elseif ($event_status === 'needs_review') {
                ++$stats['needs_review_steps'];
            }

            $updated_candidate = isset($event['finished_at']) && (string) $event['finished_at'] !== ''
                ? (string) $event['finished_at']
                : (isset($event['started_at']) ? (string) $event['started_at'] : '');
            if ($updated_candidate !== '') {
                $updated_at = $updated_candidate;
            }

            if (! isset($stages[$step_key])) {
                $stages[$step_key] = [
                    'step_key' => $step_key,
                    'step_name' => isset($event['step_name']) ? (string) $event['step_name'] : '',
                    'status' => $event_status,
                    'event_count' => 0,
                    'last_started_at' => '',
                    'last_finished_at' => '',
                    'last_duration_ms' => 0,
                    'latest_message' => '',
                    'latest_schema_fingerprint' => '',
                    'warning_codes' => [],
                    'error_code' => '',
                ];
            }

            ++$stages[$step_key]['event_count'];
            $stages[$step_key]['status'] = $event_status;
            $stages[$step_key]['last_started_at'] = isset($event['started_at']) ? (string) $event['started_at'] : '';
            $stages[$step_key]['last_finished_at'] = isset($event['finished_at']) ? (string) $event['finished_at'] : '';
            $stages[$step_key]['last_duration_ms'] = isset($event['duration_ms']) ? absint($event['duration_ms']) : 0;
            $stages[$step_key]['latest_message'] = isset($event['message']) ? sanitize_text_field((string) $event['message']) : '';
            $stages[$step_key]['latest_schema_fingerprint'] = isset($event['schema_fingerprint']) ? (string) $event['schema_fingerprint'] : '';
            $stages[$step_key]['warning_codes'] = isset($event['warning_codes']) && is_array($event['warning_codes'])
                ? array_values(array_map('sanitize_key', $event['warning_codes']))
                : [];
            $stages[$step_key]['error_code'] = isset($event['error_code']) ? sanitize_key((string) $event['error_code']) : '';
        }

        ksort($stages);

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'domain-stage-summary.v1',
            'journey_id' => $journey_id,
            'domain' => $domain,
            'pipeline_version' => $pipeline_version,
            'updated_at' => $updated_at,
            'stages' => array_values($stages),
            'stats' => $stats,
        ];
    }

    /**
     * @param string $path
     * @return array<int, array<string, mixed>>
     */
    private function read_ndjson_file($path)
    {
        $path = (string) $path;
        if ($path === '' || ! file_exists($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! is_array($lines)) {
            return [];
        }

        $events = [];
        foreach ($lines as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $events;
    }
}
