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
            ['id' => 'el2', 'name' => 'posts', 'parent' => 0, 'label' => 'loop', 'settings' => [
                'hasLoop' => true,
                'query' => ['post_type' => ['post'], 'posts_per_page' => 3, 'signature' => '95baa5352be9c2a1be038f1624227504', 'time' => 1793806336],
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

        $query = $body['meta']['_bricks_page_content_2'][0][1]['settings']['query'];
        $this->assertSame(['post_type' => ['post'], 'posts_per_page' => 3], $query, 'The query-loop cache stamp (signature/time) Bricks rewrites on every save is not design.');
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
            // Same loop, re-saved on site B: Bricks stamped a new cache signature/time.
            ['id' => 'el2', 'name' => 'posts', 'parent' => 0, 'label' => 'loop', 'settings' => [
                'hasLoop' => true,
                'query' => ['post_type' => ['post'], 'posts_per_page' => 3, 'signature' => 'b170d5d9dea3dc6874a00574cae01b38', 'time' => 1793835316],
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
    /**
     * A template whose Template element renders another template by local post id.
     *
     * @param int|string $ref  The referenced template's local id (or a token).
     * @param string     $key  `template` (current Bricks) or `templateId` (older exports).
     */
    private function make_referencing_template($ref, string $key = 'template', string $uid = 'tpl-parent'): int
    {
        $post_id = wp_insert_post(['post_type' => 'bricks_template', 'post_status' => 'publish', 'post_title' => 'Parent', 'post_name' => 'parent-' . $uid]);
        update_post_meta($post_id, 'vf_object_uid', $uid);
        update_post_meta($post_id, '_bricks_template_type', 'content');
        update_post_meta($post_id, '_bricks_page_content_2', [
            ['id' => 'root', 'name' => 'section', 'parent' => 0, 'settings' => ['template' => 'not-a-reference']], // a non-Template element: never touched
            ['id' => 'tpl', 'name' => 'template', 'parent' => 'root', 'settings' => [$key => $ref]],
        ]);

        return (int) $post_id;
    }

    private function make_referenced_template(string $uid = 'tpl-child'): int
    {
        $post_id = wp_insert_post(['post_type' => 'bricks_template', 'post_status' => 'publish', 'post_title' => 'Child ' . $uid, 'post_name' => 'child-' . $uid]);
        update_post_meta($post_id, 'vf_object_uid', $uid);
        update_post_meta($post_id, '_bricks_template_type', 'section');
        update_post_meta($post_id, '_bricks_page_content_2', [['id' => 'c1', 'name' => 'section', 'parent' => 0, 'settings' => []]]);

        return (int) $post_id;
    }

    public function test_projection_tokenizes_template_element_references_by_uid(): void
    {
        $child = $this->make_referenced_template('tpl-child');
        $parent = $this->make_referencing_template($child);
        $legacy = $this->make_referencing_template((string) $child, 'templateId', 'tpl-legacy');
        $orphan = $this->make_referencing_template(99000001, 'template', 'tpl-orphan');

        $observer = DomainRegistry::observer_for(self::DOMAIN);
        $token = ReferenceTokens::token(ReferenceTokens::KIND_TEMPLATE, 'tpl-child');

        $body = json_decode((string) $observer->snapshot(['storage_key' => (string) $parent])['canonical'], true);
        $elements = $body['meta']['_bricks_page_content_2'][0];
        $this->assertSame($token, $elements[1]['settings']['template'], 'The Template element references the child by portable uid.');
        $this->assertSame('not-a-reference', $elements[0]['settings']['template'], 'A `template` key on a non-Template element is content.');

        $body = json_decode((string) $observer->snapshot(['storage_key' => (string) $legacy])['canonical'], true);
        $this->assertSame($token, $body['meta']['_bricks_page_content_2'][0][1]['settings']['templateId'], 'The older templateId key is tokenized too.');

        $body = json_decode((string) $observer->snapshot(['storage_key' => (string) $orphan])['canonical'], true);
        $this->assertSame(99000001, $body['meta']['_bricks_page_content_2'][0][1]['settings']['template'], 'An unresolvable id stays literal.');
    }

    public function test_template_reference_projection_is_identity_independent_across_sites(): void
    {
        $child_a = $this->make_referenced_template('tpl-child');
        $parent = $this->make_referencing_template($child_a);
        $observer = DomainRegistry::observer_for(self::DOMAIN);
        $a = $observer->snapshot(['storage_key' => (string) $parent]);
        $this->assertSame('available', $a['status']);

        // "Site B": the same child template under a different local post id.
        wp_delete_post($child_a, true);
        $child_b = $this->make_referenced_template('tpl-child');
        $this->assertNotSame($child_a, $child_b);
        update_post_meta($parent, '_bricks_page_content_2', [
            ['id' => 'root', 'name' => 'section', 'parent' => 0, 'settings' => ['template' => 'not-a-reference']],
            ['id' => 'tpl', 'name' => 'template', 'parent' => 'root', 'settings' => ['template' => $child_b]],
        ]);
        DomainRegistry::reset_observers();
        $b = DomainRegistry::observer_for(self::DOMAIN)->snapshot(['storage_key' => (string) $parent]);

        $this->assertSame($a['semantic_hash'], $b['semantic_hash'], 'The parent hashes the same despite a different local child id.');
    }

    public function test_apply_body_detokenizes_template_references_and_ledger_tracks_them(): void
    {
        $child = $this->make_referenced_template('tpl-child');
        $parent = $this->make_referencing_template(12345); // stale content, overwritten by apply
        $token = ReferenceTokens::token(ReferenceTokens::KIND_TEMPLATE, 'tpl-child');
        $missing = ReferenceTokens::token(ReferenceTokens::KIND_TEMPLATE, 'tpl-nowhere');
        $body = [
            'post_status' => 'publish', 'post_title' => 'Parent', 'post_name' => 'parent-tpl-parent',
            'post_content' => '', 'post_excerpt' => '', 'post_date_gmt' => '2026-10-01 00:00:00', 'post_parent_uid' => '', 'menu_order' => 0,
            'tax_input' => [],
            'meta' => [
                '_bricks_template_type' => [0 => 'content'],
                '_bricks_page_content_2' => [0 => [['id' => 'tpl', 'name' => 'template', 'parent' => 0, 'settings' => ['template' => $token]]]],
            ],
        ];

        $observer = DomainRegistry::observer_for(self::DOMAIN);
        $result = $observer->apply_body($parent, $body);
        $this->assertTrue($result === true, is_string($result) ? $result : 'apply_body failed');
        $written = get_post_meta($parent, '_bricks_page_content_2', true);
        $this->assertSame((string) $child, (string) $written[0]['settings']['template'], 'Token resolved to the local child post id.');
        $re = json_decode((string) $observer->snapshot(['storage_key' => (string) $parent])['canonical'], true);
        $this->assertSame($token, $re['meta']['_bricks_page_content_2'][0][0]['settings']['template'], 'Re-projection re-derives the token.');

        // Ledger: present locally; selected when the release supplies it; unresolved (blocking) otherwise.
        $after = $body;
        $ledger = \Dbvc\Connected\Preparation\DependencyLedger::build(self::DOMAIN, $after, [], $observer);
        $entries = [];
        foreach ($ledger['entries'] as $entry) {
            $entries[$entry['ref']] = $entry;
        }
        $this->assertSame('present', $entries['tpl-child']['status']);
        $this->assertSame('post:' . $child, $entries['tpl-child']['detail']);
        $this->assertSame([], $ledger['blocking']);

        $after['meta']['_bricks_page_content_2'][0][0]['settings']['template'] = $missing;
        $ledger = \Dbvc\Connected\Preparation\DependencyLedger::build(self::DOMAIN, $after, [], $observer);
        $this->assertContains('dependency_unresolved:' . self::DOMAIN . ':tpl-nowhere', $ledger['blocking']);

        $release_items = [['domain' => self::DOMAIN, 'instance_uid' => 'tpl-nowhere', 'body' => '{}']];
        $ledger = \Dbvc\Connected\Preparation\DependencyLedger::build(self::DOMAIN, $after, $release_items, $observer);
        $entries = [];
        foreach ($ledger['entries'] as $entry) {
            $entries[$entry['ref']] = $entry;
        }
        $this->assertSame('selected', $entries['tpl-nowhere']['status']);
        $this->assertSame([], $ledger['blocking']);
    }
}
