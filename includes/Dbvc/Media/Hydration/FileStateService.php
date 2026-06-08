<?php

namespace Dbvc\Media\Hydration;

if (! defined('WPINC')) {
    die;
}

/**
 * Read-only attachment file state inspection for media hydration planning.
 */
final class FileStateService
{
    /**
     * Inspect the local file state for an attachment without mutating it.
     *
     * @param int   $attachment_id
     * @param array $args {
     *   @type bool $compute_hash       Whether to hash the existing file. Default false.
     *   @type bool $check_derivatives  Whether to check generated image size files. Default false.
     * }
     * @return array<string,mixed>
     */
    public static function inspect(int $attachment_id, array $args = []): array
    {
        $attachment_id = absint($attachment_id);
        $compute_hash = ! empty($args['compute_hash']);
        $check_derivatives = ! empty($args['check_derivatives']);

        $base = self::uploads_base();
        $attached_meta = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        $path = self::resolve_attachment_path($attached_meta, $base);

        $state = [
            'attachment_id' => $attachment_id,
            'relative_path' => $path['relative_path'],
            'absolute_path' => $path['absolute_path'],
            'safe_path' => $path['safe'],
            'status' => 'missing_attached_file_meta',
            'exists' => false,
            'readable' => false,
            'is_symlink' => false,
            'file_size' => null,
            'file_hash' => '',
            'mime_type' => '',
            'metadata' => self::inspect_metadata($attachment_id, $path['relative_path'], $check_derivatives, $base),
            'reason' => $path['reason'],
        ];

        if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
            $state['status'] = 'invalid_attachment';
            $state['reason'] = 'not_attachment';
            return $state;
        }

        if ($attached_meta === '') {
            return $state;
        }

        if (! $path['safe']) {
            $state['status'] = 'unsafe_path';
            return $state;
        }

        $absolute = $path['absolute_path'];
        if ($absolute === '' || ! file_exists($absolute)) {
            $state['status'] = 'missing';
            $state['reason'] = 'file_not_found';
            return $state;
        }

        if (is_link($absolute)) {
            $state['status'] = 'unsafe_path';
            $state['is_symlink'] = true;
            $state['reason'] = 'symlink_target';
            return $state;
        }

        $state['exists'] = true;
        $state['readable'] = is_readable($absolute);
        $state['file_size'] = filesize($absolute);
        $filetype = wp_check_filetype($absolute);
        $state['mime_type'] = isset($filetype['type']) ? (string) $filetype['type'] : '';

        if (! $state['readable']) {
            $state['status'] = 'unreadable';
            $state['reason'] = 'file_unreadable';
            return $state;
        }

        if ($compute_hash) {
            $hash = hash_file('sha256', $absolute);
            $state['file_hash'] = is_string($hash) ? 'sha256:' . $hash : '';
        }

