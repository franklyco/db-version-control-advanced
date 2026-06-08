<?php

namespace Dbvc\Media\Hydration;

if (! defined('WPINC')) {
    die;
}

/**
 * Read-only inventory of registered WordPress Media Library attachments.
 */
final class LibraryInventoryService
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 500;

    /**
     * Query attachment descriptors in bounded pages.
     *
     * @param array $args {
     *   @type int      $limit              Page size. Default 100, max 500.
     *   @type int      $offset             Offset. Default 0.
     *   @type int[]    $attachment_ids     Optional explicit attachment IDs.
     *   @type bool     $include_file_state Include FileStateService inspection. Default true.
     *   @type bool     $compute_hash       Compute existing local file hashes. Default false.
     *   @type bool     $check_derivatives  Check derivative file existence. Default false.
     *   @type string[] $mime_groups        Optional groups: image, video, audio, font, document, other.
     * }
     * @return array<string,mixed>
     */
    public static function query(array $args = []): array
    {
        $limit = isset($args['limit']) ? absint($args['limit']) : self::DEFAULT_LIMIT;
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }
        $limit = min($limit, self::MAX_LIMIT);

        $offset = isset($args['offset']) ? absint($args['offset']) : 0;
        $attachment_ids = self::normalize_ids($args['attachment_ids'] ?? []);
        $include_file_state = array_key_exists('include_file_state', $args) ? (bool) $args['include_file_state'] : true;
        $mime_groups = self::normalize_mime_groups($args['mime_groups'] ?? []);

        $query_args = [
            'post_type' => 'attachment',
            'post_status' => ['inherit', 'private'],
            'posts_per_page' => $limit,
            'offset' => $offset,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => false,
            'suppress_filters' => true,
        ];

        if (! empty($attachment_ids)) {
            $query_args['post__in'] = $attachment_ids;
            $query_args['orderby'] = 'post__in';
        }

        $query = new \WP_Query($query_args);
        $ids = array_values(array_map('absint', is_array($query->posts) ? $query->posts : []));
        $items = [];

        foreach ($ids as $attachment_id) {
            $descriptor = self::build_descriptor($attachment_id, [
                'include_file_state' => $include_file_state,
                'compute_hash' => ! empty($args['compute_hash']),
                'check_derivatives' => ! empty($args['check_derivatives']),
            ]);

            if (! empty($mime_groups) && ! in_array($descriptor['mime_group'], $mime_groups, true)) {
                continue;
            }

            $items[] = $descriptor;
        }

        return [
            'items' => $items,
            'summary' => self::summarize($items),
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'total' => (int) $query->found_posts,
                'returned' => count($items),
                'has_more' => ($offset + $limit) < (int) $query->found_posts,
            ],
        ];
    }

    /**
     * Build a descriptor for a single attachment.
     *
     * @param int   $attachment_id
     * @param array $args
     * @return array<string,mixed>
     */
    public static function build_descriptor(int $attachment_id, array $args = []): array
    {
        $attachment_id = absint($attachment_id);
        $post = get_post($attachment_id);
        $mime_type = $post instanceof \WP_Post ? (string) $post->post_mime_type : '';
        $relative_path = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        $resolved = FileStateService::resolve_attachment_path($relative_path);
        $file_state = [];

        if (array_key_exists('include_file_state', $args) ? (bool) $args['include_file_state'] : true) {
            $file_state = FileStateService::inspect($attachment_id, [
                'compute_hash' => ! empty($args['compute_hash']),
                'check_derivatives' => ! empty($args['check_derivatives']),
            ]);
        }

        return [
            'attachment_id' => $attachment_id,
            'post_status' => $post instanceof \WP_Post ? (string) $post->post_status : '',
            'post_title' => $post instanceof \WP_Post ? (string) $post->post_title : '',
            'post_parent' => $post instanceof \WP_Post ? (int) $post->post_parent : 0,
            'post_date' => $post instanceof \WP_Post ? (string) $post->post_date : '',
            'post_modified' => $post instanceof \WP_Post ? (string) $post->post_modified : '',
            'mime_type' => $mime_type,
            'mime_group' => FileStateService::mime_group($mime_type),
            'source_url' => wp_get_attachment_url($attachment_id) ?: '',
            'relative_path' => $resolved['relative_path'],
            'attached_file_meta' => $relative_path,
            'safe_path' => (bool) $resolved['safe'],
            'asset_uid' => (string) get_post_meta($attachment_id, 'vf_asset_uid', true),
            'file_hash_meta' => (string) get_post_meta($attachment_id, 'vf_file_hash', true),
            'original_attachment_id' => (int) get_post_meta($attachment_id, '_dbvc_original_attachment_id', true),
            'file_state' => $file_state,
        ];
    }

    /**
     * Summarize a descriptor page.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<string,mixed>
     */
    public static function summarize(array $items): array
    {
        $summary = [
            'count' => count($items),
            'file_status' => [],
            'mime_groups' => [],
            'missing_files' => 0,
            'unsafe_paths' => 0,
            'metadata_missing' => 0,
            'metadata_stale' => 0,
        ];

        foreach ($items as $item) {
            $group = isset($item['mime_group']) ? (string) $item['mime_group'] : 'other';
            if (! isset($summary['mime_groups'][$group])) {
                $summary['mime_groups'][$group] = 0;
            }
            $summary['mime_groups'][$group]++;

            $file_state = isset($item['file_state']) && is_array($item['file_state']) ? $item['file_state'] : [];
            $status = isset($file_state['status']) ? (string) $file_state['status'] : 'unknown';
            if (! isset($summary['file_status'][$status])) {
                $summary['file_status'][$status] = 0;
            }
            $summary['file_status'][$status]++;

            if ($status === 'missing' || $status === 'missing_attached_file_meta') {
                $summary['missing_files']++;
            }
            if ($status === 'unsafe_path') {
                $summary['unsafe_paths']++;
            }

            $metadata = isset($file_state['metadata']) && is_array($file_state['metadata']) ? $file_state['metadata'] : [];
            $metadata_status = isset($metadata['status']) ? (string) $metadata['status'] : '';
            if ($metadata_status === 'missing') {
                $summary['metadata_missing']++;
            } elseif (in_array($metadata_status, ['stale', 'missing_derivatives'], true)) {
                $summary['metadata_stale']++;
            }
        }

        ksort($summary['file_status']);
        ksort($summary['mime_groups']);

        return $summary;
    }

    /**
     * @param mixed $ids
     * @return int[]
     */
    private static function normalize_ids($ids): array
    {
        if (! is_array($ids)) {
            if (is_string($ids) && $ids !== '') {
                $ids = explode(',', $ids);
            } else {
                return [];
            }
        }

        $normalized = [];
        foreach ($ids as $id) {
            $id = absint($id);
            if ($id > 0) {
                $normalized[] = $id;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param mixed $groups
     * @return string[]
     */
    private static function normalize_mime_groups($groups): array
    {
        if (! is_array($groups)) {
            if (is_string($groups) && $groups !== '') {
                $groups = explode(',', $groups);
            } else {
                return [];
            }
        }

        $allowed = ['image', 'video', 'audio', 'font', 'document', 'other'];
        $normalized = [];
        foreach ($groups as $group) {
            $group = sanitize_key((string) $group);
            if (in_array($group, $allowed, true)) {
                $normalized[] = $group;
            }
        }

        return array_values(array_unique($normalized));
    }
}
