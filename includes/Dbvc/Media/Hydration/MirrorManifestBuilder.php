<?php

namespace Dbvc\Media\Hydration;

if (! defined('WPINC')) {
    die;
}

/**
 * Builds full-library media mirror manifests and optional file packages.
 */
final class MirrorManifestBuilder
{
    public const MANIFEST_FILENAME = 'dbvc-media-mirror.json';
    private const KIND = 'dbvc_media_mirror';
    private const SCHEMA = 1;
    private const DEFAULT_BATCH_SIZE = 100;
    private const MAX_BATCH_SIZE = 500;

    /**
     * Build a full media mirror manifest in memory.
     *
     * @param array $args {
     *   @type int      $batch_size         Inventory page size.
     *   @type int[]    $attachment_ids     Optional explicit attachment IDs.
     *   @type string[] $mime_groups        Optional MIME group filter.
     *   @type bool     $include_hashes     Hash existing files. Default true.
     *   @type bool     $check_derivatives  Check generated derivative files. Default false.
     * }
     * @return array<string,mixed>
     */
    public static function build_manifest(array $args = []): array
    {
        $batch_size = self::normalize_batch_size($args['batch_size'] ?? self::DEFAULT_BATCH_SIZE);
        $offset = 0;
        $attachments = [];
        $summary = self::empty_summary();

        do {
            $page = LibraryInventoryService::query([
                'limit' => $batch_size,
                'offset' => $offset,
                'attachment_ids' => $args['attachment_ids'] ?? [],
                'mime_groups' => $args['mime_groups'] ?? [],
                'include_file_state' => true,
                'compute_hash' => array_key_exists('include_hashes', $args) ? (bool) $args['include_hashes'] : true,
                'check_derivatives' => ! empty($args['check_derivatives']),
            ]);

            $items = isset($page['items']) && is_array($page['items']) ? $page['items'] : [];
            foreach ($items as $item) {
                $entry = self::build_manifest_entry($item);
                $attachments[] = $entry;
                self::accumulate_summary($summary, $entry);
            }

            $pagination = isset($page['pagination']) && is_array($page['pagination']) ? $page['pagination'] : [];
            $offset += $batch_size;
            $has_more = ! empty($pagination['has_more']);
        } while ($has_more);

        $uploads = wp_get_upload_dir();
        $manifest = [
            'schema' => self::SCHEMA,
            'kind' => self::KIND,
            'generated_at' => gmdate('c'),
            'source_site' => [
                'home_url' => home_url(),
                'site_url' => site_url(),
                'uploads_baseurl' => isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '',
            ],
            'scope' => [
                'mode' => empty($args['attachment_ids']) ? 'all_registered_attachments' : 'selected_attachments',
                'include_unattached' => true,
                'mime_groups' => array_values(array_unique(array_filter(array_map('strval', (array) ($args['mime_groups'] ?? []))))),
            ],
            'summary' => $summary,
            'attachments' => $attachments,
        ];

        $manifest['checksum'] = self::manifest_checksum($manifest);

        return $manifest;
    }

