<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * A fleet rollout and its per-target rows. A rollout is an ordered sequence of
 * cohorts (cohort 0 is the canary) over one sealed release; each target row is
 * one ordinary single-environment operation. The store keeps durable state
 * only — the {@see \Dbvc\AgencyControl\Release\RolloutService} drives the
 * per-target prepare/approve/verify steps and the cohort gate. There is no
 * fleet-wide transaction: a target either advances on its own or the rollout
 * pauses, and later cohorts never start while an earlier one has a failure.
 */
final class RolloutStore
{
    public const STATE_RUNNING = 'running';
    public const STATE_PAUSED = 'paused';
    public const STATE_COMPLETED = 'completed';
    public const STATE_FAILED = 'failed';
    public const STATE_WITHDRAWN = 'withdrawn';

    public const TARGET_PENDING = 'pending';
    public const TARGET_PREPARING = 'preparing';
    public const TARGET_APPROVED = 'approved';
    public const TARGET_SUCCEEDED = 'succeeded';
    public const TARGET_FAILED = 'failed';

    /**
     * Create a rollout and its target rows in one call.
     *
     * @param array<string, mixed>        $data    rollout_uid, agency_id, client_id, release_id, release_uid, source_environment_id, cohort_count, note, created_by
     * @param array<int, array<string, mixed>> $targets Each: cohort, position, target_environment_id
     * @return array<string, mixed>|null
     */
    public function create(array $data, array $targets)
    {
        global $wpdb;

        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(
            Schema::table('rollouts'),
            [
                'rollout_uid' => (string) $data['rollout_uid'],
                'agency_id' => (string) $data['agency_id'],
                'client_id' => (string) $data['client_id'],
                'release_id' => (int) $data['release_id'],
                'release_uid' => (string) $data['release_uid'],
                'source_environment_id' => (string) $data['source_environment_id'],
                'state' => self::STATE_RUNNING,
                'current_cohort' => 0,
                'cohort_count' => (int) $data['cohort_count'],
                'target_count' => count($targets),
                'note' => (string) ($data['note'] ?? ''),
                'created_by' => (int) ($data['created_by'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s']
        );
        if ($inserted !== 1) {
            return null;
        }
        $rollout_id = (int) $wpdb->insert_id;
        foreach ($targets as $target) {
            $wpdb->insert(
                Schema::table('rollout_targets'),
                [
                    'rollout_id' => $rollout_id,
                    'cohort' => (int) $target['cohort'],
                    'position' => (int) $target['position'],
                    'target_environment_id' => (string) $target['target_environment_id'],
                    'state' => self::TARGET_PENDING,
                    'updated_at' => $now,
                ],
                ['%d', '%d', '%d', '%s', '%s', '%s']
            );
        }

        return $this->get($rollout_id);
    }

    /**
     * @param int $rollout_id
     * @return array<string, mixed>|null
     */
    public function get($rollout_id)
    {
        global $wpdb;

        $table = Schema::table('rollouts');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE rollout_id = %d", (int) $rollout_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @param string $rollout_uid
     * @return array<string, mixed>|null
     */
    public function find($rollout_uid)
    {
        global $wpdb;

        $table = Schema::table('rollouts');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE rollout_uid = %s", (string) $rollout_uid), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @param string|null $release_uid
     * @param int         $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($release_uid = null, $limit = 200)
    {
        global $wpdb;

        $table = Schema::table('rollouts');
        if ($release_uid !== null && $release_uid !== '') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE release_uid = %s ORDER BY rollout_id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                (string) $release_uid,
                max(1, (int) $limit)
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY rollout_id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                max(1, (int) $limit)
            ), ARRAY_A);
        }

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * All target rows for a rollout, ordered by cohort then position.
     *
     * @param int $rollout_id
     * @return array<int, array<string, mixed>>
     */
    public function targets($rollout_id)
    {
        global $wpdb;

        $table = Schema::table('rollout_targets');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE rollout_id = %d ORDER BY cohort, position, rollout_target_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (int) $rollout_id
        ), ARRAY_A);

        return array_map([$this, 'normalize_target'], is_array($rows) ? $rows : []);
    }

    /**
     * @param int $rollout_id
     * @param int $cohort
     * @return array<int, array<string, mixed>>
     */
    public function cohort_targets($rollout_id, $cohort)
    {
        global $wpdb;

        $table = Schema::table('rollout_targets');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE rollout_id = %d AND cohort = %d ORDER BY position, rollout_target_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (int) $rollout_id,
            (int) $cohort
        ), ARRAY_A);

