<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Explorer_Module implements DBVC_CC_Module_Interface
{
    public const SERVICE_ID = 'dbvc_cc.module.explorer';

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
        if (! DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_EXPLORER)) {
            return;
        }

        DBVC_CC_Explorer_REST_Controller::get_instance();
    }
}
