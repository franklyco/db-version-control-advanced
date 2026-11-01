<?php

use Dbvc\ConfigurationPortability\ExportPackageBuilder;
use Dbvc\ConfigurationPortability\ImportSessionService;
use Dbvc\ConfigurationPortability\Storage as PortabilityStorage;

/**
 * Security finding S1, portability rollback backups.
 *
 * Configuration and Bricks portability write `backup.json` before they change
 * options. It copied whole option values, secrets included, under
 * `uploads/dbvc/`, which nginx serves. Secret values now live in a
 * non-autoloaded vault option and the file keeps a marker; rollback restores
 * the real value or refuses, and never writes a marker to an option.
 *
 * Also covers the guarded cleanup_directory() helpers, which deleted any
 * directory they were given.
 */
class PortabilityBackupSecretVaultTest extends WP_UnitTestCase
{
    private const CONFIG_SECRET = 'dbvc-s1-vault-config-secret-zq';
    private const MAPS_KEY      = 'dbvc-s1-vault-maps-key-zq';
    private const MAILCHIMP_KEY = 'dbvc-s1-vault-mailchimp-key-zq';
    private const LICENSE_KEY   = 'dbvc-s1-vault-license-key-zq';

    /** @var string */
    private $suffix = '';

    /** @var string */
    private $uploads_dir = '';

    /** @var array<int,string> */
    private $cleanup = [];

    /** @var array<string,mixed> */
    private $option_snapshot = [];

    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $this->suffix      = strtolower(wp_generate_password(8, false, false));
        $this->uploads_dir = wp_normalize_path(trailingslashit(ABSPATH) . 'wp-content/uploads/dbvc-s1-vault-' . $this->suffix);
        $this->cleanup[]   = $this->uploads_dir;
        wp_mkdir_p($this->uploads_dir);
        add_filter('upload_dir', [$this, 'isolate_uploads']);

