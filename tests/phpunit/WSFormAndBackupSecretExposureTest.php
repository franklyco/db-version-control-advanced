<?php

/**
 * Security finding S1, last gaps: WS Form form exports and stored backups.
 *
 * WS Form form files in third-party/ws-form/forms/ carry action settings
 * (Custom API endpoints, basic-auth passwords, header values, recipient
 * addresses), and backups under uploads copy options.json and those form files
 * verbatim. Both are web-served on nginx, so both follow the options.json
 * redaction contract.
 *
 * WSFormSecretRedactionLiveTest drives the same contract through a real WS Form
 * install.
 */
class WSFormAndBackupSecretExposureTest extends WP_UnitTestCase
{
    const PLACEHOLDER = '[dbvc-redacted]';

    private $root = '';
    private $sync_dir = '';
    private $created_backups = [];
    private $sync_path_before = null;

    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->sync_path_before = get_option('dbvc_sync_path', null);
        $this->root = untrailingslashit(ABSPATH . 'wp-content/uploads/dbvc-s1-wsf-' . wp_generate_password(8, false, false));
        wp_mkdir_p($this->root);
    }

    public function tear_down(): void
    {
        foreach ($this->created_backups as $path) {
            $this->delete_directory($path);
        }
        $this->delete_directory($this->root);
        if ($this->sync_path_before === null) {
            delete_option('dbvc_sync_path');
        } else {
            update_option('dbvc_sync_path', $this->sync_path_before);
        }
        parent::tear_down();
    }

    public function test_form_export_redacts_action_credentials_endpoints_and_addresses(): void
    {
        $redacted = DBVC_WSForm_Secret_Redactor::redact_form_payload($this->form_payload());
        $json = wp_json_encode($redacted);

        foreach (['hooks.example.test/T0K3N', 'basic-pass-4242', 'Bearer sk-live-5151', 'ops@client.test', 'audit@client.test', 'owner@client.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, 'A form export must not carry its action secrets.');
        }

        $api = $this->action_config($redacted, 1);
        $this->assertSame(self::PLACEHOLDER, $api['meta']['action_customendpoint_endpoint']);
        $this->assertSame(self::PLACEHOLDER, $api['meta']['action_customendpoint_password']);
        $this->assertSame(self::PLACEHOLDER, $api['meta']['action_customendpoint_header_mapping'][0]['action_customendpoint_header_value']);
        $this->assertSame('Authorization', $api['meta']['action_customendpoint_header_mapping'][0]['action_customendpoint_header_name'], 'Header names are labels, not secrets.');
        $this->assertSame('POST', $api['meta']['action_customendpoint_request_method']);
        $this->assertSame('event', $api['meta']['action_customendpoint_dl_var_key'], 'Mapping labels ending in _key stay readable.');

        $email = $this->action_config($redacted, 2);
        $this->assertSame(self::PLACEHOLDER, $email['meta']['action_email_to'][0]['action_email_email']);
        $this->assertSame('Ops', $email['meta']['action_email_to'][0]['action_email_name']);
        $this->assertSame(self::PLACEHOLDER, $email['meta']['action_email_bcc']);
        $this->assertSame('#blog_admin_email', $email['meta']['action_email_from_email'], 'A WS Form variable reveals no address.');
        $this->assertSame('#email_submission', $email['meta']['action_email_message_text_editor']);

        $this->assertSame(self::PLACEHOLDER, $redacted['meta']['submit_notify_email'], 'Form-level meta is redacted too.');
        $this->assertSame('on', $redacted['meta']['honeypot']);
        $this->assertSame(['honeypot', 'action', 'submit_notify_email'], array_keys($redacted['meta']), 'Meta key order is kept.');
        $this->assertSame($this->form_payload()['groups'], $redacted['groups'], 'Fields and sections are untouched.');

        $this->assertSame($redacted, DBVC_WSForm_Secret_Redactor::redact_form_payload($redacted), 'Redaction is idempotent.');
    }

    public function test_form_checksum_matches_wsform_import_check(): void
    {
        $payload = DBVC_WSForm_Secret_Redactor::redact_form_payload($this->form_payload());
        $payload['checksum'] = DBVC_WSForm_Secret_Redactor::form_checksum($payload);

        // WS_Form_Common::object_checksum_check(): decode to objects, drop checksum, md5 the rest.
        $object = json_decode((string) wp_json_encode($payload));
        $expected = $object->checksum;
        unset($object->checksum);
        $this->assertSame($expected, md5((string) wp_json_encode($object)));
    }

    public function test_form_import_keeps_the_target_forms_secrets(): void
    {
        $incoming = DBVC_WSForm_Secret_Redactor::redact_form_payload($this->form_payload());
        $incoming['label'] = 'Renamed on the source';

        $target = $this->form_payload([
            'endpoint' => 'https://hooks.example.test/TARGET-OWN',
            'password' => 'target-pass-1',
            'header'   => 'Bearer target-token',
            'to'       => 'target-ops@client.test',
            'notify'   => 'target-owner@client.test',
        ]);

        $restored = DBVC_WSForm_Secret_Redactor::restore_form_payload($incoming, $target);
        $this->assertFalse(DBVC_WSForm_Secret_Redactor::payload_has_placeholder($restored));
        $this->assertSame('Renamed on the source', $restored['label']);

        $api = $this->action_config($restored, 1);
        $this->assertSame('https://hooks.example.test/TARGET-OWN', $api['meta']['action_customendpoint_endpoint']);
        $this->assertSame('target-pass-1', $api['meta']['action_customendpoint_password']);
        $this->assertSame('Bearer target-token', $api['meta']['action_customendpoint_header_mapping'][0]['action_customendpoint_header_value']);

        $email = $this->action_config($restored, 2);
        $this->assertSame('target-ops@client.test', $email['meta']['action_email_to'][0]['action_email_email']);
        $this->assertSame('target-owner@client.test', $restored['meta']['submit_notify_email']);
    }

    public function test_form_import_never_hands_one_actions_secret_to_another(): void
    {
        $incoming = DBVC_WSForm_Secret_Redactor::redact_form_payload($this->form_payload());

        // The target's row 1 is an email action, not the Custom API action.
        $target = $this->form_payload();
        $grid = $target['meta']['action'];
        $grid['groups'][0]['rows'][0]['data'][1] = wp_json_encode([
            'id'   => 'email',
            'meta' => ['action_customendpoint_password' => 'other-action-secret'],
        ]);
        $target['meta']['action'] = $grid;

        $restored = DBVC_WSForm_Secret_Redactor::restore_form_payload($incoming, $target);
        $api = $this->action_config($restored, 1);
        $this->assertArrayNotHasKey('action_customendpoint_password', $api['meta']);
        $this->assertStringNotContainsString('other-action-secret', (string) wp_json_encode($restored));

        $clone = DBVC_WSForm_Secret_Redactor::restore_form_payload($incoming, null);
        $this->assertFalse(DBVC_WSForm_Secret_Redactor::payload_has_placeholder($clone), 'A clone with no local match drops placeholders instead of storing them.');
        $this->assertSame('POST', $this->action_config($clone, 1)['meta']['action_customendpoint_request_method']);
    }

    public function test_sync_tree_dry_run_reports_names_and_writes_nothing(): void
    {
        $this->write_unredacted_tree($this->root);
        $before = $this->tree_fingerprint($this->root);

        $report = DBVC_Sync_Posts::secure_sync_tree($this->root, true);

        $this->assertSame($before, $this->tree_fingerprint($this->root), 'A dry run writes nothing.');
        $this->assertSame(3, $report['redacted_files']);
        $this->assertSame([
            'options.json',
            'options/options-page-legacy.json',
            'third-party/ws-form/forms/ws-form-7-contact.json',
        ], $report['redacted_paths']);
        $this->assertSame(6, $report['secured_dirs'], 'Every folder lacks its deny files.');
        $this->assertNoSecretValues(wp_json_encode($report));

        $applied = DBVC_Sync_Posts::secure_sync_tree($this->root);
        $this->assertSame(3, $applied['redacted_files']);
        $form = (string) file_get_contents($this->root . '/third-party/ws-form/forms/ws-form-7-contact.json');
        $this->assertNoSecretValues($form);
        $this->assertFileExists($this->root . '/third-party/ws-form/forms/.htaccess');

        $again = DBVC_Sync_Posts::secure_sync_tree($this->root, true);
        $this->assertSame(0, $again['redacted_files']);
        $this->assertSame(0, $again['secured_dirs']);
    }

    public function test_new_backup_is_hardened_and_redacted(): void
    {
        $relative = ltrim(str_replace(wp_normalize_path(trailingslashit(ABSPATH)), '', wp_normalize_path($this->root)), '/');
        update_option('dbvc_sync_path', $relative);
        $this->write_unredacted_tree($this->root);

        $base = DBVC_Backup_Manager::get_base_path();
        $existing = glob($base . '/*', GLOB_ONLYDIR) ?: [];
        DBVC_Sync_Posts::dbvc_create_backup_folder_and_copy_exports();
        $created = array_values(array_diff(glob($base . '/*', GLOB_ONLYDIR) ?: [], $existing));
        $this->created_backups = $created;
        $this->assertCount(1, $created, 'One backup folder was created.');
        $backup = $created[0];

        foreach (['options.json', 'options/options-page-legacy.json', 'third-party/ws-form/forms/ws-form-7-contact.json'] as $file) {
            $this->assertFileExists($backup . '/' . $file);
            $this->assertNoSecretValues((string) file_get_contents($backup . '/' . $file), $file);
        }
        foreach (['', '/options', '/third-party', '/third-party/ws-form', '/third-party/ws-form/forms'] as $dir) {
            $this->assertFileExists($backup . $dir . '/.htaccess', "deny file in backup{$dir}");
            $this->assertFileExists($backup . $dir . '/index.php', "index.php in backup{$dir}");
        }
        $options = json_decode((string) file_get_contents($backup . '/options.json'), true);
        $this->assertSame('S1 Site', $options['blogname']);
    }

    public function test_existing_backup_re_redaction_is_capability_gated_and_preview_first(): void
    {
        $backup = $this->make_legacy_backup();
        $name = basename($backup);

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $denied = DBVC_Backup_Manager::redact_existing_backups(false, $name);
        $this->assertWPError($denied);
        $this->assertSame('dbvc_forbidden', $denied->get_error_code());
        $this->assertStringContainsString('lic-legacy-8888', (string) file_get_contents($backup . '/options.json'));

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $before = $this->tree_fingerprint($backup);
        $preview = DBVC_Backup_Manager::redact_existing_backups(true, $name);
        $this->assertSame($before, $this->tree_fingerprint($backup), 'The preview writes nothing.');
        $this->assertTrue($preview['dry_run']);
        $this->assertSame(1, $preview['totals']['backups']);
        $this->assertSame(3, $preview['totals']['redacted_files']);
        $this->assertSame($name, $preview['backups'][0]['backup']);
        $this->assertContains('third-party/ws-form/forms/ws-form-7-contact.json', $preview['backups'][0]['files']);
        $this->assertNoSecretValues(wp_json_encode($preview));

        $applied = DBVC_Backup_Manager::redact_existing_backups(false, $name);
        $this->assertFalse($applied['dry_run']);
        $this->assertSame(3, $applied['totals']['redacted_files']);
        foreach (['options.json', 'options/options-page-legacy.json', 'third-party/ws-form/forms/ws-form-7-contact.json'] as $file) {
            $this->assertNoSecretValues((string) file_get_contents($backup . '/' . $file), $file);
        }
        $this->assertFileExists($backup . '/third-party/ws-form/forms/index.php');

        $this->assertSame(0, DBVC_Backup_Manager::redact_existing_backups(true, $name)['totals']['redacted_files']);
        $this->assertWPError(DBVC_Backup_Manager::redact_existing_backups(true, 'no-such-backup'));
    }

    /**
     * @param array<string,string> $values Secret values for the fixture.
     * @return array<string,mixed>
     */
    private function form_payload(array $values = []): array
    {
        $values = array_merge([
            'endpoint' => 'https://hooks.example.test/T0K3N',
            'password' => 'basic-pass-4242',
            'header'   => 'Bearer sk-live-5151',
            'to'       => 'ops@client.test',
            'notify'   => 'owner@client.test',
        ], $values);

        $api = [
            'id'     => 'customendpoint',
            'meta'   => [
                'action_customendpoint_endpoint'       => $values['endpoint'],
                'action_customendpoint_request_method' => 'POST',
                'action_customendpoint_username'       => '',
                'action_customendpoint_password'       => $values['password'],
                'action_customendpoint_dl_var_key'     => 'event',
                'action_customendpoint_header_mapping' => [[
                    'action_customendpoint_header_name'  => 'Authorization',
                    'action_customendpoint_header_value' => $values['header'],
                ]],
            ],
            'events' => ['submit'],
        ];
        $email = [
            'id'     => 'email',
            'meta'   => [
                'action_email_from_email'          => '#blog_admin_email',
                'action_email_to'                  => [['action_email_email' => $values['to'], 'action_email_name' => 'Ops']],
                'action_email_bcc'                 => 'audit@client.test',
                'action_email_message_text_editor' => '#email_submission',
            ],
            'events' => ['submit'],
        ];

        return [
            'label'      => 'Contact',
            'identifier' => 'ws_form',
            'meta'       => [
                'honeypot'            => 'on',
                'action'              => [
                    'columns' => [['id' => 0, 'label' => 'Action'], ['id' => 1, 'label' => 'Data']],
                    'groups'  => [[
                        'label' => 'Actions',
                        'rows'  => [
                            ['id' => 1, 'data' => ['Webhook', wp_json_encode($api)]],
                            ['id' => 2, 'data' => ['Email', wp_json_encode($email)]],
                        ],
                    ]],
                ],
                'submit_notify_email' => $values['notify'],
            ],
            'groups'     => [['label' => 'Tab', 'sections' => [['label' => 'Section', 'fields' => [['label' => 'Email', 'type' => 'email', 'meta' => []]]]]]],
            'dbvc_portability' => ['schema' => 1, 'provider' => 'ws_form', 'object_type' => 'form', 'uid' => 'dbvc-s1-wsf-uid'],
        ];
    }

    /**
     * @param array $payload
     * @param int   $row_id
     * @return array
     */
    private function action_config(array $payload, int $row_id): array
    {
        foreach ($payload['meta']['action']['groups'][0]['rows'] as $row) {
            if ((int) $row['id'] === $row_id) {
                return json_decode($row['data'][1], true);
            }
        }
        $this->fail("Action row {$row_id} is missing.");
    }

    private function write_unredacted_tree(string $root): void
    {
        wp_mkdir_p($root . '/options');
        wp_mkdir_p($root . '/third-party/ws-form/forms');
        wp_mkdir_p($root . '/page');
        file_put_contents($root . '/options.json', wp_json_encode([
            'blogname'           => 'S1 Site',
            'bricks_license_key' => 'lic-legacy-8888',
        ]));
        file_put_contents($root . '/options/options-page-legacy.json', wp_json_encode([
            'meta'   => ['type' => 'acf_options_page', 'group_id' => 'page:legacy', 'slug' => 'legacy'],
            'fields' => ['field_legacy' => ['name' => 'service_api_key', 'type' => 'text', 'value' => 'sk-legacy-group-7777']],
        ]));
        $form = $this->form_payload();
        $form['checksum'] = 'stale';
        file_put_contents($root . '/third-party/ws-form/forms/ws-form-7-contact.json', wp_json_encode($form));
        file_put_contents($root . '/page/legacy.json', wp_json_encode(['ID' => 1, 'post_type' => 'page']));
    }

    private function make_legacy_backup(): string
    {
        $backup = DBVC_Backup_Manager::get_base_path() . '/dbvc-s1-legacy-' . wp_generate_password(6, false, false);
        $this->created_backups[] = $backup;
        $this->write_unredacted_tree($backup);
        return $backup;
    }

    private function assertNoSecretValues($text, string $context = ''): void
    {
        foreach (['lic-legacy-8888', 'sk-legacy-group-7777', 'basic-pass-4242', 'sk-live-5151', 'T0K3N', 'ops@client.test', 'audit@client.test', 'owner@client.test'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $text, trim($context . ' leaks a secret value.'));
        }
    }

    /**
     * @return array<string,string> Relative path => content hash, including folders.
     */
    private function tree_fingerprint(string $root): array
    {
        $out = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($root));
            $out[$relative] = $item->isDir() ? 'dir' : md5_file($item->getPathname());
        }
        ksort($out);
        return $out;
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
