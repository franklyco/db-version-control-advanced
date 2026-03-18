<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Import_Plan_Service
{
    private const DBVC_CC_IMPORT_PLAN_SCHEMA_VERSION = '1.0.0';

    /**
     * @var DBVC_CC_Import_Plan_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_Import_Plan_Service
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
    public function get_dry_run_plan($domain, $path, $build_if_missing = true)
    {
        $handoff = DBVC_CC_Import_Plan_Handoff_Service::get_instance()->get_handoff_payload($domain, $path, $build_if_missing);
        if (is_wp_error($handoff)) {
            return $handoff;
        }

        $phase4_input = isset($handoff['phase4_input']) && is_array($handoff['phase4_input']) ? $handoff['phase4_input'] : [];
        $approved_mappings = isset($phase4_input['approved_mappings']) && is_array($phase4_input['approved_mappings'])
            ? array_values($phase4_input['approved_mappings'])
            : [];
        $approved_media_mappings = isset($phase4_input['approved_media_mappings']) && is_array($phase4_input['approved_media_mappings'])
            ? array_values($phase4_input['approved_media_mappings'])
            : [];
        $mapping_rejections = isset($phase4_input['mapping_rejections']) && is_array($phase4_input['mapping_rejections'])
            ? array_values($phase4_input['mapping_rejections'])
            : [];
        $media_ignored = isset($phase4_input['media_ignored']) && is_array($phase4_input['media_ignored'])
            ? array_values($phase4_input['media_ignored'])
            : [];
        $unresolved_fields = isset($phase4_input['unresolved_fields']) && is_array($phase4_input['unresolved_fields'])
            ? array_values($phase4_input['unresolved_fields'])
            : [];
        $unresolved_media = isset($phase4_input['unresolved_media']) && is_array($phase4_input['unresolved_media'])
            ? array_values($phase4_input['unresolved_media'])
            : [];
        $media_conflicts = isset($phase4_input['media_conflicts']) && is_array($phase4_input['media_conflicts'])
            ? array_values($phase4_input['media_conflicts'])
            : [];

        $issues = [];
        $handoff_review = isset($handoff['review']) && is_array($handoff['review']) ? $handoff['review'] : [];
        $handoff_review_reasons = isset($handoff_review['reasons']) && is_array($handoff_review['reasons'])
            ? array_values($handoff_review['reasons'])
            : [];

        $handoff_status = isset($handoff['status']) ? sanitize_key((string) $handoff['status']) : 'needs_review';
        if ($handoff_status !== 'ready') {
            $handoff_issue = $this->dbvc_cc_build_issue(
                'handoff_needs_review',
                __('Mapping handoff is not ready for import planning.', 'dbvc'),
                true
            );
            if (! empty($handoff_review_reasons)) {
                $handoff_issue['review_reasons'] = $handoff_review_reasons;
            }
            $issues[] = $handoff_issue;
        }

        if (! empty($unresolved_fields)) {
            $issues[] = $this->dbvc_cc_build_issue(
                'unresolved_fields',
                sprintf(
                    /* translators: %d unresolved field count */
                    __('%d unresolved field mappings remain.', 'dbvc'),
                    count($unresolved_fields)
                ),
                true
            );
        }

        if (! empty($unresolved_media) || ! empty($media_conflicts)) {
            $issues[] = $this->dbvc_cc_build_issue(
                'unresolved_media',
                sprintf(
                    /* translators: 1: unresolved media count, 2: conflict count */
                    __('%1$d unresolved media mappings and %2$d media conflicts remain.', 'dbvc'),
                    count($unresolved_media),
                    count($media_conflicts)
                ),
                true
            );
        }

        $handoff_warnings = isset($handoff['warnings']) && is_array($handoff['warnings']) ? array_values($handoff['warnings']) : [];
        $handoff_trace = isset($handoff['trace']) && is_array($handoff['trace']) ? $handoff['trace'] : [];
        $dbvc_cc_handoff_schema_version = isset($handoff['handoff_schema_version'])
            ? sanitize_text_field((string) $handoff['handoff_schema_version'])
            : '1.1.0';
        $dbvc_cc_handoff_generated_at = isset($handoff['handoff_generated_at'])
            ? sanitize_text_field((string) $handoff['handoff_generated_at'])
            : '';
        foreach ($handoff_warnings as $warning) {
            if (! is_array($warning)) {
                continue;
            }
            if (empty($warning['blocking'])) {
                continue;
            }
            $issues[] = $this->dbvc_cc_build_issue(
                isset($warning['code']) ? (string) $warning['code'] : 'handoff_warning',
                isset($warning['message']) ? (string) $warning['message'] : __('Blocking handoff warning detected.', 'dbvc'),
                true
            );
        }

        $dry_run_required = ! empty($phase4_input['dry_run_required']);
        $idempotent_upsert_required = ! empty($phase4_input['idempotent_upsert_required']);
        if (! $dry_run_required) {
            $issues[] = $this->dbvc_cc_build_issue(
                'dry_run_policy_disabled',
                __('Dry-run policy is disabled. Enable dry-run policy before write execution.', 'dbvc'),
                true
            );
        }

        if (! $idempotent_upsert_required) {
            $issues[] = $this->dbvc_cc_build_issue(
                'idempotent_upsert_policy_disabled',
                __('Idempotent upsert policy is disabled. Enable idempotent upsert policy before write execution.', 'dbvc'),
                true
            );
        }

        $plan_fingerprint_source = [
            'domain' => isset($phase4_input['domain']) ? (string) $phase4_input['domain'] : '',
            'path' => isset($phase4_input['path']) ? (string) $phase4_input['path'] : '',
            'catalog_fingerprint' => isset($phase4_input['catalog_fingerprint']) ? (string) $phase4_input['catalog_fingerprint'] : '',
            'approved_mappings' => $approved_mappings,
            'approved_media_mappings' => $approved_media_mappings,
        ];
        $plan_id = 'dbvc_cc_dryrun_' . substr(hash('sha256', wp_json_encode($plan_fingerprint_source)), 0, 16);
        $media_field_catalog = $this->dbvc_cc_load_media_field_catalog(isset($phase4_input['domain']) ? (string) $phase4_input['domain'] : '');

        $upsert_operations = [];
        foreach ($approved_mappings as $row) {
            if (! is_array($row)) {
                continue;
            }
            $section_id = isset($row['section_id']) ? sanitize_text_field((string) $row['section_id']) : '';
            $target_ref = isset($row['target_ref']) ? sanitize_text_field((string) $row['target_ref']) : '';
            if ($section_id === '' || $target_ref === '') {
                continue;
            }

            $upsert_operations[] = [
                'operation_type' => 'upsert_field_mapping',
                'section_id' => $section_id,
                'target_ref' => $target_ref,
                'confidence' => isset($row['confidence']) ? (float) $row['confidence'] : null,
            ];
        }

        $media_operations = [];
        foreach ($approved_media_mappings as $row) {
            if (! is_array($row)) {
                continue;
            }
            $media_id = isset($row['media_id']) ? sanitize_text_field((string) $row['media_id']) : '';
            $target_ref = isset($row['target_ref']) ? sanitize_text_field((string) $row['target_ref']) : '';
            if ($media_id === '' || $target_ref === '') {
                continue;
            }

            $media_operations[] = [
                'operation_type' => 'upsert_media_mapping',
                'media_id' => $media_id,
                'target_ref' => $target_ref,
                'source_url' => isset($row['source_url']) ? esc_url_raw((string) $row['source_url']) : '',
                'media_kind' => isset($row['media_kind']) ? sanitize_key((string) $row['media_kind']) : '',
                'storage_shape' => $this->dbvc_cc_resolve_media_storage_shape($target_ref, $row, $media_field_catalog),
                'multi_value' => $this->dbvc_cc_resolve_media_multi_value($target_ref, $row, $media_field_catalog),
                'return_format' => $this->dbvc_cc_resolve_media_return_format($target_ref, $row, $media_field_catalog),
                'accepted_media_kinds' => $this->dbvc_cc_resolve_media_accepted_kinds($target_ref, $row, $media_field_catalog),
                'normalized_value_strategy' => $this->dbvc_cc_resolve_media_value_strategy($target_ref, $row, $media_field_catalog),
            ];
        }

        $blocking_issue_count = 0;
        foreach ($issues as $issue) {
            if (is_array($issue) && ! empty($issue['blocking'])) {
                $blocking_issue_count++;
            }
        }

        return [
            'dry_run_plan_schema_version' => self::DBVC_CC_IMPORT_PLAN_SCHEMA_VERSION,
            'generated_at' => current_time('c'),
            'status' => $blocking_issue_count > 0 ? 'blocked' : 'ready',
            'dry_run' => true,
            'write_strategy' => 'idempotent_upsert',
            'plan_id' => $plan_id,
            'domain' => isset($phase4_input['domain']) ? sanitize_text_field((string) $phase4_input['domain']) : '',
            'path' => isset($phase4_input['path']) ? sanitize_text_field((string) $phase4_input['path']) : '',
            'source_url' => isset($phase4_input['source_url']) ? esc_url_raw((string) $phase4_input['source_url']) : '',
            'handoff_schema_version' => $dbvc_cc_handoff_schema_version,
            'handoff_generated_at' => $dbvc_cc_handoff_generated_at,
            'policy' => [
                'dry_run_required' => $dry_run_required,
                'idempotent_upsert_required' => $idempotent_upsert_required,
            ],
            'operation_counts' => [
                'upsert_mappings' => count($upsert_operations),
                'upsert_media_mappings' => count($media_operations),
                'mapping_rejections' => count($mapping_rejections),
                'media_ignored' => count($media_ignored),
                'unresolved_fields' => count($unresolved_fields),
                'unresolved_media' => count($unresolved_media),
                'media_conflicts' => count($media_conflicts),
            ],
            'operations' => [
                'upsert_mappings' => $upsert_operations,
                'upsert_media_mappings' => $media_operations,
            ],
            'blocking_issue_count' => $blocking_issue_count,
            'issues' => $issues,
            'handoff' => [
                'schema_version' => $dbvc_cc_handoff_schema_version,
                'generated_at' => $dbvc_cc_handoff_generated_at,
                'status' => $handoff_status,
                'blocking_warning_count' => isset($handoff['blocking_warning_count']) ? absint($handoff['blocking_warning_count']) : 0,
                'warnings' => $handoff_warnings,
            ],
            'trace' => $handoff_trace,
            'phase4_input' => $phase4_input,
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
     * @param string $domain
     * @return array<string, array<string, mixed>>
     */
    private function dbvc_cc_load_media_field_catalog($domain)
    {
        $domain = sanitize_text_field((string) $domain);
        if ($domain === '') {
            return [];
        }

        $catalog_result = DBVC_CC_Target_Field_Catalog_Service::get_instance()->get_catalog($domain, true);
        if (is_wp_error($catalog_result) || ! is_array($catalog_result)) {
            return [];
        }

        $catalog = isset($catalog_result['catalog']) && is_array($catalog_result['catalog']) ? $catalog_result['catalog'] : [];
        return isset($catalog['media_field_catalog']) && is_array($catalog['media_field_catalog'])
            ? $catalog['media_field_catalog']
            : [];
    }

    /**
     * @param string                     $target_ref
     * @param array<string, mixed>       $row
     * @param array<string, mixed>       $media_field_catalog
     * @return array<string, mixed>
     */
    private function dbvc_cc_resolve_media_shape_meta($target_ref, array $row, array $media_field_catalog = [])
    {
        $target_ref = sanitize_text_field((string) $target_ref);
        $catalog_entry = ($target_ref !== '' && isset($media_field_catalog[$target_ref]) && is_array($media_field_catalog[$target_ref]))
            ? $media_field_catalog[$target_ref]
            : [];
        $storage_shape = isset($row['storage_shape']) ? sanitize_key((string) $row['storage_shape']) : '';
        if ($storage_shape === '') {
            $storage_shape = isset($catalog_entry['storage_shape']) ? sanitize_key((string) $catalog_entry['storage_shape']) : '';
        }
        if ($storage_shape === '' && $target_ref === 'core:featured_image') {
            $storage_shape = 'featured_image';
        }
        if ($storage_shape === '') {
            $storage_shape = 'attachment_id';
        }

        $multi_value = array_key_exists('multi_value', $row)
            ? ! empty($row['multi_value'])
            : (! empty($catalog_entry['multi_value']) || $storage_shape === 'attachment_id_list');
        $return_format = isset($row['return_format']) ? sanitize_key((string) $row['return_format']) : '';
        if ($return_format === '') {
            $return_format = isset($catalog_entry['return_format']) ? sanitize_key((string) $catalog_entry['return_format']) : '';
        }
        if ($return_format === '') {
            $return_format = in_array($storage_shape, ['attachment_id_list'], true) ? 'ids' : ($storage_shape === 'remote_url' ? 'url' : 'id');
        }
        $accepted_media_kinds = isset($row['accepted_media_kinds']) && is_array($row['accepted_media_kinds'])
            ? array_values(array_map('sanitize_key', $row['accepted_media_kinds']))
            : (isset($catalog_entry['accepted_media_kinds']) && is_array($catalog_entry['accepted_media_kinds'])
                ? array_values(array_map('sanitize_key', $catalog_entry['accepted_media_kinds']))
                : []);
        if ($target_ref === 'core:featured_image' && empty($accepted_media_kinds)) {
            $accepted_media_kinds = ['image'];
        }
        $normalized_value_strategy = isset($row['normalized_value_strategy']) ? sanitize_key((string) $row['normalized_value_strategy']) : '';
        if ($normalized_value_strategy === '') {
            $normalized_value_strategy = isset($catalog_entry['normalized_value_strategy']) ? sanitize_key((string) $catalog_entry['normalized_value_strategy']) : '';
        }
        if ($normalized_value_strategy === '') {
            $normalized_value_strategy = $storage_shape === 'attachment_id_list'
                ? 'replace_attachment_list'
                : ($storage_shape === 'remote_url' ? 'replace_remote_url' : 'replace_single_attachment');
        }

        return [
            'storage_shape' => $storage_shape,
            'multi_value' => $multi_value,
            'return_format' => $return_format,
            'accepted_media_kinds' => $accepted_media_kinds,
            'normalized_value_strategy' => $normalized_value_strategy,
        ];
    }

    /**
     * @param string               $target_ref
     * @param array<string, mixed> $row
     * @param array<string, mixed> $media_field_catalog
     * @return string
     */
    private function dbvc_cc_resolve_media_storage_shape($target_ref, array $row, array $media_field_catalog = [])
    {
        $meta = $this->dbvc_cc_resolve_media_shape_meta($target_ref, $row, $media_field_catalog);
        return isset($meta['storage_shape']) ? (string) $meta['storage_shape'] : 'attachment_id';
    }

    /**
     * @param string               $target_ref
     * @param array<string, mixed> $row
     * @param array<string, mixed> $media_field_catalog
     * @return bool
     */
    private function dbvc_cc_resolve_media_multi_value($target_ref, array $row, array $media_field_catalog = [])
    {
        $meta = $this->dbvc_cc_resolve_media_shape_meta($target_ref, $row, $media_field_catalog);
        return ! empty($meta['multi_value']);
    }

    /**
     * @param string               $target_ref
     * @param array<string, mixed> $row
     * @param array<string, mixed> $media_field_catalog
     * @return string
     */
    private function dbvc_cc_resolve_media_return_format($target_ref, array $row, array $media_field_catalog = [])
    {
        $meta = $this->dbvc_cc_resolve_media_shape_meta($target_ref, $row, $media_field_catalog);
        return isset($meta['return_format']) ? (string) $meta['return_format'] : 'id';
    }

    /**
     * @param string               $target_ref
     * @param array<string, mixed> $row
     * @param array<string, mixed> $media_field_catalog
     * @return array<int, string>
     */
    private function dbvc_cc_resolve_media_accepted_kinds($target_ref, array $row, array $media_field_catalog = [])
    {
        $meta = $this->dbvc_cc_resolve_media_shape_meta($target_ref, $row, $media_field_catalog);
        return isset($meta['accepted_media_kinds']) && is_array($meta['accepted_media_kinds'])
            ? array_values($meta['accepted_media_kinds'])
            : [];
    }

    /**
     * @param string               $target_ref
     * @param array<string, mixed> $row
     * @param array<string, mixed> $media_field_catalog
     * @return string
     */
    private function dbvc_cc_resolve_media_value_strategy($target_ref, array $row, array $media_field_catalog = [])
    {
        $meta = $this->dbvc_cc_resolve_media_shape_meta($target_ref, $row, $media_field_catalog);
        return isset($meta['normalized_value_strategy']) ? (string) $meta['normalized_value_strategy'] : 'replace_single_attachment';
    }
}
