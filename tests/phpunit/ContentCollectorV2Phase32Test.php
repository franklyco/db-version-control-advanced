<?php

if (! function_exists('acf_get_field_groups')) {
    function acf_get_field_groups()
    {
        return isset($GLOBALS['dbvc_test_acf_groups']) && is_array($GLOBALS['dbvc_test_acf_groups'])
            ? $GLOBALS['dbvc_test_acf_groups']
            : [];
    }
}

if (! function_exists('acf_get_fields')) {
    function acf_get_fields($group_key)
    {
        $group_key = sanitize_key((string) $group_key);
        return isset($GLOBALS['dbvc_test_acf_fields'][$group_key]) && is_array($GLOBALS['dbvc_test_acf_fields'][$group_key])
            ? $GLOBALS['dbvc_test_acf_fields'][$group_key]
            : [];
    }
}

if (! function_exists('vf_field_context_get_service_catalog_payload')) {
    function vf_field_context_get_service_catalog_payload($criteria = [], $profile = 'mapping')
    {
        unset($criteria, $profile);

        return isset($GLOBALS['dbvc_test_field_context_payload']) && is_array($GLOBALS['dbvc_test_field_context_payload'])
            ? $GLOBALS['dbvc_test_field_context_payload']
            : [];
    }
}

class ContentCollectorV2Phase32Test extends WP_UnitTestCase
{
    /**
     * @var array<int, string>
     */
    private $domains_to_cleanup = [];

    public function set_up(): void
    {
        parent::set_up();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        DBVC_CC_V2_Contracts::ensure_defaults();

        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V2);

