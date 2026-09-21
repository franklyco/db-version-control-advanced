<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * Immutable received observations. Database uniqueness on
 * (environment, epoch, event_id) and (environment, epoch, sequence) is the
 * only replay/duplicate authority. Each accepted row carries a durable
 * `routing_state=pending` marker so delivery generation (M2 step 2) can
 * never be lost between receipt and routing.
 */
final class EventStore
{
    public const ROUTING_PENDING = 'pending';

    /**
     * @param array<string, mixed> $row
     * @return string inserted|duplicate_event|duplicate_sequence|error
     */
    public function insert(array $row)
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors();
        $inserted = $wpdb->insert(
            Schema::table('events'),
            [
                'environment_id' => (string) $row['environment_id'],
                'installation_epoch' => (string) $row['installation_epoch'],
                'event_id' => (string) $row['event_id'],
                'source_sequence' => (int) $row['source_sequence'],
                'domain' => (string) $row['domain'],
                'instance_uid' => (string) $row['instance_uid'],
                'profile' => (string) $row['profile'],
                'body_digest' => (string) $row['body_digest'],
                'immutable_body' => (string) $row['immutable_body'],
                'routing_state' => self::ROUTING_PENDING,
                'batch_id' => substr((string) ($row['batch_id'] ?? ''), 0, 128),
                'received_at' => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        $wpdb->suppress_errors($suppress);

        if ($inserted === 1) {
            return 'inserted';
        }
        if ($this->find((string) $row['environment_id'], (string) $row['installation_epoch'], (string) $row['event_id']) !== null) {
            return 'duplicate_event';
        }
        if ($this->find_by_sequence((string) $row['environment_id'], (string) $row['installation_epoch'], (int) $row['source_sequence']) !== null) {
            return 'duplicate_sequence';
        }

        return 'error';
    }

    public const ROUTING_ROUTED = 'routed';
    public const ROUTING_UNROUTABLE = 'unroutable';

    /**
     * Pending routing jobs in receipt order.
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function pending_routing($limit)
    {
        global $wpdb;

        $table = Schema::table('events');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE routing_state = %s ORDER BY event_row_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            self::ROUTING_PENDING,
            max(1, (int) $limit)
        ), ARRAY_A);

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * Conditional transition out of `pending`.
     *
     * @param int    $event_row_id
     * @param string $state
     * @param string $policy_revision
     * @return bool
     */
    public function mark_routed($event_row_id, $state, $policy_revision)
    {
        global $wpdb;

        $table = Schema::table('events');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET routing_state = %s, routing_policy_revision = %s WHERE event_row_id = %d AND routing_state = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $state,
            (string) $policy_revision,
            (int) $event_row_id,
            self::ROUTING_PENDING
        ));

        return $updated === 1;
    }

    /**
     * @param string $environment_id
     * @param string $epoch
     * @param string $event_id
     * @return array<string, mixed>|null
     */
    public function find($environment_id, $epoch, $event_id)
    {
        global $wpdb;

        $table = Schema::table('events');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE environment_id = %s AND installation_epoch = %s AND event_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $environment_id,
            (string) $epoch,
            (string) $event_id
        ), ARRAY_A);

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @param string $environment_id
     * @param string $epoch
     * @param int    $sequence
     * @return array<string, mixed>|null
     */
    public function find_by_sequence($environment_id, $epoch, $sequence)
    {
        global $wpdb;

        $table = Schema::table('events');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE environment_id = %s AND installation_epoch = %s AND source_sequence = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $environment_id,
            (string) $epoch,
            (int) $sequence
        ), ARRAY_A);

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @param string|null $environment_id
     * @param int         $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($environment_id = null, $limit = 100, $instance_uid = null)
    {
        global $wpdb;

        $table = Schema::table('events');
        $where = [];
        $params = [];
        if ($environment_id !== null && $environment_id !== '') {
            $where[] = 'environment_id = %s';
            $params[] = (string) $environment_id;
        }
        if ($instance_uid !== null && $instance_uid !== '') {
            $where[] = 'instance_uid = %s';
            $params[] = (string) $instance_uid;
        }
        $params[] = max(1, (int) $limit);
        $order = $environment_id !== null && $environment_id !== '' ? 'installation_epoch, source_sequence' : 'event_row_id';
        $sql = "SELECT * FROM {$table}" . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY {$order} LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * Per environment/epoch: received count, highest sequence and the gap
     * (sequences never received). A gap is a reconciliation signal, not
     * proof of loss; the source may have retried out of order.
     *
     * @return array<int, array{environment_id: string, installation_epoch: string, received: int, max_sequence: int, gaps: int, pending_routing: int}>
     */
    public function sequence_summary()
    {
        global $wpdb;

        $table = Schema::table('events');
        $rows = $wpdb->get_results(
            "SELECT environment_id, installation_epoch, COUNT(*) AS received, MAX(source_sequence) AS max_sequence,
                    SUM(CASE WHEN routing_state = 'pending' THEN 1 ELSE 0 END) AS pending_routing
             FROM {$table} GROUP BY environment_id, installation_epoch", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );
        $summary = [];
        foreach ((array) $rows as $row) {
            $summary[] = [
                'environment_id' => (string) $row['environment_id'],
                'installation_epoch' => (string) $row['installation_epoch'],
                'received' => (int) $row['received'],
                'max_sequence' => (int) $row['max_sequence'],
                'gaps' => max(0, (int) $row['max_sequence'] - (int) $row['received']),
                'pending_routing' => (int) $row['pending_routing'],
            ];
        }

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    public function counts()
    {
        global $wpdb;

        $table = Schema::table('events');
        $rows = $wpdb->get_results("SELECT routing_state, COUNT(*) AS total FROM {$table} GROUP BY routing_state", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $counts = ['total' => 0];
        foreach ((array) $rows as $row) {
            $counts[(string) $row['routing_state']] = (int) $row['total'];
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
        $row['event_row_id'] = (int) $row['event_row_id'];
        $row['source_sequence'] = (int) $row['source_sequence'];
        $decoded = json_decode((string) $row['immutable_body'], true);
        $row['body'] = is_array($decoded) ? $decoded : null;

        return $row;
    }
}
