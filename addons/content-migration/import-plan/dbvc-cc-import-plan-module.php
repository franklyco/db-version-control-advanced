<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Import_Plan_Module implements DBVC_CC_Module_Interface
{
    public const SERVICE_ID = 'dbvc_cc.module.import_plan';

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

        DBVC_CC_Import_Plan_Service::get_instance();
        DBVC_CC_Import_Plan_REST_Controller::get_instance();
    }
}
