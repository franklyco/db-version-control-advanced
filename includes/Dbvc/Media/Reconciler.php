<?php

namespace Dbvc\Media;

/**
 * Deterministic media reconciliation workflow.
 *
 * Ensures bundles are processed before proposal apply runs.
 */
final class Reconciler
{
    /**
     * Kick off a reconciliation run.
     *
     * @param string $proposal_id
     * @param array  $manifest
     * @param array  $args
     * @return array
     */
    public static function enqueue(string $proposal_id, array $manifest, array $args = []): array
    {
        $proposal_id = sanitize_file_name($proposal_id);
        if ($proposal_id === '' || empty($manifest['media_index'])) {
            return ['processed' => 0, 'created' => 0];
        }

        $context = [
            'proposal_id' => $proposal_id,
            'bundle_meta' => isset($manifest['media_bundle']) && is_array($manifest['media_bundle'])
                ? $manifest['media_bundle']
                : [],
            'allow_remote' => ! empty($args['allow_remote']),
            'manifest_dir' => isset($args['backup_path']) ? $args['backup_path'] : null,
            'resolver_decisions' => isset($args['resolver_decisions']) && is_array($args['resolver_decisions'])
                ? $args['resolver_decisions']
                : (class_exists('\DBVC_Media_Sync')
                    ? \DBVC_Media_Sync::get_effective_resolver_decisions($proposal_id)
                    : []),
        ];

        $job_context = $context;
        unset($job_context['resolver_decisions']);
        $job_context['decision_count'] = count($context['resolver_decisions']);

        $job_id = null;
        if (class_exists('\DBVC_Database')) {
            $job_id = \DBVC_Database::create_job('media_reconcile', array_merge($job_context, [
                'total' => count($manifest['media_index']),
            ]), 'running');
        }

        Logger::log('media:enqueue', 'Media reconciliation enqueued', [
            'proposal_id' => $proposal_id,
            'job_id'      => $job_id,
            'total'       => count($manifest['media_index']),
        ]);

        $result = self::run($manifest, $context);

        if ($job_id && class_exists('\DBVC_Database')) {
            $job_metrics = $result;
            unset(
                $job_metrics['resolver'],
                $job_metrics['id_map'],
                $job_metrics['original_id_map'],
                $job_metrics['decision_results']
            );
            \DBVC_Database::update_job($job_id, [
                'status'   => 'done',
                'progress' => 1,
            ], array_merge($job_context, ['metrics' => $job_metrics]));
        }

        return $result;
    }

