<?php

namespace Dbvc\VisualEditor\Rest\Controllers;

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Registry\EditableDescriptor;
use Dbvc\VisualEditor\Registry\EditableRegistry;
use Dbvc\VisualEditor\Rest\DescriptorPayloadBuilder;
use WP_REST_Request;
use WP_REST_Response;

final class SharedGlobalFieldsController
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
     * @var CapabilityManager
     */
    private $capabilities;

    /**
     * @var DescriptorPayloadBuilder
     */
    private $payloads;

    public function __construct(EditableRegistry $registry, EditModeState $edit_mode, CapabilityManager $capabilities, DescriptorPayloadBuilder $payloads)
    {
        $this->registry = $registry;
        $this->edit_mode = $edit_mode;
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
            '/visual-editor/session/(?P<session_id>[A-Za-z0-9_-]+)/shared-global-fields',
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

        if (! $this->canManageSharedGlobalOptions()) {
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'message' => __('You cannot manage Visual Editor shared global options fields.', 'dbvc'),
                ],
                403
            );
        }

        $session_id = sanitize_key((string) $request['session_id']);
        $session = $this->registry->loadSession($session_id, false);

        if (empty($session)) {
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'message' => __('Visual Editor session expired. Refresh the page to continue editing.', 'dbvc'),
                ],
                404
            );
        }

        if (! function_exists('get_field_object') || ! function_exists('get_field')) {
            return new WP_REST_Response(
                [
                    'ok' => true,
                    'fields' => [],
                    'descriptors' => [],
                    'descriptorHydrations' => [],
                    'warnings' => [__('ACF is unavailable, so shared global fields cannot be inspected.', 'dbvc')],
                ]
            );
        }

        $configured_names = $this->getConfiguredFieldNames();
        $page_context = isset($session['page_context']) && is_array($session['page_context']) ? $session['page_context'] : [];
        $fields = [];
        $descriptors = [];
        $hydrations = [];
        $warnings = [];

        foreach ($configured_names as $configured_name) {
            $field = get_field_object($configured_name, 'option', false, true);
            if (! is_array($field)) {
                $warnings[] = sprintf(
                    /* translators: %s: ACF field name */
                    __('Configured shared global field `%s` was not found on ACF options.', 'dbvc'),
                    $configured_name
                );
                continue;
            }

            $field_name = isset($field['name']) ? sanitize_key((string) $field['name']) : '';
            if ($field_name === '' || $field_name !== $configured_name) {
                $warnings[] = sprintf(
                    /* translators: %s: ACF field name */
                    __('Configured shared global field `%s` did not resolve to a matching options field name.', 'dbvc'),
                    $configured_name
                );
                continue;
            }

            $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
            if (! in_array($field_type, ['relationship', 'post_object'], true)) {
                $warnings[] = sprintf(
                    /* translators: %s: ACF field name */
                    __('Configured shared global field `%s` is not an ACF relationship or post_object field.', 'dbvc'),
                    $configured_name
                );
                continue;
            }

            $descriptor = $this->buildDescriptor($session_id, $page_context, $field);
            if (empty($descriptor->source['reference_post_types'])) {
                $warnings[] = sprintf(
                    /* translators: %s: ACF field name */
                    __('Configured shared global field `%s` only targets post types excluded from Visual Editor.', 'dbvc'),
                    $configured_name
                );
                continue;
            }

            if (! $this->registry->addDescriptorToSession($session_id, $descriptor)) {
                $warnings[] = sprintf(
                    /* translators: %s: ACF field name */
                    __('Configured shared global field `%s` could not be attached to this Visual Editor session.', 'dbvc'),
                    $configured_name
                );
                continue;
            }

            $payload = $this->payloads->build($descriptor);
            $summary = $this->registry->exportPublicMap([$descriptor->token => $descriptor]);
            $public = isset($summary[$descriptor->token]) ? $summary[$descriptor->token] : [];
            $descriptors[$descriptor->token] = $public;
            $hydrations[$descriptor->token] = array_merge(['ok' => true], $payload);
            $fields[] = $this->buildFieldInventoryItem($descriptor, $field, $payload);
        }

        return new WP_REST_Response(
            [
                'ok' => true,
                'fields' => $fields,
                'descriptors' => $descriptors,
                'descriptorHydrations' => $hydrations,
                'warnings' => $warnings,
            ]
        );
    }

    /**
     * @return bool
     */
    private function canManageSharedGlobalOptions()
    {
        $descriptor = new EditableDescriptor(
            've_shared_global_capability_probe',
            'editable',
            'shared_entity',
            [
                'type' => 'option',
                'id' => 0,
                'subtype' => 'acf_options',
                'acf_object_id' => 'option',
            ],
            [],
            [],
            [],
            []
        );

        return $this->capabilities->canEditDescriptor($descriptor);
    }

    /**
     * @return array<int, string>
     */
    private function getConfiguredFieldNames()
    {
        if (class_exists('\DBVC_Visual_Editor_Addon') && method_exists('\DBVC_Visual_Editor_Addon', 'get_shared_global_field_names')) {
            return \DBVC_Visual_Editor_Addon::get_shared_global_field_names();
        }

        return [];
    }

    /**
     * @param string               $session_id
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $field
     * @return EditableDescriptor
     */
    private function buildDescriptor($session_id, array $page_context, array $field)
    {
        $field_name = sanitize_key((string) ($field['name'] ?? ''));
        $field_key = sanitize_key((string) ($field['key'] ?? ''));
        $field_type = sanitize_key((string) ($field['type'] ?? ''));
        $field_label = isset($field['label']) && is_scalar($field['label'])
            ? sanitize_text_field((string) $field['label'])
            : $field_name;
        $post_types = $this->filterPostTypes($this->normalizePostTypes(isset($field['post_type']) ? $field['post_type'] : []));
        if (empty($post_types)) {
            $post_types = $this->getDefaultReferencePostTypes();
        }
        $field_group = $this->resolveFieldGroupContext($field);
        $option_page_slug = ! empty($field_group['option_pages']) ? (string) reset($field_group['option_pages']) : '';
        $option_page_label = $this->resolveOptionPageLabel($option_page_slug);
        $selected_ids = $this->normalizeReferenceIds(get_field($field_name, 'option', false));
        $is_multiple = $field_type === 'relationship' || ! empty($field['multiple']);
        $token = $this->createToolbarToken($session_id, $field_name, $field_key);
        $contract = $field_type === 'relationship'
            ? 'shared_relationship_collection'
            : 'shared_post_object_collection';

        return new EditableDescriptor(
            $token,
            'editable',
            'shared_entity',
            [
                'type' => 'option',
                'id' => 0,
                'subtype' => 'acf_options',
                'acf_object_id' => 'option',
                'option_page_slug' => $option_page_slug,
                'option_page_label' => $option_page_label,
            ],
            [
                'context' => 'query_collection',
                'attribute' => 'toolbar_shared_global',
                'element_id' => 'toolbar-shared-global-' . $field_name,
                'display_key' => 'default',
                'sync_group' => 'option:' . $field_name,
                'source_group' => 'option:' . $field_name,
            ],
            [
                'type' => 'acf_collection_field',
                'source_context' => 'toolbar_shared_global_option',
                'field_name' => $field_name,
                'field_selector' => $field_name,
                'field_selector_raw' => $field_name,
                'field_key' => $field_key,
                'leaf_field_name' => $field_name,
                'leaf_field_key' => $field_key,
                'field_type' => $field_type,
                'field_group_key' => isset($field_group['key']) ? sanitize_key((string) $field_group['key']) : '',
                'field_group_title' => isset($field_group['title']) ? sanitize_text_field((string) $field_group['title']) : '',
                'field_group_option_pages' => isset($field_group['option_pages']) && is_array($field_group['option_pages']) ? $field_group['option_pages'] : [],
                'reference_post_types' => $post_types,
                'reference_multiple' => $is_multiple,
                'reference_min' => $this->resolveReferenceMin($field),
                'reference_max' => $this->resolveReferenceMax($field, $is_multiple),
                'query_collection_write_mode' => 'replace_full_collection',
                'query_result_ids' => $selected_ids,
                'query_full_value_ids' => $selected_ids,
                'query_preserved_ids' => [],
                'query_result_empty' => empty($selected_ids),
            ],
            [
                'label' => $field_label,
                'badgeLabel' => __('Shared Global', 'dbvc'),
                'input' => 'reference_collection',
            ],
            [
                'name' => 'acf_reference_collection',
            ],
            $page_context,
            [
                'type' => 'option',
                'id' => 0,
                'subtype' => 'acf_options',
                'scope' => 'shared_entity',
                'isCurrentPageEntity' => false,
                'isLoopOwned' => false,
                'pageEntityId' => isset($page_context['entityId']) ? absint($page_context['entityId']) : 0,
            ],
            [],
            [
                'fieldName' => $field_name,
                'fieldKey' => $field_key,
                'rootFieldName' => $field_name,
                'rootFieldKey' => $field_key,
            ],
            [
                'version' => 1,
                'kind' => 'collection',
                'target' => 'field',
                'contract' => $contract,
                'renderContext' => 'query_collection',
                'reloadAfterSave' => true,
            ]
        );
    }

    /**
     * @param EditableDescriptor    $descriptor
     * @param array<string, mixed>  $field
     * @param array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildFieldInventoryItem(EditableDescriptor $descriptor, array $field, array $payload)
    {
        $items = isset($payload['currentValue']) && is_array($payload['currentValue']) ? $payload['currentValue'] : [];

        return [
            'token' => $descriptor->token,
            'fieldName' => isset($descriptor->source['field_name']) ? sanitize_key((string) $descriptor->source['field_name']) : '',
            'fieldKey' => isset($descriptor->source['field_key']) ? sanitize_key((string) $descriptor->source['field_key']) : '',
            'fieldType' => isset($descriptor->source['field_type']) ? sanitize_key((string) $descriptor->source['field_type']) : '',
            'label' => isset($descriptor->ui['label']) ? sanitize_text_field((string) $descriptor->ui['label']) : '',
            'optionPages' => isset($descriptor->source['field_group_option_pages']) && is_array($descriptor->source['field_group_option_pages'])
                ? array_values(array_filter(array_map('sanitize_key', $descriptor->source['field_group_option_pages'])))
                : [],
            'fieldGroupTitle' => isset($descriptor->source['field_group_title']) ? sanitize_text_field((string) $descriptor->source['field_group_title']) : '',
            'itemCount' => count($items),
            'currentItems' => array_slice($items, 0, 20),
            'canEdit' => ! empty($payload['canEdit']),
            'configured' => true,
            'multiple' => ! empty($descriptor->source['reference_multiple']),
            'postTypes' => $this->resolveFieldPostTypes($field),
        ];
    }

    /**
     * @param string $session_id
     * @param string $field_name
     * @param string $field_key
     * @return string
     */
    private function createToolbarToken($session_id, $field_name, $field_key)
    {
        return 've_' . substr(hash('sha256', sanitize_key((string) $session_id) . '|toolbar_shared_global|' . sanitize_key((string) $field_name) . '|' . sanitize_key((string) $field_key)), 0, 12);
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function normalizeReferenceIds($value)
    {
        $values = is_array($value) ? $value : [$value];
        $ids = [];

        foreach ($values as $item) {
            if (is_array($item) && isset($item['ID'])) {
                $id = absint($item['ID']);
            } elseif (is_array($item) && isset($item['id'])) {
                $id = absint($item['id']);
            } elseif (is_object($item) && isset($item->ID)) {
                $id = absint($item->ID);
            } elseif (is_object($item) && isset($item->id)) {
                $id = absint($item->id);
            } else {
                $id = absint($item);
            }

            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizePostTypes($value)
    {
        $values = is_array($value) ? $value : [$value];
        $post_types = [];

        foreach ($values as $item) {
            $post_type = sanitize_key((string) $item);
            if ($post_type !== '' && ! in_array($post_type, $post_types, true)) {
                $post_types[] = $post_type;
            }
        }

        return $post_types;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<int, string>
     */
    private function resolveFieldPostTypes(array $field)
    {
        $post_types = $this->filterPostTypes($this->normalizePostTypes(isset($field['post_type']) ? $field['post_type'] : []));

        return ! empty($post_types) ? $post_types : $this->getDefaultReferencePostTypes();
    }

    /**
     * @return array<int, string>
     */
    private function getDefaultReferencePostTypes()
    {
        return $this->filterPostTypes(
            array_values(
                array_filter(
                    get_post_types(
                        [
                            'public' => true,
                        ],
                        'names'
                    )
                )
            )
        );
    }

    /**
     * @param array<int, string> $post_types
     * @return array<int, string>
     */
    private function filterPostTypes(array $post_types)
    {
        if (class_exists('\DBVC_Visual_Editor_Addon') && method_exists('\DBVC_Visual_Editor_Addon', 'filter_post_types')) {
            return \DBVC_Visual_Editor_Addon::filter_post_types($post_types);
        }

        return array_values(array_filter(array_map('sanitize_key', $post_types)));
    }

    /**
     * @param array<string, mixed> $field
     * @return int
     */
    private function resolveReferenceMin(array $field)
    {
        return isset($field['min']) && is_numeric($field['min']) ? max(0, absint($field['min'])) : 0;
    }

    /**
     * @param array<string, mixed> $field
     * @param bool                 $is_multiple
     * @return int
     */
    private function resolveReferenceMax(array $field, $is_multiple)
    {
        if (! $is_multiple) {
            return 1;
        }

        return isset($field['max']) && is_numeric($field['max']) ? max(0, absint($field['max'])) : 0;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function resolveFieldGroupContext(array $field)
    {
        $group_key = $this->resolveFieldGroupKey($field);
        if ($group_key === '' || ! function_exists('acf_get_field_group')) {
            return [
                'key' => $group_key,
                'title' => '',
                'option_pages' => [],
            ];
        }

        $group = acf_get_field_group($group_key);
        if (! is_array($group)) {
            return [
                'key' => $group_key,
                'title' => '',
                'option_pages' => [],
            ];
        }

        return [
            'key' => isset($group['key']) ? sanitize_key((string) $group['key']) : $group_key,
            'title' => isset($group['title']) ? sanitize_text_field((string) $group['title']) : '',
            'option_pages' => $this->extractFieldGroupOptionPages($group),
        ];
    }

    /**
     * @param array<string, mixed> $field
     * @return string
     */
    private function resolveFieldGroupKey(array $field)
    {
        $parent = isset($field['parent']) ? sanitize_key((string) $field['parent']) : '';
        $seen = [];

        while ($parent !== '' && empty($seen[$parent])) {
            $seen[$parent] = true;

            if (strpos($parent, 'group_') === 0) {
                return $parent;
            }

            if (! function_exists('acf_get_field')) {
                break;
            }

            $parent_field = acf_get_field($parent);
            if (! is_array($parent_field) || empty($parent_field['parent'])) {
                break;
            }

            $parent = sanitize_key((string) $parent_field['parent']);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $group
     * @return array<int, string>
     */
    private function extractFieldGroupOptionPages(array $group)
    {
        $locations = isset($group['location']) && is_array($group['location']) ? $group['location'] : [];
        $slugs = [];

        foreach ($locations as $rules) {
            if (! is_array($rules)) {
                continue;
            }

            foreach ($rules as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                $param = isset($rule['param']) ? sanitize_key((string) $rule['param']) : '';
                $operator = isset($rule['operator']) ? (string) $rule['operator'] : '==';
                $value = isset($rule['value']) ? sanitize_key((string) $rule['value']) : '';

                if (! in_array($param, ['options_page', 'options_page_key'], true) || $value === '') {
                    continue;
                }

                if (! in_array($operator, ['==', '==='], true)) {
                    continue;
                }

                $slug = preg_replace('/^acf-options-/', '', $value);
                if (is_string($slug) && $slug !== '') {
                    $slugs[] = sanitize_key($slug);
                }
            }
        }

        return array_values(array_unique(array_filter($slugs)));
    }

    /**
     * @param string $slug
     * @return string
     */
    private function resolveOptionPageLabel($slug)
    {
        $slug = sanitize_key((string) $slug);
        if ($slug === '') {
            return __('Site Settings', 'dbvc');
        }

        if (function_exists('acf_get_options_pages')) {
            $pages = acf_get_options_pages();
            if (is_array($pages)) {
                foreach ($pages as $page) {
                    if (! is_array($page)) {
                        continue;
                    }

                    $menu_slug = ! empty($page['menu_slug']) ? sanitize_key((string) $page['menu_slug']) : '';
                    $normalized = preg_replace('/^acf-options-/', '', $menu_slug);
                    $normalized = is_string($normalized) ? sanitize_key($normalized) : '';

                    if ($menu_slug !== '' && ($menu_slug === $slug || $normalized === $slug)) {
                        if (! empty($page['menu_title'])) {
                            return sanitize_text_field((string) $page['menu_title']);
                        }

                        if (! empty($page['page_title'])) {
                            return sanitize_text_field((string) $page['page_title']);
                        }
                    }
                }
            }
        }

        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }
}
