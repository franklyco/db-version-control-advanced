<?php

namespace Dbvc\VisualEditor\Performance\Bricks;

use ReflectionClass;
use Throwable;

/**
 * R5.later-perf-a — opt-in, fingerprint-pinned, self-verifying replacement of
 * Bricks' ACF dynamic-data provider instance (E-159, D-082).
 *
 * Bricks rebuilds its ACF dynamic-data tag registry on every frontend / REST
 * request at `init`; on large group-nested schemas the nested-group lookup in
 * `Provider_Acf` is quadratic (≈ 1.5 s per request on the reference site).
 * This shim swaps the provider instance Bricks created in `register_providers`
 * (init 10000) for IndexedAcfProvider before `register_tags` runs (init 10001),
 * re-pointing the three instance filters the stock constructor registered so
 * nothing is left bound to the discarded instance.
 *
 * It is inert unless ALL of the following hold, re-checked on every request:
 *  1. the option (or the `dbvc_visual_editor_bricks_acf_tag_index_enabled`
 *     filter) turns it on — default off;
 *  2. Bricks' Providers / Provider_Acf / Base classes are loaded and the
 *     private static provider registry is still there;
 *  3. the installed Bricks code matches a fingerprint in
 *     ProviderFingerprint::KNOWN (a Bricks update silently disables it);
 *  4. that exact fingerprint has been verified on this site — verify() builds
 *     the registry with both implementations and requires them to be strictly
 *     identical (tags and loop tags, values and order) before it records the
 *     fingerprint as verified;
 *  5. at swap time the registered provider is the untouched stock class, has
 *     not registered its tags yet, and its three constructor filters are found
 *     where expected.
 *
 * verify() writes one option. It runs from the settings save, from explicit
 * maintainer commands, and from a one-off WP-Cron event: when the switch is on
 * and a request sees a pinned fingerprint that is not verified yet (Bricks was
 * updated to another pinned release), arm() schedules that event once, so the
 * index comes back after a Bricks update without anyone re-saving the settings.
 * A failed verification is never retried automatically, and an unpinned or
 * failed Bricks raises an admin notice for administrators instead.
 */
final class AcfTagIndexShim
{
    public const OPTION_ENABLED = 'dbvc_visual_editor_bricks_acf_tag_index_enabled';
    public const OPTION_VERIFIED = 'dbvc_visual_editor_bricks_acf_tag_index_verified';
    public const FILTER_ENABLED = 'dbvc_visual_editor_bricks_acf_tag_index_enabled';
    public const OPTION_AUTO_VERIFY = 'dbvc_visual_editor_bricks_acf_tag_index_auto_verify';
    public const CRON_VERIFY = 'dbvc_ve_bricks_acf_tag_index_verify';

    /**
     * Minimum time between two automatic verifications of the same fingerprint.
     * Only matters when a scheduled verification could not compare (e.g. it
     * ran in a context without Bricks); a completed one is final until the
     * fingerprint changes.
     */
    public const AUTO_VERIFY_RETRY = DAY_IN_SECONDS;

    /**
     * Filters Bricks' provider Base::__construct() registers on the instance.
     * Checked against the stock instance before anything is swapped.
     *
     * @var array<int, array{0: string, 1: string, 2: int}> [hook, method, priority]
     */
    private const INSTANCE_FILTERS = [
        ['bricks/setup/control_options', 'add_control_options', 10],
        ['bricks/query/run', 'set_loop_query', 10],
        ['bricks/query/loop_object', 'set_loop_object', 10],
    ];

    /**
     * @var array<int, int> accepted args per INSTANCE_FILTERS row
     */
    private const INSTANCE_FILTER_ARGS = [1, 2, 3];

    /**
     * @var string
     */
    private static $state = 'disabled';

    /**
     * @var bool
     */
    private static $registered = false;

    /**
     * Fingerprint the gate accepted in arm(); selects the vendored copy at swap.
     *
     * @var string|null
     */
    private static $fingerprint = null;

    /**
     * @return bool
     */
    public static function isEnabled()
    {
        $enabled = get_option(self::OPTION_ENABLED, '0') === '1';

        /**
         * Filter whether the Bricks ACF tag-index shim may arm on this request.
         *
         * @param bool $enabled Value of the settings switch.
         */
        return (bool) apply_filters('dbvc_visual_editor_bricks_acf_tag_index_enabled', $enabled);
    }

