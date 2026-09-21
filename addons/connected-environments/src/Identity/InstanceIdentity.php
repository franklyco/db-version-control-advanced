<?php

namespace Dbvc\Connected\Identity;

use Dbvc\Connected\Storage\Schema;

/**
 * DBVC-owned identity sidecar for Bricks collection members.
 *
 * Maps a domain's native storage key (the Bricks `id`) to a portable
 * `instance_uid`. Lookups are read-only; `assign()` is the explicit writer
 * used only by the observation worker. Object name and numeric position are
 * attributes, never identity. A same-client clone copies this table and so
 * preserves instance lineage, while environment identity is rotated
 * separately by enrollment.
 */
final class InstanceIdentity
{
    /**
     * @param string             $domain
     * @param array<int, string> $storage_keys
     * @return array<string, array{instance_uid: string, display_name: string}> Keyed by storage key; missing keys absent.
     */
    public function lookup_many($domain, array $storage_keys)
    {
        global $wpdb;

        $storage_keys = array_values(array_unique(array_filter(array_map('strval', $storage_keys), static function ($key) {
            return $key !== '';
        })));
        if ($storage_keys === []) {
            return [];
        }

        $table = Schema::table('identity');
        $placeholders = implode(',', array_fill(0, count($storage_keys), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT storage_key, instance_uid, display_name FROM {$table} WHERE domain = %s AND storage_key IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            array_merge([$domain], $storage_keys)
        ), ARRAY_A);

        $map = [];
        foreach ((array) $rows as $row) {
            $map[(string) $row['storage_key']] = [
                'instance_uid' => (string) $row['instance_uid'],
                'display_name' => (string) $row['display_name'],
            ];
        }

        return $map;
    }

    /**
     * Reverse lookup for one portable identity (prepare/apply resolve by UID, never by name or position).
     *
     * @param string $domain
     * @param string $instance_uid
     * @return array{storage_key: string, display_name: string}|null
     */
    public function find_by_instance_uid($domain, $instance_uid)
    {
        global $wpdb;

        $table = Schema::table('identity');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT storage_key, display_name FROM {$table} WHERE domain = %s AND instance_uid = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $domain,
            (string) $instance_uid
        ), ARRAY_A);

        return is_array($row) ? ['storage_key' => (string) $row['storage_key'], 'display_name' => (string) $row['display_name']] : null;
    }

    /**
     * @param string $domain
     * @return array<string, array{instance_uid: string, display_name: string}>
     */
    public function all_for_domain($domain)
    {
        global $wpdb;

        $table = Schema::table('identity');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT storage_key, instance_uid, display_name FROM {$table} WHERE domain = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $domain
        ), ARRAY_A);

        $map = [];
        foreach ((array) $rows as $row) {
            $map[(string) $row['storage_key']] = [
                'instance_uid' => (string) $row['instance_uid'],
                'display_name' => (string) $row['display_name'],
            ];
        }

        return $map;
    }

    /**
     * Explicit writer. Returns the existing mapping when present, otherwise
     * creates one. Concurrent assignment is resolved by the unique key.
     *
     * @param string $domain
     * @param string $storage_key
     * @param string $display_name
     * @return string|null instance_uid, or null when assignment failed.
     */
    public function assign($domain, $storage_key, $display_name = '')
    {
        global $wpdb;

        $existing = $this->lookup_many($domain, [$storage_key]);
        if (isset($existing[$storage_key])) {
            return $existing[$storage_key]['instance_uid'];
        }

        $instance_uid = $this->generate_uid($domain);
        $suppress = $wpdb->suppress_errors();
        $inserted = $wpdb->insert(
            Schema::table('identity'),
            [
                'domain' => $domain,
                'storage_key' => $storage_key,
                'instance_uid' => $instance_uid,
                'display_name' => mb_substr((string) $display_name, 0, 191),
                'created_at' => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );
        $wpdb->suppress_errors($suppress);

        if ($inserted === 1) {
            return $instance_uid;
        }

        $existing = $this->lookup_many($domain, [$storage_key]);

        return isset($existing[$storage_key]) ? $existing[$storage_key]['instance_uid'] : null;
    }

    /**
     * @return int
     */
    public function count()
    {
        global $wpdb;

        $table = Schema::table('identity');

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * @param string $domain
     * @return string
     */
    private function generate_uid($domain)
    {
        $prefix = preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $domain));
        $prefix = trim((string) $prefix, '-');
        if ($prefix === '') {
            $prefix = 'obj';
        }

        try {
            $random = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            unset($e);
            $random = strtolower(wp_generate_password(16, false, false));
        }

        return $prefix . '-' . $random;
    }
}
