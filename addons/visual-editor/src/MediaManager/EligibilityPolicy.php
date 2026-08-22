<?php

namespace Dbvc\VisualEditor\MediaManager;

use Dbvc\VisualEditor\Permissions\CapabilityManager;
use WP_Post;
use WP_Term;

final class EligibilityPolicy
{
    /**
     * @var CapabilityManager
     */
    private $capabilities;

    /**
     * When true, the per-object capability check is skipped so eligibility is the
     * purely structural set (status/public/show-UI/exclusions). Used ONLY by the
     * cross-user index build (R2-H Slice 4b), which has no user context and whose
     * per-user capability is re-checked at read time. All request-time callers use
     * the default (structural + capability) policy.
     *
     * @var bool
     */
    private $structural;

    public function __construct(CapabilityManager $capabilities, $structural = false)
    {
        $this->capabilities = $capabilities;
        $this->structural = (bool) $structural;
    }

    /**
     * @return bool
     */
    public function isStructural()
    {
        return $this->structural;
    }

    /**
     * @param WP_Post|int $post
     * @return array<string, mixed>
     */
    public function assessPost($post)
    {
        $post = $this->resolvePost($post);

        if (! ($post instanceof WP_Post)) {
            return $this->postResult(false, 'invalid_post');
        }

        $post_type = sanitize_key((string) $post->post_type);
        $post_type_object = get_post_type_object($post_type);

        if (! $post_type_object || empty($post_type_object->public) || empty($post_type_object->show_ui)) {
            return $this->postResult(false, 'post_type_not_public', $post);
        }

        if ($this->isExcludedPostType($post_type)) {
            return $this->postResult(false, 'post_type_excluded', $post);
        }

        if ((string) $post->post_status !== 'publish') {
            return $this->postResult(false, 'post_not_published', $post);
        }

        if (! $this->structural && ! $this->capabilities->canEditPostId($post->ID)) {
            return $this->postResult(false, 'post_not_editable', $post);
        }

        return $this->postResult(true, 'eligible', $post);
    }

    /**
     * @param WP_Term|int $term
     * @return array<string, mixed>
     */
    public function assessTerm($term)
    {
        $term = $this->resolveTerm($term);

        if (is_wp_error($term) || ! ($term instanceof WP_Term)) {
            return $this->termResult(false, 'invalid_term');
        }

        $taxonomy = sanitize_key((string) $term->taxonomy);
        $taxonomy_object = get_taxonomy($taxonomy);

        if (! $taxonomy_object || empty($taxonomy_object->public) || empty($taxonomy_object->show_ui)) {
            return $this->termResult(false, 'taxonomy_not_public', $term);
        }

        if ($this->isExcludedTaxonomy($taxonomy)) {
            return $this->termResult(false, 'taxonomy_excluded', $term);
        }

        if (! $this->structural && ! $this->capabilities->canEditTermId($term->term_id)) {
            return $this->termResult(false, 'term_not_editable', $term);
        }

        return $this->termResult(true, 'eligible', $term);
    }

    /**
     * @return array<int, string>
     */
    public function getEligiblePostTypes()
    {
        $objects = get_post_types(['public' => true], 'objects');
        $post_types = [];

        foreach ($objects as $name => $object) {
            $name = sanitize_key((string) $name);

            if ($name === '' || ! $object || empty($object->show_ui) || $this->isExcludedPostType($name)) {
                continue;
            }

            $post_types[] = $name;
        }

        sort($post_types, SORT_STRING);

        return array_values($post_types);
    }

    /**
     * @return array<int, string>
     */
    public function getEligibleTaxonomies()
    {
        $objects = get_taxonomies(['public' => true], 'objects');
        $taxonomies = [];

        foreach ($objects as $name => $object) {
            $name = sanitize_key((string) $name);

            if ($name === '' || ! $object || empty($object->show_ui) || $this->isExcludedTaxonomy($name)) {
                continue;
            }

            $taxonomies[] = $name;
        }

        sort($taxonomies, SORT_STRING);

        return array_values($taxonomies);
    }

    /**
     * @param WP_Post|int $post
     * @return bool
     */
    public function supportsFeaturedImage($post)
    {
        $post = $this->resolvePost($post);

        return $post instanceof WP_Post
            && post_type_supports((string) $post->post_type, 'thumbnail');
    }

    /**
     * @param bool         $eligible
     * @param string       $reason
     * @param WP_Post|null $post
     * @return array<string, mixed>
     */
    private function postResult($eligible, $reason, ?WP_Post $post = null)
    {
        return [
            'eligible' => (bool) $eligible,
            'reason' => sanitize_key((string) $reason),
            'entity_type' => 'post',
            'entity_id' => $post instanceof WP_Post ? absint($post->ID) : 0,
            'subtype' => $post instanceof WP_Post ? sanitize_key((string) $post->post_type) : '',
            'status' => $post instanceof WP_Post ? sanitize_key((string) $post->post_status) : '',
            'featured_image_supported' => $post instanceof WP_Post ? $this->supportsFeaturedImage($post) : false,
        ];
    }

    /**
     * @param bool         $eligible
     * @param string       $reason
     * @param WP_Term|null $term
     * @return array<string, mixed>
     */
    private function termResult($eligible, $reason, ?WP_Term $term = null)
    {
        return [
            'eligible' => (bool) $eligible,
            'reason' => sanitize_key((string) $reason),
            'entity_type' => 'term',
            'entity_id' => $term instanceof WP_Term ? absint($term->term_id) : 0,
            'subtype' => $term instanceof WP_Term ? sanitize_key((string) $term->taxonomy) : '',
            'status' => '',
            'featured_image_supported' => false,
        ];
    }

    /**
     * @param string $post_type
     * @return bool
     */
    private function isExcludedPostType($post_type)
    {
        $post_type = sanitize_key((string) $post_type);

        if (in_array($post_type, ['attachment', 'revision', 'nav_menu_item'], true)) {
            return true;
        }

        return class_exists('\\DBVC_Visual_Editor_Addon')
            && method_exists('\\DBVC_Visual_Editor_Addon', 'is_post_type_excluded')
            && \DBVC_Visual_Editor_Addon::is_post_type_excluded($post_type);
    }

    /**
     * @param string $taxonomy
     * @return bool
     */
    private function isExcludedTaxonomy($taxonomy)
    {
        return class_exists('\\DBVC_Visual_Editor_Addon')
            && method_exists('\\DBVC_Visual_Editor_Addon', 'is_taxonomy_excluded')
            && \DBVC_Visual_Editor_Addon::is_taxonomy_excluded($taxonomy);
    }

    /**
     * @param WP_Post|int $post
     * @return WP_Post|null
     */
    private function resolvePost($post)
    {
        if ($post instanceof WP_Post) {
            return $post;
        }

        if (! is_numeric($post)) {
            return null;
        }

        $post_id = absint($post);

        return $post_id > 0 ? get_post($post_id) : null;
    }

    /**
     * @param WP_Term|int $term
     * @return WP_Term|null
     */
    private function resolveTerm($term)
    {
        if ($term instanceof WP_Term) {
            return $term;
        }

        if (! is_numeric($term)) {
            return null;
        }

        $term_id = absint($term);
        if ($term_id <= 0) {
            return null;
        }

        $resolved = get_term($term_id);

        return is_wp_error($resolved) || ! ($resolved instanceof WP_Term)
            ? null
            : $resolved;
    }
}
