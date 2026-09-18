<?php

namespace Dbvc\VisualEditor\Rest\Controllers;

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Registry\ControlRegistry;
use Dbvc\VisualEditor\Registry\EditableRegistry;
use WP_REST_Request;
use WP_REST_Response;

/**
 * R4-A — Brand Control Center batch value-summary route.
 *
 * `POST /dbvc/v1/visual-editor/session/{session_id}/control-center/value-summaries`
 * body: `{ "publicIds": ["<providerId>:<localId>", …] }` (max 50 per request)
 *
 * Returns `{ publicId → summary|null }` where `summary` is the provider's
 * per-family, display-only shape the drawer renders as the row's right-side
 * chip (mockup COMPONENT-NOTES §3). Fails soft per record — a null value
 * means "no summary today" (family without a factory, empty value, gated,
 * or the record no longer resolves); the drawer renders nothing in that
 * slot rather than removing the row.
 *
 * Auth model matches {@see ControlCenterOpenController}: WP REST nonce +
 * `CapabilityManager::canUseVisualEditor()` at `permission_callback`, plus
 * `EditModeState::isRestRequestAuthorized()` at the handler entry. Per
 * record, capability is re-checked against the resolved descriptor before
 * the provider's `buildValueSummary` runs — so a summary can never leak an
 * owned value the current user could not edit.
 *
 * Batch cap: 50 records. A larger request is 400. The drawer paginates
 * summaries in batches of 20 lazily as rows scroll into view; 50 is a
 * belt-and-braces upper bound.
 */
final class ControlCenterValueSummariesController
{
    /**
     * @var ControlRegistry
     */
    private $control_registry;

    /**
     * @var EditableRegistry
     */
    private $session_registry;

    /**
     * @var EditModeState
     */
    private $edit_mode;

    /**
     * @var CapabilityManager
     */
    private $capabilities;

    /**
     * @var int
     */
    private const BATCH_CAP = 50;

    public function __construct(
        ControlRegistry $control_registry,
        EditableRegistry $session_registry,
        EditModeState $edit_mode,
        CapabilityManager $capabilities
    ) {
        $this->control_registry = $control_registry;
        $this->session_registry = $session_registry;
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
            '/visual-editor/session/(?P<session_id>[A-Za-z0-9_-]+)/control-center/value-summaries',
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
        $session = $this->session_registry->loadSession($session_id, false);
        if (empty($session)) {
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'message' => __('Visual Editor session expired. Refresh the page to continue editing.', 'dbvc'),
                ],
                404
            );
        }

        $public_ids = $this->extractPublicIds($request);
        if ($public_ids === null) {
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'message' => sprintf(
                        /* translators: %d: max number of records the batch accepts. */
                        __('publicIds must be an array of at most %d strings.', 'dbvc'),
                        self::BATCH_CAP
                    ),
                ],
                400
            );
        }

        $page_context = isset($session['page_context']) && is_array($session['page_context']) ? $session['page_context'] : [];
        $summaries = [];
        foreach ($public_ids as $public_id) {
            $summaries[$public_id] = $this->resolveSummary($public_id, $session_id, $page_context);
        }

        return new WP_REST_Response(
            [
                'ok' => true,
                'summaries' => $summaries,
            ]
        );
    }

    /**
     * Extract + sanitize the request's `publicIds` array. Returns null when
     * the input is not an array of strings, or exceeds the batch cap.
     * Duplicates are collapsed (a caller re-requesting the same id in one
     * batch is a client bug, not something we need to serve twice).
     *
     * @param WP_REST_Request $request
     * @return array<int, string>|null
     */
    private function extractPublicIds(WP_REST_Request $request)
    {
        $raw = $request['publicIds'];
        if (! is_array($raw)) {
            return null;
        }
        if (count($raw) > self::BATCH_CAP) {
            return null;
        }

        $out = [];
        $seen = [];
        foreach ($raw as $entry) {
            if (! is_string($entry) || $entry === '') {
                continue;
            }
            if (isset($seen[$entry])) {
                continue;
            }
            $seen[$entry] = true;
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Resolve one public id to a summary or null. Fails closed on every
     * shape mismatch (unknown / visibility-blocked record, provider gone,
     * capability denied, provider returned null).
     *
     * @param string               $public_id
     * @param string               $session_id
     * @param array<string, mixed> $page_context
     * @return array<string, mixed>|null
     */
    private function resolveSummary($public_id, $session_id, array $page_context)
    {
        $record = $this->control_registry->getVisibleRecord($public_id);
        if ($record === null) {
            return null;
        }

        // Recheck capability against the resolved descriptor. This mirrors
        // ControlCenterOpenController's flow and closes the "list included
        // the row, but the caller could not edit it" window — a summary is a
        // read-model that surfaces owned data, so gate it the same way.
        $descriptor = $this->control_registry->buildDescriptorForRecord($record, $session_id, $page_context);
        if ($descriptor === null) {
            return null;
        }
        if (! $this->capabilities->canEditDescriptor($descriptor)) {
            return null;
        }

        return $this->control_registry->buildValueSummaryForRecord($record, $session_id);
    }
}
