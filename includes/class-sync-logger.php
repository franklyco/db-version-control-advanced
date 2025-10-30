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
        const OPTION_ENABLED  = 'dbvc_logging_enabled';
        const OPTION_MAX_SIZE = 'dbvc_logging_max_size';
        const DEFAULT_MAX_SIZE = 1048576; // 1MB
        const LOG_FILENAME    = 'dbvc-backup.log';

        /**
         * Write an entry to the custom log file.
         *
         * @param string $message Message to log.
         * @param array  $context Optional structured context.
         * @return void
         */
        public static function log($message, array $context = [])
        {
            if ('1' !== get_option(self::OPTION_ENABLED, '0')) {
                return;
            }

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
         * Return the absolute log file path, ensuring the directory exists.
         *
         * @return string Empty string if unavailable.
         */
        public static function get_log_file_path()
        {
            if (! function_exists('dbvc_get_sync_path')) {
                return '';
            }

            $dir = trailingslashit(dbvc_get_sync_path());
            if (! is_dir($dir) && ! wp_mkdir_p($dir)) {
                return '';
            }

            // Ensure the directory has basic protection.
            if (method_exists('DBVC_Sync_Posts', 'ensure_directory_security')) {
                DBVC_Sync_Posts::ensure_directory_security($dir);
            }

            return $dir . self::LOG_FILENAME;
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
    }
}
