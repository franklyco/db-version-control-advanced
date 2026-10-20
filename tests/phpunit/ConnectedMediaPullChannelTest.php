<?php

use Dbvc\AgencyControl\Release\ReleaseService;
use Dbvc\AgencyControl\Storage\MediaBlobStore;
use Dbvc\AgencyControl\Storage\PreparationStore;
use Dbvc\AgencyControl\Storage\ReleaseStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;

/**
 * Post-M9 A-2a: the hub side of the fetch-by-hash media pull channel — accept a
 * large blob from the source and serve it to a release target, with
 * release-scoped authorization and sha256/size verification. (The source upload
 * wiring is A2-2b; the target pull-on-apply is A2-2c.)
 *
 * @group connected-environments
 */
class ConnectedMediaPullChannelTest extends WP_UnitTestCase
{
    /** @var ReleaseService */
    private $service;
    /** @var string */
    private $hash;
    /** @var string */
    private $bytes;
    /** @var int */
    private $release_id;

    public function set_up(): void
    {
        parent::set_up();
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        HubSchema::reset_memo();
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        HubSchema::install();
        MediaBlobStore::reset();
        $this->service = new ReleaseService();
        $this->bytes = 'BIGIMG-' . str_repeat('z', 4096);
        $this->hash = 'sha256:' . hash('sha256', $this->bytes);
        $this->seed_release();
    }

    public function tear_down(): void
    {
        MediaBlobStore::reset();
        remove_all_filters('dbvc_agency_max_pull_file_bytes');
        DBVC_Agency_Control_Addon::save_settings([]);
        HubSchema::reset_memo();
        parent::tear_down();
    }

    private function seed_release(): void
    {
        $store = new ReleaseStore();
        $release = $store->create(
            ['release_uid' => 'rel-1', 'agency_id' => 'studio', 'client_id' => 'client-a', 'source_environment_id' => 'src', 'source_epoch' => 'e1'],
            [['domain' => 'wp.service', 'instance_uid' => 'inst', 'profile' => 'prof', 'after_hash' => str_repeat('a', 64)]]
        );
        $this->release_id = (int) $release['release_id'];
        $items = $store->items($this->release_id, true);
        // The item references the large file as a fetch descriptor (no inline bytes).
        $store->record_payload((int) $items[0]['release_item_id'], 'body', '', wp_json_encode(['media' => [['hash' => $this->hash, 'fetch' => true]], 'deferred' => []]));
        (new PreparationStore())->request(['operation_id' => 'op-1', 'release_id' => $this->release_id, 'release_uid' => 'rel-1', 'target_environment_id' => 'tgt', 'target_epoch' => 'e1']);
    }

    private function env(string $id): array
    {
        return ['environment_id' => $id, 'status' => 'enabled', 'current_epoch' => 'e1'];
    }

    public function test_source_uploads_blob_and_target_pulls_it(): void
    {
        $result = $this->service->accept_media_blob($this->env('src'), 'rel-1', $this->hash, $this->bytes);
        $this->assertIsArray($result);
        $this->assertTrue($result['stored']);
        $this->assertTrue(MediaBlobStore::has($this->hash));

        $this->assertSame($this->bytes, $this->service->serve_media_blob($this->env('tgt'), 'rel-1', $this->hash), 'target pulls the exact bytes');
        // The source is also a party to its own release.
        $this->assertSame($this->bytes, $this->service->serve_media_blob($this->env('src'), 'rel-1', $this->hash));
    }

    public function test_upload_rejects_non_source_principal(): void
    {
        $r = $this->service->accept_media_blob($this->env('tgt'), 'rel-1', $this->hash, $this->bytes);
        $this->assertWPError($r);
        $this->assertSame('dbvc_agency_release_not_found', $r->get_error_code());
    }

    public function test_upload_rejects_hash_not_referenced_by_release(): void
    {
        $other = 'sha256:' . hash('sha256', 'unreferenced');
        $r = $this->service->accept_media_blob($this->env('src'), 'rel-1', $other, 'unreferenced');
        $this->assertWPError($r);
        $this->assertSame('dbvc_agency_media_unreferenced', $r->get_error_code());
    }

    public function test_upload_rejects_hash_mismatch(): void
    {
        $r = $this->service->accept_media_blob($this->env('src'), 'rel-1', $this->hash, 'different bytes entirely');
        $this->assertWPError($r);
        $this->assertSame('dbvc_agency_media_hash_mismatch', $r->get_error_code());
        $this->assertFalse(MediaBlobStore::has($this->hash), 'nothing is stored on a hash mismatch');
    }

