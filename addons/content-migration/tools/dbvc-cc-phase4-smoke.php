<?php
/**
 * Phase 4 smoke check for mapping handoff -> dry-run plan -> executor flows.
 *
 * Usage (inside WordPress context):
 *   wp eval-file addons/content-migration/tools/dbvc-cc-phase4-smoke.php -- example.com home
 *   wp eval-file addons/content-migration/tools/dbvc-cc-phase4-smoke.php -- example.com home --execute=1 --rollback-after-execute=1
 */

if (! defined('ABSPATH')) {
    fwrite(STDERR, "This script must run inside a WordPress runtime.\n");
    exit(1);
}

if (defined('WP_CLI') && WP_CLI && function_exists('get_users')) {
    $admins = get_users([
        'role' => 'administrator',
        'number' => 1,
        'fields' => 'ID',
    ]);
    if (is_array($admins) && ! empty($admins)) {
        wp_set_current_user((int) $admins[0]);
    }
}

$argv = isset($GLOBALS['argv']) && is_array($GLOBALS['argv']) ? $GLOBALS['argv'] : [];
$args = array_slice($argv, 1);
$positionals = [];
$options = [
    'execute' => false,
    'rollback-after-execute' => false,
    'build-if-missing' => true,
];

foreach ($args as $arg) {
    $arg = (string) $arg;
    if (strpos($arg, '--') !== 0) {
        $positionals[] = $arg;
        continue;
    }

    $option_pair = explode('=', substr($arg, 2), 2);
    $option_key = isset($option_pair[0]) ? sanitize_key((string) $option_pair[0]) : '';
    $option_value = isset($option_pair[1]) ? strtolower(trim((string) $option_pair[1])) : '1';
    if ($option_key === '') {
        continue;
    }

    $options[$option_key] = in_array($option_value, ['1', 'true', 'yes', 'on'], true);
}

$domain = isset($positionals[0]) ? sanitize_text_field((string) $positionals[0]) : '';
$path = isset($positionals[1]) ? sanitize_text_field((string) $positionals[1]) : 'home';
$build_if_missing = ! empty($options['build-if-missing']);
$execute_requested = ! empty($options['execute']);
$rollback_after_execute = ! empty($options['rollback-after-execute']);

if ($domain === '') {
    fwrite(STDERR, "Usage: wp eval-file addons/content-migration/tools/dbvc-cc-phase4-smoke.php -- <domain> [path] [--execute=1] [--rollback-after-execute=1] [--build-if-missing=0]\n");
    exit(1);
}

if (! class_exists('DBVC_CC_Import_Plan_Handoff_Service') || ! class_exists('DBVC_CC_Import_Plan_Service') || ! class_exists('DBVC_CC_Import_Executor_Service')) {
    fwrite(STDERR, "Required Content Migration classes are unavailable. Ensure addon modules are loaded.\n");
    exit(1);
}

$handoff = DBVC_CC_Import_Plan_Handoff_Service::get_instance()->get_handoff_payload($domain, $path, $build_if_missing);
if (is_wp_error($handoff)) {
    fwrite(STDERR, "Handoff error ({$handoff->get_error_code()}): {$handoff->get_error_message()}\n");
    exit(2);
}

$dry_run_plan = DBVC_CC_Import_Plan_Service::get_instance()->get_dry_run_plan($domain, $path, $build_if_missing);
if (is_wp_error($dry_run_plan)) {
    fwrite(STDERR, "Dry-run plan error ({$dry_run_plan->get_error_code()}): {$dry_run_plan->get_error_message()}\n");
    exit(2);
}

$dry_run_execution = DBVC_CC_Import_Executor_Service::get_instance()->execute_dry_run($domain, $path, $build_if_missing);
if (is_wp_error($dry_run_execution)) {
    fwrite(STDERR, "Executor dry-run error ({$dry_run_execution->get_error_code()}): {$dry_run_execution->get_error_message()}\n");
    exit(2);
}

