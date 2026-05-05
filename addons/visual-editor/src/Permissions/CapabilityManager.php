<?php

namespace Dbvc\VisualEditor\Permissions;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class CapabilityManager
{
    /**
     * @return bool
     */
    public function canUseVisualEditor()
    {
        if (! is_user_logged_in()) {
            return false;
        }

        $capability = (string) apply_filters('dbvc_visual_editor_base_capability', 'edit_others_posts');

        return current_user_can($capability);
    }

    /**
     * @param int $post_id
     * @return bool
     */
    public function canEditPostId($post_id)
    {
        $post_id = absint($post_id);

        if ($post_id <= 0 || ! $this->canUseVisualEditor()) {
            return false;
        }

        return current_user_can('edit_post', $post_id);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    public function canEditDescriptor(EditableDescriptor $descriptor)
    {
        $entity_type = isset($descriptor->entity['type']) ? (string) $descriptor->entity['type'] : '';
        $entity_id = $descriptor->entity['id'] ?? 0;

        if ($entity_type === 'post') {
            return $this->canEditPostId($entity_id);
        }

        if (! $this->canUseVisualEditor()) {
            return false;
        }

        if ($entity_type === 'term') {
            $term_id = absint($entity_id);

            return $term_id > 0 && current_user_can('edit_term', $term_id);
        }

        if ($entity_type === 'user') {
            $user_id = absint($entity_id);

            return $user_id > 0 && current_user_can('edit_user', $user_id);
        }

        if ($entity_type === 'option') {
            $capability = (string) apply_filters('dbvc_visual_editor_option_capability', 'manage_options', $descriptor);

            return $capability !== '' && current_user_can($capability);
        }

        return false;
    }
}
