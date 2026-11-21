<?php

require_once dirname(__DIR__, 2) . '/addons/content-migration/tests/corpus/target/corpus-target-translation.php';

/**
 * G-4 and G-5, measured on the wired path.
 *
 * The corpus evaluator measures the interpretation boundary headless: it reads the pinned
 * `target-schema.v1.json` straight off disk and builds the model's input from markdown with its
 * own parser. That is the right harness for calibrating an interpreter and the wrong one for
 * answering "does a run produce model-authored recommendations", because it exercises none of the
 * product between the model and the reviewer. D-20's remaining half was exactly that gap.
 *
 * This measures the whole chain a run takes:
 *
 *   corpus artifacts on disk
 *     -> the ingest seam (S2c)                     -> stored page artifacts
 *     -> the live target field catalog              -> corpus-target-schema.v1 via the bridge
 *     -> the bridge's input builder (elements.v2)   -> interpretation-input.v1
 *     -> one real model call                        -> interpretation-result.v1
 *     -> the mapping index's candidate seam         -> mapping-index.v1
 *     -> the assignment service's gates             -> mapping-recommendations.v2
 *
 * and scores the last artifact against the same hand-authored ground truth
 * (`expected-mappings.json`) the evaluator uses.
 *
 * It costs money and needs a key, so it is skipped unless `DBVC_CC_CORPUS_MEASURE=1`:
 *
 *   DBVC_CC_CORPUS_MEASURE=1 DBVC_CC_OPENAI_API_KEY=... \
 *     vendor/bin/phpunit --filter ContentMigrationCorpusWiredMeasurementTest --do-not-cache-result
 *
 * Two deliberate stand-ins, both named rather than hidden:
 *
 * - ACF is not installed in the test environment, so the pinned schema is loaded into the
 *   `$GLOBALS['dbvc_test_acf_*']` doubles the suite already uses. Those two functions are the
 *   legacy catalog service's entire ACF contract, so everything downstream of them is shipped
 *   code. The definitions come from `DBVC_CC_Corpus_Target_Translation`, the same translation
 *   the provisioned target site registers with real ACF, so the two are one target.
 * - The pinned schema and the live catalog do not write a target_ref the same way. The schema
 *   authors a repeater sub-field as `acf:page_cards:items.card_title` and a meta field as
 *   `meta:_seo_title`; the product writes `acf:<group_key>:<field_key>` flat and
 *   `meta:post:<subtype>:<key>`, and gives a leaf the schema reuses across groups its group as
 *   a prefix (`acf:page_hero:page_hero_heading`). `normalise_target_ref()` reconciles the two for
 *   scoring only.
 *   Nothing in the product changes shape for this test.
 */
class ContentMigrationCorpusWiredMeasurementTest extends WP_UnitTestCase
{
    /**
     * @var string
     */
    private $dir = '';

    /**
     * @var string
     */
    private $domain = 'mirceaolteanudds.com';

    /**
     * @var callable|null
     */
    private $permit = null;

    /**
     * @var DBVC_CC_Corpus_Target_Translation|null
     */
    private static $target = null;

