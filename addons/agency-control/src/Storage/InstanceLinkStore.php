<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * Operator-declared lineage links between instances on two environments.
 * Instances that share a lineage (same-client clones, portable
 * vf_object_uid) pair by equal instance UID automatically; independently
 * enrolled environments need an explicit link because the hub never
 * receives names or storage keys.
 */
final class InstanceLinkStore
{
    /**
     * @param array<string, mixed> $data
     * @return string created|exists|error
     */
    public function create(array $data)
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors();
        $inserted = $wpdb->insert(
            Schema::table('instance_links'),
            [
                'domain' => (string) $data['domain'],
                'source_environment_id' => (string) $data['source_environment_id'],
                'source_instance_uid' => (string) $data['source_instance_uid'],
                'target_environment_id' => (string) $data['target_environment_id'],
                'target_instance_uid' => (string) $data['target_instance_uid'],
                'created_by' => (int) ($data['created_by'] ?? 0),
                'created_at' => current_time('mysql', true),
                'note' => mb_substr((string) ($data['note'] ?? ''), 0, 191),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );
        $wpdb->suppress_errors($suppress);
        if ($inserted === 1) {
            return 'created';
        }

        return stripos((string) $wpdb->last_error, 'duplicate') !== false ? 'exists' : 'error';
    }

    /**
     * @param int $link_id
     * @return bool
     */
    public function delete($link_id)
    {
        global $wpdb;

        return $wpdb->delete(Schema::table('instance_links'), ['link_id' => (int) $link_id], ['%d']) === 1;
    }

    /**
     * @param string      $source_environment_id
     * @param string      $target_environment_id
     * @param string|null $domain
     * @return array<string, string> "domain|source_instance_uid" => target_instance_uid
     */
    public function map_for_pair($source_environment_id, $target_environment_id, $domain = null)
    {
        global $wpdb;

        $table = Schema::table('instance_links');
        if ($domain !== null && $domain !== '') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT domain, source_instance_uid, target_instance_uid FROM {$table} WHERE source_environment_id = %s AND target_environment_id = %s AND domain = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                (string) $source_environment_id,
                (string) $target_environment_id,
                (string) $domain
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT domain, source_instance_uid, target_instance_uid FROM {$table} WHERE source_environment_id = %s AND target_environment_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                (string) $source_environment_id,
                (string) $target_environment_id
            ), ARRAY_A);
        }

        $map = [];
        foreach ((array) $rows as $row) {
            $map[$row['domain'] . '|' . $row['source_instance_uid']] = (string) $row['target_instance_uid'];
        }

        return $map;
    }

    /**
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($limit = 200)
    {
        global $wpdb;

        $table = Schema::table('instance_links');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY link_id LIMIT %d", max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array_map(static function ($row) {
            $row['link_id'] = (int) $row['link_id'];
            return $row;
        }, is_array($rows) ? $rows : []);
    }
}
