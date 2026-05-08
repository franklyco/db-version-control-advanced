<?php

namespace Dbvc\VisualEditor\Rest\Controllers;

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Registry\EditableRegistry;
use Dbvc\VisualEditor\Resolvers\ResolverRegistry;
use WP_REST_Request;
use WP_REST_Response;

final class ReferenceSearchController
{
    /**
     * @var EditableRegistry
     */
    private $registry;

    /**
     * @var ResolverRegistry
     */
    private $resolvers;

    /**
     * @var EditModeState
     */
    private $edit_mode;

    /**
     * @var CapabilityManager
     */
    private $capabilities;

    public function __construct(EditableRegistry $registry, ResolverRegistry $resolvers, EditModeState $edit_mode, CapabilityManager $capabilities)
    {
        $this->registry = $registry;
        $this->resolvers = $resolvers;
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
            '/visual-editor/session/(?P<session_id>[A-Za-z0-9_-]+)/reference-search/(?P<token>[A-Za-z0-9_-]+)',
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

        if (! $this->capabilities->canEditDescriptor($descriptor)) {
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'message' => __('You cannot inspect connected items for this descriptor.', 'dbvc'),
                ],
                403
            );
        }

        $resolver = $this->resolvers->resolve($descriptor);
        if (! is_object($resolver) || ! method_exists($resolver, 'searchItems')) {
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'message' => __('Connected-item search is not enabled for this descriptor.', 'dbvc'),
                ],
                400
            );
        }

        $search = sanitize_text_field((string) $request->get_param('search'));
        $limit = max(1, min(50, absint($request->get_param('limit')) ?: 20));
        $items = $resolver->searchItems($descriptor, $search, $limit);

        return new WP_REST_Response(
            [
                'ok' => true,
                'items' => is_array($items) ? $items : [],
            ]
        );
    }
}