        foreach (['dbvc_bricks_api_secret', 'dbvc_bricks_api_key_id', 'bricks_global_settings', 'bricks_license_key'] as $option) {
            $this->option_snapshot[$option] = get_option($option, null);
        }
        $this->reset_location_caches();
    }

    public function tear_down(): void
    {
        remove_filter('upload_dir', [$this, 'isolate_uploads']);
        remove_all_filters('dbvc_sync_path_absolute');
        foreach ($this->option_snapshot as $option => $value) {
            if ($value === null) {
                delete_option($option);
            } else {
                update_option($option, $value);
            }
        }
        foreach ($this->cleanup as $path) {
            $this->delete_path($path);
        }
        $this->reset_location_caches();

        parent::tear_down();
    }

    /**
     * @param array $uploads
     * @return array
     */
    public function isolate_uploads($uploads)
    {
        $uploads['basedir'] = $this->uploads_dir;
        $uploads['path']    = $this->uploads_dir;
        $uploads['baseurl'] = content_url('/uploads/dbvc-s1-vault-' . $this->suffix);
        $uploads['error']   = false;
        return $uploads;
    }

    public function test_config_apply_keeps_secrets_out_of_backup_json_and_rollback_restores_them(): void
    {
        $applied = $this->apply_bricks_addon_package();
        $backup_path = (string) $applied['backup_path'];
        $raw = (string) file_get_contents($backup_path);

        $this->assertStringNotContainsString(self::CONFIG_SECRET, $raw, 'backup.json must not hold the API secret');
        $this->assertStringNotContainsString('target-key-id', $raw, 'backup.json must not hold the API key id');
        $this->assertStringContainsString('[dbvc-vaulted:', $raw);

        $option = DBVC_Backup_Secret_Vault::option_name(DBVC_Backup_Secret_Vault::SCOPE_CONFIG, (string) $applied['backup_id']);
        $this->assertNotSame('', $option);
        $this->assertContains($this->autoload_of($option), ['no', 'off'], 'the vault option is not autoloaded');
        $this->assertTrue(DBVC_Options_Secret_Redactor::is_secret_option_name($option), 'options.json would redact the vault option');
        $this->assertArrayNotHasKey($option, wp_load_alloptions());

        update_option('dbvc_bricks_api_secret', 'changed-after-apply');

        $rolled_back = ImportSessionService::rollback_session((string) $applied['session_id'], ['confirm_rollback' => true]);
        $this->assertNotWPError($rolled_back);
        $this->assertSame(self::CONFIG_SECRET, get_option('dbvc_bricks_api_secret'));
        $this->assertSame('target-key-id', get_option('dbvc_bricks_api_key_id'));
    }

    public function test_config_rollback_refuses_and_writes_nothing_when_the_vault_is_missing(): void
    {
        $applied = $this->apply_bricks_addon_package();
        DBVC_Backup_Secret_Vault::delete(DBVC_Backup_Secret_Vault::SCOPE_CONFIG, (string) $applied['backup_id']);
        update_option('dbvc_bricks_api_secret', 'changed-after-apply');

        $result = ImportSessionService::rollback_session((string) $applied['session_id'], ['confirm_rollback' => true]);

        $this->assertWPError($result);
        $this->assertSame('dbvc_backup_secrets_unavailable', $result->get_error_code());
        $this->assertSame('changed-after-apply', get_option('dbvc_bricks_api_secret'));
        $this->assertSame('applied-key-id', get_option('dbvc_bricks_api_key_id'), 'no option was rolled back');
    }

    public function test_bricks_backup_file_holds_markers_and_restore_returns_real_values(): void
    {
        update_option('bricks_global_settings', $this->bricks_settings());
        update_option('bricks_license_key', self::LICENSE_KEY);

        $backup = DBVC_Bricks_Portability_Backup_Service::create_backup(['session_id' => 'vault-session'], ['bricks_global_settings', 'bricks_license_key'], 0);
        $this->assertNotWPError($backup);
        $this->assertSame(self::MAPS_KEY, $backup['options']['bricks_global_settings']['apiKeyGoogleMaps'], 'the in-memory backup keeps real values');

        $backup = DBVC_Bricks_Portability_Backup_Service::record_media_state($backup, []);
        $this->assertNotWPError($backup);

        $raw = $this->read_bricks_backup((string) $backup['backup_id']);
        foreach ([self::MAPS_KEY, self::MAILCHIMP_KEY, self::LICENSE_KEY] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $this->assertStringContainsString('"builderMode":"dark"', $raw, 'non-secret settings stay readable');

        update_option('bricks_global_settings', ['builderMode' => 'light']);
        update_option('bricks_license_key', 'changed');

        $loaded = DBVC_Bricks_Portability_Backup_Service::load_backup((string) $backup['backup_id']);
        $this->assertNotWPError($loaded);
        $this->assertTrue(DBVC_Bricks_Portability_Backup_Service::restore_backup($loaded));
        $this->assertSame($this->bricks_settings(), get_option('bricks_global_settings'));
        $this->assertSame(self::LICENSE_KEY, get_option('bricks_license_key'));
    }

    public function test_bricks_load_refuses_without_vault_and_restore_refuses_a_sealed_document(): void
    {
        update_option('bricks_global_settings', $this->bricks_settings());
        $backup = DBVC_Bricks_Portability_Backup_Service::create_backup([], ['bricks_global_settings'], 0);
        $this->assertNotWPError($backup);
        $sealed = json_decode($this->read_bricks_backup((string) $backup['backup_id']), true);

        update_option('bricks_global_settings', ['builderMode' => 'light']);
        $this->assertWPError(DBVC_Bricks_Portability_Backup_Service::restore_backup($sealed));
        $this->assertSame(['builderMode' => 'light'], get_option('bricks_global_settings'));

        DBVC_Backup_Secret_Vault::delete(DBVC_Backup_Secret_Vault::SCOPE_BRICKS, (string) $backup['backup_id']);
        $loaded = DBVC_Bricks_Portability_Backup_Service::load_backup((string) $backup['backup_id']);
        $this->assertWPError($loaded);
        $this->assertSame('dbvc_backup_secrets_unavailable', $loaded->get_error_code());
    }

    public function test_secure_existing_backups_previews_then_seals_legacy_files_in_both_roots(): void
    {
        $private_parent  = wp_normalize_path(dirname(untrailingslashit(ABSPATH)) . '/dbvc-vault-private-' . $this->suffix);
        $this->cleanup[] = $private_parent;
        $legacy_root     = $this->uploads_dir . '/dbvc';
        $private_root    = $private_parent . '/dbvc-sync-artifacts/uploads/dbvc';

        $documents = [];
        foreach ([$legacy_root, $private_root] as $root) {
            $documents[$root] = [
                'config' => $this->seed_backup($root . '/dbvc-config-portability/backups/dbvc-config-backup-legacy', $this->legacy_config_backup()),
                'bricks' => $this->seed_backup($root . '/bricks-portability/backups/bricks-portability-backup-legacy', $this->legacy_bricks_backup()),
            ];
        }
        wp_mkdir_p($private_parent . '/dbvc-sync');
        $this->use_absolute_root($private_parent . '/dbvc-sync');

        $preview = DBVC_Backup_Secret_Vault::secure_existing_backups(true);
        $this->assertNotWPError($preview);
        $this->assertSame(4, $preview['totals']['to_seal']);
        $this->assertSame(['current', 'legacy'], array_values(array_unique(array_column($preview['backups'], 'location'))));
        $this->assertStringNotContainsString(self::CONFIG_SECRET, wp_json_encode($preview), 'the report carries no values');
        $this->assertStringContainsString(self::CONFIG_SECRET, (string) file_get_contents($documents[$legacy_root]['config']), 'a dry run writes nothing');

        $result = DBVC_Backup_Secret_Vault::secure_existing_backups(false);
        $this->assertSame(4, $result['totals']['sealed']);
        $this->assertSame(0, $result['totals']['failed']);
        foreach ($documents as $files) {
            foreach ($files as $file) {
                $raw = (string) file_get_contents($file);
                foreach ([self::CONFIG_SECRET, self::MAPS_KEY, self::LICENSE_KEY] as $secret) {
                    $this->assertStringNotContainsString($secret, $raw);
                }
            }
        }
        $this->assertSame(
            file_get_contents($documents[$legacy_root]['bricks']),
            file_get_contents($documents[$private_root]['bricks']),
            'both copies seal to the same bytes, so migrate-artifacts still pairs them'
        );

        $config = json_decode((string) file_get_contents($documents[$private_root]['config']), true);
        $opened = DBVC_Backup_Secret_Vault::open($config, DBVC_Backup_Secret_Vault::SCOPE_CONFIG, 'dbvc-config-backup-legacy');
        $this->assertSame($this->legacy_config_backup(), $opened);
        $loaded = DBVC_Bricks_Portability_Backup_Service::load_backup('bricks-portability-backup-legacy');
        $this->assertSame($this->legacy_bricks_backup()['options'], $loaded['options']);

        $again = DBVC_Backup_Secret_Vault::secure_existing_backups(true);
        $this->assertSame(0, $again['totals']['to_seal'], 'sealing is idempotent');
    }

    public function test_secure_existing_backups_requires_manage_options_and_prunes_orphans_after_preview(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $denied = DBVC_Backup_Secret_Vault::secure_existing_backups(true);
        $this->assertWPError($denied);
        $this->assertSame('dbvc_forbidden', $denied->get_error_code());

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $sealed = DBVC_Backup_Secret_Vault::seal(['options' => ['bricks_license_key' => self::LICENSE_KEY]], DBVC_Backup_Secret_Vault::SCOPE_BRICKS, 'bricks-portability-backup-gone');
        $this->assertNotWPError($sealed);
        $option = DBVC_Backup_Secret_Vault::option_name(DBVC_Backup_Secret_Vault::SCOPE_BRICKS, 'bricks-portability-backup-gone');

        $preview = DBVC_Backup_Secret_Vault::secure_existing_backups(true, true);
        $this->assertSame(1, $preview['totals']['orphans']);
        $this->assertNotFalse(get_option($option, false), 'a preview keeps the orphan');

        DBVC_Backup_Secret_Vault::secure_existing_backups(false, true);
        $this->assertFalse(get_option($option, false));
    }

    public function test_backup_classifier_catches_bricks_and_dbvc_secret_keys(): void
    {
        foreach (['apiKeyGoogleMaps', 'apiSecretKeyTurnstile', 'mailchimpApiKey', 'instagramAccessToken', 'dbvc_bricks_api_secret', 'bricks_license_key', 'cc_openai_api_key', 'slack_webhook_url'] as $name) {
            $this->assertTrue(DBVC_Backup_Secret_Vault::is_secret_name($name), $name);
        }
        foreach (['builderMode', 'container_width', 'storage_path'] as $name) {
            $this->assertFalse(DBVC_Backup_Secret_Vault::is_secret_name($name), $name);
        }
    }

    /**
     * @dataProvider cleanup_helpers
     */
    public function test_cleanup_directory_refuses_paths_outside_its_root(string $class, string $root_key, string $storage): void
    {
        $roots = $storage === 'bricks' ? DBVC_Bricks_Portability_Storage::ensure_roots() : PortabilityStorage::ensure_roots();
        $this->assertNotWPError($roots);
        $root = $roots[$root_key];

        $outside = $this->uploads_dir . '/outside-' . $this->suffix;
        wp_mkdir_p($outside);
        file_put_contents($outside . '/keep.txt', 'keep');
        $sibling = $root . '-evil/workspace';
        wp_mkdir_p($sibling);
        $other_root = $roots[$root_key === 'exports' ? 'sessions' : 'exports'];
        wp_mkdir_p($root . '/x');

        $refused = [
            'outside' => $outside,
            'root' => $root,
            'root-trailing' => $root . '/',
            'traversal' => $root . '/x/../../' . basename($other_root),
            'traversal-out' => $root . '/../../../outside-' . $this->suffix,
            'sibling-prefix' => $sibling,
        ];
        if (function_exists('symlink')) {
            $link = $root . '/linked-' . $this->suffix;
            @symlink($outside, $link);
            $this->cleanup[] = $link;
            $refused['symlink'] = $link;
        }

        foreach ($refused as $label => $path) {
            $this->call_private($class, 'cleanup_directory', [$path]);
        }

        $this->assertFileExists($outside . '/keep.txt', 'outside data survives');
        $this->assertDirectoryExists($root);
        $this->assertDirectoryExists($other_root);
        $this->assertDirectoryExists($sibling);

        $workspace = $root . '/workspace-' . $this->suffix . '/nested';
        wp_mkdir_p($workspace);
        file_put_contents($workspace . '/file.json', '{}');
        if (function_exists('symlink')) {
            @symlink($outside, $workspace . '/escape');
        }
        $this->call_private($class, 'cleanup_directory', [dirname($workspace)]);
        $this->assertDirectoryDoesNotExist(dirname($workspace), 'a workspace inside the root is removed');
        $this->assertFileExists($outside . '/keep.txt', 'a symlink inside the workspace is unlinked, not followed');
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public function cleanup_helpers(): array
    {
        return [
            'config export builder' => [ExportPackageBuilder::class, 'exports', 'config'],
            'config import session' => [ImportSessionService::class, 'sessions', 'config'],
            'bricks package service' => ['DBVC_Bricks_Portability_Package_Service', 'exports', 'bricks'],
        ];
    }

    /**
     * Export the bricks_addon domain, import it, and apply a new API key id.
     * The backup captures every field of the domain, the API secret included.
     *
     * @return array<string,mixed> The applied session.
     */
    private function apply_bricks_addon_package(): array
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for configuration portability package tests.');
        }

        update_option('dbvc_bricks_api_secret', self::CONFIG_SECRET);
        update_option('dbvc_bricks_api_key_id', 'source-key-id');
        $export = ExportPackageBuilder::create_export(
            ['domains' => ['bricks_addon'], 'fields_by_domain' => ['bricks_addon' => ['dbvc_bricks_api_key_id']]],
            ['profile' => 'agency_baseline']
        );
        $this->assertNotWPError($export);

        update_option('dbvc_bricks_api_key_id', 'target-key-id');
        $session = ImportSessionService::import_uploaded_package([
            'tmp_name' => $export['zip_path'],
            'name' => basename((string) $export['zip_path']),
            'error' => UPLOAD_ERR_OK,
        ]);
        $this->assertNotWPError($session);

        $applied = ImportSessionService::apply_session(
            (string) $session['session_id'],
            ['bricks_addon' => ['dbvc_bricks_api_key_id' => ['action' => 'replace', 'value' => 'applied-key-id']]],
            ['confirm_apply' => true]
        );
        $this->assertNotWPError($applied);
        $this->assertSame('applied-key-id', get_option('dbvc_bricks_api_key_id'));
        $this->assertSame(self::CONFIG_SECRET, get_option('dbvc_bricks_api_secret'), 'the excluded secret is not applied');

        return $applied;
    }

    /**
     * @return array<string,mixed>
     */
    private function bricks_settings(): array
    {
        return [
            'builderMode' => 'dark',
            'apiKeyGoogleMaps' => self::MAPS_KEY,
            'mailchimpApiKey' => self::MAILCHIMP_KEY,
            'nested' => ['recaptchaSecretKey' => self::MAPS_KEY . '-nested', 'enabled' => true],
        ];
    }

    /**
     * A configuration backup in the shape written before the vault.
     *
     * @return array<string,mixed>
     */
    private function legacy_config_backup(): array
    {
        return [
            'record_type' => 'configuration_portability_backup',
            'backup_id' => 'dbvc-config-backup-legacy',
            'domains' => [
                'bricks_addon' => [
                    'domain' => 'bricks_addon',
                    'values' => [
                        'dbvc_bricks_api_secret' => ['option_key' => 'dbvc_bricks_api_secret', 'existed' => true, 'value' => self::CONFIG_SECRET],
                        'dbvc_bricks_mothership_url' => ['option_key' => 'dbvc_bricks_mothership_url', 'existed' => true, 'value' => 'https://example.test'],
                    ],
                ],
                'content_collector' => [
                    'domain' => 'content_collector',
                    'option_key' => 'dbvc_cc_settings',
                    'existed' => true,
                    'value' => ['openai_api_key' => self::CONFIG_SECRET . '-cc', 'storage_path' => 'contentcollector'],
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function legacy_bricks_backup(): array
    {
        return [
            'record_type' => 'backup',
            'backup_id' => 'bricks-portability-backup-legacy',
            'options' => [
                'bricks_global_settings' => $this->bricks_settings(),
                'bricks_license_key' => self::LICENSE_KEY,
            ],
            'media_state' => [],
        ];
    }

    private function seed_backup(string $dir, array $document): string
    {
        wp_mkdir_p($dir);
        file_put_contents($dir . '/backup.json', wp_json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $dir . '/backup.json';
    }

    private function read_bricks_backup(string $backup_id): string
    {
        $dir = DBVC_Bricks_Portability_Storage::resolve_backup_directory($backup_id);
        $this->assertNotWPError($dir);
        return (string) file_get_contents($dir . '/backup.json');
    }

    private function autoload_of(string $option): string
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option));
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
