<?php

namespace Dbvc\VisualEditor\Journal;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class ChangeJournalRecorder
{
    /**
     * @var ChangeJournalStore
     */
    private $store;

    public function __construct(ChangeJournalStore $store)
    {
        $this->store = $store;
    }

    /**
     * @return void
     */
    public function register()
    {
        $this->store->register();
    }

    /**
     * @return void
     */
    public function unregister()
    {
        $this->store->unregister();
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param string             $resolver_name
     * @return int
     */
    public function start(EditableDescriptor $descriptor, $resolver_name = '')
    {
        return $this->startWithContext($descriptor, $resolver_name, []);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param string             $resolver_name
     * @param array<string,mixed> $batch_context
     * @return int
     */
    public function startBatch(EditableDescriptor $descriptor, $resolver_name = '', array $batch_context = [])
    {
        return $this->startWithContext($descriptor, $resolver_name, [
            'batch' => $batch_context,
        ]);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param string             $resolver_name
     * @param array<string,mixed> $extra_context
     * @return int
     */
    private function startWithContext(EditableDescriptor $descriptor, $resolver_name = '', array $extra_context = [])
    {
        $page = $this->resolvePageContext($descriptor);
        $owner = $this->resolveOwnerContext($descriptor);
        $context = array_merge(
            [
                'descriptorVersion' => $this->resolveDescriptorVersion($descriptor),
                'resolver' => sanitize_key((string) $resolver_name),
                'scope' => isset($descriptor->scope) ? (string) $descriptor->scope : 'current_entity',
                'status' => isset($descriptor->status) ? (string) $descriptor->status : '',
                'page' => $page,
                'owner' => $owner,
                'loop' => $this->resolveLoopContext($descriptor),
                'path' => $this->resolvePathContext($descriptor),
                'mutation' => $this->resolveMutationContext($descriptor),
                'source' => isset($descriptor->source) && is_array($descriptor->source) ? $descriptor->source : [],
                'render' => isset($descriptor->render) && is_array($descriptor->render) ? $descriptor->render : [],
            ],
            $extra_context
        );

        return $this->store->startChangeSet(
            [
                'status' => 'pending',
                'scope_type' => isset($descriptor->scope) ? (string) $descriptor->scope : 'current_entity',
                'page_entity_type' => isset($page['type']) ? (string) $page['type'] : '',
                'page_entity_id' => isset($page['id']) ? absint($page['id']) : 0,
                'page_entity_subtype' => isset($page['subtype']) ? (string) $page['subtype'] : '',
                'owner_entity_type' => isset($owner['type']) ? (string) $owner['type'] : '',
                'owner_entity_id' => isset($owner['id']) ? absint($owner['id']) : 0,
                'owner_entity_subtype' => isset($owner['subtype']) ? (string) $owner['subtype'] : '',
                'descriptor_token' => isset($descriptor->token) ? (string) $descriptor->token : '',
                'context' => $context,
            ]
        );
    }

    /**
     * @param int                $change_set_id
     * @param EditableDescriptor $descriptor
     * @param string             $resolver_name
     * @param mixed              $old_value
     * @param mixed              $new_value
     * @param array<string, mixed> $save_context
     * @return void
     */
    public function recordSuccess($change_set_id, EditableDescriptor $descriptor, $resolver_name, $old_value, $new_value, array $save_context = [])
    {
        $this->recordItem($change_set_id, $descriptor, $resolver_name, $old_value, $new_value, $old_value, 'completed', '', $save_context);
        $this->store->finishChangeSet($change_set_id, 'completed');
    }

    /**
     * @param int                $change_set_id
     * @param EditableDescriptor $descriptor
     * @param string             $resolver_name
     * @param mixed              $old_value
     * @param mixed              $new_value
     * @param string             $result_status
     * @param string             $error_message
     * @param array<string,mixed> $save_context
     * @return void
     */
    public function recordBatchItem($change_set_id, EditableDescriptor $descriptor, $resolver_name, $old_value, $new_value, $result_status = 'completed', $error_message = '', array $save_context = [])
    {
        $this->recordItem($change_set_id, $descriptor, $resolver_name, $old_value, $new_value, $old_value, $result_status, $error_message, $save_context);
    }

    /**
     * @param int    $change_set_id
     * @param string $status
     * @param string $error_message
     * @return void
     */
    public function finishBatch($change_set_id, $status = 'completed', $error_message = '')
    {
        $this->store->finishChangeSet($change_set_id, $status, 0, $error_message);
    }

    /**
     * @param int                $change_set_id
     * @param EditableDescriptor $descriptor
     * @param string             $resolver_name
     * @param mixed              $old_value
     * @param mixed              $attempted_value
     * @param string             $error_message
     * @param array<string, mixed> $save_context
     * @return void
     */
    public function recordFailure($change_set_id, EditableDescriptor $descriptor, $resolver_name, $old_value, $attempted_value, $error_message, array $save_context = [])
    {
        $this->recordItem($change_set_id, $descriptor, $resolver_name, $old_value, $attempted_value, $old_value, 'failed', $error_message, $save_context);
        $this->store->finishChangeSet($change_set_id, 'failed', 0, $error_message);
    }

    /**
     * @param int                $change_set_id
     * @param EditableDescriptor $descriptor
     * @param string             $resolver_name
     * @param mixed              $old_value
     * @param mixed              $new_value
     * @param mixed              $rollback_value
     * @param string             $result_status
     * @param string             $error_message
     * @param array<string, mixed> $save_context
     * @return void
     */
    private function recordItem($change_set_id, EditableDescriptor $descriptor, $resolver_name, $old_value, $new_value, $rollback_value, $result_status, $error_message, array $save_context = [])
    {
        $change_set_id = absint($change_set_id);
        if ($change_set_id <= 0) {
            return;
        }

        $source = isset($descriptor->source) && is_array($descriptor->source) ? $descriptor->source : [];

        $this->store->recordChangeItem(
            [
                'change_set_id' => $change_set_id,
                'resolver_name' => sanitize_key((string) $resolver_name),
                'field_type' => isset($source['field_type']) ? (string) $source['field_type'] : '',
                'field_name' => isset($source['field_name']) ? (string) $source['field_name'] : '',
                'field_key' => isset($source['field_key']) ? (string) $source['field_key'] : '',
                'field_path' => $this->resolvePathContext($descriptor),
                'old_value' => $old_value,
                'new_value' => $new_value,
                'rollback_value' => $rollback_value,
                'result_status' => $result_status,
                'error_message' => $error_message,
                'context' => [
                    'descriptorVersion' => $this->resolveDescriptorVersion($descriptor),
                    'scope' => isset($descriptor->scope) ? (string) $descriptor->scope : 'current_entity',
                    'status' => isset($descriptor->status) ? (string) $descriptor->status : '',
                    'page' => $this->resolvePageContext($descriptor),
                    'owner' => $this->resolveOwnerContext($descriptor),
                    'loop' => $this->resolveLoopContext($descriptor),
                    'path' => $this->resolvePathContext($descriptor),
                    'mutation' => $this->resolveMutationContext($descriptor),
                    'source' => $source,
                    'render' => isset($descriptor->render) && is_array($descriptor->render) ? $descriptor->render : [],
                    'save' => $save_context,
                ],
            ]
        );
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return int
     */
    private function resolveDescriptorVersion(EditableDescriptor $descriptor)
    {
        return isset($descriptor->mutation['version']) ? absint($descriptor->mutation['version']) : 1;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<string, mixed>
     */
    private function resolvePageContext(EditableDescriptor $descriptor)
    {
        return isset($descriptor->page) && is_array($descriptor->page) && ! empty($descriptor->page)
            ? $descriptor->page
            : [];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<string, mixed>
     */
    private function resolveOwnerContext(EditableDescriptor $descriptor)
    {
        if (isset($descriptor->owner) && is_array($descriptor->owner) && ! empty($descriptor->owner)) {
            return $descriptor->owner;
        }

        return isset($descriptor->entity) && is_array($descriptor->entity) ? $descriptor->entity : [];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<string, mixed>
     */
    private function resolveLoopContext(EditableDescriptor $descriptor)
    {
        if (isset($descriptor->loop) && is_array($descriptor->loop) && ! empty($descriptor->loop)) {
            return $descriptor->loop;
        }

        return isset($descriptor->render['loop']) && is_array($descriptor->render['loop'])
            ? $descriptor->render['loop']
            : [];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<string, mixed>
     */
    private function resolvePathContext(EditableDescriptor $descriptor)
    {
        return isset($descriptor->path) && is_array($descriptor->path)
            ? $descriptor->path
            : [];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<string, mixed>
     */
    private function resolveMutationContext(EditableDescriptor $descriptor)
    {
        return isset($descriptor->mutation) && is_array($descriptor->mutation)
            ? $descriptor->mutation
            : [];
    }
}
