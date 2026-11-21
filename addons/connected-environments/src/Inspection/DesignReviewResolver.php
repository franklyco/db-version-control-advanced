<?php

namespace Dbvc\Connected\Inspection;

use Dbvc\ConnectedProtocol\DesignReviewAnnotation;

/**
 * The site-aware half of the Design Review annotation (CR-6): turns the
 * export's site-local keys (`post:19`, a template post id) into this site's
 * portable `vf_object_uid`s so {@see DesignReviewAnnotation::annotate()} can
 * match a row by identity before falling back to its title.
 *
 * An id is trusted only when the post it names here still carries the title
 * the export recorded (a clone keeps ids; a rebuilt site may not). Otherwise
 * the entry's path is resolved against this site's permalinks. Entries that
 * resolve to nothing are left to the title match.
 *
 * Read-only: no post is created, touched or stamped here.
 */
final class DesignReviewResolver
{
    /**
     * @param array<string, mixed> $review From {@see DesignReviewAnnotation::read()}.
     * @return array<string, string> Entry key → instance uid.
     */
    public function uid_map(array $review)
    {
        $map = [];
        foreach (isset($review['entries']) && is_array($review['entries']) ? $review['entries'] : [] as $key => $entry) {
            $post_id = $this->resolve_entry((string) $key, $entry);
            $uid = $post_id > 0 ? trim((string) get_post_meta($post_id, 'vf_object_uid', true)) : '';
            if ($uid !== '') {
                $map[(string) $key] = $uid;
            }
        }
        foreach (isset($review['templates']) && is_array($review['templates']) ? $review['templates'] : [] as $id => $template) {
            $post = get_post((int) $id);
            if (! $post instanceof \WP_Post || $post->post_type !== 'bricks_template') {
                continue;
            }
            if ((string) $template['title'] !== '' && DesignReviewAnnotation::clean_title($post->post_title) !== (string) $template['title']) {
                continue;
            }
            $uid = trim((string) get_post_meta($post->ID, 'vf_object_uid', true));
            if ($uid !== '') {
                $map[DesignReviewAnnotation::TEMPLATE_KEY_PREFIX . (int) $id] = $uid;
            }
        }

        return $map;
    }

    /**
     * @param string               $key
     * @param array<string, mixed> $entry
     * @return int Post id here, or 0.
     */
    private function resolve_entry($key, array $entry)
    {
        $title = trim((string) ($entry['title'] ?? ''));
        if ($key === 'home' || strpos($key, 'home:') === 0) {
            // The theme keys the front page `home:<id>` (older exports: bare `home`).
            $post = $key === 'home' ? null : get_post((int) substr($key, 5));
            if ($post instanceof \WP_Post && ($title === '' || DesignReviewAnnotation::clean_title($post->post_title) === $title)) {
                return (int) $post->ID;
            }
            $front = (int) get_option('page_on_front');

            return $front > 0 && get_post($front) instanceof \WP_Post ? $front : 0;
        }
        if (strpos($key, 'post:') === 0) {
            $post = get_post((int) substr($key, 5));
            if ($post instanceof \WP_Post && ($title === '' || DesignReviewAnnotation::clean_title($post->post_title) === $title)) {
                return (int) $post->ID;
            }
        }
        $path = (string) ($entry['path'] ?? '');
        if ($path !== '' && $path !== '/') {
            $post_id = (int) url_to_postid(home_url($path));
            if ($post_id > 0) {
                $post = get_post($post_id);
                if ($post instanceof \WP_Post && ($title === '' || DesignReviewAnnotation::clean_title($post->post_title) === $title)) {
                    return $post_id;
                }
            }
        }

        return 0;
    }
}
