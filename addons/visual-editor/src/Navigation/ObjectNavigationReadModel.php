<?php

namespace Dbvc\VisualEditor\Navigation;

use Dbvc\VisualEditor\Permissions\CapabilityManager;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * R6-A: the single object-navigation read model shared by the Go To Object
 * toolbar popover and the Frontend Site Manager Workspace.
 *
 * Extracted from ObjectSearchController so both surfaces share one query,
 * one permission policy, and one item shape. Responsibilities:
 *
 * - object-type policy: public + show_ui post types / taxonomies, minus the
 *   admin-configured exclusion lists, minus attachments (media stays in the
 *   native Media Library — R6 product boundary);
 * - bounded, paginated search (page/perPage, hard-capped, never the whole
 *   site inventory);
 * - permission filtering pushed into SQL where the policy is expressible
 *   (per-type `edit_others_*` → author restriction) so offset pagination
 *   stays stable, with the per-item capability check retained as a
 *   fail-closed backstop bounded to perPage + 1 lookups;
 * - honest frontend routes: `frontendUrl` is emitted only when the object
 *   is actually viewable on the frontend by the current user; otherwise
 *   `hasFrontendRoute` is false and the backend edit link is the only route.
 *
 * Users are deliberately not enumerated (sensitive; no current Visual
 * Editor use case) and attachments are not listed here.
 */
