<?php

namespace Dbvc\ConfigurationPortability;

if (! defined('WPINC')) {
    die;
}

final class ExportPackageBuilder
{
    public const PACKAGE_TYPE = 'dbvc_configuration_portability';
    public const PACKAGE_SCHEMA_VERSION = 1;
    public const FEATURE_VERSION = '0.1.0';

    /**
     * @param array<string, mixed> $selection
     * @param array<string, mixed> $args
     * @return array<string, mixed>|\WP_Error
     */
    public static function create_export(array $selection = [], array $args = [])
    {
        if (! class_exists(\ZipArchive::class)) {
            return new \WP_Error('dbvc_config_portability_zip_missing', __('PHP ZipArchive is required for configuration portability export.', 'dbvc'));
        }

        $profile = self::normalize_profile((string) ($args['profile'] ?? ($selection['profile'] ?? 'agency_baseline')));
        $resolved = self::resolve_selected_providers($selection, $profile);
        if (\is_wp_error($resolved)) {
            return $resolved;
        }

        $package_id = self::generate_package_id();
        $created_at = gmdate('c');
        $export_dir = Storage::resolve_export_directory($package_id);
        if (\is_wp_error($export_dir)) {
            return $export_dir;
        }

        $workspace = wp_normalize_path(trailingslashit($export_dir) . 'workspace');
        $workspace_result = \Dbvc\AiPackage\Storage::ensure_directory($workspace);
        if (\is_wp_error($workspace_result)) {
            return $workspace_result;
        }

        $context = [
            'profile' => $profile,
            'package_id' => $package_id,
            'created_at_gmt' => $created_at,
        ];
        $fields_by_domain = isset($selection['fields_by_domain']) && is_array($selection['fields_by_domain'])
            ? $selection['fields_by_domain']
            : [];

        $domain_files = [];
        $domain_manifest = [];
        $redactions = [];
        $selected_domains = [];
        $skipped_domains = $resolved['skipped_domains'];

        try {
            foreach ($resolved['providers'] as $domain_key => $provider) {
                $domain_selection = [];
                if (isset($fields_by_domain[$domain_key]) && is_array($fields_by_domain[$domain_key])) {
                    $domain_selection['fields'] = array_values(array_map('sanitize_key', $fields_by_domain[$domain_key]));
                }

                $payload = $provider->export($domain_selection, $context);
                if (empty($payload['groups']) || ! is_array($payload['groups'])) {
                    $skipped_domains[$domain_key] = 'no_exportable_fields';
                    continue;
                }

                $domain_file = 'domains/' . sanitize_file_name($domain_key . '.json');
                $write = Storage::write_json_file($workspace, $domain_file, $payload);
                if (\is_wp_error($write)) {
                    self::cleanup_directory($workspace);
                    return $write;
                }

                $selected_domains[] = $domain_key;
                $domain_files[$domain_key] = $domain_file;
                $domain_manifest[$domain_key] = [
                    'label' => $provider->get_label(),
                    'domain_version' => $provider->get_version(),
                    'file' => $domain_file,
                    'groups' => array_keys((array) $payload['groups']),
                    'field_count' => self::count_domain_fields($payload),
                    'redaction_count' => isset($payload['redactions']) && is_array($payload['redactions']) ? count($payload['redactions']) : 0,
                ];

                if (isset($payload['redactions']) && is_array($payload['redactions']) && ! empty($payload['redactions'])) {
                    $redactions[$domain_key] = $payload['redactions'];
                }
            }

            if (empty($selected_domains)) {
                self::cleanup_directory($workspace);
                return new \WP_Error('dbvc_config_portability_domains_missing', __('No configuration portability domains had exportable fields.', 'dbvc'));
            }

            $site_payload = self::build_site_payload($args);
            $manifest = self::build_manifest($package_id, $created_at, $profile, $domain_manifest, $selected_domains, $skipped_domains);
            $redactions_payload = [
                'package_id' => $package_id,
                'generated_at_gmt' => $created_at,
                'by_domain' => $redactions,
                'count' => self::count_redactions($redactions),
            ];

            foreach ([
                'manifest.json' => $manifest,
                'site.json' => $site_payload,
                'redactions.json' => $redactions_payload,
            ] as $relative_path => $payload) {
                $write = Storage::write_json_file($workspace, $relative_path, $payload);
                if (\is_wp_error($write)) {
                    self::cleanup_directory($workspace);
                    return $write;
                }
            }

            $checksums = self::build_checksums($workspace);
            $checksums_write = Storage::write_json_file($workspace, 'checksums.json', $checksums);
            if (\is_wp_error($checksums_write)) {
                self::cleanup_directory($workspace);
                return $checksums_write;
            }

            $zip_path = Storage::resolve_export_zip_path($package_id);
            if (\is_wp_error($zip_path)) {
                self::cleanup_directory($workspace);
                return $zip_path;
            }

            $zip_result = self::build_zip_archive($workspace, $zip_path);
            if (\is_wp_error($zip_result)) {
                self::cleanup_directory($workspace);
                return $zip_result;
            }

            $record = [
                'record_type' => 'export',
                'package_id' => $package_id,
                'created_at_gmt' => $created_at,
                'profile' => $profile,
                'selected_domains' => $selected_domains,
                'skipped_domains' => $skipped_domains,
                'domain_files' => $domain_files,
                'summary' => [
                    'domain_count' => count($selected_domains),
                    'redaction_count' => self::count_redactions($redactions),
                ],
                'manifest' => $manifest,
                'zip_filename' => basename((string) $zip_path),
            ];

            $record_write = Storage::write_json_file($export_dir, 'record.json', $record);
            if (\is_wp_error($record_write)) {
                self::cleanup_directory($workspace);
                return $record_write;
            }

            self::cleanup_directory($workspace);

            return array_merge($record, [
                'export_dir' => $export_dir,
                'zip_path' => $zip_path,
                'download_filename' => self::build_download_filename($package_id),
                'checksums' => $checksums,
            ]);
        } catch (\Throwable $e) {
            self::cleanup_directory($workspace);

            return new \WP_Error(
                'dbvc_config_portability_export_failed',
                sprintf(__('Configuration portability export failed: %s', 'dbvc'), $e->getMessage())
            );
        }
    }

