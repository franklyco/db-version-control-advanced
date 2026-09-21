<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * Approved overrides: an exact approved hash for one instance against one
 * definition, with the policy revision, rationale and approver. A new
 * definition version marks the override `needs_rebase_review`; it never
 * rewrites or removes it, and an override is never promoted automatically.
 */
final class OverrideStore
{
    public const STATE_APPROVED = 'approved';
    public const STATE_NEEDS_REBASE_REVIEW = 'needs_rebase_review';
    public const STATE_DETACHED = 'detached';

    /**
     * @param array<string, mixed> $data
     * @return bool
     */
    public function approve(array $data)
    {
        global $wpdb;

        $table = Schema::table('overrides');
        $now = current_time('mysql', true);
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (agency_id, client_id, environment_id, domain, instance_uid, definition_uid, definition_version, approved_hash, policy_revision, state, rationale, approved_by, approved_at, updated_at)
             VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s)
             ON DUPLICATE KEY UPDATE
                definition_version = VALUES(definition_version), approved_hash = VALUES(approved_hash), policy_revision = VALUES(policy_revision),
                state = VALUES(state), rationale = VALUES(rationale), approved_by = VALUES(approved_by), approved_at = VALUES(approved_at), updated_at = VALUES(updated_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $data['agency_id'],
            (string) $data['client_id'],
            (string) $data['environment_id'],
            (string) $data['domain'],
            (string) $data['instance_uid'],
            (string) $data['definition_uid'],
            (string) ($data['definition_version'] ?? ''),
            (string) $data['approved_hash'],
            (string) ($data['policy_revision'] ?? ''),
            self::STATE_APPROVED,
            (string) ($data['rationale'] ?? ''),
            (int) ($data['approved_by'] ?? 0),
            $now,
            $now
        ));

        return $result !== false;
    }

    /**
     * @param string $environment_id
     * @param string $domain
     * @param string $instance_uid
     * @param string $definition_uid
     * @return array<string, mixed>|null
     */
    public function find($environment_id, $domain, $instance_uid, $definition_uid)
    {
        global $wpdb;

        $table = Schema::table('overrides');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE environment_id = %s AND domain = %s AND instance_uid = %s AND definition_uid = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $environment_id,
            (string) $domain,
            (string) $instance_uid,
            (string) $definition_uid
        ), ARRAY_A);

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @param int    $override_id
     * @param string $state
     * @return bool
     */
    public function set_state($override_id, $state)
    {
        global $wpdb;

        $updated = $wpdb->update(
            Schema::table('overrides'),
            ['state' => (string) $state, 'updated_at' => current_time('mysql', true)],
            ['override_id' => (int) $override_id],
            ['%s', '%s'],
            ['%d']
        );

        return $updated === 1;
    }

    /**
     * A new definition version: every approved override of that definition needs a rebase review.
     *
     * @param string $agency_id
     * @param string $definition_uid
     * @return int
     */
    public function flag_rebase_review($agency_id, $definition_uid)
    {
        global $wpdb;

        $table = Schema::table('overrides');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET state = %s, updated_at = %s WHERE agency_id = %s AND definition_uid = %s AND state = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            self::STATE_NEEDS_REBASE_REVIEW,
            current_time('mysql', true),
            (string) $agency_id,
            (string) $definition_uid,
            self::STATE_APPROVED
        ));

        return (int) $updated;
    }

    /**
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($limit = 200)
    {
        global $wpdb;

        $table = Schema::table('overrides');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY override_id LIMIT %d", max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * @return int
     */
    public function count()
    {
        global $wpdb;

        $table = Schema::table('overrides');

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row)
    {
        $row['override_id'] = (int) $row['override_id'];
        $row['approved_by'] = (int) $row['approved_by'];

        return $row;
    }
}