    /**
     * Called from the add-on bootstrap on every request. Cheap when off.
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

        // The switch is evaluated in arm(), not here: bootstrap() runs while the
        // plugin file is being included, before other plugins could filter it.
        // Priority 0: registered after Bricks wired its own init callbacks
        // (theme load), before other after_setup_theme listeners add theirs.
        add_action('after_setup_theme', [self::class, 'arm'], 0);
        add_action(self::CRON_VERIFY, [self::class, 'onVerifyCron']);
        add_action('admin_notices', [self::class, 'adminNotice']);
    }

    /**
     * Decide, once Bricks is loaded, whether to hook the swap.
     *
     * @return void
     */
    public static function arm()
    {
        if (! self::isEnabled()) {
            self::$state = 'disabled';
            return;
        }

        $fingerprint = null;
        $gate = self::gate($fingerprint);

        if ($gate !== 'ok') {
            self::$state = $gate;

            if ($gate === 'not_verified') {
                self::maybeScheduleVerify($fingerprint);
            }

            return;
        }

        self::$fingerprint = $fingerprint;
        self::$state = 'armed';

        // Mirror Bricks: Polylang moves the registration hook via this filter.
        $hook = apply_filters('bricks/dynamic_data/register_hook', 'init');
        add_action($hook, [self::class, 'swap'], 10000);
    }

    /**
     * Runs on the same hook/priority as Bricks' register_providers, after it
     * (registered later), and before register_tags at 10001.
     *
     * @return void
     */
    public static function swap()
    {
        try {
            self::$state = self::performSwap();
        } catch (Throwable $e) {
            self::$state = 'error:' . get_class($e);
        }
    }

    /**
     * @return string resulting state
     */
    private static function performSwap()
    {
        $providers_class = ProviderFingerprint::PROVIDERS_CLASS;
        $reflection = new ReflectionClass($providers_class);
        $property = $reflection->getProperty('providers');
        $property->setAccessible(true);
        $providers = $property->getValue();

        if (! is_array($providers) || ! isset($providers['acf'])) {
            // Admin / builder contexts where Bricks registers no providers.
            return 'no_acf_provider';
        }

        $stock = $providers['acf'];

        if (! is_object($stock) || get_class($stock) !== ProviderFingerprint::PROVIDER_ACF_CLASS) {
            return $stock instanceof IndexedAcfProvider ? 'active' : 'provider_already_replaced';
        }

        if (! empty($stock->tags) || ! empty($stock->loop_tags)) {
            return 'tags_already_registered';
        }

        // All three constructor filters must be exactly where Base::__construct put them.
        foreach (self::INSTANCE_FILTERS as $index => [$hook, $method, $priority]) {
            if (has_filter($hook, [$stock, $method]) !== $priority) {
                return 'filter_shape_mismatch';
            }
        }

        $fast = (new ReflectionClass(IndexedAcfProvider::class))->newInstanceWithoutConstructor();
        $fast->adopt('acf', self::$fingerprint); // throws (→ 'error:…', stock kept) if no vendored copy

        foreach (self::INSTANCE_FILTERS as $index => [$hook, $method, $priority]) {
            remove_filter($hook, [$stock, $method], $priority);
            add_filter($hook, [$fast, $method], $priority, self::INSTANCE_FILTER_ARGS[$index]);
        }

        $providers['acf'] = $fast;
        $property->setValue(null, $providers);

        return 'active';
    }

    /**
     * Why the shim would (not) arm right now. 'ok' or a reason string.
     *
     * @param string|null $fingerprint out: the installed Bricks fingerprint, when computed
     * @return string
     */
    public static function gate(&$fingerprint = null)
    {
        if (! ProviderFingerprint::bricksLoaded()) {
            return 'bricks_not_loaded';
        }

        if (! ProviderFingerprint::providersRegistryAccessible()) {
            return 'providers_registry_inaccessible';
        }

        $fingerprint = ProviderFingerprint::current();

        if (! ProviderFingerprint::isKnown($fingerprint)) {
            return 'fingerprint_unknown';
        }

        if ((string) get_option(self::OPTION_VERIFIED, '') !== $fingerprint) {
            return 'not_verified';
        }

        return 'ok';
    }