final class ObjectNavigationReadModel
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 30;

    /**
     * R6.1-a sort vocabulary. Every key has an ID tiebreaker so offset pages
     * never overlap; `relevance` is search-scoped and degrades to `recent`
     * when no search term is present (WP_Query only honours it as a bare
     * string, E-142). Terms have no modified date, so `recent` / `newest` /
     * `oldest` map to creation order (`term_id`).
     */
    public const DEFAULT_SORT = 'recent';
    public const SORTS = ['recent', 'title_asc', 'title_desc', 'newest', 'oldest', 'relevance'];

    private const QUERY_MARKER = 'dbvc_ve_object_navigation';

    /**
     * @var CapabilityManager
     */
    private $capabilities;

    /**
     * Author-restricted post types for the query currently being built.
     * Populated per search() call and consumed by filterPostsWhere().
     *
     * @var array<int, string>
     */
    private $author_restricted_post_types = [];

    public function __construct(CapabilityManager $capabilities)
    {
        $this->capabilities = $capabilities;
    }

    /**
     * Normalise raw request input into the bounded query contract.
     *
     * @param array<string, mixed> $args
     * @return array{search: string, objectType: string, subtype: string, page: int, perPage: int, sort: string}
     */
    public function normalizeArgs(array $args)
    {
        $object_type = sanitize_key((string) ($args['objectType'] ?? ''));
        $search = sanitize_text_field((string) ($args['search'] ?? ''));
        $sort = sanitize_key((string) ($args['sort'] ?? ''));

        // Absent / unknown sort keeps the pre-R6.1 behaviour byte-identical for
        // callers that never send one (the Go To Object popover, D-073):
        // relevance while searching, recently-modified otherwise. An explicit
        // key always wins, except `relevance` without a term (meaningless).
        if (! in_array($sort, self::SORTS, true)) {
            $sort = $search !== '' ? 'relevance' : self::DEFAULT_SORT;
        }

        if ($sort === 'relevance' && $search === '') {
            $sort = self::DEFAULT_SORT;
        }

        if ($object_type === '' || $object_type === 'all') {
            $object_type = '';
        } elseif (! in_array($object_type, ['post', 'term'], true)) {
            // Unknown type (e.g. `user`, `attachment`): honour the request
            // shape but return nothing rather than falling back to "all".
            $object_type = 'none';
        }

        $per_page = absint($args['perPage'] ?? 0);

        if ($per_page <= 0) {
            $per_page = absint($args['limit'] ?? 0);
        }

        if ($per_page <= 0) {
            $per_page = self::DEFAULT_PER_PAGE;
        }

        return [
            'search' => $search,
            'objectType' => $object_type,
            'subtype' => sanitize_key((string) ($args['subtype'] ?? '')),
            'page' => max(1, absint($args['page'] ?? 1)),
            'perPage' => max(1, min(self::MAX_PER_PAGE, $per_page)),
            'sort' => $sort,
        ];
    }

    /**
     * @param array<string, mixed> $args See normalizeArgs().
     * @return array{items: array<int, array<string, mixed>>, page: int, perPage: int, hasMore: bool, sort: string}
     */
    public function search(array $args)
    {
        $query = $this->normalizeArgs($args);
        $items = [];
        $has_more = false;

        if ($query['objectType'] === '') {
            $posts = $this->searchPosts($query['search'], $query['subtype'], $query['page'], $query['perPage'], $query['sort']);
            $terms = $this->searchTerms($query['search'], $query['subtype'], $query['page'], $query['perPage'], $query['sort']);
            $items = $this->mergeObjectItems($posts['items'], $terms['items'], $query['perPage']);
            $has_more = $posts['hasMore'] || $terms['hasMore'];
        } elseif ($query['objectType'] === 'post') {
            $posts = $this->searchPosts($query['search'], $query['subtype'], $query['page'], $query['perPage'], $query['sort']);
            $items = $posts['items'];
            $has_more = $posts['hasMore'];
        } elseif ($query['objectType'] === 'term') {
            $terms = $this->searchTerms($query['search'], $query['subtype'], $query['page'], $query['perPage'], $query['sort']);
            $items = $terms['items'];
            $has_more = $terms['hasMore'];
        }

        return [
            'items' => array_slice($items, 0, $query['perPage']),
            'page' => $query['page'],
            'perPage' => $query['perPage'],
            'hasMore' => $has_more,
            'sort' => $query['sort'],
        ];
    }

    /**
     * WP_Query `orderby` for a normalised sort key. Array form everywhere
     * except `relevance`, which WP_Query only honours as a bare string.
     *
     * @param string $sort
     * @param string $search
     * @return string|array<string, string>
     */
    public function resolvePostOrderBy($sort, $search)
    {
        switch ($sort) {
            case 'title_asc':
                return ['title' => 'ASC', 'ID' => 'ASC'];
            case 'title_desc':
                return ['title' => 'DESC', 'ID' => 'DESC'];
            case 'newest':
                return ['date' => 'DESC', 'ID' => 'DESC'];
            case 'oldest':
                return ['date' => 'ASC', 'ID' => 'ASC'];
            case 'relevance':
                return $search !== '' ? 'relevance' : ['modified' => 'DESC', 'ID' => 'DESC'];
            case 'recent':
            default:
                return ['modified' => 'DESC', 'ID' => 'DESC'];
        }
    }

    /**
     * get_terms `orderby` + `order` for a normalised sort key.
     *
     * @param string $sort
     * @return array{orderby: string, order: string}
     */
    public function resolveTermOrder($sort)
    {
        switch ($sort) {
            case 'title_asc':
            case 'relevance':
                return ['orderby' => 'name', 'order' => 'ASC'];
            case 'title_desc':
                return ['orderby' => 'name', 'order' => 'DESC'];
            case 'oldest':
                return ['orderby' => 'term_id', 'order' => 'ASC'];
            case 'recent':
            case 'newest':
            default:
                return ['orderby' => 'term_id', 'order' => 'DESC'];
        }
    }

    /**
     * Object-type discovery for the workspace navigation rail. Cheap
     * (in-memory registry lookups only) — no counts, no object queries.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describeTypes()
    {
        $types = [];

        foreach ($this->getSearchablePostTypes('') as $name) {
            $object = get_post_type_object($name);

            if (! $object) {
                continue;
            }

            $types[] = [
                'objectType' => 'post',
                'subtype' => $name,
                'label' => $this->labelOf($object, 'name', $name),
                'singularLabel' => $this->labelOf($object, 'singular_name', $name),
                'hierarchical' => ! empty($object->hierarchical),
                'viewable' => is_post_type_viewable($object),
            ];
        }

        foreach ($this->getSearchableTaxonomies('') as $name) {
            $object = get_taxonomy($name);

            if (! $object) {
                continue;
            }

            $types[] = [
                'objectType' => 'term',
                'subtype' => $name,
                'label' => $this->labelOf($object, 'name', $name),
                'singularLabel' => $this->labelOf($object, 'singular_name', $name),
                'hierarchical' => ! empty($object->hierarchical),
                'viewable' => is_taxonomy_viewable($object),
            ];
        }

        return $types;
    }

    /**
     * @param string $subtype
     * @return array<int, string>
     */
    public function getSearchablePostTypes($subtype)
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

            if (! $this->canEditPostType($object)) {
                continue;
            }

            $post_types[] = $name;
        }

        return $post_types;
    }

    /**
     * @param string $subtype
     * @return array<int, string>
     */
    public function getSearchableTaxonomies($subtype)
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

            if (! $this->canEditTaxonomy($object)) {
                continue;
            }

            $taxonomies[] = $name;
        }

        return $taxonomies;
    }

    /**
     * @param WP_Post $post
     * @return array<string, mixed>
     */
    public function buildPostItem(WP_Post $post)
    {
        $post_type_object = get_post_type_object($post->post_type);
        $status_object = get_post_status_object($post->post_status);
        $backend_url = get_edit_post_link($post->ID, 'raw');
        $frontend_url = $this->resolvePostFrontendUrl($post);
        $title = get_the_title($post);

        return [
            'objectType' => 'post',
            'id' => absint($post->ID),
            'title' => $title !== '' ? html_entity_decode(wp_strip_all_tags($title), ENT_QUOTES) : sprintf(__('Post #%d', 'dbvc'), absint($post->ID)),
            'subtype' => sanitize_key((string) $post->post_type),
            'typeLabel' => $post_type_object ? $this->labelOf($post_type_object, 'singular_name', (string) $post->post_type) : sanitize_key((string) $post->post_type),
            'status' => $status_object && ! empty($status_object->label) ? sanitize_text_field((string) $status_object->label) : sanitize_key((string) $post->post_status),
            'statusKey' => sanitize_key((string) $post->post_status),
            'frontendUrl' => $frontend_url,
            'hasFrontendRoute' => $frontend_url !== '',
            'backendUrl' => is_string($backend_url) ? esc_url_raw($backend_url) : '',
            'canEdit' => true,
        ];
    }

    /**
     * @param WP_Term $term
     * @return array<string, mixed>
     */
    public function buildTermItem(WP_Term $term)
    {
        $taxonomy_object = get_taxonomy($term->taxonomy);
        $frontend_url = $this->resolveTermFrontendUrl($term);
        $backend_url = get_edit_term_link($term->term_id, $term->taxonomy, '', 'raw');

        return [
            'objectType' => 'term',
            'id' => absint($term->term_id),
            'title' => $term->name !== '' ? sanitize_text_field($term->name) : sprintf(__('Term #%d', 'dbvc'), absint($term->term_id)),
            'subtype' => sanitize_key((string) $term->taxonomy),
            'typeLabel' => $taxonomy_object ? $this->labelOf($taxonomy_object, 'singular_name', (string) $term->taxonomy) : sanitize_key((string) $term->taxonomy),
            'status' => __('Term', 'dbvc'),
            'statusKey' => 'term',
            'frontendUrl' => $frontend_url,
            'hasFrontendRoute' => $frontend_url !== '',
            'backendUrl' => is_string($backend_url) ? esc_url_raw($backend_url) : '',
            'canEdit' => true,
        ];
    }

    /**
     * Restrict author-limited post types to the current user's own posts in
     * SQL so pagination offsets don't drift under the per-item cap check.
     *
     * @param string   $where
     * @param WP_Query $query
     * @return string
     */
    public function filterPostsWhere($where, $query)
    {
        global $wpdb;

        if (! ($query instanceof WP_Query) || ! $query->get(self::QUERY_MARKER)) {
            return $where;
        }

        if (empty($this->author_restricted_post_types)) {
            return $where;
        }

        $placeholders = implode(', ', array_fill(0, count($this->author_restricted_post_types), '%s'));
        $where .= $wpdb->prepare(
            " AND ({$wpdb->posts}.post_type NOT IN ({$placeholders}) OR {$wpdb->posts}.post_author = %d)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            array_merge($this->author_restricted_post_types, [get_current_user_id()])
        );

        return $where;
    }

    /**
     * @param string $search
     * @param string $subtype
     * @param int    $page
     * @param int    $per_page
     * @param string $sort
     * @return array{items: array<int, array<string, mixed>>, hasMore: bool}
     */
    private function searchPosts($search, $subtype, $page, $per_page, $sort = self::DEFAULT_SORT)
    {
        $post_types = $this->getSearchablePostTypes($subtype);

        if (empty($post_types)) {
            return ['items' => [], 'hasMore' => false];
        }

        $this->author_restricted_post_types = $this->collectAuthorRestrictedPostTypes($post_types);

        add_filter('posts_where', [$this, 'filterPostsWhere'], 10, 2);

        $query = new WP_Query(
            [
                self::QUERY_MARKER => true,
                'post_type' => $post_types,
                'post_status' => 'any',
                'posts_per_page' => $per_page + 1,
                'offset' => ($page - 1) * $per_page,
                's' => $search,
                // R6.1-a: explicit sort vocabulary (see resolvePostOrderBy);
                // `relevance` only when the caller asked for it AND a term
                // exists — a bare search no longer forces relevance order.
                'orderby' => $this->resolvePostOrderBy($sort, $search),
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
                'suppress_filters' => false,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]
        );

        remove_filter('posts_where', [$this, 'filterPostsWhere'], 10);
        $this->author_restricted_post_types = [];

        $items = [];

        foreach ($query->posts as $post) {
            if (! ($post instanceof WP_Post) || ! $this->capabilities->canEditPostId($post->ID)) {
                continue;
            }

            $items[] = $this->buildPostItem($post);
        }

        wp_reset_postdata();

        return [
            'items' => array_slice($items, 0, $per_page),
            'hasMore' => count($items) > $per_page,
        ];
    }

    /**
     * @param string $search
     * @param string $subtype
     * @param int    $page
     * @param int    $per_page
     * @param string $sort
     * @return array{items: array<int, array<string, mixed>>, hasMore: bool}
     */
    private function searchTerms($search, $subtype, $page, $per_page, $sort = self::DEFAULT_SORT)
    {
        $taxonomies = $this->getSearchableTaxonomies($subtype);

        if (empty($taxonomies)) {
            return ['items' => [], 'hasMore' => false];
        }

        $order = $this->resolveTermOrder($sort);
        $terms = get_terms(
            [
                'taxonomy' => $taxonomies,
                'hide_empty' => false,
                'number' => $per_page + 1,
                'offset' => ($page - 1) * $per_page,
                'search' => $search,
                // R6.1-a: same vocabulary as posts; `count DESC` ("most used")
                // is intentionally not offered (maintainer decision 2026-09-16).
                'orderby' => $order['orderby'],
                'order' => $order['order'],
                'update_term_meta_cache' => false,
            ]
        );

        if (is_wp_error($terms) || ! is_array($terms)) {
            return ['items' => [], 'hasMore' => false];
        }

        $items = [];

        foreach ($terms as $term) {
            if (! ($term instanceof WP_Term) || ! $this->capabilities->canEditTermId($term->term_id)) {
                continue;
            }

            $items[] = $this->buildTermItem($term);
        }

        return [
            'items' => array_slice($items, 0, $per_page),
            'hasMore' => count($items) > $per_page,
        ];
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
     * Post types where the current user lacks `edit_others_*` and must be
     * limited to their own posts. Evaluated once per type, not per item.
     *
     * @param array<int, string> $post_types
     * @return array<int, string>
     */
    private function collectAuthorRestrictedPostTypes(array $post_types)
    {
        $restricted = [];

        foreach ($post_types as $name) {
            $object = get_post_type_object($name);
            $cap = $object && isset($object->cap->edit_others_posts) ? (string) $object->cap->edit_others_posts : 'edit_others_posts';

            if (! current_user_can($cap)) {
                $restricted[] = $name;
            }
        }

        return $restricted;
    }

    /**
     * @param \WP_Post_Type $object
     * @return bool
     */
    private function canEditPostType($object)
    {
        $cap = isset($object->cap->edit_posts) ? (string) $object->cap->edit_posts : 'edit_posts';

        return current_user_can($cap);
    }

    /**
     * @param \WP_Taxonomy $object
     * @return bool
     */
    private function canEditTaxonomy($object)
    {
        $cap = isset($object->cap->edit_terms) ? (string) $object->cap->edit_terms : 'manage_categories';

        return current_user_can($cap);
    }

    /**
     * Honest frontend route: public status on a viewable type, or a private
     * status the current user can read. Drafts/pending/scheduled posts have
     * no live frontend route and get an empty string.
     *
     * @param WP_Post $post
     * @return string
     */
    private function resolvePostFrontendUrl(WP_Post $post)
    {
        if (! is_post_type_viewable($post->post_type)) {
            return '';
        }

        $status = get_post_status_object($post->post_status);

        if (! $status) {
            return '';
        }

        $viewable = ! empty($status->public)
            || (! empty($status->private) && current_user_can('read_post', $post->ID));

        if (! $viewable) {
            return '';
        }

        $url = get_permalink($post);

        return is_string($url) && $url !== '' ? esc_url_raw($url) : '';
    }

    /**
     * @param WP_Term $term
     * @return string
     */
    private function resolveTermFrontendUrl(WP_Term $term)
    {
        if (! is_taxonomy_viewable($term->taxonomy)) {
            return '';
        }

        $url = get_term_link($term);

        return is_wp_error($url) || ! is_string($url) || $url === '' ? '' : esc_url_raw($url);
    }

    /**
     * @param object $object
     * @param string $key
     * @param string $fallback
     * @return string
     */
    private function labelOf($object, $key, $fallback)
    {
        $label = isset($object->labels->{$key}) ? (string) $object->labels->{$key} : '';

        return $label !== '' ? sanitize_text_field($label) : sanitize_key($fallback);
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
