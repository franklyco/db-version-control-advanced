<?php

class EntityEditorEndpointsTest extends WP_UnitTestCase
{
    /** @var string */
    private $sync_dir = '';

    /** @var string */
    private $sync_option_before = '';

    /** @var string */
    private $allow_uid_fallback_before = '';

    /** @var array<int,string> */
    private $registered_test_post_types = [];

    public function set_up(): void
    {
        parent::set_up();

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->sync_option_before = (string) get_option('dbvc_sync_path', '');
        $this->allow_uid_fallback_before = (string) get_option('dbvc_allow_uid_fallback_matching', '');
        $this->sync_dir = trailingslashit(ABSPATH . 'wp-content/uploads/dbvc-tests-' . wp_generate_password(8, false, false));
        wp_mkdir_p($this->sync_dir);
        $relative = ltrim(str_replace(wp_normalize_path(trailingslashit(ABSPATH)), '', wp_normalize_path($this->sync_dir)), '/');
        update_option('dbvc_sync_path', $relative ?: 'wp-content/uploads');
        update_option('dbvc_allow_uid_fallback_matching', '0');
        delete_transient('dbvc_entity_editor_index_v1');

        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        delete_transient('dbvc_entity_editor_index_v1');
        update_option('dbvc_sync_path', $this->sync_option_before);
        update_option('dbvc_allow_uid_fallback_matching', $this->allow_uid_fallback_before);
        $this->delete_directory($this->sync_dir);
        foreach ($this->registered_test_post_types as $post_type) {
            unregister_post_type($post_type);
        }
        $this->registered_test_post_types = [];
        parent::tear_down();
    }

    public function test_entity_editor_index_requires_manage_options(): void
    {
        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);

        $server = rest_get_server();
        $request = new WP_REST_Request('GET', '/dbvc/v1/entity-editor/index');
        $response = $server->dispatch($request);

