<?php

use Dbvc\AgencyControl\Governance\RoleService;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;

/**
 * M9 slice 7b completion — delegating the hub publisher / approver capabilities
 * to non-administrator users. The 7b split gated routes on CAP_PUBLISH /
 * CAP_APPROVE but only the administrator role held them; RoleService (behind
 * `wp dbvc agency role-grant|role-revoke|roles`) grants or revokes a single cap
 * on one user and lists the delegates. Cap assignment is operator-CLI-only — the
 * cookie-authenticated REST surface is unchanged.
 *
 * @group connected-environments
 */
class ConnectedRoleAssignmentTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        add_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        delete_option(HubSchema::OPTION_SCHEMA_VERSION);
        HubSchema::reset_memo();
        DBVC_Agency_Control_Addon::save_settings([DBVC_Agency_Control_Addon::OPTION_ENABLED => '1']);
    }

    public function tear_down(): void
    {
        DBVC_Agency_Control_Addon::save_settings([]);
        remove_filter('dbvc_agency_control_transaction_strategy', [$this, 'savepoint_strategy']);
        HubSchema::reset_memo();
        parent::tear_down();
    }

    public function savepoint_strategy(): string
    {
        return 'savepoint';
    }

    public function test_grant_and_revoke_a_single_cap(): void
    {
        $svc = new RoleService();
        $uid = self::factory()->user->create(['role' => 'subscriber', 'user_login' => 'delegate-pub']);

        $row = $svc->grant((string) $uid, 'publisher');
        $this->assertIsArray($row, is_wp_error($row) ? $row->get_error_message() : '');
        $this->assertTrue($row['is_publisher']);
        $this->assertFalse($row['is_approver'], 'only the requested cap is granted');
        $this->assertFalse($row['is_administrator']);
        $this->assertTrue(user_can($uid, HubSchema::CAP_PUBLISH), 'the cap is really on the user');

        // Revoke is scoped to the one cap.
        $row = $svc->revoke((string) $uid, 'publisher');
        $this->assertFalse($row['is_publisher']);
        $this->assertFalse(user_can($uid, HubSchema::CAP_PUBLISH));
    }

    public function test_resolve_user_by_login_or_email_and_role_aliases(): void
    {
        $svc = new RoleService();
        $uid = self::factory()->user->create(['role' => 'editor', 'user_login' => 'approver-eva', 'user_email' => 'eva@example.com']);

        $this->assertTrue($svc->grant('approver-eva', 'approve')['is_approver'], 'login + "approve" alias');
        $this->assertTrue($svc->grant('eva@example.com', 'approver')['is_approver'], 'email + "approver"');
        $this->assertTrue(user_can($uid, HubSchema::CAP_APPROVE));
    }

    public function test_invalid_role_and_unknown_user_are_rejected(): void
    {
        $svc = new RoleService();
        $uid = self::factory()->user->create(['role' => 'subscriber']);

        $bad_role = $svc->grant((string) $uid, 'superuser');
        $this->assertWPError($bad_role);
        $this->assertSame('dbvc_agency_invalid_role', $bad_role->get_error_code());

        $no_user = $svc->grant('nobody-here', 'publisher');
        $this->assertWPError($no_user);
        $this->assertSame('dbvc_agency_user_not_found', $no_user->get_error_code());

        $empty = $svc->grant('', 'publisher');
        $this->assertWPError($empty);
        $this->assertSame('dbvc_agency_user_not_found', $empty->get_error_code());
    }

    public function test_assignments_lists_delegates_but_not_administrators(): void
    {
        $svc = new RoleService();
        $pub = self::factory()->user->create(['role' => 'subscriber', 'user_login' => 'pub-one']);
        $apr = self::factory()->user->create(['role' => 'subscriber', 'user_login' => 'apr-one']);
        $admin = self::factory()->user->create(['role' => 'administrator']);
        // Make the agency caps real on the administrator role (as boot does).
        HubSchema::ensure_capabilities();

        $svc->grant((string) $pub, 'publisher');
        $svc->grant((string) $apr, 'approver');

        $out = $svc->assignments();
        $pub_logins = array_column($out['publishers'], 'login');
        $apr_logins = array_column($out['approvers'], 'login');
        $this->assertContains('pub-one', $pub_logins);
        $this->assertContains('apr-one', $apr_logins);
        // The administrator holds both caps via manage_options but is NOT a delegate.
        $admin_login = get_user_by('id', $admin)->user_login;
        $this->assertNotContains($admin_login, $pub_logins, 'administrators are not listed as delegates');
        $this->assertNotContains($admin_login, $apr_logins);
    }

    public function test_cli_inspector_maps_user_and_role_onto_the_service(): void
    {
        $uid = self::factory()->user->create(['role' => 'subscriber']);

        $row = DBVC_Agency_CLI_Inspector::role_grant(['user' => (string) $uid, 'role' => 'approver']);
        $this->assertIsArray($row, is_wp_error($row) ? $row->get_error_message() : '');
        $this->assertTrue($row['is_approver']);
        $this->assertFalse($row['is_publisher']);

        $revoked = DBVC_Agency_CLI_Inspector::role_revoke(['user' => (string) $uid, 'role' => 'approver']);
        $this->assertFalse($revoked['is_approver']);
    }
}
