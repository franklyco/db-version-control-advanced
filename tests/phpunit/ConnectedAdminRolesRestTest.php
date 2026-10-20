<?php

use Dbvc\AgencyControl\Storage\Schema as HubSchema;

/**
 * Phase B: the Connected Environments admin page's Roles panel REST surface
 * (`dbvc/v1/connected-admin/roles`). Admin-only (manage_options) grant/revoke/list
 * of the publisher & approver hub capabilities on non-admin users, wrapping the
 * CLI inspector (RoleService).
 *
 * @group connected-environments
 */
class ConnectedAdminRolesRestTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        HubSchema::reset_memo();
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
        HubSchema::install();
        $GLOBALS['wp_rest_server'] = null; // re-register routes with the hub ready
        rest_get_server();
    }

    public function tear_down(): void
    {
        wp_set_current_user(0);
        DBVC_Agency_Control_Addon::save_settings([]);
        remove_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        HubSchema::reset_memo();
        $GLOBALS['wp_rest_server'] = null;
        parent::tear_down();
    }

    public function savepoint_strategy(): string
    {
        return 'savepoint';
    }

    private function as_admin(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function request(string $method, string $route, array $body = null): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/dbvc/v1/' . ltrim($route, '/'));
        if ($body !== null) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(wp_json_encode($body));
        }

        return rest_do_request($request);
    }

    public function test_list_is_empty_then_reflects_a_grant(): void
    {
        $this->as_admin();
        $editor = self::factory()->user->create(['role' => 'editor']);

        $list = $this->request('GET', 'connected-admin/roles');
        $this->assertSame(200, $list->get_status(), wp_json_encode($list->get_data()));
        $data = $list->get_data();
        $this->assertTrue($data['hub_ready']);
        $this->assertSame(['publisher', 'approver'], $data['roles']);
        $this->assertSame([], $data['publishers']);
        $this->assertSame([], $data['approvers']);

        $this->request('POST', 'connected-admin/roles', ['action' => 'grant', 'user' => (string) $editor, 'role' => 'publisher']);

        $after = $this->request('GET', 'connected-admin/roles')->get_data();
        $logins = array_column($after['publishers'], 'id');
        $this->assertContains($editor, $logins, 'the granted non-admin appears as a publisher');
        $this->assertSame([], $after['approvers']);
    }

    public function test_grant_then_revoke_changes_the_real_capability(): void
    {
        $this->as_admin();
        $editor = self::factory()->user->create(['role' => 'editor']);

        $grant = $this->request('POST', 'connected-admin/roles', ['action' => 'grant', 'user' => (string) $editor, 'role' => 'approver']);
        $this->assertSame(200, $grant->get_status(), wp_json_encode($grant->get_data()));
        $this->assertTrue($grant->get_data()['changed']['is_approver']);
        $this->assertTrue((new WP_User($editor))->has_cap(HubSchema::CAP_APPROVE));
        $this->assertFalse((new WP_User($editor))->has_cap(HubSchema::CAP_PUBLISH));

        $revoke = $this->request('POST', 'connected-admin/roles', ['action' => 'revoke', 'user' => (string) $editor, 'role' => 'approver']);
        $this->assertSame(200, $revoke->get_status());
        $this->assertFalse($revoke->get_data()['changed']['is_approver']);
        $this->assertFalse((new WP_User($editor))->has_cap(HubSchema::CAP_APPROVE));
    }

    public function test_invalid_action_is_400(): void
    {
        $this->as_admin();
        $r = $this->request('POST', 'connected-admin/roles', ['action' => 'promote', 'user' => '1', 'role' => 'publisher']);
        $this->assertSame(400, $r->get_status());
        $this->assertSame('dbvc_connected_admin_invalid_action', $r->get_data()['code']);
    }

    public function test_invalid_role_is_400(): void
    {
        $this->as_admin();
        $editor = self::factory()->user->create(['role' => 'editor']);
        $r = $this->request('POST', 'connected-admin/roles', ['action' => 'grant', 'user' => (string) $editor, 'role' => 'wizard']);
        $this->assertSame(400, $r->get_status());
        $this->assertSame('dbvc_agency_invalid_role', $r->get_data()['code']);
    }

    public function test_unknown_user_is_404(): void
    {
        $this->as_admin();
        $r = $this->request('POST', 'connected-admin/roles', ['action' => 'grant', 'user' => 'nobody-here-xyz', 'role' => 'publisher']);
        $this->assertSame(404, $r->get_status());
        $this->assertSame('dbvc_agency_user_not_found', $r->get_data()['code']);
    }

    public function test_non_administrator_is_forbidden(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $get = $this->request('GET', 'connected-admin/roles');
        $this->assertSame(rest_authorization_required_code(), $get->get_status());
        $post = $this->request('POST', 'connected-admin/roles', ['action' => 'grant', 'user' => '1', 'role' => 'publisher']);
        $this->assertSame(rest_authorization_required_code(), $post->get_status());
    }

    public function test_hub_not_ready_returns_quiet_empty_shape(): void
    {
        DBVC_Agency_Control_Addon::save_settings([]); // disable the hub
        HubSchema::reset_memo();
        $GLOBALS['wp_rest_server'] = null;
        rest_get_server();
        $this->as_admin();

        // The route only exists while a gate is ready; with the connector off too,
        // the page is unavailable. Enable the connector so the page (and its
        // role-neutral routes) register, but the hub is not ready.
        DBVC_Connected_Environments_Addon::save_settings([DBVC_Connected_Environments_Addon::OPTION_ENABLED => '1']);
        $GLOBALS['wp_rest_server'] = null;
        rest_get_server();

        $get = $this->request('GET', 'connected-admin/roles');
        $this->assertSame(200, $get->get_status(), wp_json_encode($get->get_data()));
        $this->assertFalse($get->get_data()['hub_ready']);

        $post = $this->request('POST', 'connected-admin/roles', ['action' => 'grant', 'user' => '1', 'role' => 'publisher']);
        $this->assertSame(409, $post->get_status());

        DBVC_Connected_Environments_Addon::save_settings([]);
    }
}
