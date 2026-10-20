<?php

class MediaHydrationInventoryTest extends WP_UnitTestCase
{
    /** @var string[] */
    private $files = [];

    /** @var string[] */
    private $directories = [];

    /** @var array<string,mixed> */
    private $option_snapshots = [];

    /** @var int */
    private $previous_user_id = 0;

    public function set_up(): void
    {
        parent::set_up();

        $this->previous_user_id = get_current_user_id();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        foreach ($this->hydration_option_keys() as $option_key) {
            $this->option_snapshots[$option_key] = get_option($option_key, null);
        }

        update_option(\Dbvc\Media\Hydration\Settings::OPTION_ENABLED, '1');

        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        foreach ($this->files as $file) {
            if (is_string($file) && $file !== '' && file_exists($file)) {
                @unlink($file);
            }
        }
        $this->files = [];

        foreach (array_reverse($this->directories) as $directory) {
            if (is_string($directory) && $directory !== '' && is_dir($directory)) {
                $this->delete_directory($directory);
            }
        }
        $this->directories = [];

        foreach ($this->option_snapshots as $option_key => $value) {
            if ($value === null) {
                delete_option($option_key);
            } else {
                update_option($option_key, $value);
            }
        }
        $this->option_snapshots = [];
        wp_set_current_user($this->previous_user_id);

        parent::tear_down();
    }

    public function test_inventory_reports_existing_and_missing_attachment_files(): void
    {
        $existing_id = $this->create_attachment_with_file('dbvc-existing.jpg', 'image/jpeg', 'existing-bytes');
        $missing_id = $this->create_attachment_with_missing_file('2026/06/dbvc-missing.jpg', 'image/jpeg');

        $result = \Dbvc\Media\Hydration\LibraryInventoryService::query([
            'attachment_ids' => [$existing_id, $missing_id],
            'limit' => 10,
        ]);

        $items = $this->index_by_attachment_id($result['items'] ?? []);
        $this->assertArrayHasKey($existing_id, $items);
        $this->assertArrayHasKey($missing_id, $items);

        $this->assertSame('exists', (string) ($items[$existing_id]['file_state']['status'] ?? ''));
        $this->assertTrue((bool) ($items[$existing_id]['file_state']['exists'] ?? false));
        $this->assertSame('missing', (string) ($items[$missing_id]['file_state']['status'] ?? ''));
        $this->assertFalse((bool) ($items[$missing_id]['file_state']['exists'] ?? true));

        $summary = $result['summary'] ?? [];
        $this->assertSame(1, (int) ($summary['missing_files'] ?? 0));
    }

