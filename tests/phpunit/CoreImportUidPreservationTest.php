<?php

class CoreImportUidPreservationTest extends WP_UnitTestCase
{
    /** @var string */
    private $sync_dir = '';

    /** @var string */
    private $sync_option_before = '';

    /** @var string */
    private $allow_uid_fallback_before = '';

    /** @var string */
    private $allow_new_posts_before = '';

    public function set_up(): void
    {
        parent::set_up();

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->sync_option_before = (string) get_option('dbvc_sync_path', '');
        $this->allow_uid_fallback_before = (string) get_option('dbvc_allow_uid_fallback_matching', '');
        $this->allow_new_posts_before = (string) get_option('dbvc_allow_new_posts', '');
        $this->sync_dir = trailingslashit(ABSPATH . 'wp-content/uploads/dbvc-tests-' . wp_generate_password(8, false, false));
        wp_mkdir_p($this->sync_dir);

        $relative = ltrim(str_replace(wp_normalize_path(trailingslashit(ABSPATH)), '', wp_normalize_path($this->sync_dir)), '/');
        update_option('dbvc_sync_path', $relative ?: 'wp-content/uploads');
        update_option('dbvc_allow_uid_fallback_matching', '0');
        update_option('dbvc_allow_new_posts', '0');
    }

    public function tear_down(): void
    {
        update_option('dbvc_sync_path', $this->sync_option_before);
        update_option('dbvc_allow_uid_fallback_matching', $this->allow_uid_fallback_before);
        update_option('dbvc_allow_new_posts', $this->allow_new_posts_before);
        $this->delete_directory($this->sync_dir);
        parent::tear_down();
    }

    public function test_post_import_blocks_id_fallback_when_uid_is_unmatched_and_fallback_disabled(): void
    {
        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'UID Fallback Block Before',
            'post_status' => 'publish',
            'post_name'   => 'core-import-uid-fallback-block',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'local-post-uid');

        $path = $this->write_sync_json(
            sprintf('page/page-core-import-uid-fallback-block-%d.json', $post_id),
            [
                'ID'            => $post_id,
                'post_type'     => 'page',
                'post_name'     => 'core-import-uid-fallback-block',
                'post_title'    => 'UID Fallback Block After',
                'post_content'  => '',
                'post_excerpt'  => '',
                'post_status'   => 'publish',
                'vf_object_uid' => 'source-post-uid',
                'meta'          => [
                    'uid_test_meta' => ['changed'],
                ],
                'tax_input'     => [],
            ]
        );

        $result = DBVC_Sync_Posts::import_post_from_json($this->absolute_sync_path($path), false);

