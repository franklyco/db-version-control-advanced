<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Collector_Module implements DBVC_CC_Module_Interface
{
    public const SERVICE_ID = 'dbvc_cc.module.collector';

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
        DBVC_CC_Admin_Controller::get_instance();

        if (! DBVC_CC_Contracts::is_feature_enabled(DBVC_CC_Contracts::OPTION_FLAG_COLLECTOR)) {
            return;
        }

        add_action('init', [DBVC_CC_Artifact_Manager::class, 'ensure_storage_roots'], 15);
        DBVC_CC_Ajax_Controller::get_instance();
    }
}
