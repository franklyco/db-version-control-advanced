<?php

namespace Dbvc\VisualEditor\MediaManager;

/*
 * Namespaced stand-ins that the MediaManager classes resolve before the global
 * functions. While $GLOBALS['dbvc_test_before_pluggables'] is set they fail the way
 * the real calls do when the add-on boots during plugin load, before WordPress has
 * included pluggable.php. Otherwise they defer to WordPress.
 */
if (! function_exists(__NAMESPACE__ . '\\wp_rand')) {
    function wp_rand($min = null, $max = null)
    {
        if (! empty($GLOBALS['dbvc_test_before_pluggables'])) {
            throw new \Error('Call to undefined function ' . __FUNCTION__ . '()');
        }

        return \wp_rand(...func_get_args());
    }
}

if (! function_exists(__NAMESPACE__ . '\\wp_salt')) {
    function wp_salt($scheme = 'auth')
    {
        if (! empty($GLOBALS['dbvc_test_before_pluggables'])) {
            throw new \Error('Call to undefined function ' . __FUNCTION__ . '()');
        }

        return \wp_salt(...func_get_args());
    }
}
