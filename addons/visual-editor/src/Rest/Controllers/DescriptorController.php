<?php

namespace Dbvc\VisualEditor\Rest\Controllers;

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Registry\EditableDescriptor;
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

        register_rest_route(
            'dbvc/v1',
            '/visual-editor/session/(?P<session_id>[A-Za-z0-9_-]+)/descriptors',
            [
                'methods' => 'POST',
                'permission_callback' => [$this, 'canAccess'],
                'callback' => [$this, 'handleBatch'],
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
        $session = $this->registry->loadSession($session_id);

        if (empty($session)) {
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'message' => __('Visual Editor session expired. Refresh the page to continue editing.', 'dbvc'),
                ],
                404
            );
        }

        $descriptors = isset($session['descriptors']) && is_array($session['descriptors']) ? $session['descriptors'] : [];
        $descriptor = ($token !== '' && isset($descriptors[$token]) && is_array($descriptors[$token]))
            ? EditableDescriptor::fromArray($descriptors[$token])
            : null;

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

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleBatch($request)
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
        $tokens = $this->getBatchTokens($request);

        if (empty($tokens)) {
            return new WP_REST_Response(
                [
                    'ok' => true,
                    'sessionId' => $session_id,
                    'descriptorHydrations' => [],
                    'missingTokens' => [],
                    'requested' => 0,
                    'found' => 0,
                ]
            );
        }

        $session = $this->registry->loadSession($session_id);

        if (empty($session)) {
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'message' => __('Visual Editor session expired. Refresh the page to continue editing.', 'dbvc'),
                ],
                404
            );
        }

        $descriptor_payloads = isset($session['descriptors']) && is_array($session['descriptors']) ? $session['descriptors'] : [];
        $descriptors = [];
        $missing_tokens = [];

        foreach ($tokens as $token) {
            if (! isset($descriptor_payloads[$token]) || ! is_array($descriptor_payloads[$token])) {
                $missing_tokens[] = $token;
                continue;
            }

            $descriptor = EditableDescriptor::fromArray($descriptor_payloads[$token]);
            if ($descriptor->token === '') {
                $missing_tokens[] = $token;
                continue;
            }

            $descriptors[$token] = $descriptor;
        }

        $hydrations = $this->payloads->buildMany($descriptors);
        foreach ($tokens as $token) {
            if (! isset($hydrations[$token]) && ! in_array($token, $missing_tokens, true)) {
                $missing_tokens[] = $token;
            }
        }

        return new WP_REST_Response(
            [
                'ok' => true,
                'sessionId' => $session_id,
                'descriptorHydrations' => $hydrations,
                'missingTokens' => $missing_tokens,
                'requested' => count($tokens),
                'found' => count($hydrations),
            ]
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return array<int, string>
     */
    private function getBatchTokens(WP_REST_Request $request)
    {
        $raw_tokens = $request->get_param('tokens');
        if (! is_array($raw_tokens)) {
            return [];
        }

        $tokens = [];
        $limit = $this->getBatchTokenLimit();
        foreach ($raw_tokens as $raw_token) {
            $token = sanitize_key((string) $raw_token);
            if ($token === '') {
                continue;
            }

            $tokens[$token] = true;
            if (count($tokens) >= $limit) {
                break;
            }
        }

        return array_keys($tokens);
    }

    /**
     * @return int
     */
    private function getBatchTokenLimit()
    {
        $limit = (int) apply_filters('dbvc_visual_editor_descriptor_batch_limit', 10);

        if ($limit < 1) {
            return 1;
        }

        if ($limit > 25) {
            return 25;
        }

        return $limit;
    }
}