$preflight_approval = DBVC_CC_Import_Executor_Service::get_instance()->approve_preflight($domain, $path, $build_if_missing, true);
if (is_wp_error($preflight_approval)) {
    fwrite(STDERR, "Preflight approval error ({$preflight_approval->get_error_code()}): {$preflight_approval->get_error_message()}\n");
    exit(2);
}

$approval_token = isset($preflight_approval['approval']['approval_token']) ? (string) $preflight_approval['approval']['approval_token'] : '';
$execute_result = [
    'status' => 'not_requested',
    'write_performed' => false,
    'message' => 'Execute was not requested. This smoke run validated handoff, dry-run planning, executor dry-run, and preflight approval only.',
];
$rollback_result = null;

if ($execute_requested) {
    $execute_result = DBVC_CC_Import_Executor_Service::get_instance()->execute_write_skeleton($domain, $path, $build_if_missing, true, $approval_token);
    if (is_wp_error($execute_result)) {
        fwrite(STDERR, "Execute error ({$execute_result->get_error_code()}): {$execute_result->get_error_message()}\n");
        exit(2);
    }

    if (
        $rollback_after_execute
        && is_array($execute_result)
        && ! empty($execute_result['run_id'])
        && absint($execute_result['run_id']) > 0
    ) {
        $rollback_result = DBVC_CC_Import_Executor_Service::get_instance()->rollback_run((int) $execute_result['run_id']);
        if (is_wp_error($rollback_result)) {
            fwrite(STDERR, "Rollback error ({$rollback_result->get_error_code()}): {$rollback_result->get_error_message()}\n");
            exit(2);
        }
    }
}

