<?php

namespace Dbvc\VisualEditor\Cache;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class CacheInvalidator
{
    /**
     * @param EditableDescriptor $descriptor
     * @return void
     */
    public function invalidate(EditableDescriptor $descriptor)
    {
        $entity_type = isset($descriptor->entity['type']) ? (string) $descriptor->entity['type'] : '';
        $entity_id = isset($descriptor->entity['id']) ? absint($descriptor->entity['id']) : 0;

        if ($entity_type === 'post' && $entity_id > 0) {
            clean_post_cache($entity_id);

            if (class_exists('\\Bricks\\Integrations\\Dynamic_Data\\Providers\\Provider_Acf') && method_exists('\\Bricks\\Integrations\\Dynamic_Data\\Providers\\Provider_Acf', 'flush_cache')) {
                \Bricks\Integrations\Dynamic_Data\Providers\Provider_Acf::flush_cache($entity_id);
            }
        } elseif ($entity_type === 'term' && $entity_id > 0) {
            $taxonomy = isset($descriptor->entity['taxonomy']) ? sanitize_key((string) $descriptor->entity['taxonomy']) : '';

            if ($taxonomy !== '') {
                clean_term_cache($entity_id, $taxonomy);
            }
        } elseif ($entity_type === 'user' && $entity_id > 0) {
            clean_user_cache($entity_id);
        } elseif ($entity_type === 'option') {
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        }

        do_action('dbvc_visual_editor_invalidate_cache', $descriptor->toArray());
    }
}