    /**
     * Build a package directory and optional ZIP file.
     *
     * @param array $args {
     *   @type string $package_id       Optional package slug.
     *   @type bool   $include_files    Copy original files under media/.
     *   @type bool   $create_zip       Create a ZIP archive. Default false.
     *   @type string $zip_path         Optional ZIP destination.
     *   @type int    $batch_size       Inventory page size.
     * }
     * @return array<string,mixed>|\WP_Error
     */
    public static function build_package(array $args = [])
    {
        $package_id = self::sanitize_package_id($args['package_id'] ?? '');
        $package_dir = self::prepare_package_dir($package_id);
        if (is_wp_error($package_dir)) {
            return $package_dir;
        }

        $manifest = self::build_manifest($args);
        $copy_stats = [
            'copied' => 0,
            'missing' => 0,
            'unsafe' => 0,
            'errors' => 0,
            'bytes' => 0,
        ];

        if (! empty($args['include_files'])) {
            $copy_stats = self::copy_manifest_files($manifest, $package_dir);
            $manifest['package'] = [
                'files_included' => true,
                'media_root' => 'media',
                'copy_stats' => $copy_stats,
            ];
            $manifest['checksum'] = self::manifest_checksum($manifest);
        } else {
            $manifest['package'] = [
                'files_included' => false,
                'media_root' => 'media',
                'copy_stats' => $copy_stats,
            ];
            $manifest['checksum'] = self::manifest_checksum($manifest);
        }

        $manifest_path = trailingslashit($package_dir) . self::MANIFEST_FILENAME;
        $written = file_put_contents($manifest_path, wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($written === false) {
            return new \WP_Error('dbvc_media_mirror_manifest_write_failed', __('Unable to write media mirror manifest.', 'dbvc'));
        }

        $zip_path = '';
        if (! empty($args['create_zip'])) {
            $zip = self::create_zip($package_dir, isset($args['zip_path']) ? (string) $args['zip_path'] : '');
            if (is_wp_error($zip)) {
                return $zip;
            }
            $zip_path = $zip;
        }

        return [
            'package_id' => basename($package_dir),
            'package_dir' => $package_dir,
            'manifest_path' => $manifest_path,
            'zip_path' => $zip_path,
            'manifest' => $manifest,
            'copy_stats' => $copy_stats,
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private static function build_manifest_entry(array $item): array
    {
        $file_state = isset($item['file_state']) && is_array($item['file_state']) ? $item['file_state'] : [];
        $metadata = isset($file_state['metadata']) && is_array($file_state['metadata']) ? $file_state['metadata'] : [];
        $file_hash = isset($file_state['file_hash']) && is_string($file_state['file_hash']) && $file_state['file_hash'] !== ''
            ? $file_state['file_hash']
            : (string) ($item['file_hash_meta'] ?? '');

        return [
            'source_id' => (int) ($item['attachment_id'] ?? 0),
            'asset_uid' => (string) ($item['asset_uid'] ?? ''),
            'relative_path' => (string) ($item['relative_path'] ?? ''),
            'source_url' => (string) ($item['source_url'] ?? ''),
            'filename' => basename((string) ($item['relative_path'] ?? '')),
            'mime_type' => (string) ($item['mime_type'] ?? ''),
            'mime_group' => (string) ($item['mime_group'] ?? 'other'),
            'file_hash' => $file_hash,
            'file_size' => isset($file_state['file_size']) ? (int) $file_state['file_size'] : 0,
            'file_status' => (string) ($file_state['status'] ?? 'unknown'),
            'metadata' => [
                'status' => (string) ($metadata['status'] ?? 'missing'),
                'file' => (string) ($metadata['file'] ?? ''),
                'width' => isset($metadata['width']) ? $metadata['width'] : null,
                'height' => isset($metadata['height']) ? $metadata['height'] : null,
                'sizes_count' => (int) ($metadata['sizes_count'] ?? 0),
                'missing_sizes' => isset($metadata['missing_sizes']) && is_array($metadata['missing_sizes']) ? array_values($metadata['missing_sizes']) : [],
            ],
            'dbvc' => [
                'vf_asset_uid' => (string) ($item['asset_uid'] ?? ''),
                'vf_file_hash' => (string) ($item['file_hash_meta'] ?? ''),
                'original_attachment_id' => (int) ($item['original_attachment_id'] ?? 0),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function empty_summary(): array
    {
        return [
            'attachments' => 0,
            'existing_files' => 0,
            'missing_files' => 0,
            'unsafe_paths' => 0,
            'unreadable_files' => 0,
            'metadata_missing' => 0,
            'metadata_stale' => 0,
            'mime_groups' => [],
        ];
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $entry
     * @return void
     */
    private static function accumulate_summary(array &$summary, array $entry): void
    {
        $summary['attachments']++;

        $status = (string) ($entry['file_status'] ?? 'unknown');
        if ($status === 'exists') {
            $summary['existing_files']++;
        } elseif ($status === 'missing' || $status === 'missing_attached_file_meta') {
            $summary['missing_files']++;
        } elseif ($status === 'unsafe_path') {
            $summary['unsafe_paths']++;
        } elseif ($status === 'unreadable') {
            $summary['unreadable_files']++;
        }

        $metadata_status = (string) ($entry['metadata']['status'] ?? '');
        if ($metadata_status === 'missing') {
            $summary['metadata_missing']++;
        } elseif (in_array($metadata_status, ['stale', 'missing_derivatives'], true)) {
            $summary['metadata_stale']++;
        }

        $group = (string) ($entry['mime_group'] ?? 'other');
        if (! isset($summary['mime_groups'][$group])) {
            $summary['mime_groups'][$group] = 0;
        }
        $summary['mime_groups'][$group]++;
        ksort($summary['mime_groups']);
    }

    /**
     * @param array<string,mixed> $manifest
     * @param string              $package_dir
     * @return array<string,int>
     */
    private static function copy_manifest_files(array $manifest, string $package_dir): array
    {
        $stats = [
            'copied' => 0,
            'missing' => 0,
            'unsafe' => 0,
            'errors' => 0,
            'bytes' => 0,
        ];

        $uploads = wp_get_upload_dir();
        $uploads_base = isset($uploads['basedir']) ? wp_normalize_path((string) $uploads['basedir']) : '';
        $media_dir = trailingslashit($package_dir) . 'media';
        wp_mkdir_p($media_dir);
        self::ensure_directory_security($media_dir);

        foreach ((array) ($manifest['attachments'] ?? []) as $entry) {
            $relative = FileStateService::normalize_relative_path((string) ($entry['relative_path'] ?? ''));
            if ($relative === '' || $uploads_base === '') {
                $stats['unsafe']++;
                continue;
            }

            $source = trailingslashit($uploads_base) . $relative;
            if (! file_exists($source)) {
                $stats['missing']++;
                continue;
            }

            if (! self::is_safe_existing_file($source, $uploads_base)) {
                $stats['unsafe']++;
                continue;
            }

            $target = trailingslashit($media_dir) . $relative;
            if (! self::is_target_inside($target, $media_dir)) {
                $stats['unsafe']++;
                continue;
            }

            $target_dir = dirname($target);
            if (! is_dir($target_dir)) {
                wp_mkdir_p($target_dir);
            }

            if (! @copy($source, $target)) {
                $stats['errors']++;
                continue;
            }

            $stats['copied']++;
            $size = filesize($target);
            if ($size) {
                $stats['bytes'] += (int) $size;
            }
        }

        return $stats;
    }

    /**
     * @param string $package_dir
     * @param string $requested_zip_path
     * @return string|\WP_Error
     */
    private static function create_zip(string $package_dir, string $requested_zip_path = '')
    {
        if (! class_exists('\ZipArchive')) {
            return new \WP_Error('dbvc_media_mirror_zip_missing', __('ZipArchive is required to create a media mirror package.', 'dbvc'));
        }

        $zip_path = $requested_zip_path !== ''
            ? self::normalize_zip_path($requested_zip_path)
            : trailingslashit(dirname($package_dir)) . basename($package_dir) . '.zip';

        if (is_wp_error($zip_path)) {
            return $zip_path;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return new \WP_Error('dbvc_media_mirror_zip_create_failed', __('Unable to create media mirror ZIP package.', 'dbvc'));
        }

        $base = rtrim(wp_normalize_path($package_dir), '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($package_dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->isLink()) {
                continue;
            }

            $path = wp_normalize_path($file->getPathname());
            $relative = ltrim(substr($path, strlen($base)), '/');
            $basename = basename($relative);
            if ($relative === '' || strpos($relative, '..') !== false || $basename === '.htaccess' || $basename === 'index.php') {
                continue;
            }

            $zip->addFile($path, $relative);
        }

        $zip->close();

        return $zip_path;
    }

    /**
     * @param string $zip_path
     * @return string|\WP_Error
     */
    private static function normalize_zip_path(string $zip_path)
    {
        if (strpos($zip_path, chr(0)) !== false) {
            return new \WP_Error('dbvc_media_mirror_bad_zip_path', __('Invalid ZIP path.', 'dbvc'));
        }

        $zip_path = wp_normalize_path($zip_path);
        if (strtolower(pathinfo($zip_path, PATHINFO_EXTENSION)) !== 'zip') {
            return new \WP_Error('dbvc_media_mirror_bad_zip_extension', __('Media mirror package path must end in .zip.', 'dbvc'));
        }

        $dir = dirname($zip_path);
        if (! is_dir($dir) || ! is_writable($dir)) {
            return new \WP_Error('dbvc_media_mirror_zip_dir_unwritable', __('Media mirror ZIP destination is not writable.', 'dbvc'));
        }

        return $zip_path;
    }

    /**
     * @param mixed $value
     * @return int
     */
    private static function normalize_batch_size($value): int
    {
        $batch_size = absint($value);
        if ($batch_size <= 0) {
            $batch_size = self::DEFAULT_BATCH_SIZE;
        }

        return min($batch_size, self::MAX_BATCH_SIZE);
    }

    /**
     * @param mixed $package_id
     * @return string
     */
    private static function sanitize_package_id($package_id): string
    {
        $package_id = sanitize_file_name((string) $package_id);
        if ($package_id === '') {
            $package_id = 'media-mirror-' . gmdate('Ymd-His') . '-' . wp_generate_uuid4();
        }

        return $package_id;
    }

    /**
     * @param string $package_id
     * @return string|\WP_Error
     */
    private static function prepare_package_dir(string $package_id)
    {
        $root = function_exists('dbvc_get_sync_path')
            ? trailingslashit(dbvc_get_sync_path('media-mirrors'))
            : trailingslashit(WP_CONTENT_DIR) . 'uploads/dbvc-media-mirrors/';

        if (! is_dir($root)) {
            wp_mkdir_p($root);
        }
        self::ensure_directory_security($root);

        $package_dir = trailingslashit($root) . $package_id;
        if (is_dir($package_dir)) {
            return new \WP_Error('dbvc_media_mirror_package_exists', __('A media mirror package with this ID already exists.', 'dbvc'));
        }

        if (! wp_mkdir_p($package_dir)) {
            return new \WP_Error('dbvc_media_mirror_package_dir_failed', __('Unable to create media mirror package directory.', 'dbvc'));
        }

        self::ensure_directory_security($package_dir);

        return $package_dir;
    }

    /**
     * @param string $path
     * @return void
     */
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

    /**
     * @param string $source
     * @param string $base
     * @return bool
     */
    private static function is_safe_existing_file(string $source, string $base): bool
    {
        if (is_link($source)) {
            return false;
        }

        $real_source = realpath($source);
        $real_base = realpath($base);
        if (! $real_source || ! $real_base) {
            return false;
        }

        return strpos(wp_normalize_path($real_source) . '/', rtrim(wp_normalize_path($real_base), '/') . '/') === 0;
    }

    /**
     * @param string $target
     * @param string $base
     * @return bool
     */
    private static function is_target_inside(string $target, string $base): bool
    {
        $target_dir = dirname($target);
        if (! is_dir($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        $real_dir = realpath($target_dir);
        $real_base = realpath($base);
        if (! $real_dir || ! $real_base) {
            return false;
        }

        return strpos(wp_normalize_path($real_dir) . '/', rtrim(wp_normalize_path($real_base), '/') . '/') === 0;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return string
     */
    private static function manifest_checksum(array $manifest): string
    {
        $copy = $manifest;
        unset($copy['checksum']);
        return 'sha256:' . hash('sha256', (string) wp_json_encode($copy, JSON_UNESCAPED_SLASHES));
    }
}
