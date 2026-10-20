<?php

use Dbvc\Connected\Adapters\BricksTemplatePostObserver;
use Dbvc\Connected\Adapters\DomainRegistry;
use Dbvc\Connected\Preparation\BricksMediaReferences;
use Dbvc\Connected\Preparation\MediaReferences;
use Dbvc\Connected\Preparation\ReferenceTokens;

/**
 * M9 slice 6d — the Bricks-aware `bricks_template` post observer. A template is
 * a DBVC-tracked post (identity via vf_object_uid, taxonomies/fields via
 * PostTypeObserver) whose element tree lives in `_bricks_page_content_2` meta
 * and references global classes by site-local id. The observer tokenizes those
 * references to portable name tokens before hashing (identity-independent
 * projection) and scopes the projection to the Bricks builder meta.
 *
 * @group connected-environments
 */
class ConnectedBricksTemplatesTest extends WP_UnitTestCase
{
    private const DOMAIN = 'wp.post:bricks_template';

    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_connected_bricks_available', '__return_true');
        register_post_type('bricks_template', ['public' => true, 'show_in_rest' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
        register_taxonomy('template_tag', 'bricks_template');
        update_option('dbvc_post_types', ['bricks_template']);
        update_option(DomainRegistry::OPTION_POST_DOMAINS, ['bricks_template']);
        delete_option('bricks_global_classes');
        DomainRegistry::reset_observers();
    }

    public function tear_down(): void
    {
        unregister_taxonomy('template_tag');
        unregister_post_type('bricks_template');
        delete_option('dbvc_post_types');
        delete_option(DomainRegistry::OPTION_POST_DOMAINS);
        delete_option('bricks_global_classes');
        DomainRegistry::reset_observers();
        remove_filter('dbvc_connected_bricks_available', '__return_true');
        parent::tear_down();
    }

    /**
     * @param array<int,string> $class_ids
     */
    private function make_template(array $class_ids): int
    {
        $post_id = wp_insert_post(['post_type' => 'bricks_template', 'post_status' => 'publish', 'post_title' => 'Listing — single', 'post_name' => 'listing-single']);
        update_post_meta($post_id, '_bricks_template_type', 'content');
        update_post_meta($post_id, '_bricks_page_content_2', [
            ['id' => 'el1', 'name' => 'section', 'parent' => 0, 'label' => 'root', 'settings' => ['_cssGlobalClasses' => $class_ids]],
        ]);
        // A non-Bricks meta key that must NOT ride along in a framework template push.
        update_post_meta($post_id, 'rank_math_score', '87');

        return (int) $post_id;
    }

    public function test_domain_routes_to_the_bricks_aware_observer(): void
    {
        $observer = DomainRegistry::observer_for(self::DOMAIN);
        $this->assertInstanceOf(BricksTemplatePostObserver::class, $observer);
        $this->assertSame('bricks_template', $observer->post_type());
    }

    public function test_projection_tokenizes_class_refs_and_scopes_to_bricks_meta(): void
    {
        update_option('bricks_global_classes', [['id' => 'local1', 'name' => 'core__section']]);
        $post_id = $this->make_template(['local1']);

        $snapshot = DomainRegistry::observer_for(self::DOMAIN)->snapshot(['storage_key' => (string) $post_id]);
        $this->assertSame('available', $snapshot['status'], wp_json_encode($snapshot));
        $body = json_decode((string) $snapshot['canonical'], true);

        // The class id is tokenized to its portable name in the content meta.
        $this->assertSame([ReferenceTokens::token('class', 'core__section')], $body['meta']['_bricks_page_content_2'][0][0]['settings']['_cssGlobalClasses']);
        // The projection is scoped to Bricks builder meta — site-specific meta is excluded.
        $this->assertArrayHasKey('_bricks_template_type', $body['meta']);
        $this->assertArrayNotHasKey('rank_math_score', $body['meta'], 'Non-Bricks meta is not projected.');
    }

    public function test_projection_is_identity_independent_across_sites(): void
    {
        // Site A: class core__section at local id local1.
        update_option('bricks_global_classes', [['id' => 'local1', 'name' => 'core__section']]);
        $post_id = $this->make_template(['local1']);
        $a = DomainRegistry::observer_for(self::DOMAIN)->snapshot(['storage_key' => (string) $post_id]);
        $this->assertSame('available', $a['status']);

        // Site B: same class NAME, different local id, and the template references the new id.
        update_option('bricks_global_classes', [['id' => 'ZZZZZZ', 'name' => 'core__section']]);
        update_post_meta($post_id, '_bricks_page_content_2', [
            ['id' => 'el1', 'name' => 'section', 'parent' => 0, 'label' => 'root', 'settings' => ['_cssGlobalClasses' => ['ZZZZZZ']]],
        ]);
        DomainRegistry::reset_observers();
        $b = DomainRegistry::observer_for(self::DOMAIN)->snapshot(['storage_key' => (string) $post_id]);

        $this->assertSame($a['semantic_hash'], $b['semantic_hash'], 'The template hashes the same despite a different local class id.');
    }

    public function test_apply_body_detokenizes_class_refs_and_reprojects_consistently(): void
    {
        // The target holds the class at ITS OWN local id.
        update_option('bricks_global_classes', [['id' => 'tgt42', 'name' => 'core__section']]);
        $post_id = $this->make_template(['oldid']); // existing content will be overwritten by apply

        // A release body (tokenized projection) that references the class by name token.
        $token = ReferenceTokens::token('class', 'core__section');
        $body = [
            'post_status' => 'publish', 'post_title' => 'Listing — single', 'post_name' => 'listing-single',
            'post_content' => '', 'post_excerpt' => '', 'post_date_gmt' => '2026-10-01 00:00:00', 'post_parent_uid' => '', 'menu_order' => 0,
            'tax_input' => [],
            'meta' => [
                '_bricks_template_type' => [0 => 'content'],
                '_bricks_page_content_2' => [0 => [['id' => 'el1', 'name' => 'section', 'parent' => 0, 'label' => 'root', 'settings' => ['_cssGlobalClasses' => [$token]]]]],
            ],
        ];

        $result = DomainRegistry::observer_for(self::DOMAIN)->apply_body($post_id, $body);
        $this->assertTrue($result === true, is_string($result) ? $result : 'apply_body failed');

        // The reference was detokenized to the TARGET local id on write.
        $written = get_post_meta($post_id, '_bricks_page_content_2', true);
        $this->assertSame(['tgt42'], $written[0]['settings']['_cssGlobalClasses'], 'Token resolved to the target local class id.');

        // Re-projection re-tokenizes the local id back to the name token, so the re-snapshot
        // hash equals the release after_hash — verify would pass and the template converges.
        $re = DomainRegistry::observer_for(self::DOMAIN)->snapshot(['storage_key' => (string) $post_id]);
        $re_body = json_decode((string) $re['canonical'], true);
        $this->assertSame([$token], $re_body['meta']['_bricks_page_content_2'][0][0]['settings']['_cssGlobalClasses']);
    }

    private function make_attachment(string $name, string $bytes): int
    {
        $upload = wp_upload_bits($name, null, $bytes);
        $this->assertEmpty($upload['error'] ?? '', (string) ($upload['error'] ?? ''));
        $att = wp_insert_attachment(['post_mime_type' => (string) $upload['type'], 'post_title' => 'Hero', 'post_status' => 'inherit'], (string) $upload['file']);

        return (int) $att;
    }

    private function make_media_template(int $att): int
    {
        $post_id = wp_insert_post(['post_type' => 'bricks_template', 'post_status' => 'publish', 'post_title' => 'Hero', 'post_name' => 'hero']);
        update_post_meta($post_id, '_bricks_template_type', 'content');
        update_post_meta($post_id, '_bricks_page_content_2', [
            ['id' => 'el1', 'name' => 'image', 'parent' => 0, 'label' => 'root', 'settings' => [
                'image' => ['id' => $att, 'url' => (string) wp_get_attachment_url($att), 'filename' => basename((string) get_attached_file($att)), 'size' => 'full'],
            ]],
        ]);
        update_post_meta($post_id, '_bricks_template_settings', ['templatePreviewPostId' => '107510', 'templatePreviewType' => 'single']);

        return (int) $post_id;
    }

    public function test_projection_tokenizes_structured_media_and_strips_editor_preview(): void
    {
        $bytes = 'TEMPLATE-HERO-IMAGE-BYTES';
        $hash = hash('sha256', $bytes);
        $att = $this->make_attachment('tmpl-hero.png', $bytes);
        $post_id = $this->make_media_template($att);

        $snapshot = DomainRegistry::observer_for(self::DOMAIN)->snapshot(['storage_key' => (string) $post_id]);
        $this->assertSame('available', $snapshot['status'], wp_json_encode($snapshot));
        $body = json_decode((string) $snapshot['canonical'], true);

        $img = $body['meta']['_bricks_page_content_2'][0][0]['settings']['image'];
        $this->assertSame(BricksMediaReferences::TOKEN_PREFIX . $hash, $img['id'], 'image.id is a content-hash token.');
        $this->assertArrayNotHasKey('url', $img, 'The site-local url is dropped from the projection.');
        $this->assertArrayNotHasKey('filename', $img, 'The site-local filename is dropped.');
        $this->assertSame('full', $img['size'], 'The portable size is kept.');

        $settings = $body['meta']['_bricks_template_settings'][0] ?? [];
        $this->assertArrayNotHasKey('templatePreviewPostId', $settings, 'The builder preview post id is stripped from the projection.');
        $this->assertArrayNotHasKey('templatePreviewType', $settings);
    }

    public function test_structured_media_projection_is_identity_independent_across_sites(): void
    {
        $bytes = 'SHARED-IMAGE-BYTES';
        $att_a = $this->make_attachment('a.png', $bytes);
        $post_id = $this->make_media_template($att_a);
        $a = DomainRegistry::observer_for(self::DOMAIN)->snapshot(['storage_key' => (string) $post_id]);
        $this->assertSame('available', $a['status'], wp_json_encode($a));

        // Site B: the SAME image bytes at a different attachment id, referenced by the template.
        $att_b = $this->make_attachment('b.png', $bytes);
        $this->assertNotSame($att_a, $att_b);
        update_post_meta($post_id, '_bricks_page_content_2', [
            ['id' => 'el1', 'name' => 'image', 'parent' => 0, 'label' => 'root', 'settings' => [
                'image' => ['id' => $att_b, 'url' => (string) wp_get_attachment_url($att_b), 'filename' => 'b.png', 'size' => 'full'],
            ]],
        ]);
        DomainRegistry::reset_observers();
        $b = DomainRegistry::observer_for(self::DOMAIN)->snapshot(['storage_key' => (string) $post_id]);

        $this->assertSame($a['semantic_hash'], $b['semantic_hash'], 'Same image bytes → same hash despite a different local attachment id.');
    }

    public function test_apply_body_detokenizes_media_to_the_local_attachment_and_reprojects(): void
    {
        // The target holds the image bytes at ITS OWN attachment id.
        $bytes = 'TARGET-HELD-BYTES';
        $hash = hash('sha256', $bytes);
        $target_att = $this->make_attachment('target.png', $bytes);
        // Prime the content-hash index the way observation/sideload would (records
        // _dbvc_media_hash so local_by_hash resolves the token to this attachment).
        $this->assertSame($hash, MediaReferences::content_hash($target_att));

        $post_id = wp_insert_post(['post_type' => 'bricks_template', 'post_status' => 'publish', 'post_title' => 'Hero', 'post_name' => 'hero']);
        $token = BricksMediaReferences::TOKEN_PREFIX . $hash;
        $body = [
            'post_status' => 'publish', 'post_title' => 'Hero', 'post_name' => 'hero',
            'post_content' => '', 'post_excerpt' => '', 'post_date_gmt' => '2026-10-01 00:00:00', 'post_parent_uid' => '', 'menu_order' => 0,
            'tax_input' => [],
            'meta' => [
                '_bricks_template_type' => [0 => 'content'],
                '_bricks_page_content_2' => [0 => [['id' => 'el1', 'name' => 'image', 'parent' => 0, 'label' => 'root', 'settings' => ['image' => ['id' => $token, 'size' => 'full']]]]],
            ],
        ];

        $result = DomainRegistry::observer_for(self::DOMAIN)->apply_body($post_id, $body);
        $this->assertTrue($result === true, is_string($result) ? $result : 'apply_body failed');

        // The token resolved to the target's local attachment id, and its url was reconstructed.
        $written = get_post_meta($post_id, '_bricks_page_content_2', true);
        $img = $written[0]['settings']['image'];
        $this->assertSame($target_att, (int) $img['id'], 'image.id resolved to the target local attachment id.');
        $this->assertStringContainsString('/uploads/', (string) $img['url'], 'The local url was reconstructed.');

        // Re-projection re-tokenizes to the same content-hash token → converges by hash.
        $re = DomainRegistry::observer_for(self::DOMAIN)->snapshot(['storage_key' => (string) $post_id]);
        $re_img = json_decode((string) $re['canonical'], true)['meta']['_bricks_page_content_2'][0][0]['settings']['image'];
        $this->assertSame($token, $re_img['id']);
    }

    public function test_collect_transport_media_carries_structured_image_bytes(): void
    {
        $bytes = 'CARRIED-TEMPLATE-BYTES';
        $hash = hash('sha256', $bytes);
        $att = $this->make_attachment('carry.png', $bytes);
        $post_id = $this->make_media_template($att);

        $observer = DomainRegistry::observer_for(self::DOMAIN);
        $body = json_decode((string) $observer->snapshot(['storage_key' => (string) $post_id])['canonical'], true);
        $bundle = $observer->collect_transport_media($body);

        $hashes = array_map(static function ($m) {
            return (string) ($m['hash'] ?? '');
        }, $bundle['media']);
        $this->assertContains('sha256:' . $hash, $hashes, 'The structured image bytes ride the media channel.');
        foreach ($bundle['media'] as $m) {
            if (($m['hash'] ?? '') === 'sha256:' . $hash) {
                $this->assertSame($bytes, base64_decode((string) $m['bytes']), 'The exact bytes are carried.');
            }
        }
    }
}
