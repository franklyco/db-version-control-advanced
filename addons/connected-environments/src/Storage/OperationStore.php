<?php

namespace Dbvc\Connected\Storage;

/**
 * Recoverable operation journal for approved executions: one row per
 * operation, created before the first write with the verified before image
 * of every container touched, then closed with the step log and the
 * execution receipt. Rows are never deleted; a lost hub response is a
 * report to retry, never a reason to execute again.
 */
final class OperationStore
{
    public const STATE_STARTED = 'started';
    public const STATE_FINISHED = 'finished';

    /**
     * @param array<string, mixed> $data
     * @param string               $hub_url
     * @return string created|exists|error
     */
    public function start(array $data, $hub_url)
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors();
        $inserted = $wpdb->insert(
            Schema::table('operations'),
            [
                'operation_id' => (string) $data['operation_id'],
                'approval_uid' => (string) $data['approval_uid'],
                'release_uid' => (string) $data['release_uid'],
                'release_digest' => (string) $data['release_digest'],
                'receipt_digest' => (string) $data['receipt_digest'],
                'hub_url' => (string) $hub_url,
                'installation_epoch' => (string) $data['installation_epoch'],
                'state' => self::STATE_STARTED,
                'before_image' => wp_json_encode((array) ($data['before_image'] ?? [])),
                'journal' => wp_json_encode([]),
                'started_at' => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        $wpdb->suppress_errors($suppress);
        if ($inserted === 1) {
            return 'created';
        }

        return stripos((string) $wpdb->last_error, 'duplicate') !== false ? 'exists' : 'error';
    }

    /**
     * @param string               $operation_id
     * @param array<string, mixed> $receipt
     * @param array<int, array<string, mixed>> $journal
     * @return bool
     */
    public function finish($operation_id, array $receipt, array $journal)
    {
        global $wpdb;

        return $wpdb->update(
            Schema::table('operations'),
            [
                'state' => self::STATE_FINISHED,
                'outcome' => (string) ($receipt['outcome'] ?? ''),
                'journal' => wp_json_encode($journal),
                'execution_receipt' => wp_json_encode($receipt),
                'execution_digest' => \Dbvc\ConnectedProtocol\Canonicalizer::hash(\Dbvc\ConnectedProtocol\Canonicalizer::encode($receipt)),
                'finished_at' => current_time('mysql', true),
            ],
            ['operation_id' => (string) $operation_id],
            ['%s', '%s', '%s', '%s', '%s', '%s'],
            ['%s']
        ) !== false;
    }

    /**
     * @param string $operation_id
     * @param string $error
     * @return void
     */
    public function mark_reported($operation_id, $error = '')
    {
        global $wpdb;

        $wpdb->update(Schema::table('operations'), ['reported_at' => current_time('mysql', true), 'report_error' => (string) $error], ['operation_id' => (string) $operation_id], ['%s', '%s'], ['%s']);
    }

    /**
     * @param string $operation_id
     * @param string $error
     * @return void
     */
    public function mark_report_failed($operation_id, $error)
    {
        global $wpdb;

        $wpdb->update(Schema::table('operations'), ['report_error' => (string) $error], ['operation_id' => (string) $operation_id], ['%s'], ['%s']);
    }

    /**
     * @param string $operation_id
     * @return array<string, mixed>|null
     */
    public function find($operation_id)
    {
        global $wpdb;

        $table = Schema::table('operations');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE operation_id = %s", (string) $operation_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * Finished operations whose execution receipt the hub has not accepted.
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function unreported($limit = 10)
    {
        global $wpdb;

        $table = Schema::table('operations');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE state = %s AND reported_at IS NULL ORDER BY operation_row_id LIMIT %d", self::STATE_FINISHED, max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($limit = 50)
    {
        global $wpdb;

        $table = Schema::table('operations');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY operation_row_id DESC LIMIT %d", max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * @return array<string, int>
     */
    public function counts()
    {
        global $wpdb;

        $table = Schema::table('operations');
        $row = $wpdb->get_row("SELECT COUNT(*) AS total, SUM(state = 'started') AS started, SUM(reported_at IS NULL AND state = 'finished') AS unreported FROM {$table}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return ['total' => (int) ($row['total'] ?? 0), 'started' => (int) ($row['started'] ?? 0), 'unreported' => (int) ($row['unreported'] ?? 0)];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row)
    {
        $row['operation_row_id'] = (int) $row['operation_row_id'];
        foreach (['before_image', 'journal', 'execution_receipt'] as $key) {
            $decoded = json_decode((string) ($row[$key] ?? ''), true);
            $row[$key] = is_array($decoded) ? $decoded : null;
        }

        return $row;
    }
}
