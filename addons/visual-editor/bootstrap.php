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
        public const OPTION_SETTINGS_VERSION = 'dbvc_visual_editor_settings_version';
        public const SETTINGS_VERSION = 1;

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
            $values = [
                self::OPTION_ENABLED => isset($request_data[self::OPTION_ENABLED]) ? '1' : '0',
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
