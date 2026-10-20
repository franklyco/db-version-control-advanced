<?php

/**
 * The acquisition joint.
 *
 * V3 moved capture out of PHP into an agent skill, and the gates were re-pointed at the skill's
 * artifacts — but nothing in the plugin could read them. `page-artifact.v1` was written by exactly
 * one thing, the capture service Q5 retired, and `capture_page()` had no seam. So the skill's
 * output had nowhere to go and the only handoff that existed passed URLs back to the retired path.
 * That is D-21.
 *
 * These tests fix the contract at the joint:
 *
 * - an unstaged run behaves exactly as it did before this seam existed (no silent behaviour change);
 * - a staged bundle is matched to a run row by slug, and by URL when the slug differs;
 * - a malformed or empty bundle is refused loudly at stage time, before a run writes anything,
 *   because a half-ingested run is worse than one that refuses to start;
 * - the run's identity is stamped onto the bundle rather than trusted from the file;
 * - provenance records which producer captured the page.
 */
class ContentMigrationExternalArtifactIngestTest extends WP_UnitTestCase
{
    /**
     * @var string
     */
    private $dir = '';

    /**
     * @var string
     */
    private $journey_id = 'journey-ingest-test';

    public function set_up(): void
    {
        parent::set_up();

        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        DBVC_CC_V2_Contracts::ensure_defaults();

        $this->dir = wp_normalize_path(get_temp_dir() . 'dbvc-cc-ingest-' . wp_generate_password(8, false));
        wp_mkdir_p($this->dir);
    }

