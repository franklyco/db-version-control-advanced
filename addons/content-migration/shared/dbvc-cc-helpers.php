<?php

if (! defined('WPINC')) {
    die;
}

if (! function_exists('dbvc_cc_create_security_files')) {
    /**
     * Create baseline security files for runtime storage folders.
     *
     * @param string $directory
     * @return bool
     */
    function dbvc_cc_create_security_files($directory)
    {
        $directory = (string) $directory;
        if ($directory === '') {
            return false;
        }

        if (! is_dir($directory) && ! wp_mkdir_p($directory)) {
            return false;
        }

        $index_file = trailingslashit($directory) . 'index.php';
        if (! file_exists($index_file)) {
            @file_put_contents($index_file, '<?php // Silence is golden.');
        }

        $htaccess_file = trailingslashit($directory) . '.htaccess';
        if (! file_exists($htaccess_file)) {
            @file_put_contents($htaccess_file, "Deny from all\n");
        }

        return true;
    }
}

if (! function_exists('dbvc_cc_path_is_within')) {
    /**
     * Check whether a path is inside a base directory.
     *
     * @param string $path
     * @param string $base
     * @return bool
     */
    function dbvc_cc_path_is_within($path, $base)
    {
        $path = wp_normalize_path((string) $path);
        $base = wp_normalize_path((string) $base);
        if ($path === '' || $base === '') {
            return false;
        }

        return strpos($path, trailingslashit($base)) === 0 || $path === $base;
    }
}

if (! function_exists('dbvc_cc_normalize_relative_path')) {
    /**
     * Normalize a relative content path into DBVC storage-safe segments.
     *
     * @param string $path
     * @return string
     */
    function dbvc_cc_normalize_relative_path($path)
    {
        $value = str_replace('\\', '/', urldecode((string) $path));
        $value = trim($value, '/');
        if ($value === '') {
            return '';
        }

        $parts = [];
        foreach (explode('/', $value) as $part) {
            if ($part === '') {
                continue;
            }

            $normalized_part = sanitize_title($part);
            if ($normalized_part !== '') {
                $parts[] = $normalized_part;
            }
        }

        return implode('/', $parts);
    }
}

if (! function_exists('dbvc_cc_validate_required_relative_path')) {
    /**
     * Validate and normalize a required relative path for runtime requests.
     *
     * @param string $path
     * @param string $required_message
     * @param string $invalid_message
     * @return string|WP_Error
     */
    function dbvc_cc_validate_required_relative_path($path, $required_message = '', $invalid_message = '')
    {
        $required_message = $required_message !== ''
            ? (string) $required_message
            : __('A valid page path is required.', 'dbvc');
        $invalid_message = $invalid_message !== ''
            ? (string) $invalid_message
            : __('Invalid page path.', 'dbvc');

        $value = str_replace('\\', '/', urldecode((string) $path));
        $value = trim($value, '/');
        if ($value === '') {
            return new WP_Error('dbvc_cc_invalid_path', $required_message, ['status' => 400]);
        }

        $parts = [];
        foreach (explode('/', $value) as $part) {
            if ($part === '') {
                continue;
            }

            if ($part === '.' || $part === '..') {
                return new WP_Error('dbvc_cc_invalid_path', $invalid_message, ['status' => 400]);
            }

            $normalized_part = sanitize_title($part);
            if ($normalized_part === '') {
                return new WP_Error('dbvc_cc_invalid_path', $invalid_message, ['status' => 400]);
            }

            $parts[] = $normalized_part;
        }

        $normalized_path = implode('/', $parts);
        if ($normalized_path === '') {
            return new WP_Error('dbvc_cc_invalid_path', $invalid_message, ['status' => 400]);
        }

        return $normalized_path;
    }
}

if (! function_exists('dbvc_cc_css_to_xpath')) {
    /**
     * Convert a basic CSS selector list into XPath.
     *
     * @param string $selectors_string
     * @return string
     */
    function dbvc_cc_css_to_xpath($selectors_string)
    {
        $selectors = array_map('trim', explode(',', (string) $selectors_string));
        $xpath_parts = [];

        foreach ($selectors as $selector) {
            if ($selector === '') {
                continue;
            }

            if (strpos($selector, '#') === 0) {
                $xpath_parts[] = "//*[@id='" . substr($selector, 1) . "']";
            } elseif (strpos($selector, '.') === 0) {
                $xpath_parts[] = "//*[contains(concat(' ', normalize-space(@class), ' '), ' " . substr($selector, 1) . " ')]";
            } else {
                $xpath_parts[] = '//' . $selector;
            }
        }

        return implode(' | ', $xpath_parts);
    }
}

if (! function_exists('dbvc_cc_convert_to_absolute_url')) {
    /**
     * Convert relative URL values to absolute.
     *
     * @param string $relative_url
     * @param string $base_url
     * @return string
     */
    function dbvc_cc_convert_to_absolute_url($relative_url, $base_url)
    {
        $relative_url = (string) $relative_url;
        $base_url = (string) $base_url;

        if (filter_var($relative_url, FILTER_VALIDATE_URL)) {
            return $relative_url;
        }

        $base = wp_parse_url($base_url);
        if (! is_array($base) || ! isset($base['scheme'], $base['host'])) {
            return $relative_url;
        }

        if (strpos($relative_url, '//') === 0) {
            return $base['scheme'] . ':' . $relative_url;
        }

        if ($relative_url !== '' && $relative_url[0] === '/') {
            return $base['scheme'] . '://' . $base['host'] . $relative_url;
        }

        $path = dirname(isset($base['path']) ? (string) $base['path'] : '');
        $prefix = $path === '/' ? '' : $path;

        return $base['scheme'] . '://' . $base['host'] . $prefix . '/' . $relative_url;
    }
}

if (! function_exists('dbvc_cc_get_extension_from_mime')) {
    /**
     * Resolve file extension from MIME.
     *
     * @param string $mime_type
     * @return string|null
     */
    function dbvc_cc_get_extension_from_mime($mime_type)
    {
        $mime_map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        ];

        $mime_type = (string) $mime_type;
        return isset($mime_map[$mime_type]) ? $mime_map[$mime_type] : null;
    }
}
