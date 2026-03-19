<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Package_Observability_Service
{
    /**
     * @var DBVC_CC_V2_Package_Observability_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Package_Observability_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param string               $run_id
     * @param string               $package_id
     * @param array<string, mixed> $payload
     * @return true|WP_Error
     */
    public function record_dry_run_snapshot($run_id, $package_id, array $payload)
    {
        return $this->update_history_entry(
            $run_id,
            $package_id,
            function (array $history_item) use ($payload) {
                $workflow = isset($history_item['workflow_state']) && is_array($history_item['workflow_state'])
                    ? $history_item['workflow_state']
                    : [];
                $workflow['latest_dry_run'] = $this->build_dry_run_snapshot($payload);
                $history_item['workflow_state'] = $workflow;

                return $history_item;
            }
        );
    }

    /**
     * @param string               $run_id
     * @param string               $package_id
     * @param array<string, mixed> $payload
     * @return true|WP_Error
     */
    public function record_preflight_snapshot($run_id, $package_id, array $payload)
    {
        return $this->update_history_entry(
            $run_id,
            $package_id,
            function (array $history_item) use ($payload) {
                $workflow = isset($history_item['workflow_state']) && is_array($history_item['workflow_state'])
                    ? $history_item['workflow_state']
                    : [];
                $workflow['latest_preflight'] = $this->build_preflight_snapshot($payload);
                $history_item['workflow_state'] = $workflow;

                return $history_item;
            }
        );
    }

    /**
     * @param string               $run_id
     * @param string               $package_id
     * @param array<string, mixed> $payload
     * @return true|WP_Error
     */
    public function record_execute_snapshot($run_id, $package_id, array $payload)
    {
        return $this->update_history_entry(
            $run_id,
            $package_id,
            function (array $history_item) use ($payload) {
                $workflow = isset($history_item['workflow_state']) && is_array($history_item['workflow_state'])
                    ? $history_item['workflow_state']
                    : [];
                $execute_snapshot = $this->build_execute_snapshot($payload);
                $workflow['latest_execute'] = $execute_snapshot;
                $history_item['workflow_state'] = $workflow;

                $import_history = isset($history_item['import_history']) && is_array($history_item['import_history'])
                    ? array_values(array_filter($history_item['import_history'], 'is_array'))
                    : [];
                array_unshift($import_history, $execute_snapshot);
                $history_item['import_history'] = array_slice($import_history, 0, 10);

                return $history_item;
            }
        );
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @return array<int, array<string, mixed>>
     */
    public function augment_history(array $history)
    {
        return array_values(
            array_map(
                function ($item) {
                    return is_array($item) ? $this->augment_history_item($item) : [];
                },
                $history
            )
        );
    }

    /**
     * @param array<string, mixed> $package_detail
     * @param array<string, mixed> $history_item
     * @return array<string, mixed>
     */
    public function augment_package_detail(array $package_detail, array $history_item)
    {
        $package_detail['workflowState'] = $this->build_workflow_state($history_item);
        $package_detail['importHistory'] = $this->normalize_import_history(
            isset($history_item['import_history']) && is_array($history_item['import_history'])
                ? $history_item['import_history']
                : []
        );

        return $package_detail;
    }

    /**
     * @param array<string, mixed> $history_item
     * @return array<string, mixed>
     */
    private function augment_history_item(array $history_item)
    {
        $workflow = isset($history_item['workflow_state']) && is_array($history_item['workflow_state'])
            ? $history_item['workflow_state']
            : [];
        $latest_execute = isset($workflow['latest_execute']) && is_array($workflow['latest_execute'])
            ? $workflow['latest_execute']
            : [];

        $history_item['workflowSummary'] = [
            'buildStatus' => 'completed',
            'dryRunStatus' => isset($workflow['latest_dry_run']['status']) ? sanitize_key((string) $workflow['latest_dry_run']['status']) : '',
            'preflightStatus' => isset($workflow['latest_preflight']['status']) ? sanitize_key((string) $workflow['latest_preflight']['status']) : '',
            'executeStatus' => isset($latest_execute['status']) ? sanitize_key((string) $latest_execute['status']) : '',
            'importRunCount' => isset($latest_execute['summary']['import_runs']) ? absint($latest_execute['summary']['import_runs']) : 0,
        ];

        return $history_item;
    }

    /**
     * @param array<string, mixed> $history_item
     * @return array<string, mixed>
     */
    private function build_workflow_state(array $history_item)
    {
        $workflow = isset($history_item['workflow_state']) && is_array($history_item['workflow_state'])
            ? $history_item['workflow_state']
            : [];

        return [
            'build' => $this->normalize_stage_snapshot(
                [
                    'generated_at' => isset($history_item['built_at']) ? (string) $history_item['built_at'] : '',
                    'status' => 'completed',
                    'readiness_status' => isset($history_item['readiness_status']) ? (string) $history_item['readiness_status'] : '',
                    'summary' => [
                        'record_count' => isset($history_item['record_count']) ? absint($history_item['record_count']) : 0,
                        'included_page_count' => isset($history_item['included_page_count']) ? absint($history_item['included_page_count']) : 0,
                        'warning_count' => isset($history_item['warning_count']) ? absint($history_item['warning_count']) : 0,
                    ],
                    'issue_count' => isset($history_item['blocking_issue_count']) ? absint($history_item['blocking_issue_count']) : 0,
                    'warning_count' => isset($history_item['warning_count']) ? absint($history_item['warning_count']) : 0,
                ],
                'build'
            ),
            'latestDryRun' => $this->normalize_stage_snapshot(
                isset($workflow['latest_dry_run']) && is_array($workflow['latest_dry_run']) ? $workflow['latest_dry_run'] : [],
                'dry_run'
            ),
            'latestPreflight' => $this->normalize_stage_snapshot(
                isset($workflow['latest_preflight']) && is_array($workflow['latest_preflight']) ? $workflow['latest_preflight'] : [],
                'preflight'
            ),
            'latestExecute' => $this->normalize_stage_snapshot(
                isset($workflow['latest_execute']) && is_array($workflow['latest_execute']) ? $workflow['latest_execute'] : [],
                'execute'
            ),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalize_import_history(array $items)
    {
        return array_values(
            array_map(
                function ($item) {
                    return is_array($item) ? $this->normalize_execute_snapshot($item) : [];
                },
                array_filter($items, 'is_array')
            )
        );
    }

    /**
     * @param string   $run_id
     * @param string   $package_id
     * @param callable $mutator
     * @return true|WP_Error
     */
    private function update_history_entry($run_id, $package_id, $mutator)
    {
        $run_context = $this->resolve_run_context($run_id);
        if (is_wp_error($run_context)) {
            return $run_context;
        }

        return DBVC_CC_V2_Package_Storage_Service::get_instance()->update_history_item(
            $run_context['context'],
            $package_id,
            $mutator
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
                'dbvc_cc_v2_package_observability_run_missing',
                __('The requested V2 run could not be found for package observability.', 'dbvc'),
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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function build_dry_run_snapshot(array $payload)
    {
        $summary = isset($payload['summary']) && is_array($payload['summary']) ? $payload['summary'] : [];

        return [
            'generated_at' => isset($payload['generatedAt']) ? sanitize_text_field((string) $payload['generatedAt']) : current_time('c'),
            'status' => isset($payload['status']) ? sanitize_key((string) $payload['status']) : 'blocked',
            'readiness_status' => isset($payload['readinessStatus']) ? sanitize_key((string) $payload['readinessStatus']) : '',
            'summary' => [
                'included_pages' => isset($summary['includedPages']) ? absint($summary['includedPages']) : 0,
                'completed_pages' => isset($summary['completedPages']) ? absint($summary['completedPages']) : 0,
                'blocked_pages' => isset($summary['blockedPages']) ? absint($summary['blockedPages']) : 0,
                'simulated_operations' => isset($summary['simulatedOperations']) ? absint($summary['simulatedOperations']) : 0,
                'blocking_issues' => isset($summary['blockingIssues']) ? absint($summary['blockingIssues']) : 0,
                'dependency_edges' => isset($summary['dependencyEdges']) ? absint($summary['dependencyEdges']) : 0,
                'write_barriers' => isset($summary['writeBarriers']) ? absint($summary['writeBarriers']) : 0,
            ],
            'issue_count' => isset($payload['issues']) && is_array($payload['issues']) ? count($payload['issues']) : 0,
            'warning_count' => isset($payload['warnings']) && is_array($payload['warnings']) ? count($payload['warnings']) : 0,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function build_preflight_snapshot(array $payload)
    {
        $summary = isset($payload['summary']) && is_array($payload['summary']) ? $payload['summary'] : [];
        $page_approvals = isset($payload['pageApprovals']) && is_array($payload['pageApprovals']) ? $payload['pageApprovals'] : [];

        return [
            'generated_at' => isset($payload['generatedAt']) ? sanitize_text_field((string) $payload['generatedAt']) : current_time('c'),
            'status' => isset($payload['status']) ? sanitize_key((string) $payload['status']) : 'blocked',
            'approval_eligible' => ! empty($payload['approvalEligible']),
            'approval_valid' => ! empty($payload['approvalValid']),
            'execute_ready' => ! empty($payload['executeReady']),
            'summary' => [
                'included_pages' => isset($summary['includedPages']) ? absint($summary['includedPages']) : 0,
                'eligible_pages' => isset($summary['eligiblePages']) ? absint($summary['eligiblePages']) : 0,
                'approved_pages' => isset($summary['approvedPages']) ? absint($summary['approvedPages']) : 0,
                'blocked_pages' => isset($summary['blockedPages']) ? absint($summary['blockedPages']) : 0,
                'guard_failures' => isset($summary['guardFailures']) ? absint($summary['guardFailures']) : 0,
                'write_barriers' => isset($summary['writeBarriers']) ? absint($summary['writeBarriers']) : 0,
            ],
            'issue_count' => isset($payload['issues']) && is_array($payload['issues']) ? count($payload['issues']) : 0,
            'warning_count' => isset($payload['warnings']) && is_array($payload['warnings']) ? count($payload['warnings']) : 0,
            'page_approvals' => array_values(
                array_map(
                    static function ($page_approval) {
                        if (! is_array($page_approval)) {
                            return [];
                        }

                        $approval = isset($page_approval['approval']) && is_array($page_approval['approval']) ? $page_approval['approval'] : [];

                        return [
                            'page_id' => isset($page_approval['pageId']) ? sanitize_text_field((string) $page_approval['pageId']) : '',
                            'path' => isset($page_approval['path']) ? sanitize_text_field((string) $page_approval['path']) : '',
                            'status' => isset($page_approval['status']) ? sanitize_key((string) $page_approval['status']) : 'blocked',
                            'approval_eligible' => ! empty($page_approval['approvalEligible']),
                            'approval_valid' => ! empty($page_approval['approvalValid']),
                            'execute_ready' => ! empty($page_approval['executeReady']),
                            'approval_id' => isset($approval['approval_id']) ? sanitize_text_field((string) $approval['approval_id']) : '',
                            'expires_at' => isset($approval['expires_at']) ? sanitize_text_field((string) $approval['expires_at']) : '',
                            'message' => isset($page_approval['message']) ? sanitize_text_field((string) $page_approval['message']) : '',
                        ];
                    },
                    array_filter($page_approvals, 'is_array')
                )
            ),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function build_execute_snapshot(array $payload)
    {
        $summary = isset($payload['summary']) && is_array($payload['summary']) ? $payload['summary'] : [];
        $page_imports = isset($payload['pageImports']) && is_array($payload['pageImports']) ? $payload['pageImports'] : [];

        return [
            'generated_at' => isset($payload['generatedAt']) ? sanitize_text_field((string) $payload['generatedAt']) : current_time('c'),
            'status' => isset($payload['status']) ? sanitize_key((string) $payload['status']) : 'blocked',
            'summary' => [
                'included_pages' => isset($summary['includedPages']) ? absint($summary['includedPages']) : 0,
                'completed_pages' => isset($summary['completedPages']) ? absint($summary['completedPages']) : 0,
                'partial_pages' => isset($summary['partialPages']) ? absint($summary['partialPages']) : 0,
                'blocked_pages' => isset($summary['blockedPages']) ? absint($summary['blockedPages']) : 0,
                'failed_pages' => isset($summary['failedPages']) ? absint($summary['failedPages']) : 0,
                'import_runs' => isset($summary['importRuns']) ? absint($summary['importRuns']) : 0,
                'rollback_available_runs' => isset($summary['rollbackAvailableRuns']) ? absint($summary['rollbackAvailableRuns']) : 0,
                'executed_entity_writes' => isset($summary['executedEntityWrites']) ? absint($summary['executedEntityWrites']) : 0,
                'executed_field_writes' => isset($summary['executedFieldWrites']) ? absint($summary['executedFieldWrites']) : 0,
                'executed_media_writes' => isset($summary['executedMediaWrites']) ? absint($summary['executedMediaWrites']) : 0,
                'deferred_media_count' => isset($summary['deferredMediaCount']) ? absint($summary['deferredMediaCount']) : 0,
            ],
            'issue_count' => isset($payload['issues']) && is_array($payload['issues']) ? count($payload['issues']) : 0,
            'warning_count' => isset($payload['warnings']) && is_array($payload['warnings']) ? count($payload['warnings']) : 0,
            'page_imports' => $this->normalize_page_imports($page_imports),
            'import_runs' => $this->build_import_run_summaries($page_imports),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param string               $stage
     * @return array<string, mixed>
     */
    private function normalize_stage_snapshot(array $snapshot, $stage)
    {
        return [
            'stage' => sanitize_key((string) $stage),
            'generatedAt' => isset($snapshot['generated_at']) ? sanitize_text_field((string) $snapshot['generated_at']) : '',
            'status' => isset($snapshot['status']) ? sanitize_key((string) $snapshot['status']) : 'not_started',
            'readinessStatus' => isset($snapshot['readiness_status']) ? sanitize_key((string) $snapshot['readiness_status']) : '',
            'approvalEligible' => ! empty($snapshot['approval_eligible']),
            'approvalValid' => ! empty($snapshot['approval_valid']),
            'executeReady' => ! empty($snapshot['execute_ready']),
            'issueCount' => isset($snapshot['issue_count']) ? absint($snapshot['issue_count']) : 0,
            'warningCount' => isset($snapshot['warning_count']) ? absint($snapshot['warning_count']) : 0,
            'summary' => $this->normalize_summary_keys(
                isset($snapshot['summary']) && is_array($snapshot['summary']) ? $snapshot['summary'] : []
            ),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function normalize_execute_snapshot(array $snapshot)
    {
        $normalized = $this->normalize_stage_snapshot($snapshot, 'execute');
        $normalized['pageImports'] = $this->map_page_imports_to_camel(
            isset($snapshot['page_imports']) && is_array($snapshot['page_imports']) ? $snapshot['page_imports'] : []
        );
        $normalized['importRuns'] = $this->map_import_runs_to_camel(
            isset($snapshot['import_runs']) && is_array($snapshot['import_runs']) ? $snapshot['import_runs'] : []
        );

        return $normalized;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function normalize_summary_keys(array $summary)
    {
        return [
            'includedPages' => isset($summary['included_pages']) ? absint($summary['included_pages']) : 0,
            'completedPages' => isset($summary['completed_pages']) ? absint($summary['completed_pages']) : 0,
            'eligiblePages' => isset($summary['eligible_pages']) ? absint($summary['eligible_pages']) : 0,
            'approvedPages' => isset($summary['approved_pages']) ? absint($summary['approved_pages']) : 0,
            'partialPages' => isset($summary['partial_pages']) ? absint($summary['partial_pages']) : 0,
            'blockedPages' => isset($summary['blocked_pages']) ? absint($summary['blocked_pages']) : 0,
            'failedPages' => isset($summary['failed_pages']) ? absint($summary['failed_pages']) : 0,
            'simulatedOperations' => isset($summary['simulated_operations']) ? absint($summary['simulated_operations']) : 0,
            'blockingIssues' => isset($summary['blocking_issues']) ? absint($summary['blocking_issues']) : 0,
            'dependencyEdges' => isset($summary['dependency_edges']) ? absint($summary['dependency_edges']) : 0,
            'writeBarriers' => isset($summary['write_barriers']) ? absint($summary['write_barriers']) : 0,
            'guardFailures' => isset($summary['guard_failures']) ? absint($summary['guard_failures']) : 0,
            'importRuns' => isset($summary['import_runs']) ? absint($summary['import_runs']) : 0,
            'rollbackAvailableRuns' => isset($summary['rollback_available_runs']) ? absint($summary['rollback_available_runs']) : 0,
            'executedEntityWrites' => isset($summary['executed_entity_writes']) ? absint($summary['executed_entity_writes']) : 0,
            'executedFieldWrites' => isset($summary['executed_field_writes']) ? absint($summary['executed_field_writes']) : 0,
            'executedMediaWrites' => isset($summary['executed_media_writes']) ? absint($summary['executed_media_writes']) : 0,
            'deferredMediaCount' => isset($summary['deferred_media_count']) ? absint($summary['deferred_media_count']) : 0,
            'recordCount' => isset($summary['record_count']) ? absint($summary['record_count']) : 0,
            'includedPageCount' => isset($summary['included_page_count']) ? absint($summary['included_page_count']) : 0,
            'warningCount' => isset($summary['warning_count']) ? absint($summary['warning_count']) : 0,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $page_imports
     * @return array<int, array<string, mixed>>
     */
    private function normalize_page_imports(array $page_imports)
    {
        return array_values(
            array_map(
                static function ($page_import) {
                    if (! is_array($page_import)) {
                        return [];
                    }

                    return [
                        'page_id' => isset($page_import['pageId']) ? sanitize_text_field((string) $page_import['pageId']) : '',
                        'path' => isset($page_import['path']) ? sanitize_text_field((string) $page_import['path']) : '',
                        'status' => isset($page_import['status']) ? sanitize_key((string) $page_import['status']) : 'blocked',
                        'run_id' => isset($page_import['runId']) ? absint($page_import['runId']) : 0,
                        'run_uuid' => isset($page_import['runUuid']) ? sanitize_text_field((string) $page_import['runUuid']) : '',
                        'rollback_available' => ! empty($page_import['rollbackAvailable']),
                        'rollback_status' => isset($page_import['rollbackStatus']) ? sanitize_key((string) $page_import['rollbackStatus']) : 'not_started',
                        'message' => isset($page_import['message']) ? sanitize_text_field((string) $page_import['message']) : '',
                    ];
                },
                array_filter($page_imports, 'is_array')
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $page_imports
     * @return array<int, array<string, mixed>>
     */
    private function build_import_run_summaries(array $page_imports)
    {
        $store = DBVC_CC_Import_Run_Store::get_instance();
        $runs = [];

        foreach ($page_imports as $page_import) {
            if (! is_array($page_import) || empty($page_import['runId'])) {
                continue;
            }

            $run_id = absint($page_import['runId']);
            if ($run_id <= 0 || isset($runs[$run_id])) {
                continue;
            }

            $run = $store->get_run($run_id);
            if (! is_array($run)) {
                continue;
            }

            $summary = isset($run['summary']) && is_array($run['summary']) ? $run['summary'] : [];
            $runs[$run_id] = [
                'run_id' => $run_id,
                'run_uuid' => isset($run['run_uuid']) ? sanitize_text_field((string) $run['run_uuid']) : '',
                'path' => isset($run['path']) ? sanitize_text_field((string) $run['path']) : '',
                'source_url' => isset($run['source_url']) ? esc_url_raw((string) $run['source_url']) : '',
                'status' => isset($run['status']) ? sanitize_key((string) $run['status']) : '',
                'created_at' => isset($run['created_at']) ? sanitize_text_field((string) $run['created_at']) : '',
                'approved_at' => isset($run['approved_at']) ? sanitize_text_field((string) $run['approved_at']) : '',
                'started_at' => isset($run['started_at']) ? sanitize_text_field((string) $run['started_at']) : '',
                'finished_at' => isset($run['finished_at']) ? sanitize_text_field((string) $run['finished_at']) : '',
                'rollback_status' => isset($run['rollback_status']) ? sanitize_key((string) $run['rollback_status']) : '',
                'rollback_started_at' => isset($run['rollback_started_at']) ? sanitize_text_field((string) $run['rollback_started_at']) : '',
                'rollback_finished_at' => isset($run['rollback_finished_at']) ? sanitize_text_field((string) $run['rollback_finished_at']) : '',
                'error_summary' => isset($run['error_summary']) ? sanitize_text_field((string) $run['error_summary']) : '',
                'summary' => [
                    'total_actions' => isset($summary['total_actions']) ? absint($summary['total_actions']) : 0,
                    'executed_actions' => isset($summary['executed_actions']) ? absint($summary['executed_actions']) : 0,
                    'failed_actions' => isset($summary['failed_actions']) ? absint($summary['failed_actions']) : 0,
                    'rolled_back_actions' => isset($summary['rolled_back_actions']) ? absint($summary['rolled_back_actions']) : 0,
                ],
            ];
        }

        return array_values($runs);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function map_page_imports_to_camel(array $items)
    {
        return array_values(
            array_map(
                static function ($item) {
                    if (! is_array($item)) {
                        return [];
                    }

                    return [
                        'pageId' => isset($item['page_id']) ? sanitize_text_field((string) $item['page_id']) : '',
                        'path' => isset($item['path']) ? sanitize_text_field((string) $item['path']) : '',
                        'status' => isset($item['status']) ? sanitize_key((string) $item['status']) : 'blocked',
                        'runId' => isset($item['run_id']) ? absint($item['run_id']) : 0,
                        'runUuid' => isset($item['run_uuid']) ? sanitize_text_field((string) $item['run_uuid']) : '',
                        'rollbackAvailable' => ! empty($item['rollback_available']),
                        'rollbackStatus' => isset($item['rollback_status']) ? sanitize_key((string) $item['rollback_status']) : 'not_started',
                        'message' => isset($item['message']) ? sanitize_text_field((string) $item['message']) : '',
                    ];
                },
                array_filter($items, 'is_array')
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function map_import_runs_to_camel(array $items)
    {
        return array_values(
            array_map(
                function ($item) {
                    if (! is_array($item)) {
                        return [];
                    }

                    return [
                        'runId' => isset($item['run_id']) ? absint($item['run_id']) : 0,
                        'runUuid' => isset($item['run_uuid']) ? sanitize_text_field((string) $item['run_uuid']) : '',
                        'path' => isset($item['path']) ? sanitize_text_field((string) $item['path']) : '',
                        'sourceUrl' => isset($item['source_url']) ? esc_url_raw((string) $item['source_url']) : '',
                        'status' => isset($item['status']) ? sanitize_key((string) $item['status']) : '',
                        'createdAt' => isset($item['created_at']) ? sanitize_text_field((string) $item['created_at']) : '',
                        'approvedAt' => isset($item['approved_at']) ? sanitize_text_field((string) $item['approved_at']) : '',
                        'startedAt' => isset($item['started_at']) ? sanitize_text_field((string) $item['started_at']) : '',
                        'finishedAt' => isset($item['finished_at']) ? sanitize_text_field((string) $item['finished_at']) : '',
                        'rollbackStatus' => isset($item['rollback_status']) ? sanitize_key((string) $item['rollback_status']) : '',
                        'rollbackStartedAt' => isset($item['rollback_started_at']) ? sanitize_text_field((string) $item['rollback_started_at']) : '',
                        'rollbackFinishedAt' => isset($item['rollback_finished_at']) ? sanitize_text_field((string) $item['rollback_finished_at']) : '',
                        'errorSummary' => isset($item['error_summary']) ? sanitize_text_field((string) $item['error_summary']) : '',
                        'summary' => $this->normalize_summary_keys(
                            isset($item['summary']) && is_array($item['summary']) ? $item['summary'] : []
                        ),
                    ];
                },
                array_filter($items, 'is_array')
            )
        );
    }
}
