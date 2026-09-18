<?php

namespace Dbvc\VisualEditor\Performance\Bricks;

use Throwable;

/**
 * R5.later-perf-b — persistent cache for Bricks' ACF field schema (E-161, D-083).
 *
 * Bricks materialises the whole ACF schema (`Provider_Acf::get_fields()`) on
 * every frontend / REST request and keeps the result only in `wp_cache`, which
 * does not persist without an object cache (≈ 2 s per request on the reference
 * site). This class pre-populates the exact `wp_cache` entry Bricks reads —
 * `md5( 'acf_fields' . wp_cache_get_last_changed( 'bricks_acf-field-group' ) )`
 * in group `bricks` — from a DBVC transient before Bricks registers providers,
 * and captures what Bricks built after a miss. No Bricks method is overridden;
 * every caller of `get_fields()` (the ACF provider and the WP provider) is
 * served.
 *
 * Freshness (the payload is the output of ACF's load pipeline, third-party
 * `acf/load_field` filters included) is enforced by three layers:
 *  - a per-request fingerprint (field-group list, software versions, theme
 *    identity + file mtimes, acf-json mtime, the mtimes of every file that
 *    registers local field groups, an event salt and a site-owner salt filter);
 *    the locale is part of the transient name;
 *  - an event salt bumped by AcfFieldsCacheTriggers (ACF group CRUD, theme /
 *    plugin lifecycle, filterable post types, an explicit action, the settings
 *    page) — every bump schedules an immediate warm-up cron event;
 *  - a TTL (12 h) and a daily verification cron that rebuilds fresh, compares
 *    strictly, replaces on divergence and records it for the settings page.
 *
 * Fail-closed like perf-a: inert unless the switch is on, Bricks is present,
 * the four pinned Bricks methods hash to a known fingerprint and that
 * fingerprint has been verified on this site by the cron/CLI verifier
 * (frontend context — admin requests never seed, build or verify, so admin
 * screens always see live ACF). A hit only calls wp_cache_set on Bricks' key.
 */
final class AcfFieldsCache
{
    public const OPTION_ENABLED = 'dbvc_visual_editor_bricks_acf_fields_cache_enabled';
    public const OPTION_SALT = 'dbvc_visual_editor_bricks_acf_fields_cache_salt';
    public const OPTION_VERIFIED = 'dbvc_visual_editor_bricks_acf_fields_cache_verified';
    public const OPTION_META = 'dbvc_visual_editor_bricks_acf_fields_cache_meta';
    public const TRANSIENT_PREFIX = 'dbvc_ve_bricks_acf_fields_';
    public const CRON_WARM = 'dbvc_ve_bricks_acf_fields_cache_warm';
    public const CRON_VERIFY = 'dbvc_ve_bricks_acf_fields_cache_verify';
    public const WP_CACHE_GROUP = 'bricks';
    public const WP_CACHE_LAST_CHANGED_GROUP = 'bricks_acf-field-group';
    public const PAYLOAD_VERSION = 1;
    public const DEFAULT_TTL = 12 * HOUR_IN_SECONDS;
    public const DEFAULT_SIZE_CAP = 8 * 1024 * 1024;
    public const REGISTRAR_SCAN_FILE_CAP = 20000;
    public const REGISTRAR_SCAN_TIME_BUDGET = 2.0;

    /**
     * @var string
     */
    private static $state = 'disabled';

    /**
     * @var string
     */
    private static $missReason = '';

    /**
     * @var bool
     */
    private static $registered = false;

    /**
     * @var string|null request-scoped fingerprint memo
     */
    private static $fingerprintMemo = null;

    /**
     * @var bool salt already bumped during this request
     */
    private static $bumped = false;

    // ---------------------------------------------------------------------
    // Switch, registration, gate
    // ---------------------------------------------------------------------

