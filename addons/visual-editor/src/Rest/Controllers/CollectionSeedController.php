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

final class CollectionSeedController
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

    public function __construct(EditableRegistry $registry, MutationService $mutations, EditModeState $edit_mode, CapabilityManager $capabilities, MutationContractService $contracts)
    {
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
            '/visual-editor/session/(?P<session_id>[A-Za-z0-9_-]+)/collection-seed/(?P<token>[A-Za-z0-9_-]+)',
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
            return $this->error(__('Invalid request.', 'dbvc'), 400);
        }

        if (! $this->edit_mode->isRestRequestAuthorized()) {
            return $this->error(__('Visual Editor mode is not active.', 'dbvc'), 403);
        }

        if (! $this->hasSeedAcknowledgement($request)) {
            return $this->error(__('Confirm that this should populate the current page field from the fallback list before continuing.', 'dbvc'), 400);
        }

        $session_id = sanitize_key((string) $request['session_id']);
        $token = sanitize_key((string) $request['token']);
        $descriptor = $this->registry->getDescriptorFromSession($session_id, $token);

        if (! $descriptor) {
            return $this->error(__('Descriptor not found.', 'dbvc'), 404);
        }

        $seed = $this->buildSeedMutation($descriptor);
        if (empty($seed['ok'])) {
            return $this->error(isset($seed['message']) ? (string) $seed['message'] : __('This fallback branch cannot seed the current page field safely.', 'dbvc'), 400);
        }

        $seed_descriptor = isset($seed['descriptor']) && $seed['descriptor'] instanceof EditableDescriptor ? $seed['descriptor'] : null;
        $value = isset($seed['value']) ? $seed['value'] : [];
        if (! $seed_descriptor) {
            return $this->error(__('The current page seed descriptor could not be prepared.', 'dbvc'), 400);
        }

        $contract_summary = $this->contracts->buildSummary($seed_descriptor);
        if (empty($contract_summary['writable'])) {
            return $this->error($this->contracts->getUnsupportedMessage($seed_descriptor), 400);
        }

        $result = $this->mutations->mutate($seed_descriptor, $value);
        $result['saveContractSummary'] = $contract_summary;
        $result['reload'] = ! empty($result['ok']);
        $result['seededField'] = isset($seed_descriptor->source['field_name']) ? sanitize_key((string) $seed_descriptor->source['field_name']) : '';
        if (! empty($result['ok'])) {
            $result['message'] = __('Current page connected-items field was populated from the fallback list. Reloading page…', 'dbvc');
        }

        return new WP_REST_Response($result, ! empty($result['ok']) ? 200 : 400);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<string, mixed>
     */
    private function buildSeedMutation(EditableDescriptor $descriptor)
    {
        $source = isset($descriptor->source) && is_array($descriptor->source) ? $descriptor->source : [];
        $seed = isset($source['query_seed_current_field']) && is_array($source['query_seed_current_field'])
            ? $source['query_seed_current_field']
            : [];

        if (empty($seed['enabled'])
            || sanitize_key((string) ($source['source_context'] ?? '')) !== 'shared_option_fallback'
            || sanitize_key((string) ($source['query_branch_state'] ?? '')) !== 'shared_option_fallback_exact_match') {
            return [
                'ok' => false,
                'message' => __('This query loop is not an exact shared fallback branch with a current-page seed target.', 'dbvc'),
            ];
        }

        $page = isset($descriptor->page) && is_array($descriptor->page) ? $descriptor->page : [];
        $page_type = isset($page['type']) ? sanitize_key((string) $page['type']) : '';
        $page_id = isset($page['id']) ? absint($page['id']) : 0;
        $page_subtype = isset($page['subtype']) ? sanitize_key((string) $page['subtype']) : '';

        if ($page_type !== 'post' || $page_id <= 0 || ! $this->capabilities->canEditPostId($page_id)) {
            return [
                'ok' => false,
                'message' => __('You cannot edit the current page field for this fallback branch.', 'dbvc'),
            ];
        }

        if (! function_exists('get_field_object') || ! function_exists('get_field')) {
            return [
                'ok' => false,
                'message' => __('ACF runtime is unavailable for the current page seed action.', 'dbvc'),
            ];
        }

        $field_name = isset($seed['field_name']) ? sanitize_key((string) $seed['field_name']) : '';
        $field_key = isset($seed['field_key']) ? sanitize_key((string) $seed['field_key']) : '';
        $field_selector = isset($seed['field_selector']) ? sanitize_key((string) $seed['field_selector']) : '';
        if ($field_selector === '') {
            $field_selector = $field_name;
        }
        $field_selector_raw = isset($seed['field_selector_raw']) ? $this->normalizeAcfSelectorForSource((string) $seed['field_selector_raw']) : '';
        $field_identifier = $field_selector_raw !== '' ? $field_selector_raw : ($field_selector !== '' ? $field_selector : $field_name);
        $field = $field_identifier !== '' ? get_field_object($field_identifier, $page_id, false, true) : null;
        if (! is_array($field) && $field_key !== '') {
            $field = get_field_object($field_key, $page_id, false, true);
        }
        if (! is_array($field)) {
            return [
                'ok' => false,
                'message' => __('The current page seed field could not be resolved.', 'dbvc'),
            ];
        }

        if ($field_key !== '' && isset($field['key']) && sanitize_key((string) $field['key']) !== $field_key) {
            return [
                'ok' => false,
                'message' => __('The current page seed field no longer matches the loaded descriptor.', 'dbvc'),
            ];
        }

        $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        if (! in_array($field_type, ['relationship', 'post_object'], true)) {
            return [
                'ok' => false,
                'message' => __('Only relationship and post object fields can be seeded from fallback query loops.', 'dbvc'),
            ];
        }

        $query_ids = $this->normalizeIdList($source['query_result_ids'] ?? []);
        if (empty($query_ids)) {
            return [
                'ok' => false,
                'message' => __('The fallback query did not provide any connected items to seed.', 'dbvc'),
            ];
        }

        $target_post_type = isset($source['query_target_post_type']) ? sanitize_key((string) $source['query_target_post_type']) : '';
        $existing_ids = $this->normalizeIdList(get_field($field_identifier, $page_id, false));
        $existing_target_ids = $target_post_type === ''
            ? $existing_ids
            : $this->filterIdsByPostType($existing_ids, $target_post_type);

        if (! empty($existing_target_ids)) {
            return [
                'ok' => false,
                'message' => __('The current page field already has connected items for this query branch. Edit the current field directly instead of seeding from fallback.', 'dbvc'),
            ];
        }

        foreach ($query_ids as $post_id) {
            if (! $this->postMatchesField($field, $post_id)) {
                return [
                    'ok' => false,
                    'message' => __('One or more fallback items are no longer valid for the current page field.', 'dbvc'),
                ];
            }
        }

        $value = $this->mergeSeedIds($existing_ids, $query_ids);
        $max = $this->resolveReferenceMaxSelections($field);
        if ($max > 0 && count($value) > $max) {
            return [
                'ok' => false,
                'message' => __('The fallback list exceeds the current page field maximum selection limit.', 'dbvc'),
            ];
        }

        if (! $this->isReferenceMultiple($field) && count($value) > 1) {
            return [
                'ok' => false,
                'message' => __('The current page field only accepts one connected item, but the fallback query contains multiple items.', 'dbvc'),
            ];
        }

        $field_key = isset($field['key']) ? sanitize_key((string) $field['key']) : $field_key;
        $leaf_field_name = isset($seed['leaf_field_name']) ? sanitize_key((string) $seed['leaf_field_name']) : '';
        if ($leaf_field_name === '') {
            $leaf_field_name = $field_name;
        }
        $leaf_field_key = isset($seed['leaf_field_key']) ? sanitize_key((string) $seed['leaf_field_key']) : '';
        if ($leaf_field_key === '') {
            $leaf_field_key = $field_key;
        }
        $group_path = isset($seed['group_path']) && is_array($seed['group_path'])
            ? array_values(array_filter(array_map('sanitize_key', $seed['group_path'])))
            : [];
        $group_key_path = isset($seed['group_key_path']) && is_array($seed['group_key_path'])
            ? array_values(array_filter(array_map('sanitize_key', $seed['group_key_path'])))
            : [];
        $seed_descriptor = new EditableDescriptor(
            $descriptor->token,
            'editable',
            'current_entity',
            [
                'type' => 'post',
                'id' => $page_id,
                'subtype' => $page_subtype,
                'acf_object_id' => $page_id,
            ],
            [
                'template_id' => isset($descriptor->render['template_id']) ? absint($descriptor->render['template_id']) : 0,
                'element_id' => isset($descriptor->render['element_id']) ? sanitize_text_field((string) $descriptor->render['element_id']) : '',
                'element_uid' => isset($descriptor->render['element_uid']) ? sanitize_text_field((string) $descriptor->render['element_uid']) : '',
                'element_name' => isset($descriptor->render['element_name']) ? sanitize_key((string) $descriptor->render['element_name']) : '',
                'setting_key' => isset($descriptor->render['setting_key']) ? sanitize_key((string) $descriptor->render['setting_key']) : 'query',
                'attribute_key' => isset($descriptor->render['attribute_key']) ? sanitize_text_field((string) $descriptor->render['attribute_key']) : '',
                'context' => 'query_collection',
                'attribute' => '',
                'source_group' => '',
                'sync_group' => '',
                'loop_signature' => '',
                'loop' => [],
            ],
            [
                'type' => 'acf_collection_field',
                'source_context' => 'current_entity_seed_from_shared_option_fallback',
                'expression' => isset($source['expression']) ? sanitize_text_field((string) $source['expression']) : '',
                'expression_args' => [],
                'field_name' => $field_name,
                'field_key' => $field_key,
                'field_selector' => $field_selector,
                'field_selector_raw' => $field_selector_raw,
                'leaf_field_name' => $leaf_field_name,
                'leaf_field_key' => $leaf_field_key,
                'field_type' => $field_type,
                'return_format' => isset($field['return_format']) ? sanitize_key((string) $field['return_format']) : 'value',
                'reference_post_types' => $this->normalizeStringList($field['post_type'] ?? []),
                'reference_taxonomies' => $this->normalizeStringList($field['taxonomy'] ?? []),
                'reference_multiple' => $this->isReferenceMultiple($field),
                'reference_min' => $this->resolveReferenceMinSelections($field),
                'reference_max' => $max,
                'query_source' => 'derived_bricks_query_seed',
                'query_source_confidence' => 'explicit_seed_from_shared_option_fallback',
                'query_branch_state' => 'seed_current_field_from_shared_option_fallback',
                'query_target_post_type' => $target_post_type,
                'query_result_ids' => $query_ids,
                'query_full_value_ids' => $existing_ids,
                'query_preserved_ids' => array_values(array_diff($existing_ids, $query_ids)),
                'query_collection_write_mode' => 'seed_current_field_from_fallback',
                'container_type' => '',
                'group_path' => $group_path,
                'group_key_path' => $group_key_path,
                'is_nested_group' => ! empty($group_path),
                'is_grouped_field' => ! empty($group_path),
            ],
            [
                'label' => isset($field['label']) ? sanitize_text_field((string) $field['label']) : $field_name,
                'input' => 'reference_collection',
                'warning' => __('This explicit action copies the fallback query items into the current page field and reloads the page so Bricks can render the current-page branch.', 'dbvc'),
            ],
            [
                'name' => 'acf_reference_collection',
                'version' => 1,
            ],
            $page,
            [
                'type' => 'post',
                'id' => $page_id,
                'subtype' => $page_subtype,
                'acf_object_id' => $page_id,
            ],
            [],
            [
                'containerType' => '',
                'rootFieldName' => $field_name,
                'rootFieldKey' => $field_key,
                'fieldName' => $field_name,
                'fieldKey' => $field_key,
                'groupPath' => $group_path,
                'groupKeyPath' => $group_key_path,
                'segments' => [
                    [
                        'type' => 'field',
                        'fieldName' => $field_name,
                        'fieldKey' => $field_key,
                    ],
                ],
                'summary' => 'field:' . ($field_selector_raw !== '' ? $field_selector_raw : $field_name),
            ],
            [
                'version' => 2,
                'kind' => 'collection',
                'target' => 'field',
                'contract' => $field_type === 'post_object'
                    ? 'post_object_collection_seed_from_fallback'
                    : 'relationship_collection_seed_from_fallback',
                'renderContext' => 'query_collection',
                'requiresJournal' => true,
            ]
        );

        return [
            'ok' => true,
            'descriptor' => $seed_descriptor,
            'value' => $value,
        ];
    }

    /**
     * @param WP_REST_Request $request
     * @return bool
     */
    private function hasSeedAcknowledgement(WP_REST_Request $request)
    {
        $value = $request->get_param('acknowledgeSeed');

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
     * @param mixed $value
     * @return array<int, int>
     */
    private function normalizeIdList($value)
    {
        $values = is_array($value) ? $value : [$value];
        $ids = [];

        foreach ($values as $item) {
            $id = is_object($item) && isset($item->ID) ? absint($item->ID) : absint($item);
            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<int, int> $existing_ids
     * @param array<int, int> $query_ids
     * @return array<int, int>
     */
    private function mergeSeedIds(array $existing_ids, array $query_ids)
    {
        return array_values(
            array_unique(
                array_filter(
                    array_map('absint', array_merge($existing_ids, $query_ids))
                )
            )
        );
    }

    /**
     * @param array<int, int> $ids
     * @param string          $post_type
     * @return array<int, int>
     */
    private function filterIdsByPostType(array $ids, $post_type)
    {
        $post_type = sanitize_key((string) $post_type);
        if ($post_type === '') {
            return $ids;
        }

        return array_values(
            array_filter(
                $ids,
                static function ($id) use ($post_type) {
                    return get_post_type(absint($id)) === $post_type;
                }
            )
        );
    }

    /**
     * @param array<string, mixed> $field
     * @param int                  $post_id
     * @return bool
     */
    private function postMatchesField(array $field, $post_id)
    {
        $post_id = absint($post_id);
        if ($post_id <= 0 || ! get_post($post_id)) {
            return false;
        }

        $allowed_post_types = $this->normalizeStringList($field['post_type'] ?? []);
        if (! empty($allowed_post_types) && ! in_array((string) get_post_type($post_id), $allowed_post_types, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $field
     * @return bool
     */
    private function isReferenceMultiple(array $field)
    {
        $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';

        if ($field_type === 'post_object') {
            return ! empty($field['multiple']);
        }

        if ($field_type === 'relationship') {
            return ! isset($field['max']) || (int) $field['max'] !== 1;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $field
     * @return int
     */
    private function resolveReferenceMaxSelections(array $field)
    {
        if (isset($field['max']) && is_numeric($field['max'])) {
            return max(0, absint($field['max']));
        }

        if (isset($field['multiple']) && ! $field['multiple']) {
            return 1;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $field
     * @return int
     */
    private function resolveReferenceMinSelections(array $field)
    {
        return isset($field['min']) && is_numeric($field['min'])
            ? max(0, absint($field['min']))
            : 0;
    }

    /**
     * @param string $selector
     * @return string
     */
    private function normalizeAcfSelectorForSource($selector)
    {
        $selector = trim((string) $selector);
        if ($selector === '') {
            return '';
        }

        $selector = preg_replace('/[^A-Za-z0-9_\-]/', '', $selector);

        return is_string($selector) ? $selector : '';
    }

    /**
     * @param array<string, mixed>|string $value
     * @return array<int, string>
     */
    private function normalizeStringList($value)
    {
        $values = is_array($value) ? $value : [$value];
        $normalized = [];

        foreach ($values as $item) {
            $item = sanitize_key((string) $item);
            if ($item !== '') {
                $normalized[] = $item;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param string $message
     * @param int    $status
     * @return WP_REST_Response
     */
    private function error($message, $status)
    {
        return new WP_REST_Response(
            [
                'ok' => false,
                'message' => (string) $message,
            ],
            $status
        );
    }
}
