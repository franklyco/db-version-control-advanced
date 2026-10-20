<?php

class EntityEditorCliTest extends WP_UnitTestCase
{
    /** @var string */
    private $sync_dir = '';

    /** @var string */
    private $sync_option_before = '';

    /** @var string */
    private $post_path = '';

    /** @var string */
    private $term_path = '';

    public function set_up(): void
    {
        parent::set_up();
        $this->sync_option_before = (string) get_option('dbvc_sync_path', '');
        $this->sync_dir = trailingslashit(ABSPATH . 'wp-content/uploads/dbvc-entity-cli-' . wp_generate_password(8, false, false));
        wp_mkdir_p($this->sync_dir);
        $relative = ltrim(str_replace(wp_normalize_path(trailingslashit(ABSPATH)), '', wp_normalize_path($this->sync_dir)), '/');
        update_option('dbvc_sync_path', $relative);
        delete_transient('dbvc_entity_editor_index_v1');

        $post_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Entity CLI Matched Page',
            'post_name' => 'entity-cli-matched-page',
            'post_status' => 'publish',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'entity-cli-matched-page-uid');

        $this->post_path = $this->write_json('page/entity-cli-matched-page-' . $post_id . '.json', [
            'ID' => $post_id,
            'post_type' => 'page',
            'post_title' => 'Entity CLI Matched Page',
            'post_name' => 'entity-cli-matched-page',
            'post_status' => 'publish',
            'vf_object_uid' => 'entity-cli-matched-page-uid',
            'meta' => [
                'alpha' => 'one',
                'beta' => 'two',
            ],
            'tax_input' => [
                'category' => [1],
            ],
        ]);
        $this->term_path = $this->write_json('taxonomy/category/entity-cli-unmatched-term-999999.json', [
            'term_id' => 999999,
            'taxonomy' => 'category',
            'name' => 'Entity CLI Unmatched Term',
            'slug' => 'entity-cli-unmatched-term',
            'vf_object_uid' => 'entity-cli-unmatched-term-uid',
            'meta' => [],
        ]);

