<?php

/**
 * Lightweight logging helper for DBVC.
 *
 * Writes to a file within the configured sync directory when logging is enabled.
 *
 * @package   DB Version Control
 */

// Exit if accessed directly.
if (! defined('WPINC')) {
    die;
}

if (! class_exists('DBVC_Sync_Logger')) {
    class DBVC_Sync_Logger
    {
        const OPTION_ENABLED        = 'dbvc_logging_enabled';
        const OPTION_MAX_SIZE       = 'dbvc_logging_max_size';
        const OPTION_DIRECTORY      = 'dbvc_logging_directory';
        const OPTION_IMPORT_EVENTS  = 'dbvc_logging_imports';
        const OPTION_TERM_EVENTS    = 'dbvc_logging_term_imports';
        const OPTION_UPLOAD_EVENTS  = 'dbvc_logging_uploads';
        const OPTION_MEDIA_EVENTS   = 'dbvc_logging_media';
        const DEFAULT_MAX_SIZE      = 1048576; // 1MB
        const LOG_FILENAME          = 'dbvc-backup.log';

        /**
         * Emit a simple heartbeat message to the active log destination + WP debug log.
         */
        public static function heartbeat($message = 'Logging Active')
        {
            $formatted = sprintf('[DBVC_Plugin] %s', $message);

            if (self::is_core_logging_enabled()) {
                self::write_entry($formatted, self::export_current_settings());
            }

            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log($formatted . ' ' . wp_json_encode(self::export_current_settings()));
            }
        }

        /**
         * Write an entry to the custom log file.
         *
         * @param string $message Message to log.
         * @param array  $context Optional structured context.
         * @return void
         */
        public static function log($message, array $context = [])
        {
            if (! self::is_core_logging_enabled()) {
                return;
            }

            self::write_entry($message, $context);
        }

        /**
         * Write an entry scoped to import activity.
         *
         * @param string $message
         * @param array  $context
         * @return void
         */
        public static function log_import($message, array $context = [])
        {
            if (! self::is_import_logging_enabled()) {
                return;
            }

            self::write_entry($message, $context);
        }

        /**
         * Write an entry scoped to term import activity.
         *
         * @param string $message
         * @param array  $context
         * @return void
         */
        public static function log_term_import($message, array $context = [])
        {
            if (! self::is_term_logging_enabled()) {
                return;
            }

            self::write_entry($message, $context);
        }

        /**
         * Write an entry scoped to sync uploads.
         *
         * @param string $message
         * @param array  $context
         * @return void
         */
        public static function log_upload($message, array $context = [])
        {
            if (! self::is_upload_logging_enabled()) {
                return;
            }

            self::write_entry($message, $context);
        }

        /**
         * Write an entry scoped to media retrieval.
         *
         * @param string $message
         * @param array  $context
         * @return void
         */
        public static function log_media($message, array $context = [])
        {
            if (! self::is_media_logging_enabled()) {
                return;
            }

            self::write_entry($message, $context);
        }

        /**
         * Return the absolute log file path, ensuring the directory exists.
         *
         * @return string Empty string if unavailable.
         */
        public static function get_log_file_path()
        {
            $dir = self::get_log_directory();
            if (! $dir) {
                return '';
            }

            return trailingslashit($dir) . self::LOG_FILENAME;
        }

        /**
         * Resolve the effective log directory (creates it if missing).
         *
         * @return string Directory path without trailing slash or empty string.
         */
        public static function get_log_directory()
        {
            $dir_option = '';
            if (function_exists('get_option')) {
                $dir_option = (string) get_option(self::OPTION_DIRECTORY, '');
            }

            $normalized = self::normalize_directory_option($dir_option);
            if ($normalized === '') {
                $normalized = wp_normalize_path(WP_CONTENT_DIR);
            }

            if (! is_dir($normalized) && ! wp_mkdir_p($normalized)) {
                return '';
            }

            // Only attempt to harden if directory is within the sync path.
            if (
                function_exists('dbvc_get_sync_path')
                && method_exists('DBVC_Sync_Posts', 'ensure_directory_security')
            ) {
                $sync_dir = wp_normalize_path(trailingslashit(dbvc_get_sync_path()));
                $target   = wp_normalize_path(trailingslashit($normalized));
                if ($sync_dir && strpos($target, $sync_dir) === 0) {
                    DBVC_Sync_Posts::ensure_directory_security(rtrim($target, '/'));
                }
            }

            return untrailingslashit($normalized);
        }

        /**
         * Determine if the core logging toggle is active.
         *
         * @return bool
         */
        public static function is_core_logging_enabled()
        {
            return function_exists('get_option') && get_option(self::OPTION_ENABLED, '0') === '1';
        }

        /**
         * Determine if import-related events should be logged.
         *
         * @return bool
         */
        public static function is_import_logging_enabled()
        {
            return self::is_core_logging_enabled() && get_option(self::OPTION_IMPORT_EVENTS, '0') === '1';
        }

        /**
         * Determine if term-specific logging is enabled.
         *
         * @return bool
         */
        public static function is_term_logging_enabled()
        {
            return self::is_import_logging_enabled() && get_option(self::OPTION_TERM_EVENTS, '0') === '1';
        }

        /**
         * Determine if upload-related events should be logged.
         *
         * @return bool
         */
        public static function is_upload_logging_enabled()
        {
            return self::is_core_logging_enabled() && get_option(self::OPTION_UPLOAD_EVENTS, '0') === '1';
        }

        /**
         * Determine if media retrieval events should be logged.
         *
         * @return bool
         */
        public static function is_media_logging_enabled()
        {
            return self::is_core_logging_enabled() && get_option(self::OPTION_MEDIA_EVENTS, '0') === '1';
        }

        /**
         * Normalize the directory option into an absolute path within the WP install.
         *
         * @param string $raw
         * @return string Empty string if invalid or not provided.
         */
        private static function normalize_directory_option($raw)
        {
            $raw = trim((string) $raw);
            if ($raw === '') {
                return '';
            }

            if (function_exists('dbvc_validate_sync_path')) {
                $validated = dbvc_validate_sync_path($raw);
                if ($validated === false) {
                    return '';
                }

                if ($validated === '') {
                    return '';
                }

                $abs = trailingslashit(ABSPATH) . ltrim($validated, '/');
                return wp_normalize_path($abs);
            }

            $normalized = wp_normalize_path($raw);
            if (self::is_absolute_path($normalized)) {
                return $normalized;
            }

            return wp_normalize_path(trailingslashit(ABSPATH) . ltrim($normalized, '/'));
        }

        /**
         * Simple absolute path detector for back-compat.
         *
         * @param string $path
         * @return bool
         */
        private static function is_absolute_path($path)
        {
            if ($path === '') {
                return false;
            }

            if (preg_match('#^[a-zA-Z]:[\\\\/]#', $path)) {
                return true;
            }

            return $path[0] === '/' || strpos($path, '://') !== false;
        }

        /**
         * Delete the current log file.
         *
         * @return bool True on success or if file absent.
         */
        public static function delete_log()
        {
            $path = self::get_log_file_path();
            if (! $path || ! file_exists($path)) {
                return true;
            }
            return (bool) @unlink($path);
        }

        /**
         * Low-level writer that appends a formatted line to the log file.
         *
         * @param string $message
         * @param array  $context
         * @return void
         */
    private static function write_entry($message, array $context = [])
    {
        $path = self::get_log_file_path();
            if (! $path) {
                return;
            }

            self::maybe_rotate_log($path);

            $entry = sprintf(
                "[%s] %s%s\n",
                gmdate('Y-m-d H:i:s'),
                $message,
                $context ? ' ' . wp_json_encode($context) : ''
            );

            file_put_contents($path, $entry, FILE_APPEND);
        }

        /**
         * Get the configured maximum log size in bytes.
         *
         * @return int
         */
        public static function get_max_size()
        {
            $raw = get_option(self::OPTION_MAX_SIZE, self::DEFAULT_MAX_SIZE);
            $size = absint($raw);
            if ($size <= 0) {
                $size = self::DEFAULT_MAX_SIZE;
            }
            return $size;
        }

        /**
         * If the log exceeds the configured size, rotate it.
         *
         * @param string $path
         * @return void
         */
        private static function maybe_rotate_log($path)
        {
            $max_size = self::get_max_size();
            if (! file_exists($path)) {
                return;
            }

            $current_size = filesize($path);
            if ($current_size === false || $current_size < $max_size) {
                return;
            }

            $archive = $path . '.' . gmdate('Ymd-His');
            @rename($path, $archive);
        }

        /**
         * Export current logging toggles/settings for debugging.
         */
        private static function export_current_settings(): array
        {
            if (! function_exists('get_option')) {
                return [];
            }

            return [
                'enabled'        => get_option(self::OPTION_ENABLED, '0'),
                'import_events'  => get_option(self::OPTION_IMPORT_EVENTS, '0'),
                'upload_events'  => get_option(self::OPTION_UPLOAD_EVENTS, '0'),
                'media_events'   => get_option(self::OPTION_MEDIA_EVENTS, '0'),
                'directory'      => get_option(self::OPTION_DIRECTORY, ''),
                'max_size'       => get_option(self::OPTION_MAX_SIZE, self::DEFAULT_MAX_SIZE),
            ];
        }
    }
}
