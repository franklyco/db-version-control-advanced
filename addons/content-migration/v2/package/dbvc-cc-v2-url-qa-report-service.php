<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_URL_QA_Report_Service
{
    /**
     * @var DBVC_CC_V2_URL_QA_Report_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_URL_QA_Report_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param string                           $run_id
     * @param string                           $domain
     * @param array<string, mixed>             $row
     * @param array<string, mixed>             $page_context
     * @param array<int, array<string, mixed>> $events
     * @param string                           $schema_fingerprint
     * @return array<string, mixed>
     */
    public function build_page_report($run_id, $domain, array $row, array $page_context, array $events, $schema_fingerprint)
    {
        $artifact_paths = isset($page_context['artifact_paths']) && is_array($page_context['artifact_paths']) ? $page_context['artifact_paths'] : [];
        $artifact_relatives = isset($page_context['artifact_relatives']) && is_array($page_context['artifact_relatives']) ? $page_context['artifact_relatives'] : [];
        $selection_service = DBVC_CC_V2_Package_Selection_Service::get_instance();
        $artifacts = $selection_service->load_page_artifacts_for_run($run_id, $artifact_paths);
        $recommendations = isset($artifacts['recommendations']) ? $artifacts['recommendations'] : null;
        $mapping_decisions = isset($artifacts['mapping_decisions']) ? $artifacts['mapping_decisions'] : null;
        $media_decisions = isset($artifacts['media_decisions']) ? $artifacts['media_decisions'] : null;
        $target_transform = isset($artifacts['target_transform']) ? $artifacts['target_transform'] : null;

        $blocking_issues = $this->build_initial_blocking_issues($row, $artifact_relatives, $recommendations, $target_transform);
        $warnings = [];

        $review = is_array($recommendations) && isset($recommendations['review']) && is_array($recommendations['review'])
            ? $recommendations['review']
            : [];
        $recommended_target = is_array($recommendations) && isset($recommendations['recommended_target_object']) && is_array($recommendations['recommended_target_object'])
            ? $recommendations['recommended_target_object']
            : [];
        $review_status = isset($review['status']) ? sanitize_key((string) $review['status']) : DBVC_CC_V2_Contracts::READINESS_STATUS_NEEDS_REVIEW;
        $decision_status = is_array($mapping_decisions) && ! empty($mapping_decisions['decision_status'])
            ? sanitize_key((string) $mapping_decisions['decision_status'])
            : 'pending';
        $manual_override_count = $selection_service->count_overrides($mapping_decisions, $media_decisions);
        $rerun_count = $selection_service->count_reruns($events, $mapping_decisions);
        $selected_target_object = $selection_service->resolve_selected_target_object($recommended_target, $mapping_decisions, $domain);
        $field_values = $selection_service->build_field_values($recommendations, $mapping_decisions, $domain);
        $media_refs = $selection_service->build_media_refs($recommendations, $media_decisions, $domain);
        $conflict_count = is_array($recommendations) && isset($recommendations['conflicts']) && is_array($recommendations['conflicts'])
            ? count($recommendations['conflicts'])
            : 0;
        $unresolved_count = is_array($recommendations) && isset($recommendations['unresolved_items']) && is_array($recommendations['unresolved_items'])
            ? count($recommendations['unresolved_items'])
            : 0;

        $this->append_resolution_blockers(
            $blocking_issues,
            $row,
            $artifact_relatives,
            $selection_service->is_decision_stale($mapping_decisions, $recommendations),
            $selected_target_object,
            $review_status,
            $conflict_count,
            $unresolved_count,
            empty($field_values) && empty($media_refs)
        );
        $this->append_warning_items(
            $warnings,
            $row,
            $artifact_relatives,
            $decision_status,
            $review_status,
            $manual_override_count,
            $rerun_count,
            $schema_fingerprint === ''
        );

        $readiness_status = $this->resolve_page_status($blocking_issues, $decision_status, $review_status);
        $quality_score = $this->calculate_quality_score($blocking_issues, $warnings, $manual_override_count, $rerun_count);
        $target_entity_key = $selected_target_object['targetFamily'] !== '' && $selected_target_object['targetObjectKey'] !== ''
            ? $selected_target_object['targetFamily'] . ':' . $selected_target_object['targetObjectKey']
            : '';

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'qa-report.v1',
            'journey_id' => $run_id,
            'page_id' => isset($row['page_id']) ? (string) $row['page_id'] : '',
            'source_url' => isset($row['source_url']) ? (string) $row['source_url'] : '',
            'generated_at' => current_time('c'),
            'readiness_status' => $readiness_status,
            'blocking_issues' => $blocking_issues,
            'warnings' => $warnings,
            'quality_score' => $quality_score,
            'runId' => $run_id,
            'pageId' => isset($row['page_id']) ? (string) $row['page_id'] : '',
            'domain' => $domain,
            'path' => isset($row['path']) ? (string) $row['path'] : '',
            'readinessStatus' => $readiness_status,
            'reviewStatus' => $review_status,
            'decisionStatus' => $decision_status,
            'resolutionMode' => isset($selected_target_object['resolutionMode']) ? (string) $selected_target_object['resolutionMode'] : '',
            'selectedTargetObject' => $selected_target_object,
            'blockingIssues' => $blocking_issues,
            'warnings' => $warnings,
            'qualityScore' => $quality_score,
            'manualOverrideCount' => $manual_override_count,
            'rerunCount' => $rerun_count,
            'autoAccepted' => $review_status === 'auto_accept_candidate' && $decision_status === 'pending',
            'packageIncluded' => $readiness_status === DBVC_CC_V2_Contracts::READINESS_STATUS_READY,
            'packagePreview' => [
                'page_id' => isset($row['page_id']) ? (string) $row['page_id'] : '',
                'path' => isset($row['path']) ? (string) $row['path'] : '',
                'source_url' => isset($row['source_url']) ? (string) $row['source_url'] : '',
                'target_entity_key' => $target_entity_key,
                'target_action' => isset($selected_target_object['resolutionMode']) ? (string) $selected_target_object['resolutionMode'] : '',
                'field_values' => $field_values,
                'media_refs' => $media_refs,
                'trace' => [
                    'review_status' => $review_status,
                    'decision_status' => $decision_status,
                    'manual_override_count' => $manual_override_count,
                    'rerun_count' => $rerun_count,
                    'artifact_refs' => $artifact_relatives,
                    'recommendation_fingerprint' => $selection_service->compute_fingerprint($recommendations),
                ],
            ],
            'stats' => [
                'fieldValueCount' => count($field_values),
                'mediaRefCount' => count($media_refs),
                'conflictCount' => $conflict_count,
                'unresolvedCount' => $unresolved_count,
            ],
        ];
    }

    /**
     * @param array<string, mixed>      $row
     * @param array<string, mixed>      $artifact_relatives
     * @param array<string, mixed>|null $recommendations
     * @param array<string, mixed>|null $target_transform
     * @return array<int, array<string, mixed>>
     */
    private function build_initial_blocking_issues(array $row, array $artifact_relatives, $recommendations, $target_transform)
    {
        $issues = [];

        if (! is_array($recommendations)) {
            $issues[] = $this->make_issue(
                'missing_mapping_recommendations',
                __('The canonical V2 mapping recommendation artifact is missing.', 'dbvc'),
                'blocking',
                $this->build_issue_context($row, $artifact_relatives, 'mapping_recommendations')
            );
        }

        if (! is_array($target_transform)) {
            $issues[] = $this->make_issue(
                'missing_target_transform',
                __('The target transform artifact is missing.', 'dbvc'),
                'blocking',
                $this->build_issue_context($row, $artifact_relatives, 'target_transform')
            );
        }

        return $issues;
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @param array<string, mixed>             $row
     * @param array<string, mixed>             $artifact_relatives
     * @param bool                             $is_stale
     * @param array<string, string>            $selected_target_object
     * @param string                           $review_status
     * @param int                              $conflict_count
     * @param int                              $unresolved_count
     * @param bool                             $is_empty_package_record
     * @return void
     */
    private function append_resolution_blockers(array &$issues, array $row, array $artifact_relatives, $is_stale, array $selected_target_object, $review_status, $conflict_count, $unresolved_count, $is_empty_package_record)
    {
        if (($selected_target_object['resolutionMode'] ?? '') === DBVC_CC_V2_Contracts::RESOLUTION_MODE_BLOCKED || $review_status === 'blocked') {
            $issues[] = $this->make_issue(
                'blocked_resolution',
                __('The current target resolution is blocked and cannot be packaged.', 'dbvc'),
                'blocking',
                $this->build_issue_context($row, $artifact_relatives, 'target_transform')
            );
        }

        if ($selected_target_object['targetFamily'] === '' || $selected_target_object['targetObjectKey'] === '') {
            $issues[] = $this->make_issue(
                'missing_target_object',
                __('No target object selection is available for this URL.', 'dbvc'),
                'blocking',
                $this->build_issue_context($row, $artifact_relatives, 'mapping_recommendations')
            );
        }

        if ($is_stale) {
            $issues[] = $this->make_issue(
                'stale_decisions',
                __('Saved review decisions are stale relative to the latest recommendation fingerprint.', 'dbvc'),
                'blocking',
                $this->build_issue_context($row, $artifact_relatives, 'mapping_decisions')
            );
        }

        if ($conflict_count > 0) {
            $issues[] = $this->make_issue(
                'target_conflicts',
                __('Conflicting target references remain unresolved for this URL.', 'dbvc'),
                'blocking',
                $this->build_issue_context($row, $artifact_relatives, 'mapping_recommendations')
            );
        }

        if ($unresolved_count > 0) {
            $issues[] = $this->make_issue(
                'unresolved_items',
                __('Unresolved mapping or media items remain for this URL.', 'dbvc'),
                'blocking',
                $this->build_issue_context($row, $artifact_relatives, 'mapping_recommendations')
            );
        }

        if ($is_empty_package_record) {
            $issues[] = $this->make_issue(
                'empty_package_record',
                __('No field values or media references are ready to enter the package record.', 'dbvc'),
                'blocking',
                $this->build_issue_context($row, $artifact_relatives, 'mapping_recommendations')
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $warnings
     * @param array<string, mixed>             $row
     * @param array<string, mixed>             $artifact_relatives
     * @param string                           $decision_status
     * @param string                           $review_status
     * @param int                              $manual_override_count
     * @param int                              $rerun_count
     * @param bool                             $is_schema_missing
     * @return void
     */
    private function append_warning_items(array &$warnings, array $row, array $artifact_relatives, $decision_status, $review_status, $manual_override_count, $rerun_count, $is_schema_missing)
    {
        if ($decision_status === 'pending' && $review_status !== 'auto_accept_candidate') {
            $warnings[] = $this->make_issue(
                'manual_review_pending',
                __('This URL still requires an explicit review decision before it is import-ready.', 'dbvc'),
                'warning',
                $this->build_issue_context($row, $artifact_relatives, 'mapping_recommendations')
            );
        }

        if ($manual_override_count > 0) {
            $warnings[] = $this->make_issue(
                'manual_overrides_present',
                __('Manual overrides were captured and should be carried forward into QA and import review.', 'dbvc'),
                'warning',
                $this->build_issue_context($row, $artifact_relatives, 'mapping_decisions')
            );
        }

        if ($rerun_count > 0) {
            $warnings[] = $this->make_issue(
                'rerun_history_present',
                __('One or more reruns were recorded for this URL after initial analysis.', 'dbvc'),
                'warning',
                $this->build_issue_context($row, $artifact_relatives, 'mapping_decisions')
            );
        }

        if ($is_schema_missing) {
            $warnings[] = $this->make_issue(
                'schema_fingerprint_missing',
                __('The run does not currently expose a target schema fingerprint.', 'dbvc'),
                'warning',
                $this->build_issue_context($row, $artifact_relatives, 'target_transform')
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $blocking_issues
     * @param string                           $decision_status
     * @param string                           $review_status
     * @return string
     */
    private function resolve_page_status(array $blocking_issues, $decision_status, $review_status)
    {
        if (! empty($blocking_issues)) {
            return DBVC_CC_V2_Contracts::READINESS_STATUS_BLOCKED;
        }

        if (
            in_array($decision_status, ['reviewed', 'overridden'], true)
            || $review_status === 'auto_accept_candidate'
        ) {
            return DBVC_CC_V2_Contracts::READINESS_STATUS_READY;
        }

        return DBVC_CC_V2_Contracts::READINESS_STATUS_NEEDS_REVIEW;
    }

    /**
     * @param array<int, array<string, mixed>> $blocking_issues
     * @param array<int, array<string, mixed>> $warnings
     * @param int                              $manual_override_count
     * @param int                              $rerun_count
     * @return int
     */
    private function calculate_quality_score(array $blocking_issues, array $warnings, $manual_override_count, $rerun_count)
    {
        $score = 100;
        $score -= count($blocking_issues) * 25;
        $score -= count($warnings) * 8;
        $score -= min(12, absint($manual_override_count) * 3);
        $score -= min(8, absint($rerun_count) * 2);

        return max(0, min(100, $score));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $artifact_relatives
     * @param string               $artifact_key
     * @return array<string, string>
     */
    private function build_issue_context(array $row, array $artifact_relatives, $artifact_key)
    {
        return [
            'pageId' => isset($row['page_id']) ? (string) $row['page_id'] : '',
            'path' => isset($row['path']) ? (string) $row['path'] : '',
            'sourceUrl' => isset($row['source_url']) ? (string) $row['source_url'] : '',
            'artifactRef' => isset($artifact_relatives[$artifact_key]) ? (string) $artifact_relatives[$artifact_key] : '',
        ];
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
