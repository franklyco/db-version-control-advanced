<?php

if (! defined('WPINC')) {
    die;
}

interface DBVC_CC_Module_Interface
{
    /**
     * Get the service container ID for this module.
     *
     * @return string
     */
    public function get_service_id();

    /**
     * Register module hooks and no-op phase stubs.
     *
     * @param DBVC_CC_Service_Container $container
     * @return void
     */
    public function register(DBVC_CC_Service_Container $container);
}
