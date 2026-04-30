<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Portability_Domain_Verifier
{
    /**
     * @param array<string, mixed> $domain_definition
     * @return array<string, mixed>
     */
    public static function verify_live_domain(array $domain_definition)
    {
        $option_values = [];
        foreach ((array) ($domain_definition['option_names'] ?? []) as $option_name) {
            $option_name = sanitize_key((string) $option_name);
            if ($option_name === '') {
                continue;
            }
            $option_values[$option_name] = get_option($option_name, null);
        }

        return self::verify_domain_payload($domain_definition, $option_values, 'live');
    }

    /**
     * @param array<string, mixed> $domain_definition
     * @param array<string, mixed> $option_values
     * @param string $source
     * @return array<string, mixed>
     */
    public static function verify_domain_payload(array $domain_definition, array $option_values, $source = 'live')
    {
        $domain_key = sanitize_key((string) ($domain_definition['domain_key'] ?? ''));
        $report = [
            'domain_key' => $domain_key,
            'source' => sanitize_key((string) $source),
            'status' => 'not_required',
            'safe_for_export' => true,
            'safe_for_apply' => true,
            'payload_type' => 'unknown',
            'detected_path' => [],
            'entry_count' => 0,
            'recognized_count' => 0,
            'unrecognized_count' => 0,
            'warnings' => [],
            'notes' => [],
        ];

        if ($domain_key !== 'breakpoints') {
            return $report;
        }

        return self::verify_breakpoints_payload($domain_definition, $option_values, $report);
    }

    /**
     * @param array<string, mixed> $report
     * @return bool
     */
    public static function blocks_export(array $report)
    {
        return ! empty($report) && ! empty($report['status']) && empty($report['safe_for_export']);
    }

    /**
     * @param array<string, mixed> $report
     * @return bool
     */
    public static function blocks_apply(array $report)
    {
        return ! empty($report) && ! empty($report['status']) && empty($report['safe_for_apply']);
    }

    /**
     * @param array<string, mixed> $domain_definition
     * @param array<string, mixed> $option_values
     * @param array<string, mixed> $base_report
     * @return array<string, mixed>
     */
    private static function verify_breakpoints_payload(array $domain_definition, array $option_values, array $base_report)
    {
        $primary_option = sanitize_key((string) ($domain_definition['primary_option'] ?? 'bricks_breakpoints'));
        $raw = array_key_exists($primary_option, $option_values) ? $option_values[$primary_option] : null;
        $report = $base_report;

        if ($raw === null) {
            $report['status'] = 'missing';
            $report['safe_for_export'] = false;
            $report['safe_for_apply'] = false;
            $report['payload_type'] = 'missing';
            $report['warnings'][] = 'Bricks breakpoints option was not found for verification.';
            return $report;
        }

        $report['payload_type'] = self::detect_payload_type($raw);
        if (! is_array($raw)) {
            $report['status'] = 'unsupported';
            $report['safe_for_export'] = false;
            $report['safe_for_apply'] = false;
            $report['warnings'][] = 'Breakpoints payload is not an array and cannot be verified safely.';
            return $report;
        }

        $analysis = self::analyze_best_breakpoint_container($raw);
        $report['payload_type'] = (string) ($analysis['shape'] ?? $report['payload_type']);
        $report['detected_path'] = DBVC_Bricks_Portability_Utils::sanitize_path_segments((array) ($analysis['path'] ?? []));
        $report['entry_count'] = (int) ($analysis['entry_count'] ?? 0);
        $report['recognized_count'] = (int) ($analysis['recognized_count'] ?? 0);
        $report['unrecognized_count'] = (int) ($analysis['unrecognized_count'] ?? 0);

        if (! empty($option_values['bricks_breakpoints_last_generated'])) {
            $report['notes'][] = 'The generated marker option is excluded from portability writes and kept as backup-only metadata.';
        }

        if ((int) $report['entry_count'] <= 0) {
            $report['status'] = 'review_recommended';
            $report['safe_for_export'] = false;
            $report['safe_for_apply'] = false;
            $report['warnings'][] = 'No breakpoint entries were recognized in the current payload.';
            return $report;
        }

        if ((int) $report['recognized_count'] <= 0) {
            $report['status'] = 'unsupported';
            $report['safe_for_export'] = false;
            $report['safe_for_apply'] = false;
            $report['warnings'][] = 'Breakpoint entries could not be matched to a known Bricks-style structure.';
            return $report;
        }

        if ((int) $report['unrecognized_count'] > 0) {
            $report['status'] = 'review_recommended';
            $report['safe_for_export'] = false;
            $report['safe_for_apply'] = false;
            $report['warnings'][] = sprintf(
                'Breakpoint payload mixed recognized and unrecognized entries (%d of %d recognized).',
                (int) $report['recognized_count'],
                (int) $report['entry_count']
            );
            return $report;
        }

        $report['status'] = 'verified';
        $report['safe_for_export'] = true;
        $report['safe_for_apply'] = true;
        $report['warnings'][] = 'Breakpoints payload shape was recognized, but this domain remains high risk and should be tested after apply.';
        return $report;
    }

    /**
     * @param array<int|string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function analyze_best_breakpoint_container(array $raw)
    {
        $candidates = [
            [
                'path' => [],
                'container' => $raw,
            ],
        ];

        foreach (['breakpoints', 'items', 'values', 'data'] as $candidate_key) {
            if (! array_key_exists($candidate_key, $raw) || ! is_array($raw[$candidate_key])) {
                continue;
            }
            $candidates[] = [
                'path' => [$candidate_key],
                'container' => $raw[$candidate_key],
            ];
        }

        $best = [
            'path' => [],
            'shape' => self::detect_payload_type($raw),
            'entry_count' => 0,
            'recognized_count' => 0,
            'unrecognized_count' => 0,
        ];

        foreach ($candidates as $candidate) {
            $analysis = self::analyze_breakpoint_container((array) $candidate['container']);
            $analysis['path'] = (array) $candidate['path'];
            if (self::is_better_breakpoint_analysis($analysis, $best)) {
                $best = $analysis;
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $best
     * @return bool
     */
    private static function is_better_breakpoint_analysis(array $candidate, array $best)
    {
        $candidate_recognized = (int) ($candidate['recognized_count'] ?? 0);
        $best_recognized = (int) ($best['recognized_count'] ?? 0);
        if ($candidate_recognized !== $best_recognized) {
            return $candidate_recognized > $best_recognized;
        }

        $candidate_unrecognized = (int) ($candidate['unrecognized_count'] ?? 0);
        $best_unrecognized = (int) ($best['unrecognized_count'] ?? 0);
        if ($candidate_unrecognized !== $best_unrecognized) {
            return $candidate_unrecognized < $best_unrecognized;
        }

        return (int) ($candidate['entry_count'] ?? 0) > (int) ($best['entry_count'] ?? 0);
    }

    /**
     * @param array<int|string, mixed> $container
     * @return array<string, mixed>
     */
    private static function analyze_breakpoint_container(array $container)
    {
        $analysis = [
            'shape' => DBVC_Bricks_Portability_Utils::is_assoc($container) ? 'map' : 'list',
            'entry_count' => count($container),
            'recognized_count' => 0,
            'unrecognized_count' => 0,
        ];

        foreach ($container as $key => $item) {
            $recognized = self::is_breakpoint_entry($item, $key);
            if ($recognized) {
                $analysis['recognized_count']++;
            } else {
                $analysis['unrecognized_count']++;
            }
        }

        return $analysis;
    }

    /**
     * @param mixed $entry
     * @param mixed $key
     * @return bool
     */
    private static function is_breakpoint_entry($entry, $key)
    {
        $key = sanitize_text_field((string) $key);

        if (is_array($entry)) {
            $has_width = false;
            foreach (['width', 'minWidth', 'maxWidth', 'min', 'max', 'value', 'size'] as $candidate) {
                if (! array_key_exists($candidate, $entry)) {
                    continue;
                }
                if (self::looks_like_breakpoint_width($entry[$candidate])) {
                    $has_width = true;
                    break;
                }
            }

            $has_identifier = false;
            foreach (['id', 'key', 'slug', 'name', 'label', 'title'] as $candidate) {
                if (! array_key_exists($candidate, $entry)) {
                    continue;
                }
                if (trim((string) $entry[$candidate]) !== '') {
                    $has_identifier = true;
                    break;
                }
            }

            if (! $has_identifier && $key !== '') {
                $has_identifier = true;
            }

            return $has_width && $has_identifier;
        }

        if (! is_scalar($entry)) {
            return false;
        }

        return $key !== '' && self::looks_like_breakpoint_width($entry);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private static function looks_like_breakpoint_width($value)
    {
        if (is_int($value) || is_float($value)) {
            return $value >= 0;
        }

        if (! is_scalar($value)) {
            return false;
        }

        return preg_match('/^\d+(\.\d+)?(px|em|rem|vw|vh|%)?$/i', trim((string) $value)) === 1;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function detect_payload_type($value)
    {
        if ($value === null) {
            return 'missing';
        }
        if (! is_array($value)) {
            return 'scalar';
        }
        return DBVC_Bricks_Portability_Utils::is_assoc($value) ? 'map' : 'list';
    }
}
