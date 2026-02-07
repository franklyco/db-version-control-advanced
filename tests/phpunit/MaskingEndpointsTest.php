<?php

class MaskingEndpointsTest extends WP_UnitTestCase
{
    private const PROPOSAL_ID = 'masking-proposal';

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->reset_masking_options();
        update_option('dbvc_mask_meta_keys', "_secret_key\n_hidden_blob");
        update_option('dbvc_mask_defaults_meta_keys', '');
        update_option('dbvc_mask_subkeys', '');
        update_option('dbvc_mask_defaults_subkeys', '');
        $this->create_manifest_fixture(6);
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        $this->delete_manifest_fixture();
        $this->reset_masking_options();
        parent::tear_down();
    }

    public function test_masking_endpoint_paginates_and_clamps_chunk_size(): void
    {
        $server = rest_get_server();
        $filter = static function () {
            return 3;
        };
        add_filter('dbvc_masking_chunk_size', $filter);
        $request = new WP_REST_Request('GET', '/dbvc/v1/proposals/' . self::PROPOSAL_ID . '/masking');
        $response = $server->dispatch($request);
        remove_filter('dbvc_masking_chunk_size', $filter);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('chunk', $data);
        $this->assertSame(3, $data['chunk']['per_page']);
        $this->assertTrue($data['chunk']['has_more']);
        $this->assertNotEmpty($data['fields']);

        $request_clamped = new WP_REST_Request('GET', '/dbvc/v1/proposals/' . self::PROPOSAL_ID . '/masking');
        $request_clamped->set_param('per_page', 200);
        $response_clamped = $server->dispatch($request_clamped);
        $this->assertSame(200, $response_clamped->get_status());
        $clamped = $response_clamped->get_data();
        $this->assertSame(50, $clamped['chunk']['per_page']);
    }

    public function test_apply_masking_records_entries_by_path(): void
    {
        $data = $this->applySampleMaskingPayload();
        $this->assertSame(1, $data['applied']['auto_accept']);
        $this->assertSame(1, $data['applied']['override']);

        $suppress_store = get_option('dbvc_masked_field_suppressions');
        $this->assertArrayHasKey(self::PROPOSAL_ID, $suppress_store);
        $entity_suppress = $suppress_store[self::PROPOSAL_ID]['mask-post-1'] ?? [];
        $this->assertArrayHasKey('_secret_key', $entity_suppress);
        $this->assertArrayHasKey('meta._secret_key', $entity_suppress['_secret_key']);

        $override_store = get_option('dbvc_mask_overrides');
        $this->assertArrayHasKey(self::PROPOSAL_ID, $override_store);
        $entity_overrides = $override_store[self::PROPOSAL_ID]['mask-post-1'] ?? [];
        $this->assertArrayHasKey('_hidden_blob', $entity_overrides);
        $this->assertArrayHasKey('meta._hidden_blob.0.label', $entity_overrides['_hidden_blob']);
        $this->assertSame(
            'REDACTED',
            $entity_overrides['_hidden_blob']['meta._hidden_blob.0.label']['value']
        );
    }

    public function test_masking_endpoint_includes_post_fields(): void
    {
        update_option('dbvc_mask_post_fields', ['post_date']);
        $server = rest_get_server();
        $request = new WP_REST_Request('GET', '/dbvc/v1/proposals/' . self::PROPOSAL_ID . '/masking');
        $response = $server->dispatch($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data['fields']);

        $postField = null;
        foreach ($data['fields'] as $field) {
            if ($field['meta_path'] === 'post.post_date') {
                $postField = $field;
                break;
            }
        }

        $this->assertNotNull($postField, 'Expected to receive a post.post_date mask entry.');
        $this->assertSame('mask-post-0', $postField['vf_object_uid']);
        $this->assertSame('post.post_date', $postField['meta_path']);
    }

    public function test_apply_masking_accepts_post_fields(): void
    {
        update_option('dbvc_mask_post_fields', ['post_modified']);
        $server = rest_get_server();
        $payload = [
            'items' => [
                [
                    'vf_object_uid' => 'mask-post-1',
                    'meta_path'     => 'post.post_modified',
                    'action'        => 'auto_accept',
                    'suppress'      => true,
                ],
            ],
        ];

        $request = new WP_REST_Request('POST', '/dbvc/v1/proposals/' . self::PROPOSAL_ID . '/masking/apply');
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode($payload));
        $response = $server->dispatch($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame(1, $data['applied']['auto_accept']);

        $suppress_store = get_option('dbvc_masked_field_suppressions');
        $this->assertArrayHasKey(self::PROPOSAL_ID, $suppress_store);
        $entity_store = $suppress_store[self::PROPOSAL_ID]['mask-post-1'] ?? [];
        $this->assertArrayHasKey('post', $entity_store);
        $this->assertArrayHasKey('post:post_modified', $entity_store['post']);

        $decision_store = get_option('dbvc_proposal_decisions');
        $this->assertSame(
            'accept',
            $decision_store[self::PROPOSAL_ID]['mask-post-1']['post_modified'] ?? ''
        );
    }

    public function test_revert_masking_clears_applied_decisions(): void
    {
        $this->applySampleMaskingPayload();

        $server = rest_get_server();
        $request = new WP_REST_Request('POST', '/dbvc/v1/proposals/' . self::PROPOSAL_ID . '/masking/revert');
        $response = $server->dispatch($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame(2, $data['cleared']['decisions']);
        $this->assertSame(1, $data['cleared']['entities']);

        $decision_store = get_option('dbvc_proposal_decisions');
        $this->assertEmpty($decision_store[self::PROPOSAL_ID]['mask-post-1'] ?? []);

        $suppress_store = (array) get_option('dbvc_masked_field_suppressions');
        $override_store = (array) get_option('dbvc_mask_overrides');
        $this->assertArrayNotHasKey(self::PROPOSAL_ID, $suppress_store);
        $this->assertArrayNotHasKey(self::PROPOSAL_ID, $override_store);
    }

    public function test_revert_clears_post_field_decisions(): void
    {
        update_option('dbvc_mask_post_fields', ['post_modified']);
        $server = rest_get_server();
        $payload = [
            'items' => [
                [
                    'vf_object_uid' => 'mask-post-1',
                    'meta_path'     => 'post.post_modified',
                    'action'        => 'auto_accept',
                    'suppress'      => true,
                ],
            ],
        ];

        $apply = new WP_REST_Request('POST', '/dbvc/v1/proposals/' . self::PROPOSAL_ID . '/masking/apply');
        $apply->set_header('content-type', 'application/json');
        $apply->set_body(wp_json_encode($payload));
        $response = $server->dispatch($apply);
        $this->assertSame(200, $response->get_status());

        $decision_store = get_option('dbvc_proposal_decisions');
        $this->assertSame(
            'accept',
            $decision_store[self::PROPOSAL_ID]['mask-post-1']['post_modified'] ?? ''
        );

        $revert = new WP_REST_Request('POST', '/dbvc/v1/proposals/' . self::PROPOSAL_ID . '/masking/revert');
        $revert_response = $server->dispatch($revert);
        $this->assertSame(200, $revert_response->get_status());

        $decision_store = get_option('dbvc_proposal_decisions');
        $this->assertArrayNotHasKey('post_modified', $decision_store[self::PROPOSAL_ID]['mask-post-1'] ?? []);
    }

    private function applySampleMaskingPayload(): array
    {
        $server = rest_get_server();
        $payload = [
            'items' => [
                [
                    'vf_object_uid' => 'mask-post-1',
                    'meta_path'     => 'meta._secret_key',
                    'action'        => 'auto_accept',
                    'suppress'      => true,
                ],
                [
                    'vf_object_uid'  => 'mask-post-1',
                    'meta_path'      => 'meta._hidden_blob.0.label',
                    'action'         => 'override',
                    'override_value' => 'REDACTED',
                    'note'           => 'testing',
                ],
            ],
        ];

        $request = new WP_REST_Request('POST', '/dbvc/v1/proposals/' . self::PROPOSAL_ID . '/masking/apply');
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode($payload));
        $response = $server->dispatch($request);

        $this->assertSame(200, $response->get_status());
        return $response->get_data();
    }

    public function test_import_post_respects_post_field_masking(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_title'   => 'Import Target',
            'post_status'  => 'publish',
            'post_date'    => '2020-01-01 10:00:00',
            'post_modified'=> '2020-01-02 11:00:00',
        ]);

        $json = [
            'ID'            => $post_id,
            'post_type'     => 'page',
            'post_title'    => 'Import Target',
            'post_status'   => 'publish',
            'post_date'     => '2030-05-01 08:00:00',
            'post_modified' => '2030-05-02 08:30:00',
            'vf_object_uid' => 'mask-post-override',
            'meta'          => [
                '_secret_key' => ['value-before'],
            ],
            'tax_input'     => [],
        ];

        $file = tempnam(sys_get_temp_dir(), 'dbvc');
        file_put_contents($file, wp_json_encode($json));

        $mask_directives = [
            'overrides' => [
                'post' => [
                    'post:post_modified' => [
                        'post.post_modified' => [
                            'path'      => 'post.post_modified',
                            'meta_key'  => 'post:post_modified',
                            'scope'     => 'post',
                            'field_key' => 'post_modified',
                            'value'     => '2026-01-01 00:00:00',
                        ],
                    ],
                ],
            ],
            'suppressions' => [
                'post' => [
                    'post:post_date' => [
                        'post.post_date' => [
                            'path'      => 'post.post_date',
                            'meta_key'  => 'post:post_date',
                            'scope'     => 'post',
                            'field_key' => 'post_date',
                        ],
                    ],
                ],
            ],
        ];

        $result = DBVC_Sync_Posts::import_post_from_json(
            $file,
            false,
            null,
            $json,
            null,
            'mask-post-override',
            false,
            $mask_directives
        );

        @unlink($file);

        $this->assertSame('applied', $result);
        $post = get_post($post_id);
        $this->assertSame('2020-01-01 10:00:00', $post->post_date);
        $this->assertSame('2026-01-01 00:00:00', $post->post_modified);
    }

    private function create_manifest_fixture(int $count): void
    {
        $dir = $this->get_proposal_dir();
        if (is_dir($dir)) {
            $this->delete_manifest_fixture();
        }
        wp_mkdir_p($dir);

        $manifest = ['items' => []];
        for ($i = 0; $i < $count; $i++) {
            $uid  = 'mask-post-' . $i;
            $path = sprintf('post-%d.json', $i);
            $manifest['items'][] = [
                'item_type'    => 'post',
                'vf_object_uid'=> $uid,
                'post_title'   => 'Masked ' . $i,
                'post_status'  => 'draft',
                'post_type'    => 'page',
                'path'         => $path,
            ];

            $payload = [
                'ID'            => $i + 1,
                'post_type'     => 'page',
                'post_title'    => 'Masked ' . $i,
                'post_date'     => '2024-01-0' . ($i + 1) . ' 10:00:00',
                'post_modified' => '2024-01-0' . ($i + 1) . ' 12:00:00',
                'post_status'   => 'draft',
                'vf_object_uid' => $uid,
                'meta'          => [
                    '_secret_key' => ['token-' . $i],
                    '_hidden_blob' => [
                        [
                            'label' => 'Label ' . $i,
                            'value' => 'Value ' . $i,
                        ],
                    ],
                ],
                'tax_input'     => [],
            ];

            file_put_contents(trailingslashit($dir) . $path, wp_json_encode($payload));
        }

        file_put_contents(trailingslashit($dir) . 'manifest.json', wp_json_encode($manifest));
    }

    private function delete_manifest_fixture(): void
    {
        $dir = $this->get_proposal_dir();
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function get_proposal_dir(): string
    {
        $upload_dir = wp_upload_dir();
        $base = trailingslashit($upload_dir['basedir']) . 'sync/db-version-control-backups';
        return trailingslashit($base) . self::PROPOSAL_ID;
    }

    private function reset_masking_options(): void
    {
        delete_option('dbvc_masked_field_suppressions');
        delete_option('dbvc_mask_overrides');
        delete_option('dbvc_proposal_decisions');
        delete_option('dbvc_mask_post_fields');
    }
}
