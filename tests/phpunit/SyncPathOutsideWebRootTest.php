<?php

/**
 * Security finding S1, structural fix: the sync folder outside the web root.
 *
 * `dbvc_sync_path` always resolves under ABSPATH, and nginx ignores the
 * folder's .htaccess. DBVC_SYNC_PATH_ABSOLUTE (or the code-level
 * `dbvc_sync_path_absolute` filter) moves the folder to a validated absolute
 * path outside every web-served root, such as Cloudways `private_html`.
 *
 * The tests use the filter because a constant cannot be undefined between
 * tests. Both feed the same validator.
 */
class SyncPathOutsideWebRootTest extends WP_UnitTestCase
{
    /** @var string Sibling of the WordPress install, like private_html beside public_html. */
    private $private_root = '';

    /** @var string The absolute sync root inside $private_root. */
    private $absolute_root = '';

    /** @var string ABSPATH-relative sync folder held in the dbvc_sync_path option. */
    private $relative_dir = '';

    /** @var mixed */
    private $sync_path_before = null;

    /** @var array<int,string> */
    private $cleanup = [];

    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->sync_path_before = get_option('dbvc_sync_path', null);

        $suffix = wp_generate_password(8, false, false);
        $this->private_root  = wp_normalize_path(dirname(untrailingslashit(ABSPATH)) . '/dbvc-private-' . $suffix);
        $this->absolute_root = $this->private_root . '/dbvc-sync';
        $this->cleanup[]     = $this->private_root;

