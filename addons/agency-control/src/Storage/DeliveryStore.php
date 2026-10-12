<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * Pending deliveries per target environment. `delivery_id` doubles as the
 * per-target inbox cursor (monotonic, hub-assigned, distinct from the source
 * sequence). Deliveries survive offline targets and are re-authorized at
 * retrieval; a disabled subscription or target cancels them then.
 */
final class DeliveryStore
{
    public const STATE_PENDING = 'pending';
    public const STATE_ACKED = 'acked';
    public const STATE_CANCELLED = 'cancelled';

    /**
     * @param array<string, mixed> $data event_row_id, source_environment_id, target_environment_id, domain, policy_revision
     * @return string created|exists|error
     */
    public function create(array $data)
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors();
        $inserted = $wpdb->insert(
            Schema::table('deliveries'),
            [
                'event_row_id' => (int) $data['event_row_id'],
                'source_environment_id' => (string) $data['source_environment_id'],
                'target_environment_id' => (string) $data['target_environment_id'],
                'domain' => (string) $data['domain'],
                'policy_revision' => (string) $data['policy_revision'],
                'state' => self::STATE_PENDING,
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        $wpdb->suppress_errors($suppress);
        if ($inserted === 1) {
            return 'created';
        }

        return stripos((string) $wpdb->last_error, 'duplicate') !== false ? 'exists' : 'error';
    }

    /**
     * Pending deliveries for one target after a cursor, joined with the
     * immutable event. Authorization is rechecked by the caller.
     *
     * @param string $target_environment_id
     * @param int    $after_cursor
     * @param int    $limit
     * @return array<int, array<string, mixed>>
     */
    public function pending_for_target($target_environment_id, $after_cursor, $limit)
    {
        global $wpdb;

        $deliveries = Schema::table('deliveries');
        $events = Schema::table('events');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.delivery_id, d.event_row_id, d.source_environment_id, d.target_environment_id, d.domain, d.policy_revision, d.state, d.created_at,
                    e.installation_epoch, e.event_id, e.source_sequence, e.instance_uid, e.profile, e.body_digest, e.immutable_body
             FROM {$deliveries} d
             INNER JOIN {$events} e ON e.event_row_id = d.event_row_id
             WHERE d.target_environment_id = %s AND d.state = %s AND d.delivery_id > %d
             ORDER BY d.delivery_id ASC
             LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $target_environment_id,
            self::STATE_PENDING,
            (int) $after_cursor,
            max(1, (int) $limit)
        ), ARRAY_A);

        return array_map(static function ($row) {
            $row['delivery_id'] = (int) $row['delivery_id'];
            $row['event_row_id'] = (int) $row['event_row_id'];
            $row['source_sequence'] = (int) $row['source_sequence'];
            $decoded = json_decode((string) $row['immutable_body'], true);
            $row['body'] = is_array($decoded) ? $decoded : null;
            return $row;
        }, is_array($rows) ? $rows : []);
    }

    /**
     * Acknowledge only deliveries that belong to this target and are pending.
     *
     * @param string          $target_environment_id
     * @param array<int, int> $delivery_ids
     * @return array<int, array{delivery_id: int, outcome: string}>
     */
    public function ack($target_environment_id, array $delivery_ids)
    {
        global $wpdb;

        $table = Schema::table('deliveries');
        $now = current_time('mysql', true);
        $outcomes = [];
        foreach ($delivery_ids as $delivery_id) {
            $delivery_id = (int) $delivery_id;
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET state = %s, acked_at = %s WHERE delivery_id = %d AND target_environment_id = %s AND state = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                self::STATE_ACKED,
                $now,
                $delivery_id,
                (string) $target_environment_id,
                self::STATE_PENDING
            ));
            if ($updated === 1) {
                $outcomes[] = ['delivery_id' => $delivery_id, 'outcome' => 'acked'];
                continue;
            }
            $state = $wpdb->get_var($wpdb->prepare(
                "SELECT state FROM {$table} WHERE delivery_id = %d AND target_environment_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $delivery_id,
                (string) $target_environment_id
            ));
            $outcomes[] = ['delivery_id' => $delivery_id, 'outcome' => $state === null ? 'unknown' : 'already_' . (string) $state];
        }

        return $outcomes;
    }

    /**
     * @param int    $delivery_id
     * @param string $reason
     * @return bool
     */
    public function cancel($delivery_id, $reason)
    {
        global $wpdb;

        $table = Schema::table('deliveries');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET state = %s, cancelled_reason = %s WHERE delivery_id = %d AND state = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            self::STATE_CANCELLED,
            substr((string) $reason, 0, 128),
            (int) $delivery_id,
            self::STATE_PENDING
        ));

        return $updated === 1;
    }

    /**
     * @param string|null $target_environment_id
     * @param int         $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($target_environment_id = null, $limit = 200)
    {
        global $wpdb;

        $table = Schema::table('deliveries');
        if ($target_environment_id !== null && $target_environment_id !== '') {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE target_environment_id = %s ORDER BY delivery_id LIMIT %d", (string) $target_environment_id, max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY delivery_id LIMIT %d", max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        return array_map(static function ($row) {
            $row['delivery_id'] = (int) $row['delivery_id'];
            $row['event_row_id'] = (int) $row['event_row_id'];
            return $row;
        }, is_array($rows) ? $rows : []);
    }

    /**
     * @return array<string, array<string, int>> Keyed by target environment: pending/acked/cancelled counts and oldest pending age.
     */
    public function summary()
    {
        global $wpdb;

        $table = Schema::table('deliveries');
        $rows = $wpdb->get_results(
            "SELECT target_environment_id, state, COUNT(*) AS total, MIN(created_at) AS oldest
             FROM {$table} GROUP BY target_environment_id, state", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );
        $summary = [];
        foreach ((array) $rows as $row) {
            $target = (string) $row['target_environment_id'];
            if (! isset($summary[$target])) {
                $summary[$target] = ['pending' => 0, 'acked' => 0, 'cancelled' => 0, 'oldest_pending_age' => null];
            }
            $summary[$target][(string) $row['state']] = (int) $row['total'];
            if ((string) $row['state'] === self::STATE_PENDING && ! empty($row['oldest'])) {
                $summary[$target]['oldest_pending_age'] = max(0, time() - (int) strtotime((string) $row['oldest'] . ' UTC'));
            }
        }

        return $summary;
    }
}
