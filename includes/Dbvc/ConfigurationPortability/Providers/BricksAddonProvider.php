<?php

namespace Dbvc\ConfigurationPortability\Providers;

use Dbvc\ConfigurationPortability\AbstractOptionDomainProvider;
use Dbvc\ConfigurationPortability\Field;

if (! defined('WPINC')) {
    die;
}

final class BricksAddonProvider extends AbstractOptionDomainProvider
{
    public function get_key(): string
    {
        return 'bricks_addon';
    }

    public function get_label(): string
    {
        return __('Bricks Add-on', 'dbvc');
    }

    public function get_version(): int
    {
        return 1;
    }

    public function get_groups(): array
    {
        return class_exists('DBVC_Bricks_Addon') ? \DBVC_Bricks_Addon::get_settings_groups() : [];
    }

    public function get_fields(): array
    {
        if (! class_exists('DBVC_Bricks_Addon')) {
            return [];
        }

        $schema = \DBVC_Bricks_Addon::get_settings_schema();
        $meta = \DBVC_Bricks_Addon::get_field_meta();
        $groups = \DBVC_Bricks_Addon::get_settings_groups();
        $field_groups = [];
        foreach ($groups as $group_key => $group) {
            foreach ((array) ($group['fields'] ?? []) as $field_key) {
                $field_groups[(string) $field_key] = (string) $group_key;
            }
        }

        $fields = [];
        foreach ($schema as $field_key => $field_schema) {
            $field_key = (string) $field_key;
            $type = (string) ($field_schema['type'] ?? 'text');
            $args = [];
            if (isset($field_schema['allowed']) && is_array($field_schema['allowed'])) {
                $args['allowed'] = array_values(array_map('sanitize_key', $field_schema['allowed']));
            }
            if (isset($field_schema['min'])) {
                $args['min'] = (int) $field_schema['min'];
            }
            if (isset($field_schema['max'])) {
                $args['max'] = (int) $field_schema['max'];
            }

            $policy = $this->get_field_policy($field_key, $type);
            $args = array_merge($args, $policy);

            $fields[$field_key] = Field::make(
                $field_key,
                (string) ($meta[$field_key]['label'] ?? $field_key),
                $type,
                $field_groups[$field_key] ?? 'general',
                (string) ($field_schema['default'] ?? ''),
                $args
            );
        }

        return $fields;
    }

    public function apply(array $sanitized, array $context): array
    {
        unset($context);

        if (! class_exists('DBVC_Bricks_Addon')) {
            return [
                'domain' => $this->get_key(),
                'applied' => [],
                'skipped' => ['bricks_addon' => 'unavailable'],
            ];
        }

        $values = isset($sanitized['values']) && is_array($sanitized['values']) ? $sanitized['values'] : [];
        if (empty($values)) {
            return [
                'domain' => $this->get_key(),
                'applied' => [],
                'skipped' => [],
            ];
        }

        $fields = $this->get_fields();
        $applied = [];
        $skipped = [];
        foreach ($values as $field_key => $value) {
            if (! isset($fields[$field_key])) {
                $skipped[$field_key] = 'unknown_field';
                continue;
            }

            update_option($field_key, $value);
            $applied[$field_key] = true;
        }

        \DBVC_Bricks_Addon::refresh_runtime_registration();

        return [
            'domain' => $this->get_key(),
            'applied' => $applied,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param string $field_key
     * @param string $type
     * @return array<string, mixed>
     */
    private function get_field_policy($field_key, $type): array
    {
        $secret_fields = [
            'dbvc_bricks_api_secret',
            'dbvc_bricks_intro_handshake_token',
        ];
        $prompt_fields = [
            'dbvc_bricks_site_uid',
            'dbvc_bricks_mothership_url',
            'dbvc_bricks_api_key_id',
        ];
        $excluded_runtime_fields = [
            'dbvc_bricks_credentials_updated_at',
            'dbvc_bricks_client_registry_state',
        ];

        if (in_array($field_key, $secret_fields, true) || $type === 'secret') {
            return [
                'default_export' => Field::POLICY_EXCLUDE,
                'environment_policy' => Field::POLICY_EXCLUDE,
                'apply_strategy' => Field::STRATEGY_KEEP_EXISTING_UNLESS_SUPPLIED,
                'sensitive' => true,
                'requires_confirmation' => true,
            ];
        }

        if (in_array($field_key, $prompt_fields, true)) {
            return [
                'default_export' => Field::POLICY_PROMPT,
                'environment_policy' => Field::POLICY_PROMPT,
                'apply_strategy' => Field::STRATEGY_KEEP_EXISTING_UNLESS_SUPPLIED,
                'placeholder' => '${' . strtoupper($field_key) . '}',
                'requires_confirmation' => true,
            ];
        }

        if (in_array($field_key, $excluded_runtime_fields, true)) {
            return [
                'default_export' => Field::POLICY_EXCLUDE,
                'environment_policy' => Field::POLICY_EXCLUDE,
                'apply_strategy' => Field::STRATEGY_KEEP_EXISTING_UNLESS_SUPPLIED,
            ];
        }

        return [];
    }
}
