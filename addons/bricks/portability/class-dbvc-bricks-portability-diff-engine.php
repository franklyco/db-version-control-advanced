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
            $rows[] = self::diff_singleton_row($definition, $source_domain, $target_domain, $source_lookup, $target_lookup);
        } else {
            $rows = array_merge(
                $rows,
                self::diff_collection_rows($definition, $source_domain, $target_domain, $source_lookup, $target_lookup)
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
     * @param array<string, mixed> $source_lookup
     * @param array<string, mixed> $target_lookup
     * @return array<string, mixed>
     */
    private static function diff_singleton_row(array $definition, array $source_domain, array $target_domain, array $source_lookup, array $target_lookup)
    {
        $domain_key = sanitize_key((string) ($definition['domain_key'] ?? ''));
        $source_object = isset($source_domain['objects'][0]) && is_array($source_domain['objects'][0]) ? $source_domain['objects'][0] : null;
        $target_object = isset($target_domain['objects'][0]) && is_array($target_domain['objects'][0]) ? $target_domain['objects'][0] : null;
        $reference_analysis = self::analyze_references(
            is_array($source_object) ? (array) ($source_object['references'] ?? []) : [],
            $source_lookup,
            $target_lookup
        );

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
        $warnings = array_merge($warnings, (array) ($source_domain['warnings'] ?? []), (array) ($target_domain['warnings'] ?? []));
        $warnings = array_merge($warnings, self::build_reference_warnings($reference_analysis));
        $warnings = array_values(array_unique(array_map('sanitize_text_field', $warnings)));

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
            'verification' => [
                'source' => isset($source_domain['verification']) && is_array($source_domain['verification']) ? $source_domain['verification'] : [],
                'target' => isset($target_domain['verification']) && is_array($target_domain['verification']) ? $target_domain['verification'] : [],
            ],
            'references' => self::build_references(
                is_array($source_object) ? (array) ($source_object['references'] ?? []) : [],
                $reference_analysis
            ),
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $source_domain
     * @param array<string, mixed> $target_domain
     * @param array<string, mixed> $source_lookup
     * @param array<string, mixed> $target_lookup
     * @return array<int, array<string, mixed>>
     */
    private static function diff_collection_rows(array $definition, array $source_domain, array $target_domain, array $source_lookup, array $target_lookup)
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

            $reference_analysis = self::analyze_references((array) ($source_object['references'] ?? []), $source_lookup, $target_lookup);
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
                    'warnings' => self::build_reference_warnings($reference_analysis),
                    'path_summary' => [
                        'changed' => [],
                        'added' => [],
                        'removed' => [],
                    ],
                    'suggested_action' => self::suggest_action('new_in_source', $domain_key),
                    'available_actions' => self::actions_for_row($domain_key, 'new_in_source', 'object'),
                    'source' => $source_object,
                    'target' => null,
                    'references' => self::build_references((array) ($source_object['references'] ?? []), $reference_analysis),
                ];
                continue;
            }

            $target_index = (int) $match['target_index'];
            $matched_targets[$target_index] = true;
            $target_object = $target_objects[$target_index];

            $status = self::classify_matched_status($source_object, $target_object, (string) ($match['matched_by'] ?? ''));
            $path_summary = DBVC_Bricks_Portability_Utils::diff_paths($target_object['normalized'] ?? null, $source_object['normalized'] ?? null);
            $warnings = array_merge((array) ($source_object['warnings'] ?? []), self::build_reference_warnings($reference_analysis));

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
                'references' => self::build_references((array) ($source_object['references'] ?? []), $reference_analysis),
            ];
        }

        foreach ($target_objects as $target_index => $target_object) {
            if (! is_array($target_object) || isset($matched_targets[$target_index])) {
                continue;
            }

            $reference_analysis = self::analyze_references((array) ($target_object['references'] ?? []), $source_lookup, $target_lookup);

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
                'references' => self::build_references((array) ($target_object['references'] ?? []), $reference_analysis),
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
                'category_values' => [],
                'category_option_name' => '',
                'missing_dependencies' => [],
                'css_variable_dependencies' => [
                    'missing_on_current_supplied_by_incoming' => [],
                    'missing_on_both' => [],
                    'possibly_external' => [],
                ],
                'category_dependencies' => [
                    'option_name' => '',
                    'missing_on_current_supplied_by_incoming' => [],
                    'missing_on_both' => [],
                ],
                'class_dependencies_missing_on_current' => [],
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
            'category_values' => [
                'bricks_global_classes_categories' => [],
                'bricks_global_variables_categories' => [],
            ],
            'metadata_present' => [
                'bricks_global_classes_categories' => false,
                'bricks_global_variables_categories' => false,
            ],
            'domains_present' => [
                'global_variables' => false,
                'global_classes' => false,
            ],
        ];

        foreach ($domains as $domain_key => $domain) {
            if (! is_array($domain)) {
                continue;
            }
            if ($domain_key === 'global_variables') {
                $lookup['domains_present']['global_variables'] = true;
            } elseif ($domain_key === 'global_classes') {
                $lookup['domains_present']['global_classes'] = true;
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
                            foreach (self::get_css_variable_lookup_keys($value) as $lookup_key) {
                                $lookup['css_variables'][$lookup_key] = true;
                            }
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
            foreach ((array) ($domain['metadata_rows'] ?? []) as $meta_row) {
                if (! is_array($meta_row)) {
                    continue;
                }
                $option_name = sanitize_key((string) ($meta_row['option_name'] ?? ''));
                if ($option_name === '' || ! isset($lookup['category_values'][$option_name])) {
                    continue;
                }
                $lookup['metadata_present'][$option_name] = true;
                foreach (DBVC_Bricks_Portability_Normalizer::extract_category_lookup_values($meta_row['raw'] ?? null) as $value) {
                    foreach (self::get_category_lookup_keys($value) as $lookup_key) {
                        $lookup['category_values'][$option_name][$lookup_key] = true;
                    }
                }
            }
        }

        return $lookup;
    }

    /**
     * @param array<string, mixed> $references
     * @param array<string, mixed> $source_lookup
     * @param array<string, mixed> $target_lookup
     * @return array<string, mixed>
     */
    private static function analyze_references(array $references, array $source_lookup, array $target_lookup)
    {
        $analysis = [
            'css_variable_dependencies' => [
                'missing_on_current_supplied_by_incoming' => [],
                'missing_on_both' => [],
                'possibly_external' => [],
            ],
            'category_dependencies' => [
                'option_name' => sanitize_key((string) ($references['category_option_name'] ?? '')),
                'missing_on_current_supplied_by_incoming' => [],
                'missing_on_both' => [],
            ],
            'class_dependencies_missing_on_current' => [],
        ];

        $source_has_global_variables = ! empty($source_lookup['domains_present']['global_variables']);
        foreach (DBVC_Bricks_Portability_Utils::sanitize_string_list($references['css_variables'] ?? []) as $token) {
            $token = sanitize_text_field((string) $token);
            if ($token === '' || self::lookup_contains($target_lookup, 'css_variables', $token)) {
                continue;
            }
            if (self::lookup_contains($source_lookup, 'css_variables', $token)) {
                $analysis['css_variable_dependencies']['missing_on_current_supplied_by_incoming'][] = $token;
                continue;
            }
            if ($source_has_global_variables) {
                $analysis['css_variable_dependencies']['missing_on_both'][] = $token;
                continue;
            }
            $analysis['css_variable_dependencies']['possibly_external'][] = $token;
        }

        foreach (DBVC_Bricks_Portability_Utils::sanitize_string_list($references['class_names'] ?? []) as $class_name) {
            $class_name = sanitize_text_field((string) $class_name);
            if ($class_name === '' || self::lookup_contains($target_lookup, 'class_names', $class_name)) {
                continue;
            }
            $analysis['class_dependencies_missing_on_current'][] = $class_name;
        }

        $category_option_name = sanitize_key((string) ($analysis['category_dependencies']['option_name'] ?? ''));
        $source_has_category_metadata = $category_option_name !== '' && ! empty($source_lookup['metadata_present'][$category_option_name]);
        foreach (DBVC_Bricks_Portability_Utils::sanitize_string_list($references['category_values'] ?? []) as $category_value) {
            $category_value = sanitize_text_field((string) $category_value);
            if ($category_value === '' || self::lookup_contains_category($target_lookup, $category_option_name, $category_value)) {
                continue;
            }
            if (self::lookup_contains_category($source_lookup, $category_option_name, $category_value)) {
                $analysis['category_dependencies']['missing_on_current_supplied_by_incoming'][] = $category_value;
                continue;
            }
            if ($source_has_category_metadata) {
                $analysis['category_dependencies']['missing_on_both'][] = $category_value;
            }
        }

        foreach ($analysis['css_variable_dependencies'] as $bucket => $tokens) {
            $tokens = array_values(array_unique(array_map('sanitize_text_field', (array) $tokens)));
            sort($tokens, SORT_STRING);
            $analysis['css_variable_dependencies'][$bucket] = $tokens;
        }
        $analysis['class_dependencies_missing_on_current'] = array_values(array_unique(array_map('sanitize_text_field', (array) $analysis['class_dependencies_missing_on_current'])));
        sort($analysis['class_dependencies_missing_on_current'], SORT_STRING);
        foreach (['missing_on_current_supplied_by_incoming', 'missing_on_both'] as $bucket) {
            $tokens = array_values(array_unique(array_map('sanitize_text_field', (array) ($analysis['category_dependencies'][$bucket] ?? []))));
            sort($tokens, SORT_STRING);
            $analysis['category_dependencies'][$bucket] = $tokens;
        }

        return $analysis;
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<int, string>
     */
    private static function build_reference_warnings(array $analysis)
    {
        $warnings = [];
        $css_dependencies = isset($analysis['css_variable_dependencies']) && is_array($analysis['css_variable_dependencies'])
            ? $analysis['css_variable_dependencies']
            : [];

        foreach ((array) ($css_dependencies['missing_on_current_supplied_by_incoming'] ?? []) as $token) {
            $warnings[] = 'Missing CSS variable on Current Site but supplied by Incoming Package: ' . sanitize_text_field((string) $token);
        }
        foreach ((array) ($css_dependencies['missing_on_both'] ?? []) as $token) {
            $warnings[] = 'Missing CSS variable on both Current Site and Incoming Package: ' . sanitize_text_field((string) $token);
        }
        foreach ((array) ($css_dependencies['possibly_external'] ?? []) as $token) {
            $warnings[] = 'CSS variable may be external or outside Bricks portability scope: ' . sanitize_text_field((string) $token);
        }
        $category_dependencies = isset($analysis['category_dependencies']) && is_array($analysis['category_dependencies'])
            ? $analysis['category_dependencies']
            : [];
        foreach ((array) ($category_dependencies['missing_on_current_supplied_by_incoming'] ?? []) as $category_value) {
            $warnings[] = sprintf(
                'Missing %s on Current Site but supplied by Incoming Package: %s',
                self::get_category_option_label((string) ($category_dependencies['option_name'] ?? '')),
                sanitize_text_field((string) $category_value)
            );
        }
        foreach ((array) ($category_dependencies['missing_on_both'] ?? []) as $category_value) {
            $warnings[] = sprintf(
                'Missing %s on both Current Site and Incoming Package: %s',
                self::get_category_option_label((string) ($category_dependencies['option_name'] ?? '')),
                sanitize_text_field((string) $category_value)
            );
        }
        foreach ((array) ($analysis['class_dependencies_missing_on_current'] ?? []) as $class_name) {
            $warnings[] = 'Missing class dependency on Current Site: ' . sanitize_text_field((string) $class_name);
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed> $references
     * @param array<string, mixed> $analysis
     * @return array<string, mixed>
     */
    private static function build_references(array $references, array $analysis)
    {
        return [
            'css_variables' => DBVC_Bricks_Portability_Utils::sanitize_string_list($references['css_variables'] ?? []),
            'class_names' => DBVC_Bricks_Portability_Utils::sanitize_string_list($references['class_names'] ?? []),
            'category_values' => DBVC_Bricks_Portability_Utils::sanitize_string_list($references['category_values'] ?? []),
            'category_option_name' => sanitize_key((string) ($references['category_option_name'] ?? '')),
            'missing_dependencies' => self::build_reference_warnings($analysis),
            'css_variable_dependencies' => isset($analysis['css_variable_dependencies']) && is_array($analysis['css_variable_dependencies'])
                ? $analysis['css_variable_dependencies']
                : [
                    'missing_on_current_supplied_by_incoming' => [],
                    'missing_on_both' => [],
                    'possibly_external' => [],
                ],
            'category_dependencies' => isset($analysis['category_dependencies']) && is_array($analysis['category_dependencies'])
                ? $analysis['category_dependencies']
                : [
                    'option_name' => '',
                    'missing_on_current_supplied_by_incoming' => [],
                    'missing_on_both' => [],
                ],
            'class_dependencies_missing_on_current' => array_values((array) ($analysis['class_dependencies_missing_on_current'] ?? [])),
        ];
    }

    /**
     * @param array<string, mixed> $lookup
     * @param string $type
     * @param string $value
     * @return bool
     */
    private static function lookup_contains(array $lookup, $type, $value)
    {
        $type = sanitize_key((string) $type);
        $value = sanitize_text_field((string) $value);
        if ($type === '' || $value === '' || empty($lookup[$type]) || ! is_array($lookup[$type])) {
            return false;
        }

        $keys = $type === 'css_variables' ? self::get_css_variable_lookup_keys($value) : [$value];
        foreach ($keys as $key) {
            if (isset($lookup[$type][$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $token
     * @return array<int, string>
     */
    private static function get_css_variable_lookup_keys($token)
    {
        $token = sanitize_text_field((string) $token);
        if ($token === '') {
            return [];
        }

        $trimmed = ltrim($token, '-');
        $keys = [$token];
        if ($trimmed !== '') {
            $keys[] = $trimmed;
            $keys[] = '--' . $trimmed;
        }

        $keys = array_values(array_unique(array_map('sanitize_text_field', $keys)));
        sort($keys, SORT_STRING);
        return $keys;
    }

    /**
     * @param array<string, mixed> $lookup
     * @param string $option_name
     * @param string $value
     * @return bool
     */
    private static function lookup_contains_category(array $lookup, $option_name, $value)
    {
        $option_name = sanitize_key((string) $option_name);
        $value = sanitize_text_field((string) $value);
        if ($option_name === '' || $value === '' || empty($lookup['category_values'][$option_name]) || ! is_array($lookup['category_values'][$option_name])) {
            return false;
        }

        foreach (self::get_category_lookup_keys($value) as $lookup_key) {
            if (isset($lookup['category_values'][$option_name][$lookup_key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $value
     * @return array<int, string>
     */
    private static function get_category_lookup_keys($value)
    {
        $value = sanitize_text_field((string) $value);
        if ($value === '') {
            return [];
        }

        $normalized = strtolower(trim($value));
        $keys = array_values(array_unique(array_filter([
            $value,
            $normalized,
            sanitize_title($value),
        ])));
        sort($keys, SORT_STRING);
        return $keys;
    }

    /**
     * @param string $option_name
     * @return string
     */
    private static function get_category_option_label($option_name)
    {
        $option_name = sanitize_key((string) $option_name);
        if ($option_name === 'bricks_global_classes_categories') {
            return 'class category';
        }
        if ($option_name === 'bricks_global_variables_categories') {
            return 'variable category';
        }
        return 'category';
    }
}
