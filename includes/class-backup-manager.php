<?php

/**
 * Helper for managing DBVC backup archives and manifests.
 *
 * @package   DB Version Control
 */

if (! defined('WPINC')) {
    die;
}

if (! class_exists('DBVC_Backup_Manager')) {
    class DBVC_Backup_Manager
    {
        const MANIFEST_FILENAME = 'manifest.json';
        const OPTION_LOCKED     = 'dbvc_locked_backups';
        const SCHEMA_VERSION    = 1;

        /**
         * Return the absolute base directory for stored backups.
         *
         * @return string
         */
        public static function get_base_path()
        {
            $upload_dir = wp_upload_dir();
            $sync_dir   = trailingslashit($upload_dir['basedir']) . 'sync';
            $base       = trailingslashit($sync_dir) . 'db-version-control-backups';

            if (! is_dir($base)) {
                wp_mkdir_p($base);
            }

            // Harden directory.
            if (function_exists('dbvc_get_sync_path') && method_exists('DBVC_Sync_Posts', 'ensure_directory_security')) {
                DBVC_Sync_Posts::ensure_directory_security($base);
            }

            return $base;
        }

        /**
         * Create the manifest file for a completed backup.
         *
         * @param string $backup_path Absolute path to the backup folder.
         * @return void
         */
        public static function generate_manifest($backup_path)
        {
            if (! is_dir($backup_path)) {
                return;
            }

            $items          = [];
            $missing_hashes = 0;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($backup_path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'json') {
                    continue;
                }

                $relative = ltrim(str_replace($backup_path, '', $file->getPathname()), '/');
                $raw      = file_get_contents($file->getPathname());
                if ($raw === false) {
                    DBVC_Sync_Logger::log('Failed to read JSON while generating manifest', ['file' => $relative]);
                    continue;
                }

                $decoded = json_decode($raw, true);
                if (! is_array($decoded)) {
                    DBVC_Sync_Logger::log('Invalid JSON encountered while generating manifest', ['file' => $relative]);
                    continue;
                }

                $item_type       = 'generic';
                $has_import_hash = true;
                $content_hash    = null;
                $post_id         = null;
                $post_type       = '';
                $post_title      = '';
                $post_status     = '';
                $post_name       = '';
                $post_date       = '';
                $post_modified   = '';

                if (isset($decoded['ID'], $decoded['post_type'])) {
                    $item_type = 'post';

                    $has_import_hash = false;
                    if (isset($decoded['meta']['_dbvc_import_hash'])) {
                        $hash_source = $decoded['meta']['_dbvc_import_hash'];
                        if (is_array($hash_source)) {
                            $hash_source = reset($hash_source);
                        }
                        $has_import_hash = ! empty($hash_source);
                    }

                    $meta_for_hash = isset($decoded['meta']) && is_array($decoded['meta'])
                        ? $decoded['meta']
                        : [];

                    if (isset($meta_for_hash['_dbvc_import_hash'])) {
                        unset($meta_for_hash['_dbvc_import_hash']);
                    }

                    $content_hash = md5(serialize([
                        $decoded['post_content'] ?? '',
                        $meta_for_hash,
                    ]));

                    if (! $has_import_hash) {
                        $missing_hashes++;
                    }

                    $post_id       = absint($decoded['ID']);
                    $post_type     = sanitize_key($decoded['post_type']);
                    $post_title    = isset($decoded['post_title']) ? sanitize_text_field($decoded['post_title']) : '';
                    $post_status   = isset($decoded['post_status']) ? sanitize_text_field($decoded['post_status']) : '';
                    $post_name     = isset($decoded['post_name']) ? sanitize_title($decoded['post_name']) : '';
                    $post_date     = isset($decoded['post_date']) ? sanitize_text_field($decoded['post_date']) : '';
                    $post_modified = isset($decoded['post_modified']) ? sanitize_text_field($decoded['post_modified']) : '';
                } elseif (basename($relative) === 'options.json') {
                    $item_type = 'options';
                } elseif (basename($relative) === 'menus.json') {
                    $item_type = 'menus';
                }

                $items[] = [
                    'path'             => $relative,
                    'hash'             => hash('sha256', $raw),
                    'size'             => $file->getSize(),
                    'item_type'        => $item_type,
                    'post_id'          => $post_id,
                    'post_type'        => $post_type,
                    'post_title'       => $post_title,
                    'post_status'      => $post_status,
                    'post_name'        => $post_name,
                    'post_date'        => $post_date,
                    'post_modified'    => $post_modified,
                    'has_import_hash'  => $has_import_hash,
                    'content_hash'     => $content_hash,
                ];
            }

            $manifest = [
                'schema'       => self::SCHEMA_VERSION,
                'generated_at' => gmdate('Y-m-d H:i:s'),
                'site'         => [
                    'home_url'   => home_url(),
                    'site_url'   => site_url(),
                    'wp_version' => get_bloginfo('version'),
                    'dbvc_version' => defined('DBVC_PLUGIN_VERSION') ? DBVC_PLUGIN_VERSION : '',
                ],
                'totals'       => [
                    'files'             => count($items),
                    'missing_import_hash' => $missing_hashes,
                ],
                'items'        => $items,
            ];

            $manifest['checksum'] = hash('sha256', wp_json_encode($manifest));

            $manifest_path = trailingslashit($backup_path) . self::MANIFEST_FILENAME;
            file_put_contents(
                $manifest_path,
                wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }

        /**
         * Retrieve the manifest for a specific backup folder.
         *
         * @param string $backup_path Absolute path to the backup folder.
         * @return array|null
         */
        public static function read_manifest($backup_path)
        {
            $manifest_path = trailingslashit($backup_path) . self::MANIFEST_FILENAME;
            if (! file_exists($manifest_path)) {
                return null;
            }

            $contents = file_get_contents($manifest_path);
            if ($contents === false) {
                return null;
            }

            $decoded = json_decode($contents, true);
            if (! is_array($decoded)) {
                return null;
            }

            return $decoded;
        }

        /**
         * Scan all backup folders and return summary data.
         *
         * @return array[]
         */
        public static function list_backups()
        {
            $base = self::get_base_path();
            if (! is_dir($base)) {
                return [];
            }

            $folders = glob($base . '/*', GLOB_ONLYDIR);
            if (empty($folders)) {
                return [];
            }

            $locks = self::get_locked();

            $backups = [];
            foreach ($folders as $folder) {
                $name      = basename($folder);
                $manifest  = self::read_manifest($folder);
                $is_locked = in_array($name, $locks, true);
                $size      = self::get_directory_size($folder);

                $backups[] = [
                    'name'     => $name,
                    'path'     => $folder,
                    'locked'   => $is_locked,
                    'size'     => $size,
                    'manifest' => $manifest,
                ];
            }

            usort($backups, static function ($a, $b) {
                return strcmp($b['name'], $a['name']);
            });

            return $backups;
        }

        /**
         * Simple directory size helper.
         *
         * @param string $dir
         * @return int
         */
        public static function get_directory_size($dir)
        {
            $size = 0;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
            return $size;
        }

        /**
         * Return array of locked backup folder names.
         *
         * @return array
         */
        public static function get_locked()
        {
            $locked = get_option(self::OPTION_LOCKED, []);
            if (! is_array($locked)) {
                $locked = [];
            }
            return array_values(array_unique(array_map('sanitize_text_field', $locked)));
        }

        /**
         * Persist locked backups.
         *
         * @param array $locks
         * @return void
         */
        public static function set_locked(array $locks)
        {
            $locks = array_values(array_unique(array_map('sanitize_text_field', $locks)));
            update_option(self::OPTION_LOCKED, $locks);
        }

        /**
         * Toggle lock status for a backup folder.
         *
         * @param string $folder Backup folder name.
         * @param bool   $locked Whether to lock.
         * @return void
         */
        public static function set_lock($folder, $locked)
        {
            $folder = sanitize_text_field($folder);
            $locks  = self::get_locked();

            if ($locked) {
                if (! in_array($folder, $locks, true)) {
                    $locks[] = $folder;
                }
            } else {
                $locks = array_values(array_diff($locks, [$folder]));
            }

            self::set_locked($locks);
        }

        /**
         * Determine if a folder is locked.
         *
         * @param string $folder
         * @return bool
         */
        public static function is_locked($folder)
        {
            return in_array(sanitize_text_field($folder), self::get_locked(), true);
        }

        /**
         * Delete a backup folder if it is not locked.
         *
         * @param string $folder Folder name.
         * @return bool|WP_Error
         */
        public static function delete_backup($folder)
        {
            $folder = sanitize_text_field($folder);
            if (self::is_locked($folder)) {
                return new WP_Error('dbvc_backup_locked', __('Cannot delete a locked backup.', 'dbvc'));
            }

            $path = trailingslashit(self::get_base_path()) . $folder;
            if (! is_dir($path)) {
                return new WP_Error('dbvc_backup_missing', __('Backup folder not found.', 'dbvc'));
            }

            self::delete_recursive($path);
            return true;
        }

        /**
         * Recursively delete a directory.
         *
         * @param string $dir
         * @return void
         */
        private static function delete_recursive($dir)
        {
            $iterator = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
            foreach (new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir($dir);
        }

        /**
         * Stream a ZIP download of a backup folder.
         *
         * @param string $folder
         * @return void|WP_Error
         */
        public static function download_backup($folder)
        {
            if (! class_exists('ZipArchive')) {
                return new WP_Error('dbvc_zip_missing', __('ZipArchive is required to download backups.', 'dbvc'));
            }

            $folder = sanitize_text_field($folder);
            $path   = trailingslashit(self::get_base_path()) . $folder;

            if (! is_dir($path)) {
                return new WP_Error('dbvc_backup_missing', __('Backup folder not found.', 'dbvc'));
            }

            $tmp = wp_tempnam();
            $zip = new ZipArchive();

            if (true !== $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
                return new WP_Error('dbvc_zip_create_failed', __('Unable to create temporary ZIP file.', 'dbvc'));
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                $relative = ltrim(str_replace($path, '', $file->getPathname()), '/');
                $zip->addFile($file->getPathname(), $relative);
            }
            $zip->close();

            $filename = sprintf('dbvc-backup-%s.zip', $folder);

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
            @unlink($tmp);
            exit;
        }

        /**
         * Admin-post handler for downloading a backup archive.
         *
         * @return void
         */
        public static function handle_download_request()
        {
            if (! current_user_can('manage_options')) {
                wp_die(esc_html__('Permission denied.', 'dbvc'));
            }

            $folder = isset($_GET['backup']) ? sanitize_text_field(wp_unslash($_GET['backup'])) : '';
            $nonce  = isset($_GET['_wpnonce']) ? wp_unslash($_GET['_wpnonce']) : '';

            if (! $folder || ! wp_verify_nonce($nonce, 'dbvc_download_backup_' . $folder)) {
                wp_die(esc_html__('Nonce check failed.', 'dbvc'));
            }

            $result = self::download_backup($folder);
            if (is_wp_error($result)) {
                wp_die(esc_html($result->get_error_message()));
            }
            exit;
        }
    }
}
