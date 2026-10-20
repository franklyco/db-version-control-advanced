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
        if (! file_exists($htaccess_file) || dbvc_cc_htaccess_needs_upgrade($htaccess_file)) {
            @file_put_contents($htaccess_file, dbvc_cc_storage_htaccess_contents());
        }

        return true;
    }
}

if (! function_exists('dbvc_cc_storage_htaccess_contents')) {
    /**
     * Deny-all directives for a storage directory, for both supported Apache generations.
     *
     * `Deny from all` alone is Apache 2.2 syntax. On Apache 2.4 it is understood only while
     * mod_access_compat is loaded, and that module is not guaranteed - so a single-line file can
     * leave crawled third-party content and built package archives readable over HTTP, because
     * this storage lives under the uploads directory.
     *
     * Neither generation of this file does anything on nginx, which never reads `.htaccess`. Use
     * dbvc_cc_storage_http_exposure_probe() to find out whether the directory is actually
     * reachable rather than assuming either way.
     *
     * @return string
     */
    function dbvc_cc_storage_htaccess_contents()
    {
        return implode("\n", [
            '# DBVC Content Migration storage. Generated; edits are overwritten.',
            '# Apache 2.4 and newer.',
            '<IfModule mod_authz_core.c>',
            '    Require all denied',
            '</IfModule>',
            '# Apache 2.2, and 2.4 with mod_access_compat.',
            '<IfModule !mod_authz_core.c>',
            '    Order allow,deny',
            '    Deny from all',
            '</IfModule>',
            '',
        ]);
    }
}

if (! function_exists('dbvc_cc_htaccess_needs_upgrade')) {
    /**
     * Is an existing .htaccess the old Apache-2.2-only one-liner?
     *
     * Existing installations already carry the weaker file, and a plain file_exists() check would
     * never replace it.
     *
     * @param string $htaccess_file
     * @return bool
     */
    function dbvc_cc_htaccess_needs_upgrade($htaccess_file)
    {
        $contents = @file_get_contents($htaccess_file);
        if (! is_string($contents)) {
            return false;
        }

        return strpos($contents, 'Require all denied') === false;
    }
}

if (! function_exists('dbvc_cc_storage_http_exposure_probe')) {
    /**
     * Ask the server whether a storage path is readable over HTTP.
     *
     * Deliberately explicit rather than automatic: it makes a loopback request, so it belongs to
     * an operator action or a test, not to every page load. A truthful answer matters because the
     * `.htaccess` above is inert on nginx, and "we wrote a deny file" is not the same claim as
     * "the directory is not reachable".
     *
     * @param string $relative_path Path inside the storage root, e.g. a known artifact file.
     * @return array<string, mixed> {exposed: bool|null, status: int|null, url: string, reason: string}
     */
    function dbvc_cc_storage_http_exposure_probe($relative_path = 'index.php')
    {
        $upload = wp_upload_dir();
        $baseurl = isset($upload['baseurl']) ? (string) $upload['baseurl'] : '';
        if ($baseurl === '' || ! class_exists('DBVC_CC_Settings_Service')) {
            return ['exposed' => null, 'status' => null, 'url' => '', 'reason' => 'storage_url_unknown'];
        }

        $options = DBVC_CC_Settings_Service::get_options();
        $storage_path = isset($options['storage_path']) ? sanitize_file_name((string) $options['storage_path']) : '';
        if ($storage_path === '') {
            return ['exposed' => null, 'status' => null, 'url' => '', 'reason' => 'storage_path_unknown'];
        }

        $url = trailingslashit($baseurl) . $storage_path . '/' . ltrim((string) $relative_path, '/');
        $response = wp_remote_get($url, ['timeout' => 10, 'redirection' => 0]);
        if (is_wp_error($response)) {
            return ['exposed' => null, 'status' => null, 'url' => $url, 'reason' => $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        return [
            'exposed' => $status === 200,
            'status' => $status,
            'url' => $url,
            'reason' => $status === 200 ? 'reachable_over_http' : 'not_reachable',
        ];
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

if (! function_exists('dbvc_cc_heading_texts')) {
    /**
     * Flatten a page artifact's heading list to plain strings.
     *
     * `page-artifact.v1#headings[]` carries two shapes. V2's own capture emitted flat strings and
     * threw the level away, which is D-7. The acquisition skill emits `{level, text}` and keeps
     * it. Consumers that only want the words must not care which producer ran, and must never
     * cast an entry to string blindly: `(string) ['level' => 1, ...]` yields the literal "Array"
     * and a PHP notice, which is how a levelled heading quietly became one bogus heading in both
     * source normalization and initial classification.
     *
     * The level is not discarded here — it stays in the stored artifact. This is only for the
     * consumers that want text.
     *
     * @param mixed $headings
     * @return array<int, string>
     */
    function dbvc_cc_heading_texts($headings)
    {
        if (! is_array($headings)) {
            return [];
        }

        $texts = [];
        foreach ($headings as $heading) {
            if (is_array($heading)) {
                $heading = isset($heading['text']) ? $heading['text'] : '';
            }

            if (! is_scalar($heading)) {
                continue;
            }

            $text = trim((string) $heading);
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }
}
