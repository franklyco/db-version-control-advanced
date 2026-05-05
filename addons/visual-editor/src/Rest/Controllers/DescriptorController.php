<?php

namespace Dbvc\VisualEditor\Rest\Controllers;

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Registry\EditableRegistry;
use Dbvc\VisualEditor\Rest\DescriptorPayloadBuilder;
use WP_REST_Request;
use WP_REST_Response;

final class DescriptorController
{
    /**
     * @var EditableRegistry
     */
    private $registry;

    /**
     * @var ResolverRegistry
     */
    private $payloads;

    /**
     * @var EditModeState
     */
    private $edit_mode;

    /**
     * @var CapabilityManager
     */
    private $capabilities;

    public function __construct(
        EditableRegistry $registry,
        DescriptorPayloadBuilder $payloads,
        EditModeState $edit_mode,
        CapabilityManager $capabilities
    ) {
        $this->registry = $registry;
        $this->payloads = $payloads;
        $this->edit_mode = $edit_mode;
        $this->capabilities = $capabilities;
    }

    /**
     * @return void
     */
    public function register()
    {
        register_rest_route(
            'dbvc/v1',
            '/visual-editor/session/(?P<session_id>[A-Za-z0-9_-]+)/descriptor/(?P<token>[A-Za-z0-9_-]+)',
            [
                'methods' => 'GET',
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
        $token = sanitize_key((string) $request['token']);
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

        $payload = $this->payloads->build($descriptor);

        return new WP_REST_Response(
            array_merge(['ok' => true], $payload)
        );
    }
}
