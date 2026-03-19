<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Admin_Menu_Service
{
    /**
     * @var string
     */
    private static $page_hook = '';

    /**
     * @return void
     */
    public static function register()
    {
        add_action('admin_menu', [self::class, 'register_admin_menu'], 90);
    }

    /**
     * @return void
     */
    public static function unregister()
    {
        remove_action('admin_menu', [self::class, 'register_admin_menu'], 90);
        DBVC_CC_V2_App_Loader::unregister();
        self::$page_hook = '';
    }

    /**
     * @return string
     */
    public static function get_page_hook()
    {
        return self::$page_hook;
    }

    /**
     * @return void
     */
    public static function register_admin_menu()
    {
        self::$page_hook = (string) add_submenu_page(
            'dbvc-export',
            __('Content Migration', 'dbvc'),
            __('Content Migration', 'dbvc'),
            DBVC_CC_Contracts::ADMIN_CAPABILITY,
            DBVC_CC_V2_Contracts::ADMIN_MENU_SLUG,
            [self::class, 'render_admin_page']
        );

        DBVC_CC_V2_App_Loader::register_for_page_hook(self::$page_hook);
    }

    /**
     * @return void
     */
    public static function render_admin_page()
    {
        if (! current_user_can(DBVC_CC_Contracts::ADMIN_CAPABILITY)) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'dbvc'));
        }

        echo '<div class="wrap dbvc-cc-v2-admin-page">';
        echo '<div id="dbvc-cc-v2-root" class="dbvc-cc-v2-root" data-testid="dbvc-cc-v2-root"></div>';
        echo '</div>';
    }
}
