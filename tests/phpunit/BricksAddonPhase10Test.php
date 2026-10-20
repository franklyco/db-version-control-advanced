<?php

class BricksAddonPhase10Test extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_read_only', '0');
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, $this->seed_packages());
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGE_CATALOG, []);
        delete_option(DBVC_Bricks_Proposals::OPTION_QUEUE);
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        $_GET = [];
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        update_option('dbvc_bricks_read_only', '0');
        update_option('dbvc_bricks_role', 'client');
        delete_option(DBVC_Bricks_Packages::OPTION_PACKAGES);
        delete_option(DBVC_Bricks_Packages::OPTION_PACKAGE_CATALOG);
        delete_option(DBVC_Bricks_Proposals::OPTION_QUEUE);
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        parent::tear_down();
    }

    public function test_client_apply_and_restore_controls_and_endpoint_flow(): void
    {
        $render = $this->render_admin_page('apply_restore');
        $this->assertStringContainsString('id="dbvc-bricks-dry-run-apply"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-create-restore-point"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-run-rollback"', $render);

        $manifest = $this->seed_packages()['pkg-a'];
        $apply_request = new WP_REST_Request('POST', '/dbvc/v1/bricks/apply');
        $apply_request->set_header('content-type', 'application/json');
        $apply_request->set_body(wp_json_encode([
            'manifest' => $manifest,
            'options' => ['dry_run' => true],
        ]));
        $apply_response = rest_get_server()->dispatch($apply_request);
        $this->assertSame(200, $apply_response->get_status());
        $plan = $apply_response->get_data()['plan'] ?? null;
        $this->assertIsArray($plan);

        $restore_request = new WP_REST_Request('POST', '/dbvc/v1/bricks/restore-points');
        $restore_request->set_header('content-type', 'application/json');
        $restore_request->set_body(wp_json_encode(['plan' => $plan]));
        $restore_response = rest_get_server()->dispatch($restore_request);
        $this->assertSame(200, $restore_response->get_status());
        $this->assertNotEmpty($restore_response->get_data()['restore_id'] ?? '');
    }

    public function test_proposal_submit_review_controls_and_flow(): void
    {
        $render = $this->render_admin_page('proposals');
        $this->assertStringContainsString('id="dbvc-bricks-submit-proposal"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-proposal-approve"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-proposals-table-body"', $render);
        $this->assertStringContainsString('RECEIVED:"New"', $render);

        $submit = new WP_REST_Request('POST', '/dbvc/v1/bricks/proposals');
        $submit->set_header('content-type', 'application/json');
        $submit->set_body(wp_json_encode([
            'artifact_uid' => 'option:bricks_global_classes',
            'artifact_type' => 'bricks_global_classes',
            'base_hash' => 'sha256:' . str_repeat('a', 64),
            'proposed_hash' => 'sha256:' . str_repeat('b', 64),
            'notes' => 'phase10',
        ]));
        $submit_response = rest_get_server()->dispatch($submit);
        $this->assertSame(200, $submit_response->get_status());
        $proposal_id = $submit_response->get_data()['proposal']['proposal_id'] ?? '';
        $this->assertNotSame('', $proposal_id);

        $patch = new WP_REST_Request('PATCH', '/dbvc/v1/bricks/proposals/' . $proposal_id);
        $patch->set_header('content-type', 'application/json');
        $patch->set_body(wp_json_encode([
            'status' => 'APPROVED',
            'review_notes' => 'approved',
        ]));
        $patch_response = rest_get_server()->dispatch($patch);
        $this->assertSame(200, $patch_response->get_status());
        $this->assertSame('APPROVED', $patch_response->get_data()['status']);
    }

    public function test_mothership_package_list_detail_controls_and_flow(): void
    {
        update_option('dbvc_bricks_role', 'mothership');
        $render = $this->render_admin_page('packages');
        $this->assertStringContainsString('id="dbvc-bricks-refresh-packages"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-packages-channel-filter"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-packages-table-body"', $render);

        $list = new WP_REST_Request('GET', '/dbvc/v1/bricks/packages');
        $list->set_param('channel', 'stable');
        $list_response = rest_get_server()->dispatch($list);
        $this->assertSame(200, $list_response->get_status());
        $items = $list_response->get_data()['items'] ?? [];
        $this->assertNotEmpty($items);

        $get = new WP_REST_Request('GET', '/dbvc/v1/bricks/packages/pkg-a');
        $get_response = rest_get_server()->dispatch($get);
        $this->assertSame(200, $get_response->get_status());
        $this->assertSame('pkg-a', $get_response->get_data()['manifest']['package_id']);
    }

    public function test_permission_and_read_only_guards_for_mutating_actions(): void
    {
        update_option('dbvc_bricks_read_only', '1');
        $render = $this->render_admin_page('apply_restore');
        $this->assertStringContainsString('id="dbvc-bricks-apply-selected" disabled="disabled"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-submit-proposal" disabled="disabled"', $this->render_admin_page('proposals'));

        $manifest = $this->seed_packages()['pkg-a'];
        $apply_request = new WP_REST_Request('POST', '/dbvc/v1/bricks/apply');
        $apply_request->set_header('content-type', 'application/json');
        $apply_request->set_body(wp_json_encode(['manifest' => $manifest]));
        $apply_response = rest_get_server()->dispatch($apply_request);
        $this->assertSame(400, $apply_response->get_status());

        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);
        $submit = new WP_REST_Request('POST', '/dbvc/v1/bricks/proposals');
        $submit->set_header('content-type', 'application/json');
        $submit->set_body(wp_json_encode([
            'artifact_uid' => 'option:bricks_global_classes',
            'artifact_type' => 'bricks_global_classes',
            'base_hash' => 'sha256:' . str_repeat('1', 64),
            'proposed_hash' => 'sha256:' . str_repeat('2', 64),
        ]));
        $submit_response = rest_get_server()->dispatch($submit);
        $this->assertSame(403, $submit_response->get_status());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function seed_packages(): array
    {
        return [
            'pkg-a' => [
                'package_id' => 'pkg-a',
                'version' => '1.0.0',
                'channel' => 'stable',
                'artifacts' => [
                    [
                        'artifact_uid' => 'option:bricks_global_classes',
                        'artifact_type' => 'bricks_global_classes',
                        'payload' => ['one' => 'two'],
                    ],
                ],
            ],
            'pkg-b' => [
                'package_id' => 'pkg-b',
                'version' => '1.1.0-beta',
                'channel' => 'beta',
                'artifacts' => [],
            ],
        ];
    }

    private function render_admin_page($tab): string
    {
        $_GET['page'] = DBVC_Bricks_Addon::MENU_SLUG;
        $_GET['tab'] = $tab;
        ob_start();
        DBVC_Bricks_Addon::render_admin_page();
        return (string) ob_get_clean();
    }

    private function reset_rest_server(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
    }
}
