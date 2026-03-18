<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Schema_Snapshot_Module implements DBVC_CC_Module_Interface
{
    public const SERVICE_ID = 'dbvc_cc.module.schema_snapshot';

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
        add_action('admin_init', [DBVC_CC_Schema_Snapshot_Service::class, 'maybe_generate_initial_snapshot'], 20);
        add_action(DBVC_CC_Contracts::ACTION_RUN_SCHEMA_SNAPSHOT, [DBVC_CC_Schema_Snapshot_Service::class, 'generate_snapshot']);
    }
}
