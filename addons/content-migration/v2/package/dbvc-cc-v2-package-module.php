<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Package_Module
{
    /**
     * @return void
     */
    public static function register()
    {
        add_action('rest_api_init', [DBVC_CC_V2_Package_REST_Controller::get_instance(), 'register_routes']);
        add_action(
            'admin_post_' . DBVC_CC_V2_Package_Artifact_Service::get_instance()->get_download_action_name(),
            [DBVC_CC_V2_Package_REST_Controller::get_instance(), 'download_artifact']
        );
    }

    /**
     * @return void
     */
    public static function unregister()
    {
        remove_action('rest_api_init', [DBVC_CC_V2_Package_REST_Controller::get_instance(), 'register_routes']);
        remove_action(
            'admin_post_' . DBVC_CC_V2_Package_Artifact_Service::get_instance()->get_download_action_name(),
            [DBVC_CC_V2_Package_REST_Controller::get_instance(), 'download_artifact']
        );
    }
}
