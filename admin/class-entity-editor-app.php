<?php

if (! defined('WPINC')) {
    die;
}

/**
 * Dedicated admin app loader for the Entity Editor page.
 */
final class DBVC_Entity_Editor_App
{
    /**
     * Bootstrap hooks.
     *
     * @return void
     */
    public static function init()
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('admin_post_dbvc_entity_editor_download', [self::class, 'handle_download']);
        add_action('admin_post_dbvc_entity_editor_download_bulk', [self::class, 'handle_bulk_download']);
    }

    /**
     * Enqueue the Entity Editor bundle only on its submenu page.
     *
     * @param string $hook
     * @return void
     */
    public static function enqueue_assets($hook)
    {
        $allowed_hooks = [
            'db-version-control_page_dbvc-entity-editor',
            'dbvc-export_page_dbvc-entity-editor',
        ];

        if (! in_array($hook, $allowed_hooks, true)) {
            return;
        }

        $asset = self::get_manifest_asset();
        if (! $asset) {
            return;
        }

        if (! empty($asset['css'])) {
            foreach ($asset['css'] as $handle => $url) {
                wp_enqueue_style(
                    $handle,
                    $url,
                    [],
                    $asset['version']
                );
            }
        }

        wp_enqueue_script(
            'dbvc-entity-editor-app',
            $asset['js'],
            ['wp-element', 'wp-i18n', 'wp-components'],
            $asset['version'],
            true
        );

        if (function_exists('wp_enqueue_style')) {
            wp_enqueue_style('wp-components');
        }

        wp_localize_script(
            'dbvc-entity-editor-app',
            'DBVC_ENTITY_EDITOR_APP',
            [
                'root' => esc_url_raw(rest_url('dbvc/v1/')),
                'nonce' => wp_create_nonce('wp_rest'),
                'download_url' => esc_url_raw(admin_url('admin-post.php')),
                'download_nonce' => wp_create_nonce('dbvc_entity_editor_download'),
                'download_bulk_nonce' => wp_create_nonce('dbvc_entity_editor_download_bulk'),
            ]
        );
    }

    /**
     * Handle secure entity JSON download request.
     *
     * @return void
     */
    public static function handle_download()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to download entity files.', 'dbvc'), 403);
        }

        check_admin_referer('dbvc_entity_editor_download');

        $relative_path = isset($_GET['path']) ? wp_unslash((string) $_GET['path']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $file = DBVC_Entity_Editor_Indexer::load_entity_file_for_download($relative_path);
        if (is_wp_error($file)) {
            $status_data = $file->get_error_data();
            $status = (int) (is_array($status_data) && isset($status_data['status']) ? $status_data['status'] : 0);
            if ($status <= 0) {
                $status = 400;
            }
            wp_die(esc_html($file->get_error_message()), $status);
        }

        $filename = isset($file['filename']) ? (string) $file['filename'] : 'entity.json';
        $content = isset($file['content']) ? (string) $file['content'] : '';

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . strlen($content));
        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Handle secure bulk entity JSON download request.
     *
     * @return void
     */
    public static function handle_bulk_download()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to download entity files.', 'dbvc'), 403);
        }

        check_admin_referer('dbvc_entity_editor_download_bulk');

        $paths_raw = isset($_POST['paths']) ? wp_unslash((string) $_POST['paths']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $decoded = json_decode($paths_raw, true);
        if (! is_array($decoded)) {
            wp_die(esc_html__('Invalid bulk download request.', 'dbvc'), 400);
        }

        $paths = [];
        foreach ($decoded as $path) {
            if (! is_string($path)) {
                continue;
            }
            $normalized = str_replace('\\', '/', ltrim(trim($path), '/'));
            if ($normalized === '') {
                continue;
            }
            $paths[$normalized] = $normalized;
        }
        $paths = array_values($paths);

        if (empty($paths)) {
            wp_die(esc_html__('No entity files selected for bulk download.', 'dbvc'), 400);
        }

        if (! class_exists('ZipArchive')) {
            wp_die(esc_html__('Bulk download requires PHP ZipArchive support.', 'dbvc'), 500);
        }

        $tmp_file = wp_tempnam('dbvc-entity-editor-');
        if (! is_string($tmp_file) || $tmp_file === '') {
            wp_die(esc_html__('Unable to create temporary archive file.', 'dbvc'), 500);
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmp_file, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp_file);
            wp_die(esc_html__('Unable to open ZIP archive for bulk download.', 'dbvc'), 500);
        }

        $added = 0;
        foreach ($paths as $relative_path) {
            $file = DBVC_Entity_Editor_Indexer::load_entity_file_for_download($relative_path);
            if (is_wp_error($file)) {
                continue;
            }

            $zip_entry = isset($file['relative_path']) ? (string) $file['relative_path'] : '';
            $content = isset($file['content']) ? (string) $file['content'] : '';
            if ($zip_entry === '' || $content === '') {
                continue;
            }

            if ($zip->addFromString($zip_entry, $content)) {
                $added++;
            }
        }

        $zip->close();

        if ($added <= 0 || ! is_file($tmp_file)) {
            @unlink($tmp_file);
            wp_die(esc_html__('No valid entity files were available for bulk download.', 'dbvc'), 404);
        }

        $filename = sprintf('dbvc-entities-%s.zip', gmdate('Ymd-His'));
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . filesize($tmp_file));
        readfile($tmp_file);
        @unlink($tmp_file);
        exit;
    }

    /**
     * Locate built assets (if present).
     *
     * @return array<string,mixed>|null
     */
    private static function get_manifest_asset()
    {
        $dir = DBVC_PLUGIN_PATH . 'build/';
        if (! is_dir($dir)) {
            return null;
        }

        $asset_file = $dir . 'admin-entity-editor.asset.php';
        if (! file_exists($asset_file)) {
            return null;
        }

        $asset = include $asset_file;

        $css = [];
        if (file_exists($dir . 'style-admin-app.css')) {
            $css['dbvc-admin-app'] = DBVC_PLUGIN_URL . 'build/style-admin-app.css';
        }
        if (file_exists($dir . 'style-admin-app-rtl.css')) {
            $css['dbvc-admin-app-rtl'] = DBVC_PLUGIN_URL . 'build/style-admin-app-rtl.css';
        }

        return [
            'js'      => DBVC_PLUGIN_URL . 'build/admin-entity-editor.js',
            'css'     => $css,
            'version' => isset($asset['version']) ? $asset['version'] : DBVC_PLUGIN_VERSION,
        ];
    }
}
