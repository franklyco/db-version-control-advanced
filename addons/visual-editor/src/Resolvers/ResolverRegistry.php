<?php

namespace Dbvc\VisualEditor\Resolvers;

use Dbvc\VisualEditor\Bricks\AcfFieldContextResolver;
use Dbvc\VisualEditor\Bricks\LoopContextResolver;
use Dbvc\VisualEditor\Bricks\NativeAcfQueryResolver;
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

    /**
     * @var NativeAcfQueryResolver
     */
    private $native_acf_queries;

    public function __construct(?AcfFieldContextResolver $acf_context = null, ?LoopContextResolver $loops = null, ?NativeAcfQueryResolver $native_acf_queries = null)
    {
        $this->loops = $loops instanceof LoopContextResolver ? $loops : new LoopContextResolver();
        $this->acf_context = $acf_context instanceof AcfFieldContextResolver ? $acf_context : new AcfFieldContextResolver($this->loops);
        $this->native_acf_queries = $native_acf_queries instanceof NativeAcfQueryResolver ? $native_acf_queries : new NativeAcfQueryResolver();

        $instances = [
            new PostTitleResolver(),
            new PostExcerptResolver(),
            new PostFeaturedImageResolver(),
            new TermFieldResolver(),
            new NativeReadonlyResolver(),
            new AcfWysiwygResolver(),
            new AcfChoiceResolver(),
            new AcfLinkResolver(),
            new AcfImageResolver(),
            new AcfGalleryResolver(),
            new AcfReferenceCollectionResolver(),
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
        if (empty($page_context['isSupported'])) {
            return $this->buildUnsupported('Missing current entity context.');
        }

        $source_type = isset($candidate['source_type']) ? (string) $candidate['source_type'] : '';
        if ($source_type === 'post_field') {
            return $this->classifyPostField($candidate, $page_context);
        }

        if ($source_type === 'term_field') {
            return $this->classifyTermField($candidate, $page_context);
        }

        if ($source_type === 'archive_field') {
            return $this->classifyArchiveField($candidate, $page_context);
        }

        if ($source_type === 'acf_field') {
            return $this->classifyAcfField($candidate, $page_context);
        }

        if ($source_type === 'acf_collection_field') {
            return $this->classifyAcfCollectionField($candidate, $page_context);
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
        $page_entity_type = isset($page_context['entityType']) ? sanitize_key((string) $page_context['entityType']) : '';
        $is_archive_context = ! empty($page_context['isArchive']);
        $loop_context = $this->loops->resolve();
        $has_concrete_post_owner = $this->loops->hasConcretePostOwner($loop_context);
        $supports_related_editing = $this->loops->supportsRelatedPostEditing($loop_context);

        if ($page_entity_type !== 'post' && ! $has_concrete_post_owner) {
            return $this->buildUnsupported('Post fields on archive contexts are only surfaced when Bricks exposes a concrete query-loop post owner.');
        }

        if (! empty($loop_context['active']) && ! $has_concrete_post_owner) {
            return $this->buildUnsupported('Only Bricks query loops with a concrete post owner are surfaced in the current Visual Editor slice.');
        }

        $entity = $this->resolvePostFieldEntity($page_context, $loop_context);
        $scope = $this->resolvePostFieldScope($entity, $page_entity_id, $loop_context);
        $status = ! empty($loop_context['active']) && ! $supports_related_editing ? 'readonly' : 'editable';
        $warning = $this->buildPostFieldWarning($scope, $loop_context, $status, '', '', $is_archive_context);
        $source_context = $is_archive_context && $scope === 'related_entity' && $has_concrete_post_owner
            ? 'archive_loop_post'
            : '';

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
                    'source_context' => $source_context,
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
                    'source_context' => $source_context,
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
                    'source_context' => $source_context,
                    'media_size' => isset($candidate['media_size']) ? sanitize_key((string) $candidate['media_size']) : '',
                ],
                'resolver' => [
                    'name' => 'post_featured_image',
                    'version' => 1,
                ],
                'ui' => [
                    'label' => __('Featured Image', 'dbvc'),
                    'input' => 'media_reference',
                    'warning' => $this->buildPostFieldWarning($scope, $loop_context, $status, 'featured_image', $render_context, $is_archive_context),
                ],
            ];
        }

        if ($field_name === 'post_url') {
            return [
                'status' => 'readonly',
                'scope' => $scope,
                'entity' => $entity,
                'loop' => $this->loops->export($loop_context),
                'source' => [
                    'type' => 'post_field',
                    'expression' => isset($candidate['expression']) ? (string) $candidate['expression'] : '',
                    'expression_args' => isset($candidate['args']) && is_array($candidate['args']) ? $candidate['args'] : [],
                    'field_name' => 'post_url',
                    'field_key' => '',
                    'field_type' => 'url',
                    'source_context' => $source_context,
                ],
                'resolver' => [
                    'name' => 'native_readonly',
                    'version' => 1,
                ],
                'ui' => [
                    'label' => __('Post URL', 'dbvc'),
                    'input' => 'readonly_preview',
                    'warning' => __('This URL is derived from the post permalink and is inspect-only. Edit the post slug or permalink settings in WordPress to change it.', 'dbvc'),
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
    private function classifyTermField(array $candidate, array $page_context)
    {
        $field_name = isset($candidate['field_name']) ? sanitize_key((string) $candidate['field_name']) : '';
        if (! in_array($field_name, ['term_name', 'term_description', 'term_url', 'term_id'], true)) {
            return $this->buildUnsupported('Only native term name, description, URL, and ID bindings are enabled in the current term-field slice.');
        }

        $loop_context = $this->loops->resolve();
        $loop_term = ! empty($loop_context['active']) ? $this->resolveLoopTermEntity($loop_context) : [];
        $is_archive_context = ! empty($page_context['isArchive']);
        if (! empty($loop_context['active'])) {
            if (empty($loop_term)) {
                return $this->buildUnsupported('Native term fields inside query loops require a concrete term owner.');
            }

            $term_id = isset($loop_term['id']) ? absint($loop_term['id']) : 0;
            $taxonomy = isset($loop_term['taxonomy'])
                ? sanitize_key((string) $loop_term['taxonomy'])
                : (isset($loop_term['subtype']) ? sanitize_key((string) $loop_term['subtype']) : '');
            $scope = 'related_entity';
        } else {
            if (empty($page_context['isTaxonomyArchive'])) {
                return $this->buildUnsupported('Native term field editing is only enabled on taxonomy archive contexts in the current slice.');
            }

            $term_id = isset($page_context['entityId']) ? absint($page_context['entityId']) : 0;
            $taxonomy = isset($page_context['taxonomy']) ? sanitize_key((string) $page_context['taxonomy']) : '';
            $scope = 'current_entity';
        }

        if ($term_id <= 0 || $taxonomy === '') {
            return $this->buildUnsupported('Taxonomy term context is missing.');
        }

        $term = get_term($term_id, $taxonomy);
        if (! $term || is_wp_error($term)) {
            return $this->buildUnsupported('Taxonomy term could not be resolved.');
        }

        $field_type = $field_name === 'term_description' ? 'textarea' : 'text';
        $label = $field_name === 'term_description' ? __('Term Description', 'dbvc') : __('Term Name', 'dbvc');
        $source_context = 'archive_term';
        if ($scope === 'related_entity') {
            $source_context = $is_archive_context ? 'archive_loop_term' : 'loop_term';
        }
        $readonly_field_labels = [
            'term_url' => __('Term URL', 'dbvc'),
            'term_id' => __('Term ID', 'dbvc'),
        ];
        if (isset($readonly_field_labels[$field_name])) {
            return [
                'status' => 'readonly',
                'scope' => $scope,
                'entity' => [
                    'type' => 'term',
                    'id' => $term_id,
                    'subtype' => $taxonomy,
                    'taxonomy' => $taxonomy,
                    'acf_object_id' => $taxonomy . '_' . $term_id,
                ],
                'loop' => $this->loops->export($loop_context),
                'source' => [
                    'type' => 'term_field',
                    'expression' => isset($candidate['expression']) ? (string) $candidate['expression'] : '',
                    'expression_args' => isset($candidate['args']) && is_array($candidate['args']) ? $candidate['args'] : [],
                    'field_name' => $field_name,
                    'field_key' => '',
                    'field_type' => $field_name === 'term_url' ? 'url' : 'number',
                    'source_context' => $source_context,
                    'page_archive_type' => isset($page_context['archiveType']) ? sanitize_key((string) $page_context['archiveType']) : '',
                    'page_archive_key' => isset($page_context['archiveKey']) ? sanitize_text_field((string) $page_context['archiveKey']) : '',
                    'page_taxonomy' => $taxonomy,
                ],
                'resolver' => [
                    'name' => 'native_readonly',
                    'version' => 1,
                ],
                'ui' => [
                    'label' => $readonly_field_labels[$field_name],
                    'input' => 'readonly_preview',
                    'warning' => $field_name === 'term_url'
                        ? __('This URL is derived from the term permalink and is inspect-only. Edit the term slug or permalink settings in WordPress to change it.', 'dbvc')
                        : __('This is the stable taxonomy term ID and is inspect-only.', 'dbvc'),
                ],
            ];
        }

        return [
            'status' => 'editable',
            'scope' => $scope,
            'entity' => [
                'type' => 'term',
                'id' => $term_id,
                'subtype' => $taxonomy,
                'taxonomy' => $taxonomy,
                'acf_object_id' => $taxonomy . '_' . $term_id,
            ],
            'loop' => $this->loops->export($loop_context),
            'source' => [
                'type' => 'term_field',
                'expression' => isset($candidate['expression']) ? (string) $candidate['expression'] : '',
                'expression_args' => isset($candidate['args']) && is_array($candidate['args']) ? $candidate['args'] : [],
                'field_name' => $field_name,
                'field_key' => '',
                'field_type' => $field_type,
                'source_context' => $source_context,
                'page_archive_type' => isset($page_context['archiveType']) ? sanitize_key((string) $page_context['archiveType']) : '',
                'page_archive_key' => isset($page_context['archiveKey']) ? sanitize_text_field((string) $page_context['archiveKey']) : '',
                'page_taxonomy' => $taxonomy,
            ],
            'resolver' => [
                'name' => 'term_field',
                'version' => 1,
            ],
            'ui' => [
                'label' => $label,
                'input' => $field_name === 'term_description' ? 'textarea' : 'text',
                'warning' => $this->buildTermFieldWarning($scope, $is_archive_context),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $loop_context
     * @return array<string, mixed>
     */
    private function resolveLoopTermEntity(array $loop_context)
    {
        if (empty($loop_context['active'])) {
            return [];
        }

        $owner = isset($loop_context['effective_owner_entity']) && is_array($loop_context['effective_owner_entity'])
            ? $loop_context['effective_owner_entity']
            : (isset($loop_context['owner_entity']) && is_array($loop_context['owner_entity']) ? $loop_context['owner_entity'] : []);

        if ((string) ($owner['type'] ?? '') !== 'term' || absint($owner['id'] ?? 0) <= 0) {
            return [];
        }

        $taxonomy = isset($owner['taxonomy'])
            ? sanitize_key((string) $owner['taxonomy'])
            : (isset($owner['subtype']) ? sanitize_key((string) $owner['subtype']) : '');

        if ($taxonomy === '') {
            return [];
        }

        return [
            'type' => 'term',
            'id' => absint($owner['id']),
            'subtype' => $taxonomy,
            'taxonomy' => $taxonomy,
            'acf_object_id' => isset($owner['acf_object_id']) && (string) $owner['acf_object_id'] !== ''
                ? sanitize_text_field((string) $owner['acf_object_id'])
                : $taxonomy . '_' . absint($owner['id']),
        ];
    }

    /**
     * @param string $scope
     * @param bool   $is_archive_context
     * @return string
     */
    private function buildTermFieldWarning($scope, $is_archive_context = false)
    {
        if ($scope === 'related_entity') {
            if ($is_archive_context) {
                return __('This edits the native field on the related taxonomy term currently rendered by a Bricks query loop, not the archive term itself.', 'dbvc');
            }

            return __('This edits the native field on the related taxonomy term currently rendered by a Bricks query loop, not the current page.', 'dbvc');
        }

        return __('This edits the native field on the queried taxonomy term for this archive page.', 'dbvc');
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $page_context
     * @return array<string, mixed>
     */
    private function classifyArchiveField(array $candidate, array $page_context)
    {
        $field_name = isset($candidate['field_name']) ? sanitize_key((string) $candidate['field_name']) : '';
        if ($field_name !== 'archive_title') {
            return $this->buildUnsupported('Only derived archive title inspection is enabled in the current archive-field slice.');
        }

        if (empty($page_context['isArchive'])) {
            return $this->buildUnsupported('Archive fields are only surfaced on supported archive contexts.');
        }

        $entity = [
            'type' => 'archive',
            'id' => 0,
            'subtype' => isset($page_context['archiveType']) ? sanitize_key((string) $page_context['archiveType']) : '',
            'acf_object_id' => '',
        ];

        if (! empty($page_context['isTaxonomyArchive'])) {
            $term_id = isset($page_context['entityId']) ? absint($page_context['entityId']) : 0;
            $taxonomy = isset($page_context['taxonomy']) ? sanitize_key((string) $page_context['taxonomy']) : '';
            if ($term_id > 0 && $taxonomy !== '') {
                $entity = [
                    'type' => 'term',
                    'id' => $term_id,
                    'subtype' => $taxonomy,
                    'taxonomy' => $taxonomy,
                    'acf_object_id' => $taxonomy . '_' . $term_id,
                ];
            }
        } elseif (! empty($page_context['isPostTypeArchive'])) {
            $post_type = isset($page_context['postType']) ? sanitize_key((string) $page_context['postType']) : '';
            $entity['subtype'] = $post_type !== '' ? $post_type : $entity['subtype'];
        }

        return [
            'status' => 'editable',
            'scope' => 'current_entity',
            'entity' => $entity,
            'loop' => [],
            'source' => [
                'type' => 'archive_field',
                'expression' => isset($candidate['expression']) ? (string) $candidate['expression'] : '',
                'expression_args' => isset($candidate['args']) && is_array($candidate['args']) ? $candidate['args'] : [],
                'field_name' => 'archive_title',
                'field_key' => '',
                'field_type' => 'text',
                'source_context' => 'archive_derived',
                'page_archive_type' => isset($page_context['archiveType']) ? sanitize_key((string) $page_context['archiveType']) : '',
                'page_archive_key' => isset($page_context['archiveKey']) ? sanitize_text_field((string) $page_context['archiveKey']) : '',
                'page_post_type' => isset($page_context['postType']) ? sanitize_key((string) $page_context['postType']) : '',
                'page_taxonomy' => isset($page_context['taxonomy']) ? sanitize_key((string) $page_context['taxonomy']) : '',
            ],
            'resolver' => [
                'name' => 'native_readonly',
                'version' => 1,
            ],
            'ui' => [
                'label' => __('Archive Title', 'dbvc'),
                'input' => 'readonly_preview',
                'warning' => __('This archive title is a derived Bricks/WordPress value and is inspect-only. Edit the underlying term, post type labels, or archive options fields that feed the title instead.', 'dbvc'),
            ],
        ];
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
        $parent_field_selector = ! empty($repeater['supported'])
            ? sanitize_key((string) ($repeater['parent_field_selector'] ?? ''))
            : (! empty($flexible['supported']) ? sanitize_key((string) ($flexible['parent_field_selector'] ?? '')) : '');
        $row_index = ! empty($repeater['supported'])
            ? (isset($repeater['row_index']) ? absint($repeater['row_index']) : null)
            : (! empty($flexible['supported']) && isset($flexible['row_index']) ? absint($flexible['row_index']) : null);
        $layout_key = ! empty($flexible['supported']) ? sanitize_key((string) ($flexible['layout_key'] ?? '')) : '';
        $layout_name = ! empty($flexible['supported']) ? sanitize_key((string) ($flexible['layout_name'] ?? '')) : '';
        $tag_object = isset($resolved['tag_object']) && is_array($resolved['tag_object']) ? $resolved['tag_object'] : [];
        $loop = isset($resolved['loop']) && is_array($resolved['loop']) ? $resolved['loop'] : [];
        $native_query = isset($loop['native_acf_query']) && is_array($loop['native_acf_query']) ? $loop['native_acf_query'] : [];
        $parent_native_query = isset($loop['parent_native_acf_query']) && is_array($loop['parent_native_acf_query']) ? $loop['parent_native_acf_query'] : [];
        $native_query_ancestry = isset($loop['native_acf_query_ancestry']) && is_array($loop['native_acf_query_ancestry'])
            ? $this->normalizeNativeQueryAncestry($loop['native_acf_query_ancestry'])
            : [];
        $leaf_field_name = isset($tag_object['field']['name']) ? sanitize_key((string) $tag_object['field']['name']) : $field_name;
        $leaf_field_key = isset($tag_object['field']['key']) ? sanitize_key((string) $tag_object['field']['key']) : $field_key;
        $group_path = $this->normalizeNestedGroupPath($tag_object, $parent_field_name);
        $group_key_path = $this->normalizeNestedGroupKeyPath($tag_object, $parent_field_key);
        $nested_repeater_path = ! empty($repeater['supported'])
            ? $this->normalizeNestedRepeaterPath($repeater)
            : [];
        $field_selector = $this->resolveAcfFieldSelector($tag_object, $field_name);
        $label = isset($field['label']) && (string) $field['label'] !== ''
            ? sanitize_text_field((string) $field['label'])
            : ucwords(str_replace('_', ' ', $field_name));
        $field_group_context = $this->resolveAcfFieldGroupContext($field);
        $source_context = $this->resolveAcfSourceContext($page_context, $resolved, $field_group_context);
        $allow_multiple = $field_type === 'checkbox'
            || ($field_type === 'select' && ! empty($field['multiple']));
        $readonly_reason = ! empty($page_context['isArchive'])
            ? $this->resolveArchiveAcfReadonlyReason($page_context, $field, $resolved, $render_context)
            : $this->resolveAcfReadonlyReason($field, $resolved, $render_context);
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
            'loop' => $loop,
            'source' => [
                'type' => $source_type,
                'expression' => isset($candidate['expression']) ? (string) $candidate['expression'] : '',
                'expression_args' => isset($resolved['tag_args']) && is_array($resolved['tag_args']) ? $resolved['tag_args'] : [],
                'field_name' => $field_name,
                'field_key' => $field_key,
                'field_selector' => $field_selector,
                'leaf_field_name' => $leaf_field_name,
                'leaf_field_key' => $leaf_field_key,
                'field_type' => $field_type,
                'source_context' => $source_context,
                'field_group_key' => isset($field_group_context['key']) ? sanitize_key((string) $field_group_context['key']) : '',
                'field_group_title' => isset($field_group_context['title']) ? sanitize_text_field((string) $field_group_context['title']) : '',
                'field_group_option_pages' => isset($field_group_context['option_pages']) && is_array($field_group_context['option_pages']) ? $field_group_context['option_pages'] : [],
                'return_format' => $return_format,
                'media_size' => isset($candidate['media_size']) ? sanitize_key((string) $candidate['media_size']) : '',
                'reference_post_types' => $this->normalizeStringList($field['post_type'] ?? []),
                'reference_taxonomies' => $this->normalizeStringList($field['taxonomy'] ?? []),
                'reference_multiple' => $this->isReferenceMultiple($field),
                'container_type' => $container_type,
                'parent_field_name' => $parent_field_name,
                'parent_field_key' => $parent_field_key,
                'parent_field_selector' => $parent_field_selector,
                'row_index' => $row_index,
                'layout_key' => $layout_key,
                'layout_name' => $layout_name,
                'group_path' => $group_path,
                'group_key_path' => $group_key_path,
                'nested_repeater_path' => $nested_repeater_path,
                'is_nested_group' => ! empty($group_path),
                'is_grouped_field' => ! empty($group_path),
                'native_query_active' => ! empty($native_query['active']),
                'native_query_kind' => isset($native_query['kind']) ? sanitize_key((string) $native_query['kind']) : '',
                'native_query_selector' => isset($native_query['selector']) ? sanitize_key((string) $native_query['selector']) : '',
                'native_query_object_type' => isset($native_query['objectType']) ? sanitize_key((string) $native_query['objectType']) : '',
                'native_query_field_name' => isset($native_query['fieldName']) ? sanitize_key((string) $native_query['fieldName']) : '',
                'native_query_field_type' => isset($native_query['fieldType']) ? sanitize_key((string) $native_query['fieldType']) : '',
                'parent_native_query_active' => ! empty($parent_native_query['active']),
                'parent_native_query_kind' => isset($parent_native_query['kind']) ? sanitize_key((string) $parent_native_query['kind']) : '',
                'parent_native_query_selector' => isset($parent_native_query['selector']) ? sanitize_key((string) $parent_native_query['selector']) : '',
                'parent_native_query_object_type' => isset($parent_native_query['objectType']) ? sanitize_key((string) $parent_native_query['objectType']) : '',
                'parent_native_query_field_name' => isset($parent_native_query['fieldName']) ? sanitize_key((string) $parent_native_query['fieldName']) : '',
                'parent_native_query_field_type' => isset($parent_native_query['fieldType']) ? sanitize_key((string) $parent_native_query['fieldType']) : '',
                'native_query_ancestry' => $native_query_ancestry,
                'page_archive_type' => isset($page_context['archiveType']) ? sanitize_key((string) $page_context['archiveType']) : '',
                'page_archive_key' => isset($page_context['archiveKey']) ? sanitize_text_field((string) $page_context['archiveKey']) : '',
                'page_post_type' => isset($page_context['postType']) ? sanitize_key((string) $page_context['postType']) : '',
                'page_taxonomy' => isset($page_context['taxonomy']) ? sanitize_key((string) $page_context['taxonomy']) : '',
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
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $page_context
     * @return array<string, mixed>
     */
    private function classifyAcfCollectionField(array $candidate, array $page_context)
    {
        if (! function_exists('get_field_object')) {
            return $this->buildUnsupported('ACF runtime is unavailable.');
        }

        if ((isset($candidate['query_source']) ? sanitize_key((string) $candidate['query_source']) : '') === 'derived_bricks_query') {
            return $this->classifyDerivedBricksQueryCollectionField($candidate, $page_context);
        }

        $entity_id = isset($page_context['entityId']) ? absint($page_context['entityId']) : 0;
        $entity_type = isset($page_context['entityType']) ? sanitize_key((string) $page_context['entityType']) : '';
        $post_type = isset($page_context['postType']) ? sanitize_key((string) $page_context['postType']) : '';
        $query_object_type = isset($candidate['query_object_type']) ? sanitize_key((string) $candidate['query_object_type']) : '';

        if ($entity_type !== 'post' || $entity_id <= 0 || $query_object_type === '') {
            return $this->buildUnsupported('Only current-page post contexts are enabled in the current connected-items editor slice.');
        }

        $loop_context = $this->loops->resolve();
        $entity = $this->resolveCollectionFieldEntity($page_context, $loop_context);
        $scope = $this->resolveCollectionFieldScope($entity, $entity_id, $loop_context);

        if (empty($entity) || ($entity['type'] ?? '') !== 'post' || absint($entity['id'] ?? 0) <= 0) {
            return $this->buildUnsupported('Only current-page and related-post connected-item collection roots are enabled in the current connected-items editor slice.');
        }

        if ($scope === 'related_entity' && ! $this->loops->supportsRelatedPostEditing($loop_context)) {
            return $this->buildUnsupported('Loop-owned connected-item collection editing is only enabled for concrete related post owners in the current slice.');
        }

        $native_query = $this->native_acf_queries->resolve($query_object_type, $entity);
        $field = $this->native_acf_queries->resolveFieldDefinitionForQuery($query_object_type, $entity);

        if (empty($native_query['active']) || empty($field)) {
            return $this->buildUnsupported($scope === 'related_entity'
                ? 'The Bricks ACF query root could not be mapped back to a direct related-post ACF relationship or post-object field.'
                : 'The Bricks ACF query root could not be mapped back to a direct ACF relationship or post-object field.');
        }

        $field_path = isset($native_query['path']) && is_array($native_query['path'])
            ? array_values(array_filter(array_map('sanitize_key', $native_query['path'])))
            : [];
        $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        if (! in_array($field_type, ['relationship', 'post_object'], true)) {
            return $this->buildUnsupported('Only direct ACF relationship and post-object query roots are enabled in the current connected-items editor slice.');
        }

        $collection_context = [
            'loop' => [],
            'repeater' => [],
            'flexible' => [],
            'source' => [
                'container_type' => '',
                'parent_field_name' => '',
                'parent_field_key' => '',
                'parent_field_selector' => '',
                'row_index' => null,
                'layout_key' => '',
                'layout_name' => '',
                'container_ancestry' => [],
                'group_path' => [],
                'group_key_path' => [],
                'nested_repeater_path' => [],
                'is_nested_group' => false,
                'is_grouped_field' => false,
            ],
        ];

        if (count($field_path) > 1) {
            $collection_context = $this->resolveCurrentOwnerNestedCollectionContext($native_query, $field, $loop_context, $entity);

            if (empty($collection_context)) {
                return $this->buildUnsupported('Nested current-owner relationship and post-object collections are only enabled when the row and group ancestry can be resolved canonically in the current connected-items slice.');
            }
        }

        $field_name = isset($field['name']) ? sanitize_key((string) $field['name']) : '';
        $field_key = isset($field['key']) ? sanitize_key((string) $field['key']) : '';
        $field_selector = isset($native_query['selector']) ? sanitize_key((string) $native_query['selector']) : $field_name;
        $allow_multiple = $this->isReferenceMultiple($field);
        $max = $this->resolveReferenceMaxSelections($field);
        $min = $this->resolveReferenceMinSelections($field);
        $label = isset($field['label']) && (string) $field['label'] !== ''
            ? sanitize_text_field((string) $field['label'])
            : __('Connected items', 'dbvc');
        $source_context = isset($collection_context['source']) && is_array($collection_context['source'])
            ? $collection_context['source']
            : [];
        $repeater_context = isset($collection_context['repeater']) && is_array($collection_context['repeater'])
            ? $collection_context['repeater']
            : [];
        $flexible_context = isset($collection_context['flexible']) && is_array($collection_context['flexible'])
            ? $collection_context['flexible']
            : [];
        $loop_export = isset($collection_context['loop']) && is_array($collection_context['loop'])
            ? $collection_context['loop']
            : [];

        return [
            'status' => 'editable',
            'scope' => $scope,
            'entity' => $entity,
            'loop' => $loop_export,
            'repeater' => $repeater_context,
            'flexible' => $flexible_context,
            'source' => [
                'type' => 'acf_collection_field',
                'expression' => 'query.objectType:' . $query_object_type,
                'expression_args' => [],
                'field_name' => $field_name,
                'field_key' => $field_key,
                'field_selector' => $field_selector,
                'leaf_field_name' => $field_name,
                'leaf_field_key' => $field_key,
                'field_type' => $field_type,
                'return_format' => isset($field['return_format']) ? sanitize_key((string) $field['return_format']) : 'value',
                'reference_post_types' => $this->normalizeStringList($field['post_type'] ?? []),
                'reference_taxonomies' => $this->normalizeStringList($field['taxonomy'] ?? []),
                'reference_multiple' => $allow_multiple,
                'reference_min' => $min,
                'reference_max' => $max,
                'container_type' => isset($source_context['container_type']) ? sanitize_key((string) $source_context['container_type']) : '',
                'parent_field_name' => isset($source_context['parent_field_name']) ? sanitize_key((string) $source_context['parent_field_name']) : '',
                'parent_field_key' => isset($source_context['parent_field_key']) ? sanitize_key((string) $source_context['parent_field_key']) : '',
                'parent_field_selector' => isset($source_context['parent_field_selector']) ? sanitize_key((string) $source_context['parent_field_selector']) : '',
                'row_index' => isset($source_context['row_index']) && $source_context['row_index'] !== null && is_numeric($source_context['row_index'])
                    ? absint($source_context['row_index'])
                    : null,
                'layout_key' => isset($source_context['layout_key']) ? sanitize_key((string) $source_context['layout_key']) : '',
                'layout_name' => isset($source_context['layout_name']) ? sanitize_key((string) $source_context['layout_name']) : '',
                'container_ancestry' => isset($source_context['container_ancestry']) && is_array($source_context['container_ancestry']) ? array_values($source_context['container_ancestry']) : [],
                'group_path' => isset($source_context['group_path']) && is_array($source_context['group_path']) ? $source_context['group_path'] : [],
                'group_key_path' => isset($source_context['group_key_path']) && is_array($source_context['group_key_path']) ? $source_context['group_key_path'] : [],
                'nested_repeater_path' => isset($source_context['nested_repeater_path']) && is_array($source_context['nested_repeater_path']) ? $source_context['nested_repeater_path'] : [],
                'is_nested_group' => ! empty($source_context['is_nested_group']),
                'is_grouped_field' => ! empty($source_context['is_grouped_field']),
                'native_query_active' => ! empty($native_query['active']),
                'native_query_kind' => isset($native_query['kind']) ? sanitize_key((string) $native_query['kind']) : '',
                'native_query_selector' => $field_selector,
                'native_query_object_type' => $query_object_type,
                'native_query_field_name' => isset($native_query['fieldName']) ? sanitize_key((string) $native_query['fieldName']) : $field_name,
                'native_query_field_type' => isset($native_query['fieldType']) ? sanitize_key((string) $native_query['fieldType']) : $field_type,
                'parent_native_query_active' => false,
                'parent_native_query_kind' => '',
                'parent_native_query_selector' => '',
                'parent_native_query_object_type' => '',
                'parent_native_query_field_name' => '',
                'parent_native_query_field_type' => '',
                'native_query_ancestry' => [],
            ],
            'resolver' => [
                'name' => 'acf_reference_collection',
                'version' => 1,
            ],
            'ui' => [
                'label' => $label,
                'input' => 'reference_collection',
                'warning' => $this->buildCollectionFieldWarning($scope, $loop_context, $repeater_context, $flexible_context),
                'allowMultiple' => $allow_multiple,
                'maxSelections' => $max,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $page_context
     * @return array<string, mixed>
     */
    private function classifyDerivedBricksQueryCollectionField(array $candidate, array $page_context)
    {
        if (! function_exists('get_field_objects') || ! function_exists('get_field')) {
            return $this->buildUnsupported('ACF runtime is unavailable.');
        }

        $entity_id = isset($page_context['entityId']) ? absint($page_context['entityId']) : 0;
        $entity_type = isset($page_context['entityType']) ? sanitize_key((string) $page_context['entityType']) : '';
        $post_type = isset($page_context['postType']) ? sanitize_key((string) $page_context['postType']) : '';
        if ($entity_type !== 'post' || $entity_id <= 0) {
            return $this->buildUnsupported('Derived Bricks query collections are only inspected for current post/page owners in this slice.');
        }

        $target_post_type = isset($candidate['target_post_type']) ? sanitize_key((string) $candidate['target_post_type']) : '';
        $query_ids = $this->normalizeReferenceIdList(isset($candidate['query_result_ids']) ? $candidate['query_result_ids'] : []);
        $query_result_post_types = isset($candidate['query_result_post_types']) && is_array($candidate['query_result_post_types'])
            ? array_values(array_filter(array_map('sanitize_key', $candidate['query_result_post_types'])))
            : [];
        $is_full_collection_query = $target_post_type === '';
        if (empty($query_ids) || ($target_post_type === '' && empty($query_result_post_types))) {
            return $this->buildUnsupported('Derived Bricks query collections require a concrete post type and post__in result set.');
        }

        $query_vars = isset($candidate['query_vars']) && is_array($candidate['query_vars']) ? $candidate['query_vars'] : [];
        $query_editor_active = ! empty($query_vars['query_editor_active']);
        $query_setting_source = isset($query_vars['post__in_setting_source']) ? sanitize_key((string) $query_vars['post__in_setting_source']) : '';
        $query_dynamic_field_hints = isset($query_vars['post__in_acf_field_hints']) && is_array($query_vars['post__in_acf_field_hints'])
            ? array_values(array_filter(array_map('sanitize_key', $query_vars['post__in_acf_field_hints'])))
            : [];
        $query_editor_field_hints = isset($query_vars['query_editor_acf_field_hints']) && is_array($query_vars['query_editor_acf_field_hints'])
            ? array_values(array_filter(array_map('sanitize_key', $query_vars['query_editor_acf_field_hints'])))
            : [];
        $query_editor_option_field_hints = isset($query_vars['query_editor_acf_option_field_hints']) && is_array($query_vars['query_editor_acf_option_field_hints'])
            ? array_values(array_filter(array_map('sanitize_key', $query_vars['query_editor_acf_option_field_hints'])))
            : [];
        $query_editor_explicit_field_hints = isset($query_vars['query_editor_acf_explicit_field_hints']) && is_array($query_vars['query_editor_acf_explicit_field_hints'])
            ? array_values(array_filter(array_map('sanitize_key', $query_vars['query_editor_acf_explicit_field_hints'])))
            : [];

        if (! $query_editor_active
            && empty($query_dynamic_field_hints)
            && strpos($query_setting_source, 'settings_static_') === 0) {
            return $this->buildUnsupported('Native Bricks include/post__in controls with static post IDs do not identify a writable ACF source field.');
        }

        $field_objects = get_field_objects($entity_id, false, true);
        if (! is_array($field_objects) || empty($field_objects)) {
            return $this->buildUnsupported('No current-owner ACF fields were available for derived Bricks query collection inspection.');
        }

        $matches = [];
        foreach ($field_objects as $field) {
            if (! is_array($field)) {
                continue;
            }

            $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
            if (! in_array($field_type, ['relationship', 'post_object'], true)) {
                continue;
            }

            $allowed_post_types = $this->normalizeStringList($field['post_type'] ?? []);
            if (! empty($allowed_post_types) && ! $this->fieldAllowsQueryPostTypes($allowed_post_types, $target_post_type, $query_result_post_types)) {
                continue;
            }

            $field_name = isset($field['name']) ? sanitize_key((string) $field['name']) : '';
            if ($field_name === '') {
                continue;
            }

            if (! empty($query_dynamic_field_hints) && ! in_array($field_name, $query_dynamic_field_hints, true)) {
                continue;
            }

            $stored_value = get_field($field_name, $entity_id, false);
            $stored_ids = $this->normalizeReferenceIdList($stored_value);
            if (empty($stored_ids)) {
                continue;
            }

            $target_ids = $is_full_collection_query
                ? $stored_ids
                : $this->filterReferenceIdsByPostType($stored_ids, $target_post_type);
            if ($target_ids === $query_ids) {
                $matches[] = [
                    'field' => $field,
                    'stored_ids' => $stored_ids,
                    'target_ids' => $target_ids,
                ];
            }
        }

        if (count($matches) !== 1) {
            if ($query_editor_active) {
                return $this->classifyDerivedBricksQueryReadonlyBranch(
                    $candidate,
                    $page_context,
                    $query_vars,
                    $query_ids,
                    $target_post_type,
                    $query_result_post_types,
                    $query_dynamic_field_hints,
                    $query_editor_field_hints,
                    $query_editor_option_field_hints,
                    $query_editor_explicit_field_hints
                );
            }

            return $this->buildUnsupported(count($matches) > 1
                ? 'Derived Bricks query collection inspection found multiple current-owner ACF fields with the same queried subset.'
                : 'Derived Bricks query collection inspection could not prove a single current-owner ACF relationship/post-object source.');
        }

        $match = $matches[0];
        $field = isset($match['field']) && is_array($match['field']) ? $match['field'] : [];
        $field_name = isset($field['name']) ? sanitize_key((string) $field['name']) : '';
        $field_key = isset($field['key']) ? sanitize_key((string) $field['key']) : '';
        $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        $allow_multiple = $this->isReferenceMultiple($field);
        $max = $this->resolveReferenceMaxSelections($field);
        $min = $this->resolveReferenceMinSelections($field);
        $stored_ids = isset($match['stored_ids']) && is_array($match['stored_ids']) ? array_values(array_filter(array_map('absint', $match['stored_ids']))) : [];
        $preserved_ids = $is_full_collection_query ? [] : array_values(array_diff($stored_ids, $query_ids));
        $query_element_id = isset($candidate['query_element_id']) ? sanitize_text_field((string) $candidate['query_element_id']) : '';
        $query_element_label = isset($candidate['query_element_label']) ? sanitize_text_field((string) $candidate['query_element_label']) : '';
        $query_section_label = isset($candidate['query_section_label']) ? sanitize_text_field((string) $candidate['query_section_label']) : '';
        $query_badge_subject = isset($candidate['query_badge_subject']) ? sanitize_text_field((string) $candidate['query_badge_subject']) : '';
        $label = isset($field['label']) && (string) $field['label'] !== ''
            ? sanitize_text_field((string) $field['label'])
            : __('Linked posts', 'dbvc');
        $query_target_post_type_label = $this->resolvePostTypeBadgeLabel($target_post_type);
        $badge_label = $this->buildDerivedBricksQueryBadgeLabel($query_badge_subject, $target_post_type, $query_element_label);

        return [
            'status' => 'editable',
            'scope' => 'current_entity',
            'entity' => [
                'type' => 'post',
                'id' => $entity_id,
                'subtype' => $post_type,
                'acf_object_id' => $entity_id,
            ],
            'loop' => [],
            'repeater' => [],
            'flexible' => [],
            'source' => [
                'type' => 'acf_collection_field',
                'source_context' => 'current_entity',
                'expression' => 'query.derived:' . $query_element_id,
                'expression_args' => [],
                'field_name' => $field_name,
                'field_key' => $field_key,
                'field_selector' => $field_name,
                'leaf_field_name' => $field_name,
                'leaf_field_key' => $field_key,
                'field_type' => $field_type,
                'return_format' => isset($field['return_format']) ? sanitize_key((string) $field['return_format']) : 'value',
                'reference_post_types' => $this->normalizeStringList($field['post_type'] ?? []),
                'reference_taxonomies' => $this->normalizeStringList($field['taxonomy'] ?? []),
                'reference_multiple' => $allow_multiple,
                'reference_min' => $min,
                'reference_max' => $max,
                'query_source' => 'derived_bricks_query',
                'query_source_confidence' => $this->resolveDerivedQuerySourceConfidence($is_full_collection_query, ! empty($query_dynamic_field_hints)),
                'query_element_id' => $query_element_id,
                'query_element_label' => $query_element_label,
                'query_section_label' => $query_section_label,
                'query_badge_subject' => $query_badge_subject,
                'query_target_post_type' => $target_post_type,
                'query_target_post_type_label' => $query_target_post_type_label,
                'query_result_post_types' => $query_result_post_types,
                'query_result_ids' => $query_ids,
                'query_full_value_ids' => $stored_ids,
                'query_preserved_ids' => $preserved_ids,
                'query_subset_write_mode' => $is_full_collection_query ? '' : 'replace_target_post_type_subset',
                'query_collection_write_mode' => $is_full_collection_query ? 'replace_full_collection' : 'replace_target_post_type_subset',
                'query_id_source' => isset($query_vars['post__in_source']) ? sanitize_key((string) $query_vars['post__in_source']) : '',
                'query_id_setting_source' => $query_setting_source,
                'query_id_setting_key' => isset($query_vars['post__in_setting_key']) ? sanitize_key((string) $query_vars['post__in_setting_key']) : '',
                'query_dynamic_tags' => isset($query_vars['post__in_dynamic_tags']) && is_array($query_vars['post__in_dynamic_tags']) ? array_values(array_map('sanitize_text_field', $query_vars['post__in_dynamic_tags'])) : [],
                'query_dynamic_field_hints' => $query_dynamic_field_hints,
                'query_editor_field_hints' => $query_editor_field_hints,
                'query_editor_option_field_hints' => $query_editor_option_field_hints,
                'query_editor_explicit_field_hints' => $query_editor_explicit_field_hints,
                'query_editor_active' => $query_editor_active,
                'query_vars' => [
                    'source' => isset($query_vars['source']) ? sanitize_text_field((string) $query_vars['source']) : '',
                    'post_type' => isset($query_vars['post_type']) ? sanitize_key((string) $query_vars['post_type']) : '',
                    'orderby' => isset($query_vars['orderby']) ? sanitize_key((string) $query_vars['orderby']) : '',
                    'posts_per_page' => isset($query_vars['posts_per_page']) && is_numeric($query_vars['posts_per_page']) ? (int) $query_vars['posts_per_page'] : 0,
                    'paged' => isset($query_vars['paged']) && is_numeric($query_vars['paged']) ? max(1, absint($query_vars['paged'])) : 1,
                    'disable_query_merge' => ! empty($query_vars['disable_query_merge']),
                    'query_editor_active' => $query_editor_active,
                    'query_editor_field_hints' => $query_editor_field_hints,
                    'query_editor_option_field_hints' => $query_editor_option_field_hints,
                    'query_editor_explicit_field_hints' => $query_editor_explicit_field_hints,
                ],
                'container_type' => '',
                'parent_field_name' => '',
                'parent_field_key' => '',
                'parent_field_selector' => '',
                'row_index' => null,
                'layout_key' => '',
                'layout_name' => '',
                'container_ancestry' => [],
                'group_path' => [],
                'group_key_path' => [],
                'nested_repeater_path' => [],
                'is_nested_group' => false,
                'is_grouped_field' => false,
                'native_query_active' => false,
                'native_query_kind' => '',
                'native_query_selector' => '',
                'native_query_object_type' => '',
                'native_query_field_name' => '',
                'native_query_field_type' => '',
                'parent_native_query_active' => false,
                'parent_native_query_kind' => '',
                'parent_native_query_selector' => '',
                'parent_native_query_object_type' => '',
                'parent_native_query_field_name' => '',
                'parent_native_query_field_type' => '',
                'native_query_ancestry' => [],
            ],
            'resolver' => [
                'name' => 'acf_reference_collection',
                'version' => 1,
            ],
            'ui' => [
                'label' => $label,
                'badgeLabel' => $badge_label,
                'input' => 'reference_collection',
                'warning' => $this->buildDerivedBricksQueryCollectionWarning($target_post_type, $is_full_collection_query),
                'allowMultiple' => $allow_multiple,
                'maxSelections' => $max,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $query_vars
     * @param array<int, int>      $query_ids
     * @param string               $target_post_type
     * @param array<int, string>   $query_result_post_types
     * @param array<int, string>   $query_dynamic_field_hints
     * @param array<int, string>   $query_editor_field_hints
     * @param array<int, string>   $query_editor_option_field_hints
     * @param array<int, string>   $query_editor_explicit_field_hints
     * @return array<string, mixed>
     */
    private function classifyDerivedBricksQueryReadonlyBranch(array $candidate, array $page_context, array $query_vars, array $query_ids, $target_post_type, array $query_result_post_types, array $query_dynamic_field_hints, array $query_editor_field_hints, array $query_editor_option_field_hints, array $query_editor_explicit_field_hints)
    {
        $option_match = $this->matchDerivedQueryOptionFallbackField($query_editor_option_field_hints, $target_post_type, $query_result_post_types, $query_ids);
        if (! empty($option_match)) {
            $option_field = isset($option_match['field']) && is_array($option_match['field']) ? $option_match['field'] : [];
            $option_field['_dbvc_stored_ids'] = isset($option_match['stored_ids']) && is_array($option_match['stored_ids'])
                ? array_values(array_filter(array_map('absint', $option_match['stored_ids'])))
                : [];

            return $this->buildReadonlyDerivedBricksQueryCollectionClassification(
                $candidate,
                $page_context,
                $query_vars,
                $query_ids,
                $target_post_type,
                $query_result_post_types,
                $query_dynamic_field_hints,
                $query_editor_field_hints,
                $query_editor_option_field_hints,
                $query_editor_explicit_field_hints,
                $option_field,
                'shared_option_fallback_exact_match'
            );
        }

        return $this->buildReadonlyDerivedBricksQueryCollectionClassification(
            $candidate,
            $page_context,
            $query_vars,
            $query_ids,
            $target_post_type,
            $query_result_post_types,
            $query_dynamic_field_hints,
            $query_editor_field_hints,
            $query_editor_option_field_hints,
            $query_editor_explicit_field_hints,
            [],
            'query_editor_post_in_unmatched'
        );
    }

    /**
     * @param array<int, string> $option_field_hints
     * @param string             $target_post_type
     * @param array<int, string> $query_result_post_types
     * @param array<int, int>    $query_ids
     * @return array<string, mixed>
     */
    private function matchDerivedQueryOptionFallbackField(array $option_field_hints, $target_post_type, array $query_result_post_types, array $query_ids)
    {
        if (! function_exists('get_field_object') || ! function_exists('get_field') || empty($option_field_hints)) {
            return [];
        }

        $target_post_type = sanitize_key((string) $target_post_type);
        $is_full_collection_query = $target_post_type === '';
        $matches = [];

        foreach (array_values(array_unique(array_filter(array_map('sanitize_key', $option_field_hints)))) as $field_name) {
            $field = get_field_object($field_name, 'option', false, true);
            if (! is_array($field)) {
                continue;
            }

            $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
            if (! in_array($field_type, ['relationship', 'post_object'], true)) {
                continue;
            }

            $allowed_post_types = $this->normalizeStringList($field['post_type'] ?? []);
            if (! empty($allowed_post_types) && ! $this->fieldAllowsQueryPostTypes($allowed_post_types, $target_post_type, $query_result_post_types)) {
                continue;
            }

            $stored_value = get_field($field_name, 'option', false);
            $stored_ids = $this->normalizeReferenceIdList($stored_value);
            if (empty($stored_ids)) {
                continue;
            }

            $target_ids = $is_full_collection_query
                ? $stored_ids
                : $this->filterReferenceIdsByPostType($stored_ids, $target_post_type);

            if ($target_ids === $query_ids) {
                $matches[] = [
                    'field' => $field,
                    'stored_ids' => $stored_ids,
                ];
            }
        }

        return count($matches) === 1 ? $matches[0] : [];
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $query_vars
     * @param array<int, int>      $query_ids
     * @param string               $target_post_type
     * @param array<int, string>   $query_result_post_types
     * @param array<int, string>   $query_dynamic_field_hints
     * @param array<int, string>   $query_editor_field_hints
     * @param array<int, string>   $query_editor_option_field_hints
     * @param array<int, string>   $query_editor_explicit_field_hints
     * @param array<string, mixed> $field
     * @param string               $branch_state
     * @return array<string, mixed>
     */
    private function buildReadonlyDerivedBricksQueryCollectionClassification(array $candidate, array $page_context, array $query_vars, array $query_ids, $target_post_type, array $query_result_post_types, array $query_dynamic_field_hints, array $query_editor_field_hints, array $query_editor_option_field_hints, array $query_editor_explicit_field_hints, array $field, $branch_state)
    {
        $branch_state = sanitize_key((string) $branch_state);
        $is_option_fallback = $branch_state === 'shared_option_fallback_exact_match';
        $field_name = isset($field['name']) ? sanitize_key((string) $field['name']) : '';
        $field_key = isset($field['key']) ? sanitize_key((string) $field['key']) : '';
        $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        $target_post_type = sanitize_key((string) $target_post_type);
        $query_element_id = isset($candidate['query_element_id']) ? sanitize_text_field((string) $candidate['query_element_id']) : '';
        $query_element_label = isset($candidate['query_element_label']) ? sanitize_text_field((string) $candidate['query_element_label']) : '';
        $query_section_label = isset($candidate['query_section_label']) ? sanitize_text_field((string) $candidate['query_section_label']) : '';
        $query_badge_subject = isset($candidate['query_badge_subject']) ? sanitize_text_field((string) $candidate['query_badge_subject']) : '';
        $entity_id = isset($page_context['entityId']) ? absint($page_context['entityId']) : 0;
        $post_type = isset($page_context['postType']) ? sanitize_key((string) $page_context['postType']) : '';
        $label = isset($field['label']) && (string) $field['label'] !== ''
            ? sanitize_text_field((string) $field['label'])
            : __('Query loop posts', 'dbvc');
        $scope = $is_option_fallback ? 'shared_entity' : 'current_entity';
        $entity = $is_option_fallback
            ? [
                'type' => 'option',
                'id' => 0,
                'subtype' => 'option',
                'acf_object_id' => 'option',
            ]
            : [
                'type' => 'post',
                'id' => $entity_id,
                'subtype' => $post_type,
                'acf_object_id' => $entity_id,
            ];
        $query_target_post_type_label = $this->resolvePostTypeBadgeLabel($target_post_type);
        $stored_ids = isset($field['_dbvc_stored_ids']) && is_array($field['_dbvc_stored_ids'])
            ? array_values(array_filter(array_map('absint', $field['_dbvc_stored_ids'])))
            : [];
        $preserved_ids = $is_option_fallback && ! empty($stored_ids)
            ? array_values(array_diff($stored_ids, $query_ids))
            : [];

        return [
            'status' => 'readonly',
            'scope' => $scope,
            'entity' => $entity,
            'loop' => [],
            'repeater' => [],
            'flexible' => [],
            'source' => [
                'type' => $is_option_fallback ? 'acf_collection_field' : 'derived_query_collection',
                'source_context' => $is_option_fallback ? 'shared_option_fallback' : 'query_editor_unmatched',
                'expression' => 'query.derived:' . $query_element_id,
                'expression_args' => [],
                'field_name' => $field_name,
                'field_key' => $field_key,
                'field_selector' => $field_name,
                'leaf_field_name' => $field_name,
                'leaf_field_key' => $field_key,
                'field_type' => $field_type,
                'return_format' => isset($field['return_format']) ? sanitize_key((string) $field['return_format']) : 'value',
                'reference_post_types' => isset($field['post_type']) ? $this->normalizeStringList($field['post_type']) : [],
                'reference_taxonomies' => isset($field['taxonomy']) ? $this->normalizeStringList($field['taxonomy']) : [],
                'reference_multiple' => ! empty($field) ? $this->isReferenceMultiple($field) : true,
                'reference_min' => ! empty($field) ? $this->resolveReferenceMinSelections($field) : 0,
                'reference_max' => ! empty($field) ? $this->resolveReferenceMaxSelections($field) : 0,
                'query_source' => 'derived_bricks_query',
                'query_source_confidence' => $is_option_fallback ? 'exact_shared_option_fallback' : 'unmatched_query_editor_post_in',
                'query_branch_state' => $branch_state,
                'query_element_id' => $query_element_id,
                'query_element_label' => $query_element_label,
                'query_section_label' => $query_section_label,
                'query_badge_subject' => $query_badge_subject,
                'query_target_post_type' => $target_post_type,
                'query_target_post_type_label' => $query_target_post_type_label,
                'query_result_post_types' => $query_result_post_types,
                'query_result_ids' => $query_ids,
                'query_full_value_ids' => ! empty($stored_ids) ? $stored_ids : $query_ids,
                'query_preserved_ids' => $preserved_ids,
                'query_subset_write_mode' => '',
                'query_collection_write_mode' => 'inspect_only',
                'query_id_source' => isset($query_vars['post__in_source']) ? sanitize_key((string) $query_vars['post__in_source']) : '',
                'query_id_setting_source' => isset($query_vars['post__in_setting_source']) ? sanitize_key((string) $query_vars['post__in_setting_source']) : '',
                'query_id_setting_key' => isset($query_vars['post__in_setting_key']) ? sanitize_key((string) $query_vars['post__in_setting_key']) : '',
                'query_dynamic_tags' => isset($query_vars['post__in_dynamic_tags']) && is_array($query_vars['post__in_dynamic_tags']) ? array_values(array_map('sanitize_text_field', $query_vars['post__in_dynamic_tags'])) : [],
                'query_dynamic_field_hints' => $query_dynamic_field_hints,
                'query_editor_field_hints' => $query_editor_field_hints,
                'query_editor_option_field_hints' => $query_editor_option_field_hints,
                'query_editor_explicit_field_hints' => $query_editor_explicit_field_hints,
                'query_editor_active' => true,
                'query_vars' => [
                    'source' => isset($query_vars['source']) ? sanitize_text_field((string) $query_vars['source']) : '',
                    'post_type' => isset($query_vars['post_type']) ? sanitize_key((string) $query_vars['post_type']) : '',
                    'orderby' => isset($query_vars['orderby']) ? sanitize_key((string) $query_vars['orderby']) : '',
                    'posts_per_page' => isset($query_vars['posts_per_page']) && is_numeric($query_vars['posts_per_page']) ? (int) $query_vars['posts_per_page'] : 0,
                    'paged' => isset($query_vars['paged']) && is_numeric($query_vars['paged']) ? max(1, absint($query_vars['paged'])) : 1,
                    'disable_query_merge' => ! empty($query_vars['disable_query_merge']),
                    'query_editor_active' => true,
                    'query_editor_field_hints' => $query_editor_field_hints,
                    'query_editor_option_field_hints' => $query_editor_option_field_hints,
                    'query_editor_explicit_field_hints' => $query_editor_explicit_field_hints,
                ],
                'container_type' => '',
                'parent_field_name' => '',
                'parent_field_key' => '',
                'parent_field_selector' => '',
                'row_index' => null,
                'layout_key' => '',
                'layout_name' => '',
                'container_ancestry' => [],
                'group_path' => [],
                'group_key_path' => [],
                'nested_repeater_path' => [],
                'is_nested_group' => false,
                'is_grouped_field' => false,
                'native_query_active' => false,
                'native_query_kind' => '',
                'native_query_selector' => '',
                'native_query_object_type' => '',
                'native_query_field_name' => '',
                'native_query_field_type' => '',
                'parent_native_query_active' => false,
                'parent_native_query_kind' => '',
                'parent_native_query_selector' => '',
                'parent_native_query_object_type' => '',
                'parent_native_query_field_name' => '',
                'parent_native_query_field_type' => '',
                'native_query_ancestry' => [],
            ],
            'resolver' => [
                'name' => 'native_readonly',
                'version' => 1,
            ],
            'ui' => [
                'label' => $label,
                'badgeLabel' => $is_option_fallback
                    ? $this->buildDerivedBricksQueryBadgeLabel($query_badge_subject, $target_post_type, $query_element_label)
                    : __('Inspect Query Posts', 'dbvc'),
                'input' => 'readonly_preview',
                'warning' => $this->buildDerivedBricksQueryReadonlyWarning($branch_state),
                'allowMultiple' => true,
                'maxSelections' => 0,
            ],
        ];
    }

    /**
     * @param string $branch_state
     * @return string
     */
    private function buildDerivedBricksQueryReadonlyWarning($branch_state)
    {
        $branch_state = sanitize_key((string) $branch_state);

        if ($branch_state === 'shared_option_fallback_exact_match') {
            return __('This Bricks query loop is currently using an ACF options connected-items fallback. It is inspect-only until shared-option fallback editing has explicit warnings, save contracts, stale-subset checks, and journal coverage.', 'dbvc');
        }

        return __('This Bricks Query Editor loop resolved to a post__in list, but Visual Editor could not prove one writable current-owner ACF connected-items field for it. It is inspect-only so the rendered query can be reviewed without risking an incorrect write.', 'dbvc');
    }

    /**
     * @param string $subject
     * @param string $target_post_type
     * @param string $element_label
     * @return string
     */
    private function buildDerivedBricksQueryBadgeLabel($subject, $target_post_type = '', $element_label = '')
    {
        $post_type_label = $this->resolvePostTypeBadgeLabel($target_post_type);
        if ($post_type_label !== '') {
            return $this->formatPostTypeBadgeLabel($post_type_label, $target_post_type);
        }

        $element_label = sanitize_text_field((string) $element_label);
        $element_label = preg_replace('/\s+/', ' ', trim($element_label));
        $subject = sanitize_text_field((string) $subject);
        $subject = preg_replace('/\s+/', ' ', trim($subject));
        $fallback_subject = is_string($element_label) && $element_label !== ''
            ? $element_label
            : $subject;

        if (! is_string($fallback_subject) || $fallback_subject === '') {
            return __('Manage Linked Posts', 'dbvc');
        }

        if (preg_match('/\bposts?\b$/i', $fallback_subject)) {
            return sprintf(
                /* translators: %s: Bricks section or loop element label. */
                __('Manage %s', 'dbvc'),
                $fallback_subject
            );
        }

        return sprintf(
            /* translators: %s: Bricks section or loop element label. */
            __('Manage %s Posts', 'dbvc'),
            $fallback_subject
        );
    }

    /**
     * @param string $post_type
     * @return string
     */
    private function resolvePostTypeBadgeLabel($post_type)
    {
        $post_type = sanitize_key((string) $post_type);
        if ($post_type === '') {
            return '';
        }

        if ($post_type === 'post') {
            return __('Post', 'dbvc');
        }

        $post_type_object = get_post_type_object($post_type);
        if ($post_type_object && ! empty($post_type_object->labels->singular_name)) {
            return sanitize_text_field((string) $post_type_object->labels->singular_name);
        }

        $label = preg_replace('/[_-]+/', ' ', $post_type);
        $label = is_string($label) ? preg_replace('/\s+/', ' ', trim($label)) : '';

        return is_string($label) && $label !== '' ? ucwords($label) : '';
    }

    /**
     * @param string $post_type_label
     * @param string $post_type
     * @return string
     */
    private function formatPostTypeBadgeLabel($post_type_label, $post_type)
    {
        $post_type_label = sanitize_text_field((string) $post_type_label);
        $post_type_label = preg_replace('/\s+/', ' ', trim($post_type_label));
        $post_type = sanitize_key((string) $post_type);

        if (! is_string($post_type_label) || $post_type_label === '') {
            return __('Linked Posts', 'dbvc');
        }

        if ($post_type === 'post') {
            return __('Posts', 'dbvc');
        }

        if (preg_match('/\bposts?\b$/i', $post_type_label)) {
            return $post_type_label;
        }

        return sprintf(
            /* translators: %s: post type label. */
            __('%s Posts', 'dbvc'),
            $post_type_label
        );
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function normalizeReferenceIdList($value)
    {
        $values = is_array($value) ? $value : [$value];
        $ids = [];

        foreach ($values as $item) {
            if (is_object($item) && isset($item->ID)) {
                $id = absint($item->ID);
            } elseif (is_array($item) && isset($item['ID'])) {
                $id = absint($item['ID']);
            } elseif (is_array($item) && isset($item['id'])) {
                $id = absint($item['id']);
            } else {
                $id = absint($item);
            }

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param array<int, int> $ids
     * @param string          $post_type
     * @return array<int, int>
     */
    private function filterReferenceIdsByPostType(array $ids, $post_type)
    {
        $post_type = sanitize_key((string) $post_type);
        if ($post_type === '') {
            return [];
        }

        $filtered = [];
        foreach ($ids as $id) {
            $id = absint($id);
            if ($id > 0 && get_post_type($id) === $post_type) {
                $filtered[] = $id;
            }
        }

        return array_values($filtered);
    }

    /**
     * @param array<int, string> $allowed_post_types
     * @param string             $target_post_type
     * @param array<int, string> $query_result_post_types
     * @return bool
     */
    private function fieldAllowsQueryPostTypes(array $allowed_post_types, $target_post_type, array $query_result_post_types)
    {
        $allowed_post_types = array_values(array_filter(array_map('sanitize_key', $allowed_post_types)));
        if (empty($allowed_post_types)) {
            return true;
        }

        $target_post_type = sanitize_key((string) $target_post_type);
        if ($target_post_type !== '') {
            return in_array($target_post_type, $allowed_post_types, true);
        }

        $query_result_post_types = array_values(array_filter(array_map('sanitize_key', $query_result_post_types)));
        if (empty($query_result_post_types)) {
            return false;
        }

        return empty(array_diff($query_result_post_types, $allowed_post_types));
    }

    /**
     * @param bool $is_full_collection_query
     * @param bool $has_source_hints
     * @return string
     */
    private function resolveDerivedQuerySourceConfidence($is_full_collection_query, $has_source_hints)
    {
        if ($is_full_collection_query) {
            return $has_source_hints
                ? 'source_hinted_full_current_owner_collection'
                : 'exact_current_owner_full_collection';
        }

        return $has_source_hints
            ? 'source_hinted_target_subset'
            : 'exact_current_owner_target_subset';
    }

    /**
     * @param string $target_post_type
     * @param bool   $is_full_collection_query
     * @return string
     */
    private function buildDerivedBricksQueryCollectionWarning($target_post_type, $is_full_collection_query = false)
    {
        if ($is_full_collection_query) {
            return __('This Bricks query loop edits the full current page ACF connected-items field because the rendered query exactly matches that field\'s stored order.', 'dbvc');
        }

        $target_post_type = sanitize_key((string) $target_post_type);

        return sprintf(
            /* translators: %s: queried post type slug */
            __('This Bricks query loop edits only the `%s` posts inside the current page\'s ACF connected-items field. Other linked items in that field are preserved.', 'dbvc'),
            $target_post_type !== '' ? $target_post_type : 'post'
        );
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
     * @param array<string, mixed> $repeater_context
     * @param array<string, mixed> $flexible_context
     * @return string
     */
    private function buildCollectionFieldWarning($scope = 'current_entity', array $loop_context = [], array $repeater_context = [], array $flexible_context = [])
    {
        $warnings = [];

        if ($scope === 'related_entity' && $this->loops->supportsRelatedPostEditing($loop_context)) {
            $warnings[] = __('This query loop is driven by a related post ACF connected-items field. Saving here updates that related post and reloads the page so Bricks can rebuild the loop markup cleanly.', 'dbvc');
        } else {
            $warnings[] = __('This query loop is driven by an ACF connected-items field. Saving here updates the stored connected item list and reloads the page so Bricks can rebuild the loop markup cleanly.', 'dbvc');
        }

        if (! empty($repeater_context['supported'])) {
            $parent_field_name = isset($repeater_context['parent_field_name']) ? sanitize_key((string) $repeater_context['parent_field_name']) : '';
            $row_index = isset($repeater_context['row_index']) ? absint($repeater_context['row_index']) : 0;

            if ($parent_field_name !== '') {
                $warnings[] = sprintf(
                    /* translators: 1: repeater field name, 2: row number */
                    __('This connected-items field is being edited through repeater `%1$s`, row %2$d.', 'dbvc'),
                    $parent_field_name,
                    $row_index + 1
                );
            }
        }

        if (! empty($flexible_context['supported'])) {
            $parent_field_name = isset($flexible_context['parent_field_name']) ? sanitize_key((string) $flexible_context['parent_field_name']) : '';
            $row_index = isset($flexible_context['row_index']) ? absint($flexible_context['row_index']) : 0;
            $layout_name = isset($flexible_context['layout_name']) ? sanitize_key((string) $flexible_context['layout_name']) : '';

            if ($parent_field_name !== '') {
                $warnings[] = sprintf(
                    /* translators: 1: flexible field name, 2: row number, 3: layout slug */
                    __('This connected-items field is being edited through flexible content `%1$s`, row %2$d, layout `%3$s`.', 'dbvc'),
                    $parent_field_name,
                    $row_index + 1,
                    $layout_name !== '' ? $layout_name : 'unknown'
                );
            }
        }

        return implode(' ', $warnings);
    }

    /**
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $loop_context
     * @return array<string, mixed>
     */
    private function resolveCollectionFieldEntity(array $page_context, array $loop_context)
    {
        if ($this->loops->supportsRelatedPostEditing($loop_context)) {
            $owner = isset($loop_context['effective_owner_entity']) && is_array($loop_context['effective_owner_entity'])
                ? $loop_context['effective_owner_entity']
                : (isset($loop_context['owner_entity']) && is_array($loop_context['owner_entity']) ? $loop_context['owner_entity'] : []);

            if (! empty($owner) && ($owner['type'] ?? '') === 'post' && absint($owner['id'] ?? 0) > 0) {
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
    private function resolveCollectionFieldScope(array $entity, $page_entity_id, array $loop_context)
    {
        $entity_id = isset($entity['id']) ? absint($entity['id']) : 0;

        if ($entity_id > 0 && $entity_id === $page_entity_id) {
            return 'current_entity';
        }

        return $this->loops->supportsRelatedPostEditing($loop_context) ? 'related_entity' : 'current_entity';
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
            return 'media_gallery_reference';
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
        $scope = isset($resolved['scope']) ? (string) $resolved['scope'] : 'current_entity';

        if ($scope === 'related_entity') {
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
        } elseif ($entity_type === 'term' && $scope !== 'current_entity') {
            $warnings[] = __('This field resolves to a shared taxonomy term target rather than the current post. Saving here affects any view using that term field.', 'dbvc');
        } elseif ($entity_type === 'user' && $scope !== 'current_entity') {
            $warnings[] = __('This field resolves to a shared user profile target rather than the current post. Saving here affects any view using that user field.', 'dbvc');
        } elseif ($entity_type === 'post' && $scope === 'shared_entity') {
            $warnings[] = __('This field resolves to a shared non-current post target rather than the current page. Saving here updates that shared post anywhere this field is reused.', 'dbvc');
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
        } elseif ($reason === 'archive_context_pending') {
            $warnings[] = __('Archive Visual Editor support is currently inspect-only. This field is surfaced so its archive owner and source path can be verified before saves are enabled.', 'dbvc');
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
        } elseif ($reason === 'flexible_shared_post_owner') {
            $warnings[] = __('This flexible-content subfield belongs to a shared non-current post outside the current loop-owned slice. It is surfaced here for inspection only until that post-owner contract is enabled.', 'dbvc');
        } elseif ($reason === 'flexible_pending') {
            $warnings[] = __('This flexible-content subfield is surfaced here for inspection only. Flexible mutation is currently limited to text-like, WYSIWYG, choice, link, and image subfields with stable row identity.', 'dbvc');
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
     * @return array<string, mixed>
     */
    private function resolveAcfFieldGroupContext(array $field)
    {
        $group_key = $this->resolveAcfFieldGroupKey($field);
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
            'option_pages' => $this->extractAcfFieldGroupOptionPages($group),
        ];
    }

    /**
     * @param array<string, mixed> $field
     * @return string
     */
    private function resolveAcfFieldGroupKey(array $field)
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
    private function extractAcfFieldGroupOptionPages(array $group)
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

                $slugs[] = preg_replace('/^acf-options-/', '', $value);
            }
        }

        return array_values(array_unique(array_filter(array_map('sanitize_key', $slugs))));
    }

    /**
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $resolved
     * @param array<string, mixed> $field_group_context
     * @return string
     */
    private function resolveAcfSourceContext(array $page_context, array $resolved, array $field_group_context)
    {
        unset($field_group_context);

        $entity = isset($resolved['entity']) && is_array($resolved['entity']) ? $resolved['entity'] : [];
        $entity_type = isset($entity['type']) ? sanitize_key((string) $entity['type']) : '';

        if ($entity_type === 'option') {
            return ! empty($page_context['isArchive']) ? 'archive_option' : 'shared_option';
        }

        if ($entity_type === 'term' && ! empty($page_context['isTaxonomyArchive']) && (string) ($resolved['scope'] ?? '') === 'current_entity') {
            return 'archive_term';
        }

        if (! empty($page_context['isArchive']) && (string) ($resolved['scope'] ?? '') === 'related_entity') {
            $loop = isset($resolved['loop']) && is_array($resolved['loop']) ? $resolved['loop'] : [];
            if (! empty($loop['active']) && ! empty($loop['has_concrete_owner'])) {
                return $entity_type !== '' ? 'archive_loop_' . $entity_type : 'archive_loop_owner';
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $field
     * @param array<string, mixed> $resolved
     * @param string               $render_context
     * @return string
     */
    private function resolveArchiveAcfReadonlyReason(array $page_context, array $field, array $resolved, $render_context = '')
    {
        if ($this->supportsTaxonomyArchiveTermAcfSave($page_context, $field, $resolved)
            || $this->supportsArchiveOptionAcfSave($page_context, $field, $resolved)
            || $this->supportsArchiveLoopOwnedAcfSave($page_context, $resolved)) {
            return $this->resolveAcfReadonlyReason($field, $resolved, $render_context);
        }

        return 'archive_context_pending';
    }

    /**
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $field
     * @param array<string, mixed> $resolved
     * @return bool
     */
    private function supportsTaxonomyArchiveTermAcfSave(array $page_context, array $field, array $resolved)
    {
        if (empty($page_context['isTaxonomyArchive'])) {
            return false;
        }

        $page_term_id = isset($page_context['entityId']) ? absint($page_context['entityId']) : 0;
        $page_taxonomy = isset($page_context['taxonomy']) ? sanitize_key((string) $page_context['taxonomy']) : '';
        if ($page_term_id <= 0 || $page_taxonomy === '') {
            return false;
        }

        if ((string) ($resolved['scope'] ?? '') !== 'current_entity') {
            return false;
        }

        $entity = isset($resolved['entity']) && is_array($resolved['entity']) ? $resolved['entity'] : [];
        if ((string) ($entity['type'] ?? '') !== 'term') {
            return false;
        }

        $entity_id = isset($entity['id']) ? absint($entity['id']) : 0;
        $entity_taxonomy = isset($entity['subtype']) ? sanitize_key((string) $entity['subtype']) : '';
        if ($entity_id !== $page_term_id || $entity_taxonomy !== $page_taxonomy) {
            return false;
        }

        $acf_object_id = isset($entity['acf_object_id']) ? sanitize_text_field((string) $entity['acf_object_id']) : '';
        if (! in_array($acf_object_id, [$page_taxonomy . '_' . $page_term_id, 'term_' . $page_term_id], true)) {
            return false;
        }

        $loop = isset($resolved['loop']) && is_array($resolved['loop']) ? $resolved['loop'] : [];
        if (! empty($loop['active'])) {
            return false;
        }

        $repeater = isset($resolved['repeater']) && is_array($resolved['repeater']) ? $resolved['repeater'] : [];
        $flexible = isset($resolved['flexible']) && is_array($resolved['flexible']) ? $resolved['flexible'] : [];
        if (! empty($repeater['supported']) || ! empty($flexible['supported'])) {
            return false;
        }

        $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        return $this->isEditableTaxonomyArchiveTermAcfFieldType($field_type);
    }

    /**
     * @param string $field_type
     * @return bool
     */
    private function isEditableTaxonomyArchiveTermAcfFieldType($field_type)
    {
        return in_array(
            $field_type,
            ['text', 'textarea', 'url', 'email', 'number', 'range', 'wysiwyg', 'checkbox', 'select', 'radio', 'button_group', 'link', 'image'],
            true
        );
    }

    /**
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $field
     * @param array<string, mixed> $resolved
     * @return bool
     */
    private function supportsArchiveOptionAcfSave(array $page_context, array $field, array $resolved)
    {
        if (empty($page_context['isArchive']) || (empty($page_context['isPostTypeArchive']) && empty($page_context['isTaxonomyArchive']))) {
            return false;
        }

        if ((string) ($resolved['scope'] ?? '') !== 'shared_entity') {
            return false;
        }

        $entity = isset($resolved['entity']) && is_array($resolved['entity']) ? $resolved['entity'] : [];
        if ((string) ($entity['type'] ?? '') !== 'option') {
            return false;
        }

        $acf_object_id = isset($entity['acf_object_id']) ? sanitize_text_field((string) $entity['acf_object_id']) : '';
        if ($acf_object_id === '') {
            return false;
        }

        $loop = isset($resolved['loop']) && is_array($resolved['loop']) ? $resolved['loop'] : [];
        if (! empty($loop['active'])) {
            return false;
        }

        $repeater = isset($resolved['repeater']) && is_array($resolved['repeater']) ? $resolved['repeater'] : [];
        $flexible = isset($resolved['flexible']) && is_array($resolved['flexible']) ? $resolved['flexible'] : [];
        if (! empty($repeater['supported']) || ! empty($flexible['supported'])) {
            return false;
        }

        $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        return $this->isEditableArchiveOptionAcfFieldType($field_type);
    }

    /**
     * @param string $field_type
     * @return bool
     */
    private function isEditableArchiveOptionAcfFieldType($field_type)
    {
        return in_array(
            $field_type,
            ['text', 'textarea', 'url', 'email', 'number', 'range', 'wysiwyg', 'checkbox', 'select', 'radio', 'button_group', 'link', 'image'],
            true
        );
    }

    /**
     * @param array<string, mixed> $page_context
     * @param array<string, mixed> $resolved
     * @return bool
     */
    private function supportsArchiveLoopOwnedAcfSave(array $page_context, array $resolved)
    {
        if (empty($page_context['isArchive']) || (string) ($resolved['scope'] ?? '') !== 'related_entity') {
            return false;
        }

        $loop = isset($resolved['loop']) && is_array($resolved['loop']) ? $resolved['loop'] : [];
        if (empty($loop['active']) || empty($loop['has_concrete_owner']) || empty($loop['supports_loop_owned_editing'])) {
            return false;
        }

        $entity = isset($resolved['entity']) && is_array($resolved['entity']) ? $resolved['entity'] : [];
        $entity_type = isset($entity['type']) ? sanitize_key((string) $entity['type']) : '';
        $entity_id = isset($entity['id']) ? absint($entity['id']) : 0;
        $owner_type = isset($loop['effective_owner_type']) ? sanitize_key((string) $loop['effective_owner_type']) : '';
        $owner_id = isset($loop['effective_owner_id']) ? absint($loop['effective_owner_id']) : 0;

        if ($entity_type === '' || $entity_id <= 0 || $owner_type === '' || $owner_id <= 0) {
            return false;
        }

        if ($entity_type !== $owner_type || $entity_id !== $owner_id) {
            return false;
        }

        if ($entity_type === 'term') {
            $entity_taxonomy = isset($entity['taxonomy'])
                ? sanitize_key((string) $entity['taxonomy'])
                : (isset($entity['subtype']) ? sanitize_key((string) $entity['subtype']) : '');
            $owner_taxonomy = isset($loop['effective_owner_subtype']) ? sanitize_key((string) $loop['effective_owner_subtype']) : '';

            return $entity_taxonomy !== '' && $entity_taxonomy === $owner_taxonomy;
        }

        return in_array($entity_type, ['post', 'user'], true);
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
            $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';

            if (! $this->isEditableFlexibleFieldType($field_type)) {
                return 'flexible_pending';
            }
        }

        $loop = isset($resolved['loop']) && is_array($resolved['loop']) ? $resolved['loop'] : [];
        if (! empty($loop['active']) && ! empty($loop['has_concrete_owner']) && empty($loop['supports_loop_owned_editing'])) {
            return 'loop_owned_readonly';
        }

        $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        if ($field_type === 'gallery' && $render_context !== 'gallery_collection') {
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
            ['text', 'textarea', 'url', 'email', 'number', 'range', 'wysiwyg', 'checkbox', 'select', 'radio', 'button_group', 'link', 'image', 'gallery'],
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
     * @param array<string, mixed> $field
     * @return int
     */
    private function resolveReferenceMaxSelections(array $field)
    {
        $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';

        if ($field_type === 'relationship') {
            if (isset($field['max']) && is_numeric($field['max'])) {
                return max(0, absint($field['max']));
            }

            return 0;
        }

        if ($field_type === 'post_object') {
            if (! empty($field['multiple'])) {
                if (isset($field['max']) && is_numeric($field['max'])) {
                    return max(0, absint($field['max']));
                }

                return 0;
            }

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
     * @param array<string, mixed> $loop_context
     * @param int                  $entity_id
     * @return bool
     */
    private function isCurrentOwnerCollectionLoopContext(array $loop_context, $entity_id)
    {
        $entity_id = absint($entity_id);
        if ($entity_id <= 0 || empty($loop_context['active'])) {
            return true;
        }

        $owner = isset($loop_context['effective_owner_entity']) && is_array($loop_context['effective_owner_entity'])
            ? $loop_context['effective_owner_entity']
            : [];

        if (empty($owner)) {
            return true;
        }

        return ($owner['type'] ?? '') === 'post'
            && absint($owner['id'] ?? 0) === $entity_id;
    }

    /**
     * @param array<string, mixed> $native_query
     * @param array<string, mixed> $field
     * @param array<string, mixed> $loop_context
     * @param array<string, mixed> $entity
     * @return array<string, mixed>
     */
    private function resolveCurrentOwnerNestedCollectionContext(array $native_query, array $field, array $loop_context, array $entity)
    {
        if (empty($loop_context['active'])) {
            return [];
        }

        $field_path = $this->normalizeNativeQueryPath(isset($native_query['path']) && is_array($native_query['path']) ? $native_query['path'] : []);
        $container_query = isset($loop_context['native_acf_query']) && is_array($loop_context['native_acf_query'])
            ? $loop_context['native_acf_query']
            : [];
        $container_path = $this->normalizeNativeQueryPath(isset($container_query['path']) && is_array($container_query['path']) ? $container_query['path'] : []);

        if (empty($field_path) || empty($container_path) || ! $this->pathStartsWith($field_path, $container_path)) {
            return [];
        }

        $container_chain = $this->buildCurrentOwnerCollectionContainerChain($loop_context, $entity);
        if (! empty($container_chain) && count($container_chain) > 1) {
            $collection_context = $this->buildCurrentOwnerNestedCollectionChainContext($container_chain, $loop_context, $field_path);

            if (! empty($collection_context)) {
                return $this->mergeCollectionGroupContext($collection_context, $field_path, $container_path, $entity);
            }
        }

        $container_kind = isset($container_query['kind']) ? sanitize_key((string) $container_query['kind']) : '';
        if ($container_kind === 'repeater') {
            $collection_context = $this->buildCurrentOwnerRepeaterCollectionContext($loop_context, $field_path);

            return $this->mergeCollectionGroupContext($collection_context, $field_path, $container_path, $entity);
        }

        if ($container_kind === 'flexible_content') {
            $collection_context = $this->buildCurrentOwnerFlexibleCollectionContext($field, $loop_context, $entity, $field_path);

            return $this->mergeCollectionGroupContext($collection_context, $field_path, $container_path, $entity);
        }

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $container_chain
     * @param array<string, mixed>             $loop_context
     * @param array<int, string>               $field_path
     * @return array<string, mixed>
     */
    private function buildCurrentOwnerNestedCollectionChainContext(array $container_chain, array $loop_context, array $field_path)
    {
        if (empty($container_chain)) {
            return [];
        }

        $active_segment = $container_chain[count($container_chain) - 1];
        $active_path = isset($active_segment['path']) && is_array($active_segment['path'])
            ? $this->normalizeNativeQueryPath($active_segment['path'])
            : [];

        if (empty($active_path) || ! $this->pathStartsWith($field_path, $active_path)) {
            return [];
        }

        $root_segment = $container_chain[0];
        $root_type = isset($root_segment['type']) ? sanitize_key((string) $root_segment['type']) : '';
        $root_field_name = isset($root_segment['field_name']) ? sanitize_key((string) $root_segment['field_name']) : '';
        $root_field_key = isset($root_segment['field_key']) ? sanitize_key((string) $root_segment['field_key']) : '';
        $root_field_selector = isset($root_segment['field_selector']) ? sanitize_key((string) $root_segment['field_selector']) : '';
        $root_row_index = isset($root_segment['row_index']) && $root_segment['row_index'] !== null && is_numeric($root_segment['row_index'])
            ? absint($root_segment['row_index'])
            : null;
        $root_layout_name = isset($root_segment['layout_name']) ? sanitize_key((string) $root_segment['layout_name']) : '';
        $root_layout_key = isset($root_segment['layout_key']) ? sanitize_key((string) $root_segment['layout_key']) : '';

        if ($root_type === '' || ($root_field_name === '' && $root_field_key === '' && $root_field_selector === '') || $root_row_index === null) {
            return [];
        }

        $source = [
            'container_type' => $root_type,
            'parent_field_name' => $root_field_name,
            'parent_field_key' => $root_field_key,
            'parent_field_selector' => $root_field_selector,
            'row_index' => $root_row_index,
            'layout_key' => $root_type === 'flexible_content' ? $root_layout_key : '',
            'layout_name' => $root_type === 'flexible_content' ? $root_layout_name : '',
            'container_ancestry' => $this->normalizeCollectionContainerAncestry($container_chain),
            'group_path' => [],
            'group_key_path' => [],
            'nested_repeater_path' => $this->buildCollectionNestedRepeaterPath($container_chain),
            'is_nested_group' => false,
            'is_grouped_field' => false,
        ];

        return [
            'loop' => $this->loops->export($loop_context),
            'repeater' => $root_type === 'repeater'
                ? [
                    'supported' => true,
                    'parent_field_name' => $root_field_name,
                    'parent_field_key' => $root_field_key,
                    'parent_field_selector' => $root_field_selector,
                    'row_index' => $root_row_index,
                ]
                : [],
            'flexible' => $root_type === 'flexible_content'
                ? [
                    'supported' => true,
                    'parent_field_name' => $root_field_name,
                    'parent_field_key' => $root_field_key,
                    'parent_field_selector' => $root_field_selector,
                    'row_index' => $root_row_index,
                    'layout_key' => $root_layout_key,
                    'layout_name' => $root_layout_name,
                ]
                : [],
            'source' => $source,
        ];
    }

    /**
     * @param array<string, mixed> $loop_context
     * @param array<int, string>   $field_path
     * @return array<string, mixed>
     */
    private function buildCurrentOwnerRepeaterCollectionContext(array $loop_context, array $field_path)
    {
        if ($this->hasNativeAncestorKind($loop_context, 'flexible_content')) {
            return [];
        }

        $repeater_chain = $this->buildNativeRepeaterLoopChain($loop_context);
        if (empty($repeater_chain)) {
            return [];
        }

        $active_segment = $repeater_chain[count($repeater_chain) - 1];
        $active_path = isset($active_segment['path']) && is_array($active_segment['path'])
            ? $this->normalizeNativeQueryPath($active_segment['path'])
            : [];

        if (empty($active_path) || ! $this->pathStartsWith($field_path, $active_path)) {
            return [];
        }

        $root_segment = $repeater_chain[0];
        $nested_segments = [];

        foreach (array_slice($repeater_chain, 1) as $segment) {
            $nested_segments[] = [
                'field_name' => isset($segment['field_name']) ? sanitize_key((string) $segment['field_name']) : '',
                'field_key' => isset($segment['field_key']) ? sanitize_key((string) $segment['field_key']) : '',
                'field_selector' => isset($segment['field_selector']) ? sanitize_key((string) $segment['field_selector']) : '',
                'row_index' => isset($segment['row_index']) && $segment['row_index'] !== null && is_numeric($segment['row_index'])
                    ? absint($segment['row_index'])
                    : null,
            ];
        }

        if (! $this->nestedRepeaterSegmentsHaveStableIndexes($nested_segments)) {
            return [];
        }

        $row_index = isset($root_segment['row_index']) && $root_segment['row_index'] !== null && is_numeric($root_segment['row_index'])
            ? absint($root_segment['row_index'])
            : null;

        if ($row_index === null) {
            return [];
        }

        return [
            'loop' => $this->loops->export($loop_context),
            'repeater' => [
                'supported' => true,
                'parent_field_name' => isset($root_segment['field_name']) ? sanitize_key((string) $root_segment['field_name']) : '',
                'parent_field_key' => isset($root_segment['field_key']) ? sanitize_key((string) $root_segment['field_key']) : '',
                'parent_field_selector' => isset($root_segment['field_selector']) ? sanitize_key((string) $root_segment['field_selector']) : '',
                'row_index' => $row_index,
            ],
            'flexible' => [],
            'source' => [
                'container_type' => 'repeater',
                'parent_field_name' => isset($root_segment['field_name']) ? sanitize_key((string) $root_segment['field_name']) : '',
                'parent_field_key' => isset($root_segment['field_key']) ? sanitize_key((string) $root_segment['field_key']) : '',
                'parent_field_selector' => isset($root_segment['field_selector']) ? sanitize_key((string) $root_segment['field_selector']) : '',
                'row_index' => $row_index,
                'layout_key' => '',
                'layout_name' => '',
                'container_ancestry' => $this->normalizeCollectionContainerAncestry($repeater_chain),
                'group_path' => [],
                'group_key_path' => [],
                'nested_repeater_path' => $nested_segments,
                'is_nested_group' => false,
                'is_grouped_field' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $loop_context
     * @param array<string, mixed> $entity
     * @param array<int, string>   $field_path
     * @return array<string, mixed>
     */
    private function buildCurrentOwnerFlexibleCollectionContext(array $field, array $loop_context, array $entity, array $field_path)
    {
        unset($field);

        if ($this->hasNativeAncestorKind($loop_context, 'repeater')) {
            return [];
        }

        $container_query = isset($loop_context['native_acf_query']) && is_array($loop_context['native_acf_query'])
            ? $loop_context['native_acf_query']
            : [];
        $container_path = $this->normalizeNativeQueryPath(isset($container_query['path']) && is_array($container_query['path']) ? $container_query['path'] : []);
        if (empty($container_path) || ! $this->pathStartsWith($field_path, $container_path)) {
            return [];
        }

        $row_index = isset($loop_context['loop_index']) && is_numeric($loop_context['loop_index'])
            ? absint($loop_context['loop_index'])
            : null;
        $parent_field_name = isset($container_query['fieldName']) ? sanitize_key((string) $container_query['fieldName']) : '';
        $parent_field_key = isset($container_query['fieldKey']) ? sanitize_key((string) $container_query['fieldKey']) : '';
        $parent_field_selector = isset($container_query['selector']) ? sanitize_key((string) $container_query['selector']) : $parent_field_name;

        if ($row_index === null || $parent_field_selector === '') {
            return [];
        }

        $layout_context = $this->resolveFlexibleCollectionLayoutContext($loop_context, $entity);
        $layout_name = isset($layout_context['layout_name']) ? sanitize_key((string) $layout_context['layout_name']) : '';
        $layout_key = isset($layout_context['layout_key']) ? sanitize_key((string) $layout_context['layout_key']) : '';

        if ($layout_name === '' || ! in_array($layout_name, $field_path, true)) {
            return [];
        }

        return [
            'loop' => $this->loops->export($loop_context),
            'repeater' => [],
            'flexible' => [
                'supported' => true,
                'parent_field_name' => $parent_field_name,
                'parent_field_key' => $parent_field_key,
                'parent_field_selector' => $parent_field_selector,
                'row_index' => $row_index,
                'layout_key' => $layout_key,
                'layout_name' => $layout_name,
            ],
            'source' => [
                'container_type' => 'flexible_content',
                'parent_field_name' => $parent_field_name,
                'parent_field_key' => $parent_field_key,
                'parent_field_selector' => $parent_field_selector,
                'row_index' => $row_index,
                'layout_key' => $layout_key,
                'layout_name' => $layout_name,
                'container_ancestry' => [
                    [
                        'type' => 'flexible_content',
                        'field_name' => $parent_field_name,
                        'field_key' => $parent_field_key,
                        'field_selector' => $parent_field_selector,
                        'row_index' => $row_index,
                        'layout_key' => $layout_key,
                        'layout_name' => $layout_name,
                    ],
                ],
                'group_path' => [],
                'group_key_path' => [],
                'nested_repeater_path' => [],
                'is_nested_group' => false,
                'is_grouped_field' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $loop_context
     * @param array<string, mixed> $entity
     * @return array<string, string>
     */
    private function resolveFlexibleCollectionLayoutContext(array $loop_context, array $entity)
    {
        if (! function_exists('get_field')) {
            return [];
        }

        $container_query = isset($loop_context['native_acf_query']) && is_array($loop_context['native_acf_query'])
            ? $loop_context['native_acf_query']
            : [];
        $query_object_type = isset($container_query['objectType']) ? sanitize_key((string) $container_query['objectType']) : '';
        $parent_field_selector = isset($container_query['selector']) ? sanitize_key((string) $container_query['selector']) : '';
        $row_index = isset($loop_context['loop_index']) && is_numeric($loop_context['loop_index'])
            ? absint($loop_context['loop_index'])
            : null;
        $acf_object_id = isset($entity['acf_object_id']) ? sanitize_text_field((string) $entity['acf_object_id']) : '';

        if ($query_object_type === '' || $parent_field_selector === '' || $row_index === null || $acf_object_id === '') {
            return [];
        }

        $container_field = $this->native_acf_queries->resolveFieldDefinitionForQuery($query_object_type, $entity);
        if (empty($container_field) || sanitize_key((string) ($container_field['type'] ?? '')) !== 'flexible_content') {
            return [];
        }

        $rows = get_field($parent_field_selector, $acf_object_id, false);
        if (! is_array($rows) || ! isset($rows[$row_index]) || ! is_array($rows[$row_index])) {
            return [];
        }

        $row = $rows[$row_index];
        $layout_name = isset($row['acf_fc_layout']) ? sanitize_key((string) $row['acf_fc_layout']) : '';
        if ($layout_name === '') {
            return [];
        }

        return [
            'layout_name' => $layout_name,
            'layout_key' => $this->resolveFlexibleLayoutKeyByName($container_field, $layout_name),
        ];
    }

    /**
     * @param array<string, mixed> $loop_context
     * @param string               $kind
     * @return bool
     */
    private function hasNativeAncestorKind(array $loop_context, $kind)
    {
        $kind = sanitize_key((string) $kind);
        if ($kind === '') {
            return false;
        }

        $ancestors = isset($loop_context['ancestors']) && is_array($loop_context['ancestors']) ? $loop_context['ancestors'] : [];
        foreach ($ancestors as $ancestor) {
            if (! is_array($ancestor)) {
                continue;
            }

            $native_query = isset($ancestor['native_acf_query']) && is_array($ancestor['native_acf_query'])
                ? $ancestor['native_acf_query']
                : [];

            if (sanitize_key((string) ($native_query['kind'] ?? '')) === $kind) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $loop_context
     * @return array<int, array<string, mixed>>
     */
    private function buildNativeRepeaterLoopChain(array $loop_context)
    {
        $contexts = [];

        if (! empty($loop_context['ancestors']) && is_array($loop_context['ancestors'])) {
            foreach (array_reverse($loop_context['ancestors']) as $ancestor_context) {
                if (is_array($ancestor_context) && ! empty($ancestor_context)) {
                    $contexts[] = $ancestor_context;
                }
            }
        }

        $contexts[] = $loop_context;
        $segments = [];

        foreach ($contexts as $context) {
            $native_query = isset($context['native_acf_query']) && is_array($context['native_acf_query']) ? $context['native_acf_query'] : [];
            if (($native_query['kind'] ?? '') !== 'repeater') {
                continue;
            }

            $segments[] = [
                'field_name' => isset($native_query['fieldName']) ? sanitize_key((string) $native_query['fieldName']) : '',
                'field_key' => isset($native_query['fieldKey']) ? sanitize_key((string) $native_query['fieldKey']) : '',
                'field_selector' => isset($native_query['selector']) ? sanitize_key((string) $native_query['selector']) : '',
                'row_index' => isset($context['loop_index']) && is_numeric($context['loop_index']) ? absint($context['loop_index']) : null,
                'path' => isset($native_query['path']) && is_array($native_query['path']) ? $native_query['path'] : [],
            ];
        }

        return $segments;
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     * @return bool
     */
    private function nestedRepeaterSegmentsHaveStableIndexes(array $segments)
    {
        foreach ($segments as $segment) {
            if (! isset($segment['row_index']) || $segment['row_index'] === null || ! is_numeric($segment['row_index'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, mixed> $path
     * @return array<int, string>
     */
    private function normalizeNativeQueryPath(array $path)
    {
        return array_values(
            array_filter(
                array_map(
                    static function ($segment) {
                        return sanitize_key((string) $segment);
                    },
                    $path
                )
            )
        );
    }

    /**
     * @param array<int, string> $path
     * @param array<int, string> $prefix
     * @return bool
     */
    private function pathStartsWith(array $path, array $prefix)
    {
        if (empty($prefix) || count($path) < count($prefix)) {
            return false;
        }

        foreach ($prefix as $index => $segment) {
            if (! isset($path[$index]) || $path[$index] !== $segment) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $container_field
     * @param string               $layout_name
     * @return string
     */
    private function resolveFlexibleLayoutKeyByName(array $container_field, $layout_name)
    {
        $layout_name = sanitize_key((string) $layout_name);
        if ($layout_name === '' || empty($container_field['layouts']) || ! is_array($container_field['layouts'])) {
            return '';
        }

        foreach ($container_field['layouts'] as $layout) {
            if (! is_array($layout)) {
                continue;
            }

            if (sanitize_key((string) ($layout['name'] ?? '')) !== $layout_name) {
                continue;
            }

            return sanitize_key((string) ($layout['key'] ?? ''));
        }

        return '';
    }

    /**
     * @param array<string, mixed> $loop_context
     * @param array<string, mixed> $entity
     * @return array<int, array<string, mixed>>
     */
    private function buildCurrentOwnerCollectionContainerChain(array $loop_context, array $entity)
    {
        $contexts = [];

        if (! empty($loop_context['ancestors']) && is_array($loop_context['ancestors'])) {
            foreach (array_reverse($loop_context['ancestors']) as $ancestor_context) {
                if (is_array($ancestor_context) && ! empty($ancestor_context)) {
                    $contexts[] = $ancestor_context;
                }
            }
        }

        $contexts[] = $loop_context;
        $segments = [];

        foreach ($contexts as $context) {
            $native_query = isset($context['native_acf_query']) && is_array($context['native_acf_query']) ? $context['native_acf_query'] : [];
            $kind = isset($native_query['kind']) ? sanitize_key((string) $native_query['kind']) : '';

            if (! in_array($kind, ['repeater', 'flexible_content'], true)) {
                continue;
            }

            $field_name = isset($native_query['fieldName']) ? sanitize_key((string) $native_query['fieldName']) : '';
            $field_key = isset($native_query['fieldKey']) ? sanitize_key((string) $native_query['fieldKey']) : '';
            $field_selector = isset($native_query['selector']) ? sanitize_key((string) $native_query['selector']) : '';
            $row_index = isset($context['loop_index']) && is_numeric($context['loop_index'])
                ? absint($context['loop_index'])
                : null;

            if (($field_name === '' && $field_key === '' && $field_selector === '') || $row_index === null) {
                return [];
            }

            $layout_context = $kind === 'flexible_content'
                ? $this->resolveFlexibleCollectionLayoutContext($context, $entity)
                : [];

            $segments[] = [
                'type' => $kind,
                'field_name' => $field_name,
                'field_key' => $field_key,
                'field_selector' => $field_selector !== '' ? $field_selector : $field_name,
                'row_index' => $row_index,
                'layout_name' => isset($layout_context['layout_name']) ? sanitize_key((string) $layout_context['layout_name']) : '',
                'layout_key' => isset($layout_context['layout_key']) ? sanitize_key((string) $layout_context['layout_key']) : '',
                'path' => isset($native_query['path']) && is_array($native_query['path']) ? $native_query['path'] : [],
            ];
        }

        return $segments;
    }

    /**
     * @param array<int, array<string, mixed>> $container_chain
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCollectionContainerAncestry(array $container_chain)
    {
        return array_values(
            array_filter(
                array_map(
                    static function ($segment) {
                        if (! is_array($segment)) {
                            return null;
                        }

                        $type = isset($segment['type']) ? sanitize_key((string) $segment['type']) : '';
                        $field_name = isset($segment['field_name']) ? sanitize_key((string) $segment['field_name']) : '';
                        $field_key = isset($segment['field_key']) ? sanitize_key((string) $segment['field_key']) : '';
                        $field_selector = isset($segment['field_selector']) ? sanitize_key((string) $segment['field_selector']) : '';
                        $row_index = isset($segment['row_index']) && $segment['row_index'] !== null && is_numeric($segment['row_index'])
                            ? absint($segment['row_index'])
                            : null;

                        if ($type === '' || ($field_name === '' && $field_key === '' && $field_selector === '') || $row_index === null) {
                            return null;
                        }

                        return [
                            'type' => $type,
                            'field_name' => $field_name,
                            'field_key' => $field_key,
                            'field_selector' => $field_selector,
                            'row_index' => $row_index,
                            'layout_name' => isset($segment['layout_name']) ? sanitize_key((string) $segment['layout_name']) : '',
                            'layout_key' => isset($segment['layout_key']) ? sanitize_key((string) $segment['layout_key']) : '',
                        ];
                    },
                    $container_chain
                )
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $container_chain
     * @return array<int, array<string, mixed>>
     */
    private function buildCollectionNestedRepeaterPath(array $container_chain)
    {
        if (count($container_chain) <= 1) {
            return [];
        }

        $segments = [];

        foreach (array_slice($container_chain, 1) as $segment) {
            if (! is_array($segment) || sanitize_key((string) ($segment['type'] ?? '')) !== 'repeater') {
                return [];
            }

            $field_name = isset($segment['field_name']) ? sanitize_key((string) $segment['field_name']) : '';
            $field_key = isset($segment['field_key']) ? sanitize_key((string) $segment['field_key']) : '';
            $field_selector = isset($segment['field_selector']) ? sanitize_key((string) $segment['field_selector']) : '';
            $row_index = isset($segment['row_index']) && $segment['row_index'] !== null && is_numeric($segment['row_index'])
                ? absint($segment['row_index'])
                : null;

            if ($field_name === '' && $field_key === '' && $field_selector === '') {
                return [];
            }

            $segments[] = [
                'field_name' => $field_name,
                'field_key' => $field_key,
                'field_selector' => $field_selector,
                'row_index' => $row_index,
            ];
        }

        return $segments;
    }

    /**
     * @param array<string, mixed> $collection_context
     * @param array<int, string>   $field_path
     * @param array<int, string>   $container_path
     * @param array<string, mixed> $entity
     * @return array<string, mixed>
     */
    private function mergeCollectionGroupContext(array $collection_context, array $field_path, array $container_path, array $entity)
    {
        if (empty($collection_context)) {
            return [];
        }

        $group_context = $this->buildCollectionNestedGroupContext($field_path, $container_path, $entity);
        if (empty($group_context)) {
            return $collection_context;
        }

        $source = isset($collection_context['source']) && is_array($collection_context['source'])
            ? $collection_context['source']
            : [];

        $collection_context['source'] = array_merge($source, $group_context);

        return $collection_context;
    }

    /**
     * @param array<int, string>   $field_path
     * @param array<int, string>   $container_path
     * @param array<string, mixed> $entity
     * @return array<string, mixed>
     */
    private function buildCollectionNestedGroupContext(array $field_path, array $container_path, array $entity)
    {
        $group_depth = count($field_path) - count($container_path) - 1;
        if ($group_depth <= 0) {
            return [];
        }

        $group_path = array_values(array_slice($field_path, count($container_path), $group_depth));
        if (empty($group_path)) {
            return [];
        }

        $group_key_path = [];
        $selector_segments = $container_path;

        foreach ($group_path as $group_name) {
            $selector_segments[] = sanitize_key((string) $group_name);
            $group_field = $this->native_acf_queries->resolveFieldDefinitionForQuery('acf_' . implode('_', $selector_segments), $entity);
            if (! is_array($group_field) || empty($group_field)) {
                continue;
            }

            if (sanitize_key((string) ($group_field['type'] ?? '')) !== 'group') {
                continue;
            }

            $group_key = isset($group_field['key']) ? sanitize_key((string) $group_field['key']) : '';
            if ($group_key !== '') {
                $group_key_path[] = $group_key;
            }
        }

        return [
            'group_path' => $group_path,
            'group_key_path' => $group_key_path,
            'is_nested_group' => ! empty($group_path),
            'is_grouped_field' => ! empty($group_path),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $ancestry
     * @return array<int, array<string, mixed>>
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
                            'active' => true,
                            'kind' => $kind,
                            'selector' => $selector,
                            'objectType' => $object_type,
                            'fieldName' => $field_name,
                            'fieldKey' => $field_key,
                            'fieldType' => $field_type,
                            'queryId' => isset($item['queryId']) ? sanitize_text_field((string) $item['queryId']) : '',
                            'queryElementId' => isset($item['queryElementId']) ? sanitize_text_field((string) $item['queryElementId']) : '',
                            'loopIndex' => $loop_index,
                        ];
                    },
                    $ancestry
                )
            )
        );
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
     * @param bool                 $is_archive_context
     * @return string|null
     */
    private function buildPostFieldWarning($scope, array $loop_context, $status = 'editable', $field_name = '', $render_context = '', $is_archive_context = false)
    {
        $warnings = [];

        if ($is_archive_context && $status !== 'editable') {
            $warnings[] = __('Archive Visual Editor support is currently inspect-only. Save support will be enabled after archive owner contracts are validated.', 'dbvc');
        }

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
            $warnings[] = $is_archive_context
                ? __('This value belongs to the post currently rendered by a Bricks archive query loop. Saving here updates that post, not the archive route.', 'dbvc')
                : __('This value belongs to the related post currently rendered by a Bricks query loop. Saving here updates that related post, not the current page.', 'dbvc');
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

    /**
     * @param array<string, mixed> $tag_object
     * @return array<int, string>
     */
    private function normalizeNestedGroupPath(array $tag_object, $container_root = '')
    {
        $group_path = [];

        if (! empty($tag_object['parent_group_names']) && is_array($tag_object['parent_group_names'])) {
            $group_path = array_values(
                array_filter(
                    array_map(
                        static function ($value) {
                            return sanitize_key((string) $value);
                        },
                        array_reverse($tag_object['parent_group_names'])
                    )
                )
            );
        }

        if (empty($group_path)
            && ! empty($tag_object['parent'])
            && is_array($tag_object['parent'])
            && sanitize_key((string) ($tag_object['parent']['type'] ?? '')) === 'group') {
            $parent_group_name = sanitize_key((string) ($tag_object['parent']['name'] ?? ''));
            if ($parent_group_name !== '') {
                $group_path[] = $parent_group_name;
            }
        }

        $container_root = sanitize_key((string) $container_root);
        if ($container_root !== '' && ! empty($group_path) && $group_path[0] === $container_root) {
            array_shift($group_path);
        }

        return array_values(array_unique($group_path));
    }

    /**
     * @param array<string, mixed> $tag_object
     * @return array<int, string>
     */
    private function normalizeNestedGroupKeyPath(array $tag_object, $container_root_key = '')
    {
        $group_key_path = [];

        if (! empty($tag_object['parent_group_keys']) && is_array($tag_object['parent_group_keys'])) {
            $group_key_path = array_values(
                array_filter(
                    array_map(
                        static function ($value) {
                            return sanitize_key((string) $value);
                        },
                        array_reverse($tag_object['parent_group_keys'])
                    )
                )
            );
        }

        if (empty($group_key_path)
            && ! empty($tag_object['parent'])
            && is_array($tag_object['parent'])
            && sanitize_key((string) ($tag_object['parent']['type'] ?? '')) === 'group') {
            $parent_group_key = sanitize_key((string) ($tag_object['parent']['key'] ?? ''));
            if ($parent_group_key !== '') {
                $group_key_path[] = $parent_group_key;
            }
        }

        $container_root_key = sanitize_key((string) $container_root_key);
        if ($container_root_key !== '' && ! empty($group_key_path) && $group_key_path[0] === $container_root_key) {
            array_shift($group_key_path);
        }

        return array_values(array_unique($group_key_path));
    }

    /**
     * @param array<string, mixed> $repeater_context
     * @return array<int, array<string, mixed>>
     */
    private function normalizeNestedRepeaterPath(array $repeater_context)
    {
        if (empty($repeater_context['nested_repeater_path']) || ! is_array($repeater_context['nested_repeater_path'])) {
            return [];
        }

        $segments = [];

        foreach ($repeater_context['nested_repeater_path'] as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $field_name = isset($segment['field_name']) ? sanitize_key((string) $segment['field_name']) : '';
            $field_key = isset($segment['field_key']) ? sanitize_key((string) $segment['field_key']) : '';
            $field_selector = isset($segment['field_selector']) ? sanitize_key((string) $segment['field_selector']) : '';
            $row_index = isset($segment['row_index']) && $segment['row_index'] !== null && is_numeric($segment['row_index'])
                ? absint($segment['row_index'])
                : null;

            if ($field_name === '' && $field_key === '' && $field_selector === '') {
                continue;
            }

            $segments[] = [
                'field_name' => $field_name,
                'field_key' => $field_key,
                'field_selector' => $field_selector,
                'row_index' => $row_index,
            ];
        }

        return $segments;
    }

    /**
     * @param array<string, mixed> $tag_object
     * @param string               $fallback
     * @return string
     */
    private function resolveAcfFieldSelector(array $tag_object, $fallback)
    {
        $tag_name = isset($tag_object['name']) ? (string) $tag_object['name'] : '';
        if ($tag_name !== '' && preg_match('/^\{acf_(.+)\}$/', $tag_name, $matches)) {
            return sanitize_key((string) $matches[1]);
        }

        return sanitize_key((string) $fallback);
    }
}
