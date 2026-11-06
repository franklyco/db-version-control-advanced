<?php

/**
 * Which items the mapping stage's ambiguity gates are allowed to judge.
 *
 * `DBVC_CC_V2_Mapping_Assignment_Service::evaluate_content_item()` carries four gates with
 * generic names — `low_margin`, `low_adjusted_confidence`, `duplicate_target_pressure`,
 * `competition_group_pressure` — and they were all guarded by `$item_type === 'section'`, so a
 * `page_title` or `page_description` item could not escalate however close its margin or however
 * low its confidence. That scoping came from the one problem the gates were built for (Tranche B
 * of `MIGRATION_MAPPER_V2_VERTICAL_FIELD_CONTEXT_IMPLEMENTATION_GUIDE.md`: "ambiguity reduction
 * on low-margin hero/body collisions") and not from any decision about head items. It is why
 * nothing in the product could catch D-22.
 *
 * What a head item's protection rested on instead was `autoAcceptMinConfidence` (0.92) sitting
 * above its score — and §6.1b expects S3 to calibrate that threshold DOWNWARD, because a
 * calibrated model places its confidence below where V2's keyword scorer placed its own. An
 * escalation that only holds while a threshold stays high is not an escalation; G-5 needs one
 * that holds on the evidence.
 *
 * The other half of the boundary is the one that keeps the model path out of it. The three
 * numbers in these gates (0.76, 0.05, 0.08) are calibrated against the lexical scorer's own
 * confidence scale, so reading a model's confidence against them is the same category error
 * §6.1c names about G-4's inherited 0.92. A section item is gated exactly as before, whoever
 * ranked it; every other item type is gated only when the lexical scorer produced the candidates.
 *
 * Every test here drives the real pipeline through `POST /dbvc_cc/v2/runs` over an ingested
 * artifact directory. No network and no API key: the flag-on tests inject a transport.
 */
class ContentMigrationHeadItemAmbiguityEscalationTest extends WP_UnitTestCase
{
    /**
     * @var string
     */
    private $dir = '';

    /**
     * @var string
     */
    private $domain = 'head-item-gates.test';

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

    /**
     * @var callable|null
     */
    private $options_filter = null;

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

        // The default install runs with the flag off, which is the path these gates protect. The
        // two flag-on tests turn it on themselves.
        delete_option(DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION);

        $this->dir = wp_normalize_path(get_temp_dir() . 'dbvc-cc-head-gates-' . wp_generate_password(8, false));
        wp_mkdir_p($this->dir);

        $dir = $this->dir;
        $this->permit = static function ($roots) use ($dir) {
            $roots[] = $dir;

            return $roots;
        };
        add_filter('dbvc_cc_v2_ingest_allowed_roots', $this->permit);

