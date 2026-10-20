<?php

class BricksAddonPhase6Test extends WP_UnitTestCase
{
    private $audit_events = [];

    public function set_up(): void
    {
        parent::set_up();
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        delete_option(DBVC_Bricks_Proposals::OPTION_QUEUE);
        add_action('dbvc_bricks_proposal_transition', [$this, 'capture_transition']);
        $this->reset_rest_server();
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        remove_action('dbvc_bricks_proposal_transition', [$this, 'capture_transition']);
        delete_option(DBVC_Bricks_Proposals::OPTION_QUEUE);
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        parent::tear_down();
    }

    public function test_status_transition_rules_allow_only_valid_paths(): void
    {
        $submit = DBVC_Bricks_Proposals::submit([
            'artifact_uid' => 'option:bricks_global_classes',
            'artifact_type' => 'bricks_global_classes',
            'base_hash' => 'sha256:' . str_repeat('a', 64),
            'proposed_hash' => 'sha256:' . str_repeat('b', 64),
        ], get_current_user_id());
        $proposal = $submit['proposal'];

        $invalid = DBVC_Bricks_Proposals::transition($proposal['proposal_id'], 'SUBMITTED', [], get_current_user_id());
        $this->assertWPError($invalid);

        $valid = DBVC_Bricks_Proposals::transition($proposal['proposal_id'], 'APPROVED', [], get_current_user_id());
        $this->assertIsArray($valid);
        $this->assertSame('APPROVED', $valid['status']);
    }

    public function test_deduplication_by_artifact_uid_base_hash_and_proposed_hash(): void
    {
        $payload = [
            'artifact_uid' => 'option:bricks_components',
            'artifact_type' => 'bricks_components',
            'base_hash' => 'sha256:' . str_repeat('c', 64),
            'proposed_hash' => 'sha256:' . str_repeat('d', 64),
        ];
        $first = DBVC_Bricks_Proposals::submit($payload, get_current_user_id());
        $second = DBVC_Bricks_Proposals::submit($payload, get_current_user_id());

        $this->assertFalse($first['deduplicated']);
        $this->assertTrue($second['deduplicated']);
        $this->assertSame($first['proposal']['proposal_id'], $second['proposal']['proposal_id']);
    }

    public function test_submission_list_and_review_endpoints_flow(): void
    {
        $server = rest_get_server();
        $submit = new WP_REST_Request('POST', '/dbvc/v1/bricks/proposals');
        $submit->set_header('content-type', 'application/json');
        $submit->set_body(wp_json_encode([
            'artifact_uid' => 'option:bricks_color_palette',
            'artifact_type' => 'bricks_color_palette',
            'base_hash' => 'sha256:' . str_repeat('1', 64),
            'proposed_hash' => 'sha256:' . str_repeat('2', 64),
        ]));
        $submit_response = $server->dispatch($submit);
        $this->assertSame(200, $submit_response->get_status());
        $proposal_id = $submit_response->get_data()['proposal']['proposal_id'];

        $list = new WP_REST_Request('GET', '/dbvc/v1/bricks/proposals');
        $list_response = $server->dispatch($list);
        $this->assertSame(200, $list_response->get_status());
        $this->assertNotEmpty($list_response->get_data()['items']);

        $patch = new WP_REST_Request('PATCH', '/dbvc/v1/bricks/proposals/' . $proposal_id);
        $patch->set_header('content-type', 'application/json');
        $patch->set_body(wp_json_encode(['status' => 'APPROVED', 'review_notes' => 'ok']));
        $patch_response = $server->dispatch($patch);
        $this->assertSame(200, $patch_response->get_status());
        $this->assertSame('APPROVED', $patch_response->get_data()['status']);
    }

    public function test_audit_attribution_records_actor_on_transitions(): void
    {
        $submit = DBVC_Bricks_Proposals::submit([
            'artifact_uid' => 'option:bricks_typography',
            'artifact_type' => 'bricks_typography',
            'base_hash' => 'sha256:' . str_repeat('e', 64),
            'proposed_hash' => 'sha256:' . str_repeat('f', 64),
        ], get_current_user_id());
        $proposal_id = $submit['proposal']['proposal_id'];
        DBVC_Bricks_Proposals::transition($proposal_id, 'NEEDS_CHANGES', ['review_notes' => 'adjust'], get_current_user_id());
        DBVC_Bricks_Proposals::transition($proposal_id, 'SUBMITTED', [], get_current_user_id());
        DBVC_Bricks_Proposals::transition($proposal_id, 'RECEIVED', [], get_current_user_id());

        $queue = DBVC_Bricks_Proposals::get_queue();
        $history = $queue[$proposal_id]['history'] ?? [];
        $this->assertNotEmpty($history);
        foreach ($history as $event) {
            $this->assertSame(get_current_user_id(), $event['actor_id']);
        }
        $this->assertNotEmpty($this->audit_events);
    }

    public function capture_transition($event): void
    {
        $this->audit_events[] = $event;
    }

    private function reset_rest_server(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
    }
}
