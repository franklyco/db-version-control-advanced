<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * A framework rollout campaign and its per-target rows (M9 slice 4c). One
 * header per "publish a framework version to the fleet" action; one target row
 * per subscribing environment. Unlike an M6 rollout (one sealed release fanned
 * across cohorts), a framework campaign gives each target its OWN override-aware
 * release + single-target rollout — because whether an object is a create or a
 * replace, and whether it is locked, is decided per target. So the per-target
 * in-flight refs (release_uid → rollout_uid) live here.
 *
 * The store keeps durable state only; {@see \Dbvc\AgencyControl\Framework\FrameworkRolloutService}
 * drives each target through build → seal → rollout → verify.
 */
final class FrameworkRolloutStore
{
    public const STATE_RUNNING = 'running';
    public const STATE_PAUSED = 'paused';
    public const STATE_COMPLETED = 'completed';
    public const STATE_FAILED = 'failed';
    public const STATE_WITHDRAWN = 'withdrawn';

    // Per-target lifecycle.
    public const TARGET_PENDING = 'pending';
    public const TARGET_RELEASE_OPEN = 'release_open';
    public const TARGET_AWAITING_PAYLOADS = 'awaiting_payloads';
    public const TARGET_ROLLOUT_RUNNING = 'rollout_running';
    public const TARGET_SUCCEEDED = 'succeeded';
    public const TARGET_SKIPPED = 'skipped';
    public const TARGET_FAILED = 'failed';

    /**
     * @param array<string, mixed>             $data    campaign_uid, agency_id, client_id, source_environment_id, version, channel, definition_uids (array), note, created_by
     * @param array<int, array<string, mixed>> $targets Each: target_environment_id, cohort, position
     * @return array<string, mixed>|null
     */
    public function create(array $data, array $targets)
    {
        global $wpdb;

        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(
            Schema::table('framework_rollouts'),
            [
                'campaign_uid' => (string) $data['campaign_uid'],
                'agency_id' => (string) $data['agency_id'],
                'client_id' => (string) ($data['client_id'] ?? ''),
                'source_environment_id' => (string) $data['source_environment_id'],
                'version' => (string) ($data['version'] ?? ''),
                'channel' => (string) ($data['channel'] ?? 'stable'),
                'definition_uids' => wp_json_encode(array_values((array) ($data['definition_uids'] ?? []))),
                'state' => self::STATE_RUNNING,
                'target_count' => count($targets),
                'note' => (string) ($data['note'] ?? ''),
                'created_by' => (int) ($data['created_by'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s']
        );
        if ($inserted !== 1) {
            return null;
        }
        foreach ($targets as $target) {
            $wpdb->insert(
                Schema::table('framework_rollout_targets'),
                [
                    'campaign_uid' => (string) $data['campaign_uid'],
                    'target_environment_id' => (string) $target['target_environment_id'],
                    'cohort' => (int) ($target['cohort'] ?? 0),
                    'position' => (int) ($target['position'] ?? 0),
                    'state' => self::TARGET_PENDING,
                    'updated_at' => $now,
                ],
                ['%s', '%s', '%d', '%d', '%s', '%s']
            );
        }

        return $this->find((string) $data['campaign_uid']);
    }

    /**
     * @param string $campaign_uid
     * @return array<string, mixed>|null
     */
    public function find($campaign_uid)
    {
        global $wpdb;

        $table = Schema::table('framework_rollouts');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE campaign_uid = %s", (string) $campaign_uid), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @param string|null $agency_id
     * @param int         $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($agency_id = null, $limit = 200)
    {
        global $wpdb;

        $table = Schema::table('framework_rollouts');
        if ($agency_id !== null && $agency_id !== '') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE agency_id = %s ORDER BY framework_rollout_id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                (string) $agency_id,
                max(1, (int) $limit)
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY framework_rollout_id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                max(1, (int) $limit)
            ), ARRAY_A);
        }

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * Campaigns not yet in a terminal state — what a scheduler would advance.
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function active($limit = 200)
    {
        global $wpdb;

        $table = Schema::table('framework_rollouts');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE state = %s ORDER BY framework_rollout_id LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            self::STATE_RUNNING,
            max(1, (int) $limit)
        ), ARRAY_A);

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * @param string $campaign_uid
     * @return array<int, array<string, mixed>>
     */
    public function targets($campaign_uid)
    {
        global $wpdb;

        $table = Schema::table('framework_rollout_targets');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE campaign_uid = %s ORDER BY cohort, position, framework_rollout_target_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $campaign_uid
        ), ARRAY_A);

        return array_map([$this, 'normalize_target'], is_array($rows) ? $rows : []);
    }

    /**
     * @param int                  $framework_rollout_target_id
     * @param array<string, mixed> $fields state, release_uid, rollout_uid, item_count, detail
     * @return bool
     */
    public function update_target($framework_rollout_target_id, array $fields)
    {
        global $wpdb;

        $data = ['updated_at' => current_time('mysql', true)];
        $formats = ['%s'];
        foreach (['state', 'release_uid', 'rollout_uid', 'detail'] as $column) {
            if (array_key_exists($column, $fields)) {
                $data[$column] = (string) $fields[$column];
                $formats[] = '%s';
            }
        }
        if (array_key_exists('item_count', $fields)) {
            $data['item_count'] = (int) $fields['item_count'];
            $formats[] = '%d';
        }

        return $wpdb->update(
            Schema::table('framework_rollout_targets'),
            $data,
            ['framework_rollout_target_id' => (int) $framework_rollout_target_id],
            $formats,
            ['%d']
        ) !== false;
    }

    /**
     * @param string               $campaign_uid
     * @param array<string, mixed> $fields state
     * @return bool
     */
    public function update_campaign($campaign_uid, array $fields)
    {
        global $wpdb;

        $data = ['updated_at' => current_time('mysql', true)];
        $formats = ['%s'];
        if (array_key_exists('state', $fields)) {
            $data['state'] = (string) $fields['state'];
            $formats[] = '%s';
        }

        return $wpdb->update(
            Schema::table('framework_rollouts'),
            $data,
            ['campaign_uid' => (string) $campaign_uid],
            $formats,
            ['%s']
        ) !== false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row)
    {
        foreach (['framework_rollout_id', 'target_count', 'created_by'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $decoded = json_decode((string) ($row['definition_uids'] ?? ''), true);
        $row['definition_uids'] = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize_target(array $row)
    {
        foreach (['framework_rollout_target_id', 'cohort', 'position', 'item_count'] as $key) {
            $row[$key] = (int) $row[$key];
        }

        return $row;
    }
}
