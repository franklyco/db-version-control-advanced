<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Domain_Journey_Service
{
    /**
     * @var DBVC_CC_V2_Domain_Journey_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Domain_Journey_Service
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
     * @return array<string, string>|WP_Error
     */
    public function get_domain_context($domain)
    {
        DBVC_CC_Artifact_Manager::ensure_storage_roots();

        $domain_key = $this->sanitize_domain_key($domain);
        if ($domain_key === '') {
            return new WP_Error(
                'dbvc_cc_v2_domain_invalid',
                __('A valid domain key is required.', 'dbvc'),
                ['status' => 400]
            );
        }

        $base_dir = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        if (! is_string($base_dir) || $base_dir === '') {
            return new WP_Error(
                'dbvc_cc_v2_storage_missing',
                __('Content migration storage path is not available.', 'dbvc'),
                ['status' => 500]
            );
        }

        if (! dbvc_cc_create_security_files($base_dir)) {
            return new WP_Error(
                'dbvc_cc_v2_storage_unavailable',
                __('Could not create the content migration storage root.', 'dbvc'),
                ['status' => 500]
            );
        }

        $base_real = realpath($base_dir);
        if (! is_string($base_real)) {
            return new WP_Error(
                'dbvc_cc_v2_storage_invalid',
                __('Could not resolve the content migration storage root.', 'dbvc'),
                ['status' => 500]
            );
        }

        $domain_dir = trailingslashit($base_real) . $domain_key;
        if (! dbvc_cc_create_security_files($domain_dir)) {
            return new WP_Error(
                'dbvc_cc_v2_domain_dir_create_failed',
                __('Could not create the domain storage directory.', 'dbvc'),
                ['status' => 500]
            );
        }

        $domain_real = realpath($domain_dir);
        if (! is_string($domain_real) || ! dbvc_cc_path_is_within($domain_real, $base_real)) {
            return new WP_Error(
                'dbvc_cc_v2_domain_dir_invalid',
                __('The domain storage directory is outside the storage root.', 'dbvc'),
                ['status' => 500]
            );
        }

        $journey_dir = trailingslashit($domain_real) . DBVC_CC_V2_Contracts::STORAGE_JOURNEY_SUBDIR;
        $inventory_dir = trailingslashit($domain_real) . DBVC_CC_V2_Contracts::STORAGE_INVENTORY_SUBDIR;
        $learning_dir = trailingslashit($domain_real) . DBVC_CC_V2_Contracts::STORAGE_LEARNING_SUBDIR;
        $packages_dir = trailingslashit($domain_real) . DBVC_CC_V2_Contracts::STORAGE_PACKAGES_SUBDIR;
        $schema_dir = trailingslashit($domain_real) . DBVC_CC_Contracts::STORAGE_SCHEMA_SNAPSHOT_SUBDIR;

        if (
            ! dbvc_cc_create_security_files($journey_dir)
            || ! dbvc_cc_create_security_files($inventory_dir)
            || ! dbvc_cc_create_security_files($learning_dir)
            || ! dbvc_cc_create_security_files($packages_dir)
            || ! dbvc_cc_create_security_files($schema_dir)
        ) {
            return new WP_Error(
                'dbvc_cc_v2_domain_subdir_create_failed',
                __('Could not create V2 journey, inventory, learning, package, or schema directories.', 'dbvc'),
                ['status' => 500]
            );
        }

        $context = [
            'domain' => $domain_key,
            'base_dir' => $base_real,
            'domain_dir' => $domain_real,
            'journey_dir' => $journey_dir,
            'inventory_dir' => $inventory_dir,
            'learning_dir' => $learning_dir,
            'packages_dir' => $packages_dir,
            'schema_dir' => $schema_dir,
            'journey_log_file' => trailingslashit($journey_dir) . DBVC_CC_V2_Contracts::STORAGE_JOURNEY_LOG_FILE,
            'journey_latest_file' => trailingslashit($journey_dir) . DBVC_CC_V2_Contracts::STORAGE_JOURNEY_LATEST_FILE,
            'journey_stage_summary_file' => trailingslashit($journey_dir) . DBVC_CC_V2_Contracts::STORAGE_STAGE_SUMMARY_FILE,
            'run_profile_file' => trailingslashit($journey_dir) . DBVC_CC_V2_Contracts::STORAGE_RUN_PROFILE_FILE,
            'url_inventory_file' => trailingslashit($inventory_dir) . DBVC_CC_V2_Contracts::STORAGE_URL_INVENTORY_FILE,
            'pattern_memory_file' => trailingslashit($learning_dir) . DBVC_CC_V2_Contracts::STORAGE_DOMAIN_PATTERN_MEMORY_FILE,
            'package_builds_file' => trailingslashit($packages_dir) . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_BUILDS_FILE,
            'target_object_inventory_file' => trailingslashit($schema_dir) . DBVC_CC_V2_Contracts::STORAGE_TARGET_OBJECT_INVENTORY_FILE,
            'target_field_catalog_file' => trailingslashit($schema_dir) . DBVC_CC_V2_Contracts::STORAGE_TARGET_FIELD_CATALOG_FILE,
            'target_slot_graph_file' => trailingslashit($schema_dir) . DBVC_CC_V2_Contracts::STORAGE_TARGET_SLOT_GRAPH_FILE,
        ];

        foreach ($context as $path_key => $path) {
            if (substr((string) $path_key, -5) !== '_file') {
                continue;
            }

            if (! dbvc_cc_path_is_within((string) $path, $base_real)) {
                return new WP_Error(
                    'dbvc_cc_v2_path_outside_storage',
                    __('A V2 artifact path resolved outside the storage root.', 'dbvc'),
                    ['status' => 500]
                );
            }
        }

        return $context;
    }

    /**
     * @param string               $domain
     * @param array<string, mixed> $event
     * @return array<string, mixed>|WP_Error
     */
    public function append_event($domain, array $event)
    {
        $context = $this->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        $normalized_event = $this->normalize_event($context['domain'], $event);
        if (is_wp_error($normalized_event)) {
            return $normalized_event;
        }

        $line = wp_json_encode($normalized_event, JSON_UNESCAPED_SLASHES);
        if (! is_string($line)) {
            return new WP_Error(
                'dbvc_cc_v2_event_encode_failed',
                __('Could not encode the domain journey event.', 'dbvc'),
                ['status' => 500]
            );
        }

        if (file_put_contents($context['journey_log_file'], $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            return new WP_Error(
                'dbvc_cc_v2_event_write_failed',
                __('Could not append the domain journey event.', 'dbvc'),
                ['status' => 500]
            );
        }

        $materialized = DBVC_CC_V2_Domain_Journey_Materializer_Service::get_instance()->materialize($context);
        if (is_wp_error($materialized)) {
            return $materialized;
        }

        return [
            'event' => $normalized_event,
            'latest' => $materialized['latest'],
            'stage_summary' => $materialized['stage_summary'],
        ];
    }

    /**
     * @param string $domain
     * @return array<string, mixed>|WP_Error
     */
    public function get_latest_state($domain)
    {
        $context = $this->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        if (! file_exists($context['journey_latest_file'])) {
            $materialized = DBVC_CC_V2_Domain_Journey_Materializer_Service::get_instance()->materialize($context);
            if (is_wp_error($materialized)) {
                return $materialized;
            }
        }

        return $this->read_json_file($context['journey_latest_file']);
    }

    /**
     * @param string $domain
     * @return array<string, mixed>|WP_Error
     */
    public function get_stage_summary($domain)
    {
        $context = $this->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        if (! file_exists($context['journey_stage_summary_file'])) {
            $materialized = DBVC_CC_V2_Domain_Journey_Materializer_Service::get_instance()->materialize($context);
            if (is_wp_error($materialized)) {
                return $materialized;
            }
        }

        return $this->read_json_file($context['journey_stage_summary_file']);
    }

    /**
     * @param string $journey_id
     * @return array<string, mixed>|WP_Error
     */
    public function get_latest_state_for_run($journey_id)
    {
        $run_state = $this->get_run_materialized_state($journey_id);
        if (is_wp_error($run_state)) {
            return $run_state;
        }

        return isset($run_state['latest']) && is_array($run_state['latest'])
            ? $run_state['latest']
            : [];
    }

    /**
     * @param string $journey_id
     * @return array<string, mixed>|WP_Error
     */
    public function get_stage_summary_for_run($journey_id)
    {
        $run_state = $this->get_run_materialized_state($journey_id);
        if (is_wp_error($run_state)) {
            return $run_state;
        }

        return isset($run_state['stage_summary']) && is_array($run_state['stage_summary'])
            ? $run_state['stage_summary']
            : [];
    }

    /**
     * @param string $journey_id
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public function get_events_for_run($journey_id)
    {
        $run_state = $this->get_run_materialized_state($journey_id);
        if (is_wp_error($run_state)) {
            return $run_state;
        }

        return isset($run_state['events']) && is_array($run_state['events'])
            ? $run_state['events']
            : [];
    }

    /**
     * @param string $journey_id
     * @return string
     */
    public function find_domain_by_journey_id($journey_id)
    {
        $journey_id = sanitize_text_field((string) $journey_id);
        if ($journey_id === '') {
            return '';
        }

        foreach ($this->list_latest_states() as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (isset($entry['journey_id']) && (string) $entry['journey_id'] === $journey_id) {
                return isset($entry['domain']) ? (string) $entry['domain'] : '';
            }
        }

        foreach ($this->list_domain_keys() as $domain) {
            $events = $this->get_events($domain);
            if (is_wp_error($events) || empty($events)) {
                continue;
            }

            foreach ($events as $event) {
                if (! is_array($event)) {
                    continue;
                }

                if ((string) ($event['journey_id'] ?? '') === $journey_id) {
                    return $domain;
                }
            }
        }

        return '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list_latest_states()
    {
        $base_dir = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        if (! is_string($base_dir) || ! is_dir($base_dir)) {
            return [];
        }

        $runs = [];
        foreach ($this->list_domain_keys() as $domain) {
            $latest_file = trailingslashit($base_dir) . $domain . '/' . DBVC_CC_V2_Contracts::STORAGE_JOURNEY_SUBDIR . '/' . DBVC_CC_V2_Contracts::STORAGE_JOURNEY_LATEST_FILE;
            if (! file_exists($latest_file)) {
                continue;
            }

            $latest = $this->read_json_file($latest_file);
            if (! is_wp_error($latest)) {
                $runs[] = $latest;
            }
        }

        usort(
            $runs,
            static function ($left, $right) {
                $left_updated = isset($left['updated_at']) ? strtotime((string) $left['updated_at']) : 0;
                $right_updated = isset($right['updated_at']) ? strtotime((string) $right['updated_at']) : 0;

                if ($left_updated === $right_updated) {
                    return strnatcasecmp((string) ($left['domain'] ?? ''), (string) ($right['domain'] ?? ''));
                }

                return $right_updated <=> $left_updated;
            }
        );

        return $runs;
    }

    /**
     * @return array<int, string>
     */
    private function list_domain_keys()
    {
        $base_dir = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        if (! is_string($base_dir) || ! is_dir($base_dir)) {
            return [];
        }

        $entries = @scandir($base_dir);
        if (! is_array($entries)) {
            return [];
        }

        $domains = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || strpos($entry, '.') === 0) {
                continue;
            }

            $domain = $this->sanitize_domain_key($entry);
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * @param string $domain
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public function get_events($domain)
    {
        $context = $this->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        if (! file_exists($context['journey_log_file'])) {
            return [];
        }

        return $this->read_ndjson_file($context['journey_log_file']);
    }

    /**
     * @param string               $domain
     * @param array<string, mixed> $event
     * @return array<string, mixed>|WP_Error
     */
    private function normalize_event($domain, array $event)
    {
        $journey_id = isset($event['journey_id']) ? sanitize_text_field((string) $event['journey_id']) : '';
        $step_key = isset($event['step_key']) ? sanitize_key((string) $event['step_key']) : '';
        $step_name = isset($event['step_name']) ? sanitize_text_field((string) $event['step_name']) : '';
        if ($journey_id === '' || $step_key === '' || $step_name === '') {
            return new WP_Error(
                'dbvc_cc_v2_event_invalid',
                __('Journey events require journey_id, step_key, and step_name.', 'dbvc'),
                ['status' => 400]
            );
        }

        $status = isset($event['status']) ? sanitize_key((string) $event['status']) : 'queued';
        $started_at = isset($event['started_at']) ? sanitize_text_field((string) $event['started_at']) : current_time('c');
        $finished_at = isset($event['finished_at']) ? sanitize_text_field((string) $event['finished_at']) : $started_at;

        return [
            'journey_id' => $journey_id,
            'pipeline_version' => isset($event['pipeline_version']) && (string) $event['pipeline_version'] !== ''
                ? sanitize_text_field((string) $event['pipeline_version'])
                : DBVC_CC_V2_Contracts::PIPELINE_VERSION,
            'domain' => $domain,
            'step_key' => $step_key,
            'step_name' => $step_name,
            'status' => $status !== '' ? $status : 'queued',
            'started_at' => $started_at,
            'finished_at' => $finished_at,
            'duration_ms' => isset($event['duration_ms']) ? absint($event['duration_ms']) : 0,
            'actor' => isset($event['actor']) ? sanitize_key((string) $event['actor']) : 'system',
            'trigger' => isset($event['trigger']) ? sanitize_key((string) $event['trigger']) : 'runtime',
            'input_artifacts' => $this->sanitize_string_list(isset($event['input_artifacts']) ? $event['input_artifacts'] : []),
            'output_artifacts' => $this->sanitize_string_list(isset($event['output_artifacts']) ? $event['output_artifacts'] : []),
            'source_fingerprint' => isset($event['source_fingerprint']) ? sanitize_text_field((string) $event['source_fingerprint']) : '',
            'schema_fingerprint' => isset($event['schema_fingerprint']) ? sanitize_text_field((string) $event['schema_fingerprint']) : '',
            'message' => isset($event['message']) ? sanitize_text_field((string) $event['message']) : '',
            'warning_codes' => $this->sanitize_key_list(isset($event['warning_codes']) ? $event['warning_codes'] : []),
            'error_code' => isset($event['error_code']) ? sanitize_key((string) $event['error_code']) : '',
            'metadata' => isset($event['metadata']) && is_array($event['metadata']) ? $this->sanitize_metadata($event['metadata']) : [],
            'exception_state' => isset($event['exception_state']) ? sanitize_key((string) $event['exception_state']) : '',
            'rerun_parent_event_id' => isset($event['rerun_parent_event_id']) ? sanitize_text_field((string) $event['rerun_parent_event_id']) : '',
            'package_id' => isset($event['package_id']) ? sanitize_text_field((string) $event['package_id']) : '',
            'page_id' => isset($event['page_id']) ? sanitize_text_field((string) $event['page_id']) : '',
            'path' => isset($event['path']) ? sanitize_text_field((string) $event['path']) : '',
            'source_url' => isset($event['source_url']) ? esc_url_raw((string) $event['source_url']) : '',
            'override_scope' => isset($event['override_scope']) ? sanitize_key((string) $event['override_scope']) : '',
            'override_target' => isset($event['override_target']) ? sanitize_text_field((string) $event['override_target']) : '',
        ];
    }

    /**
     * @param string $domain
     * @return string
     */
    private function sanitize_domain_key($domain)
    {
        $domain = trim((string) $domain);
        if ($domain === '') {
            return '';
        }

        if (strpos($domain, '://') !== false) {
            $host = wp_parse_url($domain, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $domain = $host;
            }
        }

        $domain = strtolower($domain);
        $domain = preg_replace('/[^a-z0-9.-]/', '', $domain);

        return is_string($domain) ? $domain : '';
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function sanitize_string_list($value)
    {
        if (! is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            $sanitized = sanitize_text_field((string) $item);
            if ($sanitized !== '') {
                $list[] = $sanitized;
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function sanitize_key_list($value)
    {
        if (! is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            $sanitized = sanitize_key((string) $item);
            if ($sanitized !== '') {
                $list[] = $sanitized;
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function sanitize_metadata(array $metadata)
    {
        $normalized = [];
        foreach ($metadata as $key => $value) {
            $normalized_key = sanitize_key((string) $key);
            if ($normalized_key === '') {
                continue;
            }

            if (is_array($value)) {
                $normalized[$normalized_key] = $this->sanitize_metadata($value);
                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value)) {
                $normalized[$normalized_key] = $value;
                continue;
            }

            $normalized[$normalized_key] = sanitize_text_field((string) $value);
        }

        ksort($normalized);
        return $normalized;
    }

    /**
     * @param string $journey_id
     * @return array<string, mixed>|WP_Error
     */
    private function get_run_materialized_state($journey_id)
    {
        $journey_id = sanitize_text_field((string) $journey_id);
        if ($journey_id === '') {
            return new WP_Error(
                'dbvc_cc_v2_run_missing',
                __('The requested V2 run could not be found.', 'dbvc'),
                ['status' => 404]
            );
        }

        $domain = $this->find_domain_by_journey_id($journey_id);
        if ($domain === '') {
            return new WP_Error(
                'dbvc_cc_v2_run_missing',
                __('The requested V2 run could not be found.', 'dbvc'),
                ['status' => 404]
            );
        }

        $events = $this->get_events($domain);
        if (is_wp_error($events)) {
            return $events;
        }

        $run_events = [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            if ((string) ($event['journey_id'] ?? '') !== $journey_id) {
                continue;
            }

            $run_events[] = $event;
        }

        if (empty($run_events)) {
            return new WP_Error(
                'dbvc_cc_v2_run_missing',
                __('The requested V2 run could not be found.', 'dbvc'),
                ['status' => 404]
            );
        }

        $materializer = DBVC_CC_V2_Domain_Journey_Materializer_Service::get_instance();

        return [
            'domain' => $domain,
            'events' => $run_events,
            'latest' => $materializer->build_latest_state_for_events($domain, $run_events),
            'stage_summary' => $materializer->build_stage_summary_for_events($domain, $run_events),
        ];
    }

    /**
     * @param string $path
     * @return array<string, mixed>|WP_Error
     */
    private function read_json_file($path)
    {
        $path = (string) $path;
        if ($path === '' || ! file_exists($path)) {
            return new WP_Error(
                'dbvc_cc_v2_artifact_missing',
                __('The requested V2 artifact does not exist.', 'dbvc'),
                ['status' => 404]
            );
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return new WP_Error(
                'dbvc_cc_v2_artifact_unreadable',
                __('The requested V2 artifact could not be read.', 'dbvc'),
                ['status' => 500]
            );
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return new WP_Error(
                'dbvc_cc_v2_artifact_invalid',
                __('The requested V2 artifact contains invalid JSON.', 'dbvc'),
                ['status' => 500]
            );
        }

        return $decoded;
    }

    /**
     * @param string $path
     * @return array<int, array<string, mixed>>|WP_Error
     */
    private function read_ndjson_file($path)
    {
        $path = (string) $path;
        if ($path === '' || ! file_exists($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return new WP_Error(
                'dbvc_cc_v2_journey_unreadable',
                __('The requested V2 journey log could not be read.', 'dbvc'),
                ['status' => 500]
            );
        }

        $events = [];
        foreach ($lines as $line) {
            if (! is_string($line) || trim($line) === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $events;
    }
}
