<?php

use Dbvc\AgencyControl\Storage\MediaBlobStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;

/**
 * Post-M9 Phase A-2a: the content-addressed media blob store that holds carried
 * media bytes out of the hot `release_items.media` column. The end-to-end
 * transparency of the offload (release → prepare/approve → apply) is covered by
 * the existing media suites (ConnectedApplyTest / ConnectedReleaseTest /
 * ConnectedMediaTest / ConnectedBricksTemplatesTest, all green under the
 * offload); this covers the store, the hydrate helper, and GC directly.
 *
 * @group connected-environments
 */
class ConnectedMediaBlobStoreTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        HubSchema::reset_memo();
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        HubSchema::install();
        MediaBlobStore::reset();
    }

    public function tear_down(): void
    {
        MediaBlobStore::reset();
        DBVC_Agency_Control_Addon::save_settings([]);
        HubSchema::reset_memo();
        parent::tear_down();
    }

    private function hash(string $bytes): string
    {
        return 'sha256:' . hash('sha256', $bytes);
    }

    public function test_store_has_bytes_roundtrip_and_dedupe(): void
    {
        $bytes = 'PNGDATA-' . str_repeat('x', 200);
        $hash = $this->hash($bytes);

        $this->assertFalse(MediaBlobStore::has($hash));
        $this->assertTrue(MediaBlobStore::store($hash, $bytes, 'image/png', strlen($bytes)));
        $this->assertTrue(MediaBlobStore::has($hash));
        $this->assertSame($bytes, MediaBlobStore::bytes($hash), 'raw bytes round-trip (not base64)');
        $this->assertNull(MediaBlobStore::bytes($this->hash('absent')));

        // Dedupe: storing the same content hash again is a no-op — one row.
        $this->assertTrue(MediaBlobStore::store($hash, $bytes, 'image/png', strlen($bytes)));
        $this->assertSame(1, MediaBlobStore::driver()->count());
    }

    public function test_hydrate_fills_missing_bytes_only(): void
    {
        $raw = 'JPEGDATA-' . str_repeat('y', 50);
        $hash = $this->hash($raw);
        MediaBlobStore::store($hash, $raw, 'image/jpeg', strlen($raw));

        $out = MediaBlobStore::hydrate([
            ['hash' => $hash, 'mime' => 'image/jpeg'],                                   // offloaded → hydrate
            ['hash' => $this->hash('inline'), 'bytes' => base64_encode('inline-bytes')], // inline → untouched
            ['hash' => $this->hash('missing')],                                          // unknown → stays absent
            ['mime' => 'image/png'],                                                     // no hash → skipped
        ]);

        $this->assertSame(base64_encode($raw), $out[0]['bytes'], 'offloaded descriptor hydrated from store');
        $this->assertSame(base64_encode('inline-bytes'), $out[1]['bytes'], 'pre-existing inline bytes preserved');
        $this->assertArrayNotHasKey('bytes', $out[2], 'unknown hash left unresolved (fail-closed)');
        $this->assertArrayNotHasKey('bytes', $out[3], 'no-hash descriptor untouched');
    }

    public function test_gc_sweeps_orphans_but_keeps_referenced_blobs(): void
    {
        global $wpdb;
        $live = 'LIVE-' . str_repeat('a', 100);
        $orphan = 'ORPHAN-' . str_repeat('b', 100);
        $live_hash = $this->hash($live);
        $orphan_hash = $this->hash($orphan);
        MediaBlobStore::store($live_hash, $live, 'image/png', strlen($live));
        MediaBlobStore::store($orphan_hash, $orphan, 'image/png', strlen($orphan));
        $this->assertSame(2, MediaBlobStore::driver()->count());

        // A release item references only the live hash in its media descriptors.
        $items = HubSchema::table('release_items');
        $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "INSERT INTO {$items} (release_id, domain, instance_uid, profile, after_hash, media) VALUES (%d, %s, %s, %s, %s, %s)",
            1,
            'wp.service',
            'inst-x',
            'profile-x',
            'sha256:' . str_repeat('0', 64),
            wp_json_encode(['media' => [['hash' => $live_hash]], 'deferred' => []])
        ));

        $removed = MediaBlobStore::gc();
        $this->assertSame(1, $removed, 'only the unreferenced blob is swept');
        $this->assertTrue(MediaBlobStore::has($live_hash), 'a referenced blob survives GC');
        $this->assertFalse(MediaBlobStore::has($orphan_hash), 'an orphan blob is removed');
    }

    public function test_store_rejects_empty_input(): void
    {
        $this->assertFalse(MediaBlobStore::store('', 'x', 'image/png', 1));
        $this->assertFalse(MediaBlobStore::store($this->hash('z'), '', 'image/png', 0));
        $this->assertSame(0, MediaBlobStore::driver()->count());
    }
}
