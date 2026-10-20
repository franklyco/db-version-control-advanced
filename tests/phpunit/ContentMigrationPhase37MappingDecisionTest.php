<?php

class ContentMigrationPhase37MappingDecisionTest extends WP_UnitTestCase
{
    private $domain_key = 'phase37-mapping.test';
    private $relative_path = 'home';
    private $domain_dir = '';
    private $original_settings = [];

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        if (class_exists('DBVC_CC_Contracts')) {
            DBVC_CC_Contracts::ensure_phase_zero_defaults();
            update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_WORKBENCH, '1');
            update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_CATALOG_BRIDGE, '1');
            update_option(DBVC_CC_Contracts::OPTION_FLAG_MEDIA_MAPPING_BRIDGE, '1');
        }

        $this->original_settings = class_exists('DBVC_CC_Settings_Service')
            ? DBVC_CC_Settings_Service::get_options()
            : [];

        if (! empty($this->original_settings) && class_exists('DBVC_CC_Contracts')) {
            $settings = $this->original_settings;
            $settings['storage_path'] = DBVC_CC_Contracts::STORAGE_DEFAULT_PATH;
            update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $settings);
        }

        register_post_meta('page', 'hero_subheading', ['type' => 'string', 'single' => true, 'show_in_rest' => true]);
        register_post_meta('page', 'faq_question', ['type' => 'string', 'single' => false, 'show_in_rest' => true]);
        register_post_meta('page', 'faq_answer', ['type' => 'string', 'single' => false, 'show_in_rest' => true]);
        register_post_meta('page', 'cta_button_url', ['type' => 'string', 'single' => true, 'show_in_rest' => true]);
        if (! post_type_exists('service')) {
            register_post_type('service', ['public' => true, 'label' => 'Service']);
        }

        $this->build_fixture_artifacts();
    }

    public function tear_down(): void
    {
        if (is_string($this->domain_dir) && $this->domain_dir !== '') {
            $this->remove_directory($this->domain_dir);
        }

        if (! empty($this->original_settings) && class_exists('DBVC_CC_Contracts')) {
            update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $this->original_settings);
        }

        parent::tear_down();
    }

    public function test_section_candidates_and_mapping_decision_services(): void
    {
        DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, true);

        $candidates_result = DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates(
            $this->domain_key,
            $this->relative_path,
            true
        );

        $this->assertIsArray($candidates_result);
        $this->assertSame('built', $candidates_result['status']);
        $this->assertArrayHasKey('section_candidates', $candidates_result);

        $payload = is_array($candidates_result['section_candidates']) ? $candidates_result['section_candidates'] : [];
        $this->assertSame('section-field-candidates.v1', $payload['artifact_type']);

        $sections = isset($payload['sections']) && is_array($payload['sections']) ? $payload['sections'] : [];
        $this->assertCount(2, $sections);

        $hero_row = $this->find_section($sections, 'dbvc_cc_sec_hero');
        $faq_row = $this->find_section($sections, 'dbvc_cc_sec_faq');

        $this->assertSame('hero', $hero_row['section_archetype']);
        $this->assertGreaterThanOrEqual(2, count($hero_row['deterministic_candidates']));
        $this->assertSame('faq', $faq_row['section_archetype']);
        $this->assertGreaterThanOrEqual(1, count($faq_row['deterministic_candidates']));

        $decision_result = DBVC_CC_Mapping_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'pending',
                'approved_mappings' => [
                    [
                        'source_section_id' => 'dbvc_cc_sec_hero',
                        'target_ref' => 'core:post_title',
                        'confidence' => 0.92,
                    ],
                ],
                'object_hints' => [
                    'override_post_type' => 'service',
                ],
                'unresolved_fields' => [],
            ],
            get_current_user_id()
        );

        $this->assertIsArray($decision_result);
        $this->assertSame('saved', $decision_result['status']);
        $decision = is_array($decision_result['mapping_decision']) ? $decision_result['mapping_decision'] : [];
        $this->assertSame('approved', $decision['decision_status']);
        $this->assertSame('mapping-decisions.v1', $decision['artifact_type']);
        $this->assertArrayHasKey('object_hints', $decision);
        $this->assertSame('service', (string) $decision['object_hints']['override_post_type']);

        $loaded_decision = DBVC_CC_Mapping_Decision_Service::get_instance()->get_decision($this->domain_key, $this->relative_path);
        $this->assertIsArray($loaded_decision);
        $this->assertSame('loaded', $loaded_decision['status']);
        $loaded_payload = is_array($loaded_decision['mapping_decision']) ? $loaded_decision['mapping_decision'] : [];
        $this->assertSame('approved', $loaded_payload['decision_status']);
        $this->assertSame('service', (string) $loaded_payload['object_hints']['override_post_type']);
    }

    public function test_mapping_rest_endpoints_persist_decisions(): void
    {
        DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, true);
        $controller = DBVC_CC_Workbench_REST_Controller::get_instance();

        $build_request = new WP_REST_Request('GET', '/dbvc_cc/v1/mapping/candidates');
        $build_request->set_param('domain', $this->domain_key);
        $build_request->set_param('path', $this->relative_path);
        $build_request->set_param('force_rebuild', true);
        $build_response = $controller->get_mapping_candidates($build_request);
        $this->assertInstanceOf(WP_REST_Response::class, $build_response);
        $build_payload = $build_response->get_data();
        $this->assertSame('built', $build_payload['status']);

        $save_request = new WP_REST_Request('POST', '/dbvc_cc/v1/mapping/decision');
        $save_request->set_body_params(
            [
                'domain' => $this->domain_key,
                'path' => $this->relative_path,
                'decision_status' => 'pending',
                'approved_mappings' => [
                    ['source_section_id' => 'dbvc_cc_sec_hero', 'target_ref' => 'core:post_title'],
                ],
                'unresolved_fields' => [],
            ]
        );
        $save_response = $controller->save_mapping_decision($save_request);
        $this->assertInstanceOf(WP_REST_Response::class, $save_response);
        $save_payload = $save_response->get_data();
        $this->assertSame('saved', $save_payload['status']);

        $load_request = new WP_REST_Request('GET', '/dbvc_cc/v1/mapping/decision');
        $load_request->set_param('domain', $this->domain_key);
        $load_request->set_param('path', $this->relative_path);
        $load_response = $controller->get_mapping_decision($load_request);
        $this->assertInstanceOf(WP_REST_Response::class, $load_response);
        $load_payload = $load_response->get_data();
        $this->assertSame('loaded', $load_payload['status']);
    }

    public function test_workbench_domains_endpoint_returns_crawl_domains(): void
    {
        $controller = DBVC_CC_Workbench_REST_Controller::get_instance();
        $response = $controller->dbvc_cc_get_domains();

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $payload = $response->get_data();
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('domains', $payload);

        $domains = isset($payload['domains']) && is_array($payload['domains']) ? $payload['domains'] : [];
        $this->assertNotEmpty($domains);

        $keys = array_map(
            static function ($row): string {
                return is_array($row) && isset($row['key']) ? (string) $row['key'] : '';
            },
            $domains
        );

        $this->assertContains($this->domain_key, $keys);
    }

    public function test_media_decision_rest_endpoints_persist_decisions(): void
    {
        DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, true);
        DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);

        $controller = DBVC_CC_Media_REST_Controller::get_instance();

        $save_request = new WP_REST_Request('POST', '/dbvc_cc/v1/mapping/media/decision');
        $save_request->set_body_params(
            [
                'domain' => $this->domain_key,
                'path' => $this->relative_path,
                'decision_status' => 'pending',
                'approved' => [
                    [
                        'media_id' => 'dbvc_cc_media_1',
                        'target_ref' => 'core:featured_image',
                    ],
                ],
                'conflicts' => [],
            ]
        );
        $save_response = $controller->save_decision($save_request);
        $this->assertInstanceOf(WP_REST_Response::class, $save_response);
        $save_payload = $save_response->get_data();
        $this->assertSame('saved', $save_payload['status']);
        $this->assertSame('approved', $save_payload['media_decision']['decision_status']);

        $load_request = new WP_REST_Request('GET', '/dbvc_cc/v1/mapping/media/decision');
        $load_request->set_param('domain', $this->domain_key);
        $load_request->set_param('path', $this->relative_path);
        $load_response = $controller->get_decision($load_request);
        $this->assertInstanceOf(WP_REST_Response::class, $load_response);
        $load_payload = $load_response->get_data();
        $this->assertSame('loaded', $load_payload['status']);
    }

    public function test_review_queue_includes_mapping_health_state(): void
    {
        $payload = DBVC_CC_Workbench_Service::get_instance()->get_review_queue(
            [
                'domain' => $this->domain_key,
                'limit' => 10,
                'include_decided' => true,
                'min_confidence' => 0.75,
            ]
        );

        $this->assertIsArray($payload);
        $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
        $this->assertNotEmpty($items);

        $matched = null;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            if ((string) ($item['domain'] ?? '') !== $this->domain_key || (string) ($item['path'] ?? '') !== $this->relative_path) {
                continue;
            }
            $matched = $item;
            break;
        }

        $this->assertIsArray($matched);
        $this->assertArrayHasKey('mapping_health', $matched);
        $health = isset($matched['mapping_health']) && is_array($matched['mapping_health']) ? $matched['mapping_health'] : [];
        $this->assertArrayHasKey('stale', $health);
        $this->assertArrayHasKey('reasons', $health);
    }

    public function test_mapping_domain_rebuild_endpoint_rebuilds_artifacts(): void
    {
        DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, true);
        DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);
        DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);

        $page_dir = trailingslashit($this->domain_dir) . $this->relative_path;
        $slug = 'home';
        $main_file = trailingslashit($page_dir) . $slug . '.json';
        $section_candidates_file = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_SECTION_FIELD_CANDIDATES_V1_SUFFIX;
        $media_candidates_file = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_MEDIA_CANDIDATES_V1_SUFFIX;

        $this->assertFileExists($main_file);
        $this->assertFileExists($section_candidates_file);
        $this->assertFileExists($media_candidates_file);

        $section_before = (int) filemtime($section_candidates_file);
        $media_before = (int) filemtime($media_candidates_file);

        sleep(1);
        $main_payload = json_decode((string) file_get_contents($main_file), true);
        $this->assertIsArray($main_payload);
        $main_payload['provenance']['content_hash'] = hash('sha256', 'phase37-mapping-home-refresh');
        file_put_contents($main_file, wp_json_encode($main_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $controller = DBVC_CC_Workbench_REST_Controller::get_instance();
        $request = new WP_REST_Request('POST', '/dbvc_cc/v1/mapping/domain/rebuild');
        $request->set_body_params(
            [
                'domain' => $this->domain_key,
                'refresh_catalog' => true,
                'force_rebuild' => true,
                'run_now' => true,
                'batch_size' => 10,
            ]
        );

        $response = $controller->dbvc_cc_rebuild_mapping_domain($request);
        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $payload = $response->get_data();
        $this->assertIsArray($payload);
        $this->assertContains((string) $payload['status'], ['completed', 'completed_with_failures']);
        $this->assertNotEmpty((string) $payload['batch_id']);
        $this->assertSame($this->domain_key, (string) $payload['domain']);
        $this->assertGreaterThanOrEqual(1, (int) $payload['total_jobs']);
        $this->assertGreaterThanOrEqual(1, (int) $payload['processed_jobs']);
        $this->assertSame(100, (int) $payload['progress_percent']);

        $section_after = (int) filemtime($section_candidates_file);
        $media_after = (int) filemtime($media_candidates_file);
        $this->assertGreaterThan($section_before, $section_after);
        $this->assertGreaterThan($media_before, $media_after);
    }

    public function test_mapping_domain_rebuild_status_endpoint_returns_batch_progress(): void
    {
        DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, true);
        DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);
        DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);

        $controller = DBVC_CC_Workbench_REST_Controller::get_instance();
        $rebuild_request = new WP_REST_Request('POST', '/dbvc_cc/v1/mapping/domain/rebuild');
        $rebuild_request->set_body_params(
            [
                'domain' => $this->domain_key,
                'refresh_catalog' => true,
                'force_rebuild' => true,
                'run_now' => true,
                'batch_size' => 5,
            ]
        );

        $rebuild_response = $controller->dbvc_cc_rebuild_mapping_domain($rebuild_request);
        $this->assertInstanceOf(WP_REST_Response::class, $rebuild_response);
        $rebuild_payload = $rebuild_response->get_data();
        $batch_id = isset($rebuild_payload['batch_id']) ? (string) $rebuild_payload['batch_id'] : '';
        $this->assertNotSame('', $batch_id);

        $status_request = new WP_REST_Request('GET', '/dbvc_cc/v1/mapping/domain/rebuild/status');
        $status_request->set_param('batch_id', $batch_id);
        $status_response = $controller->dbvc_cc_get_mapping_rebuild_batch_status($status_request);
        $this->assertInstanceOf(WP_REST_Response::class, $status_response);
        $status_payload = $status_response->get_data();

        $this->assertIsArray($status_payload);
        $this->assertSame($batch_id, (string) $status_payload['batch_id']);
        $this->assertContains((string) $status_payload['status'], ['completed', 'completed_with_failures']);
        $this->assertSame(100, (int) $status_payload['progress_percent']);
        $this->assertGreaterThanOrEqual(1, (int) $status_payload['total_jobs']);
    }

    public function test_mapping_domain_rebuild_endpoint_rejects_unknown_domain(): void
    {
        $controller = DBVC_CC_Workbench_REST_Controller::get_instance();
        $request = new WP_REST_Request('POST', '/dbvc_cc/v1/mapping/domain/rebuild');
        $request->set_body_params(
            [
                'domain' => 'does-not-exist.test',
                'refresh_catalog' => true,
                'force_rebuild' => true,
            ]
        );

        $response = $controller->dbvc_cc_rebuild_mapping_domain($request);
        $this->assertWPError($response);
        $this->assertSame(404, (int) $response->get_error_data()['status']);
    }

    private function find_section(array $sections, string $section_id): array
    {
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            if (isset($section['section_id']) && (string) $section['section_id'] === $section_id) {
                return $section;
            }
        }

        return [];
    }

    private function build_fixture_artifacts(): void
    {
        $upload_dir = wp_upload_dir();
        $base_dir = isset($upload_dir['basedir']) ? (string) $upload_dir['basedir'] : '';
        $storage_dir = trailingslashit($base_dir) . 'contentcollector';
        $this->domain_dir = trailingslashit($storage_dir) . $this->domain_key;
        $page_dir = trailingslashit($this->domain_dir) . $this->relative_path;

        wp_mkdir_p($page_dir);

        $slug = 'home';
        $source_url = 'https://' . $this->domain_key . '/';

        $main_artifact = [
            'page_name' => 'Home',
            'slug' => $slug,
            'source_url' => $source_url,
            'provenance' => [
                'source_url' => $source_url,
                'canonical_source_url' => $source_url,
                'content_hash' => hash('sha256', 'phase37-mapping-home'),
            ],
            'content' => [
                'images' => [
                    '/wp-content/uploads/2026/03/hero.jpg',
                ],
                'sections' => [
                    [
                        'id' => 'dbvc_cc_sec_hero',
                        'heading' => 'Hero',
                        'images' => [
                            ['url' => 'https://phase37-mapping.test/wp-content/uploads/2026/03/hero-inline.jpg'],
                        ],
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
            'processing' => [
                'is_partial' => false,
                'partial_reason' => '',
                'resume_marker' => new stdClass(),
            ],
            'elements' => [
                [
                    'element_id' => 'dbvc_cc_el_hero_h1',
                    'tag' => 'h1',
                    'text' => 'Managed IT Services',
                    'sequence_index' => 1,
                    'attributes' => [],
                ],
                [
                    'element_id' => 'dbvc_cc_el_hero_img',
                    'tag' => 'img',
                    'text' => 'Hero image',
                    'sequence_index' => 2,
                    'attributes' => [
                        'src' => '/wp-content/uploads/2026/03/hero.jpg',
                        'alt' => 'Hero photo',
                    ],
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
                    'text' => 'How quickly can onboarding begin?',
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
            'processing' => [
                'is_partial' => false,
                'partial_reason' => '',
                'resume_marker' => new stdClass(),
            ],
            'sections' => [
                [
                    'section_id' => 'dbvc_cc_sec_hero',
                    'order' => 1,
                    'section_label_candidate' => 'Hero',
                    'start_sequence_index' => 1,
                    'end_sequence_index' => 2,
                    'element_ids' => ['dbvc_cc_el_hero_h1', 'dbvc_cc_el_hero_img'],
                ],
                [
                    'section_id' => 'dbvc_cc_sec_faq',
                    'order' => 2,
                    'section_label_candidate' => 'FAQ',
                    'start_sequence_index' => 3,
                    'end_sequence_index' => 4,
                    'element_ids' => ['dbvc_cc_el_faq_h2', 'dbvc_cc_el_faq_p'],
                ],
            ],
        ];

        $section_typing_v2 = [
            'artifact_schema_version' => '2.0.0',
            'artifact_type' => 'section-typing.v2',
            'source_url' => $source_url,
            'section_typings' => [
                [
                    'section_id' => 'dbvc_cc_sec_hero',
                    'section_type_candidate' => 'hero',
                    'mode' => 'deterministic',
                ],
                [
                    'section_id' => 'dbvc_cc_sec_faq',
                    'section_type_candidate' => 'faq',
                    'mode' => 'deterministic',
                ],
            ],
        ];

        $this->write_json($page_dir . '/' . $slug . '.json', $main_artifact);
        $this->write_json($page_dir . '/' . $slug . '.elements.v2.json', $elements_v2);
        $this->write_json($page_dir . '/' . $slug . '.sections.v2.json', $sections_v2);
        $this->write_json($page_dir . '/' . $slug . '.section-typing.v2.json', $section_typing_v2);
        $this->write_json(
            $page_dir . '/' . $slug . '.mapping.suggestions.json',
            [
                'source_url' => $source_url,
                'review' => [
                    'needs_review' => true,
                    'reasons' => ['low_confidence'],
                    'confidence_threshold' => 0.75,
                ],
                'conflicts' => [],
                'suggestions' => [
                    'post_type' => [
                        'value' => 'page',
                        'confidence' => 0.42,
                    ],
                    'taxonomy' => [
                        'terms' => [],
                    ],
                    'custom_fields' => [],
                    'media_roles' => [],
                ],
            ]
        );
    }

    private function write_json(string $file, array $payload): void
    {
        file_put_contents($file, wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function remove_directory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if (! is_array($entries)) {
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
}
