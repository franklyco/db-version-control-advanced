<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class SubmissionPackageValidator
{
    private const PACKAGE_TYPE_SUBMISSION = 'dbvc_ai_submission_package';
    private const PACKAGE_TYPE_SAMPLE = 'dbvc_ai_sample_package';
    private const PACKAGE_SCHEMA_VERSION = 1;
    private const MAX_ARCHIVE_ENTRIES = 5000;
    private const MAX_UNCOMPRESSED_BYTES = 52428800;

    /**
     * @param array<string,mixed> $uploaded_file
     * @return array<string,mixed>|\WP_Error
     */
    public static function intake_uploaded_zip(array $uploaded_file)
    {
        if (! class_exists('\ZipArchive')) {
            return new \WP_Error('dbvc_ai_zip_missing', __('AI intake requires PHP ZipArchive support.', 'dbvc'));
        }

        $tmp_name = isset($uploaded_file['tmp_name']) ? (string) $uploaded_file['tmp_name'] : '';
        if ($tmp_name === '' || ! is_file($tmp_name)) {
            return new \WP_Error('dbvc_ai_upload_missing', __('Uploaded AI archive is unavailable.', 'dbvc'));
        }

        $storage = Storage::ensure_base_roots();
        if (\is_wp_error($storage)) {
            return $storage;
        }

        $intake_id = self::build_intake_id();
        $workspace_path = Storage::resolve_intake_workspace($intake_id);
        if (\is_wp_error($workspace_path)) {
            return $workspace_path;
        }

        $directories = [
            'workspace' => $workspace_path,
            'source' => wp_normalize_path(trailingslashit($workspace_path) . 'source'),
            'extracted' => wp_normalize_path(trailingslashit($workspace_path) . 'extracted'),
            'translated' => wp_normalize_path(trailingslashit($workspace_path) . 'translated'),
            'reports' => wp_normalize_path(trailingslashit($workspace_path) . 'reports'),
        ];
        foreach ($directories as $directory) {
            $result = Storage::ensure_directory($directory);
            if (\is_wp_error($result)) {
                return $result;
            }
        }

        $original_filename = sanitize_file_name((string) ($uploaded_file['name'] ?? 'dbvc-ai-upload.zip'));
        if ($original_filename === '') {
            $original_filename = 'dbvc-ai-upload.zip';
        }

        $source_archive_relative = 'source/' . $original_filename;
        $source_archive_path = wp_normalize_path(trailingslashit($directories['workspace']) . $source_archive_relative);
        if (! @copy($tmp_name, $source_archive_path)) {
            return new \WP_Error('dbvc_ai_source_copy_failed', __('Unable to stage the uploaded AI archive.', 'dbvc'));
        }

        $inspection = SubmissionPackageDetector::inspect_uploaded_zip($source_archive_path);
        $current_context = SiteFingerprintService::build_current_context();
        $current_fingerprint = isset($current_context['site_fingerprint']) ? (string) $current_context['site_fingerprint'] : '';
        $schema_bundle = isset($current_context['schema_bundle']) && is_array($current_context['schema_bundle'])
            ? $current_context['schema_bundle']
            : [];
        $validation_rules = isset($schema_bundle['validation_rules']) && is_array($schema_bundle['validation_rules'])
            ? $schema_bundle['validation_rules']
            : RulesService::build_validation_rules($schema_bundle);
        $issues = [];
        $entities = [];
        $package_type = '';
        $package_schema_version = null;
        $intended_operation = '';
        $content_root_relative = '';
        $content_root_path = '';
        $translation_artifacts = [
            'translation_manifest' => null,
            'translated_sync_root' => null,
        ];
        $translation_counts = [];

        $zip = new \ZipArchive();
        if ($zip->open($source_archive_path) !== true) {
            return new \WP_Error('dbvc_ai_zip_open_failed', __('Unable to open the staged AI archive.', 'dbvc'));
        }

        $archive_audit = self::validate_archive_entries($zip);
        foreach ($archive_audit['issues'] as $issue) {
            $issues[] = $issue;
        }

        if (! $archive_audit['blocked']) {
            $extract_target = wp_normalize_path(trailingslashit($directories['extracted']) . 'package');
            $extract_result = Storage::ensure_directory($extract_target);
            if (\is_wp_error($extract_result)) {
                $zip->close();
                return $extract_result;
            }

            if (! $zip->extractTo($extract_target)) {
                $issues[] = self::build_issue('blocked', 'archive_extract_failed', __('The AI archive could not be extracted.', 'dbvc'));
            } else {
                $wrapper_dir = is_array($inspection) ? (string) ($inspection['wrapper_dir'] ?? '') : '';
                $content_root_path = $wrapper_dir !== ''
                    ? wp_normalize_path(trailingslashit($extract_target) . $wrapper_dir)
                    : $extract_target;
                $content_root_relative = $wrapper_dir !== ''
                    ? 'extracted/package/' . $wrapper_dir
                    : 'extracted/package';
            }
        }

        $zip->close();

        $manifest = is_array($inspection) && isset($inspection['manifest']) && is_array($inspection['manifest'])
            ? $inspection['manifest']
            : null;

        if (! is_array($inspection)) {
            $issues[] = self::build_issue('blocked', 'manifest_missing', __('The uploaded archive does not contain a root AI package manifest.', 'dbvc'));
        } else {
            if (! is_array($manifest)) {
                $issues[] = self::build_issue('blocked', 'manifest_invalid', __('The AI package manifest is missing or invalid JSON.', 'dbvc'), (string) ($inspection['manifest_entry'] ?? ''));
            } else {
                $package_type = isset($manifest['package_type']) ? (string) $manifest['package_type'] : '';
                $package_schema_version = isset($manifest['package_schema_version']) ? (int) $manifest['package_schema_version'] : null;
                $intended_operation = isset($manifest['intended_operation']) ? sanitize_key((string) $manifest['intended_operation']) : '';

                if ($package_type === self::PACKAGE_TYPE_SAMPLE) {
                    $issues[] = self::build_issue('blocked', 'wrong_package_type', __('This archive is a DBVC AI sample package and cannot be imported. Upload a returned AI submission package instead.', 'dbvc'), (string) ($inspection['manifest_entry'] ?? ''));
                } elseif ($package_type !== self::PACKAGE_TYPE_SUBMISSION) {
                    $issues[] = self::build_issue('blocked', 'package_type_invalid', __('The AI package type is invalid for import.', 'dbvc'), (string) ($inspection['manifest_entry'] ?? ''));
                }

                if ($package_schema_version !== self::PACKAGE_SCHEMA_VERSION) {
                    $issues[] = self::build_issue('blocked', 'schema_version_mismatch', __('The AI package schema version does not match the current DBVC intake contract.', 'dbvc'), (string) ($inspection['manifest_entry'] ?? ''));
                }

                $manifest_fingerprint = isset($manifest['source_sample_package']['site_fingerprint'])
                    ? (string) $manifest['source_sample_package']['site_fingerprint']
                    : '';
                if ($manifest_fingerprint === '') {
                    $issues[] = self::build_issue('blocked', 'site_fingerprint_missing', __('The AI package does not declare a source sample package site fingerprint.', 'dbvc'), (string) ($inspection['manifest_entry'] ?? ''));
                } elseif ($manifest_fingerprint !== $current_fingerprint) {
                    $issues[] = self::build_issue('blocked', 'site_fingerprint_mismatch', __('The AI package was generated for a different site fingerprint.', 'dbvc'), (string) ($inspection['manifest_entry'] ?? ''));
                }

                if (! in_array($intended_operation, ['create_or_update', 'create_only', 'update_only'], true)) {
                    $issues[] = self::build_issue('blocked', 'operation_invalid', __('The AI package intended operation is invalid.', 'dbvc'), (string) ($inspection['manifest_entry'] ?? ''));
                }
            }
        }

        if ($content_root_path !== '' && is_dir($content_root_path)) {
            foreach (self::validate_content_root_layout($content_root_path) as $issue) {
                $issues[] = $issue;
            }

            $entity_result = self::collect_entity_files($content_root_path, $schema_bundle, $validation_rules);
            $entities = $entity_result['entities'];
            foreach ($entity_result['issues'] as $issue) {
                $issues[] = $issue;
            }

            if ($manifest !== null && isset($manifest['counts']) && is_array($manifest['counts'])) {
                $declared_posts = isset($manifest['counts']['post_entities']) ? (int) $manifest['counts']['post_entities'] : null;
                $declared_terms = isset($manifest['counts']['term_entities']) ? (int) $manifest['counts']['term_entities'] : null;
                $actual_posts = self::count_entities_by_kind($entities, 'post');
                $actual_terms = self::count_entities_by_kind($entities, 'term');

                if ($declared_posts !== null && $declared_posts !== $actual_posts) {
                    $issues[] = self::build_issue('warning', 'entity_count_mismatch', __('Manifest post entity count does not match the extracted package contents.', 'dbvc'));
                }

                if ($declared_terms !== null && $declared_terms !== $actual_terms) {
                    $issues[] = self::build_issue('warning', 'entity_count_mismatch', __('Manifest term entity count does not match the extracted package contents.', 'dbvc'));
                }
            }
        } elseif ($content_root_relative !== '') {
            $issues[] = self::build_issue('blocked', 'content_root_missing', __('The normalized AI package content root could not be found after extraction.', 'dbvc'), $content_root_relative);
        }

        if ($content_root_path !== '' && is_dir($content_root_path) && self::resolve_status($issues) !== 'blocked' && class_exists('\Dbvc\AiPackage\SubmissionPackageTranslator')) {
            $translation_result = SubmissionPackageTranslator::translate([
                'workspace_path' => $directories['workspace'],
                'content_root_path' => $content_root_path,
                'translated_root' => $directories['translated'],
                'manifest' => is_array($manifest) ? $manifest : [],
                'schema_bundle' => $schema_bundle,
                'validation_rules' => $validation_rules,
                'entities' => $entities,
            ]);

            if (\is_wp_error($translation_result)) {
                $issues[] = self::build_issue('blocked', 'translation_failed', $translation_result->get_error_message(), 'translated');
            } else {
                $translation_artifacts = isset($translation_result['artifacts']) && is_array($translation_result['artifacts'])
                    ? array_merge($translation_artifacts, $translation_result['artifacts'])
                    : $translation_artifacts;
                $translation_counts = isset($translation_result['counts']) && is_array($translation_result['counts'])
                    ? $translation_result['counts']
                    : [];
                $entities = isset($translation_result['entities']) && is_array($translation_result['entities'])
                    ? $translation_result['entities']
                    : $entities;

                if (! empty($translation_result['issues']) && is_array($translation_result['issues'])) {
                    foreach ($translation_result['issues'] as $issue) {
                        if (is_array($issue)) {
                            $issues[] = $issue;
                        }
                    }
                }
            }
        }

        $status = self::resolve_status($issues);
        $counts = [
            'post_entities' => self::count_entities_by_kind($entities, 'post'),
            'term_entities' => self::count_entities_by_kind($entities, 'term'),
            'issues' => count($issues),
            'warnings' => self::count_issues_by_severity($issues, 'warning'),
            'blocked' => self::count_issues_by_severity($issues, 'error'),
            'translated_entities' => isset($translation_counts['translated_entities']) ? (int) $translation_counts['translated_entities'] : 0,
            'translated_posts' => isset($translation_counts['translated_posts']) ? (int) $translation_counts['translated_posts'] : 0,
            'translated_terms' => isset($translation_counts['translated_terms']) ? (int) $translation_counts['translated_terms'] : 0,
        ];

        $report = [
            'mode' => 'ai_package',
            'generated_at' => current_time('mysql'),
            'generated_at_gmt' => current_time('c'),
            'intake_id' => $intake_id,
            'status' => $status,
            'package_type' => $package_type,
            'package_schema_version' => $package_schema_version,
            'intended_operation' => $intended_operation,
            'source_archive' => [
                'original_filename' => $original_filename,
                'stored_path' => $source_archive_relative,
            ],
            'artifacts' => [
                'source_archive' => $source_archive_relative,
                'extracted_root' => $content_root_relative,
                'validation_report' => 'reports/validation-report.json',
                'validation_summary' => 'reports/validation-summary.md',
                'translation_manifest' => $translation_artifacts['translation_manifest'],
                'translated_sync_root' => $translation_artifacts['translated_sync_root'],
            ],
            'site_fingerprint' => $current_fingerprint,
            'manifest' => is_array($manifest) ? $manifest : null,
            'counts' => $counts,
            'issues' => $issues,
            'entities' => $entities,
            'workspace_path' => $workspace_path,
        ];

        $report_json_path = wp_normalize_path(trailingslashit($directories['reports']) . 'validation-report.json');
        $report_summary_path = wp_normalize_path(trailingslashit($directories['reports']) . 'validation-summary.md');
        self::write_text_file($report_json_path, (string) wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        self::write_text_file($report_summary_path, ValidationReportFormatter::build_markdown_summary($report) . "\n");

        return $report;
    }

    /**
     * @param \ZipArchive $zip
     * @return array<string,mixed>
     */
    private static function validate_archive_entries(\ZipArchive $zip): array
    {
        $issues = [];
        $entry_count = 0;
        $total_bytes = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (! is_array($stat)) {
                continue;
            }

            $name = isset($stat['name']) ? (string) $stat['name'] : '';
            $normalized = str_replace('\\', '/', $name);
            $normalized = ltrim($normalized, '/');
            if ($normalized === '') {
                continue;
            }

            $entry_count++;
            $total_bytes += isset($stat['size']) ? (int) $stat['size'] : 0;

            if (
                strpos($normalized, '../') !== false
                || strpos($normalized, '..\\') !== false
                || preg_match('#^([A-Za-z]:)?[\\\\/]#', $name)
            ) {
                $issues[] = self::build_issue('blocked', 'archive_entry_unsafe', __('The AI archive contains an unsafe path entry.', 'dbvc'), $name);
            }
        }

        if ($entry_count > self::MAX_ARCHIVE_ENTRIES) {
            $issues[] = self::build_issue('blocked', 'archive_entry_limit_exceeded', __('The AI archive contains too many files to process safely.', 'dbvc'));
        }

        if ($total_bytes > self::MAX_UNCOMPRESSED_BYTES) {
            $issues[] = self::build_issue('blocked', 'archive_size_limit_exceeded', __('The AI archive exceeds the current safe extraction size limit.', 'dbvc'));
        }

        return [
            'issues' => $issues,
            'blocked' => self::count_issues_by_severity($issues, 'error') > 0,
        ];
    }

    /**
     * @param string $content_root
     * @return array<string,mixed>
     */
    private static function collect_entity_files(string $content_root, array $schema_bundle, array $validation_rules): array
    {
        $issues = [];
        $entities = [];
        $entity_keys = [];
        $entities_root = wp_normalize_path(trailingslashit($content_root) . 'entities');
        if (! is_dir($entities_root)) {
            $issues[] = self::build_issue('blocked', 'entities_missing', __('The AI package does not contain an `entities/` directory.', 'dbvc'), 'entities');

            return [
                'issues' => $issues,
                'entities' => $entities,
            ];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($entities_root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue;
            }

            $absolute_path = wp_normalize_path($item->getPathname());
            $relative_path = ltrim(str_replace(trailingslashit($content_root), '', $absolute_path), '/');
            if (strtolower(pathinfo($absolute_path, PATHINFO_EXTENSION)) !== 'json') {
                $issues[] = self::build_issue('error', 'entity_extension_invalid', __('Entity files inside `entities/` must use the `.json` extension.', 'dbvc'), $relative_path);
                continue;
            }

            $entity = self::build_entity_record($relative_path);
            if ($entity === null) {
                $issues[] = self::build_issue('warning', 'entity_path_unrecognized', __('A JSON file inside the AI package did not match the canonical entity layout.', 'dbvc'), $relative_path);
                continue;
            }

            $entity_key = $entity['entity_kind'] . '::' . $entity['object_key'] . '::' . $entity['slug'];
            if (isset($entity_keys[$entity_key])) {
                $issues[] = self::build_issue('error', 'entity_duplicate', __('The AI package contains duplicate logical entities for the same object key and slug.', 'dbvc'), $relative_path);
            } else {
                $entity_keys[$entity_key] = true;
            }

            $raw = file_get_contents($absolute_path);
            $payload = is_string($raw) ? json_decode($raw, true) : null;
            if (! is_array($payload)) {
                $issues[] = self::build_issue('error', 'entity_json_invalid', __('An entity JSON file is invalid and cannot be processed.', 'dbvc'), $relative_path);
                $entity['status'] = 'invalid_json';
            } else {
                $entity_validation = self::validate_entity_payload($entity, $payload, $schema_bundle, $validation_rules);
                $entity['status'] = empty($entity_validation['issues']) ? 'validated' : self::resolve_status($entity_validation['issues']);
                $entity['intent'] = (string) ($entity_validation['intent'] ?? 'create');
                foreach ($entity_validation['issues'] as $issue) {
                    $issues[] = $issue;
                }
            }

            $entities[] = $entity;
        }

        if (empty($entities)) {
            $issues[] = self::build_issue('error', 'entities_empty', __('The AI package does not contain any recognized entity JSON files.', 'dbvc'), 'entities');
        }

        return [
            'issues' => $issues,
            'entities' => $entities,
        ];
    }

    /**
     * @param string $relative_path
     * @return array<string,mixed>|null
     */
    private static function build_entity_record(string $relative_path)
    {
        if (preg_match('#^entities/posts/([^/]+)/([^/]+)\.json$#', $relative_path, $matches)) {
            return [
                'entity_kind' => 'post',
                'object_key' => sanitize_key($matches[1]),
                'slug' => sanitize_title($matches[2]),
                'path' => $relative_path,
            ];
        }

        if (preg_match('#^entities/terms/([^/]+)/([^/]+)\.json$#', $relative_path, $matches)) {
            return [
                'entity_kind' => 'term',
                'object_key' => sanitize_key($matches[1]),
                'slug' => sanitize_title($matches[2]),
                'path' => $relative_path,
            ];
        }

        return null;
    }

    /**
     * @param string              $content_root
     * @return array<int,array<string,mixed>>
     */
    private static function validate_content_root_layout(string $content_root): array
    {
        $issues = [];
        $allowed_roots = [
            SamplePackageBuilder::MANIFEST_FILENAME,
            'entities',
            'docs',
            'reports',
        ];
        $entries = @scandir($content_root);
        if (! is_array($entries)) {
            return $issues;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (! in_array($entry, $allowed_roots, true)) {
                $issues[] = self::build_issue('warning', 'root_path_unexpected', __('The AI package contains an unexpected top-level path.', 'dbvc'), $entry);
            }
        }

        return $issues;
    }

    /**
     * @param array<string,mixed> $entity
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $schema_bundle
     * @param array<string,mixed> $validation_rules
     * @return array<string,mixed>
     */
    private static function validate_entity_payload(array $entity, array $payload, array $schema_bundle, array $validation_rules): array
    {
        $issues = [];
        $entity_kind = (string) ($entity['entity_kind'] ?? '');
        $object_key = (string) ($entity['object_key'] ?? '');
        $file_slug = (string) ($entity['slug'] ?? '');
        $field_catalog = isset($schema_bundle['field_catalog']) && is_array($schema_bundle['field_catalog']) ? $schema_bundle['field_catalog'] : [];

        if ($entity_kind === 'post') {
            $catalog_entry = isset($field_catalog['post_types'][$object_key]) && is_array($field_catalog['post_types'][$object_key])
                ? $field_catalog['post_types'][$object_key]
                : null;
            $required = ['ID', 'post_type', 'post_title', 'post_name'];
            $allowed = $catalog_entry && isset($catalog_entry['core_fields']) && is_array($catalog_entry['core_fields'])
                ? array_keys($catalog_entry['core_fields'])
                : [];
            $allowed[] = 'vf_object_uid';
            $blocked_meta = isset($validation_rules['post_contract']['blocked_meta_keys']) && is_array($validation_rules['post_contract']['blocked_meta_keys'])
                ? $validation_rules['post_contract']['blocked_meta_keys']
                : [];
            $blocked_top_level = isset($validation_rules['post_contract']['blocked_top_level_fields']) && is_array($validation_rules['post_contract']['blocked_top_level_fields'])
                ? $validation_rules['post_contract']['blocked_top_level_fields']
                : [];

            if (! is_array($catalog_entry)) {
                $issues[] = self::build_issue('error', 'post_type_unknown', __('The post type in the entity path is not in the current DBVC AI schema scope.', 'dbvc'), (string) ($entity['path'] ?? ''));
            }

            foreach ($required as $field_name) {
                if (! array_key_exists($field_name, $payload)) {
                    $issues[] = self::build_issue('error', 'field_required', sprintf(__('Missing required field `%s`.', 'dbvc'), $field_name), (string) ($entity['path'] ?? '') . '#' . $field_name);
                }
            }

            if (isset($payload['post_type']) && sanitize_key((string) $payload['post_type']) !== $object_key) {
                $issues[] = self::build_issue('error', 'object_key_mismatch', __('The payload `post_type` does not match the entity path.', 'dbvc'), (string) ($entity['path'] ?? '') . '#post_type');
            }

            if (isset($payload['post_name']) && sanitize_title((string) $payload['post_name']) !== $file_slug) {
                $issues[] = self::build_issue('warning', 'slug_mismatch', __('The payload `post_name` does not match the entity filename slug.', 'dbvc'), (string) ($entity['path'] ?? '') . '#post_name');
            }

            if (isset($payload['ID']) && ! is_numeric($payload['ID'])) {
                $issues[] = self::build_issue('error', 'field_type_invalid', __('`ID` must be numeric.', 'dbvc'), (string) ($entity['path'] ?? '') . '#ID');
            }

            if (isset($payload['meta']) && ! is_array($payload['meta'])) {
                $issues[] = self::build_issue('error', 'field_type_invalid', __('`meta` must be an object/associative array.', 'dbvc'), (string) ($entity['path'] ?? '') . '#meta');
            }

            if (isset($payload['tax_input']) && ! is_array($payload['tax_input'])) {
                $issues[] = self::build_issue('error', 'field_type_invalid', __('`tax_input` must be an object/associative array.', 'dbvc'), (string) ($entity['path'] ?? '') . '#tax_input');
            }

            if (isset($payload['meta']) && is_array($payload['meta'])) {
                foreach ($blocked_meta as $meta_key) {
                    if (array_key_exists($meta_key, $payload['meta'])) {
                        $issues[] = self::build_issue('error', 'meta_key_blocked', sprintf(__('The meta key `%s` is blocked in AI submission packages.', 'dbvc'), $meta_key), (string) ($entity['path'] ?? '') . '#meta.' . $meta_key);
                    }
                }
            }

            foreach ($blocked_top_level as $field_name) {
                if (array_key_exists($field_name, $payload)) {
                    $issues[] = self::build_issue('error', 'field_blocked', sprintf(__('The top-level field `%s` is blocked in AI submission packages.', 'dbvc'), $field_name), (string) ($entity['path'] ?? '') . '#' . $field_name);
                }
            }

            foreach (array_keys($payload) as $field_name) {
                if (! in_array($field_name, $allowed, true)) {
                    $issues[] = self::build_issue('warning', 'field_unexpected', sprintf(__('Unexpected top-level field `%s` was provided.', 'dbvc'), $field_name), (string) ($entity['path'] ?? '') . '#' . $field_name);
                }
            }

            return [
                'issues' => $issues,
                'intent' => self::infer_entity_intent($payload, 'ID'),
            ];
        }

        $catalog_entry = isset($field_catalog['taxonomies'][$object_key]) && is_array($field_catalog['taxonomies'][$object_key])
            ? $field_catalog['taxonomies'][$object_key]
            : null;
        $required = ['term_id', 'taxonomy', 'name', 'slug'];
        $allowed = $catalog_entry && isset($catalog_entry['core_fields']) && is_array($catalog_entry['core_fields'])
            ? array_keys($catalog_entry['core_fields'])
            : [];
        $allowed[] = 'vf_object_uid';
        $blocked_meta = isset($validation_rules['term_contract']['blocked_meta_keys']) && is_array($validation_rules['term_contract']['blocked_meta_keys'])
            ? $validation_rules['term_contract']['blocked_meta_keys']
            : [];
        $blocked_top_level = isset($validation_rules['term_contract']['blocked_top_level_fields']) && is_array($validation_rules['term_contract']['blocked_top_level_fields'])
            ? $validation_rules['term_contract']['blocked_top_level_fields']
            : [];

        if (! is_array($catalog_entry)) {
            $issues[] = self::build_issue('error', 'taxonomy_unknown', __('The taxonomy in the entity path is not in the current DBVC AI schema scope.', 'dbvc'), (string) ($entity['path'] ?? ''));
        }

        foreach ($required as $field_name) {
            if (! array_key_exists($field_name, $payload)) {
                $issues[] = self::build_issue('error', 'field_required', sprintf(__('Missing required field `%s`.', 'dbvc'), $field_name), (string) ($entity['path'] ?? '') . '#' . $field_name);
            }
        }

        if (isset($payload['taxonomy']) && sanitize_key((string) $payload['taxonomy']) !== $object_key) {
            $issues[] = self::build_issue('error', 'object_key_mismatch', __('The payload `taxonomy` does not match the entity path.', 'dbvc'), (string) ($entity['path'] ?? '') . '#taxonomy');
        }

        if (isset($payload['slug']) && sanitize_title((string) $payload['slug']) !== $file_slug) {
            $issues[] = self::build_issue('warning', 'slug_mismatch', __('The payload `slug` does not match the entity filename slug.', 'dbvc'), (string) ($entity['path'] ?? '') . '#slug');
        }

        if (isset($payload['term_id']) && ! is_numeric($payload['term_id'])) {
            $issues[] = self::build_issue('error', 'field_type_invalid', __('`term_id` must be numeric.', 'dbvc'), (string) ($entity['path'] ?? '') . '#term_id');
        }

        if (isset($payload['meta']) && ! is_array($payload['meta'])) {
            $issues[] = self::build_issue('error', 'field_type_invalid', __('`meta` must be an object/associative array.', 'dbvc'), (string) ($entity['path'] ?? '') . '#meta');
        }

        if (isset($payload['meta']) && is_array($payload['meta'])) {
            foreach ($blocked_meta as $meta_key) {
                if (array_key_exists($meta_key, $payload['meta'])) {
                    $issues[] = self::build_issue('error', 'meta_key_blocked', sprintf(__('The meta key `%s` is blocked in AI submission packages.', 'dbvc'), $meta_key), (string) ($entity['path'] ?? '') . '#meta.' . $meta_key);
                }
            }
        }

        foreach ($blocked_top_level as $field_name) {
            if (array_key_exists($field_name, $payload)) {
                $issues[] = self::build_issue('error', 'field_blocked', sprintf(__('The top-level field `%s` is blocked in AI submission packages.', 'dbvc'), $field_name), (string) ($entity['path'] ?? '') . '#' . $field_name);
            }
        }

        foreach (array_keys($payload) as $field_name) {
            if (! in_array($field_name, $allowed, true)) {
                $issues[] = self::build_issue('warning', 'field_unexpected', sprintf(__('Unexpected top-level field `%s` was provided.', 'dbvc'), $field_name), (string) ($entity['path'] ?? '') . '#' . $field_name);
            }
        }

        return [
            'issues' => $issues,
            'intent' => self::infer_entity_intent($payload, 'term_id'),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param string              $id_field
     * @return string
     */
    private static function infer_entity_intent(array $payload, string $id_field): string
    {
        $has_uid = ! empty($payload['vf_object_uid']);
        $has_numeric_id = isset($payload[$id_field]) && is_numeric($payload[$id_field]) && (int) $payload[$id_field] > 0;

        return ($has_uid || $has_numeric_id) ? 'update' : 'create';
    }

    /**
     * @param array<int,array<string,mixed>> $issues
     * @return string
     */
    private static function resolve_status(array $issues): string
    {
        if (self::count_issues_by_severity($issues, 'error') > 0) {
            return 'blocked';
        }

        if (self::count_issues_by_severity($issues, 'warning') > 0) {
            return 'valid_with_warnings';
        }

        return 'valid';
    }

    /**
     * @param array<int,array<string,mixed>> $issues
     * @param string                         $severity
     * @return int
     */
    private static function count_issues_by_severity(array $issues, string $severity): int
    {
        $count = 0;
        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                continue;
            }

            if (($issue['severity'] ?? '') === $severity) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<int,array<string,mixed>> $entities
     * @param string                         $entity_kind
     * @return int
     */
    private static function count_entities_by_kind(array $entities, string $entity_kind): int
    {
        $count = 0;
        foreach ($entities as $entity) {
            if (! is_array($entity)) {
                continue;
            }

            if (($entity['entity_kind'] ?? '') === $entity_kind) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param string $severity
     * @param string $code
     * @param string $message
     * @param string $path
     * @return array<string,mixed>
     */
    private static function build_issue(string $severity, string $code, string $message, string $path = ''): array
    {
        return IssueService::build($severity, $code, $message, $path, [
            'stage' => 'validation',
        ]);
    }

    /**
     * @param string $path
     * @param string $contents
     * @return void
     */
    private static function write_text_file(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
    }

    /**
     * @return string
     */
    private static function build_intake_id(): string
    {
        return sanitize_key('dbvc-ai-intake-' . gmdate('Ymd-His') . '-' . strtolower(wp_generate_password(6, false, false)));
    }
}
