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

    /** Address prefix for a universal post domain and its profile. */
    public const DOMAIN_POST_PREFIX = 'wp.post:';
    public const POST_PROFILE_PREFIX = 'wp-post:';

    /** Opt-in allow-list of post-type slugs observed as `wp.post:<type>` domains. */
    public const OPTION_POST_DOMAINS = 'dbvc_connected_post_domains';
    /** Subset of the observe list this connector will also let approved releases apply. */
    public const OPTION_POST_APPLY_DOMAINS = 'dbvc_connected_post_apply_domains';
    /** Per-type meta allow/deny + create-missing-terms policy, keyed by post-type slug. */
    public const OPTION_POST_TYPE_META = 'dbvc_connected_post_type_meta';

    /**
     * Built-in and internal post types never observed as a generic post domain:
     * media has its own channel (M7), the rest are editor/theme infrastructure.
     *
     * @var array<int, string>
     */
    private const EXCLUDED_POST_TYPES = [
        'attachment', 'revision', 'nav_menu_item', 'wp_block', 'wp_template',
        'wp_template_part', 'wp_global_styles', 'wp_navigation', 'oembed_cache',
        'custom_css', 'customize_changeset', 'wp_font_family', 'wp_font_face',
    ];

    /**
     * @var array<string, \Dbvc\ConnectedProtocol\DomainObserver>|null
     */
    private static $observers = null;

    /**
     * @return array<string, array<string, mixed>> Keyed by domain.
     */
    public static function definitions()
    {
        $definitions = [
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
                'post_type' => self::service_post_type(),
                'option' => '',
                // The alias is always apply-eligible (back-compat with the pre-M8 apply gate).
                'apply' => true,
                'label' => 'Service posts',
            ],
        ];

        // Universal post-type coverage: one `wp.post:<type>` domain per opted-in
        // custom post type. `wp.service` remains the alias for the service type, so
        // that type is never also registered here (dedup in opted_in_post_types()).
        $apply = self::apply_post_types();
        foreach (self::opted_in_post_types() as $type) {
            $domain = self::DOMAIN_POST_PREFIX . $type;
            $policy = self::post_type_policy($type);
            $definitions[$domain] = [
                'domain' => $domain,
                'profile' => self::POST_PROFILE_PREFIX . $type . '-v1',
                'inventory_profile' => self::POST_PROFILE_PREFIX . $type . '-inventory-v1',
                'kind' => 'post',
                'post_type' => $type,
                'option' => '',
                'meta_deny' => $policy['meta_deny'],
                'meta_allow' => $policy['meta_allow'],
                'create_terms' => $policy['create_terms'],
                // A generic CPT is apply-eligible only when explicitly opted into the apply list.
                'apply' => in_array($type, $apply, true),
                'label' => 'Posts: ' . $type,
            ];
        }

        return $definitions;
    }

    /**
     * The post type observed as the `wp.service` alias (legacy filter honoured).
     *
     * @return string
     */
    public static function service_post_type()
    {
        $type = sanitize_key((string) apply_filters('dbvc_connected_service_post_type', ServicePostObserver::DEFAULT_POST_TYPE));

        return $type !== '' ? $type : ServicePostObserver::DEFAULT_POST_TYPE;
    }

    /**
     * Resolved opt-in post-type slugs (stored option, then the
     * `dbvc_connected_post_domains` filter), with the service type, excluded
     * built-ins and duplicates removed. Slugs need not be registered yet — an
     * unregistered type simply reports unavailable until it registers, exactly
     * like the `wp.service` alias.
     *
     * @return array<int, string>
     */
    public static function opted_in_post_types()
    {
        $stored = get_option(self::OPTION_POST_DOMAINS, []);
        $stored = is_array($stored) ? $stored : [];
        /**
         * Opt-in list of post-type slugs observed as `wp.post:<type>` domains.
         *
         * @param array<int, string> $types
         */
        $types = (array) apply_filters('dbvc_connected_post_domains', $stored);

        $service = self::service_post_type();
        $out = [];
        foreach ($types as $type) {
            $type = sanitize_key((string) $type);
            if ($type === '' || $type === $service || self::is_excluded_post_type($type) || isset($out[$type])) {
                continue;
            }
            $out[$type] = true;
        }

        return array_keys($out);
    }

    /**
     * Post types this connector will also let approved releases APPLY (a subset of
     * the observe list — a type must be observed to be applied). The `wp.service`
     * alias is always apply-eligible and is not listed here. Resolved from the
     * stored option and the `dbvc_connected_post_apply_domains` filter.
     *
     * @return array<int, string>
     */
    public static function apply_post_types()
    {
        $stored = get_option(self::OPTION_POST_APPLY_DOMAINS, []);
        $stored = is_array($stored) ? $stored : [];
        /**
         * Post-type slugs approved releases may apply as `wp.post:<type>` domains.
         *
         * @param array<int, string> $types
         */
        $types = (array) apply_filters('dbvc_connected_post_apply_domains', $stored);
        $observed = array_fill_keys(self::opted_in_post_types(), true);

        $out = [];
        foreach ($types as $type) {
            $type = sanitize_key((string) $type);
            // Apply only where the type is also observed (no projection = nothing to apply against).
            if ($type === '' || ! isset($observed[$type]) || isset($out[$type])) {
                continue;
            }
            $out[$type] = true;
        }

        return array_keys($out);
    }

    /**
     * Per-type meta allow/deny and create-missing-terms policy from the stored
     * `dbvc_connected_post_type_meta` option (`{ "<type>": { "allow": [...],
     * "deny": [...], "create_terms": bool } }`). These become the PostTypeObserver
     * definition defaults; the per-key slice-1 filters still override them.
     *
     * @param string $type
     * @return array{meta_allow: array<int, string>, meta_deny: array<int, string>, create_terms: bool}
     */
    public static function post_type_policy($type)
    {
        $type = sanitize_key((string) $type);
        $all = get_option(self::OPTION_POST_TYPE_META, []);
        $all = is_array($all) ? $all : [];
        $policy = isset($all[$type]) && is_array($all[$type]) ? $all[$type] : [];

        $slugs = static function ($value) {
            $out = [];
            foreach ((array) $value as $key) {
                $key = trim((string) $key);
                if ($key !== '') {
                    $out[$key] = true;
                }
            }

            return array_keys($out);
        };

        return [
            'meta_allow' => $slugs($policy['allow'] ?? []),
            'meta_deny' => $slugs($policy['deny'] ?? []),
            'create_terms' => ! empty($policy['create_terms']),
        ];
    }

    /**
     * @param string $type
     * @return bool True for a built-in/internal type that is never a generic post domain.
     */
    public static function is_excluded_post_type($type)
    {
        return in_array((string) $type, self::EXCLUDED_POST_TYPES, true);
    }

    /**
     * @return array<int, string> Every post-backed domain (the `wp.service` alias plus `wp.post:<type>`).
     */
    public static function post_domains()
    {
        $domains = [];
        foreach (self::definitions() as $domain => $definition) {
            if (($definition['kind'] ?? 'option') === 'post') {
                $domains[] = (string) $domain;
            }
        }

        return $domains;
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
                if (($definition['kind'] ?? 'option') === 'post') {
                    // The `wp.service` alias keeps its dedicated back-compat observer;
                    // every other post type uses the parameterized PostTypeObserver.
                    self::$observers[$domain] = $domain === self::DOMAIN_WP_SERVICE
                        ? new ServicePostObserver()
                        : new PostTypeObserver($definition);
                } else {
                    self::$observers[$domain] = new BricksOptionCollectionObserver($definition);
                }
            }
        }

        return self::$observers;
    }

    /**
     * Drop the memoized observer set so a changed allow-list (or a test toggling
     * post types / filters) rebuilds it on the next access.
     *
     * @return void
     */
    public static function reset_observers()
    {
        self::$observers = null;
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
            if ($observer instanceof PostTypeObserver) {
                $watched[$observer->post_type()] = $domain;
            }
        }

        return $watched;
    }
}
