<?php

namespace Dbvc\VisualEditor\Rest\Controllers;

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_Term;

final class ObjectSearchController
{
    /**
     * @var EditModeState
     */
    private $edit_mode;

    /**
     * @var CapabilityManager
     */
    private $capabilities;

    public function __construct(EditModeState $edit_mode, CapabilityManager $capabilities)
    {
        $this->edit_mode = $edit_mode;
        $this->capabilities = $capabilities;
    }

    /**
     * @return void
     */
    public function register()
    {
        register_rest_route(
            'dbvc/v1',
            '/visual-editor/object-search',
            [
                'methods' => 'GET',
                'permission_callback' => [$this, 'canAccess'],
                'callback' => [$this, 'handle'],
            ]
        );
    }

    /**
     * @return bool
     */
    public function canAccess()
    {
        return $this->capabilities->canUseVisualEditor();
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle($request)
    {
        if (! ($request instanceof WP_REST_Request)) {
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'message' => __('Invalid request.', 'dbvc'),
                ],
                400
            );
        }

        if (! $this->edit_mode->isRestRequestAuthorized()) {
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'message' => __('Visual Editor mode is not active.', 'dbvc'),
                ],
                403
            );
        }

        $search = sanitize_text_field((string) $request->get_param('search'));
        $object_type = sanitize_key((string) $request->get_param('objectType'));
        $subtype = sanitize_key((string) $request->get_param('subtype'));
        $limit = max(1, min(30, absint($request->get_param('limit')) ?: 20));
        $items = [];

        if ($object_type === 'all') {
            $object_type = '';
        }

        if ($object_type === '') {
            $items = $this->mergeObjectItems(
                $this->searchPosts($search, $subtype, $limit),
                $this->searchTerms($search, $subtype, $limit),
                $limit
            );
        } elseif ($object_type === 'post') {
            $items = array_merge($items, $this->searchPosts($search, $subtype, $limit));
        } elseif ($object_type === 'term') {
            $items = array_merge($items, $this->searchTerms($search, $subtype, $limit));
        }

        return new WP_REST_Response(
            [
                'ok' => true,
                'items' => array_slice($items, 0, $limit),
            ]
        );
    }

    /**
     * @param array<int, array<string, mixed>> $posts
     * @param array<int, array<string, mixed>> $terms
     * @param int                              $limit
     * @return array<int, array<string, mixed>>
     */
    private function mergeObjectItems(array $posts, array $terms, $limit)
    {
        $items = [];
        $max = max(count($posts), count($terms));

        for ($index = 0; $index < $max; $index++) {
            if (isset($posts[$index])) {
                $items[] = $posts[$index];
            }

            if (count($items) >= $limit) {
                break;
            }

            if (isset($terms[$index])) {
                $items[] = $terms[$index];
            }

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param string $search
     * @param string $subtype
     * @param int    $limit
     * @return array<int, array<string, mixed>>
     */
    private function searchPosts($search, $subtype, $limit)
    {
        $post_types = $this->getSearchablePostTypes($subtype);

        if (empty($post_types)) {
            return [];
        }

        $query = new \WP_Query(
            [
                'post_type' => $post_types,
                'post_status' => 'any',
                'posts_per_page' => max($limit * 3, 20),
                's' => $search,
                'orderby' => $search !== '' ? 'relevance' : 'modified',
                'order' => $search !== '' ? 'DESC' : 'DESC',
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
                'suppress_filters' => false,
            ]
        );

        $items = [];

        foreach ($query->posts as $post) {
            if (! ($post instanceof WP_Post) || ! $this->capabilities->canEditPostId($post->ID)) {
                continue;
            }

            $item = $this->buildPostItem($post);

            if (! empty($item)) {
                $items[] = $item;
            }

            if (count($items) >= $limit) {
                break;
            }
        }

        wp_reset_postdata();

        return $items;
    }

    /**
     * @param string $subtype
     * @return array<int, string>
     */
    private function getSearchablePostTypes($subtype)
    {
        $objects = get_post_types(['public' => true], 'objects');
        $post_types = [];

        foreach ($objects as $name => $object) {
            $name = sanitize_key((string) $name);

            if ($name === 'attachment') {
                continue;
            }

            if ($this->isPostTypeExcluded($name)) {
                continue;
            }

            if ($subtype !== '' && $subtype !== $name) {
                continue;
            }

            if (! $object || empty($object->show_ui)) {
                continue;
            }

            $post_types[] = $name;
        }

        return $post_types;
    }

    /**
     * @param WP_Post $post
     * @return array<string, mixed>
     */
    private function buildPostItem(WP_Post $post)
    {
        $post_type_object = get_post_type_object($post->post_type);
        $status_object = get_post_status_object($post->post_status);
        $backend_url = get_edit_post_link($post->ID, 'raw');
        $frontend_url = get_permalink($post);
        $title = get_the_title($post);

        return [
            'objectType' => 'post',
            'id' => absint($post->ID),
            'title' => $title !== '' ? html_entity_decode(wp_strip_all_tags($title), ENT_QUOTES) : sprintf(__('Post #%d', 'dbvc'), absint($post->ID)),
            'subtype' => sanitize_key((string) $post->post_type),
            'typeLabel' => $post_type_object && ! empty($post_type_object->labels->singular_name) ? sanitize_text_field((string) $post_type_object->labels->singular_name) : sanitize_key((string) $post->post_type),
            'status' => $status_object && ! empty($status_object->label) ? sanitize_text_field((string) $status_object->label) : sanitize_key((string) $post->post_status),
            'frontendUrl' => is_string($frontend_url) ? esc_url_raw($frontend_url) : '',
            'backendUrl' => is_string($backend_url) ? esc_url_raw($backend_url) : '',
            'canEdit' => true,
        ];
    }

    /**
     * @param string $search
     * @param string $subtype
     * @param int    $limit
     * @return array<int, array<string, mixed>>
     */
    private function searchTerms($search, $subtype, $limit)
    {
        $taxonomies = $this->getSearchableTaxonomies($subtype);

        if (empty($taxonomies)) {
            return [];
        }

        $terms = get_terms(
            [
                'taxonomy' => $taxonomies,
                'hide_empty' => false,
                'number' => max($limit * 3, 20),
                'search' => $search,
                'orderby' => $search !== '' ? 'name' : 'count',
                'order' => $search !== '' ? 'ASC' : 'DESC',
            ]
        );

        if (is_wp_error($terms) || ! is_array($terms)) {
            return [];
        }

        $items = [];

        foreach ($terms as $term) {
            if (! ($term instanceof WP_Term) || ! current_user_can('edit_term', $term->term_id)) {
                continue;
            }

            $item = $this->buildTermItem($term);

            if (! empty($item)) {
                $items[] = $item;
            }

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param string $subtype
     * @return array<int, string>
     */
    private function getSearchableTaxonomies($subtype)
    {
        $objects = get_taxonomies(['public' => true], 'objects');
        $taxonomies = [];

        foreach ($objects as $name => $object) {
            $name = sanitize_key((string) $name);

            if ($this->isTaxonomyExcluded($name)) {
                continue;
            }

            if ($subtype !== '' && $subtype !== $name) {
                continue;
            }

            if (! $object || empty($object->show_ui)) {
                continue;
            }

            $taxonomies[] = $name;
        }

        return $taxonomies;
    }

    /**
     * @param WP_Term $term
     * @return array<string, mixed>
     */
    private function buildTermItem(WP_Term $term)
    {
        $taxonomy_object = get_taxonomy($term->taxonomy);
        $frontend_url = get_term_link($term);
        $backend_url = get_edit_term_link($term->term_id, $term->taxonomy, '', 'raw');

        return [
            'objectType' => 'term',
            'id' => absint($term->term_id),
            'title' => $term->name !== '' ? sanitize_text_field($term->name) : sprintf(__('Term #%d', 'dbvc'), absint($term->term_id)),
            'subtype' => sanitize_key((string) $term->taxonomy),
            'typeLabel' => $taxonomy_object && ! empty($taxonomy_object->labels->singular_name) ? sanitize_text_field((string) $taxonomy_object->labels->singular_name) : sanitize_key((string) $term->taxonomy),
            'status' => __('Term', 'dbvc'),
            'frontendUrl' => is_wp_error($frontend_url) ? '' : esc_url_raw((string) $frontend_url),
            'backendUrl' => is_string($backend_url) ? esc_url_raw($backend_url) : '',
            'canEdit' => true,
        ];
    }

    /**
     * @param string $post_type
     * @return bool
     */
    private function isPostTypeExcluded($post_type)
    {
        return class_exists('\DBVC_Visual_Editor_Addon')
            && method_exists('\DBVC_Visual_Editor_Addon', 'is_post_type_excluded')
            && \DBVC_Visual_Editor_Addon::is_post_type_excluded($post_type);
    }

    /**
     * @param string $taxonomy
     * @return bool
     */
    private function isTaxonomyExcluded($taxonomy)
    {
        return class_exists('\DBVC_Visual_Editor_Addon')
            && method_exists('\DBVC_Visual_Editor_Addon', 'is_taxonomy_excluded')
            && \DBVC_Visual_Editor_Addon::is_taxonomy_excluded($taxonomy);
    }
}
