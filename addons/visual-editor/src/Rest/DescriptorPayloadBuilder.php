<?php

namespace Dbvc\VisualEditor\Rest;

use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Presentation\DescriptorSummaryBuilder;
use Dbvc\VisualEditor\Registry\EditableDescriptor;
use Dbvc\VisualEditor\Resolvers\ResolverRegistry;
use Dbvc\VisualEditor\Save\MutationContractService;

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

    /**
     * @var DescriptorSummaryBuilder
     */
    private $summaries;

    /**
     * @var MutationContractService
     */
    private $contracts;

    public function __construct(ResolverRegistry $resolvers, CapabilityManager $capabilities, DescriptorSummaryBuilder $summaries, MutationContractService $contracts)
    {
        $this->resolvers = $resolvers;
        $this->capabilities = $capabilities;
        $this->summaries = $summaries;
        $this->contracts = $contracts;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<string, mixed>
     */
    public function build(EditableDescriptor $descriptor)
    {
        $resolver = $this->resolvers->resolve($descriptor);
        $current_value = $resolver->getValue($descriptor);
        $entity_summary = $this->summaries->buildEntitySummary($descriptor);
        $source_summary = $this->summaries->buildSourceSummary($descriptor);
        $contract_summary = $this->contracts->buildSummary($descriptor);
        $can_edit = $descriptor->status === 'editable'
            && ! empty($contract_summary['writable'])
            && $this->capabilities->canEditDescriptor($descriptor);

        return [
            'descriptorVersion' => isset($descriptor->mutation['version']) ? absint($descriptor->mutation['version']) : 1,
            'descriptor' => $descriptor->toArray(),
            'currentValue' => $current_value,
            'displayValue' => $resolver->getDisplayValue($descriptor, $current_value),
            'displayMode' => $resolver->getDisplayMode($descriptor),
            'canEdit' => $can_edit,
            'pageContext' => isset($descriptor->page) && is_array($descriptor->page) ? $descriptor->page : [],
            'ownerContext' => isset($descriptor->owner) && is_array($descriptor->owner) ? $descriptor->owner : [],
            'loopContext' => isset($descriptor->loop) && is_array($descriptor->loop) ? $descriptor->loop : [],
            'pathContext' => isset($descriptor->path) && is_array($descriptor->path) ? $descriptor->path : [],
            'mutationContract' => isset($descriptor->mutation) && is_array($descriptor->mutation) ? $descriptor->mutation : [],
            'saveContractSummary' => $contract_summary,
            'requiresSharedScopeAck' => $can_edit && ! empty($contract_summary['requiresAcknowledgement']),
            'acknowledgementType' => isset($contract_summary['acknowledgementType']) ? (string) $contract_summary['acknowledgementType'] : 'none',
            'editMessage' => $this->buildEditMessage($descriptor, $can_edit),
            'entitySummary' => $entity_summary,
            'sourceSummary' => $source_summary,
            'noticeSummary' => $this->summaries->buildNoticeSummary($descriptor, $can_edit, $entity_summary, $source_summary),
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
                return $this->buildReadonlyRelatedMessage($entity_type);
            }

            if ($entity_type === 'option') {
                return __('This field is surfaced here for inspection only. Edit the underlying Site Settings value from its canonical options screen.', 'dbvc');
            }

            if ($scope === 'shared_entity') {
                return $this->buildReadonlySharedMessage($entity_type);
            }

            return __('This field is surfaced here for inspection only. Editing for this field type or source context is not enabled yet.', 'dbvc');
        }

        if ($scope === 'related_entity') {
            return $this->buildLockedRelatedMessage($entity_type);
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
     * @param string $entity_type
     * @return string
     */
    private function buildReadonlyRelatedMessage($entity_type)
    {
        if ($entity_type === 'term') {
            return __('This field belongs to a non-current taxonomy term rendered by a Bricks query loop. It is surfaced here for inspection only until that loop-owned term source has a dedicated save contract.', 'dbvc');
        }

        if ($entity_type === 'user') {
            return __('This field belongs to a non-current user source rendered by a Bricks query loop. It is surfaced here for inspection only until that loop-owned user source has a dedicated save contract.', 'dbvc');
        }

        if ($entity_type === 'option') {
            return __('This field belongs to a non-current options source rendered by a Bricks query loop. It is surfaced here for inspection only until that loop-owned options source has a dedicated save contract.', 'dbvc');
        }

        return __('This field belongs to a non-current post rendered by a Bricks query loop. It is surfaced here for inspection only until that loop-owned source has a dedicated save contract.', 'dbvc');
    }

    /**
     * @param string $entity_type
     * @return string
     */
    private function buildReadonlySharedMessage($entity_type)
    {
        if ($entity_type === 'term') {
            return __('This shared taxonomy term field is surfaced here for inspection only. Editing for this term-backed source context is not enabled yet.', 'dbvc');
        }

        if ($entity_type === 'user') {
            return __('This shared user field is surfaced here for inspection only. Editing for this user-backed source context is not enabled yet.', 'dbvc');
        }

        if ($entity_type === 'post') {
            return __('This shared post-owned field is surfaced here for inspection only. Editing for this non-current post source context is not enabled yet.', 'dbvc');
        }

        return __('This field is surfaced here for inspection only. Editing for this field type or source context is not enabled yet.', 'dbvc');
    }

    /**
     * @param string $entity_type
     * @return string
     */
    private function buildLockedRelatedMessage($entity_type)
    {
        if ($entity_type === 'term') {
            return __('This field belongs to the related term currently rendered by a Bricks query loop. You can inspect it here, but your account cannot edit that related term.', 'dbvc');
        }

        if ($entity_type === 'user') {
            return __('This field belongs to the related user currently rendered by a Bricks query loop. You can inspect it here, but your account cannot edit that related user.', 'dbvc');
        }

        if ($entity_type === 'option') {
            return __('This field belongs to the related options source currently rendered by a Bricks query loop. You can inspect it here, but your account cannot edit that related source.', 'dbvc');
        }

        return __('This field belongs to the related post currently rendered by a Bricks query loop. You can inspect it here, but your account cannot edit that related post.', 'dbvc');
    }

}
