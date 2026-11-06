<?php

/**
 * Protects the claim that a Content Migration import followed by a rollback
 * leaves the target site's posts, post meta, terms, term relationships and media
 * library byte-identical.
 *
 * The existing executor suite asserts restored *field values*. This one asserts
 * restored *rows*: it reads wp_posts, wp_postmeta, wp_terms, wp_termmeta,
 * wp_term_taxonomy and wp_term_relationships straight out of $wpdb before and
 * after, with every column, and hashes every file under the uploads directory.
 * That is a different claim, and it is the one G-7 makes.
 *
 * One exclusion is named rather than hidden: post_modified / post_modified_gmt on
 * a row the import updated. The executor's before-state capture records eight
 * post columns and post_modified is not one of them, so the wp_update_post() that
 * restores the row stamps it with the current time. The test asserts that this is
 * the *only* difference, so any other drift fails here.
 *
 * The same comparison is run against a disposable clone of a real site by
 * addons/content-migration/tools/dbvc-cc-import-net-zero-verify.sh; see the
 * net-zero section of docs/implementation/proposed/content-collection-v3/README.md.
 */
class ContentMigrationImportNetZeroTest extends WP_UnitTestCase
{
    private const DOMAIN = 'net-zero.test';
    private const RELATIVE_PATH = 'home';
    private const MEDIA_SOURCE_URL = 'https://net-zero.test/wp-content/uploads/2026/03/hero.jpg';

    /**
     * @var string
     */
    private $domain_dir = '';

    /**
     * @var array<string, mixed>
     */
    private $original_settings = [];

