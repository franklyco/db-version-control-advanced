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
        $conflict_count = count(
            $selection_service->filter_active_conflicts(
                $recommendations,
                $mapping_decisions,
                $media_decisions
            )
        );
        $unresolved_count = is_array($recommendations) && isset($recommendations['unresolved_items']) && is_array($recommendations['unresolved_items'])
            ? count($recommendations['unresolved_items'])
            : 0;
        $field_context_provider = is_array($recommendations) && isset($recommendations['field_context_provider']) && is_array($recommendations['field_context_provider'])
            ? $recommendations['field_context_provider']
            : [];
        $field_context_provider_current = $this->load_current_field_context_provider($domain);
        $field_context_provider_drift = $this->summarize_field_context_provider_drift(
            $field_context_provider,
            $field_context_provider_current
        );
        $field_context_selection = $this->summarize_field_context_selection($recommendations);
        $unresolved_summary = $this->summarize_unresolved_items($recommendations);
        $transform_validation = $this->summarize_transform_validation($target_transform);

        $this->append_resolution_blockers(
            $blocking_issues,
            $row,
            $artifact_relatives,
            $selection_service->is_decision_stale($mapping_decisions, $recommendations),
            $selected_target_object,
            $review_status,
            $conflict_count,
            $unresolved_count,
            empty($field_values) && empty($media_refs),
            $unresolved_summary
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
        $this->append_field_context_issues(
            $blocking_issues,
            $warnings,
            $row,
            $artifact_relatives,
            $field_context_provider,
            $field_context_selection
        );
        $this->append_field_context_drift_issues(
            $blocking_issues,
            $warnings,
            $row,
            $artifact_relatives,
            $field_context_provider,
            $field_context_provider_current,
            $field_context_provider_drift
        );
        $this->append_transform_issues(
            $blocking_issues,
            $warnings,
            $row,
            $artifact_relatives,
            $transform_validation
        );

        $base_readiness_status = $this->resolve_page_status($blocking_issues, $decision_status, $review_status);
        $quality_score = $this->calculate_quality_score($blocking_issues, $warnings, $manual_override_count, $rerun_count);
        $benchmark_gate = DBVC_CC_V2_Benchmark_Gate_Service::get_instance()->evaluate_page(
            [
                'quality_score' => $quality_score,
                'ambiguous_reviewed_count' => isset($field_context_selection['ambiguous_reviewed_count'])
                    ? (int) $field_context_selection['ambiguous_reviewed_count']
                    : 0,
                'manual_override_count' => $manual_override_count,
                'rerun_count' => $rerun_count,
            ]
        );
        $this->append_benchmark_gate_issues(
            $blocking_issues,
            $warnings,
            $row,
            $artifact_relatives,
            $benchmark_gate,
            $base_readiness_status
        );
        $readiness_status = $this->resolve_page_status($blocking_issues, $decision_status, $review_status);
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
            'fieldContextProvider' => $field_context_provider,
            'fieldContextProviderCurrent' => $field_context_provider_current,
            'fieldContextProviderDrift' => $field_context_provider_drift,
            'fieldContextSelection' => $field_context_selection,
            'unresolvedSummary' => $unresolved_summary,
            'benchmarkGate' => $benchmark_gate,
            'transformValidation' => $transform_validation,
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
                    'field_context_provider' => $field_context_provider,
                    'field_context_provider_current' => $field_context_provider_current,
                    'field_context_provider_drift' => $field_context_provider_drift,
                    'field_context_selection' => $field_context_selection,
                    'unresolved_summary' => $unresolved_summary,
                    'benchmark_gate' => $benchmark_gate,
                    'transform_validation' => $transform_validation,
                ],
            ],
            'stats' => [
                'fieldValueCount' => count($field_values),
                'mediaRefCount' => count($media_refs),
                'conflictCount' => $conflict_count,
                'unresolvedCount' => $unresolved_count,
                'fieldContextProviderDriftCount' => isset($field_context_provider_drift['status']) && (string) $field_context_provider_drift['status'] === 'drifted' ? 1 : 0,
                'fieldContextAmbiguousRecommendationCount' => isset($field_context_selection['ambiguous_count']) ? (int) $field_context_selection['ambiguous_count'] : 0,
                'fieldContextAmbiguousReviewedCount' => isset($field_context_selection['ambiguous_reviewed_count']) ? (int) $field_context_selection['ambiguous_reviewed_count'] : 0,
                'unresolvedClassCount' => isset($unresolved_summary['class_count']) ? (int) $unresolved_summary['class_count'] : 0,
                'transformBlockedCount' => isset($transform_validation['blocked_count']) ? (int) $transform_validation['blocked_count'] : 0,
                'transformWarningCount' => isset($transform_validation['warning_count']) ? (int) $transform_validation['warning_count'] : 0,
                'benchmarkGateStatus' => isset($benchmark_gate['status']) ? (string) $benchmark_gate['status'] : '',
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
     * @param array<string, mixed>             $unresolved_summary
     * @return void
     */
    private function append_resolution_blockers(array &$issues, array $row, array $artifact_relatives, $is_stale, array $selected_target_object, $review_status, $conflict_count, $unresolved_count, $is_empty_package_record, array $unresolved_summary = [])
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
                array_merge(
                    $this->build_issue_context($row, $artifact_relatives, 'mapping_recommendations'),
                    [
                        'unresolvedSummary' => $unresolved_summary,
                    ]
                )
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
     * @param array<int, array<string, mixed>> $warnings
     * @param array<string, mixed>             $row
     * @param array<string, mixed>             $artifact_relatives
     * @param array<string, mixed>             $field_context_provider
     * @param array<string, int>               $field_context_selection
     * @return void
     */
    private function append_field_context_issues(
        array &$blocking_issues,
        array &$warnings,
        array $row,
        array $artifact_relatives,
        array $field_context_provider,
        array $field_context_selection
    ) {
        $provider_status = isset($field_context_provider['status']) ? sanitize_key((string) $field_context_provider['status']) : '';
        $provider_context = $this->build_field_context_issue_context($row, $artifact_relatives, $field_context_provider);

        if ($provider_status === '' || in_array($provider_status, ['missing', 'unavailable'], true)) {
            $blocking_issues[] = $this->make_issue(
                'field_context_provider_missing',
                __('Field Context provider metadata is missing for this URL, so runtime mapping constraints cannot be verified.', 'dbvc'),
                'blocking',
                $provider_context
            );
        } elseif (in_array($provider_status, ['degraded', 'legacy_only'], true)) {
            $warnings[] = $this->make_issue(
                'field_context_provider_degraded',
                __('Field Context provider coverage is degraded for this URL, so package QA is carrying reduced mapping confidence.', 'dbvc'),
                'warning',
                $provider_context
            );
        }

        $provider_warning_count = isset($field_context_provider['warnings']) && is_array($field_context_provider['warnings'])
            ? count($field_context_provider['warnings'])
            : 0;
        if ($provider_warning_count > 0) {
            $warnings[] = $this->make_issue(
                'field_context_provider_warnings',
                __('Field Context provider warnings were recorded for this URL and should be reviewed before import.', 'dbvc'),
                'warning',
                array_merge(
                    $provider_context,
                    [
                        'fieldContextWarningCount' => $provider_warning_count,
                    ]
                )
            );
        }

        $ambiguous_reviewed_count = isset($field_context_selection['ambiguous_reviewed_count'])
            ? (int) $field_context_selection['ambiguous_reviewed_count']
            : 0;
        if ($ambiguous_reviewed_count > 0) {
            $warnings[] = $this->make_issue(
                'field_context_ambiguous_recommendations',
                __('One or more recommendations were kept even though the deterministic selector still marked them ambiguous.', 'dbvc'),
                'warning',
                array_merge(
                    $provider_context,
                    [
                        'ambiguousRecommendationCount' => $ambiguous_reviewed_count,
                    ]
                )
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $blocking_issues
     * @param array<int, array<string, mixed>> $warnings
     * @param array<string, mixed>             $row
     * @param array<string, mixed>             $artifact_relatives
     * @param array<string, mixed>             $field_context_provider
     * @param array<string, mixed>             $field_context_provider_current
     * @param array<string, mixed>             $field_context_provider_drift
     * @return void
     */
    private function append_field_context_drift_issues(
        array &$blocking_issues,
        array &$warnings,
        array $row,
        array $artifact_relatives,
        array $field_context_provider,
        array $field_context_provider_current,
        array $field_context_provider_drift
    ) {
        $drift_context = array_merge(
            $this->build_field_context_issue_context($row, $artifact_relatives, $field_context_provider),
            [
                'fieldContextProviderCurrentStatus' => isset($field_context_provider_current['status']) ? sanitize_key((string) $field_context_provider_current['status']) : '',
                'fieldContextProviderCurrentSourceHash' => isset($field_context_provider_current['source_hash']) ? sanitize_text_field((string) $field_context_provider_current['source_hash']) : '',
                'fieldContextProviderCurrentSchemaVersion' => isset($field_context_provider_current['schema_version']) ? sanitize_text_field((string) $field_context_provider_current['schema_version']) : '',
                'fieldContextProviderCurrentContractVersion' => isset($field_context_provider_current['contract_version']) ? sanitize_text_field((string) $field_context_provider_current['contract_version']) : '',
                'fieldContextProviderCurrentSiteFingerprint' => isset($field_context_provider_current['site_fingerprint']) ? sanitize_text_field((string) $field_context_provider_current['site_fingerprint']) : '',
                'fieldContextProviderCurrentSlotGraphRef' => isset($field_context_provider_current['slot_graph_ref']) ? sanitize_text_field((string) $field_context_provider_current['slot_graph_ref']) : '',
                'fieldContextProviderCurrentSlotGraphFingerprint' => isset($field_context_provider_current['slot_graph_fingerprint']) ? sanitize_text_field((string) $field_context_provider_current['slot_graph_fingerprint']) : '',
                'fieldContextDriftStatus' => isset($field_context_provider_drift['status']) ? sanitize_key((string) $field_context_provider_drift['status']) : '',
                'fieldContextDriftFields' => isset($field_context_provider_drift['mismatch_fields']) && is_array($field_context_provider_drift['mismatch_fields'])
                    ? array_values($field_context_provider_drift['mismatch_fields'])
                    : [],
            ]
        );

        $current_status = isset($field_context_provider_current['status']) ? sanitize_key((string) $field_context_provider_current['status']) : '';
        if ($current_status === '' || in_array($current_status, ['missing', 'unavailable'], true)) {
            $blocking_issues[] = $this->make_issue(
                'field_context_provider_current_missing',
                __('The current slot graph does not expose verifiable Field Context provider metadata for this URL, so readiness cannot confirm the active schema.', 'dbvc'),
                'blocking',
                $drift_context
            );
            return;
        }

        if (($field_context_provider_drift['status'] ?? '') === 'drifted') {
            $blocking_issues[] = $this->make_issue(
                'field_context_provider_drift',
                __('The saved recommendation Field Context metadata no longer matches the current slot graph, so this URL should be rerun or re-reviewed before packaging.', 'dbvc'),
                'blocking',
                $drift_context
            );
        }

        if (
            in_array($current_status, ['degraded', 'legacy_only'], true)
            && $current_status !== (isset($field_context_provider['status']) ? sanitize_key((string) $field_context_provider['status']) : '')
        ) {
            $warnings[] = $this->make_issue(
                'field_context_provider_current_degraded',
                __('The current slot graph reports degraded Field Context coverage for this URL even though the saved recommendation metadata was stronger.', 'dbvc'),
                'warning',
                $drift_context
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $blocking_issues
     * @param array<int, array<string, mixed>> $warnings
     * @param array<string, mixed>             $row
     * @param array<string, mixed>             $artifact_relatives
     * @param array<string, mixed>             $transform_validation
     * @return void
     */
    private function append_transform_issues(
        array &$blocking_issues,
        array &$warnings,
        array $row,
        array $artifact_relatives,
        array $transform_validation
    ) {
        $transform_context = array_merge(
            $this->build_issue_context($row, $artifact_relatives, 'target_transform'),
            [
                'blockedTransformCount' => isset($transform_validation['blocked_count']) ? (int) $transform_validation['blocked_count'] : 0,
                'warningTransformCount' => isset($transform_validation['warning_count']) ? (int) $transform_validation['warning_count'] : 0,
                'blockedTargetRefs' => isset($transform_validation['blocked_target_refs']) && is_array($transform_validation['blocked_target_refs'])
                    ? array_values($transform_validation['blocked_target_refs'])
                    : [],
                'warningTargetRefs' => isset($transform_validation['warning_target_refs']) && is_array($transform_validation['warning_target_refs'])
                    ? array_values($transform_validation['warning_target_refs'])
                    : [],
                'blockedIssueCodes' => isset($transform_validation['blocked_issue_codes']) && is_array($transform_validation['blocked_issue_codes'])
                    ? array_values($transform_validation['blocked_issue_codes'])
                    : [],
                'warningIssueCodes' => isset($transform_validation['warning_issue_codes']) && is_array($transform_validation['warning_issue_codes'])
                    ? array_values($transform_validation['warning_issue_codes'])
                    : [],
            ]
        );

        if (! empty($transform_validation['blocked_count'])) {
            $blocking_issues[] = $this->make_issue(
                'field_value_contract_blocked',
                __('One or more transform items violate the Field Context value contract and cannot be packaged.', 'dbvc'),
                'blocking',
                $transform_context
            );
        }

        if (! empty($transform_validation['warning_count'])) {
            $warnings[] = $this->make_issue(
                'field_value_contract_warnings',
                __('One or more transform items carry Field Context transform warnings that should be reviewed before import.', 'dbvc'),
                'warning',
                $transform_context
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $blocking_issues
     * @param array<int, array<string, mixed>> $warnings
     * @param array<string, mixed>             $row
     * @param array<string, mixed>             $artifact_relatives
     * @param array<string, mixed>             $benchmark_gate
     * @param string                           $base_readiness_status
     * @return void
     */
    private function append_benchmark_gate_issues(
        array &$blocking_issues,
        array &$warnings,
        array $row,
        array $artifact_relatives,
        array $benchmark_gate,
        $base_readiness_status
    ) {
        $benchmark_status = isset($benchmark_gate['status']) ? sanitize_key((string) $benchmark_gate['status']) : DBVC_CC_V2_Contracts::READINESS_STATUS_READY;
        if ($benchmark_status === DBVC_CC_V2_Contracts::READINESS_STATUS_READY) {
            return;
        }

        $base_priority = $this->status_priority($base_readiness_status);
        $benchmark_priority = $this->status_priority($benchmark_status);
        if ($benchmark_priority <= $base_priority) {
            return;
        }

        $context = array_merge(
            $this->build_issue_context($row, $artifact_relatives, 'mapping_recommendations'),
            [
                'benchmarkGateStatus' => $benchmark_status,
                'benchmarkBlockingReasons' => isset($benchmark_gate['blocking_reason_codes']) && is_array($benchmark_gate['blocking_reason_codes'])
                    ? array_values($benchmark_gate['blocking_reason_codes'])
                    : [],
                'benchmarkWarningReasons' => isset($benchmark_gate['warning_reason_codes']) && is_array($benchmark_gate['warning_reason_codes'])
                    ? array_values($benchmark_gate['warning_reason_codes'])
                    : [],
                'benchmarkSignals' => isset($benchmark_gate['signals']) && is_array($benchmark_gate['signals'])
                    ? $benchmark_gate['signals']
                    : [],
                'benchmarkPolicy' => isset($benchmark_gate['policy']) && is_array($benchmark_gate['policy'])
                    ? $benchmark_gate['policy']
                    : [],
            ]
        );

        if ($benchmark_status === DBVC_CC_V2_Contracts::READINESS_STATUS_BLOCKED) {
            $blocking_issues[] = $this->make_issue(
                'benchmark_release_gate_blocked',
                __('The benchmark release gate blocked this URL because too many low-confidence review signals remain for a client-ready package.', 'dbvc'),
                'blocking',
                $context
            );

            return;
        }

        $warnings[] = $this->make_issue(
            'benchmark_release_gate_warning',
            __('The benchmark release gate still flags this URL for review because low-confidence signals remain above the release-review threshold.', 'dbvc'),
            'warning',
            $context
        );
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
     * @param array<string, mixed>|null $recommendations
     * @return array<string, int>
     */
    private function summarize_field_context_selection($recommendations)
    {
        if (! is_array($recommendations) || ! isset($recommendations['recommendations']) || ! is_array($recommendations['recommendations'])) {
            return [
                'ambiguous_count' => 0,
                'ambiguous_reviewed_count' => 0,
            ];
        }

        $ambiguous_count = 0;
        $ambiguous_reviewed_count = 0;

        foreach ($recommendations['recommendations'] as $recommendation) {
            if (! is_array($recommendation)) {
                continue;
            }

            $selection = isset($recommendation['selection']) && is_array($recommendation['selection'])
                ? $recommendation['selection']
                : [];
            if (($selection['status'] ?? '') !== 'ambiguous') {
                continue;
            }

            ++$ambiguous_count;
            $default_decision = isset($recommendation['default_decision']) ? sanitize_key((string) $recommendation['default_decision']) : '';
            if ($default_decision !== 'unresolved') {
                ++$ambiguous_reviewed_count;
            }
        }

        return [
            'ambiguous_count' => $ambiguous_count,
            'ambiguous_reviewed_count' => $ambiguous_reviewed_count,
        ];
    }

    /**
     * @param string $domain
     * @return array<string, mixed>
     */
    private function load_current_field_context_provider($domain)
    {
        $domain = sanitize_text_field((string) $domain);
        if ($domain === '') {
            return [];
        }

        $slot_graph_bundle = DBVC_CC_V2_Target_Slot_Graph_Service::get_instance()->get_graph($domain, true);
        if (is_wp_error($slot_graph_bundle) || ! is_array($slot_graph_bundle)) {
            return [
                'status' => 'unavailable',
            ];
        }

        $slot_graph = isset($slot_graph_bundle['slot_graph']) && is_array($slot_graph_bundle['slot_graph']) ? $slot_graph_bundle['slot_graph'] : [];
        $provider = isset($slot_graph['field_context_provider']) && is_array($slot_graph['field_context_provider'])
            ? $slot_graph['field_context_provider']
            : [];
        $summary = $this->normalize_field_context_provider_summary($provider);
        $summary['slot_graph_ref'] = isset($slot_graph_bundle['artifact_relative_path']) ? sanitize_text_field((string) $slot_graph_bundle['artifact_relative_path']) : '';
        $summary['slot_graph_fingerprint'] = isset($slot_graph_bundle['slot_graph_fingerprint']) ? sanitize_text_field((string) $slot_graph_bundle['slot_graph_fingerprint']) : '';

        return $summary;
    }

    /**
     * @param array<string, mixed>|null $recommendations
     * @return array<string, mixed>
     */
    private function summarize_unresolved_items($recommendations)
    {
        $summary = [
            'count' => 0,
            'class_count' => 0,
            'by_class' => [],
        ];

        if (! is_array($recommendations) || ! isset($recommendations['unresolved_items']) || ! is_array($recommendations['unresolved_items'])) {
            return $summary;
        }

        foreach ($recommendations['unresolved_items'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            ++$summary['count'];
            $unresolved_class = isset($item['unresolved_class']) ? sanitize_key((string) $item['unresolved_class']) : '';
            if ($unresolved_class === '') {
                $unresolved_class = 'unspecified';
            }

            if (! isset($summary['by_class'][$unresolved_class])) {
                $summary['by_class'][$unresolved_class] = 0;
            }
            ++$summary['by_class'][$unresolved_class];
        }

        ksort($summary['by_class']);
        $summary['class_count'] = count($summary['by_class']);

        return $summary;
    }

    /**
     * @param array<string, mixed> $recorded
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    private function summarize_field_context_provider_drift(array $recorded, array $current)
    {
        $summary = [
            'status' => 'unverified',
            'mismatch_fields' => [],
        ];

        if (empty($recorded) || empty($current)) {
            return $summary;
        }

        $mismatch_fields = [];
        foreach (['provider', 'contract_version', 'source_hash', 'schema_version', 'site_fingerprint'] as $field) {
            $recorded_value = isset($recorded[$field]) ? sanitize_text_field((string) $recorded[$field]) : '';
            $current_value = isset($current[$field]) ? sanitize_text_field((string) $current[$field]) : '';
            if ($recorded_value !== '' && $current_value !== '' && $recorded_value !== $current_value) {
                $mismatch_fields[] = $field;
            }
        }

        $summary['status'] = empty($mismatch_fields) ? 'matched' : 'drifted';
        $summary['mismatch_fields'] = array_values(array_unique(array_map('sanitize_key', $mismatch_fields)));

        return $summary;
    }

    /**
     * @param array<string, mixed> $provider
     * @return array<string, mixed>
     */
    private function normalize_field_context_provider_summary(array $provider)
    {
        return [
            'status' => isset($provider['status']) ? sanitize_key((string) $provider['status']) : '',
            'provider' => isset($provider['provider']) ? sanitize_key((string) $provider['provider']) : '',
            'transport' => isset($provider['transport']) ? sanitize_key((string) $provider['transport']) : '',
            'contract_version' => isset($provider['contract_version']) ? sanitize_text_field((string) $provider['contract_version']) : '',
            'source_hash' => isset($provider['source_hash']) ? sanitize_text_field((string) $provider['source_hash']) : '',
            'schema_version' => isset($provider['schema_version']) ? sanitize_text_field((string) $provider['schema_version']) : '',
            'site_fingerprint' => isset($provider['site_fingerprint']) ? sanitize_text_field((string) $provider['site_fingerprint']) : '',
            'warnings' => isset($provider['warnings']) && is_array($provider['warnings']) ? array_values($provider['warnings']) : [],
        ];
    }

    /**
     * @param array<string, mixed>|null $target_transform
     * @return array<string, mixed>
     */
    private function summarize_transform_validation($target_transform)
    {
        $summary = [
            'blocked_count' => 0,
            'warning_count' => 0,
            'blocked_target_refs' => [],
            'warning_target_refs' => [],
            'blocked_issue_codes' => [],
            'warning_issue_codes' => [],
        ];

        if (! is_array($target_transform) || ! isset($target_transform['transform_items']) || ! is_array($target_transform['transform_items'])) {
            return $summary;
        }

        foreach ($target_transform['transform_items'] as $transform_item) {
            if (! is_array($transform_item)) {
                continue;
            }

            $validation = isset($transform_item['value_contract_validation']) && is_array($transform_item['value_contract_validation'])
                ? $transform_item['value_contract_validation']
                : [];
            $status = isset($validation['status']) ? sanitize_key((string) $validation['status']) : '';
            $target_ref = isset($transform_item['target_ref']) ? sanitize_text_field((string) $transform_item['target_ref']) : '';

            if ($status === 'blocked') {
                ++$summary['blocked_count'];
                if ($target_ref !== '') {
                    $summary['blocked_target_refs'][] = $target_ref;
                }
                if (isset($validation['blocking_issue_codes']) && is_array($validation['blocking_issue_codes'])) {
                    $summary['blocked_issue_codes'] = array_merge($summary['blocked_issue_codes'], $validation['blocking_issue_codes']);
                }
                continue;
            }

            if ($status === 'warning') {
                ++$summary['warning_count'];
                if ($target_ref !== '') {
                    $summary['warning_target_refs'][] = $target_ref;
                }
                if (isset($validation['warning_codes']) && is_array($validation['warning_codes'])) {
                    $summary['warning_issue_codes'] = array_merge($summary['warning_issue_codes'], $validation['warning_codes']);
                }
            }
        }

        $summary['blocked_target_refs'] = array_values(array_unique(array_map('sanitize_text_field', $summary['blocked_target_refs'])));
        $summary['warning_target_refs'] = array_values(array_unique(array_map('sanitize_text_field', $summary['warning_target_refs'])));
        $summary['blocked_issue_codes'] = array_values(array_unique(array_map('sanitize_key', $summary['blocked_issue_codes'])));
        $summary['warning_issue_codes'] = array_values(array_unique(array_map('sanitize_key', $summary['warning_issue_codes'])));

        return $summary;
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
     * @param array<string, mixed> $row
     * @param array<string, mixed> $artifact_relatives
     * @param array<string, mixed> $field_context_provider
     * @return array<string, mixed>
     */
    private function build_field_context_issue_context(array $row, array $artifact_relatives, array $field_context_provider)
    {
        return array_merge(
            $this->build_issue_context($row, $artifact_relatives, 'mapping_recommendations'),
            [
                'fieldContextProviderStatus' => isset($field_context_provider['status']) ? sanitize_key((string) $field_context_provider['status']) : '',
                'fieldContextSourceHash' => isset($field_context_provider['source_hash']) ? sanitize_text_field((string) $field_context_provider['source_hash']) : '',
                'fieldContextSchemaVersion' => isset($field_context_provider['schema_version']) ? sanitize_text_field((string) $field_context_provider['schema_version']) : '',
                'fieldContextContractVersion' => isset($field_context_provider['contract_version']) ? sanitize_text_field((string) $field_context_provider['contract_version']) : '',
            ]
        );
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
