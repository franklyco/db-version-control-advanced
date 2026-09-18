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
 * Nothing is written at runtime; verify() writes one option and is only called
 * from the settings save and explicit maintainer commands.
 */
final class AcfTagIndexShim
{
    public const OPTION_ENABLED = 'dbvc_visual_editor_bricks_acf_tag_index_enabled';
    public const OPTION_VERIFIED = 'dbvc_visual_editor_bricks_acf_tag_index_verified';
    public const FILTER_ENABLED = 'dbvc_visual_editor_bricks_acf_tag_index_enabled';

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

        $gate = self::gate();

        if ($gate !== 'ok') {
            self::$state = $gate;
            return;
        }

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
        $fast->adopt('acf');

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
     * @return string
     */
    public static function gate()
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
        $fast->adopt('acf');

        try {
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
        remove_action('after_setup_theme', [self::class, 'arm'], 0);
    }
}
