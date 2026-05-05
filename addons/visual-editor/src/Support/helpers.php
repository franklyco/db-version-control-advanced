<?php
declare(strict_types=1);

if (! function_exists('dbvc_visual_editor_array_get')) {
    function dbvc_visual_editor_array_get(array $array, string $key, $default = null) {
        return array_key_exists($key, $array) ? $array[$key] : $default;
    }
}
