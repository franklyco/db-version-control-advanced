<?php

namespace Dbvc\ConfigurationPortability;

if (! defined('WPINC')) {
    die;
}

interface DomainProviderInterface
{
    /**
     * @return string
     */
    public function get_key(): string;

    /**
     * @return string
     */
    public function get_label(): string;

    /**
     * @return int
     */
    public function get_version(): int;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get_groups(): array;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get_fields(): array;

    /**
     * @return array<string, mixed>
     */
    public function get_current_values(): array;

    /**
     * @param array<string, mixed> $selection
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function export(array $selection, array $context): array;

    /**
     * @param array<string, mixed> $incoming
     * @param array<string, mixed> $current
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function diff(array $incoming, array $current, array $context): array;

    /**
     * @param array<string, mixed> $incoming
     * @param array<string, mixed> $resolved_environment
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    public function sanitize_for_apply(array $incoming, array $resolved_environment, array $current): array;

    /**
     * @param array<string, mixed> $sanitized
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function apply(array $sanitized, array $context): array;

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function capture_backup(array $context): array;

    /**
     * @param array<string, mixed> $backup
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function rollback(array $backup, array $context): array;
}
