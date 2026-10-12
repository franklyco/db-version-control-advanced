<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * Immutable framework definition versions. A version row is never edited
 * after publication; ordering within a definition comes from the studio's
 * explicit `version_order`, never from comparing version strings. The
 * `desired` marker names the version a channel currently expects.
 */
final class DefinitionStore
{
    /**
     * @param array<string, mixed> $data
     * @return string created|exists|order_exists|error
     */
    public function publish(array $data)
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors();
        $inserted = $wpdb->insert(
            Schema::table('definitions'),
            [
                'agency_id' => (string) $data['agency_id'],
                'definition_uid' => (string) $data['definition_uid'],
                'version' => (string) $data['version'],
                'version_order' => (int) $data['version_order'],
                'channel' => (string) ($data['channel'] ?? 'stable'),
                'domain' => (string) $data['domain'],
                'profile' => (string) $data['profile'],
                'definition_hash' => (string) $data['definition_hash'],
                'desired' => ! empty($data['desired']) ? 1 : 0,
                'source_environment_id' => (string) ($data['source_environment_id'] ?? ''),
                'source_instance_uid' => (string) ($data['source_instance_uid'] ?? ''),
                'published_by' => (int) ($data['published_by'] ?? 0),
                'published_at' => current_time('mysql', true),
                'note' => mb_substr((string) ($data['note'] ?? ''), 0, 191),
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s']
        );
        $wpdb->suppress_errors($suppress);
        if ($inserted === 1) {
            return 'created';
        }

        $error = (string) $wpdb->last_error;
        if (stripos($error, 'duplicate') === false) {
            return 'error';
        }

        return stripos($error, 'definition_order') !== false ? 'order_exists' : 'exists';
    }

    /**
     * Make one version the channel's desired version (clearing the previous one).
     *
     * @param string $agency_id
     * @param string $definition_uid
     * @param string $channel
     * @param string $version
     * @return bool
     */
    public function set_desired($agency_id, $definition_uid, $channel, $version)
    {
        global $wpdb;

        $table = Schema::table('definitions');
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET desired = 0 WHERE agency_id = %s AND definition_uid = %s AND channel = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $agency_id,
            (string) $definition_uid,
            (string) $channel
        ));
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET desired = 1 WHERE agency_id = %s AND definition_uid = %s AND channel = %s AND version = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $agency_id,
            (string) $definition_uid,
            (string) $channel,
            (string) $version
        ));

        return $updated === 1;
    }

    /**
     * @param string $agency_id
     * @param string $definition_uid
     * @param string $version
     * @return array<string, mixed>|null
     */
    public function get($agency_id, $definition_uid, $version)
    {
        global $wpdb;

        $table = Schema::table('definitions');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE agency_id = %s AND definition_uid = %s AND version = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $agency_id,
            (string) $definition_uid,
            (string) $version
        ), ARRAY_A);

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @param string $agency_id
     * @param string $definition_uid
     * @param string $channel
     * @return array<string, mixed>|null
     */
    public function desired($agency_id, $definition_uid, $channel)
    {
        global $wpdb;

        $table = Schema::table('definitions');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE agency_id = %s AND definition_uid = %s AND channel = %s AND desired = 1 ORDER BY version_order DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $agency_id,
            (string) $definition_uid,
            (string) $channel
        ), ARRAY_A);

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @param string $agency_id
     * @param string $definition_uid
     * @return int
     */
    public function next_version_order($agency_id, $definition_uid)
    {
        global $wpdb;

        $table = Schema::table('definitions');
        $max = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(version_order) FROM {$table} WHERE agency_id = %s AND definition_uid = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $agency_id,
            (string) $definition_uid
        ));

        return (int) $max + 1;
    }

    /**
     * @param string|null $definition_uid
     * @param int         $limit
     * @return array<int, array<string, mixed>>
     */
    public function all($definition_uid = null, $limit = 200)
    {
        global $wpdb;

        $table = Schema::table('definitions');
        if ($definition_uid !== null && $definition_uid !== '') {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE definition_uid = %s ORDER BY channel, version_order LIMIT %d", (string) $definition_uid, max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY definition_uid, channel, version_order LIMIT %d", max(1, (int) $limit)), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        return array_map([$this, 'normalize'], is_array($rows) ? $rows : []);
    }

    /**
     * @return int
     */
    public function count()
    {
        global $wpdb;

        $table = Schema::table('definitions');

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row)
    {
        $row['definition_row_id'] = (int) $row['definition_row_id'];
        $row['version_order'] = (int) $row['version_order'];
        $row['desired'] = (int) $row['desired'];

        return $row;
    }
}