    /**
     * Execute reconciliation synchronously.
     *
     * @param array $manifest
     * @param array $context
     * @return array
     */
    private static function run(array $manifest, array $context): array
    {
        $proposal_id = $context['proposal_id'];
        $bundle_meta = $context['bundle_meta'];

        $bundle_dir = null;
        if (! empty($context['manifest_dir'])) {
            $ingested = BundleManager::ingest_from_backup($proposal_id, $context['manifest_dir']);
            if ($ingested) {
                $bundle_dir = $ingested;
            }
        }

        if (! $bundle_dir) {
            $bundle_dir = BundleManager::get_proposal_directory($proposal_id);
        }

        $resolver_options = [
            'allow_remote'     => $context['allow_remote'],
            'dry_run'          => false,
            'proposal_id'      => $proposal_id,
            'bundle_meta'      => $bundle_meta,
            'bundle_dir'       => $bundle_dir,
            'manifest_dir'     => $context['manifest_dir'] ?? null,
        ];

        $resolver = \Dbvc\Media\Resolver::resolve_manifest($manifest, $resolver_options);

        $attachments = $resolver['attachments'] ?? [];
        $created     = 0;
        $unresolved  = 0;
        $original_id_map = [];
        $decision_results = [];
        $decisions = isset($context['resolver_decisions']) && is_array($context['resolver_decisions'])
            ? $context['resolver_decisions']
            : [];

        foreach ($attachments as $asset_key => $resolution) {
            $target_id  = isset($resolution['target_id']) ? (int) $resolution['target_id'] : 0;
            $descriptor = $resolution['descriptor'] ?? [];
            $original_id = isset($descriptor['original_id']) ? absint($descriptor['original_id']) : 0;
            $decision = $original_id && isset($decisions[(string) $original_id])
                ? $decisions[(string) $original_id]
                : null;

            if (is_array($decision)) {
                $action = isset($decision['action']) ? sanitize_key($decision['action']) : '';
                $source = isset($decision['source']) && $decision['source'] === 'global' ? 'global' : 'proposal';

                if (in_array($action, ['reuse', 'map'], true)) {
                    $target_id = isset($decision['target_id']) ? absint($decision['target_id']) : 0;
                    if (self::is_valid_target($target_id)) {
                        $resolver['attachments'][$asset_key]['target_id']    = $target_id;
                        $resolver['attachments'][$asset_key]['status']       = $action === 'map' ? 'mapped' : 'reused';
                        $resolver['attachments'][$asset_key]['resolved_via'] = 'decision:' . $source;
                        $resolver['attachments'][$asset_key]['reason']       = null;
                        $resolver['id_map'][$asset_key] = $target_id;
                        $original_id_map[$original_id] = $target_id;
                        $decision_results[$original_id] = self::decision_result(
                            $original_id,
                            $decision,
                            'applied',
                            $target_id
                        );
                    } else {
                        $resolver['attachments'][$asset_key]['target_id']    = null;
                        $resolver['attachments'][$asset_key]['status']       = 'decision_failed';
                        $resolver['attachments'][$asset_key]['resolved_via'] = 'decision:' . $source;
                        $resolver['attachments'][$asset_key]['reason']       = 'invalid_attachment_target';
                        unset($resolver['id_map'][$asset_key]);
                        $unresolved++;
                        $decision_results[$original_id] = self::decision_result(
                            $original_id,
                            $decision,
                            'failed',
                            0,
                            'invalid_attachment_target'
                        );
                    }
                    continue;
                }

                if ($action === 'skip') {
                    $resolver['attachments'][$asset_key]['target_id']    = null;
                    $resolver['attachments'][$asset_key]['status']       = 'skipped';
                    $resolver['attachments'][$asset_key]['resolved_via'] = 'decision:' . $source;
                    $resolver['attachments'][$asset_key]['reason']       = null;
                    unset($resolver['id_map'][$asset_key]);
                    $decision_results[$original_id] = self::decision_result($original_id, $decision, 'applied');
                    continue;
                }

                if ($action === 'download') {
                    unset($resolver['id_map'][$asset_key]);
                    $registered = self::register_from_bundle($proposal_id, $descriptor, $resolver_options);
                    if (is_wp_error($registered)) {
                        $reason = sanitize_key($registered->get_error_code());
                        $unresolved++;
                        $resolver['attachments'][$asset_key]['target_id']    = null;
                        $resolver['attachments'][$asset_key]['status']       = 'decision_failed';
                        $resolver['attachments'][$asset_key]['resolved_via'] = 'decision:' . $source;
                        $resolver['attachments'][$asset_key]['reason']       = $reason;
                        $decision_results[$original_id] = self::decision_result(
                            $original_id,
                            $decision,
                            'failed',
                            0,
                            $reason
                        );
                        Logger::log('media:download', 'Bundled attachment failed resolver validation', [
                            'proposal_id' => $proposal_id,
                            'asset_uid'   => $descriptor['asset_uid'] ?? '',
                            'reason'      => $reason,
                        ]);
                    } elseif ($registered) {
                        $created++;
                        $resolver['attachments'][$asset_key]['target_id']    = $registered;
                        $resolver['attachments'][$asset_key]['status']       = 'downloaded';
                        $resolver['attachments'][$asset_key]['resolved_via'] = 'decision:' . $source;
                        $resolver['attachments'][$asset_key]['reason']       = null;
                        $resolver['id_map'][$asset_key] = $registered;
                        $original_id_map[$original_id] = $registered;
                        $decision_results[$original_id] = self::decision_result(
                            $original_id,
                            $decision,
                            'applied',
                            $registered
                        );
                        Logger::log('media:download', 'Attachment registered from bundle by resolver decision', [
                            'proposal_id'  => $proposal_id,
                            'asset_uid'    => $descriptor['asset_uid'] ?? '',
                            'attachment_id'=> $registered,
                            'source'       => $source,
                        ]);
                    } else {
                        $unresolved++;
                        $resolver['attachments'][$asset_key]['target_id']    = null;
                        $resolver['attachments'][$asset_key]['status']       = 'needs_download';
                        $resolver['attachments'][$asset_key]['resolved_via'] = 'decision:' . $source;
                        $resolver['attachments'][$asset_key]['reason']       = 'forced_download_pending';
                        $decision_results[$original_id] = self::decision_result(
                            $original_id,
                            $decision,
                            'pending',
                            0,
                            'forced_download_pending'
                        );
                    }
                    continue;
                }
            }

            if ($target_id) {
                if ($original_id) {
                    $original_id_map[$original_id] = $target_id;
                }
                continue;
            }

            if (! in_array($resolution['status'], ['needs_download', 'missing'], true)) {
                $unresolved++;
                continue;
            }

            $registered = self::register_from_bundle($proposal_id, $descriptor, $resolver_options);
            if (is_wp_error($registered)) {
                $unresolved++;
                $resolver['attachments'][$asset_key]['target_id'] = null;
                $resolver['attachments'][$asset_key]['status']    = 'decision_failed';
                $resolver['attachments'][$asset_key]['reason']    = sanitize_key($registered->get_error_code());
                unset($resolver['id_map'][$asset_key]);
                Logger::log('media:download', 'Bundled attachment failed resolver validation', [
                    'proposal_id' => $proposal_id,
                    'asset_uid'   => $descriptor['asset_uid'] ?? '',
                    'reason'      => sanitize_key($registered->get_error_code()),
                ]);
            } elseif ($registered) {
                $created++;
                $resolver['attachments'][$asset_key]['target_id'] = $registered;
                $resolver['attachments'][$asset_key]['status']    = 'downloaded';
                $resolver['id_map'][$asset_key]                   = $registered;
                if ($original_id) {
                    $original_id_map[$original_id] = $registered;
                }
                Logger::log('media:download', 'Attachment registered from bundle', [
                    'proposal_id' => $proposal_id,
                    'asset_uid'   => $descriptor['asset_uid'] ?? '',
                    'attachment_id'=> $registered,
                ]);
            } else {
                $unresolved++;
                Logger::log('media:download', 'Bundle file missing during reconcile', [
                    'proposal_id' => $proposal_id,
                    'asset_uid'   => $descriptor['asset_uid'] ?? '',
                ]);
            }
        }

        Logger::log('media:map', 'Media reconciliation completed', [
            'proposal_id' => $proposal_id,
            'created'     => $created,
            'unresolved'  => $unresolved,
            'decisions'   => self::summarize_decision_results($decision_results),
        ]);

        $resolver = self::rebuild_resolver_metrics($resolver);
        $decision_summary = self::summarize_decision_results($decision_results);
        foreach ($resolver['attachments'] ?? [] as $asset_key => $resolution) {
            $original_id = isset($resolution['descriptor']['original_id'])
                ? absint($resolution['descriptor']['original_id'])
                : 0;
            if ($original_id && isset($decision_results[$original_id])) {
                $resolver['attachments'][$asset_key]['decision'] = $decision_results[$original_id];
            }
        }
        $resolver['decision_summary'] = $decision_summary;

        return [
            'processed'        => count($attachments),
            'created'          => $created,
            'unresolved'       => $unresolved,
            'bundle_dir'       => $bundle_dir,
            'id_map'           => $resolver['id_map'] ?? [],
            'original_id_map'  => $original_id_map,
            'decision_summary' => $decision_summary,
            'decision_results' => array_values($decision_results),
            'resolver'         => $resolver,
        ];
    }

