<?php

/**
 * Portable WS Form references carried in post meta.
 */
class WSFormReferenceRemapTest extends WP_UnitTestCase
{
    /** @var array<int, array{string, callable, int}> */
    private $added_filters = [];

    public function set_up(): void
    {
        parent::set_up();
        update_option(DBVC_WSForm_Reference_Remap::OPTION_ENABLED, '1');
    }

    public function tear_down(): void
    {
        foreach ($this->added_filters as [$tag, $cb, $priority]) {
            remove_filter($tag, $cb, $priority);
        }
        $this->added_filters = [];
        delete_option(DBVC_WSForm_Reference_Remap::OPTION_ENABLED);
        parent::tear_down();
    }

    private function filter(string $tag, callable $cb, int $priority = 10, int $args = 2): void
    {
        add_filter($tag, $cb, $priority, $args);
        $this->added_filters[] = [$tag, $cb, $priority];
    }

    /**
     * Stand in for WS Form: $uids maps local form id => uid.
     *
     * @param array<int, string> $uids
     */
    private function stub_provider(array $uids): void
    {
        $this->filter('dbvc_wsform_reference_provider_available', static function () {
            return true;
        }, 10, 1);

        $this->filter('dbvc_wsform_reference_form_uid', static function ($uid, $form_id) use ($uids) {
            return $uids[(int) $form_id] ?? '';
        });

        $this->filter('dbvc_wsform_reference_form_entity', static function ($entity, $form_id) {
            return ['label' => 'Form ' . (int) $form_id, 'status' => 'publish'];
        });

        $this->filter('dbvc_wsform_reference_resolve_uid', static function ($resolved, $uid) use ($uids) {
            $local = array_search((string) $uid, $uids, true);
            return $local === false ? 0 : (int) $local;
        });
    }

    public function test_export_records_uid_for_each_reference(): void
    {
        $this->stub_provider([2 => 'uid-contact', 3 => 'uid-reviews']);

        $refs = DBVC_WSForm_Reference_Remap::build_export_refs([
            'custom_wsform' => ['2'],
            'core_allpostypes_controls_objects_custom_wsform' => ['3'],
        ]);

        $this->assertCount(2, $refs);
        $this->assertSame('custom_wsform', $refs[0]['meta_key']);
        $this->assertSame(2, $refs[0]['source_form_id']);
        $this->assertSame('uid-contact', $refs[0]['uid']);
        $this->assertSame('Form 2', $refs[0]['label']);
        $this->assertSame('uid-reviews', $refs[1]['uid']);
    }

    public function test_export_skips_empty_and_non_numeric_values(): void
    {
        $this->stub_provider([2 => 'uid-contact']);

        $refs = DBVC_WSForm_Reference_Remap::build_export_refs([
            'custom_wsform' => [''],
            'core_allpostypes_controls_objects_custom_wsform' => ['not-an-id'],
        ]);

        $this->assertSame([], $refs);
    }

    public function test_export_ignores_acf_companion_keys(): void
    {
        $this->stub_provider([2 => 'uid-contact']);

        $refs = DBVC_WSForm_Reference_Remap::build_export_refs([
            '_custom_wsform' => ['field_6611f3a38d8d0'],
        ]);

        $this->assertSame([], $refs);
        $this->assertNotContains('_custom_wsform', DBVC_WSForm_Reference_Remap::get_reference_meta_keys());
    }

    public function test_export_skips_a_reference_to_a_form_absent_locally(): void
    {
        // Regression (S2b): post meta routinely carries a foreign site's form
        // ID, because the auto-export on post status transition runs before an
        // import has remapped it. Minting a uid for that ID wrote an orphan
        // meta row and claimed the uid against an ID a later form could take.
        $this->stub_provider([2 => 'uid-contact']);

        $refs = DBVC_WSForm_Reference_Remap::build_export_refs([
            'custom_wsform' => ['21'],
        ]);

        $this->assertSame([], $refs, 'a form absent locally must not produce a sidecar entry');
    }

