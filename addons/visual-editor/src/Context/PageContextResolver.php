<?php

namespace Dbvc\VisualEditor\Context;

final class PageContextResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve()
    {
        $entity_id = get_queried_object_id();
        $is_singular = is_singular() && $entity_id > 0;

        if ($is_singular) {
            $post_type = (string) get_post_type($entity_id);
            if ($this->isPostTypeExcluded($post_type)) {
                return $this->buildUnsupportedContext();
            }

            return [
                'entityType' => 'post',
                'entityId' => absint($entity_id),
                'postType' => $post_type,
                'taxonomy' => '',
                'archiveType' => '',
                'archiveKey' => '',
                'isSingular' => true,
                'isArchive' => false,
                'isPostTypeArchive' => false,
                'isTaxonomyArchive' => false,
                'isSupported' => true,
                'url' => $this->resolveSingularUrl($entity_id),
            ];
        }

        $taxonomy_context = $this->resolveTaxonomyArchiveContext();
        if (! empty($taxonomy_context)) {
            return $taxonomy_context;
        }

        $post_type_context = $this->resolvePostTypeArchiveContext();
        if (! empty($post_type_context)) {
            return $post_type_context;
        }

        return $this->buildUnsupportedContext();
    }

    /**
     * @return bool
     */
    public function isSupported()
    {
        $context = $this->resolve();

        return ! empty($context['isSupported']);
    }

    /**
     * @return int
     */
    public function resolveRenderedPostId()
    {
        $rendered_post_id = get_the_ID();
        if ($rendered_post_id > 0) {
            return absint($rendered_post_id);
        }

        return is_singular() ? absint(get_queried_object_id()) : 0;
    }

    /**
     * @return string
     */
    private function resolveSingularUrl($entity_id)
    {
        $permalink = get_permalink($entity_id);
        if (is_string($permalink) && $permalink !== '') {
            return $permalink;
        }

        return (string) home_url(add_query_arg([]));
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveTaxonomyArchiveContext()
    {
        if (! (is_category() || is_tag() || is_tax())) {
            return [];
        }

        $term = get_queried_object();
        if (! $term instanceof \WP_Term || empty($term->term_id) || empty($term->taxonomy)) {
            return [];
        }

        $term_link = get_term_link($term);
        $url = ! is_wp_error($term_link) && is_string($term_link) && $term_link !== ''
            ? $term_link
            : (string) home_url(add_query_arg([]));
        $taxonomy = sanitize_key((string) $term->taxonomy);
        if ($this->isTaxonomyExcluded($taxonomy)) {
            return [];
        }

        $term_id = absint($term->term_id);

        return [
            'entityType' => 'term',
            'entityId' => $term_id,
            'postType' => '',
            'taxonomy' => $taxonomy,
            'archiveType' => 'term',
            'archiveKey' => 'term:' . $taxonomy . ':' . $term_id,
            'isSingular' => false,
            'isArchive' => true,
            'isPostTypeArchive' => false,
            'isTaxonomyArchive' => true,
            'isSupported' => true,
            'url' => $url,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePostTypeArchiveContext()
    {
        if (! is_post_type_archive() && ! (is_home() && ! is_front_page())) {
            return [];
        }

        $post_type = $this->resolveArchivePostType();
        if ($post_type === '') {
            return [];
        }

        if ($this->isPostTypeExcluded($post_type)) {
            return [];
        }

        $post_type_object = get_post_type_object($post_type);
        if (! $post_type_object || empty($post_type_object->public)) {
            return [];
        }

        $archive_url = get_post_type_archive_link($post_type);
        $url = is_string($archive_url) && $archive_url !== ''
            ? $archive_url
            : (string) home_url(add_query_arg([]));

        return [
            'entityType' => 'archive',
            'entityId' => 0,
            'postType' => $post_type,
            'taxonomy' => '',
            'archiveType' => 'post_type',
            'archiveKey' => 'post_type:' . $post_type,
            'isSingular' => false,
            'isArchive' => true,
            'isPostTypeArchive' => true,
            'isTaxonomyArchive' => false,
            'isSupported' => true,
            'url' => $url,
        ];
    }

    /**
     * @return string
     */
    private function resolveArchivePostType()
    {
        if (is_home() && ! is_front_page()) {
            return 'post';
        }

        $queried_object = get_queried_object();
        if ($queried_object instanceof \WP_Post_Type && ! empty($queried_object->name)) {
            return sanitize_key((string) $queried_object->name);
        }

        $query_post_type = get_query_var('post_type');
        if (is_array($query_post_type)) {
            $query_post_type = reset($query_post_type);
        }

        return is_scalar($query_post_type) ? sanitize_key((string) $query_post_type) : '';
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

    /**
     * @return array<string, mixed>
     */
    private function buildUnsupportedContext()
    {
        return [
            'entityType' => '',
            'entityId' => 0,
            'postType' => '',
            'taxonomy' => '',
            'archiveType' => '',
            'archiveKey' => '',
            'isSingular' => false,
            'isArchive' => false,
            'isPostTypeArchive' => false,
            'isTaxonomyArchive' => false,
            'isSupported' => false,
            'url' => (string) home_url(add_query_arg([])),
        ];
    }
}
