<?php

namespace Dbvc\Connected\Adapters;

/**
 * Back-compat alias observer for the `wp.service` domain — the original single
 * post domain, retained so existing baselines, releases, subscriptions and the
 * M1–M7 record keep working unchanged. It is a {@see PostTypeObserver} pinned to
 * the `wp.service` domain string and the `wp-service-v1` profiles, with its post
 * type resolved through the legacy `dbvc_connected_service_post_type` filter and
 * its ignored-meta set through the legacy `dbvc_connected_service_ignored_meta_keys`
 * filter. Every other post type is observed as `wp.post:<type>` by a plain
 * {@see PostTypeObserver}; `DomainRegistry` never registers `wp.post:service`
 * because this alias already covers that type.
 */
final class ServicePostObserver extends PostTypeObserver
{
    public const DOMAIN = 'wp.service';
    public const PROFILE = 'wp-service-v1';
    /**
     * Coverage projection: the sorted set of portable UIDs currently present
     * (identity `collection.order`, like a Bricks collection order). It is
     * complete only when every observed post carries a UID, so the hub can
     * treat "never observed inside a complete inventory" as verified absent.
     */
    public const INVENTORY_PROFILE = 'wp-service-inventory-v1';
    public const DEFAULT_POST_TYPE = 'service';

    public function __construct()
    {
        parent::__construct([
            'domain' => self::DOMAIN,
            'post_type' => self::resolve_post_type(),
            'profile' => self::PROFILE,
            'inventory_profile' => self::INVENTORY_PROFILE,
            'create_terms' => true,
            // The alias is always apply-eligible (back-compat with the pre-M8 apply gate).
            'apply' => true,
        ]);
    }

    /**
     * The service post type is resolved dynamically through the legacy filter so a
     * late `dbvc_connected_service_post_type` change is still honoured (the parent
     * reads this method, never a cached property).
     *
     * @return string
     */
    public function post_type()
    {
        return self::resolve_post_type();
    }

    /**
     * Back-compat: after the generic ignored-meta computation, apply the legacy
     * `dbvc_connected_service_ignored_meta_keys` filter so existing hooks on the
     * `wp.service` domain keep working unchanged.
     *
     * @return array<int, string>
     */
    public function ignored_meta_keys()
    {
        /**
         * Meta keys excluded from the `wp.service` projection.
         *
         * @param array<int, string> $ignored
         */
        $ignored = (array) apply_filters('dbvc_connected_service_ignored_meta_keys', parent::ignored_meta_keys());

        return array_values(array_unique(array_map('strval', $ignored)));
    }

    /**
     * @return string
     */
    private static function resolve_post_type()
    {
        /**
         * Post type observed as the `wp.service` domain.
         *
         * @param string $post_type
         */
        $post_type = sanitize_key((string) apply_filters('dbvc_connected_service_post_type', self::DEFAULT_POST_TYPE));

        return $post_type !== '' ? $post_type : self::DEFAULT_POST_TYPE;
    }
}
