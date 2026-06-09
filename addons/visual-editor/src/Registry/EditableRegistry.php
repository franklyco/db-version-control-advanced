<?php

namespace Dbvc\VisualEditor\Registry;

use Dbvc\VisualEditor\Context\PageContextResolver;
use Dbvc\VisualEditor\Performance\PerformanceProfiler;

final class EditableRegistry
{
    private const TRANSIENT_PREFIX = 'dbvc_visual_editor_session_';
    private const DEFAULT_SESSION_TTL = 28800;
    private const MIN_SESSION_TTL = 300;
    private const MAX_SESSION_TTL = 172800;
    private const DESCRIPTOR_STORAGE_GZIP_JSON_BASE64 = 'gzip_json_base64_v1';

    /**
     * @var PageContextResolver
     */
    private $page_context;

    /**
     * @var PerformanceProfiler|null
     */
    private $profiler;

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

    public function __construct(PageContextResolver $page_context, ?PerformanceProfiler $profiler = null)
    {
        $this->page_context = $page_context;
        $this->profiler = $profiler;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return void
     */
    public function add(EditableDescriptor $descriptor)
    {
        if ($this->isDescriptorExcluded($descriptor)) {
            if ($this->profiler instanceof PerformanceProfiler) {
                $this->profiler->increment('registry.descriptors.excluded', 1, [
                    'status' => isset($descriptor->status) ? (string) $descriptor->status : 'unknown',
                    'scope' => isset($descriptor->scope) ? (string) $descriptor->scope : 'unknown',
                ]);
            }

            return;
        }

        $this->descriptors[$descriptor->token] = $descriptor;
        $this->getSessionId();

        if ($this->profiler instanceof PerformanceProfiler) {
            $source = isset($descriptor->source['type']) ? sanitize_key((string) $descriptor->source['type']) : 'unknown';
            $this->profiler->increment('registry.descriptors.added', 1, [
                'status' => isset($descriptor->status) ? (string) $descriptor->status : 'unknown',
                'scope' => isset($descriptor->scope) ? (string) $descriptor->scope : 'unknown',
                'source' => $source !== '' ? $source : 'unknown',
            ]);
            $this->profiler->recordValue('registry.descriptors.active', count($this->descriptors));
        }
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

        if ($this->profiler instanceof PerformanceProfiler) {
            $this->profiler->increment('registry.descriptors.removed');
            $this->profiler->recordValue('registry.descriptors.active', count($this->descriptors));
        }
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

        $started_at = $this->profiler instanceof PerformanceProfiler && $this->profiler->isEnabled()
            ? $this->profiler->startTimer()
            : 0.0;
        $page_context = $this->page_context->resolve();
        if (empty($page_context['isSupported'])) {
            return;
        }

        $filter_started_at = $this->startProfileTimer();
        $persisted_descriptors = $this->filterExcludedDescriptors($this->descriptors);
        $this->recordProfileDuration('registry.persist.filter_descriptors', $filter_started_at);

        $public_map_started_at = $this->startProfileTimer();
        $public_map = $this->exportPublicMap($persisted_descriptors);
        $this->recordProfileDuration('registry.persist.export_public_map', $public_map_started_at);

        $descriptor_payload_started_at = $this->startProfileTimer();
        $descriptor_payloads = array_map(
            static function (EditableDescriptor $descriptor) {
                return $descriptor->toArray();
            },
            $persisted_descriptors
        );
        $this->recordProfileDuration('registry.persist.export_descriptors', $descriptor_payload_started_at);

        $encoding_started_at = $this->startProfileTimer();
        $encoded_descriptors = $this->encodeSessionDescriptors($descriptor_payloads);
        $this->recordProfileDuration('registry.persist.encode_descriptors', $encoding_started_at);

        $payload = [
            'session_id' => $this->getSessionId(),
            'user_id' => get_current_user_id(),
            'page_context' => $page_context,
            'public_map' => $public_map,
            'created_at' => time(),
        ];
        if (! empty($encoded_descriptors)) {
            $payload = array_merge($payload, $encoded_descriptors);
        } else {
            $payload['descriptors'] = $descriptor_payloads;
        }

        $write_started_at = $this->startProfileTimer();
        set_transient($this->getTransientKey($this->session_id), $payload, $this->getSessionTtl());
        $this->recordProfileDuration('registry.persist.set_transient', $write_started_at);

        if ($this->profiler instanceof PerformanceProfiler && $started_at > 0) {
            $this->profiler->recordDuration('registry.persist_session', $started_at);
            $this->profiler->recordValue('registry.persisted_descriptors', count($persisted_descriptors));
            $this->profiler->recordValue('registry.public_map_entries', count($public_map));
            $this->recordSessionPayloadProfileValues($payload, $descriptor_payloads, $public_map);
        }
    }

    /**
     * @param string $session_id
     * @param bool   $include_descriptors
     * @return array<string, mixed>
     */
    public function loadSession($session_id, $include_descriptors = true)
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

        $this->maybeRefreshSessionTtl($session_id, $payload);
        if ($include_descriptors) {
            $payload['descriptors'] = $this->extractSessionDescriptors($payload);
        } else {
            unset($payload['descriptors']);
        }

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
     * @return int
     */
    private function getSessionRefreshInterval()
    {
        $ttl = $this->getSessionTtl();
        $default = min(300, max(30, (int) floor($ttl / 4)));
        $interval = (int) apply_filters('dbvc_visual_editor_session_refresh_interval', $default);
        $max_interval = max(30, (int) floor($ttl / 2));

        if ($interval < 30) {
            return 30;
        }

        if ($interval > $max_interval) {
            return $max_interval;
        }

        return $interval;
    }

    /**
     * @return float
     */
    private function startProfileTimer()
    {
        return $this->profiler instanceof PerformanceProfiler && $this->profiler->isEnabled()
            ? $this->profiler->startTimer()
            : 0.0;
    }

    /**
     * @param string $name
     * @param float  $started_at
     * @return void
     */
    private function recordProfileDuration($name, $started_at)
    {
        if (! ($this->profiler instanceof PerformanceProfiler) || ! $this->profiler->isEnabled()) {
            return;
        }

        $this->profiler->recordDuration((string) $name, $started_at);
    }

    /**
     * @param string               $session_id
     * @param array<string, mixed> $payload
     * @return void
     */
    private function maybeRefreshSessionTtl($session_id, array &$payload)
    {
        $session_id = $this->normalizeSessionId($session_id);
        if ($session_id === '') {
            return;
        }

        $now = time();
        $last_refresh = isset($payload['refreshed_at']) ? absint($payload['refreshed_at']) : 0;
        if ($last_refresh <= 0) {
            $last_refresh = isset($payload['created_at']) ? absint($payload['created_at']) : 0;
        }

        if ($last_refresh > 0 && ($now - $last_refresh) < $this->getSessionRefreshInterval()) {
            return;
        }

        $payload['refreshed_at'] = $now;
        set_transient($this->getTransientKey($session_id), $payload, $this->getSessionTtl());
    }

    /**
     * @param string $session_id
     * @param string $token
     * @return EditableDescriptor|null
     */
    public function getDescriptorFromSession($session_id, $token)
    {
        $session = $this->loadSession($session_id, true);
        $descriptors = isset($session['descriptors']) && is_array($session['descriptors']) ? $session['descriptors'] : [];
        $token = sanitize_key((string) $token);

        if ($token === '' || ! isset($descriptors[$token]) || ! is_array($descriptors[$token])) {
            return null;
        }

        $descriptor = EditableDescriptor::fromArray($descriptors[$token]);

        return $this->isDescriptorExcluded($descriptor) ? null : $descriptor;
    }

    /**
     * @param string $session_id
     * @return array<string, EditableDescriptor>
     */
    public function getDescriptorsFromSession($session_id)
    {
        $session = $this->loadSession($session_id, true);
        $descriptors = isset($session['descriptors']) && is_array($session['descriptors']) ? $session['descriptors'] : [];
        $resolved = [];

        foreach ($descriptors as $token => $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $descriptor = EditableDescriptor::fromArray($payload);
            if ($this->isDescriptorExcluded($descriptor)) {
                continue;
            }

            $resolved[sanitize_key((string) $token)] = $descriptor;
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
        if ($session_id === '' || $descriptor->token === '' || $this->isDescriptorExcluded($descriptor)) {
            return false;
        }

        $payload = $this->loadSession($session_id, true);
        if (empty($payload)) {
            return false;
        }

        $descriptors = isset($payload['descriptors']) && is_array($payload['descriptors']) ? $payload['descriptors'] : [];
        if (! isset($descriptors[$descriptor->token])) {
            return false;
        }

        $descriptors[$descriptor->token] = $descriptor->toArray();
        $resolved = [];

        foreach ($descriptors as $token => $item) {
            if (! is_array($item)) {
                continue;
            }

            $resolved_descriptor = EditableDescriptor::fromArray($item);
            if ($this->isDescriptorExcluded($resolved_descriptor)) {
                continue;
            }

            $resolved[sanitize_key((string) $token)] = $resolved_descriptor;
        }

        $payload['public_map'] = $this->exportPublicMap($resolved);
        $this->storeSessionDescriptors($payload, $descriptors);

        set_transient($this->getTransientKey($session_id), $payload, $this->getSessionTtl());

        return true;
    }

    /**
     * @param string             $session_id
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    public function addDescriptorToSession($session_id, EditableDescriptor $descriptor)
    {
        $session_id = $this->normalizeSessionId($session_id);
        if ($session_id === '' || $descriptor->token === '' || $this->isDescriptorExcluded($descriptor)) {
            return false;
        }

        $payload = $this->loadSession($session_id, true);
        if (empty($payload)) {
            return false;
        }

        $descriptors = isset($payload['descriptors']) && is_array($payload['descriptors']) ? $payload['descriptors'] : [];
        $descriptors[$descriptor->token] = $descriptor->toArray();
        $resolved = [];

        foreach ($descriptors as $token => $item) {
            if (! is_array($item)) {
                continue;
            }

            $resolved_descriptor = EditableDescriptor::fromArray($item);
            if ($this->isDescriptorExcluded($resolved_descriptor)) {
                continue;
            }

            $resolved[sanitize_key((string) $token)] = $resolved_descriptor;
        }

        $payload['public_map'] = $this->exportPublicMap($resolved);
        $this->storeSessionDescriptors($payload, $descriptors);

        set_transient($this->getTransientKey($session_id), $payload, $this->getSessionTtl());

        return true;
    }

    /**
     * @param array<string, array<string, mixed>> $descriptors
     * @return array<string, mixed>
     */
    private function encodeSessionDescriptors(array $descriptors)
    {
        if (! function_exists('gzencode') || empty($descriptors)) {
            return [];
        }

        $json = wp_json_encode($descriptors);
        if (! is_string($json) || $json === '') {
            return [];
        }

        $compressed = gzencode($json, $this->getDescriptorCompressionLevel());
        if (! is_string($compressed) || $compressed === '') {
            return [];
        }

        return [
            'descriptor_storage' => self::DESCRIPTOR_STORAGE_GZIP_JSON_BASE64,
            'descriptor_count' => count($descriptors),
            'descriptors_blob' => base64_encode($compressed),
        ];
    }

    /**
     * @return int
     */
    private function getDescriptorCompressionLevel()
    {
        $level = (int) apply_filters('dbvc_visual_editor_session_descriptor_compression_level', 4);

        if ($level < 1) {
            return 1;
        }

        if ($level > 9) {
            return 9;
        }

        return $level;
    }

    /**
     * @param array<string, mixed>                $payload
     * @param array<string, array<string, mixed>> $descriptor_payloads
     * @param array<string, array<string, mixed>> $public_map
     * @return void
     */
    private function recordSessionPayloadProfileValues(array $payload, array $descriptor_payloads, array $public_map)
    {
        if (! ($this->profiler instanceof PerformanceProfiler) || ! $this->profiler->isEnabled()) {
            return;
        }

        $payload_json_started_at = $this->profiler->startTimer();
        $payload_json = wp_json_encode($payload);
        $this->profiler->recordDuration('registry.persist.profile_payload_json', $payload_json_started_at);
        $this->profiler->recordValue('registry.session_payload_bytes', is_string($payload_json) ? strlen($payload_json) : 0);

        $descriptor_json_started_at = $this->profiler->startTimer();
        $descriptor_json = wp_json_encode($descriptor_payloads);
        $this->profiler->recordDuration('registry.persist.profile_descriptor_json', $descriptor_json_started_at);
        $this->profiler->recordValue('registry.session_descriptor_bytes', is_string($descriptor_json) ? strlen($descriptor_json) : 0);

        $public_map_json_started_at = $this->profiler->startTimer();
        $public_map_json = wp_json_encode($public_map);
        $this->profiler->recordDuration('registry.persist.profile_public_map_json', $public_map_json_started_at);
        $this->profiler->recordValue('registry.session_public_map_bytes', is_string($public_map_json) ? strlen($public_map_json) : 0);
        $this->profiler->recordValue('registry.session_descriptor_blob_bytes', isset($payload['descriptors_blob']) && is_string($payload['descriptors_blob']) ? strlen($payload['descriptors_blob']) : 0);
        $this->profiler->recordValue('registry.session_descriptor_compression_level', $this->getDescriptorCompressionLevel());
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, array<string, mixed>>
     */
    private function extractSessionDescriptors(array $payload)
    {
        if (isset($payload['descriptors']) && is_array($payload['descriptors'])) {
            return $payload['descriptors'];
        }

        $storage = isset($payload['descriptor_storage']) ? sanitize_key((string) $payload['descriptor_storage']) : '';
        $blob = isset($payload['descriptors_blob']) && is_string($payload['descriptors_blob']) ? $payload['descriptors_blob'] : '';
        if ($storage !== self::DESCRIPTOR_STORAGE_GZIP_JSON_BASE64 || $blob === '' || ! function_exists('gzdecode')) {
            return [];
        }

        $compressed = base64_decode($blob, true);
        if (! is_string($compressed) || $compressed === '') {
            return [];
        }

        $json = gzdecode($compressed);
        if (! is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_filter(
            $decoded,
            static function ($item) {
                return is_array($item);
            }
        );
    }

    /**
     * @param array<string, mixed>                $payload
     * @param array<string, array<string, mixed>> $descriptors
     * @return void
     */
    private function storeSessionDescriptors(array &$payload, array $descriptors)
    {
        unset(
            $payload['descriptors'],
            $payload['descriptor_storage'],
            $payload['descriptor_count'],
            $payload['descriptors_blob']
        );

        $encoded = $this->encodeSessionDescriptors($descriptors);
        if (! empty($encoded)) {
            $payload = array_merge($payload, $encoded);

            return;
        }

        $payload['descriptors'] = $descriptors;
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

            if ($this->isDescriptorExcluded($descriptor)) {
                continue;
            }

            $map[$token] = $this->compactPublicMapArray([
                'token' => (string) $token,
                'status' => (string) $descriptor->status,
                'scope' => (string) $descriptor->scope,
                'label' => isset($descriptor->ui['label']) ? (string) $descriptor->ui['label'] : __('Field', 'dbvc'),
                'badgeLabel' => isset($descriptor->ui['badgeLabel']) ? sanitize_text_field((string) $descriptor->ui['badgeLabel']) : '',
                'input' => isset($descriptor->ui['input']) ? (string) $descriptor->ui['input'] : 'text',
                'entity' => $this->exportPublicEntitySummary($descriptor),
                'index' => $this->exportPublicIndexSummary($descriptor),
            ]);
        }

        return $map;
    }

    /**
     * @param array<string|int, mixed> $values
     * @return array<string|int, mixed>
     */
    private function compactPublicMapArray(array $values)
    {
        $is_list = $this->isListArray($values);
        $compacted = [];

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $value = $this->compactPublicMapArray($value);
            }

            if ($this->shouldOmitPublicMapValue($value)) {
                continue;
            }

            $compacted[$key] = $value;
        }

        return $is_list ? array_values($compacted) : $compacted;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function shouldOmitPublicMapValue($value)
    {
        return $value === ''
            || $value === null
            || $value === false
            || (is_array($value) && empty($value));
    }

    /**
     * @param array<string|int, mixed> $values
     * @return bool
     */
    private function isListArray(array $values)
    {
        if (empty($values)) {
            return true;
        }

        return array_keys($values) === range(0, count($values) - 1);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<string, mixed>
     */
    private function exportPublicEntitySummary(EditableDescriptor $descriptor)
    {
        $entity = isset($descriptor->entity) && is_array($descriptor->entity) ? $descriptor->entity : [];
        $id = isset($entity['id']) ? absint($entity['id']) : 0;

        return [
            'type' => isset($entity['type']) ? sanitize_key((string) $entity['type']) : '',
            'id' => $id > 0 ? $id : null,
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
        $owner_id = isset($owner['id']) ? absint($owner['id']) : 0;
        $page_entity_id = isset($owner['pageEntityId']) ? absint($owner['pageEntityId']) : 0;

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
                'id' => $owner_id > 0 ? $owner_id : null,
                'subtype' => $this->sanitizeArrayKeyValue($owner, 'subtype'),
                'scope' => $this->sanitizeArrayKeyValue($owner, 'scope'),
                'isCurrentPageEntity' => ! empty($owner['isCurrentPageEntity']),
                'isLoopOwned' => ! empty($owner['isLoopOwned']),
                'pageEntityId' => $page_entity_id > 0 ? $page_entity_id : null,
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
     * @param array<string, EditableDescriptor> $descriptors
     * @return array<string, EditableDescriptor>
     */
    private function filterExcludedDescriptors(array $descriptors)
    {
        return array_filter(
            $descriptors,
            function ($descriptor) {
                return $descriptor instanceof EditableDescriptor && ! $this->isDescriptorExcluded($descriptor);
            }
        );
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    private function isDescriptorExcluded(EditableDescriptor $descriptor)
    {
        return class_exists('\DBVC_Visual_Editor_Addon')
            && method_exists('\DBVC_Visual_Editor_Addon', 'is_descriptor_excluded')
            && \DBVC_Visual_Editor_Addon::is_descriptor_excluded($descriptor);
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
