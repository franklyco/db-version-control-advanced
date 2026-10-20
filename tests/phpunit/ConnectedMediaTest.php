<?php

use Dbvc\Connected\Adapters\ServicePostObserver;
use Dbvc\Connected\Apply\MediaMaterializer;
use Dbvc\Connected\Preparation\DependencyLedger;
use Dbvc\Connected\Preparation\MediaReferences;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * M7 step 3a: portable attachment references. The wp.service projection names
 * attachments by a content-hash token (`dbvc-media:<sha256>`) instead of a
 * local id/URL, so two sites holding the same image bytes project the same
 * hash; apply reverses the token to this site's own id/URL before the write.
 * The tokenize/detokenize round trip is lossless and content-addressed, so a
 * media-bearing post that lands on a target already holding the bytes
 * converges to the source's after_hash.
 *
 * @group connected-environments
 */
class ConnectedMediaTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        register_post_type('service', ['public' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
    }

    public function tear_down(): void
    {
        unregister_post_type('service');
        parent::tear_down();
    }

    public function test_tokenize_replaces_resolvable_refs_and_leaves_others_literal(): void
    {
        $thumb_bytes = 'FEATURED';
        $thumb = $this->make_attachment($thumb_bytes, 'featured.png');
        $inline_bytes = 'INLINE';
        $inline = $this->make_attachment($inline_bytes, 'inline.png');
        $thumb_url = (string) wp_get_attachment_url($thumb);

        $body = [
            'post_content' => '<img class="wp-image-' . $inline . '" /> <img src="' . esc_url($thumb_url) . '" /> <img class="wp-image-99000001" />',
            'meta' => ['_thumbnail_id' => [(string) $thumb]],
        ];
        $tokenized = MediaReferences::tokenize($body);

        $thumb_token = MediaReferences::TOKEN_PREFIX . hash('sha256', $thumb_bytes);
        $inline_token = MediaReferences::TOKEN_PREFIX . hash('sha256', $inline_bytes);
        $this->assertSame([$thumb_token], $tokenized['meta']['_thumbnail_id'], 'Featured image id -> content-hash token.');
        $this->assertStringContainsString('wp-image-' . $inline_token, $tokenized['post_content']);
        $this->assertStringContainsString('src="' . $thumb_token . '"', $tokenized['post_content'], 'Upload URL -> bare content-hash token.');
        $this->assertStringContainsString('wp-image-99000001', $tokenized['post_content'], 'An unresolvable reference is left literal.');
        // The content hash is cached on the attachment for later lookup.
        $this->assertSame(hash('sha256', $thumb_bytes), get_post_meta($thumb, MediaReferences::HASH_META, true));
        $this->assertSame($thumb, MediaReferences::local_by_hash(hash('sha256', $thumb_bytes)));
    }

    public function test_tokenize_detokenize_round_trip_restores_local_references(): void
    {
        $bytes = 'ROUNDTRIP';
        $att = $this->make_attachment($bytes, 'rt.png');
        $url = (string) wp_get_attachment_url($att);
        $body = [
            'post_content' => '<img class="wp-image-' . $att . '" src="' . esc_url($url) . '" />',
            'meta' => ['_thumbnail_id' => [(string) $att]],
        ];

        $tokenized = MediaReferences::tokenize($body);
        $this->assertStringNotContainsString('wp-image-' . $att, $tokenized['post_content']);
        $restored = MediaReferences::detokenize($tokenized);

        $this->assertSame([(string) $att], $restored['meta']['_thumbnail_id']);
        $this->assertStringContainsString('wp-image-' . $att, $restored['post_content']);
        $this->assertStringContainsString($url, $restored['post_content']);
        $this->assertSame([hash('sha256', $bytes)], MediaReferences::tokens_in_body($tokenized));
    }

    public function test_ledger_blocks_when_target_lacks_the_content_and_clears_once_present(): void
    {
        $observer = new ServicePostObserver();
        $bytes = 'LEDGER-IMG';
        $hash = hash('sha256', $bytes);
        $after = [
            'post_status' => 'publish', 'post_title' => 'Hero', 'tax_input' => [],
            'post_content' => '<img class="wp-image-' . MediaReferences::TOKEN_PREFIX . $hash . '" />',
            'meta' => [],
        ];

        $ledger = DependencyLedger::build('wp.service', $after, [], $observer);
        $status = [];
        foreach ($ledger['entries'] as $entry) {
            $status[$entry['ref']] = $entry['status'];
        }
        $this->assertSame('unresolved', $status[MediaReferences::TOKEN_PREFIX . $hash], 'Content the target lacks blocks.');
        $this->assertContains('dependency_unresolved:wp.media:' . MediaReferences::TOKEN_PREFIX . $hash, $ledger['blocking']);

        // The target already holds a byte-identical attachment: the reference is present.
        $att = $this->make_attachment($bytes, 'ledger.png');
        MediaReferences::tokenize(['meta' => ['_thumbnail_id' => [(string) $att]]]); // caches the hash
        $ledger = DependencyLedger::build('wp.service', $after, [], $observer);
        $entry = $ledger['entries'][0];
        $this->assertSame('present', $entry['status']);
        $this->assertSame('attachment:' . $att, $entry['detail']);
        $this->assertSame([], $ledger['blocking']);
    }

    public function test_a_media_bearing_post_converges_to_the_source_hash_on_a_target_that_holds_the_bytes(): void
    {
        $observer = new ServicePostObserver();
        $bytes = 'CONVERGE-IMG';
        $att = $this->make_attachment($bytes, 'converge.png');
        $url = (string) wp_get_attachment_url($att);

        $source = wp_insert_post([
            'post_type' => 'service', 'post_status' => 'publish', 'post_title' => 'Services',
            'post_content' => '<p>Hero</p><img class="wp-image-' . $att . '" src="' . esc_url($url) . '" />',
        ]);
        update_post_meta($source, 'vf_object_uid', 'svc-converge');
        update_post_meta($source, '_thumbnail_id', $att);
        $snap = $observer->snapshot(['storage_key' => (string) $source]);
        $this->assertSame('available', $snap['status'], wp_json_encode($snap));
        $source_hash = (string) $snap['semantic_hash'];
        $body = json_decode((string) $snap['canonical'], true);
        // The projection carries the token, not the local id.
        $this->assertStringContainsString(MediaReferences::TOKEN_PREFIX . hash('sha256', $bytes), (string) $snap['canonical']);
        $this->assertStringNotContainsString('wp-image-' . $att, (string) $snap['canonical']);

        // Free the source's slug so the applied post is byte-identical, then apply the
        // source projection onto a fresh target that already holds the same attachment.
        wp_delete_post($source, true);
        $target = wp_insert_post(['post_type' => 'service', 'post_status' => 'draft', 'post_title' => 'placeholder', 'post_content' => '']);
        update_post_meta($target, 'vf_object_uid', 'svc-converge-target');
        $written = $observer->apply_body($target, $body);
        $this->assertTrue($written === true, is_string($written) ? $written : '');

        // The live post carries this site's real id/URL again...
        $this->assertSame((string) $att, (string) get_post_meta($target, '_thumbnail_id', true));
        $this->assertStringContainsString('wp-image-' . $att, (string) get_post($target)->post_content);
        // ...and its projection re-derives the same content-hash token, so it converges.
        $target_snap = $observer->snapshot(['storage_key' => (string) $target]);
        $this->assertSame($source_hash, (string) $target_snap['semantic_hash'], 'A media-bearing post converges to the source after_hash.');
    }

    public function test_collect_for_transport_inlines_small_and_carries_oversize_as_fetch(): void
    {
        // A2-2b: a file over the inline cap but within the pull ceiling is no
        // longer deferred — it is carried as a `fetch` descriptor (no inline
        // bytes) for the streamed pull channel. A genuinely missing attachment is
        // still deferred.
        $ok_bytes = 'OK-BYTES';
        $ok = $this->make_attachment($ok_bytes, 'ok.png');
        $big = $this->make_attachment(str_repeat('x', Protocol::MAX_MEDIA_FILE_BYTES + 1), 'big.png');
        MediaReferences::tokenize(['meta' => ['_thumbnail_id' => [(string) $ok]], 'post_content' => '<img class="wp-image-' . $big . '" />']);
        $ok_hash = hash('sha256', $ok_bytes);
        $big_hash = (string) get_post_meta($big, MediaReferences::HASH_META, true);
        $missing_hash = str_repeat('a', 64);

        $body = [
            'post_content' => '<img class="wp-image-' . MediaReferences::TOKEN_PREFIX . $big_hash . '" /> <img class="wp-image-' . MediaReferences::TOKEN_PREFIX . $missing_hash . '" />',
            'meta' => ['_thumbnail_id' => [MediaReferences::TOKEN_PREFIX . $ok_hash]],
        ];
        $bundle = MediaReferences::collect_for_transport($body);

        $media = [];
        foreach ($bundle['media'] as $entry) {
            $media[$entry['hash']] = $entry;
        }
        $this->assertCount(2, $bundle['media'], 'the small file is inlined and the oversize file is carried as fetch');

        // Small file: inlined with bytes, no fetch marker.
        $this->assertSame($ok_bytes, base64_decode($media['sha256:' . $ok_hash]['bytes']));
        $this->assertArrayNotHasKey('fetch', $media['sha256:' . $ok_hash]);

        // Oversize within the pull ceiling: a fetch descriptor, no inline bytes.
        $this->assertTrue($media['sha256:' . $big_hash]['fetch']);
        $this->assertArrayNotHasKey('bytes', $media['sha256:' . $big_hash]);

        $reasons = [];
        foreach ($bundle['deferred'] as $entry) {
            $reasons[$entry['ref']] = $entry['reason'];
        }
        $this->assertSame('source_missing', $reasons[MediaReferences::TOKEN_PREFIX . $missing_hash]);
        $this->assertArrayNotHasKey(MediaReferences::TOKEN_PREFIX . $big_hash, $reasons, 'oversize within the ceiling is no longer deferred');
    }

    public function test_descriptor_carries_a_sha256_hash_and_facts(): void
    {
        $bytes = 'DESCRIPTOR-BYTES';
        $att = $this->make_attachment($bytes, 'descriptor.png');
        update_post_meta($att, '_wp_attachment_image_alt', 'Alt words');

        $descriptor = MediaReferences::descriptor($att);
        $this->assertIsArray($descriptor);
        $this->assertSame($att, $descriptor['source_id']);
        $this->assertSame('sha256:' . hash('sha256', $bytes), $descriptor['hash']);
        $this->assertSame(strlen($bytes), $descriptor['filesize']);
        $this->assertSame(basename((string) get_attached_file($att)), $descriptor['filename']);
        $this->assertStringContainsString('image/', $descriptor['mime']);
        $this->assertSame('Alt words', $descriptor['alt']);
        $this->assertSame('A caption', $descriptor['caption']);
        $this->assertNull(MediaReferences::descriptor(0), 'A non-attachment id has no descriptor.');
    }

    public function test_ledger_reports_carried_media_present_even_when_absent_locally(): void
    {
        $observer = new ServicePostObserver();
        $hash = hash('sha256', 'CARRIED');
        $after = [
            'post_status' => 'publish', 'post_title' => 'Hero', 'tax_input' => [],
            'post_content' => '<img class="wp-image-' . MediaReferences::TOKEN_PREFIX . $hash . '" />',
            'meta' => [],
        ];

        // Not present locally and not carried -> blocks.
        $ledger = DependencyLedger::build('wp.service', $after, [], $observer, []);
        $this->assertSame('unresolved', $ledger['entries'][0]['status']);

        // The release carries its bytes -> present (apply will sideload it), does not block.
        $ledger = DependencyLedger::build('wp.service', $after, [], $observer, ['sha256:' . $hash]);
        $this->assertSame('present', $ledger['entries'][0]['status']);
        $this->assertSame('carried', $ledger['entries'][0]['detail']);
        $this->assertSame([], $ledger['blocking']);
    }

    public function test_materialize_sideloads_absent_media_then_reuses_it(): void
    {
        $bytes = 'MATERIALIZE-ME';
        $hash = hash('sha256', $bytes);
        $entry = [
            'source_id' => 4242, 'hash' => 'sha256:' . $hash, 'mime' => 'image/png',
            'filename' => 'landed.png', 'url' => 'https://studio-src.example/wp-content/uploads/2026/09/landed.png',
            'title' => 'Landed', 'alt' => 'Landed alt', 'caption' => 'Landed cap',
            'bytes' => base64_encode($bytes),
        ];

        $this->assertSame(0, MediaReferences::local_by_hash($hash));
        $first = MediaMaterializer::materialize([$entry]);
        $this->assertSame([], $first['errors']);
        $this->assertCount(1, $first['created']);
        $this->assertSame([], $first['reused']);
        $new = MediaReferences::local_by_hash($hash);
        $this->assertGreaterThan(0, $new, 'The bytes were sideloaded and hash-indexed.');
        $this->assertSame($bytes, file_get_contents((string) get_attached_file($new)));
        $this->assertSame('4242', (string) get_post_meta($new, '_dbvc_original_attachment_id', true));
        $this->assertSame('Landed alt', (string) get_post_meta($new, '_wp_attachment_image_alt', true));

        // A second pass reuses the byte-identical attachment rather than duplicating it.
        $second = MediaMaterializer::materialize([$entry]);
        $this->assertSame([], $second['created']);
        $this->assertSame([$hash => $new], $second['reused']);
    }

    public function test_materialize_rejects_bytes_that_do_not_match_the_declared_hash(): void
    {
        $declared = hash('sha256', 'DECLARED');
        $entry = ['source_id' => 1, 'hash' => 'sha256:' . $declared, 'mime' => 'image/png', 'filename' => 'tamper.png', 'bytes' => base64_encode('DIFFERENT')];

        $result = MediaMaterializer::materialize([$entry]);
        $this->assertSame([], $result['created']);
        $this->assertSame(['hash_mismatch'], array_values($result['errors']));
        $this->assertSame(0, MediaReferences::local_by_hash($declared), 'Nothing is created for a hash mismatch.');
    }

    /**
     * Create a real attachment backed by an on-disk file in the uploads dir.
     *
     * @param string $bytes
     * @param string $name
     * @return int
     */
    private function make_attachment($bytes, $name)
    {
        $upload = wp_upload_bits($name, null, $bytes);
        $this->assertFalse((bool) ($upload['error'] ?? false), is_string($upload['error'] ?? null) ? (string) $upload['error'] : '');
        $id = wp_insert_attachment([
            'post_mime_type' => (string) $upload['type'],
            'post_title' => 'Attachment',
            'post_excerpt' => 'A caption',
            'post_status' => 'inherit',
        ], (string) $upload['file']);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        return (int) $id;
    }
}
