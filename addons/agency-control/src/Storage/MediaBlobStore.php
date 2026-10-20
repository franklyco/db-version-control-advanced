<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * Facade over the active {@see MediaBlobStoreDriver}.
 *
 * Post-M9 Phase A-2a: release media bytes are stored here (keyed by sha256,
 * deduped across releases) instead of base64-inlined in the `release_items.media`
 * column. The write side ({@see \Dbvc\AgencyControl\Release\ReleaseService}) calls
 * {@see store()}; the read sides (prepare/approve manifest assembly) call
 * {@see hydrate()} to refill `bytes` so the target's apply path is byte-for-byte
 * unchanged. The driver is pluggable via the `dbvc_agency_media_blob_store`
 * filter (default {@see DbMediaBlobStore}).
 */
final class MediaBlobStore
{
    /**
     * @var MediaBlobStoreDriver|null
     */
    private static $driver = null;

    /**
     * @return MediaBlobStoreDriver
     */
    public static function driver()
    {
        if (self::$driver instanceof MediaBlobStoreDriver) {
            return self::$driver;
        }
        $driver = apply_filters('dbvc_agency_media_blob_store', new DbMediaBlobStore());
        if (! $driver instanceof MediaBlobStoreDriver) {
            $driver = new DbMediaBlobStore();
        }
        self::$driver = $driver;

        return $driver;
    }

    /**
     * Reset the memoized driver (tests).
     *
     * @return void
     */
    public static function reset()
    {
        self::$driver = null;
    }

    /**
     * @param string $hash
     * @param string $bytes raw (not base64)
     * @param string $mime
     * @param int    $filesize
     * @return bool
     */
    public static function store($hash, $bytes, $mime, $filesize)
    {
        return self::driver()->store($hash, $bytes, $mime, $filesize);
    }

    /**
     * @param string $hash
     * @return bool
     */
    public static function has($hash)
    {
        return self::driver()->has($hash);
    }

    /**
     * @param string $hash
     * @return string|null raw bytes
     */
    public static function bytes($hash)
    {
        return self::driver()->bytes($hash);
    }

    /**
     * Fill base64 `bytes` into each media descriptor that lacks them, from the
     * store, keyed by `hash`.
     *
     * Descriptors that already carry inline bytes (a release sealed before the
     * offload) are left untouched — backward compatible. A hash the store cannot
     * resolve leaves `bytes` absent, so the target's `MediaMaterializer` fails
     * closed (`media_unresolved`) exactly as today — never a silent bad apply.
     *
     * @param array<int, array<string, mixed>> $descriptors
     * @return array<int, array<string, mixed>>
     */
    public static function hydrate(array $descriptors)
    {
        foreach ($descriptors as $i => $descriptor) {
            if (! is_array($descriptor)) {
                continue;
            }
            // A `fetch` descriptor (over the inline cap) is served via the pull
            // channel, never inlined into the manifest — leave its bytes absent so
            // the target fetches it by hash at apply (A2-2).
            if (! empty($descriptor['fetch'])) {
                continue;
            }
            if (isset($descriptor['bytes']) && is_string($descriptor['bytes']) && $descriptor['bytes'] !== '') {
                continue;
            }
            $hash = isset($descriptor['hash']) ? (string) $descriptor['hash'] : '';
            if ($hash === '') {
                continue;
            }
            $raw = self::driver()->bytes($hash);
            if (is_string($raw) && $raw !== '') {
                $descriptors[$i]['bytes'] = base64_encode($raw);
            }
        }

        return $descriptors;
    }

    /**
     * Mark-and-sweep orphan blobs: collect every content hash still referenced by
     * any release item's media descriptors, then delete the rest. Best-effort
     * maintenance, safe to run at any time — it never removes a referenced blob.
     *
     * @return int blobs removed
     */
    public static function gc()
    {
        global $wpdb;
        $table = Schema::table('release_items');
        $rows = $wpdb->get_col("SELECT media FROM {$table} WHERE media IS NOT NULL AND media <> ''"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $live = [];
        foreach ((array) $rows as $json) {
            $decoded = json_decode((string) $json, true);
            if (! is_array($decoded) || ! isset($decoded['media']) || ! is_array($decoded['media'])) {
                continue;
            }
            foreach ($decoded['media'] as $descriptor) {
                if (is_array($descriptor) && isset($descriptor['hash']) && is_string($descriptor['hash']) && $descriptor['hash'] !== '') {
                    $live[$descriptor['hash']] = true;
                }
            }
        }

        return self::driver()->delete_except(array_keys($live));
    }
}
