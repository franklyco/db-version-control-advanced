<?php

namespace Dbvc\Connected\Storage;

/**
 * Local record of every prepare receipt this environment produced: the
 * evidence of what a dry run found, kept whether or not the hub accepted
 * it. Receipts are immutable once stored; only the reporting outcome is
 * updated.
 */
final class PreparationStore
{
    /**
     * @param array<string, mixed> $receipt
     * @param string               $hub_url
     * @return string created|exists|error
     */
    public function insert(array $receipt, $hub_url)
    {
        global $wpdb;

        $encoded = wp_json_encode($receipt);
        if (! is_string($encoded)) {
            return 'error';
        }
        $counts = is_array($receipt['counts'] ?? null) ? $receipt['counts'] : [];
        $suppress = $wpdb->suppress_errors();
        $inserted = $wpdb->insert(
            Schema::table('preparations'),
            [
                'operation_id' => (string) $receipt['operation_id'],
                'release_uid' => (string) $receipt['release_uid'],
                'release_digest' => (string) $receipt['release_digest'],
                'hub_url' => (string) $hub_url,
                'installation_epoch' => (string) ($receipt['target']['epoch'] ?? ''),
                'outcome' => (string) $receipt['outcome'],
                'items_ready' => (int) ($counts['ready'] ?? 0),
                'items_noop' => (int) ($counts['noop'] ?? 0),
                'items_blocked' => (int) ($counts['blocked'] ?? 0),
                'receipt' => $encoded,
                'receipt_digest' => \Dbvc\ConnectedProtocol\Canonicalizer::hash(\Dbvc\ConnectedProtocol\Canonicalizer::encode($receipt)),
                'prepared_at' => gmdate('Y-m-d H:i:s', (int) strtotime((string) $receipt['prepared_at'])),
                'expires_at' => gmdate('Y-m-d H:i:s', (int) strtotime((string) $receipt['expires_at'])),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s']
        );
        $wpdb->suppress_errors($suppress);
        if ($inserted === 1) {
            return 'created';
        }

        return stripos((string) $wpdb->last_error, 'duplicate') !== false ? 'exists' : 'error';
    }

    /**
     * The hub answered definitively: accepted ('' error) or permanently rejected (error code). No resend either way.
     *
     * @param string $operation_id
     * @param string $error
     * @return void
     */
    public function mark_reported($operation_id, $error = '')
    {
        global $wpdb;

        $wpdb->update(
            Schema::table('preparations'),
            ['reported_at' => current_time('mysql', true), 'report_error' => (string) $error],
            ['operation_id' => (string) $operation_id],
            ['%s', '%s'],
            ['%s']
        );
    }

    /**
     * Transport failure: keep the receipt unreported so the next run resends it.
     *
     * @param string $operation_id
     * @param string $error
     * @return void
     */
    public function mark_report_failed($operation_id, $error)
    {
        global $wpdb;

        $wpdb->update(
            Schema::table('preparations'),
            ['report_error' => (string) $error],
            ['operation_id' => (string) $operation_id],
            ['%s'],
            ['%s']
        );
    }

    /**
     * @param string $operation_id
     * @return array<string, mixed>|null
     */
    public function find($operation_id)
    {
        global $wpdb;

        $table = Schema::table('preparations');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE operation_id = %s", (string) $operation_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * Receipts produced but never accepted by the hub (crash or transport failure between prepare and report).
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function unreported($limit = 10)
    {
        global $wpdb;

        $table = Schema::table('preparations');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE reported_at IS NULL AND expires_at > %s ORDER BY preparation_id LIMIT %d", current_time('mysql', true), max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($limit = 50)
    {
        global $wpdb;

        $table = Schema::table('preparations');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY preparation_id DESC LIMIT %d", max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * @return array<string, int>
     */
    public function counts()
    {
        global $wpdb;

        $table = Schema::table('preparations');
        $row = $wpdb->get_row("SELECT COUNT(*) AS total, SUM(reported_at IS NULL) AS unreported FROM {$table}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return ['total' => (int) ($row['total'] ?? 0), 'unreported' => (int) ($row['unreported'] ?? 0)];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row)
    {
        $row['preparation_id'] = (int) $row['preparation_id'];
        foreach (['items_ready', 'items_noop', 'items_blocked'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $decoded = json_decode((string) $row['receipt'], true);
        $row['receipt'] = is_array($decoded) ? $decoded : null;

        return $row;
    }
}
