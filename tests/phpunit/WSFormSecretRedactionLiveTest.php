<?php

/**
 * WS Form action secrets through a real WS Form install (security finding S1).
 *
 * WSFormAndBackupSecretExposureTest covers the redaction rules on fixture
 * payloads. This drives the real path: a form with a Custom API action is
 * exported to the sync folder, the file must not carry its credentials, and
 * importing that file back over the form must keep the form's own values,
 * both with ID preservation off and on.
 *
 * Skips unless WS Form is loaded (DBVC_TESTS_WS_FORM=1, see bootstrap.php):
 *
 *     DBVC_TESTS_WS_FORM=1 vendor/bin/phpunit --filter WSFormSecretRedactionLiveTest
 *
 * @group wsform
 */
class WSFormSecretRedactionLiveTest extends WP_UnitTestCase
{
    const PLACEHOLDER = '[dbvc-redacted]';
    const ENDPOINT = 'https://hooks.example.test/live-T0K3N';
    const PASSWORD = 'live-basic-pass-6262';

    private $uid = '';
    private $form_ids = [];
    private $sync_dir = '';
    private $options_before = [];

    public function set_up(): void
    {
        parent::set_up();

        if (! class_exists('DBVC_Third_Party_Portability') || ! DBVC_Third_Party_Portability::is_wsform_available()) {
            $this->markTestSkipped('WS Form is not available in the test environment; run with DBVC_TESTS_WS_FORM=1.');
        }

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        foreach (['dbvc_sync_path', DBVC_Third_Party_Portability::OPTION_WSFORM_FORMS, DBVC_Third_Party_Portability::OPTION_WSFORM_PRESERVE_IDS] as $name) {
            $this->options_before[$name] = get_option($name, null);
        }
        delete_option(DBVC_Third_Party_Portability::OPTION_WSFORM_PRESERVE_IDS);

        $this->uid = 'dbvc-s1-wsform-live-' . wp_generate_uuid4();
        $this->sync_dir = untrailingslashit(ABSPATH . 'wp-content/uploads/dbvc-s1-wsf-live-' . wp_generate_password(8, false, false));
        wp_mkdir_p($this->sync_dir);
        update_option('dbvc_sync_path', ltrim(str_replace(wp_normalize_path(trailingslashit(ABSPATH)), '', wp_normalize_path($this->sync_dir)), '/'));
    }

    /**
     * Cleanup runs after parent::tear_down(): the ID-preserving import commits
     * its own transaction, so rows and options outlive WP's rollback.
     */
    public function tear_down(): void
    {
        parent::tear_down();

        foreach ($this->options_before as $name => $value) {
            if ($value === null) {
                delete_option($name);
            } else {
                update_option($name, $value);
            }
        }
        foreach ($this->form_ids as $form_id) {
            $this->delete_form($form_id);
        }
        $this->delete_directory($this->sync_dir);
    }

    public function test_sync_folder_form_file_is_redacted_and_import_keeps_the_forms_secrets(): void
    {
        $form_id = $this->create_form();
        $this->assertSame(self::PASSWORD, $this->read_action_meta($form_id)['action_customendpoint_password'], 'Fixture stored its secret.');

        $file = $this->export_to_sync_folder($form_id);
        $raw = (string) file_get_contents($file);
        $this->assertStringNotContainsString(self::PASSWORD, $raw);
        $this->assertStringNotContainsString('live-T0K3N', $raw);
        $this->assertStringContainsString(self::PLACEHOLDER, $raw);

        $result = DBVC_Third_Party_Portability::import_entity_file($file);
        $this->assertTrue($result === true, 'Import failed: ' . (is_wp_error($result) ? $result->get_error_message() : wp_json_encode($result)));

        $meta = $this->read_action_meta($form_id);
        $this->assertSame(self::PASSWORD, $meta['action_customendpoint_password'], 'The placeholder kept the form\'s own password.');
        $this->assertSame(self::ENDPOINT, $meta['action_customendpoint_endpoint']);
        $this->assertSame('POST', $meta['action_customendpoint_request_method']);
    }

