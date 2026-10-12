<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * Per-target prepare requests and the receipts targets return. A request is
 * hub-side intent for one sealed release and one target; the target
 * connector retrieves it, runs its dry-run and posts the receipt, which the
 * hub stores immutably with its digest. A receipt is never permission to
 * write, and expires.
 */
final class PreparationStore
{
    public const STATE_REQUESTED = 'requested';
    public const STATE_RECEIVED = 'received';
    public const STATE_CANCELLED = 'cancelled';

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function request(array $data)
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            Schema::table('preparations'),
            [
                'operation_id' => (string) $data['operation_id'],
                'release_id' => (int) $data['release_id'],
                'release_uid' => (string) $data['release_uid'],
                'target_environment_id' => (string) $data['target_environment_id'],
                'target_epoch' => (string) $data['target_epoch'],
                'state' => self::STATE_REQUESTED,
                'requested_by' => (int) ($data['requested_by'] ?? 0),
                'requested_at' => current_time('mysql', true),
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s']
        );
        if ($inserted !== 1) {
            return null;
        }

        return $this->get((int) $wpdb->insert_id);
    }

    /**
     * @param int $preparation_id
     * @return array<string, mixed>|null
     */
    public function get($preparation_id)
    {
        global $wpdb;

        $table = Schema::table('preparations');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE preparation_id = %d", (int) $preparation_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @param string $operation_id
     * @return array<string, mixed>|null
     */
    public function find($operation_id)
    {
        global $wpdb;

        $table = Schema::table('preparations');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE operation_id = %s", (string) $operation_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @param string $target_environment_id
     * @param int    $limit
     * @return array<int, array<string, mixed>>
     */
    public function requested_for_target($target_environment_id, $limit = 20)
    {
        global $wpdb;

        $table = Schema::table('preparations');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE target_environment_id = %s AND state = %s ORDER BY preparation_id LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $target_environment_id,
            self::STATE_REQUESTED,
            max(1, (int) $limit)
        ), ARRAY_A);

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * Store the target's receipt exactly once.
     *
     * @param int                  $preparation_id
     * @param array<string, mixed> $receipt
     * @param string               $digest
     * @param array<string, mixed> $hub_notes
     * @return bool
     */
    public function record_receipt($preparation_id, array $receipt, $digest, array $hub_notes)
    {
        global $wpdb;

        $counts = is_array($receipt['counts'] ?? null) ? $receipt['counts'] : [];
        $updated = $wpdb->update(
            Schema::table('preparations'),
            [
                'state' => self::STATE_RECEIVED,
                'outcome' => (string) ($receipt['outcome'] ?? ''),
                'items_ready' => (int) ($counts['ready'] ?? 0),
                'items_noop' => (int) ($counts['noop'] ?? 0),
                'items_blocked' => (int) ($counts['blocked'] ?? 0),
                'receipt' => wp_json_encode($receipt),
                'receipt_digest' => (string) $digest,
                'hub_notes' => wp_json_encode($hub_notes),
                'received_at' => current_time('mysql', true),
                'expires_at' => isset($receipt['expires_at']) ? gmdate('Y-m-d H:i:s', (int) strtotime((string) $receipt['expires_at'])) : null,
            ],
            ['preparation_id' => (int) $preparation_id, 'state' => self::STATE_REQUESTED],
            ['%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s'],
            ['%d', '%s']
        );

        return $updated === 1;
    }

    /**
     * @param int    $preparation_id
     * @param string $reason
     * @return bool
     */
    public function cancel($preparation_id, $reason)
    {
        global $wpdb;

        return $wpdb->update(
            Schema::table('preparations'),
            ['state' => self::STATE_CANCELLED, 'outcome' => (string) $reason],
            ['preparation_id' => (int) $preparation_id, 'state' => self::STATE_REQUESTED],
            ['%s', '%s'],
            ['%d', '%s']
        ) === 1;
    }

    /**
     * @param string|null $release_uid
     * @param string|null $target_environment_id
     * @param int         $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($release_uid = null, $target_environment_id = null, $limit = 200)
    {
        global $wpdb;

        $table = Schema::table('preparations');
        $where = [];
        $values = [];
        if ($release_uid !== null && $release_uid !== '') {
            $where[] = 'release_uid = %s';
            $values[] = (string) $release_uid;
        }
        if ($target_environment_id !== null && $target_environment_id !== '') {
            $where[] = 'target_environment_id = %s';
            $values[] = (string) $target_environment_id;
        }
        $values[] = max(1, (int) $limit);
        $sql = "SELECT * FROM {$table}" . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY preparation_id DESC LIMIT %d';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * @return array<string, int>
     */
    public function counts()
    {
        global $wpdb;

        $table = Schema::table('preparations');
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
        $row['preparation_id'] = (int) $row['preparation_id'];
        $row['release_id'] = (int) $row['release_id'];
        foreach (['items_ready', 'items_noop', 'items_blocked', 'requested_by'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $receipt = json_decode((string) ($row['receipt'] ?? ''), true);
        $row['receipt'] = is_array($receipt) ? $receipt : null;
        $notes = json_decode((string) ($row['hub_notes'] ?? ''), true);
        $row['hub_notes'] = is_array($notes) ? $notes : null;

        return $row;
    }
}
