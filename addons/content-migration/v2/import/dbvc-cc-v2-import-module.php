<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Import_Module
{
    /**
     * @var bool
     */
    private static $registered = false;

    /**
     * @return void
     */
    public static function register()
    {
        if (self::$registered) {
            return;
        }

        add_action('rest_api_init', [DBVC_CC_V2_Import_REST_Controller::get_instance(), 'register_routes']);
        self::$registered = true;
    }

    /**
     * @return void
     */
    public static function unregister()
    {
        remove_action('rest_api_init', [DBVC_CC_V2_Import_REST_Controller::get_instance(), 'register_routes']);
        self::$registered = false;
    }
}
