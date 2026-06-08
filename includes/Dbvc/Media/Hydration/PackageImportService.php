<?php

namespace Dbvc\Media\Hydration;

if (! defined('WPINC')) {
    die;
}

/**
 * Safely imports media mirror ZIP packages into the local DBVC media mirror store.
 */
final class PackageImportService
{
    private const MAX_ENTRIES = 50000;

    /**
     * @param string $zip_path
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public static function import_zip(string $zip_path, array $args = [])
    {
        if (! class_exists('\ZipArchive')) {
            return new \WP_Error('dbvc_media_hydration_zip_missing', __('ZipArchive is required to import media hydration packages.', 'dbvc'), ['status' => 500]);
        }

        if (! is_file($zip_path) || ! is_readable($zip_path)) {
            return new \WP_Error('dbvc_media_hydration_zip_unreadable', __('Media hydration package ZIP is unreadable.', 'dbvc'), ['status' => 400]);
        }

        if (strtolower(pathinfo($zip_path, PATHINFO_EXTENSION)) !== 'zip') {
            return new \WP_Error('dbvc_media_hydration_bad_zip_extension', __('Media hydration packages must be ZIP files.', 'dbvc'), ['status' => 400]);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zip_path) !== true) {
            return new \WP_Error('dbvc_media_hydration_zip_open_failed', __('Unable to open media hydration package ZIP.', 'dbvc'), ['status' => 400]);
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                return new \WP_Error('dbvc_media_hydration_zip_too_many_files', __('Media hydration package contains too many files.', 'dbvc'), ['status' => 400]);
            }

            $manifest_entry = self::find_manifest_entry($zip);
            if (is_wp_error($manifest_entry)) {
                return $manifest_entry;
            }

            $manifest_raw = $zip->getFromIndex((int) $manifest_entry['index']);
            if (! is_string($manifest_raw) || trim($manifest_raw) === '') {
                return new \WP_Error('dbvc_media_hydration_manifest_empty', __('Media hydration package manifest is empty.', 'dbvc'), ['status' => 400]);
            }

            $manifest = json_decode($manifest_raw, true);
            if (! is_array($manifest)) {
                return new \WP_Error('dbvc_media_hydration_manifest_invalid_json', __('Media hydration package manifest is not valid JSON.', 'dbvc'), ['status' => 400]);
            }

            $valid_manifest = self::validate_manifest($manifest);
            if (is_wp_error($valid_manifest)) {
                return $valid_manifest;
            }

            $package_id = self::sanitize_package_id($args['package_id'] ?? '', (string) ($args['source_name'] ?? basename($zip_path)));
            $overwrite = ! empty($args['overwrite']);
            $root = self::prepare_root();
            if (is_wp_error($root)) {
                return $root;
            }

            $stage_dir = trailingslashit($root) . $package_id . '.uploading-' . wp_generate_password(8, false, false);
            $final_dir = trailingslashit($root) . $package_id;
            if (is_dir($stage_dir)) {
                self::delete_directory($stage_dir);
            }

            if (! wp_mkdir_p($stage_dir)) {
                return new \WP_Error('dbvc_media_hydration_stage_failed', __('Unable to prepare media hydration package staging directory.', 'dbvc'), ['status' => 500]);
            }
            self::ensure_directory_security($stage_dir);

            $stats = self::extract_entries($zip, $manifest, (string) $manifest_entry['prefix'], $stage_dir);
            if (is_wp_error($stats)) {
                self::delete_directory($stage_dir);
                return $stats;
            }

            if (! is_file(trailingslashit($stage_dir) . MirrorManifestBuilder::MANIFEST_FILENAME)) {
                self::delete_directory($stage_dir);
                return new \WP_Error('dbvc_media_hydration_manifest_extract_missing', __('Media hydration manifest was not extracted.', 'dbvc'), ['status' => 400]);
            }

            if (is_dir($final_dir)) {
                if (! $overwrite) {
                    self::delete_directory($stage_dir);
                    return new \WP_Error('dbvc_media_hydration_package_exists', __('A media hydration package with this ID already exists.', 'dbvc'), ['status' => 409]);
                }
                self::delete_directory($final_dir);
            }

            if (! @rename($stage_dir, $final_dir)) {
                self::delete_directory($stage_dir);
                return new \WP_Error('dbvc_media_hydration_package_move_failed', __('Unable to store media hydration package.', 'dbvc'), ['status' => 500]);
            }

            self::ensure_directory_security($final_dir);
            if (is_dir(trailingslashit($final_dir) . 'media')) {
                self::ensure_directory_security(trailingslashit($final_dir) . 'media');
            }

            return [
                'package_id' => $package_id,
                'package_dir' => wp_normalize_path($final_dir),
                'manifest_path' => wp_normalize_path(trailingslashit($final_dir) . MirrorManifestBuilder::MANIFEST_FILENAME),
                'manifest' => $manifest,
                'stats' => $stats,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * @return string|\WP_Error
     */
    private static function prepare_root()
    {
        $root = function_exists('dbvc_get_sync_path')
            ? trailingslashit(dbvc_get_sync_path('media-mirrors'))
            : trailingslashit(WP_CONTENT_DIR) . 'uploads/dbvc-media-mirrors/';

        if (! is_dir($root)) {
            wp_mkdir_p($root);
        }

        if (! is_dir($root) || ! wp_is_writable($root)) {
            return new \WP_Error('dbvc_media_hydration_root_unwritable', __('Media hydration package directory is not writable.', 'dbvc'), ['status' => 500]);
        }

        self::ensure_directory_security($root);

        return wp_normalize_path(rtrim($root, '/\\'));
    }

