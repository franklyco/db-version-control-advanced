<?php

namespace Dbvc\ConfigurationPortability;

use Dbvc\AiPackage\Storage as AiPackageStorage;

if (! defined('WPINC')) {
    die;
}

final class Storage
{
    public const PORTABILITY_SUBDIR = 'dbvc-config-portability';

    /**
     * @return array<string, string>|\WP_Error
     */
    public static function ensure_roots()
    {
        if (! class_exists(AiPackageStorage::class)) {
            return new \WP_Error('dbvc_config_portability_storage_missing', __('DBVC storage runtime is unavailable.', 'dbvc'));
        }

        $dbvc_root = AiPackageStorage::get_dbvc_root();
        if (\is_wp_error($dbvc_root)) {
            return $dbvc_root;
        }

        $root = self::build_child_path($dbvc_root, self::PORTABILITY_SUBDIR);
        $exports = self::build_child_path($root, 'exports');
        $sessions = self::build_child_path($root, 'sessions');
        $backups = self::build_child_path($root, 'backups');

        foreach ([$root, $exports, $sessions, $backups] as $path) {
            $result = AiPackageStorage::ensure_directory($path);
            if (\is_wp_error($result)) {
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
        if (\is_wp_error($directory)) {
            return $directory;
        }

        return self::build_child_path($directory, sanitize_file_name($export_id . '.zip'));
    }

    /**
     * @param string $directory
     * @param string $relative_path
     * @param mixed  $payload
     * @return string|\WP_Error
     */
    public static function write_json_file($directory, $relative_path, $payload)
    {
        $path = self::resolve_child_path($directory, $relative_path);
        if (\is_wp_error($path)) {
            return $path;
        }

        $parent = wp_normalize_path(dirname($path));
        if (! is_dir($parent)) {
            $result = AiPackageStorage::ensure_directory($parent);
            if (\is_wp_error($result)) {
                return $result;
            }
        }

        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            return new \WP_Error('dbvc_config_portability_json_encode_failed', __('Could not encode configuration portability JSON.', 'dbvc'));
        }

        $bytes = file_put_contents($path, $json . "\n");
        if ($bytes === false) {
            return new \WP_Error('dbvc_config_portability_file_write_failed', __('Could not write configuration portability JSON file.', 'dbvc'));
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
            return new \WP_Error('dbvc_config_portability_file_missing', __('Configuration portability JSON file could not be read.', 'dbvc'));
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            return new \WP_Error('dbvc_config_portability_file_empty', __('Configuration portability JSON file is empty.', 'dbvc'));
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return new \WP_Error('dbvc_config_portability_file_invalid_json', __('Configuration portability JSON file is invalid.', 'dbvc'));
        }

        return $decoded;
    }

    /**
     * @param string $directory
     * @param string $relative_path
     * @return string|\WP_Error
     */
    public static function resolve_child_path($directory, $relative_path)
    {
        $directory = wp_normalize_path(rtrim((string) $directory, '/\\'));
        if ($directory === '') {
            return new \WP_Error('dbvc_config_portability_directory_missing', __('Configuration portability directory is missing.', 'dbvc'));
        }

        $relative_path = str_replace('\\', '/', (string) $relative_path);
        $relative_path = ltrim($relative_path, '/');
        if ($relative_path === '') {
            return new \WP_Error('dbvc_config_portability_path_missing', __('Configuration portability file path is missing.', 'dbvc'));
        }
        if (
            strpos($relative_path, '../') !== false
            || strpos($relative_path, '..\\') !== false
            || preg_match('#^([A-Za-z]:)?[\\\\/]#', $relative_path)
        ) {
            return new \WP_Error('dbvc_config_portability_path_invalid', __('Configuration portability file path is invalid.', 'dbvc'));
        }

        $path = wp_normalize_path(trailingslashit($directory) . $relative_path);
        if (strpos($path, trailingslashit($directory)) !== 0) {
            return new \WP_Error('dbvc_config_portability_path_outside_directory', __('Configuration portability file path escapes its workspace.', 'dbvc'));
        }

        return $path;
    }

    /**
     * @param string $type
     * @param string $identifier
     * @return string|\WP_Error
     */
    private static function resolve_named_directory($type, $identifier)
    {
        $roots = self::ensure_roots();
        if (\is_wp_error($roots)) {
            return $roots;
        }

        $type = sanitize_key((string) $type);
        $identifier = self::sanitize_identifier((string) $identifier);
        if ($identifier === '') {
            return new \WP_Error('dbvc_config_portability_identifier_invalid', __('Configuration portability identifier is invalid.', 'dbvc'));
        }
        if (! isset($roots[$type])) {
            return new \WP_Error('dbvc_config_portability_storage_type_invalid', __('Configuration portability storage type is invalid.', 'dbvc'));
        }

        $directory = self::build_child_path($roots[$type], $identifier);
        $result = AiPackageStorage::ensure_directory($directory);
        if (\is_wp_error($result)) {
            return $result;
        }

        return $directory;
    }

    /**
     * @param string $base
     * @param string $child
     * @return string
     */
    private static function build_child_path($base, $child): string
    {
        return wp_normalize_path(trailingslashit((string) $base) . trim((string) $child, '/\\'));
    }

    /**
     * @param string $identifier
     * @return string
     */
    private static function sanitize_identifier($identifier): string
    {
        $identifier = strtolower(trim((string) $identifier));
        $identifier = preg_replace('/[^a-z0-9_-]+/', '-', $identifier);

        return trim((string) $identifier, '-_');
    }
}
