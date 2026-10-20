<?php

class CoreUploadZipExtractionTest extends WP_UnitTestCase
{
    /** @var string */
    private $sync_dir = '';

    /** @var string */
    private $temp_dir = '';

    public function set_up(): void
    {
        parent::set_up();

        $this->sync_dir = trailingslashit(ABSPATH . 'wp-content/uploads/dbvc-zip-extract-tests-' . wp_generate_password(8, false, false));
        $this->temp_dir = trailingslashit(sys_get_temp_dir()) . 'dbvc-zip-extract-tests-' . wp_generate_password(8, false, false);

        wp_mkdir_p($this->sync_dir);
        wp_mkdir_p($this->temp_dir);
    }

    public function tear_down(): void
    {
        $this->delete_directory($this->sync_dir);
        $this->delete_directory($this->temp_dir);

        parent::tear_down();
    }

    public function test_zip_upload_extraction_cleans_only_its_extract_root(): void
    {
        if (! class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for upload ZIP extraction tests.');
        }

        $sibling_dir = trailingslashit($this->temp_dir) . 'sibling-dir';
        wp_mkdir_p($sibling_dir);
        file_put_contents(trailingslashit($sibling_dir) . 'keep.txt', 'keep');

        $zip_path = trailingslashit($this->temp_dir) . 'fixture.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $this->assertTrue($zip->addFromString('page-fixture.json', '{"ok":true}'));
        $zip->close();

        $tmp_file = trailingslashit($this->temp_dir) . 'dbvc-temp-base';
        file_put_contents($tmp_file, 'base');

        $method = new ReflectionMethod('DBVC_Sync_Posts', 'extract_upload_zip_to_sync_dir');
        $method->setAccessible(true);
        $result = $method->invoke(null, $zip_path, $this->sync_dir, $tmp_file);

        $this->assertIsArray($result);
        $this->assertTrue((bool) ($result['success'] ?? false));
        $this->assertSame('', (string) ($result['failure_reason'] ?? ''));
        $this->assertFileExists(trailingslashit($this->sync_dir) . 'page-fixture.json');
        $this->assertFileExists(trailingslashit($sibling_dir) . 'keep.txt');
        $this->assertFalse(file_exists($tmp_file), 'Expected the per-upload temp base file to be removed.');
        $this->assertFalse(is_dir($tmp_file . '-extract'), 'Expected the per-upload extraction root to be removed.');
    }

    private function delete_directory(string $path): void
    {
        $path = rtrim($path, '/');
        if ($path === '' || ! file_exists($path)) {
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
