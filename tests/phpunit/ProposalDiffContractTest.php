<?php

/**
 * Contract tests for the Core Proposal Diff resolution sprint.
 *
 * The red tests intentionally describe the required P0/P1 behavior before the
 * corresponding runtime phases are implemented.
 *
 * @group proposal-diff
 */
class ProposalDiffContractTest extends WP_UnitTestCase
{
    private $proposal_ids = [];
    private $temporary_files = [];

    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option('dbvc_proposal_decisions');
        delete_option('dbvc_resolver_decisions');
        delete_option('dbvc_masked_field_suppressions');
        delete_option('dbvc_mask_overrides');
        delete_option('dbvc_proposal_snapshot_states');
        delete_option('dbvc_proposal_new_entities');
        delete_option('dbvc_proposal_declined_new_entities');
        delete_option('dbvc_mask_meta_keys');
        delete_option('dbvc_mask_subkeys');
        delete_option('dbvc_mask_defaults_meta_keys');
        delete_option('dbvc_mask_defaults_subkeys');
        delete_option('dbvc_mask_post_fields');
        delete_option('dbvc_logging_enabled');
        delete_option('dbvc_logging_imports');
        delete_option('dbvc_logging_term_imports');
        delete_option('dbvc_logging_directory');
        delete_option('dbvc_allow_new_posts');
        delete_option('dbvc_force_reapply_new_posts');
        update_option('dbvc_auto_clear_decisions', '0');
        update_option('dbvc_auto_create_terms', '1');

        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        foreach ($this->proposal_ids as $proposal_id) {
            $this->delete_directory($this->get_proposal_dir($proposal_id));

            if (class_exists('DBVC_Snapshot_Manager')) {
                $snapshot_dir = trailingslashit(DBVC_Snapshot_Manager::get_base_path()) . sanitize_file_name($proposal_id);
                $this->delete_directory($snapshot_dir);
            }
        }

        foreach ($this->temporary_files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        delete_option('dbvc_proposal_decisions');
        delete_option('dbvc_resolver_decisions');
        delete_option('dbvc_masked_field_suppressions');
        delete_option('dbvc_mask_overrides');
        delete_option('dbvc_proposal_snapshot_states');
        delete_option('dbvc_proposal_new_entities');
        delete_option('dbvc_proposal_declined_new_entities');
        delete_option('dbvc_mask_meta_keys');
        delete_option('dbvc_mask_subkeys');
        delete_option('dbvc_mask_defaults_meta_keys');
        delete_option('dbvc_mask_defaults_subkeys');
        delete_option('dbvc_mask_post_fields');
        delete_option('dbvc_logging_enabled');
        delete_option('dbvc_logging_imports');
        delete_option('dbvc_logging_term_imports');
        delete_option('dbvc_logging_directory');
        delete_option('dbvc_allow_new_posts');
        delete_option('dbvc_force_reapply_new_posts');

        parent::tear_down();
    }

    public function test_apply_blocks_pending_new_entity_before_import_pipeline(): void
    {
        $proposal_id = 'proposal-diff-pending-new';
        $uid = 'proposal-diff-new-post';
        $manifest = $this->base_manifest($proposal_id, [
            [
                'item_type'     => 'post',
                'post_id'       => 987654,
                'post_type'     => 'page',
                'post_title'    => 'Pending new post',
                'vf_object_uid' => $uid,
                'path'          => 'pending-new.json',
            ],
        ]);

        $this->write_proposal($proposal_id, $manifest, [
            'pending-new.json' => $this->post_payload(987654, $uid, 'Pending new post'),
        ]);

        $this->assert_apply_blocked_by($proposal_id, 'new_entities');
    }

    public function test_apply_blocks_duplicate_manifest_entities_before_import_pipeline(): void
    {
        $proposal_id = 'proposal-diff-duplicates';
        $uid = 'proposal-diff-duplicate-post';
        $item = [
            'item_type'     => 'post',
            'post_id'       => 876543,
            'post_type'     => 'page',
            'post_title'    => 'Duplicate post',
            'vf_object_uid' => $uid,
        ];
        $manifest = $this->base_manifest($proposal_id, [
            array_merge($item, ['path' => 'duplicate-a.json']),
            array_merge($item, ['path' => 'duplicate-b.json']),
        ]);

        $payload = $this->post_payload(876543, $uid, 'Duplicate post');
        $this->write_proposal($proposal_id, $manifest, [
            'duplicate-a.json' => $payload,
            'duplicate-b.json' => $payload,
        ]);

        $this->assert_apply_blocked_by($proposal_id, 'duplicates');
    }

    public function test_proposal_inventory_defers_readiness_and_supports_bounded_full_refresh(): void
    {
        $proposal_id = 'proposal-diff-inventory-a';
        $neighbor_id = 'proposal-diff-inventory-b';
        $this->write_proposal($proposal_id, $this->base_manifest($proposal_id, []), []);
        $this->write_proposal($neighbor_id, $this->base_manifest($neighbor_id, []), []);

        $inventory_request = new WP_REST_Request('GET', '/dbvc/v1/proposals');
        $inventory_request->set_param('proposal_id', $proposal_id);
        $inventory = DBVC_Admin_App::get_proposals($inventory_request)->get_data();

        $this->assertCount(1, $inventory['items']);
        $this->assertSame($proposal_id, $inventory['items'][0]['id']);
        $this->assertSame('deferred', $inventory['items'][0]['readiness_state']);
        $this->assertNull($inventory['items'][0]['apply_gates']);
        $this->assertFalse($inventory['readiness']['included']);
        $this->assertSame(1, $inventory['pagination']['total_items']);

        $full_request = new WP_REST_Request('GET', '/dbvc/v1/proposals');
        $full_request->set_param('proposal_id', $proposal_id);
        $full_request->set_param('include_readiness', true);
        $full = DBVC_Admin_App::get_proposals($full_request)->get_data();

        $this->assertCount(1, $full['items']);
        $this->assertSame('complete', $full['items'][0]['readiness_state']);
        $this->assertIsArray($full['items'][0]['apply_gates']);
        $this->assertTrue($full['items'][0]['apply_gates']['ready']);
        $this->assertTrue($full['readiness']['included']);

        $paged_request = new WP_REST_Request('GET', '/dbvc/v1/proposals');
        $paged_request->set_param('per_page', 1);
        $paged = DBVC_Admin_App::get_proposals($paged_request)->get_data();

        $this->assertCount(1, $paged['items']);
        $this->assertSame(1, $paged['pagination']['per_page']);
        $this->assertGreaterThanOrEqual(2, $paged['pagination']['total_items']);
        $this->assertGreaterThanOrEqual(2, $paged['pagination']['total_pages']);
    }

    public function test_duplicate_surfaces_share_canonical_post_and_term_groups(): void
    {
        $proposal_id = 'proposal-diff-duplicate-parity';
        $shared_uid = 'proposal-diff-shared-duplicate-uid';
        $unique_uid = 'proposal-diff-unique-neighbor-uid';
        $post_payload = $this->post_payload(7101, $shared_uid, 'Duplicate parity post');
        $unique_payload = $this->post_payload(7102, $unique_uid, 'Unique parity neighbor');
        $post_hash = hash('sha256', wp_json_encode($post_payload));
        $unique_hash = hash('sha256', wp_json_encode($unique_payload));
        $term_payload = [
            'term_id'       => 7201,
            'taxonomy'      => 'category',
            'name'          => 'Duplicate parity term',
            'slug'          => 'duplicate-parity-term',
            'description'   => '',
            'parent'        => 0,
            'vf_object_uid' => $shared_uid,
            'meta'          => [],
        ];
        $manifest = $this->base_manifest($proposal_id, [
            [
                'item_type'     => 'post',
                'post_id'       => 7101,
                'post_type'     => 'page',
                'post_name'     => 'duplicate-parity-post',
                'post_title'    => 'Duplicate parity post',
                'post_status'   => 'publish',
                'vf_object_uid' => $shared_uid,
                'path'          => 'page-7101.json',
                'hash'          => $post_hash,
            ],
            [
                'item_type'     => 'post',
                'post_id'       => 7101,
                'post_type'     => 'page',
                'post_name'     => 'duplicate-parity-post',
                'post_title'    => 'Duplicate parity post',
                'post_status'   => 'publish',
                'vf_object_uid' => $shared_uid,
                'path'          => 'page-duplicate-parity-post-7101.json',
                'hash'          => $post_hash,
            ],
            [
                'item_type'     => 'term',
                'term_id'       => 7201,
                'term_taxonomy' => 'category',
                'term_slug'     => 'duplicate-parity-term',
                'term_name'     => 'Duplicate parity term',
                'vf_object_uid' => $shared_uid,
                'path'          => 'category-7201.json',
            ],
            [
                'item_type'     => 'term',
                'term_id'       => 7201,
                'term_taxonomy' => 'category',
                'term_slug'     => 'duplicate-parity-term',
                'term_name'     => 'Duplicate parity term',
                'vf_object_uid' => $shared_uid,
                'path'          => 'category-duplicate-parity-term-7201.json',
            ],
            [
                'item_type'     => 'post',
                'post_id'       => 7102,
                'post_type'     => 'page',
                'post_name'     => 'unique-parity-neighbor',
                'post_title'    => 'Unique parity neighbor',
                'post_status'   => 'publish',
                'vf_object_uid' => $unique_uid,
                'path'          => 'page-7102.json',
                'hash'          => $unique_hash,
            ],
        ]);
        $this->write_proposal($proposal_id, $manifest, [
            'page-7101.json' => $post_payload,
            'page-duplicate-parity-post-7101.json' => $post_payload,
            'category-7201.json' => $term_payload,
            'category-duplicate-parity-term-7201.json' => $term_payload,
            'page-7102.json' => $unique_payload,
        ]);

        $request = new WP_REST_Request('GET', '/dbvc/v1/proposals/' . $proposal_id . '/duplicates');
        $request->set_param('proposal_id', $proposal_id);
        $response = DBVC_Admin_App::get_proposal_duplicates($request);
        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $report = $response->get_data();

        $this->assertSame(2, $report['count']);
        $types = array_column($report['items'], 'entity_type');
        sort($types);
        $this->assertSame(['post', 'term'], $types);
        $duplicate_ids = array_column($report['items'], 'duplicate_id');
        $this->assertCount(2, array_unique(array_filter($duplicate_ids)));
        foreach ($report['items'] as $group) {
            $this->assertSame(2, count($group['entries'] ?? []));
            $this->assertNotEmpty($group['duplicate_id'] ?? '');
            $this->assertCount(2, array_unique(array_filter(array_column($group['entries'], 'entry_id'))));
        }

        $gates = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest);
        $this->assertSame(2, $gates['counts']['duplicates']['groups']);
        $this->assertContains('duplicates', $this->get_blocking_categories($gates));

        $list = DBVC_Admin_App::get_proposals(new WP_REST_Request('GET', '/dbvc/v1/proposals'));
        $this->assertInstanceOf(WP_REST_Response::class, $list);
        $listed = null;
        foreach ((array) ($list->get_data()['items'] ?? []) as $item) {
            if (($item['id'] ?? '') === $proposal_id) {
                $listed = $item;
                break;
            }
        }
        $this->assertIsArray($listed);
        $this->assertSame(2, $listed['duplicate_count']);

        $entities_request = new WP_REST_Request(
            'GET',
            '/dbvc/v1/proposals/' . $proposal_id . '/entities'
        );
        $entities_request->set_param('proposal_id', $proposal_id);
        $entities_request->set_param('status', 'duplicates');
        $entities = DBVC_Admin_App::get_proposal_entities($entities_request)->get_data();
        $this->assertSame(2, $entities['status_counts']['duplicates']);
        $this->assertCount(4, $entities['items']);
        foreach ($entities['items'] as $entity) {
            $this->assertGreaterThan(0, $entity['status_counts']['duplicates']);
            $this->assertNotSame($unique_uid, $entity['vf_object_uid']);
        }

