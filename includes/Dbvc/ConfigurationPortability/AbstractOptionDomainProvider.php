<?php

namespace Dbvc\ConfigurationPortability;

if (! defined('WPINC')) {
    die;
}

abstract class AbstractOptionDomainProvider implements DomainProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function get_current_values(): array
    {
        $values = [];
        foreach ($this->get_fields() as $field_key => $field) {
            $option_key = (string) ($field['option_key'] ?? $field_key);
            $default = $field['default'] ?? '';
            $values[$field_key] = get_option($option_key, $default);
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $selection
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function export(array $selection, array $context): array
    {
        $fields = $this->get_fields();
        $values = $this->get_current_values();
        $selected_fields = $this->resolve_selected_fields($selection, $context);
        $groups = [];
        $redactions = [];

        foreach ($this->get_groups() as $group_key => $group) {
            $groups[$group_key] = [
                'label' => (string) ($group['label'] ?? $group_key),
                'fields' => [],
            ];
        }

        foreach ($fields as $field_key => $field) {
            if (! in_array($field_key, $selected_fields, true)) {
                continue;
            }

            $group_key = (string) ($field['group'] ?? 'general');
            if (! isset($groups[$group_key])) {
                $groups[$group_key] = [
                    'label' => $group_key,
                    'fields' => [],
                ];
            }

            $policy = (string) ($field['environment_policy'] ?? Field::POLICY_PORTABLE);
            $default_export = (string) ($field['default_export'] ?? Field::POLICY_PORTABLE);
            $sensitive = ! empty($field['sensitive']);
            $redacted = $sensitive || in_array($default_export, [Field::POLICY_EXCLUDE, Field::POLICY_REDACT, Field::POLICY_PROMPT], true);
            $value = $values[$field_key] ?? ($field['default'] ?? '');

            if ($redacted) {
                $value = $this->build_placeholder_value($field_key, $field);
                $redactions[$field_key] = [
                    'field' => $field_key,
                    'policy' => $policy,
                    'reason' => $sensitive ? 'sensitive' : $default_export,
                ];
            }

            $groups[$group_key]['fields'][$field_key] = [
                'value' => $value,
                'policy' => $policy,
                'default_export' => $default_export,
                'type' => (string) ($field['type'] ?? 'text'),
                'label' => (string) ($field['label'] ?? $field_key),
                'source_value_redacted' => $redacted,
                'sensitive' => $sensitive,
                'requires_confirmation' => ! empty($field['requires_confirmation']),
            ];
        }

        foreach ($groups as $group_key => $group) {
            if (empty($group['fields'])) {
                unset($groups[$group_key]);
            }
        }

        return [
            'domain' => $this->get_key(),
            'label' => $this->get_label(),
            'domain_version' => $this->get_version(),
            'exported_at_gmt' => gmdate('c'),
            'groups' => $groups,
            'redactions' => $redactions,
        ];
    }

    /**
     * @param array<string, mixed> $incoming
     * @param array<string, mixed> $current
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function diff(array $incoming, array $current, array $context): array
    {
        unset($context);

        $incoming_fields = $this->flatten_incoming_fields($incoming);
        $rows = [];

        foreach ($this->get_fields() as $field_key => $field) {
            if (! isset($incoming_fields[$field_key])) {
                $rows[] = $this->build_diff_row($field_key, $field, $current[$field_key] ?? null, null, 'incoming_missing');
                continue;
            }

            $incoming_field = $incoming_fields[$field_key];
            $incoming_value = $incoming_field['value'] ?? null;
            $redacted = ! empty($incoming_field['source_value_redacted']) || ! empty($field['sensitive']);
            $policy = (string) ($incoming_field['policy'] ?? ($field['environment_policy'] ?? Field::POLICY_PORTABLE));

            if ($redacted && in_array($policy, [Field::POLICY_EXCLUDE, Field::POLICY_PROMPT, Field::POLICY_REDACT], true)) {
                $status = $policy === Field::POLICY_EXCLUDE ? 'blocked_secret' : 'needs_environment_value';
            } elseif ($this->values_equal($incoming_value, $current[$field_key] ?? null)) {
                $status = 'same';
            } else {
                $status = 'changed';
            }

            $rows[] = $this->build_diff_row($field_key, $field, $current[$field_key] ?? null, $incoming_value, $status, $policy, $redacted);
        }

        return [
            'domain' => $this->get_key(),
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $incoming
     * @param array<string, mixed> $resolved_environment
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    public function sanitize_for_apply(array $incoming, array $resolved_environment, array $current): array
    {
        $incoming_fields = $this->flatten_incoming_fields($incoming);
        $values = [];
        $errors = [];
        $skipped = [];

        foreach ($this->get_fields() as $field_key => $field) {
            if (! isset($incoming_fields[$field_key])) {
                continue;
            }

            $incoming_field = $incoming_fields[$field_key];
            $policy = (string) ($incoming_field['policy'] ?? ($field['environment_policy'] ?? Field::POLICY_PORTABLE));
            $redacted = ! empty($incoming_field['source_value_redacted']) || ! empty($field['sensitive']);
            $value = $incoming_field['value'] ?? null;

            if ($redacted || in_array($policy, [Field::POLICY_PROMPT, Field::POLICY_REDACT, Field::POLICY_KEEP_EXISTING], true)) {
                if (array_key_exists($field_key, $resolved_environment)) {
                    $value = $resolved_environment[$field_key];
                } else {
                    $skipped[$field_key] = 'environment_value_required';
                    continue;
                }
            }

            if ($policy === Field::POLICY_EXCLUDE) {
                $skipped[$field_key] = 'excluded_by_policy';
                continue;
            }

            $sanitized = $this->sanitize_field_value($field_key, $field, $value, $current[$field_key] ?? ($field['default'] ?? ''));
            if (is_array($sanitized) && isset($sanitized['error'])) {
                $errors[$field_key] = (string) $sanitized['error'];
                continue;
            }

            $values[$field_key] = $sanitized;
        }

        return [
            'domain' => $this->get_key(),
            'values' => $values,
            'errors' => $errors,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param array<string, mixed> $sanitized
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function apply(array $sanitized, array $context): array
    {
        unset($context);

        $values = isset($sanitized['values']) && is_array($sanitized['values']) ? $sanitized['values'] : [];
        $fields = $this->get_fields();
        $applied = [];
        $skipped = [];

        foreach ($values as $field_key => $value) {
            if (! isset($fields[$field_key])) {
                $skipped[$field_key] = 'unknown_field';
                continue;
            }

            $option_key = (string) ($fields[$field_key]['option_key'] ?? $field_key);
            update_option($option_key, $value);
            $applied[$field_key] = $option_key;
        }

        if (! empty($applied)) {
            $this->after_apply($applied);
        }

        return [
            'domain' => $this->get_key(),
            'applied' => $applied,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function capture_backup(array $context): array
    {
        unset($context);

        $snapshot = [];
        $sentinel = new \stdClass();
        foreach ($this->get_fields() as $field_key => $field) {
            $option_key = (string) ($field['option_key'] ?? $field_key);
            $value = get_option($option_key, $sentinel);
            $snapshot[$field_key] = [
                'option_key' => $option_key,
                'existed' => $value !== $sentinel,
                'value' => $value === $sentinel ? null : $value,
            ];
        }

        return [
            'domain' => $this->get_key(),
            'domain_version' => $this->get_version(),
            'captured_at_gmt' => gmdate('c'),
            'values' => $snapshot,
        ];
    }

    /**
     * @param array<string, mixed> $backup
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function rollback(array $backup, array $context): array
    {
        unset($context);

        $values = isset($backup['values']) && is_array($backup['values']) ? $backup['values'] : [];
        $restored = [];
        $deleted = [];
        $fields = $this->get_fields();

        foreach ($values as $field_key => $record) {
            if (! isset($fields[$field_key]) || ! is_array($record)) {
                continue;
            }

            $option_key = (string) ($record['option_key'] ?? ($fields[$field_key]['option_key'] ?? $field_key));
            if (empty($record['existed'])) {
                delete_option($option_key);
                $deleted[$field_key] = $option_key;
                continue;
            }

            update_option($option_key, $record['value'] ?? null);
            $restored[$field_key] = $option_key;
        }

        if (! empty($restored) || ! empty($deleted)) {
            $this->after_apply(array_merge($restored, $deleted));
        }

        return [
            'domain' => $this->get_key(),
            'restored' => $restored,
            'deleted' => $deleted,
        ];
    }

    /**
     * @param array<int|string, mixed> $applied
     * @return void
     */
    protected function after_apply(array $applied): void
    {
        unset($applied);
    }

    /**
     * @param array<string, mixed> $selection
     * @param array<string, mixed> $context
     * @return array<int, string>
     */
    private function resolve_selected_fields(array $selection, array $context): array
    {
        $fields = array_keys($this->get_fields());
        $requested = isset($selection['fields']) && is_array($selection['fields'])
            ? array_values(array_map('sanitize_key', $selection['fields']))
            : [];
        if (! empty($requested)) {
            return array_values(array_intersect($fields, $requested));
        }

        $profile = sanitize_key((string) ($context['profile'] ?? 'agency_baseline'));
        $selected = [];
        foreach ($this->get_fields() as $field_key => $field) {
            $default_export = (string) ($field['default_export'] ?? Field::POLICY_PORTABLE);
            if (in_array($default_export, [Field::POLICY_EXCLUDE, Field::POLICY_ADVANCED], true) && $profile !== 'full_review') {
                continue;
            }
            $selected[] = $field_key;
        }

        return $selected;
    }

    /**
     * @param string               $field_key
     * @param array<string, mixed> $field
     * @return mixed
     */
    private function build_placeholder_value($field_key, array $field)
    {
        if (! empty($field['sensitive']) || (($field['default_export'] ?? '') === Field::POLICY_EXCLUDE)) {
            return null;
        }

        $placeholder = (string) ($field['placeholder'] ?? '');
        if ($placeholder !== '') {
            return $placeholder;
        }

        return '${' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $field_key)) . '}';
    }

    /**
     * @param array<string, mixed> $incoming
     * @return array<string, array<string, mixed>>
     */
    private function flatten_incoming_fields(array $incoming): array
    {
        $groups = isset($incoming['groups']) && is_array($incoming['groups']) ? $incoming['groups'] : [];
        $fields = [];
        foreach ($groups as $group) {
            if (! is_array($group) || ! isset($group['fields']) || ! is_array($group['fields'])) {
                continue;
            }
            foreach ($group['fields'] as $field_key => $payload) {
                $field_key = sanitize_key((string) $field_key);
                if ($field_key === '' || ! is_array($payload)) {
                    continue;
                }
                $fields[$field_key] = $payload;
            }
        }

        return $fields;
    }

    /**
     * @param string               $field_key
     * @param array<string, mixed> $field
     * @param mixed                $current
     * @param mixed                $incoming
     * @param string               $status
     * @param string               $policy
     * @param bool                 $redacted
     * @return array<string, mixed>
     */
    private function build_diff_row($field_key, array $field, $current, $incoming, $status, $policy = '', $redacted = false): array
    {
        return [
            'field' => $field_key,
            'label' => (string) ($field['label'] ?? $field_key),
            'group' => (string) ($field['group'] ?? 'general'),
            'type' => (string) ($field['type'] ?? 'text'),
            'policy' => $policy !== '' ? $policy : (string) ($field['environment_policy'] ?? Field::POLICY_PORTABLE),
            'status' => $status,
            'current_value' => $this->mask_for_response($field, $current),
            'incoming_value' => $redacted ? '[redacted]' : $this->mask_for_response($field, $incoming),
            'source_value_redacted' => $redacted,
        ];
    }

    /**
     * @param array<string, mixed> $field
     * @param mixed                $value
     * @return mixed
     */
    private function mask_for_response(array $field, $value)
    {
        if (! empty($field['sensitive'])) {
            return $value === null || $value === '' ? '' : '[redacted]';
        }

        return $value;
    }

    /**
     * @param mixed $left
     * @param mixed $right
     * @return bool
     */
    private function values_equal($left, $right): bool
    {
        return wp_json_encode($left) === wp_json_encode($right);
    }

    /**
     * @param string               $field_key
     * @param array<string, mixed> $field
     * @param mixed                $value
     * @param mixed                $fallback
     * @return mixed|array{error:string}
     */
    private function sanitize_field_value($field_key, array $field, $value, $fallback)
    {
        $type = (string) ($field['type'] ?? 'text');

        if ($type === 'bool') {
            return $this->sanitize_bool($value);
        }

        if ($type === 'int') {
            $candidate = absint($value);
            $min = isset($field['min']) ? (int) $field['min'] : 0;
            $max = isset($field['max']) ? (int) $field['max'] : PHP_INT_MAX;
            if ($candidate < $min || $candidate > $max) {
                return ['error' => sprintf('%s must be between %d and %d.', $field_key, $min, $max)];
            }
            return (string) $candidate;
        }

        if ($type === 'float') {
            if (! is_scalar($value) || trim((string) $value) === '' || ! is_numeric($value)) {
                return ['error' => sprintf('%s must be numeric.', $field_key)];
            }

            $candidate = (float) $value;
            $min = isset($field['min']) ? (float) $field['min'] : 0.0;
            $max = isset($field['max']) ? (float) $field['max'] : (float) PHP_INT_MAX;
            if ($candidate < $min || $candidate > $max) {
                return ['error' => sprintf('%s must be between %s and %s.', $field_key, (string) $min, (string) $max)];
            }

            $formatted = number_format($candidate, 4, '.', '');
            return rtrim(rtrim($formatted, '0'), '.') ?: '0';
        }

        if ($type === 'enum') {
            $candidate = sanitize_key((string) $value);
            $allowed = isset($field['allowed']) && is_array($field['allowed']) ? $field['allowed'] : [];
            if (! in_array($candidate, $allowed, true)) {
                return ['error' => sprintf('%s is invalid.', $field_key)];
            }
            return $candidate;
        }

        if ($type === 'path') {
            $candidate = sanitize_text_field((string) $value);
            if ($candidate === '') {
                return '';
            }
            if (function_exists('dbvc_validate_sync_path')) {
                $validated = dbvc_validate_sync_path($candidate);
                if ($validated === false) {
                    return ['error' => sprintf('%s path is invalid.', $field_key)];
                }
                return $validated;
            }
            return $candidate;
        }

        if ($type === 'file_name') {
            $candidate = sanitize_file_name((string) $value);
            if ($candidate === '' && (string) $fallback !== '') {
                return sanitize_file_name((string) $fallback);
            }
            return $candidate;
        }

        if ($type === 'url') {
            $candidate = esc_url_raw((string) $value);
            if ($candidate !== '' && stripos($candidate, 'http') !== 0) {
                return ['error' => sprintf('%s URL is invalid.', $field_key)];
            }
            return $candidate;
        }

        if ($type === 'key_id') {
            $candidate = sanitize_text_field((string) $value);
            if ($candidate !== '' && ! preg_match('/^[A-Za-z0-9._-]{3,128}$/', $candidate)) {
                return ['error' => sprintf('%s format is invalid.', $field_key)];
            }
            return $candidate;
        }

        if ($type === 'secret') {
            return sanitize_text_field(trim((string) $value));
        }

        if ($type === 'textarea') {
            return sanitize_textarea_field((string) $value);
        }

        if ($type === 'key_list' || $type === 'string_list') {
            return $this->sanitize_list($value, $type === 'key_list', isset($field['allowed']) && is_array($field['allowed']) ? $field['allowed'] : []);
        }

        if ($type === 'map') {
            if (! is_array($value)) {
                return ['error' => sprintf('%s must be an object/map.', $field_key)];
            }
            return $this->sanitize_map_recursive($value);
        }

        if ($type === 'json_map') {
            $candidate = trim((string) $value);
            if ($candidate === '') {
                return '{}';
            }
            $decoded = json_decode($candidate, true);
            if (! is_array($decoded)) {
                return ['error' => sprintf('%s must be valid JSON object/map.', $field_key)];
            }
            return wp_json_encode($decoded);
        }

        if ($value === null) {
            return $fallback;
        }

        return sanitize_text_field((string) $value);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitize_map_recursive($value)
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $nested) {
                $safe_key = is_int($key) ? $key : sanitize_key((string) $key);
                if (! is_int($safe_key) && $safe_key === '') {
                    continue;
                }
                $sanitized[$safe_key] = $this->sanitize_map_recursive($nested);
            }
            return $sanitized;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        return sanitize_textarea_field((string) $value);
    }

    /**
     * @param mixed              $value
     * @param bool               $key_list
     * @param array<int, string> $allowed
     * @return array<int, string>
     */
    private function sanitize_list($value, bool $key_list, array $allowed): array
    {
        if (! is_array($value)) {
            $value = preg_split('/[\r\n,]+/', (string) $value);
        }

        if (! is_array($value)) {
            return [];
        }

        $sanitized = [];
        $allowed_lookup = [];
        foreach ($allowed as $allowed_value) {
            $allowed_value = $key_list ? sanitize_key((string) $allowed_value) : sanitize_text_field((string) $allowed_value);
            if ($allowed_value !== '') {
                $allowed_lookup[$allowed_value] = true;
            }
        }

        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $candidate = $key_list ? sanitize_key((string) $item) : sanitize_text_field((string) $item);
            if ($candidate === '') {
                continue;
            }
            if (! empty($allowed_lookup) && ! isset($allowed_lookup[$candidate])) {
                continue;
            }

            $sanitized[$candidate] = $candidate;
        }

        return array_values($sanitized);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function sanitize_bool($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $string = strtolower(trim((string) $value));

        return in_array($string, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
    }
}
