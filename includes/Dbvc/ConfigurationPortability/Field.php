<?php

namespace Dbvc\ConfigurationPortability;

if (! defined('WPINC')) {
    die;
}

final class Field
{
    public const POLICY_PORTABLE = 'portable';
    public const POLICY_EXCLUDE = 'exclude';
    public const POLICY_REDACT = 'redact';
    public const POLICY_PROMPT = 'prompt';
    public const POLICY_REPLACE = 'replace';
    public const POLICY_KEEP_EXISTING = 'keep_existing';
    public const POLICY_ADVANCED = 'advanced';

    public const STRATEGY_REPLACE = 'replace';
    public const STRATEGY_KEEP_EXISTING_UNLESS_SUPPLIED = 'keep_existing_unless_supplied';
    public const STRATEGY_CLEAR_IF_CONFIRMED = 'clear_if_confirmed';

    /**
     * @param string               $key
     * @param string               $label
     * @param string               $group
     * @param string               $default
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function text($key, $label, $group, $default = '', array $args = []): array
    {
        return self::make($key, $label, 'text', $group, $default, $args);
    }

    /**
     * @param string               $key
     * @param string               $label
     * @param string               $group
     * @param string               $default
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function textarea($key, $label, $group, $default = '', array $args = []): array
    {
        return self::make($key, $label, 'textarea', $group, $default, $args);
    }

    /**
     * @param string               $key
     * @param string               $label
     * @param string               $group
     * @param string               $default
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function url($key, $label, $group, $default = '', array $args = []): array
    {
        return self::make($key, $label, 'url', $group, $default, $args);
    }

    /**
     * @param string               $key
     * @param string               $label
     * @param string               $type
     * @param string               $group
     * @param mixed                $default
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function make($key, $label, $type, $group, $default, array $args = []): array
    {
        $field = [
            'key' => sanitize_key((string) $key),
            'option_key' => sanitize_key((string) ($args['option_key'] ?? $key)),
            'label' => sanitize_text_field((string) $label),
            'type' => sanitize_key((string) $type),
            'group' => sanitize_key((string) $group),
            'default' => $default,
            'default_export' => sanitize_key((string) ($args['default_export'] ?? self::POLICY_PORTABLE)),
            'environment_policy' => sanitize_key((string) ($args['environment_policy'] ?? self::POLICY_PORTABLE)),
            'apply_strategy' => sanitize_key((string) ($args['apply_strategy'] ?? self::STRATEGY_REPLACE)),
            'sensitive' => ! empty($args['sensitive']),
            'requires_confirmation' => ! empty($args['requires_confirmation']),
            'placeholder' => sanitize_text_field((string) ($args['placeholder'] ?? '')),
            'description' => sanitize_text_field((string) ($args['description'] ?? '')),
        ];

        foreach (['allowed', 'min', 'max'] as $optional_key) {
            if (array_key_exists($optional_key, $args)) {
                $field[$optional_key] = $args[$optional_key];
            }
        }

        if (isset($args['option_path']) && is_array($args['option_path'])) {
            $field['option_path'] = array_values(array_filter(array_map(static function ($path_part): string {
                return sanitize_key((string) $path_part);
            }, $args['option_path'])));
        }

        return $field;
    }

    /**
     * @param string               $key
     * @param string               $label
     * @param string               $group
     * @param string               $default
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function bool($key, $label, $group, $default = '0', array $args = []): array
    {
        return self::make($key, $label, 'bool', $group, $default, $args);
    }

    /**
     * @param string               $key
     * @param string               $label
     * @param string               $group
     * @param int                  $default
     * @param int                  $min
     * @param int                  $max
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function integer($key, $label, $group, $default, $min, $max, array $args = []): array
    {
        $args['min'] = $min;
        $args['max'] = $max;

        return self::make($key, $label, 'int', $group, (string) $default, $args);
    }

    /**
     * @param string               $key
     * @param string               $label
     * @param string               $group
     * @param string               $default
     * @param array<int, string>   $allowed
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function select($key, $label, $group, $default, array $allowed, array $args = []): array
    {
        $args['allowed'] = array_values(array_map('sanitize_key', $allowed));

        return self::make($key, $label, 'enum', $group, $default, $args);
    }

    /**
     * @param string               $key
     * @param string               $label
     * @param string               $group
     * @param string               $default
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function path($key, $label, $group, $default = '', array $args = []): array
    {
        return self::make($key, $label, 'path', $group, $default, $args);
    }

    /**
     * @param string               $key
     * @param string               $label
     * @param string               $group
     * @param string               $default
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function file_name($key, $label, $group, $default = '', array $args = []): array
    {
        return self::make($key, $label, 'file_name', $group, $default, $args);
    }

    /**
     * @param string               $key
     * @param string               $label
     * @param string               $group
     * @param string               $default
     * @param float                $min
     * @param float                $max
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function decimal($key, $label, $group, $default, $min, $max, array $args = []): array
    {
        $args['min'] = $min;
        $args['max'] = $max;

        return self::make($key, $label, 'float', $group, $default, $args);
    }

    /**
     * @param string               $key
     * @param string               $label
     * @param string               $group
     * @param array<int, string>   $default
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function key_list($key, $label, $group, array $default = [], array $args = []): array
    {
        return self::make($key, $label, 'key_list', $group, $default, $args);
    }

    /**
     * @param string               $key
     * @param string               $label
     * @param string               $group
     * @param array<int, string>   $default
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function string_list($key, $label, $group, array $default = [], array $args = []): array
    {
        return self::make($key, $label, 'string_list', $group, $default, $args);
    }

    /**
     * @param string               $key
     * @param string               $label
     * @param string               $group
     * @param array<string, mixed> $default
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function map($key, $label, $group, array $default = [], array $args = []): array
    {
        return self::make($key, $label, 'map', $group, $default, $args);
    }
}