    public function tear_down(): void
    {
        DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance()->unstage($this->journey_id);

        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach ((array) glob(trailingslashit($this->dir) . '*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->dir);
        }

        parent::tear_down();
    }

    /**
     * The seam must be invisible until something stages a directory. If registering the filter
     * changed how an ordinary run behaves, every existing run would be a regression.
     */
    public function test_an_unstaged_run_supplies_nothing()
    {
        $service = DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance();

        $this->assertNull(
            $service->supply(null, 'never-staged', ['slug' => 'home'], []),
            'An unstaged journey must fall through to the in-WordPress capture path.'
        );
    }

    /**
     * A bundle another filter already supplied wins. The seam is a chain, not a monopoly.
     */
    public function test_an_existing_bundle_is_passed_through_untouched()
    {
        $this->write_bundle('home', 'https://example.com/');
        $staged = DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance()->stage($this->journey_id, $this->dir);
        $this->assertNotWPError($staged);

        $supplied = ['raw_artifact' => ['source_url' => 'https://other.test/']];
        $result = DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance()->supply(
            $supplied,
            $this->journey_id,
            ['slug' => 'home'],
            []
        );

        $this->assertSame($supplied, $result);
    }

    public function test_staging_reports_every_page_it_validated()
    {
        $this->write_bundle('home', 'https://example.com/');
        $this->write_bundle('about', 'https://example.com/about');

        $staged = DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance()->stage($this->journey_id, $this->dir);

        $this->assertNotWPError($staged);
        $this->assertSame(2, $staged['pageCount']);
        $this->assertArrayHasKey('home', $staged['pages']);
        $this->assertArrayHasKey('about', $staged['pages']);
        $this->assertSame('https://example.com/about', $staged['pages']['about']['source_url']);
    }

    public function test_a_row_is_matched_by_slug_and_carries_the_runs_identity()
    {
        $this->write_bundle('home', 'https://example.com/');
        DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance()->stage($this->journey_id, $this->dir);

        $bundle = DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance()->supply(
            null,
            $this->journey_id,
            [
                'slug' => 'home',
                'page_id' => 'page-from-the-inventory',
                'path' => '/',
                'normalized_url' => 'https://example.com/',
                'source_url' => 'https://example.com/',
            ],
            []
        );

        $this->assertNotWPError($bundle);
        $this->assertSame('page-artifact.v1', $bundle['raw_artifact']['artifact_type']);
        $this->assertSame('elements.v2', $bundle['elements']['artifact_type']);
        $this->assertSame('sections.v2', $bundle['sections']['artifact_type']);
        $this->assertSame('ingestion-package.v2', $bundle['ingestion_package']['artifact_type']);

        // The producer cannot know the run's ids, so they are applied here and not trusted.
        $this->assertSame($this->journey_id, $bundle['raw_artifact']['journey_id']);
        $this->assertSame('page-from-the-inventory', $bundle['raw_artifact']['page_id']);
        $this->assertSame('page-from-the-inventory', $bundle['elements']['page_id']);

        // Provenance, so a reviewer can tell which producer captured the page.
        $this->assertSame('external', $bundle['capture_source']['producer']);
        $this->assertSame('home', $bundle['capture_source']['slug']);
    }

    /**
     * A producer may slugify differently from the inventory. Falling back to the URL keeps the
     * joint from being brittle about a detail neither side owns.
     */
    public function test_a_row_is_matched_by_url_when_the_slug_differs()
    {
        $this->write_bundle('about-us', 'https://example.com/about');
        DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance()->stage($this->journey_id, $this->dir);

        $bundle = DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance()->supply(
            null,
            $this->journey_id,
            [
                'slug' => 'about',
                'normalized_url' => 'http://www.example.com/about/',
            ],
            []
        );

        $this->assertNotWPError($bundle);
        $this->assertSame('about-us', $bundle['capture_source']['slug']);
    }

    public function test_a_row_with_no_staged_page_is_an_error_not_a_silent_fallback()
    {
        $this->write_bundle('home', 'https://example.com/');
        DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance()->stage($this->journey_id, $this->dir);

        $result = DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance()->supply(
            null,
            $this->journey_id,
            [
                'slug' => 'pricing',
                'normalized_url' => 'https://example.com/pricing',
            ],
            []
        );

        $this->assertWPError($result);
        $this->assertSame('dbvc_cc_v2_ingest_page_missing', $result->get_error_code());
    }

    public function test_staging_refuses_a_directory_with_no_artifacts()
    {
        $result = DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance()->stage($this->journey_id, $this->dir);

        $this->assertWPError($result);
        $this->assertSame('dbvc_cc_v2_ingest_dir_empty', $result->get_error_code());
    }

    public function test_staging_refuses_a_missing_directory()
    {
        $result = DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance()->stage(
            $this->journey_id,
            $this->dir . '-does-not-exist'
        );

        $this->assertWPError($result);
        $this->assertSame('dbvc_cc_v2_ingest_dir_missing', $result->get_error_code());
    }

    public function test_staging_refuses_a_bundle_whose_artifact_type_is_wrong()
    {
        $this->write_bundle('home', 'https://example.com/');
        $path = trailingslashit($this->dir) . 'home.elements.v2.json';
        $elements = json_decode((string) file_get_contents($path), true);
        $elements['artifact_type'] = 'elements.v1';
        file_put_contents($path, (string) wp_json_encode($elements));

        $result = DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance()->stage($this->journey_id, $this->dir);

        $this->assertWPError($result);
        $this->assertSame('dbvc_cc_v2_ingest_artifact_type_mismatch', $result->get_error_code());
    }

    /**
     * A page that captured no text is a capture failure, not an empty page. Importing nothing
     * silently is the failure mode F-1 warns about.
     */
    public function test_staging_refuses_a_bundle_that_captured_no_elements()
    {
        $this->write_bundle('home', 'https://example.com/');
        $path = trailingslashit($this->dir) . 'home.elements.v2.json';
        $elements = json_decode((string) file_get_contents($path), true);
        $elements['elements'] = [];
        file_put_contents($path, (string) wp_json_encode($elements));

        $result = DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance()->stage($this->journey_id, $this->dir);

        $this->assertWPError($result);
        $this->assertSame('dbvc_cc_v2_ingest_elements_empty', $result->get_error_code());
    }

    public function test_staging_refuses_a_bundle_missing_a_content_hash()
    {
        $this->write_bundle('home', 'https://example.com/');
        $path = trailingslashit($this->dir) . 'home.page-artifact.v1.json';
        $artifact = json_decode((string) file_get_contents($path), true);
        unset($artifact['content_hash']);
        file_put_contents($path, (string) wp_json_encode($artifact));

        $result = DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance()->stage($this->journey_id, $this->dir);

        $this->assertWPError($result);
        $this->assertSame('dbvc_cc_v2_ingest_raw_incomplete', $result->get_error_code());
    }

    public function test_staging_refuses_a_bundle_whose_part_is_not_json()
    {
        $this->write_bundle('home', 'https://example.com/');
        file_put_contents(trailingslashit($this->dir) . 'home.sections.v2.json', 'not json at all');

        $result = DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance()->stage($this->journey_id, $this->dir);

        $this->assertWPError($result);
        $this->assertSame('dbvc_cc_v2_ingest_file_not_json', $result->get_error_code());
    }

    public function test_staging_refuses_a_bundle_with_a_part_missing_entirely()
    {
        $this->write_bundle('home', 'https://example.com/');
        unlink(trailingslashit($this->dir) . 'home.ingestion-package.v2.json');

        $result = DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance()->stage($this->journey_id, $this->dir);

        $this->assertWPError($result);
        $this->assertSame('dbvc_cc_v2_ingest_file_unreadable', $result->get_error_code());
    }

    /**
     * The skill's page-artifact.v1 carries levelled headings — {level, text} — where V2's own
     * capture emitted flat strings. Source normalization casts heading entries to string, which
     * would make every levelled heading the literal "Array". Keeping the level is the D-7 fix, so
     * the consumer is what adapts.
     */
    public function test_source_normalization_reads_levelled_headings()
    {
        $normalized = DBVC_CC_V2_Source_Normalization_Service::get_instance()->build_artifact(
            [
                'source_url' => 'https://example.com/',
                'normalized_url' => 'https://example.com/',
                'content_hash' => str_repeat('a', 64),
                'headings' => [
                    ['level' => 1, 'text' => 'Creating Healthy & Happy Smiles'],
                    ['level' => 2, 'text' => 'Our Services'],
                ],
                'text_blocks' => ['A paragraph.'],
                'links' => [],
                'sections_raw' => [],
                'metadata' => [],
                'images' => [],
            ]
        );

        $this->assertContains('Creating Healthy & Happy Smiles', $normalized['normalized']['headings']);
        $this->assertContains('Our Services', $normalized['normalized']['headings']);
        $this->assertNotContains('Array', $normalized['normalized']['headings']);

        // Both survive as distinct entries. Before the fix they both cast to "Array" and the
        // dedupe collapsed them into a single bogus heading, so the count is the discriminating
        // assertion rather than the absence of the string.
        $this->assertSame(2, $normalized['stats']['heading_count']);
    }

    /**
     * A filesystem path arriving over HTTP is a different risk from one typed at a shell, so REST
     * is closed by default and the operator opens the one root they capture into.
     */
    public function test_rest_cannot_stage_an_arbitrary_path_by_default()
    {
        $service = DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance();

        $this->assertSame([], $service->get_allowed_roots());
        $this->assertFalse($service->is_path_permitted($this->dir));
    }

    public function test_an_allowed_root_permits_its_own_subdirectories_only()
    {
        $dir = $this->dir;
        $permit = static function ($roots) use ($dir) {
            $roots[] = $dir;

            return $roots;
        };
        add_filter('dbvc_cc_v2_ingest_allowed_roots', $permit);

        $service = DBVC_CC_V2_External_Artifact_Ingest_Service::get_instance();
        $nested = trailingslashit($this->dir) . 'nested';
        wp_mkdir_p($nested);

        $this->assertTrue($service->is_path_permitted($this->dir));
        $this->assertTrue($service->is_path_permitted($nested));
        $this->assertFalse(
            $service->is_path_permitted(dirname($this->dir)),
            'A permitted root must not permit its own parent.'
        );

        rmdir($nested);
        remove_filter('dbvc_cc_v2_ingest_allowed_roots', $permit);
    }

    /**
     * S2a made a run startable from a URL list and S4b gave the admin form a field for it, but
     * the run profile never stored the list — so duplicating such a run dropped every URL, and
     * because the run has no sitemap either, the duplicate could not start at all.
     */
    public function test_the_run_profile_round_trips_a_url_list()
    {
        $domain = 'ingest-profile.test';
        $stored = DBVC_CC_V2_Run_Profile_Service::get_instance()->persist_latest_profile(
            $domain,
            [
                'run_id' => 'run-profile-urls',
                'sitemap_url' => '',
                'urls' => ['https://ingest-profile.test/', 'https://ingest-profile.test/about'],
                'ingest_dir' => $this->dir,
                'max_urls' => 0,
                'force_rebuild' => false,
                'crawl_overrides' => [],
            ]
        );

        $this->assertNotWPError($stored);
        $this->assertSame(
            ['https://ingest-profile.test/', 'https://ingest-profile.test/about'],
            $stored['request']['urls']
        );
        $this->assertSame($this->dir, $stored['request']['ingest_dir']);

        $this->delete_domain_artifacts($domain);
    }

    /**
     * Remove a test domain's artifact directory, bounded to the add-on's own storage base.
     *
     * @param string $domain
     * @return void
     */
    private function delete_domain_artifacts($domain)
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($context)) {
            return;
        }