    public function set_up(): void
    {
        parent::set_up();

        if (getenv('DBVC_CC_CORPUS_MEASURE') !== '1') {
            $this->markTestSkipped('Set DBVC_CC_CORPUS_MEASURE=1 to run the paid wired measurement.');
        }

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        DBVC_CC_V2_Contracts::ensure_defaults();

        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V2);
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION, '1');
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();

        global $wp_rest_server;
        $wp_rest_server = null;

        $this->install_target_schema();
        // Artifacts outlive a PHPUnit process, so a leftover run would be read as this one's.
        $this->delete_domain_artifacts();

        $this->dir = wp_normalize_path(get_temp_dir() . 'dbvc-cc-corpus-wired-' . wp_generate_password(8, false));
        wp_mkdir_p($this->dir);

        $dir = $this->dir;
        $this->permit = static function ($roots) use ($dir) {
            $roots[] = $dir;

            return $roots;
        };
        add_filter('dbvc_cc_v2_ingest_allowed_roots', $this->permit);
    }

    public function tear_down(): void
    {
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

        global $wp_rest_server;
        $wp_rest_server = null;

        parent::tear_down();
    }

    /**
     * The same measurement with the flag off, so the model's numbers have the product's own
     * lexical floor beside them rather than the compact headless floor the evaluator uses.
     * Free: no model call is made.
     */
    public function test_g4_and_g5_on_the_wired_path_with_the_lexical_floor()
    {
        delete_option(DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION);

        $artifacts = $this->artifacts_dir();
        if ($artifacts === '') {
            $this->markTestSkipped(
                'Corpus fixture artifacts are not present. Set DBVC_CORPUS_FIXTURES to the slot capture root.'
            );
        }

        $this->stage_page('home', $artifacts);
        $this->run_ingest();

        $this->assertSame(
            [],
            $this->read_artifact('home', DBVC_CC_V2_Contracts::STORAGE_INTERPRETATION_RESULT_SUFFIX),
            'The flag was off, so nothing should have interpreted anything.'
        );

        $recommendations = $this->read_artifact('home', DBVC_CC_V2_Contracts::STORAGE_MAPPING_RECOMMENDATIONS_SUFFIX);
        $report = array_merge(
            $this->score($recommendations, $this->expected_mappings($this->slot_dir(), 'home')),
            $this->interpretation_counts()
        );

        fwrite(STDERR, "\n" . $this->format_report(
            ['interpreter' => 'lexical_mapping_index', 'model_attempt' => []],
            $report
        ) . "\n");

        $this->assertNotSame([], $recommendations, 'No mapping recommendations artifact was written.');
    }

    public function test_g4_and_g5_on_the_wired_path()
    {
        $slot = $this->slot_dir();
        $artifacts = $this->artifacts_dir();
        if ($artifacts === '') {
            $this->markTestSkipped(
                'Corpus fixture artifacts are not present. Set DBVC_CORPUS_FIXTURES to the slot capture root.'
            );
        }

        $this->stage_page('home', $artifacts);

        $this->run_ingest();

        $result = $this->read_artifact('home', DBVC_CC_V2_Contracts::STORAGE_INTERPRETATION_RESULT_SUFFIX);
        $this->assertNotSame([], $result, 'No interpretation result was persisted. Stored: ' . implode(', ', $this->stored_files()));

        $recommendations = $this->read_artifact('home', DBVC_CC_V2_Contracts::STORAGE_MAPPING_RECOMMENDATIONS_SUFFIX);
        $this->assertNotSame([], $recommendations, 'No mapping recommendations artifact was written.');

        $expected = $this->expected_mappings($slot, 'home');
        $report = $this->score($recommendations, $expected);
        $report = array_merge($report, $this->interpretation_counts());

        fwrite(STDERR, "\n" . $this->format_report($result, $report) . "\n");

        // The measurement is the deliverable; the assertion is only that it ran end to end on the
        // model path. Moving a gate threshold from one corpus slot is how you get a threshold
        // that works on exactly that slot, and G-4's 0.92 is deliberately not recalibrated here.
        $this->assertSame(
            DBVC_CC_Interp_Contracts::INTERPRETER_MODEL,
            (string) $result['interpreter'],
            'The model did not answer: ' . (string) ($result['model_attempt']['reason'] ?? '')
                . ' — ' . (string) ($result['model_attempt']['detail'] ?? '')
        );
    }

    /**
     * `/dental-team` on the wired path: the page V2 routes to `page`, which must become five
     * `team_member` records (D-19). The interpreter is offered the classifier's other post-type
     * candidates as collections and decides whether the page is a set. Paid: one model call.
     */
    public function test_the_team_page_on_the_wired_path_becomes_five_team_member_records()
    {
        $artifacts = $this->artifacts_dir();
        if ($artifacts === '') {
            $this->markTestSkipped('Corpus fixture artifacts are not present. Set DBVC_CORPUS_FIXTURES to the slot capture root.');
        }

        // A reviewed answer is cached by prompt version, model and payload, so a rerun replays
        // the same model answer (`model_attempt.from_cache`) instead of paying for a new one.
        $cache = static function ($options) {
            $options['cache_dir'] = get_temp_dir() . 'dbvc-cc-corpus-wired-model-cache';

            return $options;
        };
        add_filter(DBVC_CC_V2_Mapping_Interpretation_Service::FILTER_INTERPRETATION_OPTIONS, $cache);

        $this->stage_page('dental-team', $artifacts);
        $run = $this->run_ingest();
        remove_filter(DBVC_CC_V2_Mapping_Interpretation_Service::FILTER_INTERPRETATION_OPTIONS, $cache);

        $result = $this->read_artifact('dental-team', DBVC_CC_V2_Contracts::STORAGE_INTERPRETATION_RESULT_SUFFIX);
        $recommendations = $this->read_artifact('dental-team', DBVC_CC_V2_Contracts::STORAGE_MAPPING_RECOMMENDATIONS_SUFFIX);
        $report = $this->score_records($recommendations, $this->expected_page($this->slot_dir(), 'dental-team'));

        fwrite(STDERR, "\n" . $this->format_record_report($result, $report, 'dental-team') . "\n");

        $this->assertSame(
            DBVC_CC_Interp_Contracts::INTERPRETER_MODEL,
            (string) ($result['interpreter'] ?? ''),
            'The model did not answer: ' . (string) ($result['model_attempt']['reason'] ?? '')
                . ' — ' . (string) ($result['model_attempt']['detail'] ?? '')
        );

        // And the set is what gets built: a reviewer approves the people, leaves the page-level
        // items out, and the package commits one team_member post per person.
        $run_id = (string) ($run['runId'] ?? '');
        $page_id = '';
        foreach ($run['pages'] ?? [] as $page) {
            if (strpos((string) ($page['path'] ?? ''), 'dental-team') !== false) {
                $page_id = (string) $page['pageId'];
            }
        }
        $detail = $this->rest('GET', '/runs/' . $run_id . '/urls/' . $page_id);
        // Where two values claim one field of one person — a role heading and the photo's alt
        // text both read as the role — the reviewer keeps the first and rejects the rest.
        // The stand-in reviewer is the ground truth: it also rejects what the ground truth says
        // belongs to no field (alt text lifted into a paragraph, footer chrome), as a reviewer
        // reading the escalation would.
        $page_truth = $this->expected_page($this->slot_dir(), 'dental-team');
        $conflict_losers = [];
        foreach ($detail['recommendations']['fieldRecommendations'] ?? [] as $recommendation) {
            foreach (array_merge($page_truth['expected_unmapped'] ?? [], $page_truth['must_not_map'] ?? []) as $gap) {
                if (stripos((string) ($recommendation['source_evidence'] ?? ''), (string) $gap['source_probe']) !== false) {
                    $conflict_losers[] = (string) $recommendation['recommendation_id'];
                }
            }
        }
        foreach ($detail['recommendations']['conflicts'] ?? [] as $conflict) {
            $ids = array_values(array_diff(array_map('strval', (array) ($conflict['recommendation_ids'] ?? [])), $conflict_losers));
            array_shift($ids);
            $conflict_losers = array_merge($conflict_losers, $ids);
        }
        $conflict_losers = array_values(array_unique($conflict_losers));
        $this->rest('POST', '/runs/' . $run_id . '/urls/' . $page_id . '/decision', [
            'approvedRecommendationIds' => array_values(array_filter(array_map(
                static function ($recommendation) use ($conflict_losers) {
                    // Only values filed under a person; a value with no record has nowhere to go.
                    return isset($recommendation['entity_ref']) && $recommendation['entity_ref'] !== ''
                        && ! in_array((string) $recommendation['recommendation_id'], $conflict_losers, true)
                        ? (string) $recommendation['recommendation_id']
                        : '';
                },
                $detail['recommendations']['fieldRecommendations'] ?? []
            ))),
            'rejectedRecommendationIds' => array_values(array_filter(array_map(
                static function ($recommendation) use ($conflict_losers) {
                    return empty($recommendation['entity_ref']) || in_array((string) $recommendation['recommendation_id'], $conflict_losers, true)
                        ? (string) $recommendation['recommendation_id']
                        : '';
                },
                $detail['recommendations']['fieldRecommendations'] ?? []
            ))),
            'leftUnmappedItemIds' => array_column($detail['recommendations']['unresolvedItems'] ?? [], 'item_id'),
        ]);
        $package = $this->rest('POST', '/runs/' . $run_id . '/package');
        $package_id = (string) ($package['selectedPackageId'] ?? '');

        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');
        $approval = $this->rest('POST', '/runs/' . $run_id . '/preflight-approve', ['packageId' => $package_id, 'confirmApproval' => true]);
        $execute = $this->rest('POST', '/runs/' . $run_id . '/execute', [
            'packageId' => $package_id,
            'confirmExecute' => true,
            'approvalTokens' => $approval['approvalTokens'] ?? [],
        ]);
        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '0');

        $posts = get_posts(['post_type' => 'team_member', 'post_status' => 'any', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC']);
        $lines = [sprintf('  from cache         : %s; reviewer rejected %d value(s): ground-truth unmapped, then conflict losers', ! empty($result['model_attempt']['from_cache']) ? 'yes' : 'no', count($conflict_losers))];
        foreach ($package['selectedPackage']['qaReport']['blocking_issues'] ?? [] as $issue) {
            $lines[] = '  package blocker    : ' . (string) ($issue['code'] ?? '');
        }
        $lines[] = sprintf(
            '  package            : %s, %d records; execute %s, %d completed / %d blocked; %d team_member posts',
            (string) ($package['selectedPackage']['qaReport']['readiness_status'] ?? '?'),
            (int) ($package['selectedPackage']['summary']['record_count'] ?? 0),
            (string) ($execute['status'] ?? '?'),
            (int) ($execute['summary']['completedPages'] ?? 0),
            (int) ($execute['summary']['blockedPages'] ?? 0),
            count($posts)
        );
        foreach ($posts as $post) {
            $lines[] = sprintf(
                '    post %-5d %-22s role=%s | bio=%d chars',
                $post->ID,
                $post->post_title,
                (string) get_post_meta($post->ID, 'role', true) ?: '-',
                strlen((string) get_post_meta($post->ID, 'bio', true))
            );
        }
        fwrite(STDERR, implode("\n", $lines) . "\n");

        $this->assertCount(5, $posts, 'The built package should commit the five people as five team_member posts.');
    }

    /**
     * @param string               $method
     * @param string               $route
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function rest($method, $route, array $params = [])
    {
        $request = new WP_REST_Request($method, '/' . DBVC_CC_V2_Contracts::REST_NAMESPACE . $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        $response = rest_do_request($request);
        if ($response->is_error()) {
            $error = $response->as_error();
            $this->fail(sprintf('%s %s failed: [%s] %s', $method, $route, $error->get_error_code(), $error->get_error_message()));
        }
        $data = $response->get_data();

        return is_array($data) ? $data : [];
    }

    /**
     * The same page with the flag off. Free. The lexical floor never reads a page as a set, so
     * this is the single-record baseline the model's set is measured against.
     */
    public function test_the_team_page_on_the_wired_path_with_the_lexical_floor()
    {
        delete_option(DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION);
        $artifacts = $this->artifacts_dir();
        if ($artifacts === '') {
            $this->markTestSkipped('Corpus fixture artifacts are not present. Set DBVC_CORPUS_FIXTURES to the slot capture root.');
        }

        $this->stage_page('dental-team', $artifacts);
        $this->run_ingest();

        $recommendations = $this->read_artifact('dental-team', DBVC_CC_V2_Contracts::STORAGE_MAPPING_RECOMMENDATIONS_SUFFIX);
        $report = $this->score_records($recommendations, $this->expected_page($this->slot_dir(), 'dental-team'));

        fwrite(STDERR, "\n" . $this->format_record_report(
            ['interpreter' => 'lexical_mapping_index', 'model_attempt' => []],
            $report,
            'dental-team'
        ) . "\n");

        $this->assertArrayNotHasKey('target_entities', $recommendations, 'The lexical floor must never read a page as a set.');
    }

    /**
     * Score a page whose ground truth is a set of records: a value is right only in the right
     * field of the right person. Mirrors `dbvc_corpus_score_entity_recommendations()` in the
     * headless evaluator, on the product's own artifact.
     *
     * @param array<string, mixed> $artifact mapping-recommendations.v2
     * @param array<string, mixed> $page     expected-mappings.json page entry
     * @return array<string, mixed>
     */
    private function score_records(array $artifact, array $page)
    {
        $expected = array_values($page['expected_entities'] ?? []);
        $entities = DBVC_CC_V2_Contracts::has_entity_set($artifact) ? DBVC_CC_V2_Contracts::get_target_entities($artifact) : [];

        $own_for = [];
        foreach ($expected as $index => $person) {
            $name = strtolower((string) $person['entity']);
            foreach ($entities as $entity) {
                $label = strtolower((string) ($entity['label'] ?? ''));
                if ($label !== '' && ! isset($own_for[$entity['entity_ref']]) && (strpos($label, $name) !== false || strpos($name, $label) !== false)) {
                    $own_for[(string) $entity['entity_ref']] = $index;
                    break;
                }
            }
        }

        $auto_accepted = 0;
        $wrong_auto_accepted = 0;
        $right = 0;
        $wrong = 0;
        $misses = [];
        $values = [];
        foreach ($artifact['recommendations'] ?? [] as $recommendation) {
            $accepted = empty($recommendation['requires_review']);
            $auto_accepted += $accepted ? 1 : 0;
            $evidence = strtolower($this->evidence_of($recommendation));
            $actual = $this->normalise_target_ref($this->target_ref_of($recommendation));
            $entity_ref = (string) ($recommendation['entity_ref'] ?? '');
            $own = $own_for[$entity_ref] ?? null;
            if ($own !== null) {
                $values[$own][$actual][] = (string) ($recommendation['source_evidence'] ?? '');
            }

            $order = array_keys($expected);
            if ($own !== null) {
                $order = array_merge([$own], array_values(array_diff($order, [$own])));
            }
            $verdict = null;
            // Alt text the capture lifted into a paragraph embeds the person's name and role; the
            // ground truth says it belongs to no field, and that more specific probe wins.
            foreach ($page['expected_unmapped'] ?? [] as $gap) {
                if (stripos($evidence, (string) $gap['source_probe']) !== false) {
                    $verdict = 'wrong';
                    $misses[] = sprintf(
                        '%s -> %s%s (expected unmapped%s)',
                        substr($evidence, 0, 40),
                        $actual,
                        $own === null ? ' in no record' : ' of ' . $expected[$own]['entity'],
                        $accepted ? '; AUTO-ACCEPTED' : '; escalated'
                    );
                    break;
                }
            }
            foreach ($verdict === null ? $order : [] as $index) {
                $hits = [];
                foreach ($expected[$index]['mappings'] ?? [] as $mapping) {
                    if (stripos($evidence, (string) $mapping['source_probe']) !== false) {
                        $hits[] = $this->normalise_target_ref((string) $mapping['target_ref']);
                    }
                }
                if ($hits === []) {
                    continue;
                }
                $verdict = ($own === $index && in_array($actual, $hits, true)) ? 'right' : 'wrong';
                if ($verdict === 'wrong') {
                    $misses[] = sprintf(
                        '%s -> %s%s (expected %s of %s)',
                        substr($evidence, 0, 40),
                        $actual,
                        $own === null ? ' in no record' : ' of ' . $expected[$own]['entity'],
                        $hits[0],
                        $expected[$index]['entity']
                    );
                }
                break;
            }
            if ($verdict === null) {
                foreach ($page['must_not_map'] ?? [] as $forbidden) {
                    if (stripos($evidence, (string) $forbidden['source_probe']) !== false) {
                        $verdict = 'wrong';
                        $misses[] = sprintf('%s -> %s (must not map)', $forbidden['source_probe'], $actual);
                        break;
                    }
                }
            }
            if ($verdict === 'right') {
                ++$right;
            } elseif ($verdict === 'wrong') {
                ++$wrong;
                $wrong_auto_accepted += $accepted ? 1 : 0;
            }
        }

        $total = count($artifact['recommendations'] ?? []);

        return [
            'records' => array_map(
                static function ($entity) {
                    return (string) ($entity['label'] ?? '') . ' [' . (string) ($entity['target_object_key'] ?? '') . ']';
                },
                $entities
            ),
            'expected_records' => count($expected),
            'values' => $values,
            'names' => array_column($expected, 'entity'),
            'recommendations' => $total,
            'auto_accepted' => $auto_accepted,
            'auto_resolve_rate' => $total === 0 ? 0.0 : round(100 * $auto_accepted / $total, 1),
            'wrong_auto_accepted' => $wrong_auto_accepted,
            'precision_error_rate' => $auto_accepted === 0 ? null : round(100 * $wrong_auto_accepted / $auto_accepted, 1),
            'right' => $right,
            'wrong' => $wrong,
            'field_accuracy' => ($right + $wrong) === 0 ? 0.0 : round(100 * $right / ($right + $wrong), 1),
            'misses' => $misses,
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $report
     * @param string               $slug
     * @return string
     */
    private function format_record_report(array $result, array $report, $slug)
    {
        $attempt = isset($result['model_attempt']) && is_array($result['model_attempt']) ? $result['model_attempt'] : [];
        $lines = [
            sprintf('=== One URL, many records on the WIRED path (corpus slot 1, %s) ===', $slug),
            sprintf('  interpreter        : %s  (%s)', (string) ($result['interpreter'] ?? ''), (string) ($attempt['model'] ?? 'no model')),
            sprintf('  records            : %d produced / %d expected: %s', count($report['records']), (int) $report['expected_records'], implode('; ', $report['records'])),
            sprintf('  G-4 auto-resolve   : %d/%d = %s%%', (int) $report['auto_accepted'], (int) $report['recommendations'], $report['auto_resolve_rate']),
            sprintf(
                '  G-5 wrong accepted : %d/%d = %s',
                (int) $report['wrong_auto_accepted'],
                (int) $report['auto_accepted'],
                $report['precision_error_rate'] === null ? 'no denominator' : $report['precision_error_rate'] . '%'
            ),
            sprintf('  field accuracy     : %d right, %d wrong = %s%% (right field AND right person)', (int) $report['right'], (int) $report['wrong'], $report['field_accuracy']),
        ];
        foreach ($report['names'] as $index => $name) {
            $values = $report['values'][$index] ?? [];
            $lines[] = sprintf(
                '    %-22s name=%s | role=%s | bio=%d paragraph(s)',
                $name,
                implode(' / ', $values['core:post_title'] ?? ['-']),
                implode(' / ', $values['acf:team_member:role'] ?? ['-']),
                count($values['acf:team_member:bio'] ?? [])
            );
        }
        foreach ($report['misses'] as $miss) {
            $lines[] = '    wrong       : ' . $miss;
        }

        return implode("\n", $lines);
    }

    /**
     * @param string $slot
     * @param string $slug
     * @return array<string, mixed>
     */
    private function expected_page($slot, $slug)
    {
        foreach ($this->read_json($slot . '/expected-mappings.json')['pages'] ?? [] as $page) {
            if ((string) ($page['slug'] ?? '') === $slug) {
                return $page;
            }
        }

        return [];
    }

    /**
     * How many answers the interpreter produced, and how many the mapping stage had nowhere to
     * put. The second number is the one the headless evaluator cannot see.
     *
     * @return array<string, int>
     */
    private function interpretation_counts()
    {
        $mapping = $this->read_artifact('home', DBVC_CC_V2_Contracts::STORAGE_MAPPING_INDEX_SUFFIX);
        $summary = isset($mapping['interpretation']) && is_array($mapping['interpretation'])
            ? $mapping['interpretation']
            : [];

        return [
            'interpreter_recommendations' => (int) ($summary['recommendation_count'] ?? 0),
            'unjoined' => (int) ($summary['unjoined_recommendation_items'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed> The run payload.
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

        $data = $response->get_data();

        return is_array($data) ? $data : [];
    }

    /**
     * Score the product's own recommendations against the corpus ground truth.
     *
     * Probes are matched on source evidence, never on a generated id, exactly as
     * `expected-mappings.json#matching` requires.
     *
     * @param array<string, mixed> $artifact
     * @param array<int, array<string, mixed>> $expected
     * @return array<string, mixed>
     */
    private function score(array $artifact, array $expected)
    {
        $recommendations = isset($artifact['recommendations']) && is_array($artifact['recommendations'])
            ? $artifact['recommendations']
            : [];

        $auto_accepted = 0;
        $wrong_auto_accepted = 0;
        $probe_hits = 0;
        $right = 0;
        $wrong = 0;
        $misses = [];

        $expected_by_probe = [];
        foreach ($expected as $entry) {
            $expected_by_probe[(string) $entry['source_probe']] = $this->normalise_target_ref((string) $entry['target_ref']);
        }

        foreach ($recommendations as $recommendation) {
            if (! is_array($recommendation)) {
                continue;
            }

            // `requires_review` is where `autoAcceptMinConfidence` (0.92) is applied, so it is
            // the operational meaning of "mapped with no reviewer touch". `default_decision` is
            // the assignment service's ambiguity verdict, which is a weaker bar.
            $accepted = empty($recommendation['requires_review']);
            if ($accepted) {
                ++$auto_accepted;
            }

            $evidence = $this->evidence_of($recommendation);
            $actual = $this->normalise_target_ref($this->target_ref_of($recommendation));

            foreach ($expected_by_probe as $probe => $expected_ref) {
                if (stripos($evidence, $probe) === false) {
                    continue;
                }

                ++$probe_hits;
                if ($actual === $expected_ref) {
                    ++$right;
                } else {
                    ++$wrong;
                    $misses[] = sprintf('%s -> %s (expected %s)', $probe, $actual, $expected_ref);
                    if ($accepted) {
                        ++$wrong_auto_accepted;
                    }
                }
            }
        }

        $unmatched = [];
        foreach ($expected_by_probe as $probe => $expected_ref) {
            $seen = false;
            foreach ($recommendations as $recommendation) {
                if (is_array($recommendation) && stripos($this->evidence_of($recommendation), $probe) !== false) {
                    $seen = true;
                    break;
                }
            }
            if (! $seen) {
                $unmatched[] = sprintf('%s (expected %s)', $probe, $expected_ref);
            }
        }

        $total = count($recommendations);

        return [
            'unmatched' => $unmatched,
            'recommendations' => $total,
            'auto_accepted' => $auto_accepted,
            'auto_resolve_rate' => $total === 0 ? 0.0 : round(100 * $auto_accepted / $total, 1),
            'wrong_auto_accepted' => $wrong_auto_accepted,
            'precision_error_rate' => $auto_accepted === 0 ? null : round(100 * $wrong_auto_accepted / $auto_accepted, 1),
            'probes_total' => count($expected_by_probe),
            'probes_hit' => $probe_hits,
            'right' => $right,
            'wrong' => $wrong,
            'field_accuracy' => ($right + $wrong) === 0 ? 0.0 : round(100 * $right / ($right + $wrong), 1),
            'misses' => $misses,
        ];
    }

    /**
     * The pinned schema and the live catalog spell the same field differently. Reconciled for
     * scoring only; nothing in the product is changed to make this test pass.
     *
     * @param string $target_ref
     * @return string
     */
    private function normalise_target_ref($target_ref)
    {
        $target_ref = (string) $target_ref;

        // `meta:post:page:_seo_title` and the schema's `meta:_seo_title` are the same field.
        if (strpos($target_ref, 'meta:post:') === 0) {
            $parts = explode(':', $target_ref);
            $target_ref = 'meta:' . end($parts);
        }

        // The schema authors a repeater sub-field as `group:items.sub`; the catalog flattens it
        // to `group:sub`, because an ACF field key is unique on its own.
        if (strpos($target_ref, 'acf:') === 0 && strpos($target_ref, '.') !== false) {
            $parts = explode(':', $target_ref);
            $leaf = array_pop($parts);
            $leaf_parts = explode('.', $leaf);
            $parts[] = (string) end($leaf_parts);
            $target_ref = implode(':', $parts);
        }

        // A leaf the schema reuses across groups carries its group as a prefix in the catalog
        // (`acf:page_hero:page_hero_heading`), because a real ACF install could not hold both.
        return self::$target !== null ? self::$target->schema_ref($target_ref) : $target_ref;
    }

    /**
     * @param array<string, mixed> $recommendation
     * @return string
     */
    private function evidence_of(array $recommendation)
    {
        $parts = [];
        foreach (['value_preview', 'source_preview', 'source_evidence'] as $key) {
            if (isset($recommendation[$key]) && is_string($recommendation[$key])) {
                $parts[] = $recommendation[$key];
            }
        }
        if (isset($recommendation['source']['value_preview']) && is_string($recommendation['source']['value_preview'])) {
            $parts[] = $recommendation['source']['value_preview'];
        }
        if (isset($recommendation['transform']['value_preview']) && is_string($recommendation['transform']['value_preview'])) {
            $parts[] = $recommendation['transform']['value_preview'];
        }

        return implode(' | ', $parts);
    }

    /**
     * @param array<string, mixed> $recommendation
     * @return string
     */
    private function target_ref_of(array $recommendation)
    {
        foreach ([
            ['target_ref'],
            ['selected_candidate', 'target_ref'],
            ['target', 'target_ref'],
        ] as $path) {
            $value = $recommendation;
            foreach ($path as $segment) {
                if (! is_array($value) || ! isset($value[$segment])) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $report
     * @return string
     */
    private function format_report(array $result, array $report)
    {
        $attempt = isset($result['model_attempt']) && is_array($result['model_attempt']) ? $result['model_attempt'] : [];
        $lines = [
            '=== G-4 / G-5 on the WIRED path (corpus slot 1, home) ===',
            sprintf(
                '  interpreter        : %s  (%s%s)',
                (string) $result['interpreter'],
                (string) ($attempt['model'] ?? 'no model'),
                isset($attempt['reason']) && $attempt['reason'] !== '' ? ', reason ' . $attempt['reason'] : ''
            ),
            sprintf(
                '  recommendations    : %d  (interpreter answered %d; %d had no mapping item)',
                (int) $report['recommendations'],
                (int) $report['interpreter_recommendations'],
                (int) $report['unjoined']
            ),
            sprintf(
                '  G-4 auto-resolve   : %d/%d = %s%%   threshold 80%% (not recalibrated here)',
                (int) $report['auto_accepted'],
                (int) $report['recommendations'],
                $report['auto_resolve_rate']
            ),
            sprintf(
                '  G-5 wrong accepted : %d/%d = %s   ceiling 2%%',
                (int) $report['wrong_auto_accepted'],
                (int) $report['auto_accepted'],
                $report['precision_error_rate'] === null ? 'no denominator' : $report['precision_error_rate'] . '%'
            ),
            sprintf(
                '  probes             : %d/%d matched; %d right, %d wrong = %s%% field accuracy',
                (int) $report['probes_hit'],
                (int) $report['probes_total'],
                (int) $report['right'],
                (int) $report['wrong'],
                $report['field_accuracy']
            ),
        ];

        foreach ($report['misses'] as $miss) {
            $lines[] = '    wrong field : ' . $miss;
        }
        foreach ($report['unmatched'] as $unmatched) {
            $lines[] = '    no rec for  : ' . $unmatched;
        }

        return implode("\n", $lines);
    }

    /**
     * Declare the pinned schema to the shipped catalog builder.
     *
     * @return void
     */
    private function install_target_schema()
    {
        self::$target = DBVC_CC_Corpus_Target_Translation::from_file($this->slot_dir() . '/target-schema.v1.json');

        foreach (self::$target->post_types() as $post_type => $args) {
            register_post_type($post_type, $args);
        }

        foreach (self::$target->post_meta() as $meta) {
            register_post_meta($meta['object_key'], $meta['meta_key'], $meta['args']);
        }

        // The repo's test suite already declares `acf_get_field_groups()` and `acf_get_fields()`
        // as doubles reading these globals (see ContentCollectorV2Phase32Test), which are the
        // legacy catalog service's entire ACF contract. Everything downstream of them is shipped
        // code. The provisioned target site registers the same definitions with real ACF.
        $doubles = self::$target->acf_doubles();
        $GLOBALS['dbvc_test_acf_groups'] = $doubles['groups'];
        $GLOBALS['dbvc_test_acf_fields'] = $doubles['fields'];
    }

    /**
     * @param string $slot
     * @param string $slug
     * @return array<int, array<string, mixed>>
     */
    private function expected_mappings($slot, $slug)
    {
        $expected = $this->read_json($slot . '/expected-mappings.json');
        foreach ($expected['pages'] ?? [] as $page) {
            if ((string) ($page['slug'] ?? '') === $slug) {
                return isset($page['mappings']) && is_array($page['mappings']) ? $page['mappings'] : [];
            }
        }

        return [];
    }

    /**
     * @param string $slug
     * @param string $artifacts
     * @return void
     */
    private function stage_page($slug, $artifacts)
    {
        foreach ([
            'page-artifact.v1',
            'elements.v2',
            'sections.v2',
            'ingestion-package.v2',
        ] as $type) {
            $source = trailingslashit($artifacts) . $slug . '.' . $type . '.json';
            $this->assertFileExists($source);
            copy($source, trailingslashit($this->dir) . $slug . '.' . $type . '.json');
        }
    }

    /**
     * @return string
     */
    private function slot_dir()
    {
        return dirname(__DIR__, 2)
            . '/addons/content-migration/tests/corpus/slots/mirceaolteanudds-com/2026-10-10';
    }

    /**
     * @return string
     */
    private function artifacts_dir()
    {
        $profile = $this->read_json($this->slot_dir() . '/profile.json');
        $env = getenv('DBVC_CORPUS_FIXTURES');
        $root = is_string($env) && $env !== '' ? $env : (string) ($profile['fixtures']['default_root'] ?? '');
        if (strpos($root, '~') === 0) {
            $home = getenv('HOME');
            $root = (is_string($home) ? $home : '') . substr($root, 1);
        }

        $dir = rtrim($root, '/') . '/dbvc-artifacts';

        return is_dir($dir) ? $dir : '';
    }

    /**
     * @return array<int, string>
     */
    private function stored_files()
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($this->domain);
        if (is_wp_error($context)) {
            return ['no domain context'];
        }

        $found = [];
        foreach ((array) glob(trailingslashit($context['domain_dir']) . '*/*') as $match) {
            $found[] = str_replace($context['domain_dir'], '', (string) $match);
        }

        return $found;
    }

    /**
     * Each test is its own run; a stale artifact from the previous one would be read as this
     * run's output.
     *
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
     * @param string $slug
     * @param string $suffix
     * @return array<string, mixed>
     */
    private function read_artifact($slug, $suffix)
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($this->domain);
        if (is_wp_error($context)) {
            return [];
        }

        $matches = glob(trailingslashit($context['domain_dir']) . '*/' . $slug . $suffix);

        return is_array($matches) && $matches !== [] ? $this->read_json($matches[0]) : [];
    }

    /**
     * @param string $path
     * @return array<string, mixed>
     */
    private function read_json($path)
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
