<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class PostLookupService
{
    /**
     * @param string $slug
     * @param string $post_type
     * @return \WP_Post|null
     */
    public static function find_post_by_slug(string $slug, string $post_type = '')
    {
        $slug = sanitize_title($slug);
        $post_type = sanitize_key($post_type);

        if ($slug === '') {
            return null;
        }

        $post_types = $post_type !== '' ? [$post_type] : get_post_types(['show_ui' => true], 'names');
        if (empty($post_types)) {
            return null;
        }

        $posts = get_posts([
            'post_type' => $post_types,
            'name' => $slug,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'orderby' => 'ID',
            'order' => 'DESC',
            'no_found_rows' => true,
            'suppress_filters' => true,
        ]);

        if (! empty($posts[0]) && $posts[0] instanceof \WP_Post) {
            return $posts[0];
        }

        foreach ($post_types as $type) {
            $candidate = get_page_by_path($slug, OBJECT, $type);
            if ($candidate instanceof \WP_Post) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param string $slug
     * @param string $post_type
     * @return int
     */
    public static function find_post_id_by_slug(string $slug, string $post_type = ''): int
    {
        $post = self::find_post_by_slug($slug, $post_type);
        return $post instanceof \WP_Post ? (int) $post->ID : 0;
    }
}