        $domain_dir = $context['domain_dir'];
        $base_dir = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        if (! is_dir($domain_dir) || ! dbvc_cc_path_is_within($domain_dir, $base_dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($domain_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($domain_dir);
    }

    /**
     * Write a minimally valid four-part bundle for one page.
     *
     * @param string $slug
     * @param string $source_url
     * @return void
     */
    private function write_bundle($slug, $source_url)
    {
        $elements = [
            [
                'element_id' => $slug . '-e1',
                'tag' => 'h1',
                'text' => 'A heading',
                'level' => 1,
                'is_site_chrome' => false,
            ],
            [
                'element_id' => $slug . '-e2',
                'tag' => 'p',
                'text' => 'Some body copy.',
                'is_site_chrome' => false,
            ],
        ];

        $parts = [
            'page-artifact.v1' => [
                'artifact_schema_version' => 1,
                'artifact_type' => 'page-artifact.v1',
                'journey_id' => 'whatever-the-producer-said',
                'page_id' => 'producer-page-id',
                'slug' => $slug,
                'source_url' => $source_url,
                'normalized_url' => $source_url,
                'path' => '/' . ltrim((string) wp_parse_url($source_url, PHP_URL_PATH), '/'),
                'captured_at' => gmdate('c'),
                'content_hash' => hash('sha256', $slug),
                'metadata' => ['title' => 'A page', 'description' => ''],
                'headings' => [['level' => 1, 'text' => 'A heading']],
                'text_blocks' => ['Some body copy.'],
                'links' => [],
                'images' => [],
                'sections_raw' => [],
            ],
            'elements.v2' => [
                'artifact_schema_version' => 2,
                'artifact_type' => 'elements.v2',
                'slug' => $slug,
                'element_count' => count($elements),
                'elements' => $elements,
            ],
            'sections.v2' => [
                'artifact_schema_version' => 2,
                'artifact_type' => 'sections.v2',
                'slug' => $slug,
                'section_count' => 1,
                'sections' => [
                    [
                        'section_id' => $slug . '-s1',
                        'heading' => 'A heading',
                        'parent_section_id' => null,
                        'heading_path' => ['A heading'],
                        'element_ids' => [$slug . '-e1', $slug . '-e2'],
                    ],
                ],
            ],
            'ingestion-package.v2' => [
                'artifact_schema_version' => 2,
                'artifact_type' => 'ingestion-package.v2',
                'slug' => $slug,
                'page_slug' => $slug,
                'sections' => [],
            ],
        ];

        foreach ($parts as $type => $payload) {
            file_put_contents(
                trailingslashit($this->dir) . $slug . '.' . $type . '.json',
                (string) wp_json_encode($payload)
            );
        }
    }
}
