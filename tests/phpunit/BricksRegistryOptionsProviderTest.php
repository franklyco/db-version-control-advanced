<?php

class BricksRegistryOptionsProviderTest extends WP_UnitTestCase
{
    private $sync_option_before;
    private $option_values_before = [];
    private $test_sync_dir = '';

    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->sync_option_before = get_option('dbvc_sync_path', null);

        foreach (DBVC_Bricks_Registry_Options_Provider::OPTION_KEYS as $option_key) {
            $this->option_values_before[$option_key] = get_option($option_key, null);
        }

        $this->test_sync_dir = trailingslashit(WP_CONTENT_DIR)
            . 'uploads/dbvc-bricks-registry-test-'
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
        foreach ($this->option_values_before as $option_key => $value) {
            if ($value === null) {
                delete_option($option_key);
            } else {
                update_option($option_key, $value);
            }
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

    public function test_envelope_contains_only_the_exact_allowlist(): void
    {
        $options = $this->valid_options();
        $options['unrelated_secret'] = 'must-not-export';

        $envelope = DBVC_Bricks_Registry_Options_Provider::build_envelope($options);

        $this->assertIsArray($envelope);
        $this->assertSame(
            DBVC_Bricks_Registry_Options_Provider::OPTION_KEYS,
            array_keys($envelope['options'])
        );
        $this->assertArrayNotHasKey('unrelated_secret', $envelope['options']);
        $this->assertTrue($envelope['validation']['source_complete']);
        $this->assertTrue($envelope['validation']['registry_integrity_ready']);
        $this->assertFalse($envelope['scope']['credentials_included']);
        $this->assertFalse($envelope['scope']['writes_wordpress']);
    }

    public function test_source_completeness_and_registry_integrity_are_separate(): void
    {
        $options = $this->valid_options();
        $options['bricks_global_classes'][0]['category'] = 'missing-category';

        $envelope = DBVC_Bricks_Registry_Options_Provider::build_envelope($options);

        $this->assertTrue($envelope['validation']['source_complete']);
        $this->assertFalse($envelope['validation']['registry_integrity_ready']);
        $this->assertSame(
            ['missing-category'],
            $envelope['validation']['orphan_class_category_ids']
        );
    }

    public function test_duplicate_ids_fail_source_completeness(): void
    {
        $options = $this->valid_options();
        $options['bricks_global_variables'][] = [
            'id' => 'variable-one',
            'name' => '--duplicate',
        ];

        $envelope = DBVC_Bricks_Registry_Options_Provider::build_envelope($options);

        $this->assertFalse($envelope['validation']['source_complete']);
        $this->assertSame(
            ['variable-one'],
            $envelope['validation']['duplicate_ids']['bricks_global_variables']
        );
    }

    public function test_export_writes_dedicated_file_without_touching_generic_options(): void
    {
        foreach ($this->valid_options() as $option_key => $value) {
            update_option($option_key, $value, false);
        }

        $generic_path = trailingslashit($this->test_sync_dir) . 'options.json';
        file_put_contents($generic_path, '{"sentinel":"unchanged"}');
        $generic_hash_before = hash_file('sha256', $generic_path);

        $result = DBVC_Bricks_Registry_Options_Provider::export();
        $registry_path = trailingslashit($this->test_sync_dir)
            . DBVC_Bricks_Registry_Options_Provider::FILENAME;

        $this->assertIsArray($result);
        $this->assertTrue($result['written']);
        $this->assertFileExists($registry_path);
        $this->assertSame($generic_hash_before, hash_file('sha256', $generic_path));

        $decoded = json_decode((string) file_get_contents($registry_path), true);
        $this->assertSame(DBVC_Bricks_Registry_Options_Provider::SCHEMA, $decoded['schema']);
        $this->assertSame(
            DBVC_Bricks_Registry_Options_Provider::OPTION_KEYS,
            $decoded['option_keys']
        );
    }

    private function valid_options(): array
    {
        return [
            'bricks_global_classes_categories' => [
                ['id' => 'class-category', 'name' => 'Core'],
            ],
            'bricks_global_classes' => [
                [
                    'id' => 'class-one',
                    'name' => 'vfSection',
                    'category' => 'class-category',
                    'settings' => [],
                ],
            ],
            'bricks_global_variables_categories' => [
                ['id' => 'variable-category', 'name' => 'Tokens'],
            ],
            'bricks_global_variables' => [
                [
                    'id' => 'variable-one',
                    'name' => '--space-m',
                    'value' => '1rem',
                    'category' => 'variable-category',
                ],
            ],
        ];
    }
}
