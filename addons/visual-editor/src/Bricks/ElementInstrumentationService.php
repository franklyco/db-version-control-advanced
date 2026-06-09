<?php

namespace Dbvc\VisualEditor\Bricks;

use Dbvc\VisualEditor\Context\PageContextResolver;
use Dbvc\VisualEditor\Registry\EditableDescriptor;
use Dbvc\VisualEditor\Registry\EditableRegistry;
use Dbvc\VisualEditor\Resolvers\ResolverRegistry;

final class ElementInstrumentationService
{
    private const MISMATCH_WARNING = 'Rendered output did not match the resolved current-entity source value.';

    /**
     * @var EditableRegistry
     */
    private $registry;

    /**
     * @var PageContextResolver
     */
    private $page_context;

    /**
     * @var ResolverRegistry
     */
    private $resolvers;

    /**
     * @var DynamicDataInspector
     */
    private $inspector;

    /**
     * @var LoopContextResolver
     */
    private $loops;

    /**
     * @var array<string, string>
     */
    private $instrumented = [];

    /**
     * @var array<string, int>
     */
    private $seed_occurrences = [];

    /**
     * @var array<string, EditableDescriptor>
     */
    private $descriptors = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private $post_query_vars_by_element = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private $element_metadata_by_id = [];

    public function __construct(EditableRegistry $registry, PageContextResolver $page_context, ResolverRegistry $resolvers, ?LoopContextResolver $loops = null)
    {
        $this->registry = $registry;
        $this->page_context = $page_context;
        $this->resolvers = $resolvers;
        $this->inspector = new DynamicDataInspector();
        $this->loops = $loops instanceof LoopContextResolver ? $loops : new LoopContextResolver();
    }

    /**
     * @param array<string, mixed> $attributes
     * @param string               $key
     * @param object               $element
     * @return array<string, mixed>
     */
    public function instrumentAttributes($attributes, $key, $element)
    {
        if (! is_array($attributes) || ! is_string($key) || $key === '') {
            return is_array($attributes) ? $attributes : [];
        }

        $this->rememberElementMetadata($element);
        $this->rememberNativeQueryElement($element);

        $collection_inspection = $this->inspectCollectionLoopRoot($attributes, $key, $element);
        if (! empty($collection_inspection['supported'])) {
            return $this->instrumentInspection($attributes, $key, $element, $collection_inspection);
        }

        $inspection = $this->inspector->inspectForAttribute($element, $key);
        if (empty($inspection['supported'])) {
            return $attributes;
        }

        if (! $this->shouldInstrumentAttributeGroup($attributes, $key, $inspection)) {
            return $attributes;
        }

        return $this->instrumentInspection($attributes, $key, $element, $inspection);
    }

    /**
     * Capture the final Bricks post-query arguments so derived custom-query loops can be
     * mapped back to a current-owner ACF relationship/post_object field without parsing PHP.
     *
     * @param mixed  $query_vars
     * @param mixed  $settings
     * @param string $element_id
     * @param string $element_name
     * @return mixed
     */
    public function capturePostQueryVars($query_vars, $settings = [], $element_id = '', $element_name = '')
    {
        if (! is_array($query_vars)) {
            return $query_vars;
        }

        $element_id = sanitize_text_field((string) $element_id);
        if ($element_id === '') {
            return $query_vars;
        }

        $summary = $this->buildPostQueryVarsSummary($query_vars, is_array($settings) ? $settings : []);
        if (empty($summary)) {
            return $query_vars;
        }

        foreach ($this->buildElementIdAliases($element_id) as $alias) {
            $this->post_query_vars_by_element[$alias] = $summary;
        }

        if (! empty($summary['query_result_empty'])) {
            $this->registerSyntheticEmptyDerivedPostCollectionDescriptor($summary, $element_id, $element_name);
        }

        return $query_vars;
    }

    /**
     * Fully empty Bricks loops may only render loop-start/end comments, which means the
     * render-attributes hook never creates a marker. Register a descriptor from proven
     * query-vars evidence so finalizeRenderedData() can inject a hidden comment anchor.
     *
     * @param array<string, mixed> $query_summary
     * @param string               $element_id
     * @param string               $element_name
     * @return void
     */
    private function registerSyntheticEmptyDerivedPostCollectionDescriptor(array $query_summary, $element_id, $element_name)
    {
        $element_id = sanitize_text_field((string) $element_id);
        if ($element_id === '') {
            return;
        }

        $target_post_type = isset($query_summary['post_type']) ? sanitize_key((string) $query_summary['post_type']) : '';
        if ($target_post_type === '' || $target_post_type === 'any') {
            return;
        }

        $query_result_ids = isset($query_summary['post__in']) && is_array($query_summary['post__in'])
            ? array_values(array_filter(array_map('absint', $query_summary['post__in'])))
            : [];
        if (! empty($query_result_ids)) {
            return;
        }

        $query_vars = array_merge($query_summary, ['query_result_empty' => true]);
        $element_label = $this->normalizeElementLabel((string) $element_name);
        $inspection = [
            'supported' => true,
            'source_type' => 'acf_collection_field',
            'query_source' => 'derived_bricks_query',
            'expression' => 'query.derived:' . $element_id,
            'setting_key' => 'query',
            'render_context' => 'query_collection',
            'render_attribute' => '',
            'query_object_type' => 'post',
            'query_element_id' => $element_id,
            'query_element_label' => $element_label,
            'query_section_label' => '',
            'query_badge_subject' => $element_label,
            'target_post_type' => $target_post_type,
            'query_result_post_types' => [],
            'query_result_ids' => [],
            'query_result_empty' => true,
            'query_vars' => $query_vars,
        ];

        $this->registerSyntheticEmptyQueryCollectionDescriptor($inspection, $element_id, $element_id, $element_name, '_empty_query', '');
    }

    /**
     * Register a query-collection descriptor when Bricks rendered no loop root markup.
     * Callers must provide inspection evidence from saved Bricks query settings or final
     * query vars; classification still proves the writable source before registration.
     *
     * @param array<string, mixed> $inspection
     * @param string               $element_id
     * @param string               $element_uid
     * @param string               $element_name
     * @param string               $attribute_key
     * @param string               $seed_signature
     * @return void
     */
    private function registerSyntheticEmptyQueryCollectionDescriptor(array $inspection, $element_id, $element_uid, $element_name, $attribute_key = '_empty_query', $seed_signature = '')
    {
        $element_id = sanitize_text_field((string) $element_id);
        if ($element_id === '') {
            return;
        }

        $page_context = $this->page_context->resolve();
        if (empty($page_context['isSupported'])) {
            return;
        }

        $classification = $this->resolvers->classifyCandidate($inspection, $page_context);
        $status = isset($classification['status']) ? (string) $classification['status'] : 'unsupported';
        if (! in_array($status, ['editable', 'readonly'], true)) {
            return;
        }

        $source = isset($classification['source']) && is_array($classification['source']) ? $classification['source'] : [];
        if (empty($source['query_result_empty'])) {
            return;
        }

        $entity_id = isset($page_context['entityId']) ? absint($page_context['entityId']) : 0;
        $entity = isset($classification['entity']) && is_array($classification['entity']) && ! empty($classification['entity'])
            ? $classification['entity']
            : [
                'type' => 'post',
                'id' => $entity_id,
                'subtype' => isset($page_context['postType']) ? (string) $page_context['postType'] : '',
                'acf_object_id' => $entity_id,
            ];
        $loop_context = isset($classification['loop']) && is_array($classification['loop']) ? $classification['loop'] : [];
        $scope = isset($classification['scope']) ? (string) $classification['scope'] : 'current_entity';
        $source_group = $this->buildSourceGroup($entity, $source);
        $sync_group = $this->buildSyncGroup($entity, $source, 'query_collection');
        $page_descriptor = $this->buildPageDescriptor($page_context);
        $owner_descriptor = $this->buildOwnerDescriptor($entity, $scope, $page_descriptor, $loop_context);
        $path_descriptor = $this->buildPathDescriptor($source);
        $mutation_descriptor = $this->buildMutationDescriptor($source, 'query_collection', $scope, $status, $path_descriptor, $loop_context);
        $loop_signature = $seed_signature !== ''
            ? sanitize_text_field((string) $seed_signature)
            : $this->loops->getSignature($loop_context);
        $element_uid = sanitize_text_field((string) $element_uid);
        if ($element_uid === '') {
            $element_uid = $element_id;
        }

        $seed = implode('|', [
            $element_uid,
            sanitize_key((string) $element_name),
            isset($inspection['setting_key']) ? sanitize_key((string) $inspection['setting_key']) : 'query',
            isset($inspection['expression']) ? sanitize_text_field((string) $inspection['expression']) : '',
            $loop_signature,
        ]);
        $token = isset($this->instrumented[$seed]) ? $this->instrumented[$seed] : $this->registry->createToken($seed);
        $this->instrumented[$seed] = $token;

        if (isset($this->descriptors[$token])) {
            return;
        }

        $descriptor = new EditableDescriptor(
            $token,
            $status,
            $scope,
            $entity,
            [
                'template_id' => 0,
                'element_id' => $element_id,
                'element_uid' => $element_uid,
                'element_name' => sanitize_key((string) $element_name),
                'setting_key' => isset($inspection['setting_key']) ? sanitize_key((string) $inspection['setting_key']) : 'query',
                'attribute_key' => sanitize_text_field((string) $attribute_key),
                'context' => 'query_collection',
                'attribute' => '',
                'source_group' => $source_group,
                'sync_group' => $sync_group,
                'loop_signature' => $loop_signature,
                'loop' => $loop_context,
                'rendered_value' => '',
                'resolved_value' => '',
                'rendered_text' => '',
                'resolved_text' => '',
                'display_key' => 'default',
                'display_mode' => 'text',
                'render_verified' => true,
                'value_match' => true,
            ],
            $source,
            isset($classification['ui']) && is_array($classification['ui']) ? $classification['ui'] : [],
            isset($classification['resolver']) && is_array($classification['resolver']) ? $classification['resolver'] : [],
            $page_descriptor,
            $owner_descriptor,
            $loop_context,
            $path_descriptor,
            $mutation_descriptor
        );

        $this->registry->add($descriptor);
        $this->descriptors[$token] = $descriptor;
    }

    /**
     * @param object $element
     * @return void
     */
    private function rememberElementMetadata($element)
    {
        if (! is_object($element)) {
            return;
        }

        $settings = isset($element->settings) && is_array($element->settings) ? $element->settings : [];
        $label = isset($element->label) ? $this->normalizeElementLabel((string) $element->label) : '';
        $css_id = isset($settings['_cssId']) ? $this->normalizeElementLabel((string) $settings['_cssId']) : '';

        $metadata = [
            'id' => isset($element->id) ? sanitize_text_field((string) $element->id) : '',
            'parent' => $this->resolveElementParentId($element),
            'name' => isset($element->name) ? sanitize_key((string) $element->name) : '',
            'label' => $label,
            'css_id' => $css_id,
        ];

        foreach ($this->resolveElementIds($element) as $element_id) {
            $existing = isset($this->element_metadata_by_id[$element_id]) && is_array($this->element_metadata_by_id[$element_id])
                ? $this->element_metadata_by_id[$element_id]
                : [];

            $this->element_metadata_by_id[$element_id] = array_merge($metadata, array_filter($existing, static function ($value) {
                return $value !== '' && $value !== null;
            }));
        }
    }

    /**
     * @param string $raw_label
     * @return string
     */
    private function normalizeElementLabel($raw_label)
    {
        $label = sanitize_text_field((string) $raw_label);
        $label = preg_replace('/[_-]+/', ' ', $label);
        $label = is_string($label) ? preg_replace('/\s+/', ' ', trim($label)) : '';

        if (! is_string($label) || $label === '') {
            return '';
        }

        if (strtolower($label) === $label) {
            $label = ucwords($label);
        }

        return $label;
    }

    /**
     * @param object $element
     * @return array<string, string>
     */
    private function resolveCollectionBadgeLabelContext($element)
    {
        $fallback_label = '';
        $section_label = '';
        $visited = [];
        $current_id = isset($element->id) ? sanitize_text_field((string) $element->id) : '';

        for ($depth = 0; $depth < 12 && $current_id !== '' && empty($visited[$current_id]); $depth++) {
            $visited[$current_id] = true;
            $metadata = isset($this->element_metadata_by_id[$current_id]) && is_array($this->element_metadata_by_id[$current_id])
                ? $this->element_metadata_by_id[$current_id]
                : [];

            $label = isset($metadata['label']) && (string) $metadata['label'] !== ''
                ? (string) $metadata['label']
                : (isset($metadata['css_id']) ? (string) $metadata['css_id'] : '');
            $name = isset($metadata['name']) ? sanitize_key((string) $metadata['name']) : '';

            if ($fallback_label === '' && $label !== '') {
                $fallback_label = $label;
            }

            if ($name === 'section' && $label !== '') {
                $section_label = $label;
                break;
            }

            $current_id = isset($metadata['parent']) ? sanitize_text_field((string) $metadata['parent']) : '';
        }

        $subject = $section_label !== '' ? $section_label : $fallback_label;

        return [
            'section_label' => $section_label,
            'element_label' => $fallback_label,
            'badge_subject' => $subject,
        ];
    }

