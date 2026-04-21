<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Package_Execution_QA_Fixture_Service
{
    private const USER_META_KEY = 'dbvc_cc_v2_package_execution_qa_fixture';

    /**
     * @var DBVC_CC_V2_Package_Execution_QA_Fixture_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Package_Execution_QA_Fixture_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @return bool
     */
    public function is_available()
    {
        $enabled = defined('DBVC_PHPUNIT') && DBVC_PHPUNIT;
        if (! $enabled) {
            $enabled = defined('WP_DEBUG') && WP_DEBUG;
        }

        return (bool) apply_filters('dbvc_cc_v2_enable_recovery_qa_fixture', $enabled);
    }

    /**
     * @param string               $run_id
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function seed_fixture($run_id, array $args = [])
    {
        if (! $this->is_available()) {
            return new WP_Error(
                'dbvc_cc_v2_package_execution_fixture_unavailable',
                __('The V2 package execution QA fixture helper is unavailable in this environment.', 'dbvc'),
                ['status' => 403]
            );
        }

        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') {
            return new WP_Error(
                'dbvc_cc_v2_package_execution_fixture_run_required',
                __('A V2 run ID is required to seed package execution QA data.', 'dbvc'),
                ['status' => 400]
            );
        }

        $package_id = isset($args['packageId']) ? sanitize_text_field((string) $args['packageId']) : '';
        $surface = DBVC_CC_V2_Package_Build_Service::get_instance()->get_package_surface($run_id, $package_id);
        if (is_wp_error($surface)) {
            return $surface;
        }

        $selected_package_id = isset($surface['selectedPackageId']) ? sanitize_text_field((string) $surface['selectedPackageId']) : '';
        $selected_package = isset($surface['selectedPackage']) && is_array($surface['selectedPackage']) ? $surface['selectedPackage'] : [];
        if ($selected_package_id === '' || empty($selected_package)) {
            return new WP_Error(
                'dbvc_cc_v2_package_execution_fixture_package_missing',
                __('A built V2 package is required before seeding execution observability QA data.', 'dbvc'),
                ['status' => 404]
            );
        }

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return new WP_Error(
                'dbvc_cc_v2_package_execution_fixture_user_missing',
                __('A logged-in user is required to seed V2 package execution QA data.', 'dbvc'),
                ['status' => 401]
            );
        }

        $fixture = [
            'runId' => $run_id,
            'packageId' => $selected_package_id,
            'seededAt' => current_time('c'),
            'latestExecute' => $this->build_latest_execute_fixture($selected_package, $run_id, $selected_package_id),
        ];

        update_user_meta($user_id, self::USER_META_KEY, $fixture);

        return $fixture;
    }

    /**
     * @param string $run_id
     * @param string $package_id
     * @return array<string, mixed>
     */
    public function clear_fixture($run_id = '', $package_id = '')
    {
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            delete_user_meta($user_id, self::USER_META_KEY);
        }

        return [
            'runId' => sanitize_text_field((string) $run_id),
            'packageId' => sanitize_text_field((string) $package_id),
            'enabled' => false,
        ];
    }

    /**
     * @param string               $run_id
     * @param string               $package_id
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function apply_to_package_surface($run_id, $package_id, array $payload)
    {
        $fixture = $this->get_fixture();
        $run_id = sanitize_text_field((string) $run_id);
        $selected_package_id = sanitize_text_field(
            (string) (
                $package_id !== ''
                    ? $package_id
                    : (isset($payload['selectedPackageId']) ? $payload['selectedPackageId'] : '')
            )
        );

        if (
            $run_id === ''
            || $selected_package_id === ''
            || empty($fixture)
            || (string) ($fixture['runId'] ?? '') !== $run_id
            || (string) ($fixture['packageId'] ?? '') !== $selected_package_id
        ) {
            return $payload;
        }

        $latest_execute = isset($fixture['latestExecute']) && is_array($fixture['latestExecute'])
            ? $fixture['latestExecute']
            : [];
        $selected_package = isset($payload['selectedPackage']) && is_array($payload['selectedPackage'])
            ? $payload['selectedPackage']
            : [];

        if (empty($selected_package) || empty($latest_execute)) {
            return $payload;
        }

        $workflow_state = isset($selected_package['workflowState']) && is_array($selected_package['workflowState'])
            ? $selected_package['workflowState']
            : [];
        $workflow_state['latestExecute'] = $latest_execute;
        $selected_package['workflowState'] = $workflow_state;

        $import_history = isset($selected_package['importHistory']) && is_array($selected_package['importHistory'])
            ? array_values(array_filter($selected_package['importHistory'], 'is_array'))
            : [];
        $import_history = array_values(
            array_filter(
                $import_history,
                static function ($item) use ($latest_execute) {
                    if (! is_array($item)) {
                        return false;
                    }

                    return (
                        (string) ($item['generatedAt'] ?? '') !== (string) ($latest_execute['generatedAt'] ?? '')
                        || (string) ($item['status'] ?? '') !== (string) ($latest_execute['status'] ?? '')
                    );
                }
            )
        );
        array_unshift($import_history, $latest_execute);
        $selected_package['importHistory'] = array_slice($import_history, 0, 10);
        $payload['selectedPackage'] = $selected_package;
        $payload['history'] = $this->apply_fixture_to_history(
            isset($payload['history']) && is_array($payload['history']) ? $payload['history'] : [],
            $selected_package_id,
            $latest_execute
        );

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_fixture()
    {
        if (! $this->is_available()) {
            return [];
        }

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return [];
        }

        $stored = get_user_meta($user_id, self::USER_META_KEY, true);
        if (! is_array($stored)) {
            return [];
        }

        return $this->normalize_fixture($stored);
    }

    /**
     * @param array<string, mixed> $selected_package
     * @param string               $run_id
     * @param string               $package_id
     * @return array<string, mixed>
     */
    private function build_latest_execute_fixture(array $selected_package, $run_id, $package_id)
    {
        $summary = isset($selected_package['summary']) && is_array($selected_package['summary']) ? $selected_package['summary'] : [];
        $manifest = isset($selected_package['manifest']) && is_array($selected_package['manifest']) ? $selected_package['manifest'] : [];
        $records = isset($selected_package['records']) && is_array($selected_package['records']) ? array_values(array_filter($selected_package['records'], 'is_array')) : [];
        $media_manifest = isset($selected_package['mediaManifest']) && is_array($selected_package['mediaManifest']) ? $selected_package['mediaManifest'] : [];
        $media_items = isset($media_manifest['media_items']) && is_array($media_manifest['media_items'])
            ? array_values(array_filter($media_manifest['media_items'], 'is_array'))
            : [];
        $included_pages = isset($manifest['included_pages']) && is_array($manifest['included_pages'])
            ? array_values(array_filter(array_map('sanitize_text_field', $manifest['included_pages'])))
            : [];

        $first_record = ! empty($records[0]) && is_array($records[0]) ? $records[0] : [];
        $page_id = isset($first_record['page_id']) ? sanitize_text_field((string) $first_record['page_id']) : '';
        if ($page_id === '' && ! empty($included_pages[0])) {
            $page_id = sanitize_text_field((string) $included_pages[0]);
        }

        $path = isset($first_record['path']) ? sanitize_text_field((string) $first_record['path']) : '';
        $source_url = isset($first_record['source_url']) ? esc_url_raw((string) $first_record['source_url']) : '';
        $generated_at = current_time('c');
        $record_count = max(0, isset($summary['record_count']) ? absint($summary['record_count']) : count($records));
        $included_page_count = max(0, isset($summary['included_page_count']) ? absint($summary['included_page_count']) : count($included_pages));
        $media_item_count = max(0, isset($summary['media_item_count']) ? absint($summary['media_item_count']) : count($media_items));
        $blocking_issue_count = max(0, isset($summary['blocking_issue_count']) ? absint($summary['blocking_issue_count']) : 0);
        $warning_count = max(0, isset($summary['warning_count']) ? absint($summary['warning_count']) : 0);
        $synthetic_run_id = max(1, absint(hexdec(substr(md5($run_id . '|' . $package_id), 0, 6))));
        $synthetic_run_uuid = 'qa-fixture-' . sanitize_title($package_id);

        $latest_execute = [
            'stage' => 'execute',
            'generatedAt' => $generated_at,
            'status' => 'completed',
            'readinessStatus' => '',
            'approvalEligible' => false,
            'approvalValid' => false,
            'executeReady' => false,
            'issueCount' => $blocking_issue_count,
            'warningCount' => $warning_count,
            'summary' => [
                'includedPages' => $included_page_count,
                'completedPages' => $included_page_count,
                'partialPages' => 0,
                'blockedPages' => 0,
                'failedPages' => 0,
                'importRuns' => 1,
                'rollbackAvailableRuns' => 1,
                'executedEntityWrites' => $record_count,
                'executedFieldWrites' => $record_count,
                'executedMediaWrites' => $media_item_count,
                'deferredMediaCount' => 0,
            ],
            'pageImports' => [],
            'importRuns' => [
                [
                    'runId' => $synthetic_run_id,
                    'runUuid' => $synthetic_run_uuid,
                    'path' => $path,
                    'sourceUrl' => $source_url,
                    'status' => 'completed',
                    'createdAt' => $generated_at,
                    'approvedAt' => $generated_at,
                    'startedAt' => $generated_at,
                    'finishedAt' => $generated_at,
                    'rollbackStatus' => 'available',
                    'rollbackStartedAt' => '',
                    'rollbackFinishedAt' => '',
                    'errorSummary' => '',
                    'summary' => [
                        'totalActions' => $record_count,
                        'executedActions' => $record_count,
                        'failedActions' => 0,
                        'rolledBackActions' => 0,
                    ],
                ],
            ],
        ];

        if ($page_id !== '') {
            $latest_execute['pageImports'][] = [
                'pageId' => $page_id,
                'path' => $path,
                'status' => 'completed',
                'runId' => $synthetic_run_id,
                'runUuid' => $synthetic_run_uuid,
                'rollbackAvailable' => true,
                'rollbackStatus' => 'available',
                'message' => __('Deterministic package execution QA fixture.', 'dbvc'),
            ];
        }

        return $latest_execute;
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @param string                           $package_id
     * @param array<string, mixed>             $latest_execute
     * @return array<int, array<string, mixed>>
     */
    private function apply_fixture_to_history(array $history, $package_id, array $latest_execute)
    {
        return array_values(
            array_map(
                static function ($item) use ($package_id, $latest_execute) {
                    if (! is_array($item) || sanitize_text_field((string) ($item['package_id'] ?? '')) !== $package_id) {
                        return $item;
                    }

                    $workflow_summary = isset($item['workflowSummary']) && is_array($item['workflowSummary'])
                        ? $item['workflowSummary']
                        : [];
                    $workflow_summary['executeStatus'] = isset($latest_execute['status']) ? sanitize_key((string) $latest_execute['status']) : '';
                    $workflow_summary['importRunCount'] = isset($latest_execute['summary']['importRuns'])
                        ? absint($latest_execute['summary']['importRuns'])
                        : 0;
                    $item['workflowSummary'] = $workflow_summary;

                    return $item;
                },
                $history
            )
        );
    }

    /**
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private function normalize_fixture(array $stored)
    {
        return [
            'runId' => sanitize_text_field((string) ($stored['runId'] ?? '')),
            'packageId' => sanitize_text_field((string) ($stored['packageId'] ?? '')),
            'seededAt' => sanitize_text_field((string) ($stored['seededAt'] ?? '')),
            'latestExecute' => isset($stored['latestExecute']) && is_array($stored['latestExecute'])
                ? $stored['latestExecute']
                : [],
        ];
    }
}
