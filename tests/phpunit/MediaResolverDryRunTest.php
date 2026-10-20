<?php

/**
 * Resolver dry-run mutation barriers.
 *
 * @group proposal-diff
 * @group media
 */
class MediaResolverDryRunTest extends WP_UnitTestCase
{
    private $attachment_ids = [];
    private $temporary_upload_root = '';

    public function tear_down(): void
    {
        foreach ($this->attachment_ids as $attachment_id) {
            if (get_post($attachment_id)) {
                wp_delete_attachment($attachment_id, true);
            }
        }

        remove_filter('upload_dir', [$this, 'filter_upload_directory']);
        if ($this->temporary_upload_root !== '') {
            $this->delete_directory($this->temporary_upload_root);
        }

        parent::tear_down();
    }

    public function test_hash_match_dry_run_does_not_backfill_asset_uid(): void
    {
        $attachment_id = $this->create_attachment('resolver-hash-dry-run');
        $file_hash = hash('sha256', 'resolver-hash-dry-run');
        update_post_meta($attachment_id, 'vf_file_hash', $file_hash);

        $descriptor = [
            'asset_uid' => 'asset-dry-run-hash',
            'file_hash' => $file_hash,
            'path' => '',
            'filename' => '',
            'source_url' => '',
            'bundle_path' => '',
        ];

        $dry_run = \Dbvc\Media\Resolver::resolve_descriptor($descriptor, ['dry_run' => true]);

        $this->assertSame('reused', $dry_run['status']);
        $this->assertSame('file_hash', $dry_run['resolved_via']);
        $this->assertSame($attachment_id, $dry_run['target_id']);
        $this->assertSame('', get_post_meta($attachment_id, 'vf_asset_uid', true));

        $apply = \Dbvc\Media\Resolver::resolve_descriptor($descriptor, ['dry_run' => false]);

        $this->assertSame('reused', $apply['status']);
        $this->assertSame('asset-dry-run-hash', get_post_meta($attachment_id, 'vf_asset_uid', true));
    }

    public function test_path_match_dry_run_does_not_backfill_identity_metadata(): void
    {
        $attachment_id = $this->create_attachment('resolver-path-dry-run');
        $relative_path = '2026/08/resolver-path-dry-run.jpg';
        $file_hash = hash('sha256', 'resolver-path-dry-run');
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);

        $descriptor = [
            'asset_uid' => 'asset-dry-run-path',
            'file_hash' => $file_hash,
            'path' => $relative_path,
            'filename' => '',
            'source_url' => '',
            'bundle_path' => '',
        ];

        $dry_run = \Dbvc\Media\Resolver::resolve_descriptor($descriptor, ['dry_run' => true]);

        $this->assertSame('reused', $dry_run['status']);
        $this->assertSame('relative_path', $dry_run['resolved_via']);
        $this->assertSame('', get_post_meta($attachment_id, 'vf_asset_uid', true));
        $this->assertSame('', get_post_meta($attachment_id, 'vf_file_hash', true));

        $apply = \Dbvc\Media\Resolver::resolve_descriptor($descriptor, ['dry_run' => false]);

        $this->assertSame('reused', $apply['status']);
        $this->assertSame('asset-dry-run-path', get_post_meta($attachment_id, 'vf_asset_uid', true));
        $this->assertSame($file_hash, get_post_meta($attachment_id, 'vf_file_hash', true));
    }

    public function test_existing_bundle_lookup_does_not_create_or_harden_storage(): void
    {
        $this->temporary_upload_root = trailingslashit(sys_get_temp_dir()) . 'dbvc-resolver-reader-' . wp_generate_uuid4();
        wp_mkdir_p($this->temporary_upload_root);
        add_filter('upload_dir', [$this, 'filter_upload_directory']);

        $bundle_root = trailingslashit($this->temporary_upload_root) . 'sync/media-bundles';
        $result = \Dbvc\Media\BundleManager::get_proposal_directory('missing-proposal');

        $this->assertNull($result);
        $this->assertDirectoryDoesNotExist($bundle_root);
        $this->assertFileDoesNotExist(trailingslashit($bundle_root) . '.htaccess');
        $this->assertFileDoesNotExist(trailingslashit($bundle_root) . 'index.php');
    }

    public function filter_upload_directory(array $uploads): array
    {
        $uploads['basedir'] = $this->temporary_upload_root;
        $uploads['path'] = $this->temporary_upload_root;
        $uploads['baseurl'] = 'https://example.test/dbvc-resolver-reader';
        $uploads['url'] = $uploads['baseurl'];
        $uploads['subdir'] = '';
        $uploads['error'] = false;
        return $uploads;
    }

    private function create_attachment(string $title): int
    {
        $attachment_id = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => $title,
            'post_mime_type' => 'image/jpeg',
        ]);
        $this->attachment_ids[] = $attachment_id;
        return $attachment_id;
    }

    private function delete_directory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
