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
    public static function apply_session($session_id, array $requested_decisions)
    {
        if (class_exists('DBVC_Bricks_Addon') && DBVC_Bricks_Addon::get_bool_setting('dbvc_bricks_read_only', false)) {
            return new \WP_Error('dbvc_bricks_portability_read_only', __('Bricks portability apply is disabled while Bricks read-only mode is active.', 'dbvc'), ['status' => 403]);
        }

        $session = DBVC_Bricks_Portability_Package_Service::load_session($session_id);
        if (is_wp_error($session)) {
            return $session;
        }

        $rows = isset($session['rows']) && is_array($session['rows']) ? $session['rows'] : [];
        $effective_decisions = self::resolve_effective_decisions($rows, $requested_decisions);
        $validation = self::validate_decisions($rows, $effective_decisions);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $affected_domains = self::collect_affected_domains($rows, $effective_decisions);
        if (empty($affected_domains)) {
            return [
                'ok' => true,
                'applied' => false,
                'message' => __('No incoming changes were selected for apply.', 'dbvc'),
                'summary' => [
                    'affected_domains' => [],
                    'affected_options' => [],
                ],
            ];
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
            $source_domain = isset($session['source_domains'][$domain_key]) && is_array($session['source_domains'][$domain_key]) ? $session['source_domains'][$domain_key] : [];
            $target_domain = isset($session['target_domains'][$domain_key]) && is_array($session['target_domains'][$domain_key]) ? $session['target_domains'][$domain_key] : [];
            $domain_values = self::build_mutated_domain_option_values($domain_definition, $source_domain, $target_domain, $domain_rows, $effective_decisions);
            if (is_wp_error($domain_values)) {
                self::update_job($job_id, 'failed', ['error' => $domain_values->get_error_code()]);
                return $domain_values;
            }
            $mutated_values = array_merge($mutated_values, $domain_values);
        }

        $affected_option_names = array_keys($mutated_values);
        $backup = DBVC_Bricks_Portability_Backup_Service::create_backup($session, $affected_option_names, $job_id);
        if (is_wp_error($backup)) {
            self::update_job($job_id, 'failed', ['error' => $backup->get_error_code()]);
            return $backup;
        }

        $write_result = self::write_mutated_options($mutated_values);
        if (is_wp_error($write_result)) {
            DBVC_Bricks_Portability_Backup_Service::restore_backup($backup);
            self::update_job($job_id, 'failed', ['error' => $write_result->get_error_code()]);
            return $write_result;
        }

        $result = [
            'ok' => true,
            'applied' => true,
            'session_id' => sanitize_key((string) ($session['session_id'] ?? '')),
            'package_id' => sanitize_key((string) ($session['package_id'] ?? '')),
            'backup_id' => sanitize_key((string) ($backup['backup_id'] ?? '')),
            'summary' => [
                'affected_domains' => array_keys($affected_domains),
                'affected_options' => $affected_option_names,
                'decision_count' => count(array_filter($effective_decisions, static function ($decision) {
                    return in_array($decision, ['add_incoming', 'replace_with_incoming'], true);
                })),
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
            'option_names' => array_values((array) ($backup['option_names'] ?? [])),
        ];

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
            $row_id = sanitize_key((string) ($row['row_id'] ?? ''));
            if ($row_id === '') {
                continue;
            }

            $requested = isset($requested_decisions[$row_id]) ? sanitize_key((string) $requested_decisions[$row_id]) : '';
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
            $row_id = sanitize_key((string) ($row['row_id'] ?? ''));
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
            $row_id = sanitize_key((string) ($row['row_id'] ?? ''));
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
                $decision = sanitize_key((string) ($effective_decisions[sanitize_key((string) ($row['row_id'] ?? ''))] ?? ''));
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
                    $row_id = sanitize_key((string) ($row['row_id'] ?? ''));
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
                $row_id = sanitize_key((string) ($row['row_id'] ?? ''));
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
            $row_id = sanitize_key((string) ($row['row_id'] ?? ''));
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
}
