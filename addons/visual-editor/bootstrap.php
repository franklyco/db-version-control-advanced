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
        public const OPTION_MEDIA_MANAGER_ENABLED = 'dbvc_visual_editor_media_manager_enabled';
        public const OPTION_SHARED_GLOBAL_FIELD_NAMES = 'dbvc_visual_editor_shared_global_field_names';
        public const OPTION_EXCLUDED_POST_TYPES = 'dbvc_visual_editor_excluded_post_types';
        public const OPTION_EXCLUDED_TAXONOMIES = 'dbvc_visual_editor_excluded_taxonomies';
        public const OPTION_CURATION_TOOL_ENABLED = 'dbvc_visual_editor_curation_tool_enabled';
        public const OPTION_CONTROL_CENTER_ENABLED = 'dbvc_visual_editor_control_center_enabled';
        public const OPTION_WORKSPACE_ENABLED = 'dbvc_visual_editor_workspace_enabled';
        public const OPTION_SETTINGS_VERSION = 'dbvc_visual_editor_settings_version';
        // R5.later-perf-a (2026-09-17): Bricks ACF dynamic-data tag-index shim. Site-wide
        // (every frontend / REST request), independent of the master switch, default off.
        public const OPTION_BRICKS_ACF_TAG_INDEX_ENABLED = \Dbvc\VisualEditor\Performance\Bricks\AcfTagIndexShim::OPTION_ENABLED;
        // R5.later-perf-b (2026-09-17): persistent cache for Bricks' ACF field schema. Site-wide, default off.
        public const OPTION_BRICKS_ACF_FIELDS_CACHE_ENABLED = \Dbvc\VisualEditor\Performance\Bricks\AcfFieldsCache::OPTION_ENABLED;
        public const SETTINGS_VERSION = 9;
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
         * @var \Dbvc\VisualEditor\Admin\CurationPage|null
         */
        private static $curation_page = null;

        /**
         * @return void
         */
        public static function bootstrap()
        {
            self::ensure_defaults();
            self::register_admin_settings_page();
            self::register_admin_curation_page();
            self::refresh_runtime_registration();
            // R5.later-perf-a: inert unless its own switch is on (see the shim's gate).
            \Dbvc\VisualEditor\Performance\Bricks\AcfTagIndexShim::register();
            // R5.later-perf-b: same posture — inert unless its own switch is on.
            \Dbvc\VisualEditor\Performance\Bricks\AcfFieldsCache::register();
        }

        /**
         * @return void
         */
        public static function ensure_defaults()
        {
            add_option(self::OPTION_ENABLED, '0');
            add_option(self::OPTION_MEDIA_MANAGER_ENABLED, '0');
            add_option(self::OPTION_SHARED_GLOBAL_FIELD_NAMES, self::DEFAULT_SHARED_GLOBAL_FIELD_NAMES);
            add_option(self::OPTION_EXCLUDED_POST_TYPES, self::DEFAULT_EXCLUDED_POST_TYPES);
            add_option(self::OPTION_EXCLUDED_TAXONOMIES, self::DEFAULT_EXCLUDED_TAXONOMIES);
            add_option(self::OPTION_CURATION_TOOL_ENABLED, '0');
            add_option(self::OPTION_CONTROL_CENTER_ENABLED, '0');
            add_option(self::OPTION_WORKSPACE_ENABLED, '0');
            add_option(self::OPTION_BRICKS_ACF_TAG_INDEX_ENABLED, '0');
            add_option(\Dbvc\VisualEditor\Performance\Bricks\AcfTagIndexShim::OPTION_VERIFIED, '');
            add_option(self::OPTION_BRICKS_ACF_FIELDS_CACHE_ENABLED, '0');
            add_option(\Dbvc\VisualEditor\Performance\Bricks\AcfFieldsCache::OPTION_SALT, '', '', 'yes');
            add_option(\Dbvc\VisualEditor\Performance\Bricks\AcfFieldsCache::OPTION_VERIFIED, '');
            add_option(\Dbvc\VisualEditor\Performance\Bricks\AcfFieldsCache::OPTION_META, [], '', 'no');
            add_option(self::OPTION_SETTINGS_VERSION, (string) self::SETTINGS_VERSION);

            if ((int) get_option(self::OPTION_SETTINGS_VERSION, 0) < self::SETTINGS_VERSION) {
                update_option(self::OPTION_SETTINGS_VERSION, (string) self::SETTINGS_VERSION);
            }
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
        public static function is_media_manager_enabled()
        {
            return self::is_enabled()
                && get_option(self::OPTION_MEDIA_MANAGER_ENABLED, '0') === '1';
        }

        /**
         * R3-BX kill switch. When true, the temporary Brand Control Center
         * curation admin page renders under Settings → Visual Editor. The
         * curation surface is admin-only, does not touch runtime Visual Editor
         * behavior, and never mutates content — it only reads ACF field
         * metadata and records approval decisions into a dedicated option
         * for later export as the Vertical control provider seed.
         *
         * @return bool
         */
        public static function is_curation_tool_enabled()
        {
            return get_option(self::OPTION_CURATION_TOOL_ENABLED, '0') === '1';
        }

        /**
         * Absolute filesystem path to the R3-BX curation export
         * (`vertical-approved-controls.json`) so external providers such as
         * `VerticalControlProvider` do not have to hardcode the addon /
         * plugin folder name. The path is deterministic and computed from
         * this file's own directory — it survives a plugin-folder rename
         * because `bootstrap.php` sits at the same relative location.
         *
         * Filterable via `dbvc_visual_editor_curation_export_path` so a
         * site can point at an alternate export location during rollout
         * (returning a non-string or a path that fails `is_readable` is a
         * bug in the filter; the caller must handle a missing file).
         *
         * @return string
         */
        public static function get_curation_export_path()
        {
            $default = __DIR__ . '/curation/vertical-approved-controls.json';
            $path = apply_filters('dbvc_visual_editor_curation_export_path', $default);

            return is_string($path) && $path !== '' ? $path : $default;
        }

        /**
         * R3-B kill switch. When true, the Registry-Backed Brand Control
         * Center registers its providers on the runtime {@see
         * \Dbvc\VisualEditor\Registry\ControlRegistry}. The registry is a
         * discovery-only read surface — no new write authority — so this
         * gate only controls whether the Shared Globals compatibility
         * provider (and, in R3-C, the drawer UI + open route) is exposed.
         * Requires the master Visual Editor switch as well, mirroring
         * `is_media_manager_enabled()`.
         *
         * @return bool
         */
        public static function is_control_center_enabled()
        {
            return self::is_enabled()
                && get_option(self::OPTION_CONTROL_CENTER_ENABLED, '0') === '1';
        }

        /**
         * R6-D-1 kill switch for the Frontend Site Manager Workspace drawer.
         * Gates the toolbar entry, the `workspace-app.js` / `workspace.css`
         * enqueue, and the `workspace` bootstrap block. Off by default so the
         * rollout fallback is today's toolbar/popover navigation (R6 spec
         * §Compatibility and rollback). Requires the master switch, mirroring
         * `is_control_center_enabled()`. Read-only surface — no write authority.
         *
         * @return bool
         */
        public static function is_workspace_enabled()
        {
            return self::is_enabled()
                && get_option(self::OPTION_WORKSPACE_ENABLED, '0') === '1';
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
                'media_manager' => [
                    'label' => __('Media Manager', 'dbvc'),
                    'fields' => [
                        self::OPTION_MEDIA_MANAGER_ENABLED,
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
                'control_center_curation' => [
                    'label' => __('Brand Control Center — Curation Tool (temporary)', 'dbvc'),
                    'fields' => [
                        self::OPTION_CURATION_TOOL_ENABLED,
                    ],
                ],
                'control_center' => [
                    'label' => __('Brand Control Center', 'dbvc'),
                    'fields' => [
                        self::OPTION_CONTROL_CENTER_ENABLED,
                    ],
                ],
                'workspace' => [
                    'label' => __('Site Manager Workspace', 'dbvc'),
                    'fields' => [
                        self::OPTION_WORKSPACE_ENABLED,
                    ],
                ],
                'performance_bricks' => [
                    'label' => __('Performance — Bricks compatibility (site-wide)', 'dbvc'),
                    'fields' => [
                        self::OPTION_BRICKS_ACF_TAG_INDEX_ENABLED,
                        self::OPTION_BRICKS_ACF_FIELDS_CACHE_ENABLED,
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
                self::OPTION_MEDIA_MANAGER_ENABLED => [
                    'label' => __('Enable Frontend Media Manager', 'dbvc'),
                    'input' => 'checkbox',
                    'help' => __('Keep the read-only Frontend Media Manager boundary available for its staged rollout. This setting is off by default and has no effect unless the Visual Editor is also enabled.', 'dbvc'),
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
                self::OPTION_CURATION_TOOL_ENABLED => [
                    'label' => __('Enable Brand Control Center curation tool', 'dbvc'),
                    'input' => 'checkbox',
                    'help' => __('Temporary. Adds a Brand Control Center → Curation admin page for manually approving which options-page ACF fields become registered Visual Editor controls. Turn off when the curation artifact is committed. The page is admin-only, never mutates content, and reads options-page field metadata only.', 'dbvc'),
                ],
                self::OPTION_CONTROL_CENTER_ENABLED => [
                    'label' => __('Enable Brand Control Center', 'dbvc'),
                    'input' => 'checkbox',
                    'help' => __('Registers configured Shared Globals fields with the Visual Editor Brand Control Center so editors can discover them from one place. Off by default and has no effect unless the Visual Editor is also enabled. The Control Center is a discovery-only surface — turning it on does not grant any new edit permission; existing capability checks still apply at save time.', 'dbvc'),
                ],
                self::OPTION_WORKSPACE_ENABLED => [
                    'label' => __('Enable Site Manager Workspace', 'dbvc'),
                    'input' => 'checkbox',
                    'help' => __('Adds the persistent Site Manager drawer to the frontend Visual Editor toolbar for navigating pages, posts, approved post types, and terms without leaving Visual Editor mode, with shortcuts to Review Fields, the Brand Control Center, and the Media Manager. Off by default; turning it off restores the previous toolbar navigation.', 'dbvc'),
                ],
                self::OPTION_BRICKS_ACF_TAG_INDEX_ENABLED => [
                    'label' => __('Index Bricks ACF dynamic-data tags', 'dbvc'),
                    'input' => 'checkbox',
                    'help' => self::bricks_acf_tag_index_help(),
                ],
                self::OPTION_BRICKS_ACF_FIELDS_CACHE_ENABLED => [
                    'label' => __('Cache the Bricks ACF field schema', 'dbvc'),
                    'input' => 'checkbox',
                    'help' => self::bricks_acf_fields_cache_help(),
                    'actions' => [
                        ['action' => 'bricks-acf-fields-cache-rebuild', 'label' => __('Rebuild now', 'dbvc')],
                        ['action' => 'bricks-acf-fields-cache-verify', 'label' => __('Verify now', 'dbvc')],
                    ],
                ],
            ];
        }

        /**
         * Settings-page help text for the R5.later-perf-a switch, including the
         * shim's live status so the maintainer can see why it is (not) active.
         *
         * @return string
         */
        public static function bricks_acf_tag_index_help()
        {
            $status = \Dbvc\VisualEditor\Performance\Bricks\AcfTagIndexShim::status();
            $verified = (string) $status['verified'];
            $state = $status['gate'] === 'ok' ? 'ready' : (string) $status['gate'];

            return sprintf(
                /* translators: 1: Bricks version label or "unknown", 2: gate state, 3: verification summary */
                __('Speeds up every frontend and REST request on Bricks sites with large group-nested ACF schemas by giving Bricks\' ACF dynamic-data provider an indexed nested-group lookup (identical output, verified on save). Applies site-wide, regardless of the Visual Editor activation switch; default off. Only arms when the installed Bricks code matches a pinned fingerprint and that fingerprint has been verified on this site. Detected: %1$s · gate: %2$s · verification: %3$s.', 'dbvc'),
                (string) $status['bricks'],
                $state,
                $verified === '' ? 'never run' : (strpos($verified, 'failed:') === 0 ? 'FAILED — registries differed' : 'passed for ' . substr($verified, 0, 12))
            );
        }

        /**
         * Settings-page help text for the R5.later-perf-b switch with the live cache status.
         *
         * @return string
         */
        public static function bricks_acf_fields_cache_help()
        {
            $status = \Dbvc\VisualEditor\Performance\Bricks\AcfFieldsCache::status();
            $meta = (array) $status['meta'];
            $gate = $status['gate'] === 'ok' ? 'ready' : (string) $status['gate'];
            $built = ! empty($meta['built_at'])
                ? sprintf('%s ago, %s, %d fields', human_time_diff((int) $meta['built_at']), size_format((int) ($meta['bytes'] ?? 0)), (int) ($meta['fields'] ?? 0))
                : 'never';
            $verify = ! empty($meta['last_verify_at'])
                ? sprintf('%s ago — %s', human_time_diff((int) $meta['last_verify_at']), ! empty($meta['last_verify_ok']) ? 'identical' : sprintf('%d field(s) differed (replaced)', (int) ($meta['last_verify_differing'] ?? 0)))
                : 'pending (runs via WP-Cron shortly after enabling)';
            $divergence = ! empty($meta['last_divergence_at'])
                ? sprintf(' Last divergence: %s ago (%d field(s)) — add a trigger for whatever changed.', human_time_diff((int) $meta['last_divergence_at']), (int) ($meta['last_divergence_differing'] ?? 0))
                : '';

            return sprintf(
                /* translators: 1: Bricks version label or "unknown", 2: gate state, 3: build summary, 4: verification summary, 5: TTL in hours, 6: divergence note */
                __('Removes the remaining per-request cost of Bricks materialising the ACF schema (about 2 s on large group-nested schemas) by keeping the field definitions in a persistent, fingerprinted cache and handing them to Bricks before it registers dynamic-data tags. Applies to every frontend and REST request; admin screens always use live ACF. Invalidated by ACF field-group edits, theme/plugin changes, file changes in field-registering PHP, saves of configured post types, a %5$d-hour TTL and a daily verification that rebuilds and compares. Detected: %1$s · gate: %2$s · built: %3$s · verification: %4$s.%6$s', 'dbvc'),
                (string) $status['bricks'],
                $gate,
                $built,
                $verify,
                (int) round($status['ttl'] / HOUR_IN_SECONDS),
                $divergence
            );
        }

        /**
         * Nonce-checked settings-page actions (GET links rendered from field meta).
         *
         * @param string $action
         * @return array{success: array<int, string>, error: array<int, string>}
         */
        public static function run_settings_action($action)
        {
            $feedback = ['success' => [], 'error' => []];

            switch ((string) $action) {
                case 'bricks-acf-fields-cache-rebuild':
                    \Dbvc\VisualEditor\Performance\Bricks\AcfFieldsCache::flush('settings_rebuild');
                    $feedback['success'][] = __('Bricks ACF field cache invalidated; a warm-up has been scheduled and will run on the next WP-Cron tick.', 'dbvc');
                    break;
                case 'bricks-acf-fields-cache-verify':
                    if (! wp_next_scheduled(\Dbvc\VisualEditor\Performance\Bricks\AcfFieldsCache::CRON_VERIFY, [])) {
                        wp_schedule_single_event(time() - 1, \Dbvc\VisualEditor\Performance\Bricks\AcfFieldsCache::CRON_VERIFY);
                    }
                    add_action('shutdown', 'spawn_cron', 100);
                    $feedback['success'][] = __('Bricks ACF field cache verification scheduled; it runs in a frontend-context WP-Cron request and reports here.', 'dbvc');
                    break;
                default:
                    $feedback['error'][] = __('Unknown settings action.', 'dbvc');
            }

            return $feedback;
        }

        /**
         * @return array<string, string>
         */
        public static function get_all_settings()
        {
            self::ensure_defaults();

            return [
                self::OPTION_ENABLED => (string) get_option(self::OPTION_ENABLED, '0'),
                self::OPTION_MEDIA_MANAGER_ENABLED => (string) get_option(self::OPTION_MEDIA_MANAGER_ENABLED, '0'),
                self::OPTION_SHARED_GLOBAL_FIELD_NAMES => (string) get_option(self::OPTION_SHARED_GLOBAL_FIELD_NAMES, self::DEFAULT_SHARED_GLOBAL_FIELD_NAMES),
                self::OPTION_EXCLUDED_POST_TYPES => (string) get_option(self::OPTION_EXCLUDED_POST_TYPES, self::DEFAULT_EXCLUDED_POST_TYPES),
                self::OPTION_EXCLUDED_TAXONOMIES => (string) get_option(self::OPTION_EXCLUDED_TAXONOMIES, self::DEFAULT_EXCLUDED_TAXONOMIES),
                self::OPTION_CURATION_TOOL_ENABLED => (string) get_option(self::OPTION_CURATION_TOOL_ENABLED, '0'),
                self::OPTION_CONTROL_CENTER_ENABLED => (string) get_option(self::OPTION_CONTROL_CENTER_ENABLED, '0'),
                self::OPTION_WORKSPACE_ENABLED => (string) get_option(self::OPTION_WORKSPACE_ENABLED, '0'),
                self::OPTION_BRICKS_ACF_TAG_INDEX_ENABLED => (string) get_option(self::OPTION_BRICKS_ACF_TAG_INDEX_ENABLED, '0'),
                self::OPTION_BRICKS_ACF_FIELDS_CACHE_ENABLED => (string) get_option(self::OPTION_BRICKS_ACF_FIELDS_CACHE_ENABLED, '0'),
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
                self::OPTION_MEDIA_MANAGER_ENABLED => isset($request_data[self::OPTION_MEDIA_MANAGER_ENABLED]) ? '1' : '0',
                self::OPTION_CURATION_TOOL_ENABLED => isset($request_data[self::OPTION_CURATION_TOOL_ENABLED]) ? '1' : '0',
                self::OPTION_CONTROL_CENTER_ENABLED => isset($request_data[self::OPTION_CONTROL_CENTER_ENABLED]) ? '1' : '0',
                self::OPTION_WORKSPACE_ENABLED => isset($request_data[self::OPTION_WORKSPACE_ENABLED]) ? '1' : '0',
                self::OPTION_BRICKS_ACF_TAG_INDEX_ENABLED => isset($request_data[self::OPTION_BRICKS_ACF_TAG_INDEX_ENABLED]) ? '1' : '0',
                self::OPTION_BRICKS_ACF_FIELDS_CACHE_ENABLED => isset($request_data[self::OPTION_BRICKS_ACF_FIELDS_CACHE_ENABLED]) ? '1' : '0',
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

            $errors = [];

            // R5.later-perf-a: switching the shim on (re)verifies the installed
            // Bricks code against the indexed provider before it can ever arm.
            if ($values[self::OPTION_BRICKS_ACF_TAG_INDEX_ENABLED] === '1') {
                $verification = \Dbvc\VisualEditor\Performance\Bricks\AcfTagIndexShim::verify(true);

                if (! $verification['ok']) {
                    $errors[] = sprintf(
                        /* translators: %s: verification reason code */
                        __('Bricks ACF tag index: not activated — %s. The switch stays on but the shim remains inert until a verification passes.', 'dbvc'),
                        (string) $verification['reason']
                    );
                }
            }

            // R5.later-perf-b: enabling schedules the daily verification and an
            // immediate warm-up (which also produces the first verification);
            // disabling clears both schedules. The payload is left to expire.
            if ($values[self::OPTION_BRICKS_ACF_FIELDS_CACHE_ENABLED] === '1') {
                \Dbvc\VisualEditor\Performance\Bricks\AcfFieldsCache::ensureVerifySchedule();
                if ($current[self::OPTION_BRICKS_ACF_FIELDS_CACHE_ENABLED] !== '1') {
                    \Dbvc\VisualEditor\Performance\Bricks\AcfFieldsCache::flush('enabled');
                    if (! wp_next_scheduled(\Dbvc\VisualEditor\Performance\Bricks\AcfFieldsCache::CRON_VERIFY, [])) {
                        wp_schedule_single_event(time() + 5, \Dbvc\VisualEditor\Performance\Bricks\AcfFieldsCache::CRON_VERIFY);
                    }
                }
            } else {
                \Dbvc\VisualEditor\Performance\Bricks\AcfFieldsCache::clearSchedules();
            }

            return [
                'values' => $values,
                'errors' => $errors,
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
         * R3-BX curation page bootstrap. Always registered so the AJAX
         * handlers exist while the kill switch is on; the admin menu item
         * itself is gated by is_curation_tool_enabled() inside the page's
         * registerMenu() so flipping the option off hides the entry point
         * on the very next request without requiring a plugin reload.
         *
         * @return void
         */
        private static function register_admin_curation_page()
        {
            if (! is_admin() || self::$curation_page instanceof \Dbvc\VisualEditor\Admin\CurationPage) {
                return;
            }

            self::$curation_page = new \Dbvc\VisualEditor\Admin\CurationPage();
            self::$curation_page->register();
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
