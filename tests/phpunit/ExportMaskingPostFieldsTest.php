<?php

class ExportMaskingPostFieldsTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        update_option('dbvc_mask_action', 'remove');
        update_option('dbvc_mask_placeholder', '***');
        update_option('dbvc_mask_meta_keys', '');
        update_option('dbvc_mask_subkeys', '');
        update_option('dbvc_mask_post_fields', []);
    }

    public function tear_down(): void
    {
        delete_option('dbvc_mask_action');
        delete_option('dbvc_mask_placeholder');
        delete_option('dbvc_mask_meta_keys');
        delete_option('dbvc_mask_subkeys');
        delete_option('dbvc_mask_post_fields');
        parent::tear_down();
    }

    public function test_export_mask_post_fields_excludes_protected_identity_fields(): void
    {
        update_option('dbvc_mask_post_fields', ['post_date', 'vf_object_uid', 'post_modified']);

        $fields = dbvc_get_export_mask_post_fields();

        $this->assertSame(['post_date', 'post_modified'], $fields);
    }

    public function test_post_field_masking_helper_removes_selected_root_fields(): void
    {
        $payload = [
            'ID'            => 25,
            'vf_object_uid' => 'export-mask-uid',
            'post_date'     => '2026-04-07 10:00:00',
            'post_modified' => '2026-04-07 12:30:00',
            'post_excerpt'  => 'Short summary',
            'meta'          => [
                'keep_me' => ['value'],
            ],
        ];

        $masked = dbvc_mask_apply_to_post_fields($payload, ['post_date', 'post_modified']);

        $this->assertArrayNotHasKey('post_date', $masked);
        $this->assertArrayNotHasKey('post_modified', $masked);
        $this->assertSame('export-mask-uid', $masked['vf_object_uid']);
        $this->assertSame('Short summary', $masked['post_excerpt']);
        $this->assertSame(['value'], $masked['meta']['keep_me']);
    }

    public function test_default_export_filter_does_not_remove_root_post_fields_without_combined_mode_callback(): void
    {
        update_option('dbvc_mask_meta_keys', "secret_meta\n");

        $payload = [
            'ID'            => 42,
            'vf_object_uid' => 'export-mask-default-filter',
            'post_date'     => '2026-04-07 09:00:00',
            'post_modified' => '2026-04-07 11:00:00',
            'meta'          => [
                'secret_meta' => ['remove-me'],
                'keep_meta'   => ['keep-me'],
            ],
        ];

        $filtered = apply_filters('dbvc_export_post_data', $payload, 42, (object) ['ID' => 42, 'post_type' => 'page']);

        $this->assertArrayHasKey('post_date', $filtered);
        $this->assertArrayHasKey('post_modified', $filtered);
        $this->assertArrayHasKey('vf_object_uid', $filtered);
        $this->assertArrayNotHasKey('secret_meta', $filtered['meta']);
        $this->assertArrayHasKey('keep_meta', $filtered['meta']);
    }

    public function test_combined_mode_callback_masks_defaults_and_selected_root_post_fields(): void
    {
        update_option('dbvc_mask_meta_keys', "secret_meta\n");
        update_option('dbvc_mask_post_fields', ['post_date', 'vf_object_uid']);

        $callback = static function (array $data) {
            return dbvc_mask_apply_to_post_fields($data, dbvc_get_export_mask_post_fields());
        };
        add_filter('dbvc_export_post_data', $callback, 25, 3);

        $payload = [
            'ID'            => 77,
            'vf_object_uid' => 'export-mask-combined-mode',
            'post_date'     => '2026-04-07 08:00:00',
            'post_modified' => '2026-04-07 08:30:00',
            'meta'          => [
                'secret_meta' => ['remove-me'],
                'keep_meta'   => ['keep-me'],
            ],
        ];

        $filtered = apply_filters('dbvc_export_post_data', $payload, 77, (object) ['ID' => 77, 'post_type' => 'page']);

        remove_filter('dbvc_export_post_data', $callback, 25);

        $this->assertArrayNotHasKey('post_date', $filtered);
        $this->assertArrayHasKey('post_modified', $filtered);
        $this->assertSame('export-mask-combined-mode', $filtered['vf_object_uid']);
        $this->assertArrayNotHasKey('secret_meta', $filtered['meta']);
        $this->assertArrayHasKey('keep_meta', $filtered['meta']);
    }
}
