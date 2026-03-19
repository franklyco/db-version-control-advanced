<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Journey_Module
{
    /**
     * @return void
     */
    public static function register()
    {
        add_action('init', [DBVC_CC_Artifact_Manager::class, 'ensure_storage_roots'], 15);
        add_action('admin_init', [DBVC_CC_Schema_Snapshot_Service::class, 'maybe_generate_initial_snapshot'], 20);
        add_action(DBVC_CC_Contracts::ACTION_RUN_SCHEMA_SNAPSHOT, [DBVC_CC_Schema_Snapshot_Service::class, 'generate_snapshot']);
        add_action('rest_api_init', [DBVC_CC_V2_Domain_Journey_REST_Controller::get_instance(), 'register_routes']);
    }

    /**
     * @return void
     */
    public static function unregister()
    {
        remove_action('init', [DBVC_CC_Artifact_Manager::class, 'ensure_storage_roots'], 15);
        remove_action('admin_init', [DBVC_CC_Schema_Snapshot_Service::class, 'maybe_generate_initial_snapshot'], 20);
        remove_action(DBVC_CC_Contracts::ACTION_RUN_SCHEMA_SNAPSHOT, [DBVC_CC_Schema_Snapshot_Service::class, 'generate_snapshot']);
        remove_action('rest_api_init', [DBVC_CC_V2_Domain_Journey_REST_Controller::get_instance(), 'register_routes']);
    }
}
