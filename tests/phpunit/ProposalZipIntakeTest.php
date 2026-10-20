<?php

/**
 * @group proposal-diff
 */
class ProposalZipIntakeTest extends WP_UnitTestCase
{
    private $temporary_files = [];
    private $proposal_ids = [];
    private $resource_limit_filters = [];

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option('dbvc_resolver_decisions');
    }

    public function tear_down(): void
    {
        foreach ($this->resource_limit_filters as $callback) {
            remove_filter('dbvc_proposal_zip_resource_limits', $callback);
        }
        $this->resource_limit_filters = [];
        foreach ($this->proposal_ids as $proposal_id) {
            $this->delete_directory(
                trailingslashit(DBVC_Backup_Manager::get_base_path()) . sanitize_file_name($proposal_id)
            );
        }
        foreach ($this->temporary_files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        delete_option('dbvc_resolver_decisions');
        parent::tear_down();
    }

    /**
     * @dataProvider unsafe_entry_provider
     */
    public function test_rejects_unsafe_entry_paths(string $entry_name): void
    {
        $proposal_id = 'zip-unsafe-' . substr(md5($entry_name), 0, 10);
        $zip_path = $this->create_zip([
            'manifest.json' => $this->manifest($proposal_id, []),
            $entry_name     => 'unsafe',
        ]);

        $result = $this->import_zip($zip_path, $proposal_id);

        $this->assertWPError($result);
        $this->assertSame('dbvc_zip_unsafe_entry', $result->get_error_code());
        $this->assertSame('unsafe_path', $result->get_error_data()['reason'] ?? '');
        $this->assertStringNotContainsString('escape', strtolower($result->get_error_message()));
        $this->assertDirectoryDoesNotExist($this->get_proposal_dir($proposal_id));
    }

    public function unsafe_entry_provider(): array
    {
        return [
            'parent traversal'           => ['../escape.txt'],
            'nested parent traversal'    => ['payload/../../escape.txt'],
            'backslash traversal'        => ['payload\\..\\escape.txt'],
            'unix absolute path'         => ['/absolute/escape.txt'],
            'windows drive path'         => ['C:\\absolute\\escape.txt'],
            'windows network path'       => ['\\\\server\\share\\escape.txt'],
            'empty path segment'         => ['payload//escape.txt'],
        ];
    }

    public function test_rejects_symbolic_link_entry(): void
    {
        if (! method_exists('ZipArchive', 'setExternalAttributesName')) {
            $this->markTestSkipped('Zip external attributes are unavailable.');
        }

        $proposal_id = 'zip-symbolic-link';
        $zip_path = $this->create_zip(
            [
                'manifest.json' => $this->manifest($proposal_id, []),
                'linked.json'   => 'manifest.json',
            ],
            static function (ZipArchive $zip): void {
                $unix_opsys = defined('ZipArchive::OPSYS_UNIX')
                    ? constant('ZipArchive::OPSYS_UNIX')
                    : 3;
                $zip->setExternalAttributesName('linked.json', $unix_opsys, 0120777 << 16);
            }
        );

        $result = $this->import_zip($zip_path, $proposal_id);

        $this->assertWPError($result);
        $this->assertSame('dbvc_zip_symlink_entry', $result->get_error_code());
        $this->assertDirectoryDoesNotExist($this->get_proposal_dir($proposal_id));
    }

    public function test_rejects_mixed_wrapper_layout(): void
    {
        $proposal_id = 'zip-mixed-wrapper';
        $zip_path = $this->create_zip([
            'bundle/manifest.json' => $this->manifest($proposal_id, []),
            'outside.json'         => '{}',
        ]);

        $result = $this->import_zip($zip_path, $proposal_id);

        $this->assertWPError($result);
        $this->assertSame('dbvc_zip_layout_invalid', $result->get_error_code());
        $this->assertSame('mixed_bundle_roots', $result->get_error_data()['reason'] ?? '');
    }

    public function test_rejects_multiple_manifests(): void
    {
        $proposal_id = 'zip-multiple-manifests';
        $zip_path = $this->create_zip([
            'manifest.json'        => $this->manifest($proposal_id, []),
            'bundle/manifest.json' => $this->manifest($proposal_id, []),
        ]);

        $result = $this->import_zip($zip_path, $proposal_id);

        $this->assertWPError($result);
        $this->assertSame('dbvc_zip_layout_invalid', $result->get_error_code());
        $this->assertSame('multiple_manifests', $result->get_error_data()['reason'] ?? '');
    }

    public function test_rejects_case_colliding_archive_entries(): void
    {
        $proposal_id = 'zip-case-collision';
        $zip_path = $this->create_zip([
            'manifest.json' => $this->manifest($proposal_id, []),
            'Payload.json'  => '{}',
            'payload.json'  => '{}',
        ]);

        $result = $this->import_zip($zip_path, $proposal_id);

        $this->assertWPError($result);
        $this->assertSame('dbvc_zip_unsafe_entry', $result->get_error_code());
        $this->assertSame('duplicate_path', $result->get_error_data()['reason'] ?? '');
    }

    public function test_rejects_manifest_payload_path_that_is_not_in_archive(): void
    {
        $proposal_id = 'zip-missing-payload';
        $manifest = $this->manifest($proposal_id, [
            [
                'item_type' => 'options',
                'path'      => 'options.json',
            ],
        ]);
        $zip_path = $this->create_zip(['manifest.json' => $manifest]);

        $result = $this->import_zip($zip_path, $proposal_id);

        $this->assertWPError($result);
        $this->assertSame('dbvc_manifest_file_missing', $result->get_error_code());
        $this->assertDirectoryDoesNotExist($this->get_proposal_dir($proposal_id));
    }

    public function test_rejects_case_mismatched_manifest_payload_path(): void
    {
        $proposal_id = 'zip-case-mismatched-payload';
        $manifest = $this->manifest($proposal_id, [
            [
                'item_type' => 'options',
                'path'      => 'options.json',
            ],
        ]);
        $zip_path = $this->create_zip([
            'manifest.json' => $manifest,
            'Options.json'  => '{}',
        ]);

        $result = $this->import_zip($zip_path, $proposal_id);

        $this->assertWPError($result);
        $this->assertSame('dbvc_manifest_file_missing', $result->get_error_code());
        $this->assertDirectoryDoesNotExist($this->get_proposal_dir($proposal_id));
    }

    public function test_rejects_unsafe_manifest_payload_path(): void
    {
        $proposal_id = 'zip-unsafe-manifest-path';
        $manifest = $this->manifest($proposal_id, [
            [
                'item_type' => 'options',
                'path'      => '../options.json',
            ],
        ]);
        $zip_path = $this->create_zip([
            'manifest.json' => $manifest,
            'options.json'  => '{}',
        ]);

        $result = $this->import_zip($zip_path, $proposal_id);

        $this->assertWPError($result);
        $this->assertSame('dbvc_manifest_path_invalid', $result->get_error_code());
        $this->assertDirectoryDoesNotExist($this->get_proposal_dir($proposal_id));
    }

    public function test_rejects_executable_archive_payload(): void
    {
        $proposal_id = 'zip-executable-payload';
        $zip_path = $this->create_zip([
            'manifest.json' => $this->manifest($proposal_id, []),
            'shell.php'     => '<?php echo "unsafe";',
        ]);

        $result = $this->import_zip($zip_path, $proposal_id);

        $this->assertWPError($result);
        $this->assertSame('dbvc_zip_unsupported_entry', $result->get_error_code());
        $this->assertSame('executable_entry', $result->get_error_data()['reason'] ?? '');
        $this->assertDirectoryDoesNotExist($this->get_proposal_dir($proposal_id));
    }

    public function test_rejects_archive_when_entry_count_exceeds_limit(): void
    {
        $proposal_id = 'zip-entry-count-limit';
        $this->set_resource_limits(['max_entries' => 2]);
        $zip_path = $this->create_zip([
            'manifest.json' => $this->manifest($proposal_id, []),
            'first.json'    => '{}',
            'second.json'   => '{}',
        ]);

        $result = $this->import_zip($zip_path, $proposal_id);

        $this->assertWPError($result);
        $this->assertSame('dbvc_zip_resource_limit', $result->get_error_code());
        $this->assertSame('entry_count_exceeded', $result->get_error_data()['reason'] ?? '');
        $this->assertSame(3, $result->get_error_data()['actual_entries'] ?? null);
        $this->assertSame(2, $result->get_error_data()['max_entries'] ?? null);
        $this->assertDirectoryDoesNotExist($this->get_proposal_dir($proposal_id));
    }

    public function test_rejects_archive_when_entry_expanded_size_exceeds_limit(): void
    {
        $proposal_id = 'zip-entry-size-limit';
        $manifest = $this->manifest($proposal_id, []);
        $entry_limit = strlen($manifest) + 16;
        $this->set_resource_limits(['max_entry_uncompressed_bytes' => $entry_limit]);
        $zip_path = $this->create_zip([
            'manifest.json' => $manifest,
            'large.txt'     => str_repeat('entry-limit-', $entry_limit),
        ]);

        $result = $this->import_zip($zip_path, $proposal_id);

        $this->assertWPError($result);
        $this->assertSame('dbvc_zip_resource_limit', $result->get_error_code());
        $this->assertSame('entry_size_exceeded', $result->get_error_data()['reason'] ?? '');
        $this->assertSame($entry_limit, $result->get_error_data()['max_entry_uncompressed_bytes'] ?? null);
        $this->assertDirectoryDoesNotExist($this->get_proposal_dir($proposal_id));
    }

    public function test_rejects_archive_when_total_expanded_size_exceeds_limit(): void
    {
        $proposal_id = 'zip-total-size-limit';
        $manifest = $this->manifest($proposal_id, []);
        $payload = str_repeat('total-limit-', 20);
        $total_limit = strlen($manifest) + strlen($payload) - 1;
        $this->set_resource_limits(['max_total_uncompressed_bytes' => $total_limit]);
        $zip_path = $this->create_zip([
            'manifest.json' => $manifest,
            'payload.txt'   => $payload,
        ]);

        $result = $this->import_zip($zip_path, $proposal_id);

        $this->assertWPError($result);
        $this->assertSame('dbvc_zip_resource_limit', $result->get_error_code());
        $this->assertSame('total_size_exceeded', $result->get_error_data()['reason'] ?? '');
        $this->assertSame($total_limit, $result->get_error_data()['max_total_uncompressed_bytes'] ?? null);
        $this->assertDirectoryDoesNotExist($this->get_proposal_dir($proposal_id));
    }

    public function test_rejects_archive_when_compression_ratio_exceeds_limit(): void
    {
        $proposal_id = 'zip-compression-ratio-limit';
        $this->set_resource_limits(['max_compression_ratio' => 10.0]);
        $zip_path = $this->create_zip([
            'manifest.json' => $this->manifest($proposal_id, []),
            'compressed.txt' => str_repeat('A', 16384),
        ]);

        $result = $this->import_zip($zip_path, $proposal_id);

        $this->assertWPError($result);
        $this->assertSame('dbvc_zip_resource_limit', $result->get_error_code());
        $this->assertSame('compression_ratio_exceeded', $result->get_error_data()['reason'] ?? '');
        $this->assertSame(10.0, $result->get_error_data()['max_compression_ratio'] ?? null);
        $this->assertDirectoryDoesNotExist($this->get_proposal_dir($proposal_id));
    }

    public function test_accepts_archive_immediately_below_each_resource_limit(): void
    {
        $proposal_id = 'zip-resource-limit-valid';
        $manifest = $this->manifest($proposal_id, []);
        $zip_path = $this->create_zip(
            [
                'manifest.json' => $manifest,
                'payload.txt'   => str_repeat('valid-limit-payload-', 10),
            ],
            static function (ZipArchive $zip): void {
                $zip->addEmptyDir('empty-directory');
            }
        );
        $usage = $this->read_zip_resource_usage($zip_path);
        $this->set_resource_limits([
            'max_entries'                  => $usage['entries'] + 1,
            'max_entry_uncompressed_bytes' => $usage['max_entry_uncompressed_bytes'] + 1,
            'max_total_uncompressed_bytes' => $usage['total_uncompressed_bytes'] + 1,
            'max_compression_ratio'         => $usage['max_compression_ratio'] + 0.01,
        ]);

        $result = $this->import_zip($zip_path, $proposal_id);

        $this->assertIsArray($result);
        $this->assertSame($proposal_id, $result['proposal_id']);
        $this->assertFileExists($this->get_proposal_dir($proposal_id) . '/manifest.json');
        $this->assertDirectoryExists($this->get_proposal_dir($proposal_id) . '/empty-directory');
    }

    public function test_invalid_resource_limit_filter_values_fall_back_to_defaults(): void
    {
        $this->set_resource_limits([
            'max_entries'                  => 0,
            'max_entry_uncompressed_bytes' => -1,
            'max_total_uncompressed_bytes' => 'invalid',
            'max_compression_ratio'         => INF,
        ]);
        $method = new ReflectionMethod('DBVC_Admin_App', 'get_proposal_zip_resource_limits');
        $method->setAccessible(true);

        $limits = $method->invoke(null, '/private/tmp/proposal.zip');

        $this->assertSame(10000, $limits['max_entries']);
        $this->assertSame(268435456, $limits['max_entry_uncompressed_bytes']);
        $this->assertSame(1073741824, $limits['max_total_uncompressed_bytes']);
        $this->assertSame(200.0, $limits['max_compression_ratio']);
    }

    /**
     * @dataProvider invalid_resource_stat_provider
     */
    public function test_rejects_unusable_zip_entry_size_metadata($stat, bool $is_directory, string $reason): void
    {
        $method = new ReflectionMethod('DBVC_Admin_App', 'evaluate_proposal_zip_entry_resource_usage');
        $method->setAccessible(true);
        $result = $method->invoke(null, $stat, $is_directory, 100, [
            'max_entry_uncompressed_bytes' => 100,
            'max_total_uncompressed_bytes' => 200,
            'max_compression_ratio'         => 20.0,
        ], 0);

        $this->assertFalse($result['valid']);
        $this->assertSame($reason, $result['reason']);
    }

    public function invalid_resource_stat_provider(): array
    {
        return [
            'missing stats'                => [false, false, 'entry_stats_missing'],
            'negative expanded size'       => [['size' => -1, 'comp_size' => 1], false, 'entry_size_invalid'],
            'non-numeric compressed size'  => [['size' => 10, 'comp_size' => 'unknown'], false, 'entry_compressed_size_invalid'],
            'zero compressed nonempty file'=> [['size' => 10, 'comp_size' => 0], false, 'entry_compressed_size_invalid'],
            'compressed size beyond archive'=> [['size' => 10, 'comp_size' => 101], false, 'entry_compressed_size_inconsistent'],
            'directory carries file bytes' => [['size' => 1, 'comp_size' => 1], true, 'directory_size_invalid'],
        ];
    }

    public function test_accepts_canonical_root_layout(): void
    {
        $proposal_id = 'zip-valid-root';
        $manifest = $this->manifest($proposal_id, [
            [
                'item_type' => 'options',
                'path'      => 'options.json',
            ],
        ]);
        $zip_path = $this->create_zip([
            'manifest.json' => $manifest,
            'options.json'  => wp_json_encode(['site_title' => 'Root layout']),
        ]);

        $result = $this->import_zip($zip_path, $proposal_id);

        $this->assertIsArray($result);
        $this->assertSame($proposal_id, $result['proposal_id']);
        $this->assertFileExists($this->get_proposal_dir($proposal_id) . '/manifest.json');
        $this->assertFileExists($this->get_proposal_dir($proposal_id) . '/options.json');
    }

    public function test_accepts_single_top_level_wrapper(): void
    {
        $proposal_id = 'zip-valid-wrapper';
        $manifest = $this->manifest($proposal_id, [
            [
                'item_type' => 'options',
                'path'      => 'options.json',
            ],
        ]);
        $zip_path = $this->create_zip([
            'bundle/manifest.json' => $manifest,
            'bundle/options.json'  => wp_json_encode(['site_title' => 'Wrapper layout']),
        ]);

        $result = $this->import_zip($zip_path, $proposal_id);

        $this->assertIsArray($result);
        $this->assertSame($proposal_id, $result['proposal_id']);
        $this->assertFileExists($this->get_proposal_dir($proposal_id) . '/manifest.json');
        $this->assertFileExists($this->get_proposal_dir($proposal_id) . '/options.json');
    }

    public function test_upload_seeds_only_missing_proposal_resolver_decisions_and_keeps_global_rules_local(): void
    {
        $proposal_id = 'zip-resolver-seed-authority';
        $live_proposal_decision = [
            'action'   => 'skip',
            'saved_at' => '2026-07-20 12:00:00',
        ];
        $live_global_rules = [
            '44' => [
                'action'    => 'map',
                'target_id' => 9044,
                'saved_at'  => '2026-07-20 12:00:00',
            ],
            '45' => [
                'action'   => 'skip',
                'saved_at' => '2026-07-20 12:00:00',
            ],
        ];
        update_option('dbvc_resolver_decisions', [
            '__global'   => $live_global_rules,
            $proposal_id => [
                '44' => $live_proposal_decision,
            ],
        ], false);

        $zip_path = $this->create_zip([
            'manifest.json' => $this->manifest($proposal_id, [], [
                'resolver_decisions' => [
                    'proposal' => [
                        '44' => ['action' => 'download', 'saved_at' => '2026-07-19 12:00:00'],
                        '45' => ['action' => 'download', 'saved_at' => '2026-07-19 12:00:00'],
                        '47' => ['action' => 'download', 'saved_at' => '2026-07-19 12:00:00'],
                    ],
                    'global' => [
                        '44' => ['action' => 'skip', 'saved_at' => '2026-07-19 12:00:00'],
                        '46' => ['action' => 'skip', 'saved_at' => '2026-07-19 12:00:00'],
                    ],
                ],
            ]),
        ]);

        $result = $this->import_zip($zip_path, $proposal_id);
        $store = get_option('dbvc_resolver_decisions', []);

        $this->assertIsArray($result);
        $this->assertSame($live_proposal_decision, $store[$proposal_id]['44']);
        $this->assertArrayNotHasKey('45', $store[$proposal_id]);
        $this->assertSame('download', $store[$proposal_id]['47']['action'] ?? null);
        $this->assertSame($live_global_rules, $store['__global']);
        $this->assertArrayNotHasKey('46', $store['__global']);
        $this->assertSame('skip', DBVC_Media_Sync::get_effective_resolver_decisions($proposal_id)['45']['action']);
        $this->assertSame('global', DBVC_Media_Sync::get_effective_resolver_decisions($proposal_id)['45']['source']);
    }

    public function test_proposal_apply_does_not_replay_archived_resolver_choices(): void
    {
        $proposal_id = 'zip-resolver-apply-authority';
        $zip_path = $this->create_zip([
            'manifest.json' => $this->manifest($proposal_id, [], [
                'resolver_decisions' => [
                    'proposal' => [
                        '51' => ['action' => 'download', 'saved_at' => '2026-07-19 12:00:00'],
                    ],
                    'global' => [
                        '52' => ['action' => 'download', 'saved_at' => '2026-07-19 12:00:00'],
                    ],
                ],
            ]),
        ]);
        $upload = $this->import_zip($zip_path, $proposal_id);
        $this->assertIsArray($upload);

        $live_store = get_option('dbvc_resolver_decisions', []);
        $live_store[$proposal_id]['51'] = [
            'action'   => 'skip',
            'saved_at' => '2026-07-20 12:00:00',
        ];
        $live_store['__global']['52'] = [
            'action'    => 'map',
            'target_id' => 9052,
            'saved_at'  => '2026-07-20 12:00:00',
        ];
        update_option('dbvc_resolver_decisions', $live_store, false);

        $result = DBVC_Sync_Posts::import_backup($proposal_id, ['mode' => 'proposal-test']);
        $store_after = get_option('dbvc_resolver_decisions', []);

        $this->assertIsArray($result);
        $this->assertSame($live_store, $store_after);
        $this->assertSame('skip', DBVC_Media_Sync::get_effective_resolver_decisions($proposal_id)['51']['action']);
        $this->assertSame('proposal', DBVC_Media_Sync::get_effective_resolver_decisions($proposal_id)['51']['source']);
        $this->assertSame('map', DBVC_Media_Sync::get_effective_resolver_decisions($proposal_id)['52']['action']);
        $this->assertSame('global', DBVC_Media_Sync::get_effective_resolver_decisions($proposal_id)['52']['source']);
    }

    public function test_accepts_canonical_backup_security_files(): void
    {
        $proposal_id = 'zip-valid-security-files';
        $htaccess = "# Protect DBVC backup files from direct web access\n"
            . "Order allow,deny\n"
            . "Deny from all\n\n"
            . "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "</IfModule>\n\n"
            . 'Options -Indexes';
        $zip_path = $this->create_zip([
            'manifest.json' => $this->manifest($proposal_id, []),
            '.htaccess'     => $htaccess,
            'index.php'     => "<?php\n// Silence is golden.\nexit;",
        ]);

        $result = $this->import_zip($zip_path, $proposal_id);

        $this->assertIsArray($result);
        $this->assertFileExists($this->get_proposal_dir($proposal_id) . '/.htaccess');
        $this->assertFileExists($this->get_proposal_dir($proposal_id) . '/index.php');
    }

    private function manifest(string $proposal_id, array $items, array $extra = []): string
    {
        return wp_json_encode(array_merge([
            'schema_version' => 3,
            'backup_name'    => $proposal_id,
            'status'         => 'draft',
            'generated_at'   => gmdate('c'),
            'items'          => $items,
            'totals'         => [
                'files'               => count($items),
                'media_items'         => 0,
                'missing_import_hash' => 0,
            ],
        ], $extra));
    }

    private function create_zip(array $entries, ?callable $configure = null): string
    {
        $source = wp_tempnam('dbvc-proposal.zip');
        $zip_path = $source . '.zip';
        @rename($source, $zip_path);
        $this->temporary_files[] = $source;
        $this->temporary_files[] = $zip_path;

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $contents) {
            $this->assertTrue($zip->addFromString($name, (string) $contents));
        }
        if ($configure) {
            $configure($zip);
        }
        $zip->close();

        return $zip_path;
    }

    private function set_resource_limits(array $overrides): void
    {
        $callback = static function (array $defaults) use ($overrides): array {
            return array_merge($defaults, $overrides);
        };
        add_filter('dbvc_proposal_zip_resource_limits', $callback);
        $this->resource_limit_filters[] = $callback;
    }

    private function read_zip_resource_usage(string $zip_path): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path));
        $total = 0;
        $max_entry = 0;
        $max_ratio = 1.0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $this->assertIsArray($stat);
            $size = (int) ($stat['size'] ?? 0);
            $compressed = (int) ($stat['comp_size'] ?? 0);
            $total += $size;
            $max_entry = max($max_entry, $size);
            if ($size > 0 && $compressed > 0) {
                $max_ratio = max($max_ratio, $size / $compressed);
            }
        }
        $entries = $zip->numFiles;
        $zip->close();

        return [
            'entries'                     => $entries,
            'max_entry_uncompressed_bytes'=> $max_entry,
            'total_uncompressed_bytes'    => $total,
            'max_compression_ratio'        => $max_ratio,
        ];
    }

    private function import_zip(string $zip_path, string $proposal_id)
    {
        $this->proposal_ids[] = $proposal_id;
        return DBVC_Admin_App::import_proposal_from_zip($zip_path, [
            'preferred_id' => $proposal_id,
        ]);
    }

    private function get_proposal_dir(string $proposal_id): string
    {
        return trailingslashit(DBVC_Backup_Manager::get_base_path()) . sanitize_file_name($proposal_id);
    }

    private function delete_directory(string $directory): void
    {
        if (! is_dir($directory)) {
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
