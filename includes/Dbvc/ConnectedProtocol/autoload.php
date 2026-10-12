<?php
/**
 * Autoloader for the shared Connected Protocol contract layer.
 *
 * The classes under this directory are pure PHP: no WordPress registration,
 * database access, network, or global service locator. They must be loadable
 * by either the Connected Environments connector or the Agency Control hub
 * independently of the other add-on's activation state. Registering the
 * autoloader costs nothing until a class is actually referenced, so a plain
 * DBVC installation with both modules disabled loads no protocol code.
 *
 * @package DB Version Control
 */

if (! defined('WPINC')) {
    die;
}

spl_autoload_register(
    static function ($class) {
        $prefix = 'Dbvc\\ConnectedProtocol\\';

        if (strpos((string) $class, $prefix) !== 0) {
            return;
        }

        $relative = substr((string) $class, strlen($prefix));
        $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
        $path = __DIR__ . '/' . $relative . '.php';

        if (is_readable($path)) {
            require_once $path;
        }
    }
);
