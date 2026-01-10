<?php

namespace Dbvc\Media;

/**
 * Coordinates deterministic media bundle generation and ingestion.
 */
final class BundleManager
{
    private const BACKUP_SUBDIRECTORY = 'media-bundle';

    /**
     * Build or refresh the bundle for a proposal.
     *
     * @param string $proposal_id
     * @param array  $media_index
     * @return array
     */
    public static function build_bundle(string $proposal_id, array $media_index): array
    {
        $proposal_id = sanitize_file_name($proposal_id);
        if ($proposal_id === '' || empty($media_index)) {
            return [
                'copied'   => 0,
                'bytes'    => 0,
                'errors'   => 0,
                'skipped'  => 0,
                'path'     => '',
                'relative' => '',
            ];
        }

        $bundle_dir = self::ensure_proposal_directory($proposal_id);
        if (! $bundle_dir) {
            return [
                'copied'   => 0,
                'bytes'    => 0,
                'errors'   => count($media_index),
                'skipped'  => 0,
                'path'     => '',
                'relative' => '',
            ];
        }

        self::purge_directory($bundle_dir);

        $uploads = wp_get_upload_dir();
        $copied  = 0;
        $bytes   = 0;
        $errors  = 0;
        $skipped = 0;

        foreach ($media_index as $entry) {
            $source_path = self::locate_source_file($entry, $uploads);
            $relative    = self::normalize_relative_path($entry);

            if (! $source_path || ! file_exists($source_path)) {
                $errors++;
                continue;
            }

            if ($relative === '') {
                $relative = sanitize_file_name($entry['filename'] ?? basename($source_path));
            }

            $target_path = wp_normalize_path(trailingslashit($bundle_dir) . ltrim($relative, '/'));
            $target_dir  = dirname($target_path);
            if (! is_dir($target_dir)) {
                wp_mkdir_p($target_dir);
            }

            $hash_prefixed = isset($entry['file_hash']) ? (string) $entry['file_hash'] : '';
            $expected_hash = self::strip_algorithm_prefix($hash_prefixed);
            $existing_hash = '';
            if (file_exists($target_path)) {
                $existing_hash = hash_file('sha256', $target_path);
            }

            if ($existing_hash && $expected_hash && hash_equals($existing_hash, $expected_hash)) {
                $skipped++;
                continue;
            }

            if (! @copy($source_path, $target_path)) {
                $errors++;
                continue;
            }

            if (class_exists('\DBVC_Database')) {
                \DBVC_Database::upsert_media([
                    'attachment_id' => $entry['original_id'] ?? null,
                    'original_id'   => $entry['original_id'] ?? null,
                    'relative_path' => $relative,
                    'file_hash'     => $expected_hash ?: null,
                    'file_size'     => file_exists($target_path) ? filesize($target_path) : null,
                    'mime_type'     => $entry['mime_type'] ?? null,
                    'source_url'    => $entry['source_url'] ?? null,
                ]);
            }

            $copied++;
            $file_size = file_exists($target_path) ? filesize($target_path) : 0;
            if ($file_size) {
                $bytes += (int) $file_size;
            }
        }

        self::write_map_file($bundle_dir, $proposal_id, $media_index);
        Logger::log('media:enqueue', 'Media bundle refreshed', [
            'proposal_id' => $proposal_id,
            'path'        => $bundle_dir,
            'copied'      => $copied,
            'skipped'     => $skipped,
            'errors'      => $errors,
        ]);

        return [
            'copied'   => $copied,
            'bytes'    => $bytes,
            'errors'   => $errors,
            'skipped'  => $skipped,
            'path'     => $bundle_dir,
            'relative' => self::get_storage_relative_path($proposal_id),
        ];
    }

    /**
     * Copy the global bundle into a backup folder for distribution.
     *
     * @param string $proposal_id
     * @param string $backup_path
     * @return string|null Target directory.
     */
    public static function mirror_to_backup(string $proposal_id, string $backup_path): ?string
    {
        $source = self::get_proposal_directory($proposal_id);
        if (! $source || ! is_dir($source)) {
            return null;
        }

        $target = trailingslashit($backup_path) . self::BACKUP_SUBDIRECTORY;
        self::purge_directory($target);
        wp_mkdir_p($target);
        self::ensure_security($target);
        self::recursive_copy($source, $target);
        return $target;
    }

