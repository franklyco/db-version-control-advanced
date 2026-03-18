<?php

if (! defined('ABSPATH')) {
    exit;
}

final class DBVC_CC_Admin_Controller
{
    private const TAB_COLLECT = 'collect';
    private const TAB_EXPLORE = 'explore';
    private const TAB_CONFIGURE = 'configure';
    private const CONFIGURE_SUBTAB_GENERAL = 'general';
    private const CONFIGURE_SUBTAB_ADVANCED_COLLECTION = 'advanced-collection-controls';

    /**
     * @var DBVC_CC_Admin_Controller|null
     */
    private static $instance = null;

    /**
     * @var string
     */
    private $main_page_hook = '';

    /**
     * @var string
     */
    private $explorer_compat_hook = '';

    /**
     * @var string
     */
    private $workbench_page_hook = '';

    /**
     * @return DBVC_CC_Admin_Controller
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menu'], 90);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * @return void
     */
    public function add_admin_menu()
    {
        $this->main_page_hook = (string) add_submenu_page(
            'dbvc-export',
            __('Content Migration', 'dbvc'),
            __('Content Migration', 'dbvc'),
            DBVC_CC_Contracts::ADMIN_CAPABILITY,
            DBVC_CC_Contracts::ADMIN_MENU_SLUG,
            [$this, 'render_admin_page']
        );

        if (DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_EXPLORER)) {
            $this->explorer_compat_hook = (string) add_submenu_page(
                null,
                __('Content Migration Explorer', 'dbvc'),
                __('Content Migration Explorer', 'dbvc'),
                DBVC_CC_Contracts::ADMIN_CAPABILITY,
                DBVC_CC_Contracts::EXPLORER_MENU_SLUG,
                [$this, 'redirect_legacy_explorer_page']
            );
        }

