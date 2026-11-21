<?php

use Dbvc\Connected\Adapters\PostTypeObserver;
use Dbvc\Connected\Adapters\ServicePostObserver;
use Dbvc\Connected\Preparation\DependencyLedger;
use Dbvc\Connected\Preparation\MediaReferences;
use Dbvc\Connected\Preparation\MetaMediaReferences;

// Stub `acf_get_field` (same fixture-registry pattern the Visual Editor tests
// use; PHP defines it once regardless of load order). Reads
// `$GLOBALS['vfe_test_acf_fields']`, seeded per test.
if (! function_exists('acf_get_field')) {
    function acf_get_field($selector)
    {
        $fields = isset($GLOBALS['vfe_test_acf_fields']) && is_array($GLOBALS['vfe_test_acf_fields'])
            ? $GLOBALS['vfe_test_acf_fields']
            : [];
        return isset($fields[$selector]) && is_array($fields[$selector])
            ? $fields[$selector]
            : null;
    }
}

/**
 * Id-bearing post meta (ACF image/file/gallery fields, or filter-designated
 * keys) travels as a content-hash token (`dbvc-media-id:<sha256>`) so a
 * re-uploaded-but-identical image is not a content change (2026-11-20 real-pair
 * dry run: `intro_section_image` 84137 → 84291). Apply resolves the
 * self-describing token back to this site's attachment id by hash, with no
 * field knowledge; an unresolvable token stays literal (fails closed).
 *
 * @group connected-environments
 */
class ConnectedMetaMediaReferencesTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        register_post_type('service', ['public' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
        $GLOBALS['vfe_test_acf_fields'] = [
            'field_hero' => ['key' => 'field_hero', 'name' => 'hero_image', 'type' => 'image'],
            'field_brochure' => ['key' => 'field_brochure', 'name' => 'brochure', 'type' => 'file'],
            'field_gallery' => ['key' => 'field_gallery', 'name' => 'gallery', 'type' => 'gallery'],
            'field_blurb' => ['key' => 'field_blurb', 'name' => 'blurb', 'type' => 'text'],
            'field_rank' => ['key' => 'field_rank', 'name' => 'rank', 'type' => 'number'],
        ];
    }

    public function tear_down(): void
    {
        unset($GLOBALS['vfe_test_acf_fields']);
        unregister_post_type('service');
        parent::tear_down();
    }

    public function test_media_meta_keys_discovers_acf_image_file_gallery_and_honours_the_filter(): void
    {
        $meta = [
            'hero_image' => ['12'], '_hero_image' => ['field_hero'],
            'brochure' => ['13'], '_brochure' => ['field_brochure'],
            'gallery' => [[14, 15]], '_gallery' => ['field_gallery'],
            'blurb' => ['Hello'], '_blurb' => ['field_blurb'],
            'rank' => ['7'], '_rank' => ['field_rank'],
            'orphan_number' => ['99'], // no ACF reference → not media
            '_thumbnail_id' => ['16'],
        ];
        $this->assertSame(['hero_image', 'brochure', 'gallery'], MetaMediaReferences::media_meta_keys($meta, 'service'));

        $filter = static function ($keys, $post_type) {
            return $post_type === 'service' ? array_merge($keys, ['orphan_number', '_thumbnail_id']) : $keys;
        };
        add_filter('dbvc_connected_post_media_meta_keys', $filter, 10, 2);
        try {
            // The filter adds a key; _thumbnail_id is always MediaReferences' and is dropped.
            $this->assertSame(['hero_image', 'brochure', 'gallery', 'orphan_number'], MetaMediaReferences::media_meta_keys($meta, 'service'));
            $this->assertSame(['hero_image', 'brochure', 'gallery'], MetaMediaReferences::media_meta_keys($meta, 'page'));
        } finally {
            remove_filter('dbvc_connected_post_media_meta_keys', $filter, 10);
        }
    }

    public function test_tokenize_rewrites_only_designated_keys_and_leaves_unresolvable_ids_literal(): void
    {
        $hero_bytes = 'HERO';
        $hero = $this->make_attachment($hero_bytes, 'hero.png');
        $g1_bytes = 'GALLERY-1';
        $g1 = $this->make_attachment($g1_bytes, 'g1.png');
        $body = ['meta' => [
            'hero_image' => [(string) $hero],
            'gallery' => [[$g1, 99000001]], // second id does not exist here
            'rank' => [(string) $hero], // a number field holding a value that happens to be an id: not designated
        ]];

        $tokenized = MetaMediaReferences::tokenize($body, ['hero_image', 'gallery']);

        $hero_token = MetaMediaReferences::TOKEN_PREFIX . hash('sha256', $hero_bytes);
        $g1_token = MetaMediaReferences::TOKEN_PREFIX . hash('sha256', $g1_bytes);
        $this->assertSame([$hero_token], $tokenized['meta']['hero_image']);
        $this->assertSame([[$g1_token, 99000001]], $tokenized['meta']['gallery'], 'Gallery ids are tokenized in place; an unresolvable id stays literal.');
        $this->assertSame([(string) $hero], $tokenized['meta']['rank'], 'Undesignated keys are untouched.');
        $this->assertEqualsCanonicalizing([hash('sha256', $hero_bytes), hash('sha256', $g1_bytes)], MetaMediaReferences::tokens_in_body($tokenized));
        // Idempotent.
        $this->assertSame($tokenized, MetaMediaReferences::tokenize($tokenized, ['hero_image', 'gallery']));
        // Distinct from the string-media token, so MediaReferences never rewrites it to a URL.
        $this->assertSame([], MediaReferences::tokens_in_body($tokenized));
        $this->assertSame($tokenized, MediaReferences::detokenize($tokenized));
    }

    public function test_detokenize_resolves_by_hash_without_field_knowledge_and_fails_closed(): void
    {
        $bytes = 'RESOLVE-ME';
        $att = $this->make_attachment($bytes, 'resolve.png');
        MediaReferences::content_hash($att); // caches the hash on the attachment
        $token = MetaMediaReferences::TOKEN_PREFIX . hash('sha256', $bytes);
        $missing = MetaMediaReferences::TOKEN_PREFIX . str_repeat('b', 64);

        $restored = MetaMediaReferences::detokenize(['meta' => [
            'hero_image' => [$token],
            'gallery' => [[$token, $missing]],
        ]]);

        $this->assertSame([(string) $att], $restored['meta']['hero_image'], 'A scalar meta value is restored as a string id.');
        $this->assertSame([[$att, $missing]], $restored['meta']['gallery'], 'A gallery id is restored as an int; the unknown token stays literal.');
        $this->assertSame([str_repeat('b', 64)], MetaMediaReferences::tokens_in_body($restored), 'The unresolved reference is still visible (fails closed).');
    }

    public function test_acf_image_post_projects_identity_independently_and_converges_on_apply(): void
    {
        $observer = new ServicePostObserver();
        $bytes = 'ACF-HERO';
        $a = $this->make_attachment($bytes, 'hero-a.png');
        $b = $this->make_attachment($bytes, 'hero-b.png'); // same bytes, different id (a re-upload)
        $this->assertNotSame($a, $b);

        $one = $this->make_service('Implants', 'svc-acf-1', ['hero_image' => $a, '_hero_image' => 'field_hero', 'blurb' => 'Same']);
        $two = $this->make_service('Implants', 'svc-acf-2', ['hero_image' => $b, '_hero_image' => 'field_hero', 'blurb' => 'Same']);

        $snap_one = $observer->snapshot(['storage_key' => (string) $one]);
        $snap_two = $observer->snapshot(['storage_key' => (string) $two]);
        $this->assertSame('available', $snap_one['status'], wp_json_encode($snap_one));
        $token = MetaMediaReferences::TOKEN_PREFIX . hash('sha256', $bytes);
        $this->assertStringContainsString($token, (string) $snap_one['canonical']);
        $this->assertStringNotContainsString('"' . $a . '"', (string) $snap_one['canonical'], 'The local id is gone from the projection.');
        $body_one = json_decode((string) $snap_one['canonical'], true);
        $body_two = json_decode((string) $snap_two['canonical'], true);
        $this->assertSame($body_one['meta']['hero_image'], $body_two['meta']['hero_image'], 'Two attachments with the same bytes project the same token.');

        // The ACF field reference itself still travels (it is identical across clones).
        $this->assertSame(['field_hero'], $body_one['meta']['_hero_image']);

        // Transport carries the referenced bytes under the shared media channel.
        $bundle = $observer->collect_transport_media($body_one);
        $this->assertCount(1, $bundle['media']);
        $this->assertSame('sha256:' . hash('sha256', $bytes), $bundle['media'][0]['hash']);
        $this->assertSame($bytes, base64_decode($bundle['media'][0]['bytes']));
        // The channel is shared with string media, so the descriptor's informational
        // ref carries the channel's own prefix; the hash is the identity.
        $this->assertSame([MediaReferences::TOKEN_PREFIX . hash('sha256', $bytes)], $bundle['media'][0]['refs']);

        // Apply the first post's body onto a fresh target: the token resolves to a
        // local attachment holding the bytes and the projection converges. (Free
        // the slug first so the applied post is byte-identical.)
        wp_delete_post($one, true);
        wp_delete_post($two, true);
        $target = $this->make_service('placeholder', 'svc-acf-target', []);
        $written = $observer->apply_body($target, $body_one);
        $this->assertTrue($written === true, is_string($written) ? $written : '');
        $live = (string) get_post_meta($target, 'hero_image', true);
        $this->assertContains((int) $live, [$a, $b], 'The live post carries a real local attachment id again.');
        $this->assertSame($bytes, (string) file_get_contents((string) get_attached_file((int) $live)));
        $this->assertSame((string) $snap_one['semantic_hash'], (string) $observer->snapshot(['storage_key' => (string) $target])['semantic_hash']);
    }

    public function test_filter_designated_key_is_tokenized_without_acf(): void
    {
        $observer = new ServicePostObserver();
        $bytes = 'PLAIN-META';
        $att = $this->make_attachment($bytes, 'plain.png');
        $post = $this->make_service('Whitening', 'svc-plain', ['card_image' => $att]);

        $before = json_decode((string) $observer->snapshot(['storage_key' => (string) $post])['canonical'], true);
        $this->assertSame([(string) $att], $before['meta']['card_image'], 'No ACF reference, no filter: a plain number is content.');

        $filter = static function ($keys) {
            return array_merge($keys, ['card_image']);
        };
        add_filter('dbvc_connected_post_media_meta_keys', $filter);
        try {
            $after = json_decode((string) $observer->snapshot(['storage_key' => (string) $post])['canonical'], true);
            $this->assertSame([MetaMediaReferences::TOKEN_PREFIX . hash('sha256', $bytes)], $after['meta']['card_image']);
        } finally {
            remove_filter('dbvc_connected_post_media_meta_keys', $filter);
        }
    }

    public function test_dependency_ledger_tracks_id_meta_tokens(): void
    {
        $observer = new ServicePostObserver();
        $bytes = 'LEDGER-ACF';
        $hash = hash('sha256', $bytes);
        $ref = MetaMediaReferences::TOKEN_PREFIX . $hash;
        $after = ['post_status' => 'publish', 'post_title' => 'Hero', 'tax_input' => [], 'post_content' => '', 'meta' => ['hero_image' => [$ref]]];

        $ledger = DependencyLedger::build('wp.service', $after, [], $observer);
        $status = [];
        foreach ($ledger['entries'] as $entry) {
            $status[$entry['ref']] = $entry['status'];
        }
        $this->assertSame('unresolved', $status[$ref], 'Content the target lacks blocks.');
        $this->assertContains('dependency_unresolved:wp.media:' . $ref, $ledger['blocking']);

        // Carried by the release: present (apply sideloads it).
        $ledger = DependencyLedger::build('wp.service', $after, [], $observer, ['sha256:' . $hash]);
        $this->assertSame('present', $ledger['entries'][0]['status']);
        $this->assertSame('carried', $ledger['entries'][0]['detail']);

        // Already held locally: present by attachment.
        $att = $this->make_attachment($bytes, 'ledger-acf.png');
        MediaReferences::content_hash($att);
        $ledger = DependencyLedger::build('wp.service', $after, [], $observer);
        $this->assertSame('attachment:' . $att, $ledger['entries'][0]['detail']);
        $this->assertSame([], $ledger['blocking']);
        $this->assertSame([$hash], PostTypeObserver::post_media_hashes($after));
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function make_service($title, $uid, array $meta)
    {
        $id = wp_insert_post(['post_type' => 'service', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '<p>Body</p>']);
        $this->assertIsInt($id);
        update_post_meta($id, 'vf_object_uid', $uid);
        foreach ($meta as $key => $value) {
            update_post_meta($id, $key, $value);
        }

        return (int) $id;
    }

    private function make_attachment($bytes, $name)
    {
        $upload = wp_upload_bits($name, null, $bytes);
        $this->assertFalse((bool) ($upload['error'] ?? false), is_string($upload['error'] ?? null) ? (string) $upload['error'] : '');
        $id = wp_insert_attachment([
            'post_mime_type' => (string) $upload['type'],
            'post_title' => 'Attachment',
            'post_status' => 'inherit',
        ], (string) $upload['file']);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        return (int) $id;
    }
}
