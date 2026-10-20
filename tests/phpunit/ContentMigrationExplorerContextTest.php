<?php

class ContentMigrationExplorerContextTest extends WP_UnitTestCase
{
    private $domain_key = 'phase36-example.test';
    private $relative_path = 'home';
    private $partial_relative_path = 'home-partial';
    private $domain_dir = '';
    private $original_settings = [];

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->reset_rest_server();

        if (class_exists('DBVC_CC_Contracts')) {
            DBVC_CC_Contracts::ensure_phase_zero_defaults();
            update_option(DBVC_CC_Contracts::OPTION_FLAG_EXPLORER, '1');
        }

        $this->original_settings = class_exists('DBVC_CC_Settings_Service')
            ? DBVC_CC_Settings_Service::get_options()
            : [];

        if (!empty($this->original_settings) && class_exists('DBVC_CC_Contracts')) {
            $settings = $this->original_settings;
            $settings['storage_path'] = DBVC_CC_Contracts::STORAGE_DEFAULT_PATH;
            update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $settings);
        }

        $this->build_fixture_artifacts();
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        if (is_string($this->domain_dir) && $this->domain_dir !== '') {
            $this->remove_directory($this->domain_dir);
        }

        if (!empty($this->original_settings) && class_exists('DBVC_CC_Contracts')) {
            update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $this->original_settings);
        }

        $this->reset_rest_server();
        parent::tear_down();
    }

    public function test_content_context_endpoint_returns_trimmed_payload(): void
    {
        $request = new WP_REST_Request('GET', '/dbvc_cc/v1/explorer/content-context');
        $request->set_param('domain', $this->domain_key);
        $request->set_param('path', $this->relative_path);
        $request->set_param('artifact', 'all');
        $request->set_param('limit', 40);

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertSame('all', $data['artifact']);
        $this->assertSame(40, $data['limit']);
        $this->assertTrue($data['phase36']['available']['elements']);
        $this->assertArrayHasKey('elements', $data['payload']);
        $this->assertArrayHasKey('sections', $data['payload']);
        $this->assertArrayHasKey('context_bundle', $data['payload']);
        $this->assertArrayHasKey('privacy', $data['payload']['context_bundle']);
        $this->assertSame(2, $data['payload']['elements']['elements_total']);

        $this->assertFixtureMatches('content-context.expected.json', $this->snapshot_content_context_all($data));
    }

    public function test_content_context_endpoint_accepts_artifact_alias_and_clamps_limit(): void
    {
        $request = new WP_REST_Request('GET', '/dbvc_cc/v1/explorer/content-context');
        $request->set_param('domain', $this->domain_key);
        $request->set_param('path', $this->relative_path);
        $request->set_param('artifact', 'section-typing-v2');
        $request->set_param('limit', 999);

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertSame('section_typing', $data['artifact']);
        $this->assertSame(200, $data['limit']);
        $this->assertArrayHasKey('section_typing', $data['payload']);
        $this->assertArrayHasKey('section_typings_total', $data['payload']['section_typing']);
    }

    public function test_scrub_policy_preview_endpoint_returns_deterministic_suggestions(): void
    {
        $request = new WP_REST_Request('GET', '/dbvc_cc/v1/explorer/scrub-policy-preview');
        $request->set_param('domain', $this->domain_key);
        $request->set_param('path', $this->relative_path);
        $request->set_param('sample_size', 25);

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertSame('deterministic-preview', $data['mode']);
        $this->assertTrue($data['approval_required']);
        $this->assertSame(25, $data['sample_size']);
        $this->assertArrayHasKey('current_actions', $data);
        $this->assertArrayHasKey('suggested_actions', $data);
        $this->assertArrayHasKey('top_attributes', $data);
        $this->assertNotEmpty($data['top_attributes']);
    }

    public function test_scrub_policy_approval_status_endpoint_returns_last_approval_shape(): void
    {
        $request = new WP_REST_Request('GET', '/dbvc_cc/v1/explorer/scrub-policy-approval-status');
        $request->set_param('domain', $this->domain_key);
        $request->set_param('path', $this->relative_path);
        $response = rest_get_server()->dispatch($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('current_actions', $data);
        $this->assertArrayHasKey('current_profile_mode', $data);
        $this->assertArrayHasKey('last_approval', $data);
        $this->assertTrue($data['approval_required']);
    }

    public function test_scrub_policy_approve_endpoint_updates_settings_and_status(): void
    {
        $request = new WP_REST_Request('POST', '/dbvc_cc/v1/explorer/scrub-policy-approve');
        $request->set_header('content-type', 'application/json');
        $request->set_body(
            wp_json_encode(
                [
                    'domain' => $this->domain_key,
                    'path' => $this->relative_path,
                    'sample_size' => 20,
                ]
            )
        );
        $response = rest_get_server()->dispatch($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('approved', $data['status']);
        $this->assertArrayHasKey('applied_actions', $data);
        $this->assertArrayHasKey('current_actions', $data);

        $settings = get_option(DBVC_CC_Contracts::OPTION_SETTINGS, []);
        $this->assertIsArray($settings);
        $this->assertSame($data['applied_actions']['class'], $settings['scrub_attr_action_class']);
        $this->assertSame($data['applied_actions']['id'], $settings['scrub_attr_action_id']);
        $this->assertSame($data['applied_actions']['data'], $settings['scrub_attr_action_data']);
        $this->assertSame($data['applied_actions']['style'], $settings['scrub_attr_action_style']);
        $this->assertSame($data['applied_actions']['aria'], $settings['scrub_attr_action_aria']);

        $approval_status = get_option(DBVC_CC_Contracts::OPTION_SCRUB_POLICY_APPROVAL_STATUS, []);
        $this->assertIsArray($approval_status);
        $this->assertSame($this->domain_key, $approval_status['domain'] ?? '');
        $this->assertSame($this->relative_path, $approval_status['path'] ?? '');
        $this->assertArrayHasKey('applied_actions', $approval_status);
    }

    public function test_explorer_content_endpoint_includes_phase36_summary(): void
    {
        $request = new WP_REST_Request('GET', '/dbvc_cc/v1/explorer/content');
        $request->set_param('domain', $this->domain_key);
        $request->set_param('path', $this->relative_path);
        $request->set_param('mode', 'raw');

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('phase36', $data);
        $this->assertTrue($data['phase36']['available']['elements']);
        $this->assertSame('deep', $data['phase36']['modes']['capture_mode']);
        $this->assertSame('fallback', $data['phase36']['modes']['section_typing_mode']);
        $this->assertArrayHasKey('badges', $data['phase36']);
        $this->assertNotEmpty($data['phase36']['badges']);
        $this->assertArrayHasKey('processing', $data['phase36']['elements']);
        $this->assertArrayHasKey('processing', $data['phase36']['sections']);

        $this->assertFixtureMatches('content-preview-phase36.expected.json', $this->snapshot_content_preview($data));
    }

    public function test_node_audit_endpoint_includes_pipeline_counts(): void
    {
        $request = new WP_REST_Request('GET', '/dbvc_cc/v1/explorer/node/audit');
        $request->set_param('domain', $this->domain_key);
        $request->set_param('path', $this->relative_path);
        $request->set_param('limit', 20);

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('pipeline_counts', $data['summary']);
        $this->assertArrayHasKey('dbvc_cc_p36_fixture_one', $data['summary']['pipeline_counts']);
        $this->assertArrayHasKey('dbvc_cc_p36_fixture_two', $data['summary']['pipeline_counts']);
        $this->assertArrayHasKey('events', $data);
        $this->assertNotEmpty($data['events']);
        $this->assertArrayHasKey('pipeline_id', $data['events'][0]);

        $this->assertFixtureMatches('node-audit.expected.json', $this->snapshot_node_audit($data));
    }

    public function test_node_audit_endpoint_filters_by_pipeline_id(): void
    {
        $request = new WP_REST_Request('GET', '/dbvc_cc/v1/explorer/node/audit');
        $request->set_param('domain', $this->domain_key);
        $request->set_param('path', $this->relative_path);
        $request->set_param('pipeline_id', 'dbvc_cc_p36_fixture_two');
        $request->set_param('limit', 20);

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();

        $this->assertIsArray($data);
        $this->assertSame('dbvc_cc_p36_fixture_two', $data['pipeline_id']);
        $this->assertArrayHasKey('events', $data);
        $this->assertCount(1, $data['events']);
        $this->assertSame('dbvc_cc_p36_fixture_two', (string) $data['events'][0]['pipeline_id']);

        $this->assertFixtureMatches('node-audit-filtered.expected.json', $this->snapshot_node_audit($data));
    }

    public function test_content_context_endpoint_includes_partial_resume_markers(): void
    {
        $request = new WP_REST_Request('GET', '/dbvc_cc/v1/explorer/content-context');
        $request->set_param('domain', $this->domain_key);
        $request->set_param('path', $this->partial_relative_path);
        $request->set_param('artifact', 'all');
        $request->set_param('limit', 40);

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('payload', $data);
        $this->assertArrayHasKey('elements', $data['payload']);
        $this->assertArrayHasKey('sections', $data['payload']);
        $this->assertTrue(!empty($data['payload']['elements']['processing']['is_partial']));
        $this->assertTrue(!empty($data['payload']['sections']['processing']['is_partial']));
        $this->assertSame('max_elements', (string) ($data['payload']['elements']['processing']['partial_reason'] ?? ''));
        $this->assertSame('timeout', (string) ($data['payload']['sections']['processing']['partial_reason'] ?? ''));
        $this->assertIsArray($data['payload']['elements']['processing']['resume_marker'] ?? null);
        $this->assertIsArray($data['payload']['sections']['processing']['resume_marker'] ?? null);

        $this->assertFixtureMatches('content-context-partial.expected.json', $this->snapshot_content_context_partial($data));
    }

    private function build_fixture_artifacts(): void
    {
        $upload_dir = wp_upload_dir();
        $base_dir = isset($upload_dir['basedir']) ? (string) $upload_dir['basedir'] : '';
        $storage_dir = trailingslashit($base_dir) . 'contentcollector';
        $this->domain_dir = trailingslashit($storage_dir) . $this->domain_key;
        $page_dir = trailingslashit($this->domain_dir) . $this->relative_path;
        $partial_page_dir = trailingslashit($this->domain_dir) . $this->partial_relative_path;

        wp_mkdir_p($page_dir);
        wp_mkdir_p($partial_page_dir);
        $slug = 'home';
        $partial_slug = 'home-partial';
        $source_url = 'https://' . $this->domain_key . '/';
        $partial_source_url = 'https://' . $this->domain_key . '/home-partial';

        $main_artifact = [
            'page_name' => 'Home',
            'slug' => 'home',
            'source_url' => $source_url,
            'provenance' => [
                'source_url' => $source_url,
                'canonical_source_url' => $source_url,
                'content_hash' => hash('sha256', 'fixture-home'),
            ],
            'meta' => [
                'description' => 'Fixture home page',
            ],
            'content' => [
                'headings' => ['Home'],
                'text_blocks' => ['Welcome to the fixture home page.', 'Contact us today.'],
                'images' => [],
                'sections' => [
                    [
                        'id' => 'section-1',
                        'order' => 1,
                        'parent_id' => null,
                        'level' => 1,
                        'heading_tag' => 'h1',
                        'heading' => 'Home',
                        'is_intro' => true,
                        'text_blocks' => ['Welcome to the fixture home page.', 'Contact us today.'],
                        'links' => [
                            ['type' => 'link', 'text' => 'Contact', 'url' => $source_url . 'contact'],
                        ],
                        'ctas' => [
                            ['type' => 'button', 'text' => 'Get Started', 'url' => $source_url . 'start'],
                        ],
                        'images' => [],
                    ],
                ],
            ],
            'compliance' => [
                'pii_flags' => [
                    'emails_count' => 0,
                    'phones_count' => 0,
                    'forms_count' => 0,
                ],
                'redaction_rules' => [
                    'emails' => true,
                    'phones' => true,
                    'forms' => true,
                ],
                'requires_legal_review' => false,
            ],
        ];

        $analysis_artifact = [
            'status' => 'completed',
            'post_type' => 'page',
            'post_type_confidence' => 0.95,
            'categories' => ['company'],
            'summary' => 'Fixture summary.',
            'reasoning' => 'Fixture reasoning.',
        ];

        $sanitized_artifact = [
            'sanitized_html' => '<main><h1>Home</h1><p>Welcome to the fixture home page.</p></main>',
        ];

        $elements_v2 = [
            'artifact_schema_version' => '2.0.0',
            'artifact_type' => 'elements.v2',
            'source_url' => $source_url,
            'capture_mode' => 'deep',
            'element_count' => 2,
            'truncated' => false,
            'processing' => [
                'is_partial' => false,
                'partial_reason' => '',
                'resume_marker' => new stdClass(),
            ],
            'elements' => [
                [
                    'element_id' => 'dbvc_cc_el_0001',
                    'tag' => 'h1',
                    'text' => 'Home',
                    'sequence_index' => 1,
                    'heading_context' => ['h1' => 'Home'],
                    'attributes' => ['class' => 'hero-title', 'id' => 'home-title'],
                ],
                [
                    'element_id' => 'dbvc_cc_el_0002',
                    'tag' => 'p',
                    'text' => 'Welcome to the fixture home page.',
                    'sequence_index' => 2,
                    'heading_context' => ['h1' => 'Home'],
                    'attributes' => ['class' => 'hero-text', 'data-track' => '1'],
                ],
            ],
        ];

        $scrub_report = [
            'artifact_schema_version' => '2.0.0',
            'artifact_type' => 'attribute-scrub-report.v2',
            'source_url' => $source_url,
            'profile' => 'deterministic-default',
            'policy_hash' => hash('sha256', 'fixture-policy'),
            'totals' => ['kept' => 2, 'dropped' => 1, 'hashed' => 1, 'tokenized' => 1],
            'by_attribute' => ['class' => 2, 'id' => 1, 'data-track' => 1],
            'warnings' => [],
        ];

        $sections_v2 = [
            'artifact_schema_version' => '2.0.0',
            'artifact_type' => 'sections.v2',
            'source_url' => $source_url,
            'section_count' => 1,
            'processing' => [
                'is_partial' => false,
                'partial_reason' => '',
                'resume_marker' => new stdClass(),
            ],
            'sections' => [
                [
                    'section_id' => 'dbvc_cc_sec_0001',
                    'order' => 1,
                    'section_label_candidate' => 'Home',
                    'start_sequence_index' => 1,
                    'end_sequence_index' => 2,
                    'heading_anchor_element_id' => 'dbvc_cc_el_0001',
                    'element_ids' => ['dbvc_cc_el_0001', 'dbvc_cc_el_0002'],
                    'signals' => [
                        'heading_level' => 1,
                        'has_heading' => true,
                        'text_element_count' => 1,
                        'link_element_count' => 0,
                        'list_element_count' => 0,
                    ],
                ],
            ],
        ];

        $section_typing_v2 = [
            'artifact_schema_version' => '2.0.0',
            'artifact_type' => 'section-typing.v2',
            'source_url' => $source_url,
            'mode' => 'fallback',
            'confidence_threshold' => 0.65,
            'section_typings' => [
                [
                    'section_id' => 'dbvc_cc_sec_0001',
                    'section_type_candidate' => 'hero',
                    'confidence' => 0.78,
                    'mode' => 'fallback',
                    'evidence_element_ids' => ['dbvc_cc_el_0001'],
                ],
            ],
        ];

        $context_bundle_v2 = [
            'artifact_schema_version' => '2.0.0',
            'artifact_type' => 'context-bundle.v2',
            'source_url' => $source_url,
            'outline' => [
                ['level' => 1, 'label' => 'Home', 'section_id' => 'dbvc_cc_sec_0001'],
            ],
            'sections' => [
                ['section_id' => 'dbvc_cc_sec_0001', 'label_candidate' => 'Home', 'element_count' => 2],
            ],
            'link_graph_hints' => ['internal_link_count' => 1, 'external_link_count' => 0],
            'entity_hints' => [
                'pii_flags' => [
                    'emails_count' => 0,
                    'phones_count' => 0,
                    'forms_count' => 0,
                ],
                'pii_hint_tags' => [],
            ],
            'privacy' => [
                'redaction_rules' => [
                    'emails' => true,
                    'phones' => true,
                    'forms' => true,
                ],
                'requires_legal_review' => false,
            ],
            'trace_map' => [
                'dbvc_cc_sec_0001' => ['dbvc_cc_el_0001', 'dbvc_cc_el_0002'],
            ],
        ];

        $ingestion_package_v2 = [
            'artifact_schema_version' => '2.0.0',
            'artifact_type' => 'ingestion-package.v2',
            'source_url' => $source_url,
            'sections' => [
                [
                    'section_id' => 'dbvc_cc_sec_0001',
                    'label' => 'Home',
                    'section_type' => 'hero',
                    'section_type_mode' => 'fallback',
                ],
            ],
            'traceability' => [
                'dbvc_cc_sec_0001' => [
                    'element_ids' => ['dbvc_cc_el_0001', 'dbvc_cc_el_0002'],
                    'typing_evidence_element_ids' => ['dbvc_cc_el_0001'],
                ],
            ],
            'stats' => ['section_count' => 1, 'element_count' => 2],
        ];

        $this->write_json($page_dir . '/' . $slug . '.json', $main_artifact);
        $this->write_json($page_dir . '/' . $slug . '.analysis.json', $analysis_artifact);
        $this->write_json($page_dir . '/' . $slug . '.sanitized.json', $sanitized_artifact);
        $this->write_json($page_dir . '/' . $slug . '.elements.v2.json', $elements_v2);
        $this->write_json($page_dir . '/' . $slug . '.attribute-scrub-report.v2.json', $scrub_report);
        $this->write_json($page_dir . '/' . $slug . '.sections.v2.json', $sections_v2);
        $this->write_json($page_dir . '/' . $slug . '.section-typing.v2.json', $section_typing_v2);
        $this->write_json($page_dir . '/' . $slug . '.context-bundle.v2.json', $context_bundle_v2);
        $this->write_json($page_dir . '/' . $slug . '.ingestion-package.v2.json', $ingestion_package_v2);

        $partial_main_artifact = $main_artifact;
        $partial_main_artifact['slug'] = $partial_slug;
        $partial_main_artifact['source_url'] = $partial_source_url;
        $partial_main_artifact['provenance']['source_url'] = $partial_source_url;
        $partial_main_artifact['provenance']['canonical_source_url'] = $partial_source_url;
        $partial_main_artifact['provenance']['content_hash'] = hash('sha256', 'fixture-home-partial');

        $partial_analysis_artifact = $analysis_artifact;
        $partial_sanitized_artifact = $sanitized_artifact;

        $partial_elements_v2 = $elements_v2;
        $partial_elements_v2['source_url'] = $partial_source_url;
        $partial_elements_v2['truncated'] = true;
        $partial_elements_v2['processing'] = [
            'is_partial' => true,
            'partial_reason' => 'max_elements',
            'resume_marker' => [
                'context_node_index' => 3,
                'node_offset' => 12,
            ],
        ];

        $partial_sections_v2 = $sections_v2;
        $partial_sections_v2['source_url'] = $partial_source_url;
        $partial_sections_v2['processing'] = [
            'is_partial' => true,
            'partial_reason' => 'timeout',
            'resume_marker' => [
                'element_offset' => 42,
            ],
        ];

        $partial_section_typing_v2 = $section_typing_v2;
        $partial_section_typing_v2['source_url'] = $partial_source_url;
        $partial_context_bundle_v2 = $context_bundle_v2;
        $partial_context_bundle_v2['source_url'] = $partial_source_url;
        $partial_ingestion_package_v2 = $ingestion_package_v2;
        $partial_ingestion_package_v2['source_url'] = $partial_source_url;
        $partial_scrub_report = $scrub_report;
        $partial_scrub_report['source_url'] = $partial_source_url;

        $this->write_json($partial_page_dir . '/' . $partial_slug . '.json', $partial_main_artifact);
        $this->write_json($partial_page_dir . '/' . $partial_slug . '.analysis.json', $partial_analysis_artifact);
        $this->write_json($partial_page_dir . '/' . $partial_slug . '.sanitized.json', $partial_sanitized_artifact);
        $this->write_json($partial_page_dir . '/' . $partial_slug . '.elements.v2.json', $partial_elements_v2);
        $this->write_json($partial_page_dir . '/' . $partial_slug . '.attribute-scrub-report.v2.json', $partial_scrub_report);
        $this->write_json($partial_page_dir . '/' . $partial_slug . '.sections.v2.json', $partial_sections_v2);
        $this->write_json($partial_page_dir . '/' . $partial_slug . '.section-typing.v2.json', $partial_section_typing_v2);
        $this->write_json($partial_page_dir . '/' . $partial_slug . '.context-bundle.v2.json', $partial_context_bundle_v2);
        $this->write_json($partial_page_dir . '/' . $partial_slug . '.ingestion-package.v2.json', $partial_ingestion_package_v2);

        $logs_dir = trailingslashit($this->domain_dir) . '_logs';
        wp_mkdir_p($logs_dir);
        $events = [
            [
                'timestamp' => '2026-03-08T00:00:00+00:00',
                'domain' => $this->domain_key,
                'stage' => 'extract',
                'status' => 'success',
                'path' => 'home',
                'page_url' => $source_url,
                'pipeline_id' => 'dbvc_cc_p36_fixture_one',
            ],
            [
                'timestamp' => '2026-03-08T00:00:01+00:00',
                'domain' => $this->domain_key,
                'stage' => 'segment',
                'status' => 'partial',
                'path' => 'home',
                'page_url' => $source_url,
                'pipeline_id' => 'dbvc_cc_p36_fixture_one',
            ],
            [
                'timestamp' => '2026-03-08T00:00:02+00:00',
                'domain' => $this->domain_key,
                'stage' => 'ingestion_package',
                'status' => 'success',
                'path' => 'home',
                'page_url' => $source_url,
                'pipeline_id' => 'dbvc_cc_p36_fixture_two',
            ],
            [
                'timestamp' => '2026-03-08T00:00:03+00:00',
                'domain' => $this->domain_key,
                'stage' => 'extract',
                'status' => 'success',
                'path' => 'about',
                'page_url' => 'https://' . $this->domain_key . '/about',
                'pipeline_id' => 'dbvc_cc_p36_fixture_other',
            ],
            [
                'timestamp' => '2026-03-08T00:00:04+00:00',
                'domain' => $this->domain_key,
                'stage' => 'extract',
                'status' => 'partial',
                'path' => $this->partial_relative_path,
                'page_url' => $partial_source_url,
                'pipeline_id' => 'dbvc_cc_p36_fixture_partial',
            ],
            [
                'timestamp' => '2026-03-08T00:00:05+00:00',
                'domain' => $this->domain_key,
                'stage' => 'segment',
                'status' => 'partial',
                'path' => $this->partial_relative_path,
                'page_url' => $partial_source_url,
                'pipeline_id' => 'dbvc_cc_p36_fixture_partial',
            ],
        ];
        $lines = [];
        foreach ($events as $event) {
            $lines[] = (string) wp_json_encode($event, JSON_UNESCAPED_SLASHES);
        }
        file_put_contents(trailingslashit($logs_dir) . 'events.ndjson', implode(PHP_EOL, $lines) . PHP_EOL);
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function snapshot_content_context_all(array $response): array
    {
        return [
            'domain' => isset($response['domain']) ? (string) $response['domain'] : '',
            'path' => isset($response['path']) ? (string) $response['path'] : '',
            'artifact' => isset($response['artifact']) ? (string) $response['artifact'] : '',
            'limit' => isset($response['limit']) ? (int) $response['limit'] : 0,
            'phase36' => [
                'available' => isset($response['phase36']['available']) && is_array($response['phase36']['available']) ? $response['phase36']['available'] : [],
                'modes' => isset($response['phase36']['modes']) && is_array($response['phase36']['modes']) ? $response['phase36']['modes'] : [],
                'badges' => isset($response['phase36']['badges']) && is_array($response['phase36']['badges']) ? $response['phase36']['badges'] : [],
            ],
            'payload' => [
                'elements' => [
                    'artifact_type' => isset($response['payload']['elements']['artifact_type']) ? (string) $response['payload']['elements']['artifact_type'] : '',
                    'elements_total' => isset($response['payload']['elements']['elements_total']) ? (int) $response['payload']['elements']['elements_total'] : 0,
                    'preview_truncated' => !empty($response['payload']['elements']['preview_truncated']),
                ],
                'sections' => [
                    'artifact_type' => isset($response['payload']['sections']['artifact_type']) ? (string) $response['payload']['sections']['artifact_type'] : '',
                    'sections_total' => isset($response['payload']['sections']['sections_total']) ? (int) $response['payload']['sections']['sections_total'] : 0,
                    'preview_truncated' => !empty($response['payload']['sections']['preview_truncated']),
                ],
                'section_typing' => [
                    'artifact_type' => isset($response['payload']['section_typing']['artifact_type']) ? (string) $response['payload']['section_typing']['artifact_type'] : '',
                    'section_typings_total' => isset($response['payload']['section_typing']['section_typings_total']) ? (int) $response['payload']['section_typing']['section_typings_total'] : 0,
                    'preview_truncated' => !empty($response['payload']['section_typing']['preview_truncated']),
                ],
                'context_bundle' => [
                    'artifact_type' => isset($response['payload']['context_bundle']['artifact_type']) ? (string) $response['payload']['context_bundle']['artifact_type'] : '',
                    'sections_total' => isset($response['payload']['context_bundle']['sections_total']) ? (int) $response['payload']['context_bundle']['sections_total'] : 0,
                    'outline_total' => isset($response['payload']['context_bundle']['outline_total']) ? (int) $response['payload']['context_bundle']['outline_total'] : 0,
                    'privacy' => isset($response['payload']['context_bundle']['privacy']) && is_array($response['payload']['context_bundle']['privacy']) ? $response['payload']['context_bundle']['privacy'] : [],
                    'preview_truncated' => !empty($response['payload']['context_bundle']['preview_truncated']),
                ],
                'ingestion_package' => [
                    'artifact_type' => isset($response['payload']['ingestion_package']['artifact_type']) ? (string) $response['payload']['ingestion_package']['artifact_type'] : '',
                    'sections_total' => isset($response['payload']['ingestion_package']['sections_total']) ? (int) $response['payload']['ingestion_package']['sections_total'] : 0,
                    'preview_truncated' => !empty($response['payload']['ingestion_package']['preview_truncated']),
                ],
                'scrub_report' => [
                    'artifact_type' => isset($response['payload']['scrub_report']['artifact_type']) ? (string) $response['payload']['scrub_report']['artifact_type'] : '',
                    'by_attribute_total' => isset($response['payload']['scrub_report']['by_attribute_total']) ? (int) $response['payload']['scrub_report']['by_attribute_total'] : 0,
                    'warnings_total' => isset($response['payload']['scrub_report']['warnings_total']) ? (int) $response['payload']['scrub_report']['warnings_total'] : 0,
                    'preview_truncated' => !empty($response['payload']['scrub_report']['preview_truncated']),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function snapshot_content_context_partial(array $response): array
    {
        return [
            'domain' => isset($response['domain']) ? (string) $response['domain'] : '',
            'path' => isset($response['path']) ? (string) $response['path'] : '',
            'artifact' => isset($response['artifact']) ? (string) $response['artifact'] : '',
            'limit' => isset($response['limit']) ? (int) $response['limit'] : 0,
            'payload' => [
                'elements' => [
                    'artifact_type' => isset($response['payload']['elements']['artifact_type']) ? (string) $response['payload']['elements']['artifact_type'] : '',
                    'truncated' => !empty($response['payload']['elements']['truncated']),
                    'processing' => [
                        'is_partial' => !empty($response['payload']['elements']['processing']['is_partial']),
                        'partial_reason' => isset($response['payload']['elements']['processing']['partial_reason']) ? (string) $response['payload']['elements']['processing']['partial_reason'] : '',
                        'resume_marker' => isset($response['payload']['elements']['processing']['resume_marker']) && is_array($response['payload']['elements']['processing']['resume_marker']) ? $response['payload']['elements']['processing']['resume_marker'] : [],
                    ],
                ],
                'sections' => [
                    'artifact_type' => isset($response['payload']['sections']['artifact_type']) ? (string) $response['payload']['sections']['artifact_type'] : '',
                    'processing' => [
                        'is_partial' => !empty($response['payload']['sections']['processing']['is_partial']),
                        'partial_reason' => isset($response['payload']['sections']['processing']['partial_reason']) ? (string) $response['payload']['sections']['processing']['partial_reason'] : '',
                        'resume_marker' => isset($response['payload']['sections']['processing']['resume_marker']) && is_array($response['payload']['sections']['processing']['resume_marker']) ? $response['payload']['sections']['processing']['resume_marker'] : [],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function snapshot_content_preview(array $response): array
    {
        return [
            'domain' => isset($response['domain']) ? (string) $response['domain'] : '',
            'path' => isset($response['path']) ? (string) $response['path'] : '',
            'mode' => isset($response['mode']) ? (string) $response['mode'] : '',
            'content' => [
                'title' => isset($response['content']['title']) ? (string) $response['content']['title'] : '',
                'source_url' => isset($response['content']['source_url']) ? (string) $response['content']['source_url'] : '',
                'section_count' => isset($response['content']['section_count']) ? (int) $response['content']['section_count'] : 0,
            ],
            'phase36' => [
                'available' => isset($response['phase36']['available']) && is_array($response['phase36']['available']) ? $response['phase36']['available'] : [],
                'modes' => isset($response['phase36']['modes']) && is_array($response['phase36']['modes']) ? $response['phase36']['modes'] : [],
                'elements' => [
                    'element_count' => isset($response['phase36']['elements']['element_count']) ? (int) $response['phase36']['elements']['element_count'] : 0,
                    'truncated' => !empty($response['phase36']['elements']['truncated']),
                    'processing' => isset($response['phase36']['elements']['processing']) && is_array($response['phase36']['elements']['processing']) ? $response['phase36']['elements']['processing'] : [],
                ],
                'sections' => [
                    'section_count' => isset($response['phase36']['sections']['section_count']) ? (int) $response['phase36']['sections']['section_count'] : 0,
                    'processing' => isset($response['phase36']['sections']['processing']) && is_array($response['phase36']['sections']['processing']) ? $response['phase36']['sections']['processing'] : [],
                ],
                'section_typing' => [
                    'mode' => isset($response['phase36']['section_typing']['mode']) ? (string) $response['phase36']['section_typing']['mode'] : '',
                    'count' => isset($response['phase36']['section_typing']['count']) ? (int) $response['phase36']['section_typing']['count'] : 0,
                    'type_counts' => isset($response['phase36']['section_typing']['type_counts']) && is_array($response['phase36']['section_typing']['type_counts']) ? $response['phase36']['section_typing']['type_counts'] : [],
                ],
                'context_bundle' => [
                    'outline_count' => isset($response['phase36']['context_bundle']['outline_count']) ? (int) $response['phase36']['context_bundle']['outline_count'] : 0,
                    'section_count' => isset($response['phase36']['context_bundle']['section_count']) ? (int) $response['phase36']['context_bundle']['section_count'] : 0,
                ],
                'ingestion_package' => [
                    'section_count' => isset($response['phase36']['ingestion_package']['section_count']) ? (int) $response['phase36']['ingestion_package']['section_count'] : 0,
                    'element_count' => isset($response['phase36']['ingestion_package']['element_count']) ? (int) $response['phase36']['ingestion_package']['element_count'] : 0,
                ],
                'scrub_report' => [
                    'profile' => isset($response['phase36']['scrub_report']['profile']) ? (string) $response['phase36']['scrub_report']['profile'] : '',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function snapshot_node_audit(array $response): array
    {
        $first_event = [];
        if (isset($response['events'][0]) && is_array($response['events'][0])) {
            $first_event = [
                'stage' => isset($response['events'][0]['stage']) ? (string) $response['events'][0]['stage'] : '',
                'status' => isset($response['events'][0]['status']) ? (string) $response['events'][0]['status'] : '',
                'path' => isset($response['events'][0]['path']) ? (string) $response['events'][0]['path'] : '',
                'pipeline_id' => isset($response['events'][0]['pipeline_id']) ? (string) $response['events'][0]['pipeline_id'] : '',
            ];
        }

        return [
            'domain' => isset($response['domain']) ? (string) $response['domain'] : '',
            'path' => isset($response['path']) ? (string) $response['path'] : '',
            'pipeline_id' => isset($response['pipeline_id']) ? (string) $response['pipeline_id'] : '',
            'summary' => isset($response['summary']) && is_array($response['summary']) ? $response['summary'] : [],
            'events' => $first_event === [] ? [] : [$first_event],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function load_fixture(string $fixture_name): array
    {
        $fixture_path = dirname(__DIR__, 2) . '/addons/content-migration/tests/fixtures/explorer/' . $fixture_name;
        $raw = file_get_contents($fixture_path);
        $this->assertIsString($raw, 'Fixture file could not be read: ' . $fixture_name);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'Fixture file is not valid JSON object: ' . $fixture_name);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $actual
     */
    private function assertFixtureMatches(string $fixture_name, array $actual): void
    {
        $expected = $this->load_fixture($fixture_name);
        $this->assertSame(wp_json_encode($expected), wp_json_encode($actual), 'Fixture mismatch: ' . $fixture_name);
    }

    private function write_json(string $file, array $payload): void
    {
        file_put_contents($file, wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function remove_directory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = trailingslashit($directory) . $entry;
            if (is_dir($path)) {
                $this->remove_directory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }

    private function reset_rest_server(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
    }
}