    /**
     * @param string $package_id
     * @return array<string, mixed>|\WP_Error
     */
    public static function get_export_summary($package_id)
    {
        $package_id = sanitize_key((string) $package_id);
        if ($package_id === '') {
            return new \WP_Error('dbvc_config_portability_package_id_invalid', __('Configuration portability package id is invalid.', 'dbvc'));
        }

        $export_dir = Storage::resolve_export_directory($package_id);
        if (\is_wp_error($export_dir)) {
            return $export_dir;
        }

        $record = Storage::read_json_file(wp_normalize_path(trailingslashit($export_dir) . 'record.json'));
        if (\is_wp_error($record)) {
            return $record;
        }

        $zip_path = Storage::resolve_export_zip_path($package_id);
        if (\is_wp_error($zip_path)) {
            return $zip_path;
        }
        if (! is_file($zip_path) || ! is_readable($zip_path)) {
            return new \WP_Error('dbvc_config_portability_zip_missing', __('Configuration portability ZIP is unavailable.', 'dbvc'));
        }

        return array_merge($record, [
            'export_dir' => $export_dir,
            'zip_path' => $zip_path,
            'download_filename' => self::build_download_filename($package_id),
        ]);
    }

    /**
     * @param array<string, mixed> $selection
     * @param string               $profile
     * @return array{providers:array<string,DomainProviderInterface>,skipped_domains:array<string,string>}|\WP_Error
     */
    private static function resolve_selected_providers(array $selection, $profile)
    {
        $providers = Registry::get_providers();
        $requested = isset($selection['domains']) && is_array($selection['domains'])
            ? array_values(array_map('sanitize_key', $selection['domains']))
            : [];

        if (empty($requested)) {
            $requested = self::get_profile_domains($profile, array_keys($providers));
        }

        $selected = [];
        $skipped = [];
        foreach ($requested as $domain_key) {
            $domain_key = sanitize_key((string) $domain_key);
            if ($domain_key === '') {
                continue;
            }

            if (! isset($providers[$domain_key])) {
                $skipped[$domain_key] = 'unknown_domain';
                continue;
            }

            $selected[$domain_key] = $providers[$domain_key];
        }

        if (empty($selected)) {
            return new \WP_Error('dbvc_config_portability_domains_missing', __('Select at least one supported configuration portability domain.', 'dbvc'));
        }

        return [
            'providers' => $selected,
            'skipped_domains' => $skipped,
        ];
    }

