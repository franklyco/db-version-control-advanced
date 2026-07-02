<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Portability_Apply_Service
{
    /**
     * @param string $session_id
     * @param array<string, string> $requested_decisions
     * @return array<string, mixed>|\WP_Error
     */
    public static function apply_session($session_id, array $requested_decisions, array $manual_row_ids = [])
    {
        if (class_exists('DBVC_Bricks_Addon') && DBVC_Bricks_Addon::get_bool_setting('dbvc_bricks_read_only', false)) {
            return new \WP_Error('dbvc_bricks_portability_read_only', __('Bricks portability apply is disabled while Bricks read-only mode is active.', 'dbvc'), ['status' => 403]);
        }

        $session = DBVC_Bricks_Portability_Package_Service::load_session($session_id);
        if (is_wp_error($session)) {
            return $session;
        }

        $rows = isset($session['rows']) && is_array($session['rows']) ? $session['rows'] : [];
        $effective_decisions = self::prepare_review_decisions($rows, $requested_decisions);
        if (is_wp_error($effective_decisions)) {
            return $effective_decisions;
        }

        $approved_at_gmt = gmdate('c');
        $affected_domains = self::collect_affected_domains($rows, $effective_decisions);
        if (empty($affected_domains)) {
            $persisted_session = DBVC_Bricks_Portability_Package_Service::persist_session_approval($session, $effective_decisions, [
                'approved_at_gmt' => $approved_at_gmt,
                'applied_to_site' => false,
                'job_id' => (int) ($session['job_id'] ?? 0),
                'manual_row_ids' => $manual_row_ids,
            ]);
            if (is_wp_error($persisted_session)) {
                return $persisted_session;
            }

            self::log_activity(
                'portability_apply_approved_only',
                'info',
                'Bricks portability decisions approved with no site mutations required.',
                [
                    'session_id' => sanitize_key((string) ($session['session_id'] ?? '')),
                    'package_id' => sanitize_key((string) ($session['package_id'] ?? '')),
                    'approved_at_gmt' => $approved_at_gmt,
                    'decision_count' => count($effective_decisions),
                ],
                0
            );

            return [
                'ok' => true,
                'applied' => false,
                'message' => __('Approved decisions were recorded. No incoming changes were selected for site mutation.', 'dbvc'),
                'session_id' => sanitize_key((string) ($session['session_id'] ?? '')),
                'package_id' => sanitize_key((string) ($session['package_id'] ?? '')),
                'approved_at_gmt' => $approved_at_gmt,
                'summary' => [
                    'affected_domains' => [],
                    'affected_options' => [],
                    'decision_count' => count($effective_decisions),
                ],
            ];
        }
        $unsupported_media_domains = self::collect_unsupported_media_apply_domains(array_keys($affected_domains));
        if (! empty($unsupported_media_domains)) {
            return new \WP_Error(
                'dbvc_bricks_portability_media_apply_unsupported',
                sprintf(
                    __('The selected Bricks domains cannot be applied yet because they include media-backed fonts or icons without rollback-safe attachment remapping: %s', 'dbvc'),
                    implode(', ', $unsupported_media_domains)
                ),
                ['status' => 409, 'domains' => $unsupported_media_domains]
            );
        }
        $unsupported_entity_domains = self::collect_unsupported_entity_apply_domains(array_keys($affected_domains));
        if (! empty($unsupported_entity_domains)) {
            return new \WP_Error(
                'dbvc_bricks_portability_entity_apply_unsupported',
                sprintf(
                    __('The selected Bricks domains cannot be applied yet because they include entity-backed objects without rollback-safe apply support: %s', 'dbvc'),
                    implode(', ', $unsupported_entity_domains)
                ),
                ['status' => 409, 'domains' => $unsupported_entity_domains]
            );
        }

        $source_validation = self::validate_session_domain_verification($session, array_keys($affected_domains));
        if (is_wp_error($source_validation)) {
            return $source_validation;
        }

        $target_validation = self::validate_live_target_state($session, array_keys($affected_domains));
        if (is_wp_error($target_validation)) {
            return $target_validation;
        }

        $job_id = self::create_job('bricks_portability_apply', [
            'session_id' => sanitize_key((string) ($session['session_id'] ?? '')),
            'package_id' => sanitize_key((string) ($session['package_id'] ?? '')),
            'progress' => 0,
        ]);

        $mutated_values = [];
        foreach ($affected_domains as $domain_key => $domain_rows) {
            $domain_definition = DBVC_Bricks_Portability_Registry::get_domain($domain_key);
            if (! is_array($domain_definition)) {
                continue;
            }
            if (! empty($domain_definition['media_backed']) || ! empty($domain_definition['entity_backed'])) {
                continue;
            }
            $source_domain = isset($session['source_domains'][$domain_key]) && is_array($session['source_domains'][$domain_key]) ? $session['source_domains'][$domain_key] : [];
            $target_domain = isset($session['target_domains'][$domain_key]) && is_array($session['target_domains'][$domain_key]) ? $session['target_domains'][$domain_key] : [];
            $domain_values = self::build_mutated_domain_option_values($domain_definition, $source_domain, $target_domain, $domain_rows, $effective_decisions);
            if (is_wp_error($domain_values)) {
                self::update_job($job_id, 'failed', ['error' => $domain_values->get_error_code()]);
                return $domain_values;
            }
            $mutated_values = array_merge($mutated_values, $domain_values);
        }

        $affected_option_names = array_values(array_unique(array_merge(
            array_keys($mutated_values),
            DBVC_Bricks_Portability_Media_Apply_Service::get_affected_option_names($affected_domains)
        )));
        $backup = DBVC_Bricks_Portability_Backup_Service::create_backup($session, $affected_option_names, $job_id);
        if (is_wp_error($backup)) {
            self::update_job($job_id, 'failed', ['error' => $backup->get_error_code()]);
            return $backup;
        }

        $media_apply = DBVC_Bricks_Portability_Media_Apply_Service::apply_affected_domains($session, $affected_domains, $effective_decisions);
        if (is_wp_error($media_apply)) {
            DBVC_Bricks_Portability_Backup_Service::restore_backup($backup);
            self::update_job($job_id, 'failed', ['error' => $media_apply->get_error_code()]);
            return $media_apply;
        }

        $media_state = isset($media_apply['media_state']) && is_array($media_apply['media_state']) ? $media_apply['media_state'] : [];
        if (! empty($media_state['created_posts']) || ! empty($media_state['created_attachments']) || ! empty($media_state['reused_attachments'])) {
            $backup = DBVC_Bricks_Portability_Backup_Service::record_media_state($backup, $media_state);
            if (is_wp_error($backup)) {
                DBVC_Bricks_Portability_Backup_Service::restore_media_state($media_state);
                self::update_job($job_id, 'failed', ['error' => $backup->get_error_code()]);
                return $backup;
            }
        }

        $media_mutated_options = isset($media_apply['mutated_options']) && is_array($media_apply['mutated_options']) ? $media_apply['mutated_options'] : [];
        $mutated_values = array_merge($mutated_values, $media_mutated_options);
        $font_value_map = isset($media_apply['font_value_map']) && is_array($media_apply['font_value_map']) ? $media_apply['font_value_map'] : [];
        if (! empty($font_value_map)) {
            $mutated_values = self::remap_custom_font_references_in_options($mutated_values, $font_value_map);
        }

        $template_apply = DBVC_Bricks_Portability_Template_Apply_Service::apply_affected_domains($session, $affected_domains, $effective_decisions, $font_value_map, $media_state);
        if (is_wp_error($template_apply)) {
            DBVC_Bricks_Portability_Backup_Service::restore_media_state($media_state);
            DBVC_Bricks_Portability_Backup_Service::restore_backup($backup);
            self::update_job($job_id, 'failed', ['error' => $template_apply->get_error_code()]);
            return $template_apply;
        }

        $entity_state = isset($template_apply['entity_state']) && is_array($template_apply['entity_state']) ? $template_apply['entity_state'] : [];
        $reference_state = isset($template_apply['reference_state']) && is_array($template_apply['reference_state']) ? $template_apply['reference_state'] : [];
        if (! empty($media_state['created_posts']) || ! empty($media_state['created_attachments']) || ! empty($media_state['reused_attachments'])) {
            $media_backup = DBVC_Bricks_Portability_Backup_Service::record_media_state($backup, $media_state);
            if (is_wp_error($media_backup)) {
                DBVC_Bricks_Portability_Backup_Service::restore_entity_state($entity_state);
                DBVC_Bricks_Portability_Backup_Service::restore_media_state($media_state);
                DBVC_Bricks_Portability_Backup_Service::restore_backup($backup);
                self::update_job($job_id, 'failed', ['error' => $media_backup->get_error_code()]);
                return $media_backup;
            }
            $backup = $media_backup;
        }
        if (! empty($entity_state['created_posts']) || ! empty($entity_state['updated_posts']) || ! empty($entity_state['created_terms'])) {
            $backup_before_entity = $backup;
            $entity_backup = DBVC_Bricks_Portability_Backup_Service::record_entity_state($backup_before_entity, $entity_state);
            if (is_wp_error($entity_backup)) {
                DBVC_Bricks_Portability_Backup_Service::restore_entity_state($entity_state);
                DBVC_Bricks_Portability_Backup_Service::restore_backup($backup_before_entity);
                self::update_job($job_id, 'failed', ['error' => $entity_backup->get_error_code()]);
                return $entity_backup;
            }
            $backup = $entity_backup;
        }

        $reference_receipt = self::build_reference_receipt($affected_domains, $effective_decisions, $media_state, $entity_state, $reference_state);
        if (self::reference_receipt_has_content($reference_receipt)) {
            $receipt_backup = DBVC_Bricks_Portability_Backup_Service::record_reference_receipt($backup, $reference_receipt);
            if (is_wp_error($receipt_backup)) {
                DBVC_Bricks_Portability_Backup_Service::restore_entity_state($entity_state);
                DBVC_Bricks_Portability_Backup_Service::restore_media_state($media_state);
                DBVC_Bricks_Portability_Backup_Service::restore_backup($backup);
                self::update_job($job_id, 'failed', ['error' => $receipt_backup->get_error_code()]);
                return $receipt_backup;
            }
            $backup = $receipt_backup;
        }

        $write_result = self::write_mutated_options($mutated_values);
        if (is_wp_error($write_result)) {
            DBVC_Bricks_Portability_Backup_Service::restore_backup($backup);
            self::update_job($job_id, 'failed', ['error' => $write_result->get_error_code()]);
            return $write_result;
        }

        $persisted_session = DBVC_Bricks_Portability_Package_Service::persist_session_approval($session, $effective_decisions, [
            'approved_at_gmt' => $approved_at_gmt,
            'applied_to_site' => true,
            'backup_id' => sanitize_key((string) ($backup['backup_id'] ?? '')),
            'job_id' => $job_id,
            'manual_row_ids' => $manual_row_ids,
            'reference_receipt' => $reference_receipt,
        ]);
        if (is_wp_error($persisted_session)) {
            DBVC_Bricks_Portability_Backup_Service::restore_backup($backup);
            self::update_job($job_id, 'failed', ['error' => $persisted_session->get_error_code()]);
            return $persisted_session;
        }

        $result = [
            'ok' => true,
            'applied' => true,
            'session_id' => sanitize_key((string) ($session['session_id'] ?? '')),
            'package_id' => sanitize_key((string) ($session['package_id'] ?? '')),
            'backup_id' => sanitize_key((string) ($backup['backup_id'] ?? '')),
            'approved_at_gmt' => $approved_at_gmt,
            'summary' => [
                'affected_domains' => array_keys($affected_domains),
                'affected_options' => $affected_option_names,
                'decision_count' => count(array_filter($effective_decisions, static function ($decision) {
                    return in_array($decision, ['add_incoming', 'replace_with_incoming'], true);
                })),
                'media' => [
                    'created_posts' => count((array) ($media_state['created_posts'] ?? [])),
                    'created_attachments' => count((array) ($media_state['created_attachments'] ?? [])),
                    'reused_attachments' => count((array) ($media_state['reused_attachments'] ?? [])),
                ],
                'entities' => [
                    'created_posts' => count((array) ($entity_state['created_posts'] ?? [])),
                    'updated_posts' => count((array) ($entity_state['updated_posts'] ?? [])),
                    'created_terms' => count((array) ($entity_state['created_terms'] ?? [])),
                ],
                'reference_receipt' => $reference_receipt,
            ],
        ];

        self::update_job($job_id, 'completed', $result);
        self::log_activity(
            'portability_apply_completed',
            'info',
            'Bricks portability apply completed.',
            $result,
            $job_id
        );

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $requested_decisions
     * @return array<string, string>|\WP_Error
     */
    public static function prepare_review_decisions(array $rows, array $requested_decisions)
    {
        $effective = self::resolve_effective_decisions($rows, $requested_decisions);
        $validation = self::validate_decisions($rows, $effective);
        if (is_wp_error($validation)) {
            return $validation;
        }

        return $effective;
    }

    /**
     * @param string $backup_id
     * @return array<string, mixed>|\WP_Error
     */
    public static function rollback_backup($backup_id)
    {
        if (class_exists('DBVC_Bricks_Addon') && DBVC_Bricks_Addon::get_bool_setting('dbvc_bricks_read_only', false)) {
            return new \WP_Error('dbvc_bricks_portability_read_only', __('Bricks portability rollback is disabled while Bricks read-only mode is active.', 'dbvc'), ['status' => 403]);
        }

        $backup = DBVC_Bricks_Portability_Backup_Service::load_backup($backup_id);
        if (is_wp_error($backup)) {
            return $backup;
        }

        $job_id = self::create_job('bricks_portability_rollback', [
            'backup_id' => sanitize_key((string) ($backup['backup_id'] ?? '')),
            'progress' => 0,
        ]);

        $restored = DBVC_Bricks_Portability_Backup_Service::restore_backup($backup);
        if (is_wp_error($restored)) {
            self::update_job($job_id, 'failed', ['error' => $restored->get_error_code()]);
            return $restored;
        }

        $result = [
            'ok' => true,
            'rolled_back' => true,
            'backup_id' => sanitize_key((string) ($backup['backup_id'] ?? '')),
            'session_id' => sanitize_key((string) ($backup['session_id'] ?? '')),
            'option_names' => array_values((array) ($backup['option_names'] ?? [])),
            'reference_receipt' => isset($backup['reference_receipt']) && is_array($backup['reference_receipt']) ? $backup['reference_receipt'] : [],
        ];

        $session_id = sanitize_key((string) ($backup['session_id'] ?? ''));
        if ($session_id !== '') {
            $session_update = DBVC_Bricks_Portability_Package_Service::mark_session_rollback($session_id, [
                'rolled_back_at_gmt' => gmdate('c'),
                'backup_id' => sanitize_key((string) ($backup['backup_id'] ?? '')),
                'job_id' => $job_id,
                'option_names' => array_values((array) ($backup['option_names'] ?? [])),
                'reference_receipt' => isset($backup['reference_receipt']) && is_array($backup['reference_receipt']) ? $backup['reference_receipt'] : [],
            ]);
            if (is_wp_error($session_update)) {
                self::update_job($job_id, 'failed', ['error' => $session_update->get_error_code()]);
                return $session_update;
            }
        }

        self::update_job($job_id, 'completed', $result);
        self::log_activity(
            'portability_rollback_completed',
            'info',
            'Bricks portability rollback completed.',
            $result,
            $job_id
        );

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $requested_decisions
     * @return array<string, string>
     */
    private static function resolve_effective_decisions(array $rows, array $requested_decisions)
    {
        $effective = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $row_id = self::normalize_row_id($row['row_id'] ?? '');
            if ($row_id === '') {
                continue;
            }

            $requested = self::find_requested_decision($requested_decisions, $row_id);
            $allowed = isset($row['available_actions']) && is_array($row['available_actions']) ? $row['available_actions'] : [];
            if ($requested !== '' && in_array($requested, $allowed, true)) {
                $effective[$row_id] = $requested;
                continue;
            }
            $effective[$row_id] = sanitize_key((string) ($row['suggested_action'] ?? 'keep_current'));
        }

        return $effective;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $effective_decisions
     * @return true|\WP_Error
     */
    private static function validate_decisions(array $rows, array $effective_decisions)
    {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $row_id = self::normalize_row_id($row['row_id'] ?? '');
            if ($row_id === '') {
                continue;
            }
            $decision = sanitize_key((string) ($effective_decisions[$row_id] ?? ''));
            $allowed = isset($row['available_actions']) && is_array($row['available_actions']) ? $row['available_actions'] : [];
            if ($decision === '' || ! in_array($decision, $allowed, true)) {
                return new \WP_Error(
                    'dbvc_bricks_portability_decision_invalid',
                    sprintf(__('Invalid Bricks portability decision for row `%s`.', 'dbvc'), $row_id),
                    ['status' => 400]
                );
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $effective_decisions
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function collect_affected_domains(array $rows, array $effective_decisions)
    {
        $affected = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $row_id = self::normalize_row_id($row['row_id'] ?? '');
            $decision = sanitize_key((string) ($effective_decisions[$row_id] ?? ''));
            if (! in_array($decision, ['add_incoming', 'replace_with_incoming'], true)) {
                continue;
            }
            $domain_key = sanitize_key((string) ($row['domain_key'] ?? ''));
            if ($domain_key === '') {
                continue;
            }
            if (! isset($affected[$domain_key])) {
                $affected[$domain_key] = [];
            }
            $affected[$domain_key][] = $row;
        }

        return $affected;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $affected_domains
     * @param array<string, string> $effective_decisions
     * @param array<string, mixed> $media_state
     * @param array<string, mixed> $entity_state
     * @param array<string, mixed> $reference_state
     * @return array<string, mixed>
     */
    private static function build_reference_receipt(array $affected_domains, array $effective_decisions, array $media_state, array $entity_state, array $reference_state)
    {
        $review_summary = self::aggregate_selected_template_reference_summary($affected_domains, $effective_decisions);
        $actual_media_refs = self::count_reference_state_items($reference_state, 'media_refs');
        $actual_nested_refs = self::count_reference_state_items($reference_state, 'nested_template_refs');
        $actual_post_refs = self::count_reference_state_items($reference_state, 'post_refs');
        $actual_term_refs = self::count_reference_state_items($reference_state, 'term_refs');
        $actual_preserved_refs = self::count_reference_state_items($reference_state, 'preserved_refs');
        $actual_blocked_refs = self::count_reference_state_items($reference_state, 'blocked_refs');

        return [
            'template_rows' => (int) ($review_summary['template_rows'] ?? 0),
            'references' => [
                'safe_refs' => (int) ($review_summary['safe_refs'] ?? 0),
                'remapped_refs' => $actual_media_refs + $actual_nested_refs + $actual_post_refs + $actual_term_refs,
                'media_refs' => $actual_media_refs,
                'nested_template_refs' => $actual_nested_refs,
                'entity_refs' => $actual_post_refs + $actual_term_refs,
                'post_refs' => $actual_post_refs,
                'term_refs' => $actual_term_refs,
                'query_refs' => self::count_reference_state_items($reference_state, 'query_refs'),
                'link_refs' => self::count_reference_state_items($reference_state, 'link_refs'),
                'dynamic_data_refs' => self::count_reference_state_items($reference_state, 'dynamic_data_refs'),
                'preserved_refs' => (int) ($review_summary['preserved_refs'] ?? 0) + $actual_preserved_refs,
                'unknown_refs' => (int) ($review_summary['unknown_refs'] ?? 0),
                'blocked_refs' => (int) ($review_summary['blocked_refs'] ?? 0) + $actual_blocked_refs,
            ],
            'media' => [
                'created_posts' => count((array) ($media_state['created_posts'] ?? [])),
                'created_attachments' => count((array) ($media_state['created_attachments'] ?? [])),
                'reused_attachments' => count((array) ($media_state['reused_attachments'] ?? [])),
                'template_attachment_maps' => count((array) ($media_state['template_attachment_id_map'] ?? [])),
                'font_id_maps' => count((array) ($media_state['font_id_map'] ?? [])),
                'font_attachment_maps' => count((array) ($media_state['font_attachment_id_map'] ?? [])),
                'icon_attachment_maps' => count((array) ($media_state['icon_attachment_id_map'] ?? [])),
            ],
            'entities' => [
                'created_posts' => count((array) ($entity_state['created_posts'] ?? [])),
                'updated_posts' => count((array) ($entity_state['updated_posts'] ?? [])),
                'created_terms' => count((array) ($entity_state['created_terms'] ?? [])),
                'template_post_maps' => count(self::build_template_post_id_map($entity_state)),
            ],
            'maps' => [
                'template_posts' => self::build_template_post_id_map($entity_state),
                'template_attachments' => self::sanitize_int_map((array) ($media_state['template_attachment_id_map'] ?? [])),
                'font_ids' => self::sanitize_int_map((array) ($media_state['font_id_map'] ?? [])),
                'font_attachments' => self::sanitize_int_map((array) ($media_state['font_attachment_id_map'] ?? [])),
                'icon_attachments' => self::sanitize_int_map((array) ($media_state['icon_attachment_id_map'] ?? [])),
            ],
            'reference_maps' => self::sanitize_reference_state($reference_state),
        ];
    }

    /**
     * @param array<string, mixed> $receipt
     */
    private static function reference_receipt_has_content(array $receipt)
    {
        if ((int) ($receipt['template_rows'] ?? 0) > 0) {
            return true;
        }
        foreach (['references', 'media', 'entities'] as $section) {
            foreach ((array) ($receipt[$section] ?? []) as $value) {
                if ((int) $value > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $affected_domains
     * @param array<string, string> $effective_decisions
     * @return array<string, int>
     */
    private static function aggregate_selected_template_reference_summary(array $affected_domains, array $effective_decisions)
    {
        $summary = [
            'template_rows' => 0,
            'safe_refs' => 0,
            'preserved_refs' => 0,
            'unknown_refs' => 0,
            'blocked_refs' => 0,
        ];

        foreach ((array) ($affected_domains['bricks_templates'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $row_id = self::normalize_row_id($row['row_id'] ?? '');
            $decision = sanitize_key((string) ($effective_decisions[$row_id] ?? ''));
            if (! in_array($decision, ['add_incoming', 'replace_with_incoming'], true)) {
                continue;
            }
            if (($row['row_type'] ?? '') !== 'object') {
                continue;
            }
            $summary['template_rows']++;
            $reference_summary = isset($row['references']['template_reference_summary']) && is_array($row['references']['template_reference_summary'])
                ? $row['references']['template_reference_summary']
                : [];
            foreach (['safe_refs', 'preserved_refs', 'unknown_refs', 'blocked_refs'] as $key) {
                $summary[$key] += max(0, (int) ($reference_summary[$key] ?? 0));
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $reference_state
     */
    private static function count_reference_state_items(array $reference_state, $bucket)
    {
        $bucket = sanitize_key((string) $bucket);
        return $bucket !== '' ? count((array) ($reference_state[$bucket] ?? [])) : 0;
    }

    /**
     * @param array<string, mixed> $entity_state
     * @return array<string, int>
     */
    private static function build_template_post_id_map(array $entity_state)
    {
        $map = [];
        foreach (array_merge((array) ($entity_state['created_posts'] ?? []), (array) ($entity_state['updated_posts'] ?? [])) as $post_state) {
            if (! is_array($post_state) || sanitize_key((string) ($post_state['post_type'] ?? '')) !== 'bricks_template') {
                continue;
            }
            $source_id = (int) ($post_state['source_post_id'] ?? 0);
            $target_id = (int) ($post_state['post_id'] ?? 0);
            if ($source_id > 0 && $target_id > 0) {
                $map[(string) $source_id] = $target_id;
            }
        }

        ksort($map, SORT_NATURAL);
        return $map;
    }

    /**
     * @param array<mixed> $map
     * @return array<string, int>
     */
    private static function sanitize_int_map(array $map)
    {
        $sanitized = [];
        foreach ($map as $source_id => $target_id) {
            $source_id = (int) $source_id;
            $target_id = (int) $target_id;
            if ($source_id > 0 && $target_id > 0) {
                $sanitized[(string) $source_id] = $target_id;
            }
        }

        ksort($sanitized, SORT_NATURAL);
        return $sanitized;
    }

    /**
     * @param array<string, mixed> $reference_state
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function sanitize_reference_state(array $reference_state)
    {
        $buckets = DBVC_Bricks_Portability_Template_Apply_Service::empty_reference_state();
        foreach (array_keys($buckets) as $bucket) {
            $items = [];
            foreach ((array) ($reference_state[$bucket] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $items[] = [
                    'source_id' => max(0, (int) ($item['source_id'] ?? 0)),
                    'target_id' => max(0, (int) ($item['target_id'] ?? 0)),
                    'payload_path' => sanitize_text_field((string) ($item['payload_path'] ?? '')),
                    'control_name' => sanitize_key((string) ($item['control_name'] ?? '')),
                    'ref_type' => sanitize_key((string) ($item['ref_type'] ?? '')),
                    'entity_kind' => sanitize_key((string) ($item['entity_kind'] ?? '')),
                    'object_subtype' => sanitize_key((string) ($item['object_subtype'] ?? '')),
                    'query_ref_kind' => sanitize_key((string) ($item['query_ref_kind'] ?? '')),
                    'link_ref_kind' => sanitize_key((string) ($item['link_ref_kind'] ?? '')),
                    'dynamic_ref_kind' => sanitize_key((string) ($item['dynamic_ref_kind'] ?? '')),
                    'dynamic_token_name' => sanitize_key((string) ($item['dynamic_token_name'] ?? '')),
                ];
            }
            $buckets[$bucket] = $items;
        }

        return $buckets;
    }

    /**
     * @param array<int, string> $domain_keys
     * @return array<int, string>
     */
    private static function collect_unsupported_media_apply_domains(array $domain_keys)
    {
        $blocked = [];
        foreach ($domain_keys as $domain_key) {
            $domain_key = sanitize_key((string) $domain_key);
            $definition = DBVC_Bricks_Portability_Registry::get_domain($domain_key);
            if (! is_array($definition)) {
                continue;
            }
            if (! empty($definition['media_backed']) && empty($definition['media_apply_supported'])) {
                $blocked[] = $domain_key;
            }
        }

        return array_values(array_unique($blocked));
    }

    /**
     * @param array<int, string> $domain_keys
     * @return array<int, string>
     */
    private static function collect_unsupported_entity_apply_domains(array $domain_keys)
    {
        $blocked = [];
        foreach ($domain_keys as $domain_key) {
            $domain_key = sanitize_key((string) $domain_key);
            $definition = DBVC_Bricks_Portability_Registry::get_domain($domain_key);
            if (! is_array($definition)) {
                continue;
            }
            if (! empty($definition['entity_backed']) && empty($definition['entity_apply_supported'])) {
                $blocked[] = $domain_key;
            }
        }

        return array_values(array_unique($blocked));
    }

    /**
     * @param array<string, mixed> $session
     * @param array<int, string> $domain_keys
     * @return true|\WP_Error
     */
    private static function validate_live_target_state(array $session, array $domain_keys)
    {
        foreach ($domain_keys as $domain_key) {
            $definition = DBVC_Bricks_Portability_Registry::get_domain($domain_key);
            if (! is_array($definition)) {
                continue;
            }
            $live = DBVC_Bricks_Portability_Normalizer::normalize_live_domain($definition);
            $expected = isset($session['target_domains'][$domain_key]) && is_array($session['target_domains'][$domain_key])
                ? $session['target_domains'][$domain_key]
                : [];
            $live_fp = sanitize_text_field((string) ($live['domain_fingerprint'] ?? ''));
            $expected_fp = sanitize_text_field((string) ($expected['domain_fingerprint'] ?? ''));
            $verification = isset($live['verification']) && is_array($live['verification']) ? $live['verification'] : [];
            if (DBVC_Bricks_Portability_Domain_Verifier::blocks_apply($verification)) {
                return new \WP_Error(
                    'dbvc_bricks_portability_target_verification_failed',
                    sprintf(
                        __('The target Bricks domain `%1$s` cannot be applied because its current storage shape could not be verified safely. %2$s', 'dbvc'),
                        $domain_key,
                        sanitize_text_field((string) (($verification['warnings'][0] ?? '')))
                    ),
                    ['status' => 409]
                );
            }
            if ($expected_fp !== '' && $live_fp !== '' && ! hash_equals($expected_fp, $live_fp)) {
                return new \WP_Error(
                    'dbvc_bricks_portability_target_changed',
                    sprintf(__('The target Bricks domain `%s` changed after review. Re-import the package before apply.', 'dbvc'), $domain_key),
                    ['status' => 409]
                );
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $session
     * @param array<int, string> $domain_keys
     * @return true|\WP_Error
     */
    private static function validate_session_domain_verification(array $session, array $domain_keys)
    {
        foreach ($domain_keys as $domain_key) {
            $source_domain = isset($session['source_domains'][$domain_key]) && is_array($session['source_domains'][$domain_key])
                ? $session['source_domains'][$domain_key]
                : [];
            $verification = isset($source_domain['verification']) && is_array($source_domain['verification']) ? $source_domain['verification'] : [];
            if (! DBVC_Bricks_Portability_Domain_Verifier::blocks_apply($verification)) {
                continue;
            }

            return new \WP_Error(
                'dbvc_bricks_portability_source_verification_failed',
                sprintf(
                    __('The imported Bricks domain `%1$s` cannot be applied because the package shape could not be verified safely. %2$s', 'dbvc'),
                    $domain_key,
                    sanitize_text_field((string) (($verification['warnings'][0] ?? '')))
                ),
                ['status' => 400]
            );
        }

        return true;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $source_domain
     * @param array<string, mixed> $target_domain
     * @param array<int, array<string, mixed>> $domain_rows
     * @param array<string, string> $effective_decisions
     * @return array<string, mixed>|\WP_Error
     */
    private static function build_mutated_domain_option_values(array $definition, array $source_domain, array $target_domain, array $domain_rows, array $effective_decisions)
    {
        $domain_key = sanitize_key((string) ($definition['domain_key'] ?? ''));
        $primary_option = sanitize_key((string) ($definition['primary_option'] ?? ''));
        $mode = sanitize_key((string) ($definition['mode'] ?? 'singleton'));

        $mutated = [];
        if ($mode === 'singleton') {
            foreach ($domain_rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $decision = sanitize_key((string) ($effective_decisions[self::normalize_row_id($row['row_id'] ?? '')] ?? ''));
                if ($decision !== 'replace_with_incoming') {
                    continue;
                }
                if (($row['row_type'] ?? '') === 'meta') {
                    $mutated[sanitize_key((string) ($row['object_id'] ?? ''))] = $row['source']['raw'] ?? null;
                    continue;
                }
                $mutated[$primary_option] = $source_domain['option_values'][$primary_option] ?? null;
            }

            return $mutated;
        }

        $target_objects = isset($target_domain['objects']) && is_array($target_domain['objects']) ? $target_domain['objects'] : [];
        $source_objects = isset($source_domain['objects']) && is_array($source_domain['objects']) ? $source_domain['objects'] : [];
        $transport = isset($target_domain['transport']) && is_array($target_domain['transport']) ? $target_domain['transport'] : (isset($source_domain['transport']) && is_array($source_domain['transport']) ? $source_domain['transport'] : ['shape' => 'singleton', 'path' => []]);

        if (($transport['shape'] ?? 'singleton') === 'singleton') {
            $mutated[$primary_option] = $source_domain['option_values'][$primary_option] ?? null;
        } else {
            $row_map = [];
            foreach ($domain_rows as $row) {
                if (! is_array($row) || ($row['row_type'] ?? '') !== 'object') {
                    continue;
                }
                $target_index = isset($row['target']['source_index']) ? (int) $row['target']['source_index'] : -1;
                if ($target_index >= 0) {
                    $row_map[$target_index] = $row;
                }
            }

            $final_entries = [];
            foreach ($target_objects as $target_index => $target_object) {
                if (! is_array($target_object)) {
                    continue;
                }
                $row = $row_map[(int) $target_index] ?? null;
                if (is_array($row)) {
                    $row_id = self::normalize_row_id($row['row_id'] ?? '');
                    $decision = sanitize_key((string) ($effective_decisions[$row_id] ?? 'keep_current'));
                    if ($decision === 'replace_with_incoming' && isset($row['source']) && is_array($row['source'])) {
                        $final_entries[] = [
                            'map_key' => sanitize_text_field((string) ($target_object['map_key'] ?? $row['source']['map_key'] ?? '')),
                            'raw' => $row['source']['raw'] ?? null,
                        ];
                        continue;
                    }
                }
                $final_entries[] = [
                    'map_key' => sanitize_text_field((string) ($target_object['map_key'] ?? '')),
                    'raw' => $target_object['raw'] ?? null,
                ];
            }

            foreach ($domain_rows as $row) {
                if (! is_array($row) || ($row['row_type'] ?? '') !== 'object' || empty($row['source']) || ! is_array($row['source'])) {
                    continue;
                }
                $row_id = self::normalize_row_id($row['row_id'] ?? '');
                $decision = sanitize_key((string) ($effective_decisions[$row_id] ?? 'keep_current'));
                if ($decision !== 'add_incoming') {
                    continue;
                }
                if (($row['status'] ?? '') !== 'new_in_source') {
                    continue;
                }
                $final_entries[] = [
                    'map_key' => sanitize_text_field((string) ($row['source']['map_key'] ?? $row['source']['object_id'] ?? '')),
                    'raw' => $row['source']['raw'] ?? null,
                ];
            }

            $base_raw = $target_domain['option_values'][$primary_option] ?? ($source_domain['option_values'][$primary_option] ?? []);
            $mutated[$primary_option] = self::inject_objects_into_primary_option($base_raw, $final_entries, $transport);
        }

        foreach ($domain_rows as $row) {
            if (! is_array($row) || ($row['row_type'] ?? '') !== 'meta') {
                continue;
            }
            $row_id = self::normalize_row_id($row['row_id'] ?? '');
            $decision = sanitize_key((string) ($effective_decisions[$row_id] ?? 'keep_current'));
            if ($decision !== 'replace_with_incoming') {
                continue;
            }
            $option_name = sanitize_key((string) ($row['object_id'] ?? ''));
            if ($option_name === '') {
                continue;
            }
            $mutated[$option_name] = $row['source']['raw'] ?? null;
        }

        return $mutated;
    }

    /**
     * @param mixed $base_raw
     * @param array<int, array<string, mixed>> $final_entries
     * @param array<string, mixed> $transport
     * @return mixed
     */
    private static function inject_objects_into_primary_option($base_raw, array $final_entries, array $transport)
    {
        $shape = sanitize_key((string) ($transport['shape'] ?? 'list'));
        $path = DBVC_Bricks_Portability_Utils::sanitize_path_segments((array) ($transport['path'] ?? []));
        $wrapper_shape = sanitize_key((string) ($transport['wrapper_shape'] ?? 'root'));

        $container = [];
        if ($shape === 'map') {
            foreach ($final_entries as $index => $entry) {
                $map_key = sanitize_text_field((string) ($entry['map_key'] ?? ''));
                if ($map_key === '') {
                    $map_key = 'item_' . $index;
                }
                $container[$map_key] = $entry['raw'] ?? null;
            }
        } else {
            foreach ($final_entries as $entry) {
                $container[] = $entry['raw'] ?? null;
            }
        }

        if ($wrapper_shape !== 'object' || empty($path)) {
            return $container;
        }

        $result = is_array($base_raw) ? $base_raw : [];
        $ref = &$result;
        foreach ($path as $index => $segment) {
            $is_last = $index === (count($path) - 1);
            if ($is_last) {
                $ref[$segment] = $container;
                continue;
            }
            if (! isset($ref[$segment]) || ! is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $mutated_values
     * @return true|\WP_Error
     */
    private static function write_mutated_options(array $mutated_values)
    {
        foreach ($mutated_values as $option_name => $value) {
            $option_name = sanitize_key((string) $option_name);
            if ($option_name === '') {
                continue;
            }
            update_option($option_name, $value);
            $current = get_option($option_name, null);
            if (DBVC_Bricks_Portability_Utils::fingerprint($current) !== DBVC_Bricks_Portability_Utils::fingerprint($value)) {
                return new \WP_Error(
                    'dbvc_bricks_portability_write_verify_failed',
                    sprintf(__('Failed to verify written Bricks option `%s`.', 'dbvc'), $option_name),
                    ['status' => 500]
                );
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $mutated_values
     * @param array<string, string> $font_value_map
     * @return array<string, mixed>
     */
    private static function remap_custom_font_references_in_options(array $mutated_values, array $font_value_map)
    {
        foreach ($mutated_values as $option_name => $value) {
            $option_name = sanitize_key((string) $option_name);
            if ($option_name === 'bricks_font_face_rules') {
                continue;
            }
            $mutated_values[$option_name] = self::remap_custom_font_references($value, $font_value_map);
        }

        return $mutated_values;
    }

    /**
     * @param mixed $value
     * @param array<string, string> $font_value_map
     * @return mixed
     */
    private static function remap_custom_font_references($value, array $font_value_map)
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = self::remap_custom_font_references($child, $font_value_map);
            }
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return $value;
        }

        return preg_replace_callback('/custom_font_\d+/', static function ($matches) use ($font_value_map) {
            $token = (string) ($matches[0] ?? '');
            return isset($font_value_map[$token]) ? (string) $font_value_map[$token] : $token;
        }, $value);
    }

    /**
     * @param string $type
     * @param array<string, mixed> $context
     * @return int
     */
    private static function create_job($type, array $context = [])
    {
        if (! class_exists('DBVC_Database') || ! method_exists('DBVC_Database', 'create_job')) {
            return 0;
        }

        return (int) DBVC_Database::create_job($type, $context, 'running');
    }

    /**
     * @param int $job_id
     * @param string $status
     * @param array<string, mixed> $context
     * @return void
     */
    private static function update_job($job_id, $status, array $context = [])
    {
        if (! class_exists('DBVC_Database') || ! method_exists('DBVC_Database', 'update_job')) {
            return;
        }

        DBVC_Database::update_job((int) $job_id, ['status' => sanitize_key((string) $status), 'progress' => 1], $context);
    }

    /**
     * @param string $event
     * @param string $severity
     * @param string $message
     * @param array<string, mixed> $context
     * @param int $job_id
     * @return void
     */
    private static function log_activity($event, $severity, $message, array $context = [], $job_id = 0)
    {
        do_action('dbvc_bricks_audit_event', $event, $context);
        if (! class_exists('DBVC_Database') || ! method_exists('DBVC_Database', 'log_activity')) {
            return;
        }

        DBVC_Database::log_activity(
            'dbvc_bricks_' . sanitize_key((string) $event),
            sanitize_key((string) $severity),
            sanitize_text_field((string) $message),
            $context,
            ['job_id' => (int) $job_id]
        );
    }

    /**
     * @param array<string, string> $requested_decisions
     * @param string $row_id
     * @return string
     */
    private static function find_requested_decision(array $requested_decisions, $row_id)
    {
        $row_id = self::normalize_row_id($row_id);
        if ($row_id === '') {
            return '';
        }

        if (isset($requested_decisions[$row_id])) {
            return sanitize_key((string) $requested_decisions[$row_id]);
        }

        $legacy_key = sanitize_key($row_id);
        if ($legacy_key !== '' && isset($requested_decisions[$legacy_key])) {
            return sanitize_key((string) $requested_decisions[$legacy_key]);
        }

        return '';
    }

    /**
     * @param mixed $row_id
     * @return string
     */
    private static function normalize_row_id($row_id)
    {
        return trim(sanitize_text_field((string) $row_id));
    }
}
