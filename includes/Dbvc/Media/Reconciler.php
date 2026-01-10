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
        ];

        $job_id = null;
        if (class_exists('\DBVC_Database')) {
            $job_id = \DBVC_Database::create_job('media_reconcile', array_merge($context, [
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
            \DBVC_Database::update_job($job_id, [
                'status'   => 'done',
                'progress' => 1,
            ], array_merge($context, ['metrics' => $result]));
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

        foreach ($attachments as $asset_key => $resolution) {
            $target_id  = isset($resolution['target_id']) ? (int) $resolution['target_id'] : 0;
            $descriptor = $resolution['descriptor'] ?? [];

            if ($target_id) {
                continue;
            }

            if (! in_array($resolution['status'], ['needs_download', 'missing'], true)) {
                $unresolved++;
                continue;
            }

            $registered = self::register_from_bundle($proposal_id, $descriptor, $resolver_options);
            if ($registered) {
                $created++;
                $resolver['attachments'][$asset_key]['target_id'] = $registered;
                $resolver['attachments'][$asset_key]['status']    = 'downloaded';
                $resolver['id_map'][$asset_key]                   = $registered;
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
        ]);

        return [
            'processed'  => count($attachments),
            'created'    => $created,
            'unresolved' => $unresolved,
            'bundle_dir' => $bundle_dir,
        ];
    }

    /**
     * Register an attachment from a bundle file.
     *
     * @param string $proposal_id
     * @param array  $descriptor
     * @param array  $options
     * @return int|null
     */
    private static function register_from_bundle(string $proposal_id, array $descriptor, array $options): ?int
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

        $uploads = wp_get_upload_dir();
        if (! empty($uploads['error'])) {
            return null;
        }

        $relative = $descriptor['path'] ?? '';
        if ($relative === '') {
            $relative = gmdate('Y/m') . '/' . sanitize_file_name($descriptor['filename'] ?? basename($bundle_file));
        }

        $relative = ltrim($relative, '/');
        $target   = trailingslashit($uploads['basedir']) . $relative;
        $target_dir = dirname($target);

        if (! is_dir($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        if (! @copy($bundle_file, $target)) {
            return null;
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
            return null;
        }

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

        return (int) $attachment_id;
    }
}
