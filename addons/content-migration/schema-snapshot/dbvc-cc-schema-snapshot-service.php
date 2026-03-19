<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Schema_Snapshot_Service
{
    /**
     * @return void
     */
    public static function maybe_generate_initial_snapshot()
    {
        $snapshot_path = self::get_snapshot_file_path();
        if ($snapshot_path === '' || file_exists($snapshot_path)) {
            return;
        }

        self::generate_snapshot();
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public static function generate_snapshot()
    {
        DBVC_CC_Artifact_Manager::ensure_storage_roots();
        $snapshot_dir = self::get_snapshot_dir();
        if ($snapshot_dir === '') {
            return new WP_Error('dbvc_cc_snapshot_storage_missing', 'Content migration storage path is unavailable.');
        }

        if (! dbvc_cc_create_security_files($snapshot_dir)) {
            return new WP_Error('dbvc_cc_snapshot_dir_create_failed', 'Could not create schema snapshot directory.');
        }

        $snapshot_payload = [
            'schema_version' => DBVC_CC_Contracts::ADDON_CONTRACT_VERSION,
            'generated_at' => current_time('c'),
            'site_url' => home_url('/'),
            'is_multisite' => is_multisite(),
            'post_types' => self::collect_post_type_snapshot(),
            'taxonomies' => self::collect_taxonomy_snapshot(),
            'terms' => self::collect_term_snapshot(),
            'users' => self::collect_user_snapshot(),
            'media' => self::collect_media_snapshot(),
            'fields' => self::collect_field_snapshot(),
        ];

        $snapshot_path = self::get_snapshot_file_path();
        if ($snapshot_path === '' || ! dbvc_cc_path_is_within($snapshot_path, DBVC_CC_Artifact_Manager::get_storage_base_dir())) {
            return new WP_Error('dbvc_cc_snapshot_path_invalid', 'Snapshot path is invalid.');
        }

        if (! DBVC_CC_Artifact_Manager::write_json_file($snapshot_path, $snapshot_payload)) {
            return new WP_Error('dbvc_cc_snapshot_write_failed', 'Could not persist schema snapshot artifact.');
        }

        DBVC_CC_Artifact_Manager::log_event([
            'stage' => 'schema_snapshot',
            'status' => 'completed',
            'object' => basename($snapshot_path),
        ]);

        return [
            'snapshot_file' => $snapshot_path,
            'generated_at' => $snapshot_payload['generated_at'],
        ];
    }

    /**
     * @return string
     */
    public static function get_snapshot_file_path()
    {
        $snapshot_dir = self::get_snapshot_dir();
        if ($snapshot_dir === '') {
            return '';
        }

        return trailingslashit($snapshot_dir) . DBVC_CC_Contracts::STORAGE_SCHEMA_SNAPSHOT_FILE;
    }

    /**
     * @return string
     */
    private static function get_snapshot_dir()
    {
        $base_dir = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        if ($base_dir === '') {
            return '';
        }

        return trailingslashit($base_dir) . DBVC_CC_Contracts::STORAGE_SCHEMA_SNAPSHOT_SUBDIR;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function collect_post_type_snapshot()
    {
        $objects = get_post_types([], 'objects');
        $snapshot = [];
        if (! is_array($objects)) {
            return $snapshot;
        }

        foreach ($objects as $post_type => $object) {
            if (! ($object instanceof WP_Post_Type)) {
                continue;
            }
            $snapshot[(string) $post_type] = [
                'label' => (string) $object->label,
                'description' => (string) $object->description,
                'public' => (bool) $object->public,
                'hierarchical' => (bool) $object->hierarchical,
                'supports' => get_all_post_type_supports($post_type),
                'taxonomies' => get_object_taxonomies($post_type),
                'rest_base' => (string) $object->rest_base,
                'menu_icon' => (string) $object->menu_icon,
            ];
        }

        ksort($snapshot);
        return $snapshot;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function collect_taxonomy_snapshot()
    {
        $objects = get_taxonomies([], 'objects');
        $snapshot = [];
        if (! is_array($objects)) {
            return $snapshot;
        }

        foreach ($objects as $taxonomy => $object) {
            if (! ($object instanceof WP_Taxonomy)) {
                continue;
            }
            $snapshot[(string) $taxonomy] = [
                'label' => (string) $object->label,
                'description' => (string) $object->description,
                'public' => (bool) $object->public,
                'hierarchical' => (bool) $object->hierarchical,
                'object_type' => is_array($object->object_type) ? array_values($object->object_type) : [],
                'rest_base' => (string) $object->rest_base,
            ];
        }

        ksort($snapshot);
        return $snapshot;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function collect_term_snapshot()
    {
        $taxonomy_objects = get_taxonomies([], 'objects');
        $snapshot = [];
        if (! is_array($taxonomy_objects)) {
            return $snapshot;
        }

        foreach (array_keys($taxonomy_objects) as $taxonomy) {
            $terms = get_terms(
                [
                    'taxonomy' => $taxonomy,
                    'hide_empty' => false,
                    'fields' => 'all',
                ]
            );
            if (! is_array($terms) || is_wp_error($terms)) {
                continue;
            }

            $term_entries = [];
            foreach ($terms as $term) {
                if (! ($term instanceof WP_Term)) {
                    continue;
                }
                $term_entries[] = [
                    'term_id' => (int) $term->term_id,
                    'slug' => (string) $term->slug,
                    'name' => (string) $term->name,
                    'parent' => (int) $term->parent,
                    'count' => (int) $term->count,
                ];
            }

            $snapshot[(string) $taxonomy] = [
                'count' => count($term_entries),
                'items' => $term_entries,
            ];
        }

        ksort($snapshot);
        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private static function collect_user_snapshot()
    {
        global $wp_roles;
        $roles = isset($wp_roles->roles) && is_array($wp_roles->roles) ? $wp_roles->roles : [];

        $role_map = [];
        foreach ($roles as $role_key => $role_data) {
            $role_map[(string) $role_key] = [
                'name' => isset($role_data['name']) ? (string) $role_data['name'] : '',
                'capabilities' => isset($role_data['capabilities']) && is_array($role_data['capabilities']) ? $role_data['capabilities'] : [],
            ];
        }
        ksort($role_map);

        $counts = count_users();
        return [
            'roles' => $role_map,
            'counts' => is_array($counts) ? $counts : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function collect_media_snapshot()
    {
        global $wpdb;

        $mime_rows = $wpdb->get_results(
            "SELECT post_mime_type AS mime, COUNT(ID) AS total FROM {$wpdb->posts} WHERE post_type = 'attachment' GROUP BY post_mime_type",
            ARRAY_A
        );

        $mime_counts = [];
        if (is_array($mime_rows)) {
            foreach ($mime_rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $mime = isset($row['mime']) ? (string) $row['mime'] : '';
                if ($mime === '') {
                    continue;
                }
                $mime_counts[$mime] = isset($row['total']) ? (int) $row['total'] : 0;
            }
        }
        ksort($mime_counts);

        return [
            'total_attachments' => (int) wp_count_posts('attachment')->inherit,
            'mime_counts' => $mime_counts,
            'registered_image_sizes' => get_intermediate_image_sizes(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function collect_field_snapshot()
    {
        global $wp_meta_keys;
        $field_map = [];

        if (! is_array($wp_meta_keys)) {
            return $field_map;
        }

        foreach ($wp_meta_keys as $object_type => $subtypes) {
            if (! is_array($subtypes)) {
                continue;
            }
            foreach ($subtypes as $subtype => $meta_keys) {
                if (! is_array($meta_keys)) {
                    continue;
                }

                $keys = array_keys($meta_keys);
                sort($keys);
                $field_map[(string) $object_type][(string) $subtype] = $keys;
            }
        }

        ksort($field_map);
        return $field_map;
    }
}
