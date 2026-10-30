<?php

// Guarded ACF shims. acf_get_field_groups() and get_field() follow the same
// global-backed contracts as the Visual Editor and R5.1-b test shims, so
// whichever file loads first behaves the same for every test.
if (! function_exists('acf_get_field_groups')) {
    function acf_get_field_groups()
    {
        return isset($GLOBALS['dbvc_test_acf_groups']) && is_array($GLOBALS['dbvc_test_acf_groups'])
            ? $GLOBALS['dbvc_test_acf_groups']
            : [];
    }
}

if (! function_exists('acf_get_field_objects')) {
    function acf_get_field_objects($post_id = false)
    {
        return isset($GLOBALS['dbvc_test_acf_field_objects'][$post_id]) && is_array($GLOBALS['dbvc_test_acf_field_objects'][$post_id])
            ? $GLOBALS['dbvc_test_acf_field_objects'][$post_id]
            : false;
    }
}

if (! function_exists('get_field')) {
    function get_field($selector, $object_id = false, $format = true)
    {
        unset($format);
        if ($object_id !== 'option') {
            return null;
        }
        $rows = isset($GLOBALS['vfe_test_acf_option_rows']) && is_array($GLOBALS['vfe_test_acf_option_rows'])
            ? $GLOBALS['vfe_test_acf_option_rows']
            : [];
        return isset($rows[$selector]) ? $rows[$selector] : null;
    }
}

/**
 * Security finding S1, follow-up: sync-folder writers other than options.json.
 *
 * The sync folder sits under ABSPATH and nginx ignores its .htaccess, so every
 * file written there must be treated as public: ACF options-group files,
 * Entity Editor snapshots and backups, and files placed by upload sync or a
 * backup restore.
 */
class SyncFolderSecretExposureTest extends WP_UnitTestCase
{
    const PLACEHOLDER = '[dbvc-redacted]';
    const GROUP_ID = 'page:dbvc-s1-site-secrets';

    private $sync_dir = '';
    private $backup_src = '';
    private $options_before = [];

    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        foreach (['dbvc_sync_path', 'dbvc_options_groups', 'dbvc_mask_meta_keys', 'dbvc_mask_action', 'dbvc_export_options_on_update'] as $name) {
            $this->options_before[$name] = get_option($name, null);
        }

