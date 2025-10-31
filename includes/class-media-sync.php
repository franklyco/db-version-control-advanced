<?php

/**
 * Media synchronization helpers for DBVC.
 *
 * Handles downloading mirrored assets, storing attachment ID mappings, and
 * applying updates to posts/meta after restores.
 *
 * @package   DB Version Control
 */

if (! defined('WPINC')) {
    die;
}

if (! class_exists('DBVC_Media_Sync')) {
    class DBVC_Media_Sync
    {
        const OPTION_ENABLED         = 'dbvc_media_retrieve_enabled';
        const OPTION_PRESERVE_NAMES  = 'dbvc_media_preserve_filenames';
        const OPTION_PREVIEW_ENABLED = 'dbvc_media_preview_enabled';
        const OPTION_ALLOW_EXTERNAL  = 'dbvc_media_allow_external';
        const MAP_OPTION             = 'dbvc_media_map_temp';
        const URL_OPTION             = 'dbvc_media_url_map_temp';

        private static $map_loaded   = false;
        private static $map          = [];
        private static $url_map      = [];
        private static $stats        = [
            'downloaded'     => 0,
            'reused'         => 0,
            'updated_posts'  => 0,
            'meta_updates'   => 0,
            'content_updates'=> 0,
            'errors'         => 0,
            'blocked'        => 0,
        ];

        private static function reset_stats()
        {
            self::$stats = [
                'downloaded'      => 0,
                'reused'          => 0,
                'updated_posts'   => 0,
                'meta_updates'    => 0,
                'content_updates' => 0,
                'errors'          => 0,
                'blocked'         => 0,
            ];
        }

        /**
         * Determine if media retrieval is enabled.
         *
         * @return bool
         */
        public static function is_enabled()
        {
            return get_option(self::OPTION_ENABLED, '0') === '1';
        }

        /**
         * Whether to preserve original filenames during sideload.
         *
         * @return bool
         */
        public static function should_preserve_names()
        {
            return get_option(self::OPTION_PRESERVE_NAMES, '1') === '1';
        }

        /**
         * Whether media preview UI is enabled.
         *
         * @return bool
         */
        public static function is_preview_enabled()
        {
            return get_option(self::OPTION_PREVIEW_ENABLED, '0') === '1';
        }

        /**
         * Whether external hosts are allowed for media downloads.
         *
         * @return bool
         */
        public static function allow_external_sources()
        {
            return get_option(self::OPTION_ALLOW_EXTERNAL, '0') === '1';
        }

        /**
         * Orchestrate media synchronization from a manifest snapshot.
         *
         * @param array $manifest
         * @return array Statistics
         */
        public static function sync_manifest_media(array $manifest)
        {
            self::ensure_state_loaded();
            self::reset_stats();

            self::prime_existing_mappings($manifest);
            $candidates = self::collect_candidates($manifest);
            $queue      = $candidates['queue'];
            self::$stats['blocked'] = count($candidates['blocked']);

            if (! empty($queue)) {
                self::process_queue($queue);
            }

            self::apply_post_mappings($manifest);
            self::persist_state();

            return self::$stats;
        }

        /**
         * Load mapping/cache state from options (and legacy file if present).
         *
         * @return void
         */
        private static function ensure_state_loaded()
        {
            if (self::$map_loaded) {
                return;
            }

            $option = get_option(self::MAP_OPTION, []);
            if (is_array($option) && ! empty($option['map']) && is_array($option['map'])) {
                self::$map = array_map('absint', $option['map']);
            }

            $url_option = get_option(self::URL_OPTION, []);
            if (is_array($url_option) && ! empty($url_option['map']) && is_array($url_option['map'])) {
                self::$url_map = array_map('absint', $url_option['map']);
            }

            self::$map_loaded = true;
        }

        /**
         * Persist temporary mapping state.
         *
         * @return void
         */
        private static function persist_state()
        {
            update_option(self::MAP_OPTION, [
                'map'        => self::$map,
                'updated_at' => time(),
            ], false);

            update_option(self::URL_OPTION, [
                'map'        => self::$url_map,
                'updated_at' => time(),
            ], false);
        }

        /**
         * Build preview data for media retrieval without downloading files.
         *
         * @param array   $manifest
         * @param int|nil $limit
         * @return array
         */
        public static function preview_manifest_media(array $manifest, $limit = 20)
        {
            self::ensure_state_loaded();
            self::prime_existing_mappings($manifest, false);

            $candidates = self::collect_candidates($manifest);
            $items      = $candidates['queue'];

            if ($limit !== null && $limit > 0) {
                $items = array_slice($items, 0, (int) $limit);
            }

            return [
                'total_candidates' => count($candidates['queue']),
                'preview_items'    => $items,
                'blocked'          => $candidates['blocked'],
                'skipped_existing' => $candidates['skipped_existing'],
                'total_detected'   => $candidates['total_detected'],
            ];
        }

        /**
         * Flush temporary mapping caches.
         *
         * @return void
         */
        public static function cleanup()
        {
            delete_option(self::MAP_OPTION);
            delete_option(self::URL_OPTION);
            self::$map       = [];
            self::$url_map   = [];
            self::$map_loaded = false;
        }

        /**
         * Record existing attachment mappings before downloading new files.
         *
         * @param array $manifest
         * @return void
         */
        private static function prime_existing_mappings(array $manifest, $track_stats = true)
        {
            if (empty($manifest['media_index']) || ! is_array($manifest['media_index'])) {
                return;
            }

            foreach ($manifest['media_index'] as $entry) {
                $original_id = isset($entry['original_id']) ? (int) $entry['original_id'] : 0;
                if (! $original_id || self::get_local_id($original_id)) {
                    continue;
                }

                $existing_id = self::find_existing_attachment($original_id);
                if ($existing_id) {
                    self::set_mapping($original_id, $existing_id);
                    if (! empty($entry['source_url'])) {
                        self::register_url_mapping($entry['source_url'], $existing_id);
                    }
                    if ($track_stats) {
                        self::$stats['reused']++;
                    }
                }
            }
        }

        /**
         * Process download queue.
         *
         * @param array $queue
         * @return void
         */
        private static function process_queue(array $queue)
        {
            foreach ($queue as $item) {
                $result = self::download_item($item);
                if (is_wp_error($result)) {
                    self::$stats['errors']++;
                    DBVC_Sync_Logger::log('Media download failed', [
                        'error'        => $result->get_error_message(),
                        'original_id'  => $item['original_id'],
                        'source_url'   => $item['source_url'],
                    ]);
                }
            }
        }

        /**
         * Download a single remote asset and create a local attachment.
         *
         * @param array $item
         * @return int|\WP_Error
         */
        private static function download_item(array $item)
        {
            $tmp = download_url($item['source_url']);
            if (is_wp_error($tmp)) {
                return $tmp;
            }

            $filename = $item['filename'] ?: basename(parse_url($item['source_url'], PHP_URL_PATH));
            if (! $filename) {
                $filename = 'dbvc-media-' . wp_generate_uuid4();
            }

            $file_array = [
                'name'     => $filename,
                'tmp_name' => $tmp,
            ];

            $preserve = self::should_preserve_names();
            if ($preserve) {
                add_filter('sanitize_file_name', [__CLASS__, 'preserve_original_filename'], 10, 2);
            }

            $attachment_id = media_handle_sideload(
                $file_array,
                0,
                $item['title'],
                [
                    'post_mime_type' => $item['mime_type'] ?: null,
                ]
            );

            if ($preserve) {
                remove_filter('sanitize_file_name', [__CLASS__, 'preserve_original_filename'], 10);
            }

            if (is_wp_error($attachment_id)) {
                @unlink($tmp);
                return $attachment_id;
            }

            update_post_meta($attachment_id, '_dbvc_original_attachment_id', (int) $item['original_id']);
            update_post_meta($attachment_id, '_dbvc_original_source_url', esc_url_raw($item['source_url']));

            self::set_mapping($item['original_id'], $attachment_id);
            self::register_url_mapping($item['source_url'], $attachment_id);
            self::$stats['downloaded']++;

            DBVC_Sync_Logger::log('Downloaded media asset', [
                'original_id' => $item['original_id'],
                'attachment_id' => $attachment_id,
                'source_url'  => $item['source_url'],
            ]);

            return $attachment_id;
        }

        /**
         * Filter callback to preserve original filenames.
         *
         * @param string $filename
         * @return string
         */
        public static function preserve_original_filename($filename, $raw_filename)
        {
            return $raw_filename ?: $filename;
        }

        /**
         * Apply attachment mappings to post meta/content.
         *
         * @param array $manifest
         * @return void
         */
        private static function apply_post_mappings(array $manifest)
        {
            if (empty($manifest['items']) || ! is_array($manifest['items'])) {
                return;
            }

            foreach ($manifest['items'] as $item) {
                if (($item['item_type'] ?? '') !== 'post') {
                    continue;
                }

                $original_post_id = isset($item['post_id']) ? (int) $item['post_id'] : 0;
                if (! $original_post_id) {
                    continue;
                }

                $local_post_id = DBVC_Sync_Posts::resolve_local_post_id($original_post_id);
                if (! $local_post_id || ! get_post($local_post_id)) {
                    continue;
                }

                $updated_meta    = self::apply_meta_mappings($local_post_id, $item['media_refs']['meta'] ?? []);
                $updated_content = self::apply_content_mappings($local_post_id, $item['media_refs']['content'] ?? []);

                if ($updated_meta || $updated_content) {
                    self::$stats['updated_posts']++;
                }
            }
        }

        /**
         * Replace attachment IDs inside post meta.
         *
         * @param int   $post_id
         * @param array $meta_refs
         * @return bool
         */
        private static function apply_meta_mappings($post_id, array $meta_refs)
        {
            $meta_changed = false;

            foreach ($meta_refs as $ref) {
                $original_id = isset($ref['original_id']) ? (int) $ref['original_id'] : 0;
                $meta_key    = isset($ref['meta_key']) ? $ref['meta_key'] : '';

                if (! $original_id || ! $meta_key) {
                    continue;
                }

                $new_id = self::get_local_id($original_id);
                if (! $new_id) {
                    continue;
                }

                if ($meta_key === '_thumbnail_id') {
                    set_post_thumbnail($post_id, $new_id);
                    $meta_changed = true;
                    self::$stats['meta_updates']++;
                    continue;
                }

                $values = get_post_meta($post_id, $meta_key, false);
                $index  = isset($ref['value_index']) ? (int) $ref['value_index'] : 0;
                if (! isset($values[$index])) {
                    continue;
                }

                $prev_value = $values[$index];
                $modified   = self::set_nested_meta_value($prev_value, $ref['path'] ?? [], $new_id);

                if ($modified === null || $modified === $prev_value) {
                    continue;
                }

                $updated = update_metadata('post', $post_id, $meta_key, $modified, $prev_value);
                if ($updated) {
                    $meta_changed = true;
                    self::$stats['meta_updates']++;
                }
            }

            return $meta_changed;
        }

        /**
         * Replace mirrored URLs in post content.
         *
         * @param int   $post_id
         * @param array $content_refs
         * @return bool
         */
        private static function apply_content_mappings($post_id, array $content_refs)
        {
            if (empty($content_refs)) {
                return false;
            }

            $content      = get_post_field('post_content', $post_id);
            $updated      = $content;
            $replacements = 0;

            foreach ($content_refs as $ref) {
                $original_url = isset($ref['original_url']) ? esc_url_raw($ref['original_url']) : '';
                if (! $original_url) {
                    continue;
                }

                $new_id  = null;
                $orig_id = isset($ref['original_id']) ? (int) $ref['original_id'] : 0;
                if ($orig_id) {
                    $new_id = self::get_local_id($orig_id);
                }
                if (! $new_id && isset(self::$url_map[$original_url])) {
                    $new_id = (int) self::$url_map[$original_url];
                }
                if (! $new_id) {
                    continue;
                }

                $new_url = wp_get_attachment_url($new_id);
                if (! $new_url) {
                    continue;
                }

                if (strpos($updated, $original_url) === false) {
                    continue;
                }

                $updated = str_replace($original_url, $new_url, $updated, $count);
                if ($count > 0) {
                    $replacements += $count;
                }
            }

            if ($replacements > 0 && $updated !== $content) {
                wp_update_post([
                    'ID'           => $post_id,
                    'post_content' => $updated,
                ]);
                self::$stats['content_updates'] += $replacements;
                return true;
            }

            return false;
        }

        /**
         * Replace nested meta value by path.
         *
         * @param mixed $value
         * @param array $path
         * @param int   $new_id
         * @return mixed|null
         */
        private static function set_nested_meta_value($value, array $path, $new_id)
        {
            if (empty($path)) {
                if (is_string($value)) {
                    return (string) $new_id;
                }
                if (is_int($value)) {
                    return (int) $new_id;
                }
                return $new_id;
            }

            if (is_object($value)) {
                $value = json_decode(wp_json_encode($value), true);
            }

            if (! is_array($value)) {
                return null;
            }

            $ref =& $value;
            foreach ($path as $segment) {
                if (is_object($ref)) {
                    $ref = json_decode(wp_json_encode($ref), true);
                }
                if (! is_array($ref)) {
                    return null;
                }
                if (is_numeric($segment)) {
                    $segment = (int) $segment;
                }
                if (! array_key_exists($segment, $ref)) {
                    return null;
                }
                $ref =& $ref[$segment];
            }

            if (is_string($ref)) {
                $ref = (string) $new_id;
            } elseif (is_int($ref)) {
                $ref = (int) $new_id;
            } else {
                $ref = $new_id;
            }

            return $value;
        }

        /**
         * Get existing local attachment mapped to the original ID.
         *
         * @param int $original_id
         * @return int|null
         */
        private static function find_existing_attachment($original_id)
        {
            $existing = self::get_local_id($original_id);
            if ($existing) {
                return $existing;
            }

            $found = get_posts([
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => '_dbvc_original_attachment_id',
                'meta_value'     => $original_id,
            ]);

            if (! empty($found)) {
                return (int) $found[0];
            }

            return null;
        }

        /**
         * Register the mapping between original and local IDs.
         *
         * @param int $original_id
         * @param int $local_id
         * @return void
         */
        private static function set_mapping($original_id, $local_id)
        {
            $original_id = (int) $original_id;
            $local_id    = (int) $local_id;
            if (! $original_id || ! $local_id) {
                return;
            }

            self::$map[$original_id] = $local_id;
        }

        /**
         * Map a source URL to a local attachment ID.
         *
         * @param string $url
         * @param int    $attachment_id
         * @return void
         */
        private static function register_url_mapping($url, $attachment_id)
        {
            $url = esc_url_raw($url);
            if (! $url) {
                return;
            }
            self::$url_map[$url] = (int) $attachment_id;
        }

        /**
         * Get the local attachment ID for an original ID.
         *
         * @param int $original_id
         * @return int|null
         */
        public static function get_local_id($original_id)
        {
            $original_id = (int) $original_id;
            if (! $original_id) {
                return null;
            }
            return isset(self::$map[$original_id]) ? (int) self::$map[$original_id] : null;
        }

        /**
         * Collect media download candidates from manifest data.
         *
         * @param array $manifest
         * @return array
         */
        private static function collect_candidates(array $manifest)
        {
            $queue            = [];
            $blocked          = [];
            $skipped_existing = 0;
            $total_detected   = 0;

            if (empty($manifest['media_index']) || ! is_array($manifest['media_index'])) {
                return [
                    'queue'            => [],
                    'blocked'          => [],
                    'skipped_existing' => 0,
                    'total_detected'   => 0,
                ];
            }

            foreach ($manifest['media_index'] as $entry) {
                $original_id = isset($entry['original_id']) ? (int) $entry['original_id'] : 0;
                $source_url  = isset($entry['source_url']) ? esc_url_raw($entry['source_url']) : '';
                $total_detected++;

                if (! $original_id || empty($source_url)) {
                    continue;
                }

                if (self::get_local_id($original_id)) {
                    $skipped_existing++;
                    continue;
                }

                if (! self::is_source_allowed($source_url)) {
                    $blocked[] = [
                        'original_id' => $original_id,
                        'source_url'  => $source_url,
                        'reason'      => 'host_not_allowed',
                    ];
                    continue;
                }

                $source_host = parse_url($source_url, PHP_URL_HOST);
                $queue[] = [
                    'original_id' => $original_id,
                    'source_url'  => $source_url,
                    'source_host' => $source_host ? (string) $source_host : '',
                    'mime_type'   => isset($entry['mime_type']) ? sanitize_text_field($entry['mime_type']) : '',
                    'title'       => isset($entry['title']) ? sanitize_text_field($entry['title']) : '',
                    'filename'    => isset($entry['filename']) ? sanitize_file_name($entry['filename']) : '',
                    'hash'        => isset($entry['hash']) ? sanitize_text_field($entry['hash']) : '',
                ];
            }

            return [
                'queue'            => $queue,
                'blocked'          => $blocked,
                'skipped_existing' => $skipped_existing,
                'total_detected'   => $total_detected,
            ];
        }

        /**
         * Determine if a remote URL is allowed for download.
         *
         * @param string $url
         * @return bool
         */
        private static function is_source_allowed($url)
        {
            $url = esc_url_raw($url);
            if (! $url) {
                return false;
            }

            $host = parse_url($url, PHP_URL_HOST);
            if (! $host) {
                return true;
            }

            if (self::allow_external_sources()) {
                return true;
            }

            $allowed_hosts = [];
            $site_host     = parse_url(home_url(), PHP_URL_HOST);
            if ($site_host) {
                $allowed_hosts[] = $site_host;
            }

            $mirror = get_option('dbvc_mirror_domain', '');
            if ($mirror) {
                $mirror_host = parse_url($mirror, PHP_URL_HOST);
                if ($mirror_host) {
                    $allowed_hosts[] = $mirror_host;
                }
            }

            return in_array($host, $allowed_hosts, true);
        }

        /**
         * Handle the admin-post request to clear cached media mappings.
         *
         * @return void
         */
        public static function handle_clear_cache_request()
        {
            if (! current_user_can('manage_options')) {
                wp_die(esc_html__('Permission denied.', 'dbvc'));
            }

            $nonce = isset($_GET['_wpnonce']) ? wp_unslash($_GET['_wpnonce']) : '';
            if (! wp_verify_nonce($nonce, 'dbvc_clear_media_cache')) {
                wp_die(esc_html__('Nonce check failed.', 'dbvc'));
            }

            self::cleanup();

            $redirect = wp_get_referer();
            if (! $redirect) {
                $redirect = admin_url('admin.php?page=dbvc-export');
            }
            $redirect = add_query_arg([
                'dbvc_tab'    => 'tab-config',
                'dbvc_notice' => 'media_cache_cleared',
            ], $redirect);

            wp_safe_redirect($redirect);
            exit;
        }
    }
}
