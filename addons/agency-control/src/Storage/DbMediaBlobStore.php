<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * Default media blob store: raw bytes in a dedicated `dbvc_ac_media_blobs`
 * LONGBLOB table, keyed by content hash.
 *
 * Keeps the project's transactional / backup-consistent / net-zero storage
 * story (everything lives in `dbvc_ac_*`), while moving the bytes out of the hot
 * `release_items.media` column. Raw bytes (not base64) → ~33% smaller and no
 * decode on the hot path.
 */
final class DbMediaBlobStore implements MediaBlobStoreDriver
{
    /**
     * @return string
     */
    private function table()
    {
        return Schema::table('media_blobs');
    }

    /**
     * @param string $hash
     * @param string $bytes
     * @param string $mime
     * @param int    $filesize
     * @return bool
     */
    public function store($hash, $bytes, $mime, $filesize)
    {
        global $wpdb;
        $hash = (string) $hash;
        if ($hash === '' || ! is_string($bytes) || $bytes === '') {
            return false;
        }
        if ($this->has($hash)) {
            return true;
        }
        $table = $this->table();
        // INSERT IGNORE: a concurrent insert of the same content hash is a no-op,
        // never an error — the bytes are identical by definition.
        $ok = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (hash, mime, filesize, created_at, bytes) VALUES (%s, %s, %d, %s, %s)",
                $hash,
                (string) $mime,
                (int) $filesize,
                current_time('mysql', true),
                $bytes
            )
        );

        return $ok !== false && $this->has($hash);
    }

    /**
     * @param string $hash
     * @return bool
     */
    public function has($hash)
    {
        global $wpdb;
        $table = $this->table();

        return (int) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table} WHERE hash = %s LIMIT 1", (string) $hash)) === 1; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * @param string $hash
     * @return string|null
     */
    public function bytes($hash)
    {
        global $wpdb;
        $table = $this->table();
        $value = $wpdb->get_var($wpdb->prepare("SELECT bytes FROM {$table} WHERE hash = %s LIMIT 1", (string) $hash)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<int, string> $live_hashes
     * @return int
     */
    public function delete_except(array $live_hashes)
    {
        global $wpdb;
        $table = $this->table();
        $live = array_values(array_unique(array_filter(array_map('strval', $live_hashes), static function ($h) {
            return $h !== '';
        })));
        if ($live === []) {
            $deleted = $wpdb->query("DELETE FROM {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            return $deleted === false ? 0 : (int) $deleted;
        }
        $placeholders = implode(', ', array_fill(0, count($live), '%s'));
        $deleted = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare("DELETE FROM {$table} WHERE hash NOT IN ({$placeholders})", $live) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );

        return $deleted === false ? 0 : (int) $deleted;
    }

    /**
     * @return int
     */
    public function count()
    {
        global $wpdb;
        $table = $this->table();

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
