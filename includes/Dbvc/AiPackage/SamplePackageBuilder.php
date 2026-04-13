<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class SamplePackageBuilder
{
    public const PACKAGE_TYPE = 'dbvc_ai_sample_package';
    public const PACKAGE_SCHEMA_VERSION = 1;
    public const MANIFEST_FILENAME = 'dbvc-ai-manifest.json';

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public static function build(array $args = [])
    {
        if (! class_exists('\ZipArchive')) {
            return new \WP_Error('dbvc_ai_zip_missing', __('Sample package generation requires PHP ZipArchive support.', 'dbvc'));
        }

        $storage = Storage::ensure_base_roots();
        if (\is_wp_error($storage)) {
            return $storage;
        }

        $build_args = self::normalize_build_args($args);
        if (empty($build_args['post_types']) && empty($build_args['taxonomies'])) {
            return new \WP_Error('dbvc_ai_empty_selection', __('Select at least one post type or taxonomy before generating a sample package.', 'dbvc'));
        }

        $selection_validation = self::validate_selection($build_args);
        if (\is_wp_error($selection_validation)) {
            return $selection_validation;
        }

        $package_id = self::build_package_id();
        $workspace_path = Storage::resolve_sample_package_directory($package_id);
        $zip_path = Storage::resolve_sample_package_zip_path($package_id);

        if (\is_wp_error($workspace_path)) {
            return $workspace_path;
        }

        if (\is_wp_error($zip_path)) {
            return $zip_path;
        }

        try {
            $workspace_result = Storage::ensure_directory($workspace_path);
            if (\is_wp_error($workspace_result)) {
                return $workspace_result;
            }

            $schema_bundle = SchemaDiscoveryService::build_schema_bundle($build_args);
            $template_bundle = TemplateBuilder::build_templates(
                $schema_bundle,
                [
                    'value_style' => $build_args['value_style'],
                    'variant_set' => $build_args['variant_set'],
                ]
            );
            $fingerprint = SiteFingerprintService::build_from_schema_bundle($schema_bundle);
            $validation_rules = isset($schema_bundle['validation_rules']) && is_array($schema_bundle['validation_rules'])
                ? $schema_bundle['validation_rules']
                : RulesService::build_validation_rules($schema_bundle);

            $artifacts = self::write_schema_artifacts($workspace_path, $schema_bundle, $fingerprint, $validation_rules);
            if (\is_wp_error($artifacts)) {
                self::cleanup_paths($workspace_path, $zip_path);
                return $artifacts;
            }

            $sample_artifacts = self::write_sample_artifacts($workspace_path, $template_bundle);
            if (\is_wp_error($sample_artifacts)) {
                self::cleanup_paths($workspace_path, $zip_path);
                return $sample_artifacts;
            }

            $counts = [
                'post_type_templates' => isset($template_bundle['post_types']) && is_array($template_bundle['post_types']) ? count($template_bundle['post_types']) : 0,
                'taxonomy_templates' => isset($template_bundle['taxonomies']) && is_array($template_bundle['taxonomies']) ? count($template_bundle['taxonomies']) : 0,
                'sample_json_files' => (int) ($sample_artifacts['sample_json_files'] ?? 0),
                'sample_markdown_files' => (int) ($sample_artifacts['sample_markdown_files'] ?? 0),
                'root_doc_files' => count($build_args['included_docs']),
            ];

            $doc_context = self::build_doc_context($build_args, $schema_bundle, $template_bundle, $fingerprint, $validation_rules, $counts);
            $root_docs = PackageDocBuilder::build_root_docs($doc_context, $build_args['included_docs']);
            $root_doc_paths = self::write_root_docs($workspace_path, $root_docs);
            if (\is_wp_error($root_doc_paths)) {
                self::cleanup_paths($workspace_path, $zip_path);
                return $root_doc_paths;
            }

            $counts['root_doc_files'] = count($root_doc_paths);

            $manifest = self::build_manifest(
                $package_id,
                $build_args,
                $schema_bundle,
                $template_bundle,
                $fingerprint,
                $validation_rules,
                $artifacts,
                $sample_artifacts,
                $root_doc_paths,
                $counts
            );

            $manifest_write = self::write_json_file($workspace_path, self::MANIFEST_FILENAME, $manifest);
            if (\is_wp_error($manifest_write)) {
                self::cleanup_paths($workspace_path, $zip_path);
                return $manifest_write;
            }

            $zip_result = self::build_zip_archive($workspace_path, $zip_path);
            if (\is_wp_error($zip_result)) {
                self::cleanup_paths($workspace_path, $zip_path);
                return $zip_result;
            }

            return [
                'package_id' => $package_id,
                'zip_path' => $zip_path,
                'workspace_path' => $workspace_path,
                'manifest_path' => wp_normalize_path(trailingslashit($workspace_path) . self::MANIFEST_FILENAME),
                'download_filename' => self::build_download_filename($package_id),
                'manifest' => $manifest,
                'counts' => $counts,
            ];
        } catch (\Throwable $e) {
            self::cleanup_paths($workspace_path, $zip_path);

            return new \WP_Error(
                'dbvc_ai_sample_build_failed',
                sprintf(__('Sample package generation failed: %s', 'dbvc'), $e->getMessage())
            );
        }
    }

    /**
     * @param string $package_id
     * @return array<string,mixed>|\WP_Error
     */
    public static function get_package_summary(string $package_id)
    {
        $workspace_path = Storage::resolve_sample_package_directory($package_id);
        $zip_path = Storage::resolve_sample_package_zip_path($package_id);

        if (\is_wp_error($workspace_path)) {
            return $workspace_path;
        }

        if (\is_wp_error($zip_path)) {
            return $zip_path;
        }

        $manifest_path = wp_normalize_path(trailingslashit($workspace_path) . self::MANIFEST_FILENAME);
        if (! is_file($manifest_path) || ! is_readable($manifest_path)) {
            return new \WP_Error('dbvc_ai_manifest_missing', __('Sample package manifest could not be found.', 'dbvc'));
        }

        if (! is_file($zip_path) || ! is_readable($zip_path)) {
            return new \WP_Error('dbvc_ai_zip_missing', __('Sample package ZIP could not be found.', 'dbvc'));
        }

        $manifest_raw = file_get_contents($manifest_path);
        if (! is_string($manifest_raw) || $manifest_raw === '') {
            return new \WP_Error('dbvc_ai_manifest_unreadable', __('Sample package manifest could not be read.', 'dbvc'));
        }

        $manifest = json_decode($manifest_raw, true);
        if (! is_array($manifest)) {
            return new \WP_Error('dbvc_ai_manifest_invalid', __('Sample package manifest is invalid JSON.', 'dbvc'));
        }

        return [
            'package_id' => sanitize_key($package_id),
            'workspace_path' => $workspace_path,
            'zip_path' => $zip_path,
            'manifest_path' => $manifest_path,
            'download_filename' => self::build_download_filename(sanitize_key($package_id)),
            'manifest' => $manifest,
            'counts' => isset($manifest['counts']) && is_array($manifest['counts']) ? $manifest['counts'] : [],
        ];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function normalize_build_args(array $args): array
    {
        $settings = Settings::get_all_settings();
        $generation = isset($settings['generation']) && is_array($settings['generation']) ? $settings['generation'] : [];

        $post_types = isset($args['post_types']) && is_array($args['post_types'])
            ? $args['post_types']
            : (array) get_option('dbvc_post_types', []);
        $taxonomies = isset($args['taxonomies']) && is_array($args['taxonomies'])
            ? $args['taxonomies']
            : (function_exists('dbvc_get_selected_taxonomies') ? dbvc_get_selected_taxonomies() : (array) get_option('dbvc_taxonomies', []));

        $shape_options = Settings::get_shape_mode_options();
        $value_options = Settings::get_value_style_options();
        $variant_options = Settings::get_variant_set_options();

        $shape_mode = isset($args['shape_mode']) ? sanitize_key((string) $args['shape_mode']) : sanitize_key((string) ($generation['shape_mode'] ?? 'conservative'));
        if (! isset($shape_options[$shape_mode])) {
            $shape_mode = 'conservative';
        }

        $value_style = isset($args['value_style']) ? sanitize_key((string) $args['value_style']) : sanitize_key((string) ($generation['value_style'] ?? 'blank'));
        if (! isset($value_options[$value_style])) {
            $value_style = 'blank';
        }

        $variant_set = isset($args['variant_set']) ? sanitize_key((string) $args['variant_set']) : sanitize_key((string) ($generation['variant_set'] ?? 'single'));
        if (! isset($variant_options[$variant_set])) {
            $variant_set = 'single';
        }

        $observed_scan_cap = isset($args['observed_scan_cap']) ? absint($args['observed_scan_cap']) : (int) ($generation['observed_scan_cap'] ?? Settings::DEFAULT_OBSERVED_SCAN_CAP);
        if ($observed_scan_cap < Settings::MIN_OBSERVED_SCAN_CAP) {
            $observed_scan_cap = Settings::MIN_OBSERVED_SCAN_CAP;
        } elseif ($observed_scan_cap > Settings::MAX_OBSERVED_SCAN_CAP) {
            $observed_scan_cap = Settings::MAX_OBSERVED_SCAN_CAP;
        }

        $included_docs = isset($args['included_docs']) && is_array($args['included_docs'])
            ? array_map('sanitize_key', $args['included_docs'])
            : (isset($generation['included_docs']) && is_array($generation['included_docs']) ? $generation['included_docs'] : array_keys(Settings::get_included_doc_options()));
        $included_docs = array_values(array_intersect($included_docs, array_keys(Settings::get_included_doc_options())));

        return [
            'post_types' => self::normalize_string_list($post_types),
            'taxonomies' => self::normalize_string_list($taxonomies),
            'shape_mode' => $shape_mode,
            'value_style' => $value_style,
            'variant_set' => $variant_set,
            'observed_scan_cap' => $observed_scan_cap,
            'included_docs' => $included_docs,
        ];
    }

    /**
     * @param string              $workspace_path
     * @param array<string,mixed> $schema_bundle
     * @param array<string,mixed> $fingerprint
     * @param array<string,mixed> $validation_rules
     * @return array<string,mixed>|\WP_Error
     */
    private static function write_schema_artifacts(string $workspace_path, array $schema_bundle, array $fingerprint, array $validation_rules)
    {
        $artifacts = [
            'schema/object-inventory.json' => $schema_bundle['object_inventory'] ?? [],
            'schema/field-catalog.json' => $schema_bundle['field_catalog'] ?? [],
            'schema/validation-rules.json' => $validation_rules,
            'schema/site-fingerprint.json' => $fingerprint,
        ];

        if (
            (isset($schema_bundle['shape_mode']) && $schema_bundle['shape_mode'] === 'observed_shape')
            || ! empty($schema_bundle['observed_shape'])
        ) {
            $artifacts['schema/observed-shape.json'] = $schema_bundle['observed_shape'] ?? [];
        }

        $written = [];
        foreach ($artifacts as $relative_path => $payload) {
            $result = self::write_json_file($workspace_path, $relative_path, $payload);
            if (\is_wp_error($result)) {
                return $result;
            }

            $written[$relative_path] = [
                'path' => $relative_path,
                'sha256' => self::hash_payload($payload),
            ];
        }

        return $written;
    }

    /**
     * @param string              $workspace_path
     * @param array<string,mixed> $template_bundle
     * @return array<string,mixed>|\WP_Error
     */
    private static function write_sample_artifacts(string $workspace_path, array $template_bundle)
    {
        $paths = [
            'json' => [],
            'markdown' => [],
        ];

        foreach ((array) ($template_bundle['post_types'] ?? []) as $post_type => $template_set) {
            if (! is_array($template_set) || empty($template_set['variants']) || ! is_array($template_set['variants'])) {
                continue;
            }

            foreach ($template_set['variants'] as $variant => $variant_payload) {
                if (! is_array($variant_payload)) {
                    continue;
                }

                $basename = self::build_sample_basename((string) $variant);
                $json_path = 'samples/posts/' . sanitize_key((string) $post_type) . '/' . $basename . '.json';
                $md_path = 'samples/posts/' . sanitize_key((string) $post_type) . '/' . $basename . '.md';

                $json_result = self::write_json_file($workspace_path, $json_path, $variant_payload['template'] ?? []);
                if (\is_wp_error($json_result)) {
                    return $json_result;
                }

                $doc_result = self::write_text_file(
                    $workspace_path,
                    $md_path,
                    SampleDocBuilder::build_sample_doc('post', (string) $post_type, $variant_payload)
                );
                if (\is_wp_error($doc_result)) {
                    return $doc_result;
                }

                $paths['json'][] = $json_path;
                $paths['markdown'][] = $md_path;
            }
        }

        foreach ((array) ($template_bundle['taxonomies'] ?? []) as $taxonomy => $template_set) {
            if (! is_array($template_set) || empty($template_set['variants']) || ! is_array($template_set['variants'])) {
                continue;
            }

            foreach ($template_set['variants'] as $variant => $variant_payload) {
                if (! is_array($variant_payload)) {
                    continue;
                }

                $basename = self::build_sample_basename((string) $variant);
                $json_path = 'samples/terms/' . sanitize_key((string) $taxonomy) . '/' . $basename . '.json';
                $md_path = 'samples/terms/' . sanitize_key((string) $taxonomy) . '/' . $basename . '.md';

                $json_result = self::write_json_file($workspace_path, $json_path, $variant_payload['template'] ?? []);
                if (\is_wp_error($json_result)) {
                    return $json_result;
                }

                $doc_result = self::write_text_file(
                    $workspace_path,
                    $md_path,
                    SampleDocBuilder::build_sample_doc('term', (string) $taxonomy, $variant_payload)
                );
                if (\is_wp_error($doc_result)) {
                    return $doc_result;
                }

                $paths['json'][] = $json_path;
                $paths['markdown'][] = $md_path;
            }
        }

        return [
            'paths' => $paths,
            'sample_json_files' => count($paths['json']),
            'sample_markdown_files' => count($paths['markdown']),
        ];
    }

    /**
     * @param string              $workspace_path
     * @param array<string,string> $root_docs
     * @return array<int,string>|\WP_Error
     */
    private static function write_root_docs(string $workspace_path, array $root_docs)
    {
        $paths = [];
        foreach ($root_docs as $filename => $content) {
            $result = self::write_text_file($workspace_path, $filename, $content);
            if (\is_wp_error($result)) {
                return $result;
            }

            $paths[] = $filename;
        }

        return $paths;
    }

    /**
     * @param string              $package_id
     * @param array<string,mixed> $build_args
     * @param array<string,mixed> $schema_bundle
     * @param array<string,mixed> $template_bundle
     * @param array<string,mixed> $fingerprint
     * @param array<string,mixed> $validation_rules
     * @param array<string,mixed> $artifacts
     * @param array<string,mixed> $sample_artifacts
     * @param array<int,string>   $root_doc_paths
     * @param array<string,mixed> $counts
     * @return array<string,mixed>
     */
    private static function build_manifest(
        string $package_id,
        array $build_args,
        array $schema_bundle,
        array $template_bundle,
        array $fingerprint,
        array $validation_rules,
        array $artifacts,
        array $sample_artifacts,
        array $root_doc_paths,
        array $counts
    ): array {
        $selection = isset($schema_bundle['selection']) && is_array($schema_bundle['selection']) ? $schema_bundle['selection'] : [
            'post_types' => $build_args['post_types'],
            'taxonomies' => $build_args['taxonomies'],
        ];
        $variants = isset($template_bundle['variants']) && is_array($template_bundle['variants']) ? array_values(array_map('strval', $template_bundle['variants'])) : ['single'];
        $artifact_fingerprints = [];
        $artifact_paths = [];
        foreach ($artifacts as $relative_path => $artifact) {
            if (! is_array($artifact)) {
                continue;
            }

            $fingerprint_key = preg_replace('/[^a-z0-9_]+/i', '_', str_replace(['schema/', '.json'], '', $relative_path));
            $artifact_fingerprints[(string) $fingerprint_key] = (string) ($artifact['sha256'] ?? '');
            $artifact_paths[(string) $fingerprint_key] = (string) ($artifact['path'] ?? $relative_path);
        }

        return [
            'package_type' => self::PACKAGE_TYPE,
            'package_schema_version' => self::PACKAGE_SCHEMA_VERSION,
            'package_id' => $package_id,
            'generated_at' => current_time('c'),
            'dbvc_version' => defined('DBVC_PLUGIN_VERSION') ? (string) DBVC_PLUGIN_VERSION : '',
            'site_origin' => [
                'home_url' => isset($fingerprint['origin']['home_url']) ? (string) $fingerprint['origin']['home_url'] : trailingslashit(home_url('/')),
                'name' => isset($fingerprint['origin']['site_name']) ? (string) $fingerprint['origin']['site_name'] : (string) get_bloginfo('name'),
                'blog_id' => isset($fingerprint['origin']['blog_id']) ? (int) $fingerprint['origin']['blog_id'] : 0,
            ],
            'site_fingerprint' => isset($fingerprint['site_fingerprint']) ? (string) $fingerprint['site_fingerprint'] : '',
            'generation' => [
                'shape_mode' => $build_args['shape_mode'],
                'value_style' => $build_args['value_style'],
                'variant_set' => $build_args['variant_set'],
                'variants' => $variants,
                'observed_scan_cap' => (int) $build_args['observed_scan_cap'],
                'included_docs' => array_values($build_args['included_docs']),
            ],
            'selection' => $selection,
            'fingerprints' => $artifact_fingerprints,
            'artifacts' => [
                'schema' => $artifact_paths,
                'docs' => array_values($root_doc_paths),
                'samples' => [
                    'posts' => 'samples/posts',
                    'terms' => 'samples/terms',
                ],
            ],
            'counts' => $counts,
            'validation_defaults' => isset($validation_rules['validation_defaults']) && is_array($validation_rules['validation_defaults']) ? $validation_rules['validation_defaults'] : [],
            'sample_files' => isset($sample_artifacts['paths']) && is_array($sample_artifacts['paths']) ? $sample_artifacts['paths'] : [],
        ];
    }

    /**
     * @param array<string,mixed> $build_args
     * @param array<string,mixed> $schema_bundle
     * @param array<string,mixed> $template_bundle
     * @param array<string,mixed> $fingerprint
     * @param array<string,mixed> $validation_rules
     * @param array<string,mixed> $counts
     * @return array<string,mixed>
     */
    private static function build_doc_context(
        array $build_args,
        array $schema_bundle,
        array $template_bundle,
        array $fingerprint,
        array $validation_rules,
        array $counts
    ): array {
        return [
            'selection' => isset($schema_bundle['selection']) && is_array($schema_bundle['selection']) ? $schema_bundle['selection'] : [
                'post_types' => $build_args['post_types'],
                'taxonomies' => $build_args['taxonomies'],
            ],
            'variants' => isset($template_bundle['variants']) && is_array($template_bundle['variants']) ? $template_bundle['variants'] : ['single'],
            'site_origin' => [
                'home_url' => isset($fingerprint['origin']['home_url']) ? (string) $fingerprint['origin']['home_url'] : trailingslashit(home_url('/')),
                'name' => isset($fingerprint['origin']['site_name']) ? (string) $fingerprint['origin']['site_name'] : (string) get_bloginfo('name'),
            ],
            'site_fingerprint' => isset($fingerprint['site_fingerprint']) ? (string) $fingerprint['site_fingerprint'] : '',
            'validation_rules' => $validation_rules,
            'counts' => $counts,
        ];
    }

    /**
     * @param string              $workspace_path
     * @param string              $relative_path
     * @param array<string,mixed>|array<int,mixed> $payload
     * @return true|\WP_Error
     */
    private static function write_json_file(string $workspace_path, string $relative_path, $payload)
    {
        $encoded = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            return new \WP_Error('dbvc_ai_json_encode_failed', sprintf(__('Unable to encode JSON for %s.', 'dbvc'), $relative_path));
        }

        return self::write_text_file($workspace_path, $relative_path, $encoded . "\n");
    }

    /**
     * @param string $workspace_path
     * @param string $relative_path
     * @param string $contents
     * @return true|\WP_Error
     */
    private static function write_text_file(string $workspace_path, string $relative_path, string $contents)
    {
        $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
        if ($relative_path === '' || strpos($relative_path, '..') !== false) {
            return new \WP_Error('dbvc_ai_path_invalid', __('AI package artifact path is invalid.', 'dbvc'));
        }

        $absolute_path = wp_normalize_path(trailingslashit($workspace_path) . $relative_path);
        if (strpos($absolute_path, wp_normalize_path(trailingslashit($workspace_path))) !== 0) {
            return new \WP_Error('dbvc_ai_path_outside_workspace', __('AI package artifact path escaped the workspace root.', 'dbvc'));
        }

        $directory = wp_normalize_path(dirname($absolute_path));
        $directory_result = Storage::ensure_directory($directory);
        if (\is_wp_error($directory_result)) {
            return $directory_result;
        }

        $written = file_put_contents($absolute_path, $contents);
        if ($written === false) {
            return new \WP_Error(
                'dbvc_ai_write_failed',
                sprintf(__('Unable to write AI package artifact: %s', 'dbvc'), $relative_path)
            );
        }

        return true;
    }

    /**
     * @param string $workspace_path
     * @param string $zip_path
     * @return true|\WP_Error
     */
    private static function build_zip_archive(string $workspace_path, string $zip_path)
    {
        $zip_directory = wp_normalize_path(dirname($zip_path));
        $directory_result = Storage::ensure_directory($zip_directory);
        if (\is_wp_error($directory_result)) {
            return $directory_result;
        }

        $zip = new \ZipArchive();
        $open = $zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($open !== true) {
            return new \WP_Error('dbvc_ai_zip_open_failed', __('Unable to create the AI sample package ZIP archive.', 'dbvc'));
        }

        $base = trailingslashit(wp_normalize_path($workspace_path));
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($workspace_path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $directories = [];
        $files = [];
        foreach ($iterator as $file) {
            $path = wp_normalize_path($file->getPathname());
            $local = ltrim(str_replace($base, '', $path), '/');
            if ($local === '') {
                continue;
            }

            if ($file->isDir()) {
                $directories[$local] = $path;
                continue;
            }

            $files[$local] = $path;
        }

        ksort($directories);
        ksort($files);

        foreach ($directories as $local => $path) {
            unset($path);
            $zip->addEmptyDir($local);
        }

        foreach ($files as $local => $path) {
            $zip->addFile($path, $local);
        }

        $zip->close();

        return true;
    }

    /**
     * @param string $workspace_path
     * @param string $zip_path
     * @return void
     */
    private static function cleanup_paths(string $workspace_path, string $zip_path): void
    {
        if (is_file($zip_path)) {
            @unlink($zip_path);
        }

        if (is_dir($workspace_path)) {
            self::delete_directory($workspace_path);
        }
    }

    /**
     * @param string $directory
     * @return void
     */
    private static function delete_directory(string $directory): void
    {
        $directory = wp_normalize_path($directory);
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }

    /**
     * @return string
     */
    private static function build_package_id(): string
    {
        $token = strtolower(wp_generate_password(6, false, false));
        $timestamp = gmdate('Ymd-His');

        return sanitize_key('dbvc-ai-sample-' . $timestamp . '-' . $token);
    }

    /**
     * @param string $package_id
     * @return string
     */
    private static function build_download_filename(string $package_id): string
    {
        return sanitize_file_name($package_id . '.zip');
    }

    /**
     * @param string $variant
     * @return string
     */
    private static function build_sample_basename(string $variant): string
    {
        if ($variant === 'single') {
            return 'sample';
        }

        return 'sample-' . sanitize_key($variant);
    }

    /**
     * @param mixed $payload
     * @return string
     */
    private static function hash_payload($payload): string
    {
        return hash('sha256', (string) wp_json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<int|string,mixed> $values
     * @return array<int,string>
     */
    private static function normalize_string_list(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $string = sanitize_key((string) $value);
            if ($string === '') {
                continue;
            }

            $normalized[$string] = $string;
        }

        ksort($normalized);

        return array_values($normalized);
    }

    /**
     * @param array<string,mixed> $build_args
     * @return true|\WP_Error
     */
    private static function validate_selection(array $build_args)
    {
        $available_post_types = function_exists('dbvc_get_available_post_types')
            ? array_keys((array) dbvc_get_available_post_types())
            : array_keys((array) get_post_types(['show_ui' => true], 'objects'));
        $available_taxonomies = function_exists('dbvc_get_available_taxonomies')
            ? array_keys((array) dbvc_get_available_taxonomies())
            : array_keys((array) get_taxonomies(['show_ui' => true], 'objects'));

        $unsupported_post_types = array_values(array_diff($build_args['post_types'], array_map('sanitize_key', $available_post_types)));
        if (! empty($unsupported_post_types)) {
            return new \WP_Error(
                'dbvc_ai_invalid_post_types',
                sprintf(
                    __('Unsupported post types selected: %s', 'dbvc'),
                    implode(', ', array_map('sanitize_text_field', $unsupported_post_types))
                )
            );
        }

        $unsupported_taxonomies = array_values(array_diff($build_args['taxonomies'], array_map('sanitize_key', $available_taxonomies)));
        if (! empty($unsupported_taxonomies)) {
            return new \WP_Error(
                'dbvc_ai_invalid_taxonomies',
                sprintf(
                    __('Unsupported taxonomies selected: %s', 'dbvc'),
                    implode(', ', array_map('sanitize_text_field', $unsupported_taxonomies))
                )
            );
        }

        return true;
    }
}
