<?php

/**
 * Security finding S1, remaining item: a web-reachable `dbvc_sync_path`.
 *
 * The admin option still accepts theme, plugin and uploads folders, and nginx
 * ignores their .htaccess. DBVC keeps those locations working but gives the
 * operator the exact nginx deny block, the Cloudways steps and a hard-to-guess
 * folder name, on the settings screen, on save and in Site Health. None of it
 * makes an HTTP request.
 */
class SyncPathNginxExposureTest extends WP_UnitTestCase
{
    /** @var mixed */
    private $sync_path_before = null;

    /** @var string */
    private $suffix = '';

    /** @var array<int,string> */
    private $cleanup = [];

    public function set_up(): void
    {
        parent::set_up();

        if (! function_exists('dbvc_render_export_page')) {
            require_once DBVC_PLUGIN_PATH . 'admin/admin-page.php';
        }
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->sync_path_before = get_option('dbvc_sync_path', null);
        $this->suffix           = strtolower(wp_generate_password(6, false, false));
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

    public function test_uploads_folder_gets_an_nginx_deny_block_for_its_url_path(): void
    {
        $this->assertTrue(class_exists('DBVC_Sync_Exposure'), 'The exposure guidance helper must be loaded.');

        $relative = 'wp-content/uploads/dbvc-sync-' . $this->suffix;
        $folder   = $this->use_relative_option($relative);
        $expected = $this->url_path_of(wp_get_upload_dir()['baseurl']) . 'dbvc-sync-' . $this->suffix . '/';

        $this->assertSame($expected, DBVC_Sync_Exposure::url_path($folder));

        $rule = DBVC_Sync_Exposure::nginx_rule($folder);
        $this->assertStringContainsString('location ^~ ' . $expected . ' {', $rule, '^~ keeps regex locations from serving the JSON first.');
        $this->assertStringContainsString('deny all;', $rule);
    }

    public function test_theme_folder_maps_through_the_theme_root_url(): void
    {
        $folder   = wp_normalize_path(get_theme_root() . '/dbvc-theme-' . $this->suffix . '/sync');
        $expected = $this->url_path_of(get_theme_root_uri()) . 'dbvc-theme-' . $this->suffix . '/sync/';

        $this->assertTrue(DBVC_Sync_Location::is_web_reachable($folder));
        $this->assertSame($expected, DBVC_Sync_Exposure::url_path($folder));
        $this->assertStringContainsString('location ^~ ' . $expected . ' {', DBVC_Sync_Exposure::nginx_rule($folder));
    }

    public function test_location_with_spaces_is_quoted(): void
    {
        $folder = wp_normalize_path(wp_get_upload_dir()['basedir'] . '/dbvc sync ' . $this->suffix);

        $this->assertStringContainsString(
            'location ^~ "' . $this->url_path_of(wp_get_upload_dir()['baseurl']) . 'dbvc sync ' . $this->suffix . '/" {',
            DBVC_Sync_Exposure::nginx_rule($folder)
        );
    }

    public function test_folder_outside_the_web_root_gets_no_rule_or_warning(): void
    {
        $outside = wp_normalize_path(dirname(untrailingslashit(ABSPATH)) . '/dbvc-private-' . $this->suffix . '/dbvc-sync');

        $this->assertSame('', DBVC_Sync_Exposure::url_path($outside));
        $this->assertSame('', DBVC_Sync_Exposure::nginx_rule($outside));
        $this->assertSame('', DBVC_Sync_Exposure::save_warning($outside));
        $this->assertSame('', DBVC_Sync_Exposure::guidance_html($outside));
    }

    public function test_predictable_names_are_flagged_and_a_random_name_is_suggested(): void
    {
        $uploads = wp_normalize_path(wp_get_upload_dir()['basedir']);

        $this->assertFalse(DBVC_Sync_Exposure::has_unguessable_name($uploads . '/dbvc-sync'));
        $this->assertFalse(DBVC_Sync_Exposure::has_unguessable_name(wp_normalize_path(WP_PLUGIN_DIR . '/db-version-control/sync')));
        $this->assertFalse(DBVC_Sync_Exposure::has_unguessable_name($uploads . '/internationalization-sync'), 'A long word is not a token.');
        $this->assertTrue(DBVC_Sync_Exposure::has_unguessable_name($uploads . '/dbvc-sync-k3j9x0q7w2m5z8r4t1v6'));

        $suggested = DBVC_Sync_Exposure::suggest_unguessable_path('wp-content/themes/vertical/dbvc-sync');
        $this->assertMatchesRegularExpression('#^wp-content/themes/vertical/dbvc-sync-[a-z0-9]{20}$#', $suggested, 'The chosen parent folder is kept.');
        $this->assertNotFalse(dbvc_validate_sync_path($suggested), 'The suggestion passes the existing option validator.');
        $this->assertTrue(DBVC_Sync_Exposure::has_unguessable_name(trailingslashit(ABSPATH) . $suggested));
        $this->assertStringStartsWith('wp-content/uploads/dbvc-sync-', DBVC_Sync_Exposure::suggest_unguessable_path(''));
    }

    public function test_save_warning_names_the_folder_and_the_fix(): void
    {
        $folder  = $this->use_relative_option('wp-content/uploads/dbvc-sync-' . $this->suffix);
        $warning = DBVC_Sync_Exposure::save_warning($folder);

        $this->assertStringContainsString(untrailingslashit($folder), $warning);
        $this->assertStringContainsString('nginx', $warning);
        $this->assertStringContainsString('DBVC_SYNC_PATH_ABSOLUTE', $warning);
        $this->assertStringContainsString('predictable', $warning);
    }

    public function test_site_health_actions_carry_the_nginx_block_and_cloudways_steps(): void
    {
        $relative = 'wp-content/uploads/dbvc-sync-' . $this->suffix;
        $this->use_relative_option($relative);

        $tests  = apply_filters('site_status_tests', ['direct' => [], 'async' => []]);
        $result = call_user_func($tests['direct']['dbvc_sync_folder_location']['test']);

        $this->assertSame('recommended', $result['status'], 'Existing relative paths keep working; Site Health does not escalate.');
        $expected = $this->url_path_of(wp_get_upload_dir()['baseurl']) . 'dbvc-sync-' . $this->suffix . '/';
        $this->assertStringContainsString(esc_html('location ^~ ' . $expected . ' {'), $result['actions']);
        $this->assertStringContainsString('deny all;', $result['actions']);
        $this->assertStringContainsString('Cloudways', $result['actions']);
        $this->assertStringContainsString('private_html', $result['actions']);
        $this->assertStringContainsString('wp-content/uploads/dbvc-sync-', $result['actions'], 'A random-name suggestion keeps the uploads parent.');
        $this->assertStringNotContainsString('<script', $result['actions']);
    }

    public function test_settings_screen_shows_the_nginx_block_for_the_configured_folder(): void
    {
        $relative = 'wp-content/uploads/dbvc-sync-' . $this->suffix;
        $this->use_relative_option($relative);

        ob_start();
        try {
            dbvc_render_export_page();
        } finally {
            $html = (string) ob_get_clean();
        }

        $expected = $this->url_path_of(wp_get_upload_dir()['baseurl']) . 'dbvc-sync-' . $this->suffix . '/';
        $this->assertStringContainsString('dbvc-sync-nginx-rule', $html);
        $this->assertStringContainsString(esc_html('location ^~ ' . $expected . ' {'), $html);
    }

    public function test_saving_a_web_reachable_path_warns_once_and_guards_the_folder_root(): void
    {
        $relative = 'wp-content/uploads/dbvc-sync-' . $this->suffix;
        $folder   = trailingslashit(ABSPATH) . $relative;
        $this->cleanup[] = $folder;

        $first = $this->submit_config_save($relative);
        $this->assertStringContainsString('notice-warning', $first);
        $this->assertStringContainsString(esc_html(wp_normalize_path($folder)) . ' is inside the web root', $first);
        $this->assertFileExists($folder . '/index.php', 'index.php stops nginx directory listings.');
        $this->assertFileExists($folder . '/.htaccess');

        $again = $this->submit_config_save($relative);
        $this->assertStringNotContainsString('is inside the web root. Apache', $again, 'An unchanged path is not re-warned on every save.');
    }

    private function submit_config_save(string $relative): string
    {
        $post_before = $_POST;
        $_POST = [
            'dbvc_config_save'  => '1',
            'dbvc_config_nonce' => wp_create_nonce('dbvc_config_save_action'),
            'dbvc_sync_path'    => $relative,
        ];
        ob_start();
        try {
            dbvc_render_export_page();
        } finally {
            $html  = (string) ob_get_clean();
            $_POST = $post_before;
        }
        return $html;
    }

    private function use_relative_option(string $relative): string
    {
        update_option('dbvc_sync_path', $relative);
        $this->cleanup[] = trailingslashit(ABSPATH) . $relative;
        return untrailingslashit(wp_normalize_path(dbvc_get_sync_path()));
    }

    private function url_path_of(string $url): string
    {
        return trailingslashit('/' . trim((string) wp_parse_url($url, PHP_URL_PATH), '/'));
    }

    private function delete_path(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (! is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
