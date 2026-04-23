<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Portability_Diff_Engine
{
    /**
     * @param array<string, mixed> $manifest
     * @param array<string, array<string, mixed>> $source_domains
     * @param array<string, array<string, mixed>> $target_domains
     * @return array<string, mixed>
     */
    public static function build_review_session(array $manifest, array $source_domains, array $target_domains)
    {
        $selected_domains = isset($manifest['selected_domains']) && is_array($manifest['selected_domains'])
            ? array_values(array_map('sanitize_key', $manifest['selected_domains']))
            : [];

        $source_lookup = self::build_reference_lookup($source_domains);
        $target_lookup = self::build_reference_lookup($target_domains);

        $rows = [];
        $domain_summaries = [];
        foreach ($selected_domains as $domain_key) {
            $definition = DBVC_Bricks_Portability_Registry::get_domain($domain_key);
            if (! is_array($definition)) {
                continue;
            }
            $source_domain = isset($source_domains[$domain_key]) && is_array($source_domains[$domain_key]) ? $source_domains[$domain_key] : [];
            $target_domain = isset($target_domains[$domain_key]) && is_array($target_domains[$domain_key]) ? $target_domains[$domain_key] : [];
            $domain_result = self::diff_domain($definition, $source_domain, $target_domain, $source_lookup, $target_lookup);
            $rows = array_merge($rows, $domain_result['rows']);
            $domain_summaries[] = $domain_result['summary'];
        }

        return [
            'rows' => array_values($rows),
            'domain_summaries' => $domain_summaries,
            'summary' => self::summarize_rows($rows),
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $source_domain
     * @param array<string, mixed> $target_domain
     * @param array<string, mixed> $source_lookup
     * @param array<string, mixed> $target_lookup
     * @return array<string, mixed>
     */
    private static function diff_domain(array $definition, array $source_domain, array $target_domain, array $source_lookup, array $target_lookup)
    {
        $domain_key = sanitize_key((string) ($definition['domain_key'] ?? ''));
        $rows = [];

        if (($definition['mode'] ?? '') === 'singleton') {
            $rows[] = self::diff_singleton_row($definition, $source_domain, $target_domain, $target_lookup);
        } else {
            $rows = array_merge(
                $rows,
                self::diff_collection_rows($definition, $source_domain, $target_domain, $target_lookup)
            );
        }

        foreach ((array) ($source_domain['metadata_rows'] ?? []) as $meta_row) {
            $rows[] = self::diff_metadata_row($definition, $meta_row, self::find_metadata_target($target_domain, (string) ($meta_row['option_name'] ?? '')));
        }

        $summary = self::summarize_rows($rows);
        $summary['domain_key'] = $domain_key;
        $summary['domain_label'] = sanitize_text_field((string) ($definition['label'] ?? $domain_key));
        $summary['domain_status'] = self::build_domain_status($summary);
        $summary['source_fingerprint'] = (string) ($source_domain['domain_fingerprint'] ?? '');
        $summary['target_fingerprint'] = (string) ($target_domain['domain_fingerprint'] ?? '');

        return [
            'rows' => $rows,
            'summary' => $summary,
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $source_domain
     * @param array<string, mixed> $target_domain
     * @param array<string, mixed> $target_lookup
     * @return array<string, mixed>
     */
    private static function diff_singleton_row(array $definition, array $source_domain, array $target_domain, array $target_lookup)
    {
        $domain_key = sanitize_key((string) ($definition['domain_key'] ?? ''));
        $source_object = isset($source_domain['objects'][0]) && is_array($source_domain['objects'][0]) ? $source_domain['objects'][0] : null;
        $target_object = isset($target_domain['objects'][0]) && is_array($target_domain['objects'][0]) ? $target_domain['objects'][0] : null;

        $source_normalized = is_array($source_object) ? ($source_object['normalized'] ?? null) : null;
        $target_normalized = is_array($target_object) ? ($target_object['normalized'] ?? null) : null;
        $path_summary = DBVC_Bricks_Portability_Utils::diff_paths($target_normalized, $source_normalized);
        $status = self::classify_value_change($path_summary);
        if ($source_object !== null && $target_object !== null && (($source_object['fingerprint'] ?? '') === ($target_object['fingerprint'] ?? ''))) {
            $status = 'identical';
        }

        $warnings = [];
        if (! empty($definition['high_risk'])) {
            $warnings[] = 'This domain is high risk and should be reviewed carefully before apply.';
        }
        if ($domain_key === 'breakpoints') {
            $warnings[] = 'Breakpoints storage should be verified against the active Bricks runtime before rollout.';
        }

        return [
            'row_id' => $domain_key . '::root',
            'domain_key' => $domain_key,
            'domain_label' => sanitize_text_field((string) ($definition['label'] ?? $domain_key)),
            'row_type' => 'domain',
            'object_label' => sanitize_text_field((string) ($definition['label'] ?? $domain_key)),
            'object_id' => sanitize_key((string) ($definition['primary_option'] ?? '__root__')),
            'match_status' => 'singleton',
            'status' => $status,
            'warnings' => $warnings,
            'path_summary' => $path_summary,
            'suggested_action' => self::suggest_action($status, $domain_key),
            'available_actions' => self::actions_for_row($domain_key, $status, 'domain'),
            'source' => is_array($source_object) ? $source_object : null,
            'target' => is_array($target_object) ? $target_object : null,
            'references' => self::build_references(is_array($source_object) ? ($source_object['references'] ?? []) : [], $target_lookup),
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $source_domain
     * @param array<string, mixed> $target_domain
     * @param array<string, mixed> $target_lookup
     * @return array<int, array<string, mixed>>
     */
    private static function diff_collection_rows(array $definition, array $source_domain, array $target_domain, array $target_lookup)
    {
        $domain_key = sanitize_key((string) ($definition['domain_key'] ?? ''));
        $source_objects = isset($source_domain['objects']) && is_array($source_domain['objects']) ? $source_domain['objects'] : [];
        $target_objects = isset($target_domain['objects']) && is_array($target_domain['objects']) ? $target_domain['objects'] : [];
        $match_order = isset($definition['match_order']) && is_array($definition['match_order']) ? $definition['match_order'] : ['name', 'id'];

        $target_indexes = self::build_target_indexes($target_objects);
        $matched_targets = [];
        $rows = [];

        foreach ($source_objects as $source_object) {
            if (! is_array($source_object)) {
                continue;
            }

            $match = self::find_target_match($source_object, $target_indexes, $match_order, $matched_targets);
            if (is_array($match) && (isset($match['target_index']) || array_key_exists('target_index', $match))) {
                $target_object = $target_objects[(int) $match['target_index']] ?? null;
                if (! is_array($target_object)) {
                    $match = null;
                }
            }

            if (! is_array($match) || ! isset($match['target_index'])) {
                $rows[] = [
                    'row_id' => $domain_key . '::source::' . sanitize_key((string) ($source_object['source_key'] ?? '')),
                    'domain_key' => $domain_key,
                    'domain_label' => sanitize_text_field((string) ($definition['label'] ?? $domain_key)),
                    'row_type' => 'object',
                    'object_label' => sanitize_text_field((string) ($source_object['display_name'] ?? 'Object')),
                    'object_id' => sanitize_text_field((string) ($source_object['object_id'] ?? '')),
                    'match_status' => 'unmatched',
                    'status' => 'new_in_source',
                    'warnings' => self::build_reference_warnings($source_object, $target_lookup),
                    'path_summary' => [
                        'changed' => [],
                        'added' => [],
                        'removed' => [],
                    ],
                    'suggested_action' => self::suggest_action('new_in_source', $domain_key),
                    'available_actions' => self::actions_for_row($domain_key, 'new_in_source', 'object'),
                    'source' => $source_object,
                    'target' => null,
                    'references' => self::build_references((array) ($source_object['references'] ?? []), $target_lookup),
                ];
                continue;
            }

            $target_index = (int) $match['target_index'];
            $matched_targets[$target_index] = true;
            $target_object = $target_objects[$target_index];

            $status = self::classify_matched_status($source_object, $target_object, (string) ($match['matched_by'] ?? ''));
            $path_summary = DBVC_Bricks_Portability_Utils::diff_paths($target_object['normalized'] ?? null, $source_object['normalized'] ?? null);
            $warnings = array_merge(
                (array) ($source_object['warnings'] ?? []),
                self::build_reference_warnings($source_object, $target_lookup)
            );

            $rows[] = [
                'row_id' => $domain_key . '::match::' . sanitize_key((string) ($source_object['source_key'] ?? '')),
                'domain_key' => $domain_key,
                'domain_label' => sanitize_text_field((string) ($definition['label'] ?? $domain_key)),
                'row_type' => 'object',
                'object_label' => sanitize_text_field((string) ($source_object['display_name'] ?? 'Object')),
                'object_id' => sanitize_text_field((string) ($source_object['object_id'] ?? '')),
                'match_status' => sanitize_key((string) ($match['matched_by'] ?? 'unknown')),
                'status' => $status,
                'warnings' => $warnings,
                'path_summary' => $path_summary,
                'suggested_action' => self::suggest_action($status, $domain_key),
                'available_actions' => self::actions_for_row($domain_key, $status, 'object'),
                'source' => $source_object,
                'target' => $target_object,
                'references' => self::build_references((array) ($source_object['references'] ?? []), $target_lookup),
            ];
        }

        foreach ($target_objects as $target_index => $target_object) {
            if (! is_array($target_object) || isset($matched_targets[$target_index])) {
                continue;
            }

            $rows[] = [
                'row_id' => $domain_key . '::target::' . sanitize_key((string) ($target_object['source_key'] ?? ('target-' . $target_index))),
                'domain_key' => $domain_key,
                'domain_label' => sanitize_text_field((string) ($definition['label'] ?? $domain_key)),
                'row_type' => 'object',
                'object_label' => sanitize_text_field((string) ($target_object['display_name'] ?? 'Object')),
                'object_id' => sanitize_text_field((string) ($target_object['object_id'] ?? '')),
                'match_status' => 'target_only',
                'status' => 'missing_from_source',
                'warnings' => [],
                'path_summary' => [
                    'changed' => [],
                    'added' => [],
                    'removed' => [],
                ],
                'suggested_action' => self::suggest_action('missing_from_source', $domain_key),
                'available_actions' => self::actions_for_row($domain_key, 'missing_from_source', 'object'),
                'source' => null,
                'target' => $target_object,
                'references' => self::build_references((array) ($target_object['references'] ?? []), $target_lookup),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $source_meta
     * @param array<string, mixed>|null $target_meta
     * @return array<string, mixed>
     */
    private static function diff_metadata_row(array $definition, array $source_meta, $target_meta)
    {
        $domain_key = sanitize_key((string) ($definition['domain_key'] ?? ''));
        $source_normalized = $source_meta['normalized'] ?? null;
        $target_normalized = is_array($target_meta) ? ($target_meta['normalized'] ?? null) : null;
        $path_summary = DBVC_Bricks_Portability_Utils::diff_paths($target_normalized, $source_normalized);
        $status = self::classify_value_change($path_summary);
        if (is_array($target_meta) && (($source_meta['fingerprint'] ?? '') === ($target_meta['fingerprint'] ?? ''))) {
            $status = 'identical';
        }
        if (! is_array($target_meta)) {
            $status = 'new_in_source';
        }

        return [
            'row_id' => sanitize_key((string) ($source_meta['row_id'] ?? $domain_key . '::meta')),
            'domain_key' => $domain_key,
            'domain_label' => sanitize_text_field((string) ($definition['label'] ?? $domain_key)),
            'row_type' => 'meta',
            'object_label' => sanitize_text_field((string) ($source_meta['display_name'] ?? 'Metadata')),
            'object_id' => sanitize_key((string) ($source_meta['option_name'] ?? 'meta')),
            'match_status' => is_array($target_meta) ? 'option_name' : 'unmatched',
            'status' => $status,
            'warnings' => [],
            'path_summary' => $path_summary,
            'suggested_action' => self::suggest_action($status, $domain_key),
            'available_actions' => self::actions_for_row($domain_key, $status, 'meta'),
            'source' => $source_meta,
            'target' => $target_meta,
            'references' => [
                'css_variables' => [],
                'class_names' => [],
                'missing_dependencies' => [],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function summarize_rows(array $rows)
    {
        $summary = [
            'total_rows' => 0,
            'actionable_rows' => 0,
            'warning_rows' => 0,
            'statuses' => [],
        ];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $status = sanitize_key((string) ($row['status'] ?? 'unknown'));
            if ($status === '') {
                $status = 'unknown';
            }

            $summary['total_rows']++;
            if ($status !== 'identical' && $status !== 'missing_from_source') {
                $summary['actionable_rows']++;
            }
            if (! empty($row['warnings'])) {
                $summary['warning_rows']++;
            }
            if (! isset($summary['statuses'][$status])) {
                $summary['statuses'][$status] = 0;
            }
            $summary['statuses'][$status]++;
        }

        ksort($summary['statuses'], SORT_STRING);
        return $summary;
    }

    /**
     * @param array<string, mixed> $summary
     * @return string
     */
    private static function build_domain_status(array $summary)
    {
        $statuses = isset($summary['statuses']) && is_array($summary['statuses']) ? $summary['statuses'] : [];
        if (empty($statuses) || (count($statuses) === 1 && isset($statuses['identical']))) {
            return 'clean';
        }
        if (! empty($statuses['conflict'])) {
            return 'has_conflicts';
        }
        if ((int) ($summary['warning_rows'] ?? 0) > 0) {
            return 'requires_attention';
        }
        return 'has_drift';
    }

    /**
     * @param array<string, mixed>|null $target_domain
     * @param string $option_name
     * @return array<string, mixed>|null
     */
    private static function find_metadata_target($target_domain, $option_name)
    {
        if (! is_array($target_domain)) {
            return null;
        }

        foreach ((array) ($target_domain['metadata_rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (($row['option_name'] ?? '') === $option_name) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $target_objects
     * @return array<string, array<string, array<int, int>>>
     */
    private static function build_target_indexes(array $target_objects)
    {
        $indexes = [
            'id' => [],
            'name' => [],
            'slug' => [],
            'token' => [],
            'selector' => [],
        ];

        foreach ($target_objects as $index => $object) {
            if (! is_array($object)) {
                continue;
            }
            $match_keys = isset($object['match_keys']) && is_array($object['match_keys']) ? $object['match_keys'] : [];
            foreach ($indexes as $key => $bucket) {
                unset($bucket);
                $value = isset($match_keys[$key]) ? sanitize_text_field((string) $match_keys[$key]) : '';
                if ($value === '') {
                    continue;
                }
                if (! isset($indexes[$key][$value])) {
                    $indexes[$key][$value] = [];
                }
                $indexes[$key][$value][] = (int) $index;
            }
        }

        return $indexes;
    }

    /**
     * @param array<string, mixed> $source_object
     * @param array<string, array<string, array<int, int>>> $target_indexes
     * @param array<int, string> $match_order
     * @param array<int, bool> $matched_targets
     * @return array<string, mixed>|null
     */
    private static function find_target_match(array $source_object, array $target_indexes, array $match_order, array $matched_targets)
    {
        $match_keys = isset($source_object['match_keys']) && is_array($source_object['match_keys']) ? $source_object['match_keys'] : [];
        foreach ($match_order as $match_key) {
            $match_key = sanitize_key((string) $match_key);
            if ($match_key === '' || ! isset($target_indexes[$match_key])) {
                continue;
            }
            $value = isset($match_keys[$match_key]) ? sanitize_text_field((string) $match_keys[$match_key]) : '';
            if ($value === '' || empty($target_indexes[$match_key][$value])) {
                continue;
            }

            foreach ($target_indexes[$match_key][$value] as $target_index) {
                if (isset($matched_targets[(int) $target_index])) {
                    continue;
                }
                return [
                    'matched_by' => $match_key,
                    'target_index' => (int) $target_index,
                ];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $source_object
     * @param array<string, mixed> $target_object
     * @param string $matched_by
     * @return string
     */
    private static function classify_matched_status(array $source_object, array $target_object, $matched_by)
    {
        $source_fp = (string) ($source_object['fingerprint'] ?? '');
        $target_fp = (string) ($target_object['fingerprint'] ?? '');
        if ($source_fp !== '' && $source_fp === $target_fp) {
            return 'identical';
        }

        $source_match = isset($source_object['match_keys']) && is_array($source_object['match_keys']) ? $source_object['match_keys'] : [];
        $target_match = isset($target_object['match_keys']) && is_array($target_object['match_keys']) ? $target_object['match_keys'] : [];
        $source_id = sanitize_text_field((string) ($source_match['id'] ?? ''));
        $target_id = sanitize_text_field((string) ($target_match['id'] ?? ''));
        $source_name = sanitize_text_field((string) ($source_match['name'] ?? $source_match['token'] ?? $source_match['selector'] ?? ''));
        $target_name = sanitize_text_field((string) ($target_match['name'] ?? $target_match['token'] ?? $target_match['selector'] ?? ''));

        if ($matched_by === 'name' && $source_id !== '' && $target_id !== '' && $source_id !== $target_id) {
            return 'same_name_different_id';
        }
        if ($matched_by === 'id' && $source_name !== '' && $target_name !== '' && $source_name !== $target_name) {
            return 'same_id_different_name';
        }

        $path_summary = DBVC_Bricks_Portability_Utils::diff_paths($target_object['normalized'] ?? null, $source_object['normalized'] ?? null);
        return self::classify_value_change($path_summary);
    }

    /**
     * @param array<string, array<int, string>> $path_summary
     * @return string
     */
    private static function classify_value_change(array $path_summary)
    {
        $changed = ! empty($path_summary['changed']);
        $added = ! empty($path_summary['added']);
        $removed = ! empty($path_summary['removed']);

        if (! $changed && ! $added && ! $removed) {
            return 'identical';
        }
        if ($added && ! $changed && ! $removed) {
            return 'added_props';
        }
        if ($removed && ! $changed && ! $added) {
            return 'removed_props';
        }
        if ($changed && ! $added && ! $removed) {
            return 'value_changed';
        }

        return 'changed_props';
    }

    /**
     * @param string $status
     * @param string $domain_key
     * @return string
     */
    private static function suggest_action($status, $domain_key)
    {
        $status = sanitize_key((string) $status);
        $domain_key = sanitize_key((string) $domain_key);
        if ($status === 'identical') {
            return 'skip';
        }
        if ($status === 'missing_from_source') {
            return 'keep_current';
        }
        if ($status === 'new_in_source') {
            return $domain_key === 'settings' || $domain_key === 'breakpoints' ? 'replace_with_incoming' : 'add_incoming';
        }
        return 'replace_with_incoming';
    }

    /**
     * @param string $domain_key
     * @param string $status
     * @param string $row_type
     * @return array<int, string>
     */
    private static function actions_for_row($domain_key, $status, $row_type)
    {
        $domain_key = sanitize_key((string) $domain_key);
        $status = sanitize_key((string) $status);
        $row_type = sanitize_key((string) $row_type);

        if ($row_type === 'meta' || $row_type === 'domain' || in_array($domain_key, ['settings', 'breakpoints'], true)) {
            return ['replace_with_incoming', 'keep_current', 'skip'];
        }

        if ($status === 'new_in_source') {
            return ['add_incoming', 'keep_current', 'skip'];
        }

        if ($status === 'missing_from_source' || $status === 'identical') {
            return ['keep_current', 'skip'];
        }

        return ['replace_with_incoming', 'keep_current', 'skip'];
    }

    /**
     * @param array<string, array<string, mixed>> $domains
     * @return array<string, mixed>
     */
    private static function build_reference_lookup(array $domains)
    {
        $lookup = [
            'css_variables' => [],
            'class_names' => [],
        ];

        foreach ($domains as $domain_key => $domain) {
            if (! is_array($domain)) {
                continue;
            }
            foreach ((array) ($domain['objects'] ?? []) as $object) {
                if (! is_array($object)) {
                    continue;
                }
                $match_keys = isset($object['match_keys']) && is_array($object['match_keys']) ? $object['match_keys'] : [];
                if ($domain_key === 'global_variables') {
                    foreach (['token', 'name', 'slug'] as $key) {
                        $value = sanitize_text_field((string) ($match_keys[$key] ?? ''));
                        if ($value !== '') {
                            $lookup['css_variables'][$value] = true;
                        }
                    }
                }
                if ($domain_key === 'global_classes') {
                    foreach (['name', 'slug', 'id'] as $key) {
                        $value = sanitize_text_field((string) ($match_keys[$key] ?? ''));
                        if ($value !== '') {
                            $lookup['class_names'][$value] = true;
                        }
                    }
                }
            }
        }

        return $lookup;
    }

    /**
     * @param array<string, mixed> $references
     * @param array<string, mixed> $target_lookup
     * @return array<int, string>
     */
    private static function build_reference_warnings(array $references, array $target_lookup)
    {
        $warnings = [];
        foreach ((array) ($references['css_variables'] ?? []) as $token) {
            $token = sanitize_text_field((string) $token);
            if ($token === '' || isset($target_lookup['css_variables'][$token])) {
                continue;
            }
            $warnings[] = 'Missing CSS variable dependency on target: ' . $token;
        }
        foreach ((array) ($references['class_names'] ?? []) as $class_name) {
            $class_name = sanitize_text_field((string) $class_name);
            if ($class_name === '' || isset($target_lookup['class_names'][$class_name])) {
                continue;
            }
            $warnings[] = 'Missing class dependency on target: ' . $class_name;
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed> $references
     * @param array<string, mixed> $target_lookup
     * @return array<string, mixed>
     */
    private static function build_references(array $references, array $target_lookup)
    {
        $missing = [];
        foreach (self::build_reference_warnings($references, $target_lookup) as $warning) {
            $missing[] = $warning;
        }

        return [
            'css_variables' => array_values((array) ($references['css_variables'] ?? [])),
            'class_names' => array_values((array) ($references['class_names'] ?? [])),
            'missing_dependencies' => $missing,
        ];
    }
}