        $this->relative_dir = 'wp-content/uploads/dbvc-s1-relative-' . $suffix;
        $this->cleanup[]    = trailingslashit(ABSPATH) . $this->relative_dir;
        update_option('dbvc_sync_path', $this->relative_dir);
    }

    public function tear_down(): void
    {
        remove_all_filters('dbvc_sync_path_absolute');
        if ($this->sync_path_before === null) {
            delete_option('dbvc_sync_path');
        } else {
            update_option('dbvc_sync_path', $this->sync_path_before);
        }
        foreach ($this->cleanup as $path) {
            $this->delete_path($path);
        }
        if (class_exists('DBVC_Sync_Location')) {
            DBVC_Sync_Location::reset_cache();
        }

        parent::tear_down();
    }

    public function test_absolute_root_outside_abspath_wins_over_the_relative_option(): void
    {
        $this->use_absolute_root($this->absolute_root);

        $this->assertSame(trailingslashit($this->absolute_root), wp_normalize_path(dbvc_get_sync_path()));
        $this->assertSame(trailingslashit($this->absolute_root) . 'page/', wp_normalize_path(dbvc_get_sync_path('page')));
        $this->assertSame($this->absolute_root, wp_normalize_path(DBVC_Sync_Posts::get_sync_folder_path()));
    }

    public function test_options_and_post_exports_land_outside_the_web_root(): void
    {
        // set_up's option save may already have run the on-update export into
        // the relative folder; clear it so the assertion covers this export only.
        $this->delete_path(trailingslashit(ABSPATH) . $this->relative_dir);
        $this->use_absolute_root($this->absolute_root);
        wp_mkdir_p($this->absolute_root);

        DBVC_Sync_Posts::export_options_to_json();
        $this->assertFileExists($this->absolute_root . '/options.json');
        $this->assertFileDoesNotExist(trailingslashit(ABSPATH) . $this->relative_dir . '/options.json');

        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'S1 outside web root']);
        $result  = DBVC_Sync_Posts::export_post_to_json($post_id, get_post($post_id));
        $this->assertNotWPError($result);
        $this->assertNotEmpty(glob($this->absolute_root . '/page/*.json'), 'The page export is written under the absolute root.');
    }

    public function test_safe_file_path_accepts_the_absolute_root_only(): void
    {
        $this->use_absolute_root($this->absolute_root);
        wp_mkdir_p($this->absolute_root . '/page');
        wp_mkdir_p($this->absolute_root . '-evil');
        wp_mkdir_p($this->private_root . '/other');

        $this->assertTrue(dbvc_is_safe_file_path($this->absolute_root . '/page/1.json'));
        $this->assertTrue(DBVC_Sync_Posts::is_safe_file_path($this->absolute_root . '/page'));

        $this->assertFalse(dbvc_is_safe_file_path($this->absolute_root . '-evil/1.json'), 'A sibling sharing the prefix is outside the root.');
        $this->assertFalse(DBVC_Sync_Posts::is_safe_file_path($this->absolute_root . '-evil'));
        $this->assertFalse(dbvc_is_safe_file_path($this->private_root . '/other/1.json'));
        $this->assertFalse(dbvc_is_safe_file_path($this->absolute_root . '/page/../../other/1.json'));
        $this->assertFalse(dbvc_is_safe_file_path($this->absolute_root . '/page/1.php'));
    }

    public function test_clearing_the_absolute_root_keeps_the_operators_folder(): void
    {
        $this->use_absolute_root($this->absolute_root);
        wp_mkdir_p($this->absolute_root . '/page');
        file_put_contents($this->absolute_root . '/options.json', '{}');
        file_put_contents($this->absolute_root . '/page/1.json', '{}');

        list($deleted, $failed, $deleted_root) = dbvc_delete_sync_contents(dbvc_get_sync_path(), true);

        $this->assertSame(3, $deleted, 'options.json, page/1.json and page/ are removed.');
        $this->assertSame(0, $failed);
        $this->assertFalse($deleted_root, 'The configured absolute root itself is never removed.');
        $this->assertDirectoryExists($this->absolute_root);
        $this->assertFileDoesNotExist($this->absolute_root . '/options.json');

        $this->assertTrue(dbvc_is_safe_to_delete_root($this->absolute_root . '/page'));
        $this->assertFalse(dbvc_is_safe_to_delete_root($this->absolute_root));
    }

    /**
     * @dataProvider refused_paths
     */
    public function test_unsafe_absolute_roots_are_refused_and_fall_back(string $case, string $expected_code): void
    {
        $candidate = $this->refused_candidate($case);
        $this->use_absolute_root($candidate);

        $refusal = DBVC_Sync_Location::refusal();
        $this->assertInstanceOf(WP_Error::class, $refusal, $case);
        $this->assertSame($expected_code, $refusal->get_error_code(), $case);
        $this->assertSame('', DBVC_Sync_Location::absolute_root());

        $this->assertSame(
            wp_normalize_path(trailingslashit(trailingslashit(ABSPATH) . $this->relative_dir)),
            wp_normalize_path(dbvc_get_sync_path()),
            'A refused absolute root falls back to the existing option.'
        );
    }

    public function refused_paths(): array
    {
        return [
            'inside ABSPATH'           => ['inside_abspath', 'dbvc_sync_path_web_reachable'],
            'inside uploads'           => ['inside_uploads', 'dbvc_sync_path_web_reachable'],
            'symlink into the web root' => ['symlink_into_web_root', 'dbvc_sync_path_web_reachable'],
            'contains the install'     => ['ancestor_of_abspath', 'dbvc_sync_path_contains_site'],
            'filesystem root'          => ['root', 'dbvc_sync_path_too_shallow'],
            'top-level folder'         => ['top_level', 'dbvc_sync_path_too_shallow'],
            'system folder'            => ['system', 'dbvc_sync_path_system_folder'],
            'relative'                 => ['relative', 'dbvc_sync_path_not_absolute'],
            'traversal'                => ['traversal', 'dbvc_sync_path_traversal'],
            'null byte'                => ['null_byte', 'dbvc_sync_path_unsafe_chars'],
        ];
    }

    public function test_site_health_reports_web_reachable_and_refused_folders(): void
    {
        $tests = apply_filters('site_status_tests', ['direct' => [], 'async' => []]);
        $this->assertArrayHasKey('dbvc_sync_folder_location', $tests['direct']);
        $callback = $tests['direct']['dbvc_sync_folder_location']['test'];

        $exposed = call_user_func($callback);
        $this->assertSame('recommended', $exposed['status'], 'The ABSPATH-relative option is web-reachable.');
        $this->assertSame('dbvc_sync_folder_location', $exposed['test']);

        $this->use_absolute_root($this->absolute_root);
        $good = call_user_func($callback);
        $this->assertSame('good', $good['status']);

        $this->use_absolute_root(trailingslashit(ABSPATH) . 'wp-content/dbvc-inside');
        $refused = call_user_func($callback);
        $this->assertSame('critical', $refused['status']);
        $this->assertStringContainsString('DBVC_SYNC_PATH_ABSOLUTE', $refused['label']);
    }

    public function test_the_admin_setting_cannot_set_an_absolute_root(): void
    {
        $this->assertFalse(dbvc_validate_sync_path($this->absolute_root), 'The option stays ABSPATH-relative; only code can move the folder.');
        $this->assertSame('', DBVC_Sync_Location::configured_path());
    }

    private function use_absolute_root(string $path): void
    {
        remove_all_filters('dbvc_sync_path_absolute');
        add_filter('dbvc_sync_path_absolute', static function () use ($path) {
            return $path;
        });
    }

    private function refused_candidate(string $case): string
    {
        $abspath = untrailingslashit(wp_normalize_path(ABSPATH));
        switch ($case) {
            case 'inside_abspath':
                return $abspath . '/private-sync';
            case 'inside_uploads':
                $uploads = wp_get_upload_dir();
                return wp_normalize_path($uploads['basedir']) . '/dbvc-sync';
            case 'symlink_into_web_root':
                wp_mkdir_p($this->private_root);
                $target = $abspath . '/wp-content/uploads/dbvc-s1-link-target-' . wp_generate_password(6, false, false);
                wp_mkdir_p($target);
                $this->cleanup[] = $target;
                $link = $this->private_root . '/link';
                if (! @symlink($target, $link)) {
                    $this->markTestSkipped('symlink() is not available.');
                }
                return $link . '/dbvc-sync';
            case 'ancestor_of_abspath':
                return dirname($abspath);
            case 'root':
                return '/';
            case 'top_level':
                return '/home';
            case 'system':
                return '/etc/dbvc-sync';
            case 'relative':
                return 'private_html/dbvc-sync';
            case 'traversal':
                return $this->private_root . '/a/../dbvc-sync';
            case 'null_byte':
                return $this->private_root . "/dbvc\0sync";
        }
        $this->fail('Unknown case ' . $case);
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
