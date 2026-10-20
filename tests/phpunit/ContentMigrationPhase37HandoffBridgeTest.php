<?php

class ContentMigrationPhase37HandoffBridgeTest extends WP_UnitTestCase
{
    private $domain_key = 'phase37-handoff.test';
    private $relative_path = 'home';
    private $domain_dir = '';
    private $original_settings = [];

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_WORKBENCH, '1');
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_CATALOG_BRIDGE, '1');
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MEDIA_MAPPING_BRIDGE, '1');

        $this->original_settings = DBVC_CC_Settings_Service::get_options();
        $settings = $this->original_settings;
        $settings['storage_path'] = DBVC_CC_Contracts::STORAGE_DEFAULT_PATH;
        update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $settings);

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

        if (! empty($this->original_settings)) {
            update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $this->original_settings);
        }

        parent::tear_down();
    }

    public function test_handoff_payload_reflects_mapping_readiness_states(): void
    {
        DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, true);
        DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);
        DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);

        $pending_handoff = DBVC_CC_Import_Plan_Handoff_Service::get_instance()->get_handoff_payload($this->domain_key, $this->relative_path, true);
        $this->assertIsArray($pending_handoff);
        $this->assertSame('needs_review', $pending_handoff['status']);
        $this->assertSame('1.1.0', (string) $pending_handoff['handoff_schema_version']);
        $this->assertNotEmpty($pending_handoff['handoff_generated_at']);
        $this->assertArrayHasKey('trace', $pending_handoff);
        $this->assertArrayHasKey('artifact_refs', $pending_handoff['trace']);
        $this->assertArrayHasKey('review', $pending_handoff);
        $this->assertTrue((bool) $pending_handoff['review']['needs_review']);
        $this->assertArrayHasKey('phase4_input', $pending_handoff);
        $this->assertSame(true, $pending_handoff['phase4_input']['dry_run_required']);
        $this->assertSame(true, $pending_handoff['phase4_input']['idempotent_upsert_required']);
        $this->assertArrayHasKey('default_entity_key', $pending_handoff['phase4_input']);
        $this->assertSame('post:page', (string) $pending_handoff['phase4_input']['default_entity_key']);
        $this->assertFixtureMatches('handoff-needs-review.expected.json', $this->snapshot_handoff_contract($pending_handoff));

        DBVC_CC_Mapping_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'approved',
                'approved_mappings' => [
                    ['section_id' => 'dbvc_cc_sec_hero', 'target_ref' => 'core:post_title'],
                    ['section_id' => 'dbvc_cc_sec_faq', 'target_ref' => 'meta:post:page:faq_question'],
                ],
                'overrides' => [],
                'rejections' => [],
                'unresolved_fields' => [],
                'unresolved_media' => [],
            ],
            get_current_user_id()
        );

        DBVC_CC_Media_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'approved',
                'approved' => [
                    ['media_id' => 'dbvc_cc_med_hero', 'target_ref' => 'core:featured_image', 'source_url' => 'https://phase37-handoff.test/wp-content/uploads/2026/03/hero.jpg'],
                ],
                'overrides' => [],
                'ignored' => [],
                'conflicts' => [],
            ],
            get_current_user_id()
        );

        $ready_handoff = DBVC_CC_Import_Plan_Handoff_Service::get_instance()->get_handoff_payload($this->domain_key, $this->relative_path, false);
        $this->assertIsArray($ready_handoff);
        $this->assertSame('ready', $ready_handoff['status']);
        $this->assertSame('approved', $ready_handoff['mapping_decision_summary']['decision_status']);
        $this->assertSame('approved', $ready_handoff['media_decision_summary']['decision_status']);
        $this->assertSame(2, (int) $ready_handoff['mapping_decision_summary']['approved_count']);
        $this->assertSame(1, (int) $ready_handoff['media_decision_summary']['approved_count']);
        $this->assertSame($this->domain_key, $ready_handoff['phase4_input']['domain']);
        $this->assertSame($this->relative_path, $ready_handoff['phase4_input']['path']);
        $this->assertNotEmpty($ready_handoff['phase4_input']['catalog_fingerprint']);
        $this->assertArrayHasKey('default_entity_key', $ready_handoff['phase4_input']);
        $this->assertSame('post:page', (string) $ready_handoff['phase4_input']['default_entity_key']);
        $this->assertSame(0, (int) $ready_handoff['blocking_warning_count']);
        $this->assertArrayHasKey('review', $ready_handoff);
        $this->assertFalse((bool) $ready_handoff['review']['needs_review']);
        $this->assertFixtureMatches('handoff-ready.expected.json', $this->snapshot_handoff_contract($ready_handoff));
    }

    public function test_mapping_handoff_endpoint_rejects_invalid_path_and_enforces_permissions(): void
    {
        $controller = DBVC_CC_Workbench_REST_Controller::get_instance();
        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);
        $this->assertFalse($controller->permissions_check());

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertTrue($controller->permissions_check());

        $request = new WP_REST_Request('GET', '/dbvc_cc/v1/mapping/handoff');
        $request->set_param('domain', $this->domain_key);
        $request->set_param('path', '../../etc/passwd');

        $response = $controller->get_mapping_handoff($request);
        $this->assertWPError($response);
        $this->assertSame(400, (int) $response->get_error_data()['status']);
    }

    public function test_handoff_service_rejects_invalid_path_with_400(): void
    {
        $result = DBVC_CC_Import_Plan_Handoff_Service::get_instance()->get_handoff_payload(
            $this->domain_key,
            '../../etc/passwd',
            false
        );

        $this->assertWPError($result);
        $this->assertSame(400, (int) $result->get_error_data()['status']);
    }

    public function test_mapping_handoff_endpoint_returns_payload_for_valid_input(): void
    {
        DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, true);
        DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);

        $controller = DBVC_CC_Workbench_REST_Controller::get_instance();
        $request = new WP_REST_Request('GET', '/dbvc_cc/v1/mapping/handoff');
        $request->set_param('domain', $this->domain_key);
        $request->set_param('path', $this->relative_path);
        $request->set_param('build_if_missing', true);

        $response = $controller->get_mapping_handoff($request);
        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $payload = $response->get_data();
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('phase4_input', $payload);
        $this->assertSame($this->domain_key, $payload['domain']);
        $this->assertSame($this->relative_path, $payload['path']);
        $this->assertSame('1.1.0', (string) $payload['handoff_schema_version']);
    }

    public function test_handoff_flags_outdated_decisions_after_candidate_refresh(): void
    {
        DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, true);
        DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);
        DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);

        DBVC_CC_Mapping_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'approved',
                'approved_mappings' => [
                    ['section_id' => 'dbvc_cc_sec_hero', 'target_ref' => 'core:post_title'],
                ],
                'overrides' => [],
                'rejections' => [],
                'unresolved_fields' => [],
                'unresolved_media' => [],
            ],
            get_current_user_id()
        );
        DBVC_CC_Media_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'approved',
                'approved' => [
                    ['media_id' => 'dbvc_cc_med_hero', 'target_ref' => 'core:featured_image'],
                ],
                'overrides' => [],
                'ignored' => [],
                'conflicts' => [],
            ],
            get_current_user_id()
        );

        sleep(1);
        DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);
        DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);

        $handoff = DBVC_CC_Import_Plan_Handoff_Service::get_instance()->get_handoff_payload($this->domain_key, $this->relative_path, false);
        $this->assertIsArray($handoff);
        $this->assertSame('needs_review', $handoff['status']);
        $this->assertGreaterThanOrEqual(1, (int) $handoff['blocking_warning_count']);

        $warning_codes = [];
        $warnings = isset($handoff['warnings']) && is_array($handoff['warnings']) ? $handoff['warnings'] : [];
        foreach ($warnings as $warning) {
            if (! is_array($warning) || empty($warning['code'])) {
                continue;
            }
            $warning_codes[] = (string) $warning['code'];
        }

        $this->assertContains('mapping_decision_outdated', $warning_codes);
        $this->assertContains('media_decision_outdated', $warning_codes);
    }

    public function test_import_plan_dry_run_service_and_endpoint_return_phase4_payload(): void
    {
        DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, true);
        DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);
        DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);

        DBVC_CC_Mapping_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'approved',
                'approved_mappings' => [
                    ['section_id' => 'dbvc_cc_sec_hero', 'target_ref' => 'core:post_title'],
                    ['section_id' => 'dbvc_cc_sec_faq', 'target_ref' => 'meta:post:page:faq_question'],
                ],
                'overrides' => [],
                'rejections' => [],
                'unresolved_fields' => [],
                'unresolved_media' => [],
            ],
            get_current_user_id()
        );

        DBVC_CC_Media_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'approved',
                'approved' => [
                    ['media_id' => 'dbvc_cc_med_hero', 'target_ref' => 'core:featured_image'],
                ],
                'overrides' => [],
                'ignored' => [],
                'conflicts' => [],
            ],
            get_current_user_id()
        );

        $service_payload = DBVC_CC_Import_Plan_Service::get_instance()->get_dry_run_plan($this->domain_key, $this->relative_path, false);
        $this->assertIsArray($service_payload);
        $this->assertSame('ready', (string) $service_payload['status']);
        $this->assertTrue((bool) $service_payload['dry_run']);
        $this->assertSame('idempotent_upsert', (string) $service_payload['write_strategy']);
        $this->assertSame(0, (int) $service_payload['blocking_issue_count']);
        $this->assertSame(2, (int) $service_payload['operation_counts']['upsert_mappings']);
        $this->assertSame(1, (int) $service_payload['operation_counts']['upsert_media_mappings']);
        $this->assertArrayHasKey('phase4_input', $service_payload);
        $this->assertSame('1.1.0', (string) $service_payload['handoff_schema_version']);
        $this->assertNotEmpty((string) $service_payload['handoff_generated_at']);
        $this->assertArrayHasKey('handoff', $service_payload);
        $this->assertSame('1.1.0', (string) $service_payload['handoff']['schema_version']);
        $this->assertNotEmpty((string) $service_payload['handoff']['generated_at']);

        $controller = DBVC_CC_Import_Plan_REST_Controller::get_instance();
        $request = new WP_REST_Request('GET', '/dbvc_cc/v1/import-plan/dry-run');
        $request->set_param('domain', $this->domain_key);
        $request->set_param('path', $this->relative_path);
        $request->set_param('build_if_missing', false);
        $response = $controller->get_dry_run_plan($request);
        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $endpoint_payload = $response->get_data();
        $this->assertIsArray($endpoint_payload);
        $this->assertSame($this->domain_key, (string) $endpoint_payload['domain']);
        $this->assertSame($this->relative_path, (string) $endpoint_payload['path']);
        $this->assertSame('ready', (string) $endpoint_payload['status']);
        $this->assertNotEmpty((string) $endpoint_payload['plan_id']);
        $this->assertSame('1.1.0', (string) $endpoint_payload['handoff_schema_version']);
        $this->assertNotEmpty((string) $endpoint_payload['handoff_generated_at']);
    }

    public function test_import_plan_endpoint_rejects_invalid_path_and_enforces_permissions(): void
    {
        $controller = DBVC_CC_Import_Plan_REST_Controller::get_instance();
        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);
        $this->assertFalse($controller->permissions_check());

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertTrue($controller->permissions_check());

        $request = new WP_REST_Request('GET', '/dbvc_cc/v1/import-plan/dry-run');
        $request->set_param('domain', $this->domain_key);
        $request->set_param('path', '../../etc/passwd');
        $request->set_param('build_if_missing', false);

        $response = $controller->get_dry_run_plan($request);
        $this->assertWPError($response);
        $this->assertSame(400, (int) $response->get_error_data()['status']);
    }

    public function test_import_plan_service_rejects_invalid_path_with_400(): void
    {
        $result = DBVC_CC_Import_Plan_Service::get_instance()->get_dry_run_plan(
            $this->domain_key,
            '../../etc/passwd',
            false
        );

        $this->assertWPError($result);
        $this->assertSame(400, (int) $result->get_error_data()['status']);
    }

    public function test_handoff_uses_manual_object_hint_override(): void
    {
        DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, true);
        DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);
        DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates($this->domain_key, $this->relative_path, true);

        DBVC_CC_Mapping_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'approved',
                'approved_mappings' => [
                    ['section_id' => 'dbvc_cc_sec_hero', 'target_ref' => 'core:post_title'],
                ],
                'object_hints' => [
                    'override_post_type' => 'service',
                ],
                'overrides' => [],
                'rejections' => [],
                'unresolved_fields' => [],
                'unresolved_media' => [],
            ],
            get_current_user_id()
        );

        DBVC_CC_Media_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'approved',
                'approved' => [],
                'overrides' => [],
                'ignored' => [],
                'conflicts' => [],
            ],
            get_current_user_id()
        );

        $handoff = DBVC_CC_Import_Plan_Handoff_Service::get_instance()->get_handoff_payload($this->domain_key, $this->relative_path, false);
        $this->assertIsArray($handoff);
        $this->assertArrayHasKey('phase4_input', $handoff);
        $this->assertSame('post:service', (string) $handoff['phase4_input']['default_entity_key']);
        $this->assertArrayHasKey('object_hints', $handoff['phase4_input']);
        $this->assertSame('service', (string) $handoff['phase4_input']['object_hints']['override_post_type']);
        $this->assertSame('manual_override_post_type', (string) $handoff['phase4_input']['object_hints']['default_entity_reason']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function snapshot_handoff_contract(array $payload): array
    {
        $trace = isset($payload['trace']) && is_array($payload['trace']) ? $payload['trace'] : [];
        $artifact_refs = isset($trace['artifact_refs']) && is_array($trace['artifact_refs']) ? $trace['artifact_refs'] : [];
        ksort($artifact_refs);

        $phase4_input = isset($payload['phase4_input']) && is_array($payload['phase4_input']) ? $payload['phase4_input'] : [];
        $phase4_object_hints = isset($phase4_input['object_hints']) && is_array($phase4_input['object_hints']) ? $phase4_input['object_hints'] : [];

        $warning_codes = [];
        $warnings = isset($payload['warnings']) && is_array($payload['warnings']) ? $payload['warnings'] : [];
        foreach ($warnings as $warning) {
            if (! is_array($warning) || empty($warning['code'])) {
                continue;
            }
            $warning_codes[] = (string) $warning['code'] . ':' . (! empty($warning['blocking']) ? '1' : '0');
        }
        sort($warning_codes);

        $review = isset($payload['review']) && is_array($payload['review']) ? $payload['review'] : [];
        $review_reason_codes = [];
        $review_reasons = isset($review['reasons']) && is_array($review['reasons']) ? $review['reasons'] : [];
        foreach ($review_reasons as $review_reason) {
            if (! is_array($review_reason) || empty($review_reason['code'])) {
                continue;
            }
            $review_reason_codes[] = (string) $review_reason['code'] . ':' . (! empty($review_reason['blocking']) ? '1' : '0');
        }
        sort($review_reason_codes);

        return [
            'handoff_schema_version' => isset($payload['handoff_schema_version']) ? (string) $payload['handoff_schema_version'] : '',
            'status' => isset($payload['status']) ? (string) $payload['status'] : '',
            'domain' => isset($payload['domain']) ? (string) $payload['domain'] : '',
            'path' => isset($payload['path']) ? (string) $payload['path'] : '',
            'handoff_generated_at_present' => ! empty($payload['handoff_generated_at']),
            'source_url' => isset($payload['source_url']) ? (string) $payload['source_url'] : '',
            'blocking_warning_count' => isset($payload['blocking_warning_count']) ? (int) $payload['blocking_warning_count'] : -1,
            'warning_codes' => $warning_codes,
            'review' => [
                'needs_review' => ! empty($review['needs_review']),
                'reason_count' => isset($review['reason_count']) ? (int) $review['reason_count'] : 0,
                'reason_codes' => $review_reason_codes,
            ],
            'trace' => [
                'source_pipeline_id' => isset($trace['source_pipeline_id']) ? (string) $trace['source_pipeline_id'] : '',
                'artifact_ref_keys' => array_keys($artifact_refs),
                'artifact_ref_values_present' => [
                    'catalog' => ! empty($artifact_refs['catalog']),
                    'section_candidates' => ! empty($artifact_refs['section_candidates']),
                    'mapping_decision' => ! empty($artifact_refs['mapping_decision']),
                    'media_candidates' => ! empty($artifact_refs['media_candidates']),
                    'media_decision' => ! empty($artifact_refs['media_decision']),
                ],
            ],
            'mapping_summary' => [
                'decision_status' => isset($payload['mapping_decision_summary']['decision_status']) ? (string) $payload['mapping_decision_summary']['decision_status'] : '',
                'approved_count' => isset($payload['mapping_decision_summary']['approved_count']) ? (int) $payload['mapping_decision_summary']['approved_count'] : -1,
                'unresolved_field_count' => isset($payload['mapping_decision_summary']['unresolved_field_count']) ? (int) $payload['mapping_decision_summary']['unresolved_field_count'] : -1,
            ],
            'media_summary' => [
                'decision_status' => isset($payload['media_decision_summary']['decision_status']) ? (string) $payload['media_decision_summary']['decision_status'] : '',
                'approved_count' => isset($payload['media_decision_summary']['approved_count']) ? (int) $payload['media_decision_summary']['approved_count'] : -1,
                'conflict_count' => isset($payload['media_decision_summary']['conflict_count']) ? (int) $payload['media_decision_summary']['conflict_count'] : -1,
            ],
            'phase4_counts' => [
                'approved_mappings' => isset($phase4_input['approved_mappings']) && is_array($phase4_input['approved_mappings']) ? count($phase4_input['approved_mappings']) : 0,
                'approved_media_mappings' => isset($phase4_input['approved_media_mappings']) && is_array($phase4_input['approved_media_mappings']) ? count($phase4_input['approved_media_mappings']) : 0,
                'unresolved_fields' => isset($phase4_input['unresolved_fields']) && is_array($phase4_input['unresolved_fields']) ? count($phase4_input['unresolved_fields']) : 0,
                'media_conflicts' => isset($phase4_input['media_conflicts']) && is_array($phase4_input['media_conflicts']) ? count($phase4_input['media_conflicts']) : 0,
            ],
            'phase4_object_hints' => [
                'default_entity_key' => isset($phase4_input['default_entity_key']) ? (string) $phase4_input['default_entity_key'] : '',
                'default_entity_reason' => isset($phase4_object_hints['default_entity_reason']) ? (string) $phase4_object_hints['default_entity_reason'] : '',
                'override_post_type' => isset($phase4_object_hints['override_post_type']) ? (string) $phase4_object_hints['override_post_type'] : '',
                'suggested_post_type' => isset($phase4_object_hints['suggested_post_type']) ? (string) $phase4_object_hints['suggested_post_type'] : '',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function load_fixture(string $fixture_name): array
    {
        $fixture_path = dirname(__DIR__, 2) . '/addons/content-migration/tests/fixtures/mapping/' . $fixture_name;
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
                'content_hash' => hash('sha256', 'phase37-handoff-home'),
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
                            ['url' => 'https://phase37-handoff.test/wp-content/uploads/2026/03/hero-inline.jpg'],
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
