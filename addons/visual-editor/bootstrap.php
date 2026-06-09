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
        public const OPTION_EXCLUDED_POST_TYPES = 'dbvc_visual_editor_excluded_post_types';
        public const OPTION_EXCLUDED_TAXONOMIES = 'dbvc_visual_editor_excluded_taxonomies';
        public const OPTION_SETTINGS_VERSION = 'dbvc_visual_editor_settings_version';
        public const SETTINGS_VERSION = 3;
        public const DEFAULT_SHARED_GLOBAL_FIELD_NAMES = 'settings_globals_default_posts';
        public const DEFAULT_EXCLUDED_POST_TYPES = 'bricks_template';
        public const DEFAULT_EXCLUDED_TAXONOMIES = "template_tag\ntemplate_bundle";

        /**
         * @var \Dbvc\VisualEditor\Bootstrap\Addon|null
         */
        private static $runtime = null;

        /**
         * @var \Dbvc\VisualEditor\Admin\SettingsPage|null
         */
        private static $settings_page = null;

        /**
         * @return void
         */
        public static function bootstrap()
        {
            self::ensure_defaults();
            self::register_admin_settings_page();
            self::refresh_runtime_registration();
        }

        /**
         * @return void
         */
        public static function ensure_defaults()
        {
            add_option(self::OPTION_ENABLED, '0');
            add_option(self::OPTION_SHARED_GLOBAL_FIELD_NAMES, self::DEFAULT_SHARED_GLOBAL_FIELD_NAMES);
            add_option(self::OPTION_EXCLUDED_POST_TYPES, self::DEFAULT_EXCLUDED_POST_TYPES);
            add_option(self::OPTION_EXCLUDED_TAXONOMIES, self::DEFAULT_EXCLUDED_TAXONOMIES);
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
                'visibility' => [
                    'label' => __('Frontend Content Visibility', 'dbvc'),
                    'fields' => [
                        self::OPTION_EXCLUDED_POST_TYPES,
                        self::OPTION_EXCLUDED_TAXONOMIES,
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
                self::OPTION_EXCLUDED_POST_TYPES => [
                    'label' => __('Excluded post types', 'dbvc'),
                    'input' => 'textarea',
                    'rows' => '4',
                    'help' => __('Enter post type slugs, one per line or comma-separated. Excluded post types are omitted from Visual Editor frontend object navigation, descriptor surfaces, and connected-item panel searches. Defaults exclude Bricks templates.', 'dbvc'),
                ],
                self::OPTION_EXCLUDED_TAXONOMIES => [
                    'label' => __('Excluded taxonomies', 'dbvc'),
                    'input' => 'textarea',
                    'rows' => '4',
                    'help' => __('Enter taxonomy slugs, one per line or comma-separated. Excluded taxonomies are omitted from Visual Editor frontend object navigation, descriptor surfaces, and linked-term panel searches. Defaults exclude Bricks Template Tag and Template Bundle taxonomies.', 'dbvc'),
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
                self::OPTION_EXCLUDED_POST_TYPES => (string) get_option(self::OPTION_EXCLUDED_POST_TYPES, self::DEFAULT_EXCLUDED_POST_TYPES),
                self::OPTION_EXCLUDED_TAXONOMIES => (string) get_option(self::OPTION_EXCLUDED_TAXONOMIES, self::DEFAULT_EXCLUDED_TAXONOMIES),
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
                self::OPTION_EXCLUDED_POST_TYPES => self::sanitize_key_list_setting(
                    isset($request_data[self::OPTION_EXCLUDED_POST_TYPES])
                        ? (string) wp_unslash($request_data[self::OPTION_EXCLUDED_POST_TYPES])
                        : (string) $current[self::OPTION_EXCLUDED_POST_TYPES],
                    100
                ),
                self::OPTION_EXCLUDED_TAXONOMIES => self::sanitize_key_list_setting(
                    isset($request_data[self::OPTION_EXCLUDED_TAXONOMIES])
                        ? (string) wp_unslash($request_data[self::OPTION_EXCLUDED_TAXONOMIES])
                        : (string) $current[self::OPTION_EXCLUDED_TAXONOMIES],
                    100
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
         * @return array<int, string>
         */
        public static function get_excluded_post_types()
        {
            self::ensure_defaults();

            $raw = (string) get_option(self::OPTION_EXCLUDED_POST_TYPES, self::DEFAULT_EXCLUDED_POST_TYPES);
            $names = self::parse_key_list($raw, 100);

            /**
             * Filter post type slugs excluded from Visual Editor frontend surfaces.
             *
             * @param array<int, string> $names Post type slugs.
             */
            $filtered = apply_filters('dbvc_visual_editor_excluded_post_types', $names);

            return is_array($filtered)
                ? self::normalize_key_list($filtered, 100)
                : $names;
        }

        /**
         * @return array<int, string>
         */
        public static function get_excluded_taxonomies()
        {
            self::ensure_defaults();

            $raw = (string) get_option(self::OPTION_EXCLUDED_TAXONOMIES, self::DEFAULT_EXCLUDED_TAXONOMIES);
            $names = self::parse_key_list($raw, 100);

            /**
             * Filter taxonomy slugs excluded from Visual Editor frontend surfaces.
             *
             * @param array<int, string> $names Taxonomy slugs.
             */
            $filtered = apply_filters('dbvc_visual_editor_excluded_taxonomies', $names);

            return is_array($filtered)
                ? self::normalize_key_list($filtered, 100)
                : $names;
        }

        /**
         * @param string $post_type
         * @return bool
         */
        public static function is_post_type_excluded($post_type)
        {
            $post_type = sanitize_key((string) $post_type);

            return $post_type !== '' && in_array($post_type, self::get_excluded_post_types(), true);
        }

        /**
         * @param string $taxonomy
         * @return bool
         */
        public static function is_taxonomy_excluded($taxonomy)
        {
            $taxonomy = sanitize_key((string) $taxonomy);

            return $taxonomy !== '' && in_array($taxonomy, self::get_excluded_taxonomies(), true);
        }

        /**
         * @param array<int, string> $post_types
         * @return array<int, string>
         */
        public static function filter_post_types(array $post_types)
        {
            $excluded = self::get_excluded_post_types();

            return array_values(
                array_filter(
                    self::normalize_key_list($post_types, 200),
                    static function ($post_type) use ($excluded) {
                        return ! in_array($post_type, $excluded, true);
                    }
                )
            );
        }

        /**
         * @param array<int, string> $taxonomies
         * @return array<int, string>
         */
        public static function filter_taxonomies(array $taxonomies)
        {
            $excluded = self::get_excluded_taxonomies();

            return array_values(
                array_filter(
                    self::normalize_key_list($taxonomies, 200),
                    static function ($taxonomy) use ($excluded) {
                        return ! in_array($taxonomy, $excluded, true);
                    }
                )
            );
        }

        /**
         * @param mixed $descriptor
         * @return bool
         */
        public static function is_descriptor_excluded($descriptor)
        {
            if (! is_object($descriptor)) {
                return false;
            }

            $entity = isset($descriptor->entity) && is_array($descriptor->entity) ? $descriptor->entity : [];
            $owner = isset($descriptor->owner) && is_array($descriptor->owner) ? $descriptor->owner : [];
            $source = isset($descriptor->source) && is_array($descriptor->source) ? $descriptor->source : [];

            foreach ([$entity, $owner] as $context) {
                $type = isset($context['type']) ? sanitize_key((string) $context['type']) : '';
                $subtype = isset($context['subtype']) ? sanitize_key((string) $context['subtype']) : '';

                if ($type === 'post' && self::is_post_type_excluded($subtype)) {
                    return true;
                }

                if ($type === 'term' && self::is_taxonomy_excluded($subtype)) {
                    return true;
                }
            }

            $query_target_post_type = isset($source['query_target_post_type']) ? sanitize_key((string) $source['query_target_post_type']) : '';
            if (self::is_post_type_excluded($query_target_post_type)) {
                return true;
            }

            $taxonomy = isset($source['taxonomy']) ? sanitize_key((string) $source['taxonomy']) : '';
            if (self::is_taxonomy_excluded($taxonomy)) {
                return true;
            }

            $reference_taxonomies = isset($source['reference_taxonomies']) && is_array($source['reference_taxonomies'])
                ? self::normalize_key_list($source['reference_taxonomies'], 100)
                : [];
            if (! empty($reference_taxonomies) && empty(self::filter_taxonomies($reference_taxonomies))) {
                return true;
            }

            $reference_post_types = isset($source['reference_post_types']) && is_array($source['reference_post_types'])
                ? self::normalize_key_list($source['reference_post_types'], 100)
                : [];
            if (! empty($reference_post_types) && empty(self::filter_post_types($reference_post_types))) {
                return true;
            }

            return false;
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
         * @param int    $limit
         * @return string
         */
        public static function sanitize_key_list_setting($raw, $limit = 100)
        {
            return implode("\n", self::parse_key_list($raw, $limit));
        }

        /**
         * @param string $raw
         * @return array<int, string>
         */
        private static function parse_shared_global_field_names($raw)
        {
            return self::parse_key_list($raw, 50);
        }

        /**
         * @param string $raw
         * @param int    $limit
         * @return array<int, string>
         */
        private static function parse_key_list($raw, $limit = 100)
        {
            $parts = preg_split('/[\s,]+/', (string) $raw);
            if (! is_array($parts)) {
                return [];
            }

            return self::normalize_key_list($parts, $limit);
        }

        /**
         * @param array<int, mixed> $values
         * @param int               $limit
         * @return array<int, string>
         */
        private static function normalize_key_list(array $values, $limit = 100)
        {
            $normalized = [];
            $limit = max(1, absint($limit));

            foreach ($values as $value) {
                $key = sanitize_key((string) $value);
                if ($key === '' || isset($normalized[$key])) {
                    continue;
                }

                $normalized[$key] = $key;
                if (count($normalized) >= $limit) {
                    break;
                }
            }

            return array_values($normalized);
        }

        /**
         * @return void
         */
        private static function register_admin_settings_page()
        {
            if (! is_admin() || self::$settings_page instanceof \Dbvc\VisualEditor\Admin\SettingsPage) {
                return;
            }

            self::$settings_page = new \Dbvc\VisualEditor\Admin\SettingsPage();
            self::$settings_page->register();
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
