<?php

if (! defined('WPINC')) {
    die;
}

if (! function_exists('dbvc_cc_guard_no_source_runtime_imports')) {
    /**
     * Fail fast when runtime code includes source-reference files.
     *
     * @param string $stage Execution checkpoint for diagnostics.
     * @return void
     */
    function dbvc_cc_guard_no_source_runtime_imports($stage = 'unknown')
    {
        if (! defined('DBVC_PLUGIN_PATH')) {
            return;
        }

        $source_root = wp_normalize_path(DBVC_PLUGIN_PATH . '_source/content-collector/');
        foreach (get_included_files() as $included_file) {
            $normalized_file = wp_normalize_path((string) $included_file);
            if (strpos($normalized_file, $source_root) !== 0) {
                continue;
            }

            $stage_value = sanitize_text_field((string) $stage);
            $message = sprintf(
                'DBVC runtime guard violation: source-reference file was loaded from _source/content-collector during %s. File: %s',
                $stage_value,
                $normalized_file
            );

            if (function_exists('error_log')) {
                error_log($message);
            }

            if (defined('WP_CLI') && WP_CLI) {
                fwrite(STDERR, $message . PHP_EOL);
                exit(1);
            }

            wp_die(
                esc_html($message),
                esc_html__('DBVC Runtime Guard', 'dbvc'),
                ['response' => 500]
            );
        }
    }
}
