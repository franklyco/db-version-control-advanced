<?php

namespace Dbvc\AgencyControl\Governance;

use Dbvc\AgencyControl\Storage\Schema;

/**
 * Assign the human hub capabilities (M9 slice 7b completion). The 7b role split
 * introduced two hub capabilities — publisher ({@see Schema::CAP_PUBLISH},
 * framework authoring/distribution writes) and approver ({@see Schema::CAP_APPROVE},
 * reviewing/approving/rollback) — enforced at the single choke point
 * {@see \Dbvc\AgencyControl\Admin\RestController::permit()}. {@see
 * Schema::ensure_capabilities()} grants BOTH to the administrator role on boot,
 * but there was no way to delegate JUST one cap to a non-administrator. This
 * service grants/revokes a single cap on one user and lists who holds each, so an
 * operator can hand out publishing or approving without granting full
 * manage_options.
 *
 * Capability assignment is a site-administration action, so every surface that
 * exposes it is gated to full administrators: the operator-run WP-CLI
 * (`wp dbvc agency role-grant|role-revoke|roles`) and the Connected Environments
 * admin page's Roles panel, whose `connected-admin/roles` REST routes require
 * `manage_options` (Phase B). It is never reachable by a publisher or approver
 * delegate — only an administrator can hand out these capabilities.
 */
final class RoleService
{
    /** Operator-facing role names mapped to the stored capability. */
    private const ROLES = [
        'publisher' => Schema::CAP_PUBLISH,
        'publish' => Schema::CAP_PUBLISH,
        'approver' => Schema::CAP_APPROVE,
        'approve' => Schema::CAP_APPROVE,
    ];

    /**
     * Grant one hub capability (publisher|approver) to a user.
     *
     * @param string $user_ref A user ID, login, or email.
     * @param string $role     publisher|approver.
     * @return array<string, mixed>|\WP_Error
     */
    public function grant($user_ref, $role)
    {
        return $this->change($user_ref, $role, true);
    }

    /**
     * Revoke one hub capability (publisher|approver) from a user.
     *
     * @param string $user_ref A user ID, login, or email.
     * @param string $role     publisher|approver.
     * @return array<string, mixed>|\WP_Error
     */
    public function revoke($user_ref, $role)
    {
        return $this->change($user_ref, $role, false);
    }

    /**
     * The non-administrator users who hold each hub capability. Administrators
     * pass every route via manage_options and hold both caps through the role,
     * so they are reported only as a note rather than enumerated as delegates.
     *
     * @param int $limit Max users to scan.
     * @return array<string, mixed>
     */
    public function assignments($limit = 500)
    {
        $publishers = [];
        $approvers = [];
        foreach (get_users(['number' => max(1, (int) $limit)]) as $user) {
            if (! $user instanceof \WP_User || user_can($user, 'manage_options')) {
                continue; // administrators (superset) are not hand-granted delegates.
            }
            if ($user->has_cap(Schema::CAP_PUBLISH)) {
                $publishers[] = $this->user_row($user);
            }
            if ($user->has_cap(Schema::CAP_APPROVE)) {
                $approvers[] = $this->user_row($user);
            }
        }

        return [
            'publishers' => $publishers,
            'approvers' => $approvers,
            'note' => 'Administrators hold both capabilities via manage_options and are not listed.',
        ];
    }

    /**
     * @param string $user_ref
     * @param string $role
     * @param bool   $grant
     * @return array<string, mixed>|\WP_Error
     */
    private function change($user_ref, $role, $grant)
    {
        $cap = $this->cap_for_role((string) $role);
        if (is_wp_error($cap)) {
            return $cap;
        }
        $user = $this->resolve_user((string) $user_ref);
        if (is_wp_error($user)) {
            return $user;
        }
        if ($grant) {
            $user->add_cap($cap);
        } else {
            $user->remove_cap($cap);
        }

        // Re-read so the row reflects the committed capabilities.
        return $this->user_row(new \WP_User((int) $user->ID));
    }

    /**
     * @param string $role
     * @return string|\WP_Error
     */
    private function cap_for_role($role)
    {
        $key = strtolower(trim($role));
        if (isset(self::ROLES[$key])) {
            return self::ROLES[$key];
        }

        return new \WP_Error('dbvc_agency_invalid_role', 'role must be publisher or approver.', ['status' => 400]);
    }

    /**
     * @param string $user_ref
     * @return \WP_User|\WP_Error
     */
    private function resolve_user($user_ref)
    {
        $ref = trim($user_ref);
        if ($ref === '') {
            return new \WP_Error('dbvc_agency_user_not_found', 'A user id, login, or email is required.', ['status' => 400]);
        }
        $user = false;
        if (ctype_digit($ref)) {
            $user = get_user_by('id', (int) $ref);
        }
        if (! $user && is_email($ref)) {
            $user = get_user_by('email', $ref);
        }
        if (! $user) {
            $user = get_user_by('login', $ref);
        }
        if (! $user instanceof \WP_User) {
            return new \WP_Error('dbvc_agency_user_not_found', 'No user matches "' . $ref . '".', ['status' => 404]);
        }

        return $user;
    }

    /**
     * @param \WP_User $user
     * @return array<string, mixed>
     */
    private function user_row(\WP_User $user)
    {
        return [
            'id' => (int) $user->ID,
            'login' => (string) $user->user_login,
            'is_publisher' => (bool) $user->has_cap(Schema::CAP_PUBLISH),
            'is_approver' => (bool) $user->has_cap(Schema::CAP_APPROVE),
            'is_administrator' => (bool) user_can($user, 'manage_options'),
        ];
    }
}
