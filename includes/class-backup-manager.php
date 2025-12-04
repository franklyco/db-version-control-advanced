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
        const SCHEMA_VERSION    = 3;
        private static $attachment_cache = [];

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
        $media_index    = [];
        $source_lookup  = [];
        $backup_name    = basename($backup_path);

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($backup_path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'json') {
                    continue;
                }

                if (basename($file->getFilename()) === self::MANIFEST_FILENAME) {
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
                $media_refs      = [
                    'meta'    => [],
                    'content' => [],
                ];

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

                    $media_refs = self::collect_post_media_refs($decoded, $post_id, $media_index, $source_lookup);
                } elseif (basename($relative) === 'options.json') {
                    $item_type = 'options';
                } elseif (basename($relative) === 'menus.json') {
                    $item_type = 'menus';
                }

                $entity_uid = class_exists('DBVC_Sync_Posts') ? DBVC_Sync_Posts::ensure_post_uid($post_id) : '';

                $items[] = [
                    'path'             => $relative,
                    'hash'             => hash('sha256', $raw),
                    'size'             => $file->getSize(),
                    'item_type'        => $item_type,
                    'post_id'          => $post_id,
                    'vf_object_uid'    => $entity_uid,
                    'post_type'        => $post_type,
                    'post_title'       => $post_title,
                    'post_status'      => $post_status,
                    'post_name'        => $post_name,
                    'post_date'        => $post_date,
                    'post_modified'    => $post_modified,
                    'has_import_hash'  => $has_import_hash,
                    'content_hash'     => $content_hash,
                    'media_refs'       => $media_refs,
                ];
            }

            $media_bundle_meta = null;
            if (
                class_exists('DBVC_Media_Sync')
                && DBVC_Media_Sync::is_bundle_enabled()
                && ! empty($media_index)
            ) {
                try {
                    $bundle_stats = \Dbvc\Media\BundleManager::build_bundle($backup_name, array_values($media_index));
                    \Dbvc\Media\BundleManager::mirror_to_backup($backup_name, $backup_path);
                    $media_bundle_meta = \Dbvc\Media\BundleManager::build_manifest_metadata($backup_name, $bundle_stats);
                } catch (\Throwable $bundle_exception) {
                    if (class_exists('DBVC_Sync_Logger')) {
                        DBVC_Sync_Logger::log('Media bundle generation failed', [
                            'backup' => $backup_name,
                            'error'  => $bundle_exception->getMessage(),
                        ]);
                    }
                }
            }

            $manifest = [
                'schema'       => self::SCHEMA_VERSION,
                'generated_at' => gmdate('Y-m-d H:i:s'),
                'backup_name'  => $backup_name,
                'status'       => 'draft',
                'site'         => [
                    'home_url'   => home_url(),
                    'site_url'   => site_url(),
                    'wp_version' => get_bloginfo('version'),
                    'dbvc_version' => defined('DBVC_PLUGIN_VERSION') ? DBVC_PLUGIN_VERSION : '',
                ],
                'totals'       => [
                    'files'             => count($items),
                    'missing_import_hash' => $missing_hashes,
                    'media_items'       => count($media_index),
                ],
                'bundle'      => [
                    'media_enabled'  => class_exists('DBVC_Media_Sync') ? DBVC_Media_Sync::is_bundle_enabled() : false,
                    'transport_mode' => class_exists('DBVC_Media_Sync') ? DBVC_Media_Sync::get_transport_mode() : 'auto',
                ],
                'items'        => $items,
                'media_index'  => array_values($media_index),
                'media_bundle' => $media_bundle_meta,
            ];

            $resolver_snapshot = self::export_resolver_decisions($backup_name);
            if (! empty($resolver_snapshot['proposal']) || ! empty($resolver_snapshot['global'])) {
                $manifest['resolver_decisions'] = $resolver_snapshot;
            }

            $manifest['checksum'] = hash('sha256', wp_json_encode($manifest));

            $manifest_path = trailingslashit($backup_path) . self::MANIFEST_FILENAME;
            file_put_contents(
                $manifest_path,
                wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }

        private static function export_resolver_decisions($backup_name)
        {
            $store    = get_option('dbvc_resolver_decisions', []);
            $proposal = isset($store[$backup_name]) && is_array($store[$backup_name]) ? $store[$backup_name] : [];
            $global   = isset($store['__global']) && is_array($store['__global']) ? $store['__global'] : [];

            return [
                'proposal' => $proposal,
                'global'   => $global,
            ];
        }

        /**
         * Copy referenced media files into the sync bundle directory.
         *
         * @param array $media_index
         * @return void
         */
        /**
         * Gather media references from the post JSON payload and enrich the shared media index.
         *
         * @param array $decoded
         * @param int   $post_id
         * @param array $media_index
         * @param array $source_lookup
         * @return array
         */
        private static function collect_post_media_refs(array $decoded, $post_id, array &$media_index, array &$source_lookup)
        {
            $refs = [
                'meta'    => [],
                'content' => [],
            ];

            // Meta-based attachment references.
            if (! empty($decoded['meta']) && is_array($decoded['meta'])) {
                foreach ($decoded['meta'] as $meta_key => $values) {
                    if (! is_array($values)) {
                        continue;
                    }
                    foreach ($values as $index => $value) {
                        $matches = self::detect_attachment_ids($value);
                        if (empty($matches)) {
                            continue;
                        }
                        foreach ($matches as $match) {
                            $attachment_id = $match['id'];
                            $path          = $match['path'];

                            if (! isset($media_index[$attachment_id])) {
                                $entry = self::build_media_index_entry($attachment_id);
                                if (! $entry) {
                                    continue;
                                }
                                $media_index[$attachment_id] = $entry;
                                if (! empty($entry['source_url'])) {
                                    $source_lookup[$entry['source_url']] = $attachment_id;
                                }
                            }

                            $refs['meta'][] = [
                                'original_id' => $attachment_id,
                                'meta_key'    => sanitize_key($meta_key),
                                'value_index' => $index,
                                'path'        => $path,
                            ];
                        }
                    }
                }
            }

            // Content URLs (remote assets).
            if (! empty($decoded['post_content']) && is_string($decoded['post_content'])) {
                if (preg_match_all('#https?://[^\s"\']+#', $decoded['post_content'], $matches)) {
                    $urls = array_unique($matches[0]);
                    foreach ($urls as $url_raw) {
                        $url = esc_url_raw($url_raw);
                        if (! $url) {
                            continue;
                        }
                        $original_id = null;
                        if (isset($source_lookup[$url])) {
                            $original_id = $source_lookup[$url];
                        }

                        $refs['content'][] = [
                            'original_url' => $url,
                            'original_id'  => $original_id,
                        ];
                    }
                }
            }

            return $refs;
        }

        /**
         * Detect attachment IDs inside an arbitrary value.
         *
         * @param mixed $value
         * @param array $path
         * @return array
         */
        private static function detect_attachment_ids($value, array $path = [])
        {
            $matches = [];

            if (is_array($value)) {
                if (isset($value['ID']) && self::is_attachment_id($value['ID'])) {
                    $matches[] = [
                        'id'   => (int) $value['ID'],
                        'path' => array_merge($path, ['ID']),
                    ];
                }
                foreach ($value as $key => $child) {
                    $child_path = array_merge($path, [$key]);
                    $matches    = array_merge($matches, self::detect_attachment_ids($child, $child_path));
                }
                return $matches;
            }

            if (is_object($value)) {
                $vars = get_object_vars($value);
                return self::detect_attachment_ids($vars, $path);
            }

            if (self::is_attachment_id($value)) {
                $matches[] = [
                    'id'   => (int) $value,
                    'path' => $path,
                ];
            }

            return $matches;
        }

        /**
         * Determine whether a value points to an attachment ID.
         *
         * @param mixed $value
         * @return bool
         */
        private static function is_attachment_id($value)
        {
            if (is_int($value) && $value > 0) {
                $attachment = self::get_attachment($value);
                return ($attachment !== null);
            }

            if (is_string($value) && ctype_digit($value)) {
                $attachment = self::get_attachment((int) $value);
                return ($attachment !== null);
            }

            return false;
        }

        /**
         * Fetch attachment post with basic caching.
         *
         * @param int $attachment_id
         * @return WP_Post|null
         */
        private static function get_attachment($attachment_id)
        {
            if (isset(self::$attachment_cache[$attachment_id])) {
                return self::$attachment_cache[$attachment_id];
            }

            $post = get_post($attachment_id);
            if ($post && $post->post_type === 'attachment') {
                self::$attachment_cache[$attachment_id] = $post;
                return $post;
            }

            self::$attachment_cache[$attachment_id] = null;
            return null;
        }

        /**
         * Build a manifest entry for a single attachment.
         *
         * @param int $attachment_id
         * @return array|null
         */
        private static function build_media_index_entry($attachment_id)
        {
            $attachment = self::get_attachment($attachment_id);
            if (! $attachment) {
                return null;
            }

            $asset_uid  = get_post_meta($attachment_id, 'vf_asset_uid', true);
            $file_hash  = get_post_meta($attachment_id, 'vf_file_hash', true);
            $need_stamp = false;
            $file_path     = get_attached_file($attachment_id);
            $hash_raw      = '';
            $filesize      = 0;
            $dimensions    = ['width' => null, 'height' => null];
            $relative_path = '';

            $source_url    = wp_get_attachment_url($attachment_id);

            if ($file_path && file_exists($file_path)) {
                $hash_raw      = hash_file('sha256', $file_path);
                $filesize      = filesize($file_path);
                if (class_exists('DBVC_Media_Sync')) {
                    $relative_path = DBVC_Media_Sync::get_relative_bundle_path($attachment_id, $file_path);
                }

                $image_meta = wp_get_attachment_metadata($attachment_id);
                if (is_array($image_meta)) {
                    if (! empty($image_meta['width'])) {
                        $dimensions['width'] = (int) $image_meta['width'];
                    }
                    if (! empty($image_meta['height'])) {
                        $dimensions['height'] = (int) $image_meta['height'];
                    }
                }
            }

            if (! $asset_uid) {
                $asset_uid = wp_generate_uuid4();
                $need_stamp = true;
            }

            if (! $file_hash && $hash_raw) {
                $file_hash  = $hash_raw;
                $need_stamp = true;
            }

            if ($need_stamp) {
                self::ensure_attachment_identity($attachment_id, $asset_uid, $file_hash);
            }

            $entry = [
                'original_id'   => $attachment_id,
                'asset_uid'     => $asset_uid,
                'source_url'    => $source_url ?: '',
                'mime_type'     => $attachment->post_mime_type ?: '',
                'title'         => get_the_title($attachment_id),
                'filename'      => $source_url ? basename(parse_url($source_url, PHP_URL_PATH)) : '',
                'relative_path' => $relative_path,
                'bundle_path'   => $relative_path,
                'file_hash'     => class_exists('DBVC_Media_Sync')
                    ? DBVC_Media_Sync::format_hash($file_hash ?: $hash_raw)
                    : ($file_hash ?: $hash_raw),
                'hash'          => class_exists('DBVC_Media_Sync')
                    ? DBVC_Media_Sync::format_hash($hash_raw)
                    : $hash_raw,
                'filesize'      => $filesize,
                'file_size'     => $filesize, // Legacy key retained for backward compatibility.
                'dimensions'    => array_filter($dimensions, static function ($value) {
                    return $value !== null;
                }),
            ];

            if (class_exists('DBVC_Database')) {
                DBVC_Database::upsert_media([
                    'attachment_id' => $attachment_id,
                    'original_id'   => $attachment_id,
                    'source_url'    => $entry['source_url'],
                    'relative_path' => $relative_path ?: null,
                    'file_hash'     => $hash_raw ?: null,
                    'file_size'     => $filesize ?: null,
                    'mime_type'     => $entry['mime_type'],
                ]);
            }

            return $entry;
        }

        /**
         * Ensure attachment identity metadata is persisted.
         *
         * @param int         $attachment_id
         * @param string|null $asset_uid
         * @param string|null $file_hash
         * @return void
         */
        private static function ensure_attachment_identity($attachment_id, $asset_uid = null, $file_hash = null)
        {
            if ($asset_uid) {
                update_post_meta($attachment_id, 'vf_asset_uid', $asset_uid);
            }

            if ($file_hash) {
                update_post_meta($attachment_id, 'vf_file_hash', $file_hash);
            }
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
