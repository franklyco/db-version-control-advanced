<?php

namespace Dbvc\Media\Hydration;

if (! defined('WPINC')) {
    die;
}

/**
 * Persists media hydration plans and apply reports as JSON receipts.
 */
final class HydrationReceiptStore
{
    /**
     * Persist a hydration receipt.
     *
     * @param string $type    Receipt type, such as plan or apply.
     * @param array  $payload Receipt payload.
     * @param array  $args {
     *   @type string $run_id Optional stable run identifier.
     * }
     * @return array<string,string>|\WP_Error
     */
    public static function write(string $type, array $payload, array $args = [])
    {
        $type = sanitize_key($type);
        if ($type === '') {
            return new \WP_Error('dbvc_media_hydration_bad_receipt_type', __('Invalid media hydration receipt type.', 'dbvc'));
        }

        $base_dir = self::base_dir();
        if (is_wp_error($base_dir)) {
            return $base_dir;
        }

        $date_dir = trailingslashit($base_dir) . gmdate('Ymd');
        if (! is_dir($date_dir) && ! wp_mkdir_p($date_dir)) {
            return new \WP_Error('dbvc_media_hydration_receipt_dir_failed', __('Unable to create media hydration receipt directory.', 'dbvc'));
        }
        self::ensure_directory_security($date_dir);

        $run_id = self::sanitize_run_id((string) ($args['run_id'] ?? ''));
        $receipt_id = $run_id . '-' . $type;
        $path = trailingslashit($date_dir) . $receipt_id . '.json';

        $receipt = $payload;
        $receipt['receipt'] = [
            'id' => $receipt_id,
            'run_id' => $run_id,
            'type' => $type,
            'created_at' => gmdate('c'),
        ];

        $encoded = wp_json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || $encoded === '') {
            return new \WP_Error('dbvc_media_hydration_receipt_encode_failed', __('Unable to encode media hydration receipt.', 'dbvc'));
        }

        if (file_put_contents($path, $encoded) === false) {
            return new \WP_Error('dbvc_media_hydration_receipt_write_failed', __('Unable to write media hydration receipt.', 'dbvc'));
        }

        return [
            'run_id' => $run_id,
            'receipt_id' => $receipt_id,
            'receipt_type' => $type,
            'receipt_path' => wp_normalize_path($path),
        ];
    }

    /**
     * @return string|\WP_Error
     */
    public static function base_dir()
    {
        $root = function_exists('dbvc_get_sync_path')
            ? trailingslashit(dbvc_get_sync_path('media-hydration'))
            : trailingslashit(WP_CONTENT_DIR) . 'uploads/dbvc-media-hydration/';

        if (! is_dir($root) && ! wp_mkdir_p($root)) {
            return new \WP_Error('dbvc_media_hydration_receipt_root_failed', __('Unable to create media hydration receipt root.', 'dbvc'));
        }
        self::ensure_directory_security($root);

        $receipts = trailingslashit($root) . 'receipts';
        if (! is_dir($receipts) && ! wp_mkdir_p($receipts)) {
            return new \WP_Error('dbvc_media_hydration_receipt_root_failed', __('Unable to create media hydration receipt root.', 'dbvc'));
        }
        self::ensure_directory_security($receipts);

        return wp_normalize_path($receipts);
    }

    /**
     * @param string $run_id
     * @return string
     */
    private static function sanitize_run_id(string $run_id): string
    {
        $run_id = sanitize_file_name($run_id);
        if ($run_id === '') {
            $run_id = 'media-hydration-' . gmdate('Ymd-His') . '-' . wp_generate_password(8, false, false);
        }

        return $run_id;
    }

    /**
     * @param string $path
     * @return void
     */
    private static function ensure_directory_security(string $path): void
    {
        if (class_exists('\DBVC_Sync_Posts') && method_exists('\DBVC_Sync_Posts', 'ensure_directory_security')) {
            \DBVC_Sync_Posts::ensure_directory_security($path);
            return;
        }

        $index = trailingslashit($path) . 'index.php';
        if (! file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }
}
