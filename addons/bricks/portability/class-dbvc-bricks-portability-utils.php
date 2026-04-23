<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Portability_Utils
{
    /**
     * @param string $prefix
     * @return string
     */
    public static function generate_id($prefix)
    {
        $prefix = sanitize_key((string) $prefix);
        if ($prefix === '') {
            $prefix = 'dbvc';
        }

        try {
            $random = bin2hex(random_bytes(6));
        } catch (\Throwable $e) {
            unset($e);
            $random = wp_generate_password(12, false, false);
        }

        return $prefix . '-' . gmdate('YmdHis') . '-' . strtolower(sanitize_key($random));
    }

    /**
     * @param mixed $value
     * @param bool $preserve_numeric_order
     * @return mixed
     */
    public static function deep_sort($value, $preserve_numeric_order = true)
    {
        if (! is_array($value)) {
            return $value;
        }

        $preserve_numeric_order = (bool) $preserve_numeric_order;

        if (self::is_assoc($value)) {
            ksort($value, SORT_STRING);
        } elseif (! $preserve_numeric_order) {
            sort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::deep_sort($item, $preserve_numeric_order);
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function fingerprint($value)
    {
        $encoded = self::json_encode($value);
        return 'sha256:' . hash('sha256', $encoded);
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function json_encode($value)
    {
        $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '{}';
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public static function is_assoc($value)
    {
        if (! is_array($value)) {
            return false;
        }

        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    public static function sanitize_string_list($value)
    {
        if (! is_array($value)) {
            return [];
        }

        $clean = [];
        foreach ($value as $item) {
            $item = sanitize_text_field((string) $item);
            if ($item === '') {
                continue;
            }
            $clean[] = $item;
        }

        return array_values(array_unique($clean));
    }

    /**
     * @param mixed $path
     * @return array<int, string>
     */
    public static function sanitize_path_segments($path)
    {
        if (! is_array($path)) {
            return [];
        }

        $segments = [];
        foreach ($path as $segment) {
            $segments[] = sanitize_text_field((string) $segment);
        }

        return $segments;
    }

    /**
     * @param mixed $left
     * @param mixed $right
     * @return array<string, array<int, string>>
     */
    public static function diff_paths($left, $right)
    {
        $summary = [
            'changed' => [],
            'added' => [],
            'removed' => [],
        ];

        self::diff_paths_recursive($left, $right, [], $summary);

        foreach ($summary as $bucket => $paths) {
            $summary[$bucket] = array_values(array_unique($paths));
            sort($summary[$bucket], SORT_STRING);
        }

        return $summary;
    }

    /**
     * @param mixed $left
     * @param mixed $right
     * @param array<int, string> $segments
     * @param array<string, array<int, string>> $summary
     * @return void
     */
    private static function diff_paths_recursive($left, $right, array $segments, array &$summary)
    {
        if (is_array($left) && is_array($right)) {
            $keys = array_values(array_unique(array_merge(array_keys($left), array_keys($right))));
            foreach ($keys as $key) {
                $segment = is_int($key) ? (string) $key : sanitize_text_field((string) $key);
                $path_segments = $segments;
                $path_segments[] = $segment;

                $left_exists = array_key_exists($key, $left);
                $right_exists = array_key_exists($key, $right);
                if (! $left_exists && $right_exists) {
                    $summary['added'][] = implode('.', $path_segments);
                    continue;
                }
                if ($left_exists && ! $right_exists) {
                    $summary['removed'][] = implode('.', $path_segments);
                    continue;
                }

                self::diff_paths_recursive($left[$key], $right[$key], $path_segments, $summary);
            }

            return;
        }

        if ($left !== $right) {
            $summary['changed'][] = implode('.', $segments);
        }
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    public static function extract_css_variable_tokens($value)
    {
        $text = is_scalar($value) ? (string) $value : self::json_encode($value);
        if ($text === '') {
            return [];
        }

        preg_match_all('/--[A-Za-z0-9_-]+/', $text, $matches);
        return isset($matches[0]) ? array_values(array_unique(array_map('sanitize_text_field', (array) $matches[0]))) : [];
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function normalize_string($value)
    {
        return trim(sanitize_text_field((string) $value));
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_site_context()
    {
        $theme = wp_get_theme();
        $current_user = wp_get_current_user();

        return [
            'site_name' => get_bloginfo('name'),
            'home_url' => home_url('/'),
            'blog_id' => get_current_blog_id(),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'dbvc_version' => defined('DBVC_PLUGIN_VERSION') ? (string) DBVC_PLUGIN_VERSION : '',
            'bricks_version' => defined('BRICKS_VERSION') ? (string) BRICKS_VERSION : '',
            'theme' => $theme instanceof \WP_Theme ? (string) $theme->get('Name') : '',
            'export_user_id' => get_current_user_id(),
            'export_user_label' => $current_user instanceof \WP_User ? (string) $current_user->display_name : '',
        ];
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>
     */
    public static function normalize_job_context($raw)
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
