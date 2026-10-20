<?php

/**
 * @group proposal-diff
 */
class ImportRouterRegressionTest extends WP_UnitTestCase
{
    private $sync_dir;
    private $temporary_files = [];

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option('dbvc_export_filename_format', 'id');
        update_option('dbvc_taxonomy_filename_format', 'id');

        $placeholder = wp_tempnam('dbvc-router');
        @unlink($placeholder);
        $this->sync_dir = $placeholder;
        wp_mkdir_p($this->sync_dir);
    }

    public function tear_down(): void
    {
        foreach ($this->temporary_files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->delete_directory($this->sync_dir);
        delete_option('dbvc_export_filename_format');
        delete_option('dbvc_taxonomy_filename_format');
        parent::tear_down();
    }

    public function test_routes_post_term_options_and_menu_json_without_zip_intake(): void
    {
        $files = [
            $this->upload('post-source.json', [
                'ID'           => 101,
                'post_type'    => 'page',
                'post_title'   => 'Router post',
                'post_name'    => 'router-post',
                'post_content' => '',
                'meta'         => [
                    'dbvc_post_history' => [['status' => 'imported']],
                    'visible_key'       => ['visible'],
                ],
                'tax_input'    => [],
            ]),
            $this->upload('term-source.json', [
                'term_id'     => 202,
                'taxonomy'    => 'category',
                'name'        => 'Router term',
                'slug'        => 'router-term',
                'description' => '',
                'meta'        => [],
            ]),
            $this->upload('options.json', [
                'site_title' => 'Router options',
            ]),
            $this->upload('menus.json', [
                'menus' => [],
            ]),
        ];

        $stats = DBVC_Import_Router::route_uploaded_json($files, [
            'sync_dir'         => $this->sync_dir,
            'overwrite'        => true,
            'generate_manifest'=> false,
            'dry_run'          => false,
            'strip_history'    => true,
            'log_enabled'      => false,
        ]);

        $this->assertSame(4, $stats['processed']);
        $this->assertSame(4, $stats['routed']);
        $this->assertSame(0, $stats['skipped']);
        $this->assertSame(0, $stats['errors']);

        $details = [];
        foreach ($stats['details'] as $detail) {
            $details[$detail['scenario']] = $detail;
        }
        $scenario_keys = array_keys($details);
        sort($scenario_keys);
        $this->assertSame(['menus', 'options', 'post', 'term'], $scenario_keys);

        foreach ($details as $detail) {
            $this->assertSame('routed', $detail['status']);
            $this->assertFileExists($detail['path']);
            $this->assertStringStartsWith(trailingslashit($this->sync_dir), $detail['path']);
        }

        $post_payload = json_decode(file_get_contents($details['post']['path']), true);
        $term_payload = json_decode(file_get_contents($details['term']['path']), true);
        $this->assertArrayNotHasKey('dbvc_post_history', $post_payload['meta']);
        $this->assertNotEmpty($term_payload['vf_object_uid']);
        $this->assertNotEmpty($term_payload['meta']['dbvc_term_history']);

        DBVC_Backup_Manager::generate_manifest($this->sync_dir);
        $manifest = DBVC_Backup_Manager::read_manifest($this->sync_dir);
        $this->assertIsArray($manifest);
        $this->assertFileExists(trailingslashit($this->sync_dir) . 'entities.jsonl');
        $this->assertSame(
            ['menus', 'options', 'post', 'term'],
            $this->manifest_item_types($manifest)
        );
    }

    public function test_normalizes_multi_file_uploads_and_preserves_overwrite_controls(): void
    {
        $first = $this->upload('first.json', [
            'ID'         => 301,
            'post_type'  => 'page',
            'post_title' => 'First version',
            'post_name'  => 'first-version',
            'meta'       => [],
            'tax_input'  => [],
        ]);
        $second = $this->upload('second.json', [
            'ID'         => 302,
            'post_type'  => 'page',
            'post_title' => 'Dry run only',
            'post_name'  => 'dry-run-only',
            'meta'       => [],
            'tax_input'  => [],
        ]);
        $normalized = DBVC_Import_Router::normalize_uploads([
            'name'     => [$first['name'], $second['name']],
            'type'     => [$first['type'], $second['type']],
            'tmp_name' => [$first['tmp_name'], $second['tmp_name']],
            'error'    => [$first['error'], $second['error']],
            'size'     => [$first['size'], $second['size']],
        ]);
        $this->assertCount(2, $normalized);

        $initial = DBVC_Import_Router::route_uploaded_json([$first], [
            'sync_dir'  => $this->sync_dir,
            'overwrite' => true,
        ]);
        $this->assertSame(1, $initial['routed']);
        $output_path = $initial['details'][0]['path'];
        $initial_payload = json_decode(file_get_contents($output_path), true);

        file_put_contents($first['tmp_name'], wp_json_encode(array_merge(
            json_decode(file_get_contents($first['tmp_name']), true),
            ['post_title' => 'Blocked overwrite']
        )));
        $blocked = DBVC_Import_Router::route_uploaded_json([$first], [
            'sync_dir'  => $this->sync_dir,
            'overwrite' => false,
        ]);
        $this->assertSame(1, $blocked['skipped']);
        $this->assertSame($initial_payload, json_decode(file_get_contents($output_path), true));

        $dry_run = DBVC_Import_Router::route_uploaded_json([$second], [
            'sync_dir'  => $this->sync_dir,
            'overwrite' => true,
            'dry_run'   => true,
        ]);
        $this->assertSame(1, $dry_run['routed']);
        $this->assertFileDoesNotExist($dry_run['details'][0]['path']);
    }

    private function upload(string $name, array $payload): array
    {
        $file = wp_tempnam($name);
        file_put_contents($file, wp_json_encode($payload));
        $this->temporary_files[] = $file;

        return [
            'name'     => $name,
            'type'     => 'application/json',
            'tmp_name' => $file,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($file),
        ];
    }

    private function manifest_item_types(array $manifest): array
    {
        $types = [];
        foreach ($manifest['items'] ?? [] as $item) {
            if (isset($item['item_type'])) {
                $types[] = (string) $item['item_type'];
            }
        }
        sort($types);
        return $types;
    }

    private function delete_directory(?string $directory): void
    {
        if (! is_string($directory) || ! is_dir($directory)) {
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
