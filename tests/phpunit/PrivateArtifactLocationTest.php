<?php

/**
 * Security finding S1, remaining gap: artifacts under uploads/sync.
 *
 * DBVC_SYNC_PATH_ABSOLUTE moved the sync folder out of the web root, but
 * backups (which copy options.json), proposals, snapshots, media bundles,
 * transfer packets and the log stayed under uploads/sync/ and wp-content/,
 * where nginx serves them. With the absolute root active they now live in
 * `<absolute root>-artifacts/`; existing data moves through a preview,
 * copy-and-verify, then a separate removal of the originals.
 *
 * The tests use the filters because a constant cannot be undefined between
 * tests. Both feed the same validators.
 */
class PrivateArtifactLocationTest extends WP_UnitTestCase
{
    private const SECRET = 'dbvc-s1-artifact-secret-value';

    /** @var string Sibling of the WordPress install, like private_html beside public_html. */
    private $private_parent = '';

    /** @var string */
    private $absolute_root = '';

    /** @var string */
    private $artifact_root = '';

    /** @var string */
    private $suffix = '';

    /** @var string ABSPATH-relative log folder for the legacy log. */
    private $log_relative = '';

    /** @var mixed */
    private $log_dir_before = null;

    /** @var string Isolated uploads folder, so legacy counts see only this test's files. */
    private $uploads_dir = '';

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

        $this->log_dir_before = get_option('dbvc_logging_directory', null);
        $this->log_relative   = 'wp-content/uploads/dbvc-s1-log-' . $this->suffix;
        $this->cleanup[]      = trailingslashit(ABSPATH) . $this->log_relative;
        update_option('dbvc_logging_directory', $this->log_relative);