    /**
     * Bricks may clear the public parent property on frontend element instances,
     * but the raw template element array still carries the unprefixed parent ID.
     *
     * @param object $element
     * @return string
     */
    private function resolveElementParentId($element)
    {
        if (! is_object($element)) {
            return '';
        }

        if (! empty($element->parent)) {
            return sanitize_text_field((string) $element->parent);
        }

        $raw_element = isset($element->element) && is_array($element->element) ? $element->element : [];
        if (! empty($raw_element['parent'])) {
            return sanitize_text_field((string) $raw_element['parent']);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $attributes
     * @param string               $key
     * @param object               $element
     * @param array<string, mixed> $inspection
     * @return array<string, mixed>
     */
    private function instrumentInspection(array $attributes, $key, $element, array $inspection)
    {
        if (empty($inspection['supported'])) {
            return $attributes;
        }

        $page_context = $this->page_context->resolve();
        $entity_id = isset($page_context['entityId']) ? absint($page_context['entityId']) : 0;
        if (empty($page_context['isSupported'])) {
            return $attributes;
        }

        $classification = $this->resolvers->classifyCandidate($inspection, $page_context);
        $status = isset($classification['status']) ? (string) $classification['status'] : 'unsupported';
        if (! in_array($status, ['editable', 'readonly'], true)) {
            return $attributes;
        }

        $entity = isset($classification['entity']) && is_array($classification['entity']) && ! empty($classification['entity'])
            ? $classification['entity']
            : [
                'type' => 'post',
                'id' => $entity_id,
                'subtype' => isset($page_context['postType']) ? (string) $page_context['postType'] : '',
                'acf_object_id' => $entity_id,
            ];
        $loop_context = isset($classification['loop']) && is_array($classification['loop']) ? $classification['loop'] : [];
        $source = isset($classification['source']) && is_array($classification['source']) ? $classification['source'] : [];
        $rendered_post_id = $this->page_context->resolveRenderedPostId();
        if ($rendered_post_id > 0
            && ($entity['type'] ?? '') === 'post'
            && absint($entity['id'] ?? 0) !== $rendered_post_id
            && ! $this->allowsCollectionRootRenderedPostMismatch($source, $inspection)
            && ! $this->allowsLoopOwnedPostEntity($entity, $loop_context)) {
            return $attributes;
        }

        $render_context = isset($inspection['render_context']) ? sanitize_key((string) $inspection['render_context']) : 'text';
        $raw_seed = $this->buildSeed($element, $inspection, $loop_context);
        $seed = in_array($render_context, ['query_collection', 'gallery_collection'], true) ? $raw_seed : $this->dedupeSeed($raw_seed);
        $token = isset($this->instrumented[$seed]) ? $this->instrumented[$seed] : $this->registry->createToken($seed);
        $this->instrumented[$seed] = $token;
        $render_attribute = isset($inspection['render_attribute']) ? sanitize_key((string) $inspection['render_attribute']) : '';
        $scope = isset($classification['scope']) ? (string) $classification['scope'] : 'current_entity';
        $source_group = $this->buildSourceGroup($entity, $source);
        $sync_group = $this->buildSyncGroup($entity, $source, $render_context);
        $page_descriptor = $this->buildPageDescriptor($page_context);
        $owner_descriptor = $this->buildOwnerDescriptor($entity, $scope, $page_descriptor, $loop_context);
        $path_descriptor = $this->buildPathDescriptor($source);
        $mutation_descriptor = $this->buildMutationDescriptor($source, $render_context, $scope, $status, $path_descriptor, $loop_context);

        $descriptor = new EditableDescriptor(
            $token,
            $status,
            $scope,
            $entity,
            [
                'template_id' => 0,
                'element_id' => isset($element->id) ? sanitize_text_field((string) $element->id) : '',
                'element_uid' => isset($element->uid) ? sanitize_text_field((string) $element->uid) : (isset($element->id) ? sanitize_text_field((string) $element->id) : ''),
                'element_name' => isset($element->name) ? sanitize_key((string) $element->name) : '',
                'parent_element_id' => $this->resolveElementParentId($element),
                'setting_key' => isset($inspection['setting_key']) ? sanitize_key((string) $inspection['setting_key']) : '',
                'attribute_key' => sanitize_text_field($key),
                'context' => $render_context !== '' ? $render_context : 'text',
                'attribute' => $render_attribute,
                'source_group' => $source_group,
                'sync_group' => $sync_group,
                'loop_signature' => $this->loops->getSignature($loop_context),
                'loop' => $loop_context,
            ],
            $source,
            isset($classification['ui']) && is_array($classification['ui']) ? $classification['ui'] : [],
            isset($classification['resolver']) && is_array($classification['resolver']) ? $classification['resolver'] : [],
            $page_descriptor,
            $owner_descriptor,
            $loop_context,
            $path_descriptor,
            $mutation_descriptor
        );

        $this->registry->add($descriptor);
        $this->descriptors[$token] = $descriptor;

        if (! isset($attributes[$key]) || ! is_array($attributes[$key])) {
            $attributes[$key] = [];
        }

        $attributes[$key]['data-dbvc-ve'] = $token;
        $attributes[$key]['data-dbvc-ve-status'] = $status;
        $attributes[$key]['data-dbvc-ve-scope'] = $this->mapScopeToMarkerValue($descriptor->scope);
        $attributes[$key]['data-dbvc-ve-source-group'] = $source_group;
        $attributes[$key]['data-dbvc-ve-group'] = $sync_group;
        $attributes[$key]['data-dbvc-ve-context'] = $descriptor->render['context'];
        $attributes[$key]['data-dbvc-ve-badge-label'] = isset($descriptor->ui['badgeLabel']) ? sanitize_text_field((string) $descriptor->ui['badgeLabel']) : '';
        $attributes[$key]['data-dbvc-ve-input'] = isset($descriptor->ui['input']) ? sanitize_key((string) $descriptor->ui['input']) : '';
        if (! empty($descriptor->source['query_element_id'])) {
            $attributes[$key]['data-dbvc-ve-query-element-id'] = sanitize_text_field((string) $descriptor->source['query_element_id']);
        }

        if ($render_attribute !== '') {
            $attributes[$key]['data-dbvc-ve-attribute'] = $render_attribute;
        }

        return $attributes;
    }

    /**
     * Use the element HTML only as a narrow verification pass for already-marked candidates.
     *
     * @param string $element_html
     * @param object $element
     * @return string
     */
    public function verifyRenderedElement($element_html, $element)
    {
        if (! is_string($element_html) || ! is_object($element)) {
            return is_string($element_html) ? $element_html : '';
        }

        $this->rememberElementMetadata($element);
        $this->rememberNativeQueryElement($element);

        if ($element_html === '') {
            $this->maybeRegisterEmptyPostTermsCollectionDescriptorForElement($element);
            $this->maybeRegisterMissingMediaDescriptorForElement($element);

            foreach ($this->findElementDescriptors($element) as $descriptor) {
                if (! $this->isMissingMediaDescriptorCandidate($descriptor)) {
                    continue;
                }

                if ($this->descriptorHasEmptyMediaValue($descriptor)) {
                    $this->markMissingMediaDescriptorVerified($descriptor);
                } else {
                    $this->dropDescriptorByToken($descriptor->token);
                }
            }

            return '';
        }

        $descriptors = $this->findElementDescriptors($element);
        if (empty($descriptors)) {
            return $element_html;
        }

        foreach ($descriptors as $descriptor) {
            $element_html = $this->ensureMarkerAttributesForDescriptor($element_html, $descriptor);
            $element_html = $this->verifyDescriptor($element_html, $descriptor);
        }

        return $element_html;
    }

    /**
     * @param string $content
     * @param mixed  $post
     * @param mixed  $area
     * @return string
     */
    public function finalizeRenderedData($content, $post = null, $area = null)
    {
        unset($post, $area);

        if (! is_string($content) || $content === '' || empty($this->descriptors)) {
            return is_string($content) ? $content : '';
        }

        $occurrences = [];

        foreach ($this->descriptors as $descriptor) {
            if (! $descriptor instanceof EditableDescriptor) {
                continue;
            }

            $element_id = isset($descriptor->render['element_id']) ? sanitize_key((string) $descriptor->render['element_id']) : '';
            if ($element_id === '') {
                continue;
            }

            $index = isset($occurrences[$element_id]) ? (int) $occurrences[$element_id] : 0;
            $before_injection = $content;
            $content = $this->injectMarkerIntoElementOccurrence($content, $descriptor, $element_id, $index);
            if ($before_injection === $content && ! $this->contentContainsMarkerToken($content, $descriptor->token)) {
                $content = $this->injectMissingMediaMarkerIntoParentOccurrence($content, $descriptor, $index);
            }
            if ($before_injection === $content && ! $this->contentContainsMarkerToken($content, $descriptor->token)) {
                $content = $this->injectEmptyQueryCollectionMarkerAfterLoopComment($content, $descriptor, $element_id, $index);
            }
            if ($before_injection === $content && ! $this->contentContainsMarkerToken($content, $descriptor->token)) {
                $content = $this->injectEmptyQueryCollectionMarkerAfterQueryTrail($content, $descriptor, $element_id, $index);
            }
            $content = $this->stripDuplicateGalleryCollectionMarkers($content, $descriptor);
            $occurrences[$element_id] = $index + 1;
        }

        return $content;
    }

    /**
     * Bricks can render an empty current-post term loop as comments/placeholders only.
     * Register a descriptor from saved query settings only when the resolver proves one
     * owner post, one taxonomy, and an empty assigned-term set.
     *
     * @param object $element
     * @return void
     */
    private function maybeRegisterEmptyPostTermsCollectionDescriptorForElement($element)
    {
        if (! is_object($element)) {
            return;
        }

        foreach ($this->findElementDescriptors($element) as $descriptor) {
            if (! $descriptor instanceof EditableDescriptor) {
                continue;
            }

            if (($descriptor->render['context'] ?? '') === 'query_collection'
                && ($descriptor->source['type'] ?? '') === 'post_terms_collection') {
                return;
            }
        }

        $settings = isset($element->settings) && is_array($element->settings) ? $element->settings : [];
        $query = isset($settings['query']) && is_array($settings['query']) ? $settings['query'] : [];
        $query_object_type = isset($query['objectType']) ? sanitize_key((string) $query['objectType']) : '';
        if (empty($settings['hasLoop']) || $query_object_type !== 'term') {
            return;
        }

        $inspection = $this->inspectPostTermsCollectionLoopRoot($settings, $element);
        if (empty($inspection['supported'])) {
            return;
        }

        $inspection['query_result_empty'] = true;

        $element_ids = $this->resolveElementIds($element);
        $element_id = isset($element_ids[0]) ? sanitize_text_field((string) $element_ids[0]) : '';
        if ($element_id === '') {
            return;
        }

        $element_uid = isset($element->uid)
            ? sanitize_text_field((string) $element->uid)
            : $element_id;
        $element_name = isset($element->name) ? sanitize_key((string) $element->name) : '';

        $this->registerSyntheticEmptyQueryCollectionDescriptor($inspection, $element_id, $element_uid, $element_name, '_empty_query', '');
    }

    /**
     * Prefer root-like attribute groups so a supported element receives one stable marker.
     *
     * @param array<string, mixed> $attributes
     * @param string               $key
     * @param array<string, mixed> $inspection
     * @return bool
     */
    private function shouldInstrumentAttributeGroup(array $attributes, $key, array $inspection)
    {
        if (($inspection['render_context'] ?? '') === 'link_href') {
            if (! isset($attributes[$key]) || ! is_array($attributes[$key])) {
                return false;
            }

            $group = $attributes[$key];

            return isset($group['href'])
                && is_scalar($group['href'])
                && trim((string) $group['href']) !== ''
                && (in_array($key, ['_root', 'root', 'wrapper', 'a', 'link'], true) || preg_match('/^a-\d+$/', (string) $key));
        }

        if (in_array($key, ['_root', 'root', 'wrapper'], true)) {
            return true;
        }

        if (! isset($attributes[$key]) || ! is_array($attributes[$key])) {
            return false;
        }

        $group = $attributes[$key];

        if (isset($group['href']) || isset($group['src'])) {
            return false;
        }

        return isset($group['class']) || isset($group['id']);
    }

    /**
     * @param object $element
     * @return void
     */
    private function rememberNativeQueryElement($element)
    {
        if (! is_object($element)) {
            return;
        }

        $settings = isset($element->settings) && is_array($element->settings) ? $element->settings : [];
        if (empty($settings['hasLoop']) || empty($settings['query']) || ! is_array($settings['query'])) {
            return;
        }

        $query_object_type = isset($settings['query']['objectType'])
            ? sanitize_key((string) $settings['query']['objectType'])
            : '';
        if (strpos($query_object_type, 'acf_') !== 0) {
            return;
        }

        foreach ($this->resolveElementIds($element) as $element_id) {
            $this->loops->rememberElementQueryObjectType($element_id, $query_object_type);
        }
    }

    /**
     * @param array<string, mixed> $attributes
     * @param string               $key
     * @param object               $element
     * @return array<string, mixed>
     */
    private function inspectCollectionLoopRoot(array $attributes, $key, $element)
    {
        if (! $this->shouldInstrumentCollectionRootAttributeGroup($attributes, $key)) {
            return [
                'supported' => false,
            ];
        }

        $settings = isset($element->settings) && is_array($element->settings) ? $element->settings : [];
        $has_loop = ! empty($settings['hasLoop']);
        $query = isset($settings['query']) && is_array($settings['query']) ? $settings['query'] : [];
        $query_object_type = isset($query['objectType']) ? sanitize_key((string) $query['objectType']) : '';

        $native_terms_inspection = $this->inspectNativePostTermsElementRoot($settings, $element);
        if (! empty($native_terms_inspection['supported'])) {
            return $native_terms_inspection;
        }

        if ($has_loop && $query_object_type === 'term') {
            return $this->inspectPostTermsCollectionLoopRoot($settings, $element);
        }

        if (! $has_loop || $query_object_type === '' || strpos($query_object_type, 'acf_') !== 0) {
            return $this->inspectDerivedPostCollectionLoopRoot($settings, $element);
        }

        return [
            'supported' => true,
            'source_type' => 'acf_collection_field',
            'expression' => $query_object_type,
            'setting_key' => 'query',
            'render_context' => 'query_collection',
            'render_attribute' => '',
            'query_object_type' => $query_object_type,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param object               $element
     * @return array<string, mixed>
     */
    private function inspectNativePostTermsElementRoot(array $settings, $element)
    {
        $element_name = isset($element->name) ? sanitize_key((string) $element->name) : '';
        if ($element_name !== 'post-taxonomy') {
            return [
                'supported' => false,
            ];
        }

        $taxonomy = isset($settings['taxonomy']) ? sanitize_key((string) $settings['taxonomy']) : '';
        if ($taxonomy === '' || ! taxonomy_exists($taxonomy)) {
            return [
                'supported' => false,
            ];
        }

        $label_context = $this->resolveCollectionBadgeLabelContext($element);
        $element_ids = $this->resolveElementIds($element);
        $query_element_id = isset($element_ids[0]) ? sanitize_text_field((string) $element_ids[0]) : '';
        $owner_post_id_hint = absint($this->page_context->resolveRenderedPostId());
        $expression = 'element.taxonomy:' . $taxonomy;
        if ($owner_post_id_hint > 0) {
            $expression .= ':post:' . $owner_post_id_hint;
        }

        return [
            'supported' => true,
            'source_type' => 'post_terms_collection',
            'query_source' => 'bricks_native_post_taxonomy_element',
            'expression' => $expression,
            'setting_key' => 'taxonomy',
            'render_context' => 'query_collection',
            'render_attribute' => '',
            'query_object_type' => 'post-taxonomy',
            'query_element_id' => $query_element_id,
            'query_element_label' => $label_context['element_label'],
            'query_section_label' => $label_context['section_label'],
            'query_badge_subject' => $label_context['badge_subject'],
            'taxonomy' => $taxonomy,
            'native_terms_element' => true,
            'owner_post_id_hint' => $owner_post_id_hint,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param object               $element
     * @return array<string, mixed>
     */
    private function inspectPostTermsCollectionLoopRoot(array $settings, $element)
    {
        $query = isset($settings['query']) && is_array($settings['query']) ? $settings['query'] : [];
        $taxonomies = $this->normalizePostTypeList(isset($query['taxonomy']) ? $query['taxonomy'] : []);

        if (empty($query['current_post_term']) || count($taxonomies) !== 1) {
            return [
                'supported' => false,
            ];
        }

        $taxonomy = $taxonomies[0];
        if ($taxonomy === '' || ! taxonomy_exists($taxonomy)) {
            return [
                'supported' => false,
            ];
        }

        $label_context = $this->resolveCollectionBadgeLabelContext($element);
        $element_ids = $this->resolveElementIds($element);
        $query_element_id = isset($element_ids[0]) ? sanitize_text_field((string) $element_ids[0]) : '';

        return [
            'supported' => true,
            'source_type' => 'post_terms_collection',
            'expression' => 'query.objectType:term',
            'setting_key' => 'query',
            'render_context' => 'query_collection',
            'render_attribute' => '',
            'query_object_type' => 'term',
            'query_element_id' => $query_element_id,
            'query_element_label' => $label_context['element_label'],
            'query_section_label' => $label_context['section_label'],
            'query_badge_subject' => $label_context['badge_subject'],
            'taxonomy' => $taxonomy,
            'query_current_post_term' => true,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param object               $element
     * @return array<string, mixed>
     */
    private function inspectDerivedPostCollectionLoopRoot(array $settings, $element)
    {
        if (empty($settings['query']) || ! is_array($settings['query'])) {
            return [
                'supported' => false,
            ];
        }

        $query_object_type = isset($settings['query']['objectType'])
            ? sanitize_key((string) $settings['query']['objectType'])
            : '';
        if ($query_object_type !== '' && $query_object_type !== 'post') {
            return [
                'supported' => false,
            ];
        }

        $query_summary = $this->resolvePostQueryVarsSummaryForElement($element);
        $post_ids = isset($query_summary['post__in']) && is_array($query_summary['post__in'])
            ? array_values(array_filter(array_map('absint', $query_summary['post__in'])))
            : [];
        $target_post_type = isset($query_summary['post_type']) ? sanitize_key((string) $query_summary['post_type']) : '';
        $query_result_post_types = isset($query_summary['post_types']) && is_array($query_summary['post_types'])
            ? array_values(array_filter(array_map('sanitize_key', $query_summary['post_types'])))
            : [];
        $query_element_id = isset($query_summary['element_id']) ? sanitize_text_field((string) $query_summary['element_id']) : '';
        $query_result_empty = ! empty($query_summary['query_result_empty']);

        if ($target_post_type === 'any') {
            $target_post_type = '';
        }

        if ((empty($post_ids) && ! $query_result_empty) || ($target_post_type === '' && empty($query_result_post_types)) || $query_element_id === '') {
            return [
                'supported' => false,
            ];
        }

        $label_context = $this->resolveCollectionBadgeLabelContext($element);

        return [
            'supported' => true,
            'source_type' => 'acf_collection_field',
            'query_source' => 'derived_bricks_query',
            'expression' => 'query.derived:' . $query_element_id,
            'setting_key' => 'query',
            'render_context' => 'query_collection',
            'render_attribute' => '',
            'query_object_type' => 'post',
            'query_element_id' => $query_element_id,
            'query_element_label' => $label_context['element_label'],
            'query_section_label' => $label_context['section_label'],
            'query_badge_subject' => $label_context['badge_subject'],
            'target_post_type' => $target_post_type,
            'query_result_post_types' => $query_result_post_types,
            'query_result_ids' => $post_ids,
            'query_result_empty' => $query_result_empty,
            'query_vars' => $query_summary,
        ];
    }

    /**
     * @param object $element
     * @return array<string, mixed>
     */
    private function resolvePostQueryVarsSummaryForElement($element)
    {
        foreach ($this->resolveElementIds($element) as $element_id) {
            if (isset($this->post_query_vars_by_element[$element_id])) {
                $summary = $this->post_query_vars_by_element[$element_id];
                $summary['element_id'] = $element_id;

                return $summary;
            }
        }

        return [];
    }

    /**
     * @param object $element
     * @return array<int, string>
     */
    private function resolveElementIds($element)
    {
        if (! is_object($element)) {
            return [];
        }

        $element_ids = [];
        if (! empty($element->id)) {
            $element_ids[] = sanitize_text_field((string) $element->id);
        }

        if (! empty($element->id) && ! empty($element->instanceId) && strpos((string) $element->id, '-') === false) {
            $element_ids[] = sanitize_text_field((string) $element->id . '-' . (string) $element->instanceId);
        }

        $aliases = [];
        foreach ($element_ids as $element_id) {
            foreach ($this->buildElementIdAliases($element_id) as $alias) {
                $aliases[] = $alias;
            }
        }

        return array_values(array_unique(array_filter($aliases)));
    }

    /**
     * @param string $element_id
     * @return array<int, string>
     */
    private function buildElementIdAliases($element_id)
    {
        $element_id = sanitize_text_field((string) $element_id);
        if ($element_id === '') {
            return [];
        }

        $aliases = [$element_id];
        if (preg_match('/^([a-zA-Z0-9]+)-[a-zA-Z0-9]+$/', $element_id, $matches)) {
            $aliases[] = sanitize_text_field((string) $matches[1]);
        }

        return array_values(array_unique(array_filter($aliases)));
    }

    /**
     * @param array<string, mixed> $query_vars
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function buildPostQueryVarsSummary(array $query_vars, array $settings)
    {
        $settings_query = isset($settings['query']) && is_array($settings['query']) ? $settings['query'] : [];
        $post_id_evidence = $this->resolvePostQueryIdEvidence($query_vars, $settings_query);
        $raw_post_ids = isset($post_id_evidence['ids']) && is_array($post_id_evidence['ids'])
            ? array_values(array_map('absint', $post_id_evidence['ids']))
            : [];
        $post_ids = array_values(array_filter($raw_post_ids));
        $has_empty_id_evidence = ! empty($raw_post_ids) && empty($post_ids);

        $raw_result_post_types = $this->inferPostTypesFromIds($post_ids);
        $dynamic_tags = isset($post_id_evidence['dynamic_tags']) && is_array($post_id_evidence['dynamic_tags'])
            ? array_values(array_filter(array_map('sanitize_text_field', $post_id_evidence['dynamic_tags'])))
            : [];
        $query_editor_source_hints = $this->resolveQueryEditorAcfSourceHints($settings_query);
        $query_editor_hints = isset($query_editor_source_hints['current_owner_fields']) && is_array($query_editor_source_hints['current_owner_fields'])
            ? $query_editor_source_hints['current_owner_fields']
            : [];
        $acf_field_hints = array_values(
            array_unique(
                array_filter(
                    array_merge(
                        $this->resolveAcfFieldHintsFromDynamicTags($dynamic_tags),
                        $query_editor_hints
                    )
                )
            )
        );
        $query_editor_active = ! empty($settings_query['queryEditor']);
        $has_source_evidence = ! empty($dynamic_tags) || ! empty($query_editor_hints);
        if (empty($post_ids) && ! $has_empty_id_evidence && ! $has_source_evidence) {
            return [];
        }

        $declared_post_types = $this->normalizePostTypeList(isset($query_vars['post_type']) ? $query_vars['post_type'] : ($settings_query['post_type'] ?? ''), true);
        $query_editor_post_type = $this->resolveQueryEditorPostTypeHint($settings_query);
        $post_type = $query_editor_post_type !== '' && (empty($declared_post_types) || in_array($query_editor_post_type, $declared_post_types, true))
            ? $query_editor_post_type
            : $this->normalizeSinglePostType($declared_post_types);
        if ($post_type === '') {
            $post_type = in_array('any', $declared_post_types, true)
                ? 'any'
                : $this->inferSinglePostTypeFromIds($post_ids);
        }

        $query_post_ids = $this->filterPostQueryResultIdsByDeclaredTypes($post_ids, $post_type, $declared_post_types);
        $excluded_post_ids = array_values(array_diff($post_ids, $query_post_ids));
        $result_post_types = $this->inferPostTypesFromIds($query_post_ids);

        if ($post_type === '' && empty($post_ids)) {
            return [];
        }

        if ($post_type === '' && ! $this->shouldAllowMixedPostTypeQuerySummary($result_post_types, $query_editor_active, $acf_field_hints)) {
            return [];
        }

        $posts_per_page = isset($query_vars['posts_per_page']) && is_numeric($query_vars['posts_per_page'])
            ? (int) $query_vars['posts_per_page']
            : (isset($settings_query['posts_per_page']) && is_numeric($settings_query['posts_per_page']) ? (int) $settings_query['posts_per_page'] : 0);

        return [
            'source' => 'bricks/posts/query_vars',
            'post_type' => $post_type,
            'post_types' => $result_post_types,
            'post__in' => $query_post_ids,
            'post__in_raw' => $post_ids,
            'post__in_raw_post_types' => $raw_result_post_types,
            'post__in_excluded_by_post_type' => $excluded_post_ids,
            'post__in_source' => isset($post_id_evidence['source']) ? sanitize_key((string) $post_id_evidence['source']) : '',
            'post__in_setting_source' => $this->resolvePostQuerySettingSource($post_id_evidence),
            'post__in_setting_key' => isset($post_id_evidence['setting_key']) ? sanitize_key((string) $post_id_evidence['setting_key']) : '',
            'post__in_dynamic_tags' => $dynamic_tags,
            'post__in_acf_field_hints' => $acf_field_hints,
            'post__in_has_dynamic_source' => ! empty($dynamic_tags),
            'post__in_empty_sentinel' => $has_empty_id_evidence,
            'query_result_empty' => empty($query_post_ids),
            'query_editor_acf_field_hints' => $query_editor_hints,
            'query_editor_acf_option_field_hints' => isset($query_editor_source_hints['option_fields']) && is_array($query_editor_source_hints['option_fields']) ? $query_editor_source_hints['option_fields'] : [],
            'query_editor_acf_explicit_field_hints' => isset($query_editor_source_hints['explicit_object_fields']) && is_array($query_editor_source_hints['explicit_object_fields']) ? $query_editor_source_hints['explicit_object_fields'] : [],
            'query_editor_acf_source_hints' => $query_editor_source_hints,
            'query_editor_active' => $query_editor_active,
            'query_editor_post_type_hint' => $query_editor_post_type,
            'orderby' => $this->normalizeQueryOrderby(isset($query_vars['orderby']) ? $query_vars['orderby'] : ($settings_query['orderby'] ?? '')),
            'posts_per_page' => $posts_per_page,
            'paged' => isset($query_vars['paged']) && is_numeric($query_vars['paged']) ? max(1, absint($query_vars['paged'])) : 1,
            'disable_query_merge' => ! empty($query_vars['disable_query_merge']) || ! empty($settings_query['disable_query_merge']),
        ];
    }

    /**
     * Mixed post-type query summaries are only useful when they can continue into
     * the full-collection resolver path. Opaque mixed ID lists still stay hidden.
     *
     * @param array<int, string> $result_post_types
     * @param bool               $query_editor_active
     * @param array<int, string> $acf_field_hints
     * @return bool
     */
    private function shouldAllowMixedPostTypeQuerySummary(array $result_post_types, $query_editor_active, array $acf_field_hints)
    {
        $result_post_types = array_values(array_filter(array_map('sanitize_key', $result_post_types)));
        if (count($result_post_types) < 2) {
            return false;
        }

        return ! empty($query_editor_active) || ! empty($acf_field_hints);
    }

    /**
     * @param array<int, int>    $post_ids
     * @param string             $post_type
     * @param array<int, string> $declared_post_types
     * @return array<int, int>
     */
    private function filterPostQueryResultIdsByDeclaredTypes(array $post_ids, $post_type, array $declared_post_types)
    {
        $post_ids = array_values(array_filter(array_map('absint', $post_ids)));
        if (empty($post_ids)) {
            return [];
        }

        $post_type = sanitize_key((string) $post_type);
        $target_post_types = [];
        if ($post_type !== '' && $post_type !== 'any') {
            $target_post_types = [$post_type];
        } elseif (! in_array('any', $declared_post_types, true)) {
            $target_post_types = array_values(array_filter(array_map('sanitize_key', $declared_post_types)));
        }

        if (empty($target_post_types)) {
            return $post_ids;
        }

        $filtered = [];
        foreach ($post_ids as $post_id) {
            $resolved_type = get_post_type($post_id);
            if (is_string($resolved_type) && in_array($resolved_type, $target_post_types, true)) {
                $filtered[] = $post_id;
            }
        }

        return array_values($filtered);
    }

    /**
     * @param array<string, mixed> $query_vars
     * @param array<string, mixed> $settings_query
     * @return array<string, mixed>
     */
    private function resolvePostQueryIdEvidence(array $query_vars, array $settings_query)
    {
        $settings_evidence = $this->resolveSettingsQueryIdEvidence($settings_query);
        $query_candidates = [
            'query_vars_post__in' => isset($query_vars['post__in']) ? $query_vars['post__in'] : null,
            'query_vars_include' => isset($query_vars['include']) ? $query_vars['include'] : null,
            'query_vars_p' => isset($query_vars['p']) ? $query_vars['p'] : null,
        ];

        foreach ($query_candidates as $source => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $ids = $this->normalizeIdList($value);
            if (! empty($ids)) {
                return [
                    'ids' => $ids,
                    'source' => $source,
                    'setting_source' => isset($settings_evidence['source']) ? (string) $settings_evidence['source'] : '',
                    'setting_key' => isset($settings_evidence['setting_key']) ? (string) $settings_evidence['setting_key'] : '',
                    'dynamic_tags' => isset($settings_evidence['dynamic_tags']) && is_array($settings_evidence['dynamic_tags'])
                        ? $settings_evidence['dynamic_tags']
                        : [],
                ];
            }
        }

        return $settings_evidence;
    }

    /**
     * @param array<string, mixed> $post_id_evidence
     * @return string
     */
    private function resolvePostQuerySettingSource(array $post_id_evidence)
    {
        $setting_source = isset($post_id_evidence['setting_source'])
            ? sanitize_key((string) $post_id_evidence['setting_source'])
            : '';
        if ($setting_source !== '') {
            return $setting_source;
        }

        $source = isset($post_id_evidence['source'])
            ? sanitize_key((string) $post_id_evidence['source'])
            : '';

        return strpos($source, 'settings_') === 0 ? $source : '';
    }

    /**
     * @param array<string, mixed> $settings_query
     * @return array<string, mixed>
     */
    private function resolveSettingsQueryIdEvidence(array $settings_query)
    {
        $settings_candidates = [
            'post__in' => isset($settings_query['post__in']) ? $settings_query['post__in'] : null,
            'include' => isset($settings_query['include']) ? $settings_query['include'] : null,
            'p' => isset($settings_query['p']) ? $settings_query['p'] : null,
        ];

        foreach ($settings_candidates as $setting_key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $dynamic_tags = $this->extractDynamicTags($value);
            $ids = $this->normalizeIdList($value);

            if (empty($ids) && ! empty($dynamic_tags)) {
                $ids = $this->resolveDynamicIdList($value);
            }

            if (! empty($ids)) {
                return [
                    'ids' => $ids,
                    'source' => ! empty($dynamic_tags) ? 'settings_dynamic_' . $setting_key : 'settings_static_' . $setting_key,
                    'setting_key' => $setting_key,
                    'dynamic_tags' => $dynamic_tags,
                ];
            }

            if (! empty($dynamic_tags)) {
                return [
                    'ids' => [],
                    'source' => 'settings_dynamic_' . $setting_key,
                    'setting_key' => $setting_key,
                    'dynamic_tags' => $dynamic_tags,
                ];
            }
        }

        return [
            'ids' => [],
            'source' => '',
            'setting_source' => '',
            'setting_key' => '',
            'dynamic_tags' => [],
        ];
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function extractDynamicTags($value)
    {
        $values = is_array($value) ? $value : [$value];
        $tags = [];

        foreach ($values as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            preg_match_all('/\{([^{}]+)\}/', (string) $item, $matches);
            foreach ($matches[1] ?? [] as $tag) {
                $tag = sanitize_text_field((string) $tag);
                if ($tag !== '') {
                    $tags[] = $tag;
                }
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * @param array<int, string> $dynamic_tags
     * @return array<int, string>
     */
    private function resolveAcfFieldHintsFromDynamicTags(array $dynamic_tags)
    {
        $hints = [];

        foreach ($dynamic_tags as $tag) {
            $tag = sanitize_text_field((string) $tag);
            $tag = preg_replace('/:.+$/', '', $tag);
            $tag = is_string($tag) ? sanitize_key($tag) : '';

            if (strpos($tag, 'acf_') !== 0) {
                continue;
            }

            $field_hint = substr($tag, 4);
            if (is_string($field_hint) && $field_hint !== '') {
                $hints[] = sanitize_key($field_hint);
            }
        }

        return array_values(array_unique(array_filter($hints)));
    }

    /**
     * Resolve common scalar post-type assignments in Bricks Query Editor snippets.
     *
     * @param array<string, mixed> $settings_query
     * @return string
     */
    private function resolveQueryEditorPostTypeHint(array $settings_query)
    {
        $query_editor = isset($settings_query['queryEditor']) && is_scalar($settings_query['queryEditor'])
            ? (string) $settings_query['queryEditor']
            : '';
        if ($query_editor === '') {
            return '';
        }

        $patterns = [
            '/\$post_type\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/i',
            '/[\'"]post_type[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/i',
            '/\$query_args\s*\[\s*[\'"]post_type[\'"]\s*\]\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/i',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $query_editor, $matches)) {
                continue;
            }

            $post_type = isset($matches[1]) ? sanitize_key((string) $matches[1]) : '';
            if ($post_type !== '') {
                return $post_type;
            }
        }

        return '';
    }

    /**
     * Extract ACF get_field() source hints from Query Editor PHP without treating
     * shared or explicit-owner reads as current-owner writable evidence.
     *
     * @param array<string, mixed> $settings_query
     * @return array<string, array<int, string>>
     */
    private function resolveQueryEditorAcfSourceHints(array $settings_query)
    {
        $query_editor = isset($settings_query['queryEditor']) && is_scalar($settings_query['queryEditor'])
            ? (string) $settings_query['queryEditor']
            : '';
        if ($query_editor === '') {
            return [
                'current_owner_fields' => [],
                'option_fields' => [],
                'explicit_object_fields' => [],
            ];
        }

        $hints = [
            'current_owner_fields' => [],
            'option_fields' => [],
            'explicit_object_fields' => [],
        ];
        $current_owner_object_vars = $this->resolveQueryEditorCurrentOwnerObjectVars($query_editor);
        preg_match_all('/\bget_field\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*([^)]*))?\)/i', $query_editor, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $field_name = isset($match[1]) ? sanitize_key((string) $match[1]) : '';
            $object_arg = isset($match[2]) ? trim((string) $match[2]) : '';

            if ($field_name === '') {
                continue;
            }

            if ($object_arg === '') {
                $hints['current_owner_fields'][] = $field_name;
                continue;
            }

            $object_arg_parts = preg_split('/,/', $object_arg, 2);
            $first_object_arg = isset($object_arg_parts[0]) ? trim((string) $object_arg_parts[0]) : $object_arg;

            if (preg_match('/^[\'"]options?[\'"]$/i', $first_object_arg)) {
                $hints['option_fields'][] = $field_name;
                continue;
            }

            if ($this->isCurrentOwnerQueryEditorObjectArg($first_object_arg, $current_owner_object_vars)) {
                $hints['current_owner_fields'][] = $field_name;
                continue;
            }

            $hints['explicit_object_fields'][] = $field_name;
        }

        foreach ($hints as $key => $values) {
            $hints[$key] = array_values(array_unique(array_filter(array_map('sanitize_key', $values))));
        }

        return $hints;
    }

    /**
     * @param string $query_editor
     * @return array<int, string>
     */
    private function resolveQueryEditorCurrentOwnerObjectVars($query_editor)
    {
        $query_editor = (string) $query_editor;
        if ($query_editor === '') {
            return [];
        }

        preg_match_all(
            '/\$([A-Za-z_][A-Za-z0-9_]*)\s*=(?!=)/',
            $query_editor,
            $assignment_matches,
            PREG_SET_ORDER
        );
        $assignment_counts = [];
        foreach ($assignment_matches as $assignment_match) {
            $assignment_var = isset($assignment_match[1]) ? (string) $assignment_match[1] : '';
            if ($assignment_var === '') {
                continue;
            }

            if (! isset($assignment_counts[$assignment_var])) {
                $assignment_counts[$assignment_var] = 0;
            }

            $assignment_counts[$assignment_var]++;
        }

        preg_match_all(
            '/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?:get_the_ID|get_queried_object_id)\s*\(\s*\)\s*;/i',
            $query_editor,
            $matches,
            PREG_SET_ORDER
        );

        $vars = [];
        foreach ($matches as $match) {
            $var_name = isset($match[1]) ? (string) $match[1] : '';
            if ($var_name === '') {
                continue;
            }

            if (($assignment_counts[$var_name] ?? 0) !== 1) {
                continue;
            }

            $vars[] = '$' . $var_name;
        }

        return array_values(array_unique($vars));
    }

    /**
     * Keep Query Editor source hints narrow: only obvious current-object function
     * calls or variables directly assigned from those functions are treated as
     * current-owner evidence. Other variables and literal IDs stay explicit-owner
     * evidence because they can point at a different object.
     *
     * @param string $object_arg
     * @param array<int, string> $current_owner_object_vars
     * @return bool
     */
    private function isCurrentOwnerQueryEditorObjectArg($object_arg, array $current_owner_object_vars = [])
    {
        $object_arg = preg_replace('/\s+/', '', (string) $object_arg);
        if (! is_string($object_arg) || $object_arg === '') {
            return false;
        }

        $current_owner_object_vars = array_values(array_unique(array_filter(array_map('strval', $current_owner_object_vars))));
        if (! empty($current_owner_object_vars) && in_array($object_arg, $current_owner_object_vars, true)) {
            return true;
        }

        return preg_match('/^(?:get_the_ID|get_queried_object_id)\($/i', $object_arg)
            || preg_match('/^(?:get_the_ID|get_queried_object_id)\(\)$/i', $object_arg);
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function resolveDynamicIdList($value)
    {
        if (! function_exists('bricks_render_dynamic_data')) {
            return [];
        }

        $values = is_array($value) ? $value : [$value];
        $ids = [];

        foreach ($values as $item) {
            if (! is_scalar($item) || strpos((string) $item, '{') === false) {
                continue;
            }

            $rendered = bricks_render_dynamic_data((string) $item);
            $ids = array_merge($ids, $this->normalizeIdList($rendered));
        }

        return array_values(array_unique(array_filter(array_map('absint', $ids))));
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function normalizeQueryOrderby($value)
    {
        $values = is_array($value) ? $value : [$value];
        $normalized = [];

        foreach ($values as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $orderby = sanitize_key((string) $item);
            if ($orderby !== '') {
                $normalized[] = $orderby;
            }
        }

        return implode(',', array_values(array_unique($normalized)));
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function normalizeSinglePostType($value)
    {
        $values = $this->normalizePostTypeList($value);
        return count($values) === 1 ? $values[0] : '';
    }

    /**
     * @param mixed $value
     * @param bool  $allow_any
     * @return array<int, string>
     */
    private function normalizePostTypeList($value, $allow_any = false)
    {
        $values = is_array($value) ? $value : [$value];
        $normalized = [];

        foreach ($values as $item) {
            $post_type = sanitize_key((string) $item);
            if ($post_type !== '' && ($allow_any || $post_type !== 'any')) {
                $normalized[] = $post_type;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<int, int> $post_ids
     * @return string
     */
    private function inferSinglePostTypeFromIds(array $post_ids)
    {
        $types = $this->inferPostTypesFromIds($post_ids);

        return count($types) === 1 ? $types[0] : '';
    }

    /**
     * @param array<int, int> $post_ids
     * @return array<int, string>
     */
    private function inferPostTypesFromIds(array $post_ids)
    {
        $types = [];

        foreach ($post_ids as $post_id) {
            $post_id = absint($post_id);
            if ($post_id <= 0) {
                continue;
            }

            $post_type = get_post_type($post_id);
            $post_type = is_string($post_type) ? sanitize_key($post_type) : '';
            if ($post_type !== '') {
                $types[] = $post_type;
            }
        }

        $types = array_values(array_unique($types));

        return $types;
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function normalizeIdList($value)
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $ids = [];

        foreach ($values as $item) {
            $id = is_object($item) && isset($item->ID) ? absint($item->ID) : absint($item);
            $is_zero_sentinel = ! is_object($item) && is_numeric($item) && (int) $item === 0;
            if ($id > 0 || $is_zero_sentinel) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $attributes
     * @param string               $key
     * @return bool
     */
    private function shouldInstrumentCollectionRootAttributeGroup(array $attributes, $key)
    {
        if (! in_array($key, ['_root', 'root', 'wrapper'], true)) {
            return false;
        }

        if (! isset($attributes[$key]) || ! is_array($attributes[$key])) {
            return false;
        }

        $group = $attributes[$key];

        return isset($group['class']) || isset($group['id']);
    }

    /**
     * @param object               $element
     * @param array<string, mixed> $inspection
     * @return string
     */
    private function buildSeed($element, array $inspection, array $loop_context = [])
    {
        $seed_parts = [
            (string) ($element->uid ?? $element->id ?? ''),
            (string) ($element->name ?? ''),
            (string) ($inspection['setting_key'] ?? ''),
            (string) ($inspection['expression'] ?? ''),
            (string) ($loop_context['signature'] ?? ''),
        ];

        return implode('|', $seed_parts);
    }

    /**
     * When Bricks reuses a native loop signature for a later repeated row, avoid collapsing
     * the later descriptor into the earlier token. The first occurrence keeps the canonical
     * seed; later repeats get a stable occurrence suffix for this render pass.
     *
     * @param string $seed
     * @return string
     */
    private function dedupeSeed($seed)
    {
        $seed = (string) $seed;
        $count = isset($this->seed_occurrences[$seed]) ? (int) $this->seed_occurrences[$seed] + 1 : 1;
        $this->seed_occurrences[$seed] = $count;

        if ($count <= 1) {
            return $seed;
        }

        return $seed . '#dup:' . $count;
    }

    /**
     * @param array<string, mixed> $entity
     * @param array<string, mixed> $loop_context
     * @return bool
     */
    private function allowsLoopOwnedPostEntity(array $entity, array $loop_context)
    {
        if (empty($loop_context['active'])
            || empty($loop_context['supports_loop_owned_editing'])
            || ($entity['type'] ?? '') !== 'post') {
            return false;
        }

        $entity_id = isset($entity['id']) ? absint($entity['id']) : 0;
        $loop_object_id = isset($loop_context['loop_object_id']) ? absint($loop_context['loop_object_id']) : 0;
        $parent_loop_object_id = isset($loop_context['parent_loop_object_id']) ? absint($loop_context['parent_loop_object_id']) : 0;
        $effective_owner_type = isset($loop_context['effective_owner_type']) ? sanitize_key((string) $loop_context['effective_owner_type']) : '';
        $effective_owner_id = isset($loop_context['effective_owner_id']) ? absint($loop_context['effective_owner_id']) : 0;

        return $entity_id > 0
            && (
                ($effective_owner_type === 'post' && $entity_id === $effective_owner_id)
                || $entity_id === $loop_object_id
                || $entity_id === $parent_loop_object_id
            );
    }

    /**
     * Query-root collection markers represent the list owner, not the current loop row.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $inspection
     * @return bool
     */
    private function allowsCollectionRootRenderedPostMismatch(array $source, array $inspection)
    {
        return (isset($inspection['render_context']) ? sanitize_key((string) $inspection['render_context']) : '') === 'query_collection'
            && in_array(
                isset($source['type']) ? sanitize_key((string) $source['type']) : '',
                ['acf_collection_field', 'post_terms_collection'],
                true
            );
    }

    /**
     * @param string $value
     * @return string
     */
    private function extractComparableText($value)
    {
        $text = wp_strip_all_tags((string) $value, true);

        return $this->normalizeComparableValue($text);
    }

    /**
     * @param string             $value
     * @param EditableDescriptor $descriptor
     * @return string
     */
    private function extractComparableRenderValue($value, EditableDescriptor $descriptor)
    {
        $context = isset($descriptor->render['context']) ? (string) $descriptor->render['context'] : 'text';

        if (in_array($context, ['link_href', 'image_src'], true)) {
            $attribute = isset($descriptor->render['attribute']) ? (string) $descriptor->render['attribute'] : 'href';

            return $this->extractComparableAttribute($value, $attribute !== '' ? $attribute : ($context === 'image_src' ? 'src' : 'href'));
        }

        if ($context === 'background_image') {
            return $this->extractComparableBackgroundImage($value);
        }

        return $this->extractComparableText($value);
    }

    /**
     * @param string $value
     * @param string $attribute
     * @return string
     */
    private function extractComparableAttribute($value, $attribute)
    {
        $pattern = '/\s' . preg_quote((string) $attribute, '/') . '=(["\'])(.*?)\1/i';
        if (! preg_match($pattern, (string) $value, $matches)) {
            return '';
        }

        return $this->normalizeComparableValue(html_entity_decode((string) $matches[2], ENT_QUOTES, 'UTF-8'));
    }

    /**
     * @param string $value
     * @return string
     */
    private function extractComparableBackgroundImage($value)
    {
        $style = $this->extractComparableAttribute((string) $value, 'style');
        if ($style === '') {
            return '';
        }

        if (preg_match('/background(?:-image)?\s*:\s*url\((["\']?)(.*?)\1\)/i', $style, $matches)) {
            return $this->normalizeComparableValue((string) $matches[2]);
        }

        return '';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function normalizeComparableValue($value)
    {
        if (! is_scalar($value) && $value !== null) {
            return '';
        }

        $string = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
        $string = preg_replace('/\s+/u', ' ', $string);

        return is_string($string) ? trim($string) : '';
    }

    /**
     * @param string $left
     * @param string $right
     * @return bool
     */
    private function valuesMatch($left, $right)
    {
        return $left === $right;
    }

    /**
     * @param string             $left
     * @param string             $right
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    private function valuesMatchForDescriptor($left, $right, EditableDescriptor $descriptor)
    {
        if ($this->valuesMatch($left, $right)) {
            return true;
        }

        $context = isset($descriptor->render['context']) ? (string) $descriptor->render['context'] : 'text';
        if (! in_array($context, ['image_src', 'background_image'], true)) {
            return false;
        }

        return $this->mediaValuesMatch($left, $right);
    }

    /**
     * @param string $left
     * @param string $right
     * @return bool
     */
    private function mediaValuesMatch($left, $right)
    {
        $left_key = $this->normalizeMediaComparableValue($left);
        $right_key = $this->normalizeMediaComparableValue($right);

        return $left_key !== ''
            && $right_key !== ''
            && $left_key === $right_key;
    }

    /**
     * @param string $value
     * @return string
     */
    private function normalizeMediaComparableValue($value)
    {
        $value = $this->normalizeComparableValue($value);
        if ($value === '') {
            return '';
        }

        $path = wp_parse_url($value, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            $path = $value;
        }

        $path = rawurldecode((string) $path);
        $path = preg_replace('/-\d+x\d+(?=\.[a-zA-Z0-9]+$)/', '', $path);
        $path = is_string($path) ? trim($path) : '';

        if ($path === '') {
            return '';
        }

        return strtolower(ltrim($path, '/'));
    }

    /**
     * @param object             $resolver
     * @param EditableDescriptor $descriptor
     * @param mixed              $raw_value
     * @param string             $rendered_text
     * @return array<string, string>
     */
    private function resolveDisplayPayload($resolver, EditableDescriptor $descriptor, $raw_value, $rendered_text)
    {
        $candidates = [];

        if (is_object($resolver) && method_exists($resolver, 'getDisplayCandidates')) {
            $candidates = $resolver->getDisplayCandidates($descriptor, $raw_value);
        }

        if (! is_array($candidates) || empty($candidates)) {
            $candidates = [
                [
                    'key' => 'default',
                    'value' => $resolver->getDisplayValue($descriptor, $raw_value),
                    'mode' => $resolver->getDisplayMode($descriptor),
                ],
            ];
        }

        $fallback = null;

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $key = isset($candidate['key']) ? (string) $candidate['key'] : 'default';
            $mode = isset($candidate['mode']) ? (string) $candidate['mode'] : $resolver->getDisplayMode($descriptor);
            $value = isset($candidate['value']) ? $candidate['value'] : '';
            $text = $this->extractComparableText($value);
            $payload = [
                'key' => $key,
                'mode' => $mode,
                'text' => $text,
            ];

            if ($fallback === null) {
                $fallback = $payload;
            }

            if ($this->valuesMatchForDescriptor($rendered_text, $text, $descriptor)) {
                return $payload;
            }
        }

        return is_array($fallback)
            ? $fallback
            : [
                'key' => 'default',
                'mode' => $resolver->getDisplayMode($descriptor),
                'text' => '',
            ];
    }

    /**
     * @param string             $element_html
     * @param EditableDescriptor $descriptor
     * @return string
     */
    private function verifyDescriptor($element_html, EditableDescriptor $descriptor)
    {
        $resolver = $this->resolvers->resolve($descriptor);
        if ($resolver->name() === 'unsupported') {
            $this->dropDescriptorByToken($descriptor->token);

            return $this->stripMarkerAttributesForToken($element_html, $descriptor->token);
        }

        $raw_value = $resolver->getValue($descriptor);
        $rendered_fragment = $this->resolveRenderedFragment($element_html, $descriptor);
        if ($rendered_fragment === '') {
            $this->dropDescriptorByToken($descriptor->token);

            return $this->stripMarkerAttributesForToken($element_html, $descriptor->token);
        }

        if (($descriptor->render['context'] ?? '') === 'query_collection') {
            $descriptor->render['rendered_value'] = '';
            $descriptor->render['resolved_value'] = '';
            $descriptor->render['rendered_text'] = '';
            $descriptor->render['resolved_text'] = '';
            $descriptor->render['display_key'] = 'default';
            $descriptor->render['display_mode'] = 'text';
            $descriptor->render['render_verified'] = true;
            $descriptor->render['value_match'] = true;

            return $element_html;
        }

        if (($descriptor->render['context'] ?? '') === 'gallery_collection') {
            return $this->verifyGalleryCollectionDescriptor($element_html, $descriptor, $raw_value, $rendered_fragment, $resolver);
        }

        $rendered_value = $this->extractComparableRenderValue($rendered_fragment, $descriptor);
        $display_payload = $this->resolveDisplayPayload($resolver, $descriptor, $raw_value, $rendered_value);
        $resolved_value = isset($display_payload['text']) ? (string) $display_payload['text'] : '';
        $display_key = isset($display_payload['key']) ? (string) $display_payload['key'] : 'default';
        $display_mode = isset($display_payload['mode']) ? (string) $display_payload['mode'] : $resolver->getDisplayMode($descriptor);
        $rebound = null;

        if ($descriptor->status === 'editable' && ! $this->valuesMatchForDescriptor($rendered_value, $resolved_value, $descriptor)) {
            $rebound = $this->attemptUniqueRowRebind($resolver, $descriptor, $rendered_value);

            if (is_array($rebound)) {
                $this->applyRowDescriptorRebind($descriptor, (int) $rebound['index']);
                $raw_value = $rebound['raw_value'];
                $display_payload = $rebound['display_payload'];
                $resolved_value = isset($display_payload['text']) ? (string) $display_payload['text'] : '';
                $display_key = isset($display_payload['key']) ? (string) $display_payload['key'] : 'default';
                $display_mode = isset($display_payload['mode']) ? (string) $display_payload['mode'] : $resolver->getDisplayMode($descriptor);
            }
        }

        $descriptor->render['rendered_value'] = $rendered_value;
        $descriptor->render['resolved_value'] = $resolved_value;
        $descriptor->render['rendered_text'] = $rendered_value;
        $descriptor->render['resolved_text'] = $resolved_value;
        $descriptor->render['display_key'] = $display_key;
        $descriptor->render['display_mode'] = $display_mode;
        $descriptor->render['render_verified'] = true;
        $descriptor->render['value_match'] = $this->valuesMatchForDescriptor($rendered_value, $resolved_value, $descriptor);

        if ($descriptor->status === 'editable' && ! $descriptor->render['value_match']) {
            $descriptor->status = 'unsupported';
            $descriptor->ui['warning'] = __(self::MISMATCH_WARNING, 'dbvc');
            $this->dropDescriptorByToken($descriptor->token);

            return $this->stripMarkerAttributesForToken($element_html, $descriptor->token);
        }

        if (is_array($rebound)) {
            $element_html = $this->replaceMarkerAttributeForToken(
                $element_html,
                $descriptor->token,
                'data-dbvc-ve-source-group',
                isset($descriptor->render['source_group']) ? (string) $descriptor->render['source_group'] : ''
            );
        }

        $descriptor->render['sync_group'] = $this->buildSyncGroup(
            $descriptor->entity,
            $descriptor->source,
            isset($descriptor->render['context']) ? (string) $descriptor->render['context'] : 'text',
            $display_key
        );

        return $this->replaceMarkerAttributeForToken($element_html, $descriptor->token, 'data-dbvc-ve-group', $descriptor->render['sync_group']);
    }

    /**
     * Bricks gallery markup renders a collection of attachment nodes, not text.
     * Verify direct gallery descriptors by attachment identity before exposing saves.
     *
     * @param string             $element_html
     * @param EditableDescriptor $descriptor
     * @param mixed              $raw_value
     * @param string             $rendered_fragment
     * @param object             $resolver
     * @return string
     */
    private function verifyGalleryCollectionDescriptor($element_html, EditableDescriptor $descriptor, $raw_value, $rendered_fragment, $resolver)
    {
        $resolved_ids = $this->normalizeGalleryAttachmentIds($raw_value);
        $rendered_ids = $this->extractRenderedGalleryAttachmentIds($rendered_fragment);
        $value_match = $resolved_ids === $rendered_ids;

        $rendered_value = implode(',', $rendered_ids);
        $resolved_value = implode(',', $resolved_ids);
        $display_text = is_object($resolver) && method_exists($resolver, 'getDisplayValue')
            ? (string) $resolver->getDisplayValue($descriptor, $raw_value)
            : $resolved_value;

        $descriptor->render['rendered_value'] = $rendered_value;
        $descriptor->render['resolved_value'] = $resolved_value;
        $descriptor->render['rendered_text'] = $rendered_value;
        $descriptor->render['resolved_text'] = $display_text;
        $descriptor->render['display_key'] = 'gallery_ids';
        $descriptor->render['display_mode'] = 'text';
        $descriptor->render['render_verified'] = true;
        $descriptor->render['value_match'] = $value_match;

        if ($descriptor->status === 'editable' && ! $value_match) {
            $descriptor->status = 'unsupported';
            $descriptor->ui['warning'] = __(self::MISMATCH_WARNING, 'dbvc');
            $this->dropDescriptorByToken($descriptor->token);

            return $this->stripMarkerAttributesForToken($element_html, $descriptor->token);
        }

        $descriptor->render['sync_group'] = $this->buildSyncGroup(
            $descriptor->entity,
            $descriptor->source,
            'gallery_collection',
            'gallery_ids'
        );

        return $this->replaceMarkerAttributeForToken($element_html, $descriptor->token, 'data-dbvc-ve-group', $descriptor->render['sync_group']);
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function normalizeGalleryAttachmentIds($value)
    {
        $ids = [];
        $items = is_array($value) ? $value : [$value];

        foreach ($items as $item) {
            $id = 0;
            if (is_numeric($item)) {
                $id = absint($item);
            } elseif (is_array($item) && isset($item['id'])) {
                $id = absint($item['id']);
            } elseif (is_array($item) && isset($item['ID'])) {
                $id = absint($item['ID']);
            } elseif (is_object($item) && isset($item->ID)) {
                $id = absint($item->ID);
            }

            if ($id <= 0 || in_array($id, $ids, true)) {
                continue;
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param string $rendered_fragment
     * @return array<int, int>
     */
    private function extractRenderedGalleryAttachmentIds($rendered_fragment)
    {
        $ids = [];
        if (preg_match_all('/\bdata-id=(["\'])(\d+)\1/i', (string) $rendered_fragment, $matches)) {
            foreach ($matches[2] as $id) {
                $id = absint($id);
                if ($id > 0 && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        if (empty($ids) && preg_match_all('/\bwp-image-(\d+)\b/i', (string) $rendered_fragment, $matches)) {
            foreach ($matches[1] as $id) {
                $id = absint($id);
                if ($id > 0 && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * @param object             $resolver
     * @param EditableDescriptor $descriptor
     * @param string             $rendered_value
     * @return array<string, mixed>|null
     */
    private function attemptUniqueRowRebind($resolver, EditableDescriptor $descriptor, $rendered_value)
    {
        $source_type = isset($descriptor->source['type']) ? (string) $descriptor->source['type'] : '';
        if (! in_array($source_type, ['acf_repeater_subfield', 'acf_flexible_subfield'], true)) {
            return null;
        }

        if (! is_object($resolver) || ! method_exists($resolver, 'getRowCandidateValues')) {
            return null;
        }

        $candidates = $resolver->getRowCandidateValues($descriptor);
        if (! is_array($candidates) || empty($candidates)) {
            return null;
        }

        $matches = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || ! isset($candidate['index']) || ! is_numeric($candidate['index'])) {
                continue;
            }

            $candidate_display = $this->resolveDisplayPayload(
                $resolver,
                $descriptor,
                isset($candidate['value']) ? $candidate['value'] : '',
                $rendered_value
            );
            $candidate_text = isset($candidate_display['text']) ? (string) $candidate_display['text'] : '';

            if (! $this->valuesMatch($rendered_value, $candidate_text)) {
                continue;
            }

            $matches[] = [
                'index' => absint($candidate['index']),
                'raw_value' => isset($candidate['value']) ? $candidate['value'] : '',
                'display_payload' => $candidate_display,
            ];
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @param int                $row_index
     * @return void
     */
    private function applyRowDescriptorRebind(EditableDescriptor $descriptor, $row_index)
    {
        $descriptor->source['row_index'] = absint($row_index);
        $descriptor->path = $this->buildPathDescriptor($descriptor->source);
        $descriptor->mutation = $this->buildMutationDescriptor(
            $descriptor->source,
            isset($descriptor->render['context']) ? (string) $descriptor->render['context'] : 'text',
            $descriptor->scope,
            $descriptor->status,
            $descriptor->path,
            is_array($descriptor->loop) ? $descriptor->loop : []
        );
        $descriptor->render['source_group'] = $this->buildSourceGroup($descriptor->entity, $descriptor->source);
    }

    /**
     * @param string             $element_html
     * @param EditableDescriptor $descriptor
     * @return string
     */
    private function resolveRenderedFragment($element_html, EditableDescriptor $descriptor)
    {
        $context = isset($descriptor->render['context']) ? (string) $descriptor->render['context'] : 'text';

        if ($context === 'link_href') {
            return $this->findMarkerTag($element_html, $descriptor->token);
        }

        if ($context === 'image_src') {
            return (string) $element_html;
        }

        if ($context === 'background_image') {
            return $this->findMarkerTag($element_html, $descriptor->token);
        }

        return (string) $element_html;
    }

    /**
     * @param object $element
     * @return array<int, EditableDescriptor>
     */
    private function findElementDescriptors($element)
    {
        $element_uid = isset($element->uid) ? sanitize_text_field((string) $element->uid) : (isset($element->id) ? sanitize_text_field((string) $element->id) : '');
        if ($element_uid === '') {
            return [];
        }

        $current_loop_signature = $this->loops->getSignature($this->loops->resolve());
        $matched = [];

        foreach ($this->descriptors as $descriptor) {
            if (! $descriptor instanceof EditableDescriptor) {
                continue;
            }

            $descriptor_uid = isset($descriptor->render['element_uid']) ? (string) $descriptor->render['element_uid'] : (string) ($descriptor->render['element_id'] ?? '');
            if ($descriptor_uid !== $element_uid) {
                continue;
            }

            $descriptor_loop_signature = isset($descriptor->render['loop_signature']) ? (string) $descriptor->render['loop_signature'] : '';
            if ($descriptor_loop_signature !== $current_loop_signature) {
                if (! ($descriptor_loop_signature === '' && $current_loop_signature === '')) {
                    continue;
                }
            }

            $matched[] = $descriptor;
        }

        return $matched;
    }

    /**
     * @param string $seed
     * @param string $token
     * @return void
     */
    private function dropDescriptor($seed, $token)
    {
        $this->registry->remove($token);
        unset($this->descriptors[$token], $this->instrumented[$seed]);
    }

    /**
     * @param string $token
     * @return void
     */
    private function dropDescriptorByToken($token)
    {
        $token = sanitize_key((string) $token);
        if ($token === '') {
            return;
        }

        $seed = array_search($token, $this->instrumented, true);
        if ($seed !== false) {
            $this->dropDescriptor((string) $seed, $token);

            return;
        }

        $this->registry->remove($token);
        unset($this->descriptors[$token]);
    }

    /**
     * @param string $element_html
     * @return string
     */
    private function stripMarkerAttributes($element_html)
    {
        $clean = preg_replace('/\sdata-dbvc-ve="[^"]*"/', '', (string) $element_html, 1);
        $clean = preg_replace('/\sdata-dbvc-ve-status="[^"]*"/', '', (string) $clean, 1);
        $clean = preg_replace('/\sdata-dbvc-ve-scope="[^"]*"/', '', (string) $clean, 1);
        $clean = preg_replace('/\sdata-dbvc-ve-source-group="[^"]*"/', '', (string) $clean, 1);
        $clean = preg_replace('/\sdata-dbvc-ve-group="[^"]*"/', '', (string) $clean, 1);
        $clean = preg_replace('/\sdata-dbvc-ve-context="[^"]*"/', '', (string) $clean, 1);
        $clean = preg_replace('/\sdata-dbvc-ve-attribute="[^"]*"/', '', (string) $clean, 1);
        $clean = preg_replace('/\sdata-dbvc-ve-badge-label(?:="[^"]*")?/', '', (string) $clean, 1);
        $clean = preg_replace('/\sdata-dbvc-ve-input="[^"]*"/', '', (string) $clean, 1);

        return is_string($clean) ? $clean : (string) $element_html;
    }

    /**
     * @param string $element_html
     * @param string $token
     * @return string
     */
    private function stripMarkerAttributesForToken($element_html, $token)
    {
        $tag = $this->findMarkerTag($element_html, $token);
        if ($tag === '') {
            return $element_html;
        }

        $clean_tag = $this->stripMarkerAttributes($tag);

        return str_replace($tag, $clean_tag, (string) $element_html);
    }

    /**
     * Bricks image-gallery can apply root attributes to each gallery item.
     * Keep the first collection marker on the gallery wrapper and remove duplicates.
     *
     * @param string             $content
     * @param EditableDescriptor $descriptor
     * @return string
     */
    private function stripDuplicateGalleryCollectionMarkers($content, EditableDescriptor $descriptor)
    {
        if (($descriptor->render['context'] ?? '') !== 'gallery_collection') {
            return $content;
        }

        $token = sanitize_key((string) $descriptor->token);
        if ($token === '') {
            return $content;
        }

        $seen = false;
        $updated = preg_replace_callback(
            '/<[^>]*\sdata-dbvc-ve="' . preg_quote($token, '/') . '"[^>]*>/i',
            function ($matches) use (&$seen) {
                $tag = isset($matches[0]) ? (string) $matches[0] : '';
                if (! $seen) {
                    $seen = true;

                    return $tag;
                }

                return $this->stripMarkerAttributes($tag);
            },
            (string) $content
        );

        return is_string($updated) ? $updated : (string) $content;
    }

    /**
     * @param array<string, mixed> $entity
     * @param array<string, mixed> $source
     * @return string
     */
    private function buildSourceGroup(array $entity, array $source)
    {
        $seed = wp_json_encode(
            [
                'entity' => [
                    'type' => isset($entity['type']) ? (string) $entity['type'] : '',
                    'id' => isset($entity['id']) ? (string) $entity['id'] : '',
                    'acf_object_id' => isset($entity['acf_object_id']) ? (string) $entity['acf_object_id'] : '',
                ],
                'source' => [
                    'type' => isset($source['type']) ? (string) $source['type'] : '',
                    'field_name' => isset($source['field_name']) ? (string) $source['field_name'] : '',
                    'field_key' => isset($source['field_key']) ? (string) $source['field_key'] : '',
                    'field_selector' => isset($source['field_selector']) ? (string) $source['field_selector'] : '',
                    'leaf_field_name' => isset($source['leaf_field_name']) ? (string) $source['leaf_field_name'] : '',
                    'leaf_field_key' => isset($source['leaf_field_key']) ? (string) $source['leaf_field_key'] : '',
                    'container_type' => isset($source['container_type']) ? (string) $source['container_type'] : '',
                    'parent_field_name' => isset($source['parent_field_name']) ? (string) $source['parent_field_name'] : '',
                    'parent_field_key' => isset($source['parent_field_key']) ? (string) $source['parent_field_key'] : '',
                    'row_index' => isset($source['row_index']) ? (string) $source['row_index'] : '',
                    'layout_key' => isset($source['layout_key']) ? (string) $source['layout_key'] : '',
                    'layout_name' => isset($source['layout_name']) ? (string) $source['layout_name'] : '',
                    'group_path' => isset($source['group_path']) && is_array($source['group_path']) ? array_values($source['group_path']) : [],
                    'nested_repeater_path' => isset($source['nested_repeater_path']) && is_array($source['nested_repeater_path']) ? array_values($source['nested_repeater_path']) : [],
                    'native_query_ancestry' => $this->normalizeNativeQueryAncestryForHash(isset($source['native_query_ancestry']) && is_array($source['native_query_ancestry']) ? $source['native_query_ancestry'] : []),
                ],
            ]
        );

        return 'vesg_' . substr(hash('sha256', $this->registry->getSessionId() . '|' . (string) $seed), 0, 12);
    }

    /**
     * @param array<string, mixed> $entity
     * @param array<string, mixed> $source
     * @param string               $render_context
     * @param string               $display_key
     * @return string
     */
    private function buildSyncGroup(array $entity, array $source, $render_context = 'text', $display_key = '')
    {
        $seed = wp_json_encode(
            [
                'entity' => [
                    'type' => isset($entity['type']) ? (string) $entity['type'] : '',
                    'id' => isset($entity['id']) ? (string) $entity['id'] : '',
                    'acf_object_id' => isset($entity['acf_object_id']) ? (string) $entity['acf_object_id'] : '',
                ],
                'source' => [
                    'type' => isset($source['type']) ? (string) $source['type'] : '',
                    'expression' => isset($source['expression']) ? (string) $source['expression'] : '',
                    'field_name' => isset($source['field_name']) ? (string) $source['field_name'] : '',
                    'field_key' => isset($source['field_key']) ? (string) $source['field_key'] : '',
                    'field_selector' => isset($source['field_selector']) ? (string) $source['field_selector'] : '',
                    'leaf_field_name' => isset($source['leaf_field_name']) ? (string) $source['leaf_field_name'] : '',
                    'leaf_field_key' => isset($source['leaf_field_key']) ? (string) $source['leaf_field_key'] : '',
                    'container_type' => isset($source['container_type']) ? (string) $source['container_type'] : '',
                    'parent_field_name' => isset($source['parent_field_name']) ? (string) $source['parent_field_name'] : '',
                    'parent_field_key' => isset($source['parent_field_key']) ? (string) $source['parent_field_key'] : '',
                    'row_index' => isset($source['row_index']) ? (string) $source['row_index'] : '',
                    'layout_key' => isset($source['layout_key']) ? (string) $source['layout_key'] : '',
                    'layout_name' => isset($source['layout_name']) ? (string) $source['layout_name'] : '',
                    'group_path' => isset($source['group_path']) && is_array($source['group_path']) ? array_values($source['group_path']) : [],
                    'nested_repeater_path' => isset($source['nested_repeater_path']) && is_array($source['nested_repeater_path']) ? array_values($source['nested_repeater_path']) : [],
                    'native_query_ancestry' => $this->normalizeNativeQueryAncestryForHash(isset($source['native_query_ancestry']) && is_array($source['native_query_ancestry']) ? $source['native_query_ancestry'] : []),
                ],
                'render' => [
                    'context' => (string) $render_context,
                    'display_key' => (string) $display_key,
                ],
            ]
        );

        return 'veg_' . substr(hash('sha256', $this->registry->getSessionId() . '|' . (string) $seed), 0, 12);
    }

    /**
     * @param string $element_html
     * @param string $attribute
     * @param string $value
     * @return string
     */
    private function replaceMarkerAttribute($element_html, $attribute, $value)
    {
        $pattern = '/\s' . preg_quote((string) $attribute, '/') . '="[^"]*"/';
        $replacement = ' ' . sanitize_key((string) $attribute) . '="' . esc_attr((string) $value) . '"';
        $updated = preg_replace($pattern, $replacement, (string) $element_html, 1);

        return is_string($updated) ? $updated : (string) $element_html;
    }

    /**
     * @param string $element_html
     * @param string $token
     * @return string
     */
    private function findMarkerTag($element_html, $token)
    {
        $pattern = '/<[^>]*\sdata-dbvc-ve="' . preg_quote(sanitize_key((string) $token), '/') . '"[^>]*>/i';
        if (! preg_match($pattern, (string) $element_html, $matches)) {
            return '';
        }

        return isset($matches[0]) ? (string) $matches[0] : '';
    }

    /**
     * @param string $element_html
     * @param string $token
     * @param string $attribute
     * @param string $value
     * @return string
     */
    private function replaceMarkerAttributeForToken($element_html, $token, $attribute, $value)
    {
        $tag = $this->findMarkerTag($element_html, $token);
        if ($tag === '') {
            return $element_html;
        }

        $updated_tag = $this->replaceMarkerAttribute($tag, $attribute, $value);

        return str_replace($tag, $updated_tag, (string) $element_html);
    }

    /**
     * @param string             $content
     * @param EditableDescriptor $descriptor
     * @param string             $element_id
     * @param int                $occurrence_index
     * @return string
     */
    private function injectMarkerIntoElementOccurrence($content, EditableDescriptor $descriptor, $element_id, $occurrence_index)
    {
        $pattern = '/<([A-Za-z][A-Za-z0-9:-]*)([^>]*\bclass=(["\'])(?:(?:(?!\3).)*)\bbrxe-' . preg_quote($element_id, '/') . '\b(?:(?:(?!\3).)*)\3[^>]*)>/s';
        if (! preg_match_all($pattern, (string) $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $content;
        }

        if (! isset($matches[0][$occurrence_index][0], $matches[0][$occurrence_index][1])) {
            return $content;
        }

        $tag = (string) $matches[0][$occurrence_index][0];
        $offset = (int) $matches[0][$occurrence_index][1];
        if ($tag === '' || strpos($tag, 'data-dbvc-ve=') !== false) {
            return $content;
        }

        $updated_tag = $this->appendMarkerAttributesToTag($tag, $descriptor);
        if ($updated_tag === $tag) {
            return $content;
        }

        return substr((string) $content, 0, $offset)
            . $updated_tag
            . substr((string) $content, $offset + strlen($tag));
    }

    /**
     * @param string             $content
     * @param EditableDescriptor $descriptor
     * @param string             $element_id
     * @param int                $occurrence_index
     * @return string
     */
    private function injectEmptyQueryCollectionMarkerAfterLoopComment($content, EditableDescriptor $descriptor, $element_id, $occurrence_index)
    {
        if (! $this->isEmptyQueryCollectionDescriptor($descriptor)) {
            return $content;
        }

        $pattern = '/<!--\s*brx-loop-start-' . preg_quote($element_id, '/') . '\s*-->/';
        if (! preg_match_all($pattern, (string) $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $content;
        }

        if (! isset($matches[0][$occurrence_index][0], $matches[0][$occurrence_index][1])) {
            return $content;
        }

        $comment = (string) $matches[0][$occurrence_index][0];
        $offset = (int) $matches[0][$occurrence_index][1];
        $marker_tag = $this->appendMarkerAttributesToTag(
            '<span class="dbvc-ve-empty-query-marker" data-dbvc-ve-empty-query="1" aria-hidden="true">',
            $descriptor
        ) . '</span>';

        return substr((string) $content, 0, $offset + strlen($comment))
            . $marker_tag
            . substr((string) $content, $offset + strlen($comment));
    }

    /**
     * Bricks can replace a fully empty query loop with a query-trail placeholder
     * instead of the loop-start comment. Anchor the same hidden marker there.
     *
     * @param string             $content
     * @param EditableDescriptor $descriptor
     * @param string             $element_id
     * @param int                $occurrence_index
     * @return string
     */
    private function injectEmptyQueryCollectionMarkerAfterQueryTrail($content, EditableDescriptor $descriptor, $element_id, $occurrence_index)
    {
        if (! $this->isEmptyQueryCollectionDescriptor($descriptor)) {
            return $content;
        }

        $pattern = '/<div\b(?=[^>]*\bclass=(["\'])(?:(?:(?!\1).)*)\bbrx-query-trail\b(?:(?:(?!\1).)*)\1)(?=[^>]*\bdata-query-element-id=(["\'])' . preg_quote($element_id, '/') . '\2)[^>]*>\s*<\/div>/is';
        if (! preg_match_all($pattern, (string) $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $content;
        }

        if (! isset($matches[0][$occurrence_index][0], $matches[0][$occurrence_index][1])) {
            return $content;
        }

        $query_trail = (string) $matches[0][$occurrence_index][0];
        $offset = (int) $matches[0][$occurrence_index][1];
        $marker_tag = $this->appendMarkerAttributesToTag(
            '<span class="dbvc-ve-empty-query-marker" data-dbvc-ve-empty-query="1" aria-hidden="true">',
            $descriptor
        ) . '</span>';

        return substr((string) $content, 0, $offset + strlen($query_trail))
            . $marker_tag
            . substr((string) $content, $offset + strlen($query_trail));
    }

    /**
     * @param string $content
     * @param string $token
     * @return bool
     */
    private function contentContainsMarkerToken($content, $token)
    {
        $token = esc_attr((string) $token);
        if ($token === '') {
            return false;
        }

        return strpos((string) $content, 'data-dbvc-ve="' . $token . '"') !== false
            || strpos((string) $content, "data-dbvc-ve='" . $token . "'") !== false;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    private function isEmptyQueryCollectionDescriptor(EditableDescriptor $descriptor)
    {
        $render_context = isset($descriptor->render['context']) ? sanitize_key((string) $descriptor->render['context']) : '';
        $source_type = isset($descriptor->source['type']) ? sanitize_key((string) $descriptor->source['type']) : '';

        return $render_context === 'query_collection'
            && in_array($source_type, ['acf_collection_field', 'post_terms_collection'], true)
            && ! empty($descriptor->source['query_result_empty']);
    }

    /**
     * Register a normal image/gallery descriptor even when Bricks emits no media markup.
     * The descriptor only becomes visible later if the resolved source is empty.
     *
     * @param object $element
     * @return void
     */
    private function maybeRegisterMissingMediaDescriptorForElement($element)
    {
        if (! is_object($element)) {
            return;
        }

        foreach ($this->findElementDescriptors($element) as $descriptor) {
            if ($this->isMissingMediaDescriptorCandidate($descriptor)) {
                return;
            }
        }

        $inspection = $this->inspector->inspect($element);
        if (empty($inspection['supported'])) {
            return;
        }

        $render_context = isset($inspection['render_context']) ? sanitize_key((string) $inspection['render_context']) : '';
        if (! in_array($render_context, ['image_src', 'background_image', 'gallery_collection'], true)) {
            return;
        }

        $this->instrumentInspection([], '_missing_media', $element, $inspection);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    private function isMissingMediaDescriptorCandidate(EditableDescriptor $descriptor)
    {
        $render_context = isset($descriptor->render['context']) ? sanitize_key((string) $descriptor->render['context']) : '';
        if (! in_array($render_context, ['image_src', 'background_image', 'gallery_collection'], true)) {
            return false;
        }

        $source_type = isset($descriptor->source['type']) ? sanitize_key((string) $descriptor->source['type']) : '';
        $field_type = isset($descriptor->source['field_type']) ? sanitize_key((string) $descriptor->source['field_type']) : '';
        $field_name = isset($descriptor->source['field_name']) ? sanitize_key((string) $descriptor->source['field_name']) : '';

        return in_array($field_type, ['image', 'gallery'], true)
            || ($source_type === 'post_field' && $field_name === 'featured_image');
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    private function isMissingMediaDescriptor(EditableDescriptor $descriptor)
    {
        return ! empty($descriptor->render['missing_media_anchor'])
            && ! empty($this->resolveMissingMediaAnchorElementIds($descriptor))
            && $this->isMissingMediaDescriptorCandidate($descriptor);
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return bool
     */
    private function descriptorHasEmptyMediaValue(EditableDescriptor $descriptor)
    {
        try {
            $resolver = $this->resolvers->resolve($descriptor);
            if (! is_object($resolver) || ! method_exists($resolver, 'name') || $resolver->name() === 'unsupported') {
                return false;
            }

            $value = $resolver->getValue($descriptor);
            $render_context = isset($descriptor->render['context']) ? sanitize_key((string) $descriptor->render['context']) : '';
            if ($render_context === 'gallery_collection') {
                return empty($value) || (is_array($value) && count($value) === 0);
            }

            $display_value = $resolver->getDisplayValue($descriptor, $value);

            return trim((string) $display_value) === '';
        } catch (\Throwable $exception) {
            unset($exception);

            return false;
        }
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return void
     */
    private function markMissingMediaDescriptorVerified(EditableDescriptor $descriptor)
    {
        $descriptor->render['missing_media_anchor'] = true;
        $descriptor->render['rendered_value'] = '';
        $descriptor->render['resolved_value'] = '';
        $descriptor->render['rendered_text'] = '';
        $descriptor->render['resolved_text'] = '';
        $descriptor->render['display_key'] = 'src';
        $descriptor->render['display_mode'] = 'text';
        $descriptor->render['render_verified'] = true;
        $descriptor->render['value_match'] = true;
        $descriptor->render['sync_group'] = $this->buildSyncGroup(
            $descriptor->entity,
            $descriptor->source,
            isset($descriptor->render['context']) ? (string) $descriptor->render['context'] : 'image_src',
            'src'
        );
    }

    /**
     * @param string             $content
     * @param EditableDescriptor $descriptor
     * @param int                $occurrence_index
     * @return string
     */
    private function injectMissingMediaMarkerIntoParentOccurrence($content, EditableDescriptor $descriptor, $occurrence_index)
    {
        if (! $this->isMissingMediaDescriptor($descriptor)) {
            return $content;
        }

        foreach ($this->resolveMissingMediaAnchorElementIds($descriptor) as $anchor_element_id) {
            $match = $this->findBricksElementOpeningTagMatch($content, $anchor_element_id, $occurrence_index);
            if (empty($match)) {
                continue;
            }

            $tag = isset($match['tag']) ? (string) $match['tag'] : '';
            $offset = isset($match['offset']) ? (int) $match['offset'] : 0;
            if ($tag === '' || strpos($tag, 'data-dbvc-ve=') !== false) {
                continue;
            }

            $updated_tag = $this->appendMarkerAttributesToTag(
                $tag,
                $descriptor,
                [
                    'data-dbvc-ve-missing-media' => '1',
                    'data-dbvc-ve-missing-media-kind' => $this->resolveMissingMediaKind($descriptor),
                    'data-dbvc-ve-missing-media-anchor' => $anchor_element_id,
                    'data-dbvc-ve-badge-label' => $this->resolveMissingMediaBadgeLabel($descriptor),
                    'data-dbvc-ve-display-value' => '',
                ]
            );
            if ($updated_tag === $tag) {
                continue;
            }

            return substr((string) $content, 0, $offset)
                . $updated_tag
                . substr((string) $content, $offset + strlen($tag));
        }

        return $content;
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return array<int, string>
     */
    private function resolveMissingMediaAnchorElementIds(EditableDescriptor $descriptor)
    {
        $current = isset($descriptor->render['parent_element_id'])
            ? sanitize_key((string) $descriptor->render['parent_element_id'])
            : '';
        $ids = [];
        $visited = [];

        for ($depth = 0; $depth < 12 && $current !== '' && empty($visited[$current]); $depth++) {
            $visited[$current] = true;
            $ids[] = $current;

            $metadata = isset($this->element_metadata_by_id[$current]) && is_array($this->element_metadata_by_id[$current])
                ? $this->element_metadata_by_id[$current]
                : [];
            $current = isset($metadata['parent']) ? sanitize_key((string) $metadata['parent']) : '';
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param string $content
     * @param string $element_id
     * @param int    $occurrence_index
     * @return array<string, mixed>
     */
    private function findBricksElementOpeningTagMatch($content, $element_id, $occurrence_index)
    {
        $element_id = sanitize_key((string) $element_id);
        if ($element_id === '') {
            return [];
        }

        $pattern = '/<([A-Za-z][A-Za-z0-9:-]*)([^>]*\bclass=(["\'])(?:(?:(?!\3).)*)\bbrxe-' . preg_quote($element_id, '/') . '\b(?:(?:(?!\3).)*)\3[^>]*)>/s';
        if (! preg_match_all($pattern, (string) $content, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        if (! isset($matches[0][$occurrence_index][0], $matches[0][$occurrence_index][1])) {
            return [];
        }

        return [
            'tag' => (string) $matches[0][$occurrence_index][0],
            'offset' => (int) $matches[0][$occurrence_index][1],
        ];
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    private function resolveMissingMediaBadgeLabel(EditableDescriptor $descriptor)
    {
        $source_type = isset($descriptor->source['type']) ? sanitize_key((string) $descriptor->source['type']) : '';
        $field_name = isset($descriptor->source['field_name']) ? sanitize_key((string) $descriptor->source['field_name']) : '';
        if ($source_type === 'post_field' && $field_name === 'featured_image') {
            return __('Add Featured Image', 'dbvc');
        }

        $field_type = isset($descriptor->source['field_type']) ? sanitize_key((string) $descriptor->source['field_type']) : '';
        $label = isset($descriptor->ui['label']) ? sanitize_text_field((string) $descriptor->ui['label']) : '';
        $default_label = $field_type === 'gallery' ? 'gallery' : 'image';
        if ($label !== '' && strtolower($label) !== $default_label) {
            return sprintf(
                /* translators: %s: media field label. */
                __('Add %s', 'dbvc'),
                $label
            );
        }

        if ($field_type === 'gallery') {
            return __('Add Gallery', 'dbvc');
        }

        return __('Add Image', 'dbvc');
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return string
     */
    private function resolveMissingMediaKind(EditableDescriptor $descriptor)
    {
        $render_context = isset($descriptor->render['context']) ? sanitize_key((string) $descriptor->render['context']) : '';

        return $render_context === 'gallery_collection' ? 'gallery' : 'image';
    }

    /**
     * @param string             $element_html
     * @param EditableDescriptor $descriptor
     * @return string
     */
    private function ensureMarkerAttributesForDescriptor($element_html, EditableDescriptor $descriptor)
    {
        if ($this->findMarkerTag($element_html, $descriptor->token) !== '') {
            return $element_html;
        }

        if (! preg_match('/<([A-Za-z][A-Za-z0-9:-]*)(\s[^>]*)?>/s', (string) $element_html, $matches)) {
            return $element_html;
        }

        $tag = isset($matches[0]) ? (string) $matches[0] : '';
        if ($tag === '' || strpos($tag, 'data-dbvc-ve=') !== false) {
            return $element_html;
        }

        $updated_tag = $this->appendMarkerAttributesToTag($tag, $descriptor);

        return $updated_tag !== $tag
            ? preg_replace('/' . preg_quote($tag, '/') . '/', addcslashes($updated_tag, '\\$'), (string) $element_html, 1) ?: (string) $element_html
            : $element_html;
    }

    /**
     * @param string             $tag
     * @param EditableDescriptor $descriptor
     * @param array<string, mixed> $extra_attributes
     * @return string
     */
    private function appendMarkerAttributesToTag($tag, EditableDescriptor $descriptor, array $extra_attributes = [])
    {
        $attributes = [
            'data-dbvc-ve' => $descriptor->token,
            'data-dbvc-ve-status' => $descriptor->status,
            'data-dbvc-ve-scope' => $this->mapScopeToMarkerValue($descriptor->scope),
            'data-dbvc-ve-source-group' => isset($descriptor->render['source_group']) ? (string) $descriptor->render['source_group'] : '',
            'data-dbvc-ve-group' => isset($descriptor->render['sync_group']) ? (string) $descriptor->render['sync_group'] : '',
            'data-dbvc-ve-context' => isset($descriptor->render['context']) ? (string) $descriptor->render['context'] : 'text',
            'data-dbvc-ve-badge-label' => isset($descriptor->ui['badgeLabel']) ? sanitize_text_field((string) $descriptor->ui['badgeLabel']) : '',
            'data-dbvc-ve-input' => isset($descriptor->ui['input']) ? sanitize_key((string) $descriptor->ui['input']) : '',
            'data-dbvc-ve-query-element-id' => isset($descriptor->source['query_element_id']) ? sanitize_text_field((string) $descriptor->source['query_element_id']) : '',
        ];

        if (! empty($descriptor->render['attribute'])) {
            $attributes['data-dbvc-ve-attribute'] = (string) $descriptor->render['attribute'];
        }

        foreach ($extra_attributes as $name => $value) {
            $name = sanitize_key((string) $name);
            if ($name === '') {
                continue;
            }

            $attributes[$name] = is_scalar($value) ? (string) $value : '';
        }

        $attribute_markup = '';
        foreach ($attributes as $name => $value) {
            if ($value === '') {
                continue;
            }

            $attribute_markup .= ' ' . sanitize_key((string) $name) . '="' . esc_attr((string) $value) . '"';
        }

        if ($attribute_markup === '') {
            return $tag;
        }

        $closing = substr($tag, -2) === '/>' ? '/>' : '>';
        $tag_base = substr($tag, 0, -strlen($closing));
        return $tag_base . $attribute_markup . $closing;
    }

    /**
     * @param string $scope
     * @return string
     */
    private function mapScopeToMarkerValue($scope)
    {
        if ($scope === 'shared_entity') {
            return 'shared';
        }

        if ($scope === 'related_entity') {
            return 'related';
        }

        return 'current';
    }

    /**
     * @param array<string, mixed> $page_context
     * @return array<string, mixed>
     */
    private function buildPageDescriptor(array $page_context)
    {
        return [
            'type' => isset($page_context['entityType']) ? sanitize_key((string) $page_context['entityType']) : '',
            'id' => isset($page_context['entityId']) ? absint($page_context['entityId']) : 0,
            'subtype' => isset($page_context['postType']) ? sanitize_key((string) $page_context['postType']) : '',
            'taxonomy' => isset($page_context['taxonomy']) ? sanitize_key((string) $page_context['taxonomy']) : '',
            'archiveType' => isset($page_context['archiveType']) ? sanitize_key((string) $page_context['archiveType']) : '',
            'archiveKey' => isset($page_context['archiveKey']) ? sanitize_text_field((string) $page_context['archiveKey']) : '',
            'isArchive' => ! empty($page_context['isArchive']),
            'isPostTypeArchive' => ! empty($page_context['isPostTypeArchive']),
            'isTaxonomyArchive' => ! empty($page_context['isTaxonomyArchive']),
            'url' => isset($page_context['url']) ? esc_url_raw((string) $page_context['url']) : '',
        ];
    }

    /**
     * @param array<string, mixed> $entity
     * @param string               $scope
     * @param array<string, mixed> $page_descriptor
     * @param array<string, mixed> $loop_context
     * @return array<string, mixed>
     */
    private function buildOwnerDescriptor(array $entity, $scope, array $page_descriptor, array $loop_context)
    {
        $page_id = isset($page_descriptor['id']) ? absint($page_descriptor['id']) : 0;
        $owner_id = isset($entity['id']) && is_numeric($entity['id']) ? absint($entity['id']) : 0;
        $acf_object_id = isset($entity['acf_object_id']) ? $entity['acf_object_id'] : '';

        return [
            'type' => isset($entity['type']) ? sanitize_key((string) $entity['type']) : '',
            'id' => $owner_id,
            'subtype' => isset($entity['subtype']) ? sanitize_key((string) $entity['subtype']) : '',
            'acf_object_id' => is_numeric($acf_object_id)
                ? absint($acf_object_id)
                : sanitize_text_field((string) $acf_object_id),
            'scope' => sanitize_key((string) $scope),
            'isCurrentPageEntity' => $scope === 'current_entity',
            'isLoopOwned' => ! empty($loop_context['active']) && $scope === 'related_entity',
            'pageEntityId' => $page_id,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function buildPathDescriptor(array $source)
    {
        $container_type = isset($source['container_type']) ? sanitize_key((string) $source['container_type']) : '';
        $field_name = isset($source['field_name']) ? sanitize_key((string) $source['field_name']) : '';
        $field_key = isset($source['field_key']) ? sanitize_key((string) $source['field_key']) : '';
        $parent_field_name = isset($source['parent_field_name']) ? sanitize_key((string) $source['parent_field_name']) : '';
        $parent_field_key = isset($source['parent_field_key']) ? sanitize_key((string) $source['parent_field_key']) : '';
        $layout_key = isset($source['layout_key']) ? sanitize_key((string) $source['layout_key']) : '';
        $layout_name = isset($source['layout_name']) ? sanitize_key((string) $source['layout_name']) : '';
        $native_query_kind = isset($source['native_query_kind']) ? sanitize_key((string) $source['native_query_kind']) : '';
        $native_query_selector = isset($source['native_query_selector']) ? sanitize_key((string) $source['native_query_selector']) : '';
        $native_query_object_type = isset($source['native_query_object_type']) ? sanitize_key((string) $source['native_query_object_type']) : '';
        $parent_native_query_kind = isset($source['parent_native_query_kind']) ? sanitize_key((string) $source['parent_native_query_kind']) : '';
        $parent_native_query_selector = isset($source['parent_native_query_selector']) ? sanitize_key((string) $source['parent_native_query_selector']) : '';
        $parent_native_query_object_type = isset($source['parent_native_query_object_type']) ? sanitize_key((string) $source['parent_native_query_object_type']) : '';
        $native_query_ancestry = $this->normalizeNativeQueryAncestry(isset($source['native_query_ancestry']) && is_array($source['native_query_ancestry']) ? $source['native_query_ancestry'] : []);
        $group_path = isset($source['group_path']) && is_array($source['group_path'])
            ? array_values(
                array_filter(
                    array_map(
                        static function ($value) {
                            return sanitize_key((string) $value);
                        },
                        $source['group_path']
                    )
                )
            )
            : [];
        $group_key_path = isset($source['group_key_path']) && is_array($source['group_key_path'])
            ? array_values(
                array_filter(
                    array_map(
                        static function ($value) {
                            return sanitize_key((string) $value);
                        },
                        $source['group_key_path']
                    )
                )
            )
            : [];
        $nested_repeater_path = isset($source['nested_repeater_path']) && is_array($source['nested_repeater_path'])
            ? array_values(
                array_filter(
                    array_map(
                        static function ($segment) {
                            if (! is_array($segment)) {
                                return null;
                            }

                            $field_name = isset($segment['field_name']) ? sanitize_key((string) $segment['field_name']) : '';
                            $field_key = isset($segment['field_key']) ? sanitize_key((string) $segment['field_key']) : '';
                            $field_selector = isset($segment['field_selector']) ? sanitize_key((string) $segment['field_selector']) : '';
                            $row_index = isset($segment['row_index']) && $segment['row_index'] !== null && is_numeric($segment['row_index'])
                                ? absint($segment['row_index'])
                                : null;

                            if ($field_name === '' && $field_key === '' && $field_selector === '') {
                                return null;
                            }

                            return [
                                'fieldName' => $field_name,
                                'fieldKey' => $field_key,
                                'fieldSelector' => $field_selector,
                                'rowIndex' => $row_index,
                            ];
                        },
                        $source['nested_repeater_path']
                    )
                )
            )
            : [];
        $row_index = isset($source['row_index']) && $source['row_index'] !== null && $source['row_index'] !== ''
            ? absint($source['row_index'])
            : null;
        $root_field_name = $container_type !== '' ? $parent_field_name : $field_name;
        $root_field_key = $container_type !== '' ? $parent_field_key : $field_key;
        $segments = [];
        $summary = [];

        if ($container_type === 'repeater' && $root_field_name !== '') {
            $segments[] = [
                'type' => 'repeater',
                'fieldName' => $root_field_name,
                'fieldKey' => $root_field_key,
                'index' => $row_index,
            ];
            $summary[] = 'repeater:' . $root_field_name;
            if ($row_index !== null) {
                $summary[] = 'row:' . ($row_index + 1);
            }
        } elseif ($container_type === 'flexible_content' && $root_field_name !== '') {
            $segments[] = [
                'type' => 'flexible_content',
                'fieldName' => $root_field_name,
                'fieldKey' => $root_field_key,
                'index' => $row_index,
                'layoutKey' => $layout_key,
                'layoutName' => $layout_name,
            ];
            $summary[] = 'flexible:' . $root_field_name;
            if ($row_index !== null) {
                $summary[] = 'row:' . ($row_index + 1);
            }
        }

        foreach ($nested_repeater_path as $nested_segment) {
            $nested_field_name = isset($nested_segment['fieldName']) ? sanitize_key((string) $nested_segment['fieldName']) : '';
            $nested_field_key = isset($nested_segment['fieldKey']) ? sanitize_key((string) $nested_segment['fieldKey']) : '';
            $nested_row_index = array_key_exists('rowIndex', $nested_segment) ? $nested_segment['rowIndex'] : null;

            $segments[] = [
                'type' => 'repeater',
                'fieldName' => $nested_field_name,
                'fieldKey' => $nested_field_key,
                'fieldSelector' => isset($nested_segment['fieldSelector']) ? sanitize_key((string) $nested_segment['fieldSelector']) : '',
                'index' => $nested_row_index,
            ];

            if ($nested_field_name !== '' || $nested_field_key !== '') {
                $summary[] = 'repeater:' . ($nested_field_name !== '' ? $nested_field_name : $nested_field_key);
            }

            if ($nested_row_index !== null) {
                $summary[] = 'row:' . ($nested_row_index + 1);
            }
        }

        $native_ancestry_summary = [];

        foreach ($native_query_ancestry as $ancestor_native_query) {
            $ancestor_kind = isset($ancestor_native_query['kind']) ? sanitize_key((string) $ancestor_native_query['kind']) : '';
            $ancestor_selector = isset($ancestor_native_query['selector']) ? sanitize_key((string) $ancestor_native_query['selector']) : '';
            $ancestor_object_type = isset($ancestor_native_query['objectType']) ? sanitize_key((string) $ancestor_native_query['objectType']) : '';
            $ancestor_loop_index = isset($ancestor_native_query['loopIndex']) ? sanitize_text_field((string) $ancestor_native_query['loopIndex']) : '';

            $segments[] = [
                'type' => 'native_acf_query_ancestor',
                'kind' => $ancestor_kind,
                'selector' => $ancestor_selector,
                'objectType' => $ancestor_object_type,
                'loopIndex' => $ancestor_loop_index,
            ];

            $ancestor_summary = $ancestor_kind !== '' ? $ancestor_kind : 'query';
            if ($ancestor_selector !== '') {
                $ancestor_summary .= ':' . $ancestor_selector;
            }
            if ($ancestor_loop_index !== '' && is_numeric($ancestor_loop_index)) {
                $ancestor_summary .= '@' . (absint($ancestor_loop_index) + 1);
            }

            $native_ancestry_summary[] = $ancestor_summary;
        }

        if (! empty($native_ancestry_summary)) {
            $summary[] = 'native-chain:' . implode('>', $native_ancestry_summary);
        }

        if (empty($native_query_ancestry) && ($parent_native_query_kind !== '' || $parent_native_query_selector !== '' || $parent_native_query_object_type !== '')) {
            $segments[] = [
                'type' => 'parent_native_acf_query',
                'kind' => $parent_native_query_kind,
                'selector' => $parent_native_query_selector,
                'objectType' => $parent_native_query_object_type,
            ];

            $parent_native_summary = 'parent-native:';

            if ($parent_native_query_kind !== '') {
                $parent_native_summary .= $parent_native_query_kind;
            }

            if ($parent_native_query_selector !== '') {
                $parent_native_summary .= ($parent_native_query_kind !== '' ? ':' : '') . $parent_native_query_selector;
            }

            if ($parent_native_summary !== 'parent-native:') {
                $summary[] = $parent_native_summary;
            }
        }

        if ($native_query_kind !== '' || $native_query_selector !== '' || $native_query_object_type !== '') {
            $segments[] = [
                'type' => 'native_acf_query',
                'kind' => $native_query_kind,
                'selector' => $native_query_selector,
                'objectType' => $native_query_object_type,
            ];

            $native_summary = 'native:';

            if ($native_query_kind !== '') {
                $native_summary .= $native_query_kind;
            }

            if ($native_query_selector !== '') {
                $native_summary .= ($native_query_kind !== '' ? ':' : '') . $native_query_selector;
            }

            if ($native_summary !== 'native:') {
                $summary[] = $native_summary;
            }
        }

        if ($layout_name !== '' || $layout_key !== '') {
            $summary[] = 'layout:' . ($layout_name !== '' ? $layout_name : $layout_key);
        }

        $group_depth = max(count($group_path), count($group_key_path));
        $group_summary_path = [];

        for ($index = 0; $index < $group_depth; $index++) {
            $group_name = isset($group_path[$index]) ? $group_path[$index] : '';
            $group_key = isset($group_key_path[$index]) ? $group_key_path[$index] : '';

            $segments[] = [
                'type' => 'group',
                'fieldName' => $group_name,
                'fieldKey' => $group_key,
            ];

            if ($group_name !== '' || $group_key !== '') {
                $group_summary_path[] = $group_name !== '' ? $group_name : $group_key;
            }
        }

        if (! empty($group_summary_path)) {
            $summary[] = 'group:' . implode('>', $group_summary_path);
        }

        if ($field_name !== '') {
            $segments[] = [
                'type' => 'field',
                'fieldName' => $field_name,
                'fieldKey' => $field_key,
            ];
            $summary[] = 'field:' . $field_name;
        }

        return [
            'containerType' => $container_type,
            'rootFieldName' => $root_field_name,
            'rootFieldKey' => $root_field_key,
            'fieldName' => $field_name,
            'fieldKey' => $field_key,
            'rowIndex' => $row_index,
            'layoutKey' => $layout_key,
            'layoutName' => $layout_name,
            'groupPath' => $group_path,
            'groupKeyPath' => $group_key_path,
            'nestedRepeaterPath' => $nested_repeater_path,
            'nativeQueryKind' => $native_query_kind,
            'nativeQuerySelector' => $native_query_selector,
            'nativeQueryObjectType' => $native_query_object_type,
            'parentNativeQueryKind' => $parent_native_query_kind,
            'parentNativeQuerySelector' => $parent_native_query_selector,
            'parentNativeQueryObjectType' => $parent_native_query_object_type,
            'nativeQueryAncestry' => $native_query_ancestry,
            'isNested' => $container_type !== '',
            'segments' => $segments,
            'summary' => implode(' / ', $summary),
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param string               $render_context
     * @param string               $scope
     * @param string               $status
     * @param array<string, mixed> $path_descriptor
     * @param array<string, mixed> $loop_context
     * @return array<string, mixed>
     */
    private function buildMutationDescriptor(array $source, $render_context, $scope, $status, array $path_descriptor, array $loop_context)
    {
        $field_type = isset($source['field_type']) ? sanitize_key((string) $source['field_type']) : '';
        $container_type = isset($path_descriptor['containerType']) ? sanitize_key((string) $path_descriptor['containerType']) : '';
        $native_loop_kind = isset($source['native_query_kind']) ? sanitize_key((string) $source['native_query_kind']) : '';
        $parent_native_loop_kind = isset($source['parent_native_query_kind']) ? sanitize_key((string) $source['parent_native_query_kind']) : '';
        $native_loop_ancestry = $this->buildNativeQueryAncestryLabels(isset($path_descriptor['nativeQueryAncestry']) && is_array($path_descriptor['nativeQueryAncestry']) ? $path_descriptor['nativeQueryAncestry'] : []);
        $kind = 'scalar';

        if ($render_context === 'query_collection' && in_array($field_type, ['relationship', 'post_object', 'taxonomy'], true)) {
            $kind = 'collection';
        } elseif (in_array($field_type, ['link', 'image', 'post_object', 'relationship', 'taxonomy'], true)) {
            $kind = 'structured';
        } elseif ($field_type === 'gallery') {
            $kind = 'collection';
        } elseif (in_array($field_type, ['checkbox', 'select'], true) && ! empty($source['reference_multiple'])) {
            $kind = 'collection';
        } elseif (in_array($field_type, ['checkbox', 'select', 'radio', 'button_group'], true)) {
            $kind = 'structured';
        }

        $target = 'field';
        if ($container_type === 'repeater') {
            $target = 'row';
        } elseif ($container_type === 'flexible_content') {
            $target = 'layout';
        }
        $contract = 'direct_field';

        if ($render_context === 'query_collection'
            && $field_type === 'relationship'
            && isset($source['query_subset_write_mode'])
            && sanitize_key((string) $source['query_subset_write_mode']) === 'replace_target_post_type_subset') {
            $contract = $scope === 'shared_entity'
                ? 'shared_relationship_collection_filtered_subset'
                : 'relationship_collection_filtered_subset';
        } elseif ($render_context === 'query_collection'
            && $field_type === 'post_object'
            && isset($source['query_subset_write_mode'])
            && sanitize_key((string) $source['query_subset_write_mode']) === 'replace_target_post_type_subset') {
            $contract = $scope === 'shared_entity'
                ? 'shared_post_object_collection_filtered_subset'
                : 'post_object_collection_filtered_subset';
        } elseif ($render_context === 'query_collection' && $field_type === 'relationship') {
            if (! empty($loop_context['active']) && $scope === 'related_entity') {
                $contract = 'loop_owned_relationship_collection';
            } elseif ($scope === 'shared_entity') {
                $contract = 'shared_relationship_collection';
            } else {
                $contract = 'relationship_collection';
            }
        } elseif ($render_context === 'query_collection' && $field_type === 'post_object') {
            if (! empty($loop_context['active']) && $scope === 'related_entity') {
                $contract = 'loop_owned_post_object_collection';
            } elseif ($scope === 'shared_entity') {
                $contract = 'shared_post_object_collection';
            } else {
                $contract = 'post_object_collection';
            }
        } elseif ($render_context === 'query_collection' && $field_type === 'taxonomy' && (isset($source['type']) ? sanitize_key((string) $source['type']) : '') === 'post_terms_collection') {
            $contract = $scope === 'related_entity'
                ? 'loop_owned_post_terms_collection'
                : 'post_terms_collection';
        } elseif ($target === 'row') {
            $contract = 'repeater_row';
        } elseif ($target === 'layout') {
            $contract = 'flexible_layout';
        }

        if (! empty($loop_context['active']) && $scope === 'related_entity') {
            if ($render_context === 'query_collection' && in_array($field_type, ['relationship', 'post_object', 'taxonomy'], true)) {
                // collection contracts are already scope-specific above
            } elseif ($target === 'row') {
                $contract = 'loop_owned_repeater_row';
            } elseif ($target === 'layout') {
                $contract = 'loop_owned_flexible_layout';
            } else {
                $contract = 'loop_owned_field';
            }
        } elseif ($scope === 'shared_entity') {
            if ($render_context === 'query_collection' && in_array($field_type, ['relationship', 'post_object', 'taxonomy'], true)) {
                // collection contracts are already scope-specific above
            } elseif ($target === 'row') {
                $contract = 'shared_repeater_row';
            } elseif ($target === 'layout') {
                $contract = 'shared_flexible_layout';
            } else {
                $contract = 'shared_field';
            }
        }

        return [
            'version' => 2,
            'kind' => $kind,
            'target' => $target,
            'contract' => $contract,
            'renderContext' => sanitize_key((string) $render_context),
            'nativeLoopKind' => $native_loop_kind,
            'parentNativeLoopKind' => $parent_native_loop_kind,
            'nativeLoopAncestry' => $native_loop_ancestry,
            'loopOwned' => ! empty($loop_context['active']) && $scope === 'related_entity',
            'requiresJournal' => $target !== 'field' || $kind !== 'scalar' || $scope !== 'current_entity',
            'status' => sanitize_key((string) $status),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $ancestry
     * @return array<int, array<string, string>>
     */
    private function normalizeNativeQueryAncestry(array $ancestry)
    {
        return array_values(
            array_filter(
                array_map(
                    static function ($item) {
                        if (! is_array($item) || empty($item['active'])) {
                            return null;
                        }

                        $kind = isset($item['kind']) ? sanitize_key((string) $item['kind']) : '';
                        $selector = isset($item['selector']) ? sanitize_key((string) $item['selector']) : '';
                        $object_type = isset($item['objectType']) ? sanitize_key((string) $item['objectType']) : '';
                        $field_name = isset($item['fieldName']) ? sanitize_key((string) $item['fieldName']) : '';
                        $field_key = isset($item['fieldKey']) ? sanitize_key((string) $item['fieldKey']) : '';
                        $field_type = isset($item['fieldType']) ? sanitize_key((string) $item['fieldType']) : '';
                        $loop_index = isset($item['loopIndex']) ? sanitize_text_field((string) $item['loopIndex']) : '';

                        if ($kind === '' && $selector === '' && $object_type === '' && $field_name === '' && $field_key === '' && $field_type === '') {
                            return null;
                        }

                        return [
                            'kind' => $kind,
                            'selector' => $selector,
                            'objectType' => $object_type,
                            'fieldName' => $field_name,
                            'fieldKey' => $field_key,
                            'fieldType' => $field_type,
                            'loopIndex' => $loop_index,
                        ];
                    },
                    $ancestry
                )
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $ancestry
     * @return array<int, array<string, string>>
     */
    private function normalizeNativeQueryAncestryForHash(array $ancestry)
    {
        return array_values(
            array_map(
                static function ($item) {
                    return [
                        'kind' => isset($item['kind']) ? sanitize_key((string) $item['kind']) : '',
                        'selector' => isset($item['selector']) ? sanitize_key((string) $item['selector']) : '',
                        'objectType' => isset($item['objectType']) ? sanitize_key((string) $item['objectType']) : '',
                        'loopIndex' => isset($item['loopIndex']) ? sanitize_text_field((string) $item['loopIndex']) : '',
                    ];
                },
                $this->normalizeNativeQueryAncestry($ancestry)
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $ancestry
     * @return array<int, string>
     */
    private function buildNativeQueryAncestryLabels(array $ancestry)
    {
        $labels = [];

        foreach ($this->normalizeNativeQueryAncestry($ancestry) as $item) {
            $kind = isset($item['kind']) ? sanitize_key((string) $item['kind']) : '';
            $selector = isset($item['selector']) ? sanitize_key((string) $item['selector']) : '';
            $loop_index = isset($item['loopIndex']) ? sanitize_text_field((string) $item['loopIndex']) : '';

            $label = $kind !== '' ? $kind : 'query';
            if ($selector !== '') {
                $label .= ':' . $selector;
            }
            if ($loop_index !== '' && is_numeric($loop_index)) {
                $label .= '@' . (absint($loop_index) + 1);
            }

            $labels[] = $label;
        }

        return array_values(array_filter($labels));
    }
}
