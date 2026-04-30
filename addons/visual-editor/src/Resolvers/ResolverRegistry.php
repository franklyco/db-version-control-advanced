<?php

namespace Dbvc\VisualEditor\Resolvers;

use Dbvc\VisualEditor\Bricks\AcfFieldContextResolver;
use Dbvc\VisualEditor\Bricks\LoopContextResolver;
use Dbvc\VisualEditor\Registry\EditableDescriptor;

final class ResolverRegistry
{
    /**
     * @var array<string, ResolverInterface>
     */
    private $resolvers = [];

    /**
     * @var AcfFieldContextResolver
     */
    private $acf_context;

    /**
     * @var LoopContextResolver
     */
    private $loops;

    public function __construct(?AcfFieldContextResolver $acf_context = null, ?LoopContextResolver $loops = null)
    {
        $this->loops = $loops instanceof LoopContextResolver ? $loops : new LoopContextResolver();
        $this->acf_context = $acf_context instanceof AcfFieldContextResolver ? $acf_context : new AcfFieldContextResolver($this->loops);

        $instances = [
            new PostTitleResolver(),
            new PostExcerptResolver(),
            new PostFeaturedImageResolver(),
            new AcfWysiwygResolver(),
            new AcfChoiceResolver(),
            new AcfLinkResolver(),
            new AcfImageResolver(),
            new AcfGalleryResolver(),
            new AcfReferenceLinkResolver(),
            new AcfTextResolver(),
            new AcfReadonlyResolver(),
            new UnsupportedResolver(),
        ];

        foreach ($instances as $resolver) {
            $this->resolvers[$resolver->name()] = $resolver;
        }
    }

