<?php

class BricksAddonPhase15Test extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        update_option('dbvc_bricks_role', 'mothership');
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, []);
        update_option(DBVC_Bricks_Onboarding::OPTION_TRANSPORT, []);
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, []);
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, []);
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGE_CATALOG, []);
        update_option(DBVC_Bricks_Packages::OPTION_PUBLISH_RUNS, []);
        update_option(DBVC_Bricks_Idempotency::OPTION_STORE, []);
        update_option(DBVC_Bricks_Command_Auth::OPTION_NONCES, []);
        update_option(DBVC_Bricks_Addon::OPTION_UI_DIAGNOSTICS, []);
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        $_GET = [];
        remove_all_filters('pre_http_request');
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        update_option('dbvc_bricks_role', 'client');
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, []);
        update_option(DBVC_Bricks_Onboarding::OPTION_TRANSPORT, []);
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, []);
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, []);
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGE_CATALOG, []);
        update_option(DBVC_Bricks_Packages::OPTION_PUBLISH_RUNS, []);
        update_option(DBVC_Bricks_Idempotency::OPTION_STORE, []);
        update_option(DBVC_Bricks_Command_Auth::OPTION_NONCES, []);
        update_option(DBVC_Bricks_Addon::OPTION_UI_DIAGNOSTICS, []);
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        parent::tear_down();
    }

    public function test_intro_packet_requires_mothership_role(): void
    {
        update_option('dbvc_bricks_role', 'client');

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/intro/packet');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Idempotency-Key', 'intro-role-check');
        $request->set_body(wp_json_encode([
            'site_uid' => 'client_a',
            'site_label' => 'Client A',
            'base_url' => 'https://client-a.local',
        ]));
        $response = rest_get_server()->dispatch($request);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('dbvc_bricks_intro_role_invalid', $response->get_data()['code'] ?? '');
    }

    public function test_intro_packet_is_idempotent_and_persists_registry_record(): void
    {
        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/intro/packet');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Idempotency-Key', 'intro-packet-1');
        $request->set_body(wp_json_encode([
            'site_uid' => 'client_a',
            'site_label' => 'Client A',
            'base_url' => 'https://client-a.local',
            'environment' => 'local',
            'capabilities' => ['publish', 'pull', 'ack'],
            'auth_profile' => ['method' => 'wp_app_password', 'key_id' => 'integration_user'],
        ]));
        $response = rest_get_server()->dispatch($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue((bool) ($data['ok'] ?? false));
        $this->assertSame('client_a', $data['site_uid'] ?? '');
        $this->assertSame('PENDING_INTRO', $data['onboarding_state'] ?? '');

        $replay = new WP_REST_Request('POST', '/dbvc/v1/bricks/intro/packet');
        $replay->set_header('Content-Type', 'application/json');
        $replay->set_header('Idempotency-Key', 'intro-packet-1');
        $replay->set_body($request->get_body());
        $replay_response = rest_get_server()->dispatch($replay);
        $this->assertSame(200, $replay_response->get_status());
        $this->assertSame($data, $replay_response->get_data());

        $clients = get_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, []);
        $this->assertSame('pending_intro', $clients['client_a']['onboarding_state'] ?? '');
        $this->assertSame('wp_app_password', $clients['client_a']['auth_profile']['method'] ?? '');

        $site = DBVC_Bricks_Connected_Sites::get_site('client_a');
        $this->assertIsArray($site);
        $this->assertSame(0, (int) ($site['allow_receive_packages'] ?? 1));
    }

    public function test_intro_handshake_accept_returns_token_and_enables_site(): void
    {
        $intro = new WP_REST_Request('POST', '/dbvc/v1/bricks/intro/packet');
        $intro->set_header('Content-Type', 'application/json');
        $intro->set_header('Idempotency-Key', 'intro-packet-accept');
        $intro->set_body(wp_json_encode([
            'site_uid' => 'client_accept',
            'site_label' => 'Client Accept',
            'base_url' => 'https://client-accept.local',
        ]));
        rest_get_server()->dispatch($intro);

        $handshake = new WP_REST_Request('POST', '/dbvc/v1/bricks/intro/handshake');
        $handshake->set_header('Content-Type', 'application/json');
        $handshake->set_header('Idempotency-Key', 'intro-handshake-accept');
        $handshake->set_body(wp_json_encode([
            'site_uid' => 'client_accept',
            'decision' => 'accept',
            'notes' => 'Verified',
        ]));
        $response = rest_get_server()->dispatch($handshake);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue((bool) ($data['ok'] ?? false));
        $this->assertTrue((bool) ($data['accepted'] ?? false));
        $this->assertStringStartsWith('hs_', (string) ($data['handshake_token'] ?? ''));
        $this->assertStringStartsWith('sig_', (string) ($data['signature'] ?? ''));

        $clients = get_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, []);
        $this->assertSame('verified', $clients['client_accept']['onboarding_state'] ?? '');
        $this->assertNotEmpty($clients['client_accept']['handshake_token_hash'] ?? '');
        $this->assertNotEmpty($clients['client_accept']['approved_at'] ?? '');

        $site = DBVC_Bricks_Connected_Sites::get_site('client_accept');
        $this->assertIsArray($site);
        $this->assertSame('online', $site['status'] ?? '');
        $this->assertSame(1, (int) ($site['allow_receive_packages'] ?? 0));
    }

    public function test_intro_packet_preserves_verified_state_and_returns_handshake_token(): void
    {
        $intro = new WP_REST_Request('POST', '/dbvc/v1/bricks/intro/packet');
        $intro->set_header('Content-Type', 'application/json');
        $intro->set_header('Idempotency-Key', 'intro-packet-verified-prime');
        $intro->set_body(wp_json_encode([
            'site_uid' => 'client_verified_packet',
            'site_label' => 'Client Verified Packet',
            'base_url' => 'https://client-verified-packet.local',
        ]));
        rest_get_server()->dispatch($intro);

        $handshake = new WP_REST_Request('POST', '/dbvc/v1/bricks/intro/handshake');
        $handshake->set_header('Content-Type', 'application/json');
        $handshake->set_header('Idempotency-Key', 'intro-handshake-verified-prime');
        $handshake->set_body(wp_json_encode([
            'site_uid' => 'client_verified_packet',
            'decision' => 'accept',
            'notes' => 'Verified for token sync',
        ]));
        $handshake_response = rest_get_server()->dispatch($handshake);
        $this->assertSame(200, $handshake_response->get_status());
        $issued = (string) (($handshake_response->get_data()['handshake_token'] ?? ''));
        $this->assertStringStartsWith('hs_', $issued);

        $refresh = new WP_REST_Request('POST', '/dbvc/v1/bricks/intro/packet');
        $refresh->set_header('Content-Type', 'application/json');
        $refresh->set_header('Idempotency-Key', 'intro-packet-verified-refresh');
        $refresh->set_body(wp_json_encode([
            'site_uid' => 'client_verified_packet',
            'site_label' => 'Client Verified Packet',
            'base_url' => 'https://client-verified-packet.local',
        ]));
        $refresh_response = rest_get_server()->dispatch($refresh);
        $this->assertSame(200, $refresh_response->get_status());
        $refresh_data = $refresh_response->get_data();
        $this->assertSame('VERIFIED', (string) ($refresh_data['onboarding_state'] ?? ''));
        $this->assertSame($issued, (string) ($refresh_data['handshake_token'] ?? ''));
        $this->assertNotEmpty((string) ($refresh_data['approved_at'] ?? ''));

        $clients = get_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, []);
        $this->assertSame('verified', (string) ($clients['client_verified_packet']['onboarding_state'] ?? ''));
        $this->assertSame($issued, (string) ($clients['client_verified_packet']['command_secret'] ?? ''));
    }

    public function test_intro_handshake_reject_disables_site(): void
    {
        $intro = new WP_REST_Request('POST', '/dbvc/v1/bricks/intro/packet');
        $intro->set_header('Content-Type', 'application/json');
        $intro->set_header('Idempotency-Key', 'intro-packet-reject');
        $intro->set_body(wp_json_encode([
            'site_uid' => 'client_reject',
            'site_label' => 'Client Reject',
            'base_url' => 'https://client-reject.local',
        ]));
        rest_get_server()->dispatch($intro);

        $handshake = new WP_REST_Request('POST', '/dbvc/v1/bricks/intro/handshake');
        $handshake->set_header('Content-Type', 'application/json');
        $handshake->set_header('Idempotency-Key', 'intro-handshake-reject');
        $handshake->set_body(wp_json_encode([
            'site_uid' => 'client_reject',
            'decision' => 'reject',
            'notes' => 'Invalid setup',
        ]));
        $response = rest_get_server()->dispatch($handshake);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue((bool) ($data['ok'] ?? false));
        $this->assertFalse((bool) ($data['accepted'] ?? true));
        $this->assertSame('', (string) ($data['handshake_token'] ?? 'x'));

        $clients = get_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, []);
        $this->assertSame('rejected', $clients['client_reject']['onboarding_state'] ?? '');

        $site = DBVC_Bricks_Connected_Sites::get_site('client_reject');
        $this->assertIsArray($site);
        $this->assertSame('disabled', $site['status'] ?? '');
        $this->assertSame(0, (int) ($site['allow_receive_packages'] ?? 1));
    }

    public function test_connected_sites_registry_first_includes_onboarding_state(): void
    {
        update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, [
            'client_pending' => [
                'site_uid' => 'client_pending',
                'site_label' => 'Client Pending',
                'base_url' => 'https://client-pending.local',
                'onboarding_state' => 'pending_intro',
                'last_intro_at' => '2026-02-15T00:00:00+00:00',
                'last_seen_at' => '2026-02-15T00:00:00+00:00',
                'auth_profile' => ['method' => 'wp_app_password'],
            ],
            'client_verified' => [
                'site_uid' => 'client_verified',
                'site_label' => 'Client Verified',
                'base_url' => 'https://client-verified.local',
                'onboarding_state' => 'verified',
                'last_handshake_at' => '2026-02-15T00:10:00+00:00',
                'last_seen_at' => '2026-02-15T00:10:00+00:00',
                'auth_profile' => ['method' => 'wp_app_password'],
            ],
        ]);
        update_option('dbvc_bricks_connected_sites_mode', 'registry_table');

        $list = new WP_REST_Request('GET', '/dbvc/v1/bricks/connected-sites');
        $response = rest_get_server()->dispatch($list);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('registry_table', $data['registry_mode'] ?? '');
        $items = $data['items'] ?? [];
        $this->assertCount(2, $items);

        $by_uid = [];
        foreach ($items as $item) {
            $by_uid[(string) ($item['site_uid'] ?? '')] = $item;
        }
        $this->assertSame('PENDING_INTRO', $by_uid['client_pending']['onboarding_state'] ?? '');
        $this->assertSame('VERIFIED', $by_uid['client_verified']['onboarding_state'] ?? '');
        $this->assertSame(1, (int) ($by_uid['client_verified']['allow_receive_packages'] ?? 0));
    }

    public function test_signed_command_ping_requires_valid_signature_and_rejects_replay(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_site_uid', 'client_signed');
        update_option('dbvc_bricks_intro_handshake_token', 'hs_test_secret');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        do_action('rest_api_init');

        $timestamp = time();
        $nonce = 'nonce_' . wp_rand(1000, 9999);
        $body = wp_json_encode(['command' => 'ping']);
        $signature = DBVC_Bricks_Command_Auth::build_signature('hs_test_secret', $timestamp, $nonce, 'client_signed', (string) $body);

        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/commands/ping');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('X-DBVC-Timestamp', (string) $timestamp);
        $request->set_header('X-DBVC-Nonce', $nonce);
        $request->set_header('X-DBVC-Site-UID', 'client_signed');
        $request->set_header('X-DBVC-Signature', $signature);
        $request->set_body((string) $body);

        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $this->assertTrue((bool) ($response->get_data()['verified'] ?? false));

        $replay = new WP_REST_Request('POST', '/dbvc/v1/bricks/commands/ping');
        $replay->set_header('Content-Type', 'application/json');
        $replay->set_header('X-DBVC-Timestamp', (string) $timestamp);
        $replay->set_header('X-DBVC-Nonce', $nonce);
        $replay->set_header('X-DBVC-Site-UID', 'client_signed');
        $replay->set_header('X-DBVC-Signature', $signature);
        $replay->set_body((string) $body);

        $replay_response = rest_get_server()->dispatch($replay);
        $this->assertSame(409, $replay_response->get_status());
        $this->assertSame('dbvc_bricks_command_nonce_replay', $replay_response->get_data()['code'] ?? '');
    }

    public function test_save_settings_auto_intro_persists_transport_state_and_sends_intro(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_site_uid', 'client_intro_auto');
        update_option('dbvc_bricks_intro_auto_send', '1');
        update_option('dbvc_bricks_auth_method', 'wp_app_password');
        update_option('dbvc_bricks_api_key_id', 'integration_user');
        update_option('dbvc_bricks_api_secret', 'secret app password');
        update_option('dbvc_bricks_mothership_url', 'https://mothership.local');
        update_option('dbvc_bricks_tls_verify', '1');
        update_option('dbvc_bricks_http_timeout', '30');
        update_option('dbvc_bricks_intro_retry_max_attempts', '3');
        update_option('dbvc_bricks_intro_retry_interval_minutes', '30');

        $calls = ['status' => 0, 'intro' => 0];
        add_filter('pre_http_request', static function ($preempt, $args, $url) use (&$calls) {
            if (strpos((string) $url, '/wp-json/dbvc/v1/bricks/status') !== false) {
                $calls['status']++;
                return [
                    'headers' => [],
                    'body' => wp_json_encode(['enabled' => true, 'addon' => 'bricks', 'role' => 'mothership']),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }
            if (strpos((string) $url, '/wp-json/dbvc/v1/bricks/intro/packet') !== false) {
                $calls['intro']++;
                return [
                    'headers' => [],
                    'body' => wp_json_encode([
                        'ok' => true,
                        'site_uid' => 'client_intro_auto',
                        'onboarding_state' => 'PENDING_INTRO',
                        'registered_at' => '2026-02-15T08:00:00+00:00',
                    ]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }
            return $preempt;
        }, 10, 3);

        DBVC_Bricks_Addon::save_settings([
            'dbvc_bricks_role' => 'client',
            'dbvc_bricks_site_uid' => 'client_intro_auto',
            'dbvc_bricks_intro_auto_send' => '1',
            'dbvc_bricks_auth_method' => 'wp_app_password',
            'dbvc_bricks_api_key_id' => 'integration_user',
            'dbvc_bricks_api_secret' => 'secret app password',
            'dbvc_bricks_mothership_url' => 'https://mothership.local',
            'dbvc_bricks_intro_retry_max_attempts' => '3',
            'dbvc_bricks_intro_retry_interval_minutes' => '30',
        ]);

        $this->assertSame(1, $calls['status']);
        $this->assertSame(1, $calls['intro']);
        $transport = get_option(DBVC_Bricks_Onboarding::OPTION_TRANSPORT, []);
        $this->assertSame(1, (int) ($transport['client_intro_auto']['ping_sent'] ?? 0));
        $this->assertSame(1, (int) ($transport['client_intro_auto']['intro_sent'] ?? 0));
        $this->assertSame('PENDING_INTRO', $transport['client_intro_auto']['handshake_state'] ?? '');
        $this->assertGreaterThanOrEqual(1, (int) ($transport['client_intro_auto']['attempts'] ?? 0));
        $this->assertSame('PENDING_INTRO', get_option('dbvc_bricks_client_registry_state', ''));
    }

    public function test_cron_retry_caps_attempts_and_marks_disabled_with_diagnostics(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_site_uid', 'client_retry_exhaust');
        update_option('dbvc_bricks_intro_auto_send', '1');
        update_option('dbvc_bricks_auth_method', 'wp_app_password');
        update_option('dbvc_bricks_api_key_id', 'integration_user');
        update_option('dbvc_bricks_api_secret', 'secret app password');
        update_option('dbvc_bricks_mothership_url', 'https://mothership.local');
        update_option('dbvc_bricks_intro_retry_max_attempts', '2');
        update_option('dbvc_bricks_intro_retry_interval_minutes', '5');
        update_option('dbvc_bricks_client_registry_state', 'PENDING_INTRO');

        add_filter('pre_http_request', static function ($preempt, $args, $url) {
            if (strpos((string) $url, '/wp-json/dbvc/v1/bricks/status') !== false) {
                return new WP_Error('http_failed', 'connection failed');
            }
            return $preempt;
        }, 10, 3);

        DBVC_Bricks_Addon::run_scheduled_job();
        $state_after_first = DBVC_Bricks_Onboarding::get_transport_state('client_retry_exhaust');
        $this->assertSame('PENDING_INTRO', $state_after_first['handshake_state'] ?? '');
        $this->assertSame(1, (int) ($state_after_first['attempts'] ?? 0));

        $states = get_option(DBVC_Bricks_Onboarding::OPTION_TRANSPORT, []);
        $states['client_retry_exhaust']['next_retry_at'] = gmdate('c', time() - MINUTE_IN_SECONDS);
        update_option(DBVC_Bricks_Onboarding::OPTION_TRANSPORT, $states);

        DBVC_Bricks_Addon::run_scheduled_job();
        $state_after_second = DBVC_Bricks_Onboarding::get_transport_state('client_retry_exhaust');
        $this->assertSame('DISABLED', $state_after_second['handshake_state'] ?? '');
        $this->assertSame(2, (int) ($state_after_second['attempts'] ?? 0));
        $this->assertSame('DISABLED', get_option('dbvc_bricks_client_registry_state', ''));

        $diagnostics = get_option(DBVC_Bricks_Addon::OPTION_UI_DIAGNOSTICS, []);
        $event_types = array_map(static function ($row) {
            return isset($row['event_type']) ? (string) $row['event_type'] : '';
        }, is_array($diagnostics) ? $diagnostics : []);
        $this->assertContains('intro_retry_exhausted', $event_types);
    }

    public function test_idempotency_replay_for_bootstrap_promote_revoke_and_publish_remote(): void
    {
        update_option('dbvc_bricks_role', 'mothership');

        $bootstrap = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/bootstrap-create');
        $bootstrap->set_header('Content-Type', 'application/json');
        $bootstrap->set_header('Idempotency-Key', 'p15-bootstrap-idem');
        $bootstrap->set_body(wp_json_encode(['channel' => 'canary']));
        $bootstrap_response = rest_get_server()->dispatch($bootstrap);
        $this->assertSame(200, $bootstrap_response->get_status());
        $bootstrap_data = $bootstrap_response->get_data();
        $this->assertTrue((bool) ($bootstrap_data['ok'] ?? false));
        $package_id = (string) ($bootstrap_data['package_id'] ?? '');
        $this->assertNotSame('', $package_id);

        $bootstrap_replay = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/bootstrap-create');
        $bootstrap_replay->set_header('Content-Type', 'application/json');
        $bootstrap_replay->set_header('Idempotency-Key', 'p15-bootstrap-idem');
        $bootstrap_replay->set_body(wp_json_encode(['channel' => 'stable']));
        $bootstrap_replay_response = rest_get_server()->dispatch($bootstrap_replay);
        $this->assertSame(200, $bootstrap_replay_response->get_status());
        $this->assertSame($bootstrap_data, $bootstrap_replay_response->get_data());

        $promote = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/' . rawurlencode($package_id) . '/promote');
        $promote->set_header('Content-Type', 'application/json');
        $promote->set_header('Idempotency-Key', 'p15-promote-idem');
        $promote->set_body(wp_json_encode(['channel' => 'beta']));
        $promote_response = rest_get_server()->dispatch($promote);
        $this->assertSame(200, $promote_response->get_status());
        $promote_data = $promote_response->get_data();
        $this->assertSame('beta', $promote_data['channel'] ?? '');

        $promote_replay = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/' . rawurlencode($package_id) . '/promote');
        $promote_replay->set_header('Content-Type', 'application/json');
        $promote_replay->set_header('Idempotency-Key', 'p15-promote-idem');
        $promote_replay->set_body(wp_json_encode(['channel' => 'stable', 'confirm_stable_promotion' => true]));
        $promote_replay_response = rest_get_server()->dispatch($promote_replay);
        $this->assertSame(200, $promote_replay_response->get_status());
        $this->assertSame($promote_data, $promote_replay_response->get_data());

        $revoke = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/' . rawurlencode($package_id) . '/revoke');
        $revoke->set_header('Content-Type', 'application/json');
        $revoke->set_header('Idempotency-Key', 'p15-revoke-idem');
        $revoke->set_body(wp_json_encode(['confirm_revoke' => true]));
        $revoke_response = rest_get_server()->dispatch($revoke);
        $this->assertSame(200, $revoke_response->get_status());
        $revoke_data = $revoke_response->get_data();
        $this->assertTrue((bool) ($revoke_data['ok'] ?? false));
        $this->assertSame('REVOKED', $revoke_data['status'] ?? '');

        $revoke_replay = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/' . rawurlencode($package_id) . '/revoke');
        $revoke_replay->set_header('Content-Type', 'application/json');
        $revoke_replay->set_header('Idempotency-Key', 'p15-revoke-idem');
        $revoke_replay->set_body(wp_json_encode(['confirm_revoke' => true]));
        $revoke_replay_response = rest_get_server()->dispatch($revoke_replay);
        $this->assertSame(200, $revoke_replay_response->get_status());
        $this->assertSame($revoke_data, $revoke_replay_response->get_data());

        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_site_uid', 'client_p15_idem');
        update_option('dbvc_bricks_auth_method', 'wp_app_password');
        update_option('dbvc_bricks_api_key_id', 'integration_user');
        update_option('dbvc_bricks_api_secret', 'secret');
        update_option('dbvc_bricks_mothership_url', 'https://mothership.local');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        do_action('rest_api_init');

        add_filter('pre_http_request', static function ($preempt, $args, $url) {
            if (strpos((string) $url, '/wp-json/dbvc/v1/bricks/packages/pkg_remote_idem') !== false) {
                return [
                    'headers' => [],
                    'body' => wp_json_encode([
                        'manifest' => [
                            'package_id' => 'pkg_remote_idem',
                            'channel' => 'canary',
                            'artifact_count' => 0,
                            'receipt_id' => 'pkg_rcpt_remote_idem',
                            'updated_at' => '2026-05-05T05:40:00+00:00',
                        ],
                    ]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }
            if (strpos((string) $url, '/wp-json/dbvc/v1/bricks/packages') !== false) {
                return [
                    'headers' => [],
                    'body' => wp_json_encode(['ok' => true, 'package_id' => 'pkg_remote_idem']),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }
            return $preempt;
        }, 10, 3);

        $publish = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/publish-remote');
        $publish->set_header('Content-Type', 'application/json');
        $publish->set_header('Idempotency-Key', 'p15-publish-remote-idem');
        $publish->set_body(wp_json_encode([
            'package' => [
                'package_id' => 'pkg_local_remote_idem',
                'version' => '1.0.0',
                'channel' => 'canary',
                'artifacts' => [],
            ],
            'targeting' => ['mode' => 'all', 'site_uids' => []],
        ]));
        $publish_response = rest_get_server()->dispatch($publish);
        $this->assertSame(200, $publish_response->get_status());
        $publish_data = $publish_response->get_data();
        $this->assertTrue((bool) ($publish_data['ok'] ?? false));
        $this->assertTrue((bool) ($publish_data['remote_package_visibility']['ok'] ?? false));
        $this->assertSame('pkg_remote_idem', (string) ($publish_data['remote_package_visibility']['package_id'] ?? ''));

        $publish_replay = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/publish-remote');
        $publish_replay->set_header('Content-Type', 'application/json');
        $publish_replay->set_header('Idempotency-Key', 'p15-publish-remote-idem');
        $publish_replay->set_body(wp_json_encode([
            'package' => [
                'package_id' => 'pkg_local_remote_idem_changed',
                'version' => '2.0.0',
                'channel' => 'stable',
                'artifacts' => [],
            ],
            'targeting' => ['mode' => 'all', 'site_uids' => []],
        ]));
        $publish_replay_response = rest_get_server()->dispatch($publish_replay);
        $this->assertSame(200, $publish_replay_response->get_status());
        $this->assertSame($publish_data, $publish_replay_response->get_data());
    }

    public function test_rest_mutating_routes_require_manage_capability(): void
    {
        wp_set_current_user(0);
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        do_action('rest_api_init');

        $routes = [
            ['/dbvc/v1/bricks/apply', ['manifest' => ['package_id' => 'pkg-cap-1', 'artifacts' => []]]],
            ['/dbvc/v1/bricks/packages', ['package' => ['package_id' => 'pkg-cap-2', 'version' => '1.0.0', 'channel' => 'canary', 'artifacts' => []], 'targeting' => ['mode' => 'all']]],
            ['/dbvc/v1/bricks/proposals', ['artifact_uid' => 'option:bricks_global_classes', 'artifact_type' => 'bricks_global_classes', 'base_hash' => 'sha256:' . str_repeat('1', 64), 'proposed_hash' => 'sha256:' . str_repeat('2', 64)]],
            ['/dbvc/v1/bricks/connected-sites', ['site_uid' => 'cap_site', 'allow_receive_packages' => true]],
            ['/dbvc/v1/bricks/intro/packet', ['site_uid' => 'cap_intro', 'site_label' => 'Cap Intro', 'base_url' => 'https://cap-intro.local']],
        ];

        foreach ($routes as $spec) {
            $request = new WP_REST_Request('POST', $spec[0]);
            $request->set_header('Content-Type', 'application/json');
            $request->set_header('Idempotency-Key', 'cap-' . md5((string) $spec[0]));
            $request->set_body(wp_json_encode($spec[1]));
            $response = rest_get_server()->dispatch($request);
            $this->assertContains((int) $response->get_status(), [401, 403], 'Expected auth/capability failure for ' . $spec[0]);
        }
    }

    public function test_schema_verify_endpoint_reports_theme_styles_and_component_paths(): void
    {
        update_option('bricks_theme_styles', [
            'global' => ['palette' => ['primary' => '#111111']],
            'generated_at' => '2026-02-15T00:00:00+00:00',
        ]);
        update_option('bricks_components', [
            ['id' => 'hero_1', 'label' => 'Hero'],
            ['slug' => 'card-grid', 'name' => 'Card Grid'],
        ]);

        $request = new WP_REST_Request('GET', '/dbvc/v1/bricks/schema-verify');
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();

        $this->assertTrue((bool) ($data['theme_styles']['present'] ?? false));
        $this->assertTrue((bool) ($data['components']['present'] ?? false));
        $this->assertSame(2, (int) ($data['components']['component_count'] ?? 0));
        $this->assertGreaterThan(0, (float) ($data['components']['label_coverage'] ?? 0));
        $this->assertGreaterThan(0, (float) ($data['components']['slug_coverage'] ?? 0));
    }

    public function test_normalize_manifest_payload_supports_snapshot_wrappers_for_package_contracts(): void
    {
        $legacy_snapshot = [
            'snapshot' => [
                'manifest' => [
                    'id' => 'legacy-snap-1',
                    'schema_version' => '0.9.0',
                    'parse_mode' => 'lenient',
                    'items' => [
                        [
                            'uid' => 'option:bricks_theme_styles',
                            'type' => 'bricks_theme_styles',
                            'hash' => 'sha256:' . str_repeat('a', 64),
                            'payload' => ['global' => ['palette' => ['primary' => '#fff']]],
                        ],
                    ],
                ],
            ],
        ];

        $normalized = DBVC_Bricks_Addon::normalize_manifest_payload($legacy_snapshot);
        $this->assertSame('legacy-snap-1', $normalized['package_id']);
        $this->assertSame('0.9.0', $normalized['schema_version']);
        $this->assertSame('lenient', $normalized['parse_mode']);
        $this->assertSame('snapshot_manifest_legacy_items', $normalized['compatibility']['source_shape']);
        $this->assertSame('option:bricks_theme_styles', $normalized['artifacts'][0]['artifact_uid'] ?? '');
    }

    public function test_package_preflight_strict_vs_lenient_for_legacy_schema_versions(): void
    {
        $strict = DBVC_Bricks_Packages::build_publish_preflight([
            'package_id' => 'pkg-preflight-strict',
            'schema_version' => '0.9.0',
            'parse_mode' => 'strict',
            'version' => '1.0.0',
            'channel' => 'canary',
            'artifacts' => [],
        ]);
        $this->assertFalse((bool) ($strict['ok'] ?? true));
        $this->assertContains('schema_version_unsupported_in_strict_mode', $strict['errors'] ?? []);

        $lenient = DBVC_Bricks_Packages::build_publish_preflight([
            'package_id' => 'pkg-preflight-lenient',
            'schema_version' => '0.9.0',
            'parse_mode' => 'lenient',
            'version' => '1.0.0',
            'channel' => 'canary',
            'artifacts' => [],
        ]);
        $this->assertTrue((bool) ($lenient['ok'] ?? false));
        $this->assertContains('schema_version_not_explicitly_supported', $lenient['warnings'] ?? []);
    }

    private function reset_rest_server(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
    }
}
