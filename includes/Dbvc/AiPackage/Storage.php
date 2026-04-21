<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class Storage
{
    public const DBVC_UPLOADS_SUBDIR = 'dbvc';
    public const SAMPLE_PACKAGES_SUBDIR = 'ai-sample-packages';
    public const INTAKE_SUBDIR = 'ai-intake';
    public const DEFAULT_RETENTION_DAYS = 7;
    public const CLEANUP_HOOK = 'dbvc_ai_package_cleanup';

    /**
     * Resolve and ensure the core AI storage roots.
     *
     * @return array<string, mixed>|\WP_Error
     */
    public static function ensure_base_roots()
    {
        $uploads_base = self::get_uploads_base_dir();
        if (\is_wp_error($uploads_base)) {
            return $uploads_base;
        }

        $dbvc_root = self::build_child_path($uploads_base, self::DBVC_UPLOADS_SUBDIR);
        $sample_packages_root = self::build_child_path($dbvc_root, self::SAMPLE_PACKAGES_SUBDIR);
        $intake_root = self::build_child_path($dbvc_root, self::INTAKE_SUBDIR);

        $paths = [$dbvc_root, $sample_packages_root, $intake_root];
        foreach ($paths as $path) {
            $result = self::ensure_directory($path);
            if (\is_wp_error($result)) {
                return $result;
            }
        }

        return [
            'uploads_base_dir'      => $uploads_base,
            'dbvc_root'             => $dbvc_root,
            'sample_packages_root'  => $sample_packages_root,
            'intake_root'           => $intake_root,
            'retention_days'        => self::get_retention_days(),
            'cleanup_hook'          => self::CLEANUP_HOOK,
        ];
    }

    /**
     * @return int
     */
    public static function get_retention_days(): int
    {
        $days = (int) apply_filters('dbvc_ai_package_retention_days', self::DEFAULT_RETENTION_DAYS);
        if ($days < 1) {
            $days = self::DEFAULT_RETENTION_DAYS;
        }

        return $days;
    }

    /**
     * @return string|\WP_Error
     */
    public static function get_uploads_base_dir()
    {
        $uploads = wp_upload_dir(null, false);
        if (! empty($uploads['error'])) {
            return new \WP_Error(
                'dbvc_ai_uploads_unavailable',
                sprintf(__('Unable to resolve the uploads directory: %s', 'dbvc'), (string) $uploads['error'])
            );
        }

        $base = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        $base = wp_normalize_path(rtrim($base, '/\\'));
        if ($base === '') {
            return new \WP_Error(
                'dbvc_ai_uploads_missing',
                __('Unable to resolve the uploads base directory for AI package storage.', 'dbvc')
            );
        }

        return $base;
    }

    /**
     * @return string|\WP_Error
     */
    public static function get_dbvc_root()
    {
        $uploads_base = self::get_uploads_base_dir();
        if (\is_wp_error($uploads_base)) {
            return $uploads_base;
        }

        return self::build_child_path($uploads_base, self::DBVC_UPLOADS_SUBDIR);
    }

    /**
     * @return string|\WP_Error
     */
    public static function get_sample_packages_root()
    {
        $dbvc_root = self::get_dbvc_root();
        if (\is_wp_error($dbvc_root)) {
            return $dbvc_root;
        }

        return self::build_child_path($dbvc_root, self::SAMPLE_PACKAGES_SUBDIR);
    }

    /**
     * @return string|\WP_Error
     */
    public static function get_intake_root()
    {
        $dbvc_root = self::get_dbvc_root();
        if (\is_wp_error($dbvc_root)) {
            return $dbvc_root;
        }

        return self::build_child_path($dbvc_root, self::INTAKE_SUBDIR);
    }

    /**
     * @param string $package_id
     * @return string|\WP_Error
     */
    public static function resolve_sample_package_directory(string $package_id)
    {
        $root = self::get_sample_packages_root();
        if (\is_wp_error($root)) {
            return $root;
        }

        $sanitized = self::sanitize_package_id($package_id);
        if ($sanitized === '') {
            return new \WP_Error('dbvc_ai_package_id_invalid', __('Invalid AI sample package identifier.', 'dbvc'));
        }

        return self::build_child_path($root, $sanitized);
    }

    /**
     * @param string $package_id
     * @return string|\WP_Error
     */
    public static function resolve_sample_package_zip_path(string $package_id)
    {
        $root = self::get_sample_packages_root();
        if (\is_wp_error($root)) {
            return $root;
        }

        $sanitized = self::sanitize_package_id($package_id);
        if ($sanitized === '') {
            return new \WP_Error('dbvc_ai_package_id_invalid', __('Invalid AI sample package identifier.', 'dbvc'));
        }

        return self::build_child_path($root, $sanitized . '.zip');
    }

    /**
     * @param string $intake_id
     * @return string|\WP_Error
     */
    public static function resolve_intake_workspace(string $intake_id)
    {
        $root = self::get_intake_root();
        if (\is_wp_error($root)) {
            return $root;
        }

        $sanitized = self::sanitize_package_id($intake_id);
        if ($sanitized === '') {
            return new \WP_Error('dbvc_ai_intake_id_invalid', __('Invalid AI intake identifier.', 'dbvc'));
        }

        return self::build_child_path($root, $sanitized);
    }

    /**
     * @param string $intake_id
     * @param string $relative_path
     * @return string|\WP_Error
     */
    public static function resolve_intake_artifact_path(string $intake_id, string $relative_path)
    {
        $workspace = self::resolve_intake_workspace($intake_id);
        if (\is_wp_error($workspace)) {
            return $workspace;
        }

        $relative_path = str_replace('\\', '/', (string) $relative_path);
        $relative_path = ltrim($relative_path, '/');
        if ($relative_path === '') {
            return new \WP_Error('dbvc_ai_artifact_path_missing', __('Missing AI intake artifact path.', 'dbvc'));
        }

        if (
            strpos($relative_path, '../') !== false
            || strpos($relative_path, '..\\') !== false
            || preg_match('#^([A-Za-z]:)?[\\\\/]#', $relative_path)
        ) {
            return new \WP_Error('dbvc_ai_artifact_path_invalid', __('Invalid AI intake artifact path.', 'dbvc'));
        }

        $workspace = wp_normalize_path(rtrim($workspace, '/\\'));
        $absolute_path = wp_normalize_path(trailingslashit($workspace) . $relative_path);
        if (strpos($absolute_path, trailingslashit($workspace)) !== 0) {
            return new \WP_Error('dbvc_ai_artifact_path_outside_workspace', __('AI intake artifact path is outside the intake workspace.', 'dbvc'));
        }

        return $absolute_path;
    }

    /**
     * @param string $path
     * @return true|\WP_Error
     */
    public static function ensure_directory(string $path)
    {
        $path = wp_normalize_path(rtrim($path, '/\\'));
        if ($path === '') {
            return new \WP_Error('dbvc_ai_storage_path_empty', __('AI package storage path cannot be empty.', 'dbvc'));
        }

        $uploads_base = self::get_uploads_base_dir();
        if (\is_wp_error($uploads_base)) {
            return $uploads_base;
        }

        $uploads_base = wp_normalize_path(rtrim($uploads_base, '/\\'));
        if (strpos($path, $uploads_base) !== 0) {
            return new \WP_Error(
                'dbvc_ai_storage_path_invalid',
                __('AI package storage paths must remain inside the current uploads directory.', 'dbvc')
            );
        }

        if (! is_dir($path) && ! wp_mkdir_p($path)) {
            return new \WP_Error(
                'dbvc_ai_storage_create_failed',
                sprintf(__('Unable to create AI package storage directory: %s', 'dbvc'), $path)
            );
        }

        self::harden_directory($path);

        return true;
    }

    /**
     * @param string $path
     * @return void
     */
    public static function harden_directory(string $path): void
    {
        if (\class_exists('\DBVC_Sync_Posts') && method_exists('\DBVC_Sync_Posts', 'ensure_directory_security')) {
            \DBVC_Sync_Posts::ensure_directory_security($path);
        }
    }

    /**
     * @param string $base
     * @param string $child
     * @return string
     */
    private static function build_child_path(string $base, string $child): string
    {
        return wp_normalize_path(trailingslashit($base) . trim($child, '/\\'));
    }

    /**
     * @param string $package_id
     * @return string
     */
    private static function sanitize_package_id(string $package_id): string
    {
        $package_id = strtolower(trim($package_id));
        $package_id = preg_replace('/[^a-z0-9_-]+/', '-', $package_id);
        $package_id = trim((string) $package_id, '-_');

        return (string) $package_id;
    }
}
