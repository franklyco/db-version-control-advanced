<?php

namespace Dbvc\VisualEditor\Registry;

use Dbvc\VisualEditor\Context\PageContextResolver;

final class EditableRegistry
{
    private const TRANSIENT_PREFIX = 'dbvc_visual_editor_session_';
    private const DEFAULT_SESSION_TTL = 28800;
    private const MIN_SESSION_TTL = 300;
    private const MAX_SESSION_TTL = 172800;

    /**
     * @var PageContextResolver
     */
    private $page_context;

    /**
     * @var array<string, EditableDescriptor>
     */
    private $descriptors = [];

    /**
     * @var string
     */
    private $session_id = '';

    /**
     * @var array<string, string>
     */
    private $public_entity_label_cache = [];

    public function __construct(PageContextResolver $page_context)
    {
        $this->page_context = $page_context;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return void
     */
    public function add(EditableDescriptor $descriptor)
    {
        $this->descriptors[$descriptor->token] = $descriptor;
        $this->getSessionId();
    }

    /**
     * @param string $token
     * @return void
     */
    public function remove($token)
    {
        $token = sanitize_key((string) $token);
        if ($token === '') {
            return;
        }

        unset($this->descriptors[$token]);
    }

    /**
     * @return string
     */
    public function getSessionId()
    {
        if ($this->session_id === '') {
            $this->session_id = 'ves_' . strtolower(wp_generate_password(12, false, false));
        }

        return $this->session_id;
    }

    /**
     * @param string $seed
     * @return string
     */
    public function createToken($seed)
    {
        return 've_' . substr(hash('sha256', $this->getSessionId() . '|' . (string) $seed), 0, 12);
    }

    /**
     * @return void
     */
    public function persistRequestSession()
    {
        if (empty($this->descriptors)) {
            return;
        }

        $page_context = $this->page_context->resolve();
        if (empty($page_context['isSupported'])) {
            return;
        }

        $payload = [
            'session_id' => $this->getSessionId(),
            'user_id' => get_current_user_id(),
            'page_context' => $page_context,
            'descriptors' => array_map(
                static function (EditableDescriptor $descriptor) {
                    return $descriptor->toArray();
                },
                $this->descriptors
            ),
            'public_map' => $this->exportPublicMap($this->descriptors),
            'created_at' => time(),
        ];

        set_transient($this->getTransientKey($this->session_id), $payload, $this->getSessionTtl());
    }

    /**
     * @param string $session_id
     * @return array<string, mixed>
     */
    public function loadSession($session_id)
    {
        $session_id = $this->normalizeSessionId($session_id);
        if ($session_id === '') {
            return [];
        }

        $payload = get_transient($this->getTransientKey($session_id));
        if (! is_array($payload)) {
            return [];
        }

        if ((int) ($payload['user_id'] ?? 0) !== get_current_user_id()) {
            return [];
        }

        set_transient($this->getTransientKey($session_id), $payload, $this->getSessionTtl());

        return $payload;
    }

    /**
     * @return int
     */
    public function getSessionTtl()
    {
        $ttl = (int) apply_filters('dbvc_visual_editor_session_ttl', self::DEFAULT_SESSION_TTL);

        if ($ttl < self::MIN_SESSION_TTL) {
            return self::MIN_SESSION_TTL;
        }

        if ($ttl > self::MAX_SESSION_TTL) {
            return self::MAX_SESSION_TTL;
        }

        return $ttl;
    }

    /**
     * @param string $session_id
     * @param string $token
     * @return EditableDescriptor|null
     */
    public function getDescriptorFromSession($session_id, $token)
    {
        $session = $this->loadSession($session_id);
        $descriptors = isset($session['descriptors']) && is_array($session['descriptors']) ? $session['descriptors'] : [];
        $token = sanitize_key((string) $token);

        if ($token === '' || ! isset($descriptors[$token]) || ! is_array($descriptors[$token])) {
            return null;
        }

        return EditableDescriptor::fromArray($descriptors[$token]);
    }

    /**
     * @param string $session_id
     * @return array<string, EditableDescriptor>
     */
    public function getDescriptorsFromSession($session_id)
    {
        $session = $this->loadSession($session_id);
        $descriptors = isset($session['descriptors']) && is_array($session['descriptors']) ? $session['descriptors'] : [];
        $resolved = [];

        foreach ($descriptors as $token => $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $resolved[sanitize_key((string) $token)] = EditableDescriptor::fromArray($payload);
        }

        return $resolved;
    }

    /**
     * @param string             $session_id
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    public function updateDescriptorInSession($session_id, EditableDescriptor $descriptor)
    {
        $session_id = $this->normalizeSessionId($session_id);
        if ($session_id === '' || $descriptor->token === '') {
            return false;
        }

        $payload = $this->loadSession($session_id);
        if (empty($payload)) {
            return false;
        }

        $descriptors = isset($payload['descriptors']) && is_array($payload['descriptors']) ? $payload['descriptors'] : [];
        if (! isset($descriptors[$descriptor->token])) {
            return false;
        }

        $descriptors[$descriptor->token] = $descriptor->toArray();
        $payload['descriptors'] = $descriptors;
        $resolved = [];

        foreach ($descriptors as $token => $item) {
            if (! is_array($item)) {
                continue;
            }

            $resolved[sanitize_key((string) $token)] = EditableDescriptor::fromArray($item);
        }

        $payload['public_map'] = $this->exportPublicMap($resolved);

        set_transient($this->getTransientKey($session_id), $payload, $this->getSessionTtl());

        return true;
    }

    /**
     * @param array<string, EditableDescriptor>|null $descriptors
     * @return array<string, array<string, mixed>>
     */
    public function exportPublicMap($descriptors = null)
    {
        $descriptors = is_array($descriptors) ? $descriptors : $this->descriptors;
        $map = [];

        foreach ($descriptors as $token => $descriptor) {
            if (! $descriptor instanceof EditableDescriptor) {
                continue;
            }

            $map[$token] = [
                'token' => (string) $token,
                'status' => (string) $descriptor->status,
                'scope' => (string) $descriptor->scope,
                'label' => isset($descriptor->ui['label']) ? (string) $descriptor->ui['label'] : __('Field', 'dbvc'),
                'badgeLabel' => isset($descriptor->ui['badgeLabel']) ? sanitize_text_field((string) $descriptor->ui['badgeLabel']) : '',
                'input' => isset($descriptor->ui['input']) ? (string) $descriptor->ui['input'] : 'text',
                'entity' => $this->exportPublicEntitySummary($descriptor),
                'index' => $this->exportPublicIndexSummary($descriptor),
            ];
        }

        return $map;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<string, mixed>
     */
    private function exportPublicEntitySummary(EditableDescriptor $descriptor)
    {
        $entity = isset($descriptor->entity) && is_array($descriptor->entity) ? $descriptor->entity : [];

        return [
            'type' => isset($entity['type']) ? sanitize_key((string) $entity['type']) : '',
            'id' => isset($entity['id']) ? absint($entity['id']) : 0,
            'subtype' => isset($entity['subtype']) ? sanitize_key((string) $entity['subtype']) : '',
            'label' => $this->resolvePublicEntityLabel($entity),
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<string, mixed>
     */
    private function exportPublicIndexSummary(EditableDescriptor $descriptor)
    {
        $source = isset($descriptor->source) && is_array($descriptor->source) ? $descriptor->source : [];
        $path = isset($descriptor->path) && is_array($descriptor->path) ? $descriptor->path : [];
        $owner = isset($descriptor->owner) && is_array($descriptor->owner) ? $descriptor->owner : [];
        $render = isset($descriptor->render) && is_array($descriptor->render) ? $descriptor->render : [];
        $row_index = $this->firstArrayValue($path, $source, 'rowIndex', 'row_index');

        return [
            'sourceType' => $this->sanitizeArrayKeyValue($source, 'type'),
            'sourceContext' => $this->sanitizeArrayKeyValue($source, 'source_context'),
            'fieldName' => $this->sanitizeArrayKeyValue($source, 'field_name'),
            'leafFieldName' => $this->sanitizeArrayKeyValue($source, 'leaf_field_name'),
            'fieldType' => $this->sanitizeArrayKeyValue($source, 'field_type'),
            'querySource' => $this->sanitizeArrayKeyValue($source, 'query_source'),
            'queryElementId' => $this->sanitizeFirstArrayKeyValue($source, $render, 'query_element_id', 'element_id'),
            'queryTargetPostType' => $this->sanitizeArrayKeyValue($source, 'query_target_post_type'),
            'queryResultEmpty' => ! empty($source['query_result_empty']),
            'fieldGroupTitle' => $this->sanitizeArrayTextValue($source, 'field_group_title'),
            'fieldGroupOptionPages' => $this->sanitizeKeyList(isset($source['field_group_option_pages']) && is_array($source['field_group_option_pages']) ? $source['field_group_option_pages'] : []),
            'parentFieldName' => $this->sanitizeFirstArrayKeyValue($path, $source, 'rootFieldName', 'parent_field_name'),
            'containerType' => $this->sanitizeFirstArrayKeyValue($path, $source, 'containerType', 'container_type'),
            'layoutName' => $this->sanitizeFirstArrayKeyValue($path, $source, 'layoutName', 'layout_name'),
            'layoutKey' => $this->sanitizeFirstArrayKeyValue($path, $source, 'layoutKey', 'layout_key'),
            'groupPath' => $this->sanitizeKeyList($this->firstArrayList($path, $source, 'groupPath', 'group_path')),
            'nativeQueryKind' => $this->sanitizeFirstArrayKeyValue($path, $source, 'nativeQueryKind', 'native_query_kind'),
            'nativeQuerySelector' => $this->sanitizeFirstArrayKeyValue($path, $source, 'nativeQuerySelector', 'native_query_selector'),
            'nativeQueryObjectType' => $this->sanitizeFirstArrayKeyValue($path, $source, 'nativeQueryObjectType', 'native_query_object_type'),
            'parentNativeQueryKind' => $this->sanitizeFirstArrayKeyValue($path, $source, 'parentNativeQueryKind', 'parent_native_query_kind'),
            'parentNativeQuerySelector' => $this->sanitizeFirstArrayKeyValue($path, $source, 'parentNativeQuerySelector', 'parent_native_query_selector'),
            'nativeQueryAncestry' => $this->sanitizeNativeQueryAncestry($this->firstArrayList($path, $source, 'nativeQueryAncestry', 'native_query_ancestry')),
            'rowIndex' => is_numeric($row_index) ? absint($row_index) : null,
            'renderContext' => $this->sanitizeArrayKeyValue($render, 'context'),
            'renderAttribute' => $this->sanitizeArrayTextValue($render, 'attribute'),
            'owner' => [
                'type' => $this->sanitizeArrayKeyValue($owner, 'type'),
                'id' => isset($owner['id']) ? absint($owner['id']) : 0,
                'subtype' => $this->sanitizeArrayKeyValue($owner, 'subtype'),
                'scope' => $this->sanitizeArrayKeyValue($owner, 'scope'),
                'isCurrentPageEntity' => ! empty($owner['isCurrentPageEntity']),
                'isLoopOwned' => ! empty($owner['isLoopOwned']),
                'pageEntityId' => isset($owner['pageEntityId']) ? absint($owner['pageEntityId']) : 0,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $fallback
     * @param string               $primary_key
     * @param string               $fallback_key
     * @return mixed
     */
    private function firstArrayValue(array $primary, array $fallback, $primary_key, $fallback_key)
    {
        if (array_key_exists($primary_key, $primary) && $primary[$primary_key] !== '' && $primary[$primary_key] !== null) {
            return $primary[$primary_key];
        }

        if (array_key_exists($fallback_key, $fallback) && $fallback[$fallback_key] !== '' && $fallback[$fallback_key] !== null) {
            return $fallback[$fallback_key];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entity
     * @return string
     */
    private function resolvePublicEntityLabel(array $entity)
    {
        $type = isset($entity['type']) ? sanitize_key((string) $entity['type']) : '';
        $id = isset($entity['id']) ? absint($entity['id']) : 0;
        $subtype = isset($entity['subtype']) ? sanitize_key((string) $entity['subtype']) : '';
        $cache_key = $type . ':' . $subtype . ':' . $id;

        if (isset($this->public_entity_label_cache[$cache_key])) {
            return $this->public_entity_label_cache[$cache_key];
        }

        $label = '';

        if ($type === 'post' && $id > 0) {
            $title = get_the_title($id);
            $label = is_string($title) ? sanitize_text_field($title) : '';
        } elseif ($type === 'term' && $id > 0) {
            $term = $subtype !== '' ? get_term($id, $subtype) : get_term($id);
            if ($term && ! is_wp_error($term) && isset($term->name)) {
                $label = sanitize_text_field((string) $term->name);
            }
        } elseif ($type === 'user' && $id > 0) {
            $user = get_userdata($id);
            if ($user && isset($user->display_name)) {
                $label = sanitize_text_field((string) $user->display_name);
            }
        } elseif ($type === 'option') {
            $label = __('Site Settings', 'dbvc');
        }

        $this->public_entity_label_cache[$cache_key] = $label;

        return $label;
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $fallback
     * @param string               $primary_key
     * @param string               $fallback_key
     * @return array<int, mixed>
     */
    private function firstArrayList(array $primary, array $fallback, $primary_key, $fallback_key)
    {
        $value = $this->firstArrayValue($primary, $fallback, $primary_key, $fallback_key);

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param array<string, mixed> $values
     * @param string               $key
     * @return string
     */
    private function sanitizeArrayKeyValue(array $values, $key)
    {
        return isset($values[$key]) ? sanitize_key((string) $values[$key]) : '';
    }

    /**
     * @param array<string, mixed> $values
     * @param string               $key
     * @return string
     */
    private function sanitizeArrayTextValue(array $values, $key)
    {
        return isset($values[$key]) ? sanitize_text_field((string) $values[$key]) : '';
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $fallback
     * @param string               $primary_key
     * @param string               $fallback_key
     * @return string
     */
    private function sanitizeFirstArrayKeyValue(array $primary, array $fallback, $primary_key, $fallback_key)
    {
        $value = $this->firstArrayValue($primary, $fallback, $primary_key, $fallback_key);

        return $value !== null ? sanitize_key((string) $value) : '';
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, string>
     */
    private function sanitizeKeyList(array $values)
    {
        return array_values(
            array_filter(
                array_map(
                    static function ($value) {
                        return sanitize_key((string) $value);
                    },
                    $values
                )
            )
        );
    }

    /**
     * @param array<int, mixed> $ancestry
     * @return array<int, array<string, string>>
     */
    private function sanitizeNativeQueryAncestry(array $ancestry)
    {
        return array_values(
            array_filter(
                array_map(
                    static function ($item) {
                        if (! is_array($item)) {
                            return null;
                        }

                        $kind = isset($item['kind']) ? sanitize_key((string) $item['kind']) : '';
                        $selector = isset($item['selector']) ? sanitize_key((string) $item['selector']) : '';
                        $object_type = isset($item['objectType']) ? sanitize_key((string) $item['objectType']) : '';
                        $loop_index = isset($item['loopIndex']) ? sanitize_text_field((string) $item['loopIndex']) : '';

                        if ($kind === '' && $selector === '' && $object_type === '') {
                            return null;
                        }

                        return [
                            'kind' => $kind,
                            'selector' => $selector,
                            'objectType' => $object_type,
                            'loopIndex' => $loop_index,
                        ];
                    },
                    $ancestry
                )
            )
        );
    }

    /**
     * @param string $session_id
     * @return string
     */
    private function getTransientKey($session_id)
    {
        return self::TRANSIENT_PREFIX . $this->normalizeSessionId($session_id);
    }

    /**
     * @param string $session_id
     * @return string
     */
    private function normalizeSessionId($session_id)
    {
        return sanitize_key((string) $session_id);
    }
}
