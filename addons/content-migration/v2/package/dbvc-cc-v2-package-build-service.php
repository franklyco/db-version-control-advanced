<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Package_Build_Service
{
    /**
     * @var DBVC_CC_V2_Package_Build_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Package_Build_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param string $run_id
     * @param string $package_id
     * @return array<string, mixed>|WP_Error
     */
    public function get_package_surface($run_id, $package_id = '')
    {
        $run_context = $this->resolve_run_context($run_id);
        if (is_wp_error($run_context)) {
            return $run_context;
        }

        $readiness = DBVC_CC_V2_Package_QA_Service::get_instance()->get_run_readiness($run_id);
        if (is_wp_error($readiness)) {
            return $readiness;
        }

        $storage_service = DBVC_CC_V2_Package_Storage_Service::get_instance();
        $history = $this->filter_history_for_run(
            $storage_service->list_builds_by_domain($run_context['domain']),
            $run_context['runId']
        );
        $selected_package_id = sanitize_text_field((string) $package_id);
        if ($selected_package_id === '' && ! empty($history[0]['package_id'])) {
            $selected_package_id = (string) $history[0]['package_id'];
        }

        $selected_history_item = $this->find_history_item($history, $selected_package_id);
        if (! is_array($selected_history_item) && ! empty($history[0]['package_id'])) {
            $selected_package_id = (string) $history[0]['package_id'];
            $selected_history_item = $this->find_history_item($history, $selected_package_id);
        }

        if (! is_array($selected_history_item)) {
            $selected_package_id = '';
        }

        $history = DBVC_CC_V2_Package_Observability_Service::get_instance()->augment_history($history);
        $selected_package = $selected_package_id !== '' && is_array($selected_history_item)
            ? $storage_service->read_package_detail($run_context['context'], $selected_package_id)
            : null;
        if (is_array($selected_package) && is_array($selected_history_item)) {
            $selected_package = DBVC_CC_V2_Package_Observability_Service::get_instance()->augment_package_detail(
                $selected_package,
                $selected_history_item
            );
        }
        if (is_array($selected_package)) {
            $selected_package['artifactActions'] = DBVC_CC_V2_Package_Artifact_Service::get_instance()->build_operator_actions(
                $run_context,
                $selected_package_id
            );
        }

        return [
            'runId' => $run_context['runId'],
            'domain' => $run_context['domain'],
            'generatedAt' => current_time('c'),
            'readinessStatus' => isset($readiness['readinessStatus']) ? (string) $readiness['readinessStatus'] : DBVC_CC_V2_Contracts::READINESS_STATUS_NEEDS_REVIEW,
            'readiness' => $readiness,
            'history' => array_values($history),
            'selectedPackageId' => $selected_package_id,
            'selectedPackage' => $selected_package,
        ];
    }

    /**
     * @param string               $run_id
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function build_package($run_id, array $args = [])
    {
        $run_context = $this->resolve_run_context($run_id);
        if (is_wp_error($run_context)) {
            return $run_context;
        }

        $readiness = DBVC_CC_V2_Package_QA_Service::get_instance()->get_run_readiness(
            $run_id,
            [
                'write_reports' => true,
            ]
        );
        if (is_wp_error($readiness)) {
            return $readiness;
        }

        $artifact_service = DBVC_CC_V2_Package_Artifact_Service::get_instance();
        $storage_service = DBVC_CC_V2_Package_Storage_Service::get_instance();
        $history = $storage_service->list_builds_by_domain($run_context['domain']);
        $build_seq = $storage_service->get_next_build_sequence($history);
        $package_id = DBVC_CC_V2_Contracts::generate_package_id($run_context['runId'], $build_seq);
        $package_dir = trailingslashit($run_context['context']['packages_dir']) . $package_id;
        if (! dbvc_cc_create_security_files($package_dir)) {
            return new WP_Error(
                'dbvc_cc_v2_package_dir_create_failed',
                __('Could not create the V2 package directory.', 'dbvc'),
                ['status' => 500]
            );
        }

        $page_reports = isset($readiness['pageReports']) && is_array($readiness['pageReports']) ? $readiness['pageReports'] : [];
        $included_reports = array_values(
            array_filter(
                $page_reports,
                static function ($report) {
                    return is_array($report) && ! empty($report['packageIncluded']);
                }
            )
        );

        $records = $artifact_service->build_records($included_reports);
        $media_items = $artifact_service->build_media_items($included_reports);
        $manifest = $artifact_service->build_manifest($run_context, $package_id, $build_seq, $included_reports, $records, $media_items, $readiness);
        $package_qa = $artifact_service->build_package_qa_report($run_context, $package_id, $readiness, $included_reports);
        $package_summary = $artifact_service->build_package_summary($package_id, $build_seq, $included_reports, $records, $media_items, $readiness, $package_qa);

        $artifact_bundle = [
            DBVC_CC_V2_Contracts::STORAGE_PACKAGE_MANIFEST_FILE => $manifest,
            DBVC_CC_V2_Contracts::STORAGE_PACKAGE_RECORDS_FILE => [
                'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
                'artifact_type' => 'package-records.v1',
                'package_id' => $package_id,
                'records' => $records,
            ],
            DBVC_CC_V2_Contracts::STORAGE_PACKAGE_MEDIA_MANIFEST_FILE => [
                'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
                'artifact_type' => 'package-media-manifest.v1',
                'package_id' => $package_id,
                'media_items' => $media_items,
            ],
            DBVC_CC_V2_Contracts::STORAGE_PACKAGE_QA_REPORT_FILE => $package_qa,
            DBVC_CC_V2_Contracts::STORAGE_PACKAGE_SUMMARY_FILE => $package_summary,
        ];

        if (! $storage_service->write_artifact_bundle($package_dir, $artifact_bundle)) {
            return new WP_Error(
                'dbvc_cc_v2_package_write_failed',
                __('Could not write a V2 package artifact.', 'dbvc'),
                ['status' => 500]
            );
        }

        $zip_path = trailingslashit($package_dir) . DBVC_CC_V2_Contracts::STORAGE_PACKAGE_ZIP_FILE;
        $zip_result = $storage_service->write_zip_archive($zip_path, $artifact_bundle);
        if (is_wp_error($zip_result)) {
            return $zip_result;
        }

        $history_item = [
            'package_id' => $package_id,
            'journey_id' => $run_context['runId'],
            'build_seq' => $build_seq,
            'built_at' => current_time('c'),
            'readiness_status' => isset($package_qa['readiness_status']) ? (string) $package_qa['readiness_status'] : DBVC_CC_V2_Contracts::READINESS_STATUS_NEEDS_REVIEW,
            'record_count' => count($records),
            'media_item_count' => count($media_items),
            'included_page_count' => count($included_reports),
            'blocking_issue_count' => isset($package_summary['blocking_issue_count']) ? (int) $package_summary['blocking_issue_count'] : 0,
            'warning_count' => isset($package_summary['warning_count']) ? (int) $package_summary['warning_count'] : 0,
            'quality_score' => isset($package_qa['quality_score']) ? (int) $package_qa['quality_score'] : 0,
            'artifact_refs' => $storage_service->build_package_artifact_refs($run_context['context'], $package_id),
            'workflow_state' => [
                'build' => [
                    'generated_at' => current_time('c'),
                    'status' => 'completed',
                    'readiness_status' => isset($package_qa['readiness_status']) ? (string) $package_qa['readiness_status'] : DBVC_CC_V2_Contracts::READINESS_STATUS_NEEDS_REVIEW,
                    'summary' => [
                        'record_count' => count($records),
                        'included_page_count' => count($included_reports),
                        'warning_count' => isset($package_summary['warning_count']) ? (int) $package_summary['warning_count'] : 0,
                    ],
                    'issue_count' => isset($package_summary['blocking_issue_count']) ? (int) $package_summary['blocking_issue_count'] : 0,
                    'warning_count' => isset($package_summary['warning_count']) ? (int) $package_summary['warning_count'] : 0,
                ],
            ],
            'import_history' => [],
        ];

        if (! $storage_service->write_history($run_context['context'], $history, $history_item)) {
            return new WP_Error(
                'dbvc_cc_v2_package_history_write_failed',
                __('Could not update the V2 package build history.', 'dbvc'),
                ['status' => 500]
            );
        }

        $this->record_package_events($run_context, $package_id, $page_reports, $history_item, $args);

        return $this->get_package_surface($run_id, $package_id);
    }

    /**
     * @param array<string, mixed>             $run_context
     * @param string                           $package_id
     * @param array<int, array<string, mixed>> $page_reports
     * @param array<string, mixed>             $history_item
     * @param array<string, mixed>             $args
     * @return void
     */
    private function record_package_events(array $run_context, $package_id, array $page_reports, array $history_item, array $args)
    {
        $journey = DBVC_CC_V2_Domain_Journey_Service::get_instance();
        $artifact_refs = isset($history_item['artifact_refs']) && is_array($history_item['artifact_refs']) ? $history_item['artifact_refs'] : [];
        $actor = isset($args['actor']) ? sanitize_key((string) $args['actor']) : 'admin';
        $trigger = isset($args['trigger']) ? sanitize_key((string) $args['trigger']) : 'rest';

        $journey->append_event(
            $run_context['domain'],
            [
                'journey_id' => $run_context['runId'],
                'step_key' => DBVC_CC_V2_Contracts::STEP_PACKAGE_VALIDATION_COMPLETED,
                'step_name' => 'Package validation completed',
                'status' => 'completed',
                'actor' => $actor,
                'trigger' => $trigger,
                'package_id' => $package_id,
                'output_artifacts' => [
                    isset($artifact_refs['qa']) ? (string) $artifact_refs['qa'] : '',
                ],
                'metadata' => [
                    'readiness_status' => isset($history_item['readiness_status']) ? (string) $history_item['readiness_status'] : '',
                    'blocking_issue_count' => isset($history_item['blocking_issue_count']) ? (int) $history_item['blocking_issue_count'] : 0,
                    'warning_count' => isset($history_item['warning_count']) ? (int) $history_item['warning_count'] : 0,
                ],
                'message' => 'Package validation summary was generated.',
            ]
        );

        foreach ($page_reports as $report) {
            if (! is_array($report) || empty($report['packageIncluded'])) {
                continue;
            }

            $journey->append_event(
                $run_context['domain'],
                [
                    'journey_id' => $run_context['runId'],
                    'step_key' => DBVC_CC_V2_Contracts::STEP_PACKAGE_READY,
                    'step_name' => 'Package ready',
                    'status' => 'completed',
                    'page_id' => isset($report['pageId']) ? (string) $report['pageId'] : '',
                    'path' => isset($report['path']) ? (string) $report['path'] : '',
                    'source_url' => isset($report['source_url']) ? (string) $report['source_url'] : (isset($report['sourceUrl']) ? (string) $report['sourceUrl'] : ''),
                    'actor' => $actor,
                    'trigger' => $trigger,
                    'package_id' => $package_id,
                    'message' => 'URL included in package build.',
                ]
            );
        }

        $journey->append_event(
            $run_context['domain'],
            [
                'journey_id' => $run_context['runId'],
                'step_key' => DBVC_CC_V2_Contracts::STEP_PACKAGE_BUILT,
                'step_name' => 'Package built',
                'status' => 'completed',
                'actor' => $actor,
                'trigger' => $trigger,
                'package_id' => $package_id,
                'output_artifacts' => array_values(array_filter($artifact_refs)),
                'metadata' => [
                    'record_count' => isset($history_item['record_count']) ? (int) $history_item['record_count'] : 0,
                    'included_page_count' => isset($history_item['included_page_count']) ? (int) $history_item['included_page_count'] : 0,
                    'readiness_status' => isset($history_item['readiness_status']) ? (string) $history_item['readiness_status'] : '',
                ],
                'message' => 'Package artifacts and zip were built.',
            ]
        );
    }

    /**
     * @param string $run_id
     * @return array<string, mixed>|WP_Error
     */
    private function resolve_run_context($run_id)
    {
        $run_id = sanitize_text_field((string) $run_id);
        $domain = DBVC_CC_V2_Domain_Journey_Service::get_instance()->find_domain_by_journey_id($run_id);
        if ($domain === '') {
            return new WP_Error(
                'dbvc_cc_v2_run_missing',
                __('The requested V2 run could not be found.', 'dbvc'),
                ['status' => 404]
            );
        }

        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        return [
            'runId' => $run_id,
            'domain' => $domain,
            'context' => $context,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @param string                           $package_id
     * @return array<string, mixed>|null
     */
    private function find_history_item(array $history, $package_id)
    {
        $package_id = sanitize_text_field((string) $package_id);
        if ($package_id === '') {
            return null;
        }

        foreach ($history as $item) {
            if (! is_array($item) || (($item['package_id'] ?? '') !== $package_id)) {
                continue;
            }

            return $item;
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @param string                           $run_id
     * @return array<int, array<string, mixed>>
     */
    private function filter_history_for_run(array $history, $run_id)
    {
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') {
            return [];
        }

        return array_values(
            array_filter(
                $history,
                static function ($item) use ($run_id) {
                    return is_array($item) && (string) ($item['journey_id'] ?? '') === $run_id;
                }
            )
        );
    }
}
