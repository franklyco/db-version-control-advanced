<?php

use Dbvc\AiPackage\Storage as AiPackageStorage;
use Dbvc\ConfigurationPortability\Storage as PortabilityStorage;

/**
 * Security finding S1, uploads storage trees.
 *
 * #51 moved backups, snapshots, bundles, packets and the log beside the
 * absolute sync root, but `uploads/dbvc/` (AI packages and intake,
 * configuration and Bricks portability, official collections) and the
 * content collector's `uploads/contentcollector/` stayed in the web root.
 * Configuration portability backups copy whole option values, API keys
 * included, and nginx ignores the folders' .htaccess. With the absolute root
 * active they now live in `<absolute root>-artifacts/uploads/`, and
 * `migrate()` / `remove_legacy()` move existing data.
 *
 * The tests use the filters because a constant cannot be undefined between
 * tests. Both feed the same validators.
 */
class PrivateUploadsStorageLocationTest extends WP_UnitTestCase
{
    private const SECRET = 'dbvc-s1-uploads-storage-secret-value';

    /** @var string */
    private $private_parent = '';

    /** @var string */
    private $absolute_root = '';

    /** @var string */
    private $artifact_root = '';

    /** @var string */
    private $suffix = '';

    /** @var string Isolated uploads folder, so legacy counts see only this test's files. */
    private $uploads_dir = '';

    /** @var array<string,mixed> */
    private $cc_settings_before = [];

    /** @var array<int,string> */
    private $cleanup = [];

    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $this->suffix         = strtolower(wp_generate_password(8, false, false));
        $this->private_parent = wp_normalize_path(dirname(untrailingslashit(ABSPATH)) . '/dbvc-private-' . $this->suffix);
        $this->absolute_root  = $this->private_parent . '/dbvc-sync';
        $this->artifact_root  = $this->absolute_root . '-artifacts';
        $this->cleanup[]      = $this->private_parent;

        $this->uploads_dir = wp_normalize_path(trailingslashit(ABSPATH) . 'wp-content/uploads/dbvc-s1-store-' . $this->suffix);
        $this->cleanup[]   = $this->uploads_dir;
        wp_mkdir_p($this->uploads_dir);
        add_filter('upload_dir', [$this, 'isolate_uploads']);