    /**
     * Ingest a bundle directory from a restored backup.
     *
     * @param string $proposal_id
     * @param string $backup_path
     * @return string|null Absolute path to ingested directory.
     */
    public static function ingest_from_backup(string $proposal_id, string $backup_path): ?string
    {
        $proposal_id = sanitize_file_name($proposal_id);
        if ($proposal_id === '') {
            return null;
        }

        $source = trailingslashit($backup_path) . self::BACKUP_SUBDIRECTORY;
        if (! is_dir($source)) {
            return null;
        }

        $destination = self::ensure_proposal_directory($proposal_id);
        if (! $destination) {
            return null;
        }

        self::purge_directory($destination);
        self::recursive_copy($source, $destination);
        Logger::log('media:download', 'Ingested bundle from backup', [
            'proposal_id' => $proposal_id,
            'source'      => $source,
        ]);

        return $destination;
    }

    /**
     * Build manifest metadata for the generated bundle.
     *
     * @param string $proposal_id
     * @param array  $stats
     * @return array|null
     */
    public static function build_manifest_metadata(string $proposal_id, array $stats): ?array
    {
        $proposal_id = sanitize_file_name($proposal_id);
        if ($proposal_id === '') {
            return null;
        }

        return [
            'generated_at'  => gmdate('c'),
            'files_copied'  => (int) ($stats['copied'] ?? 0),
            'bytes'         => (int) ($stats['bytes'] ?? 0),
            'errors'        => (int) ($stats['errors'] ?? 0),
            'storage'       => [
                'relative' => self::get_storage_relative_path($proposal_id),
                'absolute' => self::get_proposal_directory($proposal_id),
            ],
            'backup_relative' => self::BACKUP_SUBDIRECTORY,
            'map'             => self::BACKUP_SUBDIRECTORY . '/bundle.json',
        ];
    }

    /**
     * Return the existing bundle directory for a proposal.
     *
     * @param string $proposal_id
     * @return string|null
     */
    public static function get_proposal_directory(string $proposal_id): ?string
    {
        $proposal_id = sanitize_file_name($proposal_id);
        if ($proposal_id === '') {
            return null;
        }

        $root = self::get_bundle_root();
        if (! $root) {
            return null;
        }

        $path = trailingslashit($root) . $proposal_id;
        return is_dir($path) ? $path : null;
    }

