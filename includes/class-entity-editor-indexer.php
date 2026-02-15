<?php

if (! defined('WPINC')) {
    die;
}

/**
 * Builds and caches a lightweight index of sync-folder entities for the Entity Editor.
 */
final class DBVC_Entity_Editor_Indexer
{
    private const TRANSIENT_KEY = 'dbvc_entity_editor_index_v1';
    private const CACHE_TTL = 300;
    private const DISK_CACHE_FILE = '.dbvc-entity-index.json';
    private const LOCK_TTL = 300;
    private const LOCK_KEY_PREFIX = 'dbvc_entity_editor_lock_';
    private const FULL_REPLACE_CONFIRM_PHRASE = 'REPLACE';
    private const PROTECTED_POST_META_KEYS = [
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_page_template',
        '_thumbnail_id',
    ];
    private const PROTECTED_TERM_META_KEYS = [
        '_edit_lock',
        '_edit_last',
    ];

    /**
     * Return cached index payload or rebuild it.
     *
     * @param bool $force_rebuild
     * @return array<string,mixed>
     */
    public static function get_index($force_rebuild = false)
    {
        if (! $force_rebuild) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if (is_array($cached) && isset($cached['items']) && is_array($cached['items'])) {
                return $cached;
            }
        }

        $payload = self::build_index();
        set_transient(self::TRANSIENT_KEY, $payload, self::CACHE_TTL);
        self::write_disk_cache($payload);

