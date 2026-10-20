<?php

/**
 * The mapping stage, routed through the interpretation boundary.
 *
 * S1b measured the interpreter at 100% field accuracy on corpus slot 1's homepage against the
 * deterministic floor's 4.8%. S1c loaded the boundary and built the adapter. Neither gave it a
 * caller, so a run's recommendations were still produced by the lexical scorer and D-2 — "the AI
 * Layer makes no outbound model call" — was still true of the shipping product. That is D-20.
 *
 * These tests exercise the real pipeline rather than the service in isolation: an ingest run over
 * artifacts already on disk, through `POST /dbvc_cc/v2/runs`, which is the only path that proves
 * the seam is reachable. What they pin:
 *
 * - with the flag off the mapping artifact carries no interpretation trace at all and no result
 *   is written, so the artifact is the one V2 has always written;
 * - the seam runs with no network and no API key, via the injectable transport, which is the only
 *   way the fallback branches get exercised before they matter in production;
 * - a model answer supplies the ranking for the items it answered, and the candidate it produced
 *   carries model provenance;
 * - a response that fails validation falls back to the lexical scorer and NAMES the reason;
 * - an enabled flag with no key says `no_api_key`, not "disabled" — a configuration mistake an
 *   operator can act on;
 * - the persisted `interpretation-result.v1` carries a `model` key only when a model ran, which
 *   is the D-3 rule, enforced here on the artifact a reviewer actually opens.
 */
class ContentMigrationMappingInterpretationTest extends WP_UnitTestCase
{
    /**
     * @var string
     */
    private $dir = '';

    /**
     * @var string
     */
    private $domain = 'mapping-interpretation.test';

    /**
     * @var int
     */
    private $http_calls = 0;

    /**
     * @var int
     */
    private $transport_calls = 0;

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

        $this->dir = wp_normalize_path(get_temp_dir() . 'dbvc-cc-interp-run-' . wp_generate_password(8, false));
        wp_mkdir_p($this->dir);

        $dir = $this->dir;
        $this->permit = static function ($roots) use ($dir) {
            $roots[] = $dir;

            return $roots;
        };
        add_filter('dbvc_cc_v2_ingest_allowed_roots', $this->permit);

