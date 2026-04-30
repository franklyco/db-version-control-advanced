<?php

namespace Dbvc\VisualEditor\Save;

use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class MutationContractService
{
    /**
     * @param EditableDescriptor $descriptor
     * @return array<string, mixed>
     */
    public function buildSummary(EditableDescriptor $descriptor)
    {
        $contract = $this->resolveContractName($descriptor);
        $kind = $this->resolveMutationString($descriptor, 'kind', 'scalar');
        $target = $this->resolveMutationString($descriptor, 'target', 'field');
        $render_context = $this->resolveMutationString($descriptor, 'renderContext', '');
        $requires_ack = $this->requiresAcknowledgement($descriptor);
        $ack_type = $this->resolveAcknowledgementType($descriptor);
        $writable = $this->isWritable($descriptor);

        return [
            'name' => $contract,
            'kind' => $kind,
            'target' => $target,
            'renderContext' => $render_context,
            'writable' => $writable,
            'requiresAcknowledgement' => $requires_ack,
            'acknowledgementType' => $ack_type,
            'label' => $this->resolveContractLabel($contract),
            'detail' => $this->resolveContractDetail($contract, $kind, $target, $render_context),
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    public function isWritable(EditableDescriptor $descriptor)
    {
        if (($descriptor->status ?? '') !== 'editable') {
            return false;
        }

        return in_array(
            $this->resolveContractName($descriptor),
            [
                'direct_field',
                'shared_field',
                'repeater_row',
                'flexible_layout',
                'loop_owned_field',
                'loop_owned_repeater_row',
                'loop_owned_flexible_layout',
            ],
            true
        );
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    public function requiresAcknowledgement(EditableDescriptor $descriptor)
    {
        return $this->resolveAcknowledgementType($descriptor) !== 'none';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    public function resolveAcknowledgementType(EditableDescriptor $descriptor)
    {
        $contract = $this->resolveContractName($descriptor);

        if (strpos($contract, 'loop_owned_') === 0) {
            return 'related';
        }

        if (strpos($contract, 'shared_') === 0) {
            return 'shared';
        }

        return 'none';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    public function getAcknowledgementMessage(EditableDescriptor $descriptor)
    {
        $contract = $this->resolveContractName($descriptor);

        if ($contract === 'loop_owned_repeater_row') {
            return __('This loop-owned repeater row requires explicit acknowledgement before it can be saved.', 'dbvc');
        }

        if ($contract === 'loop_owned_field') {
            return __('This loop-owned field requires explicit acknowledgement before it can be saved.', 'dbvc');
        }

        if ($contract === 'shared_repeater_row') {
            return __('This shared repeater row requires explicit acknowledgement before it can be saved.', 'dbvc');
        }

        if (strpos($contract, 'shared_') === 0) {
            return __('This shared field requires explicit acknowledgement before it can be saved.', 'dbvc');
        }

        if (strpos($contract, 'loop_owned_') === 0) {
            return __('This loop-owned field requires explicit acknowledgement before it can be saved.', 'dbvc');
        }

        return __('This field requires explicit acknowledgement before it can be saved.', 'dbvc');
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    public function getUnsupportedMessage(EditableDescriptor $descriptor)
    {
        $summary = $this->buildSummary($descriptor);

        return sprintf(
            /* translators: %s: contract label */
            __('This save contract is not enabled yet: %s.', 'dbvc'),
            $summary['label']
        );
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    private function resolveContractName(EditableDescriptor $descriptor)
    {
        if (isset($descriptor->mutation['contract']) && is_string($descriptor->mutation['contract']) && $descriptor->mutation['contract'] !== '') {
            return sanitize_key($descriptor->mutation['contract']);
        }

        $scope = isset($descriptor->scope) ? (string) $descriptor->scope : 'current_entity';
        $container_type = isset($descriptor->source['container_type']) ? sanitize_key((string) $descriptor->source['container_type']) : '';
        $target = 'field';
        if ($container_type === 'repeater') {
            $target = 'row';
        } elseif ($container_type === 'flexible_content') {
            $target = 'layout';
        }

        if ($scope === 'related_entity') {
            if ($target === 'row') {
                return 'loop_owned_repeater_row';
            }

            if ($target === 'layout') {
                return 'loop_owned_flexible_layout';
            }

            return 'loop_owned_field';
        }

        if ($scope === 'shared_entity') {
            if ($target === 'row') {
                return 'shared_repeater_row';
            }

            if ($target === 'layout') {
                return 'shared_flexible_layout';
            }

            return 'shared_field';
        }

        if ($target === 'row') {
            return 'repeater_row';
        }

        if ($target === 'layout') {
            return 'flexible_layout';
        }

        return 'direct_field';
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param string             $key
     * @param string             $fallback
     * @return string
     */
    private function resolveMutationString(EditableDescriptor $descriptor, $key, $fallback)
    {
        if (isset($descriptor->mutation[$key]) && is_scalar($descriptor->mutation[$key])) {
            return sanitize_text_field((string) $descriptor->mutation[$key]);
        }

        return $fallback;
    }

    /**
     * @param string $contract
     * @return string
     */
    private function resolveContractLabel($contract)
    {
        switch ($contract) {
            case 'shared_field':
                return __('shared field', 'dbvc');
            case 'shared_repeater_row':
                return __('shared repeater row', 'dbvc');
            case 'repeater_row':
                return __('repeater row', 'dbvc');
            case 'flexible_layout':
                return __('flexible layout row', 'dbvc');
            case 'loop_owned_field':
                return __('loop-owned field', 'dbvc');
            case 'loop_owned_repeater_row':
                return __('loop-owned repeater row', 'dbvc');
            case 'loop_owned_flexible_layout':
                return __('loop-owned flexible layout row', 'dbvc');
            case 'shared_flexible_layout':
                return __('shared flexible layout row', 'dbvc');
            case 'direct_field':
            default:
                return __('direct field', 'dbvc');
        }
    }

    /**
     * @param string $contract
     * @param string $kind
     * @param string $target
     * @param string $render_context
     * @return string
     */
    private function resolveContractDetail($contract, $kind, $target, $render_context)
    {
        $parts = [$this->resolveContractLabel($contract)];

        if ($kind !== '') {
            $parts[] = $kind;
        }

        if ($target !== '') {
            $parts[] = $target;
        }

        if ($render_context !== '') {
            $parts[] = $render_context;
        }

        return implode(' / ', $parts);
    }
}
