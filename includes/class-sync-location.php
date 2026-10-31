<?php
/**
 * Sync folder location: the opt-in absolute root outside the web root.
 *
 * Security finding S1: the `dbvc_sync_path` option is always resolved under
 * ABSPATH, so the sync folder (options.json, entity exports, snapshots) is
 * web-reachable. Its .htaccess deny is ignored by nginx, including the nginx
 * front end on Cloudways, where the server config cannot be edited. The
 * durable fix is to keep the folder outside the web root, for example in the
 * Cloudways `private_html` folder beside `public_html`.
 *
 * The absolute root can only be set in code: the `DBVC_SYNC_PATH_ABSOLUTE`
 * constant in wp-config.php, or the `dbvc_sync_path_absolute` filter from a
 * must-use plugin. It is deliberately not an admin setting. Only someone who
 * can already write PHP on the server can point DBVC's writers at an arbitrary
 * folder, and configuration portability cannot carry a server path between
 * environments.
 *
 * @package DB Version Control
 */

if (! defined('WPINC')) {
    die;
}

if (! class_exists('DBVC_Sync_Location')) {
    final class DBVC_Sync_Location
    {
        const CONSTANT_NAME = 'DBVC_SYNC_PATH_ABSOLUTE';
        const SITE_HEALTH_TEST = 'dbvc_sync_folder_location';

        /**
         * Validation results keyed by the raw configured value.
         *
         * @var array<string,string|WP_Error>
         */
        private static $validated = [];

        /**
         * Register the Site Health test and the refused-path admin notice.
         *
         * @return void
         */
        public static function register()
        {
            add_filter('site_status_tests', [self::class, 'register_site_health_test']);
            add_action('admin_notices', [self::class, 'render_refused_path_notice']);
        }

        /**
         * The raw absolute path from the constant or the code-level filter.
         *
         * @return string Empty when no absolute root is configured.
         */
        public static function configured_path()
        {
            $raw = defined(self::CONSTANT_NAME) ? constant(self::CONSTANT_NAME) : '';

            /**
             * Filters the absolute sync root that lives outside the web root.
             *
             * For must-use plugins and tests. Admin input never reaches this
             * filter, and the returned value is validated like the constant.
             *
             * @param string $raw The DBVC_SYNC_PATH_ABSOLUTE value, or ''.
             */
            $raw = apply_filters('dbvc_sync_path_absolute', is_string($raw) ? $raw : '');

            return is_string($raw) ? trim($raw) : '';
        }

        /**
         * Whether an absolute root is configured, valid or not.
         *
         * @return bool
         */
        public static function is_configured()
        {
            return self::configured_path() !== '';
        }

        /**
         * The validated absolute sync root, without a trailing slash.
         *
         * @return string Empty when none is configured or the value was refused.
         */
        public static function absolute_root()
        {
            $result = self::validation_result();
            return is_string($result) ? $result : '';
        }

        /**
         * Why the configured absolute root was refused.
         *
         * @return WP_Error|null Null when nothing is configured or it is valid.
         */
        public static function refusal()
        {
            $result = self::validation_result();
            return is_wp_error($result) ? $result : null;
        }

        /**
         * Validate an absolute sync root that must sit outside the web root.
         *
         * Refuses relative paths, traversal segments, unsafe characters, the
         * filesystem root, shallow paths such as `/home`, system folders, any
         * folder inside a web-served root (ABSPATH, wp-content, plugins,
         * themes, uploads), and any folder that contains one, since "clear
         * sync folder" would then delete the site. Symlinks are resolved
         * through the nearest existing ancestor before the containment checks.
         *
         * @param string $path Candidate absolute path.
         * @return string|WP_Error Normalized path without a trailing slash.
         */
        public static function validate_absolute_path($path)
        {
            $path = is_string($path) ? trim($path) : '';
            if ($path === '') {
                return new WP_Error('dbvc_sync_path_empty', __('The absolute sync path is empty.', 'dbvc'));
            }

            foreach (['<', '>', '"', '|', '?', '*', chr(0)] as $char) {
                if (strpos($path, $char) !== false) {
                    return new WP_Error('dbvc_sync_path_unsafe_chars', __('The absolute sync path contains unsafe characters.', 'dbvc'));
                }
            }

            $normalized = wp_normalize_path($path);
            if (! self::is_absolute($normalized)) {
                return new WP_Error('dbvc_sync_path_not_absolute', __('The absolute sync path must start at the filesystem root.', 'dbvc'));
            }

            $segments = array_values(array_filter(explode('/', $normalized), 'strlen'));
            foreach ($segments as $segment) {
                if ($segment === '..' || $segment === '.') {
                    return new WP_Error('dbvc_sync_path_traversal', __('The absolute sync path cannot contain "." or ".." segments.', 'dbvc'));
                }
            }

            $drive_free = preg_replace('#^[A-Za-z]:#', '', $normalized);
            if (count(array_filter(explode('/', $drive_free), 'strlen')) < 2) {
                return new WP_Error('dbvc_sync_path_too_shallow', __('The absolute sync path is the filesystem root or a top-level folder. Use a dedicated folder such as private_html/dbvc-sync.', 'dbvc'));
            }

            $resolved = self::resolve($normalized);
            foreach (self::forbidden_system_roots() as $system_root) {
                if (self::is_within($resolved, $system_root)) {
                    return new WP_Error('dbvc_sync_path_system_folder', __('The absolute sync path is inside a system folder.', 'dbvc'));
                }
            }

            foreach (self::web_roots() as $web_root) {
                if (self::is_within($resolved, $web_root)) {
                    return new WP_Error('dbvc_sync_path_web_reachable', __('The absolute sync path is inside the web root, so it would still be web-reachable.', 'dbvc'));
                }
                if (self::is_within($web_root, $resolved)) {
                    return new WP_Error('dbvc_sync_path_contains_site', __('The absolute sync path contains the WordPress install. Clearing the sync folder would delete the site.', 'dbvc'));
                }
            }

            return untrailingslashit($normalized);
        }

        /**
         * Whether a path resolves inside any web-served WordPress root.
         *
         * A filesystem check only: it never makes an HTTP request.
         *
         * @param string $path Absolute path; it need not exist yet.
         * @return bool
         */
        public static function is_web_reachable($path)
        {
            $resolved = self::resolve(wp_normalize_path((string) $path));
            foreach (self::web_roots() as $web_root) {
                if (self::is_within($resolved, $web_root)) {
                    return true;
                }
            }
            return false;
        }

        /**
         * Whether $path equals $root or sits below it, after resolving both.
         *
         * @param string $path
         * @param string $root
         * @return bool
         */
        public static function is_within($path, $root)
        {
            $path = untrailingslashit(self::resolve(wp_normalize_path((string) $path)));
            $root = untrailingslashit(self::resolve(wp_normalize_path((string) $root)));
            if ($path === '' || $root === '') {
                return false;
            }
            if (self::is_case_insensitive_fs()) {
                $path = strtolower($path);
                $root = strtolower($root);
            }
            return $path === $root || strpos($path . '/', $root . '/') === 0;
        }

        /**
         * Resolve symlinks through the nearest existing ancestor.
         *
         * realpath() fails for folders that do not exist yet, so the existing
         * prefix is resolved and the missing tail is appended unchanged.
         *
         * @param string $path Normalized absolute path.
         * @return string
         */
        public static function resolve($path)
        {
            $path = untrailingslashit(wp_normalize_path((string) $path));
            if ($path === '') {
                return '';
            }

            $tail = [];
            $probe = $path;
            while ($probe !== '' && ! file_exists($probe)) {
                $parent = dirname($probe);
                if ($parent === $probe) {
                    break;
                }
                array_unshift($tail, basename($probe));
                $probe = $parent;
            }

            $real = $probe !== '' ? realpath($probe) : false;
            if ($real === false) {
                return $path;
            }

            $resolved = untrailingslashit(wp_normalize_path($real));
            return $tail ? $resolved . '/' . implode('/', $tail) : ($resolved === '' ? '/' : $resolved);
        }

        /**
         * Register the "sync folder is web-reachable" Site Health test.
         *
         * @param array $tests
         * @return array
         */
        public static function register_site_health_test($tests)
        {
            if (! is_array($tests)) {
                $tests = [];
            }
            $tests['direct'][self::SITE_HEALTH_TEST] = [
                'label' => __('DBVC sync folder location', 'dbvc'),
                'test'  => [self::class, 'site_health_test'],
            ];
            return $tests;
        }

        /**
         * Site Health result for the sync folder location.
         *
         * @return array
         */
        public static function site_health_test()
        {
            $sync_root = function_exists('dbvc_get_sync_path') ? untrailingslashit(dbvc_get_sync_path()) : '';
            $refusal   = self::refusal();
            $exposed   = $sync_root !== '' && self::is_web_reachable($sync_root);

            $result = [
                'label'       => __('The DBVC sync folder is outside the web root', 'dbvc'),
                'status'      => 'good',
                'badge'       => [
                    'label' => __('Security', 'dbvc'),
                    'color' => 'blue',
                ],
                'description' => '<p>' . esc_html(sprintf(
                    /* translators: %s: absolute sync folder path */
                    __('DBVC writes options.json and entity exports to %s, which the web server cannot serve.', 'dbvc'),
                    $sync_root
                )) . '</p>',
                'actions'     => '',
                'test'        => self::SITE_HEALTH_TEST,
            ];

            if ($refusal instanceof WP_Error) {
                $result['status']         = 'critical';
                $result['badge']['color'] = 'red';
                $result['label']          = sprintf(
                    /* translators: %s: constant name */
                    __('DBVC refused %s, so the sync folder stayed web-reachable', 'dbvc'),
                    self::CONSTANT_NAME
                );
                $result['description'] = '<p>' . esc_html($refusal->get_error_message()) . '</p>'
                    . '<p>' . esc_html(sprintf(
                        /* translators: %s: absolute sync folder path */
                        __('DBVC fell back to %s.', 'dbvc'),
                        $sync_root
                    )) . '</p>';
                $result['actions'] = '<p>' . esc_html(self::setup_instructions()) . '</p>';
                return $result;
            }

            if ($exposed) {
                $result['status']         = 'recommended';
                $result['badge']['color'] = 'red';
                $result['label']          = __('The DBVC sync folder is web-reachable', 'dbvc');
                $result['description']    = '<p>' . esc_html(sprintf(
                    /* translators: %s: absolute sync folder path */
                    __('DBVC writes options.json and entity exports to %s, inside the web root. nginx ignores the folder\'s .htaccess, so these files can be downloaded unless the server blocks the path. Secret-shaped option values are redacted, but plugin lists, user roles and settings are not.', 'dbvc'),
                    $sync_root
                )) . '</p>';
                $result['actions'] = '<p>' . esc_html(self::setup_instructions()) . '</p>';
                if (class_exists('DBVC_Sync_Exposure')) {
                    $result['actions'] .= DBVC_Sync_Exposure::guidance_html($sync_root, (string) get_option('dbvc_sync_path', ''));
                }
            }

            return $result;
        }

        /**
         * Show a notice on DBVC screens when the configured root was refused.
         *
         * @return void
         */
        public static function render_refused_path_notice()
        {
            if (! current_user_can('manage_options')) {
                return;
            }
            $refusal = self::refusal();
            if (! $refusal instanceof WP_Error) {
                return;
            }
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            $screen_id = $screen && isset($screen->id) ? (string) $screen->id : '';
            if ($screen_id !== 'plugins' && strpos($screen_id, 'dbvc') === false) {
                return;
            }

            printf(
                '<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
                esc_html(sprintf(
                    /* translators: %s: constant name */
                    __('DBVC refused %s.', 'dbvc'),
                    self::CONSTANT_NAME
                )),
                esc_html($refusal->get_error_message() . ' ' . __('The sync folder stayed at its web-reachable location.', 'dbvc'))
            );
        }

        /**
         * One-line operator instructions for moving the folder.
         *
         * @return string
         */
        public static function setup_instructions()
        {
            return sprintf(
                /* translators: 1: constant name, 2: example path */
                __('Define %1$s in wp-config.php with an absolute path to a dedicated folder outside the web root, for example %2$s on Cloudways. Clearing the sync folder deletes everything inside it, so do not point it at a folder that holds anything else.', 'dbvc'),
                self::CONSTANT_NAME,
                "define('DBVC_SYNC_PATH_ABSOLUTE', '/home/master/applications/<app>/private_html/dbvc-sync');"
            );
        }

        /**
         * Folders WordPress serves over HTTP.
         *
         * @return array<int,string>
         */
        public static function web_roots()
        {
            $roots = [ABSPATH];
            if (defined('WP_CONTENT_DIR')) {
                $roots[] = WP_CONTENT_DIR;
            }
            if (defined('WP_PLUGIN_DIR')) {
                $roots[] = WP_PLUGIN_DIR;
            }
            if (defined('WPMU_PLUGIN_DIR')) {
                $roots[] = WPMU_PLUGIN_DIR;
            }
            if (function_exists('get_theme_root')) {
                $roots[] = get_theme_root();
            }
            if (function_exists('wp_get_upload_dir')) {
                $uploads = wp_get_upload_dir();
                if (! empty($uploads['basedir'])) {
                    $roots[] = $uploads['basedir'];
                }
            }

            $normalized = [];
            foreach ($roots as $root) {
                if (is_string($root) && $root !== '') {
                    $normalized[] = untrailingslashit(wp_normalize_path($root));
                }
            }
            return array_values(array_unique($normalized));
        }

        /**
         * Clear the validation cache. For tests that change the filter.
         *
         * @return void
         */
        public static function reset_cache()
        {
            self::$validated = [];
        }

        /**
         * @return string|WP_Error|null
         */
        private static function validation_result()
        {
            $raw = self::configured_path();
            if ($raw === '') {
                return null;
            }
            if (! array_key_exists($raw, self::$validated)) {
                self::$validated[$raw] = self::validate_absolute_path($raw);
            }
            return self::$validated[$raw];
        }

        /**
         * System folders the sync root may never live in.
         *
         * @return array<int,string>
         */
        private static function forbidden_system_roots()
        {
            $roots = [
                '/bin', '/boot', '/dev', '/etc', '/lib', '/lib32', '/lib64', '/libx32',
                '/proc', '/root', '/run', '/sbin', '/sys', '/usr', '/tmp',
                '/var/tmp', '/var/log', '/var/lib', '/var/run', '/var/spool', '/var/cache', '/var/mail',
                '/private/etc', '/private/tmp', '/private/var/tmp', '/private/var/log',
                '/System', '/Library', '/Applications',
                'C:/Windows', 'C:/Program Files', 'C:/Program Files (x86)',
            ];

            /**
             * Filters the system folders an absolute sync root may not live in.
             *
             * @param array<int,string> $roots
             */
            $roots = apply_filters('dbvc_sync_path_forbidden_roots', $roots);
            return is_array($roots) ? array_values(array_filter($roots, 'is_string')) : [];
        }

        /**
         * @param string $path Normalized path.
         * @return bool
         */
        private static function is_absolute($path)
        {
            return strpos($path, '/') === 0 || (bool) preg_match('#^[A-Za-z]:/#', $path);
        }

        /**
         * @return bool
         */
        private static function is_case_insensitive_fs()
        {
            return DIRECTORY_SEPARATOR === '\\' || PHP_OS_FAMILY === 'Darwin';
        }
    }
}
