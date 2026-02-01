<?php

namespace Dbvc\Official;

use DBVC_Database;
use WP_Error;

/**
 * Persistence helpers for "Official" collections.
 *
 * Stores metadata in dedicated tables and mirrors resolved snapshots
 * into uploads/dbvc/official/{collection}/ for export parity.
 */
final class Collections
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    /**
     * Create a collection record from a reviewed proposal.
     *
     * @param string $proposal_id      Proposal identifier (matches manifest backup_name).
     * @param array  $entities         Array of resolved entity payloads with vf_object_uid keys.
     * @param array  $metadata         {
     *   @type string       $title         Human readable label (required).
     *   @type string       $status        draft|published.
     *   @type string       $notes         Freeform release notes/description.
     *   @type array|string $tags          Tags applied to the release for filtering.
     *   @type string       $manifest_path Absolute path to manifest for archival copy.
     *   @type string       $archive_path  Absolute path to finalized zip/archive.
     *   @type int          $media_count   Number of media files bundled.
     *   @type string       $checksum      Hex digest for exported archive.
     *   @type int          $created_by    Override author ID.
     * }
     * @return int|WP_Error Collection ID on success.
     */
    public static function mark_official(string $proposal_id, array $entities, array $metadata = [])
    {
        global $wpdb;

        $proposal_id = trim($proposal_id);
        if ($proposal_id === '') {
            return new WP_Error('dbvc_official_missing_proposal', __('Proposal ID is required to promote a collection.', 'dbvc'));
        }

        $title = isset($metadata['title']) ? \sanitize_text_field((string) $metadata['title']) : '';
        if ($title === '') {
            return new WP_Error('dbvc_official_missing_title', __('A collection title is required.', 'dbvc'));
        }

        $normalized_entities = self::normalize_entities($entities);
        $entity_count        = count($normalized_entities);

        $data = [
            'proposal_id'    => $proposal_id,
            'title'          => $title,
            'status'         => self::normalize_status($metadata['status'] ?? self::STATUS_DRAFT),
            'notes'          => self::prepare_notes_blob($metadata),
            'manifest_path'  => '',
            'archive_path'   => '',
            'checksum'       => self::sanitize_optional_string($metadata['checksum'] ?? ''),
            'entities_count' => $entity_count,
            'media_count'    => isset($metadata['media_count']) ? \absint($metadata['media_count']) : 0,
            'created_at'     => \current_time('mysql'),
            'created_by'     => self::resolve_author($metadata),
        ];

        $table   = DBVC_Database::table_name('collections');
        $formats = ['%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%d'];
        $inserted = $wpdb->insert($table, $data, $formats);

        if ($inserted === false) {
            return new WP_Error('dbvc_official_insert_failed', __('Unable to create the official collection record.', 'dbvc'), $wpdb->last_error);
        }

        $collection_id = (int) $wpdb->insert_id;
        if ($collection_id <= 0) {
            return new WP_Error('dbvc_official_missing_id', __('Collection record was created but no ID was returned.', 'dbvc'));
        }

        $collection_dir = self::ensure_collection_directory($collection_id);
        $updates        = [];

        if (! empty($metadata['manifest_path'])) {
            $copied = self::maybe_copy_file($metadata['manifest_path'], $collection_dir, 'dbvc-manifest.json');
            if ($copied !== '') {
                $updates['manifest_path'] = $copied;
            }
        }

        if (! empty($metadata['archive_path'])) {
            $basename = basename((string) $metadata['archive_path']);
            if ($basename === '' || $basename === '.' || $basename === '..') {
                $basename = 'collection.zip';
            }
            $copied = self::maybe_copy_file($metadata['archive_path'], $collection_dir, $basename);
            if ($copied !== '') {
                $updates['archive_path'] = $copied;
            }
        }

        if (! empty($updates)) {
            $wpdb->update(
                $table,
                $updates,
                ['id' => $collection_id],
                array_fill(0, count($updates), '%s'),
                ['%d']
            );
        }

        if ($entity_count > 0) {
            self::persist_entities($collection_id, $normalized_entities, $collection_dir);
        }

        return $collection_id;
    }

    /**
     * Fetch a single collection row.
     */
    public static function get(int $collection_id): ?array
    {
        global $wpdb;

        if ($collection_id <= 0) {
            return null;
        }

        $table = DBVC_Database::table_name('collections');
        $row   = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $collection_id),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * List collections with simple filters for UI/CLI.
     *
     * @param array $args {
     *   @type string $status
     *   @type string $proposal_id
     *   @type string $search
     *   @type int    $limit
     *   @type int    $offset
     *   @type string $order asc|desc
     * }
     */
    public static function query(array $args = []): array
    {
        global $wpdb;

        $defaults = [
            'status'      => null,
            'proposal_id' => null,
            'search'      => null,
            'limit'       => 20,
            'offset'      => 0,
            'order'       => 'desc',
        ];
        $args = \wp_parse_args($args, $defaults);

        $limit  = max(1, (int) $args['limit']);
        $offset = max(0, (int) $args['offset']);
        $order  = strtolower((string) $args['order']) === 'asc' ? 'ASC' : 'DESC';

        $where  = ['1=1'];
        $params = [];

        if (! empty($args['status'])) {
            $where[]  = 'status = %s';
            $params[] = self::normalize_status($args['status']);
        }

        if (! empty($args['proposal_id'])) {
            $where[]  = 'proposal_id = %s';
            $params[] = \sanitize_text_field((string) $args['proposal_id']);
        }

        if (! empty($args['search'])) {
            $like     = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $where[]  = '(title LIKE %s OR notes LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $table = DBVC_Database::table_name('collections');
        $sql   = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY created_at {$order} LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        $prepared = $wpdb->prepare($sql, $params);
        $rows     = $wpdb->get_results($prepared, ARRAY_A);

        return $rows ?: [];
    }

    /**
     * Persist individual entity snapshots + metadata.
     */
    private static function persist_entities(int $collection_id, array $entities, string $collection_dir): void
    {
        global $wpdb;

        $items_table  = DBVC_Database::table_name('collection_items');
        $now          = \current_time('mysql');
        $entities_dir = \trailingslashit($collection_dir) . 'entities';

        if (! is_dir($entities_dir)) {
            \wp_mkdir_p($entities_dir);
        }
        self::secure_directory($entities_dir);

        foreach ($entities as $entity) {
            $relative_snapshot = '';
            if (! empty($entity['payload'])) {
                $relative_snapshot = self::write_snapshot_file($entities_dir, $entity['entity_uid'], $entity['payload']);
            }

            $payload_blob = '';
            if ($relative_snapshot === '' && ! empty($entity['payload'])) {
                $payload_blob = self::encode_payload($entity['payload']);
            }

            $wpdb->insert(
                $items_table,
                [
                    'collection_id' => $collection_id,
                    'entity_uid'    => $entity['entity_uid'],
                    'entity_type'   => $entity['entity_type'],
                    'entity_label'  => $entity['label'],
                    'decision'      => $entity['decision'],
                    'snapshot_path' => $relative_snapshot,
                    'payload'       => $payload_blob,
                    'created_at'    => $now,
                ],
                ['%d','%s','%s','%s','%s','%s','%s','%s']
            );
        }
    }

    /**
     * Normalizes entity payloads from the UI/REST layer.
     */
    private static function normalize_entities(array $entities): array
    {
        $normalized = [];

        foreach ($entities as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $uid = isset($entry['vf_object_uid'])
                ? (string) $entry['vf_object_uid']
                : (string) ($entry['entity_uid'] ?? '');

            $uid = trim($uid);
            if ($uid === '') {
                continue;
            }

            $entity_type = isset($entry['entity_type']) ? \sanitize_key($entry['entity_type']) : 'post';
            if ($entity_type === '') {
                $entity_type = 'post';
            }

            $payload = [];
            if (isset($entry['payload']) && is_array($entry['payload'])) {
                $payload = $entry['payload'];
            } elseif (isset($entry['snapshot']) && is_array($entry['snapshot'])) {
                $payload = $entry['snapshot'];
            }

            $normalized[] = [
                'entity_uid' => $uid,
                'entity_type'=> $entity_type,
                'label'      => isset($entry['label']) ? \sanitize_text_field((string) $entry['label']) : '',
                'decision'   => self::normalize_decision($entry['decision'] ?? ''),
                'payload'    => $payload,
            ];
        }

        return $normalized;
    }

    private static function normalize_decision($value): string
    {
        $allowed = ['accept', 'keep', 'mixed', 'new'];
        $value   = \sanitize_key((string) $value);
        return in_array($value, $allowed, true) ? $value : 'accept';
    }

    private static function normalize_status($value): string
    {
        $value = \sanitize_key((string) $value);
        return in_array($value, [self::STATUS_DRAFT, self::STATUS_PUBLISHED], true)
            ? $value
            : self::STATUS_DRAFT;
    }

    private static function resolve_author(array $metadata)
    {
        $author = isset($metadata['created_by']) ? \absint($metadata['created_by']) : \get_current_user_id();
        return $author > 0 ? $author : null;
    }

    private static function prepare_notes_blob(array $metadata): string
    {
        $notes = [];

        if (! empty($metadata['notes'])) {
            $notes['notes'] = \wp_kses_post($metadata['notes']);
        }

        if (! empty($metadata['tags'])) {
            $tags = array_filter(array_map('trim', (array) $metadata['tags']), static function ($value) {
                return $value !== '';
            });
            if (! empty($tags)) {
                $notes['tags'] = array_map('\sanitize_text_field', $tags);
            }
        }

        if (! empty($metadata['release_notes'])) {
            $notes['release_notes'] = \wp_kses_post($metadata['release_notes']);
        }

        if (empty($notes)) {
            return '';
        }

        $encoded = \wp_json_encode($notes);
        return is_string($encoded) ? $encoded : '';
    }

    private static function sanitize_optional_string($value): string
    {
        $value = trim((string) $value);
        return $value === '' ? '' : \sanitize_text_field($value);
    }

    private static function ensure_collection_directory(int $collection_id): string
    {
        $base = self::get_storage_base();
        $dir  = \trailingslashit($base) . 'collection-' . $collection_id;

        if (! is_dir($dir)) {
            \wp_mkdir_p($dir);
        }

        self::secure_directory($dir);

        return $dir;
    }

    private static function get_storage_base(): string
    {
        $uploads = \wp_upload_dir(null, false);
        $base    = isset($uploads['basedir']) && $uploads['basedir'] !== ''
            ? $uploads['basedir']
            : WP_CONTENT_DIR . '/uploads';

        $target = \trailingslashit($base) . 'dbvc/official';

        if (! is_dir($target)) {
            \wp_mkdir_p($target);
        }

        self::secure_directory($target);

        return $target;
    }

    private static function secure_directory(string $path): void
    {
        if (class_exists('\DBVC_Sync_Posts') && method_exists('\DBVC_Sync_Posts', 'ensure_directory_security')) {
            \DBVC_Sync_Posts::ensure_directory_security($path);
        }
    }

    private static function maybe_copy_file($source, string $destination_dir, string $filename): string
    {
        $source = (string) $source;
        if ($source === '' || ! file_exists($source) || ! is_readable($source)) {
            return '';
        }

        if (! is_dir($destination_dir)) {
            \wp_mkdir_p($destination_dir);
            self::secure_directory($destination_dir);
        }

        $filename = trim($filename);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $filename = basename($source);
        }
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $filename = uniqid('dbvc-official-', true);
        }

        $destination = \trailingslashit($destination_dir) . $filename;

        if (@copy($source, $destination)) {
            return self::to_relative_path($destination);
        }

        return '';
    }

    private static function write_snapshot_file(string $entities_dir, string $uid, array $payload): string
    {
        if (! is_dir($entities_dir)) {
            \wp_mkdir_p($entities_dir);
        }

        self::secure_directory($entities_dir);

        $safe_uid = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $uid);
        $filename = $safe_uid . '.json';
        $path     = \trailingslashit($entities_dir) . $filename;

        $json = self::encode_payload($payload, true);
        if ($json === '') {
            return '';
        }

        file_put_contents($path, $json);

        return self::to_relative_path($path);
    }

    private static function encode_payload(array $payload, bool $pretty = false): string
    {
        $options = JSON_UNESCAPED_SLASHES;
        if ($pretty) {
            $options |= JSON_PRETTY_PRINT;
        }

        $encoded = \wp_json_encode($payload, $options);
        return is_string($encoded) ? $encoded : '';
    }

    private static function to_relative_path(string $absolute): string
    {
        $uploads = \wp_upload_dir(null, false);
        if (empty($uploads['basedir'])) {
            return $absolute;
        }

        $base = rtrim($uploads['basedir'], '/\\');
        if (strpos($absolute, $base) === 0) {
            $relative = substr($absolute, strlen($base));
            return ltrim(str_replace('\\', '/', $relative), '/');
        }

        return $absolute;
    }
}
