<?php

namespace Dbvc\Media;

/**
 * Deterministic attachment resolver (placeholder scaffold).
 *
 * This class will eventually replace the legacy DBVC_Media_Sync lookup logic.
 * For Phase 1 we only define method signatures and data contracts so the UI,
 * REST layer, and import pipeline can start calling into a consistent API.
 */
final class Resolver
{
    /**
     * Resolve media entries from a proposal manifest against the local site.
     *
     * Expected descriptor shape (per media entry):
     * - asset_uid (string, UUIDv4)
     * - file_hash (string, sha256:hex)
     * - relative_path (string|null)
     * - filename (string|null)
     * - filesize (int|null)
     * - mime_type (string|null)
     * - dimensions (array|null)
     * - bundle_path (string|null)
     * - source_url (string|null)
     *
     * @param array $manifest  Parsed manifest array (must include media entries).
     * @param array $options   Resolver options (allow_remote, dry_run, etc).
     * @return array {
     *   @type array $attachments Map of asset_uid => resolution payload.
     *   @type array $id_map      Map of asset_uid => local attachment ID.
     *   @type array $conflicts   List of unresolved or ambiguous assets.
     *   @type array $metrics     Counters for analytics (reused, downloaded, etc).
     * }
     */
    public static function resolve_manifest(array $manifest, array $options = []): array
    {
        $allow_remote = ! empty($options['allow_remote']);

        $media_index = isset($manifest['media_index']) && is_array($manifest['media_index'])
            ? $manifest['media_index']
            : [];

        $attachments = [];
        $id_map      = [];
        $conflicts   = [];
        $metrics     = [
            'detected'    => 0,
            'reused'      => 0,
            'downloaded'  => 0,
            'unresolved'  => 0,
            'blocked'     => 0,
            'bundle_hits' => 0,
        ];

        foreach ($media_index as $entry) {
            $descriptor = self::normalize_descriptor($entry);
            $metrics['detected']++;

            $resolution = self::resolve_descriptor($descriptor, $options);

            if (! empty($resolution['bundle_hit'])) {
                $metrics['bundle_hits']++;
            }

            if (! empty($resolution['blocked_reason'])) {
                $metrics['blocked']++;
            }

            switch ($resolution['status']) {
                case 'reused':
                    $metrics['reused']++;
                    if ($resolution['target_id']) {
                        $id_map[$descriptor['asset_key']] = (int) $resolution['target_id'];
                    }
                    break;

                case 'needs_download':
                    if ($allow_remote) {
                        $metrics['downloaded']++;
                    } else {
                        $metrics['unresolved']++;
                    }
                    break;

                case 'conflict':
                    $metrics['unresolved']++;
                    $conflicts[] = $resolution;
                    break;

                default:
                    $metrics['unresolved']++;
                    break;
            }

            $attachments[$descriptor['asset_key']] = $resolution;
        }

        return [
            'attachments' => $attachments,
            'id_map'      => $id_map,
            'conflicts'   => $conflicts,
            'metrics'     => $metrics,
        ];
    }