        $this->uploads_dir = wp_normalize_path(trailingslashit(ABSPATH) . 'wp-content/uploads/dbvc-s1-uploads-' . $this->suffix);
        $this->cleanup[]   = $this->uploads_dir;
        wp_mkdir_p($this->uploads_dir);
        add_filter('upload_dir', [$this, 'isolate_uploads']);

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
        return $uploads;
    }

    public function tear_down(): void
    {
        remove_filter('upload_dir', [$this, 'isolate_uploads']);
        remove_all_filters('dbvc_sync_path_absolute');
        remove_all_filters('dbvc_private_artifacts_root');
        if ($this->log_dir_before === null) {
            delete_option('dbvc_logging_directory');
        } else {
            update_option('dbvc_logging_directory', $this->log_dir_before);
        }
        foreach ($this->cleanup as $path) {
            $this->delete_path($path);
        }
        $this->reset_location_caches();

        parent::tear_down();
    }

    public function test_backups_snapshots_bundles_and_packets_move_beside_the_absolute_root(): void
    {
        $this->use_absolute_root($this->absolute_root);

        $backups   = wp_normalize_path(DBVC_Backup_Manager::get_base_path());
        $snapshots = wp_normalize_path(DBVC_Snapshot_Manager::get_base_path());
        $bundle    = wp_normalize_path((string) $this->call_private('\Dbvc\Media\BundleManager', 'ensure_proposal_directory', ['dbvc-s1-' . $this->suffix]));
        $packets   = $this->call_private('\Dbvc\Transfer\EntityPacketBuilder', 'get_workspace_root', []);

        $this->assertSame($this->artifact_root . '/db-version-control-backups', $backups);
        $this->assertSame($this->artifact_root . '/db-version-control-snapshots', $snapshots);
        $this->assertSame($this->artifact_root . '/media-bundles/dbvc-s1-' . $this->suffix, $bundle);
        $this->assertIsString($packets);
        $this->assertSame($this->artifact_root . '/dbvc-transfer-packets', wp_normalize_path($packets));

        foreach ([$backups, $snapshots, $bundle, $packets] as $dir) {
            $this->assertDirectoryExists($dir);
            $this->assertFalse(DBVC_Sync_Location::is_web_reachable($dir), $dir . ' is outside every web root');
        }
    }

    public function test_new_backup_is_written_outside_the_web_root(): void
    {
        $this->use_absolute_root($this->absolute_root);
        wp_mkdir_p($this->absolute_root);
        file_put_contents($this->absolute_root . '/options.json', wp_json_encode(['blogname' => 'S1 Private']));

        $legacy_backups = $this->legacy_root() . '/db-version-control-backups';
        $before         = glob($legacy_backups . '/*', GLOB_ONLYDIR) ?: [];

        DBVC_Sync_Posts::dbvc_create_backup_folder_and_copy_exports();

        $created = glob($this->artifact_root . '/db-version-control-backups/*', GLOB_ONLYDIR) ?: [];
        $this->assertCount(1, $created, 'The backup was written to the private root.');
        $this->assertFileExists($created[0] . '/options.json');
        $this->assertSame($before, glob($legacy_backups . '/*', GLOB_ONLYDIR) ?: [], 'Nothing new was written under uploads/sync.');
        $this->assertSame(basename($created[0]), DBVC_Backup_Manager::list_backups()[0]['name']);
        $this->assertTrue(dbvc_is_safe_file_path($created[0] . '/options.json'), 'Restores may read JSON from the private root.');
    }

    public function test_log_moves_to_the_private_root_and_ignores_the_web_root_option(): void
    {
        $this->use_absolute_root($this->absolute_root);

        $this->assertSame($this->artifact_root . '/logs', wp_normalize_path(DBVC_Sync_Logger::get_log_directory()));
        $this->assertSame($this->artifact_root . '/logs/dbvc-backup.log', wp_normalize_path(DBVC_Sync_Logger::get_log_file_path()));
        $this->assertSame(
            wp_normalize_path(trailingslashit(ABSPATH) . $this->log_relative),
            wp_normalize_path(DBVC_Sync_Logger::get_legacy_log_directory())
        );
    }

    public function test_without_an_absolute_root_every_location_is_unchanged(): void
    {
        $legacy = $this->legacy_root();

        $this->assertSame($legacy . '/db-version-control-backups', wp_normalize_path(DBVC_Backup_Manager::get_base_path(false)));
        $this->assertSame($legacy . '/db-version-control-snapshots', wp_normalize_path(DBVC_Snapshot_Manager::get_base_path(false)));
        $this->assertSame(wp_normalize_path(trailingslashit(ABSPATH) . $this->log_relative), wp_normalize_path(DBVC_Sync_Logger::get_log_directory()));
        $this->assertSame('', DBVC_Artifact_Location::private_root());
        $this->assertFalse(DBVC_Artifact_Location::is_private());

        delete_option('dbvc_logging_directory');
        $this->assertSame(untrailingslashit(wp_normalize_path(WP_CONTENT_DIR)), wp_normalize_path(DBVC_Sync_Logger::get_log_directory()));
    }

    /**
     * @dataProvider refused_artifact_roots
     */
    public function test_unsafe_artifact_roots_are_refused(string $case, string $expected_code): void
    {
        $this->use_absolute_root($this->absolute_root);
        $candidate = $case === 'inside_sync_root'
            ? $this->absolute_root . '/artifacts'
            : ($case === 'contains_sync_root' ? $this->private_parent : trailingslashit(ABSPATH) . 'wp-content/uploads/dbvc-artifacts');
        add_filter('dbvc_private_artifacts_root', static function () use ($candidate) {
            return $candidate;
        });
        $this->reset_location_caches();

        $refusal = DBVC_Artifact_Location::refusal();
        $this->assertInstanceOf(WP_Error::class, $refusal);
        $this->assertSame($expected_code, $refusal->get_error_code());
        $this->assertSame('', DBVC_Artifact_Location::private_root());
        $this->assertWPError(DBVC_Artifact_Location::migrate(true));
    }

    public function refused_artifact_roots(): array
    {
        return [
            'inside the sync root'   => ['inside_sync_root', 'dbvc_artifacts_overlap_sync'],
            'contains the sync root' => ['contains_sync_root', 'dbvc_artifacts_overlap_sync'],
            'inside the web root'    => ['web_root', 'dbvc_sync_path_web_reachable'],
        ];
    }

    public function test_migration_previews_copies_verifies_and_removes_only_on_a_separate_step(): void
    {
        $seed = $this->seed_legacy_artifacts();
        $this->use_absolute_root($this->absolute_root);

        $preview = DBVC_Artifact_Location::migrate(true);
        $this->assertIsArray($preview);
        $this->assertTrue($preview['dry_run']);
        $this->assertDirectoryDoesNotExist($this->artifact_root, 'The preview writes nothing.');
        $this->assertSame(6, $preview['totals']['files']);
        $this->assertSame(6, $preview['totals']['to_copy']);
        $this->assertStringNotContainsString(self::SECRET, (string) wp_json_encode($preview), 'The report carries names and counts only.');
        $areas = array_column($preview['areas'], null, 'area');
        $this->assertSame([$seed['backup']], $areas['backups']['entries']);
        $this->assertSame(2, $areas['logs']['files'], 'The log and its rotated archive, not the rest of the log folder.');

        $copied = DBVC_Artifact_Location::migrate(false);
        $this->assertSame(6, $copied['totals']['copied']);
        $this->assertSame(6, $copied['totals']['verified']);
        $this->assertTrue($copied['complete']);
        $this->assertFileExists($this->artifact_root . '/db-version-control-backups/' . $seed['backup'] . '/options.json');
        $this->assertFileExists($this->artifact_root . '/logs/legacy/dbvc-backup.log');
        foreach ($seed['files'] as $original) {
            $this->assertFileExists($original, 'The copy never removes originals.');
        }
        $this->assertSame([$seed['backup']], array_column(DBVC_Backup_Manager::list_backups(), 'name'), 'Readers see the migrated backup.');

        $again = DBVC_Artifact_Location::migrate(true);
        $this->assertSame(0, $again['totals']['to_copy'], 'A second run finds every copy verified.');
        $this->assertSame(6, $again['totals']['verified']);

        $removal_preview = DBVC_Artifact_Location::remove_legacy(true);
        $this->assertSame(6, $removal_preview['totals']['to_remove']);
        foreach ($seed['files'] as $original) {
            $this->assertFileExists($original, 'The removal preview deletes nothing.');
        }

        $removed = DBVC_Artifact_Location::remove_legacy(false);
        $this->assertSame(6, $removed['totals']['removed']);
        foreach ($seed['files'] as $original) {
            $this->assertFileDoesNotExist($original);
        }
        $this->assertFileExists($seed['unrelated'], 'Other files in the log folder stay.');
        $this->assertDirectoryExists($this->legacy_root(), 'uploads/sync itself is never removed.');
        $this->assertDirectoryExists($this->artifact_root . '/db-version-control-backups/' . $seed['backup']);
    }

    public function test_conflicting_copies_are_never_overwritten_or_removed(): void
    {
        $seed = $this->seed_legacy_artifacts();
        $this->use_absolute_root($this->absolute_root);

        $target = $this->artifact_root . '/db-version-control-backups/' . $seed['backup'] . '/options.json';
        wp_mkdir_p(dirname($target));
        file_put_contents($target, '{"different":true}');

        $copied = DBVC_Artifact_Location::migrate(false);
        $this->assertSame(1, $copied['totals']['conflicts']);
        $this->assertFalse($copied['complete']);
        $this->assertSame('{"different":true}', file_get_contents($target));

        DBVC_Artifact_Location::remove_legacy(false);
        $this->assertFileExists($seed['files']['backup'], 'An original without an identical copy stays.');
    }

    public function test_migration_skips_symlinks_out_of_the_legacy_folders(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('symlink() is unavailable.');
        }
        $this->seed_legacy_artifacts();
        $outside = $this->private_parent . '/outside.json';
        wp_mkdir_p($this->private_parent);
        file_put_contents($outside, '{"outside":true}');
        $link = $this->legacy_root() . '/media-bundles/dbvc-s1-' . $this->suffix . '/linked.json';
        $this->assertTrue(@symlink($outside, $link));
        $this->use_absolute_root($this->absolute_root);

        $copied = DBVC_Artifact_Location::migrate(false);
        $this->assertSame(1, $copied['totals']['skipped']);
        $this->assertFileDoesNotExist($this->artifact_root . '/media-bundles/dbvc-s1-' . $this->suffix . '/linked.json');

        DBVC_Artifact_Location::remove_legacy(false);
        $this->assertFileExists($outside, 'Removal never follows a symlink.');
    }

    public function test_migration_is_capability_gated_and_needs_the_absolute_root(): void
    {
        $this->assertSame('dbvc_artifacts_not_private', DBVC_Artifact_Location::migrate(true)->get_error_code());

        $this->use_absolute_root($this->absolute_root);
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertSame('dbvc_forbidden', DBVC_Artifact_Location::migrate(true)->get_error_code());
        $this->assertSame('dbvc_forbidden', DBVC_Artifact_Location::remove_legacy(false)->get_error_code());
    }

    public function test_backup_delete_and_download_refuse_names_outside_one_backup_folder(): void
    {
        $this->use_absolute_root($this->absolute_root);
        $base = DBVC_Backup_Manager::get_base_path();
        wp_mkdir_p($base . '/keep-me');
        // The backup base's parent: the private root, or uploads/sync before the fix.
        $parent = dirname($base);
        file_put_contents($parent . '/sentinel.txt', 'x');

        foreach (['..', '.', '', '../db-version-control-backups', 'keep-me/..'] as $name) {
            $this->assertWPError(DBVC_Backup_Manager::delete_backup($name), "delete_backup('{$name}') is refused");
            $this->assertWPError(DBVC_Backup_Manager::download_backup($name), "download_backup('{$name}') is refused");
        }
        $this->assertFileExists($parent . '/sentinel.txt', 'The folder above the backups survives.');
        $this->assertDirectoryExists($base . '/keep-me');

        $this->assertTrue(DBVC_Backup_Manager::delete_backup('keep-me'));
        $this->assertDirectoryDoesNotExist($base . '/keep-me');
        $this->assertDirectoryExists($base);
    }

    public function test_clear_sync_rails_never_remove_the_private_root(): void
    {
        $this->use_absolute_root($this->absolute_root);
        wp_mkdir_p($this->artifact_root . '/db-version-control-backups');
        file_put_contents($this->artifact_root . '/sentinel.txt', 'x');

        [$deleted, , $deleted_root] = dbvc_delete_sync_contents($this->artifact_root, true);
        $this->assertSame(0, $deleted);
        $this->assertFalse($deleted_root);
        $this->assertFalse(dbvc_is_safe_to_delete_root($this->artifact_root));
        $this->assertFileExists($this->artifact_root . '/sentinel.txt');
    }

    public function test_site_health_flags_legacy_artifacts_until_they_are_removed(): void
    {
        $this->seed_legacy_artifacts();
        file_put_contents($this->legacy_root() . '/db-version-control-backups/.htaccess', "Deny from all\n");
        $tests = apply_filters('site_status_tests', []);
        $this->assertArrayHasKey(DBVC_Artifact_Location::SITE_HEALTH_TEST, $tests['direct']);

        $this->assertSame('good', DBVC_Artifact_Location::site_health_test()['status'], 'Nothing to report before the absolute root is set.');

        $this->use_absolute_root($this->absolute_root);
        $this->assertSame('recommended', DBVC_Artifact_Location::site_health_test()['status']);

        DBVC_Artifact_Location::migrate(false);
        DBVC_Artifact_Location::remove_legacy(false);
        $this->assertSame('good', DBVC_Artifact_Location::site_health_test()['status']);
    }

    /**
     * Seed one file per legacy area plus the log, rotated log and an unrelated file.
     *
     * @return array{backup:string,files:array<string,string>,unrelated:string}
     */
    private function seed_legacy_artifacts(): array
    {
        $legacy = $this->legacy_root();
        $backup = 'dbvc-s1-backup-' . $this->suffix;
        $id     = 'dbvc-s1-' . $this->suffix;
        $log    = trailingslashit(ABSPATH) . $this->log_relative;

        $files = [
            'backup'   => $legacy . '/db-version-control-backups/' . $backup . '/options.json',
            'snapshot' => $legacy . '/db-version-control-snapshots/' . $id . '/entity.json',
            'bundle'   => $legacy . '/media-bundles/' . $id . '/2026/10/image.jpg',
            'packet'   => $legacy . '/dbvc-transfer-packets/' . $id . '.zip',
            'log'      => $log . '/dbvc-backup.log',
            'rotated'  => $log . '/dbvc-backup.log.20261031-120000',
        ];
        foreach ($files as $path) {
            wp_mkdir_p(dirname($path));
            file_put_contents($path, wp_json_encode(['secret' => self::SECRET, 'path' => basename($path)]));
        }
        $unrelated = $log . '/unrelated.txt';
        file_put_contents($unrelated, 'keep');

        foreach ([
            $legacy . '/db-version-control-backups/' . $backup,
            $legacy . '/db-version-control-snapshots/' . $id,
            $legacy . '/media-bundles/' . $id,
            $legacy . '/dbvc-transfer-packets/' . $id . '.zip',
        ] as $path) {
            $this->cleanup[] = $path;
        }

        return ['backup' => $backup, 'files' => $files, 'unrelated' => $unrelated];
    }

    private function legacy_root(): string
    {
        $uploads = wp_get_upload_dir();
        return untrailingslashit(wp_normalize_path($uploads['basedir'])) . '/sync';
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
