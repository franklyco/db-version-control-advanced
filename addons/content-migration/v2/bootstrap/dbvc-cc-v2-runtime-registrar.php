<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Runtime_Registrar
{
    /**
     * @var string
     */
    private static $active_runtime = '';

    /**
     * @return void
     */
    public static function refresh_runtime_registration()
    {
        DBVC_CC_Addon_Bootstrap::deactivate_v1_runtime();
        DBVC_CC_V2_Admin_Menu_Service::unregister();
        DBVC_CC_V2_Journey_Module::unregister();
        DBVC_CC_V2_Review_Module::unregister();
        DBVC_CC_V2_Package_Module::unregister();
        DBVC_CC_V2_Import_Module::unregister();

        self::$active_runtime = '';

        if (! DBVC_CC_V2_Contracts::is_addon_enabled()) {
            DBVC_CC_Addon_Bootstrap::clear_scheduled_hooks();
            return;
        }

        if (DBVC_CC_V2_Contracts::get_runtime_version() === DBVC_CC_V2_Contracts::RUNTIME_V2) {
            DBVC_CC_Addon_Bootstrap::clear_scheduled_hooks();
            DBVC_CC_V2_Journey_Module::register();
            DBVC_CC_V2_Review_Module::register();
            DBVC_CC_V2_Package_Module::register();
            DBVC_CC_V2_Import_Module::register();
            DBVC_CC_V2_Admin_Menu_Service::register();
            self::$active_runtime = DBVC_CC_V2_Contracts::RUNTIME_V2;
            return;
        }

        DBVC_CC_Addon_Bootstrap::bootstrap_v1_runtime();
        self::$active_runtime = DBVC_CC_V2_Contracts::RUNTIME_V1;
    }

    /**
     * @return string
     */
    public static function get_active_runtime()
    {
        if (self::$active_runtime !== '') {
            return self::$active_runtime;
        }

        return DBVC_CC_Addon_Bootstrap::get_active_runtime();
    }
}
