<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_App_Loader
{
    /**
     * @var string
     */
    private static $page_hook = '';

    /**
     * @return void
     */
    public static function unregister()
    {
        remove_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        self::$page_hook = '';
    }

    /**
     * @param string $page_hook
     * @return void
     */
    public static function register_for_page_hook($page_hook)
    {
        self::$page_hook = (string) $page_hook;
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    /**
     * @param string $hook
     * @return void
     */
    public static function enqueue_assets($hook)
    {
        if ($hook !== self::$page_hook) {
            return;
        }

        $asset = self::get_manifest_asset();
        if ($asset === null) {
            return;
        }

        foreach ($asset['css'] as $handle => $url) {
            wp_enqueue_style($handle, $url, [], $asset['version']);
        }

        wp_enqueue_script(
            DBVC_CC_V2_Contracts::SCRIPT_HANDLE,
            $asset['js'],
            isset($asset['dependencies']) && is_array($asset['dependencies']) ? $asset['dependencies'] : ['wp-element'],
            $asset['version'],
            true
        );

        wp_localize_script(
            DBVC_CC_V2_Contracts::SCRIPT_HANDLE,
            DBVC_CC_V2_Contracts::SCRIPT_OBJECT,
            self::get_bootstrap_data()
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function get_manifest_asset()
    {
        $build_dir = DBVC_PLUGIN_PATH . 'build/';
        if (! is_dir($build_dir)) {
            return null;
        }

        $asset_file = $build_dir . 'content-collector-v2-app.asset.php';
        if (! file_exists($asset_file)) {
            return null;
        }

        $asset = include $asset_file;
        $css = [];
        $style_file = 'style-content-collector-v2-app.css';
        if (is_rtl() && file_exists($build_dir . 'style-content-collector-v2-app-rtl.css')) {
            $style_file = 'style-content-collector-v2-app-rtl.css';
        }
        if (file_exists($build_dir . $style_file)) {
            $css['dbvc-content-collector-v2-app'] = DBVC_PLUGIN_URL . 'build/' . $style_file;
        }

        return [
            'js' => DBVC_PLUGIN_URL . 'build/content-collector-v2-app.js',
            'css' => $css,
            'dependencies' => isset($asset['dependencies']) ? $asset['dependencies'] : [],
            'version' => isset($asset['version']) ? $asset['version'] : DBVC_PLUGIN_VERSION,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function get_bootstrap_data()
    {
        return [
            'adminUrl' => esc_url_raw(admin_url('admin.php?page=' . DBVC_CC_V2_Contracts::ADMIN_MENU_SLUG)),
            'apiRoot' => esc_url_raw(rest_url(DBVC_CC_V2_Contracts::REST_NAMESPACE . '/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'defaultRunId' => self::get_default_run_id(),
            'runtimeVersion' => DBVC_CC_V2_Contracts::get_runtime_version(),
            'automation' => DBVC_CC_V2_Contracts::get_automation_settings(),
            'runCreate' => self::get_run_create_bootstrap(),
            'route' => self::get_route_bootstrap(),
            'views' => [
                'runs',
                'overview',
                'exceptions',
                'readiness',
                'package',
            ],
            'selectors' => [
                'appRoot' => 'dbvc-cc-v2-root',
                'drawerToggle' => 'dbvc-cc-v2-drawer-toggle',
                'drawerRoot' => 'dbvc-cc-v2-inspector-drawer',
                'runCreateForm' => 'dbvc-cc-v2-run-create-form',
                'runCreateSubmit' => 'dbvc-cc-v2-run-create-submit',
                'runCreateAdvancedToggle' => 'dbvc-cc-v2-run-create-advanced-toggle',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function get_run_create_bootstrap()
    {
        $settings = DBVC_CC_Settings_Service::get_options();
        $fields = [
            'request_delay',
            'request_timeout',
            'user_agent',
            'exclude_selectors',
            'focus_selectors',
            'capture_mode',
            'capture_include_attribute_context',
            'capture_include_dom_path',
            'capture_max_elements_per_page',
            'capture_max_chars_per_element',
            'context_enable_boilerplate_detection',
            'context_enable_entity_hints',
            'ai_enable_section_typing',
            'ai_section_typing_confidence_threshold',
            'scrub_policy_enabled',
            'scrub_profile_mode',
            'scrub_attr_action_class',
            'scrub_attr_action_id',
            'scrub_attr_action_data',
            'scrub_attr_action_style',
            'scrub_attr_action_aria',
            'scrub_custom_allowlist',
            'scrub_custom_denylist',
            'scrub_ai_suggestion_enabled',
            'scrub_preview_sample_size',
        ];

        $crawl_defaults = [];
        foreach ($fields as $field) {
            $crawl_defaults[$field] = isset($settings[$field]) ? $settings[$field] : '';
        }

        return [
            'crawlDefaults' => $crawl_defaults,
            'optionSets' => [
                'captureModes' => [
                    [
                        'value' => DBVC_CC_Contracts::CAPTURE_MODE_STANDARD,
                        'label' => __('Standard', 'dbvc'),
                    ],
                    [
                        'value' => DBVC_CC_Contracts::CAPTURE_MODE_DEEP,
                        'label' => __('Deep', 'dbvc'),
                    ],
                ],
                'scrubProfiles' => [
                    [
                        'value' => DBVC_CC_Contracts::SCRUB_PROFILE_DETERMINISTIC_DEFAULT,
                        'label' => __('Deterministic Default', 'dbvc'),
                    ],
                    [
                        'value' => DBVC_CC_Contracts::SCRUB_PROFILE_CUSTOM,
                        'label' => __('Custom', 'dbvc'),
                    ],
                    [
                        'value' => DBVC_CC_Contracts::SCRUB_PROFILE_AI_SUGGESTED_APPROVED,
                        'label' => __('AI Suggested (Approved)', 'dbvc'),
                    ],
                ],
                'scrubActions' => [
                    [
                        'value' => DBVC_CC_Contracts::SCRUB_ACTION_KEEP,
                        'label' => __('Keep', 'dbvc'),
                    ],
                    [
                        'value' => DBVC_CC_Contracts::SCRUB_ACTION_DROP,
                        'label' => __('Drop', 'dbvc'),
                    ],
                    [
                        'value' => DBVC_CC_Contracts::SCRUB_ACTION_HASH,
                        'label' => __('Hash', 'dbvc'),
                    ],
                    [
                        'value' => DBVC_CC_Contracts::SCRUB_ACTION_TOKENIZE,
                        'label' => __('Tokenize', 'dbvc'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return string
     */
    private static function get_default_run_id()
    {
        $runs = DBVC_CC_V2_Domain_Journey_Service::get_instance()->list_latest_states();
        if (! empty($runs[0]['journey_id'])) {
            return (string) $runs[0]['journey_id'];
        }

        return DBVC_CC_V2_Contracts::DEFAULT_RUN_ID;
    }

    /**
     * @return array<string, string>
     */
    private static function get_route_bootstrap()
    {
        $allowed_views = ['runs', 'overview', 'exceptions', 'readiness', 'package'];
        $view = isset($_GET['view']) ? sanitize_key(wp_unslash((string) $_GET['view'])) : 'runs'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (! in_array($view, $allowed_views, true)) {
            $view = 'runs';
        }

        return [
            'view' => $view,
            'runId' => isset($_GET['runId']) ? sanitize_text_field(wp_unslash((string) $_GET['runId'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'pageId' => isset($_GET['pageId']) ? sanitize_text_field(wp_unslash((string) $_GET['pageId'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'panel' => isset($_GET['panel']) ? sanitize_key(wp_unslash((string) $_GET['panel'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'panelTab' => isset($_GET['panelTab']) ? sanitize_key(wp_unslash((string) $_GET['panelTab'])) : 'summary', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'filter' => isset($_GET['filter']) ? sanitize_key(wp_unslash((string) $_GET['filter'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'status' => isset($_GET['status']) ? sanitize_key(wp_unslash((string) $_GET['status'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'q' => isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'sort' => isset($_GET['sort']) ? sanitize_key(wp_unslash((string) $_GET['sort'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'packageId' => isset($_GET['packageId']) ? sanitize_text_field(wp_unslash((string) $_GET['packageId'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ];
    }
}