    public function test_upload_rejects_oversize(): void
    {
        add_filter('dbvc_agency_max_pull_file_bytes', static function () {
            return 100;
        });
        $r = $this->service->accept_media_blob($this->env('src'), 'rel-1', $this->hash, $this->bytes);
        $this->assertWPError($r);
        $this->assertSame('dbvc_agency_media_too_large', $r->get_error_code());
    }

    public function test_upload_allowed_once_sealed(): void
    {
        // The payload POST that carries the fetch descriptor can itself seal the
        // release, so the follow-up blob upload lands post-seal — and must succeed
        // (the blob is content-addressed, not part of the digest).
        global $wpdb;
        $wpdb->update(HubSchema::table('releases'), ['state' => ReleaseStore::STATE_SEALED], ['release_uid' => 'rel-1']);
        $r = $this->service->accept_media_blob($this->env('src'), 'rel-1', $this->hash, $this->bytes);
        $this->assertIsArray($r);
        $this->assertTrue($r['stored']);
    }

    public function test_upload_refused_once_withdrawn(): void
    {
        global $wpdb;
        $wpdb->update(HubSchema::table('releases'), ['state' => ReleaseStore::STATE_WITHDRAWN], ['release_uid' => 'rel-1']);
        $r = $this->service->accept_media_blob($this->env('src'), 'rel-1', $this->hash, $this->bytes);
        $this->assertWPError($r);
        $this->assertSame('dbvc_agency_release_not_open', $r->get_error_code());
    }

    public function test_serve_rejects_unrelated_environment(): void
    {
        $this->service->accept_media_blob($this->env('src'), 'rel-1', $this->hash, $this->bytes);
        $r = $this->service->serve_media_blob($this->env('stranger'), 'rel-1', $this->hash);
        $this->assertWPError($r);
        $this->assertSame('dbvc_agency_media_forbidden', $r->get_error_code());
    }

    public function test_serve_404_when_blob_not_uploaded(): void
    {
        $r = $this->service->serve_media_blob($this->env('tgt'), 'rel-1', $this->hash);
        $this->assertWPError($r);
        $this->assertSame('dbvc_agency_media_absent', $r->get_error_code());
    }

    public function test_accept_payloads_stores_a_fetch_descriptor_without_bytes(): void
    {
        // A2-2b: the source posts a large media reference as a fetch descriptor
        // (no inline bytes); the hub stores it so the target can pull by hash.
        $body = wp_json_encode(['id' => 1, 'post_title' => 'x']);
        $after = \Dbvc\ConnectedProtocol\Canonicalizer::hash($body);
        $store = new ReleaseStore();
        $release = $store->create(
            ['release_uid' => 'rel-2', 'agency_id' => 'studio', 'client_id' => 'client-a', 'source_environment_id' => 'src', 'source_epoch' => 'e1'],
            [['domain' => 'wp.service', 'instance_uid' => 'inst2', 'profile' => 'prof2', 'after_hash' => $after]]
        );
        $big = 'sha256:' . hash('sha256', 'HUGE-FILE');
        $this->service->accept_payloads($this->env('src'), 'rel-2', [[
            'domain' => 'wp.service',
            'instance_uid' => 'inst2',
            'profile' => 'prof2',
            'body' => $body,
            'media' => [['hash' => $big, 'fetch' => true, 'filesize' => 5000000, 'mime' => 'image/png']],
        ]]);

        $items = $store->items((int) $release['release_id'], true);
        $decoded = json_decode((string) $items[0]['media'], true);
        $this->assertSame($big, $decoded['media'][0]['hash']);
        $this->assertTrue($decoded['media'][0]['fetch']);
        $this->assertArrayNotHasKey('bytes', $decoded['media'][0], 'a fetch descriptor is stored without bytes');
    }

    public function test_hydrate_skips_fetch_descriptors(): void
    {
        $this->service->accept_media_blob($this->env('src'), 'rel-1', $this->hash, $this->bytes);
        // Even with the blob now in the store, a fetch descriptor is NOT inlined
        // into the manifest — the target pulls it by hash instead.
        $out = MediaBlobStore::hydrate([
            ['hash' => $this->hash, 'fetch' => true],
            ['hash' => $this->hash],
        ]);
        $this->assertArrayNotHasKey('bytes', $out[0], 'fetch descriptor stays un-inlined');
        $this->assertSame(base64_encode($this->bytes), $out[1]['bytes'], 'a non-fetch descriptor still hydrates');
    }
}