    /**
     * Build one normalized decision result.
     *
     * @param int    $original_id
     * @param array  $decision
     * @param string $status
     * @param int    $target_id
     * @param string $reason
     * @return array
     */
    private static function decision_result(
        int $original_id,
        array $decision,
        string $status,
        int $target_id = 0,
        string $reason = ''
    ): array {
        $source = isset($decision['source']) && $decision['source'] === 'global' ? 'global' : 'proposal';
        return [
            'original_id' => $original_id,
            'action'      => sanitize_key($decision['action'] ?? ''),
            'source'      => $source,
            'scope'       => $source,
            'status'      => sanitize_key($status),
            'target_id'   => $target_id > 0 ? $target_id : null,
            'reason'      => sanitize_key($reason),
        ];
    }

    /**
     * Summarize decisions handled by reconciliation.
     *
     * @param array $results
     * @return array
     */
    private static function summarize_decision_results(array $results): array
    {
        $summary = [
            'total'    => 0,
            'applied'  => 0,
            'failed'   => 0,
            'pending'  => 0,
            'reuse'    => 0,
            'map'      => 0,
            'download' => 0,
            'skip'     => 0,
            'sources'  => [
                'proposal' => 0,
                'global'   => 0,
            ],
        ];

        foreach ($results as $result) {
            $summary['total']++;
            if (isset($summary[$result['status']])) {
                $summary[$result['status']]++;
            }
            if ($result['status'] === 'applied' && isset($summary[$result['action']])) {
                $summary[$result['action']]++;
            }
            if (isset($summary['sources'][$result['source']])) {
                $summary['sources'][$result['source']]++;
            }
        }

        return $summary;
    }

    /**
     * Validate a manual attachment target without changing it.
     *
     * @param int $target_id
     * @return bool
     */
    private static function is_valid_target(int $target_id): bool
    {
        if (class_exists('\DBVC_Media_Sync')) {
            return \DBVC_Media_Sync::is_valid_resolver_target($target_id);
        }
        return $target_id > 0 && get_post_type($target_id) === 'attachment';
    }