        $this->http_calls = 0;
        $this->http_guard = function () {
            $this->http_calls++;

            return new WP_Error('dbvc_test_no_http', 'This test must not reach the network.');
        };
        add_filter('pre_http_request', $this->http_guard, 1);
    }

    public function tear_down(): void
    {
        if ($this->options_filter !== null) {
            remove_filter(DBVC_CC_V2_Mapping_Interpretation_Service::FILTER_INTERPRETATION_OPTIONS, $this->options_filter);
            $this->options_filter = null;
        }
        if ($this->http_guard !== null) {
            remove_filter('pre_http_request', $this->http_guard, 1);
            $this->http_guard = null;
        }
        if ($this->permit !== null) {
            remove_filter('dbvc_cc_v2_ingest_allowed_roots', $this->permit);
            $this->permit = null;
        }

        delete_option(DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION);
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
     * A target site with two SEO-ish description fields gives the head `<meta name="description">`
     * two homes that score identically, because every catalog pattern match shares one flat base
     * confidence. Which one wins is decided by `strnatcasecmp` on the target_ref — the mapper is
     * choosing alphabetically and reporting it as a selection.
     *
     * That is what `low_margin` exists to catch, and before this change it could not see a head
     * item at all.
     */
    public function test_a_head_description_with_two_tied_targets_escalates_on_its_margin()
    {
        $this->register_description_fields();
        $this->write_bundle();

        $this->run_ingest();

        $this->assertSame(0, $this->http_calls, 'The lexical path reached the network.');

        $item = $this->item_for('page-artifact.v1#metadata.description');
        $this->assertNotNull($item, 'The captured page produced no head-description mapping item.');
        $this->assertGreaterThan(
            1,
            count($item['target_candidates']),
            'This test only means something while the catalog offers more than one home.'
        );

        $recommendation = $this->recommendation_for('page-artifact.v1#metadata.description');
        $this->assertNotNull($recommendation, 'No recommendation was produced for the head description.');
        $this->assertLessThan(
            0.05,
            (float) $recommendation['selection']['margin_to_next'],
            'This test only means something while the top two candidates are inside the margin bar.'
        );

        $this->assertContains(
            'low_margin',
            (array) $recommendation['selection']['reason_codes'],
            'A head item whose top two candidates are tied was selected without recording the '
                . 'ambiguity. The gate that exists for exactly this was switched off by item type.'
        );
        $this->assertSame(
            'unresolved',
            (string) $recommendation['default_decision'],
            'The ambiguity was recorded but the item was still defaulted to approve.'
        );
    }

    /**
     * The escalation has to be actionable, which means reaching the exception queue as a typed
     * row with the competing fields listed. On corpus slot 1 the field this surfaces —
     * `meta:_seo_description` — is the ground-truth-correct target, so the reviewer is being shown
     * the right answer rather than merely being told the mapper was unsure.
     */
    public function test_an_escalated_head_description_reaches_the_exception_queue_with_its_alternatives()
    {
        $this->register_description_fields();
        $this->write_bundle();

        $this->run_ingest();

        $item = $this->item_for('page-artifact.v1#metadata.description');
        $this->assertNotNull($item);

        $artifact = $this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_RECOMMENDATIONS_SUFFIX);
        $unresolved = [];
        foreach ((array) ($artifact['unresolved_items'] ?? []) as $candidate) {
            if (is_array($candidate) && (string) ($candidate['item_id'] ?? '') === (string) $item['item_id']) {
                $unresolved = $candidate;
                break;
            }
        }

        $this->assertNotSame([], $unresolved, 'The escalated head description never reached the exception queue.');
        $this->assertSame('page_description', (string) $unresolved['item_type']);
        $this->assertContains('low_margin', (array) $unresolved['reason_codes']);

        $alternatives = array_column((array) $unresolved['selection']['alternatives'], 'target_ref');
        $this->assertContains(
            'meta:post:page:_seo_description',
            $alternatives,
            'The reviewer cannot pick the competing field without being shown it.'
        );
        $this->assertContains('meta:post:page:_meta_description', $alternatives);
    }

    /**
     * The boundary the widening must not cross, and the reason it is not "head items no longer
     * auto-accept".
     *
     * A target with no description field of its own leaves `core:post_content` as the only home
     * for the head description, and the mapper scores that structural default 0.68 — under its
     * own 0.76 evidence floor. Escalating it would cost a reviewer a decision with one possible
     * answer and, because an unresolved item is a blocking readiness issue, hold the package
     * until they made it. The confidence is also a prior about the shape of the target rather
     * than a measurement, so there is nothing for an evidence floor to read.
     *
     * `contested_structural_default` is the gate for a default that has a competitor. This is the
     * same line D-22's fix drew for the head title; the widening keeps it rather than reaching
     * around it through a different gate.
     */
    public function test_a_head_description_whose_only_home_is_a_structural_default_is_not_escalated()
    {
        $this->write_bundle();

        $this->run_ingest();

        $recommendation = $this->recommendation_for('page-artifact.v1#metadata.description');
        $this->assertNotNull($recommendation, 'No recommendation was produced for the head description.');
        $this->assertSame(
            1,
            (int) $recommendation['selection']['candidate_count'],
            'This test only means something while the structural default is the only candidate.'
        );
        $this->assertLessThan(
            0.76,
            (float) $recommendation['selection']['adjusted_confidence'],
            'This test only means something while that one candidate is under the evidence floor.'
        );

        $this->assertSame(
            [],
            (array) $recommendation['selection']['reason_codes'],
            'A structural default with nowhere else to go was escalated, which asks a reviewer to '
                . 'choose between one option and blocks the package until they do.'
        );
        $this->assertSame('approve', (string) $recommendation['default_decision']);
    }

    /**
     * The other three gates are widened by the same guard, and this pins them. Only
     * `low_margin` is reachable for a head item on an ordinary catalog — with two or more
     * candidates the best one is always at or above the 0.78 catalog base, so the evidence floor
     * and the two pressure gates need a penalty to bite. That makes this the one case here driven
     * through `assign_content_items()` directly rather than through a run: the gates read
     * `claimed_targets`, which is accumulated across the item list in order, and the smallest
     * fixture that produces a claim ahead of the head item is the ordered list itself.
     *
     * The shape is real: a page whose description text scores best against a field an earlier
     * item has already taken. The duplicate penalty (-0.18) drags 0.78 to 0.63, under the 0.76
     * floor and still ahead of the weaker second candidate, so both gates read it.
     *
     * `competition_group_pressure` is widened by the same guard and is not pinned here: it reads
     * a slot graph's competition groups, which needs a live ACF catalog rather than a fixture.
     */
    public function test_a_head_description_escalates_when_its_best_target_is_already_claimed()
    {
        $assignment = DBVC_CC_V2_Mapping_Assignment_Service::get_instance()->assign_content_items(
            $this->domain,
            [
                'content_items' => [
                    [
                        'item_id' => 'map_001',
                        'item_type' => 'section',
                        'source_refs' => ['elements.v2#home-e2'],
                        'candidate_group' => 'section_field',
                        'context_tag' => 'content',
                        'value_preview' => 'A paragraph that also reads as a summary.',
                        'notes' => [],
                        'target_candidates' => [
                            [
                                'target_ref' => 'meta:post:page:_seo_description',
                                'confidence' => 0.84,
                                'reason' => 'pattern_field_catalog_match',
                                'pattern_key' => 'description',
                            ],
                        ],
                    ],
                    [
                        'item_id' => 'map_002',
                        'item_type' => 'page_description',
                        'source_refs' => ['page-artifact.v1#metadata.description'],
                        'candidate_group' => 'page_description',
                        'context_tag' => 'description',
                        'value_preview' => 'A family dental practice in Hamilton.',
                        'notes' => [],
                        'target_candidates' => [
                            [
                                'target_ref' => 'meta:post:page:_seo_description',
                                'confidence' => 0.78,
                                'reason' => 'pattern_field_catalog_match',
                                'pattern_key' => 'description',
                            ],
                            [
                                'target_ref' => 'meta:post:page:_meta_description',
                                'confidence' => 0.58,
                                'reason' => 'pattern_field_catalog_match',
                                'pattern_key' => 'description',
                            ],
                        ],
                    ],
                ],
                'unresolved_items' => [],
            ]
        );

        $head = $assignment['selected_items'][1];
        $this->assertSame('page_description', (string) $head['item_type']);

        $codes = (array) $head['selection']['reason_codes'];
        $this->assertContains(
            'duplicate_target_pressure',
            $codes,
            'A head item was pointed at a target another item had already taken, without saying so.'
        );
        $this->assertContains(
            'low_adjusted_confidence',
            $codes,
            'The penalty took the best candidate under the evidence floor and the gate that reads '
                . 'that was switched off by item type.'
        );
        $this->assertSame('unresolved', (string) $head['selection']['default_decision']);

        $this->assertSame(
            ['map_002'],
            array_column($assignment['unresolved_items'], 'item_id'),
            'The escalated head item should be the one thing in the exception queue.'
        );
    }

    /**
     * The guard is about which confidence SCALE applies, not about which items deserve review.
     *
     * An interpreter's answer arrives on its own scale — §6.1c measured a model mapping this page
     * perfectly at confidences the lexical thresholds would call weak — so judging it against
     * 0.76 would escalate correct mappings and drop the model's G-4 for a defect that is not on
     * its path. Driven through the injected transport: no network, no key.
     */
    public function test_an_interpreter_ranked_head_item_is_not_judged_against_the_lexical_floor()
    {
        $this->write_bundle();
        $this->inject_interpreter('head:description', 'core:post_content', 0.6);

        $this->run_ingest();

        $this->assertSame(0, $this->http_calls, 'The seam made a real HTTP request.');

        $recommendation = $this->recommendation_for('page-artifact.v1#metadata.description');
        $this->assertNotNull($recommendation);
        $this->assertSame(
            'core:post_content',
            (string) $recommendation['target_ref'],
            'The interpreter answered this item, so its answer should be the recommendation.'
        );
        $this->assertLessThan(
            0.76,
            (float) $recommendation['selection']['adjusted_confidence'],
            'This test only means something while the model\'s confidence is under the lexical '
                . 'floor, which is the case the previous test escalates on.'
        );
        $this->assertNotContains(
            'low_adjusted_confidence',
            (array) $recommendation['selection']['reason_codes'],
            'A model-authored mapping was measured against the lexical scorer\'s confidence scale.'
        );
    }

    /**
     * And the model path is not loosened either, which is the half that is easy to break while
     * widening the guard. A section item is gated exactly as it was before, whoever ranked it:
     * `$is_section_item` short-circuits the new condition, so turning the flag on changes nothing
     * about which items are gated in either direction.
     */
    public function test_an_interpreter_ranked_section_item_keeps_the_gates_it_already_had()
    {
        $this->register_description_fields();
        $this->write_bundle();
        $this->inject_interpreter('home-e2', 'meta:post:page:_seo_description', 0.6);

        $this->run_ingest();

        $this->assertSame(0, $this->http_calls, 'The seam made a real HTTP request.');

        $recommendation = null;
        foreach ((array) ($this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_RECOMMENDATIONS_SUFFIX)['recommendations'] ?? []) as $candidate) {
            if (
                is_array($candidate)
                && (string) ($candidate['target_ref'] ?? '') === 'meta:post:page:_seo_description'
                && ! in_array('page-artifact.v1#metadata.description', (array) ($candidate['source_refs'] ?? []), true)
            ) {
                $recommendation = $candidate;
                break;
            }
        }

        $this->assertNotNull($recommendation, 'The interpreter\'s section answer produced no recommendation.');
        $this->assertContains(
            'low_adjusted_confidence',
            (array) $recommendation['selection']['reason_codes'],
            'A section item stopped being gated once the interpreter ranked it. Widening the guard '
                . 'must not narrow it for the items it already covered.'
        );
    }

    /**
     * The limit of "the model path is untouched", stated as a test rather than as a claim.
     *
     * It is untouched for an item the interpreter ANSWERS, because
     * `apply_interpretation_ranking()` replaces that item's candidate list wholesale and every
     * candidate in it reads `model_interpretation`. An item it never answers is a different case:
     * the same function hands the LEXICAL candidates straight back, so the item was scored by the
     * lexical scorer and is gated as one — even on a run with the flag on.
     *
     * That is the correct behaviour, not a leak. The gates read a lexical confidence against
     * lexical bars, which is exactly what this item carries. It is pinned here because the
     * consequence is not free: an escalated item is an `unresolved_items` entry, and
     * `unresolved_count > 0` is a blocking readiness issue in
     * `DBVC_CC_V2_Url_QA_Report_Service`, so a page whose head item the model skips can now hold
     * its own package where before it could not. Anyone measuring the model path's G-4 needs to
     * know this moves with how often the interpreter answers the head items, which is why the
     * guide no longer says the flag-on path cannot change.
     */
    public function test_a_head_item_the_interpreter_skipped_is_gated_as_the_lexical_item_it_is()
    {
        $this->register_description_fields();
        $this->write_bundle();
        // The interpreter answers a SECTION element and nothing else, so `head:description`
        // reaches the gates with the lexical scorer's own candidates.
        $this->inject_interpreter('home-e2', 'meta:post:page:_seo_description', 0.6);

        $this->run_ingest();

        $this->assertSame(0, $this->http_calls, 'The seam made a real HTTP request.');

        $mapping = $this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_INDEX_SUFFIX);
        $head = null;
        foreach ((array) ($mapping['content_items'] ?? []) as $content_item) {
            if (
                is_array($content_item)
                && in_array('page-artifact.v1#metadata.description', (array) ($content_item['source_refs'] ?? []), true)
            ) {
                $head = $content_item;
                break;
            }
        }

        $this->assertNotNull($head, 'The captured page produced no head-description item.');
        $this->assertSame(
            'interpreter_did_not_answer',
            (string) ($head['interpretation']['reason'] ?? ''),
            'This test only means something while the interpreter leaves the head item alone.'
        );
        $this->assertSame(
            'lexical',
            (string) ($head['interpretation']['ranked_by'] ?? ''),
            'The item must still be carrying the lexical scorer\'s candidates.'
        );
        foreach ((array) $head['target_candidates'] as $candidate) {
            $this->assertNotSame(
                'model_interpretation',
                (string) ($candidate['reason'] ?? ''),
                'A model-authored candidate reached an item the interpreter did not answer.'
            );
        }

        $recommendation = $this->recommendation_for('page-artifact.v1#metadata.description');
        $this->assertNotNull($recommendation);
        $this->assertNotSame(
            [],
            (array) $recommendation['selection']['reason_codes'],
            'A head item the interpreter skipped was left ungated on a flag-on run, so turning '
                . 'the flag on would silently exempt it from the evidence floor.'
        );
        $this->assertSame('unresolved', (string) $recommendation['selection']['default_decision']);
    }

    /**
     * The one gate scoped to section items that stays scoped, and why.
     *
     * `DBVC_CC_V2_Target_Eligibility_Service`'s `strict_section_family` shares the shape of the
     * four ambiguity gates and not their purpose: it REJECTS candidates rather than escalating
     * them, and the value it filters on — `resolve_preferred_section_family()`, which falls back
     * to `context_tag` — is the literal `description` a head item carries as a content role. No
     * catalog spells a slot's `section_family` that way, so applying it to a head item would
     * reject every field the catalog offers and leave the structural default alone, which is the
     * opposite of what D-22 asked for. This pins that intent against a future widening.
     */
    public function test_the_section_family_filter_does_not_narrow_a_head_item_to_its_context_tag()
    {
        $this->register_description_fields();
        $this->write_bundle();

        $this->run_ingest();

        $item = $this->item_for('page-artifact.v1#metadata.description');
        $this->assertNotNull($item);

        $refs = array_column($item['target_candidates'], 'target_ref');
        $this->assertContains(
            'meta:post:page:_seo_description',
            $refs,
            'A head item lost its catalog candidates, which is what scoping the section-family '
                . 'filter to section items exists to prevent.'
        );
        $this->assertSame(
            'description',
            (string) $item['context_tag'],
            'This test only means something while the head item carries a content role as its '
                . 'context tag rather than a real section family.'
        );
    }

    /**
     * Two fields that both reduce to pattern key `description`, so both score the same flat
     * catalog-pattern base and the choice between them is alphabetical.
     *
     * @return void
     */
    private function register_description_fields()
    {
        foreach (['_seo_description', '_meta_description'] as $key) {
            register_post_meta('page', $key, [
                'single' => true,
                'type' => 'string',
                'show_in_rest' => true,
                'description' => 'The description used in search results.',
            ]);
        }
    }

    /**
     * @param string $item_id
     * @param string $target_ref
     * @param float  $confidence
     * @return void
     */
    private function inject_interpreter($item_id, $target_ref, $confidence)
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION, '1');
        $settings = DBVC_CC_Settings_Service::get_options();
        $settings['openai_api_key'] = 'test-key-not-a-real-one';
        $settings['openai_model'] = 'gpt-5.6-terra';
        update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $settings);

        $body = (string) wp_json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => (string) wp_json_encode([
                            'mappings' => [
                                [
                                    'item_id' => $item_id,
                                    'target_ref' => $target_ref,
                                    'confidence' => $confidence,
                                    'rationale' => 'Injected by a test transport.',
                                ],
                            ],
                            'declined' => [],
                        ]),
                    ],
                ],
            ],
        ]);

        $transport = static function () use ($body) {
            return ['status' => 200, 'body' => $body];
        };
        $this->options_filter = static function ($options) use ($transport) {
            $options['transport'] = $transport;

            return $options;
        };
        add_filter(DBVC_CC_V2_Mapping_Interpretation_Service::FILTER_INTERPRETATION_OPTIONS, $this->options_filter);
    }

    /**
     * @param string $source_ref
     * @return array<string, mixed>|null
     */
    private function item_for($source_ref)
    {
        $mapping = $this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_INDEX_SUFFIX);

        foreach ((array) ($mapping['content_items'] ?? []) as $content_item) {
            if (
                is_array($content_item)
                && in_array($source_ref, (array) ($content_item['source_refs'] ?? []), true)
            ) {
                return $content_item;
            }
        }

        return null;
    }

    /**
     * @param string $source_ref
     * @return array<string, mixed>|null
     */
    private function recommendation_for($source_ref)
    {
        $artifact = $this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_RECOMMENDATIONS_SUFFIX);

        foreach ((array) ($artifact['recommendations'] ?? []) as $recommendation) {
            if (
                is_array($recommendation)
                && in_array($source_ref, (array) ($recommendation['source_refs'] ?? []), true)
            ) {
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
     * One captured page with a head description and one section, which is the smallest shape that
     * produces both a `page_description` item and a `section` item.
     *
     * @return void
     */
    private function write_bundle()
    {
        $slug = 'home';
        $source_url = 'https://head-item-gates.test/';
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
                    'description' => 'A family dental practice in Hamilton offering preventative and general dentistry.',
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
