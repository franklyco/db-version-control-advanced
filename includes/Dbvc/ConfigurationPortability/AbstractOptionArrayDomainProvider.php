<?php

namespace Dbvc\ConfigurationPortability;

if (! defined('WPINC')) {
    die;
}

abstract class AbstractOptionArrayDomainProvider extends AbstractOptionDomainProvider
{
    abstract protected function get_option_key(): string;

    /**
     * @return array<string, mixed>
     */
    protected function get_default_option_value(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    protected function sanitize_option_array(array $candidate, array $current): array
    {
        unset($current);

        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_current_values(): array
    {
        $option = $this->get_option_array();
        $values = [];
        foreach ($this->get_fields() as $field_key => $field) {
            $values[$field_key] = $this->get_path_value(
                $option,
                $this->get_field_path($field_key, $field),
                $field['default'] ?? ''
            );
        }

        return $values;
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
        if (empty($values)) {
            return [
                'domain' => $this->get_key(),
                'applied' => [],
                'skipped' => [],
            ];
        }

        $fields = $this->get_fields();
        $current = $this->get_option_array();
        $candidate = $current;
        $applied = [];
        $skipped = [];

        foreach ($values as $field_key => $value) {
            if (! isset($fields[$field_key])) {
                $skipped[$field_key] = 'unknown_field';
                continue;
            }

            $path = $this->get_field_path($field_key, $fields[$field_key]);
            $this->set_path_value($candidate, $path, $value);
            $applied[$field_key] = $this->get_option_key() . ':' . implode('.', $path);
        }

        if (! empty($applied)) {
            $candidate = $this->sanitize_option_array($candidate, $current);
            update_option($this->get_option_key(), $candidate, false);
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

        $sentinel = new \stdClass();
        $value = get_option($this->get_option_key(), $sentinel);

        return [
            'domain' => $this->get_key(),
            'domain_version' => $this->get_version(),
            'captured_at_gmt' => gmdate('c'),
            'option_key' => $this->get_option_key(),
            'existed' => $value !== $sentinel,
            'value' => $value === $sentinel ? null : $value,
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

        $option_key = (string) ($backup['option_key'] ?? $this->get_option_key());
        if (empty($backup['existed'])) {
            delete_option($option_key);
            return [
                'domain' => $this->get_key(),
                'restored' => [],
                'deleted' => [$option_key],
            ];
        }

        update_option($option_key, $backup['value'] ?? null, false);
        $this->after_apply([$option_key]);

        return [
            'domain' => $this->get_key(),
            'restored' => [$option_key],
            'deleted' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function get_option_array(): array
    {
        $value = get_option($this->get_option_key(), $this->get_default_option_value());
        if (! is_array($value)) {
            $value = [];
        }

        return $this->sanitize_option_array(
            wp_parse_args($value, $this->get_default_option_value()),
            $this->get_default_option_value()
        );
    }

    /**
     * @param string               $field_key
     * @param array<string, mixed> $field
     * @return array<int, string>
     */
    protected function get_field_path($field_key, array $field): array
    {
        if (isset($field['option_path']) && is_array($field['option_path']) && ! empty($field['option_path'])) {
            return array_values(array_map(static function ($part): string {
                return (string) $part;
            }, $field['option_path']));
        }

        return [sanitize_key((string) $field_key)];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string>   $path
     * @param mixed                $fallback
     * @return mixed
     */
    protected function get_path_value(array $source, array $path, $fallback = null)
    {
        $cursor = $source;
        foreach ($path as $index => $part) {
            if (! is_array($cursor) || ! array_key_exists($part, $cursor)) {
                return $fallback;
            }

            if ($index === count($path) - 1) {
                return $cursor[$part];
            }

            $cursor = $cursor[$part];
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $target
     * @param array<int, string>   $path
     * @param mixed                $value
     * @return void
     */
    protected function set_path_value(array &$target, array $path, $value): void
    {
        $cursor = &$target;
        $last_index = count($path) - 1;
        foreach ($path as $index => $part) {
            if ($index === $last_index) {
                $cursor[$part] = $value;
                return;
            }

            if (! isset($cursor[$part]) || ! is_array($cursor[$part])) {
                $cursor[$part] = [];
            }
            $cursor = &$cursor[$part];
        }
    }
}
