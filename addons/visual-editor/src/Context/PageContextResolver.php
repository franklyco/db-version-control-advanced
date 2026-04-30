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

        return [
            'entityType' => $is_singular ? 'post' : '',
            'entityId' => $is_singular ? absint($entity_id) : 0,
            'postType' => $is_singular ? (string) get_post_type($entity_id) : '',
            'isSingular' => $is_singular,
            'isSupported' => $is_singular,
            'url' => $this->resolveCurrentUrl($entity_id, $is_singular),
        ];
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

        return absint(get_queried_object_id());
    }

    /**
     * @param int  $entity_id
     * @param bool $is_singular
     * @return string
     */
    private function resolveCurrentUrl($entity_id, $is_singular)
    {
        if ($is_singular) {
            $permalink = get_permalink($entity_id);
            if (is_string($permalink) && $permalink !== '') {
                return $permalink;
            }
        }

        return (string) home_url(add_query_arg([]));
    }
}
