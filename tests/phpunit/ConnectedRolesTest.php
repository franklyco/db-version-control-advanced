<?php

use Dbvc\AgencyControl\Admin\RestController;
use Dbvc\AgencyControl\Storage\Schema as HubSchema;

/**
 * M9 slice 7b — human hub roles: a framework publisher vs a client approver,
 * enforced at the single route-aware RestController::permit() choke point.
 * manage_options stays a superset (admins never locked out), reads are open to
 * either cap, approver-only writes are fenced from the publisher and vice versa,
 * and an unmapped route fails safe to manage_options.
 *
 * @group connected-environments
 */
class ConnectedRolesTest extends WP_UnitTestCase
{
    private function user_with_cap(?string $cap): int
    {
        $id = self::factory()->user->create(['role' => 'subscriber']);
        if ($cap !== null) {
            get_user_by('id', $id)->add_cap($cap);
        }
        // Refresh the current-user cap cache deterministically.
        wp_set_current_user(0);
        wp_set_current_user($id);

        return $id;
    }

    public function test_publisher_and_approver_caps_gate_the_right_routes(): void
    {
        $rc = new RestController();

        // Publisher: framework authoring/distribution writes + reads; NOT approver writes.
        $this->user_with_cap(HubSchema::CAP_PUBLISH);
        $this->assertTrue($rc->permit('framework_publish'));
        $this->assertTrue($rc->permit('rollout_create'));
        $this->assertTrue($rc->permit('releases'), 'reads are open to either cap');
        $this->assertTrue($rc->permit('status'));
        $this->assertWPError($rc->permit('approve'), 'publisher cannot approve');
        $this->assertWPError($rc->permit('override_approve'));

        // Approver: reviewing/approving/rollback writes + reads; NOT publisher writes.
        $this->user_with_cap(HubSchema::CAP_APPROVE);
        $this->assertTrue($rc->permit('approve'));
        $this->assertTrue($rc->permit('rollback'));
        $this->assertTrue($rc->permit('review_resolve'));
        $this->assertTrue($rc->permit('releases'));
        $this->assertWPError($rc->permit('framework_publish'), 'approver cannot publish');
        $this->assertWPError($rc->permit('rollout_create'));
    }

    public function test_manage_options_is_a_superset_and_unmapped_routes_fail_safe(): void
    {
        $rc = new RestController();

        // Administrator passes every route, including an unmapped one.
        wp_set_current_user(0);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertTrue($rc->permit('framework_publish'));
        $this->assertTrue($rc->permit('approve'));
        $this->assertTrue($rc->permit('some_future_unmapped_route'));

        // A non-admin (even with a hub cap) is refused an unmapped route — fail-safe, no default-allow.
        $this->user_with_cap(HubSchema::CAP_PUBLISH);
        $this->assertWPError($rc->permit('some_future_unmapped_route'));

        // A non-admin with neither cap is refused everything.
        $this->user_with_cap(null);
        $this->assertWPError($rc->permit('status'));
        $this->assertWPError($rc->permit('framework_publish'));
    }

    public function test_ensure_capabilities_grants_both_caps_to_administrator(): void
    {
        $admin = get_role('administrator');
        $admin->remove_cap(HubSchema::CAP_PUBLISH);
        $admin->remove_cap(HubSchema::CAP_APPROVE);

        HubSchema::ensure_capabilities();

        $admin = get_role('administrator');
        $this->assertTrue($admin->has_cap(HubSchema::CAP_PUBLISH));
        $this->assertTrue($admin->has_cap(HubSchema::CAP_APPROVE));
    }
}
