<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Portability_Media_Apply_Service
{
    private const CHECKSUM_META_KEY = '_dbvc_bricks_portability_checksum';
    private const PACKAGE_PATH_META_KEY = '_dbvc_bricks_portability_package_path';
    private const DEFAULT_MAX_MEDIA_FILE_BYTES = 26214400;

    /**
     * @param array<string, array<int, array<string, mixed>>> $affected_domains
     * @return array<int, string>
     */
    public static function get_media_domain_keys(array $affected_domains)
    {
        $keys = [];
        foreach (array_keys($affected_domains) as $domain_key) {
            $definition = DBVC_Bricks_Portability_Registry::get_domain($domain_key);
            if (is_array($definition) && ! empty($definition['media_backed'])) {
                $keys[] = sanitize_key((string) $domain_key);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $affected_domains
     * @return array<int, string>
     */
    public static function get_affected_option_names(array $affected_domains)
    {
        $option_names = [];
        foreach (self::get_media_domain_keys($affected_domains) as $domain_key) {
            if ($domain_key === 'custom_fonts') {
                $option_names[] = 'bricks_font_face_rules';
            }
            if ($domain_key === 'icon_collections') {
                $option_names[] = 'bricks_icon_sets';
                $option_names[] = 'bricks_custom_icons';
                $option_names[] = 'bricks_disabled_icon_sets';
            }
        }

        return array_values(array_unique($option_names));
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, array<int, array<string, mixed>>> $affected_domains
     * @param array<string, string> $effective_decisions
     * @return array<string, mixed>|\WP_Error
     */
    public static function apply_affected_domains(array $session, array $affected_domains, array $effective_decisions)
    {
        $media_domain_keys = self::get_media_domain_keys($affected_domains);
        if (empty($media_domain_keys)) {
            return [
                'mutated_options' => [],
                'media_state' => self::empty_media_state(),
                'font_value_map' => [],
            ];
        }

        $validation = self::validate_media_rows($affected_domains, $effective_decisions);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $state = self::empty_media_state();
        $mutated_options = [];
        $extract_dir = wp_normalize_path((string) ($session['extract_dir'] ?? ''));

        if (isset($affected_domains['custom_fonts'])) {
            $font_result = self::apply_custom_fonts($session, (array) $affected_domains['custom_fonts'], $extract_dir, $state);
            if (is_wp_error($font_result)) {
                DBVC_Bricks_Portability_Backup_Service::restore_media_state($state);
                return $font_result;
            }
            if (! empty($font_result['changed'])) {
                $mutated_options['bricks_font_face_rules'] = '';
            }
        }

        if (isset($affected_domains['icon_collections'])) {
            $icon_result = self::apply_icon_collections($session, (array) $affected_domains['icon_collections'], $extract_dir, $state);
            if (is_wp_error($icon_result)) {
                DBVC_Bricks_Portability_Backup_Service::restore_media_state($state);
                return $icon_result;
            }
            $mutated_options = array_merge($mutated_options, (array) ($icon_result['mutated_options'] ?? []));
        }

        return [
            'mutated_options' => $mutated_options,
            'media_state' => $state,
            'font_value_map' => (array) ($state['font_value_map'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $session
     * @param array<int, array<string, mixed>> $rows
     * @param string $extract_dir
     * @param array<string, mixed> $state
     * @return array<string, mixed>|\WP_Error
     */
    private static function apply_custom_fonts(array $session, array $rows, $extract_dir, array &$state)
    {
        unset($session);
        $changed = false;
        foreach ($rows as $row) {
            if (! self::row_is_addable_object($row)) {
                continue;
            }

            $source = isset($row['source']) && is_array($row['source']) ? $row['source'] : [];
            $raw = isset($source['raw']) && is_array($source['raw']) ? $source['raw'] : [];
            $title = sanitize_text_field((string) ($raw['post_title'] ?? $source['display_name'] ?? ''));
            if ($title === '') {
                return new \WP_Error('dbvc_bricks_portability_font_title_missing', __('Imported Bricks custom font is missing a title.', 'dbvc'), ['status' => 400]);
            }
            if (self::find_bricks_font_by_title($title) > 0) {
                return new \WP_Error(
                    'dbvc_bricks_portability_font_collision',
                    sprintf(__('Target site already has a Bricks custom font named `%s`. Refresh the review before applying.', 'dbvc'), $title),
                    ['status' => 409]
                );
            }

            $attachment_map = [];
            foreach ((array) ($source['media_refs'] ?? []) as $ref) {
                if (! is_array($ref)) {
                    continue;
                }
                $attachment_id = self::import_media_ref($ref, $extract_dir, $state);
                if (is_wp_error($attachment_id)) {
                    return $attachment_id;
                }
                $source_attachment_id = isset($ref['source_attachment_id']) ? (int) $ref['source_attachment_id'] : 0;
                if ($source_attachment_id > 0) {
                    $attachment_map[$source_attachment_id] = (int) $attachment_id;
                    $state['font_attachment_id_map'][(string) $source_attachment_id] = (int) $attachment_id;
                }
            }

            $font_faces = isset($raw['font_faces']) && is_array($raw['font_faces']) ? $raw['font_faces'] : [];
            $font_faces = self::remap_font_face_attachments($font_faces, $attachment_map);
            $post_status = sanitize_key((string) ($raw['post_status'] ?? 'publish'));
            if ($post_status === '') {
                $post_status = 'publish';
            }

            $post_id = wp_insert_post([
                'post_type' => 'bricks_fonts',
                'post_status' => $post_status,
                'post_title' => $title,
                'post_name' => sanitize_title((string) ($raw['post_name'] ?? $title)),
            ], true);
            if (is_wp_error($post_id)) {
                return $post_id;
            }
            $post_id = (int) $post_id;
            if ($post_id <= 0) {
                return new \WP_Error('dbvc_bricks_portability_font_insert_failed', __('Failed to create target Bricks custom font.', 'dbvc'), ['status' => 500]);
            }

            update_post_meta($post_id, 'bricks_font_faces', $font_faces);
            $state['created_posts'][] = [
                'post_id' => $post_id,
                'post_type' => 'bricks_fonts',
                'label' => $title,
            ];

            $source_post_id = isset($raw['post_id']) ? (int) $raw['post_id'] : self::parse_custom_font_object_id((string) ($source['object_id'] ?? ''));
            if ($source_post_id > 0) {
                $state['font_id_map'][(string) $source_post_id] = $post_id;
                $state['font_value_map']['custom_font_' . $source_post_id] = 'custom_font_' . $post_id;
            }
            $changed = true;
        }

        return [
            'changed' => $changed,
        ];
    }

    /**
     * @param array<string, mixed> $session
     * @param array<int, array<string, mixed>> $rows
     * @param string $extract_dir
     * @param array<string, mixed> $state
     * @return array<string, mixed>|\WP_Error
     */
    private static function apply_icon_collections(array $session, array $rows, $extract_dir, array &$state)
    {
        unset($session);
        $sets = get_option('bricks_icon_sets', []);
        $icons = get_option('bricks_custom_icons', []);
        $disabled_sets = get_option('bricks_disabled_icon_sets', []);
        $sets = is_array($sets) ? $sets : [];
        $icons = is_array($icons) ? $icons : [];
        $disabled_sets = is_array($disabled_sets) ? $disabled_sets : [];

        foreach ($rows as $row) {
            if (! self::row_is_addable_object($row)) {
                continue;
            }

            $source = isset($row['source']) && is_array($row['source']) ? $row['source'] : [];
            $raw = isset($source['raw']) && is_array($source['raw']) ? $source['raw'] : [];
            $set = isset($raw['set']) && is_array($raw['set']) ? $raw['set'] : [];
            $set_id = sanitize_text_field((string) ($set['id'] ?? $set['setId'] ?? $source['object_id'] ?? ''));
            if ($set_id === '') {
                return new \WP_Error('dbvc_bricks_portability_icon_set_id_missing', __('Imported Bricks icon set is missing an ID.', 'dbvc'), ['status' => 400]);
            }
            if (self::icon_set_exists($sets, $set_id)) {
                return new \WP_Error(
                    'dbvc_bricks_portability_icon_set_collision',
                    sprintf(__('Target site already has a Bricks icon set with ID `%s`. Refresh the review before applying.', 'dbvc'), $set_id),
                    ['status' => 409]
                );
            }

            $sets[] = $set;
            foreach ((array) ($raw['icons'] ?? []) as $icon) {
                if (! is_array($icon)) {
                    continue;
                }
                $ref = self::find_media_ref_for_source_attachment((array) ($source['media_refs'] ?? []), (int) ($icon['attachment_id'] ?? $icon['attachmentId'] ?? 0));
                if (empty($ref)) {
                    return new \WP_Error(
                        'dbvc_bricks_portability_icon_media_missing',
                        sprintf(__('Imported Bricks icon `%s` is missing its packaged SVG media reference.', 'dbvc'), sanitize_text_field((string) ($icon['name'] ?? $icon['id'] ?? 'icon'))),
                        ['status' => 400]
                    );
                }

                $attachment_id = self::import_media_ref($ref, $extract_dir, $state);
                if (is_wp_error($attachment_id)) {
                    return $attachment_id;
                }
                $source_attachment_id = (int) ($ref['source_attachment_id'] ?? 0);
                if ($source_attachment_id > 0) {
                    $state['icon_attachment_id_map'][(string) $source_attachment_id] = (int) $attachment_id;
                }
                $icon['attachment_id'] = (int) $attachment_id;
                $icon['url'] = wp_get_attachment_url((int) $attachment_id);
                $icons[] = $icon;
            }

            if (! empty($raw['disabled'])) {
                $disabled_sets = self::add_disabled_icon_set($disabled_sets, $set_id);
            }
        }

        return [
            'mutated_options' => [
                'bricks_icon_sets' => $sets,
                'bricks_custom_icons' => $icons,
                'bricks_disabled_icon_sets' => $disabled_sets,
            ],
        ];
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $affected_domains
     * @param array<string, string> $effective_decisions
     * @return true|\WP_Error
     */
    private static function validate_media_rows(array $affected_domains, array $effective_decisions)
    {
        foreach (self::get_media_domain_keys($affected_domains) as $domain_key) {
            foreach ((array) ($affected_domains[$domain_key] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $row_id = trim(sanitize_text_field((string) ($row['row_id'] ?? '')));
                $decision = sanitize_key((string) ($effective_decisions[$row_id] ?? ''));
                if ($decision !== 'add_incoming' || ! self::row_is_addable_object($row)) {
                    return new \WP_Error(
                        'dbvc_bricks_portability_media_apply_mode_unsupported',
                        sprintf(__('Bricks media-backed domain `%s` currently supports applying only new incoming objects.', 'dbvc'), $domain_key),
                        ['status' => 409]
                    );
                }
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @return bool
     */
    private static function row_is_addable_object(array $row)
    {
        return ($row['row_type'] ?? '') === 'object' && ($row['status'] ?? '') === 'new_in_source' && ! empty($row['source']) && is_array($row['source']);
    }

    /**
     * @param array<string, mixed> $ref
     * @param string $extract_dir
     * @param array<string, mixed> $state
     * @return int|\WP_Error
     */
    private static function import_media_ref(array $ref, $extract_dir, array &$state)
    {
        $checksum = sanitize_text_field((string) ($ref['checksum'] ?? ''));
        $package_path = self::normalize_package_relative_path($ref['package_path'] ?? '');
        $extension = sanitize_key((string) ($ref['extension'] ?? pathinfo($package_path, PATHINFO_EXTENSION)));
        $filename = sanitize_file_name((string) ($ref['filename'] ?? basename($package_path)));
        $mime_type = sanitize_text_field((string) ($ref['mime_type'] ?? ''));

        if ($checksum === '' || $package_path === '' || $extension === '' || ! self::is_allowed_media_extension($extension)) {
            return new \WP_Error('dbvc_bricks_portability_media_ref_invalid', __('Imported Bricks media reference is invalid or unsupported.', 'dbvc'), ['status' => 400]);
        }

        $extract_dir = wp_normalize_path((string) $extract_dir);
        $absolute = wp_normalize_path(trailingslashit($extract_dir) . $package_path);
        $extract_prefix = wp_normalize_path(trailingslashit($extract_dir));
        if ($extract_dir === '' || strpos($absolute, $extract_prefix) !== 0 || ! is_file($absolute) || ! is_readable($absolute)) {
            return new \WP_Error('dbvc_bricks_portability_media_file_missing', sprintf(__('Packaged Bricks media file `%s` is missing.', 'dbvc'), $package_path), ['status' => 400]);
        }

        $size_validation = self::validate_media_file_size($absolute, $package_path);
        if (is_wp_error($size_validation)) {
            return $size_validation;
        }

        $actual_hash = hash_file('sha256', $absolute);
        if (! is_string($actual_hash)) {
            return new \WP_Error('dbvc_bricks_portability_media_checksum_failed', sprintf(__('Failed to calculate checksum for Bricks media file `%s`.', 'dbvc'), $package_path), ['status' => 500]);
        }
        $actual = 'sha256:' . $actual_hash;
        if (! hash_equals($checksum, $actual)) {
            return new \WP_Error('dbvc_bricks_portability_media_checksum_mismatch', sprintf(__('Checksum mismatch detected for Bricks media file `%s`.', 'dbvc'), $package_path), ['status' => 400]);
        }

        $svg_validation = self::validate_svg_if_needed($absolute, $extension);
        if (is_wp_error($svg_validation)) {
            return $svg_validation;
        }

        $existing_id = self::find_attachment_by_checksum($checksum);
        if ($existing_id > 0) {
            $state['reused_attachments'][] = [
                'attachment_id' => $existing_id,
                'checksum' => $checksum,
                'package_path' => $package_path,
            ];
            return $existing_id;
        }

        $uploads = wp_upload_dir();
        if (! is_array($uploads) || empty($uploads['path'])) {
            return new \WP_Error('dbvc_bricks_portability_upload_dir_failed', __('Failed to resolve the WordPress uploads directory for Bricks media import.', 'dbvc'), ['status' => 500]);
        }

        $target_dir = wp_normalize_path((string) $uploads['path']);
        if (! is_dir($target_dir) || ! is_writable($target_dir)) {
            return new \WP_Error('dbvc_bricks_portability_upload_dir_unwritable', __('The WordPress uploads directory is not writable for Bricks media import.', 'dbvc'), ['status' => 500]);
        }

        if ($filename === '') {
            $filename = 'bricks-media-' . substr(preg_replace('/^sha256:/', '', $checksum), 0, 12) . '.' . $extension;
        }
        $target_filename = wp_unique_filename($target_dir, $filename);
        $target_path = wp_normalize_path(trailingslashit($target_dir) . $target_filename);
        if (! @copy($absolute, $target_path)) {
            return new \WP_Error('dbvc_bricks_portability_media_import_copy_failed', sprintf(__('Failed to copy Bricks media file `%s` into uploads.', 'dbvc'), $filename), ['status' => 500]);
        }

        $attachment_id = wp_insert_attachment([
            'post_title' => sanitize_text_field(pathinfo($target_filename, PATHINFO_FILENAME)),
            'post_mime_type' => $mime_type !== '' ? $mime_type : self::mime_type_for_extension($extension),
            'post_status' => 'inherit',
        ], $target_path, 0, true);
        if (is_wp_error($attachment_id)) {
            @unlink($target_path);
            return $attachment_id;
        }
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            @unlink($target_path);
            return new \WP_Error('dbvc_bricks_portability_media_insert_failed', __('Failed to create a WordPress attachment for imported Bricks media.', 'dbvc'), ['status' => 500]);
        }

        update_post_meta($attachment_id, self::CHECKSUM_META_KEY, $checksum);
        update_post_meta($attachment_id, self::PACKAGE_PATH_META_KEY, $package_path);
        $state['created_attachments'][] = [
            'attachment_id' => $attachment_id,
            'checksum' => $checksum,
            'package_path' => $package_path,
            'file' => $target_path,
        ];

        return $attachment_id;
    }

    /**
     * @param mixed $value
     * @param array<int, int> $attachment_map
     * @param string $key
     * @return mixed
     */
    private static function remap_font_face_attachments($value, array $attachment_map, $key = '')
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $child_key => $child_value) {
                $result[$child_key] = self::remap_font_face_attachments($child_value, $attachment_map, (string) $child_key);
            }
            return $result;
        }

        $key = sanitize_key((string) $key);
        if (in_array($key, self::font_attachment_keys(), true) && is_numeric($value)) {
            $source_id = (int) $value;
            return $attachment_map[$source_id] ?? $source_id;
        }

        return $value;
    }

    /**
     * @param array<int, mixed> $refs
     * @param int $source_attachment_id
     * @return array<string, mixed>
     */
    private static function find_media_ref_for_source_attachment(array $refs, $source_attachment_id)
    {
        foreach ($refs as $ref) {
            if (is_array($ref) && (int) ($ref['source_attachment_id'] ?? 0) === (int) $source_attachment_id) {
                return $ref;
            }
        }

        return [];
    }

    /**
     * @param string $title
     * @return int
     */
    private static function find_bricks_font_by_title($title)
    {
        $posts = get_posts([
            'post_type' => 'bricks_fonts',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'no_found_rows' => true,
        ]);
        $title = sanitize_text_field((string) $title);
        foreach ($posts as $post) {
            if ($post instanceof \WP_Post && (string) $post->post_title === $title) {
                return (int) $post->ID;
            }
        }

        return 0;
    }

    /**
     * @param array<int|string, mixed> $sets
     * @param string $set_id
     * @return bool
     */
    private static function icon_set_exists(array $sets, $set_id)
    {
        foreach ($sets as $set) {
            if (is_array($set) && sanitize_text_field((string) ($set['id'] ?? $set['setId'] ?? '')) === $set_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $disabled_sets
     * @param string $set_id
     * @return array<int|string, mixed>
     */
    private static function add_disabled_icon_set(array $disabled_sets, $set_id)
    {
        if (DBVC_Bricks_Portability_Utils::is_assoc($disabled_sets)) {
            $disabled_sets[$set_id] = true;
            return $disabled_sets;
        }

        if (! in_array($set_id, array_map('strval', $disabled_sets), true)) {
            $disabled_sets[] = $set_id;
        }

        return $disabled_sets;
    }

    /**
     * @param string $checksum
     * @return int
     */
    private static function find_attachment_by_checksum($checksum)
    {
        $posts = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => self::CHECKSUM_META_KEY,
            'meta_value' => sanitize_text_field((string) $checksum),
            'no_found_rows' => true,
        ]);

        return empty($posts) ? 0 : (int) $posts[0];
    }

    /**
     * @param string $absolute
     * @param string $extension
     * @return true|\WP_Error
     */
    private static function validate_svg_if_needed($absolute, $extension)
    {
        if (sanitize_key((string) $extension) !== 'svg') {
            return true;
        }

        $raw = file_get_contents($absolute);
        if (! is_string($raw) || trim($raw) === '') {
            return new \WP_Error('dbvc_bricks_portability_svg_empty', __('Imported Bricks SVG file is empty.', 'dbvc'), ['status' => 400]);
        }

        $lower = strtolower($raw);
        if (
            strpos($lower, '<script') !== false
            || strpos($lower, 'javascript:') !== false
            || preg_match('/\son[a-z0-9_-]+\s*=/i', $raw)
        ) {
            return new \WP_Error('dbvc_bricks_portability_svg_unsafe', __('Imported Bricks SVG contains scriptable content and was rejected.', 'dbvc'), ['status' => 400]);
        }

        return true;
    }

    /**
     * @param string $absolute
     * @param string $label
     * @return true|\WP_Error
     */
    private static function validate_media_file_size($absolute, $label)
    {
        $max_bytes = self::get_max_media_file_bytes();
        if ($max_bytes <= 0) {
            return true;
        }

        clearstatcache(true, $absolute);
        $bytes = filesize($absolute);
        if (! is_int($bytes)) {
            return new \WP_Error('dbvc_bricks_portability_media_size_failed', sprintf(__('Failed to inspect Bricks media file `%s`.', 'dbvc'), sanitize_text_field((string) $label)), ['status' => 500]);
        }
        if ($bytes > $max_bytes) {
            return new \WP_Error('dbvc_bricks_portability_media_file_too_large', sprintf(__('Bricks media file `%1$s` exceeds the allowed size limit of %2$s bytes.', 'dbvc'), sanitize_text_field((string) $label), number_format_i18n($max_bytes)), ['status' => 400]);
        }

        return true;
    }

    /**
     * @return int
     */
    private static function get_max_media_file_bytes()
    {
        return max(0, (int) apply_filters('dbvc_bricks_portability_max_media_file_bytes', self::DEFAULT_MAX_MEDIA_FILE_BYTES));
    }

    /**
     * @param mixed $relative_path
     * @return string
     */
    private static function normalize_package_relative_path($relative_path)
    {
        $path = ltrim(str_replace('\\', '/', (string) $relative_path), '/');
        if ($path === '' || strpos($path, '../') !== false || substr($path, -3) === '/..' || $path === '..') {
            return '';
        }

        return $path;
    }

    /**
     * @param string $extension
     * @return bool
     */
    private static function is_allowed_media_extension($extension)
    {
        return in_array(sanitize_key((string) $extension), ['woff2', 'woff', 'ttf', 'otf', 'eot', 'svg'], true);
    }

    /**
     * @param string $extension
     * @return string
     */
    private static function mime_type_for_extension($extension)
    {
        $map = [
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
            'svg' => 'image/svg+xml',
        ];

        $extension = sanitize_key((string) $extension);
        return $map[$extension] ?? 'application/octet-stream';
    }

    /**
     * @return array<int, string>
     */
    private static function font_attachment_keys()
    {
        return ['woff2', 'woff', 'ttf', 'otf', 'eot', 'svg'];
    }

    /**
     * @param string $object_id
     * @return int
     */
    private static function parse_custom_font_object_id($object_id)
    {
        return preg_match('/custom_font_(\d+)/', (string) $object_id, $matches) ? (int) $matches[1] : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private static function empty_media_state()
    {
        return [
            'created_posts' => [],
            'created_attachments' => [],
            'reused_attachments' => [],
            'font_id_map' => [],
            'font_value_map' => [],
            'font_attachment_id_map' => [],
            'icon_attachment_id_map' => [],
        ];
    }
}
