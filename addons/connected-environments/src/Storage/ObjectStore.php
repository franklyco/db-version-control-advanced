<?php

namespace Dbvc\Connected\Storage;

/**
 * Latest observed projection per (domain, instance_uid, profile).
 */
final class ObjectStore
{
    /**
     * @param string $domain
     * @param string $profile
     * @return array<string, array<string, mixed>> Keyed by instance_uid.
     */
    public function get_for_domain($domain, $profile)
    {
        global $wpdb;

        $table = Schema::table('objects');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE domain = %s AND profile = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $domain,
            $profile
        ), ARRAY_A);

        $keyed = [];
        foreach ((array) $rows as $row) {
            $keyed[(string) $row['instance_uid']] = $this->normalize($row);
        }

        return $keyed;
    }

    /**
     * @param string $domain
     * @param string $instance_uid
     * @param string $profile
     * @return array<string, mixed>|null
     */
    public function get($domain, $instance_uid, $profile)
    {
        global $wpdb;

        $table = Schema::table('objects');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE domain = %s AND instance_uid = %s AND profile = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $domain,
            $instance_uid,
            $profile
        ), ARRAY_A);

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @param string $domain
     * @param string $storage_key
     * @param string $profile
     * @return array<string, mixed>|null
     */
    public function find_by_storage_key($domain, $storage_key, $profile)
    {
        global $wpdb;

        $table = Schema::table('objects');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE domain = %s AND storage_key = %s AND profile = %s ORDER BY observed_sequence DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $domain,
            $storage_key,
            $profile
        ), ARRAY_A);

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * Conditional upsert: an older sequence in the same epoch never overwrites
     * a newer row. Returns `inserted`, `updated` or `stale` (skipped).
     *
     * @param array<string, mixed> $data
     * @return string
     */
    public function upsert(array $data)
    {
        global $wpdb;

        $table = Schema::table('objects');
        $now = current_time('mysql', true);
        $problems = isset($data['problems']) && $data['problems'] !== [] ? wp_json_encode(array_values((array) $data['problems'])) : null;
        $domain = (string) $data['domain'];
        $instance_uid = (string) $data['instance_uid'];
        $profile = (string) $data['profile'];
        $epoch = (string) $data['observed_epoch'];
        $sequence = (int) $data['observed_sequence'];

        $columns = [
            'storage_key' => (string) ($data['storage_key'] ?? ''),
            'display_name' => mb_substr((string) ($data['display_name'] ?? ''), 0, 191),
            'object_exists' => ! empty($data['exists']) ? 1 : 0,
            'snapshot_complete' => ! empty($data['complete']) ? 1 : 0,
            'semantic_hash' => (string) $data['semantic_hash'],
            'storage_fingerprint' => isset($data['storage_fingerprint']) ? (string) $data['storage_fingerprint'] : null,
            'position' => isset($data['position']) ? (int) $data['position'] : null,
            'observed_sequence' => $sequence,
            'observed_epoch' => $epoch,
            'snapshot_body' => isset($data['snapshot_body']) ? (string) $data['snapshot_body'] : null,
            'problems' => $problems,
            'updated_at' => $now,
        ];
        $formats = ['%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s'];

        $set = [];
        $values = [];
        $index = 0;
        foreach ($columns as $column => $value) {
            if ($value === null) {
                $set[] = "{$column} = NULL";
            } else {
                $set[] = "{$column} = {$formats[$index]}";
                $values[] = $value;
            }
            $index++;
        }
        $values[] = $domain;
        $values[] = $instance_uid;
        $values[] = $profile;
        $values[] = $epoch;
        $values[] = $sequence;

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET " . implode(', ', $set) . "
             WHERE domain = %s AND instance_uid = %s AND profile = %s
               AND (observed_epoch <> %s OR observed_sequence < %d)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $values
        ));
        if ($updated === 1) {
            return 'updated';
        }
        if ($this->get($domain, $instance_uid, $profile) !== null) {
            return 'stale';
        }

        $insert_data = array_merge(['domain' => $domain, 'instance_uid' => $instance_uid, 'profile' => $profile], $columns);
        $insert_formats = array_merge(['%s', '%s', '%s'], $formats);
        $suppress = $wpdb->suppress_errors();
        $inserted = $wpdb->insert($table, $insert_data, $insert_formats);
        $wpdb->suppress_errors($suppress);

        return $inserted === 1 ? 'inserted' : 'stale';
    }

    /**
     * @param string|null $domain
     * @param int         $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($domain = null, $limit = 100)
    {
        global $wpdb;

        $table = Schema::table('objects');
        $limit = max(1, (int) $limit);
        if ($domain !== null && $domain !== '') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE domain = %s ORDER BY (storage_key = '') ASC, position ASC, instance_uid ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $domain,
                $limit
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY domain ASC, (storage_key = '') ASC, position ASC, instance_uid ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $limit
            ), ARRAY_A);
        }

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * @return array<string, array{total: int, present: int, absent: int, incomplete: int}>
     */
    public function counts_by_domain()
    {
        global $wpdb;

        $table = Schema::table('objects');
        $rows = $wpdb->get_results(
            "SELECT domain, COUNT(*) AS total,
                    SUM(object_exists) AS present,
                    SUM(1 - object_exists) AS absent,
                    SUM(1 - snapshot_complete) AS incomplete
             FROM {$table} GROUP BY domain", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        $counts = [];
        foreach ((array) $rows as $row) {
            $counts[(string) $row['domain']] = [
                'total' => (int) $row['total'],
                'present' => (int) $row['present'],
                'absent' => (int) $row['absent'],
                'incomplete' => (int) $row['incomplete'],
            ];
        }

        return $counts;
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
        $row['position'] = $row['position'] === null ? null : (int) $row['position'];
        $decoded_problems = is_string($row['problems']) && $row['problems'] !== '' ? json_decode($row['problems'], true) : [];
        $row['problems'] = is_array($decoded_problems) ? $decoded_problems : [];

        return $row;
    }
}
