<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class SubmissionPackageImporter
{
    private const IMPORT_REPORT_PATH = 'reports/import-report.json';
    private const IMPORT_SUMMARY_PATH = 'reports/import-summary.md';

    /**
     * @param string              $intake_id
     * @param array<string,mixed> $report
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public static function import_intake(string $intake_id, array $report, array $args = [])
    {
        if (! current_user_can('manage_options')) {
            return new \WP_Error('dbvc_ai_import_forbidden', __('You do not have permission to import AI packages.', 'dbvc'));
        }

        $intake_id = sanitize_key($intake_id);
        if ($intake_id === '') {
            return new \WP_Error('dbvc_ai_import_missing_intake', __('Missing AI intake identifier.', 'dbvc'));
        }

        $status = isset($report['status']) ? (string) $report['status'] : 'blocked';
        if ($status === 'blocked') {
            return new \WP_Error('dbvc_ai_import_blocked', __('Blocked AI packages cannot be imported.', 'dbvc'));
        }

        $entities = isset($report['entities']) && is_array($report['entities']) ? $report['entities'] : [];
        if (empty($entities)) {
            return new \WP_Error('dbvc_ai_import_no_entities', __('No translated AI entities are available for import.', 'dbvc'));
        }

        $post_files = [];
        $term_files = [];

        foreach ($entities as $entity) {
            if (! is_array($entity)) {
                continue;
            }

            $translated_path = isset($entity['translated_path']) ? (string) $entity['translated_path'] : '';
            if ($translated_path === '' || ($entity['status'] ?? '') !== 'translated') {
                continue;
            }

            $absolute_path = Storage::resolve_intake_artifact_path($intake_id, $translated_path);
            if (\is_wp_error($absolute_path)) {
                continue;
            }

            if (($entity['entity_kind'] ?? '') === 'term') {
                $term_files[] = [
                    'path' => $absolute_path,
                    'taxonomy' => sanitize_key((string) ($entity['object_key'] ?? '')),
                ];
            } else {
                $post_files[] = $absolute_path;
            }
        }

        $result = [
            'imported_at' => current_time('mysql'),
            'imported_at_gmt' => current_time('c'),
            'status' => 'completed',
            'intake_id' => $intake_id,
            'posts' => [
                'requested' => count($post_files),
                'eligible' => 0,
                'processed' => 0,
                'applied' => 0,
                'skipped' => 0,
                'errors' => 0,
                'details' => [],
            ],
            'terms' => [
                'requested' => count($term_files),
                'processed' => 0,
                'applied' => 0,
                'skipped' => 0,
                'errors' => 0,
                'details' => [],
            ],
            'artifacts' => [
                'import_report' => self::IMPORT_REPORT_PATH,
                'import_summary' => self::IMPORT_SUMMARY_PATH,
            ],
            'artifact_errors' => [],
            'source_report' => [
                'validation_status' => $status,
                'package_type' => isset($report['package_type']) ? (string) $report['package_type'] : '',
                'package_schema_version' => isset($report['package_schema_version']) ? (int) $report['package_schema_version'] : 0,
                'intended_operation' => isset($report['intended_operation']) ? (string) $report['intended_operation'] : '',
                'counts' => isset($report['counts']) && is_array($report['counts']) ? $report['counts'] : [],
            ],
        ];

        if (! empty($post_files)) {
            if (! class_exists('\DBVC_Sync_Posts')) {
                return new \WP_Error('dbvc_ai_import_posts_unavailable', __('DBVC post importer is unavailable.', 'dbvc'));
            }

            $post_import_result = \DBVC_Sync_Posts::import_selected_post_files($post_files, false, [
                'source' => 'ai_translated_package',
                'allow_outside_sync' => true,
            ]);
            if (! is_array($post_import_result)) {
                return new \WP_Error('dbvc_ai_import_posts_failed', __('AI post import did not return a valid result.', 'dbvc'));
            }

            $result['posts'] = array_merge($result['posts'], $post_import_result);
        }

        if (! empty($term_files)) {
            if (! class_exists('\DBVC_Sync_Taxonomies')) {
                return new \WP_Error('dbvc_ai_import_terms_unavailable', __('DBVC taxonomy importer is unavailable.', 'dbvc'));
            }

            foreach ($term_files as $term_file) {
                $result['terms']['processed']++;
                $path = (string) ($term_file['path'] ?? '');
                $taxonomy = sanitize_key((string) ($term_file['taxonomy'] ?? ''));

                if ($path === '' || $taxonomy === '' || ! file_exists($path)) {
                    $result['terms']['errors']++;
                    $result['terms']['details'][] = [
                        'file' => basename($path),
                        'path' => $path,
                        'taxonomy' => $taxonomy,
                        'status' => 'error',
                        'message' => __('Translated term source missing.', 'dbvc'),
                    ];
                    continue;
                }

                $term_import = \DBVC_Sync_Taxonomies::import_term_json_file($path, $taxonomy);
                if (\is_wp_error($term_import)) {
                    $result['terms']['errors']++;
                    $result['terms']['details'][] = [
                        'file' => basename($path),
                        'path' => $path,
                        'taxonomy' => $taxonomy,
                        'status' => 'error',
                        'message' => $term_import->get_error_message(),
                    ];
                    continue;
                }

                $result['terms']['applied']++;
                $result['terms']['details'][] = [
                    'file' => basename($path),
                    'path' => $path,
                    'taxonomy' => $taxonomy,
                    'status' => 'applied',
                    'message' => __('Imported.', 'dbvc'),
                    'term_id' => isset($term_import['term_id']) ? (int) $term_import['term_id'] : 0,
                ];
            }
        }

        if (($result['posts']['errors'] ?? 0) > 0 || ($result['terms']['errors'] ?? 0) > 0) {
            $result['status'] = 'completed_with_errors';
        }

        if (class_exists('\Dbvc\AiPackage\SubmissionPackagePostImportResolver')) {
            $relationship_result = SubmissionPackagePostImportResolver::resolve($intake_id, $report);
            if (\is_wp_error($relationship_result)) {
                $result['relationship_resolution'] = [
                    'status' => 'error',
                    'message' => $relationship_result->get_error_message(),
                ];
                $result['status'] = 'completed_with_errors';
            } else {
                $result['relationship_resolution'] = array_merge(
                    [
                        'status' => 'completed',
                    ],
                    $relationship_result
                );

                if (($relationship_result['errors'] ?? 0) > 0) {
                    $result['status'] = 'completed_with_errors';
                } elseif (($relationship_result['warnings'] ?? 0) > 0 && $result['status'] === 'completed') {
                    $result['status'] = 'completed_with_warnings';
                }
            }
        }

        self::persist_import_report_artifacts($intake_id, $result);

        return $result;
    }

    /**
     * @param string              $intake_id
     * @param array<string,mixed> $result
     * @return void
     */
    private static function persist_import_report_artifacts(string $intake_id, array &$result): void
    {
        $json_path = Storage::resolve_intake_artifact_path($intake_id, self::IMPORT_REPORT_PATH);
        $summary_path = Storage::resolve_intake_artifact_path($intake_id, self::IMPORT_SUMMARY_PATH);

        if (\is_wp_error($json_path)) {
            $result['artifact_errors'][] = $json_path->get_error_message();
        }

        if (\is_wp_error($summary_path)) {
            $result['artifact_errors'][] = $summary_path->get_error_message();
        }

        $summary = ImportReportFormatter::build_markdown_summary($result);
        if (! \is_wp_error($summary_path) && file_put_contents($summary_path, $summary . "\n") === false) {
            $result['artifact_errors'][] = __('Unable to write the retained AI import summary artifact.', 'dbvc');
        }

        $encoded = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded) && ! \is_wp_error($json_path) && file_put_contents($json_path, $encoded . "\n") === false) {
            $result['artifact_errors'][] = __('Unable to write the retained AI import report JSON artifact.', 'dbvc');
        }

        if (! empty($result['artifact_errors'])) {
            $result['status'] = $result['status'] === 'completed' ? 'completed_with_warnings' : $result['status'];
        }
    }
}
