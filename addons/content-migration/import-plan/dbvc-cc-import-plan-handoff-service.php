<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Import_Plan_Handoff_Service
{
    private const DBVC_CC_HANDOFF_SCHEMA_VERSION = '1.1.0';

    /**
     * @var DBVC_CC_Import_Plan_Handoff_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_Import_Plan_Handoff_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param string $domain
     * @param string $path
     * @param bool   $build_if_missing
     * @return array<string, mixed>|WP_Error
     */
    public function get_handoff_payload($domain, $path, $build_if_missing = true)
    {
        if (! DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_CATALOG_BRIDGE)) {
            return new WP_Error(
                'dbvc_cc_import_plan_handoff_disabled',
                __('Mapping catalog bridge is disabled.', 'dbvc'),
                ['status' => 403]
            );
        }

        $dbvc_cc_validated_path = dbvc_cc_validate_required_relative_path(
            $path,
            __('A valid page path is required.', 'dbvc'),
            __('Invalid page path.', 'dbvc')
        );
        if (is_wp_error($dbvc_cc_validated_path)) {
            return $dbvc_cc_validated_path;
        }
        $path = (string) $dbvc_cc_validated_path;

        $catalog_result = DBVC_CC_Target_Field_Catalog_Service::get_instance()->get_catalog($domain, $build_if_missing);
        if (is_wp_error($catalog_result)) {
            return $catalog_result;
        }

        $section_candidate_result = DBVC_CC_Section_Field_Candidate_Service::get_instance()->get_candidates($domain, $path, $build_if_missing);
        if (is_wp_error($section_candidate_result)) {
            return $section_candidate_result;
        }

        $mapping_decision_result = DBVC_CC_Mapping_Decision_Service::get_instance()->get_decision($domain, $path);
        if (is_wp_error($mapping_decision_result)) {
            return $mapping_decision_result;
        }

        $catalog = isset($catalog_result['catalog']) && is_array($catalog_result['catalog']) ? $catalog_result['catalog'] : [];
        $section_candidates = isset($section_candidate_result['section_candidates']) && is_array($section_candidate_result['section_candidates'])
            ? $section_candidate_result['section_candidates']
            : [];
        $mapping_decision = isset($mapping_decision_result['mapping_decision']) && is_array($mapping_decision_result['mapping_decision'])
            ? $mapping_decision_result['mapping_decision']
            : [];

        $media_enabled = DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_MEDIA_MAPPING_BRIDGE) && class_exists('DBVC_CC_Media_Candidate_Service');
        $media_candidate_result = null;
        $media_decision_result = null;
        $media_candidates = [];
        $media_decision = [];
        $warnings = [];
        $handoff_generated_at = current_time('c');

        if ($media_enabled) {
            $media_candidate_result = DBVC_CC_Media_Candidate_Service::get_instance()->get_candidates($domain, $path, $build_if_missing);
            if (! is_wp_error($media_candidate_result)) {
                $media_candidates = isset($media_candidate_result['media_candidates']) && is_array($media_candidate_result['media_candidates'])
                    ? $media_candidate_result['media_candidates']
                    : [];
            } else {
                $warnings[] = $this->dbvc_cc_make_warning('media_candidates_unavailable', $media_candidate_result->get_error_message(), false);
            }

            if (class_exists('DBVC_CC_Media_Decision_Service')) {
                $media_decision_result = DBVC_CC_Media_Decision_Service::get_instance()->get_decision($domain, $path);
                if (! is_wp_error($media_decision_result)) {
                    $media_decision = isset($media_decision_result['media_decision']) && is_array($media_decision_result['media_decision'])
                        ? $media_decision_result['media_decision']
                        : [];
                } else {
                    $warnings[] = $this->dbvc_cc_make_warning('media_decision_unavailable', $media_decision_result->get_error_message(), false);
                }
            }
        } else {
            $warnings[] = $this->dbvc_cc_make_warning(
                'media_mapping_disabled',
                __('Media mapping bridge is disabled; handoff includes text mapping only.', 'dbvc'),
                false
            );
        }

        $catalog_fingerprint = isset($catalog_result['catalog_fingerprint']) ? (string) $catalog_result['catalog_fingerprint'] : '';
        $source_url = isset($section_candidates['source_url']) ? esc_url_raw((string) $section_candidates['source_url']) : '';
        if ($source_url === '' && isset($mapping_decision['source_url'])) {
            $source_url = esc_url_raw((string) $mapping_decision['source_url']);
        }

        $mapping_fingerprint = isset($mapping_decision['catalog_fingerprint']) ? (string) $mapping_decision['catalog_fingerprint'] : '';
        $media_fingerprint = isset($media_decision['catalog_fingerprint']) ? (string) $media_decision['catalog_fingerprint'] : '';
        if ($mapping_fingerprint !== '' && $catalog_fingerprint !== '' && $mapping_fingerprint !== $catalog_fingerprint) {
            $warnings[] = $this->dbvc_cc_make_warning(
                'mapping_catalog_fingerprint_mismatch',
                __('Mapping decision fingerprint does not match current catalog.', 'dbvc'),
                true
            );
        }
        if ($media_fingerprint !== '' && $catalog_fingerprint !== '' && $media_fingerprint !== $catalog_fingerprint) {
            $warnings[] = $this->dbvc_cc_make_warning(
                'media_catalog_fingerprint_mismatch',
                __('Media decision fingerprint does not match current catalog.', 'dbvc'),
                true
            );
        }

        $approved_mappings = isset($mapping_decision['approved_mappings']) && is_array($mapping_decision['approved_mappings'])
            ? array_values($mapping_decision['approved_mappings'])
            : [];
        $mapping_overrides = isset($mapping_decision['overrides']) && is_array($mapping_decision['overrides'])
            ? array_values($mapping_decision['overrides'])
            : [];
        $mapping_rejections = isset($mapping_decision['rejections']) && is_array($mapping_decision['rejections'])
            ? array_values($mapping_decision['rejections'])
            : [];
        $mapping_unresolved_fields = isset($mapping_decision['unresolved_fields']) && is_array($mapping_decision['unresolved_fields'])
            ? array_values($mapping_decision['unresolved_fields'])
            : [];
        $mapping_unresolved_media = isset($mapping_decision['unresolved_media']) && is_array($mapping_decision['unresolved_media'])
            ? array_values($mapping_decision['unresolved_media'])
            : [];

        $approved_media = isset($media_decision['approved']) && is_array($media_decision['approved'])
            ? array_values($media_decision['approved'])
            : [];
        $media_overrides = isset($media_decision['overrides']) && is_array($media_decision['overrides'])
            ? array_values($media_decision['overrides'])
            : [];
        $media_ignored = isset($media_decision['ignored']) && is_array($media_decision['ignored'])
            ? array_values($media_decision['ignored'])
            : [];
        $media_conflicts = isset($media_decision['conflicts']) && is_array($media_decision['conflicts'])
            ? array_values($media_decision['conflicts'])
            : [];

        $approved_mappings = $this->dbvc_cc_sort_row_list($approved_mappings, ['section_id', 'target_ref', 'candidate_id']);
        $mapping_overrides = $this->dbvc_cc_sort_row_list($mapping_overrides, ['section_id', 'target_ref', 'override_target_ref']);
        $mapping_rejections = $this->dbvc_cc_sort_row_list($mapping_rejections, ['section_id', 'reason']);
        $mapping_unresolved_fields = $this->dbvc_cc_sort_row_list($mapping_unresolved_fields, ['section_id', 'reason']);
        $mapping_unresolved_media = $this->dbvc_cc_sort_row_list($mapping_unresolved_media, ['media_id', 'reason']);
        $approved_media = $this->dbvc_cc_sort_row_list($approved_media, ['media_id', 'target_ref']);
        $media_overrides = $this->dbvc_cc_sort_row_list($media_overrides, ['media_id', 'target_ref', 'override_target_ref']);
        $media_ignored = $this->dbvc_cc_sort_row_list($media_ignored, ['media_id', 'reason']);
        $media_conflicts = $this->dbvc_cc_sort_row_list($media_conflicts, ['media_id', 'reason']);
        $warnings = $this->dbvc_cc_sort_warning_list($warnings);

        $mapping_decision_status = isset($mapping_decision['decision_status']) ? sanitize_key((string) $mapping_decision['decision_status']) : 'pending';
        $media_decision_status = isset($media_decision['decision_status']) ? sanitize_key((string) $media_decision['decision_status']) : 'pending';

        $mapping_candidate_generated_at = $this->dbvc_cc_parse_timestamp(isset($section_candidates['generated_at']) ? $section_candidates['generated_at'] : null);
        $mapping_decision_updated_at = $this->dbvc_cc_parse_timestamp(isset($mapping_decision['updated_at']) ? $mapping_decision['updated_at'] : null);
        if (
            is_int($mapping_candidate_generated_at)
            && is_int($mapping_decision_updated_at)
            && $mapping_decision_updated_at < $mapping_candidate_generated_at
        ) {
            $warnings[] = $this->dbvc_cc_make_warning(
                'mapping_decision_outdated',
                __('Mapping decision is older than current section candidates. Review and re-save mapping decisions.', 'dbvc'),
                true
            );
        }

        $mapping_decision_source_url = isset($mapping_decision['source_url']) ? esc_url_raw((string) $mapping_decision['source_url']) : '';
        if ($mapping_decision_source_url !== '' && $source_url !== '' && $mapping_decision_source_url !== $source_url) {
            $warnings[] = $this->dbvc_cc_make_warning(
                'mapping_decision_source_mismatch',
                __('Mapping decision source URL does not match current crawl artifact source URL.', 'dbvc'),
                true
            );
        }

        $media_candidate_generated_at = $this->dbvc_cc_parse_timestamp(isset($media_candidates['generated_at']) ? $media_candidates['generated_at'] : null);
        $media_decision_updated_at = $this->dbvc_cc_parse_timestamp(isset($media_decision['updated_at']) ? $media_decision['updated_at'] : null);
        if (
            is_int($media_candidate_generated_at)
            && is_int($media_decision_updated_at)
            && $media_decision_updated_at < $media_candidate_generated_at
        ) {
            $warnings[] = $this->dbvc_cc_make_warning(
                'media_decision_outdated',
                __('Media decision is older than current media candidates. Review and re-save media mapping decisions.', 'dbvc'),
                true
            );
        }

        $media_decision_source_url = isset($media_decision['source_url']) ? esc_url_raw((string) $media_decision['source_url']) : '';
        if ($media_decision_source_url !== '' && $source_url !== '' && $media_decision_source_url !== $source_url) {
            $warnings[] = $this->dbvc_cc_make_warning(
                'media_decision_source_mismatch',
                __('Media decision source URL does not match current crawl artifact source URL.', 'dbvc'),
                true
            );
        }

        $mapping_is_ready = ($mapping_decision_status === 'approved' && empty($mapping_unresolved_fields) && empty($mapping_unresolved_media) && ! empty($approved_mappings));
        $media_is_ready = (! $media_enabled) || ($media_decision_status === 'approved' && empty($media_conflicts));
        $blocking_warning_count = 0;
        foreach ($warnings as $warning) {
            if (is_array($warning) && ! empty($warning['blocking'])) {
                $blocking_warning_count++;
            }
        }
        $review = $this->dbvc_cc_build_review_summary(
            $handoff_generated_at,
            $mapping_decision_status,
            $approved_mappings,
            $mapping_unresolved_fields,
            $mapping_unresolved_media,
            $media_enabled,
            $media_decision_status,
            $media_conflicts,
            $warnings
        );
        $handoff_ready = $mapping_is_ready && $media_is_ready && $blocking_warning_count === 0;
        $source_pipeline_id = $this->dbvc_cc_resolve_source_pipeline_id($section_candidates, $mapping_decision, $media_decision);
        $artifact_refs = $this->dbvc_cc_build_artifact_refs(
            $catalog_result,
            $section_candidate_result,
            $mapping_decision_result,
            $media_candidate_result,
            $media_decision_result
        );
        $object_hints = $this->dbvc_cc_build_object_hints(
            $section_candidate_result,
            $mapping_decision,
            $approved_mappings,
            $approved_media
        );
        $default_entity_key = isset($object_hints['default_entity_key']) ? sanitize_text_field((string) $object_hints['default_entity_key']) : 'post:page';

        $phase4_input = [
            'domain' => isset($section_candidate_result['domain']) ? (string) $section_candidate_result['domain'] : sanitize_text_field((string) $domain),
            'path' => isset($section_candidate_result['path']) ? (string) $section_candidate_result['path'] : sanitize_text_field((string) $path),
            'source_url' => $source_url,
            'catalog_fingerprint' => $catalog_fingerprint,
            'default_entity_key' => $default_entity_key,
            'object_hints' => $object_hints,
            'approved_mappings' => $approved_mappings,
            'mapping_overrides' => $mapping_overrides,
            'mapping_rejections' => $mapping_rejections,
            'unresolved_fields' => $mapping_unresolved_fields,
            'unresolved_media' => $mapping_unresolved_media,
            'approved_media_mappings' => $approved_media,
            'media_overrides' => $media_overrides,
            'media_ignored' => $media_ignored,
            'media_conflicts' => $media_conflicts,
            'dry_run_required' => get_option(DBVC_CC_Contracts::IMPORT_POLICY_DRY_RUN_REQUIRED, '1') === '1',
            'idempotent_upsert_required' => get_option(DBVC_CC_Contracts::IMPORT_POLICY_IDEMPOTENT_UPSERT, '1') === '1',
        ];

        return [
            'handoff_schema_version' => self::DBVC_CC_HANDOFF_SCHEMA_VERSION,
            'handoff_generated_at' => $handoff_generated_at,
            'status' => $handoff_ready ? 'ready' : 'needs_review',
            'domain' => $phase4_input['domain'],
            'path' => $phase4_input['path'],
            'source_url' => $source_url,
            'dry_run_required' => $phase4_input['dry_run_required'],
            'idempotent_upsert_required' => $phase4_input['idempotent_upsert_required'],
            'trace' => [
                'source_pipeline_id' => $source_pipeline_id,
                'artifact_refs' => $artifact_refs,
            ],
            'catalog' => [
                'generated_at' => isset($catalog['generated_at']) ? (string) $catalog['generated_at'] : '',
                'catalog_fingerprint' => $catalog_fingerprint,
                'stats' => isset($catalog['stats']) && is_array($catalog['stats']) ? $catalog['stats'] : [],
            ],
            'section_candidates' => [
                'generated_at' => isset($section_candidates['generated_at']) ? (string) $section_candidates['generated_at'] : '',
                'stats' => isset($section_candidates['stats']) && is_array($section_candidates['stats']) ? $section_candidates['stats'] : [],
            ],
            'media_candidates' => [
                'enabled' => $media_enabled,
                'generated_at' => isset($media_candidates['generated_at']) ? (string) $media_candidates['generated_at'] : '',
                'stats' => isset($media_candidates['stats']) && is_array($media_candidates['stats']) ? $media_candidates['stats'] : [],
                'policy' => isset($media_candidates['policy']) && is_array($media_candidates['policy']) ? $media_candidates['policy'] : [],
            ],
            'mapping_decision_summary' => [
                'decision_status' => $mapping_decision_status,
                'approved_count' => count($approved_mappings),
                'override_count' => count($mapping_overrides),
                'rejection_count' => count($mapping_rejections),
                'unresolved_field_count' => count($mapping_unresolved_fields),
                'unresolved_media_count' => count($mapping_unresolved_media),
                'updated_at' => isset($mapping_decision['updated_at']) ? (string) $mapping_decision['updated_at'] : '',
                'updated_by' => isset($mapping_decision['updated_by']) ? absint($mapping_decision['updated_by']) : 0,
            ],
            'media_decision_summary' => [
                'decision_status' => $media_decision_status,
                'approved_count' => count($approved_media),
                'override_count' => count($media_overrides),
                'ignored_count' => count($media_ignored),
                'conflict_count' => count($media_conflicts),
                'updated_at' => isset($media_decision['updated_at']) ? (string) $media_decision['updated_at'] : '',
                'updated_by' => isset($media_decision['updated_by']) ? absint($media_decision['updated_by']) : 0,
            ],
            'blocking_warning_count' => $blocking_warning_count,
            'warnings' => $warnings,
            'review' => $review,
            'phase4_input' => $phase4_input,
        ];
    }

    /**
     * @param string                             $generated_at
     * @param string                             $mapping_decision_status
     * @param array<int, array<string, mixed>>   $approved_mappings
     * @param array<int, array<string, mixed>>   $mapping_unresolved_fields
     * @param array<int, array<string, mixed>>   $mapping_unresolved_media
     * @param bool                               $media_enabled
     * @param string                             $media_decision_status
     * @param array<int, array<string, mixed>>   $media_conflicts
     * @param array<int, array<string, mixed>>   $warnings
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_review_summary(
        $generated_at,
        $mapping_decision_status,
        array $approved_mappings,
        array $mapping_unresolved_fields,
        array $mapping_unresolved_media,
        $media_enabled,
        $media_decision_status,
        array $media_conflicts,
        array $warnings
    ) {
        $reasons = [];

        if ($mapping_decision_status !== 'approved') {
            $reasons[] = $this->dbvc_cc_make_review_reason(
                'mapping_decision_pending',
                __('Mapping decisions must be reviewed and approved before handoff can continue.', 'dbvc'),
                true
            );
        }

        if (empty($approved_mappings)) {
            $reasons[] = $this->dbvc_cc_make_review_reason(
                'no_approved_mappings',
                __('No approved field mappings are available for this path.', 'dbvc'),
                true
            );
        }

        if (! empty($mapping_unresolved_fields)) {
            $reasons[] = $this->dbvc_cc_make_review_reason(
                'unresolved_fields_present',
                sprintf(
                    /* translators: %d unresolved field count */
                    __('%d unresolved field mappings still need review.', 'dbvc'),
                    count($mapping_unresolved_fields)
                ),
                true,
                ['count' => count($mapping_unresolved_fields)]
            );
        }

        if (! empty($mapping_unresolved_media)) {
            $reasons[] = $this->dbvc_cc_make_review_reason(
                'unresolved_media_present',
                sprintf(
                    /* translators: %d unresolved media count */
                    __('%d unresolved media references still need review.', 'dbvc'),
                    count($mapping_unresolved_media)
                ),
                true,
                ['count' => count($mapping_unresolved_media)]
            );
        }

        if ($media_enabled && $media_decision_status !== 'approved') {
            $reasons[] = $this->dbvc_cc_make_review_reason(
                'media_decision_pending',
                __('Media decisions must be reviewed and approved before media handoff can continue.', 'dbvc'),
                true
            );
        }

        if (! empty($media_conflicts)) {
            $reasons[] = $this->dbvc_cc_make_review_reason(
                'media_conflicts_present',
                sprintf(
                    /* translators: %d media conflict count */
                    __('%d media mapping conflicts still need review.', 'dbvc'),
                    count($media_conflicts)
                ),
                true,
                ['count' => count($media_conflicts)]
            );
        }

        foreach ($warnings as $warning) {
            if (! is_array($warning) || empty($warning['blocking'])) {
                continue;
            }

            $reasons[] = $this->dbvc_cc_make_review_reason(
                isset($warning['code']) ? (string) $warning['code'] : 'handoff_warning',
                isset($warning['message']) ? (string) $warning['message'] : __('Handoff review is blocked by a warning.', 'dbvc'),
                true
            );
        }

        $reasons = $this->dbvc_cc_sort_review_reason_list($reasons);

        return [
            'needs_review' => ! empty($reasons),
            'generated_at' => (string) $generated_at,
            'reason_count' => count($reasons),
            'reason_codes' => array_values(array_map(static function (array $reason): string {
                return isset($reason['code']) ? (string) $reason['code'] : '';
            }, $reasons)),
            'reasons' => $reasons,
        ];
    }

    /**
     * @param string $code
     * @param string $message
     * @param bool   $blocking
     * @return array<string, mixed>
     */
    private function dbvc_cc_make_warning($code, $message, $blocking)
    {
        $safe_code = sanitize_key((string) $code);
        if ($safe_code === '') {
            $safe_code = 'handoff_warning';
        }

        return [
            'code' => $safe_code,
            'message' => sanitize_text_field((string) $message),
            'blocking' => (bool) $blocking,
        ];
    }

    /**
     * @param string               $code
     * @param string               $message
     * @param bool                 $blocking
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function dbvc_cc_make_review_reason($code, $message, $blocking, array $context = [])
    {
        $reason = [
            'code' => sanitize_key((string) $code),
            'message' => sanitize_text_field((string) $message),
            'blocking' => (bool) $blocking,
        ];

        $safe_context = [];
        foreach ($context as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $safe_context[sanitize_key($key)] = is_string($value)
                    ? sanitize_text_field($value)
                    : $value;
            }
        }

        if (! empty($safe_context)) {
            $reason['context'] = $safe_context;
        }

        return $reason;
    }

    /**
     * @param array<int, array<string, mixed>> $reasons
     * @return array<int, array<string, mixed>>
     */
    private function dbvc_cc_sort_review_reason_list(array $reasons)
    {
        $normalized = [];
        foreach ($reasons as $reason) {
            if (! is_array($reason)) {
                continue;
            }

            $normalized[] = $this->dbvc_cc_recursive_sort_assoc([
                'code' => isset($reason['code']) ? sanitize_key((string) $reason['code']) : '',
                'message' => isset($reason['message']) ? sanitize_text_field((string) $reason['message']) : '',
                'blocking' => ! empty($reason['blocking']),
                'context' => isset($reason['context']) && is_array($reason['context']) ? $reason['context'] : [],
            ]);
        }

        usort($normalized, static function (array $left, array $right): int {
            $left_key = (string) ($left['code'] ?? '') . '|' . (string) ($left['message'] ?? '');
            $right_key = (string) ($right['code'] ?? '') . '|' . (string) ($right['message'] ?? '');
            return strnatcasecmp($left_key, $right_key);
        });

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $warnings
     * @return array<int, array<string, mixed>>
     */
    private function dbvc_cc_sort_warning_list(array $warnings)
    {
        $normalized = [];
        foreach ($warnings as $warning) {
            if (! is_array($warning)) {
                continue;
            }
            $normalized[] = $this->dbvc_cc_make_warning(
                isset($warning['code']) ? (string) $warning['code'] : '',
                isset($warning['message']) ? (string) $warning['message'] : '',
                ! empty($warning['blocking'])
            );
        }

        usort($normalized, static function (array $left, array $right): int {
            $left_key = (string) $left['code'] . '|' . (string) $left['message'] . '|' . (! empty($left['blocking']) ? '1' : '0');
            $right_key = (string) $right['code'] . '|' . (string) $right['message'] . '|' . (! empty($right['blocking']) ? '1' : '0');
            return strnatcasecmp($left_key, $right_key);
        });

        return $normalized;
    }

    /**
     * @param array<int, mixed>   $rows
     * @param array<int, string>  $priority_keys
     * @return array<int, array<string, mixed>>
     */
    private function dbvc_cc_sort_row_list(array $rows, array $priority_keys = [])
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized[] = $this->dbvc_cc_recursive_sort_assoc($row);
        }

        usort($normalized, function (array $left, array $right) use ($priority_keys): int {
            $left_key = $this->dbvc_cc_row_sort_key($left, $priority_keys);
            $right_key = $this->dbvc_cc_row_sort_key($right, $priority_keys);
            return strnatcasecmp($left_key, $right_key);
        });

        return $normalized;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string>   $priority_keys
     * @return string
     */
    private function dbvc_cc_row_sort_key(array $row, array $priority_keys)
    {
        $parts = [];
        foreach ($priority_keys as $priority_key) {
            if (! is_string($priority_key) || $priority_key === '') {
                continue;
            }
            if (array_key_exists($priority_key, $row)) {
                $parts[] = sanitize_text_field((string) $row[$priority_key]);
            } else {
                $parts[] = '';
            }
        }

        $parts[] = (string) wp_json_encode($row);
        return implode('|', $parts);
    }

    /**
     * @param mixed $timestamp
     * @return int|false
     */
    private function dbvc_cc_parse_timestamp($timestamp)
    {
        if (! is_string($timestamp) || trim($timestamp) === '') {
            return false;
        }

        $parsed = strtotime($timestamp);
        if (! is_int($parsed) || $parsed <= 0) {
            return false;
        }

        return $parsed;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function dbvc_cc_recursive_sort_assoc($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($this->dbvc_cc_is_list($value)) {
            $items = [];
            foreach ($value as $item) {
                $items[] = $this->dbvc_cc_recursive_sort_assoc($item);
            }
            return $items;
        }

        $sorted = [];
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $sorted[$key] = $this->dbvc_cc_recursive_sort_assoc($value[$key]);
        }

        return $sorted;
    }

    /**
     * @param array<mixed> $array
     * @return bool
     */
    private function dbvc_cc_is_list(array $array)
    {
        if ($array === []) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }

    /**
     * @param array<string, mixed> $section_candidates
     * @param array<string, mixed> $mapping_decision
     * @param array<string, mixed> $media_decision
     * @return string
     */
    private function dbvc_cc_resolve_source_pipeline_id(array $section_candidates, array $mapping_decision, array $media_decision)
    {
        $candidates = [
            isset($section_candidates['pipeline_id']) ? (string) $section_candidates['pipeline_id'] : '',
            isset($mapping_decision['pipeline_id']) ? (string) $mapping_decision['pipeline_id'] : '',
            isset($media_decision['pipeline_id']) ? (string) $media_decision['pipeline_id'] : '',
            isset($mapping_decision['provenance']['pipeline_id']) ? (string) $mapping_decision['provenance']['pipeline_id'] : '',
            isset($media_decision['provenance']['pipeline_id']) ? (string) $media_decision['provenance']['pipeline_id'] : '',
        ];

        foreach ($candidates as $pipeline_id) {
            $safe = sanitize_key((string) $pipeline_id);
            if ($safe !== '') {
                return $safe;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed>      $catalog_result
     * @param array<string, mixed>      $section_candidate_result
     * @param array<string, mixed>      $mapping_decision_result
     * @param array<string, mixed>|null $media_candidate_result
     * @param array<string, mixed>|null $media_decision_result
     * @return array<string, string>
     */
    private function dbvc_cc_build_artifact_refs(
        array $catalog_result,
        array $section_candidate_result,
        array $mapping_decision_result,
        $media_candidate_result,
        $media_decision_result
    ) {
        $artifact_refs = [
            'catalog' => isset($catalog_result['catalog_file']) ? $this->dbvc_cc_relativize_storage_path((string) $catalog_result['catalog_file']) : '',
            'section_candidates' => isset($section_candidate_result['candidate_file']) ? $this->dbvc_cc_relativize_storage_path((string) $section_candidate_result['candidate_file']) : '',
            'mapping_decision' => isset($mapping_decision_result['decision_file']) ? $this->dbvc_cc_relativize_storage_path((string) $mapping_decision_result['decision_file']) : '',
            'media_candidates' => '',
            'media_decision' => '',
        ];

        if (is_array($media_candidate_result) && isset($media_candidate_result['candidate_file'])) {
            $artifact_refs['media_candidates'] = $this->dbvc_cc_relativize_storage_path((string) $media_candidate_result['candidate_file']);
        }

        if (is_array($media_decision_result) && isset($media_decision_result['decision_file'])) {
            $artifact_refs['media_decision'] = $this->dbvc_cc_relativize_storage_path((string) $media_decision_result['decision_file']);
        }

        return $artifact_refs;
    }

    /**
     * @param array<string, mixed> $section_candidate_result
     * @param array<string, mixed> $mapping_decision
     * @param array<int, array<string, mixed>> $approved_mappings
     * @param array<int, array<string, mixed>> $approved_media
     * @return array<string, mixed>
     */
    private function dbvc_cc_build_object_hints(array $section_candidate_result, array $mapping_decision, array $approved_mappings, array $approved_media)
    {
        $mapped_post_subtypes = $this->dbvc_cc_collect_mapped_post_subtypes($approved_mappings, $approved_media);
        $suggested_post_type = '';
        $suggested_post_type_confidence = 0.0;
        $suggestions_status = 'missing';
        $override_post_type = '';
        $override_source = 'auto';

        if (isset($mapping_decision['object_hints']) && is_array($mapping_decision['object_hints'])) {
            $override_post_type = isset($mapping_decision['object_hints']['override_post_type'])
                ? sanitize_key((string) $mapping_decision['object_hints']['override_post_type'])
                : '';
            $override_source = isset($mapping_decision['object_hints']['source'])
                ? sanitize_key((string) $mapping_decision['object_hints']['source'])
                : 'manual_override';
            if ($override_post_type !== '') {
                $public_post_types = array_values(get_post_types(['public' => true], 'names'));
                if (! in_array($override_post_type, $public_post_types, true)) {
                    $override_post_type = '';
                }
            }
        }

        $suggestions = $this->dbvc_cc_load_mapping_suggestions_artifact($section_candidate_result);
        if (is_array($suggestions)) {
            $suggestions_status = 'loaded';
            $suggested_post_type = isset($suggestions['suggestions']['post_type']['value'])
                ? sanitize_key((string) $suggestions['suggestions']['post_type']['value'])
                : '';
            $suggested_post_type_confidence = isset($suggestions['suggestions']['post_type']['confidence'])
                ? (float) $suggestions['suggestions']['post_type']['confidence']
                : 0.0;
            $suggested_post_type_confidence = max(0.0, min(1.0, $suggested_post_type_confidence));

            $public_post_types = array_values(get_post_types(['public' => true], 'names'));
            if ($suggested_post_type !== '' && ! in_array($suggested_post_type, $public_post_types, true)) {
                $suggested_post_type = '';
            }
        }

        $default_subtype = 'page';
        $default_reason = 'deterministic_fallback';
        if ($override_post_type !== '') {
            $default_subtype = $override_post_type;
            $default_reason = 'manual_override_post_type';
        } elseif (count($mapped_post_subtypes) === 1) {
            $default_subtype = (string) $mapped_post_subtypes[0];
            $default_reason = 'mapped_target_ref';
        } elseif (count($mapped_post_subtypes) > 1) {
            if ($suggested_post_type !== '' && in_array($suggested_post_type, $mapped_post_subtypes, true)) {
                $default_subtype = $suggested_post_type;
                $default_reason = 'mapped_target_ref_ambiguous_ai_tiebreak';
            } else {
                $default_subtype = (string) $mapped_post_subtypes[0];
                $default_reason = 'mapped_target_ref_ambiguous_first_sorted';
            }
        } elseif ($suggested_post_type !== '') {
            $default_subtype = $suggested_post_type;
            $default_reason = 'ai_suggested_post_type';
        }

        $default_entity_key = 'post:' . sanitize_key($default_subtype);

        return [
            'default_entity_key' => $default_entity_key,
            'default_entity_reason' => $default_reason,
            'override_post_type' => $override_post_type,
            'override_source' => $override_source,
            'mapped_post_subtypes' => $mapped_post_subtypes,
            'suggestions_status' => $suggestions_status,
            'suggested_post_type' => $suggested_post_type,
            'suggested_post_type_confidence' => $suggested_post_type_confidence,
        ];
    }

    /**
     * @param array<string, mixed> $section_candidate_result
     * @return array<string, mixed>|null
     */
    private function dbvc_cc_load_mapping_suggestions_artifact(array $section_candidate_result)
    {
        $candidate_file = isset($section_candidate_result['candidate_file'])
            ? wp_normalize_path((string) $section_candidate_result['candidate_file'])
            : '';
        $path = isset($section_candidate_result['path']) ? sanitize_text_field((string) $section_candidate_result['path']) : '';
        if ($candidate_file === '' || $path === '') {
            return null;
        }

        $slug = sanitize_title((string) basename($path));
        if ($slug === '') {
            return null;
        }

        $page_dir = wp_normalize_path((string) dirname($candidate_file));
        if ($page_dir === '' || ! is_dir($page_dir)) {
            return null;
        }

        $suggestions_file = trailingslashit($page_dir) . $slug . '.mapping.suggestions.json';
        if (! file_exists($suggestions_file)) {
            return null;
        }

        $raw = @file_get_contents($suggestions_file);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<int, array<string, mixed>> $approved_mappings
     * @param array<int, array<string, mixed>> $approved_media
     * @return array<int, string>
     */
    private function dbvc_cc_collect_mapped_post_subtypes(array $approved_mappings, array $approved_media)
    {
        $subtypes = [];
        foreach ($approved_mappings as $row) {
            if (! is_array($row)) {
                continue;
            }

            $target_ref = isset($row['target_ref']) ? sanitize_text_field((string) $row['target_ref']) : '';
            $subtype = $this->dbvc_cc_extract_post_subtype_from_target_ref($target_ref);
            if ($subtype !== '') {
                $subtypes[$subtype] = $subtype;
            }
        }

        foreach ($approved_media as $row) {
            if (! is_array($row)) {
                continue;
            }

            $target_ref = isset($row['target_ref']) ? sanitize_text_field((string) $row['target_ref']) : '';
            $subtype = $this->dbvc_cc_extract_post_subtype_from_target_ref($target_ref);
            if ($subtype !== '') {
                $subtypes[$subtype] = $subtype;
            }
        }

        $values = array_values($subtypes);
        usort(
            $values,
            static function ($left, $right) {
                return strnatcasecmp((string) $left, (string) $right);
            }
        );

        return $values;
    }

    /**
     * @param string $target_ref
     * @return string
     */
    private function dbvc_cc_extract_post_subtype_from_target_ref($target_ref)
    {
        $target_ref = sanitize_text_field((string) $target_ref);
        if ($target_ref === '') {
            return '';
        }

        $parts = explode(':', $target_ref);
        if (count($parts) < 4) {
            return '';
        }

        if (sanitize_key((string) $parts[0]) !== 'meta') {
            return '';
        }

        if (sanitize_key((string) $parts[1]) !== 'post') {
            return '';
        }

        $subtype = sanitize_key((string) $parts[2]);
        return $subtype;
    }

    /**
     * @param string $absolute_path
     * @return string
     */
    private function dbvc_cc_relativize_storage_path($absolute_path)
    {
        $path = wp_normalize_path((string) $absolute_path);
        if ($path === '') {
            return '';
        }

        $base_dir = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        $base_path = wp_normalize_path((string) $base_dir);
        if ($base_path === '') {
            return $path;
        }

        $base_path = untrailingslashit($base_path);
        if (strpos($path, $base_path . '/') === 0) {
            return ltrim(substr($path, strlen($base_path)), '/');
        }

        return $path;
    }
}