    /**
     * @param string            $profile
     * @param array<int,string> $all_domains
     * @return array<int,string>
     */
    private static function get_profile_domains($profile, array $all_domains): array
    {
        $profiles = [
            'agency_baseline' => $all_domains,
            'full_review' => $all_domains,
            'add_on_baseline' => [
                'ai_package',
                'bricks_addon',
                'content_collector',
                'content_collector_runtime',
                'third_party_portability_settings',
                'visual_editor',
            ],
            'core_import_export_baseline' => [
                'core_import_export',
                'masking',
                'media_handling',
            ],
        ];

        return $profiles[$profile] ?? $profiles['agency_baseline'];
    }

    /**
     * @param string $profile
     * @return string
     */
    private static function normalize_profile($profile): string
    {
        $profile = sanitize_key(str_replace('-', '_', (string) $profile));
        if ($profile === 'full_review_package') {
            $profile = 'full_review';
        }

        return in_array($profile, ['agency_baseline', 'add_on_baseline', 'core_import_export_baseline', 'full_review'], true)
            ? $profile
            : 'agency_baseline';
    }

    /**
     * @param string               $package_id
     * @param string               $created_at
     * @param string               $profile
     * @param array<string, mixed> $domain_manifest
     * @param array<int, string>   $selected_domains
     * @param array<string,string> $skipped_domains
     * @return array<string, mixed>
     */
    private static function build_manifest($package_id, $created_at, $profile, array $domain_manifest, array $selected_domains, array $skipped_domains): array
    {
        return [
            'package_type' => self::PACKAGE_TYPE,
            'package_schema_version' => self::PACKAGE_SCHEMA_VERSION,
            'package_id' => $package_id,
            'created_at_gmt' => $created_at,
            'profile' => $profile,
            'generator' => [
                'plugin' => 'DBVC',
                'feature' => 'Configuration Portability',
                'feature_version' => self::FEATURE_VERSION,
                'dbvc_version' => defined('DBVC_PLUGIN_VERSION') ? DBVC_PLUGIN_VERSION : '',
            ],
            'compatibility' => [
                'min_dbvc_version' => defined('DBVC_PLUGIN_VERSION') ? DBVC_PLUGIN_VERSION : '',
                'package_schema_version' => self::PACKAGE_SCHEMA_VERSION,
            ],
            'selected_domains' => array_values($selected_domains),
            'skipped_domains' => $skipped_domains,
            'domains' => $domain_manifest,
            'files' => [
                'site' => 'site.json',
                'redactions' => 'redactions.json',
                'checksums' => 'checksums.json',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private static function build_site_payload(array $args): array
    {
        $theme = function_exists('wp_get_theme') ? wp_get_theme() : null;

        return [
            'site_name' => function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '',
            'home_url' => function_exists('home_url') ? (string) home_url('/') : '',
            'site_url' => function_exists('site_url') ? (string) site_url('/') : '',
            'blog_id' => function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0,
            'wp_version' => function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '',
            'php_version' => PHP_VERSION,
            'dbvc_version' => defined('DBVC_PLUGIN_VERSION') ? DBVC_PLUGIN_VERSION : '',
            'theme' => is_object($theme) && method_exists($theme, 'get') ? (string) $theme->get('Name') : '',
            'export_user_id' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'notes' => sanitize_textarea_field((string) ($args['notes'] ?? '')),
            'environment' => sanitize_key((string) ($args['environment'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return int
     */
    private static function count_domain_fields(array $payload): int
    {
        $count = 0;
        $groups = isset($payload['groups']) && is_array($payload['groups']) ? $payload['groups'] : [];
        foreach ($groups as $group) {
            if (isset($group['fields']) && is_array($group['fields'])) {
                $count += count($group['fields']);
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $redactions
     * @return int
     */
    private static function count_redactions(array $redactions): int
    {
        $count = 0;
        foreach ($redactions as $domain_redactions) {
            if (is_array($domain_redactions)) {
                $count += count($domain_redactions);
            }
        }

        return $count;
    }

    /**
     * @param string $workspace
     * @return array<string, string>
     */
    private static function build_checksums($workspace): array
    {
        $workspace = wp_normalize_path(rtrim((string) $workspace, '/\\'));
        $checksums = [];
        $files = self::list_workspace_files($workspace);

        foreach ($files as $file_path) {
            $relative = ltrim(str_replace($workspace, '', wp_normalize_path($file_path)), '/');
            if ($relative === '' || $relative === 'checksums.json') {
                continue;
            }

            $hash = hash_file('sha256', $file_path);
            if (is_string($hash) && $hash !== '') {
                $checksums[$relative] = $hash;
            }
        }

        ksort($checksums, SORT_STRING);

        return $checksums;
    }

    /**
     * @param string $workspace
     * @param string $zip_path
     * @return true|\WP_Error
     */
    private static function build_zip_archive($workspace, $zip_path)
    {
        $workspace = wp_normalize_path(rtrim((string) $workspace, '/\\'));
        $zip_path = wp_normalize_path((string) $zip_path);
        $parent = wp_normalize_path(dirname($zip_path));
        if (! is_dir($parent)) {
            $result = \Dbvc\AiPackage\Storage::ensure_directory($parent);
            if (\is_wp_error($result)) {
                return $result;
            }
        }

        $zip = new \ZipArchive();
        if ($zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return new \WP_Error('dbvc_config_portability_zip_create_failed', __('Could not create configuration portability ZIP package.', 'dbvc'));
        }

        foreach (self::list_workspace_files($workspace) as $file_path) {
            $relative = ltrim(str_replace($workspace, '', wp_normalize_path($file_path)), '/');
            if ($relative === '') {
                continue;
            }
            $zip->addFile($file_path, $relative);
        }

        $zip->close();

        return true;
    }

    /**
     * @param string $workspace
     * @return array<int, string>
     */
    private static function list_workspace_files($workspace): array
    {
        if (! is_dir($workspace)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($workspace, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }
            if (in_array($file->getBasename(), ['.htaccess', 'index.php'], true)) {
                continue;
            }
            $files[] = wp_normalize_path($file->getPathname());
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return string
     */
    private static function generate_package_id(): string
    {
        $token = function_exists('wp_generate_uuid4')
            ? substr(str_replace('-', '', wp_generate_uuid4()), 0, 8)
            : substr(md5((string) microtime(true)), 0, 8);

        return sanitize_key('dbvc-config-export-' . gmdate('Ymd-His') . '-' . strtolower($token));
    }

    /**
     * @param string $package_id
     * @return string
     */
    private static function build_download_filename($package_id): string
    {
        return sanitize_file_name((string) $package_id . '.zip');
    }

    /**
     * @param string $directory
     * @return void
     */
    private static function cleanup_directory($directory): void
    {
        $directory = wp_normalize_path((string) $directory);
        if ($directory === '' || ! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if (! $item instanceof \SplFileInfo) {
                continue;
            }

            $path = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
