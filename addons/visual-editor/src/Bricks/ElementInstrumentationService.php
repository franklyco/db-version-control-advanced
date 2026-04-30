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
        $rendered_post_id = $this->page_context->resolveRenderedPostId();
        if ($rendered_post_id > 0 && ($entity['type'] ?? '') === 'post' && absint($entity['id'] ?? 0) !== $rendered_post_id) {
            return $attributes;
        }

        $loop_context = isset($classification['loop']) && is_array($classification['loop']) ? $classification['loop'] : [];
        $seed = $this->buildSeed($element, $inspection, $loop_context);
        $token = isset($this->instrumented[$seed]) ? $this->instrumented[$seed] : $this->registry->createToken($seed);
        $this->instrumented[$seed] = $token;
        $source = isset($classification['source']) && is_array($classification['source']) ? $classification['source'] : [];
        $render_context = isset($inspection['render_context']) ? sanitize_key((string) $inspection['render_context']) : 'text';
        $render_attribute = isset($inspection['render_attribute']) ? sanitize_key((string) $inspection['render_attribute']) : '';
        $source_group = $this->buildSourceGroup($entity, $source);
        $sync_group = $this->buildSyncGroup($entity, $source, $render_context);

        $descriptor = new EditableDescriptor(
            $token,
            $status,
            isset($classification['scope']) ? (string) $classification['scope'] : 'current_entity',
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
            isset($classification['resolver']) && is_array($classification['resolver']) ? $classification['resolver'] : []
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
            $element_html = $this->verifyDescriptor($element_html, $descriptor);
        }

        return $element_html;
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
}
