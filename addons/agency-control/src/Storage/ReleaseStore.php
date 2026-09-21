<?php

namespace Dbvc\AgencyControl\Storage;

use Dbvc\ConnectedProtocol\Canonicalizer;

/**
 * Release manifests and their items. A manifest is fixed at creation (the
 * selected objects and their expected after-hashes); `open` only means the
 * source environment has not yet supplied every payload. Payloads are the
 * canonical bodies whose version-1 hash equals the item's after-hash, so the
 * hub verifies each one before storing it. The digest is computed over the
 * sorted item list when the release seals and never changes afterwards.
 */
final class ReleaseStore
{
    public const STATE_OPEN = 'open';
    public const STATE_SEALED = 'sealed';
    public const STATE_WITHDRAWN = 'withdrawn';

    public const PAYLOAD_REQUESTED = 'requested';
    public const PAYLOAD_RECEIVED = 'received';
    public const PAYLOAD_MISMATCH = 'mismatch';

    public const OPERATION_REPLACE = 'replace';
    public const OPERATION_DELETE = 'delete';

    /**
     * @param array<string, mixed>              $release
     * @param array<int, array<string, mixed>>  $items domain, instance_uid, profile, after_hash, source_sequence
     * @return array<string, mixed>|null
     */
    public function create(array $release, array $items)
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            Schema::table('releases'),
            [
                'release_uid' => (string) $release['release_uid'],
                'agency_id' => (string) $release['agency_id'],
                'client_id' => (string) $release['client_id'],
                'source_environment_id' => (string) $release['source_environment_id'],
                'source_epoch' => (string) $release['source_epoch'],
                'state' => self::STATE_OPEN,
                'item_count' => count($items),
                'digest' => '',
                'note' => mb_substr((string) ($release['note'] ?? ''), 0, 191),
                'created_by' => (int) ($release['created_by'] ?? 0),
                'created_at' => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s']
        );
        if ($inserted !== 1) {
            return null;
        }
        $release_id = (int) $wpdb->insert_id;
        foreach ($items as $item) {
            $ok = $wpdb->insert(
                Schema::table('release_items'),
                [
                    'release_id' => $release_id,
                    'domain' => (string) $item['domain'],
                    'instance_uid' => (string) $item['instance_uid'],
                    'profile' => (string) $item['profile'],
                    'operation' => (string) ($item['operation'] ?? self::OPERATION_REPLACE),
                    'after_hash' => (string) $item['after_hash'],
                    'source_sequence' => (int) ($item['source_sequence'] ?? 0),
                    'payload_state' => self::PAYLOAD_REQUESTED,
                ],
                ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s']
            );
            if ($ok !== 1) {
                return null;
            }
        }

