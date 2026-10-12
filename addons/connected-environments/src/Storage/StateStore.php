<?php

namespace Dbvc\Connected\Storage;

/**
 * Singleton connector state: provisional environment identity, epoch,
 * sequence allocator and last worker status. Never stores secrets.
 */
final class StateStore
{
    public const ENROLLMENT_UNINITIALIZED = 'uninitialized';
    public const ENROLLMENT_PROVISIONAL = 'provisional';
    public const ENROLLMENT_ENROLLED = 'enrolled';

    public const CONNECTION_DISCONNECTED = 'disconnected';
    public const CONNECTION_ENROLLED = 'enrolled';
    public const CONNECTION_HELD = 'held';

    /**
     * @return array<string, mixed>|null
     */
    public function get()
    {
        global $wpdb;

        $table = Schema::table('state');
        $row = $wpdb->get_row("SELECT * FROM {$table} WHERE singleton_id = 1", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if (! is_array($row)) {
            return null;
        }

        $row['next_sequence'] = (int) $row['next_sequence'];
        $row['schema_version'] = (int) $row['schema_version'];
        $row['worker_status'] = $this->decode_json($row['worker_status']);
        $row['delivery_status'] = $this->decode_json($row['delivery_status'] ?? null);
        $row['inbox_status'] = $this->decode_json($row['inbox_status'] ?? null);
        $row['release_status'] = $this->decode_json($row['release_status'] ?? null);
        $row['inbox_cursor'] = isset($row['inbox_cursor']) ? (int) $row['inbox_cursor'] : 0;
        foreach (['hub_url', 'hub_agency_id', 'hub_client_id', 'hub_principal', 'connection_state', 'hold_reason', 'enrolled_site_url'] as $key) {
            $row[$key] = isset($row[$key]) ? (string) $row[$key] : '';
        }
        if ($row['connection_state'] === '') {
            $row['connection_state'] = self::CONNECTION_DISCONNECTED;
        }
        // The encrypted secret never leaves the store through get(); use hub_secret() explicitly.
        $row['has_hub_secret'] = isset($row['hub_secret']) && $row['hub_secret'] !== null && $row['hub_secret'] !== '';
        unset($row['hub_secret']);

        return $row;
    }

    /**
     * Encrypted hub secret, decrypted with this installation's salts.
     *
     * @return string|null Null when absent or undecryptable (for example a clone with different salts).
     */
    public function hub_secret()
    {
        global $wpdb;

        $table = Schema::table('state');
        $encrypted = $wpdb->get_var("SELECT hub_secret FROM {$table} WHERE singleton_id = 1"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        return \Dbvc\Connected\Lifecycle\SecretBox::open($encrypted);
    }

    /**
     * Explicit writer used only by enrollment: adopt the hub-accepted identity
     * and epoch, store the credential encrypted, and mark the connection.
     *
     * @param array<string, mixed> $enrollment environment_id, installation_epoch, hub_url, agency_id, client_id, principal, secret, site_url
     * @return bool
     */
    public function record_enrollment(array $enrollment)
    {
        global $wpdb;

        $sealed = \Dbvc\Connected\Lifecycle\SecretBox::seal((string) $enrollment['secret']);
        if ($sealed === null) {
            return false;
        }
        $updated = $wpdb->update(
            Schema::table('state'),
            [
                'environment_id' => (string) $enrollment['environment_id'],
                'installation_epoch' => (string) $enrollment['installation_epoch'],
                // Sequence lifetimes are per epoch: a new epoch starts at 1 (uniqueness is (epoch, sequence)).
                'next_sequence' => 1,
                'enrollment_state' => self::ENROLLMENT_ENROLLED,
                'hub_url' => (string) $enrollment['hub_url'],
                'hub_agency_id' => (string) $enrollment['agency_id'],
                'hub_client_id' => (string) $enrollment['client_id'],
                'hub_principal' => mb_substr((string) $enrollment['principal'], 0, 191),
                'hub_secret' => $sealed,
                'connection_state' => self::CONNECTION_ENROLLED,
                'hold_reason' => '',
                'enrolled_site_url' => (string) $enrollment['site_url'],
                'inbox_cursor' => 0,
                'updated_at' => current_time('mysql', true),
            ],
            ['singleton_id' => 1],
            ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'],
            ['%d']
        );

        return $updated !== false;
    }

    /**
     * @param string $state
     * @param string $reason
     * @return void
     */
    public function set_connection_state($state, $reason = '')
    {
        global $wpdb;

        $wpdb->update(
            Schema::table('state'),
            ['connection_state' => (string) $state, 'hold_reason' => substr((string) $reason, 0, 128), 'updated_at' => current_time('mysql', true)],
            ['singleton_id' => 1],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * Advance the inbox cursor only past durably stored and acknowledged deliveries.
     *
     * @param int                  $cursor
     * @param array<string, mixed> $status
     * @return void
     */
    public function record_inbox_progress($cursor, array $status)
    {
        global $wpdb;

        $wpdb->update(
            Schema::table('state'),
            ['inbox_cursor' => max(0, (int) $cursor), 'inbox_status' => wp_json_encode($status), 'updated_at' => current_time('mysql', true)],
            ['singleton_id' => 1],
            ['%d', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * @param array<string, mixed> $status
     * @return void
     */
    public function record_release_status(array $status)
    {
        global $wpdb;

        $wpdb->update(
            Schema::table('state'),
            ['release_status' => wp_json_encode($status), 'updated_at' => current_time('mysql', true)],
            ['singleton_id' => 1],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * @param array<string, mixed> $status
     * @return void
     */
    public function record_delivery_status(array $status)
    {
        global $wpdb;

        $wpdb->update(
            Schema::table('state'),
            ['delivery_status' => wp_json_encode($status), 'updated_at' => current_time('mysql', true)],
            ['singleton_id' => 1],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Identity has been explicitly initialized (provisional or enrolled).
     *
     * @return bool
     */
    public function is_initialized()
    {
        $row = $this->get();

        return is_array($row)
            && $row['enrollment_state'] !== self::ENROLLMENT_UNINITIALIZED
            && $row['environment_id'] !== ''
            && $row['installation_epoch'] !== '';
    }

    /**
     * Explicit writer: create the provisional local identity when absent.
     * Never rotates an existing identity; M2 enrollment owns rotation.
     *
     * @return array<string, mixed> The current state row.
     */
    public function initialize_provisional()
    {
        global $wpdb;

        $existing = $this->get();
        if (is_array($existing) && $existing['enrollment_state'] !== self::ENROLLMENT_UNINITIALIZED) {
            return $existing;
        }

        $now = current_time('mysql', true);
        $environment_id = 'local-' . $this->random_token(8);
        $epoch = 'provisional-' . gmdate('Ymd') . '-' . $this->random_token(6);

        if (is_array($existing)) {
            $wpdb->update(
                Schema::table('state'),
                [
                    'environment_id' => $environment_id,
                    'installation_epoch' => $epoch,
                    'enrollment_state' => self::ENROLLMENT_PROVISIONAL,
                    'schema_version' => Schema::SCHEMA_VERSION,
                    'updated_at' => $now,
                ],
                ['singleton_id' => 1],
                ['%s', '%s', '%s', '%d', '%s'],
                ['%d']
            );
        } else {
            $wpdb->insert(
                Schema::table('state'),
                [
                    'singleton_id' => 1,
                    'environment_id' => $environment_id,
                    'installation_epoch' => $epoch,
                    'enrollment_state' => self::ENROLLMENT_PROVISIONAL,
                    'next_sequence' => 1,
                    'schema_version' => Schema::SCHEMA_VERSION,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
            );
        }

        return $this->get();
    }

    /**
     * Atomically allocate the next source sequence for the current epoch.
     *
     * @return int|null Null when the state row is missing.
     */
    public function allocate_sequence()
    {
        global $wpdb;

        $table = Schema::table('state');
        $updated = $wpdb->query(
            "UPDATE {$table} SET next_sequence = LAST_INSERT_ID(next_sequence) + 1 WHERE singleton_id = 1" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
        if ($updated !== 1) {
            return null;
        }

        $sequence = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
        if ($sequence < 1 || $sequence > \Dbvc\ConnectedProtocol\ObservationEvent::MAX_SEQUENCE) {
            return null;
        }

        return $sequence;
    }

    /**
     * @param array<string, mixed> $status
     * @return void
     */
    public function record_worker_status(array $status)
    {
        global $wpdb;

        $wpdb->update(
            Schema::table('state'),
            [
                'worker_status' => wp_json_encode($status),
                'updated_at' => current_time('mysql', true),
            ],
            ['singleton_id' => 1],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * @param int $bytes
     * @return string
     */
    private function random_token($bytes)
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (\Throwable $e) {
            unset($e);
            return strtolower(wp_generate_password($bytes * 2, false, false));
        }
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private function decode_json($value)
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
