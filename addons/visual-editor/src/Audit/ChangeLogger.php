<?php

namespace Dbvc\VisualEditor\Audit;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class ChangeLogger
{
    /**
     * @param EditableDescriptor $descriptor
     * @param mixed              $old_value
     * @param mixed              $new_value
     * @return void
     */
    public function log(EditableDescriptor $descriptor, $old_value, $new_value)
    {
        $context = [
            'token' => $descriptor->token,
            'entity' => $descriptor->entity,
            'render' => $descriptor->render,
            'source' => $descriptor->source,
            'old_value' => $old_value,
            'new_value' => $new_value,
            'changed_by' => get_current_user_id(),
            'changed_at' => current_time('mysql', true),
        ];

        if (class_exists('DBVC_Database') && method_exists('DBVC_Database', 'log_activity')) {
            \DBVC_Database::log_activity(
                'dbvc_visual_editor_save',
                'info',
                'Visual editor save applied.',
                $context
            );
        }

        if (class_exists('DBVC_Sync_Logger') && method_exists('DBVC_Sync_Logger', 'log')) {
            \DBVC_Sync_Logger::log('Visual editor save applied', $context);
        }

        do_action('dbvc_visual_editor_audit_event', $context);
    }
}
