<?php

namespace Dbvc\VisualEditor\Bricks;

final class AcfFieldContextResolver
{
    /**
     * @var LoopContextResolver
     */
    private $loops;

    public function __construct(?LoopContextResolver $loops = null)
    {
        $this->loops = $loops instanceof LoopContextResolver ? $loops : new LoopContextResolver();
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $page_context
     * @return array<string, mixed>
     */
    public function resolve(array $candidate, array $page_context)
    {
        $page_post_id = isset($page_context['entityId']) ? absint($page_context['entityId']) : 0;
        if ($page_post_id <= 0) {
            return [
                'ok' => false,
                'message' => __('Missing current entity context.', 'dbvc'),
            ];
        }

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
        $repeater_context = $this->buildRepeaterContext($tag_object, $loop_context);

        if (! empty($loop_context['active']) && ! $this->supportsLoopContext($loop_context, $repeater_context)) {
            return [
                'ok' => false,
                'message' => $this->buildLoopContextErrorMessage($repeater_context),
            ];
        }

        $acf_object_id = $provider->get_object_id($field_for_context, $page_post_id);
        $acf_object_id = $this->maybeOverrideAcfObjectIdForLoopContext($acf_object_id, $loop_context);
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
            if (empty($repeater_context) && ! empty($tag_object['duplicate'])) {
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
            'scope' => $this->resolveScope($entity, $page_post_id, $loop_context),
            'loop' => $this->loops->export($loop_context),
            'repeater' => $repeater_context,
        ];
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
     * @param mixed $acf_object_id
     * @param int   $page_post_id
     * @return array<string, mixed>
     */
    private function mapAcfObjectIdToEntity($acf_object_id, $page_post_id)
    {
        if ($acf_object_id === 'option' || $acf_object_id === 'options') {
            return [
                'type' => 'option',
                'id' => 'option',
                'subtype' => 'option',
                'acf_object_id' => 'option',
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
     * @param mixed               $acf_object_id
     * @param array<string, mixed> $loop_context
     * @return mixed
     */
    private function maybeOverrideAcfObjectIdForLoopContext($acf_object_id, array $loop_context)
    {
        if (! $this->loops->hasConcretePostOwner($loop_context)) {
            return $acf_object_id;
        }

        $owner = isset($loop_context['effective_owner_entity']) && is_array($loop_context['effective_owner_entity'])
            ? $loop_context['effective_owner_entity']
            : (isset($loop_context['owner_entity']) && is_array($loop_context['owner_entity']) ? $loop_context['owner_entity'] : []);
        $owner_type = isset($owner['type']) ? (string) $owner['type'] : '';
        $owner_object_id = $owner['acf_object_id'] ?? '';

        if ($owner_type !== 'post' || $owner_object_id === '') {
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
     * @param int                  $page_post_id
     * @param array<string, mixed> $loop_context
     * @return string
     */
    private function resolveScope(array $entity, $page_post_id, array $loop_context)
    {
        $entity_type = isset($entity['type']) ? (string) $entity['type'] : '';
        $entity_id = isset($entity['id']) ? absint($entity['id']) : 0;

        if ($entity_type !== 'post') {
            return 'shared_entity';
        }

        if ($entity_id === $page_post_id) {
            return 'current_entity';
        }

        if ($this->entityMatchesLoopOwner($entity, $loop_context)) {
            return 'related_entity';
        }

        return 'shared_entity';
    }

    /**
     * @param array<string, mixed> $loop_context
     * @return bool
     */
    private function supportsLoopContext(array $loop_context, array $repeater_context = [])
    {
        if (empty($loop_context['active'])) {
            return empty($repeater_context);
        }

        if (! empty($repeater_context)) {
            return ! empty($repeater_context['supported']);
        }

        return $this->loops->hasConcretePostOwner($loop_context);
    }

    /**
     * @param array<string, mixed> $tag_object
     * @param array<string, mixed> $loop_context
     * @return array<string, mixed>
     */
    private function buildRepeaterContext(array $tag_object, array $loop_context)
    {
        $field = isset($tag_object['field']) && is_array($tag_object['field']) ? $tag_object['field'] : [];
        $parent = isset($tag_object['parent']) && is_array($tag_object['parent']) ? $tag_object['parent'] : [];
        $parent_type = isset($parent['type']) ? sanitize_key((string) $parent['type']) : '';
        $parent_name = isset($parent['name']) ? sanitize_key((string) $parent['name']) : '';
        $parent_key = isset($parent['key']) ? sanitize_key((string) $parent['key']) : sanitize_key((string) ($field['parent_repeater'] ?? ''));

        if ($parent_type !== 'repeater' && $parent_key === '') {
            return [];
        }

        $row_index = null;
        if (isset($loop_context['loop_index']) && is_numeric($loop_context['loop_index'])) {
            $row_index = absint($loop_context['loop_index']);
        }

        $query_object_type = isset($loop_context['query_object_type']) ? sanitize_key((string) $loop_context['query_object_type']) : '';
        $supported = ! empty($loop_context['active'])
            && $parent_name !== ''
            && $parent_key !== ''
            && $row_index !== null
            && $this->queryObjectTypeMatchesRepeater($query_object_type, $parent_name);

        return [
            'active' => true,
            'supported' => $supported,
            'parent_field_name' => $parent_name,
            'parent_field_key' => $parent_key,
            'parent_field_type' => 'repeater',
            'row_index' => $row_index,
            'query_object_type' => $query_object_type,
            'subfield_name' => isset($field['name']) ? sanitize_key((string) $field['name']) : '',
            'subfield_key' => isset($field['key']) ? sanitize_key((string) $field['key']) : '',
        ];
    }

    /**
     * @param string $query_object_type
     * @param string $parent_name
     * @return bool
     */
    private function queryObjectTypeMatchesRepeater($query_object_type, $parent_name)
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
     * @param array<string, mixed> $repeater_context
     * @return string
     */
    private function buildLoopContextErrorMessage(array $repeater_context)
    {
        if (! empty($repeater_context)) {
            return __('Only Bricks ACF repeater rows with a stable row index and a matching repeater query context are surfaced in the current Visual Editor slice.', 'dbvc');
        }

        return __('Only Bricks query loops with a concrete post owner are surfaced in the current Visual Editor slice.', 'dbvc');
    }
}
