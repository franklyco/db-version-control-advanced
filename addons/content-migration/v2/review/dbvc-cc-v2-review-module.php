<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Review_Module
{
    /**
     * @return void
     */
    public static function register()
    {
        add_action('rest_api_init', [DBVC_CC_V2_Review_REST_Controller::get_instance(), 'register_routes']);
    }

    /**
     * @return void
     */
    public static function unregister()
    {
        remove_action('rest_api_init', [DBVC_CC_V2_Review_REST_Controller::get_instance(), 'register_routes']);
    }
}
