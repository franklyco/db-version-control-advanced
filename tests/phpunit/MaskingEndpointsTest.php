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
    }
}
