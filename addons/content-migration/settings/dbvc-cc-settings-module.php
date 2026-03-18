<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Settings_Module implements DBVC_CC_Module_Interface
{
    public const SERVICE_ID = 'dbvc_cc.module.settings';

    /**
     * @return string
     */
    public function get_service_id()
    {
        return self::SERVICE_ID;
    }

    /**
     * @param DBVC_CC_Service_Container $container
     * @return void
     */
    public function register(DBVC_CC_Service_Container $container)
    {
        unset($container);
        DBVC_CC_Settings_Service::bootstrap();
        add_action('admin_init', [DBVC_CC_Settings_Service::class, 'register_settings']);
    }
}
