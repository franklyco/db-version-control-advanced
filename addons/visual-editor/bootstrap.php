<?php

if (! defined('WPINC')) {
    die;
}

spl_autoload_register(
    static function ($class) {
        $prefix = 'Dbvc\\VisualEditor\\';

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

require_once __DIR__ . '/src/Support/helpers.php';

if (! class_exists('DBVC_Visual_Editor_Addon')) {
    final class DBVC_Visual_Editor_Addon
    {
        public const OPTION_ENABLED = 'dbvc_addon_visual_editor_enabled';
        public const OPTION_SHARED_GLOBAL_FIELD_NAMES = 'dbvc_visual_editor_shared_global_field_names';
        public const OPTION_SETTINGS_VERSION = 'dbvc_visual_editor_settings_version';
        public const SETTINGS_VERSION = 2;
        public const DEFAULT_SHARED_GLOBAL_FIELD_NAMES = 'settings_globals_default_posts';

        /**
         * @var \Dbvc\VisualEditor\Bootstrap\Addon|null
         */
        private static $runtime = null;

        /**
         * @return void
         */
        public static function bootstrap()
        {
            self::ensure_defaults();
            self::refresh_runtime_registration();
        }

        /**
         * @return void
         */
        public static function ensure_defaults()
        {
            add_option(self::OPTION_ENABLED, '0');
            add_option(self::OPTION_SHARED_GLOBAL_FIELD_NAMES, self::DEFAULT_SHARED_GLOBAL_FIELD_NAMES);
            add_option(self::OPTION_SETTINGS_VERSION, (string) self::SETTINGS_VERSION);
        }

        /**
         * @return bool
         */
        public static function is_enabled()
        {
            return get_option(self::OPTION_ENABLED, '0') === '1';
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
                'toolbar' => [
                    'label' => __('Toolbar 2.0', 'dbvc'),
                    'fields' => [
                        self::OPTION_SHARED_GLOBAL_FIELD_NAMES,
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
                    'label' => __('Enable Visual Editor', 'dbvc'),
                    'input' => 'checkbox',
                    'help' => __('Turn on to register the frontend visual editor runtime, admin-bar toggle, and authenticated REST endpoints for supported Bricks singular pages.', 'dbvc'),
                ],
                self::OPTION_SHARED_GLOBAL_FIELD_NAMES => [
                    'label' => __('Shared global option field names', 'dbvc'),
                    'input' => 'textarea',
                    'rows' => '4',
                    'help' => __('Enter ACF options field names, one per line or comma-separated. The Toolbar Shared Globals panel will only expose configured option-owned relationship/post_object fields with verified ACF metadata.', 'dbvc'),
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
                self::OPTION_SHARED_GLOBAL_FIELD_NAMES => (string) get_option(self::OPTION_SHARED_GLOBAL_FIELD_NAMES, self::DEFAULT_SHARED_GLOBAL_FIELD_NAMES),
            ];
        }

        /**
         * @param array<string, mixed> $request_data
         * @return array<string, mixed>
         */
        public static function save_settings(array $request_data)
        {
            $current = self::get_all_settings();
            $values = [
                self::OPTION_ENABLED => isset($request_data[self::OPTION_ENABLED]) ? '1' : '0',
                self::OPTION_SHARED_GLOBAL_FIELD_NAMES => self::sanitize_shared_global_field_names(
                    isset($request_data[self::OPTION_SHARED_GLOBAL_FIELD_NAMES])
                        ? (string) wp_unslash($request_data[self::OPTION_SHARED_GLOBAL_FIELD_NAMES])
                        : (string) $current[self::OPTION_SHARED_GLOBAL_FIELD_NAMES]
                ),
            ];

            foreach ($values as $option_key => $option_value) {
                update_option($option_key, $option_value);
            }

            self::refresh_runtime_registration();

            return [
                'values' => $values,
                'errors' => [],
            ];
        }

        /**
         * @return array<int, string>
         */
        public static function get_shared_global_field_names()
        {
            self::ensure_defaults();

            $raw = (string) get_option(self::OPTION_SHARED_GLOBAL_FIELD_NAMES, self::DEFAULT_SHARED_GLOBAL_FIELD_NAMES);
            $names = self::parse_shared_global_field_names($raw);

            /**
             * Filter the ACF options field names available in the Visual Editor Toolbar Shared Globals panel.
             *
             * @param array<int, string> $names ACF options field names.
             */
            $filtered = apply_filters('dbvc_visual_editor_shared_global_field_names', $names);

            return is_array($filtered)
                ? array_values(array_slice(array_unique(array_filter(array_map('sanitize_key', $filtered))), 0, 50))
                : $names;
        }

        /**
         * @param string $raw
         * @return string
         */
        public static function sanitize_shared_global_field_names($raw)
        {
            return implode("\n", self::parse_shared_global_field_names($raw));
        }

        /**
         * @param string $raw
         * @return array<int, string>
         */
        private static function parse_shared_global_field_names($raw)
        {
            $parts = preg_split('/[\s,]+/', (string) $raw);
            if (! is_array($parts)) {
                return [];
            }

            $names = [];
            foreach ($parts as $part) {
                $name = sanitize_key((string) $part);
                if ($name === '' || isset($names[$name])) {
                    continue;
                }

                $names[$name] = $name;
                if (count($names) >= 50) {
                    break;
                }
            }

            return array_values($names);
        }

        /**
         * @return void
         */
        public static function refresh_runtime_registration()
        {
            remove_filter('rest_post_dispatch', [self::class, 'prevent_rest_caching'], 10);

            if (self::$runtime instanceof \Dbvc\VisualEditor\Bootstrap\Addon) {
                self::$runtime->unregister();
                self::$runtime = null;
            }

            if (! self::is_enabled()) {
                return;
            }

            self::$runtime = new \Dbvc\VisualEditor\Bootstrap\Addon(__FILE__);
            self::$runtime->register();
            add_filter('rest_post_dispatch', [self::class, 'prevent_rest_caching'], 10, 3);
        }

        /**
         * @param mixed $result
         * @param \WP_REST_Server $server
         * @param \WP_REST_Request $request
         * @return mixed
         */
        public static function prevent_rest_caching($result, $server, $request)
        {
            unset($server);

            if (! ($request instanceof \WP_REST_Request)) {
                return $result;
            }

            $route = (string) $request->get_route();
            if (strpos($route, '/dbvc/v1/visual-editor') !== 0) {
                return $result;
            }

            if ($result instanceof \WP_REST_Response) {
                $result->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
                $result->header('Pragma', 'no-cache');
                $result->header('Expires', 'Wed, 11 Jan 1984 05:00:00 GMT');
                $result->header('Vary', 'Authorization, Cookie');
            }

            return $result;
        }
    }
}