    /**
     * @param \ZipArchive $zip
     * @return array{index:int,name:string,prefix:string}|\WP_Error
     */
    private static function find_manifest_entry(\ZipArchive $zip)
    {
        $matches = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $raw_name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
            $name = self::normalize_zip_entry_name($raw_name);
            if ($name === '') {
                return new \WP_Error('dbvc_media_hydration_bad_zip_entry', __('Media hydration package contains an unsafe ZIP entry path.', 'dbvc'), ['status' => 400]);
            }
            if (self::is_directory_entry($raw_name) || self::is_ignored_entry($name)) {
                continue;
            }

            if (basename($name) === MirrorManifestBuilder::MANIFEST_FILENAME) {
                $prefix = dirname($name);
                $matches[] = [
                    'index' => $index,
                    'name' => $name,
                    'prefix' => $prefix === '.' ? '' : trim($prefix, '/'),
                ];
            }
        }

        if (count($matches) !== 1) {
            return new \WP_Error('dbvc_media_hydration_manifest_missing', __('Media hydration package must contain exactly one DBVC media mirror manifest.', 'dbvc'), ['status' => 400]);
        }

        return $matches[0];
    }

    /**
     * @param array<string,mixed> $manifest
     * @return true|\WP_Error
     */
    private static function validate_manifest(array $manifest)
    {
        if ((string) ($manifest['kind'] ?? '') !== 'dbvc_media_mirror') {
            return new \WP_Error('dbvc_media_hydration_wrong_manifest_kind', __('Uploaded ZIP is not a DBVC media mirror package.', 'dbvc'), ['status' => 400]);
        }

        if ((int) ($manifest['schema'] ?? 0) !== 1) {
            return new \WP_Error('dbvc_media_hydration_unsupported_manifest_schema', __('Unsupported media mirror manifest schema.', 'dbvc'), ['status' => 400]);
        }

        if (empty($manifest['attachments']) || ! is_array($manifest['attachments'])) {
            return new \WP_Error('dbvc_media_hydration_manifest_empty_attachments', __('Media mirror manifest does not contain attachment descriptors.', 'dbvc'), ['status' => 400]);
        }

        $checksum = isset($manifest['checksum']) ? (string) $manifest['checksum'] : '';
        if ($checksum === '' || ! hash_equals($checksum, self::manifest_checksum($manifest))) {
            return new \WP_Error('dbvc_media_hydration_manifest_checksum_mismatch', __('Media mirror manifest checksum does not match package contents.', 'dbvc'), ['status' => 400]);
        }

        return true;
    }

    /**
     * @param \ZipArchive $zip
     * @param array<string,mixed> $manifest
     * @param string $prefix
     * @param string $stage_dir
     * @return array<string,int>|\WP_Error
     */
    private static function extract_entries(\ZipArchive $zip, array $manifest, string $prefix, string $stage_dir)
    {
        $allowed_media = self::allowed_media_entries($manifest);
        $prefix = trim($prefix, '/');
        $prefixed_root = $prefix === '' ? '' : $prefix . '/';
        $manifest_entry = $prefixed_root . MirrorManifestBuilder::MANIFEST_FILENAME;
        $media_prefix = $prefixed_root . 'media/';
        $max_bytes = self::max_uncompressed_bytes();
        $seen_targets = [];
        $stats = [
            'entries' => 0,
            'media_files' => 0,
            'bytes' => 0,
        ];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $raw_name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
            $name = self::normalize_zip_entry_name($raw_name);
            if ($name === '' || self::is_directory_entry($raw_name) || self::is_ignored_entry($name)) {
                continue;
            }

            if (self::is_symlink_entry($zip, $index)) {
                return new \WP_Error('dbvc_media_hydration_zip_symlink', __('Media hydration packages cannot contain symlink entries.', 'dbvc'), ['status' => 400]);
            }

            if ($name === $manifest_entry) {
                $target_relative = MirrorManifestBuilder::MANIFEST_FILENAME;
            } elseif (strpos($name, $media_prefix) === 0) {
                $target_relative = substr($name, strlen($prefixed_root));
                if (! isset($allowed_media[$target_relative])) {
                    return new \WP_Error('dbvc_media_hydration_unexpected_media_file', __('Media hydration package contains a media file that is not listed in the manifest.', 'dbvc'), ['status' => 400]);
                }
                $stats['media_files']++;
            } else {
                return new \WP_Error('dbvc_media_hydration_unexpected_zip_entry', __('Media hydration package contains unexpected files.', 'dbvc'), ['status' => 400]);
            }

            if (isset($seen_targets[$target_relative])) {
                return new \WP_Error('dbvc_media_hydration_duplicate_zip_entry', __('Media hydration package contains duplicate file entries.', 'dbvc'), ['status' => 400]);
            }
            $seen_targets[$target_relative] = true;

            $size = isset($stat['size']) ? (int) $stat['size'] : 0;
            if ($size < 0 || $stats['bytes'] + $size > $max_bytes) {
                return new \WP_Error('dbvc_media_hydration_zip_too_large', __('Media hydration package exceeds the maximum extracted size.', 'dbvc'), ['status' => 400]);
            }

            $copy = self::copy_zip_entry($zip, $index, $stage_dir, $target_relative);
            if (is_wp_error($copy)) {
                return $copy;
            }

            $stats['entries']++;
            $stats['bytes'] += $copy;
        }

        return $stats;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,bool>
     */
    private static function allowed_media_entries(array $manifest): array
    {
        $allowed = [];
        foreach ((array) ($manifest['attachments'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $relative = FileStateService::normalize_relative_path((string) ($entry['relative_path'] ?? ''));
            if ($relative !== '') {
                $allowed['media/' . $relative] = true;
            }
        }

        return $allowed;
    }

    /**
     * @param \ZipArchive $zip
     * @return int|\WP_Error
     */
    private static function copy_zip_entry(\ZipArchive $zip, int $index, string $stage_dir, string $target_relative)
    {
        $target_relative = self::normalize_zip_entry_name($target_relative);
        if ($target_relative === '' || ! self::is_allowed_target_relative($target_relative)) {
            return new \WP_Error('dbvc_media_hydration_bad_target_path', __('Media hydration package contains an unsafe target path.', 'dbvc'), ['status' => 400]);
        }

        $target = trailingslashit($stage_dir) . $target_relative;
        if (! self::target_is_inside($target, $stage_dir)) {
            return new \WP_Error('dbvc_media_hydration_target_escape', __('Media hydration package target escapes the staging directory.', 'dbvc'), ['status' => 400]);
        }

        $dir = dirname($target);
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $zip_name = $zip->getNameIndex($index);
        if (! is_string($zip_name) || $zip_name === '') {
            return new \WP_Error('dbvc_media_hydration_zip_read_failed', __('Unable to read a media hydration package entry.', 'dbvc'), ['status' => 400]);
        }

        $stream = $zip->getStream($zip_name);
        if (! is_resource($stream)) {
            return new \WP_Error('dbvc_media_hydration_zip_read_failed', __('Unable to read a media hydration package entry.', 'dbvc'), ['status' => 400]);
        }

        $out = @fopen($target, 'wb');
        if (! is_resource($out)) {
            fclose($stream);
            return new \WP_Error('dbvc_media_hydration_entry_write_failed', __('Unable to write a media hydration package entry.', 'dbvc'), ['status' => 500]);
        }

        $bytes = 0;
        while (! feof($stream)) {
            $chunk = fread($stream, 1048576);
            if ($chunk === false) {
                fclose($stream);
                fclose($out);
                return new \WP_Error('dbvc_media_hydration_zip_read_failed', __('Unable to read a media hydration package entry.', 'dbvc'), ['status' => 400]);
            }
            if ($chunk === '') {
                continue;
            }
            $written = fwrite($out, $chunk);
            if ($written === false) {
                fclose($stream);
                fclose($out);
                return new \WP_Error('dbvc_media_hydration_entry_write_failed', __('Unable to write a media hydration package entry.', 'dbvc'), ['status' => 500]);
            }
            $bytes += (int) $written;
        }

        fclose($stream);
        fclose($out);

        return $bytes;
    }

    private static function is_allowed_target_relative(string $relative): bool
    {
        return $relative === MirrorManifestBuilder::MANIFEST_FILENAME || strpos($relative, 'media/') === 0;
    }

    private static function normalize_zip_entry_name(string $name): string
    {
        if ($name === '' || strpos($name, chr(0)) !== false) {
            return '';
        }

        $name = str_replace('\\', '/', $name);
        if ($name === '' || $name[0] === '/' || preg_match('/^[A-Za-z]:\//', $name)) {
            return '';
        }

        $name = trim($name);
        $name = rtrim($name, '/');
        if ($name === '') {
            return '';
        }

        $segments = explode('/', $name);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return '';
            }
        }

        return implode('/', $segments);
    }

    private static function is_directory_entry(string $raw_name): bool
    {
        return $raw_name !== '' && substr(str_replace('\\', '/', $raw_name), -1) === '/';
    }

    private static function is_ignored_entry(string $name): bool
    {
        $basename = basename($name);
        return $basename === '.DS_Store'
            || $basename === 'index.php'
            || $basename === '.htaccess'
            || strpos($name, '__MACOSX/') === 0
            || strpos($name, '/__MACOSX/') !== false;
    }

    private static function is_symlink_entry(\ZipArchive $zip, int $index): bool
    {
        if (! method_exists($zip, 'getExternalAttributesIndex')) {
            return false;
        }

        $opsys = 0;
        $attr = 0;
        if (! $zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        return defined('ZipArchive::OPSYS_UNIX')
            && $opsys === \ZipArchive::OPSYS_UNIX
            && (($attr >> 16) & 0170000) === 0120000;
    }

    private static function target_is_inside(string $target, string $base): bool
    {
        $target_dir = dirname($target);
        if (! is_dir($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        $real_dir = realpath($target_dir);
        $real_base = realpath($base);
        if (! is_string($real_dir) || ! is_string($real_base)) {
            return false;
        }

        return strpos(wp_normalize_path($real_dir) . '/', rtrim(wp_normalize_path($real_base), '/') . '/') === 0;
    }

    /**
     * @param mixed $package_id
     */
    private static function sanitize_package_id($package_id, string $source_name): string
    {
        $package_id = sanitize_file_name((string) $package_id);
        if ($package_id === '') {
            $package_id = sanitize_file_name(pathinfo($source_name, PATHINFO_FILENAME));
        }
        if ($package_id === '') {
            $package_id = 'media-mirror-' . gmdate('Ymd-His') . '-' . wp_generate_password(8, false, false);
        }

        return $package_id;
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private static function manifest_checksum(array $manifest): string
    {
        $copy = $manifest;
        unset($copy['checksum']);
        return 'sha256:' . hash('sha256', (string) wp_json_encode($copy, JSON_UNESCAPED_SLASHES));
    }

    private static function max_uncompressed_bytes(): int
    {
        $upload_max = function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : 0;
        $default = max(2147483648, $upload_max * 20);

        return max(1, (int) apply_filters('dbvc_media_hydration_package_max_uncompressed_bytes', $default));
    }

    private static function ensure_directory_security(string $path): void
    {
        if (class_exists('\DBVC_Sync_Posts') && method_exists('\DBVC_Sync_Posts', 'ensure_directory_security')) {
            \DBVC_Sync_Posts::ensure_directory_security($path);
            return;
        }

        $index = trailingslashit($path) . 'index.php';
        if (! file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }

    private static function delete_directory(string $path): void
    {
        $path = rtrim($path, '/\\');
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($path);
    }
}
