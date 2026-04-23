<?php

use Dbvc\AiPackage\Storage;

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Portability_Storage
{
    public const PORTABILITY_SUBDIR = 'bricks-portability';

    /**
     * @return array<string, string>|\WP_Error
     */
    public static function ensure_roots()
    {
        if (! class_exists(Storage::class)) {
            return new \WP_Error('dbvc_bricks_portability_storage_missing', __('DBVC storage runtime is unavailable.', 'dbvc'));
        }

        $dbvc_root = Storage::get_dbvc_root();
        if (is_wp_error($dbvc_root)) {
            return $dbvc_root;
        }

        $root = wp_normalize_path(trailingslashit($dbvc_root) . self::PORTABILITY_SUBDIR);
        $exports = wp_normalize_path(trailingslashit($root) . 'exports');
        $sessions = wp_normalize_path(trailingslashit($root) . 'sessions');
        $backups = wp_normalize_path(trailingslashit($root) . 'backups');

        foreach ([$root, $exports, $sessions, $backups] as $path) {
            $result = Storage::ensure_directory($path);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        return [
            'root' => $root,
            'exports' => $exports,
            'sessions' => $sessions,
            'backups' => $backups,
        ];
    }

    /**
     * @param string $export_id
     * @return string|\WP_Error
     */
    public static function resolve_export_directory($export_id)
    {
        return self::resolve_named_directory('exports', $export_id);
    }

    /**
     * @param string $session_id
     * @return string|\WP_Error
     */
    public static function resolve_session_directory($session_id)
    {
        return self::resolve_named_directory('sessions', $session_id);
    }

    /**
     * @param string $backup_id
     * @return string|\WP_Error
     */
    public static function resolve_backup_directory($backup_id)
    {
        return self::resolve_named_directory('backups', $backup_id);
    }

    /**
     * @param string $export_id
     * @return string|\WP_Error
     */
    public static function resolve_export_zip_path($export_id)
    {
        $directory = self::resolve_export_directory($export_id);
        if (is_wp_error($directory)) {
            return $directory;
        }

        return wp_normalize_path(trailingslashit($directory) . sanitize_file_name($export_id . '.zip'));
    }

    /**
     * @param string $directory
     * @param string $filename
     * @param mixed $payload
     * @return string|\WP_Error
     */
    public static function write_json_file($directory, $filename, $payload)
    {
        $directory = wp_normalize_path((string) $directory);
        if (! is_dir($directory)) {
            return new \WP_Error('dbvc_bricks_portability_directory_missing', __('Portability storage directory is missing.', 'dbvc'));
        }

        $filename = sanitize_file_name((string) $filename);
        if ($filename === '') {
            return new \WP_Error('dbvc_bricks_portability_file_invalid', __('Portability storage filename is invalid.', 'dbvc'));
        }

        $path = wp_normalize_path(trailingslashit($directory) . $filename);
        $bytes = file_put_contents($path, DBVC_Bricks_Portability_Utils::json_encode($payload));
        if ($bytes === false) {
            return new \WP_Error('dbvc_bricks_portability_file_write_failed', __('Failed to write portability JSON file.', 'dbvc'));
        }

        return $path;
    }

    /**
     * @param string $path
     * @return array<string, mixed>|\WP_Error
     */
    public static function read_json_file($path)
    {
        $path = wp_normalize_path((string) $path);
        if (! is_file($path) || ! is_readable($path)) {
            return new \WP_Error('dbvc_bricks_portability_file_missing', __('Portability JSON file could not be read.', 'dbvc'));
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return new \WP_Error('dbvc_bricks_portability_file_empty', __('Portability JSON file is empty.', 'dbvc'));
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return new \WP_Error('dbvc_bricks_portability_file_invalid_json', __('Portability JSON file is invalid.', 'dbvc'));
        }

        return $decoded;
    }

    /**
     * @param string $type
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public static function list_records($type, $limit = 10)
    {
        $roots = self::ensure_roots();
        if (is_wp_error($roots)) {
            return [];
        }

        $type = sanitize_key((string) $type);
        if (! isset($roots[$type])) {
            return [];
        }

        $base = $roots[$type];
        $entries = glob(trailingslashit($base) . '*', GLOB_ONLYDIR);
        if (! is_array($entries)) {
            return [];
        }

        usort($entries, static function ($left, $right) {
            return @filemtime((string) $right) <=> @filemtime((string) $left);
        });

        $items = [];
        foreach (array_slice($entries, 0, max(1, (int) $limit)) as $directory) {
            $record = self::read_record_directory((string) $directory);
            if ($record !== null) {
                $items[] = $record;
            }
        }

        return $items;
    }

    /**
     * @param string $type
     * @param string $identifier
     * @return string|\WP_Error
     */
    private static function resolve_named_directory($type, $identifier)
    {
        $roots = self::ensure_roots();
        if (is_wp_error($roots)) {
            return $roots;
        }

        $type = sanitize_key((string) $type);
        $identifier = sanitize_key((string) $identifier);
        if ($identifier === '') {
            return new \WP_Error('dbvc_bricks_portability_identifier_invalid', __('Portability identifier is invalid.', 'dbvc'));
        }
        if (! isset($roots[$type])) {
            return new \WP_Error('dbvc_bricks_portability_type_invalid', __('Portability storage type is invalid.', 'dbvc'));
        }

        $directory = wp_normalize_path(trailingslashit($roots[$type]) . $identifier);
        $result = Storage::ensure_directory($directory);
        if (is_wp_error($result)) {
            return $result;
        }

        return $directory;
    }

    /**
     * @param string $directory
     * @return array<string, mixed>|null
     */
    private static function read_record_directory($directory)
    {
        $directory = wp_normalize_path((string) $directory);
        if (! is_dir($directory)) {
            return null;
        }

        foreach (['record.json', 'session.json', 'backup.json'] as $filename) {
            $path = wp_normalize_path(trailingslashit($directory) . $filename);
            if (! is_file($path)) {
                continue;
            }

            $record = self::read_json_file($path);
            if (is_wp_error($record)) {
                continue;
            }

            $record['directory'] = $directory;
            return $record;
        }

        return null;
    }
}
