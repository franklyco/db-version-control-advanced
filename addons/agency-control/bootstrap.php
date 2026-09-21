<?php
/**
 * DBVC Agency Control add-on (studio hub role).
 *
 * Disabled by default and independent of the connector. Enabling it installs
 * the hub stores through the explicit settings lifecycle and registers the
 * enrollment, observation-receipt and capabilities REST routes; nothing else
 * (no delivery, inbox, routing, workers, admin menus or assets yet).
 * `DBVC_AGENCY_EMERGENCY_DISABLE` stops runtime loading regardless of the
 * option gate.
 *
 * @package DB Version Control
 */

if (! defined('WPINC')) {
    die;
}

spl_autoload_register(
    static function ($class) {
        $prefix = 'Dbvc\\AgencyControl\\';

        if (strpos((string) $class, $prefix) !== 0) {
            return;
        }

        $relative = substr((string) $class, strlen($prefix));
        $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
        $path = __DIR__ . '/src/' . $relative . '.php';

        if (is_readable($path)) {
            require_once $path;
        }
    }
);

if (! class_exists('DBVC_Agency_Control_Addon')) {
    final class DBVC_Agency_Control_Addon
    {
        public const OPTION_ENABLED = 'dbvc_addon_agency_control_enabled';
        public const EMERGENCY_CONSTANT = 'DBVC_AGENCY_EMERGENCY_DISABLE';

        /**
         * @var array<string, mixed>|null
         */
        private static $runtime_state = null;

        /**
         * @return void
         */
        public static function bootstrap()
        {
            self::refresh_runtime_registration();
        }

        /**
         * @return void
         */
        public static function ensure_defaults()
        {
            add_option(self::OPTION_ENABLED, '0');
        }

        /**
         * @return bool
         */
        public static function is_enabled()
        {
            return get_option(self::OPTION_ENABLED, '0') === '1';
        }

        /**
         * @return bool
         */
        public static function is_emergency_disabled()
        {
            return defined(self::EMERGENCY_CONSTANT) && constant(self::EMERGENCY_CONSTANT);
        }

        /**
         * @return string
         */
        public static function get_gate_state()
        {
            $enabled = self::is_enabled();

            return \Dbvc\ConnectedProtocol\RoleGate::state(
                $enabled,
                self::is_emergency_disabled(),
                $enabled ? \Dbvc\AgencyControl\Storage\Schema::is_ready() : false,
                ! is_multisite()
            );
        }

        /**
         * Administrator panel (read-only tables + invitation form) for the Add-ons tab.
         *
         * @return void
         */
        public static function render_admin_panel()
        {
            \Dbvc\AgencyControl\Admin\HubPanel::render();
        }

        /**
         * @return array<string, mixed>|null Null while the hub is not enabled.
         */
        public static function get_runtime_state()
        {
            return self::$runtime_state;
        }

        /**
         * @return void
         */
        public static function refresh_runtime_registration()
        {
            self::$runtime_state = null;
            \Dbvc\AgencyControl\Runtime::unregister();

            if (self::is_emergency_disabled() || ! self::is_enabled() || is_multisite()) {
                return;
            }

            self::$runtime_state = \Dbvc\AgencyControl\Runtime::boot();
        }

        /**
         * @return array<string, array<string, mixed>>
         */
        public static function get_settings_groups()
        {
            return [
                'activation' => [
                    'label' => __('Activation', 'dbvc'),
                    'fields' => [
                        self::OPTION_ENABLED,
                    ],
                ],
            ];
        }

        /**
         * @return array<string, array<string, mixed>>
         */
        public static function get_field_meta()
        {
            return [
                self::OPTION_ENABLED => [
                    'label' => __('Enable Agency Control (studio hub)', 'dbvc'),
                    'input' => 'checkbox',
                    'help' => __('Marks this installation as the designated studio hub: enrollment invitations, application-password principals per environment, and durable observation receipt under dbvc-agency/v1. Requires neither Bricks, the Visual Editor, nor the connector. Delivery, routing, and review arrive with the next reporting step.', 'dbvc'),
                ],
            ];
        }

        /**
         * @return array<string, string>
         */
        public static function get_all_settings()
        {
            self::ensure_defaults();

            return [
                self::OPTION_ENABLED => (string) get_option(self::OPTION_ENABLED, '0'),
            ];
        }

        /**
         * @param array<string, mixed> $request_data
         * @return array<string, mixed>
         */
        public static function save_settings(array $request_data)
        {
            $enabled = isset($request_data[self::OPTION_ENABLED]) ? '1' : '0';
            $errors = [];

            if ($enabled === '1' && is_multisite()) {
                $errors[] = __('Agency Control supports single-site WordPress only.', 'dbvc');
                $enabled = '0';
            }

            update_option(self::OPTION_ENABLED, $enabled);

            if ($enabled === '1' && ! self::is_emergency_disabled()) {
                // Explicit lifecycle step: install hub stores and the service-user role under the administrator's action.
                if (! \Dbvc\AgencyControl\Storage\Schema::install()) {
                    $errors[] = __('Hub tables could not be created; the module is held in migration_required.', 'dbvc');
                }
            }

            self::refresh_runtime_registration();

            return [
                'values' => [self::OPTION_ENABLED => $enabled],
                'errors' => $errors,
            ];
        }
    }
}
