<?php

class ProposalInspectionCliTest extends WP_UnitTestCase
{
    private $proposal_id = '';
    private $proposal_dir = '';
    private $snapshot_dir = '';

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->proposal_id = 'cli-proposal-' . strtolower(wp_generate_password(8, false, false));
        $uploads = wp_get_upload_dir();
        $this->proposal_dir = trailingslashit($uploads['basedir']) . 'sync/db-version-control-backups/' . $this->proposal_id;
        $this->snapshot_dir = trailingslashit($uploads['basedir']) . 'sync/db-version-control-snapshots/' . $this->proposal_id;
        wp_mkdir_p($this->proposal_dir);
        wp_mkdir_p($this->snapshot_dir);
        $this->seed_manifest();
        $this->seed_options();
        file_put_contents(trailingslashit($this->snapshot_dir) . 'entity-alpha.json', wp_json_encode(['private' => 'snapshot value']));
    }

    public function tear_down(): void
    {
        $this->delete_directory($this->proposal_dir);
        $this->delete_directory($this->snapshot_dir);
        delete_option('dbvc_proposal_decisions');
        delete_option('dbvc_resolver_decisions');
        delete_option('dbvc_proposal_snapshot_states');
        delete_option('dbvc_locked_backups');
        parent::tear_down();
    }

    public function test_show_returns_conservative_structural_preflight_without_mutation(): void
    {
        $before_tree = $this->tree_fingerprint();
        $before_options = $this->option_fingerprint();

        $result = DBVC_Proposal_CLI_Inspector::show_proposal($this->proposal_id, []);

        $this->assertIsArray($result);
        $this->assertSame($this->proposal_id, $result['proposal']['id']);
        $this->assertSame(3, $result['manifest']['item_count']);
        $this->assertSame(2, $result['manifest']['type_counts']['post']);
        $this->assertSame(1, $result['manifest']['type_counts']['term']);
        $this->assertSame(1, $result['duplicates']['groups']);
        $this->assertSame(2, $result['duplicates']['items']);
        $this->assertSame(2, $result['decisions']['total']);
        $this->assertSame(2, $result['resolver']['declared_media_items']);
        $this->assertSame(2, $result['resolver']['decision_coverage']);
        $this->assertFalse($result['resolver']['live_resolution_evaluated']);
        $this->assertSame(2, $result['snapshots']['eligible']);
        $this->assertSame(1, $result['snapshots']['present']);
        $this->assertFalse($result['snapshots']['trust_evaluated']);
        $this->assertSame('known_blockers', $result['readiness']['state']);
        $this->assertNull($result['readiness']['authoritative_apply_ready']);
        $this->assertSame(['hashes' => 1, 'duplicates' => 1], $result['readiness']['known_blockers']);
        $this->assertSame($before_tree, $this->tree_fingerprint());
        $this->assertSame($before_options, $this->option_fingerprint());

        $encoded = wp_json_encode($result);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('Sensitive Proposal Title', $encoded);
        $this->assertStringNotContainsString('private.example', $encoded);
        $this->assertStringNotContainsString('snapshot value', $encoded);
        $this->assertStringNotContainsString('/posts/private.json', $encoded);
    }

    public function test_entities_returns_bounded_sanitized_rows_and_hash_metadata(): void
    {
        $before_tree = $this->tree_fingerprint();
        $result = DBVC_Proposal_CLI_Inspector::list_entities($this->proposal_id, [
            'entity-type' => 'post',
            'snapshot-state' => 'present',
            'limit' => 1,
        ]);

        $this->assertIsArray($result);
        $this->assertSame(2, $result['total_matching']);
        $this->assertSame(1, $result['returned']);
        $this->assertTrue($result['has_more']);
        $this->assertSame('entity-alpha', $result['items'][0]['entity_uid']);
        $this->assertSame('post', $result['items'][0]['entity_type']);
        $this->assertSame('page', $result['items'][0]['object_type']);
        $this->assertSame('present', $result['items'][0]['snapshot_state']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['items'][0]['snapshot_sha256']);
        $this->assertSame(2, $result['items'][0]['duplicate_group_size']);
        $this->assertSame(2, $result['items'][0]['media_reference_count']);
        $this->assertSame(2, $result['items'][0]['decisions']['total']);
        $this->assertArrayNotHasKey('post_title', $result['items'][0]);
        $this->assertArrayNotHasKey('path', $result['items'][0]);
        $this->assertArrayNotHasKey('media_refs', $result['items'][0]);
        $this->assertSame($before_tree, $this->tree_fingerprint());
    }

    public function test_entities_pagination_and_filters_are_bounded(): void
    {
        $page = DBVC_Proposal_CLI_Inspector::list_entities($this->proposal_id, [
            'limit' => 1,
            'offset' => 1,
        ]);
        $terms = DBVC_Proposal_CLI_Inspector::list_entities($this->proposal_id, [
            'object-type' => 'category',
            'limit' => 500,
        ]);

        $this->assertIsArray($page);
        $this->assertSame(3, $page['total_matching']);
        $this->assertSame(1, $page['returned']);
        $this->assertTrue($page['has_more']);
        $this->assertIsArray($terms);
        $this->assertSame(100, $terms['limit']);
        $this->assertSame(1, $terms['total_matching']);
        $this->assertSame('term', $terms['items'][0]['entity_type']);
    }

    public function test_snapshot_symlink_outside_exact_proposal_directory_is_not_read(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlinks are unavailable.');
        }

        $snapshot = trailingslashit($this->snapshot_dir) . 'entity-alpha.json';
        $outside = trailingslashit($this->proposal_dir) . 'outside-snapshot.json';
        file_put_contents($outside, wp_json_encode(['private' => 'outside snapshot value']));
        unlink($snapshot);
        if (! symlink($outside, $snapshot)) {
            $this->markTestSkipped('Could not create the snapshot symlink fixture.');
        }

        $result = DBVC_Proposal_CLI_Inspector::list_entities($this->proposal_id, [
            'entity-type' => 'post',
            'limit' => 1,
        ]);

        $this->assertIsArray($result);
        $this->assertSame('entity-alpha', $result['items'][0]['entity_uid']);
        $this->assertSame('missing', $result['items'][0]['snapshot_state']);
        $this->assertSame('', $result['items'][0]['snapshot_sha256']);
    }

    public function test_read_only_guards_reject_detail_and_writer_flags(): void
    {
        foreach (['raw', 'download', 'detail', 'decision', 'mask', 'cleanup', 'recapture', 'upload', 'delete', 'apply', 'resolver-rule'] as $flag) {
            $show = DBVC_Proposal_CLI_Inspector::show_proposal($this->proposal_id, [$flag => true]);
            $entities = DBVC_Proposal_CLI_Inspector::list_entities($this->proposal_id, [$flag => true]);
            $this->assertWPError($show, $flag);
            $this->assertSame('dbvc_proposal_cli_read_only', $show->get_error_code(), $flag);
            $this->assertWPError($entities, $flag);
            $this->assertSame('dbvc_proposal_cli_read_only', $entities->get_error_code(), $flag);
        }

        $invalid = DBVC_Proposal_CLI_Inspector::show_proposal('../private', []);
        $this->assertWPError($invalid);
        $this->assertSame('dbvc_proposal_cli_id_invalid', $invalid->get_error_code());
    }

    public function test_registration_exposes_only_show_and_entities_inspection(): void
    {
        $source = file_get_contents(DBVC_PLUGIN_PATH . 'commands/class-proposal-inspection-cli.php');
        $router = file_get_contents(DBVC_PLUGIN_PATH . 'commands/class-wp-cli-commands.php');

        $this->assertIsString($source);
        $this->assertIsString($router);
        $this->assertStringContainsString('public function show( $args, $assoc_args )', $router);
        $this->assertStringContainsString('public function entities( $args, $assoc_args )', $router);
        $this->assertStringContainsString("require_once __DIR__ . '/class-proposal-inspection-cli.php'", $router);
        $this->assertStringContainsString('[--fail-on-blockers]', $source);
        $this->assertStringNotContainsString('[--raw]', $source);
        $this->assertStringNotContainsString('[--apply]', $source);
        $this->assertStringNotContainsString('get_proposal_entity(', $source);
        $this->assertStringNotContainsString('get_proposal_readiness(', $source);
        $this->assertStringNotContainsString('DBVC_Backup_Manager::get_base_path(', $source);
        $this->assertStringNotContainsString('DBVC_Snapshot_Manager::', $source);
        $this->assertStringNotContainsString('Resolver::resolve_manifest(', $source);
        $this->assertStringNotContainsString('update_option(', $source);
        $this->assertStringNotContainsString('file_put_contents(', $source);
		$this->assertStringContainsString('self::path_within($file, $proposal_dir)', $source);
    }

    private function seed_manifest(): void
    {
        $manifest = [
            'schema' => 'dbvc-manifest-v3',
            'generated_at' => '2026-08-11T15:00:00Z',
            'backup_name' => $this->proposal_id,
            'status' => 'draft',
            'site' => ['url' => 'https://private.example'],
            'totals' => [
                'files' => 3,
                'missing_import_hash' => 1,
                'media_items' => 2,
            ],
            'items' => [
                [
                    'path' => 'posts/private.json',
                    'hash' => hash('sha256', 'alpha'),
                    'content_hash' => md5('alpha'),
                    'item_type' => 'post',
                    'post_id' => 101,
                    'vf_object_uid' => 'entity-alpha',
                    'post_type' => 'page',
                    'post_title' => 'Sensitive Proposal Title',
                    'post_name' => 'private-page',
                    'has_import_hash' => true,
                    'media_refs' => [
                        'meta' => [['original_id' => 41], ['original_id' => 42]],
                        'content' => [['original_id' => 41]],
                    ],
                ],
                [
                    'path' => 'posts/private-duplicate.json',
                    'hash' => hash('sha256', 'duplicate'),
                    'content_hash' => md5('duplicate'),
                    'item_type' => 'post',
                    'post_id' => 102,
                    'vf_object_uid' => 'entity-alpha',
                    'post_type' => 'page',
                    'post_title' => 'Duplicate Sensitive Title',
                    'post_name' => 'private-page-copy',
                    'has_import_hash' => false,
                    'media_refs' => [],
                ],
                [
                    'path' => 'terms/private.json',
                    'hash' => hash('sha256', 'term'),
                    'content_hash' => md5('term'),
                    'item_type' => 'term',
                    'term_id' => 201,
                    'vf_object_uid' => 'entity-term',
                    'taxonomy' => 'category',
                    'slug' => 'private-term',
                    'name' => 'Private Term',
                    'has_import_hash' => true,
                    'media_refs' => [],
                ],
            ],
            'media_index' => [
                ['original_id' => 41, 'source_url' => 'https://private.example/one.jpg'],
                ['original_id' => 42, 'source_url' => 'https://private.example/two.jpg'],
            ],
            'checksum' => 'sha256:' . hash('sha256', 'declared'),
        ];

        file_put_contents(trailingslashit($this->proposal_dir) . 'manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT));
    }

    private function seed_options(): void
    {
        update_option('dbvc_proposal_decisions', [
            $this->proposal_id => [
                'entity-alpha' => [
                    'post_title' => 'accept',
                    'meta.private' => 'keep',
                ],
            ],
        ], false);
        update_option('dbvc_resolver_decisions', [
            '__global' => [
                '42' => ['action' => 'download'],
            ],
            $this->proposal_id => [
                '41' => ['action' => 'skip'],
            ],
        ], false);
        update_option('dbvc_proposal_snapshot_states', [
            $this->proposal_id => [
                'entity-term' => ['state' => 'failed'],
            ],
        ], false);
    }

    private function option_fingerprint(): string
    {
        return hash('sha256', serialize([
            get_option('dbvc_proposal_decisions', []),
            get_option('dbvc_resolver_decisions', []),
            get_option('dbvc_proposal_snapshot_states', []),
            get_option('dbvc_locked_backups', []),
        ]));
    }

    private function tree_fingerprint(): string
    {
        $rows = [];
        foreach ([$this->proposal_dir, $this->snapshot_dir] as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $rows[] = wp_normalize_path($file->getPathname()) . '|' . $file->getSize() . '|' . $file->getMTime() . '|' . hash_file('sha256', $file->getPathname());
                }
            }
        }
        sort($rows);
        return hash('sha256', implode("\n", $rows));
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
