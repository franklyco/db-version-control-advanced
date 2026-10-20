<?php

class BricksAddonPhase19DTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        update_option('dbvc_bricks_role', 'mothership');
        update_option(DBVC_Bricks_Idempotency::OPTION_STORE, []);
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, []);
        update_option(DBVC_Bricks_Connected_Sites::OPTION_SITE_ALIASES, []);
        update_option(DBVC_Bricks_Connected_Sites::OPTION_SITE_SEQUENCE, 0);
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, []);
        update_option(DBVC_Bricks_Command_Queue::OPTION_QUEUE, []);
        update_option(DBVC_Bricks_Command_Queue::OPTION_PROCESSED, []);
        update_option(DBVC_Bricks_Command_Queue::OPTION_CLIENT_NONCES, []);
        update_option(DBVC_Bricks_Addon::OPTION_SHARED_RULES_PROFILE, '{}');
        update_option('dbvc_bricks_command_transport_mode', 'direct_push');
        update_option('dbvc_bricks_command_retry_max_attempts', '3');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        update_option('dbvc_bricks_role', 'client');
        update_option(DBVC_Bricks_Idempotency::OPTION_STORE, []);
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, []);
        update_option(DBVC_Bricks_Connected_Sites::OPTION_SITE_ALIASES, []);
        update_option(DBVC_Bricks_Connected_Sites::OPTION_SITE_SEQUENCE, 0);
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, []);
        update_option(DBVC_Bricks_Command_Queue::OPTION_QUEUE, []);
        update_option(DBVC_Bricks_Command_Queue::OPTION_PROCESSED, []);
        update_option(DBVC_Bricks_Command_Queue::OPTION_CLIENT_NONCES, []);
        update_option(DBVC_Bricks_Addon::OPTION_SHARED_RULES_PROFILE, '{}');
        update_option('dbvc_bricks_command_transport_mode', 'direct_push');
        update_option('dbvc_bricks_command_retry_max_attempts', '3');
        remove_all_filters('pre_http_request');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        parent::tear_down();
    }

    public function test_commands_enqueue_creates_site_bound_envelope(): void
    {
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, [
            'client_a' => ['site_uid' => 'client_a', 'status' => 'online', 'allow_receive_packages' => 1],
        ]);
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, [
            'client_a' => ['site_uid' => 'client_a', 'command_secret' => 'hs_client_a'],
        ]);

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/commands/enqueue');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Idempotency-Key', 'cmd-enqueue-1');
        $request->set_body(wp_json_encode([
            'command_type' => 'shared_rules_apply',
            'target_mode' => 'selected',
            'site_uids' => ['client_a'],
            'distribution_id' => 'dist_cmdq_1',
            'payload' => ['distribution_id' => 'dist_cmdq_1', 'profile' => ['profile_version' => 1]],
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue((bool) ($data['ok'] ?? false));
        $this->assertSame(1, (int) ($data['summary']['queued'] ?? 0));
        $this->assertSame(0, (int) ($data['summary']['failed'] ?? 0));

        $queue = get_option(DBVC_Bricks_Command_Queue::OPTION_QUEUE, []);
        $this->assertIsArray($queue);
        $this->assertCount(1, $queue);
        $first = array_values($queue)[0];
        $this->assertSame('client_a', $first['site_uid'] ?? '');
        $this->assertSame('queued', $first['state'] ?? '');
        $this->assertSame('dist_cmdq_1', $first['context']['distribution_id'] ?? '');
    }

    public function test_commands_enqueue_refresh_shared_rules_builds_distribution_payload(): void
    {
        update_option(DBVC_Bricks_Addon::OPTION_SHARED_RULES_PROFILE, wp_json_encode([
            'profile_version' => 9,
            'updated_at' => gmdate('c'),
            'updated_by' => 'qa',
            'notes' => 'refresh_shared_rules payload test',
            'rules' => [
                'dbvc_bricks_diff_path_rules' => ['ignore' => ['*' => ['meta.runtime.last_seen']], 'mask' => []],
                'dbvc_bricks_artifact_ignore_rules' => [],
                'dbvc_bricks_artifact_mask_rules' => [],
                'dbvc_bricks_meta_ignore_rules' => [],
                'dbvc_bricks_meta_mask_rules' => [],
            ],
            'non_goals' => ['no_automatic_artifact_apply'],
        ]));
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, [
            'client_a' => ['site_uid' => 'client_a', 'status' => 'online', 'allow_receive_packages' => 1],
        ]);
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, [
            'client_a' => ['site_uid' => 'client_a', 'command_secret' => 'hs_client_a'],
        ]);

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/commands/enqueue');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Idempotency-Key', 'cmd-refresh-shared-rules-1');
        $request->set_body(wp_json_encode([
            'command' => 'refresh_shared_rules',
            'target_mode' => 'selected',
            'site_uids' => ['client_a'],
            'distribution_id' => 'dist_refresh_shared_rules_1',
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue((bool) ($data['ok'] ?? false), wp_json_encode($data));
        $this->assertSame('shared_rules_apply', (string) ($data['command_type'] ?? ''));
        $this->assertSame('dist_refresh_shared_rules_1', (string) ($data['distribution_id'] ?? ''));

        $queue = get_option(DBVC_Bricks_Command_Queue::OPTION_QUEUE, []);
        $this->assertIsArray($queue);
        $first = array_values($queue)[0];
        $payload = is_array($first['payload'] ?? null) ? $first['payload'] : [];
        $profile = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];

        $this->assertSame('dist_refresh_shared_rules_1', (string) ($payload['distribution_id'] ?? ''));
        $this->assertSame(9, (int) ($profile['profile_version'] ?? 0));
        $this->assertSame('refresh_shared_rules payload test', (string) ($profile['notes'] ?? ''));
    }

    public function test_commands_pull_leases_envelopes_for_site(): void
    {
        $this->seed_single_envelope_for_client_a();

        $pull = new WP_REST_Request('POST', '/dbvc/v1/bricks/commands/pull');
        $pull->set_header('Content-Type', 'application/json');
        $pull->set_body(wp_json_encode([
            'site_uid' => 'client_a',
            'limit' => 10,
        ]));
        $response = rest_get_server()->dispatch($pull);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame(1, (int) ($data['count'] ?? 0));
        $this->assertSame('leased', (string) ($data['items'][0]['state'] ?? ''));
        $this->assertSame(1, (int) ($data['items'][0]['attempt_count'] ?? 0));
    }

    public function test_commands_pull_refreshes_signature_for_lease_and_tracks_pull_activity(): void
    {
        $this->seed_single_envelope_for_client_a();

        $queue = get_option(DBVC_Bricks_Command_Queue::OPTION_QUEUE, []);
        $this->assertIsArray($queue);
        $this->assertCount(1, $queue);
        $envelope_id = array_key_first($queue);
        $this->assertIsString($envelope_id);
        $queue[$envelope_id]['signature'] = 'invalid_sig';
        $queue[$envelope_id]['signature_meta'] = [
            'alg' => 'hmac-sha256',
            'timestamp' => time() - (DBVC_Bricks_Command_Auth::TIMESTAMP_WINDOW_SECONDS + 120),
            'nonce' => 'old_nonce',
            'site_uid' => 'client_a',
        ];
        update_option(DBVC_Bricks_Command_Queue::OPTION_QUEUE, $queue);

        $pull = new WP_REST_Request('POST', '/dbvc/v1/bricks/commands/pull');
        $pull->set_header('Content-Type', 'application/json');
        $pull->set_body(wp_json_encode([
            'site_uid' => 'client_a',
            'limit' => 10,
        ]));
        $response = rest_get_server()->dispatch($pull);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame(1, (int) ($data['count'] ?? 0));
        $leased = $data['items'][0] ?? [];
        $this->assertNotSame('invalid_sig', (string) ($leased['signature'] ?? ''));
        $this->assertSame('hmac-sha256', (string) ($leased['signature_meta']['alg'] ?? ''));
        $this->assertLessThanOrEqual(
            DBVC_Bricks_Command_Auth::TIMESTAMP_WINDOW_SECONDS,
            abs(time() - (int) ($leased['signature_meta']['timestamp'] ?? 0))
        );

        $site = DBVC_Bricks_Connected_Sites::get_site('client_a');
        $this->assertIsArray($site);
        $this->assertNotSame('', (string) ($site['last_pull_at'] ?? ''));
        $this->assertSame('client_a', (string) ($site['last_pull_incoming_uid'] ?? ''));
    }

    public function test_commands_ack_failed_moves_to_dead_letter_at_max_attempts(): void
    {
        update_option('dbvc_bricks_command_retry_max_attempts', '1');
        $this->seed_single_envelope_for_client_a();

        $pull = new WP_REST_Request('POST', '/dbvc/v1/bricks/commands/pull');
        $pull->set_header('Content-Type', 'application/json');
        $pull->set_body(wp_json_encode(['site_uid' => 'client_a']));
        $pull_response = rest_get_server()->dispatch($pull);
        $this->assertSame(200, $pull_response->get_status());
        $envelope_id = (string) ($pull_response->get_data()['items'][0]['envelope_id'] ?? '');
        $this->assertNotSame('', $envelope_id);

        $ack = new WP_REST_Request('POST', '/dbvc/v1/bricks/commands/ack');
        $ack->set_header('Content-Type', 'application/json');
        $ack->set_header('Idempotency-Key', 'ack-dead-letter-1');
        $ack->set_body(wp_json_encode([
            'envelope_id' => $envelope_id,
            'site_uid' => 'client_a',
            'state' => 'failed',
            'result' => ['error_code' => 'apply_failed', 'error_message' => 'simulated failure'],
        ]));
        $ack_response = rest_get_server()->dispatch($ack);
        $this->assertSame(200, $ack_response->get_status());
        $envelope = $ack_response->get_data()['envelope'] ?? [];
        $this->assertSame('dead_letter', (string) ($envelope['state'] ?? ''));
        $this->assertSame('', (string) ($envelope['next_attempt_at'] ?? ''));
        $this->assertSame('apply_failed', (string) ($envelope['last_error_code'] ?? ''));
    }

    public function test_commands_pull_missing_site_uid_returns_operator_hints_and_diagnostic(): void
    {
        $pull = new WP_REST_Request('POST', '/dbvc/v1/bricks/commands/pull');
        $pull->set_header('Content-Type', 'application/json');
        $pull->set_body(wp_json_encode([]));
        $response = rest_get_server()->dispatch($pull);

        $this->assertSame(400, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('dbvc_bricks_command_pull_site_uid_required', (string) ($data['code'] ?? ''));
        $this->assertSame('blocked_site_uid_required', (string) (($data['data']['classification'] ?? '')));
        $this->assertSame('commands/pull', (string) (($data['data']['endpoint'] ?? '')));
        $this->assertSame('missing_site_uid', (string) (($data['data']['reason'] ?? '')));
        $this->assertNotSame('', (string) (($data['data']['remediation_hint'] ?? '')));

        $diagnostics = get_option(DBVC_Bricks_Addon::OPTION_UI_DIAGNOSTICS, []);
        $this->assertIsArray($diagnostics);
        $matches = array_values(array_filter($diagnostics, static function ($row) {
            if (! is_array($row) || (($row['event_type'] ?? '') !== 'command_pull_rejected')) {
                return false;
            }
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            return (($payload['error_code'] ?? '') === 'dbvc_bricks_command_pull_site_uid_required')
                && (($payload['reason'] ?? '') === 'missing_site_uid');
        }));
        $this->assertNotEmpty($matches);
    }

    public function test_commands_ack_missing_idempotency_returns_operator_hints_and_diagnostic(): void
    {
        $ack = new WP_REST_Request('POST', '/dbvc/v1/bricks/commands/ack');
        $ack->set_header('Content-Type', 'application/json');
        $ack->set_body(wp_json_encode([
            'envelope_id' => 'env_missing_idempotency',
            'site_uid' => 'client_a',
            'state' => 'failed',
        ]));
        $response = rest_get_server()->dispatch($ack);

        $this->assertSame(400, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('dbvc_bricks_idempotency_required', (string) ($data['code'] ?? ''));
        $this->assertSame('blocked_idempotency_missing', (string) (($data['data']['classification'] ?? '')));
        $this->assertSame('commands/ack', (string) (($data['data']['endpoint'] ?? '')));
        $this->assertSame('missing_idempotency_key', (string) (($data['data']['reason'] ?? '')));
        $this->assertNotSame('', (string) (($data['data']['remediation_hint'] ?? '')));

        $diagnostics = get_option(DBVC_Bricks_Addon::OPTION_UI_DIAGNOSTICS, []);
        $this->assertIsArray($diagnostics);
        $matches = array_values(array_filter($diagnostics, static function ($row) {
            if (! is_array($row) || (($row['event_type'] ?? '') !== 'command_ack_rejected')) {
                return false;
            }
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            return (($payload['error_code'] ?? '') === 'dbvc_bricks_idempotency_required')
                && (($payload['reason'] ?? '') === 'missing_idempotency_key');
        }));
        $this->assertNotEmpty($matches);
    }

    public function test_commands_ack_invalid_payload_returns_operator_hints_and_diagnostic(): void
    {
        $ack = new WP_REST_Request('POST', '/dbvc/v1/bricks/commands/ack');
        $ack->set_header('Content-Type', 'application/json');
        $ack->set_header('Idempotency-Key', 'ack-invalid-payload-1');
        $ack->set_body(wp_json_encode([
            'site_uid' => 'client_a',
            'state' => 'failed',
        ]));
        $response = rest_get_server()->dispatch($ack);

        $this->assertSame(400, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('dbvc_bricks_command_ack_invalid', (string) ($data['code'] ?? ''));
        $this->assertSame('blocked_ack_invalid', (string) (($data['data']['classification'] ?? '')));
        $this->assertSame('commands/ack', (string) (($data['data']['endpoint'] ?? '')));
        $this->assertSame('ack_validation_failed', (string) (($data['data']['reason'] ?? '')));
        $this->assertNotSame('', (string) (($data['data']['remediation_hint'] ?? '')));

        $diagnostics = get_option(DBVC_Bricks_Addon::OPTION_UI_DIAGNOSTICS, []);
        $this->assertIsArray($diagnostics);
        $matches = array_values(array_filter($diagnostics, static function ($row) {
            if (! is_array($row) || (($row['event_type'] ?? '') !== 'command_ack_rejected')) {
                return false;
            }
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            return (($payload['error_code'] ?? '') === 'dbvc_bricks_command_ack_invalid')
                && (($payload['reason'] ?? '') === 'ack_validation_failed');
        }));
        $this->assertNotEmpty($matches);
    }

    public function test_commands_ack_secret_missing_marks_site_pending_intro_for_recovery(): void
    {
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, [
            'client_a' => [
                'site_uid' => 'client_a',
                'status' => 'online',
                'allow_receive_packages' => 1,
                'onboarding_state' => 'VERIFIED',
            ],
        ]);
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, [
            'client_a' => [
                'site_uid' => 'client_a',
                'onboarding_state' => 'verified',
                'command_secret' => 'hs_client_a',
                'handshake_token_hash' => hash('sha256', 'hs_client_a'),
            ],
        ]);
        $this->seed_single_envelope_for_client_a();

        $pull = new WP_REST_Request('POST', '/dbvc/v1/bricks/commands/pull');
        $pull->set_header('Content-Type', 'application/json');
        $pull->set_body(wp_json_encode(['site_uid' => 'client_a']));
        $pull_response = rest_get_server()->dispatch($pull);
        $this->assertSame(200, $pull_response->get_status());
        $envelope_id = (string) ($pull_response->get_data()['items'][0]['envelope_id'] ?? '');
        $this->assertNotSame('', $envelope_id);

        $ack = new WP_REST_Request('POST', '/dbvc/v1/bricks/commands/ack');
        $ack->set_header('Content-Type', 'application/json');
        $ack->set_header('Idempotency-Key', 'ack-secret-missing-1');
        $ack->set_body(wp_json_encode([
            'envelope_id' => $envelope_id,
            'site_uid' => 'client_a',
            'state' => 'failed',
            'result' => [
                'error_code' => 'dbvc_bricks_client_envelope_secret_missing',
                'error_message' => 'Client handshake token is missing.',
            ],
        ]));
        $ack_response = rest_get_server()->dispatch($ack);
        $this->assertSame(200, $ack_response->get_status(), wp_json_encode($ack_response->get_data()));

        $connected = DBVC_Bricks_Connected_Sites::get_site('client_a');
        $this->assertIsArray($connected);
        $this->assertSame('PENDING_INTRO', (string) ($connected['onboarding_state'] ?? ''));
        $this->assertSame(0, (int) ($connected['allow_receive_packages'] ?? 1));

        $clients = get_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, []);
        $client = is_array($clients) ? ($clients['client_a'] ?? []) : [];
        $this->assertSame('pending_intro', (string) ($client['onboarding_state'] ?? ''));
        $this->assertSame('', (string) ($client['command_secret'] ?? ''));
        $this->assertSame('', (string) ($client['handshake_token_hash'] ?? ''));

        $diagnostics = get_option(DBVC_Bricks_Addon::OPTION_UI_DIAGNOSTICS, []);
        $this->assertIsArray($diagnostics);
        $events = array_values(array_filter($diagnostics, static function ($row) {
            return is_array($row) && (($row['event_type'] ?? '') === 'command_site_pending_intro_required');
        }));
        $this->assertNotEmpty($events);
    }

    public function test_shared_rules_distribution_client_pull_mode_enqueues_instead_of_direct_push(): void
    {
        update_option('dbvc_bricks_command_transport_mode', 'client_pull_envelope');
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
        ]);
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, [
            'client_a' => ['site_uid' => 'client_a', 'command_secret' => 'hs_client_a'],
        ]);

        $http_calls = 0;
        add_filter('pre_http_request', static function () use (&$http_calls) {
            $http_calls++;
            return new WP_Error('unexpected_http_call', 'direct push should not execute in client_pull_envelope mode');
        }, 10, 0);

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/configure/shared-rules-profile/distribute');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Idempotency-Key', 'dist-client-pull-1');
        $request->set_body(wp_json_encode([
            'target_mode' => 'selected',
            'site_uids' => ['client_a'],
            'distribution_id' => 'dist_client_pull_mode',
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue((bool) ($data['ok'] ?? false));
        $this->assertSame('client_pull_envelope', (string) ($data['transport_mode'] ?? ''));
        $this->assertSame(1, (int) ($data['summary']['queued'] ?? 0));
        $this->assertSame(0, $http_calls);
    }

    public function test_client_pull_tick_applies_shared_rules_and_posts_ack(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_site_uid', 'client_a');
        update_option('dbvc_bricks_intro_handshake_token', 'hs_client_a');
        update_option('dbvc_bricks_command_transport_mode', 'client_pull_envelope');
        update_option('dbvc_bricks_mothership_url', 'https://mothership.local');
        update_option('dbvc_bricks_api_key_id', 'integration_user');
        update_option('dbvc_bricks_api_secret', 'app password');

        $ackCalls = [];
        add_filter('pre_http_request', static function ($preempt, $args, $url) use (&$ackCalls) {
            $url = (string) $url;
            if (strpos($url, '/wp-json/dbvc/v1/bricks/commands/pull') !== false) {
                $payload = [
                    'distribution_id' => 'dist_live_client_pull',
                    'profile' => [
                        'profile_version' => 5,
                        'updated_at' => gmdate('c'),
                        'updated_by' => 'mothership',
                        'notes' => 'client pull tick',
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
                $sigTs = time();
                $sig = DBVC_Bricks_Command_Auth::build_signature('hs_client_a', $sigTs, 'env_nonce_1', 'client_a', $raw);
                return [
                    'headers' => [],
                    'body' => wp_json_encode([
                        'ok' => true,
                        'site_uid' => 'client_a',
                        'items' => [[
                            'envelope_id' => 'env_client_pull_1',
                            'command_type' => 'shared_rules_apply',
                            'site_uid' => 'client_a',
                            'payload' => $payload,
                            'signature' => $sig,
                            'signature_meta' => [
                                'alg' => 'hmac-sha256',
                                'timestamp' => $sigTs,
                                'nonce' => 'env_nonce_1',
                                'site_uid' => 'client_a',
                            ],
                            'state' => 'leased',
                            'attempt_count' => 1,
                            'max_attempts' => 3,
                            'expires_at' => gmdate('c', time() + HOUR_IN_SECONDS),
                        ]],
                        'count' => 1,
                    ]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }

            if (strpos($url, '/wp-json/dbvc/v1/bricks/commands/ack') !== false) {
                $ackCalls[] = [
                    'headers' => $args['headers'] ?? [],
                    'body' => isset($args['body']) ? json_decode((string) $args['body'], true) : [],
                ];
                return [
                    'headers' => [],
                    'body' => wp_json_encode(['ok' => true]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }

            return $preempt;
        }, 10, 3);

        $result = DBVC_Bricks_Command_Queue::run_client_pull_tick('phpunit');
        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertSame(1, (int) ($result['applied'] ?? 0));
        $this->assertCount(1, $ackCalls);
        $this->assertSame('applied', (string) ($ackCalls[0]['body']['state'] ?? ''));
        $this->assertSame('client_a', (string) ($ackCalls[0]['body']['site_uid'] ?? ''));
        $this->assertSame('env_client_pull_1', (string) ($ackCalls[0]['body']['envelope_id'] ?? ''));
        $this->assertStringContainsString('meta.runtime.last_seen', (string) get_option('dbvc_bricks_diff_path_rules'));
        $processed = get_option(DBVC_Bricks_Command_Queue::OPTION_PROCESSED, []);
        $this->assertArrayHasKey('env_client_pull_1', is_array($processed) ? $processed : []);
    }

    public function test_client_pull_tick_rejects_stale_signature_timestamp_and_acks_failed(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_site_uid', 'client_a');
        update_option('dbvc_bricks_intro_handshake_token', 'hs_client_a');
        update_option('dbvc_bricks_command_transport_mode', 'client_pull_envelope');
        update_option('dbvc_bricks_mothership_url', 'https://mothership.local');
        update_option('dbvc_bricks_api_key_id', 'integration_user');
        update_option('dbvc_bricks_api_secret', 'app password');

        $ackCalls = [];
        add_filter('pre_http_request', static function ($preempt, $args, $url) use (&$ackCalls) {
            $url = (string) $url;
            if (strpos($url, '/wp-json/dbvc/v1/bricks/commands/pull') !== false) {
                $payload = ['distribution_id' => 'dist_stale_sig', 'profile' => ['profile_version' => 1, 'rules' => []]];
                $raw = (string) wp_json_encode($payload);
                $sigTs = time() - (DBVC_Bricks_Command_Auth::TIMESTAMP_WINDOW_SECONDS + 30);
                $sig = DBVC_Bricks_Command_Auth::build_signature('hs_client_a', $sigTs, 'env_nonce_stale', 'client_a', $raw);
                return [
                    'headers' => [],
                    'body' => wp_json_encode([
                        'ok' => true,
                        'site_uid' => 'client_a',
                        'items' => [[
                            'envelope_id' => 'env_stale_sig',
                            'command_type' => 'shared_rules_apply',
                            'site_uid' => 'client_a',
                            'payload' => $payload,
                            'payload_hash' => 'sha256:' . hash('sha256', $raw),
                            'signature' => $sig,
                            'signature_meta' => [
                                'alg' => 'hmac-sha256',
                                'timestamp' => $sigTs,
                                'nonce' => 'env_nonce_stale',
                                'site_uid' => 'client_a',
                            ],
                            'state' => 'leased',
                            'attempt_count' => 1,
                            'max_attempts' => 3,
                            'expires_at' => gmdate('c', time() + HOUR_IN_SECONDS),
                        ]],
                        'count' => 1,
                    ]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }
            if (strpos($url, '/wp-json/dbvc/v1/bricks/commands/ack') !== false) {
                $ackCalls[] = isset($args['body']) ? json_decode((string) $args['body'], true) : [];
                return [
                    'headers' => [],
                    'body' => wp_json_encode(['ok' => true]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }
            return $preempt;
        }, 10, 3);

        $result = DBVC_Bricks_Command_Queue::run_client_pull_tick('phpunit');
        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertSame(1, (int) ($result['failed'] ?? 0));
        $this->assertCount(1, $ackCalls);
        $this->assertSame('failed', (string) ($ackCalls[0]['state'] ?? ''));
        $this->assertSame('dbvc_bricks_client_envelope_timestamp_invalid', (string) ($ackCalls[0]['result']['error_code'] ?? ''));
    }

    public function test_client_pull_tick_rejects_nonce_replay_for_different_envelope_ids(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_site_uid', 'client_a');
        update_option('dbvc_bricks_intro_handshake_token', 'hs_client_a');
        update_option('dbvc_bricks_command_transport_mode', 'client_pull_envelope');
        update_option('dbvc_bricks_mothership_url', 'https://mothership.local');
        update_option('dbvc_bricks_api_key_id', 'integration_user');
        update_option('dbvc_bricks_api_secret', 'app password');

        $ackCalls = [];
        add_filter('pre_http_request', static function ($preempt, $args, $url) use (&$ackCalls) {
            $url = (string) $url;
            if (strpos($url, '/wp-json/dbvc/v1/bricks/commands/pull') !== false) {
                $payload = [
                    'distribution_id' => 'dist_nonce_replay',
                    'profile' => [
                        'profile_version' => 6,
                        'updated_at' => gmdate('c'),
                        'updated_by' => 'mothership',
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
                $sigTs = time();
                $nonce = 'env_nonce_replay_1';
                $sig = DBVC_Bricks_Command_Auth::build_signature('hs_client_a', $sigTs, $nonce, 'client_a', $raw);
                $baseEnvelope = [
                    'command_type' => 'shared_rules_apply',
                    'site_uid' => 'client_a',
                    'payload' => $payload,
                    'payload_hash' => 'sha256:' . hash('sha256', $raw),
                    'signature' => $sig,
                    'signature_meta' => [
                        'alg' => 'hmac-sha256',
                        'timestamp' => $sigTs,
                        'nonce' => $nonce,
                        'site_uid' => 'client_a',
                    ],
                    'state' => 'leased',
                    'attempt_count' => 1,
                    'max_attempts' => 3,
                    'expires_at' => gmdate('c', time() + HOUR_IN_SECONDS),
                ];
                return [
                    'headers' => [],
                    'body' => wp_json_encode([
                        'ok' => true,
                        'site_uid' => 'client_a',
                        'items' => [
                            array_merge(['envelope_id' => 'env_nonce_replay_a'], $baseEnvelope),
                            array_merge(['envelope_id' => 'env_nonce_replay_b'], $baseEnvelope),
                        ],
                        'count' => 2,
                    ]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }
            if (strpos($url, '/wp-json/dbvc/v1/bricks/commands/ack') !== false) {
                $ackCalls[] = isset($args['body']) ? json_decode((string) $args['body'], true) : [];
                return [
                    'headers' => [],
                    'body' => wp_json_encode(['ok' => true]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }
            return $preempt;
        }, 10, 3);

        $result = DBVC_Bricks_Command_Queue::run_client_pull_tick('phpunit');
        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertSame(1, (int) ($result['applied'] ?? 0));
        $this->assertSame(1, (int) ($result['failed'] ?? 0));
        $this->assertCount(2, $ackCalls);
        $this->assertSame('applied', (string) ($ackCalls[0]['state'] ?? ''));
        $this->assertSame('failed', (string) ($ackCalls[1]['state'] ?? ''));
        $this->assertSame('dbvc_bricks_client_envelope_nonce_replay', (string) ($ackCalls[1]['result']['error_code'] ?? ''));
    }

    public function test_commands_enqueue_blocks_duplicate_base_url_alias(): void
    {
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, [
            'client_a' => [
                'site_uid' => 'client_a',
                'base_url' => 'https://same-client.local',
                'status' => 'online',
                'allow_receive_packages' => 1,
                'updated_at' => gmdate('c', time() + 60),
            ],
            'client_b' => [
                'site_uid' => 'client_b',
                'base_url' => 'https://same-client.local/',
                'status' => 'online',
                'allow_receive_packages' => 1,
                'updated_at' => gmdate('c'),
            ],
        ]);
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, [
            'client_a' => ['site_uid' => 'client_a', 'command_secret' => 'hs_client_a'],
            'client_b' => ['site_uid' => 'client_b', 'command_secret' => 'hs_client_b'],
        ]);

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/commands/enqueue');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Idempotency-Key', 'cmd-enqueue-dup-conflict');
        $request->set_body(wp_json_encode([
            'command_type' => 'shared_rules_apply',
            'target_mode' => 'selected',
            'site_uids' => ['client_b'],
            'distribution_id' => 'dist_dup_conflict',
            'payload' => ['distribution_id' => 'dist_dup_conflict', 'profile' => ['profile_version' => 1]],
        ]));

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse((bool) ($data['ok'] ?? true), 'enqueue response: ' . wp_json_encode($data));
        $this->assertSame(0, (int) ($data['summary']['queued'] ?? -1));
        $this->assertSame(1, (int) ($data['summary']['failed'] ?? 0));
        $this->assertSame('site_uid_conflict_duplicate_base_url', (string) ($data['results'][0]['error_code'] ?? ''));
        $this->assertSame('blocked_duplicate_conflict', (string) ($data['results'][0]['classification'] ?? ''));
        $this->assertSame('client_a', (string) ($data['results'][0]['canonical_site_uid'] ?? ''));
        $this->assertSame(1, (int) ($data['preflight']['classification_counts']['blocked_duplicate_conflict'] ?? 0));
    }

    public function test_duplicate_conflict_prefers_verified_linked_canonical_over_pending_intro(): void
    {
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, [
            'client_ready' => [
                'site_uid' => 'client_ready',
                'base_url' => 'https://same-linked.local',
                'status' => 'online',
                'allow_receive_packages' => 1,
                'onboarding_state' => 'VERIFIED',
                'updated_at' => gmdate('c'),
                'last_pull_at' => gmdate('c'),
            ],
            'client_pending' => [
                'site_uid' => 'client_pending',
                'base_url' => 'https://same-linked.local/',
                'status' => 'online',
                'allow_receive_packages' => 1,
                'onboarding_state' => 'PENDING_INTRO',
                'updated_at' => gmdate('c', time() + 300),
            ],
        ]);
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, [
            'client_ready' => [
                'site_uid' => 'client_ready',
                'onboarding_state' => 'verified',
                'command_secret' => 'hs_ready',
                'handshake_token_hash' => hash('sha256', 'hs_ready'),
            ],
            'client_pending' => [
                'site_uid' => 'client_pending',
                'onboarding_state' => 'pending_intro',
                'command_secret' => '',
                'handshake_token_hash' => '',
            ],
        ]);

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/commands/enqueue');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Idempotency-Key', 'cmd-enqueue-canonical-health');
        $request->set_body(wp_json_encode([
            'command_type' => 'shared_rules_apply',
            'target_mode' => 'selected',
            'site_uids' => ['client_ready'],
            'distribution_id' => 'dist_canonical_health',
            'payload' => ['distribution_id' => 'dist_canonical_health', 'profile' => ['profile_version' => 1]],
        ]));

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue((bool) ($data['ok'] ?? false), wp_json_encode($data));
        $this->assertSame(1, (int) ($data['summary']['queued'] ?? 0));
        $this->assertSame(0, (int) ($data['summary']['failed'] ?? -1));
        $this->assertSame('client_ready', (string) ($data['results'][0]['resolved_site_uid'] ?? ''));

        $conflicts = DBVC_Bricks_Connected_Sites::build_conflict_map();
        $this->assertIsArray($conflicts);
        $this->assertSame('client_ready', (string) ($conflicts['client_ready']['canonical_site_uid'] ?? ''));
        $this->assertSame('client_ready', (string) ($conflicts['client_pending']['canonical_site_uid'] ?? ''));
        $this->assertSame(1, (int) ($conflicts['client_ready']['is_targetable'] ?? 0));
        $this->assertSame(0, (int) ($conflicts['client_pending']['is_targetable'] ?? 1));
    }

    public function test_commands_enqueue_auto_reroutes_duplicate_conflict_to_recent_pull_canonical(): void
    {
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, [
            'client_a' => [
                'site_uid' => 'client_a',
                'base_url' => 'https://same-client.local',
                'status' => 'online',
                'allow_receive_packages' => 1,
                'onboarding_state' => 'VERIFIED',
                'updated_at' => gmdate('c', time() + 60),
                'last_pull_at' => gmdate('c'),
                'local_instance_uuid' => 'inst_shared_1',
            ],
            'client_b' => [
                'site_uid' => 'client_b',
                'base_url' => 'https://same-client.local/',
                'status' => 'online',
                'allow_receive_packages' => 1,
                'onboarding_state' => 'VERIFIED',
                'updated_at' => gmdate('c'),
                'local_instance_uuid' => 'inst_shared_1',
            ],
        ]);
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, [
            'client_a' => ['site_uid' => 'client_a', 'command_secret' => 'hs_client_a'],
            'client_b' => ['site_uid' => 'client_b', 'command_secret' => 'hs_client_b'],
        ]);

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/commands/enqueue');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Idempotency-Key', 'cmd-enqueue-dup-reroute');
        $request->set_body(wp_json_encode([
            'command_type' => 'shared_rules_apply',
            'target_mode' => 'selected',
            'site_uids' => ['client_b'],
            'distribution_id' => 'dist_dup_reroute',
            'payload' => ['distribution_id' => 'dist_dup_reroute', 'profile' => ['profile_version' => 2]],
        ]));

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue((bool) ($data['ok'] ?? false), wp_json_encode($data));
        $this->assertSame(1, (int) ($data['summary']['queued'] ?? 0));
        $this->assertSame(0, (int) ($data['summary']['failed'] ?? -1));
        $this->assertSame('client_b', (string) ($data['results'][0]['site_uid'] ?? ''));
        $this->assertSame('client_a', (string) ($data['results'][0]['resolved_site_uid'] ?? ''));
        $this->assertSame('ready_alias_reroute', (string) ($data['results'][0]['classification'] ?? ''));
        $this->assertSame(1, (int) ($data['preflight']['classification_counts']['ready_alias_reroute'] ?? 0));

        $queue = get_option(DBVC_Bricks_Command_Queue::OPTION_QUEUE, []);
        $this->assertIsArray($queue);
        $this->assertCount(1, $queue);
        $first = array_values($queue)[0];
        $this->assertSame('client_a', (string) ($first['site_uid'] ?? ''));
        $context = is_array($first['context'] ?? null) ? $first['context'] : [];
        $this->assertSame('client_b', (string) ($context['incoming_site_uid'] ?? ''));
        $this->assertSame('client_a', (string) ($context['effective_site_uid'] ?? ''));
    }

    public function test_connected_sites_reset_linkage_and_merge_alias_actions(): void
    {
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, [
            'canonical_a' => [
                'site_uid' => 'canonical_a',
                'site_label' => 'Canonical A',
                'base_url' => 'https://same-alias.local',
                'status' => 'online',
                'allow_receive_packages' => 1,
                'onboarding_state' => 'VERIFIED',
            ],
            'alias_b' => [
                'site_uid' => 'alias_b',
                'site_label' => 'Alias B',
                'base_url' => 'https://same-alias.local/',
                'status' => 'online',
                'allow_receive_packages' => 1,
                'onboarding_state' => 'VERIFIED',
            ],
        ]);
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, [
            'canonical_a' => [
                'site_uid' => 'canonical_a',
                'onboarding_state' => 'verified',
                'command_secret' => 'hs_canonical',
                'handshake_token_hash' => hash('sha256', 'hs_canonical'),
            ],
            'alias_b' => [
                'site_uid' => 'alias_b',
                'onboarding_state' => 'verified',
                'command_secret' => 'hs_alias',
                'handshake_token_hash' => hash('sha256', 'hs_alias'),
            ],
        ]);

        $merge = new WP_REST_Request('POST', '/dbvc/v1/bricks/connected-sites/merge-alias');
        $merge->set_header('Content-Type', 'application/json');
        $merge->set_body(wp_json_encode([
            'canonical_site_uid' => 'canonical_a',
            'alias_site_uid' => 'alias_b',
            'confirm_merge' => true,
        ]));
        $merge_response = rest_get_server()->dispatch($merge);
        $this->assertSame(200, $merge_response->get_status(), 'merge response: ' . wp_json_encode($merge_response->get_data()));

        $alias_site = DBVC_Bricks_Connected_Sites::get_site('alias_b');
        $this->assertSame('disabled', (string) ($alias_site['status'] ?? ''));
        $this->assertSame(0, (int) ($alias_site['allow_receive_packages'] ?? 1));
        $this->assertSame('DISABLED', (string) ($alias_site['onboarding_state'] ?? ''));

        $reset = new WP_REST_Request('POST', '/dbvc/v1/bricks/connected-sites/reset-linkage');
        $reset->set_header('Content-Type', 'application/json');
        $reset->set_body(wp_json_encode([
            'site_uid' => 'canonical_a',
            'confirm_reset' => true,
        ]));
        $reset_response = rest_get_server()->dispatch($reset);
        $this->assertSame(200, $reset_response->get_status());

        $canonical_site = DBVC_Bricks_Connected_Sites::get_site('canonical_a');
        $this->assertSame('PENDING_INTRO', (string) ($canonical_site['onboarding_state'] ?? ''));
        $this->assertSame(0, (int) ($canonical_site['allow_receive_packages'] ?? 1));

        $clients = get_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, []);
        $canonical_client = $clients['canonical_a'] ?? [];
        $this->assertSame('pending_intro', (string) ($canonical_client['onboarding_state'] ?? ''));
        $this->assertSame('', (string) ($canonical_client['command_secret'] ?? ''));
        $this->assertSame('', (string) ($canonical_client['handshake_token_hash'] ?? ''));
    }

    public function test_connected_sites_forget_linkage_hides_row_and_allows_recovery_after_verified_handshake(): void
    {
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, [
            'client_a' => [
                'site_uid' => 'client_a',
                'site_label' => 'Client A',
                'base_url' => 'https://client-a.local',
                'status' => 'online',
                'allow_receive_packages' => 1,
                'onboarding_state' => 'VERIFIED',
            ],
        ]);
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, [
            'client_a' => [
                'site_uid' => 'client_a',
                'site_label' => 'Client A',
                'base_url' => 'https://client-a.local',
                'onboarding_state' => 'verified',
                'command_secret' => 'hs_client_a',
                'handshake_token_hash' => hash('sha256', 'hs_client_a'),
            ],
        ]);

        $forget = new WP_REST_Request('POST', '/dbvc/v1/bricks/connected-sites/forget-linkage');
        $forget->set_header('Content-Type', 'application/json');
        $forget->set_body(wp_json_encode([
            'site_uid' => 'client_a',
            'confirm_forget' => true,
            'confirm_site_uid' => 'client_a',
        ]));
        $forget_response = rest_get_server()->dispatch($forget);
        $this->assertSame(200, $forget_response->get_status(), wp_json_encode($forget_response->get_data()));

        $site_after_forget = DBVC_Bricks_Connected_Sites::get_site('client_a');
        $this->assertIsArray($site_after_forget);
        $this->assertSame(1, (int) ($site_after_forget['is_hidden'] ?? 0));
        $this->assertSame('disabled', (string) ($site_after_forget['status'] ?? ''));
        $this->assertSame('PENDING_INTRO', (string) ($site_after_forget['onboarding_state'] ?? ''));
        $this->assertSame(0, (int) ($site_after_forget['allow_receive_packages'] ?? 1));
        $this->assertSame('manual_forget_linkage', (string) ($site_after_forget['hidden_reason'] ?? ''));
        $this->assertNotSame('', (string) ($site_after_forget['hidden_at'] ?? ''));

        $clients = get_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, []);
        $client_after_forget = is_array($clients) && isset($clients['client_a']) && is_array($clients['client_a']) ? $clients['client_a'] : [];
        $this->assertSame('pending_intro', (string) ($client_after_forget['onboarding_state'] ?? ''));
        $this->assertSame('', (string) ($client_after_forget['command_secret'] ?? ''));
        $this->assertSame('', (string) ($client_after_forget['handshake_token_hash'] ?? ''));

        $list_default = new WP_REST_Request('GET', '/dbvc/v1/bricks/connected-sites');
        $list_default_response = rest_get_server()->dispatch($list_default);
        $this->assertSame(200, $list_default_response->get_status());
        $default_items = $list_default_response->get_data()['items'] ?? [];
        $this->assertIsArray($default_items);
        $this->assertCount(0, $default_items);
        $this->assertSame(1, (int) ($list_default_response->get_data()['hidden_count'] ?? 0));

        $list_with_hidden = new WP_REST_Request('GET', '/dbvc/v1/bricks/connected-sites');
        $list_with_hidden->set_param('include_hidden', 1);
        $list_with_hidden_response = rest_get_server()->dispatch($list_with_hidden);
        $this->assertSame(200, $list_with_hidden_response->get_status());
        $with_hidden_items = $list_with_hidden_response->get_data()['items'] ?? [];
        $this->assertIsArray($with_hidden_items);
        $this->assertCount(1, $with_hidden_items);
        $this->assertSame('client_a', (string) ($with_hidden_items[0]['site_uid'] ?? ''));
        $this->assertSame(1, (int) ($with_hidden_items[0]['is_hidden'] ?? 0));

        $intro_packet = new WP_REST_Request('POST', '/dbvc/v1/bricks/intro/packet');
        $intro_packet->set_header('Content-Type', 'application/json');
        $intro_packet->set_header('Idempotency-Key', 'intro-recover-visibility-1');
        $intro_packet->set_body(wp_json_encode([
            'site_uid' => 'client_a',
            'site_label' => 'Client A',
            'base_url' => 'https://client-a.local',
            'environment' => 'local',
            'capabilities' => ['read', 'pull'],
            'auth_profile' => ['method' => 'wp_app_password', 'key_id' => 'app_key_client_a'],
        ]));
        $intro_packet_response = rest_get_server()->dispatch($intro_packet);
        $this->assertSame(200, $intro_packet_response->get_status(), wp_json_encode($intro_packet_response->get_data()));

        $site_after_intro = DBVC_Bricks_Connected_Sites::get_site('client_a');
        $this->assertIsArray($site_after_intro);
        $this->assertSame(0, (int) ($site_after_intro['is_hidden'] ?? 1));
        $this->assertSame(0, (int) ($site_after_intro['allow_receive_packages'] ?? 1));
        $this->assertSame('PENDING_INTRO', (string) ($site_after_intro['onboarding_state'] ?? ''));
        $this->assertNotSame('', (string) ($site_after_intro['linkage_recovered_at'] ?? ''));

        $list_default_after_intro = new WP_REST_Request('GET', '/dbvc/v1/bricks/connected-sites');
        $list_default_after_intro_response = rest_get_server()->dispatch($list_default_after_intro);
        $this->assertSame(200, $list_default_after_intro_response->get_status());
        $default_after_intro_items = $list_default_after_intro_response->get_data()['items'] ?? [];
        $this->assertIsArray($default_after_intro_items);
        $this->assertCount(1, $default_after_intro_items);
        $this->assertSame(0, (int) ($list_default_after_intro_response->get_data()['hidden_count'] ?? 0));

        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, [
            'client_a' => [
                'site_uid' => 'client_a',
                'site_label' => 'Client A',
                'base_url' => 'https://client-a.local',
                'onboarding_state' => 'verified',
                'command_secret' => 'hs_client_a_new',
                'handshake_token_hash' => hash('sha256', 'hs_client_a_new'),
            ],
        ]);
        DBVC_Bricks_Connected_Sites::sync_from_onboarding_registry();
        $site_after_recovery = DBVC_Bricks_Connected_Sites::get_site('client_a');
        $this->assertIsArray($site_after_recovery);
        $this->assertSame(0, (int) ($site_after_recovery['is_hidden'] ?? 1));
        $this->assertSame(1, (int) ($site_after_recovery['allow_receive_packages'] ?? 0));
        $this->assertSame('VERIFIED', (string) ($site_after_recovery['onboarding_state'] ?? ''));
        $this->assertNotSame('', (string) ($site_after_recovery['linkage_recovered_at'] ?? ''));
    }

    public function test_merge_alias_self_heals_stale_reverse_alias_mapping(): void
    {
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, [
            'canonical_a' => [
                'site_uid' => 'canonical_a',
                'site_label' => 'Canonical A',
                'base_url' => 'https://same-cycle.local',
                'status' => 'online',
                'allow_receive_packages' => 1,
                'onboarding_state' => 'VERIFIED',
            ],
            'alias_b' => [
                'site_uid' => 'alias_b',
                'site_label' => 'Alias B',
                'base_url' => 'https://same-cycle.local/',
                'status' => 'online',
                'allow_receive_packages' => 1,
                'onboarding_state' => 'PENDING_INTRO',
            ],
        ]);
        update_option(DBVC_Bricks_Connected_Sites::OPTION_SITE_ALIASES, [
            'canonical_a' => [
                'alias_site_uid' => 'canonical_a',
                'canonical_site_uid' => 'alias_b',
                'reason' => 'stale_reverse_alias',
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
                'auto_generated' => 0,
            ],
        ]);

        $merge = new WP_REST_Request('POST', '/dbvc/v1/bricks/connected-sites/merge-alias');
        $merge->set_header('Content-Type', 'application/json');
        $merge->set_body(wp_json_encode([
            'canonical_site_uid' => 'canonical_a',
            'alias_site_uid' => 'alias_b',
            'confirm_merge' => true,
        ]));
        $merge_response = rest_get_server()->dispatch($merge);
        $this->assertSame(200, $merge_response->get_status(), wp_json_encode($merge_response->get_data()));

        $aliases = DBVC_Bricks_Connected_Sites::get_aliases();
        $this->assertArrayHasKey('alias_b', $aliases);
        $this->assertSame('canonical_a', (string) ($aliases['alias_b']['canonical_site_uid'] ?? ''));
        $this->assertArrayNotHasKey('canonical_a', $aliases);

        $alias_site = DBVC_Bricks_Connected_Sites::get_site('alias_b');
        $this->assertIsArray($alias_site);
        $this->assertSame('disabled', (string) ($alias_site['status'] ?? ''));
        $this->assertSame(0, (int) ($alias_site['allow_receive_packages'] ?? 1));
        $this->assertSame('DISABLED', (string) ($alias_site['onboarding_state'] ?? ''));
    }

    public function test_client_reset_rerun_intro_endpoint_clears_local_state(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_site_uid', 'client_a');
        update_option('dbvc_bricks_intro_handshake_token', 'hs_existing');
        update_option('dbvc_bricks_client_registry_state', 'VERIFIED');

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/onboarding/reset-rerun');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'confirm_reset' => true,
            'run_now' => false,
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertTrue((bool) ($data['ok'] ?? false));
        $this->assertSame('client_a', (string) ($data['site_uid'] ?? ''));
        $this->assertSame('', (string) get_option('dbvc_bricks_intro_handshake_token', ''));
        $this->assertSame('PENDING_INTRO', (string) get_option('dbvc_bricks_client_registry_state', ''));
    }

    public function test_known_alias_mapping_resolves_enqueue_to_canonical_site_uid(): void
    {
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, [
            'client_canonical' => [
                'site_uid' => 'client_canonical',
                'base_url' => 'https://canonical.local',
                'status' => 'online',
                'allow_receive_packages' => 1,
                'onboarding_state' => 'VERIFIED',
            ],
        ]);
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, [
            'client_canonical' => [
                'site_uid' => 'client_canonical',
                'onboarding_state' => 'verified',
                'command_secret' => 'hs_canonical',
            ],
        ]);

        $alias = new WP_REST_Request('POST', '/dbvc/v1/bricks/connected-sites/known-alias');
        $alias->set_header('Content-Type', 'application/json');
        $alias->set_body(wp_json_encode([
            'canonical_site_uid' => 'client_canonical',
            'alias_site_uid' => 'client_legacy',
            'confirm_alias' => true,
        ]));
        $alias_response = rest_get_server()->dispatch($alias);
        $this->assertSame(200, $alias_response->get_status());

        $enqueue = new WP_REST_Request('POST', '/dbvc/v1/bricks/commands/enqueue');
        $enqueue->set_header('Content-Type', 'application/json');
        $enqueue->set_header('Idempotency-Key', 'enqueue-known-alias');
        $enqueue->set_body(wp_json_encode([
            'command_type' => 'shared_rules_apply',
            'target_mode' => 'selected',
            'site_uids' => ['client_legacy'],
            'distribution_id' => 'dist_known_alias',
            'payload' => ['distribution_id' => 'dist_known_alias', 'profile' => ['profile_version' => 1]],
        ]));
        $enqueue_response = rest_get_server()->dispatch($enqueue);
        $this->assertSame(200, $enqueue_response->get_status());
        $data = $enqueue_response->get_data();
        $this->assertTrue((bool) ($data['ok'] ?? false), wp_json_encode($data));
        $this->assertSame('client_legacy', (string) ($data['results'][0]['site_uid'] ?? ''));
        $this->assertSame('client_canonical', (string) ($data['results'][0]['resolved_site_uid'] ?? ''));

        $queue = get_option(DBVC_Bricks_Command_Queue::OPTION_QUEUE, []);
        $this->assertIsArray($queue);
        $first = array_values($queue)[0];
        $this->assertSame('client_canonical', (string) ($first['site_uid'] ?? ''));
    }

    public function test_known_alias_mapping_rejects_non_targetable_duplicate_alias_as_canonical(): void
    {
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, [
            'canonical_a' => [
                'site_uid' => 'canonical_a',
                'base_url' => 'https://same-known-alias.local',
                'status' => 'online',
                'allow_receive_packages' => 1,
                'onboarding_state' => 'VERIFIED',
            ],
            'alias_b' => [
                'site_uid' => 'alias_b',
                'base_url' => 'https://same-known-alias.local/',
                'status' => 'online',
                'allow_receive_packages' => 1,
                'onboarding_state' => 'PENDING_INTRO',
            ],
        ]);
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, [
            'canonical_a' => [
                'site_uid' => 'canonical_a',
                'onboarding_state' => 'verified',
                'command_secret' => 'hs_canonical',
            ],
            'alias_b' => [
                'site_uid' => 'alias_b',
                'onboarding_state' => 'pending_intro',
                'command_secret' => '',
            ],
        ]);

        $alias = new WP_REST_Request('POST', '/dbvc/v1/bricks/connected-sites/known-alias');
        $alias->set_header('Content-Type', 'application/json');
        $alias->set_body(wp_json_encode([
            'canonical_site_uid' => 'alias_b',
            'alias_site_uid' => 'legacy_uid',
            'confirm_alias' => true,
        ]));
        $alias_response = rest_get_server()->dispatch($alias);
        $this->assertSame(409, $alias_response->get_status());
        $error = $alias_response->get_data();
        $this->assertSame('dbvc_bricks_known_alias_canonical_not_targetable', (string) ($error['code'] ?? ''));
        $this->assertSame('canonical_a', (string) (($error['data']['canonical_site_uid'] ?? '')));
    }

    public function test_assisted_merge_uses_deterministic_candidate_token(): void
    {
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, [
            'canonical_a' => [
                'site_uid' => 'canonical_a',
                'base_url' => 'https://same-assisted.local',
                'status' => 'online',
                'allow_receive_packages' => 1,
                'onboarding_state' => 'VERIFIED',
                'local_instance_uuid' => 'inst_abc123',
            ],
            'alias_b' => [
                'site_uid' => 'alias_b',
                'base_url' => 'https://same-assisted.local/',
                'status' => 'online',
                'allow_receive_packages' => 1,
                'onboarding_state' => 'VERIFIED',
                'local_instance_uuid' => 'inst_abc123',
            ],
        ]);
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, [
            'canonical_a' => ['site_uid' => 'canonical_a', 'onboarding_state' => 'verified', 'command_secret' => 'hs_a'],
            'alias_b' => ['site_uid' => 'alias_b', 'onboarding_state' => 'verified', 'command_secret' => 'hs_b'],
        ]);

        $candidates = new WP_REST_Request('GET', '/dbvc/v1/bricks/connected-sites/assisted-merge-candidates');
        $candidates_response = rest_get_server()->dispatch($candidates);
        $this->assertSame(200, $candidates_response->get_status());
        $items = $candidates_response->get_data()['items'] ?? [];
        $this->assertNotEmpty($items);
        $candidate = null;
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $cand_alias = (string) ($row['alias_site_uid'] ?? '');
            $cand_canonical = (string) ($row['canonical_site_uid'] ?? '');
            $uids = [$cand_alias, $cand_canonical];
            sort($uids, SORT_STRING);
            if ($uids === ['alias_b', 'canonical_a']) {
                $candidate = $row;
                break;
            }
        }
        $this->assertIsArray($candidate);
        $candidate_canonical = (string) ($candidate['canonical_site_uid'] ?? '');
        $candidate_alias = (string) ($candidate['alias_site_uid'] ?? '');
        $this->assertNotSame('', $candidate_canonical);
        $this->assertNotSame('', $candidate_alias);
        $token = (string) ($candidate['match_token'] ?? '');
        $this->assertNotSame('', $token);

        $merge = new WP_REST_Request('POST', '/dbvc/v1/bricks/connected-sites/assisted-merge');
        $merge->set_header('Content-Type', 'application/json');
        $merge->set_body(wp_json_encode([
            'canonical_site_uid' => $candidate_canonical,
            'alias_site_uid' => $candidate_alias,
            'match_token' => $token,
            'confirm_assisted_merge' => true,
        ]));
        $merge_response = rest_get_server()->dispatch($merge);
        $this->assertSame(200, $merge_response->get_status(), wp_json_encode($merge_response->get_data()));

        $alias_site = DBVC_Bricks_Connected_Sites::get_site($candidate_alias);
        $this->assertSame('disabled', (string) ($alias_site['status'] ?? ''));
        $aliases = get_option(DBVC_Bricks_Connected_Sites::OPTION_SITE_ALIASES, []);
        $this->assertIsArray($aliases);
        $alias_row = $aliases[$candidate_alias] ?? [];
        $this->assertSame($candidate_canonical, (string) ($alias_row['canonical_site_uid'] ?? ''));
        $this->assertSame(1, (int) ($alias_row['auto_generated'] ?? 0));
    }

    private function seed_single_envelope_for_client_a(): void
    {
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, [
            'client_a' => ['site_uid' => 'client_a', 'status' => 'online', 'allow_receive_packages' => 1],
        ]);
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, [
            'client_a' => ['site_uid' => 'client_a', 'command_secret' => 'hs_client_a'],
        ]);
        $enqueue = new WP_REST_Request('POST', '/dbvc/v1/bricks/commands/enqueue');
        $enqueue->set_header('Content-Type', 'application/json');
        $enqueue->set_header('Idempotency-Key', 'seed-enqueue-client-a');
        $enqueue->set_body(wp_json_encode([
            'command_type' => 'shared_rules_apply',
            'target_mode' => 'selected',
            'site_uids' => ['client_a'],
            'distribution_id' => 'dist_seed_client_a',
            'payload' => ['distribution_id' => 'dist_seed_client_a', 'profile' => ['profile_version' => 1]],
        ]));
        $response = rest_get_server()->dispatch($enqueue);
        $this->assertSame(200, $response->get_status());
    }

    private function reset_rest_server(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }
}