    public function test_id_preserving_import_keeps_the_forms_secrets(): void
    {
        $form_id = $this->create_form();
        update_option(DBVC_Third_Party_Portability::OPTION_WSFORM_PRESERVE_IDS, '1');

        $snapshot = DBVC_Third_Party_Portability::export_wsform_form_snapshot_for_entity_editor($form_id);
        $this->assertIsArray($snapshot);
        $this->assertStringNotContainsString(self::PASSWORD, (string) wp_json_encode($snapshot), 'Entity Editor snapshots are redacted too.');

        $result = DBVC_Third_Party_Portability::import_wsform_form_for_entity_editor($snapshot);
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : 'Import failed.');
        $this->assertSame('update_matched_form', $result['action']);
        $this->assertSame($form_id, $result['form_id'], 'ID preservation still targets the uid-matched form.');
        $this->assertSame(self::PASSWORD, $this->read_action_meta($form_id)['action_customendpoint_password']);
    }

    public function test_clone_without_a_local_match_never_stores_the_placeholder(): void
    {
        $form_id = $this->create_form();
        $snapshot = DBVC_Third_Party_Portability::export_wsform_form_snapshot_for_entity_editor($form_id);
        $this->assertIsArray($snapshot);

        $snapshot['dbvc_portability']['uid'] = 'dbvc-s1-wsform-clone-' . wp_generate_uuid4();
        $snapshot['meta'][DBVC_Third_Party_Portability::META_PORTABILITY_UID] = $snapshot['dbvc_portability']['uid'];
        $result = DBVC_Third_Party_Portability::import_wsform_form_for_entity_editor($snapshot);
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : 'Import failed.');
        $this->form_ids[] = (int) $result['form_id'];
        $this->assertTrue($result['created']);

        $meta = $this->read_action_meta((int) $result['form_id']);
        $this->assertNotSame(self::PLACEHOLDER, $meta['action_customendpoint_password'] ?? '');
        $this->assertNotSame(self::PASSWORD, $meta['action_customendpoint_password'] ?? '', 'A clone gets no secret from the export.');
        $this->assertSame('POST', $meta['action_customendpoint_request_method']);
    }

    private function create_form(): int
    {
        $result = DBVC_Third_Party_Portability::import_wsform_form_for_entity_editor($this->fixture_payload());
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : 'Fixture import failed.');
        $form_id = (int) $result['form_id'];
        $this->form_ids[] = $form_id;
        return $form_id;
    }

    private function export_to_sync_folder(int $form_id): string
    {
        update_option(DBVC_Third_Party_Portability::OPTION_WSFORM_FORMS, '1');
        DBVC_Third_Party_Portability::export_selected_entities();

        foreach (glob($this->sync_dir . '/third-party/ws-form/forms/*.json') ?: [] as $file) {
            $payload = json_decode((string) file_get_contents($file), true);
            if (is_array($payload) && (int) ($payload['dbvc_portability']['source_id'] ?? 0) === $form_id) {
                return $file;
            }
        }
        $this->fail('The form was not exported to the sync folder.');
    }

    /**
     * @return array<string,mixed> The Custom API action's settings as WS Form stores them.
     */
    private function read_action_meta(int $form_id): array
    {
        $ws_form = new WS_Form_Form();
        $ws_form->id = $form_id;
        $tree = json_decode((string) wp_json_encode($ws_form->db_read(true, true, false, false, true)), true);
        $grid = $tree['meta']['action'] ?? [];
        $grid = is_string($grid) ? json_decode($grid, true) : $grid;
        foreach ($grid['groups'][0]['rows'] ?? [] as $row) {
            foreach ($row['data'] ?? [] as $cell) {
                $config = is_string($cell) ? json_decode($cell, true) : $cell;
                if (is_array($config) && ($config['id'] ?? '') === 'customendpoint') {
                    return (array) $config['meta'];
                }
            }
        }
        $this->fail('The Custom API action is missing from the stored form.');
    }

    private function fixture_payload(): array
    {
        $now = current_time('mysql', true);
        $user_id = get_current_user_id();
        $action = [
            'id'     => 'customendpoint',
            'meta'   => [
                'action_customendpoint_endpoint'       => self::ENDPOINT,
                'action_customendpoint_request_method' => 'POST',
                'action_customendpoint_password'       => self::PASSWORD,
            ],
            'events' => ['submit'],
        ];

        return [
            'label'      => 'DBVC S1 WS Form Fixture',
            'status'     => 'draft',
            'identifier' => 'ws_form',
            'meta'       => [
                'export_object' => 'form',
                'action'        => [
                    'rows_per_page' => 10,
                    'group_index'   => 0,
                    'default'       => [],
                    'columns'       => [['id' => 0, 'label' => 'Action'], ['id' => 1, 'label' => 'Data']],
                    'groups'        => [[
                        'label'      => 'Actions',
                        'page'       => 0,
                        'disabled'   => '',
                        'mask_group' => '',
                        'rows'       => [[
                            'id'       => 1,
                            'default'  => '',
                            'required' => '',
                            'disabled' => '',
                            'hidden'   => '',
                            'data'     => ['Webhook', wp_json_encode($action)],
                        ]],
                    ]],
                ],
            ],
            'groups'     => [[
                'label'        => 'Group',
                'user_id'      => $user_id,
                'date_added'   => $now,
                'date_updated' => $now,
                'sort_index'   => 1,
                'meta'         => [],
                'sections'     => [[
                    'label'             => 'Section',
                    'child_count'       => 0,
                    'user_id'           => $user_id,
                    'date_added'        => $now,
                    'date_updated'      => $now,
                    'sort_index'        => 1,
                    'parent_section_id' => 0,
                    'meta'              => [],
                    'fields'            => [[
                        'label'        => 'Client Email',
                        'type'         => 'email',
                        'user_id'      => $user_id,
                        'date_added'   => $now,
                        'date_updated' => $now,
                        'sort_index'   => 1,
                        'meta'         => [],
                    ]],
                ]],
            ]],
            'dbvc_portability' => [
                'schema'        => 1,
                'provider'      => 'ws_form',
                'object_type'   => 'form',
                'uid'           => $this->uid,
                'source_status' => 'draft',
            ],
        ];
    }

    private function delete_form(int $form_id): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix . WS_FORM_DB_TABLE_PREFIX;

        $group_ids = array_map('absint', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM {$prefix}group WHERE form_id = %d", $form_id)));
        $section_ids = $group_ids ? array_map('absint', (array) $wpdb->get_col("SELECT id FROM {$prefix}section WHERE group_id IN (" . implode(',', $group_ids) . ')')) : [];
        $field_ids = $section_ids ? array_map('absint', (array) $wpdb->get_col("SELECT id FROM {$prefix}field WHERE section_id IN (" . implode(',', $section_ids) . ')')) : [];

        foreach (['field' => $field_ids, 'section' => $section_ids, 'group' => $group_ids, 'form' => [$form_id]] as $object => $ids) {
            if (empty($ids)) {
                continue;
            }
            $in = implode(',', $ids);
            $wpdb->query("DELETE FROM {$prefix}{$object}_meta WHERE parent_id IN ({$in})");
            $wpdb->query("DELETE FROM {$prefix}{$object} WHERE id IN ({$in})");
        }
    }

    private function delete_directory(string $path): void
    {
        if ($path === '' || ! is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
