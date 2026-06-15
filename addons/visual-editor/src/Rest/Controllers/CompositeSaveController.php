<?php

namespace Dbvc\VisualEditor\Rest\Controllers;

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Registry\EditableDescriptor;
use Dbvc\VisualEditor\Registry\EditableRegistry;
use Dbvc\VisualEditor\Save\MutationContractService;
use Dbvc\VisualEditor\Save\MutationService;
use WP_REST_Request;
use WP_REST_Response;

final class CompositeSaveController
{
    /**
     * @var EditableRegistry
     */
    private $registry;

    /**
     * @var MutationService
     */
    private $mutations;

    /**
     * @var EditModeState
     */
    private $edit_mode;

    /**
     * @var CapabilityManager
     */
    private $capabilities;

    /**
     * @var MutationContractService
     */
    private $contracts;

    public function __construct(EditableRegistry $registry, MutationService $mutations, EditModeState $edit_mode, CapabilityManager $capabilities, MutationContractService $contracts)
    {
        $this->registry = $registry;
        $this->mutations = $mutations;
        $this->edit_mode = $edit_mode;
        $this->capabilities = $capabilities;
        $this->contracts = $contracts;
    }

    /**
     * @return void
     */
    public function register()
    {
        register_rest_route(
            'dbvc/v1',
            '/visual-editor/session/(?P<session_id>[A-Za-z0-9_-]+)/composite-save/(?P<token>[A-Za-z0-9_-]+)',
            [
                'methods' => 'POST',
                'permission_callback' => [$this, 'canAccess'],
                'callback' => [$this, 'handle'],
            ]
        );
    }

