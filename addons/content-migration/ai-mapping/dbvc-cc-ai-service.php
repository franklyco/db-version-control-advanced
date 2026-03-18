<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_AI_Service
{
    public const JOB_TRANSIENT_PREFIX = DBVC_CC_Contracts::TRANSIENT_PREFIX_AI_JOB;
    public const BATCH_TRANSIENT_PREFIX = DBVC_CC_Contracts::TRANSIENT_PREFIX_AI_BATCH;
    public const REVIEW_CONFIDENCE_THRESHOLD = 0.75;

    private static $instance = null;
    private $options;

    /**
     * Singleton bootstrap.
     *
     * @return DBVC_CC_AI_Service
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct()
    {
        $this->options = DBVC_CC_Settings_Service::get_options();
        add_action(DBVC_CC_Contracts::CRON_HOOK_AI_PROCESS_JOB, [$this, 'process_job'], 10, 3);
    }

    /**
     * Queues and optionally runs an AI job immediately.
     *
     * @param string $domain       Domain key.
     * @param string $path         Relative page path.
     * @param string $trigger      Trigger source.
     * @param int    $requested_by User ID.
     * @param bool   $run_now      Whether to process immediately.
     * @param bool   $spawn_cron  Whether to trigger cron spawn.
     * @return array|WP_Error
     */
    public function queue_job($domain, $path, $trigger = 'manual_rerun', $requested_by = 0, $run_now = true, $spawn_cron = true) {
        $context = $this->resolve_page_context($domain, $path);
        if (is_wp_error($context)) {
            return $context;
        }

        $job_id = 'dbvc_cc_ai_' . substr(md5(wp_generate_uuid4() . microtime(true)), 0, 12);
        $status = [
            'schema_version' => '1.0',
            'job_id'         => $job_id,
            'status'         => 'queued',
            'mode'           => 'pending',
            'trigger'        => $trigger,
            'attempts'       => 0,
            'requested_at'   => current_time('c'),
            'requested_by'   => absint($requested_by),
            'started_at'     => null,
            'finished_at'    => null,
            'prompt_version' => isset($this->options['prompt_version']) ? (string) $this->options['prompt_version'] : 'v1',
            'model'          => isset($this->options['openai_model']) ? (string) $this->options['openai_model'] : 'gpt-4o-mini',
            'source_url'     => $context['source_url'],
            'domain'         => $context['domain'],
            'path'           => $context['path'],
            'analysis_file'  => basename($context['analysis_file']),
            'sanitized_file' => basename($context['sanitized_file']),
            'message'        => __('AI job queued.', 'dbvc'),
            'error'          => null,
        ];

        if (!DBVC_CC_Artifact_Manager::write_json_file($context['status_file'], $status)) {
            return new WP_Error('dbvc_cc_ai_queue_write', __('Could not write AI status file.', 'dbvc'), ['status' => 500]);
        }

        set_transient(
            $this->get_job_transient_key($job_id),
            [
                'domain' => $context['domain'],
                'path'   => $context['path'],
            ],
            DAY_IN_SECONDS
        );

        DBVC_CC_Artifact_Manager::log_event(
            [
                'stage'    => 'ai',
                'status'   => 'queued',
                'page_url' => $context['source_url'],
                'path'     => $context['path'],
                'job_id'   => $job_id,
                'message'  => 'AI job queued.',
            ]
        );

        wp_schedule_single_event(time() + 2, DBVC_CC_Contracts::CRON_HOOK_AI_PROCESS_JOB, [$context['domain'], $context['path'], $job_id]);
        if ($spawn_cron && function_exists('spawn_cron')) {
            @spawn_cron(time());
        }

        if ($run_now) {
            $this->process_job($context['domain'], $context['path'], $job_id);
            $latest = $this->get_status($context['domain'], $context['path']);
            if (!is_wp_error($latest)) {
                return $latest;
            }
        }

        return $status;
    }

    /**
     * Queues AI jobs for all page artifacts in a subtree and returns a batch payload.
     *
     * @param string $domain       Domain key.
     * @param string $path         Relative subtree path.
     * @param string $trigger      Trigger source.
     * @param int    $requested_by User ID.
     * @param bool   $run_now      Whether to process immediately.
     * @param int    $max_jobs     Maximum jobs to queue.
     * @param int    $offset       Zero-based candidate offset.
     * @return array|WP_Error
     */
    public function queue_branch_jobs($domain, $path, $trigger = 'manual_branch_rerun', $requested_by = 0, $run_now = false, $max_jobs = 150, $offset = 0) {
        $subtree_context = $this->resolve_subtree_context($domain, $path);
        if (is_wp_error($subtree_context)) {
            return $subtree_context;
        }

        $dbvc_cc_max_jobs = absint($max_jobs);
        if ($dbvc_cc_max_jobs > 0) {
            $dbvc_cc_max_jobs = max(1, min(400, $dbvc_cc_max_jobs));
        }
        $dbvc_cc_offset = absint($offset);
        $page_paths = $this->discover_page_paths_in_subtree($subtree_context['domain_dir'], $subtree_context['target_dir']);
        if (empty($page_paths)) {
            return new WP_Error(
                'dbvc_cc_ai_branch_empty',
                __('No page artifacts were found under this branch.', 'dbvc'),
                ['status' => 400]
            );
        }

        $dbvc_cc_total_candidate_paths = count($page_paths);
        if ($dbvc_cc_offset > 0) {
            if ($dbvc_cc_offset >= $dbvc_cc_total_candidate_paths) {
                return new WP_Error(
                    'dbvc_cc_ai_branch_offset_out_of_range',
                    __('Branch AI rerun offset is out of range.', 'dbvc'),
                    [
                        'status' => 400,
                        'total_candidate_jobs' => $dbvc_cc_total_candidate_paths,
                    ]
                );
            }

            $page_paths = array_slice($page_paths, $dbvc_cc_offset);
        }

        $dbvc_cc_remaining_candidate_paths = count($page_paths);
        if ($dbvc_cc_max_jobs > 0 && $dbvc_cc_remaining_candidate_paths > $dbvc_cc_max_jobs) {
            $page_paths = array_slice($page_paths, 0, $dbvc_cc_max_jobs);
        }

        $batch_id = 'dbvc_cc_aib_' . substr(md5(wp_generate_uuid4() . microtime(true)), 0, 12);
        $jobs = [];
        $queue_errors = [];

        foreach ($page_paths as $page_path) {
            $queued = $this->queue_job($subtree_context['domain'], $page_path, $trigger, $requested_by, false, false);
            if (is_wp_error($queued)) {
                $queue_errors[] = [
                    'path'    => $page_path,
                    'code'    => $queued->get_error_code(),
                    'message' => $queued->get_error_message(),
                ];
                continue;
            }

            $jobs[] = [
                'job_id'  => isset($queued['job_id']) ? sanitize_key((string) $queued['job_id']) : '',
                'domain'  => $subtree_context['domain'],
                'path'    => $page_path,
                'status'  => isset($queued['status']) ? sanitize_key((string) $queued['status']) : 'queued',
                'message' => isset($queued['message']) ? sanitize_text_field((string) $queued['message']) : '',
            ];
        }

        if (empty($jobs)) {
            return new WP_Error(
                'dbvc_cc_ai_branch_queue_failed',
                __('Branch AI rerun could not queue any jobs.', 'dbvc'),
                [
                    'status' => 500,
                    'errors' => $queue_errors,
                ]
            );
        }

        $batch_payload = [
            'schema_version' => '1.0',
            'batch_id'       => $batch_id,
            'status'         => 'queued',
            'trigger'        => $trigger,
            'requested_at'   => current_time('c'),
            'requested_by'   => absint($requested_by),
            'domain'         => $subtree_context['domain'],
            'path'           => $subtree_context['path'],
            'offset'         => $dbvc_cc_offset,
            'next_offset'    => $dbvc_cc_offset + count($jobs),
            'remaining_candidate_jobs' => max(0, $dbvc_cc_total_candidate_paths - ($dbvc_cc_offset + count($jobs))),
            'total_jobs'     => count($jobs),
            'queued_jobs'    => count($jobs),
            'failed_jobs'    => count($queue_errors),
            'run_now'        => !empty($run_now),
            'max_jobs'       => $dbvc_cc_max_jobs,
            'total_candidate_jobs' => $dbvc_cc_total_candidate_paths,
            'was_truncated'  => ($dbvc_cc_offset + count($jobs)) < $dbvc_cc_total_candidate_paths,
            'jobs'           => $jobs,
            'errors'         => $queue_errors,
            'message'        => sprintf(
                /* translators: 1: queued job count, 2: error count */
                __('Queued %1$d AI jobs for branch rerun. Queue errors: %2$d.', 'dbvc'),
                count($jobs),
                count($queue_errors)
            ),
        ];

        set_transient($this->get_batch_transient_key($batch_id), $batch_payload, DAY_IN_SECONDS);

        if (function_exists('spawn_cron')) {
            @spawn_cron(time());
        }

        DBVC_CC_Artifact_Manager::log_event(
            [
                'stage'    => 'ai',
                'status'   => 'queued',
                'job_id'   => $batch_id,
                'path'     => $subtree_context['path'],
                'page_url' => '',
                'message'  => $batch_payload['message'],
            ]
        );

        if ($run_now) {
            foreach ($jobs as $job) {
                if (empty($job['job_id'])) {
                    continue;
                }
                $this->process_job($job['domain'], $job['path'], $job['job_id']);
            }
            return $this->get_status_by_batch_id($batch_id);
        }

        return $batch_payload;
    }

    /**
     * Queues a full-domain AI refresh for all discovered page artifact paths.
     *
     * @param string $domain       Domain key.
     * @param string $trigger      Trigger source.
     * @param int    $requested_by User ID.
     * @param bool   $run_now      Whether to process immediately.
     * @return array|WP_Error
     */
    public function dbvc_cc_queue_domain_refresh($domain, $trigger = 'crawl_domain_refresh', $requested_by = 0, $run_now = false)
    {
        $dbvc_cc_result = $this->queue_branch_jobs($domain, '', $trigger, $requested_by, $run_now, 0);
        if (is_wp_error($dbvc_cc_result)) {
            return $dbvc_cc_result;
        }

        $dbvc_cc_domain_key = isset($dbvc_cc_result['domain']) ? (string) $dbvc_cc_result['domain'] : (string) $domain;
        $dbvc_cc_result['domain_ai_health'] = $this->dbvc_cc_get_domain_ai_health($dbvc_cc_domain_key);

        return $dbvc_cc_result;
    }

    /**
     * Returns rolled-up AI health and coverage metrics for a domain.
     *
     * @param string $domain Domain key.
     * @return array<string, mixed>
     */
    public function dbvc_cc_get_domain_ai_health($domain)
    {
        $dbvc_cc_health = [
            'domain' => $this->sanitize_domain($domain),
            'status' => 'unknown',
            'warning_badge' => false,
            'warning_message' => '',
            'counts' => [
                'total_urls' => 0,
                'completed_ai' => 0,
                'completed_fallback' => 0,
                'queued' => 0,
                'processing' => 0,
                'failed' => 0,
                'not_started' => 0,
                'stale' => 0,
            ],
            'updated_at' => null,
        ];

        $dbvc_cc_context = $this->resolve_subtree_context($domain, '');
        if (is_wp_error($dbvc_cc_context)) {
            $dbvc_cc_health['status'] = 'missing';
            $dbvc_cc_health['warning_badge'] = true;
            $dbvc_cc_health['warning_message'] = __('AI health is unavailable because crawl storage for this domain could not be resolved.', 'dbvc');
            return $dbvc_cc_health;
        }

        $dbvc_cc_domain_dir = isset($dbvc_cc_context['domain_dir']) ? (string) $dbvc_cc_context['domain_dir'] : '';
        if ($dbvc_cc_domain_dir === '' || ! is_dir($dbvc_cc_domain_dir)) {
            $dbvc_cc_health['status'] = 'missing';
            $dbvc_cc_health['warning_badge'] = true;
            $dbvc_cc_health['warning_message'] = __('AI health is unavailable because crawl storage for this domain is missing.', 'dbvc');
            return $dbvc_cc_health;
        }

        $dbvc_cc_page_paths = $this->discover_page_paths_in_subtree($dbvc_cc_domain_dir, $dbvc_cc_domain_dir);
        $dbvc_cc_health['counts']['total_urls'] = count($dbvc_cc_page_paths);
        if (empty($dbvc_cc_page_paths)) {
            $dbvc_cc_health['status'] = 'empty';
            $dbvc_cc_health['warning_badge'] = false;
            $dbvc_cc_health['warning_message'] = '';
            return $dbvc_cc_health;
        }

        $dbvc_cc_latest_updated_at = 0;
        foreach ($dbvc_cc_page_paths as $dbvc_cc_page_path) {
            $dbvc_cc_slug = basename($dbvc_cc_page_path);
            $dbvc_cc_page_dir = trailingslashit($dbvc_cc_domain_dir) . $dbvc_cc_page_path;
            $dbvc_cc_status_file = trailingslashit($dbvc_cc_page_dir) . $dbvc_cc_slug . '.analysis.status.json';
            $dbvc_cc_artifact_file = trailingslashit($dbvc_cc_page_dir) . $dbvc_cc_slug . '.json';
            $dbvc_cc_suggestions_file = trailingslashit($dbvc_cc_page_dir) . $dbvc_cc_slug . '.mapping.suggestions.json';

            $dbvc_cc_status_payload = $this->read_json_file($dbvc_cc_status_file);
            $dbvc_cc_status_key = 'not_started';
            if (is_array($dbvc_cc_status_payload)) {
                $dbvc_cc_raw_status = isset($dbvc_cc_status_payload['status']) ? sanitize_key((string) $dbvc_cc_status_payload['status']) : '';
                $dbvc_cc_raw_mode = isset($dbvc_cc_status_payload['mode']) ? sanitize_key((string) $dbvc_cc_status_payload['mode']) : '';

                if (in_array($dbvc_cc_raw_status, ['queued', 'processing', 'failed'], true)) {
                    $dbvc_cc_status_key = $dbvc_cc_raw_status;
                } elseif ($dbvc_cc_raw_status === 'completed') {
                    $dbvc_cc_status_key = ($dbvc_cc_raw_mode === 'fallback') ? 'completed_fallback' : 'completed_ai';
                }

                $dbvc_cc_updated_candidates = [
                    isset($dbvc_cc_status_payload['finished_at']) ? strtotime((string) $dbvc_cc_status_payload['finished_at']) : false,
                    isset($dbvc_cc_status_payload['started_at']) ? strtotime((string) $dbvc_cc_status_payload['started_at']) : false,
                    isset($dbvc_cc_status_payload['requested_at']) ? strtotime((string) $dbvc_cc_status_payload['requested_at']) : false,
                ];
                foreach ($dbvc_cc_updated_candidates as $dbvc_cc_candidate_time) {
                    if (is_int($dbvc_cc_candidate_time) && $dbvc_cc_candidate_time > $dbvc_cc_latest_updated_at) {
                        $dbvc_cc_latest_updated_at = $dbvc_cc_candidate_time;
                    }
                }
            }

            if (isset($dbvc_cc_health['counts'][$dbvc_cc_status_key])) {
                $dbvc_cc_health['counts'][$dbvc_cc_status_key]++;
            } else {
                $dbvc_cc_health['counts']['not_started']++;
            }

            $dbvc_cc_artifact_mtime = file_exists($dbvc_cc_artifact_file) ? (int) @filemtime($dbvc_cc_artifact_file) : 0;
            $dbvc_cc_suggestions_mtime = file_exists($dbvc_cc_suggestions_file) ? (int) @filemtime($dbvc_cc_suggestions_file) : 0;
            if ($dbvc_cc_artifact_mtime > 0 && ($dbvc_cc_suggestions_mtime <= 0 || $dbvc_cc_artifact_mtime > $dbvc_cc_suggestions_mtime)) {
                $dbvc_cc_health['counts']['stale']++;
            }

            if ($dbvc_cc_artifact_mtime > $dbvc_cc_latest_updated_at) {
                $dbvc_cc_latest_updated_at = $dbvc_cc_artifact_mtime;
            }
        }

        if ($dbvc_cc_latest_updated_at > 0) {
            $dbvc_cc_health['updated_at'] = gmdate('c', $dbvc_cc_latest_updated_at);
        }

        $dbvc_cc_failed_count = (int) $dbvc_cc_health['counts']['failed'];
        $dbvc_cc_processing_count = (int) $dbvc_cc_health['counts']['processing'];
        $dbvc_cc_queued_count = (int) $dbvc_cc_health['counts']['queued'];
        $dbvc_cc_stale_count = (int) $dbvc_cc_health['counts']['stale'];
        $dbvc_cc_total_urls = (int) $dbvc_cc_health['counts']['total_urls'];
        $dbvc_cc_completed_total = (int) $dbvc_cc_health['counts']['completed_ai'] + (int) $dbvc_cc_health['counts']['completed_fallback'];

        if ($dbvc_cc_failed_count > 0) {
            $dbvc_cc_health['status'] = 'warning';
            $dbvc_cc_health['warning_badge'] = true;
            $dbvc_cc_health['warning_message'] = sprintf(
                /* translators: %d: failed AI page count */
                __('AI pass errors were detected for %d URL(s). Newer content may be missing.', 'dbvc'),
                $dbvc_cc_failed_count
            );
        } elseif ($dbvc_cc_processing_count > 0 || $dbvc_cc_queued_count > 0) {
            $dbvc_cc_health['status'] = 'processing';
            $dbvc_cc_health['warning_badge'] = false;
            $dbvc_cc_health['warning_message'] = '';
        } elseif ($dbvc_cc_stale_count > 0) {
            $dbvc_cc_health['status'] = 'stale';
            $dbvc_cc_health['warning_badge'] = true;
            $dbvc_cc_health['warning_message'] = sprintf(
                /* translators: %d: stale AI page count */
                __('AI outputs are stale for %d URL(s). Run a domain AI refresh to include the latest crawled content.', 'dbvc'),
                $dbvc_cc_stale_count
            );
        } elseif ($dbvc_cc_completed_total >= $dbvc_cc_total_urls) {
            $dbvc_cc_health['status'] = 'healthy';
            $dbvc_cc_health['warning_badge'] = false;
            $dbvc_cc_health['warning_message'] = '';
        } else {
            $dbvc_cc_health['status'] = 'incomplete';
            $dbvc_cc_health['warning_badge'] = true;
            $dbvc_cc_health['warning_message'] = sprintf(
                /* translators: 1: completed URL count, 2: total URL count */
                __('AI pass is incomplete (%1$d of %2$d URL(s) processed). Run a domain AI refresh to complete coverage.', 'dbvc'),
                $dbvc_cc_completed_total,
                $dbvc_cc_total_urls
            );
        }

        return $dbvc_cc_health;
    }

    /**
     * Processes a queued AI job.
     *
     * @param string $domain Domain key.
     * @param string $path   Relative path.
     * @param string $job_id Job ID.
     * @return array|WP_Error
     */
    public function process_job($domain, $path, $job_id = '') {
        $context = $this->resolve_page_context($domain, $path);
        if (is_wp_error($context)) {
            return $context;
        }

        $status = $this->read_json_file($context['status_file']);
        if (!is_array($status)) {
            $status = [
                'schema_version' => '1.0',
                'job_id'         => $job_id,
                'requested_at'   => current_time('c'),
                'requested_by'   => 0,
                'trigger'        => 'system',
                'attempts'       => 0,
            ];
        }

        $status['job_id'] = !empty($status['job_id']) ? $status['job_id'] : $job_id;
        $status['status'] = 'processing';
        $status['mode'] = 'ai';
        $status['attempts'] = isset($status['attempts']) ? absint($status['attempts']) + 1 : 1;
        $status['started_at'] = current_time('c');
        $status['finished_at'] = null;
        $status['domain'] = $context['domain'];
        $status['path'] = $context['path'];
        $status['source_url'] = $context['source_url'];
        $status['message'] = __('AI processing in progress.', 'dbvc');
        $status['error'] = null;
        DBVC_CC_Artifact_Manager::write_json_file($context['status_file'], $status);

        $artifact = $this->read_json_file($context['artifact_file']);
        if (!is_array($artifact)) {
            $status['status'] = 'failed';
            $status['mode'] = 'failed';
            $status['finished_at'] = current_time('c');
            $status['error'] = __('Artifact JSON is missing or invalid.', 'dbvc');
            $status['message'] = __('AI processing failed.', 'dbvc');
            DBVC_CC_Artifact_Manager::write_json_file($context['status_file'], $status);

            DBVC_CC_Artifact_Manager::log_event(
                [
                    'stage'        => 'ai',
                    'status'       => 'error',
                    'job_id'       => $status['job_id'],
                    'page_url'     => $context['source_url'],
                    'path'         => $context['path'],
                    'failure_code' => 'dbvc_cc_ai_artifact_missing',
                    'message'      => $status['error'],
                ]
            );

            return new WP_Error('dbvc_cc_ai_artifact_missing', $status['error'], ['status' => 500]);
        }

        $result = $this->build_ai_outputs($artifact, $context);
        if (is_wp_error($result)) {
            $fallback_mode = !empty($this->options['ai_fallback_mode']);
            if ($fallback_mode) {
                $result = $this->build_fallback_outputs($artifact, $context, $result->get_error_message());
            } else {
                $status['status'] = 'failed';
                $status['mode'] = 'failed';
                $status['finished_at'] = current_time('c');
                $status['error'] = $result->get_error_message();
                $status['message'] = __('AI processing failed.', 'dbvc');
                DBVC_CC_Artifact_Manager::write_json_file($context['status_file'], $status);

                DBVC_CC_Artifact_Manager::log_event(
                    [
                        'stage'        => 'ai',
                        'status'       => 'error',
                        'job_id'       => $status['job_id'],
                        'page_url'     => $context['source_url'],
                        'path'         => $context['path'],
                        'failure_code' => $result->get_error_code(),
                        'message'      => $result->get_error_message(),
                    ]
                );

                return $result;
            }
        }

        if (!DBVC_CC_Artifact_Manager::write_json_file($context['analysis_file'], $result['analysis'])) {
            return new WP_Error('dbvc_cc_ai_write_analysis', __('Could not write analysis artifact.', 'dbvc'), ['status' => 500]);
        }
        if (!DBVC_CC_Artifact_Manager::write_json_file($context['sanitized_file'], $result['sanitized'])) {
            return new WP_Error('dbvc_cc_ai_write_sanitized', __('Could not write sanitized artifact.', 'dbvc'), ['status' => 500]);
        }
        @file_put_contents($context['sanitized_html_file'], (string) $result['sanitized']['sanitized_html']);

        $suggestions = $this->build_mapping_suggestions($artifact, $context, $result['analysis'], !empty($result['fallback']));
        if (! DBVC_CC_Artifact_Manager::write_json_file($context['mapping_suggestions_file'], $suggestions)) {
            return new WP_Error('dbvc_cc_ai_write_suggestions', __('Could not write mapping suggestions artifact.', 'dbvc'), ['status' => 500]);
        }

        $status['status'] = 'completed';
        $status['mode'] = !empty($result['fallback']) ? 'fallback' : 'ai';
        $status['finished_at'] = current_time('c');
        $status['analysis_file'] = basename($context['analysis_file']);
        $status['sanitized_file'] = basename($context['sanitized_file']);
        $status['sanitized_html_file'] = basename($context['sanitized_html_file']);
        $status['message'] = !empty($result['fallback'])
            ? __('AI fallback completed. Deterministic outputs generated.', 'dbvc')
            : __('AI processing completed successfully.', 'dbvc');
        $status['error'] = null;
        DBVC_CC_Artifact_Manager::write_json_file($context['status_file'], $status);

        DBVC_CC_Artifact_Manager::log_event(
            [
                'stage'    => 'ai',
                'status'   => !empty($result['fallback']) ? 'fallback' : 'success',
                'job_id'   => $status['job_id'],
                'page_url' => $context['source_url'],
                'path'     => $context['path'],
                'message'  => $status['message'],
            ]
        );

        return $status;
    }

    /**
     * Returns status by domain and path.
     *
     * @param string $domain Domain key.
     * @param string $path   Relative page path.
     * @return array|WP_Error
     */
    public function get_status($domain, $path) {
        $context = $this->resolve_page_context($domain, $path);
        if (is_wp_error($context)) {
            return $context;
        }

        $status = $this->read_json_file($context['status_file']);
        if (is_array($status)) {
            return $status;
        }

        if (file_exists($context['analysis_file'])) {
            return [
                'schema_version' => '1.0',
                'status'         => 'completed',
                'mode'           => 'ai',
                'job_id'         => null,
                'domain'         => $context['domain'],
                'path'           => $context['path'],
                'source_url'     => $context['source_url'],
                'analysis_file'  => basename($context['analysis_file']),
                'sanitized_file' => basename($context['sanitized_file']),
                'message'        => __('Analysis artifacts are available.', 'dbvc'),
                'error'          => null,
            ];
        }

        return [
            'schema_version' => '1.0',
            'status'         => 'not_started',
            'mode'           => 'pending',
            'job_id'         => null,
            'domain'         => $context['domain'],
            'path'           => $context['path'],
            'source_url'     => $context['source_url'],
            'message'        => __('No AI job has run for this node yet.', 'dbvc'),
            'error'          => null,
        ];
    }

    /**
     * Returns status by job ID.
     *
     * @param string $job_id Job identifier.
     * @return array|WP_Error
     */
    public function get_status_by_job_id($job_id) {
        $job = get_transient($this->get_job_transient_key($job_id));
        if (!is_array($job) || empty($job['domain']) || empty($job['path'])) {
            return new WP_Error('dbvc_cc_ai_job_not_found', __('AI job not found.', 'dbvc'), ['status' => 404]);
        }

        return $this->get_status($job['domain'], $job['path']);
    }

    /**
     * Returns rolled-up status for a queued branch rerun batch.
     *
     * @param string $batch_id Batch identifier.
     * @return array|WP_Error
     */
    public function get_status_by_batch_id($batch_id) {
        $batch = get_transient($this->get_batch_transient_key($batch_id));
        if (!is_array($batch) || empty($batch['jobs']) || !is_array($batch['jobs'])) {
            return new WP_Error('dbvc_cc_ai_batch_not_found', __('AI batch job not found.', 'dbvc'), ['status' => 404]);
        }

        $totals = [
            'not_started'   => 0,
            'queued'        => 0,
            'processing'    => 0,
            'completed_ai'  => 0,
            'completed_fallback' => 0,
            'failed'        => 0,
            'unknown'       => 0,
        ];

        $jobs = [];
        foreach ($batch['jobs'] as $job) {
            if (!is_array($job)) {
                continue;
            }

            $domain = isset($job['domain']) ? (string) $job['domain'] : (isset($batch['domain']) ? (string) $batch['domain'] : '');
            $path = isset($job['path']) ? (string) $job['path'] : '';
            $job_id = isset($job['job_id']) ? sanitize_key((string) $job['job_id']) : '';

            $status_payload = ('' !== $domain && '' !== $path) ? $this->get_status($domain, $path) : null;
            if (is_wp_error($status_payload) || !is_array($status_payload)) {
                $totals['unknown']++;
                $jobs[] = [
                    'job_id'  => $job_id,
                    'domain'  => $domain,
                    'path'    => $path,
                    'status'  => 'unknown',
                    'mode'    => 'pending',
                    'message' => is_wp_error($status_payload) ? $status_payload->get_error_message() : __('Unknown AI status.', 'dbvc'),
                ];
                continue;
            }

            $raw_status = isset($status_payload['status']) ? sanitize_key((string) $status_payload['status']) : 'unknown';
            $raw_mode = isset($status_payload['mode']) ? sanitize_key((string) $status_payload['mode']) : 'pending';

            if (in_array($raw_status, ['not_started', 'queued', 'processing'], true)) {
                $totals[$raw_status]++;
            } elseif ('completed' === $raw_status) {
                if ('fallback' === $raw_mode) {
                    $totals['completed_fallback']++;
                } else {
                    $totals['completed_ai']++;
                }
            } elseif ('failed' === $raw_status) {
                $totals['failed']++;
            } else {
                $totals['unknown']++;
            }

            $jobs[] = [
                'job_id'      => $job_id,
                'domain'      => $domain,
                'path'        => $path,
                'status'      => $raw_status,
                'mode'        => $raw_mode,
                'started_at'  => isset($status_payload['started_at']) ? (string) $status_payload['started_at'] : null,
                'finished_at' => isset($status_payload['finished_at']) ? (string) $status_payload['finished_at'] : null,
                'message'     => isset($status_payload['message']) ? sanitize_text_field((string) $status_payload['message']) : '',
            ];
        }

        $total_jobs = count($jobs);
        $completed_jobs = $totals['completed_ai'] + $totals['completed_fallback'];
        $processed_jobs = $completed_jobs + $totals['failed'];
        $active_jobs = $totals['queued'] + $totals['processing'];
        $batch_status = 'queued';

        if ($total_jobs > 0 && $processed_jobs >= $total_jobs && $totals['failed'] > 0) {
            $batch_status = 'completed_with_failures';
        } elseif ($total_jobs > 0 && $processed_jobs >= $total_jobs) {
            $batch_status = 'completed';
        } elseif ($active_jobs > 0) {
            $batch_status = 'processing';
        }

        $progress_percent = $total_jobs > 0 ? (int) round(($processed_jobs / $total_jobs) * 100) : 0;

        return [
            'schema_version'  => '1.0',
            'batch_id'        => sanitize_key((string) $batch_id),
            'status'          => $batch_status,
            'domain'          => isset($batch['domain']) ? (string) $batch['domain'] : '',
            'path'            => isset($batch['path']) ? (string) $batch['path'] : '',
            'requested_at'    => isset($batch['requested_at']) ? (string) $batch['requested_at'] : null,
            'total_jobs'      => $total_jobs,
            'processed_jobs'  => $processed_jobs,
            'progress_percent'=> $progress_percent,
            'counts'          => $totals,
            'jobs'            => $jobs,
            'message'         => sprintf(
                /* translators: 1: processed job count, 2: total job count */
                __('Processed %1$d of %2$d AI jobs.', 'dbvc'),
                $processed_jobs,
                $total_jobs
            ),
        ];
    }

    /**
     * Creates AI outputs via OpenAI API.
     *
     * @param array $artifact Crawl artifact.
     * @param array $context  Page context.
     * @return array|WP_Error
     */
    private function build_ai_outputs($artifact, $context) {
        $this->refresh_options();

        $api_key = isset($this->options['openai_api_key']) ? trim((string) $this->options['openai_api_key']) : '';
        if ('' === $api_key) {
            return new WP_Error('dbvc_cc_ai_missing_key', __('OpenAI API key is not configured.', 'dbvc'));
        }

        $classification = $this->request_classification($artifact, $context, $api_key);
        if (is_wp_error($classification)) {
            return $classification;
        }

        $sanitization = $this->request_sanitization($artifact, $context, $api_key);
        if (is_wp_error($sanitization)) {
            return $sanitization;
        }

        $generated_at = current_time('c');
        $prompt_version = isset($this->options['prompt_version']) ? (string) $this->options['prompt_version'] : 'v1';
        $model = isset($this->options['openai_model']) ? (string) $this->options['openai_model'] : 'gpt-4o-mini';

        $analysis = [
            'schema_version'        => '1.0',
            'status'                => 'success',
            'source_json'           => basename($context['artifact_file']),
            'source_url'            => $context['source_url'],
            'generated_at'          => $generated_at,
            'model'                 => $model,
            'prompt_version'        => $prompt_version,
            'post_type'             => isset($classification['post_type']) ? sanitize_key((string) $classification['post_type']) : 'page',
            'post_type_confidence'  => isset($classification['post_type_confidence']) ? (float) $classification['post_type_confidence'] : 0.5,
            'categories'            => isset($classification['categories']) && is_array($classification['categories']) ? array_values($classification['categories']) : [],
            'summary'               => isset($classification['summary']) ? (string) $classification['summary'] : '',
            'needs_review'          => !empty($classification['needs_review']),
            'reasoning'             => isset($classification['reasoning']) ? (string) $classification['reasoning'] : '',
        ];

        $sanitized_html = isset($sanitization['sanitized_html']) ? (string) $sanitization['sanitized_html'] : '';
        if ('' === $sanitized_html) {
            return new WP_Error('dbvc_cc_ai_sanitized_empty', __('AI sanitization returned empty HTML.', 'dbvc'));
        }

        $sanitized = [
            'schema_version'        => '1.0',
            'status'                => 'success',
            'source_json'           => basename($context['artifact_file']),
            'source_url'            => $context['source_url'],
            'generated_at'          => $generated_at,
            'model'                 => $model,
            'prompt_version'        => $prompt_version,
            'sanitized_html'        => $sanitized_html,
            'heading_outline'       => isset($sanitization['heading_outline']) && is_array($sanitization['heading_outline']) ? array_values($sanitization['heading_outline']) : [],
            'asset_map'             => $this->build_asset_map($artifact),
            'removed_inline_styles' => isset($sanitization['removed_inline_styles']) ? absint($sanitization['removed_inline_styles']) : 0,
            'removed_classes'       => isset($sanitization['removed_classes']) && is_array($sanitization['removed_classes']) ? array_values($sanitization['removed_classes']) : [],
            'warnings'              => isset($sanitization['warnings']) && is_array($sanitization['warnings']) ? array_values($sanitization['warnings']) : [],
        ];

        return [
            'fallback' => false,
            'analysis' => $analysis,
            'sanitized'=> $sanitized,
        ];
    }

    /**
     * Builds deterministic fallback outputs.
     *
     * @param array  $artifact      Crawl artifact.
     * @param array  $context       Page context.
     * @param string $failure_reason Failure reason.
     * @return array
     */
    private function build_fallback_outputs($artifact, $context, $failure_reason) {
        $generated_at = current_time('c');
        $prompt_version = isset($this->options['prompt_version']) ? (string) $this->options['prompt_version'] : 'v1';

        $post_types = get_post_types(['public' => true], 'names');
        $post_type = $this->infer_post_type($artifact, $post_types);
        $categories = $this->infer_categories($artifact);

        $text_blocks = isset($artifact['content']['text_blocks']) && is_array($artifact['content']['text_blocks']) ? $artifact['content']['text_blocks'] : [];
        $summary = '';
        if (!empty($text_blocks)) {
            $summary_source = trim(implode(' ', array_slice($text_blocks, 0, 3)));
            if (function_exists('mb_substr')) {
                $summary = mb_substr($summary_source, 0, 240);
            } else {
                $summary = substr($summary_source, 0, 240);
            }
        }

        $analysis = [
            'schema_version'        => '1.0',
            'status'                => 'fallback',
            'source_json'           => basename($context['artifact_file']),
            'source_url'            => $context['source_url'],
            'generated_at'          => $generated_at,
            'model'                 => 'deterministic-fallback',
            'prompt_version'        => $prompt_version,
            'post_type'             => $post_type,
            'post_type_confidence'  => 0.4,
            'categories'            => $categories,
            'summary'               => $summary,
            'needs_review'          => true,
            'reasoning'             => __('AI request failed. Applied deterministic fallback mapping.', 'dbvc'),
            'failure_reason'        => $failure_reason,
        ];

        $sanitized_html = $this->build_fallback_sanitized_html($artifact);
        $removed_inline_styles = substr_count($sanitized_html, ' style=');

        $sanitized = [
            'schema_version'        => '1.0',
            'status'                => 'fallback',
            'source_json'           => basename($context['artifact_file']),
            'source_url'            => $context['source_url'],
            'generated_at'          => $generated_at,
            'model'                 => 'deterministic-fallback',
            'prompt_version'        => $prompt_version,
            'sanitized_html'        => $sanitized_html,
            'heading_outline'       => $this->build_heading_outline($artifact),
            'asset_map'             => $this->build_asset_map($artifact),
            'removed_inline_styles' => $removed_inline_styles,
            'removed_classes'       => [],
            'warnings'              => [
                __('Fallback sanitizer used due to AI error.', 'dbvc'),
                $failure_reason,
            ],
        ];

        return [
            'fallback' => true,
            'analysis' => $analysis,
            'sanitized'=> $sanitized,
        ];
    }

    /**
     * Sends classification request to OpenAI.
     *
     * @param array  $artifact Artifact payload.
     * @param array  $context  Page context.
     * @param string $api_key  API key.
     * @return array|WP_Error
     */
    private function request_classification($artifact, $context, $api_key) {
        $post_types = array_values(get_post_types(['public' => true], 'names'));
        $categories = get_categories(
            [
                'hide_empty' => false,
                'fields'     => 'all',
            ]
        );

        $allowed_categories = [];
        foreach ($categories as $category) {
            if (is_object($category) && isset($category->slug)) {
                $allowed_categories[] = [
                    'slug' => (string) $category->slug,
                    'name' => isset($category->name) ? (string) $category->name : (string) $category->slug,
                ];
            }
        }

        $payload = [
            'title'              => isset($artifact['page_name']) ? (string) $artifact['page_name'] : '',
            'source_url'         => $context['source_url'],
            'headings'           => isset($artifact['content']['headings']) && is_array($artifact['content']['headings']) ? array_values($artifact['content']['headings']) : [],
            'text_blocks'        => isset($artifact['content']['text_blocks']) && is_array($artifact['content']['text_blocks']) ? array_slice(array_values($artifact['content']['text_blocks']), 0, 40) : [],
            'allowed_post_types' => $post_types,
            'allowed_categories' => $allowed_categories,
        ];

        $system = 'You are a WordPress migration classifier for VerticalFramework. Choose exactly one post_type from allowed_post_types. Choose up to three category slugs from allowed_categories. Return strict JSON with keys post_type, post_type_confidence (0..1), categories (array of slugs), summary, needs_review (boolean), reasoning.';

        return $this->call_openai_json($system, $payload, $api_key);
    }

    /**
     * Sends sanitization request to OpenAI.
     *
     * @param array  $artifact Artifact payload.
     * @param array  $context  Page context.
     * @param string $api_key  API key.
     * @return array|WP_Error
     */
    private function request_sanitization($artifact, $context, $api_key) {
        $payload = [
            'title'       => isset($artifact['page_name']) ? (string) $artifact['page_name'] : '',
            'source_url'  => $context['source_url'],
            'headings'    => isset($artifact['content']['headings']) && is_array($artifact['content']['headings']) ? array_values($artifact['content']['headings']) : [],
            'text_blocks' => isset($artifact['content']['text_blocks']) && is_array($artifact['content']['text_blocks']) ? array_slice(array_values($artifact['content']['text_blocks']), 0, 120) : [],
            'images'      => isset($artifact['content']['images']) && is_array($artifact['content']['images']) ? array_values($artifact['content']['images']) : [],
        ];

        $system = 'You are an HTML migration sanitizer for WordPress VerticalFramework. Produce semantic, clean HTML suitable for Gutenberg. Preserve meaning, heading hierarchy, links, lists, and images. Remove inline styles and legacy classes. Return strict JSON with keys sanitized_html (string), heading_outline (array), removed_inline_styles (integer), removed_classes (array), warnings (array).';

        return $this->call_openai_json($system, $payload, $api_key);
    }

    /**
     * Calls OpenAI chat completions and parses JSON response.
     *
     * @param string $system_prompt System prompt.
     * @param array  $payload       User payload.
     * @param string $api_key       API key.
     * @return array|WP_Error
     */
    private function call_openai_json($system_prompt, $payload, $api_key) {
        $model = isset($this->options['openai_model']) && '' !== trim((string) $this->options['openai_model'])
            ? trim((string) $this->options['openai_model'])
            : 'gpt-4o-mini';
        $timeout = isset($this->options['ai_request_timeout']) ? absint($this->options['ai_request_timeout']) : 90;
        if ($timeout < 30) {
            $timeout = 30;
        }
        if ($timeout > 180) {
            $timeout = 180;
        }

        $request_body = [
            'model' => $model,
            'temperature' => 0.1,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $system_prompt,
                ],
                [
                    'role' => 'user',
                    'content' => wp_json_encode($payload, JSON_UNESCAPED_SLASHES),
                ],
            ],
        ];

        $response = wp_remote_post(
            'https://api.openai.com/v1/chat/completions',
            [
                'timeout' => $timeout,
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode($request_body),
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error('dbvc_cc_ai_request_failed', sprintf(__('OpenAI request failed: %s', 'dbvc'), $response->get_error_message()));
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            $message = $this->extract_openai_error_message($body);
            return new WP_Error('dbvc_cc_ai_http_error', sprintf(__('OpenAI API error (%1$d): %2$s', 'dbvc'), $code, $message));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return new WP_Error('dbvc_cc_ai_decode', __('OpenAI response could not be decoded.', 'dbvc'));
        }

        $content = '';
        if (isset($decoded['choices'][0]['message']['content']) && is_string($decoded['choices'][0]['message']['content'])) {
            $content = $decoded['choices'][0]['message']['content'];
        }
        if ('' === $content) {
            return new WP_Error('dbvc_cc_ai_empty', __('OpenAI response content was empty.', 'dbvc'));
        }

        $json = json_decode($content, true);
        if (!is_array($json)) {
            return new WP_Error('dbvc_cc_ai_invalid_json', __('OpenAI returned non-JSON content.', 'dbvc'));
        }

        return $json;
    }

    /**
     * Extracts OpenAI API error message from response body.
     *
     * @param string $body API response body.
     * @return string
     */
    private function extract_openai_error_message($body) {
        $decoded = json_decode((string) $body, true);
        if (is_array($decoded) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
            return $decoded['error']['message'];
        }

        return __('Unknown API error', 'dbvc');
    }

    /**
     * Infers post type via deterministic heuristics.
     *
     * @param array $artifact   Artifact payload.
     * @param array $post_types Available post types.
     * @return string
     */
    private function infer_post_type($artifact, $post_types) {
        $candidates = array_values($post_types);
        if (empty($candidates)) {
            return 'page';
        }

        $title = isset($artifact['page_name']) ? strtolower((string) $artifact['page_name']) : '';
        $source_url = isset($artifact['source_url']) ? strtolower((string) $artifact['source_url']) : '';
        $combined = $title . ' ' . $source_url;

        $preferred = [
            'services'   => ['service', 'services'],
            'portfolios' => ['portfolio', 'portfolios', 'work', 'project', 'projects'],
            'post'       => ['blog', 'news', 'article', 'insight'],
            'page'       => ['about', 'contact', 'home', 'landing'],
        ];

        foreach ($preferred as $post_type => $terms) {
            if (!in_array($post_type, $candidates, true)) {
                continue;
            }
            foreach ($terms as $term) {
                if (false !== strpos($combined, $term)) {
                    return $post_type;
                }
            }
        }

        if (in_array('page', $candidates, true)) {
            return 'page';
        }
        if (in_array('post', $candidates, true)) {
            return 'post';
        }

        return (string) reset($candidates);
    }

    /**
     * Infers categories via deterministic matching.
     *
     * @param array $artifact Artifact payload.
     * @return array
     */
    private function infer_categories($artifact) {
        $categories = get_categories(
            [
                'hide_empty' => false,
                'fields'     => 'all',
            ]
        );

        $title = isset($artifact['page_name']) ? strtolower((string) $artifact['page_name']) : '';
        $text_blocks = isset($artifact['content']['text_blocks']) && is_array($artifact['content']['text_blocks'])
            ? strtolower(implode(' ', array_slice($artifact['content']['text_blocks'], 0, 20)))
            : '';
        $haystack = $title . ' ' . $text_blocks;

        $selected = [];
        foreach ($categories as $category) {
            if (!is_object($category) || !isset($category->slug)) {
                continue;
            }
            $slug = strtolower((string) $category->slug);
            $name = isset($category->name) ? strtolower((string) $category->name) : $slug;
            if ('' !== $slug && (false !== strpos($haystack, $slug) || false !== strpos($haystack, $name))) {
                $selected[] = [
                    'slug'       => $slug,
                    'confidence' => 0.6,
                    'reason'     => __('Matched against page content.', 'dbvc'),
                ];
            }
            if (count($selected) >= 3) {
                break;
            }
        }

        if (empty($selected)) {
            foreach ($categories as $category) {
                if (is_object($category) && isset($category->slug) && 'uncategorized' === (string) $category->slug) {
                    $selected[] = [
                        'slug'       => 'uncategorized',
                        'confidence' => 0.4,
                        'reason'     => __('Default fallback category.', 'dbvc'),
                    ];
                    break;
                }
            }
        }

        return $selected;
    }

    /**
     * Builds fallback sanitized HTML from structured text content.
     *
     * @param array $artifact Artifact payload.
     * @return string
     */
    private function build_fallback_sanitized_html($artifact) {
        $title = isset($artifact['page_name']) ? wp_strip_all_tags((string) $artifact['page_name']) : '';
        $headings = isset($artifact['content']['headings']) && is_array($artifact['content']['headings']) ? $artifact['content']['headings'] : [];
        $blocks = isset($artifact['content']['text_blocks']) && is_array($artifact['content']['text_blocks']) ? $artifact['content']['text_blocks'] : [];
        $images = isset($artifact['content']['images']) && is_array($artifact['content']['images']) ? $artifact['content']['images'] : [];

        $html = [];
        $html[] = '<section class="dbvc-cc-migrated-content">';
        if ('' !== $title) {
            $html[] = '<h1>' . esc_html($title) . '</h1>';
        }

        $heading_count = 0;
        foreach ($headings as $heading) {
            if ($heading_count >= 8) {
                break;
            }
            $text = trim((string) $heading);
            if ('' === $text || ($title !== '' && strtolower($text) === strtolower($title))) {
                continue;
            }
            $html[] = '<h2>' . esc_html($text) . '</h2>';
            $heading_count++;
        }

        $paragraph_count = 0;
        foreach ($blocks as $block) {
            if ($paragraph_count >= 30) {
                break;
            }
            $text = trim((string) $block);
            if ('' === $text) {
                continue;
            }
            $html[] = '<p>' . esc_html($text) . '</p>';
            $paragraph_count++;
        }

        foreach ($images as $image) {
            if (!isset($image['local_filename'])) {
                continue;
            }
            $filename = sanitize_file_name((string) $image['local_filename']);
            if ('' === $filename) {
                continue;
            }
            $html[] = '<figure><img src="' . esc_attr($filename) . '" alt="" loading="lazy" /></figure>';
        }

        $html[] = '</section>';

        return implode("\n", $html);
    }

    /**
     * Builds heading outline from artifact headings.
     *
     * @param array $artifact Artifact payload.
     * @return array
     */
    private function build_heading_outline($artifact) {
        $title = isset($artifact['page_name']) ? trim((string) $artifact['page_name']) : '';
        $headings = isset($artifact['content']['headings']) && is_array($artifact['content']['headings']) ? $artifact['content']['headings'] : [];

        $outline = [];
        if ('' !== $title) {
            $outline[] = 'h1: ' . $title;
        }

        foreach ($headings as $heading) {
            $text = trim((string) $heading);
            if ('' === $text) {
                continue;
            }
            if ('' !== $title && strtolower($text) === strtolower($title)) {
                continue;
            }
            $outline[] = 'h2: ' . $text;
            if (count($outline) >= 20) {
                break;
            }
        }

        return $outline;
    }

    /**
     * Builds asset map for sanitized output.
     *
     * @param array $artifact Artifact payload.
     * @return array
     */
    private function build_asset_map($artifact) {
        $images = isset($artifact['content']['images']) && is_array($artifact['content']['images']) ? $artifact['content']['images'] : [];
        $map = [];
        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }
            $from = isset($image['source_url']) ? (string) $image['source_url'] : '';
            $to = isset($image['local_filename']) ? (string) $image['local_filename'] : '';
            if ('' === $from && '' === $to) {
                continue;
            }
            $map[] = [
                'from'   => $from,
                'to'     => $to,
                'status' => 'mapped',
            ];
        }

        return $map;
    }

    /**
     * Builds mapping suggestions for workbench review.
     *
     * @param array<string, mixed> $artifact
     * @param array<string, mixed> $context
     * @param array<string, mixed> $analysis
     * @param bool                 $is_fallback
     * @return array<string, mixed>
     */
    private function build_mapping_suggestions($artifact, $context, $analysis, $is_fallback)
    {
        $confidence_threshold = self::REVIEW_CONFIDENCE_THRESHOLD;

        $post_type = isset($analysis['post_type']) ? sanitize_key((string) $analysis['post_type']) : '';
        $post_type_confidence = isset($analysis['post_type_confidence']) ? (float) $analysis['post_type_confidence'] : 0.0;
        $post_type_confidence = max(0.0, min(1.0, $post_type_confidence));
        $post_type_rationale = isset($analysis['reasoning']) ? sanitize_text_field((string) $analysis['reasoning']) : '';
        if ($post_type_rationale === '' && $is_fallback) {
            $post_type_rationale = __('Derived from deterministic fallback heuristics.', 'dbvc');
        }

        $taxonomy_terms = [];
        if (isset($analysis['categories']) && is_array($analysis['categories'])) {
            foreach ($analysis['categories'] as $category_suggestion) {
                if (is_array($category_suggestion)) {
                    $slug = isset($category_suggestion['slug']) ? sanitize_key((string) $category_suggestion['slug']) : '';
                    if ($slug === '') {
                        continue;
                    }
                    $taxonomy_terms[] = [
                        'slug' => $slug,
                        'confidence' => isset($category_suggestion['confidence']) ? (float) $category_suggestion['confidence'] : 0.6,
                        'rationale' => isset($category_suggestion['reason']) ? sanitize_text_field((string) $category_suggestion['reason']) : '',
                    ];
                    continue;
                }

                $slug = sanitize_key((string) $category_suggestion);
                if ($slug === '') {
                    continue;
                }
                $taxonomy_terms[] = [
                    'slug' => $slug,
                    'confidence' => $is_fallback ? 0.55 : 0.75,
                    'rationale' => $is_fallback
                        ? __('Fallback category match.', 'dbvc')
                        : __('AI category suggestion.', 'dbvc'),
                ];
            }
        }

        $custom_field_suggestions = $this->infer_custom_field_suggestions($artifact);
        $media_role_suggestions = $this->infer_media_role_suggestions($artifact);
        $author_suggestion = $this->infer_author_suggestion($artifact, $is_fallback);

        $conflicts = [];
        if ($post_type === '') {
            $conflicts[] = 'missing_post_type';
        }
        if (empty($taxonomy_terms)) {
            $conflicts[] = 'missing_category';
        }
        if ($is_fallback) {
            $conflicts[] = 'fallback_mode';
        }

        $review_reasons = [];
        if (!empty($analysis['needs_review'])) {
            $review_reasons[] = 'ai_marked_for_review';
        }
        if ($post_type_confidence < $confidence_threshold) {
            $review_reasons[] = 'low_confidence_post_type';
        }
        foreach ($taxonomy_terms as $term) {
            if ((float) $term['confidence'] < $confidence_threshold) {
                $review_reasons[] = 'low_confidence_taxonomy';
                break;
            }
        }
        foreach ($custom_field_suggestions as $field_suggestion) {
            if (isset($field_suggestion['confidence']) && (float) $field_suggestion['confidence'] < $confidence_threshold) {
                $review_reasons[] = 'low_confidence_custom_field';
                break;
            }
        }
        foreach ($media_role_suggestions as $media_suggestion) {
            if (isset($media_suggestion['confidence']) && (float) $media_suggestion['confidence'] < $confidence_threshold) {
                $review_reasons[] = 'low_confidence_media_role';
                break;
            }
        }
        if ((float) $author_suggestion['confidence'] < $confidence_threshold) {
            $review_reasons[] = 'low_confidence_author';
        }
        foreach ($conflicts as $conflict) {
            $review_reasons[] = 'conflict_' . sanitize_key((string) $conflict);
        }
        $review_reasons = array_values(array_unique($review_reasons));

        $review_state = [
            'needs_review' => ! empty($review_reasons),
            'confidence_threshold' => $confidence_threshold,
            'reasons' => $review_reasons,
        ];

        return [
            'schema_version' => '1.0',
            'status' => $is_fallback ? 'fallback' : 'ai',
            'generated_at' => current_time('c'),
            'domain' => isset($context['domain']) ? sanitize_text_field((string) $context['domain']) : '',
            'path' => isset($context['path']) ? sanitize_text_field((string) $context['path']) : '',
            'source_url' => isset($context['source_url']) ? esc_url_raw((string) $context['source_url']) : '',
            'suggestions' => [
                'post_type' => [
                    'value' => $post_type,
                    'confidence' => $post_type_confidence,
                    'rationale' => $post_type_rationale,
                ],
                'taxonomy' => [
                    'taxonomy' => 'category',
                    'terms' => $taxonomy_terms,
                ],
                'custom_fields' => $custom_field_suggestions,
                'media_roles' => $media_role_suggestions,
                'author' => $author_suggestion,
            ],
            'conflicts' => $conflicts,
            'review' => $review_state,
        ];
    }

    /**
     * Derive deterministic custom field suggestions from section data.
     *
     * @param array<string, mixed> $artifact
     * @return array<int, array<string, mixed>>
     */
    private function infer_custom_field_suggestions($artifact)
    {
        $suggestions = [];
        $sections = isset($artifact['content']['section_groups']) && is_array($artifact['content']['section_groups'])
            ? $artifact['content']['section_groups']
            : [];

        foreach (array_slice($sections, 0, 8) as $section) {
            if (! is_array($section)) {
                continue;
            }

            $heading = isset($section['heading']) ? sanitize_text_field((string) $section['heading']) : '';
            if ($heading === '') {
                continue;
            }

            $text_blocks = isset($section['text_blocks']) && is_array($section['text_blocks']) ? $section['text_blocks'] : [];
            $preview = '';
            if (! empty($text_blocks)) {
                $preview = sanitize_text_field((string) reset($text_blocks));
            }

            $field_key = 'section_' . sanitize_title($heading);
            $suggestions[] = [
                'field_key' => $field_key,
                'value_preview' => $preview,
                'confidence' => 0.7,
                'rationale' => __('Derived from section heading and text grouping.', 'dbvc'),
            ];
        }

        return $suggestions;
    }

    /**
     * Infer media role suggestions from available images.
     *
     * @param array<string, mixed> $artifact
     * @return array<int, array<string, mixed>>
     */
    private function infer_media_role_suggestions($artifact)
    {
        $suggestions = [];
        $images = isset($artifact['content']['images']) && is_array($artifact['content']['images']) ? $artifact['content']['images'] : [];

        foreach (array_slice($images, 0, 8) as $index => $image) {
            if (! is_array($image)) {
                continue;
            }

            $filename = isset($image['local_filename']) ? sanitize_file_name((string) $image['local_filename']) : '';
            if ($filename === '') {
                continue;
            }

            $role = $index === 0 ? 'featured' : 'gallery';
            $confidence = $index === 0 ? 0.72 : 0.64;
            $rationale = $index === 0
                ? __('First image in capture set treated as featured media candidate.', 'dbvc')
                : __('Additional captured image treated as gallery media.', 'dbvc');

            $suggestions[] = [
                'role' => $role,
                'asset' => $filename,
                'confidence' => $confidence,
                'rationale' => $rationale,
            ];
        }

        return $suggestions;
    }

    /**
     * Infer author suggestion using byline text if available.
     *
     * @param array<string, mixed> $artifact
     * @param bool                 $is_fallback
     * @return array<string, mixed>
     */
    private function infer_author_suggestion($artifact, $is_fallback)
    {
        $text_blocks = isset($artifact['content']['text_blocks']) && is_array($artifact['content']['text_blocks'])
            ? $artifact['content']['text_blocks']
            : [];

        $author_name = '';
        foreach (array_slice($text_blocks, 0, 8) as $text_block) {
            $text = wp_strip_all_tags((string) $text_block);
            if (preg_match('/\bby\s+([A-Za-z][A-Za-z .\'-]{1,60})/i', $text, $matches) === 1) {
                $author_name = sanitize_text_field(trim((string) $matches[1]));
                break;
            }
        }

        if ($author_name === '') {
            return [
                'value' => '',
                'confidence' => 0.0,
                'rationale' => $is_fallback
                    ? __('No deterministic byline match found during fallback.', 'dbvc')
                    : __('No byline marker detected in sampled text.', 'dbvc'),
            ];
        }

        return [
            'value' => $author_name,
            'confidence' => 0.7,
            'rationale' => __('Byline phrase match from captured text blocks.', 'dbvc'),
        ];
    }

    /**
     * Resolves and validates a subtree directory for branch reruns.
     *
     * @param string $domain Domain key.
     * @param string $path   Relative subtree path.
     * @return array|WP_Error
     */
    private function resolve_subtree_context($domain, $path) {
        $domain = $this->sanitize_domain($domain);
        $path = $this->normalize_relative_path($path);
        if ('' === $domain) {
            return new WP_Error('dbvc_cc_invalid_domain', __('A valid domain is required.', 'dbvc'), ['status' => 400]);
        }

        $base_dir = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        if (!is_dir($base_dir)) {
            return new WP_Error('dbvc_cc_storage_missing', __('Content collector storage path does not exist.', 'dbvc'), ['status' => 404]);
        }

        $base_real = realpath($base_dir);
        if (!is_string($base_real)) {
            return new WP_Error('dbvc_cc_storage_invalid', __('Could not resolve storage path.', 'dbvc'), ['status' => 500]);
        }

        $domain_dir = trailingslashit($base_real) . $domain;
        if (!is_dir($domain_dir)) {
            return new WP_Error('dbvc_cc_domain_missing', __('No crawl data found for this domain.', 'dbvc'), ['status' => 404]);
        }

        $target_dir = '' === $path ? $domain_dir : trailingslashit($domain_dir) . $path;
        if (!is_dir($target_dir)) {
            return new WP_Error('dbvc_cc_missing_path', __('The selected branch path does not exist.', 'dbvc'), ['status' => 404]);
        }

        $domain_real = realpath($domain_dir);
        $target_real = realpath($target_dir);
        if (!is_string($domain_real) || !is_string($target_real) || 0 !== strpos($target_real, $domain_real)) {
            return new WP_Error('dbvc_cc_invalid_path', __('Invalid branch path.', 'dbvc'), ['status' => 400]);
        }

        return [
            'domain'    => $domain,
            'path'      => $path,
            'domain_dir'=> $domain_real,
            'target_dir'=> $target_real,
        ];
    }

    /**
     * Discovers page artifact paths in a subtree.
     *
     * @param string $domain_dir Absolute domain directory.
     * @param string $target_dir Absolute target subtree directory.
     * @return array
     */
    private function discover_page_paths_in_subtree($domain_dir, $target_dir) {
        if (!is_dir($domain_dir) || !is_dir($target_dir)) {
            return [];
        }

        $paths = [];
        $domain_prefix = strlen(trailingslashit($domain_dir));

        $candidate_dirs = [$target_dir];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                $candidate_dirs[] = $item->getPathname();
            }
        }

        foreach ($candidate_dirs as $dir) {
            $relative_path = str_replace('\\', '/', substr($dir, $domain_prefix));
            $relative_path = trim($relative_path, '/');
            if ('' === $relative_path) {
                continue;
            }

            $slug = basename($relative_path);
            $artifact_file = trailingslashit($dir) . $slug . '.json';
            if (!file_exists($artifact_file)) {
                continue;
            }

            $paths[] = $relative_path;
        }

        $paths = array_values(array_unique($paths));
        natcasesort($paths);
        return array_values($paths);
    }

    /**
     * Resolves and validates artifact file paths for a domain/path.
     *
     * @param string $domain Domain key.
     * @param string $path   Relative page path.
     * @return array|WP_Error
     */
    private function resolve_page_context($domain, $path) {
        $domain = $this->sanitize_domain($domain);
        $path = $this->normalize_relative_path($path);
        if ('' === $domain) {
            return new WP_Error('dbvc_cc_invalid_domain', __('A valid domain is required.', 'dbvc'), ['status' => 400]);
        }
        if ('' === $path) {
            return new WP_Error('dbvc_cc_invalid_path', __('A valid page path is required.', 'dbvc'), ['status' => 400]);
        }

        $base_dir = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        if (!is_dir($base_dir)) {
            return new WP_Error('dbvc_cc_storage_missing', __('Content collector storage path does not exist.', 'dbvc'), ['status' => 404]);
        }

        $base_real = realpath($base_dir);
        if (!is_string($base_real)) {
            return new WP_Error('dbvc_cc_storage_invalid', __('Could not resolve storage path.', 'dbvc'), ['status' => 500]);
        }

        $domain_dir = trailingslashit($base_real) . $domain;
        if (!is_dir($domain_dir)) {
            return new WP_Error('dbvc_cc_domain_missing', __('No crawl data found for this domain.', 'dbvc'), ['status' => 404]);
        }

        $target_dir = trailingslashit($domain_dir) . $path;
        if (!is_dir($target_dir)) {
            return new WP_Error('dbvc_cc_missing_path', __('The selected page path does not exist.', 'dbvc'), ['status' => 404]);
        }

        $domain_real = realpath($domain_dir);
        $target_real = realpath($target_dir);
        if (!is_string($domain_real) || !is_string($target_real) || 0 !== strpos($target_real, $domain_real)) {
            return new WP_Error('dbvc_cc_invalid_path', __('Invalid page path.', 'dbvc'), ['status' => 400]);
        }

        $slug = basename($path);
        $artifact_file = trailingslashit($target_real) . $slug . '.json';
        if (!file_exists($artifact_file)) {
            return new WP_Error('dbvc_cc_missing_artifact', __('No page artifact found for this node.', 'dbvc'), ['status' => 404]);
        }

        $artifact = $this->read_json_file($artifact_file);
        $source_url = is_array($artifact) && isset($artifact['source_url']) ? (string) $artifact['source_url'] : ('https://' . $domain . '/' . $path);

        return [
            'domain'               => $domain,
            'path'                 => $path,
            'slug'                 => $slug,
            'source_url'           => $source_url,
            'domain_dir'           => $domain_real,
            'target_dir'           => $target_real,
            'artifact_file'        => $artifact_file,
            'status_file'          => trailingslashit($target_real) . $slug . '.analysis.status.json',
            'analysis_file'        => trailingslashit($target_real) . $slug . '.analysis.json',
            'sanitized_file'       => trailingslashit($target_real) . $slug . '.sanitized.json',
            'sanitized_html_file'  => trailingslashit($target_real) . $slug . '.sanitized.html',
            'mapping_suggestions_file' => trailingslashit($target_real) . $slug . '.mapping.suggestions.json',
        ];
    }

    /**
     * Reads JSON file.
     *
     * @param string $path File path.
     * @return array|null
     */
    private function read_json_file($path) {
        if (!file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || '' === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Sanitizes domain key.
     *
     * @param string $domain Domain input.
     * @return string
     */
    private function sanitize_domain($domain) {
        $value = strtolower((string) $domain);
        return preg_replace('/[^a-z0-9.-]/', '', $value);
    }

    /**
     * Sanitizes relative path.
     *
     * @param string $path Path input.
     * @return string
     */
    private function normalize_relative_path($path) {
        $value = str_replace('\\', '/', urldecode((string) $path));
        $value = trim($value, '/');
        if ('' === $value) {
            return '';
        }

        $parts = [];
        foreach (explode('/', $value) as $part) {
            $part = sanitize_title($part);
            if ('' !== $part && '..' !== $part) {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    /**
     * Returns transient key for AI job ID.
     *
     * @param string $job_id Job ID.
     * @return string
     */
    private function get_job_transient_key($job_id) {
        return self::JOB_TRANSIENT_PREFIX . sanitize_key($job_id);
    }

    /**
     * Returns transient key for AI branch batch ID.
     *
     * @param string $batch_id Batch ID.
     * @return string
     */
    private function get_batch_transient_key($batch_id) {
        return self::BATCH_TRANSIENT_PREFIX . sanitize_key($batch_id);
    }

    /**
     * Reloads options.
     *
     * @return void
     */
    private function refresh_options() {
        $this->options = DBVC_CC_Settings_Service::get_options();
    }
}
