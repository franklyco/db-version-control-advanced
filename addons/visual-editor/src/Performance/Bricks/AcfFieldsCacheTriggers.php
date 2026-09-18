<?php

namespace Dbvc\VisualEditor\Performance\Bricks;

/**
 * R5.later-perf-b — everything that bumps the fields-cache salt (and thereby
 * schedules a warm-up). Registered only while the cache switch is on.
 */
final class AcfFieldsCacheTriggers
{
    public const ACTION_FLUSH = 'dbvc_visual_editor_bricks_acf_fields_cache_flush';

    /**
     * @var array<int, string>
     */
    public const DEFAULT_POST_TYPES = ['acf-field-group', 'acf-field'];

    /**
     * @var bool
     */
    private static $registered = false;

    /**
     * @return void
     */
    public static function register()
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        // ACF schema changes (admin-edited groups and sub-fields, imports, trash).
        foreach ([
            'acf/update_field_group',
            'acf/delete_field_group',
            'acf/trash_field_group',
            'acf/untrash_field_group',
            'acf/import_field_group',
            'acf/duplicate_field_group',
            'acf/update_field',
            'acf/delete_field',
        ] as $hook) {
            add_action($hook, self::flusher($hook), 20, 0);
        }

        // Site lifecycle: themes, plugins, upgrades.
        add_action('switch_theme', self::flusher('switch_theme'), 20, 0);
        add_action('activated_plugin', self::flusher('activated_plugin'), 20, 0);
        add_action('deactivated_plugin', self::flusher('deactivated_plugin'), 20, 0);
        add_action('upgrader_process_complete', self::flusher('upgrader_process_complete'), 20, 0);
        add_action('_core_updated_successfully', self::flusher('core_updated'), 20, 0);
        add_action('update_option_active_plugins', self::flusher('active_plugins'), 20, 0);

        // Content that feeds `acf/load_field` filters (choices populated from posts).
        add_action('save_post', [self::class, 'onSavePost'], 20, 2);
        add_action('deleted_post', [self::class, 'onDeletedPost'], 20, 2);

        // Explicit flush from any code: do_action( 'dbvc_visual_editor_bricks_acf_fields_cache_flush' ).
        add_action(self::ACTION_FLUSH, self::flusher('action'), 10, 0);
    }

    /**
     * Post types whose saves invalidate the cache. Defaults to the ACF schema
     * post types; sites add the ones their `acf/load_field` filters read from
     * (e.g. `wsf_form` when WS Form choices are populated into fields).
     *
     * @return array<int, string>
     */
    public static function postTypes()
    {
        /**
         * Filter the post types whose saves/deletes flush the Bricks ACF fields cache.
         *
         * @param array<int, string> $post_types
         */
        $types = apply_filters('dbvc_visual_editor_bricks_acf_fields_cache_trigger_post_types', self::DEFAULT_POST_TYPES);

        return array_values(array_unique(array_filter(array_map('strval', (array) $types))));
    }

    /**
     * @param int      $post_id
     * @param \WP_Post $post
     * @return void
     */
    public static function onSavePost($post_id, $post)
    {
        if (! ($post instanceof \WP_Post) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if (in_array($post->post_type, self::postTypes(), true)) {
            AcfFieldsCache::flush('save_post:' . $post->post_type);
        }
    }

    /**
     * @param int           $post_id
     * @param \WP_Post|null $post
     * @return void
     */
    public static function onDeletedPost($post_id, $post = null)
    {
        if ($post instanceof \WP_Post && in_array($post->post_type, self::postTypes(), true)) {
            AcfFieldsCache::flush('deleted_post:' . $post->post_type);
        }
    }

    /**
     * @param string $reason
     * @return callable
     */
    private static function flusher($reason)
    {
        return static function () use ($reason) {
            AcfFieldsCache::flush($reason);
        };
    }

    /**
     * Test hook.
     *
     * @return void
     */
    public static function reset()
    {
        self::$registered = false;
        remove_action('save_post', [self::class, 'onSavePost'], 20);
        remove_action('deleted_post', [self::class, 'onDeletedPost'], 20);
    }
}
