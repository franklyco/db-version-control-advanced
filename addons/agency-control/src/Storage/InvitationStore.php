<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * Single-use, expiring enrollment invitations. Only the SHA-256 of the
 * token is stored; the plaintext is shown once to the administrator who
 * created it.
 */
final class InvitationStore
{
    public const DEFAULT_TTL_SECONDS = 3600;
    public const MAX_TTL_SECONDS = 604800;

    /**
     * @param array<string, mixed> $data agency_id, client_id, environment_label, environment_id (optional fixed id), ttl_seconds, created_by
     * @return array{invitation_id: int, token: string, expires_at: string}|null
     */
    public function create(array $data)
    {
        global $wpdb;

        $token = 'inv-' . bin2hex(random_bytes(24));
        $ttl = isset($data['ttl_seconds']) ? max(60, min(self::MAX_TTL_SECONDS, (int) $data['ttl_seconds'])) : self::DEFAULT_TTL_SECONDS;
        $expires_at = gmdate('Y-m-d H:i:s', time() + $ttl);
        $inserted = $wpdb->insert(
            Schema::table('invitations'),
            [
                'token_hash' => hash('sha256', $token),
                'agency_id' => (string) $data['agency_id'],
                'client_id' => (string) $data['client_id'],
                'environment_label' => mb_substr((string) ($data['environment_label'] ?? ''), 0, 191),
                'environment_id' => (string) ($data['environment_id'] ?? ''),
                'expires_at' => $expires_at,
                'created_by' => isset($data['created_by']) ? (int) $data['created_by'] : null,
                'created_at' => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s']
        );
        if ($inserted !== 1) {
            return null;
        }

        return ['invitation_id' => (int) $wpdb->insert_id, 'token' => $token, 'expires_at' => $expires_at];
    }

    /**
     * @param string $token
     * @return array<string, mixed>|null
     */
    public function find_by_token($token)
    {
        global $wpdb;

        $table = Schema::table('invitations');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE token_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            hash('sha256', (string) $token)
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * Atomic single-use consumption.
     *
     * @param int    $invitation_id
     * @param string $environment_id
     * @return bool
     */
    public function consume($invitation_id, $environment_id)
    {
        global $wpdb;

        $table = Schema::table('invitations');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET consumed_at = %s, consumed_environment_id = %s WHERE invitation_id = %d AND consumed_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            current_time('mysql', true),
            (string) $environment_id,
            (int) $invitation_id
        ));

        return $updated === 1;
    }

    /**
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($limit = 100)
    {
        global $wpdb;

        $table = Schema::table('invitations');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT invitation_id, agency_id, client_id, environment_label, environment_id, expires_at, consumed_at, consumed_environment_id, created_by, created_at FROM {$table} ORDER BY invitation_id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            max(1, (int) $limit)
        ), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }
}
