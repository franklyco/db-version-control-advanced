<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class ImportReportFormatter
{
    /**
     * @param array<string,mixed> $report
     * @return string
     */
    public static function build_markdown_summary(array $report): string
    {
        $posts = isset($report['posts']) && is_array($report['posts']) ? $report['posts'] : [];
        $terms = isset($report['terms']) && is_array($report['terms']) ? $report['terms'] : [];
        $relationship = isset($report['relationship_resolution']) && is_array($report['relationship_resolution'])
            ? $report['relationship_resolution']
            : [];
        $artifacts = isset($report['artifacts']) && is_array($report['artifacts']) ? $report['artifacts'] : [];
        $source = isset($report['source_report']) && is_array($report['source_report']) ? $report['source_report'] : [];
        $artifact_errors = isset($report['artifact_errors']) && is_array($report['artifact_errors']) ? $report['artifact_errors'] : [];

        $lines = [
            '# DBVC AI Intake Import Summary',
            '',
            '- Intake ID: `' . (string) ($report['intake_id'] ?? '') . '`',
            '- Imported at: `' . (string) ($report['imported_at'] ?? '') . '`',
            '- Status: `' . (string) ($report['status'] ?? 'completed') . '`',
            '- Source validation status: `' . (string) ($source['validation_status'] ?? '') . '`',
            '- Source package type: `' . (string) ($source['package_type'] ?? '') . '`',
            '- Source package schema version: `' . (string) ($source['package_schema_version'] ?? '') . '`',
            '',
            '## Import Counts',
            '',
            '- Posts requested: `' . (int) ($posts['requested'] ?? 0) . '`',
            '- Posts applied: `' . (int) ($posts['applied'] ?? 0) . '`',
            '- Posts errors: `' . (int) ($posts['errors'] ?? 0) . '`',
            '- Terms requested: `' . (int) ($terms['requested'] ?? 0) . '`',
            '- Terms applied: `' . (int) ($terms['applied'] ?? 0) . '`',
            '- Terms errors: `' . (int) ($terms['errors'] ?? 0) . '`',
            '- Relationship operations processed: `' . (int) ($relationship['processed'] ?? 0) . '`',
            '- Relationship operations applied: `' . (int) ($relationship['applied'] ?? 0) . '`',
            '- Relationship warnings: `' . (int) ($relationship['warnings'] ?? 0) . '`',
            '- Relationship errors: `' . (int) ($relationship['errors'] ?? 0) . '`',
            '',
            '## Artifacts',
            '',
            '- Import report: `' . (string) ($artifacts['import_report'] ?? '') . '`',
            '- Import summary: `' . (string) ($artifacts['import_summary'] ?? '') . '`',
            '',
        ];

        if (! empty($artifact_errors)) {
            $lines[] = '## Artifact Notes';
            $lines[] = '';
            foreach ($artifact_errors as $message) {
                if (! is_scalar($message)) {
                    continue;
                }
                $lines[] = '- ' . (string) $message;
            }
            $lines[] = '';
        }

        self::append_detail_block($lines, __('Post import details', 'dbvc'), $posts['details'] ?? [], ['file', 'status', 'message', 'post_id']);
        self::append_detail_block($lines, __('Term import details', 'dbvc'), $terms['details'] ?? [], ['file', 'status', 'message', 'term_id']);
        self::append_detail_block($lines, __('Relationship resolution details', 'dbvc'), $relationship['details'] ?? [], ['family', 'status', 'message', 'path', 'field_name']);

        return implode("\n", $lines);
    }

    /**
     * @param array<int,string>   $lines
     * @param string              $heading
     * @param mixed               $details
     * @param array<int,string>   $preferred_keys
     * @return void
     */
    private static function append_detail_block(array &$lines, string $heading, $details, array $preferred_keys): void
    {
        $details = is_array($details) ? array_values($details) : [];
        $lines[] = '## ' . $heading;
        $lines[] = '';

        if (empty($details)) {
            $lines[] = '- None.';
            $lines[] = '';
            return;
        }

        foreach ($details as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            $parts = [];
            foreach ($preferred_keys as $key) {
                if (! array_key_exists($key, $detail) || $detail[$key] === '' || $detail[$key] === null) {
                    continue;
                }

                $value = is_scalar($detail[$key]) ? (string) $detail[$key] : wp_json_encode($detail[$key], JSON_UNESCAPED_SLASHES);
                if (! is_string($value) || $value === '') {
                    continue;
                }

                $parts[] = $key . '=' . $value;
            }

            foreach ($detail as $key => $value) {
                if (in_array($key, $preferred_keys, true) || $value === '' || $value === null) {
                    continue;
                }

                if (is_scalar($value)) {
                    $parts[] = (string) $key . '=' . (string) $value;
                }
            }

            if (empty($parts)) {
                continue;
            }

            $lines[] = '- ' . implode(' | ', $parts);
        }

        $lines[] = '';
    }
}
