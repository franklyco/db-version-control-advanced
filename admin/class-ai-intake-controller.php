<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_AI_Intake_Controller
{
    private const ACTION_DOWNLOAD_ARTIFACT = 'dbvc_ai_download_intake_artifact';
    private const ACTION_IMPORT_INTAKE = 'dbvc_ai_import_intake';
    private const ACTION_CANCEL_INTAKE = 'dbvc_ai_cancel_intake';

    /**
     * @return void
     */
    public static function init()
    {
        add_action('admin_post_' . self::ACTION_DOWNLOAD_ARTIFACT, [self::class, 'handle_download_artifact']);
        add_action('admin_post_' . self::ACTION_IMPORT_INTAKE, [self::class, 'handle_import_intake']);
        add_action('admin_post_' . self::ACTION_CANCEL_INTAKE, [self::class, 'handle_cancel_intake']);
    }

    /**
     * @param string $intake_id
     * @param string $relative_path
     * @return string
     */
    public static function get_download_url(string $intake_id, string $relative_path): string
    {
        $intake_id = sanitize_key($intake_id);
        $relative_path = ltrim(str_replace('\\', '/', (string) $relative_path), '/');

        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => self::ACTION_DOWNLOAD_ARTIFACT,
                    'intake_id' => $intake_id,
                    'artifact' => rawurlencode($relative_path),
                ],
                admin_url('admin-post.php')
            ),
            self::ACTION_DOWNLOAD_ARTIFACT . '_' . $intake_id . '_' . md5($relative_path)
        );
    }

    /**
     * @param string $intake_id
     * @return string
     */
    public static function get_import_url(string $intake_id): string
    {
        $intake_id = sanitize_key($intake_id);

        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => self::ACTION_IMPORT_INTAKE,
                    'intake_id' => $intake_id,
                ],
                admin_url('admin-post.php')
            ),
            self::ACTION_IMPORT_INTAKE . '_' . $intake_id
        );
    }

    /**
     * @param string $intake_id
     * @return string
     */
    public static function get_cancel_url(string $intake_id): string
    {
        $intake_id = sanitize_key($intake_id);

        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => self::ACTION_CANCEL_INTAKE,
                    'intake_id' => $intake_id,
                ],
                admin_url('admin-post.php')
            ),
            self::ACTION_CANCEL_INTAKE . '_' . $intake_id
        );
    }

    /**
     * @return void
     */
    public static function handle_download_artifact()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to download AI intake artifacts.', 'dbvc'), 403);
        }

        $intake_id = isset($_GET['intake_id']) ? sanitize_key((string) wp_unslash($_GET['intake_id'])) : '';
        $artifact = isset($_GET['artifact']) ? rawurldecode((string) wp_unslash($_GET['artifact'])) : '';
        if ($intake_id === '' || $artifact === '') {
            wp_die(esc_html__('Missing AI intake artifact parameters.', 'dbvc'), 400);
        }

        check_admin_referer(self::ACTION_DOWNLOAD_ARTIFACT . '_' . $intake_id . '_' . md5($artifact));

        if (! class_exists('\Dbvc\AiPackage\Storage')) {
            wp_die(esc_html__('AI intake storage service unavailable.', 'dbvc'), 500);
        }

        $artifact_path = \Dbvc\AiPackage\Storage::resolve_intake_artifact_path($intake_id, $artifact);
        if (is_wp_error($artifact_path)) {
            wp_die(esc_html($artifact_path->get_error_message()), 404);
        }

        if (! is_file($artifact_path) || ! is_readable($artifact_path)) {
            wp_die(esc_html__('Requested AI intake artifact could not be found.', 'dbvc'), 404);
        }

        $filename = sanitize_file_name(basename($artifact_path));
        $extension = strtolower(pathinfo($artifact_path, PATHINFO_EXTENSION));
        $content_type = 'text/plain; charset=utf-8';
        if ($extension === 'json') {
            $content_type = 'application/json; charset=utf-8';
        } elseif ($extension === 'md') {
            $content_type = 'text/markdown; charset=utf-8';
        } elseif ($extension === 'zip') {
            $content_type = 'application/zip';
        }

        nocache_headers();
        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($artifact_path));
        readfile($artifact_path);
        exit;
    }

    /**
     * @return void
     */
    public static function handle_import_intake()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to import AI intake packages.', 'dbvc'), 403);
        }

        $intake_id = isset($_REQUEST['intake_id']) ? sanitize_key((string) wp_unslash($_REQUEST['intake_id'])) : '';
        if ($intake_id === '') {
            wp_safe_redirect(add_query_arg('dbvc_upload', 'ai_import_failed', wp_get_referer()));
            exit;
        }

        check_admin_referer(self::ACTION_IMPORT_INTAKE . '_' . $intake_id);

        $report = get_option('dbvc_ai_upload_report');
        if (! is_array($report) || (($report['intake_id'] ?? '') !== $intake_id)) {
            wp_safe_redirect(add_query_arg('dbvc_upload', 'ai_import_missing', wp_get_referer()));
            exit;
        }

        $settings = class_exists('\Dbvc\AiPackage\Settings')
            ? \Dbvc\AiPackage\Settings::get_all_settings()
            : [];
        $warning_policy = isset($settings['validation']['warning_policy']) ? (string) $settings['validation']['warning_policy'] : 'confirm';
        $status = isset($report['status']) ? (string) $report['status'] : 'blocked';
        $has_warnings = ! empty($report['counts']['warnings']);
        $confirmed = ! empty($_REQUEST['dbvc_ai_confirm_warnings']);

        if ($status === 'blocked') {
            wp_safe_redirect(add_query_arg('dbvc_upload', 'ai_blocked', wp_get_referer()));
            exit;
        }

        if ($status === 'valid_with_warnings' && $has_warnings && $warning_policy === 'confirm' && ! $confirmed) {
            wp_safe_redirect(add_query_arg('dbvc_upload', 'ai_confirm_required', wp_get_referer()));
            exit;
        }

        if (! class_exists('\Dbvc\AiPackage\SubmissionPackageImporter')) {
            wp_safe_redirect(add_query_arg('dbvc_upload', 'ai_import_failed', wp_get_referer()));
            exit;
        }

        $import_result = \Dbvc\AiPackage\SubmissionPackageImporter::import_intake($intake_id, $report, []);
        if (is_wp_error($import_result)) {
            $report['import_result'] = [
                'status' => 'error',
                'message' => $import_result->get_error_message(),
            ];
            update_option('dbvc_ai_upload_report', $report, false);
            wp_safe_redirect(add_query_arg('dbvc_upload', 'ai_import_failed', wp_get_referer()));
            exit;
        }

        $report['import_result'] = $import_result;
        if (! isset($report['artifacts']) || ! is_array($report['artifacts'])) {
            $report['artifacts'] = [];
        }
        if (! empty($import_result['artifacts']) && is_array($import_result['artifacts'])) {
            $report['artifacts'] = array_merge($report['artifacts'], $import_result['artifacts']);
        }
        update_option('dbvc_ai_upload_report', $report, false);

        wp_safe_redirect(add_query_arg('dbvc_upload', 'ai_imported', wp_get_referer()));
        exit;
    }

    /**
     * @return void
     */
    public static function handle_cancel_intake()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to dismiss AI intake packages.', 'dbvc'), 403);
        }

        $intake_id = isset($_GET['intake_id']) ? sanitize_key((string) wp_unslash($_GET['intake_id'])) : '';
        if ($intake_id !== '') {
            check_admin_referer(self::ACTION_CANCEL_INTAKE . '_' . $intake_id);
        }

        delete_option('dbvc_ai_upload_report');
        wp_safe_redirect(add_query_arg('dbvc_upload', 'ai_cancelled', wp_get_referer()));
        exit;
    }
}