        if (class_exists('DBVC_CC_Settings_Service') && class_exists('DBVC_CC_Contracts')) {
            $this->cc_settings_before = DBVC_CC_Settings_Service::get_options();
            $settings                 = $this->cc_settings_before;
            $settings['storage_path'] = DBVC_CC_Contracts::STORAGE_DEFAULT_PATH;
            update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $settings);
        }

        $this->reset_location_caches();
    }

    /**
     * @param array $uploads
     * @return array
     */
    public function isolate_uploads($uploads)
    {
        $uploads['basedir'] = $this->uploads_dir;
        $uploads['path']    = $this->uploads_dir;
        $uploads['baseurl'] = content_url('/uploads/dbvc-s1-store-' . $this->suffix);
        $uploads['error']   = false;
        return $uploads;
    }

    public function tear_down(): void
    {
        remove_filter('upload_dir', [$this, 'isolate_uploads']);
        remove_all_filters('dbvc_sync_path_absolute');
        remove_all_filters('dbvc_private_artifacts_root');
        if (! empty($this->cc_settings_before) && class_exists('DBVC_CC_Contracts')) {
            update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $this->cc_settings_before);
        }
        foreach ($this->cleanup as $path) {
            $this->delete_path($path);
        }
        $this->reset_location_caches();

        parent::tear_down();
    }

    public function test_without_the_absolute_root_uploads_storage_paths_do_not_change(): void
    {
        $this->assertSame($this->uploads_dir, AiPackageStorage::get_uploads_base_dir());
        $this->assertSame($this->uploads_dir . '/dbvc', AiPackageStorage::get_dbvc_root());
        $this->assertSame($this->uploads_dir . '/dbvc/official', wp_normalize_path((string) $this->call_private('\Dbvc\Official\Collections', 'get_storage_base', [])));
        if (class_exists('DBVC_CC_Artifact_Manager')) {
            $this->assertSame($this->uploads_dir . '/contentcollector', wp_normalize_path(DBVC_CC_Artifact_Manager::get_storage_base_dir()));
        }
    }

    public function test_uploads_storage_moves_beside_the_absolute_root(): void
    {
        $this->use_absolute_root($this->absolute_root);
        $private_uploads = $this->artifact_root . '/uploads';

        $this->assertSame($private_uploads . '/dbvc', AiPackageStorage::get_dbvc_root());

        $roots = AiPackageStorage::ensure_base_roots();
        $this->assertIsArray($roots);
        $this->assertSame($private_uploads . '/dbvc/ai-intake', $roots['intake_root']);
        $this->assertSame($private_uploads . '/dbvc/ai-sample-packages', $roots['sample_packages_root']);

        $portability = PortabilityStorage::ensure_roots();
        $this->assertIsArray($portability);
        $this->assertSame($private_uploads . '/dbvc/dbvc-config-portability/backups', $portability['backups']);

        $official = wp_normalize_path((string) $this->call_private('\Dbvc\Official\Collections', 'get_storage_base', []));
        $this->assertSame($private_uploads . '/dbvc/official', $official);

        $dirs = [$roots['intake_root'], $roots['sample_packages_root'], $portability['backups'], $official];
        if (class_exists('DBVC_Bricks_Portability_Storage')) {
            $bricks = DBVC_Bricks_Portability_Storage::ensure_roots();
            $this->assertIsArray($bricks);
            $this->assertSame($private_uploads . '/dbvc/bricks-portability/backups', $bricks['backups']);
            $dirs[] = $bricks['backups'];
        }
        foreach ($dirs as $dir) {
            $this->assertDirectoryExists($dir);
            $this->assertFalse(DBVC_Sync_Location::is_web_reachable($dir), $dir . ' is outside every web root');
        }

        $this->assertDirectoryDoesNotExist($this->uploads_dir . '/dbvc', 'Nothing is created under uploads/dbvc.');
    }

    public function test_content_collector_storage_moves_and_reports_no_url(): void
    {
        if (! class_exists('DBVC_CC_Artifact_Manager')) {
            $this->markTestSkipped('Content collector add-on is not loaded.');
        }
        $this->use_absolute_root($this->absolute_root);

        $this->assertSame($this->artifact_root . '/uploads/contentcollector', wp_normalize_path(DBVC_CC_Artifact_Manager::get_storage_base_dir()));

        $probe = dbvc_cc_storage_http_exposure_probe();
        $this->assertFalse($probe['exposed']);
        $this->assertSame('outside_web_root', $probe['reason']);
        $this->assertSame('', $probe['url'], 'No loopback request is made for private storage.');
    }

    public function test_portability_backup_with_secrets_is_written_outside_the_web_root(): void
    {
        $this->use_absolute_root($this->absolute_root);

        $backup_dir = PortabilityStorage::resolve_backup_directory('dbvc-s1-' . $this->suffix);
        $this->assertIsString($backup_dir);
        $written = PortabilityStorage::write_json_file($backup_dir, 'backup.json', ['value' => ['providers' => ['api_key' => self::SECRET]]]);
        $this->assertIsString($written);

        $this->assertStringStartsWith($this->artifact_root . '/uploads/dbvc/dbvc-config-portability/backups/', wp_normalize_path($written));
        $this->assertFalse(DBVC_Sync_Location::is_web_reachable($written));
        $this->assertSame([], $this->files_containing($this->uploads_dir, self::SECRET), 'No copy of the secret is under uploads.');
    }

    public function test_official_collection_paths_stay_relative_to_the_uploads_base(): void
    {
        $this->use_absolute_root($this->absolute_root);

        $dir = wp_normalize_path((string) $this->call_private('\Dbvc\Official\Collections', 'ensure_collection_directory', [7]));
        $this->assertSame($this->artifact_root . '/uploads/dbvc/official/collection-7', $dir);

        $relative = $this->call_private('\Dbvc\Official\Collections', 'write_snapshot_file', [$dir . '/entities', 'uid-1', ['title' => 'x']]);
        $this->assertSame('dbvc/official/collection-7/entities/uid-1.json', $relative, 'Recorded paths are the same in both layouts.');
    }

    public function test_migration_moves_uploads_storage_and_removal_keeps_the_uploads_folder(): void
    {
        $seed = $this->seed_legacy_storage();
        $this->use_absolute_root($this->absolute_root);

        $preview = DBVC_Artifact_Location::migrate(true);
        $this->assertIsArray($preview);
        $areas = array_column($preview['areas'], null, 'area');
        $this->assertArrayHasKey('dbvc_storage', $areas);
        $this->assertSame(['ai-intake', 'dbvc-config-portability'], $areas['dbvc_storage']['entries']);
        $this->assertStringNotContainsString(self::SECRET, wp_json_encode($preview), 'Reports carry names and counts only.');
        $this->assertDirectoryDoesNotExist($this->artifact_root . '/uploads', 'A preview writes nothing.');
        if (class_exists('DBVC_CC_Artifact_Manager')) {
            $this->assertArrayHasKey('content_collector', $areas);
            $this->assertSame(['example.com'], $areas['content_collector']['entries']);
        }

        $result = DBVC_Artifact_Location::migrate(false);
        $this->assertTrue($result['complete']);
        foreach ($seed['files'] as $relative => $contents) {
            $this->assertSame($contents, file_get_contents($this->artifact_root . '/uploads/' . $relative), $relative . ' was copied');
            $this->assertFileExists($this->uploads_dir . '/' . $relative, 'The copy leaves the original.');
        }
        $this->assertFileExists($this->artifact_root . '/uploads/.htaccess', 'The private uploads mirror gets deny files.');

        $backup = PortabilityStorage::read_json_file(PortabilityStorage::resolve_backup_directory('cfg-backup-1') . '/backup.json');
        $this->assertSame(self::SECRET, $backup['value']['providers']['api_key'] ?? null, 'The storage API reads the migrated copy.');

        // A differing private copy is a conflict and its original stays.
        file_put_contents($this->artifact_root . '/uploads/dbvc/ai-intake/intake-1/report.json', '{"changed":true}');

        $removal = DBVC_Artifact_Location::remove_legacy(false);
        $this->assertIsArray($removal);
        $this->assertFileExists($this->uploads_dir . '/dbvc/ai-intake/intake-1/report.json', 'An original without an identical copy is kept.');
        $this->assertFileDoesNotExist($this->uploads_dir . '/dbvc/dbvc-config-portability/backups/cfg-backup-1/backup.json');
        $this->assertSame([], $this->files_containing($this->uploads_dir, self::SECRET), 'The secret no longer sits under uploads.');

        $this->assertDirectoryExists($this->uploads_dir, 'The uploads folder is never removed.');
        foreach ($seed['unrelated'] as $relative => $contents) {
            $this->assertSame($contents, file_get_contents($this->uploads_dir . '/' . $relative), $relative . ' is not DBVC storage');
        }
    }

    public function test_site_health_flags_uploads_storage_without_the_absolute_root(): void
    {
        $this->seed_legacy_storage();

        $result = DBVC_Artifact_Location::site_health_test();
        $this->assertSame('recommended', $result['status'], 'Portability backups in uploads/dbvc are web-reachable.');
        $this->assertStringContainsString($this->uploads_dir . '/dbvc', $result['description']);
        $this->assertStringContainsString('location ^~ /wp-content/uploads/dbvc-s1-store-' . $this->suffix . '/dbvc/ {', html_entity_decode($result['actions']));

        $this->use_absolute_root($this->absolute_root);
        $this->assertSame('recommended', DBVC_Artifact_Location::site_health_test()['status'], 'Unmigrated uploads storage is still reported.');

        DBVC_Artifact_Location::migrate(false);
        DBVC_Artifact_Location::remove_legacy(false);
        $this->assertSame('good', DBVC_Artifact_Location::site_health_test()['status']);
    }

    public function test_site_health_ignores_folders_that_hold_only_deny_files(): void
    {
        wp_mkdir_p($this->uploads_dir . '/dbvc/ai-intake');
        DBVC_Sync_Posts::ensure_directory_security($this->uploads_dir . '/dbvc');
        DBVC_Sync_Posts::ensure_directory_security($this->uploads_dir . '/dbvc/ai-intake');

        $this->assertSame('good', DBVC_Artifact_Location::site_health_test()['status']);
    }

    /**
     * @return array{files:array<string,string>,unrelated:array<string,string>}
     */
    private function seed_legacy_storage(): array
    {
        $files = [
            'dbvc/dbvc-config-portability/backups/cfg-backup-1/backup.json' => wp_json_encode(['value' => ['providers' => ['api_key' => self::SECRET]]]),
            'dbvc/ai-intake/intake-1/report.json'                           => '{"status":"ok"}',
        ];
        if (class_exists('DBVC_CC_Artifact_Manager')) {
            $files['contentcollector/example.com/home/home.json'] = '{"url":"https://example.com/"}';
        }
        $unrelated = [
            '2026/10/photo.jpg' => 'jpeg-bytes',
            'dbvcx/keep.txt'    => 'a sibling whose name starts with dbvc',
        ];
        foreach (array_merge($files, $unrelated) as $relative => $contents) {
            $path = $this->uploads_dir . '/' . $relative;
            wp_mkdir_p(dirname($path));
            file_put_contents($path, $contents);
        }
        return ['files' => $files, 'unrelated' => $unrelated];
    }

    /**
     * @return array<int,string>
     */
    private function files_containing(string $dir, string $needle): array
    {
        $hits = [];
        if (! is_dir($dir)) {
            return $hits;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if ($item->isFile() && strpos((string) file_get_contents($item->getPathname()), $needle) !== false) {
                $hits[] = wp_normalize_path($item->getPathname());
            }
        }
        return $hits;
    }

    private function use_absolute_root(string $path): void
    {
        remove_all_filters('dbvc_sync_path_absolute');
        add_filter('dbvc_sync_path_absolute', static function () use ($path) {
            return $path;
        });
        $this->reset_location_caches();
    }

    private function reset_location_caches(): void
    {
        if (class_exists('DBVC_Sync_Location')) {
            DBVC_Sync_Location::reset_cache();
        }
        if (class_exists('DBVC_Artifact_Location')) {
            DBVC_Artifact_Location::reset_cache();
        }
    }

    /**
     * @param class-string $class
     * @param string       $method
     * @param array        $args
     * @return mixed
     */
    private function call_private(string $class, string $method, array $args)
    {
        $reflection = new ReflectionMethod($class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $args);
    }

    private function delete_path(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);
            return;
        }
        if (! is_dir($path)) {
            if (file_exists($path)) {
                @unlink($path);
            }
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isLink() || ! $item->isDir()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
