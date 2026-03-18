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
        if (file_exists($build_dir . 'style-content-collector-v2-app.css')) {
            $css['dbvc-content-collector-v2-app'] = DBVC_PLUGIN_URL . 'build/style-content-collector-v2-app.css';
        }
        if (file_exists($build_dir . 'style-content-collector-v2-app-rtl.css')) {
            $css['dbvc-content-collector-v2-app-rtl'] = DBVC_PLUGIN_URL . 'build/style-content-collector-v2-app-rtl.css';
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
            'defaultRunId' => DBVC_CC_V2_Contracts::DEFAULT_RUN_ID,
            'runtimeVersion' => DBVC_CC_V2_Contracts::get_runtime_version(),
            'automation' => DBVC_CC_V2_Contracts::get_automation_settings(),
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
            ],
        ];
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
