<?php

namespace Dbvc\VisualEditor\Rest\Controllers;

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Navigation\ObjectNavigationReadModel;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `GET /dbvc/v1/visual-editor/object-search`
 *
 * R6-A: thin transport over ObjectNavigationReadModel. Backward compatible
 * with the pre-R6 Go To Object popover contract (`search`, `objectType`,
 * `subtype`, `limit`) and widened with `page`, `perPage`, `includeTypes`,
 * `sort` (R6.1-a) plus `page` / `perPage` / `hasMore` / `query` / optional
 * `types` in the response. No new route, no write authority.
 */
final class ObjectSearchController
{
    /**
     * @var EditModeState
     */
    private $edit_mode;

    /**
     * @var CapabilityManager
     */
    private $capabilities;

    /**
     * @var ObjectNavigationReadModel
     */
    private $read_model;

    public function __construct(EditModeState $edit_mode, CapabilityManager $capabilities, ?ObjectNavigationReadModel $read_model = null)
    {
        $this->edit_mode = $edit_mode;
        $this->capabilities = $capabilities;
        $this->read_model = $read_model ?: new ObjectNavigationReadModel($capabilities);
    }

    /**
     * @return void
     */
    public function register()
    {
        register_rest_route(
            'dbvc/v1',
            '/visual-editor/object-search',
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

        $args = $this->read_model->normalizeArgs(
            [
                'search' => $request->get_param('search'),
                'objectType' => $request->get_param('objectType'),
                'subtype' => $request->get_param('subtype'),
                'page' => $request->get_param('page'),
                'perPage' => $request->get_param('perPage'),
                'limit' => $request->get_param('limit'),
                'sort' => $request->get_param('sort'),
            ]
        );

        $result = $this->read_model->search($args);

        $payload = [
            'ok' => true,
            'items' => $result['items'],
            'page' => $result['page'],
            'perPage' => $result['perPage'],
            'hasMore' => $result['hasMore'],
            'query' => [
                'search' => $args['search'],
                'objectType' => $args['objectType'] === '' ? 'all' : $args['objectType'],
                'subtype' => $args['subtype'],
                // R6.1-a: the sort actually applied (absent/unknown keys resolve
                // to relevance-while-searching or recent).
                'sort' => $args['sort'],
            ],
        ];

        if (rest_sanitize_boolean($request->get_param('includeTypes'))) {
            $payload['types'] = $this->read_model->describeTypes();
        }

        return new WP_REST_Response($payload);
    }
}
