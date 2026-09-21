<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * Latest projection per (environment, epoch, object, profile). Updates are
 * guarded by the object's own sequence, so event 12 for class X arriving
 * before event 11 for class Y updates Y without regressing X.
 */
final class ProjectionStore
{
    /**
     * @param array<string, mixed> $data
     * @return string inserted|updated|stale
     */
    public function upsert(array $data)
    {
        global $wpdb;

        $table = Schema::table('projections');
        $now = current_time('mysql', true);
        $keys = [
            (string) $data['environment_id'], (string) $data['installation_epoch'], (string) $data['domain'],
            (string) $data['instance_uid'], (string) $data['profile'],
        ];

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET semantic_hash = %s, object_exists = %d, snapshot_complete = %d, observed_sequence = %d, observed_at = %s, event_id = %s, updated_at = %s
             WHERE environment_id = %s AND installation_epoch = %s AND domain = %s AND instance_uid = %s AND profile = %s
               AND observed_sequence < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $data['semantic_hash'],
            ! empty($data['object_exists']) ? 1 : 0,
            ! empty($data['snapshot_complete']) ? 1 : 0,
            (int) $data['observed_sequence'],
            (string) $data['observed_at'],
            (string) $data['event_id'],
            $now,
            $keys[0], $keys[1], $keys[2], $keys[3], $keys[4],
            (int) $data['observed_sequence']
        ));
        if ($updated === 1) {
            return 'updated';
        }
        if ($this->get($keys[0], $keys[1], $keys[2], $keys[3], $keys[4]) !== null) {
            return 'stale';
        }

        $suppress = $wpdb->suppress_errors();
        $inserted = $wpdb->insert(
            $table,
            [
                'environment_id' => $keys[0],
                'installation_epoch' => $keys[1],
                'domain' => $keys[2],
                'instance_uid' => $keys[3],
                'profile' => $keys[4],
                'semantic_hash' => (string) $data['semantic_hash'],
                'object_exists' => ! empty($data['object_exists']) ? 1 : 0,
                'snapshot_complete' => ! empty($data['snapshot_complete']) ? 1 : 0,
                'observed_sequence' => (int) $data['observed_sequence'],
                'observed_at' => (string) $data['observed_at'],
                'event_id' => (string) $data['event_id'],
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s']
        );
        $wpdb->suppress_errors($suppress);

        return $inserted === 1 ? 'inserted' : 'stale';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get($environment_id, $epoch, $domain, $instance_uid, $profile)
    {
        global $wpdb;

        $table = Schema::table('projections');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE environment_id = %s AND installation_epoch = %s AND domain = %s AND instance_uid = %s AND profile = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $environment_id, (string) $epoch, (string) $domain, (string) $instance_uid, (string) $profile
        ), ARRAY_A);

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @param string|null $environment_id
     * @param int         $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($environment_id = null, $limit = 200, $instance_uid = null)
    {
        global $wpdb;

        $table = Schema::table('projections');
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
        $sql = "SELECT * FROM {$table}" . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY environment_id, domain, profile, instance_uid LIMIT %d';
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row)
    {
        $row['object_exists'] = (int) $row['object_exists'];
        $row['snapshot_complete'] = (int) $row['snapshot_complete'];
        $row['observed_sequence'] = (int) $row['observed_sequence'];

        return $row;
    }
}
