<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * Change report persistence (CR-3, schema v22). Three stores behind one class:
 *
 * - reports: one row per completed report cycle (a run) — the console's list.
 * - items: one LIVE row per pair + object, updated by every run that touches
 *   the object and never duplicated (CR-M decision e: the simple view is an
 *   inbox of objects, runs are invisible there). `resolved` hides a row from
 *   the inbox; a later run reopens it when the object changes again.
 * - decisions: one row per pair + object carried across runs (D11): reconciled,
 *   ignored, snoozed, promoted, with the hashes it was decided against and a
 *   verify state the next comparison settles.
 * - run items (CR-4e, v23): one row per run + object as that run recorded it —
 *   the console's historical per-run view. Written beside every live upsert
 *   (and for each object a run settles as converged), deleted with the report,
 *   never read by the inbox.
 *
 * Additive store; every write is keyed on the pair so two pairs never mix.
 */
final class ChangeReportStore
{
    public const DECISION_RECONCILED = 'reconciled';
    public const DECISION_IGNORED = 'ignored';
    public const DECISION_SNOOZED = 'snoozed';
    public const DECISION_PROMOTED = 'promoted';
    public const DECISIONS = [self::DECISION_RECONCILED, self::DECISION_IGNORED, self::DECISION_SNOOZED, self::DECISION_PROMOTED];

    public const VERIFY_PENDING = 'pending';
    public const VERIFY_VERIFIED = 'verified';
    public const VERIFY_STILL_DIFFERS = 'still_differs';
    public const VERIFY_MUTED = 'muted';
    public const VERIFY_CHANGED_AGAIN = 'changed_again';
    public const VERIFY_SNOOZED = 'snoozed';
    public const VERIFY_DUE = 'due';

    public const LABELS = ['created', 'updated', 'removed', 'conflict', 'local_only', 'converged', 'locked', 'unknown', 'baseline_required'];

    // ---- reports ----------------------------------------------------------

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>|null
     */
    public function create_report(array $report)
    {
        global $wpdb;

        $ok = $wpdb->insert(Schema::table('change_reports'), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'report_uid' => (string) $report['report_uid'],
            'agency_id' => (string) ($report['agency_id'] ?? ''),
            'client_id' => (string) ($report['client_id'] ?? ''),
            'source_environment_id' => (string) $report['source_environment_id'],
            'target_environment_id' => (string) $report['target_environment_id'],
            'release_uid' => (string) ($report['release_uid'] ?? ''),
            'operation_id' => (string) ($report['operation_id'] ?? ''),
            'state' => (string) ($report['state'] ?? 'ready'),
            'receipt_outcome' => (string) ($report['receipt_outcome'] ?? ''),
            'comparison_digest' => (string) ($report['comparison_digest'] ?? ''),
            'values_included' => ! empty($report['values_included']) ? 1 : 0,
            'item_count' => (int) ($report['item_count'] ?? 0),
            'counts' => wp_json_encode((array) ($report['counts'] ?? [])),
            'generated_at' => (string) ($report['generated_at'] ?? current_time('mysql', true)),
        ]);