    /**
     * @var string
     */
    private $fixture_jpeg = '';

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->reset_import_run_ledger();

        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_WORKBENCH, '1');
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_CATALOG_BRIDGE, '1');
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MEDIA_MAPPING_BRIDGE, '1');
        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');

        $this->original_settings = DBVC_CC_Settings_Service::get_options();
        $settings = $this->original_settings;
        $settings['storage_path'] = DBVC_CC_Contracts::STORAGE_DEFAULT_PATH;
        update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $settings);

        register_post_meta('page', 'hero_subheading', ['type' => 'string', 'single' => true, 'show_in_rest' => true]);
        register_post_meta('page', 'faq_question', ['type' => 'string', 'single' => false, 'show_in_rest' => true]);

        $this->fixture_jpeg = $this->build_fixture_jpeg();
        add_filter('pre_http_request', [$this, 'serve_fixture_image'], 10, 3);

        $this->build_fixture_artifacts();
        $this->prepare_ready_mapping_inputs();
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'serve_fixture_image'], 10);
        if ($this->fixture_jpeg !== '' && file_exists($this->fixture_jpeg)) {
            @unlink($this->fixture_jpeg);
        }
        if ($this->domain_dir !== '') {
            $this->remove_directory($this->domain_dir);
        }
        $this->reset_import_run_ledger();
        if (! empty($this->original_settings)) {
            update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $this->original_settings);
        }

        parent::tear_down();
    }

    public function test_import_then_rollback_leaves_content_rows_and_media_files_byte_identical(): void
    {
        $existing = $this->seed_existing_site_content();

        $before = $this->capture_site_state();

        $approval_token = $this->approve_preflight_and_get_token();
        $execute = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            self::DOMAIN,
            self::RELATIVE_PATH,
            false,
            true,
            $approval_token
        );

        $this->assertIsArray($execute);
        $this->assertSame('completed', (string) $execute['status'], 'execute did not complete: ' . wp_json_encode($execute['execution_failures'] ?? []));
        $this->assertSame($existing['page_id'], (int) $execute['entity_write_execution']['operations'][0]['target_entity_id']);
        $this->assertSame(1, (int) $execute['operation_counts']['executed_entity_updates']);
        $this->assertSame(1, (int) $execute['operation_counts']['created_media_attachments']);

        // The import really did change the site: without this the comparison below
        // would pass on a run that wrote nothing.
        $during = $this->capture_site_state();
        $this->assertNotSame($before['posts'], $during['posts'], 'the import wrote no post rows');
        $this->assertNotSame($before['postmeta'], $during['postmeta'], 'the import wrote no post meta rows');
        $this->assertNotSame($before['uploads'], $during['uploads'], 'the import wrote no media files');
        $created_attachment_id = (int) get_post_thumbnail_id($existing['page_id']);
        $this->assertGreaterThan(0, $created_attachment_id);
        $this->assertNotSame($existing['attachment_id'], $created_attachment_id);

        $rollback = DBVC_CC_Import_Executor_Service::get_instance()->rollback_run((int) $execute['run_id']);
        $this->assertIsArray($rollback);
        $this->assertSame('completed', (string) $rollback['status'], 'rollback failed: ' . wp_json_encode($rollback['failures']));
        $this->assertSame(0, (int) $rollback['failed_count']);

        $after = $this->capture_site_state();

        $this->assertSame(
            $before['postmeta_content'],
            $after['postmeta_content'],
            'post meta content is not identical after rollback'
        );
        // Named exclusion: restoring a multi-value meta key deletes and re-adds its
        // rows, so those rows come back with the same post_id, key and value under a
        // new meta_id. The surrogate key is in the same category as the table's
        // AUTO_INCREMENT counter: it advances and cannot rewind. Asserted narrowly,
        // so a changed key or value still fails above.
        $reissued = $this->reissued_postmeta($before['postmeta'], $after['postmeta']);
        $this->assertSame(
            ['faq_question', 'faq_question'],
            $reissued,
            'a post meta row other than the restored multi-value key was reissued'
        );

        $this->assertSame($before['terms'], $after['terms'], 'wp_terms is not byte-identical after rollback');
        $this->assertSame($before['termmeta'], $after['termmeta'], 'wp_termmeta is not byte-identical after rollback');
        $this->assertSame($before['term_taxonomy'], $after['term_taxonomy'], 'wp_term_taxonomy is not byte-identical after rollback');
        $this->assertSame($before['term_relationships'], $after['term_relationships'], 'wp_term_relationships is not byte-identical after rollback');
        $this->assertSame($before['uploads'], $after['uploads'], 'the uploads directory is not byte-identical after rollback');

        // wp_posts, with post_modified / post_modified_gmt blanked: the named
        // exclusion. Everything else in every row has to match.
        $this->assertSame(
            $this->without_post_modified($before['posts']),
            $this->without_post_modified($after['posts']),
            'wp_posts differs after rollback in a column other than post_modified'
        );

        // And the exclusion is exactly that: one row, two columns.
        $drifted = $this->rows_differing($before['posts'], $after['posts']);
        $this->assertSame([$existing['page_id']], array_keys($drifted), 'a row the import did not update changed');
        $this->assertSame(
            ['post_modified', 'post_modified_gmt'],
            $drifted[$existing['page_id']],
            'the updated row drifted in a column other than post_modified'
        );

        // The created attachment is gone from the database and from disk.
        $this->assertNull(get_post($created_attachment_id));
        $this->assertSame($existing['attachment_id'], (int) get_post_thumbnail_id($existing['page_id']));
        $this->assertFileExists($existing['attachment_file']);
    }

    public function test_rollback_restores_a_created_post_to_absence_without_touching_neighbours(): void
    {
        $neighbour = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'about',
            'post_title' => 'About',
            'post_content' => 'Untouched neighbour body.',
            'post_status' => 'publish',
        ]);
        add_post_meta($neighbour, 'hero_subheading', 'Untouched subheading');

        $before = $this->capture_site_state();

        $approval_token = $this->approve_preflight_and_get_token();
        $execute = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            self::DOMAIN,
            self::RELATIVE_PATH,
            false,
            true,
            $approval_token
        );
        $this->assertSame('completed', (string) $execute['status']);
        $this->assertSame(1, (int) $execute['operation_counts']['executed_entity_creates']);

        $created_post_id = (int) $execute['entity_write_execution']['operations'][0]['target_entity_id'];
        $this->assertInstanceOf(WP_Post::class, get_post($created_post_id));

        $rollback = DBVC_CC_Import_Executor_Service::get_instance()->rollback_run((int) $execute['run_id']);
        $this->assertSame('completed', (string) $rollback['status']);

        $after = $this->capture_site_state();

        // A created post leaves no trace at all, so even post_modified is net zero here.
        $this->assertSame($before['posts'], $after['posts'], 'wp_posts is not byte-identical after rolling back a create');
        $this->assertSame($before['postmeta'], $after['postmeta'], 'wp_postmeta is not byte-identical after rolling back a create');
        $this->assertSame($before['term_relationships'], $after['term_relationships']);
        $this->assertSame($before['uploads'], $after['uploads']);
        $this->assertNull(get_post($created_post_id));
        $this->assertSame('Untouched neighbour body.', (string) get_post($neighbour)->post_content);
    }

    public function test_site_state_capture_reads_every_post_column_so_a_drifted_column_cannot_hide(): void
    {
        // Guards the comparison itself: if capture_site_state() ever stopped
        // reading a column, the two tests above would pass while drifting.
        global $wpdb;
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->posts}");
        $page_id = self::factory()->post->create(['post_type' => 'page']);
        $state = $this->capture_site_state();
        $rows = json_decode($state['posts'], true);

        $this->assertIsArray($rows);
        $this->assertArrayHasKey((string) $page_id, $rows);
        $this->assertSame(
            array_values($columns),
            array_keys($rows[(string) $page_id]),
            'the posts capture does not cover every wp_posts column'
        );
    }

    /**
     * Captures the target site's authoritative state: whole rows, every column,
     * plus a sha256 of every file under the uploads directory.
     *
     * @return array<string, string>
     */
    private function capture_site_state()
    {
        global $wpdb;

        $keyed = static function ($rows, $key) {
            $out = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                $out[(string) $row[$key]] = $row;
            }
            ksort($out, SORT_STRING);

            return (string) wp_json_encode($out);
        };

        $upload_dir = wp_upload_dir();
        $base_dir = isset($upload_dir['basedir']) ? (string) $upload_dir['basedir'] : '';
        $files = [];
        if ($base_dir !== '' && is_dir($base_dir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base_dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $entry) {
                if (! $entry->isFile()) {
                    continue;
                }
                $path = (string) $entry->getPathname();
                // The add-on's own storage root holds the staged capture artifacts
                // and append-only run logs, which are inputs and audit records
                // rather than target-site media. Named in the guide's exclusions.
                if (strpos($path, trailingslashit($base_dir) . DBVC_CC_Contracts::STORAGE_DEFAULT_PATH) === 0) {
                    continue;
                }
                // Operating-system metadata. No import or rollback writes these, and the host
                // rewrites them whenever it likes -- macOS Finder rewrites .DS_Store in any
                // directory it displays. Because wp-tests-config.php puts ABSPATH in the main
                // checkout, every worktree shares one uploads tree, so a Finder window anywhere
                // near it was enough to fail this comparison with the file set completely
                // unchanged and only those two hashes differing. The assertion is about what the
                // importer leaves behind, not about what the OS does to a directory mid-run.
                //
                // This cannot make the comparison vacuous: the same test asserts that uploads
                // *does* differ during the import ("the import wrote no media files"), so if the
                // hash ever stopped tracking real media that assertion fails first.
                $filename = (string) $entry->getFilename();
                if (
                    $filename === '.DS_Store'
                    || $filename === 'Thumbs.db'
                    || $filename === 'desktop.ini'
                    || strpos($filename, '._') === 0
                ) {
                    continue;
                }
                $files[substr($path, strlen(trailingslashit($base_dir)))] = hash_file('sha256', $path);
            }
        }
        ksort($files, SORT_STRING);

        $postmeta_rows = $wpdb->get_results("SELECT * FROM {$wpdb->postmeta}", ARRAY_A);

        return [
            'posts' => $keyed($wpdb->get_results("SELECT * FROM {$wpdb->posts}", ARRAY_A), 'ID'),
            'postmeta' => $keyed($postmeta_rows, 'meta_id'),
            'postmeta_content' => (string) wp_json_encode($this->postmeta_content_multiset($postmeta_rows)),
            'terms' => $keyed($wpdb->get_results("SELECT * FROM {$wpdb->terms}", ARRAY_A), 'term_id'),
            'termmeta' => $keyed($wpdb->get_results("SELECT * FROM {$wpdb->termmeta}", ARRAY_A), 'meta_id'),
            'term_taxonomy' => $keyed($wpdb->get_results("SELECT * FROM {$wpdb->term_taxonomy}", ARRAY_A), 'term_taxonomy_id'),
            'term_relationships' => (string) wp_json_encode(
                $this->sorted_term_relationships($wpdb->get_results("SELECT * FROM {$wpdb->term_relationships}", ARRAY_A))
            ),
            'uploads' => (string) wp_json_encode($files),
        ];
    }

    /**
     * Post meta as content rather than as rows: the (post_id, meta_key, meta_value)
     * multiset, which is what "the site's meta came back" means. meta_id is a
     * surrogate key and is compared separately, because restoring a multi-value
     * key deletes and re-adds its rows and so reissues their ids.
     *
     * @param array<int, array<string, mixed>>|null $rows
     * @return array<int, string>
     */
    private function postmeta_content_multiset($rows)
    {
        $triples = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $triples[] = implode("\x1f", [
                (string) $row['post_id'],
                (string) $row['meta_key'],
                (string) $row['meta_value'],
            ]);
        }
        sort($triples, SORT_STRING);

        return $triples;
    }

    /**
     * @param array<int, array<string, mixed>>|null $rows
     * @return array<int, array<string, mixed>>
     */
    private function sorted_term_relationships($rows)
    {
        $rows = is_array($rows) ? $rows : [];
        usort($rows, static function ($left, $right) {
            return [(int) $left['object_id'], (int) $left['term_taxonomy_id']]
                <=> [(int) $right['object_id'], (int) $right['term_taxonomy_id']];
        });

        return $rows;
    }

    /**
     * The meta keys whose rows exist before and after with identical content but
     * under a different meta_id.
     *
     * @param string $before
     * @param string $after
     * @return array<int, string>
     */
    private function reissued_postmeta($before, $after)
    {
        $before_rows = json_decode((string) $before, true);
        $after_rows = json_decode((string) $after, true);
        $before_ids = array_keys(is_array($before_rows) ? $before_rows : []);
        $after_ids = array_keys(is_array($after_rows) ? $after_rows : []);

        $keys = [];
        foreach (array_diff($before_ids, $after_ids) as $missing_id) {
            $keys[] = (string) $before_rows[$missing_id]['meta_key'];
        }
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * @param string $encoded_posts
     * @return string
     */
    private function without_post_modified($encoded_posts)
    {
        $rows = json_decode((string) $encoded_posts, true);
        foreach (is_array($rows) ? $rows : [] as $id => $row) {
            $rows[$id]['post_modified'] = '<excluded>';
            $rows[$id]['post_modified_gmt'] = '<excluded>';
        }

        return (string) wp_json_encode($rows);
    }

    /**
     * @param string $before
     * @param string $after
     * @return array<int, array<int, string>> Post ID => the columns that changed.
     */
    private function rows_differing($before, $after)
    {
        $before_rows = json_decode((string) $before, true);
        $after_rows = json_decode((string) $after, true);
        $drifted = [];
        foreach (is_array($before_rows) ? $before_rows : [] as $id => $row) {
            if (! isset($after_rows[$id])) {
                $drifted[(int) $id] = ['<row removed>'];
                continue;
            }
            $columns = [];
            foreach ($row as $column => $value) {
                if ($after_rows[$id][$column] !== $value) {
                    $columns[] = (string) $column;
                }
            }
            if (! empty($columns)) {
                $drifted[(int) $id] = $columns;
            }
        }
        foreach (is_array($after_rows) ? $after_rows : [] as $id => $row) {
            if (! isset($before_rows[$id])) {
                $drifted[(int) $id] = ['<row added>'];
            }
        }

        return $drifted;
    }

    /**
     * @return array<string, mixed>
     */
    private function seed_existing_site_content()
    {
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => self::RELATIVE_PATH,
            'post_title' => 'Existing Home',
            'post_content' => "Existing body with an em dash — and a quote \"like this\".",
            'post_excerpt' => 'Existing excerpt.',
            'post_status' => 'publish',
            'menu_order' => 3,
        ]);
        add_post_meta($page_id, 'hero_subheading', 'Existing subheading');
        add_post_meta($page_id, 'faq_question', 'Existing question one');
        add_post_meta($page_id, 'faq_question', 'Existing question two');

        $term = wp_insert_term('Net Zero Category', 'category');
        $this->assertIsArray($term);
        add_term_meta((int) $term['term_id'], 'net_zero_term_meta', 'kept');
        wp_set_object_terms($page_id, [(int) $term['term_id']], 'category');

        $attachment_id = $this->create_existing_attachment($page_id);
        set_post_thumbnail($page_id, $attachment_id);

        return [
            'page_id' => $page_id,
            'term_id' => (int) $term['term_id'],
            'attachment_id' => $attachment_id,
            'attachment_file' => get_attached_file($attachment_id),
        ];
    }

    /**
     * @param int $parent_id
     * @return int
     */
    private function create_existing_attachment($parent_id)
    {
        $upload = wp_upload_bits('net-zero-existing.jpg', null, (string) file_get_contents($this->fixture_jpeg));
        $this->assertEmpty($upload['error']);

        $attachment_id = wp_insert_attachment(
            [
                'post_mime_type' => 'image/jpeg',
                'post_title' => 'Net Zero Existing',
                'post_status' => 'inherit',
            ],
            $upload['file'],
            $parent_id
        );
        $this->assertGreaterThan(0, $attachment_id);

        return (int) $attachment_id;
    }

    /**
     * Serves the media download from a local fixture file.
     *
     * Only the HTTP transport is stubbed. download_url() still writes a real temp
     * file, and media_handle_sideload(), wp_handle_sideload(), the attachment row
     * and wp_generate_attachment_metadata() all run unmodified, so the files that
     * land in the uploads directory — and that the rollback has to remove — are
     * the real ones.
     *
     * @param false|array<string, mixed> $preempt
     * @param array<string, mixed>       $parsed_args
     * @param string                     $url
     * @return false|array<string, mixed>
     */
    public function serve_fixture_image($preempt, $parsed_args, $url)
    {
        if ((string) $url !== self::MEDIA_SOURCE_URL) {
            return $preempt;
        }

        $body = (string) file_get_contents($this->fixture_jpeg);
        if (! empty($parsed_args['stream']) && ! empty($parsed_args['filename'])) {
            file_put_contents((string) $parsed_args['filename'], $body);
            $body = '';
        }

        return [
            'headers' => new WpOrg\Requests\Utility\CaseInsensitiveDictionary(['content-type' => 'image/jpeg']),
            'body' => $body,
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => isset($parsed_args['filename']) ? (string) $parsed_args['filename'] : null,
        ];
    }

    /**
     * @return string
     */
    private function build_fixture_jpeg()
    {
        $path = wp_tempnam('dbvc-net-zero-fixture.jpg');
        $image = imagecreatetruecolor(240, 180);
        imagefilledrectangle($image, 0, 0, 240, 180, imagecolorallocate($image, 20, 90, 160));
        imagejpeg($image, $path, 90);
        imagedestroy($image);

        return (string) $path;
    }

    /**
     * @param string $domain
     * @param string $path
     * @return string
     */
    private function approve_preflight_and_get_token()
    {
        $approval = DBVC_CC_Import_Executor_Service::get_instance()->approve_preflight(
            self::DOMAIN,
            self::RELATIVE_PATH,
            false,
            true
        );
        $this->assertIsArray($approval);
        $this->assertSame('approved', (string) $approval['status'], wp_json_encode($approval['guard_failures']));

        return (string) $approval['approval']['approval_token'];
    }

    private function prepare_ready_mapping_inputs(): void
    {
        DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog(self::DOMAIN, true);
        DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates(self::DOMAIN, self::RELATIVE_PATH, true);
        DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates(self::DOMAIN, self::RELATIVE_PATH, true);

        DBVC_CC_Mapping_Decision_Service::get_instance()->save_decision(
            self::DOMAIN,
            self::RELATIVE_PATH,
            [
                'decision_status' => 'approved',
                'approved_mappings' => [
                    ['section_id' => 'dbvc_cc_sec_hero', 'target_ref' => 'core:post_title'],
                    ['section_id' => 'dbvc_cc_sec_faq', 'target_ref' => 'meta:post:page:faq_question'],
                ],
                'overrides' => [],
                'rejections' => [],
                'unresolved_fields' => [],
                'unresolved_media' => [],
            ],
            get_current_user_id()
        );

        DBVC_CC_Media_Decision_Service::get_instance()->save_decision(
            self::DOMAIN,
            self::RELATIVE_PATH,
            [
                'decision_status' => 'approved',
                'approved' => [
                    [
                        'media_id' => 'dbvc_cc_med_hero',
                        'target_ref' => 'core:featured_image',
                        'source_url' => self::MEDIA_SOURCE_URL,
                    ],
                ],
                'overrides' => [],
                'ignored' => [],
                'conflicts' => [],
            ],
            get_current_user_id()
        );
    }

    private function build_fixture_artifacts(): void
    {
        $upload_dir = wp_upload_dir();
        $storage_dir = trailingslashit((string) $upload_dir['basedir']) . DBVC_CC_Contracts::STORAGE_DEFAULT_PATH;
        $this->domain_dir = trailingslashit($storage_dir) . self::DOMAIN;
        $page_dir = trailingslashit($this->domain_dir) . self::RELATIVE_PATH;
        wp_mkdir_p($page_dir);

        $slug = self::RELATIVE_PATH;
        $source_url = 'https://' . self::DOMAIN . '/';

        $main_artifact = [
            'page_name' => 'Home',
            'slug' => $slug,
            'source_url' => $source_url,
            'provenance' => [
                'source_url' => $source_url,
                'canonical_source_url' => $source_url,
                'content_hash' => hash('sha256', 'net-zero-home'),
            ],
            'content' => [
                'images' => ['/wp-content/uploads/2026/03/hero.jpg'],
                'sections' => [
                    [
                        'id' => 'dbvc_cc_sec_hero',
                        'heading' => 'Hero',
                        'images' => [['url' => self::MEDIA_SOURCE_URL]],
                    ],
                ],
            ],
        ];

        $elements_v2 = [
            'artifact_schema_version' => '2.0.0',
            'artifact_type' => 'elements.v2',
            'source_url' => $source_url,
            'capture_mode' => 'deep',
            'element_count' => 4,
            'truncated' => false,
            'processing' => ['is_partial' => false, 'partial_reason' => '', 'resume_marker' => new stdClass()],
            'elements' => [
                [
                    'element_id' => 'dbvc_cc_el_hero_h1',
                    'tag' => 'h1',
                    'text' => 'Imported Home Headline',
                    'sequence_index' => 1,
                    'attributes' => [],
                ],
                [
                    'element_id' => 'dbvc_cc_el_hero_img',
                    'tag' => 'img',
                    'text' => 'Hero image',
                    'sequence_index' => 2,
                    'attributes' => ['src' => self::MEDIA_SOURCE_URL, 'alt' => 'Hero photo'],
                ],
                [
                    'element_id' => 'dbvc_cc_el_faq_h2',
                    'tag' => 'h2',
                    'text' => 'FAQs',
                    'sequence_index' => 3,
                    'attributes' => [],
                ],
                [
                    'element_id' => 'dbvc_cc_el_faq_p',
                    'tag' => 'p',
                    'text' => 'Imported question?',
                    'sequence_index' => 4,
                    'attributes' => [],
                ],
            ],
        ];

        $sections_v2 = [
            'artifact_schema_version' => '2.0.0',
            'artifact_type' => 'sections.v2',
            'source_url' => $source_url,
            'section_count' => 2,
            'processing' => ['is_partial' => false, 'partial_reason' => '', 'resume_marker' => new stdClass()],
            'sections' => [
                [
                    'section_id' => 'dbvc_cc_sec_hero',
                    'section_label_candidate' => 'Hero',
                    'element_ids' => ['dbvc_cc_el_hero_h1', 'dbvc_cc_el_hero_img'],
                ],
                [
                    'section_id' => 'dbvc_cc_sec_faq',
                    'section_label_candidate' => 'FAQ',
                    'element_ids' => ['dbvc_cc_el_faq_h2', 'dbvc_cc_el_faq_p'],
                ],
            ],
        ];

        $section_typing_v2 = [
            'artifact_schema_version' => '2.0.0',
            'artifact_type' => 'section-typing.v2',
            'source_url' => $source_url,
            'generated_at' => current_time('c'),
            'mode' => 'deterministic',
            'sections' => [
                ['section_id' => 'dbvc_cc_sec_hero', 'section_type_candidate' => 'hero', 'confidence' => 0.9, 'evidence' => ['heading_signal']],
                ['section_id' => 'dbvc_cc_sec_faq', 'section_type_candidate' => 'faq', 'confidence' => 0.82, 'evidence' => ['faq_heading_signal']],
            ],
        ];

        file_put_contents(
            trailingslashit($page_dir) . $slug . '.json',
            wp_json_encode($main_artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        file_put_contents(
            trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_ELEMENTS_V2_SUFFIX,
            wp_json_encode($elements_v2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        file_put_contents(
            trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_SECTIONS_V2_SUFFIX,
            wp_json_encode($sections_v2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        file_put_contents(
            trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_SECTION_TYPING_V2_SUFFIX,
            wp_json_encode($section_typing_v2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function remove_directory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
                continue;
            }
            @unlink($entry->getPathname());
        }
        @rmdir($directory);
    }

    private function reset_import_run_ledger(): void
    {
        global $wpdb;
        $runs_table = $wpdb->prefix . 'dbvc_cc_import_runs';
        $actions_table = $wpdb->prefix . 'dbvc_cc_import_run_actions';
        $wpdb->query("DELETE FROM {$actions_table}");
        $wpdb->query("DELETE FROM {$runs_table}");
    }
}
