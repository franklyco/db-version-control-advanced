<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Package_Artifact_Service
{
    /**
     * @var DBVC_CC_V2_Package_Artifact_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Package_Artifact_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param array<string, mixed>             $run_context
     * @param string                           $package_id
     * @param int                              $build_seq
     * @param array<int, array<string, mixed>> $included_reports
     * @param array<int, array<string, mixed>> $records
     * @param array<int, array<string, mixed>> $media_items
     * @param array<string, mixed>             $readiness
     * @return array<string, mixed>
     */
    public function build_manifest(array $run_context, $package_id, $build_seq, array $included_reports, array $records, array $media_items, array $readiness)
    {
        $included_object_types = [];
        foreach ($included_reports as $report) {
            if (! is_array($report)) {
                continue;
            }

            $target_key = isset($report['packagePreview']['target_entity_key']) ? (string) $report['packagePreview']['target_entity_key'] : '';
            if ($target_key !== '') {
                $included_object_types[$target_key] = $target_key;
            }
        }

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'package-manifest.v1',
            'package_id' => $package_id,
            'journey_id' => $run_context['runId'],
            'domain' => $run_context['domain'],
            'generated_at' => current_time('c'),
            'target_schema_fingerprint' => isset($readiness['schemaFingerprint']) ? (string) $readiness['schemaFingerprint'] : '',
            'included_pages' => array_values(
                array_map(
                    static function ($report) {
                        return isset($report['pageId']) ? (string) $report['pageId'] : '';
                    },
                    $included_reports
                )
            ),
            'included_object_types' => array_values($included_object_types),
            'stats' => [
                'build_seq' => $build_seq,
                'eligible_page_count' => isset($readiness['summary']['eligiblePages']) ? (int) $readiness['summary']['eligiblePages'] : 0,
                'included_page_count' => count($included_reports),
                'record_count' => count($records),
                'media_item_count' => count($media_items),
                'readiness_status' => isset($readiness['readinessStatus']) ? (string) $readiness['readinessStatus'] : '',
            ],
        ];
    }

    /**
     * @param array<string, mixed>             $run_context
     * @param string                           $package_id
     * @param array<string, mixed>             $readiness
     * @param array<int, array<string, mixed>> $included_reports
     * @return array<string, mixed>
     */
    public function build_package_qa_report(array $run_context, $package_id, array $readiness, array $included_reports)
    {
        $page_reports = isset($readiness['pageReports']) && is_array($readiness['pageReports']) ? $readiness['pageReports'] : [];
        $quality_scores = array_values(
            array_filter(
                array_map(
                    static function ($report) {
                        return is_array($report) ? (int) ($report['qualityScore'] ?? 0) : null;
                    },
                    $page_reports
                ),
                'is_int'
            )
        );

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'package-qa-report.v1',
            'package_id' => $package_id,
            'journey_id' => $run_context['runId'],
            'domain' => $run_context['domain'],
            'generated_at' => current_time('c'),
            'readiness_status' => isset($readiness['readinessStatus']) ? (string) $readiness['readinessStatus'] : DBVC_CC_V2_Contracts::READINESS_STATUS_NEEDS_REVIEW,
            'blocking_issues' => isset($readiness['blockingIssues']) && is_array($readiness['blockingIssues']) ? array_values($readiness['blockingIssues']) : [],
            'warnings' => isset($readiness['warnings']) && is_array($readiness['warnings']) ? array_values($readiness['warnings']) : [],
            'quality_score' => ! empty($quality_scores) ? (int) round(array_sum($quality_scores) / count($quality_scores)) : 0,
            'included_pages' => array_values(
                array_map(
                    static function ($report) {
                        return isset($report['pageId']) ? (string) $report['pageId'] : '';
                    },
                    $included_reports
                )
            ),
        ];
    }

    /**
     * @param string                           $package_id
     * @param int                              $build_seq
     * @param array<int, array<string, mixed>> $included_reports
     * @param array<int, array<string, mixed>> $records
     * @param array<int, array<string, mixed>> $media_items
     * @param array<string, mixed>             $readiness
     * @param array<string, mixed>             $package_qa
     * @return array<string, mixed>
     */
    public function build_package_summary($package_id, $build_seq, array $included_reports, array $records, array $media_items, array $readiness, array $package_qa)
    {
        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'package-summary.v1',
            'package_id' => $package_id,
            'generated_at' => current_time('c'),
            'build_seq' => $build_seq,
            'readiness_status' => isset($package_qa['readiness_status']) ? (string) $package_qa['readiness_status'] : '',
            'record_count' => count($records),
            'exception_count' => isset($readiness['summary']['needsReviewPages']) ? (int) $readiness['summary']['needsReviewPages'] : 0,
            'auto_accepted_count' => isset($readiness['summary']['autoAcceptedPages']) ? (int) $readiness['summary']['autoAcceptedPages'] : 0,
            'manual_override_count' => isset($readiness['summary']['manualOverridePages']) ? (int) $readiness['summary']['manualOverridePages'] : 0,
            'included_page_count' => count($included_reports),
            'media_item_count' => count($media_items),
            'blocking_issue_count' => isset($package_qa['blocking_issues']) && is_array($package_qa['blocking_issues']) ? count($package_qa['blocking_issues']) : 0,
            'warning_count' => isset($package_qa['warnings']) && is_array($package_qa['warnings']) ? count($package_qa['warnings']) : 0,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $included_reports
     * @return array<int, array<string, mixed>>
     */
    public function build_records(array $included_reports)
    {
        $records = [];
        foreach ($included_reports as $report) {
            if (! is_array($report) || empty($report['packagePreview']) || ! is_array($report['packagePreview'])) {
                continue;
            }

            $preview = $report['packagePreview'];
            $records[] = [
                'page_id' => isset($preview['page_id']) ? (string) $preview['page_id'] : '',
                'path' => isset($preview['path']) ? (string) $preview['path'] : '',
                'source_url' => isset($preview['source_url']) ? (string) $preview['source_url'] : '',
                'target_entity_key' => isset($preview['target_entity_key']) ? (string) $preview['target_entity_key'] : '',
                'target_action' => isset($preview['target_action']) ? (string) $preview['target_action'] : '',
                'field_values' => isset($preview['field_values']) && is_array($preview['field_values']) ? array_values($preview['field_values']) : [],
                'media_refs' => isset($preview['media_refs']) && is_array($preview['media_refs']) ? array_values($preview['media_refs']) : [],
                'trace' => isset($preview['trace']) && is_array($preview['trace']) ? $preview['trace'] : [],
            ];
        }

        return $records;
    }

    /**
     * @param array<int, array<string, mixed>> $included_reports
     * @return array<int, array<string, mixed>>
     */
    public function build_media_items(array $included_reports)
    {
        $items = [];
        foreach ($included_reports as $report) {
            if (! is_array($report) || empty($report['packagePreview']['media_refs']) || ! is_array($report['packagePreview']['media_refs'])) {
                continue;
            }

            foreach ($report['packagePreview']['media_refs'] as $media_ref) {
                if (! is_array($media_ref)) {
                    continue;
                }

                $dedupe_key = sprintf(
                    '%s|%s|%s',
                    isset($report['pageId']) ? (string) $report['pageId'] : '',
                    isset($media_ref['target_ref']) ? (string) $media_ref['target_ref'] : '',
                    isset($media_ref['source_url']) ? (string) $media_ref['source_url'] : ''
                );

                $items[$dedupe_key] = [
                    'page_id' => isset($report['pageId']) ? (string) $report['pageId'] : '',
                    'path' => isset($report['path']) ? (string) $report['path'] : '',
                    'media_id' => isset($media_ref['media_id']) ? (string) $media_ref['media_id'] : '',
                    'source_url' => isset($media_ref['source_url']) ? (string) $media_ref['source_url'] : '',
                    'target_ref' => isset($media_ref['target_ref']) ? (string) $media_ref['target_ref'] : '',
                    'media_kind' => isset($media_ref['media_kind']) ? (string) $media_ref['media_kind'] : '',
                    'recommendation_id' => isset($media_ref['recommendation_id']) ? (string) $media_ref['recommendation_id'] : '',
                    'trace' => [
                        'decision_source' => isset($media_ref['decision_source']) ? (string) $media_ref['decision_source'] : '',
                        'source_refs' => isset($media_ref['source_refs']) && is_array($media_ref['source_refs']) ? array_values($media_ref['source_refs']) : [],
                    ],
                ];
            }
        }

        return array_values($items);
    }
}
