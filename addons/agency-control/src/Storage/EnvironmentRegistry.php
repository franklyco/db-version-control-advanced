<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * Trusted enrollment registry. Agency/client/environment/epoch bindings are
 * read from here after authentication; never from observation fields.
 */
final class EnvironmentRegistry
{
    public const STATUS_ENABLED = 'enabled';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_HELD = 'held';

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function create(array $data)
    {
        global $wpdb;

        $now = current_time('mysql', true);
        $suppress = $wpdb->suppress_errors();
        $inserted = $wpdb->insert(
            Schema::table('environments'),
            [
                'agency_id' => (string) $data['agency_id'],
                'client_id' => (string) $data['client_id'],
                'environment_id' => (string) $data['environment_id'],
                'label' => mb_substr((string) ($data['label'] ?? ''), 0, 191),
                'current_epoch' => (string) $data['current_epoch'],
                'epoch_changed_at' => $now,
                'principal_user_id' => (int) $data['principal_user_id'],
                'principal_uuid' => (string) $data['principal_uuid'],
                'status' => self::STATUS_ENABLED,
                'hold_reason' => '',
                'enrolled_site_url' => (string) ($data['site_url'] ?? ''),
                'last_site_url' => (string) ($data['site_url'] ?? ''),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        $wpdb->suppress_errors($suppress);

        return $inserted === 1 ? $this->find((string) $data['environment_id']) : null;
    }

    /**
     * @param string $environment_id
     * @return array<string, mixed>|null
     */
    public function find($environment_id)
    {
        global $wpdb;

        $table = Schema::table('environments');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE environment_id = %s", (string) $environment_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * Resolve an authenticated principal (user + application password UUID)
     * to its enrollment. Both parts must match; a different application
     * password on the same user is not enrollment authority.
     *
     * @param int    $user_id
     * @param string $app_password_uuid
     * @return array<string, mixed>|null
     */
    public function find_by_principal($user_id, $app_password_uuid)
    {
        global $wpdb;

        if ((int) $user_id <= 0 || (string) $app_password_uuid === '') {
            return null;
        }
        $table = Schema::table('environments');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE principal_user_id = %d AND principal_uuid = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (int) $user_id,
            (string) $app_password_uuid
        ), ARRAY_A);

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @param string               $environment_id
     * @param array<string, mixed> $fields
     * @return bool
     */
    public function update($environment_id, array $fields)
    {
        global $wpdb;

        $fields['updated_at'] = current_time('mysql', true);
        $formats = [];
        foreach ($fields as $key => $value) {
            $formats[] = in_array($key, ['principal_user_id', 'max_received_sequence', 'received_events'], true) ? '%d' : '%s';
        }
        $updated = $wpdb->update(Schema::table('environments'), $fields, ['environment_id' => (string) $environment_id], $formats, ['%s']);

        return $updated !== false;
    }

    /**
     * Record durable receipt bookkeeping for one batch.
     *
     * @param string $environment_id
     * @param int    $accepted
     * @param int    $max_sequence
     * @param string $batch_id
     * @param string $site_url
     * @return void
     */
    public function record_contact($environment_id, $accepted, $max_sequence, $batch_id, $site_url)
    {
        global $wpdb;

        $table = Schema::table('environments');
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET last_contact_at = %s, last_batch_id = %s, last_site_url = %s,
                 received_events = received_events + %d,
                 max_received_sequence = GREATEST(max_received_sequence, %d),
                 updated_at = %s
             WHERE environment_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            current_time('mysql', true),
            substr((string) $batch_id, 0, 128),
            (string) $site_url,
            (int) $accepted,
            (int) $max_sequence,
            current_time('mysql', true),
            (string) $environment_id
        ));
    }

    /**
     * @param string|null $client_id
     * @param int         $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($client_id = null, $limit = 200)
    {
        global $wpdb;

        $table = Schema::table('environments');
        if ($client_id !== null && $client_id !== '') {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE client_id = %s ORDER BY agency_id, client_id, environment_id LIMIT %d", (string) $client_id, max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY agency_id, client_id, environment_id LIMIT %d", max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row)
    {
        $row['principal_user_id'] = (int) $row['principal_user_id'];
        $row['max_received_sequence'] = (int) $row['max_received_sequence'];
        $row['received_events'] = (int) $row['received_events'];

        return $row;
    }
}
