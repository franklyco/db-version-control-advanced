<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class ValidationReportFormatter
{
    /**
     * @param array<string,mixed> $report
     * @return string
     */
    public static function build_markdown_summary(array $report): string
    {
        $counts = isset($report['counts']) && is_array($report['counts']) ? $report['counts'] : [];
        $issues = isset($report['issues']) && is_array($report['issues']) ? $report['issues'] : [];
        $artifacts = isset($report['artifacts']) && is_array($report['artifacts']) ? $report['artifacts'] : [];

        $lines = [
            '# DBVC AI Intake Validation Summary',
            '',
            '- Intake ID: `' . (string) ($report['intake_id'] ?? '') . '`',
            '- Status: `' . (string) ($report['status'] ?? 'blocked') . '`',
            '- Package type: `' . (string) ($report['package_type'] ?? '') . '`',
            '- Package schema version: `' . (string) ($report['package_schema_version'] ?? '') . '`',
            '- Post entities: `' . (int) ($counts['post_entities'] ?? 0) . '`',
            '- Term entities: `' . (int) ($counts['term_entities'] ?? 0) . '`',
            '- Translated entities: `' . (int) ($counts['translated_entities'] ?? 0) . '`',
            '- Warnings: `' . (int) ($counts['warnings'] ?? 0) . '`',
            '- Blocked issues: `' . (int) ($counts['blocked'] ?? 0) . '`',
            '',
            '## Artifacts',
            '',
            '- Source archive: `' . (string) ($artifacts['source_archive'] ?? '') . '`',
            '- Extracted root: `' . (string) ($artifacts['extracted_root'] ?? '') . '`',
            '- Validation report: `' . (string) ($artifacts['validation_report'] ?? '') . '`',
            '- Validation summary: `' . (string) ($artifacts['validation_summary'] ?? '') . '`',
            '- Translation manifest: `' . (string) ($artifacts['translation_manifest'] ?? '') . '`',
            '- Translated sync root: `' . (string) ($artifacts['translated_sync_root'] ?? '') . '`',
            '',
            '## Issues',
            '',
        ];

        if (empty($issues)) {
            $lines[] = '- No issues detected.';
        } else {
            $groups = IssueService::group_for_display($issues);

            foreach ($groups as $group) {
                if (! is_array($group)) {
                    continue;
                }

                $label = isset($group['label']) ? (string) $group['label'] : __('Issue group', 'dbvc');
                $counts = isset($group['counts']) && is_array($group['counts']) ? $group['counts'] : [];
                $lines[] = '### ' . $label;
                $lines[] = '';
                $lines[] = '- Errors: `' . (int) ($counts['error'] ?? 0) . '`';
                $lines[] = '- Warnings: `' . (int) ($counts['warning'] ?? 0) . '`';
                $lines[] = '';

                foreach ((array) ($group['issues'] ?? []) as $issue) {
                    if (! is_array($issue)) {
                        continue;
                    }

                    $line = '- [' . (string) ($issue['severity'] ?? 'warning') . '] `' . (string) ($issue['code'] ?? 'unknown') . '`';
                    if (! empty($issue['path'])) {
                        $line .= ' at `' . (string) $issue['path'] . '`';
                    }
                    $line .= ': ' . (string) ($issue['message'] ?? '');
                    $lines[] = $line;
                }

                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }
}
