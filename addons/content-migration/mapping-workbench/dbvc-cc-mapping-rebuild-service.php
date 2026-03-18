<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Mapping_Rebuild_Service
{
    public const DBVC_CC_BATCH_SCHEMA_VERSION = '1.0';

    /**
     * @var DBVC_CC_Mapping_Rebuild_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_Mapping_Rebuild_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action(DBVC_CC_Contracts::CRON_HOOK_MAPPING_REBUILD_BATCH, [$this, 'dbvc_cc_process_rebuild_batch_event'], 10, 1);
    }

    /**
     * @param string              $dbvc_cc_domain
     * @param array<string, mixed> $dbvc_cc_args
     * @return array<string, mixed>|WP_Error
     */
    public function dbvc_cc_queue_domain_mapping_rebuild($dbvc_cc_domain, array $dbvc_cc_args = [])
    {
        $dbvc_cc_domain = $this->dbvc_cc_sanitize_domain($dbvc_cc_domain);
        if ($dbvc_cc_domain === '') {
            return new WP_Error(
                'dbvc_cc_mapping_rebuild_invalid_domain',
                __('A valid domain is required.', 'dbvc'),
                ['status' => 400]
            );
        }

        $dbvc_cc_force_rebuild = isset($dbvc_cc_args['force_rebuild'])
            ? rest_sanitize_boolean($dbvc_cc_args['force_rebuild'])
            : true;
        $dbvc_cc_refresh_catalog = isset($dbvc_cc_args['refresh_catalog'])
            ? rest_sanitize_boolean($dbvc_cc_args['refresh_catalog'])
            : false;
        $dbvc_cc_run_now = isset($dbvc_cc_args['run_now'])
            ? rest_sanitize_boolean($dbvc_cc_args['run_now'])
            : false;
        $dbvc_cc_batch_size = isset($dbvc_cc_args['batch_size']) ? absint($dbvc_cc_args['batch_size']) : 20;
        $dbvc_cc_batch_size = max(1, min(200, $dbvc_cc_batch_size));
        $dbvc_cc_requested_by = isset($dbvc_cc_args['requested_by']) ? absint($dbvc_cc_args['requested_by']) : 0;

        $dbvc_cc_paths = DBVC_CC_Artifact_Manager::dbvc_cc_list_domain_relative_paths($dbvc_cc_domain);
        if (empty($dbvc_cc_paths)) {
            return new WP_Error(
                'dbvc_cc_mapping_rebuild_domain_empty',
                __('No page artifacts were found for the selected domain.', 'dbvc'),
                ['status' => 404]
            );
        }

        $dbvc_cc_catalog_result = DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($dbvc_cc_domain, $dbvc_cc_refresh_catalog);
        if (is_wp_error($dbvc_cc_catalog_result)) {
            return $dbvc_cc_catalog_result;
        }

        $dbvc_cc_catalog_fingerprint = isset($dbvc_cc_catalog_result['catalog_fingerprint'])
            ? (string) $dbvc_cc_catalog_result['catalog_fingerprint']
            : '';
        $dbvc_cc_batch_id = 'dbvc_cc_mrb_' . substr(md5(wp_generate_uuid4() . microtime(true)), 0, 12);
        $dbvc_cc_total_jobs = count($dbvc_cc_paths);

        $dbvc_cc_batch_payload = [
            'schema_version' => self::DBVC_CC_BATCH_SCHEMA_VERSION,
            'batch_id' => $dbvc_cc_batch_id,
            'status' => 'queued',
            'requested_at' => current_time('c'),
            'requested_by' => $dbvc_cc_requested_by,
            'updated_at' => current_time('c'),
            'domain' => $dbvc_cc_domain,
            'catalog_fingerprint' => $dbvc_cc_catalog_fingerprint,
            'refresh_catalog' => $dbvc_cc_refresh_catalog,
            'force_rebuild' => $dbvc_cc_force_rebuild,
            'batch_size' => $dbvc_cc_batch_size,
            'total_jobs' => $dbvc_cc_total_jobs,
            'processed_jobs' => 0,
            'queued_jobs' => $dbvc_cc_total_jobs,
            'failed_jobs' => 0,
            'section_built_count' => 0,
            'media_built_count' => 0,
            'cursor' => 0,
            'progress_percent' => 0,
            'paths' => array_values($dbvc_cc_paths),
            'errors' => [],
            'recent_jobs' => [],
            'message' => sprintf(
                /* translators: %d: queued job count */
                __('Queued %d mapping rebuild jobs.', 'dbvc'),
                $dbvc_cc_total_jobs
            ),
        ];

        $this->dbvc_cc_set_batch_payload($dbvc_cc_batch_id, $dbvc_cc_batch_payload);

        DBVC_CC_Artifact_Manager::log_event(
            [
                'stage' => 'mapping_workbench',
                'status' => 'queued',
                'page_url' => 'https://' . $dbvc_cc_domain . '/',
                'path' => '',
                'job_id' => $dbvc_cc_batch_id,
                'message' => $dbvc_cc_batch_payload['message'],
            ]
        );

        if ($dbvc_cc_run_now) {
            return $this->dbvc_cc_process_batch_until_complete($dbvc_cc_batch_id);
        }

        $this->dbvc_cc_schedule_batch_event($dbvc_cc_batch_id, 2);
        if (function_exists('spawn_cron')) {
            @spawn_cron(time());
        }

        return $this->dbvc_cc_format_batch_status($dbvc_cc_batch_payload);
    }

    /**
     * @param string $dbvc_cc_batch_id
     * @return array<string, mixed>|WP_Error
     */
    public function dbvc_cc_get_batch_status($dbvc_cc_batch_id)
    {
        $dbvc_cc_batch_id = sanitize_key((string) $dbvc_cc_batch_id);
        if ($dbvc_cc_batch_id === '') {
            return new WP_Error(
                'dbvc_cc_mapping_rebuild_invalid_batch',
                __('A valid batch ID is required.', 'dbvc'),
                ['status' => 400]
            );
        }

        $dbvc_cc_batch = $this->dbvc_cc_get_batch_payload($dbvc_cc_batch_id);
        if (! is_array($dbvc_cc_batch)) {
            return new WP_Error(
                'dbvc_cc_mapping_rebuild_batch_not_found',
                __('Mapping rebuild batch not found.', 'dbvc'),
                ['status' => 404]
            );
        }

        return $this->dbvc_cc_format_batch_status($dbvc_cc_batch);
    }

    /**
     * @param string $dbvc_cc_batch_id
     * @return array<string, mixed>|WP_Error
     */
    public function dbvc_cc_process_rebuild_batch_event($dbvc_cc_batch_id)
    {
        $dbvc_cc_batch_id = sanitize_key((string) $dbvc_cc_batch_id);
        if ($dbvc_cc_batch_id === '') {
            return new WP_Error(
                'dbvc_cc_mapping_rebuild_invalid_batch',
                __('A valid batch ID is required.', 'dbvc'),
                ['status' => 400]
            );
        }

        $dbvc_cc_batch = $this->dbvc_cc_get_batch_payload($dbvc_cc_batch_id);
        if (! is_array($dbvc_cc_batch)) {
            return new WP_Error(
                'dbvc_cc_mapping_rebuild_batch_not_found',
                __('Mapping rebuild batch not found.', 'dbvc'),
                ['status' => 404]
            );
        }

        if (in_array((string) $dbvc_cc_batch['status'], ['completed', 'completed_with_failures', 'failed'], true)) {
            return $this->dbvc_cc_format_batch_status($dbvc_cc_batch);
        }

        $dbvc_cc_lock_key = $this->dbvc_cc_get_batch_lock_key($dbvc_cc_batch_id);
        if (get_transient($dbvc_cc_lock_key)) {
            return $this->dbvc_cc_format_batch_status($dbvc_cc_batch);
        }

        set_transient($dbvc_cc_lock_key, 1, 30);

        try {
            $dbvc_cc_paths = isset($dbvc_cc_batch['paths']) && is_array($dbvc_cc_batch['paths'])
                ? array_values($dbvc_cc_batch['paths'])
                : [];
            $dbvc_cc_total_jobs = count($dbvc_cc_paths);
            $dbvc_cc_cursor = isset($dbvc_cc_batch['cursor']) ? absint($dbvc_cc_batch['cursor']) : 0;
            $dbvc_cc_batch_size = isset($dbvc_cc_batch['batch_size']) ? absint($dbvc_cc_batch['batch_size']) : 20;
            $dbvc_cc_batch_size = max(1, min(200, $dbvc_cc_batch_size));
            $dbvc_cc_processed_now = 0;
            $dbvc_cc_processed_total = isset($dbvc_cc_batch['processed_jobs']) ? absint($dbvc_cc_batch['processed_jobs']) : 0;
            $dbvc_cc_failed_total = isset($dbvc_cc_batch['failed_jobs']) ? absint($dbvc_cc_batch['failed_jobs']) : 0;
            $dbvc_cc_section_built_total = isset($dbvc_cc_batch['section_built_count']) ? absint($dbvc_cc_batch['section_built_count']) : 0;
            $dbvc_cc_media_built_total = isset($dbvc_cc_batch['media_built_count']) ? absint($dbvc_cc_batch['media_built_count']) : 0;
            $dbvc_cc_recent_jobs = isset($dbvc_cc_batch['recent_jobs']) && is_array($dbvc_cc_batch['recent_jobs'])
                ? array_values($dbvc_cc_batch['recent_jobs'])
                : [];
            $dbvc_cc_errors = isset($dbvc_cc_batch['errors']) && is_array($dbvc_cc_batch['errors'])
                ? array_values($dbvc_cc_batch['errors'])
                : [];

            $dbvc_cc_batch['status'] = 'processing';
            while ($dbvc_cc_cursor < $dbvc_cc_total_jobs && $dbvc_cc_processed_now < $dbvc_cc_batch_size) {
                $dbvc_cc_path = isset($dbvc_cc_paths[$dbvc_cc_cursor]) ? (string) $dbvc_cc_paths[$dbvc_cc_cursor] : '';
                $dbvc_cc_cursor++;
                if ($dbvc_cc_path === '') {
                    continue;
                }

                $dbvc_cc_processed_now++;
                $dbvc_cc_processed_total++;

                $dbvc_cc_result = DBVC_CC_Workbench_Service::get_instance()->dbvc_cc_rebuild_mapping_artifacts_for_path(
                    isset($dbvc_cc_batch['domain']) ? (string) $dbvc_cc_batch['domain'] : '',
                    $dbvc_cc_path,
                    ! empty($dbvc_cc_batch['force_rebuild'])
                );

                if (is_wp_error($dbvc_cc_result)) {
                    $dbvc_cc_failed_total++;
                    if (count($dbvc_cc_errors) < 100) {
                        $dbvc_cc_errors[] = [
                            'path' => $dbvc_cc_path,
                            'code' => $dbvc_cc_result->get_error_code(),
                            'message' => $dbvc_cc_result->get_error_message(),
                        ];
                    }

                    $dbvc_cc_recent_jobs[] = [
                        'path' => $dbvc_cc_path,
                        'status' => 'failed',
                        'section_status' => 'error',
                        'media_status' => 'error',
                    ];
                    continue;
                }

                $dbvc_cc_row = is_array($dbvc_cc_result) ? $dbvc_cc_result : [];
                if ((isset($dbvc_cc_row['section_status']) ? (string) $dbvc_cc_row['section_status'] : '') === 'built') {
                    $dbvc_cc_section_built_total++;
                }
                if ((isset($dbvc_cc_row['media_status']) ? (string) $dbvc_cc_row['media_status'] : '') === 'built') {
                    $dbvc_cc_media_built_total++;
                }
                $dbvc_cc_row_has_error = ! empty($dbvc_cc_row['has_error']);
                if ($dbvc_cc_row_has_error) {
                    $dbvc_cc_failed_total++;
                    if (count($dbvc_cc_errors) < 100) {
                        $dbvc_cc_errors[] = [
                            'path' => $dbvc_cc_path,
                            'code' => 'mapping_rebuild_partial_failure',
                            'message' => __('One or more candidate rebuilds failed for this path.', 'dbvc'),
                        ];
                    }
                }

                $dbvc_cc_recent_jobs[] = [
                    'path' => $dbvc_cc_path,
                    'status' => $dbvc_cc_row_has_error ? 'failed' : 'completed',
                    'section_status' => isset($dbvc_cc_row['section_status']) ? (string) $dbvc_cc_row['section_status'] : 'unknown',
                    'media_status' => isset($dbvc_cc_row['media_status']) ? (string) $dbvc_cc_row['media_status'] : 'unknown',
                ];
            }

            if (count($dbvc_cc_recent_jobs) > 25) {
                $dbvc_cc_recent_jobs = array_slice($dbvc_cc_recent_jobs, -25);
            }

            $dbvc_cc_remaining_jobs = max(0, $dbvc_cc_total_jobs - $dbvc_cc_processed_total);
            $dbvc_cc_progress_percent = $dbvc_cc_total_jobs > 0
                ? (int) round(($dbvc_cc_processed_total / $dbvc_cc_total_jobs) * 100)
                : 0;

            $dbvc_cc_batch['cursor'] = $dbvc_cc_cursor;
            $dbvc_cc_batch['processed_jobs'] = $dbvc_cc_processed_total;
            $dbvc_cc_batch['failed_jobs'] = $dbvc_cc_failed_total;
            $dbvc_cc_batch['section_built_count'] = $dbvc_cc_section_built_total;
            $dbvc_cc_batch['media_built_count'] = $dbvc_cc_media_built_total;
            $dbvc_cc_batch['queued_jobs'] = $dbvc_cc_remaining_jobs;
            $dbvc_cc_batch['progress_percent'] = $dbvc_cc_progress_percent;
            $dbvc_cc_batch['updated_at'] = current_time('c');
            $dbvc_cc_batch['errors'] = $dbvc_cc_errors;
            $dbvc_cc_batch['recent_jobs'] = $dbvc_cc_recent_jobs;

            if ($dbvc_cc_processed_total >= $dbvc_cc_total_jobs) {
                $dbvc_cc_batch['status'] = $dbvc_cc_failed_total > 0 ? 'completed_with_failures' : 'completed';
                $dbvc_cc_batch['message'] = sprintf(
                    /* translators: 1: processed job count, 2: failed job count */
                    __('Processed %1$d mapping rebuild jobs. Failures: %2$d.', 'dbvc'),
                    $dbvc_cc_processed_total,
                    $dbvc_cc_failed_total
                );
            } else {
                $dbvc_cc_batch['status'] = 'processing';
                $dbvc_cc_batch['message'] = sprintf(
                    /* translators: 1: processed job count, 2: total job count */
                    __('Processed %1$d of %2$d mapping rebuild jobs.', 'dbvc'),
                    $dbvc_cc_processed_total,
                    $dbvc_cc_total_jobs
                );
            }

            $this->dbvc_cc_set_batch_payload($dbvc_cc_batch_id, $dbvc_cc_batch);

            if ($dbvc_cc_batch['status'] === 'processing') {
                $this->dbvc_cc_schedule_batch_event($dbvc_cc_batch_id, 2);
                if (function_exists('spawn_cron')) {
                    @spawn_cron(time());
                }
            } else {
                DBVC_CC_Artifact_Manager::log_event(
                    [
                        'stage' => 'mapping_workbench',
                        'status' => $dbvc_cc_batch['status'],
                        'page_url' => 'https://' . (isset($dbvc_cc_batch['domain']) ? (string) $dbvc_cc_batch['domain'] : '') . '/',
                        'path' => '',
                        'job_id' => $dbvc_cc_batch_id,
                        'message' => (string) $dbvc_cc_batch['message'],
                    ]
                );
            }

            return $this->dbvc_cc_format_batch_status($dbvc_cc_batch);
        } finally {
            delete_transient($dbvc_cc_lock_key);
        }
    }

    /**
     * @param string $dbvc_cc_batch_id
     * @return array<string, mixed>|WP_Error
     */
    private function dbvc_cc_process_batch_until_complete($dbvc_cc_batch_id)
    {
        $dbvc_cc_guard = 0;
        while ($dbvc_cc_guard < 2000) {
            $dbvc_cc_guard++;
            $dbvc_cc_result = $this->dbvc_cc_process_rebuild_batch_event($dbvc_cc_batch_id);
            if (is_wp_error($dbvc_cc_result)) {
                return $dbvc_cc_result;
            }

            $dbvc_cc_status = isset($dbvc_cc_result['status']) ? (string) $dbvc_cc_result['status'] : '';
            if (in_array($dbvc_cc_status, ['completed', 'completed_with_failures', 'failed'], true)) {
                return $dbvc_cc_result;
            }
        }

        return $this->dbvc_cc_get_batch_status($dbvc_cc_batch_id);
    }

    /**
     * @param string $dbvc_cc_batch_id
     * @return string
     */
    private function dbvc_cc_get_batch_transient_key($dbvc_cc_batch_id)
    {
        return DBVC_CC_Contracts::TRANSIENT_PREFIX_MAPPING_REBUILD_BATCH . sanitize_key((string) $dbvc_cc_batch_id);
    }

    /**
     * @param string $dbvc_cc_batch_id
     * @return string
     */
    private function dbvc_cc_get_batch_lock_key($dbvc_cc_batch_id)
    {
        return $this->dbvc_cc_get_batch_transient_key($dbvc_cc_batch_id) . '_lock';
    }

    /**
     * @param string $dbvc_cc_batch_id
     * @return array<string, mixed>|null
     */
    private function dbvc_cc_get_batch_payload($dbvc_cc_batch_id)
    {
        $dbvc_cc_payload = get_transient($this->dbvc_cc_get_batch_transient_key($dbvc_cc_batch_id));
        return is_array($dbvc_cc_payload) ? $dbvc_cc_payload : null;
    }

    /**
     * @param string              $dbvc_cc_batch_id
     * @param array<string, mixed> $dbvc_cc_payload
     * @return void
     */
    private function dbvc_cc_set_batch_payload($dbvc_cc_batch_id, array $dbvc_cc_payload)
    {
        set_transient($this->dbvc_cc_get_batch_transient_key($dbvc_cc_batch_id), $dbvc_cc_payload, DAY_IN_SECONDS);
    }

    /**
     * @param string $dbvc_cc_batch_id
     * @param int    $dbvc_cc_delay
     * @return void
     */
    private function dbvc_cc_schedule_batch_event($dbvc_cc_batch_id, $dbvc_cc_delay = 2)
    {
        $dbvc_cc_args = [sanitize_key((string) $dbvc_cc_batch_id)];
        if (wp_next_scheduled(DBVC_CC_Contracts::CRON_HOOK_MAPPING_REBUILD_BATCH, $dbvc_cc_args)) {
            return;
        }

        wp_schedule_single_event(time() + max(1, absint($dbvc_cc_delay)), DBVC_CC_Contracts::CRON_HOOK_MAPPING_REBUILD_BATCH, $dbvc_cc_args);
    }

    /**
     * @param array<string, mixed> $dbvc_cc_batch
     * @return array<string, mixed>
     */
    private function dbvc_cc_format_batch_status(array $dbvc_cc_batch)
    {
        $dbvc_cc_total_jobs = isset($dbvc_cc_batch['total_jobs']) ? absint($dbvc_cc_batch['total_jobs']) : 0;
        $dbvc_cc_processed_jobs = isset($dbvc_cc_batch['processed_jobs']) ? absint($dbvc_cc_batch['processed_jobs']) : 0;
        $dbvc_cc_failed_jobs = isset($dbvc_cc_batch['failed_jobs']) ? absint($dbvc_cc_batch['failed_jobs']) : 0;
        $dbvc_cc_remaining_jobs = max(0, $dbvc_cc_total_jobs - $dbvc_cc_processed_jobs);
        $dbvc_cc_progress_percent = $dbvc_cc_total_jobs > 0
            ? (int) round(($dbvc_cc_processed_jobs / $dbvc_cc_total_jobs) * 100)
            : 0;
        $dbvc_cc_success_jobs = max(0, $dbvc_cc_processed_jobs - $dbvc_cc_failed_jobs);
        $dbvc_cc_status = isset($dbvc_cc_batch['status']) ? sanitize_key((string) $dbvc_cc_batch['status']) : 'queued';
        $dbvc_cc_legacy_status = 'queued';
        if ($dbvc_cc_status === 'completed') {
            $dbvc_cc_legacy_status = 'rebuilt';
        } elseif ($dbvc_cc_status === 'completed_with_failures') {
            $dbvc_cc_legacy_status = $dbvc_cc_success_jobs > 0 ? 'partial' : 'failed';
        } elseif ($dbvc_cc_status === 'failed') {
            $dbvc_cc_legacy_status = 'failed';
        }

        return [
            'schema_version' => self::DBVC_CC_BATCH_SCHEMA_VERSION,
            'batch_id' => isset($dbvc_cc_batch['batch_id']) ? sanitize_key((string) $dbvc_cc_batch['batch_id']) : '',
            'status' => $dbvc_cc_status,
            'legacy_status' => $dbvc_cc_legacy_status,
            'domain' => isset($dbvc_cc_batch['domain']) ? sanitize_text_field((string) $dbvc_cc_batch['domain']) : '',
            'requested_at' => isset($dbvc_cc_batch['requested_at']) ? (string) $dbvc_cc_batch['requested_at'] : null,
            'updated_at' => isset($dbvc_cc_batch['updated_at']) ? (string) $dbvc_cc_batch['updated_at'] : null,
            'requested_by' => isset($dbvc_cc_batch['requested_by']) ? absint($dbvc_cc_batch['requested_by']) : 0,
            'catalog_fingerprint' => isset($dbvc_cc_batch['catalog_fingerprint']) ? (string) $dbvc_cc_batch['catalog_fingerprint'] : '',
            'force_rebuild' => ! empty($dbvc_cc_batch['force_rebuild']),
            'refresh_catalog' => ! empty($dbvc_cc_batch['refresh_catalog']),
            'batch_size' => isset($dbvc_cc_batch['batch_size']) ? absint($dbvc_cc_batch['batch_size']) : 20,
            'total_jobs' => $dbvc_cc_total_jobs,
            'processed_jobs' => $dbvc_cc_processed_jobs,
            'success_jobs' => $dbvc_cc_success_jobs,
            'remaining_jobs' => $dbvc_cc_remaining_jobs,
            'queued_jobs' => $dbvc_cc_remaining_jobs,
            'failed_jobs' => $dbvc_cc_failed_jobs,
            'section_built_count' => isset($dbvc_cc_batch['section_built_count']) ? absint($dbvc_cc_batch['section_built_count']) : 0,
            'media_built_count' => isset($dbvc_cc_batch['media_built_count']) ? absint($dbvc_cc_batch['media_built_count']) : 0,
            'progress_percent' => $dbvc_cc_progress_percent,
            'errors' => isset($dbvc_cc_batch['errors']) && is_array($dbvc_cc_batch['errors']) ? array_values($dbvc_cc_batch['errors']) : [],
            'recent_jobs' => isset($dbvc_cc_batch['recent_jobs']) && is_array($dbvc_cc_batch['recent_jobs']) ? array_values($dbvc_cc_batch['recent_jobs']) : [],
            // Backward-compat aliases for the previous synchronous payload contract.
            'total_paths' => $dbvc_cc_total_jobs,
            'success_paths' => $dbvc_cc_success_jobs,
            'failed_paths' => $dbvc_cc_failed_jobs,
            'pages' => isset($dbvc_cc_batch['recent_jobs']) && is_array($dbvc_cc_batch['recent_jobs']) ? array_values($dbvc_cc_batch['recent_jobs']) : [],
            'message' => isset($dbvc_cc_batch['message']) ? sanitize_text_field((string) $dbvc_cc_batch['message']) : '',
        ];
    }

    /**
     * @param string $dbvc_cc_domain
     * @return string
     */
    private function dbvc_cc_sanitize_domain($dbvc_cc_domain)
    {
        return preg_replace('/[^a-z0-9.-]/', '', strtolower((string) $dbvc_cc_domain));
    }
}