        // Interpretation must reach the model only through the injected transport. A real
        // outbound request from a test is a defect in the seam, not a slow test.
        $this->http_calls = 0;
        $this->transport_calls = 0;
        $this->http_guard = function () {
            $this->http_calls++;

            return new WP_Error('dbvc_test_no_http', 'The interpretation seam must not reach the network in tests.');
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
        if ($this->options_filter !== null) {
            remove_filter(
                DBVC_CC_V2_Mapping_Interpretation_Service::FILTER_INTERPRETATION_OPTIONS,
                $this->options_filter
            );
            $this->options_filter = null;
        }

        delete_option(DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION);
        $this->clear_api_key();
        $this->delete_domain_artifacts($this->domain);

        foreach (glob(trailingslashit($this->dir) . '*') ?: [] as $file) {
            @unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
        $this->reset_rest_server();

        parent::tear_down();
    }

    /**
     * The flag is the whole gate. With it off nothing about the mapping artifact changes, which
     * is what lets a seam this central land without a migration.
     */
    public function test_with_the_model_off_the_mapping_artifact_carries_no_interpretation_trace()
    {
        $this->write_bundle();
        $this->assertFalse(DBVC_CC_V2_Mapping_Interpretation_Service::get_instance()->is_enabled());

        $this->run_ingest();

        $mapping = $this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_INDEX_SUFFIX);
        $this->assertNotSame([], $mapping, 'No mapping index artifact was written.');
        $this->assertArrayNotHasKey(
            'interpretation',
            $mapping,
            'An interpretation block appeared with the flag off; the key must be added, never defaulted.'
        );

        foreach ($mapping['content_items'] as $content_item) {
            $this->assertArrayNotHasKey('interpretation', $content_item);
            foreach ($content_item['target_candidates'] as $candidate) {
                $this->assertArrayNotHasKey(
                    'provenance',
                    $candidate,
                    'A lexical candidate carried provenance, which is the D-3 defect reintroduced.'
                );
            }
        }

        $this->assertSame(
            [],
            $this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_INTERPRETATION_RESULT_SUFFIX),
            'An interpretation result was persisted for a run that never interpreted anything.'
        );
    }

    /**
     * Every branch of the model path has to be reachable without a key and without the network,
     * or the fallbacks are first exercised in production.
     */
    public function test_the_seam_interprets_with_no_network_and_no_api_key()
    {
        $this->write_bundle();
        $this->enable_model();
        $this->inject_transport($this->model_response([
            'mappings' => [
                [
                    'item_id' => 'home-e1',
                    'target_ref' => 'core:post_title',
                    'confidence' => 0.95,
                    'rationale' => 'The only h1 on the page.',
                ],
            ],
            'declined' => [],
        ]));

        $this->run_ingest();

        $this->assertSame(0, $this->http_calls, 'The seam made a real HTTP request.');
        $this->assertSame(1, $this->transport_calls, 'The seam should call the model once for one page.');
    }

    /**
     * The point of the slice: what a reviewer sees for an item the interpreter answered is the
     * interpreter's answer, carrying its own provenance.
     */
    public function test_a_model_ranking_supplies_the_candidates_for_the_items_it_answered()
    {
        $this->write_bundle();
        $this->enable_model();
        $this->inject_transport($this->model_response([
            'mappings' => [
                [
                    'item_id' => 'home-e2',
                    'target_ref' => 'core:post_content',
                    'confidence' => 0.94,
                    'rationale' => 'The only body paragraph in the hero band.',
                ],
            ],
            'declined' => [],
        ]));

        $this->run_ingest();

        $mapping = $this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_INDEX_SUFFIX);
        $item = $this->find_item_by_source_ref($mapping, 'elements.v2#home-e2');
        $this->assertNotNull($item, 'The body element produced no mapping item to rank.');

        $this->assertSame('model', $item['interpretation']['ranked_by']);
        $this->assertSame('interpreter_mapped', $item['interpretation']['reason']);

        $candidates = $item['target_candidates'];
        $this->assertCount(1, $candidates, 'The interpreter answered, so its answer is the candidate set.');
        $this->assertSame('core:post_content', $candidates[0]['target_ref']);
        $this->assertSame(0.94, (float) $candidates[0]['confidence']);
        $this->assertSame('model_interpretation', $candidates[0]['reason']);
        $this->assertSame(
            DBVC_CC_Interp_Contracts::INTERPRETER_MODEL,
            $candidates[0]['provenance']['interpreter']
        );
        $this->assertSame('gpt-5.6-terra', $candidates[0]['provenance']['model']);
        $this->assertSame(
            DBVC_CC_Interp_Prompt::PROMPT_VERSION,
            $candidates[0]['provenance']['prompt_version']
        );

        // An item the interpreter never answered keeps the lexical candidates it always had.
        $title = $this->find_item_by_source_ref($mapping, 'page-artifact.v1#metadata.title');
        $this->assertNotNull($title);
        $this->assertSame('lexical', $title['interpretation']['ranked_by']);
        $this->assertSame('interpreter_did_not_answer', $title['interpretation']['reason']);
        foreach ($title['target_candidates'] as $candidate) {
            $this->assertArrayNotHasKey('provenance', $candidate);
        }

        $this->assertTrue($mapping['interpretation']['ranking_applied']);
        $this->assertSame(1, (int) $mapping['interpretation']['items_ranked_by_interpreter']);
    }

    /**
     * A hallucinated field_ref invalidates the whole response. The run must still produce a
     * mapping, and must say which failure it was: V2's single `fallback_mode` flag could not
     * distinguish "no key" from "the model invented a field", and claimed a model ran either way.
     */
    public function test_a_model_response_that_fails_validation_falls_back_to_the_floor_and_says_why()
    {
        $this->write_bundle();
        $this->enable_model();
        $this->inject_transport($this->model_response([
            'mappings' => [
                [
                    'item_id' => 'home-e2',
                    'target_ref' => 'acf:a_group_that_does_not_exist:field',
                    'confidence' => 0.99,
                    'rationale' => 'Invented.',
                ],
            ],
            'declined' => [],
        ]));

        $this->run_ingest();

        $result = $this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_INTERPRETATION_RESULT_SUFFIX);
        $this->assertSame(DBVC_CC_Interp_Contracts::INTERPRETER_FLOOR, $result['interpreter']);
        $this->assertFalse((bool) $result['model_attempt']['used']);
        $this->assertTrue((bool) $result['model_attempt']['attempted']);
        $this->assertSame(DBVC_CC_Interp_Model::SKIP_INVALID_SHAPE, $result['model_attempt']['reason']);
        $this->assertStringContainsString(
            'invented target_ref',
            (string) $result['model_attempt']['detail'],
            'The fallback must name the specific failure, not just that one happened.'
        );

        // The lexical scorer in the mapping index keeps the ranking. The boundary's own floor is
        // a weaker scorer than this one, so a model failure must not demote the better floor.
        $mapping = $this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_INDEX_SUFFIX);
        $this->assertFalse((bool) $mapping['interpretation']['ranking_applied']);
        $this->assertSame(
            DBVC_CC_Interp_Contracts::INTERPRETER_FLOOR,
            $mapping['interpretation']['interpreter']
        );

        $item = $this->find_item_by_source_ref($mapping, 'elements.v2#home-e2');
        $this->assertNotNull($item);
        $this->assertArrayNotHasKey('interpretation', $item);
        foreach ($item['target_candidates'] as $candidate) {
            $this->assertArrayNotHasKey('provenance', $candidate);
        }
    }

    /**
     * An enabled flag with no key is a configuration mistake, and the run has to say so. Nothing
     * is sent: the model client refuses before it builds a request.
     */
    public function test_an_enabled_flag_with_no_key_names_the_missing_key()
    {
        $this->write_bundle();
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION, '1');
        $this->clear_api_key();

        if (DBVC_CC_Settings_Service::get_openai_api_key() !== ''
            || DBVC_CC_Interp_Model::resolve_api_key(DBVC_CC_Interp_Model::PROVIDER_ANTHROPIC) !== '') {
            $this->markTestSkipped('A provider key is configured in this environment.');
        }

        $this->run_ingest();

        $result = $this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_INTERPRETATION_RESULT_SUFFIX);
        $this->assertSame(DBVC_CC_Interp_Contracts::INTERPRETER_FLOOR, $result['interpreter']);
        $this->assertSame(DBVC_CC_Interp_Model::SKIP_NO_KEY, $result['model_attempt']['reason']);
        $this->assertSame(0, $this->http_calls, 'A keyless run must not reach the network.');
    }

    /**
     * Half of the D-3 rule, on the artifact a reviewer opens rather than only inside the
     * boundary: a result no model produced carries no model key anywhere, at any depth. Its
     * positive half is test_a_model_produced_result_names_the_model_that_ran.
     */
    public function test_a_floor_produced_result_carries_no_model_key_anywhere()
    {
        $this->write_bundle();
        $this->enable_model();
        $this->inject_transport(['status' => 503, 'body' => 'upstream unavailable']);

        $this->run_ingest();

        $floor = $this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_INTERPRETATION_RESULT_SUFFIX);
        $this->assertSame(DBVC_CC_Interp_Contracts::INTERPRETER_FLOOR, $floor['interpreter']);
        $this->assertSame(DBVC_CC_Interp_Model::SKIP_TRANSPORT_ERROR, $floor['model_attempt']['reason']);
        $this->assertSame(
            [],
            DBVC_CC_Interp_Contracts::validate_result($floor),
            'The floor result failed the provenance contract.'
        );
        $this->assertFalse(
            $this->has_key_anywhere($floor, 'model'),
            'A floor-produced result carried a model key.'
        );
        $this->assertFalse($this->has_key_anywhere($floor, 'prompt_version'));
    }

    /**
     * The other half of the D-3 rule. V2 stamped `model: "gpt-4o-mini"` onto every artifact
     * whether or not a model ran, so the key carried no information. Here it names the model
     * that actually answered, on the attempt record and on each recommendation it produced.
     */
    public function test_a_model_produced_result_names_the_model_that_ran()
    {
        $this->write_bundle();
        $this->enable_model();
        $this->inject_transport($this->model_response([
            'mappings' => [
                [
                    'item_id' => 'home-e1',
                    'target_ref' => 'core:post_title',
                    'confidence' => 0.96,
                    'rationale' => 'The page heading.',
                ],
            ],
            'declined' => [],
        ]));

        $this->run_ingest();

        $result = $this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_INTERPRETATION_RESULT_SUFFIX);
        $this->assertSame(DBVC_CC_Interp_Contracts::INTERPRETER_MODEL, $result['interpreter']);
        $this->assertTrue((bool) $result['model_attempt']['used']);
        $this->assertSame('gpt-5.6-terra', $result['model_attempt']['model']);
        $this->assertSame(DBVC_CC_Interp_Model::PROVIDER_OPENAI, $result['model_attempt']['provider']);
        $this->assertSame(DBVC_CC_Interp_Prompt::PROMPT_VERSION, $result['model_attempt']['prompt_version']);
        $this->assertSame([], DBVC_CC_Interp_Contracts::validate_result($result));

        $this->assertNotSame([], $result['recommendations']);
        foreach ($result['recommendations'] as $recommendation) {
            $this->assertSame(
                DBVC_CC_Interp_Contracts::INTERPRETER_MODEL,
                $recommendation['provenance']['interpreter']
            );
            $this->assertSame('gpt-5.6-terra', $recommendation['provenance']['model']);
        }
    }

    /**
     * Rule 2 of the prompt contract: decline rather than force. Overriding a decline with a
     * lexical guess is exactly the confident-wrong mapping the decline exists to prevent, so a
     * declined item becomes a typed unresolved item instead.
     */
    public function test_an_item_the_interpreter_declined_is_not_lexically_guessed()
    {
        $this->write_bundle();
        $this->enable_model();
        $this->inject_transport($this->model_response([
            'mappings' => [
                [
                    'item_id' => 'home-e1',
                    'target_ref' => 'core:post_title',
                    'confidence' => 0.97,
                    'rationale' => 'The page heading.',
                ],
            ],
            'declined' => [
                ['item_id' => 'home-e2', 'reason' => 'site_chrome'],
            ],
        ]));

        $this->run_ingest();

        $mapping = $this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_INDEX_SUFFIX);
        $item = $this->find_item_by_source_ref($mapping, 'elements.v2#home-e2');
        $this->assertNotNull($item);
        $this->assertSame([], $item['target_candidates']);
        $this->assertSame('interpreter_declined', $item['interpretation']['reason']);
        $this->assertContains('site_chrome', $item['interpretation']['decline_reasons']);

        $unresolved = null;
        foreach ($mapping['unresolved_items'] as $candidate) {
            if (in_array('elements.v2#home-e2', (array) $candidate['source_refs'], true)) {
                $unresolved = $candidate;
                break;
            }
        }

        $this->assertNotNull($unresolved, 'A declined item must still reach the exception queue.');
        $this->assertSame('interpretation_declined', $unresolved['reason']);
        $this->assertSame('declined_by_interpreter', $unresolved['unresolved_class']);
        $this->assertSame(1, (int) $mapping['interpretation']['items_declined_by_interpreter']);
    }

    /**
     * The artifact is the reviewable record, so it has to be where the other mapping artifacts
     * are and has to be pointed at from the mapping index's own trace.
     */
    public function test_the_interpretation_result_is_persisted_beside_the_mapping_artifacts()
    {
        $this->write_bundle();
        $this->enable_model();
        $this->inject_transport($this->model_response([
            'mappings' => [
                [
                    'item_id' => 'home-e1',
                    'target_ref' => 'core:post_title',
                    'confidence' => 0.96,
                    'rationale' => 'The page heading.',
                ],
            ],
            'declined' => [],
        ]));

        $this->run_ingest();

        $result = $this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_INTERPRETATION_RESULT_SUFFIX);
        $this->assertSame(DBVC_CC_Interp_Contracts::RESULT_TYPE, $result['artifact_type']);
        $this->assertSame(DBVC_CC_Interp_Contracts::INTERPRETER_MODEL, $result['interpreter']);
        $this->assertSame([], DBVC_CC_Interp_Contracts::validate_result($result));

        $mapping = $this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_MAPPING_INDEX_SUFFIX);
        $reference = (string) $mapping['interpretation']['artifact_ref'];
        $this->assertStringEndsWith(
            DBVC_CC_V2_Contracts::STORAGE_INTERPRETATION_RESULT_SUFFIX,
            $reference,
            'The mapping index must point at the result a reviewer should open.'
        );
        $this->assertContains($reference, $mapping['trace']['input_artifacts']);
    }

    /**
     * A filter must not become a second way to put a key into configuration. D-12 was a plaintext
     * key in wp_options; keys resolve from a constant, then the environment, then the option.
     */
    public function test_the_options_filter_cannot_supply_an_api_key()
    {
        $this->write_bundle();
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION, '1');
        $this->clear_api_key();

        if (DBVC_CC_Settings_Service::get_openai_api_key() !== ''
            || DBVC_CC_Interp_Model::resolve_api_key(DBVC_CC_Interp_Model::PROVIDER_ANTHROPIC) !== '') {
            $this->markTestSkipped('A provider key is configured in this environment.');
        }

        $this->options_filter = function ($options) {
            $options['api_key'] = 'a-key-smuggled-through-a-filter';
            $options['transport'] = function () {
                $this->transport_calls++;

                return ['status' => 200, 'body' => '{}'];
            };

            return $options;
        };
        add_filter(
            DBVC_CC_V2_Mapping_Interpretation_Service::FILTER_INTERPRETATION_OPTIONS,
            $this->options_filter
        );

        $this->run_ingest();

        $result = $this->read_stored_artifact(DBVC_CC_V2_Contracts::STORAGE_INTERPRETATION_RESULT_SUFFIX);
        $this->assertSame(DBVC_CC_Interp_Model::SKIP_NO_KEY, $result['model_attempt']['reason']);
        $this->assertSame(0, $this->transport_calls, 'The filtered key reached the model client.');
    }

    /**
     * @return void
     */
    private function enable_model()
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION, '1');

        $settings = DBVC_CC_Settings_Service::get_options();
        $settings['openai_api_key'] = 'test-key-not-a-real-one';
        $settings['openai_model'] = 'gpt-5.6-terra';
        update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $settings);
    }

    /**
     * @return void
     */
    private function clear_api_key()
    {
        $settings = DBVC_CC_Settings_Service::get_options();
        unset($settings['openai_api_key'], $settings['openai_model']);
        update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $settings);
    }

    /**
     * @param array<string, mixed> $response
     * @return void
     */
    private function inject_transport(array $response)
    {
        $this->options_filter = function ($options) use ($response) {
            $options['transport'] = function () use ($response) {
                $this->transport_calls++;

                return $response;
            };

            return $options;
        };

        add_filter(
            DBVC_CC_V2_Mapping_Interpretation_Service::FILTER_INTERPRETATION_OPTIONS,
            $this->options_filter
        );
    }

    /**
     * An OpenAI chat-completions envelope carrying the model's JSON answer.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function model_response(array $payload)
    {
        return [
            'status' => 200,
            'body' => (string) wp_json_encode([
                'choices' => [
                    ['message' => ['content' => (string) wp_json_encode($payload)]],
                ],
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $mapping
     * @param string               $source_ref
     * @return array<string, mixed>|null
     */
    private function find_item_by_source_ref(array $mapping, $source_ref)
    {
        foreach (isset($mapping['content_items']) ? $mapping['content_items'] : [] as $content_item) {
            if (! is_array($content_item)) {
                continue;
            }

            $source_refs = isset($content_item['source_refs']) && is_array($content_item['source_refs'])
                ? $content_item['source_refs']
                : [];
            if (in_array($source_ref, $source_refs, true)) {
                return $content_item;
            }
        }

        return null;
    }

    /**
     * @param mixed  $value
     * @param string $key
     * @return bool
     */
    private function has_key_anywhere($value, $key)
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $index => $child) {
            if ($index === $key) {
                return true;
            }
            if ($this->has_key_anywhere($child, $key)) {
                return true;
            }
        }

        return false;
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
     * One captured page with a hero section the segmenter can split into units, so the mapping
     * stage produces element-backed items the interpreter's answers can join onto.
     *
     * @return void
     */
    private function write_bundle()
    {
        $slug = 'home';
        $source_url = 'https://mapping-interpretation.test/';
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
            [
                'element_id' => 'home-e3',
                'tag' => 'a',
                'text' => 'Book an appointment',
                'link_target' => 'https://mapping-interpretation.test/contact',
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
                    'title' => 'Mircea Olteanu Dental Surgery',
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
                        'element_ids' => ['home-e1', 'home-e2', 'home-e3'],
                        'signals' => [
                            'heading_level' => 1,
                            'has_heading' => true,
                            'text_element_count' => 2,
                            'link_element_count' => 1,
                            'cta_keyword_hits' => 1,
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
                            'Book an appointment',
                        ],
                        'signals' => [
                            'heading_level' => 1,
                            'has_heading' => true,
                            'text_element_count' => 2,
                            'link_element_count' => 1,
                            'cta_keyword_hits' => 1,
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