    /**
     * Build the registry with the stock provider and with IndexedAcfProvider
     * and require them to be strictly identical. Records the fingerprint as
     * verified (or as failed) when $persist is true.
     *
     * @param bool $persist
     * @return array<string, mixed>
     */
    public static function verify($persist = false)
    {
        $result = [
            'ok' => false,
            'reason' => '',
            'fingerprint' => null,
            'bricks' => 'unknown',
            'tags' => 0,
            'loopTags' => 0,
            'differing' => null,
            'stockMs' => null,
            'indexedMs' => null,
        ];

        if (! ProviderFingerprint::bricksLoaded()) {
            $result['reason'] = 'bricks_not_loaded';
            return $result;
        }

        if (! ProviderFingerprint::providersRegistryAccessible()) {
            $result['reason'] = 'providers_registry_inaccessible';
            return $result;
        }

        $fingerprint = ProviderFingerprint::current();
        $result['fingerprint'] = $fingerprint;
        $result['bricks'] = ProviderFingerprint::label($fingerprint);

        if (! ProviderFingerprint::isKnown($fingerprint)) {
            $result['reason'] = 'fingerprint_unknown';
            return $result;
        }

        $stock_class = ProviderFingerprint::PROVIDER_ACF_CLASS;
        $stock = new $stock_class('acf');

        // The stock constructor registered three filters on this throwaway
        // instance; drop them so the check leaves no trace.
        foreach (self::INSTANCE_FILTERS as [$hook, $method, $priority]) {
            remove_filter($hook, [$stock, $method], $priority);
        }

        $fast = (new ReflectionClass(IndexedAcfProvider::class))->newInstanceWithoutConstructor();

        try {
            $fast->adopt('acf', $fingerprint);

            $started = microtime(true);
            $stock->register_tags();
            $result['stockMs'] = (int) round((microtime(true) - $started) * 1000);

            $started = microtime(true);
            $fast->register_tags();
            $result['indexedMs'] = (int) round((microtime(true) - $started) * 1000);
        } catch (Throwable $e) {
            $result['reason'] = 'exception:' . get_class($e);

            if ($persist) {
                update_option(self::OPTION_VERIFIED, 'failed:' . $fingerprint);
            }

            return $result;
        }

        $result['tags'] = count($stock->tags);
        $result['loopTags'] = count($stock->loop_tags);

        $differing = 0;
        foreach ($stock->tags as $name => $tag) {
            if (! array_key_exists($name, $fast->tags) || $fast->tags[$name] !== $tag) {
                $differing++;
            }
        }
        $result['differing'] = $differing;

        $identical = $stock->tags === $fast->tags && $stock->loop_tags === $fast->loop_tags;

        $result['ok'] = $identical;
        $result['reason'] = $identical ? 'identical' : 'registries_differ';

        if ($persist) {
            update_option(self::OPTION_VERIFIED, $identical ? $fingerprint : 'failed:' . $fingerprint);
        }

        return $result;
    }

    /**
     * Schedule one background verify(true) for a pinned fingerprint that is not
     * verified on this site yet. Writes the cron option once per fingerprint;
     * every later request finds the event (or the result) and does nothing.
     *
     * @param string|null $fingerprint
     * @return bool whether an event was scheduled
     */
    public static function maybeScheduleVerify($fingerprint)
    {
        if (! self::shouldAutoVerify($fingerprint) || (function_exists('wp_doing_cron') && wp_doing_cron())) {
            return false;
        }

        if (wp_next_scheduled(self::CRON_VERIFY)) {
            return false;
        }

        return (bool) wp_schedule_single_event(time(), self::CRON_VERIFY);
    }

    /**
     * Whether $fingerprint may be verified automatically: pinned, not verified
     * here, not already failed here, and not attempted in the last day.
     *
     * @param string|null $fingerprint
     * @return bool
     */
    public static function shouldAutoVerify($fingerprint)
    {
        if (! ProviderFingerprint::isKnown($fingerprint)) {
            return false;
        }

        $marker = (string) get_option(self::OPTION_VERIFIED, '');

        if ($marker === $fingerprint || $marker === 'failed:' . $fingerprint) {
            return false;
        }

        $last = get_option(self::OPTION_AUTO_VERIFY, []);

        return ! (is_array($last)
            && ($last['fingerprint'] ?? null) === $fingerprint
            && (int) ($last['at'] ?? 0) > time() - self::AUTO_VERIFY_RETRY);
    }