        $this->seed_field_context_fixtures();
        $this->reset_singleton(DBVC_CC_Field_Context_Provider_Service::class);
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
    }

    public function tear_down(): void
    {
        foreach ($this->domains_to_cleanup as $domain) {
            $this->delete_domain_artifacts($domain);
        }

        unset($GLOBALS['dbvc_test_acf_groups'], $GLOBALS['dbvc_test_acf_fields'], $GLOBALS['dbvc_test_field_context_payload']);
        $this->reset_singleton(DBVC_CC_Field_Context_Provider_Service::class);

        update_option(DBVC_CC_V2_Contracts::OPTION_ADDON_ENABLED, '1');
        update_option(DBVC_CC_V2_Contracts::OPTION_RUNTIME_VERSION, DBVC_CC_V2_Contracts::RUNTIME_V1);

        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();

        parent::tear_down();
    }

    public function test_phase_thirty_two_v2_target_catalog_enriches_nested_acf_fields_with_field_context(): void
    {
        $domain = 'phase32-field-context.example';
        $this->domains_to_cleanup[] = $domain;

        $result = DBVC_CC_V2_Target_Field_Catalog_Service::get_instance()->build_catalog($domain, true);

        $this->assertNotWPError($result);
        $catalog = isset($result['catalog']) && is_array($result['catalog']) ? $result['catalog'] : [];
        $this->assertSame('fresh', (string) $catalog['field_context_provider']['status']);
        $this->assertSame('vf-schema-20260421', (string) $catalog['field_context_provider']['source_hash']);

        $groups = isset($catalog['acf_catalog']['groups']) && is_array($catalog['acf_catalog']['groups']) ? $catalog['acf_catalog']['groups'] : [];
        $this->assertArrayHasKey('group_core_fields', $groups);

        $group = $groups['group_core_fields'];
        $this->assertSame(['page'], array_values($group['object_context']['post_types'] ?? []));
        $this->assertSame('Core page fields for structural page sections and shared content.', (string) $group['field_context']['resolved_purpose']);

        $fields = isset($group['fields']) && is_array($group['fields']) ? $group['fields'] : [];
        $this->assertArrayHasKey('field_hero_section', $fields);
        $this->assertArrayHasKey('field_hero_h1', $fields);
        $this->assertArrayHasKey('field_hero_image', $fields);

        $hero_section = $fields['field_hero_section'];
        $this->assertSame('group', (string) $hero_section['type']);
        $this->assertSame('group', (string) $hero_section['container_type']);
        $this->assertSame('', (string) $hero_section['parent_type']);

        $hero_h1 = $fields['field_hero_h1'];
        $this->assertSame(['field_hero_section'], array_values($hero_h1['ancestor_field_keys'] ?? []));
        $this->assertSame(['hero_section'], array_values($hero_h1['ancestor_name_path'] ?? []));
        $this->assertSame(['Hero Section'], array_values($hero_h1['ancestor_label_path'] ?? []));
        $this->assertSame('Primary hero headline that states the page\'s core promise, offer, or outcome.', (string) $hero_h1['field_context']['resolved_purpose']);
        $this->assertTrue((bool) $hero_h1['field_context']['value_contract']['writable']);

        $hero_image = $fields['field_hero_image'];
        $this->assertSame(['hero_section', 'image_group'], array_values($hero_image['ancestor_name_path'] ?? []));
        $this->assertSame(['Hero Section', 'Image Group'], array_values($hero_image['ancestor_label_path'] ?? []));
        $this->assertSame('image', (string) $hero_image['type']);
        $this->assertSame('id', (string) $hero_image['return_format']);
        $this->assertSame('Hero media asset for the opening section.', (string) $hero_image['field_context']['resolved_purpose']);
    }

    public function test_phase_thirty_two_slot_graph_builds_context_chain_and_object_indexes(): void
    {
        $domain = 'phase32-slot-graph.example';
        $this->domains_to_cleanup[] = $domain;

        $sync = DBVC_CC_V2_Schema_Sync_Service::get_instance()->sync_domain(
            $domain,
            [
                'journey_id' => 'ccv2_phase32_slot_graph',
                'actor' => 'test',
                'trigger' => 'phpunit',
                'force_rebuild' => true,
            ]
        );

        $this->assertNotWPError($sync);
        $this->assertNotEmpty($sync['slot_graph']);

        $slot_graph = is_array($sync['slot_graph']) ? $sync['slot_graph'] : [];
        $this->assertSame('target-slot-graph.v1', (string) $slot_graph['artifact_type']);
        $this->assertSame('fresh', (string) $slot_graph['field_context_provider']['status']);
        $this->assertNotEmpty($slot_graph['slot_graph_fingerprint']);

        $slots = isset($slot_graph['slots']) && is_array($slot_graph['slots']) ? $slot_graph['slots'] : [];
        $this->assertArrayHasKey('acf:group_core_fields:field_hero_h1', $slots);
        $this->assertArrayHasKey('acf:group_core_fields:field_hero_image', $slots);
        $this->assertArrayNotHasKey('acf:group_core_fields:field_hero_section', $slots);

        $hero_h1 = $slots['acf:group_core_fields:field_hero_h1'];
        $this->assertSame(['hero_section'], array_values($hero_h1['branch_name_path'] ?? []));
        $this->assertSame('hero', (string) $hero_h1['section_family']);
        $this->assertSame('headline', (string) $hero_h1['slot_role']);
        $this->assertSame('group_core_fields:hero_section:headline', (string) $hero_h1['competition_group']);
        $this->assertCount(3, $hero_h1['context_chain']);
        $this->assertSame('Core', (string) $hero_h1['context_chain'][0]['label']);
        $this->assertSame('Hero Section', (string) $hero_h1['context_chain'][1]['label']);
        $this->assertSame('Hero H1', (string) $hero_h1['context_chain'][2]['label']);
        $this->assertSame(['page'], array_values($hero_h1['object_context']['post_types'] ?? []));

        $hero_image = $slots['acf:group_core_fields:field_hero_image'];
        $this->assertSame(['hero_section', 'image_group'], array_values($hero_image['branch_name_path'] ?? []));
        $this->assertSame('image', (string) $hero_image['slot_role']);

        $indexes = isset($slot_graph['indexes']) && is_array($slot_graph['indexes']) ? $slot_graph['indexes'] : [];
        $this->assertContains('acf:group_core_fields:field_hero_h1', $indexes['slots_by_object_type']['page']);
        $this->assertContains('acf:group_core_fields:field_hero_h1', $indexes['slots_by_section_family']['hero']);
        $this->assertContains('acf:group_core_fields:field_hero_h1', $indexes['slots_by_slot_role']['headline']);
        $this->assertContains('acf:group_core_fields:field_hero_h1', $indexes['slots_by_competition_group']['group_core_fields:hero_section:headline']);
        $this->assertSame('acf:group_core_fields:field_hero_h1', $indexes['slots_by_group_and_acf_key']['group_core_fields']['field_hero_h1']);

        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        $this->assertNotWPError($context);
        $this->assertFileExists((string) $context['target_slot_graph_file']);
    }

    public function test_phase_thirty_two_runtime_acf_fallback_keeps_missing_provider_status_and_populates_slot_context(): void
    {
        $domain = 'phase32-runtime-fallback.example';
        $this->domains_to_cleanup[] = $domain;

        $GLOBALS['dbvc_test_field_context_payload'] = [
            'provider' => [
                'name' => 'vertical-field-context',
                'contract_version' => '1.0.0',
                'schema_version' => '2026-04-21',
                'site_fingerprint' => 'vf-site-runtime-fallback',
            ],
            'catalog_meta' => [
                'status' => 'missing',
                'resolver_status' => 'empty',
                'source_hash' => 'vf-schema-runtime-missing',
            ],
            'data' => [],
        ];
        $GLOBALS['dbvc_test_acf_fields']['group_core_fields'][0]['sub_fields'][0]['type'] = 'wysiwyg';

        $catalog_result = DBVC_CC_V2_Target_Field_Catalog_Service::get_instance()->build_catalog($domain, true);
        $this->assertNotWPError($catalog_result);

        $catalog = isset($catalog_result['catalog']) && is_array($catalog_result['catalog']) ? $catalog_result['catalog'] : [];
        $this->assertSame('missing', (string) $catalog['field_context_provider']['status']);
        $this->assertSame('vf-schema-runtime-missing', (string) $catalog['field_context_provider']['source_hash']);
        $this->assertSame('2026-04-21', (string) $catalog['field_context_provider']['schema_version']);
        $this->assertSame('vf-site-runtime-fallback', (string) $catalog['field_context_provider']['site_fingerprint']);

        $groups = isset($catalog['acf_catalog']['groups']) && is_array($catalog['acf_catalog']['groups']) ? $catalog['acf_catalog']['groups'] : [];
        $this->assertArrayHasKey('group_core_fields', $groups);

        $group = $groups['group_core_fields'];
        $this->assertSame('missing', (string) $group['field_context']['status']);
        $this->assertSame('Core page fields for structural page sections and shared content.', (string) $group['field_context']['resolved_purpose']);
        $this->assertSame('acf_runtime_field_context', (string) $group['field_context']['resolved_from']);
        $this->assertContains('runtime_field_context_fallback', $group['field_context']['warnings']);

        $fields = isset($group['fields']) && is_array($group['fields']) ? $group['fields'] : [];
        $hero_h1 = isset($fields['field_hero_h1']) && is_array($fields['field_hero_h1']) ? $fields['field_hero_h1'] : [];
        $this->assertSame('wysiwyg', (string) $hero_h1['type']);
        $this->assertSame('missing', (string) $hero_h1['field_context']['status']);
        $this->assertSame('Primary hero headline that states the page\'s core promise, offer, or outcome.', (string) $hero_h1['field_context']['resolved_purpose']);
        $this->assertSame('acf_runtime_field_context', (string) $hero_h1['field_context']['resolved_from']);
        $this->assertSame('acf_runtime_field_context', (string) $hero_h1['field_context']['matched_by']);
        $this->assertContains('runtime_field_context_fallback', $hero_h1['field_context']['warnings']);

        $slot_graph_result = DBVC_CC_V2_Target_Slot_Graph_Service::get_instance()->build_graph($domain, true);
        $this->assertNotWPError($slot_graph_result);

        $slot_graph = isset($slot_graph_result['slot_graph']) && is_array($slot_graph_result['slot_graph']) ? $slot_graph_result['slot_graph'] : [];
        $this->assertSame('missing', (string) $slot_graph['field_context_provider']['status']);

        $slots = isset($slot_graph['slots']) && is_array($slot_graph['slots']) ? $slot_graph['slots'] : [];
        $this->assertArrayHasKey('acf:group_core_fields:field_hero_h1', $slots);

        $hero_h1_slot = $slots['acf:group_core_fields:field_hero_h1'];
        $this->assertSame('headline', (string) $hero_h1_slot['slot_role']);
        $this->assertStringContainsString('core promise', (string) $hero_h1_slot['chain_purpose_text']);
        $this->assertSame('Primary hero headline that states the page\'s core promise, offer, or outcome.', (string) $hero_h1_slot['context_chain'][2]['purpose']);
        $this->assertSame('missing', (string) $hero_h1_slot['provider_trace']['status']);
        $this->assertContains('runtime_field_context_fallback', $hero_h1_slot['provider_trace']['warnings']);
    }

    public function test_phase_thirty_two_chain_builder_classifies_acf_link_fields_as_link_targets(): void
    {
        $slot = DBVC_CC_Field_Context_Chain_Builder::get_instance()->build_slot_projection(
            [
                'key' => 'group_core_fields',
                'group_name' => 'core_group',
                'title' => 'Core',
                'location' => [
                    [
                        [
                            'param' => 'post_type',
                            'operator' => '==',
                            'value' => 'page',
                        ],
                    ],
                ],
                'field_context' => [
                    'resolved_purpose' => 'Core page fields.',
                    'object_context' => [
                        'post_types' => ['page'],
                        'taxonomies' => [],
                        'options_pages' => [],
                        'unknown_rules' => [],
                    ],
                ],
            ],
            [
                'key' => 'field_hero_custom_link',
                'name' => 'custom_link',
                'label' => 'Custom Link',
                'type' => 'link',
                'ancestor_field_keys' => ['field_hero_section'],
                'ancestor_name_path' => ['hero_section'],
                'ancestor_label_path' => ['Hero Section'],
                'field_context' => [
                    'resolved_purpose' => 'Destination link for a compact hero popup CTA.',
                    'value_contract' => [
                        'field_type' => 'link',
                        'value_shape' => 'link_object',
                        'writable' => true,
                    ],
                    'clone_context' => [
                        'is_clone_projected' => false,
                        'is_directly_writable' => true,
                    ],
                    'object_context' => [
                        'post_types' => ['page'],
                        'taxonomies' => [],
                        'options_pages' => [],
                        'unknown_rules' => [],
                    ],
                ],
            ],
            [
                'field_hero_section' => [
                    'key' => 'field_hero_section',
                    'name' => 'hero_section',
                    'label' => 'Hero Section',
                    'type' => 'group',
                    'field_context' => [
                        'resolved_purpose' => 'Top-level hero section wrapper.',
                    ],
                ],
            ]
        );

        $this->assertSame('link', (string) $slot['slot_role']);
    }

    public function test_phase_thirty_two_chain_builder_classifies_process_steps_as_process_headline_and_body_slots(): void
    {
        $group = [
            'key' => 'group_core_fields',
            'group_name' => 'core_group',
            'title' => 'Core',
            'location' => [
                [
                    [
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'page',
                    ],
                ],
            ],
            'field_context' => [
                'resolved_purpose' => 'Core page fields.',
                'object_context' => [
                    'post_types' => ['page'],
                    'taxonomies' => [],
                    'options_pages' => [],
                    'unknown_rules' => [],
                ],
            ],
        ];
        $fields_by_key = [
            'field_process_section' => [
                'key' => 'field_process_section',
                'name' => 'process_section',
                'label' => 'Process',
                'type' => 'group',
                'field_context' => [
                    'resolved_purpose' => 'Container for the page process section.',
                ],
            ],
            'field_process_steps' => [
                'key' => 'field_process_steps',
                'name' => 'process_steps',
                'label' => 'Process Steps',
                'type' => 'repeater',
                'field_context' => [
                    'resolved_purpose' => 'Collection of numbered process steps.',
                ],
            ],
        ];

        $step_name_slot = DBVC_CC_Field_Context_Chain_Builder::get_instance()->build_slot_projection(
            $group,
            [
                'key' => 'field_step_name',
                'name' => 'step_name',
                'label' => 'Step Name',
                'type' => 'text',
                'ancestor_field_keys' => ['field_process_section', 'field_process_steps'],
                'ancestor_name_path' => ['process_section', 'process_steps'],
                'ancestor_label_path' => ['Process', 'Process Steps'],
                'field_context' => [
                    'resolved_purpose' => 'Short title for each process step.',
                    'value_contract' => [
                        'field_type' => 'text',
                        'writable' => true,
                    ],
                    'clone_context' => [
                        'is_clone_projected' => false,
                        'is_directly_writable' => true,
                    ],
                ],
            ],
            $fields_by_key
        );

        $step_description_slot = DBVC_CC_Field_Context_Chain_Builder::get_instance()->build_slot_projection(
            $group,
            [
                'key' => 'field_step_description',
                'name' => 'step_description',
                'label' => 'Step Description',
                'type' => 'textarea',
                'ancestor_field_keys' => ['field_process_section', 'field_process_steps'],
                'ancestor_name_path' => ['process_section', 'process_steps'],
                'ancestor_label_path' => ['Process', 'Process Steps'],
                'field_context' => [
                    'resolved_purpose' => 'Supporting description for each process step.',
                    'value_contract' => [
                        'field_type' => 'textarea',
                        'writable' => true,
                    ],
                    'clone_context' => [
                        'is_clone_projected' => false,
                        'is_directly_writable' => true,
                    ],
                ],
            ],
            $fields_by_key
        );

        $this->assertSame('process', (string) $step_name_slot['section_family']);
        $this->assertSame('headline', (string) $step_name_slot['slot_role']);
        $this->assertTrue((bool) $step_name_slot['is_repeatable']);
        $this->assertSame('process', (string) $step_description_slot['section_family']);
        $this->assertSame('rich_text', (string) $step_description_slot['slot_role']);
        $this->assertTrue((bool) $step_description_slot['is_repeatable']);
    }

    public function test_phase_thirty_two_mapping_index_uses_slot_graph_to_filter_ineligible_acf_targets(): void
    {
        $domain = 'phase32-mapping-index.example';
        $this->domains_to_cleanup[] = $domain;

        $catalog_result = DBVC_CC_V2_Target_Field_Catalog_Service::get_instance()->build_catalog($domain, true);
        $this->assertNotWPError($catalog_result);

        $slot_graph_result = DBVC_CC_V2_Target_Slot_Graph_Service::get_instance()->build_graph($domain, true);
        $this->assertNotWPError($slot_graph_result);

        $artifact = DBVC_CC_V2_Mapping_Index_Service::get_instance()->build_artifact(
            [
                'domain' => $domain,
                'page_id' => 'page_hero',
                'source_url' => 'https://phase32-mapping-index.example/',
                'raw_artifact' => [
                    'journey_id' => 'ccv2_phase32_mapping_index',
                    'page_id' => 'page_hero',
                    'source_url' => 'https://phase32-mapping-index.example/',
                    'metadata' => [],
                ],
                'ingestion_package_artifact' => [
                    'sections' => [
                        [
                            'section_id' => 'section_hero',
                            'label' => 'Hero Section',
                            'sample_text' => [
                                'Primary hero headline about the page offer.',
                                'Support copy for the opening section.',
                            ],
                        ],
                    ],
                ],
                'context_artifact' => [
                    'items' => [
                        [
                            'source_refs' => ['sections.v2#section_hero'],
                            'context_tag' => 'hero',
                            'rationale' => 'Hero section heading and intro copy.',
                        ],
                    ],
                ],
            ],
            [
                'primary_classification' => [
                    'object_key' => 'page',
                    'type_family' => 'post_type',
                    'label' => 'Page',
                ],
            ],
            [
                'catalog' => isset($catalog_result['catalog']) && is_array($catalog_result['catalog']) ? $catalog_result['catalog'] : [],
                'catalog_fingerprint' => isset($catalog_result['catalog_fingerprint']) ? (string) $catalog_result['catalog_fingerprint'] : '',
            ],
            [
                'domain' => $domain,
            ]
        );

        $this->assertContains((string) $artifact['trace']['slot_graph_status'], ['built', 'loaded', 'reused']);
        $this->assertNotEmpty($artifact['trace']['slot_graph_ref']);
        $this->assertNotEmpty($artifact['trace']['slot_graph_fingerprint']);

        $content_items = isset($artifact['content_items']) && is_array($artifact['content_items']) ? $artifact['content_items'] : [];
        $this->assertCount(1, $content_items);

        $hero_section = $content_items[0];
        $candidate_refs = array_map(
            static function ($candidate) {
                return is_array($candidate) && isset($candidate['target_ref']) ? (string) $candidate['target_ref'] : '';
            },
            isset($hero_section['target_candidates']) && is_array($hero_section['target_candidates']) ? $hero_section['target_candidates'] : []
        );

        $this->assertNotContains('acf:group_services_fields:field_service_hero_h1', $candidate_refs);
        $this->assertNotContains('acf:group_site_settings_fields:field_announcement_banner_text', $candidate_refs);
        $this->assertNotContains('acf:group_core_fields:field_hero_clone_headline', $candidate_refs);
        $this->assertNotContains('core:post_title', $candidate_refs);
        $this->assertContains(
            (string) $hero_section['target_candidates'][0]['target_ref'],
            [
                'core:post_content',
                'acf:group_core_fields:field_hero_h1',
                'acf:group_core_fields:field_hero_body',
                'acf:group_core_fields:field_hero_button_text',
                'acf:group_core_fields:field_hero_button_url',
                'acf:group_core_fields:field_hero_image',
            ]
        );
        $this->assertSame(count($candidate_refs), (int) ($hero_section['confidence_summary']['candidate_count'] ?? 0));
        $this->assertLessThanOrEqual(6, count($candidate_refs));
    }

    public function test_phase_thirty_two_mapping_index_records_typed_page_level_unresolved_class(): void
    {
        $domain = 'phase32-unresolved-page-level.example';
        $this->domains_to_cleanup[] = $domain;

        $catalog_result = DBVC_CC_V2_Target_Field_Catalog_Service::get_instance()->build_catalog($domain, true);
        $this->assertNotWPError($catalog_result);

        $slot_graph_result = DBVC_CC_V2_Target_Slot_Graph_Service::get_instance()->build_graph($domain, true);
        $this->assertNotWPError($slot_graph_result);

        $artifact = DBVC_CC_V2_Mapping_Index_Service::get_instance()->build_artifact(
            [
                'domain' => $domain,
                'page_id' => 'page_unresolved_title',
                'source_url' => 'https://phase32-unresolved-page-level.example/',
                'raw_artifact' => [
                    'journey_id' => 'ccv2_phase32_unresolved_page_level',
                    'page_id' => 'page_unresolved_title',
                    'source_url' => 'https://phase32-unresolved-page-level.example/',
                    'metadata' => [
                        'title' => 'Page title with no eligible object slot.',
                    ],
                ],
                'ingestion_package_artifact' => [
                    'sections' => [],
                ],
            ],
            [
                'primary_classification' => [
                    'object_key' => 'unknown_object',
                    'type_family' => 'post_type',
                    'label' => 'Unknown Object',
                ],
            ],
            [
                'catalog' => isset($catalog_result['catalog']) && is_array($catalog_result['catalog']) ? $catalog_result['catalog'] : [],
                'catalog_fingerprint' => isset($catalog_result['catalog_fingerprint']) ? (string) $catalog_result['catalog_fingerprint'] : '',
            ],
            [
                'domain' => $domain,
            ]
        );

        $unresolved_items = isset($artifact['unresolved_items']) && is_array($artifact['unresolved_items']) ? $artifact['unresolved_items'] : [];
        $this->assertCount(1, $unresolved_items);
        $this->assertSame('no_target_candidates', (string) $unresolved_items[0]['reason']);
        $this->assertSame('missing_page_level_slot', (string) $unresolved_items[0]['unresolved_class']);
        $this->assertSame(['no_target_candidates'], array_values($unresolved_items[0]['reason_codes'] ?? []));
    }

    public function test_phase_thirty_two_routing_artifact_persists_page_intent_and_section_scopes(): void
    {
        $artifact = DBVC_CC_V2_Routing_Artifact_Service::get_instance()->build_artifact(
            [
                'path' => '/',
                'raw_artifact' => [
                    'journey_id' => 'ccv2_phase32_routing_artifact',
                    'page_id' => 'page_home',
                    'source_url' => 'https://phase32-routing.example/',
                    'content_hash' => 'route-hash-123',
                    'metadata' => [
                        'title' => 'Home Title',
                    ],
                ],
            ],
            [
                'summary' => [
                    'dominant_context_tags' => ['hero', 'conversion'],
                ],
                'items' => [
                    [
                        'source_refs' => ['sections.v2#section_hero'],
                        'context_tag' => 'hero',
                        'authoring_intent' => 'summarize_page',
                        'audience_purpose' => 'inform',
                        'confidence' => 0.93,
                        'rationale' => 'Hero heading and supporting copy.',
                    ],
                    [
                        'source_refs' => ['sections.v2#section_cta'],
                        'context_tag' => 'cta',
                        'authoring_intent' => 'invite_conversion',
                        'audience_purpose' => 'convert',
                        'confidence' => 0.88,
                        'rationale' => 'CTA section with consultation language.',
                    ],
                ],
            ],
            [
                'primary_classification' => [
                    'object_key' => 'page',
                    'confidence' => 0.91,
                ],
                'review' => [
                    'status' => 'auto_accept_candidate',
                ],
            ],
            [
                'context_ref' => 'home.context-creation.v1.json',
                'classification_ref' => 'home.initial-classification.v1.json',
            ]
        );

        $this->assertSame('routing-artifact.v1', (string) $artifact['artifact_type']);
        $this->assertSame('page', (string) $artifact['primary_route']['object_key']);
        $this->assertSame('homepage', (string) $artifact['primary_route']['page_intent']);
        $this->assertSame('page:front_page', (string) $artifact['primary_route']['object_identity']);
        $this->assertSame(['hero', 'conversion'], array_values($artifact['summary']['dominant_section_scopes'] ?? []));
        $this->assertSame('hero', (string) $artifact['section_routes'][0]['section_scope']);
        $this->assertSame('conversion', (string) $artifact['section_routes'][1]['section_scope']);
        $this->assertSame('auto_accept_candidate', (string) $artifact['review']['status']);
    }

    public function test_phase_thirty_two_routing_artifact_normalizes_process_and_conversion_page_intent_from_slug(): void
    {
        $service = DBVC_CC_V2_Routing_Artifact_Service::get_instance();

        $process_artifact = $service->build_artifact(
            [
                'path' => '/our-process',
                'raw_artifact' => [
                    'journey_id' => 'ccv2_phase32_routing_process',
                    'page_id' => 'page_process',
                    'source_url' => 'https://phase32-routing.example/our-process',
                    'content_hash' => 'route-hash-process',
                    'metadata' => [
                        'title' => 'Our Process',
                    ],
                ],
            ],
            [
                'summary' => [
                    'dominant_context_tags' => ['about', 'services'],
                ],
                'items' => [],
            ],
            [
                'primary_classification' => [
                    'object_key' => 'page',
                    'confidence' => 0.88,
                ],
                'review' => [
                    'status' => 'auto_accept_candidate',
                ],
            ],
            []
        );

        $conversion_artifact = $service->build_artifact(
            [
                'path' => '/get-started',
                'raw_artifact' => [
                    'journey_id' => 'ccv2_phase32_routing_conversion',
                    'page_id' => 'page_conversion',
                    'source_url' => 'https://phase32-routing.example/get-started',
                    'content_hash' => 'route-hash-conversion',
                    'metadata' => [
                        'title' => 'Get Started',
                    ],
                ],
            ],
            [
                'summary' => [
                    'dominant_context_tags' => ['general'],
                ],
                'items' => [],
            ],
            [
                'primary_classification' => [
                    'object_key' => 'page',
                    'confidence' => 0.87,
                ],
                'review' => [
                    'status' => 'auto_accept_candidate',
                ],
            ],
            []
        );

        $this->assertSame('process', (string) $process_artifact['primary_route']['page_intent']);
        $this->assertSame('conversion', (string) $conversion_artifact['primary_route']['page_intent']);
    }

    public function test_phase_thirty_two_mapping_index_prefers_specific_page_intent_over_broad_route_scope(): void
    {
        $service = DBVC_CC_V2_Mapping_Index_Service::get_instance();
        $method = new ReflectionMethod(DBVC_CC_V2_Mapping_Index_Service::class, 'resolve_effective_section_context_tag');
        $method->setAccessible(true);

        $this->assertSame('process', (string) $method->invoke($service, 'services', 'services', 'process'));
        $this->assertSame('pricing', (string) $method->invoke($service, 'product', 'product', 'pricing'));
        $this->assertSame('conversion', (string) $method->invoke($service, 'general', 'about', 'conversion'));
        $this->assertSame('hero', (string) $method->invoke($service, 'hero', 'hero', 'homepage'));
    }

    public function test_phase_thirty_two_body_copy_scoring_prefers_description_over_label_slots(): void
    {
        $candidates = DBVC_CC_V2_Target_Eligibility_Service::get_instance()->collect_slot_candidates(
            ['hero'],
            0.82,
            [
                'slot_lookup' => [
                    'acf:group_core_fields:field_hero_label' => [
                        'target_ref' => 'acf:group_core_fields:field_hero_label',
                        'acf_name' => 'hero_label',
                        'acf_label' => 'Hero Label',
                        'section_family' => 'hero',
                        'slot_role' => 'body',
                        'branch_name_path' => ['hero_section'],
                        'branch_label_path' => ['Hero Section'],
                        'chain_purpose_text' => 'Short eyebrow label that frames the hero topic or category before the main headline.',
                        'object_context' => [
                            'post_types' => ['page'],
                            'taxonomies' => [],
                            'options_pages' => [],
                            'unknown_rules' => [],
                        ],
                        'provider_trace' => [
                            'status' => 'fresh',
                        ],
                        'clone_context' => [
                            'is_clone_projected' => false,
                            'is_directly_writable' => true,
                        ],
                        'writable' => true,
                    ],
                    'acf:group_core_fields:field_hero_description' => [
                        'target_ref' => 'acf:group_core_fields:field_hero_description',
                        'acf_name' => 'hero_description',
                        'acf_label' => 'Hero Description',
                        'section_family' => 'hero',
                        'type' => 'wysiwyg',
                        'slot_role' => 'rich_text',
                        'branch_name_path' => ['hero_section'],
                        'branch_label_path' => ['Hero Section'],
                        'chain_purpose_text' => 'Supporting hero description that clarifies the value proposition, relevance, or next expectation for the visitor.',
                        'object_context' => [
                            'post_types' => ['page'],
                            'taxonomies' => [],
                            'options_pages' => [],
                            'unknown_rules' => [],
                        ],
                        'provider_trace' => [
                            'status' => 'fresh',
                        ],
                        'clone_context' => [
                            'is_clone_projected' => false,
                            'is_directly_writable' => true,
                        ],
                        'writable' => true,
                    ],
                    'acf:group_core_fields:field_popup_custom_link' => [
                        'target_ref' => 'acf:group_core_fields:field_popup_custom_link',
                        'acf_name' => 'custom_link',
                        'acf_label' => 'Custom Link',
                        'section_family' => 'hero',
                        'type' => 'link',
                        'slot_role' => 'link',
                        'branch_name_path' => ['hero_section', 'compact_popup'],
                        'branch_label_path' => ['Hero Section', 'Compact Popup'],
                        'chain_purpose_text' => 'Destination link for a compact hero popup CTA.',
                        'object_context' => [
                            'post_types' => ['page'],
                            'taxonomies' => [],
                            'options_pages' => [],
                            'unknown_rules' => [],
                        ],
                        'provider_trace' => [
                            'status' => 'fresh',
                        ],
                        'clone_context' => [
                            'is_clone_projected' => false,
                            'is_directly_writable' => true,
                        ],
                        'writable' => true,
                    ],
                ],
                'slot_pattern_refs' => [
                    'hero' => [
                        'acf:group_core_fields:field_hero_label',
                        'acf:group_core_fields:field_hero_description',
                        'acf:group_core_fields:field_popup_custom_link',
                    ],
                ],
                'slot_indexes' => [
                    'slots_by_section_family' => [
                        'hero' => [
                            'acf:group_core_fields:field_hero_label',
                            'acf:group_core_fields:field_hero_description',
                            'acf:group_core_fields:field_popup_custom_link',
                        ],
                    ],
                ],
            ],
            [
                'object_key' => 'page',
                'item_type' => 'section_unit',
                'context_tag' => 'hero',
                'section_family' => 'hero',
                'content_role' => 'body',
                'source_preview' => 'Fully managed Websites, Patient Forms, Patient Reviews, SEO & more that you 100% own (never leased).',
            ]
        );

        $confidence_by_target = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || empty($candidate['target_ref'])) {
                continue;
            }

            $confidence_by_target[(string) $candidate['target_ref']] = (float) ($candidate['sort_score'] ?? ($candidate['confidence'] ?? 0.0));
        }

        $this->assertArrayHasKey('acf:group_core_fields:field_hero_label', $confidence_by_target);
        $this->assertArrayHasKey('acf:group_core_fields:field_hero_description', $confidence_by_target);
        $this->assertArrayNotHasKey('acf:group_core_fields:field_popup_custom_link', $confidence_by_target);
        $this->assertGreaterThan(
            $confidence_by_target['acf:group_core_fields:field_hero_label'],
            $confidence_by_target['acf:group_core_fields:field_hero_description']
        );
    }

    public function test_phase_thirty_two_assignment_uses_raw_margin_beyond_display_confidence_cap(): void
    {
        $domain = 'phase32-raw-margin.example';
        $this->domains_to_cleanup[] = $domain;

        $catalog_result = DBVC_CC_V2_Target_Field_Catalog_Service::get_instance()->build_catalog($domain, true);
        $this->assertNotWPError($catalog_result);

        $slot_graph_result = DBVC_CC_V2_Target_Slot_Graph_Service::get_instance()->build_graph($domain, true);
        $this->assertNotWPError($slot_graph_result);

        $assignment = DBVC_CC_V2_Mapping_Assignment_Service::get_instance()->assign_content_items(
            $domain,
            [
                'content_items' => [
                    [
                        'item_id' => 'map_hero_body',
                        'item_type' => 'section',
                        'source_refs' => ['sections.v2#hero_body'],
                        'candidate_group' => 'section_field',
                        'context_tag' => 'hero',
                        'value_preview' => 'Supporting hero body copy.',
                        'notes' => [],
                        'target_candidates' => [
                            [
                                'target_ref' => 'acf:group_core_fields:field_hero_body',
                                'confidence' => 0.99,
                                'sort_score' => 1.18,
                                'reason' => 'slot_graph_context_match',
                                'pattern_key' => 'hero',
                            ],
                            [
                                'target_ref' => 'acf:group_core_fields:field_hero_label',
                                'confidence' => 0.99,
                                'sort_score' => 1.08,
                                'reason' => 'slot_graph_context_match',
                                'pattern_key' => 'hero',
                            ],
                        ],
                    ],
                ],
                'unresolved_items' => [],
            ]
        );

        $selected_items = isset($assignment['selected_items']) && is_array($assignment['selected_items']) ? $assignment['selected_items'] : [];
        $this->assertCount(1, $selected_items);

        $selection = isset($selected_items[0]['selection']) && is_array($selected_items[0]['selection']) ? $selected_items[0]['selection'] : [];
        $selected_candidate = isset($selected_items[0]['selected_candidate']) && is_array($selected_items[0]['selected_candidate']) ? $selected_items[0]['selected_candidate'] : [];

        $this->assertSame('approve', (string) $selection['default_decision']);
        $this->assertSame('selected', (string) $selection['status']);
        $this->assertGreaterThan(0.05, (float) $selection['margin_to_next']);
        $this->assertSame('acf:group_core_fields:field_hero_body', (string) $selected_candidate['target_ref']);
    }

    public function test_phase_thirty_two_assignment_and_finalizer_apply_duplicate_avoidance_and_unresolved_bias(): void
    {
        $domain = 'phase32-assignment.example';
        $this->domains_to_cleanup[] = $domain;

        $catalog_result = DBVC_CC_V2_Target_Field_Catalog_Service::get_instance()->build_catalog($domain, true);
        $this->assertNotWPError($catalog_result);

        $slot_graph_result = DBVC_CC_V2_Target_Slot_Graph_Service::get_instance()->build_graph($domain, true);
        $this->assertNotWPError($slot_graph_result);

        $mapping_index_artifact = [
            'content_items' => [
                [
                    'item_id' => 'map_001',
                    'item_type' => 'section',
                    'source_refs' => ['sections.v2#hero_heading'],
                    'candidate_group' => 'section_field',
                    'context_tag' => 'hero',
                    'value_preview' => 'Primary hero headline.',
                    'notes' => [],
                    'target_candidates' => [
                        [
                            'target_ref' => 'acf:group_core_fields:field_hero_h1',
                            'confidence' => 0.91,
                            'reason' => 'slot_graph_context_match',
                            'pattern_key' => 'hero',
                        ],
                        [
                            'target_ref' => 'core:post_title',
                            'confidence' => 0.84,
                            'reason' => 'pattern_field_catalog_match',
                            'pattern_key' => 'title',
                        ],
                    ],
                ],
                [
                    'item_id' => 'map_002',
                    'item_type' => 'section',
                    'source_refs' => ['sections.v2#hero_body'],
                    'candidate_group' => 'section_field',
                    'context_tag' => 'hero',
                    'value_preview' => 'Supporting hero body copy.',
                    'notes' => [],
                    'target_candidates' => [
                        [
                            'target_ref' => 'acf:group_core_fields:field_hero_h1',
                            'confidence' => 0.88,
                            'reason' => 'slot_graph_context_match',
                            'pattern_key' => 'hero',
                        ],
                        [
                            'target_ref' => 'core:post_content',
                            'confidence' => 0.86,
                            'reason' => 'section_copy_to_post_content',
                            'pattern_key' => 'content',
                        ],
                    ],
                ],
                [
                    'item_id' => 'map_003',
                    'item_type' => 'section',
                    'source_refs' => ['sections.v2#hero_ambiguous'],
                    'candidate_group' => 'section_field',
                    'context_tag' => 'hero',
                    'value_preview' => 'Ambiguous hero support copy.',
                    'notes' => [],
                    'target_candidates' => [
                        [
                            'target_ref' => 'core:post_title',
                            'confidence' => 0.81,
                            'reason' => 'pattern_field_catalog_match',
                            'pattern_key' => 'title',
                        ],
                        [
                            'target_ref' => 'core:post_content',
                            'confidence' => 0.79,
                            'reason' => 'section_copy_to_post_content',
                            'pattern_key' => 'content',
                        ],
                    ],
                ],
            ],
            'unresolved_items' => [],
        ];

        $assignment = DBVC_CC_V2_Mapping_Assignment_Service::get_instance()->assign_content_items(
            $domain,
            $mapping_index_artifact
        );

        $this->assertCount(3, $assignment['selected_items']);
        $this->assertCount(1, $assignment['unresolved_items']);
        $this->assertSame('ambiguous_sibling_slots', (string) $assignment['unresolved_items'][0]['unresolved_class']);
        $this->assertContains('low_margin', array_values($assignment['unresolved_items'][0]['reason_codes'] ?? []));
        $this->assertSame(
            'core:post_content',
            (string) $assignment['selected_items'][1]['selected_candidate']['target_ref']
        );
        $this->assertSame(
            'unresolved',
            (string) $assignment['selected_items'][2]['selection']['default_decision']
        );

        $page_context = [
            'domain' => $domain,
            'page_id' => 'page_assignment',
            'source_url' => 'https://phase32-assignment.example/',
            'raw_artifact' => [
                'journey_id' => 'ccv2_phase32_assignment',
                'page_id' => 'page_assignment',
                'source_url' => 'https://phase32-assignment.example/',
                'metadata' => [],
            ],
        ];
        $classification_artifact = [
            'primary_classification' => [
                'object_key' => 'page',
                'type_family' => 'post_type',
                'label' => 'Page',
                'confidence' => 0.95,
            ],
            'review' => [
                'status' => 'ready',
            ],
        ];

        $target_transform = DBVC_CC_V2_Target_Transform_Service::get_instance()->build_artifact(
            $page_context,
            $classification_artifact,
            $mapping_index_artifact,
            [
                'media_items' => [],
            ]
        );

        $this->assertCount(3, $target_transform['transform_items']);
        $this->assertContains(
            'ambiguous_target_selection',
            $target_transform['transform_items'][2]['warnings']
        );

        $recommendations = DBVC_CC_V2_Recommendation_Finalizer_Service::get_instance()->build_artifact(
            $page_context,
            $classification_artifact,
            $mapping_index_artifact,
            $target_transform,
            [
                'media_items' => [],
            ]
        );

        $this->assertCount(3, $recommendations['recommendations']);
        $this->assertCount(1, $recommendations['unresolved_items']);
        $this->assertEmpty($recommendations['conflicts']);
        $this->assertSame(
            'core:post_content',
            (string) $recommendations['recommendations'][1]['target_ref']
        );
        $this->assertSame(
            'unresolved',
            (string) $recommendations['recommendations'][2]['default_decision']
        );
        $this->assertSame(
            'ambiguous',
            (string) $recommendations['recommendations'][2]['selection']['status']
        );
        $this->assertSame(
            'ambiguous_sibling_slots',
            (string) $recommendations['unresolved_items'][0]['unresolved_class']
        );
        $this->assertContains('unresolved_items', $recommendations['review']['reason_codes']);
        $this->assertContains('ambiguous_recommendations', $recommendations['review']['reason_codes']);
    }

    public function test_phase_thirty_two_url_qa_reports_field_context_provider_risk_and_reviewed_ambiguity(): void
    {
        $domain = 'phase32-package-qa.example';
        $this->domains_to_cleanup[] = $domain;

        $catalog_result = DBVC_CC_V2_Target_Field_Catalog_Service::get_instance()->build_catalog($domain, true);
        $this->assertNotWPError($catalog_result);

        $domain_context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        $this->assertNotWPError($domain_context);

        $fixture_dir = trailingslashit((string) $domain_context['domain_dir']) . 'qa-fixture';
        wp_mkdir_p($fixture_dir);

        $run_id = 'ccv2_phase32_package_qa';
        $row = [
            'page_id' => 'page_qa',
            'path' => '/services/',
            'source_url' => 'https://phase32-package-qa.example/services/',
        ];
        $artifact_paths = [
            'mapping_recommendations' => $fixture_dir . '/services.mapping-recommendations.v2.json',
            'mapping_decisions' => $fixture_dir . '/services.mapping-decisions.v2.json',
            'media_decisions' => $fixture_dir . '/services.media-decisions.v2.json',
            'target_transform' => $fixture_dir . '/services.target-transform.v1.json',
        ];
        $artifact_relatives = [
            'mapping_recommendations' => 'qa-fixture/services.mapping-recommendations.v2.json',
            'mapping_decisions' => 'qa-fixture/services.mapping-decisions.v2.json',
            'media_decisions' => 'qa-fixture/services.media-decisions.v2.json',
            'target_transform' => 'qa-fixture/services.target-transform.v1.json',
        ];

        $recommendations = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'mapping-recommendations.v2',
            'journey_id' => $run_id,
            'page_id' => 'page_qa',
            'source_url' => 'https://phase32-package-qa.example/services/',
            'field_context_provider' => [
                'status' => 'degraded',
                'provider' => 'vertical-field-context',
                'transport' => 'local',
                'contract_version' => '1.0.0',
                'source_hash' => 'vf-schema-20260421',
                'schema_version' => '2026-04-21',
                'warnings' => [
                    'Catalog still contains legacy-only entries.',
                ],
            ],
            'review' => [
                'status' => 'ready',
                'reason_codes' => [],
            ],
            'recommended_target_object' => [
                'target_family' => 'post_type',
                'target_object_key' => 'page',
                'label' => 'Page',
                'resolution_mode' => 'update_existing',
            ],
            'recommendations' => [
                [
                    'recommendation_id' => 'rec_001',
                    'target_ref' => 'acf:group_core_fields:field_hero_h1',
                    'target_family' => 'acf',
                    'target_field_key' => 'field_hero_h1',
                    'recommended_value_type' => 'scalar_string',
                    'confidence' => 0.83,
                    'source_refs' => ['sections.v2#hero_heading'],
                    'source_evidence' => 'Hero headline source copy.',
                    'target_evidence' => 'Hero headline target preview.',
                    'requires_review' => true,
                    'default_decision' => 'approve',
                    'selection' => [
                        'status' => 'ambiguous',
                        'default_decision' => 'approve',
                        'adjusted_confidence' => 0.83,
                        'raw_confidence' => 0.83,
                        'margin_to_next' => 0.03,
                        'candidate_count' => 2,
                        'selected_rank' => 1,
                        'reason_codes' => ['low_margin'],
                        'alternatives' => [],
                    ],
                ],
            ],
            'media_recommendations' => [],
            'unresolved_items' => [],
            'conflicts' => [],
        ];

        $fingerprint = DBVC_CC_V2_Package_Selection_Service::get_instance()->compute_fingerprint($recommendations);
        $mapping_decisions = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'mapping-decisions.v2',
            'journey_id' => $run_id,
            'page_id' => 'page_qa',
            'source_url' => 'https://phase32-package-qa.example/services/',
            'decision_status' => 'reviewed',
            'recommendation_fingerprint' => $fingerprint,
            'target_object_decision' => [
                'selected_target_family' => 'post_type',
                'selected_target_object_key' => 'page',
                'selected_resolution_mode' => 'update_existing',
            ],
            'approved' => [
                [
                    'recommendation_id' => 'rec_001',
                ],
            ],
            'rejected' => [],
            'unresolved' => [],
            'overrides' => [],
        ];
        $media_decisions = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'media-decisions.v2',
            'journey_id' => $run_id,
            'page_id' => 'page_qa',
            'source_url' => 'https://phase32-package-qa.example/services/',
            'decision_status' => 'reviewed',
            'approved' => [],
            'ignored' => [],
            'conflicts' => [],
            'overrides' => [],
        ];
        $target_transform = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'target-transform.v1',
            'journey_id' => $run_id,
            'page_id' => 'page_qa',
            'source_url' => 'https://phase32-package-qa.example/services/',
            'transform_items' => [
                [
                    'source_refs' => ['sections.v2#hero_heading'],
                    'target_ref' => 'acf:group_core_fields:field_hero_h1',
                    'preview_value' => 'Hero headline target preview.',
                    'warnings' => [],
                ],
            ],
            'resolution_preview' => [
                'mode' => 'update_existing',
                'reason' => 'slug_match',
            ],
        ];

        $this->write_fixture_json($artifact_paths['mapping_recommendations'], $recommendations);
        $this->write_fixture_json($artifact_paths['mapping_decisions'], $mapping_decisions);
        $this->write_fixture_json($artifact_paths['media_decisions'], $media_decisions);
        $this->write_fixture_json($artifact_paths['target_transform'], $target_transform);

        $report = DBVC_CC_V2_URL_QA_Report_Service::get_instance()->build_page_report(
            $run_id,
            $domain,
            $row,
            [
                'artifact_paths' => $artifact_paths,
                'artifact_relatives' => $artifact_relatives,
            ],
            [],
            'schema-20260421'
        );

        $this->assertSame(DBVC_CC_V2_Contracts::READINESS_STATUS_READY, (string) $report['readinessStatus']);
        $this->assertTrue((bool) $report['packageIncluded']);
        $this->assertEmpty($report['blockingIssues']);
        $warning_codes = array_values(
            array_map(
                static function ($item) {
                    return is_array($item) && isset($item['code']) ? (string) $item['code'] : '';
                },
                isset($report['warnings']) && is_array($report['warnings']) ? $report['warnings'] : []
            )
        );
        $this->assertContains('field_context_provider_degraded', $warning_codes);
        $this->assertContains('field_context_provider_warnings', $warning_codes);
        $this->assertContains('field_context_ambiguous_recommendations', $warning_codes);
        $this->assertSame('degraded', (string) $report['fieldContextProvider']['status']);
        $this->assertSame(1, (int) $report['fieldContextSelection']['ambiguous_reviewed_count']);
        $this->assertSame(1, (int) $report['stats']['fieldContextAmbiguousRecommendationCount']);
        $this->assertSame(
            'vf-schema-20260421',
            (string) $report['packagePreview']['trace']['field_context_provider']['source_hash']
        );
    }

    public function test_phase_thirty_two_target_transform_blocks_invalid_url_and_reference_shape_mismatches(): void
    {
        $domain = 'phase32-transform-contracts.example';
        $this->domains_to_cleanup[] = $domain;

        $catalog_result = DBVC_CC_V2_Target_Field_Catalog_Service::get_instance()->build_catalog($domain, true);
        $this->assertNotWPError($catalog_result);

        $slot_graph_result = DBVC_CC_V2_Target_Slot_Graph_Service::get_instance()->build_graph($domain, true);
        $this->assertNotWPError($slot_graph_result);

        $mapping_index_artifact = [
            'content_items' => [
                [
                    'item_id' => 'map_url_invalid',
                    'item_type' => 'section',
                    'source_refs' => ['sections.v2#hero_button_label'],
                    'candidate_group' => 'section_field',
                    'context_tag' => 'hero',
                    'value_preview' => 'Get Started',
                    'target_candidates' => [
                        [
                            'target_ref' => 'acf:group_core_fields:field_hero_button_url',
                            'confidence' => 0.92,
                            'reason' => 'slot_graph_context_match',
                            'pattern_key' => 'cta_url',
                        ],
                    ],
                ],
                [
                    'item_id' => 'map_image_invalid',
                    'item_type' => 'section',
                    'source_refs' => ['sections.v2#hero_body'],
                    'candidate_group' => 'section_field',
                    'context_tag' => 'hero',
                    'value_preview' => 'A paragraph of hero body copy that should not map to an image field.',
                    'target_candidates' => [
                        [
                            'target_ref' => 'acf:group_core_fields:field_hero_image',
                            'confidence' => 0.81,
                            'reason' => 'slot_graph_context_match',
                            'pattern_key' => 'image',
                        ],
                    ],
                ],
                [
                    'item_id' => 'map_headline_valid',
                    'item_type' => 'section',
                    'source_refs' => ['sections.v2#hero_heading'],
                    'candidate_group' => 'section_field',
                    'context_tag' => 'hero',
                    'value_preview' => 'Primary hero headline.',
                    'target_candidates' => [
                        [
                            'target_ref' => 'acf:group_core_fields:field_hero_h1',
                            'confidence' => 0.95,
                            'reason' => 'slot_graph_context_match',
                            'pattern_key' => 'hero',
                        ],
                    ],
                ],
            ],
            'unresolved_items' => [],
        ];

        $target_transform = DBVC_CC_V2_Target_Transform_Service::get_instance()->build_artifact(
            [
                'domain' => $domain,
                'page_id' => 'page_transform_contracts',
                'source_url' => 'https://phase32-transform-contracts.example/',
                'raw_artifact' => [
                    'journey_id' => 'ccv2_phase32_transform_contracts',
                    'page_id' => 'page_transform_contracts',
                    'source_url' => 'https://phase32-transform-contracts.example/',
                    'metadata' => [],
                ],
            ],
            [
                'primary_classification' => [
                    'object_key' => 'page',
                    'type_family' => 'post_type',
                    'label' => 'Page',
                    'confidence' => 0.95,
                ],
                'review' => [
                    'status' => 'ready',
                ],
            ],
            $mapping_index_artifact,
            [
                'media_items' => [],
            ]
        );

        $this->assertSame(1, (int) $target_transform['stats']['ready_count']);
        $this->assertSame(2, (int) $target_transform['stats']['blocked_count']);

        $transform_by_target = [];
        foreach ($target_transform['transform_items'] as $transform_item) {
            if (is_array($transform_item) && ! empty($transform_item['target_ref'])) {
                $transform_by_target[(string) $transform_item['target_ref']] = $transform_item;
            }
        }

        $button_url = $transform_by_target['acf:group_core_fields:field_hero_button_url'];
        $this->assertSame('blocked', (string) $button_url['transform_status']);
        $this->assertSame('url', (string) $button_url['output_shape']);
        $this->assertContains('value_contract_invalid_url', $button_url['warnings']);
        $this->assertSame('blocked', (string) $button_url['value_contract_validation']['status']);

        $hero_image = $transform_by_target['acf:group_core_fields:field_hero_image'];
        $this->assertSame('blocked', (string) $hero_image['transform_status']);
        $this->assertSame('attachment_reference', (string) $hero_image['output_shape']);
        $this->assertContains('value_contract_reference_mismatch', $hero_image['warnings']);
        $this->assertSame('image', (string) $hero_image['value_contract']['field_type']);

        $hero_h1 = $transform_by_target['acf:group_core_fields:field_hero_h1'];
        $this->assertSame('ready', (string) $hero_h1['transform_status']);
        $this->assertEmpty($hero_h1['warnings']);
    }

    public function test_phase_thirty_two_url_qa_blocks_field_value_contract_violations(): void
    {
        $domain = 'phase32-package-contract-blocks.example';
        $this->domains_to_cleanup[] = $domain;

        $catalog_result = DBVC_CC_V2_Target_Field_Catalog_Service::get_instance()->build_catalog($domain, true);
        $this->assertNotWPError($catalog_result);

        $domain_context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        $this->assertNotWPError($domain_context);

        $fixture_dir = trailingslashit((string) $domain_context['domain_dir']) . 'qa-contract-fixture';
        wp_mkdir_p($fixture_dir);

        $run_id = 'ccv2_phase32_package_contract_block';
        $row = [
            'page_id' => 'page_contract_block',
            'path' => '/',
            'source_url' => 'https://phase32-package-contract-blocks.example/',
        ];
        $artifact_paths = [
            'mapping_recommendations' => $fixture_dir . '/home.mapping-recommendations.v2.json',
            'mapping_decisions' => $fixture_dir . '/home.mapping-decisions.v2.json',
            'media_decisions' => $fixture_dir . '/home.media-decisions.v2.json',
            'target_transform' => $fixture_dir . '/home.target-transform.v1.json',
        ];
        $artifact_relatives = [
            'mapping_recommendations' => 'qa-contract-fixture/home.mapping-recommendations.v2.json',
            'mapping_decisions' => 'qa-contract-fixture/home.mapping-decisions.v2.json',
            'media_decisions' => 'qa-contract-fixture/home.media-decisions.v2.json',
            'target_transform' => 'qa-contract-fixture/home.target-transform.v1.json',
        ];

        $recommendations = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'mapping-recommendations.v2',
            'journey_id' => $run_id,
            'page_id' => 'page_contract_block',
            'source_url' => 'https://phase32-package-contract-blocks.example/',
            'field_context_provider' => [
                'status' => 'fresh',
                'provider' => 'vertical-field-context',
                'transport' => 'local',
                'contract_version' => '1.0.0',
                'source_hash' => 'vf-schema-20260421',
                'schema_version' => '2026-04-21',
                'warnings' => [],
            ],
            'review' => [
                'status' => 'ready',
                'reason_codes' => [],
            ],
            'recommended_target_object' => [
                'target_family' => 'post_type',
                'target_object_key' => 'page',
                'label' => 'Page',
                'resolution_mode' => 'update_existing',
            ],
            'recommendations' => [
                [
                    'recommendation_id' => 'rec_001',
                    'target_ref' => 'acf:group_core_fields:field_hero_button_url',
                    'target_family' => 'acf',
                    'target_field_key' => 'field_hero_button_url',
                    'recommended_value_type' => 'url',
                    'confidence' => 0.87,
                    'source_refs' => ['sections.v2#hero_button_label'],
                    'source_evidence' => 'Get Started',
                    'target_evidence' => 'Get Started',
                    'requires_review' => true,
                    'default_decision' => 'approve',
                ],
            ],
            'media_recommendations' => [],
            'unresolved_items' => [],
            'conflicts' => [],
        ];

        $fingerprint = DBVC_CC_V2_Package_Selection_Service::get_instance()->compute_fingerprint($recommendations);
        $mapping_decisions = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'mapping-decisions.v2',
            'journey_id' => $run_id,
            'page_id' => 'page_contract_block',
            'source_url' => 'https://phase32-package-contract-blocks.example/',
            'decision_status' => 'reviewed',
            'recommendation_fingerprint' => $fingerprint,
            'target_object_decision' => [
                'selected_target_family' => 'post_type',
                'selected_target_object_key' => 'page',
                'selected_resolution_mode' => 'update_existing',
            ],
            'approved' => [
                [
                    'recommendation_id' => 'rec_001',
                ],
            ],
            'rejected' => [],
            'unresolved' => [],
            'overrides' => [],
        ];
        $media_decisions = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'media-decisions.v2',
            'journey_id' => $run_id,
            'page_id' => 'page_contract_block',
            'source_url' => 'https://phase32-package-contract-blocks.example/',
            'decision_status' => 'reviewed',
            'approved' => [],
            'ignored' => [],
            'conflicts' => [],
            'overrides' => [],
        ];
        $target_transform = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'target-transform.v1',
            'journey_id' => $run_id,
            'page_id' => 'page_contract_block',
            'source_url' => 'https://phase32-package-contract-blocks.example/',
            'transform_items' => [
                [
                    'source_refs' => ['sections.v2#hero_button_label'],
                    'target_ref' => 'acf:group_core_fields:field_hero_button_url',
                    'preview_value' => 'Get Started',
                    'warnings' => ['value_contract_invalid_url'],
                    'transform_status' => 'blocked',
                    'value_contract_validation' => [
                        'status' => 'blocked',
                        'warning_codes' => [],
                        'blocking_issue_codes' => ['value_contract_invalid_url'],
                    ],
                ],
            ],
            'resolution_preview' => [
                'mode' => 'update_existing',
                'reason' => 'slug_match',
            ],
        ];

        $this->write_fixture_json($artifact_paths['mapping_recommendations'], $recommendations);
        $this->write_fixture_json($artifact_paths['mapping_decisions'], $mapping_decisions);
        $this->write_fixture_json($artifact_paths['media_decisions'], $media_decisions);
        $this->write_fixture_json($artifact_paths['target_transform'], $target_transform);

        $report = DBVC_CC_V2_URL_QA_Report_Service::get_instance()->build_page_report(
            $run_id,
            $domain,
            $row,
            [
                'artifact_paths' => $artifact_paths,
                'artifact_relatives' => $artifact_relatives,
            ],
            [],
            'schema-20260421'
        );

        $this->assertSame(DBVC_CC_V2_Contracts::READINESS_STATUS_BLOCKED, (string) $report['readinessStatus']);
        $this->assertFalse((bool) $report['packageIncluded']);

        $blocking_codes = array_values(
            array_map(
                static function ($item) {
                    return is_array($item) && isset($item['code']) ? (string) $item['code'] : '';
                },
                isset($report['blockingIssues']) && is_array($report['blockingIssues']) ? $report['blockingIssues'] : []
            )
        );
        $this->assertContains('field_value_contract_blocked', $blocking_codes);
        $this->assertSame(1, (int) $report['transformValidation']['blocked_count']);
        $this->assertSame(
            ['value_contract_invalid_url'],
            array_values($report['transformValidation']['blocked_issue_codes'])
        );
        $this->assertSame(1, (int) $report['stats']['transformBlockedCount']);
    }

    public function test_phase_thirty_two_url_qa_blocks_field_context_provider_drift(): void
    {
        $domain = 'phase32-provider-drift.example';
        $this->domains_to_cleanup[] = $domain;

        $catalog_result = DBVC_CC_V2_Target_Field_Catalog_Service::get_instance()->build_catalog($domain, true);
        $this->assertNotWPError($catalog_result);

        $slot_graph_result = DBVC_CC_V2_Target_Slot_Graph_Service::get_instance()->build_graph($domain, true);
        $this->assertNotWPError($slot_graph_result);

        $domain_context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        $this->assertNotWPError($domain_context);

        $fixture_dir = trailingslashit((string) $domain_context['domain_dir']) . 'qa-drift-fixture';
        wp_mkdir_p($fixture_dir);

        $run_id = 'ccv2_phase32_provider_drift';
        $row = [
            'page_id' => 'page_provider_drift',
            'path' => '/about/',
            'source_url' => 'https://phase32-provider-drift.example/about/',
        ];
        $artifact_paths = [
            'mapping_recommendations' => $fixture_dir . '/about.mapping-recommendations.v2.json',
            'mapping_decisions' => $fixture_dir . '/about.mapping-decisions.v2.json',
            'media_decisions' => $fixture_dir . '/about.media-decisions.v2.json',
            'target_transform' => $fixture_dir . '/about.target-transform.v1.json',
        ];
        $artifact_relatives = [
            'mapping_recommendations' => 'qa-drift-fixture/about.mapping-recommendations.v2.json',
            'mapping_decisions' => 'qa-drift-fixture/about.mapping-decisions.v2.json',
            'media_decisions' => 'qa-drift-fixture/about.media-decisions.v2.json',
            'target_transform' => 'qa-drift-fixture/about.target-transform.v1.json',
        ];

        $recommendations = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'mapping-recommendations.v2',
            'journey_id' => $run_id,
            'page_id' => 'page_provider_drift',
            'source_url' => 'https://phase32-provider-drift.example/about/',
            'field_context_provider' => [
                'status' => 'fresh',
                'provider' => 'vertical-field-context',
                'transport' => 'local',
                'contract_version' => '1.0.0',
                'source_hash' => 'vf-schema-stale',
                'schema_version' => '2026-04-20',
                'site_fingerprint' => 'vf-site-old',
                'warnings' => [],
            ],
            'review' => [
                'status' => 'ready',
                'reason_codes' => [],
            ],
            'recommended_target_object' => [
                'target_family' => 'post_type',
                'target_object_key' => 'page',
                'label' => 'Page',
                'resolution_mode' => 'update_existing',
            ],
            'recommendations' => [
                [
                    'recommendation_id' => 'rec_001',
                    'target_ref' => 'acf:group_core_fields:field_hero_h1',
                    'target_family' => 'acf',
                    'target_field_key' => 'field_hero_h1',
                    'recommended_value_type' => 'scalar_string',
                    'confidence' => 0.93,
                    'source_refs' => ['sections.v2#hero_heading'],
                    'source_evidence' => 'Primary hero headline.',
                    'target_evidence' => 'Primary hero headline.',
                    'requires_review' => false,
                    'default_decision' => 'approve',
                ],
            ],
            'media_recommendations' => [],
            'unresolved_items' => [],
            'conflicts' => [],
        ];

        $fingerprint = DBVC_CC_V2_Package_Selection_Service::get_instance()->compute_fingerprint($recommendations);
        $mapping_decisions = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'mapping-decisions.v2',
            'journey_id' => $run_id,
            'page_id' => 'page_provider_drift',
            'source_url' => 'https://phase32-provider-drift.example/about/',
            'decision_status' => 'reviewed',
            'recommendation_fingerprint' => $fingerprint,
            'target_object_decision' => [
                'selected_target_family' => 'post_type',
                'selected_target_object_key' => 'page',
                'selected_resolution_mode' => 'update_existing',
            ],
            'approved' => [
                [
                    'recommendation_id' => 'rec_001',
                ],
            ],
            'rejected' => [],
            'unresolved' => [],
            'overrides' => [],
        ];
        $media_decisions = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'media-decisions.v2',
            'journey_id' => $run_id,
            'page_id' => 'page_provider_drift',
            'source_url' => 'https://phase32-provider-drift.example/about/',
            'decision_status' => 'reviewed',
            'approved' => [],
            'ignored' => [],
            'conflicts' => [],
            'overrides' => [],
        ];
        $target_transform = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'target-transform.v1',
            'journey_id' => $run_id,
            'page_id' => 'page_provider_drift',
            'source_url' => 'https://phase32-provider-drift.example/about/',
            'transform_items' => [
                [
                    'source_refs' => ['sections.v2#hero_heading'],
                    'target_ref' => 'acf:group_core_fields:field_hero_h1',
                    'preview_value' => 'Primary hero headline.',
                    'warnings' => [],
                    'transform_status' => 'ready',
                    'value_contract_validation' => [
                        'status' => 'valid',
                        'warning_codes' => [],
                        'blocking_issue_codes' => [],
                    ],
                ],
            ],
            'resolution_preview' => [
                'mode' => 'update_existing',
                'reason' => 'slug_match',
            ],
        ];

        $this->write_fixture_json($artifact_paths['mapping_recommendations'], $recommendations);
        $this->write_fixture_json($artifact_paths['mapping_decisions'], $mapping_decisions);
        $this->write_fixture_json($artifact_paths['media_decisions'], $media_decisions);
        $this->write_fixture_json($artifact_paths['target_transform'], $target_transform);

        $report = DBVC_CC_V2_URL_QA_Report_Service::get_instance()->build_page_report(
            $run_id,
            $domain,
            $row,
            [
                'artifact_paths' => $artifact_paths,
                'artifact_relatives' => $artifact_relatives,
            ],
            [],
            'schema-20260421'
        );

        $this->assertSame(DBVC_CC_V2_Contracts::READINESS_STATUS_BLOCKED, (string) $report['readinessStatus']);

        $blocking_codes = array_values(
            array_map(
                static function ($item) {
                    return is_array($item) && isset($item['code']) ? (string) $item['code'] : '';
                },
                isset($report['blockingIssues']) && is_array($report['blockingIssues']) ? $report['blockingIssues'] : []
            )
        );
        $this->assertContains('field_context_provider_drift', $blocking_codes);
        $this->assertSame('drifted', (string) $report['fieldContextProviderDrift']['status']);
        $this->assertContains('source_hash', $report['fieldContextProviderDrift']['mismatch_fields']);
        $this->assertSame('vf-schema-20260421', (string) $report['fieldContextProviderCurrent']['source_hash']);
        $this->assertSame(1, (int) $report['stats']['fieldContextProviderDriftCount']);
    }

    public function test_phase_thirty_two_package_qa_builds_benchmark_rollup(): void
    {
        $page_reports = [
            [
                'pageId' => 'page_home',
                'path' => '/',
                'readinessStatus' => DBVC_CC_V2_Contracts::READINESS_STATUS_NEEDS_REVIEW,
                'qualityScore' => 38,
                'manualOverrideCount' => 0,
                'rerunCount' => 0,
                'stats' => [
                    'unresolvedCount' => 61,
                    'fieldContextAmbiguousRecommendationCount' => 32,
                    'fieldContextAmbiguousReviewedCount' => 0,
                    'transformBlockedCount' => 0,
                    'fieldContextProviderDriftCount' => 0,
                ],
            ],
            [
                'pageId' => 'page_pricing',
                'path' => '/pricing/',
                'readinessStatus' => DBVC_CC_V2_Contracts::READINESS_STATUS_BLOCKED,
                'qualityScore' => 22,
                'manualOverrideCount' => 2,
                'rerunCount' => 1,
                'stats' => [
                    'unresolvedCount' => 4,
                    'fieldContextAmbiguousRecommendationCount' => 2,
                    'fieldContextAmbiguousReviewedCount' => 2,
                    'transformBlockedCount' => 1,
                    'fieldContextProviderDriftCount' => 1,
                ],
            ],
            [
                'pageId' => 'page_about',
                'path' => '/about/',
                'readinessStatus' => DBVC_CC_V2_Contracts::READINESS_STATUS_READY,
                'qualityScore' => 92,
                'manualOverrideCount' => 0,
                'rerunCount' => 0,
                'stats' => [
                    'unresolvedCount' => 0,
                    'fieldContextAmbiguousRecommendationCount' => 0,
                    'fieldContextAmbiguousReviewedCount' => 0,
                    'transformBlockedCount' => 0,
                    'fieldContextProviderDriftCount' => 0,
                ],
            ],
        ];

        $benchmark_summary = DBVC_CC_V2_Package_QA_Service::get_instance()->build_benchmark_summary($page_reports);

        $this->assertSame(DBVC_CC_V2_Contracts::READINESS_STATUS_BLOCKED, (string) $benchmark_summary['status']);
        $this->assertSame(3, (int) $benchmark_summary['trackedPageCount']);
        $this->assertSame(2, (int) $benchmark_summary['highRiskPageCount']);
        $this->assertSame(2, (int) $benchmark_summary['pagesWithUnresolvedCount']);
        $this->assertSame(2, (int) $benchmark_summary['pagesWithAmbiguityCount']);
        $this->assertSame(1, (int) $benchmark_summary['pagesWithAmbiguousReviewedCount']);
        $this->assertSame(1, (int) $benchmark_summary['pagesWithManualOverrideCount']);
        $this->assertSame(1, (int) $benchmark_summary['pagesWithRerunCount']);
        $this->assertSame(1, (int) $benchmark_summary['pagesWithTransformBlocks']);
        $this->assertSame(1, (int) $benchmark_summary['pagesWithProviderDrift']);
        $this->assertSame(2, (int) $benchmark_summary['pagesWithBenchmarkBlocked']);
        $this->assertSame(0, (int) $benchmark_summary['pagesWithBenchmarkReview']);
        $this->assertSame(65, (int) $benchmark_summary['totals']['unresolvedCount']);
        $this->assertSame(34, (int) $benchmark_summary['totals']['ambiguousCount']);
        $this->assertSame(2, (int) $benchmark_summary['totals']['ambiguousReviewedCount']);
        $this->assertSame(2, (int) $benchmark_summary['totals']['manualOverrideCount']);
        $this->assertSame(1, (int) $benchmark_summary['totals']['rerunCount']);
        $this->assertSame(1, (int) $benchmark_summary['totals']['transformBlockedCount']);
        $this->assertSame(1, (int) $benchmark_summary['totals']['providerDriftCount']);
        $this->assertSame('/', (string) $benchmark_summary['pages'][0]['path']);
        $this->assertSame(DBVC_CC_V2_Contracts::READINESS_STATUS_BLOCKED, (string) $benchmark_summary['pages'][0]['benchmarkStatus']);

        $package_qa = DBVC_CC_V2_Package_Artifact_Service::get_instance()->build_package_qa_report(
            [
                'runId' => 'ccv2_phase32_benchmark_rollup',
                'domain' => 'phase32-benchmark-rollup.example',
            ],
            'pkg_ccv2_phase32_benchmark_rollup_001',
            [
                'readinessStatus' => DBVC_CC_V2_Contracts::READINESS_STATUS_NEEDS_REVIEW,
                'pageReports' => $page_reports,
                'blockingIssues' => [],
                'warnings' => [],
                'benchmarkSummary' => $benchmark_summary,
            ],
            []
        );

        $this->assertSame('package-qa-report.v1', (string) $package_qa['artifact_type']);
        $this->assertSame(DBVC_CC_V2_Contracts::READINESS_STATUS_BLOCKED, (string) $package_qa['benchmark_summary']['status']);
        $this->assertSame(2, (int) $package_qa['benchmark_summary']['highRiskPageCount']);

        $review_only_summary = DBVC_CC_V2_Package_QA_Service::get_instance()->build_benchmark_summary(
            [
                [
                    'pageId' => 'page_services',
                    'path' => '/services/',
                    'readinessStatus' => DBVC_CC_V2_Contracts::READINESS_STATUS_READY,
                    'qualityScore' => 92,
                    'manualOverrideCount' => 1,
                    'rerunCount' => 0,
                    'stats' => [
                        'unresolvedCount' => 0,
                        'fieldContextAmbiguousRecommendationCount' => 0,
                        'fieldContextAmbiguousReviewedCount' => 0,
                        'transformBlockedCount' => 0,
                        'fieldContextProviderDriftCount' => 0,
                    ],
                ],
            ]
        );
        $this->assertSame(DBVC_CC_V2_Contracts::READINESS_STATUS_NEEDS_REVIEW, (string) $review_only_summary['status']);
        $this->assertSame(1, (int) $review_only_summary['highRiskPageCount']);
    }

    public function test_phase_thirty_two_benchmark_gate_evaluates_low_confidence_review_signals(): void
    {
        $blocked = DBVC_CC_V2_Benchmark_Gate_Service::get_instance()->evaluate_page(
            [
                'quality_score' => 91,
                'ambiguous_reviewed_count' => 3,
                'manual_override_count' => 0,
                'rerun_count' => 0,
            ]
        );
        $this->assertSame(DBVC_CC_V2_Contracts::READINESS_STATUS_BLOCKED, (string) $blocked['status']);
        $this->assertContains('ambiguous_reviewed_threshold', $blocked['blocking_reason_codes']);

        $review = DBVC_CC_V2_Benchmark_Gate_Service::get_instance()->evaluate_page(
            [
                'quality_score' => 92,
                'ambiguous_reviewed_count' => 0,
                'manual_override_count' => 1,
                'rerun_count' => 0,
            ]
        );
        $this->assertSame(DBVC_CC_V2_Contracts::READINESS_STATUS_NEEDS_REVIEW, (string) $review['status']);
        $this->assertContains('manual_override_threshold', $review['warning_reason_codes']);

        $ready = DBVC_CC_V2_Benchmark_Gate_Service::get_instance()->evaluate_page(
            [
                'quality_score' => 94,
                'ambiguous_reviewed_count' => 0,
                'manual_override_count' => 0,
                'rerun_count' => 0,
            ]
        );
        $this->assertSame(DBVC_CC_V2_Contracts::READINESS_STATUS_READY, (string) $ready['status']);
    }

    public function test_phase_thirty_two_url_qa_adds_benchmark_warning_for_manual_overrides(): void
    {
        $domain = 'phase32-benchmark-warning.example';
        $this->domains_to_cleanup[] = $domain;

        $catalog_result = DBVC_CC_V2_Target_Field_Catalog_Service::get_instance()->build_catalog($domain, true);
        $this->assertNotWPError($catalog_result);

        $slot_graph_result = DBVC_CC_V2_Target_Slot_Graph_Service::get_instance()->build_graph($domain, true);
        $this->assertNotWPError($slot_graph_result);
        $slot_graph = isset($slot_graph_result['slot_graph']) && is_array($slot_graph_result['slot_graph'])
            ? $slot_graph_result['slot_graph']
            : [];
        $field_context_provider = isset($slot_graph['field_context_provider']) && is_array($slot_graph['field_context_provider'])
            ? $slot_graph['field_context_provider']
            : [];

        $domain_context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        $this->assertNotWPError($domain_context);

        $fixture_dir = trailingslashit((string) $domain_context['domain_dir']) . 'qa-benchmark-warning-fixture';
        wp_mkdir_p($fixture_dir);

        $run_id = 'ccv2_phase32_benchmark_warning';
        $row = [
            'page_id' => 'page_benchmark_warning',
            'path' => '/services/',
            'source_url' => 'https://phase32-benchmark-warning.example/services/',
        ];
        $artifact_paths = [
            'mapping_recommendations' => $fixture_dir . '/services.mapping-recommendations.v2.json',
            'mapping_decisions' => $fixture_dir . '/services.mapping-decisions.v2.json',
            'media_decisions' => $fixture_dir . '/services.media-decisions.v2.json',
            'target_transform' => $fixture_dir . '/services.target-transform.v1.json',
        ];
        $artifact_relatives = [
            'mapping_recommendations' => 'qa-benchmark-warning-fixture/services.mapping-recommendations.v2.json',
            'mapping_decisions' => 'qa-benchmark-warning-fixture/services.mapping-decisions.v2.json',
            'media_decisions' => 'qa-benchmark-warning-fixture/services.media-decisions.v2.json',
            'target_transform' => 'qa-benchmark-warning-fixture/services.target-transform.v1.json',
        ];

        $recommendations = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'mapping-recommendations.v2',
            'journey_id' => $run_id,
            'page_id' => 'page_benchmark_warning',
            'source_url' => 'https://phase32-benchmark-warning.example/services/',
            'field_context_provider' => [
                'status' => isset($field_context_provider['status']) ? (string) $field_context_provider['status'] : 'fresh',
                'provider' => isset($field_context_provider['provider']) ? (string) $field_context_provider['provider'] : 'vertical-field-context',
                'transport' => isset($field_context_provider['transport']) ? (string) $field_context_provider['transport'] : 'local',
                'contract_version' => isset($field_context_provider['contract_version']) ? (string) $field_context_provider['contract_version'] : '1.0.0',
                'source_hash' => isset($field_context_provider['source_hash']) ? (string) $field_context_provider['source_hash'] : 'vf-schema-20260421',
                'schema_version' => isset($field_context_provider['schema_version']) ? (string) $field_context_provider['schema_version'] : '2026-04-21',
                'site_fingerprint' => isset($field_context_provider['site_fingerprint']) ? (string) $field_context_provider['site_fingerprint'] : '',
                'warnings' => [],
            ],
            'review' => [
                'status' => 'ready',
                'reason_codes' => [],
            ],
            'recommended_target_object' => [
                'target_family' => 'post_type',
                'target_object_key' => 'page',
                'label' => 'Page',
                'resolution_mode' => 'update_existing',
            ],
            'recommendations' => [
                [
                    'recommendation_id' => 'rec_001',
                    'target_ref' => 'acf:group_core_fields:field_hero_h1',
                    'target_family' => 'acf',
                    'target_field_key' => 'field_hero_h1',
                    'recommended_value_type' => 'scalar_string',
                    'confidence' => 0.96,
                    'source_refs' => ['sections.v2#hero_heading'],
                    'source_evidence' => 'Primary hero headline.',
                    'target_evidence' => 'Primary hero headline.',
                    'requires_review' => false,
                    'default_decision' => 'approve',
                    'selection' => [
                        'status' => 'selected',
                    ],
                ],
            ],
            'media_recommendations' => [],
            'unresolved_items' => [],
            'conflicts' => [],
        ];

        $fingerprint = DBVC_CC_V2_Package_Selection_Service::get_instance()->compute_fingerprint($recommendations);
        $mapping_decisions = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'mapping-decisions.v2',
            'journey_id' => $run_id,
            'page_id' => 'page_benchmark_warning',
            'source_url' => 'https://phase32-benchmark-warning.example/services/',
            'decision_status' => 'overridden',
            'recommendation_fingerprint' => $fingerprint,
            'target_object_decision' => [
                'selected_target_family' => 'post_type',
                'selected_target_object_key' => 'page',
                'selected_resolution_mode' => 'update_existing',
            ],
            'approved' => [
                [
                    'recommendation_id' => 'rec_001',
                ],
            ],
            'rejected' => [],
            'unresolved' => [],
            'overrides' => [
                [
                    'recommendation_id' => 'rec_001',
                    'override_target' => 'acf:group_core_fields:field_hero_h1',
                    'reason' => 'operator-adjusted',
                ],
            ],
        ];
        $media_decisions = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'media-decisions.v2',
            'journey_id' => $run_id,
            'page_id' => 'page_benchmark_warning',
            'source_url' => 'https://phase32-benchmark-warning.example/services/',
            'decision_status' => 'reviewed',
            'approved' => [],
            'ignored' => [],
            'conflicts' => [],
            'overrides' => [],
        ];
        $target_transform = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'target-transform.v1',
            'journey_id' => $run_id,
            'page_id' => 'page_benchmark_warning',
            'source_url' => 'https://phase32-benchmark-warning.example/services/',
            'transform_items' => [
                [
                    'source_refs' => ['sections.v2#hero_heading'],
                    'target_ref' => 'acf:group_core_fields:field_hero_h1',
                    'preview_value' => 'Primary hero headline.',
                    'warnings' => [],
                    'transform_status' => 'ready',
                    'value_contract_validation' => [
                        'status' => 'valid',
                        'warning_codes' => [],
                        'blocking_issue_codes' => [],
                    ],
                ],
            ],
            'resolution_preview' => [
                'mode' => 'update_existing',
                'reason' => 'slug_match',
            ],
        ];

        $this->write_fixture_json($artifact_paths['mapping_recommendations'], $recommendations);
        $this->write_fixture_json($artifact_paths['mapping_decisions'], $mapping_decisions);
        $this->write_fixture_json($artifact_paths['media_decisions'], $media_decisions);
        $this->write_fixture_json($artifact_paths['target_transform'], $target_transform);

        $report = DBVC_CC_V2_URL_QA_Report_Service::get_instance()->build_page_report(
            $run_id,
            $domain,
            $row,
            [
                'artifact_paths' => $artifact_paths,
                'artifact_relatives' => $artifact_relatives,
            ],
            [],
            'schema-20260421'
        );

        $this->assertSame(DBVC_CC_V2_Contracts::READINESS_STATUS_READY, (string) $report['readinessStatus']);
        $this->assertSame(DBVC_CC_V2_Contracts::READINESS_STATUS_NEEDS_REVIEW, (string) $report['benchmarkGate']['status']);
        $this->assertSame(1, (int) $report['manualOverrideCount']);
        $this->assertSame(0, (int) $report['rerunCount']);
        $this->assertSame('needs_review', (string) $report['stats']['benchmarkGateStatus']);

        $warning_codes = array_values(
            array_map(
                static function ($item) {
                    return is_array($item) && isset($item['code']) ? (string) $item['code'] : '';
                },
                isset($report['warnings']) && is_array($report['warnings']) ? $report['warnings'] : []
            )
        );
        $this->assertContains('manual_overrides_present', $warning_codes);
        $this->assertContains('benchmark_release_gate_warning', $warning_codes);
    }

    public function test_phase_thirty_two_context_creation_detects_utility_navigation_and_h1_hero_sections(): void
    {
        $artifact = DBVC_CC_V2_Context_Creation_Service::get_instance()->build_artifact(
            [
                'raw_artifact' => [
                    'journey_id' => 'ccv2_phase32_context_signals',
                    'page_id' => 'page_context_signals',
                    'source_url' => 'https://phase32-context-signals.example/',
                    'metadata' => [
                        'title' => 'Phase 32 Context Signals',
                    ],
                ],
                'source_normalization_artifact' => [],
                'sections_artifact' => [
                    'section_count' => 2,
                    'sections' => [
                        [
                            'section_id' => 'section_utility',
                            'section_label_candidate' => 'Intro',
                            'element_ids' => ['el_skip_main', 'el_skip_footer', 'el_nav'],
                            'signals' => [
                                'heading_level' => 0,
                                'has_heading' => false,
                                'text_element_count' => 28,
                                'link_element_count' => 18,
                                'list_density' => 0.22,
                            ],
                        ],
                        [
                            'section_id' => 'section_hero',
                            'section_label_candidate' => 'All-in-one Dental Marketing that Dentists + Patients Love',
                            'element_ids' => ['el_hero_h1', 'el_hero_body', 'el_hero_cta'],
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
                'elements_artifact' => [
                    'elements' => [
                        [
                            'element_id' => 'el_skip_main',
                            'tag' => 'a',
                            'text' => 'Skip to main content',
                        ],
                        [
                            'element_id' => 'el_skip_footer',
                            'tag' => 'a',
                            'text' => 'Skip to footer',
                        ],
                        [
                            'element_id' => 'el_nav',
                            'tag' => 'a',
                            'text' => 'Start Here',
                        ],
                        [
                            'element_id' => 'el_hero_h1',
                            'tag' => 'h1',
                            'text' => 'All-in-one Dental Marketing that Dentists + Patients Love',
                        ],
                        [
                            'element_id' => 'el_hero_body',
                            'tag' => 'p',
                            'text' => 'Fully managed Websites, Patient Forms, Patient Reviews, SEO & more that you 100% own.',
                        ],
                        [
                            'element_id' => 'el_hero_cta',
                            'tag' => 'a',
                            'text' => 'Book a call with Rhett',
                        ],
                    ],
                ],
            ]
        );

        $items = isset($artifact['items']) && is_array($artifact['items']) ? $artifact['items'] : [];
        $this->assertCount(2, $items);
        $this->assertSame('utility', (string) $items[0]['context_tag']);
        $this->assertSame('hero', (string) $items[1]['context_tag']);
    }

    public function test_phase_thirty_two_mapping_index_splits_structured_sections_and_skips_utility_navigation(): void
    {
        $domain = 'phase32-section-units.example';
        $this->domains_to_cleanup[] = $domain;

        $catalog_result = DBVC_CC_V2_Target_Field_Catalog_Service::get_instance()->build_catalog($domain, true);
        $this->assertNotWPError($catalog_result);

        $slot_graph_result = DBVC_CC_V2_Target_Slot_Graph_Service::get_instance()->build_graph($domain, true);
        $this->assertNotWPError($slot_graph_result);

        $artifact = DBVC_CC_V2_Mapping_Index_Service::get_instance()->build_artifact(
            [
                'domain' => $domain,
                'page_id' => 'page_section_units',
                'source_url' => 'https://phase32-section-units.example/',
                'raw_artifact' => [
                    'journey_id' => 'ccv2_phase32_section_units',
                    'page_id' => 'page_section_units',
                    'source_url' => 'https://phase32-section-units.example/',
                    'metadata' => [],
                ],
                'ingestion_package_artifact' => [
                    'sections' => [
                        [
                            'section_id' => 'section_utility',
                            'order' => 1,
                            'label' => 'Intro',
                            'sample_text' => [
                                'Skip to main content',
                                'Skip to footer',
                                'Start Here',
                            ],
                            'signals' => [
                                'heading_level' => 0,
                                'has_heading' => false,
                                'text_element_count' => 28,
                                'link_element_count' => 18,
                                'list_density' => 0.22,
                            ],
                        ],
                        [
                            'section_id' => 'section_hero',
                            'order' => 2,
                            'label' => 'All-in-one Dental Marketing that Dentists + Patients Love',
                            'sample_text' => [
                                'All-in-one Dental Marketing that Dentists + Patients Love',
                                'Fully managed Websites, Patient Forms, Patient Reviews, SEO & more that you 100% own.',
                                'Book a call with Rhett',
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
                'sections_artifact' => [
                    'sections' => [
                        [
                            'section_id' => 'section_utility',
                            'order' => 1,
                            'section_label_candidate' => 'Intro',
                            'element_ids' => ['el_skip_main', 'el_skip_footer', 'el_nav'],
                            'signals' => [
                                'heading_level' => 0,
                                'has_heading' => false,
                                'text_element_count' => 28,
                                'link_element_count' => 18,
                                'list_density' => 0.22,
                            ],
                        ],
                        [
                            'section_id' => 'section_hero',
                            'order' => 2,
                            'section_label_candidate' => 'All-in-one Dental Marketing that Dentists + Patients Love',
                            'element_ids' => ['el_hero_h1', 'el_hero_body', 'el_hero_cta'],
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
                'elements_artifact' => [
                    'elements' => [
                        [
                            'element_id' => 'el_skip_main',
                            'tag' => 'a',
                            'text' => 'Skip to main content',
                            'link_target' => '#content',
                        ],
                        [
                            'element_id' => 'el_skip_footer',
                            'tag' => 'a',
                            'text' => 'Skip to footer',
                            'link_target' => '#footer',
                        ],
                        [
                            'element_id' => 'el_nav',
                            'tag' => 'a',
                            'text' => 'Start Here',
                            'link_target' => '/our-process/',
                        ],
                        [
                            'element_id' => 'el_hero_h1',
                            'tag' => 'h1',
                            'text' => 'All-in-one Dental Marketing that Dentists + Patients Love',
                        ],
                        [
                            'element_id' => 'el_hero_body',
                            'tag' => 'p',
                            'text' => 'Fully managed Websites, Patient Forms, Patient Reviews, SEO & more that you 100% own.',
                        ],
                        [
                            'element_id' => 'el_hero_cta',
                            'tag' => 'a',
                            'text' => 'Book a call with Rhett',
                            'link_target' => 'https://phase32-section-units.example/book-a-call/',
                        ],
                    ],
                ],
                'context_artifact' => [
                    'items' => [
                        [
                            'source_refs' => ['sections.v2#section_utility'],
                            'context_tag' => 'hero',
                            'rationale' => 'Legacy first-section heuristic.',
                        ],
                        [
                            'source_refs' => ['sections.v2#section_hero'],
                            'context_tag' => 'contact',
                            'rationale' => 'CTA language present.',
                        ],
                    ],
                ],
            ],
            [
                'primary_classification' => [
                    'object_key' => 'page',
                    'type_family' => 'post_type',
                    'label' => 'Page',
                ],
            ],
            [
                'catalog' => isset($catalog_result['catalog']) && is_array($catalog_result['catalog']) ? $catalog_result['catalog'] : [],
                'catalog_fingerprint' => isset($catalog_result['catalog_fingerprint']) ? (string) $catalog_result['catalog_fingerprint'] : '',
            ],
            [
                'domain' => $domain,
            ]
        );

        $content_items = isset($artifact['content_items']) && is_array($artifact['content_items']) ? $artifact['content_items'] : [];
        $this->assertCount(4, $content_items);
        $this->assertSame(1, (int) $artifact['stats']['skipped_section_count']);
        $this->assertSame(4, (int) $artifact['stats']['structured_section_item_count']);

        $items_by_role = [];
        foreach ($content_items as $content_item) {
            if (! is_array($content_item)) {
                continue;
            }

            $source_refs = isset($content_item['source_refs']) && is_array($content_item['source_refs']) ? $content_item['source_refs'] : [];
            $this->assertNotContains('sections.v2#section_utility', $source_refs);
            $items_by_role[(string) $content_item['content_role']] = $content_item;
            $this->assertSame('hero', (string) $content_item['context_tag']);
        }

        $this->assertArrayHasKey('headline', $items_by_role);
        $this->assertArrayHasKey('body', $items_by_role);
        $this->assertArrayHasKey('cta_label', $items_by_role);
        $this->assertArrayHasKey('cta_url', $items_by_role);

        $this->assertSame(
            'acf:group_core_fields:field_hero_h1',
            (string) $items_by_role['headline']['target_candidates'][0]['target_ref']
        );
        $this->assertSame(
            'acf:group_core_fields:field_hero_body',
            (string) $items_by_role['body']['target_candidates'][0]['target_ref']
        );
        $this->assertSame(
            'acf:group_core_fields:field_hero_button_text',
            (string) $items_by_role['cta_label']['target_candidates'][0]['target_ref']
        );
        $this->assertSame(
            'acf:group_core_fields:field_hero_button_url',
            (string) $items_by_role['cta_url']['target_candidates'][0]['target_ref']
        );
    }

    private function seed_field_context_fixtures(): void
    {
        $GLOBALS['dbvc_test_acf_groups'] = [
            [
                'key' => 'group_core_fields',
                'name' => 'core_group',
                'title' => 'Core',
                'location' => [
                    [
                        [
                            'param' => 'post_type',
                            'operator' => '==',
                            'value' => 'page',
                        ],
                    ],
                ],
                'vf_field_context' => [
                    'purpose' => 'Core page fields for structural page sections and shared content.',
                ],
            ],
            [
                'key' => 'group_services_fields',
                'name' => 'services_group',
                'title' => 'Services',
                'location' => [
                    [
                        [
                            'param' => 'post_type',
                            'operator' => '==',
                            'value' => 'service',
                        ],
                    ],
                ],
                'vf_field_context' => [
                    'purpose' => 'Service-only hero and support fields for service detail pages.',
                ],
            ],
            [
                'key' => 'group_site_settings_fields',
                'name' => 'site_settings_group',
                'title' => 'Site Settings',
                'location' => [
                    [
                        [
                            'param' => 'options_page',
                            'operator' => '==',
                            'value' => 'site-settings',
                        ],
                    ],
                ],
                'vf_field_context' => [
                    'purpose' => 'Site-wide options page settings that are not page post fields.',
                ],
            ],
        ];

        $GLOBALS['dbvc_test_acf_fields'] = [
            'group_core_fields' => [
                [
                    'key' => 'field_hero_section',
                    'name' => 'hero_section',
                    'label' => 'Hero Section',
                    'type' => 'group',
                    'parent' => 'group_core_fields',
                    'vf_field_context' => [
                        'purpose' => 'Top-level hero section wrapper for opening page content.',
                    ],
                    'sub_fields' => [
                        [
                            'key' => 'field_hero_h1',
                            'name' => 'hero_h1',
                            'label' => 'Hero H1',
                            'type' => 'text',
                            'parent' => 'field_hero_section',
                            'vf_field_context' => [
                                'purpose' => 'Primary hero headline that states the page\'s core promise, offer, or outcome.',
                            ],
                            'gardenai_field_purpose' => 'Primary hero H1 that states the page\'s core promise.',
                        ],
                        [
                            'key' => 'field_hero_body',
                            'name' => 'hero_body',
                            'label' => 'Hero Body',
                            'type' => 'textarea',
                            'parent' => 'field_hero_section',
                            'vf_field_context' => [
                                'purpose' => 'Supporting hero body copy that explains the page offer in one or two sentences.',
                            ],
                        ],
                        [
                            'key' => 'field_hero_button_text',
                            'name' => 'hero_button_text',
                            'label' => 'Hero Button Text',
                            'type' => 'text',
                            'parent' => 'field_hero_section',
                            'vf_field_context' => [
                                'purpose' => 'Primary hero call-to-action label.',
                            ],
                        ],
                        [
                            'key' => 'field_hero_button_url',
                            'name' => 'hero_button_url',
                            'label' => 'Hero Button URL',
                            'type' => 'url',
                            'parent' => 'field_hero_section',
                            'vf_field_context' => [
                                'purpose' => 'Primary hero call-to-action destination URL.',
                            ],
                        ],
                        [
                            'key' => 'field_image_group',
                            'name' => 'image_group',
                            'label' => 'Image Group',
                            'type' => 'group',
                            'parent' => 'field_hero_section',
                            'vf_field_context' => [
                                'purpose' => 'Container for hero media and visual support assets.',
                            ],
                            'sub_fields' => [
                                [
                                    'key' => 'field_hero_image',
                                    'name' => 'hero_image',
                                    'label' => 'Hero Image',
                                    'type' => 'image',
                                    'parent' => 'field_image_group',
                                    'return_format' => 'id',
                                    'vf_field_context' => [
                                        'purpose' => 'Hero media asset for the opening section.',
                                    ],
                                ],
                            ],
                        ],
                        [
                            'key' => 'field_hero_clone_headline',
                            'name' => 'hero_clone_headline',
                            'label' => 'Hero Clone Headline',
                            'type' => 'text',
                            'parent' => 'field_hero_section',
                            'vf_field_context' => [
                                'purpose' => 'Clone-projected hero headline mirror that should not be written directly.',
                            ],
                        ],
                    ],
                ],
            ],
            'group_services_fields' => [
                [
                    'key' => 'field_service_hero_h1',
                    'name' => 'hero_h1',
                    'label' => 'Hero H1',
                    'type' => 'text',
                    'parent' => 'group_services_fields',
                    'vf_field_context' => [
                        'purpose' => 'Primary hero headline for service detail pages only.',
                    ],
                ],
            ],
            'group_site_settings_fields' => [
                [
                    'key' => 'field_banner_settings',
                    'name' => 'banner_settings',
                    'label' => 'Header Banner Settings',
                    'type' => 'group',
                    'parent' => 'group_site_settings_fields',
                    'vf_field_context' => [
                        'purpose' => 'Site-wide banner settings shown above the header.',
                    ],
                    'sub_fields' => [
                        [
                            'key' => 'field_announcement_banner_text',
                            'name' => 'announcement_banner_text',
                            'label' => 'Banner Text',
                            'type' => 'wysiwyg',
                            'parent' => 'field_banner_settings',
                            'vf_field_context' => [
                                'purpose' => 'Announcement banner copy shown site-wide above the header.',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $GLOBALS['dbvc_test_field_context_payload'] = [
            'provider' => [
                'name' => 'vertical-field-context',
                'contract_version' => '1.0.0',
            ],
            'catalog_meta' => [
                'status' => 'fresh',
                'source_hash' => 'vf-schema-20260421',
                'schema_version' => '2026-04-21',
                'site_fingerprint' => 'vf-site-test',
            ],
            'data' => [
                'groups' => [
                    [
                        'group_key' => 'group_core_fields',
                        'group_name' => 'core_group',
                        'label' => 'Core',
                        'key_path' => 'group_core_fields',
                        'name_path' => 'core_group',
                        'location' => [
                            [
                                [
                                    'param' => 'post_type',
                                    'operator' => '==',
                                    'value' => 'page',
                                ],
                            ],
                        ],
                        'resolved_purpose' => 'Core page fields for structural page sections and shared content.',
                    ],
                    [
                        'group_key' => 'group_services_fields',
                        'group_name' => 'services_group',
                        'label' => 'Services',
                        'key_path' => 'group_services_fields',
                        'name_path' => 'services_group',
                        'location' => [
                            [
                                [
                                    'param' => 'post_type',
                                    'operator' => '==',
                                    'value' => 'service',
                                ],
                            ],
                        ],
                        'resolved_purpose' => 'Service-only hero and support fields for service detail pages.',
                    ],
                    [
                        'group_key' => 'group_site_settings_fields',
                        'group_name' => 'site_settings_group',
                        'label' => 'Site Settings',
                        'key_path' => 'group_site_settings_fields',
                        'name_path' => 'site_settings_group',
                        'location' => [
                            [
                                [
                                    'param' => 'options_page',
                                    'operator' => '==',
                                    'value' => 'site-settings',
                                ],
                            ],
                        ],
                        'resolved_purpose' => 'Site-wide options page settings that are not page post fields.',
                    ],
                ],
                'entries' => [
                    [
                        'group_key' => 'group_core_fields',
                        'group_name' => 'core_group',
                        'acf_key' => 'field_hero_section',
                        'acf_name' => 'hero_section',
                        'label' => 'Hero Section',
                        'type' => 'group',
                        'container_type' => 'group',
                        'key_path' => 'group_core_fields.hero_section',
                        'name_path' => 'core_group.hero_section',
                        'parent_key_path' => 'group_core_fields',
                        'parent_name_path' => 'core_group',
                        'resolved_purpose' => 'Top-level hero section wrapper for opening page content.',
                        'value_contract' => [
                            'field_type' => 'group',
                            'writable' => false,
                        ],
                        'clone_context' => [
                            'is_clone_projected' => false,
                            'is_directly_writable' => true,
                        ],
                    ],
                    [
                        'group_key' => 'group_core_fields',
                        'group_name' => 'core_group',
                        'acf_key' => 'field_hero_h1',
                        'acf_name' => 'hero_h1',
                        'label' => 'Hero H1',
                        'type' => 'text',
                        'key_path' => 'group_core_fields.hero_section.hero_h1',
                        'name_path' => 'core_group.hero_section.hero_h1',
                        'parent_key_path' => 'group_core_fields.hero_section',
                        'parent_name_path' => 'core_group.hero_section',
                        'resolved_purpose' => 'Primary hero headline that states the page\'s core promise, offer, or outcome.',
                        'value_contract' => [
                            'field_type' => 'text',
                            'writable' => true,
                        ],
                        'clone_context' => [
                            'is_clone_projected' => false,
                            'is_directly_writable' => true,
                        ],
                    ],
                    [
                        'group_key' => 'group_core_fields',
                        'group_name' => 'core_group',
                        'acf_key' => 'field_hero_body',
                        'acf_name' => 'hero_body',
                        'label' => 'Hero Body',
                        'type' => 'textarea',
                        'key_path' => 'group_core_fields.hero_section.hero_body',
                        'name_path' => 'core_group.hero_section.hero_body',
                        'parent_key_path' => 'group_core_fields.hero_section',
                        'parent_name_path' => 'core_group.hero_section',
                        'resolved_purpose' => 'Supporting hero body copy that explains the page offer in one or two sentences.',
                        'value_contract' => [
                            'field_type' => 'textarea',
                            'writable' => true,
                        ],
                        'clone_context' => [
                            'is_clone_projected' => false,
                            'is_directly_writable' => true,
                        ],
                    ],
                    [
                        'group_key' => 'group_core_fields',
                        'group_name' => 'core_group',
                        'acf_key' => 'field_hero_button_text',
                        'acf_name' => 'hero_button_text',
                        'label' => 'Hero Button Text',
                        'type' => 'text',
                        'key_path' => 'group_core_fields.hero_section.hero_button_text',
                        'name_path' => 'core_group.hero_section.hero_button_text',
                        'parent_key_path' => 'group_core_fields.hero_section',
                        'parent_name_path' => 'core_group.hero_section',
                        'resolved_purpose' => 'Primary hero call-to-action label.',
                        'value_contract' => [
                            'field_type' => 'text',
                            'writable' => true,
                        ],
                        'clone_context' => [
                            'is_clone_projected' => false,
                            'is_directly_writable' => true,
                        ],
                    ],
                    [
                        'group_key' => 'group_core_fields',
                        'group_name' => 'core_group',
                        'acf_key' => 'field_hero_button_url',
                        'acf_name' => 'hero_button_url',
                        'label' => 'Hero Button URL',
                        'type' => 'url',
                        'key_path' => 'group_core_fields.hero_section.hero_button_url',
                        'name_path' => 'core_group.hero_section.hero_button_url',
                        'parent_key_path' => 'group_core_fields.hero_section',
                        'parent_name_path' => 'core_group.hero_section',
                        'resolved_purpose' => 'Primary hero call-to-action destination URL.',
                        'value_contract' => [
                            'field_type' => 'url',
                            'writable' => true,
                        ],
                        'clone_context' => [
                            'is_clone_projected' => false,
                            'is_directly_writable' => true,
                        ],
                    ],
                    [
                        'group_key' => 'group_core_fields',
                        'group_name' => 'core_group',
                        'acf_key' => 'field_image_group',
                        'acf_name' => 'image_group',
                        'label' => 'Image Group',
                        'type' => 'group',
                        'container_type' => 'group',
                        'key_path' => 'group_core_fields.hero_section.image_group',
                        'name_path' => 'core_group.hero_section.image_group',
                        'parent_key_path' => 'group_core_fields.hero_section',
                        'parent_name_path' => 'core_group.hero_section',
                        'resolved_purpose' => 'Container for hero media and visual support assets.',
                        'value_contract' => [
                            'field_type' => 'group',
                            'writable' => false,
                        ],
                        'clone_context' => [
                            'is_clone_projected' => false,
                            'is_directly_writable' => true,
                        ],
                    ],
                    [
                        'group_key' => 'group_core_fields',
                        'group_name' => 'core_group',
                        'acf_key' => 'field_hero_image',
                        'acf_name' => 'hero_image',
                        'label' => 'Hero Image',
                        'type' => 'image',
                        'key_path' => 'group_core_fields.hero_section.image_group.hero_image',
                        'name_path' => 'core_group.hero_section.image_group.hero_image',
                        'parent_key_path' => 'group_core_fields.hero_section.image_group',
                        'parent_name_path' => 'core_group.hero_section.image_group',
                        'resolved_purpose' => 'Hero media asset for the opening section.',
                        'value_contract' => [
                            'field_type' => 'image',
                            'writable' => true,
                            'return_format' => 'id',
                        ],
                        'clone_context' => [
                            'is_clone_projected' => false,
                            'is_directly_writable' => true,
                        ],
                    ],
                    [
                        'group_key' => 'group_core_fields',
                        'group_name' => 'core_group',
                        'acf_key' => 'field_hero_clone_headline',
                        'acf_name' => 'hero_clone_headline',
                        'label' => 'Hero Clone Headline',
                        'type' => 'text',
                        'key_path' => 'group_core_fields.hero_section.hero_clone_headline',
                        'name_path' => 'core_group.hero_section.hero_clone_headline',
                        'parent_key_path' => 'group_core_fields.hero_section',
                        'parent_name_path' => 'core_group.hero_section',
                        'resolved_purpose' => 'Clone-projected hero headline mirror that should not be written directly.',
                        'value_contract' => [
                            'field_type' => 'text',
                            'writable' => true,
                        ],
                        'clone_context' => [
                            'is_clone_projected' => true,
                            'is_directly_writable' => false,
                        ],
                    ],
                    [
                        'group_key' => 'group_services_fields',
                        'group_name' => 'services_group',
                        'acf_key' => 'field_service_hero_h1',
                        'acf_name' => 'hero_h1',
                        'label' => 'Hero H1',
                        'type' => 'text',
                        'key_path' => 'group_services_fields.hero_h1',
                        'name_path' => 'services_group.hero_h1',
                        'parent_key_path' => 'group_services_fields',
                        'parent_name_path' => 'services_group',
                        'resolved_purpose' => 'Primary hero headline for service detail pages only.',
                        'value_contract' => [
                            'field_type' => 'text',
                            'writable' => true,
                        ],
                        'clone_context' => [
                            'is_clone_projected' => false,
                            'is_directly_writable' => true,
                        ],
                    ],
                    [
                        'group_key' => 'group_site_settings_fields',
                        'group_name' => 'site_settings_group',
                        'acf_key' => 'field_banner_settings',
                        'acf_name' => 'banner_settings',
                        'label' => 'Header Banner Settings',
                        'type' => 'group',
                        'container_type' => 'group',
                        'key_path' => 'group_site_settings_fields.banner_settings',
                        'name_path' => 'site_settings_group.banner_settings',
                        'parent_key_path' => 'group_site_settings_fields',
                        'parent_name_path' => 'site_settings_group',
                        'resolved_purpose' => 'Site-wide banner settings shown above the header.',
                        'value_contract' => [
                            'field_type' => 'group',
                            'writable' => false,
                        ],
                        'clone_context' => [
                            'is_clone_projected' => false,
                            'is_directly_writable' => true,
                        ],
                    ],
                    [
                        'group_key' => 'group_site_settings_fields',
                        'group_name' => 'site_settings_group',
                        'acf_key' => 'field_announcement_banner_text',
                        'acf_name' => 'announcement_banner_text',
                        'label' => 'Banner Text',
                        'type' => 'wysiwyg',
                        'key_path' => 'group_site_settings_fields.banner_settings.announcement_banner_text',
                        'name_path' => 'site_settings_group.banner_settings.announcement_banner_text',
                        'parent_key_path' => 'group_site_settings_fields.banner_settings',
                        'parent_name_path' => 'site_settings_group.banner_settings',
                        'resolved_purpose' => 'Announcement banner copy shown site-wide above the header.',
                        'value_contract' => [
                            'field_type' => 'wysiwyg',
                            'writable' => true,
                        ],
                        'clone_context' => [
                            'is_clone_projected' => false,
                            'is_directly_writable' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function reset_singleton(string $class_name): void
    {
        if (! class_exists($class_name)) {
            return;
        }

        $reflection = new ReflectionClass($class_name);
        if (! $reflection->hasProperty('instance')) {
            return;
        }

        $property = $reflection->getProperty('instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    private function delete_domain_artifacts(string $domain): void
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($context) || empty($context['domain_dir'])) {
            return;
        }

        $this->delete_directory((string) $context['domain_dir']);
    }

    private function delete_directory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->delete_directory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }

    private function write_fixture_json(string $path, array $payload): void
    {
        wp_mkdir_p((string) dirname($path));
        file_put_contents($path, (string) wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