        $cleanup = new WP_REST_Request('POST', '/dbvc/v1/proposals/' . $proposal_id . '/duplicates/cleanup');
        $cleanup->set_param('proposal_id', $proposal_id);
        $cleanup->set_header('content-type', 'application/json');
        $cleanup->set_body(wp_json_encode([
            'vf_object_uid' => $shared_uid,
            'keep_path'     => 'page-7101.json',
        ]));
        $ambiguous = DBVC_Admin_App::cleanup_proposal_duplicates($cleanup);
        $this->assertWPError($ambiguous);
        $this->assertSame('dbvc_duplicate_identity_ambiguous', $ambiguous->get_error_code());
    }

    public function test_duplicate_detector_supports_term_id_fallback_and_upload_validation(): void
    {
        $proposal_id = 'proposal-diff-term-duplicate-fallback';
        $term_payload = [
            'term_id'     => 7301,
            'taxonomy'    => 'category',
            'name'        => 'Term duplicate fallback',
            'slug'        => 'term-duplicate-fallback',
            'description' => '',
            'parent'      => 0,
            'meta'        => [],
        ];
        $manifest = $this->base_manifest($proposal_id, [
            [
                'item_type'     => 'term',
                'term_id'       => 7301,
                'term_taxonomy' => 'category',
                'term_slug'     => 'term-duplicate-fallback',
                'term_name'     => 'Term duplicate fallback',
                'path'          => 'category-7301.json',
            ],
            [
                'item_type'     => 'term',
                'term_id'       => 7301,
                'term_taxonomy' => 'category',
                'term_slug'     => 'term-duplicate-fallback',
                'term_name'     => 'Term duplicate fallback',
                'path'          => 'category-term-duplicate-fallback-7301.json',
            ],
        ]);
        $this->write_proposal($proposal_id, $manifest, [
            'category-7301.json' => $term_payload,
            'category-term-duplicate-fallback-7301.json' => $term_payload,
        ]);

        $request = new WP_REST_Request('GET', '/dbvc/v1/proposals/' . $proposal_id . '/duplicates');
        $request->set_param('proposal_id', $proposal_id);
        $report = DBVC_Admin_App::get_proposal_duplicates($request)->get_data();
        $this->assertSame(1, $report['count']);
        $this->assertSame('term', $report['items'][0]['entity_type'] ?? '');
        $this->assertSame(7301, (int) ($report['items'][0]['term_id'] ?? 0));
        $this->assertNotEmpty($report['items'][0]['duplicate_id'] ?? '');

        $zip_path = $this->write_temporary_zip([
            DBVC_Backup_Manager::MANIFEST_FILENAME => $manifest,
            'category-7301.json' => $term_payload,
            'category-term-duplicate-fallback-7301.json' => $term_payload,
        ]);
        $upload = DBVC_Admin_App::import_proposal_from_zip($zip_path, [
            'preferred_id' => $proposal_id . '-upload',
        ]);
        $this->assertWPError($upload);
        $this->assertSame('dbvc_manifest_duplicates', $upload->get_error_code());
        $error_data = $upload->get_error_data();
        $this->assertSame(1, $error_data['duplicates']['count'] ?? null);
        $this->assertSame('term', $error_data['duplicates']['items'][0]['entity_type'] ?? '');
    }

    public function test_duplicate_cleanup_uses_canonical_ids_and_accepts_cli_body_params(): void
    {
        $proposal_id = 'proposal-diff-duplicate-cleanup';
        $post_uid = 'proposal-diff-cleanup-post';
        $term_uid = 'proposal-diff-cleanup-term';
        $post_payload = $this->post_payload(7401, $post_uid, 'Duplicate cleanup post');
        $term_payload = [
            'term_id'       => 7501,
            'taxonomy'      => 'category',
            'name'          => 'Duplicate cleanup term',
            'slug'          => 'duplicate-cleanup-term',
            'description'   => '',
            'parent'        => 0,
            'vf_object_uid' => $term_uid,
            'meta'          => [],
        ];
        $manifest = $this->base_manifest($proposal_id, [
            [
                'item_type'     => 'post',
                'post_id'       => 7401,
                'post_type'     => 'page',
                'post_name'     => 'duplicate-cleanup-post',
                'post_title'    => 'Duplicate cleanup post',
                'vf_object_uid' => $post_uid,
                'path'          => 'page-7401.json',
            ],
            [
                'item_type'     => 'post',
                'post_id'       => 7401,
                'post_type'     => 'page',
                'post_name'     => 'duplicate-cleanup-post',
                'post_title'    => 'Duplicate cleanup post',
                'vf_object_uid' => $post_uid,
                'path'          => 'page-duplicate-cleanup-post-7401.json',
            ],
            [
                'item_type'     => 'term',
                'term_id'       => 7501,
                'term_taxonomy' => 'category',
                'term_slug'     => 'duplicate-cleanup-term',
                'term_name'     => 'Duplicate cleanup term',
                'vf_object_uid' => $term_uid,
                'path'          => 'category-7501.json',
            ],
            [
                'item_type'     => 'term',
                'term_id'       => 7501,
                'term_taxonomy' => 'category',
                'term_slug'     => 'duplicate-cleanup-term',
                'term_name'     => 'Duplicate cleanup term',
                'vf_object_uid' => $term_uid,
                'path'          => 'category-duplicate-cleanup-term-7501.json',
            ],
        ]);
        $this->write_proposal($proposal_id, $manifest, [
            'page-7401.json' => $post_payload,
            'page-duplicate-cleanup-post-7401.json' => $post_payload,
            'category-7501.json' => $term_payload,
            'category-duplicate-cleanup-term-7501.json' => $term_payload,
        ]);

        $report_request = new WP_REST_Request('GET', '/dbvc/v1/proposals/' . $proposal_id . '/duplicates');
        $report_request->set_param('proposal_id', $proposal_id);
        $initial_report = DBVC_Admin_App::get_proposal_duplicates($report_request)->get_data();
        $term_group = null;
        foreach ($initial_report['items'] as $group) {
            if (($group['entity_type'] ?? '') === 'term') {
                $term_group = $group;
                break;
            }
        }
        $this->assertIsArray($term_group);
        $term_keep = null;
        foreach ($term_group['entries'] as $entry) {
            if (($entry['filename_mode'] ?? '') === 'slug_id') {
                $term_keep = $entry;
                break;
            }
        }
        $this->assertIsArray($term_keep);

        $specific = new WP_REST_Request('POST', '/dbvc/v1/proposals/' . $proposal_id . '/duplicates/cleanup');
        $specific->set_param('proposal_id', $proposal_id);
        $specific->set_header('content-type', 'application/json');
        $specific->set_body(wp_json_encode([
            'duplicate_id' => $term_group['duplicate_id'],
            'keep_entry_id'=> $term_keep['entry_id'],
            'keep_path'    => $term_keep['path'],
        ]));
        $specific_response = DBVC_Admin_App::cleanup_proposal_duplicates($specific);
        $this->assertInstanceOf(WP_REST_Response::class, $specific_response);
        $this->assertSame(1, $specific_response->get_data()['count'] ?? null);
        $this->assertSame(1, $specific_response->get_data()['removed'] ?? null);

        $cli_style = new WP_REST_Request('POST', '/dbvc/v1/proposals/' . $proposal_id . '/duplicates/cleanup');
        $cli_style->set_body_params([
            'apply_all'        => true,
            'preferred_format' => 'slug_id',
            'confirm_token'    => DBVC_Admin_App::DUPLICATE_BULK_CONFIRM_PHRASE,
        ]);
        $cli_style->set_param('proposal_id', $proposal_id);
        $bulk_response = DBVC_Admin_App::cleanup_proposal_duplicates($cli_style);
        $this->assertInstanceOf(
            WP_REST_Response::class,
            $bulk_response,
            is_wp_error($bulk_response)
                ? $bulk_response->get_error_code() . ': ' . $bulk_response->get_error_message()
                : ''
        );
        $bulk_data = $bulk_response->get_data();
        $this->assertSame(0, $bulk_data['count'] ?? null);
        $this->assertSame(1, $bulk_data['removed'] ?? null);
        $this->assertSame([], $bulk_data['items'] ?? null);

        $directory = $this->get_proposal_dir($proposal_id);
        $stored_manifest = json_decode(
            (string) file_get_contents($directory . '/' . DBVC_Backup_Manager::MANIFEST_FILENAME),
            true
        );
        $this->assertCount(2, $stored_manifest['items'] ?? []);
        $this->assertSame(2, $stored_manifest['totals']['files'] ?? null);
        $remaining_paths = array_column($stored_manifest['items'], 'path');
        sort($remaining_paths);
        $this->assertSame([
            'category-duplicate-cleanup-term-7501.json',
            'page-duplicate-cleanup-post-7401.json',
        ], $remaining_paths);
        $this->assertFileExists($directory . '/category-duplicate-cleanup-term-7501.json');
        $this->assertFileExists($directory . '/page-duplicate-cleanup-post-7401.json');
        $this->assertFileDoesNotExist($directory . '/category-7501.json');
        $this->assertFileDoesNotExist($directory . '/page-7401.json');
        $this->assertSame([], glob($directory . '/.dbvc-duplicate-cleanup-*') ?: []);
        $this->assertSame([], glob($directory . '/.manifest.json.tmp-*') ?: []);

        $final_gates = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $stored_manifest);
        $this->assertSame(0, $final_gates['counts']['duplicates']['groups']);
    }

    public function test_existing_entity_without_snapshot_reports_missing_state(): void
    {
        $proposal_id = 'proposal-diff-missing-snapshot';
        $uid = 'proposal-diff-existing-post';
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Current title',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);

        $manifest = $this->base_manifest($proposal_id, [
            [
                'item_type'     => 'post',
                'post_id'       => $post_id,
                'post_type'     => 'page',
                'post_title'    => 'Proposed title',
                'vf_object_uid' => $uid,
                'path'          => 'existing.json',
            ],
        ]);
        $this->write_proposal($proposal_id, $manifest, [
            'existing.json' => $this->post_payload($post_id, $uid, 'Proposed title'),
        ]);
        update_option('dbvc_proposal_decisions', [
            $proposal_id => [
                $uid => [
                    'post_title' => 'accept',
                    'meta.legacy_review' => 'keep',
                ],
            ],
        ], false);
        $decisions_before = get_option('dbvc_proposal_decisions', []);

        $request = new WP_REST_Request(
            'GET',
            '/dbvc/v1/proposals/' . $proposal_id . '/entities/' . $uid
        );
        $request->set_param('proposal_id', $proposal_id);
        $request->set_param('vf_object_uid', $uid);
        $response = DBVC_Admin_App::get_proposal_entity($request);
        $data = $response->get_data();

        $this->assertArrayHasKey(
            'snapshot_state',
            $data,
            'Existing entities must not silently use the proposed payload as their current baseline.'
        );
        $this->assertSame('missing', $data['snapshot_state']);
        $this->assertSame($decisions_before, get_option('dbvc_proposal_decisions', []));
        $this->assertSame($decisions_before[$proposal_id][$uid], $data['decisions']);
        $this->assertFalse($data['decision_pruning']['performed']);
        $this->assertSame('missing', $data['decision_pruning']['source']);
        $this->assertSame('untrusted_snapshot', $data['decision_pruning']['reason']);
        $this->assertSame(2, $data['decision_pruning']['before_count']);
        $this->assertSame(2, $data['decision_pruning']['after_count']);
        $this->assertSame(0, $data['decision_pruning']['pruned_count']);
        $this->assertSame(
            'dbvc_decisions_preserved_untrusted_baseline',
            $data['warnings'][0]['code'] ?? null
        );

        $prune_request = new WP_REST_Request(
            'POST',
            '/dbvc/v1/proposals/' . $proposal_id . '/entities/' . $uid . '/selections/prune'
        );
        $prune_request->set_param('proposal_id', $proposal_id);
        $prune_request->set_param('vf_object_uid', $uid);
        $prune = DBVC_Admin_App::prune_entity_decisions($prune_request);

        $this->assertWPError($prune);
        $this->assertSame('dbvc_decision_pruning_unavailable', $prune->get_error_code());
        $this->assertSame(409, $prune->get_error_data()['status'] ?? null);
        $this->assertSame($decisions_before, get_option('dbvc_proposal_decisions', []));
    }

    public function test_trusted_snapshot_detail_preserves_decisions_until_explicit_pruning_writer_runs(): void
    {
        $proposal_id = 'proposal-diff-trusted-pruning';
        $uid = 'proposal-diff-trusted-pruning-post';
        $post_id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => 'Trusted current title',
            'post_excerpt' => 'Unchanged excerpt',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);
        DBVC_Snapshot_Manager::capture_post_snapshot($proposal_id, $post_id, $uid);

        $proposed = DBVC_Snapshot_Manager::read_snapshot($proposal_id, $uid);
        $this->assertIsArray($proposed);
        $proposed['post_title'] = 'Trusted proposed title';
        $manifest = $this->base_manifest($proposal_id, [[
            'item_type'     => 'post',
            'post_id'       => $post_id,
            'post_type'     => 'page',
            'post_title'    => 'Trusted proposed title',
            'vf_object_uid' => $uid,
            'path'          => 'trusted-pruning.json',
        ]]);
        $this->write_proposal($proposal_id, $manifest, [
            'trusted-pruning.json' => $proposed,
        ]);
        update_option('dbvc_proposal_decisions', [
            $proposal_id => [
                $uid => [
                    'post_title'   => 'accept',
                    'post_excerpt' => 'keep',
                ],
            ],
        ], false);

        $request = new WP_REST_Request(
            'GET',
            '/dbvc/v1/proposals/' . $proposal_id . '/entities/' . $uid
        );
        $request->set_param('proposal_id', $proposal_id);
        $request->set_param('vf_object_uid', $uid);
        $data = DBVC_Admin_App::get_proposal_entity($request)->get_data();

        $this->assertSame([
            'post_title'   => 'accept',
            'post_excerpt' => 'keep',
        ], $data['decisions']);
        $this->assertSame(
            [
                'post_title'   => 'accept',
                'post_excerpt' => 'keep',
            ],
            get_option('dbvc_proposal_decisions', [])[$proposal_id][$uid] ?? []
        );
        $this->assertFalse($data['decision_pruning']['performed']);
        $this->assertSame('snapshot', $data['decision_pruning']['source']);
        $this->assertSame('explicit_action_required', $data['decision_pruning']['reason']);
        $this->assertSame(2, $data['decision_pruning']['before_count']);
        $this->assertSame(2, $data['decision_pruning']['after_count']);
        $this->assertSame(0, $data['decision_pruning']['pruned_count']);
        $this->assertTrue($data['decision_pruning']['eligible']);
        $this->assertSame([], $data['warnings']);

        $prune_request = new WP_REST_Request(
            'POST',
            '/dbvc/v1/proposals/' . $proposal_id . '/entities/' . $uid . '/selections/prune'
        );
        $prune_request->set_param('proposal_id', $proposal_id);
        $prune_request->set_param('vf_object_uid', $uid);

        $administrator_id = get_current_user_id();
        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);
        $denied = rest_do_request($prune_request);
        $this->assertSame(403, $denied->get_status());
        $this->assertSame('rest_forbidden', $denied->get_data()['code'] ?? null);
        $this->assertSame(
            [
                'post_title'   => 'accept',
                'post_excerpt' => 'keep',
            ],
            get_option('dbvc_proposal_decisions', [])[$proposal_id][$uid] ?? []
        );

        wp_set_current_user($administrator_id);
        $approved = rest_do_request($prune_request);
        $this->assertSame(200, $approved->get_status());
        $pruned = $approved->get_data();

        $this->assertSame(['post_title' => 'accept'], $pruned['decisions']);
        $this->assertSame(
            ['post_title' => 'accept'],
            get_option('dbvc_proposal_decisions', [])[$proposal_id][$uid] ?? []
        );
        $this->assertTrue($pruned['decision_pruning']['performed']);
        $this->assertSame('trusted_snapshot', $pruned['decision_pruning']['reason']);
        $this->assertSame(2, $pruned['decision_pruning']['before_count']);
        $this->assertSame(1, $pruned['decision_pruning']['after_count']);
        $this->assertSame(1, $pruned['decision_pruning']['pruned_count']);
        $this->assertSame(1, $pruned['summary']['total']);
        $this->assertSame(1, $pruned['summary']['accepted']);
        $this->assertSame(1, $pruned['proposal_summary']['total']);
        $this->assertSame(1, $pruned['proposal_summary']['accepted']);
    }

    public function test_snapshot_readers_do_not_create_storage_and_capture_still_hardens_it(): void
    {
        $root = trailingslashit(sys_get_temp_dir()) . 'dbvc-snapshot-read-' . wp_generate_uuid4();
        $uploads = trailingslashit($root) . 'uploads';
        $filter = static function (array $paths) use ($uploads): array {
            $paths['basedir'] = $uploads;
            $paths['baseurl'] = 'https://example.test/dbvc-snapshot-read';
            $paths['path'] = $uploads;
            $paths['url'] = $paths['baseurl'];
            $paths['subdir'] = '';
            $paths['error'] = false;
            return $paths;
        };
        add_filter('upload_dir', $filter);

        try {
            $proposal_id = 'proposal-diff-read-only-snapshot-path';
            $uid = 'proposal-diff-read-only-snapshot-uid';
            $base = DBVC_Snapshot_Manager::get_base_path(false);

            $this->assertDirectoryDoesNotExist($base);
            $this->assertNull(DBVC_Snapshot_Manager::read_snapshot($proposal_id, $uid));
            $this->assertSame([
                'exists'             => false,
                'readable'           => false,
                'captured_at'        => null,
                'captured_timestamp' => null,
            ], DBVC_Snapshot_Manager::get_snapshot_metadata($proposal_id, $uid));
            $this->assertDirectoryDoesNotExist($base);

            $post_id = self::factory()->post->create([
                'post_type'   => 'page',
                'post_status' => 'publish',
                'post_title'  => 'Snapshot writer remains explicit',
            ]);
            update_post_meta($post_id, 'vf_object_uid', $uid);
            $capture = DBVC_Snapshot_Manager::capture_post_snapshot_result(
                $proposal_id,
                $post_id,
                $uid
            );

            $this->assertIsArray($capture);
            $this->assertDirectoryExists($base);
            $this->assertFileExists(trailingslashit($base) . '.htaccess');
            $this->assertFileExists(trailingslashit($base) . 'index.php');
            $this->assertIsArray(DBVC_Snapshot_Manager::read_snapshot($proposal_id, $uid));
        } finally {
            remove_filter('upload_dir', $filter);
            $this->delete_directory($root);
        }
    }

    public function test_snapshot_inspection_does_not_assign_stable_identity_and_capture_remains_explicit(): void
    {
        global $wpdb;

        $proposal_id = 'proposal-diff-read-only-stable-identity';
        $post_snapshot_uid = 'proposal-diff-read-only-post-snapshot';
        $term_snapshot_uid = 'proposal-diff-read-only-term-snapshot';
        $this->proposal_ids[] = $proposal_id;

        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Snapshot inspection identity read',
        ]);
        // Bypass the post-meta auto-export hook so the fixture begins without
        // identity metadata; the assertion below then isolates inspection.
        $wpdb->delete($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => 'vf_object_uid']);
        $wpdb->delete($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => 'dbvc_post_history']);
        wp_cache_delete($post_id, 'post_meta');

        $initial_post_capture = DBVC_Snapshot_Manager::capture_post_snapshot_result(
            $proposal_id,
            $post_id,
            $post_snapshot_uid
        );
        $this->assertIsArray($initial_post_capture);
        $wpdb->delete($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => 'vf_object_uid']);
        $wpdb->delete($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => 'dbvc_post_history']);
        wp_cache_delete($post_id, 'post_meta');

        $post_inspection = DBVC_Snapshot_Manager::inspect_post_snapshot(
            $proposal_id,
            $post_id,
            $post_snapshot_uid
        );
        $this->assertTrue($post_inspection['exists']);
        $this->assertTrue($post_inspection['valid']);
        $this->assertSame('', get_post_meta($post_id, 'vf_object_uid', true));
        $this->assertSame('', get_post_meta($post_id, 'dbvc_post_history', true));

        $post_capture = DBVC_Snapshot_Manager::capture_post_snapshot_result(
            $proposal_id,
            $post_id,
            $post_snapshot_uid
        );
        $this->assertIsArray($post_capture);
        $this->assertNotSame('', get_post_meta($post_id, 'vf_object_uid', true));

        $term = wp_insert_term('Snapshot inspection identity term', 'category');
        $this->assertNotWPError($term);
        $term_id = (int) $term['term_id'];
        $wpdb->delete($wpdb->termmeta, ['term_id' => $term_id, 'meta_key' => 'vf_object_uid']);
        wp_cache_delete($term_id, 'term_meta');

        $initial_term_capture = DBVC_Snapshot_Manager::capture_term_snapshot_result(
            $proposal_id,
            $term_id,
            'category',
            $term_snapshot_uid
        );
        $this->assertIsArray($initial_term_capture);
        $wpdb->delete($wpdb->termmeta, ['term_id' => $term_id, 'meta_key' => 'vf_object_uid']);
        wp_cache_delete($term_id, 'term_meta');

        $term_inspection = DBVC_Snapshot_Manager::inspect_term_snapshot(
            $proposal_id,
            $term_id,
            'category',
            $term_snapshot_uid
        );
        $this->assertTrue($term_inspection['exists']);
        $this->assertTrue($term_inspection['valid']);
        $this->assertSame('', get_term_meta($term_id, 'vf_object_uid', true));

        $term_capture = DBVC_Snapshot_Manager::capture_term_snapshot_result(
            $proposal_id,
            $term_id,
            'category',
            $term_snapshot_uid
        );
        $this->assertIsArray($term_capture);
        $this->assertNotSame('', get_term_meta($term_id, 'vf_object_uid', true));
    }

    public function test_backup_readers_do_not_create_storage_and_backup_base_writer_still_hardens_it(): void
    {
        $root = trailingslashit(sys_get_temp_dir()) . 'dbvc-backup-read-' . wp_generate_uuid4();
        $uploads = trailingslashit($root) . 'uploads';
        $filter = static function (array $paths) use ($uploads): array {
            $paths['basedir'] = $uploads;
            $paths['baseurl'] = 'https://example.test/dbvc-backup-read';
            $paths['path'] = $uploads;
            $paths['url'] = $paths['baseurl'];
            $paths['subdir'] = '';
            $paths['error'] = false;
            return $paths;
        };
        add_filter('upload_dir', $filter);

        try {
            $base = DBVC_Backup_Manager::get_base_path(false);
            $proposal_id = 'proposal-diff-read-only-backup-path';

            $this->assertDirectoryDoesNotExist($base);
            $this->assertSame([], DBVC_Backup_Manager::list_backups());
            $this->assertNull(DBVC_Backup_Manager::read_manifest(trailingslashit($base) . $proposal_id));
            $this->assertDirectoryDoesNotExist($base);

            $writer_base = DBVC_Backup_Manager::get_base_path();
            $this->assertSame($base, $writer_base);
            $this->assertDirectoryExists($writer_base);
            $this->assertFileExists(trailingslashit($writer_base) . '.htaccess');
            $this->assertFileExists(trailingslashit($writer_base) . 'index.php');
        } finally {
            remove_filter('upload_dir', $filter);
            $this->delete_directory($root);
        }
    }

    public function test_missing_snapshot_blocks_apply_and_is_not_bypassed_by_hash_override(): void
    {
        $proposal_id = 'proposal-diff-snapshot-gate';
        $uid = 'proposal-diff-snapshot-gate-post';
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Snapshot gate current',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);

        $manifest = $this->base_manifest($proposal_id, [[
            'item_type'     => 'post',
            'post_id'       => $post_id,
            'post_type'     => 'page',
            'post_title'    => 'Snapshot gate proposed',
            'vf_object_uid' => $uid,
            'path'          => 'snapshot-gate.json',
        ]]);
        $manifest['totals']['missing_import_hash'] = 1;
        $this->write_proposal($proposal_id, $manifest, [
            'snapshot-gate.json' => $this->post_payload($post_id, $uid, 'Snapshot gate proposed'),
        ]);

        $gates = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest, [
            'ignore_missing_hash' => true,
        ]);

        $this->assertContains('snapshots', $this->get_blocking_categories($gates));
        $this->assertNotContains('hashes', $this->get_blocking_categories($gates));
        $this->assertSame(1, $gates['counts']['snapshots']['missing']);
        $this->assertSame(1, $gates['counts']['snapshots']['untrusted']);
        $this->assertTrue($gates['counts']['snapshots']['enforced']);
    }

    public function test_active_recapture_state_is_visible_and_blocks_apply(): void
    {
        $proposal_id = 'proposal-diff-recapturing-snapshot';
        $uid = 'proposal-diff-recapturing-post';
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Recapturing current',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);
        $manifest = $this->base_manifest($proposal_id, [[
            'item_type'     => 'post',
            'post_id'       => $post_id,
            'post_type'     => 'page',
            'post_title'    => 'Recapturing proposed',
            'vf_object_uid' => $uid,
            'path'          => 'recapturing.json',
        ]]);
        $this->write_proposal($proposal_id, $manifest, [
            'recapturing.json' => $this->post_payload($post_id, $uid, 'Recapturing proposed'),
        ]);
        update_option('dbvc_proposal_snapshot_states', [
            $proposal_id => [
                $uid => [
                    'state'             => 'recapturing',
                    'message'           => 'Snapshot recapture is in progress.',
                    'updated_at'        => current_time('mysql', true),
                    'updated_timestamp' => time(),
                ],
            ],
        ], false);

        $request = new WP_REST_Request('GET', '/dbvc/v1/proposals/' . $proposal_id . '/entities/' . $uid);
        $request->set_param('proposal_id', $proposal_id);
        $request->set_param('vf_object_uid', $uid);
        $data = DBVC_Admin_App::get_proposal_entity($request)->get_data();
        $gates = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest);

        $this->assertSame('recapturing', $data['snapshot_state']);
        $this->assertFalse($data['diff']['available']);
        $this->assertSame(1, $gates['counts']['snapshots']['recapturing']);
        $this->assertContains('snapshots', $this->get_blocking_categories($gates));
    }

    public function test_new_entity_snapshot_is_not_required_and_never_uses_proposed_as_current(): void
    {
        $proposal_id = 'proposal-diff-new-snapshot-state';
        $uid = 'proposal-diff-new-snapshot-post';
        $manifest = $this->base_manifest($proposal_id, [[
            'item_type'     => 'post',
            'post_id'       => 987321,
            'post_type'     => 'page',
            'post_title'    => 'New snapshot post',
            'vf_object_uid' => $uid,
            'path'          => 'new-snapshot.json',
        ]]);
        $this->write_proposal($proposal_id, $manifest, [
            'new-snapshot.json' => $this->post_payload(987321, $uid, 'New snapshot post'),
        ]);

        $request = new WP_REST_Request('GET', '/dbvc/v1/proposals/' . $proposal_id . '/entities/' . $uid);
        $request->set_param('proposal_id', $proposal_id);
        $request->set_param('vf_object_uid', $uid);
        $data = DBVC_Admin_App::get_proposal_entity($request)->get_data();
        $gates = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest);

        $this->assertSame('not_required', $data['snapshot_state']);
        $this->assertSame('not_required', $data['current_source']);
        $this->assertSame([], $data['current']);
        $this->assertFalse($data['diff']['available']);
        $this->assertSame('new_entity', $data['diff']['reason']);
        $this->assertSame(1, $gates['counts']['snapshots']['not_required']);
        $this->assertNotContains('snapshots', $this->get_blocking_categories($gates));
    }

    public function test_post_snapshot_becomes_stale_and_recapture_restores_trust(): void
    {
        $proposal_id = 'proposal-diff-stale-snapshot';
        $uid = 'proposal-diff-stale-snapshot-post';
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Current before capture',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);
        $manifest = $this->base_manifest($proposal_id, [[
            'item_type'     => 'post',
            'post_id'       => $post_id,
            'post_type'     => 'page',
            'post_title'    => 'Proposed title',
            'vf_object_uid' => $uid,
            'path'          => 'stale-snapshot.json',
        ]]);
        $this->write_proposal($proposal_id, $manifest, [
            'stale-snapshot.json' => $this->post_payload($post_id, $uid, 'Proposed title'),
        ]);

        $capture = DBVC_Admin_App::recapture_proposal_snapshots($proposal_id, $manifest, [$uid]);
        $this->assertSame(1, $capture['captured']);
        $this->assertSame('available', $capture['results'][0]['state']);

        wp_update_post([
            'ID'         => $post_id,
            'post_title' => 'Changed after capture',
        ]);

        $request = new WP_REST_Request('GET', '/dbvc/v1/proposals/' . $proposal_id . '/entities/' . $uid);
        $request->set_param('proposal_id', $proposal_id);
        $request->set_param('vf_object_uid', $uid);
        $stale = DBVC_Admin_App::get_proposal_entity($request)->get_data();
        $stale_gates = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest);

        $this->assertSame('stale', $stale['snapshot_state']);
        $this->assertSame([], $stale['current']);
        $this->assertFalse($stale['diff']['available']);
        $this->assertSame(1, $stale_gates['counts']['snapshots']['stale']);
        $this->assertContains('snapshots', $this->get_blocking_categories($stale_gates));

        $recapture_request = new WP_REST_Request('POST', '/dbvc/v1/proposals/' . $proposal_id . '/entities/' . $uid . '/snapshot');
        $recapture_request->set_param('proposal_id', $proposal_id);
        $recapture_request->set_param('vf_object_uid', $uid);
        $recaptured = DBVC_Admin_App::capture_entity_snapshot($recapture_request);

        $this->assertInstanceOf(WP_REST_Response::class, $recaptured);
        $this->assertSame('available', $recaptured->get_data()['snapshot_state']);
        $this->assertNotContains(
            'snapshots',
            $this->get_blocking_categories(DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest))
        );
    }

    public function test_bulk_recapture_has_post_and_term_parity(): void
    {
        $proposal_id = 'proposal-diff-post-term-snapshots';
        $post_uid = 'proposal-diff-parity-post';
        $term_uid = 'proposal-diff-parity-term';
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Parity post',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $post_uid);
        $term = self::factory()->term->create_and_get([
            'taxonomy' => 'category',
            'name'     => 'Parity term',
            'slug'     => 'parity-term',
        ]);
        update_term_meta($term->term_id, 'vf_object_uid', $term_uid);

        $manifest = $this->base_manifest($proposal_id, [
            [
                'item_type'     => 'post',
                'post_id'       => $post_id,
                'post_type'     => 'page',
                'post_title'    => 'Parity post proposed',
                'vf_object_uid' => $post_uid,
                'path'          => 'parity-post.json',
            ],
            [
                'item_type'     => 'term',
                'term_id'       => $term->term_id,
                'term_taxonomy' => 'category',
                'term_name'     => 'Parity term proposed',
                'term_slug'     => 'parity-term',
                'vf_object_uid' => $term_uid,
                'path'          => 'parity-term.json',
            ],
        ]);
        $this->write_proposal($proposal_id, $manifest, [
            'parity-post.json' => $this->post_payload($post_id, $post_uid, 'Parity post proposed'),
            'parity-term.json' => [
                'term_id'       => $term->term_id,
                'taxonomy'      => 'category',
                'name'          => 'Parity term proposed',
                'slug'          => 'parity-term',
                'vf_object_uid' => $term_uid,
                'meta'          => [],
            ],
        ]);

        $capture = DBVC_Admin_App::recapture_proposal_snapshots($proposal_id, $manifest);
        $states = wp_list_pluck($capture['results'], 'state', 'entity_type');

        $this->assertSame(2, $capture['targets']);
        $this->assertSame(2, $capture['captured']);
        $this->assertSame(0, $capture['failed']);
        $this->assertSame('available', $states['post']);
        $this->assertSame('available', $states['term']);
        $this->assertSame(2, $capture['snapshot_readiness']['available']);

        wp_update_term($term->term_id, 'category', ['name' => 'Parity term changed']);
        $detail_request = new WP_REST_Request('GET', '/dbvc/v1/proposals/' . $proposal_id . '/entities/' . $term_uid);
        $detail_request->set_param('proposal_id', $proposal_id);
        $detail_request->set_param('vf_object_uid', $term_uid);
        $term_detail = DBVC_Admin_App::get_proposal_entity($detail_request)->get_data();
        $this->assertSame('stale', $term_detail['snapshot_state']);

        $term_capture_request = new WP_REST_Request('POST', '/dbvc/v1/proposals/' . $proposal_id . '/entities/' . $term_uid . '/snapshot');
        $term_capture_request->set_param('proposal_id', $proposal_id);
        $term_capture_request->set_param('vf_object_uid', $term_uid);
        $term_recaptured = DBVC_Admin_App::capture_entity_snapshot($term_capture_request);
        $this->assertInstanceOf(WP_REST_Response::class, $term_recaptured);
        $this->assertSame('available', $term_recaptured->get_data()['snapshot_state']);
    }

    public function test_disabled_capture_reports_failed_state_and_upload_summary(): void
    {
        if (! class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for proposal upload snapshot coverage.');
        }

        $proposal_id = 'proposal-diff-disabled-snapshot';
        $uid = 'proposal-diff-disabled-snapshot-post';
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Disabled capture current',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);
        $manifest = $this->base_manifest($proposal_id, [[
            'item_type'     => 'post',
            'post_id'       => $post_id,
            'post_type'     => 'page',
            'post_title'    => 'Disabled capture proposed',
            'vf_object_uid' => $uid,
            'path'          => 'disabled-snapshot.json',
        ]]);

        $source = wp_tempnam('proposal-diff-disabled-snapshot.zip');
        $zip_path = $source . '.zip';
        @rename($source, $zip_path);
        $this->temporary_files[] = $source;
        $this->temporary_files[] = $zip_path;
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('manifest.json', wp_json_encode($manifest));
        $zip->addFromString('disabled-snapshot.json', wp_json_encode($this->post_payload($post_id, $uid, 'Disabled capture proposed')));
        $zip->close();

        $disable_capture = static function () {
            return false;
        };
        add_filter('dbvc_enable_snapshot_capture', $disable_capture);
        try {
            $result = DBVC_Admin_App::import_proposal_from_zip($zip_path, [
                'preferred_id' => $proposal_id,
                'overwrite'    => true,
            ]);
        } finally {
            remove_filter('dbvc_enable_snapshot_capture', $disable_capture);
        }
        $this->proposal_ids[] = $proposal_id;

        $this->assertIsArray($result);
        $this->assertSame(1, $result['snapshot_capture']['failed']);
        $this->assertSame('failed', $result['snapshot_capture']['results'][0]['state']);
        $this->assertSame('failed', $result['manifest']['snapshot_capture']['status']);
        $this->assertSame(1, $result['manifest']['snapshot_capture']['failed']);

        $stored_manifest = DBVC_Backup_Manager::read_manifest($this->get_proposal_dir($proposal_id));
        $gates = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $stored_manifest);
        $this->assertSame(1, $gates['counts']['snapshots']['failed']);
        $this->assertContains('snapshots', $this->get_blocking_categories($gates));
    }

    public function test_proposal_zip_rejects_parent_directory_entry_before_extraction(): void
    {
        if (! class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for proposal ZIP contract coverage.');
        }

        $proposal_id = 'proposal-diff-unsafe-zip';
        $source = wp_tempnam('proposal-diff.zip');
        $zip_path = $source . '.zip';
        @rename($source, $zip_path);
        $this->temporary_files[] = $source;
        $this->temporary_files[] = $zip_path;

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('manifest.json', wp_json_encode($this->base_manifest($proposal_id, [])));
        $zip->addFromString('../proposal-diff-escaped.txt', 'unsafe');
        $zip->close();

        $result = DBVC_Admin_App::import_proposal_from_zip($zip_path, [
            'preferred_id' => $proposal_id,
        ]);

        $this->proposal_ids[] = $proposal_id;
        $this->assertWPError($result);
        $this->assertSame('dbvc_zip_unsafe_entry', $result->get_error_code());
        $this->assertDirectoryDoesNotExist($this->get_proposal_dir($proposal_id));
    }

    public function test_proposal_resolver_decision_precedes_global_rule_and_reaches_media_sync(): void
    {
        update_option('dbvc_resolver_decisions', [
            'proposal-diff-resolver' => [
                '44' => [
                    'action' => 'skip',
                    'scope'  => 'proposal',
                ],
            ],
            '__global' => [
                '44' => [
                    'action'    => 'map',
                    'target_id' => 99,
                    'scope'     => 'global',
                ],
            ],
        ], false);

        $reflection = new ReflectionClass('DBVC_Media_Sync');
        $load = $reflection->getMethod('load_resolver_decisions');
        $get = $reflection->getMethod('get_resolver_decision');
        $apply = $reflection->getMethod('apply_resolver_result');
        $load->setAccessible(true);
        $get->setAccessible(true);
        $apply->setAccessible(true);

        $load->invoke(null, 'proposal-diff-resolver');
        $decision = $get->invoke(null, 44);

        $this->assertSame('skip', $decision['action']);
        $this->assertSame('proposal', $decision['scope']);

        $queue = [
            [
                'original_id' => 44,
                'handled'     => false,
            ],
        ];
        $resolver_result = [
            'attachments' => [
                'asset-44' => [
                    'descriptor' => ['original_id' => 44],
                    'status'     => 'conflict',
                    'target_id'  => null,
                ],
            ],
        ];
        $arguments = [$resolver_result, &$queue];
        $apply->invokeArgs(null, $arguments);

        $this->assertTrue($queue[0]['handled']);
        $this->assertArrayNotHasKey('local_id', $queue[0]);
    }

    public function test_diff_rows_expose_canonical_apply_units(): void
    {
        $current = [
            'ID'           => 10,
            'post_type'    => 'page',
            'post_title'   => 'Current title',
            'post_name'    => 'current-template',
            'slug'         => 'current-template',
            'meta'         => [
                '_layout' => [[
                    'color' => 'red',
                ]],
                '_obsolete' => ['remove-me'],
            ],
            'tax_input'    => [
                'category' => [[
                    'name' => 'Current category',
                    'slug' => 'current-category',
                ]],
            ],
        ];
        $proposed = [
            'ID'           => 99,
            'post_type'    => 'post',
            'post_title'   => 'Proposed title',
            'post_excerpt' => 'Added excerpt',
            'post_name'    => 'proposed-template',
            'slug'         => 'proposed-template',
            'meta'         => [
                '_layout' => [[
                    'color' => 'blue',
                ]],
            ],
            'tax_input'    => [
                'category' => [[
                    'name' => 'Proposed category',
                    'slug' => 'proposed-category',
                ]],
            ],
        ];

        $method = new ReflectionMethod('DBVC_Admin_App', 'compare_snapshots');
        $method->setAccessible(true);
        $diff = $method->invoke(null, $current, $proposed);
        $rows = [];
        foreach ($diff['changes'] as $row) {
            $rows[$row['path']] = $row;
            $this->assertMatchesRegularExpression('/^field-[a-f0-9]{20}$/', $row['id']);
            $this->assertArrayHasKey('changeType', $row);
            $this->assertArrayHasKey('source', $row);
            $this->assertArrayHasKey('destination', $row);
            $this->assertArrayHasKey('decision', $row);
            $this->assertArrayHasKey('render_hint', $row);
            $this->assertSame($row['source'], $row['from']);
            $this->assertSame($row['destination'], $row['to']);
        }

        $this->assertSame('modified', $rows['post_title']['changeType']);
        $this->assertSame('added', $rows['post_excerpt']['changeType']);
        $this->assertFalse($rows['post_excerpt']['source_exists']);
        $this->assertTrue($rows['post_excerpt']['destination_exists']);
        $this->assertSame('deleted', $rows['meta._obsolete.0']['changeType']);
        $this->assertTrue($rows['meta._obsolete.0']['source_exists']);
        $this->assertFalse($rows['meta._obsolete.0']['destination_exists']);
        $this->assertSame('post_field', $rows['post_title']['apply_scope']);
        $this->assertSame('post_title', $rows['post_title']['apply_path']);
        $this->assertTrue($rows['post_title']['can_apply']);
        $this->assertSame('post_name', $rows['post_name']['apply_path']);
        $this->assertSame('post_name', $rows['slug']['apply_path']);

        $this->assertSame('meta_leaf', $rows['meta._layout.0.color']['apply_scope']);
        $this->assertSame('meta._layout.0.color', $rows['meta._layout.0.color']['apply_path']);
        $this->assertTrue($rows['meta._layout.0.color']['can_apply']);

        $this->assertSame('meta_key', $rows['meta._obsolete.0']['apply_scope']);
        $this->assertSame('meta._obsolete', $rows['meta._obsolete.0']['apply_path']);
        $this->assertTrue($rows['meta._obsolete.0']['can_apply']);

        $this->assertSame('taxonomy', $rows['tax_input.category.0.slug']['apply_scope']);
        $this->assertSame('tax_input.category', $rows['tax_input.category.0.slug']['apply_path']);
        $this->assertTrue($rows['tax_input.category.0.slug']['can_apply']);
        $this->assertSame('tax_input.category', $rows['tax_input.category.0.name']['apply_path']);

        $this->assertSame('identity', $rows['ID']['apply_scope']);
        $this->assertNull($rows['ID']['apply_path']);
        $this->assertFalse($rows['ID']['can_apply']);
        $this->assertSame('identity', $rows['post_type']['apply_scope']);
        $this->assertSame(6, $diff['actionable_total']);
        $this->assertSame(count($diff['changes']), $diff['displayed_total']);
        $this->assertFalse($diff['truncated']);
        $this->assertSame(1, $diff['change_counts']['added']);
        $this->assertSame(1, $diff['change_counts']['deleted']);
        $this->assertGreaterThan(0, $diff['change_counts']['modified']);

        $updated_current = $current;
        $updated_proposed = $proposed;
        $updated_current['post_title'] = 'Another current title';
        $updated_proposed['post_title'] = 'Another proposed title';
        $updated_diff = $method->invoke(null, $updated_current, $updated_proposed);
        $updated_rows = array_column($updated_diff['changes'], null, 'path');
        $this->assertSame($rows['post_title']['id'], $updated_rows['post_title']['id']);
    }

    public function test_classified_diff_preserves_null_presence_and_bounds_rendered_payloads(): void
    {
        $method = new ReflectionMethod('DBVC_Admin_App', 'compare_snapshots');
        $method->setAccessible(true);

        $classified = $method->invoke(
            null,
            [
                'post_title' => 'Same title',
                'meta'       => [
                    'deleted_null' => null,
                ],
                'media_refs' => [
                    'content' => [['original_id' => 42]],
                ],
            ],
            [
                'post_title' => 'Same title',
                'meta'       => [
                    'added_null' => null,
                ],
                'media_refs' => [
                    'content' => [['original_id' => 42]],
                ],
            ],
            [
                'include_unchanged' => true,
            ]
        );
        $classified_rows = array_column($classified['changes'], null, 'path');

        $this->assertSame('unchanged', $classified_rows['post_title']['changeType']);
        $this->assertSame('deleted', $classified_rows['meta.deleted_null']['changeType']);
        $this->assertTrue($classified_rows['meta.deleted_null']['source_exists']);
        $this->assertFalse($classified_rows['meta.deleted_null']['destination_exists']);
        $this->assertSame('added', $classified_rows['meta.added_null']['changeType']);
        $this->assertFalse($classified_rows['meta.added_null']['source_exists']);
        $this->assertTrue($classified_rows['meta.added_null']['destination_exists']);
        $this->assertSame('unchanged', $classified_rows['media_refs.content.0.original_id']['changeType']);
        $this->assertSame('media', $classified_rows['media_refs.content.0.original_id']['section']);
        $this->assertSame(2, $classified['change_counts']['unchanged']);

        $bounded = $method->invoke(
            null,
            [
                'post_title' => 'Current',
                'meta'       => [
                    'big' => str_repeat('a', 6000),
                ],
            ],
            [
                'post_title' => 'Proposed',
                'meta'       => [
                    'big' => str_repeat('b', 6000),
                ],
            ],
            [
                'max_rows'          => 1,
                'inline_value_bytes'=> 256,
            ]
        );

        $this->assertSame(2, $bounded['total']);
        $this->assertSame(1, $bounded['displayed_total']);
        $this->assertSame(1, $bounded['omitted_total']);
        $this->assertTrue($bounded['truncated']);
        $this->assertSame(2, $bounded['actionable_total']);
        $this->assertCount(2, $bounded['apply_paths']);
        $this->assertTrue($bounded['changes'][0]['render_hint']['truncated']);
        $this->assertSame(6000, $bounded['changes'][0]['render_hint']['source_bytes']);
        $this->assertLessThan(300, strlen($bounded['changes'][0]['source']));
    }

    public function test_entity_detail_views_are_complete_bounded_and_keep_changed_apply_units_canonical(): void
    {
        $proposal_id = 'proposal-diff-view-modes';
        $uid = 'proposal-diff-view-modes-uid';
        $large_current = str_repeat('current-', 4000);
        $large_proposed = str_repeat('proposed-', 4000);
        $category = self::factory()->term->create_and_get([
            'taxonomy' => 'category',
            'name'     => 'Proposal Diff View Category',
            'slug'     => 'proposal-diff-view-category',
        ]);
        $post_id = self::factory()->post->create([
            'post_type'    => 'post',
            'post_status'  => 'publish',
            'post_title'   => 'Current view-mode title',
            'post_content' => 'Shared body content',
            'post_excerpt' => 'Shared excerpt',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);
        update_post_meta($post_id, 'view_mode_large', $large_current);
        wp_set_post_terms($post_id, [$category->term_id], 'category');
        DBVC_Snapshot_Manager::capture_post_snapshot($proposal_id, $post_id, $uid);

        $current = DBVC_Snapshot_Manager::read_snapshot($proposal_id, $uid);
        $this->assertIsArray($current);
        $proposed = $current;
        $proposed['post_title'] = 'Proposed view-mode title';
        $proposed['meta']['view_mode_large'] = [$large_proposed];
        $proposed['meta']['view_mode_added'] = ['added'];

        $manifest = $this->base_manifest($proposal_id, [[
            'item_type'     => 'post',
            'post_id'       => $post_id,
            'post_type'     => 'post',
            'post_title'    => $proposed['post_title'],
            'vf_object_uid' => $uid,
            'path'          => 'view-modes.json',
        ]]);
        $this->write_proposal($proposal_id, $manifest, [
            'view-modes.json' => $proposed,
        ]);
        update_option('dbvc_proposal_decisions', [
            $proposal_id => [
                $uid => [
                    'post_title' => 'accept',
                ],
            ],
        ], false);

        $get_detail = function (?string $view = null) use ($proposal_id, $uid): array {
            $request = new WP_REST_Request(
                'GET',
                '/dbvc/v1/proposals/' . $proposal_id . '/entities/' . $uid
            );
            $request->set_param('proposal_id', $proposal_id);
            $request->set_param('vf_object_uid', $uid);
            if ($view !== null) {
                $request->set_param('view', $view);
            }
            $response = DBVC_Admin_App::get_proposal_entity($request);
            $this->assertInstanceOf(WP_REST_Response::class, $response);
            return $response->get_data();
        };

        $legacy = $get_detail();
        $this->assertSame('changed', $legacy['view']['mode']);
        $this->assertTrue($legacy['view']['legacy_payloads_included']);
        $this->assertSame($large_current, $legacy['current']['meta']['view_mode_large'][0]);
        $this->assertSame($large_proposed, $legacy['proposed']['meta']['view_mode_large'][0]);

        $changed = $get_detail('changed');
        $changed_rows = array_column($changed['diff']['changes'], null, 'path');
        $this->assertFalse($changed['view']['legacy_payloads_included']);
        $this->assertArrayNotHasKey('meta', $changed['current']);
        $this->assertArrayNotHasKey('tax_input', $changed['proposed']);
        $this->assertNotEmpty($changed_rows);
        foreach ($changed_rows as $row) {
            $this->assertNotSame('unchanged', $row['changeType']);
        }
        $this->assertSame('accept', $changed_rows['post_title']['decision']);
        $this->assertSame('accept', $changed['decisions']['post_title']);

        $all = $get_detail('all');
        $all_rows = array_column($all['diff']['changes'], null, 'path');
        $this->assertSame('all', $all['view']['mode']);
        $this->assertGreaterThan(0, $all['diff']['change_counts']['unchanged']);
        $this->assertSame('unchanged', $all_rows['post_content']['changeType']);
        $this->assertSame('unchanged', $all_rows['post_excerpt']['changeType']);
        $this->assertArrayHasKey('tax_input.category.0.slug', $all_rows);
        $this->assertSame('unchanged', $all_rows['tax_input.category.0.slug']['changeType']);
        $this->assertSame(
            $changed['diff']['actionable_total'],
            $all['diff']['actionable_total']
        );
        $this->assertSame(
            $changed['diff']['apply_paths'],
            $all['diff']['apply_paths']
        );
        $this->assertSame(
            $changed_rows['post_title']['id'],
            $all_rows['post_title']['id']
        );
        $this->assertSame('accept', $all['decisions']['post_title']);

        $raw = $get_detail('raw');
        $raw_index = $raw['raw_view']['change_index'];
        $raw_rows = array_column($raw_index['rows'], null, 'path');
        $this->assertSame('raw', $raw['view']['mode']);
        $this->assertSame([], $raw['diff']['changes']);
        $this->assertArrayNotHasKey('meta', $raw['current']);
        $this->assertTrue($raw['raw_view']['current']['truncated']);
        $this->assertTrue($raw['raw_view']['proposed']['truncated']);
        $this->assertLessThanOrEqual(
            $raw['raw_view']['limits']['preview_bytes'],
            $raw['raw_view']['current']['preview_bytes']
        );
        $this->assertLessThanOrEqual(
            $raw['raw_view']['limits']['preview_bytes'],
            $raw['raw_view']['proposed']['preview_bytes']
        );
        $this->assertNotEmpty($raw['raw_view']['current']['download']);
        $this->assertNotEmpty($raw['raw_view']['proposed']['download']);
        $this->assertSame(
            $changed_rows['post_title']['id'],
            $raw_rows['post_title']['id']
        );
        $this->assertSame('accept', $raw_rows['post_title']['decision']);
        foreach ($raw_index['rows'] as $row) {
            $this->assertArrayNotHasKey('source', $row);
            $this->assertArrayNotHasKey('destination', $row);
            $this->assertArrayNotHasKey('from', $row);
            $this->assertArrayNotHasKey('to', $row);
        }
        $this->assertStringNotContainsString($large_current, wp_json_encode($raw));
        $this->assertStringNotContainsString($large_proposed, wp_json_encode($raw));
        $this->assertSame(
            $changed['diff']['actionable_total'],
            $raw['diff']['actionable_total']
        );
        $this->assertSame('accept', $raw['decisions']['post_title']);

        $invalid_request = new WP_REST_Request(
            'GET',
            '/dbvc/v1/proposals/' . $proposal_id . '/entities/' . $uid
        );
        $invalid_request->set_query_params(['view' => 'full']);
        $invalid_response = rest_do_request($invalid_request);
        $this->assertSame(400, $invalid_response->get_status());
        $this->assertSame('rest_invalid_param', $invalid_response->get_data()['code']);
    }

    public function test_entity_drawer_exposes_stable_mode_and_field_selectors(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/admin-app/index.js'
        );

        $this->assertStringContainsString('"data-component": "entity-drawer"', $source);
        $this->assertStringContainsString('"data-component": "raw-diff-view"', $source);
        $this->assertStringContainsString('"data-slot": "view-mode-controls"', $source);
        $this->assertStringContainsString('"data-slot": "raw-change-index"', $source);
        $this->assertStringContainsString('"data-slot": "field-row"', $source);
        $this->assertStringContainsString('"data-change-type": dataChangeType', $source);
        $this->assertStringContainsString('"data-decision-state": decisionState', $source);
        $this->assertStringContainsString('?view=${encodeURIComponent(tt)}', $source);
        $this->assertStringContainsString('e.closest(\'[data-component="entity-drawer"]\')', $source);
        $this->assertStringContainsString('!t.contains(s)) && e.focus()', $source);
        $this->assertStringContainsString('onPruneDecisions: pruneEntityDecisions', $source);
        $this->assertStringContainsString('cache: "no-store"', $source);
        $this->assertStringContainsString('selections/prune', $source);
        $this->assertStringContainsString('Prune stale decisions', $source);
        $this->assertStringContainsString('Prune only stale Accept/Keep decisions for this entity?', $source);
        $this->assertStringContainsString('Confirm stale-decision pruning', $source);
        $this->assertStringContainsString('Confirm prune', $source);
        $this->assertStringContainsString('pruneTriggerRef', $source);
        $this->assertStringContainsString('pruneCancelRef', $source);
        $this->assertStringContainsString('restorePruneTriggerFocus', $source);
        $this->assertStringContainsString('closePruneConfirmation', $source);
        $this->assertStringContainsString('ref: pruneCancelRef', $source);
        $this->assertStringContainsString('ref: pruneTriggerRef', $source);
        $this->assertStringContainsString('decisionPruningResult', $source);
        $this->assertStringContainsString('setDecisionPruningNotice', $source);
        $this->assertStringContainsString('Stale decisions pruned', $source);
        $this->assertStringNotContainsString('!eligible || !window.confirm("Prune only stale Accept/Keep decisions', $source);
        $this->assertStringContainsString('decisionPruningEligible', $source);
        $this->assertStringContainsString('Number(decisionPruning.before_count || 0) > 0', $source);
        $this->assertStringContainsString('summary = null !== (t = response.summary)', $source);
        $this->assertStringContainsString('response.proposal_summary', $source);
        $this->assertStringContainsString('Could not prune stale decisions.', $source);
        foreach ([
            'Fields need review',
            'Meta needs review',
            'Media needs review',
            'Resolver conflicts',
            'Masking candidates',
            'Duplicate groups',
            'New entities pending',
        ] as $label) {
            $this->assertStringContainsString($label, $source);
        }
        foreach ([
            'field_needs_review',
            'meta_needs_review',
            'media_needs_review',
            'resolver_conflicts',
            'masking_candidates',
            'duplicates',
            'new_entities_pending',
        ] as $status_count) {
            $this->assertStringContainsString($status_count, $source);
        }
        $this->assertStringContainsString('"data-status-count": t', $source);
        $this->assertStringContainsString('value: "duplicates"', $source);
        $this->assertStringContainsString('children: m.duplicates', $source);
        $this->assertStringNotContainsString('allMetaItems', $source);
        $this->assertStringNotContainsString('JSON.stringify(ae, null, 2)', $source);
        $this->assertStringNotContainsString('Conflicts & Resolver', $source);
        $this->assertStringNotContainsString('Full Overview', $source);
        $this->assertStringNotContainsString('Unresolved meta', $source);
        $this->assertStringNotContainsString('Needs Review (Media)', $source);
        $this->assertStringNotContainsString('Resolver unresolved', $source);
    }

    public function test_raw_diff_download_returns_proposed_payload_and_rejects_untrusted_current_side(): void
    {
        $proposal_id = 'proposal-diff-raw-download';
        $uid = 'proposal-diff-raw-download-uid';
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Current raw download post',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);
        $payload = $this->post_payload($post_id, $uid, 'Raw download proposal');
        $manifest = $this->base_manifest($proposal_id, [[
            'item_type'     => 'post',
            'post_id'       => $post_id,
            'post_type'     => 'page',
            'post_title'    => 'Raw download proposal',
            'vf_object_uid' => $uid,
            'path'          => 'raw-download.json',
        ]]);
        $this->write_proposal($proposal_id, $manifest, [
            'raw-download.json' => $payload,
        ]);

        $proposed_request = new WP_REST_Request(
            'GET',
            '/dbvc/v1/proposals/' . $proposal_id . '/entities/' . $uid . '/raw/proposed'
        );
        $proposed_request->set_param('proposal_id', $proposal_id);
        $proposed_request->set_param('vf_object_uid', $uid);
        $proposed_request->set_param('side', 'proposed');
        $proposed = DBVC_Admin_App::download_proposal_entity_raw($proposed_request);

        $this->assertInstanceOf(WP_REST_Response::class, $proposed);
        $this->assertSame($payload, $proposed->get_data());
        $this->assertStringContainsString(
            'attachment; filename=',
            $proposed->get_headers()['Content-Disposition']
        );

        $current_request = clone $proposed_request;
        $current_request->set_param('side', 'current');
        $current = DBVC_Admin_App::download_proposal_entity_raw($current_request);
        $this->assertWPError($current);
        $this->assertSame('dbvc_snapshot_untrusted', $current->get_error_code());
    }

    public function test_saving_a_canonical_apply_unit_removes_stale_overlapping_choices(): void
    {
        $proposal_id = 'proposal-diff-canonical-decision-store';
        $uid = 'proposal-diff-canonical-decision-uid';
        update_option('dbvc_proposal_decisions', [
            $proposal_id => [
                $uid => [
                    'tax_input.category.0.name' => 'keep',
                    'tax_input.category.0.slug' => 'keep',
                ],
            ],
        ], false);

        $request = new WP_REST_Request(
            'POST',
            '/dbvc/v1/proposals/' . $proposal_id . '/entities/' . $uid . '/selections'
        );
        $request->set_param('proposal_id', $proposal_id);
        $request->set_param('vf_object_uid', $uid);
        $request->set_body_params([
            'path'   => 'tax_input.category',
            'action' => 'accept',
        ]);
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode([
            'path'   => 'tax_input.category',
            'action' => 'accept',
        ]));

        $response = DBVC_Admin_App::update_entity_decision($request);
        $data = $response->get_data();

        $this->assertSame([
            'tax_input.category' => 'accept',
        ], $data['decisions']);
        $this->assertSame(1, $data['summary']['total']);
    }

    public function test_partial_post_field_accept_preserves_every_kept_field(): void
    {
        global $wpdb;

        $uid = 'proposal-diff-partial-post-fields';
        $post_id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => 'Current title',
            'post_content' => 'Current content',
            'post_excerpt' => 'Current excerpt',
            'post_date'    => '2020-01-01 10:00:00',
            'post_date_gmt'=> '2020-01-01 10:00:00',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);
        $wpdb->update(
            $wpdb->posts,
            [
                'post_modified'     => '2020-01-02 11:00:00',
                'post_modified_gmt' => '2020-01-02 11:00:00',
            ],
            ['ID' => $post_id],
            ['%s', '%s'],
            ['%d']
        );
        clean_post_cache($post_id);

        $payload = $this->post_payload($post_id, $uid, 'Proposed title');
        $payload['post_content'] = 'Incoming content';
        $payload['post_excerpt'] = 'Incoming excerpt';
        $payload['post_status'] = 'draft';
        $payload['post_date'] = '2030-01-01 10:00:00';
        $payload['post_modified'] = '2030-01-02 11:00:00';

        $file = $this->write_temporary_json($payload);
        $result = DBVC_Sync_Posts::import_post_from_json(
            $file,
            false,
            null,
            $payload,
            [
                'post_title'    => 'accept',
                'post_content'  => 'keep',
                'post_excerpt'  => 'keep',
                'post_status'   => 'keep',
                'post_date'     => 'keep',
                'post_modified' => 'keep',
            ],
            $uid
        );

        $post = get_post($post_id);
        $this->assertSame('applied', $result);
        $this->assertSame('Proposed title', $post->post_title);
        $this->assertSame('Current content', $post->post_content);
        $this->assertSame('Current excerpt', $post->post_excerpt);
        $this->assertSame('publish', $post->post_status);
        $this->assertSame('2020-01-01 10:00:00', $post->post_date);
        $this->assertSame('2020-01-02 11:00:00', $post->post_modified);
    }

    public function test_identity_only_accept_is_not_treated_as_an_apply_decision(): void
    {
        $uid = 'proposal-diff-identity-only';
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Current identity title',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);

        $payload = $this->post_payload($post_id, $uid, 'Proposed identity title');
        $file = $this->write_temporary_json($payload);
        $result = DBVC_Sync_Posts::import_post_from_json(
            $file,
            false,
            null,
            $payload,
            [
                'ID' => 'accept',
            ],
            $uid
        );

        $this->assertSame('skipped', $result);
        $this->assertSame('Current identity title', get_post($post_id)->post_title);
        $this->assertSame('', get_post_meta($post_id, 'dbvc_post_history', true));
    }

    /**
     * Phase 5.1 contract for nested meta apply units.
     */
    public function test_nested_meta_accept_preserves_kept_sibling_value(): void
    {
        $uid = 'proposal-diff-nested-meta';
        $post_id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => 'Nested meta target',
            'post_content' => '',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);
        update_post_meta($post_id, '_layout', [
            'color'   => 'red',
            'spacing' => 'compact',
        ]);

        $payload = $this->post_payload($post_id, $uid, 'Nested meta target');
        $payload['meta']['_layout'] = [
            [
                'color'   => 'blue',
                'spacing' => 'wide',
            ],
        ];

        $file = $this->write_temporary_json($payload);
        $result = DBVC_Sync_Posts::import_post_from_json(
            $file,
            false,
            null,
            $payload,
            [
                'meta._layout.0.color'   => 'accept',
                'meta._layout.0.spacing' => 'keep',
            ],
            $uid
        );

        $this->assertSame('applied', $result);
        $this->assertSame(
            [
                'color'   => 'blue',
                'spacing' => 'compact',
            ],
            get_post_meta($post_id, '_layout', true),
            'Accepting one nested leaf must not replace a sibling marked Keep.'
        );
    }

    public function test_nested_meta_accept_removes_only_the_selected_leaf(): void
    {
        $uid = 'proposal-diff-nested-meta-delete';
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Nested meta delete target',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);
        update_post_meta($post_id, '_layout', [
            'color'   => 'red',
            'spacing' => 'compact',
        ]);

        $payload = $this->post_payload($post_id, $uid, 'Nested meta delete target');
        $payload['meta']['_layout'] = [[
            'spacing' => 'wide',
        ]];

        $file = $this->write_temporary_json($payload);
        $result = DBVC_Sync_Posts::import_post_from_json(
            $file,
            false,
            null,
            $payload,
            [
                'meta._layout.0.color'   => 'accept',
                'meta._layout.0.spacing' => 'keep',
            ],
            $uid
        );

        $this->assertSame('applied', $result);
        $this->assertSame(
            ['spacing' => 'compact'],
            get_post_meta($post_id, '_layout', true)
        );
    }

    public function test_nested_meta_masking_stays_within_the_reviewed_leaf(): void
    {
        $uid = 'proposal-diff-nested-meta-mask';
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Nested meta mask target',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);
        update_post_meta($post_id, '_layout', [
            'color'   => 'red',
            'spacing' => 'compact',
        ]);

        $payload = $this->post_payload($post_id, $uid, 'Nested meta mask target');
        $payload['meta']['_layout'] = [[
            'color'   => 'blue',
            'spacing' => 'wide',
        ]];
        $mask_directives = [
            'overrides' => [
                'meta' => [
                    '_layout' => [
                        'meta._layout.0.color' => [
                            'path'     => 'meta._layout.0.color',
                            'meta_key' => '_layout',
                            'scope'    => 'meta',
                            'value'    => 'green',
                        ],
                    ],
                ],
            ],
            'suppressions' => [
                'meta' => [
                    '_layout' => [
                        'meta._layout.0.spacing' => [
                            'path'     => 'meta._layout.0.spacing',
                            'meta_key' => '_layout',
                            'scope'    => 'meta',
                        ],
                    ],
                ],
            ],
        ];

        $file = $this->write_temporary_json($payload);
        $result = DBVC_Sync_Posts::import_post_from_json(
            $file,
            false,
            null,
            $payload,
            [
                'meta._layout.0.color'   => 'accept',
                'meta._layout.0.spacing' => 'accept',
            ],
            $uid,
            false,
            $mask_directives
        );

        $this->assertSame('applied', $result);
        $this->assertSame(
            [
                'color'   => 'green',
                'spacing' => 'compact',
            ],
            get_post_meta($post_id, '_layout', true)
        );
    }

    public function test_complete_meta_key_accept_removes_a_missing_proposed_key(): void
    {
        $uid = 'proposal-diff-meta-key-delete';
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Meta key delete target',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);
        update_post_meta($post_id, '_obsolete', [
            'keep' => false,
        ]);

        $payload = $this->post_payload($post_id, $uid, 'Meta key delete target');
        $file = $this->write_temporary_json($payload);
        $result = DBVC_Sync_Posts::import_post_from_json(
            $file,
            false,
            null,
            $payload,
            [
                'meta._obsolete' => 'accept',
            ],
            $uid
        );

        $this->assertSame('applied', $result);
        $this->assertFalse(metadata_exists('post', $post_id, '_obsolete'));
    }

    public function test_taxonomy_review_uses_one_complete_assignment_and_can_clear_it(): void
    {
        $uid = 'proposal-diff-taxonomy-clear';
        $post_id = self::factory()->post->create([
            'post_type'   => 'post',
            'post_status' => 'publish',
            'post_title'  => 'Taxonomy clear target',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);
        $term_id = self::factory()->term->create([
            'taxonomy' => 'category',
            'name'     => 'Current category',
            'slug'     => 'current-category',
        ]);
        wp_set_object_terms($post_id, [$term_id], 'category', false);

        $payload = $this->post_payload($post_id, $uid, 'Taxonomy clear target');
        $payload['post_type'] = 'post';
        $file = $this->write_temporary_json($payload);
        $conflict = DBVC_Sync_Posts::import_post_from_json(
            $file,
            false,
            null,
            $payload,
            [
                'tax_input.category.0.name' => 'keep',
                'tax_input.category.0.slug' => 'accept',
            ],
            $uid
        );

        $this->assertWPError($conflict);
        $this->assertSame('dbvc_import_conflicting_decisions', $conflict->get_error_code());
        $this->assertSame([$term_id], wp_get_object_terms($post_id, 'category', ['fields' => 'ids']));

        $result = DBVC_Sync_Posts::import_post_from_json(
            $file,
            false,
            null,
            $payload,
            [
                'tax_input.category' => 'accept',
            ],
            $uid
        );

        $this->assertSame('applied', $result);
        $this->assertSame([], wp_get_object_terms($post_id, 'category', ['fields' => 'ids']));
    }

    public function test_conflicting_parent_and_child_decisions_are_rejected_before_apply(): void
    {
        $uid = 'proposal-diff-overlapping-decisions';
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Current conflict title',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);
        update_post_meta($post_id, '_layout', [
            'color'   => 'red',
            'spacing' => 'compact',
        ]);

        $payload = $this->post_payload($post_id, $uid, 'Proposed conflict title');
        $payload['meta']['_layout'] = [[
            'color'   => 'blue',
            'spacing' => 'wide',
        ]];

        $file = $this->write_temporary_json($payload);
        $result = DBVC_Sync_Posts::import_post_from_json(
            $file,
            false,
            null,
            $payload,
            [
                'post_title'            => 'accept',
                'meta._layout'           => 'accept',
                'meta._layout.0.spacing' => 'keep',
            ],
            $uid
        );

        $this->assertWPError($result);
        $this->assertSame('dbvc_import_conflicting_decisions', $result->get_error_code());
        $this->assertSame('Current conflict title', get_post($post_id)->post_title);
        $this->assertSame(
            [
                'color'   => 'red',
                'spacing' => 'compact',
            ],
            get_post_meta($post_id, '_layout', true)
        );
    }

    public function test_existing_term_with_only_keep_decisions_has_no_import_side_effects(): void
    {
        $proposal_id = 'proposal-diff-term-keep-only';
        $uid = 'proposal-diff-term-keep-only-uid';
        $term = self::factory()->term->create_and_get([
            'taxonomy' => 'category',
            'name'     => 'Current term name',
            'slug'     => 'current-term-name',
        ]);
        update_term_meta($term->term_id, 'vf_object_uid', $uid);

        $payload = [
            'term_id'       => $term->term_id,
            'taxonomy'      => 'category',
            'name'          => 'Proposed term name',
            'slug'          => 'current-term-name',
            'description'   => '',
            'parent'        => 0,
            'vf_object_uid' => $uid,
            'meta'           => [],
        ];
        $manifest = $this->base_manifest($proposal_id, [[
            'item_type'     => 'term',
            'term_id'       => $term->term_id,
            'term_taxonomy' => 'category',
            'term_slug'     => 'current-term-name',
            'vf_object_uid' => $uid,
            'path'          => 'term.json',
        ]]);
        $this->write_proposal($proposal_id, $manifest, ['term.json' => $payload]);
        update_option('dbvc_proposal_decisions', [
            $proposal_id => [
                $uid => [
                    'name' => 'keep',
                ],
            ],
        ], false);

        $result = DBVC_Sync_Posts::import_backup($proposal_id, ['mode' => 'proposal-test']);

        $this->assertIsArray($result);
        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('Current term name', get_term($term->term_id, 'category')->name);
        $this->assertSame('', get_term_meta($term->term_id, 'dbvc_term_history', true));
    }

    public function test_nested_term_meta_uses_the_same_safe_leaf_merge(): void
    {
        $proposal_id = 'proposal-diff-term-nested-meta';
        $uid = 'proposal-diff-term-nested-meta-uid';
        $term = self::factory()->term->create_and_get([
            'taxonomy' => 'category',
            'name'     => 'Nested term meta',
            'slug'     => 'nested-term-meta',
        ]);
        update_term_meta($term->term_id, 'vf_object_uid', $uid);
        update_term_meta($term->term_id, '_layout', [
            'color'   => 'red',
            'spacing' => 'compact',
        ]);

        $payload = [
            'term_id'       => $term->term_id,
            'taxonomy'      => 'category',
            'name'          => 'Nested term meta',
            'slug'          => 'nested-term-meta',
            'description'   => '',
            'parent'        => 0,
            'vf_object_uid' => $uid,
            'meta'           => [
                '_layout' => [[
                    'color'   => 'blue',
                    'spacing' => 'wide',
                ]],
            ],
        ];
        $manifest = $this->base_manifest($proposal_id, [[
            'item_type'     => 'term',
            'term_id'       => $term->term_id,
            'term_taxonomy' => 'category',
            'term_slug'     => 'nested-term-meta',
            'vf_object_uid' => $uid,
            'path'          => 'term.json',
        ]]);
        $this->write_proposal($proposal_id, $manifest, ['term.json' => $payload]);
        update_option('dbvc_proposal_decisions', [
            $proposal_id => [
                $uid => [
                    'meta._layout.0.color'   => 'accept',
                    'meta._layout.0.spacing' => 'keep',
                ],
            ],
        ], false);

        $result = DBVC_Sync_Posts::import_backup($proposal_id, ['mode' => 'proposal-test']);

        $this->assertIsArray($result);
        $this->assertSame(1, $result['imported']);
        $this->assertSame(
            [
                'color'   => 'blue',
                'spacing' => 'compact',
            ],
            get_term_meta($term->term_id, '_layout', true)
        );
    }

    /**
     * Phase 5.2 contract for term masking overrides.
     */
    public function test_term_import_applies_proposal_mask_override(): void
    {
        $proposal_id = 'proposal-diff-term-mask';
        $uid = 'proposal-diff-term-mask-uid';
        $term = self::factory()->term->create_and_get([
            'taxonomy' => 'category',
            'name'     => 'Proposal diff term',
            'slug'     => 'proposal-diff-term',
        ]);
        update_term_meta($term->term_id, 'vf_object_uid', $uid);
        update_term_meta($term->term_id, 'secret_label', 'local');

        $payload = [
            'term_id'       => $term->term_id,
            'taxonomy'      => 'category',
            'name'          => 'Proposal diff term',
            'slug'          => 'proposal-diff-term',
            'description'   => '',
            'parent'        => 0,
            'vf_object_uid' => $uid,
            'meta'           => [
                'secret_label' => ['incoming'],
            ],
        ];
        $manifest = $this->base_manifest($proposal_id, [
            [
                'item_type'     => 'term',
                'term_id'       => $term->term_id,
                'term_taxonomy' => 'category',
                'term_slug'     => 'proposal-diff-term',
                'vf_object_uid' => $uid,
                'path'          => 'term.json',
            ],
        ]);
        $this->write_proposal($proposal_id, $manifest, ['term.json' => $payload]);

        update_option('dbvc_proposal_decisions', [
            $proposal_id => [
                $uid => [
                    'meta.secret_label' => 'accept',
                ],
            ],
        ], false);
        update_option('dbvc_mask_overrides', [
            $proposal_id => [
                $uid => [
                    'meta' => [
                        'secret_label' => [
                            'meta.secret_label' => [
                                'path'     => 'meta.secret_label',
                                'meta_key' => 'secret_label',
                                'scope'    => 'meta',
                                'value'    => 'redacted',
                            ],
                        ],
                    ],
                ],
            ],
        ], false);

        $result = DBVC_Sync_Posts::import_backup($proposal_id, ['mode' => 'proposal-test']);

        $this->assertIsArray($result);
        $this->assertSame([], $result['errors'] ?? []);
        $this->assertSame(1, $result['term_masking']['entities'] ?? null);
        $this->assertSame(1, $result['term_masking']['overridden'] ?? null);
        $this->assertSame(0, $result['term_masking']['suppressed'] ?? null);
        $this->assertSame(
            'redacted',
            get_term_meta($term->term_id, 'secret_label', true),
            'Term meta must receive the same proposal masking overrides as post meta.'
        );
    }

    public function test_term_import_combines_nested_mask_override_and_suppression(): void
    {
        $proposal_id = 'proposal-diff-term-nested-mask';
        $uid = 'proposal-diff-term-nested-mask-uid';
        $term = self::factory()->term->create_and_get([
            'taxonomy' => 'category',
            'name'     => 'Nested masked term',
            'slug'     => 'nested-masked-term',
        ]);
        update_term_meta($term->term_id, 'vf_object_uid', $uid);
        update_term_meta($term->term_id, 'private_layout', [
            'token' => 'local-token',
            'label' => 'local-label',
        ]);

        $payload = [
            'term_id'       => $term->term_id,
            'taxonomy'      => 'category',
            'name'          => 'Nested masked term',
            'slug'          => 'nested-masked-term',
            'description'   => '',
            'parent'        => 0,
            'vf_object_uid' => $uid,
            'meta'           => [
                'private_layout' => [[
                    'token' => 'incoming-token',
                    'label' => 'incoming-label',
                ]],
            ],
        ];
        $manifest = $this->base_manifest($proposal_id, [[
            'item_type'     => 'term',
            'term_id'       => $term->term_id,
            'term_taxonomy' => 'category',
            'term_slug'     => 'nested-masked-term',
            'vf_object_uid' => $uid,
            'path'          => 'term.json',
        ]]);
        $this->write_proposal($proposal_id, $manifest, ['term.json' => $payload]);

        update_option('dbvc_proposal_decisions', [
            $proposal_id => [
                $uid => [
                    'meta.private_layout' => 'accept',
                ],
            ],
        ], false);
        update_option('dbvc_masked_field_suppressions', [
            $proposal_id => [
                $uid => [
                    'meta' => [
                        'private_layout' => [
                            'meta.private_layout.0.token' => [
                                'path'     => 'meta.private_layout.0.token',
                                'meta_key' => 'private_layout',
                                'scope'    => 'meta',
                            ],
                        ],
                    ],
                ],
            ],
        ], false);
        update_option('dbvc_mask_overrides', [
            $proposal_id => [
                $uid => [
                    'meta' => [
                        'private_layout' => [
                            'meta.private_layout.0.label' => [
                                'path'     => 'meta.private_layout.0.label',
                                'meta_key' => 'private_layout',
                                'scope'    => 'meta',
                                'value'    => 'REDACTED',
                            ],
                        ],
                    ],
                ],
            ],
        ], false);

        $log_directory = $this->get_proposal_dir($proposal_id) . '/logs';
        $relative_log_directory = ltrim(
            str_replace(wp_normalize_path(ABSPATH), '', wp_normalize_path($log_directory)),
            '/'
        );
        update_option('dbvc_logging_enabled', '1');
        update_option('dbvc_logging_imports', '1');
        update_option('dbvc_logging_term_imports', '1');
        update_option('dbvc_logging_directory', $relative_log_directory);

        $result = DBVC_Sync_Posts::import_backup($proposal_id, ['mode' => 'proposal-test']);

        $this->assertIsArray($result);
        $this->assertSame([], $result['errors'] ?? []);
        $this->assertSame(
            [
                'entities'   => 1,
                'overridden' => 1,
                'suppressed' => 1,
            ],
            $result['term_masking'] ?? []
        );
        $this->assertSame(
            [
                'token' => 'local-token',
                'label' => 'REDACTED',
            ],
            get_term_meta($term->term_id, 'private_layout', true)
        );

        $log_path = trailingslashit($log_directory) . DBVC_Sync_Logger::LOG_FILENAME;
        $this->assertFileExists($log_path);
        $log_contents = (string) file_get_contents($log_path);
        $this->assertStringContainsString('Term masking directives applied', $log_contents);
        $this->assertStringContainsString('meta.private_layout.0.token', $log_contents);
        $this->assertStringContainsString('meta.private_layout.0.label', $log_contents);
    }

    public function test_term_masking_auto_accept_suppression_preserves_local_value(): void
    {
        $proposal_id = 'proposal-diff-term-mask-suppress';
        $uid = 'proposal-diff-term-mask-suppress-uid';
        [$term, $manifest] = $this->create_term_masking_proposal(
            $proposal_id,
            $uid,
            'suppressed_secret',
            'local-secret',
            'incoming-secret'
        );
        update_option('dbvc_mask_meta_keys', 'suppressed_secret');

        $mask_path = $this->get_term_masking_path($proposal_id, $uid, 'suppressed_secret');
        $request = new WP_REST_Request('POST', '/dbvc/v1/proposals/' . $proposal_id . '/masking/apply');
        $request->set_param('proposal_id', $proposal_id);
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode([
            'items' => [[
                'vf_object_uid' => $uid,
                'meta_path'     => $mask_path,
                'action'        => 'auto_accept',
                'suppress'      => true,
            ]],
        ]));
        $response = DBVC_Admin_App::apply_proposal_masking($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(1, $response->get_data()['applied']['auto_accept']);
        $gates = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest);
        $this->assertSame(0, $gates['counts']['masking']['pending']);

        $result = DBVC_Sync_Posts::import_backup($proposal_id, ['mode' => 'proposal-test']);

        $this->assertIsArray($result);
        $this->assertSame([], $result['errors'] ?? []);
        $this->assertSame(
            [
                'entities'   => 1,
                'overridden' => 0,
                'suppressed' => 1,
            ],
            $result['term_masking'] ?? []
        );
        $this->assertSame(
            'local-secret',
            get_term_meta($term->term_id, 'suppressed_secret', true)
        );
    }

    public function test_term_masking_ignore_and_revert_restore_review_state(): void
    {
        $proposal_id = 'proposal-diff-term-mask-revert';
        $uid = 'proposal-diff-term-mask-revert-uid';
        [, $manifest] = $this->create_term_masking_proposal(
            $proposal_id,
            $uid,
            'revert_secret',
            'local-secret',
            'incoming-secret'
        );
        update_option('dbvc_mask_meta_keys', 'revert_secret');

        $before = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest);
        $this->assertSame(1, $before['counts']['masking']['pending']);

        $mask_path = $this->get_term_masking_path($proposal_id, $uid, 'revert_secret');
        $apply = new WP_REST_Request('POST', '/dbvc/v1/proposals/' . $proposal_id . '/masking/apply');
        $apply->set_param('proposal_id', $proposal_id);
        $apply->set_header('content-type', 'application/json');
        $apply->set_body(wp_json_encode([
            'items' => [[
                'vf_object_uid' => $uid,
                'meta_path'     => $mask_path,
                'action'        => 'ignore',
            ]],
        ]));
        $apply_response = DBVC_Admin_App::apply_proposal_masking($apply);

        $this->assertInstanceOf(WP_REST_Response::class, $apply_response);
        $this->assertSame(1, $apply_response->get_data()['applied']['ignore']);
        $reviewed = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest);
        $this->assertSame(0, $reviewed['counts']['masking']['pending']);

        $revert = new WP_REST_Request('POST', '/dbvc/v1/proposals/' . $proposal_id . '/masking/revert');
        $revert->set_param('proposal_id', $proposal_id);
        $revert_response = DBVC_Admin_App::revert_proposal_masking($revert);

        $this->assertInstanceOf(WP_REST_Response::class, $revert_response);
        $this->assertSame(1, $revert_response->get_data()['cleared']['decisions']);
        $after = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest);
        $this->assertSame(1, $after['counts']['masking']['pending']);
    }

    public function test_declined_new_entity_is_resolved_in_proposal_summary(): void
    {
        $proposal_id = 'proposal-diff-declined-new';
        $uid = 'proposal-diff-declined-new-post';
        $manifest = $this->base_manifest($proposal_id, [
            [
                'item_type'     => 'post',
                'post_id'       => 765432,
                'post_type'     => 'page',
                'post_title'    => 'Declined new post',
                'post_status'   => 'publish',
                'vf_object_uid' => $uid,
                'path'          => 'declined.json',
                'hash'          => '',
            ],
        ]);
        $this->write_proposal($proposal_id, $manifest, [
            'declined.json' => $this->post_payload(765432, $uid, 'Declined new post'),
        ]);
        update_option('dbvc_proposal_decisions', [
            $proposal_id => [
                $uid => [
                    DBVC_NEW_ENTITY_DECISION_KEY => 'decline_new',
                ],
            ],
        ], false);
        update_option('dbvc_mask_post_fields', ['post_excerpt']);

        $request = new WP_REST_Request('GET', '/dbvc/v1/proposals');
        $request->set_param('include_readiness', true);
        $response = DBVC_Admin_App::get_proposals($request);
        $items = $response->get_data()['items'];
        $proposal = null;
        foreach ($items as $item) {
            if (($item['id'] ?? '') === $proposal_id) {
                $proposal = $item;
                break;
            }
        }

        $this->assertNotNull($proposal);
        $this->assertSame(1, $proposal['new_entities']['total']);
        $this->assertSame(0, $proposal['new_entities']['accepted']);
        $this->assertSame(0, $proposal['new_entities']['pending']);
        $this->assertSame(1, $proposal['new_entities']['declined']);
        $this->assertSame(1, $proposal['new_entities']['resolved']);
        $this->assertSame(
            [
                'accepted_new' => 0,
                'declined_new' => 1,
                'pending_new'  => 0,
            ],
            $proposal['new_entities']['states']
        );
        $this->assertSame(1, $proposal['decisions']['declined_new']);
        $this->assertSame(1, $proposal['decisions']['total']);
        $this->assertTrue($proposal['apply_gates']['ready']);
        $this->assertSame(0, $proposal['apply_gates']['counts']['masking']['total']);
        $this->assertSame(0, $proposal['apply_gates']['counts']['masking']['pending']);
        $this->assertNotContains(
            'new_entities',
            $this->get_blocking_categories($proposal['apply_gates'])
        );
        $this->assertNotContains(
            'masking',
            $this->get_blocking_categories($proposal['apply_gates'])
        );

        $masking_request = new WP_REST_Request(
            'GET',
            '/dbvc/v1/proposals/' . $proposal_id . '/masking'
        );
        $masking_request->set_param('proposal_id', $proposal_id);
        $masking = DBVC_Admin_App::get_proposal_masking($masking_request)->get_data();
        $this->assertSame([], $masking['fields']);

        $entities_request = new WP_REST_Request(
            'GET',
            '/dbvc/v1/proposals/' . $proposal_id . '/entities'
        );
        $entities_request->set_param('proposal_id', $proposal_id);
        $entities = DBVC_Admin_App::get_proposal_entities($entities_request)->get_data()['items'];
        $this->assertCount(1, $entities);
        $this->assertSame('declined_new', $entities[0]['new_entity_state']);
        $this->assertSame('resolved', $entities[0]['overall_status']);
        $this->assertFalse($entities[0]['diff_state']['needs_review']);

        $resolved_request = new WP_REST_Request(
            'GET',
            '/dbvc/v1/proposals/' . $proposal_id . '/entities'
        );
        $resolved_request->set_param('proposal_id', $proposal_id);
        $resolved_request->set_param('status', 'resolved');
        $resolved = DBVC_Admin_App::get_proposal_entities($resolved_request)->get_data()['items'];
        $this->assertCount(1, $resolved);

        $review_request = new WP_REST_Request(
            'GET',
            '/dbvc/v1/proposals/' . $proposal_id . '/entities'
        );
        $review_request->set_param('proposal_id', $proposal_id);
        $review_request->set_param('status', 'needs_review');
        $review = DBVC_Admin_App::get_proposal_entities($review_request)->get_data()['items'];
        $this->assertSame([], $review);

        $detail_request = new WP_REST_Request(
            'GET',
            '/dbvc/v1/proposals/' . $proposal_id . '/entities/' . $uid
        );
        $detail_request->set_param('proposal_id', $proposal_id);
        $detail_request->set_param('vf_object_uid', $uid);
        $detail = DBVC_Admin_App::get_proposal_entity($detail_request)->get_data();
        $this->assertSame('declined_new', $detail['new_entity_state']);
        $this->assertFalse($detail['diff_state']['needs_review']);
    }

    public function test_new_entity_states_cover_posts_terms_and_pending_bulk_acceptance(): void
    {
        $proposal_id = 'proposal-diff-new-state-matrix';
        $accepted_uid = 'proposal-diff-accepted-new-post';
        $declined_term_id = 765433;
        $pending_term_uid = 'proposal-diff-pending-new-term';
        $manifest = $this->base_manifest($proposal_id, [
            [
                'item_type'     => 'post',
                'post_id'       => 765434,
                'post_type'     => 'page',
                'post_title'    => 'Accepted new post',
                'post_status'   => 'publish',
                'vf_object_uid' => $accepted_uid,
                'path'          => 'accepted-post.json',
                'hash'          => '',
            ],
            [
                'item_type'     => 'term',
                'term_id'       => $declined_term_id,
                'term_taxonomy' => 'category',
                'term_slug'     => 'declined-new-term-id-fallback',
                'term_name'     => 'Declined new term',
                'path'          => 'declined-term.json',
            ],
            [
                'item_type'     => 'term',
                'term_id'       => 765435,
                'term_taxonomy' => 'category',
                'term_slug'     => 'pending-new-term',
                'term_name'     => 'Pending new term',
                'vf_object_uid' => $pending_term_uid,
                'path'          => 'pending-term.json',
            ],
        ]);
        $this->write_proposal($proposal_id, $manifest, [
            'accepted-post.json' => $this->post_payload(765434, $accepted_uid, 'Accepted new post'),
            'declined-term.json' => $this->term_payload(
                $declined_term_id,
                '',
                'Declined new term',
                'declined-new-term-id-fallback'
            ),
            'pending-term.json' => $this->term_payload(
                765435,
                $pending_term_uid,
                'Pending new term',
                'pending-new-term'
            ),
        ]);
        update_option('dbvc_proposal_decisions', [
            $proposal_id => [
                $accepted_uid => [
                    DBVC_NEW_ENTITY_DECISION_KEY => 'accept_new',
                ],
                (string) $declined_term_id => [
                    DBVC_NEW_ENTITY_DECISION_KEY => 'decline_new',
                ],
            ],
        ], false);

        $response = DBVC_Admin_App::get_proposals(new WP_REST_Request('GET', '/dbvc/v1/proposals'));
        $proposal = null;
        foreach ($response->get_data()['items'] as $item) {
            if (($item['id'] ?? '') === $proposal_id) {
                $proposal = $item;
                break;
            }
        }

        $this->assertNotNull($proposal);
        $this->assertSame(3, $proposal['new_entities']['total']);
        $this->assertSame(1, $proposal['new_entities']['accepted']);
        $this->assertSame(1, $proposal['new_entities']['declined']);
        $this->assertSame(1, $proposal['new_entities']['pending']);
        $this->assertSame(2, $proposal['new_entities']['resolved']);
        $this->assertSame(
            [
                'accepted_new' => 1,
                'declined_new' => 1,
                'pending_new'  => 1,
            ],
            $proposal['new_entities']['states']
        );

        $entities_request = new WP_REST_Request(
            'GET',
            '/dbvc/v1/proposals/' . $proposal_id . '/entities'
        );
        $entities_request->set_param('proposal_id', $proposal_id);
        $entity_response = DBVC_Admin_App::get_proposal_entities($entities_request)->get_data();
        $entities = $entity_response['items'];
        $entities_by_uid = [];
        foreach ($entities as $entity) {
            $entities_by_uid[(string) $entity['vf_object_uid']] = $entity;
        }
        $this->assertSame(1, $entity_response['status_counts']['new_entities_pending']);
        $this->assertSame(
            1,
            $entities_by_uid[$pending_term_uid]['status_counts']['new_entities_pending']
        );
        $this->assertSame(
            0,
            $entities_by_uid[$accepted_uid]['status_counts']['new_entities_pending']
        );
        $this->assertSame(
            0,
            $entities_by_uid[(string) $declined_term_id]['status_counts']['new_entities_pending']
        );
        $this->assertSame(
            'declined_new',
            $entities_by_uid[(string) $declined_term_id]['new_entity_state']
        );
        $this->assertSame(
            'pending_new',
            $entities_by_uid[$pending_term_uid]['new_entity_state']
        );

        $pending_request = new WP_REST_Request(
            'GET',
            '/dbvc/v1/proposals/' . $proposal_id . '/entities'
        );
        $pending_request->set_param('proposal_id', $proposal_id);
        $pending_request->set_param('status', 'new_entities_pending');
        $pending_entities = DBVC_Admin_App::get_proposal_entities($pending_request)->get_data()['items'];
        $this->assertCount(1, $pending_entities);
        $this->assertSame($pending_term_uid, $pending_entities[0]['vf_object_uid']);

        $accept_request = new WP_REST_Request(
            'POST',
            '/dbvc/v1/proposals/' . $proposal_id . '/entities/accept'
        );
        $accept_request->set_param('proposal_id', $proposal_id);
        $accept_request->set_header('content-type', 'application/json');
        $accept_request->set_body(wp_json_encode(['scope' => 'new_only']));
        $accepted = DBVC_Admin_App::accept_entities_bulk($accept_request);

        $this->assertInstanceOf(WP_REST_Response::class, $accepted);
        $this->assertSame(1, $accepted->get_data()['accepted_new']);
        $store = get_option('dbvc_proposal_decisions', []);
        $this->assertSame(
            'accept_new',
            $store[$proposal_id][$accepted_uid][DBVC_NEW_ENTITY_DECISION_KEY] ?? null
        );
        $this->assertSame(
            'decline_new',
            $store[$proposal_id][(string) $declined_term_id][DBVC_NEW_ENTITY_DECISION_KEY] ?? null
        );
        $this->assertSame(
            'accept_new',
            $store[$proposal_id][$pending_term_uid][DBVC_NEW_ENTITY_DECISION_KEY] ?? null
        );
        $resolved_response = DBVC_Admin_App::get_proposal_entities($entities_request)->get_data();
        $this->assertSame(0, $resolved_response['status_counts']['new_entities_pending']);
    }

    public function test_declined_new_posts_and_terms_are_recorded_skips_and_never_reapplied(): void
    {
        $proposal_id = 'proposal-diff-declined-new-apply';
        $post_uid = 'proposal-diff-declined-apply-post';
        $term_id = 765436;
        $term_slug = 'proposal-diff-declined-apply-term';
        $manifest = $this->base_manifest($proposal_id, [
            [
                'item_type'     => 'post',
                'post_id'       => 765437,
                'post_type'     => 'page',
                'post_title'    => 'Declined apply post',
                'post_status'   => 'publish',
                'vf_object_uid' => $post_uid,
                'path'          => 'declined-apply-post.json',
                'hash'          => '',
            ],
            [
                'item_type'     => 'term',
                'term_id'       => $term_id,
                'term_taxonomy' => 'category',
                'term_slug'     => $term_slug,
                'term_name'     => 'Declined apply term',
                'path'          => 'declined-apply-term.json',
            ],
        ]);
        $this->write_proposal($proposal_id, $manifest, [
            'declined-apply-post.json' => $this->post_payload(
                765437,
                $post_uid,
                'Declined apply post'
            ),
            'declined-apply-term.json' => $this->term_payload(
                $term_id,
                '',
                'Declined apply term',
                $term_slug
            ),
        ]);
        update_option('dbvc_proposal_decisions', [
            $proposal_id => [
                $post_uid => [
                    DBVC_NEW_ENTITY_DECISION_KEY => 'decline_new',
                ],
                (string) $term_id => [
                    DBVC_NEW_ENTITY_DECISION_KEY => 'decline_new',
                ],
            ],
        ], false);
        update_option('dbvc_allow_new_posts', '1');
        update_option('dbvc_force_reapply_new_posts', '1');
        update_option('dbvc_auto_clear_decisions', '0');
        update_option('dbvc_mask_post_fields', ['post_excerpt']);

        $before_apply = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest);
        $this->assertTrue($before_apply['ready']);
        $this->assertSame(0, $before_apply['counts']['masking']['pending']);

        $forced = DBVC_Sync_Posts::import_backup($proposal_id, [
            'mode'                    => 'full',
            'force_reapply_new_posts' => true,
        ]);

        $this->assertIsArray($forced);
        $this->assertSame(0, $forced['imported']);
        $this->assertSame(2, $forced['skipped']);
        $this->assertCount(2, $forced['skipped_entities']);
        $this->assertSame(
            ['post', 'term'],
            array_column($forced['skipped_entities'], 'entity_type')
        );
        $this->assertSame(
            ['declined_by_reviewer'],
            array_values(array_unique(array_column($forced['skipped_entities'], 'reason')))
        );
        $this->assertSame(
            ['declined_new'],
            array_values(array_unique(array_column($forced['skipped_entities'], 'decision_state')))
        );

        update_option('dbvc_auto_clear_decisions', '1');
        $apply_request = new WP_REST_Request(
            'POST',
            '/dbvc/v1/proposals/' . $proposal_id . '/apply'
        );
        $apply_request->set_param('proposal_id', $proposal_id);
        $apply_request->set_param('force_reapply_new_posts', true);
        $applied = DBVC_Admin_App::apply_proposal($apply_request);

        $this->assertInstanceOf(WP_REST_Response::class, $applied);
        $apply_data = $applied->get_data();
        $this->assertSame(0, $apply_data['result']['imported']);
        $this->assertSame(2, $apply_data['result']['skipped']);
        $this->assertSame(2, $apply_data['result']['reviewer_declined']);
        $this->assertCount(2, $apply_data['result']['skipped_entities']);
        $this->assertTrue($apply_data['had_decisions']);
        $this->assertTrue($apply_data['decisions_cleared']);
        $this->assertSame('closed', $apply_data['status']);
        $this->assertArrayNotHasKey(
            $proposal_id,
            get_option('dbvc_proposal_decisions', [])
        );
        $declined_store = get_option('dbvc_proposal_declined_new_entities', []);
        $this->assertTrue($declined_store[$proposal_id][$post_uid] ?? false);
        $this->assertTrue($declined_store[$proposal_id][(string) $term_id] ?? false);

        $list_request = new WP_REST_Request('GET', '/dbvc/v1/proposals');
        $list_request->set_param('include_readiness', true);
        $proposals = DBVC_Admin_App::get_proposals($list_request);
        $refreshed = null;
        foreach ($proposals->get_data()['items'] as $item) {
            if (($item['id'] ?? '') === $proposal_id) {
                $refreshed = $item;
                break;
            }
        }
        $this->assertNotNull($refreshed);
        $this->assertSame(0, $refreshed['new_entities']['pending']);
        $this->assertSame(2, $refreshed['new_entities']['declined']);
        $this->assertSame(2, $refreshed['new_entities']['resolved']);
        $this->assertSame(0, $refreshed['apply_gates']['counts']['masking']['pending']);
        $this->assertTrue($refreshed['apply_gates']['ready']);

        $entities_request = new WP_REST_Request(
            'GET',
            '/dbvc/v1/proposals/' . $proposal_id . '/entities'
        );
        $entities_request->set_param('proposal_id', $proposal_id);
        $refreshed_entities = DBVC_Admin_App::get_proposal_entities($entities_request)->get_data()['items'];
        $this->assertCount(2, $refreshed_entities);
        $this->assertSame(
            ['declined_new'],
            array_values(array_unique(array_column($refreshed_entities, 'new_entity_state')))
        );
        $this->assertSame(
            ['resolved'],
            array_values(array_unique(array_column($refreshed_entities, 'overall_status')))
        );
        $this->assertSame(
            [],
            get_posts([
                'post_type'      => 'any',
                'post_status'    => 'any',
                'meta_key'       => 'vf_object_uid',
                'meta_value'     => $post_uid,
                'fields'         => 'ids',
                'posts_per_page' => -1,
            ])
        );
        $this->assertFalse(get_term_by('slug', $term_slug, 'category'));

        $delete_request = new WP_REST_Request(
            'DELETE',
            '/dbvc/v1/proposals/' . $proposal_id
        );
        $delete_request->set_param('proposal_id', $proposal_id);
        $deleted = DBVC_Admin_App::delete_proposal($delete_request);
        $this->assertInstanceOf(WP_REST_Response::class, $deleted);
        $this->assertTrue($deleted->get_data()['deleted']);
        $this->assertArrayNotHasKey(
            $proposal_id,
            get_option('dbvc_proposal_declined_new_entities', [])
        );
    }

    public function test_apply_gate_contract_exposes_every_category_and_readiness_endpoint(): void
    {
        $proposal_id = 'proposal-diff-gate-shape';
        $manifest = $this->base_manifest($proposal_id, []);
        $this->write_proposal($proposal_id, $manifest, []);

        $request = new WP_REST_Request(
            'GET',
            '/dbvc/v1/proposals/' . $proposal_id . '/readiness'
        );
        $request->set_param('proposal_id', $proposal_id);
        $response = DBVC_Admin_App::get_proposal_readiness($request);
        $data = $response->get_data();
        $gates = $data['apply_gates'] ?? [];

        $this->assertTrue($gates['ready'] ?? false);
        $this->assertSame([], $gates['blocking'] ?? null);
        $this->assertSame([], $gates['override_tokens'] ?? null);
        $this->assertSame(
            [
                'duplicates',
                'resolver',
                'masking',
                'new_entities',
                'field_decisions',
                'snapshots',
                'unsupported_domains',
                'hashes',
                'permissions',
            ],
            array_keys($gates['counts'] ?? [])
        );
        $this->assertSame(
            [
                'field_needs_review',
                'meta_needs_review',
                'media_needs_review',
                'resolver_conflicts',
                'masking_candidates',
                'duplicates',
                'new_entities_pending',
            ],
            array_keys($gates['status_counts'] ?? [])
        );
        $this->assertSame(
            array_fill_keys(array_keys($gates['status_counts']), 0),
            $gates['status_counts']
        );
    }

    public function test_canonical_status_counts_keep_field_meta_media_and_masking_work_separate(): void
    {
        $proposal_id = 'proposal-diff-canonical-status-counts';
        $uid = 'proposal-diff-canonical-status-post';
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Current status count title',
            'post_name'   => 'current-status-count-title',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);
        update_post_meta($post_id, 'plain_status_meta', 'current plain');
        update_post_meta($post_id, 'masked_status_meta', 'current masked');
        update_option('dbvc_mask_meta_keys', 'masked_status_meta');

        DBVC_Snapshot_Manager::capture_post_snapshot($proposal_id, $post_id, $uid);
        $proposed = DBVC_Snapshot_Manager::read_snapshot($proposal_id, $uid);
        $proposed['post_title'] = 'Proposed status count title';
        $proposed['meta']['plain_status_meta'] = ['proposed plain'];
        $proposed['meta']['masked_status_meta'] = ['proposed masked'];

        $manifest = $this->base_manifest($proposal_id, [[
            'item_type'     => 'post',
            'post_id'       => $post_id,
            'post_type'     => 'page',
            'post_title'    => 'Proposed status count title',
            'post_name'     => 'current-status-count-title',
            'post_status'   => 'publish',
            'vf_object_uid' => $uid,
            'path'          => 'canonical-status-counts.json',
            'hash'          => hash('sha256', wp_json_encode($proposed)),
        ]]);
        $manifest['totals']['media_items'] = 2;
        $this->write_proposal($proposal_id, $manifest, [
            'canonical-status-counts.json' => $proposed,
        ]);

        $resolver_result = [
            'attachments' => [
                'asset-conflict' => [
                    'status'     => 'conflict',
                    'descriptor' => ['original_id' => 44],
                ],
                'asset-download' => [
                    'status'     => 'needs_download',
                    'descriptor' => ['original_id' => 45],
                ],
            ],
            'conflicts' => [
                ['status' => 'conflict', 'descriptor' => ['original_id' => 44]],
            ],
            'metrics' => [
                'detected'   => 2,
                'unresolved' => 2,
            ],
        ];

        $before = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest, [
            'resolver_result' => $resolver_result,
        ]);
        $this->assertSame([
            'field_needs_review'   => 2,
            'meta_needs_review'    => 1,
            'media_needs_review'   => 2,
            'resolver_conflicts'   => 1,
            'masking_candidates'   => 1,
            'duplicates'           => 0,
            'new_entities_pending' => 0,
        ], $before['status_counts']);
        $this->assertSame(2, $before['counts']['field_decisions']['pending']);
        $this->assertSame(1, $before['counts']['masking']['pending']);
        $this->assertSame(2, $before['counts']['resolver']['pending']);

        $entities_request = new WP_REST_Request(
            'GET',
            '/dbvc/v1/proposals/' . $proposal_id . '/entities'
        );
        $entities_request->set_param('proposal_id', $proposal_id);
        $entities = DBVC_Admin_App::get_proposal_entities($entities_request)->get_data();
        $this->assertCount(1, $entities['items']);
        $this->assertSame(2, $entities['items'][0]['status_counts']['field_needs_review']);
        $this->assertSame(1, $entities['items'][0]['status_counts']['meta_needs_review']);
        $this->assertSame(1, $entities['items'][0]['status_counts']['masking_candidates']);
        $this->assertSame(
            $entities['apply_gates']['status_counts'],
            $entities['status_counts']
        );

        foreach (['field_needs_review', 'meta_needs_review', 'masking_candidates'] as $filter) {
            $filter_request = new WP_REST_Request(
                'GET',
                '/dbvc/v1/proposals/' . $proposal_id . '/entities'
            );
            $filter_request->set_param('proposal_id', $proposal_id);
            $filter_request->set_param('status', $filter);
            $this->assertCount(
                1,
                DBVC_Admin_App::get_proposal_entities($filter_request)->get_data()['items'],
                sprintf('The %s filter should retain the matching entity.', $filter)
            );
        }

        update_option('dbvc_proposal_decisions', [
            $proposal_id => [
                $uid => [
                    'post_title'                  => 'accept',
                    'meta.plain_status_meta.0'    => 'keep',
                    'meta.masked_status_meta.0'   => 'keep',
                ],
            ],
        ], false);
        $after_fields = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest, [
            'resolver_result' => $resolver_result,
        ]);
        $this->assertSame(0, $after_fields['status_counts']['field_needs_review']);
        $this->assertSame(0, $after_fields['status_counts']['meta_needs_review']);
        $this->assertSame(0, $after_fields['status_counts']['masking_candidates']);
        $this->assertSame(2, $after_fields['status_counts']['media_needs_review']);
        $this->assertSame(1, $after_fields['status_counts']['resolver_conflicts']);

        update_option('dbvc_resolver_decisions', [
            $proposal_id => [
                '44' => ['action' => 'skip', 'scope' => 'proposal'],
                '45' => ['action' => 'skip', 'scope' => 'proposal'],
            ],
        ], false);
        $after_media = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest, [
            'resolver_result' => $resolver_result,
        ]);
        $this->assertSame(0, $after_media['status_counts']['media_needs_review']);
        $this->assertSame(0, $after_media['status_counts']['resolver_conflicts']);
        $this->assertSame(2, $after_media['counts']['resolver']['resolved_by_decision']);
    }

    public function test_writable_non_post_domains_are_explicitly_blocked_until_review_is_supported(): void
    {
        $proposal_id = 'proposal-diff-unsupported-domains';
        $manifest = $this->base_manifest($proposal_id, [
            [
                'item_type' => 'options',
                'path'      => 'options.json',
            ],
            [
                'item_type'          => 'options_group',
                'options_group_id'   => 'acf_options',
                'options_group_type' => 'acf',
                'path'               => 'options/acf-options.json',
            ],
            [
                'item_type' => 'menus',
                'path'      => 'menus.json',
            ],
        ]);
        $this->write_proposal($proposal_id, $manifest, [
            'options.json'             => ['proposal_diff_option' => 'incoming'],
            'options/acf-options.json' => [
                'meta'    => ['group_id' => 'acf_options', 'type' => 'acf'],
                'options' => ['options_proposal_diff' => 'incoming'],
            ],
            'menus.json'               => ['menus' => []],
        ]);

        $gates = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest);
        $categories = $this->get_blocking_categories($gates);
        $domain_counts = $gates['counts']['unsupported_domains'] ?? [];

        $this->assertFalse($gates['ready']);
        $this->assertContains('unsupported_domains', $categories);
        $this->assertSame(3, $domain_counts['total'] ?? null);
        $this->assertSame(3, $domain_counts['blocked'] ?? null);
        $this->assertSame([
            'options'       => 1,
            'options_group' => 1,
            'menus'         => 1,
        ], $domain_counts['types'] ?? null);
        $this->assertFalse($domain_counts['review_supported'] ?? true);
        $this->assertFalse($domain_counts['baseline_supported'] ?? true);
        $this->assertTrue($domain_counts['enforced'] ?? false);
        $this->assertContains(
            'unsupported_domains',
            $this->get_blocking_categories(DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest, [
                'ignore_missing_hash' => true,
            ])),
            'The import-hash override must not bypass unsupported writable domains.'
        );

        $request = new WP_REST_Request('GET', '/dbvc/v1/proposals/' . $proposal_id . '/readiness');
        $request->set_param('proposal_id', $proposal_id);
        $readiness = DBVC_Admin_App::get_proposal_readiness($request)->get_data();
        $this->assertSame($domain_counts, $readiness['apply_gates']['counts']['unsupported_domains'] ?? null);

        $this->assert_apply_blocked_by($proposal_id, 'unsupported_domains');
    }

    public function test_post_backed_fse_entities_are_not_treated_as_unsupported_domains(): void
    {
        $proposal_id = 'proposal-diff-post-backed-fse';
        $manifest = $this->base_manifest($proposal_id, [[
            'item_type'     => 'post',
            'post_id'       => 721900,
            'post_type'     => 'wp_template',
            'post_title'    => 'Post-backed template',
            'vf_object_uid' => 'proposal-diff-post-backed-fse-uid',
            'path'          => 'wp_template/post-backed-template.json',
        ]]);

        $gates = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest);

        $this->assertNotContains('unsupported_domains', $this->get_blocking_categories($gates));
        $this->assertSame(0, $gates['counts']['unsupported_domains']['blocked'] ?? null);
    }

    public function test_apply_gate_accepts_a_trusted_cli_permission_context(): void
    {
        $proposal_id = 'proposal-diff-cli-permission';
        $manifest = $this->base_manifest($proposal_id, []);
        $this->write_proposal($proposal_id, $manifest, []);
        wp_set_current_user(0);

        $denied = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest, [
            'permission_granted' => false,
        ]);
        $authorized = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest, [
            'permission_granted' => true,
        ]);

        $this->assertContains('permissions', $this->get_blocking_categories($denied));
        $this->assertSame(1, $denied['counts']['permissions']['denied']);
        $this->assertTrue($authorized['ready']);
        $this->assertNotContains('permissions', $this->get_blocking_categories($authorized));
        $this->assertSame(0, $authorized['counts']['permissions']['denied']);
    }

    public function test_hash_override_only_removes_hash_blocker(): void
    {
        $proposal_id = 'proposal-diff-hash-override-scope';
        $uid = 'proposal-diff-hash-new';
        $manifest = $this->base_manifest($proposal_id, [
            [
                'item_type'     => 'post',
                'post_id'       => 654321,
                'post_type'     => 'page',
                'post_title'    => 'Pending hash entity',
                'vf_object_uid' => $uid,
                'path'          => 'pending-hash.json',
            ],
        ]);
        $manifest['totals']['missing_import_hash'] = 1;
        $this->write_proposal($proposal_id, $manifest, [
            'pending-hash.json' => $this->post_payload(654321, $uid, 'Pending hash entity'),
        ]);

        $gates = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest, [
            'ignore_missing_hash' => true,
        ]);
        $categories = $this->get_blocking_categories($gates);

        $this->assertFalse($gates['ready']);
        $this->assertContains('new_entities', $categories);
        $this->assertNotContains('hashes', $categories);
        $this->assertTrue($gates['counts']['hashes']['overridden']);
    }

    public function test_resolver_decision_clears_only_the_resolver_blocker(): void
    {
        $proposal_id = 'proposal-diff-resolver-gate';
        $manifest = $this->base_manifest($proposal_id, []);
        $manifest['media_index'] = [
            [
                'original_id' => 44,
                'filename'    => 'proposal-diff-missing.jpg',
                'source_url'  => 'https://example.com/proposal-diff-missing.jpg',
            ],
        ];
        $manifest['totals']['media_items'] = 1;
        $this->write_proposal($proposal_id, $manifest, []);

        $before = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest);
        $this->assertContains('resolver', $this->get_blocking_categories($before));
        $this->assertSame(1, $before['counts']['resolver']['pending']);

        update_option('dbvc_resolver_decisions', [
            $proposal_id => [
                '44' => [
                    'action' => 'skip',
                    'scope'  => 'proposal',
                ],
            ],
        ], false);

        $after = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest);
        $this->assertNotContains('resolver', $this->get_blocking_categories($after));
        $this->assertSame(0, $after['counts']['resolver']['pending']);
        $this->assertSame(1, $after['counts']['resolver']['resolved_by_decision']);
    }

    public function test_rest_dispatch_returns_structured_conflict_for_blocked_apply(): void
    {
        $proposal_id = 'proposal-diff-rest-blocked';
        $uid = 'proposal-diff-rest-new';
        $manifest = $this->base_manifest($proposal_id, [
            [
                'item_type'     => 'post',
                'post_id'       => 532109,
                'post_type'     => 'page',
                'post_title'    => 'REST blocked post',
                'vf_object_uid' => $uid,
                'path'          => 'rest-blocked.json',
            ],
        ]);
        $this->write_proposal($proposal_id, $manifest, [
            'rest-blocked.json' => $this->post_payload(532109, $uid, 'REST blocked post'),
        ]);

        $request = new WP_REST_Request('POST', '/dbvc/v1/proposals/' . $proposal_id . '/apply');
        $response = rest_get_server()->dispatch($request);
        $data = $response->get_data();

        $this->assertSame(409, $response->get_status());
        $this->assertSame('dbvc_proposal_not_ready', $data['code'] ?? null);
        $this->assertFalse($data['data']['gates']['ready'] ?? true);
        $this->assertContains(
            'new_entities',
            $this->get_blocking_categories($data['data']['gates'] ?? [])
        );
    }

    public function test_field_decision_gate_tracks_snapshot_diff_paths(): void
    {
        $proposal_id = 'proposal-diff-field-gate';
        $uid = 'proposal-diff-field-gate-post';
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Current gate title',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);
        DBVC_Snapshot_Manager::capture_post_snapshot($proposal_id, $post_id, $uid);
        $proposed = DBVC_Snapshot_Manager::read_snapshot($proposal_id, $uid);
        $proposed['post_title'] = 'Proposed gate title';

        $manifest = $this->base_manifest($proposal_id, [
            [
                'item_type'     => 'post',
                'post_id'       => $post_id,
                'post_type'     => 'page',
                'post_title'    => 'Proposed gate title',
                'vf_object_uid' => $uid,
                'path'          => 'field-gate.json',
            ],
        ]);
        $this->write_proposal($proposal_id, $manifest, ['field-gate.json' => $proposed]);

        $before = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest);
        $this->assertContains('field_decisions', $this->get_blocking_categories($before));
        $this->assertSame(1, $before['counts']['field_decisions']['pending']);

        update_option('dbvc_proposal_decisions', [
            $proposal_id => [
                $uid => [
                    'post_title' => 'accept',
                ],
            ],
        ], false);

        $after = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest);
        $this->assertNotContains('field_decisions', $this->get_blocking_categories($after));
        $this->assertSame(0, $after['counts']['field_decisions']['pending']);
        $this->assertSame(1, $after['counts']['field_decisions']['reviewed']);
    }

    public function test_masking_gate_uses_the_same_saved_field_decision(): void
    {
        $proposal_id = 'proposal-diff-masking-gate';
        $uid = 'proposal-diff-masking-gate-post';
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Masking gate post',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);
        update_post_meta($post_id, 'secret_gate', 'local');
        update_option('dbvc_mask_meta_keys', 'secret_gate');
        DBVC_Snapshot_Manager::capture_post_snapshot($proposal_id, $post_id, $uid);
        $proposed = DBVC_Snapshot_Manager::read_snapshot($proposal_id, $uid);
        $proposed['meta']['secret_gate'] = ['incoming'];

        $manifest = $this->base_manifest($proposal_id, [
            [
                'item_type'     => 'post',
                'post_id'       => $post_id,
                'post_type'     => 'page',
                'post_title'    => 'Masking gate post',
                'vf_object_uid' => $uid,
                'path'          => 'masking-gate.json',
            ],
        ]);
        $this->write_proposal($proposal_id, $manifest, ['masking-gate.json' => $proposed]);

        $before = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest);
        $this->assertContains('masking', $this->get_blocking_categories($before));
        $this->assertSame(1, $before['counts']['masking']['pending']);
        $this->assertSame(0, $before['counts']['field_decisions']['pending']);

        update_option('dbvc_proposal_decisions', [
            $proposal_id => [
                $uid => [
                    'meta.secret_gate.0' => 'keep',
                ],
            ],
        ], false);

        $after = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest);
        $this->assertNotContains('masking', $this->get_blocking_categories($after));
        $this->assertSame(0, $after['counts']['masking']['pending']);
    }

    public function test_entity_detail_refresh_preserves_masking_review_decisions(): void
    {
        $proposal_id = 'proposal-diff-mask-refresh';
        $uid = 'proposal-diff-mask-refresh-post';
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Current mask refresh title',
            'post_date'   => '2026-01-02 12:00:00',
        ]);
        update_post_meta($post_id, 'vf_object_uid', $uid);
        DBVC_Snapshot_Manager::capture_post_snapshot($proposal_id, $post_id, $uid);
        update_option('dbvc_mask_post_fields', ['post_date']);

        $proposed = DBVC_Snapshot_Manager::read_snapshot($proposal_id, $uid);
        $proposed['post_title'] = 'Proposed mask refresh title';
        $manifest = $this->base_manifest($proposal_id, [[
            'item_type'     => 'post',
            'post_id'       => $post_id,
            'post_type'     => 'page',
            'post_title'    => 'Proposed mask refresh title',
            'vf_object_uid' => $uid,
            'path'          => 'mask-refresh.json',
        ]]);
        $this->write_proposal($proposal_id, $manifest, ['mask-refresh.json' => $proposed]);

        $mask_request = new WP_REST_Request('POST', '/dbvc/v1/proposals/' . $proposal_id . '/masking/apply');
        $mask_request->set_param('proposal_id', $proposal_id);
        $mask_request->set_header('content-type', 'application/json');
        $mask_request->set_body(wp_json_encode([
            'items' => [[
                'vf_object_uid' => $uid,
                'meta_path'     => 'post.post_date',
                'action'        => 'ignore',
            ]],
        ]));
        $mask_response = DBVC_Admin_App::apply_proposal_masking($mask_request);
        $this->assertInstanceOf(WP_REST_Response::class, $mask_response);
        $this->assertSame(1, $mask_response->get_data()['applied']['ignore']);

        $decisions = get_option('dbvc_proposal_decisions', []);
        $decisions[$proposal_id][$uid]['post_title'] = 'accept';
        update_option('dbvc_proposal_decisions', $decisions, false);

        $before = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest);
        $this->assertTrue($before['ready']);
        $this->assertSame(1, $before['counts']['masking']['reviewed']);

        $detail_request = new WP_REST_Request('GET', '/dbvc/v1/proposals/' . $proposal_id . '/entities/' . $uid);
        $detail_request->set_param('proposal_id', $proposal_id);
        $detail_request->set_param('vf_object_uid', $uid);
        $detail = DBVC_Admin_App::get_proposal_entity($detail_request)->get_data();
        $after = DBVC_Admin_App::build_proposal_apply_gates($proposal_id, $manifest);

        $this->assertSame('keep', $detail['decisions']['post_date'] ?? null);
        $this->assertTrue($after['ready']);
        $this->assertSame(1, $after['counts']['masking']['reviewed']);
    }

    public function test_apply_succeeds_after_new_entity_gate_is_resolved(): void
    {
        $proposal_id = 'proposal-diff-ready-new';
        $uid = 'proposal-diff-ready-new-post';
        $manifest = $this->base_manifest($proposal_id, [
            [
                'item_type'     => 'post',
                'post_id'       => 543210,
                'post_type'     => 'page',
                'post_title'    => 'Approved new post',
                'vf_object_uid' => $uid,
                'path'          => 'approved-new.json',
            ],
        ]);
        $this->write_proposal($proposal_id, $manifest, [
            'approved-new.json' => $this->post_payload(543210, $uid, 'Approved new post'),
        ]);
        update_option('dbvc_proposal_decisions', [
            $proposal_id => [
                $uid => [
                    DBVC_NEW_ENTITY_DECISION_KEY => 'accept_new',
                ],
            ],
        ], false);

        $import_called = false;
        $override = static function () use (&$import_called) {
            $import_called = true;
            return [
                'imported' => 1,
                'skipped'  => 0,
                'errors'   => [],
            ];
        };
        add_filter('dbvc_import_backup_override', $override, 10, 4);

        try {
            $request = new WP_REST_Request('POST', '/dbvc/v1/proposals/' . $proposal_id . '/apply');
            $request->set_param('proposal_id', $proposal_id);
            $result = DBVC_Admin_App::apply_proposal($request);
        } finally {
            remove_filter('dbvc_import_backup_override', $override, 10);
        }

        $this->assertTrue($import_called);
        $this->assertInstanceOf(WP_REST_Response::class, $result);
        $this->assertSame(1, $result->get_data()['result']['imported']);
    }

    public function test_import_preserves_decisions_when_required_media_download_fails(): void
    {
        $proposal_id = 'proposal-diff-media-failure';
        $uid = 'proposal-diff-media-failure-post';
        $original_id = 987601;
        $manifest = $this->base_manifest($proposal_id, [
            [
                'item_type'     => 'post',
                'post_id'       => 987600,
                'post_type'     => 'page',
                'post_title'    => 'Applied before media failure',
                'vf_object_uid' => $uid,
                'path'          => 'media-failure.json',
            ],
        ]);
        $manifest['media_index'] = [[
            'original_id'   => $original_id,
            'asset_uid'     => 'proposal-diff-media-failure-asset',
            'filename'      => 'required-but-missing.txt',
            'relative_path' => 'proposal-diff/required-but-missing.txt',
            'bundle_path'   => 'proposal-diff/required-but-missing.txt',
            'source_url'    => '',
            'mime_type'     => 'text/plain',
        ]];
        $manifest['totals']['media_items'] = 1;
        $this->write_proposal($proposal_id, $manifest, [
            'media-failure.json' => $this->post_payload(987600, $uid, 'Applied before media failure'),
        ]);

        update_option('dbvc_proposal_decisions', [
            $proposal_id => [
                $uid => [DBVC_NEW_ENTITY_DECISION_KEY => 'accept_new'],
            ],
        ], false);
        update_option('dbvc_resolver_decisions', [
            $proposal_id => [
                (string) $original_id => ['action' => 'download'],
            ],
        ], false);
        update_option('dbvc_auto_clear_decisions', '1');
        update_option('dbvc_allow_new_posts', '1');
        update_option(DBVC_Media_Sync::OPTION_ENABLED, '1');
        update_option(DBVC_Media_Sync::OPTION_ALLOW_EXTERNAL, '0');
        update_option(DBVC_Media_Sync::OPTION_TRANSPORT_MODE, 'bundled');

        try {
            $result = DBVC_Sync_Posts::import_backup($proposal_id, ['mode' => 'partial']);

            $this->assertIsArray($result);
            $this->assertSame(1, $result['imported'] ?? null);
            $this->assertFalse($result['outcome']['success'] ?? true);
            $this->assertSame('partial', $result['outcome']['status'] ?? '');
            $this->assertGreaterThan(0, $result['outcome']['counts']['media_failed'] ?? 0);
            $this->assertNotEmpty($result['errors'] ?? []);

            $decision_store = get_option('dbvc_proposal_decisions', []);
            $this->assertArrayHasKey($proposal_id, $decision_store);
            $this->assertSame(
                'accept_new',
                $decision_store[$proposal_id][$uid][DBVC_NEW_ENTITY_DECISION_KEY] ?? null
            );
        } finally {
            DBVC_Media_Sync::cleanup();
            delete_option(DBVC_Media_Sync::OPTION_ENABLED);
            delete_option(DBVC_Media_Sync::OPTION_ALLOW_EXTERNAL);
            delete_option(DBVC_Media_Sync::OPTION_TRANSPORT_MODE);
            delete_option('dbvc_allow_new_posts');
        }
    }

    public function test_apply_returns_structured_failure_and_restores_decisions_after_reconcile_exception(): void
    {
        $proposal_id = 'proposal-diff-reconcile-exception';
        $uid = 'proposal-diff-reconcile-exception-post';
        $manifest = $this->base_manifest($proposal_id, [
            [
                'item_type'     => 'post',
                'post_id'       => 987610,
                'post_type'     => 'page',
                'post_title'    => 'Reconcile exception',
                'vf_object_uid' => $uid,
                'path'          => 'reconcile-exception.json',
            ],
        ]);
        $this->write_proposal($proposal_id, $manifest, [
            'reconcile-exception.json' => $this->post_payload(987610, $uid, 'Reconcile exception'),
        ]);
        update_option('dbvc_proposal_decisions', [
            $proposal_id => [
                $uid => [DBVC_NEW_ENTITY_DECISION_KEY => 'accept_new'],
            ],
        ], false);
        update_option('dbvc_auto_clear_decisions', '1');

        $override = static function () use ($proposal_id) {
            $store = get_option('dbvc_proposal_decisions', []);
            unset($store[$proposal_id]);
            update_option('dbvc_proposal_decisions', $store, false);

            return [
                'imported' => 0,
                'skipped'  => 0,
                'errors'   => ['Media reconciliation stopped unexpectedly.'],
                'outcome'  => [
                    'success' => false,
                    'status'  => 'failed',
                    'errors'  => [[
                        'domain'  => 'media_reconcile',
                        'code'    => 'media_reconcile_exception',
                        'message' => 'Media reconciliation stopped unexpectedly.',
                    ]],
                    'counts' => [
                        'entity_failed' => 0,
                        'media_failed'  => 1,
                    ],
                ],
            ];
        };
        add_filter('dbvc_import_backup_override', $override, 10, 4);

        try {
            $request = new WP_REST_Request('POST', '/dbvc/v1/proposals/' . $proposal_id . '/apply');
            $request->set_param('proposal_id', $proposal_id);
            $result = DBVC_Admin_App::apply_proposal($request);
        } finally {
            remove_filter('dbvc_import_backup_override', $override, 10);
        }

        $this->assertWPError($result);
        $this->assertSame('dbvc_proposal_apply_failed', $result->get_error_code());
        $error_data = $result->get_error_data();
        $this->assertSame(409, $error_data['status'] ?? null);
        $this->assertSame('draft', $error_data['proposal_status'] ?? null);
        $this->assertSame(
            'media_reconcile_exception',
            $error_data['outcome']['errors'][0]['code'] ?? null
        );
        $this->assertCount(1, $error_data['outcome']['errors'] ?? []);
        $this->assertSame(0, $error_data['outcome']['counts']['entity_failed'] ?? null);
        $this->assertSame(1, $error_data['outcome']['counts']['media_failed'] ?? null);

        $decision_store = get_option('dbvc_proposal_decisions', []);
        $this->assertSame(
            'accept_new',
            $decision_store[$proposal_id][$uid][DBVC_NEW_ENTITY_DECISION_KEY] ?? null
        );

        $stored_manifest = json_decode(
            (string) file_get_contents($this->get_proposal_dir($proposal_id) . '/manifest.json'),
            true
        );
        $this->assertSame('draft', $stored_manifest['status'] ?? null);
    }

    public function test_delete_proposal_removes_only_its_ingested_media_bundle(): void
    {
        $proposal_id = 'proposal-diff-delete-bundle';
        $neighbor_id = 'proposal-diff-delete-bundle-neighbor';
        $this->write_proposal($proposal_id, $this->base_manifest($proposal_id, []), []);
        $this->write_proposal($neighbor_id, $this->base_manifest($neighbor_id, []), []);

        $proposal_dir = $this->get_proposal_dir($proposal_id);
        $neighbor_dir = $this->get_proposal_dir($neighbor_id);
        wp_mkdir_p($proposal_dir . '/media-bundle');
        wp_mkdir_p($neighbor_dir . '/media-bundle');
        file_put_contents($proposal_dir . '/media-bundle/proposal.txt', 'proposal bundle');
        file_put_contents($neighbor_dir . '/media-bundle/neighbor.txt', 'neighbor bundle');

        $proposal_bundle = \Dbvc\Media\BundleManager::ingest_from_backup($proposal_id, $proposal_dir);
        $neighbor_bundle = \Dbvc\Media\BundleManager::ingest_from_backup($neighbor_id, $neighbor_dir);
        $this->assertNotNull($proposal_bundle);
        $this->assertNotNull($neighbor_bundle);

        try {
            $request = new WP_REST_Request('DELETE', '/dbvc/v1/proposals/' . $proposal_id);
            $request->set_param('proposal_id', $proposal_id);
            $response = DBVC_Admin_App::delete_proposal($request);

            $this->assertInstanceOf(WP_REST_Response::class, $response);
            $this->assertTrue($response->get_data()['deleted'] ?? false);
            $this->assertTrue($response->get_data()['media_bundle_deleted'] ?? false);
            $this->assertNull(\Dbvc\Media\BundleManager::get_proposal_directory($proposal_id));
            $this->assertNotNull(\Dbvc\Media\BundleManager::get_proposal_directory($neighbor_id));
            $this->assertFileExists($neighbor_bundle . '/neighbor.txt');
        } finally {
            if (is_string($proposal_bundle)) {
                $this->delete_directory($proposal_bundle);
            }
            if (is_string($neighbor_bundle)) {
                $this->delete_directory($neighbor_bundle);
            }
        }
    }

    public function test_classic_importer_does_not_inherit_proposal_apply_gate(): void
    {
        $proposal_id = 'proposal-diff-classic-boundary';
        $uid = 'proposal-diff-classic-new';
        $item = [
            'item_type'     => 'post',
            'post_id'       => 432109,
            'post_type'     => 'page',
            'post_title'    => 'Classic boundary post',
            'vf_object_uid' => $uid,
        ];
        $manifest = $this->base_manifest($proposal_id, [
            array_merge($item, ['path' => 'classic-a.json']),
            array_merge($item, ['path' => 'classic-b.json']),
        ]);
        $payload = $this->post_payload(432109, $uid, 'Classic boundary post');
        $this->write_proposal($proposal_id, $manifest, [
            'classic-a.json' => $payload,
            'classic-b.json' => $payload,
        ]);

        $result = DBVC_Sync_Posts::import_backup($proposal_id, ['mode' => 'full']);

        $this->assertIsArray($result);
        $this->assertSame([], $result['errors'] ?? []);
        $this->assertSame(2, $result['skipped'] ?? null);
    }

    private function assert_apply_blocked_by(string $proposal_id, string $category): void
    {
        $import_called = false;
        $override = static function () use (&$import_called) {
            $import_called = true;
            return [
                'imported' => 0,
                'skipped'  => 0,
                'errors'   => [],
            ];
        };
        add_filter('dbvc_import_backup_override', $override, 10, 4);

        try {
            $request = new WP_REST_Request(
                'POST',
                '/dbvc/v1/proposals/' . $proposal_id . '/apply'
            );
            $request->set_param('proposal_id', $proposal_id);
            $result = DBVC_Admin_App::apply_proposal($request);
        } finally {
            remove_filter('dbvc_import_backup_override', $override, 10);
        }

        $this->assertFalse(
            $import_called,
            sprintf('The import pipeline ran while the %s blocker was unresolved.', $category)
        );
        $this->assertWPError($result);
        $this->assertSame('dbvc_proposal_not_ready', $result->get_error_code());

        $error_data = $result->get_error_data();
        $gates = is_array($error_data) && isset($error_data['gates']) && is_array($error_data['gates'])
            ? $error_data['gates']
            : [];
        $blocking = isset($gates['blocking']) && is_array($gates['blocking'])
            ? $gates['blocking']
            : [];
        $categories = [];
        foreach ($blocking as $blocker) {
            if (is_string($blocker)) {
                $categories[] = $blocker;
            } elseif (is_array($blocker) && isset($blocker['category'])) {
                $categories[] = (string) $blocker['category'];
            }
        }

        $this->assertFalse($gates['ready'] ?? true);
        $this->assertContains($category, $categories);
    }

    private function get_blocking_categories(array $gates): array
    {
        $categories = [];
        foreach ((array) ($gates['blocking'] ?? []) as $blocker) {
            if (is_string($blocker)) {
                $categories[] = $blocker;
            } elseif (is_array($blocker) && isset($blocker['category'])) {
                $categories[] = (string) $blocker['category'];
            }
        }
        return $categories;
    }

    private function base_manifest(string $proposal_id, array $items): array
    {
        return [
            'schema_version' => 3,
            'backup_name'    => $proposal_id,
            'status'         => 'draft',
            'generated_at'   => gmdate('c'),
            'items'          => $items,
            'totals'         => [
                'files'               => count($items),
                'media_items'         => 0,
                'missing_import_hash' => 0,
            ],
        ];
    }

    private function post_payload(int $post_id, string $uid, string $title): array
    {
        return [
            'ID'            => $post_id,
            'post_type'     => 'page',
            'post_title'    => $title,
            'post_content'  => '',
            'post_excerpt'  => '',
            'post_status'   => 'publish',
            'post_name'     => sanitize_title($title),
            'vf_object_uid' => $uid,
            'meta'          => [],
            'tax_input'     => [],
        ];
    }

    private function term_payload(
        int $term_id,
        string $uid,
        string $name,
        string $slug
    ): array {
        $payload = [
            'term_id'     => $term_id,
            'taxonomy'    => 'category',
            'name'        => $name,
            'slug'        => $slug,
            'description' => '',
            'parent'      => 0,
            'meta'        => [],
        ];
        if ($uid !== '') {
            $payload['vf_object_uid'] = $uid;
        }
        return $payload;
    }

    private function create_term_masking_proposal(
        string $proposal_id,
        string $uid,
        string $meta_key,
        $local_value,
        $incoming_value
    ): array {
        $slug = sanitize_title($proposal_id);
        $term = self::factory()->term->create_and_get([
            'taxonomy' => 'category',
            'name'     => $proposal_id,
            'slug'     => $slug,
        ]);
        update_term_meta($term->term_id, 'vf_object_uid', $uid);
        update_term_meta($term->term_id, $meta_key, $local_value);
        DBVC_Snapshot_Manager::capture_term_snapshot($proposal_id, $term->term_id, 'category', $uid);

        $payload = [
            'term_id'       => $term->term_id,
            'taxonomy'      => 'category',
            'name'          => $proposal_id,
            'slug'          => $slug,
            'description'   => '',
            'parent'        => 0,
            'vf_object_uid' => $uid,
            'meta'           => [
                $meta_key => [$incoming_value],
            ],
        ];
        $manifest = $this->base_manifest($proposal_id, [[
            'item_type'     => 'term',
            'term_id'       => $term->term_id,
            'term_taxonomy' => 'category',
            'term_slug'     => $slug,
            'vf_object_uid' => $uid,
            'path'          => 'term.json',
        ]]);
        $this->write_proposal($proposal_id, $manifest, ['term.json' => $payload]);

        return [$term, $manifest, $payload];
    }

    private function get_term_masking_path(string $proposal_id, string $uid, string $meta_key): string
    {
        $request = new WP_REST_Request('GET', '/dbvc/v1/proposals/' . $proposal_id . '/masking');
        $request->set_param('proposal_id', $proposal_id);
        $response = DBVC_Admin_App::get_proposal_masking($request);
        $this->assertInstanceOf(WP_REST_Response::class, $response);

        foreach ((array) ($response->get_data()['fields'] ?? []) as $field) {
            if (
                ($field['vf_object_uid'] ?? '') === $uid
                && ($field['meta_key'] ?? '') === $meta_key
            ) {
                return (string) ($field['meta_path'] ?? '');
            }
        }

        $this->fail(sprintf('Term masking path for %s was not returned.', $meta_key));
        return '';
    }

    private function write_proposal(string $proposal_id, array $manifest, array $payloads): void
    {
        $directory = $this->get_proposal_dir($proposal_id);
        $this->delete_directory($directory);
        wp_mkdir_p($directory);

        foreach ($payloads as $relative_path => $payload) {
            $path = trailingslashit($directory) . ltrim($relative_path, '/\\');
            wp_mkdir_p(dirname($path));
            file_put_contents($path, wp_json_encode($payload));
        }

        file_put_contents(
            trailingslashit($directory) . DBVC_Backup_Manager::MANIFEST_FILENAME,
            wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->proposal_ids[] = $proposal_id;
    }

    private function write_temporary_json(array $payload): string
    {
        $file = wp_tempnam('proposal-diff.json');
        file_put_contents($file, wp_json_encode($payload));
        $this->temporary_files[] = $file;
        return $file;
    }

    private function write_temporary_zip(array $entries): string
    {
        $base = wp_tempnam('proposal-diff.zip');
        if (is_file($base)) {
            @unlink($base);
        }
        $file = $base . '.zip';
        $zip = new ZipArchive();
        $opened = $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($opened === true, 'Unable to create temporary proposal ZIP.');

        foreach ($entries as $path => $payload) {
            $encoded = is_string($payload)
                ? $payload
                : wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->assertTrue($zip->addFromString((string) $path, (string) $encoded));
        }
        $zip->close();
        $this->temporary_files[] = $file;

        return $file;
    }

    private function get_proposal_dir(string $proposal_id): string
    {
        return trailingslashit(DBVC_Backup_Manager::get_base_path()) . sanitize_file_name($proposal_id);
    }

    private function delete_directory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($directory);
    }
}
