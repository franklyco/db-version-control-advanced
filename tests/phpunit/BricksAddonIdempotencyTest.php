<?php

class BricksAddonIdempotencyTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        delete_option(DBVC_Bricks_Idempotency::OPTION_STORE);
        delete_option(DBVC_Bricks_Proposals::OPTION_QUEUE);
        $this->reset_rest_server();
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        delete_option(DBVC_Bricks_Idempotency::OPTION_STORE);
        delete_option(DBVC_Bricks_Proposals::OPTION_QUEUE);
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        parent::tear_down();
    }

    public function test_proposal_submit_honors_idempotency_key_replay(): void
    {
        $server = rest_get_server();
        $payload_one = [
            'artifact_uid' => 'option:bricks_global_classes',
            'artifact_type' => 'bricks_global_classes',
            'base_hash' => 'sha256:' . str_repeat('1', 64),
            'proposed_hash' => 'sha256:' . str_repeat('2', 64),
        ];
        $payload_two = [
            'artifact_uid' => 'option:bricks_global_classes',
            'artifact_type' => 'bricks_global_classes',
            'base_hash' => 'sha256:' . str_repeat('3', 64),
            'proposed_hash' => 'sha256:' . str_repeat('4', 64),
        ];

        $first = new WP_REST_Request('POST', '/dbvc/v1/bricks/proposals');
        $first->set_header('content-type', 'application/json');
        $first->set_header('Idempotency-Key', 'proposal-key-1');
        $first->set_body(wp_json_encode($payload_one));
        $first_response = $server->dispatch($first);
        $this->assertSame(200, $first_response->get_status());
        $first_data = $first_response->get_data();
        $first_proposal_id = $first_data['proposal']['proposal_id'];

        $second = new WP_REST_Request('POST', '/dbvc/v1/bricks/proposals');
        $second->set_header('content-type', 'application/json');
        $second->set_header('Idempotency-Key', 'proposal-key-1');
        $second->set_body(wp_json_encode($payload_two));
        $second_response = $server->dispatch($second);
        $this->assertSame(200, $second_response->get_status());
        $second_data = $second_response->get_data();
        $this->assertTrue($second_data['idempotent_replay']);
        $this->assertSame($first_proposal_id, $second_data['proposal']['proposal_id']);
    }

    public function test_apply_honors_idempotency_key_replay(): void
    {
        update_option('bricks_global_classes', ['state' => 'before']);
        $server = rest_get_server();

        $manifest_one = [
            'package_id' => 'pkg-idem-1',
            'artifacts' => [
                [
                    'artifact_uid' => 'option:bricks_global_classes',
                    'artifact_type' => 'bricks_global_classes',
                    'payload' => ['state' => 'first'],
                ],
            ],
        ];

        $manifest_two = [
            'package_id' => 'pkg-idem-2',
            'artifacts' => [
                [
                    'artifact_uid' => 'option:bricks_global_classes',
                    'artifact_type' => 'bricks_global_classes',
                    'payload' => ['state' => 'second'],
                ],
            ],
        ];

        $first = new WP_REST_Request('POST', '/dbvc/v1/bricks/apply');
        $first->set_header('content-type', 'application/json');
        $first->set_header('Idempotency-Key', 'apply-key-1');
        $first->set_body(wp_json_encode([
            'manifest' => $manifest_one,
            'options' => ['dry_run' => true],
        ]));
        $first_response = $server->dispatch($first);
        $this->assertSame(200, $first_response->get_status());
        $this->assertTrue($first_response->get_data()['dry_run']);

        $second = new WP_REST_Request('POST', '/dbvc/v1/bricks/apply');
        $second->set_header('content-type', 'application/json');
        $second->set_header('Idempotency-Key', 'apply-key-1');
        $second->set_body(wp_json_encode([
            'manifest' => $manifest_two,
            'options' => ['dry_run' => true],
        ]));
        $second_response = $server->dispatch($second);
        $this->assertSame(200, $second_response->get_status());
        $this->assertTrue($second_response->get_data()['idempotent_replay']);
        $this->assertSame('pkg-idem-1', $second_response->get_data()['plan']['manifest_id']);
    }

    private function reset_rest_server(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
    }
}
