<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class IssueService
{
    /**
     * @param string              $severity
     * @param string              $code
     * @param string              $message
     * @param string              $path
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function build(string $severity, string $code, string $message, string $path = '', array $context = []): array
    {
        $normalized = self::normalize_issue([
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'path' => $path,
            'stage' => isset($context['stage']) ? (string) $context['stage'] : 'validation',
            'scope' => isset($context['scope']) && is_array($context['scope']) ? $context['scope'] : [],
        ]);

        return $normalized;
    }

    /**
     * @param array<string,mixed> $issue
     * @return array<string,mixed>
     */
    public static function normalize_issue(array $issue): array
    {
        $path = isset($issue['path']) ? (string) $issue['path'] : '';
        $stage = sanitize_key((string) ($issue['stage'] ?? 'validation'));
        if ($stage === '') {
            $stage = 'validation';
        }

        $scope = isset($issue['scope']) && is_array($issue['scope']) ? $issue['scope'] : [];
        $scope = self::derive_scope($path, $scope, $stage);

        return [
            'severity' => (($issue['severity'] ?? '') === 'warning') ? 'warning' : 'error',
            'code' => sanitize_key((string) ($issue['code'] ?? 'unknown')),
            'message' => (string) ($issue['message'] ?? ''),
            'path' => $path,
            'stage' => $stage,
            'scope' => $scope,
        ];
    }

    /**
     * @param array<int,mixed> $issues
     * @return array<int,array<string,mixed>>
     */
    public static function group_for_display(array $issues): array
    {
        $groups = [];

        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                continue;
            }

            $normalized = self::normalize_issue($issue);
            $scope = isset($normalized['scope']) && is_array($normalized['scope']) ? $normalized['scope'] : [];
            $group_key = isset($scope['group_key']) ? (string) $scope['group_key'] : 'package:general';

            if (! isset($groups[$group_key])) {
                $groups[$group_key] = [
                    'key' => $group_key,
                    'level' => (string) ($scope['level'] ?? 'package'),
                    'label' => (string) ($scope['group_label'] ?? __('Package', 'dbvc')),
                    'stage' => (string) ($normalized['stage'] ?? 'validation'),
                    'file_path' => (string) ($scope['file_path'] ?? ''),
                    'field_path' => (string) ($scope['field_path'] ?? ''),
                    'entity_kind' => (string) ($scope['entity_kind'] ?? ''),
                    'object_key' => (string) ($scope['object_key'] ?? ''),
                    'entity_slug' => (string) ($scope['entity_slug'] ?? ''),
                    'issues' => [],
                    'counts' => [
                        'warning' => 0,
                        'error' => 0,
                    ],
                ];
            }

            $groups[$group_key]['issues'][] = $normalized;
            $severity = (string) ($normalized['severity'] ?? 'warning');
            if (! isset($groups[$group_key]['counts'][$severity])) {
                $groups[$group_key]['counts'][$severity] = 0;
            }
            $groups[$group_key]['counts'][$severity]++;
        }

        foreach ($groups as &$group) {
            usort($group['issues'], [self::class, 'compare_issues']);
        }
        unset($group);

        uasort($groups, [self::class, 'compare_groups']);

        return array_values($groups);
    }

    /**
     * @param array<string,mixed> $issue
     * @return string
     */
    public static function get_scope_label(array $issue): string
    {
        $normalized = self::normalize_issue($issue);
        $scope = isset($normalized['scope']) && is_array($normalized['scope']) ? $normalized['scope'] : [];

        return isset($scope['group_label']) ? (string) $scope['group_label'] : __('Package', 'dbvc');
    }

    /**
     * @param string              $path
     * @param array<string,mixed> $existing_scope
     * @param string              $stage
     * @return array<string,string>
     */
    private static function derive_scope(string $path, array $existing_scope, string $stage): array
    {
        $scope = [
            'level' => isset($existing_scope['level']) ? sanitize_key((string) $existing_scope['level']) : '',
            'group_key' => isset($existing_scope['group_key']) ? (string) $existing_scope['group_key'] : '',
            'group_label' => isset($existing_scope['group_label']) ? (string) $existing_scope['group_label'] : '',
            'file_path' => isset($existing_scope['file_path']) ? (string) $existing_scope['file_path'] : '',
            'field_path' => isset($existing_scope['field_path']) ? (string) $existing_scope['field_path'] : '',
            'entity_kind' => isset($existing_scope['entity_kind']) ? sanitize_key((string) $existing_scope['entity_kind']) : '',
            'object_key' => isset($existing_scope['object_key']) ? sanitize_key((string) $existing_scope['object_key']) : '',
            'entity_slug' => isset($existing_scope['entity_slug']) ? sanitize_title((string) $existing_scope['entity_slug']) : '',
        ];

        $path_parts = self::split_path_and_fragment($path);
        $file_path = $scope['file_path'] !== '' ? $scope['file_path'] : $path_parts['path'];
        $field_path = $scope['field_path'] !== '' ? $scope['field_path'] : $path_parts['fragment'];

        if ($file_path !== '' && preg_match('#^entities/posts/([^/]+)/([^/]+)\.json$#', $file_path, $matches)) {
            if ($scope['entity_kind'] === '') {
                $scope['entity_kind'] = 'post';
            }
            if ($scope['object_key'] === '') {
                $scope['object_key'] = sanitize_key($matches[1]);
            }
            if ($scope['entity_slug'] === '') {
                $scope['entity_slug'] = sanitize_title($matches[2]);
            }
        } elseif ($file_path !== '' && preg_match('#^entities/terms/([^/]+)/([^/]+)\.json$#', $file_path, $matches)) {
            if ($scope['entity_kind'] === '') {
                $scope['entity_kind'] = 'term';
            }
            if ($scope['object_key'] === '') {
                $scope['object_key'] = sanitize_key($matches[1]);
            }
            if ($scope['entity_slug'] === '') {
                $scope['entity_slug'] = sanitize_title($matches[2]);
            }
        }

        $scope['file_path'] = $file_path;
        $scope['field_path'] = $field_path;

        if ($scope['level'] === '') {
            if ($scope['field_path'] !== '') {
                $scope['level'] = 'field';
            } elseif ($scope['entity_kind'] !== '' && $scope['object_key'] !== '' && $scope['entity_slug'] !== '') {
                $scope['level'] = 'entity';
            } elseif ($scope['file_path'] !== '') {
                $scope['level'] = 'file';
            } else {
                $scope['level'] = 'package';
            }
        }

        if ($scope['group_key'] === '') {
            $scope['group_key'] = self::build_group_key($stage, $scope);
        }

        if ($scope['group_label'] === '') {
            $scope['group_label'] = self::build_group_label($stage, $scope);
        }

        return $scope;
    }

    /**
     * @param string              $stage
     * @param array<string,string> $scope
     * @return string
     */
    private static function build_group_key(string $stage, array $scope): string
    {
        $parts = [$stage];

        if (($scope['level'] ?? '') === 'field') {
            $parts[] = 'field';
        } elseif (($scope['level'] ?? '') === 'entity') {
            $parts[] = 'entity';
        } elseif (($scope['level'] ?? '') === 'file') {
            $parts[] = 'file';
        } else {
            $parts[] = 'package';
        }

        foreach (['entity_kind', 'object_key', 'entity_slug', 'file_path', 'field_path'] as $key) {
            if (! empty($scope[$key])) {
                $parts[] = (string) $scope[$key];
            }
        }

        return implode(':', $parts);
    }

    /**
     * @param string               $stage
     * @param array<string,string> $scope
     * @return string
     */
    private static function build_group_label(string $stage, array $scope): string
    {
        $stage_label = $stage === 'translation' ? __('Translation', 'dbvc') : __('Validation', 'dbvc');
        $level = $scope['level'] ?? 'package';
        $entity_label = self::build_entity_label($scope);

        if ($level === 'field' && ! empty($scope['field_path'])) {
            if ($entity_label !== '') {
                return sprintf(
                    /* translators: 1: stage label 2: field path 3: entity label */
                    __('%1$s field `%2$s` in %3$s', 'dbvc'),
                    $stage_label,
                    $scope['field_path'],
                    $entity_label
                );
            }

            return sprintf(
                /* translators: 1: stage label 2: field path */
                __('%1$s field `%2$s`', 'dbvc'),
                $stage_label,
                $scope['field_path']
            );
        }

        if ($level === 'entity' && $entity_label !== '') {
            return sprintf(
                /* translators: 1: stage label 2: entity label */
                __('%1$s %2$s', 'dbvc'),
                $stage_label,
                $entity_label
            );
        }

        if (! empty($scope['file_path'])) {
            return sprintf(
                /* translators: 1: stage label 2: file path */
                __('%1$s file `%2$s`', 'dbvc'),
                $stage_label,
                $scope['file_path']
            );
        }

        return sprintf(
            /* translators: %s: stage label */
            __('%s package', 'dbvc'),
            $stage_label
        );
    }

    /**
     * @param array<string,string> $scope
     * @return string
     */
    private static function build_entity_label(array $scope): string
    {
        $entity_kind = isset($scope['entity_kind']) ? (string) $scope['entity_kind'] : '';
        $object_key = isset($scope['object_key']) ? (string) $scope['object_key'] : '';
        $entity_slug = isset($scope['entity_slug']) ? (string) $scope['entity_slug'] : '';

        if ($entity_kind === '' || $object_key === '' || $entity_slug === '') {
            return '';
        }

        if ($entity_kind === 'term') {
            return sprintf(
                /* translators: 1: taxonomy 2: term slug */
                __('term `%1$s/%2$s`', 'dbvc'),
                $object_key,
                $entity_slug
            );
        }

        return sprintf(
            /* translators: 1: post type 2: post slug */
            __('post `%1$s/%2$s`', 'dbvc'),
            $object_key,
            $entity_slug
        );
    }

    /**
     * @param string $path
     * @return array{path:string,fragment:string}
     */
    private static function split_path_and_fragment(string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            return [
                'path' => '',
                'fragment' => '',
            ];
        }

        $parts = explode('#', $path, 2);

        return [
            'path' => (string) ($parts[0] ?? ''),
            'fragment' => (string) ($parts[1] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return int
     */
    private static function compare_groups(array $left, array $right): int
    {
        $stage_order = [
            'validation' => 0,
            'translation' => 1,
        ];
        $level_order = [
            'package' => 0,
            'file' => 1,
            'entity' => 2,
            'field' => 3,
        ];

        $left_stage = $stage_order[(string) ($left['stage'] ?? 'validation')] ?? 9;
        $right_stage = $stage_order[(string) ($right['stage'] ?? 'validation')] ?? 9;
        if ($left_stage !== $right_stage) {
            return $left_stage <=> $right_stage;
        }

        $left_level = $level_order[(string) ($left['level'] ?? 'package')] ?? 9;
        $right_level = $level_order[(string) ($right['level'] ?? 'package')] ?? 9;
        if ($left_level !== $right_level) {
            return $left_level <=> $right_level;
        }

        return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return int
     */
    private static function compare_issues(array $left, array $right): int
    {
        $severity_order = [
            'error' => 0,
            'warning' => 1,
        ];
        $left_severity = $severity_order[(string) ($left['severity'] ?? 'warning')] ?? 9;
        $right_severity = $severity_order[(string) ($right['severity'] ?? 'warning')] ?? 9;

        if ($left_severity !== $right_severity) {
            return $left_severity <=> $right_severity;
        }

        $code_compare = strcasecmp((string) ($left['code'] ?? ''), (string) ($right['code'] ?? ''));
        if ($code_compare !== 0) {
            return $code_compare;
        }

        return strcasecmp((string) ($left['path'] ?? ''), (string) ($right['path'] ?? ''));
    }
}
