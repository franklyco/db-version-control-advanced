<?php

namespace Dbvc\Connected\Storage;

/**
 * Dirty-object markers with generations and worker leases.
 *
 * One row per (domain, object_key). Every save-side signal is a single
 * atomic upsert that bumps the generation; the worker claims rows with a
 * compare-and-set lease and acknowledges only the generation it captured at
 * claim time. A newer generation therefore survives an in-flight processing
 * run and stays pending.
 */
final class JobStore
{
    public const OUTCOME_ACKNOWLEDGED = 'acknowledged';
    public const OUTCOME_GENERATION_ADVANCED = 'generation_advanced';
    public const OUTCOME_LEASE_LOST = 'lease_lost';

    /**
     * Atomic upsert/increment. Returns the resulting generation.
     *
     * @param string               $domain
     * @param string               $object_key
     * @param array<string, mixed> $context   Small allowlisted hints (origin, causation_id, source_hook).
     * @param int                  $delay_seconds
     * @return int|null
     */
    public function mark_dirty($domain, $object_key, array $context, $delay_seconds)
    {
        global $wpdb;

        $table = Schema::table('jobs');
        $now = current_time('mysql', true);
        $available_at = gmdate('Y-m-d H:i:s', time() + max(0, (int) $delay_seconds));
        $context_json = wp_json_encode($context);
        if (! is_string($context_json)) {
            $context_json = '{}';
        }

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (domain, object_key, generation, claimed_generation, attempts, available_at, last_signal_context, created_at, updated_at)
             VALUES (%s, %s, 1, 0, 0, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                generation = generation + 1,
                available_at = LEAST(available_at, VALUES(available_at)),
                last_signal_context = VALUES(last_signal_context),
                updated_at = VALUES(updated_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $domain,
            $object_key,
            $available_at,
            $context_json,
            $now,
            $now
        ));
        if ($result === false) {
            return null;
        }

        $generation = $wpdb->get_var($wpdb->prepare(
            "SELECT generation FROM {$table} WHERE domain = %s AND object_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $domain,
            $object_key
        ));

        return $generation === null ? null : (int) $generation;
    }

    /**
     * Atomically claim due, unleased jobs.
     *
     * @param int $limit
     * @param int $lease_seconds
     * @return array<int, array<string, mixed>> Claims with job_id, domain, object_key, generation, lease_token, lease_until, attempts.
     */
    public function claim_due($limit, $lease_seconds)
    {
        global $wpdb;

        $table = Schema::table('jobs');
        $limit = max(1, (int) $limit);
        $now_ts = time();
        $now = gmdate('Y-m-d H:i:s', $now_ts);
        $lease_until = gmdate('Y-m-d H:i:s', $now_ts + max(5, (int) $lease_seconds));

        $candidates = $wpdb->get_col($wpdb->prepare(
            "SELECT job_id FROM {$table}
             WHERE available_at <= %s AND (lease_until IS NULL OR lease_until < %s)
             ORDER BY available_at ASC, job_id ASC
             LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $now,
            $now,
            $limit
        ));

        $claims = [];
        foreach ((array) $candidates as $job_id) {
            $token = $this->random_token();
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET lease_token = %s, lease_until = %s, claimed_generation = generation, attempts = attempts + 1, updated_at = %s
                 WHERE job_id = %d AND available_at <= %s AND (lease_until IS NULL OR lease_until < %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $token,
                $lease_until,
                $now,
                (int) $job_id,
                $now,
                $now
            ));
            if ($updated !== 1) {
                continue;
            }

            $row = $this->get((int) $job_id);
            if (! is_array($row) || $row['lease_token'] !== $token) {
                continue;
            }

            $claims[] = [
                'job_id' => (int) $row['job_id'],
                'domain' => (string) $row['domain'],
                'object_key' => (string) $row['object_key'],
                'generation' => (int) $row['claimed_generation'],
                'lease_token' => $token,
                'lease_until' => $lease_until,
                'attempts' => (int) $row['attempts'],
                'context' => $this->decode_context($row['last_signal_context']),
            ];
        }