        DBVC_Entity_Editor_Indexer::get_index(true);
    }

    public function tear_down(): void
    {
        delete_transient('dbvc_entity_editor_index_v1');
        update_option('dbvc_sync_path', $this->sync_option_before);
        $this->delete_directory($this->sync_dir);
        parent::tear_down();
    }

    public function test_list_reads_cached_index_without_mutating_cache_artifacts(): void
    {
        $cache_path = $this->sync_dir . '.dbvc-entity-index.json';
        $transient_before = get_transient('dbvc_entity_editor_index_v1');
        $disk_hash_before = hash_file('sha256', $cache_path);

        $result = DBVC_Entity_Editor_CLI_Inspector::list_entities([
            'kind' => 'post',
            'match' => 'matched',
            'limit' => 1,
        ]);

        $this->assertIsArray($result);
        $this->assertSame('transient', $result['index']['source']);
        $this->assertSame(1, $result['total_matching']);
        $this->assertSame(1, $result['returned']);
        $this->assertSame($this->post_path, $result['items'][0]['relative_path']);
        $this->assertSame('matched', $result['items'][0]['match_status']);
        $this->assertSame($transient_before, get_transient('dbvc_entity_editor_index_v1'));
        $this->assertSame($disk_hash_before, hash_file('sha256', $cache_path));
    }

    public function test_list_filters_unmatched_terms_and_uses_disk_fallback(): void
    {
        delete_transient('dbvc_entity_editor_index_v1');

        $result = DBVC_Entity_Editor_CLI_Inspector::list_entities([
            'kind' => 'term',
            'match' => 'unmatched',
            'search' => 'unmatched-term',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('disk', $result['index']['source']);
        $this->assertCount(1, $result['items']);
        $this->assertSame($this->term_path, $result['items'][0]['relative_path']);
        $this->assertSame('unmatched', $result['items'][0]['match_status']);
        $this->assertFalse(get_transient('dbvc_entity_editor_index_v1'));
    }

    public function test_inspect_returns_metadata_hash_and_counts_without_raw_values(): void
    {
        $result = DBVC_Entity_Editor_CLI_Inspector::inspect_entity($this->post_path);

        $this->assertIsArray($result);
        $this->assertSame($this->post_path, $result['file']['relative_path']);
        $this->assertSame('post', $result['entity']['entity_kind']);
        $this->assertSame('matched', $result['entity']['match_status']);
        $this->assertSame(2, $result['file']['meta_key_count']);
        $this->assertSame(1, $result['file']['taxonomy_count']);
        $this->assertGreaterThan(0, $result['file']['bytes']);
        $this->assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $result['file']['sha256']);
        $this->assertArrayNotHasKey('content', $result);
        $this->assertArrayNotHasKey('decoded', $result);
        $this->assertArrayNotHasKey('content', $result['file']);
        $this->assertArrayNotHasKey('decoded', $result['file']);
    }

    public function test_missing_or_stale_cache_stops_without_rebuilding(): void
    {
        $payload = get_transient('dbvc_entity_editor_index_v1');
        $this->assertIsArray($payload);
        $payload['generated_at'] = gmdate('c', time() - 3600);
        set_transient('dbvc_entity_editor_index_v1', $payload, 300);

        $stale = DBVC_Entity_Editor_CLI_Inspector::list_entities(['max-age' => 60]);
        $this->assertWPError($stale);
        $this->assertSame('dbvc_entity_editor_cli_index_stale', $stale->get_error_code());

        delete_transient('dbvc_entity_editor_index_v1');
        $cache_path = $this->sync_dir . '.dbvc-entity-index.json';
        unlink($cache_path);
        $missing = DBVC_Entity_Editor_CLI_Inspector::list_entities([]);

        $this->assertWPError($missing);
        $this->assertSame('dbvc_entity_editor_cli_index_missing', $missing->get_error_code());
        $this->assertFileDoesNotExist($cache_path);
        $this->assertFalse(get_transient('dbvc_entity_editor_index_v1'));
    }

    public function test_path_and_write_guards_preserve_inspection_boundary(): void
    {
        $not_indexed = DBVC_Entity_Editor_CLI_Inspector::inspect_entity('page/not-indexed.json');
        $traversal = DBVC_Entity_Editor_CLI_Inspector::inspect_entity('../wp-config.php');
        $rebuild = DBVC_Entity_Editor_CLI_Inspector::list_entities(['rebuild' => true]);
        $download = DBVC_Entity_Editor_CLI_Inspector::inspect_entity($this->post_path, ['download' => true]);

        $this->assertWPError($not_indexed);
        $this->assertSame('dbvc_entity_editor_cli_path_not_indexed', $not_indexed->get_error_code());
        $this->assertWPError($traversal);
        $this->assertSame('dbvc_entity_editor_cli_path_required', $traversal->get_error_code());
        $this->assertWPError($rebuild);
        $this->assertSame('dbvc_entity_editor_cli_read_only', $rebuild->get_error_code());
        $this->assertWPError($download);
        $this->assertSame('dbvc_entity_editor_cli_read_only', $download->get_error_code());
    }

    public function test_registration_synopsis_excludes_download_and_writer_flags(): void
    {
        $source = file_get_contents(DBVC_PLUGIN_PATH . 'commands/class-entity-editor-cli.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("WP_CLI::add_command('dbvc entity-editor', 'DBVC_WP_CLI_Entity_Editor')", $source);
        $this->assertStringContainsString('@subcommand list', $source);
        $this->assertStringContainsString('<relative-path>', $source);
        $this->assertStringNotContainsString('[--rebuild]', $source);
        $this->assertStringNotContainsString('[--download]', $source);
        $this->assertStringNotContainsString('[--raw]', $source);
        $this->assertStringNotContainsString('[--save]', $source);
        $this->assertStringNotContainsString('[--import]', $source);
    }

    /**
     * @param string $relative_path Relative path.
     * @param array  $payload JSON payload.
     * @return string
     */
    private function write_json(string $relative_path, array $payload): string
    {
        $absolute = $this->sync_dir . ltrim($relative_path, '/');
        wp_mkdir_p(dirname($absolute));
        file_put_contents($absolute, wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return str_replace('\\', '/', ltrim($relative_path, '/'));
    }

    /**
     * @param string $directory Directory path.
     * @return void
     */
    private function delete_directory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
