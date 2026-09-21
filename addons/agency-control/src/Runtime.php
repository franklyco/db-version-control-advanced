<?php

namespace Dbvc\AgencyControl;

use Dbvc\AgencyControl\Admin\HubPanel;
use Dbvc\AgencyControl\Admin\RestController as AdminRestController;
use Dbvc\AgencyControl\Rest\Controller;
use Dbvc\AgencyControl\Storage\Schema;

/**
 * Hub composition root. With the gate on and the schema installed it
 * registers the enrollment/receipt/capabilities REST routes and the schema
 * version-bump hook; nothing else. Delivery, inbox, routing and review are
 * not registered until they exist. The hub never requires Bricks, the
 * Visual Editor, or connector activation on its own installation.
 */
final class Runtime
{
    public const STATE_REGISTERED = 'registered';
    public const STATE_MIGRATION_REQUIRED = 'migration_required';

    /**
     * @var Controller|null
     */
    private static $controller = null;

    /**
     * @var AdminRestController|null
     */
    private static $admin_rest = null;

    /**
     * @return array{state: string, registered: bool, reason: string, capabilities: array<string, bool>}
     */
    public static function boot()
    {
        self::unregister();

        add_action('plugins_loaded', [Schema::class, 'maybe_upgrade'], 15);
        if (did_action('plugins_loaded')) {
            Schema::maybe_upgrade();
        }

        if (! Schema::is_ready()) {
            return [
                'state' => self::STATE_MIGRATION_REQUIRED,
                'registered' => false,
                'reason' => 'hub_schema_not_installed',
                'capabilities' => ['enrollment' => false, 'receipt' => false, 'delivery' => false, 'review' => false],
            ];
        }

        self::$controller = new Controller();
        add_action('rest_api_init', [self::$controller, 'register_routes']);
        self::$admin_rest = new AdminRestController();
        add_action('rest_api_init', [self::$admin_rest, 'register_routes']);
        if (is_admin()) {
            // Administrator panel actions only exist inside wp-admin (admin-post.php included); public requests register nothing.
            HubPanel::register();
        }

        return [
            'state' => self::STATE_REGISTERED,
            'registered' => true,
            'reason' => '',
            'capabilities' => ['enrollment' => true, 'receipt' => true, 'delivery' => true, 'review' => false],
        ];
    }

    /**
     * @return void
     */
    public static function unregister()
    {
        remove_action('plugins_loaded', [Schema::class, 'maybe_upgrade'], 15);
        HubPanel::unregister();
        if (self::$admin_rest instanceof AdminRestController) {
            remove_action('rest_api_init', [self::$admin_rest, 'register_routes']);
            self::$admin_rest = null;
        }
        if (self::$controller instanceof Controller) {
            remove_action('rest_api_init', [self::$controller, 'register_routes']);
            self::$controller = null;
        }
    }
}
