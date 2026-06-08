<?php

namespace Dbvc\Media\Hydration;

if (! defined('WPINC')) {
    die;
}

/**
 * Secure admin-post downloads for generated media mirror ZIP packages.
 */
final class PackageDownloadController
{
    public const ACTION = 'dbvc_media_hydration_package_download';
    public const NONCE_ACTION = 'dbvc_media_hydration_package_download';

    public static function init(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'handle_download']);
    }

    public static function handle_download(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to download media hydration packages.', 'dbvc'), 403);
        }

        $package_id = isset($_GET['package_id']) ? sanitize_file_name(wp_unslash((string) $_GET['package_id'])) : '';
        if ($package_id === '') {
            wp_die(esc_html__('Invalid media hydration package ID.', 'dbvc'), 400);
        }

        if (! wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), self::NONCE_ACTION . '_' . $package_id)) {
            wp_die(esc_html__('Invalid media hydration package download request.', 'dbvc'), 403);
        }

        $zip_path = self::resolve_zip_path($package_id);
        if (is_wp_error($zip_path)) {
            $error_data = $zip_path->get_error_data();
            $status = is_array($error_data) && isset($error_data['status']) ? (int) $error_data['status'] : 404;
            wp_die(esc_html($zip_path->get_error_message()), $status);
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($zip_path) . '"');
        header('Content-Length: ' . (string) filesize($zip_path));
        header('X-Content-Type-Options: nosniff');

        readfile($zip_path);
        exit;
    }

    public static function download_url_for_package(string $package_id): string
    {
        $package_id = sanitize_file_name($package_id);
        if ($package_id === '') {
            return '';
        }

        return esc_url_raw(
            add_query_arg(
                [
                    'action' => self::ACTION,
                    'package_id' => $package_id,
                    '_wpnonce' => wp_create_nonce(self::NONCE_ACTION . '_' . $package_id),
                ],
                admin_url('admin-post.php')
            )
        );
    }

    /**
     * @return string|\WP_Error
     */
    public static function resolve_zip_path(string $package_id)
    {
        $package_id = sanitize_file_name($package_id);
        if ($package_id === '' || $package_id === '.' || $package_id === '..' || strpos($package_id, '/') !== false || strpos($package_id, '\\') !== false) {
            return new \WP_Error('dbvc_media_hydration_bad_package_id', __('Invalid media hydration package ID.', 'dbvc'), ['status' => 400]);
        }

        $root = self::media_mirror_root();
        if (is_wp_error($root)) {
            return $root;
        }

        $candidate = trailingslashit($root) . $package_id . '.zip';
        $real = realpath($candidate);
        if (! is_string($real) || ! is_file($real) || ! is_readable($real)) {
            return new \WP_Error('dbvc_media_hydration_zip_missing', __('Media hydration ZIP package was not found.', 'dbvc'), ['status' => 404]);
        }

        $real = wp_normalize_path($real);
        $root = rtrim(wp_normalize_path($root), '/') . '/';
        if (strpos($real . '/', $root) !== 0 || strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'zip') {
            return new \WP_Error('dbvc_media_hydration_zip_not_allowed', __('Media hydration ZIP package is outside the allowed media mirror directory.', 'dbvc'), ['status' => 403]);
        }

        return $real;
    }

    /**
     * @return string|\WP_Error
     */
    private static function media_mirror_root()
    {
        $root = function_exists('dbvc_get_sync_path')
            ? dbvc_get_sync_path('media-mirrors')
            : trailingslashit(WP_CONTENT_DIR) . 'uploads/dbvc-media-mirrors';

        if (! is_dir($root)) {
            return new \WP_Error('dbvc_media_hydration_root_missing', __('Media hydration package directory does not exist.', 'dbvc'), ['status' => 404]);
        }

        $real = realpath($root);
        if (! is_string($real) || $real === '') {
            return new \WP_Error('dbvc_media_hydration_root_missing', __('Media hydration package directory does not exist.', 'dbvc'), ['status' => 404]);
        }

        return wp_normalize_path($real);
    }
}
