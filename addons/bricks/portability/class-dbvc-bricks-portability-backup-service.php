<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Portability_Backup_Service
{
    /**
     * @param array<string, mixed> $session
     * @param array<int, string> $option_names
     * @param int $job_id
     * @return array<string, mixed>|\WP_Error
     */
    public static function create_backup(array $session, array $option_names, $job_id = 0)
    {
        $backup_id = DBVC_Bricks_Portability_Utils::generate_id('bricks-portability-backup');
        $backup_dir = DBVC_Bricks_Portability_Storage::resolve_backup_directory($backup_id);
        if (is_wp_error($backup_dir)) {
            return $backup_dir;
        }

        $option_snapshot = [];
        foreach ($option_names as $option_name) {
            $option_name = sanitize_key((string) $option_name);
            if ($option_name === '') {
                continue;
            }
            $option_snapshot[$option_name] = get_option($option_name, null);
        }

        $payload = [
            'record_type' => 'backup',
            'backup_id' => $backup_id,
            'created_at_gmt' => gmdate('c'),
            'job_id' => (int) $job_id,
            'session_id' => sanitize_key((string) ($session['session_id'] ?? '')),
            'package_id' => sanitize_key((string) ($session['package_id'] ?? '')),
            'actor_user_id' => get_current_user_id(),
            'option_names' => array_keys($option_snapshot),
            'checksum' => DBVC_Bricks_Portability_Utils::fingerprint($option_snapshot),
            'options' => $option_snapshot,
        ];

        $record = [
            'record_type' => 'backup',
            'backup_id' => $backup_id,
            'created_at_gmt' => $payload['created_at_gmt'],
            'job_id' => (int) $job_id,
            'session_id' => $payload['session_id'],
            'package_id' => $payload['package_id'],
            'option_names' => array_keys($option_snapshot),
        ];

        DBVC_Bricks_Portability_Storage::write_json_file($backup_dir, 'backup.json', $payload);
        DBVC_Bricks_Portability_Storage::write_json_file($backup_dir, 'record.json', $record);

        return $payload;
    }

    /**
     * @param string $backup_id
     * @return array<string, mixed>|\WP_Error
     */
    public static function load_backup($backup_id)
    {
        $backup_dir = DBVC_Bricks_Portability_Storage::resolve_backup_directory($backup_id);
        if (is_wp_error($backup_dir)) {
            return $backup_dir;
        }

        return DBVC_Bricks_Portability_Storage::read_json_file(wp_normalize_path(trailingslashit($backup_dir) . 'backup.json'));
    }

    /**
     * @param array<string, mixed> $backup
     * @return true|\WP_Error
     */
    public static function restore_backup(array $backup)
    {
        $options = isset($backup['options']) && is_array($backup['options']) ? $backup['options'] : [];
        foreach ($options as $option_name => $value) {
            $option_name = sanitize_key((string) $option_name);
            if ($option_name === '') {
                continue;
            }
            update_option($option_name, $value);
            $current = get_option($option_name, null);
            if (DBVC_Bricks_Portability_Utils::fingerprint($current) !== DBVC_Bricks_Portability_Utils::fingerprint($value)) {
                return new \WP_Error(
                    'dbvc_bricks_portability_backup_restore_failed',
                    sprintf(__('Failed to verify restored Bricks option `%s`.', 'dbvc'), $option_name),
                    ['status' => 500]
                );
            }
        }

        return true;
    }

    /**
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public static function list_recent_backups($limit = 10)
    {
        return DBVC_Bricks_Portability_Storage::list_records('backups', $limit);
    }
}
