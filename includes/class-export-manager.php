<?php

/**
 * Chunked export orchestration helper.
 *
 * @package DB Version Control
 */

if (! defined('WPINC')) {
    die;
}

if (! class_exists('DBVC_Export_Manager')) {
    class DBVC_Export_Manager
    {
        /**
         * Process a chunk of exports (create or resume a job).
         *
         * @param int      $chunk_size
         * @param int|null $job_id
         * @return array|\WP_Error
         */
        public static function run_chunked_export($chunk_size, $job_id = null)
        {
            if (! class_exists('DBVC_Database')) {
                return new WP_Error('dbvc_missing_database', __('Snapshot storage layer unavailable.', 'dbvc'));
            }

            $chunk_size = absint($chunk_size);
            if ($chunk_size <= 0) {
                return new WP_Error('dbvc_invalid_chunk', __('Chunk size must be greater than zero.', 'dbvc'));
            }

            $job          = null;
            $context      = [];
            $creating_job = false;

            if ($job_id) {
                $job = DBVC_Database::get_job($job_id);
                if (! $job) {
                    return new WP_Error('dbvc_job_not_found', sprintf(__('Export job %d was not found.', 'dbvc'), $job_id));
                }

                if ($job->job_type !== 'export_chunked') {
                    return new WP_Error('dbvc_wrong_job_type', __('Job is not a chunked export.', 'dbvc'));
                }

                if (isset($job->status) && 'done' === $job->status) {
                    return [
                        'job_id'    => (int) $job_id,
                        'status'    => 'done',
                        'total'     => $job->context ? (int) (json_decode($job->context, true)['total'] ?? 0) : 0,
                        'remaining' => 0,
                        'processed_current' => 0,
                        'progress'  => 1,
                        'message'   => __('Export job already completed.', 'dbvc'),
                    ];
                }

                $context = $job->context ? json_decode($job->context, true) : [];
                if (! is_array($context)) {
                    $context = [];
                }

                if (! empty($context['chunk_size']) && (int) $context['chunk_size'] !== $chunk_size) {
                    $context['chunk_size'] = $chunk_size;
                } else {
                    $context['chunk_size'] = $chunk_size;
                }
            } else {
                $creating_job = true;

                DBVC_Sync_Posts::export_options_to_json();
                DBVC_Sync_Posts::export_menus_to_json();
                if (class_exists('DBVC_Sync_Taxonomies')) {
                    DBVC_Sync_Taxonomies::export_selected_taxonomies();
                }

                $total_posts = DBVC_Sync_Posts::get_total_export_post_count();
                if ($total_posts <= 0) {
                    return new WP_Error('dbvc_no_posts', __('No posts available for export.', 'dbvc'));
                }

                $context = [
                    'offset'     => 0,
                    'chunk_size' => $chunk_size,
                    'total'      => $total_posts,
                    'processed'  => 0,
                    'remaining'  => $total_posts,
                ];

                $job_id = DBVC_Database::create_job('export_chunked', $context, 'running');
                DBVC_Database::log_activity(
                    'export_chunk_job_created',
                    'info',
                    'Chunked export job created',
                    [
                        'job_id'    => $job_id,
                        'total'     => $total_posts,
                        'chunk_size'=> $chunk_size,
                    ]
                );

                $job = DBVC_Database::get_job($job_id);
            }

            $offset           = isset($context['offset']) ? (int) $context['offset'] : 0;
            $processed_before = isset($context['processed']) ? (int) $context['processed'] : 0;
            $total            = isset($context['total']) ? (int) $context['total'] : DBVC_Sync_Posts::get_total_export_post_count();

            $result = DBVC_Sync_Posts::export_posts_batch($chunk_size, $offset);

            $context['offset']     = $result['offset'];
            $context['processed']  = $processed_before + $result['processed'];
            $context['remaining']  = $result['remaining'];
            $context['total']      = $total > 0 ? $total : $result['total'];
            $context['chunk_size'] = $chunk_size;

            $progress = ($context['total'] > 0)
                ? min(1, $context['offset'] / $context['total'])
                : 1;

            $status = $result['remaining'] > 0 ? 'running' : 'done';

            DBVC_Database::update_job(
                $job_id,
                [
                    'status'   => $status,
                    'progress' => $progress,
                ],
                $context
            );

            DBVC_Database::log_activity(
                'export_chunk_processed',
                'info',
                'Chunk processed during export',
                [
                    'job_id'    => $job_id,
                    'processed' => $result['processed'],
                    'offset'    => $context['offset'],
                    'remaining' => $result['remaining'],
                    'progress'  => $progress,
                ]
            );

            $snapshot_id = null;

            if ('done' === $status) {
                if (class_exists('DBVC_Backup_Manager')) {
                    DBVC_Backup_Manager::generate_manifest(dbvc_get_sync_path());
                }

                if (class_exists('DBVC_Database')) {
                    $snapshot_id = DBVC_Database::insert_snapshot([
                        'type'      => 'chunked_export',
                        'sync_path' => dbvc_get_sync_path(),
                        'notes'     => wp_json_encode([
                            'job_id'    => $job_id,
                            'total'     => $context['total'],
                            'processed' => $context['processed'],
                            'chunk_size'=> $chunk_size,
                            'timestamp' => current_time('mysql', true),
                            'source'    => $creating_job ? 'manual' : 'manual',
                        ]),
                    ]);
                }

                DBVC_Database::log_activity(
                    'export_chunk_completed',
                    'success',
                    'Chunked export completed',
                    [
                        'job_id'      => $job_id,
                        'snapshot_id' => $snapshot_id,
                        'total'       => $context['total'],
                    ]
                );
            }

            return [
                'job_id'            => (int) $job_id,
                'status'            => $status,
                'chunk_size'        => $chunk_size,
                'processed_current' => $result['processed'],
                'processed_total'   => $context['processed'],
                'remaining'         => $result['remaining'],
                'offset'            => $context['offset'],
                'total'             => $context['total'],
                'progress'          => $progress,
                'snapshot_id'       => $snapshot_id,
                'message'           => ('done' === $status)
                    ? __('Chunked export completed.', 'dbvc')
                    : __('Chunk processed.', 'dbvc'),
            ];
        }
    }
}