        return $payload;
    }

    /**
     * Build an index by scanning sync JSON files.
     *
     * @return array<string,mixed>
     */
    private static function build_index()
    {
        $sync_root = dbvc_get_sync_path();
        $sync_real = realpath($sync_root);

        if (! $sync_real || ! is_dir($sync_real)) {
            return [
                'items' => [],
                'stats' => [
                    'scanned_files' => 0,
                    'indexed_files' => 0,
                    'excluded_files' => 0,
                ],
                'generated_at' => gmdate('c'),
                'sync_root' => '',
            ];
        }

        $items = [];
        $scanned = 0;
        $excluded = 0;
        $latest_mtime = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sync_real, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file_info) {
            if (! $file_info->isFile()) {
                continue;
            }

            $filename = $file_info->getFilename();
            if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'json') {
                continue;
            }

            $path = $file_info->getPathname();
            $relative = self::relative_path($sync_real, $path);
            if (self::is_excluded_path($relative)) {
                $excluded++;
                continue;
            }

            $scanned++;
            $item = self::build_item_from_json($path, $relative);
            if (! is_array($item)) {
                $excluded++;
                continue;
            }

            $mtime = isset($item['mtime']) ? (int) $item['mtime'] : 0;
            if ($mtime > $latest_mtime) {
                $latest_mtime = $mtime;
            }
            $items[] = $item;
        }

        usort($items, static function ($a, $b) {
            $a_kind = isset($a['entity_kind']) ? (string) $a['entity_kind'] : '';
            $b_kind = isset($b['entity_kind']) ? (string) $b['entity_kind'] : '';
            if ($a_kind !== $b_kind) {
                return strcmp($a_kind, $b_kind);
            }

            $a_sub = isset($a['subtype']) ? (string) $a['subtype'] : '';
            $b_sub = isset($b['subtype']) ? (string) $b['subtype'] : '';
            if ($a_sub !== $b_sub) {
                return strcmp($a_sub, $b_sub);
            }

            $a_title = isset($a['title']) ? (string) $a['title'] : '';
            $b_title = isset($b['title']) ? (string) $b['title'] : '';
            return strcmp($a_title, $b_title);
        });

        return [
            'items' => $items,
            'stats' => [
                'scanned_files' => $scanned,
                'indexed_files' => count($items),
                'excluded_files' => $excluded,
                'latest_mtime' => $latest_mtime,
            ],
            'generated_at' => gmdate('c'),
            'sync_root' => basename($sync_real),
        ];
    }

    /**
     * @param string $path
     * @param string $relative
     * @return array<string,mixed>|null
     */
    private static function build_item_from_json($path, $relative)
    {
        $raw = file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        if (self::is_excluded_payload($decoded, $relative)) {
            return null;
        }

        $kind = self::detect_kind($decoded);
        if ($kind === '') {
            return null;
        }

        $subtype = $kind === 'term'
            ? (string) ($decoded['taxonomy'] ?? '')
            : (string) ($decoded['post_type'] ?? '');

        $title = $kind === 'term'
            ? (string) ($decoded['name'] ?? $decoded['term_name'] ?? '')
            : (string) ($decoded['post_title'] ?? '');

        $slug = $kind === 'term'
            ? (string) ($decoded['slug'] ?? '')
            : (string) ($decoded['post_name'] ?? '');

        $uid = (string) ($decoded['vf_object_uid'] ?? $decoded['dbvc_object_uid'] ?? '');

        $mtime = (int) filemtime($path);

        return [
            'entity_kind' => $kind,
            'subtype' => $subtype,
            'title' => $title,
            'slug' => $slug,
            'uid' => $uid,
            'matched_wp' => self::find_matched_wp_entity($kind, $subtype, $slug, $uid),
            'relative_path' => $relative,
            'filename' => basename($relative),
            'mtime' => $mtime,
            'mtime_gmt' => gmdate('c', $mtime),
        ];
    }



    /**
     * Resolve a best-effort WP match for a JSON entity.
     *
     * @param string $kind
     * @param string $subtype
     * @param string $slug
     * @param string $uid
     * @return array<string,mixed>|null
     */
    private static function find_matched_wp_entity($kind, $subtype, $slug, $uid)
    {
        if ($kind === 'post') {
            $post_id = 0;
            if ($uid !== '') {
                $post_id = (int) self::lookup_post_id_by_uid($uid);
            }

            if ($post_id <= 0 && $slug !== '' && $subtype !== '' && function_exists('get_page_by_path')) {
                $post = get_page_by_path($slug, OBJECT, $subtype);
                if ($post && ! is_wp_error($post)) {
                    $post_id = (int) $post->ID;
                }
            }

            if ($post_id > 0) {
                return [
                    'id' => $post_id,
                    'kind' => 'post',
                    'label' => get_the_title($post_id) ?: sprintf(__('Post #%d', 'dbvc'), $post_id),
                    'edit_url' => function_exists('get_edit_post_link') ? (string) get_edit_post_link($post_id, '') : '',
                ];
            }

            return null;
        }

        if ($kind === 'term') {
            $term_id = 0;
            if ($uid !== '' && class_exists('DBVC_Database')) {
                $row = DBVC_Database::get_entity_by_uid($uid);
                if (is_array($row) && isset($row['entity_type']) && $row['entity_type'] === 'term') {
                    $term_id = isset($row['entity_id']) ? (int) $row['entity_id'] : 0;
                }
            }

            if ($term_id <= 0 && $slug !== '' && $subtype !== '' && function_exists('get_term_by')) {
                $term = get_term_by('slug', $slug, $subtype);
                if ($term && ! is_wp_error($term)) {
                    $term_id = (int) $term->term_id;
                }
            }

            if ($term_id > 0) {
                $term = get_term($term_id, $subtype);
                return [
                    'id' => $term_id,
                    'kind' => 'term',
                    'label' => $term && ! is_wp_error($term) ? (string) $term->name : sprintf(__('Term #%d', 'dbvc'), $term_id),
                    'edit_url' => function_exists('get_edit_term_link') ? (string) get_edit_term_link($term_id, $subtype) : '',
                ];
            }
        }

        return null;
    }

    /**
     * @param string $uid
     * @return int
     */
    private static function lookup_post_id_by_uid($uid)
    {
        global $wpdb;
        if (! $wpdb || ! isset($wpdb->postmeta)) {
            return 0;
        }

        $sql = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            'vf_object_uid',
            $uid
        );

        return (int) $wpdb->get_var($sql);
    }

    /**
     * @param array<string,mixed> $decoded
     * @return string
     */
    private static function detect_kind(array $decoded)
    {
        if (isset($decoded['taxonomy']) && (isset($decoded['slug']) || isset($decoded['term_id']))) {
            return 'term';
        }

        if (isset($decoded['ID']) && isset($decoded['post_type'])) {
            return 'post';
        }

        return '';
    }

    /**
     * @param array<string,mixed> $decoded
     * @param string $relative
     * @return bool
     */
    private static function is_excluded_payload(array $decoded, $relative)
    {
        $filename = strtolower(basename($relative));
        if ($filename === 'options.json' || $filename === 'menus.json') {
            return true;
        }

        $post_type = isset($decoded['post_type']) ? (string) $decoded['post_type'] : '';
        if ($post_type === 'attachment' || $post_type === 'nav_menu_item') {
            return true;
        }

        return false;
    }

    /**
     * @param string $relative
     * @return bool
     */
    private static function is_excluded_path($relative)
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '') {
            return true;
        }

        if (strpos($relative, '.dbvc_entity_editor_backups/') === 0) {
            return true;
        }

        if (strpos($relative, 'options/') === 0) {
            return true;
        }

        return false;
    }

    /**
     * @param string $root
     * @param string $path
     * @return string
     */
    private static function relative_path($root, $path)
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);

        if (strpos($path, $root . '/') === 0) {
            return ltrim(substr($path, strlen($root)), '/');
        }

        return ltrim($path, '/');
    }

    /**
     * Safely read an entity file by relative path.
     *
     * @param string $relative_path
     * @return array<string,mixed>|\WP_Error
     */
    public static function load_entity_file($relative_path, $user_id = 0, $force_takeover = false)
    {
        $resolved = self::resolve_entity_file_path($relative_path);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $lock = self::acquire_file_lock($relative_path, (int) $user_id, (bool) $force_takeover);
        if (is_wp_error($lock)) {
            return $lock;
        }

        $raw = file_get_contents($resolved);
        if (! is_string($raw)) {
            return new \WP_Error('dbvc_entity_editor_file_read_failed', __('Unable to read entity file.', 'dbvc'), ['status' => 500]);
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return new \WP_Error('dbvc_entity_editor_invalid_json', __('Entity file contains invalid JSON.', 'dbvc'), ['status' => 422]);
        }

        return [
            'relative_path' => str_replace('\\', '/', ltrim((string) $relative_path, '/')),
            'content' => $raw,
            'decoded' => $decoded,
            'mtime' => (int) filemtime($resolved),
            'mtime_gmt' => gmdate('c', (int) filemtime($resolved)),
            'lock' => $lock,
        ];
    }

    /**
     * Safely read an entity file for direct download without acquiring an editor lock.
     *
     * @param string $relative_path
     * @return array<string,mixed>|\WP_Error
     */
    public static function load_entity_file_for_download($relative_path)
    {
        $resolved = self::resolve_entity_file_path($relative_path);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $raw = file_get_contents($resolved);
        if (! is_string($raw)) {
            return new \WP_Error('dbvc_entity_editor_file_read_failed', __('Unable to read entity file.', 'dbvc'), ['status' => 500]);
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return new \WP_Error('dbvc_entity_editor_invalid_json', __('Entity file contains invalid JSON.', 'dbvc'), ['status' => 422]);
        }

        return [
            'relative_path' => str_replace('\\', '/', ltrim((string) $relative_path, '/')),
            'filename' => basename((string) $resolved),
            'content' => $raw,
            'decoded' => $decoded,
            'mtime' => (int) filemtime($resolved),
            'mtime_gmt' => gmdate('c', (int) filemtime($resolved)),
        ];
    }


    /**
     * Save entity JSON content after validation, backup, and atomic write.
     *
     * @param string $relative_path
     * @param string $content
     * @param int    $user_id
     * @return array<string,mixed>|\WP_Error
     */
    public static function save_entity_file($relative_path, $content, $user_id = 0, $lock_token = '', $force_takeover = false)
    {
        $resolved = self::resolve_entity_file_path($relative_path);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $content = is_string($content) ? $content : '';
        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            return new \WP_Error('dbvc_entity_editor_invalid_json', __('JSON payload is invalid.', 'dbvc'), ['status' => 422]);
        }

        if (self::is_excluded_payload($decoded, (string) $relative_path)) {
            return new \WP_Error('dbvc_entity_editor_excluded_payload', __('This JSON file type is excluded from Entity Editor edits.', 'dbvc'), ['status' => 400]);
        }

        $lock = self::validate_file_lock_for_save($relative_path, (int) $user_id, (string) $lock_token, (bool) $force_takeover);
        if (is_wp_error($lock)) {
            return $lock;
        }

        $normalized = function_exists('dbvc_normalize_for_json') ? dbvc_normalize_for_json($decoded) : $decoded;
        $encoded = wp_json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded) || $encoded === '') {
            return new \WP_Error('dbvc_entity_editor_encode_failed', __('Unable to encode JSON for save.', 'dbvc'), ['status' => 500]);
        }

        $backup = self::create_backup_copy($resolved, $relative_path);
        if (is_wp_error($backup)) {
            return $backup;
        }

        $tmp = $resolved . '.tmp-' . wp_generate_password(8, false, false);
        $bytes = file_put_contents($tmp, $encoded . "
");
        if (! is_int($bytes) || $bytes <= 0) {
            @unlink($tmp);
            return new \WP_Error('dbvc_entity_editor_write_failed', __('Failed writing temporary JSON file.', 'dbvc'), ['status' => 500]);
        }

        if (! @rename($tmp, $resolved)) {
            @unlink($tmp);
            return new \WP_Error('dbvc_entity_editor_replace_failed', __('Failed replacing JSON file atomically.', 'dbvc'), ['status' => 500]);
        }

        if (class_exists('DBVC_Sync_Logger')) {
            DBVC_Sync_Logger::log('Entity Editor JSON save', [
                'relative_path' => (string) $relative_path,
                'user_id' => (int) $user_id,
                'backup_path' => $backup,
                'bytes' => strlen($encoded),
            ]);
        }

        return [
            'relative_path' => str_replace('\\', '/', ltrim((string) $relative_path, '/')),
            'backup_path' => $backup,
            'mtime' => (int) filemtime($resolved),
            'mtime_gmt' => gmdate('c', (int) filemtime($resolved)),
            'content' => $encoded . "\n",
            'lock' => $lock,
        ];
    }

    /**
     * Save JSON and run non-destructive partial import for the matched entity.
     *
     * @param string $relative_path
     * @param string $content
     * @param int    $user_id
     * @param string $lock_token
     * @param bool   $force_takeover
     * @return array<string,mixed>|\WP_Error
     */
    public static function save_and_partial_import($relative_path, $content, $user_id = 0, $lock_token = '', $force_takeover = false)
    {
        $saved = self::save_entity_file($relative_path, $content, $user_id, $lock_token, $force_takeover);
        if (is_wp_error($saved)) {
            return $saved;
        }

        $decoded = json_decode((string) $saved['content'], true);
        if (! is_array($decoded)) {
            return new \WP_Error('dbvc_entity_editor_invalid_json', __('Saved JSON could not be decoded for import.', 'dbvc'), ['status' => 422]);
        }

        $kind = self::detect_kind($decoded);
        if ($kind === '') {
            return new \WP_Error('dbvc_entity_editor_unknown_kind', __('Unable to detect entity type for partial import.', 'dbvc'), ['status' => 400]);
        }

        if ($kind === 'post') {
            $result = self::partial_import_post($decoded);
        } else {
            $result = self::partial_import_term($decoded);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        if (class_exists('DBVC_Sync_Logger')) {
            DBVC_Sync_Logger::log('Entity Editor partial import', [
                'relative_path' => (string) $relative_path,
                'user_id' => (int) $user_id,
                'entity_kind' => $kind,
                'matched' => $result['matched'] ?? null,
                'counts' => $result['counts'] ?? [],
            ]);
        }

        return array_merge(
            $saved,
            [
                'import_mode' => 'partial',
                'imported' => true,
                'import_result' => $result,
            ]
        );
    }

    /**
     * Save JSON and run destructive full replace import for the matched entity.
     *
     * @param string $relative_path
     * @param string $content
     * @param int    $user_id
     * @param string $lock_token
     * @param bool   $force_takeover
     * @param string $confirm_phrase
     * @return array<string,mixed>|\WP_Error
     */
    public static function save_and_full_replace($relative_path, $content, $user_id = 0, $lock_token = '', $force_takeover = false, $confirm_phrase = '')
    {
        $confirm_phrase = trim((string) $confirm_phrase);
        if ($confirm_phrase !== self::FULL_REPLACE_CONFIRM_PHRASE) {
            return new \WP_Error(
                'dbvc_entity_editor_replace_confirmation_required',
                __('Full replace requires typed confirmation phrase.', 'dbvc'),
                ['status' => 400]
            );
        }

        $saved = self::save_entity_file($relative_path, $content, $user_id, $lock_token, $force_takeover);
        if (is_wp_error($saved)) {
            return $saved;
        }

        $decoded = json_decode((string) $saved['content'], true);
        if (! is_array($decoded)) {
            return new \WP_Error('dbvc_entity_editor_invalid_json', __('Saved JSON could not be decoded for full replace.', 'dbvc'), ['status' => 422]);
        }

        $kind = self::detect_kind($decoded);
        if ($kind === '') {
            return new \WP_Error('dbvc_entity_editor_unknown_kind', __('Unable to detect entity type for full replace.', 'dbvc'), ['status' => 400]);
        }

        if ($kind === 'post') {
            $result = self::full_replace_post($decoded);
        } else {
            $result = self::full_replace_term($decoded);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        if (class_exists('DBVC_Sync_Logger')) {
            DBVC_Sync_Logger::log('Entity Editor full replace', [
                'relative_path' => (string) $relative_path,
                'user_id' => (int) $user_id,
                'entity_kind' => $kind,
                'matched' => $result['matched'] ?? null,
                'counts' => $result['counts'] ?? [],
                'snapshot_path' => $result['snapshot_path'] ?? '',
                'backup_path' => $saved['backup_path'] ?? '',
            ]);
        }

        return array_merge(
            $saved,
            [
                'import_mode' => 'full_replace',
                'imported' => true,
                'import_result' => $result,
            ]
        );
    }

    /**
     * @param string $relative_path
     * @param int    $user_id
     * @param bool   $force_takeover
     * @return array<string,mixed>|\WP_Error
     */
    private static function acquire_file_lock($relative_path, $user_id, $force_takeover = false)
    {
        if ($user_id <= 0) {
            return new \WP_Error('dbvc_entity_editor_lock_user_required', __('Unable to determine current user for editor lock.', 'dbvc'), ['status' => 401]);
        }

        $key = self::lock_transient_key($relative_path);
        $existing = get_transient($key);
        if (is_array($existing) && ! self::is_lock_expired($existing)) {
            $owner_id = isset($existing['user_id']) ? (int) $existing['user_id'] : 0;
            if ($owner_id !== $user_id && ! $force_takeover) {
                return new \WP_Error(
                    'dbvc_entity_editor_locked',
                    __('This entity file is currently being edited by another user.', 'dbvc'),
                    [
                        'status' => 409,
                        'lock' => self::format_lock($existing, $relative_path),
                    ]
                );
            }
        }

        $token = is_array($existing) && isset($existing['user_id']) && (int) $existing['user_id'] === $user_id && isset($existing['token'])
            ? (string) $existing['token']
            : wp_generate_password(20, false, false);

        $lock = self::build_lock($relative_path, $user_id, $token);
        set_transient($key, $lock, self::LOCK_TTL);

        return self::format_lock($lock, $relative_path);
    }

    /**
     * @param string $relative_path
     * @param int    $user_id
     * @param string $lock_token
     * @param bool   $force_takeover
     * @return array<string,mixed>|\WP_Error
     */
    private static function validate_file_lock_for_save($relative_path, $user_id, $lock_token, $force_takeover = false)
    {
        if ($user_id <= 0) {
            return new \WP_Error('dbvc_entity_editor_lock_user_required', __('Unable to determine current user for editor lock.', 'dbvc'), ['status' => 401]);
        }

        $key = self::lock_transient_key($relative_path);
        $existing = get_transient($key);
        $normalized_token = is_string($lock_token) ? trim($lock_token) : '';

        if (is_array($existing) && ! self::is_lock_expired($existing)) {
            $owner_id = isset($existing['user_id']) ? (int) $existing['user_id'] : 0;
            $owner_token = isset($existing['token']) ? (string) $existing['token'] : '';

            if ($owner_id !== $user_id) {
                if (! $force_takeover) {
                    return new \WP_Error(
                        'dbvc_entity_editor_locked',
                        __('This entity file is currently being edited by another user.', 'dbvc'),
                        [
                            'status' => 409,
                            'lock' => self::format_lock($existing, $relative_path),
                        ]
                    );
                }

                $takeover_lock = self::build_lock($relative_path, $user_id, wp_generate_password(20, false, false));
                set_transient($key, $takeover_lock, self::LOCK_TTL);
                return self::format_lock($takeover_lock, $relative_path);
            }

            if ($normalized_token === '' || ! hash_equals($owner_token, $normalized_token)) {
                return new \WP_Error(
                    'dbvc_entity_editor_lock_mismatch',
                    __('Your editor lock expired or changed. Reload the file before saving.', 'dbvc'),
                    [
                        'status' => 409,
                        'lock' => self::format_lock($existing, $relative_path),
                    ]
                );
            }

            $lock = self::build_lock($relative_path, $user_id, $owner_token);
            set_transient($key, $lock, self::LOCK_TTL);
            return self::format_lock($lock, $relative_path);
        }

        if ($normalized_token === '') {
            return new \WP_Error(
                'dbvc_entity_editor_lock_required',
                __('Open the file in Entity Editor before saving so a lock can be acquired.', 'dbvc'),
                ['status' => 409]
            );
        }

        $lock = self::build_lock($relative_path, $user_id, $normalized_token);
        set_transient($key, $lock, self::LOCK_TTL);
        return self::format_lock($lock, $relative_path);
    }

    /**
     * @param string $relative_path
     * @param int    $user_id
     * @param string $token
     * @return array<string,mixed>
     */
    private static function build_lock($relative_path, $user_id, $token)
    {
        $expires_at = time() + self::LOCK_TTL;
        $user = function_exists('get_userdata') ? get_userdata((int) $user_id) : null;
        $display_name = ($user && ! is_wp_error($user) && isset($user->display_name)) ? (string) $user->display_name : sprintf(__('User #%d', 'dbvc'), (int) $user_id);

        return [
            'relative_path' => str_replace('\\', '/', ltrim((string) $relative_path, '/')),
            'token' => (string) $token,
            'user_id' => (int) $user_id,
            'user_display' => $display_name,
            'acquired_at' => gmdate('c'),
            'expires_at' => gmdate('c', $expires_at),
            'expires_ts' => $expires_at,
        ];
    }

    /**
     * @param array<string,mixed> $lock
     * @param string              $relative_path
     * @return array<string,mixed>
     */
    private static function format_lock(array $lock, $relative_path)
    {
        return [
            'relative_path' => str_replace('\\', '/', ltrim((string) $relative_path, '/')),
            'token' => isset($lock['token']) ? (string) $lock['token'] : '',
            'user_id' => isset($lock['user_id']) ? (int) $lock['user_id'] : 0,
            'user_display' => isset($lock['user_display']) ? (string) $lock['user_display'] : '',
            'acquired_at' => isset($lock['acquired_at']) ? (string) $lock['acquired_at'] : '',
            'expires_at' => isset($lock['expires_at']) ? (string) $lock['expires_at'] : '',
        ];
    }

    /**
     * @param array<string,mixed> $lock
     * @return bool
     */
    private static function is_lock_expired(array $lock)
    {
        $expires_ts = isset($lock['expires_ts']) ? (int) $lock['expires_ts'] : 0;
        return $expires_ts > 0 && $expires_ts < time();
    }

    /**
     * @param string $relative_path
     * @return string
     */
    private static function lock_transient_key($relative_path)
    {
        $normalized = str_replace('\\', '/', ltrim((string) $relative_path, '/'));
        return self::LOCK_KEY_PREFIX . md5($normalized);
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>|\WP_Error
     */
    private static function partial_import_post(array $decoded)
    {
        $post_type = isset($decoded['post_type']) ? sanitize_key((string) $decoded['post_type']) : '';
        $slug = isset($decoded['post_name']) ? sanitize_title((string) $decoded['post_name']) : '';
        $uid = self::extract_entity_uid($decoded);

        $match = self::match_single_post($post_type, $slug, $uid);
        if (is_wp_error($match)) {
            return $match;
        }

        $post_id = (int) $match['id'];
        $counts = [
            'core_fields_updated' => 0,
            'meta_keys_updated' => 0,
            'taxonomies_updated' => 0,
        ];

        $post_update = ['ID' => $post_id];
        $core_fields = [
            'post_title',
            'post_content',
            'post_excerpt',
            'post_status',
            'post_name',
            'post_parent',
            'menu_order',
            'post_author',
            'post_password',
            'comment_status',
            'ping_status',
            'post_date',
            'post_date_gmt',
        ];

        foreach ($core_fields as $field) {
            if (! array_key_exists($field, $decoded)) {
                continue;
            }
            $post_update[$field] = $decoded[$field];
            $counts['core_fields_updated']++;
        }

        if ($counts['core_fields_updated'] > 0) {
            $updated = wp_update_post($post_update, true);
            if (is_wp_error($updated)) {
                return new \WP_Error('dbvc_entity_editor_partial_post_update_failed', $updated->get_error_message(), ['status' => 500]);
            }
        }

        if (isset($decoded['meta']) && is_array($decoded['meta'])) {
            foreach ($decoded['meta'] as $meta_key => $meta_value) {
                $meta_key = (string) $meta_key;
                if ($meta_key === '') {
                    continue;
                }
                self::replace_post_meta_value($post_id, $meta_key, $meta_value);
                $counts['meta_keys_updated']++;
            }
        }

        if (isset($decoded['tax_input']) && is_array($decoded['tax_input']) && ! empty($decoded['tax_input'])) {
            $tax_subset = [];
            foreach ($decoded['tax_input'] as $taxonomy => $items) {
                $taxonomy = sanitize_key((string) $taxonomy);
                if ($taxonomy === '' || ! taxonomy_exists($taxonomy)) {
                    continue;
                }
                $tax_subset[$taxonomy] = is_array($items) ? $items : [];
            }

            if (! empty($tax_subset) && class_exists('DBVC_Sync_Posts')) {
                DBVC_Sync_Posts::import_tax_input_for_post($post_id, $post_type, $tax_subset, true);
                $counts['taxonomies_updated'] = count(array_keys($tax_subset));
            }
        }

        if (class_exists('DBVC_Sync_Posts')) {
            $post = get_post($post_id);
            if ($post instanceof \WP_Post) {
                if (function_exists('dbvc_run_auto_export_with_mask')) {
                    dbvc_run_auto_export_with_mask(static function () use ($post_id, $post) {
                        DBVC_Sync_Posts::export_post_to_json($post_id, $post);
                    });
                } else {
                    DBVC_Sync_Posts::export_post_to_json($post_id, $post);
                }
            }
        }

        return [
            'matched' => [
                'id' => $post_id,
                'kind' => 'post',
                'subtype' => $post_type,
                'match_source' => $match['match_source'],
            ],
            'counts' => $counts,
        ];
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>|\WP_Error
     */
    private static function partial_import_term(array $decoded)
    {
        $taxonomy = isset($decoded['taxonomy']) ? sanitize_key((string) $decoded['taxonomy']) : '';
        $slug = isset($decoded['slug']) ? sanitize_title((string) $decoded['slug']) : '';
        $uid = self::extract_entity_uid($decoded);

        $match = self::match_single_term($taxonomy, $slug, $uid);
        if (is_wp_error($match)) {
            return $match;
        }

        $term_id = (int) $match['id'];
        $counts = [
            'core_fields_updated' => 0,
            'meta_keys_updated' => 0,
        ];

        $term_args = [];
        foreach (['name', 'slug', 'description', 'parent'] as $field) {
            if (array_key_exists($field, $decoded)) {
                $term_args[$field] = $decoded[$field];
                $counts['core_fields_updated']++;
            }
        }

        if (array_key_exists('parent_slug', $decoded)) {
            $parent_slug = sanitize_title((string) $decoded['parent_slug']);
            if ($parent_slug !== '' && taxonomy_exists($taxonomy)) {
                $parent_term = get_term_by('slug', $parent_slug, $taxonomy);
                if ($parent_term && ! is_wp_error($parent_term)) {
                    $term_args['parent'] = (int) $parent_term->term_id;
                    $counts['core_fields_updated']++;
                }
            } elseif ($parent_slug === '') {
                $term_args['parent'] = 0;
                $counts['core_fields_updated']++;
            }
        }

        if (! empty($term_args)) {
            $updated = wp_update_term($term_id, $taxonomy, $term_args);
            if (is_wp_error($updated)) {
                return new \WP_Error('dbvc_entity_editor_partial_term_update_failed', $updated->get_error_message(), ['status' => 500]);
            }
        }

        if (isset($decoded['meta']) && is_array($decoded['meta'])) {
            foreach ($decoded['meta'] as $meta_key => $meta_value) {
                $meta_key = (string) $meta_key;
                if ($meta_key === '') {
                    continue;
                }
                self::replace_term_meta_value($term_id, $meta_key, $meta_value);
                $counts['meta_keys_updated']++;
            }
        }

        if (class_exists('DBVC_Sync_Taxonomies')) {
            DBVC_Sync_Taxonomies::export_selected_taxonomies();
        }

        return [
            'matched' => [
                'id' => $term_id,
                'kind' => 'term',
                'subtype' => $taxonomy,
                'match_source' => $match['match_source'],
            ],
            'counts' => $counts,
        ];
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>|\WP_Error
     */
    private static function full_replace_post(array $decoded)
    {
        $post_type = isset($decoded['post_type']) ? sanitize_key((string) $decoded['post_type']) : '';
        $slug = isset($decoded['post_name']) ? sanitize_title((string) $decoded['post_name']) : '';
        $uid = self::extract_entity_uid($decoded);

        $match = self::match_single_post($post_type, $slug, $uid);
        if (is_wp_error($match)) {
            return $match;
        }
        $post_id = isset($match['id']) ? (int) $match['id'] : 0;

        $snapshot_path = self::create_current_entity_snapshot('post', $post_id, $decoded);
        if (is_wp_error($snapshot_path)) {
            return $snapshot_path;
        }

        $counts = [
            'core_fields_updated' => 0,
            'meta_keys_updated' => 0,
            'taxonomies_updated' => 0,
        ];

        $post_update = ['ID' => $post_id];
        $core_fields = [
            'post_title',
            'post_content',
            'post_excerpt',
            'post_status',
            'post_name',
            'post_parent',
            'menu_order',
            'post_author',
            'post_password',
            'comment_status',
            'ping_status',
            'post_date',
            'post_date_gmt',
        ];

        foreach ($core_fields as $field) {
            if (! array_key_exists($field, $decoded)) {
                continue;
            }
            $post_update[$field] = $decoded[$field];
            $counts['core_fields_updated']++;
        }

        if ($counts['core_fields_updated'] > 0) {
            $updated = wp_update_post($post_update, true);
            if (is_wp_error($updated)) {
                return new \WP_Error('dbvc_entity_editor_partial_post_update_failed', $updated->get_error_message(), ['status' => 500]);
            }
        }

        $incoming_meta = isset($decoded['meta']) && is_array($decoded['meta']) ? $decoded['meta'] : [];
        $incoming_keys = array_map('strval', array_keys($incoming_meta));
        $protected_keys = self::get_protected_post_meta_keys();

        $existing_keys = get_post_custom_keys($post_id);
        if (! is_array($existing_keys)) {
            $existing_keys = [];
        }

        $deleted = 0;
        foreach ($existing_keys as $key) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            if (in_array($key, $incoming_keys, true)) {
                continue;
            }
            if (in_array($key, $protected_keys, true)) {
                continue;
            }
            delete_post_meta($post_id, $key);
            $deleted++;
        }

        foreach ($incoming_meta as $meta_key => $meta_value) {
            $meta_key = (string) $meta_key;
            if ($meta_key === '') {
                continue;
            }
            self::replace_post_meta_value($post_id, $meta_key, $meta_value);
            $counts['meta_keys_updated']++;
        }

        if (isset($decoded['tax_input']) && is_array($decoded['tax_input']) && ! empty($decoded['tax_input'])) {
            $tax_subset = [];
            foreach ($decoded['tax_input'] as $taxonomy => $items) {
                $taxonomy = sanitize_key((string) $taxonomy);
                if ($taxonomy === '' || ! taxonomy_exists($taxonomy)) {
                    continue;
                }
                $tax_subset[$taxonomy] = is_array($items) ? $items : [];
            }

            if (! empty($tax_subset) && class_exists('DBVC_Sync_Posts')) {
                DBVC_Sync_Posts::import_tax_input_for_post($post_id, $post_type, $tax_subset, true);
                $counts['taxonomies_updated'] = count(array_keys($tax_subset));
            }
        }

        if (class_exists('DBVC_Sync_Posts')) {
            $post = get_post($post_id);
            if ($post instanceof \WP_Post) {
                if (function_exists('dbvc_run_auto_export_with_mask')) {
                    dbvc_run_auto_export_with_mask(static function () use ($post_id, $post) {
                        DBVC_Sync_Posts::export_post_to_json($post_id, $post);
                    });
                } else {
                    DBVC_Sync_Posts::export_post_to_json($post_id, $post);
                }
            }
        }

        $counts['meta_keys_deleted'] = $deleted;

        return [
            'matched' => [
                'id' => $post_id,
                'kind' => 'post',
                'subtype' => $post_type,
                'match_source' => $match['match_source'],
            ],
            'counts' => $counts,
            'snapshot_path' => $snapshot_path,
        ];
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>|\WP_Error
     */
    private static function full_replace_term(array $decoded)
    {
        $taxonomy = isset($decoded['taxonomy']) ? sanitize_key((string) $decoded['taxonomy']) : '';
        $slug = isset($decoded['slug']) ? sanitize_title((string) $decoded['slug']) : '';
        $uid = self::extract_entity_uid($decoded);

        $match = self::match_single_term($taxonomy, $slug, $uid);
        if (is_wp_error($match)) {
            return $match;
        }
        $term_id = isset($match['id']) ? (int) $match['id'] : 0;

        $snapshot_path = self::create_current_entity_snapshot('term', $term_id, $decoded);
        if (is_wp_error($snapshot_path)) {
            return $snapshot_path;
        }

        $counts = [
            'core_fields_updated' => 0,
            'meta_keys_updated' => 0,
        ];

        $term_args = [];
        foreach (['name', 'slug', 'description', 'parent'] as $field) {
            if (array_key_exists($field, $decoded)) {
                $term_args[$field] = $decoded[$field];
                $counts['core_fields_updated']++;
            }
        }

        if (array_key_exists('parent_slug', $decoded)) {
            $parent_slug = sanitize_title((string) $decoded['parent_slug']);
            if ($parent_slug !== '' && taxonomy_exists($taxonomy)) {
                $parent_term = get_term_by('slug', $parent_slug, $taxonomy);
                if ($parent_term && ! is_wp_error($parent_term)) {
                    $term_args['parent'] = (int) $parent_term->term_id;
                    $counts['core_fields_updated']++;
                }
            } elseif ($parent_slug === '') {
                $term_args['parent'] = 0;
                $counts['core_fields_updated']++;
            }
        }

        if (! empty($term_args)) {
            $updated = wp_update_term($term_id, $taxonomy, $term_args);
            if (is_wp_error($updated)) {
                return new \WP_Error('dbvc_entity_editor_partial_term_update_failed', $updated->get_error_message(), ['status' => 500]);
            }
        }

        $incoming_meta = isset($decoded['meta']) && is_array($decoded['meta']) ? $decoded['meta'] : [];
        $incoming_keys = array_map('strval', array_keys($incoming_meta));
        $protected_keys = self::get_protected_term_meta_keys();

        $existing_meta = get_term_meta($term_id);
        $existing_keys = is_array($existing_meta) ? array_keys($existing_meta) : [];

        $deleted = 0;
        foreach ($existing_keys as $key) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            if (in_array($key, $incoming_keys, true)) {
                continue;
            }
            if (in_array($key, $protected_keys, true)) {
                continue;
            }
            delete_term_meta($term_id, $key);
            $deleted++;
        }

        foreach ($incoming_meta as $meta_key => $meta_value) {
            $meta_key = (string) $meta_key;
            if ($meta_key === '') {
                continue;
            }
            self::replace_term_meta_value($term_id, $meta_key, $meta_value);
            $counts['meta_keys_updated']++;
        }

        if (class_exists('DBVC_Sync_Taxonomies')) {
            DBVC_Sync_Taxonomies::export_selected_taxonomies();
        }

        $counts['meta_keys_deleted'] = $deleted;

        return [
            'matched' => [
                'id' => $term_id,
                'kind' => 'term',
                'subtype' => $taxonomy,
                'match_source' => $match['match_source'],
            ],
            'counts' => $counts,
            'snapshot_path' => $snapshot_path,
        ];
    }

    /**
     * @param string $post_type
     * @param string $slug
     * @param string $uid
     * @return array<string,mixed>|\WP_Error
     */
    private static function match_single_post($post_type, $slug, $uid)
    {
        $post_type = sanitize_key((string) $post_type);
        $slug = sanitize_title((string) $slug);
        $uid = trim((string) $uid);

        $sources = [];
        if ($uid !== '') {
            $sources[] = ['source' => 'uid', 'ids' => self::find_post_ids_by_uid($uid, $post_type)];
        }
        if ($slug !== '' && $post_type !== '') {
            $sources[] = ['source' => 'slug', 'ids' => self::find_post_ids_by_slug($slug, $post_type)];
        }

        return self::resolve_single_candidate($sources, 'post');
    }

    /**
     * @param string $taxonomy
     * @param string $slug
     * @param string $uid
     * @return array<string,mixed>|\WP_Error
     */
    private static function match_single_term($taxonomy, $slug, $uid)
    {
        $taxonomy = sanitize_key((string) $taxonomy);
        $slug = sanitize_title((string) $slug);
        $uid = trim((string) $uid);

        $sources = [];
        if ($uid !== '') {
            $sources[] = ['source' => 'uid', 'ids' => self::find_term_ids_by_uid($uid, $taxonomy)];
        }
        if ($slug !== '' && $taxonomy !== '') {
            $sources[] = ['source' => 'slug', 'ids' => self::find_term_ids_by_slug($slug, $taxonomy)];
        }

        return self::resolve_single_candidate($sources, 'term');
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     * @param string                         $kind
     * @return array<string,mixed>|\WP_Error
     */
    private static function resolve_single_candidate(array $sources, $kind)
    {
        $all_ids = [];
        $best_source = '';

        foreach ($sources as $source) {
            $ids = isset($source['ids']) && is_array($source['ids']) ? array_values(array_unique(array_map('intval', $source['ids']))) : [];
            if (empty($ids)) {
                continue;
            }

            if (count($ids) > 1) {
                return new \WP_Error(
                    'dbvc_entity_editor_ambiguous_match',
                    __('Multiple matching entities were found; refine identifiers before importing.', 'dbvc'),
                    ['status' => 409, 'candidates' => $ids]
                );
            }

            if ($best_source === '') {
                $best_source = (string) ($source['source'] ?? '');
            }

            $all_ids = array_values(array_unique(array_merge($all_ids, $ids)));
        }

        if (empty($all_ids)) {
            return new \WP_Error('dbvc_entity_editor_no_match', __('No matching local entity was found for this JSON.', 'dbvc'), ['status' => 404]);
        }

        if (count($all_ids) > 1) {
            return new \WP_Error(
                'dbvc_entity_editor_ambiguous_match',
                __('Matched more than one local entity; partial import was blocked.', 'dbvc'),
                ['status' => 409, 'candidates' => $all_ids]
            );
        }

        return [
            'id' => (int) $all_ids[0],
            'kind' => $kind,
            'match_source' => $best_source ?: 'unknown',
        ];
    }

    /**
     * @param string $uid
     * @param string $post_type
     * @return array<int,int>
     */
    private static function find_post_ids_by_uid($uid, $post_type)
    {
        $ids = [];
        $uid = trim((string) $uid);
        if ($uid === '') {
            return $ids;
        }

        if (class_exists('DBVC_Database')) {
            $record = DBVC_Database::get_entity_by_uid($uid);
            if (is_object($record) && ! empty($record->object_id)) {
                $ids[] = (int) $record->object_id;
            }
        }

        $query = get_posts([
            'post_type' => $post_type ? [$post_type] : 'any',
            'post_status' => 'any',
            'meta_key' => 'vf_object_uid',
            'meta_value' => $uid,
            'posts_per_page' => 20,
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => true,
        ]);
        if (is_array($query)) {
            foreach ($query as $post_id) {
                $ids[] = (int) $post_id;
            }
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * @param string $slug
     * @param string $post_type
     * @return array<int,int>
     */
    private static function find_post_ids_by_slug($slug, $post_type)
    {
        $slug = sanitize_title((string) $slug);
        $post_type = sanitize_key((string) $post_type);
        if ($slug === '' || $post_type === '') {
            return [];
        }

        $ids = get_posts([
            'name' => $slug,
            'post_type' => [$post_type],
            'post_status' => 'any',
            'posts_per_page' => 20,
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => true,
        ]);

        return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
    }

    /**
     * @param string $uid
     * @param string $taxonomy
     * @return array<int,int>
     */
    private static function find_term_ids_by_uid($uid, $taxonomy)
    {
        $ids = [];
        $uid = trim((string) $uid);
        $taxonomy = sanitize_key((string) $taxonomy);
        if ($uid === '' || $taxonomy === '') {
            return $ids;
        }

        if (class_exists('DBVC_Database')) {
            $record = DBVC_Database::get_entity_by_uid($uid);
            if (is_object($record) && ! empty($record->object_id) && isset($record->object_type) && (string) $record->object_type === 'term:' . $taxonomy) {
                $ids[] = (int) $record->object_id;
            }
        }

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'number' => 20,
            'meta_query' => [
                [
                    'key' => 'vf_object_uid',
                    'value' => $uid,
                ],
            ],
            'fields' => 'ids',
        ]);

        if (is_array($terms)) {
            foreach ($terms as $term_id) {
                $ids[] = (int) $term_id;
            }
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * @param string $slug
     * @param string $taxonomy
     * @return array<int,int>
     */
    private static function find_term_ids_by_slug($slug, $taxonomy)
    {
        $slug = sanitize_title((string) $slug);
        $taxonomy = sanitize_key((string) $taxonomy);
        if ($slug === '' || $taxonomy === '') {
            return [];
        }

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'slug' => $slug,
            'number' => 20,
            'fields' => 'ids',
        ]);

        return is_array($terms) ? array_values(array_unique(array_map('intval', $terms))) : [];
    }

    /**
     * @param array<string,mixed> $decoded
     * @return string
     */
    private static function extract_entity_uid(array $decoded)
    {
        $uid = isset($decoded['vf_object_uid']) ? (string) $decoded['vf_object_uid'] : '';
        if ($uid === '' && isset($decoded['dbvc_object_uid'])) {
            $uid = (string) $decoded['dbvc_object_uid'];
        }
        if ($uid === '' && isset($decoded['meta']) && is_array($decoded['meta']) && isset($decoded['meta']['dbvc_post_history']) && is_array($decoded['meta']['dbvc_post_history'])) {
            $uid = isset($decoded['meta']['dbvc_post_history']['vf_object_uid']) ? (string) $decoded['meta']['dbvc_post_history']['vf_object_uid'] : '';
        }
        if ($uid === '' && isset($decoded['meta']) && is_array($decoded['meta']) && isset($decoded['meta']['dbvc_term_history'])) {
            $term_history = $decoded['meta']['dbvc_term_history'];
            if (is_array($term_history) && isset($term_history[0]) && is_array($term_history[0]) && isset($term_history[0]['vf_object_uid'])) {
                $uid = (string) $term_history[0]['vf_object_uid'];
            }
        }

        return trim($uid);
    }

    /**
     * @param int    $post_id
     * @param string $meta_key
     * @param mixed  $meta_value
     * @return void
     */
    private static function replace_post_meta_value($post_id, $meta_key, $meta_value)
    {
        if (is_array($meta_value) && self::is_list_array($meta_value)) {
            delete_post_meta($post_id, $meta_key);
            foreach ($meta_value as $item) {
                add_post_meta($post_id, $meta_key, maybe_unserialize($item));
            }
            return;
        }

        update_post_meta($post_id, $meta_key, maybe_unserialize($meta_value));
    }

    /**
     * @param int    $term_id
     * @param string $meta_key
     * @param mixed  $meta_value
     * @return void
     */
    private static function replace_term_meta_value($term_id, $meta_key, $meta_value)
    {
        if (is_array($meta_value) && self::is_list_array($meta_value)) {
            delete_term_meta($term_id, $meta_key);
            foreach ($meta_value as $item) {
                add_term_meta($term_id, $meta_key, maybe_unserialize($item));
            }
            return;
        }

        update_term_meta($term_id, $meta_key, maybe_unserialize($meta_value));
    }

    /**
     * @param array<int|string,mixed> $value
     * @return bool
     */
    private static function is_list_array(array $value)
    {
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @return array<int,string>
     */
    private static function get_protected_post_meta_keys()
    {
        $keys = apply_filters('dbvc_entity_editor_protected_post_meta_keys', self::PROTECTED_POST_META_KEYS);
        return is_array($keys) ? array_values(array_unique(array_map('strval', $keys))) : self::PROTECTED_POST_META_KEYS;
    }

    /**
     * @return array<int,string>
     */
    private static function get_protected_term_meta_keys()
    {
        $keys = apply_filters('dbvc_entity_editor_protected_term_meta_keys', self::PROTECTED_TERM_META_KEYS);
        return is_array($keys) ? array_values(array_unique(array_map('strval', $keys))) : self::PROTECTED_TERM_META_KEYS;
    }

    /**
     * @param string              $kind
     * @param int                 $entity_id
     * @param array<string,mixed> $decoded
     * @return string|\WP_Error
     */
    private static function create_current_entity_snapshot($kind, $entity_id, array $decoded)
    {
        $sync_real = realpath(dbvc_get_sync_path());
        if (! $sync_real || ! is_dir($sync_real)) {
            return new \WP_Error('dbvc_entity_editor_sync_missing', __('Sync folder is unavailable.', 'dbvc'), ['status' => 500]);
        }

        $backup_dir = trailingslashit($sync_real) . '.dbvc_entity_editor_backups';
        if (! is_dir($backup_dir) && ! wp_mkdir_p($backup_dir)) {
            return new \WP_Error('dbvc_entity_editor_backup_dir_failed', __('Unable to create backup directory.', 'dbvc'), ['status' => 500]);
        }

        $snapshot = [];
        if ($kind === 'post') {
            $post = get_post($entity_id);
            if (! ($post instanceof \WP_Post)) {
                return new \WP_Error('dbvc_entity_editor_snapshot_failed', __('Unable to capture post snapshot before replace.', 'dbvc'), ['status' => 500]);
            }
            $snapshot = [
                'kind' => 'post',
                'id' => (int) $post->ID,
                'post_type' => (string) $post->post_type,
                'post_name' => (string) $post->post_name,
                'post_title' => (string) $post->post_title,
                'post_content' => (string) $post->post_content,
                'post_excerpt' => (string) $post->post_excerpt,
                'post_status' => (string) $post->post_status,
                'meta' => get_post_meta($post->ID),
                'tax_input' => class_exists('DBVC_Sync_Posts') ? DBVC_Sync_Posts::export_tax_input_portable($post->ID, $post->post_type) : [],
            ];
        } elseif ($kind === 'term') {
            $taxonomy = isset($decoded['taxonomy']) ? sanitize_key((string) $decoded['taxonomy']) : '';
            $term = get_term($entity_id, $taxonomy);
            if (! $term || is_wp_error($term)) {
                return new \WP_Error('dbvc_entity_editor_snapshot_failed', __('Unable to capture term snapshot before replace.', 'dbvc'), ['status' => 500]);
            }
            $snapshot = [
                'kind' => 'term',
                'id' => (int) $term->term_id,
                'taxonomy' => (string) $term->taxonomy,
                'slug' => (string) $term->slug,
                'name' => (string) $term->name,
                'description' => (string) $term->description,
                'parent' => (int) $term->parent,
                'meta' => get_term_meta($term->term_id),
            ];
        } else {
            return new \WP_Error('dbvc_entity_editor_snapshot_failed', __('Unknown entity kind for snapshot.', 'dbvc'), ['status' => 500]);
        }

        $snapshot['captured_at'] = gmdate('c');
        $snapshot['captured_by'] = get_current_user_id();

        $snapshot_name = sprintf(
            '%s_%d.%s.snapshot.json',
            sanitize_key($kind),
            (int) $entity_id,
            gmdate('Ymd-His')
        );
        $snapshot_path = trailingslashit($backup_dir) . $snapshot_name;
        $encoded = wp_json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || $encoded === '' || file_put_contents($snapshot_path, $encoded . "\n") === false) {
            return new \WP_Error('dbvc_entity_editor_snapshot_failed', __('Unable to write pre-replace snapshot.', 'dbvc'), ['status' => 500]);
        }

        return '.dbvc_entity_editor_backups/' . $snapshot_name;
    }

    /**
     * @param string $file_path
     * @param string $relative_path
     * @return string|\WP_Error
     */
    private static function create_backup_copy($file_path, $relative_path)
    {
        $sync_real = realpath(dbvc_get_sync_path());
        if (! $sync_real || ! is_dir($sync_real)) {
            return new \WP_Error('dbvc_entity_editor_sync_missing', __('Sync folder is unavailable.', 'dbvc'), ['status' => 500]);
        }

        $backup_dir = trailingslashit($sync_real) . '.dbvc_entity_editor_backups';
        if (! is_dir($backup_dir) && ! wp_mkdir_p($backup_dir)) {
            return new \WP_Error('dbvc_entity_editor_backup_dir_failed', __('Unable to create backup directory.', 'dbvc'), ['status' => 500]);
        }

        $safe_name = str_replace(['/', '\\'], '__', ltrim((string) $relative_path, '/'));
        $backup_name = $safe_name . '.' . gmdate('Ymd-His') . '.bak.json';
        $backup_path = trailingslashit($backup_dir) . $backup_name;

        if (! @copy($file_path, $backup_path)) {
            return new \WP_Error('dbvc_entity_editor_backup_failed', __('Unable to create backup before save.', 'dbvc'), ['status' => 500]);
        }

        return '.dbvc_entity_editor_backups/' . $backup_name;
    }

    /**
     * Validate and resolve an entity file path under sync root.
     *
     * @param string $relative_path
     * @return string|\WP_Error
     */
    private static function resolve_entity_file_path($relative_path)
    {
        $relative_path = str_replace('\\', '/', ltrim((string) $relative_path, '/'));
        if ($relative_path === '' || strpos($relative_path, '..') !== false || substr($relative_path, -5) !== '.json') {
            return new \WP_Error('dbvc_entity_editor_invalid_path', __('Invalid entity file path.', 'dbvc'), ['status' => 400]);
        }

        $sync_real = realpath(dbvc_get_sync_path());
        if (! $sync_real || ! is_dir($sync_real)) {
            return new \WP_Error('dbvc_entity_editor_sync_missing', __('Sync folder is unavailable.', 'dbvc'), ['status' => 500]);
        }

        $candidate = trailingslashit($sync_real) . $relative_path;
        if (! is_file($candidate)) {
            return new \WP_Error('dbvc_entity_editor_file_missing', __('Entity file does not exist.', 'dbvc'), ['status' => 404]);
        }

        $real = realpath($candidate);
        if (! $real || strpos(str_replace('\\', '/', $real), rtrim(str_replace('\\', '/', $sync_real), '/') . '/') !== 0) {
            return new \WP_Error('dbvc_entity_editor_invalid_path', __('Entity file path escapes sync folder.', 'dbvc'), ['status' => 400]);
        }

        if (self::is_excluded_path(self::relative_path($sync_real, $real))) {
            return new \WP_Error('dbvc_entity_editor_excluded_path', __('That file is excluded from Entity Editor.', 'dbvc'), ['status' => 400]);
        }

        return $real;
    }

    /**
     * @param array<string,mixed> $payload
     * @return void
     */
    private static function write_disk_cache(array $payload)
    {
        $sync_real = realpath(dbvc_get_sync_path());
        if (! $sync_real || ! is_dir($sync_real)) {
            return;
        }

        $cache_path = trailingslashit($sync_real) . self::DISK_CACHE_FILE;
        $encoded = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || $encoded === '') {
            return;
        }

        file_put_contents($cache_path, $encoded);
    }
}
