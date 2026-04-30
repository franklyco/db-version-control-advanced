<?php

namespace Dbvc\VisualEditor\Rest;

use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Registry\EditableDescriptor;
use Dbvc\VisualEditor\Resolvers\ResolverRegistry;

final class DescriptorPayloadBuilder
{
    /**
     * @var ResolverRegistry
     */
    private $resolvers;

    /**
     * @var CapabilityManager
     */
    private $capabilities;

    public function __construct(ResolverRegistry $resolvers, CapabilityManager $capabilities)
    {
        $this->resolvers = $resolvers;
        $this->capabilities = $capabilities;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<string, mixed>
     */
    public function build(EditableDescriptor $descriptor)
    {
        $resolver = $this->resolvers->resolve($descriptor);
        $current_value = $resolver->getValue($descriptor);
        $can_edit = $descriptor->status === 'editable' && $this->capabilities->canEditDescriptor($descriptor);

        return [
            'descriptor' => $descriptor->toArray(),
            'currentValue' => $current_value,
            'displayValue' => $resolver->getDisplayValue($descriptor, $current_value),
            'displayMode' => $resolver->getDisplayMode($descriptor),
            'canEdit' => $can_edit,
            'requiresSharedScopeAck' => $can_edit && $descriptor->scope !== 'current_entity',
            'acknowledgementType' => $this->resolveAcknowledgementType($descriptor),
            'editMessage' => $this->buildEditMessage($descriptor, $can_edit),
        ];
    }

    /**
     * @param array<string, EditableDescriptor> $descriptors
     * @return array<string, array<string, mixed>>
     */
    public function buildMany(array $descriptors)
    {
        $payloads = [];

        foreach ($descriptors as $token => $descriptor) {
            if (! $descriptor instanceof EditableDescriptor) {
                continue;
            }

            $payloads[sanitize_key((string) $token)] = $this->build($descriptor);
        }

        return $payloads;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param bool               $can_edit
     * @return string
     */
    private function buildEditMessage(EditableDescriptor $descriptor, $can_edit)
    {
        if ($can_edit) {
            return '';
        }

        $entity_type = isset($descriptor->entity['type']) ? (string) $descriptor->entity['type'] : '';
        $scope = isset($descriptor->scope) ? (string) $descriptor->scope : 'current_entity';
        $status = isset($descriptor->status) ? (string) $descriptor->status : 'unsupported';

        if ($status === 'readonly') {
            if ($scope === 'related_entity') {
                return __('This field belongs to a non-current post rendered by a Bricks query loop. It is surfaced here for inspection only until that loop-owned source has a dedicated save contract.', 'dbvc');
            }

            if ($entity_type === 'option') {
                return __('This field is surfaced here for inspection only. Edit the underlying Site Settings value from its canonical options screen.', 'dbvc');
            }

            return __('This field is surfaced here for inspection only. Editing for this field type or source context is not enabled yet.', 'dbvc');
        }

        if ($scope === 'related_entity') {
            return __('This field belongs to the related post currently rendered by a Bricks query loop. You can inspect it here, but your account cannot edit that related post.', 'dbvc');
        }

        if ($entity_type === 'option') {
            return __('This shared options value can be inspected here, but saving it requires a higher capability.', 'dbvc');
        }

        if ($entity_type === 'term') {
            return __('This shared taxonomy field can be inspected here, but saving it requires term-edit capability.', 'dbvc');
        }

        if ($entity_type === 'user') {
            return __('This shared user field can be inspected here, but saving it requires user-edit capability.', 'dbvc');
        }

        return __('This field can be inspected here, but your account cannot save it.', 'dbvc');
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    private function resolveAcknowledgementType(EditableDescriptor $descriptor)
    {
        $scope = isset($descriptor->scope) ? (string) $descriptor->scope : 'current_entity';

        if ($scope === 'related_entity') {
            return 'related';
        }

        if ($scope === 'shared_entity') {
            return 'shared';
        }

        return 'none';
    }
}