        return $ok === 1 ? $this->find_report((string) $report['report_uid']) : null;
    }

    /**
     * @param string $report_uid
     * @return array<string, mixed>|null
     */
    public function find_report($report_uid)
    {
        global $wpdb;
        $table = Schema::table('change_reports');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE report_uid = %s", (string) $report_uid), ARRAY_A); // phpcs:ignore WordPress.DB

        return is_array($row) ? $this->report_out($row) : null;
    }

    /**
     * Latest report of a pair.
     *
     * @param string $source
     * @param string $target
     * @return array<string, mixed>|null
     */
    public function latest_report($source, $target)
    {
        $rows = $this->reports($source, $target, 1);

        return $rows[0] ?? null;
    }

    /**
     * @param string|null $source
     * @param string|null $target
     * @param int         $limit
     * @return array<int, array<string, mixed>>
     */
    public function reports($source = null, $target = null, $limit = 20)
    {
        global $wpdb;
        $table = Schema::table('change_reports');
        $where = [];
        $values = [];
        if ($source !== null && $source !== '') {
            $where[] = 'source_environment_id = %s';
            $values[] = (string) $source;
        }
        if ($target !== null && $target !== '') {
            $where[] = 'target_environment_id = %s';
            $values[] = (string) $target;
        }
        $values[] = max(1, min(500, (int) $limit));
        $sql = "SELECT * FROM {$table}" . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY report_id DESC LIMIT %d';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A); // phpcs:ignore WordPress.DB

        return array_map([$this, 'report_out'], is_array($rows) ? $rows : []);
    }

    /**
     * Keep the newest `$keep` reports of a pair; delete the rest together with
     * their run snapshots. Items are live rows and are never deleted here.
     *
     * @param string $source
     * @param string $target
     * @param int    $keep
     * @return int Deleted rows.
     */
    public function prune_reports($source, $target, $keep)
    {
        global $wpdb;
        $table = Schema::table('change_reports');
        $keep = max(1, (int) $keep);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT report_id, report_uid FROM {$table} WHERE source_environment_id = %s AND target_environment_id = %s ORDER BY report_id DESC LIMIT %d, 100000", (string) $source, (string) $target, $keep), ARRAY_A); // phpcs:ignore WordPress.DB
        $deleted = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $this->delete_run_items((string) $row['report_uid']);
            $deleted += (int) $wpdb->delete($table, ['report_id' => (int) $row['report_id']], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        }

        return $deleted;
    }

    /**
     * @param string $release_uid
     * @return bool
     */
    public function report_exists_for_release($release_uid)
    {
        global $wpdb;
        $table = Schema::table('change_reports');

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE release_uid = %s", (string) $release_uid)) > 0; // phpcs:ignore WordPress.DB
    }

    // ---- items --------------------------------------------------------------

    /**
     * Insert or update the live row for one pair + object.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    public function upsert_item(array $item)
    {
        global $wpdb;
        $table = Schema::table('change_report_items');
        $now = current_time('mysql', true);
        $existing = $this->item((string) $item['source_environment_id'], (string) $item['target_environment_id'], (string) $item['domain'], (string) $item['instance_uid']);
        $data = [
            'target_instance_uid' => (string) ($item['target_instance_uid'] ?? ''),
            'profile' => (string) ($item['profile'] ?? ''),
            'display_name' => mb_substr((string) ($item['display_name'] ?? ''), 0, 191),
            'label' => (string) ($item['label'] ?? 'updated'),
            'comparison_state' => (string) ($item['comparison_state'] ?? ''),
            'operation' => (string) ($item['operation'] ?? ''),
            'source_hash' => isset($item['source_hash']) ? (string) $item['source_hash'] : null,
            'target_hash' => isset($item['target_hash']) ? (string) $item['target_hash'] : null,
            'baseline_hash' => isset($item['baseline_hash']) ? (string) $item['baseline_hash'] : null,
            'paths' => wp_json_encode(array_values((array) ($item['paths'] ?? []))),
            'path_count' => count((array) ($item['paths'] ?? [])),
            'values_included' => ! empty($item['values_included']) ? 1 : 0,
            'truncated' => ! empty($item['truncated']) ? 1 : 0,
            'receipt_outcome' => (string) ($item['receipt_outcome'] ?? ''),
            'blockers' => wp_json_encode(array_values((array) ($item['blockers'] ?? []))),
            'report_uid' => (string) ($item['report_uid'] ?? ''),
            'resolved' => ! empty($item['resolved']) ? 1 : 0,
            'changed_at' => (string) ($item['changed_at'] ?? $now),
            'updated_at' => $now,
        ];
        if ($existing !== null) {
            $wpdb->update($table, $data, ['item_id' => (int) $existing['item_id']]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        } else {
            $data['source_environment_id'] = (string) $item['source_environment_id'];
            $data['target_environment_id'] = (string) $item['target_environment_id'];
            $data['domain'] = (string) $item['domain'];
            $data['instance_uid'] = (string) $item['instance_uid'];
            $data['first_report_uid'] = (string) ($item['report_uid'] ?? '');
            $wpdb->insert($table, $data); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        }

        return $this->item((string) $item['source_environment_id'], (string) $item['target_environment_id'], (string) $item['domain'], (string) $item['instance_uid']);
    }

    /**
     * @param string $source
     * @param string $target
     * @param string $domain
     * @param string $instance_uid
     * @return array<string, mixed>|null
     */
    public function item($source, $target, $domain, $instance_uid)
    {
        global $wpdb;
        $table = Schema::table('change_report_items');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE source_environment_id = %s AND target_environment_id = %s AND domain = %s AND instance_uid = %s", (string) $source, (string) $target, (string) $domain, (string) $instance_uid), ARRAY_A); // phpcs:ignore WordPress.DB

        return is_array($row) ? $this->item_out($row) : null;
    }

    /**
     * @param string               $source
     * @param string               $target
     * @param array<string, mixed> $options resolved (bool|null = both), report_uid, limit
     * @return array<int, array<string, mixed>>
     */
    public function items($source, $target, array $options = [])
    {
        global $wpdb;
        $table = Schema::table('change_report_items');
        $where = ['source_environment_id = %s', 'target_environment_id = %s'];
        $values = [(string) $source, (string) $target];
        if (array_key_exists('resolved', $options) && $options['resolved'] !== null) {
            $where[] = 'resolved = %d';
            $values[] = ! empty($options['resolved']) ? 1 : 0;
        }
        if (! empty($options['report_uid'])) {
            $where[] = 'report_uid = %s';
            $values[] = (string) $options['report_uid'];
        }
        $values[] = max(1, min(2000, (int) ($options['limit'] ?? 500)));
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY changed_at DESC, domain, instance_uid LIMIT %d';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A); // phpcs:ignore WordPress.DB

        return array_map([$this, 'item_out'], is_array($rows) ? $rows : []);
    }

    /**
     * @param int  $item_id
     * @param bool $resolved
     * @return void
     */
    public function set_item_resolved($item_id, $resolved)
    {
        global $wpdb;
        $wpdb->update(Schema::table('change_report_items'), ['resolved' => $resolved ? 1 : 0, 'updated_at' => current_time('mysql', true)], ['item_id' => (int) $item_id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    /**
     * @param int    $item_id
     * @param string $label
     * @param bool   $resolved
     * @param string $comparison_state
     * @return void
     */
    public function set_item_label($item_id, $label, $resolved, $comparison_state = '')
    {
        global $wpdb;
        $data = ['label' => (string) $label, 'resolved' => $resolved ? 1 : 0, 'updated_at' => current_time('mysql', true)];
        if ($comparison_state !== '') {
            $data['comparison_state'] = (string) $comparison_state;
        }
        $wpdb->update(Schema::table('change_report_items'), $data, ['item_id' => (int) $item_id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    // ---- run items (per-run snapshot) ---------------------------------------

    /**
     * Record how a run saw one object: the live item's shape frozen under the
     * run's report_uid (CR-4e). Idempotent per run + object.
     *
     * @param string               $report_uid
     * @param array<string, mixed> $item The live item row (output of {@see item()} / {@see upsert_item()}).
     * @return bool
     */
    public function snapshot_item($report_uid, array $item)
    {
        global $wpdb;
        $table = Schema::table('change_report_run_items');
        $data = [
            'report_uid' => (string) $report_uid,
            'source_environment_id' => (string) $item['source_environment_id'],
            'target_environment_id' => (string) $item['target_environment_id'],
            'domain' => (string) $item['domain'],
            'instance_uid' => (string) $item['instance_uid'],
            'target_instance_uid' => (string) ($item['target_instance_uid'] ?? ''),
            'profile' => (string) ($item['profile'] ?? ''),
            'display_name' => mb_substr((string) ($item['display_name'] ?? ''), 0, 191),
            'label' => (string) ($item['label'] ?? 'updated'),
            'comparison_state' => (string) ($item['comparison_state'] ?? ''),
            'operation' => (string) ($item['operation'] ?? ''),
            'source_hash' => isset($item['source_hash']) ? (string) $item['source_hash'] : null,
            'target_hash' => isset($item['target_hash']) ? (string) $item['target_hash'] : null,
            'baseline_hash' => isset($item['baseline_hash']) ? (string) $item['baseline_hash'] : null,
            'paths' => wp_json_encode(array_values((array) ($item['paths'] ?? []))),
            'path_count' => count((array) ($item['paths'] ?? [])),
            'values_included' => ! empty($item['values_included']) ? 1 : 0,
            'truncated' => ! empty($item['truncated']) ? 1 : 0,
            'receipt_outcome' => (string) ($item['receipt_outcome'] ?? ''),
            'blockers' => wp_json_encode(array_values((array) ($item['blockers'] ?? []))),
            'resolved' => ! empty($item['resolved']) ? 1 : 0,
            'recorded_at' => current_time('mysql', true),
        ];
        $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT run_item_id FROM {$table} WHERE report_uid = %s AND domain = %s AND instance_uid = %s", (string) $report_uid, $data['domain'], $data['instance_uid'])); // phpcs:ignore WordPress.DB
        if ($existing > 0) {
            return $wpdb->update($table, $data, ['run_item_id' => $existing], null, ['%d']) !== false; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        }

        return $wpdb->insert($table, $data) === 1; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    /**
     * The objects one run recorded, as it recorded them (empty for a run older
     * than the snapshot table; callers fall back to the live rows).
     *
     * @param string $source
     * @param string $target
     * @param string $report_uid
     * @param int    $limit
     * @return array<int, array<string, mixed>>
     */
    public function run_items($source, $target, $report_uid, $limit = 500)
    {
        global $wpdb;
        $table = Schema::table('change_report_run_items');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE source_environment_id = %s AND target_environment_id = %s AND report_uid = %s ORDER BY resolved ASC, label, domain, instance_uid LIMIT %d", (string) $source, (string) $target, (string) $report_uid, max(1, min(2000, (int) $limit))), ARRAY_A); // phpcs:ignore WordPress.DB

        return array_map([$this, 'run_item_out'], is_array($rows) ? $rows : []);
    }

    /**
     * @param string $report_uid
     * @return int Deleted rows.
     */
    public function delete_run_items($report_uid)
    {
        global $wpdb;

        return (int) $wpdb->delete(Schema::table('change_report_run_items'), ['report_uid' => (string) $report_uid]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    // ---- decisions ----------------------------------------------------------

    /**
     * @param array<string, mixed> $decision
     * @return array<string, mixed>|null
     */
    public function upsert_decision(array $decision)
    {
        global $wpdb;
        $table = Schema::table('change_report_decisions');
        $now = current_time('mysql', true);
        $existing = $this->decision((string) $decision['source_environment_id'], (string) $decision['target_environment_id'], (string) $decision['domain'], (string) $decision['instance_uid']);
        $data = [
            'decision' => (string) $decision['decision'],
            'decided_source_hash' => isset($decision['decided_source_hash']) ? (string) $decision['decided_source_hash'] : null,
            'decided_target_hash' => isset($decision['decided_target_hash']) ? (string) $decision['decided_target_hash'] : null,
            'decided_by' => (int) ($decision['decided_by'] ?? 0) ?: null,
            'decided_at' => (string) ($decision['decided_at'] ?? $now),
            'note' => mb_substr((string) ($decision['note'] ?? ''), 0, 191),
            'verify_state' => (string) ($decision['verify_state'] ?? self::VERIFY_PENDING),
            'snoozed_until' => isset($decision['snoozed_until']) ? (string) $decision['snoozed_until'] : null,
            'rebaseline_object' => ! empty($decision['rebaseline_object']) ? 1 : 0,
            'release_uid' => (string) ($decision['release_uid'] ?? ''),
            'updated_at' => $now,
        ];
        if ($existing !== null) {
            $wpdb->update($table, $data, ['decision_id' => (int) $existing['decision_id']]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        } else {
            $data['source_environment_id'] = (string) $decision['source_environment_id'];
            $data['target_environment_id'] = (string) $decision['target_environment_id'];
            $data['domain'] = (string) $decision['domain'];
            $data['instance_uid'] = (string) $decision['instance_uid'];
            $wpdb->insert($table, $data); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        }

        return $this->decision((string) $decision['source_environment_id'], (string) $decision['target_environment_id'], (string) $decision['domain'], (string) $decision['instance_uid']);
    }

    /**
     * @param int    $decision_id
     * @param string $verify_state
     * @return void
     */
    public function set_verify_state($decision_id, $verify_state)
    {
        global $wpdb;
        $wpdb->update(Schema::table('change_report_decisions'), ['verify_state' => (string) $verify_state, 'updated_at' => current_time('mysql', true)], ['decision_id' => (int) $decision_id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    /**
     * @param string $source
     * @param string $target
     * @param string $domain
     * @param string $instance_uid
     * @return array<string, mixed>|null
     */
    public function decision($source, $target, $domain, $instance_uid)
    {
        global $wpdb;
        $table = Schema::table('change_report_decisions');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE source_environment_id = %s AND target_environment_id = %s AND domain = %s AND instance_uid = %s", (string) $source, (string) $target, (string) $domain, (string) $instance_uid), ARRAY_A); // phpcs:ignore WordPress.DB

        return is_array($row) ? $this->decision_out($row) : null;
    }

    /**
     * @param string $source
     * @param string $target
     * @return array<string, array<string, mixed>> keyed "domain|instance_uid"
     */
    public function decisions($source, $target)
    {
        global $wpdb;
        $table = Schema::table('change_report_decisions');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE source_environment_id = %s AND target_environment_id = %s", (string) $source, (string) $target), ARRAY_A); // phpcs:ignore WordPress.DB
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $decision = $this->decision_out($row);
            $out[$decision['domain'] . '|' . $decision['instance_uid']] = $decision;
        }

        return $out;
    }

    /**
     * @param string $source
     * @param string $target
     * @param string $domain
     * @param string $instance_uid
     * @return bool
     */
    public function delete_decision($source, $target, $domain, $instance_uid)
    {
        global $wpdb;

        return (bool) $wpdb->delete(Schema::table('change_report_decisions'), ['source_environment_id' => (string) $source, 'target_environment_id' => (string) $target, 'domain' => (string) $domain, 'instance_uid' => (string) $instance_uid]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    // ---- row shapes ---------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function report_out(array $row)
    {
        $counts = json_decode((string) ($row['counts'] ?? ''), true);
        $row['report_id'] = (int) $row['report_id'];
        $row['values_included'] = (int) $row['values_included'] === 1;
        $row['item_count'] = (int) $row['item_count'];
        $row['counts'] = is_array($counts) ? $counts : [];

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function item_out(array $row)
    {
        $paths = json_decode((string) ($row['paths'] ?? ''), true);
        $blockers = json_decode((string) ($row['blockers'] ?? ''), true);
        $row['item_id'] = (int) $row['item_id'];
        $row['path_count'] = (int) $row['path_count'];
        $row['values_included'] = (int) $row['values_included'] === 1;
        $row['truncated'] = (int) $row['truncated'] === 1;
        $row['resolved'] = (int) $row['resolved'] === 1;
        $row['paths'] = is_array($paths) ? $paths : [];
        $row['blockers'] = is_array($blockers) ? $blockers : [];

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function run_item_out(array $row)
    {
        // Same shape as a live item (consumers read changed_at / updated_at), stamped
        // with the moment the run recorded it.
        $row = $this->item_out($row + ['item_id' => 0, 'path_count' => 0]);
        $row['run_item_id'] = (int) $row['run_item_id'];
        $row['changed_at'] = (string) $row['recorded_at'];
        $row['updated_at'] = (string) $row['recorded_at'];
        $row['first_report_uid'] = '';
        unset($row['item_id']);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decision_out(array $row)
    {
        $row['decision_id'] = (int) $row['decision_id'];
        $row['decided_by'] = (int) ($row['decided_by'] ?? 0);
        $row['rebaseline_object'] = (int) $row['rebaseline_object'] === 1;
        $row['snoozed_until'] = $row['snoozed_until'] !== null ? (string) $row['snoozed_until'] : null;

        return $row;
    }
}
