<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * Framework review items: one per accepted observation of a subscribed
 * instance. `observed` is the raw receipt; `classified` carries the drift
 * and version state the framework status report assigned; `resolved`
 * closes it (automatically when the report shows nothing to review, or by
 * an explicit operator action). Nothing here promotes a client edit into a
 * definition or changes what a status row says.
 */
final class ReviewStore
{
    public const STATE_OBSERVED = 'observed';
    public const STATE_CLASSIFIED = 'classified';
    public const STATE_RESOLVED = 'resolved';

    /**
     * @param array<string, mixed> $data
     * @return string created|exists|error
     */
    public function create(array $data)
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors();
        $inserted = $wpdb->insert(
            Schema::table('review_items'),
            [
                'event_row_id' => (int) $data['event_row_id'],
                'agency_id' => (string) $data['agency_id'],
                'client_id' => (string) $data['client_id'],
                'environment_id' => (string) $data['environment_id'],
                'domain' => (string) $data['domain'],
                'instance_uid' => (string) $data['instance_uid'],
                'definition_uid' => (string) $data['definition_uid'],
                'installation_epoch' => (string) ($data['installation_epoch'] ?? ''),
                'event_sequence' => (int) ($data['event_sequence'] ?? 0),
                'state' => self::STATE_OBSERVED,
                'policy_revision' => (string) $data['policy_revision'],
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );
        $wpdb->suppress_errors($suppress);
        if ($inserted === 1) {
            return 'created';
        }

        return stripos((string) $wpdb->last_error, 'duplicate') !== false ? 'exists' : 'error';
    }

    /**
     * @param string|null $definition_uid
     * @param int         $limit
     * @param string|null $state
     * @param string|null $environment_id
     * @return array<int, array<string, mixed>>
     */
    public function all($definition_uid = null, $limit = 200, $state = null, $environment_id = null)
    {
        global $wpdb;

        $table = Schema::table('review_items');
        $where = [];
        $values = [];
        if ($definition_uid !== null && $definition_uid !== '') {
            $where[] = 'definition_uid = %s';
            $values[] = (string) $definition_uid;
        }
        if ($state !== null && $state !== '') {
            $where[] = 'state = %s';
            $values[] = (string) $state;
        }
        if ($environment_id !== null && $environment_id !== '') {
            $where[] = 'environment_id = %s';
            $values[] = (string) $environment_id;
        }
        $values[] = max(1, (int) $limit);
        $sql = "SELECT * FROM {$table}" . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY review_item_id LIMIT %d';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * @param int $review_item_id
     * @return array<string, mixed>|null
     */
    public function get($review_item_id)
    {
        global $wpdb;

        $table = Schema::table('review_items');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE review_item_id = %d", (int) $review_item_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * Stamp the drift/version state a report assigned; optionally close the item.
     *
     * @param int         $review_item_id
     * @param string      $drift
     * @param string      $version_state
     * @param string|null $resolution Non-null closes the item with this resolution.
     * @param string      $note
     * @param int         $resolved_by
     * @return bool
     */
    public function classify($review_item_id, $drift, $version_state, $resolution = null, $note = '', $resolved_by = 0)
    {
        global $wpdb;

        $now = current_time('mysql', true);
        $fields = [
            'state' => $resolution === null ? self::STATE_CLASSIFIED : self::STATE_RESOLVED,
            'drift' => (string) $drift,
            'version_state' => (string) $version_state,
            'classified_at' => $now,
        ];
        $formats = ['%s', '%s', '%s', '%s'];
        if ($resolution !== null) {
            $fields['resolution'] = (string) $resolution;
            $fields['note'] = mb_substr((string) $note, 0, 191);
            $fields['resolved_at'] = $now;
            $fields['resolved_by'] = (int) $resolved_by;
            array_push($formats, '%s', '%s', '%s', '%d');
        }
        $updated = $wpdb->update(Schema::table('review_items'), $fields, ['review_item_id' => (int) $review_item_id], $formats, ['%d']);

        return $updated !== false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row)
    {
        $row['review_item_id'] = (int) $row['review_item_id'];
        $row['event_row_id'] = (int) $row['event_row_id'];
        $row['event_sequence'] = (int) ($row['event_sequence'] ?? 0);
        $row['resolved_by'] = isset($row['resolved_by']) ? (int) $row['resolved_by'] : null;

        return $row;
    }

    /**
     * @return array<string, int>
     */
    public function counts()
    {
        global $wpdb;

        $table = Schema::table('review_items');
        $rows = $wpdb->get_results("SELECT state, COUNT(*) AS total FROM {$table} GROUP BY state", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $counts = ['total' => 0];
        foreach ((array) $rows as $row) {
            $counts[(string) $row['state']] = (int) $row['total'];
            $counts['total'] += (int) $row['total'];
        }

        return $counts;
    }
}