        $this->sync_dir = trailingslashit(ABSPATH . 'wp-content/uploads/dbvc-s1-sync-' . wp_generate_password(8, false, false));
        wp_mkdir_p($this->sync_dir);
        $relative = ltrim(str_replace(wp_normalize_path(trailingslashit(ABSPATH)), '', wp_normalize_path($this->sync_dir)), '/');
        update_option('dbvc_sync_path', $relative);
        delete_transient('dbvc_entity_editor_index_v1');
    }

    public function tear_down(): void
    {
        unset($GLOBALS['dbvc_test_acf_groups'], $GLOBALS['dbvc_test_acf_field_objects'], $GLOBALS['vfe_test_acf_option_rows']);
        foreach ($this->options_before as $name => $value) {
            if ($value === null) {
                delete_option($name);
            } else {
                update_option($name, $value);
            }
        }
        delete_transient('dbvc_entity_editor_index_v1');
        $this->delete_directory($this->sync_dir);
        if ($this->backup_src !== '') {
            $this->delete_directory($this->backup_src);
        }

        parent::tear_down();
    }

    public function test_options_group_export_redacts_secret_fields(): void
    {
        $this->seed_acf_options_page([
            'field_s1_api_key' => ['name' => 'openai_api_key', 'type' => 'text', 'value' => 'sk-s1-openai-1111'],
            'field_s1_smtp' => ['name' => 'smtp_login', 'type' => 'password', 'value' => 'pw-s1-2222'],
            'field_s1_hooks' => ['name' => 'webhooks', 'type' => 'repeater', 'value' => [
                ['url' => 'https://example.test/hook', 'secret' => 'whsec-s1-3333'],
            ]],
            'field_s1_phone' => ['name' => 'office_phone', 'type' => 'text', 'value' => '555-0100'],
        ]);

        $this->assertSame(1, DBVC_Options_Groups::export_selected_groups());

        $files = glob($this->sync_dir . 'options/*.json');
        $this->assertCount(1, $files);
        $raw = (string) file_get_contents($files[0]);
        foreach (['sk-s1-openai-1111', 'pw-s1-2222', 'whsec-s1-3333'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }

        $fields = json_decode($raw, true)['fields'];
        $this->assertSame(self::PLACEHOLDER, $fields['field_s1_api_key']['value']);
        $this->assertSame(self::PLACEHOLDER, $fields['field_s1_smtp']['value'], 'ACF password fields are secret whatever their name.');
        $this->assertSame(self::PLACEHOLDER, $fields['field_s1_hooks']['value'][0]['secret']);
        $this->assertSame('https://example.test/hook', $fields['field_s1_hooks']['value'][0]['url']);
        $this->assertSame('555-0100', $fields['field_s1_phone']['value']);
    }

    public function test_options_group_import_keeps_the_target_sites_secrets(): void
    {
        $this->seed_acf_options_page([
            'field_s1_api_key' => ['name' => 'openai_api_key', 'type' => 'text', 'value' => 'sk-target-own'],
            'field_s1_hooks' => ['name' => 'webhooks', 'type' => 'repeater', 'value' => [
                ['url' => 'https://old.test/hook', 'secret' => 'whsec-target-own'],
            ]],
        ]);

        $file = $this->sync_dir . 'options/options-page-dbvc-s1-site-secrets.json';
        wp_mkdir_p(dirname($file));
        file_put_contents($file, wp_json_encode([
            'meta' => ['type' => 'acf_options_page', 'group_id' => self::GROUP_ID, 'slug' => 'dbvc-s1-site-secrets'],
            'fields' => [
                'field_s1_api_key' => ['name' => 'openai_api_key', 'type' => 'text', 'value' => self::PLACEHOLDER],
                'field_s1_hooks' => ['name' => 'webhooks', 'type' => 'repeater', 'value' => [
                    ['url' => 'https://new.test/hook', 'secret' => self::PLACEHOLDER],
                ]],
                'field_s1_phone' => ['name' => 'office_phone', 'type' => 'text', 'value' => '555-0199'],
            ],
        ]));

        $writes = [];
        $writer = static function ($field_key, $value, $post_id) use (&$writes) {
            $writes[$field_key] = ['value' => $value, 'post_id' => $post_id];
        };

        $this->assertTrue(DBVC_Options_Groups::import_group_from_file($file, $writer));

        $this->assertArrayNotHasKey('field_s1_api_key', $writes, 'A fully redacted field must not be written.');
        $this->assertSame(
            [['url' => 'https://new.test/hook', 'secret' => 'whsec-target-own']],
            $writes['field_s1_hooks']['value'],
            'A redacted sub-field takes the target value at the same path.'
        );
        $this->assertSame('555-0199', $writes['field_s1_phone']['value']);
        foreach ($writes as $write) {
            $this->assertFalse(DBVC_Options_Secret_Redactor::contains_placeholder($write['value']));
        }
    }

    public function test_entity_snapshot_masks_and_redacts_meta_in_a_hardened_folder(): void
    {
        update_option('dbvc_mask_meta_keys', 'operator_masked_meta');
        update_option('dbvc_mask_action', 'remove');

        $post_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'S1 Snapshot Before',
            'post_status' => 'publish',
            'post_name' => 'dbvc-s1-snapshot',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'dbvc-s1-snapshot-uid');
        update_post_meta($post_id, 'stripe_secret_key', 'sk-live-s1-4444');
        update_post_meta($post_id, 'integration_settings', ['endpoint' => 'https://api.example.test', 'api_key' => 'ak-s1-5555']);
        update_post_meta($post_id, 'operator_masked_meta', 'masked-s1-6666');
        update_post_meta($post_id, 'plain_note', 'visible-note');

        $relative = 'page/dbvc-s1-snapshot.json';
        wp_mkdir_p($this->sync_dir . 'page');
        file_put_contents($this->sync_dir . $relative, wp_json_encode([
            'ID' => $post_id,
            'post_type' => 'page',
            'post_name' => 'dbvc-s1-snapshot',
            'post_title' => 'S1 Snapshot After',
            'post_status' => 'publish',
            'vf_object_uid' => 'dbvc-s1-snapshot-uid',
            'meta' => ['plain_note' => 'after'],
            'tax_input' => [],
        ]));

        $loaded = DBVC_Entity_Editor_Indexer::load_entity_file($relative, get_current_user_id(), false);
        $this->assertIsArray($loaded);
        $result = DBVC_Entity_Editor_Indexer::save_and_full_replace(
            $relative,
            (string) $loaded['content'],
            get_current_user_id(),
            (string) ($loaded['lock']['token'] ?? ''),
            false,
            'REPLACE'
        );
        $this->assertIsArray($result);

        $snapshot_rel = (string) ($result['import_result']['snapshot_path'] ?? '');
        $this->assertNotSame('', $snapshot_rel);
        $backup_dir = $this->sync_dir . '.dbvc_entity_editor_backups';
        $this->assertFileExists($backup_dir . '/.htaccess');
        $this->assertFileExists($backup_dir . '/index.php');
        $this->assertMatchesRegularExpression(
            '/^\.dbvc_entity_editor_backups\/post_\d+\.\d{8}-\d{6}-[a-z0-9]{12}\.snapshot\.json$/',
            $snapshot_rel,
            'Snapshot names carry a random suffix so their URL cannot be guessed.'
        );

        $raw = (string) file_get_contents($this->sync_dir . $snapshot_rel);
        foreach (['sk-live-s1-4444', 'ak-s1-5555', 'masked-s1-6666'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $meta = json_decode($raw, true)['meta'];
        $this->assertSame([self::PLACEHOLDER], $meta['stripe_secret_key']);
        $this->assertSame(self::PLACEHOLDER, $meta['integration_settings'][0]['api_key']);
        $this->assertSame('https://api.example.test', $meta['integration_settings'][0]['endpoint']);
        $this->assertArrayNotHasKey('operator_masked_meta', $meta, 'Configured meta masking applies to snapshots.');
        $this->assertSame(['visible-note'], $meta['plain_note']);
    }

    public function test_backup_restore_hardens_folders_and_re_redacts_option_exports(): void
    {
        $this->write_unredacted_backup();

        DBVC_Sync_Posts::copy_backup_to_sync($this->backup_src, true);

        foreach (['', 'page/', 'options/'] as $dir) {
            $this->assertFileExists($this->sync_dir . $dir . '.htaccess', "deny file in /{$dir}");
            $this->assertFileExists($this->sync_dir . $dir . 'index.php', "index.php in /{$dir}");
        }

        $options = json_decode((string) file_get_contents($this->sync_dir . 'options.json'), true);
        $this->assertSame(self::PLACEHOLDER, $options['bricks_license_key']);
        $this->assertSame(self::PLACEHOLDER, $options['imagify_settings']['api_key']);
        $this->assertSame('S1 Site', $options['blogname']);

        $group = (string) file_get_contents($this->sync_dir . 'options/options-page-legacy.json');
        $this->assertStringNotContainsString('sk-legacy-group-7777', $group);
        $this->assertFileExists($this->sync_dir . 'page/legacy.json');
    }

    public function test_restore_re_redaction_can_be_turned_off(): void
    {
        $this->write_unredacted_backup();
        add_filter('dbvc_redact_restored_option_exports', '__return_false');

        DBVC_Sync_Posts::copy_backup_to_sync($this->backup_src, true);

        remove_filter('dbvc_redact_restored_option_exports', '__return_false');
        $options = json_decode((string) file_get_contents($this->sync_dir . 'options.json'), true);
        $this->assertSame('lic-legacy-8888', $options['bricks_license_key']);
        $this->assertFileExists($this->sync_dir . '.htaccess', 'Folders are hardened either way.');
    }

    public function test_option_update_export_trigger_is_a_setting(): void
    {
        delete_option('dbvc_export_options_on_update');
        $this->assertTrue(dbvc_is_option_update_export_enabled(), 'Default stays on, matching earlier releases.');

        update_option('dbvc_export_options_on_update', '0');
        $this->assertFalse(dbvc_is_option_update_export_enabled());
    }

    /**
     * @param array<string,array{name:string,type:string,value:mixed}> $fields
     */
    private function seed_acf_options_page(array $fields): void
    {
        $GLOBALS['dbvc_test_acf_groups'] = [[
            'key' => 'group_dbvc_s1',
            'title' => 'S1 Site Secrets',
            'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'dbvc-s1-site-secrets']]],
        ]];

        $objects = [];
        $rows = [];
        foreach ($fields as $key => $field) {
            $objects[] = ['key' => $key, 'name' => $field['name'], 'type' => $field['type']];
            $rows[$field['name']] = $field['value'];
            $rows[$key] = $field['value'];
        }
        $GLOBALS['dbvc_test_acf_field_objects'] = ['option' => $objects];
        $GLOBALS['vfe_test_acf_option_rows'] = $rows;

        update_option('dbvc_options_groups', [self::GROUP_ID]);
    }

    private function write_unredacted_backup(): void
    {
        $this->backup_src = untrailingslashit(ABSPATH . 'wp-content/uploads/dbvc-s1-backup-' . wp_generate_password(8, false, false));
        wp_mkdir_p($this->backup_src . '/page');
        wp_mkdir_p($this->backup_src . '/options');
        file_put_contents($this->backup_src . '/options.json', wp_json_encode([
            'blogname' => 'S1 Site',
            'bricks_license_key' => 'lic-legacy-8888',
            'imagify_settings' => ['api_key' => 'ak-legacy-9999', 'optimization_level' => 2],
        ]));
        file_put_contents($this->backup_src . '/options/options-page-legacy.json', wp_json_encode([
            'meta' => ['type' => 'acf_options_page', 'group_id' => 'page:legacy', 'slug' => 'legacy'],
            'fields' => ['field_legacy' => ['name' => 'service_api_key', 'type' => 'text', 'value' => 'sk-legacy-group-7777']],
        ]));
        file_put_contents($this->backup_src . '/page/legacy.json', wp_json_encode(['ID' => 1, 'post_type' => 'page']));
    }

    private function delete_directory(string $path): void
    {
        $path = rtrim($path, '/');
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
