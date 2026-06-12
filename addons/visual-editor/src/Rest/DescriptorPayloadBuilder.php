<?php

namespace Dbvc\VisualEditor\Rest;

use Dbvc\VisualEditor\Performance\PerformanceProfiler;
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

    /**
     * @var PerformanceProfiler|null
     */
    private $profiler;

    public function __construct(ResolverRegistry $resolvers, CapabilityManager $capabilities, DescriptorSummaryBuilder $summaries, MutationContractService $contracts, ?PerformanceProfiler $profiler = null)
    {
        $this->resolvers = $resolvers;
        $this->capabilities = $capabilities;
        $this->summaries = $summaries;
        $this->contracts = $contracts;
        $this->profiler = $profiler;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<string, mixed>
     */
    public function build(EditableDescriptor $descriptor)
    {
        if ($this->profiler instanceof PerformanceProfiler && $this->profiler->isEnabled()) {
            $started_at = $this->profiler->startTimer();
            $payload = $this->buildUnprofiled($descriptor);
            $this->profiler->recordDuration('rest.descriptor_payload.build', $started_at, [
                'status' => isset($descriptor->status) ? (string) $descriptor->status : 'unknown',
                'scope' => isset($descriptor->scope) ? (string) $descriptor->scope : 'unknown',
                'resolver' => isset($descriptor->resolver['name']) ? (string) $descriptor->resolver['name'] : 'unknown',
                'input' => isset($descriptor->ui['input']) ? (string) $descriptor->ui['input'] : 'unknown',
            ]);

            return $payload;
        }

        return $this->buildUnprofiled($descriptor);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<string, mixed>
     */
    private function buildUnprofiled(EditableDescriptor $descriptor)
    {
        $resolver = $this->resolvers->resolve($descriptor);
        $current_value = $resolver->getValue($descriptor);
        $entity_summary = $this->summaries->buildEntitySummary($descriptor);
        $source_summary = $this->summaries->buildSourceSummary($descriptor);
        $contract_summary = $this->contracts->buildSummary($descriptor);
        $can_edit = $descriptor->status === 'editable'
            && ! empty($contract_summary['writable'])
            && $this->capabilities->canEditDescriptor($descriptor);

        $payload = [
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

        if ((isset($descriptor->source['type']) ? sanitize_key((string) $descriptor->source['type']) : '') === 'composite_text') {
            $payload['compositeText'] = $this->buildCompositeTextPayload($descriptor);
        }

        return $payload;
    }

    /**
     * @param array<string, EditableDescriptor> $descriptors
     * @return array<string, array<string, mixed>>
     */
    public function buildMany(array $descriptors)
    {
        $started_at = $this->profiler instanceof PerformanceProfiler && $this->profiler->isEnabled()
            ? $this->profiler->startTimer()
            : 0.0;
        $payloads = [];

        foreach ($descriptors as $token => $descriptor) {
            if (! $descriptor instanceof EditableDescriptor) {
                continue;
            }

            $payloads[sanitize_key((string) $token)] = $this->build($descriptor);
        }

        if ($this->profiler instanceof PerformanceProfiler && $started_at > 0) {
            $this->profiler->recordDuration('rest.descriptor_payload.build_many', $started_at);
            $this->profiler->recordValue('rest.descriptor_payload.count', count($payloads));
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
        $source_type = isset($descriptor->source['type']) ? sanitize_key((string) $descriptor->source['type']) : '';

        if ($source_type === 'composite_text') {
            return __('This mixed Bricks text element contains multiple dynamic sources. It is inspect-only until Visual Editor has a rollback-safe batch save contract for composite fields.', 'dbvc');
        }

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
     * @param EditableDescriptor $descriptor
     * @return array<string, mixed>
     */
    private function buildCompositeTextPayload(EditableDescriptor $descriptor)
    {
        $source = isset($descriptor->source) && is_array($descriptor->source) ? $descriptor->source : [];
        $children = isset($source['children']) && is_array($source['children']) ? array_values($source['children']) : [];
        $segments = isset($source['segments']) && is_array($source['segments']) ? array_values($source['segments']) : [];
        $child_payloads = [];
        $display_by_index = [];

        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }

            $index = isset($child['index']) ? absint($child['index']) : count($child_payloads);
            $payload = [
                'index' => $index,
                'expression' => isset($child['expression']) ? sanitize_text_field((string) $child['expression']) : '',
                'supported' => ! empty($child['supported']),
                'status' => isset($child['status']) ? sanitize_key((string) $child['status']) : 'unsupported',
                'scope' => isset($child['scope']) ? sanitize_key((string) $child['scope']) : 'current_entity',
                'label' => isset($child['label']) ? sanitize_text_field((string) $child['label']) : __('Field', 'dbvc'),
                'input' => isset($child['input']) ? sanitize_key((string) $child['input']) : 'readonly_preview',
                'warning' => isset($child['warning']) ? sanitize_text_field((string) $child['warning']) : '',
                'entity' => isset($child['entity']) && is_array($child['entity']) ? $child['entity'] : [],
                'source' => isset($child['source']) && is_array($child['source']) ? $child['source'] : [],
                'currentValue' => '',
                'displayValue' => '',
                'displayMode' => 'text',
                'sourceSummary' => [],
                'entitySummary' => [],
                'canEdit' => false,
                'saveReady' => false,
                'blockReason' => __('This dynamic tag is locked because it does not resolve to a Visual Editor save contract.', 'dbvc'),
            ];

            if (! empty($child['descriptor']) && is_array($child['descriptor'])) {
                $child_descriptor = EditableDescriptor::fromArray($child['descriptor']);
                $child_resolver = $this->resolvers->resolve($child_descriptor);
                $child_current_value = $child_resolver->getValue($child_descriptor);
                $child_display_value = $child_resolver->getDisplayValue($child_descriptor, $child_current_value);
                $child_contract_summary = $this->contracts->buildSummary($child_descriptor);

                $payload['descriptor'] = $child_descriptor->toArray();
                $payload['currentValue'] = $child_current_value;
                $payload['displayValue'] = $child_display_value;
                $payload['displayMode'] = $child_resolver->getDisplayMode($child_descriptor);
                $payload['sourceSummary'] = $this->summaries->buildSourceSummary($child_descriptor);
                $payload['entitySummary'] = $this->summaries->buildEntitySummary($child_descriptor);
                $payload['saveContractSummary'] = $child_contract_summary;
                $payload['canEdit'] = $child_descriptor->status === 'editable'
                    && ! empty($child_contract_summary['writable'])
                    && $this->capabilities->canEditDescriptor($child_descriptor);
                $payload['saveReady'] = $payload['canEdit'];
                $payload['blockReason'] = $this->buildCompositeChildBlockReason($child_descriptor, $child_contract_summary, $payload['canEdit']);
                $display_by_index[$index] = $this->stringifyCompositeDisplayValue($child_display_value);
            } elseif ($payload['expression'] !== '') {
                $payload['currentValue'] = $payload['expression'];
                $payload['displayValue'] = $payload['expression'];
                $display_by_index[$index] = $payload['expression'];
            }

            $child_payloads[] = $payload;
        }

        return [
            'template' => isset($source['template']) ? wp_kses_post((string) $source['template']) : '',
            'segments' => $segments,
            'children' => $child_payloads,
            'previewText' => $this->buildCompositePreviewText($segments, $display_by_index),
            'dynamicCount' => isset($source['dynamic_count']) ? absint($source['dynamic_count']) : count($children),
            'supportedChildCount' => isset($source['supported_child_count']) ? absint($source['supported_child_count']) : count($child_payloads),
            'unsupportedChildCount' => isset($source['unsupported_child_count']) ? absint($source['unsupported_child_count']) : 0,
            'saveReadiness' => $this->buildCompositeSaveReadiness($child_payloads),
        ];
    }

    /**
     * @param EditableDescriptor    $descriptor
     * @param array<string, mixed>  $contract_summary
     * @param bool                  $can_edit
     * @return string
     */
    private function buildCompositeChildBlockReason(EditableDescriptor $descriptor, array $contract_summary, $can_edit)
    {
        if ($can_edit) {
            return '';
        }

        if ($descriptor->status !== 'editable') {
            return __('This child source is inspect-only in the current Visual Editor contract.', 'dbvc');
        }

        if (empty($contract_summary['writable'])) {
            return $this->contracts->getUnsupportedMessage($descriptor);
        }

        return __('Your account cannot save this child source from the Visual Editor.', 'dbvc');
    }

    /**
     * @param array<int, array<string, mixed>> $children
     * @return array<string, mixed>
     */
    private function buildCompositeSaveReadiness(array $children)
    {
        $dynamic_count = count($children);
        $ready_children = 0;
        $blocked_children = 0;
        $unsupported_children = 0;
        $acknowledgement_types = [];
        $owner_groups = [];
        $blockers = [];

        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }

            $status = isset($child['status']) ? sanitize_key((string) $child['status']) : 'unsupported';
            $save_ready = ! empty($child['saveReady']);
            $reason = isset($child['blockReason']) ? sanitize_text_field((string) $child['blockReason']) : '';

            if ($save_ready) {
                $ready_children++;
            } else {
                $blocked_children++;
                if ($reason !== '') {
                    $blockers[] = $reason;
                }
            }

            if ($status === 'unsupported' || empty($child['descriptor'])) {
                $unsupported_children++;
            }

            $contract = isset($child['saveContractSummary']) && is_array($child['saveContractSummary'])
                ? $child['saveContractSummary']
                : [];
            $ack_type = isset($contract['acknowledgementType']) ? sanitize_key((string) $contract['acknowledgementType']) : 'none';
            if (! empty($contract['requiresAcknowledgement']) && $ack_type !== '' && $ack_type !== 'none') {
                $acknowledgement_types[$ack_type] = true;
            }

            $entity = isset($child['entitySummary']) && is_array($child['entitySummary'])
                ? $child['entitySummary']
                : (isset($child['entity']) && is_array($child['entity']) ? $child['entity'] : []);
            $scope = isset($child['scope']) ? sanitize_key((string) $child['scope']) : 'current_entity';
            $entity_type = isset($entity['type']) ? sanitize_key((string) $entity['type']) : '';
            $entity_id = isset($entity['id']) ? absint($entity['id']) : 0;
            $entity_subtype = isset($entity['subtype']) ? sanitize_key((string) $entity['subtype']) : '';
            $owner_key = implode(':', [$scope, $entity_type, $entity_subtype, $entity_id]);

            if (! isset($owner_groups[$owner_key])) {
                $owner_groups[$owner_key] = [
                    'scope' => $scope,
                    'type' => $entity_type,
                    'id' => $entity_id,
                    'subtype' => $entity_subtype,
                    'title' => isset($entity['title']) ? sanitize_text_field((string) $entity['title']) : '',
                    'count' => 0,
                ];
            }

            $owner_groups[$owner_key]['count']++;
        }

        $blockers = array_values(array_unique(array_filter($blockers)));
        $child_contracts_ready = $dynamic_count > 0 && $ready_children === $dynamic_count;
        $batch_inputs_ready = $child_contracts_ready && $unsupported_children === 0 && $this->areCompositeBatchInputsAllowed($children);
        $status = $batch_inputs_ready ? 'ready' : ($child_contracts_ready ? 'ready_pending_ui' : 'blocked');

        return [
            'status' => $status,
            'canBatchSave' => $batch_inputs_ready,
            'childContractsReady' => $child_contracts_ready,
            'readyChildCount' => $ready_children,
            'blockedChildCount' => $blocked_children,
            'unsupportedChildCount' => $unsupported_children,
            'dynamicCount' => $dynamic_count,
            'requiresAcknowledgementTypes' => array_values(array_keys($acknowledgement_types)),
            'ownerGroups' => array_values($owner_groups),
            'blockers' => $blockers,
            'message' => $batch_inputs_ready
                ? __('All child sources have scalar save contracts. Review the owner acknowledgement before saving this mixed text element.', 'dbvc')
                : ($child_contracts_ready
                    ? __('All child sources have save contracts, but one or more controls need a dedicated composite editor before batch save is enabled.', 'dbvc')
                    : __('Composite batch save is blocked until every child source has a writable Visual Editor save contract.', 'dbvc')),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $children
     * @return bool
     */
    private function areCompositeBatchInputsAllowed(array $children)
    {
        foreach ($children as $child) {
            if (! is_array($child)) {
                return false;
            }

            if (empty($child['saveReady']) || empty($child['descriptor']) || ! is_array($child['descriptor'])) {
                return false;
            }

            $input = isset($child['input']) ? sanitize_key((string) $child['input']) : '';
            if (in_array($input, ['text', 'textarea', 'number', 'url', 'email'], true)) {
                continue;
            }

            if ($input === 'select') {
                $descriptor = EditableDescriptor::fromArray($child['descriptor']);
                if (empty($descriptor->ui['allowMultiple'])) {
                    continue;
                }
            }

            return false;
        }

        return ! empty($children);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function stringifyCompositeDisplayValue($value)
    {
        if (is_array($value) || is_object($value)) {
            $json = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return is_string($json) ? $json : '';
        }

        return is_scalar($value) || $value === null ? (string) $value : '';
    }

    /**
     * @param array<int, mixed> $segments
     * @param array<int, string> $display_by_index
     * @return string
     */
    private function buildCompositePreviewText(array $segments, array $display_by_index)
    {
        $preview = '';

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $type = isset($segment['type']) ? sanitize_key((string) $segment['type']) : '';
            if ($type === 'literal') {
                $preview .= isset($segment['text']) ? (string) $segment['text'] : '';
                continue;
            }

            if ($type === 'dynamic') {
                $index = isset($segment['index']) ? absint($segment['index']) : null;
                $preview .= $index !== null && isset($display_by_index[$index])
                    ? $display_by_index[$index]
                    : (isset($segment['expression']) ? (string) $segment['expression'] : '');
            }
        }

        $preview = preg_replace('/<br\s*\/?>/i', "\n", $preview);
        $preview = wp_strip_all_tags(is_string($preview) ? $preview : '', true);
        $preview = html_entity_decode((string) $preview, ENT_QUOTES, 'UTF-8');

        return trim($preview);
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
