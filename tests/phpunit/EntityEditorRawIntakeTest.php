<?php

class EntityEditorRawIntakeTest extends WP_UnitTestCase
{
    /** @var string */
    private $sync_dir = '';

    /** @var string */
    private $sync_option_before = '';

    /** @var string */
    private $allow_new_posts_before = '';

    /** @var string */
    private $new_post_status_before = '';

    /** @var array<int,string> */
    private $new_post_whitelist_before = [];

    /** @var string */
    private $uid_fallback_matching_before = '';

    /** @var string */
    private $export_filename_format_before = '';

    /** @var string */
    private $taxonomy_filename_format_before = '';

    /** @var string */
    private $bricks_addon_enabled_before = '';

    /** @var array<int,string> */
    private $registered_test_post_types = [];

    public function set_up(): void
    {
        parent::set_up();

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->sync_option_before = (string) get_option('dbvc_sync_path', '');
        $this->allow_new_posts_before = (string) get_option('dbvc_allow_new_posts', '');
        $this->new_post_status_before = (string) get_option('dbvc_new_post_status', '');
        $this->new_post_whitelist_before = (array) get_option('dbvc_new_post_types_whitelist', []);
        $this->uid_fallback_matching_before = (string) get_option('dbvc_allow_uid_fallback_matching', '');
        $this->export_filename_format_before = (string) get_option('dbvc_export_filename_format', '');
        $this->taxonomy_filename_format_before = (string) get_option('dbvc_taxonomy_filename_format', '');
        $this->bricks_addon_enabled_before = (string) get_option('dbvc_addon_bricks_enabled', '0');

        $this->sync_dir = trailingslashit(ABSPATH . 'wp-content/uploads/dbvc-tests-' . wp_generate_password(8, false, false));
        wp_mkdir_p($this->sync_dir);
        $relative = ltrim(str_replace(wp_normalize_path(trailingslashit(ABSPATH)), '', wp_normalize_path($this->sync_dir)), '/');
        update_option('dbvc_sync_path', $relative ?: 'wp-content/uploads');
        update_option('dbvc_allow_new_posts', '1');
        update_option('dbvc_new_post_status', 'publish');
        update_option('dbvc_new_post_types_whitelist', []);
        update_option('dbvc_allow_uid_fallback_matching', '0');
        update_option('dbvc_taxonomy_filename_format', 'slug_id');
        delete_transient('dbvc_entity_editor_index_v1');

        if (class_exists('DBVC_Database')) {
            DBVC_Database::activate();
        }

        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        delete_transient('dbvc_entity_editor_index_v1');
        update_option('dbvc_sync_path', $this->sync_option_before);
        update_option('dbvc_allow_new_posts', $this->allow_new_posts_before);
        update_option('dbvc_new_post_status', $this->new_post_status_before);
        update_option('dbvc_new_post_types_whitelist', $this->new_post_whitelist_before);
        update_option('dbvc_allow_uid_fallback_matching', $this->uid_fallback_matching_before);
        update_option('dbvc_export_filename_format', $this->export_filename_format_before);
        update_option('dbvc_taxonomy_filename_format', $this->taxonomy_filename_format_before);
        update_option('dbvc_addon_bricks_enabled', $this->bricks_addon_enabled_before);
        foreach ($this->registered_test_post_types as $post_type) {
            if (post_type_exists($post_type)) {
                unregister_post_type($post_type);
            }
        }
        $this->registered_test_post_types = [];
        $this->delete_directory($this->sync_dir);
        parent::tear_down();
    }

    public function test_raw_intake_preview_requires_manage_options(): void
    {
        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/raw-intake/preview',
            [
                'content' => wp_json_encode([
                    'ID'         => 10,
                    'post_type'  => 'page',
                    'post_name'  => 'preview-only',
                    'post_title' => 'Preview Only',
                ]),
                'mode' => 'create_only',
            ]
        );

