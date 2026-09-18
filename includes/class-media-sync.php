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
        const OPTION_TRANSPORT_MODE  = 'dbvc_media_transport_mode';
        const OPTION_BUNDLE_ENABLED  = 'dbvc_media_bundle_enabled';
        const OPTION_BUNDLE_CHUNK    = 'dbvc_media_bundle_chunk';
        const BUNDLE_DIR             = 'media';
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
         * Retrieve the configured transport mode.
         *
         * @return string auto|bundled|remote
         */
        public static function get_transport_mode()
        {
            $mode     = get_option(self::OPTION_TRANSPORT_MODE, 'auto');
            $allowed  = ['auto', 'bundled', 'remote'];

            if (! in_array($mode, $allowed, true)) {
                $mode = 'auto';
            }

            return $mode;
        }

        /**
         * Determine whether bundled media should be generated.
         *
         * @return bool
         */
        public static function is_bundle_enabled()
        {
            return get_option(self::OPTION_BUNDLE_ENABLED, '0') === '1';
        }

        /**
         * Return the chunk size for bundle processing.
         *
         * @return int
         */
        public static function get_bundle_chunk_size()
        {
            $raw = (int) get_option(self::OPTION_BUNDLE_CHUNK, 250);
            if ($raw <= 0) {
                $raw = 250;
            }
            return $raw;
        }

        /**
         * Absolute path to the bundle media directory.
         *
         * @return string
         */
        public static function get_bundle_base_dir()
        {
            $base = dbvc_get_sync_path();
            return trailingslashit($base) . self::BUNDLE_DIR;
        }

        /**
         * Build the relative bundle path (inside sync directory) for an attachment.
         *
         * @param int         $attachment_id
         * @param string|null $file_path
         * @return string
         */
        public static function get_relative_bundle_path($attachment_id, $file_path = null)
        {
            if ($file_path === null) {
                $file_path = get_attached_file($attachment_id);
            }

            if (! $file_path) {
                return '';
            }

            $normalized = wp_normalize_path($file_path);
            $uploads    = wp_get_upload_dir();

            if (! empty($uploads['basedir'])) {
                $base = wp_normalize_path($uploads['basedir']);
                if (strpos($normalized, $base) === 0) {
                    $relative = ltrim(substr($normalized, strlen($base)), '/');
                } else {
                    $relative = basename($normalized);
                }
            } else {
                $relative = basename($normalized);
            }

            $relative = str_replace('\\', '/', $relative);
            $relative = ltrim($relative, '/');

            return self::BUNDLE_DIR . '/' . $relative;
        }

        /**
         * Remove a bundled media file from the sync directory.
         *
         * @param int $attachment_id
         * @return void
         */
        public static function delete_bundle_file_for_attachment($attachment_id)
        {
            $attachment_id = (int) $attachment_id;
            if (! $attachment_id) {
                return;
            }

            $base = trailingslashit(dbvc_get_sync_path());
            $candidates = [];

            $relative = self::get_relative_bundle_path($attachment_id);
            if ($relative !== '') {
                $candidates[] = $relative;
            }

            if (class_exists('DBVC_Database')) {
                $row = DBVC_Database::get_media_by_attachment_id($attachment_id);
                if ($row && ! empty($row->relative_path)) {
                    $candidates[] = (string) $row->relative_path;
                }
            }

            $candidates = array_unique(array_filter($candidates));
            foreach ($candidates as $relative_path) {
                $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
                if ($relative_path === '') {
                    continue;
                }

                $absolute = wp_normalize_path($base . $relative_path);
                if (is_file($absolute)) {
                    @unlink($absolute);
                }

                $bundle_base = wp_normalize_path(trailingslashit(self::get_bundle_base_dir()));
                $dir = dirname($absolute);
                while ($dir && strpos(wp_normalize_path($dir) . '/', $bundle_base) === 0) {
                    $items = @scandir($dir);
                    if ($items === false || count($items) > 2) {
                        break;
                    }
                    @rmdir($dir);
                    $dir = dirname($dir);
                }
            }
        }

        /**
         * Ensure hashes carry a consistent prefix.
         *
         * @param string $hash
         * @return string
         */
        public static function format_hash($hash)
        {
            $hash = (string) $hash;
            if ($hash === '') {
                return '';
            }

            if (strpos($hash, ':') === false) {
                return 'sha256:' . $hash;
            }

            return $hash;
        }

        /**
         * Centralized logging helper for media sync instrumentation.
         *
         * @param string $event
         * @param string $message
         * @param array  $context
         * @param string $level
         * @return void
         */
        private static function log_event($event, $message, array $context = [], $level = 'info')
        {
            if (
                class_exists('DBVC_Sync_Logger')
                && method_exists('DBVC_Sync_Logger', 'is_media_logging_enabled')
                && DBVC_Sync_Logger::is_media_logging_enabled()
            ) {
                DBVC_Sync_Logger::log_media($message, array_merge([
                    'event' => $event,
                    'level' => $level,
                ], $context));
            } elseif (
                defined('WP_DEBUG_LOG') && WP_DEBUG_LOG
            ) {
                error_log('[DBVC media] ' . $message . ' ' . wp_json_encode($context));
            }

            if (class_exists('DBVC_Database')) {
                DBVC_Database::log_activity($event, $level, $message, $context);
            }
        }

        /**
         * Orchestrate media synchronization from a manifest snapshot.
         *
         * @param array $manifest
         * @return array Statistics
         */
        private static $resolver_decisions = [];
        private static $resolver_global_decisions = [];
        private static $resolver_effective_decisions = [];
        private static $resolver_decision_outcomes = [];
        private static $resolver_reconcile_map = [];

        public static function sync_manifest_media(array $manifest, array $context = [])
        {
            self::ensure_state_loaded();
            self::reset_stats();
            self::load_resolver_decisions($context['proposal_id'] ?? '');
            self::$resolver_decision_outcomes = [];
            self::$resolver_reconcile_map = self::extract_reconcile_id_map($context['media_reconcile'] ?? []);
            self::clear_forced_mapping_state($manifest);

            self::prime_existing_mappings($manifest);
            $candidates = self::collect_candidates($manifest);
            $queue      = $candidates['queue'];
            self::$stats['blocked'] = count($candidates['blocked']);

            $resolver_result = null;
            if (class_exists('\Dbvc\Media\Resolver')) {
                try {
                    $resolver_result = \Dbvc\Media\Resolver::resolve_manifest($manifest, [
                        'allow_remote' => self::allow_external_sources(),
                        'dry_run'      => false,
                        'proposal_id'  => $context['proposal_id'] ?? ($manifest['backup_name'] ?? ''),
                        'bundle_meta'  => $manifest['media_bundle'] ?? [],
                        'manifest_dir' => $context['manifest_dir'] ?? null,
                    ]);
                    $resolver_result = self::apply_resolver_result($resolver_result, $queue);
                } catch (\Throwable $resolver_exception) {
                    self::log_event(
                        'media_resolver_apply_failed',
                        'Resolver preflight failed',
                        [
                            'error' => $resolver_exception->getMessage(),
                        ],
                        'error'
                    );
                }
            }

            $run_legacy = self::should_run_legacy_sync($resolver_result, $queue);

            $transport_mode = self::get_transport_mode();
            $initial_queue_count = count($queue);
            self::log_event(
                'media_sync_candidates',
                'Media sync candidates prepared',
                [
                    'transport_mode'      => $transport_mode,
                    'queue_count'         => $initial_queue_count,
                    'blocked_count'       => count($candidates['blocked']),
                    'skipped_existing'    => $candidates['skipped_existing'],
                    'total_detected'      => $candidates['total_detected'],
                    'hosts'               => self::summarize_hosts($queue),
                    'blocked_reasons'     => self::summarize_blocked($candidates['blocked']),
                    'relative_path_use'   => self::summarize_relative_paths($queue),
                    'resolver_reused'     => isset($resolver_result['metrics']['reused']) ? (int) $resolver_result['metrics']['reused'] : 0,
                    'resolver_unresolved' => isset($resolver_result['metrics']['unresolved']) ? (int) $resolver_result['metrics']['unresolved'] : 0,
                ]
            );

            if ($run_legacy && $transport_mode !== 'remote') {
                self::process_bundled_media($queue, $manifest);
            }

            $queue = array_values(array_filter($queue, static function ($item) {
                return empty($item['handled']);
            }));

            $remaining_queue_count = count($queue);

            if ($run_legacy && ! empty($queue) && $transport_mode !== 'bundled') {
                self::process_queue($queue);
            }

            $resolver_result = self::finalize_resolver_decision_outcomes($resolver_result);
            $decision_summary = self::summarize_resolver_decision_outcomes();

            self::apply_post_mappings($manifest);
            self::persist_state();

            self::log_event(
                'media_sync_completed',
                'Media sync completed',
                [
                    'queued_initial'   => $initial_queue_count,
                    'queued_remaining' => $remaining_queue_count,
                    'blocked'          => self::$stats['blocked'],
                    'downloaded'       => self::$stats['downloaded'],
                    'reused'           => self::$stats['reused'],
                    'meta_updates'     => self::$stats['meta_updates'],
                    'content_updates'  => self::$stats['content_updates'],
                    'errors'           => self::$stats['errors'],
                    'skipped_existing' => $candidates['skipped_existing'],
                    'resolver_decisions'=> $decision_summary,
                ]
            );

            $stats = self::$stats;
            $stats['resolver_decisions'] = $decision_summary;
            $stats['resolver'] = $resolver_result;

            return $stats;
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
            self::prime_existing_mappings($manifest, false, false);

            $candidates = self::collect_candidates($manifest);
            $items      = $candidates['queue'];

            if ($limit !== null && $limit > 0) {
                $items = array_slice($items, 0, (int) $limit);
            }

            $summary = [
                'total_candidates' => count($candidates['queue']),
                'preview_items'    => $items,
                'blocked'          => $candidates['blocked'],
                'skipped_existing' => $candidates['skipped_existing'],
                'total_detected'   => $candidates['total_detected'],
            ];

            self::log_event(
                'media_preview_generated',
                'Media preview generated',
                [
                    'total_detected'    => $summary['total_detected'],
                    'total_candidates' => $summary['total_candidates'],
                    'preview_count'    => count($summary['preview_items']),
                    'blocked_count'    => count($summary['blocked']),
                    'skipped_existing' => $summary['skipped_existing'],
                    'hosts'            => self::summarize_hosts($candidates['queue']),
                    'relative_path_use'=> self::summarize_relative_paths($candidates['queue']),
                ]
            );

            return $summary;
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
         * Remove cached mappings when a decision must suppress reuse.
         *
         * @param array $manifest
         * @return void
         */
        private static function clear_forced_mapping_state(array $manifest)
        {
            if (empty($manifest['media_index']) || ! is_array($manifest['media_index'])) {
                return;
            }

            foreach ($manifest['media_index'] as $entry) {
                $original_id = isset($entry['original_id']) ? absint($entry['original_id']) : 0;
                if (! $original_id) {
                    continue;
                }

                $decision = self::get_resolver_decision($original_id);
                if (! $decision || ! in_array($decision['action'], ['download', 'skip'], true)) {
                    continue;
                }

                unset(self::$map[$original_id]);

                $source_url = isset($entry['source_url']) ? esc_url_raw($entry['source_url']) : '';
                if ($source_url !== '') {
                    unset(self::$url_map[$source_url]);
                }
            }
        }

        /**
         * Record existing attachment mappings before downloading new files.
         *
         * @param array $manifest
         * @return void
         */
        private static function prime_existing_mappings(
            array $manifest,
            $track_stats = true,
            $honor_resolver_decisions = true
        )
        {
            if (empty($manifest['media_index']) || ! is_array($manifest['media_index'])) {
                return;
            }

            foreach ($manifest['media_index'] as $entry) {
                $original_id = isset($entry['original_id']) ? (int) $entry['original_id'] : 0;
                if (
                    ! $original_id
                    || self::get_local_id($original_id)
                    || ($honor_resolver_decisions && self::get_resolver_decision($original_id))
                ) {
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

                    if (class_exists('DBVC_Database')) {
                        $file_path = get_attached_file($existing_id);
                        $file_hash = ($file_path && is_readable($file_path)) ? hash_file('sha256', $file_path) : null;
                        $file_size = ($file_path && is_readable($file_path)) ? filesize($file_path) : null;

                        DBVC_Database::upsert_media([
                            'attachment_id' => $existing_id,
                            'original_id'   => $original_id,
                            'source_url'    => $entry['source_url'] ?? null,
                            'relative_path' => null,
                            'file_hash'     => $file_hash,
                            'file_size'     => $file_size,
                            'mime_type'     => $entry['mime_type'] ?? null,
                        ]);
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
            if (empty($queue)) {
                return;
            }

            $downloaded = 0;
            $errors     = 0;

            foreach ($queue as $item) {
                if (! empty($item['remote_blocked']) || empty($item['source_url'])) {
                    continue;
                }

                $result = self::download_item($item);
                if (is_wp_error($result)) {
                    $errors++;
                    self::$stats['errors']++;
                    DBVC_Sync_Logger::log_media('Media download failed', [
                        'error'        => $result->get_error_message(),
                        'original_id'  => $item['original_id'],
                        'source_url'   => $item['source_url'],
                    ]);
                    if (class_exists('DBVC_Database')) {
                        DBVC_Database::log_activity(
                            'media_download_failed',
                            'error',
                            $result->get_error_message(),
                            [
                                'original_id' => $item['original_id'],
                                'source_url'  => $item['source_url'],
                            ]
                        );
                    }
                } else {
                    $downloaded++;
                }
            }

            self::log_event('media_sync_remote_processed', 'Remote media queue processed', [
                'queued'      => count($queue),
                'downloaded'  => $downloaded,
                'errors'      => $errors,
            ]);
        }

        /**
         * Attempt to import bundled media files based on manifest entries.
         *
         * @param array $queue
         * @param array $manifest
         * @return void
         */
        private static function process_bundled_media(array &$queue, array $manifest)
        {
            if (empty($queue)) {
                return;
            }

            $mode        = self::get_transport_mode();
            $handled     = 0;
            $missing     = 0;
            $hash_miss   = 0;
            $import_fail = 0;

            foreach ($queue as &$item) {
                if (! empty($item['handled'])) {
                    continue;
                }

                $relative = isset($item['relative_path']) ? wp_normalize_path($item['relative_path']) : '';
                if (! $relative) {
                    continue;
                }

                $absolute = wp_normalize_path(trailingslashit(dbvc_get_sync_path()) . ltrim($relative, '/'));
                if (! file_exists($absolute)) {
                    if ($mode === 'bundled') {
                        self::$stats['errors']++;
                        DBVC_Sync_Logger::log_media('Bundled media missing', [
                            'original_id'   => $item['original_id'],
                            'relative_path' => $relative,
                        ]);
                        $missing++;
                        $item['handled'] = true;
                    }
                    continue;
                }

                $hash_expected = '';
                if (! empty($item['hash'])) {
                    $parts = explode(':', $item['hash'], 2);
                    $hash_expected = end($parts);
                }

                $hash_actual = hash_file('sha256', $absolute);
                if ($hash_expected && ! hash_equals($hash_expected, $hash_actual)) {
                    DBVC_Sync_Logger::log_media('Bundled media hash mismatch', [
                        'relative_path' => $relative,
                        'expected'      => $hash_expected,
                        'actual'        => $hash_actual,
                    ]);
                    if ($mode === 'bundled') {
                        self::$stats['errors']++;
                        $hash_miss++;
                        $item['handled'] = true;
                    }
                    continue;
                }

                $attachment_id = self::import_bundled_file($absolute, $item);
                if (is_wp_error($attachment_id)) {
                    DBVC_Sync_Logger::log_media('Bundled media import failed', [
                        'error'        => $attachment_id->get_error_message(),
                        'relative_path'=> $relative,
                        'original_id'  => $item['original_id'],
                    ]);
                    if ($mode === 'bundled') {
                        self::$stats['errors']++;
                        $import_fail++;
                        $item['handled'] = true;
                    }
                    continue;
                }

                $item['handled'] = true;
                self::set_mapping($item['original_id'], $attachment_id);
                self::register_url_mapping($item['source_url'], $attachment_id);
                self::$stats['downloaded']++;
                $handled++;

                DBVC_Sync_Logger::log_media('Bundled media imported', [
                    'original_id'   => $item['original_id'],
                    'attachment_id' => $attachment_id,
                    'relative_path' => $relative,
                ]);

                if (class_exists('DBVC_Database')) {
                    DBVC_Database::upsert_media([
                        'attachment_id' => $attachment_id,
                        'original_id'   => $item['original_id'],
                        'relative_path' => $relative,
                        'file_hash'     => $hash_actual,
                        'file_size'     => filesize($absolute),
                        'mime_type'     => $item['mime_type'] ?? null,
                        'source_url'    => $item['source_url'] ?? null,
                    ]);

                    DBVC_Database::log_activity(
                        'media_bundled_imported',
                        'info',
                        'Bundled media imported',
                        [
                            'original_id'   => $item['original_id'],
                            'attachment_id' => $attachment_id,
                            'relative_path' => $relative,
                        ]
                    );
                }
            }

            self::log_event('media_sync_bundled_processed', 'Bundled media processed', [
                'queued'        => count($queue),
                'handled'       => $handled,
                'missing'       => $missing,
                'hash_mismatch' => $hash_miss,
                'import_errors' => $import_fail,
                'mode'          => $mode,
            ]);
        }

        /**
         * Create a local attachment from a bundled file on disk.
         *
         * @param string $absolute_path
         * @param array  $item
         * @return int|\WP_Error
         */
        private static function import_bundled_file($absolute_path, array $item)
        {
            $tmp = wp_tempnam();
            if (! $tmp || ! @copy($absolute_path, $tmp)) {
                return new WP_Error('dbvc_bundle_copy_failed', __('Unable to stage bundled media file.', 'dbvc'));
            }

            $file_array = [
                'name'     => $item['filename'] ?: basename($absolute_path),
                'tmp_name' => $tmp,
            ];

            $attachment_id = media_handle_sideload(
                $file_array,
                0,
                $item['title'] ?? '',
                [
                    'post_mime_type' => $item['mime_type'] ?? null,
                ]
            );

            if (is_wp_error($attachment_id)) {
                @unlink($tmp);
                return $attachment_id;
            }

            update_post_meta($attachment_id, '_dbvc_original_attachment_id', (int) $item['original_id']);
            if (! empty($item['source_url'])) {
                update_post_meta($attachment_id, '_dbvc_original_source_url', esc_url_raw($item['source_url']));
            }

            return $attachment_id;
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

            DBVC_Sync_Logger::log_media('Downloaded media asset', [
                'original_id' => $item['original_id'],
                'attachment_id' => $attachment_id,
                'source_url'  => $item['source_url'],
            ]);

            if (class_exists('DBVC_Database')) {
                $file_path = get_attached_file($attachment_id);
                $file_hash = ($file_path && is_readable($file_path)) ? hash_file('sha256', $file_path) : null;
                $file_size = ($file_path && is_readable($file_path)) ? filesize($file_path) : null;

                DBVC_Database::upsert_media([
                    'attachment_id' => $attachment_id,
                    'original_id'   => $item['original_id'] ?? null,
                    'source_url'    => $item['source_url'] ?? null,
                    'relative_path' => null,
                    'file_hash'     => $file_hash,
                    'file_size'     => $file_size,
                    'mime_type'     => $item['mime_type'] ?? null,
                ]);

                DBVC_Database::log_activity(
                    'media_downloaded',
                    'info',
                    'Media asset downloaded',
                    [
                        'original_id'   => $item['original_id'],
                        'attachment_id' => $attachment_id,
                        'source_url'    => $item['source_url'],
                    ]
                );
            }

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

                $raw_post_id = $item['post_id'] ?? 0;
                $original_post_id = is_numeric($raw_post_id) ? (int) $raw_post_id : 0;
                $entity_uid = isset($item['vf_object_uid'])
                    ? (string) $item['vf_object_uid']
                    : (string) $original_post_id;
                if (! $original_post_id && $entity_uid === '') {
                    continue;
                }

                $local_post_id = DBVC_Sync_Posts::resolve_local_post_id($original_post_id, $entity_uid, $item['post_type'] ?? '');
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
                    self::log_event('media_meta_thumbnail_updated', 'Post thumbnail remapped', [
                        'post_id'      => $post_id,
                        'original_id'  => $original_id,
                        'attachment_id'=> $new_id,
                    ]);
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
                    self::log_event('media_meta_reference_updated', 'Post meta reference remapped', [
                        'post_id'      => $post_id,
                        'meta_key'     => $meta_key,
                        'original_id'  => $original_id,
                        'attachment_id'=> $new_id,
                        'path'         => implode('/', $ref['path'] ?? []),
                    ]);
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

                if (! $original_id) {
                    continue;
                }

                if (self::get_local_id($original_id)) {
                    $skipped_existing++;
                    continue;
                }
                $relative_path = isset($entry['relative_path']) ? sanitize_text_field($entry['relative_path']) : '';
                $bundle_path   = isset($entry['bundle_path']) ? sanitize_text_field($entry['bundle_path']) : $relative_path;

                if (empty($source_url) && ! $relative_path) {
                    continue;
                }

                $remote_blocked = $source_url && ! self::is_source_allowed($source_url);
                if ($remote_blocked) {
                    $blocked[] = [
                        'original_id' => $original_id,
                        'source_url'  => $source_url,
                        'reason'      => 'host_not_allowed',
                    ];
                    if (! $relative_path) {
                        continue;
                    }
                }

                $source_host = parse_url($source_url, PHP_URL_HOST);
                $raw_hash    = '';
                if (! empty($entry['file_hash'])) {
                    $raw_hash = sanitize_text_field($entry['file_hash']);
                } elseif (! empty($entry['hash'])) {
                    $raw_hash = sanitize_text_field($entry['hash']);
                }

                $queue[] = [
                    'original_id'   => $original_id,
                    'asset_uid'     => isset($entry['asset_uid']) ? sanitize_text_field($entry['asset_uid']) : '',
                    'source_url'    => $source_url,
                    'source_host'   => $source_host ? (string) $source_host : '',
                    'mime_type'     => isset($entry['mime_type']) ? sanitize_text_field($entry['mime_type']) : '',
                    'title'         => isset($entry['title']) ? sanitize_text_field($entry['title']) : '',
                    'filename'      => isset($entry['filename']) ? sanitize_file_name($entry['filename']) : '',
                    'hash'          => $raw_hash,
                    'relative_path' => $relative_path,
                    'bundle_path'   => $bundle_path,
                    'filesize'      => isset($entry['filesize']) ? (int) $entry['filesize'] : 0,
                    'remote_blocked'=> $remote_blocked,
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
         * Apply resolver results by seeding ID mappings and pruning queue items.
         *
         * @param array|null $resolver_result
         * @param array      $queue
         * @return array|null
         */
        private static function apply_resolver_result($resolver_result, array &$queue)
        {
            if (! is_array($resolver_result) || empty($resolver_result['attachments'])) {
                return $resolver_result;
            }

            foreach ($resolver_result['attachments'] as $asset_key => $resolution) {
                $descriptor = isset($resolution['descriptor']) && is_array($resolution['descriptor'])
                    ? $resolution['descriptor']
                    : [];

                $original_id = isset($descriptor['original_id']) ? absint($descriptor['original_id']) : 0;
                if (! $original_id) {
                    continue;
                }

                $status     = isset($resolution['status']) ? (string) $resolution['status'] : '';
                $target_id  = isset($resolution['target_id']) ? absint($resolution['target_id']) : 0;
                $source_url = isset($descriptor['source_url']) ? esc_url_raw($descriptor['source_url']) : '';
                $decision   = self::get_resolver_decision($original_id);

                if ($decision) {
                    $action = $decision['action'];
                    $source = $decision['source'];

                    if (in_array($action, ['reuse', 'map'], true)) {
                        $target_id = isset($decision['target_id']) ? absint($decision['target_id']) : 0;
                        if (self::is_valid_resolver_target($target_id)) {
                            self::set_mapping($original_id, $target_id);
                            if ($source_url !== '') {
                                self::register_url_mapping($source_url, $target_id);
                            }
                            self::$stats['reused']++;
                            self::mark_queue_handled($queue, $original_id, $target_id);
                            $resolution['status']       = $action === 'map' ? 'mapped' : 'reused';
                            $resolution['target_id']    = $target_id;
                            $resolution['resolved_via'] = 'decision:' . $source;
                            $resolution['reason']       = null;
                            $resolver_result['id_map'][$asset_key] = $target_id;
                            self::record_resolver_decision_outcome($original_id, $decision, 'applied', $target_id);
                            self::log_event('media_resolver_decision_reuse', 'Resolver decision applied (reuse/map)', [
                                'original_id' => $original_id,
                                'target_id'   => $target_id,
                                'action'      => $action,
                                'source'      => $source,
                            ]);
                        } else {
                            unset(self::$map[$original_id], $resolver_result['id_map'][$asset_key]);
                            if ($source_url !== '') {
                                unset(self::$url_map[$source_url]);
                            }
                            self::mark_queue_handled($queue, $original_id, null);
                            self::$stats['errors']++;
                            $resolution['status']       = 'decision_failed';
                            $resolution['target_id']    = null;
                            $resolution['resolved_via'] = 'decision:' . $source;
                            $resolution['reason']       = 'invalid_attachment_target';
                            self::record_resolver_decision_outcome(
                                $original_id,
                                $decision,
                                'failed',
                                0,
                                'invalid_attachment_target'
                            );
                            self::log_event('media_resolver_decision_failed', 'Resolver decision target is not an attachment', [
                                'original_id' => $original_id,
                                'target_id'   => $target_id,
                                'action'      => $action,
                                'source'      => $source,
                            ], 'error');
                        }

                        $resolver_result['attachments'][$asset_key] = $resolution;
                        continue;
                    }

                    if ($action === 'skip') {
                        unset(self::$map[$original_id], $resolver_result['id_map'][$asset_key]);
                        if ($source_url !== '') {
                            unset(self::$url_map[$source_url]);
                        }
                        self::mark_queue_handled($queue, $original_id, null);
                        $resolution['status']       = 'skipped';
                        $resolution['target_id']    = null;
                        $resolution['resolved_via'] = 'decision:' . $source;
                        $resolution['reason']       = null;
                        self::record_resolver_decision_outcome($original_id, $decision, 'applied');
                        self::log_event('media_resolver_decision_skip', 'Resolver decision applied (skip)', [
                            'original_id' => $original_id,
                            'source'      => $source,
                        ]);
                        $resolver_result['attachments'][$asset_key] = $resolution;
                        continue;
                    }

                    if ($action === 'download') {
                        $reconciled_id = isset(self::$resolver_reconcile_map[$original_id])
                            ? absint(self::$resolver_reconcile_map[$original_id])
                            : 0;

                        unset(self::$map[$original_id], $resolver_result['id_map'][$asset_key]);
                        if ($source_url !== '') {
                            unset(self::$url_map[$source_url]);
                        }

                        if (self::is_valid_resolver_target($reconciled_id)) {
                            self::set_mapping($original_id, $reconciled_id);
                            if ($source_url !== '') {
                                self::register_url_mapping($source_url, $reconciled_id);
                            }
                            self::$stats['downloaded']++;
                            self::mark_queue_handled($queue, $original_id, $reconciled_id);
                            $resolution['status']       = 'downloaded';
                            $resolution['target_id']    = $reconciled_id;
                            $resolution['resolved_via'] = 'decision:' . $source;
                            $resolution['reason']       = null;
                            $resolver_result['id_map'][$asset_key] = $reconciled_id;
                            self::record_resolver_decision_outcome($original_id, $decision, 'applied', $reconciled_id);
                        } else {
                            self::flag_queue_for_download($queue, $original_id);
                            $resolution['status']       = 'needs_download';
                            $resolution['target_id']    = null;
                            $resolution['resolved_via'] = 'decision:' . $source;
                            $resolution['reason']       = 'forced_download_pending';
                            self::record_resolver_decision_outcome(
                                $original_id,
                                $decision,
                                'pending',
                                0,
                                'forced_download_pending'
                            );
                        }

                        self::log_event('media_resolver_decision_download', 'Resolver decision applied (download)', [
                            'original_id' => $original_id,
                            'target_id'   => $reconciled_id,
                            'source'      => $source,
                        ]);
                        $resolver_result['attachments'][$asset_key] = $resolution;
                        continue;
                    }
                }

                if ($status === 'reused' && $target_id) {
                    $current = self::get_local_id($original_id);
                    self::set_mapping($original_id, $target_id);
                    if (! $current) {
                        self::$stats['reused']++;
                    }
                    self::mark_queue_handled($queue, $original_id, $target_id);
                    continue;
                }

                if ($status === 'conflict') {
                    self::log_event(
                        'media_resolver_conflict',
                        'Resolver conflict detected',
                        [
                            'original_id' => $original_id,
                            'asset_uid'   => $descriptor['asset_uid'] ?? null,
                            'reason'      => $resolution['reason'] ?? '',
                            'candidates'  => $resolution['candidates'] ?? [],
                        ],
                        'warning'
                    );
                }
            }

            return self::rebuild_resolver_metrics($resolver_result);
        }

        /**
         * Complete pending download outcomes after bundle/remote processing.
         *
         * @param array|null $resolver_result
         * @return array|null
         */
        private static function finalize_resolver_decision_outcomes($resolver_result)
        {
            foreach (self::$resolver_decision_outcomes as $original_id => $outcome) {
                if ($outcome['status'] !== 'pending') {
                    continue;
                }

                $target_id = self::get_local_id($original_id);
                if (self::is_valid_resolver_target($target_id)) {
                    self::$resolver_decision_outcomes[$original_id]['status']    = 'applied';
                    self::$resolver_decision_outcomes[$original_id]['target_id'] = (int) $target_id;
                    self::$resolver_decision_outcomes[$original_id]['reason']    = '';
                } else {
                    self::$resolver_decision_outcomes[$original_id]['status'] = 'failed';
                    self::$resolver_decision_outcomes[$original_id]['reason'] = 'download_not_completed';
                }
            }

            if (! is_array($resolver_result)) {
                return $resolver_result;
            }

            foreach ($resolver_result['attachments'] ?? [] as $asset_key => $resolution) {
                $descriptor = isset($resolution['descriptor']) && is_array($resolution['descriptor'])
                    ? $resolution['descriptor']
                    : [];
                $original_id = isset($descriptor['original_id']) ? absint($descriptor['original_id']) : 0;
                if (! $original_id || ! isset(self::$resolver_decision_outcomes[$original_id])) {
                    continue;
                }

                $outcome = self::$resolver_decision_outcomes[$original_id];
                $resolution['decision'] = $outcome;
                if ($outcome['status'] === 'applied') {
                    if ($outcome['action'] === 'skip') {
                        $resolution['status']    = 'skipped';
                        $resolution['target_id'] = null;
                        unset($resolver_result['id_map'][$asset_key]);
                    } elseif (! empty($outcome['target_id'])) {
                        $resolution['status']    = $outcome['action'] === 'download'
                            ? 'downloaded'
                            : ($outcome['action'] === 'map' ? 'mapped' : 'reused');
                        $resolution['target_id'] = (int) $outcome['target_id'];
                        $resolver_result['id_map'][$asset_key] = (int) $outcome['target_id'];
                    }
                    $resolution['reason'] = null;
                } elseif ($outcome['status'] === 'failed') {
                    $resolution['status']    = 'decision_failed';
                    $resolution['target_id'] = null;
                    $resolution['reason']    = $outcome['reason'];
                    unset($resolver_result['id_map'][$asset_key]);
                }

                $resolver_result['attachments'][$asset_key] = $resolution;
            }

            $resolver_result = self::rebuild_resolver_metrics($resolver_result);
            $resolver_result['decision_summary'] = self::summarize_resolver_decision_outcomes();
            return $resolver_result;
        }

        /**
         * Record one effective decision outcome.
         *
         * @param int    $original_id
         * @param array  $decision
         * @param string $status
         * @param int    $target_id
         * @param string $reason
         * @return void
         */
        private static function record_resolver_decision_outcome(
            $original_id,
            array $decision,
            $status,
            $target_id = 0,
            $reason = ''
        ) {
            self::$resolver_decision_outcomes[(int) $original_id] = [
                'original_id' => (int) $original_id,
                'action'      => $decision['action'],
                'source'      => $decision['source'],
                'scope'       => $decision['scope'],
                'status'      => sanitize_key($status),
                'target_id'   => $target_id ? (int) $target_id : null,
                'reason'      => sanitize_key($reason),
            ];
        }

        /**
         * Summarize decisions that were actually encountered during this run.
         *
         * @return array
         */
        private static function summarize_resolver_decision_outcomes()
        {
            $summary = [
                'total'    => 0,
                'applied'  => 0,
                'failed'   => 0,
                'pending'  => 0,
                'reuse'    => 0,
                'map'      => 0,
                'download' => 0,
                'skip'     => 0,
                'sources'  => [
                    'proposal' => 0,
                    'global'   => 0,
                ],
                'results'  => [],
            ];

            foreach (self::$resolver_decision_outcomes as $outcome) {
                $summary['total']++;
                if (isset($summary[$outcome['status']])) {
                    $summary[$outcome['status']]++;
                }
                if ($outcome['status'] === 'applied' && isset($summary[$outcome['action']])) {
                    $summary[$outcome['action']]++;
                }
                if (isset($summary['sources'][$outcome['source']])) {
                    $summary['sources'][$outcome['source']]++;
                }
                $summary['results'][] = $outcome;
            }

            return $summary;
        }

        /**
         * Extract the attachment map created by the earlier reconciliation pass.
         *
         * @param mixed $reconcile_result
         * @return array
         */
        private static function extract_reconcile_id_map($reconcile_result)
        {
            if (! is_array($reconcile_result)) {
                return [];
            }

            $map = isset($reconcile_result['original_id_map']) && is_array($reconcile_result['original_id_map'])
                ? $reconcile_result['original_id_map']
                : [];

            return array_filter(array_map('absint', $map));
        }

        /**
         * Recalculate metrics after explicit decisions change resolver statuses.
         *
         * @param array $resolver_result
         * @return array
         */
        private static function rebuild_resolver_metrics(array $resolver_result)
        {
            $metrics = [
                'detected'    => 0,
                'reused'      => 0,
                'downloaded'  => 0,
                'skipped'     => 0,
                'unresolved'  => 0,
                'blocked'     => 0,
                'bundle_hits' => 0,
            ];
            $conflicts = [];

            foreach ($resolver_result['attachments'] ?? [] as $resolution) {
                $metrics['detected']++;
                $status = isset($resolution['status']) ? (string) $resolution['status'] : 'unresolved';

                if (in_array($status, ['reused', 'mapped'], true)) {
                    $metrics['reused']++;
                } elseif ($status === 'downloaded') {
                    $metrics['downloaded']++;
                } elseif ($status === 'skipped') {
                    $metrics['skipped']++;
                } else {
                    $metrics['unresolved']++;
                }

                if (! empty($resolution['blocked_reason'])) {
                    $metrics['blocked']++;
                }
                if (! empty($resolution['bundle_hit'])) {
                    $metrics['bundle_hits']++;
                }
                if (in_array($status, ['conflict', 'decision_failed'], true)) {
                    $conflicts[] = $resolution;
                }
            }

            $resolver_result['metrics']   = $metrics;
            $resolver_result['conflicts'] = $conflicts;
            return $resolver_result;
        }

        /**
         * Determine whether legacy queue processing should run.
         *
         * @param array|null $resolver_result
         * @param array      $queue
         * @return bool
         */
        private static function should_run_legacy_sync($resolver_result, array $queue): bool
        {
            $default = ! empty($queue);

            if (is_array($resolver_result) && isset($resolver_result['metrics'])) {
                $metrics     = $resolver_result['metrics'];
                $unresolved  = isset($metrics['unresolved']) ? (int) $metrics['unresolved'] : null;
                $detected    = isset($metrics['detected']) ? (int) $metrics['detected'] : null;
                $resolved_id = isset($resolver_result['id_map']) ? count($resolver_result['id_map']) : 0;

                if (
                    $unresolved === 0
                    && $detected !== null
                    && $resolved_id >= $detected
                ) {
                    $default = false;
                }
            }

            /**
             * Filter whether the legacy media sync queue should run.
             *
             * @param bool  $should_run     Default decision.
             * @param array $resolver_result Resolver payload (may be null).
             * @param array $queue           Remaining queue entries.
             */
            return apply_filters('dbvc_media_use_legacy_sync', $default, $resolver_result, $queue);
        }

        /**
         * Summarize source hosts for logging.
         *
         * @param array $items
         * @return array
         */
        private static function summarize_hosts(array $items)
        {
            $summary = [];
            foreach ($items as $item) {
                $host = isset($item['source_host']) && $item['source_host'] !== '' ? $item['source_host'] : 'local';
                if (! isset($summary[$host])) {
                    $summary[$host] = 0;
                }
                $summary[$host]++;
            }
            return $summary;
        }

        /**
         * Summarize blocked reasons for logging.
         *
         * @param array $blocked
         * @return array
         */
        private static function summarize_blocked(array $blocked)
        {
            $summary = [];
            foreach ($blocked as $item) {
                $reason = isset($item['reason']) ? (string) $item['reason'] : 'unknown';
                if (! isset($summary[$reason])) {
                    $summary[$reason] = 0;
                }
                $summary[$reason]++;
            }
            return $summary;
        }

        /**
         * Summarize relative path availability for logging.
         *
         * @param array $items
         * @return array
         */
        private static function summarize_relative_paths(array $items)
        {
            $with    = 0;
            $without = 0;

            foreach ($items as $item) {
                if (! empty($item['relative_path'])) {
                    $with++;
                } else {
                    $without++;
                }
            }

            return [
                'with_relative_path'    => $with,
                'without_relative_path' => $without,
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
         * Return normalized decisions with proposal choices overriding globals.
         *
         * @param string $proposal_id
         * @return array
         */
        public static function get_effective_resolver_decisions($proposal_id)
        {
            $store = get_option('dbvc_resolver_decisions', []);
            if (! is_array($store)) {
                return [];
            }

            $effective = [];
            $global = isset($store['__global']) && is_array($store['__global'])
                ? $store['__global']
                : [];
            $proposal = $proposal_id !== '' && isset($store[$proposal_id]) && is_array($store[$proposal_id])
                ? $store[$proposal_id]
                : [];

            foreach ($global as $original_id => $decision) {
                $normalized = self::normalize_resolver_decision($decision, 'global');
                $key = (string) absint($original_id);
                if ($key !== '0' && $normalized) {
                    $effective[$key] = $normalized;
                }
            }

            foreach ($proposal as $original_id => $decision) {
                if ($original_id === '__summary') {
                    continue;
                }
                $normalized = self::normalize_resolver_decision($decision, 'proposal');
                $key = (string) absint($original_id);
                if ($key !== '0' && $normalized) {
                    $effective[$key] = $normalized;
                }
            }

            return $effective;
        }

        /**
         * Validate a manual map/reuse target.
         *
         * @param mixed $target_id
         * @return bool
         */
        public static function is_valid_resolver_target($target_id)
        {
            $target_id = absint($target_id);
            return $target_id > 0 && get_post_type($target_id) === 'attachment';
        }

        private static function load_resolver_decisions($proposal_id)
        {
            self::$resolver_decisions = [];
            self::$resolver_global_decisions = [];
            self::$resolver_effective_decisions = self::get_effective_resolver_decisions($proposal_id);

            foreach (self::$resolver_effective_decisions as $original_id => $decision) {
                if ($decision['source'] === 'proposal') {
                    self::$resolver_decisions[$original_id] = $decision;
                } else {
                    self::$resolver_global_decisions[$original_id] = $decision;
                }
            }
        }

        private static function normalize_resolver_decision($decision, $source)
        {
            if (! is_array($decision)) {
                return null;
            }

            $action = isset($decision['action']) ? sanitize_key($decision['action']) : '';
            if (! in_array($action, ['reuse', 'map', 'download', 'skip'], true)) {
                return null;
            }

            $normalized = $decision;
            $normalized['action'] = $action;
            $normalized['source'] = $source === 'global' ? 'global' : 'proposal';
            $normalized['scope']  = $normalized['source'];
            $normalized['target_id'] = isset($decision['target_id']) ? absint($decision['target_id']) : null;

            return $normalized;
        }

        private static function get_resolver_decision($original_id)
        {
            $key = (string) absint($original_id);
            return isset(self::$resolver_effective_decisions[$key])
                ? self::$resolver_effective_decisions[$key]
                : null;
        }

        private static function mark_queue_handled(array &$queue, $original_id, $local_id = null)
        {
            foreach ($queue as &$item) {
                if ((int) ($item['original_id'] ?? 0) === (int) $original_id) {
                    $item['handled'] = true;
                    if ($local_id) {
                        $item['local_id'] = $local_id;
                    }
                    break;
                }
            }
            unset($item);
        }

        private static function flag_queue_for_download(array &$queue, $original_id)
        {
            foreach ($queue as &$item) {
                if ((int) ($item['original_id'] ?? 0) === (int) $original_id) {
                    $item['force_download'] = true;
                    $item['handled']        = false;
                    break;
                }
            }
            unset($item);
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
