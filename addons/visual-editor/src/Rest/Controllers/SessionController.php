<?php

namespace Dbvc\VisualEditor\Rest\Controllers;

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Context\PageContextResolver;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Registry\EditableRegistry;
use Dbvc\VisualEditor\Rest\DescriptorPayloadBuilder;
use WP_REST_Request;
use WP_REST_Response;

final class SessionController
{
    /**
     * @var EditableRegistry
     */
    private $registry;

    /**
     * @var EditModeState
     */
    private $edit_mode;

    /**
     * @var PageContextResolver
     */
    private $page_context;

    /**
     * @var CapabilityManager
     */
    private $capabilities;

    /**
     * @var DescriptorPayloadBuilder
     */
    private $payloads;

    public function __construct(
        EditableRegistry $registry,
        EditModeState $edit_mode,
        PageContextResolver $page_context,
        CapabilityManager $capabilities,
        DescriptorPayloadBuilder $payloads
    ) {
        $this->registry = $registry;
        $this->edit_mode = $edit_mode;
        $this->page_context = $page_context;
        $this->capabilities = $capabilities;
        $this->payloads = $payloads;
    }

    /**
     * @return void
     */
    public function register()
    {
        register_rest_route(
            'dbvc/v1',
            '/visual-editor/session/(?P<session_id>[A-Za-z0-9_-]+)',
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
        $session = $this->registry->loadSession($session_id);

        if (empty($session)) {
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'message' => __('Visual Editor session not found.', 'dbvc'),
                    'active' => true,
                    'pageContext' => $this->page_context->resolve(),
                ],
                404
            );
        }

        $hydrate = $this->shouldHydrate($request);
        $hydrations = $hydrate
            ? $this->payloads->buildMany($this->registry->getDescriptorsFromSession($session_id))
            : [];

        return new WP_REST_Response(
            [
                'ok' => true,
                'active' => true,
                'sessionId' => $session_id,
                'pageContext' => isset($session['page_context']) && is_array($session['page_context'])
                    ? $session['page_context']
                    : $this->page_context->resolve(),
                'descriptors' => isset($session['public_map']) && is_array($session['public_map']) ? $session['public_map'] : [],
                'descriptorHydrations' => $hydrations,
            ]
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return bool
     */
    private function shouldHydrate(WP_REST_Request $request)
    {
        $value = $request->get_param('hydrate');

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
