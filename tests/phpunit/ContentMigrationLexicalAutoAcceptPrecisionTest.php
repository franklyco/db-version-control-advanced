<?php

/**
 * What the lexical mapping path is allowed to auto-accept.
 *
 * G-5 caps wrong auto-accepted mappings at 2% on the stated reasoning that a wrong confident
 * mapping costs more than an escalation: the reviewer never sees it. The model path passes that
 * gate at 0%. The path a DEFAULT install takes — `dbvc_cc_flag_model_interpretation` is off —
 * was measured at 50%: of two auto-accepted recommendations on corpus slot 1's homepage, one was
 * wrong (§6.1c of the V3 guide).
 *
 * The wrong one was the head `<title>`. `DBVC_CC_V2_Mapping_Index_Service::build_page_title_item()`
 * emits `core:post_title` at a fixed 0.97 whenever the routed object supports a title. That is a
 * STRUCTURAL DEFAULT, not evidence: it is the same number whatever the page says and whatever
 * else the catalog offers, and it sits above `autoAcceptMinConfidence` (0.92) and above the
 * ceiling of every evidence-derived candidate (slot-graph context 0.88, catalog pattern 0.84,
 * pattern memory 0.96). On a target site that keeps its own SEO title field, the `<title>` text
 * belongs in that field and the default is wrong — confidently, unreviewably wrong.
 *
 * The behaviour these tests pin is the boundary, not the number: a structural default may be
 * acted on without review only when it is the sole home for the value. The moment the catalog
 * offers somewhere else to put it, the choice is the reviewer's, so the item escalates to the
 * exception queue with the default still pre-selected.
 *
 * Both tests drive the real pipeline through `POST /dbvc_cc/v2/runs` over an ingested artifact
 * directory, with the flag off. No network, no API key and no external corpus fixture: the two
 * runs differ only in whether the target site has an SEO title field.
 */
class ContentMigrationLexicalAutoAcceptPrecisionTest extends WP_UnitTestCase
{
    /**
     * @var string
     */
    private $dir = '';

    /**
     * @var string
     */
    private $domain = 'lexical-auto-accept.test';

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

        // The defect is on the no-model path, so the flag stays off for every test here. That is
        // also what a default install runs.
        delete_option(DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION);

        $this->dir = wp_normalize_path(get_temp_dir() . 'dbvc-cc-auto-accept-' . wp_generate_password(8, false));
        wp_mkdir_p($this->dir);

        $dir = $this->dir;
        $this->permit = static function ($roots) use ($dir) {
            $roots[] = $dir;

            return $roots;
        };
        add_filter('dbvc_cc_v2_ingest_allowed_roots', $this->permit);

