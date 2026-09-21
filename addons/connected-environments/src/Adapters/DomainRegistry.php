<?php

namespace Dbvc\Connected\Adapters;

/**
 * Domains supported by the local observation slice and their signal sources.
 *
 * Each domain maps one Bricks option (its whole collection) to one dirty
 * object key. Adding a domain here without a verified observer is not
 * allowed; unsupported domains are reported as unsupported, never as clean.
 */
final class DomainRegistry
{
    public const DOMAIN_BRICKS_GLOBAL_CLASS = 'bricks.global_class';
    public const DOMAIN_BRICKS_VARIABLE = 'bricks.variable';
    public const DOMAIN_WP_SERVICE = 'wp.service';
    public const COLLECTION_KEY = 'collection';

    /**
     * @var array<string, \Dbvc\ConnectedProtocol\DomainObserver>|null
     */
    private static $observers = null;

    /**
     * @return array<string, array<string, mixed>> Keyed by domain.
     */
    public static function definitions()
    {
        return [
            self::DOMAIN_BRICKS_GLOBAL_CLASS => [
                'domain' => self::DOMAIN_BRICKS_GLOBAL_CLASS,
                'profile' => 'bricks-global-class-v1',
                'option' => 'bricks_global_classes',
                'key_field' => 'id',
                'name_field' => 'name',
                // No documented volatile top-level keys in the active class entry (id, name, settings, cat).
                'volatile_top_level_keys' => [],
                'label' => 'Bricks global classes',
            ],
            self::DOMAIN_BRICKS_VARIABLE => [
                'domain' => self::DOMAIN_BRICKS_VARIABLE,
                'profile' => 'bricks-variable-v1',
                'option' => 'bricks_global_variables',
                'key_field' => 'id',
                'name_field' => 'name',
                'volatile_top_level_keys' => [],
                'label' => 'Bricks global variables',
            ],
            self::DOMAIN_WP_SERVICE => [
                'domain' => self::DOMAIN_WP_SERVICE,
                'profile' => ServicePostObserver::PROFILE,
                'kind' => 'post',
                'option' => '',
                'label' => 'Service posts',
            ],
        ];
    }

    /**
     * @param string $domain
     * @return bool True when the domain owns portable identity itself (no sidecar) and is observed per object.
     */
    public static function is_post_domain($domain)
    {
        $definitions = self::definitions();

        return isset($definitions[(string) $domain]) && ($definitions[(string) $domain]['kind'] ?? 'option') === 'post';
    }

    /**
     * @return array<int, string>
     */
    public static function domains()
    {
        return array_keys(self::definitions());
    }

    /**
     * @param string $domain
     * @return BricksOptionCollectionObserver|null
     */
    public static function observer_for($domain)
    {
        $observers = self::observers();

        return $observers[(string) $domain] ?? null;
    }

    /**
     * @return array<string, \Dbvc\ConnectedProtocol\DomainObserver>
     */
    public static function observers()
    {
        if (self::$observers === null) {
            self::$observers = [];
            foreach (self::definitions() as $domain => $definition) {
                self::$observers[$domain] = ($definition['kind'] ?? 'option') === 'post'
                    ? new ServicePostObserver()
                    : new BricksOptionCollectionObserver($definition);
            }
        }

        return self::$observers;
    }

    /**
     * @param string $option_name
     * @return array{domain: string, object_key: string}|null
     */
    public static function signal_for_option($option_name)
    {
        foreach (self::definitions() as $domain => $definition) {
            if ($definition['option'] !== '' && $definition['option'] === (string) $option_name) {
                return ['domain' => $domain, 'object_key' => self::COLLECTION_KEY];
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function watched_options()
    {
        return array_values(array_filter(array_map(static function ($definition) {
            return (string) $definition['option'];
        }, self::definitions())));
    }

    /**
     * @return array<string, string> post type => domain.
     */
    public static function watched_post_types()
    {
        $watched = [];
        foreach (self::observers() as $domain => $observer) {
            if ($observer instanceof ServicePostObserver) {
                $watched[$observer->post_type()] = $domain;
            }
        }

        return $watched;
    }
}