    /**
     * Resolve a single media descriptor using UID → hash → path priority.
     *
     * @param array $descriptor Normalized descriptor.
     * @param array $options    Resolver options.
     * @return array Resolution payload.
     */
    public static function resolve_descriptor(array $descriptor, array $options = []): array
    {
        $result = [
            'status'        => 'unresolved',
            'resolved_via'  => null,
            'target_id'     => null,
            'reason'        => null,
            'bundle_hit'    => false,
            'blocked_reason'=> null,
            'candidates'    => [],
            'descriptor'    => $descriptor,
        ];

        $asset_uid = $descriptor['asset_uid'];
        $file_hash = $descriptor['file_hash'];
        $path      = $descriptor['path'];
        $filename  = $descriptor['filename'];
        $source    = $descriptor['source_url'];

        // 1) Match by asset UID.
        if ($asset_uid) {
            $candidates = self::query_attachment_by_meta('vf_asset_uid', $asset_uid);
            if (! empty($candidates)) {
                if (count($candidates) === 1) {
                    $result['status']       = 'reused';
                    $result['resolved_via'] = 'asset_uid';
                    $result['target_id']    = (int) $candidates[0];
                    $result['candidates']   = $candidates;
                    $result['bundle_hit']   = self::bundle_file_exists($descriptor, $options);
                    return $result;
                }

                $result['status']      = 'conflict';
                $result['reason']      = 'duplicate_asset_uid';
                $result['candidates']  = array_map('intval', $candidates);
                return $result;
            }
        }

        // 2) Match by file hash.
        if ($file_hash) {
            $hash_candidates = self::query_attachments_by_hash($file_hash);
            if (! empty($hash_candidates)) {
                if (count($hash_candidates) === 1) {
                    $result['status']       = 'reused';
                    $result['resolved_via'] = 'file_hash';
                    $result['target_id']    = (int) $hash_candidates[0];
                    $result['candidates']   = $hash_candidates;
                    $result['bundle_hit']   = self::bundle_file_exists($descriptor, $options);

                    if ($asset_uid && ! \get_post_meta($hash_candidates[0], 'vf_asset_uid', true)) {
                        \update_post_meta($hash_candidates[0], 'vf_asset_uid', $asset_uid);
                    }

                    return $result;
                }

                $result['status']      = 'conflict';
                $result['reason']      = 'duplicate_hash';
                $result['candidates']  = array_map('intval', $hash_candidates);
                return $result;
            }
        }

        // 3) Match by relative path / filename.
        if ($path) {
            $path_candidates = self::query_attachments_by_path($path);
            if (! empty($path_candidates)) {
                if (count($path_candidates) === 1) {
                    $target_id             = (int) $path_candidates[0];
                    $result['status']       = 'reused';
                    $result['resolved_via'] = 'relative_path';
                    $result['target_id']    = $target_id;
                    $result['candidates']   = $path_candidates;
                    $result['bundle_hit']   = self::bundle_file_exists($descriptor, $options);

                    if ($asset_uid) {
                        \update_post_meta($target_id, 'vf_asset_uid', $asset_uid);
                    }
                    if ($file_hash) {
                        \update_post_meta($target_id, 'vf_file_hash', $file_hash);
                    }

                    return $result;
                }

                $result['status']     = 'conflict';
                $result['reason']     = 'duplicate_path';
                $result['candidates'] = array_map('intval', $path_candidates);
                return $result;
            }
        }

        if ($filename) {
            $filename_candidates = self::query_attachments_by_filename($filename);
            if (! empty($filename_candidates)) {
                $result['status']     = 'conflict';
                $result['reason']     = 'ambiguous_filename';
                $result['candidates'] = array_map('intval', $filename_candidates);
                return $result;
            }
        }

        // Remote download required if allowed and we have a source URL.
        if (! empty($source)) {
            $result['status']       = 'needs_download';
            $result['resolved_via'] = 'remote';
            $result['reason']       = 'not_found_locally';
            return $result;
        }

        $result['status'] = 'missing';
        $result['reason'] = 'no_match';
        return $result;
    }