        return $claims;
    }

    /**
     * Generation-aware acknowledgement under the claim's lease.
     *
     * @param array<string, mixed> $claim
     * @param int                  $requeue_delay_seconds Delay applied when a newer generation remains pending.
     * @return string One of the OUTCOME_* constants.
     */
    public function complete(array $claim, $requeue_delay_seconds = 0)
    {
        global $wpdb;

        $table = Schema::table('jobs');
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE job_id = %d AND lease_token = %s AND generation = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (int) $claim['job_id'],
            (string) $claim['lease_token'],
            (int) $claim['generation']
        ));
        if ($deleted === 1) {
            return self::OUTCOME_ACKNOWLEDGED;
        }

        $row = $this->get((int) $claim['job_id']);
        if (! is_array($row) || $row['lease_token'] !== (string) $claim['lease_token']) {
            return self::OUTCOME_LEASE_LOST;
        }

        // Newer generation arrived during processing: release the lease and keep the marker pending.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET lease_token = NULL, lease_until = NULL, available_at = %s, updated_at = %s
             WHERE job_id = %d AND lease_token = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            gmdate('Y-m-d H:i:s', time() + max(0, (int) $requeue_delay_seconds)),
            current_time('mysql', true),
            (int) $claim['job_id'],
            (string) $claim['lease_token']
        ));

        return self::OUTCOME_GENERATION_ADVANCED;
    }

    /**
     * Token-checked failure: release the lease, record the reason and defer.
     *
     * @param array<string, mixed> $claim
     * @param string               $reason
     * @param int                  $available_at_ts
     * @return bool
     */
    public function retry(array $claim, $reason, $available_at_ts)
    {
        global $wpdb;

        $table = Schema::table('jobs');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET lease_token = NULL, lease_until = NULL, last_error_code = %s, available_at = %s, updated_at = %s
             WHERE job_id = %d AND lease_token = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            substr((string) $reason, 0, 128),
            gmdate('Y-m-d H:i:s', max(time(), (int) $available_at_ts)),
            current_time('mysql', true),
            (int) $claim['job_id'],
            (string) $claim['lease_token']
        ));

        return $updated === 1;
    }

    /**
     * @param int $job_id
     * @return array<string, mixed>|null
     */
    public function get($job_id)
    {
        global $wpdb;

        $table = Schema::table('jobs');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE job_id = %d", (int) $job_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? $row : null;
    }

    /**
     * @param string $domain
     * @param string $object_key
     * @return array<string, mixed>|null
     */
    public function find($domain, $object_key)
    {
        global $wpdb;

        $table = Schema::table('jobs');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE domain = %s AND object_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $domain,
            $object_key
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($limit = 100)
    {
        global $wpdb;

        $table = Schema::table('jobs');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY available_at ASC, job_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            max(1, (int) $limit)
        ), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array{total: int, due: int, leased: int, errored: int, oldest_pending_age: int|null}
     */
    public function counts()
    {
        global $wpdb;

        $table = Schema::table('jobs');
        $now = gmdate('Y-m-d H:i:s');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN available_at <= %s AND (lease_until IS NULL OR lease_until < %s) THEN 1 ELSE 0 END) AS due,
                    SUM(CASE WHEN lease_until IS NOT NULL AND lease_until >= %s THEN 1 ELSE 0 END) AS leased,
                    SUM(CASE WHEN last_error_code IS NOT NULL AND last_error_code <> '' THEN 1 ELSE 0 END) AS errored,
                    MIN(created_at) AS oldest_created_at
             FROM {$table}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $now,
            $now,
            $now
        ), ARRAY_A);

        $oldest = null;
        if (is_array($row) && ! empty($row['oldest_created_at'])) {
            $oldest = max(0, time() - (int) strtotime((string) $row['oldest_created_at'] . ' UTC'));
        }

        return [
            'total' => is_array($row) ? (int) $row['total'] : 0,
            'due' => is_array($row) ? (int) $row['due'] : 0,
            'leased' => is_array($row) ? (int) $row['leased'] : 0,
            'errored' => is_array($row) ? (int) $row['errored'] : 0,
            'oldest_pending_age' => $oldest,
        ];
    }

    /**
     * @return string
     */
    private function random_token()
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            unset($e);
            return strtolower(wp_generate_password(32, false, false));
        }
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function decode_context($value)
    {
        if (! is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
