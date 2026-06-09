<?php

namespace Dbvc\Media\Hydration;

if (! defined('WPINC')) {
    die;
}

/**
 * Classic admin-post controller for target-side media hydration package uploads.
 */
final class AdminUploadController
{
    public const ACTION = 'dbvc_media_hydration_package_upload';
    public const NONCE_ACTION = 'dbvc_media_hydration_package_upload';
    public const NONCE_FIELD = 'dbvc_media_hydration_package_upload_nonce';
    public const REPORT_OPTION = 'dbvc_media_hydration_upload_report';

    public static function init(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'handle_upload']);
    }

    public static function handle_upload(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to upload media hydration packages.', 'dbvc'), 403);
        }

        if (! wp_verify_nonce((string) ($_POST[self::NONCE_FIELD] ?? ''), self::NONCE_ACTION)) {
            wp_die(esc_html__('Invalid media hydration package upload request.', 'dbvc'), 403);
        }

        if (empty($_FILES['dbvc_media_hydration_package_zip']) || ! is_array($_FILES['dbvc_media_hydration_package_zip'])) {
            self::store_error_report('dbvc_media_hydration_missing_upload', __('Choose a media hydration ZIP package to upload.', 'dbvc'));
            self::redirect('error');
        }

        $file = $_FILES['dbvc_media_hydration_package_zip'];
        if (! empty($file['error']) && (int) $file['error'] !== UPLOAD_ERR_OK) {
            self::store_error_report('dbvc_media_hydration_upload_failed', __('Media hydration package upload failed.', 'dbvc'));
            self::redirect('error');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $handled = wp_handle_upload($file, [
            'test_form' => false,
            'mimes' => [
                'zip' => 'application/zip',
            ],
        ]);

        if (! is_array($handled) || isset($handled['error'])) {
            self::store_error_report('dbvc_media_hydration_upload_failed', is_array($handled) ? (string) ($handled['error'] ?? '') : __('Upload failed.', 'dbvc'));
            self::redirect('error');
        }

        $zip_path = (string) ($handled['file'] ?? '');
        $result = PackageImportService::import_zip($zip_path, [
            'package_id' => isset($_POST['dbvc_media_hydration_package_id']) ? sanitize_file_name(wp_unslash((string) $_POST['dbvc_media_hydration_package_id'])) : '',
            'overwrite' => ! empty($_POST['dbvc_media_hydration_overwrite_package']),
            'source_name' => isset($file['name']) ? sanitize_file_name((string) $file['name']) : basename($zip_path),
        ]);

        if ($zip_path !== '' && is_file($zip_path)) {
            @unlink($zip_path);
        }

        if (is_wp_error($result)) {
            self::store_error_report($result->get_error_code(), $result->get_error_message());
            self::redirect('error');
        }

        update_option(self::REPORT_OPTION, [
            'mode' => 'media_hydration_package',
            'status' => 'success',
            'generated_at' => current_time('mysql'),
            'message' => __('Media hydration package uploaded and staged. No media files were hydrated yet.', 'dbvc'),
            'result' => $result,
        ], false);

        self::redirect('success');
    }

    private static function store_error_report(string $code, string $message): void
    {
        update_option(self::REPORT_OPTION, [
            'mode' => 'media_hydration_package',
            'status' => 'error',
            'generated_at' => current_time('mysql'),
            'error_code' => sanitize_key($code),
            'message' => $message !== '' ? $message : __('Media hydration package upload failed.', 'dbvc'),
        ], false);
    }

    private static function redirect(string $status): void
    {
        $url = add_query_arg(
            'dbvc_media_hydration_upload',
            sanitize_key($status),
            admin_url('admin.php?page=dbvc-export')
        );

        wp_safe_redirect($url . '#dbvc-import-upload');
        exit;
    }
}
