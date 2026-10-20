<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * A content-addressed store for carried media bytes, keyed by sha256.
 *
 * Post-M9 Phase A-2a offloads release media bytes out of the hot
 * `release_items.media` column into a store so the column carries only
 * descriptors. The default driver keeps bytes in a dedicated DB table
 * ({@see DbMediaBlobStore}); a filesystem/object-store driver can replace it
 * behind this interface later (A2-3) with no change to the services.
 */
interface MediaBlobStoreDriver
{
    /**
     * Store raw bytes under their content hash. Idempotent and deduped: a hash
     * already present is left as-is (same content by definition). Returns whether
     * the blob is present after the call.
     *
     * @param string $hash     Content hash, `sha256:<64 hex>`.
     * @param string $bytes    Raw file bytes (not base64).
     * @param string $mime
     * @param int    $filesize
     * @return bool
     */
    public function store($hash, $bytes, $mime, $filesize);

    /**
     * @param string $hash
     * @return bool
     */
    public function has($hash);

    /**
     * Raw bytes for a hash, or null when absent.
     *
     * @param string $hash
     * @return string|null
     */
    public function bytes($hash);

    /**
     * Mark-and-sweep: delete every blob whose hash is NOT in the given live set.
     * An empty live set deletes all blobs. Returns the number removed.
     *
     * @param array<int, string> $live_hashes
     * @return int
     */
    public function delete_except(array $live_hashes);

    /**
     * Total blobs held (inspection/tests).
     *
     * @return int
     */
    public function count();
}
