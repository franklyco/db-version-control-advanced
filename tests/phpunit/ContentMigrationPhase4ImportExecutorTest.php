<?php

class ContentMigrationPhase4ImportExecutorTest extends WP_UnitTestCase
{
    private $domain_key = 'phase4-executor.test';
    private $relative_path = 'home';
    private $domain_dir = '';
    private $original_settings = [];
    private $fake_media_ingest_attachment_ids = [];
    private $fake_media_ingest_files = [];

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->reset_import_run_ledger();

        DBVC_CC_Contracts::ensure_phase_zero_defaults();
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_WORKBENCH, '1');
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_CATALOG_BRIDGE, '1');
        update_option(DBVC_CC_Contracts::OPTION_FLAG_MEDIA_MAPPING_BRIDGE, '1');
        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '0');

        $this->original_settings = DBVC_CC_Settings_Service::get_options();
        $settings = $this->original_settings;
        $settings['storage_path'] = DBVC_CC_Contracts::STORAGE_DEFAULT_PATH;
        update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $settings);

        register_post_meta('page', 'hero_subheading', ['type' => 'string', 'single' => true, 'show_in_rest' => true]);
        register_post_meta('page', 'faq_question', ['type' => 'string', 'single' => false, 'show_in_rest' => true]);
        register_post_meta('page', 'faq_answer', ['type' => 'string', 'single' => false, 'show_in_rest' => true]);
        register_post_meta('page', 'cta_button_url', ['type' => 'string', 'single' => true, 'show_in_rest' => true]);
        register_post_meta('page', 'gallery_images', ['type' => 'array', 'single' => true]);
        register_post_meta('page', 'video_embed_url', ['type' => 'string', 'single' => true, 'show_in_rest' => true]);
        if (! post_type_exists('service')) {
            register_post_type('service', ['public' => true, 'label' => 'Service']);
        }

        add_filter('dbvc_cc_import_executor_media_ingest', [$this, 'fake_media_ingest_attachment'], 20, 4);

        $this->build_fixture_artifacts();
        $this->prepare_ready_mapping_inputs();
    }

    public function tear_down(): void
    {
        if (is_string($this->domain_dir) && $this->domain_dir !== '') {
            $this->remove_directory($this->domain_dir);
        }

        remove_filter('dbvc_cc_import_executor_media_ingest', [$this, 'fake_media_ingest_attachment'], 20);
        foreach ($this->fake_media_ingest_files as $file_path) {
            if (is_string($file_path) && $file_path !== '' && file_exists($file_path)) {
                @unlink($file_path);
            }
        }
        $this->fake_media_ingest_attachment_ids = [];
        $this->fake_media_ingest_files = [];

        $this->reset_import_run_ledger();

        if (! empty($this->original_settings)) {
            update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $this->original_settings);
        }

        parent::tear_down();
    }

    public function test_import_executor_dry_run_service_returns_deterministic_report(): void
    {
        $result = DBVC_CC_Import_Executor_Service::get_instance()->execute_dry_run($this->domain_key, $this->relative_path, false);
        $this->assertIsArray($result);
        $this->assertSame('1.8.0', (string) $result['executor_schema_version']);
        $this->assertSame('completed', (string) $result['status']);
        $this->assertTrue((bool) $result['dry_run']);
        $this->assertFalse((bool) $result['write_performed']);
        $this->assertSame($this->domain_key, (string) $result['domain']);
        $this->assertSame($this->relative_path, (string) $result['path']);
        $this->assertGreaterThanOrEqual(3, (int) $result['operation_counts']['total_simulated']);
        $this->assertGreaterThanOrEqual(1, (int) $result['operation_counts']['entity_resolutions']);
        $this->assertGreaterThanOrEqual(1, (int) $result['operation_counts']['entity_creates']);
        $this->assertArrayHasKey('operation_graph', $result);
        $this->assertIsArray($result['operation_graph']);
        $this->assertArrayHasKey('entity_operations', $result['operation_graph']);
        $this->assertArrayHasKey('field_operations', $result['operation_graph']);
        $this->assertArrayHasKey('media_operations', $result['operation_graph']);
        $this->assertNotEmpty($result['simulated_operations']);
        $this->assertArrayHasKey('dry_run_plan', $result);
        $this->assertArrayHasKey('trace', $result);
        $this->assertSame('ready', (string) $result['trace']['plan_status']);
        $this->assertArrayHasKey('phase4_context', $result);
        $this->assertSame('1.1.0', (string) $result['phase4_context']['handoff_schema_version']);
        $this->assertNotEmpty((string) $result['phase4_context']['handoff_generated_at']);
        $this->assertSame('1.1.0', (string) $result['trace']['handoff_schema_version']);
        $this->assertNotEmpty((string) $result['trace']['handoff_generated_at']);
        $first_entity = $result['operation_graph']['entity_operations'][0];
        $this->assertSame('would_create_new', (string) $first_entity['result']);
        $this->assertSame('create_new', (string) $first_entity['resolution_status']);
        $this->assertSame('deterministic_create', (string) $first_entity['resolution_strategy']);
    }

    public function test_import_executor_dry_run_endpoint_returns_payload(): void
    {
        $controller = DBVC_CC_Import_Executor_REST_Controller::get_instance();
        $request = new WP_REST_Request('GET', '/dbvc_cc/v1/import-executor/dry-run');
        $request->set_param('domain', $this->domain_key);
        $request->set_param('path', $this->relative_path);
        $request->set_param('build_if_missing', false);

        $response = $controller->get_dry_run_execution($request);
        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $payload = $response->get_data();
        $this->assertIsArray($payload);
        $this->assertSame('completed', (string) $payload['status']);
        $this->assertNotEmpty((string) $payload['execution_id']);
        $this->assertGreaterThanOrEqual(3, (int) $payload['operation_counts']['total_simulated']);
        $this->assertArrayHasKey('operation_graph', $payload);
        $this->assertArrayHasKey('entity_operations', $payload['operation_graph']);
        $this->assertArrayHasKey('phase4_context', $payload);
        $this->assertSame('1.1.0', (string) $payload['phase4_context']['handoff_schema_version']);
    }

    public function test_import_executor_preflight_approval_endpoint_returns_payload(): void
    {
        $controller = DBVC_CC_Import_Executor_REST_Controller::get_instance();
        $request = new WP_REST_Request('POST', '/dbvc_cc/v1/import-executor/preflight-approve');
        $request->set_param('domain', $this->domain_key);
        $request->set_param('path', $this->relative_path);
        $request->set_param('build_if_missing', false);
        $request->set_param('confirm_approval', true);

        $response = $controller->approve_preflight($request);
        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $payload = $response->get_data();
        $this->assertIsArray($payload);
        $this->assertSame('approved', (string) $payload['status']);
        $this->assertTrue((bool) $payload['approval_valid']);
        $this->assertArrayHasKey('approval', $payload);
        $this->assertNotEmpty((string) $payload['approval']['approval_token']);
    }

    public function test_import_executor_endpoints_enforce_permissions_and_reject_invalid_path(): void
    {
        $controller = DBVC_CC_Import_Executor_REST_Controller::get_instance();

        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);
        $this->assertFalse($controller->permissions_check());

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertTrue($controller->permissions_check());

        $dry_run_request = new WP_REST_Request('GET', '/dbvc_cc/v1/import-executor/dry-run');
        $dry_run_request->set_param('domain', $this->domain_key);
        $dry_run_request->set_param('path', '../../etc/passwd');
        $dry_run_request->set_param('build_if_missing', false);

        $dry_run_response = $controller->get_dry_run_execution($dry_run_request);
        $this->assertWPError($dry_run_response);
        $this->assertSame(400, (int) $dry_run_response->get_error_data()['status']);

        $approval_request = new WP_REST_Request('POST', '/dbvc_cc/v1/import-executor/preflight-approve');
        $approval_request->set_param('domain', $this->domain_key);
        $approval_request->set_param('path', '../../etc/passwd');
        $approval_request->set_param('build_if_missing', false);
        $approval_request->set_param('confirm_approval', true);

        $approval_response = $controller->approve_preflight($approval_request);
        $this->assertWPError($approval_response);
        $this->assertSame(400, (int) $approval_response->get_error_data()['status']);

        $approval_status_request = new WP_REST_Request('GET', '/dbvc_cc/v1/import-executor/preflight-status');
        $approval_status_request->set_param('domain', $this->domain_key);
        $approval_status_request->set_param('path', '../../etc/passwd');
        $approval_status_request->set_param('build_if_missing', false);

        $approval_status_response = $controller->get_preflight_status($approval_status_request);
        $this->assertWPError($approval_status_response);
        $this->assertSame(400, (int) $approval_status_response->get_error_data()['status']);

        $execute_request = new WP_REST_Request('POST', '/dbvc_cc/v1/import-executor/execute');
        $execute_request->set_param('domain', $this->domain_key);
        $execute_request->set_param('path', '../../etc/passwd');
        $execute_request->set_param('build_if_missing', false);
        $execute_request->set_param('confirm_execute', true);

        $execute_response = $controller->execute_write_skeleton($execute_request);
        $this->assertWPError($execute_response);
        $this->assertSame(400, (int) $execute_response->get_error_data()['status']);
    }

    public function test_import_executor_services_reject_invalid_path_with_400(): void
    {
        $dry_run_result = DBVC_CC_Import_Executor_Service::get_instance()->execute_dry_run(
            $this->domain_key,
            '../../etc/passwd',
            false
        );
        $this->assertWPError($dry_run_result);
        $this->assertSame(400, (int) $dry_run_result->get_error_data()['status']);

        $execute_result = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            $this->domain_key,
            '../../etc/passwd',
            false,
            true,
            'invalid'
        );
        $this->assertWPError($execute_result);
        $this->assertSame(400, (int) $execute_result->get_error_data()['status']);

        $approval_result = DBVC_CC_Import_Executor_Service::get_instance()->approve_preflight(
            $this->domain_key,
            '../../etc/passwd',
            false,
            true
        );
        $this->assertWPError($approval_result);
        $this->assertSame(400, (int) $approval_result->get_error_data()['status']);

        $approval_status_result = DBVC_CC_Import_Executor_Service::get_instance()->get_preflight_status(
            $this->domain_key,
            '../../etc/passwd',
            '',
            false
        );
        $this->assertWPError($approval_status_result);
        $this->assertSame(400, (int) $approval_status_result->get_error_data()['status']);
    }

    public function test_import_executor_dry_run_is_deterministic_across_reruns(): void
    {
        $first = DBVC_CC_Import_Executor_Service::get_instance()->execute_dry_run($this->domain_key, $this->relative_path, false);
        $second = DBVC_CC_Import_Executor_Service::get_instance()->execute_dry_run($this->domain_key, $this->relative_path, false);

        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $this->assertSame((string) $first['execution_id'], (string) $second['execution_id']);
        $this->assertSame(
            (string) $first['operation_graph']['graph_fingerprint'],
            (string) $second['operation_graph']['graph_fingerprint']
        );

        $first_ids = array_map(
            static function (array $operation): string {
                return isset($operation['operation_id']) ? (string) $operation['operation_id'] : '';
            },
            isset($first['simulated_operations']) && is_array($first['simulated_operations']) ? $first['simulated_operations'] : []
        );
        $second_ids = array_map(
            static function (array $operation): string {
                return isset($operation['operation_id']) ? (string) $operation['operation_id'] : '';
            },
            isset($second['simulated_operations']) && is_array($second['simulated_operations']) ? $second['simulated_operations'] : []
        );

        $this->assertSame($first_ids, $second_ids);
        $this->assertNotEmpty($first_ids);
    }

    public function test_import_executor_uses_default_entity_key_from_handoff_hints(): void
    {
        DBVC_CC_Mapping_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'approved',
                'approved_mappings' => [
                    ['section_id' => 'dbvc_cc_sec_hero', 'target_ref' => 'core:post_title'],
                    ['section_id' => 'dbvc_cc_sec_faq', 'target_ref' => 'core:post_content'],
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
                    ['media_id' => 'dbvc_cc_med_hero', 'target_ref' => 'core:featured_image', 'source_url' => 'https://phase4-executor.test/wp-content/uploads/2026/03/hero.jpg'],
                ],
                'overrides' => [],
                'ignored' => [],
                'conflicts' => [],
            ],
            get_current_user_id()
        );

        $result = DBVC_CC_Import_Executor_Service::get_instance()->execute_dry_run($this->domain_key, $this->relative_path, false);
        $this->assertIsArray($result);
        $this->assertSame('post:service', (string) $result['trace']['default_entity_key']);
        $this->assertNotEmpty($result['operation_graph']['entity_operations']);
        $first_entity = $result['operation_graph']['entity_operations'][0];
        $this->assertSame('post:service', (string) $first_entity['entity_key']);
        $this->assertSame('service', (string) $first_entity['entity_subtype']);
    }

    public function test_import_executor_matches_existing_page_by_path(): void
    {
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'home',
            'post_title' => 'Existing Home',
            'post_status' => 'publish',
        ]);

        $result = DBVC_CC_Import_Executor_Service::get_instance()->execute_dry_run($this->domain_key, $this->relative_path, false);
        $this->assertIsArray($result);
        $this->assertSame('completed', (string) $result['status']);
        $this->assertSame(1, (int) $result['operation_counts']['entity_updates']);
        $first_entity = $result['operation_graph']['entity_operations'][0];
        $this->assertSame('would_update_existing', (string) $first_entity['result']);
        $this->assertSame('matched_existing', (string) $first_entity['resolution_status']);
        $this->assertSame('page_path_match', (string) $first_entity['resolution_strategy']);
        $this->assertSame($page_id, (int) $first_entity['existing_entity_id']);
    }

    public function test_import_executor_matches_existing_page_by_source_path_meta(): void
    {
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'legacy-home',
            'post_title' => 'Legacy Home',
            'post_status' => 'publish',
        ]);
        add_post_meta($page_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH, $this->relative_path);

        $result = DBVC_CC_Import_Executor_Service::get_instance()->execute_dry_run($this->domain_key, $this->relative_path, false);
        $this->assertIsArray($result);
        $first_entity = $result['operation_graph']['entity_operations'][0];
        $this->assertSame('would_update_existing', (string) $first_entity['result']);
        $this->assertSame('matched_existing', (string) $first_entity['resolution_status']);
        $this->assertSame('source_path_meta', (string) $first_entity['resolution_strategy']);
        $this->assertSame($page_id, (int) $first_entity['existing_entity_id']);
    }

    public function test_import_executor_matches_existing_page_by_source_hash_meta(): void
    {
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'legacy-home-hash',
            'post_title' => 'Legacy Home Hash',
            'post_status' => 'publish',
        ]);
        add_post_meta(
            $page_id,
            DBVC_CC_Contracts::IMPORT_META_SOURCE_HASH,
            hash('sha256', wp_json_encode([
                'domain' => $this->domain_key,
                'path' => $this->relative_path,
            ]))
        );

        $result = DBVC_CC_Import_Executor_Service::get_instance()->execute_dry_run($this->domain_key, $this->relative_path, false);
        $this->assertIsArray($result);
        $first_entity = $result['operation_graph']['entity_operations'][0];
        $this->assertSame('would_update_existing', (string) $first_entity['result']);
        $this->assertSame('matched_existing', (string) $first_entity['resolution_status']);
        $this->assertSame('source_hash_meta', (string) $first_entity['resolution_strategy']);
        $this->assertSame($page_id, (int) $first_entity['existing_entity_id']);
    }

    public function test_import_executor_matches_existing_service_by_source_url_meta(): void
    {
        $service_id = self::factory()->post->create([
            'post_type' => 'service',
            'post_name' => 'managed-it-existing',
            'post_title' => 'Managed IT Existing',
            'post_status' => 'publish',
        ]);
        add_post_meta($service_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_URL, 'https://' . $this->domain_key . '/');

        DBVC_CC_Mapping_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'approved',
                'approved_mappings' => [
                    ['section_id' => 'dbvc_cc_sec_hero', 'target_ref' => 'core:post_title'],
                    ['section_id' => 'dbvc_cc_sec_faq', 'target_ref' => 'core:post_content'],
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

        $result = DBVC_CC_Import_Executor_Service::get_instance()->execute_dry_run($this->domain_key, $this->relative_path, false);
        $this->assertIsArray($result);
        $this->assertSame('completed', (string) $result['status']);
        $first_entity = $result['operation_graph']['entity_operations'][0];
        $this->assertSame('would_update_existing', (string) $first_entity['result']);
        $this->assertSame('matched_existing', (string) $first_entity['resolution_status']);
        $this->assertSame('source_url_meta', (string) $first_entity['resolution_strategy']);
        $this->assertSame($service_id, (int) $first_entity['existing_entity_id']);
    }

    public function test_import_executor_blocks_on_ambiguous_service_source_url_match(): void
    {
        $first_service_id = self::factory()->post->create([
            'post_type' => 'service',
            'post_name' => 'managed-it-existing-a',
            'post_title' => 'Managed IT Existing A',
            'post_status' => 'publish',
        ]);
        $second_service_id = self::factory()->post->create([
            'post_type' => 'service',
            'post_name' => 'managed-it-existing-b',
            'post_title' => 'Managed IT Existing B',
            'post_status' => 'draft',
        ]);
        add_post_meta($first_service_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_URL, 'https://' . $this->domain_key . '/');
        add_post_meta($second_service_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_URL, 'https://' . $this->domain_key . '/');

        DBVC_CC_Mapping_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'approved',
                'approved_mappings' => [
                    ['section_id' => 'dbvc_cc_sec_hero', 'target_ref' => 'core:post_title'],
                    ['section_id' => 'dbvc_cc_sec_faq', 'target_ref' => 'core:post_content'],
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

        $result = DBVC_CC_Import_Executor_Service::get_instance()->execute_dry_run($this->domain_key, $this->relative_path, false);
        $this->assertIsArray($result);
        $this->assertSame('blocked', (string) $result['status']);
        $this->assertGreaterThanOrEqual(1, (int) $result['blocking_issue_count']);
        $this->assertSame(1, (int) $result['operation_counts']['entity_blocked']);

        $issue_codes = array_map(
            static function (array $issue): string {
                return isset($issue['code']) ? (string) $issue['code'] : '';
            },
            isset($result['issues']) && is_array($result['issues']) ? $result['issues'] : []
        );
        $this->assertContains('entity_resolution_ambiguous', $issue_codes);

        $first_entity = $result['operation_graph']['entity_operations'][0];
        $this->assertSame('blocked_needs_review', (string) $first_entity['result']);
        $this->assertSame('ambiguous_match', (string) $first_entity['resolution_status']);
        $this->assertSame('source_url_meta', (string) $first_entity['resolution_strategy']);
        $this->assertSame(2, (int) $first_entity['candidate_count']);
    }

    public function test_import_executor_prefers_manual_object_hint_override_over_ai_hint(): void
    {
        DBVC_CC_Mapping_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'approved',
                'approved_mappings' => [
                    ['section_id' => 'dbvc_cc_sec_hero', 'target_ref' => 'core:post_title'],
                    ['section_id' => 'dbvc_cc_sec_faq', 'target_ref' => 'core:post_content'],
                ],
                'object_hints' => [
                    'override_post_type' => 'page',
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
                    ['media_id' => 'dbvc_cc_med_hero', 'target_ref' => 'core:featured_image', 'source_url' => 'https://phase4-executor.test/wp-content/uploads/2026/03/hero.jpg'],
                ],
                'overrides' => [],
                'ignored' => [],
                'conflicts' => [],
            ],
            get_current_user_id()
        );

        $result = DBVC_CC_Import_Executor_Service::get_instance()->execute_dry_run($this->domain_key, $this->relative_path, false);
        $this->assertIsArray($result);
        $this->assertSame('post:page', (string) $result['trace']['default_entity_key']);
        $this->assertNotEmpty($result['operation_graph']['entity_operations']);
        $first_entity = $result['operation_graph']['entity_operations'][0];
        $this->assertSame('post:page', (string) $first_entity['entity_key']);
        $this->assertSame('page', (string) $first_entity['entity_subtype']);
    }

    public function test_import_executor_preflight_approval_returns_token_for_ready_dry_run(): void
    {
        $result = DBVC_CC_Import_Executor_Service::get_instance()->approve_preflight(
            $this->domain_key,
            $this->relative_path,
            false,
            true
        );

        $this->assertIsArray($result);
        $this->assertSame('approved', (string) $result['status']);
        $this->assertTrue((bool) $result['approval_required']);
        $this->assertTrue((bool) $result['approval_eligible']);
        $this->assertTrue((bool) $result['approval_valid']);
        $this->assertTrue((bool) $result['execute_ready']);
        $this->assertArrayHasKey('approval', $result);
        $this->assertNotEmpty((string) $result['approval']['approval_id']);
        $this->assertNotEmpty((string) $result['approval']['approval_token']);
        $this->assertGreaterThan(0, (int) $result['approval']['expires_in']);
        $this->assertSame('post:page', (string) $result['summary']['default_entity_key']);
    }

    public function test_import_executor_execute_blocks_without_preflight_approval(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');
        $result = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            $this->domain_key,
            $this->relative_path,
            false,
            true,
            ''
        );

        $this->assertIsArray($result);
        $this->assertSame('blocked', (string) $result['status']);
        $this->assertFalse((bool) $result['write_performed']);
        $this->assertArrayHasKey('preflight_approval', $result);
        $this->assertSame('missing', (string) $result['preflight_approval']['status']);

        $guard_codes = array_map(
            static function (array $failure): string {
                return isset($failure['code']) ? (string) $failure['code'] : '';
            },
            isset($result['guard_failures']) && is_array($result['guard_failures']) ? $result['guard_failures'] : []
        );

        $this->assertContains('preflight_approval_required', $guard_codes);
    }

    public function test_import_executor_preflight_status_reports_stale_token_after_graph_changes(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');
        $approval_token = $this->approve_preflight_and_get_token();

        $first_execute = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            $this->domain_key,
            $this->relative_path,
            false,
            true,
            $approval_token
        );
        $this->assertIsArray($first_execute);
        $this->assertSame('completed', (string) $first_execute['status']);

        $status = DBVC_CC_Import_Executor_Service::get_instance()->get_preflight_status(
            $this->domain_key,
            $this->relative_path,
            $approval_token,
            false
        );
        $this->assertIsArray($status);
        $this->assertSame('stale', (string) $status['status']);
        $this->assertFalse((bool) $status['approval_valid']);

        $guard_codes = array_map(
            static function (array $failure): string {
                return isset($failure['code']) ? (string) $failure['code'] : '';
            },
            isset($status['guard_failures']) && is_array($status['guard_failures']) ? $status['guard_failures'] : []
        );
        $this->assertContains('preflight_approval_stale', $guard_codes);
    }

    public function test_import_executor_write_skeleton_blocks_when_feature_flag_disabled(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '0');
        $approval_token = $this->approve_preflight_and_get_token();
        $result = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            $this->domain_key,
            $this->relative_path,
            false,
            true,
            $approval_token
        );

        $this->assertIsArray($result);
        $this->assertSame('blocked', (string) $result['status']);
        $this->assertFalse((bool) $result['write_performed']);
        $this->assertArrayHasKey('phase4_context', $result);
        $this->assertSame('1.1.0', (string) $result['phase4_context']['handoff_schema_version']);

        $guard_codes = array_map(
            static function (array $failure): string {
                return isset($failure['code']) ? (string) $failure['code'] : '';
            },
            isset($result['guard_failures']) && is_array($result['guard_failures']) ? $result['guard_failures'] : []
        );

        $this->assertContains('write_feature_disabled', $guard_codes);
    }

    public function test_import_executor_execute_runs_entity_writes_when_guards_pass(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');
        $approval_token = $this->approve_preflight_and_get_token();
        $result = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            $this->domain_key,
            $this->relative_path,
            false,
            true,
            $approval_token
        );

        $this->assertIsArray($result);
        $this->assertSame('completed', (string) $result['status']);
        $this->assertTrue((bool) $result['write_performed']);
        $this->assertEmpty($result['guard_failures']);
        $this->assertEmpty($result['execution_failures']);
        $this->assertSame(['entity', 'field', 'media'], array_values($result['executed_stages']));
        $this->assertSame([], array_values($result['deferred_stages']));
        $this->assertArrayHasKey('write_preparation', $result);
        $this->assertSame('1.1.0', (string) $result['write_preparation']['write_plan_schema_version']);
        $this->assertNotEmpty((string) $result['write_preparation']['write_plan_id']);
        $this->assertEmpty($result['write_barriers']);
        $this->assertSame(1, (int) $result['operation_counts']['prepared_entity_writes']);
        $this->assertSame(2, (int) $result['operation_counts']['prepared_field_writes']);
        $this->assertSame(1, (int) $result['operation_counts']['prepared_media_writes']);
        $this->assertSame(0, (int) $result['operation_counts']['write_barrier_count']);
        $this->assertSame(1, (int) $result['operation_counts']['executed_entity_writes']);
        $this->assertSame(1, (int) $result['operation_counts']['executed_entity_creates']);
        $this->assertSame(0, (int) $result['operation_counts']['executed_entity_updates']);
        $this->assertSame(0, (int) $result['operation_counts']['failed_entity_writes']);
        $this->assertSame(2, (int) $result['operation_counts']['executed_field_writes']);
        $this->assertSame(0, (int) $result['operation_counts']['failed_field_writes']);
        $this->assertSame(0, (int) $result['operation_counts']['deferred_field_writes']);
        $this->assertSame(1, (int) $result['operation_counts']['executed_media_writes']);
        $this->assertSame(1, (int) $result['operation_counts']['created_media_attachments']);
        $this->assertSame(0, (int) $result['operation_counts']['reused_media_attachments']);
        $this->assertSame(0, (int) $result['operation_counts']['failed_media_writes']);
        $this->assertSame(0, (int) $result['operation_counts']['deferred_media_writes']);
        $this->assertNotEmpty($result['write_preparation']['entity_writes']);
        $this->assertNotEmpty($result['write_preparation']['field_writes']);
        $this->assertNotEmpty($result['write_preparation']['media_writes']);
        $this->assertSame(2, (int) $result['write_preparation']['source_context']['stats']['section_sources']);
        $this->assertGreaterThanOrEqual(1, (int) $result['write_preparation']['source_context']['stats']['media_sources']);
        $this->assertSame('Home', (string) $result['write_preparation']['source_context']['page']['page_title_candidate']);
        $entity_write = $result['write_preparation']['entity_writes'][0];
        $this->assertSame('prepared_create_new', (string) $entity_write['result']);
        $this->assertArrayHasKey('idempotency_meta', $entity_write);
        $this->assertSame('home', (string) $entity_write['idempotency_meta'][DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH]);
        $this->assertNotEmpty((string) $entity_write['idempotency_meta'][DBVC_CC_Contracts::IMPORT_META_SOURCE_HASH]);
        $this->assertArrayHasKey('source_payload', $entity_write);
        $this->assertSame('Home', (string) $entity_write['source_payload']['page_title_candidate']);

        $field_writes_by_section = [];
        foreach ($result['write_preparation']['field_writes'] as $field_write) {
            if (! is_array($field_write) || empty($field_write['section_id'])) {
                continue;
            }
            $field_writes_by_section[(string) $field_write['section_id']] = $field_write;
        }

        $this->assertArrayHasKey('dbvc_cc_sec_hero', $field_writes_by_section);
        $this->assertArrayHasKey('dbvc_cc_sec_faq', $field_writes_by_section);
        $this->assertSame(
            'Managed IT Services',
            (string) $field_writes_by_section['dbvc_cc_sec_hero']['resolved_value_candidate']
        );
        $this->assertSame(
            'section_primary_text',
            (string) $field_writes_by_section['dbvc_cc_sec_hero']['resolved_value_origin']
        );
        $this->assertArrayHasKey('source_payload', $field_writes_by_section['dbvc_cc_sec_faq']);
        $this->assertSame(
            'How quickly can onboarding begin?',
            (string) $field_writes_by_section['dbvc_cc_sec_faq']['source_payload']['body_texts'][0]
        );
        $this->assertIsArray($field_writes_by_section['dbvc_cc_sec_faq']['resolved_value_candidate']);

        $media_write = $result['write_preparation']['media_writes'][0];
        $this->assertArrayHasKey('source_payload', $media_write);
        $this->assertSame(
            'https://phase4-executor.test/wp-content/uploads/2026/03/hero.jpg',
            (string) $media_write['source_payload']['source_url']
        );
        $this->assertArrayHasKey('resolved_media_candidate', $media_write);
        $this->assertTrue((bool) $media_write['resolved_media_candidate']['preview_available']);
        $this->assertArrayHasKey('phase4_context', $result);
        $this->assertSame('1.1.0', (string) $result['phase4_context']['handoff_schema_version']);

        $this->assertArrayHasKey('entity_write_execution', $result);
        $this->assertSame(1, (int) $result['entity_write_execution']['counts']['completed']);
        $this->assertSame(1, (int) $result['entity_write_execution']['counts']['created']);
        $this->assertSame(0, (int) $result['entity_write_execution']['counts']['updated']);
        $this->assertSame(0, (int) $result['entity_write_execution']['counts']['failed']);
        $this->assertNotEmpty($result['entity_write_execution']['operations']);
        $this->assertArrayHasKey('field_write_execution', $result);
        $this->assertSame(2, (int) $result['field_write_execution']['counts']['completed']);
        $this->assertSame(0, (int) $result['field_write_execution']['counts']['failed']);
        $this->assertNotEmpty($result['field_write_execution']['operations']);
        $this->assertArrayHasKey('media_write_execution', $result);
        $this->assertSame(1, (int) $result['media_write_execution']['counts']['completed']);
        $this->assertSame(1, (int) $result['media_write_execution']['counts']['created']);
        $this->assertSame(0, (int) $result['media_write_execution']['counts']['reused']);
        $this->assertSame(0, (int) $result['media_write_execution']['counts']['failed']);
        $this->assertNotEmpty($result['media_write_execution']['operations']);

        $executed_entity = $result['entity_write_execution']['operations'][0];
        $this->assertSame('completed', (string) $executed_entity['status']);
        $this->assertSame('created', (string) $executed_entity['result']);
        $created_post_id = (int) $executed_entity['target_entity_id'];
        $this->assertGreaterThan(0, $created_post_id);
        $this->assertSame('page', get_post_type($created_post_id));
        $this->assertSame('Managed IT Services', get_the_title($created_post_id));
        $this->assertSame($this->relative_path, (string) get_post_meta($created_post_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH, true));
        $this->assertSame(
            'https://' . $this->domain_key . '/',
            (string) get_post_meta($created_post_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_URL, true)
        );
        $this->assertNotEmpty((string) get_post_meta($created_post_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_HASH, true));
        $this->assertSame(['How quickly can onboarding begin?'], get_post_meta($created_post_id, 'faq_question', false));
        $created_attachment_id = (int) get_post_thumbnail_id($created_post_id);
        $this->assertGreaterThan(0, $created_attachment_id);
        $this->assertSame(
            'https://phase4-executor.test/wp-content/uploads/2026/03/hero.jpg',
            (string) get_post_meta($created_attachment_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_URL, true)
        );
        $this->assertGreaterThan(0, (int) $result['run_id']);
        $this->assertNotEmpty((string) $result['run_uuid']);
        $this->assertTrue((bool) $result['rollback_available']);
        $this->assertSame('available', (string) $result['rollback_status']);
    }

    public function test_import_executor_defers_unsupported_media_target_without_blocking_execute(): void
    {
        DBVC_CC_Media_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'approved',
                'approved' => [
                    [
                        'media_id' => 'dbvc_cc_med_hero',
                        'target_ref' => 'core:post_content',
                        'source_url' => 'https://phase4-executor.test/wp-content/uploads/2026/03/hero.jpg',
                    ],
                ],
                'overrides' => [],
                'ignored' => [],
                'conflicts' => [],
            ],
            get_current_user_id()
        );

        $dry_run = DBVC_CC_Import_Executor_Service::get_instance()->execute_dry_run($this->domain_key, $this->relative_path, false);
        $this->assertIsArray($dry_run);
        $this->assertSame('completed', (string) $dry_run['status']);
        $this->assertSame(0, (int) $dry_run['operation_counts']['write_barrier_count']);
        $this->assertSame(1, (int) $dry_run['deferred_media_count']);
        $this->assertNotEmpty($dry_run['deferred_media_reasons']);
        $this->assertSame('unsupported_media_target', (string) $dry_run['deferred_media_reasons'][0]['code']);
        $this->assertSame('unsupported_shape', (string) $dry_run['deferred_media_reasons'][0]['group']);
        $this->assertSame('deferred', (string) $dry_run['write_preparation']['media_writes'][0]['write_status']);
        $this->assertFalse((bool) $dry_run['write_preparation']['media_writes'][0]['blocking']);

        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');
        $approval_token = $this->approve_preflight_and_get_token();
        $execute = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            $this->domain_key,
            $this->relative_path,
            false,
            true,
            $approval_token
        );

        $this->assertIsArray($execute);
        $this->assertSame('completed_partial', (string) $execute['status']);
        $this->assertTrue((bool) $execute['write_performed']);
        $this->assertSame(['entity', 'field'], array_values($execute['executed_stages']));
        $this->assertSame(['media'], array_values($execute['deferred_stages']));
        $this->assertSame(1, (int) $execute['deferred_media_count']);
        $this->assertSame(1, (int) $execute['operation_counts']['deferred_media_writes']);
        $this->assertSame(1, (int) $execute['operation_counts']['deferred_media_execution_writes']);
        $this->assertSame(0, (int) $execute['operation_counts']['executed_media_writes']);
        $this->assertSame(0, (int) $execute['operation_counts']['failed_media_writes']);
        $this->assertSame(1, (int) $execute['media_write_execution']['counts']['deferred']);
        $this->assertSame('deferred', (string) $execute['media_write_execution']['operations'][0]['status']);
        $this->assertSame('unsupported_media_target', (string) $execute['media_write_execution']['operations'][0]['deferred_reason_code']);

        $created_post_id = (int) $execute['entity_write_execution']['operations'][0]['target_entity_id'];
        $this->assertGreaterThan(0, $created_post_id);
        $this->assertSame(0, (int) get_post_thumbnail_id($created_post_id));
    }

    public function test_import_executor_defers_policy_blocked_media_without_blocking_execute(): void
    {
        $settings = DBVC_CC_Settings_Service::get_options();
        $settings['dbvc_cc_media_download_policy'] = DBVC_CC_Contracts::MEDIA_DOWNLOAD_POLICY_SKIP;
        update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $settings);

        $dry_run = DBVC_CC_Import_Executor_Service::get_instance()->execute_dry_run($this->domain_key, $this->relative_path, false);
        $this->assertIsArray($dry_run);
        $this->assertSame('completed', (string) $dry_run['status']);
        $this->assertSame(1, (int) $dry_run['deferred_media_count']);
        $this->assertSame('media_policy_skip', (string) $dry_run['deferred_media_reasons'][0]['code']);
        $this->assertSame('policy', (string) $dry_run['deferred_media_reasons'][0]['group']);
        $this->assertSame('deferred', (string) $dry_run['write_preparation']['media_writes'][0]['write_status']);

        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');
        $approval_token = $this->approve_preflight_and_get_token();
        $execute = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            $this->domain_key,
            $this->relative_path,
            false,
            true,
            $approval_token
        );

        $this->assertIsArray($execute);
        $this->assertSame('completed_partial', (string) $execute['status']);
        $this->assertSame(['entity', 'field'], array_values($execute['executed_stages']));
        $this->assertSame(['media'], array_values($execute['deferred_stages']));
        $this->assertSame(1, (int) $execute['deferred_media_count']);
        $this->assertSame(1, (int) $execute['operation_counts']['deferred_media_writes']);
        $this->assertSame(0, (int) $execute['operation_counts']['write_barrier_count']);
        $this->assertSame(0, (int) $execute['operation_counts']['executed_media_writes']);
        $this->assertSame(1, (int) $execute['media_write_execution']['counts']['deferred']);
        $this->assertSame('media_policy_skip', (string) $execute['media_write_execution']['operations'][0]['deferred_reason_code']);

        $created_post_id = (int) $execute['entity_write_execution']['operations'][0]['target_entity_id'];
        $this->assertGreaterThan(0, $created_post_id);
        $this->assertSame(0, (int) get_post_thumbnail_id($created_post_id));
    }

    public function test_import_executor_executes_gallery_media_list_and_replaces_on_rerun(): void
    {
        DBVC_CC_Media_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'approved',
                'approved' => [
                    [
                        'media_id' => 'dbvc_cc_med_gallery_hero',
                        'target_ref' => 'meta:post:page:gallery_images',
                        'source_url' => 'https://phase4-executor.test/wp-content/uploads/2026/03/hero.jpg',
                    ],
                    [
                        'media_id' => 'dbvc_cc_med_gallery_two',
                        'target_ref' => 'meta:post:page:gallery_images',
                        'source_url' => 'https://phase4-executor.test/wp-content/uploads/2026/03/gallery-two.jpg',
                    ],
                ],
                'overrides' => [],
                'ignored' => [],
                'conflicts' => [],
            ],
            get_current_user_id()
        );

        $dry_run = DBVC_CC_Import_Executor_Service::get_instance()->execute_dry_run($this->domain_key, $this->relative_path, false);
        $this->assertIsArray($dry_run);
        $this->assertSame('completed', (string) $dry_run['status']);
        $this->assertCount(1, $dry_run['write_preparation']['media_writes']);
        $this->assertSame('attachment_id_list', (string) $dry_run['write_preparation']['media_writes'][0]['storage_shape']);
        $this->assertSame('replace_attachment_list', (string) $dry_run['write_preparation']['media_writes'][0]['normalized_value_strategy']);
        $this->assertCount(2, $dry_run['write_preparation']['media_writes'][0]['media_ids']);

        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');
        $first_approval_token = $this->approve_preflight_and_get_token();
        $first_execute = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            $this->domain_key,
            $this->relative_path,
            false,
            true,
            $first_approval_token
        );

        $this->assertIsArray($first_execute);
        $this->assertSame('completed', (string) $first_execute['status']);
        $this->assertSame(1, (int) $first_execute['operation_counts']['executed_media_writes']);
        $this->assertSame(2, (int) $first_execute['operation_counts']['created_media_attachments']);
        $created_post_id = (int) $first_execute['entity_write_execution']['operations'][0]['target_entity_id'];
        $this->assertGreaterThan(0, $created_post_id);
        $first_gallery = get_post_meta($created_post_id, 'gallery_images', true);
        $this->assertIsArray($first_gallery);
        $first_gallery = array_values(array_map('intval', $first_gallery));
        $this->assertCount(2, $first_gallery);
        $this->assertSame(array_values(array_unique($first_gallery)), $first_gallery);

        DBVC_CC_Media_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'approved',
                'approved' => [
                    [
                        'media_id' => 'dbvc_cc_med_gallery_two',
                        'target_ref' => 'meta:post:page:gallery_images',
                        'source_url' => 'https://phase4-executor.test/wp-content/uploads/2026/03/gallery-two.jpg',
                    ],
                ],
                'overrides' => [],
                'ignored' => [],
                'conflicts' => [],
            ],
            get_current_user_id()
        );

        $second_approval_token = $this->approve_preflight_and_get_token();
        $second_execute = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            $this->domain_key,
            $this->relative_path,
            false,
            true,
            $second_approval_token
        );

        $this->assertIsArray($second_execute);
        $this->assertSame('completed', (string) $second_execute['status']);
        $this->assertSame(1, (int) $second_execute['operation_counts']['executed_media_writes']);
        $this->assertSame(0, (int) $second_execute['operation_counts']['created_media_attachments']);
        $this->assertSame(1, (int) $second_execute['operation_counts']['reused_media_attachments']);
        $second_gallery = get_post_meta($created_post_id, 'gallery_images', true);
        $expected_gallery_attachment = isset($this->fake_media_ingest_attachment_ids['https://phase4-executor.test/wp-content/uploads/2026/03/gallery-two.jpg'])
            ? (int) $this->fake_media_ingest_attachment_ids['https://phase4-executor.test/wp-content/uploads/2026/03/gallery-two.jpg']
            : 0;
        $this->assertGreaterThan(0, $expected_gallery_attachment);
        $this->assertSame([$expected_gallery_attachment], array_values(array_map('intval', is_array($second_gallery) ? $second_gallery : [])));
    }

    public function test_import_executor_rollback_restores_gallery_media_array_state(): void
    {
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'home',
            'post_title' => 'Existing Home',
            'post_status' => 'publish',
        ]);
        add_post_meta($page_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH, $this->relative_path);

        $existing_attachment_id = $this->create_fake_existing_attachment(
            'https://phase4-executor.test/wp-content/uploads/2026/03/existing-gallery.jpg',
            $page_id
        );
        $this->assertGreaterThan(0, $existing_attachment_id);
        update_post_meta($page_id, 'gallery_images', [$existing_attachment_id]);

        DBVC_CC_Media_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'approved',
                'approved' => [
                    [
                        'media_id' => 'dbvc_cc_med_gallery_hero',
                        'target_ref' => 'meta:post:page:gallery_images',
                        'source_url' => 'https://phase4-executor.test/wp-content/uploads/2026/03/hero.jpg',
                    ],
                    [
                        'media_id' => 'dbvc_cc_med_gallery_two',
                        'target_ref' => 'meta:post:page:gallery_images',
                        'source_url' => 'https://phase4-executor.test/wp-content/uploads/2026/03/gallery-two.jpg',
                    ],
                ],
                'overrides' => [],
                'ignored' => [],
                'conflicts' => [],
            ],
            get_current_user_id()
        );

        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');
        $approval_token = $this->approve_preflight_and_get_token();
        $execute = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            $this->domain_key,
            $this->relative_path,
            false,
            true,
            $approval_token
        );

        $this->assertIsArray($execute);
        $this->assertSame('completed', (string) $execute['status']);
        $gallery_after_execute = get_post_meta($page_id, 'gallery_images', true);
        $this->assertIsArray($gallery_after_execute);
        $gallery_after_execute = array_values(array_map('intval', $gallery_after_execute));
        $this->assertCount(2, $gallery_after_execute);
        $this->assertNotSame([$existing_attachment_id], $gallery_after_execute);

        $rollback = DBVC_CC_Import_Executor_Service::get_instance()->rollback_run((int) $execute['run_id']);
        $this->assertIsArray($rollback);
        $this->assertSame('completed', (string) $rollback['status']);
        $this->assertSame([$existing_attachment_id], array_values(array_map('intval', (array) get_post_meta($page_id, 'gallery_images', true))));
        $this->assertInstanceOf(WP_Post::class, get_post($existing_attachment_id));

        foreach ($gallery_after_execute as $attachment_id) {
            $this->assertNull(get_post($attachment_id));
        }
    }

    public function test_import_executor_executes_remote_url_media_target_and_restores_previous_value_on_rollback(): void
    {
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'home',
            'post_title' => 'Existing Home',
            'post_status' => 'publish',
        ]);
        add_post_meta($page_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH, $this->relative_path);
        update_post_meta($page_id, 'video_embed_url', 'https://videos.phase4-executor.test/embed/original');

        DBVC_CC_Media_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'approved',
                'approved' => [
                    [
                        'media_id' => 'dbvc_cc_med_video_embed',
                        'target_ref' => 'meta:post:page:video_embed_url',
                        'source_url' => 'https://videos.phase4-executor.test/embed/updated',
                    ],
                ],
                'overrides' => [],
                'ignored' => [],
                'conflicts' => [],
            ],
            get_current_user_id()
        );

        $dry_run = DBVC_CC_Import_Executor_Service::get_instance()->execute_dry_run($this->domain_key, $this->relative_path, false);
        $this->assertIsArray($dry_run);
        $this->assertSame('remote_url', (string) $dry_run['write_preparation']['media_writes'][0]['storage_shape']);
        $this->assertSame('update_post_meta_media_url', (string) $dry_run['write_preparation']['media_writes'][0]['action']);

        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');
        $approval_token = $this->approve_preflight_and_get_token();
        $execute = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            $this->domain_key,
            $this->relative_path,
            false,
            true,
            $approval_token
        );

        $this->assertIsArray($execute);
        $this->assertSame('completed', (string) $execute['status']);
        $this->assertSame('https://videos.phase4-executor.test/embed/updated', (string) get_post_meta($page_id, 'video_embed_url', true));
        $this->assertSame(0, (int) $execute['operation_counts']['created_media_attachments']);

        $rollback = DBVC_CC_Import_Executor_Service::get_instance()->rollback_run((int) $execute['run_id']);
        $this->assertIsArray($rollback);
        $this->assertSame('completed', (string) $rollback['status']);
        $this->assertSame('https://videos.phase4-executor.test/embed/original', (string) get_post_meta($page_id, 'video_embed_url', true));
    }

    public function test_import_executor_defers_unsupported_nested_media_target_without_blocking_execute(): void
    {
        DBVC_CC_Media_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'approved',
                'approved' => [
                    [
                        'media_id' => 'dbvc_cc_med_nested_gallery',
                        'target_ref' => 'acf:group_nested:gallery_media',
                        'source_url' => 'https://phase4-executor.test/wp-content/uploads/2026/03/hero.jpg',
                        'storage_shape' => 'unsupported_nested_media',
                        'multi_value' => true,
                        'return_format' => 'array',
                        'accepted_media_kinds' => ['image'],
                        'normalized_value_strategy' => 'defer_unsupported_nested',
                    ],
                ],
                'overrides' => [],
                'ignored' => [],
                'conflicts' => [],
            ],
            get_current_user_id()
        );

        $dry_run = DBVC_CC_Import_Executor_Service::get_instance()->execute_dry_run($this->domain_key, $this->relative_path, false);
        $this->assertIsArray($dry_run);
        $this->assertSame('completed', (string) $dry_run['status']);
        $this->assertSame(1, (int) $dry_run['deferred_media_count']);
        $this->assertSame('unsupported_nested_media_target', (string) $dry_run['deferred_media_reasons'][0]['code']);
        $this->assertSame('unsupported_shape', (string) $dry_run['deferred_media_reasons'][0]['group']);
        $this->assertSame('deferred', (string) $dry_run['write_preparation']['media_writes'][0]['write_status']);
        $this->assertSame('unsupported_nested_media', (string) $dry_run['write_preparation']['media_writes'][0]['storage_shape']);

        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');
        $approval_token = $this->approve_preflight_and_get_token();
        $execute = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            $this->domain_key,
            $this->relative_path,
            false,
            true,
            $approval_token
        );

        $this->assertIsArray($execute);
        $this->assertSame('completed_partial', (string) $execute['status']);
        $this->assertSame(['entity', 'field'], array_values($execute['executed_stages']));
        $this->assertSame(['media'], array_values($execute['deferred_stages']));
        $this->assertSame(1, (int) $execute['operation_counts']['deferred_media_writes']);
        $this->assertSame('unsupported_nested_media_target', (string) $execute['media_write_execution']['operations'][0]['deferred_reason_code']);
    }

    public function test_import_executor_rollback_removes_created_posts_and_restores_run_journal(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');
        $approval_token = $this->approve_preflight_and_get_token();
        $execute = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            $this->domain_key,
            $this->relative_path,
            false,
            true,
            $approval_token
        );

        $this->assertIsArray($execute);
        $this->assertSame('completed', (string) $execute['status']);
        $this->assertGreaterThan(0, (int) $execute['run_id']);
        $this->assertTrue((bool) $execute['rollback_available']);
        $this->assertSame('available', (string) $execute['rollback_status']);

        $created_post_id = (int) $execute['entity_write_execution']['operations'][0]['target_entity_id'];
        $this->assertGreaterThan(0, $created_post_id);
        $this->assertInstanceOf(WP_Post::class, get_post($created_post_id));
        $created_attachment_id = (int) get_post_thumbnail_id($created_post_id);
        $this->assertGreaterThan(0, $created_attachment_id);

        $run_details = DBVC_CC_Import_Executor_Service::get_instance()->get_run_details((int) $execute['run_id']);
        $this->assertIsArray($run_details);
        $this->assertSame((int) $execute['run_id'], (int) $run_details['run']['id']);
        $this->assertSame('completed', (string) $run_details['run']['status']);
        $this->assertSame('available', (string) $run_details['run']['rollback_status']);
        $this->assertCount(4, $run_details['actions']);
        $this->assertSame('entity', (string) $run_details['actions'][0]['stage']);
        $this->assertSame('field', (string) $run_details['actions'][1]['stage']);
        $this->assertSame('field', (string) $run_details['actions'][2]['stage']);
        $this->assertSame('media', (string) $run_details['actions'][3]['stage']);
        $this->assertSame('completed', (string) $run_details['actions'][0]['execution_status']);

        $rollback = DBVC_CC_Import_Executor_Service::get_instance()->rollback_run((int) $execute['run_id']);
        $this->assertIsArray($rollback);
        $this->assertSame('completed', (string) $rollback['status']);
        $this->assertSame(4, (int) $rollback['restored_count']);
        $this->assertSame(0, (int) $rollback['failed_count']);
        $this->assertSame('rolled_back', (string) $rollback['run']['status']);
        $this->assertSame('completed', (string) $rollback['run']['rollback_status']);

        foreach ($rollback['actions'] as $action) {
            $this->assertSame('completed', (string) $action['rollback_status']);
        }

        $this->assertNull(get_post($created_post_id));
        $this->assertNull(get_post($created_attachment_id));
        $matching_posts = get_posts([
            'post_type' => 'page',
            'post_status' => 'any',
            'meta_key' => DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH,
            'meta_value' => $this->relative_path,
            'fields' => 'ids',
            'numberposts' => 10,
        ]);
        $this->assertCount(0, is_array($matching_posts) ? $matching_posts : []);

        $repeat = DBVC_CC_Import_Executor_Service::get_instance()->rollback_run((int) $execute['run_id']);
        $this->assertIsArray($repeat);
        $this->assertSame('completed', (string) $repeat['status']);
        $this->assertSame(0, (int) $repeat['restored_count']);
        $this->assertSame(0, (int) $repeat['failed_count']);
    }

    public function test_import_executor_rollback_restores_updated_existing_post_via_endpoint(): void
    {
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'home',
            'post_title' => 'Existing Home',
            'post_content' => 'Existing page body.',
            'post_status' => 'publish',
        ]);
        add_post_meta($page_id, 'faq_question', 'Existing FAQ');

        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');
        $approval_token = $this->approve_preflight_and_get_token();
        $execute = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            $this->domain_key,
            $this->relative_path,
            false,
            true,
            $approval_token
        );

        $this->assertIsArray($execute);
        $this->assertSame('completed', (string) $execute['status']);
        $this->assertSame($page_id, (int) $execute['entity_write_execution']['operations'][0]['target_entity_id']);
        $this->assertSame('Managed IT Services', get_the_title($page_id));
        $this->assertSame(['How quickly can onboarding begin?'], get_post_meta($page_id, 'faq_question', false));
        $this->assertGreaterThan(0, (int) get_post_thumbnail_id($page_id));

        $controller = DBVC_CC_Import_Executor_REST_Controller::get_instance();

        $run_request = new WP_REST_Request('GET', '/dbvc_cc/v1/import-executor/run');
        $run_request->set_param('run_id', (int) $execute['run_id']);
        $run_response = $controller->get_run_details($run_request);
        $this->assertInstanceOf(WP_REST_Response::class, $run_response);
        $run_payload = $run_response->get_data();
        $this->assertSame((int) $execute['run_id'], (int) $run_payload['run']['id']);
        $this->assertCount(4, $run_payload['actions']);

        $list_request = new WP_REST_Request('GET', '/dbvc_cc/v1/import-executor/runs');
        $list_request->set_param('domain', $this->domain_key);
        $list_request->set_param('path', $this->relative_path);
        $list_request->set_param('limit', 5);
        $list_response = $controller->list_runs($list_request);
        $this->assertInstanceOf(WP_REST_Response::class, $list_response);
        $list_payload = $list_response->get_data();
        $this->assertNotEmpty($list_payload['runs']);
        $this->assertSame((int) $execute['run_id'], (int) $list_payload['runs'][0]['id']);

        $rollback_request = new WP_REST_Request('POST', '/dbvc_cc/v1/import-executor/rollback');
        $rollback_request->set_param('run_id', (int) $execute['run_id']);
        $rollback_response = $controller->rollback_run($rollback_request);
        $this->assertInstanceOf(WP_REST_Response::class, $rollback_response);
        $rollback_payload = $rollback_response->get_data();
        $this->assertSame('completed', (string) $rollback_payload['status']);
        $this->assertSame('Existing Home', get_the_title($page_id));
        $this->assertSame(['Existing FAQ'], get_post_meta($page_id, 'faq_question', false));
        $this->assertSame(0, (int) get_post_thumbnail_id($page_id));
        $this->assertSame('', (string) get_post_meta($page_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_URL, true));
        $this->assertSame('', (string) get_post_meta($page_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH, true));
        $this->assertSame('', (string) get_post_meta($page_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_HASH, true));
    }

    public function test_import_executor_automatically_rolls_back_after_nested_entity_failure(): void
    {
        $nested_path = 'services/managed-it';
        $this->build_nested_fixture_artifacts($nested_path);

        DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, true);
        DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates($this->domain_key, $nested_path, true);
        DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates($this->domain_key, $nested_path, true);

        DBVC_CC_Mapping_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $nested_path,
            [
                'decision_status' => 'approved',
                'approved_mappings' => [
                    ['section_id' => 'dbvc_cc_sec_nested_hero', 'target_ref' => 'core:post_title'],
                    ['section_id' => 'dbvc_cc_sec_nested_body', 'target_ref' => 'core:post_content'],
                ],
                'object_hints' => [
                    'override_post_type' => 'page',
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
            $nested_path,
            [
                'decision_status' => 'approved',
                'approved' => [],
                'overrides' => [],
                'ignored' => [],
                'conflicts' => [],
            ],
            get_current_user_id()
        );

        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');
        $approval_token = $this->approve_preflight_and_get_token($this->domain_key, $nested_path);

        $reject_child_create = static function ($result, $stage, $write_operation) {
            if (
                is_wp_error($result)
                || (string) $stage !== 'entity'
                || ! is_array($write_operation)
                || empty($write_operation['planned_post_args']['post_name'])
                || (string) $write_operation['planned_post_args']['post_name'] !== 'managed-it'
            ) {
                return $result;
            }

            return new WP_Error(
                'dbvc_cc_test_injected_entity_failure',
                'Injected entity write failure for rollback test.'
            );
        };

        add_filter('dbvc_cc_import_executor_pre_write', $reject_child_create, 10, 3);
        try {
            $result = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
                $this->domain_key,
                $nested_path,
                false,
                true,
                $approval_token
            );
        } finally {
            remove_filter('dbvc_cc_import_executor_pre_write', $reject_child_create, 10);
        }

        $this->assertIsArray($result);
        $this->assertSame('rolled_back_after_failure', (string) $result['status']);
        $this->assertTrue((bool) $result['write_performed']);
        $this->assertSame('completed', (string) $result['auto_rollback']['status']);
        $this->assertSame(1, (int) $result['auto_rollback']['restored_count']);
        $this->assertSame(0, (int) $result['auto_rollback']['failed_count']);
        $this->assertSame(1, (int) $result['operation_counts']['executed_entity_writes']);
        $this->assertSame(1, (int) $result['operation_counts']['failed_entity_writes']);
        $this->assertSame(1, (int) $result['operation_counts']['auto_rollback_attempted']);
        $this->assertSame(1, (int) $result['operation_counts']['auto_rollback_restored_actions']);
        $this->assertSame(0, (int) $result['operation_counts']['auto_rollback_failed_actions']);
        $this->assertFalse((bool) $result['rollback_available']);
        $this->assertSame('completed', (string) $result['rollback_status']);

        $run_details = DBVC_CC_Import_Executor_Service::get_instance()->get_run_details((int) $result['run_id']);
        $this->assertIsArray($run_details);
        $this->assertSame('rolled_back', (string) $run_details['run']['status']);
        $this->assertSame('completed', (string) $run_details['run']['rollback_status']);

        $actions = isset($run_details['actions']) && is_array($run_details['actions']) ? $run_details['actions'] : [];
        $this->assertCount(2, $actions);
        $this->assertSame('completed', (string) $actions[0]['rollback_status']);
        $this->assertSame('failed', (string) $actions[1]['execution_status']);

        $services_posts = get_posts([
            'post_type' => 'page',
            'post_status' => 'any',
            'meta_key' => DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH,
            'meta_value' => 'services',
            'fields' => 'ids',
            'numberposts' => 10,
        ]);
        $nested_posts = get_posts([
            'post_type' => 'page',
            'post_status' => 'any',
            'meta_key' => DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH,
            'meta_value' => $nested_path,
            'fields' => 'ids',
            'numberposts' => 10,
        ]);
        $this->assertCount(0, is_array($services_posts) ? $services_posts : []);
        $this->assertCount(0, is_array($nested_posts) ? $nested_posts : []);
    }

    public function test_import_executor_automatically_rolls_back_after_field_failure(): void
    {
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'home',
            'post_title' => 'Existing Home',
            'post_content' => 'Existing page body.',
            'post_status' => 'publish',
        ]);
        add_post_meta($page_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH, $this->relative_path);
        add_post_meta($page_id, 'faq_question', 'Existing FAQ');

        DBVC_CC_Mapping_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $this->relative_path,
            [
                'decision_status' => 'approved',
                'approved_mappings' => [
                    ['section_id' => 'dbvc_cc_sec_hero', 'target_ref' => 'core:post_title'],
                    ['section_id' => 'dbvc_cc_sec_faq', 'target_ref' => 'core:post_content'],
                ],
                'object_hints' => [
                    'override_post_type' => 'page',
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

        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');
        $approval_token = $this->approve_preflight_and_get_token();

        $reject_content_field_update = static function ($result, $stage, $write_operation, $context) use ($page_id) {
            if (
                is_wp_error($result)
                || (string) $stage !== 'field'
                || ! is_array($write_operation)
                || empty($write_operation['target_field_key'])
                || (string) $write_operation['target_field_key'] !== 'post_content'
                || ! is_array($context)
                || empty($context['target_entity_id'])
                || absint($context['target_entity_id']) !== $page_id
            ) {
                return $result;
            }

            return new WP_Error(
                'dbvc_cc_test_injected_field_failure',
                'Injected field write failure for rollback test.'
            );
        };

        add_filter('dbvc_cc_import_executor_pre_write', $reject_content_field_update, 10, 4);
        try {
            $result = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
                $this->domain_key,
                $this->relative_path,
                false,
                true,
                $approval_token
            );
        } finally {
            remove_filter('dbvc_cc_import_executor_pre_write', $reject_content_field_update, 10);
        }

        $this->assertIsArray($result);
        $this->assertSame('rolled_back_after_failure', (string) $result['status']);
        $this->assertTrue((bool) $result['write_performed']);
        $this->assertSame('completed', (string) $result['auto_rollback']['status']);
        $this->assertSame(2, (int) $result['auto_rollback']['restored_count']);
        $this->assertSame(0, (int) $result['auto_rollback']['failed_count']);
        $this->assertSame(1, (int) $result['operation_counts']['executed_entity_writes']);
        $this->assertSame(1, (int) $result['operation_counts']['executed_field_writes']);
        $this->assertSame(1, (int) $result['operation_counts']['failed_field_writes']);
        $this->assertSame(1, (int) $result['operation_counts']['auto_rollback_attempted']);
        $this->assertSame(2, (int) $result['operation_counts']['auto_rollback_restored_actions']);
        $this->assertFalse((bool) $result['rollback_available']);
        $this->assertSame('completed', (string) $result['rollback_status']);

        $this->assertSame('Existing Home', get_the_title($page_id));
        $this->assertSame('Existing page body.', (string) get_post($page_id)->post_content);
        $this->assertSame(['Existing FAQ'], get_post_meta($page_id, 'faq_question', false));
        $this->assertSame('', (string) get_post_meta($page_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_URL, true));
        $this->assertSame([$this->relative_path], get_post_meta($page_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH, false));
        $this->assertSame('', (string) get_post_meta($page_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_HASH, true));

        $run_details = DBVC_CC_Import_Executor_Service::get_instance()->get_run_details((int) $result['run_id']);
        $this->assertIsArray($run_details);
        $this->assertSame('rolled_back', (string) $run_details['run']['status']);
        $this->assertSame('completed', (string) $run_details['run']['rollback_status']);
    }

    public function test_import_executor_automatically_rolls_back_after_media_failure(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');
        $approval_token = $this->approve_preflight_and_get_token();

        $reject_media_ingest = static function ($result, $resolved_media, $media_write) {
            if ($result !== null) {
                return $result;
            }
            if (! is_array($media_write) || empty($media_write['target_ref']) || (string) $media_write['target_ref'] !== 'core:featured_image') {
                return $result;
            }

            return new WP_Error(
                'dbvc_cc_test_injected_media_failure',
                'Injected media ingest failure for rollback test.'
            );
        };

        add_filter('dbvc_cc_import_executor_media_ingest', $reject_media_ingest, 10, 3);
        try {
            $result = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
                $this->domain_key,
                $this->relative_path,
                false,
                true,
                $approval_token
            );
        } finally {
            remove_filter('dbvc_cc_import_executor_media_ingest', $reject_media_ingest, 10);
        }

        $this->assertIsArray($result);
        $this->assertSame('rolled_back_after_failure', (string) $result['status']);
        $this->assertTrue((bool) $result['write_performed']);
        $this->assertSame('completed', (string) $result['auto_rollback']['status']);
        $this->assertSame(3, (int) $result['auto_rollback']['restored_count']);
        $this->assertSame(0, (int) $result['auto_rollback']['failed_count']);
        $this->assertSame(1, (int) $result['operation_counts']['executed_entity_writes']);
        $this->assertSame(2, (int) $result['operation_counts']['executed_field_writes']);
        $this->assertSame(0, (int) $result['operation_counts']['executed_media_writes']);
        $this->assertSame(1, (int) $result['operation_counts']['failed_media_writes']);
        $this->assertSame(1, (int) $result['operation_counts']['auto_rollback_attempted']);
        $this->assertSame(3, (int) $result['operation_counts']['auto_rollback_restored_actions']);

        $matching_posts = get_posts([
            'post_type' => 'page',
            'post_status' => 'any',
            'meta_key' => DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH,
            'meta_value' => $this->relative_path,
            'fields' => 'ids',
            'numberposts' => 10,
        ]);
        $this->assertCount(0, is_array($matching_posts) ? $matching_posts : []);

        $attachments = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'any',
            'meta_key' => DBVC_CC_Contracts::IMPORT_META_SOURCE_URL,
            'meta_value' => 'https://phase4-executor.test/wp-content/uploads/2026/03/hero.jpg',
            'fields' => 'ids',
            'numberposts' => 10,
        ]);
        $this->assertCount(0, is_array($attachments) ? $attachments : []);

        $run_details = DBVC_CC_Import_Executor_Service::get_instance()->get_run_details((int) $result['run_id']);
        $this->assertIsArray($run_details);
        $this->assertSame('rolled_back', (string) $run_details['run']['status']);
        $this->assertSame('completed', (string) $run_details['run']['rollback_status']);
    }

    public function test_import_executor_execute_endpoint_returns_guarded_payload(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '0');
        $controller = DBVC_CC_Import_Executor_REST_Controller::get_instance();
        $request = new WP_REST_Request('POST', '/dbvc_cc/v1/import-executor/execute');
        $request->set_param('domain', $this->domain_key);
        $request->set_param('path', $this->relative_path);
        $request->set_param('build_if_missing', false);
        $request->set_param('confirm_execute', true);
        $request->set_param('approval_token', 'missing');

        $response = $controller->execute_write_skeleton($request);
        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $payload = $response->get_data();
        $this->assertIsArray($payload);
        $this->assertSame('blocked', (string) $payload['status']);
        $this->assertFalse((bool) $payload['write_performed']);
        $this->assertArrayHasKey('phase4_context', $payload);
        $this->assertSame('1.1.0', (string) $payload['phase4_context']['handoff_schema_version']);
    }

    public function test_import_executor_prepares_missing_parent_chain_for_hierarchical_create(): void
    {
        $nested_path = 'services/managed-it';
        $this->build_nested_fixture_artifacts($nested_path);

        DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($this->domain_key, true);
        DBVC_CC_Section_Field_Candidate_Service::get_instance()->build_candidates($this->domain_key, $nested_path, true);
        DBVC_CC_Media_Candidate_Service::get_instance()->build_candidates($this->domain_key, $nested_path, true);

        DBVC_CC_Mapping_Decision_Service::get_instance()->save_decision(
            $this->domain_key,
            $nested_path,
            [
                'decision_status' => 'approved',
                'approved_mappings' => [
                    ['section_id' => 'dbvc_cc_sec_nested_hero', 'target_ref' => 'core:post_title'],
                    ['section_id' => 'dbvc_cc_sec_nested_body', 'target_ref' => 'core:post_content'],
                ],
                'object_hints' => [
                    'override_post_type' => 'page',
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
            $nested_path,
            [
                'decision_status' => 'approved',
                'approved' => [],
                'overrides' => [],
                'ignored' => [],
                'conflicts' => [],
            ],
            get_current_user_id()
        );

        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');
        $approval_token = $this->approve_preflight_and_get_token($this->domain_key, $nested_path);
        $result = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            $this->domain_key,
            $nested_path,
            false,
            true,
            $approval_token
        );

        $this->assertIsArray($result);
        $this->assertSame('completed', (string) $result['status']);
        $this->assertEmpty($result['write_barriers']);
        $this->assertSame(2, (int) $result['operation_counts']['prepared_entity_writes']);
        $this->assertSame(2, (int) $result['operation_counts']['executed_entity_writes']);
        $this->assertSame(2, (int) $result['operation_counts']['executed_field_writes']);
        $this->assertSame(0, (int) $result['operation_counts']['deferred_media_writes']);

        $entity_writes = isset($result['write_preparation']['entity_writes']) && is_array($result['write_preparation']['entity_writes'])
            ? $result['write_preparation']['entity_writes']
            : [];
        $this->assertCount(2, $entity_writes);

        $parent_write = null;
        $child_write = null;
        foreach ($entity_writes as $entity_write) {
            if (! is_array($entity_write)) {
                continue;
            }
            if (! empty($entity_write['is_supporting_parent'])) {
                $parent_write = $entity_write;
                continue;
            }
            if (! empty($entity_write['source_operation_id'])) {
                $child_write = $entity_write;
            }
        }

        $this->assertIsArray($parent_write);
        $this->assertIsArray($child_write);
        $this->assertSame('prepared_create_parent', (string) $parent_write['result']);
        $this->assertSame('services', (string) $parent_write['planned_post_args']['post_name']);
        $this->assertSame('prepared_create_new', (string) $child_write['result']);
        $this->assertSame('@' . (string) $parent_write['write_operation_id'], (string) $child_write['planned_post_args']['post_parent_ref']);
        $this->assertSame((string) $parent_write['write_operation_id'], (string) $child_write['depends_on'][0]);

        $execution_operations = isset($result['entity_write_execution']['operations']) && is_array($result['entity_write_execution']['operations'])
            ? $result['entity_write_execution']['operations']
            : [];
        $this->assertCount(2, $execution_operations);
        $parent_execution = $execution_operations[0];
        $child_execution = $execution_operations[1];
        $this->assertSame('completed', (string) $parent_execution['status']);
        $this->assertSame('completed', (string) $child_execution['status']);

        $parent_post_id = (int) $parent_execution['target_entity_id'];
        $child_post_id = (int) $child_execution['target_entity_id'];
        $this->assertGreaterThan(0, $parent_post_id);
        $this->assertGreaterThan(0, $child_post_id);
        $this->assertSame($parent_post_id, (int) wp_get_post_parent_id($child_post_id));
        $this->assertSame('services', (string) get_post_meta($parent_post_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH, true));
        $this->assertSame($nested_path, (string) get_post_meta($child_post_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH, true));
        $this->assertSame('Managed IT', get_the_title($child_post_id));
        $this->assertSame('<p> Support plans and onboarding details.', (string) get_post($child_post_id)->post_content);
    }

    public function test_import_executor_execute_is_idempotent_across_reruns(): void
    {
        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');
        $first_approval_token = $this->approve_preflight_and_get_token();

        $first = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            $this->domain_key,
            $this->relative_path,
            false,
            true,
            $first_approval_token
        );
        $this->assertIsArray($first);
        $this->assertSame('completed', (string) $first['status']);
        $this->assertNotEmpty($first['entity_write_execution']['operations']);
        $created_post_id = (int) $first['entity_write_execution']['operations'][0]['target_entity_id'];
        $this->assertGreaterThan(0, $created_post_id);

        $stale = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            $this->domain_key,
            $this->relative_path,
            false,
            true,
            $first_approval_token
        );
        $this->assertIsArray($stale);
        $this->assertSame('blocked', (string) $stale['status']);
        $this->assertSame('stale', (string) $stale['preflight_approval']['status']);

        $second_approval_token = $this->approve_preflight_and_get_token();
        $second = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton(
            $this->domain_key,
            $this->relative_path,
            false,
            true,
            $second_approval_token
        );
        $this->assertIsArray($second);
        $this->assertSame('completed', (string) $second['status']);
        $this->assertTrue((bool) $second['write_performed']);
        $this->assertSame(1, (int) $second['operation_counts']['executed_entity_writes']);
        $this->assertSame(0, (int) $second['operation_counts']['executed_entity_creates']);
        $this->assertSame(1, (int) $second['operation_counts']['executed_entity_updates']);
        $this->assertSame(0, (int) $second['operation_counts']['failed_entity_writes']);
        $this->assertSame(2, (int) $second['operation_counts']['executed_field_writes']);
        $this->assertSame(0, (int) $second['operation_counts']['failed_field_writes']);
        $this->assertSame(0, (int) $second['operation_counts']['deferred_field_writes']);
        $this->assertSame(1, (int) $second['operation_counts']['executed_media_writes']);
        $this->assertSame(0, (int) $second['operation_counts']['created_media_attachments']);
        $this->assertSame(1, (int) $second['operation_counts']['reused_media_attachments']);
        $this->assertSame(0, (int) $second['operation_counts']['deferred_media_writes']);

        $second_execution = $second['entity_write_execution']['operations'][0];
        $this->assertSame('completed', (string) $second_execution['status']);
        $this->assertSame('updated', (string) $second_execution['result']);
        $this->assertSame($created_post_id, (int) $second_execution['target_entity_id']);

        $matching_posts = get_posts([
            'post_type' => 'page',
            'post_status' => 'any',
            'meta_key' => DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH,
            'meta_value' => $this->relative_path,
            'fields' => 'ids',
            'numberposts' => 10,
        ]);
        $this->assertCount(1, is_array($matching_posts) ? $matching_posts : []);
        $this->assertSame(['How quickly can onboarding begin?'], get_post_meta($created_post_id, 'faq_question', false));
    }

    public function test_preflight_approval_survives_a_rebuild_in_a_later_second(): void
    {
        // Approving and executing are two separate admin requests, so the plan is rebuilt
        // between them and its generation timestamps are re-stamped from the clock. The
        // approval fingerprint describes the plan, so a later rebuild of the same plan must
        // still match it.
        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');

        $service = DBVC_CC_Import_Executor_Service::get_instance();
        $dry_run = $service->execute_dry_run($this->domain_key, $this->relative_path, false);
        $this->assertIsArray($dry_run);
        $this->assertNotSame('', (string) $dry_run['dry_run_plan']['generated_at']);
        $this->assertNotSame('', (string) $dry_run['dry_run_plan']['handoff_generated_at']);

        $approval = $service->approve_preflight_from_execution($dry_run, true);
        $this->assertSame('approved', (string) $approval['status']);
        $approval_token = (string) $approval['approval']['approval_token'];
        $this->assertNotSame('', $approval_token);

        $rebuilt_later = $dry_run;
        $rebuilt_later['dry_run_plan']['generated_at'] = '2099-01-01T00:00:01+00:00';
        $rebuilt_later['dry_run_plan']['handoff_generated_at'] = '2099-01-01T00:00:01+00:00';

        $execute = $service->execute_write_skeleton_from_execution($rebuilt_later, true, $approval_token);
        $this->assertIsArray($execute);
        $this->assertSame('approved', (string) $execute['preflight_approval']['status']);
        $this->assertSame('completed', (string) $execute['status']);
        $this->assertTrue((bool) $execute['write_performed']);
    }

    public function test_preflight_approval_goes_stale_when_the_planned_operations_change(): void
    {
        // The other half of the contract: the fingerprint must still notice real change.
        update_option(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE, '1');

        $service = DBVC_CC_Import_Executor_Service::get_instance();
        $dry_run = $service->execute_dry_run($this->domain_key, $this->relative_path, false);
        $approval = $service->approve_preflight_from_execution($dry_run, true);
        $approval_token = (string) $approval['approval']['approval_token'];

        $different_plan = $dry_run;
        $different_plan['operation_graph']['graph_fingerprint'] = 'dbvc_cc_graph_0000000000000000';

        $execute = $service->execute_write_skeleton_from_execution($different_plan, true, $approval_token);
        $this->assertIsArray($execute);
        $this->assertSame('stale', (string) $execute['preflight_approval']['status']);
        $this->assertSame('blocked', (string) $execute['status']);
    }

    private function approve_preflight_and_get_token($domain = '', $path = ''): string
    {
        $approval = DBVC_CC_Import_Executor_Service::get_instance()->approve_preflight(
            $domain !== '' ? (string) $domain : $this->domain_key,
            $path !== '' ? (string) $path : $this->relative_path,
            false,
            true
        );

        $this->assertIsArray($approval);
        $this->assertSame('approved', (string) $approval['status']);
        $this->assertArrayHasKey('approval', $approval);
        $this->assertNotEmpty((string) $approval['approval']['approval_token']);

        return (string) $approval['approval']['approval_token'];
    }

    private function prepare_ready_mapping_inputs(): void
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
                    ['media_id' => 'dbvc_cc_med_hero', 'target_ref' => 'core:featured_image', 'source_url' => 'https://phase4-executor.test/wp-content/uploads/2026/03/hero.jpg'],
                ],
                'overrides' => [],
                'ignored' => [],
                'conflicts' => [],
            ],
            get_current_user_id()
        );
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
                'content_hash' => hash('sha256', 'phase4-executor-home'),
            ],
            'content' => [
                'images' => [
                    '/wp-content/uploads/2026/03/hero.jpg',
                    '/wp-content/uploads/2026/03/gallery-two.jpg',
                ],
                'sections' => [
                    [
                        'id' => 'dbvc_cc_sec_hero',
                        'heading' => 'Hero',
                        'images' => [
                            ['url' => 'https://phase4-executor.test/wp-content/uploads/2026/03/hero-inline.jpg'],
                            ['url' => 'https://phase4-executor.test/wp-content/uploads/2026/03/gallery-two.jpg'],
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
            'element_count' => 5,
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
                    'element_id' => 'dbvc_cc_el_gallery_img',
                    'tag' => 'img',
                    'text' => 'Gallery image',
                    'sequence_index' => 3,
                    'attributes' => [
                        'src' => '/wp-content/uploads/2026/03/gallery-two.jpg',
                        'alt' => 'Gallery photo',
                    ],
                ],
                [
                    'element_id' => 'dbvc_cc_el_faq_h2',
                    'tag' => 'h2',
                    'text' => 'FAQs',
                    'sequence_index' => 4,
                    'attributes' => [],
                ],
                [
                    'element_id' => 'dbvc_cc_el_faq_p',
                    'tag' => 'p',
                    'text' => 'How quickly can onboarding begin?',
                    'sequence_index' => 5,
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
                    'section_label_candidate' => 'Hero',
                    'element_ids' => [
                        'dbvc_cc_el_hero_h1',
                        'dbvc_cc_el_hero_img',
                        'dbvc_cc_el_gallery_img',
                    ],
                ],
                [
                    'section_id' => 'dbvc_cc_sec_faq',
                    'section_label_candidate' => 'FAQ',
                    'element_ids' => [
                        'dbvc_cc_el_faq_h2',
                        'dbvc_cc_el_faq_p',
                    ],
                ],
            ],
        ];

        $section_typing_v2 = [
            'artifact_schema_version' => '2.0.0',
            'artifact_type' => 'section-typing.v2',
            'source_url' => $source_url,
            'generated_at' => current_time('c'),
            'mode' => 'deterministic',
            'sections' => [
                [
                    'section_id' => 'dbvc_cc_sec_hero',
                    'section_type_candidate' => 'hero',
                    'confidence' => 0.9,
                    'evidence' => [
                        'heading_signal',
                    ],
                ],
                [
                    'section_id' => 'dbvc_cc_sec_faq',
                    'section_type_candidate' => 'faq',
                    'confidence' => 0.82,
                    'evidence' => [
                        'faq_heading_signal',
                    ],
                ],
            ],
        ];
        $mapping_suggestions = [
            'schema_version' => '1.0',
            'status' => 'fallback',
            'generated_at' => current_time('c'),
            'domain' => $this->domain_key,
            'path' => $this->relative_path,
            'source_url' => $source_url,
            'suggestions' => [
                'post_type' => [
                    'value' => 'service',
                    'confidence' => 0.84,
                    'rationale' => 'Deterministic fixture hint.',
                ],
                'taxonomy' => [
                    'taxonomy' => 'category',
                    'terms' => [],
                ],
                'custom_fields' => [],
                'media_roles' => [],
                'author' => [
                    'user_login' => 'admin',
                    'confidence' => 0.5,
                ],
            ],
            'conflicts' => [],
            'review' => [
                'needs_review' => false,
                'confidence_threshold' => 0.65,
                'reasons' => [],
            ],
        ];

        file_put_contents(trailingslashit($page_dir) . $slug . '.json', wp_json_encode($main_artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents(
            trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_ELEMENTS_V2_SUFFIX,
            wp_json_encode($elements_v2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        file_put_contents(
            trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_SECTIONS_V2_SUFFIX,
            wp_json_encode($sections_v2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        file_put_contents(
            trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_SECTION_TYPING_V2_SUFFIX,
            wp_json_encode($section_typing_v2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        file_put_contents(
            trailingslashit($page_dir) . $slug . '.mapping.suggestions.json',
            wp_json_encode($mapping_suggestions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function build_nested_fixture_artifacts(string $relative_path): void
    {
        $page_dir = trailingslashit($this->domain_dir) . $relative_path;
        wp_mkdir_p($page_dir);

        $slug = basename($relative_path);
        $source_url = 'https://' . $this->domain_key . '/' . trim($relative_path, '/') . '/';

        $main_artifact = [
            'page_name' => 'Managed IT',
            'slug' => $slug,
            'source_url' => $source_url,
            'provenance' => [
                'source_url' => $source_url,
                'canonical_source_url' => $source_url,
                'content_hash' => hash('sha256', 'phase4-executor-' . $relative_path),
            ],
            'content' => [
                'images' => [],
                'sections' => [],
            ],
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
                    'element_id' => 'dbvc_cc_el_nested_h1',
                    'tag' => 'h1',
                    'text' => 'Managed IT',
                    'sequence_index' => 1,
                    'attributes' => [],
                ],
                [
                    'element_id' => 'dbvc_cc_el_nested_p',
                    'tag' => 'p',
                    'text' => 'Support plans and onboarding details.',
                    'sequence_index' => 2,
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
                    'section_id' => 'dbvc_cc_sec_nested_hero',
                    'section_label_candidate' => 'Hero',
                    'element_ids' => [
                        'dbvc_cc_el_nested_h1',
                    ],
                ],
                [
                    'section_id' => 'dbvc_cc_sec_nested_body',
                    'section_label_candidate' => 'Body',
                    'element_ids' => [
                        'dbvc_cc_el_nested_p',
                    ],
                ],
            ],
        ];

        $section_typing_v2 = [
            'artifact_schema_version' => '2.0.0',
            'artifact_type' => 'section-typing.v2',
            'source_url' => $source_url,
            'generated_at' => current_time('c'),
            'mode' => 'deterministic',
            'sections' => [
                [
                    'section_id' => 'dbvc_cc_sec_nested_hero',
                    'section_type_candidate' => 'hero',
                    'confidence' => 0.9,
                ],
                [
                    'section_id' => 'dbvc_cc_sec_nested_body',
                    'section_type_candidate' => 'content',
                    'confidence' => 0.8,
                ],
            ],
        ];

        $mapping_suggestions = [
            'schema_version' => '1.0',
            'status' => 'fallback',
            'generated_at' => current_time('c'),
            'domain' => $this->domain_key,
            'path' => $relative_path,
            'source_url' => $source_url,
            'suggestions' => [
                'post_type' => [
                    'value' => 'page',
                    'confidence' => 0.9,
                    'rationale' => 'Nested page fixture hint.',
                ],
            ],
            'conflicts' => [],
            'review' => [
                'needs_review' => false,
                'confidence_threshold' => 0.65,
                'reasons' => [],
            ],
        ];

        file_put_contents(trailingslashit($page_dir) . $slug . '.json', wp_json_encode($main_artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents(
            trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_ELEMENTS_V2_SUFFIX,
            wp_json_encode($elements_v2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        file_put_contents(
            trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_SECTIONS_V2_SUFFIX,
            wp_json_encode($sections_v2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        file_put_contents(
            trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_SECTION_TYPING_V2_SUFFIX,
            wp_json_encode($section_typing_v2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        file_put_contents(
            trailingslashit($page_dir) . $slug . '.mapping.suggestions.json',
            wp_json_encode($mapping_suggestions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function fake_media_ingest_attachment($result, $resolved_media, $media_write, $context)
    {
        if ($result !== null) {
            return $result;
        }

        $source_url = isset($resolved_media['source_url']) ? esc_url_raw((string) $resolved_media['source_url']) : '';
        if ($source_url === '') {
            return $result;
        }

        if (isset($this->fake_media_ingest_attachment_ids[$source_url])) {
            return [
                'attachment_id' => (int) $this->fake_media_ingest_attachment_ids[$source_url],
                'created' => false,
                'source_url' => $source_url,
            ];
        }

        $upload_dir = wp_upload_dir();
        $base_dir = isset($upload_dir['basedir']) ? (string) $upload_dir['basedir'] : '';
        $base_url = isset($upload_dir['baseurl']) ? (string) $upload_dir['baseurl'] : '';
        if ($base_dir === '' || $base_url === '') {
            return new WP_Error('dbvc_cc_test_media_upload_dir_missing', 'Upload directory is unavailable for fake media ingest.');
        }

        $subdir = trailingslashit($base_dir) . 'dbvc-cc-test-media';
        wp_mkdir_p($subdir);

        $filename = substr(hash('sha256', $source_url), 0, 12) . '.gif';
        $file_path = trailingslashit($subdir) . $filename;
        if (! file_exists($file_path)) {
            $gif_data = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
            file_put_contents($file_path, $gif_data === false ? '' : $gif_data);
            $this->fake_media_ingest_files[] = $file_path;
        }

        if (! function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $attachment_id = wp_insert_attachment(
            [
                'post_mime_type' => 'image/gif',
                'post_title' => 'Fake Media ' . substr(hash('sha256', $source_url), 0, 8),
                'post_status' => 'inherit',
                'guid' => trailingslashit($base_url) . 'dbvc-cc-test-media/' . $filename,
            ],
            $file_path,
            isset($context['target_entity_id']) ? absint($context['target_entity_id']) : 0
        );

        if (is_wp_error($attachment_id) || (int) $attachment_id <= 0) {
            return is_wp_error($attachment_id)
                ? $attachment_id
                : new WP_Error('dbvc_cc_test_media_attachment_create_failed', 'Could not create fake media attachment.');
        }

        update_attached_file((int) $attachment_id, $file_path);
        $metadata = wp_generate_attachment_metadata((int) $attachment_id, $file_path);
        if (is_array($metadata) && ! empty($metadata)) {
            wp_update_attachment_metadata((int) $attachment_id, $metadata);
        }

        update_post_meta((int) $attachment_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_URL, $source_url);
        update_post_meta((int) $attachment_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_HASH, hash('sha256', $source_url));
        if (! empty($resolved_media['alt_text'])) {
            update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field((string) $resolved_media['alt_text']));
        }

        $this->fake_media_ingest_attachment_ids[$source_url] = (int) $attachment_id;

        return [
            'attachment_id' => (int) $attachment_id,
            'created' => true,
            'source_url' => $source_url,
        ];
    }

    private function create_fake_existing_attachment(string $source_url, int $parent_id = 0): int
    {
        $result = $this->fake_media_ingest_attachment(
            null,
            [
                'source_url' => $source_url,
                'alt_text' => 'Existing media',
            ],
            [
                'target_ref' => 'meta:post:page:gallery_images',
                'action' => 'update_post_meta_media',
            ],
            [
                'target_entity_id' => $parent_id,
            ]
        );

        return is_array($result) && ! empty($result['attachment_id'])
            ? (int) $result['attachment_id']
            : 0;
    }

    private function remove_directory(string $directory): void
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
                $this->remove_directory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }

    private function reset_import_run_ledger(): void
    {
        global $wpdb;

        DBVC_CC_Import_Run_Store::get_instance()->maybe_upgrade();
        $wpdb->query('DELETE FROM ' . DBVC_CC_Import_Run_Store::get_instance()->table_name('actions'));
        $wpdb->query('DELETE FROM ' . DBVC_CC_Import_Run_Store::get_instance()->table_name('runs'));
    }
}
