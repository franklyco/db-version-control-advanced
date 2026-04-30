<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Portability_Registry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_option_registry()
    {
        return [
            'bricks_global_settings' => self::option_item('settings', 'Bricks Settings', 'portable'),
            'bricks_color_palette' => self::option_item('color_palette', 'Bricks Color Palettes', 'portable'),
            'bricks_global_classes' => self::option_item('global_classes', 'Bricks Global Classes', 'portable'),
            'bricks_global_classes_categories' => self::option_item('global_classes', 'Bricks Global Classes Categories', 'related_metadata'),
            'bricks_global_variables' => self::option_item('global_variables', 'Bricks Global CSS Variables', 'portable'),
            'bricks_global_variables_categories' => self::option_item('global_variables', 'Bricks Global Variable Categories', 'related_metadata'),
            'bricks_global_pseudo_classes' => self::option_item('pseudo_classes', 'Bricks Pseudo Classes', 'portable'),
            'bricks_theme_styles' => self::option_item('theme_styles', 'Bricks Theme Styles', 'portable'),
            'bricks_components' => self::option_item('components', 'Bricks Components', 'portable'),
            'bricks_breakpoints' => self::option_item('breakpoints', 'Bricks Breakpoints Settings', 'portable', [
                'verification' => 'live_shape_recommended',
                'notes' => 'Current DBVC registry suggests this option, but live Bricks storage should still be verified.',
            ]),
            'bricks_breakpoints_last_generated' => self::option_item('breakpoints', 'Bricks Breakpoints Generated Marker', 'backup_only'),
            'bricks_remote_templates' => self::option_item('', 'Bricks Remote Templates', 'ignore_mvp'),
            'bricks_panel_width' => self::option_item('', 'Bricks Panel Width', 'ignore_mvp'),
            'bricks_global_classes_changes' => self::option_item('global_classes', 'Bricks Global Classes Changes', 'backup_only'),
            'bricks_global_classes_locked' => self::option_item('global_classes', 'Bricks Global Classes Locked', 'backup_only'),
            'bricks_global_classes_timestamp' => self::option_item('global_classes', 'Bricks Global Classes Timestamp', 'backup_only'),
            'bricks_global_classes_user' => self::option_item('global_classes', 'Bricks Global Classes User', 'backup_only'),
            'bricks_global_classes_trash' => self::option_item('global_classes', 'Bricks Global Classes Trash', 'backup_only'),
            'bricks_global_elements' => self::option_item('', 'Bricks Global Elements', 'needs_verification', [
                'notes' => 'Legacy predecessor to bricks_components. Newer Bricks versions can convert legacy global elements, but MVP portability stays anchored to bricks_components until live rules are verified.',
            ]),
            'bricks_pinned_elements' => self::option_item('', 'Bricks Pinned Elements', 'ignore_mvp'),
            'bricks_font_face_rules' => self::option_item('', 'Bricks Font Face Rules', 'needs_verification', [
                'notes' => 'Fonts can have file dependency concerns. Excluded from MVP portability.',
            ]),
            'bricks_icon_sets' => self::option_item('', 'Bricks Icon Sets', 'needs_verification', [
                'notes' => 'Icon set portability may require media or file transfer support.',
            ]),
            'bricks_custom_icons' => self::option_item('', 'Bricks Custom Icons', 'needs_verification', [
                'notes' => 'Custom icon portability may require media or file transfer support.',
            ]),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_domain_registry()
    {
        return [
            'settings' => [
                'label' => 'Bricks Settings',
                'file_slug' => 'bricks-settings',
                'mode' => 'singleton',
                'primary_option' => 'bricks_global_settings',
                'related_options' => [],
                'match_order' => ['singleton'],
                'high_risk' => false,
                'portable' => true,
            ],
            'color_palette' => [
                'label' => 'Bricks Color Palettes',
                'file_slug' => 'color-palettes',
                'mode' => 'collection',
                'primary_option' => 'bricks_color_palette',
                'related_options' => [],
                'match_order' => ['name', 'slug', 'id'],
                'high_risk' => false,
                'portable' => true,
            ],
            'global_classes' => [
                'label' => 'Bricks Global Classes',
                'file_slug' => 'global-classes',
                'mode' => 'collection',
                'primary_option' => 'bricks_global_classes',
                'related_options' => ['bricks_global_classes_categories'],
                'match_order' => ['name', 'id'],
                'high_risk' => false,
                'portable' => true,
            ],
            'global_variables' => [
                'label' => 'Bricks Global CSS Variables',
                'file_slug' => 'global-variables',
                'mode' => 'collection',
                'primary_option' => 'bricks_global_variables',
                'related_options' => ['bricks_global_variables_categories'],
                'match_order' => ['token', 'name', 'id'],
                'high_risk' => false,
                'portable' => true,
            ],
            'pseudo_classes' => [
                'label' => 'Bricks Pseudo Classes',
                'file_slug' => 'pseudo-classes',
                'mode' => 'collection',
                'primary_option' => 'bricks_global_pseudo_classes',
                'related_options' => [],
                'match_order' => ['selector', 'name', 'id'],
                'high_risk' => false,
                'portable' => true,
            ],
            'theme_styles' => [
                'label' => 'Bricks Theme Styles',
                'file_slug' => 'theme-styles',
                'mode' => 'collection',
                'primary_option' => 'bricks_theme_styles',
                'related_options' => [],
                'match_order' => ['name', 'slug', 'id'],
                'high_risk' => false,
                'portable' => true,
            ],
            'components' => [
                'label' => 'Bricks Components',
                'file_slug' => 'components',
                'mode' => 'collection',
                'primary_option' => 'bricks_components',
                'related_options' => [],
                'match_order' => ['name', 'slug', 'id'],
                'high_risk' => false,
                'portable' => true,
            ],
            'breakpoints' => [
                'label' => 'Bricks Breakpoints Settings',
                'file_slug' => 'breakpoints',
                'mode' => 'singleton',
                'primary_option' => 'bricks_breakpoints',
                'related_options' => ['bricks_breakpoints_last_generated'],
                'match_order' => ['singleton'],
                'high_risk' => true,
                'portable' => true,
                'verification' => 'live_shape_recommended',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function get_supported_domains()
    {
        $domains = [];
        foreach (self::get_domain_registry() as $key => $definition) {
            $definition['domain_key'] = $key;
            $definition['option_names'] = self::get_domain_option_names($key);
            $definition['available'] = self::domain_is_available($key, $definition);
            $domains[] = $definition;
        }

        return $domains;
    }

    /**
     * @param string $domain_key
     * @return array<string, mixed>|null
     */
    public static function get_domain($domain_key)
    {
        $domain_key = sanitize_key((string) $domain_key);
        $registry = self::get_domain_registry();
        if (! isset($registry[$domain_key]) || ! is_array($registry[$domain_key])) {
            return null;
        }

        $definition = $registry[$domain_key];
        $definition['domain_key'] = $domain_key;
        $definition['option_names'] = self::get_domain_option_names($domain_key);
        $definition['available'] = self::domain_is_available($domain_key, $definition);
        return $definition;
    }

    /**
     * @param string $domain_key
     * @return array<int, string>
     */
    public static function get_domain_option_names($domain_key)
    {
        $definition = self::get_domain_registry()[sanitize_key((string) $domain_key)] ?? null;
        if (! is_array($definition)) {
            return [];
        }

        $options = [];
        $primary = isset($definition['primary_option']) ? sanitize_key((string) $definition['primary_option']) : '';
        if ($primary !== '') {
            $options[] = $primary;
        }
        foreach ((array) ($definition['related_options'] ?? []) as $option_name) {
            $option_name = sanitize_key((string) $option_name);
            if ($option_name === '') {
                continue;
            }
            $options[] = $option_name;
        }

        return array_values(array_unique($options));
    }

    /**
     * @param array<int, string> $requested
     * @return array<int, array<string, mixed>>
     */
    public static function resolve_requested_domains(array $requested)
    {
        $resolved = [];
        foreach ($requested as $domain_key) {
            $domain = self::get_domain($domain_key);
            if (! is_array($domain)) {
                continue;
            }
            if (empty($domain['portable'])) {
                continue;
            }
            $resolved[] = $domain;
        }

        return $resolved;
    }

    /**
     * @param string $domain_key
     * @param array<string, mixed> $definition
     * @return bool
     */
    public static function domain_is_available($domain_key, array $definition = [])
    {
        $domain_key = sanitize_key((string) $domain_key);
        if ($definition === []) {
            $definition = self::get_domain($domain_key) ?: [];
        }
        if ($definition === []) {
            return false;
        }

        foreach (self::get_domain_option_names($domain_key) as $option_name) {
            if (get_option($option_name, null) !== null) {
                return true;
            }
        }

        return $domain_key !== 'breakpoints';
    }

    /**
     * @param string $domain_key
     * @return array<int, string>
     */
    public static function get_supported_actions($domain_key)
    {
        $definition = self::get_domain($domain_key);
        if (! is_array($definition)) {
            return ['keep_current', 'skip'];
        }

        if (($definition['mode'] ?? '') === 'singleton') {
            return ['replace_with_incoming', 'keep_current', 'skip'];
        }

        return ['keep_current', 'add_incoming', 'replace_with_incoming', 'skip'];
    }

    /**
     * @param string $domain_key
     * @param string $label
     * @param string $classification
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function option_item($domain_key, $label, $classification, array $extra = [])
    {
        return array_merge([
            'domain_key' => sanitize_key((string) $domain_key),
            'label' => sanitize_text_field((string) $label),
            'classification' => sanitize_key((string) $classification),
        ], $extra);
    }
}