        $state['status'] = 'exists';
        $state['reason'] = '';
        return $state;
    }

    /**
     * Resolve an attached-file meta value into a safe uploads-relative path.
     *
     * @param string     $attached_file
     * @param array|null $uploads
     * @return array{relative_path:string,absolute_path:string,safe:bool,reason:string}
     */
    public static function resolve_attachment_path(string $attached_file, ?array $uploads = null): array
    {
        $uploads = $uploads ?: self::uploads_base();
        $base_dir = isset($uploads['basedir']) ? wp_normalize_path((string) $uploads['basedir']) : '';
        $attached_file = trim(wp_normalize_path($attached_file));

        if ($attached_file === '') {
            return [
                'relative_path' => '',
                'absolute_path' => '',
                'safe' => false,
                'reason' => 'empty_attached_file',
            ];
        }

        $relative = $attached_file;
        if (self::is_absolute_path($attached_file)) {
            if ($base_dir === '' || ! self::path_starts_with($attached_file, $base_dir)) {
                return [
                    'relative_path' => '',
                    'absolute_path' => $attached_file,
                    'safe' => false,
                    'reason' => 'outside_uploads',
                ];
            }

            $relative = ltrim(substr($attached_file, strlen(rtrim($base_dir, '/'))), '/');
        }

        $relative = self::normalize_relative_path($relative);
        if ($relative === '') {
            return [
                'relative_path' => '',
                'absolute_path' => '',
                'safe' => false,
                'reason' => 'invalid_relative_path',
            ];
        }

        $absolute = $base_dir !== '' ? trailingslashit($base_dir) . $relative : '';
        return [
            'relative_path' => $relative,
            'absolute_path' => $absolute,
            'safe' => $absolute !== '',
            'reason' => '',
        ];
    }

    /**
     * Normalize a relative upload path and reject traversal.
     *
     * @param string $path
     * @return string
     */
    public static function normalize_relative_path(string $path): string
    {
        $path = ltrim(wp_normalize_path($path), '/');
        if ($path === '') {
            return '';
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return '';
            }
            $segments[] = sanitize_file_name($segment);
        }

        return implode('/', array_filter($segments, static function ($segment) {
            return $segment !== '';
        }));
    }

    /**
     * Return a coarse MIME group for filtering/reporting.
     *
     * @param string $mime_type
     * @return string
     */
    public static function mime_group(string $mime_type): string
    {
        $mime_type = strtolower(trim($mime_type));
        if ($mime_type === '') {
            return 'other';
        }

        if (strpos($mime_type, 'image/') === 0) {
            return 'image';
        }
        if (strpos($mime_type, 'video/') === 0) {
            return 'video';
        }
        if (strpos($mime_type, 'audio/') === 0) {
            return 'audio';
        }
        if (strpos($mime_type, 'font/') === 0 || in_array($mime_type, ['application/font-woff', 'application/font-woff2', 'application/vnd.ms-fontobject'], true)) {
            return 'font';
        }
        if (strpos($mime_type, 'application/pdf') === 0 || strpos($mime_type, 'text/') === 0 || strpos($mime_type, 'application/msword') === 0 || strpos($mime_type, 'application/vnd.openxmlformats-officedocument') === 0) {
            return 'document';
        }

        return 'other';
    }

    /**
     * Inspect metadata shape without regenerating it.
     *
     * @param int    $attachment_id
     * @param string $relative_path
     * @param bool   $check_derivatives
     * @param array  $uploads
     * @return array<string,mixed>
     */
    private static function inspect_metadata(int $attachment_id, string $relative_path, bool $check_derivatives, array $uploads): array
    {
        $metadata = wp_get_attachment_metadata($attachment_id);
        $result = [
            'status' => 'missing',
            'has_metadata' => false,
            'file' => '',
            'file_matches_attached' => null,
            'width' => null,
            'height' => null,
            'sizes_count' => 0,
            'missing_sizes' => [],
        ];

        if (! is_array($metadata) || empty($metadata)) {
            return $result;
        }

        $file = isset($metadata['file']) ? self::normalize_relative_path((string) $metadata['file']) : '';
        $result['status'] = 'present';
        $result['has_metadata'] = true;
        $result['file'] = $file;
        $result['file_matches_attached'] = ($file !== '' && $relative_path !== '') ? ($file === $relative_path) : null;
        $result['width'] = isset($metadata['width']) ? (int) $metadata['width'] : null;
        $result['height'] = isset($metadata['height']) ? (int) $metadata['height'] : null;
        $result['sizes_count'] = isset($metadata['sizes']) && is_array($metadata['sizes']) ? count($metadata['sizes']) : 0;

        if ($check_derivatives && ! empty($metadata['sizes']) && is_array($metadata['sizes']) && ! empty($uploads['basedir'])) {
            $base_dir = wp_normalize_path((string) $uploads['basedir']);
            $base_subdir = $file !== '' ? trailingslashit(dirname($file)) : '';
            if ($base_subdir === './') {
                $base_subdir = '';
            }

            foreach ($metadata['sizes'] as $size_name => $size_data) {
                $filename = is_array($size_data) && ! empty($size_data['file'])
                    ? sanitize_file_name((string) $size_data['file'])
                    : '';
                if ($filename === '') {
                    continue;
                }

                $absolute = trailingslashit($base_dir) . $base_subdir . $filename;
                if (! file_exists($absolute)) {
                    $result['missing_sizes'][] = (string) $size_name;
                }
            }
        }

        if ($result['file_matches_attached'] === false) {
            $result['status'] = 'stale';
        } elseif (! empty($result['missing_sizes'])) {
            $result['status'] = 'missing_derivatives';
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private static function uploads_base(): array
    {
        $uploads = wp_get_upload_dir();
        return is_array($uploads) ? $uploads : [];
    }

    /**
     * @param string $path
     * @return bool
     */
    private static function is_absolute_path(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || preg_match('/^[A-Za-z]:\//', $path) === 1);
    }

    /**
     * @param string $path
     * @param string $base
     * @return bool
     */
    private static function path_starts_with(string $path, string $base): bool
    {
        $path = rtrim(wp_normalize_path($path), '/') . '/';
        $base = rtrim(wp_normalize_path($base), '/') . '/';
        return strpos($path, $base) === 0;
    }
}
