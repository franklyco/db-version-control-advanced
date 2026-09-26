<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * One parity sync policy per source→target pair (M8). Additive store; a row is
 * created only by an explicit operator action. JSON columns (scope/include/
 * exclude) are decoded to arrays on read.
 */
final class SyncPolicyStore
{
    public const MODES = ['manual', 'assisted', 'auto'];
    public const CONFLICT_POLICIES = ['hold', 'source_wins', 'skip'];
    public const DIRECTIONS = ['push'];

    /**
     * Insert or update the policy for a pair (unique on source+target).
     *
     * @param array<string, mixed> $policy
     * @return array<string, mixed>|null The stored row.
     */
    public function upsert(array $policy)
    {
        global $wpdb;
        $table = Schema::table('sync_policies');
        $now = current_time('mysql', 1);
        $source = (string) ($policy['source_environment_id'] ?? '');
        $target = (string) ($policy['target_environment_id'] ?? '');

        $data = [
            'agency_id' => (string) ($policy['agency_id'] ?? ''),
            'client_id' => (string) ($policy['client_id'] ?? ''),
            'source_environment_id' => $source,
            'target_environment_id' => $target,
            'direction' => (string) ($policy['direction'] ?? 'push'),
            'mode' => (string) ($policy['mode'] ?? 'manual'),
            'scope_domains' => wp_json_encode(array_values(array_map('strval', (array) ($policy['scope_domains'] ?? [])))),
            'include_uids' => wp_json_encode(array_values(array_map('strval', (array) ($policy['include_uids'] ?? [])))),
            'exclude_uids' => wp_json_encode(array_values(array_map('strval', (array) ($policy['exclude_uids'] ?? [])))),
            'conflict_policy' => (string) ($policy['conflict_policy'] ?? 'hold'),
            'create_new' => ! empty($policy['create_new']) ? 1 : 0,
            'propagate_deletions' => ! empty($policy['propagate_deletions']) ? 1 : 0,
            'max_objects' => max(1, (int) ($policy['max_objects'] ?? 25)),
            'cadence' => (string) ($policy['cadence'] ?? 'manual'),
            'enabled' => array_key_exists('enabled', $policy) ? (! empty($policy['enabled']) ? 1 : 0) : 1,
            'note' => (string) ($policy['note'] ?? ''),
            'updated_at' => $now,
        ];

        $existing = $this->find($source, $target);
        if ($existing !== null) {
            $wpdb->update($table, $data, ['sync_policy_id' => (int) $existing['sync_policy_id']]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        } else {
            $data['created_by'] = get_current_user_id() ?: null;
            $data['created_at'] = $now;
            $wpdb->insert($table, $data); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        }

        return $this->find($source, $target);
    }

    /**
     * @param string $source
     * @param string $target
     * @return array<string, mixed>|null
     */
    public function find($source, $target)
    {
        global $wpdb;
        $table = Schema::table('sync_policies');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE source_environment_id = %s AND target_environment_id = %s", (string) $source, (string) $target), ARRAY_A); // phpcs:ignore WordPress.DB

        return is_array($row) ? $this->row_out($row) : null;
    }

    /**
     * @param string|null $agency
     * @param string|null $client
     * @param int         $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($agency = null, $client = null, $limit = 50)
    {
        global $wpdb;
        $table = Schema::table('sync_policies');
        $limit = max(1, min(500, (int) $limit));
        $where = [];
        $args = [];
        if ($agency !== null && $agency !== '') {
            $where[] = 'agency_id = %s';
            $args[] = (string) $agency;
        }
        if ($client !== null && $client !== '') {
            $where[] = 'client_id = %s';
            $args[] = (string) $client;
        }
        $clause = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));
        $sql = "SELECT * FROM {$table} {$clause} ORDER BY sync_policy_id DESC LIMIT %d";
        $args[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A); // phpcs:ignore WordPress.DB

        return array_map([$this, 'row_out'], is_array($rows) ? $rows : []);
    }

    /**
     * @param string $source
     * @param string $target
     * @return bool
     */
    public function delete($source, $target)
    {
        global $wpdb;
        $table = Schema::table('sync_policies');

        return (bool) $wpdb->delete($table, ['source_environment_id' => (string) $source, 'target_environment_id' => (string) $target]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function row_out(array $row)
    {
        $decode = static function ($value) {
            $decoded = json_decode((string) $value, true);

            return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
        };

        return [
            'sync_policy_id' => (int) $row['sync_policy_id'],
            'agency_id' => (string) $row['agency_id'],
            'client_id' => (string) $row['client_id'],
            'source_environment_id' => (string) $row['source_environment_id'],
            'target_environment_id' => (string) $row['target_environment_id'],
            'direction' => (string) $row['direction'],
            'mode' => (string) $row['mode'],
            'scope_domains' => $decode($row['scope_domains'] ?? '[]'),
            'include_uids' => $decode($row['include_uids'] ?? '[]'),
            'exclude_uids' => $decode($row['exclude_uids'] ?? '[]'),
            'conflict_policy' => (string) $row['conflict_policy'],
            'create_new' => (int) $row['create_new'] === 1,
            'propagate_deletions' => (int) $row['propagate_deletions'] === 1,
            'max_objects' => (int) $row['max_objects'],
            'cadence' => (string) $row['cadence'],
            'enabled' => (int) $row['enabled'] === 1,
            'note' => (string) $row['note'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
