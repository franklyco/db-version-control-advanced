<?php

namespace Dbvc\Connected\Storage;

/**
 * Durable local inbox of deliveries retrieved from the hub. Rows are stored
 * before they are acknowledged; the hub delivery id and the source event
 * identity are both unique, so re-polls and retries never duplicate.
 */
final class InboxStore
{
    /**
     * @param array<string, mixed> $item Inbox item as returned by the hub.
     * @param string               $hub_url
     * @return string inserted|duplicate|error
     */
    public function insert(array $item, $hub_url)
    {
        global $wpdb;

        $body = isset($item['event']) && is_array($item['event']) ? wp_json_encode($item['event']) : '';
        if (! is_string($body) || $body === '') {
            return 'error';
        }
        $suppress = $wpdb->suppress_errors();
        $inserted = $wpdb->insert(
            Schema::table('inbox'),
            [
                'hub_url' => (string) $hub_url,
                'delivery_id' => (int) $item['delivery_id'],
                'source_environment_id' => (string) $item['source_environment_id'],
                'installation_epoch' => (string) $item['installation_epoch'],
                'event_id' => (string) $item['event_id'],
                'source_sequence' => (int) $item['sequence'],
                'domain' => (string) $item['domain'],
                'instance_uid' => (string) $item['instance_uid'],
                'profile' => (string) $item['profile'],
                'body_digest' => (string) $item['body_digest'],
                'policy_revision' => (string) ($item['policy_revision'] ?? ''),
                'immutable_body' => $body,
                'received_at' => current_time('mysql', true),
            ],
            ['%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        $wpdb->suppress_errors($suppress);
        if ($inserted === 1) {
            return 'inserted';
        }

        return stripos((string) $wpdb->last_error, 'duplicate') !== false ? 'duplicate' : 'error';
    }

    /**
     * @param array<int, int> $delivery_ids
     * @return int
     */
    public function mark_acked(array $delivery_ids)
    {
        global $wpdb;

        if ($delivery_ids === []) {
            return 0;
        }
        $table = Schema::table('inbox');
        $placeholders = implode(',', array_fill(0, count($delivery_ids), '%d'));
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET acked_at = %s WHERE acked_at IS NULL AND delivery_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            array_merge([current_time('mysql', true)], array_map('intval', $delivery_ids))
        ));

        return (int) $updated;
    }

    /**
     * Deliveries stored locally but not yet acknowledged to the hub (a crash between store and ack).
     *
     * @param int $limit
     * @return array<int, int>
     */
    public function unacked_delivery_ids($limit)
    {
        global $wpdb;

        $table = Schema::table('inbox');
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT delivery_id FROM {$table} WHERE acked_at IS NULL ORDER BY delivery_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            max(1, (int) $limit)
        ));

        return array_map('intval', (array) $ids);
    }

    /**
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($limit = 100)
    {
        global $wpdb;

        $table = Schema::table('inbox');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY delivery_id ASC LIMIT %d", max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array_map(static function ($row) {
            $row['inbox_id'] = (int) $row['inbox_id'];
            $row['delivery_id'] = (int) $row['delivery_id'];
            $row['source_sequence'] = (int) $row['source_sequence'];
            $decoded = json_decode((string) $row['immutable_body'], true);
            $row['body'] = is_array($decoded) ? $decoded : null;
            return $row;
        }, is_array($rows) ? $rows : []);
    }

    /**
     * @return array{total: int, unacked: int, sources: int}
     */
    public function counts()
    {
        global $wpdb;

        $table = Schema::table('inbox');
        $row = $wpdb->get_row("SELECT COUNT(*) AS total, SUM(CASE WHEN acked_at IS NULL THEN 1 ELSE 0 END) AS unacked, COUNT(DISTINCT source_environment_id) AS sources FROM {$table}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return [
            'total' => is_array($row) ? (int) $row['total'] : 0,
            'unacked' => is_array($row) ? (int) $row['unacked'] : 0,
            'sources' => is_array($row) ? (int) $row['sources'] : 0,
        ];
    }
}
