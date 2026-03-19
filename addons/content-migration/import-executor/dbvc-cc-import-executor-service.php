<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Import_Executor_Service
{
    private const DBVC_CC_EXECUTOR_SCHEMA_VERSION = '1.8.0';
    private const DBVC_CC_WRITE_PLAN_SCHEMA_VERSION = '1.1.0';
    private const DBVC_CC_PREFLIGHT_APPROVAL_SCHEMA_VERSION = '1.0.0';
    private const DBVC_CC_PREFLIGHT_APPROVAL_TTL = 900;
    private const DBVC_CC_PREFLIGHT_APPROVAL_TRANSIENT_PREFIX = 'dbvc_cc_exec_approval_';

    /**
     * @var DBVC_CC_Import_Executor_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_Import_Executor_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Executes a Phase 4 dry-run simulation without writing import data.
     *
     * @param string $domain
     * @param string $path
     * @param bool   $build_if_missing
     * @return array<string, mixed>|WP_Error
     */
    public function execute_dry_run($domain, $path, $build_if_missing = true)
    {
        $dry_run_plan = DBVC_CC_Import_Plan_Service::get_instance()->get_dry_run_plan($domain, $path, $build_if_missing);
        if (is_wp_error($dry_run_plan)) {
            return $dry_run_plan;
        }

        return $this->execute_dry_run_plan($dry_run_plan);
    }

    /**
     * Executes a dry-run simulation from a prepared dry-run plan payload.
     *
     * @param array<string, mixed> $dry_run_plan
     * @return array<string, mixed>|WP_Error
     */
    public function execute_dry_run_plan(array $dry_run_plan)
    {
        $plan_status = isset($dry_run_plan['status']) ? sanitize_key((string) $dry_run_plan['status']) : 'blocked';
        $domain = isset($dry_run_plan['domain']) ? sanitize_text_field((string) $dry_run_plan['domain']) : '';
        $path = isset($dry_run_plan['path']) ? sanitize_text_field((string) $dry_run_plan['path']) : '';
        $source_url = isset($dry_run_plan['source_url']) ? esc_url_raw((string) $dry_run_plan['source_url']) : '';

        $mapping_operations = isset($dry_run_plan['operations']['upsert_mappings']) && is_array($dry_run_plan['operations']['upsert_mappings'])
            ? array_values($dry_run_plan['operations']['upsert_mappings'])
            : [];
        $media_operations = isset($dry_run_plan['operations']['upsert_media_mappings']) && is_array($dry_run_plan['operations']['upsert_media_mappings'])
            ? array_values($dry_run_plan['operations']['upsert_media_mappings'])
            : [];
        $issues = isset($dry_run_plan['issues']) && is_array($dry_run_plan['issues'])
            ? array_values($dry_run_plan['issues'])
            : [];
        $default_entity_key = $this->dbvc_cc_resolve_default_entity_key($dry_run_plan);
        $entity_graph = $this->dbvc_cc_build_entity_operations($domain, $path, $source_url, $mapping_operations, $media_operations, $default_entity_key);
        $field_operations = $this->dbvc_cc_build_field_operations(
            $domain,
            $path,
            $mapping_operations,
            isset($entity_graph['operation_id_by_entity_key']) && is_array($entity_graph['operation_id_by_entity_key'])
                ? $entity_graph['operation_id_by_entity_key']
                : [],
            $default_entity_key
        );
        $media_operations = $this->dbvc_cc_build_media_operations(
            $domain,
            $path,
            $media_operations,
            isset($entity_graph['operation_id_by_entity_key']) && is_array($entity_graph['operation_id_by_entity_key'])
                ? $entity_graph['operation_id_by_entity_key']
                : [],
            $default_entity_key
        );

        $ordered_entity = $this->dbvc_cc_apply_execution_order(
            1,
            isset($entity_graph['entity_operations']) && is_array($entity_graph['entity_operations']) ? $entity_graph['entity_operations'] : []
        );
        $ordered_field = $this->dbvc_cc_apply_execution_order(
            isset($ordered_entity['next_index']) ? (int) $ordered_entity['next_index'] : 1,
            $field_operations
        );
        $ordered_media = $this->dbvc_cc_apply_execution_order(
            isset($ordered_field['next_index']) ? (int) $ordered_field['next_index'] : 1,
            $media_operations
        );

        $entity_operations = isset($ordered_entity['operations']) && is_array($ordered_entity['operations']) ? $ordered_entity['operations'] : [];
        $field_operations = isset($ordered_field['operations']) && is_array($ordered_field['operations']) ? $ordered_field['operations'] : [];
        $media_operations = isset($ordered_media['operations']) && is_array($ordered_media['operations']) ? $ordered_media['operations'] : [];

        $entity_resolution_issues = isset($entity_graph['resolution_issues']) && is_array($entity_graph['resolution_issues'])
            ? array_values($entity_graph['resolution_issues'])
            : [];
        if (! empty($entity_resolution_issues)) {
            $issues = array_values(array_merge($issues, $entity_resolution_issues));
        }

        $simulated_operations = array_values(array_merge($entity_operations, $field_operations, $media_operations));
        $operation_graph_fingerprint = $this->dbvc_cc_build_operation_graph_fingerprint($simulated_operations);
        $dependency_edge_count = $this->dbvc_cc_count_dependency_edges($simulated_operations);

        $blocking_issue_count = 0;
        foreach ($issues as $issue) {
            if (is_array($issue) && ! empty($issue['blocking'])) {
                $blocking_issue_count++;
            }
        }

        $dry_run_execution_preview = [
            'domain' => $domain,
            'path' => $path,
            'source_url' => $source_url,
            'execution_id' => '',
            'operation_graph' => [
                'entity_operations' => $entity_operations,
                'field_operations' => $field_operations,
                'media_operations' => $media_operations,
            ],
        ];
        $write_preparation = $this->dbvc_cc_build_write_preparation($dry_run_execution_preview);
        $write_barriers = isset($write_preparation['write_barriers']) && is_array($write_preparation['write_barriers'])
            ? array_values($write_preparation['write_barriers'])
            : [];
        $write_barrier_count = count(array_filter($write_barriers, static function ($barrier) {
            return is_array($barrier) && ! empty($barrier['blocking']);
        }));
        $deferred_media_count = isset($write_preparation['operation_counts']['deferred_media_writes'])
            ? absint($write_preparation['operation_counts']['deferred_media_writes'])
            : 0;
        $deferred_media_reasons = isset($write_preparation['deferred_media_reasons']) && is_array($write_preparation['deferred_media_reasons'])
            ? array_values($write_preparation['deferred_media_reasons'])
            : [];

        $executor_status = ($plan_status === 'ready' && $blocking_issue_count === 0 && $write_barrier_count === 0) ? 'completed' : 'blocked';
        $plan_id = isset($dry_run_plan['plan_id']) ? sanitize_key((string) $dry_run_plan['plan_id']) : '';
        $dbvc_cc_phase4_context = $this->dbvc_cc_build_phase4_context($dry_run_plan);
        $execution_id = 'dbvc_cc_exec_' . substr(hash('sha256', wp_json_encode([
            'domain' => $domain,
            'path' => $path,
            'plan_id' => $plan_id,
            'graph_fingerprint' => $operation_graph_fingerprint,
        ])), 0, 16);

        return [
            'executor_schema_version' => self::DBVC_CC_EXECUTOR_SCHEMA_VERSION,
            'generated_at' => current_time('c'),
            'execution_id' => $execution_id,
            'status' => $executor_status,
            'dry_run' => true,
            'write_performed' => false,
            'domain' => $domain,
            'path' => $path,
            'source_url' => $source_url,
            'plan_id' => $plan_id,
            'policy' => isset($dry_run_plan['policy']) && is_array($dry_run_plan['policy']) ? $dry_run_plan['policy'] : [],
            'phase4_context' => $dbvc_cc_phase4_context,
            'trace' => $this->dbvc_cc_build_execution_trace($dry_run_plan, $plan_status, $operation_graph_fingerprint),
            'operation_counts' => [
                'total_simulated' => count($simulated_operations),
                'entity_resolutions' => count($entity_operations),
                'entity_updates' => $this->dbvc_cc_count_operations_by_result($entity_operations, 'would_update_existing'),
                'entity_creates' => $this->dbvc_cc_count_operations_by_result($entity_operations, 'would_create_new'),
                'entity_blocked' => $this->dbvc_cc_count_operations_by_result($entity_operations, 'blocked_needs_review'),
                'field_mappings' => count($field_operations),
                'media_mappings' => count($media_operations),
                'dependency_edges' => $dependency_edge_count,
                'write_barrier_count' => $write_barrier_count,
                'deferred_media_count' => $deferred_media_count,
            ],
            'operation_graph' => [
                'graph_schema_version' => '1.0.0',
                'graph_fingerprint' => $operation_graph_fingerprint,
                'entity_operations' => $entity_operations,
                'field_operations' => $field_operations,
                'media_operations' => $media_operations,
            ],
            'blocking_issue_count' => $blocking_issue_count,
            'issues' => $issues,
            'write_barriers' => $write_barriers,
            'write_preparation' => $write_preparation,
            'deferred_media_count' => $deferred_media_count,
            'deferred_media_reasons' => $deferred_media_reasons,
            'simulated_operations' => $simulated_operations,
            'dry_run_plan' => $dry_run_plan,
        ];
    }

    /**
     * Generates a short-lived approval token for the current dry-run fingerprint.
     *
     * @param string $domain
     * @param string $path
     * @param bool   $build_if_missing
     * @param bool   $confirm_approval
     * @return array<string, mixed>|WP_Error
     */
    public function approve_preflight($domain, $path, $build_if_missing = true, $confirm_approval = false)
    {
        $dry_run_execution = $this->execute_dry_run($domain, $path, $build_if_missing);
        if (is_wp_error($dry_run_execution)) {
            return $dry_run_execution;
        }

        return $this->dbvc_cc_approve_preflight_from_dry_run_execution($dry_run_execution, $confirm_approval);
    }

    /**
     * @param array<string, mixed> $dry_run_execution
     * @param bool                 $confirm_approval
     * @return array<string, mixed>|WP_Error
     */
    public function approve_preflight_from_execution(array $dry_run_execution, $confirm_approval = false)
    {
        return $this->dbvc_cc_approve_preflight_from_dry_run_execution($dry_run_execution, $confirm_approval);
    }

    /**
     * Returns approval-token validity for the current dry-run fingerprint.
     *
     * @param string $domain
     * @param string $path
     * @param string $approval_token
     * @param bool   $build_if_missing
     * @return array<string, mixed>|WP_Error
     */
    public function get_preflight_status($domain, $path, $approval_token = '', $build_if_missing = true)
    {
        $dry_run_execution = $this->execute_dry_run($domain, $path, $build_if_missing);
        if (is_wp_error($dry_run_execution)) {
            return $dry_run_execution;
        }

        return $this->dbvc_cc_get_preflight_status_from_dry_run_execution($dry_run_execution, $approval_token);
    }

    /**
     * @param array<string, mixed> $dry_run_execution
     * @param string               $approval_token
     * @return array<string, mixed>|WP_Error
     */
    public function get_preflight_status_from_execution(array $dry_run_execution, $approval_token = '')
    {
        return $this->dbvc_cc_get_preflight_status_from_dry_run_execution($dry_run_execution, $approval_token);
    }

    /**
     * @param array<string, mixed> $dry_run_execution
     * @param bool                 $confirm_execute
     * @param string               $approval_token
     * @return array<string, mixed>|WP_Error
     */
    public function execute_write_skeleton_from_execution(array $dry_run_execution, $confirm_execute = false, $approval_token = '')
    {
        return $this->dbvc_cc_execute_write_skeleton_from_dry_run_execution(
            $dry_run_execution,
            $confirm_execute,
            $approval_token
        );
    }

    /**
     * @param array<string, mixed> $dry_run_execution
     * @param bool                 $confirm_approval
     * @return array<string, mixed>|WP_Error
     */
    private function dbvc_cc_approve_preflight_from_dry_run_execution(array $dry_run_execution, $confirm_approval = false)
    {
        $domain = isset($dry_run_execution['domain']) ? sanitize_text_field((string) $dry_run_execution['domain']) : '';
        $path = isset($dry_run_execution['path']) ? sanitize_text_field((string) $dry_run_execution['path']) : '';
        $source_url = isset($dry_run_execution['source_url']) ? esc_url_raw((string) $dry_run_execution['source_url']) : '';
        $dry_run_status = isset($dry_run_execution['status']) ? sanitize_key((string) $dry_run_execution['status']) : 'blocked';
        $write_preparation = $this->dbvc_cc_build_write_preparation($dry_run_execution);
        $write_barriers = isset($write_preparation['write_barriers']) && is_array($write_preparation['write_barriers'])
            ? array_values($write_preparation['write_barriers'])
            : [];
        $write_barrier_count = count(array_filter($write_barriers, static function ($barrier) {
            return is_array($barrier) && ! empty($barrier['blocking']);
        }));
        $deferred_media_count = isset($write_preparation['operation_counts']['deferred_media_writes'])
            ? absint($write_preparation['operation_counts']['deferred_media_writes'])
            : 0;
        $deferred_media_reasons = isset($write_preparation['deferred_media_reasons']) && is_array($write_preparation['deferred_media_reasons'])
            ? array_values($write_preparation['deferred_media_reasons'])
            : [];

        $guard_failures = [];
        if ($dry_run_status !== 'completed') {
            $guard_failures[] = $this->dbvc_cc_build_guard_failure(
                'dry_run_not_completed',
                __('Dry-run execution must be completed before preflight approval is eligible.', 'dbvc'),
                true
            );
        }
        if (! rest_sanitize_boolean($confirm_approval)) {
            $guard_failures[] = $this->dbvc_cc_build_guard_failure(
                'approval_confirmation_required',
                __('Explicit approval confirmation is required before issuing a preflight token.', 'dbvc'),
                false
            );
        }

        $approval_required = true;
        $approval_eligible = $dry_run_status === 'completed' && $write_barrier_count === 0;
        $approval = $this->dbvc_cc_get_empty_preflight_approval();
        $approval_valid = false;
        $status = 'blocked';
        if ($approval_eligible && empty($guard_failures)) {
            $approval_context = $this->dbvc_cc_build_preflight_approval_context($dry_run_execution, $write_preparation);
            $approval_summary = $this->dbvc_cc_build_preflight_approval_summary($dry_run_execution, $write_preparation);
            $approval = $this->dbvc_cc_create_preflight_approval($approval_context, $approval_summary);
            $approval_valid = ! empty($approval['approval_token']);
            $status = $approval_valid ? 'approved' : 'blocked';
        } elseif ($approval_eligible) {
            $status = 'confirmation_required';
        }

        $summary = $this->dbvc_cc_build_preflight_approval_summary($dry_run_execution, $write_preparation);

        return [
            'executor_schema_version' => self::DBVC_CC_EXECUTOR_SCHEMA_VERSION,
            'generated_at' => current_time('c'),
            'status' => $status,
            'domain' => $domain,
            'path' => $path,
            'source_url' => $source_url,
            'plan_id' => isset($dry_run_execution['plan_id']) ? sanitize_key((string) $dry_run_execution['plan_id']) : '',
            'dry_run_execution_id' => isset($dry_run_execution['execution_id']) ? sanitize_key((string) $dry_run_execution['execution_id']) : '',
            'approval_required' => $approval_required,
            'approval_eligible' => $approval_eligible,
            'approval_valid' => $approval_valid,
            'execute_ready' => $approval_eligible && $approval_valid,
            'approval' => $approval,
            'summary' => $summary,
            'guard_failures' => $guard_failures,
            'write_barriers' => $write_barriers,
            'deferred_media_count' => $deferred_media_count,
            'deferred_media_reasons' => $deferred_media_reasons,
            'operation_counts' => [
                'guard_failure_count' => count($guard_failures),
                'write_barrier_count' => $write_barrier_count,
                'deferred_media_count' => $deferred_media_count,
            ],
            'message' => $approval_valid
                ? __('Preflight approval granted.', 'dbvc')
                : ($approval_eligible
                    ? __('Preflight approval is ready once explicit confirmation is provided.', 'dbvc')
                    : __('Preflight approval is blocked until the dry-run is completed without write barriers.', 'dbvc')),
        ];
    }

    /**
     * @param array<string, mixed> $dry_run_execution
     * @param string               $approval_token
     * @return array<string, mixed>|WP_Error
     */
    private function dbvc_cc_get_preflight_status_from_dry_run_execution(array $dry_run_execution, $approval_token = '')
    {
        $domain = isset($dry_run_execution['domain']) ? sanitize_text_field((string) $dry_run_execution['domain']) : '';
        $path = isset($dry_run_execution['path']) ? sanitize_text_field((string) $dry_run_execution['path']) : '';
        $source_url = isset($dry_run_execution['source_url']) ? esc_url_raw((string) $dry_run_execution['source_url']) : '';
        $dry_run_status = isset($dry_run_execution['status']) ? sanitize_key((string) $dry_run_execution['status']) : 'blocked';
        $write_preparation = $this->dbvc_cc_build_write_preparation($dry_run_execution);
        $write_barriers = isset($write_preparation['write_barriers']) && is_array($write_preparation['write_barriers'])
            ? array_values($write_preparation['write_barriers'])
            : [];
        $write_barrier_count = count(array_filter($write_barriers, static function ($barrier) {
            return is_array($barrier) && ! empty($barrier['blocking']);
        }));
        $deferred_media_count = isset($write_preparation['operation_counts']['deferred_media_writes'])
            ? absint($write_preparation['operation_counts']['deferred_media_writes'])
            : 0;
        $deferred_media_reasons = isset($write_preparation['deferred_media_reasons']) && is_array($write_preparation['deferred_media_reasons'])
            ? array_values($write_preparation['deferred_media_reasons'])
            : [];

        $approval_required = true;
        $approval_eligible = $dry_run_status === 'completed' && $write_barrier_count === 0;
        $approval_state = [
            'status' => 'missing',
            'approval_valid' => false,
            'approval' => $this->dbvc_cc_get_empty_preflight_approval(),
            'guard_failure' => null,
        ];
        $guard_failures = [];
        $status = 'blocked';

        if ($approval_eligible) {
            $approval_state = $this->dbvc_cc_validate_preflight_approval_token(
                $approval_token,
                $this->dbvc_cc_build_preflight_approval_context($dry_run_execution, $write_preparation)
            );
            if (isset($approval_state['guard_failure']) && is_array($approval_state['guard_failure'])) {
                $guard_failures[] = $approval_state['guard_failure'];
            }
            $status = isset($approval_state['status']) ? sanitize_key((string) $approval_state['status']) : 'missing';
        } elseif ($dry_run_status !== 'completed') {
            $guard_failures[] = $this->dbvc_cc_build_guard_failure(
                'dry_run_not_completed',
                __('Dry-run execution must be completed before preflight approval is eligible.', 'dbvc'),
                true
            );
        }

        $approval_valid = $approval_eligible && ! empty($approval_state['approval_valid']);
        $summary = $this->dbvc_cc_build_preflight_approval_summary($dry_run_execution, $write_preparation);

        return [
            'executor_schema_version' => self::DBVC_CC_EXECUTOR_SCHEMA_VERSION,
            'generated_at' => current_time('c'),
            'status' => $status,
            'domain' => $domain,
            'path' => $path,
            'source_url' => $source_url,
            'plan_id' => isset($dry_run_execution['plan_id']) ? sanitize_key((string) $dry_run_execution['plan_id']) : '',
            'dry_run_execution_id' => isset($dry_run_execution['execution_id']) ? sanitize_key((string) $dry_run_execution['execution_id']) : '',
            'approval_required' => $approval_required,
            'approval_eligible' => $approval_eligible,
            'approval_valid' => $approval_valid,
            'execute_ready' => $approval_eligible && $approval_valid,
            'approval' => isset($approval_state['approval']) && is_array($approval_state['approval'])
                ? $approval_state['approval']
                : $this->dbvc_cc_get_empty_preflight_approval(),
            'summary' => $summary,
            'guard_failures' => $guard_failures,
            'write_barriers' => $write_barriers,
            'deferred_media_count' => $deferred_media_count,
            'deferred_media_reasons' => $deferred_media_reasons,
            'operation_counts' => [
                'guard_failure_count' => count($guard_failures),
                'write_barrier_count' => $write_barrier_count,
                'deferred_media_count' => $deferred_media_count,
            ],
            'message' => $approval_valid
                ? __('Preflight approval remains valid for the current dry-run fingerprint.', 'dbvc')
                : ($approval_eligible
                    ? __('Preflight approval is missing, stale, or expired for the current dry-run fingerprint.', 'dbvc')
                    : __('Preflight approval is blocked until the dry-run is completed without write barriers.', 'dbvc')),
        ];
    }

    /**
     * @param int|string $run_id_or_uuid
     * @return array<string, mixed>|WP_Error
     */
    public function get_run_details($run_id_or_uuid)
    {
        $run = DBVC_CC_Import_Run_Store::get_instance()->get_run($run_id_or_uuid);
        if (! is_array($run) || empty($run['id'])) {
            return new WP_Error(
                'dbvc_cc_import_run_not_found',
                __('Import run was not found.', 'dbvc'),
                ['status' => 404]
            );
        }

        return [
            'generated_at' => current_time('c'),
            'run' => $run,
            'actions' => DBVC_CC_Import_Run_Store::get_instance()->get_run_actions(absint($run['id'])),
        ];
    }

    /**
     * @param string $domain
     * @param string $path
     * @param int    $limit
     * @return array<string, mixed>
     */
    public function list_runs($domain = '', $path = '', $limit = 20)
    {
        return [
            'generated_at' => current_time('c'),
            'runs' => DBVC_CC_Import_Run_Store::get_instance()->list_runs([
                'domain' => sanitize_text_field((string) $domain),
                'path' => sanitize_text_field((string) $path),
                'limit' => max(1, min(100, absint($limit))),
            ]),
        ];
    }

    /**
     * @param int|string $run_id_or_uuid
     * @return array<string, mixed>|WP_Error
     */
    public function rollback_run($run_id_or_uuid)
    {
        $store = DBVC_CC_Import_Run_Store::get_instance();
        $run = $store->get_run($run_id_or_uuid);
        if (! is_array($run) || empty($run['id'])) {
            return new WP_Error(
                'dbvc_cc_import_run_not_found',
                __('Import run was not found.', 'dbvc'),
                ['status' => 404]
            );
        }

        if (isset($run['rollback_status']) && (string) $run['rollback_status'] === 'completed') {
            return [
                'generated_at' => current_time('c'),
                'status' => 'completed',
                'run' => $run,
                'restored_count' => 0,
                'failed_count' => 0,
                'failures' => [],
                'actions' => $store->get_run_actions(absint($run['id'])),
            ];
        }

        $actions = $store->get_run_actions(absint($run['id']));
        if (empty($actions)) {
            return new WP_Error(
                'dbvc_cc_import_run_no_actions',
                __('Import run has no journaled actions to roll back.', 'dbvc'),
                ['status' => 400]
            );
        }

        $store->update_run(absint($run['id']), [
            'status' => 'rolling_back',
            'rollback_status' => 'running',
            'rollback_started_at' => current_time('mysql', true),
            'rollback_finished_at' => null,
        ]);

        $restored_count = 0;
        $failed_count = 0;
        $failures = [];

        foreach (array_reverse($actions) as $action) {
            if (! is_array($action)) {
                continue;
            }
            if (isset($action['execution_status']) && (string) $action['execution_status'] !== 'completed') {
                continue;
            }
            if (isset($action['rollback_status']) && (string) $action['rollback_status'] === 'completed') {
                continue;
            }

            $restore_result = $this->dbvc_cc_restore_run_action($action);
            if (is_wp_error($restore_result)) {
                $failed_count++;
                $failures[] = [
                    'action_id' => isset($action['id']) ? absint($action['id']) : 0,
                    'message' => $restore_result->get_error_message(),
                ];
                $store->update_action(
                    isset($action['id']) ? absint($action['id']) : 0,
                    [
                        'rollback_status' => 'failed',
                        'rollback_error' => $restore_result->get_error_message(),
                    ]
                );
                continue;
            }

            $restored_count++;
            $store->update_action(
                isset($action['id']) ? absint($action['id']) : 0,
                [
                    'rollback_status' => 'completed',
                    'rollback_error' => '',
                ]
            );
        }

        $rollback_status = $failed_count > 0 ? 'failed' : 'completed';
        $status = $failed_count > 0 ? 'rollback_failed' : 'rolled_back';
        $store->update_run(absint($run['id']), [
            'status' => $status,
            'rollback_status' => $rollback_status,
            'rollback_finished_at' => current_time('mysql', true),
            'error_summary' => $failed_count > 0 && ! empty($failures)
                ? sanitize_text_field((string) $failures[0]['message'])
                : '',
        ]);

        $updated_run = $store->get_run(absint($run['id']));

        return [
            'generated_at' => current_time('c'),
            'status' => $rollback_status,
            'run' => $updated_run,
            'restored_count' => $restored_count,
            'failed_count' => $failed_count,
            'failures' => $failures,
            'actions' => $store->get_run_actions(absint($run['id'])),
        ];
    }

    /**
     * Executes the guarded Phase 4 write path.
     * Current slice activates entity + field writes; media writes remain deferred.
     *
     * @param string $domain
     * @param string $path
     * @param bool   $build_if_missing
     * @param bool   $confirm_execute
     * @param string $approval_token
     * @return array<string, mixed>|WP_Error
     */
    public function execute_write_skeleton($domain, $path, $build_if_missing = true, $confirm_execute = false, $approval_token = '')
    {
        $dry_run_execution = $this->execute_dry_run($domain, $path, $build_if_missing);
        if (is_wp_error($dry_run_execution)) {
            return $dry_run_execution;
        }

        $plan_id = isset($dry_run_execution['plan_id']) ? sanitize_key((string) $dry_run_execution['plan_id']) : '';
        $domain = isset($dry_run_execution['domain']) ? sanitize_text_field((string) $dry_run_execution['domain']) : '';
        $path = isset($dry_run_execution['path']) ? sanitize_text_field((string) $dry_run_execution['path']) : '';
        $source_url = isset($dry_run_execution['source_url']) ? esc_url_raw((string) $dry_run_execution['source_url']) : '';
        $dry_run_status = isset($dry_run_execution['status']) ? sanitize_key((string) $dry_run_execution['status']) : 'blocked';

        $guard_failures = [];

        if (! DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE)) {
            $guard_failures[] = $this->dbvc_cc_build_guard_failure(
                'write_feature_disabled',
                __('Import execute feature flag is disabled.', 'dbvc'),
                true
            );
        }

        if (get_option(DBVC_CC_Contracts::IMPORT_POLICY_DRY_RUN_REQUIRED, '1') !== '1') {
            $guard_failures[] = $this->dbvc_cc_build_guard_failure(
                'dry_run_policy_disabled',
                __('Dry-run policy must remain enabled before write execution.', 'dbvc'),
                true
            );
        }

        if (get_option(DBVC_CC_Contracts::IMPORT_POLICY_IDEMPOTENT_UPSERT, '1') !== '1') {
            $guard_failures[] = $this->dbvc_cc_build_guard_failure(
                'idempotent_upsert_policy_disabled',
                __('Idempotent upsert policy must remain enabled before write execution.', 'dbvc'),
                true
            );
        }

        if (! rest_sanitize_boolean($confirm_execute)) {
            $guard_failures[] = $this->dbvc_cc_build_guard_failure(
                'execute_confirmation_required',
                __('Explicit execute confirmation is required for write path activation.', 'dbvc'),
                false
            );
        }

        if ($dry_run_status !== 'completed') {
            $guard_failures[] = $this->dbvc_cc_build_guard_failure(
                'dry_run_not_completed',
                __('Dry-run execution must be completed before write execution is eligible.', 'dbvc'),
                true
            );
        }

        $dbvc_cc_dry_run_plan = isset($dry_run_execution['dry_run_plan']) && is_array($dry_run_execution['dry_run_plan'])
            ? $dry_run_execution['dry_run_plan']
            : [];
        $dbvc_cc_phase4_context = $this->dbvc_cc_build_phase4_context($dbvc_cc_dry_run_plan);
        $write_preparation = $this->dbvc_cc_get_empty_write_preparation();
        if (empty($guard_failures)) {
            $write_preparation = $this->dbvc_cc_build_write_preparation($dry_run_execution);
        }

        $write_barriers = isset($write_preparation['write_barriers']) && is_array($write_preparation['write_barriers'])
            ? array_values($write_preparation['write_barriers'])
            : [];
        $write_barrier_count = 0;
        foreach ($write_barriers as $dbvc_cc_barrier) {
            if (is_array($dbvc_cc_barrier) && ! empty($dbvc_cc_barrier['blocking'])) {
                $write_barrier_count++;
            }
        }
        $deferred_media_count = isset($write_preparation['operation_counts']['deferred_media_writes'])
            ? absint($write_preparation['operation_counts']['deferred_media_writes'])
            : 0;
        $deferred_media_reasons = isset($write_preparation['deferred_media_reasons']) && is_array($write_preparation['deferred_media_reasons'])
            ? array_values($write_preparation['deferred_media_reasons'])
            : [];

        $entity_write_execution = $this->dbvc_cc_get_empty_entity_write_execution();
        $field_write_execution = $this->dbvc_cc_get_empty_field_write_execution();
        $preflight_approval = [
            'status' => 'missing',
            'approval_valid' => false,
            'approval' => $this->dbvc_cc_get_empty_preflight_approval(),
            'guard_failure' => null,
        ];
        $import_run = null;
        $rollback_available = false;
        $rollback_status = 'not_started';
        $auto_rollback = $this->dbvc_cc_get_empty_auto_rollback_result();
        $execution_failures = [];
        $executed_stages = [];
        $deferred_stages = [];
        $media_write_execution = $this->dbvc_cc_get_empty_media_write_execution();

        $write_performed = false;
        if (empty($guard_failures) && $write_barrier_count === 0) {
            $preflight_approval = $this->dbvc_cc_validate_preflight_approval_token(
                $approval_token,
                $this->dbvc_cc_build_preflight_approval_context($dry_run_execution, $write_preparation)
            );
            if (isset($preflight_approval['guard_failure']) && is_array($preflight_approval['guard_failure'])) {
                $guard_failures[] = $preflight_approval['guard_failure'];
            }
        }

        $dbvc_cc_execution_runtime = [
            'run_id' => 0,
            'run_uuid' => '',
            'next_action_order' => 1,
        ];
        if (empty($guard_failures) && $write_barrier_count === 0) {
            $import_run = $this->dbvc_cc_open_import_run(
                $dry_run_execution,
                $write_preparation,
                $preflight_approval,
                $approval_token
            );
            if (is_wp_error($import_run)) {
                $guard_failures[] = $this->dbvc_cc_build_guard_failure(
                    'rollback_journal_unavailable',
                    $import_run->get_error_message(),
                    true
                );
            } elseif (is_array($import_run)) {
                $dbvc_cc_execution_runtime['run_id'] = isset($import_run['id']) ? absint($import_run['id']) : 0;
                $dbvc_cc_execution_runtime['run_uuid'] = isset($import_run['run_uuid']) ? sanitize_text_field((string) $import_run['run_uuid']) : '';
            }
        }

        if (empty($guard_failures) && $write_barrier_count === 0) {
            $entity_write_execution = $this->dbvc_cc_execute_prepared_entity_writes(
                isset($write_preparation['entity_writes']) && is_array($write_preparation['entity_writes'])
                    ? array_values($write_preparation['entity_writes'])
                    : [],
                $dbvc_cc_execution_runtime
            );
            $execution_failures = isset($entity_write_execution['failures']) && is_array($entity_write_execution['failures'])
                ? array_values($entity_write_execution['failures'])
                : [];
            $write_performed = isset($entity_write_execution['counts']['completed'])
                ? absint($entity_write_execution['counts']['completed']) > 0
                : false;
            if ($write_performed) {
                $executed_stages[] = 'entity';
            }
            if (empty($execution_failures)) {
                $field_write_execution = $this->dbvc_cc_execute_prepared_field_writes(
                    isset($write_preparation['field_writes']) && is_array($write_preparation['field_writes'])
                        ? array_values($write_preparation['field_writes'])
                        : [],
                    $entity_write_execution,
                    $dbvc_cc_execution_runtime
                );
                $field_execution_failures = isset($field_write_execution['failures']) && is_array($field_write_execution['failures'])
                    ? array_values($field_write_execution['failures'])
                    : [];
                if (! empty($field_execution_failures)) {
                    $execution_failures = array_values(array_merge($execution_failures, $field_execution_failures));
                }
                if (isset($field_write_execution['counts']['completed']) && absint($field_write_execution['counts']['completed']) > 0) {
                    $executed_stages[] = 'field';
                    $write_performed = true;
                }
            } elseif (isset($write_preparation['operation_counts']['field_writes']) && absint($write_preparation['operation_counts']['field_writes']) > 0) {
                $deferred_stages[] = 'field';
            }

            if (empty($execution_failures)) {
                $media_write_execution = $this->dbvc_cc_execute_prepared_media_writes(
                    isset($write_preparation['media_writes']) && is_array($write_preparation['media_writes'])
                        ? array_values($write_preparation['media_writes'])
                        : [],
                    $entity_write_execution,
                    $dbvc_cc_execution_runtime
                );
                $media_execution_failures = isset($media_write_execution['failures']) && is_array($media_write_execution['failures'])
                    ? array_values($media_write_execution['failures'])
                    : [];
                if (! empty($media_execution_failures)) {
                    $execution_failures = array_values(array_merge($execution_failures, $media_execution_failures));
                }
                if (isset($media_write_execution['counts']['completed']) && absint($media_write_execution['counts']['completed']) > 0) {
                    $executed_stages[] = 'media';
                    $write_performed = true;
                }
                if (isset($media_write_execution['counts']['deferred']) && absint($media_write_execution['counts']['deferred']) > 0) {
                    $deferred_stages[] = 'media';
                }
            }
        } elseif (
            isset($write_preparation['operation_counts']['field_writes']) && absint($write_preparation['operation_counts']['field_writes']) > 0
            || isset($write_preparation['operation_counts']['media_writes']) && absint($write_preparation['operation_counts']['media_writes']) > 0
        ) {
            if (isset($write_preparation['operation_counts']['field_writes']) && absint($write_preparation['operation_counts']['field_writes']) > 0) {
                $deferred_stages[] = 'field';
            }
            if ($deferred_media_count > 0) {
                $deferred_stages[] = 'media';
            }
        } elseif (isset($write_preparation['operation_counts']['field_writes']) && absint($write_preparation['operation_counts']['field_writes']) > 0) {
            $deferred_stages[] = 'field';
        }

        $deferred_stages = array_values(array_unique($deferred_stages));

        if (! empty($guard_failures) || $write_barrier_count > 0) {
            $status = 'blocked';
        } elseif (! empty($execution_failures)) {
            $status = 'completed_with_failures';
        } elseif (! empty($deferred_stages)) {
            $status = 'completed_partial';
        } else {
            $status = 'completed';
        }

        if (is_array($import_run) && ! empty($dbvc_cc_execution_runtime['run_id'])) {
            $rollback_available = $this->dbvc_cc_finalize_import_run(
                absint($dbvc_cc_execution_runtime['run_id']),
                $status,
                $entity_write_execution,
                $field_write_execution,
                $media_write_execution,
                $execution_failures,
                $dbvc_cc_execution_runtime
            );
            $import_run = DBVC_CC_Import_Run_Store::get_instance()->get_run(absint($dbvc_cc_execution_runtime['run_id']));
            if (is_array($import_run) && ! empty($import_run['rollback_status'])) {
                $rollback_status = sanitize_key((string) $import_run['rollback_status']);
            }
        }

        if (
            empty($guard_failures)
            && $write_barrier_count === 0
            && ! empty($execution_failures)
            && $write_performed
            && is_array($import_run)
            && ! empty($dbvc_cc_execution_runtime['run_id'])
            && $rollback_available
        ) {
            $auto_rollback = $this->dbvc_cc_attempt_automatic_rollback(absint($dbvc_cc_execution_runtime['run_id']));
            if (! empty($auto_rollback['attempted']) && isset($auto_rollback['run']) && is_array($auto_rollback['run'])) {
                $import_run = $auto_rollback['run'];
                $rollback_status = isset($import_run['rollback_status'])
                    ? sanitize_key((string) $import_run['rollback_status'])
                    : $rollback_status;
                $rollback_available = false;

                if (isset($auto_rollback['status']) && (string) $auto_rollback['status'] === 'completed') {
                    $status = 'rolled_back_after_failure';
                } elseif (isset($auto_rollback['status']) && (string) $auto_rollback['status'] === 'failed') {
                    $status = 'rollback_failed_after_failure';
                }
            }
        }

        $skeleton_id = 'dbvc_cc_execw_' . substr(hash('sha256', wp_json_encode([
            'domain' => $domain,
            'path' => $path,
            'plan_id' => $plan_id,
            'dry_run_execution_id' => isset($dry_run_execution['execution_id']) ? (string) $dry_run_execution['execution_id'] : '',
            'confirm_execute' => rest_sanitize_boolean($confirm_execute) ? '1' : '0',
            'write_plan_id' => isset($write_preparation['write_plan_id']) ? (string) $write_preparation['write_plan_id'] : '',
        ])), 0, 16);

        if ($status === 'blocked') {
            $message = ! empty($guard_failures)
                ? __('Write execution is blocked by guardrails.', 'dbvc')
                : __('Write execution is blocked by write-plan barriers.', 'dbvc');
        } elseif (! empty($auto_rollback['attempted']) && isset($auto_rollback['status']) && (string) $auto_rollback['status'] === 'completed') {
            $message = __('Write execution encountered failures and was automatically rolled back.', 'dbvc');
        } elseif (! empty($auto_rollback['attempted']) && isset($auto_rollback['status']) && (string) $auto_rollback['status'] === 'failed') {
            $message = __('Write execution encountered failures and automatic rollback also failed. Manual review is required.', 'dbvc');
        } elseif (! empty($execution_failures)) {
            $message = __('Write execution completed with failures. Deferred stages remain disabled in this phase.', 'dbvc');
        } elseif (! empty($deferred_stages)) {
            $message = $deferred_media_count > 0
                ? __('Entity and field execution completed. Some media writes were deferred due to policy, unsupported target shape, or missing source state.', 'dbvc')
                : __('Entity and field execution completed. Some write stages remain deferred.', 'dbvc');
        } else {
            $message = __('Write execution completed.', 'dbvc');
        }

        $response = [
            'executor_schema_version' => self::DBVC_CC_EXECUTOR_SCHEMA_VERSION,
            'generated_at' => current_time('c'),
            'execution_id' => $skeleton_id,
            'status' => $status,
            'dry_run' => false,
            'write_performed' => $write_performed,
            'domain' => $domain,
            'path' => $path,
            'source_url' => $source_url,
            'plan_id' => $plan_id,
            'policy' => isset($dry_run_execution['policy']) && is_array($dry_run_execution['policy']) ? $dry_run_execution['policy'] : [],
            'dry_run_execution_id' => isset($dry_run_execution['execution_id']) ? sanitize_key((string) $dry_run_execution['execution_id']) : '',
            'dry_run_status' => $dry_run_status,
            'run_id' => is_array($import_run) && isset($import_run['id']) ? absint($import_run['id']) : 0,
            'run_uuid' => is_array($import_run) && isset($import_run['run_uuid']) ? sanitize_text_field((string) $import_run['run_uuid']) : '',
            'rollback_available' => $rollback_available,
            'rollback_status' => $rollback_status,
            'phase4_context' => $dbvc_cc_phase4_context,
            'guard_failures' => $guard_failures,
            'preflight_approval' => $preflight_approval,
            'execution_failures' => $execution_failures,
            'auto_rollback' => $auto_rollback,
            'executed_stages' => $executed_stages,
            'deferred_stages' => $deferred_stages,
            'write_preparation' => $write_preparation,
            'entity_write_execution' => $entity_write_execution,
            'field_write_execution' => $field_write_execution,
            'media_write_execution' => $media_write_execution,
            'write_barriers' => $write_barriers,
            'deferred_media_count' => $deferred_media_count,
            'deferred_media_reasons' => $deferred_media_reasons,
            'message' => $message,
            'operation_counts' => [
                'simulated_from_dry_run' => isset($dry_run_execution['operation_counts']['total_simulated'])
                    ? absint($dry_run_execution['operation_counts']['total_simulated'])
                    : 0,
                'guard_failure_count' => count($guard_failures),
                'preflight_approval_valid' => ! empty($preflight_approval['approval_valid']) ? 1 : 0,
                'rollback_available' => $rollback_available ? 1 : 0,
                'auto_rollback_attempted' => ! empty($auto_rollback['attempted']) ? 1 : 0,
                'auto_rollback_restored_actions' => isset($auto_rollback['restored_count'])
                    ? absint($auto_rollback['restored_count'])
                    : 0,
                'auto_rollback_failed_actions' => isset($auto_rollback['failed_count'])
                    ? absint($auto_rollback['failed_count'])
                    : 0,
                'prepared_entity_writes' => isset($write_preparation['operation_counts']['entity_writes'])
                    ? absint($write_preparation['operation_counts']['entity_writes'])
                    : 0,
                'prepared_field_writes' => isset($write_preparation['operation_counts']['field_writes'])
                    ? absint($write_preparation['operation_counts']['field_writes'])
                    : 0,
                'prepared_media_writes' => isset($write_preparation['operation_counts']['media_writes'])
                    ? absint($write_preparation['operation_counts']['media_writes'])
                    : 0,
                'write_barrier_count' => $write_barrier_count,
                'deferred_media_count' => $deferred_media_count,
                'executed_entity_writes' => isset($entity_write_execution['counts']['completed'])
                    ? absint($entity_write_execution['counts']['completed'])
                    : 0,
                'executed_entity_creates' => isset($entity_write_execution['counts']['created'])
                    ? absint($entity_write_execution['counts']['created'])
                    : 0,
                'executed_entity_updates' => isset($entity_write_execution['counts']['updated'])
                    ? absint($entity_write_execution['counts']['updated'])
                    : 0,
                'failed_entity_writes' => isset($entity_write_execution['counts']['failed'])
                    ? absint($entity_write_execution['counts']['failed'])
                    : 0,
                'executed_field_writes' => isset($field_write_execution['counts']['completed'])
                    ? absint($field_write_execution['counts']['completed'])
                    : 0,
                'failed_field_writes' => isset($field_write_execution['counts']['failed'])
                    ? absint($field_write_execution['counts']['failed'])
                    : 0,
                'executed_media_writes' => isset($media_write_execution['counts']['completed'])
                    ? absint($media_write_execution['counts']['completed'])
                    : 0,
                'created_media_attachments' => isset($media_write_execution['counts']['created'])
                    ? absint($media_write_execution['counts']['created'])
                    : 0,
                'reused_media_attachments' => isset($media_write_execution['counts']['reused'])
                    ? absint($media_write_execution['counts']['reused'])
                    : 0,
                'failed_media_writes' => isset($media_write_execution['counts']['failed'])
                    ? absint($media_write_execution['counts']['failed'])
                    : 0,
                'deferred_media_execution_writes' => isset($media_write_execution['counts']['deferred'])
                    ? absint($media_write_execution['counts']['deferred'])
                    : 0,
                'deferred_field_writes' => isset($write_preparation['operation_counts']['field_writes'])
                    ? max(
                        0,
                        absint($write_preparation['operation_counts']['field_writes'])
                        - (isset($field_write_execution['counts']['completed']) ? absint($field_write_execution['counts']['completed']) : 0)
                    )
                    : 0,
                'deferred_media_writes' => $deferred_media_count,
            ],
            'trace' => isset($dry_run_execution['trace']) && is_array($dry_run_execution['trace']) ? $dry_run_execution['trace'] : [],
        ];

        $this->dbvc_cc_log_write_skeleton_event($domain, $path, $source_url, $status, $guard_failures, $dbvc_cc_phase4_context, $write_barriers);

        return $response;
    }

    /**
     * @param array<string, mixed> $dry_run_execution
     * @param bool                 $confirm_execute
     * @param string               $approval_token
     * @return array<string, mixed>|WP_Error
     */
    private function dbvc_cc_execute_write_skeleton_from_dry_run_execution(array $dry_run_execution, $confirm_execute = false, $approval_token = '')
    {
        $plan_id = isset($dry_run_execution['plan_id']) ? sanitize_key((string) $dry_run_execution['plan_id']) : '';
        $domain = isset($dry_run_execution['domain']) ? sanitize_text_field((string) $dry_run_execution['domain']) : '';
        $path = isset($dry_run_execution['path']) ? sanitize_text_field((string) $dry_run_execution['path']) : '';
        $source_url = isset($dry_run_execution['source_url']) ? esc_url_raw((string) $dry_run_execution['source_url']) : '';
        $dry_run_status = isset($dry_run_execution['status']) ? sanitize_key((string) $dry_run_execution['status']) : 'blocked';

        $guard_failures = [];

        if (! DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_IMPORT_EXECUTE)) {
            $guard_failures[] = $this->dbvc_cc_build_guard_failure(
                'write_feature_disabled',
                __('Import execute feature flag is disabled.', 'dbvc'),
                true
            );
        }

        if (get_option(DBVC_CC_Contracts::IMPORT_POLICY_DRY_RUN_REQUIRED, '1') !== '1') {
            $guard_failures[] = $this->dbvc_cc_build_guard_failure(
                'dry_run_policy_disabled',
                __('Dry-run policy must remain enabled before write execution.', 'dbvc'),
                true
            );
        }

        if (get_option(DBVC_CC_Contracts::IMPORT_POLICY_IDEMPOTENT_UPSERT, '1') !== '1') {
            $guard_failures[] = $this->dbvc_cc_build_guard_failure(
                'idempotent_upsert_policy_disabled',
                __('Idempotent upsert policy must remain enabled before write execution.', 'dbvc'),
                true
            );
        }

        if (! rest_sanitize_boolean($confirm_execute)) {
            $guard_failures[] = $this->dbvc_cc_build_guard_failure(
                'execute_confirmation_required',
                __('Explicit execute confirmation is required for write path activation.', 'dbvc'),
                false
            );
        }

        if ($dry_run_status !== 'completed') {
            $guard_failures[] = $this->dbvc_cc_build_guard_failure(
                'dry_run_not_completed',
                __('Dry-run execution must be completed before write execution is eligible.', 'dbvc'),
                true
            );
        }

        $dbvc_cc_dry_run_plan = isset($dry_run_execution['dry_run_plan']) && is_array($dry_run_execution['dry_run_plan'])
            ? $dry_run_execution['dry_run_plan']
            : [];
        $dbvc_cc_phase4_context = $this->dbvc_cc_build_phase4_context($dbvc_cc_dry_run_plan);
        $write_preparation = $this->dbvc_cc_get_empty_write_preparation();
        if (empty($guard_failures)) {
            $write_preparation = $this->dbvc_cc_build_write_preparation($dry_run_execution);
        }

        $write_barriers = isset($write_preparation['write_barriers']) && is_array($write_preparation['write_barriers'])
            ? array_values($write_preparation['write_barriers'])
            : [];
        $write_barrier_count = 0;
        foreach ($write_barriers as $dbvc_cc_barrier) {
            if (is_array($dbvc_cc_barrier) && ! empty($dbvc_cc_barrier['blocking'])) {
                $write_barrier_count++;
            }
        }
        $deferred_media_count = isset($write_preparation['operation_counts']['deferred_media_writes'])
            ? absint($write_preparation['operation_counts']['deferred_media_writes'])
            : 0;
        $deferred_media_reasons = isset($write_preparation['deferred_media_reasons']) && is_array($write_preparation['deferred_media_reasons'])
            ? array_values($write_preparation['deferred_media_reasons'])
            : [];

        $entity_write_execution = $this->dbvc_cc_get_empty_entity_write_execution();
        $field_write_execution = $this->dbvc_cc_get_empty_field_write_execution();
        $preflight_approval = [
            'status' => 'missing',
            'approval_valid' => false,
            'approval' => $this->dbvc_cc_get_empty_preflight_approval(),
            'guard_failure' => null,
        ];
        $import_run = null;
        $rollback_available = false;
        $rollback_status = 'not_started';
        $auto_rollback = $this->dbvc_cc_get_empty_auto_rollback_result();
        $execution_failures = [];
        $executed_stages = [];
        $deferred_stages = [];
        $media_write_execution = $this->dbvc_cc_get_empty_media_write_execution();

        $write_performed = false;
        if (empty($guard_failures) && $write_barrier_count === 0) {
            $preflight_approval = $this->dbvc_cc_validate_preflight_approval_token(
                $approval_token,
                $this->dbvc_cc_build_preflight_approval_context($dry_run_execution, $write_preparation)
            );
            if (isset($preflight_approval['guard_failure']) && is_array($preflight_approval['guard_failure'])) {
                $guard_failures[] = $preflight_approval['guard_failure'];
            }
        }

        $dbvc_cc_execution_runtime = [
            'run_id' => 0,
            'run_uuid' => '',
            'next_action_order' => 1,
        ];
        if (empty($guard_failures) && $write_barrier_count === 0) {
            $import_run = $this->dbvc_cc_open_import_run(
                $dry_run_execution,
                $write_preparation,
                $preflight_approval,
                $approval_token
            );
            if (is_wp_error($import_run)) {
                $guard_failures[] = $this->dbvc_cc_build_guard_failure(
                    'rollback_journal_unavailable',
                    $import_run->get_error_message(),
                    true
                );
            } elseif (is_array($import_run)) {
                $dbvc_cc_execution_runtime['run_id'] = isset($import_run['id']) ? absint($import_run['id']) : 0;
                $dbvc_cc_execution_runtime['run_uuid'] = isset($import_run['run_uuid']) ? sanitize_text_field((string) $import_run['run_uuid']) : '';
            }
        }

        if (empty($guard_failures) && $write_barrier_count === 0) {
            $entity_write_execution = $this->dbvc_cc_execute_prepared_entity_writes(
                isset($write_preparation['entity_writes']) && is_array($write_preparation['entity_writes'])
                    ? array_values($write_preparation['entity_writes'])
                    : [],
                $dbvc_cc_execution_runtime
            );
            $execution_failures = isset($entity_write_execution['failures']) && is_array($entity_write_execution['failures'])
                ? array_values($entity_write_execution['failures'])
                : [];
            $write_performed = isset($entity_write_execution['counts']['completed'])
                ? absint($entity_write_execution['counts']['completed']) > 0
                : false;
            if ($write_performed) {
                $executed_stages[] = 'entity';
            }
            if (empty($execution_failures)) {
                $field_write_execution = $this->dbvc_cc_execute_prepared_field_writes(
                    isset($write_preparation['field_writes']) && is_array($write_preparation['field_writes'])
                        ? array_values($write_preparation['field_writes'])
                        : [],
                    $entity_write_execution,
                    $dbvc_cc_execution_runtime
                );
                $field_execution_failures = isset($field_write_execution['failures']) && is_array($field_write_execution['failures'])
                    ? array_values($field_write_execution['failures'])
                    : [];
                if (! empty($field_execution_failures)) {
                    $execution_failures = array_values(array_merge($execution_failures, $field_execution_failures));
                }
                if (isset($field_write_execution['counts']['completed']) && absint($field_write_execution['counts']['completed']) > 0) {
                    $executed_stages[] = 'field';
                    $write_performed = true;
                }
            } elseif (isset($write_preparation['operation_counts']['field_writes']) && absint($write_preparation['operation_counts']['field_writes']) > 0) {
                $deferred_stages[] = 'field';
            }

            if (empty($execution_failures)) {
                $media_write_execution = $this->dbvc_cc_execute_prepared_media_writes(
                    isset($write_preparation['media_writes']) && is_array($write_preparation['media_writes'])
                        ? array_values($write_preparation['media_writes'])
                        : [],
                    $entity_write_execution,
                    $dbvc_cc_execution_runtime
                );
                $media_execution_failures = isset($media_write_execution['failures']) && is_array($media_write_execution['failures'])
                    ? array_values($media_write_execution['failures'])
                    : [];
                if (! empty($media_execution_failures)) {
                    $execution_failures = array_values(array_merge($execution_failures, $media_execution_failures));
                }
                if (isset($media_write_execution['counts']['completed']) && absint($media_write_execution['counts']['completed']) > 0) {
                    $executed_stages[] = 'media';
                    $write_performed = true;
                }
                if (isset($media_write_execution['counts']['deferred']) && absint($media_write_execution['counts']['deferred']) > 0) {
                    $deferred_stages[] = 'media';
                }
            }
        } elseif (
            isset($write_preparation['operation_counts']['field_writes']) && absint($write_preparation['operation_counts']['field_writes']) > 0
            || isset($write_preparation['operation_counts']['media_writes']) && absint($write_preparation['operation_counts']['media_writes']) > 0
        ) {
            if (isset($write_preparation['operation_counts']['field_writes']) && absint($write_preparation['operation_counts']['field_writes']) > 0) {
                $deferred_stages[] = 'field';
            }
            if ($deferred_media_count > 0) {
                $deferred_stages[] = 'media';
            }
        } elseif (isset($write_preparation['operation_counts']['field_writes']) && absint($write_preparation['operation_counts']['field_writes']) > 0) {
            $deferred_stages[] = 'field';
        }

        $deferred_stages = array_values(array_unique($deferred_stages));

        if (! empty($guard_failures) || $write_barrier_count > 0) {
            $status = 'blocked';
        } elseif (! empty($execution_failures)) {
            $status = 'completed_with_failures';
        } elseif (! empty($deferred_stages)) {
            $status = 'completed_partial';
        } else {
            $status = 'completed';
        }

        if (is_array($import_run) && ! empty($dbvc_cc_execution_runtime['run_id'])) {
            $rollback_available = $this->dbvc_cc_finalize_import_run(
                absint($dbvc_cc_execution_runtime['run_id']),
                $status,
                $entity_write_execution,
                $field_write_execution,
                $media_write_execution,
                $execution_failures,
                $dbvc_cc_execution_runtime
            );
            $import_run = DBVC_CC_Import_Run_Store::get_instance()->get_run(absint($dbvc_cc_execution_runtime['run_id']));
            if (is_array($import_run) && ! empty($import_run['rollback_status'])) {
                $rollback_status = sanitize_key((string) $import_run['rollback_status']);
            }
        }

        if (
            empty($guard_failures)
            && $write_barrier_count === 0
            && ! empty($execution_failures)
            && $write_performed
            && is_array($import_run)
            && ! empty($dbvc_cc_execution_runtime['run_id'])
            && $rollback_available
        ) {
            $auto_rollback = $this->dbvc_cc_attempt_automatic_rollback(absint($dbvc_cc_execution_runtime['run_id']));
            if (! empty($auto_rollback['attempted']) && isset($auto_rollback['run']) && is_array($auto_rollback['run'])) {
                $import_run = $auto_rollback['run'];
                $rollback_status = isset($import_run['rollback_status'])
                    ? sanitize_key((string) $import_run['rollback_status'])
                    : $rollback_status;
                $rollback_available = false;

                if (isset($auto_rollback['status']) && (string) $auto_rollback['status'] === 'completed') {
                    $status = 'rolled_back_after_failure';
                } elseif (isset($auto_rollback['status']) && (string) $auto_rollback['status'] === 'failed') {
                    $status = 'rollback_failed_after_failure';
                }
            }
        }

        $skeleton_id = 'dbvc_cc_execw_' . substr(hash('sha256', wp_json_encode([
            'domain' => $domain,
            'path' => $path,
            'plan_id' => $plan_id,
            'dry_run_execution_id' => isset($dry_run_execution['execution_id']) ? (string) $dry_run_execution['execution_id'] : '',
            'confirm_execute' => rest_sanitize_boolean($confirm_execute) ? '1' : '0',
            'write_plan_id' => isset($write_preparation['write_plan_id']) ? (string) $write_preparation['write_plan_id'] : '',
        ])), 0, 16);

        if ($status === 'blocked') {
            $message = ! empty($guard_failures)
                ? __('Write execution is blocked by guardrails.', 'dbvc')
                : __('Write execution is blocked by write-plan barriers.', 'dbvc');
        } elseif (! empty($auto_rollback['attempted']) && isset($auto_rollback['status']) && (string) $auto_rollback['status'] === 'completed') {
            $message = __('Write execution encountered failures and was automatically rolled back.', 'dbvc');
        } elseif (! empty($auto_rollback['attempted']) && isset($auto_rollback['status']) && (string) $auto_rollback['status'] === 'failed') {
            $message = __('Write execution encountered failures and automatic rollback also failed. Manual review is required.', 'dbvc');
        } elseif (! empty($execution_failures)) {
            $message = __('Write execution completed with failures. Deferred stages remain disabled in this phase.', 'dbvc');
        } elseif (! empty($deferred_stages)) {
            $message = $deferred_media_count > 0
                ? __('Entity and field execution completed. Some media writes were deferred due to policy, unsupported target shape, or missing source state.', 'dbvc')
                : __('Entity and field execution completed. Some write stages remain deferred.', 'dbvc');
        } else {
            $message = __('Write execution completed.', 'dbvc');
        }

        $response = [
            'executor_schema_version' => self::DBVC_CC_EXECUTOR_SCHEMA_VERSION,
            'generated_at' => current_time('c'),
            'execution_id' => $skeleton_id,
            'status' => $status,
            'dry_run' => false,
            'write_performed' => $write_performed,
            'domain' => $domain,
            'path' => $path,
            'source_url' => $source_url,
            'plan_id' => $plan_id,
            'policy' => isset($dry_run_execution['policy']) && is_array($dry_run_execution['policy']) ? $dry_run_execution['policy'] : [],
            'dry_run_execution_id' => isset($dry_run_execution['execution_id']) ? sanitize_key((string) $dry_run_execution['execution_id']) : '',
            'dry_run_status' => $dry_run_status,
            'run_id' => is_array($import_run) && isset($import_run['id']) ? absint($import_run['id']) : 0,
            'run_uuid' => is_array($import_run) && isset($import_run['run_uuid']) ? sanitize_text_field((string) $import_run['run_uuid']) : '',
            'rollback_available' => $rollback_available,
            'rollback_status' => $rollback_status,
            'phase4_context' => $dbvc_cc_phase4_context,
            'guard_failures' => $guard_failures,
            'preflight_approval' => $preflight_approval,
            'execution_failures' => $execution_failures,
            'auto_rollback' => $auto_rollback,
            'executed_stages' => $executed_stages,
            'deferred_stages' => $deferred_stages,
            'write_preparation' => $write_preparation,
            'entity_write_execution' => $entity_write_execution,
            'field_write_execution' => $field_write_execution,
            'media_write_execution' => $media_write_execution,
            'write_barriers' => $write_barriers,
            'deferred_media_count' => $deferred_media_count,
            'deferred_media_reasons' => $deferred_media_reasons,
            'message' => $message,
            'operation_counts' => [
                'simulated_from_dry_run' => isset($dry_run_execution['operation_counts']['total_simulated'])
                    ? absint($dry_run_execution['operation_counts']['total_simulated'])
                    : 0,
                'guard_failure_count' => count($guard_failures),
                'preflight_approval_valid' => ! empty($preflight_approval['approval_valid']) ? 1 : 0,
                'rollback_available' => $rollback_available ? 1 : 0,
                'auto_rollback_attempted' => ! empty($auto_rollback['attempted']) ? 1 : 0,
                'auto_rollback_restored_actions' => isset($auto_rollback['restored_count'])
                    ? absint($auto_rollback['restored_count'])
                    : 0,
                'auto_rollback_failed_actions' => isset($auto_rollback['failed_count'])
                    ? absint($auto_rollback['failed_count'])
                    : 0,
                'prepared_entity_writes' => isset($write_preparation['operation_counts']['entity_writes'])
                    ? absint($write_preparation['operation_counts']['entity_writes'])
                    : 0,
                'prepared_field_writes' => isset($write_preparation['operation_counts']['field_writes'])
                    ? absint($write_preparation['operation_counts']['field_writes'])
                    : 0,
                'prepared_media_writes' => isset($write_preparation['operation_counts']['media_writes'])
                    ? absint($write_preparation['operation_counts']['media_writes'])
                    : 0,
                'write_barrier_count' => $write_barrier_count,
                'deferred_media_count' => $deferred_media_count,
                'executed_entity_writes' => isset($entity_write_execution['counts']['completed'])
                    ? absint($entity_write_execution['counts']['completed'])
                    : 0,
                'executed_entity_creates' => isset($entity_write_execution['counts']['created'])
                    ? absint($entity_write_execution['counts']['created'])
                    : 0,
                'executed_entity_updates' => isset($entity_write_execution['counts']['updated'])
                    ? absint($entity_write_execution['counts']['updated'])
                    : 0,
                'failed_entity_writes' => isset($entity_write_execution['counts']['failed'])
                    ? absint($entity_write_execution['counts']['failed'])
                    : 0,
                'executed_field_writes' => isset($field_write_execution['counts']['completed'])
                    ? absint($field_write_execution['counts']['completed'])
                    : 0,
                'failed_field_writes' => isset($field_write_execution['counts']['failed'])
                    ? absint($field_write_execution['counts']['failed'])
                    : 0,
                'executed_media_writes' => isset($media_write_execution['counts']['completed'])
                    ? absint($media_write_execution['counts']['completed'])
                    : 0,
                'created_media_attachments' => isset($media_write_execution['counts']['created'])
                    ? absint($media_write_execution['counts']['created'])
                    : 0,
                'reused_media_attachments' => isset($media_write_execution['counts']['reused'])
                    ? absint($media_write_execution['counts']['reused'])
                    : 0,
                'failed_media_writes' => isset($media_write_execution['counts']['failed'])
                    ? absint($media_write_execution['counts']['failed'])
                    : 0,
                'deferred_media_execution_writes' => isset($media_write_execution['counts']['deferred'])
                    ? absint($media_write_execution['counts']['deferred'])
                    : 0,
                'deferred_field_writes' => isset($write_preparation['operation_counts']['field_writes'])
                    ? max(
                        0,
                        absint($write_preparation['operation_counts']['field_writes'])
                        - (isset($field_write_execution['counts']['completed']) ? absint($field_write_execution['counts']['completed']) : 0)
                    )
                    : 0,
                'deferred_media_writes' => $deferred_media_count,
            ],
            'trace' => isset($dry_run_execution['trace']) && is_array($dry_run_execution['trace']) ? $dry_run_execution['trace'] : [],
        ];

        $this->dbvc_cc_log_write_skeleton_event($domain, $path, $source_url, $status, $guard_failures, $dbvc_cc_phase4_context, $write_barriers);

        return $response;
    }

    /**
     * @param int $run_id
     * @return array<string, mixed>
     */
    private function dbvc_cc_attempt_automatic_rollback($run_id)
    {
        $run_id = absint($run_id);
        if ($run_id <= 0) {
            return $this->dbvc_cc_get_empty_auto_rollback_result();
        }

        $result = $this->rollback_run($run_id);
        if (is_wp_error($result)) {
            return [
                'attempted' => true,
                'status' => 'failed',
                'message' => $result->get_error_message(),
                'restored_count' => 0,
                'failed_count' => 1,
                'failures' => [
                    [
                        'action_id' => 0,
                        'message' => $result->get_error_message(),
                    ],
                ],
                'run' => DBVC_CC_Import_Run_Store::get_instance()->get_run($run_id),
            ];
        }

        return [
            'attempted' => true,
            'status' => isset($result['status']) ? sanitize_key((string) $result['status']) : 'failed',
            'message' => isset($result['status']) && (string) $result['status'] === 'completed'
                ? __('Automatic rollback completed.', 'dbvc')
                : __('Automatic rollback completed with failures.', 'dbvc'),
            'restored_count' => isset($result['restored_count']) ? absint($result['restored_count']) : 0,
            'failed_count' => isset($result['failed_count']) ? absint($result['failed_count']) : 0,
            'failures' => isset($result['failures']) && is_array($result['failures']) ? array_values($result['failures']) : [],
            'run' => isset($result['run']) && is_array($result['run']) ? $result['run'] : DBVC_CC_Import_Run_Store::get_instance()->get_run($run_id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dbvc_cc_get_empty_auto_rollback_result()
    {
        return [
            'attempted' => false,
            'status' => 'not_needed',
            'message' => '',
            'restored_count' => 0,
            'failed_count' => 0,
            'failures' => [],
            'run' => null,
        ];
    }

    /**
     * @param string               $stage
     * @param array<string, mixed> $write_operation
     * @param array<string, mixed> $context
     * @return WP_Error|null
     */
    private function dbvc_cc_get_pre_write_failure($stage, array $write_operation = [], array $context = [])
    {
        $result = apply_filters(
            'dbvc_cc_import_executor_pre_write',
            null,
            sanitize_key((string) $stage),
            $write_operation,
            $context
        );

        return is_wp_error($result) ? $result : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function dbvc_cc_get_empty_entity_write_execution()
    {
        return [
            'operations' => [],
            'failures' => [],
            'counts' => [
                'attempted' => 0,
                'completed' => 0,
                'created' => 0,
                'updated' => 0,
                'failed' => 0,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dbvc_cc_get_empty_field_write_execution()
    {
        return [
            'operations' => [],
            'failures' => [],
            'counts' => [
                'attempted' => 0,
                'completed' => 0,
                'failed' => 0,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dbvc_cc_get_empty_media_write_execution()
    {
        return [
            'operations' => [],
            'failures' => [],
            'counts' => [
                'attempted' => 0,
                'completed' => 0,
                'created' => 0,
                'reused' => 0,
                'failed' => 0,
                'deferred' => 0,
            ],
            'deferred_reasons' => [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entity_writes
     * @param array<string, mixed>             $dbvc_cc_execution_runtime
     * @return array<string, mixed>
     */
    private function dbvc_cc_execute_prepared_entity_writes(array $entity_writes, array &$dbvc_cc_execution_runtime = [])
    {
        $execution = $this->dbvc_cc_get_empty_entity_write_execution();
        $resolved_entity_refs = [];
        $resolved_operation_statuses = [];

        foreach ($entity_writes as $entity_write) {
            if (! is_array($entity_write)) {
                continue;
            }

            $execution['counts']['attempted']++;
            $write_operation_id = isset($entity_write['write_operation_id']) ? sanitize_text_field((string) $entity_write['write_operation_id']) : '';
            $entity_key = isset($entity_write['entity_key']) ? sanitize_text_field((string) $entity_write['entity_key']) : 'post:page';
            $depends_on = isset($entity_write['depends_on']) && is_array($entity_write['depends_on'])
                ? array_values($entity_write['depends_on'])
                : [];

            $blocked_dependency = '';
            foreach ($depends_on as $dependency_id) {
                $dependency_id = sanitize_text_field((string) $dependency_id);
                if ($dependency_id === '') {
                    continue;
                }

                $dependency_status = isset($resolved_operation_statuses[$dependency_id])
                    ? sanitize_key((string) $resolved_operation_statuses[$dependency_id])
                    : '';
                if ($dependency_status !== 'completed') {
                    $blocked_dependency = $dependency_id;
                    break;
                }
            }

            if ($blocked_dependency !== '') {
                $execution['counts']['failed']++;
                $resolved_operation_statuses[$write_operation_id] = 'failed';
                $execution['operations'][] = [
                    'write_operation_id' => $write_operation_id,
                    'entity_key' => $entity_key,
                    'status' => 'failed',
                    'result' => 'failed_dependency',
                    'message' => sprintf(
                        /* translators: %s: dependency operation id */
                        __('Entity write dependency did not complete: %s', 'dbvc'),
                        $blocked_dependency
                    ),
                ];
                $execution['failures'][] = $this->dbvc_cc_build_execution_failure(
                    'missing_write_dependency',
                    sprintf(
                        /* translators: %s: dependency operation id */
                        __('Entity write dependency did not complete: %s', 'dbvc'),
                        $blocked_dependency
                    ),
                    'entity',
                    $write_operation_id,
                    $entity_key
                );
                continue;
            }

            $write_status = isset($entity_write['write_status']) ? sanitize_key((string) $entity_write['write_status']) : '';
            if ($write_status !== 'prepared') {
                $execution['counts']['failed']++;
                $resolved_operation_statuses[$write_operation_id] = 'failed';
                $execution['operations'][] = [
                    'write_operation_id' => $write_operation_id,
                    'entity_key' => $entity_key,
                    'status' => 'failed',
                    'result' => 'failed_unprepared',
                    'message' => __('Entity write was not in a prepared state.', 'dbvc'),
                ];
                $execution['failures'][] = $this->dbvc_cc_build_execution_failure(
                    'entity_write_not_prepared',
                    __('Entity write was not in a prepared state.', 'dbvc'),
                    'entity',
                    $write_operation_id,
                    $entity_key
                );
                continue;
            }

            $execution_result = $this->dbvc_cc_execute_single_entity_write($entity_write, $resolved_entity_refs, $dbvc_cc_execution_runtime);
            $resolved_operation_statuses[$write_operation_id] = isset($execution_result['status'])
                ? sanitize_key((string) $execution_result['status'])
                : 'failed';
            if (
                isset($execution_result['status'], $execution_result['target_entity_id'])
                && (string) $execution_result['status'] === 'completed'
                && absint($execution_result['target_entity_id']) > 0
            ) {
                $resolved_entity_refs['@' . $write_operation_id] = absint($execution_result['target_entity_id']);
            }

            if (
                isset($entity_write['target_entity_ref'], $execution_result['target_entity_id'])
                && is_string($entity_write['target_entity_ref'])
                && strpos((string) $entity_write['target_entity_ref'], '@') === 0
                && absint($execution_result['target_entity_id']) > 0
            ) {
                $resolved_entity_refs[(string) $entity_write['target_entity_ref']] = absint($execution_result['target_entity_id']);
            }

            if ((string) $resolved_operation_statuses[$write_operation_id] === 'completed') {
                $execution['counts']['completed']++;
                if (isset($execution_result['action']) && (string) $execution_result['action'] === 'create_post') {
                    $execution['counts']['created']++;
                } else {
                    $execution['counts']['updated']++;
                }
            } else {
                $execution['counts']['failed']++;
                if (isset($execution_result['failure']) && is_array($execution_result['failure'])) {
                    $execution['failures'][] = $execution_result['failure'];
                }
            }

            $execution['operations'][] = $execution_result;
        }

        return $execution;
    }

    /**
     * @param array<int, array<string, mixed>> $field_writes
     * @param array<string, mixed>             $entity_write_execution
     * @param array<string, mixed>             $dbvc_cc_execution_runtime
     * @return array<string, mixed>
     */
    private function dbvc_cc_execute_prepared_field_writes(array $field_writes, array $entity_write_execution = [], array &$dbvc_cc_execution_runtime = [])
    {
        $execution = $this->dbvc_cc_get_empty_field_write_execution();
        $entity_target_map = $this->dbvc_cc_build_entity_execution_target_map($entity_write_execution);

        foreach ($field_writes as $field_write) {
            if (! is_array($field_write)) {
                continue;
            }

            $execution['counts']['attempted']++;
            $field_execution_result = $this->dbvc_cc_execute_single_field_write($field_write, $entity_target_map, $dbvc_cc_execution_runtime);

            if (
                isset($field_execution_result['status'])
                && sanitize_key((string) $field_execution_result['status']) === 'completed'
            ) {
                $execution['counts']['completed']++;
            } else {
                $execution['counts']['failed']++;
                if (isset($field_execution_result['failure']) && is_array($field_execution_result['failure'])) {
                    $execution['failures'][] = $field_execution_result['failure'];
                }
            }

            $execution['operations'][] = $field_execution_result;
        }

        return $execution;
    }

    /**
     * @param array<int, array<string, mixed>> $media_writes
     * @param array<string, mixed>             $entity_write_execution
     * @param array<string, mixed>             $dbvc_cc_execution_runtime
     * @return array<string, mixed>
     */
    private function dbvc_cc_execute_prepared_media_writes(array $media_writes, array $entity_write_execution = [], array &$dbvc_cc_execution_runtime = [])
    {
        $execution = $this->dbvc_cc_get_empty_media_write_execution();
        $entity_target_map = $this->dbvc_cc_build_entity_execution_target_map($entity_write_execution);

        foreach ($media_writes as $media_write) {
            if (! is_array($media_write)) {
                continue;
            }

            $write_status = isset($media_write['write_status']) ? sanitize_key((string) $media_write['write_status']) : '';
            if ($write_status === 'deferred') {
                $execution['counts']['deferred']++;
                $reason_code = isset($media_write['deferred_reason_code']) ? sanitize_key((string) $media_write['deferred_reason_code']) : 'deferred_media';
                if (! isset($execution['deferred_reasons'][$reason_code])) {
                    $execution['deferred_reasons'][$reason_code] = [
                        'code' => $reason_code,
                        'group' => isset($media_write['deferred_reason_group']) ? sanitize_key((string) $media_write['deferred_reason_group']) : 'policy',
                        'count' => 0,
                        'message' => isset($media_write['message']) ? sanitize_text_field((string) $media_write['message']) : __('Media write was deferred.', 'dbvc'),
                    ];
                }
                $execution['deferred_reasons'][$reason_code]['count']++;
                $execution['operations'][] = [
                    'write_operation_id' => isset($media_write['write_operation_id']) ? sanitize_text_field((string) $media_write['write_operation_id']) : '',
                    'entity_key' => isset($media_write['entity_key']) ? sanitize_text_field((string) $media_write['entity_key']) : 'post:page',
                    'status' => 'deferred',
                    'result' => isset($media_write['result']) ? sanitize_key((string) $media_write['result']) : 'deferred_media',
                    'target_ref' => isset($media_write['target_ref']) ? sanitize_text_field((string) $media_write['target_ref']) : '',
                    'message' => isset($media_write['message']) ? sanitize_text_field((string) $media_write['message']) : __('Media write was deferred.', 'dbvc'),
                    'deferred_reason_code' => $reason_code,
                    'deferred_reason_group' => isset($media_write['deferred_reason_group']) ? sanitize_key((string) $media_write['deferred_reason_group']) : 'policy',
                    'blocking' => false,
                ];
                continue;
            }

            $execution['counts']['attempted']++;
            $media_execution_result = $this->dbvc_cc_execute_single_media_write($media_write, $entity_target_map, $dbvc_cc_execution_runtime);

            if (
                isset($media_execution_result['status'])
                && sanitize_key((string) $media_execution_result['status']) === 'completed'
            ) {
                $execution['counts']['completed']++;
                $execution['counts']['created'] += isset($media_execution_result['created_attachment_count'])
                    ? absint($media_execution_result['created_attachment_count'])
                    : (! empty($media_execution_result['created_attachment']) ? 1 : 0);
                $execution['counts']['reused'] += isset($media_execution_result['reused_attachment_count'])
                    ? absint($media_execution_result['reused_attachment_count'])
                    : (! empty($media_execution_result['created_attachment']) ? 0 : 1);
            } else {
                $execution['counts']['failed']++;
                if (isset($media_execution_result['failure']) && is_array($media_execution_result['failure'])) {
                    $execution['failures'][] = $media_execution_result['failure'];
                }
            }

            $execution['operations'][] = $media_execution_result;
        }

        return $execution;
    }

    /**
     * @param array<string, mixed> $entity_write_execution
     * @return array<string, int>
     */
    private function dbvc_cc_build_entity_execution_target_map(array $entity_write_execution = [])
    {
        $target_map = [];
        $operations = isset($entity_write_execution['operations']) && is_array($entity_write_execution['operations'])
            ? array_values($entity_write_execution['operations'])
            : [];

        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            $status = isset($operation['status']) ? sanitize_key((string) $operation['status']) : '';
            $target_entity_id = isset($operation['target_entity_id']) ? absint($operation['target_entity_id']) : 0;
            if ($status !== 'completed' || $target_entity_id <= 0) {
                continue;
            }

            $write_operation_id = isset($operation['write_operation_id']) ? sanitize_text_field((string) $operation['write_operation_id']) : '';
            $target_entity_ref = isset($operation['target_entity_ref']) ? sanitize_text_field((string) $operation['target_entity_ref']) : '';
            if ($write_operation_id !== '') {
                $target_map['@' . $write_operation_id] = $target_entity_id;
            }
            if ($target_entity_ref !== '') {
                $target_map[$target_entity_ref] = $target_entity_id;
            }
            $target_map['post:' . $target_entity_id] = $target_entity_id;
        }

        return $target_map;
    }

    /**
     * @param array<string, mixed> $field_write
     * @param array<string, int>   $entity_target_map
     * @param array<string, mixed> $dbvc_cc_execution_runtime
     * @return array<string, mixed>
     */
    private function dbvc_cc_execute_single_field_write(array $field_write, array $entity_target_map = [], array &$dbvc_cc_execution_runtime = [])
    {
        $write_operation_id = isset($field_write['write_operation_id']) ? sanitize_text_field((string) $field_write['write_operation_id']) : '';
        $entity_key = isset($field_write['entity_key']) ? sanitize_text_field((string) $field_write['entity_key']) : 'post:page';
        $target_family = isset($field_write['target_family']) ? sanitize_key((string) $field_write['target_family']) : '';
        $target_field_key = isset($field_write['target_field_key']) ? sanitize_key((string) $field_write['target_field_key']) : '';
        $action = isset($field_write['action']) ? sanitize_key((string) $field_write['action']) : '';
        $write_status = isset($field_write['write_status']) ? sanitize_key((string) $field_write['write_status']) : '';

        if ($write_status !== 'prepared') {
            return [
                'write_operation_id' => $write_operation_id,
                'entity_key' => $entity_key,
                'status' => 'failed',
                'result' => 'failed_unprepared',
                'target_ref' => isset($field_write['target_ref']) ? sanitize_text_field((string) $field_write['target_ref']) : '',
                'message' => __('Field write was not in a prepared state.', 'dbvc'),
                'failure' => $this->dbvc_cc_build_execution_failure(
                    'field_write_not_prepared',
                    __('Field write was not in a prepared state.', 'dbvc'),
                    'field',
                    $write_operation_id,
                    $entity_key
                ),
            ];
        }

        $target_entity_ref = isset($field_write['target_entity_ref']) ? sanitize_text_field((string) $field_write['target_entity_ref']) : '';
        $target_entity_id = isset($field_write['target_entity_id']) ? absint($field_write['target_entity_id']) : 0;
        if ($target_entity_id <= 0 && $target_entity_ref !== '' && isset($entity_target_map[$target_entity_ref])) {
            $target_entity_id = absint($entity_target_map[$target_entity_ref]);
        }
        if ($target_entity_id <= 0 && preg_match('/^post:(\d+)$/', $target_entity_ref, $dbvc_cc_post_ref_match) === 1) {
            $target_entity_id = absint($dbvc_cc_post_ref_match[1]);
        }

        if ($target_entity_id <= 0 || ! (get_post($target_entity_id) instanceof WP_Post)) {
            return [
                'write_operation_id' => $write_operation_id,
                'entity_key' => $entity_key,
                'status' => 'failed',
                'result' => 'failed_missing_target_entity',
                'target_ref' => isset($field_write['target_ref']) ? sanitize_text_field((string) $field_write['target_ref']) : '',
                'message' => __('Field write target entity could not be resolved.', 'dbvc'),
                'failure' => $this->dbvc_cc_build_execution_failure(
                    'missing_field_target_entity',
                    __('Field write target entity could not be resolved.', 'dbvc'),
                    'field',
                    $write_operation_id,
                    $entity_key
                ),
            ];
        }

        $resolved_value = isset($field_write['resolved_value_candidate']) ? $field_write['resolved_value_candidate'] : '';
        $before_state = $this->dbvc_cc_capture_field_state($target_entity_id, $target_family, $target_field_key, $action);
        $run_action_id = $this->dbvc_cc_start_import_run_action(
            $dbvc_cc_execution_runtime,
            [
                'stage' => 'field',
                'action_type' => 'update_field',
                'target_object_type' => 'post',
                'target_object_id' => $target_entity_id,
                'target_subtype' => get_post_type($target_entity_id),
                'target_meta_key' => $target_family === 'core' ? '' : $target_field_key,
                'before_state_json' => $before_state,
            ]
        );
        if (is_wp_error($run_action_id)) {
            return [
                'write_operation_id' => $write_operation_id,
                'entity_key' => $entity_key,
                'status' => 'failed',
                'result' => 'failed_journal_unavailable',
                'target_ref' => isset($field_write['target_ref']) ? sanitize_text_field((string) $field_write['target_ref']) : '',
                'message' => $run_action_id->get_error_message(),
                'failure' => $this->dbvc_cc_build_execution_failure(
                    'field_journal_unavailable',
                    $run_action_id->get_error_message(),
                    'field',
                    $write_operation_id,
                    $entity_key
                ),
            ];
        }

        $dbvc_cc_pre_write_failure = $this->dbvc_cc_get_pre_write_failure(
            'field',
            $field_write,
            [
                'run_action_id' => absint($run_action_id),
                'target_entity_id' => $target_entity_id,
                'target_family' => $target_family,
                'target_field_key' => $target_field_key,
                'action' => $action,
                'resolved_value' => $resolved_value,
            ]
        );
        if (is_wp_error($dbvc_cc_pre_write_failure)) {
            $this->dbvc_cc_fail_import_run_action($run_action_id, $dbvc_cc_pre_write_failure->get_error_message());
            return [
                'write_operation_id' => $write_operation_id,
                'entity_key' => $entity_key,
                'status' => 'failed',
                'result' => 'failed_field_write',
                'target_ref' => isset($field_write['target_ref']) ? sanitize_text_field((string) $field_write['target_ref']) : '',
                'message' => $dbvc_cc_pre_write_failure->get_error_message(),
                'failure' => $this->dbvc_cc_build_execution_failure(
                    'field_write_failed',
                    $dbvc_cc_pre_write_failure->get_error_message(),
                    'field',
                    $write_operation_id,
                    $entity_key
                ),
            ];
        }

        $apply_result = $this->dbvc_cc_apply_field_value_to_post(
            $target_entity_id,
            $target_family,
            $target_field_key,
            $action,
            $resolved_value
        );

        if (is_wp_error($apply_result)) {
            $this->dbvc_cc_fail_import_run_action($run_action_id, $apply_result->get_error_message());
            return [
                'write_operation_id' => $write_operation_id,
                'entity_key' => $entity_key,
                'status' => 'failed',
                'result' => 'failed_field_write',
                'target_ref' => isset($field_write['target_ref']) ? sanitize_text_field((string) $field_write['target_ref']) : '',
                'message' => $apply_result->get_error_message(),
                'failure' => $this->dbvc_cc_build_execution_failure(
                    'field_write_failed',
                    $apply_result->get_error_message(),
                    'field',
                    $write_operation_id,
                    $entity_key
                ),
            ];
        }

        $after_state = $this->dbvc_cc_capture_field_state($target_entity_id, $target_family, $target_field_key, $action);
        $journal_completion = $this->dbvc_cc_complete_import_run_action(
            $run_action_id,
            [
                'target_object_id' => $target_entity_id,
                'after_state_json' => $after_state,
                'execution_status' => 'completed',
            ]
        );
        if (is_wp_error($journal_completion)) {
            $this->dbvc_cc_restore_field_state($target_entity_id, $before_state);

            return [
                'write_operation_id' => $write_operation_id,
                'entity_key' => $entity_key,
                'status' => 'failed',
                'result' => 'failed_journal_finalize',
                'target_ref' => isset($field_write['target_ref']) ? sanitize_text_field((string) $field_write['target_ref']) : '',
                'message' => $journal_completion->get_error_message(),
                'failure' => $this->dbvc_cc_build_execution_failure(
                    'field_journal_finalize_failed',
                    $journal_completion->get_error_message(),
                    'field',
                    $write_operation_id,
                    $entity_key
                ),
            ];
        }

        return [
            'write_operation_id' => $write_operation_id,
            'entity_key' => $entity_key,
            'status' => 'completed',
            'result' => 'updated_field',
            'run_action_id' => absint($run_action_id),
            'target_entity_id' => $target_entity_id,
            'target_entity_ref' => 'post:' . $target_entity_id,
            'target_ref' => isset($field_write['target_ref']) ? sanitize_text_field((string) $field_write['target_ref']) : '',
            'action' => $action,
            'resolved_value_origin' => isset($field_write['resolved_value_origin']) ? sanitize_text_field((string) $field_write['resolved_value_origin']) : '',
            'resolved_value_format' => isset($field_write['resolved_value_format']) ? sanitize_key((string) $field_write['resolved_value_format']) : 'string',
            'resolved_value_preview' => isset($field_write['resolved_value_preview']) ? sanitize_text_field((string) $field_write['resolved_value_preview']) : '',
        ];
    }

    /**
     * @param array<string, mixed> $media_write
     * @param array<string, int>   $entity_target_map
     * @param array<string, mixed> $dbvc_cc_execution_runtime
     * @return array<string, mixed>
     */
    private function dbvc_cc_execute_single_media_write(array $media_write, array $entity_target_map = [], array &$dbvc_cc_execution_runtime = [])
    {
        $write_operation_id = isset($media_write['write_operation_id']) ? sanitize_text_field((string) $media_write['write_operation_id']) : '';
        $entity_key = isset($media_write['entity_key']) ? sanitize_text_field((string) $media_write['entity_key']) : 'post:page';
        $target_family = isset($media_write['target_family']) ? sanitize_key((string) $media_write['target_family']) : '';
        $target_field_key = isset($media_write['target_field_key']) ? sanitize_key((string) $media_write['target_field_key']) : '';
        $action = isset($media_write['action']) ? sanitize_key((string) $media_write['action']) : '';
        $write_status = isset($media_write['write_status']) ? sanitize_key((string) $media_write['write_status']) : '';

        if ($write_status === 'deferred') {
            return [
                'write_operation_id' => $write_operation_id,
                'entity_key' => $entity_key,
                'status' => 'deferred',
                'result' => isset($media_write['result']) ? sanitize_key((string) $media_write['result']) : 'deferred_media',
                'target_ref' => isset($media_write['target_ref']) ? sanitize_text_field((string) $media_write['target_ref']) : '',
                'message' => isset($media_write['message']) ? sanitize_text_field((string) $media_write['message']) : __('Media write was deferred.', 'dbvc'),
                'deferred_reason_code' => isset($media_write['deferred_reason_code']) ? sanitize_key((string) $media_write['deferred_reason_code']) : 'deferred_media',
                'deferred_reason_group' => isset($media_write['deferred_reason_group']) ? sanitize_key((string) $media_write['deferred_reason_group']) : 'policy',
                'blocking' => false,
            ];
        }

        if ($write_status !== 'prepared') {
            return [
                'write_operation_id' => $write_operation_id,
                'entity_key' => $entity_key,
                'status' => 'failed',
                'result' => 'failed_unprepared',
                'target_ref' => isset($media_write['target_ref']) ? sanitize_text_field((string) $media_write['target_ref']) : '',
                'message' => __('Media write was not in a prepared state.', 'dbvc'),
                'failure' => $this->dbvc_cc_build_execution_failure(
                    'media_write_not_prepared',
                    __('Media write was not in a prepared state.', 'dbvc'),
                    'media',
                    $write_operation_id,
                    $entity_key
                ),
            ];
        }

        $target_entity_ref = isset($media_write['target_entity_ref']) ? sanitize_text_field((string) $media_write['target_entity_ref']) : '';
        $target_entity_id = isset($media_write['target_entity_id']) ? absint($media_write['target_entity_id']) : 0;
        if ($target_entity_id <= 0 && $target_entity_ref !== '' && isset($entity_target_map[$target_entity_ref])) {
            $target_entity_id = absint($entity_target_map[$target_entity_ref]);
        }
        if ($target_entity_id <= 0 && preg_match('/^post:(\d+)$/', $target_entity_ref, $dbvc_cc_post_ref_match) === 1) {
            $target_entity_id = absint($dbvc_cc_post_ref_match[1]);
        }

        if ($target_entity_id <= 0 || ! (get_post($target_entity_id) instanceof WP_Post)) {
            return [
                'write_operation_id' => $write_operation_id,
                'entity_key' => $entity_key,
                'status' => 'failed',
                'result' => 'failed_missing_target_entity',
                'target_ref' => isset($media_write['target_ref']) ? sanitize_text_field((string) $media_write['target_ref']) : '',
                'message' => __('Media write target entity could not be resolved.', 'dbvc'),
                'failure' => $this->dbvc_cc_build_execution_failure(
                    'missing_media_target_entity',
                    __('Media write target entity could not be resolved.', 'dbvc'),
                    'media',
                    $write_operation_id,
                    $entity_key
                ),
            ];
        }

        $storage_shape = isset($media_write['storage_shape']) ? sanitize_key((string) $media_write['storage_shape']) : 'attachment_id';
        $resolved_media = isset($media_write['resolved_media_candidate']) && is_array($media_write['resolved_media_candidate'])
            ? $media_write['resolved_media_candidate']
            : [];
        $before_state = $this->dbvc_cc_capture_media_state($target_entity_id, $target_family, $target_field_key, $action);
        $run_action_id = $this->dbvc_cc_start_import_run_action(
            $dbvc_cc_execution_runtime,
            [
                'stage' => 'media',
                'action_type' => $action,
                'target_object_type' => 'post',
                'target_object_id' => $target_entity_id,
                'target_subtype' => get_post_type($target_entity_id),
                'target_meta_key' => $target_family === 'core' ? '_thumbnail_id' : $target_field_key,
                'before_state_json' => $before_state,
            ]
        );
        if (is_wp_error($run_action_id)) {
            return [
                'write_operation_id' => $write_operation_id,
                'entity_key' => $entity_key,
                'status' => 'failed',
                'result' => 'failed_journal_unavailable',
                'target_ref' => isset($media_write['target_ref']) ? sanitize_text_field((string) $media_write['target_ref']) : '',
                'message' => $run_action_id->get_error_message(),
                'failure' => $this->dbvc_cc_build_execution_failure(
                    'media_journal_unavailable',
                    $run_action_id->get_error_message(),
                    'media',
                    $write_operation_id,
                    $entity_key
                ),
            ];
        }

        $dbvc_cc_pre_write_failure = $this->dbvc_cc_get_pre_write_failure(
            'media',
            $media_write,
            [
                'run_action_id' => absint($run_action_id),
                'target_entity_id' => $target_entity_id,
                'target_family' => $target_family,
                'target_field_key' => $target_field_key,
                'action' => $action,
                'resolved_media' => $resolved_media,
            ]
        );
        if (is_wp_error($dbvc_cc_pre_write_failure)) {
            $this->dbvc_cc_fail_import_run_action($run_action_id, $dbvc_cc_pre_write_failure->get_error_message());
            return [
                'write_operation_id' => $write_operation_id,
                'entity_key' => $entity_key,
                'status' => 'failed',
                'result' => 'failed_media_write',
                'target_ref' => isset($media_write['target_ref']) ? sanitize_text_field((string) $media_write['target_ref']) : '',
                'message' => $dbvc_cc_pre_write_failure->get_error_message(),
                'failure' => $this->dbvc_cc_build_execution_failure(
                    'media_write_failed',
                    $dbvc_cc_pre_write_failure->get_error_message(),
                    'media',
                    $write_operation_id,
                    $entity_key
                ),
            ];
        }

        $media_value = null;
        $attachment_id = 0;
        $attachment_ids = [];
        $created_attachment = false;
        $created_attachment_ids = [];
        $created_attachment_count = 0;
        $reused_attachment_count = 0;
        $source_urls = [];

        if ($storage_shape === 'remote_url') {
            $media_value = isset($resolved_media['source_url']) ? esc_url_raw((string) $resolved_media['source_url']) : '';
            $source_urls = $media_value !== '' ? [$media_value] : [];
        } else {
            $resolved_media_list = isset($resolved_media[0]) && is_array($resolved_media[0])
                ? array_values($resolved_media)
                : [$resolved_media];

            foreach ($resolved_media_list as $resolved_media_item) {
                if (! is_array($resolved_media_item)) {
                    continue;
                }

                $ingest_result = $this->dbvc_cc_ingest_media_attachment($resolved_media_item, $target_entity_id, $media_write);
                if (is_wp_error($ingest_result)) {
                    foreach ($created_attachment_ids as $created_attachment_id) {
                        if ($created_attachment_id > 0 && get_post($created_attachment_id) instanceof WP_Post) {
                            wp_delete_attachment($created_attachment_id, true);
                        }
                    }

                    $this->dbvc_cc_fail_import_run_action($run_action_id, $ingest_result->get_error_message());
                    return [
                        'write_operation_id' => $write_operation_id,
                        'entity_key' => $entity_key,
                        'status' => 'failed',
                        'result' => 'failed_media_ingest',
                        'target_ref' => isset($media_write['target_ref']) ? sanitize_text_field((string) $media_write['target_ref']) : '',
                        'message' => $ingest_result->get_error_message(),
                        'failure' => $this->dbvc_cc_build_execution_failure(
                            'media_ingest_failed',
                            $ingest_result->get_error_message(),
                            'media',
                            $write_operation_id,
                            $entity_key
                        ),
                    ];
                }

                $resolved_attachment_id = isset($ingest_result['attachment_id']) ? absint($ingest_result['attachment_id']) : 0;
                if ($resolved_attachment_id > 0) {
                    $attachment_ids[] = $resolved_attachment_id;
                    $attachment_id = $resolved_attachment_id;
                }
                if (! empty($ingest_result['created']) && $resolved_attachment_id > 0) {
                    $created_attachment = true;
                    $created_attachment_ids[] = $resolved_attachment_id;
                    $created_attachment_count++;
                } elseif ($resolved_attachment_id > 0) {
                    $reused_attachment_count++;
                }
                if (! empty($ingest_result['source_url'])) {
                    $source_urls[] = esc_url_raw((string) $ingest_result['source_url']);
                }
            }

            if ($this->dbvc_cc_is_aggregated_media_storage_shape($storage_shape)) {
                $media_value = array_values(array_unique(array_filter(array_map('absint', $attachment_ids))));
            } else {
                $media_value = $attachment_id;
            }
        }

        $apply_result = $this->dbvc_cc_apply_media_to_post($target_entity_id, $target_family, $target_field_key, $action, $media_value, $media_write);
        if (is_wp_error($apply_result)) {
            foreach ($created_attachment_ids as $created_attachment_id) {
                if ($created_attachment_id > 0 && get_post($created_attachment_id) instanceof WP_Post) {
                    wp_delete_attachment($created_attachment_id, true);
                }
            }

            $this->dbvc_cc_fail_import_run_action($run_action_id, $apply_result->get_error_message());
            return [
                'write_operation_id' => $write_operation_id,
                'entity_key' => $entity_key,
                'status' => 'failed',
                'result' => 'failed_media_write',
                'target_ref' => isset($media_write['target_ref']) ? sanitize_text_field((string) $media_write['target_ref']) : '',
                'message' => $apply_result->get_error_message(),
                'failure' => $this->dbvc_cc_build_execution_failure(
                    'media_write_failed',
                    $apply_result->get_error_message(),
                    'media',
                    $write_operation_id,
                    $entity_key
                ),
            ];
        }

        $after_state = $this->dbvc_cc_capture_media_state(
            $target_entity_id,
            $target_family,
            $target_field_key,
            $action,
            [
                'attachment_id' => $attachment_id,
                'created_attachment' => $created_attachment,
                'attachment_ids' => $attachment_ids,
                'created_attachment_ids' => $created_attachment_ids,
                'source_url' => ! empty($source_urls) ? (string) $source_urls[0] : '',
                'source_urls' => $source_urls,
            ]
        );
        $journal_completion = $this->dbvc_cc_complete_import_run_action(
            $run_action_id,
            [
                'target_object_id' => $target_entity_id,
                'after_state_json' => $after_state,
                'execution_status' => 'completed',
            ]
        );
        if (is_wp_error($journal_completion)) {
            $this->dbvc_cc_restore_media_state($target_entity_id, $before_state, $after_state);

            return [
                'write_operation_id' => $write_operation_id,
                'entity_key' => $entity_key,
                'status' => 'failed',
                'result' => 'failed_journal_finalize',
                'target_ref' => isset($media_write['target_ref']) ? sanitize_text_field((string) $media_write['target_ref']) : '',
                'message' => $journal_completion->get_error_message(),
                'failure' => $this->dbvc_cc_build_execution_failure(
                    'media_journal_finalize_failed',
                    $journal_completion->get_error_message(),
                    'media',
                    $write_operation_id,
                    $entity_key
                ),
            ];
        }

        return [
            'write_operation_id' => $write_operation_id,
            'entity_key' => $entity_key,
            'status' => 'completed',
            'result' => 'updated_media',
            'run_action_id' => absint($run_action_id),
            'target_entity_id' => $target_entity_id,
            'target_entity_ref' => 'post:' . $target_entity_id,
            'target_ref' => isset($media_write['target_ref']) ? sanitize_text_field((string) $media_write['target_ref']) : '',
            'action' => $action,
            'attachment_id' => $attachment_id,
            'attachment_ids' => $attachment_ids,
            'created_attachment' => $created_attachment,
            'created_attachment_count' => $created_attachment_count,
            'reused_attachment_count' => $reused_attachment_count,
            'source_url' => ! empty($source_urls) ? (string) $source_urls[0] : '',
            'preview_ref' => isset($resolved_media['preview_ref']) ? esc_url_raw((string) $resolved_media['preview_ref']) : '',
        ];
    }

    /**
     * @param int    $post_id
     * @param string $target_family
     * @param string $target_field_key
     * @param string $action
     * @param mixed  $value
     * @return true|WP_Error
     */
    private function dbvc_cc_apply_field_value_to_post($post_id, $target_family, $target_field_key, $action, $value)
    {
        $post_id = absint($post_id);
        $target_family = sanitize_key((string) $target_family);
        $target_field_key = sanitize_key((string) $target_field_key);
        $action = sanitize_key((string) $action);

        if ($post_id <= 0 || ! (get_post($post_id) instanceof WP_Post)) {
            return new WP_Error(
                'dbvc_cc_invalid_post_id',
                __('Field write target post is invalid.', 'dbvc'),
                ['status' => 500]
            );
        }

        if ($action === 'update_post_field') {
            $post_update = ['ID' => $post_id];
            if ($target_field_key === 'post_title') {
                $post_update['post_title'] = sanitize_text_field((string) $value);
            } elseif ($target_field_key === 'post_content') {
                $post_update['post_content'] = (string) $value;
            } elseif ($target_field_key === 'post_excerpt') {
                $post_update['post_excerpt'] = sanitize_textarea_field((string) $value);
            } elseif ($target_field_key === 'post_name') {
                $post_update['post_name'] = sanitize_title((string) $value);
            } elseif ($target_field_key === 'menu_order') {
                $post_update['menu_order'] = is_numeric($value) ? (int) $value : 0;
            } else {
                return new WP_Error(
                    'dbvc_cc_unsupported_core_field',
                    __('Field write target is not supported for core updates.', 'dbvc'),
                    ['status' => 500]
                );
            }

            $result = wp_update_post(wp_slash($post_update), true, false);
            if (is_wp_error($result)) {
                return $result;
            }

            return true;
        }

        if ($action === 'update_post_meta') {
            return $this->dbvc_cc_replace_post_meta_values($post_id, $target_field_key, $value);
        }

        if ($action === 'update_acf_field') {
            if (function_exists('update_field')) {
                $acf_result = update_field($target_field_key, $value, $post_id);
                if ($acf_result !== false) {
                    return true;
                }
            }

            return $this->dbvc_cc_replace_post_meta_values($post_id, $target_field_key, $value);
        }

        return new WP_Error(
            'dbvc_cc_unsupported_field_action',
            __('Field write action is not supported.', 'dbvc'),
            ['status' => 500]
        );
    }

    /**
     * @param int    $post_id
     * @param string $target_family
     * @param string $target_field_key
     * @param string $action
     * @param mixed  $media_value
     * @param array<string, mixed> $media_write
     * @return true|WP_Error
     */
    private function dbvc_cc_apply_media_to_post($post_id, $target_family, $target_field_key, $action, $media_value, array $media_write = [])
    {
        $post_id = absint($post_id);
        $target_family = sanitize_key((string) $target_family);
        $target_field_key = sanitize_key((string) $target_field_key);
        $action = sanitize_key((string) $action);
        $storage_shape = isset($media_write['storage_shape']) ? sanitize_key((string) $media_write['storage_shape']) : 'attachment_id';
        $attachment_id = is_array($media_value) ? absint(reset($media_value)) : absint($media_value);

        if ($post_id <= 0 || ! (get_post($post_id) instanceof WP_Post)) {
            return new WP_Error(
                'dbvc_cc_invalid_media_target',
                __('Media write target is invalid.', 'dbvc'),
                ['status' => 500]
            );
        }

        if ($target_family === 'core' && $action === 'set_featured_image_from_remote') {
            if ($attachment_id <= 0 || ! (get_post($attachment_id) instanceof WP_Post)) {
                return new WP_Error(
                    'dbvc_cc_invalid_featured_image_target',
                    __('Featured image target attachment is invalid.', 'dbvc'),
                    ['status' => 500]
                );
            }

            if ((int) get_post_thumbnail_id($post_id) === $attachment_id) {
                return true;
            }

            $thumbnail_result = set_post_thumbnail($post_id, $attachment_id);
            if (! $thumbnail_result && (int) get_post_thumbnail_id($post_id) !== $attachment_id) {
                return new WP_Error(
                    'dbvc_cc_featured_image_failed',
                    __('Featured image could not be applied to the target post.', 'dbvc'),
                    ['status' => 500]
                );
            }

            return true;
        }

        if (in_array($action, ['update_post_meta_media_url', 'update_acf_media_url'], true)) {
            $url_value = esc_url_raw((string) $media_value);
            if ($action === 'update_acf_media_url' && function_exists('update_field')) {
                $acf_result = update_field($target_field_key, $url_value, $post_id);
                if ($acf_result !== false || (string) get_post_meta($post_id, $target_field_key, true) === $url_value) {
                    return true;
                }
            }

            return $this->dbvc_cc_replace_post_meta_values($post_id, $target_field_key, $url_value);
        }

        if ($target_family === 'acf' && function_exists('update_field')) {
            $acf_value = $storage_shape === 'attachment_id_list'
                ? (is_array($media_value) ? array_values(array_map('absint', $media_value)) : [])
                : $attachment_id;
            $acf_result = update_field($target_field_key, $acf_value, $post_id);
            if ($acf_result !== false || get_post_meta($post_id, $target_field_key, false) === (array) $acf_value) {
                return true;
            }
        }

        if ($storage_shape === 'attachment_id_list') {
            return $this->dbvc_cc_replace_post_meta_values(
                $post_id,
                $target_field_key,
                is_array($media_value) ? array_values(array_map('absint', $media_value)) : []
            );
        }

        if ($attachment_id <= 0 || ! (get_post($attachment_id) instanceof WP_Post)) {
            return new WP_Error(
                'dbvc_cc_invalid_media_attachment',
                __('Media attachment target is invalid.', 'dbvc'),
                ['status' => 500]
            );
        }

        return $this->dbvc_cc_replace_post_meta_values($post_id, $target_field_key, $attachment_id);
    }

    /**
     * @param int    $post_id
     * @param string $meta_key
     * @param mixed  $value
     * @return true|WP_Error
     */
    private function dbvc_cc_replace_post_meta_values($post_id, $meta_key, $value)
    {
        $post_id = absint($post_id);
        $meta_key = sanitize_key((string) $meta_key);
        if ($post_id <= 0 || $meta_key === '') {
            return new WP_Error(
                'dbvc_cc_invalid_meta_target',
                __('Meta write target is invalid.', 'dbvc'),
                ['status' => 500]
            );
        }

        $post = get_post($post_id);
        if (! ($post instanceof WP_Post)) {
            return new WP_Error(
                'dbvc_cc_invalid_meta_post',
                __('Meta write target post is invalid.', 'dbvc'),
                ['status' => 500]
            );
        }

        $registered_meta = function_exists('get_registered_meta_keys')
            ? get_registered_meta_keys('post', $post->post_type)
            : [];
        $is_single = true;
        $registered_meta_type = '';
        if (is_array($registered_meta) && isset($registered_meta[$meta_key]) && is_array($registered_meta[$meta_key]) && array_key_exists('single', $registered_meta[$meta_key])) {
            $is_single = (bool) $registered_meta[$meta_key]['single'];
            $registered_meta_type = isset($registered_meta[$meta_key]['type']) ? sanitize_key((string) $registered_meta[$meta_key]['type']) : '';
        } elseif (is_array($value)) {
            $is_single = count($value) <= 1;
        }

        if ($is_single) {
            $single_value = is_array($value)
                ? ($registered_meta_type === 'array' ? array_values($value) : reset($value))
                : $value;
            update_post_meta($post_id, $meta_key, $single_value);
            return true;
        }

        delete_post_meta($post_id, $meta_key);
        $value_set = is_array($value) ? array_values($value) : [$value];
        foreach ($value_set as $meta_value) {
            add_post_meta($post_id, $meta_key, $meta_value, false);
        }

        return true;
    }

    /**
     * @param array<string, mixed>    $entity_write
     * @param array<string, int>      $resolved_entity_refs
     * @param array<string, mixed>    $dbvc_cc_execution_runtime
     * @return array<string, mixed>
     */
    private function dbvc_cc_execute_single_entity_write(array $entity_write, array $resolved_entity_refs = [], array &$dbvc_cc_execution_runtime = [])
    {
        $write_operation_id = isset($entity_write['write_operation_id']) ? sanitize_text_field((string) $entity_write['write_operation_id']) : '';
        $entity_key = isset($entity_write['entity_key']) ? sanitize_text_field((string) $entity_write['entity_key']) : 'post:page';
        $entity_subtype = isset($entity_write['entity_subtype']) ? sanitize_key((string) $entity_write['entity_subtype']) : 'page';
        $planned_post_args = isset($entity_write['planned_post_args']) && is_array($entity_write['planned_post_args'])
            ? $entity_write['planned_post_args']
            : [];
        $idempotency_meta = isset($entity_write['idempotency_meta']) && is_array($entity_write['idempotency_meta'])
            ? $entity_write['idempotency_meta']
            : [];
        $source_payload = isset($entity_write['source_payload']) && is_array($entity_write['source_payload'])
            ? $entity_write['source_payload']
            : [];
        $action = isset($entity_write['action']) ? sanitize_key((string) $entity_write['action']) : '';
        $target_entity_id = isset($entity_write['target_entity_id']) ? absint($entity_write['target_entity_id']) : 0;
        $post_parent_ref = isset($planned_post_args['post_parent_ref']) ? sanitize_text_field((string) $planned_post_args['post_parent_ref']) : '';
        $post_parent = isset($planned_post_args['post_parent']) ? absint($planned_post_args['post_parent']) : 0;

        if ($post_parent_ref !== '' && isset($resolved_entity_refs[$post_parent_ref])) {
            $post_parent = absint($resolved_entity_refs[$post_parent_ref]);
        } elseif ($post_parent_ref !== '' && strpos($post_parent_ref, '@') === 0) {
            return [
                'write_operation_id' => $write_operation_id,
                'entity_key' => $entity_key,
                'action' => $action,
                'status' => 'failed',
                'result' => 'failed_missing_parent_ref',
                'message' => __('Parent write reference could not be resolved during execution.', 'dbvc'),
                'failure' => $this->dbvc_cc_build_execution_failure(
                    'missing_parent_write_ref',
                    __('Parent write reference could not be resolved during execution.', 'dbvc'),
                    'entity',
                    $write_operation_id,
                    $entity_key
                ),
            ];
        }

        $prepared_post_args = [
            'post_type' => $entity_subtype,
        ];
        if ($post_parent > 0) {
            $prepared_post_args['post_parent'] = $post_parent;
        }

        $page_title_candidate = isset($source_payload['page_title_candidate'])
            ? sanitize_text_field((string) $source_payload['page_title_candidate'])
            : '';
        $page_excerpt_candidate = isset($source_payload['page_excerpt_candidate'])
            ? sanitize_textarea_field((string) $source_payload['page_excerpt_candidate'])
            : '';
        $page_slug_candidate = isset($source_payload['page_slug_candidate'])
            ? sanitize_title((string) $source_payload['page_slug_candidate'])
            : '';

        if ($action === 'create_post') {
            $dbvc_cc_recheck = $this->dbvc_cc_recheck_prepared_entity_target($entity_subtype, $idempotency_meta);
            if (! empty($dbvc_cc_recheck['ambiguous'])) {
                return [
                    'write_operation_id' => $write_operation_id,
                    'entity_key' => $entity_key,
                    'action' => $action,
                    'status' => 'failed',
                    'result' => 'failed_ambiguous_rerun_target',
                    'message' => __('Prepared create became ambiguous before execution.', 'dbvc'),
                    'failure' => $this->dbvc_cc_build_execution_failure(
                        'ambiguous_rerun_target',
                        __('Prepared create became ambiguous before execution.', 'dbvc'),
                        'entity',
                        $write_operation_id,
                        $entity_key
                    ),
                ];
            }

            if (! empty($dbvc_cc_recheck['existing_entity_id'])) {
                $action = 'update_existing_post';
                $target_entity_id = absint($dbvc_cc_recheck['existing_entity_id']);
            } else {
                $prepared_post_args['post_status'] = isset($planned_post_args['post_status'])
                    ? sanitize_key((string) $planned_post_args['post_status'])
                    : 'draft';
                $prepared_post_args['post_name'] = isset($planned_post_args['post_name'])
                    ? sanitize_title((string) $planned_post_args['post_name'])
                    : $page_slug_candidate;
                $prepared_post_args['post_title'] = isset($planned_post_args['post_title'])
                    ? sanitize_text_field((string) $planned_post_args['post_title'])
                    : $page_title_candidate;
                $prepared_post_args['post_excerpt'] = isset($planned_post_args['post_excerpt'])
                    ? sanitize_textarea_field((string) $planned_post_args['post_excerpt'])
                    : $page_excerpt_candidate;

                if ($prepared_post_args['post_name'] === '') {
                    $prepared_post_args['post_name'] = $page_slug_candidate !== '' ? $page_slug_candidate : sanitize_title($entity_key);
                }
                if ($prepared_post_args['post_title'] === '') {
                    $prepared_post_args['post_title'] = $page_title_candidate !== '' ? $page_title_candidate : ucwords(str_replace('-', ' ', (string) $prepared_post_args['post_name']));
                }
            }
        }

        if ($action === 'update_existing_post') {
            if ($target_entity_id <= 0) {
                return [
                    'write_operation_id' => $write_operation_id,
                    'entity_key' => $entity_key,
                    'action' => $action,
                    'status' => 'failed',
                    'result' => 'failed_missing_target_entity',
                    'message' => __('Prepared update did not include a target entity ID.', 'dbvc'),
                    'failure' => $this->dbvc_cc_build_execution_failure(
                        'missing_target_entity_id',
                        __('Prepared update did not include a target entity ID.', 'dbvc'),
                        'entity',
                        $write_operation_id,
                        $entity_key
                    ),
                ];
            }

            $prepared_post_args['ID'] = $target_entity_id;
            if ($page_title_candidate !== '') {
                $prepared_post_args['post_title'] = $page_title_candidate;
            }
            if ($page_excerpt_candidate !== '') {
                $prepared_post_args['post_excerpt'] = $page_excerpt_candidate;
            }
        } else {
            if ($action !== 'create_post') {
                return [
                    'write_operation_id' => $write_operation_id,
                    'entity_key' => $entity_key,
                    'action' => $action,
                    'status' => 'failed',
                    'result' => 'failed_unsupported_action',
                    'message' => __('Prepared entity write action is unsupported in this phase.', 'dbvc'),
                    'failure' => $this->dbvc_cc_build_execution_failure(
                        'unsupported_entity_write_action',
                        __('Prepared entity write action is unsupported in this phase.', 'dbvc'),
                        'entity',
                        $write_operation_id,
                        $entity_key
                    ),
                ];
            }
        }

        $before_state = $this->dbvc_cc_capture_entity_state($action, $target_entity_id, $idempotency_meta);
        $run_action_id = $this->dbvc_cc_start_import_run_action(
            $dbvc_cc_execution_runtime,
            [
                'stage' => 'entity',
                'action_type' => $action === 'create_post' ? 'create_post' : 'update_post',
                'target_object_type' => 'post',
                'target_object_id' => $target_entity_id,
                'target_subtype' => $entity_subtype,
                'target_meta_key' => '',
                'before_state_json' => $before_state,
            ]
        );
        if (is_wp_error($run_action_id)) {
            return [
                'write_operation_id' => $write_operation_id,
                'entity_key' => $entity_key,
                'action' => $action,
                'status' => 'failed',
                'result' => 'failed_journal_unavailable',
                'message' => $run_action_id->get_error_message(),
                'failure' => $this->dbvc_cc_build_execution_failure(
                    'entity_journal_unavailable',
                    $run_action_id->get_error_message(),
                    'entity',
                    $write_operation_id,
                    $entity_key
                ),
            ];
        }

        $dbvc_cc_pre_write_failure = $this->dbvc_cc_get_pre_write_failure(
            'entity',
            $entity_write,
            [
                'run_action_id' => absint($run_action_id),
                'target_entity_id' => $target_entity_id,
                'entity_subtype' => $entity_subtype,
                'action' => $action,
                'planned_post_args' => $prepared_post_args,
            ]
        );
        if (is_wp_error($dbvc_cc_pre_write_failure)) {
            $error_message = $dbvc_cc_pre_write_failure->get_error_message();
            $this->dbvc_cc_fail_import_run_action($run_action_id, $error_message);

            return [
                'write_operation_id' => $write_operation_id,
                'entity_key' => $entity_key,
                'action' => $action,
                'status' => 'failed',
                'result' => 'failed_wordpress_write',
                'message' => sanitize_text_field((string) $error_message),
                'failure' => $this->dbvc_cc_build_execution_failure(
                    'wordpress_entity_write_failed',
                    sanitize_text_field((string) $error_message),
                    'entity',
                    $write_operation_id,
                    $entity_key
                ),
            ];
        }

        if ($action === 'update_existing_post') {
            $write_result = wp_update_post(wp_slash($prepared_post_args), true, false);
        } else {
            $write_result = wp_insert_post(wp_slash($prepared_post_args), true, false);
        }

        if (is_wp_error($write_result) || absint($write_result) <= 0) {
            $error_message = is_wp_error($write_result)
                ? $write_result->get_error_message()
                : __('WordPress did not return a valid entity ID.', 'dbvc');

            $this->dbvc_cc_fail_import_run_action($run_action_id, $error_message);

            return [
                'write_operation_id' => $write_operation_id,
                'entity_key' => $entity_key,
                'action' => $action,
                'status' => 'failed',
                'result' => 'failed_wordpress_write',
                'message' => sanitize_text_field((string) $error_message),
                'failure' => $this->dbvc_cc_build_execution_failure(
                    'wordpress_entity_write_failed',
                    sanitize_text_field((string) $error_message),
                    'entity',
                    $write_operation_id,
                    $entity_key
                ),
            ];
        }

        $target_entity_id = absint($write_result);
        foreach ($idempotency_meta as $meta_key => $meta_value) {
            if (! is_string($meta_key) || $meta_key === '') {
                continue;
            }
            update_post_meta($target_entity_id, $meta_key, $meta_value);
        }

        $after_state = $this->dbvc_cc_capture_entity_state($action, $target_entity_id, $idempotency_meta);
        $journal_completion = $this->dbvc_cc_complete_import_run_action(
            $run_action_id,
            [
                'target_object_id' => $target_entity_id,
                'after_state_json' => $after_state,
                'execution_status' => 'completed',
            ]
        );
        if (is_wp_error($journal_completion)) {
            $this->dbvc_cc_restore_entity_state($action, $target_entity_id, $before_state);

            return [
                'write_operation_id' => $write_operation_id,
                'entity_key' => $entity_key,
                'action' => $action,
                'status' => 'failed',
                'result' => 'failed_journal_finalize',
                'message' => $journal_completion->get_error_message(),
                'failure' => $this->dbvc_cc_build_execution_failure(
                    'entity_journal_finalize_failed',
                    $journal_completion->get_error_message(),
                    'entity',
                    $write_operation_id,
                    $entity_key
                ),
            ];
        }

        return [
            'write_operation_id' => $write_operation_id,
            'entity_key' => $entity_key,
            'action' => $action,
            'status' => 'completed',
            'result' => $action === 'create_post' ? 'created' : 'updated',
            'run_action_id' => absint($run_action_id),
            'target_entity_id' => $target_entity_id,
            'target_entity_ref' => 'post:' . $target_entity_id,
            'source_payload' => $source_payload,
            'applied_post_args' => $prepared_post_args,
            'idempotency_meta' => $idempotency_meta,
        ];
    }

    /**
     * @param string               $post_type
     * @param array<string, mixed> $idempotency_meta
     * @return array<string, mixed>
     */
    private function dbvc_cc_recheck_prepared_entity_target($post_type, array $idempotency_meta = [])
    {
        $post_type = sanitize_key((string) $post_type);
        if ($post_type === '' || ! post_type_exists($post_type)) {
            return [
                'existing_entity_id' => 0,
                'ambiguous' => false,
            ];
        }

        $source_url = isset($idempotency_meta[DBVC_CC_Contracts::IMPORT_META_SOURCE_URL])
            ? esc_url_raw((string) $idempotency_meta[DBVC_CC_Contracts::IMPORT_META_SOURCE_URL])
            : '';
        $source_path = isset($idempotency_meta[DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH])
            ? $this->dbvc_cc_normalize_source_path((string) $idempotency_meta[DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH])
            : '';
        $source_hash = isset($idempotency_meta[DBVC_CC_Contracts::IMPORT_META_SOURCE_HASH])
            ? sanitize_text_field((string) $idempotency_meta[DBVC_CC_Contracts::IMPORT_META_SOURCE_HASH])
            : '';

        if ($source_url !== '') {
            $candidate_ids = $this->dbvc_cc_find_post_ids_by_source_url($post_type, $source_url);
            if (count($candidate_ids) === 1) {
                return [
                    'existing_entity_id' => (int) $candidate_ids[0],
                    'ambiguous' => false,
                ];
            }
            if (count($candidate_ids) > 1) {
                return [
                    'existing_entity_id' => 0,
                    'ambiguous' => true,
                ];
            }
        }

        if ($source_path !== '') {
            $candidate_ids = $this->dbvc_cc_find_post_ids_by_source_path($post_type, $source_path);
            if (count($candidate_ids) === 1) {
                return [
                    'existing_entity_id' => (int) $candidate_ids[0],
                    'ambiguous' => false,
                ];
            }
            if (count($candidate_ids) > 1) {
                return [
                    'existing_entity_id' => 0,
                    'ambiguous' => true,
                ];
            }
        }

        if ($source_hash !== '') {
            $candidate_ids = $this->dbvc_cc_find_post_ids_by_source_hash($post_type, $source_hash);
            if (count($candidate_ids) === 1) {
                return [
                    'existing_entity_id' => (int) $candidate_ids[0],
                    'ambiguous' => false,
                ];
            }
            if (count($candidate_ids) > 1) {
                return [
                    'existing_entity_id' => 0,
                    'ambiguous' => true,
                ];
            }
        }

        if ($source_path !== '' && is_post_type_hierarchical($post_type)) {
            $page = get_page_by_path($source_path, OBJECT, $post_type);
            if ($page instanceof WP_Post) {
                return [
                    'existing_entity_id' => (int) $page->ID,
                    'ambiguous' => false,
                ];
            }
        }

        return [
            'existing_entity_id' => 0,
            'ambiguous' => false,
        ];
    }

    /**
     * @param string $code
     * @param string $message
     * @param string $stage
     * @param string $write_operation_id
     * @param string $entity_key
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_execution_failure($code, $message, $stage, $write_operation_id, $entity_key)
    {
        return [
            'code' => sanitize_key((string) $code),
            'message' => sanitize_text_field((string) $message),
            'stage' => sanitize_key((string) $stage),
            'write_operation_id' => sanitize_text_field((string) $write_operation_id),
            'entity_key' => sanitize_text_field((string) $entity_key),
        ];
    }

    /**
     * @param string $type
     * @param string $domain
     * @param string $path
     * @param string $source_key
     * @param string $target_ref
     * @return string
     */
    private function dbvc_cc_build_operation_id($type, $domain, $path, $source_key, $target_ref)
    {
        $payload = [
            'type' => sanitize_key((string) $type),
            'domain' => sanitize_text_field((string) $domain),
            'path' => sanitize_text_field((string) $path),
            'source_key' => sanitize_text_field((string) $source_key),
            'target_ref' => sanitize_text_field((string) $target_ref),
        ];

        return 'dbvc_cc_op_' . substr(hash('sha256', wp_json_encode($payload)), 0, 16);
    }

    /**
     * @param array<string, mixed> $dry_run_execution
     * @param array<string, mixed> $write_preparation
     * @param array<string, mixed> $preflight_approval
     * @param string               $approval_token
     * @return array<string, mixed>|WP_Error
     */
    private function dbvc_cc_open_import_run(array $dry_run_execution, array $write_preparation, array $preflight_approval, $approval_token = '')
    {
        $approval = isset($preflight_approval['approval']) && is_array($preflight_approval['approval'])
            ? $preflight_approval['approval']
            : [];
        $approval_id = isset($approval['approval_id']) ? sanitize_text_field((string) $approval['approval_id']) : '';
        if ($approval_id === '') {
            return new WP_Error(
                'dbvc_cc_import_run_missing_approval',
                __('Preflight approval must be valid before the import run ledger can be created.', 'dbvc'),
                ['status' => 500]
            );
        }

        $run_uuid = 'dbvc_cc_run_' . substr(hash('sha256', wp_json_encode([
            'approval_id' => $approval_id,
            'generated_at' => microtime(true),
            'user_id' => get_current_user_id(),
            'rand' => wp_rand(),
        ])), 0, 20);

        $store = DBVC_CC_Import_Run_Store::get_instance();
        $run_id = $store->create_run([
            'run_uuid' => $run_uuid,
            'approval_id' => $approval_id,
            'approval_token_hash' => $approval_token !== '' ? hash('sha256', sanitize_text_field((string) $approval_token)) : '',
            'approval_context_fingerprint' => isset($approval['context_fingerprint']) ? (string) $approval['context_fingerprint'] : '',
            'domain' => isset($dry_run_execution['domain']) ? (string) $dry_run_execution['domain'] : '',
            'path' => isset($dry_run_execution['path']) ? (string) $dry_run_execution['path'] : '',
            'source_url' => isset($dry_run_execution['source_url']) ? (string) $dry_run_execution['source_url'] : '',
            'dry_run_execution_id' => isset($dry_run_execution['execution_id']) ? (string) $dry_run_execution['execution_id'] : '',
            'plan_id' => isset($dry_run_execution['plan_id']) ? (string) $dry_run_execution['plan_id'] : '',
            'graph_fingerprint' => isset($dry_run_execution['operation_graph']['graph_fingerprint']) ? (string) $dry_run_execution['operation_graph']['graph_fingerprint'] : '',
            'write_plan_id' => isset($write_preparation['write_plan_id']) ? (string) $write_preparation['write_plan_id'] : '',
            'status' => 'running',
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
            'approved_at' => isset($approval['approved_at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $approval['approved_at'])) : current_time('mysql', true),
            'started_at' => current_time('mysql', true),
            'rollback_status' => 'available',
            'summary_json' => [
                'approval_summary' => isset($preflight_approval['summary']) ? $preflight_approval['summary'] : [],
                'prepared_counts' => isset($write_preparation['operation_counts']) ? $write_preparation['operation_counts'] : [],
            ],
            'trace_json' => isset($dry_run_execution['trace']) ? $dry_run_execution['trace'] : [],
        ]);
        if (is_wp_error($run_id)) {
            return $run_id;
        }

        $run = $store->get_run($run_id);
        if (! is_array($run)) {
            return new WP_Error(
                'dbvc_cc_import_run_load_failed',
                __('Import run ledger was created but could not be loaded.', 'dbvc'),
                ['status' => 500]
            );
        }

        return $run;
    }

    /**
     * @param int                  $run_id
     * @param string               $status
     * @param array<string, mixed> $entity_write_execution
     * @param array<string, mixed> $field_write_execution
     * @param array<string, mixed> $media_write_execution
     * @param array<int, mixed>    $execution_failures
     * @param array<string, mixed> $dbvc_cc_execution_runtime
     * @return bool
     */
    private function dbvc_cc_finalize_import_run($run_id, $status, array $entity_write_execution, array $field_write_execution, array $media_write_execution = [], array $execution_failures = [], array $dbvc_cc_execution_runtime = [])
    {
        $run_id = absint($run_id);
        if ($run_id <= 0) {
            return false;
        }

        $store = DBVC_CC_Import_Run_Store::get_instance();
        $actions = $store->get_run_actions($run_id);
        $completed_action_count = 0;
        foreach ($actions as $action) {
            if (is_array($action) && isset($action['execution_status']) && (string) $action['execution_status'] === 'completed') {
                $completed_action_count++;
            }
        }

        $rollback_available = $completed_action_count > 0;
        $store->update_run($run_id, [
            'status' => sanitize_key((string) $status),
            'finished_at' => current_time('mysql', true),
            'rollback_status' => $rollback_available ? 'available' : 'not_available',
            'summary_json' => [
                'entity_counts' => isset($entity_write_execution['counts']) ? $entity_write_execution['counts'] : [],
                'field_counts' => isset($field_write_execution['counts']) ? $field_write_execution['counts'] : [],
                'media_counts' => isset($media_write_execution['counts']) ? $media_write_execution['counts'] : [],
                'execution_failures' => $execution_failures,
                'next_action_order' => isset($dbvc_cc_execution_runtime['next_action_order']) ? absint($dbvc_cc_execution_runtime['next_action_order']) : 1,
            ],
            'error_summary' => ! empty($execution_failures) ? sanitize_text_field((string) reset($execution_failures)['message']) : '',
        ]);

        return $rollback_available;
    }

    /**
     * @param array<string, mixed> $dbvc_cc_execution_runtime
     * @param array<string, mixed> $action_data
     * @return int|WP_Error
     */
    private function dbvc_cc_start_import_run_action(array &$dbvc_cc_execution_runtime, array $action_data)
    {
        $run_id = isset($dbvc_cc_execution_runtime['run_id']) ? absint($dbvc_cc_execution_runtime['run_id']) : 0;
        if ($run_id <= 0) {
            return new WP_Error(
                'dbvc_cc_import_action_missing_run',
                __('Import action journaling requires an active run ledger.', 'dbvc'),
                ['status' => 500]
            );
        }

        $action_order = isset($dbvc_cc_execution_runtime['next_action_order'])
            ? max(1, absint($dbvc_cc_execution_runtime['next_action_order']))
            : 1;

        $action_id = DBVC_CC_Import_Run_Store::get_instance()->create_action(array_merge(
            $action_data,
            [
                'run_id' => $run_id,
                'action_order' => $action_order,
                'execution_status' => 'pending',
                'rollback_status' => 'available',
                'created_at' => current_time('mysql', true),
            ]
        ));

        if (! is_wp_error($action_id)) {
            $dbvc_cc_execution_runtime['next_action_order'] = $action_order + 1;
        }

        return $action_id;
    }

    /**
     * @param int                  $action_id
     * @param array<string, mixed> $data
     * @return true|WP_Error
     */
    private function dbvc_cc_complete_import_run_action($action_id, array $data = [])
    {
        $action_id = absint($action_id);
        if ($action_id <= 0) {
            return new WP_Error(
                'dbvc_cc_import_action_invalid',
                __('Import action ID is invalid.', 'dbvc'),
                ['status' => 500]
            );
        }

        $data['execution_status'] = isset($data['execution_status']) ? $data['execution_status'] : 'completed';

        return DBVC_CC_Import_Run_Store::get_instance()->update_action($action_id, $data);
    }

    /**
     * @param int    $action_id
     * @param string $error_message
     * @return true|WP_Error
     */
    private function dbvc_cc_fail_import_run_action($action_id, $error_message)
    {
        return DBVC_CC_Import_Run_Store::get_instance()->update_action(
            $action_id,
            [
                'execution_status' => 'failed',
                'execution_error' => sanitize_text_field((string) $error_message),
                'rollback_status' => 'not_available',
            ]
        );
    }

    /**
     * @param string               $action
     * @param int                  $target_entity_id
     * @param array<string, mixed> $idempotency_meta
     * @return array<string, mixed>
     */
    private function dbvc_cc_capture_entity_state($action, $target_entity_id, array $idempotency_meta = [])
    {
        $action = sanitize_key((string) $action);
        $target_entity_id = absint($target_entity_id);
        $post = $target_entity_id > 0 ? get_post($target_entity_id) : null;
        if (! ($post instanceof WP_Post)) {
            return [
                'action' => $action,
                'post_exists' => false,
                'post_id' => 0,
                'post_fields' => [],
                'idempotency_meta' => [],
            ];
        }

        $meta_state = [];
        foreach ($idempotency_meta as $meta_key => $unused_meta_value) {
            if (! is_string($meta_key) || $meta_key === '') {
                continue;
            }
            $meta_state[$meta_key] = get_post_meta($target_entity_id, $meta_key, false);
        }

        return [
            'action' => $action,
            'post_exists' => true,
            'post_id' => $target_entity_id,
            'post_fields' => [
                'post_type' => $post->post_type,
                'post_status' => $post->post_status,
                'post_title' => $post->post_title,
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
                'post_name' => $post->post_name,
                'menu_order' => (int) $post->menu_order,
                'post_parent' => (int) $post->post_parent,
            ],
            'idempotency_meta' => $meta_state,
        ];
    }

    /**
     * @param string               $action
     * @param int                  $target_entity_id
     * @param array<string, mixed> $before_state
     * @return true|WP_Error
     */
    private function dbvc_cc_restore_entity_state($action, $target_entity_id, array $before_state = [])
    {
        $action = sanitize_key((string) $action);
        $target_entity_id = absint($target_entity_id);
        if ($target_entity_id <= 0) {
            return true;
        }

        if (empty($before_state['post_exists'])) {
            wp_delete_post($target_entity_id, true);

            return true;
        }

        $post_fields = isset($before_state['post_fields']) && is_array($before_state['post_fields'])
            ? $before_state['post_fields']
            : [];
        $result = wp_update_post(
            wp_slash([
                'ID' => $target_entity_id,
                'post_type' => isset($post_fields['post_type']) ? sanitize_key((string) $post_fields['post_type']) : get_post_type($target_entity_id),
                'post_status' => isset($post_fields['post_status']) ? sanitize_key((string) $post_fields['post_status']) : 'draft',
                'post_title' => isset($post_fields['post_title']) ? (string) $post_fields['post_title'] : '',
                'post_content' => isset($post_fields['post_content']) ? (string) $post_fields['post_content'] : '',
                'post_excerpt' => isset($post_fields['post_excerpt']) ? (string) $post_fields['post_excerpt'] : '',
                'post_name' => isset($post_fields['post_name']) ? sanitize_title((string) $post_fields['post_name']) : '',
                'menu_order' => isset($post_fields['menu_order']) ? (int) $post_fields['menu_order'] : 0,
                'post_parent' => isset($post_fields['post_parent']) ? (int) $post_fields['post_parent'] : 0,
            ]),
            true,
            false
        );
        if (is_wp_error($result)) {
            return $result;
        }

        $meta_state = isset($before_state['idempotency_meta']) && is_array($before_state['idempotency_meta'])
            ? $before_state['idempotency_meta']
            : [];
        foreach ($meta_state as $meta_key => $values) {
            $restore = $this->dbvc_cc_restore_post_meta_values_exact($target_entity_id, (string) $meta_key, is_array($values) ? $values : []);
            if (is_wp_error($restore)) {
                return $restore;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $action
     * @return true|WP_Error
     */
    private function dbvc_cc_restore_run_action(array $action)
    {
        $stage = isset($action['stage']) ? sanitize_key((string) $action['stage']) : '';
        $action_type = isset($action['action_type']) ? sanitize_key((string) $action['action_type']) : '';
        $target_object_type = isset($action['target_object_type']) ? sanitize_key((string) $action['target_object_type']) : '';
        $target_object_id = isset($action['target_object_id']) ? absint($action['target_object_id']) : 0;
        $before_state = isset($action['before_state']) && is_array($action['before_state'])
            ? $action['before_state']
            : [];
        $after_state = isset($action['after_state']) && is_array($action['after_state'])
            ? $action['after_state']
            : [];

        if ($stage === 'entity' || in_array($action_type, ['create_post', 'update_post'], true)) {
            if ($target_object_type !== 'post') {
                return new WP_Error(
                    'dbvc_cc_rollback_unsupported_entity_type',
                    __('Rollback only supports post entity actions in the current phase.', 'dbvc'),
                    ['status' => 500]
                );
            }

            if ($target_object_id <= 0 && isset($after_state['post_id'])) {
                $target_object_id = absint($after_state['post_id']);
            }

            return $this->dbvc_cc_restore_entity_state($action_type, $target_object_id, $before_state);
        }

        if ($stage === 'field' || $action_type === 'update_field') {
            if ($target_object_type !== 'post') {
                return new WP_Error(
                    'dbvc_cc_rollback_unsupported_field_type',
                    __('Rollback only supports post field actions in the current phase.', 'dbvc'),
                    ['status' => 500]
                );
            }

            if ($target_object_id <= 0 && isset($before_state['post_id'])) {
                $target_object_id = absint($before_state['post_id']);
            }
            if ($target_object_id <= 0 && isset($after_state['post_id'])) {
                $target_object_id = absint($after_state['post_id']);
            }

            return $this->dbvc_cc_restore_field_state($target_object_id, $before_state);
        }

        if ($stage === 'media' || in_array($action_type, ['set_featured_image_from_remote', 'update_post_meta_media', 'update_acf_media', 'update_post_meta_media_url', 'update_acf_media_url'], true)) {
            if ($target_object_type !== 'post') {
                return new WP_Error(
                    'dbvc_cc_rollback_unsupported_media_type',
                    __('Rollback only supports post media actions in the current phase.', 'dbvc'),
                    ['status' => 500]
                );
            }

            if ($target_object_id <= 0 && isset($before_state['post_id'])) {
                $target_object_id = absint($before_state['post_id']);
            }
            if ($target_object_id <= 0 && isset($after_state['post_id'])) {
                $target_object_id = absint($after_state['post_id']);
            }

            return $this->dbvc_cc_restore_media_state($target_object_id, $before_state, $after_state);
        }

        return new WP_Error(
            'dbvc_cc_rollback_unsupported_action',
            __('Rollback action type is not supported.', 'dbvc'),
            ['status' => 500]
        );
    }

    /**
     * @param int    $post_id
     * @param string $target_family
     * @param string $target_field_key
     * @param string $action
     * @return array<string, mixed>
     */
    private function dbvc_cc_capture_field_state($post_id, $target_family, $target_field_key, $action)
    {
        $post_id = absint($post_id);
        $target_family = sanitize_key((string) $target_family);
        $target_field_key = sanitize_key((string) $target_field_key);
        $action = sanitize_key((string) $action);

        if ($post_id <= 0 || ! (get_post($post_id) instanceof WP_Post)) {
            return [
                'post_id' => 0,
                'target_family' => $target_family,
                'target_field_key' => $target_field_key,
                'action' => $action,
                'value' => null,
            ];
        }

        if ($target_family === 'core' && $action === 'update_post_field') {
            $post = get_post($post_id);
            $value = null;
            if ($post instanceof WP_Post) {
                if ($target_field_key === 'post_title') {
                    $value = $post->post_title;
                } elseif ($target_field_key === 'post_content') {
                    $value = $post->post_content;
                } elseif ($target_field_key === 'post_excerpt') {
                    $value = $post->post_excerpt;
                } elseif ($target_field_key === 'post_name') {
                    $value = $post->post_name;
                } elseif ($target_field_key === 'menu_order') {
                    $value = (int) $post->menu_order;
                }
            }

            return [
                'post_id' => $post_id,
                'target_family' => $target_family,
                'target_field_key' => $target_field_key,
                'action' => $action,
                'value' => $value,
            ];
        }

        return [
            'post_id' => $post_id,
            'target_family' => $target_family,
            'target_field_key' => $target_field_key,
            'action' => $action,
            'value' => $this->dbvc_cc_capture_post_meta_exact($post_id, $target_field_key),
        ];
    }

    /**
     * @param int    $post_id
     * @param string $meta_key
     * @return array<string, mixed>
     */
    private function dbvc_cc_capture_post_meta_exact($post_id, $meta_key)
    {
        $post_id = absint($post_id);
        $meta_key = sanitize_key((string) $meta_key);
        if ($post_id <= 0 || $meta_key === '') {
            return [
                'exact_meta_state' => true,
                'exists' => false,
                'is_single' => false,
                'registered_meta_type' => '',
                'values' => [],
            ];
        }

        $post = get_post($post_id);
        $registered_meta = ($post instanceof WP_Post && function_exists('get_registered_meta_keys'))
            ? get_registered_meta_keys('post', $post->post_type)
            : [];
        $is_single = false;
        $registered_meta_type = '';
        if (is_array($registered_meta) && isset($registered_meta[$meta_key]) && is_array($registered_meta[$meta_key]) && array_key_exists('single', $registered_meta[$meta_key])) {
            $is_single = (bool) $registered_meta[$meta_key]['single'];
            $registered_meta_type = isset($registered_meta[$meta_key]['type']) ? sanitize_key((string) $registered_meta[$meta_key]['type']) : '';
        }

        $meta_exists = metadata_exists('post', $post_id, $meta_key);
        $values = [];
        if ($meta_exists) {
            if ($is_single) {
                $values[] = get_post_meta($post_id, $meta_key, true);
            } else {
                $captured_values = get_post_meta($post_id, $meta_key, false);
                $values = is_array($captured_values)
                    ? array_values($captured_values)
                    : [$captured_values];
            }
        }

        return [
            'exact_meta_state' => true,
            'exists' => $meta_exists,
            'is_single' => $is_single,
            'registered_meta_type' => $registered_meta_type,
            'values' => $values,
        ];
    }

    /**
     * @param int                  $post_id
     * @param array<string, mixed> $state
     * @return true|WP_Error
     */
    private function dbvc_cc_restore_field_state($post_id, array $state = [])
    {
        $post_id = absint($post_id);
        if ($post_id <= 0) {
            return true;
        }

        $target_family = isset($state['target_family']) ? sanitize_key((string) $state['target_family']) : '';
        $target_field_key = isset($state['target_field_key']) ? sanitize_key((string) $state['target_field_key']) : '';
        $action = isset($state['action']) ? sanitize_key((string) $state['action']) : '';
        $value = array_key_exists('value', $state) ? $state['value'] : null;

        if ($target_family === 'core' && $action === 'update_post_field') {
            return $this->dbvc_cc_apply_field_value_to_post($post_id, $target_family, $target_field_key, $action, $value);
        }

        return $this->dbvc_cc_restore_post_meta_values_exact(
            $post_id,
            $target_field_key,
            is_array($value) ? $value : []
        );
    }

    /**
     * @param int                  $post_id
     * @param string               $target_family
     * @param string               $target_field_key
     * @param string               $action
     * @param array<string, mixed> $attachment_state
     * @return array<string, mixed>
     */
    private function dbvc_cc_capture_media_state($post_id, $target_family, $target_field_key, $action, array $attachment_state = [])
    {
        $post_id = absint($post_id);
        $target_family = sanitize_key((string) $target_family);
        $target_field_key = sanitize_key((string) $target_field_key);
        $action = sanitize_key((string) $action);

        if ($post_id <= 0 || ! (get_post($post_id) instanceof WP_Post)) {
            return [
                'post_id' => 0,
                'target_family' => $target_family,
                'target_field_key' => $target_field_key,
                'action' => $action,
                'value' => null,
                'attachment' => $this->dbvc_cc_normalize_media_attachment_state($attachment_state),
            ];
        }

        if ($target_family === 'core' && $action === 'set_featured_image_from_remote') {
            return [
                'post_id' => $post_id,
                'target_family' => $target_family,
                'target_field_key' => $target_field_key,
                'action' => $action,
                'value' => get_post_thumbnail_id($post_id),
                'attachment' => $this->dbvc_cc_normalize_media_attachment_state($attachment_state),
            ];
        }

        return [
            'post_id' => $post_id,
            'target_family' => $target_family,
            'target_field_key' => $target_field_key,
            'action' => $action,
            'value' => $this->dbvc_cc_capture_post_meta_exact($post_id, $target_field_key),
            'attachment' => $this->dbvc_cc_normalize_media_attachment_state($attachment_state),
        ];
    }

    /**
     * @param array<string, mixed> $attachment_state
     * @return array<string, mixed>
     */
    private function dbvc_cc_normalize_media_attachment_state(array $attachment_state = [])
    {
        $attachment_ids = isset($attachment_state['attachment_ids']) && is_array($attachment_state['attachment_ids'])
            ? array_values(array_filter(array_map('absint', $attachment_state['attachment_ids'])))
            : [];
        $created_attachment_ids = isset($attachment_state['created_attachment_ids']) && is_array($attachment_state['created_attachment_ids'])
            ? array_values(array_filter(array_map('absint', $attachment_state['created_attachment_ids'])))
            : [];
        $source_urls = isset($attachment_state['source_urls']) && is_array($attachment_state['source_urls'])
            ? array_values(array_filter(array_map('esc_url_raw', $attachment_state['source_urls'])))
            : [];

        return [
            'attachment_id' => isset($attachment_state['attachment_id']) ? absint($attachment_state['attachment_id']) : (! empty($attachment_ids) ? absint($attachment_ids[0]) : 0),
            'attachment_ids' => $attachment_ids,
            'created_attachment' => ! empty($attachment_state['created_attachment']) || ! empty($created_attachment_ids),
            'created_attachment_ids' => $created_attachment_ids,
            'source_url' => isset($attachment_state['source_url']) ? esc_url_raw((string) $attachment_state['source_url']) : (! empty($source_urls) ? (string) $source_urls[0] : ''),
            'source_urls' => $source_urls,
        ];
    }

    /**
     * @param int                  $post_id
     * @param array<string, mixed> $before_state
     * @param array<string, mixed> $after_state
     * @return true|WP_Error
     */
    private function dbvc_cc_restore_media_state($post_id, array $before_state = [], array $after_state = [])
    {
        $post_id = absint($post_id);
        if ($post_id > 0) {
            $target_family = isset($before_state['target_family']) ? sanitize_key((string) $before_state['target_family']) : '';
            $target_field_key = isset($before_state['target_field_key']) ? sanitize_key((string) $before_state['target_field_key']) : '';
            $action = isset($before_state['action']) ? sanitize_key((string) $before_state['action']) : '';
            $value = array_key_exists('value', $before_state) ? $before_state['value'] : null;

            if ($target_family === 'core' && $action === 'set_featured_image_from_remote') {
                $previous_attachment_id = absint($value);
                if ($previous_attachment_id > 0) {
                    $thumbnail_result = set_post_thumbnail($post_id, $previous_attachment_id);
                    if (! $thumbnail_result) {
                        return new WP_Error(
                            'dbvc_cc_restore_featured_image_failed',
                            __('Featured image could not be restored during rollback.', 'dbvc'),
                            ['status' => 500]
                        );
                    }
                } else {
                    delete_post_thumbnail($post_id);
                }
            } elseif ($target_field_key !== '') {
                $restore_result = $this->dbvc_cc_restore_post_meta_values_exact(
                    $post_id,
                    $target_field_key,
                    is_array($value) ? $value : []
                );
                if (is_wp_error($restore_result)) {
                    return $restore_result;
                }
            }
        }

        $attachment_state = isset($after_state['attachment']) && is_array($after_state['attachment'])
            ? $after_state['attachment']
            : [];
        $created_attachment_ids = isset($attachment_state['created_attachment_ids']) && is_array($attachment_state['created_attachment_ids'])
            ? array_values(array_filter(array_map('absint', $attachment_state['created_attachment_ids'])))
            : [];
        if (empty($created_attachment_ids) && ! empty($attachment_state['created_attachment']) && ! empty($attachment_state['attachment_id'])) {
            $created_attachment_ids[] = absint($attachment_state['attachment_id']);
        }
        foreach ($created_attachment_ids as $attachment_id) {
            if ($attachment_id > 0 && get_post($attachment_id) instanceof WP_Post) {
                wp_delete_attachment($attachment_id, true);
            }
        }

        return true;
    }

    /**
     * @param int                  $post_id
     * @param string               $meta_key
     * @param array<int|string, mixed> $values
     * @return true|WP_Error
     */
    private function dbvc_cc_restore_post_meta_values_exact($post_id, $meta_key, array $values = [])
    {
        $post_id = absint($post_id);
        $meta_key = sanitize_key((string) $meta_key);
        if ($post_id <= 0 || $meta_key === '') {
            return new WP_Error(
                'dbvc_cc_invalid_meta_restore_target',
                __('Meta restore target is invalid.', 'dbvc'),
                ['status' => 500]
            );
        }

        $post = get_post($post_id);
        $registered_meta = ($post instanceof WP_Post && function_exists('get_registered_meta_keys'))
            ? get_registered_meta_keys('post', $post->post_type)
            : [];
        $is_single = false;
        $registered_meta_type = '';
        $meta_exists = ! empty($values);
        $restore_values = array_values($values);

        if (isset($values['exact_meta_state'])) {
            $meta_exists = ! empty($values['exists']);
            $is_single = ! empty($values['is_single']);
            $registered_meta_type = isset($values['registered_meta_type']) ? sanitize_key((string) $values['registered_meta_type']) : '';
            $restore_values = isset($values['values']) && is_array($values['values'])
                ? array_values($values['values'])
                : [];
        } elseif (is_array($registered_meta) && isset($registered_meta[$meta_key]) && is_array($registered_meta[$meta_key])) {
            $is_single = array_key_exists('single', $registered_meta[$meta_key]) ? (bool) $registered_meta[$meta_key]['single'] : false;
            $registered_meta_type = isset($registered_meta[$meta_key]['type']) ? sanitize_key((string) $registered_meta[$meta_key]['type']) : '';
        }

        delete_post_meta($post_id, $meta_key);
        if (! $meta_exists) {
            return true;
        }

        if ($is_single) {
            $restore_value = $registered_meta_type === 'array'
                ? (isset($restore_values[0]) && is_array($restore_values[0])
                    ? array_values($restore_values[0])
                    : array_values($restore_values))
                : reset($restore_values);
            update_post_meta($post_id, $meta_key, $restore_value);
            return true;
        }

        foreach ($restore_values as $meta_value) {
            add_post_meta($post_id, $meta_key, $meta_value, false);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function dbvc_cc_get_empty_preflight_approval()
    {
        return [
            'approval_id' => '',
            'approval_token' => '',
            'approved_at' => '',
            'expires_at' => '',
            'expires_in' => 0,
            'approved_by' => 0,
            'context_fingerprint' => '',
        ];
    }

    /**
     * @param array<string, mixed> $dry_run_execution
     * @param array<string, mixed> $write_preparation
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_preflight_approval_context(array $dry_run_execution, array $write_preparation)
    {
        $trace = isset($dry_run_execution['trace']) && is_array($dry_run_execution['trace'])
            ? $dry_run_execution['trace']
            : [];
        $artifact_refs = isset($trace['artifact_refs']) && is_array($trace['artifact_refs'])
            ? $trace['artifact_refs']
            : [];
        ksort($artifact_refs);

        $dry_run_plan = isset($dry_run_execution['dry_run_plan']) && is_array($dry_run_execution['dry_run_plan'])
            ? $dry_run_execution['dry_run_plan']
            : [];
        $phase4_context = $this->dbvc_cc_build_phase4_context($dry_run_plan);
        $phase4_input = isset($dry_run_plan['phase4_input']) && is_array($dry_run_plan['phase4_input'])
            ? $dry_run_plan['phase4_input']
            : [];

        return [
            'domain' => isset($dry_run_execution['domain']) ? sanitize_text_field((string) $dry_run_execution['domain']) : '',
            'path' => isset($dry_run_execution['path']) ? sanitize_text_field((string) $dry_run_execution['path']) : '',
            'source_url' => isset($dry_run_execution['source_url']) ? esc_url_raw((string) $dry_run_execution['source_url']) : '',
            'dry_run_execution_id' => isset($dry_run_execution['execution_id']) ? sanitize_key((string) $dry_run_execution['execution_id']) : '',
            'plan_id' => isset($dry_run_execution['plan_id']) ? sanitize_key((string) $dry_run_execution['plan_id']) : '',
            'graph_fingerprint' => isset($dry_run_execution['operation_graph']['graph_fingerprint'])
                ? sanitize_text_field((string) $dry_run_execution['operation_graph']['graph_fingerprint'])
                : '',
            'write_plan_id' => isset($write_preparation['write_plan_id']) ? sanitize_key((string) $write_preparation['write_plan_id']) : '',
            'default_entity_key' => isset($phase4_context['default_entity_key']) ? sanitize_text_field((string) $phase4_context['default_entity_key']) : 'post:page',
            'default_entity_reason' => isset($phase4_context['default_entity_reason']) ? sanitize_key((string) $phase4_context['default_entity_reason']) : '',
            'override_post_type' => isset($phase4_context['override_post_type']) ? sanitize_key((string) $phase4_context['override_post_type']) : '',
            'handoff_schema_version' => isset($phase4_context['handoff_schema_version']) ? sanitize_text_field((string) $phase4_context['handoff_schema_version']) : '',
            'handoff_generated_at' => isset($phase4_context['handoff_generated_at']) ? sanitize_text_field((string) $phase4_context['handoff_generated_at']) : '',
            'catalog_fingerprint' => isset($phase4_input['catalog_fingerprint']) ? sanitize_text_field((string) $phase4_input['catalog_fingerprint']) : '',
            'source_pipeline_id' => isset($trace['source_pipeline_id']) ? sanitize_text_field((string) $trace['source_pipeline_id']) : '',
            'artifact_refs' => $artifact_refs,
            'source_freshness_fingerprint' => $this->dbvc_cc_build_preflight_source_freshness_fingerprint($dry_run_execution),
        ];
    }

    /**
     * @param array<string, mixed> $dry_run_execution
     * @return string
     */
    private function dbvc_cc_build_preflight_source_freshness_fingerprint(array $dry_run_execution)
    {
        $dry_run_plan = isset($dry_run_execution['dry_run_plan']) && is_array($dry_run_execution['dry_run_plan'])
            ? $dry_run_execution['dry_run_plan']
            : [];
        $trace = isset($dry_run_execution['trace']) && is_array($dry_run_execution['trace'])
            ? $dry_run_execution['trace']
            : [];
        $artifact_refs = isset($trace['artifact_refs']) && is_array($trace['artifact_refs'])
            ? $trace['artifact_refs']
            : [];
        ksort($artifact_refs);

        $phase4_input = isset($dry_run_plan['phase4_input']) && is_array($dry_run_plan['phase4_input'])
            ? $dry_run_plan['phase4_input']
            : [];

        return 'dbvc_cc_src_' . substr(hash('sha256', wp_json_encode([
            'domain' => isset($dry_run_execution['domain']) ? sanitize_text_field((string) $dry_run_execution['domain']) : '',
            'path' => isset($dry_run_execution['path']) ? sanitize_text_field((string) $dry_run_execution['path']) : '',
            'dry_run_execution_id' => isset($dry_run_execution['execution_id']) ? sanitize_key((string) $dry_run_execution['execution_id']) : '',
            'plan_id' => isset($dry_run_execution['plan_id']) ? sanitize_key((string) $dry_run_execution['plan_id']) : '',
            'graph_fingerprint' => isset($dry_run_execution['operation_graph']['graph_fingerprint'])
                ? sanitize_text_field((string) $dry_run_execution['operation_graph']['graph_fingerprint'])
                : '',
            'handoff_generated_at' => isset($dry_run_plan['handoff_generated_at']) ? sanitize_text_field((string) $dry_run_plan['handoff_generated_at']) : '',
            'plan_generated_at' => isset($dry_run_plan['generated_at']) ? sanitize_text_field((string) $dry_run_plan['generated_at']) : '',
            'catalog_fingerprint' => isset($phase4_input['catalog_fingerprint']) ? sanitize_text_field((string) $phase4_input['catalog_fingerprint']) : '',
            'source_pipeline_id' => isset($trace['source_pipeline_id']) ? sanitize_text_field((string) $trace['source_pipeline_id']) : '',
            'artifact_refs' => $artifact_refs,
        ])), 0, 16);
    }

    /**
     * @param array<string, mixed> $dry_run_execution
     * @param array<string, mixed> $write_preparation
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_preflight_approval_summary(array $dry_run_execution, array $write_preparation)
    {
        $dry_run_plan = isset($dry_run_execution['dry_run_plan']) && is_array($dry_run_execution['dry_run_plan'])
            ? $dry_run_execution['dry_run_plan']
            : [];
        $phase4_context = $this->dbvc_cc_build_phase4_context($dry_run_plan);

        return [
            'domain' => isset($dry_run_execution['domain']) ? sanitize_text_field((string) $dry_run_execution['domain']) : '',
            'path' => isset($dry_run_execution['path']) ? sanitize_text_field((string) $dry_run_execution['path']) : '',
            'default_entity_key' => isset($phase4_context['default_entity_key']) ? sanitize_text_field((string) $phase4_context['default_entity_key']) : 'post:page',
            'default_entity_reason' => isset($phase4_context['default_entity_reason']) ? sanitize_key((string) $phase4_context['default_entity_reason']) : '',
            'override_post_type' => isset($phase4_context['override_post_type']) ? sanitize_key((string) $phase4_context['override_post_type']) : '',
            'handoff_schema_version' => isset($phase4_context['handoff_schema_version']) ? sanitize_text_field((string) $phase4_context['handoff_schema_version']) : '',
            'graph_fingerprint' => isset($dry_run_execution['operation_graph']['graph_fingerprint'])
                ? sanitize_text_field((string) $dry_run_execution['operation_graph']['graph_fingerprint'])
                : '',
            'write_plan_id' => isset($write_preparation['write_plan_id']) ? sanitize_key((string) $write_preparation['write_plan_id']) : '',
            'entity_updates' => isset($dry_run_execution['operation_counts']['entity_updates'])
                ? absint($dry_run_execution['operation_counts']['entity_updates'])
                : 0,
            'entity_creates' => isset($dry_run_execution['operation_counts']['entity_creates'])
                ? absint($dry_run_execution['operation_counts']['entity_creates'])
                : 0,
            'entity_blocked' => isset($dry_run_execution['operation_counts']['entity_blocked'])
                ? absint($dry_run_execution['operation_counts']['entity_blocked'])
                : 0,
            'field_writes' => isset($write_preparation['operation_counts']['field_writes'])
                ? absint($write_preparation['operation_counts']['field_writes'])
                : 0,
            'media_writes' => isset($write_preparation['operation_counts']['media_writes'])
                ? absint($write_preparation['operation_counts']['media_writes'])
                : 0,
            'deferred_media_count' => isset($write_preparation['operation_counts']['deferred_media_writes'])
                ? absint($write_preparation['operation_counts']['deferred_media_writes'])
                : 0,
            'deferred_media_reasons' => isset($write_preparation['deferred_media_reasons']) && is_array($write_preparation['deferred_media_reasons'])
                ? array_values($write_preparation['deferred_media_reasons'])
                : [],
            'blocking_issue_count' => isset($dry_run_execution['blocking_issue_count'])
                ? absint($dry_run_execution['blocking_issue_count'])
                : 0,
            'write_barrier_count' => isset($write_preparation['write_barriers']) && is_array($write_preparation['write_barriers'])
                ? count(array_filter($write_preparation['write_barriers'], static function ($barrier) {
                    return is_array($barrier) && ! empty($barrier['blocking']);
                }))
                : 0,
        ];
    }

    /**
     * @param array<string, mixed> $approval_context
     * @param array<string, mixed> $approval_summary
     * @return array<string, mixed>
     */
    private function dbvc_cc_create_preflight_approval(array $approval_context, array $approval_summary)
    {
        $approval_token = wp_generate_password(48, false, false);
        $approval_token_hash = hash('sha256', $approval_token);
        $approval_id = 'dbvc_cc_appr_' . substr($approval_token_hash, 0, 16);
        $ttl = self::DBVC_CC_PREFLIGHT_APPROVAL_TTL;
        $approved_at_timestamp = current_time('timestamp', true);
        $expires_at_timestamp = $approved_at_timestamp + $ttl;
        $context_fingerprint = hash('sha256', wp_json_encode($approval_context));

        $record = [
            'approval_schema_version' => self::DBVC_CC_PREFLIGHT_APPROVAL_SCHEMA_VERSION,
            'approval_id' => $approval_id,
            'token_hash' => $approval_token_hash,
            'approved_at' => current_time('c'),
            'approved_at_timestamp' => $approved_at_timestamp,
            'expires_at' => wp_date('c', $expires_at_timestamp),
            'expires_at_timestamp' => $expires_at_timestamp,
            'approved_by' => get_current_user_id(),
            'context_fingerprint' => $context_fingerprint,
            'context' => $approval_context,
            'summary' => $approval_summary,
        ];

        set_transient($this->dbvc_cc_get_preflight_approval_transient_key($approval_token_hash), $record, $ttl);

        return [
            'approval_id' => $approval_id,
            'approval_token' => $approval_token,
            'approved_at' => $record['approved_at'],
            'expires_at' => $record['expires_at'],
            'expires_in' => $ttl,
            'approved_by' => absint($record['approved_by']),
            'context_fingerprint' => $context_fingerprint,
        ];
    }

    /**
     * @param string               $approval_token
     * @param array<string, mixed> $approval_context
     * @return array<string, mixed>
     */
    private function dbvc_cc_validate_preflight_approval_token($approval_token, array $approval_context)
    {
        $approval_token = sanitize_text_field((string) $approval_token);
        if ($approval_token === '') {
            return [
                'status' => 'missing',
                'approval_valid' => false,
                'approval' => $this->dbvc_cc_get_empty_preflight_approval(),
                'guard_failure' => $this->dbvc_cc_build_guard_failure(
                    'preflight_approval_required',
                    __('A fresh preflight approval is required before write execution can begin.', 'dbvc'),
                    true
                ),
            ];
        }

        $approval_token_hash = hash('sha256', $approval_token);
        $record = get_transient($this->dbvc_cc_get_preflight_approval_transient_key($approval_token_hash));
        if (! is_array($record) || empty($record['approval_id'])) {
            return [
                'status' => 'invalid_or_expired',
                'approval_valid' => false,
                'approval' => $this->dbvc_cc_get_empty_preflight_approval(),
                'guard_failure' => $this->dbvc_cc_build_guard_failure(
                    'preflight_approval_invalid_or_expired',
                    __('Preflight approval is missing, invalid, or expired. Run approval again from the latest dry-run.', 'dbvc'),
                    true
                ),
            ];
        }

        if (isset($record['approved_by']) && absint($record['approved_by']) > 0 && absint($record['approved_by']) !== get_current_user_id()) {
            return [
                'status' => 'user_mismatch',
                'approval_valid' => false,
                'approval' => $this->dbvc_cc_build_preflight_approval_response_from_record($record),
                'guard_failure' => $this->dbvc_cc_build_guard_failure(
                    'preflight_approval_user_mismatch',
                    __('Preflight approval belongs to a different user and cannot be reused for this execution.', 'dbvc'),
                    true
                ),
            ];
        }

        $current_context_fingerprint = hash('sha256', wp_json_encode($approval_context));
        $stored_context_fingerprint = isset($record['context_fingerprint']) ? sanitize_text_field((string) $record['context_fingerprint']) : '';
        if ($stored_context_fingerprint === '' || $stored_context_fingerprint !== $current_context_fingerprint) {
            return [
                'status' => 'stale',
                'approval_valid' => false,
                'approval' => $this->dbvc_cc_build_preflight_approval_response_from_record($record),
                'guard_failure' => $this->dbvc_cc_build_guard_failure(
                    'preflight_approval_stale',
                    __('Preflight approval is stale because the current dry-run fingerprint no longer matches the approved plan.', 'dbvc'),
                    true
                ),
            ];
        }

        return [
            'status' => 'approved',
            'approval_valid' => true,
            'approval' => $this->dbvc_cc_build_preflight_approval_response_from_record($record),
            'guard_failure' => null,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_preflight_approval_response_from_record(array $record)
    {
        $expires_at_timestamp = isset($record['expires_at_timestamp']) ? absint($record['expires_at_timestamp']) : 0;
        $expires_in = 0;
        if ($expires_at_timestamp > 0) {
            $expires_in = max(0, $expires_at_timestamp - current_time('timestamp', true));
        }

        return [
            'approval_id' => isset($record['approval_id']) ? sanitize_key((string) $record['approval_id']) : '',
            'approval_token' => '',
            'approved_at' => isset($record['approved_at']) ? sanitize_text_field((string) $record['approved_at']) : '',
            'expires_at' => isset($record['expires_at']) ? sanitize_text_field((string) $record['expires_at']) : '',
            'expires_in' => $expires_in,
            'approved_by' => isset($record['approved_by']) ? absint($record['approved_by']) : 0,
            'context_fingerprint' => isset($record['context_fingerprint']) ? sanitize_text_field((string) $record['context_fingerprint']) : '',
        ];
    }

    /**
     * @param string $approval_token_hash
     * @return string
     */
    private function dbvc_cc_get_preflight_approval_transient_key($approval_token_hash)
    {
        return self::DBVC_CC_PREFLIGHT_APPROVAL_TRANSIENT_PREFIX . substr(hash('sha256', sanitize_text_field((string) $approval_token_hash)), 0, 40);
    }

    /**
     * @param string $code
     * @param string $message
     * @param bool   $blocking
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_guard_failure($code, $message, $blocking)
    {
        return [
            'code' => sanitize_key((string) $code),
            'message' => sanitize_text_field((string) $message),
            'blocking' => (bool) $blocking,
        ];
    }

    /**
     * @param string $code
     * @param string $message
     * @param bool   $blocking
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_issue($code, $message, $blocking)
    {
        return [
            'code' => sanitize_key((string) $code),
            'message' => sanitize_text_field((string) $message),
            'blocking' => (bool) $blocking,
        ];
    }

    /**
     * @param string                    $domain
     * @param string                    $path
     * @param string                    $source_url
     * @param string                         $status
     * @param array<int, array<string, mixed>> $guard_failures
     * @param array<string, mixed>             $dbvc_cc_phase4_context
     * @param array<int, array<string, mixed>> $write_barriers
     * @return void
     */
    private function dbvc_cc_log_write_skeleton_event($domain, $path, $source_url, $status, array $guard_failures, array $dbvc_cc_phase4_context = [], array $write_barriers = [])
    {
        if (! class_exists('DBVC_CC_Artifact_Manager')) {
            return;
        }

        $event_url = $source_url;
        if ($event_url === '' && $domain !== '') {
            $event_url = 'https://' . $domain . '/' . ltrim($path, '/');
        }

        $dbvc_cc_default_entity_key = isset($dbvc_cc_phase4_context['default_entity_key'])
            ? sanitize_text_field((string) $dbvc_cc_phase4_context['default_entity_key'])
            : 'post:page';
        $dbvc_cc_guard_failure_codes = array_values(array_filter(array_map(
            static function ($dbvc_cc_failure): string {
                if (! is_array($dbvc_cc_failure) || ! isset($dbvc_cc_failure['code'])) {
                    return '';
                }

                return sanitize_key((string) $dbvc_cc_failure['code']);
            },
            $guard_failures
        )));
        $dbvc_cc_write_barrier_codes = array_values(array_filter(array_map(
            static function ($dbvc_cc_barrier): string {
                if (! is_array($dbvc_cc_barrier) || ! isset($dbvc_cc_barrier['code'])) {
                    return '';
                }

                return sanitize_key((string) $dbvc_cc_barrier['code']);
            },
            $write_barriers
        )));

        $message = sprintf(
            'Execute path returned %1$s (%2$d guard failures, %3$d write barriers, default entity %4$s).',
            sanitize_key((string) $status),
            count($guard_failures),
            count($write_barriers),
            $dbvc_cc_default_entity_key
        );

        DBVC_CC_Artifact_Manager::log_event(
            [
                'stage' => 'import_executor',
                'status' => sanitize_key((string) $status),
                'page_url' => esc_url_raw($event_url),
                'path' => sanitize_text_field((string) $path),
                'message' => $message,
                'default_entity_key' => $dbvc_cc_default_entity_key,
                'default_entity_reason' => isset($dbvc_cc_phase4_context['default_entity_reason'])
                    ? sanitize_key((string) $dbvc_cc_phase4_context['default_entity_reason'])
                    : '',
                'override_post_type' => isset($dbvc_cc_phase4_context['override_post_type'])
                    ? sanitize_key((string) $dbvc_cc_phase4_context['override_post_type'])
                    : '',
                'handoff_schema_version' => isset($dbvc_cc_phase4_context['handoff_schema_version'])
                    ? sanitize_text_field((string) $dbvc_cc_phase4_context['handoff_schema_version'])
                    : '',
                'guard_failure_codes' => $dbvc_cc_guard_failure_codes,
                'write_barrier_codes' => $dbvc_cc_write_barrier_codes,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dbvc_cc_get_empty_write_preparation()
    {
        return [
            'write_plan_schema_version' => self::DBVC_CC_WRITE_PLAN_SCHEMA_VERSION,
            'write_plan_id' => '',
            'entity_writes' => [],
            'field_writes' => [],
            'media_writes' => [],
            'write_barriers' => [],
            'source_context' => [
                'page' => [],
                'stats' => [
                    'section_sources' => 0,
                    'media_sources' => 0,
                ],
            ],
            'operation_counts' => [
                'entity_writes' => 0,
                'field_writes' => 0,
                'media_writes' => 0,
                'blocking_write_barriers' => 0,
                'deferred_media_writes' => 0,
            ],
            'deferred_media_reasons' => [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $media_writes
     * @return array<string, mixed>
     */
    private function dbvc_cc_collect_deferred_media_summary(array $media_writes)
    {
        $count = 0;
        $reason_counts = [];

        foreach ($media_writes as $media_write) {
            if (! is_array($media_write)) {
                continue;
            }

            if (isset($media_write['write_status']) && sanitize_key((string) $media_write['write_status']) !== 'deferred') {
                continue;
            }

            $count++;
            $reason_code = isset($media_write['deferred_reason_code']) ? sanitize_key((string) $media_write['deferred_reason_code']) : 'deferred_media';
            $reason_group = isset($media_write['deferred_reason_group']) ? sanitize_key((string) $media_write['deferred_reason_group']) : 'policy';
            $reason_message = isset($media_write['message']) ? sanitize_text_field((string) $media_write['message']) : __('Media write was deferred.', 'dbvc');

            if (! isset($reason_counts[$reason_code])) {
                $reason_counts[$reason_code] = [
                    'code' => $reason_code,
                    'group' => $reason_group,
                    'count' => 0,
                    'message' => $reason_message,
                ];
            }

            $reason_counts[$reason_code]['count']++;
        }

        return [
            'count' => $count,
            'reasons' => array_values($reason_counts),
        ];
    }

    /**
     * @param array<string, mixed> $dry_run_execution
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_write_preparation(array $dry_run_execution)
    {
        $write_preparation = $this->dbvc_cc_get_empty_write_preparation();
        $domain = isset($dry_run_execution['domain']) ? sanitize_text_field((string) $dry_run_execution['domain']) : '';
        $path = isset($dry_run_execution['path']) ? sanitize_text_field((string) $dry_run_execution['path']) : '';
        $source_url = isset($dry_run_execution['source_url']) ? esc_url_raw((string) $dry_run_execution['source_url']) : '';
        $write_source_context = $this->dbvc_cc_load_write_source_context($domain, $path);
        $section_source_index = $this->dbvc_cc_build_section_source_index($write_source_context);
        $media_source_index = $this->dbvc_cc_build_media_source_index($write_source_context);
        $page_source_context = $this->dbvc_cc_build_page_source_context($write_source_context, $section_source_index, $source_url);
        $operation_graph = isset($dry_run_execution['operation_graph']) && is_array($dry_run_execution['operation_graph'])
            ? $dry_run_execution['operation_graph']
            : [];
        $entity_operations = isset($operation_graph['entity_operations']) && is_array($operation_graph['entity_operations'])
            ? array_values($operation_graph['entity_operations'])
            : [];
        $field_operations = isset($operation_graph['field_operations']) && is_array($operation_graph['field_operations'])
            ? array_values($operation_graph['field_operations'])
            : [];
        $media_operations = isset($operation_graph['media_operations']) && is_array($operation_graph['media_operations'])
            ? array_values($operation_graph['media_operations'])
            : [];

        $entity_write_plan = $this->dbvc_cc_build_entity_write_operations($domain, $path, $source_url, $entity_operations, $page_source_context);
        $field_write_plan = $this->dbvc_cc_build_field_write_operations(
            $domain,
            $path,
            $field_operations,
            isset($entity_write_plan['entity_index']) && is_array($entity_write_plan['entity_index'])
                ? $entity_write_plan['entity_index']
                : [],
            $section_source_index
        );
        $media_write_plan = $this->dbvc_cc_build_media_write_operations(
            $domain,
            $path,
            $media_operations,
            isset($entity_write_plan['entity_index']) && is_array($entity_write_plan['entity_index'])
                ? $entity_write_plan['entity_index']
                : [],
            $media_source_index
        );

        $write_barriers = array_values(array_merge(
            isset($entity_write_plan['write_barriers']) && is_array($entity_write_plan['write_barriers']) ? $entity_write_plan['write_barriers'] : [],
            isset($field_write_plan['write_barriers']) && is_array($field_write_plan['write_barriers']) ? $field_write_plan['write_barriers'] : [],
            isset($media_write_plan['write_barriers']) && is_array($media_write_plan['write_barriers']) ? $media_write_plan['write_barriers'] : []
        ));

        $fingerprint_source = [
            'dry_run_execution_id' => isset($dry_run_execution['execution_id']) ? (string) $dry_run_execution['execution_id'] : '',
            'domain' => $domain,
            'path' => $path,
            'entity_writes' => isset($entity_write_plan['operations']) ? $entity_write_plan['operations'] : [],
            'field_writes' => isset($field_write_plan['operations']) ? $field_write_plan['operations'] : [],
            'media_writes' => isset($media_write_plan['operations']) ? $media_write_plan['operations'] : [],
        ];

        $write_preparation['write_plan_id'] = 'dbvc_cc_writeplan_' . substr(hash('sha256', wp_json_encode($fingerprint_source)), 0, 16);
        $write_preparation['entity_writes'] = isset($entity_write_plan['operations']) && is_array($entity_write_plan['operations'])
            ? array_values($entity_write_plan['operations'])
            : [];
        $write_preparation['field_writes'] = isset($field_write_plan['operations']) && is_array($field_write_plan['operations'])
            ? array_values($field_write_plan['operations'])
            : [];
        $write_preparation['media_writes'] = isset($media_write_plan['operations']) && is_array($media_write_plan['operations'])
            ? array_values($media_write_plan['operations'])
            : [];
        $write_preparation['write_barriers'] = $write_barriers;
        $deferred_media_summary = $this->dbvc_cc_collect_deferred_media_summary($write_preparation['media_writes']);
        $blocking_write_barriers = count(array_filter($write_barriers, static function ($barrier) {
            return is_array($barrier) && ! empty($barrier['blocking']);
        }));
        $write_preparation['source_context'] = [
            'page' => $page_source_context,
            'stats' => [
                'section_sources' => count($section_source_index),
                'media_sources' => count($media_source_index),
            ],
        ];
        $write_preparation['operation_counts'] = [
            'entity_writes' => count($write_preparation['entity_writes']),
            'field_writes' => count($write_preparation['field_writes']),
            'media_writes' => count($write_preparation['media_writes']),
            'blocking_write_barriers' => $blocking_write_barriers,
            'deferred_media_writes' => isset($deferred_media_summary['count']) ? absint($deferred_media_summary['count']) : 0,
        ];
        $write_preparation['deferred_media_reasons'] = isset($deferred_media_summary['reasons']) && is_array($deferred_media_summary['reasons'])
            ? array_values($deferred_media_summary['reasons'])
            : [];

        return $write_preparation;
    }

    /**
     * @param string $domain
     * @param string $path
     * @return array<string, mixed>
     */
    private function dbvc_cc_load_write_source_context($domain, $path)
    {
        $context = $this->dbvc_cc_resolve_storage_context($domain, $path);
        if (empty($context) || empty($context['page_dir']) || ! is_array($context)) {
            return [];
        }

        return [
            'domain' => isset($context['domain']) ? (string) $context['domain'] : '',
            'path' => isset($context['path']) ? (string) $context['path'] : '',
            'source_url' => isset($context['source_url']) ? (string) $context['source_url'] : '',
            'artifact' => isset($context['artifact_file']) ? $this->dbvc_cc_read_json_file((string) $context['artifact_file']) : [],
            'elements' => isset($context['elements_file']) ? $this->dbvc_cc_read_json_file((string) $context['elements_file']) : [],
            'sections' => isset($context['sections_file']) ? $this->dbvc_cc_read_json_file((string) $context['sections_file']) : [],
            'section_typing' => isset($context['section_typing_file']) ? $this->dbvc_cc_read_json_file((string) $context['section_typing_file']) : [],
            'ingestion_package' => isset($context['ingestion_package_file']) ? $this->dbvc_cc_read_json_file((string) $context['ingestion_package_file']) : [],
            'media_candidates' => isset($context['media_candidates_file']) ? $this->dbvc_cc_read_json_file((string) $context['media_candidates_file']) : [],
        ];
    }

    /**
     * @param array<string, mixed> $write_source_context
     * @return array<string, array<string, mixed>>
     */
    private function dbvc_cc_build_section_source_index(array $write_source_context)
    {
        $index = [];
        $sections_artifact = isset($write_source_context['sections']) && is_array($write_source_context['sections']) ? $write_source_context['sections'] : [];
        $elements_artifact = isset($write_source_context['elements']) && is_array($write_source_context['elements']) ? $write_source_context['elements'] : [];
        $typing_artifact = isset($write_source_context['section_typing']) && is_array($write_source_context['section_typing']) ? $write_source_context['section_typing'] : [];
        $ingestion_package = isset($write_source_context['ingestion_package']) && is_array($write_source_context['ingestion_package']) ? $write_source_context['ingestion_package'] : [];
        $media_candidates = isset($write_source_context['media_candidates']) && is_array($write_source_context['media_candidates']) ? $write_source_context['media_candidates'] : [];

        $elements = isset($elements_artifact['elements']) && is_array($elements_artifact['elements']) ? $elements_artifact['elements'] : [];
        $elements_by_id = [];
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }
            $element_id = isset($element['element_id']) ? sanitize_key((string) $element['element_id']) : '';
            if ($element_id === '') {
                continue;
            }
            $elements_by_id[$element_id] = $element;
        }

        $typing_by_section = [];
        $typing_rows = [];
        if (isset($typing_artifact['section_typings']) && is_array($typing_artifact['section_typings'])) {
            $typing_rows = $typing_artifact['section_typings'];
        } elseif (isset($typing_artifact['sections']) && is_array($typing_artifact['sections'])) {
            $typing_rows = $typing_artifact['sections'];
        }
        foreach ($typing_rows as $typing_row) {
            if (! is_array($typing_row)) {
                continue;
            }
            $section_id = isset($typing_row['section_id']) ? sanitize_key((string) $typing_row['section_id']) : '';
            if ($section_id === '') {
                continue;
            }
            $typing_by_section[$section_id] = $typing_row;
        }

        $ingestion_by_section = [];
        $ingestion_sections = isset($ingestion_package['sections']) && is_array($ingestion_package['sections']) ? $ingestion_package['sections'] : [];
        foreach ($ingestion_sections as $ingestion_section) {
            if (! is_array($ingestion_section)) {
                continue;
            }
            $section_id = isset($ingestion_section['section_id']) ? sanitize_key((string) $ingestion_section['section_id']) : '';
            if ($section_id === '') {
                continue;
            }
            $ingestion_by_section[$section_id] = $ingestion_section;
        }

        $media_by_section = [];
        $media_items = isset($media_candidates['media_items']) && is_array($media_candidates['media_items']) ? $media_candidates['media_items'] : [];
        foreach ($media_items as $media_item) {
            if (! is_array($media_item)) {
                continue;
            }
            $section_id = isset($media_item['source_section_id']) ? sanitize_key((string) $media_item['source_section_id']) : '';
            $media_id = isset($media_item['media_id']) ? sanitize_text_field((string) $media_item['media_id']) : '';
            if ($section_id === '' || $media_id === '') {
                continue;
            }
            if (! isset($media_by_section[$section_id])) {
                $media_by_section[$section_id] = [];
            }
            $media_by_section[$section_id][] = $media_id;
        }

        $sections = isset($sections_artifact['sections']) && is_array($sections_artifact['sections']) ? $sections_artifact['sections'] : [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $section_id = isset($section['section_id']) ? sanitize_key((string) $section['section_id']) : '';
            if ($section_id === '') {
                continue;
            }

            $element_ids = isset($section['element_ids']) && is_array($section['element_ids']) ? array_values($section['element_ids']) : [];
            $element_blocks = [];
            $heading_texts = [];
            $body_texts = [];
            $tagged_lines = [];
            $link_targets = [];
            foreach ($element_ids as $element_id) {
                $element_id = sanitize_key((string) $element_id);
                if ($element_id === '' || ! isset($elements_by_id[$element_id]) || ! is_array($elements_by_id[$element_id])) {
                    continue;
                }

                $element = $elements_by_id[$element_id];
                $tag = isset($element['tag']) ? sanitize_key((string) $element['tag']) : '';
                $text = isset($element['text']) ? sanitize_text_field((string) $element['text']) : '';
                if ($text === '') {
                    continue;
                }

                $element_blocks[] = [
                    'element_id' => $element_id,
                    'tag' => $tag,
                    'text' => $text,
                    'sequence_index' => isset($element['sequence_index']) ? absint($element['sequence_index']) : 0,
                    'link_target' => isset($element['link_target']) ? esc_url_raw((string) $element['link_target']) : '',
                ];
                $tagged_lines[] = '<' . ($tag !== '' ? $tag : 'text') . '> ' . $text;

                if (preg_match('/^h[1-6]$/', $tag) === 1) {
                    $heading_texts[] = $text;
                } else {
                    $body_texts[] = $text;
                }

                if (! empty($element['link_target'])) {
                    $link_targets[] = esc_url_raw((string) $element['link_target']);
                }
            }

            $ingestion_section = isset($ingestion_by_section[$section_id]) && is_array($ingestion_by_section[$section_id])
                ? $ingestion_by_section[$section_id]
                : [];
            $typing_row = isset($typing_by_section[$section_id]) && is_array($typing_by_section[$section_id])
                ? $typing_by_section[$section_id]
                : [];
            $sample_text = isset($ingestion_section['sample_text']) && is_array($ingestion_section['sample_text'])
                ? array_values(array_map('sanitize_text_field', $ingestion_section['sample_text']))
                : array_slice($body_texts, 0, 3);

            $index[$section_id] = [
                'section_id' => $section_id,
                'section_label' => isset($section['section_label_candidate']) ? sanitize_text_field((string) $section['section_label_candidate']) : '',
                'section_type' => isset($ingestion_section['section_type']) ? sanitize_key((string) $ingestion_section['section_type']) : (isset($typing_row['section_type_candidate']) ? sanitize_key((string) $typing_row['section_type_candidate']) : ''),
                'section_type_confidence' => isset($ingestion_section['section_type_confidence'])
                    ? (float) $ingestion_section['section_type_confidence']
                    : (isset($typing_row['confidence']) ? (float) $typing_row['confidence'] : 0.0),
                'element_ids' => array_values(array_map('sanitize_key', $element_ids)),
                'element_blocks' => $element_blocks,
                'heading_texts' => $heading_texts,
                'body_texts' => $body_texts,
                'sample_text' => $sample_text,
                'primary_text' => ! empty($heading_texts)
                    ? (string) $heading_texts[0]
                    : (! empty($body_texts) ? (string) $body_texts[0] : ''),
                'text_blob' => implode("\n\n", array_merge($heading_texts, $body_texts)),
                'tagged_text_blob' => implode("\n", $tagged_lines),
                'link_targets' => array_values(array_unique(array_filter($link_targets))),
                'section_media_ids' => isset($media_by_section[$section_id]) ? array_values(array_unique($media_by_section[$section_id])) : [],
                'evidence_element_ids' => isset($typing_row['evidence_element_ids']) && is_array($typing_row['evidence_element_ids'])
                    ? array_values(array_map('sanitize_key', $typing_row['evidence_element_ids']))
                    : [],
            ];
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $write_source_context
     * @return array<string, array<string, mixed>>
     */
    private function dbvc_cc_build_media_source_index(array $write_source_context)
    {
        $index = [];
        $media_candidates = isset($write_source_context['media_candidates']) && is_array($write_source_context['media_candidates'])
            ? $write_source_context['media_candidates']
            : [];
        $media_items = isset($media_candidates['media_items']) && is_array($media_candidates['media_items']) ? $media_candidates['media_items'] : [];
        foreach ($media_items as $media_item) {
            if (! is_array($media_item)) {
                continue;
            }

            $media_id = isset($media_item['media_id']) ? sanitize_text_field((string) $media_item['media_id']) : '';
            if ($media_id === '') {
                continue;
            }

            $index[$media_id] = [
                'media_id' => $media_id,
                'source_url' => isset($media_item['source_url']) ? esc_url_raw((string) $media_item['source_url']) : '',
                'normalized_url' => isset($media_item['normalized_url']) ? esc_url_raw((string) $media_item['normalized_url']) : '',
                'media_kind' => isset($media_item['media_kind']) ? sanitize_key((string) $media_item['media_kind']) : '',
                'mime_guess' => isset($media_item['mime_guess']) ? sanitize_text_field((string) $media_item['mime_guess']) : '',
                'alt_text' => isset($media_item['alt_text']) ? sanitize_text_field((string) $media_item['alt_text']) : '',
                'caption_text' => isset($media_item['caption_text']) ? sanitize_text_field((string) $media_item['caption_text']) : '',
                'surrounding_text_snippet' => isset($media_item['surrounding_text_snippet']) ? sanitize_text_field((string) $media_item['surrounding_text_snippet']) : '',
                'source_section_id' => isset($media_item['source_section_id']) ? sanitize_key((string) $media_item['source_section_id']) : '',
                'source_element_id' => isset($media_item['source_element_id']) ? sanitize_key((string) $media_item['source_element_id']) : '',
                'role_candidates' => isset($media_item['role_candidates']) && is_array($media_item['role_candidates'])
                    ? array_values(array_map('sanitize_key', $media_item['role_candidates']))
                    : [],
                'preview_ref' => isset($media_item['preview_ref']) ? esc_url_raw((string) $media_item['preview_ref']) : '',
                'preview_status' => isset($media_item['preview_status']) ? sanitize_key((string) $media_item['preview_status']) : '',
                'ingest_policy' => isset($media_item['ingest_policy']) ? sanitize_key((string) $media_item['ingest_policy']) : '',
                'policy_trace' => isset($media_item['policy_trace']) && is_array($media_item['policy_trace']) ? $media_item['policy_trace'] : [],
            ];
        }

        return $index;
    }

    /**
     * @param array<string, mixed>                 $write_source_context
     * @param array<string, array<string, mixed>>  $section_source_index
     * @param string                               $source_url
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_page_source_context(array $write_source_context, array $section_source_index, $source_url)
    {
        $artifact = isset($write_source_context['artifact']) && is_array($write_source_context['artifact'])
            ? $write_source_context['artifact']
            : [];
        $page_title = isset($artifact['page_name']) ? sanitize_text_field((string) $artifact['page_name']) : '';
        $page_slug = isset($artifact['slug']) ? sanitize_title((string) $artifact['slug']) : '';
        $page_meta_description = isset($artifact['meta']['description']) ? sanitize_text_field((string) $artifact['meta']['description']) : '';
        $fallback_primary = '';
        $fallback_excerpt = '';
        foreach ($section_source_index as $section_source) {
            if (! is_array($section_source)) {
                continue;
            }
            if ($fallback_primary === '' && ! empty($section_source['primary_text'])) {
                $fallback_primary = sanitize_text_field((string) $section_source['primary_text']);
            }
            if ($fallback_excerpt === '' && ! empty($section_source['text_blob'])) {
                $fallback_excerpt = sanitize_text_field((string) $section_source['text_blob']);
            }
            if ($fallback_primary !== '' && $fallback_excerpt !== '') {
                break;
            }
        }

        if ($page_title === '') {
            $page_title = $fallback_primary;
        }
        if ($page_meta_description === '') {
            $page_meta_description = $this->dbvc_cc_truncate_preview($fallback_excerpt, 180);
        }

        return [
            'source_url' => esc_url_raw((string) $source_url),
            'page_title_candidate' => $page_title,
            'page_slug_candidate' => $page_slug,
            'page_excerpt_candidate' => $page_meta_description,
        ];
    }

    /**
     * @param string                            $domain
     * @param string                            $path
     * @param string                            $source_url
     * @param array<int, array<string, mixed>>  $entity_operations
     * @param array<string, mixed>              $page_source_context
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_entity_write_operations($domain, $path, $source_url, array $entity_operations, array $page_source_context = [])
    {
        $operations = [];
        $entity_index = [];
        $write_barriers = [];
        $normalized_path = $this->dbvc_cc_normalize_source_path($path);
        $idempotency_meta = $this->dbvc_cc_build_idempotency_meta($domain, $normalized_path, $source_url);

        foreach ($entity_operations as $entity_operation) {
            if (! is_array($entity_operation)) {
                continue;
            }

            $entity_key = isset($entity_operation['entity_key']) ? sanitize_text_field((string) $entity_operation['entity_key']) : 'post:page';
            $entity_subtype = isset($entity_operation['entity_subtype']) ? sanitize_key((string) $entity_operation['entity_subtype']) : 'page';
            $write_operation_id = $this->dbvc_cc_build_operation_id('write_entity', $domain, $normalized_path, $entity_key, 'prepare');
            $write_status = 'prepared';
            $action = 'update_existing_post';
            $target_entity_id = isset($entity_operation['existing_entity_id']) ? absint($entity_operation['existing_entity_id']) : 0;
            $target_entity_ref = $target_entity_id > 0 ? 'post:' . $target_entity_id : '@' . $write_operation_id;
            $planned_post_args = [
                'post_type' => $entity_subtype,
            ];
            $depends_on = [];
            $planned_create_parent = [
                'path' => '',
                'id' => 0,
            ];
            $result = 'prepared_update_existing';

            if (isset($entity_operation['write_intent']) && sanitize_key((string) $entity_operation['write_intent']) === 'create') {
                $action = 'create_post';
                $result = 'prepared_create_new';
                $planned_post_args['post_status'] = 'draft';
                $planned_post_args['post_name'] = isset($entity_operation['post_name_candidate'])
                    ? sanitize_title((string) $entity_operation['post_name_candidate'])
                    : '';
                if (! empty($page_source_context['page_title_candidate'])) {
                    $planned_post_args['post_title'] = sanitize_text_field((string) $page_source_context['page_title_candidate']);
                }
                if (! empty($page_source_context['page_excerpt_candidate'])) {
                    $planned_post_args['post_excerpt'] = sanitize_text_field((string) $page_source_context['page_excerpt_candidate']);
                }

                $dbvc_cc_parent_resolution = $this->dbvc_cc_resolve_create_parent($entity_subtype, $normalized_path);
                $planned_create_parent = [
                    'path' => isset($dbvc_cc_parent_resolution['parent_path']) ? (string) $dbvc_cc_parent_resolution['parent_path'] : '',
                    'id' => isset($dbvc_cc_parent_resolution['parent_id']) ? absint($dbvc_cc_parent_resolution['parent_id']) : 0,
                ];
                if (isset($dbvc_cc_parent_resolution['parent_id']) && absint($dbvc_cc_parent_resolution['parent_id']) > 0) {
                    $planned_post_args['post_parent'] = absint($dbvc_cc_parent_resolution['parent_id']);
                }
                if (! empty($dbvc_cc_parent_resolution['barrier'])) {
                    $dbvc_cc_parent_chain = $this->dbvc_cc_build_missing_parent_write_chain(
                        $domain,
                        $normalized_path,
                        $entity_subtype,
                        $idempotency_meta
                    );
                    $parent_chain_operations = isset($dbvc_cc_parent_chain['operations']) && is_array($dbvc_cc_parent_chain['operations'])
                        ? array_values($dbvc_cc_parent_chain['operations'])
                        : [];
                    if (! empty($parent_chain_operations)) {
                        foreach ($parent_chain_operations as $parent_chain_operation) {
                            if (! is_array($parent_chain_operation)) {
                                continue;
                            }
                            $operations[] = $parent_chain_operation;
                        }
                        $last_parent_operation = end($parent_chain_operations);
                        if (is_array($last_parent_operation) && ! empty($last_parent_operation['write_operation_id'])) {
                            $planned_post_args['post_parent_ref'] = (string) $last_parent_operation['target_entity_ref'];
                            $planned_create_parent['path'] = isset($dbvc_cc_parent_chain['parent_path']) ? (string) $dbvc_cc_parent_chain['parent_path'] : $planned_create_parent['path'];
                            $depends_on = isset($last_parent_operation['write_operation_id']) ? [(string) $last_parent_operation['write_operation_id']] : [];
                        }
                    } else {
                        $write_status = 'blocked';
                        $result = 'blocked_missing_parent';
                        $write_barriers[] = $this->dbvc_cc_build_write_barrier(
                            'entity_missing_parent',
                            sprintf(
                                /* translators: 1: entity key, 2: parent path */
                                __('Cannot create %1$s because required parent path %2$s was not found.', 'dbvc'),
                                $entity_key,
                                isset($dbvc_cc_parent_resolution['parent_path']) ? (string) $dbvc_cc_parent_resolution['parent_path'] : ''
                            ),
                            'entity',
                            isset($entity_operation['operation_id']) ? (string) $entity_operation['operation_id'] : '',
                            $write_operation_id,
                            $entity_key
                        );
                    }
                }
            } elseif (isset($entity_operation['write_intent']) && sanitize_key((string) $entity_operation['write_intent']) === 'blocked') {
                $write_status = 'blocked';
                $action = 'blocked';
                $result = 'blocked_by_entity_resolution';
                $write_barriers[] = $this->dbvc_cc_build_write_barrier(
                    'entity_resolution_blocked',
                    sprintf(
                        /* translators: 1: entity key, 2: barrier */
                        __('Cannot prepare write for %1$s because entity resolution is blocked (%2$s).', 'dbvc'),
                        $entity_key,
                        isset($entity_operation['write_barrier']) ? (string) $entity_operation['write_barrier'] : 'unknown'
                    ),
                    'entity',
                    isset($entity_operation['operation_id']) ? (string) $entity_operation['operation_id'] : '',
                    $write_operation_id,
                    $entity_key
                );
            }

            $operation = [
                'write_operation_id' => $write_operation_id,
                'source_operation_id' => isset($entity_operation['operation_id']) ? (string) $entity_operation['operation_id'] : '',
                'write_stage' => 'entity',
                'write_status' => $write_status,
                'result' => $result,
                'action' => $action,
                'entity_key' => $entity_key,
                'entity_object_type' => isset($entity_operation['entity_object_type']) ? sanitize_key((string) $entity_operation['entity_object_type']) : 'post',
                'entity_subtype' => $entity_subtype,
                'depends_on' => isset($depends_on) && is_array($depends_on) ? $depends_on : [],
                'target_entity_id' => $target_entity_id,
                'target_entity_ref' => $target_entity_ref,
                'resolution_strategy' => isset($entity_operation['resolution_strategy']) ? sanitize_key((string) $entity_operation['resolution_strategy']) : '',
                'planned_post_args' => $planned_post_args,
                'planned_create_parent' => $planned_create_parent,
                'idempotency_meta' => $idempotency_meta,
                'source_payload' => $page_source_context,
            ];

            $operations[] = $operation;
            $source_operation_id = isset($operation['source_operation_id']) ? (string) $operation['source_operation_id'] : '';
            if ($source_operation_id !== '') {
                $entity_index[$source_operation_id] = [
                    'write_operation_id' => $write_operation_id,
                    'write_status' => $write_status,
                    'target_entity_id' => $target_entity_id,
                    'target_entity_ref' => $target_entity_ref,
                    'entity_key' => $entity_key,
                ];
            }
        }

        return [
            'operations' => $operations,
            'entity_index' => $entity_index,
            'write_barriers' => $write_barriers,
        ];
    }

    /**
     * @param string                           $domain
     * @param string                           $path
     * @param array<int, array<string, mixed>>    $field_operations
     * @param array<string, array<string, mixed>> $entity_index
     * @param array<string, array<string, mixed>> $section_source_index
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_field_write_operations($domain, $path, array $field_operations, array $entity_index, array $section_source_index = [])
    {
        $operations = [];
        $write_barriers = [];
        $normalized_path = $this->dbvc_cc_normalize_source_path($path);

        foreach ($field_operations as $field_operation) {
            if (! is_array($field_operation)) {
                continue;
            }

            $source_operation_id = isset($field_operation['operation_id']) ? (string) $field_operation['operation_id'] : '';
            $depends_on = isset($field_operation['depends_on']) && is_array($field_operation['depends_on'])
                ? array_values($field_operation['depends_on'])
                : [];
            $entity_dependency = ! empty($depends_on) ? (string) $depends_on[0] : '';
            $entity_target = ($entity_dependency !== '' && isset($entity_index[$entity_dependency]) && is_array($entity_index[$entity_dependency]))
                ? $entity_index[$entity_dependency]
                : [];
            $target_ref = isset($field_operation['target_ref']) ? sanitize_text_field((string) $field_operation['target_ref']) : '';
            $target_family = isset($field_operation['target_family']) ? sanitize_key((string) $field_operation['target_family']) : '';
            $target_field_key = isset($field_operation['target_field_key']) ? sanitize_key((string) $field_operation['target_field_key']) : '';
            $section_id = isset($field_operation['section_id']) ? sanitize_text_field((string) $field_operation['section_id']) : '';
            $section_source = ($section_id !== '' && isset($section_source_index[$section_id]) && is_array($section_source_index[$section_id]))
                ? $section_source_index[$section_id]
                : [];
            $field_source = $this->dbvc_cc_resolve_field_write_source(
                $target_family,
                $target_field_key,
                $field_operation,
                $section_source
            );
            $write_operation_id = $this->dbvc_cc_build_operation_id('write_field', $domain, $normalized_path, isset($field_operation['section_id']) ? (string) $field_operation['section_id'] : '', $target_ref);
            $write_status = 'prepared';
            $result = 'prepared_field_write';
            $action = $this->dbvc_cc_resolve_field_write_action($target_family, $target_field_key);
            $barrier_code = '';
            $resolved_value = isset($field_source['resolved_value']) && is_array($field_source['resolved_value'])
                ? $field_source['resolved_value']
                : $this->dbvc_cc_resolve_field_source_value($target_family, $target_field_key, $section_source);
            $section_source = isset($field_source['source_payload']) && is_array($field_source['source_payload'])
                ? $field_source['source_payload']
                : $section_source;
            $has_supplied_value = ! empty($field_source['has_supplied_value']);

            if ($action === '') {
                $write_status = 'blocked';
                $result = 'blocked_unsupported_field_target';
                $barrier_code = 'unsupported_field_target';
            } elseif (empty($section_source) && ! $has_supplied_value) {
                $write_status = 'blocked';
                $result = 'blocked_missing_section_source';
                $barrier_code = 'missing_section_source';
            } elseif (empty($entity_target)) {
                $write_status = 'blocked';
                $result = 'blocked_missing_entity_dependency';
                $barrier_code = 'missing_entity_dependency';
            } elseif (isset($entity_target['write_status']) && (string) $entity_target['write_status'] !== 'prepared') {
                $write_status = 'blocked';
                $result = 'blocked_upstream_entity';
                $barrier_code = 'upstream_entity_blocked';
            } elseif (! empty($resolved_value['missing'])) {
                $write_status = 'blocked';
                $result = 'blocked_missing_source_value';
                $barrier_code = 'missing_source_value';
            }

            $operation = [
                'write_operation_id' => $write_operation_id,
                'source_operation_id' => $source_operation_id,
                'write_stage' => 'field',
                'write_status' => $write_status,
                'result' => $result,
                'action' => $action,
                'section_id' => $section_id,
                'target_ref' => $target_ref,
                'target_family' => $target_family,
                'target_field_key' => $target_field_key,
                'target_entity_id' => isset($entity_target['target_entity_id']) ? absint($entity_target['target_entity_id']) : 0,
                'target_entity_ref' => isset($entity_target['target_entity_ref']) ? (string) $entity_target['target_entity_ref'] : '',
                'depends_on' => isset($entity_target['write_operation_id']) ? [(string) $entity_target['write_operation_id']] : [],
                'value_source_ref' => 'section:' . $section_id,
                'source_payload' => $section_source,
                'resolved_value_candidate' => isset($resolved_value['value']) ? $resolved_value['value'] : '',
                'resolved_value_format' => isset($resolved_value['format']) ? (string) $resolved_value['format'] : 'string',
                'resolved_value_preview' => isset($resolved_value['preview']) ? (string) $resolved_value['preview'] : '',
                'resolved_value_origin' => isset($resolved_value['origin']) ? (string) $resolved_value['origin'] : '',
            ];
            $operations[] = $operation;

            if ($barrier_code !== '') {
                $write_barriers[] = $this->dbvc_cc_build_write_barrier(
                    $barrier_code,
                    sprintf(
                        /* translators: 1: target ref, 2: entity key */
                        __('Cannot prepare field write for %1$s because %2$s.', 'dbvc'),
                        $target_ref,
                        $barrier_code === 'unsupported_field_target'
                            ? __('the target field family is unsupported', 'dbvc')
                            : ($barrier_code === 'missing_section_source'
                                ? __('the section source payload is missing', 'dbvc')
                                : ($barrier_code === 'missing_entity_dependency'
                                    ? __('the target entity dependency is missing', 'dbvc')
                                    : ($barrier_code === 'missing_source_value'
                                        ? __('the resolved source value is empty', 'dbvc')
                                        : __('the target entity preparation is blocked', 'dbvc'))))
                    ),
                    'field',
                    $source_operation_id,
                    $write_operation_id,
                    isset($field_operation['entity_key']) ? (string) $field_operation['entity_key'] : ''
                );
            }
        }

        return [
            'operations' => $operations,
            'write_barriers' => $write_barriers,
        ];
    }

    /**
     * @param string                           $domain
     * @param string                           $path
     * @param array<int, array<string, mixed>>    $media_operations
     * @param array<string, array<string, mixed>> $entity_index
     * @param array<string, array<string, mixed>> $media_source_index
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_media_write_operations($domain, $path, array $media_operations, array $entity_index, array $media_source_index = [])
    {
        $operations = [];
        $write_barriers = [];
        $aggregated_operations = [];
        $aggregated_order = [];
        $normalized_path = $this->dbvc_cc_normalize_source_path($path);
        $settings = class_exists('DBVC_CC_Settings_Service')
            ? DBVC_CC_Settings_Service::get_options()
            : [];
        $policy_context = $this->dbvc_cc_get_media_execution_policy_context();

        foreach ($media_operations as $media_operation) {
            if (! is_array($media_operation)) {
                continue;
            }

            $source_operation_id = isset($media_operation['operation_id']) ? (string) $media_operation['operation_id'] : '';
            $depends_on = isset($media_operation['depends_on']) && is_array($media_operation['depends_on'])
                ? array_values($media_operation['depends_on'])
                : [];
            $entity_dependency = ! empty($depends_on) ? (string) $depends_on[0] : '';
            $entity_target = ($entity_dependency !== '' && isset($entity_index[$entity_dependency]) && is_array($entity_index[$entity_dependency]))
                ? $entity_index[$entity_dependency]
                : [];
            $target_ref = isset($media_operation['target_ref']) ? sanitize_text_field((string) $media_operation['target_ref']) : '';
            $target_family = isset($media_operation['target_family']) ? sanitize_key((string) $media_operation['target_family']) : '';
            $target_field_key = isset($media_operation['target_field_key']) ? sanitize_key((string) $media_operation['target_field_key']) : '';
            $source_url = isset($media_operation['source_url']) ? esc_url_raw((string) $media_operation['source_url']) : '';
            $media_id = isset($media_operation['media_id']) ? sanitize_text_field((string) $media_operation['media_id']) : '';
            $storage_shape = isset($media_operation['storage_shape']) ? sanitize_key((string) $media_operation['storage_shape']) : 'attachment_id';
            $return_format = isset($media_operation['return_format']) ? sanitize_key((string) $media_operation['return_format']) : 'id';
            $accepted_media_kinds = isset($media_operation['accepted_media_kinds']) && is_array($media_operation['accepted_media_kinds'])
                ? array_values(array_map('sanitize_key', $media_operation['accepted_media_kinds']))
                : [];
            $normalized_value_strategy = isset($media_operation['normalized_value_strategy']) ? sanitize_key((string) $media_operation['normalized_value_strategy']) : 'replace_single_attachment';
            $media_source = $this->dbvc_cc_resolve_media_source_record($media_id, $source_url, $media_source_index);
            $media_resolution = $this->dbvc_cc_resolve_media_write_source(
                $media_operation,
                $media_source,
                $source_url
            );
            $media_source = isset($media_resolution['source_payload']) && is_array($media_resolution['source_payload'])
                ? $media_resolution['source_payload']
                : $media_source;
            $source_url = isset($media_resolution['source_url']) ? esc_url_raw((string) $media_resolution['source_url']) : $source_url;
            $write_operation_id = $this->dbvc_cc_build_operation_id('write_media', $domain, $normalized_path, isset($media_operation['media_id']) ? (string) $media_operation['media_id'] : '', $target_ref);
            $write_status = 'prepared';
            $result = 'prepared_media_write';
            $action = $this->dbvc_cc_resolve_media_write_action($target_family, $target_field_key, $storage_shape);
            $barrier_code = '';
            $barrier_message = '';
            $barrier_blocking = true;
            $deferred_reason_code = '';
            $deferred_reason_group = '';
            $existing_attachment_id_hint = 0;
            $resolved_media = isset($media_resolution['resolved_media']) && is_array($media_resolution['resolved_media'])
                ? $media_resolution['resolved_media']
                : $this->dbvc_cc_resolve_media_source_value($media_source, $source_url);
            $media_kind = isset($media_operation['media_kind']) ? sanitize_key((string) $media_operation['media_kind']) : '';

            if ($source_url === '') {
                $write_status = 'deferred';
                $result = 'deferred_missing_media_source_url';
                $barrier_code = 'missing_media_source_url';
                $barrier_message = __('Media write was deferred because the source URL is missing.', 'dbvc');
                $barrier_blocking = false;
                $deferred_reason_code = $barrier_code;
                $deferred_reason_group = 'missing_source';
            } elseif ($storage_shape === 'unsupported_nested_media') {
                $write_status = 'deferred';
                $result = 'deferred_unsupported_media_target';
                $barrier_code = 'unsupported_nested_media_target';
                $barrier_message = __('Media write was deferred because nested repeater/flexible media targets are not supported in Phase 4.', 'dbvc');
                $barrier_blocking = false;
                $deferred_reason_code = $barrier_code;
                $deferred_reason_group = 'unsupported_shape';
            } elseif (! empty($accepted_media_kinds) && $media_kind !== '' && ! in_array($media_kind, $accepted_media_kinds, true)) {
                $write_status = 'deferred';
                $result = 'deferred_unsupported_media_target';
                $barrier_code = 'unsupported_media_kind';
                $barrier_message = __('Media write was deferred because the target field does not accept this media kind.', 'dbvc');
                $barrier_blocking = false;
                $deferred_reason_code = $barrier_code;
                $deferred_reason_group = 'unsupported_shape';
            } elseif (
                empty($media_source)
                && empty($resolved_media)
                && $storage_shape !== 'remote_url'
            ) {
                $write_status = 'deferred';
                $result = 'deferred_missing_media_source';
                $barrier_code = 'missing_media_source';
                $barrier_message = __('Media write was deferred because the collected media source payload is missing.', 'dbvc');
                $barrier_blocking = false;
                $deferred_reason_code = $barrier_code;
                $deferred_reason_group = 'missing_source';
            } elseif ($action === '') {
                $write_status = 'deferred';
                $result = 'deferred_unsupported_media_target';
                $barrier_code = 'unsupported_media_target';
                $barrier_message = __('Media write was deferred because the target field shape is not supported in Phase 4.', 'dbvc');
                $barrier_blocking = false;
                $deferred_reason_code = $barrier_code;
                $deferred_reason_group = 'unsupported_shape';
            } elseif (empty($entity_target)) {
                $write_status = 'blocked';
                $result = 'blocked_missing_entity_dependency';
                $barrier_code = 'missing_entity_dependency';
                $barrier_message = __('Media write cannot proceed because the target entity dependency is missing.', 'dbvc');
            } elseif (isset($entity_target['write_status']) && (string) $entity_target['write_status'] !== 'prepared') {
                $write_status = 'blocked';
                $result = 'blocked_upstream_entity';
                $barrier_code = 'upstream_entity_blocked';
                $barrier_message = __('Media write cannot proceed because the target entity preparation is blocked.', 'dbvc');
            } else {
                if ($storage_shape !== 'remote_url') {
                    $existing_attachment_lookup = $this->dbvc_cc_find_existing_media_attachment($source_url);
                    if (is_wp_error($existing_attachment_lookup)) {
                        $write_status = 'blocked';
                        $result = 'blocked_existing_attachment_lookup';
                        $barrier_code = $this->dbvc_cc_normalize_media_deferred_reason_code($existing_attachment_lookup->get_error_code());
                        $barrier_message = $existing_attachment_lookup->get_error_message();
                    } else {
                        $existing_attachment_id_hint = absint($existing_attachment_lookup);
                        if ($existing_attachment_id_hint <= 0) {
                            $policy_error = $this->dbvc_cc_validate_media_source_for_execution(
                                isset($resolved_media['source_url']) ? (string) $resolved_media['source_url'] : $source_url,
                                $resolved_media,
                                $policy_context
                            );
                            if (is_wp_error($policy_error)) {
                                $write_status = 'deferred';
                                $result = 'deferred_media_policy';
                                $barrier_code = $this->dbvc_cc_normalize_media_deferred_reason_code($policy_error->get_error_code());
                                $barrier_message = $policy_error->get_error_message();
                                $barrier_blocking = false;
                                $deferred_reason_code = $barrier_code;
                                $deferred_reason_group = $this->dbvc_cc_map_media_deferred_reason_group($barrier_code);
                            }
                        }
                    }
                }
            }

            $operation = [
                'write_operation_id' => $write_operation_id,
                'source_operation_id' => $source_operation_id,
                'write_stage' => 'media',
                'write_status' => $write_status,
                'result' => $result,
                'action' => $action,
                'media_id' => $media_id,
                'media_kind' => $media_kind,
                'source_url' => $source_url,
                'target_ref' => $target_ref,
                'target_family' => $target_family,
                'target_field_key' => $target_field_key,
                'target_entity_id' => isset($entity_target['target_entity_id']) ? absint($entity_target['target_entity_id']) : 0,
                'target_entity_ref' => isset($entity_target['target_entity_ref']) ? (string) $entity_target['target_entity_ref'] : '',
                'depends_on' => isset($entity_target['write_operation_id']) ? [(string) $entity_target['write_operation_id']] : [],
                'value_source_ref' => 'media:' . $media_id,
                'source_payload' => $media_source,
                'resolved_media_candidate' => $resolved_media,
                'existing_attachment_id_hint' => $existing_attachment_id_hint,
                'storage_shape' => $storage_shape,
                'multi_value' => ! empty($media_operation['multi_value']),
                'return_format' => $return_format,
                'accepted_media_kinds' => $accepted_media_kinds,
                'normalized_value_strategy' => $normalized_value_strategy,
                'download_policy' => isset($settings['dbvc_cc_media_download_policy'])
                    ? sanitize_key((string) $settings['dbvc_cc_media_download_policy'])
                    : DBVC_CC_Contracts::MEDIA_DOWNLOAD_POLICY_REMOTE_ONLY,
                'preview_enabled' => ! empty($settings['dbvc_cc_media_preview_thumbnail_enabled']),
                'blocking' => $barrier_code === '' ? false : $barrier_blocking,
                'deferred_reason_code' => $deferred_reason_code,
                'deferred_reason_group' => $deferred_reason_group,
                'message' => $barrier_message,
            ];

            if ($barrier_code === '' && $this->dbvc_cc_is_aggregated_media_storage_shape($storage_shape)) {
                $aggregation_key = implode('|', [
                    (string) $target_ref,
                    isset($operation['target_entity_ref']) ? (string) $operation['target_entity_ref'] : '',
                    (string) $storage_shape,
                ]);

                if (! isset($aggregated_operations[$aggregation_key])) {
                    $aggregated_order[] = $aggregation_key;
                    $aggregated_operations[$aggregation_key] = [
                        'write_operation_id' => $this->dbvc_cc_build_operation_id('write_media_group', $domain, $normalized_path, $target_ref, $storage_shape),
                        'source_operation_id' => $source_operation_id,
                        'source_operation_ids' => [],
                        'write_stage' => 'media',
                        'write_status' => 'prepared',
                        'result' => 'prepared_media_write',
                        'action' => $action,
                        'media_id' => $media_id,
                        'media_ids' => [],
                        'media_kind' => $media_kind,
                        'source_url' => $source_url,
                        'source_urls' => [],
                        'target_ref' => $target_ref,
                        'target_family' => $target_family,
                        'target_field_key' => $target_field_key,
                        'target_entity_id' => isset($entity_target['target_entity_id']) ? absint($entity_target['target_entity_id']) : 0,
                        'target_entity_ref' => isset($entity_target['target_entity_ref']) ? (string) $entity_target['target_entity_ref'] : '',
                        'depends_on' => isset($entity_target['write_operation_id']) ? [(string) $entity_target['write_operation_id']] : [],
                        'value_source_ref' => 'media_group:' . substr(hash('sha256', $aggregation_key), 0, 12),
                        'source_payload' => [],
                        'resolved_media_candidate' => [],
                        'existing_attachment_id_hint' => 0,
                        'existing_attachment_id_hints' => [],
                        'storage_shape' => $storage_shape,
                        'multi_value' => true,
                        'return_format' => $return_format,
                        'accepted_media_kinds' => $accepted_media_kinds,
                        'normalized_value_strategy' => $normalized_value_strategy,
                        'download_policy' => isset($settings['dbvc_cc_media_download_policy'])
                            ? sanitize_key((string) $settings['dbvc_cc_media_download_policy'])
                            : DBVC_CC_Contracts::MEDIA_DOWNLOAD_POLICY_REMOTE_ONLY,
                        'preview_enabled' => ! empty($settings['dbvc_cc_media_preview_thumbnail_enabled']),
                        'blocking' => false,
                        'deferred_reason_code' => '',
                        'deferred_reason_group' => '',
                        'message' => '',
                    ];
                }

                $aggregated_operations[$aggregation_key]['source_operation_ids'][] = $source_operation_id;
                $aggregated_operations[$aggregation_key]['media_ids'][] = $media_id;
                $aggregated_operations[$aggregation_key]['source_urls'][] = $source_url;
                $aggregated_operations[$aggregation_key]['source_payload'][] = $media_source;
                $aggregated_operations[$aggregation_key]['resolved_media_candidate'][] = $resolved_media;
                if ($existing_attachment_id_hint > 0) {
                    $aggregated_operations[$aggregation_key]['existing_attachment_id_hints'][] = $existing_attachment_id_hint;
                    if (empty($aggregated_operations[$aggregation_key]['existing_attachment_id_hint'])) {
                        $aggregated_operations[$aggregation_key]['existing_attachment_id_hint'] = $existing_attachment_id_hint;
                    }
                }
            } else {
                $operations[] = $operation;
            }

            if ($barrier_code !== '') {
                $write_barriers[] = $this->dbvc_cc_build_write_barrier(
                    $barrier_code,
                    $barrier_message !== ''
                        ? $barrier_message
                        : sprintf(
                            /* translators: 1: target ref */
                            __('Cannot prepare media write for %s.', 'dbvc'),
                            $target_ref
                        ),
                    'media',
                    $source_operation_id,
                    $write_operation_id,
                    isset($media_operation['entity_key']) ? (string) $media_operation['entity_key'] : '',
                    $barrier_blocking,
                    [
                        'deferred_reason_code' => $deferred_reason_code,
                        'deferred_reason_group' => $deferred_reason_group,
                    ]
                );
            }
        }

        foreach ($aggregated_order as $aggregation_key) {
            if (! isset($aggregated_operations[$aggregation_key]) || ! is_array($aggregated_operations[$aggregation_key])) {
                continue;
            }

            $aggregated_operation = $aggregated_operations[$aggregation_key];
            if (isset($aggregated_operation['media_ids']) && is_array($aggregated_operation['media_ids'])) {
                $aggregated_operation['media_ids'] = array_values(array_filter(array_unique(array_map('sanitize_text_field', $aggregated_operation['media_ids']))));
            }
            if (isset($aggregated_operation['source_urls']) && is_array($aggregated_operation['source_urls'])) {
                $aggregated_operation['source_urls'] = array_values(array_filter(array_unique(array_map('esc_url_raw', $aggregated_operation['source_urls']))));
            }
            if (isset($aggregated_operation['existing_attachment_id_hints']) && is_array($aggregated_operation['existing_attachment_id_hints'])) {
                $aggregated_operation['existing_attachment_id_hints'] = array_values(array_filter(array_unique(array_map('absint', $aggregated_operation['existing_attachment_id_hints']))));
            }

            $operations[] = $aggregated_operation;
        }

        usort(
            $operations,
            static function ($left, $right) {
                $left_id = isset($left['write_operation_id']) ? (string) $left['write_operation_id'] : '';
                $right_id = isset($right['write_operation_id']) ? (string) $right['write_operation_id'] : '';
                return strnatcasecmp($left_id, $right_id);
            }
        );

        return [
            'operations' => $operations,
            'write_barriers' => $write_barriers,
        ];
    }

    /**
     * @param string $code
     * @param string $message
     * @param string $stage
     * @param string $source_operation_id
     * @param string $write_operation_id
     * @param string $entity_key
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_write_barrier($code, $message, $stage, $source_operation_id, $write_operation_id, $entity_key, $blocking = true, array $extra = [])
    {
        $barrier = [
            'code' => sanitize_key((string) $code),
            'message' => sanitize_text_field((string) $message),
            'blocking' => (bool) $blocking,
            'stage' => sanitize_key((string) $stage),
            'source_operation_id' => sanitize_text_field((string) $source_operation_id),
            'write_operation_id' => sanitize_text_field((string) $write_operation_id),
            'entity_key' => sanitize_text_field((string) $entity_key),
        ];

        if (isset($extra['deferred_reason_code']) && (string) $extra['deferred_reason_code'] !== '') {
            $barrier['deferred_reason_code'] = sanitize_key((string) $extra['deferred_reason_code']);
        }
        if (isset($extra['deferred_reason_group']) && (string) $extra['deferred_reason_group'] !== '') {
            $barrier['deferred_reason_group'] = sanitize_key((string) $extra['deferred_reason_group']);
        }

        return $barrier;
    }

    /**
     * @param string $reason_code
     * @return string
     */
    private function dbvc_cc_normalize_media_deferred_reason_code($reason_code)
    {
        $reason_code = sanitize_key((string) $reason_code);
        if (strpos($reason_code, 'dbvc_cc_') === 0) {
            $reason_code = substr($reason_code, 8);
        }

        return $reason_code !== '' ? $reason_code : 'deferred_media';
    }

    /**
     * @param string $reason_code
     * @return string
     */
    private function dbvc_cc_map_media_deferred_reason_group($reason_code)
    {
        $reason_code = $this->dbvc_cc_normalize_media_deferred_reason_code($reason_code);
        if (in_array($reason_code, ['missing_media_source_url', 'missing_media_source', 'media_source_url_invalid', 'media_source_host_missing'], true)) {
            return 'missing_source';
        }
        if ($reason_code === 'unsupported_media_target') {
            return 'unsupported_shape';
        }

        return 'policy';
    }

    /**
     * @param string $target_family
     * @param string $target_field_key
     * @return string
     */
    private function dbvc_cc_resolve_field_write_action($target_family, $target_field_key)
    {
        $target_family = sanitize_key((string) $target_family);
        $target_field_key = sanitize_key((string) $target_field_key);

        if ($target_family === 'core' && in_array($target_field_key, ['post_title', 'post_content', 'post_excerpt', 'post_name', 'menu_order'], true)) {
            return 'update_post_field';
        }

        if ($target_family === 'meta' && $target_field_key !== '') {
            return 'update_post_meta';
        }

        if ($target_family === 'acf' && $target_field_key !== '') {
            return 'update_acf_field';
        }

        return '';
    }

    /**
     * @param string $target_family
     * @param string $target_field_key
     * @return string
     */
    private function dbvc_cc_resolve_media_write_action($target_family, $target_field_key, $storage_shape = '')
    {
        $target_family = sanitize_key((string) $target_family);
        $target_field_key = sanitize_key((string) $target_field_key);
        $storage_shape = sanitize_key((string) $storage_shape);

        if ($target_family === 'core' && $target_field_key === 'featured_image') {
            return 'set_featured_image_from_remote';
        }

        if ($storage_shape === 'remote_url' && $target_family === 'meta' && $target_field_key !== '') {
            return 'update_post_meta_media_url';
        }

        if ($storage_shape === 'remote_url' && $target_family === 'acf' && $target_field_key !== '') {
            return 'update_acf_media_url';
        }

        if ($target_family === 'meta' && $target_field_key !== '') {
            return 'update_post_meta_media';
        }

        if ($target_family === 'acf' && $target_field_key !== '') {
            return 'update_acf_media';
        }

        return '';
    }

    /**
     * @param string $storage_shape
     * @return bool
     */
    private function dbvc_cc_is_aggregated_media_storage_shape($storage_shape)
    {
        $storage_shape = sanitize_key((string) $storage_shape);
        return in_array($storage_shape, ['attachment_id_list'], true);
    }

    /**
     * @param string               $target_family
     * @param string               $target_field_key
     * @param array<string, mixed> $section_source
     * @return array<string, mixed>
     */
    private function dbvc_cc_resolve_field_source_value($target_family, $target_field_key, array $section_source = [])
    {
        $target_family = sanitize_key((string) $target_family);
        $target_field_key = sanitize_key((string) $target_field_key);
        if (empty($section_source)) {
            return [
                'format' => 'string',
                'value' => '',
                'preview' => '',
                'origin' => '',
                'missing' => true,
            ];
        }

        $primary_text = isset($section_source['primary_text']) ? sanitize_text_field((string) $section_source['primary_text']) : '';
        $text_blob = isset($section_source['text_blob']) ? (string) $section_source['text_blob'] : '';
        $tagged_text_blob = isset($section_source['tagged_text_blob']) ? (string) $section_source['tagged_text_blob'] : '';
        $body_texts = isset($section_source['body_texts']) && is_array($section_source['body_texts'])
            ? array_values(array_map('sanitize_text_field', $section_source['body_texts']))
            : [];
        $heading_texts = isset($section_source['heading_texts']) && is_array($section_source['heading_texts'])
            ? array_values(array_map('sanitize_text_field', $section_source['heading_texts']))
            : [];
        $sample_text = isset($section_source['sample_text']) && is_array($section_source['sample_text'])
            ? array_values(array_map('sanitize_text_field', $section_source['sample_text']))
            : [];

        if ($target_family === 'core' && $target_field_key === 'post_title') {
            $value = $primary_text !== '' ? $primary_text : (! empty($heading_texts) ? (string) $heading_texts[0] : '');
            return [
                'format' => 'string',
                'value' => $value,
                'preview' => $this->dbvc_cc_truncate_preview($value, 120),
                'origin' => 'section_primary_text',
                'missing' => $value === '',
            ];
        }

        if ($target_family === 'core' && $target_field_key === 'post_content') {
            $value = $tagged_text_blob !== '' ? $tagged_text_blob : $text_blob;
            return [
                'format' => 'string',
                'value' => $value,
                'preview' => $this->dbvc_cc_truncate_preview($value, 180),
                'origin' => $tagged_text_blob !== '' ? 'section_tagged_text_blob' : 'section_text_blob',
                'missing' => $value === '',
            ];
        }

        if ($target_family === 'core' && $target_field_key === 'post_excerpt') {
            $value = $text_blob !== '' ? $this->dbvc_cc_truncate_preview($text_blob, 180) : '';
            return [
                'format' => 'string',
                'value' => $value,
                'preview' => $value,
                'origin' => 'section_excerpt',
                'missing' => $value === '',
            ];
        }

        if (strpos($target_field_key, 'faq_question') !== false || strpos($target_field_key, 'question') !== false) {
            $value = ! empty($body_texts) ? $body_texts : $sample_text;
            return [
                'format' => 'array',
                'value' => $value,
                'preview' => $this->dbvc_cc_truncate_preview(implode(' | ', $value), 180),
                'origin' => 'section_body_texts',
                'missing' => empty($value),
            ];
        }

        if (strpos($target_field_key, 'faq_answer') !== false || strpos($target_field_key, 'answer') !== false) {
            $value = count($body_texts) > 1 ? array_slice($body_texts, 1) : [];
            return [
                'format' => 'array',
                'value' => $value,
                'preview' => $this->dbvc_cc_truncate_preview(implode(' | ', $value), 180),
                'origin' => 'section_body_followups',
                'missing' => empty($value),
            ];
        }

        if (($target_family === 'meta' || $target_family === 'acf') && strpos($target_field_key, 'url') !== false) {
            $link_targets = isset($section_source['link_targets']) && is_array($section_source['link_targets'])
                ? array_values(array_map('esc_url_raw', $section_source['link_targets']))
                : [];
            $value = ! empty($link_targets) ? (string) $link_targets[0] : '';
            return [
                'format' => 'string',
                'value' => $value,
                'preview' => $this->dbvc_cc_truncate_preview($value, 180),
                'origin' => 'section_link_target',
                'missing' => $value === '',
            ];
        }

        $fallback_value = $text_blob !== '' ? $text_blob : implode("\n\n", $sample_text);
        return [
            'format' => 'string',
            'value' => $fallback_value,
            'preview' => $this->dbvc_cc_truncate_preview($fallback_value, 180),
            'origin' => $text_blob !== '' ? 'section_text_blob' : 'section_sample_text',
            'missing' => $fallback_value === '',
        ];
    }

    /**
     * @param string               $target_family
     * @param string               $target_field_key
     * @param array<string, mixed> $field_operation
     * @param array<string, mixed> $section_source
     * @return array<string, mixed>
     */
    private function dbvc_cc_resolve_field_write_source($target_family, $target_field_key, array $field_operation = [], array $section_source = [])
    {
        $source_payload = isset($field_operation['source_payload']) && is_array($field_operation['source_payload'])
            ? $field_operation['source_payload']
            : [];
        $resolved_value = $this->dbvc_cc_resolve_field_source_value($target_family, $target_field_key, $section_source);
        $has_supplied_value = array_key_exists('resolved_value_candidate', $field_operation) || ! empty($source_payload);

        if (! $has_supplied_value) {
            return [
                'source_payload' => $section_source,
                'resolved_value' => $resolved_value,
                'has_supplied_value' => false,
            ];
        }

        $candidate = array_key_exists('resolved_value_candidate', $field_operation)
            ? $field_operation['resolved_value_candidate']
            : (array_key_exists('package_value', $source_payload) ? $source_payload['package_value'] : '');
        $format = isset($field_operation['resolved_value_format']) ? sanitize_key((string) $field_operation['resolved_value_format']) : '';
        if ($format === '' && isset($source_payload['package_value_format'])) {
            $format = sanitize_key((string) $source_payload['package_value_format']);
        }
        if ($format === '') {
            $format = is_array($candidate) ? 'array' : 'string';
        }

        $preview = isset($field_operation['resolved_value_preview']) ? sanitize_text_field((string) $field_operation['resolved_value_preview']) : '';
        if ($preview === '') {
            $preview = $this->dbvc_cc_build_value_preview($candidate);
        }

        $origin = isset($field_operation['resolved_value_origin']) ? sanitize_key((string) $field_operation['resolved_value_origin']) : '';
        if ($origin === '') {
            $origin = 'package_record';
        }

        return [
            'source_payload' => ! empty($source_payload) ? $source_payload : $section_source,
            'resolved_value' => [
                'format' => $format,
                'value' => $candidate,
                'preview' => $preview,
                'origin' => $origin,
                'missing' => $this->dbvc_cc_is_empty_resolved_value($candidate),
            ],
            'has_supplied_value' => true,
        ];
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function dbvc_cc_is_empty_resolved_value($value)
    {
        if (is_array($value)) {
            return empty($value);
        }

        return $value === null || $value === '';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function dbvc_cc_build_value_preview($value)
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

        return $this->dbvc_cc_truncate_preview($preview, 180);
    }

    /**
     * @param array<string, mixed> $media_source
     * @param string               $source_url
     * @return array<string, mixed>
     */
    private function dbvc_cc_resolve_media_source_value(array $media_source = [], $source_url = '')
    {
        $resolved_source_url = isset($media_source['source_url']) ? esc_url_raw((string) $media_source['source_url']) : esc_url_raw((string) $source_url);
        $preview_ref = isset($media_source['preview_ref']) ? esc_url_raw((string) $media_source['preview_ref']) : '';

        return [
            'source_url' => $resolved_source_url,
            'preview_ref' => $preview_ref,
            'alt_text' => isset($media_source['alt_text']) ? sanitize_text_field((string) $media_source['alt_text']) : '',
            'caption_text' => isset($media_source['caption_text']) ? sanitize_text_field((string) $media_source['caption_text']) : '',
            'surrounding_text_snippet' => isset($media_source['surrounding_text_snippet']) ? sanitize_text_field((string) $media_source['surrounding_text_snippet']) : '',
            'media_kind' => isset($media_source['media_kind']) ? sanitize_key((string) $media_source['media_kind']) : '',
            'mime_guess' => isset($media_source['mime_guess']) ? sanitize_text_field((string) $media_source['mime_guess']) : '',
            'role_candidates' => isset($media_source['role_candidates']) && is_array($media_source['role_candidates'])
                ? array_values(array_map('sanitize_key', $media_source['role_candidates']))
                : [],
            'preview_available' => $preview_ref !== '',
        ];
    }

    /**
     * @param array<string, mixed> $media_operation
     * @param array<string, mixed> $media_source
     * @param string               $source_url
     * @return array<string, mixed>
     */
    private function dbvc_cc_resolve_media_write_source(array $media_operation = [], array $media_source = [], $source_url = '')
    {
        $source_payload = isset($media_operation['source_payload']) && is_array($media_operation['source_payload'])
            ? $media_operation['source_payload']
            : [];
        $resolved_media = isset($media_operation['resolved_media_candidate']) && is_array($media_operation['resolved_media_candidate'])
            ? $media_operation['resolved_media_candidate']
            : [];
        $effective_source = ! empty($source_payload) ? array_merge($media_source, $source_payload) : $media_source;
        $effective_source_url = isset($resolved_media['source_url']) ? esc_url_raw((string) $resolved_media['source_url']) : '';
        if ($effective_source_url === '' && isset($effective_source['source_url'])) {
            $effective_source_url = esc_url_raw((string) $effective_source['source_url']);
        }
        if ($effective_source_url === '') {
            $effective_source_url = esc_url_raw((string) $source_url);
        }

        if (empty($resolved_media)) {
            $resolved_media = $this->dbvc_cc_resolve_media_source_value($effective_source, $effective_source_url);
        } else {
            if (! isset($resolved_media['source_url']) || (string) $resolved_media['source_url'] === '') {
                $resolved_media['source_url'] = $effective_source_url;
            }
            if (! isset($resolved_media['preview_ref']) && isset($effective_source['preview_ref'])) {
                $resolved_media['preview_ref'] = esc_url_raw((string) $effective_source['preview_ref']);
            }
            if (! isset($resolved_media['media_kind']) && isset($effective_source['media_kind'])) {
                $resolved_media['media_kind'] = sanitize_key((string) $effective_source['media_kind']);
            }
        }

        return [
            'source_payload' => $effective_source,
            'resolved_media' => $resolved_media,
            'source_url' => $effective_source_url,
        ];
    }

    /**
     * @param string                                  $media_id
     * @param string                                  $source_url
     * @param array<string, array<string, mixed>>     $media_source_index
     * @return array<string, mixed>
     */
    private function dbvc_cc_resolve_media_source_record($media_id, $source_url, array $media_source_index = [])
    {
        $media_id = sanitize_text_field((string) $media_id);
        $source_url = esc_url_raw((string) $source_url);

        if ($media_id !== '' && isset($media_source_index[$media_id]) && is_array($media_source_index[$media_id])) {
            return $media_source_index[$media_id];
        }

        if ($source_url === '') {
            return [];
        }

        foreach ($media_source_index as $media_source) {
            if (! is_array($media_source)) {
                continue;
            }

            $candidate_url = isset($media_source['source_url']) ? esc_url_raw((string) $media_source['source_url']) : '';
            if ($candidate_url !== '' && $candidate_url === $source_url) {
                return $media_source;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $resolved_media
     * @param int                  $target_entity_id
     * @param array<string, mixed> $media_write
     * @return array<string, mixed>|WP_Error
     */
    private function dbvc_cc_ingest_media_attachment(array $resolved_media = [], $target_entity_id = 0, array $media_write = [])
    {
        $target_entity_id = absint($target_entity_id);
        $source_url = isset($resolved_media['source_url']) ? esc_url_raw((string) $resolved_media['source_url']) : '';
        $media_filter_result = apply_filters(
            'dbvc_cc_import_executor_media_ingest',
            null,
            $resolved_media,
            $media_write,
            [
                'target_entity_id' => $target_entity_id,
                'target_ref' => isset($media_write['target_ref']) ? sanitize_text_field((string) $media_write['target_ref']) : '',
                'action' => isset($media_write['action']) ? sanitize_key((string) $media_write['action']) : '',
            ]
        );
        if (is_wp_error($media_filter_result)) {
            return $media_filter_result;
        }
        if (is_array($media_filter_result) && ! empty($media_filter_result['attachment_id'])) {
            return [
                'attachment_id' => absint($media_filter_result['attachment_id']),
                'created' => ! empty($media_filter_result['created']),
                'source_url' => isset($media_filter_result['source_url']) ? esc_url_raw((string) $media_filter_result['source_url']) : $source_url,
            ];
        }

        if ($source_url === '') {
            return new WP_Error(
                'dbvc_cc_media_source_url_missing',
                __('Media source URL is missing.', 'dbvc'),
                ['status' => 500]
            );
        }

        $existing_attachment_id = $this->dbvc_cc_find_existing_media_attachment($source_url);
        if (is_wp_error($existing_attachment_id)) {
            return $existing_attachment_id;
        }
        if ($existing_attachment_id > 0) {
            return [
                'attachment_id' => $existing_attachment_id,
                'created' => false,
                'source_url' => $source_url,
            ];
        }

        $policy_context = $this->dbvc_cc_get_media_execution_policy_context();
        $policy_error = $this->dbvc_cc_validate_media_source_for_execution($source_url, $resolved_media, $policy_context);
        if (is_wp_error($policy_error)) {
            return $policy_error;
        }

        if (! function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (! function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
        if (! function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $timeout = max(5, (int) apply_filters(
            'dbvc_cc_import_executor_media_timeout',
            (int) (class_exists('DBVC_CC_Settings_Service') ? (DBVC_CC_Settings_Service::get_options()['request_timeout'] ?? 30) : 30),
            $resolved_media,
            $media_write
        ));
        $tmp_file = download_url($source_url, $timeout);
        if (is_wp_error($tmp_file)) {
            return new WP_Error(
                'dbvc_cc_media_download_failed',
                $tmp_file->get_error_message(),
                ['status' => 500]
            );
        }

        $max_bytes = isset($policy_context['dbvc_cc_media_max_bytes_per_asset']) ? absint($policy_context['dbvc_cc_media_max_bytes_per_asset']) : 0;
        if ($max_bytes > 0 && file_exists($tmp_file) && filesize($tmp_file) > $max_bytes) {
            @unlink($tmp_file);
            return new WP_Error(
                'dbvc_cc_media_too_large',
                __('Media asset exceeds the configured maximum size.', 'dbvc'),
                ['status' => 500]
            );
        }

        $file_array = [
            'name' => $this->dbvc_cc_resolve_media_filename($source_url, isset($resolved_media['mime_guess']) ? (string) $resolved_media['mime_guess'] : ''),
            'tmp_name' => $tmp_file,
        ];
        $attachment_title = $this->dbvc_cc_resolve_media_attachment_title($source_url, $resolved_media);
        $attachment_post_data = [
            'post_title' => $attachment_title,
            'post_excerpt' => isset($resolved_media['caption_text']) ? sanitize_text_field((string) $resolved_media['caption_text']) : '',
            'post_content' => isset($resolved_media['surrounding_text_snippet']) ? sanitize_textarea_field((string) $resolved_media['surrounding_text_snippet']) : '',
        ];

        $attachment_id = media_handle_sideload($file_array, $target_entity_id, $attachment_title, $attachment_post_data);
        if (is_wp_error($attachment_id) || absint($attachment_id) <= 0) {
            if (is_string($tmp_file) && file_exists($tmp_file)) {
                @unlink($tmp_file);
            }
            return is_wp_error($attachment_id)
                ? $attachment_id
                : new WP_Error(
                    'dbvc_cc_media_attachment_failed',
                    __('Media attachment could not be created.', 'dbvc'),
                    ['status' => 500]
                );
        }

        update_post_meta($attachment_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_URL, $source_url);
        update_post_meta($attachment_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_HASH, hash('sha256', $source_url));
        delete_post_meta($attachment_id, DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH);

        $alt_text = isset($resolved_media['alt_text']) ? sanitize_text_field((string) $resolved_media['alt_text']) : '';
        if ($alt_text !== '') {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
        }

        return [
            'attachment_id' => absint($attachment_id),
            'created' => true,
            'source_url' => $source_url,
        ];
    }

    /**
     * @param string $source_url
     * @return int|WP_Error
     */
    private function dbvc_cc_find_existing_media_attachment($source_url)
    {
        $source_url = esc_url_raw((string) $source_url);
        if ($source_url === '') {
            return 0;
        }

        $attachments = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'any',
            'meta_key' => DBVC_CC_Contracts::IMPORT_META_SOURCE_URL,
            'meta_value' => $source_url,
            'fields' => 'ids',
            'numberposts' => 3,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        $attachments = is_array($attachments) ? array_values(array_map('absint', $attachments)) : [];
        $attachments = array_values(array_filter($attachments));

        if (count($attachments) > 1) {
            return new WP_Error(
                'dbvc_cc_ambiguous_media_attachment',
                __('Multiple existing attachments were found for the same media source URL.', 'dbvc'),
                ['status' => 500]
            );
        }

        return ! empty($attachments) ? absint($attachments[0]) : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function dbvc_cc_get_media_execution_policy_context()
    {
        $options = class_exists('DBVC_CC_Settings_Service')
            ? DBVC_CC_Settings_Service::get_options()
            : [];

        return [
            'dbvc_cc_media_download_policy' => isset($options['dbvc_cc_media_download_policy'])
                ? sanitize_key((string) $options['dbvc_cc_media_download_policy'])
                : DBVC_CC_Contracts::MEDIA_DOWNLOAD_POLICY_REMOTE_ONLY,
            'dbvc_cc_media_mime_allowlist' => $this->dbvc_cc_parse_media_mime_allowlist(isset($options['dbvc_cc_media_mime_allowlist']) ? (string) $options['dbvc_cc_media_mime_allowlist'] : ''),
            'dbvc_cc_media_max_bytes_per_asset' => max(0, absint(isset($options['dbvc_cc_media_max_bytes_per_asset']) ? $options['dbvc_cc_media_max_bytes_per_asset'] : 0)),
            'dbvc_cc_media_block_private_hosts' => ! empty($options['dbvc_cc_media_block_private_hosts']),
            'dbvc_cc_media_source_domain_allowlist' => $this->dbvc_cc_parse_domain_list(isset($options['dbvc_cc_media_source_domain_allowlist']) ? (string) $options['dbvc_cc_media_source_domain_allowlist'] : ''),
            'dbvc_cc_media_source_domain_denylist' => $this->dbvc_cc_parse_domain_list(isset($options['dbvc_cc_media_source_domain_denylist']) ? (string) $options['dbvc_cc_media_source_domain_denylist'] : ''),
        ];
    }

    /**
     * @param string               $source_url
     * @param array<string, mixed> $resolved_media
     * @param array<string, mixed> $policy_context
     * @return true|WP_Error
     */
    private function dbvc_cc_validate_media_source_for_execution($source_url, array $resolved_media = [], array $policy_context = [])
    {
        $source_url = esc_url_raw((string) $source_url);
        if ($source_url === '') {
            return new WP_Error(
                'dbvc_cc_media_source_url_invalid',
                __('Media source URL is invalid.', 'dbvc'),
                ['status' => 500]
            );
        }

        $policy = isset($policy_context['dbvc_cc_media_download_policy']) ? sanitize_key((string) $policy_context['dbvc_cc_media_download_policy']) : DBVC_CC_Contracts::MEDIA_DOWNLOAD_POLICY_REMOTE_ONLY;
        if ($policy === DBVC_CC_Contracts::MEDIA_DOWNLOAD_POLICY_SKIP) {
            return new WP_Error(
                'dbvc_cc_media_policy_skip',
                __('Media download policy is currently set to skip execution.', 'dbvc'),
                ['status' => 500]
            );
        }

        $host = strtolower((string) wp_parse_url($source_url, PHP_URL_HOST));
        if ($host === '') {
            return new WP_Error(
                'dbvc_cc_media_source_host_missing',
                __('Media source host could not be resolved.', 'dbvc'),
                ['status' => 500]
            );
        }

        $allowlist = isset($policy_context['dbvc_cc_media_source_domain_allowlist']) && is_array($policy_context['dbvc_cc_media_source_domain_allowlist'])
            ? $policy_context['dbvc_cc_media_source_domain_allowlist']
            : [];
        $denylist = isset($policy_context['dbvc_cc_media_source_domain_denylist']) && is_array($policy_context['dbvc_cc_media_source_domain_denylist'])
            ? $policy_context['dbvc_cc_media_source_domain_denylist']
            : [];
        if (in_array($host, $denylist, true)) {
            return new WP_Error(
                'dbvc_cc_media_source_denied',
                __('Media source host is denied by the current policy.', 'dbvc'),
                ['status' => 500]
            );
        }
        if (! empty($allowlist) && ! in_array($host, $allowlist, true)) {
            return new WP_Error(
                'dbvc_cc_media_source_not_allowlisted',
                __('Media source host is not allowlisted.', 'dbvc'),
                ['status' => 500]
            );
        }
        if (in_array($host, $allowlist, true)) {
            $mime_guess = isset($resolved_media['mime_guess']) ? sanitize_text_field((string) $resolved_media['mime_guess']) : '';
            $mime_allowlist = isset($policy_context['dbvc_cc_media_mime_allowlist']) && is_array($policy_context['dbvc_cc_media_mime_allowlist'])
                ? $policy_context['dbvc_cc_media_mime_allowlist']
                : [];
            if (! $this->dbvc_cc_is_media_mime_allowed($mime_guess, $mime_allowlist)) {
                return new WP_Error(
                    'dbvc_cc_media_mime_not_allowed',
                    __('Media MIME type is not allowed by the current policy.', 'dbvc'),
                    ['status' => 500]
                );
            }

            return true;
        }
        if (! empty($policy_context['dbvc_cc_media_block_private_hosts'])) {
            if ($host === 'localhost' || substr($host, -6) === '.local') {
                return new WP_Error(
                    'dbvc_cc_media_source_private_host',
                    __('Media source host is private and blocked by policy.', 'dbvc'),
                    ['status' => 500]
                );
            }
            if (filter_var($host, FILTER_VALIDATE_IP) && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return new WP_Error(
                    'dbvc_cc_media_source_private_ip',
                    __('Media source IP is private and blocked by policy.', 'dbvc'),
                    ['status' => 500]
                );
            }
        }

        $mime_guess = isset($resolved_media['mime_guess']) ? sanitize_text_field((string) $resolved_media['mime_guess']) : '';
        $mime_allowlist = isset($policy_context['dbvc_cc_media_mime_allowlist']) && is_array($policy_context['dbvc_cc_media_mime_allowlist'])
            ? $policy_context['dbvc_cc_media_mime_allowlist']
            : [];
        if (! $this->dbvc_cc_is_media_mime_allowed($mime_guess, $mime_allowlist)) {
            return new WP_Error(
                'dbvc_cc_media_mime_not_allowed',
                __('Media MIME type is not allowed by the current policy.', 'dbvc'),
                ['status' => 500]
            );
        }

        return true;
    }

    /**
     * @param string $value
     * @return array<int, string>
     */
    private function dbvc_cc_parse_media_mime_allowlist($value)
    {
        $tokens = preg_split('/[\s,]+/', strtolower((string) $value));
        if (! is_array($tokens)) {
            return [];
        }

        $allowlist = [];
        foreach ($tokens as $token) {
            $mime = trim((string) $token);
            if ($mime === '' || ! preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+*-]+$/', $mime)) {
                continue;
            }
            $allowlist[$mime] = true;
        }

        return array_keys($allowlist);
    }

    /**
     * @param string $value
     * @return array<int, string>
     */
    private function dbvc_cc_parse_domain_list($value)
    {
        $items = preg_split('/[\s,]+/', strtolower((string) $value));
        if (! is_array($items)) {
            return [];
        }

        $domains = [];
        foreach ($items as $item) {
            $domain = trim((string) $item);
            if ($domain === '') {
                continue;
            }
            $domain = preg_replace('/[^a-z0-9.\-]/', '', $domain);
            if (! is_string($domain) || $domain === '') {
                continue;
            }
            $domains[$domain] = true;
        }

        return array_keys($domains);
    }

    /**
     * @param string             $mime_guess
     * @param array<int, string> $allowlist
     * @return bool
     */
    private function dbvc_cc_is_media_mime_allowed($mime_guess, array $allowlist = [])
    {
        $mime = strtolower(trim((string) $mime_guess));
        if ($mime === '' || empty($allowlist)) {
            return true;
        }

        foreach ($allowlist as $allowed_mime) {
            $allowed = strtolower(trim((string) $allowed_mime));
            if ($allowed === '') {
                continue;
            }
            if ($allowed === $mime) {
                return true;
            }
            if (substr($allowed, -2) === '/*') {
                $prefix = substr($allowed, 0, -1);
                if ($prefix !== '' && strpos($mime, $prefix) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param string $source_url
     * @param string $mime_guess
     * @return string
     */
    private function dbvc_cc_resolve_media_filename($source_url, $mime_guess = '')
    {
        $path = (string) wp_parse_url((string) $source_url, PHP_URL_PATH);
        $filename = sanitize_file_name(basename($path));
        if ($filename !== '' && strpos($filename, '.') !== false) {
            return $filename;
        }

        $extension = '';
        $mime_guess = strtolower(trim((string) $mime_guess));
        if ($mime_guess === 'image/jpeg') {
            $extension = 'jpg';
        } elseif ($mime_guess === 'image/png') {
            $extension = 'png';
        } elseif ($mime_guess === 'image/gif') {
            $extension = 'gif';
        } elseif ($mime_guess === 'image/webp') {
            $extension = 'webp';
        } elseif ($mime_guess === 'image/svg+xml') {
            $extension = 'svg';
        } elseif ($mime_guess === 'video/mp4') {
            $extension = 'mp4';
        } elseif ($mime_guess === 'application/pdf') {
            $extension = 'pdf';
        }

        return 'dbvc-cc-media-' . substr(hash('sha256', (string) $source_url), 0, 12) . ($extension !== '' ? '.' . $extension : '.bin');
    }

    /**
     * @param string               $source_url
     * @param array<string, mixed> $resolved_media
     * @return string
     */
    private function dbvc_cc_resolve_media_attachment_title($source_url, array $resolved_media = [])
    {
        $alt_text = isset($resolved_media['alt_text']) ? sanitize_text_field((string) $resolved_media['alt_text']) : '';
        if ($alt_text !== '') {
            return $alt_text;
        }

        $caption_text = isset($resolved_media['caption_text']) ? sanitize_text_field((string) $resolved_media['caption_text']) : '';
        if ($caption_text !== '') {
            return $caption_text;
        }

        $path = (string) wp_parse_url((string) $source_url, PHP_URL_PATH);
        $basename = sanitize_text_field((string) pathinfo($path, PATHINFO_FILENAME));
        if ($basename !== '') {
            return ucwords(str_replace(['-', '_'], ' ', $basename));
        }

        return __('Imported Media', 'dbvc');
    }

    /**
     * @param string $domain
     * @param string $path
     * @return array<string, string>
     */
    private function dbvc_cc_resolve_storage_context($domain, $path)
    {
        if (! class_exists('DBVC_CC_Artifact_Manager')) {
            return [];
        }

        $base_dir = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        if (! is_string($base_dir) || $base_dir === '' || ! is_dir($base_dir)) {
            return [];
        }

        $base_real = realpath($base_dir);
        if (! is_string($base_real) || $base_real === '') {
            return [];
        }

        $domain_key = preg_replace('/[^a-z0-9.\-]/', '', strtolower(trim((string) $domain)));
        $relative_path = $this->dbvc_cc_normalize_source_path($path);
        if (! is_string($domain_key) || $domain_key === '' || $relative_path === '') {
            return [];
        }

        $domain_dir = trailingslashit($base_real) . $domain_key;
        $page_dir = trailingslashit($domain_dir) . $relative_path;
        if (! is_dir($page_dir) || ! dbvc_cc_path_is_within($page_dir, $base_real)) {
            return [];
        }

        $slug = basename($relative_path);
        $artifact_file = trailingslashit($page_dir) . $slug . '.json';
        $elements_file = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_ELEMENTS_V2_SUFFIX;
        $sections_file = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_SECTIONS_V2_SUFFIX;
        $section_typing_file = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_SECTION_TYPING_V2_SUFFIX;
        $ingestion_package_file = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_INGESTION_PACKAGE_V2_SUFFIX;
        $media_candidates_file = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_MEDIA_CANDIDATES_V1_SUFFIX;

        $artifact = $this->dbvc_cc_read_json_file($artifact_file);
        $source_url = '';
        if (is_array($artifact)) {
            if (isset($artifact['source_url'])) {
                $source_url = esc_url_raw((string) $artifact['source_url']);
            } elseif (isset($artifact['provenance']['source_url'])) {
                $source_url = esc_url_raw((string) $artifact['provenance']['source_url']);
            }
        }
        if ($source_url === '') {
            $source_url = 'https://' . $domain_key . '/' . ltrim($relative_path, '/');
        }

        return [
            'domain' => $domain_key,
            'path' => $relative_path,
            'slug' => $slug,
            'page_dir' => $page_dir,
            'artifact_file' => $artifact_file,
            'elements_file' => $elements_file,
            'sections_file' => $sections_file,
            'section_typing_file' => $section_typing_file,
            'ingestion_package_file' => $ingestion_package_file,
            'media_candidates_file' => $media_candidates_file,
            'source_url' => $source_url,
        ];
    }

    /**
     * @param string $path
     * @return array<string, mixed>|null
     */
    private function dbvc_cc_read_json_file($path)
    {
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param string $value
     * @param int    $max_chars
     * @return string
     */
    private function dbvc_cc_truncate_preview($value, $max_chars)
    {
        $value = trim((string) $value);
        if ($value === '' || $max_chars <= 0) {
            return $value;
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value, 'UTF-8') > $max_chars) {
                return trim(mb_substr($value, 0, $max_chars, 'UTF-8'));
            }

            return $value;
        }

        if (strlen($value) > $max_chars) {
            return trim(substr($value, 0, $max_chars));
        }

        return $value;
    }

    /**
     * @param string $post_type
     * @param string $path
     * @return array<string, mixed>
     */
    private function dbvc_cc_resolve_create_parent($post_type, $path)
    {
        $post_type = sanitize_key((string) $post_type);
        $path = $this->dbvc_cc_normalize_source_path($path);
        $parent_path = '';
        if (strpos($path, '/') !== false) {
            $parent_path = trim((string) dirname($path), '/');
        }

        if ($parent_path === '' || $parent_path === '.' || ! is_post_type_hierarchical($post_type)) {
            return [
                'parent_path' => '',
                'parent_id' => 0,
                'barrier' => '',
            ];
        }

        $parent = get_page_by_path($parent_path, OBJECT, $post_type);
        if ($parent instanceof WP_Post) {
            return [
                'parent_path' => $parent_path,
                'parent_id' => (int) $parent->ID,
                'barrier' => '',
            ];
        }

        return [
            'parent_path' => $parent_path,
            'parent_id' => 0,
            'barrier' => 'missing_parent_path',
        ];
    }

    /**
     * @param string               $domain
     * @param string               $path
     * @param string               $post_type
     * @param array<string, string> $child_idempotency_meta
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_missing_parent_write_chain($domain, $path, $post_type, array $child_idempotency_meta = [])
    {
        $post_type = sanitize_key((string) $post_type);
        $path = $this->dbvc_cc_normalize_source_path($path);
        if (! is_post_type_hierarchical($post_type) || strpos($path, '/') === false) {
            return [
                'operations' => [],
                'parent_path' => '',
            ];
        }

        $segments = array_values(array_filter(explode('/', $path), static function ($segment) {
            return $segment !== '';
        }));
        if (count($segments) < 2) {
            return [
                'operations' => [],
                'parent_path' => '',
            ];
        }

        $operations = [];
        $current_parent_ref = '';
        $current_parent_id = 0;
        $parent_path = '';

        for ($index = 0; $index < count($segments) - 1; $index++) {
            $parent_segments = array_slice($segments, 0, $index + 1);
            $parent_path = implode('/', $parent_segments);
            $existing_parent = get_page_by_path($parent_path, OBJECT, $post_type);
            if ($existing_parent instanceof WP_Post) {
                $current_parent_id = (int) $existing_parent->ID;
                $current_parent_ref = 'post:' . $current_parent_id;
                continue;
            }

            $parent_slug = sanitize_title((string) end($parent_segments));
            $parent_write_operation_id = $this->dbvc_cc_build_operation_id('write_entity_parent', $domain, $parent_path, 'post:' . $post_type, 'prepare_parent');
            $parent_source_url = '';
            if (! empty($child_idempotency_meta[DBVC_CC_Contracts::IMPORT_META_SOURCE_URL])) {
                $parent_source_url = 'https://' . strtolower(sanitize_text_field((string) $domain)) . '/' . $parent_path;
            }
            $parent_idempotency_meta = $this->dbvc_cc_build_idempotency_meta($domain, $parent_path, $parent_source_url);
            $parent_target_ref = '@' . $parent_write_operation_id;

            $operations[] = [
                'write_operation_id' => $parent_write_operation_id,
                'source_operation_id' => '',
                'write_stage' => 'entity',
                'write_status' => 'prepared',
                'result' => 'prepared_create_parent',
                'action' => 'create_post',
                'entity_key' => 'post:' . $post_type,
                'entity_object_type' => 'post',
                'entity_subtype' => $post_type,
                'depends_on' => $current_parent_ref !== '' && strpos($current_parent_ref, '@') === 0 ? [substr($current_parent_ref, 1)] : [],
                'target_entity_id' => 0,
                'target_entity_ref' => $parent_target_ref,
                'resolution_strategy' => 'synthetic_parent_chain',
                'planned_post_args' => [
                    'post_type' => $post_type,
                    'post_status' => 'draft',
                    'post_name' => $parent_slug,
                    'post_title' => ucwords(str_replace('-', ' ', $parent_slug)),
                    'post_parent' => $current_parent_id,
                    'post_parent_ref' => $current_parent_ref,
                ],
                'planned_create_parent' => [
                    'path' => $index > 0 ? implode('/', array_slice($segments, 0, $index)) : '',
                    'id' => $current_parent_id,
                ],
                'idempotency_meta' => $parent_idempotency_meta,
                'source_payload' => [
                    'source_url' => $parent_source_url,
                    'page_title_candidate' => ucwords(str_replace('-', ' ', $parent_slug)),
                    'page_slug_candidate' => $parent_slug,
                    'synthetic_parent' => true,
                ],
                'is_supporting_parent' => true,
            ];

            $current_parent_ref = $parent_target_ref;
            $current_parent_id = 0;
        }

        return [
            'operations' => $operations,
            'parent_path' => $parent_path,
        ];
    }

    /**
     * @param string $domain
     * @param string $path
     * @param string $source_url
     * @return array<string, string>
     */
    private function dbvc_cc_build_idempotency_meta($domain, $path, $source_url)
    {
        return [
            DBVC_CC_Contracts::IMPORT_META_SOURCE_URL => esc_url_raw((string) $source_url),
            DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH => $this->dbvc_cc_normalize_source_path($path),
            DBVC_CC_Contracts::IMPORT_META_SOURCE_HASH => $this->dbvc_cc_build_source_hash($domain, $path),
        ];
    }

    /**
     * @param string $path
     * @return string
     */
    private function dbvc_cc_normalize_source_path($path)
    {
        $path = trim((string) $path);
        $path = trim($path, '/');

        return $path === '' ? 'home' : sanitize_text_field($path);
    }

    /**
     * @param string $domain
     * @param string $path
     * @return string
     */
    private function dbvc_cc_build_source_hash($domain, $path)
    {
        return hash('sha256', wp_json_encode([
            'domain' => strtolower(sanitize_text_field((string) $domain)),
            'path' => $this->dbvc_cc_normalize_source_path($path),
        ]));
    }

    /**
     * @param string            $domain
     * @param string            $path
     * @param string            $source_url
     * @param array<int, mixed> $mapping_operations
     * @param array<int, mixed> $media_operations
     * @param string            $default_entity_key
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_entity_operations($domain, $path, $source_url, array $mapping_operations, array $media_operations, $default_entity_key)
    {
        $entity_rows = [];
        foreach ($mapping_operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            $target_ref = isset($operation['target_ref']) ? sanitize_text_field((string) $operation['target_ref']) : '';
            if ($target_ref === '') {
                continue;
            }

            $target_meta = $this->dbvc_cc_parse_target_ref($target_ref, $default_entity_key);
            $entity_key = isset($target_meta['entity_key']) ? (string) $target_meta['entity_key'] : '';
            if ($entity_key === '') {
                continue;
            }

            if (! isset($entity_rows[$entity_key])) {
                $entity_meta = $this->dbvc_cc_parse_entity_key($entity_key);
                $entity_rows[$entity_key] = [
                    'operation_id' => $this->dbvc_cc_build_operation_id('entity', $domain, $path, $entity_key, 'resolve_target_entity'),
                    'operation_type' => 'resolve_target_entity',
                    'entity_key' => $entity_key,
                    'entity_object_type' => isset($entity_meta['object_type']) ? (string) $entity_meta['object_type'] : '',
                    'entity_subtype' => isset($entity_meta['subtype']) ? (string) $entity_meta['subtype'] : '',
                    'result' => 'would_resolve',
                    'depends_on' => [],
                    'dependency_hints' => [],
                    'target_ref_samples' => [],
                ];
            }

            if (
                count($entity_rows[$entity_key]['target_ref_samples']) < 5
                && ! in_array($target_ref, $entity_rows[$entity_key]['target_ref_samples'], true)
            ) {
                $entity_rows[$entity_key]['target_ref_samples'][] = $target_ref;
            }
        }

        foreach ($media_operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            $target_ref = isset($operation['target_ref']) ? sanitize_text_field((string) $operation['target_ref']) : '';
            if ($target_ref === '') {
                continue;
            }

            $target_meta = $this->dbvc_cc_parse_target_ref($target_ref, $default_entity_key);
            $entity_key = isset($target_meta['entity_key']) ? (string) $target_meta['entity_key'] : '';
            if ($entity_key === '') {
                continue;
            }

            if (! isset($entity_rows[$entity_key])) {
                $entity_meta = $this->dbvc_cc_parse_entity_key($entity_key);
                $entity_rows[$entity_key] = [
                    'operation_id' => $this->dbvc_cc_build_operation_id('entity', $domain, $path, $entity_key, 'resolve_target_entity'),
                    'operation_type' => 'resolve_target_entity',
                    'entity_key' => $entity_key,
                    'entity_object_type' => isset($entity_meta['object_type']) ? (string) $entity_meta['object_type'] : '',
                    'entity_subtype' => isset($entity_meta['subtype']) ? (string) $entity_meta['subtype'] : '',
                    'result' => 'would_resolve',
                    'depends_on' => [],
                    'dependency_hints' => [],
                    'target_ref_samples' => [],
                ];
            }

            if (
                count($entity_rows[$entity_key]['target_ref_samples']) < 5
                && ! in_array($target_ref, $entity_rows[$entity_key]['target_ref_samples'], true)
            ) {
                $entity_rows[$entity_key]['target_ref_samples'][] = $target_ref;
            }
        }

        uksort(
            $entity_rows,
            static function ($left, $right) {
                return strnatcasecmp((string) $left, (string) $right);
            }
        );

        $entity_operations = [];
        $operation_id_by_entity_key = [];
        $resolution_issues = [];
        foreach ($entity_rows as $entity_key => $entity_operation) {
            if (! is_array($entity_operation)) {
                continue;
            }

            $samples = isset($entity_operation['target_ref_samples']) && is_array($entity_operation['target_ref_samples'])
                ? array_values($entity_operation['target_ref_samples'])
                : [];
            usort(
                $samples,
                static function ($left, $right) {
                    return strnatcasecmp((string) $left, (string) $right);
                }
            );
            $entity_operation['target_ref_samples'] = $samples;
            $entity_operation = $this->dbvc_cc_apply_entity_resolution($entity_operation, $domain, $path, $source_url);

            $dbvc_cc_resolution_issue = $this->dbvc_cc_build_entity_resolution_issue($entity_operation);
            if (is_array($dbvc_cc_resolution_issue)) {
                $resolution_issues[] = $dbvc_cc_resolution_issue;
            }

            $entity_operations[] = $entity_operation;
            $operation_id_by_entity_key[(string) $entity_key] = isset($entity_operation['operation_id'])
                ? (string) $entity_operation['operation_id']
                : '';
        }

        return [
            'entity_operations' => $entity_operations,
            'operation_id_by_entity_key' => $operation_id_by_entity_key,
            'resolution_issues' => $resolution_issues,
        ];
    }

    /**
     * @param array<string, mixed> $entity_operation
     * @param string               $domain
     * @param string               $path
     * @param string               $source_url
     * @return array<string, mixed>
     */
    private function dbvc_cc_apply_entity_resolution(array $entity_operation, $domain, $path, $source_url)
    {
        $entity_object_type = isset($entity_operation['entity_object_type']) ? sanitize_key((string) $entity_operation['entity_object_type']) : '';
        $entity_subtype = isset($entity_operation['entity_subtype']) ? sanitize_key((string) $entity_operation['entity_subtype']) : '';

        if ($entity_object_type !== 'post') {
            $entity_operation['result'] = 'blocked_needs_review';
            $entity_operation['resolution_status'] = 'unsupported_object_type';
            $entity_operation['resolution_strategy'] = 'unsupported_object_type';
            $entity_operation['write_intent'] = 'blocked';
            $entity_operation['write_barrier'] = 'unsupported_object_type';
            $entity_operation['existing_entity_id'] = 0;
            $entity_operation['existing_entity_status'] = '';
            $entity_operation['existing_entity_title'] = '';
            $entity_operation['candidate_entity_ids'] = [];
            $entity_operation['candidate_count'] = 0;
            $entity_operation['post_name_candidate'] = sanitize_title((string) basename((string) $path));
            $entity_operation['source_url'] = esc_url_raw((string) $source_url);
            return $entity_operation;
        }

        $dbvc_cc_resolution = $this->dbvc_cc_resolve_post_entity($entity_subtype, $domain, $path, $source_url);
        foreach ($dbvc_cc_resolution as $dbvc_cc_key => $dbvc_cc_value) {
            $entity_operation[$dbvc_cc_key] = $dbvc_cc_value;
        }

        return $entity_operation;
    }

    /**
     * @param string $post_type
     * @param string $domain
     * @param string $path
     * @param string $source_url
     * @return array<string, mixed>
     */
    private function dbvc_cc_resolve_post_entity($post_type, $domain, $path, $source_url)
    {
        $post_type = sanitize_key((string) $post_type);
        $path = $this->dbvc_cc_normalize_source_path($path);
        $domain = sanitize_text_field((string) $domain);
        $source_url = esc_url_raw((string) $source_url);
        $slug_candidate = sanitize_title((string) basename($path));
        $settings = class_exists('DBVC_CC_Settings_Service')
            ? DBVC_CC_Settings_Service::get_options()
            : [];
        $slug_collision_policy = isset($settings['slug_collision_policy'])
            ? sanitize_key((string) $settings['slug_collision_policy'])
            : 'append-path-hash';

        if ($post_type === '' || ! post_type_exists($post_type)) {
            return [
                'result' => 'blocked_needs_review',
                'resolution_status' => 'unsupported_post_type',
                'resolution_strategy' => 'unsupported_post_type',
                'write_intent' => 'blocked',
                'write_barrier' => 'unsupported_post_type',
                'existing_entity_id' => 0,
                'existing_entity_status' => '',
                'existing_entity_title' => '',
                'candidate_entity_ids' => [],
                'candidate_count' => 0,
                'post_name_candidate' => $slug_candidate,
                'slug_collision_policy' => $slug_collision_policy,
                'source_url' => $source_url,
            ];
        }

        if ($source_url !== '') {
            $meta_candidate_ids = $this->dbvc_cc_find_post_ids_by_source_url($post_type, $source_url);
            if (count($meta_candidate_ids) === 1) {
                return $this->dbvc_cc_build_post_resolution_payload(
                    'would_update_existing',
                    'matched_existing',
                    'source_url_meta',
                    'update',
                    '',
                    (int) $meta_candidate_ids[0],
                    $meta_candidate_ids,
                    $slug_candidate,
                    $slug_collision_policy,
                    $source_url
                );
            }

            if (count($meta_candidate_ids) > 1) {
                return $this->dbvc_cc_build_post_resolution_payload(
                    'blocked_needs_review',
                    'ambiguous_match',
                    'source_url_meta',
                    'blocked',
                    'ambiguous_source_url_match',
                    0,
                    $meta_candidate_ids,
                    $slug_candidate,
                    $slug_collision_policy,
                    $source_url
                );
            }
        }

        $path_candidate_ids = $this->dbvc_cc_find_post_ids_by_source_path($post_type, $path);
        if (count($path_candidate_ids) === 1) {
            return $this->dbvc_cc_build_post_resolution_payload(
                'would_update_existing',
                'matched_existing',
                'source_path_meta',
                'update',
                '',
                (int) $path_candidate_ids[0],
                $path_candidate_ids,
                $slug_candidate,
                $slug_collision_policy,
                $source_url
            );
        }

        if (count($path_candidate_ids) > 1) {
            return $this->dbvc_cc_build_post_resolution_payload(
                'blocked_needs_review',
                'ambiguous_match',
                'source_path_meta',
                'blocked',
                'ambiguous_source_path_match',
                0,
                $path_candidate_ids,
                $slug_candidate,
                $slug_collision_policy,
                $source_url
            );
        }

        $source_hash = $this->dbvc_cc_build_source_hash($domain, $path);
        $hash_candidate_ids = $this->dbvc_cc_find_post_ids_by_source_hash($post_type, $source_hash);
        if (count($hash_candidate_ids) === 1) {
            return $this->dbvc_cc_build_post_resolution_payload(
                'would_update_existing',
                'matched_existing',
                'source_hash_meta',
                'update',
                '',
                (int) $hash_candidate_ids[0],
                $hash_candidate_ids,
                $slug_candidate,
                $slug_collision_policy,
                $source_url
            );
        }

        if (count($hash_candidate_ids) > 1) {
            return $this->dbvc_cc_build_post_resolution_payload(
                'blocked_needs_review',
                'ambiguous_match',
                'source_hash_meta',
                'blocked',
                'ambiguous_source_hash_match',
                0,
                $hash_candidate_ids,
                $slug_candidate,
                $slug_collision_policy,
                $source_url
            );
        }

        if (is_post_type_hierarchical($post_type)) {
            $page = get_page_by_path($path, OBJECT, $post_type);
            if ($page instanceof WP_Post) {
                return $this->dbvc_cc_build_post_resolution_payload(
                    'would_update_existing',
                    'matched_existing',
                    'page_path_match',
                    'update',
                    '',
                    (int) $page->ID,
                    [(int) $page->ID],
                    $slug_candidate,
                    $slug_collision_policy,
                    $source_url
                );
            }
        }

        if ($slug_candidate !== '') {
            $slug_candidate_ids = $this->dbvc_cc_find_post_ids_by_slug($post_type, $slug_candidate);
            if (count($slug_candidate_ids) === 1) {
                return $this->dbvc_cc_build_post_resolution_payload(
                    'would_update_existing',
                    'matched_existing',
                    'post_slug_match',
                    'update',
                    '',
                    (int) $slug_candidate_ids[0],
                    $slug_candidate_ids,
                    $slug_candidate,
                    $slug_collision_policy,
                    $source_url
                );
            }

            if (count($slug_candidate_ids) > 1) {
                return $this->dbvc_cc_build_post_resolution_payload(
                    'blocked_needs_review',
                    'ambiguous_match',
                    'post_slug_match',
                    'blocked',
                    'ambiguous_slug_match',
                    0,
                    $slug_candidate_ids,
                    $slug_candidate,
                    $slug_collision_policy,
                    $source_url
                );
            }
        }

        return $this->dbvc_cc_build_post_resolution_payload(
            'would_create_new',
            'create_new',
            'deterministic_create',
            'create',
            '',
            0,
            [],
            $slug_candidate,
            $slug_collision_policy,
            $source_url
        );
    }

    /**
     * @param string      $result
     * @param string      $resolution_status
     * @param string      $resolution_strategy
     * @param string      $write_intent
     * @param string      $write_barrier
     * @param int         $existing_entity_id
     * @param array<int>  $candidate_entity_ids
     * @param string      $slug_candidate
     * @param string      $slug_collision_policy
     * @param string      $source_url
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_post_resolution_payload($result, $resolution_status, $resolution_strategy, $write_intent, $write_barrier, $existing_entity_id, array $candidate_entity_ids, $slug_candidate, $slug_collision_policy, $source_url)
    {
        $existing_entity_id = absint($existing_entity_id);
        $candidate_entity_ids = array_values(array_map('absint', $candidate_entity_ids));

        $existing_entity_status = '';
        $existing_entity_title = '';
        if ($existing_entity_id > 0) {
            $post = get_post($existing_entity_id);
            if ($post instanceof WP_Post) {
                $existing_entity_status = sanitize_key((string) $post->post_status);
                $existing_entity_title = sanitize_text_field((string) get_the_title($post));
            }
        }

        return [
            'result' => sanitize_key((string) $result),
            'resolution_status' => sanitize_key((string) $resolution_status),
            'resolution_strategy' => sanitize_key((string) $resolution_strategy),
            'write_intent' => sanitize_key((string) $write_intent),
            'write_barrier' => sanitize_key((string) $write_barrier),
            'existing_entity_id' => $existing_entity_id,
            'existing_entity_status' => $existing_entity_status,
            'existing_entity_title' => $existing_entity_title,
            'candidate_entity_ids' => $candidate_entity_ids,
            'candidate_count' => count($candidate_entity_ids),
            'post_name_candidate' => sanitize_title((string) $slug_candidate),
            'slug_collision_policy' => sanitize_key((string) $slug_collision_policy),
            'source_url' => esc_url_raw((string) $source_url),
        ];
    }

    /**
     * @param string $post_type
     * @param string $source_url
     * @return array<int>
     */
    private function dbvc_cc_find_post_ids_by_source_url($post_type, $source_url)
    {
        $query = get_posts([
            'post_type' => sanitize_key((string) $post_type),
            'post_status' => 'any',
            'meta_key' => DBVC_CC_Contracts::IMPORT_META_SOURCE_URL,
            'meta_value' => esc_url_raw((string) $source_url),
            'fields' => 'ids',
            'numberposts' => 3,
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => false,
        ]);

        if (! is_array($query)) {
            return [];
        }

        return array_values(array_map('absint', $query));
    }

    /**
     * @param string $post_type
     * @param string $source_path
     * @return array<int>
     */
    private function dbvc_cc_find_post_ids_by_source_path($post_type, $source_path)
    {
        $query = get_posts([
            'post_type' => sanitize_key((string) $post_type),
            'post_status' => 'any',
            'meta_key' => DBVC_CC_Contracts::IMPORT_META_SOURCE_PATH,
            'meta_value' => $this->dbvc_cc_normalize_source_path($source_path),
            'fields' => 'ids',
            'numberposts' => 3,
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => false,
        ]);

        if (! is_array($query)) {
            return [];
        }

        return array_values(array_map('absint', $query));
    }

    /**
     * @param string $post_type
     * @param string $source_hash
     * @return array<int>
     */
    private function dbvc_cc_find_post_ids_by_source_hash($post_type, $source_hash)
    {
        $query = get_posts([
            'post_type' => sanitize_key((string) $post_type),
            'post_status' => 'any',
            'meta_key' => DBVC_CC_Contracts::IMPORT_META_SOURCE_HASH,
            'meta_value' => sanitize_text_field((string) $source_hash),
            'fields' => 'ids',
            'numberposts' => 3,
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => false,
        ]);

        if (! is_array($query)) {
            return [];
        }

        return array_values(array_map('absint', $query));
    }

    /**
     * @param string $post_type
     * @param string $slug
     * @return array<int>
     */
    private function dbvc_cc_find_post_ids_by_slug($post_type, $slug)
    {
        $query = get_posts([
            'post_type' => sanitize_key((string) $post_type),
            'post_status' => 'any',
            'name' => sanitize_title((string) $slug),
            'fields' => 'ids',
            'numberposts' => 3,
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => false,
        ]);

        if (! is_array($query)) {
            return [];
        }

        return array_values(array_map('absint', $query));
    }

    /**
     * @param array<string, mixed> $entity_operation
     * @return array<string, mixed>|null
     */
    private function dbvc_cc_build_entity_resolution_issue(array $entity_operation)
    {
        $resolution_status = isset($entity_operation['resolution_status']) ? sanitize_key((string) $entity_operation['resolution_status']) : '';
        if (! in_array($resolution_status, ['ambiguous_match', 'unsupported_object_type', 'unsupported_post_type'], true)) {
            return null;
        }

        $entity_key = isset($entity_operation['entity_key']) ? sanitize_text_field((string) $entity_operation['entity_key']) : 'post:page';
        $resolution_strategy = isset($entity_operation['resolution_strategy']) ? sanitize_key((string) $entity_operation['resolution_strategy']) : 'unknown';
        $candidate_count = isset($entity_operation['candidate_count']) ? absint($entity_operation['candidate_count']) : 0;

        if ($resolution_status === 'ambiguous_match') {
            return $this->dbvc_cc_build_issue(
                'entity_resolution_ambiguous',
                sprintf(
                    /* translators: 1: entity key, 2: strategy, 3: candidate count */
                    __('Target entity resolution is ambiguous for %1$s via %2$s (%3$d candidates).', 'dbvc'),
                    $entity_key,
                    $resolution_strategy,
                    $candidate_count
                ),
                true
            );
        }

        return $this->dbvc_cc_build_issue(
            'entity_resolution_unsupported',
            sprintf(
                /* translators: 1: entity key, 2: strategy */
                __('Target entity resolution is unsupported for %1$s (%2$s).', 'dbvc'),
                $entity_key,
                $resolution_strategy
            ),
            true
        );
    }

    /**
     * @param string $domain
     * @param string $path
     * @param array<int, mixed> $mapping_operations
     * @param array<string, string> $entity_operation_index
     * @param string $default_entity_key
     * @return array<int, array<string, mixed>>
     */
    private function dbvc_cc_build_field_operations($domain, $path, array $mapping_operations, array $entity_operation_index, $default_entity_key)
    {
        $operations = [];
        foreach ($mapping_operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            $section_id = isset($operation['section_id']) ? sanitize_text_field((string) $operation['section_id']) : '';
            $target_ref = isset($operation['target_ref']) ? sanitize_text_field((string) $operation['target_ref']) : '';
            if ($section_id === '' || $target_ref === '') {
                continue;
            }

            $target_meta = $this->dbvc_cc_parse_target_ref($target_ref, $default_entity_key);
            $entity_key = isset($target_meta['entity_key']) ? (string) $target_meta['entity_key'] : '';
            $depends_on = [];
            if ($entity_key !== '' && isset($entity_operation_index[$entity_key])) {
                $depends_on[] = (string) $entity_operation_index[$entity_key];
            }

            $field_operation = [
                'operation_id' => $this->dbvc_cc_build_operation_id('field', $domain, $path, $section_id, $target_ref),
                'operation_type' => 'upsert_field_mapping',
                'section_id' => $section_id,
                'target_ref' => $target_ref,
                'target_family' => isset($target_meta['target_family']) ? (string) $target_meta['target_family'] : '',
                'target_field_key' => isset($target_meta['target_field_key']) ? (string) $target_meta['target_field_key'] : '',
                'entity_key' => $entity_key,
                'depends_on' => $depends_on,
                'dependency_hints' => empty($depends_on) ? [] : ['resolve_target_entity'],
                'result' => 'would_upsert',
                'confidence' => isset($operation['confidence']) ? (float) $operation['confidence'] : null,
            ];
            if (array_key_exists('resolved_value_candidate', $operation)) {
                $field_operation['resolved_value_candidate'] = $operation['resolved_value_candidate'];
            }
            if (isset($operation['resolved_value_format'])) {
                $field_operation['resolved_value_format'] = sanitize_key((string) $operation['resolved_value_format']);
            }
            if (isset($operation['resolved_value_preview'])) {
                $field_operation['resolved_value_preview'] = sanitize_text_field((string) $operation['resolved_value_preview']);
            }
            if (isset($operation['resolved_value_origin'])) {
                $field_operation['resolved_value_origin'] = sanitize_key((string) $operation['resolved_value_origin']);
            }
            if (isset($operation['source_payload']) && is_array($operation['source_payload']) && ! empty($operation['source_payload'])) {
                $field_operation['source_payload'] = $operation['source_payload'];
            }
            $operations[] = $field_operation;
        }

        usort(
            $operations,
            static function ($left, $right) {
                $left_id = isset($left['operation_id']) ? (string) $left['operation_id'] : '';
                $right_id = isset($right['operation_id']) ? (string) $right['operation_id'] : '';
                return strnatcasecmp($left_id, $right_id);
            }
        );

        return $operations;
    }

    /**
     * @param string $domain
     * @param string $path
     * @param array<int, mixed> $media_operations
     * @param array<string, string> $entity_operation_index
     * @param string $default_entity_key
     * @return array<int, array<string, mixed>>
     */
    private function dbvc_cc_build_media_operations($domain, $path, array $media_operations, array $entity_operation_index, $default_entity_key)
    {
        $operations = [];
        foreach ($media_operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            $media_id = isset($operation['media_id']) ? sanitize_text_field((string) $operation['media_id']) : '';
            $target_ref = isset($operation['target_ref']) ? sanitize_text_field((string) $operation['target_ref']) : '';
            if ($media_id === '' || $target_ref === '') {
                continue;
            }

            $target_meta = $this->dbvc_cc_parse_target_ref($target_ref, $default_entity_key);
            $entity_key = isset($target_meta['entity_key']) ? (string) $target_meta['entity_key'] : '';
            $depends_on = [];
            if ($entity_key !== '' && isset($entity_operation_index[$entity_key])) {
                $depends_on[] = (string) $entity_operation_index[$entity_key];
            }

            $media_operation_row = [
                'operation_id' => $this->dbvc_cc_build_operation_id('media', $domain, $path, $media_id, $target_ref),
                'operation_type' => 'upsert_media_mapping',
                'media_id' => $media_id,
                'target_ref' => $target_ref,
                'target_family' => isset($target_meta['target_family']) ? (string) $target_meta['target_family'] : '',
                'target_field_key' => isset($target_meta['target_field_key']) ? (string) $target_meta['target_field_key'] : '',
                'entity_key' => $entity_key,
                'depends_on' => $depends_on,
                'dependency_hints' => empty($depends_on) ? [] : ['resolve_target_entity'],
                'media_kind' => isset($operation['media_kind']) ? sanitize_key((string) $operation['media_kind']) : '',
                'source_url' => isset($operation['source_url']) ? esc_url_raw((string) $operation['source_url']) : '',
                'storage_shape' => isset($operation['storage_shape']) ? sanitize_key((string) $operation['storage_shape']) : 'attachment_id',
                'multi_value' => ! empty($operation['multi_value']),
                'return_format' => isset($operation['return_format']) ? sanitize_key((string) $operation['return_format']) : 'id',
                'accepted_media_kinds' => isset($operation['accepted_media_kinds']) && is_array($operation['accepted_media_kinds'])
                    ? array_values(array_map('sanitize_key', $operation['accepted_media_kinds']))
                    : [],
                'normalized_value_strategy' => isset($operation['normalized_value_strategy']) ? sanitize_key((string) $operation['normalized_value_strategy']) : 'replace_single_attachment',
                'result' => 'would_upsert',
            ];
            if (isset($operation['source_payload']) && is_array($operation['source_payload']) && ! empty($operation['source_payload'])) {
                $media_operation_row['source_payload'] = $operation['source_payload'];
            }
            if (isset($operation['resolved_media_candidate']) && is_array($operation['resolved_media_candidate']) && ! empty($operation['resolved_media_candidate'])) {
                $media_operation_row['resolved_media_candidate'] = $operation['resolved_media_candidate'];
            }
            $operations[] = $media_operation_row;
        }

        usort(
            $operations,
            static function ($left, $right) {
                $left_id = isset($left['operation_id']) ? (string) $left['operation_id'] : '';
                $right_id = isset($right['operation_id']) ? (string) $right['operation_id'] : '';
                return strnatcasecmp($left_id, $right_id);
            }
        );

        return $operations;
    }

    /**
     * @param int $start_index
     * @param array<int, array<string, mixed>> $operations
     * @return array<string, mixed>
     */
    private function dbvc_cc_apply_execution_order($start_index, array $operations)
    {
        $next_index = max(1, absint($start_index));
        $ordered = [];
        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            $operation['execution_order'] = $next_index;
            $next_index++;
            $ordered[] = $operation;
        }

        return [
            'operations' => $ordered,
            'next_index' => $next_index,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $operations
     * @return string
     */
    private function dbvc_cc_build_operation_graph_fingerprint(array $operations)
    {
        $normalized = [];
        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            $depends_on = isset($operation['depends_on']) && is_array($operation['depends_on'])
                ? array_values($operation['depends_on'])
                : [];
            usort(
                $depends_on,
                static function ($left, $right) {
                    return strnatcasecmp((string) $left, (string) $right);
                }
            );

            $normalized[] = [
                'operation_id' => isset($operation['operation_id']) ? (string) $operation['operation_id'] : '',
                'operation_type' => isset($operation['operation_type']) ? (string) $operation['operation_type'] : '',
                'result' => isset($operation['result']) ? (string) $operation['result'] : '',
                'resolution_status' => isset($operation['resolution_status']) ? (string) $operation['resolution_status'] : '',
                'existing_entity_id' => isset($operation['existing_entity_id']) ? absint($operation['existing_entity_id']) : 0,
                'depends_on' => $depends_on,
            ];
        }

        return 'dbvc_cc_graph_' . substr(hash('sha256', wp_json_encode($normalized)), 0, 16);
    }

    /**
     * @param array<int, array<string, mixed>> $operations
     * @return int
     */
    private function dbvc_cc_count_dependency_edges(array $operations)
    {
        $count = 0;
        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            $depends_on = isset($operation['depends_on']) && is_array($operation['depends_on'])
                ? $operation['depends_on']
                : [];
            $count += count($depends_on);
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $operations
     * @param string                           $result
     * @return int
     */
    private function dbvc_cc_count_operations_by_result(array $operations, $result)
    {
        $result = sanitize_key((string) $result);
        $count = 0;
        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            if (isset($operation['result']) && sanitize_key((string) $operation['result']) === $result) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $dry_run_plan
     * @param string $plan_status
     * @param string $operation_graph_fingerprint
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_execution_trace(array $dry_run_plan, $plan_status, $operation_graph_fingerprint)
    {
        $trace = isset($dry_run_plan['trace']) && is_array($dry_run_plan['trace']) ? $dry_run_plan['trace'] : [];
        $artifact_refs = isset($trace['artifact_refs']) && is_array($trace['artifact_refs']) ? $trace['artifact_refs'] : [];
        $dbvc_cc_phase4_context = $this->dbvc_cc_build_phase4_context($dry_run_plan);

        return [
            'plan_status' => sanitize_key((string) $plan_status),
            'source_pipeline_id' => isset($trace['source_pipeline_id']) ? sanitize_text_field((string) $trace['source_pipeline_id']) : '',
            'artifact_refs' => $artifact_refs,
            'default_entity_key' => isset($dbvc_cc_phase4_context['default_entity_key']) ? (string) $dbvc_cc_phase4_context['default_entity_key'] : 'post:page',
            'default_entity_reason' => isset($dbvc_cc_phase4_context['default_entity_reason']) ? (string) $dbvc_cc_phase4_context['default_entity_reason'] : '',
            'override_post_type' => isset($dbvc_cc_phase4_context['override_post_type']) ? (string) $dbvc_cc_phase4_context['override_post_type'] : '',
            'suggested_post_type' => isset($dbvc_cc_phase4_context['suggested_post_type']) ? (string) $dbvc_cc_phase4_context['suggested_post_type'] : '',
            'handoff_schema_version' => isset($dbvc_cc_phase4_context['handoff_schema_version']) ? (string) $dbvc_cc_phase4_context['handoff_schema_version'] : '',
            'handoff_generated_at' => isset($dbvc_cc_phase4_context['handoff_generated_at']) ? (string) $dbvc_cc_phase4_context['handoff_generated_at'] : '',
            'operation_graph_fingerprint' => sanitize_text_field((string) $operation_graph_fingerprint),
        ];
    }

    /**
     * @param array<string, mixed> $dry_run_plan
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_phase4_context(array $dry_run_plan)
    {
        $dbvc_cc_phase4_input = isset($dry_run_plan['phase4_input']) && is_array($dry_run_plan['phase4_input'])
            ? $dry_run_plan['phase4_input']
            : [];
        $dbvc_cc_object_hints = isset($dbvc_cc_phase4_input['object_hints']) && is_array($dbvc_cc_phase4_input['object_hints'])
            ? $dbvc_cc_phase4_input['object_hints']
            : [];

        $dbvc_cc_default_entity_key = $this->dbvc_cc_resolve_default_entity_key($dry_run_plan);

        return [
            'default_entity_key' => $dbvc_cc_default_entity_key,
            'default_entity_reason' => isset($dbvc_cc_object_hints['default_entity_reason'])
                ? sanitize_key((string) $dbvc_cc_object_hints['default_entity_reason'])
                : '',
            'override_post_type' => isset($dbvc_cc_object_hints['override_post_type'])
                ? sanitize_key((string) $dbvc_cc_object_hints['override_post_type'])
                : '',
            'suggested_post_type' => isset($dbvc_cc_object_hints['suggested_post_type'])
                ? sanitize_key((string) $dbvc_cc_object_hints['suggested_post_type'])
                : '',
            'suggested_post_type_confidence' => isset($dbvc_cc_object_hints['suggested_post_type_confidence'])
                ? (float) $dbvc_cc_object_hints['suggested_post_type_confidence']
                : 0.0,
            'handoff_schema_version' => isset($dry_run_plan['handoff_schema_version'])
                ? sanitize_text_field((string) $dry_run_plan['handoff_schema_version'])
                : '',
            'handoff_generated_at' => isset($dry_run_plan['handoff_generated_at'])
                ? sanitize_text_field((string) $dry_run_plan['handoff_generated_at'])
                : '',
        ];
    }

    /**
     * @param array<string, mixed> $dry_run_plan
     * @return string
     */
    private function dbvc_cc_resolve_default_entity_key(array $dry_run_plan)
    {
        $phase4_input = isset($dry_run_plan['phase4_input']) && is_array($dry_run_plan['phase4_input'])
            ? $dry_run_plan['phase4_input']
            : [];

        $candidates = [
            isset($phase4_input['default_entity_key']) ? (string) $phase4_input['default_entity_key'] : '',
            isset($phase4_input['object_hints']['default_entity_key']) ? (string) $phase4_input['object_hints']['default_entity_key'] : '',
        ];

        foreach ($candidates as $candidate) {
            $entity_key = sanitize_text_field((string) $candidate);
            if (preg_match('/^[a-z0-9_]+:[a-z0-9_]+$/', $entity_key) === 1) {
                return $entity_key;
            }
        }

        return 'post:page';
    }

    /**
     * @param string $target_ref
     * @param string $default_entity_key
     * @return array<string, string>
     */
    private function dbvc_cc_parse_target_ref($target_ref, $default_entity_key)
    {
        $target_ref = sanitize_text_field((string) $target_ref);
        $default_entity = $this->dbvc_cc_parse_entity_key($default_entity_key);
        $target_family = '';
        $target_field_key = '';
        $entity_key = isset($default_entity['entity_key']) ? (string) $default_entity['entity_key'] : 'post:page';
        $entity_object_type = isset($default_entity['object_type']) ? (string) $default_entity['object_type'] : 'post';
        $entity_subtype = isset($default_entity['subtype']) ? (string) $default_entity['subtype'] : 'page';

        $parts = explode(':', $target_ref);
        if (! empty($parts)) {
            $target_family = sanitize_key((string) $parts[0]);
        }

        if ($target_family === 'meta' && count($parts) >= 4) {
            $object_type = sanitize_key((string) $parts[1]);
            $subtype = sanitize_key((string) $parts[2]);
            $field_key = sanitize_key((string) implode('_', array_slice($parts, 3)));
            if ($object_type !== '' && $subtype !== '') {
                $entity_key = $object_type . ':' . $subtype;
                $entity_object_type = $object_type;
                $entity_subtype = $subtype;
            }
            $target_field_key = $field_key;
        } elseif ($target_family === 'core' && count($parts) >= 2) {
            $target_field_key = sanitize_key((string) implode('_', array_slice($parts, 1)));
        } elseif ($target_family === 'acf' && count($parts) >= 3) {
            $target_field_key = sanitize_key((string) $parts[2]);
        }

        return [
            'target_family' => $target_family,
            'target_field_key' => $target_field_key,
            'entity_key' => $entity_key,
            'entity_object_type' => $entity_object_type,
            'entity_subtype' => $entity_subtype,
        ];
    }

    /**
     * @param string $entity_key
     * @return array<string, string>
     */
    private function dbvc_cc_parse_entity_key($entity_key)
    {
        $entity_key = sanitize_text_field((string) $entity_key);
        $parts = explode(':', $entity_key, 2);
        $object_type = isset($parts[0]) ? sanitize_key((string) $parts[0]) : '';
        $subtype = isset($parts[1]) ? sanitize_key((string) $parts[1]) : '';

        if ($object_type === '') {
            $object_type = 'post';
        }
        if ($subtype === '') {
            $subtype = 'page';
        }

        return [
            'entity_key' => $object_type . ':' . $subtype,
            'object_type' => $object_type,
            'subtype' => $subtype,
        ];
    }
}
