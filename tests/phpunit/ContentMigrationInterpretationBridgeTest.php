<?php

/**
 * The WordPress adapter for the interpretation boundary.
 *
 * S1b built and measured the interpreter — 100% field accuracy against the floor's 4.8% — and
 * then nothing called it. `grep -rln "DBVC_CC_Interp" .` returned only its own five files and the
 * corpus evaluator: no require in the add-on bootstrap, no caller in any mapping stage. So D-2,
 * "the AI Layer makes no outbound model call", was still true of the shipping product. That is
 * D-20, and this is the adapter that closes the loading half of it.
 *
 * What these tests pin:
 *
 * - the boundary is actually loaded by the plugin, which is the literal defect;
 * - the model is off by default, so enabling a paid per-page call stays deliberate;
 * - the live target field catalog adapts into corpus-target-schema.v1 with the same target_ref
 *   vocabulary V2's own mapping stage parses, because a model answer in a different vocabulary
 *   could never map back onto a real field;
 * - captured artifacts adapt into interpretation-input.v1 with kinds read from the elements
 *   artifact rather than guessed from markdown, and site chrome excluded;
 * - provenance stays honest in both directions — the D-3 rule, enforced against the adapter and
 *   not only inside the boundary.
 *
 * Every branch runs with no network and no key, via the injectable transport.
 */
class ContentMigrationInterpretationBridgeTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        DBVC_CC_V2_Contracts::ensure_defaults();
    }

    public function tear_down(): void
    {
        delete_option(DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION);

        parent::tear_down();
    }

    /**
     * The defect was literally that the plugin did not load these classes.
     */
    public function test_the_interpretation_boundary_is_loaded_by_the_plugin()
    {
        foreach ([
            'DBVC_CC_Interp_Contracts',
            'DBVC_CC_Interp_Prompt',
            'DBVC_CC_Interp_Floor',
            'DBVC_CC_Interp_Model',
            'DBVC_CC_Interp_Service',
            'DBVC_CC_V2_Interpretation_Bridge_Service',
        ] as $class) {
            $this->assertTrue(class_exists($class), $class . ' is not loaded by the add-on bootstrap.');
        }
    }

    /**
     * Enabling an outbound paid call per page must be an operator's decision, not a default.
     */
    public function test_the_model_is_disabled_by_default()
    {
        $this->assertSame(
            '0',
            DBVC_CC_Contracts::get_feature_flag_defaults()[DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION]
        );
        $this->assertFalse(DBVC_CC_V2_Interpretation_Bridge_Service::get_instance()->model_is_enabled());
        $this->assertFalse(DBVC_CC_V2_Interpretation_Bridge_Service::get_instance()->model_is_available());
    }

    /**
     * A flag with no key is not availability. It would attempt a call per page and fall back every
     * time: honest, but pointlessly slow.
     */
    public function test_the_flag_alone_does_not_make_the_model_available()
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION, '1');

        $bridge = DBVC_CC_V2_Interpretation_Bridge_Service::get_instance();
        $this->assertTrue($bridge->model_is_enabled());

        if (DBVC_CC_Settings_Service::get_openai_api_key() === ''
            && ! defined('DBVC_CC_ANTHROPIC_API_KEY')
            && getenv('DBVC_CC_ANTHROPIC_API_KEY') === false) {
            $this->assertFalse(
                $bridge->model_is_available(),
                'Enabled with no key anywhere must not report the model as available.'
            );
        } else {
            $this->assertTrue($bridge->model_is_available());
        }
    }

    public function test_the_live_catalog_adapts_into_a_valid_target_schema()
    {
        $schema = DBVC_CC_V2_Interpretation_Bridge_Service::get_instance()->build_target_schema(
            $this->catalog(),
            'page'
        );

        $this->assertSame([], DBVC_CC_Interp_Contracts::validate_schema($schema));
        $this->assertSame(DBVC_CC_Interp_Contracts::SCHEMA_TYPE, $schema['schema']);
        $this->assertCount(1, $schema['objects'], 'Only the routed object belongs in the prompt.');
        $this->assertSame('page', $schema['objects'][0]['object_key']);

        $refs = wp_list_pluck($schema['objects'][0]['fields'], 'field_ref');

        // The same vocabulary the finalizer's infer_target_family() parses. A model answer in any
        // other vocabulary could never map back onto a writable field.
        $this->assertContains('core:post_title', $refs);
        $this->assertContains('core:post_content', $refs);
        $this->assertContains('acf:page_hero:heading', $refs);
        $this->assertContains('meta:post:page:_custom_subtitle', $refs);

        // A group recorded against another object type is not offered.
        $this->assertNotContains('acf:team_member_details:role', $refs);
    }

    /**
     * Purpose text is the signal lexical overlap could not use, so it has to survive the adapter.
     */
    public function test_field_purpose_survives_the_adapter()
    {
        $schema = DBVC_CC_V2_Interpretation_Bridge_Service::get_instance()->build_target_schema(
            $this->catalog(),
            'page'
        );

        $hero = null;
        foreach ($schema['objects'][0]['fields'] as $field) {
            if ($field['field_ref'] === 'acf:page_hero:heading') {
                $hero = $field;
                break;
            }
        }

        $this->assertNotNull($hero);
        $this->assertSame('The large headline in the hero band at the top of the page.', $hero['purpose']);
        $this->assertSame('scalar_string', $hero['value_contract']['shape']);
    }

    public function test_captured_artifacts_adapt_into_a_valid_interpretation_input()
    {
        $input = DBVC_CC_V2_Interpretation_Bridge_Service::get_instance()->build_interpretation_input(
            $this->page_context()
        );

        $this->assertSame([], DBVC_CC_Interp_Contracts::validate_input($input));
        $this->assertSame(DBVC_CC_Interp_Contracts::INPUT_TYPE, $input['artifact_type']);

        $by_id = [];
        foreach ($input['items'] as $item) {
            $by_id[$item['item_id']] = $item;
        }

        // Kinds are read from the elements artifact, not guessed.
        $this->assertSame(DBVC_CC_Interp_Contracts::KIND_HEADING, $by_id['e1']['kind']);
        $this->assertSame(DBVC_CC_Interp_Contracts::KIND_BODY, $by_id['e2']['kind']);
        $this->assertSame(DBVC_CC_Interp_Contracts::KIND_CTA, $by_id['e3']['kind']);
        $this->assertSame(DBVC_CC_Interp_Contracts::KIND_IMAGE, $by_id['e5']['kind']);

        // Head metadata reaches the interpreter rather than being silently dropped: that is the
        // SEO-continuity gap the V3 guide lists under missing scope.
        $this->assertSame(DBVC_CC_Interp_Contracts::KIND_HEAD_META, $by_id['head:title']['kind']);
        $this->assertSame('A dental practice in Hamilton', $by_id['head:description']['text']);

        // Heading context is carried so a body item can be read under the heading it sits beneath.
        $this->assertSame(['h1' => 'Creating Healthy & Happy Smiles'], $by_id['e2']['heading_context']);
    }

    /**
     * A logo and a footer menu appear on every page, carry no page-specific meaning, and would
     * spend prompt budget competing with real content.
     */
    public function test_site_chrome_is_excluded_from_the_prompt_input()
    {
        $input = DBVC_CC_V2_Interpretation_Bridge_Service::get_instance()->build_interpretation_input(
            $this->page_context()
        );

        $texts = wp_list_pluck($input['items'], 'text');
        $this->assertNotContains('Home About Contact', $texts);
    }

    /**
     * With the model off the floor answers, and it labels itself honestly.
     *
     * Note what is NOT asserted: that the floor produces a recommendation for this page. On this
     * fixture it produces none, scoring 0.0 against every field — and that is the correct result,
     * not a defect. "Creating Healthy & Happy Smiles" shares no token with "Hero heading / the
     * large headline in the hero band", which is precisely the measurement in the V3 guide's
     * section 7.2: the floor locates the right item 94.1% of the time and assigns it to the right
     * field 5.3% of the time. An earlier draft of this test asserted the floor must return
     * something and failed for the right reason.
     *
     * What matters is that nothing is lost silently: an item the floor cannot score is declined
     * with a named reason, so a reviewer sees "we could not place this" rather than an absence.
     */
    public function test_with_the_model_off_the_floor_answers_and_claims_nothing()
    {
        $result = DBVC_CC_V2_Interpretation_Bridge_Service::get_instance()->interpret_page(
            $this->page_context(),
            $this->catalog(),
            'page'
        );

        $this->assertNotWPError($result);
        $this->assertSame([], DBVC_CC_Interp_Contracts::validate_result($result));
        $this->assertSame(DBVC_CC_Interp_Contracts::RESULT_TYPE, $result['artifact_type']);

        // Every input item is accounted for, as a recommendation or as a named decline.
        $accounted = count($result['recommendations']) + count($result['declined']);
        $input = DBVC_CC_V2_Interpretation_Bridge_Service::get_instance()->build_interpretation_input(
            $this->page_context()
        );
        $this->assertSame(
            count($input['items']),
            $accounted,
            'An item that is neither recommended nor declined has been lost silently.'
        );

        foreach ($result['declined'] as $declined) {
            $this->assertNotSame('', (string) $declined['reason']);
        }

        foreach ($result['recommendations'] as $recommendation) {
            $provenance = $recommendation['provenance'];
            $this->assertSame(DBVC_CC_Interp_Contracts::INTERPRETER_FLOOR, $provenance['interpreter']);
            $this->assertArrayNotHasKey('model', $provenance);
            $this->assertArrayNotHasKey('provider', $provenance);
            $this->assertArrayNotHasKey('prompt_version', $provenance);
        }
    }

    /**
     * Where lexical overlap does exist the floor places the item, and still claims nothing it did
     * not do. "Book an appointment" overlaps a field labelled "Appointment CTA label".
     */
    public function test_the_floor_places_an_item_when_lexical_overlap_exists()
    {
        $catalog = $this->catalog();
        $catalog['acf_catalog']['groups']['page_hero']['fields']['appointment_cta'] = [
            'name' => 'appointment_cta',
            'label' => 'Appointment CTA label',
            'type' => 'text',
            'resolved_purpose' => 'The label on the appointment booking button.',
            'value_contract' => ['shape' => 'scalar_string'],
        ];

        $result = DBVC_CC_V2_Interpretation_Bridge_Service::get_instance()->interpret_page(
            $this->page_context(),
            $catalog,
            'page'
        );

        $this->assertNotWPError($result);

        $placed = null;
        foreach ($result['recommendations'] as $recommendation) {
            if (($recommendation['item_id'] ?? '') === 'e3') {
                $placed = $recommendation;
                break;
            }
        }

        $this->assertNotNull($placed, 'The floor did not place an item it has clear overlap for.');
        $this->assertSame('acf:page_hero:appointment_cta', $placed['target_ref']);
        $this->assertSame(
            DBVC_CC_Interp_Contracts::INTERPRETER_FLOOR,
            $placed['provenance']['interpreter']
        );
        $this->assertSame(
            DBVC_CC_Interp_Contracts::BASIS_LEXICAL_OVERLAP,
            $placed['provenance']['confidence_basis']
        );
        $this->assertArrayNotHasKey('model', $placed['provenance']);
    }

    /**
     * The whole adapter end to end against a fake transport: no network, no key.
     */
    public function test_a_model_answer_reaches_the_result_with_model_provenance()
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MODEL_INTERPRETATION, '1');

        $response = [
            'mappings' => [
                [
                    'item_id' => 'e1',
                    'target_ref' => 'acf:page_hero:heading',
                    'confidence' => 0.96,
                    'rationale' => 'The largest heading on the page, in the hero band.',
                ],
            ],
            'declined' => [],
        ];

        $calls = 0;
        $transport = function ($url, $headers, $body) use (&$calls, $response) {
            $calls++;

            return [
                'status' => 200,
                'body' => (string) wp_json_encode([
                    'choices' => [
                        ['message' => ['content' => (string) wp_json_encode($response)]],
                    ],
                ]),
            ];
        };

        $result = DBVC_CC_V2_Interpretation_Bridge_Service::get_instance()->interpret_page(
            $this->page_context(),
            $this->catalog(),
            'page',
            [
                'transport' => $transport,
                'use_model' => true,
                'provider' => DBVC_CC_Interp_Model::PROVIDER_OPENAI,
                'api_key' => 'test-key-not-a-real-one',
                'model' => 'gpt-5.6-terra',
            ]
        );

        $this->assertNotWPError($result);
        $this->assertSame(1, $calls, 'The adapter should make exactly one model call for one page.');
        $this->assertSame([], DBVC_CC_Interp_Contracts::validate_result($result));

        $hero = null;
        foreach ($result['recommendations'] as $recommendation) {
            if (($recommendation['target_ref'] ?? '') === 'acf:page_hero:heading') {
                $hero = $recommendation;
                break;
            }
        }

        $this->assertNotNull($hero, 'The model mapping did not reach the result.');
        $this->assertSame(DBVC_CC_Interp_Contracts::INTERPRETER_MODEL, $hero['provenance']['interpreter']);
        $this->assertSame('gpt-5.6-terra', $hero['provenance']['model']);
        $this->assertSame(
            DBVC_CC_Interp_Prompt::PROMPT_VERSION,
            $hero['provenance']['prompt_version']
        );
        $this->assertSame(0.96, (float) $hero['confidence']);
    }

    /**
     * An unrouted page has no target object, so there is nothing to map onto.
     *
     * An earlier draft asserted that an empty catalog produces the error. It does not, and should
     * not: the adapter always offers the four core post fields, which is a legitimate minimal
     * schema for a page that genuinely has no custom fields. The real guard is the missing object
     * key, because reporting a result against the generic core four as if the page had been
     * interpreted against its real destination would be the misleading outcome.
     */
    public function test_an_unrouted_page_is_an_error_not_a_generic_prompt()
    {
        $result = DBVC_CC_V2_Interpretation_Bridge_Service::get_instance()->interpret_page(
            $this->page_context(),
            $this->catalog(),
            ''
        );

        $this->assertWPError($result);
        $this->assertSame('dbvc_cc_v2_interp_object_key_required', $result->get_error_code());
    }

    /**
     * A catalog with no custom fields still yields the core four, so a plain page is mappable.
     */
    public function test_a_catalog_with_no_custom_fields_still_offers_the_core_fields()
    {
        $catalog = $this->catalog();
        $catalog['acf_catalog']['groups'] = [];
        $catalog['meta_catalog'] = [];

        $schema = DBVC_CC_V2_Interpretation_Bridge_Service::get_instance()->build_target_schema(
            $catalog,
            'page'
        );

        $this->assertSame([], DBVC_CC_Interp_Contracts::validate_schema($schema));
        $this->assertSame(
            ['core:post_title', 'core:post_name', 'core:post_excerpt', 'core:post_content'],
            wp_list_pluck($schema['objects'][0]['fields'], 'field_ref')
        );
    }

    public function test_a_page_with_no_mappable_content_is_an_error()
    {
        $page_context = $this->page_context();
        $page_context['elements_artifact']['elements'] = [];
        $page_context['raw_artifact']['metadata'] = [];

        $result = DBVC_CC_V2_Interpretation_Bridge_Service::get_instance()->interpret_page(
            $page_context,
            $this->catalog(),
            'page'
        );

        $this->assertWPError($result);
        $this->assertSame('dbvc_cc_v2_interp_input_invalid', $result->get_error_code());
    }

    /**
     * A minimal target-field-catalog.v2, shaped like the live one.
     *
     * @return array<string, mixed>
     */
    private function catalog()
    {
        return [
            'artifact_type' => 'target-field-catalog.v2',
            'domain' => 'example.test',
            'catalog_fingerprint' => str_repeat('c', 64),
            'object_catalog' => [
                'page' => [
                    'type_family' => 'post_type',
                    'label' => 'Page',
                    'content_model' => 'landing_page',
                    'entity_role' => 'page',
                ],
            ],
            'acf_catalog' => [
                'groups' => [
                    'page_hero' => [
                        'object_keys' => ['page'],
                        'fields' => [
                            'heading' => [
                                'name' => 'heading',
                                'label' => 'Hero heading',
                                'type' => 'text',
                                'resolved_purpose' => 'The large headline in the hero band at the top of the page.',
                                'value_contract' => ['shape' => 'scalar_string'],
                            ],
                            'subheading' => [
                                'name' => 'subheading',
                                'label' => 'Hero subheading',
                                'type' => 'text',
                                'effective_purpose' => 'Supporting line under the hero headline.',
                                'value_contract' => ['shape' => 'scalar_string'],
                            ],
                        ],
                    ],
                    'team_member_details' => [
                        'object_keys' => ['team_member'],
                        'fields' => [
                            'role' => [
                                'name' => 'role',
                                'label' => 'Role',
                                'type' => 'text',
                                'value_contract' => ['shape' => 'scalar_string'],
                            ],
                        ],
                    ],
                ],
            ],
            'meta_catalog' => [
                'post' => [
                    'page' => [
                        '_custom_subtitle' => [
                            'label' => 'Custom subtitle',
                            'type' => 'text',
                            'purpose' => 'A secondary line shown under the page title.',
                            'value_contract' => ['shape' => 'scalar_string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * A page context shaped like the one the capture orchestrator assembles.
     *
     * @return array<string, mixed>
     */
    private function page_context()
    {
        return [
            'page_id' => 'page-home',
            'path' => '/',
            'source_url' => 'https://example.test/',
            'raw_artifact' => [
                'artifact_type' => 'page-artifact.v1',
                'slug' => 'home',
                'source_url' => 'https://example.test/',
                'normalized_url' => 'https://example.test/',
                'content_hash' => str_repeat('a', 64),
                'metadata' => [
                    'title' => 'Mircea Olteanu Dental Surgery',
                    'description' => 'A dental practice in Hamilton',
                ],
                'headings' => [['level' => 1, 'text' => 'Creating Healthy & Happy Smiles']],
            ],
            'elements_artifact' => [
                'artifact_type' => 'elements.v2',
                'element_count' => 6,
                'elements' => [
                    [
                        'element_id' => 'e1',
                        'tag' => 'h1',
                        'level' => 1,
                        'text' => 'Creating Healthy & Happy Smiles',
                        'is_site_chrome' => false,
                    ],
                    [
                        'element_id' => 'e2',
                        'tag' => 'p',
                        'text' => 'We have been caring for Hamilton families since 1998.',
                        'is_site_chrome' => false,
                    ],
                    [
                        'element_id' => 'e3',
                        'tag' => 'a',
                        'text' => 'Book an appointment',
                        'link_target' => 'https://example.test/contact',
                        'is_site_chrome' => false,
                    ],
                    [
                        'element_id' => 'e4',
                        'tag' => 'nav',
                        'text' => 'Home About Contact',
                        'is_site_chrome' => true,
                    ],
                    [
                        'element_id' => 'e5',
                        'tag' => 'img',
                        'text' => 'hero-smiling-patient.jpg A smiling patient',
                        'image_url' => 'https://example.test/hero.jpg',
                        'is_site_chrome' => false,
                    ],
                    [
                        'element_id' => 'e6',
                        'tag' => 'a',
                        'text' => 'This is a long sentence that happens to carry a link inside it rather than being a button.',
                        'link_target' => 'https://example.test/about',
                        'is_site_chrome' => false,
                    ],
                ],
            ],
        ];
    }
}