$summary = [
    'generated_at' => current_time('c'),
    'domain' => $domain,
    'path' => $path,
    'smoke_options' => [
        'build_if_missing' => $build_if_missing,
        'execute_requested' => $execute_requested,
        'rollback_after_execute' => $rollback_after_execute,
    ],
    'handoff' => [
        'status' => isset($handoff['status']) ? (string) $handoff['status'] : '',
        'blocking_warning_count' => isset($handoff['blocking_warning_count']) ? absint($handoff['blocking_warning_count']) : 0,
        'default_entity_key' => isset($handoff['phase4_input']['default_entity_key']) ? (string) $handoff['phase4_input']['default_entity_key'] : '',
        'default_entity_reason' => isset($handoff['phase4_input']['object_hints']['default_entity_reason']) ? (string) $handoff['phase4_input']['object_hints']['default_entity_reason'] : '',
        'suggested_post_type' => isset($handoff['phase4_input']['object_hints']['suggested_post_type']) ? (string) $handoff['phase4_input']['object_hints']['suggested_post_type'] : '',
        'suggested_post_type_confidence' => isset($handoff['phase4_input']['object_hints']['suggested_post_type_confidence'])
            ? (float) $handoff['phase4_input']['object_hints']['suggested_post_type_confidence']
            : 0.0,
        'review' => isset($handoff['review']) && is_array($handoff['review']) ? $handoff['review'] : [],
    ],
    'dry_run_plan' => [
        'status' => isset($dry_run_plan['status']) ? (string) $dry_run_plan['status'] : '',
        'blocking_issue_count' => isset($dry_run_plan['blocking_issue_count']) ? absint($dry_run_plan['blocking_issue_count']) : 0,
        'plan_id' => isset($dry_run_plan['plan_id']) ? (string) $dry_run_plan['plan_id'] : '',
        'handoff_schema_version' => isset($dry_run_plan['handoff_schema_version']) ? (string) $dry_run_plan['handoff_schema_version'] : '',
        'handoff_generated_at' => isset($dry_run_plan['handoff_generated_at']) ? (string) $dry_run_plan['handoff_generated_at'] : '',
        'default_entity_key' => isset($dry_run_plan['phase4_input']['default_entity_key']) ? (string) $dry_run_plan['phase4_input']['default_entity_key'] : '',
        'issues' => isset($dry_run_plan['issues']) && is_array($dry_run_plan['issues']) ? $dry_run_plan['issues'] : [],
    ],
    'dry_run_execution' => [
        'status' => isset($dry_run_execution['status']) ? (string) $dry_run_execution['status'] : '',
        'execution_id' => isset($dry_run_execution['execution_id']) ? (string) $dry_run_execution['execution_id'] : '',
        'default_entity_key' => isset($dry_run_execution['trace']['default_entity_key']) ? (string) $dry_run_execution['trace']['default_entity_key'] : '',
        'default_entity_reason' => isset($dry_run_execution['trace']['default_entity_reason']) ? (string) $dry_run_execution['trace']['default_entity_reason'] : '',
        'handoff_schema_version' => isset($dry_run_execution['trace']['handoff_schema_version']) ? (string) $dry_run_execution['trace']['handoff_schema_version'] : '',
        'first_entity_resolution' => isset($dry_run_execution['operation_graph']['entity_operations'][0]) && is_array($dry_run_execution['operation_graph']['entity_operations'][0])
            ? [
                'result' => isset($dry_run_execution['operation_graph']['entity_operations'][0]['result']) ? (string) $dry_run_execution['operation_graph']['entity_operations'][0]['result'] : '',
                'resolution_status' => isset($dry_run_execution['operation_graph']['entity_operations'][0]['resolution_status']) ? (string) $dry_run_execution['operation_graph']['entity_operations'][0]['resolution_status'] : '',
                'resolution_strategy' => isset($dry_run_execution['operation_graph']['entity_operations'][0]['resolution_strategy']) ? (string) $dry_run_execution['operation_graph']['entity_operations'][0]['resolution_strategy'] : '',
                'existing_entity_id' => isset($dry_run_execution['operation_graph']['entity_operations'][0]['existing_entity_id']) ? (int) $dry_run_execution['operation_graph']['entity_operations'][0]['existing_entity_id'] : 0,
            ]
            : [],
        'operation_counts' => isset($dry_run_execution['operation_counts']) && is_array($dry_run_execution['operation_counts'])
            ? $dry_run_execution['operation_counts']
            : [],
        'issues' => isset($dry_run_execution['issues']) && is_array($dry_run_execution['issues'])
            ? $dry_run_execution['issues']
            : [],
        'write_barriers' => isset($dry_run_execution['write_barriers']) && is_array($dry_run_execution['write_barriers'])
            ? $dry_run_execution['write_barriers']
            : [],
        'deferred_media_count' => isset($dry_run_execution['deferred_media_count']) ? absint($dry_run_execution['deferred_media_count']) : 0,
        'deferred_media_reasons' => isset($dry_run_execution['deferred_media_reasons']) && is_array($dry_run_execution['deferred_media_reasons'])
            ? array_values($dry_run_execution['deferred_media_reasons'])
            : [],
    ],
    'preflight_approval' => [
        'status' => isset($preflight_approval['status']) ? (string) $preflight_approval['status'] : '',
        'approval_id' => isset($preflight_approval['approval']['approval_id']) ? (string) $preflight_approval['approval']['approval_id'] : '',
        'expires_at' => isset($preflight_approval['approval']['expires_at']) ? (string) $preflight_approval['approval']['expires_at'] : '',
        'approval_valid' => ! empty($preflight_approval['approval_valid']),
        'approval_eligible' => ! empty($preflight_approval['approval_eligible']),
        'summary' => isset($preflight_approval['summary']) && is_array($preflight_approval['summary'])
            ? $preflight_approval['summary']
            : [],
        'guard_failures' => isset($preflight_approval['guard_failures']) && is_array($preflight_approval['guard_failures'])
            ? $preflight_approval['guard_failures']
            : [],
        'deferred_media_count' => isset($preflight_approval['deferred_media_count']) ? absint($preflight_approval['deferred_media_count']) : 0,
        'deferred_media_reasons' => isset($preflight_approval['deferred_media_reasons']) && is_array($preflight_approval['deferred_media_reasons'])
            ? array_values($preflight_approval['deferred_media_reasons'])
            : [],
    ],
    'execute' => [
        'status' => isset($execute_result['status']) ? (string) $execute_result['status'] : '',
        'execution_id' => isset($execute_result['execution_id']) ? (string) $execute_result['execution_id'] : '',
        'write_performed' => ! empty($execute_result['write_performed']),
        'default_entity_key' => isset($execute_result['trace']['default_entity_key']) ? (string) $execute_result['trace']['default_entity_key'] : '',
        'default_entity_reason' => isset($execute_result['trace']['default_entity_reason']) ? (string) $execute_result['trace']['default_entity_reason'] : '',
        'phase4_context' => isset($execute_result['phase4_context']) && is_array($execute_result['phase4_context']) ? $execute_result['phase4_context'] : [],
        'executed_stages' => isset($execute_result['executed_stages']) && is_array($execute_result['executed_stages'])
            ? $execute_result['executed_stages']
            : [],
        'deferred_stages' => isset($execute_result['deferred_stages']) && is_array($execute_result['deferred_stages'])
            ? $execute_result['deferred_stages']
            : [],
        'write_plan_id' => isset($execute_result['write_preparation']['write_plan_id']) ? (string) $execute_result['write_preparation']['write_plan_id'] : '',
        'prepared_counts' => isset($execute_result['write_preparation']['operation_counts']) && is_array($execute_result['write_preparation']['operation_counts'])
            ? $execute_result['write_preparation']['operation_counts']
            : [],
        'operation_counts' => isset($execute_result['operation_counts']) && is_array($execute_result['operation_counts'])
            ? $execute_result['operation_counts']
            : [],
        'write_barriers' => isset($execute_result['write_barriers']) && is_array($execute_result['write_barriers'])
            ? $execute_result['write_barriers']
            : [],
        'first_entity_write' => isset($execute_result['write_preparation']['entity_writes'][0]) && is_array($execute_result['write_preparation']['entity_writes'][0])
            ? $execute_result['write_preparation']['entity_writes'][0]
            : [],
        'first_entity_execution' => isset($execute_result['entity_write_execution']['operations'][0]) && is_array($execute_result['entity_write_execution']['operations'][0])
            ? $execute_result['entity_write_execution']['operations'][0]
            : [],
        'first_field_execution' => isset($execute_result['field_write_execution']['operations'][0]) && is_array($execute_result['field_write_execution']['operations'][0])
            ? $execute_result['field_write_execution']['operations'][0]
            : [],
        'first_media_execution' => isset($execute_result['media_write_execution']['operations'][0]) && is_array($execute_result['media_write_execution']['operations'][0])
            ? $execute_result['media_write_execution']['operations'][0]
            : [],
        'guard_failures' => isset($execute_result['guard_failures']) && is_array($execute_result['guard_failures'])
            ? $execute_result['guard_failures']
            : [],
        'execution_failures' => isset($execute_result['execution_failures']) && is_array($execute_result['execution_failures'])
            ? $execute_result['execution_failures']
            : [],
        'auto_rollback' => isset($execute_result['auto_rollback']) && is_array($execute_result['auto_rollback'])
            ? $execute_result['auto_rollback']
            : [],
        'deferred_media_count' => isset($execute_result['deferred_media_count']) ? absint($execute_result['deferred_media_count']) : 0,
        'deferred_media_reasons' => isset($execute_result['deferred_media_reasons']) && is_array($execute_result['deferred_media_reasons'])
            ? array_values($execute_result['deferred_media_reasons'])
            : [],
        'message' => isset($execute_result['message']) ? (string) $execute_result['message'] : '',
    ],
    'rollback' => is_array($rollback_result) ? $rollback_result : null,
];

echo wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);