        $this->assertSame('skipped', $result);
        $this->assertSame('local-post-uid', (string) get_post_meta($post_id, 'vf_object_uid', true));
        $this->assertSame('', (string) get_post_meta($post_id, 'uid_test_meta', true));
    }

    public function test_post_import_preserves_payload_uid_in_top_level_and_meta_when_fallback_enabled(): void
    {
        update_option('dbvc_allow_uid_fallback_matching', '1');

        $post_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'UID Import Before',
            'post_status' => 'publish',
            'post_name'   => 'core-import-uid-preserve',
        ]);
        update_post_meta($post_id, 'vf_object_uid', 'local-post-uid');

        $path = $this->write_sync_json(
            sprintf('page/page-core-import-uid-preserve-%d.json', $post_id),
            [
                'ID'            => $post_id,
                'post_type'     => 'page',
                'post_name'     => 'core-import-uid-preserve',
                'post_title'    => 'UID Import After',
                'post_content'  => '',
                'post_excerpt'  => '',
                'post_status'   => 'publish',
                'vf_object_uid' => 'source-post-uid',
                'meta'          => [
                    'vf_object_uid' => ['local-post-uid'],
                    'uid_test_meta' => ['changed'],
                ],
                'tax_input'     => [],
            ]
        );

        $result = DBVC_Sync_Posts::import_post_from_json($this->absolute_sync_path($path), false);

        $this->assertSame('applied', $result);
        $this->assertSame('source-post-uid', (string) get_post_meta($post_id, 'vf_object_uid', true));

        $payload = json_decode((string) file_get_contents($this->absolute_sync_path($path)), true);
        $this->assertIsArray($payload);
        $this->assertSame('source-post-uid', $payload['vf_object_uid'] ?? '');
        $this->assertSame(['source-post-uid'], $payload['meta']['vf_object_uid'] ?? null);
        $this->assertSame('source-post-uid', $payload['meta']['dbvc_post_history']['vf_object_uid'] ?? '');
    }

    public function test_term_normalization_preserves_incoming_uid_on_slug_match(): void
    {
        $insert = wp_insert_term('Core UID Preserve Term', 'category', [
            'slug' => 'core-uid-preserve-term',
        ]);
        $this->assertIsArray($insert);

        $term_id = (int) $insert['term_id'];
        update_term_meta($term_id, 'vf_object_uid', 'local-term-uid');

        $path = $this->write_sync_json(
            'taxonomy/category/core-uid-preserve-term.json',
            [
                'term_id'       => $term_id,
                'taxonomy'      => 'category',
                'slug'          => 'core-uid-preserve-term',
                'name'          => 'Core UID Preserve Term',
                'description'   => '',
                'vf_object_uid' => 'source-term-uid',
                'meta'          => [
                    'dbvc_term_history' => [[
                        'vf_object_uid' => 'local-term-uid',
                    ]],
                ],
            ]
        );

        $stats = DBVC_Sync_Taxonomies::normalize_term_json_files(['category']);
        $this->assertSame(1, (int) ($stats['processed'] ?? 0));

        $payload = json_decode((string) file_get_contents($this->absolute_sync_path($path)), true);
        $this->assertIsArray($payload);
        $this->assertSame('source-term-uid', $payload['vf_object_uid'] ?? '');
        $this->assertSame(['source-term-uid'], $payload['meta']['vf_object_uid'] ?? null);
        $this->assertSame('source-term-uid', $payload['meta']['dbvc_term_history'][0]['vf_object_uid'] ?? '');
    }

    public function test_term_import_blocks_slug_fallback_when_uid_is_unmatched_and_fallback_disabled(): void
    {
        $insert = wp_insert_term('Core UID Fallback Block Term', 'category', [
            'slug' => 'core-uid-fallback-block-term',
        ]);
        $this->assertIsArray($insert);

        $term_id = (int) $insert['term_id'];
        update_term_meta($term_id, 'vf_object_uid', 'local-term-uid');

        $path = $this->write_sync_json(
            'taxonomy/category/core-uid-fallback-block-term.json',
            [
                'term_id'       => 999999,
                'taxonomy'      => 'category',
                'slug'          => 'core-uid-fallback-block-term',
                'name'          => 'Core UID Fallback Block Term Updated',
                'description'   => 'blocked change',
                'vf_object_uid' => 'source-term-uid',
                'meta'          => [],
            ]
        );

        $result = DBVC_Sync_Taxonomies::import_term_json_file($this->absolute_sync_path($path), 'category');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('dbvc_term_uid_unmatched_fallback_disabled', $result->get_error_code());
        $this->assertSame('local-term-uid', (string) get_term_meta($term_id, 'vf_object_uid', true));
        $term = get_term($term_id, 'category');
        $this->assertInstanceOf(WP_Term::class, $term);
        $this->assertSame('', (string) $term->description);
    }

    /**
     * @param string              $relative_path
     * @param array<string,mixed> $payload
     * @return string
     */
    private function write_sync_json(string $relative_path, array $payload): string
    {
        $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
        $absolute = $this->absolute_sync_path($relative_path);
        wp_mkdir_p(dirname($absolute));
        file_put_contents($absolute, wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return $relative_path;
    }

    private function absolute_sync_path(string $relative_path): string
    {
        return trailingslashit($this->sync_dir) . ltrim(str_replace('\\', '/', $relative_path), '/');
    }

    private function delete_directory(string $path): void
    {
        $path = rtrim($path, '/');
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
