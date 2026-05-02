<?php

namespace Dbvc\VisualEditor\Context;

final class FrontendRuntimeGuard
{
    /**
     * @return bool
     */
    public function isBuilderRequest()
    {
        if (function_exists('bricks_is_builder') && bricks_is_builder()) {
            return true;
        }

        if (function_exists('bricks_is_builder_main') && bricks_is_builder_main()) {
            return true;
        }

        if (function_exists('bricks_is_builder_iframe') && bricks_is_builder_iframe()) {
            return true;
        }

        $builder_params = [
            'bricks',
            'brickspreview',
            'bricks_preview',
            '_bricksmode',
            'elementor-preview',
            'ct_builder',
            'oxygen_iframe',
            'fl_builder',
            'vc_editable',
        ];

        foreach ($builder_params as $param) {
            if (isset($_GET[$param])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    public function shouldRunFrontend()
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || is_customize_preview()) {
            return false;
        }

        if ($this->isBuilderRequest()) {
            return false;
        }

        return true;
    }
}
