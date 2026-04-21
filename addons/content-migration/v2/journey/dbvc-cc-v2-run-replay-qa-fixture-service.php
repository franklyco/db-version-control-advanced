<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Run_Replay_QA_Fixture_Service
{
    /**
     * @var DBVC_CC_V2_Run_Replay_QA_Fixture_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Run_Replay_QA_Fixture_Service
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
     * @param string               $source_run_id
     * @param string               $replay_run_id
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function create_replay_run($source_run_id, $replay_run_id, array $args = [])
    {
        if (! $this->is_available()) {
            return new WP_Error(
                'dbvc_cc_v2_replay_qa_fixture_unavailable',
                __('The V2 replay QA fixture helper is unavailable in this environment.', 'dbvc'),
                ['status' => 403]
            );
        }

        $source_run_id = sanitize_text_field((string) $source_run_id);
        $replay_run_id = sanitize_text_field((string) $replay_run_id);
        if ($source_run_id === '' || $replay_run_id === '') {
            return new WP_Error(
                'dbvc_cc_v2_replay_qa_fixture_invalid',
                __('Source and replay run IDs are required for deterministic replay QA.', 'dbvc'),
                ['status' => 400]
            );
        }

        $journey = DBVC_CC_V2_Domain_Journey_Service::get_instance();
        $domain = $journey->find_domain_by_journey_id($source_run_id);
        if ($domain === '') {
            return new WP_Error(
                'dbvc_cc_v2_run_missing',
                __('The requested V2 source run could not be found.', 'dbvc'),
                ['status' => 404]
            );
        }

        $requested_domain = isset($args['domain']) ? sanitize_text_field((string) $args['domain']) : '';
        if ($requested_domain !== '' && $requested_domain !== $domain) {
            return new WP_Error(
                'dbvc_cc_v2_replay_qa_fixture_domain_mismatch',
                __('The deterministic replay helper requires a source run from the requested domain.', 'dbvc'),
                ['status' => 400]
            );
        }

        $source_events = $journey->get_events_for_run($source_run_id);
        if (is_wp_error($source_events)) {
            return $source_events;
        }

        if (empty($source_events)) {
            return new WP_Error(
                'dbvc_cc_v2_replay_qa_fixture_source_empty',
                __('The requested V2 source run does not have replayable journey events.', 'dbvc'),
                ['status' => 400]
            );
        }

        $cloned_event_count = 0;
        foreach ($source_events as $index => $event) {
            if (! is_array($event)) {
                continue;
            }

            $append_result = $journey->append_event(
                $domain,
                $this->build_cloned_event($event, $source_run_id, $replay_run_id, (int) $index)
            );
            if (is_wp_error($append_result)) {
                return $append_result;
            }

            ++$cloned_event_count;
        }

        if ($cloned_event_count < 1) {
            return new WP_Error(
                'dbvc_cc_v2_replay_qa_fixture_clone_failed',
                __('The deterministic replay helper could not clone the source run.', 'dbvc'),
                ['status' => 500]
            );
        }

        $cloned_artifact_count = $this->clone_page_artifacts_for_replay($source_run_id, $replay_run_id);
        if (is_wp_error($cloned_artifact_count)) {
            return $cloned_artifact_count;
        }

        $latest = $journey->get_latest_state_for_run($replay_run_id);
        if (is_wp_error($latest)) {
            return $latest;
        }

        $stage_summary = $journey->get_stage_summary_for_run($replay_run_id);
        if (is_wp_error($stage_summary)) {
            return $stage_summary;
        }

        $url_inventory = DBVC_CC_V2_URL_Inventory_Service::get_instance()->get_inventory($domain);
        if (is_wp_error($url_inventory)) {
            $url_inventory = [];
        }

        $run_profile = DBVC_CC_V2_Run_Profile_Service::get_instance()->get_profile_for_run($replay_run_id);
        if (is_wp_error($run_profile)) {
            $run_profile = [];
        }

        return [
            'runId' => $replay_run_id,
            'journey_id' => $replay_run_id,
            'domain' => $domain,
            'latest' => $latest,
            'stage_summary' => $stage_summary,
            'run_profile' => is_array($run_profile) ? $run_profile : [],
            'url_inventory' => is_array($url_inventory) ? $url_inventory : [],
            'pages' => [],
            'stats' => [
                'cloned_event_count' => $cloned_event_count,
                'cloned_artifact_count' => absint($cloned_artifact_count),
                'source_url_count' => isset($latest['latest_stage_by_url']) && is_array($latest['latest_stage_by_url'])
                    ? count($latest['latest_stage_by_url'])
                    : 0,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $event
     * @param string               $source_run_id
     * @param string               $replay_run_id
     * @param int                  $index
     * @return array<string, mixed>
     */
    private function build_cloned_event(array $event, $source_run_id, $replay_run_id, $index)
    {
        $timestamp = gmdate('c', time() + max(0, (int) $index));
        $metadata = isset($event['metadata']) && is_array($event['metadata']) ? $event['metadata'] : [];
        $metadata['qa_replay_source_run_id'] = $source_run_id;
        $metadata['qa_replay_cloned'] = true;

        return [
            'journey_id' => $replay_run_id,
            'pipeline_version' => isset($event['pipeline_version']) ? (string) $event['pipeline_version'] : DBVC_CC_V2_Contracts::PIPELINE_VERSION,
            'step_key' => isset($event['step_key']) ? (string) $event['step_key'] : '',
            'step_name' => isset($event['step_name']) ? (string) $event['step_name'] : '',
            'status' => isset($event['status']) ? (string) $event['status'] : 'queued',
            'started_at' => $timestamp,
            'finished_at' => $timestamp,
            'duration_ms' => isset($event['duration_ms']) ? absint($event['duration_ms']) : 0,
            'actor' => 'admin',
            'trigger' => 'qa_fixture',
            'input_artifacts' => isset($event['input_artifacts']) && is_array($event['input_artifacts']) ? $event['input_artifacts'] : [],
            'output_artifacts' => isset($event['output_artifacts']) && is_array($event['output_artifacts']) ? $event['output_artifacts'] : [],
            'source_fingerprint' => isset($event['source_fingerprint']) ? (string) $event['source_fingerprint'] : '',
            'schema_fingerprint' => isset($event['schema_fingerprint']) ? (string) $event['schema_fingerprint'] : '',
            'message' => sprintf(
                /* translators: %s: source run id */
                __('Deterministic replay QA cloned from %s.', 'dbvc'),
                $source_run_id
            ),
            'warning_codes' => isset($event['warning_codes']) && is_array($event['warning_codes']) ? $event['warning_codes'] : [],
            'error_code' => isset($event['error_code']) ? (string) $event['error_code'] : '',
            'metadata' => $metadata,
            'exception_state' => isset($event['exception_state']) ? (string) $event['exception_state'] : '',
            'rerun_parent_event_id' => '',
            'package_id' => '',
            'page_id' => isset($event['page_id']) ? (string) $event['page_id'] : '',
            'path' => isset($event['path']) ? (string) $event['path'] : '',
            'source_url' => isset($event['source_url']) ? (string) $event['source_url'] : '',
            'override_scope' => isset($event['override_scope']) ? (string) $event['override_scope'] : '',
            'override_target' => isset($event['override_target']) ? (string) $event['override_target'] : '',
        ];
    }

    /**
     * @param string $source_run_id
     * @param string $replay_run_id
     * @return int|WP_Error
     */
    private function clone_page_artifacts_for_replay($source_run_id, $replay_run_id)
    {
        $inventory = DBVC_CC_V2_URL_Inventory_Service::get_instance()->get_inventory_for_run($source_run_id);
        if (is_wp_error($inventory)) {
            return $inventory;
        }

        $rows = isset($inventory['urls']) && is_array($inventory['urls']) ? $inventory['urls'] : [];
        $page_service = DBVC_CC_V2_Page_Artifact_Service::get_instance();
        $cloned_artifact_count = 0;

        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['page_id'])) {
                continue;
            }

            $page_context = $page_service->resolve_page_context_for_run($source_run_id, (string) $row['page_id']);
            if (is_wp_error($page_context)) {
                return $page_context;
            }

            $artifact_paths = isset($page_context['artifact_paths']) && is_array($page_context['artifact_paths']) ? $page_context['artifact_paths'] : [];
            $run_artifact_paths = isset($page_context['run_artifact_paths']) && is_array($page_context['run_artifact_paths']) ? $page_context['run_artifact_paths'] : [];
            $current_artifact_paths = isset($page_context['current_artifact_paths']) && is_array($page_context['current_artifact_paths']) ? $page_context['current_artifact_paths'] : [];

            foreach ($artifact_paths as $key => $source_path) {
                $artifact = $this->read_json_file((string) $source_path);
                if (! is_array($artifact)) {
                    continue;
                }

                $source_run_scoped_path = isset($run_artifact_paths[$key]) ? (string) $run_artifact_paths[$key] : '';
                if (
                    $source_run_scoped_path !== ''
                    && ! $page_service->write_page_artifact(
                        $source_run_scoped_path,
                        $this->prepare_cloned_artifact($artifact, $source_run_id, $source_run_id, false),
                        $source_run_id
                    )
                ) {
                    return new WP_Error(
                        'dbvc_cc_v2_replay_qa_fixture_source_preserve_failed',
                        __('The deterministic replay helper could not preserve the source page artifacts.', 'dbvc'),
                        ['status' => 500]
                    );
                }

                $current_path = isset($current_artifact_paths[$key]) ? (string) $current_artifact_paths[$key] : '';
                if (
                    $current_path !== ''
                    && ! $page_service->write_page_artifact(
                        $current_path,
                        $this->prepare_cloned_artifact($artifact, $source_run_id, $replay_run_id, true),
                        $replay_run_id
                    )
                ) {
                    return new WP_Error(
                        'dbvc_cc_v2_replay_qa_fixture_artifact_clone_failed',
                        __('The deterministic replay helper could not clone the page artifacts for replay.', 'dbvc'),
                        ['status' => 500]
                    );
                }

                ++$cloned_artifact_count;
            }
        }

        return $cloned_artifact_count;
    }

    /**
     * @param array<string, mixed> $artifact
     * @param string               $source_run_id
     * @param string               $target_run_id
     * @param bool                 $mark_as_replay_clone
     * @return array<string, mixed>
     */
    private function prepare_cloned_artifact(array $artifact, $source_run_id, $target_run_id, $mark_as_replay_clone)
    {
        $cloned_artifact = $artifact;
        $cloned_artifact['journey_id'] = $target_run_id;

        if (isset($cloned_artifact['generated_at'])) {
            $cloned_artifact['generated_at'] = current_time('c');
        }

        $metadata = isset($cloned_artifact['metadata']) && is_array($cloned_artifact['metadata'])
            ? $cloned_artifact['metadata']
            : [];
        $metadata['qa_replay_source_run_id'] = $source_run_id;
        if ($mark_as_replay_clone) {
            $metadata['qa_replay_cloned'] = true;
        }
        $cloned_artifact['metadata'] = $metadata;

        return $cloned_artifact;
    }

    /**
     * @param string $path
     * @return array<string, mixed>|null
     */
    private function read_json_file($path)
    {
        $path = (string) $path;
        if ($path === '' || ! file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
