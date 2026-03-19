<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Mapping_Workbench_Module implements DBVC_CC_Module_Interface
{
    public const SERVICE_ID = 'dbvc_cc.module.mapping_workbench';

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

        if (! DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_MAPPING_WORKBENCH)) {
            return;
        }

        DBVC_CC_Section_Field_Candidate_Service::get_instance();
        DBVC_CC_Mapping_Decision_Service::get_instance();
        DBVC_CC_Mapping_Rebuild_Service::get_instance();
        DBVC_CC_Workbench_REST_Controller::get_instance();
    }
}
