<?php

class TransferPacketWorkflowTest extends WP_UnitTestCase
{
    /** @var string */
    private $sync_dir = '';

    /** @var string */
    private $sync_option_before = '';

    public function set_up(): void
    {
        parent::set_up();

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->sync_option_before = (string) get_option('dbvc_sync_path', '');
        $this->sync_dir = trailingslashit(ABSPATH . 'wp-content/uploads/dbvc-transfer-tests-' . wp_generate_password(8, false, false));
        wp_mkdir_p($this->sync_dir);

        $relative = ltrim(str_replace(wp_normalize_path(trailingslashit(ABSPATH)), '', wp_normalize_path($this->sync_dir)), '/');
        update_option('dbvc_sync_path', $relative ?: 'wp-content/uploads');
        delete_transient('dbvc_entity_editor_index_v1');

        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        delete_transient('dbvc_entity_editor_index_v1');
        update_option('dbvc_sync_path', $this->sync_option_before);
        $this->delete_directory($this->sync_dir);
        parent::tear_down();
    }

    public function test_transfer_preview_endpoint_surfaces_unsupported_post_reference_warning(): void
    {
        $referenced_post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Referenced Page',
            'post_name'   => 'referenced-page',
        ]);
        update_post_meta($referenced_post_id, 'vf_object_uid', 'transfer-referenced-page');

        $selected_post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'Selected Page',
            'post_name'   => 'selected-page',
        ]);
        update_post_meta($selected_post_id, 'vf_object_uid', 'transfer-selected-page');
        update_post_meta($selected_post_id, 'selected_posts', [$referenced_post_id]);

        $relative_path = $this->write_sync_json('page/selected-page.json', [
            'ID'            => $selected_post_id,
            'post_type'     => 'page',
            'post_name'     => 'selected-page',
            'post_title'    => 'Selected Page',
            'post_status'   => 'publish',
            'vf_object_uid' => 'transfer-selected-page',
            'meta'          => [
                'selected_posts' => [$referenced_post_id],
            ],
            'tax_input'     => [],
        ]);

        DBVC_Entity_Editor_Indexer::get_index(true);

        $server = rest_get_server();
        $request = new WP_REST_Request('POST', '/dbvc/v1/entity-editor/transfer-preview');
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode([
            'paths' => [$relative_path],
        ]));

        $response = $server->dispatch($request);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertSame(1, $data['warnings']['summary']['unsupported_post_references'] ?? 0);
        $this->assertSame(0, $data['selection']['summary']['missing_dependencies'] ?? 0);

        $warning = $data['warnings']['unsupported_post_references'][0] ?? [];
        $this->assertNotSame('', $warning['source_path'] ?? '');
        $this->assertStringStartsWith('page/', $warning['source_path'] ?? '');
        $this->assertSame('Selected Page', $warning['source_post_title'] ?? '');
        $this->assertSame('selected_posts', $warning['meta_key'] ?? '');
        $this->assertSame($referenced_post_id, $warning['referenced_post_id'] ?? 0);
        $this->assertSame('page', $warning['referenced_post_type'] ?? '');
    }

    public function test_import_proposal_from_zip_rejects_missing_payload_files(): void
    {
        if (! class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for proposal import tests.');
        }

        $manifest = [
            'generated_at' => gmdate('c'),
            'backup_name'  => 'transfer-packet-missing-payload',
            'items'        => [
                [
                    'item_type'     => 'post',
                    'post_id'       => 301,
                    'post_type'     => 'page',
                    'post_title'    => 'Missing Payload',
                    'post_status'   => 'publish',
                    'vf_object_uid' => 'transfer-missing-payload',
                    'path'          => 'page/missing-payload.json',
                ],
            ],
        ];

        $zip_path = $this->create_zip_fixture([
            'manifest.json' => wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);

        $result = DBVC_Admin_App::import_proposal_from_zip($zip_path, [
            'preferred_id' => 'transfer-missing-payload',
        ]);

        $this->assertWPError($result);
        $this->assertSame('dbvc_manifest_file_missing', $result->get_error_code());

        $error_data = $result->get_error_data();
        $this->assertIsArray($error_data);
        $this->assertSame(400, (int) ($error_data['status'] ?? 0));
        $this->assertSame('manifest_file_missing', $error_data['reason'] ?? '');
        $this->assertSame(0, (int) ($error_data['entry_index'] ?? -1));

        @unlink($zip_path);
    }

    public function test_import_proposal_from_zip_rejects_missing_media_bundle_assets(): void
    {
        if (! class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for proposal import tests.');
        }

        $payload = [
            'ID'            => 401,
            'post_type'     => 'page',
            'post_name'     => 'media-bundle-page',
            'post_title'    => 'Media Bundle Page',
            'post_status'   => 'publish',
            'vf_object_uid' => 'transfer-media-bundle-page',
            'meta'          => [],
            'tax_input'     => [],
        ];

        $manifest = [
            'generated_at' => gmdate('c'),
            'backup_name'  => 'transfer-packet-missing-bundle',
            'items'        => [
                [
                    'item_type'     => 'post',
                    'post_id'       => 401,
                    'post_type'     => 'page',
                    'post_title'    => 'Media Bundle Page',
                    'post_status'   => 'publish',
                    'vf_object_uid' => 'transfer-media-bundle-page',
                    'path'          => 'page/media-bundle-page.json',
                ],
            ],
            'media_bundle' => [
                'backup_relative' => 'media-bundle',
                'map'             => 'media-bundle/bundle.json',
            ],
        ];

        $zip_path = $this->create_zip_fixture([
            'manifest.json'               => wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'page/media-bundle-page.json' => wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);

        $result = DBVC_Admin_App::import_proposal_from_zip($zip_path, [
            'preferred_id' => 'transfer-missing-media-bundle',
        ]);

        $this->assertWPError($result);
        $this->assertSame('dbvc_manifest_bundle_invalid', $result->get_error_code());

        $error_data = $result->get_error_data();
        $details = is_array($error_data['details'] ?? null) ? $error_data['details'] : [];
        $this->assertContains('media-bundle', $details['missing_bundle_assets'] ?? []);
        $this->assertContains('media-bundle/bundle.json', $details['missing_bundle_assets'] ?? []);

        @unlink($zip_path);
    }

    /**
     * @param string $relative_path
     * @param array  $payload
     * @return string
     */
    private function write_sync_json(string $relative_path, array $payload): string
    {
        $absolute_path = trailingslashit($this->sync_dir) . ltrim($relative_path, '/');
        wp_mkdir_p(dirname($absolute_path));
        file_put_contents($absolute_path, wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return ltrim(str_replace('\\', '/', $relative_path), '/');
    }

    /**
     * @param array<string,string> $entries
     * @return string
     */
    private function create_zip_fixture(array $entries): string
    {
        $zip_path = wp_tempnam('dbvc-transfer-packet-test');
        $this->assertIsString($zip_path);
        @unlink($zip_path);
        $zip_path .= '.zip';

        $zip = new ZipArchive();
        $open_result = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue(true === $open_result, 'Unable to create ZIP fixture.');

        foreach ($entries as $relative_path => $contents) {
            $zip->addFromString(ltrim(str_replace('\\', '/', $relative_path), '/'), $contents);
        }

        $zip->close();

        return $zip_path;
    }

    private function delete_directory(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($path);
    }
}
