<?php

namespace Dbvc\VisualEditor\Performance\Bricks;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * R5.later-perf-a — pins the shim to the exact Bricks code it was derived from.
 *
 * The indexed provider vendors `Provider_Acf::register_tag()` and replaces
 * `get_nested_parent_group_field_data()`; it also relies on `Base::__construct()`
 * registering exactly three instance filters and on `Providers::register()`
 * wiring `register_providers` / `register_tags` at init 10000 / 10001. The
 * fingerprint is a hash of those five method sources (whitespace-normalised)
 * read from the installed theme files. A Bricks update that touches any of
 * them changes the fingerprint and the shim stays inert until it is re-derived
 * and re-verified against the new code.
 */
final class ProviderFingerprint
{
    public const PROVIDERS_CLASS = 'Bricks\\Integrations\\Dynamic_Data\\Providers';
    public const PROVIDER_ACF_CLASS = 'Bricks\\Integrations\\Dynamic_Data\\Providers\\Provider_Acf';
    public const PROVIDER_BASE_CLASS = 'Bricks\\Integrations\\Dynamic_Data\\Providers\\Base';

    /**
     * Fingerprints the vendored code was derived from, keyed by hash.
     *
     * @var array<string, string>
     */
    public const KNOWN = [
        '07662227df5bdbd7219e8e8613eb4834aec30902' => 'Bricks 2.3.8',
    ];

    /**
     * R5.later-perf-b pins: the methods whose behaviour the fields cache relies
     * on — the wp_cache key derivation + JSON round-trip in get_fields(), the
     * locations affix, and the flush hook. Keyed by hash.
     *
     * @var array<string, string>
     */
    public const KNOWN_FIELDS_CACHE = [
        '0ca8ed62f2cbc8615347573f3709025e95b7e76d' => 'Bricks 2.3.8',
    ];

    /**
     * @var array<int, array{0: string, 1: string}> [class, method]
     */
    private const FIELDS_CACHE_METHODS = [
        [self::PROVIDER_ACF_CLASS, 'get_fields'],
        [self::PROVIDER_ACF_CLASS, 'get_fields_locations'],
        [self::PROVIDER_ACF_CLASS, 'required_hooks'],
        [self::PROVIDER_ACF_CLASS, 'flush_cache'],
    ];

    /**
     * @var array<int, array{0: string, 1: string}> [class, method]
     */
    private const METHODS = [
        [self::PROVIDER_ACF_CLASS, 'register_tag'],
        [self::PROVIDER_ACF_CLASS, 'get_nested_parent_group_field_data'],
        [self::PROVIDER_ACF_CLASS, 'get_fields_by_context'],
        [self::PROVIDER_ACF_CLASS, 'get_flexible_content_parent_layout_data'],
        [self::PROVIDER_BASE_CLASS, '__construct'],
        [self::PROVIDERS_CLASS, 'register'],
    ];

    /**
     * @return bool
     */
    public static function bricksLoaded()
    {
        // Providers is instantiated while the theme loads; the provider classes
        // are autoloaded lazily by Bricks (first use is register_providers at
        // init 10000), so let the autoloader bring them in — but only once
        // Providers proves Bricks is the active theme.
        return class_exists(self::PROVIDERS_CLASS, false)
            && class_exists(self::PROVIDER_BASE_CLASS)
            && class_exists(self::PROVIDER_ACF_CLASS);
    }

    /**
     * Current fingerprint of the installed Bricks code, or null when Bricks
     * (or one of the pinned methods) is not there.
     *
     * @return string|null
     */
    public static function current()
    {
        if (! self::bricksLoaded()) {
            return null;
        }

        $parts = [];

        foreach (self::METHODS as [$class, $method]) {
            $source = self::methodSource($class, $method);

            if ($source === null) {
                return null;
            }

            $parts[] = $class . '::' . $method . "\n" . $source;
        }

        return sha1(implode("\n---\n", $parts));
    }

    /**
     * Fingerprint of the methods the fields cache (perf-b) relies on.
     *
     * @return string|null
     */
    public static function fieldsCacheCurrent()
    {
        if (! self::bricksLoaded()) {
            return null;
        }

        $parts = [];

        foreach (self::FIELDS_CACHE_METHODS as [$class, $method]) {
            $source = self::methodSource($class, $method);

            if ($source === null) {
                return null;
            }

            $parts[] = $class . '::' . $method . "\n" . $source;
        }

        return sha1(implode("\n---\n", $parts));
    }

    /**
     * @param string|null $fingerprint
     * @return bool
     */
    public static function isKnownFieldsCache($fingerprint)
    {
        return is_string($fingerprint) && isset(self::KNOWN_FIELDS_CACHE[$fingerprint]);
    }

    /**
     * @param string|null $fingerprint
     * @return bool
     */
    public static function isKnown($fingerprint)
    {
        return is_string($fingerprint) && isset(self::KNOWN[$fingerprint]);
    }

    /**
     * @param string|null $fingerprint
     * @return string
     */
    public static function label($fingerprint)
    {
        return is_string($fingerprint) && isset(self::KNOWN[$fingerprint]) ? self::KNOWN[$fingerprint] : 'unknown';
    }

    /**
     * Whitespace-normalised source of one method, read from its declaring file.
     *
     * @param string $class
     * @param string $method
     * @return string|null
     */
    public static function methodSource($class, $method)
    {
        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (ReflectionException $e) {
            return null;
        }

        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();

        if (! is_string($file) || ! is_readable($file) || $start === false || $end === false || $end < $start) {
            return null;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);

        if (! is_array($lines) || count($lines) < $end) {
            return null;
        }

        return self::normalize(implode("\n", array_slice($lines, $start - 1, $end - $start + 1)));
    }

    /**
     * @param string $source
     * @return string
     */
    public static function normalize($source)
    {
        $source = str_replace("\r", '', $source);
        $lines = [];

        foreach (explode("\n", $source) as $line) {
            $line = trim(preg_replace('/\s+/', ' ', $line));

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Whether Bricks' Providers class still has the private static registry
     * the shim swaps into.
     *
     * @return bool
     */
    public static function providersRegistryAccessible()
    {
        if (! class_exists(self::PROVIDERS_CLASS, false)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass(self::PROVIDERS_CLASS);

            return $reflection->hasProperty('providers')
                && $reflection->getProperty('providers')->isStatic()
                && $reflection->hasMethod('get_registered_provider');
        } catch (ReflectionException $e) {
            return false;
        }
    }
}
