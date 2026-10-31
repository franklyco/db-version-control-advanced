<?php
/**
 * Sync folder exposure guidance for web-reachable `dbvc_sync_path` folders.
 *
 * Security finding S1: the admin `dbvc_sync_path` option resolves under
 * ABSPATH, and its .htaccess deny is ignored by nginx, so a sync folder in a
 * theme, plugin or uploads folder can be downloaded on nginx hosts. The owner
 * keeps those locations available, so DBVC does not refuse or move them.
 * Instead it gives the operator what closes the gap without moving the folder:
 *
 * - the exact nginx `location` block that denies the folder's URL path;
 * - the Cloudways steps, where the nginx config is not editable by the site;
 * - a hard-to-guess folder name, so exported file URLs cannot be predicted.
 *
 * Everything here is computed from the filesystem and the site's configured
 * URLs. It never makes an HTTP request.
 *
 * @package DB Version Control
 */

if (! defined('WPINC')) {
    die;
}

if (! class_exists('DBVC_Sync_Exposure')) {
    final class DBVC_Sync_Exposure
    {
        /** Shortest random token that counts as a hard-to-guess folder name. */
        const MIN_TOKEN_LENGTH = 16;

        /**
         * The URL path a web-reachable folder is served at, with a trailing slash.
         *
         * Maps the folder through the most specific web root that contains it
         * (uploads, themes, plugins, must-use plugins, wp-content, ABSPATH), so
         * a relocated wp-content or uploads folder gets its real URL path.
         *
         * @param string $path Absolute folder path; it need not exist yet.
         * @return string Empty when the folder is not under a web root.
         */
        public static function url_path($path)
        {
            $resolved = untrailingslashit(DBVC_Sync_Location::resolve(wp_normalize_path((string) $path)));
            if ($resolved === '') {
                return '';
            }

            $best_root = '';
            $best_url  = '';
            foreach (self::web_root_urls() as $root => $url) {
                $root = untrailingslashit(DBVC_Sync_Location::resolve($root));
                if (strlen($root) > strlen($best_root) && DBVC_Sync_Location::is_within($resolved, $root)) {
                    $best_root = $root;
                    $best_url  = $url;
                }
            }
            if ($best_root === '') {
                return '';
            }

            $relative = ltrim((string) substr($resolved, strlen($best_root)), '/');
            $base     = (string) wp_parse_url($best_url, PHP_URL_PATH);
            $url_path = '/' . trim($base, '/');
            if ($relative !== '') {
                $url_path = rtrim($url_path, '/') . '/' . $relative;
            }

            return trailingslashit($url_path);
        }

        /**
         * The nginx block that denies every file in the folder.
         *
         * `^~` makes the prefix match win over regex locations, such as the
         * usual `\.php$` and static-file blocks, which would otherwise serve
         * the JSON before the deny is reached.
         *
         * @param string $path Absolute folder path.
         * @return string Empty when the folder is not under a web root.
         */
        public static function nginx_rule($path)
        {
            $url_path = self::url_path($path);
            if ($url_path === '') {
                return '';
            }

            return "# DBVC sync folder: nginx ignores its .htaccess\n"
                . 'location ^~ ' . self::nginx_quote($url_path) . " {\n"
                . "    deny all;\n"
                . "}";
        }

        /**
         * Operator steps for Cloudways, whose nginx config the site cannot edit.
         *
         * @param string $path Absolute folder path.
         * @return array<int,string>
         */
        public static function cloudways_steps($path)
        {
            $url_path = self::url_path($path);
            return [
                sprintf(
                    /* translators: %s: example DBVC_SYNC_PATH_ABSOLUTE define */
                    __('Preferred: move the folder out of the web root. Create private_html/dbvc-sync beside public_html, then add %s to wp-config.php. DBVC then ignores this field.', 'dbvc'),
                    "define('DBVC_SYNC_PATH_ABSOLUTE', '/home/master/applications/<app>/private_html/dbvc-sync');"
                ),
                __('To keep this folder instead: Cloudways does not let the site edit its nginx config, so open a support ticket for this application, paste the nginx block above, and ask them to add it to the application\'s nginx configuration.', 'dbvc'),
                sprintf(
                    /* translators: %s: URL path of the sync folder */
                    __('After they confirm, open %soptions.json in a private browser window. Expect 403 or 404, not JSON. Repeat after any server migration or stack change.', 'dbvc'),
                    $url_path !== '' ? $url_path : '/'
                ),
            ];
        }

        /**
         * Whether the folder's path below its web root has a random segment.
         *
         * A heuristic: some path segment holds a run of at least
         * MIN_TOKEN_LENGTH characters that mixes letters and digits. Default
         * names such as `sync` or `dbvc-sync`, and long dictionary words,
         * fail it, so their file URLs are predictable.
         *
         * @param string $path Absolute folder path.
         * @return bool
         */
        public static function has_unguessable_name($path)
        {
            $url_path = self::url_path($path);
            if ($url_path === '') {
                return true;
            }
            $pattern = '/[A-Za-z0-9]{' . (int) self::MIN_TOKEN_LENGTH . ',}/';
            foreach (array_filter(explode('/', $url_path), 'strlen') as $segment) {
                if (! preg_match_all($pattern, $segment, $matches)) {
                    continue;
                }
                foreach ($matches[0] as $run) {
                    if (self::is_random_token($run)) {
                        return true;
                    }
                }
            }
            return false;
        }

        /**
         * A suggested ABSPATH-relative path with a random folder name.
         *
         * Keeps the operator's chosen parent folder (theme, uploads, plugin)
         * and only randomizes the last segment. Nothing is saved or moved.
         *
         * @param string $relative The current `dbvc_sync_path` value, or ''.
         * @return string
         */
        public static function suggest_unguessable_path($relative)
        {
            $relative = trim(str_replace('\\', '/', (string) $relative), '/');
            $parent   = $relative !== '' && strpos($relative, '/') !== false ? dirname($relative) : 'wp-content/uploads';
            do {
                $token = strtolower(wp_generate_password(self::MIN_TOKEN_LENGTH + 4, false, false));
            } while (! self::is_random_token($token));

            return $parent . '/dbvc-sync-' . $token;
        }

        /**
         * The warning shown after saving a web-reachable sync path.
         *
         * @param string $path Absolute folder path.
         * @return string Empty when the folder is not web-reachable.
         */
        public static function save_warning($path)
        {
            if (! DBVC_Sync_Location::is_web_reachable($path)) {
                return '';
            }

            $message = sprintf(
                /* translators: %s: absolute sync folder path */
                __('Saved, but %s is inside the web root. Apache honours the folder\'s .htaccess; nginx does not, so options.json and entity exports there can be downloaded unless the server blocks the path. Add the nginx block shown under the sync path, or move the folder outside the web root with DBVC_SYNC_PATH_ABSOLUTE.', 'dbvc'),
                untrailingslashit(wp_normalize_path((string) $path))
            );
            if (! self::has_unguessable_name($path)) {
                $message .= ' ' . __('The folder name is predictable; a random folder name makes the file URLs hard to guess.', 'dbvc');
            }

            return $message;
        }

        /**
         * Settings and Site Health guidance: nginx block, Cloudways steps and,
         * for a predictable folder name, a random-name suggestion.
         *
         * @param string $path     Absolute folder path.
         * @param string $relative The current `dbvc_sync_path` value, or ''.
         * @return string HTML, escaped. Empty when the folder is not web-reachable.
         */
        public static function guidance_html($path, $relative = '')
        {
            if (! DBVC_Sync_Location::is_web_reachable($path)) {
                return '';
            }

            $html = '';
            $rule = self::nginx_rule($path);
            if ($rule !== '') {
                $html .= '<p>' . esc_html__('nginx: add this block to the server block for this site, then reload nginx.', 'dbvc') . '</p>'
                    . '<pre class="dbvc-sync-nginx-rule"><code>' . esc_html($rule) . '</code></pre>';
            }

            $html .= '<p><strong>' . esc_html__('Cloudways', 'dbvc') . '</strong></p><ol>';
            foreach (self::cloudways_steps($path) as $step) {
                $html .= '<li>' . esc_html($step) . '</li>';
            }
            $html .= '</ol>';

            if (! self::has_unguessable_name($path)) {
                $html .= '<p>' . esc_html(sprintf(
                    /* translators: %s: suggested relative sync path */
                    __('The folder name is predictable, so its file URLs can be guessed. A random name such as %s makes them hard to guess. Changing the path does not move or delete existing files: move them yourself, then delete the old folder.', 'dbvc'),
                    self::suggest_unguessable_path($relative)
                )) . '</p>';
            }

            return $html;
        }

        /**
         * Web roots mapped to the URLs that serve them.
         *
         * @return array<string,string>
         */
        private static function web_root_urls()
        {
            $map = [ABSPATH => site_url('/')];
            if (defined('WP_CONTENT_DIR')) {
                $map[WP_CONTENT_DIR] = content_url('/');
            }
            if (defined('WP_PLUGIN_DIR')) {
                $map[WP_PLUGIN_DIR] = plugins_url('/');
            }
            if (defined('WPMU_PLUGIN_DIR') && defined('WPMU_PLUGIN_URL')) {
                $map[WPMU_PLUGIN_DIR] = WPMU_PLUGIN_URL;
            }
            if (function_exists('get_theme_root') && function_exists('get_theme_root_uri')) {
                $map[get_theme_root()] = get_theme_root_uri();
            }
            $uploads = wp_get_upload_dir();
            if (! empty($uploads['basedir']) && ! empty($uploads['baseurl'])) {
                $map[$uploads['basedir']] = $uploads['baseurl'];
            }

            $normalized = [];
            foreach ($map as $root => $url) {
                if (is_string($root) && $root !== '' && is_string($url) && $url !== '') {
                    $normalized[untrailingslashit(wp_normalize_path($root))] = $url;
                }
            }
            return $normalized;
        }

        /**
         * Whether a run mixes letters and digits, as a generated token does.
         *
         * @param string $run
         * @return bool
         */
        private static function is_random_token($run)
        {
            return (bool) preg_match('/[0-9]/', $run) && (bool) preg_match('/[A-Za-z]/', $run);
        }

        /**
         * Quote a location prefix when it holds characters nginx would split on.
         *
         * @param string $url_path
         * @return string
         */
        private static function nginx_quote($url_path)
        {
            if (preg_match('#^[A-Za-z0-9._~/%-]+$#', $url_path)) {
                return $url_path;
            }
            return '"' . addcslashes($url_path, '"\\') . '"';
        }
    }
}