        $this->assertSame(403, $response->get_status());
    }

    public function test_entity_editor_file_rejects_path_traversal(): void
    {
        $server = rest_get_server();
        $request = new WP_REST_Request('GET', '/dbvc/v1/entity-editor/file');
        $request->set_param('path', '../wp-config.php');
        $response = $server->dispatch($request);

        $this->assertSame(400, $response->get_status());
    }

    public function test_entity_editor_file_rejects_absolute_outside_path(): void
    {
        $server = rest_get_server();
        $request = new WP_REST_Request('GET', '/dbvc/v1/entity-editor/file');
        $request->set_param('path', '/etc/passwd.json');
        $response = $server->dispatch($request);

        $this->assertSame(404, $response->get_status());
    }

    public function test_save_json_only_does_not_mutate_post_data(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Save Only Baseline',
            'post_status' => 'publish',
            'post_name'   => 'entity-editor-save-only',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'entity-editor-save-only-uid');
        update_post_meta($post_id, 'save_only_meta', 'before');

        $path = $this->write_sync_json(
            'page/entity-editor-save-only.json',
            [
                'ID'            => $post_id,
                'post_type'     => 'page',
                'post_name'     => 'entity-editor-save-only',
                'post_title'    => 'Save Only Baseline',
                'post_status'   => 'publish',
                'vf_object_uid' => 'entity-editor-save-only-uid',
                'meta'          => [
                    'save_only_meta' => 'json-value',
                ],
                'tax_input'     => [],
            ]
        );

        $loaded = DBVC_Entity_Editor_Indexer::load_entity_file($path, get_current_user_id(), false);
        $this->assertIsArray($loaded);
        $lock_token = isset($loaded['lock']['token']) ? (string) $loaded['lock']['token'] : '';
        $this->assertNotSame('', $lock_token);

        $updated_payload = json_decode((string) $loaded['content'], true);
        $updated_payload['meta']['save_only_meta'] = 'json-only-change';
        $updated_content = wp_json_encode($updated_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($updated_content);

        $result = DBVC_Entity_Editor_Indexer::save_entity_file(
            $path,
            (string) $updated_content,
            get_current_user_id(),
            $lock_token,
            false
        );
        $this->assertIsArray($result);

        $this->assertSame('Save Only Baseline', get_post($post_id)->post_title);
        $this->assertSame('before', (string) get_post_meta($post_id, 'save_only_meta', true));
    }

    public function test_entity_editor_index_marks_latest_duplicate_row_by_local_db_id(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Duplicate Canonical Row',
            'post_status' => 'publish',
            'post_name'   => 'entity-editor-duplicate-row',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'entity-editor-duplicate-row-uid');

        $canonical_path = $this->write_sync_json(
            sprintf('page/entity-editor-duplicate-row-%d.json', $post_id),
            [
                'ID'            => $post_id,
                'post_type'     => 'page',
                'post_name'     => 'entity-editor-duplicate-row',
                'post_title'    => 'Duplicate Canonical Row',
                'post_status'   => 'publish',
                'vf_object_uid' => 'entity-editor-duplicate-row-uid',
                'meta'          => [],
                'tax_input'     => [],
            ]
        );

        $older_duplicate_path = $this->write_sync_json(
            'page/entity-editor-duplicate-row-999999.json',
            [
                'ID'            => $post_id,
                'post_type'     => 'page',
                'post_name'     => 'entity-editor-duplicate-row',
                'post_title'    => 'Duplicate Canonical Row',
                'post_status'   => 'publish',
                'vf_object_uid' => 'entity-editor-duplicate-row-uid',
                'meta'          => [],
                'tax_input'     => [],
            ]
        );

        touch($this->absolute_sync_path($canonical_path), time() - 120);
        touch($this->absolute_sync_path($older_duplicate_path), time());

        $index = DBVC_Entity_Editor_Indexer::get_index(true);
        $items = [];
        foreach ($index['items'] as $item) {
            $items[$item['relative_path']] = $item;
        }

        $this->assertSame(1, (int) ($index['stats']['duplicate_groups'] ?? 0));
        $this->assertGreaterThanOrEqual(2, (int) ($index['stats']['duplicate_files'] ?? 0));
        $this->assertArrayHasKey($canonical_path, $items);
        $this->assertArrayHasKey($older_duplicate_path, $items);

        $canonical = $items[$canonical_path];
        $stale     = $items[$older_duplicate_path];
        $group_canonical_path = $stale['duplicate_group']['canonical_relative_path'] ?? '';

        $this->assertTrue((bool) ($canonical['is_duplicate'] ?? false));
        $this->assertTrue((bool) ($stale['is_duplicate'] ?? false));
        $this->assertFalse((bool) ($stale['is_canonical_duplicate'] ?? true));
        $this->assertSame('matched_wp_id', $stale['duplicate_group']['match_basis'] ?? '');
        $this->assertNotSame($older_duplicate_path, $group_canonical_path);
        $this->assertArrayHasKey($group_canonical_path, $items);
        $this->assertTrue((bool) ($items[$group_canonical_path]['is_canonical_duplicate'] ?? false));
        $this->assertSame($post_id, (int) ($items[$group_canonical_path]['filename_entity_id'] ?? 0));
    }

    public function test_entity_editor_index_groups_source_id_and_local_id_duplicates_by_matched_entity(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Source Local Duplicate Row',
            'post_status' => 'publish',
            'post_name'   => 'entity-editor-source-local-duplicate',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'entity-editor-source-local-duplicate-uid');

        $canonical_path = $this->write_sync_json(
            sprintf('page/entity-editor-source-local-duplicate-%d.json', $post_id),
            [
                'ID'            => $post_id,
                'post_type'     => 'page',
                'post_name'     => 'entity-editor-source-local-duplicate',
                'post_title'    => 'Source Local Duplicate Row',
                'post_status'   => 'publish',
                'vf_object_uid' => 'entity-editor-source-local-duplicate-uid',
                'meta'          => [],
                'tax_input'     => [],
            ]
        );

        $source_id_path = $this->write_sync_json(
            'page/entity-editor-source-local-duplicate-987654321.json',
            [
                'ID'            => 987654321,
                'post_type'     => 'page',
                'post_name'     => 'entity-editor-source-local-duplicate',
                'post_title'    => 'Source Local Duplicate Row',
                'post_status'   => 'publish',
                'vf_object_uid' => 'entity-editor-source-local-duplicate-uid',
                'meta'          => [],
                'tax_input'     => [],
            ]
        );

        $source_slug_path = $this->write_sync_json(
            'page/entity-editor-source-local-duplicate.json',
            [
                'ID'            => 987654321,
                'post_type'     => 'page',
                'post_name'     => 'entity-editor-source-local-duplicate',
                'post_title'    => 'Source Local Duplicate Row',
                'post_status'   => 'publish',
                'vf_object_uid' => 'entity-editor-source-local-duplicate-uid',
                'meta'          => [],
                'tax_input'     => [],
            ]
        );

        touch($this->absolute_sync_path($source_id_path), time() + 60);
        touch($this->absolute_sync_path($source_slug_path), time() + 120);
        delete_transient('dbvc_entity_editor_index_v1');

        $index = DBVC_Entity_Editor_Indexer::get_index(true);
        $items = [];
        foreach ($index['items'] as $item) {
            $items[$item['relative_path']] = $item;
        }

        $this->assertArrayHasKey($canonical_path, $items);
        $this->assertArrayHasKey($source_id_path, $items);
        $this->assertArrayHasKey($source_slug_path, $items);
        $this->assertTrue((bool) ($items[$canonical_path]['is_canonical_duplicate'] ?? false));
        $this->assertSame('matched_wp_id', $items[$canonical_path]['duplicate_group']['match_basis'] ?? '');
        $this->assertSame($canonical_path, $items[$source_id_path]['duplicate_group']['canonical_relative_path'] ?? '');
        $this->assertSame($canonical_path, $items[$source_slug_path]['duplicate_group']['canonical_relative_path'] ?? '');
        $this->assertFalse((bool) ($items[$source_id_path]['is_canonical_duplicate'] ?? true));
        $this->assertFalse((bool) ($items[$source_slug_path]['is_canonical_duplicate'] ?? true));
    }

    public function test_delete_entity_editor_files_endpoint_removes_only_sync_json(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Delete Sync Row',
            'post_status' => 'publish',
            'post_name'   => 'entity-editor-delete-row',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'entity-editor-delete-row-uid');

        $path = $this->write_sync_json(
            'page/entity-editor-delete-row.json',
            [
                'ID'            => $post_id,
                'post_type'     => 'page',
                'post_name'     => 'entity-editor-delete-row',
                'post_title'    => 'Delete Sync Row',
                'post_status'   => 'publish',
                'vf_object_uid' => 'entity-editor-delete-row-uid',
                'meta'          => [],
                'tax_input'     => [],
            ]
        );

        $absolute_path = $this->absolute_sync_path($path);
        $this->assertFileExists($absolute_path);

        $server = rest_get_server();
        $request = new WP_REST_Request('POST', '/dbvc/v1/entity-editor/files/delete');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode([
            'paths' => [$path],
        ]));

        $response = $server->dispatch($request);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertSame(1, (int) ($data['deleted_count'] ?? 0));
        $this->assertSame([$path], $data['deleted_paths'] ?? []);
        $this->assertFileDoesNotExist($absolute_path);
        $this->assertNotEmpty($data['backup_paths'][$path] ?? '');
        $this->assertFileExists($this->absolute_sync_path((string) $data['backup_paths'][$path]));
        $this->assertNotNull(get_post($post_id));
        $this->assertSame([], $data['errors'] ?? []);

        $indexed_paths = array_map(
            static function ($item) {
                return isset($item['relative_path']) ? (string) $item['relative_path'] : '';
            },
            is_array($data['index']['items'] ?? null) ? $data['index']['items'] : []
        );
        $this->assertNotContains($path, $indexed_paths);
    }

    public function test_partial_import_does_not_delete_absent_meta_keys(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Partial Before',
            'post_status' => 'publish',
            'post_name'   => 'entity-editor-partial',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'entity-editor-partial-uid');
        update_post_meta($post_id, 'keep_meta', 'keep-value');
        update_post_meta($post_id, 'change_meta', 'old-value');

        $path = $this->write_sync_json(
            'page/entity-editor-partial.json',
            [
                'ID'            => $post_id,
                'post_type'     => 'page',
                'post_name'     => 'entity-editor-partial',
                'post_title'    => 'Partial After',
                'post_status'   => 'publish',
                'vf_object_uid' => 'entity-editor-partial-uid',
                'meta'          => [
                    'change_meta' => 'new-value',
                ],
                'tax_input'     => [],
            ]
        );

        $loaded = DBVC_Entity_Editor_Indexer::load_entity_file($path, get_current_user_id(), false);
        $this->assertIsArray($loaded);
        $lock_token = isset($loaded['lock']['token']) ? (string) $loaded['lock']['token'] : '';
        $this->assertNotSame('', $lock_token);

        $result = DBVC_Entity_Editor_Indexer::save_and_partial_import(
            $path,
            (string) $loaded['content'],
            get_current_user_id(),
            $lock_token,
            false
        );

        $this->assertIsArray($result);
        $this->assertSame('Partial After', get_post($post_id)->post_title);
        $this->assertSame('new-value', (string) get_post_meta($post_id, 'change_meta', true));
        $this->assertSame('keep-value', (string) get_post_meta($post_id, 'keep_meta', true));
    }

    public function test_partial_import_default_strict_matching_blocks_same_id_when_uid_is_unmatched(): void
    {
        update_option('dbvc_allow_uid_fallback_matching', '0');

        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Strict Matching Before',
            'post_status' => 'publish',
            'post_name'   => 'entity-editor-strict-same-id',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'local-strict-same-id-uid');

        $path = $this->write_sync_json(
            'page/entity-editor-strict-same-id.json',
            [
                'ID'            => $post_id,
                'post_type'     => 'page',
                'post_name'     => 'entity-editor-strict-same-id',
                'post_title'    => 'Strict Matching After',
                'post_status'   => 'publish',
                'vf_object_uid' => 'source-strict-same-id-uid',
                'meta'          => [
                    'strict_matching_meta' => 'blocked-value',
                ],
                'tax_input'     => [],
            ]
        );

        $loaded = DBVC_Entity_Editor_Indexer::load_entity_file($path, get_current_user_id(), false);
        $this->assertIsArray($loaded);
        $lock_token = isset($loaded['lock']['token']) ? (string) $loaded['lock']['token'] : '';
        $this->assertNotSame('', $lock_token);

        $result = DBVC_Entity_Editor_Indexer::save_and_partial_import(
            $path,
            (string) $loaded['content'],
            get_current_user_id(),
            $lock_token,
            false
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('dbvc_entity_editor_no_match', $result->get_error_code());
        $this->assertSame('Strict Matching Before', get_post($post_id)->post_title);
        $this->assertSame('local-strict-same-id-uid', (string) get_post_meta($post_id, 'vf_object_uid', true));
        $this->assertSame('', (string) get_post_meta($post_id, 'strict_matching_meta', true));
    }

    public function test_partial_import_allows_one_time_slug_fallback_matching_policy(): void
    {
        update_option('dbvc_allow_uid_fallback_matching', '0');

        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Slug Policy Before',
            'post_status' => 'publish',
            'post_name'   => 'entity-editor-slug-policy',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'local-slug-policy-uid');

        $path = $this->write_sync_json(
            'page/entity-editor-slug-policy.json',
            [
                'ID'            => 999999,
                'post_type'     => 'page',
                'post_name'     => 'entity-editor-slug-policy',
                'post_title'    => 'Slug Policy After',
                'post_status'   => 'publish',
                'vf_object_uid' => 'source-slug-policy-uid',
                'meta'          => [
                    'slug_policy_meta' => 'new-value',
                ],
                'tax_input'     => [],
            ]
        );

        $loaded = DBVC_Entity_Editor_Indexer::load_entity_file($path, get_current_user_id(), false);
        $this->assertIsArray($loaded);
        $lock_token = isset($loaded['lock']['token']) ? (string) $loaded['lock']['token'] : '';
        $this->assertNotSame('', $lock_token);

        $server = rest_get_server();
        $request = new WP_REST_Request('POST', '/dbvc/v1/entity-editor/file/import-partial');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode([
            'path'          => $path,
            'content'       => (string) $loaded['content'],
            'lock_token'    => $lock_token,
            'matching'      => [
                'policy' => 'allow_slug_fallback',
            ],
        ]));

        $response = $server->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('slug', (string) ($data['import_result']['matched']['match_source'] ?? ''));
        $this->assertSame('Slug Policy After', get_post($post_id)->post_title);
        $this->assertSame('source-slug-policy-uid', (string) get_post_meta($post_id, 'vf_object_uid', true));
        $this->assertSame('new-value', (string) get_post_meta($post_id, 'slug_policy_meta', true));
        $this->assertSame('0', (string) get_option('dbvc_allow_uid_fallback_matching', '0'));
    }

    public function test_partial_import_selected_entity_policy_matches_by_local_id(): void
    {
        update_option('dbvc_allow_uid_fallback_matching', '0');

        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Selected Entity Before',
            'post_status' => 'publish',
            'post_name'   => 'entity-editor-selected-entity-local',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'local-selected-entity-uid');

        $path = $this->write_sync_json(
            'page/entity-editor-selected-entity.json',
            [
                'ID'            => $post_id,
                'post_type'     => 'page',
                'post_name'     => 'entity-editor-selected-entity-source',
                'post_title'    => 'Selected Entity After',
                'post_status'   => 'publish',
                'vf_object_uid' => 'source-selected-entity-uid',
                'meta'          => [
                    'selected_entity_meta' => 'new-value',
                ],
                'tax_input'     => [],
            ]
        );

        $loaded = DBVC_Entity_Editor_Indexer::load_entity_file($path, get_current_user_id(), false);
        $this->assertIsArray($loaded);
        $lock_token = isset($loaded['lock']['token']) ? (string) $loaded['lock']['token'] : '';
        $this->assertNotSame('', $lock_token);

        $result = DBVC_Entity_Editor_Indexer::save_and_partial_import(
            $path,
            (string) $loaded['content'],
            get_current_user_id(),
            $lock_token,
            false,
            [
                'policy' => 'selected_entity',
            ]
        );

        $this->assertIsArray($result);
        $this->assertSame('selected_entity_id', (string) ($result['import_result']['matched']['match_source'] ?? ''));
        $post = get_post($post_id);
        $this->assertInstanceOf(WP_Post::class, $post);
        $this->assertSame('Selected Entity After', $post->post_title);
        $this->assertSame('entity-editor-selected-entity-source', $post->post_name);
        $this->assertSame('source-selected-entity-uid', (string) get_post_meta($post_id, 'vf_object_uid', true));
        $this->assertSame('new-value', (string) get_post_meta($post_id, 'selected_entity_meta', true));
    }

    public function test_full_replace_captures_snapshot_before_changes(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Before Replace',
            'post_status' => 'publish',
            'post_name'   => 'entity-editor-replace',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'entity-editor-uid-1');
        update_post_meta($post_id, '_edit_last', '111');
        update_post_meta($post_id, 'legacy_meta_key', 'legacy-value');

        $path = $this->write_sync_json(
            'page/entity-editor-replace.json',
            [
                'ID'           => $post_id,
                'post_type'    => 'page',
                'post_name'    => 'entity-editor-replace',
                'post_title'   => 'After Replace',
                'post_status'  => 'publish',
                'vf_object_uid'=> 'entity-editor-uid-1',
                'meta'         => [
                    'new_meta_key' => 'new-value',
                ],
                'tax_input'    => [],
            ]
        );

        $loaded = DBVC_Entity_Editor_Indexer::load_entity_file($path, get_current_user_id(), false);
        $this->assertIsArray($loaded);
        $lock_token = $loaded['lock']['token'] ?? '';
        $this->assertNotEmpty($lock_token);

        $result = DBVC_Entity_Editor_Indexer::save_and_full_replace(
            $path,
            (string) $loaded['content'],
            get_current_user_id(),
            (string) $lock_token,
            false,
            'REPLACE'
        );

        $this->assertIsArray($result);
        $this->assertSame('full_replace', $result['import_mode']);

        $import_result = $result['import_result'] ?? [];
        $snapshot_rel = isset($import_result['snapshot_path']) ? (string) $import_result['snapshot_path'] : '';
        $this->assertNotSame('', $snapshot_rel);

        $snapshot_abs = trailingslashit($this->sync_dir) . ltrim($snapshot_rel, '/');
        $this->assertFileExists($snapshot_abs);

        $snapshot = json_decode((string) file_get_contents($snapshot_abs), true);
        $this->assertIsArray($snapshot);
        $this->assertSame('Before Replace', $snapshot['post_title'] ?? '');
        $this->assertArrayHasKey('legacy_meta_key', $snapshot['meta'] ?? []);

        $this->assertSame('After Replace', get_post($post_id)->post_title);
        $this->assertSame('', (string) get_post_meta($post_id, 'legacy_meta_key', true));
        $this->assertSame('111', (string) get_post_meta($post_id, '_edit_last', true));
        $this->assertSame('new-value', (string) get_post_meta($post_id, 'new_meta_key', true));
    }

    public function test_full_replace_preserves_incoming_uid_when_slug_fallback_matches(): void
    {
        update_option('dbvc_allow_uid_fallback_matching', '1');

        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'UID Slug Fallback Before',
            'post_status' => 'publish',
            'post_name'   => 'entity-editor-uid-slug-fallback',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'local-uid-slug-fallback');

        $path = $this->write_sync_json(
            'page/entity-editor-uid-slug-fallback.json',
            [
                'ID'            => 999999,
                'post_type'     => 'page',
                'post_name'     => 'entity-editor-uid-slug-fallback',
                'post_title'    => 'UID Slug Fallback After',
                'post_status'   => 'publish',
                'vf_object_uid' => 'source-uid-slug-fallback',
                'meta'          => [
                    'slug_fallback_meta' => 'new-value',
                ],
                'tax_input'     => [],
            ]
        );

        $loaded = DBVC_Entity_Editor_Indexer::load_entity_file($path, get_current_user_id(), false);
        $this->assertIsArray($loaded);
        $lock_token = isset($loaded['lock']['token']) ? (string) $loaded['lock']['token'] : '';
        $this->assertNotSame('', $lock_token);

        $result = DBVC_Entity_Editor_Indexer::save_and_full_replace(
            $path,
            (string) $loaded['content'],
            get_current_user_id(),
            $lock_token,
            false,
            'REPLACE'
        );

        $this->assertIsArray($result);
        $this->assertSame('source-uid-slug-fallback', (string) get_post_meta($post_id, 'vf_object_uid', true));
        $this->assertSame('new-value', (string) get_post_meta($post_id, 'slug_fallback_meta', true));
    }

    public function test_full_replace_blocks_slug_fallback_when_uid_is_unmatched_and_fallback_disabled(): void
    {
        update_option('dbvc_allow_uid_fallback_matching', '0');

        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'UID Slug Fallback Block Before',
            'post_status' => 'publish',
            'post_name'   => 'entity-editor-uid-slug-fallback-block',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'local-uid-slug-fallback-block');

        $path = $this->write_sync_json(
            'page/entity-editor-uid-slug-fallback-block.json',
            [
                'ID'            => 999999,
                'post_type'     => 'page',
                'post_name'     => 'entity-editor-uid-slug-fallback-block',
                'post_title'    => 'UID Slug Fallback Block After',
                'post_status'   => 'publish',
                'vf_object_uid' => 'source-uid-slug-fallback-block',
                'meta'          => [
                    'slug_fallback_meta' => 'blocked-value',
                ],
                'tax_input'     => [],
            ]
        );

        $loaded = DBVC_Entity_Editor_Indexer::load_entity_file($path, get_current_user_id(), false);
        $this->assertIsArray($loaded);
        $lock_token = isset($loaded['lock']['token']) ? (string) $loaded['lock']['token'] : '';
        $this->assertNotSame('', $lock_token);

        $result = DBVC_Entity_Editor_Indexer::save_and_full_replace(
            $path,
            (string) $loaded['content'],
            get_current_user_id(),
            $lock_token,
            false,
            'REPLACE'
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('dbvc_entity_editor_no_match', $result->get_error_code());
        $this->assertSame('local-uid-slug-fallback-block', (string) get_post_meta($post_id, 'vf_object_uid', true));
        $this->assertSame('', (string) get_post_meta($post_id, 'slug_fallback_meta', true));
    }

    public function test_full_replace_blocks_when_confirmation_phrase_missing(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Replace Phrase',
            'post_status' => 'publish',
            'post_name'   => 'entity-editor-replace-phrase',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'entity-editor-replace-phrase-uid');

        $path = $this->write_sync_json(
            'page/entity-editor-replace-phrase.json',
            [
                'ID'            => $post_id,
                'post_type'     => 'page',
                'post_name'     => 'entity-editor-replace-phrase',
                'post_title'    => 'After Phrase',
                'post_status'   => 'publish',
                'vf_object_uid' => 'entity-editor-replace-phrase-uid',
                'meta'          => [],
                'tax_input'     => [],
            ]
        );

        $loaded = DBVC_Entity_Editor_Indexer::load_entity_file($path, get_current_user_id(), false);
        $this->assertIsArray($loaded);
        $lock_token = isset($loaded['lock']['token']) ? (string) $loaded['lock']['token'] : '';
        $this->assertNotSame('', $lock_token);

        $result = DBVC_Entity_Editor_Indexer::save_and_full_replace(
            $path,
            (string) $loaded['content'],
            get_current_user_id(),
            $lock_token,
            false,
            ''
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('dbvc_entity_editor_replace_confirmation_required', $result->get_error_code());
    }

    public function test_merge_incoming_json_preview_preserves_local_identity_and_protected_meta(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Local Merge Title',
            'post_status' => 'publish',
            'post_name'   => 'local-merge-slug',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'local-merge-uid');

        $path = $this->write_sync_json(
            'page/local-merge-slug.json',
            [
                'ID'            => $post_id,
                'post_type'     => 'page',
                'post_name'     => 'local-merge-slug',
                'post_title'    => 'Local Merge Title',
                'post_status'   => 'publish',
                'vf_object_uid' => 'local-merge-uid',
                'meta'          => [
                    '_edit_last' => 'local-editor',
                    'keep_meta'  => 'local-value',
                ],
                'tax_input'     => [],
            ]
        );

        $incoming = [
            'ID'            => 987654,
            'post_type'     => 'page',
            'post_name'     => 'incoming-merge-slug',
            'post_title'    => 'Incoming Merge Title',
            'post_status'   => 'publish',
            'vf_object_uid' => 'incoming-merge-uid',
            'meta'          => [
                '_edit_last'  => 'incoming-editor',
                'change_meta' => 'incoming-value',
            ],
            'tax_input'     => [],
        ];

        $server = rest_get_server();
        $request = new WP_REST_Request('POST', '/dbvc/v1/entity-editor/merge-json/preview');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode([
            'path'          => $path,
            'incoming_json' => wp_json_encode($incoming),
            'identity'      => [
                'uid'   => 'keep_local',
                'slug'  => 'keep_local',
                'title' => 'use_incoming',
            ],
        ]));

        $response = $server->dispatch($request);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertNotEmpty($data['preview_hash'] ?? '');
        $this->assertSame([], $data['blockers'] ?? []);

        $proposed = json_decode((string) ($data['proposed_json'] ?? ''), true);
        $this->assertIsArray($proposed);
        $this->assertSame($post_id, (int) ($proposed['ID'] ?? 0));
        $this->assertSame('local-merge-uid', (string) ($proposed['vf_object_uid'] ?? ''));
        $this->assertSame('local-merge-slug', (string) ($proposed['post_name'] ?? ''));
        $this->assertSame('Incoming Merge Title', (string) ($proposed['post_title'] ?? ''));
        $this->assertSame('local-editor', (string) ($proposed['meta']['_edit_last'] ?? ''));
        $this->assertSame('incoming-value', (string) ($proposed['meta']['change_meta'] ?? ''));

        $note_codes = array_map(
            static function ($note) {
                return isset($note['code']) ? (string) $note['code'] : '';
            },
            is_array($data['notes'] ?? null) ? $data['notes'] : []
        );

        $this->assertContains('incoming_id_ignored', $note_codes);
        $this->assertContains('incoming_uid_ignored', $note_codes);
        $this->assertContains('incoming_slug_ignored', $note_codes);
        $this->assertContains('protected_meta_preserved', $note_codes);
    }

    public function test_merge_incoming_json_save_and_partial_import_updates_matched_post(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Merge Partial Before',
            'post_status' => 'publish',
            'post_name'   => 'merge-partial-local',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'merge-partial-local-uid');
        update_post_meta($post_id, 'change_meta', 'old-value');
        update_post_meta($post_id, 'keep_meta', 'keep-value');
        update_post_meta($post_id, '_edit_last', 'local-editor');

        $path = $this->write_sync_json(
            'page/merge-partial-local.json',
            [
                'ID'            => $post_id,
                'post_type'     => 'page',
                'post_name'     => 'merge-partial-local',
                'post_title'    => 'Merge Partial Before',
                'post_status'   => 'publish',
                'vf_object_uid' => 'merge-partial-local-uid',
                'meta'          => [
                    'change_meta' => 'old-value',
                    'keep_meta'   => 'keep-value',
                    '_edit_last'  => 'local-editor',
                ],
                'tax_input'     => [],
            ]
        );

        $incoming = [
            'ID'            => 123456,
            'post_type'     => 'page',
            'post_name'     => 'merge-partial-source',
            'post_title'    => 'Merge Partial After',
            'post_status'   => 'publish',
            'vf_object_uid' => 'merge-partial-source-uid',
            'meta'          => [
                'change_meta' => 'new-value',
                '_edit_last'  => 'incoming-editor',
            ],
            'tax_input'     => [],
        ];

        $loaded = DBVC_Entity_Editor_Indexer::load_entity_file($path, get_current_user_id(), false);
        $this->assertIsArray($loaded);
        $lock_token = isset($loaded['lock']['token']) ? (string) $loaded['lock']['token'] : '';
        $this->assertNotSame('', $lock_token);

        $server = rest_get_server();
        $preview_request = new WP_REST_Request('POST', '/dbvc/v1/entity-editor/merge-json/preview');
        $preview_request->set_header('content-type', 'application/json');
        $preview_request->set_body((string) wp_json_encode([
            'path'          => $path,
            'incoming_json' => wp_json_encode($incoming),
            'identity'      => [
                'uid'   => 'keep_local',
                'slug'  => 'keep_local',
                'title' => 'use_incoming',
            ],
        ]));

        $preview_response = $server->dispatch($preview_request);
        $this->assertSame(200, $preview_response->get_status());
        $preview = $preview_response->get_data();
        $this->assertNotEmpty($preview['preview_hash'] ?? '');
        $this->assertTrue((bool) ($preview['available_actions']['save_and_partial_import'] ?? false));

        $save_request = new WP_REST_Request('POST', '/dbvc/v1/entity-editor/merge-json/save-and-partial-import');
        $save_request->set_header('content-type', 'application/json');
        $save_request->set_body((string) wp_json_encode([
            'path'          => $path,
            'incoming_json' => wp_json_encode($incoming),
            'identity'      => [
                'uid'   => 'keep_local',
                'slug'  => 'keep_local',
                'title' => 'use_incoming',
            ],
            'lock_token'   => $lock_token,
            'preview_hash' => (string) $preview['preview_hash'],
            'confirmed'    => true,
        ]));

        $save_response = $server->dispatch($save_request);
        $this->assertSame(200, $save_response->get_status());

        $post = get_post($post_id);
        $this->assertInstanceOf(WP_Post::class, $post);
        $this->assertSame('Merge Partial After', $post->post_title);
        $this->assertSame('merge-partial-local', $post->post_name);
        $this->assertSame('merge-partial-local-uid', (string) get_post_meta($post_id, 'vf_object_uid', true));
        $this->assertSame('new-value', (string) get_post_meta($post_id, 'change_meta', true));
        $this->assertSame('keep-value', (string) get_post_meta($post_id, 'keep_meta', true));
        $this->assertSame('local-editor', (string) get_post_meta($post_id, '_edit_last', true));

        $saved = json_decode((string) file_get_contents($this->absolute_sync_path($path)), true);
        $this->assertIsArray($saved);
        $this->assertSame($post_id, (int) ($saved['ID'] ?? 0));
        $this->assertSame('merge-partial-local', (string) ($saved['post_name'] ?? ''));
        $this->assertSame('merge-partial-local-uid', (string) ($saved['vf_object_uid'] ?? ''));
    }

    public function test_merge_incoming_json_save_and_partial_import_updates_bricks_template_content(): void
    {
        $this->ensure_test_post_type('bricks_template', [
            'public' => true,
        ]);

        $post_id = self::factory()->post->create([
            'post_type'   => 'bricks_template',
            'post_title'  => 'Service',
            'post_status' => 'publish',
            'post_name'   => 'service-template',
        ]);

        $local_uid = 'bricks-service-template-local-uid';
        $stale_json_uid = 'bricks-service-template-stale-json-uid';
        $local_content = [
            [
                'id'       => 'local-heading',
                'name'     => 'heading',
                'settings' => [
                    'text' => 'Local service heading',
                ],
            ],
        ];
        $incoming_content = [
            [
                'id'       => 'incoming-heading',
                'name'     => 'heading',
                'settings' => [
                    'text' => 'Incoming service heading',
                ],
            ],
            [
                'id'       => 'incoming-text',
                'name'     => 'text-basic',
                'settings' => [
                    'text' => 'Incoming service body',
                ],
            ],
        ];

        update_post_meta($post_id, 'vf_object_uid', $local_uid);
        update_post_meta($post_id, '_bricks_template_type', 'content');
        update_post_meta($post_id, '_bricks_template_settings', [
            'templateType'       => 'content',
            'templateConditions' => [
                [
                    'main'  => 'post_type',
                    'value' => 'service',
                ],
            ],
            'templatePreviewPostId' => 111,
        ]);
        update_post_meta($post_id, '_bricks_page_content_2', $local_content);

        $path = $this->write_sync_json(
            sprintf('bricks_template/bricks_template-service-template-%d.json', $post_id),
            [
                'ID'            => $post_id,
                'post_type'     => 'bricks_template',
                'post_name'     => 'service-template',
                'post_title'    => 'Service',
                'post_status'   => 'publish',
                'vf_object_uid' => $stale_json_uid,
                'meta'          => [
                    'vf_object_uid'             => [$stale_json_uid],
                    '_bricks_template_type'     => ['content'],
                    '_bricks_template_settings' => [
                        [
                            'templateType'          => 'content',
                            'templateConditions'    => [
                                [
                                    'main'  => 'post_type',
                                    'value' => 'service',
                                ],
                            ],
                            'templatePreviewPostId' => 111,
                        ],
                    ],
                    '_bricks_page_content_2'    => [$local_content],
                ],
                'tax_input'     => [],
            ]
        );

        $incoming = [
            'ID'            => $post_id,
            'post_type'     => 'bricks_template',
            'post_name'     => 'service-template',
            'post_title'    => 'Service - Incoming',
            'post_status'   => 'publish',
            'vf_object_uid' => 'bricks-service-template-source-uid',
            'meta'          => [
                'vf_object_uid'             => ['bricks-service-template-source-uid'],
                '_bricks_template_type'     => ['content'],
                '_bricks_template_settings' => [
                    [
                        'templateType'          => 'content',
                        'templateConditions'    => [
                            [
                                'main'  => 'post_type',
                                'value' => 'other-service',
                            ],
                        ],
                        'templatePreviewPostId' => 999999,
                    ],
                ],
                '_bricks_page_content_2'    => [$incoming_content],
            ],
            'tax_input'     => [],
        ];

        $loaded = DBVC_Entity_Editor_Indexer::load_entity_file($path, get_current_user_id(), false);
        $this->assertIsArray($loaded);
        $lock_token = isset($loaded['lock']['token']) ? (string) $loaded['lock']['token'] : '';
        $this->assertNotSame('', $lock_token);

        $server = rest_get_server();
        $preview_request = new WP_REST_Request('POST', '/dbvc/v1/entity-editor/merge-json/preview');
        $preview_request->set_header('content-type', 'application/json');
        $preview_request->set_body((string) wp_json_encode([
            'path'          => $path,
            'incoming_json' => wp_json_encode($incoming),
            'identity'      => [
                'uid'   => 'keep_local',
                'slug'  => 'keep_local',
                'title' => 'use_incoming',
            ],
            'mode'          => 'save_and_partial_import',
        ]));

        $preview_response = $server->dispatch($preview_request);
        $this->assertSame(200, $preview_response->get_status());
        $preview = $preview_response->get_data();
        $this->assertTrue((bool) ($preview['available_actions']['save_and_partial_import'] ?? false));

        $save_request = new WP_REST_Request('POST', '/dbvc/v1/entity-editor/merge-json/save-and-partial-import');
        $save_request->set_header('content-type', 'application/json');
        $save_request->set_body((string) wp_json_encode([
            'path'          => $path,
            'incoming_json' => wp_json_encode($incoming),
            'identity'      => [
                'uid'   => 'keep_local',
                'slug'  => 'keep_local',
                'title' => 'use_incoming',
            ],
            'lock_token'   => $lock_token,
            'preview_hash' => (string) $preview['preview_hash'],
            'confirmed'    => true,
        ]));

        $save_response = $server->dispatch($save_request);
        $this->assertSame(200, $save_response->get_status());

        $this->assertSame($incoming_content, get_post_meta($post_id, '_bricks_page_content_2', true));
        $this->assertSame($local_uid, (string) get_post_meta($post_id, 'vf_object_uid', true));
        $settings = get_post_meta($post_id, '_bricks_template_settings', true);
        $this->assertSame(111, (int) ($settings['templatePreviewPostId'] ?? 0));
        $this->assertSame('Service - Incoming', get_post($post_id)->post_title);

        $saved = json_decode((string) file_get_contents($this->absolute_sync_path($path)), true);
        $this->assertIsArray($saved);
        $this->assertSame($local_uid, (string) ($saved['vf_object_uid'] ?? ''));
        $this->assertSame([$local_uid], $saved['meta']['vf_object_uid'] ?? []);
    }

    /**
     * @param string              $relative_path
     * @param array<string,mixed> $payload
     * @return string
     */
    private function write_sync_json(string $relative_path, array $payload): string
    {
        $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
        $absolute = $this->absolute_sync_path($relative_path);
        wp_mkdir_p(dirname($absolute));
        file_put_contents($absolute, wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return $relative_path;
    }

    private function absolute_sync_path(string $relative_path): string
    {
        return trailingslashit($this->sync_dir) . ltrim(str_replace('\\', '/', $relative_path), '/');
    }

    /**
     * @param string              $post_type
     * @param array<string,mixed> $args
     * @return void
     */
    private function ensure_test_post_type(string $post_type, array $args = []): void
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
