<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Package_QA_Service
{
    /**
     * @var DBVC_CC_V2_Package_QA_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Package_QA_Service
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
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function get_run_readiness($run_id, array $args = [])
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

        $inventory = DBVC_CC_V2_URL_Inventory_Service::get_instance()->get_inventory_for_run($run_id);
        if (is_wp_error($inventory)) {
            return $inventory;
        }

        $latest = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_latest_state_for_run($run_id);
        if (is_wp_error($latest)) {
            return $latest;
        }

        $events = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_events_for_run($run_id);
        if (is_wp_error($events)) {
            return $events;
        }

        $events_by_page = $this->index_events_by_page($events);
        $write_reports = ! empty($args['write_reports']);
        $page_reports = [];
        $blocking_issues = [];
        $warnings = [];
        $summary = [
            'eligiblePages' => 0,
            'readyPages' => 0,
            'needsReviewPages' => 0,
            'blockedPages' => 0,
            'includedPages' => 0,
            'excludedPages' => 0,
            'autoAcceptedPages' => 0,
            'manualOverridePages' => 0,
            'rerunPages' => 0,
        ];

        $rows = isset($inventory['urls']) && is_array($inventory['urls']) ? $inventory['urls'] : [];
        foreach ($rows as $row) {
            if (! is_array($row) || (($row['scope_status'] ?? '') !== 'eligible')) {
                continue;
            }

            ++$summary['eligiblePages'];

            $page_id = isset($row['page_id']) ? sanitize_text_field((string) $row['page_id']) : '';
            $page_context = DBVC_CC_V2_Page_Artifact_Service::get_instance()->resolve_page_context_for_run($run_id, $page_id);
            if (is_wp_error($page_context)) {
                $blocking_issues[] = $this->make_issue(
                    'missing_page_context',
                    __('A page artifact context could not be resolved for an eligible URL.', 'dbvc'),
                    'blocking',
                    [
                        'pageId' => $page_id,
                        'path' => isset($row['path']) ? (string) $row['path'] : '',
                        'sourceUrl' => isset($row['source_url']) ? (string) $row['source_url'] : '',
                    ]
                );
                ++$summary['blockedPages'];
                ++$summary['excludedPages'];
                continue;
            }

            $report = DBVC_CC_V2_URL_QA_Report_Service::get_instance()->build_page_report(
                $run_id,
                $domain,
                $row,
                $page_context,
                isset($events_by_page[$page_id]) ? $events_by_page[$page_id] : [],
                isset($latest['latest_schema_fingerprint']) ? (string) $latest['latest_schema_fingerprint'] : ''
            );

            if ($write_reports && ! empty($page_context['artifact_paths']['qa_report'])) {
                DBVC_CC_Artifact_Manager::write_json_file($page_context['artifact_paths']['qa_report'], $report);
            }

            $page_reports[] = $report;
            $this->apply_report_to_summary($summary, $report);
            $this->collect_issue_group($blocking_issues, isset($report['blockingIssues']) ? $report['blockingIssues'] : []);
            $this->collect_issue_group($warnings, isset($report['warnings']) ? $report['warnings'] : []);
        }

        usort(
            $page_reports,
            static function ($left, $right) {
                $priority = [
                    DBVC_CC_V2_Contracts::READINESS_STATUS_BLOCKED => 0,
                    DBVC_CC_V2_Contracts::READINESS_STATUS_NEEDS_REVIEW => 1,
                    DBVC_CC_V2_Contracts::READINESS_STATUS_READY => 2,
                ];

                $left_priority = isset($priority[$left['readinessStatus']]) ? $priority[$left['readinessStatus']] : 9;
                $right_priority = isset($priority[$right['readinessStatus']]) ? $priority[$right['readinessStatus']] : 9;
                if ($left_priority !== $right_priority) {
                    return $left_priority <=> $right_priority;
                }

                return strnatcasecmp((string) ($left['path'] ?? ''), (string) ($right['path'] ?? ''));
            }
        );
        $benchmark_summary = $this->build_benchmark_summary($page_reports);
        $summary['benchmarkTrackedPages'] = isset($benchmark_summary['trackedPageCount']) ? (int) $benchmark_summary['trackedPageCount'] : 0;
        $summary['benchmarkHighRiskPages'] = isset($benchmark_summary['highRiskPageCount']) ? (int) $benchmark_summary['highRiskPageCount'] : 0;
        $summary['benchmarkUnresolvedItems'] = isset($benchmark_summary['totals']['unresolvedCount']) ? (int) $benchmark_summary['totals']['unresolvedCount'] : 0;
        $summary['benchmarkAmbiguousRecommendations'] = isset($benchmark_summary['totals']['ambiguousCount']) ? (int) $benchmark_summary['totals']['ambiguousCount'] : 0;
        $summary['benchmarkAmbiguousReviewedCount'] = isset($benchmark_summary['totals']['ambiguousReviewedCount']) ? (int) $benchmark_summary['totals']['ambiguousReviewedCount'] : 0;
        $summary['benchmarkManualOverrideCount'] = isset($benchmark_summary['totals']['manualOverrideCount']) ? (int) $benchmark_summary['totals']['manualOverrideCount'] : 0;
        $summary['benchmarkRerunCount'] = isset($benchmark_summary['totals']['rerunCount']) ? (int) $benchmark_summary['totals']['rerunCount'] : 0;
        $summary['benchmarkTransformBlockedCount'] = isset($benchmark_summary['totals']['transformBlockedCount']) ? (int) $benchmark_summary['totals']['transformBlockedCount'] : 0;
        $summary['benchmarkProviderDriftCount'] = isset($benchmark_summary['totals']['providerDriftCount']) ? (int) $benchmark_summary['totals']['providerDriftCount'] : 0;
        $collection_status = $this->resolve_collection_status($summary);
        $readiness_status = $this->resolve_overall_status(
            $collection_status,
            isset($benchmark_summary['status']) ? (string) $benchmark_summary['status'] : ''
        );

        return [
            'runId' => $run_id,
            'domain' => $domain,
            'generatedAt' => current_time('c'),
            'readinessStatus' => $readiness_status,
            'schemaFingerprint' => isset($latest['latest_schema_fingerprint']) ? (string) $latest['latest_schema_fingerprint'] : '',
            'summary' => $summary,
            'benchmarkSummary' => $benchmark_summary,
            'blockingIssues' => array_values($blocking_issues),
            'warnings' => array_values($warnings),
            'pageReports' => array_values($page_reports),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $page_reports
     * @return array<string, mixed>
     */
    public function build_benchmark_summary(array $page_reports)
    {
        $pages = [];
        $pages_with_unresolved = 0;
        $pages_with_ambiguity = 0;
        $pages_with_ambiguous_reviewed = 0;
        $pages_with_manual_overrides = 0;
        $pages_with_reruns = 0;
        $pages_with_transform_blocks = 0;
        $pages_with_provider_drift = 0;
        $pages_with_benchmark_blocked = 0;
        $pages_with_benchmark_review = 0;
        $high_risk_pages = 0;
        $totals = [
            'unresolvedCount' => 0,
            'ambiguousCount' => 0,
            'ambiguousReviewedCount' => 0,
            'manualOverrideCount' => 0,
            'rerunCount' => 0,
            'transformBlockedCount' => 0,
            'providerDriftCount' => 0,
        ];

        foreach ($page_reports as $report) {
            if (! is_array($report)) {
                continue;
            }

            $stats = isset($report['stats']) && is_array($report['stats']) ? $report['stats'] : [];
            $unresolved_count = isset($stats['unresolvedCount']) ? (int) $stats['unresolvedCount'] : 0;
            $ambiguous_count = isset($stats['fieldContextAmbiguousRecommendationCount']) ? (int) $stats['fieldContextAmbiguousRecommendationCount'] : 0;
            $ambiguous_reviewed_count = isset($stats['fieldContextAmbiguousReviewedCount']) ? (int) $stats['fieldContextAmbiguousReviewedCount'] : 0;
            $manual_override_count = isset($report['manualOverrideCount']) ? (int) $report['manualOverrideCount'] : 0;
            $rerun_count = isset($report['rerunCount']) ? (int) $report['rerunCount'] : 0;
            $transform_blocked_count = isset($stats['transformBlockedCount']) ? (int) $stats['transformBlockedCount'] : 0;
            $provider_drift_count = isset($stats['fieldContextProviderDriftCount']) ? (int) $stats['fieldContextProviderDriftCount'] : 0;
            $benchmark_gate = isset($report['benchmarkGate']) && is_array($report['benchmarkGate'])
                ? $report['benchmarkGate']
                : DBVC_CC_V2_Benchmark_Gate_Service::get_instance()->evaluate_page(
                    [
                        'quality_score' => isset($report['qualityScore']) ? (int) $report['qualityScore'] : 0,
                        'ambiguous_reviewed_count' => $ambiguous_reviewed_count,
                        'manual_override_count' => $manual_override_count,
                        'rerun_count' => $rerun_count,
                    ]
                );
            $benchmark_gate_status = isset($benchmark_gate['status']) ? (string) $benchmark_gate['status'] : DBVC_CC_V2_Contracts::READINESS_STATUS_READY;

            $totals['unresolvedCount'] += $unresolved_count;
            $totals['ambiguousCount'] += $ambiguous_count;
            $totals['ambiguousReviewedCount'] += $ambiguous_reviewed_count;
            $totals['manualOverrideCount'] += $manual_override_count;
            $totals['rerunCount'] += $rerun_count;
            $totals['transformBlockedCount'] += $transform_blocked_count;
            $totals['providerDriftCount'] += $provider_drift_count;

            if ($unresolved_count > 0) {
                ++$pages_with_unresolved;
            }
            if ($ambiguous_count > 0) {
                ++$pages_with_ambiguity;
            }
            if ($ambiguous_reviewed_count > 0) {
                ++$pages_with_ambiguous_reviewed;
            }
            if ($manual_override_count > 0) {
                ++$pages_with_manual_overrides;
            }
            if ($rerun_count > 0) {
                ++$pages_with_reruns;
            }
            if ($transform_blocked_count > 0) {
                ++$pages_with_transform_blocks;
            }
            if ($provider_drift_count > 0) {
                ++$pages_with_provider_drift;
            }
            if ($benchmark_gate_status === DBVC_CC_V2_Contracts::READINESS_STATUS_BLOCKED) {
                ++$pages_with_benchmark_blocked;
            } elseif ($benchmark_gate_status === DBVC_CC_V2_Contracts::READINESS_STATUS_NEEDS_REVIEW) {
                ++$pages_with_benchmark_review;
            }

            $status = $this->resolve_overall_status(
                isset($report['readinessStatus']) ? (string) $report['readinessStatus'] : '',
                $benchmark_gate_status
            );
            $high_risk = $status !== DBVC_CC_V2_Contracts::READINESS_STATUS_READY;
            if ($high_risk) {
                ++$high_risk_pages;
            }

            $pages[] = [
                'pageId' => isset($report['pageId']) ? (string) $report['pageId'] : '',
                'path' => isset($report['path']) ? (string) $report['path'] : '',
                'readinessStatus' => isset($report['readinessStatus']) ? (string) $report['readinessStatus'] : '',
                'benchmarkStatus' => $status,
                'qualityScore' => isset($report['qualityScore']) ? (int) $report['qualityScore'] : 0,
                'unresolvedCount' => $unresolved_count,
                'ambiguousCount' => $ambiguous_count,
                'ambiguousReviewedCount' => $ambiguous_reviewed_count,
                'manualOverrideCount' => $manual_override_count,
                'rerunCount' => $rerun_count,
                'transformBlockedCount' => $transform_blocked_count,
                'providerDriftCount' => $provider_drift_count,
                'highRisk' => $high_risk,
                'benchmarkReasonCodes' => array_values(
                    array_unique(
                        array_merge(
                            isset($benchmark_gate['blocking_reason_codes']) && is_array($benchmark_gate['blocking_reason_codes'])
                                ? $benchmark_gate['blocking_reason_codes']
                                : [],
                            isset($benchmark_gate['warning_reason_codes']) && is_array($benchmark_gate['warning_reason_codes'])
                                ? $benchmark_gate['warning_reason_codes']
                                : []
                        )
                    )
                ),
            ];
        }

        usort(
            $pages,
            static function ($left, $right) {
                $priority = [
                    DBVC_CC_V2_Contracts::READINESS_STATUS_BLOCKED => 0,
                    DBVC_CC_V2_Contracts::READINESS_STATUS_NEEDS_REVIEW => 1,
                    DBVC_CC_V2_Contracts::READINESS_STATUS_READY => 2,
                ];
                $left_status = isset($left['benchmarkStatus']) ? (string) $left['benchmarkStatus'] : DBVC_CC_V2_Contracts::READINESS_STATUS_READY;
                $right_status = isset($right['benchmarkStatus']) ? (string) $right['benchmarkStatus'] : DBVC_CC_V2_Contracts::READINESS_STATUS_READY;
                $left_priority = isset($priority[$left_status]) ? $priority[$left_status] : 9;
                $right_priority = isset($priority[$right_status]) ? $priority[$right_status] : 9;
                if ($left_priority !== $right_priority) {
                    return $left_priority <=> $right_priority;
                }

                return strnatcasecmp((string) ($left['path'] ?? ''), (string) ($right['path'] ?? ''));
            }
        );

        $status = DBVC_CC_V2_Contracts::READINESS_STATUS_READY;
        if ($pages_with_transform_blocks > 0 || $pages_with_provider_drift > 0) {
            $status = DBVC_CC_V2_Contracts::READINESS_STATUS_BLOCKED;
        } elseif ($pages_with_unresolved > 0 || $pages_with_ambiguity > 0) {
            $status = DBVC_CC_V2_Contracts::READINESS_STATUS_NEEDS_REVIEW;
        }
        $status = $this->resolve_overall_status(
            $status,
            $pages_with_benchmark_blocked > 0
                ? DBVC_CC_V2_Contracts::READINESS_STATUS_BLOCKED
                : ($pages_with_benchmark_review > 0 ? DBVC_CC_V2_Contracts::READINESS_STATUS_NEEDS_REVIEW : DBVC_CC_V2_Contracts::READINESS_STATUS_READY)
        );

        return [
            'status' => $status,
            'trackedPageCount' => count($pages),
            'highRiskPageCount' => $high_risk_pages,
            'pagesWithUnresolvedCount' => $pages_with_unresolved,
            'pagesWithAmbiguityCount' => $pages_with_ambiguity,
            'pagesWithAmbiguousReviewedCount' => $pages_with_ambiguous_reviewed,
            'pagesWithManualOverrideCount' => $pages_with_manual_overrides,
            'pagesWithRerunCount' => $pages_with_reruns,
            'pagesWithTransformBlocks' => $pages_with_transform_blocks,
            'pagesWithProviderDrift' => $pages_with_provider_drift,
            'pagesWithBenchmarkBlocked' => $pages_with_benchmark_blocked,
            'pagesWithBenchmarkReview' => $pages_with_benchmark_review,
            'totals' => $totals,
            'pages' => array_values($pages),
        ];
    }

    /**
     * @param array<string, int>   $summary
     * @param array<string, mixed> $report
     * @return void
     */
    private function apply_report_to_summary(array &$summary, array $report)
    {
        if (($report['readinessStatus'] ?? '') === DBVC_CC_V2_Contracts::READINESS_STATUS_READY) {
            ++$summary['readyPages'];
            ++$summary['includedPages'];
        } elseif (($report['readinessStatus'] ?? '') === DBVC_CC_V2_Contracts::READINESS_STATUS_BLOCKED) {
            ++$summary['blockedPages'];
            ++$summary['excludedPages'];
        } else {
            ++$summary['needsReviewPages'];
            ++$summary['excludedPages'];
        }

        if (! empty($report['autoAccepted'])) {
            ++$summary['autoAcceptedPages'];
        }
        if (! empty($report['manualOverrideCount'])) {
            ++$summary['manualOverridePages'];
        }
        if (! empty($report['rerunCount'])) {
            ++$summary['rerunPages'];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $target
     * @param mixed                            $items
     * @return void
     */
    private function collect_issue_group(array &$target, $items)
    {
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if (is_array($item)) {
                $target[] = $item;
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function index_events_by_page(array $events)
    {
        $indexed = [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $page_id = isset($event['page_id']) ? sanitize_text_field((string) $event['page_id']) : '';
            if ($page_id === '') {
                continue;
            }

            if (! isset($indexed[$page_id])) {
                $indexed[$page_id] = [];
            }

            $indexed[$page_id][] = $event;
        }

        return $indexed;
    }

    /**
     * @param array<string, int> $summary
     * @return string
     */
    private function resolve_collection_status(array $summary)
    {
        if (! empty($summary['blockedPages'])) {
            return DBVC_CC_V2_Contracts::READINESS_STATUS_BLOCKED;
        }

        if (! empty($summary['needsReviewPages'])) {
            return DBVC_CC_V2_Contracts::READINESS_STATUS_NEEDS_REVIEW;
        }

        return DBVC_CC_V2_Contracts::READINESS_STATUS_READY;
    }

    /**
     * @param string $left_status
     * @param string $right_status
     * @return string
     */
    private function resolve_overall_status($left_status, $right_status)
    {
        return $this->status_priority($right_status) > $this->status_priority($left_status)
            ? sanitize_key((string) $right_status)
            : sanitize_key((string) $left_status);
    }

    /**
     * @param string $status
     * @return int
     */
    private function status_priority($status)
    {
        $status = sanitize_key((string) $status);
        if ($status === DBVC_CC_V2_Contracts::READINESS_STATUS_BLOCKED) {
            return 2;
        }

        if ($status === DBVC_CC_V2_Contracts::READINESS_STATUS_NEEDS_REVIEW) {
            return 1;
        }

        return 0;
    }

    /**
     * @param string               $code
     * @param string               $message
     * @param string               $severity
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function make_issue($code, $message, $severity, array $context = [])
    {
        return array_merge(
            [
                'code' => sanitize_key((string) $code),
                'message' => sanitize_text_field((string) $message),
                'severity' => sanitize_key((string) $severity),
            ],
            $context
        );
    }
}