        return $this->get($release_id);
    }

    /**
     * @param int $release_id
     * @return array<string, mixed>|null
     */
    public function get($release_id)
    {
        global $wpdb;

        $table = Schema::table('releases');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE release_id = %d", (int) $release_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @param string $release_uid
     * @return array<string, mixed>|null
     */
    public function find($release_uid)
    {
        global $wpdb;

        $table = Schema::table('releases');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE release_uid = %s", (string) $release_uid), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @param int  $release_id
     * @param bool $with_payload
     * @return array<int, array<string, mixed>>
     */
    public function items($release_id, $with_payload = false)
    {
        global $wpdb;

        $table = Schema::table('release_items');
        $columns = $with_payload ? '*' : 'release_item_id, release_id, domain, instance_uid, profile, operation, after_hash, source_sequence, payload_state, payload_reason, payload_received_at';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT {$columns} FROM {$table} WHERE release_id = %d ORDER BY domain, profile, instance_uid", (int) $release_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array_map(static function ($row) {
            $row['release_item_id'] = (int) $row['release_item_id'];
            $row['release_id'] = (int) $row['release_id'];
            $row['source_sequence'] = (int) $row['source_sequence'];
            return $row;
        }, is_array($rows) ? $rows : []);
    }

    /**
     * Items still awaiting a payload across every open release of one source environment.
     *
     * @param string $source_environment_id
     * @param int    $limit
     * @return array<int, array<string, mixed>> Each with `release_uid`.
     */
    public function requested_payloads($source_environment_id, $limit = 100)
    {
        global $wpdb;

        $releases = Schema::table('releases');
        $items = Schema::table('release_items');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.release_item_id, i.release_id, r.release_uid, i.domain, i.instance_uid, i.profile, i.operation, i.after_hash, i.source_sequence, i.payload_state
             FROM {$items} i INNER JOIN {$releases} r ON r.release_id = i.release_id
             WHERE r.source_environment_id = %s AND r.state = %s AND i.payload_state = %s
             ORDER BY i.release_item_id LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $source_environment_id,
            self::STATE_OPEN,
            self::PAYLOAD_REQUESTED,
            max(1, (int) $limit)
        ), ARRAY_A);

        return array_map(static function ($row) {
            $row['release_item_id'] = (int) $row['release_item_id'];
            $row['release_id'] = (int) $row['release_id'];
            $row['source_sequence'] = (int) $row['source_sequence'];
            return $row;
        }, is_array($rows) ? $rows : []);
    }

    /**
     * Store a verified payload (the caller has checked the hash) or record a mismatch.
     *
     * @param int         $release_item_id
     * @param string|null $payload Canonical body, or null to record `reason` as a mismatch.
     * @param string      $reason
     * @return bool
     */
    public function record_payload($release_item_id, $payload, $reason = '')
    {
        global $wpdb;

        $fields = $payload !== null
            ? ['payload_state' => self::PAYLOAD_RECEIVED, 'payload' => (string) $payload, 'payload_reason' => '', 'payload_received_at' => current_time('mysql', true)]
            : ['payload_state' => self::PAYLOAD_MISMATCH, 'payload' => null, 'payload_reason' => (string) $reason, 'payload_received_at' => current_time('mysql', true)];
        $updated = $wpdb->update(
            Schema::table('release_items'),
            $fields,
            ['release_item_id' => (int) $release_item_id, 'payload_state' => self::PAYLOAD_REQUESTED],
            ['%s', '%s', '%s', '%s'],
            ['%d', '%s']
        );

        return $updated === 1;
    }

    /**
     * Seal once every item holds a verified payload: compute the immutable digest.
     *
     * @param int $release_id
     * @return string|null The digest, or null when the release is not complete.
     */
    public function seal($release_id)
    {
        global $wpdb;

        $items = $this->items((int) $release_id);
        if ($items === []) {
            return null;
        }
        $manifest = [];
        foreach ($items as $item) {
            if ($item['payload_state'] !== self::PAYLOAD_RECEIVED) {
                return null;
            }
            $manifest[] = ['domain' => $item['domain'], 'instance_uid' => $item['instance_uid'], 'profile' => $item['profile'], 'operation' => $item['operation'], 'after_hash' => $item['after_hash']];
        }
        $digest = Canonicalizer::hash(Canonicalizer::encode($manifest));
        $updated = $wpdb->update(
            Schema::table('releases'),
            ['state' => self::STATE_SEALED, 'digest' => $digest, 'sealed_at' => current_time('mysql', true)],
            ['release_id' => (int) $release_id, 'state' => self::STATE_OPEN],
            ['%s', '%s', '%s'],
            ['%d', '%s']
        );

        return $updated === 1 ? $digest : null;
    }

    /**
     * @param int    $release_id
     * @param string $state
     * @return bool
     */
    public function set_state($release_id, $state)
    {
        global $wpdb;

        return $wpdb->update(Schema::table('releases'), ['state' => (string) $state], ['release_id' => (int) $release_id], ['%s'], ['%d']) === 1;
    }

    /**
     * @param string|null $source_environment_id
     * @param int         $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($source_environment_id = null, $limit = 200)
    {
        global $wpdb;

        $table = Schema::table('releases');
        if ($source_environment_id !== null && $source_environment_id !== '') {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE source_environment_id = %s ORDER BY release_id DESC LIMIT %d", (string) $source_environment_id, max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY release_id DESC LIMIT %d", max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * @return array<string, int>
     */
    public function counts()
    {
        global $wpdb;

        $table = Schema::table('releases');
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
        $row['release_id'] = (int) $row['release_id'];
        $row['item_count'] = (int) $row['item_count'];
        $row['created_by'] = (int) $row['created_by'];

        return $row;
    }
}
