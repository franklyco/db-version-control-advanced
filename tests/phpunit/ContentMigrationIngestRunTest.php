<?php

/**
 * The acquisition joint, end to end.
 *
 * ContentMigrationExternalArtifactIngestTest covers the seam's contract in isolation. This covers
 * the claim that matters: that a run started against an externally captured artifact directory
 * reaches the pipeline **without fetching anything**, and that what the pipeline stores is what
 * the external producer wrote.
 *
 * Before this, `page-artifact.v1` was written by exactly one thing — the capture service Q5
 * retired — and the only handoff that existed passed URLs to a run that re-crawled every page,
 * reintroducing D-5 through D-8, D-15 and D-18. So the assertion "no HTTP request was made" is
 * the whole point and not an incidental detail. See D-21.
 */
class ContentMigrationIngestRunTest extends WP_UnitTestCase
{
    /**
     * @var string
     */
    private $dir = '';

    /**
     * @var string
     */
    private $domain = 'ingest-run.test';

    /**
     * @var int
     */
    private $http_calls = 0;

    /**
     * @var callable|null
     */
    private $permit = null;

    /**
     * @var callable|null
     */
    private $http_guard = null;

    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        DBVC_CC_V2_Contracts::ensure_defaults();

        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V2);
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();

        $this->dir = wp_normalize_path(get_temp_dir() . 'dbvc-cc-ingest-run-' . wp_generate_password(8, false));
        wp_mkdir_p($this->dir);

        $dir = $this->dir;
        $this->permit = static function ($roots) use ($dir) {
            $roots[] = $dir;

            return $roots;
        };
        add_filter('dbvc_cc_v2_ingest_allowed_roots', $this->permit);

        // Any outbound request during an ingest run is a defect: the content is already on disk.
        $this->http_calls = 0;
        $this->http_guard = function ($preempt) {
            $this->http_calls++;

            return new WP_Error('dbvc_test_no_http', 'An ingest run must not make HTTP requests.');
        };
        add_filter('pre_http_request', $this->http_guard, 1);
    }

    public function tear_down(): void
    {
        if ($this->http_guard !== null) {
            remove_filter('pre_http_request', $this->http_guard, 1);
        }
        if ($this->permit !== null) {
            remove_filter('dbvc_cc_v2_ingest_allowed_roots', $this->permit);
        }

        $this->delete_domain_artifacts($this->domain);

        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach ((array) glob(trailingslashit($this->dir) . '*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->dir);
        }

        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();

        parent::tear_down();
    }

    public function test_a_run_ingests_from_disk_without_fetching_anything()
    {
        $this->write_bundle('home', 'https://ingest-run.test/');
        $this->write_bundle('about', 'https://ingest-run.test/about');

        $response = $this->create_run(['ingestDir' => $this->dir]);
        $this->assertFalse($response->is_error(), $this->describe_error($response));

        $data = $response->get_data();
        $this->assertSame(0, $this->http_calls, 'An ingest run fetched something it should have read from disk.');

        // The directory defines the run scope: no sitemap and no URL list were supplied.
        $pages = isset($data['pages']) ? $data['pages'] : [];
        $this->assertCount(2, $pages);
        foreach ($pages as $page) {
            $this->assertSame('completed', $page['status'], 'Every staged page should capture.');
        }
    }

    public function test_the_stored_artifact_is_the_producers_artifact()
    {
        $this->write_bundle('home', 'https://ingest-run.test/');

        $response = $this->create_run(['ingestDir' => $this->dir]);
        $this->assertFalse($response->is_error(), $this->describe_error($response));

        $stored = $this->read_stored_artifact('home', '.json');
        $this->assertNotSame([], $stored, 'No raw page artifact was stored.');

        $source = json_decode(
            (string) file_get_contents(trailingslashit($this->dir) . 'home.page-artifact.v1.json'),
            true
        );

        // Content survives byte-for-byte. The run's own identity is stamped on, which is why the
        // comparison is per-key rather than a whole-array identity.
        $this->assertSame($source['content_hash'], $stored['content_hash']);
        $this->assertSame($source['headings'], $stored['headings']);
        $this->assertSame($source['metadata'], $stored['metadata']);
        $this->assertSame($source['source_url'], $stored['source_url']);

        // The producer's journey id is replaced by the run's, not trusted.
        $this->assertNotSame('whatever-the-producer-said', $stored['journey_id']);

        // The elements the skill segmented are stored as-is: the DOM extractor never ran, and
        // could not have, since no HTML was fetched.
        $elements = $this->read_stored_artifact('home', DBVC_CC_Contracts::STORAGE_ELEMENTS_V2_SUFFIX);
        $this->assertSame(2, $elements['element_count']);
        $this->assertSame('A heading', $elements['elements'][0]['text']);

        $sections = $this->read_stored_artifact('home', DBVC_CC_Contracts::STORAGE_SECTIONS_V2_SUFFIX);
        $this->assertSame(1, $sections['section_count']);
    }

    /**
     * Source normalization is the first consumer downstream of the seam, and the one that would
     * have cast the skill's levelled headings to the literal "Array".
     */
    public function test_the_downstream_normalization_artifact_reads_the_ingested_headings()
    {
        $this->write_bundle('home', 'https://ingest-run.test/');

        $response = $this->create_run(['ingestDir' => $this->dir]);
        $this->assertFalse($response->is_error(), $this->describe_error($response));

        $normalized = $this->read_stored_artifact(
            'home',
            DBVC_CC_V2_Contracts::STORAGE_SOURCE_NORMALIZATION_SUFFIX
        );

        $this->assertContains('A heading', $normalized['normalized']['headings']);
        $this->assertNotContains('Array', $normalized['normalized']['headings']);
    }

    public function test_a_run_refuses_a_directory_rest_is_not_permitted_to_read()
    {
        $this->write_bundle('home', 'https://ingest-run.test/');
        remove_filter('dbvc_cc_v2_ingest_allowed_roots', $this->permit);
        $this->permit = null;

        $response = $this->create_run(['ingestDir' => $this->dir]);

        $this->assertTrue($response->is_error());
        $this->assertSame('dbvc_cc_v2_ingest_path_not_permitted', $response->as_error()->get_error_code());
    }

    /**
     * A malformed capture must fail the request, not produce a run with some pages missing.
     */
    public function test_a_malformed_bundle_refuses_the_run_before_it_starts()
    {
        $this->write_bundle('home', 'https://ingest-run.test/');
        file_put_contents(trailingslashit($this->dir) . 'home.sections.v2.json', '{ broken');

        $response = $this->create_run(['ingestDir' => $this->dir]);

        $this->assertTrue($response->is_error());
        $this->assertSame('dbvc_cc_v2_ingest_file_not_json', $response->as_error()->get_error_code());
    }

    /**
     * @param array<string, mixed> $params
     * @return WP_REST_Response
     */
    private function create_run(array $params)
    {
        $request = new WP_REST_Request('POST', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs');
        $request->set_param('domain', $this->domain);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_do_request($request);
    }

    /**
     * @param WP_REST_Response $response
     * @return string
     */
    private function describe_error($response)
    {
        if (! $response->is_error()) {
            return '';
        }

        $error = $response->as_error();

        return sprintf('[%s] %s', $error->get_error_code(), $error->get_error_message());
    }

    /**
     * @param string $slug
     * @param string $suffix
     * @return array<string, mixed>
     */
    private function read_stored_artifact($slug, $suffix)
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($this->domain);
        if (is_wp_error($context)) {
            return [];
        }

        $matches = glob(trailingslashit($context['domain_dir']) . '*/' . $slug . $suffix);
        if (! is_array($matches) || $matches === []) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($matches[0]), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
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
     * @return void
     */
    private function reset_rest_server()
    {
        global $wp_rest_server;
        $wp_rest_server = null;
    }

    /**
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

        $path = '/' . ltrim((string) wp_parse_url($source_url, PHP_URL_PATH), '/');

        $parts = [
            'page-artifact.v1' => [
                'artifact_schema_version' => 1,
                'artifact_type' => 'page-artifact.v1',
                'journey_id' => 'whatever-the-producer-said',
                'page_id' => 'producer-page-id',
                'slug' => $slug,
                'source_url' => $source_url,
                'normalized_url' => $source_url,
                'path' => $path,
                'captured_at' => gmdate('c'),
                'content_hash' => hash('sha256', $slug),
                'metadata' => [
                    'title' => 'A page',
                    'description' => 'A description the DOM extractor never saw.',
                ],
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
