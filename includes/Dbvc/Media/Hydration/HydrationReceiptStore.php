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
     * @param int $limit
     * @return array<int,array<string,mixed>>
     */
    public static function list_recent(int $limit = 25): array
    {
        $limit = min(100, max(1, absint($limit)));
        $base_dir = self::base_dir();
        if (is_wp_error($base_dir) || ! is_dir($base_dir)) {
            return [];
        }

        $files = [];
        $dates = scandir($base_dir);
        foreach (is_array($dates) ? $dates : [] as $date_dir) {
            if ($date_dir === '.' || $date_dir === '..' || ! preg_match('/^\d{8}$/', $date_dir)) {
                continue;
            }

            $dir = trailingslashit($base_dir) . $date_dir;
            if (! is_dir($dir)) {
                continue;
            }

            foreach (glob(trailingslashit($dir) . '*.json') ?: [] as $path) {
                $real = realpath($path);
                if (! is_string($real) || ! self::path_starts_with($real, $base_dir)) {
                    continue;
                }
                $files[] = self::receipt_summary($real);
            }
        }

        usort($files, static function (array $a, array $b): int {
            return (int) ($b['modified_unix'] ?? 0) <=> (int) ($a['modified_unix'] ?? 0);
        });

        return array_slice($files, 0, $limit);
    }

    /**
     * @param string $receipt_id
     * @return string|\WP_Error
     */
    public static function resolve_receipt_path(string $receipt_id)
    {
        $receipt_id = sanitize_file_name($receipt_id);
        if ($receipt_id === '' || $receipt_id === '.' || $receipt_id === '..' || strpos($receipt_id, '/') !== false || strpos($receipt_id, '\\') !== false) {
            return new \WP_Error('dbvc_media_hydration_bad_receipt_id', __('Invalid media hydration receipt ID.', 'dbvc'), ['status' => 400]);
        }

        $base_dir = self::base_dir();
        if (is_wp_error($base_dir)) {
            return $base_dir;
        }

        foreach (glob(trailingslashit($base_dir) . '*/' . $receipt_id . '.json') ?: [] as $path) {
            $real = realpath($path);
            if (is_string($real) && is_file($real) && is_readable($real) && self::path_starts_with($real, $base_dir)) {
                return wp_normalize_path($real);
            }
        }

        return new \WP_Error('dbvc_media_hydration_receipt_missing', __('Media hydration receipt was not found.', 'dbvc'), ['status' => 404]);
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
     * @param string $path
     * @return array<string,mixed>
     */
    private static function receipt_summary(string $path): array
    {
        $raw = is_readable($path) ? file_get_contents($path) : '';
        $payload = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $receipt = is_array($payload) && isset($payload['receipt']) && is_array($payload['receipt']) ? $payload['receipt'] : [];
        $summary = is_array($payload) && isset($payload['summary']) && is_array($payload['summary']) ? $payload['summary'] : [];
        $plan_summary = is_array($payload) && isset($payload['plan_summary']) && is_array($payload['plan_summary']) ? $payload['plan_summary'] : [];
        $receipt_id = (string) ($receipt['id'] ?? pathinfo($path, PATHINFO_FILENAME));
        $type = (string) ($receipt['type'] ?? (self::ends_with($receipt_id, '-plan') ? 'plan' : (self::ends_with($receipt_id, '-apply') ? 'apply' : '')));

        return [
            'receipt_id' => $receipt_id,
            'run_id' => (string) ($receipt['run_id'] ?? ''),
            'type' => $type,
            'created_at' => (string) ($receipt['created_at'] ?? ''),
            'modified_at' => gmdate('c', (int) filemtime($path)),
            'modified_unix' => (int) filemtime($path),
            'path' => wp_normalize_path($path),
            'download_url' => class_exists(__NAMESPACE__ . '\ReceiptDownloadController')
                ? ReceiptDownloadController::download_url_for_receipt($receipt_id)
                : '',
            'summary' => [
                'items' => (int) ($summary['items'] ?? $plan_summary['items'] ?? 0),
                'hydrated' => (int) ($summary['hydrated'] ?? 0),
                'metadata_repaired' => (int) ($summary['metadata_repaired'] ?? 0),
                'blocked' => (int) ($summary['blocked'] ?? $plan_summary['blocked'] ?? 0),
                'errors' => (int) ($summary['errors'] ?? 0),
                'needs_hydration' => (int) ($plan_summary['needs_hydration'] ?? 0),
                'needs_metadata_repair' => (int) ($plan_summary['needs_metadata_repair'] ?? 0),
            ],
        ];
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

    private static function path_starts_with(string $path, string $base): bool
    {
        $path = rtrim(wp_normalize_path($path), '/') . '/';
        $base = rtrim(wp_normalize_path($base), '/') . '/';
        return strpos($path, $base) === 0;
    }

    private static function ends_with(string $value, string $suffix): bool
    {
        if ($suffix === '') {
            return true;
        }

        return substr($value, -strlen($suffix)) === $suffix;
    }
}
