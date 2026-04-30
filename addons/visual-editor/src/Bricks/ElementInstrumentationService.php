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
     * @var array<string, EditableDescriptor>
     */
    private $descriptors = [];

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

        $inspection = $this->inspector->inspectForAttribute($element, $key);
        if (empty($inspection['supported'])) {
            return $attributes;
        }

        if (! $this->shouldInstrumentAttributeGroup($attributes, $key, $inspection)) {
            return $attributes;
        }

        $page_context = $this->page_context->resolve();
        $entity_id = isset($page_context['entityId']) ? absint($page_context['entityId']) : 0;
        if ($entity_id <= 0) {
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
        $rendered_post_id = $this->page_context->resolveRenderedPostId();
        if ($rendered_post_id > 0
            && ($entity['type'] ?? '') === 'post'
            && absint($entity['id'] ?? 0) !== $rendered_post_id
            && ! $this->allowsLoopOwnedPostEntity($entity, $loop_context)) {
            return $attributes;
        }

        $seed = $this->buildSeed($element, $inspection, $loop_context);
        $token = isset($this->instrumented[$seed]) ? $this->instrumented[$seed] : $this->registry->createToken($seed);
        $this->instrumented[$seed] = $token;
        $source = isset($classification['source']) && is_array($classification['source']) ? $classification['source'] : [];
        $render_context = isset($inspection['render_context']) ? sanitize_key((string) $inspection['render_context']) : 'text';
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
        if (! is_string($element_html) || $element_html === '' || ! is_object($element)) {
            return is_string($element_html) ? $element_html : '';
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
            $content = $this->injectMarkerIntoElementOccurrence($content, $descriptor, $element_id, $index);
            $occurrences[$element_id] = $index + 1;
        }

        return $content;
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

        return $entity_id > 0
            && ($entity_id === $loop_object_id || $entity_id === $parent_loop_object_id);
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

            if ($this->valuesMatch($rendered_text, $text)) {
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

        $rendered_value = $this->extractComparableRenderValue($rendered_fragment, $descriptor);
        $display_payload = $this->resolveDisplayPayload($resolver, $descriptor, $raw_value, $rendered_value);
        $resolved_value = isset($display_payload['text']) ? (string) $display_payload['text'] : '';
        $display_key = isset($display_payload['key']) ? (string) $display_payload['key'] : 'default';
        $display_mode = isset($display_payload['mode']) ? (string) $display_payload['mode'] : $resolver->getDisplayMode($descriptor);

        $descriptor->render['rendered_value'] = $rendered_value;
        $descriptor->render['resolved_value'] = $resolved_value;
        $descriptor->render['rendered_text'] = $rendered_value;
        $descriptor->render['resolved_text'] = $resolved_value;
        $descriptor->render['display_key'] = $display_key;
        $descriptor->render['display_mode'] = $display_mode;
        $descriptor->render['render_verified'] = true;
        $descriptor->render['value_match'] = $this->valuesMatch($rendered_value, $resolved_value);

        if ($descriptor->status === 'editable' && ! $descriptor->render['value_match']) {
            $descriptor->status = 'unsupported';
            $descriptor->ui['warning'] = __(self::MISMATCH_WARNING, 'dbvc');
            $this->dropDescriptorByToken($descriptor->token);

            return $this->stripMarkerAttributesForToken($element_html, $descriptor->token);
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
                    'container_type' => isset($source['container_type']) ? (string) $source['container_type'] : '',
                    'parent_field_name' => isset($source['parent_field_name']) ? (string) $source['parent_field_name'] : '',
                    'parent_field_key' => isset($source['parent_field_key']) ? (string) $source['parent_field_key'] : '',
                    'row_index' => isset($source['row_index']) ? (string) $source['row_index'] : '',
                    'layout_key' => isset($source['layout_key']) ? (string) $source['layout_key'] : '',
                    'layout_name' => isset($source['layout_name']) ? (string) $source['layout_name'] : '',
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
                    'container_type' => isset($source['container_type']) ? (string) $source['container_type'] : '',
                    'parent_field_name' => isset($source['parent_field_name']) ? (string) $source['parent_field_name'] : '',
                    'parent_field_key' => isset($source['parent_field_key']) ? (string) $source['parent_field_key'] : '',
                    'row_index' => isset($source['row_index']) ? (string) $source['row_index'] : '',
                    'layout_key' => isset($source['layout_key']) ? (string) $source['layout_key'] : '',
                    'layout_name' => isset($source['layout_name']) ? (string) $source['layout_name'] : '',
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
     * @return string
     */
    private function appendMarkerAttributesToTag($tag, EditableDescriptor $descriptor)
    {
        $attributes = [
            'data-dbvc-ve' => $descriptor->token,
            'data-dbvc-ve-status' => $descriptor->status,
            'data-dbvc-ve-scope' => $this->mapScopeToMarkerValue($descriptor->scope),
            'data-dbvc-ve-source-group' => isset($descriptor->render['source_group']) ? (string) $descriptor->render['source_group'] : '',
            'data-dbvc-ve-group' => isset($descriptor->render['sync_group']) ? (string) $descriptor->render['sync_group'] : '',
            'data-dbvc-ve-context' => isset($descriptor->render['context']) ? (string) $descriptor->render['context'] : 'text',
        ];

        if (! empty($descriptor->render['attribute'])) {
            $attributes['data-dbvc-ve-attribute'] = (string) $descriptor->render['attribute'];
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
        }

        if ($row_index !== null) {
            $summary[] = 'row:' . ($row_index + 1);
        }

        if ($layout_name !== '' || $layout_key !== '') {
            $summary[] = 'layout:' . ($layout_name !== '' ? $layout_name : $layout_key);
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
        $kind = 'scalar';

        if (in_array($field_type, ['link', 'image', 'post_object', 'relationship', 'taxonomy'], true)) {
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

        if ($target === 'row') {
            $contract = 'repeater_row';
        } elseif ($target === 'layout') {
            $contract = 'flexible_layout';
        }

        if (! empty($loop_context['active']) && $scope === 'related_entity') {
            if ($target === 'row') {
                $contract = 'loop_owned_repeater_row';
            } elseif ($target === 'layout') {
                $contract = 'loop_owned_flexible_layout';
            } else {
                $contract = 'loop_owned_field';
            }
        } elseif ($scope === 'shared_entity') {
            if ($target === 'row') {
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
            'loopOwned' => ! empty($loop_context['active']) && $scope === 'related_entity',
            'requiresJournal' => $target !== 'field' || $kind !== 'scalar' || $scope !== 'current_entity',
            'status' => sanitize_key((string) $status),
        ];
    }
}