    /**
     * Normalize a raw manifest entry.
     *
     * @param array $entry
     * @return array
     */
    private static function normalize_descriptor(array $entry): array
    {
        $original_id = isset($entry['original_id']) ? (int) $entry['original_id'] : 0;
        $asset_uid   = isset($entry['asset_uid']) && $entry['asset_uid']
            ? (string) $entry['asset_uid']
            : ($original_id ? 'orig-' . $original_id : '');

        $raw_hash = $entry['file_hash'] ?? ($entry['hash'] ?? '');
        $raw_hash = is_string($raw_hash) ? trim($raw_hash) : '';

        $normalized_hash = $raw_hash;
        if ($raw_hash && strpos($raw_hash, ':') !== false) {
            [$algo, $hash_value] = explode(':', $raw_hash, 2);
            $normalized_hash      = trim($hash_value);
        }

        $bundle_path = isset($entry['bundle_path']) ? (string) $entry['bundle_path'] : '';
        if (! $bundle_path && ! empty($entry['relative_path'])) {
            $bundle_path = (string) $entry['relative_path'];
        }

        $relative_path = self::normalize_relative_path($bundle_path ?: ($entry['relative_path'] ?? ''));

        $descriptor = [
            'asset_key'    => $asset_uid ?: ($original_id ? 'orig-' . $original_id : \md5(\wp_json_encode($entry))),
            'asset_uid'    => $asset_uid,
            'original_id'  => $original_id,
            'file_hash'    => $normalized_hash,
            'raw_hash'     => $raw_hash,
            'bundle_path'  => $bundle_path,
            'path'         => $relative_path,
            'filename'     => isset($entry['filename']) ? \sanitize_file_name($entry['filename']) : '',
            'source_url'   => isset($entry['source_url']) ? \esc_url_raw($entry['source_url']) : '',
        ];

        return $descriptor;
    }

    /**
     * Normalize relative bundle path to _wp_attached_file format.
     *
     * @param string $path
     * @return string
     */
    private static function normalize_relative_path($path): string
    {
        $path = (string) $path;
        if ($path === '') {
            return '';
        }

        $path = \wp_normalize_path($path);
        $path = ltrim($path, '/');

        if (strpos($path, 'media/') === 0) {
            $path = \substr($path, \strlen('media/'));
        }

        return $path;
    }

    /**
     * Look for attachments by asset UID meta.
     *
     * @param string $meta_key
     * @param string $value
     * @return int[]
     */
    private static function query_attachment_by_meta($meta_key, $value): array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }

        $query = \get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => $meta_key,
                    'value' => $value,
                ],
            ],
        ]);

        return array_map('intval', $query);
    }

    /**
     * Find attachments sharing the given file hash.
     *
     * @param string $hash
     * @return int[]
     */
    private static function query_attachments_by_hash($hash): array
    {
        $hash = trim((string) $hash);
        if ($hash === '') {
            return [];
        }

        $candidates = self::query_attachment_by_meta('vf_file_hash', $hash);
        if (! empty($candidates)) {
            return $candidates;
        }

        // Attempt with prefixed format.
        $prefixed = \strpos($hash, 'sha256:') === 0 ? $hash : 'sha256:' . $hash;
        return self::query_attachment_by_meta('vf_file_hash', $prefixed);
    }

    /**
     * Query attachments by _wp_attached_file path.
     *
     * @param string $relative_path
     * @return int[]
     */
    private static function query_attachments_by_path($relative_path): array
    {
        $relative_path = trim((string) $relative_path);
        if ($relative_path === '') {
            return [];
        }

        return self::query_attachment_by_meta('_wp_attached_file', $relative_path);
    }

    /**
     * Query attachments by filename when other lookups fail.
     *
     * @param string $filename
     * @return int[]
     */
    private static function query_attachments_by_filename($filename): array
    {
        $filename = \sanitize_file_name($filename);
        if ($filename === '') {
            return [];
        }

        $query = \get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'title'          => $filename,
            's'              => $filename,
        ]);

        return array_map('intval', $query);
    }

    /**
     * Determine if the bundle file exists on disk.
     *
     * @param string $bundle_path
     * @return bool
     */
    private static function bundle_file_exists(array $descriptor, array $options): bool
    {
        $bundle_path = $descriptor['bundle_path'] ?? '';
        if ($bundle_path === '') {
            return false;
        }

        $proposal_id = $options['proposal_id'] ?? '';
        $bundle_meta = isset($options['bundle_meta']) && is_array($options['bundle_meta'])
            ? $options['bundle_meta']
            : [];

        $located = BundleManager::locate_bundle_file(
            $proposal_id,
            $bundle_path,
            $bundle_meta,
            [
                'bundle_dir'   => $options['bundle_dir'] ?? '',
                'manifest_dir' => $options['manifest_dir'] ?? '',
            ]
        );

        return $located && file_exists($located);
    }

}
