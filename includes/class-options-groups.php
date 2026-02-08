<?php

/**
 * ACF options group export/import handling.
 *
 * @package DB Version Control
 */

if (! defined('WPINC')) {
    die;
}

if (! class_exists('DBVC_Options_Groups')) {
    class DBVC_Options_Groups
    {
        public const OPTION_SELECTED = 'dbvc_options_groups';
        public const OPTIONS_DIR = 'options';

        /**
         * Get selected options group IDs.
         *
         * @return array
         */
        public static function get_selected_group_ids(): array
        {
            $stored = get_option(self::OPTION_SELECTED, []);
            if (! is_array($stored)) {
                $stored = [];
            }

            $sanitized = [];
            foreach ($stored as $value) {
                $value = is_string($value) ? trim($value) : '';
                if ($value === '') {
                    continue;
                }
                $sanitized[] = sanitize_text_field($value);
            }

            $sanitized = array_values(array_unique($sanitized));

            return apply_filters('dbvc_selected_options_groups', $sanitized);
        }

        /**
         * Return available ACF options groups for configuration UI.
         *
         * @return array
         */
        public static function get_available_groups(): array
        {
            if (! function_exists('acf_get_field_groups')) {
                return [];
            }

            $field_groups = acf_get_field_groups();
            $local_files  = function_exists('acf_get_local_json_files') ? acf_get_local_json_files() : [];
            $pages        = self::get_acf_options_pages();

            $group_entries = [];
            $page_slugs    = [];

            foreach ($field_groups as $group) {
                if (! is_array($group)) {
                    continue;
                }

                $group_key = isset($group['key']) ? (string) $group['key'] : '';
                if ($group_key === '') {
                    continue;
                }

                $locations = isset($group['location']) ? $group['location'] : [];
                $slugs     = self::extract_options_page_slugs($locations);
                if (empty($slugs)) {
                    continue;
                }

                foreach ($slugs as $slug) {
                    $page_slugs[$slug] = true;
                }

                $source = isset($local_files[$group_key]) ? 'local' : 'database';
                $label  = isset($group['title']) ? (string) $group['title'] : $group_key;

                $group_entries[] = [
                    'id'        => 'group:' . $group_key,
                    'type'      => 'field_group',
                    'group_key' => $group_key,
                    'label'     => $label . ' (Field Group)',
                    'source'    => $source,
                    'locations' => array_values(array_unique($slugs)),
                ];
            }

            if (! empty($pages)) {
                foreach ($pages as $slug => $page) {
                    $page_slugs[$slug] = true;
                }
            }

            $page_entries = [];
            foreach (array_keys($page_slugs) as $slug) {
                $page   = isset($pages[$slug]) ? $pages[$slug] : [];
                $label  = self::get_options_page_label($slug, $page);
                $post_id = self::resolve_options_post_id($slug, $page);

                $page_entries[] = [
                    'id'      => 'page:' . $slug,
                    'type'    => 'options_page',
                    'slug'    => $slug,
                    'label'   => $label,
                    'post_id' => $post_id,
                    'source'  => 'acf',
                ];
            }

            $entries = array_merge($page_entries, $group_entries);

            usort($entries, function ($a, $b) {
                return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
            });

            return apply_filters('dbvc_available_options_groups', $entries);
        }

        /**
         * Export selected options groups to the /options/ folder.
         *
         * @return void
         */
        public static function export_selected_groups(): int
        {
            if (! function_exists('acf_get_field_objects')) {
                return 0;
            }

            $selected = self::get_selected_group_ids();
            if (empty($selected)) {
                return 0;
            }

            $available = self::get_available_groups();
            $available_map = [];
            foreach ($available as $entry) {
                if (! empty($entry['id'])) {
                    $available_map[$entry['id']] = $entry;
                }
            }

            $selected = array_values(array_intersect($selected, array_keys($available_map)));
            if (empty($selected)) {
                return 0;
            }

            $sync_root   = trailingslashit(dbvc_get_sync_path());
            $options_dir = $sync_root . self::OPTIONS_DIR;
            if (! is_dir($options_dir)) {
                if (! wp_mkdir_p($options_dir)) {
                    error_log('DBVC: Failed to create options group export directory: ' . $options_dir);
                    return 0;
                }
            }

            if (class_exists('DBVC_Sync_Posts')) {
                DBVC_Sync_Posts::ensure_directory_security($options_dir);
            }

            $exported = 0;
            foreach ($selected as $group_id) {
                $entry = $available_map[$group_id] ?? null;
                if (! is_array($entry)) {
                    continue;
                }

                $payload = self::build_export_payload($entry);
                if (empty($payload)) {
                    continue;
                }

                $file_name = self::build_export_filename($entry, $payload);
                $file_path = trailingslashit($options_dir) . $file_name;

                $file_path = apply_filters('dbvc_export_options_group_file_path', $file_path, $entry, $payload);

                if (! dbvc_is_safe_file_path($file_path)) {
                    error_log('DBVC: Unsafe options group export path: ' . $file_path);
                    continue;
                }

                $json_content = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if (false === $json_content) {
                    error_log('DBVC: Failed to encode options group JSON for ' . $group_id);
                    continue;
                }

                if (false === file_put_contents($file_path, $json_content)) {
                    error_log('DBVC: Failed to write options group export: ' . $file_path);
                    continue;
                }

                do_action('dbvc_after_export_options_group', $file_path, $payload, $entry);
                $exported++;
            }

            return $exported;
        }

        /**
         * Import selected options groups from the sync folder.
         *
         * @return int Number of groups imported.
         */
        public static function import_selected_groups_from_sync(): int
        {
            $sync_root   = trailingslashit(dbvc_get_sync_path());
            $options_dir = $sync_root . self::OPTIONS_DIR;
            if (! is_dir($options_dir)) {
                return 0;
            }

            $files = glob(trailingslashit($options_dir) . '*.json');
            if (empty($files)) {
                return 0;
            }

            $imported = 0;
            foreach ($files as $file) {
                if (self::import_group_from_file($file)) {
                    $imported++;
                }
            }

            return $imported;
        }

        /**
         * Import a single options group file.
         *
         * @param string $file_path
         * @return bool
         */
        public static function import_group_from_file(string $file_path): bool
        {
            if (! function_exists('update_field')) {
                return false;
            }

            if (! file_exists($file_path) || ! is_readable($file_path)) {
                return false;
            }

            $raw = file_get_contents($file_path);
            if ($raw === false) {
                return false;
            }

            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return false;
            }

            $meta = isset($decoded['meta']) && is_array($decoded['meta']) ? $decoded['meta'] : [];
            $group_id = isset($meta['group_id']) ? (string) $meta['group_id'] : '';
            if ($group_id === '') {
                return false;
            }

            if (! self::is_group_selected($group_id)) {
                return false;
            }

            $type = isset($meta['type']) ? (string) $meta['type'] : '';

            if ($type === 'acf_options_page') {
                $slug   = isset($meta['slug']) ? (string) $meta['slug'] : '';
                $post_id = self::resolve_options_export_post_id($slug, $meta);
                $fields = isset($decoded['fields']) && is_array($decoded['fields']) ? $decoded['fields'] : [];
                foreach ($fields as $field_key => $field) {
                    $value = is_array($field) && array_key_exists('value', $field) ? $field['value'] : null;
                    update_field($field_key, $value, $post_id);
                }
                return true;
            }

            if ($type === 'acf_field_group') {
                $slug   = isset($meta['slug']) ? (string) $meta['slug'] : '';
                $post_id = self::resolve_options_export_post_id($slug, $meta);
                $fields_by_post = isset($decoded['fields']) && is_array($decoded['fields']) ? $decoded['fields'] : [];
                $fields = $fields_by_post[$post_id] ?? reset($fields_by_post);
                if (is_array($fields)) {
                    foreach ($fields as $field_key => $field) {
                        $value = is_array($field) && array_key_exists('value', $field) ? $field['value'] : null;
                        update_field($field_key, $value, (string) $post_id);
                    }
                }
                return true;
            }

            return false;
        }

        private static function is_group_selected(string $group_id): bool
        {
            $selected = self::get_selected_group_ids();
            return in_array($group_id, $selected, true);
        }

        private static function build_export_payload(array $entry): array
        {
            $group_id = $entry['id'] ?? '';
            if ($group_id === '') {
                return [];
            }

            if (($entry['type'] ?? '') === 'options_page') {
                $slug    = $entry['slug'] ?? '';
                $post_id = self::resolve_options_export_post_id((string) $slug, $entry);
                $fields  = acf_get_field_objects($post_id);
                if (! is_array($fields)) {
                    $fields = [];
                }

                $values = [];
                foreach ($fields as $field) {
                    if (! is_array($field)) {
                        continue;
                    }
                    $field_key  = isset($field['key']) ? (string) $field['key'] : '';
                    $field_name = isset($field['name']) ? (string) $field['name'] : '';
                    if ($field_key === '' || $field_name === '') {
                        continue;
                    }
                    $value = get_field($field_name, $post_id);
                    $values[$field_key] = [
                        'name'  => $field_name,
                        'type'  => isset($field['type']) ? (string) $field['type'] : '',
                        'value' => dbvc_sanitize_json_data($value),
                    ];
                }

                return [
                    'meta' => [
                        'type'       => 'acf_options_page',
                        'group_id'   => $group_id,
                        'slug'       => $slug,
                        'label'      => $entry['label'] ?? '',
                        'post_id'    => $post_id,
                        'source'     => $entry['source'] ?? '',
                        'exported_at' => current_time('mysql'),
                    ],
                    'fields' => $values,
                ];
            }

            if (($entry['type'] ?? '') === 'field_group') {
                if (! function_exists('get_field')) {
                    return [];
                }

                $group_key = $entry['group_key'] ?? '';
                if ($group_key === '') {
                    return [];
                }

                $field_defs = function_exists('acf_get_fields') ? acf_get_fields($group_key) : [];
                if (! is_array($field_defs)) {
                    $field_defs = [];
                }

                $field_map = [];
                foreach ($field_defs as $field) {
                    if (! is_array($field)) {
                        continue;
                    }
                    $field_key = isset($field['key']) ? (string) $field['key'] : '';
                    $field_name = isset($field['name']) ? (string) $field['name'] : '';
                    if ($field_key === '') {
                        continue;
                    }
                    $field_map[$field_key] = [
                        'name' => $field_name,
                        'type' => isset($field['type']) ? (string) $field['type'] : '',
                    ];
                }

                $locations = isset($entry['locations']) && is_array($entry['locations']) ? $entry['locations'] : [];
                $values_by_post = [];
                $post_id = self::resolve_options_export_post_id($locations[0] ?? '', []);
                $values_by_post[$post_id] = [];
                foreach ($field_map as $field_key => $info) {
                    $field_name = $info['name'] ?? '';
                    if ($field_name === '') {
                        continue;
                    }
                    $value = get_field($field_name, $post_id);
                    $values_by_post[$post_id][$field_key] = [
                        'name'  => $field_name,
                        'type'  => $info['type'] ?? '',
                        'value' => dbvc_sanitize_json_data($value),
                    ];
                }

                return [
                    'meta' => [
                        'type'        => 'acf_field_group',
                        'group_id'    => $group_id,
                        'group_key'   => $group_key,
                        'label'       => $entry['label'] ?? '',
                        'source'      => $entry['source'] ?? '',
                        'locations'   => array_values($locations),
                        'exported_at' => current_time('mysql'),
                    ],
                    'field_map' => $field_map,
                    'fields'    => $values_by_post,
                ];
            }

            return [];
        }

        private static function build_export_filename(array $entry, array $payload): string
        {
            $name = 'options-group.json';
            if (($entry['type'] ?? '') === 'options_page' && ! empty($entry['slug'])) {
                $name = 'options-page-' . $entry['slug'] . '.json';
            } elseif (($entry['type'] ?? '') === 'field_group' && ! empty($entry['group_key'])) {
                $name = 'options-group-' . $entry['group_key'] . '.json';
            }

            $name = sanitize_file_name($name);
            if ($name === '') {
                $name = 'options-group.json';
            }

            return apply_filters('dbvc_export_options_group_filename', $name, $entry, $payload);
        }

        private static function extract_options_page_slugs(array $locations): array
        {
            $slugs = [];
            foreach ($locations as $group) {
                if (! is_array($group)) {
                    continue;
                }
                foreach ($group as $rule) {
                    if (! is_array($rule)) {
                        continue;
                    }
                    $param = isset($rule['param']) ? (string) $rule['param'] : '';
                    $value = isset($rule['value']) ? (string) $rule['value'] : '';
                    $operator = isset($rule['operator']) ? (string) $rule['operator'] : '==';
                    if ($param === 'options_page' && $value !== '' && ($operator === '==' || $operator === '===')) {
                        $slugs[] = $value;
                    }
                }
            }

            return array_values(array_unique($slugs));
        }

        private static function get_acf_options_pages(): array
        {
            if (! function_exists('acf_get_options_pages')) {
                return [];
            }

            $pages_raw = acf_get_options_pages();
            if (! is_array($pages_raw)) {
                return [];
            }

            $pages = [];
            foreach ($pages_raw as $page) {
                if (! is_array($page)) {
                    continue;
                }
                $slug = '';
                if (! empty($page['menu_slug'])) {
                    $slug = (string) $page['menu_slug'];
                } elseif (! empty($page['slug'])) {
                    $slug = (string) $page['slug'];
                }

                if ($slug === '') {
                    continue;
                }

                $pages[$slug] = $page;
            }

            return $pages;
        }

        private static function get_options_page_label(string $slug, array $page): string
        {
            if (! empty($page['menu_title'])) {
                return (string) $page['menu_title'];
            }
            if (! empty($page['page_title'])) {
                return (string) $page['page_title'];
            }

            return ucwords(str_replace(['-', '_'], ' ', $slug));
        }

        private static function resolve_options_post_id(string $slug, array $page): string
        {
            if (! empty($page['post_id'])) {
                $post_id = (string) $page['post_id'];
            } elseif ($slug === 'options' || $slug === 'acf-options') {
                $post_id = 'options';
            } elseif ($slug !== '') {
                $post_id = 'options_' . $slug;
            } else {
                $post_id = 'options';
            }

            return (string) apply_filters('dbvc_acf_options_post_id', $post_id, $slug, $page);
        }

        private static function resolve_options_export_post_id(string $slug, array $page): string
        {
            $candidates = [];
            if (! empty($page['post_id'])) {
                $candidates[] = (string) $page['post_id'];
            }
            $candidates[] = 'option';
            $candidates[] = 'options';
            if ($slug !== '') {
                $candidates[] = 'options_' . $slug;
            }

            foreach ($candidates as $candidate) {
                $fields = acf_get_field_objects($candidate);
                if (is_array($fields) && ! empty($fields)) {
                    return $candidate;
                }
            }

            return (string) apply_filters('dbvc_acf_options_post_id', $candidates[0], $slug, $page);
        }
    }
}
