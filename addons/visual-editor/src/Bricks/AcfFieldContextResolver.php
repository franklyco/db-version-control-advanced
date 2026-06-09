<?php

namespace Dbvc\VisualEditor\Bricks;

use Dbvc\VisualEditor\Performance\PerformanceProfiler;

final class AcfFieldContextResolver
{
    /**
     * @var LoopContextResolver
     */
    private $loops;

    /**
     * @var PerformanceProfiler|null
     */
    private $profiler;

    public function __construct(?LoopContextResolver $loops = null, ?PerformanceProfiler $profiler = null)
    {
        $this->profiler = $profiler;
        $this->loops = $loops instanceof LoopContextResolver ? $loops : new LoopContextResolver(null, $profiler);
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $page_context
     * @return array<string, mixed>
     */
    public function resolve(array $candidate, array $page_context)
    {
        if ($this->profiler instanceof PerformanceProfiler && $this->profiler->isEnabled()) {
            $started_at = $this->profiler->startTimer();
            $result = $this->resolveUnprofiled($candidate, $page_context);
            $this->profiler->recordDuration('acf_context.resolve', $started_at, [
                'ok' => empty($result['ok']) ? 'no' : 'yes',
            ]);

            return $result;
        }

        return $this->resolveUnprofiled($candidate, $page_context);
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $page_context
     * @return array<string, mixed>
     */
    private function resolveUnprofiled(array $candidate, array $page_context)
    {
        if (empty($page_context['isSupported'])) {
            return [
                'ok' => false,
                'message' => __('Missing current entity context.', 'dbvc'),
            ];
        }

        $page_post_id = $this->resolvePagePostId($page_context);
        $context_object_id = $this->resolveProviderContextObjectId($page_context);
        $expression = isset($candidate['expression']) ? (string) $candidate['expression'] : '';
        $tag_data = $this->locateTag($expression);
        if (empty($tag_data)) {
            return [
                'ok' => false,
                'message' => __('The Bricks ACF tag could not be resolved.', 'dbvc'),
            ];
        }

        $provider = $tag_data['provider'];
        $tag_object = $tag_data['tag_object'];
        $field_for_context = $this->buildContextField($tag_object);

        if (! method_exists($provider, 'get_object_id')) {
            return [
                'ok' => false,
                'message' => __('The Bricks ACF provider cannot resolve object context.', 'dbvc'),
            ];
        }

        $loop_context = $this->loops->resolve();
        $acf_object_id = $provider->get_object_id($field_for_context, $context_object_id);
        $acf_object_id = $this->normalizeArchiveOptionsAcfObjectId($acf_object_id, $field_for_context, $page_context);
        $acf_object_id = $this->normalizeArchiveAcfObjectId($acf_object_id, $field_for_context, $page_context);
        $acf_object_id = $this->maybeOverrideAcfObjectIdForLoopContext($acf_object_id, $loop_context);
        $tag = isset($tag_data['tag']) ? (string) $tag_data['tag'] : '';
        $repeater_context = $this->buildRepeaterContext($tag_object, $loop_context, $acf_object_id);
        $flexible_context = $this->buildFlexibleContext($tag_object, $tag, $loop_context, $acf_object_id);

        if (! empty($loop_context['active']) && ! $this->supportsLoopContext($loop_context, $repeater_context, $flexible_context)) {
            return [
                'ok' => false,
                'message' => $this->buildLoopContextErrorMessage($repeater_context, $flexible_context),
            ];
        }

        $entity = $this->mapAcfObjectIdToEntity($acf_object_id, $page_post_id);
        if (empty($entity)) {
            return [
                'ok' => false,
                'message' => __('The ACF field target could not be mapped to a safe entity context.', 'dbvc'),
            ];
        }

        $field_name = isset($candidate['field_name']) ? sanitize_key((string) $candidate['field_name']) : '';
        $field = $field_name !== '' && function_exists('get_field_object')
            ? get_field_object($field_name, $acf_object_id, false, false)
            : false;

        if (! is_array($field)) {
            if (empty($repeater_context) && empty($flexible_context) && ! empty($tag_object['duplicate'])) {
                return [
                    'ok' => false,
                    'message' => __('The ACF field is duplicated across groups and could not be resolved safely for this object.', 'dbvc'),
                ];
            }

            $field = isset($tag_object['field']) && is_array($tag_object['field']) ? $tag_object['field'] : [];
        }

        if (empty($field)) {
            return [
                'ok' => false,
                'message' => __('The ACF field definition could not be loaded.', 'dbvc'),
            ];
        }

        $tag_object = $this->rebindContainerScopedTagField(
            $tag_object,
            $repeater_context,
            $flexible_context,
            $acf_object_id
        );

        if (! empty($tag_object['field']) && is_array($tag_object['field'])) {
            $field = $tag_object['field'];
        }

        $entity_type = isset($entity['type']) ? (string) $entity['type'] : '';
        $entity_id = isset($entity['id']) ? $entity['id'] : 0;

        if ($entity_type === 'post' && absint($entity_id) !== $page_post_id && ! $this->entityMatchesLoopOwner($entity, $loop_context)) {
            return [
                'ok' => false,
                'message' => __('Referenced post editing is not enabled in the current MVP slice.', 'dbvc'),
            ];
        }

        return [
            'ok' => true,
            'tag' => isset($tag_data['tag']) ? (string) $tag_data['tag'] : '',
            'tag_args' => isset($tag_data['args']) && is_array($tag_data['args']) ? $tag_data['args'] : [],
            'tag_object' => $tag_object,
            'field' => $field,
            'acf_object_id' => $acf_object_id,
            'entity' => $entity,
            'scope' => $this->resolveScope($entity, $page_context, $loop_context),
            'loop' => $this->loops->export($loop_context),
            'repeater' => $repeater_context,
            'flexible' => $flexible_context,
        ];
    }

    /**
     * @param array<string, mixed> $page_context
     * @return int
     */
    private function resolvePagePostId(array $page_context)
    {
        $entity_type = isset($page_context['entityType']) ? sanitize_key((string) $page_context['entityType']) : '';

        return $entity_type === 'post' && isset($page_context['entityId'])
            ? absint($page_context['entityId'])
            : 0;
    }

    /**
     * @param array<string, mixed> $page_context
     * @return int
     */
    private function resolveProviderContextObjectId(array $page_context)
    {
        $entity_type = isset($page_context['entityType']) ? sanitize_key((string) $page_context['entityType']) : '';

        if (in_array($entity_type, ['post', 'term'], true) && isset($page_context['entityId'])) {
            return absint($page_context['entityId']);
        }

        return 0;
    }

    /**
     * @param string $expression
     * @return array<string, mixed>
     */
    private function locateTag($expression)
    {
        $provider = $this->getProvider('acf');
        if (! $provider || ! method_exists($provider, 'get_tags')) {
            return [];
        }

        $parsed = $this->parseExpression($expression);
        $tag = isset($parsed['tag']) ? sanitize_key((string) $parsed['tag']) : '';
        if ($tag === '') {
            return [];
        }

        $tags = $provider->get_tags();
        if (! is_array($tags) || ! isset($tags[$tag]) || ! is_array($tags[$tag])) {
            return [];
        }

        return [
            'provider' => $provider,
            'tag' => $tag,
            'args' => isset($parsed['args']) && is_array($parsed['args']) ? $parsed['args'] : [],
            'tag_object' => $tags[$tag],
        ];
    }

    /**
     * @param string $provider_name
     * @return object|null
     */
    private function getProvider($provider_name)
    {
        if (! class_exists('\\Bricks\\Integrations\\Dynamic_Data\\Providers')
            || ! method_exists('\\Bricks\\Integrations\\Dynamic_Data\\Providers', 'get_registered_provider')) {
            return null;
        }

        $provider = \Bricks\Integrations\Dynamic_Data\Providers::get_registered_provider((string) $provider_name);

        return is_object($provider) ? $provider : null;
    }

    /**
     * @param string $expression
     * @return array<string, mixed>
     */
    private function parseExpression($expression)
    {
        $expression = trim((string) $expression);
        $inner = preg_replace('/^\{|\}$/', '', $expression);
        $inner = is_string($inner) ? trim($inner) : '';

        if ($inner === '') {
            return [
                'tag' => '',
                'args' => [],
            ];
        }

        if (class_exists('\\Bricks\\Integrations\\Dynamic_Data\\Dynamic_Data_Parser')) {
            $parser = new \Bricks\Integrations\Dynamic_Data\Dynamic_Data_Parser();
            $parsed = $parser->parse($inner);

            return [
                'tag' => isset($parsed['tag']) ? (string) $parsed['tag'] : '',
                'args' => isset($parsed['args']) && is_array($parsed['args']) ? $parsed['args'] : [],
            ];
        }

        $parts = explode(':', $inner);

        return [
            'tag' => isset($parts[0]) ? (string) $parts[0] : '',
            'args' => array_slice($parts, 1),
        ];
    }

    /**
     * @param array<string, mixed> $tag_object
     * @return array<string, mixed>
     */
    private function buildContextField(array $tag_object)
    {
        $field = isset($tag_object['field']) && is_array($tag_object['field']) ? $tag_object['field'] : [];

        if (! empty($tag_object['_bricks_locations']) && is_array($tag_object['_bricks_locations'])) {
            $field['_bricks_locations'] = $tag_object['_bricks_locations'];
        } elseif (! empty($tag_object['parent']['_bricks_locations']) && is_array($tag_object['parent']['_bricks_locations'])) {
            $field['_bricks_locations'] = $tag_object['parent']['_bricks_locations'];
        }

        return $field;
    }

    /**
     * @param array<string, mixed> $tag_object
     * @param array<string, mixed> $repeater_context
     * @param array<string, mixed> $flexible_context
     * @param mixed                $acf_object_id
     * @return array<string, mixed>
     */
    private function rebindContainerScopedTagField(array $tag_object, array $repeater_context, array $flexible_context, $acf_object_id)
    {
        if (! function_exists('get_field_object')) {
            return $tag_object;
        }

        $container_context = ! empty($repeater_context['supported'])
            ? $repeater_context
            : (! empty($flexible_context['supported']) ? $flexible_context : []);

        if (empty($container_context)) {
            return $tag_object;
        }

        $container_field = $this->resolveContainerFieldDefinition($container_context, $acf_object_id);
        if (empty($container_field)) {
            return $tag_object;
        }

        $leaf_name = isset($tag_object['field']['name']) ? sanitize_key((string) $tag_object['field']['name']) : '';
        $leaf_key = isset($tag_object['field']['key']) ? sanitize_key((string) $tag_object['field']['key']) : '';
        $container_root = isset($container_context['parent_field_name']) ? sanitize_key((string) $container_context['parent_field_name']) : '';
        $group_path = $this->normalizeTagGroupPath($tag_object, $container_root);
        $resolved_field = $this->findContainerSubFieldDefinition($container_field, $container_context, $leaf_name, $leaf_key, $group_path);

        if (empty($resolved_field)) {
            return $tag_object;
        }

        $existing_field = isset($tag_object['field']) && is_array($tag_object['field']) ? $tag_object['field'] : [];
        $tag_object['field'] = array_merge($existing_field, $resolved_field);

        if (! empty($repeater_context['parent_field_key'])) {
            $tag_object['field']['parent_repeater'] = sanitize_key((string) $repeater_context['parent_field_key']);
        }

        if (! empty($flexible_context['layout_key'])) {
            $tag_object['field']['parent_layout'] = sanitize_key((string) $flexible_context['layout_key']);
        }

        return $tag_object;
    }

    /**
     * @param mixed $acf_object_id
     * @param int   $page_post_id
     * @return array<string, mixed>
     */
    private function mapAcfObjectIdToEntity($acf_object_id, $page_post_id)
    {
        $options_context = $this->resolveOptionsObjectContext($acf_object_id);
        if (! empty($options_context)) {
            return [
                'type' => 'option',
                'id' => 'option',
                'subtype' => 'option',
                'acf_object_id' => isset($options_context['acf_object_id']) ? (string) $options_context['acf_object_id'] : 'option',
                'option_page_slug' => isset($options_context['option_page_slug']) ? sanitize_key((string) $options_context['option_page_slug']) : '',
                'option_page_label' => isset($options_context['option_page_label']) ? sanitize_text_field((string) $options_context['option_page_label']) : '',
            ];
        }

        if (is_numeric($acf_object_id)) {
            $post_id = absint($acf_object_id);
            if ($post_id <= 0) {
                return [];
            }

            return [
                'type' => 'post',
                'id' => $post_id,
                'subtype' => $post_id === $page_post_id ? (string) get_post_type($post_id) : (string) get_post_type($post_id),
                'acf_object_id' => $post_id,
            ];
        }

        $acf_object_id = (string) $acf_object_id;

        if (preg_match('/^term_(\d+)$/', $acf_object_id, $matches)) {
            $term_id = absint($matches[1]);
            $term = $term_id > 0 ? get_term($term_id) : null;
            if ($term && ! is_wp_error($term) && ! empty($term->taxonomy)) {
                $taxonomy = sanitize_key((string) $term->taxonomy);

                return [
                    'type' => 'term',
                    'id' => $term_id,
                    'subtype' => $taxonomy,
                    'taxonomy' => $taxonomy,
                    'acf_object_id' => $acf_object_id,
                ];
            }
        }

        if (preg_match('/^user_(\d+)$/', $acf_object_id, $matches)) {
            return [
                'type' => 'user',
                'id' => absint($matches[1]),
                'subtype' => 'user',
                'acf_object_id' => $acf_object_id,
            ];
        }

        if (preg_match('/^([A-Za-z0-9_-]+)_(\d+)$/', $acf_object_id, $matches)) {
            $taxonomy = sanitize_key((string) $matches[1]);
            $term_id = absint($matches[2]);

            if ($taxonomy !== '' && $term_id > 0 && taxonomy_exists($taxonomy)) {
                return [
                    'type' => 'term',
                    'id' => $term_id,
                    'subtype' => $taxonomy,
                    'taxonomy' => $taxonomy,
                    'acf_object_id' => $acf_object_id,
                ];
            }
        }

        return [];
    }

    /**
     * @param mixed $acf_object_id
     * @return array<string, string>
     */
    private function resolveOptionsObjectContext($acf_object_id)
    {
        if (! is_scalar($acf_object_id)) {
            return [];
        }

        $raw_object_id = trim((string) $acf_object_id);
        if ($raw_object_id === '') {
            return [];
        }

        if ($raw_object_id === 'option' || $raw_object_id === 'options') {
            return [
                'acf_object_id' => $raw_object_id === 'options' ? 'options' : 'option',
                'option_page_slug' => '',
                'option_page_label' => '',
            ];
        }

        $pages = $this->getAcfOptionsPages();
        foreach ($pages as $slug => $page) {
            if (! is_array($page)) {
                continue;
            }

            $candidates = array_values(
                array_unique(
                    array_filter(
                        [
                            isset($page['post_id']) ? (string) $page['post_id'] : '',
                            $slug !== '' ? 'options_' . $slug : '',
                            $slug !== '' ? 'acf-options-' . $slug : '',
                            $slug,
                        ]
                    )
                )
            );

            if (in_array($raw_object_id, $candidates, true)) {
                return [
                    'acf_object_id' => $raw_object_id,
                    'option_page_slug' => sanitize_key((string) $slug),
                    'option_page_label' => $this->resolveOptionsPageLabel($slug, $page),
                ];
            }
        }

        if (strpos($raw_object_id, 'options_') === 0) {
            $slug = sanitize_key(substr($raw_object_id, 8));
            if ($slug !== '') {
                return [
                    'acf_object_id' => $raw_object_id,
                    'option_page_slug' => $slug,
                    'option_page_label' => ucwords(str_replace(['-', '_'], ' ', $slug)),
                ];
            }
        }

        if (strpos($raw_object_id, 'acf-options-') === 0) {
            $slug = sanitize_key(substr($raw_object_id, 12));
            if ($slug !== '') {
                return [
                    'acf_object_id' => $raw_object_id,
                    'option_page_slug' => $slug,
                    'option_page_label' => ucwords(str_replace(['-', '_'], ' ', $slug)),
                ];
            }
        }

        return [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getAcfOptionsPages()
    {
        if (! function_exists('acf_get_options_pages')) {
            return [];
        }

        $pages_raw = acf_get_options_pages();
        if (! is_array($pages_raw)) {
            return [];
        }

        $pages = [];
        foreach ($pages_raw as $page) {
            if (! is_array($page)) {
                continue;
            }

            $slug = '';
            if (! empty($page['menu_slug'])) {
                $slug = (string) $page['menu_slug'];
            } elseif (! empty($page['slug'])) {
                $slug = (string) $page['slug'];
            }

            $slug = preg_replace('/^acf-options-/', '', sanitize_key($slug));
            if (! is_string($slug) || $slug === '') {
                continue;
            }

            $pages[$slug] = $page;
        }

        return $pages;
    }

    /**
     * @param string               $slug
     * @param array<string, mixed> $page
     * @return string
     */
    private function resolveOptionsPageLabel($slug, array $page)
    {
        if (! empty($page['menu_title'])) {
            return sanitize_text_field((string) $page['menu_title']);
        }

        if (! empty($page['page_title'])) {
            return sanitize_text_field((string) $page['page_title']);
        }

        return ucwords(str_replace(['-', '_'], ' ', sanitize_key((string) $slug)));
    }

    /**
     * @param mixed                $acf_object_id
     * @param array<string, mixed> $field_for_context
     * @param array<string, mixed> $page_context
     * @return mixed
     */
    private function normalizeArchiveAcfObjectId($acf_object_id, array $field_for_context, array $page_context)
    {
        if (empty($page_context['isTaxonomyArchive'])) {
            return $acf_object_id;
        }

        $term_id = isset($page_context['entityId']) ? absint($page_context['entityId']) : 0;
        $taxonomy = isset($page_context['taxonomy']) ? sanitize_key((string) $page_context['taxonomy']) : '';
        if ($term_id <= 0 || $taxonomy === '' || ! $this->fieldHasBricksLocation($field_for_context, 'term')) {
            return $acf_object_id;
        }

        if ((is_numeric($acf_object_id) && absint($acf_object_id) === $term_id) || absint($acf_object_id) === 0) {
            return $taxonomy . '_' . $term_id;
        }

        return $acf_object_id;
    }

    /**
     * @param mixed                $acf_object_id
     * @param array<string, mixed> $field_for_context
     * @param array<string, mixed> $page_context
     * @return mixed
     */
    private function normalizeArchiveOptionsAcfObjectId($acf_object_id, array $field_for_context, array $page_context)
    {
        if (empty($page_context['isArchive']) || ! $this->fieldHasOptionsPageLocation($field_for_context)) {
            return $acf_object_id;
        }

        if (! empty($this->resolveOptionsObjectContext($acf_object_id))) {
            return $acf_object_id;
        }

        return 'option';
    }

    /**
     * @param array<string, mixed> $field
     * @param string               $location
     * @return bool
     */
    private function fieldHasBricksLocation(array $field, $location)
    {
        $location = sanitize_key((string) $location);
        $locations = isset($field['_bricks_locations']) && is_array($field['_bricks_locations'])
            ? $field['_bricks_locations']
            : [];

        foreach ($locations as $field_location) {
            if (sanitize_key((string) $field_location) === $location) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $field
     * @return bool
     */
    private function fieldHasOptionsPageLocation(array $field)
    {
        $group_key = $this->resolveAcfFieldGroupKey($field);
        if ($group_key === '' || ! function_exists('acf_get_field_group')) {
            return false;
        }

        $group = acf_get_field_group($group_key);
        if (! is_array($group) || empty($group['location']) || ! is_array($group['location'])) {
            return false;
        }

        foreach ($group['location'] as $rules) {
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

                if (in_array($param, ['options_page', 'options_page_key'], true) && in_array($operator, ['==', '==='], true) && $value !== '') {
                    return true;
                }
            }
        }

        return false;
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
     * @param mixed               $acf_object_id
     * @param array<string, mixed> $loop_context
     * @return mixed
     */
    private function maybeOverrideAcfObjectIdForLoopContext($acf_object_id, array $loop_context)
    {
        if (! $this->loops->hasConcreteOwner($loop_context)) {
            return $acf_object_id;
        }

        $owner = isset($loop_context['effective_owner_entity']) && is_array($loop_context['effective_owner_entity'])
            ? $loop_context['effective_owner_entity']
            : (isset($loop_context['owner_entity']) && is_array($loop_context['owner_entity']) ? $loop_context['owner_entity'] : []);
        $owner_object_id = $owner['acf_object_id'] ?? '';

        if ($owner_object_id === '') {
            return $acf_object_id;
        }

        return $owner_object_id;
    }

    /**
     * @param array<string, mixed> $entity
     * @param array<string, mixed> $loop_context
     * @return bool
     */
    private function entityMatchesLoopOwner(array $entity, array $loop_context)
    {
        $owner = isset($loop_context['effective_owner_entity']) && is_array($loop_context['effective_owner_entity'])
            ? $loop_context['effective_owner_entity']
            : (isset($loop_context['owner_entity']) && is_array($loop_context['owner_entity']) ? $loop_context['owner_entity'] : []);

        return ! empty($owner)
            && isset($entity['type'], $entity['id'], $owner['type'], $owner['id'])
            && (string) $entity['type'] === (string) $owner['type']
            && (string) $entity['id'] === (string) $owner['id'];
    }

    /**
     * @param array<string, mixed> $entity
     * @param array<string, mixed> $loop_context
     * @return string
     */
    private function resolveScope(array $entity, array $page_context, array $loop_context)
    {
        if ($this->entityMatchesPageContext($entity, $page_context)) {
            return 'current_entity';
        }

        if ($this->entityMatchesLoopOwner($entity, $loop_context)) {
            return 'related_entity';
        }

        return 'shared_entity';
    }

    /**
     * @param array<string, mixed> $entity
     * @param array<string, mixed> $page_context
     * @return bool
     */
    private function entityMatchesPageContext(array $entity, array $page_context)
    {
        $entity_type = isset($entity['type']) ? sanitize_key((string) $entity['type']) : '';
        $page_entity_type = isset($page_context['entityType']) ? sanitize_key((string) $page_context['entityType']) : '';
        $entity_id = isset($entity['id']) ? absint($entity['id']) : 0;
        $page_entity_id = isset($page_context['entityId']) ? absint($page_context['entityId']) : 0;

        if ($entity_type === '' || $entity_id <= 0 || $page_entity_type === '' || $page_entity_id <= 0) {
            return false;
        }

        if ($entity_type !== $page_entity_type) {
            return false;
        }

        if ($entity_type === 'term') {
            $taxonomy = isset($entity['taxonomy']) ? sanitize_key((string) $entity['taxonomy']) : (isset($entity['subtype']) ? sanitize_key((string) $entity['subtype']) : '');
            $page_taxonomy = isset($page_context['taxonomy']) ? sanitize_key((string) $page_context['taxonomy']) : '';

            return $taxonomy !== '' && $taxonomy === $page_taxonomy && $entity_id === $page_entity_id;
        }

        return $entity_id === $page_entity_id;
    }

    /**
     * @param array<string, mixed> $loop_context
     * @return bool
     */
    private function supportsLoopContext(array $loop_context, array $repeater_context = [], array $flexible_context = [])
    {
        if (empty($loop_context['active'])) {
            return empty($repeater_context) && empty($flexible_context);
        }

        if (! empty($repeater_context)) {
            return ! empty($repeater_context['supported']);
        }

        if (! empty($flexible_context)) {
            return ! empty($flexible_context['supported']);
        }

        return $this->loops->hasConcreteOwner($loop_context);
    }

    /**
     * @param array<string, mixed> $tag_object
     * @param array<string, mixed> $loop_context
     * @return array<string, mixed>
     */
    private function buildRepeaterContext(array $tag_object, array $loop_context, $acf_object_id = '')
    {
        $field = isset($tag_object['field']) && is_array($tag_object['field']) ? $tag_object['field'] : [];
        $parent = isset($tag_object['parent']) && is_array($tag_object['parent']) ? $tag_object['parent'] : [];
        $parent_type = isset($parent['type']) ? sanitize_key((string) $parent['type']) : '';
        $parent_name = isset($parent['name']) ? sanitize_key((string) $parent['name']) : '';
        $parent_repeater_key = sanitize_key((string) ($field['parent_repeater'] ?? ''));

        $row_index = null;
        if (isset($loop_context['loop_index']) && is_numeric($loop_context['loop_index'])) {
            $row_index = absint($loop_context['loop_index']);
        }

        $query_object_type = isset($loop_context['query_object_type']) ? sanitize_key((string) $loop_context['query_object_type']) : '';
        $native_query = isset($loop_context['native_acf_query']) && is_array($loop_context['native_acf_query']) ? $loop_context['native_acf_query'] : [];

        if ($parent_type === 'repeater') {
            $parent_key = isset($parent['key']) ? sanitize_key((string) $parent['key']) : $parent_repeater_key;
            $parent_selector = $this->resolveNativeQueryFieldSelector($native_query, $parent_name, $parent_key);
            $context = [
                'active' => true,
                'supported' => ! empty($loop_context['active'])
                && $parent_name !== ''
                && $parent_key !== ''
                && $row_index !== null
                && (
                    $this->queryObjectTypeMatchesContainer($query_object_type, $parent_name)
                    || $this->nativeQueryMatchesRepeaterContainer($native_query, $parent_name, $parent_key)
                ),
                'parent_field_name' => $parent_name,
                'parent_field_key' => $parent_key,
                'parent_field_selector' => $parent_selector,
                'parent_field_type' => 'repeater',
                'row_index' => $row_index,
                'query_object_type' => $query_object_type,
                'subfield_name' => isset($field['name']) ? sanitize_key((string) $field['name']) : '',
                'subfield_key' => isset($field['key']) ? sanitize_key((string) $field['key']) : '',
                'nested_repeater_path' => [],
            ];

            return $this->canonicalizeNestedRepeaterContext($context, $tag_object, $loop_context, $acf_object_id);
        }

        if ($parent_type !== 'group' || ($native_query['kind'] ?? '') !== 'repeater') {
            return [];
        }

        $parent_key = isset($native_query['fieldKey']) ? sanitize_key((string) $native_query['fieldKey']) : '';
        $parent_selector = isset($native_query['selector']) ? sanitize_key((string) $native_query['selector']) : '';
        $resolved_parent_name = isset($native_query['fieldName']) ? sanitize_key((string) $native_query['fieldName']) : '';
        $context = [
            'active' => true,
            'supported' => false,
            'parent_field_name' => $resolved_parent_name !== '' ? $resolved_parent_name : $parent_name,
            'parent_field_key' => $parent_key,
            'parent_field_selector' => $parent_selector !== '' ? $parent_selector : $resolved_parent_name,
            'parent_field_type' => 'repeater',
            'row_index' => $row_index,
            'query_object_type' => $query_object_type,
            'subfield_name' => isset($field['name']) ? sanitize_key((string) $field['name']) : '',
            'subfield_key' => isset($field['key']) ? sanitize_key((string) $field['key']) : '',
            'nested_repeater_path' => [],
        ];
        $context['supported'] = ! empty($loop_context['active'])
            && $row_index !== null
            && $this->containerSupportsTagField($tag_object, $context, $acf_object_id);

        return $this->canonicalizeNestedRepeaterContext($context, $tag_object, $loop_context, $acf_object_id);
    }

    /**
     * Canonicalize nested native repeater loops back to the outer repeater root so
     * the descriptor reads and writes against the actual stored ACF row tree.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $tag_object
     * @param array<string, mixed> $loop_context
     * @param mixed                $acf_object_id
     * @return array<string, mixed>
     */
    private function canonicalizeNestedRepeaterContext(array $context, array $tag_object, array $loop_context, $acf_object_id)
    {
        if (($context['parent_field_type'] ?? '') !== 'repeater' || empty($loop_context['active'])) {
            return $context;
        }

        $repeater_chain = $this->buildNativeRepeaterLoopChain($loop_context);
        if (empty($repeater_chain)) {
            return $context;
        }

        $root_segment = $repeater_chain[0];
        $nested_segments = [];

        foreach (array_slice($repeater_chain, 1) as $segment) {
            $nested_segments[] = [
                'field_name' => isset($segment['field_name']) ? sanitize_key((string) $segment['field_name']) : '',
                'field_key' => isset($segment['field_key']) ? sanitize_key((string) $segment['field_key']) : '',
                'field_selector' => isset($segment['field_selector']) ? sanitize_key((string) $segment['field_selector']) : '',
                'row_index' => isset($segment['row_index']) && $segment['row_index'] !== null ? absint($segment['row_index']) : null,
            ];
        }

        $context['parent_field_name'] = isset($root_segment['field_name']) ? sanitize_key((string) $root_segment['field_name']) : (string) ($context['parent_field_name'] ?? '');
        $context['parent_field_key'] = isset($root_segment['field_key']) ? sanitize_key((string) $root_segment['field_key']) : (string) ($context['parent_field_key'] ?? '');
        $context['parent_field_selector'] = isset($root_segment['field_selector']) ? sanitize_key((string) $root_segment['field_selector']) : (string) ($context['parent_field_selector'] ?? '');
        $context['row_index'] = isset($root_segment['row_index']) && $root_segment['row_index'] !== null
            ? absint($root_segment['row_index'])
            : (isset($context['row_index']) && $context['row_index'] !== null ? absint($context['row_index']) : null);
        $context['nested_repeater_path'] = $nested_segments;
        $context['supported'] = ! empty($loop_context['active'])
            && $context['row_index'] !== null
            && $this->nestedRepeaterSegmentsHaveStableIndexes($nested_segments)
            && $this->containerSupportsTagField($tag_object, $context, $acf_object_id);

        return $context;
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
     * @param array<string, mixed> $tag_object
     * @param string               $tag
     * @param array<string, mixed> $loop_context
     * @return array<string, mixed>
     */
    private function buildFlexibleContext(array $tag_object, $tag, array $loop_context, $acf_object_id = '')
    {
        $field = isset($tag_object['field']) && is_array($tag_object['field']) ? $tag_object['field'] : [];
        $parent = isset($tag_object['parent']) && is_array($tag_object['parent']) ? $tag_object['parent'] : [];
        $parent_type = isset($parent['type']) ? sanitize_key((string) $parent['type']) : '';
        $parent_name = isset($parent['name']) ? sanitize_key((string) $parent['name']) : '';
        $parent_key = isset($parent['key']) ? sanitize_key((string) $parent['key']) : '';
        $layout_key = isset($field['parent_layout']) ? sanitize_key((string) $field['parent_layout']) : '';

        $row_index = null;
        if (isset($loop_context['loop_index']) && is_numeric($loop_context['loop_index'])) {
            $row_index = absint($loop_context['loop_index']);
        }

        $query_object_type = isset($loop_context['query_object_type']) ? sanitize_key((string) $loop_context['query_object_type']) : '';
        $native_query = isset($loop_context['native_acf_query']) && is_array($loop_context['native_acf_query']) ? $loop_context['native_acf_query'] : [];
        $subfield_name = isset($field['name']) ? sanitize_key((string) $field['name']) : '';
        if ($parent_type === 'flexible_content' && $parent_name !== '' && $parent_key !== '') {
            $parent_selector = $this->resolveNativeQueryFieldSelector($native_query, $parent_name, $parent_key);
            $layout_name = $this->inferFlexibleLayoutName($tag, $parent_name, $subfield_name);
            $context = [
                'active' => true,
                'supported' => false,
                'parent_field_name' => $parent_name,
                'parent_field_key' => $parent_key,
                'parent_field_selector' => $parent_selector,
                'parent_field_type' => 'flexible_content',
                'row_index' => $row_index,
                'query_object_type' => $query_object_type,
                'layout_key' => $layout_key,
                'layout_name' => $layout_name,
                'subfield_name' => $subfield_name,
                'subfield_key' => isset($field['key']) ? sanitize_key((string) $field['key']) : '',
            ];

            $context = $this->canonicalizeFlexibleContextFromRow($context, $acf_object_id);
            $context['supported'] = ! empty($loop_context['active'])
                && $row_index !== null
                && ! empty($context['layout_key'])
                && ! empty($context['layout_name'])
                && (
                    $this->queryObjectTypeMatchesFlexible($query_object_type, $parent_name, (string) $context['layout_name'])
                    || $this->nativeQueryMatchesFlexibleContainer($native_query, $parent_name, $parent_key, (string) $context['layout_name'], (string) $context['layout_key'])
                )
                && $this->containerSupportsTagField($tag_object, $context, $acf_object_id);

            return $context;
        }

        if ($parent_type !== 'group' || ($native_query['kind'] ?? '') !== 'flexible_content') {
            return [];
        }

        $resolved_parent_name = isset($native_query['fieldName']) ? sanitize_key((string) $native_query['fieldName']) : '';
        $resolved_parent_key = isset($native_query['fieldKey']) ? sanitize_key((string) $native_query['fieldKey']) : '';
        $parent_selector = isset($native_query['selector']) ? sanitize_key((string) $native_query['selector']) : '';
        $layout_name = $this->inferFlexibleLayoutName($tag, $resolved_parent_name !== '' ? $resolved_parent_name : $parent_name, $subfield_name);
        $context = [
            'active' => true,
            'supported' => false,
            'parent_field_name' => $resolved_parent_name !== '' ? $resolved_parent_name : $parent_name,
            'parent_field_key' => $resolved_parent_key,
            'parent_field_selector' => $parent_selector !== '' ? $parent_selector : $resolved_parent_name,
            'parent_field_type' => 'flexible_content',
            'row_index' => $row_index,
            'query_object_type' => $query_object_type,
            'layout_key' => $layout_key,
            'layout_name' => $layout_name,
            'subfield_name' => $subfield_name,
            'subfield_key' => isset($field['key']) ? sanitize_key((string) $field['key']) : '',
        ];
        $context = $this->canonicalizeFlexibleContextFromRow($context, $acf_object_id);
        $context['supported'] = ! empty($loop_context['active'])
            && $row_index !== null
            && ! empty($context['layout_key'])
            && ! empty($context['layout_name'])
            && $this->containerSupportsTagField($tag_object, $context, $acf_object_id);

        return $context;
    }

    /**
     * Reconcile flexible descendants against the actual row layout.
     *
     * Bricks can emit a duplicate flexible child tag keyed to the wrong layout
     * alias even though the rendered element belongs to a different active row
     * layout. The raw flexible row is the safer source of truth for layout
     * identity, so canonicalize the descriptor context before field matching.
     *
     * @param array<string, mixed> $context
     * @param mixed                $acf_object_id
     * @return array<string, mixed>
     */
    private function canonicalizeFlexibleContextFromRow(array $context, $acf_object_id)
    {
        if (($context['parent_field_type'] ?? '') !== 'flexible_content') {
            return $context;
        }

        if ($acf_object_id === '' || ! isset($context['row_index']) || ! is_numeric($context['row_index'])) {
            return $context;
        }

        $container_field = $this->resolveContainerFieldDefinition($context, $acf_object_id);
        if (empty($container_field)) {
            return $context;
        }

        $rows = $this->loadRawContainerRows($context, $acf_object_id);
        $row_index = absint($context['row_index']);
        $row = isset($rows[$row_index]) && is_array($rows[$row_index]) ? $rows[$row_index] : [];
        $layout_name = isset($row['acf_fc_layout']) ? sanitize_key((string) $row['acf_fc_layout']) : '';

        if ($layout_name === '') {
            return $context;
        }

        $context['layout_name'] = $layout_name;

        $layout_key = $this->resolveFlexibleLayoutKeyByName($container_field, $layout_name);
        if ($layout_key !== '') {
            $context['layout_key'] = $layout_key;
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $tag_object
     * @param array<string, mixed> $container_context
     * @param mixed                $acf_object_id
     * @return bool
     */
    private function containerSupportsTagField(array $tag_object, array $container_context, $acf_object_id)
    {
        if ($acf_object_id === '') {
            return false;
        }

        $container_field = $this->resolveContainerFieldDefinition($container_context, $acf_object_id);
        if (empty($container_field)) {
            return false;
        }

        $leaf_name = isset($tag_object['field']['name']) ? sanitize_key((string) $tag_object['field']['name']) : '';
        $leaf_key = isset($tag_object['field']['key']) ? sanitize_key((string) $tag_object['field']['key']) : '';
        $container_root = isset($container_context['parent_field_name']) ? sanitize_key((string) $container_context['parent_field_name']) : '';
        $group_path = $this->normalizeTagGroupPath($tag_object, $container_root);
        $resolved_field = $this->findContainerSubFieldDefinition($container_field, $container_context, $leaf_name, $leaf_key, $group_path);

        return ! empty($resolved_field);
    }

    /**
     * @param string $query_object_type
     * @param string $parent_name
     * @return bool
     */
    private function queryObjectTypeMatchesContainer($query_object_type, $parent_name)
    {
        $query_object_type = sanitize_key((string) $query_object_type);
        $parent_name = sanitize_key((string) $parent_name);

        if ($query_object_type === '' || $parent_name === '') {
            return false;
        }

        if ($query_object_type === 'acf_' . $parent_name) {
            return true;
        }

        return (bool) preg_match('/(^|_)' . preg_quote($parent_name, '/') . '$/', preg_replace('/^acf_/', '', $query_object_type));
    }

    /**
     * @param string $query_object_type
     * @param string $parent_name
     * @param string $layout_name
     * @return bool
     */
    private function queryObjectTypeMatchesFlexible($query_object_type, $parent_name, $layout_name)
    {
        $query_object_type = sanitize_key((string) $query_object_type);
        $parent_name = sanitize_key((string) $parent_name);
        $layout_name = sanitize_key((string) $layout_name);

        if ($this->queryObjectTypeMatchesContainer($query_object_type, $parent_name)) {
            return true;
        }

        if ($parent_name === '' || $layout_name === '') {
            return false;
        }

        return $this->queryObjectTypeMatchesContainer($query_object_type, $parent_name . '_' . $layout_name);
    }

    /**
     * @param array<string, mixed> $repeater_context
     * @param array<string, mixed> $flexible_context
     * @return string
     */
    private function buildLoopContextErrorMessage(array $repeater_context, array $flexible_context = [])
    {
        if (! empty($repeater_context)) {
            return __('Only Bricks ACF repeater rows with a stable row index and a matching native repeater query context are surfaced in the current Visual Editor slice.', 'dbvc');
        }

        if (! empty($flexible_context)) {
            return __('Only Bricks ACF flexible-content rows with a stable row index, layout identity, and matching native flexible query context are surfaced in the current Visual Editor slice.', 'dbvc');
        }

        return __('Only Bricks native ACF relationship, post-object, taxonomy, or other query loops with a concrete post, term, or user owner are surfaced in the current Visual Editor slice.', 'dbvc');
    }

    /**
     * @param string $tag
     * @param string $parent_name
     * @param string $subfield_name
     * @return string
     */
    private function inferFlexibleLayoutName($tag, $parent_name, $subfield_name)
    {
        $tag = sanitize_key((string) $tag);
        $parent_name = sanitize_key((string) $parent_name);
        $subfield_name = sanitize_key((string) $subfield_name);

        if ($tag === '' || $parent_name === '' || $subfield_name === '') {
            return '';
        }

        $suffix = '_' . $subfield_name;

        if (strpos($tag, 'acf_') !== 0 || substr($tag, -strlen($suffix)) !== $suffix) {
            return '';
        }

        $tag_body = substr($tag, 4, -strlen($suffix));
        if (! is_string($tag_body) || $tag_body === '') {
            return '';
        }

        foreach ($this->buildFlexibleParentNameCandidates($parent_name) as $candidate) {
            $candidate_prefix = $candidate . '_';
            if (strpos($tag_body, $candidate_prefix) !== 0) {
                continue;
            }

            $layout_name = substr($tag_body, strlen($candidate_prefix));
            if (is_string($layout_name) && $layout_name !== '') {
                return sanitize_key((string) $layout_name);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $container_context
     * @param mixed                $acf_object_id
     * @return array<string, mixed>
     */
    private function resolveContainerFieldDefinition(array $container_context, $acf_object_id)
    {
        $identifiers = array_values(
            array_filter(
                array_unique(
                    [
                        isset($container_context['parent_field_selector']) ? sanitize_key((string) $container_context['parent_field_selector']) : '',
                        isset($container_context['parent_field_name']) ? sanitize_key((string) $container_context['parent_field_name']) : '',
                        isset($container_context['parent_field_key']) ? sanitize_key((string) $container_context['parent_field_key']) : '',
                    ]
                )
            )
        );
        $identifiers = $this->expandContainerFieldDefinitionIdentifiers($identifiers);

        foreach ($identifiers as $identifier) {
            $field = get_field_object($identifier, $acf_object_id, false, false);
            if (is_array($field) && ! empty($field)) {
                return $field;
            }
        }

        return [];
    }

    /**
     * ACF clone fields can expose a prefixed runtime field key such as
     * `field_clone_field_original`, while `get_field_object()` only resolves the
     * original cloned field key. Keep the descriptor's selector/key unchanged for
     * reads and writes; this fallback is only for loading subfield definitions.
     *
     * @param array<int, string> $identifiers
     * @return array<int, string>
     */
    private function expandContainerFieldDefinitionIdentifiers(array $identifiers)
    {
        $expanded = [];

        foreach ($identifiers as $identifier) {
            $identifier = sanitize_key((string) $identifier);
            if ($identifier === '') {
                continue;
            }

            $expanded[] = $identifier;

            if (preg_match('/_(field_[a-z0-9]+)$/', $identifier, $matches)) {
                $expanded[] = sanitize_key((string) $matches[1]);
            }
        }

        return array_values(array_unique(array_filter($expanded)));
    }

    /**
     * @param array<string, mixed> $container_field
     * @param array<string, mixed> $container_context
     * @param string               $leaf_name
     * @param string               $leaf_key
     * @param array<int, string>   $group_path
     * @return array<string, mixed>
     */
    private function findContainerSubFieldDefinition(array $container_field, array $container_context, $leaf_name, $leaf_key, array $group_path = [])
    {
        $fields = [];
        $container_type = isset($container_context['parent_field_type']) ? sanitize_key((string) $container_context['parent_field_type']) : '';
        $nested_repeater_path = $this->normalizeNestedRepeaterPath($container_context);

        if ($container_type === 'flexible_content') {
            $fields = $this->resolveFlexibleLayoutSubFields($container_field, $container_context);
        } elseif (! empty($container_field['sub_fields']) && is_array($container_field['sub_fields'])) {
            $fields = $container_field['sub_fields'];
        }

        if (empty($fields)) {
            return [];
        }

        foreach ($nested_repeater_path as $segment) {
            $segment_name = isset($segment['field_name']) ? sanitize_key((string) $segment['field_name']) : '';
            $segment_key = isset($segment['field_key']) ? sanitize_key((string) $segment['field_key']) : '';
            $repeater_field = $this->findSubFieldMatch($fields, $segment_name, $segment_key);

            if (empty($repeater_field) || sanitize_key((string) ($repeater_field['type'] ?? '')) !== 'repeater') {
                return [];
            }

            $fields = ! empty($repeater_field['sub_fields']) && is_array($repeater_field['sub_fields'])
                ? $repeater_field['sub_fields']
                : [];

            if (empty($fields)) {
                return [];
            }
        }

        foreach ($group_path as $segment) {
            $group_field = $this->findSubFieldMatch($fields, sanitize_key((string) $segment), '');
            if (empty($group_field) || sanitize_key((string) ($group_field['type'] ?? '')) !== 'group') {
                return [];
            }

            $fields = ! empty($group_field['sub_fields']) && is_array($group_field['sub_fields'])
                ? $group_field['sub_fields']
                : [];

            if (empty($fields)) {
                return [];
            }
        }

        return $this->findSubFieldMatch($fields, $leaf_name, $leaf_key);
    }

    /**
     * @param array<string, mixed> $container_context
     * @return array<int, array<string, mixed>>
     */
    private function normalizeNestedRepeaterPath(array $container_context)
    {
        if (empty($container_context['nested_repeater_path']) || ! is_array($container_context['nested_repeater_path'])) {
            return [];
        }

        $segments = [];

        foreach ($container_context['nested_repeater_path'] as $segment) {
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
     * @param array<string, mixed> $container_field
     * @param array<string, mixed> $container_context
     * @return array<int, array<string, mixed>>
     */
    private function resolveFlexibleLayoutSubFields(array $container_field, array $container_context)
    {
        if (empty($container_field['layouts']) || ! is_array($container_field['layouts'])) {
            return [];
        }

        $layout_key = isset($container_context['layout_key']) ? sanitize_key((string) $container_context['layout_key']) : '';
        $layout_name = isset($container_context['layout_name']) ? sanitize_key((string) $container_context['layout_name']) : '';

        foreach ($container_field['layouts'] as $layout) {
            if (! is_array($layout)) {
                continue;
            }

            $candidate_key = isset($layout['key']) ? sanitize_key((string) $layout['key']) : '';
            $candidate_name = isset($layout['name']) ? sanitize_key((string) $layout['name']) : '';
            $matches_layout = ($layout_key !== '' && $candidate_key === $layout_key)
                || ($layout_name !== '' && $candidate_name === $layout_name);

            if (! $matches_layout) {
                continue;
            }

            return ! empty($layout['sub_fields']) && is_array($layout['sub_fields'])
                ? $layout['sub_fields']
                : [];
        }

        return [];
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
     * @param array<string, mixed> $container_context
     * @param mixed                $acf_object_id
     * @return array<int, array<string, mixed>>
     */
    private function loadRawContainerRows(array $container_context, $acf_object_id)
    {
        if (! function_exists('get_field')) {
            return [];
        }

        $identifiers = array_values(
            array_filter(
                array_unique(
                    [
                        isset($container_context['parent_field_selector']) ? sanitize_key((string) $container_context['parent_field_selector']) : '',
                        isset($container_context['parent_field_name']) ? sanitize_key((string) $container_context['parent_field_name']) : '',
                        isset($container_context['parent_field_key']) ? sanitize_key((string) $container_context['parent_field_key']) : '',
                    ]
                )
            )
        );

        foreach ($identifiers as $identifier) {
            $rows = get_field($identifier, $acf_object_id, false);
            if (is_array($rows) && ! empty($rows)) {
                return $rows;
            }
        }

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param string                           $field_name
     * @param string                           $field_key
     * @return array<string, mixed>
     */
    private function findSubFieldMatch(array $fields, $field_name, $field_key = '')
    {
        $field_name = sanitize_key((string) $field_name);
        $field_key = sanitize_key((string) $field_key);

        if ($field_key !== '') {
            foreach ($fields as $field) {
                if (! is_array($field)) {
                    continue;
                }

                if (sanitize_key((string) ($field['key'] ?? '')) === $field_key) {
                    return $field;
                }
            }
        }

        if ($field_name !== '') {
            foreach ($fields as $field) {
                if (! is_array($field)) {
                    continue;
                }

                if (sanitize_key((string) ($field['name'] ?? '')) === $field_name) {
                    return $field;
                }
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $tag_object
     * @param string               $container_root
     * @return array<int, string>
     */
    private function normalizeTagGroupPath(array $tag_object, $container_root = '')
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
            && sanitize_key((string) ($tag_object['parent']['type'] ?? '')) === 'group'
        ) {
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
     * @param string $parent_name
     * @return array<int, string>
     */
    private function buildFlexibleParentNameCandidates($parent_name)
    {
        $parent_name = sanitize_key((string) $parent_name);
        if ($parent_name === '') {
            return [];
        }

        $parts = array_values(array_filter(explode('_', $parent_name)));
        $candidates = [$parent_name];

        $count = count($parts);
        if ($count > 1) {
            for ($index = 1; $index < $count; $index++) {
                $candidate = implode('_', array_slice($parts, $index));
                if ($candidate !== '') {
                    $candidates[] = $candidate;
                }
            }
        }

        $candidates = array_values(array_unique($candidates));
        usort($candidates, static function ($left, $right) {
            return strlen((string) $right) <=> strlen((string) $left);
        });

        return $candidates;
    }

    /**
     * @param array<string, mixed> $native_query
     * @param string               $parent_name
     * @param string               $parent_key
     * @return bool
     */
    private function nativeQueryMatchesRepeaterContainer(array $native_query, $parent_name, $parent_key = '')
    {
        if (($native_query['kind'] ?? '') !== 'repeater') {
            return false;
        }

        return $this->nativeQueryMatchesField($native_query, $parent_name, $parent_key);
    }

    /**
     * @param array<string, mixed> $native_query
     * @param string               $parent_name
     * @param string               $parent_key
     * @param string               $layout_name
     * @param string               $layout_key
     * @return bool
     */
    private function nativeQueryMatchesFlexibleContainer(array $native_query, $parent_name, $parent_key = '', $layout_name = '', $layout_key = '')
    {
        if (($native_query['kind'] ?? '') !== 'flexible_content') {
            return false;
        }

        if (! $this->nativeQueryMatchesField($native_query, $parent_name, $parent_key)) {
            return false;
        }

        $path = isset($native_query['path']) && is_array($native_query['path'])
            ? array_values(array_filter(array_map('sanitize_key', $native_query['path'])))
            : [];
        $layout_name = sanitize_key((string) $layout_name);
        $layout_key = sanitize_key((string) $layout_key);

        if ($layout_name === '' && $layout_key === '') {
            return true;
        }

        return in_array($layout_name, $path, true) || in_array($layout_key, $path, true);
    }

    /**
     * @param array<string, mixed> $native_query
     * @param string               $field_name
     * @param string               $field_key
     * @return bool
     */
    private function nativeQueryMatchesField(array $native_query, $field_name, $field_key = '')
    {
        $field_name = sanitize_key((string) $field_name);
        $field_key = sanitize_key((string) $field_key);
        $selector = isset($native_query['selector']) ? sanitize_key((string) $native_query['selector']) : '';
        $native_field_name = isset($native_query['fieldName']) ? sanitize_key((string) $native_query['fieldName']) : '';
        $native_field_key = isset($native_query['fieldKey']) ? sanitize_key((string) $native_query['fieldKey']) : '';
        $path = isset($native_query['path']) && is_array($native_query['path'])
            ? array_values(array_filter(array_map('sanitize_key', $native_query['path'])))
            : [];

        if ($field_name !== '' && ($field_name === $native_field_name || in_array($field_name, $path, true))) {
            return true;
        }

        if ($field_key !== '' && $field_key === $native_field_key) {
            return true;
        }

        return $field_name !== '' && $selector !== '' && $this->queryObjectTypeMatchesContainer('acf_' . $selector, $field_name);
    }

    /**
     * @param array<string, mixed> $native_query
     * @param string               $field_name
     * @param string               $field_key
     * @return string
     */
    private function resolveNativeQueryFieldSelector(array $native_query, $field_name, $field_key = '')
    {
        if (! $this->nativeQueryMatchesField($native_query, $field_name, $field_key)) {
            return sanitize_key((string) $field_name);
        }

        $selector = isset($native_query['selector']) ? sanitize_key((string) $native_query['selector']) : '';

        return $selector !== '' ? $selector : sanitize_key((string) $field_name);
    }
}
