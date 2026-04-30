<?php

namespace Dbvc\VisualEditor\Rest\Controllers;

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Registry\EditableRegistry;
use Dbvc\VisualEditor\Save\MutationContractService;
use Dbvc\VisualEditor\Save\MutationService;
use WP_REST_Request;
use WP_REST_Response;

final class SaveController
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

    public function __construct(
        EditableRegistry $registry,
        MutationService $mutations,
        EditModeState $edit_mode,
        CapabilityManager $capabilities,
        MutationContractService $contracts
    ) {
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
            '/visual-editor/session/(?P<session_id>[A-Za-z0-9_-]+)/save',
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
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'message' => __('Invalid request.', 'dbvc'),
                ],
                400
            );
        }

        if (! $this->edit_mode->isRestRequestAuthorized()) {
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'message' => __('Visual Editor mode is not active.', 'dbvc'),
                ],
                403
            );
        }

        $session_id = sanitize_key((string) $request['session_id']);
        $token = sanitize_key((string) $request->get_param('token'));
        $value = $request->get_param('value');
        $descriptor = $this->registry->getDescriptorFromSession($session_id, $token);

        if (! $descriptor) {
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'message' => __('Descriptor not found.', 'dbvc'),
                ],
                404
            );
        }

        if (! $this->capabilities->canEditDescriptor($descriptor)) {
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'message' => __('You cannot edit this descriptor.', 'dbvc'),
                ],
                403
            );
        }

        $contract_summary = $this->contracts->buildSummary($descriptor);

        if (empty($contract_summary['writable'])) {
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'message' => $this->contracts->getUnsupportedMessage($descriptor),
                ],
                400
            );
        }

        if (! empty($contract_summary['requiresAcknowledgement']) && ! $this->hasSharedScopeAcknowledgement($request)) {
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'message' => $this->contracts->getAcknowledgementMessage($descriptor),
                ],
                400
            );
        }

        $result = $this->mutations->mutate($descriptor, $value);
        $result['saveContractSummary'] = $contract_summary;

        return new WP_REST_Response($result, ! empty($result['ok']) ? 200 : 400);
    }

    /**
     * @param WP_REST_Request $request
     * @return bool
     */
    private function hasSharedScopeAcknowledgement(WP_REST_Request $request)
    {
        $value = $request->get_param('acknowledgeSharedScope');

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
}
