<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * Approvals bind one received prepare receipt to execution: release digest,
 * receipt digest, target environment + epoch and policy revision. An
 * approval is consumed by exactly one execution receipt, expires with the
 * prepare receipt it was made for, and can be revoked while unconsumed.
 */
final class ApprovalStore
{
    public const STATE_APPROVED = 'approved';
    public const STATE_CONSUMED = 'consumed';
    public const STATE_REVOKED = 'revoked';

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function create(array $data)
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors();
        $inserted = $wpdb->insert(
            Schema::table('approvals'),
            [
                'approval_uid' => (string) $data['approval_uid'],
                'operation_id' => (string) $data['operation_id'],
                'release_id' => (int) $data['release_id'],
                'release_uid' => (string) $data['release_uid'],
                'release_digest' => (string) $data['release_digest'],
                'receipt_digest' => (string) $data['receipt_digest'],
                'target_environment_id' => (string) $data['target_environment_id'],
                'target_epoch' => (string) $data['target_epoch'],
                'policy_revision' => (string) ($data['policy_revision'] ?? ''),
                'state' => self::STATE_APPROVED,
                'note' => mb_substr((string) ($data['note'] ?? ''), 0, 191),
                'approved_by' => (int) ($data['approved_by'] ?? 0),
                'approved_at' => current_time('mysql', true),
                'expires_at' => (string) $data['expires_at'],
            ],
            ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );
        $wpdb->suppress_errors($suppress);
        if ($inserted !== 1) {
            return null;
        }

        return $this->get((int) $wpdb->insert_id);
    }

    /**
     * @param int $approval_id
     * @return array<string, mixed>|null
     */
    public function get($approval_id)
    {
        global $wpdb;

        $table = Schema::table('approvals');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE approval_id = %d", (int) $approval_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @param string $approval_uid
     * @return array<string, mixed>|null
     */
    public function find($approval_uid)
    {
        global $wpdb;

        $table = Schema::table('approvals');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE approval_uid = %s", (string) $approval_uid), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @param string $operation_id
     * @return array<string, mixed>|null
     */
    public function find_by_operation($operation_id)
    {
        global $wpdb;

        $table = Schema::table('approvals');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE operation_id = %s", (string) $operation_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * Unconsumed, unexpired approvals for one target.
     *
     * @param string $target_environment_id
     * @param int    $limit
     * @return array<int, array<string, mixed>>
     */
    public function open_for_target($target_environment_id, $limit = 10)
    {
        global $wpdb;

        $table = Schema::table('approvals');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE target_environment_id = %s AND state = %s AND expires_at > %s ORDER BY approval_id LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $target_environment_id,
            self::STATE_APPROVED,
            current_time('mysql', true),
            max(1, (int) $limit)
        ), ARRAY_A);

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * @param int                  $approval_id
     * @param array<string, mixed> $receipt
     * @param string               $digest
     * @return bool
     */
    public function consume($approval_id, array $receipt, $digest)
    {
        global $wpdb;

        return $wpdb->update(
            Schema::table('approvals'),
            [
                'state' => self::STATE_CONSUMED,
                'execution_outcome' => (string) ($receipt['outcome'] ?? ''),
                'execution_receipt' => wp_json_encode($receipt),
                'execution_digest' => (string) $digest,
                'executed_at' => current_time('mysql', true),
            ],
            ['approval_id' => (int) $approval_id, 'state' => self::STATE_APPROVED],
            ['%s', '%s', '%s', '%s', '%s'],
            ['%d', '%s']
        ) === 1;
    }

    /**
     * @param int    $approval_id
     * @param string $reason
     * @return bool
     */
    public function revoke($approval_id, $reason)
    {
        global $wpdb;

        return $wpdb->update(
            Schema::table('approvals'),
            ['state' => self::STATE_REVOKED, 'execution_outcome' => (string) $reason],
            ['approval_id' => (int) $approval_id, 'state' => self::STATE_APPROVED],
            ['%s', '%s'],
            ['%d', '%s']
        ) === 1;
    }

    /**
     * @param string|null $target_environment_id
     * @param int         $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($target_environment_id = null, $limit = 200)
    {
        global $wpdb;

        $table = Schema::table('approvals');
        if ($target_environment_id !== null && $target_environment_id !== '') {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE target_environment_id = %s ORDER BY approval_id DESC LIMIT %d", (string) $target_environment_id, max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY approval_id DESC LIMIT %d", max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * @return array<string, int>
     */
    public function counts()
    {
        global $wpdb;

        $table = Schema::table('approvals');
        $rows = $wpdb->get_results("SELECT state, COUNT(*) AS total FROM {$table} GROUP BY state", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $counts = ['total' => 0];
        foreach ((array) $rows as $row) {
            $counts[(string) $row['state']] = (int) $row['total'];
            $counts['total'] += (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row)
    {
        $row['approval_id'] = (int) $row['approval_id'];
        $row['release_id'] = (int) $row['release_id'];
        $row['approved_by'] = (int) $row['approved_by'];
        $receipt = json_decode((string) ($row['execution_receipt'] ?? ''), true);
        $row['execution_receipt'] = is_array($receipt) ? $receipt : null;

        return $row;
    }
}