    /**
     * Recalculate resolver metrics after decisions alter statuses.
     *
     * @param array $resolver
     * @return array
     */
    private static function rebuild_resolver_metrics(array $resolver): array
    {
        $metrics = [
            'detected'    => 0,
            'reused'      => 0,
            'downloaded'  => 0,
            'skipped'     => 0,
            'unresolved'  => 0,
            'blocked'     => 0,
            'bundle_hits' => 0,
        ];
        $conflicts = [];

        foreach ($resolver['attachments'] ?? [] as $resolution) {
            $metrics['detected']++;
            $status = isset($resolution['status']) ? (string) $resolution['status'] : 'unresolved';
            if (in_array($status, ['reused', 'mapped'], true)) {
                $metrics['reused']++;
            } elseif ($status === 'downloaded') {
                $metrics['downloaded']++;
            } elseif ($status === 'skipped') {
                $metrics['skipped']++;
            } else {
                $metrics['unresolved']++;
            }

            if (! empty($resolution['blocked_reason'])) {
                $metrics['blocked']++;
            }
            if (! empty($resolution['bundle_hit'])) {
                $metrics['bundle_hits']++;
            }
            if (in_array($status, ['conflict', 'decision_failed'], true)) {
                $conflicts[] = $resolution;
            }
        }

        $resolver['metrics']   = $metrics;
        $resolver['conflicts'] = $conflicts;
        return $resolver;
    }

    /**
     * Register an attachment from a bundle file.
     *
     * @param string $proposal_id
     * @param array  $descriptor
     * @param array  $options
     * @return int|null|\WP_Error
     */
    private static function register_from_bundle(string $proposal_id, array $descriptor, array $options)
    {
        $bundle_file = BundleManager::locate_bundle_file(
            $proposal_id,
            $descriptor['bundle_path'] ?? '',
            $options['bundle_meta'] ?? [],
            [
                'bundle_dir'   => $options['bundle_dir'] ?? '',
                'manifest_dir' => $options['manifest_dir'] ?? '',
            ]
        );

        if (! $bundle_file || ! file_exists($bundle_file)) {
            return null;
        }

        $expected_hash = strtolower(trim((string) ($descriptor['file_hash'] ?? '')));
        if ($expected_hash !== '') {
            if (strpos($expected_hash, ':') !== false) {
                [, $expected_hash] = explode(':', $expected_hash, 2);
                $expected_hash = trim($expected_hash);
            }
            if (! preg_match('/^[a-f0-9]{64}$/', $expected_hash)) {
                return new \WP_Error(
                    'bundle_hash_invalid',
                    __('Bundled media has an invalid expected hash.', 'dbvc')
                );
            }

            $actual_hash = hash_file('sha256', $bundle_file);
            if (! is_string($actual_hash) || ! hash_equals($expected_hash, strtolower($actual_hash))) {
                return new \WP_Error(
                    'bundle_hash_mismatch',
                    __('Bundled media did not match its expected hash.', 'dbvc')
                );
            }
        }

        $uploads = wp_get_upload_dir();
        if (! empty($uploads['error'])) {
            return null;
        }

        $relative = $descriptor['path'] ?? '';
        if ($relative === '') {
            $relative = gmdate('Y/m') . '/' . sanitize_file_name($descriptor['filename'] ?? basename($bundle_file));
        }

        $relative = ltrim($relative, '/');
        $target_dir = dirname(trailingslashit($uploads['basedir']) . $relative);

        if (! is_dir($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        $target = trailingslashit($target_dir) . wp_unique_filename($target_dir, basename($relative));

        if (! @copy($bundle_file, $target)) {
            return new \WP_Error(
                'bundle_copy_failed',
                __('Bundled media could not be copied into uploads.', 'dbvc')
            );
        }

        $attachment = [
            'post_mime_type' => $descriptor['mime_type'] ?? wp_check_filetype($target)['type'] ?? 'application/octet-stream',
            'post_title'     => sanitize_text_field($descriptor['filename'] ?? basename($target)),
            'post_status'    => 'inherit',
            'post_content'   => '',
        ];

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = wp_insert_attachment($attachment, $target);
        if (is_wp_error($attachment_id)) {
            @unlink($target);
            return $attachment_id;
        }

        update_attached_file($attachment_id, $target);
        $metadata = wp_generate_attachment_metadata($attachment_id, $target);
        if (! is_wp_error($metadata) && ! empty($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        if (! empty($descriptor['asset_uid'])) {
            update_post_meta($attachment_id, 'vf_asset_uid', $descriptor['asset_uid']);
        }
        if (! empty($descriptor['file_hash'])) {
            update_post_meta($attachment_id, 'vf_file_hash', $descriptor['file_hash']);
        }
        if (! empty($descriptor['original_id'])) {
            update_post_meta($attachment_id, '_dbvc_original_attachment_id', (int) $descriptor['original_id']);
        }
        if (! empty($descriptor['source_url'])) {
            update_post_meta($attachment_id, '_dbvc_original_source_url', esc_url_raw($descriptor['source_url']));
        }

        return (int) $attachment_id;
    }
}