    /**
     * @return bool
     */
    public function canAccess()
    {
        return $this->capabilities->canUseVisualEditor();
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle($request)
    {
        if (! ($request instanceof WP_REST_Request)) {
            return $this->error(__('Invalid request.', 'dbvc'), 400);
        }

        if (! $this->edit_mode->isRestRequestAuthorized()) {
            return $this->error(__('Visual Editor mode is not active.', 'dbvc'), 403);
        }

        $session_id = sanitize_key((string) $request['session_id']);
        $token = sanitize_key((string) $request['token']);
        $descriptor = $this->registry->getDescriptorFromSession($session_id, $token);

        if (! $descriptor) {
            return $this->error(__('Descriptor not found.', 'dbvc'), 404);
        }

        $source_type = isset($descriptor->source['type']) ? sanitize_key((string) $descriptor->source['type']) : '';
        $input = isset($descriptor->ui['input']) ? sanitize_key((string) $descriptor->ui['input']) : '';
        if ($source_type !== 'composite_text' || $input !== 'composite_text') {
            return $this->error(__('This descriptor is not a composite text field.', 'dbvc'), 400);
        }

        $values = $this->normalizeSubmittedValues($request->get_param('values'));
        $base_values = $this->normalizeSubmittedValues($request->get_param('baseValues'));
        $children = isset($descriptor->source['children']) && is_array($descriptor->source['children'])
            ? array_values($descriptor->source['children'])
            : [];
        $dynamic_count = isset($descriptor->source['dynamic_count']) ? absint($descriptor->source['dynamic_count']) : count($children);

        if (empty($children) || empty($values)) {
            return $this->error(__('Composite save requires values for every child field.', 'dbvc'), 400);
        }

        $items = [];
        $ack_types = [];

        foreach ($children as $child) {
            if (! is_array($child)) {
                return $this->error(__('Composite child metadata is invalid.', 'dbvc'), 400);
            }

            $index = isset($child['index']) ? absint($child['index']) : count($items);
            if (empty($child['descriptor']) || ! is_array($child['descriptor'])) {
                return $this->error(__('Composite save is blocked because one child tag is not writable.', 'dbvc'), 400);
            }

            if (! array_key_exists($index, $values)) {
                return $this->error(__('Composite save requires a submitted value for every child field.', 'dbvc'), 400);
            }

            $child_descriptor = EditableDescriptor::fromArray($child['descriptor']);
            $preflight = $this->preflightChildDescriptor($child_descriptor);
            if (empty($preflight['ok'])) {
                return $this->error(isset($preflight['message']) ? (string) $preflight['message'] : __('Composite child preflight failed.', 'dbvc'), 400);
            }

            $contract = isset($preflight['contract']) && is_array($preflight['contract']) ? $preflight['contract'] : [];
            $ack_type = isset($contract['acknowledgementType']) ? sanitize_key((string) $contract['acknowledgementType']) : 'none';
            if (! empty($contract['requiresAcknowledgement']) && $ack_type !== '' && $ack_type !== 'none') {
                $ack_types[$ack_type] = true;
            }

            $item = [
                'descriptor' => $child_descriptor,
                'value' => $values[$index],
            ];
            if (array_key_exists($index, $base_values)) {
                $item['expectedValue'] = $base_values[$index];
            }

            $items[] = $item;
        }

        if ($dynamic_count !== count($items)) {
            return $this->error(__('Composite save is blocked until every dynamic tag maps to a writable child field.', 'dbvc'), 400);
        }

        foreach (array_keys($ack_types) as $ack_type) {
            if (! $this->hasCompositeAcknowledgement($request, $ack_type)) {
                return $this->error($this->buildAcknowledgementMessage($ack_type), 400);
            }
        }

        $result = $this->mutations->mutateBatch(
            $descriptor,
            $items,
            [
                'type' => 'composite_text',
                'parentToken' => $descriptor->token,
                'childCount' => count($items),
                'acknowledgementTypes' => array_values(array_keys($ack_types)),
            ]
        );
        $result['compositeSave'] = [
            'parentToken' => $descriptor->token,
            'childCount' => count($items),
            'acknowledgementTypes' => array_values(array_keys($ack_types)),
        ];

        return new WP_REST_Response($result, $this->resolveResponseStatus($result));
    }

    /**
     * @param array<string,mixed> $result
     * @return int
     */
    private function resolveResponseStatus(array $result)
    {
        if (! empty($result['ok'])) {
            return 200;
        }

        return isset($result['stage']) && sanitize_key((string) $result['stage']) === 'stale' ? 409 : 400;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<string,mixed>
     */
    private function preflightChildDescriptor(EditableDescriptor $descriptor)
    {
        if ($descriptor->status !== 'editable') {
            return [
                'ok' => false,
                'message' => __('Composite save is blocked because one child field is inspect-only.', 'dbvc'),
            ];
        }

        $input = isset($descriptor->ui['input']) ? sanitize_key((string) $descriptor->ui['input']) : '';
        if (! $this->isAllowedScalarInput($descriptor, $input)) {
            return [
                'ok' => false,
                'message' => __('Composite save currently supports only scalar text, number, URL, email, and single-select child fields.', 'dbvc'),
            ];
        }

        $contract = $this->contracts->buildSummary($descriptor);
        if (empty($contract['writable'])) {
            return [
                'ok' => false,
                'message' => $this->contracts->getUnsupportedMessage($descriptor),
            ];
        }

        if (! $this->capabilities->canEditDescriptor($descriptor)) {
            return [
                'ok' => false,
                'message' => __('You cannot save one of the child fields in this composite text element.', 'dbvc'),
            ];
        }

        return [
            'ok' => true,
            'contract' => $contract,
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param string $input
     * @return bool
     */
    private function isAllowedScalarInput(EditableDescriptor $descriptor, $input)
    {
        if (in_array($input, ['text', 'textarea', 'number', 'url', 'email'], true)) {
            return true;
        }

        if ($input === 'select') {
            return empty($descriptor->ui['allowMultiple']);
        }

        return false;
    }

    /**
     * @param mixed $raw_values
     * @return array<int,mixed>
     */
    private function normalizeSubmittedValues($raw_values)
    {
        if (! is_array($raw_values)) {
            return [];
        }

        $values = [];
        foreach ($raw_values as $key => $item) {
            if (is_array($item) && array_key_exists('index', $item)) {
                $index = absint($item['index']);
                $values[$index] = array_key_exists('value', $item) ? $item['value'] : null;
                continue;
            }

            if (is_numeric($key)) {
                $values[absint($key)] = $item;
            }
        }

        return $values;
    }

    /**
     * @param WP_REST_Request $request
     * @param string $ack_type
     * @return bool
     */
    private function hasCompositeAcknowledgement(WP_REST_Request $request, $ack_type)
    {
        $ack_type = sanitize_key((string) $ack_type);
        $value = $request->get_param('acknowledgeCompositeScope');
        if ($this->truthy($value)) {
            return true;
        }

        $acks = $request->get_param('acknowledgements');
        if (! is_array($acks)) {
            return false;
        }

        return ! empty($acks[$ack_type]) || in_array($ack_type, array_map('sanitize_key', $acks), true);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function truthy($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @param string $ack_type
     * @return string
     */
    private function buildAcknowledgementMessage($ack_type)
    {
        if (sanitize_key((string) $ack_type) === 'related') {
            return __('Composite save requires acknowledgement because one or more child fields belong to related loop-owned content.', 'dbvc');
        }

        return __('Composite save requires acknowledgement because one or more child fields belong to shared content.', 'dbvc');
    }

    /**
     * @param string $message
     * @param int $status
     * @return WP_REST_Response
     */
    private function error($message, $status)
    {
        return new WP_REST_Response(
            [
                'ok' => false,
                'message' => $message,
            ],
            $status
        );
    }
}
