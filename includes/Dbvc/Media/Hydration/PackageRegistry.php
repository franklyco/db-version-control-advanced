<?php

namespace Dbvc\Media\Hydration;

if (! defined('WPINC')) {
    die;
}

/**
 * Read-only discovery of staged media hydration packages.
 */
final class PackageRegistry
{
    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function list_packages(array $args = []): array
    {
        $limit = isset($args['limit']) ? absint($args['limit']) : 50;
        if ($limit <= 0) {
            $limit = 50;
        }
        $limit = min($limit, 200);

        $root = self::media_mirror_root();
        if (is_wp_error($root) || ! is_dir($root)) {
            return [
                'packages' => [],
                'summary' => [
                    'count' => 0,
                    'root_available' => false,
                ],
            ];
        }

        $packages = [];
        $entries = scandir($root);
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $package_dir = trailingslashit($root) . $entry;
            if (! is_dir($package_dir)) {
                continue;
            }

            $package = self::read_package($package_dir, $root);
            if ($package !== null) {
                $packages[] = $package;
            }
        }

        usort($packages, static function (array $a, array $b): int {
            return (int) ($b['modified_unix'] ?? 0) <=> (int) ($a['modified_unix'] ?? 0);
        });

        $packages = array_slice($packages, 0, $limit);

        return [
            'packages' => $packages,
            'summary' => [
                'count' => count($packages),
                'root_available' => true,
                'root' => $root,
            ],
        ];
    }

    /**
     * @param string $package_dir
     * @param string $root
     * @return array<string,mixed>|null
     */
    private static function read_package(string $package_dir, string $root): ?array
    {
        $real = realpath($package_dir);
        if (! is_string($real) || ! self::path_starts_with($real, $root)) {
            return null;
        }

        $manifest_path = trailingslashit($real) . MirrorManifestBuilder::MANIFEST_FILENAME;
        if (! is_file($manifest_path) || ! is_readable($manifest_path)) {
            return null;
        }

        $raw = file_get_contents($manifest_path);
        $manifest = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($manifest) || (string) ($manifest['kind'] ?? '') !== 'dbvc_media_mirror') {
            return null;
        }

        $package_id = basename($real);
        $zip_path = trailingslashit($root) . $package_id . '.zip';
        $zip_real = is_file($zip_path) ? realpath($zip_path) : false;
        $package_meta = isset($manifest['package']) && is_array($manifest['package']) ? $manifest['package'] : [];
        $summary = isset($manifest['summary']) && is_array($manifest['summary']) ? $manifest['summary'] : [];
        $source_site = isset($manifest['source_site']) && is_array($manifest['source_site']) ? $manifest['source_site'] : [];

        return [
            'package_id' => $package_id,
            'package_dir' => wp_normalize_path($real),
            'manifest_path' => wp_normalize_path($manifest_path),
            'manifest_checksum' => (string) ($manifest['checksum'] ?? ''),
            'generated_at' => (string) ($manifest['generated_at'] ?? ''),
            'modified_at' => gmdate('c', (int) filemtime($manifest_path)),
            'modified_unix' => (int) filemtime($manifest_path),
            'source_site' => [
                'home_url' => (string) ($source_site['home_url'] ?? ''),
                'site_url' => (string) ($source_site['site_url'] ?? ''),
                'uploads_baseurl' => (string) ($source_site['uploads_baseurl'] ?? ''),
            ],
            'summary' => [
                'attachments' => (int) ($summary['attachments'] ?? count((array) ($manifest['attachments'] ?? []))),
                'existing_files' => (int) ($summary['existing_files'] ?? 0),
                'missing_files' => (int) ($summary['missing_files'] ?? 0),
                'metadata_missing' => (int) ($summary['metadata_missing'] ?? 0),
                'metadata_stale' => (int) ($summary['metadata_stale'] ?? 0),
            ],
            'package' => [
                'files_included' => ! empty($package_meta['files_included']),
                'media_root' => (string) ($package_meta['media_root'] ?? 'media'),
            ],
            'zip' => [
                'exists' => is_string($zip_real) && self::path_starts_with($zip_real, $root),
                'path' => is_string($zip_real) ? wp_normalize_path($zip_real) : '',
                'download_url' => is_string($zip_real) && class_exists(__NAMESPACE__ . '\PackageDownloadController')
                    ? PackageDownloadController::download_url_for_package($package_id)
                    : '',
            ],
        ];
    }

    /**
     * @return string|\WP_Error
     */
    private static function media_mirror_root()
    {
        $root = function_exists('dbvc_get_sync_path')
            ? dbvc_get_sync_path('media-mirrors')
            : trailingslashit(WP_CONTENT_DIR) . 'uploads/dbvc-media-mirrors';

        if (! is_dir($root)) {
            return new \WP_Error('dbvc_media_hydration_root_missing', __('Media hydration package directory does not exist.', 'dbvc'));
        }

        $real = realpath($root);
        return is_string($real) && $real !== ''
            ? wp_normalize_path($real)
            : new \WP_Error('dbvc_media_hydration_root_missing', __('Media hydration package directory does not exist.', 'dbvc'));
    }

    private static function path_starts_with(string $path, string $base): bool
    {
        $path = rtrim(wp_normalize_path($path), '/') . '/';
        $base = rtrim(wp_normalize_path($base), '/') . '/';
        return strpos($path, $base) === 0;
    }
}
