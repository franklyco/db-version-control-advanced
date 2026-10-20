<?php

class BricksAddonPhase19ATest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        update_option('dbvc_bricks_role', 'mothership');
        update_option(DBVC_Bricks_Addon::OPTION_SHARED_RULES_PROFILE, '{}');
        update_option(DBVC_Bricks_Addon::OPTION_SHARED_RULES_TRANSPORT, '{}');
        update_option(DBVC_Bricks_Idempotency::OPTION_STORE, []);
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, []);
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, []);
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        update_option('dbvc_bricks_role', 'client');
        update_option(DBVC_Bricks_Addon::OPTION_SHARED_RULES_PROFILE, '{}');
        update_option(DBVC_Bricks_Addon::OPTION_SHARED_RULES_TRANSPORT, '{}');
        update_option(DBVC_Bricks_Idempotency::OPTION_STORE, []);
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, []);
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, []);
        remove_all_filters('pre_http_request');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        parent::tear_down();
    }

    public function test_shared_rules_profile_get_returns_contract_fields(): void
    {
        $request = new WP_REST_Request('GET', '/dbvc/v1/bricks/configure/shared-rules-profile');
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertArrayHasKey('profile', $data);
        $this->assertArrayHasKey('fields', $data);
        $this->assertArrayHasKey('non_goals', $data);
        $this->assertArrayHasKey('rules', $data['profile']);

        $rule_keys = array_keys((array) ($data['profile']['rules'] ?? []));
        $this->assertContains('dbvc_bricks_diff_path_rules', $rule_keys);
        $this->assertContains('dbvc_bricks_artifact_ignore_rules', $rule_keys);
        $this->assertContains('dbvc_bricks_artifact_mask_rules', $rule_keys);
        $this->assertContains('dbvc_bricks_meta_ignore_rules', $rule_keys);
        $this->assertContains('dbvc_bricks_meta_mask_rules', $rule_keys);
    }

    public function test_shared_rules_profile_post_requires_idempotency_key(): void
    {
        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/configure/shared-rules-profile');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'rules' => [
                'dbvc_bricks_diff_path_rules' => ['ignore' => ['*' => ['meta.runtime.last_seen']], 'mask' => []],
            ],
        ]));

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(400, $response->get_status());
        $this->assertSame('dbvc_bricks_idempotency_required', $response->get_data()['code'] ?? '');
    }

    public function test_shared_rules_profile_post_is_idempotent_and_persists_normalized_profile(): void
    {
        $payload = [
            'notes' => 'Network baseline for masking and ignore.',
            'rules' => [
                'dbvc_bricks_diff_path_rules' => [
                    'ignore' => ['*' => ['meta.runtime.last_seen', 'meta.runtime.last_seen']],
                    'mask' => ['*' => ['meta.auth.token']],
                ],
                'dbvc_bricks_artifact_ignore_rules' => ['option:bricks_theme_styles' => ['settings.unused']],
                'dbvc_bricks_artifact_mask_rules' => ['option:bricks_theme_styles' => ['settings.secret']],
                'dbvc_bricks_meta_ignore_rules' => ['*' => ['runtime.generated_at']],
                'dbvc_bricks_meta_mask_rules' => ['*' => ['auth.api_key']],
            ],
        ];

        $first = new WP_REST_Request('POST', '/dbvc/v1/bricks/configure/shared-rules-profile');
        $first->set_header('Content-Type', 'application/json');
        $first->set_header('Idempotency-Key', 'shared-rules-save-1');
        $first->set_body(wp_json_encode($payload));
        $first_response = rest_get_server()->dispatch($first);
        $this->assertSame(200, $first_response->get_status());

        $first_data = $first_response->get_data();
        $this->assertTrue((bool) ($first_data['ok'] ?? false));
        $this->assertSame(1, (int) ($first_data['profile']['profile_version'] ?? 0));
        $this->assertSame('Network baseline for masking and ignore.', (string) ($first_data['profile']['notes'] ?? ''));
        $this->assertSame(
            ['meta.runtime.last_seen'],
            $first_data['profile']['rules']['dbvc_bricks_diff_path_rules']['ignore']['*'] ?? []
        );

        $replay = new WP_REST_Request('POST', '/dbvc/v1/bricks/configure/shared-rules-profile');
        $replay->set_header('Content-Type', 'application/json');
        $replay->set_header('Idempotency-Key', 'shared-rules-save-1');
        $replay->set_body(wp_json_encode($payload));
        $replay_response = rest_get_server()->dispatch($replay);
        $this->assertSame(200, $replay_response->get_status());
        $this->assertSame($first_data, $replay_response->get_data());

        $stored = get_option(DBVC_Bricks_Addon::OPTION_SHARED_RULES_PROFILE, '{}');
        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;
        $this->assertIsArray($decoded);
        $this->assertSame(1, (int) ($decoded['profile_version'] ?? 0));
    }

    public function test_shared_rules_profile_post_rejects_invalid_diff_rules_shape(): void
    {
        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/configure/shared-rules-profile');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Idempotency-Key', 'shared-rules-invalid-shape');
        $request->set_body(wp_json_encode([
            'rules' => [
                'dbvc_bricks_diff_path_rules' => [
                    'ignore' => ['*' => ['meta.runtime.last_seen']],
                    'invalid_group' => ['*' => ['meta.bad.path']],
                ],
            ],
        ]));

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(400, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse((bool) ($data['ok'] ?? true));
        $this->assertNotEmpty($data['errors'] ?? []);
    }

    public function test_shared_rules_profile_endpoints_require_mothership_role(): void
    {
        update_option('dbvc_bricks_role', 'client');

        $get_request = new WP_REST_Request('GET', '/dbvc/v1/bricks/configure/shared-rules-profile');
        $get_response = rest_get_server()->dispatch($get_request);
        $this->assertSame(400, $get_response->get_status());
        $this->assertSame('dbvc_bricks_shared_rules_role_invalid', $get_response->get_data()['code'] ?? '');

        $post_request = new WP_REST_Request('POST', '/dbvc/v1/bricks/configure/shared-rules-profile');
        $post_request->set_header('Content-Type', 'application/json');
        $post_request->set_header('Idempotency-Key', 'shared-rules-role-check');
        $post_request->set_body(wp_json_encode(['rules' => []]));
        $post_response = rest_get_server()->dispatch($post_request);
        $this->assertSame(400, $post_response->get_status());
        $this->assertSame('dbvc_bricks_shared_rules_role_invalid', $post_response->get_data()['code'] ?? '');
    }

    public function test_shared_rules_profile_apply_endpoint_requires_valid_signature_and_applies_rules(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_site_uid', 'client_signed');
        update_option('dbvc_bricks_intro_handshake_token', 'hs_shared_secret');

        $payload = [
            'distribution_id' => 'dist_signed_apply',
            'profile' => [
                'profile_version' => 3,
                'updated_at' => gmdate('c'),
                'updated_by' => 'mothership',
                'notes' => 'Apply test',
                'rules' => [
                    'dbvc_bricks_diff_path_rules' => ['ignore' => ['*' => ['meta.runtime.last_seen']], 'mask' => []],
                    'dbvc_bricks_artifact_ignore_rules' => [],
                    'dbvc_bricks_artifact_mask_rules' => [],
                    'dbvc_bricks_meta_ignore_rules' => [],
                    'dbvc_bricks_meta_mask_rules' => [],
                ],
            ],
        ];
        $raw = (string) wp_json_encode($payload);
        $timestamp = time();
        $nonce = 'nonce_apply_' . wp_rand(100, 999);
        $signature = DBVC_Bricks_Command_Auth::build_signature('hs_shared_secret', $timestamp, $nonce, 'client_signed', $raw);

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/configure/shared-rules-profile/apply');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('X-DBVC-Timestamp', (string) $timestamp);
        $request->set_header('X-DBVC-Nonce', $nonce);
        $request->set_header('X-DBVC-Site-UID', 'client_signed');
        $request->set_header('X-DBVC-Signature', $signature);
        $request->set_body($raw);
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $this->assertTrue((bool) ($response->get_data()['ok'] ?? false));
        $this->assertStringContainsString('meta.runtime.last_seen', (string) get_option('dbvc_bricks_diff_path_rules'));

        $invalid = new WP_REST_Request('POST', '/dbvc/v1/bricks/configure/shared-rules-profile/apply');
        $invalid->set_header('Content-Type', 'application/json');
        $invalid->set_header('X-DBVC-Timestamp', (string) $timestamp);
        $invalid->set_header('X-DBVC-Nonce', 'nonce_invalid');
        $invalid->set_header('X-DBVC-Site-UID', 'client_signed');
        $invalid->set_header('X-DBVC-Signature', 'sha256=invalid');
        $invalid->set_body($raw);
        $invalid_response = rest_get_server()->dispatch($invalid);
        $this->assertSame(401, $invalid_response->get_status());
        $this->assertSame('dbvc_bricks_command_signature_invalid', $invalid_response->get_data()['code'] ?? '');
    }

    public function test_shared_rules_profile_distribution_selected_target_succeeds_and_tracks_transport(): void
    {
        update_option('dbvc_bricks_role', 'mothership');
        update_option(DBVC_Bricks_Addon::OPTION_SHARED_RULES_PROFILE, wp_json_encode([
            'profile_version' => 2,
            'updated_at' => gmdate('c'),
            'updated_by' => 'admin',
            'rules' => [
                'dbvc_bricks_diff_path_rules' => ['ignore' => ['*' => ['meta.runtime.last_seen']], 'mask' => []],
                'dbvc_bricks_artifact_ignore_rules' => [],
                'dbvc_bricks_artifact_mask_rules' => [],
                'dbvc_bricks_meta_ignore_rules' => [],
                'dbvc_bricks_meta_mask_rules' => [],
            ],
        ]));
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, [
            'client_a' => ['site_uid' => 'client_a', 'base_url' => 'https://client-a.local', 'status' => 'online', 'allow_receive_packages' => 1],
            'client_b' => ['site_uid' => 'client_b', 'base_url' => 'https://client-b.local', 'status' => 'online', 'allow_receive_packages' => 1],
        ]);
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, [
            'client_a' => ['site_uid' => 'client_a', 'command_secret' => 'hs_client_a'],
            'client_b' => ['site_uid' => 'client_b', 'command_secret' => 'hs_client_b'],
        ]);

        $calls = [];
        add_filter('pre_http_request', static function ($preempt, $args, $url) use (&$calls) {
            $calls[] = ['url' => $url, 'headers' => $args['headers'] ?? []];
            return [
                'headers' => [],
                'body' => wp_json_encode(['ok' => true, 'applied_profile_version' => 2]),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies' => [],
                'filename' => null,
            ];
        }, 10, 3);

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/configure/shared-rules-profile/distribute');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Idempotency-Key', 'dist-selected-success');
        $request->set_body(wp_json_encode([
            'target_mode' => 'selected',
            'site_uids' => ['client_a'],
            'distribution_id' => 'dist_selected_success',
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue((bool) ($data['ok'] ?? false));
        $this->assertSame(1, (int) ($data['summary']['applied'] ?? 0));
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('/configure/shared-rules-profile/apply', (string) $calls[0]['url']);
        $this->assertNotEmpty($calls[0]['headers']['X-DBVC-Signature'] ?? '');

        $transport = get_option(DBVC_Bricks_Addon::OPTION_SHARED_RULES_TRANSPORT, []);
        $row = $transport['dist_selected_success|client_a'] ?? [];
        $this->assertSame('applied', $row['last_status'] ?? '');
        $this->assertSame(1, (int) ($row['attempt_count'] ?? 0));
    }

    public function test_shared_rules_profile_distribution_failures_mark_dead_letter_after_threshold(): void
    {
        update_option('dbvc_bricks_role', 'mothership');
        update_option(DBVC_Bricks_Addon::OPTION_SHARED_RULES_PROFILE, wp_json_encode([
            'profile_version' => 1,
            'updated_at' => gmdate('c'),
            'updated_by' => 'admin',
            'rules' => [
                'dbvc_bricks_diff_path_rules' => ['ignore' => ['*' => ['meta.runtime.last_seen']], 'mask' => []],
                'dbvc_bricks_artifact_ignore_rules' => [],
                'dbvc_bricks_artifact_mask_rules' => [],
                'dbvc_bricks_meta_ignore_rules' => [],
                'dbvc_bricks_meta_mask_rules' => [],
            ],
        ]));
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, [
            'client_a' => ['site_uid' => 'client_a', 'base_url' => 'https://client-a.local', 'status' => 'online', 'allow_receive_packages' => 1],
        ]);
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, [
            'client_a' => ['site_uid' => 'client_a', 'command_secret' => 'hs_client_a'],
        ]);

        add_filter('pre_http_request', static function () {
            return new WP_Error('http_error', 'network down');
        }, 10, 0);

        for ($i = 1; $i <= 3; $i++) {
            $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/configure/shared-rules-profile/distribute');
            $request->set_header('Content-Type', 'application/json');
            $request->set_header('Idempotency-Key', 'dist-fail-' . $i);
            $request->set_body(wp_json_encode([
                'target_mode' => 'selected',
                'site_uids' => ['client_a'],
                'distribution_id' => 'dist_failure_threshold',
            ]));
            $response = rest_get_server()->dispatch($request);
            $this->assertSame(200, $response->get_status());
        }

        $transport = get_option(DBVC_Bricks_Addon::OPTION_SHARED_RULES_TRANSPORT, []);
        $row = $transport['dist_failure_threshold|client_a'] ?? [];
        $this->assertSame('failed', $row['last_status'] ?? '');
        $this->assertSame(3, (int) ($row['attempt_count'] ?? 0));
        $this->assertTrue((bool) ($row['dead_letter'] ?? false));
        $this->assertSame('', (string) ($row['next_retry_at'] ?? ''));
    }

    private function reset_rest_server(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }
}