    public function test_file_state_rejects_attached_file_path_outside_uploads(): void
    {
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => 'image/jpeg',
            'post_title' => 'Unsafe media path',
            'post_status' => 'inherit',
        ]);
        $this->assertGreaterThan(0, (int) $attachment_id);
        update_post_meta((int) $attachment_id, '_wp_attached_file', '../outside.jpg');

        $state = \Dbvc\Media\Hydration\FileStateService::inspect((int) $attachment_id);

        $this->assertSame('unsafe_path', (string) ($state['status'] ?? ''));
        $this->assertFalse((bool) ($state['safe_path'] ?? true));
    }

    public function test_inventory_uses_bounded_pagination_for_explicit_ids(): void
    {
        $first = $this->create_attachment_with_file('dbvc-page-one.jpg', 'image/jpeg', 'one');
        $second = $this->create_attachment_with_file('dbvc-page-two.jpg', 'image/jpeg', 'two');
        $third = $this->create_attachment_with_file('dbvc-page-three.jpg', 'image/jpeg', 'three');

        $result = \Dbvc\Media\Hydration\LibraryInventoryService::query([
            'attachment_ids' => [$first, $second, $third],
            'limit' => 2,
            'offset' => 0,
        ]);

        $this->assertSame(2, (int) ($result['pagination']['returned'] ?? 0));
        $this->assertSame(3, (int) ($result['pagination']['total'] ?? 0));
        $this->assertTrue((bool) ($result['pagination']['has_more'] ?? false));
    }

    public function test_metadata_status_is_missing_without_mutation(): void
    {
        $attachment_id = $this->create_attachment_with_file('dbvc-no-metadata.pdf', 'application/pdf', 'pdf');

        $state = \Dbvc\Media\Hydration\FileStateService::inspect($attachment_id);

        $this->assertSame('exists', (string) ($state['status'] ?? ''));
        $this->assertSame('missing', (string) ($state['metadata']['status'] ?? ''));
        $this->assertSame('', (string) get_post_meta($attachment_id, '_wp_attachment_metadata', true));
    }

    public function test_mirror_manifest_builder_exports_registered_attachment_shape(): void
    {
        $attachment_id = $this->create_attachment_with_file('dbvc-mirror-shape.jpg', 'image/jpeg', 'mirror-shape');

        $manifest = \Dbvc\Media\Hydration\MirrorManifestBuilder::build_manifest([
            'attachment_ids' => [$attachment_id],
            'batch_size' => 10,
        ]);

        $this->assertSame(1, (int) ($manifest['schema'] ?? 0));
        $this->assertSame('dbvc_media_mirror', (string) ($manifest['kind'] ?? ''));
        $this->assertArrayHasKey('attachments', $manifest);
        $this->assertCount(1, $manifest['attachments']);
        $this->assertArrayNotHasKey('media_index', $manifest);
        $this->assertSame($attachment_id, (int) ($manifest['attachments'][0]['source_id'] ?? 0));
        $this->assertSame('exists', (string) ($manifest['attachments'][0]['file_status'] ?? ''));
        $this->assertStringStartsWith('sha256:', (string) ($manifest['attachments'][0]['file_hash'] ?? ''));
        $this->assertStringStartsWith('sha256:', (string) ($manifest['checksum'] ?? ''));
    }

    public function test_mirror_package_can_copy_original_files_under_media_directory(): void
    {
        $attachment_id = $this->create_attachment_with_file('dbvc-mirror-copy.jpg', 'image/jpeg', 'mirror-copy');
        $package_id = 'phpunit-media-mirror-' . wp_generate_password(8, false, false);

        $result = \Dbvc\Media\Hydration\MirrorManifestBuilder::build_package([
            'package_id' => $package_id,
            'attachment_ids' => [$attachment_id],
            'include_files' => true,
            'create_zip' => false,
            'batch_size' => 10,
        ]);

        $this->assertIsArray($result);
        $this->assertSame($package_id, (string) ($result['package_id'] ?? ''));
        $this->assertFileExists((string) ($result['manifest_path'] ?? ''));
        $this->assertSame(1, (int) ($result['copy_stats']['copied'] ?? 0));

        $entry = $result['manifest']['attachments'][0] ?? [];
        $relative = isset($entry['relative_path']) ? (string) $entry['relative_path'] : '';
        $this->assertNotSame('', $relative);
        $this->assertFileExists(trailingslashit((string) $result['package_dir']) . 'media/' . $relative);

        $this->delete_directory((string) ($result['package_dir'] ?? ''));
    }

    public function test_hydration_planner_marks_missing_target_file_for_existing_same_id_attachment(): void
    {
        $attachment_id = $this->create_attachment_with_missing_file('2026/06/dbvc-plan-missing.jpg', 'image/jpeg');
        $manifest = $this->build_manifest_for_entries([[
            'source_id' => $attachment_id,
            'relative_path' => '2026/06/dbvc-plan-missing.jpg',
            'mime_type' => 'image/jpeg',
            'mime_group' => 'image',
            'file_status' => 'exists',
            'file_hash' => 'sha256:' . hash('sha256', 'source-bytes'),
            'file_size' => 12,
        ]]);

        $plan = \Dbvc\Media\Hydration\HydrationPlanner::plan($manifest);

        $this->assertIsArray($plan);
        $this->assertSame(1, (int) ($plan['summary']['needs_hydration'] ?? 0));
        $this->assertSame('needs_hydration', (string) ($plan['items'][0]['status'] ?? ''));
        $this->assertSame('hydrate_existing_attachment', (string) ($plan['items'][0]['planned_action'] ?? ''));
        $this->assertSame($attachment_id, (int) ($plan['items'][0]['target_id'] ?? 0));
    }

    public function test_hydration_planner_blocks_existing_file_hash_mismatch(): void
    {
        $attachment_id = $this->create_attachment_with_file('dbvc-plan-mismatch.pdf', 'application/pdf', 'target-bytes');
        $manifest = $this->build_manifest_for_entries([[
            'source_id' => $attachment_id,
            'relative_path' => (string) get_post_meta($attachment_id, '_wp_attached_file', true),
            'mime_type' => 'application/pdf',
            'mime_group' => 'document',
            'file_status' => 'exists',
            'file_hash' => 'sha256:' . hash('sha256', 'different-source-bytes'),
            'file_size' => 22,
        ]]);

        $plan = \Dbvc\Media\Hydration\HydrationPlanner::plan($manifest);

        $this->assertIsArray($plan);
        $this->assertSame(1, (int) ($plan['summary']['hash_mismatch'] ?? 0));
        $this->assertSame('hash_mismatch', (string) ($plan['items'][0]['status'] ?? ''));
        $this->assertSame('block', (string) ($plan['items'][0]['planned_action'] ?? ''));
    }

    public function test_hydration_planner_allows_existing_document_with_matching_hash(): void
    {
        $contents = 'matching-document';
        $attachment_id = $this->create_attachment_with_file('dbvc-plan-ok.pdf', 'application/pdf', $contents);
        $manifest = $this->build_manifest_for_entries([[
            'source_id' => $attachment_id,
            'relative_path' => (string) get_post_meta($attachment_id, '_wp_attached_file', true),
            'mime_type' => 'application/pdf',
            'mime_group' => 'document',
            'file_status' => 'exists',
            'file_hash' => 'sha256:' . hash('sha256', $contents),
            'file_size' => strlen($contents),
        ]]);

        $plan = \Dbvc\Media\Hydration\HydrationPlanner::plan($manifest);

        $this->assertIsArray($plan);
        $this->assertSame(1, (int) ($plan['summary']['ok'] ?? 0));
        $this->assertSame('ok', (string) ($plan['items'][0]['status'] ?? ''));
        $this->assertSame('none', (string) ($plan['items'][0]['planned_action'] ?? ''));
    }

    public function test_hydration_planner_marks_media_metadata_repair_without_mutation(): void
    {
        $contents = 'matching-image';
        $attachment_id = $this->create_attachment_with_file('dbvc-plan-metadata.jpg', 'image/jpeg', $contents);
        $manifest = $this->build_manifest_for_entries([[
            'source_id' => $attachment_id,
            'relative_path' => (string) get_post_meta($attachment_id, '_wp_attached_file', true),
            'mime_type' => 'image/jpeg',
            'mime_group' => 'image',
            'file_status' => 'exists',
            'file_hash' => 'sha256:' . hash('sha256', $contents),
            'file_size' => strlen($contents),
        ]]);

        $plan = \Dbvc\Media\Hydration\HydrationPlanner::plan($manifest);

        $this->assertIsArray($plan);
        $this->assertSame(1, (int) ($plan['summary']['needs_metadata_repair'] ?? 0));
        $this->assertSame('needs_metadata_repair', (string) ($plan['items'][0]['status'] ?? ''));
        $this->assertSame('repair_metadata', (string) ($plan['items'][0]['planned_action'] ?? ''));
        $this->assertSame('', (string) get_post_meta($attachment_id, '_wp_attachment_metadata', true));
    }

    public function test_hydrator_loads_video_metadata_dependencies_during_hydration(): void
    {
        $contents = 'fake-video-bytes';
        $relative = '2026/06/dbvc-hydrate-video.mp4';
        $attachment_id = $this->create_attachment_with_missing_file($relative, 'video/mp4');
        $package_dir = $this->create_package_with_media($relative, $contents);
        $uploads = wp_get_upload_dir();
        $this->files[] = trailingslashit((string) $uploads['basedir']) . $relative;

        $manifest = $this->build_manifest_for_entries([[
            'source_id' => $attachment_id,
            'relative_path' => $relative,
            'mime_type' => 'video/mp4',
            'mime_group' => 'video',
            'file_status' => 'exists',
            'file_hash' => 'sha256:' . hash('sha256', $contents),
            'file_size' => strlen($contents),
        ]]);

        $report = \Dbvc\Media\Hydration\Hydrator::apply($manifest, $package_dir, [
            'clone_confirmation' => true,
            'strict_hashes' => true,
            'require_hashes' => true,
            'repair_metadata' => true,
        ]);

        $this->assertIsArray($report);
        $this->assertSame(1, (int) ($report['summary']['hydrated'] ?? 0));
        $this->assertSame('hydrated', (string) ($report['items'][0]['result'] ?? ''));
        $this->assertNotSame('metadata_generation_failed', (string) ($report['items'][0]['metadata_reason'] ?? ''));
        $this->assertTrue(function_exists('wp_read_video_metadata'));
    }

    public function test_hydrator_reports_chunk_progress_fields(): void
    {
        $uploads = wp_get_upload_dir();
        $this->assertEmpty($uploads['error']);

        $media = [
            '2026/06/dbvc-progress-one.pdf' => 'progress-one',
            '2026/06/dbvc-progress-two.pdf' => 'progress-two',
            '2026/06/dbvc-progress-three.pdf' => 'progress-three',
        ];
        $entries = [];
        $package_dir = '';

        foreach ($media as $relative => $contents) {
            $attachment_id = $this->create_attachment_with_missing_file($relative, 'application/pdf');
            $this->files[] = trailingslashit((string) $uploads['basedir']) . $relative;
            $entries[] = [
                'source_id' => $attachment_id,
                'relative_path' => $relative,
                'mime_type' => 'application/pdf',
                'mime_group' => 'document',
                'file_status' => 'exists',
                'file_hash' => 'sha256:' . hash('sha256', $contents),
                'file_size' => strlen($contents),
            ];

            if ($package_dir === '') {
                $package_dir = $this->create_package_with_media($relative, $contents);
                continue;
            }

            $package_file = trailingslashit($package_dir) . 'media/' . \Dbvc\Media\Hydration\FileStateService::normalize_relative_path($relative);
            wp_mkdir_p(dirname($package_file));
            file_put_contents($package_file, $contents);
        }

        $manifest = $this->build_manifest_for_entries($entries);
        $args = [
            'clone_confirmation' => true,
            'strict_hashes' => true,
            'require_hashes' => true,
            'repair_metadata' => false,
            'limit' => 2,
        ];

        $first = \Dbvc\Media\Hydration\Hydrator::apply($manifest, $package_dir, $args + ['offset' => 0]);
        $this->assertIsArray($first);
        $this->assertSame(2, (int) ($first['summary']['hydrated'] ?? 0));
        $this->assertSame(2, (int) ($first['pagination']['processed_this_batch'] ?? 0));
        $this->assertSame(2, (int) ($first['pagination']['processed_total'] ?? 0));
        $this->assertSame(2, (int) ($first['pagination']['next_offset'] ?? 0));
        $this->assertSame(1, (int) ($first['pagination']['remaining'] ?? 0));
        $this->assertSame(3, (int) ($first['pagination']['total_plan_items'] ?? 0));
        $this->assertTrue((bool) ($first['pagination']['has_more'] ?? false));
        $this->assertSame(2, (int) ($first['progress']['next_offset'] ?? 0));

        $second = \Dbvc\Media\Hydration\Hydrator::apply($manifest, $package_dir, $args + ['offset' => 2]);
        $this->assertIsArray($second);
        $this->assertSame(1, (int) ($second['summary']['hydrated'] ?? 0));
        $this->assertSame(1, (int) ($second['pagination']['processed_this_batch'] ?? 0));
        $this->assertSame(3, (int) ($second['pagination']['processed_total'] ?? 0));
        $this->assertSame(3, (int) ($second['pagination']['next_offset'] ?? 0));
        $this->assertSame(0, (int) ($second['pagination']['remaining'] ?? 0));
        $this->assertFalse((bool) ($second['pagination']['has_more'] ?? true));
        $this->assertSame(100.0, (float) ($second['progress']['percent'] ?? 0));
    }

    public function test_hydrator_can_apply_only_requested_source_ids(): void
    {
        $uploads = wp_get_upload_dir();
        $this->assertEmpty($uploads['error']);

        $first_relative = '2026/06/dbvc-filter-one.pdf';
        $second_relative = '2026/06/dbvc-filter-two.pdf';
        $first_id = $this->create_attachment_with_missing_file($first_relative, 'application/pdf');
        $second_id = $this->create_attachment_with_missing_file($second_relative, 'application/pdf');
        $this->files[] = trailingslashit((string) $uploads['basedir']) . $first_relative;
        $this->files[] = trailingslashit((string) $uploads['basedir']) . $second_relative;

        $manifest = $this->build_manifest_for_entries([
            [
                'source_id' => $first_id,
                'relative_path' => $first_relative,
                'mime_type' => 'application/pdf',
                'mime_group' => 'document',
                'file_status' => 'exists',
                'file_hash' => 'sha256:' . hash('sha256', 'filter-one'),
                'file_size' => strlen('filter-one'),
            ],
            [
                'source_id' => $second_id,
                'relative_path' => $second_relative,
                'mime_type' => 'application/pdf',
                'mime_group' => 'document',
                'file_status' => 'exists',
                'file_hash' => 'sha256:' . hash('sha256', 'filter-two'),
                'file_size' => strlen('filter-two'),
            ],
        ]);
        $package_dir = $this->create_package_with_manifest($manifest, [
            $first_relative => 'filter-one',
            $second_relative => 'filter-two',
        ]);

        $report = \Dbvc\Media\Hydration\Hydrator::apply($manifest, $package_dir, [
            'clone_confirmation' => true,
            'strict_hashes' => true,
            'require_hashes' => true,
            'repair_metadata' => false,
            'source_ids' => [$second_id],
        ]);

        $this->assertIsArray($report);
        $this->assertSame(1, (int) ($report['policy']['source_id_filter_count'] ?? 0));
        $this->assertSame(1, (int) ($report['pagination']['total_plan_items'] ?? 0));
        $this->assertSame(1, (int) ($report['summary']['hydrated'] ?? 0));
        $this->assertSame($second_id, (int) ($report['items'][0]['source_id'] ?? 0));
        $this->assertFalse(file_exists(trailingslashit((string) $uploads['basedir']) . $first_relative));
        $this->assertTrue(file_exists(trailingslashit((string) $uploads['basedir']) . $second_relative));
    }

    public function test_hydrator_can_normalize_exact_media_urls_to_https(): void
    {
        $contents = 'https-normalize';
        $relative = '2026/06/dbvc-https-normalize.pdf';
        $attachment_id = $this->create_attachment_with_missing_file($relative, 'application/pdf');
        $package_dir = $this->create_package_with_media($relative, $contents);
        $uploads = wp_get_upload_dir();
        $this->files[] = trailingslashit((string) $uploads['basedir']) . $relative;

        $target_http = 'http://canonical.example/wp-content/uploads/' . $relative;
        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            ['guid' => $target_http],
            ['ID' => $attachment_id],
            ['%s'],
            ['%d']
        );
        clean_post_cache($attachment_id);

        $post_id = self::factory()->post->create([
            'post_content' => 'Media link: ' . $target_http,
        ]);
        update_post_meta($post_id, 'dbvc_https_media_url', [
            'url' => $target_http,
        ]);
        $attachment_page_url = 'http://canonical.example/?attachment_id=' . $attachment_id;
        $permalink_post_id = self::factory()->post->create([
            'post_content' => 'Attachment page link: ' . $attachment_page_url,
        ]);

        $manifest = $this->build_manifest_for_entries([[
            'source_id' => $attachment_id,
            'relative_path' => $relative,
            'source_url' => $target_http,
            'mime_type' => 'application/pdf',
            'mime_group' => 'document',
            'file_status' => 'exists',
            'file_hash' => 'sha256:' . hash('sha256', $contents),
            'file_size' => strlen($contents),
        ]]);

        $report = \Dbvc\Media\Hydration\Hydrator::apply($manifest, $package_dir, [
            'clone_confirmation' => true,
            'strict_hashes' => true,
            'require_hashes' => true,
            'repair_metadata' => false,
            'normalize_media_urls_to_https' => true,
        ]);

        $this->assertIsArray($report);
        $this->assertSame(1, (int) ($report['summary']['hydrated'] ?? 0));
        $this->assertGreaterThanOrEqual(3, (int) ($report['summary']['https_url_replacements'] ?? 0));
        $target_https = set_url_scheme(wp_get_attachment_url($attachment_id), 'https');
        $this->assertSame($target_https, (string) get_post_field('guid', $attachment_id));
        $this->assertStringContainsString($target_https, (string) get_post_field('post_content', $post_id));
        $this->assertSame(['url' => $target_https], get_post_meta($post_id, 'dbvc_https_media_url', true));
        $this->assertStringContainsString($attachment_page_url, (string) get_post_field('post_content', $permalink_post_id));
    }

    public function test_hydration_planner_blocks_when_target_attachment_is_missing(): void
    {
        $manifest = $this->build_manifest_for_entries([[
            'source_id' => 987654,
            'relative_path' => '2026/06/dbvc-plan-no-target.jpg',
            'mime_type' => 'image/jpeg',
            'mime_group' => 'image',
            'file_status' => 'exists',
            'file_hash' => 'sha256:' . hash('sha256', 'source-bytes'),
            'file_size' => 12,
        ]]);

        $plan = \Dbvc\Media\Hydration\HydrationPlanner::plan($manifest);

        $this->assertIsArray($plan);
        $this->assertSame(1, (int) ($plan['summary']['target_attachment_missing'] ?? 0));
        $this->assertSame('target_attachment_missing', (string) ($plan['items'][0]['status'] ?? ''));
        $this->assertSame('block', (string) ($plan['items'][0]['planned_action'] ?? ''));
    }

    public function test_hydration_planner_requires_clone_same_id_by_default(): void
    {
        $source_id = 876543;
        $target_id = $this->create_attachment_with_missing_file('2026/06/dbvc-plan-clone-conflict.jpg', 'image/jpeg');
        update_post_meta($target_id, 'vf_asset_uid', 'dbvc-test-media-uid');

        $manifest = $this->build_manifest_for_entries([[
            'source_id' => $source_id,
            'asset_uid' => 'dbvc-test-media-uid',
            'relative_path' => '2026/06/dbvc-plan-clone-conflict.jpg',
            'mime_type' => 'image/jpeg',
            'mime_group' => 'image',
            'file_status' => 'exists',
            'file_hash' => 'sha256:' . hash('sha256', 'source-bytes'),
            'file_size' => 12,
        ]]);

        $plan = \Dbvc\Media\Hydration\HydrationPlanner::plan($manifest);

        $this->assertIsArray($plan);
        $this->assertSame(1, (int) ($plan['summary']['conflict'] ?? 0));
        $this->assertSame('conflict', (string) ($plan['items'][0]['status'] ?? ''));
        $this->assertSame('clone_same_id_required', (string) ($plan['items'][0]['reason'] ?? ''));
        $this->assertSame($target_id, (int) ($plan['items'][0]['target_id'] ?? 0));
    }

    public function test_hydration_planner_rejects_wrong_manifest_kind(): void
    {
        $plan = \Dbvc\Media\Hydration\HydrationPlanner::plan([
            'schema' => 1,
            'kind' => 'not_a_media_mirror',
            'attachments' => [],
        ]);

        $this->assertWPError($plan);
        $this->assertSame('dbvc_media_hydration_wrong_manifest_kind', $plan->get_error_code());
    }

    public function test_hydrator_copies_missing_package_file_to_existing_attachment(): void
    {
        $relative = '2026/06/dbvc-apply-hydrate.pdf';
        $contents = 'hydrate-package-bytes';
        $attachment_id = $this->create_attachment_with_missing_file($relative, 'application/pdf');
        $package_dir = $this->create_package_with_media($relative, $contents);
        $manifest = $this->build_manifest_for_entries([[
            'source_id' => $attachment_id,
            'relative_path' => $relative,
            'mime_type' => 'application/pdf',
            'mime_group' => 'document',
            'file_status' => 'exists',
            'file_hash' => 'sha256:' . hash('sha256', $contents),
            'file_size' => strlen($contents),
        ]]);

        $report = \Dbvc\Media\Hydration\Hydrator::apply($manifest, $package_dir, [
            'limit' => 10,
        ]);

        $uploads = wp_get_upload_dir();
        $target = trailingslashit((string) $uploads['basedir']) . $relative;
        $this->files[] = $target;

        $this->assertIsArray($report);
        $this->assertSame(1, (int) ($report['summary']['hydrated'] ?? 0));
        $this->assertSame('hydrated', (string) ($report['items'][0]['result'] ?? ''));
        $this->assertFileExists($target);
        $this->assertSame($contents, (string) file_get_contents($target));
        $this->assertSame($relative, (string) get_post_meta($attachment_id, '_wp_attached_file', true));
    }

    public function test_hydrator_rejects_package_hash_mismatch_without_copying(): void
    {
        $relative = '2026/06/dbvc-apply-hash-mismatch.pdf';
        $attachment_id = $this->create_attachment_with_missing_file($relative, 'application/pdf');
        $package_dir = $this->create_package_with_media($relative, 'wrong-package-bytes');
        $manifest = $this->build_manifest_for_entries([[
            'source_id' => $attachment_id,
            'relative_path' => $relative,
            'mime_type' => 'application/pdf',
            'mime_group' => 'document',
            'file_status' => 'exists',
            'file_hash' => 'sha256:' . hash('sha256', 'expected-package-bytes'),
            'file_size' => 22,
        ]]);

        $report = \Dbvc\Media\Hydration\Hydrator::apply($manifest, $package_dir, [
            'limit' => 10,
        ]);

        $uploads = wp_get_upload_dir();
        $target = trailingslashit((string) $uploads['basedir']) . $relative;

        $this->assertIsArray($report);
        $this->assertSame(1, (int) ($report['summary']['errors'] ?? 0));
        $this->assertSame('error', (string) ($report['items'][0]['result'] ?? ''));
        $this->assertSame('source_package_hash_mismatch', (string) ($report['items'][0]['reason'] ?? ''));
        $this->assertFalse(file_exists($target));
    }

    public function test_hydration_receipt_store_persists_json_report(): void
    {
        $stored = \Dbvc\Media\Hydration\HydrationReceiptStore::write('plan', [
            'kind' => 'dbvc_media_hydration_plan',
            'schema' => 1,
            'summary' => [
                'items' => 0,
            ],
        ], [
            'run_id' => 'phpunit-media-receipt-' . wp_generate_password(8, false, false),
        ]);

        $this->assertIsArray($stored);
        $path = (string) ($stored['receipt_path'] ?? '');
        $this->assertFileExists($path);
        $this->directories[] = dirname($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);
        $this->assertSame('dbvc_media_hydration_plan', (string) ($decoded['kind'] ?? ''));
        $this->assertSame('plan', (string) ($decoded['receipt']['type'] ?? ''));
        $this->assertSame((string) ($stored['run_id'] ?? ''), (string) ($decoded['receipt']['run_id'] ?? ''));
    }

    public function test_hydration_plan_store_persists_and_verifies_manifest_checksum(): void
    {
        $manifest = $this->build_manifest_for_entries([]);
        $plan = \Dbvc\Media\Hydration\HydrationPlanner::plan($manifest);
        $this->assertIsArray($plan);

        $stored = \Dbvc\Media\Hydration\HydrationPlanStore::write($plan, [
            'plan_id' => 'phpunit-plan-' . wp_generate_password(8, false, false),
        ]);
        $this->assertIsArray($stored);
        $this->assertFileExists((string) ($stored['plan_path'] ?? ''));
        $this->directories[] = dirname((string) ($stored['plan_path'] ?? ''));

        $loaded = \Dbvc\Media\Hydration\HydrationPlanStore::load((string) ($stored['plan_id'] ?? ''));
        $this->assertIsArray($loaded);
        $this->assertSame((string) ($stored['plan_id'] ?? ''), (string) ($loaded['saved_plan']['id'] ?? ''));

        $verified = \Dbvc\Media\Hydration\HydrationPlanStore::verify_for_manifest((string) ($stored['plan_id'] ?? ''), $manifest);
        $this->assertIsArray($verified);

        $mismatch = $manifest;
        $mismatch['checksum'] = 'sha256:other';
        $blocked = \Dbvc\Media\Hydration\HydrationPlanStore::verify_for_manifest((string) ($stored['plan_id'] ?? ''), $mismatch);
        $this->assertWPError($blocked);
        $this->assertSame('dbvc_media_hydration_plan_manifest_mismatch', $blocked->get_error_code());
    }

    public function test_hydration_lock_blocks_concurrent_apply_runs_until_release(): void
    {
        $first = \Dbvc\Media\Hydration\HydrationLock::acquire([
            'ttl_seconds' => 60,
            'owner' => 'phpunit',
        ]);
        $this->assertIsArray($first);

        try {
            $second = \Dbvc\Media\Hydration\HydrationLock::acquire([
                'ttl_seconds' => 60,
                'owner' => 'phpunit-second',
            ]);
            $this->assertWPError($second);
            $this->assertSame('dbvc_media_hydration_apply_locked', $second->get_error_code());
        } finally {
            \Dbvc\Media\Hydration\HydrationLock::release((string) ($first['token'] ?? ''));
        }

        $third = \Dbvc\Media\Hydration\HydrationLock::acquire([
            'ttl_seconds' => 60,
            'owner' => 'phpunit-third',
        ]);
        $this->assertIsArray($third);
        $this->assertTrue(\Dbvc\Media\Hydration\HydrationLock::release((string) ($third['token'] ?? '')));
    }

    public function test_media_handling_provider_exposes_hydration_settings(): void
    {
        $provider = new \Dbvc\ConfigurationPortability\Providers\MediaHandlingProvider();
        $groups = $provider->get_groups();
        $fields = $provider->get_fields();

        $this->assertArrayHasKey('media_hydration', $groups);
        $this->assertContains(\Dbvc\Media\Hydration\Settings::OPTION_ENABLED, $groups['media_hydration']['fields']);
        $this->assertContains(\Dbvc\Media\Hydration\Settings::OPTION_EXECUTION_MODE, $groups['media_hydration']['fields']);
        $this->assertContains(\Dbvc\Media\Hydration\Settings::OPTION_NORMALIZE_MEDIA_URLS_TO_HTTPS, $groups['media_hydration']['fields']);
        $this->assertArrayHasKey(\Dbvc\Media\Hydration\Settings::OPTION_BATCH_SIZE, $fields);
        $this->assertSame('50', (string) ($fields[\Dbvc\Media\Hydration\Settings::OPTION_BATCH_SIZE]['default'] ?? ''));
        $this->assertArrayHasKey(\Dbvc\Media\Hydration\Settings::OPTION_EXECUTION_MODE, $fields);
        $this->assertSame('browser_chunks', (string) ($fields[\Dbvc\Media\Hydration\Settings::OPTION_EXECUTION_MODE]['default'] ?? ''));
        $this->assertArrayHasKey(\Dbvc\Media\Hydration\Settings::OPTION_NORMALIZE_MEDIA_URLS_TO_HTTPS, $fields);
    }

    public function test_hydration_settings_sanitize_admin_post_values(): void
    {
        $settings = \Dbvc\Media\Hydration\Settings::save_from_post([
            \Dbvc\Media\Hydration\Settings::OPTION_ENABLED => '1',
            \Dbvc\Media\Hydration\Settings::OPTION_SOURCE => 'remote_only',
            \Dbvc\Media\Hydration\Settings::OPTION_MATCH_POLICY => 'bad_policy',
            \Dbvc\Media\Hydration\Settings::OPTION_ALLOWED_MIME_GROUPS => ['image', 'bad-group'],
            \Dbvc\Media\Hydration\Settings::OPTION_BATCH_SIZE => 9999,
            \Dbvc\Media\Hydration\Settings::OPTION_EXECUTION_MODE => 'bad_mode',
            \Dbvc\Media\Hydration\Settings::OPTION_LOCK_TIMEOUT_MINUTES => 0,
            \Dbvc\Media\Hydration\Settings::OPTION_NORMALIZE_MEDIA_URLS_TO_HTTPS => '1',
        ]);

        $this->assertSame('1', (string) ($settings[\Dbvc\Media\Hydration\Settings::OPTION_ENABLED] ?? ''));
        $this->assertSame('bundle_only', (string) ($settings[\Dbvc\Media\Hydration\Settings::OPTION_SOURCE] ?? ''));
        $this->assertSame('same_id_then_uid', (string) ($settings[\Dbvc\Media\Hydration\Settings::OPTION_MATCH_POLICY] ?? ''));
        $this->assertSame(['image'], $settings[\Dbvc\Media\Hydration\Settings::OPTION_ALLOWED_MIME_GROUPS] ?? []);
        $this->assertSame(500, (int) ($settings[\Dbvc\Media\Hydration\Settings::OPTION_BATCH_SIZE] ?? 0));
        $this->assertSame('browser_chunks', (string) ($settings[\Dbvc\Media\Hydration\Settings::OPTION_EXECUTION_MODE] ?? ''));
        $this->assertSame(30, (int) ($settings[\Dbvc\Media\Hydration\Settings::OPTION_LOCK_TIMEOUT_MINUTES] ?? 0));
        $this->assertSame('1', (string) ($settings[\Dbvc\Media\Hydration\Settings::OPTION_NORMALIZE_MEDIA_URLS_TO_HTTPS] ?? ''));

        $settings = \Dbvc\Media\Hydration\Settings::save_from_post([
            \Dbvc\Media\Hydration\Settings::OPTION_EXECUTION_MODE => 'background_job',
        ]);
        $this->assertSame('background_job', (string) ($settings[\Dbvc\Media\Hydration\Settings::OPTION_EXECUTION_MODE] ?? ''));
    }

    public function test_media_hydration_rest_inventory_requires_manage_options(): void
    {
        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);

        $request = new WP_REST_Request('GET', '/dbvc/v1/media-hydration/inventory');
        $response = rest_get_server()->dispatch($request);

        $this->assertSame(403, $response->get_status());
    }

    public function test_media_hydration_rest_preflight_requires_enabled_workflow(): void
    {
        update_option(\Dbvc\Media\Hydration\Settings::OPTION_ENABLED, '0');

        $request = new WP_REST_Request('POST', '/dbvc/v1/media-hydration/preflight');
        $request->set_body_params([
            'manifest' => [],
        ]);
        $response = rest_get_server()->dispatch($request);
        $data = $response->get_data();

        $this->assertSame(403, $response->get_status());
        $this->assertSame('dbvc_media_hydration_disabled', (string) ($data['code'] ?? ''));
    }

    public function test_media_hydration_rest_preflight_accepts_manifest_payload(): void
    {
        $attachment_id = $this->create_attachment_with_missing_file('2026/06/dbvc-rest-plan-missing.jpg', 'image/jpeg');
        $manifest = $this->build_manifest_for_entries([[
            'source_id' => $attachment_id,
            'relative_path' => '2026/06/dbvc-rest-plan-missing.jpg',
            'mime_type' => 'image/jpeg',
            'mime_group' => 'image',
            'file_status' => 'exists',
            'file_hash' => 'sha256:' . hash('sha256', 'source-bytes'),
            'file_size' => 12,
        ]]);

        $request = new WP_REST_Request('POST', '/dbvc/v1/media-hydration/preflight');
        $request->set_body_params([
            'manifest' => $manifest,
        ]);
        $response = rest_get_server()->dispatch($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('dbvc_media_hydration_plan', (string) ($data['kind'] ?? ''));
        $this->assertSame(1, (int) ($data['summary']['needs_hydration'] ?? 0));
        $this->assertNotSame('', (string) ($data['saved_plan']['plan_id'] ?? ''));
        $this->directories[] = dirname((string) ($data['saved_plan']['plan_path'] ?? ''));
    }

    public function test_media_hydration_rest_package_export_creates_manifest_package(): void
    {
        $attachment_id = $this->create_attachment_with_file('dbvc-rest-package.pdf', 'application/pdf', 'rest-package');
        $package_id = 'phpunit-rest-media-package-' . wp_generate_password(8, false, false);

        $request = new WP_REST_Request('POST', '/dbvc/v1/media-hydration/package/export');
        $request->set_body_params([
            'ids' => (string) $attachment_id,
            'include_files' => true,
            'create_zip' => false,
            'package_id' => $package_id,
        ]);
        $response = rest_get_server()->dispatch($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertFileExists((string) ($data['manifest_path'] ?? ''));
        $this->assertSame(1, (int) ($data['copy_stats']['copied'] ?? 0));
        $this->assertSame('dbvc_media_mirror', (string) ($data['manifest']['kind'] ?? ''));
        $this->directories[] = (string) ($data['package_dir'] ?? '');

        $list_request = new WP_REST_Request('GET', '/dbvc/v1/media-hydration/packages');
        $list_request->set_query_params(['limit' => 20]);
        $list_response = rest_get_server()->dispatch($list_request);
        $list_data = $list_response->get_data();
        $package_ids = array_map(static function ($package) {
            return (string) ($package['package_id'] ?? '');
        }, (array) ($list_data['packages'] ?? []));

        $this->assertSame(200, $list_response->get_status());
        $this->assertContains($package_id, $package_ids);
    }

    public function test_media_hydration_rest_receipts_returns_download_url(): void
    {
        $stored = \Dbvc\Media\Hydration\HydrationReceiptStore::write('apply', [
            'kind' => 'dbvc_media_hydration_apply_report',
            'schema' => 1,
            'summary' => [
                'items' => 1,
                'hydrated' => 1,
            ],
        ], [
            'run_id' => 'phpunit-receipt-' . wp_generate_password(8, false, false),
        ]);
        $this->assertIsArray($stored);
        $this->directories[] = dirname((string) ($stored['receipt_path'] ?? ''));

        $request = new WP_REST_Request('GET', '/dbvc/v1/media-hydration/receipts');
        $request->set_query_params(['limit' => 10]);
        $response = rest_get_server()->dispatch($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertNotEmpty($data['receipts'] ?? []);
        $receipt = (array) ($data['receipts'][0] ?? []);
        $this->assertStringContainsString('action=dbvc_media_hydration_receipt_download', (string) ($receipt['download_url'] ?? ''));
        $this->assertStringNotContainsString('&amp;', (string) ($receipt['download_url'] ?? ''));
        $resolved = \Dbvc\Media\Hydration\HydrationReceiptStore::resolve_receipt_path((string) ($stored['receipt_id'] ?? ''));
        $this->assertSame(wp_normalize_path((string) ($stored['receipt_path'] ?? '')), $resolved);
    }

    public function test_media_hydration_rest_receipts_cleanup_deletes_only_old_receipts(): void
    {
        $old = \Dbvc\Media\Hydration\HydrationReceiptStore::write('apply', [
            'kind' => 'dbvc_media_hydration_apply_report',
            'summary' => ['items' => 1, 'errors' => 1],
            'items' => [
                [
                    'source_id' => 123,
                    'result' => 'error',
                ],
            ],
        ], [
            'run_id' => 'phpunit-old-receipt-' . wp_generate_password(8, false, false),
        ]);
        $this->assertIsArray($old);

        $recent = \Dbvc\Media\Hydration\HydrationReceiptStore::write('plan', [
            'kind' => 'dbvc_media_hydration_plan',
            'summary' => ['items' => 1],
        ], [
            'run_id' => 'phpunit-recent-receipt-' . wp_generate_password(8, false, false),
        ]);
        $this->assertIsArray($recent);

        $old_path = (string) ($old['receipt_path'] ?? '');
        $recent_path = (string) ($recent['receipt_path'] ?? '');
        $this->assertFileExists($old_path);
        $this->assertFileExists($recent_path);
        $this->directories[] = dirname($old_path);

        $day_seconds = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;
        touch($old_path, time() - (40 * $day_seconds));
        touch($recent_path, time());

        $request = new WP_REST_Request('DELETE', '/dbvc/v1/media-hydration/receipts');
        $request->set_body_params([
            'older_than_days' => 30,
        ]);
        $response = rest_get_server()->dispatch($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(1, (int) ($data['cleanup']['deleted'] ?? 0));
        $this->assertFileDoesNotExist($old_path);
        $this->assertFileExists($recent_path);

        $bad = \Dbvc\Media\Hydration\HydrationReceiptStore::delete_older_than(0);
        $this->assertWPError($bad);
        $this->assertSame('dbvc_media_hydration_bad_receipt_cleanup_age', $bad->get_error_code());
    }

    public function test_media_hydration_rest_package_export_returns_download_url_for_zip(): void
    {
        if (! class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for media hydration ZIP export tests.');
        }

        $attachment_id = $this->create_attachment_with_file('dbvc-rest-package-zip.pdf', 'application/pdf', 'rest-package-zip');
        $package_id = 'phpunit-rest-media-zip-' . wp_generate_password(8, false, false);

        $request = new WP_REST_Request('POST', '/dbvc/v1/media-hydration/package/export');
        $request->set_body_params([
            'ids' => (string) $attachment_id,
            'include_files' => true,
            'create_zip' => true,
            'package_id' => $package_id,
        ]);
        $response = rest_get_server()->dispatch($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertFileExists((string) ($data['zip_path'] ?? ''));
        $this->files[] = (string) ($data['zip_path'] ?? '');
        $this->directories[] = (string) ($data['package_dir'] ?? '');

        $download_url = (string) ($data['download_url'] ?? '');
        $this->assertStringContainsString('action=dbvc_media_hydration_package_download', $download_url);
        $this->assertStringContainsString('package_id=' . rawurlencode($package_id), $download_url);
        $this->assertStringContainsString('_wpnonce=', $download_url);
        $this->assertStringNotContainsString('&amp;', $download_url);
        parse_str((string) wp_parse_url($download_url, PHP_URL_QUERY), $download_query);
        $this->assertSame($package_id, (string) ($download_query['package_id'] ?? ''));
        $this->assertSame('dbvc_media_hydration_package_download', (string) ($download_query['action'] ?? ''));

        $resolved = \Dbvc\Media\Hydration\PackageDownloadController::resolve_zip_path($package_id);
        $this->assertSame(wp_normalize_path((string) ($data['zip_path'] ?? '')), $resolved);

        $missing = \Dbvc\Media\Hydration\PackageDownloadController::resolve_zip_path('missing-' . wp_generate_password(8, false, false));
        $this->assertWPError($missing);
        $this->assertSame('dbvc_media_hydration_zip_missing', $missing->get_error_code());
    }

    public function test_media_hydration_package_import_extracts_zip_to_media_mirrors(): void
    {
        if (! class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for media hydration package import tests.');
        }

        $relative = '2026/06/dbvc-uploaded-package.jpg';
        $zip_path = $this->create_media_hydration_zip([
            [
                'source_id' => 123,
                'relative_path' => $relative,
                'mime_type' => 'image/jpeg',
                'mime_group' => 'image',
                'file_status' => 'exists',
                'file_hash' => 'sha256:' . hash('sha256', 'uploaded-image'),
                'file_size' => strlen('uploaded-image'),
            ],
        ], [
            $relative => 'uploaded-image',
        ], 'source-package/');

        $package_id = 'phpunit-media-upload-' . wp_generate_password(8, false, false);
        $result = \Dbvc\Media\Hydration\PackageImportService::import_zip($zip_path, [
            'package_id' => $package_id,
            'source_name' => 'canonical-media.zip',
        ]);

        $this->assertIsArray($result);
        $this->directories[] = (string) ($result['package_dir'] ?? '');
        $this->assertSame($package_id, (string) ($result['package_id'] ?? ''));
        $this->assertFileExists((string) ($result['manifest_path'] ?? ''));
        $this->assertFileExists(trailingslashit((string) ($result['package_dir'] ?? '')) . 'media/' . $relative);
        $this->assertSame(2, (int) ($result['stats']['entries'] ?? 0));
        $this->assertSame(1, (int) ($result['stats']['media_files'] ?? 0));
    }

    public function test_media_hydration_package_import_rejects_traversal_zip_entry(): void
    {
        if (! class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for media hydration package import tests.');
        }

        $relative = '2026/06/dbvc-safe-package.jpg';
        $zip_path = $this->create_media_hydration_zip([
            [
                'source_id' => 124,
                'relative_path' => $relative,
                'mime_type' => 'image/jpeg',
                'mime_group' => 'image',
                'file_status' => 'exists',
                'file_hash' => 'sha256:' . hash('sha256', 'safe-image'),
                'file_size' => strlen('safe-image'),
            ],
        ], [
            $relative => 'safe-image',
        ], '', [
            '../evil.txt' => 'evil',
        ]);

        $result = \Dbvc\Media\Hydration\PackageImportService::import_zip($zip_path, [
            'package_id' => 'phpunit-bad-traversal-' . wp_generate_password(8, false, false),
        ]);

        $this->assertWPError($result);
        $this->assertSame('dbvc_media_hydration_bad_zip_entry', $result->get_error_code());
    }

    public function test_media_hydration_package_import_rejects_unmanifested_media_file(): void
    {
        if (! class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for media hydration package import tests.');
        }

        $relative = '2026/06/dbvc-manifested-package.jpg';
        $zip_path = $this->create_media_hydration_zip([
            [
                'source_id' => 125,
                'relative_path' => $relative,
                'mime_type' => 'image/jpeg',
                'mime_group' => 'image',
                'file_status' => 'exists',
                'file_hash' => 'sha256:' . hash('sha256', 'manifested-image'),
                'file_size' => strlen('manifested-image'),
            ],
        ], [
            $relative => 'manifested-image',
        ], '', [
            'media/2026/06/not-in-manifest.jpg' => 'extra',
        ]);

        $result = \Dbvc\Media\Hydration\PackageImportService::import_zip($zip_path, [
            'package_id' => 'phpunit-extra-media-' . wp_generate_password(8, false, false),
        ]);

        $this->assertWPError($result);
        $this->assertSame('dbvc_media_hydration_unexpected_media_file', $result->get_error_code());
    }

    public function test_media_hydration_rest_apply_requires_dry_run_acknowledgement_when_enabled(): void
    {
        update_option(\Dbvc\Media\Hydration\Settings::OPTION_REQUIRE_DRY_RUN, '1');

        $request = new WP_REST_Request('POST', '/dbvc/v1/media-hydration/apply');
        $request->set_body_params([
            'confirm' => 'hydrate-existing-media',
        ]);
        $response = rest_get_server()->dispatch($request);
        $data = $response->get_data();

        $this->assertSame(400, $response->get_status());
        $this->assertSame('dbvc_media_hydration_dry_run_required', (string) ($data['code'] ?? ''));
    }

    public function test_media_hydration_rest_job_creation_requires_dry_run_acknowledgement_when_enabled(): void
    {
        update_option(\Dbvc\Media\Hydration\Settings::OPTION_REQUIRE_DRY_RUN, '1');

        $request = new WP_REST_Request('POST', '/dbvc/v1/media-hydration/jobs');
        $request->set_body_params([
            'confirm' => 'hydrate-existing-media',
        ]);
        $response = rest_get_server()->dispatch($request);
        $data = $response->get_data();

        $this->assertSame(400, $response->get_status());
        $this->assertSame('dbvc_media_hydration_dry_run_required', (string) ($data['code'] ?? ''));
    }

    public function test_media_hydration_rest_job_creation_and_run_hydrates_existing_attachment(): void
    {
        if (class_exists('DBVC_Database')) {
            DBVC_Database::activate();
        }

        update_option(\Dbvc\Media\Hydration\Settings::OPTION_REQUIRE_DRY_RUN, '1');
        update_option(\Dbvc\Media\Hydration\Settings::OPTION_RECEIPTS_ENABLED, '0');

        $uploads = wp_get_upload_dir();
        $this->assertEmpty($uploads['error']);

        $relative = '2026/06/dbvc-background-job.pdf';
        $attachment_id = $this->create_attachment_with_missing_file($relative, 'application/pdf');
        $this->files[] = trailingslashit((string) $uploads['basedir']) . $relative;

        $manifest = $this->build_valid_media_hydration_manifest([
            [
                'source_id' => $attachment_id,
                'relative_path' => $relative,
                'mime_type' => 'application/pdf',
                'mime_group' => 'document',
                'file_status' => 'exists',
                'file_hash' => 'sha256:' . hash('sha256', 'background-job-pdf'),
                'file_size' => strlen('background-job-pdf'),
            ],
        ]);
        $package_dir = $this->create_package_with_manifest($manifest, [
            $relative => 'background-job-pdf',
        ]);
        $manifest_path = trailingslashit($package_dir) . \Dbvc\Media\Hydration\MirrorManifestBuilder::MANIFEST_FILENAME;

        $plan = \Dbvc\Media\Hydration\HydrationPlanner::plan($manifest);
        $this->assertIsArray($plan);
        $stored = \Dbvc\Media\Hydration\HydrationPlanStore::write($plan, [
            'plan_id' => 'phpunit-background-job-plan-' . wp_generate_password(8, false, false),
        ]);
        $this->assertIsArray($stored);
        $this->directories[] = dirname((string) ($stored['plan_path'] ?? ''));

        $request = new WP_REST_Request('POST', '/dbvc/v1/media-hydration/jobs');
        $request->set_body_params([
            'confirm' => 'hydrate-existing-media',
            'manifest_path' => $manifest_path,
            'plan_id' => (string) ($stored['plan_id'] ?? ''),
            'repair_metadata' => false,
            'limit' => 1,
        ]);
        $response = rest_get_server()->dispatch($request);
        $data = $response->get_data();

        $this->assertSame(201, $response->get_status());
        $job = (array) ($data['job'] ?? []);
        $this->assertGreaterThan(0, (int) ($job['job_id'] ?? 0));
        $this->assertSame('media_hydration', (string) ($job['job_type'] ?? ''));
        $this->assertSame('queued', (string) ($job['status'] ?? ''));
        $this->assertSame(1, (int) ($job['context']['total_plan_items'] ?? 0));
        $this->assertSame(0, (int) ($job['context']['source_id_count'] ?? -1));
        $this->assertArrayNotHasKey('source_ids', (array) ($job['context'] ?? []));

        $status_request = new WP_REST_Request('GET', '/dbvc/v1/media-hydration/jobs/' . (int) ($job['job_id'] ?? 0));
        $status_response = rest_get_server()->dispatch($status_request);
        $status_data = $status_response->get_data();
        $this->assertSame(200, $status_response->get_status());
        $this->assertSame((int) ($job['job_id'] ?? 0), (int) ($status_data['job']['job_id'] ?? 0));

        $run_request = new WP_REST_Request('POST', '/dbvc/v1/media-hydration/jobs/' . (int) ($job['job_id'] ?? 0) . '/run');
        $run_response = rest_get_server()->dispatch($run_request);
        $run_data = $run_response->get_data();

        $this->assertSame(200, $run_response->get_status());
        $this->assertSame('completed', (string) ($run_data['job']['status'] ?? ''));
        $this->assertSame(100, (int) ($run_data['job']['progress'] ?? 0));
        $this->assertSame(1, (int) ($run_data['report']['summary']['hydrated'] ?? 0));
        $this->assertSame($attachment_id, (int) ($run_data['report']['items'][0]['target_id'] ?? 0));
        $this->assertTrue(file_exists(trailingslashit((string) $uploads['basedir']) . $relative));
    }

    public function test_media_hydration_rest_apply_rejects_mismatched_saved_plan(): void
    {
        update_option(\Dbvc\Media\Hydration\Settings::OPTION_REQUIRE_DRY_RUN, '1');

        $attachment_id = $this->create_attachment_with_file('dbvc-rest-plan-package.pdf', 'application/pdf', 'plan-package');
        $package = \Dbvc\Media\Hydration\MirrorManifestBuilder::build_package([
            'package_id' => 'phpunit-rest-plan-mismatch-' . wp_generate_password(8, false, false),
            'attachment_ids' => [$attachment_id],
            'include_files' => true,
            'create_zip' => false,
            'batch_size' => 10,
        ]);
        $this->assertIsArray($package);
        $this->directories[] = (string) ($package['package_dir'] ?? '');

        $plan_manifest = $package['manifest'];
        $plan_manifest['checksum'] = 'sha256:plan';
        $plan = \Dbvc\Media\Hydration\HydrationPlanner::plan($plan_manifest);
        $this->assertIsArray($plan);
        $stored = \Dbvc\Media\Hydration\HydrationPlanStore::write($plan, [
            'plan_id' => 'phpunit-rest-mismatch-plan-' . wp_generate_password(8, false, false),
        ]);
        $this->assertIsArray($stored);
        $this->directories[] = dirname((string) ($stored['plan_path'] ?? ''));

        $request = new WP_REST_Request('POST', '/dbvc/v1/media-hydration/apply');
        $request->set_body_params([
            'confirm' => 'hydrate-existing-media',
            'plan_id' => (string) ($stored['plan_id'] ?? ''),
            'manifest_path' => (string) ($package['manifest_path'] ?? ''),
        ]);
        $response = rest_get_server()->dispatch($request);
        $data = $response->get_data();

        $this->assertSame(400, $response->get_status());
        $this->assertSame('dbvc_media_hydration_plan_manifest_mismatch', (string) ($data['code'] ?? ''));
    }

    public function test_media_hydration_rest_apply_can_retry_failed_items_from_receipt(): void
    {
        update_option(\Dbvc\Media\Hydration\Settings::OPTION_REQUIRE_DRY_RUN, '0');

        $uploads = wp_get_upload_dir();
        $this->assertEmpty($uploads['error']);

        $first_relative = '2026/06/dbvc-retry-ok.pdf';
        $second_relative = '2026/06/dbvc-retry-failed.pdf';
        $first_id = $this->create_attachment_with_missing_file($first_relative, 'application/pdf');
        $second_id = $this->create_attachment_with_missing_file($second_relative, 'application/pdf');
        $this->files[] = trailingslashit((string) $uploads['basedir']) . $first_relative;
        $this->files[] = trailingslashit((string) $uploads['basedir']) . $second_relative;

        $manifest = $this->build_valid_media_hydration_manifest([
            [
                'source_id' => $first_id,
                'relative_path' => $first_relative,
                'mime_type' => 'application/pdf',
                'mime_group' => 'document',
                'file_status' => 'exists',
                'file_hash' => 'sha256:' . hash('sha256', 'retry-ok'),
                'file_size' => strlen('retry-ok'),
            ],
            [
                'source_id' => $second_id,
                'relative_path' => $second_relative,
                'mime_type' => 'application/pdf',
                'mime_group' => 'document',
                'file_status' => 'exists',
                'file_hash' => 'sha256:' . hash('sha256', 'retry-failed'),
                'file_size' => strlen('retry-failed'),
            ],
        ]);
        $package_dir = $this->create_package_with_manifest($manifest, [
            $first_relative => 'retry-ok',
            $second_relative => 'retry-failed',
        ]);
        $manifest_path = trailingslashit($package_dir) . \Dbvc\Media\Hydration\MirrorManifestBuilder::MANIFEST_FILENAME;

        $stored = \Dbvc\Media\Hydration\HydrationReceiptStore::write('apply', [
            'kind' => 'dbvc_media_hydration_apply_report',
            'summary' => [
                'items' => 2,
                'hydrated' => 1,
                'blocked' => 0,
                'errors' => 1,
            ],
            'items' => [
                [
                    'source_id' => $first_id,
                    'target_id' => $first_id,
                    'result' => 'hydrated',
                ],
                [
                    'source_id' => $second_id,
                    'target_id' => $second_id,
                    'result' => 'error',
                    'reason' => 'source_package_hash_mismatch',
                ],
            ],
        ], [
            'run_id' => 'phpunit-retry-' . wp_generate_password(8, false, false),
        ]);
        $this->assertIsArray($stored);

        $failed_ids = \Dbvc\Media\Hydration\HydrationReceiptStore::failed_source_ids((string) ($stored['receipt_id'] ?? ''));
        $this->assertSame([$second_id], $failed_ids);

        $request = new WP_REST_Request('POST', '/dbvc/v1/media-hydration/apply');
        $request->set_body_params([
            'confirm' => 'hydrate-existing-media',
            'manifest_path' => $manifest_path,
            'retry_receipt_id' => (string) ($stored['receipt_id'] ?? ''),
            'repair_metadata' => false,
        ]);
        $response = rest_get_server()->dispatch($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(1, (int) ($data['retry']['source_id_count'] ?? 0));
        $this->assertSame(1, (int) ($data['policy']['source_id_filter_count'] ?? 0));
        $this->assertSame(1, (int) ($data['pagination']['total_plan_items'] ?? 0));
        $this->assertSame(1, (int) ($data['summary']['hydrated'] ?? 0));
        $this->assertSame($second_id, (int) ($data['items'][0]['source_id'] ?? 0));
        $this->assertFalse(file_exists(trailingslashit((string) $uploads['basedir']) . $first_relative));
        $this->assertTrue(file_exists(trailingslashit((string) $uploads['basedir']) . $second_relative));
    }

    private function create_attachment_with_file(string $filename, string $mime_type, string $contents): int
    {
        $uploads = wp_get_upload_dir();
        $this->assertEmpty($uploads['error']);

        $subdir = trailingslashit((string) $uploads['basedir']) . 'dbvc-media-hydration-tests';
        wp_mkdir_p($subdir);

        $path = trailingslashit($subdir) . sanitize_file_name($filename);
        file_put_contents($path, $contents);
        $this->files[] = $path;

        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $mime_type,
            'post_title' => pathinfo($filename, PATHINFO_FILENAME),
            'post_status' => 'inherit',
        ], $path);

        $this->assertGreaterThan(0, (int) $attachment_id);
        update_attached_file((int) $attachment_id, $path);

        return (int) $attachment_id;
    }

    private function create_package_with_media(string $relative_path, string $contents): string
    {
        $uploads = wp_get_upload_dir();
        $this->assertEmpty($uploads['error']);

        $package_dir = trailingslashit((string) $uploads['basedir']) . 'dbvc-media-hydration-package-' . wp_generate_password(8, false, false);
        $media_path = trailingslashit($package_dir) . 'media/' . \Dbvc\Media\Hydration\FileStateService::normalize_relative_path($relative_path);
        wp_mkdir_p(dirname($media_path));
        file_put_contents($media_path, $contents);
        $this->directories[] = $package_dir;

        return $package_dir;
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,string> $media_files
     * @return string
     */
    private function create_package_with_manifest(array $manifest, array $media_files): string
    {
        $package_dir = $this->create_package_with_media('placeholder.txt', 'placeholder');
        $placeholder = trailingslashit($package_dir) . 'media/placeholder.txt';
        if (file_exists($placeholder)) {
            @unlink($placeholder);
        }

        file_put_contents(
            trailingslashit($package_dir) . \Dbvc\Media\Hydration\MirrorManifestBuilder::MANIFEST_FILENAME,
            wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        foreach ($media_files as $relative => $contents) {
            $media_path = trailingslashit($package_dir) . 'media/' . \Dbvc\Media\Hydration\FileStateService::normalize_relative_path($relative);
            wp_mkdir_p(dirname($media_path));
            file_put_contents($media_path, $contents);
        }

        return $package_dir;
    }

    /**
     * @param array<int,array<string,mixed>> $entries
     * @param array<string,string> $media_files
     * @param array<string,string> $extra_files
     */
    private function create_media_hydration_zip(array $entries, array $media_files, string $prefix = '', array $extra_files = []): string
    {
        $uploads = wp_get_upload_dir();
        $this->assertEmpty($uploads['error']);

        $dir = trailingslashit((string) $uploads['basedir']) . 'dbvc-media-hydration-zips';
        wp_mkdir_p($dir);
        $this->directories[] = $dir;
        $zip_path = trailingslashit($dir) . 'media-hydration-' . wp_generate_password(8, false, false) . '.zip';
        $this->files[] = $zip_path;

        $manifest = $this->build_valid_media_hydration_manifest($entries);
        $prefix = trim(str_replace('\\', '/', $prefix), '/');
        $prefix = $prefix !== '' ? $prefix . '/' : '';

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString($prefix . \Dbvc\Media\Hydration\MirrorManifestBuilder::MANIFEST_FILENAME, wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        foreach ($media_files as $relative => $contents) {
            $relative = \Dbvc\Media\Hydration\FileStateService::normalize_relative_path($relative);
            $this->assertNotSame('', $relative);
            $zip->addFromString($prefix . 'media/' . $relative, $contents);
        }

        foreach ($extra_files as $relative => $contents) {
            $zip->addFromString($relative, $contents);
        }

        $zip->close();

        return $zip_path;
    }

    private function create_attachment_with_missing_file(string $relative_path, string $mime_type): int
    {
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $mime_type,
            'post_title' => pathinfo($relative_path, PATHINFO_FILENAME),
            'post_status' => 'inherit',
        ]);

        $this->assertGreaterThan(0, (int) $attachment_id);
        update_post_meta((int) $attachment_id, '_wp_attached_file', ltrim(str_replace('\\', '/', $relative_path), '/'));

        return (int) $attachment_id;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function index_by_attachment_id(array $items): array
    {
        $indexed = [];
        foreach ($items as $item) {
            $id = isset($item['attachment_id']) ? (int) $item['attachment_id'] : 0;
            if ($id > 0) {
                $indexed[$id] = $item;
            }
        }

        return $indexed;
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

        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($path);
    }

    /**
     * @param array<int,array<string,mixed>> $entries
     * @return array<string,mixed>
     */
    private function build_manifest_for_entries(array $entries): array
    {
        return [
            'schema' => 1,
            'kind' => 'dbvc_media_mirror',
            'generated_at' => gmdate('c'),
            'source_site' => [
                'home_url' => home_url(),
                'site_url' => site_url(),
            ],
            'summary' => [
                'attachments' => count($entries),
            ],
            'attachments' => $entries,
            'checksum' => 'sha256:test',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $entries
     * @return array<string,mixed>
     */
    private function build_valid_media_hydration_manifest(array $entries): array
    {
        $manifest = $this->build_manifest_for_entries($entries);
        unset($manifest['checksum']);
        $manifest['package'] = [
            'files_included' => true,
            'media_root' => 'media',
        ];
        $manifest['checksum'] = 'sha256:' . hash('sha256', (string) wp_json_encode($manifest, JSON_UNESCAPED_SLASHES));

        return $manifest;
    }

    /**
     * @return string[]
     */
    private function hydration_option_keys(): array
    {
        return [
            \Dbvc\Media\Hydration\Settings::OPTION_ENABLED,
            \Dbvc\Media\Hydration\Settings::OPTION_SOURCE,
            \Dbvc\Media\Hydration\Settings::OPTION_MATCH_POLICY,
            \Dbvc\Media\Hydration\Settings::OPTION_OVERWRITE_POLICY,
            \Dbvc\Media\Hydration\Settings::OPTION_METADATA_POLICY,
            \Dbvc\Media\Hydration\Settings::OPTION_ALLOWED_MIME_GROUPS,
            \Dbvc\Media\Hydration\Settings::OPTION_BATCH_SIZE,
            \Dbvc\Media\Hydration\Settings::OPTION_EXECUTION_MODE,
            \Dbvc\Media\Hydration\Settings::OPTION_REQUIRE_DRY_RUN,
            \Dbvc\Media\Hydration\Settings::OPTION_RECEIPTS_ENABLED,
            \Dbvc\Media\Hydration\Settings::OPTION_STRICT_HASHES,
            \Dbvc\Media\Hydration\Settings::OPTION_CLONE_CONFIRMATION,
            \Dbvc\Media\Hydration\Settings::OPTION_NORMALIZE_MEDIA_URLS_TO_HTTPS,
            \Dbvc\Media\Hydration\Settings::OPTION_LOCK_TIMEOUT_MINUTES,
        ];
    }
}