    /**
     * @param EditableDescriptor $descriptor
     * @return ResolverInterface
     */
    public function resolve(EditableDescriptor $descriptor)
    {
        $resolver_name = isset($descriptor->resolver['name']) ? (string) $descriptor->resolver['name'] : '';
        if ($resolver_name !== '' && isset($this->resolvers[$resolver_name])) {
            return $this->resolvers[$resolver_name];
        }

        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($descriptor)) {
                return $resolver;
            }
        }

        return $this->resolvers['unsupported'];
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $page_context
     * @return array<string, mixed>
     */
    public function classifyCandidate(array $candidate, array $page_context)
    {
        $entity_id = isset($page_context['entityId']) ? absint($page_context['entityId']) : 0;
        if ($entity_id <= 0) {
            return $this->buildUnsupported('Missing current entity context.');
        }

        $source_type = isset($candidate['source_type']) ? (string) $candidate['source_type'] : '';
        if ($source_type === 'post_field') {
            return $this->classifyPostField($candidate, $page_context);
        }

        if ($source_type === 'acf_field') {
            return $this->classifyAcfField($candidate, $page_context);
        }

        return $this->buildUnsupported('Unsupported dynamic source.');
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function classifyPostField(array $candidate, array $page_context)
    {
        $field_name = isset($candidate['field_name']) ? sanitize_key((string) $candidate['field_name']) : '';
        $render_context = isset($candidate['render_context']) ? sanitize_key((string) $candidate['render_context']) : '';
        $page_entity_id = isset($page_context['entityId']) ? absint($page_context['entityId']) : 0;
        $loop_context = $this->loops->resolve();
        $has_concrete_post_owner = $this->loops->hasConcretePostOwner($loop_context);
        $supports_related_editing = $this->loops->supportsRelatedPostEditing($loop_context);

        if (! empty($loop_context['active']) && ! $has_concrete_post_owner) {
            return $this->buildUnsupported('Only Bricks query loops with a concrete post owner are surfaced in the current Visual Editor slice.');
        }

        $entity = $this->resolvePostFieldEntity($page_context, $loop_context);
        $scope = $this->resolvePostFieldScope($entity, $page_entity_id, $loop_context);
        $status = ! empty($loop_context['active']) && ! $supports_related_editing ? 'readonly' : 'editable';
        $warning = $this->buildPostFieldWarning($scope, $loop_context, $status);

        if ($field_name === 'post_title') {
            return [
                'status' => $status,
                'scope' => $scope,
                'entity' => $entity,
                'loop' => $this->loops->export($loop_context),
                'source' => [
                    'type' => 'post_field',
                    'expression' => isset($candidate['expression']) ? (string) $candidate['expression'] : '',
                    'expression_args' => isset($candidate['args']) && is_array($candidate['args']) ? $candidate['args'] : [],
                    'field_name' => 'post_title',
                    'field_key' => '',
                    'field_type' => 'text',
                ],
                'resolver' => [
                    'name' => 'post_title',
                    'version' => 1,
                ],
                'ui' => [
                    'label' => __('Post Title', 'dbvc'),
                    'input' => $status === 'readonly' ? 'readonly_preview' : 'text',
                    'warning' => $warning,
                ],
            ];
        }

        if ($field_name === 'post_excerpt') {
            return [
                'status' => $status,
                'scope' => $scope,
                'entity' => $entity,
                'loop' => $this->loops->export($loop_context),
                'source' => [
                    'type' => 'post_field',
                    'expression' => isset($candidate['expression']) ? (string) $candidate['expression'] : '',
                    'expression_args' => isset($candidate['args']) && is_array($candidate['args']) ? $candidate['args'] : [],
                    'field_name' => 'post_excerpt',
                    'field_key' => '',
                    'field_type' => 'textarea',
                ],
                'resolver' => [
                    'name' => 'post_excerpt',
                    'version' => 1,
                ],
                'ui' => [
                    'label' => __('Post Excerpt', 'dbvc'),
                    'input' => $status === 'readonly' ? 'readonly_preview' : 'textarea',
                    'warning' => $warning,
                ],
            ];
        }

        if ($field_name === 'featured_image') {
            if (! in_array($render_context, ['image_src', 'background_image'], true)) {
                return $this->buildUnsupported('Only direct Bricks image and background-image projections are enabled for featured image editing in the current slice.');
            }

            return [
                'status' => $status,
                'scope' => $scope,
                'entity' => $entity,
                'loop' => $this->loops->export($loop_context),
                'source' => [
                    'type' => 'post_field',
                    'expression' => isset($candidate['expression']) ? (string) $candidate['expression'] : '',
                    'expression_args' => isset($candidate['args']) && is_array($candidate['args']) ? $candidate['args'] : [],
                    'field_name' => 'featured_image',
                    'field_key' => '',
                    'field_type' => 'image',
                    'media_size' => isset($candidate['media_size']) ? sanitize_key((string) $candidate['media_size']) : '',
                ],
                'resolver' => [
                    'name' => 'post_featured_image',
                    'version' => 1,
                ],
                'ui' => [
                    'label' => __('Featured Image', 'dbvc'),
                    'input' => 'media_reference',
                    'warning' => $this->buildPostFieldWarning($scope, $loop_context, $status, 'featured_image', $render_context),
                ],
            ];
        }

        return $this->buildUnsupported('Only direct post title, post excerpt, and featured image bindings are enabled in the current post-field slice.');
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $page_context
     * @return array<string, mixed>
     */
    private function classifyAcfField(array $candidate, array $page_context)
    {
        if (! function_exists('get_field_object')) {
            return $this->buildUnsupported('ACF runtime is unavailable.');
        }

        $resolved = $this->acf_context->resolve($candidate, $page_context);
        if (empty($resolved['ok'])) {
            return $this->buildUnsupported(isset($resolved['message']) ? (string) $resolved['message'] : 'ACF field context could not be resolved.');
        }

        $field = isset($resolved['field']) && is_array($resolved['field']) ? $resolved['field'] : [];
        if (empty($field)) {
            return $this->buildUnsupported('ACF field definition is missing.');
        }

        $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        $render_context = isset($candidate['render_context']) ? sanitize_key((string) $candidate['render_context']) : '';
        if ($render_context === 'link_href' && ! $this->supportsAcfLinkHrefProjection($field, $resolved)) {
            return $this->buildUnsupported('Only direct URL-capable ACF projections are enabled for Bricks link attributes in the MVP.');
        }
        if (in_array($render_context, ['image_src', 'background_image'], true) && $field_type !== 'image') {
            return $this->buildUnsupported('Only direct ACF image fields are enabled for Bricks image source editing in the current slice.');
        }
        if ($render_context === 'gallery_collection' && $field_type !== 'gallery') {
            return $this->buildUnsupported('Only direct ACF gallery fields are enabled for Bricks gallery inspection in the current slice.');
        }

        $field_name = isset($field['name']) ? sanitize_key((string) $field['name']) : '';
        $field_key = isset($field['key']) ? sanitize_key((string) $field['key']) : '';
        $return_format = isset($field['return_format']) ? sanitize_key((string) $field['return_format']) : 'value';
        $repeater = isset($resolved['repeater']) && is_array($resolved['repeater']) ? $resolved['repeater'] : [];
        $flexible = isset($resolved['flexible']) && is_array($resolved['flexible']) ? $resolved['flexible'] : [];
        $source_type = 'acf_field';

        if (! empty($repeater['supported'])) {
            $source_type = 'acf_repeater_subfield';
        } elseif (! empty($flexible['supported'])) {
            $source_type = 'acf_flexible_subfield';
        }

        $container_type = ! empty($repeater['supported'])
            ? 'repeater'
            : (! empty($flexible['supported']) ? 'flexible_content' : '');
        $parent_field_name = ! empty($repeater['supported'])
            ? sanitize_key((string) ($repeater['parent_field_name'] ?? ''))
            : (! empty($flexible['supported']) ? sanitize_key((string) ($flexible['parent_field_name'] ?? '')) : '');
        $parent_field_key = ! empty($repeater['supported'])
            ? sanitize_key((string) ($repeater['parent_field_key'] ?? ''))
            : (! empty($flexible['supported']) ? sanitize_key((string) ($flexible['parent_field_key'] ?? '')) : '');
        $row_index = ! empty($repeater['supported'])
            ? (isset($repeater['row_index']) ? absint($repeater['row_index']) : null)
            : (! empty($flexible['supported']) && isset($flexible['row_index']) ? absint($flexible['row_index']) : null);
        $layout_key = ! empty($flexible['supported']) ? sanitize_key((string) ($flexible['layout_key'] ?? '')) : '';
        $layout_name = ! empty($flexible['supported']) ? sanitize_key((string) ($flexible['layout_name'] ?? '')) : '';
        $label = isset($field['label']) && (string) $field['label'] !== ''
            ? sanitize_text_field((string) $field['label'])
            : ucwords(str_replace('_', ' ', $field_name));
        $allow_multiple = $field_type === 'checkbox'
            || ($field_type === 'select' && ! empty($field['multiple']));
        $readonly_reason = $this->resolveAcfReadonlyReason($field, $resolved, $render_context);
        $status = $readonly_reason !== '' ? 'readonly' : 'editable';
        $resolver_name = $status === 'readonly'
            ? $this->resolveReadonlyAcfResolverName($field_type, $render_context)
            : $this->resolveAcfResolverName($field_type, $render_context);
        $input = $status === 'readonly'
            ? $this->resolveReadonlyAcfInputType($field_type, $allow_multiple, $render_context)
            : $this->resolveAcfInputType($field_type, $allow_multiple, $render_context);
        $warning = $status === 'readonly'
            ? $this->buildReadonlyAcfWarning($resolved, $field, $render_context, $readonly_reason)
            : $this->buildAcfWarning($resolved, $field_type, $render_context);

        return [
            'status' => $status,
            'scope' => isset($resolved['scope']) ? (string) $resolved['scope'] : 'current_entity',
            'entity' => isset($resolved['entity']) && is_array($resolved['entity']) ? $resolved['entity'] : [],
            'loop' => isset($resolved['loop']) && is_array($resolved['loop']) ? $resolved['loop'] : [],
            'source' => [
                'type' => $source_type,
                'expression' => isset($candidate['expression']) ? (string) $candidate['expression'] : '',
                'expression_args' => isset($resolved['tag_args']) && is_array($resolved['tag_args']) ? $resolved['tag_args'] : [],
                'field_name' => $field_name,
                'field_key' => $field_key,
                'field_type' => $field_type,
                'return_format' => $return_format,
                'media_size' => isset($candidate['media_size']) ? sanitize_key((string) $candidate['media_size']) : '',
                'reference_post_types' => $this->normalizeStringList($field['post_type'] ?? []),
                'reference_taxonomies' => $this->normalizeStringList($field['taxonomy'] ?? []),
                'reference_multiple' => $this->isReferenceMultiple($field),
                'container_type' => $container_type,
                'parent_field_name' => $parent_field_name,
                'parent_field_key' => $parent_field_key,
                'row_index' => $row_index,
                'layout_key' => $layout_key,
                'layout_name' => $layout_name,
            ],
            'resolver' => [
                'name' => $resolver_name,
                'version' => 1,
            ],
            'ui' => [
                'label' => $label,
                'input' => $input,
                'warning' => $warning,
                'allowMultiple' => $allow_multiple,
                'options' => $this->normalizeChoiceOptions($field),
            ],
        ];
    }

    /**
     * @param string $warning
     * @return array<string, mixed>
     */
    private function buildUnsupported($warning)
    {
        return [
            'status' => 'unsupported',
            'scope' => 'current_entity',
            'source' => [],
            'resolver' => [
                'name' => 'unsupported',
                'version' => 1,
            ],
            'ui' => [
                'label' => __('Unsupported field', 'dbvc'),
                'input' => 'text',
                'warning' => (string) $warning,
            ],
        ];
    }

    /**
     * @param string $field_type
     * @param string $render_context
     * @return string
     */
    private function resolveAcfResolverName($field_type, $render_context = '')
    {
        if ($render_context === 'link_href' && in_array($field_type, ['post_object', 'relationship', 'taxonomy'], true)) {
            return 'acf_reference_link';
        }

        if ($field_type === 'wysiwyg') {
            return 'acf_wysiwyg';
        }

        if (in_array($field_type, ['checkbox', 'select', 'radio', 'button_group'], true)) {
            return 'acf_choice';
        }

        if ($field_type === 'link') {
            return 'acf_link';
        }

        if ($field_type === 'image') {
            return 'acf_image';
        }

        if ($field_type === 'gallery') {
            return 'acf_gallery';
        }

        return 'acf_text';
    }

    /**
     * @param string $field_type
     * @param string $render_context
     * @return string
     */
    private function resolveReadonlyAcfResolverName($field_type, $render_context = '')
    {
        if ($render_context === 'link_href' && in_array($field_type, ['post_object', 'relationship', 'taxonomy'], true)) {
            return 'acf_reference_link';
        }

        if ($field_type === 'wysiwyg') {
            return 'acf_wysiwyg';
        }

        if (in_array($field_type, ['checkbox', 'select', 'radio', 'button_group'], true)) {
            return 'acf_choice';
        }

        if ($field_type === 'link') {
            return 'acf_link';
        }

        if ($field_type === 'image') {
            return 'acf_image';
        }

        if ($field_type === 'gallery') {
            return 'acf_gallery';
        }

        if (in_array($field_type, ['text', 'textarea', 'url', 'email', 'number', 'range'], true)) {
            return 'acf_text';
        }

        return 'acf_readonly';
    }

    /**
     * @param string $field_type
     * @param bool   $allow_multiple
     * @param string $render_context
     * @return string
     */
    private function resolveReadonlyAcfInputType($field_type, $allow_multiple, $render_context = '')
    {
        if ($field_type === 'gallery') {
            return 'media_gallery_preview';
        }

        if ($field_type === 'image') {
            return 'media_reference';
        }

        if (in_array($field_type, ['wysiwyg', 'checkbox', 'select', 'radio', 'button_group', 'link'], true)) {
            return $this->resolveAcfInputType($field_type, $allow_multiple, $render_context);
        }

        return 'readonly_preview';
    }

    /**
     * @param string $field_type
     * @param bool   $allow_multiple
     * @param string $render_context
     * @return string
     */
    private function resolveAcfInputType($field_type, $allow_multiple, $render_context = '')
    {
        if ($render_context === 'link_href' && in_array($field_type, ['post_object', 'relationship', 'taxonomy'], true)) {
            return 'url';
        }

        if ($field_type === 'wysiwyg') {
            return 'richtext';
        }

        if ($field_type === 'textarea') {
            return 'textarea';
        }

        if ($field_type === 'url') {
            return 'url';
        }

        if ($field_type === 'email') {
            return 'email';
        }

        if (in_array($field_type, ['number', 'range'], true)) {
            return 'number';
        }

        if ($field_type === 'link') {
            return 'link';
        }

        if ($field_type === 'image') {
            return 'media_reference';
        }

        if ($field_type === 'gallery') {
            return 'media_gallery_preview';
        }

        if ($allow_multiple) {
            return 'checkbox_group';
        }

        if (in_array($field_type, ['select', 'radio', 'button_group'], true)) {
            return 'select';
        }

        return 'text';
    }

    /**
     * @param array<string, mixed> $field
     * @return array<int, array<string, string>>
     */
    private function normalizeChoiceOptions(array $field)
    {
        if (empty($field['choices']) || ! is_array($field['choices'])) {
            return [];
        }

        $options = [];

        foreach ($field['choices'] as $value => $label) {
            $options[] = [
                'value' => sanitize_text_field((string) $value),
                'label' => sanitize_text_field(is_scalar($label) ? (string) $label : wp_json_encode($label)),
            ];
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $resolved
     * @param string               $field_type
     * @param string               $render_context
     * @return string|null
     */
    private function buildAcfWarning(array $resolved, $field_type, $render_context = '')
    {
        $warnings = [];
        $entity = isset($resolved['entity']) && is_array($resolved['entity']) ? $resolved['entity'] : [];
        $loop = isset($resolved['loop']) && is_array($resolved['loop']) ? $resolved['loop'] : [];
        $entity_type = isset($entity['type']) ? (string) $entity['type'] : '';

        if (($resolved['scope'] ?? '') === 'related_entity') {
            if ($entity_type === 'term') {
                $warnings[] = __('This field is rendered from the related taxonomy term currently shown in a Bricks query loop. Saving here updates that related term, not the current page.', 'dbvc');
            } elseif ($entity_type === 'user') {
                $warnings[] = __('This field is rendered from the related user currently shown in a Bricks query loop. Saving here updates that related user, not the current page.', 'dbvc');
            } elseif (! empty($loop['supports_related_post_editing'])) {
                $warnings[] = __('This field is rendered from a related post inside a Bricks query loop. Saving here updates that related post, not the current page.', 'dbvc');
            } else {
                $warnings[] = __('This field is rendered from a non-current item inside a Bricks query loop rather than the current page.', 'dbvc');
            }
        } elseif ($entity_type === 'option') {
            $warnings[] = __('This field resolves to a shared options-level ACF target. Saving here affects every frontend context using that option value.', 'dbvc');
        } elseif ($entity_type === 'term') {
            $warnings[] = __('This field resolves to a shared taxonomy term target rather than the current post. Saving here affects any view using that term field.', 'dbvc');
        } elseif ($entity_type === 'user') {
            $warnings[] = __('This field resolves to a shared user profile target rather than the current post. Saving here affects any view using that user field.', 'dbvc');
        }

        $repeater = isset($resolved['repeater']) && is_array($resolved['repeater']) ? $resolved['repeater'] : [];
        $flexible = isset($resolved['flexible']) && is_array($resolved['flexible']) ? $resolved['flexible'] : [];
        if (! empty($repeater['supported'])) {
            $row_index = isset($repeater['row_index']) ? absint($repeater['row_index']) : 0;
            $parent_field_name = isset($repeater['parent_field_name']) ? sanitize_key((string) $repeater['parent_field_name']) : '';

            if ($parent_field_name !== '') {
                $warnings[] = sprintf(
                    /* translators: 1: repeater field name, 2: row number */
                    __('This field is being edited through repeater `%1$s`, row %2$d.', 'dbvc'),
                    $parent_field_name,
                    $row_index + 1
                );
            }
        }

        if (! empty($flexible['supported'])) {
            $row_index = isset($flexible['row_index']) ? absint($flexible['row_index']) : 0;
            $parent_field_name = isset($flexible['parent_field_name']) ? sanitize_key((string) $flexible['parent_field_name']) : '';
            $layout_name = isset($flexible['layout_name']) ? sanitize_key((string) $flexible['layout_name']) : '';

            if ($parent_field_name !== '') {
                $warnings[] = sprintf(
                    /* translators: 1: flexible field name, 2: row number, 3: layout slug */
                    __('This field is rendered through flexible content `%1$s`, row %2$d, layout `%3$s`.', 'dbvc'),
                    $parent_field_name,
                    $row_index + 1,
                    $layout_name !== '' ? $layout_name : 'unknown'
                );
            }
        }

        if ($field_type === 'link') {
            $warnings[] = __('This link field is only enabled when Bricks renders either a direct single-tag text value or a direct single-tag top-level link URL. The modal edits the structured link value, but only the currently matched visible projection updates in place.', 'dbvc');
        }

        if ($field_type === 'image' && in_array($render_context, ['image_src', 'background_image'], true)) {
            $warnings[] = $render_context === 'background_image'
                ? __('This background image field saves the underlying Media Library attachment ID. The modal can submit a selected attachment directly or resolve a pasted local media URL as a fallback, and the overlay refreshes the rendered background image in place after save.', 'dbvc')
                : __('This image field saves the underlying Media Library attachment ID. The modal can submit a selected attachment directly or resolve a pasted local media URL as a fallback, and the overlay refreshes the rendered image attributes in place after save.', 'dbvc');
        }

        if ($render_context === 'link_href' && in_array($field_type, ['post_object', 'relationship', 'taxonomy'], true)) {
            $warnings[] = __('This related-object field is being edited through its resolved permalink. The saved value remains the underlying related post or term ID, not the raw URL string.', 'dbvc');
        }

        return empty($warnings) ? null : implode(' ', $warnings);
    }

    /**
     * @param array<string, mixed> $resolved
     * @param array<string, mixed> $field
     * @param string               $render_context
     * @param string               $reason
     * @return string|null
     */
    private function buildReadonlyAcfWarning(array $resolved, array $field, $render_context, $reason)
    {
        $warnings = [];
        $base_warning = $this->buildAcfWarning(
            $resolved,
            isset($field['type']) ? sanitize_key((string) $field['type']) : '',
            $render_context
        );

        if (is_string($base_warning) && $base_warning !== '') {
            $warnings[] = $base_warning;
        }

        if ($reason === 'restricted_options_group') {
            $warnings[] = __('This Site Settings global-link field group is intentionally locked in Visual Editor. Edit it from the ACF Site Settings options page instead.', 'dbvc');
        } elseif ($reason === 'repeater_shared_owner') {
            $warnings[] = __('This repeater row resolves to a non-post owner. Non-post repeater mutation is still inspect-only until it has a dedicated rollback-safe contract.', 'dbvc');
        } elseif ($reason === 'loop_owned_readonly') {
            $entity = isset($resolved['entity']) && is_array($resolved['entity']) ? $resolved['entity'] : [];
            $entity_type = isset($entity['type']) ? (string) $entity['type'] : '';

            if ($entity_type === 'term') {
                $warnings[] = __('This field belongs to a non-current taxonomy term rendered by a Bricks query loop. It is surfaced here for inspection only until that loop-owned term mutation path has a dedicated save contract.', 'dbvc');
            } elseif ($entity_type === 'user') {
                $warnings[] = __('This field belongs to a non-current user rendered by a Bricks query loop. It is surfaced here for inspection only until that loop-owned user mutation path has a dedicated save contract.', 'dbvc');
            } else {
                $warnings[] = __('This field belongs to a non-current post rendered by a Bricks query loop. It is surfaced here for inspection only until that loop-owned mutation path has a dedicated save contract.', 'dbvc');
            }
        } elseif ($reason === 'flexible_non_post_owner') {
            $warnings[] = __('This flexible-content subfield belongs to a non-post owner. Flexible mutation is currently limited to current and related post owners only.', 'dbvc');
        } elseif ($reason === 'flexible_shared_post_owner') {
            $warnings[] = __('This flexible-content subfield belongs to a shared non-current post outside the current loop-owned slice. It is surfaced here for inspection only until that post-owner contract is enabled.', 'dbvc');
        } elseif ($reason === 'flexible_pending') {
            $warnings[] = __('This flexible-content subfield is surfaced here for inspection only. Flexible mutation is currently limited to text-like, WYSIWYG, choice, link, and image subfields with stable post-owned row identity.', 'dbvc');
        } elseif ($reason === 'gallery_collection') {
            $warnings[] = __('This gallery field is surfaced here for inspection only. Multi-image collection editing needs a dedicated mutation and rollback contract before it can be saved safely.', 'dbvc');
        } elseif ($reason === 'image_projection') {
            $warnings[] = __('This image field is only editable when the Bricks element is rendering its direct image source. Other projections remain inspect-only for now.', 'dbvc');
        } elseif ($reason === 'reference_projection') {
            $warnings[] = __('This related-object ACF field is surfaced here for inspection only. Relationship and referenced-object collection editing is not enabled yet.', 'dbvc');
        } else {
            $warnings[] = __('This ACF field is surfaced here for inspection only. Nested, object-style, or advanced field editing for it is not enabled yet.', 'dbvc');
        }

        return implode(' ', array_values(array_filter($warnings)));
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $resolved
     * @param string               $render_context
     * @return string
     */
    private function resolveAcfReadonlyReason(array $field, array $resolved, $render_context = '')
    {
        if ($this->isRestrictedOptionsFieldGroup($field, $resolved)) {
            return 'restricted_options_group';
        }

        $repeater = isset($resolved['repeater']) && is_array($resolved['repeater']) ? $resolved['repeater'] : [];
        $flexible = isset($resolved['flexible']) && is_array($resolved['flexible']) ? $resolved['flexible'] : [];
        if (! empty($repeater['supported'])) {
            $entity = isset($resolved['entity']) && is_array($resolved['entity']) ? $resolved['entity'] : [];
            $entity_type = isset($entity['type']) ? (string) $entity['type'] : '';

            if ($entity_type !== 'post') {
                return 'repeater_shared_owner';
            }
        }

        if (! empty($flexible['supported'])) {
            $entity = isset($resolved['entity']) && is_array($resolved['entity']) ? $resolved['entity'] : [];
            $entity_type = isset($entity['type']) ? (string) $entity['type'] : '';
            $scope = isset($resolved['scope']) ? (string) $resolved['scope'] : 'current_entity';
            $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';

            if ($entity_type !== 'post') {
                return 'flexible_non_post_owner';
            }

            if ($scope === 'shared_entity') {
                return 'flexible_shared_post_owner';
            }

            if (! $this->isEditableFlexibleFieldType($field_type)) {
                return 'flexible_pending';
            }
        }

        $loop = isset($resolved['loop']) && is_array($resolved['loop']) ? $resolved['loop'] : [];
        if (! empty($loop['active']) && ! empty($loop['has_concrete_owner']) && empty($loop['supports_loop_owned_editing'])) {
            return 'loop_owned_readonly';
        }

        $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        if ($field_type === 'gallery') {
            return 'gallery_collection';
        }

        if ($field_type === 'image' && ! in_array($render_context, ['image_src', 'background_image'], true)) {
            return 'image_projection';
        }

        if (in_array($field_type, ['post_object', 'relationship', 'taxonomy'], true) && $render_context !== 'link_href') {
            return 'reference_projection';
        }

        if (! $this->isEditableAcfFieldType($field_type)) {
            return 'advanced_field_type';
        }

        return '';
    }

    /**
     * @param string $field_type
     * @return bool
     */
    private function isEditableAcfFieldType($field_type)
    {
        return in_array(
            $field_type,
            ['text', 'textarea', 'url', 'email', 'number', 'range', 'wysiwyg', 'checkbox', 'select', 'radio', 'button_group', 'link', 'image', 'gallery', 'post_object', 'relationship', 'taxonomy'],
            true
        );
    }

    /**
     * @param string $field_type
     * @return bool
     */
    private function isEditableFlexibleFieldType($field_type)
    {
        return in_array(
            $field_type,
            ['text', 'textarea', 'url', 'email', 'number', 'range', 'wysiwyg', 'checkbox', 'select', 'radio', 'button_group', 'link', 'image'],
            true
        );
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $resolved
     * @return bool
     */
    private function supportsAcfLinkHrefProjection(array $field, array $resolved)
    {
        $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        $args = isset($resolved['tag_args']) && is_array($resolved['tag_args']) ? $resolved['tag_args'] : [];
        $normalized_args = array_values(
            array_filter(
                array_map(
                    static function ($arg) {
                        return sanitize_key((string) $arg);
                    },
                    $args
                )
            )
        );

        if (in_array($field_type, ['text', 'url', 'email'], true)) {
            return empty($normalized_args);
        }

        if (in_array($field_type, ['post_object', 'relationship', 'taxonomy'], true)) {
            return empty($normalized_args)
                && $this->supportsReferenceLinkField($field);
        }

        if ($field_type !== 'link') {
            return false;
        }

        if (empty($normalized_args)) {
            return true;
        }

        foreach ($normalized_args as $arg) {
            if (in_array($arg, ['url', 'link'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $field
     * @return bool
     */
    private function supportsReferenceLinkField(array $field)
    {
        $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';

        if ($field_type === 'post_object') {
            return empty($field['multiple']);
        }

        if ($field_type === 'relationship') {
            return isset($field['max']) && (int) $field['max'] === 1;
        }

        if ($field_type === 'taxonomy') {
            $selection_type = isset($field['field_type']) ? sanitize_key((string) $field['field_type']) : '';

            return in_array($selection_type, ['select', 'radio'], true);
        }

        return false;
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

        if ($field_type === 'taxonomy') {
            return in_array(isset($field['field_type']) ? sanitize_key((string) $field['field_type']) : '', ['checkbox', 'multi_select'], true);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $loop_context
     * @return array<string, mixed>
     */
    private function resolvePostFieldEntity(array $page_context, array $loop_context)
    {
        if ($this->loops->hasConcretePostOwner($loop_context)) {
            $owner = isset($loop_context['owner_entity']) && is_array($loop_context['owner_entity']) ? $loop_context['owner_entity'] : [];

            if (! empty($owner)) {
                return $owner;
            }
        }

        $entity_id = isset($page_context['entityId']) ? absint($page_context['entityId']) : 0;

        return [
            'type' => 'post',
            'id' => $entity_id,
            'subtype' => isset($page_context['postType']) ? (string) $page_context['postType'] : '',
            'acf_object_id' => $entity_id,
        ];
    }

    /**
     * @param array<string, mixed> $entity
     * @param int                  $page_entity_id
     * @param array<string, mixed> $loop_context
     * @return string
     */
    private function resolvePostFieldScope(array $entity, $page_entity_id, array $loop_context)
    {
        $entity_id = isset($entity['id']) ? absint($entity['id']) : 0;

        if ($entity_id > 0 && $entity_id === $page_entity_id) {
            return 'current_entity';
        }

        return $this->loops->hasConcretePostOwner($loop_context) ? 'related_entity' : 'current_entity';
    }

    /**
     * @param string               $scope
     * @param array<string, mixed> $loop_context
     * @param string               $status
     * @return string|null
     */
    private function buildPostFieldWarning($scope, array $loop_context, $status = 'editable', $field_name = '', $render_context = '')
    {
        $warnings = [];

        if ($scope !== 'related_entity') {
            if ($field_name === 'featured_image' && in_array($render_context, ['image_src', 'background_image'], true)) {
                $warnings[] = $render_context === 'background_image'
                    ? __('This featured image control saves the underlying post thumbnail attachment ID and refreshes the rendered background image in place after save.', 'dbvc')
                    : __('This featured image control saves the underlying post thumbnail attachment ID and refreshes the rendered image attributes in place after save.', 'dbvc');
            }

            return empty($warnings) ? null : implode(' ', $warnings);
        }

        if ($status !== 'editable') {
            $warnings[] = __('This value belongs to a non-current post rendered by a Bricks query loop. It is surfaced here for inspection only until that loop-owned mutation path has a dedicated save contract.', 'dbvc');
        } elseif ($this->loops->supportsRelatedPostEditing($loop_context)) {
            $warnings[] = __('This value belongs to the related post currently rendered by a Bricks ACF relationship/post-object query loop. Saving here updates that related post, not the current page.', 'dbvc');
        }

        if ($field_name === 'featured_image' && in_array($render_context, ['image_src', 'background_image'], true)) {
            $warnings[] = $render_context === 'background_image'
                ? __('This featured image control saves the underlying post thumbnail attachment ID and refreshes the rendered background image in place after save.', 'dbvc')
                : __('This featured image control saves the underlying post thumbnail attachment ID and refreshes the rendered image attributes in place after save.', 'dbvc');
        }

        return empty($warnings) ? null : implode(' ', $warnings);
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $resolved
     * @return bool
     */
    private function isRestrictedOptionsFieldGroup(array $field, array $resolved)
    {
        $entity = isset($resolved['entity']) && is_array($resolved['entity']) ? $resolved['entity'] : [];
        if (($entity['type'] ?? '') !== 'option') {
            return false;
        }

        $restricted_roots = apply_filters('dbvc_visual_editor_restricted_option_group_roots', ['universal_cta_options'], $field, $resolved);
        if (! is_array($restricted_roots) || empty($restricted_roots)) {
            return false;
        }

        $roots = array_values(
            array_filter(
                array_map(
                    static function ($value) {
                        return sanitize_key((string) $value);
                    },
                    $restricted_roots
                )
            )
        );

        if (empty($roots)) {
            return false;
        }

        $tag_object = isset($resolved['tag_object']) && is_array($resolved['tag_object']) ? $resolved['tag_object'] : [];
        $names = [
            isset($field['name']) ? sanitize_key((string) $field['name']) : '',
            isset($tag_object['name']) ? sanitize_key(str_replace('{acf_', '', str_replace('}', '', (string) $tag_object['name']))) : '',
        ];

        if (! empty($tag_object['parent_group_names']) && is_array($tag_object['parent_group_names'])) {
            foreach ($tag_object['parent_group_names'] as $group_name) {
                $names[] = sanitize_key((string) $group_name);
            }
        }

        if (! empty($tag_object['parent']) && is_array($tag_object['parent'])) {
            $names[] = isset($tag_object['parent']['name']) ? sanitize_key((string) $tag_object['parent']['name']) : '';
        }

        $names = array_values(array_unique(array_filter($names)));

        foreach ($roots as $root) {
            if (in_array($root, $names, true)) {
                return true;
            }
        }

        return false;
    }
}
