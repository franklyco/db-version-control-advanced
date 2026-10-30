<?php

/**
 * Security finding S1: options.json must never carry secret-shaped option values.
 *
 * The sync folder sits under ABSPATH and its .htaccess deny does nothing on
 * nginx, so anything written to options.json must be treated as public.
 */
class OptionsExportSecretRedactionTest extends WP_UnitTestCase
{
    const PLACEHOLDER = '[dbvc-redacted]';

    private $sync_option_before;
    private $test_sync_dir = '';
    private $seeded_options = [];

    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->sync_option_before = get_option('dbvc_sync_path', null);

        $this->test_sync_dir = trailingslashit(WP_CONTENT_DIR)
            . 'uploads/dbvc-options-redaction-test-'
            . wp_generate_uuid4();
        wp_mkdir_p($this->test_sync_dir);

        $relative = ltrim(
            str_replace(wp_normalize_path(ABSPATH), '', wp_normalize_path($this->test_sync_dir)),
            '/'
        );
        update_option('dbvc_sync_path', $relative);
    }

    public function tear_down(): void
    {
        foreach ($this->seeded_options as $option_name) {
            delete_option($option_name);
        }

        if ($this->sync_option_before === null) {
            delete_option('dbvc_sync_path');
        } else {
            update_option('dbvc_sync_path', $this->sync_option_before);
        }

        if (is_dir($this->test_sync_dir)) {
            foreach (new FilesystemIterator($this->test_sync_dir, FilesystemIterator::SKIP_DOTS) as $item) {
                if ($item->isFile()) {
                    unlink($item->getPathname());
                }
            }
            rmdir($this->test_sync_dir);
        }

        parent::tear_down();
    }

    public function test_export_redacts_secret_named_options(): void
    {
        $secrets = [
            'dbvc_bricks_api_secret' => 'sk-dbvc-bricks-secret-1111',
            'bricks_license_key' => 'lic-bricks-2222',
            'mailserver_pass' => 'mailpass-3333',
            'sc_api_token' => 'tok-surecart-4444',
            'rg_gforms_key' => 'gf-key-5555',
            'brickscore_license_options' => ['license' => 'lic-core-6666', 'status' => 'valid'],
        ];
        foreach ($secrets as $name => $value) {
            $this->seed_option($name, $value);
        }
        $this->seed_option('dbvc_s1_plain_setting', 'visible-value');

        $raw = $this->export_and_read_raw();
        $exported = json_decode($raw, true);

        foreach ($secrets as $name => $value) {
            $this->assertArrayHasKey($name, $exported, "{$name} stays listed so operators can see it was redacted.");
            $this->assertSame(self::PLACEHOLDER, $exported[$name], "{$name} must be redacted.");
        }
        foreach (['1111', '2222', '3333', '4444', '5555', '6666'] as $marker) {
            $this->assertStringNotContainsString($marker, $raw, 'No secret value may reach options.json.');
        }
        $this->assertSame('visible-value', $exported['dbvc_s1_plain_setting']);
    }

    public function test_export_redacts_secrets_nested_inside_settings_arrays(): void
    {
        $this->seed_option('dbvc_s1_plugin_settings', [
            'api_key' => 'nested-api-7777',
            'facebook_secret' => 'nested-fb-8888',
            'cron_job_key' => 'nested-cron-9999',
            'smtp' => ['host' => 'smtp.example.test', 'pass' => 'nested-smtp-0000'],
            'kits' => [['name' => 'Kit', 'token' => 'nested-kit-1212']],
            'unsplash_api' => 'nested-unsplash-1313',
            'cf_apitoken' => 'nested-cf-1414',
            // Non-secret look-alikes must survive untouched.
            'conditions' => [['key' => 'user_role', 'value' => 'editor']],
            'max_tokens' => 4000,
            'lost_password_page' => '12',
            'noindex_password_protected' => 'off',
            'license_status' => 'valid',
        ]);

        $raw = $this->export_and_read_raw();
        $exported = json_decode($raw, true)['dbvc_s1_plugin_settings'];

        foreach (['7777', '8888', '9999', '0000', '1212', '1313', '1414'] as $marker) {
            $this->assertStringNotContainsString($marker, $raw);
        }
        $this->assertSame(self::PLACEHOLDER, $exported['api_key']);
        $this->assertSame(self::PLACEHOLDER, $exported['facebook_secret']);
        $this->assertSame(self::PLACEHOLDER, $exported['cron_job_key']);
        $this->assertSame(self::PLACEHOLDER, $exported['smtp']['pass']);
        $this->assertSame('smtp.example.test', $exported['smtp']['host']);
        $this->assertSame(self::PLACEHOLDER, $exported['kits'][0]['token']);
        $this->assertSame([['key' => 'user_role', 'value' => 'editor']], $exported['conditions']);
        $this->assertSame(4000, $exported['max_tokens']);
        $this->assertSame('12', $exported['lost_password_page']);
        $this->assertSame('off', $exported['noindex_password_protected']);
        $this->assertSame('valid', $exported['license_status']);
    }

    public function test_plugin_activation_export_is_redacted(): void
    {
        $this->seed_option('dbvc_bricks_api_secret', 'sk-activation-3131');

        do_action('activated_plugin', 'example/example.php', false);

        $raw = $this->read_options_file();
        $this->assertStringNotContainsString('3131', $raw);
    }

    public function test_import_keeps_the_target_sites_own_secrets(): void
    {
        $this->seed_option('dbvc_bricks_api_secret', 'target-secret-4141');
        $this->seed_option('dbvc_s1_plugin_settings', [
            'api_key' => 'target-api-5151',
            'smtp' => ['host' => 'old.example.test', 'pass' => 'target-smtp-6161'],
            'mode' => 'old',
        ]);

        $this->write_options_file([
            'dbvc_bricks_api_secret' => self::PLACEHOLDER,
            'dbvc_s1_unknown_secret' => self::PLACEHOLDER,
            'dbvc_s1_plugin_settings' => [
                'api_key' => self::PLACEHOLDER,
                'smtp' => ['host' => 'new.example.test', 'pass' => self::PLACEHOLDER],
                'mode' => 'new',
                'token' => self::PLACEHOLDER,
            ],
        ]);
        $this->seeded_options[] = 'dbvc_s1_unknown_secret';

        DBVC_Sync_Posts::import_options_from_json();

        $this->assertSame('target-secret-4141', get_option('dbvc_bricks_api_secret'));
        $this->assertFalse(get_option('dbvc_s1_unknown_secret'), 'A placeholder never creates an option.');
        $this->assertSame(
            [
                'api_key' => 'target-api-5151',
                'smtp' => ['host' => 'new.example.test', 'pass' => 'target-smtp-6161'],
                'mode' => 'new',
            ],
            get_option('dbvc_s1_plugin_settings'),
            'Non-secret fields import; redacted fields keep the target value or are dropped.'
        );
    }

    public function test_secret_classification_is_filterable(): void
    {
        $this->seed_option('dbvc_s1_public_token_label', 'shown-7171');
        $allow = static function ($is_secret, $key) {
            return $key === 'dbvc_s1_public_token_label' ? false : $is_secret;
        };
        add_filter('dbvc_is_secret_option_key', $allow, 10, 2);

        $exported = json_decode($this->export_and_read_raw(), true);
        remove_filter('dbvc_is_secret_option_key', $allow, 10);

        $this->assertSame('shown-7171', $exported['dbvc_s1_public_token_label']);
    }

    /**
     * @param string $name
     * @param mixed  $value
     */
    private function seed_option($name, $value): void
    {
        delete_option($name);
        add_option($name, $value, '', true);
        $this->seeded_options[] = $name;
    }

    private function export_and_read_raw(): string
    {
        DBVC_Sync_Posts::export_options_to_json();

        return $this->read_options_file();
    }

    private function read_options_file(): string
    {
        $path = trailingslashit($this->test_sync_dir) . 'options.json';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function write_options_file(array $options): void
    {
        file_put_contents(
            trailingslashit($this->test_sync_dir) . 'options.json',
            wp_json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}
