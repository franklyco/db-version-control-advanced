<?php

class BricksAddonPhase19BTest extends WP_UnitTestCase
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private $audit_events = [];

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_read_only', '0');
        update_option(DBVC_Bricks_Idempotency::OPTION_STORE, []);
        update_option(DBVC_Bricks_Protected_Variants::OPTION_VARIANTS, []);
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
        update_option(DBVC_Bricks_Idempotency::OPTION_STORE, []);
        update_option(DBVC_Bricks_Protected_Variants::OPTION_VARIANTS, []);
        remove_action('dbvc_bricks_audit_event', [$this, 'capture_audit_event'], 10);
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        parent::tear_down();
    }

    public function test_protected_variant_crud_is_deduped_and_audited(): void
    {
        add_action('dbvc_bricks_audit_event', [$this, 'capture_audit_event'], 10, 2);

        $create = new WP_REST_Request('POST', '/dbvc/v1/bricks/protected-variants');
        $create->set_header('Content-Type', 'application/json');
        $create->set_header('Idempotency-Key', 'p19b-create-1');
        $create->set_body(wp_json_encode([
            'artifact_uid' => 'option:bricks_theme_styles',
            'artifact_type' => 'option',
            'label' => 'Theme Styles',
            'reason' => 'Intentional local variation for staging.',
            'scope' => 'site_local',
        ]));
        $create_response = rest_get_server()->dispatch($create);
        $this->assertSame(200, $create_response->get_status());
        $create_data = $create_response->get_data();
        $this->assertTrue((bool) ($create_data['ok'] ?? false));
        $this->assertSame('created', (string) ($create_data['action'] ?? ''));
        $variant_id = sanitize_key((string) ($create_data['variant']['variant_id'] ?? ''));
        $this->assertNotSame('', $variant_id);

        $upsert = new WP_REST_Request('POST', '/dbvc/v1/bricks/protected-variants');
        $upsert->set_header('Content-Type', 'application/json');
        $upsert->set_header('Idempotency-Key', 'p19b-create-2');
        $upsert->set_body(wp_json_encode([
            'artifact_uid' => 'option:bricks_theme_styles',
            'reason' => 'Updated reason from create upsert.',
            'scope' => 'site_local',
        ]));
        $upsert_response = rest_get_server()->dispatch($upsert);
        $this->assertSame(200, $upsert_response->get_status());
        $upsert_data = $upsert_response->get_data();
        $this->assertSame('updated_reason', (string) ($upsert_data['action'] ?? ''));

        $list = new WP_REST_Request('GET', '/dbvc/v1/bricks/protected-variants');
        $list_response = rest_get_server()->dispatch($list);
        $this->assertSame(200, $list_response->get_status());
        $list_data = $list_response->get_data();
        $this->assertSame(1, count($list_data['items'] ?? []));
        $this->assertSame(1, (int) (($list_data['summary']['total'] ?? 0)));

        $update = new WP_REST_Request('PATCH', '/dbvc/v1/bricks/protected-variants/' . $variant_id);
        $update->set_header('Content-Type', 'application/json');
        $update->set_header('Idempotency-Key', 'p19b-update-1');
        $update->set_body(wp_json_encode([
            'reason' => 'Updated reason via patch endpoint.',
        ]));
        $update_response = rest_get_server()->dispatch($update);
        $this->assertSame(200, $update_response->get_status());
        $update_data = $update_response->get_data();
        $this->assertSame('updated_reason', (string) ($update_data['action'] ?? ''));

        $delete = new WP_REST_Request('DELETE', '/dbvc/v1/bricks/protected-variants/' . $variant_id);
        $delete->set_header('Idempotency-Key', 'p19b-delete-1');
        $delete->set_param('confirm_remove', true);
        $delete_response = rest_get_server()->dispatch($delete);
        $this->assertSame(200, $delete_response->get_status());
        $delete_data = $delete_response->get_data();
        $this->assertSame('removed', (string) ($delete_data['action'] ?? ''));

        $final_list = new WP_REST_Request('GET', '/dbvc/v1/bricks/protected-variants');
        $final_response = rest_get_server()->dispatch($final_list);
        $this->assertSame(200, $final_response->get_status());
        $this->assertSame(0, count($final_response->get_data()['items'] ?? []));

        $event_names = array_map(static function ($row) {
            return is_array($row) ? (string) ($row['event'] ?? '') : '';
        }, $this->audit_events);
        $this->assertContains('protected_variant_created', $event_names);
        $this->assertContains('protected_variant_updated_reason', $event_names);
        $this->assertContains('protected_variant_removed', $event_names);
    }

    public function test_protected_variant_create_requires_idempotency_key(): void
    {
        $create = new WP_REST_Request('POST', '/dbvc/v1/bricks/protected-variants');
        $create->set_header('Content-Type', 'application/json');
        $create->set_body(wp_json_encode([
            'artifact_uid' => 'option:bricks_theme_styles',
            'reason' => 'No key should fail.',
        ]));
        $response = rest_get_server()->dispatch($create);
        $this->assertSame(400, $response->get_status());
        $this->assertSame('dbvc_bricks_idempotency_required', (string) ($response->get_data()['code'] ?? ''));
    }

    public function test_protected_variant_endpoints_require_client_role(): void
    {
        update_option('dbvc_bricks_role', 'mothership');

        $list = new WP_REST_Request('GET', '/dbvc/v1/bricks/protected-variants');
        $list_response = rest_get_server()->dispatch($list);
        $this->assertSame(400, $list_response->get_status());
        $this->assertSame('dbvc_bricks_protected_variant_role_invalid', (string) ($list_response->get_data()['code'] ?? ''));

        $create = new WP_REST_Request('POST', '/dbvc/v1/bricks/protected-variants');
        $create->set_header('Content-Type', 'application/json');
        $create->set_header('Idempotency-Key', 'p19b-role-1');
        $create->set_body(wp_json_encode([
            'artifact_uid' => 'option:bricks_theme_styles',
            'reason' => 'Role should reject this.',
        ]));
        $create_response = rest_get_server()->dispatch($create);
        $this->assertSame(400, $create_response->get_status());
        $this->assertSame('dbvc_bricks_protected_variant_role_invalid', (string) ($create_response->get_data()['code'] ?? ''));
    }

    public function test_protected_variant_mutations_blocked_when_read_only(): void
    {
        update_option('dbvc_bricks_read_only', '1');

        $create = new WP_REST_Request('POST', '/dbvc/v1/bricks/protected-variants');
        $create->set_header('Content-Type', 'application/json');
        $create->set_header('Idempotency-Key', 'p19b-readonly-1');
        $create->set_body(wp_json_encode([
            'artifact_uid' => 'option:bricks_theme_styles',
            'reason' => 'Read-only should block this write.',
        ]));
        $response = rest_get_server()->dispatch($create);
        $this->assertSame(403, $response->get_status());
        $this->assertSame('dbvc_bricks_protected_variant_read_only', (string) ($response->get_data()['code'] ?? ''));
    }

    public function test_protected_variant_create_is_idempotent_for_same_key(): void
    {
        $payload = [
            'artifact_uid' => 'option:bricks_theme_styles',
            'artifact_type' => 'option',
            'reason' => 'Idempotent create.',
            'scope' => 'site_local',
        ];

        $first = new WP_REST_Request('POST', '/dbvc/v1/bricks/protected-variants');
        $first->set_header('Content-Type', 'application/json');
        $first->set_header('Idempotency-Key', 'p19b-idempotent-1');
        $first->set_body(wp_json_encode($payload));
        $first_response = rest_get_server()->dispatch($first);
        $this->assertSame(200, $first_response->get_status());
        $first_data = $first_response->get_data();
        $this->assertSame('created', (string) ($first_data['action'] ?? ''));

        $replay = new WP_REST_Request('POST', '/dbvc/v1/bricks/protected-variants');
        $replay->set_header('Content-Type', 'application/json');
        $replay->set_header('Idempotency-Key', 'p19b-idempotent-1');
        $replay->set_body(wp_json_encode($payload));
        $replay_response = rest_get_server()->dispatch($replay);
        $this->assertSame(200, $replay_response->get_status());
        $this->assertSame($first_data, $replay_response->get_data());
    }

    public function test_protected_variant_delete_requires_confirmation_flag(): void
    {
        $create = new WP_REST_Request('POST', '/dbvc/v1/bricks/protected-variants');
        $create->set_header('Content-Type', 'application/json');
        $create->set_header('Idempotency-Key', 'p19b-delete-create');
        $create->set_body(wp_json_encode([
            'artifact_uid' => 'option:bricks_theme_styles',
            'reason' => 'Ready for delete guard check.',
        ]));
        $create_response = rest_get_server()->dispatch($create);
        $this->assertSame(200, $create_response->get_status());
        $variant_id = sanitize_key((string) ($create_response->get_data()['variant']['variant_id'] ?? ''));
        $this->assertNotSame('', $variant_id);

        $delete = new WP_REST_Request('DELETE', '/dbvc/v1/bricks/protected-variants/' . $variant_id);
        $delete->set_header('Idempotency-Key', 'p19b-delete-no-confirm');
        $delete_response = rest_get_server()->dispatch($delete);
        $this->assertSame(400, $delete_response->get_status());
        $this->assertSame('dbvc_bricks_protected_variant_delete_invalid', (string) ($delete_response->get_data()['code'] ?? ''));
    }

    public function test_protected_artifacts_tab_renders_for_client_role_only(): void
    {
        update_option('dbvc_bricks_role', 'client');
        $client = $this->render_admin_page('protected_artifacts');
        $this->assertStringContainsString('id="dbvc-bricks-tab-protected_artifacts"', $client);
        $this->assertStringContainsString('id="dbvc-bricks-panel-protected_artifacts"', $client);
        $this->assertStringContainsString('id="dbvc-bricks-refresh-protected-variants"', $client);
        $this->assertStringContainsString('id="dbvc-bricks-protected-artifact-uid"', $client);
        $this->assertStringContainsString('id="dbvc-bricks-protected-artifact-reason"', $client);
        $this->assertStringContainsString('id="dbvc-bricks-add-protected-variant"', $client);
        $this->assertStringContainsString('id="dbvc-bricks-remove-protected-variant"', $client);
        $this->assertStringContainsString('id="dbvc-bricks-protected-variants-summary"', $client);
        $this->assertStringContainsString('"protectedVariantsEndpoint"', $client);
        $this->assertStringContainsString('function createProtectedVariant()', $client);
        $this->assertStringContainsString('function removeSelectedProtectedVariant()', $client);
        $this->assertStringContainsString('protected_variant_saved', $client);
        $this->assertStringContainsString('protected_variant_removed', $client);

        update_option('dbvc_bricks_role', 'mothership');
        $mothership = $this->render_admin_page('overview');
        $this->assertStringNotContainsString('id="dbvc-bricks-tab-protected_artifacts"', $mothership);
        $this->assertStringNotContainsString('id="dbvc-bricks-panel-protected_artifacts"', $mothership);
    }

    public function test_protected_variant_routes_require_manage_options_capability(): void
    {
        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);

        $list = new WP_REST_Request('GET', '/dbvc/v1/bricks/protected-variants');
        $response = rest_get_server()->dispatch($list);
        $this->assertSame(403, $response->get_status());
        $this->assertSame('rest_forbidden', (string) ($response->get_data()['code'] ?? ''));
    }

    public function test_differences_panel_protected_mark_unmark_controls_render_for_client_only(): void
    {
        update_option('dbvc_bricks_role', 'client');
        $client = $this->render_admin_page('differences');
        $this->assertStringContainsString('id="dbvc-bricks-diff-protected-reason"', $client);
        $this->assertStringContainsString('id="dbvc-bricks-diff-mark-protected"', $client);
        $this->assertStringContainsString('id="dbvc-bricks-diff-unmark-protected"', $client);
        $this->assertStringContainsString('id="dbvc-bricks-diff-protected-state"', $client);
        $this->assertStringContainsString('function markSelectedDiffProtected()', $client);
        $this->assertStringContainsString('function unmarkSelectedDiffProtected()', $client);
        $this->assertStringContainsString('function syncDiffProtectedControls()', $client);

        update_option('dbvc_bricks_role', 'mothership');
        $mothership = $this->render_admin_page('differences');
        $this->assertStringNotContainsString('id="dbvc-bricks-diff-mark-protected"', $mothership);
        $this->assertStringNotContainsString('id="dbvc-bricks-diff-unmark-protected"', $mothership);
    }

    public function test_read_only_mode_disables_protected_variant_mutating_controls(): void
    {
        update_option('dbvc_bricks_role', 'client');
        update_option('dbvc_bricks_read_only', '1');

        $protected = $this->render_admin_page('protected_artifacts');
        $this->assertStringContainsString('id="dbvc-bricks-add-protected-variant" disabled="disabled"', $protected);
        $this->assertStringContainsString('id="dbvc-bricks-remove-protected-variant" disabled="disabled"', $protected);
        $this->assertStringContainsString('Review-only mode is on, so protection changes are disabled.', $protected);

        $differences = $this->render_admin_page('differences');
        $this->assertStringContainsString('id="dbvc-bricks-diff-mark-protected" disabled="disabled"', $differences);
        $this->assertStringContainsString('id="dbvc-bricks-diff-unmark-protected" disabled="disabled"', $differences);
    }

    public function test_drift_apply_and_package_payloads_include_protected_variant_annotations_without_behavior_change(): void
    {
        update_option('dbvc_bricks_site_uid', 'client_phase19b');
        $this->create_protected_variant('option:bricks_global_settings', 'Intentional protected local override.');

        $manifest = [
            'package_id' => 'pkg-p19b-annotations',
            'version' => '2026.03.08.1',
            'channel' => 'stable',
            'status' => DBVC_Bricks_Packages::STATUS_PUBLISHED,
            'targeting' => ['mode' => 'all', 'site_uids' => []],
            'receipt_id' => 'pkg_rcpt_p19b',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
            'artifacts' => [
                [
                    'artifact_uid' => 'option:bricks_global_settings',
                    'artifact_type' => 'bricks_global_settings',
                    'payload' => ['palette' => ['primary' => '#123456']],
                ],
                [
                    'artifact_uid' => 'option:bricks_theme_styles',
                    'artifact_type' => 'bricks_theme_styles',
                    'payload' => ['spacing' => ['unit' => 'rem']],
                ],
            ],
        ];

        $drift_scan = new WP_REST_Request('POST', '/dbvc/v1/bricks/drift-scan');
        $drift_scan->set_header('Content-Type', 'application/json');
        $drift_scan->set_body(wp_json_encode([
            'manifest' => $manifest,
            'local_artifacts' => [
                'option:bricks_global_settings' => ['payload' => ['palette' => ['primary' => '#000000']]],
                'option:bricks_theme_styles' => ['payload' => ['spacing' => ['unit' => 'rem']]],
            ],
        ]));
        $drift_scan_response = rest_get_server()->dispatch($drift_scan);
        $this->assertSame(200, $drift_scan_response->get_status());
        $drift_scan_data = $drift_scan_response->get_data();
        $this->assertArrayHasKey('protected_variant_summary', $drift_scan_data);
        $this->assertGreaterThanOrEqual(1, (int) (($drift_scan_data['protected_variant_summary']['variant_records'] ?? 0)));
        $scan_rows = isset($drift_scan_data['artifacts']) && is_array($drift_scan_data['artifacts']) ? $drift_scan_data['artifacts'] : [];
        $scan_by_uid = [];
        foreach ($scan_rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $scan_by_uid[(string) ($row['artifact_uid'] ?? '')] = $row;
        }
        $this->assertTrue((bool) ($scan_by_uid['option:bricks_global_settings']['protected_variant']['is_protected'] ?? false));
        $this->assertFalse((bool) ($scan_by_uid['option:bricks_theme_styles']['protected_variant']['is_protected'] ?? true));

        $drift_compare = new WP_REST_Request('POST', '/dbvc/v1/bricks/drift-compare');
        $drift_compare->set_header('Content-Type', 'application/json');
        $drift_compare->set_body(wp_json_encode([
            'manifest' => $manifest,
            'local_artifacts' => [
                'option:bricks_global_settings' => ['payload' => ['palette' => ['primary' => '#000000']]],
            ],
            'artifact_uid' => 'option:bricks_global_settings',
        ]));
        $drift_compare_response = rest_get_server()->dispatch($drift_compare);
        $this->assertSame(200, $drift_compare_response->get_status());
        $drift_compare_data = $drift_compare_response->get_data();
        $this->assertTrue((bool) ($drift_compare_data['protected_variant']['is_protected'] ?? false));
        $this->assertGreaterThanOrEqual(1, (int) (($drift_compare_data['protected_variant_summary']['variant_records'] ?? 0)));

        update_option('bricks_global_settings', ['palette' => ['primary' => '#000000']]);
        $apply = new WP_REST_Request('POST', '/dbvc/v1/bricks/apply');
        $apply->set_header('Content-Type', 'application/json');
        $apply->set_header('Idempotency-Key', 'p19b-apply-annotations');
        $apply->set_body(wp_json_encode([
            'manifest' => $manifest,
            'options' => [
                'dry_run' => false,
                'allow_destructive' => false,
            ],
            'selection' => [
                'artifact_uids' => ['option:bricks_global_settings'],
            ],
            'receipt_id' => 'pkg_rcpt_p19b',
        ]));
        $apply_response = rest_get_server()->dispatch($apply);
        $this->assertSame(200, $apply_response->get_status());
        $apply_data = $apply_response->get_data();
        $this->assertFalse((bool) ($apply_data['dry_run'] ?? true));
        $this->assertContains('option:bricks_global_settings', (array) ($apply_data['applied']['options'] ?? []));
        $this->assertSame(['palette' => ['primary' => '#123456']], get_option('bricks_global_settings'));
        $this->assertGreaterThanOrEqual(1, (int) (($apply_data['protected_variant_summary']['variant_records'] ?? 0)));
        $this->assertTrue((bool) ($apply_data['protected_variant_lookup']['option:bricks_global_settings']['is_protected'] ?? false));

        update_option(DBVC_Bricks_Packages::OPTION_PACKAGES, [
            'pkg-p19b-annotations' => $manifest,
        ]);
        $packages_list = new WP_REST_Request('GET', '/dbvc/v1/bricks/packages');
        $packages_list_response = rest_get_server()->dispatch($packages_list);
        $this->assertSame(200, $packages_list_response->get_status());
        $packages_items = $packages_list_response->get_data()['items'] ?? [];
        $this->assertNotEmpty($packages_items);
        $first_package = is_array($packages_items[0] ?? null) ? $packages_items[0] : [];
        $this->assertArrayHasKey('protected_variant_summary', $first_package);
        $this->assertTrue((bool) ($first_package['artifacts'][0]['protected_variant']['is_protected'] ?? false));

        $package_get = new WP_REST_Request('GET', '/dbvc/v1/bricks/packages/pkg-p19b-annotations');
        $package_get_response = rest_get_server()->dispatch($package_get);
        $this->assertSame(200, $package_get_response->get_status());
        $manifest_data = $package_get_response->get_data()['manifest'] ?? [];
        $this->assertTrue((bool) ($manifest_data['artifacts'][0]['protected_variant']['is_protected'] ?? false));
        $this->assertArrayHasKey('protected_variant_lookup', $manifest_data);

        $pull_latest = new WP_REST_Request('POST', '/dbvc/v1/bricks/packages/pull-latest');
        $pull_latest->set_header('Content-Type', 'application/json');
        $pull_latest->set_body(wp_json_encode(['channel' => 'stable']));
        $pull_latest_response = rest_get_server()->dispatch($pull_latest);
        $this->assertSame(200, $pull_latest_response->get_status());
        $pull_latest_data = $pull_latest_response->get_data();
        $this->assertTrue((bool) ($pull_latest_data['manifest']['artifacts'][0]['protected_variant']['is_protected'] ?? false));
        $this->assertTrue((bool) ($pull_latest_data['dry_run_apply']['plan']['option_artifacts'][0]['protected_variant']['is_protected'] ?? false));
    }

    /**
     * @param string $event
     * @param array<string, mixed> $payload
     * @return void
     */
    public function capture_audit_event($event, $payload): void
    {
        $this->audit_events[] = [
            'event' => sanitize_key((string) $event),
            'payload' => is_array($payload) ? $payload : [],
        ];
    }

    private function render_admin_page(string $tab): string
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
        rest_get_server();
    }

    private function create_protected_variant(string $artifact_uid, string $reason): void
    {
        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/protected-variants');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Idempotency-Key', 'p19b-seed-' . substr(md5($artifact_uid . '|' . $reason), 0, 12));
        $request->set_body(wp_json_encode([
            'artifact_uid' => $artifact_uid,
            'artifact_type' => 'option',
            'reason' => $reason,
            'scope' => 'site_local',
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
    }
}
