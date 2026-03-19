<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Import_Execution_Bridge_Service
{
    /**
     * @var DBVC_CC_V2_Import_Execution_Bridge_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Import_Execution_Bridge_Service
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
     * @param bool   $confirm_approval
     * @return array<string, mixed>|WP_Error
     */
    public function approve_package_preflight($run_id, $package_id = '', $confirm_approval = false)
    {
        $collection = DBVC_CC_V2_Import_Collection_Service::get_instance()->build_collection_context($run_id, $package_id);
        if (is_wp_error($collection)) {
            return $collection;
        }

        $page_details = isset($collection['pageExecutionDetails']) && is_array($collection['pageExecutionDetails'])
            ? $collection['pageExecutionDetails']
            : [];

        $page_approvals = [];
        $issues = isset($collection['issues']) && is_array($collection['issues']) ? array_values($collection['issues']) : [];
        $warnings = isset($collection['warnings']) && is_array($collection['warnings']) ? array_values($collection['warnings']) : [];
        $summary = [
            'includedPages' => count($page_details),
            'eligiblePages' => 0,
            'approvedPages' => 0,
            'blockedPages' => 0,
            'guardFailures' => 0,
            'writeBarriers' => 0,
        ];
        $approval_tokens = [];

        foreach ($page_details as $page_detail) {
            if (! is_array($page_detail) || empty($page_detail['dryRunExecution']) || ! is_array($page_detail['dryRunExecution'])) {
                continue;
            }

            $page_id = isset($page_detail['pageId']) ? (string) $page_detail['pageId'] : '';
            $path = isset($page_detail['path']) ? (string) $page_detail['path'] : '';
            $approval = DBVC_CC_Import_Executor_Service::get_instance()->approve_preflight_from_execution(
                $page_detail['dryRunExecution'],
                $confirm_approval
            );
            if (is_wp_error($approval)) {
                ++$summary['blockedPages'];
                $issues[] = $this->make_issue(
                    'preflight_bridge_failed',
                    $approval->get_error_message(),
                    $page_id,
                    $path
                );
                continue;
            }

            if (! empty($approval['approval_eligible'])) {
                ++$summary['eligiblePages'];
            }
            if (! empty($approval['approval_valid'])) {
                ++$summary['approvedPages'];
            } else {
                ++$summary['blockedPages'];
            }

            $guard_failures = isset($approval['guard_failures']) && is_array($approval['guard_failures'])
                ? array_values($approval['guard_failures'])
                : [];
            $write_barriers = isset($approval['write_barriers']) && is_array($approval['write_barriers'])
                ? array_values($approval['write_barriers'])
                : [];

            $summary['guardFailures'] += count($guard_failures);
            $summary['writeBarriers'] += isset($approval['operation_counts']['write_barrier_count'])
                ? absint($approval['operation_counts']['write_barrier_count'])
                : 0;

            foreach ($guard_failures as $guard_failure) {
                if (! is_array($guard_failure)) {
                    continue;
                }
                $issues[] = $this->make_issue(
                    isset($guard_failure['code']) ? (string) $guard_failure['code'] : 'preflight_guard_failure',
                    isset($guard_failure['message']) ? (string) $guard_failure['message'] : __('Import preflight is blocked.', 'dbvc'),
                    $page_id,
                    $path
                );
            }

            foreach ($write_barriers as $write_barrier) {
                if (! is_array($write_barrier) || empty($write_barrier['blocking'])) {
                    continue;
                }
                $issues[] = $this->make_issue(
                    isset($write_barrier['code']) ? (string) $write_barrier['code'] : 'preflight_write_barrier',
                    isset($write_barrier['message']) ? (string) $write_barrier['message'] : __('Import preflight found a blocking write barrier.', 'dbvc'),
                    $page_id,
                    $path
                );
            }

            if (! empty($approval['approval']['approval_token'])) {
                $approval_tokens[$page_id] = (string) $approval['approval']['approval_token'];
            }

            $page_approvals[] = [
                'pageId' => $page_id,
                'path' => $path,
                'status' => isset($approval['status']) ? (string) $approval['status'] : 'blocked',
                'approvalEligible' => ! empty($approval['approval_eligible']),
                'approvalValid' => ! empty($approval['approval_valid']),
                'executeReady' => ! empty($approval['execute_ready']),
                'planId' => isset($approval['plan_id']) ? (string) $approval['plan_id'] : '',
                'dryRunExecutionId' => isset($approval['dry_run_execution_id']) ? (string) $approval['dry_run_execution_id'] : '',
                'approval' => isset($approval['approval']) && is_array($approval['approval']) ? $approval['approval'] : [],
                'guardFailures' => $guard_failures,
                'writeBarriers' => $write_barriers,
                'operationCounts' => isset($approval['operation_counts']) && is_array($approval['operation_counts']) ? $approval['operation_counts'] : [],
                'message' => isset($approval['message']) ? (string) $approval['message'] : '',
            ];
        }

        $payload = [
            'runId' => isset($collection['runId']) ? (string) $collection['runId'] : '',
            'packageId' => isset($collection['packageId']) ? (string) $collection['packageId'] : '',
            'domain' => isset($collection['domain']) ? (string) $collection['domain'] : '',
            'generatedAt' => current_time('c'),
            'status' => $summary['includedPages'] > 0 && $summary['approvedPages'] === $summary['includedPages'] ? 'approved' : 'blocked',
            'approvalEligible' => $summary['eligiblePages'] === $summary['includedPages'] && $summary['includedPages'] > 0,
            'approvalValid' => $summary['approvedPages'] === $summary['includedPages'] && $summary['includedPages'] > 0,
            'executeReady' => $summary['approvedPages'] === $summary['includedPages'] && $summary['includedPages'] > 0,
            'summary' => $summary,
            'package' => isset($collection['package']) && is_array($collection['package']) ? $collection['package'] : [],
            'approvalTokens' => $approval_tokens,
            'issues' => array_values($issues),
            'warnings' => $warnings,
            'pageApprovals' => $page_approvals,
        ];

        if (! empty($payload['packageId'])) {
            DBVC_CC_V2_Package_Observability_Service::get_instance()->record_preflight_snapshot(
                $run_id,
                (string) $payload['packageId'],
                $payload
            );
        }

        return $payload;
    }

    /**
     * @param string               $run_id
     * @param string               $package_id
     * @param bool                 $confirm_execute
     * @param array<string, mixed> $approval_tokens
     * @return array<string, mixed>|WP_Error
     */
    public function execute_package_import($run_id, $package_id = '', $confirm_execute = false, array $approval_tokens = [])
    {
        $collection = DBVC_CC_V2_Import_Collection_Service::get_instance()->build_collection_context($run_id, $package_id);
        if (is_wp_error($collection)) {
            return $collection;
        }

        $page_details = isset($collection['pageExecutionDetails']) && is_array($collection['pageExecutionDetails'])
            ? $collection['pageExecutionDetails']
            : [];
        $page_imports = [];
        $issues = isset($collection['issues']) && is_array($collection['issues']) ? array_values($collection['issues']) : [];
        $warnings = isset($collection['warnings']) && is_array($collection['warnings']) ? array_values($collection['warnings']) : [];
        $summary = [
            'includedPages' => count($page_details),
            'completedPages' => 0,
            'partialPages' => 0,
            'blockedPages' => 0,
            'failedPages' => 0,
            'importRuns' => 0,
            'rollbackAvailableRuns' => 0,
            'executedEntityWrites' => 0,
            'executedFieldWrites' => 0,
            'executedMediaWrites' => 0,
            'deferredMediaCount' => 0,
        ];

        foreach ($page_details as $page_detail) {
            if (! is_array($page_detail) || empty($page_detail['dryRunExecution']) || ! is_array($page_detail['dryRunExecution'])) {
                continue;
            }

            $page_id = isset($page_detail['pageId']) ? (string) $page_detail['pageId'] : '';
            $path = isset($page_detail['path']) ? (string) $page_detail['path'] : '';
            $approval_token = $this->resolve_approval_token($approval_tokens, $page_id, $path);
            $execution = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton_from_execution(
                $page_detail['dryRunExecution'],
                $confirm_execute,
                $approval_token
            );
            if (is_wp_error($execution)) {
                ++$summary['blockedPages'];
                $issues[] = $this->make_issue(
                    'package_execute_failed',
                    $execution->get_error_message(),
                    $page_id,
                    $path
                );
                continue;
            }

            $status = isset($execution['status']) ? (string) $execution['status'] : 'blocked';
            if ($status === 'completed') {
                ++$summary['completedPages'];
            } elseif (in_array($status, ['completed_partial', 'completed_with_failures', 'rolled_back_after_failure', 'rollback_failed_after_failure'], true)) {
                ++$summary['partialPages'];
            } elseif ($status === 'blocked') {
                ++$summary['blockedPages'];
            } else {
                ++$summary['failedPages'];
            }

            if (! empty($execution['run_id'])) {
                ++$summary['importRuns'];
            }
            if (! empty($execution['rollback_available'])) {
                ++$summary['rollbackAvailableRuns'];
            }

            $summary['executedEntityWrites'] += isset($execution['operation_counts']['executed_entity_writes'])
                ? absint($execution['operation_counts']['executed_entity_writes'])
                : 0;
            $summary['executedFieldWrites'] += isset($execution['operation_counts']['executed_field_writes'])
                ? absint($execution['operation_counts']['executed_field_writes'])
                : 0;
            $summary['executedMediaWrites'] += isset($execution['operation_counts']['executed_media_writes'])
                ? absint($execution['operation_counts']['executed_media_writes'])
                : 0;
            $summary['deferredMediaCount'] += isset($execution['deferred_media_count'])
                ? absint($execution['deferred_media_count'])
                : 0;

            $guard_failures = isset($execution['guard_failures']) && is_array($execution['guard_failures'])
                ? array_values($execution['guard_failures'])
                : [];
            $execution_failures = isset($execution['execution_failures']) && is_array($execution['execution_failures'])
                ? array_values($execution['execution_failures'])
                : [];

            foreach ($guard_failures as $guard_failure) {
                if (! is_array($guard_failure)) {
                    continue;
                }
                $issues[] = $this->make_issue(
                    isset($guard_failure['code']) ? (string) $guard_failure['code'] : 'package_execute_guard_failure',
                    isset($guard_failure['message']) ? (string) $guard_failure['message'] : __('Package execute is blocked by a guard failure.', 'dbvc'),
                    $page_id,
                    $path
                );
            }

            foreach ($execution_failures as $execution_failure) {
                if (! is_array($execution_failure)) {
                    continue;
                }
                $issues[] = $this->make_issue(
                    isset($execution_failure['code']) ? (string) $execution_failure['code'] : 'package_execute_failure',
                    isset($execution_failure['message']) ? (string) $execution_failure['message'] : __('Package execute encountered a write failure.', 'dbvc'),
                    $page_id,
                    $path
                );
            }

            $page_imports[] = [
                'pageId' => $page_id,
                'path' => $path,
                'status' => $status,
                'runId' => isset($execution['run_id']) ? absint($execution['run_id']) : 0,
                'runUuid' => isset($execution['run_uuid']) ? (string) $execution['run_uuid'] : '',
                'rollbackAvailable' => ! empty($execution['rollback_available']),
                'rollbackStatus' => isset($execution['rollback_status']) ? (string) $execution['rollback_status'] : 'not_started',
                'executedStages' => isset($execution['executed_stages']) && is_array($execution['executed_stages']) ? array_values($execution['executed_stages']) : [],
                'deferredStages' => isset($execution['deferred_stages']) && is_array($execution['deferred_stages']) ? array_values($execution['deferred_stages']) : [],
                'guardFailures' => $guard_failures,
                'executionFailures' => $execution_failures,
                'operationCounts' => isset($execution['operation_counts']) && is_array($execution['operation_counts']) ? $execution['operation_counts'] : [],
                'message' => isset($execution['message']) ? (string) $execution['message'] : '',
            ];
        }

        $payload = [
            'runId' => isset($collection['runId']) ? (string) $collection['runId'] : '',
            'packageId' => isset($collection['packageId']) ? (string) $collection['packageId'] : '',
            'domain' => isset($collection['domain']) ? (string) $collection['domain'] : '',
            'generatedAt' => current_time('c'),
            'status' => $this->resolve_execution_status($summary),
            'summary' => $summary,
            'package' => isset($collection['package']) && is_array($collection['package']) ? $collection['package'] : [],
            'issues' => array_values($issues),
            'warnings' => $warnings,
            'pageImports' => $page_imports,
        ];

        if (! empty($payload['packageId'])) {
            DBVC_CC_V2_Package_Observability_Service::get_instance()->record_execute_snapshot(
                $run_id,
                (string) $payload['packageId'],
                $payload
            );
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $approval_tokens
     * @param string               $page_id
     * @param string               $path
     * @return string
     */
    private function resolve_approval_token(array $approval_tokens, $page_id, $path)
    {
        if ($page_id !== '' && isset($approval_tokens[$page_id]) && is_scalar($approval_tokens[$page_id])) {
            return sanitize_text_field((string) $approval_tokens[$page_id]);
        }

        if ($path !== '' && isset($approval_tokens[$path]) && is_scalar($approval_tokens[$path])) {
            return sanitize_text_field((string) $approval_tokens[$path]);
        }

        return '';
    }

    /**
     * @param string $code
     * @param string $message
     * @param string $page_id
     * @param string $path
     * @return array<string, mixed>
     */
    private function make_issue($code, $message, $page_id, $path)
    {
        return [
            'code' => sanitize_key((string) $code),
            'message' => sanitize_text_field((string) $message),
            'severity' => 'blocking',
            'pageId' => sanitize_text_field((string) $page_id),
            'path' => sanitize_text_field((string) $path),
        ];
    }

    /**
     * @param array<string, int> $summary
     * @return string
     */
    private function resolve_execution_status(array $summary)
    {
        if (! empty($summary['blockedPages']) && empty($summary['completedPages']) && empty($summary['partialPages']) && empty($summary['failedPages'])) {
            return 'blocked';
        }

        if (! empty($summary['failedPages'])) {
            return 'completed_with_failures';
        }

        if (! empty($summary['partialPages']) || ! empty($summary['blockedPages'])) {
            return 'completed_partial';
        }

        return ! empty($summary['completedPages']) ? 'completed' : 'blocked';
    }
}