        return array_map([$this, 'normalize_target'], is_array($rows) ? $rows : []);
    }

    /**
     * @param int                  $rollout_target_id
     * @param array<string, mixed> $fields
     * @return bool
     */
    public function update_target($rollout_target_id, array $fields)
    {
        global $wpdb;

        $columns = ['state', 'operation_id', 'approval_uid', 'outcome', 'detail'];
        $data = ['updated_at' => current_time('mysql', true)];
        $formats = ['%s'];
        foreach ($columns as $column) {
            if (array_key_exists($column, $fields)) {
                $data[$column] = (string) $fields[$column];
                $formats[] = '%s';
            }
        }

        return $wpdb->update(
            Schema::table('rollout_targets'),
            $data,
            ['rollout_target_id' => (int) $rollout_target_id],
            $formats,
            ['%d']
        ) !== false;
    }

    /**
     * @param int                  $rollout_id
     * @param array<string, mixed> $fields state, current_cohort, paused_reason
     * @return bool
     */
    public function update_rollout($rollout_id, array $fields)
    {
        global $wpdb;

        $data = ['updated_at' => current_time('mysql', true)];
        $formats = ['%s'];
        if (array_key_exists('state', $fields)) {
            $data['state'] = (string) $fields['state'];
            $formats[] = '%s';
        }
        if (array_key_exists('current_cohort', $fields)) {
            $data['current_cohort'] = (int) $fields['current_cohort'];
            $formats[] = '%d';
        }
        if (array_key_exists('paused_reason', $fields)) {
            $data['paused_reason'] = (string) $fields['paused_reason'];
            $formats[] = '%s';
        }

        return $wpdb->update(
            Schema::table('rollouts'),
            $data,
            ['rollout_id' => (int) $rollout_id],
            $formats,
            ['%d']
        ) !== false;
    }

    /**
     * How many rollouts in the given states are older than the cutoff (dry run).
     *
     * @param array<int, string> $states
     * @param string             $before_gmt Y-m-d H:i:s in UTC.
     * @return int
     */
    public function prunable_count(array $states, $before_gmt)
    {
        global $wpdb;

        if ($states === []) {
            return 0;
        }
        $rollouts = Schema::table('rollouts');
        $state_ph = implode(', ', array_fill(0, count($states), '%s'));
        $sql = "SELECT COUNT(*) FROM {$rollouts} WHERE state IN ({$state_ph}) AND updated_at < %s";
        $args = array_merge(array_map('strval', array_values($states)), [(string) $before_gmt]);

        return (int) $wpdb->get_var($wpdb->prepare($sql, $args)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Delete rollouts (and their target rows) in the given states older than the
     * cutoff. Only terminal rollouts should be passed; a running or paused one
     * is never removed. Returns the number of rollouts deleted.
     *
     * @param array<int, string> $states
     * @param string             $before_gmt Y-m-d H:i:s in UTC.
     * @return int
     */
    public function prune(array $states, $before_gmt)
    {
        global $wpdb;

        if ($states === []) {
            return 0;
        }
        $rollouts = Schema::table('rollouts');
        $targets = Schema::table('rollout_targets');
        $state_ph = implode(', ', array_fill(0, count($states), '%s'));
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT rollout_id FROM {$rollouts} WHERE state IN ({$state_ph}) AND updated_at < %s ORDER BY rollout_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
            array_merge(array_map('strval', array_values($states)), [(string) $before_gmt])
        )));
        if ($ids === []) {
            return 0;
        }
        $id_ph = implode(', ', array_fill(0, count($ids), '%d'));
        $wpdb->query($wpdb->prepare("DELETE FROM {$targets} WHERE rollout_id IN ({$id_ph})", $ids)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$rollouts} WHERE rollout_id IN ({$id_ph})", $ids)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

        return (int) $deleted;
    }

    /**
     * @return array<string, int>
     */
    public function counts()
    {
        global $wpdb;

        $table = Schema::table('rollouts');
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
        foreach (['rollout_id', 'release_id', 'current_cohort', 'cohort_count', 'target_count', 'created_by'] as $key) {
            $row[$key] = (int) $row[$key];
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize_target(array $row)
    {
        foreach (['rollout_target_id', 'rollout_id', 'cohort', 'position'] as $key) {
            $row[$key] = (int) $row[$key];
        }

        return $row;
    }
}