    /**
     * Locate a bundle file using descriptor data.
     *
     * @param string $proposal_id
     * @param string $bundle_path
     * @param array  $bundle_meta
     * @param array  $options
     * @return string|null
     */
    public static function locate_bundle_file(string $proposal_id, string $bundle_path, array $bundle_meta = [], array $options = []): ?string
    {
        $bundle_path = self::sanitize_relative_reference($bundle_path);
        if ($bundle_path === '') {
            return null;
        }

        $candidates = [];

        $primary = self::get_proposal_directory($proposal_id);
        if ($primary) {
            $candidates[] = $primary;
        }

        if (! empty($options['bundle_dir']) && is_dir($options['bundle_dir'])) {
            $candidates[] = $options['bundle_dir'];
        }

        if (! empty($bundle_meta['storage']['absolute']) && is_dir($bundle_meta['storage']['absolute'])) {
            $candidates[] = $bundle_meta['storage']['absolute'];
        }

        if (! empty($options['manifest_dir']) && ! empty($bundle_meta['backup_relative'])) {
            $backup_dir = trailingslashit($options['manifest_dir']) . ltrim($bundle_meta['backup_relative'], '/');
            if (is_dir($backup_dir)) {
                $candidates[] = $backup_dir;
            }
        }

        foreach (array_unique($candidates) as $dir) {
            $candidate = wp_normalize_path(trailingslashit($dir) . $bundle_path);
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Compute the relative storage path under the sync root.
     *
     * @param string $proposal_id
     * @return string
     */
    public static function get_storage_relative_path(string $proposal_id): string
    {
        return 'media-bundles/' . sanitize_file_name($proposal_id);
    }

    /**
     * Ensure the proposal directory exists and is hardened.
     *
     * @param string $proposal_id
     * @return string|null
     */
    private static function ensure_proposal_directory(string $proposal_id): ?string
    {
        $root = self::get_bundle_root();
        if (! $root) {
            return null;
        }

        $path = trailingslashit($root) . sanitize_file_name($proposal_id);
        if (! is_dir($path)) {
            wp_mkdir_p($path);
            self::ensure_security($path);
        }

        return $path;
    }

    /**
     * Base directory for media bundles.
     *
     * @return string|null
     */
    private static function get_bundle_root(): ?string
    {
        $upload_dir = wp_get_upload_dir();
        if (! empty($upload_dir['error'])) {
            return null;
        }

        $sync_root = trailingslashit($upload_dir['basedir']) . 'sync';
        if (! is_dir($sync_root)) {
            wp_mkdir_p($sync_root);
        }

        $bundle_root = trailingslashit($sync_root) . 'media-bundles';
        if (! is_dir($bundle_root)) {
            wp_mkdir_p($bundle_root);
        }

        self::ensure_security($bundle_root);
        return $bundle_root;
    }

    /**
     * Remove all contents of a directory without deleting the root.
     *
     * @param string $dir
     * @return void
     */
    private static function purge_directory($dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
    }

    /**
     * Copy a directory recursively.
     *
     * @param string $source
     * @param string $destination
     * @return void
     */
    private static function recursive_copy($source, $destination): void
    {
        $dir = opendir($source);
        if (! $dir) {
            return;
        }

        @mkdir($destination);
        while (false !== ($file = readdir($dir))) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $src  = $source . '/' . $file;
            $dest = $destination . '/' . $file;

            if (is_dir($src)) {
                self::recursive_copy($src, $dest);
            } else {
                @copy($src, $dest);
            }
        }
        closedir($dir);
    }

    /**
     * Add .htaccess/index.php guards.
     *
     * @param string $dir
     * @return void
     */
    private static function ensure_security($dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $htaccess = trailingslashit($dir) . '.htaccess';
        if (! file_exists($htaccess)) {
            file_put_contents(
                $htaccess,
                "Order allow,deny\nDeny from all\n\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n\nOptions -Indexes\n"
            );
        }

        $index = trailingslashit($dir) . 'index.php';
        if (! file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }

    /**
     * Normalize relative file paths.
     *
     * @param array $entry
     * @return string
     */
    private static function normalize_relative_path(array $entry): string
    {
        $relative = '';
        if (! empty($entry['bundle_path'])) {
            $relative = (string) $entry['bundle_path'];
        } elseif (! empty($entry['relative_path'])) {
            $relative = (string) $entry['relative_path'];
        }

        return self::sanitize_relative_reference($relative);
    }

    /**
     * Normalize and validate a relative reference to prevent traversal.
     *
     * @param string $path
     * @return string
     */
    private static function sanitize_relative_reference(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $path = ltrim(wp_normalize_path($path), '/');
        if (strpos($path, 'media/') === 0) {
            $path = substr($path, strlen('media/'));
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return '';
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * Determine the original file path.
     *
     * @param array $entry
     * @param array $uploads
     * @return string|null
     */
    private static function locate_source_file(array $entry, array $uploads): ?string
    {
        $candidates = [];
        $relative   = self::normalize_relative_path($entry);

        if ($relative && ! empty($uploads['basedir'])) {
            $candidates[] = trailingslashit($uploads['basedir']) . $relative;
        }

        if (! empty($entry['original_id'])) {
            $attached = get_attached_file((int) $entry['original_id']);
            if ($attached) {
                $candidates[] = $attached;
            }
        }

        foreach (array_filter($candidates) as $candidate) {
            $candidate = wp_normalize_path($candidate);
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Remove hash algorithm prefix.
     *
     * @param string $hash
     * @return string
     */
    private static function strip_algorithm_prefix(string $hash): string
    {
        if (strpos($hash, ':') === false) {
            return $hash;
        }

        [, $value] = explode(':', $hash, 2);
        return trim($value);
    }

    /**
     * Persist bundle manifest file.
     *
     * @param string $bundle_dir
     * @param string $proposal_id
     * @param array  $media_index
     * @return void
     */
    private static function write_map_file(string $bundle_dir, string $proposal_id, array $media_index): void
    {
        $map = [
            'proposal_id' => $proposal_id,
            'generated_at'=> gmdate('c'),
            'count'       => count($media_index),
            'entries'     => array_values($media_index),
        ];

        file_put_contents(
            trailingslashit($bundle_dir) . 'bundle.json',
            wp_json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}
