<?php

use Dbvc\AgencyControl\Admin\HubPanel;
use Dbvc\AgencyControl\Enrollment\SubscriptionService;
use Dbvc\AgencyControl\Framework\FrameworkService;
use Dbvc\AgencyControl\Storage\InvitationStore;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;
use Dbvc\Connected\Admin\InboxPanel;
use Dbvc\Connected\Storage\InboxStore;
use Dbvc\Connected\Storage\Schema as ConnectorSchema;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * M3 step 3: administrator surfaces on top of the CLI inspectors — hub
 * panel (environments, invitations, framework status, review items, the
 * invitation action) and the connector inbox table. Public requests and
 * disabled gates register nothing.
 *
 * @group connected-environments
 */
class ConnectedAdminSurfacesTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        add_filter('dbvc_connected_transaction_strategy', [$this, 'savepoint_strategy']);
        add_filter('wp_is_application_passwords_available', '__return_true');
        add_filter('application_password_is_api_request', '__return_true');
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        delete_option(ConnectorSchema::OPTION_SCHEMA_VERSION);
        HubSchema::reset_memo();
        ConnectorSchema::reset_memo();
        $GLOBALS['wp_rest_server'] = null;
        rest_get_server();
    }

    public function tear_down(): void
    {
        wp_set_current_user(0);
        $GLOBALS['wp_rest_application_password_uuid'] = null;
        DBVC_Agency_Control_Addon::save_settings([]);
        DBVC_Connected_Environments_Addon::save_settings([]);
        remove_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        remove_filter('dbvc_connected_transaction_strategy', [$this, 'savepoint_strategy']);
        remove_filter('wp_is_application_passwords_available', '__return_true');
        remove_filter('application_password_is_api_request', '__return_true');
        HubSchema::reset_memo();
        ConnectorSchema::reset_memo();
        unset($GLOBALS['current_screen']);
        parent::tear_down();
    }

    public function savepoint_strategy(): string
    {
        return 'savepoint';
    }

    public function test_panel_actions_exist_only_inside_wp_admin_with_the_hub_ready(): void
    {
        $this->assertFalse(has_action('admin_post_' . HubPanel::ACTION_INVITE, [HubPanel::class, 'handle_invite']));
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        $this->assertSame('ready', DBVC_Agency_Control_Addon::get_gate_state());
        $this->assertFalse(is_admin());
        $this->assertFalse(has_action('admin_post_' . HubPanel::ACTION_INVITE, [HubPanel::class, 'handle_invite']), 'A non-admin request (front end, REST, cron) never registers the panel action.');

        // Nothing renders for a disabled hub or for a user without manage_options, whatever the gate says.
        DBVC_Agency_Control_Addon::save_settings([]);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertSame('', $this->capture([HubPanel::class, 'render']));
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertSame('', $this->capture([HubPanel::class, 'render']));
        $this->assertSame('', $this->capture([HubPanel::class, 'render_invite_form_element']));
    }

    public function test_hub_panel_creates_invitations_shows_the_token_once_and_lists_hub_state(): void
    {
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        $GLOBALS['wp_rest_server'] = null;
        rest_get_server();
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        set_current_screen('toplevel_page_dbvc-export');

        // The invitation action: validation errors and success are both held for exactly one display.
        $redirect = HubPanel::process_invite_request(['dbvc_agency_invite_client' => 'bad client!']);
        $this->assertStringContainsString('page=dbvc-export', $redirect);
        $notice = $this->capture([HubPanel::class, 'render_invite_notice']);
        $this->assertStringContainsString('notice-error', $notice);
        $this->assertStringContainsString('client_id must be a bounded ASCII identifier', $notice);
        $this->assertSame('', $this->capture([HubPanel::class, 'render_invite_notice']), 'Shown once.');

        HubPanel::process_invite_request(['dbvc_agency_invite_client' => 'client-a', 'dbvc_agency_invite_label' => 'Client A production', 'dbvc_agency_invite_environment' => 'a-prod', 'dbvc_agency_invite_ttl' => '600']);
        $invitations = (new InvitationStore())->all();
        $this->assertCount(1, $invitations);
        $this->assertSame('a-prod', $invitations[0]['environment_id']);
        $this->assertArrayNotHasKey('token', $invitations[0], 'The store keeps a hash, never the token.');
        $notice = $this->capture([HubPanel::class, 'render_invite_notice']);
        $this->assertStringContainsString('notice-success', $notice);
        $this->assertStringContainsString('wp dbvc connected enroll --hub=' . home_url('/') . ' --token=', $notice);
        preg_match('/--token=([A-Za-z0-9._:-]+)/', $notice, $match);
        $this->assertNotEmpty($match[1] ?? '');
        $this->assertSame('', $this->capture([HubPanel::class, 'render_invite_notice']), 'The token is gone after one display.');
        $this->assertStringNotContainsString($match[1], $this->capture([HubPanel::class, 'render']), 'The panel never repeats a token.');

        // Another user sees nothing: the held outcome is scoped to the administrator who created it.
        HubPanel::process_invite_request(['dbvc_agency_invite_client' => 'client-b']);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        set_current_screen('toplevel_page_dbvc-export');
        $this->assertSame('', $this->capture([HubPanel::class, 'render_invite_notice']));
        wp_set_current_user($admin);
        set_current_screen('toplevel_page_dbvc-export');
        $this->assertStringContainsString('notice-success', $this->capture([HubPanel::class, 'render_invite_notice']));

        // Enroll a-prod with the first token, report a class, subscribe it to a framework definition.
        $exchange = new WP_REST_Request('POST', '/dbvc-agency/v1/enrollments/exchange');
        $exchange->set_header('Content-Type', 'application/json');
        $exchange->set_body(wp_json_encode(['token' => $match[1], 'environment_id' => 'a-prod', 'site_url' => 'https://a-prod.example/', 'protocol_versions' => [Protocol::PROTOCOL_VERSION]]));
        $response = rest_do_request($exchange);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $env = $response->get_data();
        $hash = hash('sha256', 'fixture:x1');
        $event = ObservationEvent::build([
            'event_id' => 'obs-000000000001', 'environment_id' => 'a-prod', 'installation_epoch' => $env['installation_epoch'], 'sequence' => 1, 'observed_at' => '2026-09-19T10:00:00Z',
            'object' => ['domain' => 'bricks.global_class', 'instance_uid' => 'class-x'],
            'projection' => ['profile' => 'bricks-global-class-v1', 'hash' => $hash, 'exists' => true, 'complete' => true],
        ]);
        (new FrameworkService())->publish(['agency_id' => 'studio', 'definition_uid' => 'def-a', 'version' => '1.0', 'domain' => 'bricks.global_class', 'profile' => 'bricks-global-class-v1', 'hash' => hash('sha256', 'fixture:v1'), 'desired' => true]);
        (new SubscriptionService())->subscribe_framework('a-prod', 'bricks.global_class', 'class-x', 'def-a', '1.0');
        wp_set_current_user(get_user_by('login', $env['principal']['username'])->ID);
        $GLOBALS['wp_rest_application_password_uuid'] = $env['principal']['uuid'];
        $report = new WP_REST_Request('POST', '/dbvc-agency/v1/observations');
        $report->set_header('Content-Type', 'application/json');
        $report->set_body(wp_json_encode(['events' => [$event]]));
        $this->assertSame(200, rest_do_request($report)->get_status());
        $GLOBALS['wp_rest_application_password_uuid'] = null;
        wp_set_current_user($admin);
        set_current_screen('toplevel_page_dbvc-export');

        $html = $this->capture([HubPanel::class, 'render']);
        $this->assertStringContainsString('<code>a-prod</code>', $html);
        $this->assertStringContainsString('client-a', $html);
        $this->assertStringContainsString('<code>' . $env['installation_epoch'] . '</code>', $html);
        $this->assertStringContainsString('client-b', $html, 'The open invitation for client-b is listed.');
        $this->assertStringContainsString('<code>def-a</code>', $html);
        $this->assertStringContainsString('local_drift', $html, 'The framework status row: the class hash differs from 1.0.');
        $this->assertStringContainsString('1 total: 1 observed', $html, 'The review item created by routing is counted.');
        $this->assertStringContainsString('0 total: 0 open (collecting payloads), 0 sealed, 0 withdrawn', $html, 'Releases are listed (none yet).');
        $this->assertStringContainsString('Prepare receipts', $html);
        $this->assertStringContainsString('form="' . HubPanel::FORM_ID . '"', $html, 'Panel fields target the external invitation form.');
        $this->assertStringNotContainsString('<form', $html, 'No nested form inside the settings form.');
        $this->assertStringNotContainsString($match[1], $html);
        $form = $this->capture([HubPanel::class, 'render_invite_form_element']);
        $this->assertStringContainsString('id="' . HubPanel::FORM_ID . '"', $form);
        $this->assertStringContainsString('admin-post.php', $form);
        $this->assertStringContainsString('_wpnonce', $form);
        $this->assertSame(1, (int) preg_match_all('/<form /', $form));

        // Off the DBVC screen the notice and form element stay silent.
        set_current_screen('dashboard');
        $this->assertSame('', $this->capture([HubPanel::class, 'render_invite_form_element']));
    }

    public function test_connector_inbox_panel_lists_received_observations_read_only(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertSame('', $this->capture([InboxPanel::class, 'render']), 'Disabled connector renders nothing.');
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        $this->assertSame('ready', DBVC_Connected_Environments_Addon::get_gate_state());
        $html = $this->capture([InboxPanel::class, 'render']);
        $this->assertStringContainsString('Nothing received yet', $html);

        $body = ObservationEvent::build([
            'event_id' => 'obs-000000000007', 'environment_id' => 'a-prod', 'installation_epoch' => 'enrolled-x', 'sequence' => 7, 'observed_at' => '2026-09-19T10:00:00Z',
            'object' => ['domain' => 'wp.service', 'instance_uid' => 'svc-1'],
            'projection' => ['profile' => 'wp-service-v1', 'hash' => hash('sha256', 'fixture:s1'), 'exists' => true, 'complete' => false],
        ]);
        $this->assertSame('inserted', (new InboxStore())->insert([
            'delivery_id' => 42, 'source_environment_id' => 'a-prod', 'installation_epoch' => 'enrolled-x', 'event_id' => 'obs-000000000007', 'sequence' => 7,
            'domain' => 'wp.service', 'instance_uid' => 'svc-1', 'profile' => 'wp-service-v1', 'body_digest' => ObservationEvent::digest($body), 'policy_revision' => 'rev', 'event' => $body,
        ], 'https://hub.example/'));
        $html = $this->capture([InboxPanel::class, 'render']);
        $this->assertStringContainsString('1 received from 1 source(s), 1 awaiting acknowledgement', $html);
        $this->assertStringContainsString('<code>a-prod</code>', $html);
        $this->assertStringContainsString('<code>svc-1</code>', $html);
        $this->assertStringContainsString('wp-service-v1', $html);
        $this->assertStringContainsString('pending', $html);
        $this->assertStringContainsString(substr(hash('sha256', 'fixture:s1'), 0, 12), $html);
        $this->assertStringContainsString('Prepare receipts (dry runs)', $html);
        $this->assertStringContainsString('0 produced, 0 not yet accepted', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    /**
     * @param callable $callback
     */
    private function capture($callback): string
    {
        ob_start();
        call_user_func($callback);

        return (string) ob_get_clean();
    }
}
