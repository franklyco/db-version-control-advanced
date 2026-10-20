<?php

class BricksAddonPhase11Test extends WP_UnitTestCase
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private $events = [];

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '1');
        update_option('dbvc_bricks_role', 'client');
        delete_option(DBVC_Bricks_Addon::OPTION_UI_DIAGNOSTICS);
        DBVC_Bricks_Addon::refresh_runtime_registration();
        add_action('dbvc_bricks_ui_event', [$this, 'capture_ui_event']);
        $this->reset_rest_server();
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        remove_action('dbvc_bricks_ui_event', [$this, 'capture_ui_event']);
        delete_option(DBVC_Bricks_Addon::OPTION_UI_DIAGNOSTICS);
        update_option(DBVC_Bricks_Addon::OPTION_ENABLED, '0');
        DBVC_Bricks_Addon::refresh_runtime_registration();
        $this->reset_rest_server();
        $_GET = [];
        parent::tear_down();
    }

    public function test_accessibility_smoke_for_tab_and_detail_markup(): void
    {
        $output = $this->render_admin_page('differences');
        $this->assertStringContainsString('role="tablist"', $output);
        $this->assertStringContainsString('role="tabpanel"', $output);
        $this->assertStringContainsString('aria-labelledby="dbvc-bricks-tab-differences"', $output);
        $this->assertStringContainsString('id="dbvc-bricks-diff-detail" tabindex="0"', $output);
        $this->assertStringContainsString('aria-label="Bricks difference results"', $output);
    }

    public function test_error_and_retry_controls_render_across_ui(): void
    {
        $output = $this->render_admin_page('overview');
        $this->assertStringContainsString('id="dbvc-bricks-retry-last-action"', $output);
        $this->assertStringContainsString('id="dbvc-bricks-notice-error"', $output);
        $this->assertStringContainsString('id="dbvc-bricks-refresh-diagnostics"', $output);
        $this->assertStringContainsString('id="dbvc-bricks-diagnostics-json"', $output);
    }

    public function test_i18n_strings_are_rendered_for_phase11_additions(): void
    {
        $output = $this->render_admin_page('overview');
        $this->assertStringContainsString('Retry Last Action', $output);
        $this->assertStringContainsString('Recent Diagnostics', $output);
        $this->assertStringContainsString('Bricks admin sections', $output);
    }

    public function test_observability_ui_event_endpoint_persists_and_emits_hook(): void
    {
        $request = new WP_REST_Request('POST', '/dbvc/v1/bricks/ui-event');
        $request->set_header('content-type', 'application/json');
        $request->set_header('X-DBVC-Correlation-ID', 'corr-phase11');
        $request->set_body(wp_json_encode([
            'event_type' => 'ui_error',
            'payload' => ['context' => 'phase11-test'],
        ]));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue((bool) ($data['stored'] ?? false));
        $this->assertSame('corr-phase11', $data['entry']['correlation_id']);

        $stored = get_option(DBVC_Bricks_Addon::OPTION_UI_DIAGNOSTICS, []);
        $this->assertNotEmpty($stored);
        $this->assertNotEmpty($this->events);

        $diagnostics_request = new WP_REST_Request('GET', '/dbvc/v1/bricks/diagnostics');
        $diagnostics_response = rest_get_server()->dispatch($diagnostics_request);
        $this->assertSame(200, $diagnostics_response->get_status());
        $this->assertNotEmpty($diagnostics_response->get_data()['items']);
    }

    /**
     * @param array<string, mixed> $event
     * @return void
     */
    public function capture_ui_event($event): void
    {
        $this->events[] = $event;
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
