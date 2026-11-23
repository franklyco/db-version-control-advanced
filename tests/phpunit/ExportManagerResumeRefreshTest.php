<?php

/**
 * Non-post entities in a resumed chunked export.
 *
 * Options, menus, taxonomies and third-party entities are written once, when a
 * chunked export job is created. Unfinished jobs persist indefinitely and the
 * admin offers each one a "Process Next Chunk" button, so a job can be finished
 * long after it was started. Before this fix that run exported today's posts but
 * left the non-post entities as they were at creation, then recorded the mix as
 * one coherent export in the manifest and snapshot.
 *
 * Counted through `dbvc_after_export_options`, which the real options export
 * fires each time it writes, so these exercise the actual export rather than a
 * stand-in.
 */
class ExportManagerResumeRefreshTest extends WP_UnitTestCase
{
    /** @var string */
    private $sync_option_before = '';

    /** @var mixed */
    private $post_types_before;

    /** @var string */
    private $sync_dir = '';

    /** @var int */
    private $options_exports = 0;

    public function set_up(): void
    {
        parent::set_up();

        if (! class_exists('DBVC_Export_Manager') || ! class_exists('DBVC_Database')) {
            $this->markTestSkipped('Chunked export manager is not loaded.');
        }

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $this->sync_option_before = (string) get_option('dbvc_sync_path', '');
        $this->post_types_before  = get_option('dbvc_post_types', []);

        $this->sync_dir = trailingslashit(ABSPATH . 'wp-content/uploads/dbvc-export-resume-tests-' . wp_generate_password(8, false, false));
        wp_mkdir_p($this->sync_dir);
        $relative = ltrim(str_replace(wp_normalize_path(trailingslashit(ABSPATH)), '', wp_normalize_path($this->sync_dir)), '/');

        update_option('dbvc_sync_path', $relative ?: 'wp-content/uploads');
        update_option('dbvc_post_types', ['post']);

        self::factory()->post->create_many(5, ['post_status' => 'publish']);

        $this->options_exports = 0;
        add_action('dbvc_after_export_options', [$this, 'count_options_export']);
    }

    public function tear_down(): void
    {
        remove_action('dbvc_after_export_options', [$this, 'count_options_export']);
        update_option('dbvc_sync_path', $this->sync_option_before);
        update_option('dbvc_post_types', $this->post_types_before);
        $this->delete_directory($this->sync_dir);

        parent::tear_down();
    }

    public function count_options_export(): void
    {
        $this->options_exports++;
    }

    public function test_single_call_export_writes_non_post_entities_exactly_once(): void
    {
        $total = DBVC_Sync_Posts::get_total_export_post_count();
        $this->assertGreaterThan(0, $total, 'fixture posts must be exportable');

        $result = DBVC_Export_Manager::run_chunked_export($total + 10, null);

        $this->assertIsArray($result);
        $this->assertSame('done', $result['status'], 'a chunk larger than the post count finishes in one call');
        $this->assertSame(
            1,
            $this->options_exports,
            'a job created and finished in the same call must not export non-post entities twice'
        );
    }

    public function test_resumed_job_refreshes_non_post_entities_when_it_completes(): void
    {
        $total = DBVC_Sync_Posts::get_total_export_post_count();
        $this->assertGreaterThan(2, $total, 'need enough posts to span several chunks');

        $first = DBVC_Export_Manager::run_chunked_export(2, null);
        $this->assertSame('running', $first['status']);
        $this->assertSame(1, $this->options_exports, 'job creation exports non-post entities once');

        $last = $this->resume_until_done((int) $first['job_id'], 2);

        $this->assertSame('done', $last['status']);
        $this->assertSame(
            2,
            $this->options_exports,
            'completing a resumed job must refresh non-post entities so they match its posts'
        );
    }

    public function test_resuming_an_intermediate_chunk_does_not_refresh(): void
    {
        $total = DBVC_Sync_Posts::get_total_export_post_count();
        $this->assertGreaterThan(3, $total, 'need at least two resumable chunks');

        $first = DBVC_Export_Manager::run_chunked_export(1, null);
        $this->assertSame(1, $this->options_exports);

        $middle = DBVC_Export_Manager::run_chunked_export(1, (int) $first['job_id']);
        $this->assertSame('running', $middle['status'], 'this chunk must not be the last');
        $this->assertSame(
            1,
            $this->options_exports,
            'only the completing chunk refreshes; intermediate chunks must not repeat the work'
        );
    }

    public function test_an_already_finished_job_is_not_re_exported(): void
    {
        $total = DBVC_Sync_Posts::get_total_export_post_count();
        $done  = DBVC_Export_Manager::run_chunked_export($total + 10, null);
        $this->assertSame('done', $done['status']);
        $this->assertSame(1, $this->options_exports);

        $again = DBVC_Export_Manager::run_chunked_export($total + 10, (int) $done['job_id']);

        $this->assertSame('done', $again['status']);
        $this->assertSame(
            1,
            $this->options_exports,
            'calling a completed job returns early and must not re-export anything'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resume_until_done(int $job_id, int $chunk_size): array
    {
        $result = [];
        for ($i = 0; $i < 50; $i++) {
            $result = DBVC_Export_Manager::run_chunked_export($chunk_size, $job_id);
            $this->assertIsArray($result, 'resume must not fail');
            if (($result['status'] ?? '') === 'done') {
                return $result;
            }
        }
        $this->fail('chunked export did not finish within 50 resumes');
    }

    private function delete_directory(string $dir): void
    {
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
