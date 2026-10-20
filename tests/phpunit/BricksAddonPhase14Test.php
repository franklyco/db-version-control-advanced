<?php

class BricksAddonPhase14Test extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        update_option('dbvc_bricks_role', 'mothership');
        update_option('dbvc_bricks_read_only', '0');
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, [
            'pkg_canary' => [
                'package_id' => 'pkg_canary',
                'version' => '1.0.0',
                'channel' => 'canary',
                'status' => DBVC_Bricks_Packages::STATUS_PUBLISHED,
                'artifacts' => [],
            ],
            'pkg_stable' => [
                'package_id' => 'pkg_stable',
                'version' => '1.0.1',
                'channel' => 'stable',
                'status' => DBVC_Bricks_Packages::STATUS_PUBLISHED,
                'artifacts' => [],
            ],
        ]);
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGE_CATALOG, []);
        update_option(DBVC_Bricks_Idempotency::OPTION_STORE, []);
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        $_GET = [];
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_read_only', '0');
        update_option('dbvc_bricks_site_uid', '');
        update_option('dbvc_bricks_client_force_channel', 'none');
        update_option('dbvc_bricks_force_stable_confirm', '1');
        remove_all_filters('pre_http_request');
        delete_option(DBVC_Bricks_Packages::OPTION_PACKAGES);
        delete_option(DBVC_Bricks_Packages::OPTION_PACKAGE_CATALOG);
        update_option(DBVC_Bricks_Idempotency::OPTION_STORE, []);
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        parent::tear_down();
    }

    public function test_mothership_packages_panel_renders_promote_revoke_controls(): void
    {
        $render = $this->render_admin_page('packages');
        $this->assertStringContainsString('id="dbvc-bricks-promote-channel"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-promote-package"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-revoke-package"', $render);
        $this->assertStringContainsString('Test (Canary) = first review group', $render);
    }

    public function test_client_packages_panel_hides_promote_revoke_controls(): void
    {
        update_option('dbvc_bricks_role', 'client');
        $render = $this->render_admin_page('packages');
        $this->assertStringNotContainsString('id="dbvc-bricks-promote-package"', $render);
        $this->assertStringNotContainsString('id="dbvc-bricks-revoke-package"', $render);
        $this->assertStringContainsString('id="dbvc-bricks-pull-latest-dry-run"', $render);
    }

    public function test_promote_requires_stable_confirmation_and_forward_progression(): void
    {
        $stable_confirm_missing = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/pkg_canary/promote');
        $stable_confirm_missing->set_header('Content-Type', 'application/json');
        $stable_confirm_missing->set_header('Idempotency-Key', 'promote-stable-no-confirm');
        $stable_confirm_missing->set_body(wp_json_encode([
            'channel' => 'stable',
        ]));
        $missing_response = rest_get_server()->dispatch($stable_confirm_missing);
        $this->assertSame(400, $missing_response->get_status());
        $this->assertSame('dbvc_bricks_stable_promotion_confirmation_required', $missing_response->get_data()['code'] ?? '');

        $downgrade = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/pkg_stable/promote');
        $downgrade->set_header('Content-Type', 'application/json');
        $downgrade->set_header('Idempotency-Key', 'promote-downgrade');
        $downgrade->set_body(wp_json_encode([
            'channel' => 'beta',
        ]));
        $downgrade_response = rest_get_server()->dispatch($downgrade);
        $this->assertSame(400, $downgrade_response->get_status());
        $this->assertSame('dbvc_bricks_channel_progression_invalid', $downgrade_response->get_data()['code'] ?? '');
    }

    public function test_promote_and_revoke_success_with_confirmations(): void
    {
        $promote = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/pkg_canary/promote');
        $promote->set_header('Content-Type', 'application/json');
        $promote->set_header('Idempotency-Key', 'promote-beta');
        $promote->set_body(wp_json_encode([
            'channel' => 'beta',
        ]));
        $promote_response = rest_get_server()->dispatch($promote);
        $this->assertSame(200, $promote_response->get_status());
        $this->assertSame('beta', $promote_response->get_data()['channel'] ?? '');

        $revoke_missing_confirm = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/pkg_canary/revoke');
        $revoke_missing_confirm->set_header('Content-Type', 'application/json');
        $revoke_missing_confirm->set_header('Idempotency-Key', 'revoke-no-confirm');
        $revoke_missing_confirm->set_body(wp_json_encode([]));
        $revoke_missing_response = rest_get_server()->dispatch($revoke_missing_confirm);
        $this->assertSame(400, $revoke_missing_response->get_status());
        $this->assertSame('dbvc_bricks_revoke_confirmation_required', $revoke_missing_response->get_data()['code'] ?? '');

        $revoke = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/pkg_canary/revoke');
        $revoke->set_header('Content-Type', 'application/json');
        $revoke->set_header('Idempotency-Key', 'revoke-confirm');
        $revoke->set_body(wp_json_encode([
            'confirm_revoke' => true,
        ]));
        $revoke_response = rest_get_server()->dispatch($revoke);
        $this->assertSame(200, $revoke_response->get_status());
        $this->assertSame(DBVC_Bricks_Packages::STATUS_REVOKED, $revoke_response->get_data()['status'] ?? '');
    }

    public function test_client_site_uid_filter_includes_visibility_reason(): void
    {
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, [
            'pkg_all' => [
                'package_id' => 'pkg_all',
                'version' => '1.0.0',
                'channel' => 'stable',
                'status' => DBVC_Bricks_Packages::STATUS_PUBLISHED,
                'targeting' => ['mode' => 'all', 'site_uids' => []],
                'artifacts' => [],
            ],
            'pkg_selected' => [
                'package_id' => 'pkg_selected',
                'version' => '1.1.0',
                'channel' => 'beta',
                'status' => DBVC_Bricks_Packages::STATUS_PUBLISHED,
                'targeting' => ['mode' => 'selected', 'site_uids' => ['client_x']],
                'artifacts' => [],
            ],
        ]);

        $list = new WP_REST_Request('GET', '/dbvc/v1/bricks/packages');
        $list->set_param('site_uid', 'client_x');
        $response = rest_get_server()->dispatch($list);
        $this->assertSame(200, $response->get_status());
        $items = $response->get_data()['items'] ?? [];
        $this->assertCount(2, $items);

        $reasons = [];
        foreach ($items as $item) {
            $reasons[(string) ($item['package_id'] ?? '')] = (string) ($item['visibility_reason'] ?? '');
        }
        $this->assertSame('target_all', $reasons['pkg_all'] ?? '');
        $this->assertSame('target_selected_match', $reasons['pkg_selected'] ?? '');
    }

    public function test_client_pull_latest_allowed_runs_dry_run_and_records_pull_ack(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_site_uid', 'client_site');
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, [
            'pkg_stable' => [
                'package_id' => 'pkg_stable',
                'version' => '1.0.0',
                'channel' => 'stable',
                'status' => DBVC_Bricks_Packages::STATUS_PUBLISHED,
                'targeting' => ['mode' => 'all', 'site_uids' => []],
                'artifacts' => [
                    [
                        'artifact_uid' => 'option:bricks_global_settings',
                        'artifact_type' => 'option',
                        'payload' => ['enabled' => true],
                        'hash' => 'sha256:stable',
                    ],
                ],
                'created_at' => '2026-02-15T00:00:00+00:00',
                'receipt_id' => 'pkg_rcpt_stable',
            ],
            'pkg_beta_selected' => [
                'package_id' => 'pkg_beta_selected',
                'version' => '1.1.0',
                'channel' => 'beta',
                'status' => DBVC_Bricks_Packages::STATUS_PUBLISHED,
                'targeting' => ['mode' => 'selected', 'site_uids' => ['client_site']],
                'artifacts' => [
                    [
                        'artifact_uid' => 'option:bricks_global_settings',
                        'artifact_type' => 'option',
                        'payload' => ['enabled' => false],
                        'hash' => 'sha256:beta',
                    ],
                ],
                'created_at' => '2026-02-15T01:00:00+00:00',
                'receipt_id' => 'pkg_rcpt_beta',
            ],
        ]);

        $pull = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/pull-latest');
        $pull->set_header('Content-Type', 'application/json');
        $pull->set_body(wp_json_encode([
            'channel' => 'beta',
        ]));
        $response = rest_get_server()->dispatch($pull);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('pkg_beta_selected', $data['package_id'] ?? '');
        $this->assertSame('client_site', $data['site_uid'] ?? '');
        $this->assertSame('beta', $data['channel'] ?? '');
        $this->assertTrue((bool) ($data['dry_run_apply']['dry_run'] ?? false));

        $stored = DBVC_Bricks_Packages::get_package('pkg_beta_selected');
        $acks = is_array($stored) ? ($stored['acks'] ?? []) : [];
        $this->assertNotEmpty($acks);
        $last_ack = end($acks);
        $this->assertSame('client_site', $last_ack['site_uid'] ?? '');
        $this->assertSame('pulled', $last_ack['state'] ?? '');
        $this->assertSame('pkg_rcpt_beta', $last_ack['receipt_id'] ?? '');
    }

    public function test_publish_remote_force_stable_requires_confirmation_and_records_audit(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_auth_method', 'wp_app_password');
        update_option('dbvc_bricks_mothership_url', 'https://mothership.local');
        update_option('dbvc_bricks_api_key_id', 'integration_user');
        update_option('dbvc_bricks_api_secret', 'app-password');
        update_option('dbvc_bricks_site_uid', 'client_site');
        update_option('dbvc_bricks_client_force_channel', 'stable');
        update_option('dbvc_bricks_force_stable_confirm', '1');
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, [
            'pkg_canary' => [
                'package_id' => 'pkg_canary',
                'version' => '2.0.0',
                'channel' => 'canary',
                'status' => DBVC_Bricks_Packages::STATUS_PUBLISHED,
                'receipt_id' => 'pkg_rcpt_1',
                'artifacts' => [],
            ],
        ]);

        $missing_confirm = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/publish-remote');
        $missing_confirm->set_header('Content-Type', 'application/json');
        $missing_confirm->set_header('Idempotency-Key', 'remote-force-stable-no-confirm');
        $missing_confirm->set_body(wp_json_encode([
            'package_id' => 'pkg_canary',
            'targeting' => ['mode' => 'all', 'site_uids' => []],
        ]));
        $missing_response = rest_get_server()->dispatch($missing_confirm);
        $this->assertSame(400, $missing_response->get_status());
        $this->assertSame('dbvc_bricks_force_stable_confirmation_required', $missing_response->get_data()['code'] ?? '');

        add_filter('pre_http_request', static function ($pre, $args, $url) {
            if (strpos((string) $url, '/wp-json/dbvc/v1/bricks/packages') !== false) {
                return [
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'body' => wp_json_encode(['ok' => true, 'receipt_id' => 'pkg_rcpt_remote']),
                    'headers' => [],
                ];
            }
            return $pre;
        }, 10, 3);

        $confirmed = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/publish-remote');
        $confirmed->set_header('Content-Type', 'application/json');
        $confirmed->set_header('Idempotency-Key', 'remote-force-stable-confirm');
        $confirmed->set_body(wp_json_encode([
            'package_id' => 'pkg_canary',
            'targeting' => ['mode' => 'all', 'site_uids' => []],
            'confirm_force_stable' => true,
        ]));
        $confirmed_response = rest_get_server()->dispatch($confirmed);
        $this->assertSame(200, $confirmed_response->get_status());
        $payload = $confirmed_response->get_data();
        $this->assertTrue((bool) ($payload['channel_force_audit']['channel_forced'] ?? false));
        $this->assertSame('stable', $payload['channel_force_audit']['forced_to'] ?? '');
    }

    public function test_targeting_rejects_non_wp_app_password_auth_mode_site(): void
    {
        update_option('dbvc_bricks_role', 'mothership');
        update_option(DBVC_Bricks_Connected_Sites::OPTION_CONNECTED_SITES, [
            'client_x' => [
                'site_uid' => 'client_x',
                'site_label' => 'Client X',
                'status' => 'online',
                'auth_mode' => 'api_key',
                'allow_receive_packages' => 1,
            ],
        ]);

        $create = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages');
        $create->set_header('Content-Type', 'application/json');
        $create->set_header('Idempotency-Key', 'target-auth-mode-invalid');
        $create->set_body(wp_json_encode([
            'package' => [
                'package_id' => 'pkg_auth_test',
                'version' => '1.0.0',
                'channel' => 'stable',
                'artifacts' => [],
            ],
            'targeting' => [
                'mode' => 'selected',
                'site_uids' => ['client_x'],
            ],
        ]));
        $response = rest_get_server()->dispatch($create);
        $this->assertSame(400, $response->get_status());
        $this->assertSame('dbvc_bricks_target_site_auth_mode_invalid', $response->get_data()['code'] ?? '');
    }

    public function test_apply_restore_rollback_receipt_id_roundtrip(): void
    {
        update_option('dbvc_bricks_role', 'client');
        $manifest = [
            'package_id' => 'pkg_receipt',
            'receipt_id' => 'pkg_rcpt_receipt',
            'artifacts' => [
                [
                    'artifact_uid' => 'option:bricks_global_settings',
                    'artifact_type' => 'option',
                    'payload' => ['flag' => 1],
                    'hash' => 'sha256:abc',
                ],
            ],
        ];

        $apply = new WP_REST_Request('POST', '/dbvc/v1/bricks/apply');
        $apply->set_header('Content-Type', 'application/json');
        $apply->set_body(wp_json_encode([
            'manifest' => $manifest,
            'options' => ['dry_run' => true],
            'receipt_id' => 'pkg_rcpt_receipt',
        ]));
        $apply_response = rest_get_server()->dispatch($apply);
        $this->assertSame(200, $apply_response->get_status());
        $this->assertSame('pkg_rcpt_receipt', $apply_response->get_data()['receipt_id'] ?? '');

        $restore = new WP_REST_Request('POST', '/dbvc/v1/bricks/restore-points');
        $restore->set_header('Content-Type', 'application/json');
        $restore->set_body(wp_json_encode([
            'plan' => $apply_response->get_data()['plan'] ?? ['option_artifacts' => [], 'entity_artifacts' => []],
            'receipt_id' => 'pkg_rcpt_receipt',
        ]));
        $restore_response = rest_get_server()->dispatch($restore);
        $this->assertSame(200, $restore_response->get_status());
        $this->assertSame('pkg_rcpt_receipt', $restore_response->get_data()['receipt_id'] ?? '');

        $rollback = new WP_REST_Request('POST', '/dbvc/v1/bricks/restore-points/' . $restore_response->get_data()['restore_id'] . '/rollback');
        $rollback->set_header('Content-Type', 'application/json');
        $rollback->set_body(wp_json_encode([
            'receipt_id' => 'pkg_rcpt_receipt',
        ]));
        $rollback_response = rest_get_server()->dispatch($rollback);
        $this->assertSame(200, $rollback_response->get_status());
        $this->assertSame('pkg_rcpt_receipt', $rollback_response->get_data()['receipt_id'] ?? '');
    }

    public function test_delivery_diagnostics_exposes_timeline_and_transport_markers(): void
    {
        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, [
            'pkg_diag' => [
                'package_id' => 'pkg_diag',
                'version' => '3.0.0',
                'channel' => 'stable',
                'status' => DBVC_Bricks_Packages::STATUS_PUBLISHED,
                'receipt_id' => 'pkg_rcpt_diag',
                'artifacts' => [],
                'delivery_timeline' => [
                    ['state' => 'sent', 'at' => '2026-02-15T00:00:00+00:00', 'actor_id' => 1, 'context' => []],
                    ['state' => 'failed', 'at' => '2026-02-15T00:10:00+00:00', 'actor_id' => 1, 'context' => []],
                ],
                'delivery_transport' => [
                    'publish_remote' => [
                        'attempt_count' => 5,
                        'last_status' => 'failed',
                        'dead_letter' => true,
                        'remediation_hint' => 'Retry after configuration fix.',
                    ],
                ],
                'updated_at' => '2026-02-15T00:10:00+00:00',
            ],
        ]);

        $diag = DBVC_Bricks_Packages::get_delivery_diagnostics(10);
        $this->assertNotEmpty($diag);
        $row = $diag[0];
        $this->assertSame('pkg_rcpt_diag', $row['receipt_id'] ?? '');
        $this->assertNotEmpty($row['delivery_timeline'] ?? []);
        $this->assertTrue((bool) ($row['delivery_transport']['publish_remote']['dead_letter'] ?? false));
    }

    public function test_transport_retry_backoff_marks_dead_letter_after_threshold(): void
    {
        $package = [
            'package_id' => 'pkg_retry',
            'delivery_transport' => [],
        ];
        for ($i = 0; $i < DBVC_Bricks_Packages::RETRY_MAX_ATTEMPTS; $i++) {
            $package = DBVC_Bricks_Packages::update_transport_state($package, 'publish_remote', false, 'dbvc_bricks_publish_remote_failed');
        }
        $transport = $package['delivery_transport']['publish_remote'] ?? [];
        $this->assertSame(DBVC_Bricks_Packages::RETRY_MAX_ATTEMPTS, $transport['attempt_count'] ?? 0);
        $this->assertTrue((bool) ($transport['dead_letter'] ?? false));
        $this->assertNotEmpty($transport['next_retry_at'] ?? '');
        $this->assertNotEmpty($transport['remediation_hint'] ?? '');
    }

    public function test_client_packages_panel_shows_credential_rotation_warning_when_stale(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_credentials_rotate_days', '1');
        update_option('dbvc_bricks_credentials_updated_at', '2026-02-01T00:00:00+00:00');
        $render = $this->render_admin_page('packages');
        $this->assertStringContainsString('Credential age warning', $render);
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
