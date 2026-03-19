<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Import_Collection_Service
{
    /**
     * @var DBVC_CC_V2_Import_Collection_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Import_Collection_Service
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
    public function build_dry_run_surface($run_id, $package_id = '')
    {
        $collection = $this->build_collection_context($run_id, $package_id);
        if (is_wp_error($collection)) {
            return $collection;
        }

        $page_executions = array_map(
            static function ($item) {
                return isset($item['surface']) && is_array($item['surface']) ? $item['surface'] : [];
            },
            isset($collection['pageExecutionDetails']) && is_array($collection['pageExecutionDetails'])
                ? $collection['pageExecutionDetails']
                : []
        );

        return [
            'runId' => isset($collection['runId']) ? (string) $collection['runId'] : '',
            'packageId' => isset($collection['packageId']) ? (string) $collection['packageId'] : '',
            'domain' => isset($collection['domain']) ? (string) $collection['domain'] : '',
            'generatedAt' => isset($collection['generatedAt']) ? (string) $collection['generatedAt'] : current_time('c'),
            'status' => isset($collection['status']) ? (string) $collection['status'] : 'blocked',
            'readinessStatus' => isset($collection['readinessStatus']) ? (string) $collection['readinessStatus'] : '',
            'summary' => isset($collection['summary']) && is_array($collection['summary']) ? $collection['summary'] : [],
            'package' => isset($collection['package']) && is_array($collection['package']) ? $collection['package'] : [],
            'issues' => isset($collection['issues']) && is_array($collection['issues']) ? array_values($collection['issues']) : [],
            'warnings' => isset($collection['warnings']) && is_array($collection['warnings']) ? array_values($collection['warnings']) : [],
            'pageExecutions' => array_values($page_executions),
        ];
    }

    /**
     * @param string $run_id
     * @param string $package_id
     * @return array<string, mixed>|WP_Error
     */
    public function build_collection_context($run_id, $package_id = '')
    {
        $run_id = sanitize_text_field((string) $run_id);
        $package_id = sanitize_text_field((string) $package_id);

        $package_surface = DBVC_CC_V2_Package_Build_Service::get_instance()->get_package_surface($run_id, $package_id);
        if (is_wp_error($package_surface)) {
            return $package_surface;
        }

        $selected_package = isset($package_surface['selectedPackage']) && is_array($package_surface['selectedPackage'])
            ? $package_surface['selectedPackage']
            : [];
        if (empty($selected_package['packageId'])) {
            return new WP_Error(
                'dbvc_cc_v2_dry_run_package_missing',
                __('Build or select a V2 package before running the dry-run bridge.', 'dbvc'),
                ['status' => 409]
            );
        }

        $records = isset($selected_package['records']['records']) && is_array($selected_package['records']['records'])
            ? array_values($selected_package['records']['records'])
            : [];

        $page_reports = $this->index_page_reports(
            isset($package_surface['readiness']['pageReports']) && is_array($package_surface['readiness']['pageReports'])
                ? $package_surface['readiness']['pageReports']
                : []
        );
        $media_items_by_page = $this->index_media_items_by_page(
            isset($selected_package['mediaManifest']['media_items']) && is_array($selected_package['mediaManifest']['media_items'])
                ? $selected_package['mediaManifest']['media_items']
                : []
        );

        $page_execution_details = [];
        $bridge_issues = [];
        $summary = [
            'includedPages' => 0,
            'completedPages' => 0,
            'blockedPages' => 0,
            'simulatedOperations' => 0,
            'blockingIssues' => 0,
            'dependencyEdges' => 0,
            'writeBarriers' => 0,
        ];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            ++$summary['includedPages'];
            $page_execution = $this->build_page_execution(
                $run_id,
                $selected_package,
                $record,
                isset($page_reports[(string) ($record['page_id'] ?? '')]) ? $page_reports[(string) ($record['page_id'] ?? '')] : [],
                isset($media_items_by_page[(string) ($record['page_id'] ?? '')]) ? $media_items_by_page[(string) ($record['page_id'] ?? '')] : []
            );

            if (is_wp_error($page_execution)) {
                ++$summary['blockedPages'];
                ++$summary['blockingIssues'];
                $bridge_issues[] = $this->make_issue(
                    'page_dry_run_unavailable',
                    $page_execution->get_error_message(),
                    [
                        'pageId' => isset($record['page_id']) ? (string) $record['page_id'] : '',
                        'path' => isset($record['path']) ? (string) $record['path'] : '',
                    ]
                );
                continue;
            }

            $page_execution_details[] = $page_execution;
            $surface = isset($page_execution['surface']) && is_array($page_execution['surface']) ? $page_execution['surface'] : [];

            if (($surface['status'] ?? '') === 'completed') {
                ++$summary['completedPages'];
            } else {
                ++$summary['blockedPages'];
            }

            $summary['simulatedOperations'] += isset($surface['operationCounts']['totalSimulated'])
                ? absint($surface['operationCounts']['totalSimulated'])
                : 0;
            $summary['blockingIssues'] += isset($surface['blockingIssueCount'])
                ? absint($surface['blockingIssueCount'])
                : 0;
            $summary['dependencyEdges'] += isset($surface['operationCounts']['dependencyEdges'])
                ? absint($surface['operationCounts']['dependencyEdges'])
                : 0;
            $summary['writeBarriers'] += isset($surface['operationCounts']['writeBarrierCount'])
                ? absint($surface['operationCounts']['writeBarrierCount'])
                : 0;
        }

        $package_qa = isset($selected_package['qaReport']) && is_array($selected_package['qaReport'])
            ? $selected_package['qaReport']
            : [];
        $package_readiness_status = isset($package_qa['readiness_status']) && is_string($package_qa['readiness_status'])
            ? sanitize_key($package_qa['readiness_status'])
            : (isset($package_surface['readinessStatus']) ? sanitize_key((string) $package_surface['readinessStatus']) : '');
        $package_blockers = isset($package_qa['blocking_issues']) && is_array($package_qa['blocking_issues'])
            ? array_values($package_qa['blocking_issues'])
            : [];
        $package_warnings = isset($package_qa['warnings']) && is_array($package_qa['warnings'])
            ? array_values($package_qa['warnings'])
            : [];

        return [
            'runId' => $run_id,
            'packageId' => (string) $selected_package['packageId'],
            'domain' => isset($package_surface['domain']) ? (string) $package_surface['domain'] : '',
            'generatedAt' => current_time('c'),
            'status' => $this->resolve_collection_status($package_readiness_status, $summary),
            'readinessStatus' => $package_readiness_status,
            'summary' => $summary,
            'package' => [
                'summary' => isset($selected_package['summary']) && is_array($selected_package['summary']) ? $selected_package['summary'] : [],
                'qaReport' => $package_qa,
                'artifactRefs' => isset($selected_package['artifactRefs']) && is_array($selected_package['artifactRefs']) ? $selected_package['artifactRefs'] : [],
            ],
            'issues' => array_values(array_merge($bridge_issues, $package_blockers)),
            'warnings' => $package_warnings,
            'pageExecutionDetails' => $page_execution_details,
            'selectedPackage' => $selected_package,
            'packageSurface' => $package_surface,
        ];
    }

    /**
     * @param string                      $run_id
     * @param array<string, mixed>        $selected_package
     * @param array<string, mixed>        $record
     * @param array<string, mixed>        $page_report
     * @param array<int, array<string, mixed>> $media_items
     * @return array<string, mixed>|WP_Error
     */
    private function build_page_execution($run_id, array $selected_package, array $record, array $page_report, array $media_items)
    {
        $page_id = isset($record['page_id']) ? sanitize_text_field((string) $record['page_id']) : '';
        $path = isset($record['path']) ? sanitize_text_field((string) $record['path']) : '';
        if ($path === '' && ! empty($page_report['path'])) {
            $path = sanitize_text_field((string) $page_report['path']);
        }

        $source_url = isset($record['source_url']) ? esc_url_raw((string) $record['source_url']) : '';
        if ($source_url === '' && ! empty($page_report['source_url'])) {
            $source_url = esc_url_raw((string) $page_report['source_url']);
        }

        if ($page_id === '' || $path === '') {
            return new WP_Error(
                'dbvc_cc_v2_dry_run_page_context_missing',
                __('A packaged page is missing the path or page identifier required for dry-run planning.', 'dbvc'),
                ['status' => 500]
            );
        }

        $phase4_input = $this->build_phase4_input($record, $page_report, $media_items, $source_url, $path);
        $handoff = $this->build_handoff_context($selected_package, $page_report, $path, $source_url);
        $dry_run_plan = DBVC_CC_Import_Plan_Service::get_instance()->build_dry_run_plan(
            $phase4_input,
            ['handoff' => $handoff]
        );
        if (is_wp_error($dry_run_plan)) {
            return $dry_run_plan;
        }

        $dry_run_execution = DBVC_CC_Import_Executor_Service::get_instance()->execute_dry_run_plan($dry_run_plan);
        if (is_wp_error($dry_run_execution)) {
            return $dry_run_execution;
        }

        return [
            'pageId' => $page_id,
            'path' => $path,
            'sourceUrl' => $source_url,
            'pageReport' => $page_report,
            'record' => $record,
            'mediaItems' => $media_items,
            'dryRunPlan' => $dry_run_plan,
            'dryRunExecution' => $dry_run_execution,
            'surface' => [
                'pageId' => $page_id,
                'path' => $path,
                'sourceUrl' => $source_url,
                'status' => isset($dry_run_execution['status']) ? (string) $dry_run_execution['status'] : 'blocked',
                'planId' => isset($dry_run_execution['plan_id']) ? (string) $dry_run_execution['plan_id'] : '',
                'executionId' => isset($dry_run_execution['execution_id']) ? (string) $dry_run_execution['execution_id'] : '',
                'blockingIssueCount' => isset($dry_run_execution['blocking_issue_count']) ? absint($dry_run_execution['blocking_issue_count']) : 0,
                'issues' => isset($dry_run_execution['issues']) && is_array($dry_run_execution['issues']) ? array_values($dry_run_execution['issues']) : [],
                'writeBarriers' => isset($dry_run_execution['write_barriers']) && is_array($dry_run_execution['write_barriers'])
                    ? array_values($dry_run_execution['write_barriers'])
                    : [],
                'operationCounts' => [
                    'totalSimulated' => isset($dry_run_execution['operation_counts']['total_simulated'])
                        ? absint($dry_run_execution['operation_counts']['total_simulated'])
                        : 0,
                    'entityResolutions' => isset($dry_run_execution['operation_counts']['entity_resolutions'])
                        ? absint($dry_run_execution['operation_counts']['entity_resolutions'])
                        : 0,
                    'fieldMappings' => isset($dry_run_execution['operation_counts']['field_mappings'])
                        ? absint($dry_run_execution['operation_counts']['field_mappings'])
                        : 0,
                    'mediaMappings' => isset($dry_run_execution['operation_counts']['media_mappings'])
                        ? absint($dry_run_execution['operation_counts']['media_mappings'])
                        : 0,
                    'dependencyEdges' => isset($dry_run_execution['operation_counts']['dependency_edges'])
                        ? absint($dry_run_execution['operation_counts']['dependency_edges'])
                        : 0,
                    'writeBarrierCount' => isset($dry_run_execution['operation_counts']['write_barrier_count'])
                        ? absint($dry_run_execution['operation_counts']['write_barrier_count'])
                        : 0,
                ],
                'trace' => isset($dry_run_execution['trace']) && is_array($dry_run_execution['trace']) ? $dry_run_execution['trace'] : [],
            ],
        ];
    }

    /**
     * @param array<string, mixed>             $record
     * @param array<string, mixed>             $page_report
     * @param array<int, array<string, mixed>> $media_items
     * @param string                           $source_url
     * @param string                           $path
     * @return array<string, mixed>
     */
    private function build_phase4_input(array $record, array $page_report, array $media_items, $source_url, $path)
    {
        $default_entity_key = isset($record['target_entity_key']) ? sanitize_text_field((string) $record['target_entity_key']) : '';
        $default_entity_key = $this->normalize_entity_key($default_entity_key);
        if ($default_entity_key === '') {
            $selected_target = isset($page_report['selectedTargetObject']) && is_array($page_report['selectedTargetObject'])
                ? $page_report['selectedTargetObject']
                : [];
            if (! empty($selected_target['targetFamily']) && ! empty($selected_target['targetObjectKey'])) {
                $default_entity_key = $this->normalize_entity_key(
                    sanitize_key((string) $selected_target['targetFamily']) . ':' . sanitize_key((string) $selected_target['targetObjectKey'])
                );
            }
        }
        if ($default_entity_key === '') {
            $default_entity_key = 'post:page';
        }

        $entity_meta = $this->parse_entity_key($default_entity_key);

        return [
            'domain' => isset($page_report['domain']) ? sanitize_text_field((string) $page_report['domain']) : '',
            'path' => sanitize_text_field((string) $path),
            'source_url' => esc_url_raw((string) $source_url),
            'catalog_fingerprint' => isset($page_report['schemaFingerprint']) ? sanitize_text_field((string) $page_report['schemaFingerprint']) : '',
            'default_entity_key' => $default_entity_key,
            'object_hints' => [
                'default_entity_key' => $default_entity_key,
                'default_entity_reason' => 'v2_package_selection',
                'suggested_post_type' => isset($entity_meta['subtype']) ? (string) $entity_meta['subtype'] : 'page',
                'suggested_post_type_confidence' => 1.0,
            ],
            'approved_mappings' => $this->build_field_mappings(
                isset($record['field_values']) && is_array($record['field_values']) ? $record['field_values'] : []
            ),
            'approved_media_mappings' => $this->build_media_mappings($media_items),
            'mapping_overrides' => [],
            'mapping_rejections' => [],
            'unresolved_fields' => [],
            'unresolved_media' => [],
            'media_overrides' => [],
            'media_ignored' => [],
            'media_conflicts' => [],
            'dry_run_required' => get_option(DBVC_CC_Contracts::IMPORT_POLICY_DRY_RUN_REQUIRED, '1') === '1',
            'idempotent_upsert_required' => get_option(DBVC_CC_Contracts::IMPORT_POLICY_IDEMPOTENT_UPSERT, '1') === '1',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $field_values
     * @return array<int, array<string, mixed>>
     */
    private function build_field_mappings(array $field_values)
    {
        $mappings = [];
        foreach ($field_values as $index => $field_value) {
            if (! is_array($field_value)) {
                continue;
            }

            $target_ref = isset($field_value['target_ref']) ? sanitize_text_field((string) $field_value['target_ref']) : '';
            if ($target_ref === '') {
                continue;
            }

            $resolved_value = array_key_exists('value', $field_value) ? $this->normalize_value($field_value['value']) : '';
            $resolved_value_format = is_array($resolved_value) ? 'array' : 'string';

            $mappings[] = [
                'section_id' => $this->derive_source_token(
                    isset($field_value['source_refs']) && is_array($field_value['source_refs']) ? $field_value['source_refs'] : [],
                    'field_' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)
                ),
                'target_ref' => $target_ref,
                'confidence' => isset($field_value['confidence']) ? (float) $field_value['confidence'] : 1.0,
                'resolved_value_candidate' => $resolved_value,
                'resolved_value_format' => $resolved_value_format,
                'resolved_value_preview' => $this->build_value_preview($resolved_value),
                'resolved_value_origin' => 'package_record',
                'source_payload' => [
                    'source_refs' => isset($field_value['source_refs']) && is_array($field_value['source_refs']) ? array_values($field_value['source_refs']) : [],
                    'decision_source' => isset($field_value['decision_source']) ? sanitize_key((string) $field_value['decision_source']) : '',
                    'package_value' => $resolved_value,
                    'package_value_format' => $resolved_value_format,
                ],
            ];
        }

        return $mappings;
    }

    /**
     * @param array<int, array<string, mixed>> $media_items
     * @return array<int, array<string, mixed>>
     */
    private function build_media_mappings(array $media_items)
    {
        $mappings = [];
        foreach ($media_items as $index => $media_item) {
            if (! is_array($media_item)) {
                continue;
            }

            $target_ref = isset($media_item['target_ref']) ? sanitize_text_field((string) $media_item['target_ref']) : '';
            $source_url = isset($media_item['source_url']) ? esc_url_raw((string) $media_item['source_url']) : '';
            if ($target_ref === '' || $source_url === '') {
                continue;
            }

            $trace = isset($media_item['trace']) && is_array($media_item['trace']) ? $media_item['trace'] : [];
            $source_payload = [
                'source_url' => $source_url,
                'preview_ref' => $source_url,
                'media_kind' => isset($media_item['media_kind']) ? sanitize_key((string) $media_item['media_kind']) : 'image',
                'source_refs' => isset($trace['source_refs']) && is_array($trace['source_refs']) ? array_values($trace['source_refs']) : [],
                'decision_source' => isset($trace['decision_source']) ? sanitize_key((string) $trace['decision_source']) : '',
            ];

            $mappings[] = [
                'media_id' => ! empty($media_item['media_id'])
                    ? sanitize_text_field((string) $media_item['media_id'])
                    : $this->derive_source_token(
                        isset($trace['source_refs']) && is_array($trace['source_refs']) ? $trace['source_refs'] : [],
                        'media_' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)
                    ),
                'target_ref' => $target_ref,
                'source_url' => $source_url,
                'media_kind' => isset($media_item['media_kind']) ? sanitize_key((string) $media_item['media_kind']) : 'image',
                'source_payload' => $source_payload,
                'resolved_media_candidate' => $source_payload,
            ];
        }

        return $mappings;
    }

    /**
     * @param array<string, mixed> $selected_package
     * @param array<string, mixed> $page_report
     * @param string               $path
     * @param string               $source_url
     * @return array<string, mixed>
     */
    private function build_handoff_context(array $selected_package, array $page_report, $path, $source_url)
    {
        $package_qa = isset($selected_package['qaReport']) && is_array($selected_package['qaReport'])
            ? $selected_package['qaReport']
            : [];
        $is_ready_package = isset($package_qa['readiness_status'])
            && sanitize_key((string) $package_qa['readiness_status']) === DBVC_CC_V2_Contracts::READINESS_STATUS_READY;

        return [
            'status' => $is_ready_package ? 'ready' : 'needs_review',
            'review' => ['reasons' => []],
            'warnings' => [],
            'trace' => [
                'source_pipeline_id' => isset($selected_package['packageId']) ? (string) $selected_package['packageId'] : '',
                'artifact_refs' => isset($selected_package['artifactRefs']) && is_array($selected_package['artifactRefs']) ? $selected_package['artifactRefs'] : [],
                'source_url' => esc_url_raw((string) $source_url),
                'path' => sanitize_text_field((string) $path),
            ],
            'handoff_schema_version' => '2.0.0',
            'handoff_generated_at' => isset($selected_package['manifest']['generated_at'])
                ? sanitize_text_field((string) $selected_package['manifest']['generated_at'])
                : current_time('c'),
            'blocking_warning_count' => 0,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $page_reports
     * @return array<string, array<string, mixed>>
     */
    private function index_page_reports(array $page_reports)
    {
        $indexed = [];
        foreach ($page_reports as $page_report) {
            if (! is_array($page_report) || empty($page_report['pageId'])) {
                continue;
            }

            $indexed[(string) $page_report['pageId']] = $page_report;
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, mixed>> $media_items
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function index_media_items_by_page(array $media_items)
    {
        $indexed = [];
        foreach ($media_items as $media_item) {
            if (! is_array($media_item) || empty($media_item['page_id'])) {
                continue;
            }

            $page_id = (string) $media_item['page_id'];
            if (! isset($indexed[$page_id])) {
                $indexed[$page_id] = [];
            }

            $indexed[$page_id][] = $media_item;
        }

        return $indexed;
    }

    /**
     * @param array<int, mixed> $source_refs
     * @param string            $fallback
     * @return string
     */
    private function derive_source_token(array $source_refs, $fallback)
    {
        foreach ($source_refs as $source_ref) {
            if (! is_string($source_ref) || strpos($source_ref, 'sections.v2#') !== 0) {
                continue;
            }

            $section_id = substr($source_ref, strlen('sections.v2#'));
            $token = sanitize_key($section_id);
            if ($token !== '') {
                return $token;
            }
        }

        foreach ($source_refs as $source_ref) {
            if (! is_string($source_ref) || $source_ref === '') {
                continue;
            }

            $parts = explode('#', $source_ref, 2);
            if (! empty($parts[1])) {
                $token = sanitize_key(str_replace(['[', ']', '.', ':'], '_', (string) $parts[1]));
                if ($token !== '') {
                    return $token;
                }
            }
        }

        return sanitize_key((string) $fallback);
    }

    /**
     * @param string $entity_key
     * @return array<string, string>
     */
    private function parse_entity_key($entity_key)
    {
        $entity_key = $this->normalize_entity_key($entity_key);
        $parts = explode(':', $entity_key, 2);

        return [
            'object_type' => isset($parts[0]) ? sanitize_key((string) $parts[0]) : 'post',
            'subtype' => isset($parts[1]) ? sanitize_key((string) $parts[1]) : 'page',
        ];
    }

    /**
     * @param string $entity_key
     * @return string
     */
    private function normalize_entity_key($entity_key)
    {
        $entity_key = sanitize_text_field((string) $entity_key);
        if (strpos($entity_key, 'post_type:') === 0) {
            return 'post:' . substr($entity_key, strlen('post_type:'));
        }

        return $entity_key;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function normalize_value($value)
    {
        if (is_array($value)) {
            return array_values(array_map([$this, 'normalize_value'], $value));
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function build_value_preview($value)
    {
        if (is_array($value)) {
            $preview = wp_json_encode($value);
        } elseif (is_bool($value)) {
            $preview = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $preview = 'null';
        } else {
            $preview = (string) $value;
        }

        return $this->truncate_preview($preview, 180);
    }

    /**
     * @param string $value
     * @param int    $max_length
     * @return string
     */
    private function truncate_preview($value, $max_length)
    {
        $value = sanitize_text_field((string) $value);
        $max_length = max(1, absint($max_length));
        if (strlen($value) <= $max_length) {
            return $value;
        }

        return substr($value, 0, max(0, $max_length - 1)) . '…';
    }

    /**
     * @param string             $readiness_status
     * @param array<string, int> $summary
     * @return string
     */
    private function resolve_collection_status($readiness_status, array $summary)
    {
        $readiness_status = sanitize_key((string) $readiness_status);
        if ($readiness_status !== DBVC_CC_V2_Contracts::READINESS_STATUS_READY) {
            return 'blocked';
        }

        return ! empty($summary['blockedPages']) ? 'blocked' : 'completed';
    }

    /**
     * @param string               $code
     * @param string               $message
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function make_issue($code, $message, array $context = [])
    {
        return array_merge(
            [
                'code' => sanitize_key((string) $code),
                'message' => sanitize_text_field((string) $message),
                'severity' => 'blocking',
            ],
            $context
        );
    }
}
