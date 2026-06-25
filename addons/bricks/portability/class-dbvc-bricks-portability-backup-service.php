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
            'media_state' => [],
            'entity_state' => [],
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
     * @param array<string, mixed> $backup
     * @param array<string, mixed> $media_state
     * @return array<string, mixed>|\WP_Error
     */
    public static function record_media_state(array $backup, array $media_state)
    {
        $backup_id = sanitize_key((string) ($backup['backup_id'] ?? ''));
        if ($backup_id === '') {
            return new \WP_Error('dbvc_bricks_portability_backup_invalid', __('Bricks portability backup identifier is invalid.', 'dbvc'), ['status' => 500]);
        }

        $backup['media_state'] = self::sanitize_media_state($media_state);
        $backup['checksum'] = DBVC_Bricks_Portability_Utils::fingerprint([
            'options' => isset($backup['options']) && is_array($backup['options']) ? $backup['options'] : [],
            'media_state' => $backup['media_state'],
        ]);

        $backup_dir = DBVC_Bricks_Portability_Storage::resolve_backup_directory($backup_id);
        if (is_wp_error($backup_dir)) {
            return $backup_dir;
        }

        $write = DBVC_Bricks_Portability_Storage::write_json_file($backup_dir, 'backup.json', $backup);
        if (is_wp_error($write)) {
            return $write;
        }

        $record = [
            'record_type' => 'backup',
            'backup_id' => $backup_id,
            'created_at_gmt' => sanitize_text_field((string) ($backup['created_at_gmt'] ?? '')),
            'job_id' => (int) ($backup['job_id'] ?? 0),
            'session_id' => sanitize_key((string) ($backup['session_id'] ?? '')),
            'package_id' => sanitize_key((string) ($backup['package_id'] ?? '')),
            'option_names' => array_values((array) ($backup['option_names'] ?? [])),
            'media' => [
                'created_posts' => count((array) ($backup['media_state']['created_posts'] ?? [])),
                'created_attachments' => count((array) ($backup['media_state']['created_attachments'] ?? [])),
                'reused_attachments' => count((array) ($backup['media_state']['reused_attachments'] ?? [])),
            ],
        ];
        $record_write = DBVC_Bricks_Portability_Storage::write_json_file($backup_dir, 'record.json', $record);
        if (is_wp_error($record_write)) {
            return $record_write;
        }

        return $backup;
    }

    /**
     * @param array<string, mixed> $backup
     * @param array<string, mixed> $entity_state
     * @return array<string, mixed>|\WP_Error
     */
    public static function record_entity_state(array $backup, array $entity_state)
    {
        $backup_id = sanitize_key((string) ($backup['backup_id'] ?? ''));
        if ($backup_id === '') {
            return new \WP_Error('dbvc_bricks_portability_backup_invalid', __('Bricks portability backup identifier is invalid.', 'dbvc'), ['status' => 500]);
        }

        $backup['entity_state'] = self::sanitize_entity_state($entity_state);
        $backup['checksum'] = DBVC_Bricks_Portability_Utils::fingerprint([
            'options' => isset($backup['options']) && is_array($backup['options']) ? $backup['options'] : [],
            'media_state' => isset($backup['media_state']) && is_array($backup['media_state']) ? $backup['media_state'] : [],
            'entity_state' => $backup['entity_state'],
        ]);

        $backup_dir = DBVC_Bricks_Portability_Storage::resolve_backup_directory($backup_id);
        if (is_wp_error($backup_dir)) {
            return $backup_dir;
        }

        $write = DBVC_Bricks_Portability_Storage::write_json_file($backup_dir, 'backup.json', $backup);
        if (is_wp_error($write)) {
            return $write;
        }

        $record = self::build_backup_record($backup);
        $record_write = DBVC_Bricks_Portability_Storage::write_json_file($backup_dir, 'record.json', $record);
        if (is_wp_error($record_write)) {
            return $record_write;
        }

        return $backup;
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
            if ($value === null) {
                delete_option($option_name);
            } else {
                update_option($option_name, $value);
            }
            $current = get_option($option_name, null);
            if (DBVC_Bricks_Portability_Utils::fingerprint($current) !== DBVC_Bricks_Portability_Utils::fingerprint($value)) {
                return new \WP_Error(
                    'dbvc_bricks_portability_backup_restore_failed',
                    sprintf(__('Failed to verify restored Bricks option `%s`.', 'dbvc'), $option_name),
                    ['status' => 500]
                );
            }
        }

        $media_restore = self::restore_media_state(isset($backup['media_state']) && is_array($backup['media_state']) ? $backup['media_state'] : []);
        if (is_wp_error($media_restore)) {
            return $media_restore;
        }

        $entity_restore = self::restore_entity_state(isset($backup['entity_state']) && is_array($backup['entity_state']) ? $backup['entity_state'] : []);
        if (is_wp_error($entity_restore)) {
            return $entity_restore;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $media_state
     * @return true|\WP_Error
     */
    public static function restore_media_state(array $media_state)
    {
        foreach ((array) ($media_state['created_posts'] ?? []) as $created_post) {
            $post_id = is_array($created_post) ? (int) ($created_post['post_id'] ?? 0) : (int) $created_post;
            if ($post_id <= 0) {
                continue;
            }
            $post = get_post($post_id);
            if (! $post instanceof \WP_Post || $post->post_type !== 'bricks_fonts') {
                continue;
            }
            wp_delete_post($post_id, true);
        }

        foreach ((array) ($media_state['created_attachments'] ?? []) as $created_attachment) {
            $attachment_id = is_array($created_attachment) ? (int) ($created_attachment['attachment_id'] ?? 0) : (int) $created_attachment;
            if ($attachment_id <= 0) {
                continue;
            }
            $post = get_post($attachment_id);
            if (! $post instanceof \WP_Post || $post->post_type !== 'attachment') {
                continue;
            }
            wp_delete_attachment($attachment_id, true);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $entity_state
     * @return true|\WP_Error
     */
    public static function restore_entity_state(array $entity_state)
    {
        foreach ((array) ($entity_state['updated_posts'] ?? []) as $updated_post) {
            if (! is_array($updated_post) || empty($updated_post['before']) || ! is_array($updated_post['before'])) {
                continue;
            }
            if (sanitize_key((string) ($updated_post['post_type'] ?? '')) !== 'bricks_template') {
                continue;
            }
            $restore = DBVC_Bricks_Portability_Template_Apply_Service::restore_template_snapshot((array) $updated_post['before']);
            if (is_wp_error($restore)) {
                return $restore;
            }
        }

        foreach ((array) ($entity_state['created_posts'] ?? []) as $created_post) {
            $post_id = is_array($created_post) ? (int) ($created_post['post_id'] ?? 0) : (int) $created_post;
            if ($post_id <= 0) {
                continue;
            }
            $post = get_post($post_id);
            if (! $post instanceof \WP_Post || $post->post_type !== 'bricks_template') {
                continue;
            }
            wp_delete_post($post_id, true);
        }

        foreach ((array) ($entity_state['created_terms'] ?? []) as $created_term) {
            if (! is_array($created_term)) {
                continue;
            }
            $term_id = (int) ($created_term['term_id'] ?? 0);
            $taxonomy = sanitize_key((string) ($created_term['taxonomy'] ?? ''));
            if ($term_id <= 0 || $taxonomy === '' || ! taxonomy_exists($taxonomy)) {
                continue;
            }
            $objects = get_objects_in_term($term_id, $taxonomy);
            if (is_wp_error($objects) || ! empty($objects)) {
                continue;
            }
            wp_delete_term($term_id, $taxonomy);
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

    /**
     * @param array<string, mixed> $media_state
     * @return array<string, mixed>
     */
    private static function sanitize_media_state(array $media_state)
    {
        return [
            'created_posts' => array_values((array) ($media_state['created_posts'] ?? [])),
            'created_attachments' => array_values((array) ($media_state['created_attachments'] ?? [])),
            'reused_attachments' => array_values((array) ($media_state['reused_attachments'] ?? [])),
            'font_id_map' => array_map('intval', (array) ($media_state['font_id_map'] ?? [])),
            'font_value_map' => array_map('sanitize_text_field', (array) ($media_state['font_value_map'] ?? [])),
            'font_attachment_id_map' => array_map('intval', (array) ($media_state['font_attachment_id_map'] ?? [])),
            'icon_attachment_id_map' => array_map('intval', (array) ($media_state['icon_attachment_id_map'] ?? [])),
        ];
    }

    /**
     * @param array<string, mixed> $entity_state
     * @return array<string, mixed>
     */
    private static function sanitize_entity_state(array $entity_state)
    {
        return [
            'created_posts' => array_values((array) ($entity_state['created_posts'] ?? [])),
            'updated_posts' => array_values((array) ($entity_state['updated_posts'] ?? [])),
            'created_terms' => array_values((array) ($entity_state['created_terms'] ?? [])),
        ];
    }

    /**
     * @param array<string, mixed> $backup
     * @return array<string, mixed>
     */
    private static function build_backup_record(array $backup)
    {
        return [
            'record_type' => 'backup',
            'backup_id' => sanitize_key((string) ($backup['backup_id'] ?? '')),
            'created_at_gmt' => sanitize_text_field((string) ($backup['created_at_gmt'] ?? '')),
            'job_id' => (int) ($backup['job_id'] ?? 0),
            'session_id' => sanitize_key((string) ($backup['session_id'] ?? '')),
            'package_id' => sanitize_key((string) ($backup['package_id'] ?? '')),
            'option_names' => array_values((array) ($backup['option_names'] ?? [])),
            'media' => [
                'created_posts' => count((array) ($backup['media_state']['created_posts'] ?? [])),
                'created_attachments' => count((array) ($backup['media_state']['created_attachments'] ?? [])),
                'reused_attachments' => count((array) ($backup['media_state']['reused_attachments'] ?? [])),
            ],
            'entities' => [
                'created_posts' => count((array) ($backup['entity_state']['created_posts'] ?? [])),
                'updated_posts' => count((array) ($backup['entity_state']['updated_posts'] ?? [])),
                'created_terms' => count((array) ($backup['entity_state']['created_terms'] ?? [])),
            ],
        ];
    }
}