        // The lexical path needs neither a network nor a key. A request from these tests would be
        // a defect in the seam.
        $this->http_calls = 0;
        $this->http_guard = function () {
            $this->http_calls++;

            return new WP_Error('dbvc_test_no_http', 'The lexical mapping path must not reach the network.');
        };
        add_filter('pre_http_request', $this->http_guard, 1);
    }

    public function tear_down(): void
    {
        if ($this->http_guard !== null) {
            remove_filter('pre_http_request', $this->http_guard, 1);
            $this->http_guard = null;
        }
        if ($this->permit !== null) {
            remove_filter('dbvc_cc_v2_ingest_allowed_roots', $this->permit);
            $this->permit = null;
        }

        $this->delete_domain_artifacts();

        foreach (glob(trailingslashit($this->dir) . '*') ?: [] as $file) {
            @unlink($file);
        }
        if (is_dir($this->dir)) {
            @rmdir($this->dir);
        }

        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();

        parent::tear_down();
    }

    /**
     * The regression. A target site with its own SEO title field has two plausible homes for the
     * head `<title>`, and lexical overlap cannot tell them apart — the text is identical either
     * way. Before the fix the structural default won at 0.97 and was auto-accepted, which is the
     * 1-in-2 wrong auto-accept §6.1c measured.
     */
    public function test_the_head_title_is_not_auto_accepted_when_the_target_has_its_own_seo_title_field()
    {
        $this->register_seo_title_field();
        $this->write_bundle();

        $this->run_ingest();

        $this->assertSame(0, $this->http_calls, 'The lexical path reached the network.');

        $item = $this->head_title_item();
        $this->assertNotNull($item, 'The captured page produced no head-title mapping item.');
        $this->assertGreaterThan(
            1,
            count($item['target_candidates']),
            'This test only means something while the catalog offers a competing home for the title.'
        );
        $this->assertContains(
            'meta:post:page:_seo_title',
            array_column($item['target_candidates'], 'target_ref'),
            'The competing SEO title field was not offered as a candidate.'
        );

        $recommendation = $this->head_title_recommendation();
        $this->assertNotNull($recommendation, 'No recommendation was produced for the head title.');

        $this->assertTrue(
            (bool) $recommendation['requires_review'],
            'A structural default with a competing target was auto-accepted. That is a confidently '
                . 'wrong mapping the reviewer never sees, which is what G-5 exists to keep out.'
        );

        // Escalating is not the same as giving up. The default stays pre-selected so the reviewer
        // confirms or redirects it rather than mapping the field from nothing.
        $this->assertSame(
            'core:post_title',
            (string) $recommendation['target_ref'],
            'The escalated item should still carry the structural default as its pre-selection.'
        );
    }

    /**
     * The other half of the boundary, and the reason this is not a blanket "stop auto-accepting
     * the title". On a target with nowhere else to put a document title, `core:post_title` is the
     * only possible home, there is nothing for a reviewer to decide, and escalating it would move
     * work to a human for no gain. Review by exception means escalating the ambiguous case, not
     * every case.
     */
    public function test_the_head_title_still_auto_accepts_when_the_post_title_is_its_only_home()
    {
        $this->write_bundle();

        $this->run_ingest();

        $item = $this->head_title_item();
        $this->assertNotNull($item);
        $this->assertSame(
            ['core:post_title'],
            array_column($item['target_candidates'], 'target_ref'),
            'This test only means something while the structural default is uncontested.'
        );

        $recommendation = $this->head_title_recommendation();
        $this->assertNotNull($recommendation);
        $this->assertFalse(
            (bool) $recommendation['requires_review'],
            'An uncontested structural default was escalated, which costs a reviewer a decision '
                . 'that has only one possible answer.'
        );
        $this->assertSame('core:post_title', (string) $recommendation['target_ref']);
    }

    /**
     * An escalation a reviewer cannot act on is a dropped mapping. The contested default has to
     * reach the exception queue as a typed item saying what the reviewer is being asked, with the
     * competing fields listed.
     */
    public function test_an_escalated_head_title_reaches_the_exception_queue_with_a_named_reason()
    {
        $this->register_seo_title_field();
        $this->write_bundle();

        $this->run_ingest();

        $item = $this->head_title_item();
        $this->assertNotNull($item);

        $mapping = $this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_RECOMMENDATIONS_SUFFIX);
        $unresolved = [];
        foreach (isset($mapping['unresolved_items']) ? $mapping['unresolved_items'] : [] as $candidate) {
            if (is_array($candidate) && (string) ($candidate['item_id'] ?? '') === (string) $item['item_id']) {
                $unresolved = $candidate;
                break;
            }
        }

        $this->assertNotSame([], $unresolved, 'The escalated head title never reached the exception queue.');
        $this->assertSame('contested_structural_default', (string) $unresolved['unresolved_class']);
        $this->assertContains('contested_structural_default', (array) $unresolved['reason_codes']);
        $this->assertSame('core:post_title', (string) $unresolved['selected_target_ref']);

        // The reviewer needs to see what it is being asked to choose between.
        $alternatives = array_column((array) $unresolved['selection']['alternatives'], 'target_ref');
        $this->assertContains('core:post_title', $alternatives);
        $this->assertContains('meta:post:page:_seo_title', $alternatives);

        // The review service's label map is closed: an unresolved class it does not know renders
        // as an empty string, so a new class without a label shows the reviewer a blank reason.
        $describe = new ReflectionMethod(DBVC_CC_V2_Recommendation_Review_Service::class, 'describe_unresolved_class');
        $describe->setAccessible(true);
        $this->assertNotSame(
            '',
            (string) $describe->invoke(
                DBVC_CC_V2_Recommendation_Review_Service::get_instance(),
                'contested_structural_default'
            ),
            'The exception queue would show this escalation with no reason text.'
        );
    }

    /**
     * The escalation is keyed on the KIND of candidate, not on the kind of item, and that
     * distinction is the whole reason the model path is untouched by this change.
     *
     * An interpreter's answer arrives as a candidate whose reason is `model_interpretation`, so
     * the same contested catalog that escalates the lexical default leaves a model-authored
     * mapping auto-accepting. Had the gate been written as "head items no longer auto-accept",
     * this would fail — and the model's G-4, already short of its threshold, would have dropped
     * further for a defect that is not on its path.
     *
     * Driven through the injected transport: no network, no API key.
     */
    public function test_a_model_authored_head_title_still_auto_accepts_in_the_same_contested_catalog()
    {
        $this->register_seo_title_field();
        $this->write_bundle();

        update_option(DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION, '1');
        $settings = DBVC_CC_Settings_Service::get_options();
        $settings['openai_api_key'] = 'test-key-not-a-real-one';
        $settings['openai_model'] = 'gpt-5.6-terra';
        update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $settings);

        $transport = static function () {
            return [
                'status' => 200,
                'body' => (string) wp_json_encode([
                    'choices' => [
                        [
                            'message' => [
                                'content' => (string) wp_json_encode([
                                    'mappings' => [
                                        [
                                            'item_id' => 'head:title',
                                            'target_ref' => 'meta:post:page:_seo_title',
                                            'confidence' => 0.95,
                                            'rationale' => 'A keyword-stuffed document title, not the page name.',
                                        ],
                                    ],
                                    'declined' => [],
                                ]),
                            ],
                        ],
                    ],
                ]),
            ];
        };
        $options_filter = static function ($options) use ($transport) {
            $options['transport'] = $transport;

            return $options;
        };
        add_filter(DBVC_CC_V2_Mapping_Interpretation_Service::FILTER_INTERPRETATION_OPTIONS, $options_filter);

        try {
            $this->run_ingest();
        } finally {
            remove_filter(DBVC_CC_V2_Mapping_Interpretation_Service::FILTER_INTERPRETATION_OPTIONS, $options_filter);
            delete_option(DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION);
        }

        $this->assertSame(0, $this->http_calls, 'The seam made a real HTTP request.');

        $recommendation = $this->head_title_recommendation();
        $this->assertNotNull($recommendation);
        $this->assertSame(
            'meta:post:page:_seo_title',
            (string) $recommendation['target_ref'],
            'The interpreter answered this item, so its answer should be the recommendation.'
        );
        $this->assertFalse(
            (bool) $recommendation['requires_review'],
            'A model-authored mapping was escalated by a gate that exists for the lexical default.'
        );
    }

    /**
     * A target site that keeps the document title in its own field, which is what a page-builder
     * or SEO-plugin target looks like.
     *
     * @return void
     */
    private function register_seo_title_field()
    {
        register_post_meta('page', '_seo_title', [
            'single' => true,
            'type' => 'string',
            'show_in_rest' => true,
            'description' => 'The document title used in the browser tab and in search results.',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function head_title_item()
    {
        $mapping = $this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_INDEX_SUFFIX);

        foreach (isset($mapping['content_items']) ? $mapping['content_items'] : [] as $content_item) {
            if (! is_array($content_item)) {
                continue;
            }

            $source_refs = isset($content_item['source_refs']) && is_array($content_item['source_refs'])
                ? $content_item['source_refs']
                : [];
            if (in_array('page-artifact.v1#metadata.title', $source_refs, true)) {
                return $content_item;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function head_title_recommendation()
    {
        $artifact = $this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_RECOMMENDATIONS_SUFFIX);

        foreach (isset($artifact['recommendations']) ? $artifact['recommendations'] : [] as $recommendation) {
            if (! is_array($recommendation)) {
                continue;
            }

            $source_refs = isset($recommendation['source_refs']) && is_array($recommendation['source_refs'])
                ? $recommendation['source_refs']
                : [];
            if (in_array('page-artifact.v1#metadata.title', $source_refs, true)) {
                return $recommendation;
            }
        }

        return null;
    }

    /**
     * @return void
     */
    private function run_ingest()
    {
        $request = new WP_REST_Request('POST', '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . '/runs');
        $request->set_param('domain', $this->domain);
        $request->set_param('ingestDir', $this->dir);

        $response = rest_do_request($request);
        if ($response->is_error()) {
            $error = $response->as_error();
            $this->fail(sprintf('The ingest run failed: [%s] %s', $error->get_error_code(), $error->get_error_message()));
        }
    }

    /**
     * @param string $suffix
     * @return array<string, mixed>
     */
    private function read_stored_artifact($suffix)
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($this->domain);
        if (is_wp_error($context)) {
            return [];
        }

        $matches = glob(trailingslashit($context['domain_dir']) . '*/home' . $suffix);
        if (! is_array($matches) || $matches === []) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($matches[0]), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return void
     */
    private function delete_domain_artifacts()
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($this->domain);
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
     * One captured page whose head carries a `<title>` that is plainly an SEO title rather than
     * the page's name — the shape corpus slot 1's homepage has.
     *
     * @return void
     */
    private function write_bundle()
    {
        $slug = 'home';
        $source_url = 'https://lexical-auto-accept.test/';
        $elements = [
            [
                'element_id' => 'home-e1',
                'tag' => 'h1',
                'level' => 1,
                'text' => 'Creating Healthy and Happy Smiles',
                'is_site_chrome' => false,
            ],
            [
                'element_id' => 'home-e2',
                'tag' => 'p',
                'text' => 'We have been caring for Hamilton families since 1998.',
                'is_site_chrome' => false,
            ],
        ];

        $parts = [
            'page-artifact.v1' => [
                'artifact_schema_version' => 1,
                'artifact_type' => 'page-artifact.v1',
                'journey_id' => 'producer-journey',
                'page_id' => 'producer-page-id',
                'slug' => $slug,
                'source_url' => $source_url,
                'normalized_url' => $source_url,
                'path' => '/',
                'captured_at' => gmdate('c'),
                'content_hash' => hash('sha256', $slug),
                'metadata' => [
                    'title' => 'Family Dentist in Hamilton, ON - Olteanu Dental',
                    'description' => 'A dental practice in Hamilton.',
                ],
                'headings' => [['level' => 1, 'text' => 'Creating Healthy and Happy Smiles']],
                'text_blocks' => ['We have been caring for Hamilton families since 1998.'],
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
                        'section_id' => 'home_s1',
                        'order' => 1,
                        'heading' => 'Creating Healthy and Happy Smiles',
                        'section_label_candidate' => 'Creating Healthy and Happy Smiles',
                        'parent_section_id' => null,
                        'heading_path' => ['Creating Healthy and Happy Smiles'],
                        'element_ids' => ['home-e1', 'home-e2'],
                        'signals' => [
                            'heading_level' => 1,
                            'has_heading' => true,
                            'text_element_count' => 2,
                            'link_element_count' => 0,
                            'cta_keyword_hits' => 0,
                            'list_density' => 0,
                        ],
                    ],
                ],
            ],
            'ingestion-package.v2' => [
                'artifact_schema_version' => 2,
                'artifact_type' => 'ingestion-package.v2',
                'slug' => $slug,
                'page_slug' => $slug,
                'sections' => [
                    [
                        'section_id' => 'home_s1',
                        'order' => 1,
                        'label' => 'Creating Healthy and Happy Smiles',
                        'sample_text' => [
                            'Creating Healthy and Happy Smiles',
                            'We have been caring for Hamilton families since 1998.',
                        ],
                        'signals' => [
                            'heading_level' => 1,
                            'has_heading' => true,
                            'text_element_count' => 2,
                            'link_element_count' => 0,
                            'cta_keyword_hits' => 0,
                            'list_density' => 0,
                        ],
                    ],
                ],
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
