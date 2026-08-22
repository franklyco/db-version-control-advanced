<?php

namespace Dbvc\VisualEditor\Rest\Controllers;

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\MediaManager\MediaAssignmentService;
use Dbvc\VisualEditor\MediaManager\MediaFindingDescriptorBridge;
use Dbvc\VisualEditor\MediaManager\MediaScanCoordinator;
use Dbvc\VisualEditor\MediaManager\MediaScanReadModel;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class MediaManagerController
{
    /**
     * @var MediaScanCoordinator
     */
    private $coordinator;

    /**
     * @var MediaScanReadModel
     */
    private $read_model;

    /**
     * @var EditModeState
     */
    private $edit_mode;

    /**
     * @var CapabilityManager
     */
    private $capabilities;

    /**
     * @var MediaFindingDescriptorBridge
     */
    private $descriptor_bridge;

    /**
     * @var MediaAssignmentService
     */
    private $assignment_service;

    public function __construct(
        MediaScanCoordinator $coordinator,
        MediaScanReadModel $read_model,
        EditModeState $edit_mode,
        CapabilityManager $capabilities,
        MediaFindingDescriptorBridge $descriptor_bridge,
        MediaAssignmentService $assignment_service
    ) {
        $this->coordinator = $coordinator;
        $this->read_model = $read_model;
        $this->edit_mode = $edit_mode;
        $this->capabilities = $capabilities;
        $this->descriptor_bridge = $descriptor_bridge;
        $this->assignment_service = $assignment_service;
    }

    /**
     * @return void
     */
    public function register()
    {
        register_rest_route('dbvc/v1', '/visual-editor/media-manager/scans', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'canAccess'],
            'callback' => [$this, 'handleStart'],
        ]);
        register_rest_route('dbvc/v1', '/visual-editor/media-manager/scans/latest', [
            'methods' => 'GET',
            'permission_callback' => [$this, 'canAccess'],
            'callback' => [$this, 'handleLatest'],
        ]);
        register_rest_route('dbvc/v1', '/visual-editor/media-manager/scans/(?P<scan_ref>vems_[a-z0-9_]+)', [
            'methods' => 'GET',
            'permission_callback' => [$this, 'canAccess'],
            'callback' => [$this, 'handleList'],
        ]);
        register_rest_route('dbvc/v1', '/visual-editor/media-manager/scans/(?P<scan_ref>vems_[a-z0-9_]+)/next', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'canAccess'],
            'callback' => [$this, 'handleNext'],
        ]);
        register_rest_route('dbvc/v1', '/visual-editor/media-manager/scans/(?P<scan_ref>vems_[a-z0-9_]+)/retry', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'canAccess'],
            'callback' => [$this, 'handleRetry'],
        ]);
        register_rest_route('dbvc/v1', '/visual-editor/media-manager/scans/(?P<scan_ref>vems_[a-z0-9_]+)/cancel', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'canAccess'],
            'callback' => [$this, 'handleCancel'],
        ]);
        register_rest_route('dbvc/v1', '/visual-editor/media-manager/scans/(?P<scan_ref>vems_[a-z0-9_]+)/groups/(?P<group_ref>vemg_[a-f0-9]{20})', [
            'methods' => 'GET',
            'permission_callback' => [$this, 'canAccess'],
            'callback' => [$this, 'handleGroup'],
        ]);
        register_rest_route('dbvc/v1', '/visual-editor/media-manager/scans/(?P<scan_ref>vems_[a-z0-9_]+)/groups/(?P<group_ref>vemg_[a-f0-9]{20})/findings/(?P<finding_ref>vemf_[a-f0-9]{20})/descriptor', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'canAccess'],
            'callback' => [$this, 'handleFindingDescriptor'],
        ]);
        register_rest_route('dbvc/v1', '/visual-editor/media-manager/scans/(?P<scan_ref>vems_[a-z0-9_]+)/groups/(?P<group_ref>vemg_[a-f0-9]{20})/findings/(?P<finding_ref>vemf_[a-f0-9]{20})/assignment', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'canAccess'],
            'callback' => [$this, 'handleAssignFinding'],
        ]);
        register_rest_route('dbvc/v1', '/visual-editor/media-manager/scans/(?P<scan_ref>vems_[a-z0-9_]+)/groups/(?P<group_ref>vemg_[a-f0-9]{20})/findings/(?P<finding_ref>vemf_[a-f0-9]{20})/replacement', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'canAccess'],
            'callback' => [$this, 'handleReplaceFinding'],
        ]);
    }

    /**
     * @return bool
     */
    public function canAccess()
    {
        return $this->capabilities->canUseVisualEditor()
            && class_exists('\\DBVC_Visual_Editor_Addon')
            && method_exists('\\DBVC_Visual_Editor_Addon', 'is_media_manager_enabled')
            && \DBVC_Visual_Editor_Addon::is_media_manager_enabled();
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleStart($request)
    {
        $authorized = $this->authorizeRequest($request);
        if ($authorized instanceof WP_REST_Response) {
            return $authorized;
        }

        return $this->scanResponse($this->coordinator->start(), 201);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleLatest($request)
    {
        $authorized = $this->authorizeRequest($request);
        if ($authorized instanceof WP_REST_Response) {
            return $authorized;
        }

        return $this->resultResponse($this->read_model->getLatestList($this->queryFromRequest($request)));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleList($request)
    {
        $authorized = $this->authorizeRequest($request);
        if ($authorized instanceof WP_REST_Response) {
            return $authorized;
        }

        $identity = $this->requestIdentity($request);
        if (is_wp_error($identity)) {
            return $this->errorResponse($identity);
        }

        return $this->resultResponse(
            $this->read_model->getList(
                sanitize_key((string) $request['scan_ref']),
                array_merge($this->queryFromRequest($request), $identity)
            )
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleNext($request)
    {
        return $this->handleCoordinatorAction($request, 'next');
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleRetry($request)
    {
        return $this->handleCoordinatorAction($request, 'retry');
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleCancel($request)
    {
        return $this->handleCoordinatorAction($request, 'cancel');
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleGroup($request)
    {
        $authorized = $this->authorizeRequest($request);
        if ($authorized instanceof WP_REST_Response) {
            return $authorized;
        }

        $identity = $this->requestIdentity($request);
        if (is_wp_error($identity)) {
            return $this->errorResponse($identity);
        }

        return $this->resultResponse(
            $this->read_model->expandGroup(
                sanitize_key((string) $request['scan_ref']),
                sanitize_key((string) $request['group_ref']),
                $identity
            )
        );
    }

    /**
     * R2-A: exchange one opaque finding for one fresh server-authoritative descriptor.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleFindingDescriptor($request)
    {
        $authorized = $this->authorizeRequest($request);
        if ($authorized instanceof WP_REST_Response) {
            return $authorized;
        }

        $identity = $this->requestIdentity($request);
        if (is_wp_error($identity)) {
            return $this->errorResponse($identity);
        }

        return $this->resultResponse(
            $this->descriptor_bridge->bridgeFinding(
                sanitize_key((string) $request['scan_ref']),
                sanitize_key((string) $request['group_ref']),
                sanitize_key((string) $request['finding_ref']),
                $identity
            )
        );
    }

    /**
     * R2-C: assign the staged Media Library selection to the field and save it.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleAssignFinding($request)
    {
        $authorized = $this->authorizeRequest($request);
        if ($authorized instanceof WP_REST_Response) {
            return $authorized;
        }

        $identity = $this->requestIdentity($request);
        if (is_wp_error($identity)) {
            return $this->errorResponse($identity);
        }

        return $this->resultResponse(
            $this->assignment_service->assign(
                sanitize_key((string) $request['scan_ref']),
                sanitize_key((string) $request['group_ref']),
                sanitize_key((string) $request['finding_ref']),
                $identity,
                $this->assignmentValueFromRequest($request)
            )
        );
    }

    /**
     * R2-F Slice 3: replace the media on a populated field with the staged selection,
     * gated by the expected-current-value fingerprint the client read at expand time.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleReplaceFinding($request)
    {
        $authorized = $this->authorizeRequest($request);
        if ($authorized instanceof WP_REST_Response) {
            return $authorized;
        }

        $identity = $this->requestIdentity($request);
        if (is_wp_error($identity)) {
            return $this->errorResponse($identity);
        }

        return $this->resultResponse(
            $this->assignment_service->replace(
                sanitize_key((string) $request['scan_ref']),
                sanitize_key((string) $request['group_ref']),
                sanitize_key((string) $request['finding_ref']),
                sanitize_key((string) $request->get_param('expectedValueRef')),
                $identity,
                $this->assignmentValueFromRequest($request)
            )
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return array<string, mixed>
     */
    private function assignmentValueFromRequest(WP_REST_Request $request)
    {
        $value = [];

        $attachment_id = $request->get_param('attachmentId');
        if ($attachment_id !== null) {
            $value['attachmentId'] = absint($attachment_id);
        }

        $attachment_ids = $request->get_param('attachmentIds');
        if (is_array($attachment_ids)) {
            $value['attachmentIds'] = array_values(array_map('absint', $attachment_ids));
        }

        return $value;
    }

    /**
     * @param WP_REST_Request $request
     * @param string          $action
     * @return WP_REST_Response
     */
    private function handleCoordinatorAction($request, $action)
    {
        $authorized = $this->authorizeRequest($request);
        if ($authorized instanceof WP_REST_Response) {
            return $authorized;
        }

        $identity = $this->requestIdentity($request);
        if (is_wp_error($identity)) {
            return $this->errorResponse($identity);
        }

        $scan_ref = sanitize_key((string) $request['scan_ref']);
        if ($action === 'retry') {
            $result = $this->coordinator->retry($scan_ref, $identity['generation'], $identity['expectedRevision']);
        } elseif ($action === 'cancel') {
            $result = $this->coordinator->cancel($scan_ref, $identity['generation'], $identity['expectedRevision']);
        } else {
            $result = $this->coordinator->runNextChunk($scan_ref, $identity['generation'], $identity['expectedRevision']);
        }

        return $this->scanResponse($result);
    }

    /**
     * @param mixed $request
     * @return true|WP_REST_Response
     */
    private function authorizeRequest($request)
    {
        if (! ($request instanceof WP_REST_Request)) {
            return new WP_REST_Response([
                'ok' => false,
                'code' => 'media_manager_request_invalid',
                'message' => __('Invalid request.', 'dbvc'),
            ], 400);
        }

        if (! $this->edit_mode->isRestRequestAuthorized()) {
            return new WP_REST_Response([
                'ok' => false,
                'code' => 'media_manager_mode_inactive',
                'message' => __('Visual Editor mode is not active.', 'dbvc'),
            ], 403);
        }

        return true;
    }

    /**
     * @param WP_REST_Request $request
     * @return array<string, mixed>|WP_Error
     */
    private function requestIdentity(WP_REST_Request $request)
    {
        $generation = sanitize_key((string) $request->get_param('generation'));
        $revision = $request->get_param('expectedRevision');
        if ($generation === '') {
            return new WP_Error('media_scan_generation_required', __('The media scan generation is required.', 'dbvc'), ['status' => 400]);
        }
        if (! is_numeric($revision) || absint($revision) < 1) {
            return new WP_Error('media_scan_revision_required', __('The media scan revision is required.', 'dbvc'), ['status' => 400]);
        }

        return [
            'generation' => $generation,
            'expectedRevision' => absint($revision),
        ];
    }

    /**
     * @param WP_REST_Request $request
     * @return array<string, mixed>
     */
    private function queryFromRequest(WP_REST_Request $request)
    {
        return [
            'search' => (string) $request->get_param('search'),
            'entityFamily' => (string) ($request->get_param('entityFamily') ?: 'all'),
            'fieldFamily' => (string) ($request->get_param('fieldFamily') ?: 'all'),
            'sort' => (string) ($request->get_param('sort') ?: 'entity_asc'),
            'limit' => $request->get_param('limit') ?: 20,
            'cursor' => (string) $request->get_param('cursor'),
        ];
    }

    /**
     * @param array<string, mixed>|WP_Error $result
     * @param int                          $success_status
     * @return WP_REST_Response
     */
    private function scanResponse($result, $success_status = 200)
    {
        if (is_wp_error($result)) {
            return $this->errorResponse($result);
        }

        return new WP_REST_Response([
            'ok' => true,
            'scan' => $this->read_model->projectSnapshot(is_array($result) ? $result : []),
        ], absint($success_status));
    }

    /**
     * @param array<string, mixed>|WP_Error $result
     * @return WP_REST_Response
     */
    private function resultResponse($result)
    {
        if (is_wp_error($result)) {
            return $this->errorResponse($result);
        }

        return new WP_REST_Response(array_merge(['ok' => true], is_array($result) ? $result : []), 200);
    }

    /**
     * @param WP_Error $error
     * @return WP_REST_Response
     */
    private function errorResponse(WP_Error $error)
    {
        $data = $error->get_error_data();
        $status = is_array($data) && ! empty($data['status'])
            ? absint($data['status'])
            : $this->defaultErrorStatus((string) $error->get_error_code());

        return new WP_REST_Response([
            'ok' => false,
            'code' => sanitize_key((string) $error->get_error_code()),
            'message' => sanitize_text_field((string) $error->get_error_message()),
            'retryable' => is_array($data) && ! empty($data['retryable']),
        ], $status);
    }

    /**
     * @param string $code
     * @return int
     */
    private function defaultErrorStatus($code)
    {
        if (in_array($code, ['media_scan_expired_or_invalid', 'media_scan_group_unavailable'], true)) {
            return 404;
        }
        if (in_array($code, ['media_scan_busy', 'media_scan_generation_mismatch', 'media_scan_revision_changed', 'media_scan_superseded'], true)) {
            return 409;
        }
        if (strpos($code, 'unavailable') !== false || strpos($code, 'failed') !== false) {
            return 503;
        }

        return 400;
    }
}