    public function test_export_mixes_present_and_absent_references(): void
    {
        $this->stub_provider([3 => 'uid-reviews']);

        $refs = DBVC_WSForm_Reference_Remap::build_export_refs([
            'custom_wsform' => ['21'],
            'core_allpostypes_controls_objects_custom_wsform' => ['3'],
        ]);

        $this->assertCount(1, $refs);
        $this->assertSame('core_allpostypes_controls_objects_custom_wsform', $refs[0]['meta_key']);
        $this->assertSame(3, $refs[0]['source_form_id']);
    }

    public function test_export_emits_nothing_without_a_provider(): void
    {
        $this->filter('dbvc_wsform_reference_provider_available', static function () {
            return false;
        }, 10, 1);

        $this->assertSame([], DBVC_WSForm_Reference_Remap::build_export_refs(['custom_wsform' => ['2']]));
    }

    public function test_import_remaps_reference_to_the_local_form_id(): void
    {
        // Source exported form id 2; locally that uid is form 77.
        $this->stub_provider([77 => 'uid-contact']);

        $post_id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, 'custom_wsform', '2');

        $meta = ['custom_wsform' => ['2']];
        $report = DBVC_WSForm_Reference_Remap::apply_import_refs($post_id, [[
            'meta_key'       => 'custom_wsform',
            'index'          => 0,
            'source_form_id' => 2,
            'uid'            => 'uid-contact',
            'label'          => 'Contact Form',
        ]], $meta);

