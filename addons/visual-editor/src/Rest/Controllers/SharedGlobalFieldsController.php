<?php

namespace Dbvc\VisualEditor\Rest\Controllers;

use Dbvc\VisualEditor\Context\EditModeState;
use Dbvc\VisualEditor\Permissions\CapabilityManager;
use Dbvc\VisualEditor\Registry\EditableDescriptor;
use Dbvc\VisualEditor\Registry\EditableRegistry;
use Dbvc\VisualEditor\Registry\Providers\SharedGlobalsDescriptorFactory;
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

    /**
     * R3-C-1 — descriptor construction (and every helper it used to inline) was
     * extracted to {@see SharedGlobalsDescriptorFactory} so the new Brand Control
     * Center open route mints identical descriptors from the same ACF field
     * object. This controller's public route response is unchanged; the factory
     * is a lift, not a redesign.
     *
     * @var SharedGlobalsDescriptorFactory
     */
    private $descriptor_factory;

    public function __construct(EditableRegistry $registry, EditModeState $edit_mode, CapabilityManager $capabilities, DescriptorPayloadBuilder $payloads)
    {
        $this->registry = $registry;
        $this->edit_mode = $edit_mode;
        $this->capabilities = $capabilities;
        $this->payloads = $payloads;
        $this->descriptor_factory = new SharedGlobalsDescriptorFactory();
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

            $descriptor = $this->descriptor_factory->build($session_id, $page_context, $field);
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
            'postTypes' => $this->descriptor_factory->resolveFieldPostTypes($field),
        ];
    }
}
