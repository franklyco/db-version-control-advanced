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

        if (! empty($result['ok'])) {
            $collection_state = $this->refreshQueryCollectionDescriptorState($session_id, $descriptor, $result);
            if (! empty($collection_state)) {
                $result['collectionState'] = $collection_state;
            }
        }

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

    /**
     * @param string             $session_id
     * @param EditableDescriptor $descriptor
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function refreshQueryCollectionDescriptorState($session_id, EditableDescriptor $descriptor, array $result)
    {
        $render_context = isset($descriptor->render['context']) ? sanitize_key((string) $descriptor->render['context']) : '';
        $source = isset($descriptor->source) && is_array($descriptor->source) ? $descriptor->source : [];

        if ($render_context !== 'query_collection' || empty($source)) {
            return [];
        }

        $selected_ids = $this->extractReferenceIds($result['value'] ?? []);
        $target_post_type = isset($source['query_target_post_type']) ? sanitize_key((string) $source['query_target_post_type']) : '';
        $preserved_ids = isset($source['query_preserved_ids']) && is_array($source['query_preserved_ids'])
            ? array_values(array_filter(array_map('absint', $source['query_preserved_ids'])))
            : [];
        $write_mode = isset($source['query_collection_write_mode']) ? sanitize_key((string) $source['query_collection_write_mode']) : '';
        $subset_mode = isset($source['query_subset_write_mode']) ? sanitize_key((string) $source['query_subset_write_mode']) : '';
        $is_subset = $subset_mode === 'replace_target_post_type_subset' || $write_mode === 'replace_target_post_type_subset';
        $full_ids = $is_subset ? array_values(array_merge($preserved_ids, $selected_ids)) : $selected_ids;

        $descriptor->source['query_result_ids'] = $selected_ids;
        $descriptor->source['query_full_value_ids'] = $full_ids;
        $descriptor->source['query_preserved_ids'] = $is_subset ? $preserved_ids : [];
        $descriptor->source['query_result_empty'] = empty($selected_ids);

        $this->registry->updateDescriptorInSession($session_id, $descriptor);

        return [
            'queryResultIds' => $selected_ids,
            'queryFullValueIds' => $full_ids,
            'queryPreservedIds' => $is_subset ? $preserved_ids : [],
            'queryTargetPostType' => $target_post_type,
            'queryCollectionWriteMode' => $write_mode,
            'querySubsetWriteMode' => $subset_mode,
        ];
    }

    /**
     * @param mixed $value
     * @return array<int,int>
     */
    private function extractReferenceIds($value)
    {
        $values = is_array($value) ? $value : [$value];
        $ids = [];

        foreach ($values as $item) {
            if (is_array($item) && isset($item['id'])) {
                $id = absint($item['id']);
            } elseif (is_array($item) && isset($item['ID'])) {
                $id = absint($item['ID']);
            } elseif (is_object($item) && isset($item->id)) {
                $id = absint($item->id);
            } elseif (is_object($item) && isset($item->ID)) {
                $id = absint($item->ID);
            } else {
                $id = absint($item);
            }

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values($ids);
    }
}