        $this->assertSame(403, $response->get_status());
    }

    public function test_raw_intake_preview_reports_post_creation_block_when_setting_disabled(): void
    {
        update_option('dbvc_allow_new_posts', '0');

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/raw-intake/preview',
            [
                'content' => wp_json_encode([
                    'ID'         => 0,
                    'post_type'  => 'page',
                    'post_name'  => 'raw-intake-blocked-post',
                    'post_title' => 'Raw Intake Blocked Post',
                    'meta'       => [],
                    'tax_input'  => [],
                ]),
                'mode' => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();

        $this->assertFalse((bool) ($data['available_actions']['create_only'] ?? true));
        $this->assertNotEmpty($data['blocking'] ?? []);
        $this->assertContains('post_creation_disabled', array_column((array) ($data['blocking'] ?? []), 'code'));
        $this->assertNotEmpty($data['blocker_details'] ?? []);
        $this->assertNotEmpty($data['settings_links'] ?? []);
        $this->assertSame('blocked', $data['detected_action'] ?? '');
    }

    public function test_raw_intake_preview_reports_post_type_whitelist_blocker_details(): void
    {
        update_option('dbvc_new_post_types_whitelist', ['post']);

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/raw-intake/preview',
            [
                'content' => wp_json_encode([
                    'ID'         => 0,
                    'post_type'  => 'page',
                    'post_name'  => 'raw-intake-whitelist-blocked-post',
                    'post_title' => 'Raw Intake Whitelist Blocked Post',
                    'meta'       => [],
                    'tax_input'  => [],
                ]),
                'mode' => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $blocking_codes = array_column((array) ($data['blocking'] ?? []), 'code');
        $details = (array) ($data['blocker_details'] ?? []);
        $detail = (array) ($details[0] ?? []);
        $settings_link_ids = array_column((array) ($data['settings_links'] ?? []), 'id');

        $this->assertFalse((bool) ($data['available_actions']['create_only'] ?? true));
        $this->assertContains('post_type_whitelist_blocked', $blocking_codes);
        $this->assertSame('dbvc_new_post_types_whitelist', (string) ($detail['option'] ?? ''));
        $this->assertSame('page', (string) ($detail['post_type'] ?? ''));
        $this->assertContains('import_settings', $settings_link_ids);
        $this->assertContains('post_type_settings', $settings_link_ids);
    }

    public function test_entity_editor_index_includes_wsform_provider_payload(): void
    {
        $relative_path = 'third-party/ws-form/forms/ws-form-15-contact-form.json';
        $this->write_sync_json_file($relative_path, [
            'identifier' => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
            'id'         => 15,
            'label'      => 'Contact Form',
            'status'     => 'publish',
            'meta'       => [
                'export_object'        => 'form',
                'dbvc_portability_uid' => 'wsform-contact-uid',
                'action'               => [
                    ['id' => 1],
                ],
            ],
            'groups'     => [
                [
                    'id'       => 1,
                    'sections' => [
                        [
                            'id'     => 2,
                            'fields' => [
                                ['id' => 3, 'type' => 'text'],
                            ],
                        ],
                    ],
                ],
            ],
            'dbvc_portability' => [
                'provider'      => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
                'object_type'   => 'form',
                'uid'           => 'wsform-contact-uid',
                'source_id'     => 15,
                'source_status' => 'publish',
            ],
        ]);

        $index = DBVC_Entity_Editor_Indexer::get_index(true);
        $items = array_values(array_filter((array) ($index['items'] ?? []), static function ($item) use ($relative_path) {
            return is_array($item) && ($item['relative_path'] ?? '') === $relative_path;
        }));

        $this->assertCount(1, $items);
        $item = $items[0];
        $this->assertSame('third_party', (string) ($item['entity_kind'] ?? ''));
        $this->assertSame('ws_form', (string) ($item['provider'] ?? ''));
        $this->assertSame('form', (string) ($item['object_type'] ?? ''));
        $this->assertSame('Contact Form', (string) ($item['title'] ?? ''));
        $this->assertSame('wsform-contact-uid', (string) ($item['uid'] ?? ''));
        $this->assertSame(1, (int) ($item['counts']['groups'] ?? 0));
        $this->assertSame(1, (int) ($item['counts']['sections'] ?? 0));
        $this->assertSame(1, (int) ($item['counts']['fields'] ?? 0));

        $server = rest_get_server();
        $request = new WP_REST_Request('GET', '/dbvc/v1/entity-editor/file');
        $request->set_param('path', $relative_path);
        $response = $server->dispatch($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('third_party', (string) ($data['entity_kind'] ?? ''));
        $this->assertSame('ws_form', (string) ($data['provider'] ?? ''));
        $this->assertSame('form', (string) ($data['object_type'] ?? ''));
        $this->assertSame('wsform-contact-uid', (string) ($data['uid'] ?? ''));
    }

    public function test_third_party_preview_blocks_wsform_form_when_provider_unavailable(): void
    {
        if (DBVC_Third_Party_Portability::is_wsform_available()) {
            $this->markTestSkipped('WS Form is available in this test environment.');
        }

        $relative_path = 'third-party/ws-form/forms/ws-form-15-contact-form.json';
        $this->write_sync_json_file($relative_path, [
            'identifier' => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
            'id'         => 15,
            'label'      => 'Contact Form',
            'meta'       => [
                'export_object'        => 'form',
                'dbvc_portability_uid' => 'wsform-contact-uid',
            ],
            'dbvc_portability' => [
                'provider'    => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
                'object_type' => 'form',
                'uid'         => 'wsform-contact-uid',
                'source_id'   => 15,
            ],
        ]);

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/third-party/preview',
            [
                'paths' => [$relative_path],
                'mode'  => 'preview',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $item = (array) ($data['items'][0] ?? []);
        $blocking_codes = array_column((array) ($item['blocking'] ?? []), 'code');

        $this->assertSame('third_party', (string) ($item['entity_kind'] ?? ''));
        $this->assertSame('ws_form', (string) ($item['provider'] ?? ''));
        $this->assertFalse((bool) ($item['available_actions']['create_form'] ?? true));
        $this->assertContains('wsform_unavailable', $blocking_codes);
        $this->assertSame(1, (int) ($data['summary']['blocked'] ?? 0));
    }

    public function test_third_party_preview_reports_wsform_settings_mergeable(): void
    {
        $relative_path = 'third-party/ws-form/settings.json';
        $this->write_sync_json_file($relative_path, [
            'schema'      => 1,
            'entity_type' => 'third_party',
            'provider'    => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
            'object_type' => 'settings',
            'options'     => [
                'ws_form' => [
                    'framework' => 'tailwind',
                ],
            ],
            'excluded_keys' => [
                'ws_form' => ['license_key'],
            ],
        ]);

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/third-party/preview',
            [
                'paths' => [$relative_path],
                'mode'  => 'preview',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $item = (array) ($data['items'][0] ?? []);

        $this->assertSame('settings', (string) ($item['object_type'] ?? ''));
        $this->assertTrue((bool) ($item['available_actions']['merge_settings'] ?? false));
        $this->assertContains('ws_form', (array) ($item['settings_preview']['mergeable_options'] ?? []));
        $this->assertSame(1, (int) ($data['summary']['mergeable'] ?? 0));
    }

    public function test_raw_intake_preview_routes_wsform_form_payload_to_provider_preview(): void
    {
        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/raw-intake/preview',
            [
                'content' => wp_json_encode([
                    'identifier' => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
                    'id'         => 15,
                    'label'      => 'Contact Form',
                    'status'     => 'publish',
                    'meta'       => [
                        'export_object'        => 'form',
                        'dbvc_portability_uid' => 'wsform-contact-uid',
                    ],
                    'dbvc_portability' => [
                        'provider'      => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
                        'object_type'   => 'form',
                        'uid'           => 'wsform-contact-uid',
                        'source_id'     => 15,
                        'source_status' => 'publish',
                    ],
                ]),
                'mode' => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();

        $this->assertSame('third_party', (string) ($data['entity_kind'] ?? ''));
        $this->assertSame('ws_form', (string) ($data['provider'] ?? ''));
        $this->assertSame('form', (string) ($data['object_type'] ?? ''));
        $this->assertSame('ws_form_form', (string) ($data['subtype'] ?? ''));
        $this->assertSame('Contact Form', (string) ($data['title'] ?? ''));
        $this->assertSame('third-party/ws-form/forms/ws-form-15-contact-form.json', (string) ($data['target_relative_path'] ?? ''));
        $this->assertArrayHasKey('create_only', (array) ($data['available_actions'] ?? []));

        if (! DBVC_Third_Party_Portability::is_wsform_available()) {
            $blocking_codes = array_column((array) ($data['blocking'] ?? []), 'code');
            $this->assertContains('wsform_unavailable', $blocking_codes);
        }
    }

    public function test_raw_intake_preview_routes_wsform_template_without_export_object_to_provider_preview(): void
    {
        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/raw-intake/preview',
            [
                'content' => wp_json_encode([
                    'identifier' => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
                    'id'         => 7008,
                    'label'      => 'OpenAI Chat Simple',
                    'version'    => '1.9.210',
                    'meta'       => [
                        'label_position_form' => 'top',
                        'submit_on_enter'     => 'on',
                    ],
                    'groups'     => [
                        [
                            'label'    => 'Default',
                            'sections' => [
                                [
                                    'label'  => 'Main',
                                    'fields' => [
                                        [
                                            'type'  => 'text',
                                            'label' => 'Prompt',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]),
                'mode' => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();

        $this->assertSame('third_party', (string) ($data['entity_kind'] ?? ''));
        $this->assertSame('ws_form', (string) ($data['provider'] ?? ''));
        $this->assertSame('form', (string) ($data['object_type'] ?? ''));
        $this->assertSame('ws_form_form', (string) ($data['subtype'] ?? ''));
        $this->assertSame('OpenAI Chat Simple', (string) ($data['title'] ?? ''));
        $this->assertSame('third-party/ws-form/forms/ws-form-7008-openai-chat-simple.json', (string) ($data['target_relative_path'] ?? ''));
        $this->assertSame(1, (int) ($data['counts']['groups'] ?? 0));
        $this->assertSame(1, (int) ($data['counts']['sections'] ?? 0));
        $this->assertSame(1, (int) ($data['counts']['fields'] ?? 0));
    }

    public function test_raw_intake_preview_reports_unsupported_wsform_payload_as_blocked_preview(): void
    {
        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/raw-intake/preview',
            [
                'content' => wp_json_encode([
                    'identifier' => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
                    'label'      => 'Reusable Section',
                    'meta'       => [
                        'export_object' => 'section',
                    ],
                ]),
                'mode' => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $blocking_codes = array_column((array) ($data['blocking'] ?? []), 'code');

        $this->assertSame('third_party', (string) ($data['entity_kind'] ?? ''));
        $this->assertSame('ws_form', (string) ($data['provider'] ?? ''));
        $this->assertSame('section', (string) ($data['object_type'] ?? ''));
        $this->assertSame('Reusable Section', (string) ($data['title'] ?? ''));
        $this->assertContains('unsupported_wsform_object_type', $blocking_codes);
        $this->assertSame('', (string) ($data['target_relative_path'] ?? ''));
    }

    public function test_raw_intake_stage_only_writes_wsform_provider_json_to_sync(): void
    {
        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/raw-intake/commit',
            [
                'content' => wp_json_encode([
                    'identifier' => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
                    'id'         => 15,
                    'label'      => 'Contact Form',
                    'status'     => 'publish',
                    'meta'       => [
                        'export_object'        => 'form',
                        'dbvc_portability_uid' => 'wsform-contact-uid',
                    ],
                    'dbvc_portability' => [
                        'provider'      => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
                        'object_type'   => 'form',
                        'uid'           => 'wsform-contact-uid',
                        'source_id'     => 15,
                        'source_status' => 'publish',
                    ],
                ]),
                'mode' => 'stage_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $relative_path = 'third-party/ws-form/forms/ws-form-15-contact-form.json';

        $this->assertSame('stage', (string) ($data['action'] ?? ''));
        $this->assertSame('third_party', (string) ($data['entity_kind'] ?? ''));
        $this->assertSame('ws_form', (string) ($data['provider'] ?? ''));
        $this->assertSame('form', (string) ($data['object_type'] ?? ''));
        $this->assertFalse((bool) ($data['imported'] ?? true));
        $this->assertSame($relative_path, (string) ($data['relative_path'] ?? ''));
        $this->assertFileExists($this->absolute_sync_path($relative_path));

        $index = DBVC_Entity_Editor_Indexer::get_index(true);
        $items = array_values(array_filter((array) ($index['items'] ?? []), static function ($item) use ($relative_path) {
            return is_array($item) && ($item['relative_path'] ?? '') === $relative_path;
        }));

        $this->assertCount(1, $items);
        $this->assertSame('third_party', (string) ($items[0]['entity_kind'] ?? ''));
        $this->assertSame('form', (string) ($items[0]['object_type'] ?? ''));
    }

    public function test_wsform_snapshot_helper_writes_recoverable_provider_json(): void
    {
        $filter = static function ($payload, $form_id) {
            return [
                'identifier' => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
                'id'         => (int) $form_id,
                'label'      => 'Snapshot Contact Form',
                'status'     => 'publish',
                'meta'       => [
                    'export_object'        => 'form',
                    'dbvc_portability_uid' => 'wsform-snapshot-contact-uid',
                ],
                'groups'     => [
                    [
                        'label'    => 'Default',
                        'sections' => [
                            [
                                'label'  => 'Main',
                                'fields' => [
                                    [
                                        'type'  => 'text',
                                        'label' => 'Name',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'dbvc_portability' => [
                    'provider'      => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
                    'object_type'   => 'form',
                    'uid'           => 'wsform-snapshot-contact-uid',
                    'source_id'     => (int) $form_id,
                    'source_status' => 'publish',
                ],
            ];
        };

        add_filter('dbvc_third_party_wsform_form_snapshot_payload', $filter, 10, 2);
        try {
            $method = new ReflectionMethod(\Dbvc\EntityEditor\ThirdPartySyncFileImportService::class, 'create_wsform_form_snapshot');
            $method->setAccessible(true);
            $snapshot_path = $method->invoke(null, 314, [
                'title'         => 'Snapshot Contact Form',
                'relative_path' => 'third-party/ws-form/forms/ws-form-314-snapshot-contact-form.json',
            ], get_current_user_id());
        } finally {
            remove_filter('dbvc_third_party_wsform_form_snapshot_payload', $filter, 10);
        }

        $this->assertIsString($snapshot_path);
        $this->assertSame(0, strpos((string) $snapshot_path, '.dbvc_entity_editor_backups/wsform-form-314-snapshot-contact-form.'));
        $this->assertStringEndsWith('.snapshot.json', (string) $snapshot_path);

        $absolute_path = $this->absolute_sync_path((string) $snapshot_path);
        $this->assertFileExists($absolute_path);

        $decoded = json_decode((string) file_get_contents($absolute_path), true);
        $this->assertIsArray($decoded);
        $this->assertTrue(DBVC_Third_Party_Portability::is_wsform_form_payload($decoded));
        $this->assertSame(DBVC_Third_Party_Portability::PROVIDER_WSFORM, (string) ($decoded['identifier'] ?? ''));
        $this->assertSame('entity_editor_wsform_update', (string) ($decoded['dbvc_portability']['snapshot']['source'] ?? ''));
        $this->assertSame(314, (int) ($decoded['dbvc_portability']['snapshot']['matched_form_id'] ?? 0));
        $this->assertSame(get_current_user_id(), (int) ($decoded['dbvc_portability']['snapshot']['captured_by'] ?? 0));
        $this->assertSame(
            'third-party/ws-form/forms/ws-form-314-snapshot-contact-form.json',
            (string) ($decoded['dbvc_portability']['snapshot']['relative_path'] ?? '')
        );
    }

    public function test_wsform_recovery_restores_overwritten_raw_sync_file_from_backup(): void
    {
        $relative_path = 'third-party/ws-form/forms/ws-form-314-rollback-contact-form.json';
        $backup_path = '.dbvc_entity_editor_backups/third-party__ws-form__forms__ws-form-314-rollback-contact-form.json.test.bak.json';

        $this->write_sync_json_file($relative_path, $this->wsform_test_payload('Incoming Rollback Contact Form', 314, 'wsform-rollback-contact-uid'));
        $this->write_sync_json_file($backup_path, $this->wsform_test_payload('Previous Rollback Contact Form', 314, 'wsform-rollback-contact-uid'));

        $method = new ReflectionMethod(\Dbvc\EntityEditor\ThirdPartySyncFileImportService::class, 'rollback_raw_provider_sync_write');
        $method->setAccessible(true);
        $result = $method->invoke(null, [
            'relative_path' => $relative_path,
            'absolute_path' => $this->absolute_sync_path($relative_path),
            'backup_path'   => $backup_path,
        ]);

        $this->assertIsArray($result);
        $this->assertTrue((bool) ($result['attempted'] ?? false));
        $this->assertTrue((bool) ($result['restored'] ?? false));
        $this->assertFalse((bool) ($result['removed'] ?? true));
        $this->assertEmpty((array) ($result['errors'] ?? []));

        $decoded = json_decode((string) file_get_contents($this->absolute_sync_path($relative_path)), true);
        $this->assertIsArray($decoded);
        $this->assertSame('Previous Rollback Contact Form', (string) ($decoded['label'] ?? ''));
    }

    public function test_wsform_recovery_removes_new_raw_sync_file_without_backup(): void
    {
        $relative_path = 'third-party/ws-form/forms/ws-form-315-new-rollback-contact-form.json';
        $this->write_sync_json_file($relative_path, $this->wsform_test_payload('New Rollback Contact Form', 315, 'wsform-new-rollback-contact-uid'));

        $method = new ReflectionMethod(\Dbvc\EntityEditor\ThirdPartySyncFileImportService::class, 'rollback_raw_provider_sync_write');
        $method->setAccessible(true);
        $result = $method->invoke(null, [
            'relative_path' => $relative_path,
            'absolute_path' => $this->absolute_sync_path($relative_path),
            'backup_path'   => '',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue((bool) ($result['attempted'] ?? false));
        $this->assertFalse((bool) ($result['restored'] ?? true));
        $this->assertTrue((bool) ($result['removed'] ?? false));
        $this->assertEmpty((array) ($result['errors'] ?? []));
        $this->assertFileDoesNotExist($this->absolute_sync_path($relative_path));
    }

    public function test_wsform_failed_update_recovery_reports_sync_and_live_snapshot_restore(): void
    {
        $relative_path = 'third-party/ws-form/forms/ws-form-316-combined-rollback-form.json';
        $backup_path = '.dbvc_entity_editor_backups/third-party__ws-form__forms__ws-form-316-combined-rollback-form.json.test.bak.json';
        $snapshot_path = '.dbvc_entity_editor_backups/wsform-form-316-combined-rollback-form.test.snapshot.json';

        $this->write_sync_json_file($relative_path, $this->wsform_test_payload('Incoming Combined Rollback Form', 316, 'wsform-combined-rollback-uid'));
        $this->write_sync_json_file($backup_path, $this->wsform_test_payload('Previous Combined Rollback Form', 316, 'wsform-combined-rollback-uid'));
        $this->write_sync_json_file($snapshot_path, $this->wsform_test_payload('Snapshot Combined Rollback Form', 316, 'wsform-combined-rollback-uid'));

        $filter = static function ($result, $path, $payload) use ($snapshot_path) {
            return [
                'restored'      => $path === $snapshot_path,
                'snapshot_label' => (string) ($payload['label'] ?? ''),
            ];
        };

        add_filter('dbvc_entity_editor_wsform_snapshot_restore_result', $filter, 10, 3);
        try {
            $method = new ReflectionMethod(\Dbvc\EntityEditor\ThirdPartySyncFileImportService::class, 'recover_wsform_failed_update');
            $method->setAccessible(true);
            $recovery = $method->invoke(null, $snapshot_path, [
                'relative_path' => $relative_path,
                'absolute_path' => $this->absolute_sync_path($relative_path),
                'backup_path'   => $backup_path,
            ]);
        } finally {
            remove_filter('dbvc_entity_editor_wsform_snapshot_restore_result', $filter, 10);
        }

        $this->assertIsArray($recovery);
        $this->assertTrue((bool) ($recovery['attempted'] ?? false));
        $this->assertTrue((bool) ($recovery['sync_file']['restored'] ?? false));
        $this->assertTrue((bool) ($recovery['live_snapshot']['restored'] ?? false));
        $this->assertSame('Snapshot Combined Rollback Form', (string) ($recovery['live_snapshot']['snapshot_label'] ?? ''));

        $decoded = json_decode((string) file_get_contents($this->absolute_sync_path($relative_path)), true);
        $this->assertIsArray($decoded);
        $this->assertSame('Previous Combined Rollback Form', (string) ($decoded['label'] ?? ''));
    }

    public function test_raw_intake_commit_create_only_creates_post_and_sync_file(): void
    {
        update_option('dbvc_export_filename_format', 'slug_id');

        $source_relative_path = 'page/page-raw-intake-created-post.json';

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/raw-intake/commit',
            [
                'content' => wp_json_encode([
                    'ID'          => 0,
                    'post_type'   => 'page',
                    'post_name'   => 'raw-intake-created-post',
                    'post_title'  => 'Raw Intake Created Post',
                    'post_status' => 'draft',
                    'meta'        => [
                        'raw_intake_meta' => 'hello-post',
                    ],
                    'tax_input'   => [],
                ]),
                'mode' => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();

        $this->assertSame('create', $data['action'] ?? '');
        $this->assertTrue((bool) ($data['created'] ?? false));
        $this->assertTrue((bool) ($data['imported'] ?? false));
        $this->assertNotEmpty($data['relative_path'] ?? '');
        $this->assertFileExists($this->absolute_sync_path((string) $data['relative_path']));
        $this->assertSame($source_relative_path, (string) ($data['source_relative_path'] ?? ''));

        $post = get_page_by_path('raw-intake-created-post', OBJECT, 'page');
        $this->assertInstanceOf(WP_Post::class, $post);
        $this->assertSame('Raw Intake Created Post', $post->post_title);
        $this->assertSame('hello-post', (string) get_post_meta($post->ID, 'raw_intake_meta', true));
        $this->assertSame((int) $post->ID, (int) ($data['wp_entity']['id'] ?? 0));

        $final_relative_path = sprintf('page/page-raw-intake-created-post-%d.json', $post->ID);
        $this->assertSame($final_relative_path, (string) ($data['relative_path'] ?? ''));
        $this->assertFileDoesNotExist($this->absolute_sync_path($source_relative_path));
        $this->assertFileExists($this->absolute_sync_path($final_relative_path));
        $this->assertTrue((bool) ($data['file_normalization']['moved'] ?? false));
        $this->assertCount(1, glob(trailingslashit($this->sync_dir) . 'page/*.json') ?: []);

        $final_payload = json_decode((string) file_get_contents($this->absolute_sync_path($final_relative_path)), true);
        $this->assertIsArray($final_payload);
        $this->assertSame((int) $post->ID, (int) ($final_payload['ID'] ?? 0));
    }

    public function test_raw_intake_commit_create_only_creates_term_and_sync_file(): void
    {
        $source_relative_path = 'taxonomy/category/category-raw-intake-created-term.json';

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/raw-intake/commit',
            [
                'content' => wp_json_encode([
                    'term_id'     => 0,
                    'taxonomy'    => 'category',
                    'slug'        => 'raw-intake-created-term',
                    'name'        => 'Raw Intake Created Term',
                    'description' => 'Created from raw intake',
                    'meta'        => [
                        'raw_term_meta' => 'hello-term',
                    ],
                ]),
                'mode' => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();

        $this->assertSame('create', $data['action'] ?? '');
        $this->assertTrue((bool) ($data['created'] ?? false));
        $this->assertTrue((bool) ($data['imported'] ?? false));
        $this->assertNotEmpty($data['relative_path'] ?? '');
        $this->assertFileExists($this->absolute_sync_path((string) $data['relative_path']));
        $this->assertSame($source_relative_path, (string) ($data['source_relative_path'] ?? ''));

        $term = get_term_by('slug', 'raw-intake-created-term', 'category');
        $this->assertNotFalse($term);
        $this->assertInstanceOf(WP_Term::class, $term);
        $this->assertSame('hello-term', (string) get_term_meta($term->term_id, 'raw_term_meta', true));
        $this->assertSame((int) $term->term_id, (int) ($data['wp_entity']['id'] ?? 0));

        $final_relative_path = sprintf('taxonomy/category/category-raw-intake-created-term-%d.json', $term->term_id);
        $this->assertSame($final_relative_path, (string) ($data['relative_path'] ?? ''));
        $this->assertFileDoesNotExist($this->absolute_sync_path($source_relative_path));
        $this->assertFileExists($this->absolute_sync_path($final_relative_path));
        $this->assertTrue((bool) ($data['file_normalization']['moved'] ?? false));
        $this->assertCount(1, glob(trailingslashit($this->sync_dir) . 'taxonomy/category/*.json') ?: []);

        $final_payload = json_decode((string) file_get_contents($this->absolute_sync_path($final_relative_path)), true);
        $this->assertIsArray($final_payload);
        $this->assertSame((int) $term->term_id, (int) ($final_payload['term_id'] ?? 0));
    }

    public function test_raw_intake_commit_create_only_blocks_when_live_match_exists(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Raw Intake Existing Post',
            'post_status' => 'publish',
            'post_name'   => 'raw-intake-existing-post',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'raw-intake-existing-post-uid');

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/raw-intake/commit',
            [
                'content' => wp_json_encode([
                    'ID'           => $post_id,
                    'post_type'    => 'page',
                    'post_name'    => 'raw-intake-existing-post',
                    'post_title'   => 'Raw Intake Existing Post',
                    'vf_object_uid'=> 'raw-intake-existing-post-uid',
                    'meta'         => [],
                    'tax_input'    => [],
                ]),
                'mode' => 'create_only',
            ]
        );

        $this->assertSame(409, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('dbvc_entity_editor_raw_intake_blocked', $data['code'] ?? '');
    }

    public function test_raw_intake_update_matched_requires_current_confirmation(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Raw Intake Matched Update Before',
            'post_status' => 'publish',
            'post_name'   => 'raw-intake-matched-update',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'raw-intake-matched-update-uid');

        $content = wp_json_encode([
            'ID'            => 987654350,
            'post_type'     => 'page',
            'post_name'     => 'raw-intake-matched-update',
            'post_title'    => 'Raw Intake Matched Update After',
            'post_content'  => 'Updated through confirmed raw intake.',
            'post_excerpt'  => '',
            'post_status'   => 'publish',
            'vf_object_uid' => 'raw-intake-matched-update-uid',
            'meta'          => [
                'vf_object_uid'          => ['raw-intake-matched-update-uid'],
                'raw_intake_update_meta' => ['confirmed-update'],
            ],
            'tax_input'     => [],
        ]);

        $preview_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/raw-intake/preview',
            [
                'content' => $content,
                'mode'    => 'create_or_update_matched',
            ]
        );

        $this->assertSame(200, $preview_response->get_status());
        $preview = $preview_response->get_data();
        $matched_update = (array) ($preview['matched_update'] ?? []);

        $this->assertSame('update_matched', (string) ($preview['detected_action'] ?? ''));
        $this->assertTrue((bool) ($preview['available_actions']['create_or_update_matched'] ?? false));
        $this->assertNotEmpty($preview['preview_hash'] ?? '');
        $this->assertTrue((bool) ($matched_update['eligible'] ?? false));
        $this->assertSame($post_id, (int) ($matched_update['wp_entity']['id'] ?? 0));
        $this->assertTrue((bool) ($matched_update['requires_confirmation'] ?? false));

        $missing_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/raw-intake/commit',
            [
                'content' => $content,
                'mode'    => 'create_or_update_matched',
            ]
        );

        $this->assertSame(409, $missing_response->get_status());
        $missing = $missing_response->get_data();
        $this->assertSame('dbvc_entity_editor_raw_intake_update_confirmation_required', (string) ($missing['code'] ?? ''));
        $this->assertSame('Raw Intake Matched Update Before', (string) get_the_title($post_id));

        $stale_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/raw-intake/commit',
            [
                'content'      => $content,
                'mode'         => 'create_or_update_matched',
                'confirmation' => [
                    'confirmed'         => true,
                    'preview_hash'      => 'stale-preview-hash',
                    'matched_entity_id' => $post_id,
                ],
            ]
        );

        $this->assertSame(409, $stale_response->get_status());
        $stale = $stale_response->get_data();
        $this->assertSame('dbvc_entity_editor_raw_intake_update_stale_preview', (string) ($stale['code'] ?? ''));
        $this->assertSame('Raw Intake Matched Update Before', (string) get_the_title($post_id));

        $commit_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/raw-intake/commit',
            [
                'content'      => $content,
                'mode'         => 'create_or_update_matched',
                'confirmation' => [
                    'confirmed'         => true,
                    'preview_hash'      => (string) ($preview['preview_hash'] ?? ''),
                    'matched_entity_id' => $post_id,
                ],
            ]
        );

        $this->assertSame(200, $commit_response->get_status());
        $result = $commit_response->get_data();

        $this->assertSame('update_matched', (string) ($result['action'] ?? ''));
        $this->assertFalse((bool) ($result['created'] ?? true));
        $this->assertTrue((bool) ($result['updated'] ?? false));
        $this->assertSame($post_id, (int) ($result['wp_entity']['id'] ?? 0));
        $this->assertSame('Raw Intake Matched Update After', (string) get_the_title($post_id));
        $this->assertSame('confirmed-update', (string) get_post_meta($post_id, 'raw_intake_update_meta', true));
    }

    public function test_raw_intake_preview_ignores_stale_entity_registry_match(): void
    {
        if (! class_exists('DBVC_Database')) {
            $this->markTestSkipped('DBVC entity registry unavailable.');
        }

        $uid = 'raw-intake-stale-registry-uid';
        DBVC_Database::upsert_entity([
            'entity_uid'    => $uid,
            'object_type'   => 'page',
            'object_id'     => 987654321,
            'object_status' => 'publish',
        ]);

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/raw-intake/preview',
            [
                'content' => wp_json_encode([
                    'ID'            => 987654321,
                    'post_type'     => 'page',
                    'post_name'     => 'raw-intake-stale-registry-preview',
                    'post_title'    => 'Raw Intake Stale Registry Preview',
                    'vf_object_uid' => $uid,
                    'meta'          => [],
                    'tax_input'     => [],
                ]),
                'mode' => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $blocking_codes = array_column((array) ($data['blocking'] ?? []), 'code');

        $this->assertSame('create', $data['detected_action'] ?? '');
        $this->assertTrue((bool) ($data['available_actions']['create_only'] ?? false));
        $this->assertSame('none', $data['match']['status'] ?? '');
        $this->assertNotContains('matched_entity', $blocking_codes);
    }

    public function test_raw_intake_create_only_blocks_uidless_payload_id_collision(): void
    {
        $existing_post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Raw Intake Local Payload ID Collision',
            'post_status' => 'publish',
            'post_name'   => 'raw-intake-local-payload-id-collision',
        ]);

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/raw-intake/preview',
            [
                'content' => wp_json_encode([
                    'ID'           => $existing_post_id,
                    'post_type'    => 'page',
                    'post_name'    => 'raw-intake-incoming-cross-site-post',
                    'post_title'   => 'Raw Intake Incoming Cross Site Post',
                    'post_content' => '',
                    'post_excerpt' => '',
                    'meta'         => [],
                    'tax_input'    => [],
                ]),
                'mode' => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $blocking_codes = array_column((array) ($data['blocking'] ?? []), 'code');

        $this->assertFalse((bool) ($data['available_actions']['create_only'] ?? true));
        $this->assertSame('matched', (string) ($data['match']['status'] ?? ''));
        $this->assertSame($existing_post_id, (int) ($data['match']['id'] ?? 0));
        $this->assertSame('payload_id', (string) ($data['match']['match_source'] ?? ''));
        $this->assertContains('matched_entity', $blocking_codes);
    }

    public function test_raw_intake_preview_uses_meta_uid_before_payload_id_fallback(): void
    {
        update_option('dbvc_allow_uid_fallback_matching', '0');
        update_option('dbvc_export_filename_format', 'slug_id');
        $existing_post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Raw Intake Meta UID Local ID',
            'post_status' => 'publish',
            'post_name'   => 'raw-intake-meta-uid-local-id',
        ]);

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/raw-intake/preview',
            [
                'content' => wp_json_encode([
                    'ID'           => $existing_post_id,
                    'post_type'    => 'page',
                    'post_name'    => 'raw-intake-meta-uid-cross-site-post',
                    'post_title'   => 'Raw Intake Meta UID Cross Site Post',
                    'post_content' => '',
                    'post_excerpt' => '',
                    'meta'         => [
                        'vf_object_uid' => ['raw-intake-meta-only-cross-site-uid'],
                    ],
                    'tax_input'    => [],
                ]),
                'mode' => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();

        $this->assertSame('raw-intake-meta-only-cross-site-uid', (string) ($data['uid'] ?? ''));
        $this->assertTrue((bool) ($data['available_actions']['create_only'] ?? false));
        $this->assertSame('none', (string) ($data['match']['status'] ?? ''));
        $this->assertEmpty($data['blocking'] ?? []);
    }

    public function test_sync_file_import_preview_requires_manage_options(): void
    {
        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => ['page/preview-only.json'],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(403, $response->get_status());
    }

    public function test_sync_file_import_preview_reports_invalid_json(): void
    {
        $relative_path = 'page/page-sync-file-import-invalid-json.json';
        $this->write_sync_file($relative_path, "{invalid-json\n");

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => [$relative_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $item = $data['items'][0] ?? [];
        $blocking_codes = array_column((array) ($item['blocking'] ?? []), 'code');

        $this->assertSame(1, (int) ($data['summary']['blocked'] ?? 0));
        $this->assertContains('dbvc_entity_editor_invalid_json', $blocking_codes);
    }

    public function test_sync_file_import_preview_blocks_unsupported_json(): void
    {
        $relative_path = 'page/page-sync-file-import-unsupported.json';
        $this->write_sync_json_file($relative_path, [
            'label' => 'unsupported',
        ]);

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => [$relative_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $item = $data['items'][0] ?? [];
        $blocking_codes = array_column((array) ($item['blocking'] ?? []), 'code');

        $this->assertSame(1, (int) ($data['summary']['blocked'] ?? 0));
        $this->assertContains('unsupported_entity_kind', $blocking_codes);
    }

    public function test_sync_file_import_preview_reports_excluded_path(): void
    {
        $relative_path = 'options/options-sync-file-import-excluded.json';
        $this->write_sync_json_file($relative_path, [
            'option_name'  => 'sync_file_import_excluded',
            'option_value' => 'excluded',
        ]);

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => [$relative_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $item = $data['items'][0] ?? [];
        $blocking_codes = array_column((array) ($item['blocking'] ?? []), 'code');

        $this->assertSame(1, (int) ($data['summary']['blocked'] ?? 0));
        $this->assertContains('dbvc_entity_editor_excluded_path', $blocking_codes);
    }

    public function test_sync_file_import_preview_reports_post_creation_block_when_setting_disabled(): void
    {
        update_option('dbvc_allow_new_posts', '0');

        $relative_path = 'page/page-sync-file-import-creation-disabled-987654325.json';
        $this->write_sync_json_file($relative_path, [
            'ID'           => 987654325,
            'post_title'   => 'Sync File Import Creation Disabled',
            'post_content' => '',
            'post_excerpt' => '',
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_name'    => 'sync-file-import-creation-disabled',
            'meta'         => [],
            'tax_input'    => [],
        ]);

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => [$relative_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $item = $data['items'][0] ?? [];
        $blocking_codes = array_column((array) ($item['blocking'] ?? []), 'code');
        $setting_remediation_ids = array_column((array) ($item['setting_remediations'] ?? []), 'id');

        $this->assertFalse((bool) ($item['available_actions']['create_only'] ?? true));
        $this->assertContains('post_creation_disabled', $blocking_codes);
        $this->assertContains('enable_new_post_creation', $setting_remediation_ids);
        $this->assertNotEmpty($item['blocker_details'] ?? []);
        $this->assertNotEmpty($item['settings_links'] ?? []);
        $this->assertNotEmpty($item['preview_hash'] ?? '');
    }

    public function test_sync_file_import_preview_blocks_post_type_outside_whitelist(): void
    {
        update_option('dbvc_new_post_types_whitelist', ['post']);

        $relative_path = 'page/page-sync-file-import-whitelist-blocked-987654326.json';
        $this->write_sync_json_file($relative_path, [
            'ID'           => 987654326,
            'post_title'   => 'Sync File Import Whitelist Blocked',
            'post_content' => '',
            'post_excerpt' => '',
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_name'    => 'sync-file-import-whitelist-blocked',
            'meta'         => [],
            'tax_input'    => [],
        ]);

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => [$relative_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $item = $data['items'][0] ?? [];
        $blocking_codes = array_column((array) ($item['blocking'] ?? []), 'code');
        $setting_remediation_ids = array_column((array) ($item['setting_remediations'] ?? []), 'id');

        $this->assertFalse((bool) ($item['available_actions']['create_only'] ?? true));
        $this->assertContains('post_type_whitelist_blocked', $blocking_codes);
        $this->assertContains('allow_post_type_creation', $setting_remediation_ids);
        $this->assertNotEmpty($item['blocker_details'] ?? []);
        $this->assertNotEmpty($item['settings_links'] ?? []);
        $this->assertNotEmpty($item['preview_hash'] ?? '');
    }

    public function test_sync_file_import_preview_blocks_uidless_payload_id_collision(): void
    {
        $existing_post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Sync File Local Payload ID Collision',
            'post_status' => 'publish',
            'post_name'   => 'sync-file-local-payload-id-collision',
        ]);

        $relative_path = 'page/page-sync-file-import-payload-id-collision-' . $existing_post_id . '.json';
        $this->write_sync_json_file($relative_path, [
            'ID'           => $existing_post_id,
            'post_title'   => 'Sync File Incoming Cross Site Post',
            'post_content' => '',
            'post_excerpt' => '',
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_name'    => 'sync-file-incoming-cross-site-post',
            'meta'         => [],
            'tax_input'    => [],
        ]);
        delete_transient('dbvc_entity_editor_index_v1');

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => [$relative_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $item = $data['items'][0] ?? [];
        $blocking_codes = array_column((array) ($item['blocking'] ?? []), 'code');

        $this->assertSame(1, (int) ($data['summary']['blocked'] ?? 0));
        $this->assertFalse((bool) ($item['available_actions']['create_only'] ?? true));
        $this->assertSame('matched', (string) ($item['match']['status'] ?? ''));
        $this->assertSame($existing_post_id, (int) ($item['match']['id'] ?? 0));
        $this->assertSame('payload_id', (string) ($item['match']['match_source'] ?? ''));
        $this->assertContains('matched_entity', $blocking_codes);

        $commit_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/commit',
            [
                'paths' => [$relative_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $commit_response->get_status());
        $commit = $commit_response->get_data();
        $commit_item = $commit['items'][0] ?? [];
        $this->assertSame(1, (int) ($commit['summary']['blocked'] ?? 0));
        $this->assertSame('blocked', (string) ($commit_item['status'] ?? ''));
        $this->assertSame('Sync File Local Payload ID Collision', (string) get_the_title($existing_post_id));
    }

    public function test_sync_file_import_preview_ignores_payload_id_when_uid_present_and_fallback_disabled(): void
    {
        update_option('dbvc_allow_uid_fallback_matching', '0');
        $existing_post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Sync File UID Present Local ID',
            'post_status' => 'publish',
            'post_name'   => 'sync-file-uid-present-local-id',
        ]);

        $relative_path = 'page/page-sync-file-import-uid-present-id-collision-' . $existing_post_id . '.json';
        $this->write_sync_json_file($relative_path, [
            'ID'            => $existing_post_id,
            'vf_object_uid' => 'sync-file-import-new-cross-site-uid',
            'post_title'    => 'Sync File Incoming UID Present',
            'post_content'  => '',
            'post_excerpt'  => '',
            'post_type'     => 'page',
            'post_status'   => 'draft',
            'post_name'     => 'sync-file-incoming-uid-present',
            'meta'          => [
                'vf_object_uid' => ['sync-file-import-new-cross-site-uid'],
            ],
            'tax_input'     => [],
        ]);
        delete_transient('dbvc_entity_editor_index_v1');

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => [$relative_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $item = $data['items'][0] ?? [];

        $this->assertSame(1, (int) ($data['summary']['creatable'] ?? 0));
        $this->assertTrue((bool) ($item['available_actions']['create_only'] ?? false));
        $this->assertSame('none', (string) ($item['match']['status'] ?? ''));
        $this->assertEmpty($item['blocking'] ?? []);
    }

    public function test_sync_file_import_preview_blocks_payload_id_when_uid_fallback_enabled(): void
    {
        update_option('dbvc_allow_uid_fallback_matching', '1');
        $existing_post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Sync File UID Fallback Local ID',
            'post_status' => 'publish',
            'post_name'   => 'sync-file-uid-fallback-local-id',
        ]);

        $relative_path = 'page/page-sync-file-import-uid-fallback-id-collision-' . $existing_post_id . '.json';
        $this->write_sync_json_file($relative_path, [
            'ID'            => $existing_post_id,
            'vf_object_uid' => 'sync-file-import-fallback-cross-site-uid',
            'post_title'    => 'Sync File Incoming UID Fallback',
            'post_content'  => '',
            'post_excerpt'  => '',
            'post_type'     => 'page',
            'post_status'   => 'draft',
            'post_name'     => 'sync-file-incoming-uid-fallback',
            'meta'          => [
                'vf_object_uid' => ['sync-file-import-fallback-cross-site-uid'],
            ],
            'tax_input'     => [],
        ]);
        delete_transient('dbvc_entity_editor_index_v1');

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => [$relative_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $item = $data['items'][0] ?? [];
        $blocking_codes = array_column((array) ($item['blocking'] ?? []), 'code');

        $this->assertSame(1, (int) ($data['summary']['blocked'] ?? 0));
        $this->assertFalse((bool) ($item['available_actions']['create_only'] ?? true));
        $this->assertSame('matched', (string) ($item['match']['status'] ?? ''));
        $this->assertSame($existing_post_id, (int) ($item['match']['id'] ?? 0));
        $this->assertSame('payload_id', (string) ($item['match']['match_source'] ?? ''));
        $this->assertContains('matched_entity', $blocking_codes);
    }

    public function test_sync_file_import_remediation_enables_post_creation_setting(): void
    {
        update_option('dbvc_allow_new_posts', '0');

        $relative_path = 'page/page-sync-file-import-remediate-creation-disabled-987654330.json';
        $this->write_sync_json_file($relative_path, [
            'ID'           => 987654330,
            'post_title'   => 'Sync File Import Remediate Creation Disabled',
            'post_content' => '',
            'post_excerpt' => '',
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_name'    => 'sync-file-import-remediate-creation-disabled',
            'meta'         => [],
            'tax_input'    => [],
        ]);

        $preview_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => [$relative_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $preview_response->get_status());
        $preview = $preview_response->get_data();
        $preview_item = $preview['items'][0] ?? [];

        $remediate_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/remediate',
            [
                'path'         => $relative_path,
                'mode'         => 'create_only',
                'remediation'  => 'enable_new_post_creation',
                'preview_hash' => (string) ($preview_item['preview_hash'] ?? ''),
            ]
        );

        $this->assertSame(200, $remediate_response->get_status());
        $data = $remediate_response->get_data();
        $item = $data['items'][0] ?? [];

        $this->assertSame('1', (string) get_option('dbvc_allow_new_posts'));
        $this->assertSame('enable_new_post_creation', (string) ($data['remediation_result']['action'] ?? ''));
        $this->assertSame(1, (int) ($data['summary']['creatable'] ?? 0));
        $this->assertTrue((bool) ($item['available_actions']['create_only'] ?? false));
        $this->assertEmpty($item['blocking'] ?? []);
    }

    public function test_sync_file_import_remediation_adds_post_type_to_whitelist(): void
    {
        update_option('dbvc_new_post_types_whitelist', ['post']);

        $relative_path = 'page/page-sync-file-import-remediate-whitelist-987654331.json';
        $this->write_sync_json_file($relative_path, [
            'ID'           => 987654331,
            'post_title'   => 'Sync File Import Remediate Whitelist',
            'post_content' => '',
            'post_excerpt' => '',
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_name'    => 'sync-file-import-remediate-whitelist',
            'meta'         => [],
            'tax_input'    => [],
        ]);

        $preview_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => [$relative_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $preview_response->get_status());
        $preview = $preview_response->get_data();
        $preview_item = $preview['items'][0] ?? [];

        $remediate_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/remediate',
            [
                'path'         => $relative_path,
                'mode'         => 'create_only',
                'remediation'  => 'allow_post_type_creation',
                'preview_hash' => (string) ($preview_item['preview_hash'] ?? ''),
            ]
        );

        $this->assertSame(200, $remediate_response->get_status());
        $data = $remediate_response->get_data();
        $item = $data['items'][0] ?? [];
        $whitelist = (array) get_option('dbvc_new_post_types_whitelist', []);

        $this->assertContains('post', $whitelist);
        $this->assertContains('page', $whitelist);
        $this->assertSame('allow_post_type_creation', (string) ($data['remediation_result']['action'] ?? ''));
        $this->assertSame(1, (int) ($data['summary']['creatable'] ?? 0));
        $this->assertTrue((bool) ($item['available_actions']['create_only'] ?? false));
        $this->assertEmpty($item['blocking'] ?? []);
    }

    public function test_sync_file_import_preview_blocks_stale_duplicate_but_allows_canonical_duplicate(): void
    {
        $uid = 'sync-file-import-duplicate-uid';
        $canonical_path = 'page/page-sync-file-import-duplicate-123456001.json';
        $stale_path = 'page/page-sync-file-import-duplicate-987654001.json';
        $payload = [
            'ID'            => 123456001,
            'vf_object_uid' => $uid,
            'post_title'    => 'Sync File Import Duplicate',
            'post_content'  => '',
            'post_excerpt'  => '',
            'post_type'     => 'page',
            'post_status'   => 'draft',
            'post_name'     => 'sync-file-import-duplicate',
            'meta'          => [
                'vf_object_uid' => [$uid],
            ],
            'tax_input'     => [],
        ];

        $this->write_sync_json_file($canonical_path, $payload);
        $this->write_sync_json_file($stale_path, $payload);
        delete_transient('dbvc_entity_editor_index_v1');

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => [$canonical_path, $stale_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $items = (array) ($data['items'] ?? []);
        $canonical_item = $items[0] ?? [];
        $stale_item = $items[1] ?? [];
        $stale_blocking_codes = array_column((array) ($stale_item['blocking'] ?? []), 'code');
        $stale_override_ids = array_column((array) ($stale_item['advanced_overrides'] ?? []), 'id');

        $this->assertSame(1, (int) ($data['summary']['creatable'] ?? 0));
        $this->assertSame(1, (int) ($data['summary']['blocked'] ?? 0));
        $this->assertTrue((bool) ($canonical_item['available_actions']['create_only'] ?? false));
        $this->assertContains('stale_duplicate_file', $stale_blocking_codes);
        $this->assertContains('use_canonical_row', $stale_override_ids);
        $this->assertContains('archive_stale_duplicate', $stale_override_ids);
        $this->assertSame($canonical_path, (string) ($stale_item['canonical_relative_path'] ?? ''));
        $this->assertEmpty($stale_item['settings_links'] ?? []);
    }

    public function test_sync_file_import_remediation_archives_stale_duplicate_file(): void
    {
        $uid = 'sync-file-import-archive-duplicate-uid';
        $canonical_path = 'page/page-sync-file-import-archive-duplicate-123456002.json';
        $stale_path = 'page/page-sync-file-import-archive-duplicate-987654002.json';
        $payload = [
            'ID'            => 123456002,
            'vf_object_uid' => $uid,
            'post_title'    => 'Sync File Import Archive Duplicate',
            'post_content'  => '',
            'post_excerpt'  => '',
            'post_type'     => 'page',
            'post_status'   => 'draft',
            'post_name'     => 'sync-file-import-archive-duplicate',
            'meta'          => [
                'vf_object_uid' => [$uid],
            ],
            'tax_input'     => [],
        ];

        $this->write_sync_json_file($canonical_path, $payload);
        $this->write_sync_json_file($stale_path, $payload);
        delete_transient('dbvc_entity_editor_index_v1');

        $preview_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => [$stale_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $preview_response->get_status());
        $preview = $preview_response->get_data();
        $preview_item = $preview['items'][0] ?? [];
        $stale_blocking_codes = array_column((array) ($preview_item['blocking'] ?? []), 'code');

        $this->assertContains('stale_duplicate_file', $stale_blocking_codes);
        $this->assertFileExists($this->absolute_sync_path($stale_path));

        $remediate_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/remediate',
            [
                'path'         => $stale_path,
                'mode'         => 'create_only',
                'remediation'  => 'archive_stale_duplicate',
                'preview_hash' => (string) ($preview_item['preview_hash'] ?? ''),
            ]
        );

        $this->assertSame(200, $remediate_response->get_status());
        $data = $remediate_response->get_data();
        $item = $data['items'][0] ?? [];
        $archive = (array) ($data['remediation_result']['archive'] ?? []);

        $this->assertSame('archive_stale_duplicate', (string) ($data['remediation_result']['action'] ?? ''));
        $this->assertSame($stale_path, (string) ($archive['relative_path'] ?? ''));
        $this->assertSame($canonical_path, (string) ($item['relative_path'] ?? ''));
        $this->assertSame(1, (int) ($data['summary']['creatable'] ?? 0));
        $this->assertFileDoesNotExist($this->absolute_sync_path($stale_path));
        $this->assertFileExists($this->absolute_sync_path($canonical_path));
        $this->assertFileExists($this->absolute_sync_path((string) ($archive['backup_path'] ?? '')));
    }

    public function test_sync_file_import_preview_reports_bricks_template_condition_conflict_when_addon_enabled(): void
    {
        if (! class_exists('DBVC_Bricks_Addon')) {
            $this->markTestSkipped('DBVC Bricks add-on class unavailable.');
        }

        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        $this->ensure_test_post_type('bricks_template', [
            'public'  => false,
            'show_ui' => true,
        ]);
        $this->ensure_test_post_type('listing', [
            'public'  => true,
            'show_ui' => true,
        ]);

        $existing_template_id = self::factory()->post->create([
            'post_type'   => 'bricks_template',
            'post_title'  => 'Existing Listing Template',
            'post_status' => 'publish',
            'post_name'   => 'existing-listing-template',
        ]);
        update_post_meta($existing_template_id, '_bricks_template_type', 'content');
        update_post_meta($existing_template_id, '_bricks_template_settings', [
            'templateConditions'  => [
                [
                    'id'       => 'existing',
                    'main'     => 'postType',
                    'postType' => ['listing'],
                ],
            ],
            'templatePreviewType' => 'single',
        ]);

        $relative_path = 'bricks_template/bricks_template-new-listing-template-987654327.json';
        $this->write_sync_json_file($relative_path, [
            'ID'            => 987654327,
            'vf_object_uid' => 'sync-file-import-bricks-conflict-uid',
            'post_title'    => 'New Listing Template',
            'post_content'  => '',
            'post_excerpt'  => '',
            'post_type'     => 'bricks_template',
            'post_status'   => 'publish',
            'post_name'     => 'new-listing-template',
            'meta'          => [
                'vf_object_uid'             => ['sync-file-import-bricks-conflict-uid'],
                '_bricks_template_type'     => ['content'],
                '_bricks_template_settings' => [
                    [
                        'templateConditions'  => [
                            [
                                'id'       => 'incoming',
                                'main'     => 'postType',
                                'postType' => ['listing'],
                            ],
                        ],
                        'templatePreviewType' => 'single',
                    ],
                ],
            ],
            'tax_input'     => [],
        ]);
        delete_transient('dbvc_entity_editor_index_v1');

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => [$relative_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $item = $data['items'][0] ?? [];
        $advisory = (array) ($item['bricks_template_advisory'] ?? []);
        $conflicts = (array) ($advisory['condition_conflicts'] ?? []);
        $messages = (array) ($advisory['messages'] ?? []);

        $this->assertSame(1, (int) ($data['summary']['creatable'] ?? 0));
        $this->assertTrue((bool) ($item['available_actions']['create_only'] ?? false));
        $this->assertEmpty($item['blocking'] ?? []);
        $this->assertTrue((bool) ($advisory['enabled'] ?? false));
        $this->assertSame('warning', (string) ($advisory['severity'] ?? ''));
        $this->assertSame('content', (string) ($advisory['template_type'] ?? ''));
        $this->assertContains('postType: listing', (array) ($advisory['conditions'] ?? []));
        $this->assertCount(1, $conflicts);
        $this->assertSame($existing_template_id, (int) ($conflicts[0]['id'] ?? 0));
        $this->assertStringContainsString('may render instead', implode(' ', $messages));
    }

    public function test_sync_file_import_preview_reports_missing_bricks_preview_post_without_blocking(): void
    {
        if (! class_exists('DBVC_Bricks_Addon')) {
            $this->markTestSkipped('DBVC Bricks add-on class unavailable.');
        }

        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        $this->ensure_test_post_type('bricks_template', [
            'public'  => false,
            'show_ui' => true,
        ]);

        $missing_preview_post_id = 987654328;
        $relative_path = 'bricks_template/bricks_template-missing-preview-post-987654329.json';
        $this->write_sync_json_file($relative_path, [
            'ID'            => 987654329,
            'vf_object_uid' => 'sync-file-import-bricks-missing-preview-uid',
            'post_title'    => 'Missing Preview Post Template',
            'post_content'  => '',
            'post_excerpt'  => '',
            'post_type'     => 'bricks_template',
            'post_status'   => 'publish',
            'post_name'     => 'missing-preview-post-template',
            'meta'          => [
                'vf_object_uid'             => ['sync-file-import-bricks-missing-preview-uid'],
                '_bricks_template_type'     => ['content'],
                '_bricks_template_settings' => [
                    [
                        'templateConditions'    => [
                            [
                                'id'       => 'incoming',
                                'main'     => 'postType',
                                'postType' => ['listing'],
                            ],
                        ],
                        'templatePreviewType'   => 'single',
                        'templatePreviewPostId' => (string) $missing_preview_post_id,
                    ],
                ],
            ],
            'tax_input'     => [],
        ]);
        delete_transient('dbvc_entity_editor_index_v1');

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => [$relative_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $item = $data['items'][0] ?? [];
        $advisory = (array) ($item['bricks_template_advisory'] ?? []);
        $preview_post = (array) ($advisory['preview_post'] ?? []);
        $messages = (array) ($advisory['messages'] ?? []);

        $this->assertSame(1, (int) ($data['summary']['creatable'] ?? 0));
        $this->assertTrue((bool) ($item['available_actions']['create_only'] ?? false));
        $this->assertEmpty($item['blocking'] ?? []);
        $this->assertTrue((bool) ($advisory['enabled'] ?? false));
        $this->assertSame('warning', (string) ($advisory['severity'] ?? ''));
        $this->assertSame($missing_preview_post_id, (int) ($preview_post['incoming_id'] ?? 0));
        $this->assertFalse((bool) ($preview_post['exists_locally'] ?? true));
        $this->assertStringContainsString('does not exist locally', implode(' ', $messages));
    }

    public function test_sync_file_import_commit_creates_unmatched_post_from_existing_sync_json(): void
    {
        if (! class_exists('DBVC_Database')) {
            $this->markTestSkipped('DBVC entity registry unavailable.');
        }

        $uid = 'sync-file-import-stale-registry-uid';
        $relative_path = 'page/page-sync-file-import-created-post-987654322.json';
        $stale_duplicate_path = 'page/page-sync-file-import-created-post.json';
        DBVC_Database::upsert_entity([
            'entity_uid'    => $uid,
            'object_type'   => 'page',
            'object_id'     => 987654322,
            'object_status' => 'publish',
        ]);

        $payload = [
            'ID'            => 987654322,
            'vf_object_uid' => $uid,
            'post_title'    => 'Sync File Import Created Post',
            'post_content'  => 'Created from an existing sync JSON file.',
            'post_excerpt'  => '',
            'post_type'     => 'page',
            'post_status'   => 'draft',
            'post_name'     => 'sync-file-import-created-post',
            'meta'          => [
                'vf_object_uid'         => [$uid],
                'sync_file_import_meta' => ['hello-sync-file'],
            ],
            'tax_input'     => [],
        ];
        $this->write_sync_json_file($relative_path, $payload);
        $this->write_sync_json_file($stale_duplicate_path, $payload);
        delete_transient('dbvc_entity_editor_index_v1');

        $preview_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => [$relative_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $preview_response->get_status());
        $preview = $preview_response->get_data();
        $preview_item = $preview['items'][0] ?? [];
        $blocking_codes = array_column((array) ($preview_item['blocking'] ?? []), 'code');

        $this->assertSame(1, (int) ($preview['summary']['creatable'] ?? 0));
        $this->assertTrue((bool) ($preview_item['available_actions']['create_only'] ?? false));
        $this->assertSame('none', $preview_item['match']['status'] ?? '');
        $this->assertNotContains('matched_entity', $blocking_codes);

        $commit_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/commit',
            [
                'paths' => [$relative_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $commit_response->get_status());
        $result = $commit_response->get_data();
        $result_item = $result['items'][0] ?? [];
        $created_post_id = (int) ($result_item['wp_entity']['id'] ?? 0);
        $final_relative_path = (string) ($result_item['relative_path'] ?? '');

        $this->assertSame(1, (int) ($result['summary']['created'] ?? 0));
        $this->assertTrue((bool) ($result_item['created'] ?? false));
        $this->assertGreaterThan(0, $created_post_id);
        $this->assertNotSame($relative_path, $final_relative_path);
        $this->assertFileDoesNotExist($this->absolute_sync_path($relative_path));
        $this->assertFileDoesNotExist($this->absolute_sync_path($stale_duplicate_path));
        $this->assertFileExists($this->absolute_sync_path($final_relative_path));
        $this->assertTrue((bool) ($result_item['file_normalization']['moved'] ?? false));
        $archived_duplicates = (array) ($result_item['file_normalization']['archived_duplicates'] ?? []);
        $this->assertCount(1, $archived_duplicates);
        $this->assertSame($stale_duplicate_path, (string) ($archived_duplicates[0]['relative_path'] ?? ''));
        $this->assertFileExists($this->absolute_sync_path((string) ($archived_duplicates[0]['backup_path'] ?? '')));
        $this->assertCount(1, glob(trailingslashit($this->sync_dir) . 'page/*.json') ?: []);

        $post = get_page_by_path('sync-file-import-created-post', OBJECT, 'page');
        $this->assertInstanceOf(WP_Post::class, $post);
        $this->assertSame($created_post_id, (int) $post->ID);
        $this->assertSame('Sync File Import Created Post', $post->post_title);
        $this->assertSame('hello-sync-file', (string) get_post_meta($post->ID, 'sync_file_import_meta', true));
        $this->assertSame($uid, (string) get_post_meta($post->ID, 'vf_object_uid', true));

        $final_payload = json_decode((string) file_get_contents($this->absolute_sync_path($final_relative_path)), true);
        $this->assertIsArray($final_payload);
        $this->assertSame((int) $post->ID, (int) ($final_payload['ID'] ?? 0));
    }

    public function test_sync_file_import_bulk_commit_reports_mixed_created_and_blocked_items(): void
    {
        $existing_post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Sync File Import Existing Batch Post',
            'post_status' => 'publish',
            'post_name'   => 'sync-file-import-existing-batch-post',
        ]);
        update_post_meta($existing_post_id, 'vf_object_uid', 'sync-file-import-existing-batch-uid');

        $creatable_path = 'page/page-sync-file-import-bulk-created-987654323.json';
        $blocked_path = 'page/page-sync-file-import-existing-batch-post-' . $existing_post_id . '.json';

        $this->write_sync_json_file($creatable_path, [
            'ID'            => 987654323,
            'vf_object_uid' => 'sync-file-import-bulk-created-uid',
            'post_title'    => 'Sync File Import Bulk Created',
            'post_content'  => 'Created from a bulk sync import.',
            'post_excerpt'  => '',
            'post_type'     => 'page',
            'post_status'   => 'draft',
            'post_name'     => 'sync-file-import-bulk-created',
            'meta'          => [
                'vf_object_uid' => ['sync-file-import-bulk-created-uid'],
            ],
            'tax_input'     => [],
        ]);

        $this->write_sync_json_file($blocked_path, [
            'ID'            => $existing_post_id,
            'vf_object_uid' => 'sync-file-import-existing-batch-uid',
            'post_title'    => 'Sync File Import Existing Batch Post',
            'post_content'  => '',
            'post_excerpt'  => '',
            'post_type'     => 'page',
            'post_status'   => 'publish',
            'post_name'     => 'sync-file-import-existing-batch-post',
            'meta'          => [
                'vf_object_uid' => ['sync-file-import-existing-batch-uid'],
            ],
            'tax_input'     => [],
        ]);
        delete_transient('dbvc_entity_editor_index_v1');

        $preview_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => [$creatable_path, $blocked_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $preview_response->get_status());
        $preview = $preview_response->get_data();
        $this->assertSame(2, (int) ($preview['summary']['requested'] ?? 0));
        $this->assertSame(1, (int) ($preview['summary']['creatable'] ?? 0));
        $this->assertSame(1, (int) ($preview['summary']['blocked'] ?? 0));

        $commit_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/commit',
            [
                'paths' => [$creatable_path, $blocked_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $commit_response->get_status());
        $result = $commit_response->get_data();
        $items = (array) ($result['items'] ?? []);

        $this->assertSame(2, (int) ($result['summary']['requested'] ?? 0));
        $this->assertSame(1, (int) ($result['summary']['created'] ?? 0));
        $this->assertSame(1, (int) ($result['summary']['blocked'] ?? 0));
        $this->assertCount(2, $items);
        $this->assertTrue((bool) ($items[0]['created'] ?? false));
        $this->assertSame('blocked', (string) ($items[1]['status'] ?? ''));

        $created_post = get_page_by_path('sync-file-import-bulk-created', OBJECT, 'page');
        $this->assertInstanceOf(WP_Post::class, $created_post);
        $this->assertSame('sync-file-import-bulk-created-uid', (string) get_post_meta($created_post->ID, 'vf_object_uid', true));
        $this->assertSame($existing_post_id, (int) get_page_by_path('sync-file-import-existing-batch-post', OBJECT, 'page')->ID);
    }

    public function test_sync_file_import_preview_exposes_confirmed_update_for_uid_matched_post(): void
    {
        update_option('dbvc_export_filename_format', 'slug_id');

        $uid = 'sync-file-import-matched-update-uid';
        $existing_post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Sync File Matched Update Before',
            'post_status' => 'publish',
            'post_name'   => 'sync-file-matched-update',
        ]);
        update_post_meta($existing_post_id, 'vf_object_uid', $uid);

        $this->delete_directory($this->sync_dir);
        wp_mkdir_p($this->sync_dir);

        $source_id = 987654340;
        $relative_path = 'page/page-sync-file-matched-update-' . $source_id . '.json';
        $this->write_sync_json_file($relative_path, [
            'ID'            => $source_id,
            'vf_object_uid' => $uid,
            'post_title'    => 'Sync File Matched Update After',
            'post_content'  => 'Updated content from matched sync JSON.',
            'post_excerpt'  => '',
            'post_type'     => 'page',
            'post_status'   => 'publish',
            'post_name'     => 'sync-file-matched-update',
            'meta'          => [
                'vf_object_uid'              => [$uid],
                'sync_file_matched_update'   => ['updated-meta'],
            ],
            'tax_input'     => [],
        ]);
        delete_transient('dbvc_entity_editor_index_v1');

        $preview_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => [$relative_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $preview_response->get_status());
        $preview = $preview_response->get_data();
        $item = $preview['items'][0] ?? [];
        $matched_update = (array) ($item['matched_update'] ?? []);
        $blocking_codes = array_column((array) ($item['blocking'] ?? []), 'code');

        $this->assertSame(0, (int) ($preview['summary']['creatable'] ?? -1));
        $this->assertSame(1, (int) ($preview['summary']['updatable'] ?? 0));
        $this->assertFalse((bool) ($item['available_actions']['create_only'] ?? true));
        $this->assertTrue((bool) ($item['available_actions']['update_matched'] ?? false));
        $this->assertContains('matched_entity', $blocking_codes);
        $this->assertTrue((bool) ($matched_update['eligible'] ?? false));
        $this->assertSame($existing_post_id, (int) ($matched_update['wp_entity']['id'] ?? 0));
        $this->assertSame('uid', (string) ($matched_update['match_source'] ?? ''));

        $blocked_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/commit',
            [
                'paths' => [$relative_path],
                'mode'  => 'update_matched',
            ]
        );

        $this->assertSame(200, $blocked_response->get_status());
        $blocked = $blocked_response->get_data();
        $blocked_item = $blocked['items'][0] ?? [];
        $blocked_codes = array_column((array) ($blocked_item['blocking'] ?? []), 'code');

        $this->assertSame(1, (int) ($blocked['summary']['blocked'] ?? 0));
        $this->assertContains('matched_update_confirmation_required', $blocked_codes);
        $this->assertSame('Sync File Matched Update Before', (string) get_the_title($existing_post_id));

        $commit_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/commit',
            [
                'paths'         => [$relative_path],
                'mode'          => 'update_matched',
                'confirmations' => [
                    $relative_path => [
                        'confirmed'         => true,
                        'preview_hash'      => (string) ($item['preview_hash'] ?? ''),
                        'matched_entity_id' => $existing_post_id,
                    ],
                ],
            ]
        );

        $this->assertSame(200, $commit_response->get_status());
        $result = $commit_response->get_data();
        $result_item = $result['items'][0] ?? [];
        $final_relative_path = (string) ($result_item['relative_path'] ?? '');

        $this->assertSame(1, (int) ($result['summary']['updated'] ?? 0));
        $this->assertTrue((bool) ($result_item['updated'] ?? false));
        $this->assertFalse((bool) ($result_item['created'] ?? true));
        $this->assertSame($existing_post_id, (int) ($result_item['wp_entity']['id'] ?? 0));
        $this->assertSame('Sync File Matched Update After', (string) get_the_title($existing_post_id));
        $this->assertSame('updated-meta', (string) get_post_meta($existing_post_id, 'sync_file_matched_update', true));
        $this->assertNotSame($relative_path, $final_relative_path);
        $this->assertFileDoesNotExist($this->absolute_sync_path($relative_path));
        $this->assertFileExists($this->absolute_sync_path($final_relative_path));

        $final_payload = json_decode((string) file_get_contents($this->absolute_sync_path($final_relative_path)), true);
        $this->assertIsArray($final_payload);
        $this->assertSame($existing_post_id, (int) ($final_payload['ID'] ?? 0));
        $this->assertSame($uid, (string) ($final_payload['vf_object_uid'] ?? ''));
    }

    public function test_sync_file_import_update_matched_blocks_stale_preview_hash(): void
    {
        $uid = 'sync-file-import-stale-preview-update-uid';
        $existing_post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Sync File Stale Preview Before',
            'post_status' => 'publish',
            'post_name'   => 'sync-file-stale-preview-before',
        ]);
        update_post_meta($existing_post_id, 'vf_object_uid', $uid);

        $this->delete_directory($this->sync_dir);
        wp_mkdir_p($this->sync_dir);

        $relative_path = 'page/page-sync-file-stale-preview-update-987654341.json';
        $this->write_sync_json_file($relative_path, [
            'ID'            => 987654341,
            'vf_object_uid' => $uid,
            'post_title'    => 'Sync File Stale Preview After',
            'post_content'  => '',
            'post_excerpt'  => '',
            'post_type'     => 'page',
            'post_status'   => 'publish',
            'post_name'     => 'sync-file-stale-preview-before',
            'meta'          => [
                'vf_object_uid' => [$uid],
            ],
            'tax_input'     => [],
        ]);
        delete_transient('dbvc_entity_editor_index_v1');

        $response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/commit',
            [
                'paths'         => [$relative_path],
                'mode'          => 'update_matched',
                'confirmations' => [
                    $relative_path => [
                        'confirmed'         => true,
                        'preview_hash'      => 'stale-preview-hash',
                        'matched_entity_id' => $existing_post_id,
                    ],
                ],
            ]
        );

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $item = $data['items'][0] ?? [];
        $blocking_codes = array_column((array) ($item['blocking'] ?? []), 'code');

        $this->assertSame(1, (int) ($data['summary']['blocked'] ?? 0));
        $this->assertContains('matched_update_stale_preview', $blocking_codes);
        $this->assertSame('Sync File Stale Preview Before', (string) get_the_title($existing_post_id));
    }

    public function test_sync_file_import_slug_only_match_does_not_offer_update_matched(): void
    {
        $existing_post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Sync File Slug Only Existing',
            'post_status' => 'publish',
            'post_name'   => 'sync-file-slug-only-existing',
        ]);

        $this->delete_directory($this->sync_dir);
        wp_mkdir_p($this->sync_dir);

        $relative_path = 'page/page-sync-file-slug-only-existing-987654342.json';
        $this->write_sync_json_file($relative_path, [
            'ID'           => 987654342,
            'post_title'   => 'Sync File Slug Only Incoming',
            'post_content' => '',
            'post_excerpt' => '',
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_name'    => 'sync-file-slug-only-existing',
            'meta'         => [],
            'tax_input'    => [],
        ]);
        delete_transient('dbvc_entity_editor_index_v1');

        $preview_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => [$relative_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $preview_response->get_status());
        $preview = $preview_response->get_data();
        $item = $preview['items'][0] ?? [];

        $this->assertSame(0, (int) ($preview['summary']['updatable'] ?? 0));
        $this->assertFalse((bool) ($item['available_actions']['update_matched'] ?? true));
        $this->assertArrayNotHasKey('matched_update', (array) $item);
        $this->assertSame('slug', (string) ($item['match']['match_source'] ?? ''));
        $this->assertSame($existing_post_id, (int) ($item['match']['id'] ?? 0));
    }

    public function test_sync_file_import_commit_creates_unmatched_term_from_existing_sync_json(): void
    {
        if (! class_exists('DBVC_Database')) {
            $this->markTestSkipped('DBVC entity registry unavailable.');
        }

        $uid = 'sync-file-import-term-created-uid';
        $relative_path = 'taxonomy/category/category-sync-file-import-created-term-987654324.json';
        DBVC_Database::upsert_entity([
            'entity_uid'    => $uid,
            'object_type'   => 'term:category',
            'object_id'     => 987654324,
            'object_status' => 'active',
        ]);

        $this->write_sync_json_file($relative_path, [
            'term_id'       => 987654324,
            'vf_object_uid' => $uid,
            'taxonomy'      => 'category',
            'slug'          => 'sync-file-import-created-term',
            'name'          => 'Sync File Import Created Term',
            'description'   => 'Created from an existing taxonomy sync JSON file.',
            'meta'          => [
                'vf_object_uid'        => [$uid],
                'sync_file_term_meta'  => ['hello-sync-term'],
            ],
        ]);
        delete_transient('dbvc_entity_editor_index_v1');

        $preview_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/preview',
            [
                'paths' => [$relative_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $preview_response->get_status());
        $preview = $preview_response->get_data();
        $preview_item = $preview['items'][0] ?? [];
        $blocking_codes = array_column((array) ($preview_item['blocking'] ?? []), 'code');

        $this->assertSame(1, (int) ($preview['summary']['creatable'] ?? 0));
        $this->assertSame('term', (string) ($preview_item['entity_kind'] ?? ''));
        $this->assertTrue((bool) ($preview_item['available_actions']['create_only'] ?? false));
        $this->assertSame('none', $preview_item['match']['status'] ?? '');
        $this->assertNotContains('matched_entity', $blocking_codes);

        $commit_response = $this->dispatch_json_request(
            'POST',
            '/dbvc/v1/entity-editor/sync-file-import/commit',
            [
                'paths' => [$relative_path],
                'mode'  => 'create_only',
            ]
        );

        $this->assertSame(200, $commit_response->get_status());
        $result = $commit_response->get_data();
        $result_item = $result['items'][0] ?? [];
        $created_term_id = (int) ($result_item['wp_entity']['id'] ?? 0);
        $final_relative_path = (string) ($result_item['relative_path'] ?? '');

        $this->assertSame(1, (int) ($result['summary']['created'] ?? 0));
        $this->assertTrue((bool) ($result_item['created'] ?? false));
        $this->assertGreaterThan(0, $created_term_id);
        $this->assertNotSame($relative_path, $final_relative_path);
        $this->assertFileDoesNotExist($this->absolute_sync_path($relative_path));
        $this->assertFileExists($this->absolute_sync_path($final_relative_path));
        $this->assertTrue((bool) ($result_item['file_normalization']['moved'] ?? false));
        $this->assertCount(1, glob(trailingslashit($this->sync_dir) . 'taxonomy/category/*.json') ?: []);

        $term = get_term_by('slug', 'sync-file-import-created-term', 'category');
        $this->assertInstanceOf(WP_Term::class, $term);
        $this->assertSame($created_term_id, (int) $term->term_id);
        $this->assertSame('Sync File Import Created Term', $term->name);
        $this->assertSame('hello-sync-term', (string) get_term_meta($term->term_id, 'sync_file_term_meta', true));
        $this->assertSame($uid, (string) get_term_meta($term->term_id, 'vf_object_uid', true));

        $final_payload = json_decode((string) file_get_contents($this->absolute_sync_path($final_relative_path)), true);
        $this->assertIsArray($final_payload);
        $this->assertSame($created_term_id, (int) ($final_payload['term_id'] ?? 0));
        $this->assertSame('category', (string) ($final_payload['taxonomy'] ?? ''));
        $this->assertSame($uid, (string) ($final_payload['vf_object_uid'] ?? ''));
    }

    /**
     * @param string $method
     * @param string $route
     * @param array<string,mixed> $body
     * @return WP_REST_Response
     */
    private function dispatch_json_request(string $method, string $route, array $body): WP_REST_Response
    {
        $server = rest_get_server();
        $request = new WP_REST_Request($method, $route);
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode($body));

        return $server->dispatch($request);
    }

    /**
     * @param string              $post_type
     * @param array<string,mixed> $args
     * @return void
     */
    private function ensure_test_post_type(string $post_type, array $args): void
    {
        if (post_type_exists($post_type)) {
            return;
        }

        register_post_type($post_type, array_merge([
            'label'        => $post_type,
            'show_in_rest' => true,
            'supports'     => ['title', 'editor', 'excerpt'],
        ], $args));
        $this->registered_test_post_types[] = $post_type;
    }

    private function absolute_sync_path(string $relative_path): string
    {
        return trailingslashit($this->sync_dir) . ltrim(str_replace('\\', '/', $relative_path), '/');
    }

    private function write_sync_file(string $relative_path, string $content): void
    {
        $path = $this->absolute_sync_path($relative_path);
        wp_mkdir_p(dirname($path));
        file_put_contents($path, $content);
    }

    /**
     * @param string $relative_path
     * @param array<string,mixed> $payload
     * @return void
     */
    private function write_sync_json_file(string $relative_path, array $payload): void
    {
        $path = $this->absolute_sync_path($relative_path);
        wp_mkdir_p(dirname($path));
        file_put_contents($path, wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * @param string $label
     * @param int    $source_id
     * @param string $uid
     * @return array<string,mixed>
     */
    private function wsform_test_payload(string $label, int $source_id, string $uid): array
    {
        return [
            'identifier' => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
            'id'         => $source_id,
            'label'      => $label,
            'status'     => 'publish',
            'meta'       => [
                'export_object'        => 'form',
                'dbvc_portability_uid' => $uid,
            ],
            'groups'     => [
                [
                    'label'    => 'Default',
                    'sections' => [
                        [
                            'label'  => 'Main',
                            'fields' => [
                                [
                                    'type'  => 'text',
                                    'label' => 'Name',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'dbvc_portability' => [
                'provider'      => DBVC_Third_Party_Portability::PROVIDER_WSFORM,
                'object_type'   => 'form',
                'uid'           => $uid,
                'source_id'     => $source_id,
                'source_status' => 'publish',
            ],
        ];
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