        if (DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_WORKBENCH)) {
            $this->workbench_page_hook = (string) add_submenu_page(
                'dbvc-export',
                __('Content Mapping Workbench', 'dbvc'),
                __('Mapping Workbench', 'dbvc'),
                DBVC_CC_Contracts::ADMIN_CAPABILITY,
                DBVC_CC_Contracts::WORKBENCH_MENU_SLUG,
                [$this, 'render_workbench_page']
            );
        }
    }

    /**
     * @param string $hook
     * @return void
     */
    public function enqueue_scripts($hook)
    {
        if ($hook === $this->main_page_hook) {
            $active_tab = $this->get_active_tab();
            if ($active_tab === self::TAB_COLLECT) {
                $this->enqueue_collect_assets();
            } elseif ($active_tab === self::TAB_EXPLORE && DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_EXPLORER)) {
                $this->enqueue_explorer_assets();
            }

            return;
        }

        if ($hook === $this->workbench_page_hook) {
            $this->enqueue_workbench_assets();
            return;
        }

        unset($hook);
    }

    /**
     * @return void
     */
    private function enqueue_collect_assets()
    {
        wp_enqueue_style(
            'dbvc_cc_admin_styles',
            DBVC_PLUGIN_URL . 'addons/content-migration/collector/assets/dbvc-cc-admin-styles.css',
            [],
            $this->get_asset_version('addons/content-migration/collector/assets/dbvc-cc-admin-styles.css')
        );

        wp_enqueue_script(
            'dbvc_cc_admin_script',
            DBVC_PLUGIN_URL . 'addons/content-migration/collector/assets/dbvc-cc-crawler-admin.js',
            ['jquery'],
            $this->get_asset_version('addons/content-migration/collector/assets/dbvc-cc-crawler-admin.js'),
            true
        );

        wp_localize_script('dbvc_cc_admin_script', 'dbvc_cc_ajax_object', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(DBVC_CC_Contracts::AJAX_NONCE_ACTION),
            'actions' => [
                'get_urls' => DBVC_CC_Contracts::AJAX_ACTION_GET_URLS_FROM_SITEMAP,
                'process_url' => DBVC_CC_Contracts::AJAX_ACTION_PROCESS_SINGLE_URL,
                'trigger_domain_ai_refresh' => DBVC_CC_Contracts::AJAX_ACTION_DBVC_CC_TRIGGER_DOMAIN_AI_REFRESH,
            ],
        ]);
    }

    /**
     * @return void
     */
    private function enqueue_explorer_assets()
    {
        $options = DBVC_CC_Settings_Service::get_options();

        wp_enqueue_style(
            'dbvc_cc_explorer_styles',
            DBVC_PLUGIN_URL . 'addons/content-migration/explorer/assets/dbvc-cc-explorer.css',
            [],
            $this->get_asset_version('addons/content-migration/explorer/assets/dbvc-cc-explorer.css')
        );

        wp_enqueue_script(
            'dbvc_cc_cytoscape',
            'https://unpkg.com/cytoscape@3.30.2/dist/cytoscape.min.js',
            [],
            '3.30.2',
            true
        );

        wp_enqueue_script(
            'dbvc_cc_explorer_script',
            DBVC_PLUGIN_URL . 'addons/content-migration/explorer/assets/dbvc-cc-explorer.js',
            ['jquery', 'dbvc_cc_cytoscape'],
            $this->get_asset_version('addons/content-migration/explorer/assets/dbvc-cc-explorer.js'),
            true
        );

        wp_localize_script('dbvc_cc_explorer_script', 'dbvc_cc_explorer_object', [
            'rest_base' => esc_url_raw(rest_url(DBVC_CC_Contracts::REST_NAMESPACE . '/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'capabilities' => [
                'ai' => DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_AI_MAPPING),
                'workbench' => DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_WORKBENCH),
                'mapping_catalog_bridge' => DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_CATALOG_BRIDGE),
                'media_mapping_bridge' => DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_MEDIA_MAPPING_BRIDGE),
                'export' => DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_EXPORT),
            ],
            'workbench_url' => DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_WORKBENCH)
                ? esc_url_raw($this->get_workbench_page_url())
                : '',
            'defaults' => [
                'depth' => isset($options['explorer_default_depth']) ? absint($options['explorer_default_depth']) : 2,
                'max_nodes' => isset($options['explorer_max_nodes']) ? absint($options['explorer_max_nodes']) : 600,
                'cache_ttl' => isset($options['explorer_cache_ttl']) ? absint($options['explorer_cache_ttl']) : 300,
                'show_files' => false,
            ],
        ]);
    }

    /**
     * @return void
     */
    private function enqueue_workbench_assets()
    {
        $options = DBVC_CC_Settings_Service::get_options();
        $requested_domain = isset($_GET['domain']) ? sanitize_text_field(wp_unslash((string) $_GET['domain'])) : '';
        $requested_path = isset($_GET['path']) ? sanitize_text_field(wp_unslash((string) $_GET['path'])) : '';
        $dbvc_cc_post_type_options = [];
        $dbvc_cc_post_type_objects = get_post_types(['public' => true], 'objects');
        if (is_array($dbvc_cc_post_type_objects)) {
            foreach ($dbvc_cc_post_type_objects as $dbvc_cc_post_type_name => $dbvc_cc_post_type_object) {
                $dbvc_cc_value = sanitize_key((string) $dbvc_cc_post_type_name);
                if ($dbvc_cc_value === '') {
                    continue;
                }

                $dbvc_cc_label = is_object($dbvc_cc_post_type_object) && isset($dbvc_cc_post_type_object->labels->singular_name)
                    ? sanitize_text_field((string) $dbvc_cc_post_type_object->labels->singular_name)
                    : $dbvc_cc_value;

                $dbvc_cc_post_type_options[] = [
                    'value' => $dbvc_cc_value,
                    'label' => $dbvc_cc_label,
                ];
            }
        }

        wp_enqueue_style(
            'dbvc_cc_workbench_styles',
            DBVC_PLUGIN_URL . 'addons/content-migration/mapping-workbench/assets/dbvc-cc-workbench.css',
            [],
            $this->get_asset_version('addons/content-migration/mapping-workbench/assets/dbvc-cc-workbench.css')
        );

        wp_enqueue_script(
            'dbvc_cc_workbench_script',
            DBVC_PLUGIN_URL . 'addons/content-migration/mapping-workbench/assets/dbvc-cc-workbench.js',
            ['jquery'],
            $this->get_asset_version('addons/content-migration/mapping-workbench/assets/dbvc-cc-workbench.js'),
            true
        );

        wp_localize_script('dbvc_cc_workbench_script', 'dbvc_cc_workbench_object', [
            'rest_base' => esc_url_raw(rest_url(DBVC_CC_Contracts::REST_NAMESPACE . '/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'workbench_url' => esc_url_raw($this->get_workbench_page_url()),
            'capabilities' => [
                'mapping_catalog_bridge' => DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_CATALOG_BRIDGE),
                'media_mapping_bridge' => DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_MEDIA_MAPPING_BRIDGE),
                'import_plan' => DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_CATALOG_BRIDGE),
                'import_executor' => DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_CATALOG_BRIDGE),
            ],
            'prefill' => [
                'domain' => $requested_domain,
                'path' => $requested_path,
            ],
            'defaults' => [
                'limit' => 50,
                'include_decided' => false,
                'min_confidence' => DBVC_CC_AI_Service::REVIEW_CONFIDENCE_THRESHOLD,
                'mapping_candidate_confidence_threshold' => isset($options['dbvc_cc_mapping_candidate_confidence_threshold'])
                    ? (float) $options['dbvc_cc_mapping_candidate_confidence_threshold']
                    : 0.65,
                'media_mapping_confidence_threshold' => isset($options['dbvc_cc_media_mapping_confidence_threshold'])
                    ? (float) $options['dbvc_cc_media_mapping_confidence_threshold']
                    : 0.70,
            ],
            'post_types' => $dbvc_cc_post_type_options,
        ]);
    }

    /**
     * @param string $relative_path
     * @return string
     */
    private function get_asset_version($relative_path)
    {
        $relative_path = ltrim((string) $relative_path, '/');
        if ($relative_path === '') {
            return (string) DBVC_PLUGIN_VERSION;
        }

        $absolute_path = DBVC_PLUGIN_PATH . $relative_path;
        if (! file_exists($absolute_path)) {
            return (string) DBVC_PLUGIN_VERSION;
        }

        $modified_at = filemtime($absolute_path);
        if (! is_int($modified_at) || $modified_at <= 0) {
            return (string) DBVC_PLUGIN_VERSION;
        }

        return (string) DBVC_PLUGIN_VERSION . '.' . (string) $modified_at;
    }

    /**
     * @return void
     */
    public function render_admin_page()
    {
        $options = DBVC_CC_Settings_Service::get_options();
        $active_tab = $this->get_active_tab();
        $tabs = $this->get_available_tabs();
        $configure_subtabs = $this->get_configure_subtabs();
        $active_configure_subtab = $this->get_active_configure_subtab($active_tab, $configure_subtabs);

        require DBVC_PLUGIN_PATH . 'addons/content-migration/collector/views/dbvc-cc-admin-page.php';
    }

    /**
     * @return void
     */
    public function redirect_legacy_explorer_page()
    {
        if (! current_user_can(DBVC_CC_Contracts::ADMIN_CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'dbvc'));
        }

        $target = add_query_arg(
            [
                'page' => DBVC_CC_Contracts::ADMIN_MENU_SLUG,
                'tab' => self::TAB_EXPLORE,
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($target);
        exit;
    }

    /**
     * @return void
     */
    public function render_workbench_page()
    {
        require DBVC_PLUGIN_PATH . 'addons/content-migration/mapping-workbench/views/dbvc-cc-workbench-page.php';
    }

    /**
     * @return array<string, string>
     */
    private function get_available_tabs()
    {
        $tabs = [
            self::TAB_COLLECT => __('Collect', 'dbvc'),
            self::TAB_CONFIGURE => __('Configure', 'dbvc'),
        ];

        if (DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_EXPLORER)) {
            $tabs = [
                self::TAB_COLLECT => __('Collect', 'dbvc'),
                self::TAB_EXPLORE => __('Explore', 'dbvc'),
                self::TAB_CONFIGURE => __('Configure', 'dbvc'),
            ];
        }

        return $tabs;
    }

    /**
     * @return string
     */
    private function get_active_tab()
    {
        $requested_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : self::TAB_COLLECT;
        $tabs = $this->get_available_tabs();

        if (! isset($tabs[$requested_tab])) {
            return self::TAB_COLLECT;
        }

        return $requested_tab;
    }

    /**
     * @return array<string, string>
     */
    private function get_configure_subtabs()
    {
        return [
            self::CONFIGURE_SUBTAB_GENERAL => __('General', 'dbvc'),
            self::CONFIGURE_SUBTAB_ADVANCED_COLLECTION => __('Advanced Collection Controls', 'dbvc'),
        ];
    }

    /**
     * @param string $active_tab
     * @param array<string, string> $configure_subtabs
     * @return string
     */
    private function get_active_configure_subtab($active_tab, array $configure_subtabs)
    {
        if ($active_tab !== self::TAB_CONFIGURE) {
            return self::CONFIGURE_SUBTAB_GENERAL;
        }

        $requested_subtab = isset($_GET['configure_subtab']) ? sanitize_key(wp_unslash((string) $_GET['configure_subtab'])) : self::CONFIGURE_SUBTAB_GENERAL;
        if (! isset($configure_subtabs[$requested_subtab])) {
            return self::CONFIGURE_SUBTAB_GENERAL;
        }

        return $requested_subtab;
    }

    /**
     * @return string
     */
    private function get_workbench_page_url()
    {
        return add_query_arg(
            [
                'page' => DBVC_CC_Contracts::WORKBENCH_MENU_SLUG,
            ],
            admin_url('admin.php')
        );
    }
}
