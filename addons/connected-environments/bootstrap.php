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
                'coverage' => [
                    'label' => __('Post-type coverage', 'dbvc'),
                    'fields' => [
                        \Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_DOMAINS,
                        \Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_APPLY_DOMAINS,
                        \Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_TYPE_META,
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
                \Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_DOMAINS => [
                    'label' => __('Observed post types (one slug per line)', 'dbvc'),
                    'input' => 'textarea',
                    'rows' => 4,
                    'help' => __('Opt in custom post types to observe as wp.post:<type> domains, one post-type slug per line (commas also work). The service post type (wp.service alias) is always observed and never listed here; media and built-in editor/theme types are excluded automatically.', 'dbvc'),
                ],
                \Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_APPLY_DOMAINS => [
                    'label' => __('Post types approved releases may apply (subset of observed)', 'dbvc'),
                    'input' => 'textarea',
                    'rows' => 3,
                    'help' => __('One slug per line. Even with the apply gate on, an approved release only writes a custom post type listed here (a type must also be observed above). Empty means no custom post type is applied. The wp.service alias is always apply-eligible.', 'dbvc'),
                ],
                \Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_TYPE_META => [
                    'label' => __('Per-type meta & term policy (JSON)', 'dbvc'),
                    'input' => 'textarea',
                    'rows' => 5,
                    'help' => __('Optional JSON keyed by post-type slug: {"portfolio": {"allow": ["client"], "deny": ["_internal"], "create_terms": true}}. "allow" (non-empty) projects only those meta keys; "deny" excludes keys; "create_terms" lets apply create a missing taxonomy term. Masked/privacy fields are never synced regardless. Leave blank for defaults (all meta minus the ignored set, create_terms off).', 'dbvc'),
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
                \Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_DOMAINS => self::format_slug_list(get_option(\Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_DOMAINS, [])),
                \Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_APPLY_DOMAINS => self::format_slug_list(get_option(\Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_APPLY_DOMAINS, [])),
                \Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_TYPE_META => self::format_type_meta(get_option(\Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_TYPE_META, [])),
            ];
        }

        /**
         * Newline-joined slug list for a textarea.
         *
         * @param mixed $value
         * @return string
         */
        private static function format_slug_list($value)
        {
            $value = is_array($value) ? $value : [];

            return implode("\n", array_map('strval', $value));
        }

        /**
         * Pretty JSON for the per-type policy textarea ('' when empty).
         *
         * @param mixed $value
         * @return string
         */
        private static function format_type_meta($value)
        {
            if (! is_array($value) || $value === []) {
                return '';
            }

            return (string) wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        /**
         * Parse a textarea slug list (newlines or commas) into sanitized slugs.
         *
         * @param string $raw
         * @return array<int, string>
         */
        private static function parse_slug_list($raw)
        {
            $out = [];
            foreach (preg_split('/[\s,]+/', (string) $raw) as $token) {
                $slug = sanitize_key((string) $token);
                if ($slug !== '') {
                    $out[$slug] = true;
                }
            }

            return array_keys($out);
        }

        /**
         * Parse the per-type meta/term policy JSON into a normalized array keyed by
         * post-type slug. Empty input clears the policy ([]); invalid JSON returns
         * null so the caller keeps the previous value and surfaces an error.
         *
         * @param string $raw
         * @return array<string, array<string, mixed>>|null
         */
        private static function parse_type_meta($raw)
        {
            $raw = trim((string) $raw);
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return null;
            }
            $out = [];
            foreach ($decoded as $type => $policy) {
                $type = sanitize_key((string) $type);
                if ($type === '' || ! is_array($policy)) {
                    continue;
                }
                $entry = [];
                if (isset($policy['allow'])) {
                    $entry['allow'] = array_values(array_filter(array_map('strval', (array) $policy['allow']), 'strlen'));
                }
                if (isset($policy['deny'])) {
                    $entry['deny'] = array_values(array_filter(array_map('strval', (array) $policy['deny']), 'strlen'));
                }
                if (array_key_exists('create_terms', $policy)) {
                    $entry['create_terms'] = (bool) $policy['create_terms'];
                }
                if ($entry !== []) {
                    $out[$type] = $entry;
                }
            }

            return $out;
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

            // Post-type coverage config. Each key is written only when present in the request, so a
            // programmatic save_settings([OPTION_ENABLED => '1']) (tests, CLI, enrollment) never wipes
            // the operator's allow-lists. Updated before the enable block so an enable-from-disabled
            // full reconciliation already covers any newly opted-in CPT.
            $added_observe = [];
            $coverage_changed = false;
            if (array_key_exists(\Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_DOMAINS, $request_data)) {
                $before_observe = \Dbvc\Connected\Adapters\DomainRegistry::opted_in_post_types();
                update_option(\Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_DOMAINS, self::parse_slug_list($request_data[\Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_DOMAINS]));
                \Dbvc\Connected\Adapters\DomainRegistry::reset_observers();
                $added_observe = array_values(array_diff(\Dbvc\Connected\Adapters\DomainRegistry::opted_in_post_types(), $before_observe));
                $coverage_changed = true;
            }
            if (array_key_exists(\Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_APPLY_DOMAINS, $request_data)) {
                update_option(\Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_APPLY_DOMAINS, self::parse_slug_list($request_data[\Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_APPLY_DOMAINS]));
                $coverage_changed = true;
            }
            if (array_key_exists(\Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_TYPE_META, $request_data)) {
                $parsed_meta = self::parse_type_meta((string) $request_data[\Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_TYPE_META]);
                if ($parsed_meta === null) {
                    $errors[] = __('Per-type meta & term policy must be valid JSON; the previous value was kept.', 'dbvc');
                } else {
                    update_option(\Dbvc\Connected\Adapters\DomainRegistry::OPTION_POST_TYPE_META, $parsed_meta);
                    $coverage_changed = true;
                }
            }
            if ($coverage_changed) {
                \Dbvc\Connected\Adapters\DomainRegistry::reset_observers();
            }

            if ($enabled === '1' && ! self::is_emergency_disabled()) {
                // Explicit lifecycle step: migrate schema under the administrator's action, then initialize provisional identity.
                if (! \Dbvc\Connected\Storage\Schema::install()) {
                    $errors[] = __('Connector tables could not be created; the module is held in migration_required.', 'dbvc');
                } else {
                    (new \Dbvc\Connected\Storage\StateStore())->initialize_provisional();
                    if (! $was_enabled) {
                        // Edits made while disabled are unknown; request a full reconciliation of every supported domain (incl. newly opted-in CPTs).
                        self::request_reconciliation('', 'enable');
                    } elseif ($added_observe !== []) {
                        // Already enabled: begin observing each newly opted-in CPT so it does not wait for the next save.
                        foreach ($added_observe as $added_type) {
                            self::request_reconciliation(\Dbvc\Connected\Adapters\DomainRegistry::DOMAIN_POST_PREFIX . $added_type, 'coverage_opt_in');
                        }
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