    /**
     * @return bool
     */
    public static function isEnabled()
    {
        $enabled = get_option(self::OPTION_ENABLED, '0') === '1';

        /**
         * Filter whether the Bricks ACF fields cache may arm on this request.
         *
         * @param bool $enabled Value of the settings switch.
         */
        return (bool) apply_filters('dbvc_visual_editor_bricks_acf_fields_cache_enabled', $enabled);
    }

    /**
     * Called from the add-on bootstrap on every request.
     *
     * @return void
     */
    public static function register()
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;
        self::$state = 'disabled';

        add_action('after_setup_theme', [self::class, 'arm'], 0);
        add_action(self::CRON_WARM, [self::class, 'onWarmCron']);
        add_action(self::CRON_VERIFY, [self::class, 'onVerifyCron']);
    }

    /**
     * @return void
     */
    public static function arm()
    {
        if (! self::isEnabled()) {
            self::$state = 'disabled';
            return;
        }

        AcfFieldsCacheTriggers::register();

        $gate = self::gate();

        if ($gate !== 'ok') {
            self::$state = $gate;
            // Still capture on a miss so the first verification has a payload
            // to compare against, but never seed before verification passed.
            if ($gate === 'not_verified') {
                self::hookCapture();
            }
            return;
        }

        self::$state = 'armed';
        $hook = apply_filters('bricks/dynamic_data/register_hook', 'init');
        add_action($hook, [self::class, 'seed'], 9999);
        self::hookCapture();
    }

    /**
     * @return void
     */
    private static function hookCapture()
    {
        $hook = apply_filters('bricks/dynamic_data/register_hook', 'init');
        add_action($hook, [self::class, 'capture'], 10003);
    }

    /**
     * 'ok' or the reason the cache must stay inert.
     *
     * @return string
     */
    public static function gate()
    {
        if (! ProviderFingerprint::bricksLoaded()) {
            return 'bricks_not_loaded';
        }

        if (! function_exists('wp_cache_get_last_changed') || ! function_exists('bricks_is_ajax_call') || ! function_exists('bricks_is_rest_call')) {
            return 'bricks_helpers_missing';
        }

        $pin = ProviderFingerprint::fieldsCacheCurrent();

        if (! ProviderFingerprint::isKnownFieldsCache($pin)) {
            return 'fingerprint_unknown';
        }

        if ((string) get_option(self::OPTION_VERIFIED, '') !== $pin) {
            return 'not_verified';
        }

        return 'ok';
    }

    /**
     * Frontend and REST requests only. Bricks also registers providers on
     * admin-ajax requests, but those are `is_admin()` — theme/plugin
     * `acf/load_field` filters that are registered only in admin (e.g. the
     * Vertical CTA-collection choice populator) run there, so an array
     * captured in admin-ajax is not what frontend requests would build.
     * Verified live 2026-09-17: an admin-ajax capture carried DB-populated
     * CTA choices while every frontend build carried the JSON's static ones.
     *
     * @return bool
     */
    public static function contextAllowsSeed()
    {
        if (! function_exists('bricks_is_ajax_call') || ! function_exists('bricks_is_rest_call')) {
            return false;
        }

        if (is_admin()) {
            return false;
        }

        return true;
    }

    // ---------------------------------------------------------------------
    // Seed / capture (the per-request path)
    // ---------------------------------------------------------------------

    /**
     * Bricks' own wp_cache key for the fields array.
     *
     * @return string
     */
    public static function bricksCacheKey()
    {
        return md5('acf_fields' . wp_cache_get_last_changed(self::WP_CACHE_LAST_CHANGED_GROUP));
    }

    /**
     * init@9999: put the persisted fields into Bricks' wp_cache entry.
     *
     * @return void
     */
    public static function seed()
    {
        try {
            self::$state = self::performSeed();
        } catch (Throwable $e) {
            self::$state = 'error:' . get_class($e);
            self::$missReason = 'exception';
        }
    }

    /**
     * @return string
     */
    private static function performSeed()
    {
        if (! self::contextAllowsSeed()) {
            return 'skipped_context';
        }

        $key = self::bricksCacheKey();

        if (wp_cache_get($key, self::WP_CACHE_GROUP) !== false) {
            // A persistent object cache already holds Bricks' own copy.
            return 'already_cached';
        }

        $payload = self::readPayload();

        if ($payload === null) {
            return 'miss';
        }

        $fingerprint = self::fingerprint($payload['registrars']);

        if ($payload['fingerprint'] !== $fingerprint) {
            self::$missReason = 'fingerprint_mismatch';
            return 'miss';
        }

        wp_cache_set($key, $payload['fields'], self::WP_CACHE_GROUP, DAY_IN_SECONDS);

        return 'hit';
    }

    /**
     * init@10003 (after Bricks' tag_registered@10002): on a miss, persist what
     * Bricks just built.
     *
     * @return void
     */
    public static function capture()
    {
        if (self::$state !== 'miss' && self::$state !== 'not_verified') {
            return;
        }

        if (! self::contextAllowsSeed()) {
            return;
        }

        try {
            $fields = wp_cache_get(self::bricksCacheKey(), self::WP_CACHE_GROUP);

            if (! is_array($fields) || $fields === []) {
                self::$state = self::$state === 'miss' ? 'miss_nothing_built' : self::$state;
                return;
            }

            $stored = self::storeFields($fields, 'capture');
            self::$state = $stored === 'stored' ? ($stored . (self::$state === 'not_verified' ? '_unverified' : '')) : $stored;
        } catch (Throwable $e) {
            self::$state = 'error:' . get_class($e);
        }
    }

    // ---------------------------------------------------------------------
    // Payload
    // ---------------------------------------------------------------------

    /**
     * @return string
     */
    public static function locale()
    {
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();

        if (function_exists('pll_current_language')) {
            $locale .= ':' . (string) pll_current_language('slug');
        } elseif (defined('ICL_LANGUAGE_CODE')) {
            $locale .= ':' . (string) ICL_LANGUAGE_CODE;
        }

        return (string) $locale;
    }

    /**
     * @return string
     */
    public static function transientName()
    {
        return self::TRANSIENT_PREFIX . md5(self::locale());
    }

    /**
     * @return array{fields: array<int, mixed>, fingerprint: string, registrars: array<string, int>, built: int, bytes: int}|null
     */
    public static function readPayload()
    {
        $raw = get_transient(self::transientName());

        if (! is_array($raw) || ($raw['v'] ?? 0) !== self::PAYLOAD_VERSION || ! isset($raw['fields'], $raw['fingerprint'])) {
            self::$missReason = $raw === false ? 'no_transient' : 'payload_shape';
            return null;
        }

        $decoded = self::decode((string) $raw['fields']);

        if ($decoded === null) {
            self::$missReason = 'decode_failed';
            return null;
        }

        return [
            'fields' => $decoded,
            'fingerprint' => (string) $raw['fingerprint'],
            'registrars' => is_array($raw['registrars'] ?? null) ? $raw['registrars'] : [],
            'built' => (int) ($raw['built'] ?? 0),
            'bytes' => strlen((string) $raw['fields']),
        ];
    }

    /**
     * Persist a fields array as the current payload.
     *
     * @param array<int, mixed> $fields
     * @param string            $source capture|cron|verify
     * @return string stored|payload_too_large|encode_failed
     */
    public static function storeFields(array $fields, $source)
    {
        $encoded = self::encode($fields);

        if ($encoded === null) {
            return 'encode_failed';
        }

        $cap = (int) apply_filters('dbvc_visual_editor_bricks_acf_fields_cache_size_cap', self::DEFAULT_SIZE_CAP);

        if (strlen($encoded) > $cap) {
            self::updateMeta(['last_error' => 'payload_too_large', 'last_error_at' => time()]);
            return 'payload_too_large';
        }

        $registrars = self::discoverRegistrarFiles();
        $fingerprint = self::fingerprint($registrars);

        set_transient(
            self::transientName(),
            [
                'v' => self::PAYLOAD_VERSION,
                'fields' => $encoded,
                'fingerprint' => $fingerprint,
                'registrars' => $registrars,
                'built' => time(),
            ],
            self::ttl()
        );

        self::updateMeta([
            'built_at' => time(),
            'built_by' => $source,
            'bytes' => strlen($encoded),
            'fields' => count($fields),
            'fingerprint' => $fingerprint,
            'registrars' => count($registrars),
            'locale' => self::locale(),
        ]);

        return 'stored';
    }

    /**
     * @param array<int, mixed> $fields
     * @return string|null
     */
    public static function encode(array $fields)
    {
        $serialized = serialize($fields);
        $compressed = function_exists('gzcompress') ? gzcompress($serialized, 6) : false;

        if ($compressed === false) {
            return null;
        }

        return base64_encode($compressed);
    }

    /**
     * @param string $encoded
     * @return array<int, mixed>|null
     */
    public static function decode($encoded)
    {
        $binary = base64_decode($encoded, true);

        if ($binary === false || ! function_exists('gzuncompress')) {
            return null;
        }

        $serialized = @gzuncompress($binary);

        if ($serialized === false) {
            return null;
        }

        $fields = @unserialize($serialized, ['allowed_classes' => false]);

        return is_array($fields) ? $fields : null;
    }

    /**
     * @return int
     */
    public static function ttl()
    {
        return max(5 * MINUTE_IN_SECONDS, (int) apply_filters('dbvc_visual_editor_bricks_acf_fields_cache_ttl', self::DEFAULT_TTL));
    }

    // ---------------------------------------------------------------------
    // Fingerprint
    // ---------------------------------------------------------------------

    /**
     * Cheap per-request fingerprint (see class docblock). Memoised per request.
     *
     * @param array<string, int> $registrars relative path => mtime recorded at build time (only the paths are used)
     * @return string
     */
    public static function fingerprint(array $registrars = [])
    {
        $key = md5(implode('|', array_keys($registrars)));

        if (is_array(self::$fingerprintMemo) && isset(self::$fingerprintMemo[$key])) {
            return self::$fingerprintMemo[$key];
        }

        $signals = [
            'groups' => self::groupsSignal(),
            'versions' => [
                'acf' => function_exists('acf_get_setting') ? (string) acf_get_setting('version') : '',
                'bricks' => (string) wp_get_theme('bricks')->get('Version'),
                'dbvc' => defined('DBVC_VERSION') ? (string) DBVC_VERSION : '',
                'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            ],
            'theme' => self::themeSignal(),
            'acf_json' => self::acfJsonSignal(),
            'registrars' => self::registrarSignal($registrars),
            'salt' => (string) get_option(self::OPTION_SALT, ''),
            /**
             * Extra invalidation input for site-specific `acf/load_field`
             * dependencies (forms, option pages…). Return any scalar/array.
             *
             * @param mixed $extra
             */
            'extra' => apply_filters('dbvc_visual_editor_bricks_acf_fields_cache_salt', ''),
        ];

        $fingerprint = sha1((string) wp_json_encode($signals));

        if (! is_array(self::$fingerprintMemo)) {
            self::$fingerprintMemo = [];
        }
        self::$fingerprintMemo[$key] = $fingerprint;

        return $fingerprint;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function groupsSignal()
    {
        if (! function_exists('acf_get_field_groups')) {
            return [];
        }

        $signal = [];

        foreach ((array) acf_get_field_groups() as $group) {
            if (! is_array($group)) {
                continue;
            }

            $signal[] = [
                'key' => $group['key'] ?? '',
                'modified' => $group['modified'] ?? '',
                'local' => $group['local'] ?? '',
                'active' => $group['active'] ?? '',
                'menu_order' => $group['menu_order'] ?? '',
                'title' => $group['title'] ?? '',
                'location' => $group['location'] ?? [],
            ];
        }

        return $signal;
    }

    /**
     * @return array<string, mixed>
     */
    private static function themeSignal()
    {
        $signal = [
            'stylesheet' => get_stylesheet(),
            'template' => get_template(),
            'child_version' => (string) wp_get_theme()->get('Version'),
            'parent_version' => (string) wp_get_theme(get_template())->get('Version'),
        ];

        foreach ([get_stylesheet_directory(), get_template_directory()] as $dir) {
            foreach (['functions.php', 'style.css'] as $file) {
                $path = $dir . '/' . $file;
                $signal[$path] = is_file($path) ? (int) @filemtime($path) : 0;
            }
        }

        return $signal;
    }

    /**
     * Every local JSON file ACF actually loads (all `acf/json/load_paths`,
     * nested directories included) with its mtime — ≈ 3 ms for 47 files.
     * Falls back to a flat glob of the configured paths on older ACF.
     *
     * @return array<string, int> relative path => mtime
     */
    private static function acfJsonSignal()
    {
        $files = [];

        if (function_exists('acf_get_local_json_files')) {
            foreach ((array) acf_get_local_json_files() as $file) {
                if (is_string($file)) {
                    $files[] = $file;
                }
            }
        } else {
            $dirs = [get_stylesheet_directory() . '/acf-json'];

            if (function_exists('acf_get_setting')) {
                foreach ((array) apply_filters('acf/json/load_paths', (array) acf_get_setting('load_json')) as $dir) {
                    $dirs[] = (string) $dir;
                }
            }

            foreach (array_unique($dirs) as $dir) {
                if (is_dir($dir)) {
                    $files = array_merge($files, (array) glob(rtrim($dir, '/') . '/*.json'));
                }
            }
        }

        $signal = [];

        foreach ($files as $file) {
            $signal[str_replace(ABSPATH, '', (string) $file)] = (int) @filemtime($file);
        }

        ksort($signal);

        return $signal;
    }

    /**
     * @param array<string, int> $registrars
     * @return array<string, int>
     */
    private static function registrarSignal(array $registrars)
    {
        $signal = [];

        foreach (array_keys($registrars) as $relative) {
            $path = ABSPATH . ltrim((string) $relative, '/');
            $signal[(string) $relative] = is_file($path) ? (int) @filemtime($path) : 0;
        }

        return $signal;
    }

    /**
     * Files (theme parent + child, active plugins) that call
     * acf_add_local_field_group() or hook `acf/load_field*`. Build-time only;
     * bounded by a file cap and a time budget.
     *
     * @return array<string, int> relative path => mtime
     */
    public static function discoverRegistrarFiles()
    {
        $roots = [];

        foreach ([get_stylesheet_directory(), get_template_directory()] as $dir) {
            $real = realpath($dir);
            if ($real !== false) {
                $roots[] = $real;
            }
        }

        $plugin_root = realpath(WP_PLUGIN_DIR);

        foreach ((array) get_option('active_plugins', []) as $plugin) {
            $dir = realpath(dirname(WP_PLUGIN_DIR . '/' . (string) $plugin));

            if ($dir !== false && $dir !== $plugin_root && is_dir($dir)) {
                $roots[] = $dir;
            }
        }

        $roots = array_unique($roots);
        $abspath = realpath(ABSPATH) ?: ABSPATH;

        $skip = ['vendor', 'node_modules', 'tests', 'tmp', '.git', 'dist', 'build', 'docs'];
        $started = microtime(true);
        $scanned = 0;
        $found = [];

        foreach ($roots as $root) {
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveCallbackFilterIterator(
                        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                        static function ($current) use ($skip) {
                            return ! ($current->isDir() && in_array($current->getFilename(), $skip, true));
                        }
                    )
                );
            } catch (Throwable $e) {
                continue;
            }

            foreach ($iterator as $file) {
                if (++$scanned > self::REGISTRAR_SCAN_FILE_CAP || (microtime(true) - $started) > self::REGISTRAR_SCAN_TIME_BUDGET) {
                    break 2;
                }

                if (! $file->isFile() || substr($file->getFilename(), -4) !== '.php') {
                    continue;
                }

                $contents = @file_get_contents($file->getPathname());

                // Files that register local groups OR hook the load pipeline
                // (their edits change the cached output without any version bump).
                if (
                    is_string($contents)
                    && (strpos($contents, 'acf_add_local_field_group') !== false || strpos($contents, 'acf/load_field') !== false)
                    && strpos($file->getPathname(), '/addons/visual-editor/src/Performance/Bricks/') === false
                ) {
                    $relative = ltrim(str_replace(rtrim($abspath, '/'), '', $file->getPathname()), '/');
                    $found[$relative] = (int) $file->getMTime();
                }
            }
        }

        ksort($found);

        return $found;
    }

    // ---------------------------------------------------------------------
    // Invalidation, warm-up, verification
    // ---------------------------------------------------------------------

    /**
     * Bump the event salt (every payload misses on its next read) and
     * schedule an immediate warm-up.
     *
     * @param string $reason
     * @return void
     */
    public static function flush($reason)
    {
        if (self::$bumped) {
            return; // one bump per request — several triggers fire on one save
        }

        self::$bumped = true;
        update_option(self::OPTION_SALT, (string) time() . '.' . wp_generate_password(6, false), true);
        self::updateMeta(['flushed_at' => time(), 'flushed_by' => (string) $reason]);
        self::$fingerprintMemo = null;
        self::scheduleWarm();
    }

    /**
     * @return void
     */
    public static function scheduleWarm()
    {
        if (! wp_next_scheduled(self::CRON_WARM)) {
            wp_schedule_single_event(time() - 1, self::CRON_WARM);
        }

        // Kick the cron runner now (non-blocking loopback); harmless when
        // system cron owns the schedule.
        add_action('shutdown', 'spawn_cron', 100);
    }

    /**
     * The warm cron request is a frontend-context request: Bricks builds at
     * init, capture() has already stored the result by the time this runs.
     *
     * @return void
     */
    public static function onWarmCron()
    {
        self::updateMeta(['warmed_at' => time(), 'warm_state' => self::$state]);
    }

    /**
     * @return void
     */
    public static function onVerifyCron()
    {
        self::verify(true);
    }

    /**
     * @return void
     */
    public static function ensureVerifySchedule()
    {
        if (! wp_next_scheduled(self::CRON_VERIFY)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'daily', self::CRON_VERIFY);
        }
    }

    /**
     * @return void
     */
    public static function clearSchedules()
    {
        foreach ([self::CRON_VERIFY, self::CRON_WARM] as $hook) {
            $timestamp = wp_next_scheduled($hook);

            while ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
                $timestamp = wp_next_scheduled($hook);
            }
        }
    }

    /**
     * Build the schema fresh (bypassing Bricks' wp_cache) and compare with the
     * stored payload. Frontend/cron/CLI context only — admin builds are not
     * comparable (filters may branch on is_admin()).
     *
     * @param bool $persist record the outcome (verified marker, meta, replace a divergent payload)
     * @return array<string, mixed>
     */
    public static function verify($persist = false)
    {
        $result = [
            'ok' => false,
            'reason' => '',
            'fingerprint' => null,
            'bricks' => 'unknown',
            'cached' => false,
            'fields' => 0,
            'differing' => null,
            'freshMs' => null,
        ];

        if (! self::contextAllowsSeed()) {
            $result['reason'] = 'admin_context';
            return $result;
        }

        if (! ProviderFingerprint::bricksLoaded()) {
            $result['reason'] = 'bricks_not_loaded';
            return $result;
        }

        $pin = ProviderFingerprint::fieldsCacheCurrent();
        $result['fingerprint'] = $pin;
        $result['bricks'] = ProviderFingerprint::isKnownFieldsCache($pin) ? ProviderFingerprint::KNOWN_FIELDS_CACHE[$pin] : 'unknown';

        if (! ProviderFingerprint::isKnownFieldsCache($pin)) {
            $result['reason'] = 'fingerprint_unknown';
            return $result;
        }

        $class = ProviderFingerprint::PROVIDER_ACF_CLASS;

        try {
            wp_cache_delete(self::bricksCacheKey(), self::WP_CACHE_GROUP);
            $started = microtime(true);
            $fresh = $class::get_fields();
            $result['freshMs'] = (int) round((microtime(true) - $started) * 1000);
        } catch (Throwable $e) {
            $result['reason'] = 'exception:' . get_class($e);
            return $result;
        }

        if (! is_array($fresh) || $fresh === []) {
            $result['reason'] = 'nothing_built';
            return $result;
        }

        $result['fields'] = count($fresh);
        $payload = self::readPayload();
        $result['cached'] = $payload !== null;

        if ($payload === null) {
            // Nothing to compare yet: store the fresh build so the next request hits.
            if ($persist) {
                self::storeFields($fresh, 'verify');
                update_option(self::OPTION_VERIFIED, $pin);
                self::updateMeta(['last_verify_at' => time(), 'last_verify_ok' => true, 'last_verify_differing' => 0]);
            }
            $result['ok'] = true;
            $result['reason'] = 'stored_fresh';
            $result['differing'] = 0;
            return $result;
        }

        $differing = 0;
        $count = max(count($fresh), count($payload['fields']));
        for ($index = 0; $index < $count; $index++) {
            if (($fresh[$index] ?? null) !== ($payload['fields'][$index] ?? null)) {
                $differing++;
            }
        }
        $result['differing'] = $differing;
        $identical = $fresh === $payload['fields'];

        if ($persist) {
            update_option(self::OPTION_VERIFIED, $pin);
            $meta = ['last_verify_at' => time(), 'last_verify_ok' => $identical, 'last_verify_differing' => $differing];

            if (! $identical) {
                self::storeFields($fresh, 'verify');
                $meta['last_divergence_at'] = time();
                $meta['last_divergence_differing'] = $differing;
            }

            self::updateMeta($meta);
        }

        $result['ok'] = true;
        $result['reason'] = $identical ? 'identical' : 'divergence_replaced';

        return $result;
    }

    // ---------------------------------------------------------------------
    // Meta / status
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $patch
     * @return void
     */
    private static function updateMeta(array $patch)
    {
        $meta = get_option(self::OPTION_META, []);
        $meta = is_array($meta) ? $meta : [];
        update_option(self::OPTION_META, array_merge($meta, $patch), false);
    }

    /**
     * @return array<string, mixed>
     */
    public static function status()
    {
        $meta = get_option(self::OPTION_META, []);
        $pin = ProviderFingerprint::bricksLoaded() ? ProviderFingerprint::fieldsCacheCurrent() : null;

        return [
            'enabled' => self::isEnabled(),
            'state' => self::$state,
            'missReason' => self::$missReason,
            'gate' => self::gate(),
            'fingerprint' => $pin,
            'bricks' => ProviderFingerprint::isKnownFieldsCache($pin) ? ProviderFingerprint::KNOWN_FIELDS_CACHE[$pin] : 'unknown',
            'verified' => (string) get_option(self::OPTION_VERIFIED, ''),
            'transient' => get_transient(self::transientName()) !== false,
            'ttl' => self::ttl(),
            'nextVerify' => wp_next_scheduled(self::CRON_VERIFY) ?: null,
            'nextWarm' => wp_next_scheduled(self::CRON_WARM) ?: null,
            'meta' => is_array($meta) ? $meta : [],
        ];
    }

    /**
     * @return string
     */
    public static function state()
    {
        return self::$state;
    }

    /**
     * Test hook.
     *
     * @return void
     */
    public static function reset()
    {
        self::$state = 'disabled';
        self::$missReason = '';
        self::$registered = false;
        self::$fingerprintMemo = null;
        self::$bumped = false;
        remove_action('after_setup_theme', [self::class, 'arm'], 0);
        remove_action(self::CRON_WARM, [self::class, 'onWarmCron']);
        remove_action(self::CRON_VERIFY, [self::class, 'onVerifyCron']);
        remove_action('init', [self::class, 'seed'], 9999);
        remove_action('init', [self::class, 'capture'], 10003);
    }
}