        $this->assertSame('77', get_post_meta($post_id, 'custom_wsform', true));
        $this->assertSame(['77'], $meta['custom_wsform']);
        $this->assertCount(1, $report['remapped']);
        $this->assertSame(2, $report['remapped'][0]['source_form_id']);
        $this->assertSame(77, $report['remapped'][0]['local_form_id']);
        $this->assertSame([], $report['unresolved']);
    }

    public function test_import_leaves_raw_id_when_uid_is_unresolvable(): void
    {
        $this->stub_provider([]); // uid exists nowhere locally

        $post_id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, 'custom_wsform', '2');

        $meta = ['custom_wsform' => ['2']];
        $report = DBVC_WSForm_Reference_Remap::apply_import_refs($post_id, [[
            'meta_key'       => 'custom_wsform',
            'source_form_id' => 2,
            'uid'            => 'uid-missing',
            'label'          => 'Contact Form',
        ]], $meta);

        $this->assertSame('2', get_post_meta($post_id, 'custom_wsform', true), 'raw id must survive');
        $this->assertSame(['2'], $meta['custom_wsform'], 'sync file must be left alone');
        $this->assertSame([], $report['remapped']);
        $this->assertCount(1, $report['unresolved']);
        $this->assertSame('uid-missing', $report['unresolved'][0]['uid']);
    }

    public function test_import_is_a_noop_when_the_id_already_matches(): void
    {
        $this->stub_provider([2 => 'uid-contact']);

        $post_id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, 'custom_wsform', '2');

        $meta = ['custom_wsform' => ['2']];
        $report = DBVC_WSForm_Reference_Remap::apply_import_refs($post_id, [[
            'meta_key'       => 'custom_wsform',
            'source_form_id' => 2,
            'uid'            => 'uid-contact',
        ]], $meta);

        $this->assertSame('2', get_post_meta($post_id, 'custom_wsform', true));
        $this->assertSame([], $report['remapped']);
        $this->assertCount(1, $report['unchanged']);
    }

    public function test_import_rejects_keys_outside_the_registry(): void
    {
        $this->stub_provider([77 => 'uid-contact']);

        $post_id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, 'some_other_field', '2');

        $meta = ['some_other_field' => ['2']];
        $report = DBVC_WSForm_Reference_Remap::apply_import_refs($post_id, [[
            'meta_key'       => 'some_other_field',
            'source_form_id' => 2,
            'uid'            => 'uid-contact',
        ]], $meta);

        $this->assertSame('2', get_post_meta($post_id, 'some_other_field', true));
        $this->assertSame([], $report['remapped']);
    }

    public function test_import_does_nothing_when_disabled(): void
    {
        update_option(DBVC_WSForm_Reference_Remap::OPTION_ENABLED, '0');
        $this->stub_provider([77 => 'uid-contact']);

        $post_id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, 'custom_wsform', '2');

        $meta = ['custom_wsform' => ['2']];
        $report = DBVC_WSForm_Reference_Remap::apply_import_refs($post_id, [[
            'meta_key'       => 'custom_wsform',
            'source_form_id' => 2,
            'uid'            => 'uid-contact',
        ]], $meta);

        $this->assertSame('2', get_post_meta($post_id, 'custom_wsform', true));
        $this->assertSame([], $report['remapped']);
        $this->assertFalse(DBVC_WSForm_Reference_Remap::is_enabled());
    }

    public function test_remap_preserves_multi_value_references(): void
    {
        $this->stub_provider([77 => 'uid-a', 88 => 'uid-b']);

        $post_id = self::factory()->post->create(['post_type' => 'page']);
        add_post_meta($post_id, 'custom_wsform', '2');
        add_post_meta($post_id, 'custom_wsform', '5');

        $meta = ['custom_wsform' => ['2', '5']];
        DBVC_WSForm_Reference_Remap::apply_import_refs($post_id, [
            ['meta_key' => 'custom_wsform', 'source_form_id' => 2, 'uid' => 'uid-a'],
            ['meta_key' => 'custom_wsform', 'source_form_id' => 5, 'uid' => 'uid-b'],
        ], $meta);

        $this->assertSame(['77', '88'], get_post_meta($post_id, 'custom_wsform', false));
        $this->assertSame(['77', '88'], $meta['custom_wsform']);
    }

    public function test_third_party_import_is_ordered_before_the_post_loop(): void
    {
        // S3: post meta references are resolved during the post import by
        // portable uid, so the referenced entity must already exist locally.
        // Both orchestrators previously ran third-party import last, which made
        // every referencing post report unresolved until a second import run.
        $sync = file_get_contents(DBVC_PLUGIN_PATH . 'includes/class-sync-posts.php');
        $third_party = strpos($sync, 'DBVC_Third_Party_Portability::import_selected_entities_from_sync()');
        $post_loop   = strpos($sync, 'self::$suppress_export_on_import = true;' . "\n" . '        try {' . "\n" . '        foreach ($supported_types as $post_type) {');
        $this->assertNotFalse($third_party, 'import_all must import third-party entities');
        $this->assertNotFalse($post_loop, 'import_all post loop anchor must exist');
        $this->assertLessThan(
            $post_loop,
            $third_party,
            'import_all must import third-party entities BEFORE the post loop'
        );

        $cli = file_get_contents(DBVC_PLUGIN_PATH . 'commands/class-wp-cli-commands.php');
        $cli_third_party = strpos($cli, 'DBVC_Third_Party_Portability::import_selected_entities_from_sync()');
        $cli_batch       = strpos($cli, 'DBVC_Sync_Posts::import_posts_batch(');
        $this->assertNotFalse($cli_third_party);
        $this->assertNotFalse($cli_batch);
        $this->assertLessThan(
            $cli_batch,
            $cli_third_party,
            'the CLI batched import must import third-party entities BEFORE the batch loop'
        );
    }

    public function test_reference_meta_key_registry_is_filterable(): void
    {
        $cb = static function ($keys) {
            $keys[] = 'theme_contact_form';
            return $keys;
        };
        $this->filter('dbvc_wsform_reference_meta_keys', $cb, 10, 1);

        $keys = DBVC_WSForm_Reference_Remap::get_reference_meta_keys();
        $this->assertContains('custom_wsform', $keys);
        $this->assertContains('theme_contact_form', $keys);
    }

    public function test_export_filter_attaches_the_sidecar_to_post_payload(): void
    {
        $this->stub_provider([2 => 'uid-contact']);

        $post_id = self::factory()->post->create(['post_type' => 'page']);
        $data = apply_filters(
            'dbvc_export_post_data',
            ['meta' => ['custom_wsform' => ['2']]],
            $post_id,
            get_post($post_id)
        );

        $this->assertArrayHasKey(DBVC_WSForm_Reference_Remap::PAYLOAD_KEY, $data);
        $this->assertSame('uid-contact', $data[DBVC_WSForm_Reference_Remap::PAYLOAD_KEY][0]['uid']);
        $this->assertSame(['2'], $data['meta']['custom_wsform'], 'raw id stays in meta');
    }
}
