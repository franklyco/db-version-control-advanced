<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * Per pair/object/profile baselines: the last mutually confirmed hash for a
 * source→target pair. A baseline advances only through an explicit
 * confirmation of current agreement; receiving, routing, delivering or
 * acknowledging an event never touches it.
 */
final class BaselineStore
{
    /**
     * @param string $source_environment_id
     * @param string $target_environment_id
     * @param string|null $domain
     * @return array<string, array<string, mixed>> Keyed by "domain|source_instance_uid|profile".
     */
    public function for_pair($source_environment_id, $target_environment_id, $domain = null)
    {
        global $wpdb;

        $table = Schema::table('baselines');
        if ($domain !== null && $domain !== '') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE source_environment_id = %s AND target_environment_id = %s AND domain = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                (string) $source_environment_id,
                (string) $target_environment_id,
                (string) $domain
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE source_environment_id = %s AND target_environment_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                (string) $source_environment_id,
                (string) $target_environment_id
            ), ARRAY_A);
        }

        $keyed = [];
        foreach ((array) $rows as $row) {
            $keyed[$row['domain'] . '|' . $row['source_instance_uid'] . '|' . $row['profile']] = $this->normalize($row);
        }

        return $keyed;
    }

    /**
     * Explicit writer: record or advance a baseline.
     *
     * @param array<string, mixed> $data
     * @return bool
     */
    public function upsert(array $data)
    {
        global $wpdb;

        $table = Schema::table('baselines');
        $now = current_time('mysql', true);
        $updated = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (source_environment_id, target_environment_id, domain, source_instance_uid, target_instance_uid, profile, baseline_hash, source_sequence, target_sequence, source_epoch, target_epoch, confirmed_by, confirmed_at, note)
             VALUES (%s, %s, %s, %s, %s, %s, %s, %d, %d, %s, %s, %d, %s, %s)
             ON DUPLICATE KEY UPDATE
                target_instance_uid = VALUES(target_instance_uid), baseline_hash = VALUES(baseline_hash),
                source_sequence = VALUES(source_sequence), target_sequence = VALUES(target_sequence),
                source_epoch = VALUES(source_epoch), target_epoch = VALUES(target_epoch),
                confirmed_by = VALUES(confirmed_by), confirmed_at = VALUES(confirmed_at), note = VALUES(note)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $data['source_environment_id'],
            (string) $data['target_environment_id'],
            (string) $data['domain'],
            (string) $data['source_instance_uid'],
            (string) $data['target_instance_uid'],
            (string) $data['profile'],
            (string) $data['baseline_hash'],
            (int) ($data['source_sequence'] ?? 0),
            (int) ($data['target_sequence'] ?? 0),
            (string) ($data['source_epoch'] ?? ''),
            (string) ($data['target_epoch'] ?? ''),
            (int) ($data['confirmed_by'] ?? 0),
            $now,
            mb_substr((string) ($data['note'] ?? ''), 0, 191)
        ));

        return $updated !== false;
    }

    /**
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($limit = 200, $instance_uid = null)
    {
        global $wpdb;

        $table = Schema::table('baselines');
        if ($instance_uid !== null && $instance_uid !== '') {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE source_instance_uid = %s OR target_instance_uid = %s ORDER BY baseline_id LIMIT %d", (string) $instance_uid, (string) $instance_uid, max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY baseline_id LIMIT %d", max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * @return int
     */
    public function count()
    {
        global $wpdb;

        $table = Schema::table('baselines');

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row)
    {
        $row['baseline_id'] = (int) $row['baseline_id'];
        $row['source_sequence'] = (int) $row['source_sequence'];
        $row['target_sequence'] = (int) $row['target_sequence'];

        return $row;
    }
}
