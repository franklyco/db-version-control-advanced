<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Portability_Package_Service
{
    public const PACKAGE_VERSION = 1;
    public const FEATURE_VERSION = '0.1.0';

    /**
     * @param array<int, string> $requested_domains
     * @param array<string, mixed> $args
     * @return array<string, mixed>|\WP_Error
     */
    public static function create_export(array $requested_domains, array $args = [])
    {
        if (! class_exists(\ZipArchive::class)) {
            return new \WP_Error('dbvc_bricks_portability_zip_missing', __('PHP ZipArchive is required for Bricks portability export.', 'dbvc'), ['status' => 500]);
        }

        $domains = DBVC_Bricks_Portability_Registry::resolve_requested_domains($requested_domains);
        if (empty($domains)) {
            return new \WP_Error('dbvc_bricks_portability_domains_missing', __('Select at least one supported Bricks portability domain.', 'dbvc'), ['status' => 400]);
        }

        $export_id = DBVC_Bricks_Portability_Utils::generate_id('bricks-portability-export');
        $export_dir = DBVC_Bricks_Portability_Storage::resolve_export_directory($export_id);
        if (is_wp_error($export_dir)) {
            return $export_dir;
        }

        $workspace = wp_normalize_path(trailingslashit($export_dir) . 'workspace');
        if (! wp_mkdir_p($workspace)) {
            return new \WP_Error('dbvc_bricks_portability_workspace_failed', __('Failed to create the Bricks portability export workspace.', 'dbvc'), ['status' => 500]);
        }

        $domains_dir = wp_normalize_path(trailingslashit($workspace) . 'domains');
        $raw_dir = wp_normalize_path(trailingslashit($workspace) . 'raw-options');
        wp_mkdir_p($domains_dir);
        wp_mkdir_p($raw_dir);

        $created_at = gmdate('c');
        $checksums = [];
        $selected_domain_keys = [];
        $normalized_domains = [];

        foreach ($domains as $domain) {
            $domain_key = sanitize_key((string) ($domain['domain_key'] ?? ''));
            if ($domain_key === '') {
                continue;
            }

            $normalized = DBVC_Bricks_Portability_Normalizer::normalize_live_domain($domain);
            $normalized_domains[$domain_key] = $normalized;
            $selected_domain_keys[] = $domain_key;

            $domain_payload = [
                'domain' => $domain_key,
                'label' => sanitize_text_field((string) ($domain['label'] ?? $domain_key)),
                'exported_at_gmt' => $created_at,
                'source_option_names' => array_values((array) ($domain['option_names'] ?? [])),
                'normalization_version' => DBVC_Bricks_Portability_Normalizer::NORMALIZATION_VERSION,
                'objects' => array_values((array) ($normalized['objects'] ?? [])),
                'metadata_rows' => array_values((array) ($normalized['metadata_rows'] ?? [])),
                'meta' => [
                    'count' => count((array) ($normalized['objects'] ?? [])),
                    'warnings' => array_values((array) ($normalized['warnings'] ?? [])),
                    'transport' => $normalized['transport'] ?? [],
                    'domain_fingerprint' => (string) ($normalized['domain_fingerprint'] ?? ''),
                ],
            ];

            $domain_file = sanitize_file_name((string) ($domain['file_slug'] ?? $domain_key) . '.json');
            $domain_path = DBVC_Bricks_Portability_Storage::write_json_file($domains_dir, $domain_file, $domain_payload);
            if (is_wp_error($domain_path)) {
                self::cleanup_directory($workspace);
                return $domain_path;
            }
            $checksums['domains/' . $domain_file] = DBVC_Bricks_Portability_Utils::fingerprint($domain_payload);

            foreach ((array) ($normalized['option_values'] ?? []) as $option_name => $value) {
                $option_name = sanitize_key((string) $option_name);
                if ($option_name === '') {
                    continue;
                }
                $raw_file = sanitize_file_name($option_name . '.json');
                $raw_path = DBVC_Bricks_Portability_Storage::write_json_file($raw_dir, $raw_file, [
                    'option_name' => $option_name,
                    'domain' => $domain_key,
                    'value' => $value,
                ]);
                if (is_wp_error($raw_path)) {
                    self::cleanup_directory($workspace);
                    return $raw_path;
                }
                $checksums['raw-options/' . $raw_file] = DBVC_Bricks_Portability_Utils::fingerprint([
                    'option_name' => $option_name,
                    'domain' => $domain_key,
                    'value' => $value,
                ]);
            }
        }

        $site_context = DBVC_Bricks_Portability_Utils::get_site_context();
        $manifest = [
            'package_id' => $export_id,
            'package_version' => self::PACKAGE_VERSION,
            'generator' => [
                'plugin' => 'DBVC',
                'addon' => 'Bricks',
                'feature' => 'Portability & Drift Manager',
                'version' => self::FEATURE_VERSION,
            ],
            'created_at_gmt' => $created_at,
            'source_site' => [
                'home_url' => (string) ($site_context['home_url'] ?? ''),
                'blog_id' => (int) ($site_context['blog_id'] ?? 0),
                'wp_version' => (string) ($site_context['wp_version'] ?? ''),
                'php_version' => (string) ($site_context['php_version'] ?? ''),
                'dbvc_version' => (string) ($site_context['dbvc_version'] ?? ''),
                'bricks_version' => (string) ($site_context['bricks_version'] ?? ''),
                'theme' => (string) ($site_context['theme'] ?? ''),
            ],
            'selected_domains' => $selected_domain_keys,
            'compatibility' => [
                'min_dbvc_version' => (string) ($site_context['dbvc_version'] ?? ''),
                'min_bricks_version' => '',
            ],
        ];
        $site_payload = [
            'site_name' => (string) ($site_context['site_name'] ?? ''),
            'home_url' => (string) ($site_context['home_url'] ?? ''),
            'export_user_id' => (int) ($site_context['export_user_id'] ?? 0),
            'export_user_label' => (string) ($site_context['export_user_label'] ?? ''),
            'notes' => sanitize_textarea_field((string) ($args['notes'] ?? '')),
            'environment' => sanitize_key((string) ($args['environment'] ?? '')),
        ];

        DBVC_Bricks_Portability_Storage::write_json_file($workspace, 'manifest.json', $manifest);
        DBVC_Bricks_Portability_Storage::write_json_file($workspace, 'site.json', $site_payload);
        $checksums['manifest.json'] = DBVC_Bricks_Portability_Utils::fingerprint($manifest);
        $checksums['site.json'] = DBVC_Bricks_Portability_Utils::fingerprint($site_payload);
        DBVC_Bricks_Portability_Storage::write_json_file($workspace, 'checksums.json', $checksums);

        $zip_path = DBVC_Bricks_Portability_Storage::resolve_export_zip_path($export_id);
        if (is_wp_error($zip_path)) {
            self::cleanup_directory($workspace);
            return $zip_path;
        }

        $zip_result = self::build_zip_archive($workspace, $zip_path);
        if (is_wp_error($zip_result)) {
            self::cleanup_directory($workspace);
            return $zip_result;
        }

        $job_id = self::create_job('bricks_portability_export', [
            'export_id' => $export_id,
            'selected_domains' => $selected_domain_keys,
            'progress' => 1,
        ]);

        $record = [
            'record_type' => 'export',
            'export_id' => $export_id,
            'package_id' => $export_id,
            'created_at_gmt' => $created_at,
            'selected_domains' => $selected_domain_keys,
            'summary' => [
                'domain_count' => count($selected_domain_keys),
                'job_id' => $job_id,
            ],
            'manifest' => $manifest,
            'zip_filename' => basename((string) $zip_path),
        ];
        DBVC_Bricks_Portability_Storage::write_json_file($export_dir, 'record.json', $record);

        self::log_activity(
            'portability_export_created',
            'info',
            'Bricks portability export created.',
            [
                'export_id' => $export_id,
                'selected_domains' => $selected_domain_keys,
            ],
            $job_id
        );

        self::cleanup_directory($workspace);

        return array_merge($record, [
            'zip_path' => $zip_path,
        ]);
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>|\WP_Error
     */
    public static function import_uploaded_package(array $file)
    {
        if (! class_exists(\ZipArchive::class)) {
            return new \WP_Error('dbvc_bricks_portability_zip_missing', __('PHP ZipArchive is required for Bricks portability import.', 'dbvc'), ['status' => 500]);
        }

        $tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ($tmp_name === '' || ! is_file($tmp_name)) {
            return new \WP_Error('dbvc_bricks_portability_upload_missing', __('Upload a Bricks portability ZIP package first.', 'dbvc'), ['status' => 400]);
        }

        $original_name = sanitize_file_name((string) ($file['name'] ?? 'bricks-portability.zip'));
        if (strtolower((string) pathinfo($original_name, PATHINFO_EXTENSION)) !== 'zip') {
            return new \WP_Error('dbvc_bricks_portability_upload_invalid', __('Bricks portability import accepts ZIP packages only.', 'dbvc'), ['status' => 400]);
        }

        $session_id = DBVC_Bricks_Portability_Utils::generate_id('bricks-portability-session');
        $session_dir = DBVC_Bricks_Portability_Storage::resolve_session_directory($session_id);
        if (is_wp_error($session_dir)) {
            return $session_dir;
        }

        $archive_path = wp_normalize_path(trailingslashit($session_dir) . 'package.zip');
        if (! @copy($tmp_name, $archive_path)) {
            return new \WP_Error('dbvc_bricks_portability_upload_copy_failed', __('Failed to stage the uploaded Bricks portability ZIP.', 'dbvc'), ['status' => 500]);
        }

        $extract_dir = wp_normalize_path(trailingslashit($session_dir) . 'extracted');
        if (! wp_mkdir_p($extract_dir)) {
            return new \WP_Error('dbvc_bricks_portability_extract_dir_failed', __('Failed to create the Bricks portability import workspace.', 'dbvc'), ['status' => 500]);
        }

        $extract_result = self::extract_zip_archive($archive_path, $extract_dir);
        if (is_wp_error($extract_result)) {
            return $extract_result;
        }

        $manifest = DBVC_Bricks_Portability_Storage::read_json_file(wp_normalize_path(trailingslashit($extract_dir) . 'manifest.json'));
        $checksums = DBVC_Bricks_Portability_Storage::read_json_file(wp_normalize_path(trailingslashit($extract_dir) . 'checksums.json'));
        $site_payload = DBVC_Bricks_Portability_Storage::read_json_file(wp_normalize_path(trailingslashit($extract_dir) . 'site.json'));
        if (is_wp_error($manifest)) {
            return $manifest;
        }
        if (is_wp_error($checksums)) {
            return $checksums;
        }
        if (is_wp_error($site_payload)) {
            return $site_payload;
        }

        $validation = self::validate_manifest($manifest);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $checksum_validation = self::validate_checksums($extract_dir, $checksums);
        if (is_wp_error($checksum_validation)) {
            return $checksum_validation;
        }

        $selected_domains = isset($manifest['selected_domains']) && is_array($manifest['selected_domains'])
            ? array_values(array_map('sanitize_key', $manifest['selected_domains']))
            : [];
        $domains = DBVC_Bricks_Portability_Registry::resolve_requested_domains($selected_domains);
        if (empty($domains)) {
            return new \WP_Error('dbvc_bricks_portability_manifest_domains_invalid', __('The uploaded Bricks portability package does not contain any supported domains.', 'dbvc'), ['status' => 400]);
        }

        $source_domains = [];
        $target_domains = [];
        foreach ($domains as $domain) {
            $domain_key = sanitize_key((string) ($domain['domain_key'] ?? ''));
            $raw_values = [];
            foreach ((array) ($domain['option_names'] ?? []) as $option_name) {
                $option_name = sanitize_key((string) $option_name);
                if ($option_name === '') {
                    continue;
                }
                $raw_path = wp_normalize_path(trailingslashit($extract_dir) . 'raw-options/' . sanitize_file_name($option_name . '.json'));
                $raw_payload = DBVC_Bricks_Portability_Storage::read_json_file($raw_path);
                if (is_wp_error($raw_payload)) {
                    return new \WP_Error(
                        'dbvc_bricks_portability_package_raw_missing',
                        sprintf(__('Missing expected raw option payload for `%s` in the uploaded package.', 'dbvc'), $option_name),
                        ['status' => 400]
                    );
                }
                $raw_values[$option_name] = $raw_payload['value'] ?? null;
            }

            $source_domains[$domain_key] = DBVC_Bricks_Portability_Normalizer::normalize_package_domain($domain, $raw_values);
            $target_domains[$domain_key] = DBVC_Bricks_Portability_Normalizer::normalize_live_domain($domain);
        }

        $review = DBVC_Bricks_Portability_Diff_Engine::build_review_session($manifest, $source_domains, $target_domains);
        $job_id = self::create_job('bricks_portability_import', [
            'session_id' => $session_id,
            'package_id' => sanitize_key((string) ($manifest['package_id'] ?? '')),
            'selected_domains' => $selected_domains,
            'progress' => 1,
        ]);

        $session = [
            'record_type' => 'session',
            'session_id' => $session_id,
            'created_at_gmt' => gmdate('c'),
            'package_id' => sanitize_key((string) ($manifest['package_id'] ?? '')),
            'job_id' => $job_id,
            'manifest' => $manifest,
            'site' => $site_payload,
            'summary' => $review['summary'],
            'domain_summaries' => $review['domain_summaries'],
            'rows' => $review['rows'],
            'source_domains' => $source_domains,
            'target_domains' => $target_domains,
            'archive_path' => $archive_path,
            'extract_dir' => $extract_dir,
        ];

        $session_record = [
            'record_type' => 'session',
            'session_id' => $session_id,
            'created_at_gmt' => $session['created_at_gmt'],
            'package_id' => $session['package_id'],
            'summary' => $session['summary'],
            'domain_summaries' => $session['domain_summaries'],
            'job_id' => $job_id,
        ];

        DBVC_Bricks_Portability_Storage::write_json_file($session_dir, 'record.json', $session_record);
        DBVC_Bricks_Portability_Storage::write_json_file($session_dir, 'session.json', $session);

        self::log_activity(
            'portability_import_completed',
            'info',
            'Bricks portability package imported and compared.',
            [
                'session_id' => $session_id,
                'package_id' => $session['package_id'],
                'selected_domains' => $selected_domains,
                'summary' => $session['summary'],
            ],
            $job_id
        );

        return self::build_session_view($session);
    }

    /**
     * @param string $session_id
     * @return array<string, mixed>|\WP_Error
     */
    public static function load_session($session_id)
    {
        $session_dir = DBVC_Bricks_Portability_Storage::resolve_session_directory($session_id);
        if (is_wp_error($session_dir)) {
            return $session_dir;
        }

        $session = DBVC_Bricks_Portability_Storage::read_json_file(wp_normalize_path(trailingslashit($session_dir) . 'session.json'));
        if (is_wp_error($session)) {
            return $session;
        }

        return $session;
    }

    /**
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public static function list_recent_exports($limit = 10)
    {
        return DBVC_Bricks_Portability_Storage::list_records('exports', $limit);
    }

    /**
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public static function list_recent_sessions($limit = 10)
    {
        return DBVC_Bricks_Portability_Storage::list_records('sessions', $limit);
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public static function build_session_view(array $session)
    {
        return [
            'session_id' => sanitize_key((string) ($session['session_id'] ?? '')),
            'created_at_gmt' => sanitize_text_field((string) ($session['created_at_gmt'] ?? '')),
            'package_id' => sanitize_key((string) ($session['package_id'] ?? '')),
            'manifest' => isset($session['manifest']) && is_array($session['manifest']) ? $session['manifest'] : [],
            'site' => isset($session['site']) && is_array($session['site']) ? $session['site'] : [],
            'summary' => isset($session['summary']) && is_array($session['summary']) ? $session['summary'] : [],
            'domain_summaries' => isset($session['domain_summaries']) && is_array($session['domain_summaries']) ? $session['domain_summaries'] : [],
            'rows' => isset($session['rows']) && is_array($session['rows']) ? $session['rows'] : [],
        ];
    }

    /**
     * @param string $workspace
     * @param string $zip_path
     * @return true|\WP_Error
     */
    private static function build_zip_archive($workspace, $zip_path)
    {
        $workspace = wp_normalize_path((string) $workspace);
        $zip_path = wp_normalize_path((string) $zip_path);

        $zip = new \ZipArchive();
        $open = $zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($open !== true) {
            return new \WP_Error('dbvc_bricks_portability_zip_open_failed', __('Unable to create the Bricks portability ZIP archive.', 'dbvc'), ['status' => 500]);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($workspace, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file_info) {
            $absolute = wp_normalize_path((string) $file_info->getPathname());
            $relative = ltrim(str_replace($workspace, '', $absolute), '/');
            if ($relative === '') {
                continue;
            }
            if ($file_info->isDir()) {
                $zip->addEmptyDir($relative);
                continue;
            }
            $zip->addFile($absolute, $relative);
        }

        $zip->close();
        return true;
    }

    /**
     * @param string $archive_path
     * @param string $extract_dir
     * @return true|\WP_Error
     */
    private static function extract_zip_archive($archive_path, $extract_dir)
    {
        $zip = new \ZipArchive();
        if ($zip->open($archive_path) !== true) {
            return new \WP_Error('dbvc_bricks_portability_zip_open_failed', __('Unable to open the uploaded Bricks portability ZIP package.', 'dbvc'), ['status' => 400]);
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (
                $name === ''
                || strpos($name, '../') !== false
                || strpos($name, '..\\') !== false
                || preg_match('#^([A-Za-z]:)?[\\\\/]#', $name)
            ) {
                $zip->close();
                return new \WP_Error('dbvc_bricks_portability_zip_entry_invalid', __('The uploaded Bricks portability ZIP contains an invalid path.', 'dbvc'), ['status' => 400]);
            }
        }

        if (! $zip->extractTo($extract_dir)) {
            $zip->close();
            return new \WP_Error('dbvc_bricks_portability_zip_extract_failed', __('Failed to extract the uploaded Bricks portability ZIP package.', 'dbvc'), ['status' => 400]);
        }
        $zip->close();
        return true;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return true|\WP_Error
     */
    private static function validate_manifest(array $manifest)
    {
        $package_version = isset($manifest['package_version']) ? (int) $manifest['package_version'] : 0;
        if ($package_version !== self::PACKAGE_VERSION) {
            return new \WP_Error('dbvc_bricks_portability_package_version_invalid', __('This Bricks portability package version is not supported.', 'dbvc'), ['status' => 400]);
        }

        $selected_domains = isset($manifest['selected_domains']) && is_array($manifest['selected_domains']) ? $manifest['selected_domains'] : [];
        if (empty($selected_domains)) {
            return new \WP_Error('dbvc_bricks_portability_manifest_invalid', __('The Bricks portability manifest does not include any selected domains.', 'dbvc'), ['status' => 400]);
        }

        return true;
    }

    /**
     * @param string $extract_dir
     * @param array<string, mixed> $checksums
     * @return true|\WP_Error
     */
    private static function validate_checksums($extract_dir, array $checksums)
    {
        foreach ($checksums as $relative_path => $expected) {
            $relative_path = ltrim(str_replace('\\', '/', (string) $relative_path), '/');
            $expected = sanitize_text_field((string) $expected);
            if ($relative_path === '' || $expected === '') {
                continue;
            }
            $absolute = wp_normalize_path(trailingslashit($extract_dir) . $relative_path);
            if (! is_file($absolute)) {
                return new \WP_Error('dbvc_bricks_portability_checksum_missing', sprintf(__('Missing package file `%s` while validating checksums.', 'dbvc'), $relative_path), ['status' => 400]);
            }
            $raw = file_get_contents($absolute);
            if (! is_string($raw)) {
                return new \WP_Error('dbvc_bricks_portability_checksum_read_failed', sprintf(__('Failed to read package file `%s` while validating checksums.', 'dbvc'), $relative_path), ['status' => 400]);
            }
            $actual = 'sha256:' . hash('sha256', $raw);
            if (! hash_equals($expected, $actual)) {
                return new \WP_Error('dbvc_bricks_portability_checksum_mismatch', sprintf(__('Checksum mismatch detected for `%s`.', 'dbvc'), $relative_path), ['status' => 400]);
            }
        }

        return true;
    }

    /**
     * @param string $type
     * @param array<string, mixed> $context
     * @return int
     */
    private static function create_job($type, array $context = [])
    {
        if (! class_exists('DBVC_Database') || ! method_exists('DBVC_Database', 'create_job')) {
            return 0;
        }

        return (int) DBVC_Database::create_job($type, $context, 'completed');
    }

    /**
     * @param string $event
     * @param string $severity
     * @param string $message
     * @param array<string, mixed> $context
     * @param int $job_id
     * @return void
     */
    private static function log_activity($event, $severity, $message, array $context = [], $job_id = 0)
    {
        do_action('dbvc_bricks_audit_event', $event, $context);
        if (! class_exists('DBVC_Database') || ! method_exists('DBVC_Database', 'log_activity')) {
            return;
        }

        DBVC_Database::log_activity(
            'dbvc_bricks_' . sanitize_key((string) $event),
            sanitize_key((string) $severity),
            sanitize_text_field((string) $message),
            $context,
            ['job_id' => (int) $job_id]
        );
    }

    /**
     * @param string $directory
     * @return void
     */
    private static function cleanup_directory($directory)
    {
        $directory = wp_normalize_path((string) $directory);
        if ($directory === '' || ! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file_info) {
            $path = wp_normalize_path((string) $file_info->getPathname());
            if ($file_info->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
