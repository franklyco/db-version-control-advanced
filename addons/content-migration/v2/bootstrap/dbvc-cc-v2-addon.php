<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Addon
{
    /**
     * @return void
     */
    public static function bootstrap()
    {
        DBVC_CC_V2_Runtime_Registrar::refresh_runtime_registration();
    }
}
