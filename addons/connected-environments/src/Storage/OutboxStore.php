<?php

namespace Dbvc\Connected\Storage;

/**
 * Immutable local outbox. Event identity is (installation_epoch, event_id);
 * the body and digest never change across delivery retries. Database
 * uniqueness, not PHP check-then-insert, rejects sequence or event reuse.
 */
final class OutboxStore
{
    public const STATE_PENDING = 'pending';
    public const STATE_DELIVERED = 'delivered';
    public const STATE_REJECTED = 'rejected';
    public const STATE_SUPERSEDED = 'superseded';

    /**
     * @param array<string, mixed> $event
     * @return bool
     */
    public function insert(array $event)
    {
        global $wpdb;

        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(
            Schema::table('outbox'),
            [
                'installation_epoch' => (string) $event['installation_epoch'],
                'event_id' => (string) $event['event_id'],
                'source_sequence' => (int) $event['source_sequence'],
                'domain' => (string) ($event['domain'] ?? ''),
                'instance_uid' => (string) ($event['instance_uid'] ?? ''),
                'body_digest' => (string) $event['body_digest'],
                'immutable_body' => (string) $event['immutable_body'],
                'delivery_state' => self::STATE_PENDING,
                'attempts' => 0,
                'available_at' => $now,
                'created_at' => $now,
            ],
            ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        return $inserted === 1;
    }

    /**
     * Atomically lease due pending events of one epoch in sequence order.
     *
     * @param string $epoch
     * @param int    $limit
     * @param int    $lease_seconds
     * @return array<int, array<string, mixed>> Leased rows (with `lease_token`).
     */
    public function claim_pending($epoch, $limit, $lease_seconds)
    {
        global $wpdb;

        $table = Schema::table('outbox');
        $now_ts = time();
        $now = gmdate('Y-m-d H:i:s', $now_ts);
        $lease_until = gmdate('Y-m-d H:i:s', $now_ts + max(5, (int) $lease_seconds));
        $token = bin2hex(random_bytes(16));

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET lease_token = %s, lease_until = %s, attempts = attempts + 1
             WHERE installation_epoch = %s AND delivery_state = %s AND available_at <= %s
               AND (lease_until IS NULL OR lease_until < %s)
             ORDER BY source_sequence ASC
             LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $token,
            $lease_until,
            (string) $epoch,
            self::STATE_PENDING,
            $now,
            $now,
            max(1, (int) $limit)
        ));
        if (! $updated) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE lease_token = %s ORDER BY source_sequence ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $token
        ), ARRAY_A);

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * Token-checked terminal outcome for one leased event.
     *
     * @param int                  $outbox_id
     * @param string               $lease_token
     * @param string               $state       delivered|rejected
     * @param array<string, mixed> $receipt
     * @return bool
     */
    public function settle($outbox_id, $lease_token, $state, array $receipt)
    {
        global $wpdb;

        $table = Schema::table('outbox');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET delivery_state = %s, receipt_body = %s, lease_token = NULL, lease_until = NULL
             WHERE outbox_id = %d AND lease_token = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $state,
            wp_json_encode($receipt),
            (int) $outbox_id,
            (string) $lease_token
        ));

        return $updated === 1;
    }

    /**
     * Release every event under a lease and defer it (transport failure, hold).
     *
     * @param string $lease_token
     * @param int    $available_at_ts
     * @return int Rows released.
     */
    public function release($lease_token, $available_at_ts)
    {
        global $wpdb;

        $table = Schema::table('outbox');
        $released = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET lease_token = NULL, lease_until = NULL, available_at = %s
             WHERE lease_token = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            gmdate('Y-m-d H:i:s', max(time(), (int) $available_at_ts)),
            (string) $lease_token
        ));

        return (int) $released;
    }

    /**
     * Pending events from a previous epoch are never sent under the new
     * identity; they are retained as history.
     *
     * @param string $current_epoch
     * @return int
     */
    public function supersede_other_epochs($current_epoch)
    {
        global $wpdb;

        $table = Schema::table('outbox');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET delivery_state = %s, lease_token = NULL, lease_until = NULL
             WHERE delivery_state = %s AND installation_epoch <> %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            self::STATE_SUPERSEDED,
            self::STATE_PENDING,
            (string) $current_epoch
        ));

        return (int) $updated;
    }

    /**
     * @param string|null $state
     * @param int         $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($state = null, $limit = 100)
    {
        global $wpdb;

        $table = Schema::table('outbox');
        $limit = max(1, (int) $limit);
        if ($state !== null && $state !== '') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE delivery_state = %s ORDER BY installation_epoch ASC, source_sequence ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $state,
                $limit
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY installation_epoch ASC, source_sequence ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $limit
            ), ARRAY_A);
        }

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * @param string $epoch
     * @param string $event_id
     * @return array<string, mixed>|null
     */
    public function get($epoch, $event_id)
    {
        global $wpdb;

        $table = Schema::table('outbox');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE installation_epoch = %s AND event_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $epoch,
            $event_id
        ), ARRAY_A);

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @return array<string, int> Counts keyed by delivery_state plus `total`.
     */
    public function counts()
    {
        global $wpdb;

        $table = Schema::table('outbox');
        $rows = $wpdb->get_results("SELECT delivery_state, COUNT(*) AS total FROM {$table} GROUP BY delivery_state", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $counts = ['total' => 0];
        foreach ((array) $rows as $row) {
            $counts[(string) $row['delivery_state']] = (int) $row['total'];
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
        $row['source_sequence'] = (int) $row['source_sequence'];
        $row['outbox_id'] = (int) $row['outbox_id'];
        $row['attempts'] = (int) $row['attempts'];
        $decoded = json_decode((string) $row['immutable_body'], true);
        $row['body'] = is_array($decoded) ? $decoded : null;
        $receipt = is_string($row['receipt_body']) && $row['receipt_body'] !== '' ? json_decode($row['receipt_body'], true) : null;
        $row['receipt'] = is_array($receipt) ? $receipt : null;

        return $row;
    }
}
