<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Artifact_Manager
{
    public const INDEX_FILE = DBVC_CC_Contracts::STORAGE_INDEX_FILE;
    public const REDIRECT_FILE = DBVC_CC_Contracts::STORAGE_REDIRECT_MAP_FILE;
    public const LOG_FILE = 'events.ndjson';

    /**
     * @return void
     */
    public static function ensure_storage_roots()
    {
        $base_dir = self::get_storage_base_dir();
        if (! self::ensure_directory($base_dir)) {
            return;
        }

        self::ensure_directory(trailingslashit($base_dir) . '_schema');
        self::ensure_directory(trailingslashit($base_dir) . DBVC_CC_Contracts::STORAGE_EXPORTS_PATH);
    }

    /**
     * @return string
     */
    public static function get_storage_base_dir()
    {
        $upload_dir = wp_upload_dir();
        $basedir = isset($upload_dir['basedir']) ? (string) $upload_dir['basedir'] : '';
        if ($basedir === '') {
            return '';
        }

        $options = DBVC_CC_Settings_Service::get_options();
        $storage_path = sanitize_file_name((string) $options['storage_path']);
        if ($storage_path === '') {
            $storage_path = DBVC_CC_Contracts::STORAGE_DEFAULT_PATH;
        }

        return trailingslashit($basedir) . $storage_path;
    }

    /**
     * Returns discovered domain keys that represent crawl collections.
     *
     * @return array<int, string>
     */
    public static function list_domain_keys()
    {
        $base_dir = self::get_storage_base_dir();
        if (! is_dir($base_dir)) {
            return [];
        }

        $entries = @scandir($base_dir);
        if (! is_array($entries)) {
            return [];
        }

        $domains = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || strpos($entry, '.') === 0) {
                continue;
            }

            $domain_key = preg_replace('/[^a-z0-9.-]/', '', strtolower((string) $entry));
            if (! self::is_probable_domain_key($domain_key)) {
                continue;
            }

            $domain_dir = trailingslashit($base_dir) . $entry;
            if (! is_dir($domain_dir) || ! self::is_domain_collection_directory($domain_dir)) {
                continue;
            }

            $domains[$domain_key] = $domain_key;
        }

        $domain_rows = array_values($domains);
        usort(
            $domain_rows,
            static function ($left, $right) {
                return strnatcasecmp((string) $left, (string) $right);
            }
        );

        return $domain_rows;
    }

    /**
     * Returns all discovered relative page paths for a domain collection.
     *
     * @param string $dbvc_cc_domain_key
     * @return array<int, string>
     */
    public static function dbvc_cc_list_domain_relative_paths($dbvc_cc_domain_key)
    {
        $dbvc_cc_domain_key = self::dbvc_cc_sanitize_domain_key($dbvc_cc_domain_key);
        if ($dbvc_cc_domain_key === '') {
            return [];
        }

        $dbvc_cc_base_dir = self::get_storage_base_dir();
        if (! is_dir($dbvc_cc_base_dir)) {
            return [];
        }

        $dbvc_cc_base_real = realpath($dbvc_cc_base_dir);
        if (! is_string($dbvc_cc_base_real)) {
            return [];
        }

        $dbvc_cc_domain_dir = trailingslashit($dbvc_cc_base_real) . $dbvc_cc_domain_key;
        if (! is_dir($dbvc_cc_domain_dir)) {
            return [];
        }

        $dbvc_cc_domain_real = realpath($dbvc_cc_domain_dir);
        if (! is_string($dbvc_cc_domain_real) || ! dbvc_cc_path_is_within($dbvc_cc_domain_real, $dbvc_cc_base_real)) {
            return [];
        }

        $dbvc_cc_relative_paths = [];

        $dbvc_cc_index_path = trailingslashit($dbvc_cc_domain_real) . self::INDEX_FILE;
        $dbvc_cc_index = self::load_json_file($dbvc_cc_index_path, ['items' => []]);
        $dbvc_cc_items = isset($dbvc_cc_index['items']) && is_array($dbvc_cc_index['items']) ? $dbvc_cc_index['items'] : [];
        foreach ($dbvc_cc_items as $dbvc_cc_item) {
            if (! is_array($dbvc_cc_item) || ! isset($dbvc_cc_item['relative_path'])) {
                continue;
            }

            $dbvc_cc_relative_path = self::dbvc_cc_normalize_relative_path((string) $dbvc_cc_item['relative_path']);
            if ($dbvc_cc_relative_path === '') {
                continue;
            }

            $dbvc_cc_page_dir = trailingslashit($dbvc_cc_domain_real) . $dbvc_cc_relative_path;
            if (is_dir($dbvc_cc_page_dir)) {
                $dbvc_cc_relative_paths[$dbvc_cc_relative_path] = $dbvc_cc_relative_path;
            }
        }

        try {
            $dbvc_cc_iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dbvc_cc_domain_real, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
        } catch (Exception $dbvc_cc_exception) {
            return array_values($dbvc_cc_relative_paths);
        }

        foreach ($dbvc_cc_iterator as $dbvc_cc_file_info) {
            if (! $dbvc_cc_file_info instanceof SplFileInfo || ! $dbvc_cc_file_info->isFile()) {
                continue;
            }

            $dbvc_cc_filename = (string) $dbvc_cc_file_info->getFilename();
            if (strlen($dbvc_cc_filename) < 6 || substr($dbvc_cc_filename, -5) !== '.json') {
                continue;
            }

            $dbvc_cc_artifact_slug = basename($dbvc_cc_filename, '.json');
            $dbvc_cc_parent_slug = basename((string) $dbvc_cc_file_info->getPath());
            if ($dbvc_cc_artifact_slug === '' || $dbvc_cc_artifact_slug !== $dbvc_cc_parent_slug) {
                continue;
            }

            $dbvc_cc_page_dir = (string) $dbvc_cc_file_info->getPath();
            if (! dbvc_cc_path_is_within($dbvc_cc_page_dir, $dbvc_cc_domain_real)) {
                continue;
            }

            $dbvc_cc_relative_path = trim(str_replace(wp_normalize_path($dbvc_cc_domain_real), '', wp_normalize_path($dbvc_cc_page_dir)), '/');
            $dbvc_cc_relative_path = self::dbvc_cc_normalize_relative_path($dbvc_cc_relative_path);
            if ($dbvc_cc_relative_path === '') {
                continue;
            }

            $dbvc_cc_relative_paths[$dbvc_cc_relative_path] = $dbvc_cc_relative_path;
        }

        $dbvc_cc_rows = array_values($dbvc_cc_relative_paths);
        usort(
            $dbvc_cc_rows,
            static function ($dbvc_cc_left, $dbvc_cc_right) {
                return strnatcasecmp((string) $dbvc_cc_left, (string) $dbvc_cc_right);
            }
        );

        return $dbvc_cc_rows;
    }

    /**
     * @param string $url
     * @return string
     */
    public static function get_domain_key($url)
    {
        $host = wp_parse_url((string) $url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return 'unknown-domain';
        }

        $domain = strtolower($host);
        $domain = preg_replace('/[^a-z0-9.-]/', '', $domain);

        return $domain !== '' ? $domain : 'unknown-domain';
    }

    /**
     * @param string $url
     * @return string
     */
    public static function get_relative_page_path($url)
    {
        $url_path = wp_parse_url((string) $url, PHP_URL_PATH);
        $url_path = is_string($url_path) ? trim($url_path, '/') : '';

        if ($url_path === '') {
            return 'home';
        }

        $segments = explode('/', $url_path);
        $sanitized_segments = [];
        foreach ($segments as $segment) {
            $segment = sanitize_title(urldecode($segment));
            if ($segment !== '') {
                $sanitized_segments[] = $segment;
            }
        }

        return empty($sanitized_segments) ? 'home' : implode('/', $sanitized_segments);
    }

    /**
     * @param string $url
     * @return string
     */
    public static function canonicalize_url($url)
    {
        $parts = wp_parse_url((string) $url);
        if (! is_array($parts)) {
            return (string) $url;
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'https';
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $path = isset($parts['path']) ? (string) $parts['path'] : '/';
        if ($path === '') {
            $path = '/';
        }
        $path = preg_replace('#/+#', '/', $path);
        if ($path !== '/') {
            $path = rtrim((string) $path, '/');
        }
        if ($host === '') {
            return (string) $url;
        }

        return $scheme . '://' . $host . $path;
    }

    /**
     * @param string $url
     * @return string
     */
    public static function get_slug_from_url($url)
    {
        $relative_path = self::get_relative_page_path((string) $url);
        $segments = explode('/', $relative_path);
        $slug = end($segments);
        if (! is_string($slug) || $slug === '') {
            return 'home';
        }

        return $slug;
    }

    /**
     * @param string $url
     * @return string
     */
    public static function get_domain_dir($url)
    {
        return trailingslashit(self::get_storage_base_dir()) . self::get_domain_key((string) $url);
    }

    /**
     * @param string $url
     * @return string
     */
    public static function get_page_dir($url)
    {
        return trailingslashit(self::get_domain_dir((string) $url)) . self::get_relative_page_path((string) $url);
    }

    /**
     * @param string $url
     * @return string
     */
    public static function get_json_file_path($url)
    {
        return trailingslashit(self::get_page_dir((string) $url)) . self::get_slug_from_url((string) $url) . '.json';
    }

    /**
     * @param string $url
     * @return string|WP_Error
     */
    public static function prepare_page_directory($url)
    {
        $base_dir = self::get_storage_base_dir();
        $domain_dir = self::get_domain_dir((string) $url);
        $page_dir = self::get_page_dir((string) $url);

        if (! self::ensure_directory($base_dir)) {
            return new WP_Error('dbvc_cc_dir_creation_failed', sprintf('Could not create directory: %s', $base_dir));
        }
        if (! self::ensure_directory($domain_dir)) {
            return new WP_Error('dbvc_cc_dir_creation_failed', sprintf('Could not create directory: %s', $domain_dir));
        }
        if (! self::ensure_directory($page_dir)) {
            return new WP_Error('dbvc_cc_dir_creation_failed', sprintf('Could not create directory: %s', $page_dir));
        }

        return trailingslashit($page_dir);
    }

    /**
     * @param string $path
     * @param array<string, mixed> $data
     * @return bool
     */
    public static function write_json_file($path, array $data)
    {
        $base_dir = self::get_storage_base_dir();
        $path = (string) $path;
        if ($base_dir === '' || ! dbvc_cc_path_is_within($path, $base_dir)) {
            return false;
        }

        $encoded = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            return false;
        }

        return file_put_contents($path, $encoded) !== false;
    }

    /**
     * @param string $page_url
     * @param string $relative_path
     * @param string $content_hash
     * @return void
     */
    public static function update_domain_index($page_url, $relative_path, $content_hash)
    {
        $domain_dir = self::get_domain_dir((string) $page_url);
        if (! self::ensure_directory($domain_dir)) {
            return;
        }

        $index_path = trailingslashit($domain_dir) . DBVC_CC_Contracts::STORAGE_INDEX_FILE;
        $index = self::load_json_file(
            $index_path,
            [
                'schema_version' => '1.0',
                'generated_at' => current_time('c'),
                'items' => [],
            ]
        );

        $index_items = isset($index['items']) && is_array($index['items']) ? $index['items'] : [];
        $canonical_url = self::canonicalize_url((string) $page_url);
        $index_items[$canonical_url] = [
            'source_url' => (string) $page_url,
            'canonical_url' => $canonical_url,
            'relative_path' => (string) $relative_path,
            'slug' => self::get_slug_from_url((string) $page_url),
            'content_hash' => (string) $content_hash,
            'last_crawled_at' => current_time('c'),
        ];
        ksort($index_items);

        $index['items'] = $index_items;
        $index['generated_at'] = current_time('c');
        self::write_json_file($index_path, $index);

        if (self::is_dev_mode()) {
            self::write_dev_copy(
                'indexes/' . self::get_domain_key((string) $page_url) . '.json',
                wp_json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }
    }

    /**
     * @param string $page_url
     * @param string $relative_path
     * @return void
     */
    public static function update_redirect_map($page_url, $relative_path)
    {
        $domain_dir = self::get_domain_dir((string) $page_url);
        if (! self::ensure_directory($domain_dir)) {
            return;
        }

        $redirect_path = trailingslashit($domain_dir) . DBVC_CC_Contracts::STORAGE_REDIRECT_MAP_FILE;
        $options = DBVC_CC_Settings_Service::get_options();
        $redirect_map = self::load_json_file(
            $redirect_path,
            [
                'schema_version' => '1.0',
                'generated_at' => current_time('c'),
                'policy' => [
                    'slug_collision' => (string) $options['slug_collision_policy'],
                    'taxonomy_collision' => (string) $options['taxonomy_collision_policy'],
                ],
                'mappings' => [],
            ]
        );

        $mappings = isset($redirect_map['mappings']) && is_array($redirect_map['mappings']) ? $redirect_map['mappings'] : [];
        $canonical_url = self::canonicalize_url((string) $page_url);
        $mappings[$canonical_url] = [
            'source_url' => (string) $page_url,
            'canonical_url' => $canonical_url,
            'target_permalink' => self::build_target_permalink((string) $relative_path),
            'status' => 'mapped',
            'updated_at' => current_time('c'),
        ];
        ksort($mappings);

        $redirect_map['mappings'] = $mappings;
        $redirect_map['generated_at'] = current_time('c');
        self::write_json_file($redirect_path, $redirect_map);

        if (self::is_dev_mode()) {
            self::write_dev_copy(
                'redirects/' . self::get_domain_key((string) $page_url) . '.json',
                wp_json_encode($redirect_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }
    }

    /**
     * @param array<string, mixed> $event
     * @return void
     */
    public static function log_event(array $event)
    {
        $page_url = isset($event['page_url']) ? (string) $event['page_url'] : '';
        $domain_key = $page_url !== '' ? self::get_domain_key($page_url) : 'system';

        $domain_dir = trailingslashit(self::get_storage_base_dir()) . $domain_key;
        if (! self::ensure_directory($domain_dir)) {
            return;
        }

        $logs_dir = trailingslashit($domain_dir) . '_logs';
        if (! self::ensure_directory($logs_dir)) {
            return;
        }

        $payload = array_merge(
            [
                'timestamp' => current_time('c'),
                'domain' => $domain_key,
            ],
            $event
        );

        $line = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (! is_string($line)) {
            return;
        }

        $log_path = trailingslashit($logs_dir) . basename(DBVC_CC_Contracts::STORAGE_EVENTS_LOG_PATH);
        if (! dbvc_cc_path_is_within($log_path, self::get_storage_base_dir())) {
            return;
        }

        file_put_contents($log_path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

        if (self::is_dev_mode()) {
            self::write_dev_copy('logs/' . $domain_key . '.ndjson', $line . PHP_EOL, true);
        }
    }

    /**
     * @param string $page_url
     * @param string $page_dir
     * @return void
     */
    public static function sync_page_to_dev($page_url, $page_dir)
    {
        if (! self::is_dev_mode() || ! is_dir($page_dir)) {
            return;
        }

        $target_dir = trailingslashit(self::get_dev_base_dir()) . 'crawls/' . self::get_domain_key((string) $page_url) . '/' . self::get_relative_page_path((string) $page_url);
        self::copy_directory((string) $page_dir, $target_dir);
    }

    /**
     * @return bool
     */
    public static function is_dev_mode()
    {
        $options = DBVC_CC_Settings_Service::get_options();
        return ! empty($options['dev_mode']);
    }

    /**
     * @param string $directory
     * @return bool
     */
    private static function ensure_directory($directory)
    {
        $base_dir = self::get_storage_base_dir();
        $directory = (string) $directory;

        if ($directory === '' || $base_dir === '' || ! dbvc_cc_path_is_within($directory, $base_dir)) {
            return false;
        }

        return dbvc_cc_create_security_files($directory);
    }

    /**
     * @param string $domain_key
     * @return bool
     */
    private static function is_probable_domain_key($domain_key)
    {
        $domain_key = trim((string) $domain_key);
        if ($domain_key === '' || $domain_key === 'unknown-domain' || $domain_key === 'system') {
            return false;
        }

        if (strpos($domain_key, '..') !== false) {
            return false;
        }

        if (preg_match('/(^[.-]|[.-]$)/', $domain_key) === 1) {
            return false;
        }

        if (strpos($domain_key, '.') === false && $domain_key !== 'localhost') {
            return false;
        }

        return preg_match('/^[a-z0-9.-]+$/', $domain_key) === 1;
    }

    /**
     * @param string $dbvc_cc_domain_key
     * @return string
     */
    private static function dbvc_cc_sanitize_domain_key($dbvc_cc_domain_key)
    {
        $dbvc_cc_domain_key = preg_replace('/[^a-z0-9.-]/', '', strtolower((string) $dbvc_cc_domain_key));
        if (! is_string($dbvc_cc_domain_key)) {
            return '';
        }

        return self::is_probable_domain_key($dbvc_cc_domain_key)
            ? $dbvc_cc_domain_key
            : '';
    }

    /**
     * @param string $dbvc_cc_path
     * @return string
     */
    private static function dbvc_cc_normalize_relative_path($dbvc_cc_path)
    {
        $dbvc_cc_path = wp_normalize_path((string) $dbvc_cc_path);
        $dbvc_cc_path = trim($dbvc_cc_path, '/');
        if ($dbvc_cc_path === '' || strpos($dbvc_cc_path, '..') !== false) {
            return '';
        }

        $dbvc_cc_segments = array_filter(explode('/', $dbvc_cc_path), static function ($dbvc_cc_segment) {
            return $dbvc_cc_segment !== '';
        });

        $dbvc_cc_normalized_segments = [];
        foreach ($dbvc_cc_segments as $dbvc_cc_segment) {
            $dbvc_cc_safe_segment = sanitize_title((string) $dbvc_cc_segment);
            if ($dbvc_cc_safe_segment === '') {
                continue;
            }
            $dbvc_cc_normalized_segments[] = $dbvc_cc_safe_segment;
        }

        return empty($dbvc_cc_normalized_segments) ? '' : implode('/', $dbvc_cc_normalized_segments);
    }

    /**
     * @param string $domain_dir
     * @return bool
     */
    private static function is_domain_collection_directory($domain_dir)
    {
        $domain_dir = (string) $domain_dir;
        $index_file = trailingslashit($domain_dir) . self::INDEX_FILE;
        if (file_exists($index_file)) {
            return true;
        }

        return self::has_primary_artifact_file($domain_dir);
    }

    /**
     * @param string $domain_dir
     * @return bool
     */
    private static function has_primary_artifact_file($domain_dir)
    {
        if (! is_dir($domain_dir)) {
            return false;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($domain_dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
        } catch (Exception $exception) {
            return false;
        }

        $scanned = 0;
        foreach ($iterator as $file_info) {
            if (! $file_info instanceof SplFileInfo || ! $file_info->isFile()) {
                continue;
            }

            $scanned++;
            if ($scanned > 3000) {
                break;
            }

            $filename = (string) $file_info->getFilename();
            if (strlen($filename) < 6 || substr($filename, -5) !== '.json') {
                continue;
            }

            $artifact_slug = basename($filename, '.json');
            $parent_slug = basename((string) $file_info->getPath());
            if ($artifact_slug === $parent_slug && $artifact_slug !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $path
     * @param array<string, mixed> $fallback
     * @return array<string, mixed>
     */
    private static function load_json_file($path, array $fallback)
    {
        $path = (string) $path;
        if (! file_exists($path)) {
            return $fallback;
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return $fallback;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $fallback;
        }

        return $decoded;
    }

    /**
     * @param string $relative_path
     * @return string
     */
    private static function build_target_permalink($relative_path)
    {
        $normalized = trim((string) $relative_path, '/');
        if ($normalized === '' || $normalized === 'home') {
            return '/';
        }

        return '/' . $normalized . '/';
    }

    /**
     * @return string
     */
    private static function get_dev_base_dir()
    {
        $options = DBVC_CC_Settings_Service::get_options();
        $base_dir = self::get_storage_base_dir();
        $subdir = isset($options['dev_storage_subdir']) ? sanitize_file_name((string) $options['dev_storage_subdir']) : 'dev-data';
        if ($subdir === '') {
            $subdir = 'dev-data';
        }

        return trailingslashit($base_dir) . $subdir;
    }

    /**
     * @param string $relative_path
     * @param string|false $contents
     * @param bool $append
     * @return void
     */
    private static function write_dev_copy($relative_path, $contents, $append = false)
    {
        if (! is_string($contents) || $contents === '') {
            return;
        }

        $target = trailingslashit(self::get_dev_base_dir()) . ltrim((string) $relative_path, '/');
        $target_dir = dirname($target);
        $base_dir = self::get_storage_base_dir();

        if (! dbvc_cc_path_is_within($target, $base_dir)) {
            return;
        }

        if (! is_dir($target_dir) && ! wp_mkdir_p($target_dir)) {
            return;
        }
        dbvc_cc_create_security_files($target_dir);

        if ($append) {
            file_put_contents($target, $contents, FILE_APPEND | LOCK_EX);
            return;
        }
        file_put_contents($target, $contents);
    }

    /**
     * @param string $source
     * @param string $destination
     * @return void
     */
    private static function copy_directory($source, $destination)
    {
        if (! is_dir($source)) {
            return;
        }

        $base_dir = self::get_storage_base_dir();
        if (! dbvc_cc_path_is_within($destination, $base_dir)) {
            return;
        }

        if (! is_dir($destination) && ! wp_mkdir_p($destination)) {
            return;
        }
        dbvc_cc_create_security_files($destination);

        $entries = scandir($source);
        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $source_path = trailingslashit($source) . $entry;
            $destination_path = trailingslashit($destination) . $entry;
            if (is_dir($source_path)) {
                self::copy_directory($source_path, $destination_path);
                continue;
            }
            @copy($source_path, $destination_path);
        }
    }
}