    /**
     * WP-Cron handler: verify the installed Bricks once, if still needed.
     *
     * @return array<string, mixed>|null the verify() result, or null when skipped
     */
    public static function onVerifyCron()
    {
        if (! self::isEnabled()) {
            return null;
        }

        $fingerprint = null;

        if (self::gate($fingerprint) !== 'not_verified' || ! self::shouldAutoVerify($fingerprint)) {
            return null;
        }

        $result = self::verify(true);

        update_option(self::OPTION_AUTO_VERIFY, [
            'fingerprint' => $fingerprint,
            'at' => time(),
            'ok' => (bool) $result['ok'],
            'reason' => (string) $result['reason'],
        ], false);

        return $result;
    }

    /**
     * Why the index is off in a way only a person can fix, or '' when it is
     * on, off by choice, or about to verify itself.
     *
     * @return string 'unpinned' | 'failed' | ''
     */
    public static function attentionReason()
    {
        if (! self::isEnabled()) {
            return '';
        }

        $fingerprint = null;
        $gate = self::gate($fingerprint);

        if ($gate === 'fingerprint_unknown') {
            return 'unpinned';
        }

        if ($gate === 'not_verified' && (string) get_option(self::OPTION_VERIFIED, '') === 'failed:' . $fingerprint) {
            return 'failed';
        }

        return '';
    }

    /**
     * Admin notice for administrators when the switch is on but the index
     * cannot run, shown on the dashboard, the plugin, theme and update screens
     * and the Visual Editor settings page.
     *
     * @return void
     */
    public static function adminNotice()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $screen_id = $screen ? (string) $screen->id : '';

        if (! in_array($screen_id, ['dashboard', 'plugins', 'themes', 'update-core'], true) && strpos($screen_id, 'dbvc') === false) {
            return;
        }

        $reason = self::attentionReason();

        if ($reason === '') {
            return;
        }

        $message = $reason === 'unpinned'
            ? __('DBVC: the Bricks ACF tag index is switched on but not running. This DBVC version has no pinned copy for the installed Bricks version, so Bricks\' own (slower) lookup is in use. Pages are unaffected; update DBVC to a release that pins this Bricks version.', 'dbvc')
            : __('DBVC: the Bricks ACF tag index is switched on but not running. Its check found differences between the index and stock Bricks for the installed Bricks version, so Bricks\' own (slower) lookup is in use. Pages are unaffected; please report this.', 'dbvc');

        printf('<div class="notice notice-warning"><p>%s</p></div>', esc_html($message));
    }

    /**
     * @return void
     */
    public static function clearSchedule()
    {
        $timestamp = wp_next_scheduled(self::CRON_VERIFY);

        while ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_VERIFY);
            $timestamp = wp_next_scheduled(self::CRON_VERIFY);
        }
    }

    /**
     * Human-readable state for the settings page and `wp eval`.
     *
     * @return array<string, mixed>
     */
    public static function status()
    {
        $fingerprint = ProviderFingerprint::bricksLoaded() ? ProviderFingerprint::current() : null;

        return [
            'enabled' => self::isEnabled(),
            'state' => self::$state,
            'gate' => self::gate(),
            'fingerprint' => $fingerprint,
            'bricks' => ProviderFingerprint::label($fingerprint),
            'verified' => (string) get_option(self::OPTION_VERIFIED, ''),
            'autoVerify' => get_option(self::OPTION_AUTO_VERIFY, []),
            'nextVerify' => wp_next_scheduled(self::CRON_VERIFY) ?: null,
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
     * Test hook: forget request-scoped state.
     *
     * @return void
     */
    public static function reset()
    {
        self::$state = 'disabled';
        self::$registered = false;
        self::$fingerprint = null;
        remove_action('after_setup_theme', [self::class, 'arm'], 0);
        remove_action(self::CRON_VERIFY, [self::class, 'onVerifyCron']);
        remove_action('admin_notices', [self::class, 'adminNotice']);
    }
}
