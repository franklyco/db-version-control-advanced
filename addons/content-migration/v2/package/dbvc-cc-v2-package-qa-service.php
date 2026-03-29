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

        return [
            'runId' => $run_id,
            'domain' => $domain,
            'generatedAt' => current_time('c'),
            'readinessStatus' => $this->resolve_collection_status($summary),
            'schemaFingerprint' => isset($latest['latest_schema_fingerprint']) ? (string) $latest['latest_schema_fingerprint'] : '',
            'summary' => $summary,
            'blockingIssues' => array_values($blocking_issues),
            'warnings' => array_values($warnings),
            'pageReports' => array_values($page_reports),
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
