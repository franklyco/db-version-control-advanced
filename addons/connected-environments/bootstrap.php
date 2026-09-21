<?php
/**
 * DBVC Connected Environments add-on (connector role).
 *
 * Disabled by default. While disabled this file only defines the settings
 * owner class, registers a lazy autoloader and attaches the operational
 * option exclusion filters; it creates no tables, options, routes, cron
 * events, listeners or assets. The emergency constant
 * `DBVC_CONNECTED_EMERGENCY_DISABLE` stops runtime registration before any
 * module service loads, even when the option gate is on.
 *
 * @package DB Version Control
 */

if (! defined('WPINC')) {
    die;
}

spl_autoload_register(
    static function ($class) {
        $prefix = 'Dbvc\\Connected\\';

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

if (! class_exists('DBVC_Connected_Environments_Addon')) {
    final class DBVC_Connected_Environments_Addon
    {
        public const OPTION_ENABLED = 'dbvc_addon_connected_environments_enabled';
        /** Separate gate for approved executions; off by default and never implied by enabling observation. */
        public const OPTION_APPLY_ENABLED = 'dbvc_addon_connected_environments_apply_enabled';
        public const EMERGENCY_CONSTANT = 'DBVC_CONNECTED_EMERGENCY_DISABLE';

        /**
         * @var \Dbvc\Connected\Runtime|null
         */
        private static $runtime = null;

        /**
         * @return void
         */
        public static function bootstrap()
        {
            add_filter('dbvc_excluded_option_keys', [self::class, 'filter_excluded_option_keys'], 10, 1);
            add_filter('dbvc_import_options_data', [self::class, 'filter_import_options_data'], 10, 1);
            self::refresh_runtime_registration();
        }

        /**
         * Settings defaults are created only from the administrator settings
         * page or a settings save, never on ordinary requests.
         *
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
         * @return bool
         */
        public static function is_runtime_supported()
        {
            return ! is_multisite();
        }

        /**
         * Pure gate state for the current request. Reads one cached option
         * plus, when enabled, the schema-version option.
         *
         * @return string
         */
        public static function get_gate_state()
        {
            $enabled = self::is_enabled();
            $schema_ready = $enabled ? \Dbvc\Connected\Storage\Schema::is_ready() : false;

            return \Dbvc\ConnectedProtocol\RoleGate::state(
                $enabled,
                self::is_emergency_disabled(),
                $schema_ready,
                self::is_runtime_supported()
            );
        }

        /**
         * @return bool
         */
        public static function is_runtime_registered()
        {
            return self::$runtime instanceof \Dbvc\Connected\Runtime;
        }

        /**
         * @return \Dbvc\Connected\Runtime|null
         */
        public static function runtime()
        {
            return self::$runtime;
        }

        /**
         * Register or unregister runtime hooks based on the gate.
         *
         * @return void
         */
        public static function refresh_runtime_registration()
        {
            if (self::$runtime instanceof \Dbvc\Connected\Runtime) {
                self::$runtime->unregister();
                self::$runtime = null;
            }

            if (self::is_emergency_disabled() || ! self::is_enabled() || ! self::is_runtime_supported()) {
                return;
            }

            self::$runtime = new \Dbvc\Connected\Runtime();
            self::$runtime->register();
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
                        self::OPTION_APPLY_ENABLED,
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
                    'label' => __('Enable Connected Environments (connector)', 'dbvc'),
                    'input' => 'checkbox',
                    'help' => __('Turn on local observation of Bricks global classes and variables: save-side dirty markers, background snapshots, and a durable local outbox. This release performs no network transport and never applies content. Requires single-site WordPress.', 'dbvc'),
                ],
                self::OPTION_APPLY_ENABLED => [
                    'label' => __('Allow approved releases to be applied here', 'dbvc'),
                    'input' => 'checkbox',
                    'help' => __('Off by default. When on, the connector executes releases the studio hub has explicitly approved for this environment, only after its own prepare receipt, with a conditional write that refuses to overwrite a container edited in the meantime, a journalled before image and verified after-state. Bricks global classes and variables only in this release.', 'dbvc'),
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
                self::OPTION_APPLY_ENABLED => (string) get_option(self::OPTION_APPLY_ENABLED, '0'),
            ];
        }

        /**
         * @return bool
         */
        public static function is_apply_enabled()
        {
            return self::is_enabled() && ! self::is_emergency_disabled() && get_option(self::OPTION_APPLY_ENABLED, '0') === '1';
        }

        /**
         * Capability and nonce validation happen in the unified DBVC settings
         * save handler before this method is called.
         *
         * @param array<string, mixed> $request_data
         * @return array<string, mixed>
         */
        public static function save_settings(array $request_data)
        {
            $was_enabled = self::is_enabled();
            $enabled = isset($request_data[self::OPTION_ENABLED]) ? '1' : '0';
            $errors = [];

            if ($enabled === '1' && ! self::is_runtime_supported()) {
                $errors[] = __('Connected Environments supports single-site WordPress only; multisite enrollment is not designed yet.', 'dbvc');
                $enabled = '0';
            }

            update_option(self::OPTION_ENABLED, $enabled);
            $apply_enabled = $enabled === '1' && isset($request_data[self::OPTION_APPLY_ENABLED]) ? '1' : '0';
            update_option(self::OPTION_APPLY_ENABLED, $apply_enabled);

            if ($enabled === '1' && ! self::is_emergency_disabled()) {
                // Explicit lifecycle step: migrate schema under the administrator's action, then initialize provisional identity.
                if (! \Dbvc\Connected\Storage\Schema::install()) {
                    $errors[] = __('Connector tables could not be created; the module is held in migration_required.', 'dbvc');
                } else {
                    (new \Dbvc\Connected\Storage\StateStore())->initialize_provisional();
                    if (! $was_enabled) {
                        // Edits made while disabled are unknown; request a full reconciliation of every supported domain.
                        self::request_reconciliation('', 'enable');
                    }
                }
            } elseif ($enabled === '0' && $was_enabled) {
                \Dbvc\Connected\Capture\DirtyCapture::unschedule_processing();
            }

            self::refresh_runtime_registration();

            return [
                'values' => [self::OPTION_ENABLED => $enabled, self::OPTION_APPLY_ENABLED => $apply_enabled],
                'errors' => $errors,
            ];
        }

        /**
         * Explicit reconciliation signal for one or every supported domain.
         *
         * @param string $domain Empty for all domains.
         * @param string $causation_id
         * @return array<string, int|false> Resulting generation per domain.
         */
        public static function request_reconciliation($domain = '', $causation_id = '')
        {
            if (! \Dbvc\Connected\Storage\Schema::is_ready()) {
                return [];
            }

            $capture = new \Dbvc\Connected\Capture\DirtyCapture(new \Dbvc\Connected\Storage\JobStore(), \Dbvc\Connected\Adapters\DomainRegistry::domains());
            $domains = $domain !== '' ? [$domain] : \Dbvc\Connected\Adapters\DomainRegistry::domains();
            $results = [];
            foreach ($domains as $candidate) {
                $results[$candidate] = $capture->signal_reconciliation($candidate, $causation_id);
            }

            return $results;
        }

        /**
         * @return array<string, mixed>
         */
        public static function get_status_report()
        {
            return \Dbvc\Connected\Inspection\StatusReport::build(self::get_gate_state());
        }

        /**
         * Read-only inbox table for the Add-ons tab.
         *
         * @return void
         */
        public static function render_admin_inbox()
        {
            \Dbvc\Connected\Admin\InboxPanel::render();
        }

        /**
         * @param mixed $excluded
         * @return array<int, string>
         */
        public static function filter_excluded_option_keys($excluded)
        {
            return \Dbvc\Connected\Lifecycle\TransferExclusions::filter_excluded_option_keys($excluded);
        }

        /**
         * @param mixed $options
         * @return array<string, mixed>
         */
        public static function filter_import_options_data($options)
        {
            return \Dbvc\Connected\Lifecycle\TransferExclusions::filter_import_options_data($options);
        }
    }
}
