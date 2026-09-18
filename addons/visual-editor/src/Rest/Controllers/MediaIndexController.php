<?php

namespace Dbvc\VisualEditor\Rest\Controllers;

use Dbvc\VisualEditor\MediaManager\EligibilityPolicy;
use Dbvc\VisualEditor\MediaManager\MediaIndexReadModel;
use Dbvc\VisualEditor\MediaManager\MediaIndexStore;
use Dbvc\VisualEditor\MediaManager\MediaScanCoordinator;
use Dbvc\VisualEditor\MediaManager\MediaScanReadModel;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_Term;

/**
 * R2-H Slice 2b — read the persistent Media Index over REST (server plumbing).
 *
 * `GET .../media-manager/index` lists the durable index for the current user with
 * read-time eligibility filtering. `POST .../media-manager/index/expand` resolves one
 * opaque index ref, re-checks eligibility for the requesting user, builds a detached
 * single-entity snapshot on demand, and returns the live detail plus that snapshot's
 * scan/group references — so the existing assign/replace routes drive mutation
 * unchanged. The frontend flip to this source is a separate slice (2c); these routes
 * do not change any current UI behavior.
 */
final class MediaIndexController
{
    /**
     * @var CapabilityManager
     */
    private $capabilities;

    /**
     * @var MediaIndexReadModel
     */
    private $index_read_model;

    /**
     * @var MediaIndexStore
     */
    private $index_store;

    /**
     * @var MediaScanCoordinator
     */
    private $coordinator;

    /**
     * @var MediaScanReadModel
     */
    private $read_model;

    /**
     * @var EligibilityPolicy
     */
    private $policy;

    public function __construct(
        CapabilityManager $capabilities,
        MediaIndexReadModel $index_read_model,
        MediaIndexStore $index_store,
        MediaScanCoordinator $coordinator,
        MediaScanReadModel $read_model,
        EligibilityPolicy $policy
    ) {
        $this->capabilities = $capabilities;
        $this->index_read_model = $index_read_model;
        $this->index_store = $index_store;
        $this->coordinator = $coordinator;
        $this->read_model = $read_model;
        $this->policy = $policy;
    }

    /**
     * @return void
     */
    public function register()
    {
        register_rest_route('dbvc/v1', '/visual-editor/media-manager/index', [
            'methods' => 'GET',
            'permission_callback' => [$this, 'canAccess'],
            'callback' => [$this, 'handleIndexList'],
        ]);
        register_rest_route('dbvc/v1', '/visual-editor/media-manager/index/expand', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'canAccess'],
            'callback' => [$this, 'handleIndexExpand'],
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
    public function handleIndexList($request)
    {
        $list = $this->index_read_model->getList([
            'limit' => absint($request->get_param('limit')),
            'offset' => absint($request->get_param('offset')),
            'search' => (string) $request->get_param('search'),
            'entityFamily' => (string) $request->get_param('entityFamily'),
            'fieldFamily' => (string) $request->get_param('fieldFamily'),
            'sort' => (string) $request->get_param('sort'),
        ]);

        return new WP_REST_Response($list, 200);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleIndexExpand($request)
    {
        $entity_ref = sanitize_key((string) $request->get_param('entityRef'));
        $row = $this->index_store->getByEntityRef($entity_ref);
        if ($row === null) {
            return $this->error('media_index_ref_unavailable', __('This media index reference is unavailable. Refresh the list.', 'dbvc'), 404);
        }

        // Read-time authority: re-check eligibility for the requesting user before any work.
        if (! $this->isVisibleToCurrentUser($row)) {
            return $this->error('media_index_forbidden', __('You cannot view this entity.', 'dbvc'), 403);
        }

        $snapshot = $this->coordinator->snapshotEntity(
            (string) $row['entity_type'],
            (string) $row['entity_subtype'],
            absint($row['entity_id'])
        );
        if (is_wp_error($snapshot)) {
            return $this->errorFromWp($snapshot);
        }

        $groups = isset($snapshot['groups']) && is_array($snapshot['groups']) ? $snapshot['groups'] : [];
        $group_ref = (string) array_key_first($groups);
        if ($group_ref === '') {
            return $this->error('media_index_entity_empty', __('This entity no longer has supported media fields. Refresh the list.', 'dbvc'), 409);
        }

        $expanded = $this->read_model->expandGroup(
            (string) $snapshot['scan_ref'],
            $group_ref,
            [
                'generation' => (string) $snapshot['generation'],
                'expectedRevision' => absint($snapshot['revision']),
            ]
        );
        if (is_wp_error($expanded)) {
            return $this->errorFromWp($expanded);
        }

        $expanded['source'] = 'index';

        return new WP_REST_Response($expanded, 200);
    }

    /**
     * @param array<string, mixed> $row
     * @return bool
     */
    private function isVisibleToCurrentUser(array $row)
    {
        $type = sanitize_key((string) ($row['entity_type'] ?? ''));
        $id = absint($row['entity_id'] ?? 0);
        $subtype = sanitize_key((string) ($row['entity_subtype'] ?? ''));
        if ($id <= 0) {
            return false;
        }

        if ($type === 'post') {
            $post = get_post($id);

            return $post instanceof WP_Post && ! empty($this->policy->assessPost($post)['eligible']);
        }

        if ($type === 'term') {
            $term = get_term($id, $subtype);

            return ! is_wp_error($term)
                && $term instanceof WP_Term
                && ! empty($this->policy->assessTerm($term)['eligible']);
        }

        return false;
    }

    /**
     * @param string $code
     * @param string $message
     * @param int    $status
     * @return WP_REST_Response
     */
    private function error($code, $message, $status)
    {
        return new WP_REST_Response([
            'ok' => false,
            'code' => sanitize_key((string) $code),
            'message' => sanitize_text_field((string) $message),
        ], absint($status));
    }

    /**
     * @param WP_Error $error
     * @return WP_REST_Response
     */
    private function errorFromWp(WP_Error $error)
    {
        $data = $error->get_error_data();
        $status = is_array($data) && isset($data['status']) ? absint($data['status']) : 400;

        return $this->error($error->get_error_code(), $error->get_error_message(), $status ?: 400);
    }
}
