<?php

use Dbvc\Connected\Apply\MediaMaterializer;
use Dbvc\Connected\Preparation\MediaReferences;

/**
 * A2-2c: MediaMaterializer pulls a large (`fetch`) media descriptor by content
 * hash and sideloads it with the same sha256 verification as an inline file —
 * so a tampered or failed pull fails closed, never a bad apply.
 *
 * @group connected-environments
 */
class ConnectedMediaMaterializerPullTest extends WP_UnitTestCase
{
    public function tear_down(): void
    {
        foreach (get_posts([
            'post_type' => 'attachment',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => MediaReferences::HASH_META, // phpcs:ignore WordPress.DB.SlowDBQuery
            'suppress_filters' => true,
        ]) as $id) {
            wp_delete_attachment((int) $id, true);
        }
        parent::tear_down();
    }

    private function hash(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    public function test_fetch_descriptor_is_pulled_and_sideloaded(): void
    {
        $bytes = str_repeat('PULL', 5000);
        $hash = $this->hash($bytes);
        $seen = null;
        $puller = function ($h) use ($bytes, &$seen) {
            $seen = $h;

            return $bytes;
        };

        $result = MediaMaterializer::materialize([
            ['hash' => 'sha256:' . $hash, 'fetch' => true, 'filename' => 'big.png', 'mime' => 'image/png'],
        ], $puller);

        $this->assertSame('sha256:' . $hash, $seen, 'the puller is called with the prefixed content hash');
        $this->assertArrayHasKey($hash, $result['created']);
        $this->assertSame([], $result['errors']);
        $this->assertGreaterThan(0, MediaReferences::local_by_hash($hash), 'the sideloaded attachment is found by content hash');
    }

    public function test_fetch_without_a_puller_is_unresolved(): void
    {
        $hash = $this->hash('xyz');
        $result = MediaMaterializer::materialize([['hash' => 'sha256:' . $hash, 'fetch' => true]]);
        $this->assertSame('invalid_bytes', $result['errors'][$hash]);
    }

    public function test_a_failed_pull_is_unresolved(): void
    {
        $hash = $this->hash('content');
        $result = MediaMaterializer::materialize([['hash' => 'sha256:' . $hash, 'fetch' => true]], static function () {
            return null;
        });
        $this->assertSame('invalid_bytes', $result['errors'][$hash]);
    }

    public function test_tampered_pull_is_rejected_by_rehash(): void
    {
        $hash = $this->hash('the-declared-content');
        $result = MediaMaterializer::materialize([['hash' => 'sha256:' . $hash, 'fetch' => true]], static function () {
            return 'tampered-bytes';
        });
        $this->assertSame('hash_mismatch', $result['errors'][$hash]);
        $this->assertSame(0, MediaReferences::local_by_hash($hash), 'nothing is sideloaded on a hash mismatch');
    }

    public function test_inline_descriptor_never_calls_the_puller(): void
    {
        $bytes = 'INLINE-BYTES';
        $hash = $this->hash($bytes);
        $called = false;
        $result = MediaMaterializer::materialize([
            ['hash' => 'sha256:' . $hash, 'bytes' => base64_encode($bytes), 'filename' => 'a.png', 'mime' => 'image/png'],
        ], function () use (&$called) {
            $called = true;

            return 'unused';
        });

        $this->assertFalse($called, 'an inline descriptor resolves from its own bytes');
        $this->assertArrayHasKey($hash, $result['created']);
    }
}
